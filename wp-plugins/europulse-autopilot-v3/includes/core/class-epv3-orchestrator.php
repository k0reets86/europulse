<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Orchestrator {
	public const HOOK = 'epv3_orchestrator_tick';

	public static function register(): void {
		add_action(self::HOOK, [self::class, 'tick']);
	}

	public static function schedule(): void {
		if (! wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time() + 60, 'epv3_5_minutes', self::HOOK);
		}
	}

	public static function tick(): void {
		if ((string) EPV3_Settings::get('mode', 'disabled') === 'disabled') {
			return;
		}

		$process_limit = max(1, min(10, (int) EPV3_Settings::get('max_parallel_items', 1)));
		$publish_limit = max(1, min(10, (int) EPV3_Settings::get('max_publish_per_tick', 3)));

		for ($i = 0; $i < $process_limit; $i++) {
			if (! EPV3_Runner::run_process_cycle()) {
				break;
			}
		}

		for ($i = 0; $i < $publish_limit; $i++) {
			if (! EPV3_Runner::run_publish_cycle()) {
				break;
			}
		}
	}
}
