<?php

if (! defined('ABSPATH')) {
	echo "ERROR: publish_schedule_test.php must run via `wp eval-file`.\n";
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

$tz = new DateTimeZone('Europe/Berlin');
$utc = new DateTimeZone('UTC');
$future_day = (new DateTimeImmutable('now', $tz))->modify('+2 days');

$inside_prime = $future_day->setTime(21, 50, 0);
$inside_prime_ts = $inside_prime->setTimezone($utc)->getTimestamp();
$inside_prime_slot = EPV2_Jobs::next_publish_slot_after($inside_prime_ts);
$inside_prime_expected = $inside_prime->setTime(21, 52, 0)->setTimezone($utc)->getTimestamp();
$assert(
	'evening prime keeps the next aligned publish slot',
	$inside_prime_slot === $inside_prime_expected,
	'got ' . gmdate('Y-m-d H:i:s', $inside_prime_slot) . ', expected ' . gmdate('Y-m-d H:i:s', $inside_prime_expected)
);

$near_cutoff = $future_day->setTime(21, 55, 54);
$near_cutoff_ts = $near_cutoff->setTimezone($utc)->getTimestamp();
$near_cutoff_slot = EPV2_Jobs::next_publish_slot_after($near_cutoff_ts);
$near_cutoff_expected = $near_cutoff->setTime(21, 57, 0)->setTimezone($utc)->getTimestamp();
$assert(
	'late evening before cutoff still catches the final regular slot',
	$near_cutoff_slot === $near_cutoff_expected,
	'got ' . gmdate('Y-m-d H:i:s', $near_cutoff_slot) . ', expected ' . gmdate('Y-m-d H:i:s', $near_cutoff_expected)
);

$after_cutoff = $future_day->setTime(22, 8, 43);
$after_cutoff_ts = $after_cutoff->setTimezone($utc)->getTimestamp();
$after_cutoff_slot = EPV2_Jobs::next_publish_slot_after($after_cutoff_ts);
$after_cutoff_expected = $after_cutoff->setTime(22, 12, 0)->setTimezone($utc)->getTimestamp();
$assert(
	'ready item after cutoff keeps the next aligned drain slot',
	$after_cutoff_slot === $after_cutoff_expected,
	'got ' . gmdate('Y-m-d H:i:s', $after_cutoff_slot) . ', expected ' . gmdate('Y-m-d H:i:s', $after_cutoff_expected)
);

$wind_down = $future_day->setTime(22, 30, 0)->setTimezone($utc)->getTimestamp();
$assert(
	'wind-down final is not a regular publish window',
	! EPV2_Time_Planner::timestamp_allows_regular_publish($wind_down, 2 * MINUTE_IN_SECONDS),
	'22:30 Berlin should be breaking-only'
);

if ($failures !== []) {
	echo "\nFailures:\n";
	foreach ($failures as $failure) {
		echo " - {$failure}\n";
	}
	exit(1);
}

echo "\nPublish schedule regression suite OK.\n";
