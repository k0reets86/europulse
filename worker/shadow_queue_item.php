#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "CLI only.\n");
	exit(1);
}

$options = getopt('', [
	'queue-id:',
	'stage::',
	'site-path::',
	'worker-python::',
	'request-out::',
	'response-out::',
]);

$queueId = (int) ($options['queue-id'] ?? 0);
$stage = (string) ($options['stage'] ?? 'rebuild_bundle');
$sitePath = rtrim((string) ($options['site-path'] ?? '/var/www/europulse/public'), '/');
$workerPython = (string) ($options['worker-python'] ?? 'python3');
$requestOut = (string) ($options['request-out'] ?? '');
$responseOut = (string) ($options['response-out'] ?? '');

if ($queueId <= 0) {
	fwrite(STDERR, "--queue-id is required.\n");
	exit(1);
}

$wpLoad = $sitePath . '/wp-load.php';
if (! is_file($wpLoad)) {
	fwrite(STDERR, "Missing wp-load.php at {$wpLoad}\n");
	exit(1);
}

require $wpLoad;

global $wpdb;
$table = $wpdb->prefix . 'epv2_queue';
$row = $wpdb->get_row(
	$wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $queueId),
	ARRAY_A
);

if (! is_array($row) || $row === []) {
	fwrite(STDERR, "Queue item {$queueId} not found.\n");
	exit(1);
}

$payload = json_decode((string) ($row['ai_payload'] ?? ''), true);
$payload = is_array($payload) ? $payload : [];

$request = [
	'queue_id' => $queueId,
	'stage' => $stage,
	'story_kind' => (string) ($payload['_meta']['story_kind'] ?? $row['story_format'] ?? 'news'),
	'length_profile' => (string) ($payload['_meta']['length_profile'] ?? 'standard'),
	'original_title' => (string) ($row['original_title'] ?? ''),
	'original_excerpt' => (string) ($row['original_excerpt'] ?? ''),
	'original_content' => (string) ($row['original_content'] ?? ''),
	'original_url' => (string) ($row['original_url'] ?? ''),
	'original_date' => (string) ($row['original_date'] ?? ''),
	'source_image_url' => (string) ($row['source_image_url'] ?? ''),
	'source_language' => (string) ($payload['_meta']['source_language'] ?? ''),
	'category_proposed' => (string) ($row['category_proposed'] ?? ''),
	'category_final' => (string) ($row['category_final'] ?? ''),
	'story_format' => (string) ($row['story_format'] ?? ''),
	'topic_label' => (string) ($row['topic_label'] ?? ''),
	'cluster_id' => (int) ($row['cluster_id'] ?? 0),
	'editorial_flags' => [
		'shadow_mode' => true,
		'live_state' => (string) ($row['state'] ?? ''),
	],
	'existing_payload' => $payload,
];

$requestJson = wp_json_encode($request, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (! is_string($requestJson) || $requestJson === '') {
	fwrite(STDERR, "Failed to encode worker request.\n");
	exit(1);
}

$requestFile = $requestOut !== '' ? $requestOut : tempnam(sys_get_temp_dir(), 'epv2-shadow-request-');
if (! $requestFile) {
	fwrite(STDERR, "Failed to create temp request file.\n");
	exit(1);
}
file_put_contents($requestFile, $requestJson);

$cmd = sprintf(
	'PYTHONPATH=%s %s -m epv2_worker --input %s',
	escapeshellarg('/root/projects/europulse/worker/src'),
	escapeshellcmd($workerPython),
	escapeshellarg($requestFile)
);

$descriptors = [
	0 => ['pipe', 'r'],
	1 => ['pipe', 'w'],
	2 => ['pipe', 'w'],
];

$proc = proc_open($cmd, $descriptors, $pipes, '/root/projects/europulse/worker');
if (! is_resource($proc)) {
	fwrite(STDERR, "Failed to start worker command.\n");
	exit(1);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

if ($responseOut !== '' && is_string($stdout)) {
	file_put_contents($responseOut, $stdout);
}

$summary = [
	'queue_id' => $queueId,
	'stage' => $stage,
	'request_file' => $requestFile,
	'response_file' => $responseOut !== '' ? $responseOut : null,
	'exit_code' => $exitCode,
];

if ($exitCode !== 0) {
	$summary['stderr'] = trim((string) $stderr);
	echo wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
	exit($exitCode);
}

$decoded = json_decode((string) $stdout, true);
if (is_array($decoded)) {
	$summary['outcome'] = (string) ($decoded['outcome'] ?? '');
	$summary['categories'] = (array) ($decoded['categories'] ?? []);
	$summary['quality_score'] = (int) ($decoded['quality']['score'] ?? 0);
	$summary['seo_score'] = (int) ($decoded['seo_quality']['score'] ?? 0);
	$summary['release_score'] = (int) ($decoded['release_quality']['score'] ?? 0);
	$summary['google_score'] = (int) ($decoded['google_quality']['score'] ?? 0);
	$summary['warnings'] = (array) ($decoded['warnings'] ?? []);
	$summary['blockers'] = (array) ($decoded['blockers'] ?? []);
}

echo wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(0);
