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

		// R19 2026-05-14: semi mode deprecation warning. Operator policy
		// 2026-05-14 — full auto operation. Semi mode kept для emergency
		// fallback only. Full removal planned после 60-90 дней наблюдения.
		add_action('admin_notices', static function (): void {
			if (! current_user_can('manage_europulse_autopilot')) {
				return;
			}
			$mode = (string) EPV2_Settings::get('mode', 'semi');
			if ($mode === 'auto') {
				return;
			}
			echo '<div class="notice notice-warning"><p><strong>EuroPulse mode = "' . esc_html($mode) . '"</strong>. ';
			echo esc_html('Operator policy 2026-05-14: full auto mode рекомендован. Manual review queue routes полностью disabled в auto mode (items не накапливаются). Переключите mode в Settings для full automation.');
			echo '</p></div>';
		});

		// R17 2026-05-14: Polylang health check. Plugin полагается на pll_*
		// functions для language assignment + translation linking (publisher,
		// weekly-analysis, watchdog). 30+ call sites guard через function_exists,
		// но logic assumes works. Disable Polylang → posts создаются без language,
		// home pool foundation выдаёт fallback на DE, реальные UK/EN orphans.
		// Catch this early via Notifier + auto-pause.
		add_action('init', static function (): void {
			if ( ! function_exists( 'pll_set_post_language' ) ) {
				// Polylang missing OR not yet loaded — schedule on plugins_loaded.
				// But при init Polylang должен быть available если активен.
				$paused = (bool) get_option( 'epv2_automation_paused', false );
				if ( ! $paused ) {
					update_option( 'epv2_automation_paused', 1, false );
					if ( class_exists( 'EPV2_Notifier' ) ) {
						EPV2_Notifier::notify(
							'alert',
							'autopilot',
							"🔴 Polylang unavailable — automation paused\nPolylang plugin не loaded, posts будут создаваться без language assignment, UK/EN orphan'ятся. Automation auto-paused для safety. Восстанови Polylang и снова Resume через Dashboard."
						);
					}
					if ( class_exists( 'EPV2_Logger' ) ) {
						EPV2_Logger::error( 'autopilot', 'Polylang missing — automation paused (R17 health check)' );
					}
				}
			}
		}, 20);
		if ( class_exists( 'EPV2_Schema_Enricher' ) ) {
			EPV2_Schema_Enricher::register();
		}

		if (is_admin()) {
			EPV2_Admin::register();
		}

		// B5 (2026-05-12): WP-CLI bulk maintenance commands для manual_review backlog.
		// Operator получает CLI tooling для разгрузки 100+ items без UI клик-фест:
		//   wp epv2 mr reject --age=24h --max-importance=50
		//   wp epv2 mr stats
		//   wp epv2 mr promote --editorial-match=match
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			if ( class_exists( 'EPV2_CLI_Commands' ) ) {
				\WP_CLI::add_command( 'epv2', 'EPV2_CLI_Commands' );
			}
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
