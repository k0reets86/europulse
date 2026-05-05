<?php

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run with WP-CLI: wp eval-file scripts/epv2_recalibrate_new_items.php -- --since-id=1092 --apply\n");
	exit(1);
}

$since_id = 0;
$exact_id = 0;
$apply = false;
$limit = 50;
$cli_args = array_merge((array) ($argv ?? []), (array) ($args ?? []));
foreach ($cli_args as $arg) {
	$arg = (string) $arg;
	if ($arg === '--apply' || $arg === 'apply') {
		$apply = true;
	}
	if (preg_match('/^\d+$/', $arg) === 1) {
		$since_id = max(0, (int) $arg);
	}
	if (preg_match('/^--since-id=(\d+)$/', $arg, $m)) {
		$since_id = max(0, (int) $m[1]);
	}
	if (preg_match('/^since-id=(\d+)$/', $arg, $m)) {
		$since_id = max(0, (int) $m[1]);
	}
	if (preg_match('/^--id=(\d+)$/', $arg, $m)) {
		$exact_id = max(0, (int) $m[1]);
	}
	if (preg_match('/^id=(\d+)$/', $arg, $m)) {
		$exact_id = max(0, (int) $m[1]);
	}
	if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
		$limit = max(1, min(500, (int) $m[1]));
	}
	if (preg_match('/^limit=(\d+)$/', $arg, $m)) {
		$limit = max(1, min(500, (int) $m[1]));
	}
}

if (! class_exists('EPV2_Budget_Manager') || ! class_exists('EPV2_Queue')) {
	fwrite(STDERR, "EPV2 classes are not loaded\n");
	exit(1);
}

global $wpdb;
$queue_table = $wpdb->prefix . 'epv2_queue';
$sources_table = $wpdb->prefix . 'epv2_sources';

$where = ["q.state = 'new'"];
$params = [];
if ($exact_id > 0) {
	$where[] = 'q.id = %d';
	$params[] = $exact_id;
} elseif ($since_id > 0) {
	$where[] = 'q.id >= %d';
	$params[] = $since_id;
}

$sql = "SELECT q.*, s.name AS source_name, s.type AS source_type, s.category_bias AS source_category_bias, s.priority AS source_priority, s.risk_level AS source_risk
	FROM {$queue_table} q
	LEFT JOIN {$sources_table} s ON s.id = q.source_id
	WHERE " . implode(' AND ', $where) . "
	ORDER BY q.id ASC
	LIMIT %d";
$params[] = $limit;
$rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

$summary = [
	'apply' => $apply,
	'since_id' => $since_id,
	'exact_id' => $exact_id,
	'checked' => 0,
	'updated' => 0,
	'terminalized' => 0,
	'items' => [],
];

$auto_publish_grade = ((string) EPV2_Settings::get('mode', 'semi') === 'auto')
	&& in_array((string) EPV2_Settings::get('default_post_status', 'draft'), ['publish', 'pending'], true);

foreach ((array) $rows as $row) {
	$summary['checked']++;
	$source = (object) [
		'id' => (int) ($row->source_id ?? 0),
		'name' => (string) ($row->source_name ?? ''),
		'type' => (string) ($row->source_type ?? ''),
		'category_bias' => (string) ($row->source_category_bias ?? ''),
		'priority' => (int) ($row->source_priority ?? 5),
		'risk_level' => (string) ($row->source_risk ?? 'low'),
	];
	$analysis = EPV2_Budget_Manager::analyze_item([
		'title' => (string) ($row->original_title ?? ''),
		'excerpt' => (string) ($row->original_excerpt ?? ''),
		'content' => (string) ($row->original_content ?? ''),
		'url' => (string) ($row->original_url ?? ''),
		'date' => (string) ($row->original_date ?? ''),
		'image' => (string) ($row->source_image_url ?? ''),
		'category' => (string) ($source->category_bias ?: ($row->category_proposed ?? '')),
	], $source);
	$category = sanitize_text_field((string) ($analysis['category'] ?? ($row->category_proposed ?? '')));
	$ai_gate = EPV2_Budget_Manager::should_send_to_ai($analysis);
	$planner = EPV2_Category_Planner::decide_for_candidate($category, (int) ($analysis['score'] ?? 0), $analysis);
	$notes = json_decode((string) ($row->admin_notes ?? ''), true);
	$notes = is_array($notes) ? $notes : [];
	$notes['selection'] = $analysis;
	$notes['ai_gate'] = $ai_gate;
	$notes['planner'] = $planner;
	if (isset($notes['cluster']) && is_array($notes['cluster'])) {
		$notes['cluster']['primary_category'] = $category;
	}

	$decision = sanitize_key((string) ($analysis['decision'] ?? ''));
	$should_terminalize = $auto_publish_grade && ! in_array($decision, ['review', 'strong', 'priority'], true);
	$item_result = [
		'id' => (int) $row->id,
		'title' => mb_substr(wp_strip_all_tags((string) ($row->original_title ?? '')), 0, 140),
		'old_category' => (string) ($row->category_proposed ?? ''),
		'new_category' => $category,
		'old_score' => (int) ($row->story_score ?? 0),
		'new_score' => (int) ($analysis['score'] ?? 0),
		'decision' => $decision,
		'tier' => (string) ($analysis['tier'] ?? ''),
		'action' => $should_terminalize ? 'reject_low_grade_new' : 'update_new',
	];

	if ($apply) {
		EPV2_Queue::update_fields((int) $row->id, [
			'category_proposed' => $category,
			'story_score' => max(0, (int) ($analysis['score'] ?? 0)),
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
		$cluster_id = (int) ($row->cluster_id ?? 0);
		if ($cluster_id > 0 && $category !== '') {
			$wpdb->update(
				$wpdb->prefix . 'epv2_clusters',
				['primary_category' => $category],
				['id' => $cluster_id],
				['%s'],
				['%d']
			);
		}
		$summary['updated']++;
		if ($should_terminalize) {
			EPV2_Queue::mark_state((int) $row->id, 'rejected', [
				'error_message' => 'Материал снят после повторной калибровки: selection decision "' . ($decision !== '' ? $decision : 'unknown') . '" не допускает auto publish-grade обработку.',
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			]);
			$summary['terminalized']++;
		}
	}

	$summary['items'][] = $item_result;
}

echo wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
