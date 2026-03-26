<?php

declare(strict_types=1);

if (! defined('DISABLE_WP_CRON')) {
	define('DISABLE_WP_CRON', true);
}
if (! defined('EPV2_WORKER_CONTEXT')) {
	define('EPV2_WORKER_CONTEXT', true);
}

if (defined('WP_CLI') && WP_CLI) {
	WP_CLI::add_hook('after_wp_load', static function (): void {
		require_once '/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/europulse-autopilot.php';
		add_filter('automatic_updater_disabled', '__return_true', 100);
		add_filter('pre_http_request', static function ($pre, array $parsed_args, string $url) {
			if (preg_match('#(api|downloads|translate)\\.wordpress\\.org|s\\.w\\.org|google\\.com/complete|googleapis\\.com/.*updates#i', $url) === 1) {
				return new WP_Error('epv2_worker_bootstrap_blocked', 'Blocked WordPress/bootstrap external request in worker mode');
			}
			return $pre;
		}, 1, 3);
	});
}
