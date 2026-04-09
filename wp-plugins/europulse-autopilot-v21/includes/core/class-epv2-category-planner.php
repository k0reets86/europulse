<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Category_Planner {
	private const ACTIVE_MIX_STATES = ['new', 'processing_de', 'retry_process', 'ready_review', 'ready_publish', 'retry_publish', 'publishing', 'published'];

	public static function defaults(): array {
		return [
			'deutschland' => ['min' => 2, 'target' => 4, 'max' => 5, 'upgrade_threshold' => 7],
			'münchen' => ['min' => 0, 'target' => 1, 'max' => 2, 'upgrade_threshold' => 7],
			'bayern' => ['min' => 1, 'target' => 2, 'max' => 3, 'upgrade_threshold' => 7],
			'ukraine' => ['min' => 2, 'target' => 4, 'max' => 5, 'upgrade_threshold' => 8],
			'europa' => ['min' => 0, 'target' => 1, 'max' => 2, 'upgrade_threshold' => 7],
			'politik' => ['min' => 2, 'target' => 4, 'max' => 5, 'upgrade_threshold' => 8],
			'wirtschaft' => ['min' => 1, 'target' => 2, 'max' => 3, 'upgrade_threshold' => 8],
			'world' => ['min' => 2, 'target' => 3, 'max' => 5, 'upgrade_threshold' => 8],
			'leben-in-deutschland' => ['min' => 1, 'target' => 3, 'max' => 4, 'upgrade_threshold' => 7],
			'kultur' => ['min' => 0, 'target' => 2, 'max' => 3, 'upgrade_threshold' => 10],
			'sport' => ['min' => 1, 'target' => 3, 'max' => 4, 'upgrade_threshold' => 10],
			'community' => ['min' => 0, 'target' => 2, 'max' => 3, 'upgrade_threshold' => 7],
		];
	}

	public static function category_plan(string $category): array {
		$plans = EPV2_Settings::get('category_plans', self::defaults());
		$plan = is_array($plans[$category] ?? null) ? $plans[$category] : (self::defaults()[$category] ?? ['min' => 0, 'target' => 1, 'max' => 2, 'upgrade_threshold' => 8]);
		return [
			'min' => max(0, (int) ($plan['min'] ?? 0)),
			'target' => max(1, (int) ($plan['target'] ?? 1)),
			'max' => max(1, (int) ($plan['max'] ?? 2)),
			'upgrade_threshold' => max(1, (int) ($plan['upgrade_threshold'] ?? 8)),
		];
	}

	public static function target_shares(): array {
		return [
			'world' => 0.125,
			'politik' => 0.125,
			'ukraine' => 0.125,
			'deutschland' => 0.125,
			'wirtschaft' => 0.0833,
			'sport' => 0.0833,
			'kultur' => 0.0583,
			'münchen' => 0.0583,
			'muenchen' => 0.0583,
			'bayern' => 0.05,
			'leben-in-deutschland' => 0.10,
			'community' => 0.0667,
		];
	}

	public static function burst_caps_6h(): array {
		return [
			'world' => 4,
			'politik' => 4,
			'ukraine' => 4,
			'deutschland' => 4,
			'wirtschaft' => 3,
			'sport' => 3,
			'kultur' => 2,
			'münchen' => 2,
			'muenchen' => 2,
			'bayern' => 2,
			'leben-in-deutschland' => 2,
			'community' => 2,
		];
	}

	public static function selection_adjustment(string $category, array $analysis = []): array {
		$category = self::canonical_category($category);
		if ($category === '') {
			return ['delta' => 0, 'reason' => '', 'stats' => []];
		}
		if (self::is_priority_override($analysis)) {
			return ['delta' => 0, 'reason' => 'priority_override', 'stats' => self::window_stats($category)];
		}
		$stats = self::window_stats($category);
		$total24 = max(0, (int) ($stats['total_24h'] ?? 0));
		$cat24 = max(0, (int) ($stats['category_24h'] ?? 0));
		$cat6 = max(0, (int) ($stats['category_6h'] ?? 0));
		$target = (float) (self::target_shares()[$category] ?? 0.0);
		$share24 = $total24 > 0 ? ($cat24 / $total24) : 0.0;
		$cap6 = (int) (self::burst_caps_6h()[$category] ?? 3);

		if ($total24 < 8) {
			return ['delta' => 0, 'reason' => 'insufficient_density', 'stats' => $stats];
		}
		if ($target > 0 && $share24 < max(0.01, $target - 0.05)) {
			return ['delta' => 8, 'reason' => 'underrepresented_24h', 'stats' => $stats];
		}
		if ($cat6 === 0 && $target > 0 && $share24 < $target) {
			return ['delta' => 4, 'reason' => 'missing_recent_presence', 'stats' => $stats];
		}
		if ($cat6 >= $cap6 + 2 && $share24 > ($target + 0.08)) {
			return ['delta' => -12, 'reason' => 'burst_and_overrepresented', 'stats' => $stats];
		}
		if ($cat6 >= $cap6 && $share24 > ($target + 0.04)) {
			return ['delta' => -6, 'reason' => 'burst_soft_throttle', 'stats' => $stats];
		}

		return ['delta' => 0, 'reason' => '', 'stats' => $stats];
	}

	public static function decide_for_candidate(string $category, int $score, array $analysis = []): array {
		$category = self::canonical_category($category);
		$plan = self::category_plan($category);
		$stats = self::today_stats($category);
		$mix = self::window_stats($category);
		$target = (float) (self::target_shares()[$category] ?? 0.0);
		$share24 = (int) ($mix['total_24h'] ?? 0) > 0 ? ((int) ($mix['category_24h'] ?? 0) / max(1, (int) $mix['total_24h'])) : 0.0;
		$cap6 = (int) (self::burst_caps_6h()[$category] ?? 3);

		if (self::is_priority_override($analysis)) {
			return ['action' => 'select', 'reason' => 'priority_override', 'plan' => $plan, 'stats' => $stats, 'mix' => $mix];
		}
		if ((int) ($mix['total_24h'] ?? 0) >= 8 && $target > 0 && $share24 < max(0.01, $target - 0.05)) {
			return ['action' => 'select', 'reason' => 'underrepresented_24h', 'plan' => $plan, 'stats' => $stats, 'mix' => $mix];
		}
		if ((int) ($mix['category_6h'] ?? 0) >= ($cap6 + 2) && $share24 > ($target + 0.08) && $score < 58) {
			return ['action' => 'reject', 'reason' => 'soft_mix_burst_reject', 'plan' => $plan, 'stats' => $stats, 'mix' => $mix];
		}

		if ($stats['selected_count'] < $plan['target']) {
			return ['action' => 'select', 'reason' => 'below_target', 'plan' => $plan, 'stats' => $stats, 'mix' => $mix];
		}

		if ($stats['selected_count'] < $plan['max'] && $score >= max(44, $stats['weakest_selected_score'] + 1)) {
			return ['action' => 'select', 'reason' => 'within_max_and_strong', 'plan' => $plan, 'stats' => $stats, 'mix' => $mix];
		}

		if ($stats['weakest_selected_id'] > 0 && $score >= ($stats['weakest_selected_score'] + $plan['upgrade_threshold'])) {
			return [
				'action' => 'replace',
				'reason' => 'stronger_than_weakest_selected',
				'replace_id' => $stats['weakest_selected_id'],
				'plan' => $plan,
				'stats' => $stats,
				'mix' => $mix,
			];
		}

		return ['action' => 'select', 'reason' => 'soft_accept_without_slot_reject', 'plan' => $plan, 'stats' => $stats, 'mix' => $mix];
	}

	public static function should_throttle_publish(string $category, array $analysis = []): bool {
		$category = self::canonical_category($category);
		if ($category === '' || self::is_priority_override($analysis)) {
			return false;
		}
		$stats = self::window_stats($category);
		$total24 = max(0, (int) ($stats['total_24h'] ?? 0));
		$cat24 = max(0, (int) ($stats['category_24h'] ?? 0));
		$cat6 = max(0, (int) ($stats['category_6h'] ?? 0));
		$target = (float) (self::target_shares()[$category] ?? 0.0);
		$share24 = $total24 > 0 ? ($cat24 / $total24) : 0.0;
		$cap6 = (int) (self::burst_caps_6h()[$category] ?? 3);

		return $total24 >= 8 && $cat6 >= $cap6 && $target > 0 && $share24 > ($target + 0.04);
	}

	public static function today_stats(string $category): array {
		global $wpdb;
		$category = sanitize_text_field($category);
		$start = gmdate('Y-m-d 00:00:00');
		$selected_states = ['new', 'processing_de', 'retry_process', 'ready_publish', 'publishing'];
		$reserve_states = [];
		$selected = self::rows_for_category($category, $selected_states, $start);
		$reserve = self::rows_for_category($category, $reserve_states, $start);
		return [
			'selected_count' => count($selected),
			'reserve_count' => count($reserve),
			'weakest_selected_id' => $selected[0]['id'] ?? 0,
			'weakest_selected_score' => $selected[0]['score'] ?? 0,
			'weakest_reserve_score' => $reserve[0]['score'] ?? 0,
		];
	}

	private static function rows_for_category(string $category, array $states, string $start): array {
		global $wpdb;
		if ($states === []) {
			return [];
		}
		$placeholders = implode(',', array_fill(0, count($states), '%s'));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, story_score, admin_notes
				FROM {$wpdb->prefix}epv2_queue
				WHERE created_at >= %s
				AND state IN ({$placeholders})
				AND (FIND_IN_SET(%s, category_final) OR FIND_IN_SET(%s, category_proposed))
				ORDER BY created_at DESC",
				array_merge([$start], $states, [$category, $category])
			),
			ARRAY_A
		);
		$rows = is_array($rows) ? $rows : [];
		foreach ($rows as &$row) {
			$row['score'] = self::score_from_row($row);
		}
		unset($row);
		usort($rows, static fn(array $a, array $b): int => $a['score'] <=> $b['score']);
		return $rows;
	}

	private static function window_stats(string $category): array {
		global $wpdb;
		$category = self::canonical_category($category);
		if ($category === '') {
			return ['total_24h' => 0, 'category_24h' => 0, 'category_6h' => 0];
		}

		$states = self::ACTIVE_MIX_STATES;
		$placeholders = implode(',', array_fill(0, count($states), '%s'));
		$window24 = gmdate('Y-m-d H:i:s', time() - (24 * HOUR_IN_SECONDS));
		$window6 = gmdate('Y-m-d H:i:s', time() - (6 * HOUR_IN_SECONDS));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, state, category_final, category_proposed, updated_at
				FROM {$wpdb->prefix}epv2_queue
				WHERE state IN ({$placeholders})
				  AND updated_at >= %s",
				...array_merge($states, [$window24])
			),
			ARRAY_A
		);
		$rows = is_array($rows) ? $rows : [];
		$total24 = count($rows);
		$category24 = 0;
		$category6 = 0;
		foreach ($rows as $row) {
			$rowCategories = self::row_categories_from_array($row);
			if (! in_array($category, $rowCategories, true)) {
				continue;
			}
			$category24++;
			$updatedAt = (string) ($row['updated_at'] ?? '');
			if ($updatedAt !== '' && strcmp($updatedAt, $window6) >= 0) {
				$category6++;
			}
		}

		return [
			'total_24h' => $total24,
			'category_24h' => $category24,
			'category_6h' => $category6,
		];
	}

	private static function row_categories_from_array(array $row): array {
		$raw = trim((string) ($row['category_final'] ?? ''));
		if ($raw === '') {
			$raw = trim((string) ($row['category_proposed'] ?? ''));
		}
		$parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
		$parts = array_map([self::class, 'canonical_category'], $parts);
		return array_values(array_unique(array_filter($parts)));
	}

	private static function canonical_category(string $category): string {
		$category = sanitize_text_field($category);
		if ($category === 'münchen') {
			return 'muenchen';
		}
		return $category;
	}

	private static function is_priority_override(array $analysis): bool {
		return ! empty($analysis['breaking_candidate'])
			|| ! empty($analysis['top_story_candidate'])
			|| (string) ($analysis['decision'] ?? '') === 'priority';
	}

	private static function score_from_row(array $row): int {
		$score = (int) ($row['story_score'] ?? 0);
		if ($score > 0) {
			return $score;
		}
		$notes = json_decode((string) ($row['admin_notes'] ?? ''), true);
		return (int) ($notes['selection']['score'] ?? 0);
	}
}
