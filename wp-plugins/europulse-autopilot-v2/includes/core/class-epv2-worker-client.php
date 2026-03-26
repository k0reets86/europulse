<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Worker_Client {
	private const DEFAULT_PHP_WORKER = '/var/www/europulse/worker/php_worker.php';

	public static function enabled(): bool {
		return (string) EPV2_Settings::get('worker_mode', 'disabled') !== 'disabled';
	}

	public static function mode(): string {
		return (string) EPV2_Settings::get('worker_mode', 'disabled');
	}

	public static function build_request(object $item, string $stage = 'rebuild_bundle', array $existing_payload = []): array {
		return [
			'queue_id' => (int) ($item->id ?? 0),
			'stage' => $stage,
			'site_url' => rtrim((string) site_url(), '/'),
			'site_path' => ABSPATH,
			'worker_endpoint' => esc_url_raw(rest_url('epv2/v1/worker/execute')),
			'worker_secret' => EPV2_Settings::worker_shared_secret(),
			'story_kind' => (string) ($item->story_format ?? 'news'),
			'length_profile' => 'standard',
			'original_title' => (string) ($item->original_title ?? ''),
			'original_excerpt' => (string) ($item->original_excerpt ?? ''),
			'original_content' => (string) ($item->original_content ?? ''),
			'original_url' => (string) ($item->original_url ?? ''),
			'original_date' => (string) ($item->original_date ?? ''),
			'source_image_url' => (string) ($item->source_image_url ?? ''),
			'source_language' => '',
			'category_proposed' => (string) ($item->category_proposed ?? ''),
			'category_final' => (string) ($item->category_final ?? ''),
			'story_format' => (string) ($item->story_format ?? 'news'),
			'topic_label' => (string) ($item->topic_label ?? ''),
			'cluster_id' => (int) ($item->cluster_id ?? 0),
			'editorial_flags' => [],
			'existing_payload' => $existing_payload,
		];
	}

	public static function run_for_item(object $item, string $stage = 'rebuild_bundle', array $existing_payload = [], ?string $mode_override = null): array {
		if ($mode_override === null && ! self::enabled()) {
			throw new RuntimeException('External worker is disabled');
		}
		$request = self::build_request($item, $stage, $existing_payload);
		return self::run_request($request, $mode_override);
	}

	public static function run_request(array $request, ?string $mode_override = null): array {
		$mode = $mode_override !== null ? $mode_override : self::mode();
		return match ($mode) {
			'cli' => self::run_cli_request($request),
			default => throw new RuntimeException('Unsupported worker mode'),
		};
	}

	private static function run_cli_request(array $request): array {
			$php_bin = self::php_binary();
			$worker_script = self::worker_script_path();
			$timeout = max(10, min(600, (int) EPV2_Settings::get('worker_timeout_seconds', 180)));
			$idle_timeout = max(90, min($timeout - 10, 300));
		$tmp = wp_tempnam('epv2-worker-request');
		if (! $tmp) {
			throw new RuntimeException('Unable to allocate temp file for worker request');
		}

		file_put_contents($tmp, wp_json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		$cmd = [
			$php_bin,
			$worker_script,
			'--input',
			$tmp,
		];

		$descriptorspec = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = proc_open($cmd, $descriptorspec, $pipes);
		if (! is_resource($process)) {
			@unlink($tmp);
			throw new RuntimeException('Failed to start external worker process');
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
				proc_terminate($process);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				@unlink($tmp);
				throw new RuntimeException('External worker timed out');
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
				@unlink($tmp);
				throw new RuntimeException('External worker stalled without output');
			}
			usleep(100000);
			$status = proc_get_status($process);
		}

		$stdout .= (string) stream_get_contents($pipes[1]);
		$stderr .= (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit_code = proc_close($process);
		@unlink($tmp);

		if ($exit_code !== 0) {
			throw new RuntimeException('External worker failed: ' . trim($stderr));
		}

		$data = json_decode(trim($stdout), true);
		if (! is_array($data)) {
			throw new RuntimeException('External worker returned invalid JSON');
		}

		return $data;
	}

	private static function php_binary(): string {
		$configured = trim((string) EPV2_Settings::get('worker_python_bin', ''));
		if ($configured !== '' && is_executable($configured)) {
			return $configured;
		}
		if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '' && is_executable(PHP_BINARY)) {
			return PHP_BINARY;
		}
		return 'php';
	}

	private static function worker_script_path(): string {
		$configured = trim((string) EPV2_Settings::get('worker_cli_command', self::DEFAULT_PHP_WORKER));
		if ($configured !== '' && self::is_allowed_worker_script($configured)) {
			return $configured;
		}
		if (self::is_allowed_worker_script(self::DEFAULT_PHP_WORKER)) {
			return self::DEFAULT_PHP_WORKER;
		}
		throw new RuntimeException('Built-in external worker script is unavailable');
	}

	private static function is_allowed_worker_script(string $path): bool {
		if ($path === '') {
			return false;
		}
		$real = realpath($path);
		if (! is_string($real) || $real === '') {
			return false;
		}
		if (! is_file($real) || ! is_readable($real)) {
			return false;
		}
		$allowed_roots = [
			realpath('/var/www/europulse/worker'),
			realpath('/root/projects/europulse/worker'),
		];
		foreach ($allowed_roots as $root) {
			if (is_string($root) && $root !== '' && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
				return true;
			}
		}
		return false;
	}
}
