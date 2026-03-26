<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Time_Planner {
	public static function defaults(): array {
		$publish_minutes = range(0, 55, 5);
		return [
			'windows' => [
				['start' => '06:00', 'end' => '09:00', 'mode' => 'morning_hourly', 'collect_minutes' => [0], 'publish_minutes' => $publish_minutes],
				['start' => '09:00', 'end' => '18:00', 'mode' => 'day_half_hour', 'collect_minutes' => [0, 30], 'publish_minutes' => $publish_minutes],
				['start' => '18:00', 'end' => '00:00', 'mode' => 'evening_hourly', 'collect_minutes' => [0], 'publish_minutes' => $publish_minutes],
				['start' => '00:00', 'end' => '06:00', 'mode' => 'night_monitor', 'collect_minutes' => [0], 'publish_minutes' => $publish_minutes],
			],
			'breaking_watch_minutes' => [0, 30],
			'timezone' => 'Europe/Berlin',
		];
	}

	public static function now(): DateTimeImmutable {
		$tz = new DateTimeZone((string) (EPV2_Settings::get('time_schedule_profile', self::defaults())['timezone'] ?? 'Europe/Berlin'));
		return new DateTimeImmutable('now', $tz);
	}

	public static function current_window(): array {
		$profile = EPV2_Settings::get('time_schedule_profile', self::defaults());
		$now = self::now();
		$hm = $now->format('H:i');
		foreach ((array) ($profile['windows'] ?? []) as $window) {
			$start = (string) ($window['start'] ?? '');
			$end = (string) ($window['end'] ?? '');
			if ($start === '' || $end === '') {
				continue;
			}
			if (self::time_in_range($hm, $start, $end)) {
				return $window;
			}
		}
		return self::defaults()['windows'][0];
	}

	public static function should_collect(bool $force = false): bool {
		if ($force) {
			return true;
		}
		$window = self::current_window();
		if (($window['mode'] ?? '') === 'night_monitor') {
			return self::minute_allowed((array) ($window['collect_minutes'] ?? [])) && self::has_breaking_watch();
		}
		return self::minute_allowed((array) ($window['collect_minutes'] ?? []));
	}

	public static function should_process(bool $force = false): bool {
		if ($force) {
			return true;
		}
		if (self::queue_has_states(['new', 'retry_process', 'processing_de'])) {
			return true;
		}
		$window = self::current_window();
		$minute_allowed = self::minute_allowed((array) ($window['collect_minutes'] ?? []));
		if (! $minute_allowed) {
			return false;
		}
		if (($window['mode'] ?? '') === 'night_monitor') {
			return self::has_breaking_watch();
		}
		return true;
	}

	public static function should_publish(bool $force = false): bool {
		if ($force) {
			return true;
		}
		$window = self::current_window();
		$minute_allowed = self::minute_allowed((array) ($window['publish_minutes'] ?? []));
		if (! $minute_allowed) {
			return false;
		}
		if (($window['mode'] ?? '') === 'night_monitor') {
			if (! self::queue_has_states(['ready_publish', 'retry_publish', 'publishing']) && ! self::has_breaking_watch()) {
				return false;
			}
		}
		if (! self::daily_publish_limit_enforced()) {
			return true;
		}
		return self::published_today_count() < self::allowed_publish_budget_now();
	}

	public static function current_mode_label(): string {
		$mode = (string) (self::current_window()['mode'] ?? '');
		return match ($mode) {
			'morning_hourly' => 'Утренний режим',
			'day_half_hour' => 'Дневной активный режим',
			'evening_hourly' => 'Вечерний режим',
			'night_monitor' => 'Ночной мониторинг',
			default => $mode,
		};
	}

	public static function has_breaking_watch(): bool {
		global $wpdb;
		$cutoff = gmdate('Y-m-d H:i:s', time() - (6 * HOUR_IN_SECONDS));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ai_payload, admin_notes
				FROM {$wpdb->prefix}epv2_queue
				WHERE created_at >= %s
				AND state IN ('new','processing_de','retry_process','ready_publish','reserve')",
				$cutoff
			),
			ARRAY_A
		);
		foreach ((array) $rows as $row) {
			$payload = json_decode((string) ($row['ai_payload'] ?? ''), true);
			if (! empty($payload['_meta']['breaking']) || ! empty($payload['_meta']['top_story']) || ! empty($payload['_meta']['breaking_watch'])) {
				return true;
			}
			$notes = json_decode((string) ($row['admin_notes'] ?? ''), true);
			if (! empty($notes['selection']['breaking_candidate']) || ! empty($notes['selection']['top_story_candidate']) || ! empty($notes['selection']['breaking_watch'])) {
				return true;
			}
		}
		return false;
	}

	private static function time_in_range(string $time, string $start, string $end): bool {
		if ($start === $end) {
			return true;
		}
		if ($start < $end) {
			return $time >= $start && $time < $end;
		}
		return $time >= $start || $time < $end;
	}

	private static function minute_allowed(array $allowedMinutes): bool {
		$allowedMinutes = array_values(array_unique(array_map('intval', $allowedMinutes)));
		if ($allowedMinutes === []) {
			return true;
		}
		$minute = (int) self::now()->format('i');
		return in_array($minute, $allowedMinutes, true);
	}

	private static function published_today_count(): int {
		global $wpdb;
		$tz = new DateTimeZone((string) (EPV2_Settings::get('time_schedule_profile', self::defaults())['timezone'] ?? 'Europe/Berlin'));
		$now = new DateTimeImmutable('now', $tz);
		$day_start_local = $now->setTime(0, 0, 0);
		$day_end_local = $day_start_local->modify('+1 day');
		$day_start_gmt = $day_start_local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$day_end_gmt = $day_end_local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT pm.meta_value)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_epv2_queue_id'
			WHERE p.post_status = 'publish'
			  AND p.post_type = 'post'
			  AND p.post_date_gmt >= %s
			  AND p.post_date_gmt < %s",
			$day_start_gmt,
			$day_end_gmt
		));
	}

	private static function allowed_publish_budget_now(): int {
		$target = max(1, (int) EPV2_Settings::get('daily_publish_target', 24));
		$window = self::current_window();
		$mode = (string) ($window['mode'] ?? '');
		$now = self::now();
		$current_minutes = ((int) $now->format('H') * 60) + (int) $now->format('i');
		$window_start = self::time_to_minutes((string) ($window['start'] ?? '00:00'));
		$window_end = self::time_to_minutes((string) ($window['end'] ?? '00:00'));
		if ($window_end <= $window_start) {
			$window_end += 24 * 60;
			if ($current_minutes < $window_start) {
				$current_minutes += 24 * 60;
			}
		}
		$elapsed = max(0, min($window_end - $window_start, $current_minutes - $window_start));
		$span = max(1, $window_end - $window_start);
		$progress = min(1, max(0, $elapsed / $span));

		$shares = [
			'morning_hourly' => ['base' => 0.00, 'cap' => 0.15],
			'day_half_hour' => ['base' => 0.15, 'cap' => 0.70],
			'evening_hourly' => ['base' => 0.70, 'cap' => 0.95],
			'night_monitor' => ['base' => 0.95, 'cap' => 1.00],
		];
		$rule = $shares[$mode] ?? ['base' => 0.00, 'cap' => 1.00];
		$fraction = (float) $rule['base'] + (((float) $rule['cap'] - (float) $rule['base']) * $progress);
		return max(1, (int) ceil($target * $fraction));
	}

	private static function daily_publish_limit_enforced(): bool {
		return ! empty(EPV2_Settings::get('enforce_daily_publish_target', true));
	}

	private static function queue_has_states(array $states): bool {
		global $wpdb;
		$states = array_values(array_filter(array_map('sanitize_text_field', $states)));
		if ($states === []) {
			return false;
		}
		$placeholders = implode(',', array_fill(0, count($states), '%s'));
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}epv2_queue WHERE state IN ({$placeholders})",
				...$states
			)
		);
		return $count > 0;
	}

	private static function time_to_minutes(string $hhmm): int {
		[$h, $m] = array_pad(array_map('intval', explode(':', $hhmm)), 2, 0);
		return ($h * 60) + $m;
	}

}
