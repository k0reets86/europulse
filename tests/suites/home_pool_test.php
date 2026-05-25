<?php

if (! defined('ABSPATH')) {
	echo "ERROR: home_pool_test.php must run via `wp eval-file`.\n";
	exit(1);
}

$failures = [];

$assert = static function (string $name, bool $condition, string $reason = '') use (&$failures): void {
	if ($condition) {
		echo "[PASS] {$name}\n";
		return;
	}
	$failures[] = $name . ($reason !== '' ? ': ' . $reason : '');
	echo "[FAIL] {$name}" . ($reason !== '' ? ': ' . $reason : '') . "\n";
};

$skip = static function (string $name, string $reason): void {
	echo "[SKIP] {$name}: {$reason}\n";
};

$assert(
	'home selection resolver is loaded',
	function_exists('europulse_home_resolve_selection'),
	'europulse_home_resolve_selection missing'
);

if (function_exists('europulse_home_resolve_selection')) {
	$resolved = europulse_home_resolve_selection('review', 46, 'low', 12);
	$assert(
		'post selection decision overrides stale queue decision',
		($resolved['decision'] ?? '') === 'review' && (int) ($resolved['score'] ?? 0) === 46 && ($resolved['source'] ?? '') === 'post',
		wp_json_encode($resolved, JSON_UNESCAPED_UNICODE)
	);

	$resolved = europulse_home_resolve_selection('', 0, 'low', 12);
	$assert(
		'queue selection is fallback only when post decision is absent',
		($resolved['decision'] ?? '') === 'low' && (int) ($resolved['score'] ?? 0) === 12 && ($resolved['source'] ?? '') === 'queue',
		wp_json_encode($resolved, JSON_UNESCAPED_UNICODE)
	);

	$resolved = europulse_home_resolve_selection('review', 0, 'strong', 95);
	$assert(
		'post decision does not inherit unrelated queue score',
		($resolved['decision'] ?? '') === 'review' && (int) ($resolved['score'] ?? -1) === 0,
		wp_json_encode($resolved, JSON_UNESCAPED_UNICODE)
	);
}

global $wpdb;

$queue_id = 6395;
$queue_table = $wpdb->prefix . 'epv2_queue';
$queue_row = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT id, state, admin_notes
		FROM {$queue_table}
		WHERE id = %d",
		$queue_id
	)
);

if (! $queue_row) {
	$skip('live 6395 homepage regression fixture', 'queue row not present on this environment');
} else {
	$post_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT p.ID, p.post_status, p.post_title
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
			WHERE qid.meta_key = '_epv2_queue_id'
			  AND qid.meta_value = %s
			  AND lang.slug = 'de'
			ORDER BY p.ID ASC
			LIMIT 1",
			(string) $queue_id
		)
	);

	if (! $post_row) {
		$skip('live 6395 homepage regression fixture', 'German post not present on this environment');
	} else {
		$post_id = (int) $post_row->ID;
		$queue_notes = json_decode((string) $queue_row->admin_notes, true);
		$queue_decision = sanitize_key((string) ($queue_notes['selection']['decision'] ?? ''));
		$post_decision = sanitize_key((string) get_post_meta($post_id, 'europulse_selection_decision', true));
		$post_score = (int) get_post_meta($post_id, 'europulse_selection_score', true);

		$assert(
			'live 6395 fixture still has stale queue notes',
			in_array($queue_decision, ['low', 'reject'], true) && ($post_decision === 'review' || $post_decision === 'strong' || $post_decision === 'priority') && $post_score >= 40,
			"queue={$queue_decision}, post={$post_decision}, score={$post_score}, post_id={$post_id}"
		);

		$assert(
			'live 6395 post remains homepage eligible',
			function_exists('europulse_home_post_is_eligible') && europulse_home_post_is_eligible($post_id),
			"post_id={$post_id}, title=" . (string) $post_row->post_title
		);

		if (function_exists('europulse_autopilot_home_pool')) {
			$pool = europulse_autopilot_home_pool();
			$entry = $pool[$post_id] ?? [];
			$assert(
				'live 6395 pool entry uses post selection metadata',
				($entry['selection_decision'] ?? '') === $post_decision && (int) ($entry['selection_score'] ?? 0) === $post_score,
				wp_json_encode($entry, JSON_UNESCAPED_UNICODE)
			);
		}
	}
}

if ($failures !== []) {
	echo "\nFailures:\n";
	foreach ($failures as $failure) {
		echo " - {$failure}\n";
	}
	exit(1);
}

echo "\nHome pool regression suite OK.\n";
