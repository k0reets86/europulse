<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Sources {
	public static function all(bool $active_only = false): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_sources';
		$where = $active_only ? 'WHERE is_active = 1' : '';
		return $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY priority DESC, name ASC");
	}

	public static function get(int $id) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}epv2_sources WHERE id = %d", $id));
	}

	public static function exists(string $name, string $url): bool {
		global $wpdb;
		$count = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}epv2_sources WHERE name = %s OR url = %s",
			$name,
			$url
		));
		return $count > 0;
	}

	public static function save(array $data): int {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_sources';
		$parse_rules = [];
		if (! empty($data['parse_rules_json']) && is_string($data['parse_rules_json'])) {
			$decoded = json_decode(wp_unslash($data['parse_rules_json']), true);
			if (is_array($decoded)) {
				$parse_rules = $decoded;
			}
		} elseif (! empty($data['parse_rules']) && is_array($data['parse_rules'])) {
			$parse_rules = $data['parse_rules'];
		}
		if (empty($parse_rules) && in_array((string) ($data['type'] ?? ''), ['scrape', 'facebook', 'telegram'], true)) {
			$parse_rules = EPV2_Source_Adapters::rules_for((string) ($data['url'] ?? ''));
		}
		$row = [
			'name' => sanitize_text_field($data['name'] ?? ''),
			'type' => sanitize_text_field($data['type'] ?? 'rss'),
			'url' => esc_url_raw($data['url'] ?? ''),
			'language' => sanitize_text_field($data['language'] ?? 'de'),
			'category_bias' => sanitize_text_field($data['category_bias'] ?? ''),
			'priority' => max(1, min(10, (int) ($data['priority'] ?? 5))),
			'fetch_interval' => max(300, (int) ($data['fetch_interval'] ?? 1800)),
			'is_active' => ! empty($data['is_active']) ? 1 : 0,
			'risk_level' => sanitize_text_field($data['risk_level'] ?? 'low'),
			'parse_rules' => ! empty($parse_rules) ? wp_json_encode($parse_rules, JSON_UNESCAPED_UNICODE) : null,
			'attribution_rule' => ! empty($data['attribution_rule']) ? wp_json_encode($data['attribution_rule'], JSON_UNESCAPED_UNICODE) : null,
			'notes' => wp_kses_post($data['notes'] ?? ''),
		];
		if (! empty($data['id'])) {
			$wpdb->update($table, $row, ['id' => (int) $data['id']]);
			return (int) $data['id'];
		}
		$wpdb->insert($table, $row);
		return (int) $wpdb->insert_id;
	}

	public static function delete(int $id): void {
		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'epv2_sources', ['id' => $id]);
	}

	public static function toggle(int $id): void {
		$source = self::get($id);
		if (! $source) {
			return;
		}
		global $wpdb;
		$wpdb->update($wpdb->prefix . 'epv2_sources', ['is_active' => $source->is_active ? 0 : 1], ['id' => $id]);
	}

	public static function update_fetch(int $id, int $count = 0, ?string $error = null): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'epv2_sources',
			[
				'last_fetched' => current_time('mysql'),
				'last_error' => $error,
			],
			['id' => $id]
		);
	}

	public static function coverage(): array {
		$coverage = [];
		foreach (EPV2_Taxonomy_Map::categories() as $slug => $label) {
			$coverage[$slug] = [
				'label' => $label,
				'total' => 0,
				'active' => 0,
			];
		}

		foreach (self::all(false) as $source) {
			$slug = (string) $source->category_bias;
			if (! isset($coverage[$slug])) {
				continue;
			}
			$coverage[$slug]['total']++;
			if ((int) $source->is_active === 1) {
				$coverage[$slug]['active']++;
			}
		}

		return $coverage;
	}
}
