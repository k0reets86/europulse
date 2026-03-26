<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Category_Planner {
	public static function defaults(): array {
		return [
			'deutschland' => ['min' => 2, 'target' => 3, 'max' => 4, 'upgrade_threshold' => 7],
			'münchen' => ['min' => 1, 'target' => 2, 'max' => 3, 'upgrade_threshold' => 7],
			'bayern' => ['min' => 1, 'target' => 2, 'max' => 3, 'upgrade_threshold' => 7],
			'ukraine' => ['min' => 1, 'target' => 2, 'max' => 4, 'upgrade_threshold' => 8],
			'europa' => ['min' => 1, 'target' => 2, 'max' => 3, 'upgrade_threshold' => 7],
			'politik' => ['min' => 2, 'target' => 4, 'max' => 6, 'upgrade_threshold' => 8],
			'wirtschaft' => ['min' => 2, 'target' => 3, 'max' => 4, 'upgrade_threshold' => 8],
			'world' => ['min' => 1, 'target' => 2, 'max' => 4, 'upgrade_threshold' => 8],
			'leben-in-deutschland' => ['min' => 2, 'target' => 3, 'max' => 4, 'upgrade_threshold' => 7],
			'kultur' => ['min' => 0, 'target' => 1, 'max' => 2, 'upgrade_threshold' => 10],
			'sport' => ['min' => 0, 'target' => 1, 'max' => 2, 'upgrade_threshold' => 10],
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

	public static function decide_for_candidate(string $category, int $score): array {
		$category = sanitize_text_field($category);
		$plan = self::category_plan($category);
		$stats = self::today_stats($category);

		if ($stats['selected_count'] < $plan['target']) {
			return ['action' => 'select', 'reason' => 'below_target', 'plan' => $plan, 'stats' => $stats];
		}

		if ($stats['selected_count'] < $plan['max'] && $score >= max(44, $stats['weakest_selected_score'] + 1)) {
			return ['action' => 'select', 'reason' => 'within_max_and_strong', 'plan' => $plan, 'stats' => $stats];
		}

		if ($stats['weakest_selected_id'] > 0 && $score >= ($stats['weakest_selected_score'] + $plan['upgrade_threshold'])) {
			return [
				'action' => 'replace',
				'reason' => 'stronger_than_weakest_selected',
				'replace_id' => $stats['weakest_selected_id'],
				'plan' => $plan,
				'stats' => $stats,
			];
		}

		return ['action' => 'reject', 'reason' => 'category_slot_taken_by_stronger_items', 'plan' => $plan, 'stats' => $stats];
	}

	public static function today_stats(string $category): array {
		global $wpdb;
		$category = sanitize_text_field($category);
		$start = gmdate('Y-m-d 00:00:00');
		$selected_states = ['new', 'processing_de', 'retry_process', 'ready_publish', 'publishing', 'draft_created', 'pending_review', 'partially_created'];
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

	private static function score_from_row(array $row): int {
		$score = (int) ($row['story_score'] ?? 0);
		if ($score > 0) {
			return $score;
		}
		$notes = json_decode((string) ($row['admin_notes'] ?? ''), true);
		return (int) ($notes['selection']['score'] ?? 0);
	}
}
