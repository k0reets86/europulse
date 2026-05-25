<?php

if (! defined('ABSPATH')) {
	echo "ERROR: quality_gate_test.php must run via `wp eval-file`.\n";
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

$assert(
	'quality gate class is loaded',
	class_exists('EPV2_Quality_Gate'),
	'EPV2_Quality_Gate missing'
);

$raw = '<p>Росія рекомендувала іноземним державам евакуювати своє дипломатичне персонал і громадян із Києва.</p>';
$repair = EPV2_Quality_Gate::repair_rendered_text($raw, 'uk');
$fixed = (string) ($repair['text'] ?? '');
$repairs = (array) ($repair['repairs'] ?? []);

$assert(
	'quality gate repairs observed Ukrainian personal agreement',
	str_contains($fixed, 'свій дипломатичний персонал') && ! str_contains($fixed, 'своє дипломатичне персонал'),
	$fixed
);
$assert(
	'quality gate marks Ukrainian grammar repair',
	! empty($repairs['uk_grammar']),
	wp_json_encode($repairs, JSON_UNESCAPED_UNICODE)
);

$canonical_source = '<p>Як повідомляє Українська правда, матеріал оновлено.</p>';
$rendered = EPV2_Quality_Gate::evaluate_rendered_text('uk', '', '', $canonical_source, ['context' => 'quality_gate_test']);
$assert(
	'canonical Ukrainian source casing is not flagged after repair',
	! in_array('uk_bad_brand_transliteration', (array) ($rendered['blockers'] ?? []), true),
	wp_json_encode($rendered, JSON_UNESCAPED_UNICODE)
);

if ($failures !== []) {
	echo "\nFailures:\n";
	foreach ($failures as $failure) {
		echo " - {$failure}\n";
	}
	exit(1);
}

echo "\nQuality gate regression suite OK.\n";
