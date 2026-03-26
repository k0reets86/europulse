<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Runs_Repository {
	public static function start(string $job_name, ?int $queue_id = null, ?string $stage = null, array $payload = []): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'epv3_runs',
			[
				'job_name' => $job_name,
				'queue_id' => $queue_id ?: null,
				'stage' => $stage,
				'status' => 'started',
				'payload' => $payload !== [] ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
			]
		);

		return (int) $wpdb->insert_id;
	}

	public static function finish(int $id, string $status, string $message = '', array $payload = []): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'epv3_runs',
			[
				'status' => $status,
				'message' => $message,
				'payload' => $payload !== [] ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
				'finished_at' => current_time('mysql', true),
			],
			['id' => $id]
		);
	}

	public static function latest(int $limit = 20): array {
		global $wpdb;
		$limit = max(1, min(100, $limit));
		return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}epv3_runs ORDER BY started_at DESC LIMIT {$limit}");
	}

	public static function get_items(array $args = []): array {
		global $wpdb;

		$table = $wpdb->prefix . 'epv3_runs';
		$limit = max(1, min(500, (int) ($args['limit'] ?? 100)));
		$where = ['1=1'];
		$params = [];

		if (! empty($args['status'])) {
			$where[] = 'status = %s';
			$params[] = (string) $args['status'];
		}

		if (! empty($args['job_name'])) {
			$where[] = 'job_name = %s';
			$params[] = (string) $args['job_name'];
		}

		if (! empty($args['queue_id'])) {
			$where[] = 'queue_id = %d';
			$params[] = (int) $args['queue_id'];
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY started_at DESC LIMIT {$limit}";
		if ($params !== []) {
			$sql = $wpdb->prepare($sql, ...$params);
		}

		return $wpdb->get_results($sql);
	}
}
