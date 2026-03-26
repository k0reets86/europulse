<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Plugin {
	public static function load_textdomain(): void {
		load_plugin_textdomain('europulse-autopilot-v2', false, dirname(EPV2_PLUGIN_BASENAME) . '/languages');
	}

	public static function boot(): void {
		EPV2_Upgrader::maybe_run();
		if (defined('EPV2_WORKER_CONTEXT') && EPV2_WORKER_CONTEXT) {
			return;
		}
		if (defined('WP_CLI') && WP_CLI) {
			EPV2_Worker_CLI::register();
			return;
		}

		EPV2_Capabilities::register();
		EPV2_Jobs::register();
		EPV2_Publisher::register();

		if (is_admin()) {
			EPV2_Admin::register();
		}

		add_action('rest_api_init', ['EPV2_REST', 'register_routes']);
		EPV2_Worker_CLI::register();
	}
}
