<?php
/**
 * EuroPulse AutoPilot v2.1 — Weekly Analytical Article Generator
 *
 * Runs once a week (Sunday 08:00 Berlin time):
 *  1. Finds Top Theme clusters (mentions_7d >= 5, developing_candidate = 1)
 *  2. Picks the cluster with the highest score (not already used this week)
 *  3. Falls back to most-published topic from last 7 days if no cluster qualifies
 *  4. Fetches 5 supporting sources on the topic via Google News
 *  5. Generates a long-form analytical article (900-1400 words) via worker 'analysis' profile
 *  6. Publishes as a special "Analyse" post with "Top-Thema" tag in DE/UK/EN
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class EPV2_Weekly_Analysis {

    private const ANALYSE_TAG  = 'Top-Thema';
    private const MIN_MENTIONS = 5;

    // -------------------------------------------------------------------------
    // Cron registration
    // -------------------------------------------------------------------------

    public static function register(): void {
        add_action( 'epv2_weekly_analysis', [ self::class, 'run' ] );
    }

    public static function maybe_schedule(): void {
        if ( ! wp_next_scheduled( 'epv2_weekly_analysis' ) ) {
            wp_schedule_event( self::next_weekday( 0, 8 ), 'weekly', 'epv2_weekly_analysis' ); // Sunday
        }
        wp_clear_scheduled_hook( 'epv2_weekly_analysis_thursday' );
    }

    /**
     * Returns the Unix timestamp for the next occurrence of $weekday (0=Mon…6=Sun ISO) at $hour Berlin time.
     * N in PHP date(): 1=Mon … 7=Sun. We use ISO weekday internally.
     */
    private static function next_weekday( int $iso_weekday, int $hour ): int {
        $tz = new DateTimeZone( 'Europe/Berlin' );
        $dt = new DateTime( 'now', $tz );
        // PHP date('N'): 1=Mon … 7=Sun; our iso_weekday: 0=Mon … 6=Sun
        $current_iso = (int) $dt->format( 'N' ) - 1; // 0-based Mon
        $diff = ( $iso_weekday - $current_iso + 7 ) % 7;
        if ( $diff === 0 && (int) $dt->format( 'H' ) >= $hour ) {
            $diff = 7;
        }
        $dt->modify( '+' . $diff . ' days' );
        $dt->setTime( $hour, 0, 0 );
        return $dt->getTimestamp();
    }

    // -------------------------------------------------------------------------
    // Main runner
    // -------------------------------------------------------------------------

    public static function run(): void {
        EPV2_Logger::info( 'weekly_analysis', 'Starting weekly Top Theme analysis' );

        $cluster = self::pick_top_cluster();
        if ( ! $cluster ) {
            EPV2_Logger::info( 'weekly_analysis', 'No qualifying clusters found, skipping' );
            return;
        }

        $topic = (string) ( $cluster->topic_label ?? $cluster->title_seed ?? $cluster->cluster_key ?? '' );
        EPV2_Logger::info( 'weekly_analysis', 'Picked cluster', [ 'topic' => $topic ] );

        // Mark cluster as used for this week's analysis to avoid duplication on Thursday run
        self::mark_cluster_used( $cluster );

        // Fetch 5 supporting sources via Google News
        $sources = EPV2_Google_News::fetch( $topic, 'de', 'DE', 5 );
        if ( empty( $sources ) ) {
            EPV2_Logger::warning( 'weekly_analysis', 'No supporting sources found for ' . $topic );
        }

        $source_text = self::compile_sources( $sources );

        if ( ! EPV2_Worker_Client::is_available() ) {
            EPV2_Logger::error( 'weekly_analysis', 'Worker unavailable, aborting' );
            return;
        }

        // Build a synthetic queue item for the worker; use 'analysis' length profile
        $fake_item = (object) [
            'id'               => 0,
            'original_url'     => '',
            'original_title'   => 'Analyse: ' . $topic,
            'original_excerpt' => 'Wochenrückblick und Analyse zu: ' . $topic,
            'original_content' => $source_text,
            'original_date'    => current_time( 'mysql' ),
            'source_image_url' => '',
            'category_proposed'=> 'Analyse',
            'source_language'  => 'de',
            'story_format'     => 'analysis',
            'length_profile'   => 'analysis', // EPV2_Worker_Client::build_payload() respects this
        ];

        try {
            $result = EPV2_Worker_Client::process( $fake_item, 'full_bundle' );
            self::publish_analysis( $topic, $result, $cluster );
        } catch ( Throwable $e ) {
            EPV2_Logger::error( 'weekly_analysis', 'Worker error: ' . $e->getMessage() );
        }
    }

    // -------------------------------------------------------------------------
    // Cluster selection
    // -------------------------------------------------------------------------

    private static function pick_top_cluster(): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'epv2_clusters';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return null;
        }

        // Primary: developing candidates not already used for analysis this week
        $week_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE developing_candidate = 1
               AND mentions_7d >= %d
               AND ( last_promoted_analysis IS NULL OR last_promoted_analysis < %s )
             ORDER BY mentions_7d DESC, source_count_7d DESC
             LIMIT 1",
            self::MIN_MENTIONS,
            $week_start . ' 00:00:00'
        ) );

        if ( ! empty( $results[0] ) ) {
            return $results[0];
        }

        // Fallback: any cluster with enough mentions (ignore analysis gate)
        $fallback = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE mentions_7d >= %d
             ORDER BY mentions_7d DESC, source_count_7d DESC
             LIMIT 1",
            self::MIN_MENTIONS
        ) );

        return $fallback[0] ?? null;
    }

    private static function mark_cluster_used( object $cluster ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'epv2_clusters';
        if ( ! isset( $cluster->id ) ) {
            return;
        }
        $wpdb->update(
            $table,
            [ 'last_promoted_analysis' => current_time( 'mysql', true ) ],
            [ 'id' => (int) $cluster->id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    // -------------------------------------------------------------------------
    // Source text compilation
    // -------------------------------------------------------------------------

    private static function compile_sources( array $sources ): string {
        $parts = [];
        foreach ( $sources as $i => $src ) {
            $n       = $i + 1;
            $title   = (string) ( $src['title']   ?? '' );
            $excerpt = (string) ( $src['excerpt']  ?? '' );
            $url     = (string) ( $src['url']      ?? '' );
            $parts[] = "### Quelle {$n}: {$title}\nURL: {$url}\n{$excerpt}";
        }
        return implode( "\n\n", $parts );
    }

    // -------------------------------------------------------------------------
    // Publish
    // -------------------------------------------------------------------------

    private static function publish_analysis( string $topic, array $result, object $cluster ): void {
        $de = $result['german_master'] ?? [];
        $uk = $result['ukrainian']     ?? [];
        $en = $result['english']       ?? [];

        if ( empty( $de['content'] ) ) {
            EPV2_Logger::warning( 'weekly_analysis', 'Worker returned empty content for topic: ' . $topic );
            return;
        }

        $post_id = wp_insert_post( [
            'post_title'   => wp_strip_all_tags( (string) ( $de['title'] ?? 'Analyse: ' . $topic ) ),
            'post_content' => wp_kses_post( (string) $de['content'] ),
            'post_excerpt' => wp_strip_all_tags( (string) ( $de['excerpt'] ?? '' ) ),
            'post_status'  => 'publish',
            'post_author'  => 1,
            'post_type'    => 'post',
            'meta_input'   => [
                '_epv2_weekly_analysis' => 1,
                '_epv2_cluster_key'     => (string) ( $cluster->cluster_key ?? '' ),
                '_epv2_seo_title'       => (string) ( $de['seo_title']        ?? '' ),
                '_epv2_meta_desc'       => (string) ( $de['meta_description'] ?? '' ),
                '_yoast_wpseo_title'    => (string) ( $de['seo_title']        ?? '' ),
                '_yoast_wpseo_metadesc' => (string) ( $de['meta_description'] ?? '' ),
            ],
        ] );

        if ( is_wp_error( $post_id ) ) {
            EPV2_Logger::error( 'weekly_analysis', 'Failed to publish: ' . $post_id->get_error_message() );
            return;
        }

        if ( function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $post_id, 'de' );
        }

        // Tag as Analyse
        wp_set_post_tags( $post_id, [ self::ANALYSE_TAG ], true );

        // Featured image
        if ( ! empty( $result['featured_media_url'] ) ) {
            self::attach_featured_image( $post_id, (string) $result['featured_media_url'] );
        }

        EPV2_Logger::info( 'weekly_analysis', 'Published analysis post', [
            'post_id' => $post_id,
            'topic'   => $topic,
        ] );

        // Polylang translations
        if ( function_exists( 'pll_set_post_language' ) ) {
            self::create_polylang_translations( $post_id, $uk, $en, $topic );
        }
    }

    private static function attach_featured_image( int $post_id, string $image_url ): void {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_sideload_image( $image_url, $post_id, '', 'id' );
        if ( ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    private static function create_polylang_translations( int $de_post_id, array $uk, array $en, string $topic ): void {
        $translations = [ 'de' => $de_post_id ];
        foreach ( [ 'uk' => $uk, 'en' => $en ] as $lang => $pkg ) {
            if ( empty( $pkg['content'] ) ) {
                continue;
            }
            $translated_id = wp_insert_post( [
                'post_title'   => wp_strip_all_tags( (string) ( $pkg['title']   ?? 'Analyse: ' . $topic ) ),
                'post_content' => wp_kses_post( (string) $pkg['content'] ),
                'post_excerpt' => wp_strip_all_tags( (string) ( $pkg['excerpt'] ?? '' ) ),
                'post_status'  => 'publish',
                'post_author'  => 1,
                'post_type'    => 'post',
            ] );
            if ( ! is_wp_error( $translated_id ) ) {
                pll_set_post_language( $translated_id, $lang );
                $translations[ $lang ] = $translated_id;
                wp_set_post_tags( $translated_id, [ self::ANALYSE_TAG ], true );
            }
        }
        if ( count( $translations ) > 1 ) {
            pll_save_post_translations( $translations );
        }
    }
}
