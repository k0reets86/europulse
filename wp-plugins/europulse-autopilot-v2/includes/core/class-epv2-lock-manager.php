<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Lock_Manager {
	private const STALE_HEARTBEAT_SECONDS = 300;

	private static function option_key(string $name): string {
		return 'epv2_lock_' . sanitize_key($name);
	}

	private static function is_stale(array $current, int $now): bool {
		if (empty($current['token'])) {
			return false;
		}
		$heartbeat = (int) ($current['heartbeat_at'] ?? 0);
		return $heartbeat > 0 && $heartbeat <= ($now - self::STALE_HEARTBEAT_SECONDS);
	}

	public static function current(string $name): array {
		$current = get_option(self::option_key($name), []);
		return is_array($current) ? $current : [];
	}

	public static function is_active(string $name): bool {
		$current = self::current($name);
		$now = time();
		if (! is_array($current) || empty($current['token'])) {
			return false;
		}
		if (self::is_stale($current, $now)) {
			delete_option(self::option_key($name));
			return false;
		}
		if ((int) ($current['expires_at'] ?? 0) <= $now) {
			delete_option(self::option_key($name));
			return false;
		}
		return true;
	}

	public static function acquire(string $name, int $ttl = 600, array $meta = []): ?string {
		$ttl = max(30, min(3600, $ttl));
		$key = self::option_key($name);
		$now = time();
		$current = self::current($name);
		if (is_array($current) && self::is_stale($current, $now)) {
			delete_option($key);
			$current = [];
		}
		if (is_array($current) && ! empty($current['token']) && (int) ($current['expires_at'] ?? 0) > $now) {
			return null;
		}

		$token = wp_generate_password(20, false, false);
		$data = [
			'token' => $token,
			'acquired_at' => $now,
			'heartbeat_at' => $now,
			'expires_at' => $now + $ttl,
			'meta' => $meta,
		];
		if (add_option($key, $data, '', false)) {
			return $token;
		}

		$current = self::current($name);
		if ((! is_array($current) || empty($current['token'])) && update_option($key, $data, false)) {
			$confirmed = self::current($name);
			if (is_array($confirmed) && (string) ($confirmed['token'] ?? '') === $token) {
				return $token;
			}
		}
		if (is_array($current) && self::is_stale($current, $now)) {
			delete_option($key);
			if (add_option($key, $data, '', false)) {
				return $token;
			}
		}

		global $wpdb;
		$serialized = maybe_serialize($data);
		$wpdb->query($wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			VALUES (%s, %s, 'no')
			ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
			$key,
			$serialized
		));
		wp_cache_delete($key, 'options');
		wp_cache_delete('alloptions', 'options');
		$confirmed = self::current($name);
		if (is_array($confirmed) && (string) ($confirmed['token'] ?? '') === $token) {
			return $token;
		}

		return null;
	}

	public static function heartbeat(string $name, string $token, int $ttl = 600): bool {
		$ttl = max(30, min(3600, $ttl));
		$key = self::option_key($name);
		$current = self::current($name);
		if (! is_array($current) || (string) ($current['token'] ?? '') !== $token) {
			return false;
		}
		$now = time();
		$current['heartbeat_at'] = $now;
		$current['expires_at'] = $now + $ttl;
		update_option($key, $current, false);
		return true;
	}

	public static function release(string $name, string $token): void {
		$key = self::option_key($name);
		$current = self::current($name);
		if (is_array($current) && (string) ($current['token'] ?? '') === $token) {
			delete_option($key);
		}
	}

	public static function cleanup(array $names = ['collect', 'process', 'publish']): void {
		$now = time();
		foreach ($names as $name) {
			$key = self::option_key((string) $name);
			$current = get_option($key, []);
			if (is_array($current) && ! empty($current['token']) && ((int) ($current['expires_at'] ?? 0) <= $now || self::is_stale($current, $now))) {
				delete_option($key);
			}
		}
	}
}
