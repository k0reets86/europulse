<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Knowledge_Pack {
	public static function prompts(): array {
		$file = '/root/projects/europulse/config/epv3-prompts/defaults.json';
		return self::read_json_file($file);
	}

	public static function rules(): array {
		$file = '/root/projects/europulse/config/epv3-rules/defaults.json';
		return self::read_json_file($file);
	}

	public static function docs(): array {
		$files = [
			'mandatory' => '/root/projects/europulse/docs/epv3-mandatory-knowledge-pack.md',
			'editorial' => '/root/projects/europulse/docs/epv3-editorial-spec.md',
			'pipeline' => '/root/projects/europulse/docs/epv3-pipeline-spec.md',
			'state_machine' => '/root/projects/europulse/docs/epv3-state-machine.md',
		];

		$result = [];
		foreach ($files as $key => $path) {
			$result[$key] = is_readable($path) ? (string) file_get_contents($path) : '';
		}
		return $result;
	}

	private static function read_json_file(string $file): array {
		if (! is_readable($file)) {
			return [];
		}
		$data = json_decode((string) file_get_contents($file), true);
		return is_array($data) ? $data : [];
	}
}
