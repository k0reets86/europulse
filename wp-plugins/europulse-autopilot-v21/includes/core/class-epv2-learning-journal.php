<?php
/**
 * EuroPulse AutoPilot v2.1 — Learning Journal
 *
 * Accumulates operator feedback (trash flags on published posts,
 * manual_review reject reasons, editorial calibration verdicts) into
 * a structured journal so a future pulse-tuning routine can read
 * patterns and auto-correct: source priorities, importance-score
 * weights, prompt modules, stop-list tokens.
 *
 * Per architecture audit section 3 reaction #6 («сигналы оператора
 * не учатся → нужно обучение») and section 8 phase-3 task list
 * («Operator feedback → pulse-tuning»). The analysis routine is a
 * later phase — this commit just builds the journal infrastructure.
 *
 * Storage: ep_epv2_learning_journal table, append-only.
 *   id, occurred_at, event_type, item_id, source_id,
 *   category, content_kind, severity, reason, context (JSON)
 *
 * Event types:
 *   trash_published        — operator trash-flagged a published post
 *   manual_review_landed   — item entered manual_review state
 *   manual_review_rejected — operator rejected from manual_review UI
 *   manual_review_promoted — operator promoted from manual_review to publish
 *   editorial_reject       — Story Card editorial_match=reject_low_value
 *   source_cooldown        — source auto-cooled down on quality rejects
 */

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Learning_Journal {

	const TABLE_SUFFIX = 'epv2_learning_journal';

	/**
	 * Append a row. Idempotent on (event_type, item_id, occurred_at minute)
	 * dedup is handled at the call sites where it matters.
	 */
	public static function record(
		string $event_type,
		int $item_id,
		string $reason = '',
		array $context = []
	): bool {
		$event_type = sanitize_key($event_type);
		if ($event_type === '') {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$row = self::enrich_context_from_item($item_id, $context);
		$severity = (string) ($row['severity'] ?? 'info');
		$ok = $wpdb->insert(
			$table,
			[
				'occurred_at'  => current_time('mysql', true),
				'event_type'   => $event_type,
				'item_id'      => max(0, $item_id),
				'source_id'    => (int) ($row['source_id'] ?? 0),
				'category'     => sanitize_key((string) ($row['category'] ?? '')),
				'content_kind' => sanitize_key((string) ($row['content_kind'] ?? '')),
				'severity'     => sanitize_key($severity),
				'reason'       => mb_substr((string) $reason, 0, 250),
				'context'      => wp_json_encode($context, JSON_UNESCAPED_UNICODE) ?: '{}',
			],
			['%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
		);
		return $ok !== false;
	}

	/**
	 * Read recent events of one or more types. Used by a future
	 * pulse-tuning analysis routine and by the admin journal page.
	 */
	public static function recent(array $event_types = [], int $limit = 100, int $hours = 168): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$since = gmdate('Y-m-d H:i:s', time() - max(1, $hours) * HOUR_IN_SECONDS);
		$type_clause = '';
		$args = [$since];
		if ($event_types !== []) {
			$placeholders = implode(',', array_fill(0, count($event_types), '%s'));
			$type_clause = " AND event_type IN ({$placeholders}) ";
			foreach ($event_types as $t) {
				$args[] = sanitize_key((string) $t);
			}
		}
		$args[] = max(1, min(1000, $limit));
		$sql = "SELECT id, occurred_at, event_type, item_id, source_id, category, content_kind, severity, reason, context
				FROM `{$table}`
				WHERE occurred_at >= %s {$type_clause}
				ORDER BY occurred_at DESC
				LIMIT %d";
		$prepared = $wpdb->prepare($sql, $args);
		return (array) $wpdb->get_results($prepared);
	}

	/**
	 * Aggregate per-source / per-category problem stats. The future
	 * pulse-tuning analyzer would consume this directly.
	 */
	public static function stats(int $hours = 168): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$since = gmdate('Y-m-d H:i:s', time() - max(1, $hours) * HOUR_IN_SECONDS);
		$by_source = $wpdb->get_results($wpdb->prepare(
			"SELECT source_id, event_type, COUNT(*) c
			 FROM `{$table}`
			 WHERE occurred_at >= %s AND source_id > 0
			 GROUP BY source_id, event_type ORDER BY c DESC LIMIT 100",
			$since
		));
		$by_category = $wpdb->get_results($wpdb->prepare(
			"SELECT category, event_type, COUNT(*) c
			 FROM `{$table}`
			 WHERE occurred_at >= %s AND category <> ''
			 GROUP BY category, event_type ORDER BY c DESC LIMIT 100",
			$since
		));
		$by_kind = $wpdb->get_results($wpdb->prepare(
			"SELECT content_kind, event_type, COUNT(*) c
			 FROM `{$table}`
			 WHERE occurred_at >= %s AND content_kind <> ''
			 GROUP BY content_kind, event_type ORDER BY c DESC LIMIT 100",
			$since
		));
		return [
			'window_hours' => $hours,
			'by_source'    => $by_source,
			'by_category'  => $by_category,
			'by_kind'      => $by_kind,
		];
	}

	private static function enrich_context_from_item(int $item_id, array $context): array {
		if ($item_id <= 0) {
			return $context;
		}
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT source_id, category_proposed, category_final, ai_payload
			 FROM {$wpdb->prefix}epv2_queue WHERE id = %d",
			$item_id
		));
		if (! $row) {
			return $context;
		}
		$payload = json_decode((string) ($row->ai_payload ?? ''), true);
		$content_kind = '';
		if (is_array($payload)) {
			$content_kind = (string) ($payload['_meta']['content_kind'] ?? '');
		}
		return array_merge($context, [
			'source_id'    => (int) ($row->source_id ?? 0),
			'category'     => (string) ($row->category_final ?: $row->category_proposed ?: ''),
			'content_kind' => $content_kind,
		]);
	}

	/**
	 * Schema setup. Called from the installer when the plugin activates
	 * or upgrades.
	 */
	public static function ensure_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			event_type VARCHAR(48) NOT NULL DEFAULT '',
			item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			category VARCHAR(64) NOT NULL DEFAULT '',
			content_kind VARCHAR(48) NOT NULL DEFAULT '',
			severity VARCHAR(16) NOT NULL DEFAULT 'info',
			reason VARCHAR(255) NOT NULL DEFAULT '',
			context LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY occurred_at (occurred_at),
			KEY source_id (source_id),
			KEY category (category)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}
}
