<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Quality_Audit {
	private const TABLE = 'epv2_quality_audit';

	public static function record(array $row): int {
		global $wpdb;

		if (! isset($wpdb) || ! $wpdb instanceof wpdb) {
			return 0;
		}

		$table = self::table_name();
		if (! self::table_exists($table)) {
			return 0;
		}

		$verdict = sanitize_key((string) ($row['verdict'] ?? 'review'));
		if (! in_array($verdict, ['pass', 'review', 'reject', 'retry'], true)) {
			$verdict = 'review';
		}

		$insert = [
			'queue_id'     => self::nullable_int($row['queue_id'] ?? null),
			'post_id'      => self::nullable_int($row['post_id'] ?? null),
			'source_id'    => self::nullable_int($row['source_id'] ?? null),
			'phase'        => sanitize_key((string) ($row['phase'] ?? 'publish_gate_shadow')) ?: 'publish_gate_shadow',
			'verdict'      => $verdict,
			'score'        => max(0, min(100, (int) ($row['score'] ?? 0))),
			'blockers'     => self::json_or_null($row['blockers'] ?? []),
			'warnings'     => self::json_or_null($row['warnings'] ?? []),
			'signals'      => self::json_or_null($row['signals'] ?? []),
			'worker_json'  => self::json_or_null($row['worker_json'] ?? null),
			'context_json' => self::json_or_null($row['context_json'] ?? ($row['context'] ?? null)),
			'created_at'   => gmdate('Y-m-d H:i:s'),
		];

		$ok = $wpdb->insert(
			$table,
			$insert,
			['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function latest_for_queue(int $queue_id): ?array {
		return self::latest_by('queue_id', $queue_id);
	}

	public static function latest_for_post(int $post_id): ?array {
		return self::latest_by('post_id', $post_id);
	}

	public static function summary(int $hours = 24): array {
		global $wpdb;

		$table = self::table_name();
		if (! self::table_exists($table)) {
			return ['available' => false, 'reason' => 'quality_audit_table_missing'];
		}

		$hours = max(1, min(24 * 30, $hours));
		$since = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT phase, verdict, COUNT(*) AS n, ROUND(AVG(score), 1) AS avg_score
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY phase, verdict
				ORDER BY phase ASC, n DESC",
				$since
			),
			ARRAY_A
		);

		return [
			'available' => true,
			'window_hours' => $hours,
			'rows' => is_array($rows) ? $rows : [],
		];
	}

	public static function source_breakdown(int $hours = 168): array {
		global $wpdb;

		$table = self::table_name();
		if (! self::table_exists($table)) {
			return ['available' => false, 'reason' => 'quality_audit_table_missing'];
		}

		$hours = max(1, min(24 * 30, $hours));
		$since = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));
		$sources = $wpdb->prefix . 'epv2_sources';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT qa.source_id, COALESCE(s.name, '') AS source_name, qa.verdict,
					COUNT(*) AS n, ROUND(AVG(qa.score), 1) AS avg_score
				FROM {$table} qa
				LEFT JOIN {$sources} s ON s.id = qa.source_id
				WHERE qa.created_at >= %s
				GROUP BY qa.source_id, source_name, qa.verdict
				ORDER BY n DESC
				LIMIT 50",
				$since
			),
			ARRAY_A
		);

		return [
			'available' => true,
			'window_hours' => $hours,
			'rows' => is_array($rows) ? $rows : [],
		];
	}

	public static function failure_histogram(int $hours = 24): array {
		global $wpdb;

		$table = self::table_name();
		if (! self::table_exists($table)) {
			return ['available' => false, 'reason' => 'quality_audit_table_missing', 'buckets' => []];
		}

		$hours = max(1, min(24 * 30, $hours));
		$since = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blockers, warnings
				FROM {$table}
				WHERE created_at >= %s
					AND verdict IN ('review', 'reject', 'retry')
				ORDER BY created_at DESC
				LIMIT 500",
				$since
			),
			ARRAY_A
		);

		$buckets = [];
		foreach ((array) $rows as $row) {
			$reasons = array_merge(
				self::decode_list((string) ($row['blockers'] ?? '')),
				self::decode_list((string) ($row['warnings'] ?? ''))
			);
			if ($reasons === []) {
				$reasons = ['unknown'];
			}
			foreach (array_unique($reasons) as $reason) {
				$key = sanitize_key((string) $reason) ?: 'unknown';
				$buckets[$key] = ($buckets[$key] ?? 0) + 1;
			}
		}
		arsort($buckets);

		return [
			'available' => true,
			'window_hours' => $hours,
			'buckets' => $buckets,
		];
	}

	public static function latest(int $limit = 50): array {
		global $wpdb;

		$table = self::table_name();
		if (! self::table_exists($table)) {
			return [];
		}

		$limit = max(1, min(200, $limit));
		$rows = $wpdb->get_results(
			"SELECT *
			FROM {$table}
			ORDER BY created_at DESC
			LIMIT {$limit}",
			ARRAY_A
		);

		return array_map([self::class, 'decode_row'], is_array($rows) ? $rows : []);
	}

	private static function latest_by(string $column, int $id): ?array {
		global $wpdb;

		if ($id <= 0 || ! in_array($column, ['queue_id', 'post_id'], true)) {
			return null;
		}

		$table = self::table_name();
		if (! self::table_exists($table)) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE {$column} = %d
				ORDER BY created_at DESC
				LIMIT 1",
				$id
			),
			ARRAY_A
		);

		return is_array($row) ? self::decode_row($row) : null;
	}

	private static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	private static function table_exists(string $table): bool {
		global $wpdb;
		return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
	}

	private static function nullable_int($value): ?int {
		$value = (int) $value;
		return $value > 0 ? $value : null;
	}

	private static function json_or_null($value): ?string {
		if ($value === null || $value === [] || $value === '') {
			return null;
		}
		$json = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
		return is_string($json) ? $json : null;
	}

	private static function decode_row(array $row): array {
		foreach (['blockers', 'warnings', 'signals', 'worker_json', 'context_json'] as $key) {
			if (! isset($row[$key]) || $row[$key] === null || $row[$key] === '') {
				$row[$key] = [];
				continue;
			}
			$decoded = json_decode((string) $row[$key], true);
			$row[$key] = is_array($decoded) ? $decoded : [];
		}
		return $row;
	}

	private static function decode_list(string $json): array {
		if ($json === '') {
			return [];
		}
		$decoded = json_decode($json, true);
		if (! is_array($decoded)) {
			return [];
		}
		if (array_is_list($decoded)) {
			return array_values(array_filter(array_map('strval', $decoded)));
		}
		return array_values(array_filter(array_map('strval', array_keys($decoded))));
	}
}
