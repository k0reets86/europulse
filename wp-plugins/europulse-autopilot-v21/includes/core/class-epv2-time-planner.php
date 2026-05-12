<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Time_Planner {
	public static function defaults(): array {
		// Architecture audit section 8 phase-3 schedule (agreed 2026-05-08).
		// Each window's `publish_minutes` lists minute-of-hour slots when
		// publishing is allowed; the gap between slots IS the operator-facing
		// «timer between publications»:
		//   every 5 min → 0/5/10/15/...
		//   every 8 min → 0/8/16/24/32/40/48/56
		//   every 15 min → 0/15/30/45
		// The night window has zero allowed slots: only breaking_alert
		// items override the schedule (handled in
		// EPV2_Time_Planner::should_publish via has_breaking_watch()).
		// Operator-агреемент 2026-05-09 (вечерняя сессия) + 2026-05-12 (утренний slot 1/час):
		//   06–09  collect 1/час (на :00)               publish каждые 5 мин
		//   09–16  collect 1/час (на :00)               publish каждые 5 мин
		//   16–19  collect 2/час (на :00 и :30)         publish каждые 5 мин
		//   19–22  collect 1/час (на :00)               publish каждые 5 мин
		//   22–23  collect 1× (на :00 в 22:00)          publish каждые 5 мин
		//   23–00  collect OFF                          publish каждые 5 мин (очередь дорабатывается)
		//   00–01  collect 1× (на :00 в 00:00)          publish OFF
		//   01–06  collect OFF, publish OFF — только breaking + top stories
		// Breaking всегда обходит лимиты (см. should_collect/should_publish).
		$timer_5  = range(0, 55, 5);
		return [
			'windows' => [
				// Morning catch-up (2026-05-12: уменьшен до 1/час — operator-feedback: 2/час давало backlog в «новых»)
				['start' => '06:00', 'end' => '09:00', 'mode' => 'morning_catchup',  'collect_minutes' => [0],     'publish_minutes' => $timer_5],
				// Daytime active (один сбор в час)
				['start' => '09:00', 'end' => '16:00', 'mode' => 'daytime_active',   'collect_minutes' => [0],     'publish_minutes' => $timer_5],
				// Daytime peak: 2 сбора в час
				['start' => '16:00', 'end' => '19:00', 'mode' => 'daytime_peak',     'collect_minutes' => [0, 30], 'publish_minutes' => $timer_5],
				// Evening prime: один сбор в час
				['start' => '19:00', 'end' => '22:00', 'mode' => 'evening_prime',    'collect_minutes' => [0],     'publish_minutes' => $timer_5],
				// Last collect of the day at 22:00, publish продолжается
				['start' => '22:00', 'end' => '23:00', 'mode' => 'wind_down_final',  'collect_minutes' => [0],     'publish_minutes' => $timer_5],
				// Wind-down hour: collect OFF (но publish продолжается)
				['start' => '23:00', 'end' => '00:00', 'mode' => 'wind_down_quiet',  'collect_minutes' => [],      'publish_minutes' => $timer_5],
				// Полуночный одиночный сбор (00:00). Без publish — ночью только breaking.
				['start' => '00:00', 'end' => '01:00', 'mode' => 'night_open',       'collect_minutes' => [0],     'publish_minutes' => []],
				// Night: collect OFF, publish OFF — только breaking/top
				['start' => '01:00', 'end' => '06:00', 'mode' => 'night_monitor',    'collect_minutes' => [],      'publish_minutes' => []],
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
		// Breaking news ВСЕГДА проходит, любое окно, любой минутный слот.
		if (self::has_breaking_watch()) {
			return true;
		}
		$window = self::current_window();
		$mode = (string) ($window['mode'] ?? '');
		// Окна где collect полностью off (ночь, тихий час перед последним сбором).
		if (in_array($mode, ['night_monitor', 'wind_down_quiet'], true)) {
			return false;
		}
		$collect_minutes = (array) ($window['collect_minutes'] ?? []);
		// Пустой list = collect off для этого окна (раньше = «всегда», что
		// конфликтовало с дизайном — теперь явно «никогда»).
		if ($collect_minutes === []) {
			return false;
		}
		return self::minute_allowed($collect_minutes);
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
		if (class_exists('EPV2_Queue') && EPV2_Queue::has_due_publish_item()) {
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

	public static function publish_budget_allows_item(bool $priority_override = false): bool {
		if ($priority_override) {
			return true;
		}
		if (! self::daily_publish_limit_enforced()) {
			return true;
		}
		return self::published_today_count() < self::allowed_publish_budget_now();
	}

	public static function publish_category_budget_allows_item(string $category, bool $priority_override = false): bool {
		if ($priority_override || ! self::daily_publish_limit_enforced()) {
			return true;
		}
		$target = self::daily_category_target($category);
		if ($target <= 0) {
			return true;
		}
		return self::published_today_category_count($category) < self::allowed_category_publish_budget_now($category);
	}

	public static function next_category_budget_slot_timestamp(string $category): int {
		$target = self::daily_category_target($category);
		if ($target <= 0) {
			return time();
		}

		$count = self::published_today_category_count($category);
		if ($count < self::allowed_category_publish_budget_now($category)) {
			return time();
		}

		$now = time();
		$reset = self::next_daily_budget_reset_timestamp();
		$step = 5 * MINUTE_IN_SECONDS;
		for ($candidate = EPV2_Jobs::next_publish_slot_after($now); $candidate < $reset; $candidate += $step) {
			if ($count < self::allowed_category_publish_budget_at($category, $candidate)) {
				return $candidate;
			}
		}
		return $reset;
	}

	public static function next_publish_budget_slot_timestamp(): int {
		if (! self::daily_publish_limit_enforced()) {
			return time();
		}
		$count = self::published_today_count();
		if ($count < self::allowed_publish_budget_now()) {
			return time();
		}

		$reset = self::next_daily_budget_reset_timestamp();
		$step = 5 * MINUTE_IN_SECONDS;
		for ($candidate = EPV2_Jobs::next_publish_slot_after(time()); $candidate < $reset; $candidate += $step) {
			if ($count < self::allowed_publish_budget_at($candidate)) {
				return $candidate;
			}
		}
		return $reset;
	}

	public static function next_daily_budget_reset_timestamp(): int {
		$tz = new DateTimeZone((string) (EPV2_Settings::get('time_schedule_profile', self::defaults())['timezone'] ?? 'Europe/Berlin'));
		$now = new DateTimeImmutable('now', $tz);
		return $now->setTime(0, 0, 0)->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
	}

	public static function current_mode_label(): string {
		$mode = (string) (self::current_window()['mode'] ?? '');
		return match ($mode) {
			// Architecture audit phase-3 schedule labels
			'morning_catchup' => 'Утро (6:00–9:00, 5 мин)',
			'daytime_active'  => 'Дневной актив (9:00–12:00, 5 мин)',
			'lunch_peak'      => 'Обед (12:00–13:30, 5 мин)',
			'daytime_mid'     => 'День мид (13:30–18:00, 8 мин)',
			'evening_prime'   => 'Вечерний прайм (18:00–22:00, 5 мин)',
			'wind_down'       => 'Wind-down (22:00–00:00, 15 мин)',
			'night_monitor'   => 'Ночь (00:00–06:00, пауза, только breaking)',
			// Legacy labels for older time_schedule_profile installations
			'morning_hourly'  => 'Утренний режим (legacy)',
			'day_half_hour'   => 'Дневной активный режим (legacy)',
			'evening_hourly'  => 'Вечерний режим (legacy)',
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

	private static function published_today_category_count(string $category): int {
		global $wpdb;
		$key = self::category_budget_key($category);
		if ($key === '') {
			return 0;
		}

		$tz = new DateTimeZone((string) (EPV2_Settings::get('time_schedule_profile', self::defaults())['timezone'] ?? 'Europe/Berlin'));
		$now = new DateTimeImmutable('now', $tz);
		$day_start_gmt = $now->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$day_end_gmt = $now->setTime(0, 0, 0)->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT DISTINCT pm.meta_value AS queue_id
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_epv2_queue_id'
			WHERE p.post_status = 'publish'
			  AND p.post_type = 'post'
			  AND p.post_date_gmt >= %s
			  AND p.post_date_gmt < %s",
			$day_start_gmt,
			$day_end_gmt
		));

		$count = 0;
		foreach ((array) $rows as $row) {
			$post_id = self::german_post_id_for_queue((string) ($row->queue_id ?? ''));
			if ($post_id > 0 && self::post_primary_category_budget_key($post_id) === $key) {
				$count++;
			}
		}
		return $count;
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
			// Architecture audit phase-3 schedule (7 windows)
			'morning_catchup' => ['base' => 0.00, 'cap' => 0.15],
			'daytime_active'  => ['base' => 0.15, 'cap' => 0.40],
			'lunch_peak'      => ['base' => 0.40, 'cap' => 0.55],
			'daytime_mid'     => ['base' => 0.55, 'cap' => 0.70],
			'evening_prime'   => ['base' => 0.70, 'cap' => 0.92],
			'wind_down'       => ['base' => 0.92, 'cap' => 0.98],
			'night_monitor'   => ['base' => 0.98, 'cap' => 1.00],
			// Legacy 4-window schedule (kept for back-compat with existing
			// time_schedule_profile option values that haven't been migrated)
			'morning_hourly'  => ['base' => 0.00, 'cap' => 0.15],
			'day_half_hour'   => ['base' => 0.15, 'cap' => 0.70],
			'evening_hourly'  => ['base' => 0.70, 'cap' => 0.95],
		];
		$rule = $shares[$mode] ?? ['base' => 0.00, 'cap' => 1.00];
		$fraction = (float) $rule['base'] + (((float) $rule['cap'] - (float) $rule['base']) * $progress);
		return max(1, (int) ceil($target * $fraction));
	}

	private static function allowed_category_publish_budget_now(string $category): int {
		return self::allowed_category_publish_budget_at($category, time());
	}

	private static function allowed_category_publish_budget_at(string $category, int $timestamp): int {
		$target = self::daily_category_target($category);
		if ($target <= 0) {
			return 999;
		}
		$overall_target = max(1, (int) EPV2_Settings::get('daily_publish_target', 24));
		$overall_allowed = self::allowed_publish_budget_at($timestamp);
		$fraction = min(1, max(0.01, $overall_allowed / $overall_target));
		return max(1, min($target, (int) ceil($target * $fraction)));
	}

	private static function allowed_publish_budget_at(int $timestamp): int {
		$target = max(1, (int) EPV2_Settings::get('daily_publish_target', 24));
		$tz = new DateTimeZone((string) (EPV2_Settings::get('time_schedule_profile', self::defaults())['timezone'] ?? 'Europe/Berlin'));
		$time = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
		$hour = (int) $time->format('H');
		$minute = (int) $time->format('i');
		$minute_of_day = ($hour * 60) + $minute;
		// Mirrors the default publication budget curve: 15% by 11:00, 70% by 18:00, 95% by midnight.
		$points = [
			[0, 0.95],
			[360, 0.00],
			[660, 0.15],
			[1080, 0.70],
			[1440, 0.95],
		];
		for ($i = 1; $i < count($points); $i++) {
			[$start_minute, $start_fraction] = $points[$i - 1];
			[$end_minute, $end_fraction] = $points[$i];
			if ($minute_of_day < $start_minute || $minute_of_day > $end_minute) {
				continue;
			}
			$span = max(1, $end_minute - $start_minute);
			$progress = max(0, min(1, ($minute_of_day - $start_minute) / $span));
			return max(1, (int) ceil($target * ($start_fraction + (($end_fraction - $start_fraction) * $progress))));
		}
		return max(1, (int) ceil($target * 0.95));
	}

	private static function daily_category_target(string $category): int {
		$targets = EPV2_Settings::get('daily_category_publish_targets', []);
		if (! is_array($targets)) {
			return 0;
		}
		$key = self::category_budget_key($category);
		return $key !== '' ? (int) ($targets[$key] ?? 0) : 0;
	}

	private static function category_budget_key(string $category): string {
		$value = sanitize_title($category);
		$map = [
			'politics' => 'politik',
			'polityka' => 'politik',
			'ukraina' => 'ukraine',
			'nimechchyna' => 'deutschland',
			'germany' => 'deutschland',
			'economy' => 'wirtschaft',
			'ekonomika' => 'wirtschaft',
			'sport-2' => 'sport',
			'culture' => 'kultur',
			'kultura' => 'kultur',
			'life-in-germany' => 'leben_in_deutschland',
			'zhyttia-v-nimechchyni' => 'leben_in_deutschland',
			'community-de' => 'community',
			'spilnota' => 'community',
			'europe' => 'europa',
			'yevropa' => 'europa',
			'world' => 'welt',
			'svit' => 'welt',
		];
		return $map[$value] ?? str_replace('-', '_', $value);
	}

	private static function german_post_id_for_queue(string $queue_id): int {
		if ($queue_id === '') {
			return 0;
		}
		$posts = get_posts([
			'post_type' => 'post',
			'post_status' => 'publish',
			'posts_per_page' => 6,
			'meta_key' => '_epv2_queue_id',
			'meta_value' => $queue_id,
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'ASC',
		]);
		foreach ($posts as $post_id) {
			if (function_exists('pll_get_post_language') && pll_get_post_language((int) $post_id) !== 'de') {
				continue;
			}
			return (int) $post_id;
		}
		return (int) ($posts[0] ?? 0);
	}

	private static function post_primary_category_budget_key(int $post_id): string {
		$categories = get_the_category($post_id);
		if ($categories === []) {
			return '';
		}
		return self::category_budget_key((string) ($categories[0]->slug ?: $categories[0]->name));
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
