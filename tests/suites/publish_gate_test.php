<?php

if (! defined('ABSPATH')) {
	echo "ERROR: publish_gate_test.php must run via `wp eval-file`.\n";
	exit(1);
}

require_once dirname(__DIR__) . '/lib/pipeline_contracts.php';

$failures = [];

$assert = static function (string $name, bool $condition, string $reason = '') use (&$failures): void {
	if ($condition) {
		echo "[PASS] {$name}\n";
		return;
	}
	$failures[] = $name . ($reason !== '' ? ': ' . $reason : '');
	echo "[FAIL] {$name}" . ($reason !== '' ? ': ' . $reason : '') . "\n";
};

$reflect = static function (string $method): ReflectionMethod {
	$ref = new ReflectionMethod('EPV2_Publish_Gate', $method);
	$ref->setAccessible(true);
	return $ref;
};

$source_risk = $reflect('payload_has_source_expansion_risk');
$source_profile = $reflect('source_support_profile');
$selection_floor = $reflect('selection_score_publishable');
$ai_reflect = static function (string $method): ReflectionMethod {
	$ref = new ReflectionMethod('EPV2_AI_Processor', $method);
	$ref->setAccessible(true);
	return $ref;
};
$refresh_selection = $ai_reflect('refresh_selection_from_payload');

$assert(
	'publish gate class is loaded',
	class_exists('EPV2_Publish_Gate'),
	'EPV2_Publish_Gate missing'
);

$assert(
	'publisher class is loaded',
	class_exists('EPV2_Publisher'),
	'EPV2_Publisher missing'
);

$internal_url = home_url('/prior-story/');
$existing_url = home_url('/existing-anchor/');
$image_url = home_url('/image.jpg');
$linked_content = EPV2_Publisher::link_plain_urls(
	'<p>EuroPulse berichtete zuvor: ' . $internal_url . '</p>'
	. '<p><a href="' . esc_url($existing_url) . '">' . esc_html($existing_url) . '</a></p>'
	. '<figure><img src="' . esc_url($image_url) . '" alt=""></figure>'
);
$assert(
	'plain self-reference URL becomes clickable',
	strpos($linked_content, '<a href="' . esc_url($internal_url) . '">' . esc_html($internal_url) . '</a>') !== false,
	$linked_content
);
$assert(
	'existing anchors are not double-linked',
	substr_count($linked_content, '<a href=') === 2,
	$linked_content
);
$assert(
	'image src URLs are not rewritten as anchors',
	strpos($linked_content, '<img src="' . esc_url($image_url) . '"') !== false,
	$linked_content
);

$worker_reflect = static function (string $method): ReflectionMethod {
	$ref = new ReflectionMethod('EPV2_Worker_Client', $method);
	$ref->setAccessible(true);
	return $ref;
};
$prior_terms = $worker_reflect('prior_coverage_search_terms');
$assert(
	'prior coverage keeps person surname matching',
	$prior_terms->invoke(null, 'Thomas Müller', 'person') === ['Müller'],
	wp_json_encode($prior_terms->invoke(null, 'Thomas Müller', 'person'), JSON_UNESCAPED_UNICODE)
);
$org_terms = $prior_terms->invoke(null, 'FC Bayern München', 'organization');
$assert(
	'prior coverage organization terms do not use city-only fallback',
	! in_array('München', $org_terms, true) && in_array('FC Bayern', $org_terms, true),
	wp_json_encode($org_terms, JSON_UNESCAPED_UNICODE)
);
$assert(
	'prior coverage ignores single generic city organization',
	$prior_terms->invoke(null, 'München', 'organization') === [],
	wp_json_encode($prior_terms->invoke(null, 'München', 'organization'), JSON_UNESCAPED_UNICODE)
);

$stale_selection_item = (object) [
	'admin_notes' => wp_json_encode([
		'selection' => [
			'decision' => 'review',
			'score' => 46,
		],
	]),
];
$stale_selection_payload = [
	'_meta' => [
		'selection' => [
			'decision' => 'low',
			'score' => 30,
		],
	],
];
$assert(
	'admin_notes selection overrides stale payload selection',
	EPV2_Publish_Gate::selection_decision($stale_selection_item, $stale_selection_payload) === 'review',
	'canonical queue selection should prevent stale payload selection_low/reject blockers'
);

$runtime_downgrade_payload = [
	'categories' => ['kultur'],
	'languages' => [
		'de' => [
			'title' => 'Horoskop: Die besten Sternzeichen der Woche',
			'excerpt' => 'Ein Ranking zeigt angeblich, welche Sternzeichen jetzt besonders viel Glück haben.',
			'content' => '<p>Horoskop und Ranking der Sternzeichen ohne belastbaren Nachrichtenwert.</p>',
		],
	],
	'_meta' => [
		'selection' => [
			'decision' => 'strong',
			'score' => 61,
			'category' => 'kultur',
		],
	],
];
$refreshed_runtime_payload = $refresh_selection->invoke(null, $runtime_downgrade_payload);
$assert(
	'runtime selection refresh does not downgrade canonical publish-grade selection',
	($refreshed_runtime_payload['_meta']['selection']['decision'] ?? '') === 'strong'
		&& in_array(($refreshed_runtime_payload['_meta']['runtime_selection']['decision'] ?? ''), ['low', 'reject'], true),
	wp_json_encode($refreshed_runtime_payload['_meta'] ?? [], JSON_UNESCAPED_UNICODE)
);

$assert(
	'selection floor blocks serious category below 45',
	! (bool) $selection_floor->invoke(null, 40, 'politik', false, [], null),
	'politik score 40 should not be publishable'
);

$assert(
	'selection floor allows weak category below 45',
	(bool) $selection_floor->invoke(null, 40, 'kultur', false, [], null),
	'kultur score 40 should remain eligible'
);

$assert(
	'selection floor allows serious category at 45',
	(bool) $selection_floor->invoke(null, 45, 'ukraine', false, [], null),
	'ukraine score 45 should be publishable'
);

$thin_title_only_profile = [
	'primary_body_chars' => 80,
	'primary_total_chars' => 190,
	'real_supporting_count' => 0,
	'title_only_supporting_count' => 2,
	'de_content_chars' => 520,
];
$assert(
	'title-only short primary blocks expanded German copy',
	(bool) $source_risk->invoke(null, $thin_title_only_profile),
	'expected source_expansion_risk for primary_body<120, primary_total<260, de_content>280'
);

$real_support_profile = $thin_title_only_profile;
$real_support_profile['real_supporting_count'] = 1;
$assert(
	'real supporting text clears source expansion risk',
	! (bool) $source_risk->invoke(null, $real_support_profile),
	'real supporting source should allow enrichment'
);

$well_supported_profile = [
	'primary_body_chars' => 650,
	'primary_total_chars' => 760,
	'real_supporting_count' => 0,
	'title_only_supporting_count' => 0,
	'de_content_chars' => 1300,
];
$assert(
	'long primary source clears source expansion risk',
	! (bool) $source_risk->invoke(null, $well_supported_profile),
	'primary_total>=700 should be treated as enough source support'
);

$item = (object) [
	'original_title' => 'Short source headline',
	'original_excerpt' => 'Only a short source excerpt with one factual sentence.',
	'original_content' => '',
];
$payload = [
	'languages' => [
		'de' => [
			'content' => '<p>' . str_repeat('Der Bericht bleibt faktisch und knapp. ', 18) . '</p>',
		],
	],
	'_meta' => [
		'source_dossier' => [
			'primary' => [
				'title' => 'Short source headline',
				'excerpt' => 'Only a short source excerpt with one factual sentence.',
				'content' => '',
			],
			'supporting' => [
				['title' => 'Related headline only', 'url' => 'https://example.test/related'],
			],
		],
	],
];
$profile = $source_profile->invoke(null, $item, $payload);
$assert(
	'source support profile treats title-only supporting as non-real',
	(int) ($profile['real_supporting_count'] ?? -1) === 0 && (int) ($profile['title_only_supporting_count'] ?? -1) === 1,
	wp_json_encode($profile, JSON_UNESCAPED_UNICODE)
);
$assert(
	'source support profile feeds title-only source risk',
	(bool) $source_risk->invoke(null, $profile),
	wp_json_encode($profile, JSON_UNESCAPED_UNICODE)
);

if ($failures !== []) {
	echo "\nFailures:\n";
	foreach ($failures as $failure) {
		echo " - {$failure}\n";
	}
	exit(1);
}

echo "\nPublish gate regression suite OK.\n";
