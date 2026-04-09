<?php
/**
 * EuroPulse AutoPilot v2.1 — Weekly Analytical Article Generator
 *
 * Every Sunday at 08:00 Berlin time:
 *  1. Finds Top Theme clusters (mentions_7d >= 5, developing_candidate = 1)
 *  2. Picks the cluster with the highest score
 *  3. Fetches 3-5 fresh supporting sources on the topic
 *  4. Generates a long-form analytical article (1500-2500 words) in DE → UK + EN
 *  5. Publishes as a special "Analyse" post with Top-Thema tag
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class EPV2_Weekly_Analysis {

    private const ANALYSE_TAG   = 'Top-Thema';
    private const MIN_MENTIONS  = 5;
    private const WORD_TARGET   = 2000;

    // -------------------------------------------------------------------------
    // Cron registration
    // -------------------------------------------------------------------------

    public static function register(): void {
        add_action( 'epv2_weekly_analysis', [ self::class, 'run' ] );
    }

    public static function maybe_schedule(): void {
        if ( ! wp_next_scheduled( 'epv2_weekly_analysis' ) ) {
            $next = self::next_sunday_08();
            wp_schedule_event( $next, 'weekly', 'epv2_weekly_analysis' );
        }
    }

    private static function next_sunday_08(): int {
        $tz = new DateTimeZone( 'Europe/Berlin' );
        $dt = new DateTime( 'now', $tz );
        // Advance to next Sunday
        $days_until_sunday = ( 7 - (int) $dt->format( 'N' ) ) % 7;
        if ( $days_until_sunday === 0 && (int) $dt->format( 'H' ) >= 8 ) {
            $days_until_sunday = 7;
        }
        $dt->modify( '+' . $days_until_sunday . ' days' );
        $dt->setTime( 8, 0, 0 );
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

        $topic   = (string) ( $cluster->label ?? $cluster->cluster_key ?? '' );
        EPV2_Logger::info( 'weekly_analysis', 'Picked cluster', [ 'topic' => $topic ] );

        // Fetch supporting sources via Google News
        $sources = EPV2_Google_News::fetch( $topic, 'de', 'DE', 5 );
        if ( empty( $sources ) ) {
            EPV2_Logger::warning( 'weekly_analysis', 'No supporting sources found for ' . $topic );
        }

        // Build a combined source text for AI
        $source_text = self::compile_sources( $sources );

        // Send to worker for long-form article generation
        if ( ! EPV2_Worker_Client::is_available() ) {
            EPV2_Logger::error( 'weekly_analysis', 'Worker unavailable, aborting' );
            return;
        }

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
        ];

        try {
            // Temporarily override length_profile
            $original_profile = EPV2_Settings::get( 'length_profile', 'standard' );
            EPV2_Settings::set_transient( 'length_profile', 'long' );

            $result = EPV2_Worker_Client::process( $fake_item, 'full_bundle' );

            EPV2_Settings::set_transient( 'length_profile', $original_profile );

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

        // Check table exists first
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return null;
        }

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE developing_candidate = 1
               AND mentions_7d >= %d
             ORDER BY mentions_7d DESC, source_count_7d DESC
             LIMIT 1",
            self::MIN_MENTIONS
        ) );

        return $results[0] ?? null;
    }

    // -------------------------------------------------------------------------
    // Source text compilation
    // -------------------------------------------------------------------------

    private static function compile_sources( array $sources ): string {
        $parts = [];
        foreach ( $sources as $i => $src ) {
            $n = $i + 1;
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
            EPV2_Logger::warning( 'weekly_analysis', 'Worker returned empty content' );
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

        // Tag
        wp_set_post_tags( $post_id, [ self::ANALYSE_TAG ], true );

        // Featured image
        if ( ! empty( $result['featured_media_url'] ) ) {
            self::attach_featured_image( $post_id, (string) $result['featured_media_url'] );
        }

        EPV2_Logger::info( 'weekly_analysis', 'Published analysis post', [
            'post_id' => $post_id,
            'topic'   => $topic,
        ] );

        // Multilingual: attempt WPML/Polylang parallel posts
        if ( function_exists( 'pll_set_post_language' ) ) {
            self::create_polylang_translations( $post_id, $uk, $en, $topic, $result );
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

    private static function create_polylang_translations( int $de_post_id, array $uk, array $en, string $topic, array $result ): void {
        foreach ( [ 'uk' => $uk, 'en' => $en ] as $lang => $pkg ) {
            if ( empty( $pkg['content'] ) ) continue;
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
                pll_save_post_translations( [ 'de' => $de_post_id, $lang => $translated_id ] );
                wp_set_post_tags( $translated_id, [ self::ANALYSE_TAG ], true );
            }
        }
    }
}
