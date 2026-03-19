<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Runs {
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

	public static function has_recent_started(string $job_name, int $within_seconds = 90): bool {
		global $wpdb;
		$within_seconds = max(15, $within_seconds);
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
		$older_than_seconds = max(180, $older_than_seconds);
		$thresholds = [
			'collect' => max(300, $older_than_seconds),
			'process' => max(300, $older_than_seconds),
			'publish' => max(180, $older_than_seconds),
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
				$wpdb->update(
					$wpdb->prefix . 'epv2_runs',
					[
						'status' => 'finished_with_errors',
						'error_count' => max(1, (int) ($payload['error_count'] ?? 0)),
						'payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
						'finished_at' => current_time('mysql', true),
					],
					['id' => (int) $row['id']]
				);
				$updated++;
			}
		}
		return $updated;
	}

	private static function job_has_recent_activity(string $job_name, int $thresholdSeconds): bool {
		global $wpdb;
		$thresholdSeconds = max(180, $thresholdSeconds);
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
			$updatedTs = strtotime((string) ($progress['updated_at'] ?? '')) ?: 0;
			if ($updatedTs > $cutoffTs && (string) ($progress['status'] ?? '') === 'running') {
				return true;
			}
		}

		return false;
	}
}
