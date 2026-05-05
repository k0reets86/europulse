<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Lock_Manager {
	private const STALE_HEARTBEAT_SECONDS = 300;

	private static function option_key(string $name): string {
		return 'epv2_lock_' . sanitize_key($name);
	}

	private static function flush_option_cache(string $key): void {
		wp_cache_delete($key, 'options');
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('notoptions', 'options');
	}

	private static function options_table(): string {
		global $wpdb;
		return $wpdb->options;
	}

	private static function decode_value($value): array {
		$decoded = maybe_unserialize($value);
		return is_array($decoded) ? $decoded : [];
	}

	private static function read_row(string $key): ?array {
		global $wpdb;
		self::flush_option_cache($key);
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_id, option_value FROM " . self::options_table() . " WHERE option_name = %s LIMIT 1",
				$key
			),
			ARRAY_A
		);
		if (! is_array($row)) {
			return null;
		}
		$row['option_id'] = (int) ($row['option_id'] ?? 0);
		$row['data'] = self::decode_value($row['option_value'] ?? '');
		return $row;
	}

	private static function write_row(string $key, array $data): bool {
		global $wpdb;
		$serialized = maybe_serialize($data);
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO " . self::options_table() . " (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$key,
				$serialized
			)
		);
		self::flush_option_cache($key);
		return $result === 1;
	}

	private static function replace_row_if_matches(string $key, string $previous_serialized, array $data): bool {
		global $wpdb;
		$serialized = maybe_serialize($data);
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::options_table() . " SET option_value = %s, autoload = 'off' WHERE option_name = %s AND option_value = %s",
				$serialized,
				$key,
				$previous_serialized
			)
		);
		self::flush_option_cache($key);
		return $result === 1;
	}

	private static function delete_row(string $key): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . self::options_table() . " WHERE option_name = %s",
				$key
			)
		);
		self::flush_option_cache($key);
	}

	private static function is_stale(array $current, int $now): bool {
		if (empty($current['token'])) {
			return false;
		}
		$meta = is_array($current['meta'] ?? null) ? $current['meta'] : [];
		$stale_after = (int) ($meta['stale_after'] ?? self::STALE_HEARTBEAT_SECONDS);
		$stale_after = max(60, min(3600, $stale_after));
		$heartbeat = (int) ($current['heartbeat_at'] ?? 0);
		return $heartbeat > 0 && $heartbeat <= ($now - $stale_after);
	}

	public static function current(string $name): array {
		$key = self::option_key($name);
		$row = self::read_row($key);
		return is_array($row['data'] ?? null) ? $row['data'] : [];
	}

	public static function status_snapshot(string $name): array {
		$current = self::current($name);
		$now = time();
		$meta = is_array($current['meta'] ?? null) ? $current['meta'] : [];
		$stale_after = max(60, min(3600, (int) ($meta['stale_after'] ?? self::STALE_HEARTBEAT_SECONDS)));
		$heartbeat_at = (int) ($current['heartbeat_at'] ?? 0);
		$expires_at = (int) ($current['expires_at'] ?? 0);
		$token_present = (string) ($current['token'] ?? '') !== '';
		$stale = $token_present && self::is_stale($current, $now);
		$expired = $token_present && $expires_at > 0 && $expires_at <= $now;

		return [
			'name' => sanitize_key($name),
			'active' => $token_present && ! $stale && ! $expired,
			'token_present' => $token_present,
			'stale' => $stale,
			'expired' => $expired,
			'heartbeat_at' => $heartbeat_at,
			'expires_at' => $expires_at,
			'stale_after' => $stale_after,
			'meta' => $meta,
		];
	}

	public static function is_active(string $name): bool {
		$current = self::current($name);
		$now = time();
		if (! is_array($current) || empty($current['token'])) {
			return false;
		}
		if (self::is_stale($current, $now)) {
			self::delete_row(self::option_key($name));
			return false;
		}
		if ((int) ($current['expires_at'] ?? 0) <= $now) {
			self::delete_row(self::option_key($name));
			return false;
		}
		return true;
	}

	public static function acquire(string $name, int $ttl = 600, array $meta = []): ?string {
		$ttl = max(30, min(3600, $ttl));
		$key = self::option_key($name);
		$now = time();
		$current_row = self::read_row($key);
		$current = is_array($current_row['data'] ?? null) ? $current_row['data'] : [];
		if (is_array($current) && self::is_stale($current, $now)) {
			self::delete_row($key);
			$current_row = null;
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

		if ($current_row === null && self::write_row($key, $data)) {
			return $token;
		}

		$current_row = self::read_row($key);
		$current = is_array($current_row['data'] ?? null) ? $current_row['data'] : [];
		if (! is_array($current) || empty($current['token']) || (int) ($current['expires_at'] ?? 0) <= $now || self::is_stale($current, $now)) {
			$previous_serialized = (string) ($current_row['option_value'] ?? '');
			if ($previous_serialized !== '' && self::replace_row_if_matches($key, $previous_serialized, $data)) {
				$claimed = self::current($name);
				if (is_array($claimed) && (string) ($claimed['token'] ?? '') === $token) {
					return $token;
				}
			}
		}

		return null;
	}

	public static function heartbeat(string $name, string $token, int $ttl = 600): bool {
		$ttl = max(30, min(3600, $ttl));
		$key = self::option_key($name);
		$current_row = self::read_row($key);
		$current = is_array($current_row['data'] ?? null) ? $current_row['data'] : [];
		if (! is_array($current) || (string) ($current['token'] ?? '') !== $token || empty($current_row['option_value'])) {
			return false;
		}
		$now = time();
		$current['heartbeat_at'] = $now;
		$current['expires_at'] = $now + $ttl;
		return self::replace_row_if_matches($key, (string) $current_row['option_value'], $current);
	}

	public static function release(string $name, string $token): void {
		$key = self::option_key($name);
		$current = self::current($name);
		if (is_array($current) && (string) ($current['token'] ?? '') === $token) {
			self::delete_row($key);
		}
	}

	public static function cleanup(array $names = ['collect', 'process', 'publish']): void {
		$now = time();
		foreach ($names as $name) {
			$key = self::option_key((string) $name);
			$current = self::current((string) $name);
			if (is_array($current) && ! empty($current['token']) && ((int) ($current['expires_at'] ?? 0) <= $now || self::is_stale($current, $now))) {
				self::delete_row($key);
			}
		}
	}
}
