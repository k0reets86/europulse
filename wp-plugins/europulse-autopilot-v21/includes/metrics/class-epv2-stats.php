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

	/**
	 * Static cache to prevent double-recording within single PHP process.
	 * Persistent idempotency lives in epv2_ai_usage_runtime_seen_${date};
	 * this cache only saves option reads during a hot request.
	 */
	private static array $recorded_runtime_entry_keys = [];

	private const AI_RUNTIME_SEEN_LIMIT = 5000;

	private static function runtime_seen_option_key(): string {
		return 'epv2_ai_usage_runtime_seen_' . self::today();
	}

	private static function runtime_entry_key(int $item_id, int $index, array $entry): string {
		return md5(wp_json_encode([
			'item_id' => $item_id,
			'index' => $index,
			'stage' => (string) ($entry['stage'] ?? ''),
			'provider' => (string) ($entry['provider'] ?? ''),
			'model' => (string) ($entry['model'] ?? ''),
			'tokens' => (int) ($entry['tokens'] ?? 0),
			'cached_tokens' => (int) ($entry['cached_tokens'] ?? 0),
		], JSON_UNESCAPED_UNICODE));
	}

	private static function trim_runtime_seen(array $seen): array {
		if (count($seen) <= self::AI_RUNTIME_SEEN_LIMIT) {
			return $seen;
		}
		return array_slice($seen, -((int) (self::AI_RUNTIME_SEEN_LIMIT / 2)), null, true);
	}

	/**
	 * Pull per-stage AI runtime entries from a worker payload and write only
	 * not-yet-recorded entries to both daily aggregates and per-provider
	 * counters. Worker payloads are cumulative; counting the full runtime on
	 * every stage transition inflates the daily budget by 3x+.
	 */
	public static function record_payload_ai_usage(array $payload, int $item_id = 0): void {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$runtime = is_array($meta['ai_runtime'] ?? null) ? $meta['ai_runtime'] : [];
		$payload_total = (int) ($meta['tokens'] ?? 0);

		$seen_key = self::runtime_seen_option_key();
		$seen = get_option($seen_key, []);
		$seen = is_array($seen) ? $seen : [];
		$changed_seen = false;
		$runtime_total = 0;
		foreach ($runtime as $index => $entry) {
			if (! is_array($entry)) {
				continue;
			}
			$entry_key = self::runtime_entry_key($item_id, (int) $index, $entry);
			if (isset(self::$recorded_runtime_entry_keys[$entry_key]) || isset($seen[$entry_key])) {
				continue;
			}
			self::$recorded_runtime_entry_keys[$entry_key] = true;
			$seen[$entry_key] = time();
			$changed_seen = true;
			$provider = (string) ($entry['provider'] ?? '');
			$model = (string) ($entry['model'] ?? '');
			$stage_tokens = (int) ($entry['tokens'] ?? 0);
			if ($provider === '' && $model === '') {
				$runtime_total += max(0, $stage_tokens);
				continue;
			}
			if ($stage_tokens > 0) {
				self::bump_ai_request($provider, $model, $stage_tokens, 0.0);
				$runtime_total += $stage_tokens;
			} else {
				self::bump_ai_request($provider, $model, 0, 0.0);
			}
		}
		if ($runtime === [] && $payload_total > 0) {
			$fallback_entry = ['stage' => 'payload_total', 'tokens' => $payload_total];
			$entry_key = self::runtime_entry_key($item_id, 0, $fallback_entry);
			if (! isset(self::$recorded_runtime_entry_keys[$entry_key]) && ! isset($seen[$entry_key])) {
				self::$recorded_runtime_entry_keys[$entry_key] = true;
				$seen[$entry_key] = time();
				$changed_seen = true;
				$runtime_total += $payload_total;
			}
		}
		if ($runtime_total > 0) {
			self::bump('ai_tokens', $runtime_total);
		}
		if ($changed_seen) {
			update_option($seen_key, self::trim_runtime_seen($seen), false);
		}
		if (count(self::$recorded_runtime_entry_keys) > self::AI_RUNTIME_SEEN_LIMIT) {
			self::$recorded_runtime_entry_keys = array_slice(
				self::$recorded_runtime_entry_keys,
				-((int) (self::AI_RUNTIME_SEEN_LIMIT / 2)),
				null,
				true
			);
		}
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
		delete_option('epv2_ai_usage_runtime_seen_' . $date);
	}

	/**
	 * R12 2026-05-14: hourly throughput за последние 24 часа.
	 * Возвращает [{hour_label, count}, ...] — 24 entries в chrono order.
	 * Используется dashboard widget'ом для visualisation.
	 */
	public static function throughput_24h_hourly(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT HOUR(post_date_gmt) AS h, DATE(post_date_gmt) AS d, COUNT(*) AS c
			 FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
			 GROUP BY d, h
			 ORDER BY d ASC, h ASC",
			ARRAY_A
		);
		$counts = [];
		foreach ((array) $rows as $r) {
			$key = (string) ($r['d'] ?? '') . ' ' . str_pad((string) ($r['h'] ?? 0), 2, '0', STR_PAD_LEFT) . ':00';
			$counts[$key] = (int) ($r['c'] ?? 0);
		}
		// Build full 24h timeline filling gaps with 0.
		$result = [];
		$now = time();
		for ($i = 23; $i >= 0; $i--) {
			$ts = $now - ($i * HOUR_IN_SECONDS);
			$key = gmdate('Y-m-d H', $ts) . ':00';
			$label = gmdate('H', $ts);
			$result[] = ['label' => $label, 'count' => (int) ($counts[$key] ?? 0)];
		}
		return $result;
	}

	/**
	 * R12 2026-05-14: AI cost summary — today vs yesterday vs 7d avg.
	 * Aggregates `epv2_ai_usage_YYYY-MM-DD` options. Returns
	 * `{today_requests, today_tokens, yesterday_requests, yesterday_tokens,
	 *   week_avg_requests, week_avg_tokens}`.
	 */
	public static function ai_cost_summary(): array {
		$today_key = self::today();
		$yesterday_key = gmdate('Y-m-d', strtotime('-1 day'));
		$today_data = get_option('epv2_ai_usage_' . $today_key, []);
		$yesterday_data = get_option('epv2_ai_usage_' . $yesterday_key, []);
		$week_total_req = 0;
		$week_total_tok = 0;
		$week_days = 0;
		for ($i = 1; $i <= 7; $i++) {
			$key = gmdate('Y-m-d', strtotime("-$i day"));
			$data = get_option('epv2_ai_usage_' . $key, []);
			if (! is_array($data) || $data === []) continue;
			$week_days++;
			foreach ($data as $provider => $models) {
				if (! is_array($models)) continue;
				foreach ($models as $model => $stats) {
					if (! is_array($stats)) continue;
					$week_total_req += (int) ($stats['requests'] ?? 0);
					$week_total_tok += (int) ($stats['tokens'] ?? 0);
				}
			}
		}
		$week_avg_req = $week_days > 0 ? (int) round($week_total_req / $week_days) : 0;
		$week_avg_tok = $week_days > 0 ? (int) round($week_total_tok / $week_days) : 0;
		$today_req = 0; $today_tok = 0;
		foreach ((array) $today_data as $provider => $models) {
			if (! is_array($models)) continue;
			foreach ($models as $stats) {
				if (! is_array($stats)) continue;
				$today_req += (int) ($stats['requests'] ?? 0);
				$today_tok += (int) ($stats['tokens'] ?? 0);
			}
		}
		$y_req = 0; $y_tok = 0;
		foreach ((array) $yesterday_data as $provider => $models) {
			if (! is_array($models)) continue;
			foreach ($models as $stats) {
				if (! is_array($stats)) continue;
				$y_req += (int) ($stats['requests'] ?? 0);
				$y_tok += (int) ($stats['tokens'] ?? 0);
			}
		}
		return [
			'today_requests' => $today_req,
			'today_tokens' => $today_tok,
			'yesterday_requests' => $y_req,
			'yesterday_tokens' => $y_tok,
			'week_avg_requests' => $week_avg_req,
			'week_avg_tokens' => $week_avg_tok,
			'week_days_sampled' => $week_days,
		];
	}

	/**
	 * R6 2026-05-14: classify rejected items by error_message pattern.
	 *
	 * Returns histogram bucket counts. Used by dashboard widget.
	 * Includes state='rejected', 'error', 'duplicate' (terminal failure
	 * states). For period — hours back from now (24h, 168h=7d).
	 */
	public static function rejected_reason_histogram(int $hours = 24): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$hours = max(1, min(720, $hours));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT error_message, COUNT(*) AS c FROM {$table}
			 WHERE state IN ('rejected', 'error', 'duplicate')
			   AND updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
			 GROUP BY error_message",
			$hours
		), ARRAY_A);
		$buckets = [
			'selection' => 0,
			'dedup' => 0,
			'editorial_weak' => 0,
			'thin_source' => 0,
			'hallucination' => 0,
			'cross_lang' => 0,
			'chronic' => 0,
			'attempt_cap' => 0,
			'expired' => 0,
			'auto_reject_policy' => 0,
			'media' => 0,
			'plagiarism' => 0,
			'other' => 0,
		];
		if (! is_array($rows)) {
			return $buckets;
		}
		foreach ($rows as $r) {
			$msg = strtolower((string) ($r['error_message'] ?? ''));
			$c = (int) ($r['c'] ?? 0);
			if ($c === 0) continue;
			$matched = false;
			// Order matters: more specific patterns first.
			if (preg_match('/auto_reject_review_policy/', $msg)) { $buckets['auto_reject_policy'] += $c; $matched = true; }
			elseif (preg_match('/duplicate|dedup|signature/', $msg)) { $buckets['dedup'] += $c; $matched = true; }
			elseif (preg_match('/invented_number|invented_quote|invented_publisher|hallucin/', $msg)) { $buckets['hallucination'] += $c; $matched = true; }
			elseif (preg_match('/cross_lang|title_substitution/', $msg)) { $buckets['cross_lang'] += $c; $matched = true; }
			elseif (preg_match('/plagiarism|uniqueness/', $msg)) { $buckets['plagiarism'] += $c; $matched = true; }
			elseif (preg_match('/chronic_recycler/', $msg)) { $buckets['chronic'] += $c; $matched = true; }
			elseif (preg_match('/thin.*source|sources_below|enrichment_required|too thin/', $msg)) { $buckets['thin_source'] += $c; $matched = true; }
			elseif (preg_match('/editorial.*reject|reject_low_value/', $msg)) { $buckets['editorial_weak'] += $c; $matched = true; }
			elseif (preg_match('/attempt_cap|stage_attempt_limit|rebuild_bundle_attempt|inline_stage/', $msg)) { $buckets['attempt_cap'] += $c; $matched = true; }
			elseif (preg_match('/expired|stale_time|live_angle/', $msg)) { $buckets['expired'] += $c; $matched = true; }
			elseif (preg_match('/media|featured/', $msg)) { $buckets['media'] += $c; $matched = true; }
			elseif (preg_match('/selection|publish-?grade|low_grade|publish_c/', $msg)) { $buckets['selection'] += $c; $matched = true; }
			if (! $matched) { $buckets['other'] += $c; }
		}
		return $buckets;
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
