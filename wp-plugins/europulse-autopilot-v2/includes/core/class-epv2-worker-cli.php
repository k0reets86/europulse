<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Worker_CLI {
	public static function register(): void {
		if (! defined('WP_CLI') || ! WP_CLI) {
			return;
		}
		WP_CLI::add_command('epv2 worker-execute', [self::class, 'execute']);
	}

	/**
	 * Execute worker request from a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * --input=<path>
	 * : Path to request JSON file.
	 */
	public static function execute(array $args, array $assoc_args): void {
		$input = (string) ($assoc_args['input'] ?? '');
		if ($input === '' || ! is_file($input)) {
			WP_CLI::error('Missing --input request file');
		}
		try {
			$request = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
			$request = is_array($request) ? $request : [];
			$result = EPV2_Worker_Bridge::execute_request($request);
			$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			if (! is_string($json) || $json === '') {
				throw new RuntimeException('Worker CLI produced empty JSON output');
			}
			WP_CLI::line($json);
		} catch (Throwable $e) {
			WP_CLI::error($e->getMessage(), false);
		}
	}
}
