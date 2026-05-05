<?php

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run with WP-CLI: wp eval-file scripts/epv2_selection_calibration_audit.php -- 7\n");
	exit(1);
}

$days = 7;
$cli_args = array_merge((array) ($argv ?? []), (array) ($args ?? []));
foreach ($cli_args as $arg) {
	if (preg_match('/^(?:--days=|days=)?(\d+)$/', (string) $arg, $m)) {
		$days = max(1, min(30, (int) $m[1]));
	}
}

if (! class_exists('EPV2_Budget_Manager')) {
	fwrite(STDERR, "EPV2_Budget_Manager is not loaded\n");
	exit(1);
}

global $wpdb;

$queue_table = $wpdb->prefix . 'epv2_queue';
$sources_table = $wpdb->prefix . 'epv2_sources';
$since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

$sources = [];
foreach ((array) $wpdb->get_results("SELECT * FROM {$sources_table}") as $source) {
	$sources[(int) $source->id] = $source;
}

$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT *
		FROM {$queue_table}
		WHERE created_at >= %s
		ORDER BY created_at DESC
		LIMIT 500",
		$since
	)
);

$summary = [
	'window_days' => $days,
	'checked' => 0,
	'current_by_state_tier' => [],
	'recomputed_by_state_decision' => [],
	'decision_changes' => [],
	'false_reject_candidates' => [],
	'source_reject_summary' => [],
	'selection_audit' => class_exists('EPV2_Selection_Audit') ? EPV2_Selection_Audit::summary($days) : ['available' => false, 'reason' => 'class_missing'],
];

foreach ((array) $rows as $row) {
	$summary['checked']++;
	$notes = json_decode((string) ($row->admin_notes ?? ''), true);
	$notes = is_array($notes) ? $notes : [];
	$current = is_array($notes['selection'] ?? null) ? $notes['selection'] : [];
	$current_tier = sanitize_key((string) ($current['tier'] ?? 'unknown'));
	$current_decision = sanitize_key((string) ($current['decision'] ?? 'unknown'));
	$state = sanitize_key((string) ($row->state ?? 'unknown'));
	$source = $sources[(int) ($row->source_id ?? 0)] ?? null;
	$source_name = is_object($source) ? (string) ($source->name ?? '') : '';
	$category = (string) ($row->category_final ?: $row->category_proposed ?: ($source->category_bias ?? ''));

	$current_key = $state . ':' . ($current_tier !== '' ? $current_tier : 'unknown');
	$summary['current_by_state_tier'][$current_key] = ($summary['current_by_state_tier'][$current_key] ?? 0) + 1;

	$analysis = EPV2_Budget_Manager::analyze_item([
		'title' => (string) ($row->original_title ?? ''),
		'excerpt' => (string) ($row->original_excerpt ?? ''),
		'content' => (string) ($row->original_content ?? ''),
		'url' => (string) ($row->original_url ?? ''),
		'date' => (string) ($row->original_date ?? ''),
		'image' => (string) ($row->source_image_url ?? ''),
		'category' => $category,
	], $source);

	$new_decision = sanitize_key((string) ($analysis['decision'] ?? 'unknown'));
	$new_tier = sanitize_key((string) ($analysis['tier'] ?? 'unknown'));
	$new_key = $state . ':' . $new_decision;
	$summary['recomputed_by_state_decision'][$new_key] = ($summary['recomputed_by_state_decision'][$new_key] ?? 0) + 1;

	if ($new_decision !== $current_decision || $new_tier !== $current_tier) {
		$summary['decision_changes'][] = [
			'id' => (int) $row->id,
			'state' => $state,
			'source' => $source_name,
			'title' => mb_substr(wp_strip_all_tags((string) ($row->original_title ?? '')), 0, 140),
			'old' => [
				'score' => (int) ($current['score'] ?? $row->story_score ?? 0),
				'tier' => $current_tier,
				'decision' => $current_decision,
			],
			'new' => [
				'score' => (int) ($analysis['score'] ?? 0),
				'tier' => $new_tier,
				'decision' => $new_decision,
				'dynamic_threshold' => $analysis['dynamic_threshold'] ?? [],
				'scorecard' => $analysis['scorecard'] ?? [],
			],
		];
	}

	if ($state === 'rejected') {
		$source_key = $source_name !== '' ? $source_name : '(no source)';
		$summary['source_reject_summary'][$source_key] = ($summary['source_reject_summary'][$source_key] ?? 0) + 1;
		if (! in_array($new_decision, ['low', 'reject'], true)) {
			$summary['false_reject_candidates'][] = [
				'id' => (int) $row->id,
				'source' => $source_name,
				'title' => mb_substr(wp_strip_all_tags((string) ($row->original_title ?? '')), 0, 160),
				'old_decision' => $current_decision,
				'new_decision' => $new_decision,
				'new_tier' => $new_tier,
				'new_score' => (int) ($analysis['score'] ?? 0),
				'reasons' => array_values(array_slice((array) ($analysis['reasons'] ?? []), 0, 6)),
			];
		}
	}
}

ksort($summary['current_by_state_tier']);
ksort($summary['recomputed_by_state_decision']);
arsort($summary['source_reject_summary']);
$summary['decision_changes'] = array_values(array_slice($summary['decision_changes'], 0, 60));
$summary['false_reject_candidates'] = array_values(array_slice($summary['false_reject_candidates'], 0, 60));

echo wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
