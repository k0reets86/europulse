#!/usr/bin/env php
<?php

declare(strict_types=1);

$input = null;
for ($i = 1; $i < $argc; $i++) {
	if ($argv[$i] === '--input' && isset($argv[$i + 1])) {
		$input = $argv[$i + 1];
		break;
	}
}

if (! is_string($input) || $input === '' || ! is_file($input)) {
	fwrite(STDERR, "Missing --input request file\n");
	exit(1);
}

	try {
		$request = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
		$request = is_array($request) ? $request : [];
		$site_path = trim((string) ($request['site_path'] ?? '/var/www/europulse/public'));
		$body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		file_put_contents($input, $body);
		try {
			$stdout = run_wp_cli_worker($site_path, $input);
			$data = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
			if (! is_array($data)) {
				throw new RuntimeException('wp-cli worker bridge returned invalid JSON');
			}
			if (! empty($data['error'])) {
				throw new RuntimeException((string) $data['error']);
			}
			$data['_worker_meta'] = [
				'bridge' => 'wp_cli',
			];
		} catch (Throwable $wpCliError) {
			if (! allow_direct_wp_fallback($request)) {
				throw new RuntimeException('wp-cli worker bridge failed and direct wp-load fallback is disabled: ' . $wpCliError->getMessage());
			}
			$data = run_direct_wp_bridge($site_path, $request, $wpCliError->getMessage());
		}
		if (! is_array($data)) {
			throw new RuntimeException('Worker bridge returned invalid JSON');
		}
	if (! empty($data['error'])) {
		throw new RuntimeException((string) $data['error']);
	}

	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	exit(0);
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}

function allow_direct_wp_fallback(array $request): bool {
	if (! empty($request['allow_direct_wp_fallback'])) {
		return true;
	}
	$env = getenv('EPV2_ALLOW_DIRECT_WP_FALLBACK');
	return is_string($env) && in_array(strtolower(trim($env)), ['1', 'true', 'yes', 'on'], true);
}

function run_wp_cli_worker(string $site_path, string $input): string {
	$wp_bin = is_file('/usr/local/bin/wp') ? '/usr/local/bin/wp' : 'wp';
	$timeout = 240;
	$idle_timeout = 210;
	$cmd = [
		$wp_bin,
		'--allow-root',
		'--skip-plugins',
		'--skip-themes',
		'--skip-packages',
		'--path=' . $site_path,
		'--require=/var/www/europulse/worker/wp_cli_worker_bootstrap.php',
		'eval-file',
		'/var/www/europulse/worker/wp_cli_worker_eval.php',
	];
	$descriptorspec = [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];
	putenv('EPV2_WORKER_INPUT=' . $input);
	$process = proc_open($cmd, $descriptorspec, $pipes);
	if (! is_resource($process)) {
		putenv('EPV2_WORKER_INPUT');
		throw new RuntimeException('Failed to start wp-cli worker bridge');
	}
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	$stdout = '';
	$stderr = '';
	$started = time();
	$last_activity = $started;
	$status = proc_get_status($process);
	while (! empty($status['running'])) {
		$out = (string) stream_get_contents($pipes[1]);
		$err = (string) stream_get_contents($pipes[2]);
		if ($out !== '' || $err !== '') {
			$last_activity = time();
		}
		$stdout .= $out;
		$stderr .= $err;
		if ((time() - $started) >= $timeout) {
			proc_terminate($process, 15);
			usleep(500000);
			$status = proc_get_status($process);
			if (! empty($status['running'])) {
				proc_terminate($process, 9);
			}
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($process);
			throw new RuntimeException('wp-cli worker bridge timed out');
		}
		if ((time() - $last_activity) >= $idle_timeout) {
			proc_terminate($process, 15);
			usleep(500000);
			$status = proc_get_status($process);
			if (! empty($status['running'])) {
				proc_terminate($process, 9);
			}
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($process);
			throw new RuntimeException('wp-cli worker bridge stalled without output');
		}
		usleep(100000);
		$status = proc_get_status($process);
	}
	$stdout .= (string) stream_get_contents($pipes[1]);
	$stderr .= (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exit_code = proc_close($process);
	putenv('EPV2_WORKER_INPUT');
	if ($exit_code !== 0) {
		throw new RuntimeException(trim($stderr) !== '' ? trim($stderr) : 'wp-cli worker bridge failed');
	}
	return $stdout;
}

function run_direct_wp_bridge(string $site_path, array $request, string $fallback_reason): array {
	$wp_load = rtrim($site_path, '/') . '/wp-load.php';
	$trace = '/tmp/epv2-worker-direct.log';
	$trace_log = static function (string $message) use ($trace): void {
		@file_put_contents($trace, '[' . gmdate('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
	};
	$trace_log('run_direct_wp_bridge:start stage=' . (string) ($request['stage'] ?? '') . ' queue_id=' . (int) ($request['queue_id'] ?? 0));
	if (! is_file($wp_load)) {
		throw new RuntimeException('wp-cli bridge failed and wp-load fallback is unavailable: ' . $fallback_reason);
	}
	if (! defined('EPV2_WORKER_CONTEXT')) {
		define('EPV2_WORKER_CONTEXT', true);
	}
	if (! defined('DISABLE_WP_CRON')) {
		define('DISABLE_WP_CRON', true);
	}
	$trace_log('run_direct_wp_bridge:before_wp_load');
	require_once $wp_load;
	$trace_log('run_direct_wp_bridge:after_wp_load');
	if (! class_exists('EPV2_Worker_Bridge')) {
		$trace_log('run_direct_wp_bridge:worker_bridge_missing');
		throw new RuntimeException('wp-cli bridge failed and worker bridge is unavailable: ' . $fallback_reason);
	}
	$trace_log('run_direct_wp_bridge:before_execute_request');
	$result = EPV2_Worker_Bridge::execute_request($request);
	$trace_log('run_direct_wp_bridge:after_execute_request');
	if (is_array($result)) {
		$result['_worker_meta'] = [
			'fallback' => 'direct_wp_load',
			'fallback_reason' => $fallback_reason,
		];
	}
	$trace_log('run_direct_wp_bridge:finish');
	return $result;
}
