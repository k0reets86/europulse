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

    private const ANALYSE_TAG    = 'Top-Thema';
    private const MIN_MENTIONS   = 5;
    private const SOURCE_TARGET  = 8;   // Architecture audit section 4 phase-3 spec: 5–8 sources for weekly analysis.
    private const ECHO_LOOKBACK_DAYS = 30;

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

        // Phase-3 broadening: 5–8 fresh sources via Google News (architecture
        // audit section 4 — weekly analytics is supposed to synthesise across
        // sources, not paraphrase one). The new is_aggregator-aware collector
        // path will follow GN wrappers automatically.
        $sources = EPV2_Google_News::fetch( $topic, 'de', 'DE', self::SOURCE_TARGET );
        if ( empty( $sources ) ) {
            EPV2_Logger::warning( 'weekly_analysis', 'No supporting sources found for ' . $topic );
        }

        // Pull our own past coverage on this cluster — these become the
        // "Europulse berichtete zuvor" echo block in the analytical piece,
        // turning isolated weekly news into a continuous narrative arc.
        $internal_echo = self::collect_internal_coverage( $cluster );

        $source_text = self::compile_sources( $sources, $internal_echo, $topic );

        if ( ! EPV2_Worker_Client::is_available() ) {
            EPV2_Logger::error( 'weekly_analysis', 'Worker unavailable, aborting' );
            return;
        }

        // Resolve the cluster's actual rubric so the prompt matrix
        // (worker-v21/src/epv2_worker/prompts/rubrics.py) picks up the
        // right stylistic module — politik and ukraine carry the
        // editorial-position addendum, kultur/wirtschaft don't.
        $rubric = self::resolve_cluster_rubric( $cluster );

        // Build a synthetic queue item for the worker. story_format=analysis
        // + length_profile=analysis both push the rewriter onto the longer
        // analysis-tier prompt. category_proposed/final feed rubric_slug
        // through pipeline.py so compose_rewrite_prompt() picks the right
        // RUBRIC × TYPE combo.
        $fake_item = (object) [
            'id'               => 0,
            'original_url'     => '',
            'original_title'   => 'Analyse: ' . $topic,
            'original_excerpt' => 'Wochenrückblick und Analyse zu: ' . $topic,
            'original_content' => $source_text,
            'original_date'    => current_time( 'mysql' ),
            'source_image_url' => '',
            'category_proposed'=> $rubric,
            'category_final'   => $rubric,
            'source_language'  => 'de',
            'story_format'     => 'analysis',
            'length_profile'   => 'analysis',
            'topic_label'      => $topic,
            'cluster_id'       => (int) ( $cluster->id ?? 0 ),
        ];

        try {
            // Pre-seed _meta.content_kind = analysis so the worker matrix
            // composes the analysis type module immediately; otherwise
            // detect_kind would have to infer from a synthetic story.
            $existing = [
                '_meta' => [
                    'content_kind' => 'analysis',
                    'weekly_analysis' => [
                        'cluster_id' => (int) ( $cluster->id ?? 0 ),
                        'topic_label' => $topic,
                        'source_count' => count( $sources ),
                        'internal_coverage' => count( $internal_echo ),
                    ],
                ],
            ];
            $result = EPV2_Worker_Client::process( $fake_item, 'full_bundle', $existing );
            self::publish_analysis( $topic, $result, $cluster );
        } catch ( Throwable $e ) {
            EPV2_Logger::error( 'weekly_analysis', 'Worker error: ' . $e->getMessage() );
        }
    }

    /**
     * Pull EuroPulse's own past coverage of the same cluster — last
     * ECHO_LOOKBACK_DAYS days, max 5 entries. Output rows go into the
     * source_text under a "BISHERIGE EUROPULSE-BERICHTE" header that the
     * rewriter is instructed to weave into a closing «Europulse berichtete
     * zuvor» paragraph. This is the architectural «echo» block from
     * section 2 step 5B of the audit.
     */
    private static function collect_internal_coverage( object $cluster ): array {
        global $wpdb;
        $cluster_id = (int) ( $cluster->id ?? 0 );
        $cluster_key = (string) ( $cluster->cluster_key ?? '' );
        if ( $cluster_id <= 0 && $cluster_key === '' ) {
            return [];
        }
        $sql = "SELECT p.ID, p.post_title, p.post_excerpt, p.post_date_gmt,
                       (SELECT meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_epv2_source_url' LIMIT 1) AS source_url
                FROM {$wpdb->posts} p
                WHERE p.post_type='post' AND p.post_status='publish'
                  AND p.post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
                  AND (
                    EXISTS (SELECT 1 FROM {$wpdb->postmeta} m1 WHERE m1.post_id=p.ID AND m1.meta_key='_epv2_cluster_id' AND m1.meta_value=%s)
                    OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} m2 WHERE m2.post_id=p.ID AND m2.meta_key='_epv2_cluster_key' AND m2.meta_value=%s)
                  )
                ORDER BY p.post_date_gmt DESC
                LIMIT 5";
        $rows = $wpdb->get_results( $wpdb->prepare(
            $sql,
            self::ECHO_LOOKBACK_DAYS,
            (string) $cluster_id,
            $cluster_key
        ) );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[] = [
                'title'   => (string) ( $row->post_title ?? '' ),
                'excerpt' => wp_trim_words( wp_strip_all_tags( (string) ( $row->post_excerpt ?? '' ) ), 40, '…' ),
                'date'    => (string) ( $row->post_date_gmt ?? '' ),
                'url'     => get_permalink( (int) $row->ID ) ?: '',
            ];
        }
        return $out;
    }

    /**
     * Resolve the cluster's editorial rubric using ingested-content tag
     * frequency. Falls back to politik when the cluster has no clear
     * tag majority (politik is the safest analytical default).
     */
    private static function resolve_cluster_rubric( object $cluster ): string {
        $candidate = strtolower( trim( (string) ( $cluster->primary_category ?? '' ) ) );
        $allowed = [
            'politik', 'ukraine', 'deutschland', 'wirtschaft', 'welt',
            'leben-in-deutschland', 'sport', 'kultur', 'community', 'meinung',
        ];
        if ( in_array( $candidate, $allowed, true ) ) {
            return $candidate;
        }
        // Heuristic from cluster topic_label / cluster_key tokens.
        $signal = mb_strtolower( (string) ( $cluster->topic_label ?? $cluster->cluster_key ?? '' ) );
        if ( $signal !== '' ) {
            if ( preg_match( '/\b(ukrain|kyiv|kiew|selenskyj|krieg)/u', $signal ) ) return 'ukraine';
            if ( preg_match( '/\b(bundestag|merz|scholz|wahl|koalition|partei)/u', $signal ) ) return 'politik';
            if ( preg_match( '/\b(wirtschaft|inflation|euro|aktie|markt|export|tarif)/u', $signal ) ) return 'wirtschaft';
            if ( preg_match( '/\b(kultur|literatur|theater|oper|festival|berlinale)/u', $signal ) ) return 'kultur';
            if ( preg_match( '/\b(sport|fussball|champions|league|olymp|biathlon)/u', $signal ) ) return 'sport';
        }
        return 'politik';
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

    private static function compile_sources( array $sources, array $internal_echo = [], string $topic = '' ): string {
        $blocks = [];

        // Phase-3 weekly-analysis specific framing block — instructs the
        // rewriter to use expert tone, span the week, weave predictions
        // and reference past EuroPulse coverage. Augments the existing
        // ANALYSIS type module from prompts/types.py with weekly-specific
        // angles.
        $blocks[] = sprintf(
            "WÖCHENTLICHE TOP-THEMA-ANALYSE — Aufgabentyp: tiefgehende Wochenanalyse zu «%s».\n" .
            "Strukturwünsche zusätzlich zur Standard-Analysis-Vorgabe:\n" .
            "  • Lead: setzt das Thema der Woche in den Kontext (warum gerade diese Woche).\n" .
            "  • Body: 4–7 Absätze, jeder mit Beleg aus mindestens einer der Quellen unten.\n" .
            "  • Mindestens 2 Quellen pro Hauptthese namentlich nennen («wie Spiegel berichtet», «laut Reuters»).\n" .
            "  • Ein Ausblicks-/Prognose-Absatz: was im Verlauf der nächsten Woche / Monate beobachtet werden sollte.\n" .
            "  • Schluss-Absatz: «Europulse berichtete zuvor zu diesem Thema, dass …» — 1–2 Sätze, mit Querverweis auf unsere früheren Stücke (siehe BISHERIGE EUROPULSE-BERICHTE unten).\n" .
            "  • Kein eigenes «Ich» — analytischer Ton, keine Meinungsspalte.\n" .
            "  • Eindeutigkeit ≥ 80 %% gegenüber den Quelltexten (Anti-Plagiat-Gate kontrolliert).\n",
            $topic !== '' ? $topic : 'das Wochenthema'
        );

        $blocks[] = "QUELLEN DIESER WOCHE (Aussen):";
        foreach ( $sources as $i => $src ) {
            $n       = $i + 1;
            $title   = (string) ( $src['title']   ?? '' );
            $excerpt = (string) ( $src['excerpt']  ?? '' );
            $url     = (string) ( $src['url']      ?? '' );
            $blocks[] = "### Quelle {$n}: {$title}\nURL: {$url}\n{$excerpt}";
        }

        if ( $internal_echo !== [] ) {
            $blocks[] = "BISHERIGE EUROPULSE-BERICHTE ZU DIESEM CLUSTER (für den Schluss-Echo-Absatz):";
            foreach ( $internal_echo as $i => $row ) {
                $n     = $i + 1;
                $title = (string) ( $row['title']   ?? '' );
                $excerpt = (string) ( $row['excerpt'] ?? '' );
                $date  = (string) ( $row['date']    ?? '' );
                $url   = (string) ( $row['url']     ?? '' );
                $blocks[] = "### Eigener Bericht {$n} ({$date}): {$title}\nURL: {$url}\n{$excerpt}";
            }
        }

        return implode( "\n\n", $blocks );
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
