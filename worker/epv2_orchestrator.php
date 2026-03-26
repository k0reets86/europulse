#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "CLI only.\n");
	exit(1);
}

@ini_set('memory_limit', '512M');
@set_time_limit(240);

$wpLoad = '/var/www/europulse/public/wp-load.php';
if (! is_file($wpLoad)) {
	fwrite(STDERR, "Missing wp-load.php\n");
	exit(1);
}

require $wpLoad;

$options = getopt('', ['once', 'interval::', 'quiet']);
$once = isset($options['once']);
$interval = max(5, (int) ($options['interval'] ?? 15));
$quiet = isset($options['quiet']);
$logFile = '/var/www/europulse/worker/epv2_orchestrator.log';

function epv2_orchestrator_log(string $message, string $logFile, bool $quiet): void {
	$line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
	file_put_contents($logFile, $line, FILE_APPEND);
	if (! $quiet) {
		echo $line;
	}
}

function epv2_orchestrator_snapshot(): array {
	global $wpdb;
	$table = $wpdb->prefix . 'epv2_queue';
	$rows = $wpdb->get_results("SELECT state, COUNT(*) c FROM {$table} GROUP BY state", ARRAY_A);
	$summary = [];
	foreach ((array) $rows as $row) {
		$summary[(string) $row['state']] = (int) $row['c'];
	}
	return $summary;
}

function epv2_orchestrator_last_run_ids(): array {
	global $wpdb;
	$table = $wpdb->prefix . 'epv2_runs';
	$rows = $wpdb->get_results("SELECT job_name, MAX(id) AS id FROM {$table} WHERE job_name IN ('collect','process','publish') GROUP BY job_name", ARRAY_A);
	$ids = [
		'collect' => 0,
		'process' => 0,
		'publish' => 0,
	];
	foreach ((array) $rows as $row) {
		$job = (string) ($row['job_name'] ?? '');
		if ($job !== '' && array_key_exists($job, $ids)) {
			$ids[$job] = (int) ($row['id'] ?? 0);
		}
	}
	return $ids;
}

function epv2_orchestrator_stage_items_waiting(): int {
	global $wpdb;
	$table = $wpdb->prefix . 'epv2_queue';
	$count = $wpdb->get_var(
		"SELECT COUNT(*) FROM {$table}
		WHERE state IN ('retry_process','ready_review')
		  AND ai_payload IS NOT NULL
		  AND ai_payload <> ''
		  AND JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.pipeline_stage')) <> ''"
	);
	return (int) $count;
}

function epv2_orchestrator_tick(string $logFile, bool $quiet): void {
	$tickStartedAt = microtime(true);
	$cleanupStartedAt = microtime(true);
	EPV2_Resilience_Manager::cleanup();
	$cleanupDurationMs = (int) round((microtime(true) - $cleanupStartedAt) * 1000);

	$collectDurationMs = 0;
	$processDurationMs = 0;
	$publishDurationMs = 0;
	$iterations = 0;
	$maxIterations = 6;
	$timeBudgetSeconds = 210;
	$collectStartedAt = microtime(true);
	EPV2_Jobs::run_collect_windowed();
	$collectDurationMs = (int) round((microtime(true) - $collectStartedAt) * 1000);

	$beforeRunIds = epv2_orchestrator_last_run_ids();
	do {
		$iterations++;
		$processStartedAt = microtime(true);
		EPV2_Jobs::run_process_windowed();
		$processDurationMs += (int) round((microtime(true) - $processStartedAt) * 1000);

		$publishStartedAt = microtime(true);
		EPV2_Jobs::run_publish_windowed();
		$publishDurationMs += (int) round((microtime(true) - $publishStartedAt) * 1000);

		$afterRunIds = epv2_orchestrator_last_run_ids();
		$progressed = $afterRunIds !== $beforeRunIds;
		$beforeRunIds = $afterRunIds;
		$stagedWaiting = epv2_orchestrator_stage_items_waiting();
		$withinBudget = (microtime(true) - $tickStartedAt) < $timeBudgetSeconds;
	} while ($progressed && $stagedWaiting > 0 && $iterations < $maxIterations && $withinBudget);

	$summary = epv2_orchestrator_snapshot();
	epv2_orchestrator_log(
		'tick_ms=' . (int) round((microtime(true) - $tickStartedAt) * 1000)
		. ' cleanup_ms=' . $cleanupDurationMs
		. ' collect_ms=' . $collectDurationMs
		. ' process_ms=' . $processDurationMs
		. ' publish_ms=' . $publishDurationMs
		. ' iterations=' . $iterations
		. ' staged_waiting=' . $stagedWaiting
		. ' queue=' . wp_json_encode($summary, JSON_UNESCAPED_UNICODE),
		$logFile,
		$quiet
	);
}

epv2_orchestrator_log(
	'start interval=' . $interval . ' once=' . ($once ? '1' : '0'),
	$logFile,
	$quiet
);

do {
	epv2_orchestrator_tick($logFile, $quiet);
	if ($once) {
		break;
	}
	sleep($interval);
} while (true);
