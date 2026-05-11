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
		EPV2_Capabilities::register();
		EPV2_Jobs::register();
		EPV2_Jobs::maybe_schedule();
		EPV2_Publisher::register();
		EPV2_Logger::register();
		EPV2_News_Sitemap::register();
		EPV2_News_Sitemap::maybe_render_early();
		if ( class_exists( 'EPV2_Schema_Enricher' ) ) {
			EPV2_Schema_Enricher::register();
		}

		if (is_admin()) {
			EPV2_Admin::register();
		}

		add_action('rest_api_init', ['EPV2_REST', 'register_routes']);

		// Security hardening: блокируем неаутентифицированный /wp/v2/users.
		// По дефолту WP отдаёт slug+name всех authors публично — login-name
		// для brute-force утекает (мы видели europulse_admin в API).
		add_filter('rest_endpoints', static function (array $endpoints): array {
			if (! empty($endpoints['/wp/v2/users']) || ! empty($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
				$blocker = static function ($endpoint) {
					if (empty($endpoint['methods']) || strpos((string) $endpoint['methods'], 'GET') === false) {
						return $endpoint;
					}
					$endpoint['permission_callback'] = static function () {
						if (is_user_logged_in() && current_user_can('list_users')) {
							return true;
						}
						return new WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', ['status' => 401]);
					};
					return $endpoint;
				};
				if (! empty($endpoints['/wp/v2/users'])) {
					foreach ($endpoints['/wp/v2/users'] as &$endpoint) {
						$endpoint = $blocker($endpoint);
					}
					unset($endpoint);
				}
				if (! empty($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
					foreach ($endpoints['/wp/v2/users/(?P<id>[\d]+)'] as &$endpoint) {
						$endpoint = $blocker($endpoint);
					}
					unset($endpoint);
				}
			}
			return $endpoints;
		});
	}
}
