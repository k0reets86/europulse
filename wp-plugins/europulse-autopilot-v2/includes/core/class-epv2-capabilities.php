<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Capabilities {
	public static function register(): void {
		add_action('admin_init', [self::class, 'maybe_grant_caps']);
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
