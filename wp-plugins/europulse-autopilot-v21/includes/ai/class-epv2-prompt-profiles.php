<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Prompt_Profiles {
	public static function get(string $profile): string {
		$prompts = EPV2_Settings::get('prompts', []);
		return (string) ($prompts[$profile] ?? '');
	}

	public static function all(): array {
		return EPV2_Settings::get('prompts', []);
	}
}
