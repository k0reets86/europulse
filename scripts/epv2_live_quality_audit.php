<?php

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run with WP-CLI: wp eval-file scripts/epv2_live_quality_audit.php\n");
	exit(1);
}

$limit = (int) getenv('EPV2_AUDIT_LIMIT');
$limit = $limit > 0 ? min($limit, 200) : 80;
$reject_limit_env = getenv('EPV2_AUDIT_REJECT_LIMIT');
$reject_limit = ($reject_limit_env === false || trim((string) $reject_limit_env) === '') ? 20 : (int) $reject_limit_env;
$reject_limit = max(0, min($reject_limit, 50));
$strict = (string) getenv('EPV2_AUDIT_STRICT') === '1';
$since = trim((string) getenv('EPV2_AUDIT_SINCE'));
$since_sql = '';
$query_args = [$limit];
if ($since !== '') {
	$since_ts = strtotime($since . ' UTC');
	if ($since_ts === false || $since_ts <= 0) {
		fwrite(STDERR, "Invalid EPV2_AUDIT_SINCE value: {$since}\n");
		exit(1);
	}
	$since_sql = ' AND updated_at >= %s';
	array_unshift($query_args, gmdate('Y-m-d H:i:s', $since_ts));
}

global $wpdb;

$queue_table = $wpdb->prefix . 'epv2_queue';
$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT *
		FROM {$queue_table}
		WHERE state = 'published'
		{$since_sql}
		ORDER BY updated_at DESC
		LIMIT %d",
		...$query_args
	)
);
$rows = is_array($rows) ? $rows : [];
$queue_ids = array_values(array_filter(array_map(static function ($row): int {
	return (int) ($row->id ?? 0);
}, $rows)));

$post_groups = [];
if ($queue_ids !== []) {
	$ids_sql = implode(',', array_map('intval', $queue_ids));
	$post_rows = $wpdb->get_results(
		"SELECT
			(CAST(qid.meta_value AS UNSIGNED)) AS queue_id,
			p.ID,
			p.post_status,
			p.post_title,
			p.post_content,
			lang.slug AS lang,
			decision.meta_value AS selection_decision,
			score.meta_value AS selection_score
		FROM {$wpdb->postmeta} qid
		JOIN {$wpdb->posts} p
		  ON p.ID = qid.post_id
		 AND p.post_type = 'post'
		LEFT JOIN {$wpdb->term_relationships} tr
		  ON tr.object_id = p.ID
		LEFT JOIN {$wpdb->term_taxonomy} tt
		  ON tt.term_taxonomy_id = tr.term_taxonomy_id
		 AND tt.taxonomy = 'language'
		LEFT JOIN {$wpdb->terms} lang
		  ON lang.term_id = tt.term_id
		LEFT JOIN {$wpdb->postmeta} decision
		  ON decision.post_id = p.ID
		 AND decision.meta_key = 'europulse_selection_decision'
		LEFT JOIN {$wpdb->postmeta} score
		  ON score.post_id = p.ID
		 AND score.meta_key = 'europulse_selection_score'
		WHERE qid.meta_key = '_epv2_queue_id'
		  AND CAST(qid.meta_value AS UNSIGNED) IN ({$ids_sql})
		ORDER BY queue_id ASC, p.ID ASC",
		ARRAY_A
	);
	foreach ((array) $post_rows as $post_row) {
		$qid = (int) ($post_row['queue_id'] ?? 0);
		if ($qid <= 0) {
			continue;
		}
		$lang = sanitize_key((string) ($post_row['lang'] ?? ''));
		$lang = in_array($lang, ['de', 'uk', 'en'], true) ? $lang : 'unknown';
		$post_groups[$qid][$lang] = $post_row;
	}
}

$latest_ids = [];
if (function_exists('europulse_home_zone_ids')) {
	$latest_ids = array_map('intval', europulse_home_zone_ids('latest', min(120, max(30, $limit)), []));
}

$broken_uk_brand_pattern = '/\b(Ки[іїі]в\s*Пост|Ки[іїі]впост|Киівпост|Дойтше\s+Велле|Рейтерс|Блумберг|Ойро[-\s]?Пулсе|Нью[-\s]?Йорк\s+Таймс|Вашингтон\s+Пост)\b/u';
$cyrillic_pattern = '/[А-Яа-яІіЇїЄєҐґ]/u';
$summary = [
	'checked_published_rows' => count($rows),
	'since_utc' => $since_sql !== '' ? (string) $query_args[0] : '',
	'categories' => [],
	'queue_state_counts' => [],
	'budget_state' => [],
	'warn_counts' => [],
	'reject_warn_counts' => [],
	'recent_rejects' => [],
	'findings' => [
		'hard' => [],
		'warn' => [],
		'info' => [],
	],
];
$max_listed_warnings = 20;

$add_warn = static function (array $finding) use (&$summary, $max_listed_warnings): void {
	$type = (string) ($finding['type'] ?? 'unknown');
	$summary['warn_counts'][$type] = ($summary['warn_counts'][$type] ?? 0) + 1;
	if (count($summary['findings']['warn']) < $max_listed_warnings) {
		$summary['findings']['warn'][] = $finding;
	}
};

$state_rows = $wpdb->get_results(
	"SELECT state, COUNT(*) AS cnt
	FROM {$queue_table}
	GROUP BY state
	ORDER BY cnt DESC",
	ARRAY_A
);
foreach ((array) $state_rows as $state_row) {
	$summary['queue_state_counts'][(string) ($state_row['state'] ?? '')] = (int) ($state_row['cnt'] ?? 0);
}

if (class_exists('EPV2_Budget_Manager')) {
	$budget_state = EPV2_Budget_Manager::budget_state();
	$summary['budget_state'] = [
		'rewritten_today' => (int) ($budget_state['rewritten_today'] ?? 0),
		'tokens_today' => (int) ($budget_state['tokens_today'] ?? 0),
		'request_limit' => (int) ($budget_state['request_limit'] ?? 0),
		'token_limit' => (int) ($budget_state['token_limit'] ?? 0),
		'hard_stop' => ! empty($budget_state['hard_stop']),
	];
}

if ($reject_limit > 0) {
	$reject_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, pipeline_stage, category_final, original_title, error_message, admin_notes, ai_payload, updated_at
			FROM {$queue_table}
			WHERE state = 'rejected'
			ORDER BY updated_at DESC
			LIMIT %d",
			$reject_limit
		)
	);
	foreach ((array) $reject_rows as $reject_row) {
		$notes = json_decode((string) ($reject_row->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$payload = json_decode((string) ($reject_row->ai_payload ?? ''), true);
		$payload = is_array($payload) ? $payload : [];
		$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$note_selection = is_array($notes['selection'] ?? null) ? $notes['selection'] : [];
		$payload_selection = is_array($payload['_meta']['selection'] ?? null) ? $payload['_meta']['selection'] : [];
		$note_decision = sanitize_key((string) ($note_selection['decision'] ?? ''));
		$payload_decision = sanitize_key((string) ($payload_selection['decision'] ?? ''));
		$reason = (string) ($system['quarantine_reason'] ?? $system['reject_reason'] ?? $reject_row->error_message ?? '');
		$gate_blockers = [];
		$gate_decision = '';
		if ($payload !== [] && class_exists('EPV2_Publish_Gate')) {
			$gate = EPV2_Publish_Gate::evaluate($reject_row, $payload, ['context' => 'audit_replay']);
			$gate_decision = (string) ($gate['selection_decision'] ?? '');
			$gate_blockers = array_values(array_map('strval', (array) ($gate['blockers'] ?? [])));
		}

		$flags = [];
		if (in_array($payload_decision, ['low', 'reject'], true) && ! in_array($note_decision, ['', 'low', 'reject'], true)) {
			$flags[] = 'stale_payload_selection';
			$summary['reject_warn_counts']['stale_payload_selection'] = ($summary['reject_warn_counts']['stale_payload_selection'] ?? 0) + 1;
		}
		if (str_starts_with($reason, 'stage_attempt_limit_')) {
			$flags[] = 'stage_attempt_limit';
			$summary['reject_warn_counts']['stage_attempt_limit'] = ($summary['reject_warn_counts']['stage_attempt_limit'] ?? 0) + 1;
		}
		if (! empty($system['worker_blockers'])) {
			$flags[] = 'worker_blockers';
			$summary['reject_warn_counts']['worker_blockers'] = ($summary['reject_warn_counts']['worker_blockers'] ?? 0) + 1;
		}

		$summary['recent_rejects'][] = [
			'id' => (int) ($reject_row->id ?? 0),
			'updated_at' => (string) ($reject_row->updated_at ?? ''),
			'category' => sanitize_key((string) ($reject_row->category_final ?? '')),
			'stage' => sanitize_key((string) ($reject_row->pipeline_stage ?? '')),
			'reason' => $reason,
			'note_selection' => $note_decision,
			'payload_selection' => $payload_decision,
			'gate_selection' => $gate_decision,
			'gate_blockers' => $gate_blockers,
			'flags' => $flags,
			'title' => html_entity_decode(wp_strip_all_tags((string) ($reject_row->original_title ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
		];
	}
}

foreach ($rows as $row) {
	$qid = (int) ($row->id ?? 0);
	$category = sanitize_key((string) ($row->category_final ?? 'uncategorized'));
	$category = $category !== '' ? $category : 'uncategorized';
	$summary['categories'][$category] = ($summary['categories'][$category] ?? 0) + 1;

	$posts = $post_groups[$qid] ?? [];
	foreach (['de', 'uk', 'en'] as $lang) {
		if (empty($posts[$lang]) || (string) ($posts[$lang]['post_status'] ?? '') !== 'publish') {
			$summary['findings']['hard'][] = [
				'id' => $qid,
				'type' => 'missing_translation',
				'lang' => $lang,
				'title' => html_entity_decode((string) ($row->original_title ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			];
		}
	}

	$uk_text = '';
	if (! empty($posts['uk'])) {
		$uk_text = (string) ($posts['uk']['post_title'] ?? '') . "\n" . wp_strip_all_tags((string) ($posts['uk']['post_content'] ?? ''));
		if (preg_match($broken_uk_brand_pattern, $uk_text, $m) === 1) {
			$summary['findings']['hard'][] = [
				'id' => $qid,
				'type' => 'broken_uk_brand_or_source_name',
				'match' => $m[0],
				'post_id' => (int) ($posts['uk']['ID'] ?? 0),
			];
		}
	}

	if (! empty($posts['en'])) {
		$en_text = (string) ($posts['en']['post_title'] ?? '') . "\n" . wp_strip_all_tags((string) ($posts['en']['post_content'] ?? ''));
		if (preg_match($cyrillic_pattern, $en_text, $m) === 1) {
			$summary['findings']['hard'][] = [
				'id' => $qid,
				'type' => 'cyrillic_in_english',
				'match' => $m[0],
				'post_id' => (int) ($posts['en']['ID'] ?? 0),
			];
		}
	}

	$payload = json_decode((string) ($row->ai_payload ?? ''), true);
	$payload = is_array($payload) ? $payload : [];
	if (empty($payload['languages'])) {
		$publish_payload = json_decode((string) ($row->publish_payload ?? ''), true);
		if (is_array($publish_payload) && ! empty($publish_payload['languages'])) {
			$payload = $publish_payload;
		}
	}
	if ($payload !== [] && class_exists('EPV2_Publish_Gate')) {
		$gate = EPV2_Publish_Gate::evaluate($row, $payload, ['context' => 'audit_replay']);
		$all_blockers = (array) ($gate['blockers'] ?? []);
		$source_blockers = array_values(array_intersect(['thin_source_dossier', 'source_expansion_risk', 'context_reject', 'stale_context'], $all_blockers));
		$contract_blockers = array_values(array_intersect(['payload_contract', 'stage_contract', 'quality_contract'], $all_blockers));
		if ($contract_blockers !== []) {
			$summary['warn_counts']['legacy_payload_contract_replay'] = ($summary['warn_counts']['legacy_payload_contract_replay'] ?? 0) + 1;
		}
		if ($source_blockers !== []) {
			$add_warn([
				'id' => $qid,
				'type' => 'published_item_source_risk',
				'blockers' => $source_blockers,
				'category' => $category,
				'title' => (string) ($posts['de']['post_title'] ?? $row->original_title ?? ''),
			]);
		}
	}

	$queue_notes = json_decode((string) ($row->admin_notes ?? ''), true);
	$queue_decision = sanitize_key((string) ($queue_notes['selection']['decision'] ?? ''));
	$post_decision = sanitize_key((string) ($posts['de']['selection_decision'] ?? ''));
	$post_score = (int) ($posts['de']['selection_score'] ?? 0);
	if (in_array($queue_decision, ['low', 'reject'], true) && ($post_decision === 'strong' || $post_decision === 'priority' || ($post_decision === 'review' && $post_score >= 40))) {
		$summary['findings']['info'][] = [
			'id' => $qid,
			'type' => 'queue_post_selection_mismatch',
			'queue_decision' => $queue_decision,
			'post_decision' => $post_decision,
			'post_score' => $post_score,
		];
	}

	if (! empty($posts['de']) && $latest_ids !== []) {
		$post_id = (int) ($posts['de']['ID'] ?? 0);
		$post_decision = sanitize_key((string) ($posts['de']['selection_decision'] ?? ''));
		$post_score = (int) ($posts['de']['selection_score'] ?? 0);
		$site_publishable = $post_decision === 'strong'
			|| $post_decision === 'priority'
			|| ($post_decision === 'review' && $post_score >= 40);
		if ($site_publishable && $post_id > 0 && ! in_array($post_id, $latest_ids, true)) {
			$summary['findings']['info'][] = [
				'id' => $qid,
				'type' => 'publishable_not_in_latest_sample',
				'post_id' => $post_id,
				'post_decision' => $post_decision,
				'post_score' => $post_score,
			];
		}
	}
}

ksort($summary['categories']);

echo wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

if ($strict && $summary['findings']['hard'] !== []) {
	exit(1);
}
