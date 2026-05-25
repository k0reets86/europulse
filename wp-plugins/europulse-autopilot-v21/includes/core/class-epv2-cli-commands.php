<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EPV2 WP-CLI commands (B5, 2026-05-12).
 *
 * Operator tooling для bulk manual_review maintenance — закрывает gap
 * между большим backlog (113+ items) и отсутствием bulk UI.
 *
 * Examples:
 *   wp epv2 mr stats
 *   wp epv2 mr reject --age=24h --max-importance=50
 *   wp epv2 mr reject --reason=build_de_master_cap --max-importance=40
 *   wp epv2 mr promote --editorial-match=match --dry-run
 *   wp epv2 maintenance run
 */
final class EPV2_CLI_Commands {

	/**
	 * Manual_review bulk operations.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : stats | reject | promote | list
	 *
	 * [--age=<duration>]
	 * : Min age, e.g. "24h", "3d", "1w". Default no filter.
	 *
	 * [--max-importance=<N>]
	 * : Only items with importance score <= N. Default no filter.
	 *
	 * [--min-importance=<N>]
	 * : Only items with importance score >= N. Default no filter.
	 *
	 * [--reason=<substring>]
	 * : Filter by substring в error_message. Default no filter.
	 *
	 * [--editorial-match=<value>]
	 * : Filter по story_card.editorial_match (match|borderline|reject_low_value).
	 *
	 * [--category=<slug>]
	 * : Filter by category slug (politik, kultur, wirtschaft, ukraine, etc.).
	 *
	 * [--source=<id>]
	 * : Filter by source_id integer.
	 *
	 * [--has-media=<yes|no>]
	 * : yes = only items with featured_media_url; no = only without.
	 *
	 * [--limit=<N>]
	 * : Max items to process. Default 100.
	 *
	 * [--dry-run]
	 * : Don't actually do anything, just show what would happen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp epv2 mr stats
	 *     wp epv2 mr reject --age=48h --max-importance=50
	 *     wp epv2 mr reject --reason=build_de_master_cap --max-importance=40 --dry-run
	 *     wp epv2 mr promote --editorial-match=match --min-importance=60
	 *
	 * @when after_wp_load
	 */
	public function mr( $args, $assoc_args ): void {
		// 2026-05-13 (revised): WP-CLI без user-context → current_user_can() всегда false,
		// блокировало normal usage `wp epv2 mr reject ...`. Shell access = root уже trust.
		// Если запущен из WP-CLI как root — пропускаем. Иначе требуем capability (если
		// в будущем добавят web-shell или CGI runner).
		$sub = (string) ( $args[0] ?? 'stats' );
		$write_subs = ['reject', 'promote'];
		$is_cli_root = (defined('WP_CLI') && WP_CLI)
			&& (function_exists('posix_geteuid') ? posix_geteuid() === 0 : true);
		if (in_array($sub, $write_subs, true) && ! $is_cli_root && ! current_user_can('manage_europulse_autopilot')) {
			\WP_CLI::error("Insufficient capability: 'manage_europulse_autopilot' required for mr $sub. Run as root via WP-CLI, or pass --user=<admin_login>.");
		}
		switch ( $sub ) {
			case 'stats':
				$this->mr_stats();
				break;
			case 'list':
				$this->mr_list( $assoc_args );
				break;
			case 'reject':
				$this->mr_reject( $assoc_args );
				break;
			case 'promote':
				$this->mr_promote( $assoc_args );
				break;
			default:
				\WP_CLI::error( "Unknown subcommand '$sub'. Use: stats | list | reject | promote." );
		}
	}

	private function mr_stats(): void {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epv2_queue WHERE state='manual_review'" );
		$by_age = $wpdb->get_results(
			"SELECT
				CASE
					WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN '0-6h'
					WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN '6-24h'
					WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR) THEN '24-72h'
					ELSE '>72h'
				END AS bucket,
				COUNT(*) AS cnt
			FROM {$wpdb->prefix}epv2_queue
			WHERE state='manual_review'
			GROUP BY bucket
			ORDER BY MIN(updated_at)",
			ARRAY_A
		);
		$by_reason = $wpdb->get_results(
			"SELECT
				CASE
					WHEN error_message LIKE '%build_de_master%' OR error_message LIKE '%rebuild_bundle%' THEN 'build_cap'
					WHEN error_message LIKE '%publish_ready_gate%' THEN 'publish_ready_cap'
					WHEN error_message LIKE '%publish_finish%' THEN 'publish_finish_cap'
					WHEN error_message LIKE '%translate%' THEN 'translation_no_progress'
					WHEN error_message LIKE '%Циркуит%' THEN 'circuit_breaker'
					WHEN error_message LIKE '%media%' THEN 'media_blocker'
					WHEN error_message LIKE '%thin_dossier%' OR error_message LIKE '%Тонкий source%' THEN 'thin_dossier'
					WHEN error_message LIKE '%Перенесён вручную%' THEN 'manual_move'
					WHEN error_message LIKE '%inline%' THEN 'inline_short_circuit'
					WHEN error_message LIKE '%chronic recycler%' THEN 'chronic_recycler'
					ELSE 'other'
				END AS reason,
				COUNT(*) AS cnt
			FROM {$wpdb->prefix}epv2_queue
			WHERE state='manual_review'
			GROUP BY reason
			ORDER BY cnt DESC",
			ARRAY_A
		);
		\WP_CLI::log( sprintf( '=== manual_review total: %d items ===', $total ) );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'By age:' );
		foreach ( $by_age as $row ) {
			\WP_CLI::log( sprintf( '  %-8s %4d', $row['bucket'], (int) $row['cnt'] ) );
		}
		\WP_CLI::log( '' );
		\WP_CLI::log( 'By reason:' );
		foreach ( $by_reason as $row ) {
			\WP_CLI::log( sprintf( '  %-25s %4d', $row['reason'], (int) $row['cnt'] ) );
		}
	}

	private function mr_list( array $assoc_args ): void {
		$candidates = $this->find_mr_candidates( $assoc_args );
		\WP_CLI::log( sprintf( '%d candidates match filter:', count( $candidates ) ) );
		\WP_CLI::log( '' );
		foreach ( $candidates as $c ) {
			\WP_CLI::log( sprintf(
				'  [%5d] imp=%3d age=%4dm cat=%-15s %s',
				$c['id'],
				$c['importance'],
				$c['age_min'],
				substr( $c['category'], 0, 15 ),
				substr( $c['title'], 0, 60 )
			) );
		}
	}

	private function mr_reject( array $assoc_args ): void {
		$candidates = $this->find_mr_candidates( $assoc_args );
		$dry = isset( $assoc_args['dry-run'] );
		\WP_CLI::log( sprintf( '%d items match filter%s', count( $candidates ), $dry ? ' [DRY-RUN]' : '' ) );
		if ( $dry ) {
			foreach ( array_slice( $candidates, 0, 20 ) as $c ) {
				\WP_CLI::log( sprintf( '  WOULD REJECT [%5d] imp=%3d %s', $c['id'], $c['importance'], substr( $c['title'], 0, 60 ) ) );
			}
			if ( count( $candidates ) > 20 ) {
				\WP_CLI::log( sprintf( '  ... and %d more', count( $candidates ) - 20 ) );
			}
			return;
		}
		$rejected = 0;
		foreach ( $candidates as $c ) {
			$row = EPV2_Queue::get_item( $c['id'] );
			if ( ! $row || (string) $row->state !== 'manual_review' ) continue;
			$notes = is_array( json_decode( (string) ( $row->admin_notes ?? '' ), true ) )
				? json_decode( (string) $row->admin_notes, true ) : [];
			$notes['_system'] = is_array( $notes['_system'] ?? null ) ? $notes['_system'] : [];
			$notes['_system']['workflow_terminal_reason'] = 'cli_bulk_reject';
			$notes['_system']['quarantine_reason'] = 'wp_cli_epv2_mr_reject';
			$notes['_system']['workflow_step_status'] = 'terminal';
			EPV2_Queue::mark_state( $c['id'], 'rejected', [
				'admin_notes' => wp_json_encode( $notes, JSON_UNESCAPED_UNICODE ),
				'error_message' => sprintf(
					'CLI bulk reject (importance=%d, age=%dm) — не пересматривается перезапуском.',
					$c['importance'], $c['age_min']
				),
			] );
			if ( class_exists( 'EPV2_Learning_Journal' ) ) {
				EPV2_Learning_Journal::record( 'manual_review_rejected', $c['id'], 'cli_bulk_reject', [
					'importance' => $c['importance'],
					'age_min' => $c['age_min'],
				] );
			}
			$rejected++;
		}
		\WP_CLI::success( sprintf( 'Rejected %d items', $rejected ) );
	}

	private function mr_promote( array $assoc_args ): void {
		$candidates = $this->find_mr_candidates( $assoc_args );
		$dry = isset( $assoc_args['dry-run'] );
		\WP_CLI::log( sprintf( '%d items match filter%s', count( $candidates ), $dry ? ' [DRY-RUN]' : '' ) );
		if ( $dry ) {
			foreach ( array_slice( $candidates, 0, 20 ) as $c ) {
				\WP_CLI::log( sprintf( '  WOULD PROMOTE [%5d] imp=%3d %s', $c['id'], $c['importance'], substr( $c['title'], 0, 60 ) ) );
			}
			return;
		}
		$promoted = 0;
		foreach ( $candidates as $c ) {
			$row = EPV2_Queue::get_item( $c['id'] );
			if ( ! $row || (string) $row->state !== 'manual_review' ) continue;
			$notes = is_array( json_decode( (string) ( $row->admin_notes ?? '' ), true ) )
				? json_decode( (string) $row->admin_notes, true ) : [];
			if ( ! is_array( $notes['_system'] ?? null ) ) $notes['_system'] = [];
			$sys = &$notes['_system'];
			foreach ( [
				'quarantine_reason','last_stage_blocker','workflow_terminal_reason',
				'workflow_step_attempts','workflow_step','workflow_step_status',
				'workflow_owner_token','workflow_heartbeat_at',
				'workflow_last_error','workflow_not_before','retry_after',
				'next_operator_action','manual_confirmation_required',
			] as $k ) {
				unset( $sys[$k] );
			}
			$sys['admin_promote_at'] = current_time( 'mysql' );
			$sys['admin_promote_reason'] = 'wp_cli_epv2_mr_promote';
			unset( $sys );
			EPV2_Queue::update_fields( $c['id'], [
				'admin_notes' => wp_json_encode( $notes, JSON_UNESCAPED_UNICODE ),
				'error_message' => '',
			] );
			EPV2_Queue::mark_state( $c['id'], 'retry_process' );
			$promoted++;
		}
		\WP_CLI::success( sprintf( 'Promoted %d items to retry_process', $promoted ) );
	}

	private function find_mr_candidates( array $args ): array {
		global $wpdb;
		$where = [ "state='manual_review'" ];
		if ( isset( $args['age'] ) ) {
			$minutes = $this->parse_duration_minutes( (string) $args['age'] );
			if ( $minutes > 0 ) {
				$where[] = $wpdb->prepare( "updated_at <= DATE_SUB(NOW(), INTERVAL %d MINUTE)", $minutes );
			}
		}
		if ( isset( $args['reason'] ) ) {
			$where[] = $wpdb->prepare( "error_message LIKE %s", '%' . $wpdb->esc_like( (string) $args['reason'] ) . '%' );
		}
		if ( isset( $args['editorial-match'] ) ) {
			$em = sanitize_text_field( (string) $args['editorial-match'] );
			$where[] = $wpdb->prepare(
				"JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.story_card.editorial_match')) = %s",
				$em
			);
		}
		// 2026-05-12 W2.5: additional operator filters for bulk operations.
		if ( isset( $args['category'] ) ) {
			$cat = sanitize_text_field( (string) $args['category'] );
			$where[] = $wpdb->prepare( "category_proposed = %s", $cat );
		}
		if ( isset( $args['source'] ) ) {
			$src = (int) $args['source'];
			$where[] = $wpdb->prepare( "source_id = %d", $src );
		}
		if ( isset( $args['has-media'] ) ) {
			$want = sanitize_text_field( (string) $args['has-media'] ) === 'no' ? 0 : 1;
			if ( $want === 1 ) {
				$where[] = "JSON_EXTRACT(ai_payload, '$.featured_media_url') IS NOT NULL";
			} else {
				$where[] = "JSON_EXTRACT(ai_payload, '$.featured_media_url') IS NULL";
			}
		}
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		$rows = $wpdb->get_results(
			"SELECT id, original_title, category_proposed, ai_payload, admin_notes, updated_at,
				TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS age_min
			FROM {$wpdb->prefix}epv2_queue
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY updated_at ASC
			LIMIT $limit",
			ARRAY_A
		);
		$out = [];
		$min_imp = isset( $args['min-importance'] ) ? (int) $args['min-importance'] : null;
		$max_imp = isset( $args['max-importance'] ) ? (int) $args['max-importance'] : null;
		foreach ( (array) $rows as $r ) {
			$payload = json_decode( (string) ( $r['ai_payload'] ?? '' ), true );
			$payload = is_array( $payload ) ? $payload : [];
			$importance = 0;
			if ( class_exists( 'EPV2_Importance_Score' ) ) {
				$item_obj = (object) [
					'id' => (int) $r['id'],
					'original_title' => $r['original_title'],
					'category_proposed' => $r['category_proposed'],
					'category_final' => $r['category_proposed'],
				];
				$importance = (int) EPV2_Importance_Score::compute( $item_obj, $payload );
			}
			if ( $min_imp !== null && $importance < $min_imp ) continue;
			if ( $max_imp !== null && $importance > $max_imp ) continue;
			$out[] = [
				'id' => (int) $r['id'],
				'title' => (string) $r['original_title'],
				'category' => (string) $r['category_proposed'],
				'age_min' => (int) $r['age_min'],
				'importance' => $importance,
			];
		}
		return $out;
	}

	private function parse_duration_minutes( string $s ): int {
		if ( preg_match( '/^(\d+)\s*([hHdDmMwW]?)$/', trim( $s ), $m ) ) {
			$n = (int) $m[1];
			$unit = strtolower( $m[2] ?: 'm' );
			return match ( $unit ) {
				'h' => $n * 60,
				'd' => $n * 60 * 24,
				'w' => $n * 60 * 24 * 7,
				default => $n,
			};
		}
		return 0;
	}

	/**
	 * Run maintenance pipeline (same as orchestrator bridge call).
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : run
	 *
	 * ## EXAMPLES
	 *     wp epv2 maintenance run
	 *
	 * @when after_wp_load
	 */
	public function maintenance( $args, $assoc_args ): void {
		$sub = (string) ( $args[0] ?? 'run' );
		if ( $sub !== 'run' ) {
			\WP_CLI::error( "Use: wp epv2 maintenance run" );
		}
		if ( ! class_exists( 'EPV2_REST' ) ) {
			\WP_CLI::error( "EPV2_REST not available" );
		}
		// Re-implement maintenance bundle inline (REST endpoint requires HTTP).
		$cleanup = [];
		$cleanup['abandoned_started_runs'] = EPV2_Runs::cleanup_abandoned_started( 120 );
		$cleanup['promoted_live_published_rows'] = EPV2_Queue::promote_live_published_rows( 20 );
		$cleanup['reactivated_media_rows'] = EPV2_Queue::reactivate_media_recoverable_rows( 5 );
		if ( class_exists( 'EPV2_AI_Processor' ) ) {
			$cleanup['repaired_retry_process_stage_contract'] = EPV2_AI_Processor::repair_persisted_retry_process_stage_contract( 200 );
			$cleanup['repaired_publish_finish_translation_contract'] = EPV2_AI_Processor::repair_persisted_publish_finish_translation_contract( 200 );
		}
		$ttl_hours = max( 1, (int) EPV2_Settings::get( 'queue_new_ttl_hours', 5 ) );
		$cleanup['pruned_new_stale'] = EPV2_Queue::prune_new_stale( $ttl_hours );
		$cleanup['workflow_quarantine'] = EPV2_Queue::quarantine_pathological_workflow_loops( 100 );
		$cleanup['force_rejected_chronic_recyclers'] = EPV2_Queue::force_reject_chronic_recyclers( 20, 25 );
		$cleanup['trimmed_terminal_rows'] = EPV2_Queue::trim_old_terminal_items( 80 );
		\WP_CLI::log( wp_json_encode( $cleanup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		\WP_CLI::success( 'Maintenance done.' );
	}
}
