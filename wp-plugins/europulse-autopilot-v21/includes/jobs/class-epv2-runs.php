<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Runs {
	private const MAX_RECENT_ITEM_STREAK_LIMIT = 20;
	private const MAX_RECENT_STATUS_STREAK_LIMIT = 20;

	public static function start(string $job_name, array $payload = []): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'epv2_runs',
			[
				'job_name' => $job_name,
				'status' => 'started',
				'payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				'started_at' => current_time('mysql', true),
			]
		);
		return (int) $wpdb->insert_id;
	}

	public static function finish(int $run_id, string $status, int $item_count = 0, int $error_count = 0, array $payload = []): void {
		global $wpdb;
		$current_payload = $wpdb->get_var($wpdb->prepare(
			"SELECT payload FROM {$wpdb->prefix}epv2_runs WHERE id = %d",
			$run_id
		));
		$stored_payload = json_decode((string) $current_payload, true);
		$stored_payload = is_array($stored_payload) ? $stored_payload : [];
		$payload = array_merge($stored_payload, $payload);
		$wpdb->update(
			$wpdb->prefix . 'epv2_runs',
			[
				'status' => $status,
				'item_count' => $item_count,
				'error_count' => $error_count,
				'payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				'finished_at' => current_time('mysql', true),
			],
			['id' => $run_id]
		);
	}

	public static function latest(string $job_name): ?object {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}epv2_runs WHERE job_name = %s ORDER BY started_at DESC, id DESC LIMIT 1",
			$job_name
		));
	}

	public static function health_snapshot(string $job_name, int $within_seconds = 300): array {
		global $wpdb;
		$within_seconds = max(15, $within_seconds);
		$latest = self::latest($job_name);
		$cutoff = gmdate('Y-m-d H:i:s', time() - $within_seconds);
		$started_count = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}epv2_runs
			WHERE job_name = %s
			  AND status = 'started'",
			$job_name
		));
		$recent_started_count = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}epv2_runs
			WHERE job_name = %s
			  AND status = 'started'
			  AND started_at >= %s",
			$job_name,
			$cutoff
		));
		$latest_started_at = (string) ($latest->started_at ?? '');
		$latest_started_ts = $latest_started_at !== '' ? strtotime($latest_started_at) : false;

		return [
			'job_name' => sanitize_key($job_name),
			'latest_run_id' => (int) ($latest->id ?? 0),
			'latest_status' => (string) ($latest->status ?? ''),
			'latest_started_at' => $latest_started_at,
			'latest_finished_at' => (string) ($latest->finished_at ?? ''),
			'latest_error_count' => (int) ($latest->error_count ?? 0),
			'started_count' => $started_count,
			'recent_started_count' => $recent_started_count,
			'recent_started' => $recent_started_count > 0,
			'latest_started_age_seconds' => $latest_started_ts ? max(0, time() - (int) $latest_started_ts) : null,
		];
	}

	public static function has_recent_started(string $job_name, int $within_seconds = 90): bool {
		global $wpdb;
		$within_seconds = max(15, $within_seconds);
		if (! self::job_has_recent_activity($job_name, $within_seconds)) {
			return false;
		}
		$cutoff = gmdate('Y-m-d H:i:s', time() - $within_seconds);
		$count = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}epv2_runs
			WHERE job_name = %s
			  AND status = 'started'
			  AND started_at >= %s",
			$job_name,
			$cutoff
		));
		return $count > 0;
	}

	public static function cleanup_abandoned_started(int $older_than_seconds = 120): int {
		global $wpdb;
		$older_than_seconds = max(30, $older_than_seconds);
		$thresholds = [
			'collect' => max(180, $older_than_seconds),
			'process' => max(45, $older_than_seconds),
			'publish' => max(90, $older_than_seconds),
		];
		$jobs = [
			'process' => ! EPV2_Lock_Manager::is_active('process'),
			'publish' => ! EPV2_Lock_Manager::is_active('publish'),
			'collect' => ! EPV2_Lock_Manager::is_active('collect'),
		];
		$updated = 0;
		foreach ($jobs as $job_name => $should_cleanup) {
			if (! $should_cleanup) {
				continue;
			}
			if (self::job_has_recent_activity($job_name, (int) ($thresholds[$job_name] ?? $older_than_seconds))) {
				continue;
			}
			$cutoff = gmdate('Y-m-d H:i:s', time() - (int) ($thresholds[$job_name] ?? $older_than_seconds));
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT id, payload FROM {$wpdb->prefix}epv2_runs
				WHERE job_name = %s
				  AND status = 'started'
				  AND started_at <= %s",
				$job_name,
				$cutoff
			), ARRAY_A);
			foreach ((array) $rows as $row) {
				$payload = json_decode((string) ($row['payload'] ?? ''), true);
				$payload = is_array($payload) ? $payload : [];
				$payload['result'] = 'abandoned_started_run_cleaned';
				$payload['job_name'] = $job_name;
				$payload['recovery_source'] = 'cleanup_abandoned_started';
				self::finish(
					(int) $row['id'],
					'finished_with_errors',
					0,
					max(1, (int) ($payload['error_count'] ?? 0)),
					$payload
				);
				$updated++;
			}
		}
		return $updated;
	}

	public static function recent_processed_item_streak(string $job_name, int $item_id, int $limit = 6): int {
		global $wpdb;
		$item_id = max(0, $item_id);
		if ($item_id <= 0) {
			return 0;
		}
		$limit = max(1, min(self::MAX_RECENT_ITEM_STREAK_LIMIT, $limit));
		$rows = $wpdb->get_col($wpdb->prepare(
			"SELECT payload FROM {$wpdb->prefix}epv2_runs
			WHERE job_name = %s
			  AND status != 'started'
			ORDER BY id DESC
			LIMIT %d",
			$job_name,
			$limit
		));
		if (! is_array($rows) || $rows === []) {
			return 0;
		}

		$streak = 0;
		foreach ($rows as $payloadJson) {
			$payload = json_decode((string) $payloadJson, true);
			$payload = is_array($payload) ? $payload : [];
			$processed_id = (int) ($payload['processed_item_id'] ?? $payload['last_item_id'] ?? 0);
			if ($processed_id !== $item_id) {
				break;
			}
			$streak++;
		}

		return $streak;
	}

	public static function recent_status_streak(string $job_name, array $statuses, int $limit = 6): int {
		global $wpdb;
		$statuses = array_values(array_filter(array_map('sanitize_key', $statuses)));
		if ($statuses === []) {
			return 0;
		}
		$limit = max(1, min(self::MAX_RECENT_STATUS_STREAK_LIMIT, $limit));
		$rows = $wpdb->get_col($wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}epv2_runs
			WHERE job_name = %s
			  AND status != 'started'
			ORDER BY id DESC
			LIMIT %d",
			$job_name,
			$limit
		));
		if (! is_array($rows) || $rows === []) {
			return 0;
		}

		$streak = 0;
		foreach ($rows as $status) {
			if (! in_array(sanitize_key((string) $status), $statuses, true)) {
				break;
			}
			$streak++;
		}

		return $streak;
	}

	public static function latest_finished_payload(string $job_name): array {
		global $wpdb;
		$payload_json = $wpdb->get_var($wpdb->prepare(
			"SELECT payload FROM {$wpdb->prefix}epv2_runs
			WHERE job_name = %s
			  AND status != 'started'
			ORDER BY id DESC
			LIMIT 1",
			$job_name
		));
		$payload = json_decode((string) $payload_json, true);
		return is_array($payload) ? $payload : [];
	}

	private static function job_has_recent_activity(string $job_name, int $thresholdSeconds): bool {
		global $wpdb;
		$thresholdSeconds = max(30, $thresholdSeconds);
		$cutoffTs = time() - $thresholdSeconds;

		$lock = get_option('epv2_lock_' . $job_name, []);
		if (is_array($lock) && ! empty($lock['token'])) {
			$heartbeat = (int) ($lock['heartbeat_at'] ?? 0);
			if ($heartbeat > $cutoffTs) {
				return true;
			}
		}

		if ($job_name === 'process') {
			$rows = $wpdb->get_col("SELECT updated_at FROM {$wpdb->prefix}epv2_queue WHERE state = 'processing_de' ORDER BY updated_at DESC LIMIT 3");
			foreach ((array) $rows as $updatedAt) {
				$updatedTs = strtotime((string) $updatedAt) ?: 0;
				if ($updatedTs > $cutoffTs) {
					return true;
				}
			}
		} elseif ($job_name === 'publish') {
			$rows = $wpdb->get_col("SELECT updated_at FROM {$wpdb->prefix}epv2_queue WHERE state = 'publishing' ORDER BY updated_at DESC LIMIT 3");
			foreach ((array) $rows as $updatedAt) {
				$updatedTs = strtotime((string) $updatedAt) ?: 0;
				if ($updatedTs > $cutoffTs) {
					return true;
				}
			}
		} elseif ($job_name === 'collect') {
			$progress = get_option('epv2_collect_progress', []);
			$updatedTs = (int) ($progress['updated_at_ts'] ?? 0);
			if ($updatedTs <= 0) {
				$updatedTs = strtotime((string) ($progress['updated_at'] ?? '')) ?: 0;
			}
			if ($updatedTs > time()) {
				$updatedTs = time();
			}
			if ($updatedTs > $cutoffTs && (string) ($progress['status'] ?? '') === 'running') {
				return true;
			}
		}

		return false;
	}
}
