<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Logger {
	public static function log(string $level, string $module, string $message, array $context = []): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'epv2_log',
			[
				'level' => $level,
				'module' => $module,
				'message' => $message,
				'context' => ! empty($context) ? wp_json_encode($context, JSON_UNESCAPED_UNICODE) : null,
			]
		);
	}

	public static function info(string $module, string $message, array $context = []): void {
		self::log('info', $module, $message, $context);
	}

	public static function warning(string $module, string $message, array $context = []): void {
		self::log('warning', $module, $message, $context);
	}

	public static function error(string $module, string $message, array $context = []): void {
		self::log('error', $module, $message, $context);
	}
}
