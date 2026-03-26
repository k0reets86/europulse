<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Queue_Repository {
	public static function find(int $id): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'epv3_queue';
		$item = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id)
		);

		return is_object($item) ? $item : null;
	}

	public static function add_item(array $item): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'epv3_queue',
			[
				'state' => 'queued',
				'stage' => EPV3_Stage_Machine::STAGE_INGESTED,
				'priority' => (int) ($item['priority'] ?? 0),
				'source_id' => ! empty($item['source_id']) ? (int) $item['source_id'] : null,
				'original_url' => esc_url_raw((string) ($item['original_url'] ?? '')),
				'original_language' => sanitize_text_field((string) ($item['original_language'] ?? '')),
				'original_title' => sanitize_text_field((string) ($item['original_title'] ?? '')),
				'original_excerpt' => sanitize_text_field((string) ($item['original_excerpt'] ?? '')),
				'original_content' => wp_kses_post((string) ($item['original_content'] ?? '')),
				'source_image_url' => esc_url_raw((string) ($item['source_image_url'] ?? '')),
			]
		);

		return (int) $wpdb->insert_id;
	}

	public static function counts_by_state(): array {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT state, COUNT(*) qty FROM {$wpdb->prefix}epv3_queue GROUP BY state", ARRAY_A);
		$result = [];
		foreach ($rows as $row) {
			$result[(string) $row['state']] = (int) $row['qty'];
		}
		return $result;
	}

	public static function recent_items(int $limit = 20): array {
		global $wpdb;
		$limit = max(1, min(100, $limit));
		return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}epv3_queue ORDER BY created_at DESC LIMIT {$limit}");
	}

	public static function get_items(array $args = []): array {
		global $wpdb;

		$table = $wpdb->prefix . 'epv3_queue';
		$limit = max(1, min(500, (int) ($args['limit'] ?? 100)));
		$where = ['1=1'];
		$params = [];

		if (! empty($args['state'])) {
			$where[] = 'state = %s';
			$params[] = (string) $args['state'];
		}

		if (! empty($args['stage'])) {
			$where[] = 'stage = %s';
			$params[] = (string) $args['stage'];
		}

		if (! empty($args['search'])) {
			$where[] = '(original_title LIKE %s OR original_url LIKE %s)';
			$like = '%' . $wpdb->esc_like((string) $args['search']) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$order_by = in_array((string) ($args['orderby'] ?? ''), ['created_at', 'updated_at', 'priority', 'id', 'publish_not_before'], true)
			? (string) $args['orderby']
			: 'updated_at';
		$order = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
		$sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY {$order_by} {$order} LIMIT {$limit}";
		if ($params !== []) {
			$sql = $wpdb->prepare($sql, ...$params);
		}

		return $wpdb->get_results($sql);
	}

	public static function next_processable_item(): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'epv3_queue';
		$stages = EPV3_Stage_Machine::processing_stages();
		$placeholders = implode(',', array_fill(0, count($stages), '%s'));
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table}
			WHERE state IN ('queued','retry')
			AND stage IN ({$placeholders})
			AND (retry_after IS NULL OR retry_after <= UTC_TIMESTAMP())
			ORDER BY priority DESC, created_at ASC
			LIMIT 1",
			...$stages
		);

		$item = $wpdb->get_row($sql);
		return is_object($item) ? $item : null;
	}

	public static function next_publishable_item(): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'epv3_queue';
		$sql = "SELECT * FROM {$table}
			WHERE state = 'ready_publish'
			AND stage = 'publish_ready'
			AND (publish_not_before IS NULL OR publish_not_before <= UTC_TIMESTAMP())
			ORDER BY publish_not_before ASC, created_at ASC
			LIMIT 1";

		$item = $wpdb->get_row($sql);
		return is_object($item) ? $item : null;
	}

	public static function mark_processing(int $id): void {
		self::update($id, [
			'state' => 'processing',
			'error_code' => null,
			'error_message' => null,
		]);
	}

	public static function advance_stage(int $id, string $next_stage, array $fields = []): void {
		$fields['state'] = 'queued';
		$fields['stage'] = $next_stage;
		$fields['error_code'] = null;
		$fields['error_message'] = null;
		self::update($id, $fields);
	}

	public static function mark_retry(int $id, string $message, int $delay_seconds = 300): void {
		self::update($id, [
			'state' => 'retry',
			'error_message' => $message,
			'retry_after' => gmdate('Y-m-d H:i:s', time() + max(60, $delay_seconds)),
		]);
	}

	public static function mark_ready_publish(int $id): void {
		$delay = max(5, (int) EPV3_Settings::get('publish_delay_minutes', 5));
		self::update($id, [
			'state' => 'ready_publish',
			'stage' => EPV3_Stage_Machine::STAGE_PUBLISH,
			'publish_not_before' => gmdate('Y-m-d H:i:s', time() + ($delay * MINUTE_IN_SECONDS)),
			'error_code' => null,
			'error_message' => null,
		]);
	}

	public static function update(int $id, array $fields): void {
		global $wpdb;
		$table = $wpdb->prefix . 'epv3_queue';
		$wpdb->update($table, $fields, ['id' => $id]);
	}
}
