<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Worker_Client {
	public static function enabled(): bool {
		return (string) EPV2_Settings::get('worker_mode', 'disabled') !== 'disabled';
	}

	public static function mode(): string {
		return (string) EPV2_Settings::get('worker_mode', 'disabled');
	}

	public static function build_request(object $item, string $stage = 'full_bundle', array $existing_payload = []): array {
		return [
			'queue_id' => (int) ($item->id ?? 0),
			'stage' => $stage,
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

	public static function run_for_item(object $item, string $stage = 'full_bundle', array $existing_payload = [], ?string $mode_override = null): array {
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
		$python = trim((string) EPV2_Settings::get('worker_python_bin', 'python3'));
		$command = trim((string) EPV2_Settings::get('worker_cli_command', 'python3 -m epv2_worker'));
		$src_dir = trim((string) EPV2_Settings::get('worker_src_dir', '/root/projects/europulse/worker/src'));
		$timeout = max(10, min(600, (int) EPV2_Settings::get('worker_timeout_seconds', 180)));
		$tmp = wp_tempnam('epv2-worker-request');
		if (! $tmp) {
			throw new RuntimeException('Unable to allocate temp file for worker request');
		}

		file_put_contents($tmp, wp_json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		$env = [
			'PYTHONPATH=' . $src_dir,
		];
		$cmd = implode(' ', [
			'env',
			escapeshellarg($env[0]),
			$command !== '' ? $command : escapeshellcmd($python) . ' -m epv2_worker',
			'--input',
			escapeshellarg($tmp),
		]);

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
		$status = proc_get_status($process);
		while (! empty($status['running'])) {
			$stdout .= (string) stream_get_contents($pipes[1]);
			$stderr .= (string) stream_get_contents($pipes[2]);
			if ((time() - $started) >= $timeout) {
				proc_terminate($process);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				@unlink($tmp);
				throw new RuntimeException('External worker timed out');
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
}
