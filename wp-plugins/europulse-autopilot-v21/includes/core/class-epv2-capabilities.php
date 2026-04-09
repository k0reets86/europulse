<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Capabilities {
	private const OPTION_CAPS_VERSION = 'epv2_caps_version';

	public static function register(): void {
		self::maybe_grant_caps_once();
	}

	public static function maybe_grant_caps(): void {
		$role = get_role('administrator');
		if (! $role) {
			return;
		}

		foreach (self::all() as $cap) {
			if (! $role->has_cap($cap)) {
				$role->add_cap($cap);
			}
		}
	}

	public static function maybe_grant_caps_once(): void {
		$current = (string) get_option(self::OPTION_CAPS_VERSION, '');
		if ($current === EPV2_VERSION) {
			return;
		}
		self::maybe_grant_caps();
		update_option(self::OPTION_CAPS_VERSION, EPV2_VERSION, false);
	}

	public static function all(): array {
		return [
			'read',
			'edit_posts',
			'edit_others_posts',
			'edit_published_posts',
			'publish_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_published_posts',
			'delete_private_posts',
			'edit_private_posts',
			'read_private_posts',
			'manage_europulse_autopilot',
			'edit_europulse_autopilot_sources',
			'run_europulse_autopilot',
			'publish_europulse_autopilot',
			'view_europulse_autopilot_logs',
		];
	}
}
