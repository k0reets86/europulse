<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Stats {
	private static function has_koko_table(): bool {
		global $wpdb;
		static $checked = null;
		if ($checked !== null) {
			return $checked;
		}
		$table = $wpdb->prefix . 'koko_analytics_post_stats';
		$checked = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		return $checked;
	}

	private static function today(): string {
		return current_time('Y-m-d');
	}

	public static function bump(string $field, int $amount = 1): void {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_stats';
		$date = self::today();
		$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (metric_date) VALUES (%s)", $date));
		$allowed = ['collected', 'rewritten', 'published', 'duplicates', 'errors', 'ai_tokens'];
		if (in_array($field, $allowed, true)) {
			$wpdb->query($wpdb->prepare("UPDATE {$table} SET {$field} = {$field} + %d WHERE metric_date = %s", $amount, $date));
		}
	}

	public static function add_cost(float $amount): void {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_stats';
		$date = self::today();
		$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (metric_date) VALUES (%s)", $date));
		$wpdb->query($wpdb->prepare("UPDATE {$table} SET ai_cost = ai_cost + %f WHERE metric_date = %s", $amount, $date));
	}

	public static function dashboard(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_stats';
		$date = self::today();
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE metric_date = %s", $date), ARRAY_A);
		return $row ?: ['metric_date' => $date, 'collected' => 0, 'rewritten' => 0, 'published' => 0, 'duplicates' => 0, 'errors' => 0, 'ai_tokens' => 0, 'ai_cost' => 0];
	}

	public static function dashboard_ranges(?string $category = null): array {
		$today = self::today();
		$periods = [
			'day' => ['from' => $today],
			'week' => ['from' => gmdate('Y-m-d', strtotime($today . ' -6 days'))],
			'month' => ['from' => gmdate('Y-m-d', strtotime($today . ' -29 days'))],
			'year' => ['from' => gmdate('Y-m-d', strtotime($today . ' -364 days'))],
		];
		$result = [];
		foreach ($periods as $key => $period) {
			$result[$key] = self::publication_range($period['from'], $today, $category);
		}
		return $result;
	}

	public static function top_publications(string $range = 'month', ?string $category = null, int $limit = 8): array {
		$today = self::today();
		$from = match ($range) {
			'day' => $today,
			'week' => gmdate('Y-m-d', strtotime($today . ' -6 days')),
			'year' => gmdate('Y-m-d', strtotime($today . ' -364 days')),
			default => gmdate('Y-m-d', strtotime($today . ' -29 days')),
		};
		return self::fetch_top_publications($from, $today, $category, $limit);
	}

	public static function ai_usage_today(): array {
		$data = get_option('epv2_ai_usage_' . self::today(), []);
		return is_array($data) ? $data : [];
	}

	public static function bump_ai_request(string $provider, string $model, int $tokens = 0, float $cost = 0.0): void {
		$date = self::today();
		$key = 'epv2_ai_usage_' . $date;
		$data = get_option($key, []);
		$data = is_array($data) ? $data : [];
		$provider = sanitize_key($provider);
		$model = sanitize_key($model);
		if (! isset($data[$provider])) {
			$data[$provider] = [];
		}
		if (! isset($data[$provider][$model])) {
			$data[$provider][$model] = ['requests' => 0, 'tokens' => 0, 'cost' => 0.0];
		}
		$data[$provider][$model]['requests']++;
		$data[$provider][$model]['tokens'] += max(0, $tokens);
		$data[$provider][$model]['cost'] += max(0, $cost);
		update_option($key, $data, false);
	}

	public static function reset_today(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_stats';
		$date = self::today();
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$table} WHERE metric_date = %s",
			$date
		));
		delete_option('epv2_ai_usage_' . $date);
	}

	private static function publication_range(string $from, string $to, ?string $category = null): array {
		global $wpdb;
		$totals = self::stats_range_totals($from, $to);
		$publications = self::published_bundle_count($from, $to, null);
		$published_filtered = self::published_bundle_count($from, $to, $category);
		$engagement = self::publication_engagement($from, $to, $category);
		$visitors = (int) ($engagement['visitors'] ?? 0);
		$pageviews = (int) ($engagement['pageviews'] ?? 0);
		return [
			'published' => $publications,
			'published_filtered' => $category !== null && $category !== '' ? $published_filtered : $publications,
			'collected' => (int) ($totals['collected'] ?? 0),
			'rewritten' => (int) ($totals['rewritten'] ?? 0),
			'duplicates' => (int) ($totals['duplicates'] ?? 0),
			'errors' => (int) ($totals['errors'] ?? 0),
			'ai_tokens' => (int) ($totals['ai_tokens'] ?? 0),
			'visitors' => $visitors,
			'pageviews' => $pageviews,
			'avg_views' => $published_filtered > 0 ? round($pageviews / $published_filtered, 1) : 0,
			'avg_visitors' => $published_filtered > 0 ? round($visitors / $published_filtered, 1) : 0,
		];
	}

	private static function stats_range_totals(string $from, string $to): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_stats';
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT
				COALESCE(SUM(collected), 0) AS collected,
				COALESCE(SUM(rewritten), 0) AS rewritten,
				COALESCE(SUM(duplicates), 0) AS duplicates,
				COALESCE(SUM(errors), 0) AS errors,
				COALESCE(SUM(ai_tokens), 0) AS ai_tokens
			FROM {$table}
			WHERE metric_date >= %s AND metric_date <= %s",
			$from,
			$to
		), ARRAY_A);
		return is_array($row) ? $row : [];
	}

	private static function published_bundle_count(string $from, string $to, ?string $category = null): int {
		global $wpdb;
		$posts = $wpdb->posts;
		$postmeta = $wpdb->postmeta;
		$sql = "SELECT COUNT(DISTINCT qpm.meta_value)
			FROM {$posts} p
			INNER JOIN {$postmeta} qpm ON qpm.post_id = p.ID AND qpm.meta_key = '_epv2_queue_id'";
		$args = [];
		if ($category !== null && $category !== '') {
			$sql .= " INNER JOIN {$postmeta} cpm ON cpm.post_id = p.ID AND cpm.meta_key = '_epv2_primary_category' AND cpm.meta_value = %s";
			$args[] = $category;
		}
		$sql .= " WHERE p.post_type = 'post' AND p.post_status = 'publish' AND DATE(p.post_date) >= %s AND DATE(p.post_date) <= %s";
		$args[] = $from;
		$args[] = $to;
		return (int) $wpdb->get_var($wpdb->prepare($sql, ...$args));
	}

	private static function publication_engagement(string $from, string $to, ?string $category = null): array {
		global $wpdb;
		if (! self::has_koko_table()) {
			return [
				'visitors' => 0,
				'pageviews' => 0,
			];
		}
		$posts = $wpdb->posts;
		$postmeta = $wpdb->postmeta;
		$koko = $wpdb->prefix . 'koko_analytics_post_stats';
		$sql = "SELECT
				COALESCE(SUM(k.visitors),0) AS visitors,
				COALESCE(SUM(k.pageviews),0) AS pageviews
			FROM {$posts} p
			INNER JOIN {$postmeta} qpm ON qpm.post_id = p.ID AND qpm.meta_key = '_epv2_queue_id'";
		$args = [];
		if ($category !== null && $category !== '') {
			$sql .= " INNER JOIN {$postmeta} cpm ON cpm.post_id = p.ID AND cpm.meta_key = '_epv2_primary_category' AND cpm.meta_value = %s";
			$args[] = $category;
		}
		$sql .= " LEFT JOIN {$koko} k ON k.post_id = p.ID AND k.date >= %s AND k.date <= %s
			WHERE p.post_type = 'post' AND p.post_status = 'publish' AND DATE(p.post_date) >= %s AND DATE(p.post_date) <= %s";
		$args[] = $from;
		$args[] = $to;
		$args[] = $from;
		$args[] = $to;
		$row = $wpdb->get_row($wpdb->prepare($sql, ...$args), ARRAY_A);
		return is_array($row) ? $row : [];
	}

	private static function fetch_top_publications(string $from, string $to, ?string $category = null, int $limit = 8): array {
		global $wpdb;
		if (! self::has_koko_table()) {
			return [];
		}
		$posts = $wpdb->posts;
		$postmeta = $wpdb->postmeta;
		$koko = $wpdb->prefix . 'koko_analytics_post_stats';
		$sql = "SELECT p.ID, p.post_title,
			COALESCE(SUM(k.visitors),0) AS visitors,
			COALESCE(SUM(k.pageviews),0) AS pageviews
			FROM {$posts} p
			INNER JOIN {$postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_epv2_queue_id'";
		$args = [];
		$ids = self::category_term_ids($category);
		if ($ids !== []) {
			$placeholders = implode(',', array_fill(0, count($ids), '%d'));
			$sql .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category' AND tt.term_id IN ({$placeholders})";
			$args = array_merge($args, $ids);
		}
		$sql .= " LEFT JOIN {$koko} k ON k.post_id = p.ID AND k.date >= %s AND k.date <= %s
			WHERE p.post_type = 'post' AND p.post_status = 'publish' AND DATE(p.post_date) >= %s AND DATE(p.post_date) <= %s
			GROUP BY p.ID
			ORDER BY pageviews DESC, visitors DESC, p.post_date DESC
			LIMIT %d";
		$args = array_merge($args, [$from, $to, $from, $to, $limit]);
		$rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
		return is_array($rows) ? $rows : [];
	}

	private static function category_term_ids(?string $category = null): array {
		if ($category === null || $category === '') {
			return [];
		}
		$ids = [];
		foreach (['de', 'uk', 'en'] as $lang) {
			$mapped = EPV2_Taxonomy_Map::map($category, $lang);
			$termId = (int) ($mapped['term_id'] ?? 0);
			if ($termId > 0) {
				$ids[] = $termId;
			}
		}
		return array_values(array_unique(array_filter($ids)));
	}
}
