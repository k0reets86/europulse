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

$assert(
	'publish gate class is loaded',
	class_exists('EPV2_Publish_Gate'),
	'EPV2_Publish_Gate missing'
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
