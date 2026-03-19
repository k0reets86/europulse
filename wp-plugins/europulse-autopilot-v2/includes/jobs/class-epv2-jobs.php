<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Jobs {
	private const HOOK_COLLECT = 'epv2_collect';
	private const HOOK_PROCESS = 'epv2_process';
	private const HOOK_PUBLISH = 'epv2_publish';
	private const HOOK_COLLECT_ASYNC = 'epv2_collect_async';
	private const HOOK_PROCESS_ASYNC = 'epv2_process_async';
	private const HOOK_PUBLISH_ASYNC = 'epv2_publish_async';

	public static function automation_paused(): bool {
		return ! empty(get_option('epv2_automation_paused', false));
	}

	public static function server_orchestrator_enabled(): bool {
		return ! empty(get_option('epv2_server_orchestrator_enabled', false));
	}

	public static function pause_automation(): void {
		update_option('epv2_automation_paused', true, false);
		self::clear_scheduled();
	}

	public static function resume_automation(): void {
		update_option('epv2_automation_paused', false, false);
		self::schedule_recurring();
	}

	public static function register(): void {
		add_filter('cron_schedules', [self::class, 'cron_schedules']);
		add_action(self::HOOK_COLLECT, [self::class, 'run_collect_windowed']);
		add_action(self::HOOK_PROCESS, [self::class, 'run_process_windowed']);
		add_action(self::HOOK_PUBLISH, [self::class, 'run_publish_windowed']);
		add_action(self::HOOK_COLLECT_ASYNC, [self::class, 'run_collect_async']);
		add_action(self::HOOK_PROCESS_ASYNC, [self::class, 'run_process_async']);
		add_action(self::HOOK_PUBLISH_ASYNC, [self::class, 'run_publish_async']);
		add_action('init', ['EPV2_Resilience_Manager', 'cleanup']);
		add_action('init', [self::class, 'maybe_schedule']);
		add_action('init', [self::class, 'maybe_kick_pipeline'], 20);
	}

	public static function schedule_recurring(): void {
		if (self::server_orchestrator_enabled()) {
			self::clear_scheduled();
			return;
		}
		self::schedule_hook(self::HOOK_COLLECT, EPV2_Settings::get('collect_interval_minutes', 30));
		self::schedule_hook(self::HOOK_PROCESS, EPV2_Settings::get('process_interval_minutes', 5));
		self::schedule_hook(self::HOOK_PUBLISH, EPV2_Settings::get('publish_interval_minutes', 5));
	}

	public static function clear_scheduled(): void {
		foreach ([self::HOOK_COLLECT, self::HOOK_PROCESS, self::HOOK_PUBLISH, self::HOOK_COLLECT_ASYNC, self::HOOK_PROCESS_ASYNC, self::HOOK_PUBLISH_ASYNC] as $hook) {
			self::clear_hook($hook);
		}
	}

	public static function maybe_schedule(): void {
		if (self::automation_paused()) {
			self::clear_scheduled();
			return;
		}
		if (self::server_orchestrator_enabled()) {
			self::clear_scheduled();
			return;
		}
		self::schedule_recurring();
	}

	public static function maybe_kick_pipeline(): void {
		if (self::automation_paused() || self::server_orchestrator_enabled()) {
			return;
		}
		self::recover_orphan_collect_lock();
		$collect_active = EPV2_Lock_Manager::is_active('collect');
		self::recover_orphan_process_lock();
		self::recover_orphan_publish_lock();
		EPV2_Queue::promote_publish_ready_payloads(['retry_process']);
		EPV2_Queue::normalize_ready_publish_schedule();
		$next_collect = self::next_collect_timestamp();
		$next_process = wp_next_scheduled(self::HOOK_PROCESS);
		$next_publish = self::next_publish_timestamp();
		if (function_exists('spawn_cron')) {
			if (! $collect_active && $next_collect && $next_collect <= time()) {
				@spawn_cron(time());
			}
			if ($next_process && $next_process <= time()) {
				@spawn_cron(time());
			}
		}
		if (! EPV2_Lock_Manager::is_active('publish')) {
			$due_publish_item = EPV2_Queue::next_item_for_publish();
			if ($due_publish_item) {
				self::enqueue_publish();
				return;
			}
		}
		if ($next_publish && $next_publish <= time() && function_exists('spawn_cron')) {
			@spawn_cron(time());
		}
		if (EPV2_Lock_Manager::is_active('process')) {
			return;
		}
		if (self::has_async_work_scheduled(self::HOOK_PROCESS_ASYNC)) {
			return;
		}
		if (EPV2_Queue::has_processable_items() && ! self::has_async_work_scheduled(self::HOOK_PROCESS_ASYNC)) {
			self::enqueue_process();
		}
	}

	public static function cron_schedules(array $schedules): array {
		foreach ([
			EPV2_Settings::get('collect_interval_minutes', 30),
			EPV2_Settings::get('process_interval_minutes', 5),
			EPV2_Settings::get('publish_interval_minutes', 5),
		] as $minutes) {
			$minutes = max(5, (int) $minutes);
			$key = 'epv2_' . $minutes . '_minutes';
			$schedules[$key] = [
				'interval' => $minutes * MINUTE_IN_SECONDS,
				'display' => sprintf('Every %d minutes (EPV2)', $minutes),
			];
		}

		return $schedules;
	}

	public static function dispatch_async(string $hook, array $args = []): void {
		if (self::automation_paused() || self::server_orchestrator_enabled()) {
			return;
		}
		$async_hook = self::async_hook_for($hook);
		if (self::async_dispatch_guard_active($async_hook)) {
			return;
		}
		$is_async = str_ends_with($async_hook, '_async');
		if ($is_async) {
			if (self::has_async_work_scheduled($async_hook)) {
				return;
			}
		} elseif (function_exists('as_has_scheduled_action') && as_has_scheduled_action($async_hook, $args, 'epv2')) {
			return;
		}
		if (! $is_async && function_exists('as_next_scheduled_action')) {
			$next = as_next_scheduled_action($async_hook, $args, 'epv2');
			if (is_numeric($next) && (int) $next > 0) {
				return;
			}
		}
		if ($is_async) {
			if (! wp_next_scheduled($async_hook, $args)) {
				wp_schedule_single_event(time() + 1, $async_hook, $args);
				self::touch_async_dispatch_guard($async_hook);
			}
			if (function_exists('spawn_cron')) {
				@spawn_cron(time());
			}
			return;
		}

		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action($async_hook, $args, 'epv2');
			self::touch_async_dispatch_guard($async_hook);
			return;
		}

		if (! wp_next_scheduled($async_hook, $args)) {
			wp_schedule_single_event(time() + 5, $async_hook, $args);
			self::touch_async_dispatch_guard($async_hook);
		}
	}

	public static function enqueue_collect(): void {
		self::dispatch_async(self::HOOK_COLLECT);
	}

	public static function enqueue_process(): void {
		self::dispatch_async(self::HOOK_PROCESS);
	}

	public static function enqueue_publish(): void {
		self::dispatch_async(self::HOOK_PUBLISH);
	}

	public static function next_collect_timestamp(): ?int {
		return self::next_scheduled_timestamp(self::HOOK_COLLECT);
	}

	public static function next_process_timestamp(): ?int {
		return self::next_scheduled_timestamp(self::HOOK_PROCESS);
	}

	public static function next_publish_timestamp(): ?int {
		return self::next_scheduled_timestamp(self::HOOK_PUBLISH);
	}

	public static function next_publish_slot_after(int $afterTimestamp): int {
		$minutes = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5));
		$base = max($afterTimestamp, time());
		$slot = self::next_aligned_timestamp_from($base, $minutes, self::hook_offset_seconds(self::HOOK_PUBLISH));
		if ($slot < $afterTimestamp) {
			$slot = self::next_aligned_timestamp_from($afterTimestamp + MINUTE_IN_SECONDS, $minutes, self::hook_offset_seconds(self::HOOK_PUBLISH));
		}
		return $slot;
	}

	public static function current_publish_slot_base(): int {
		$minutes = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5));
		$offset = self::hook_offset_seconds(self::HOOK_PUBLISH);
		$now = time();
		$candidate = self::next_aligned_timestamp_from($now - ($minutes * MINUTE_IN_SECONDS), $minutes, $offset);
		if ($candidate > $now) {
			$candidate -= $minutes * MINUTE_IN_SECONDS;
		}
		return max(0, $candidate);
	}

	public static function run_collect_windowed(): void {
		if (EPV2_Lock_Manager::is_active('collect')) {
			return;
		}
		if (EPV2_Runs::has_recent_started('collect', 300)) {
			return;
		}
		EPV2_Collector::run_scheduled(false);
	}

	public static function run_process_windowed(): void {
		if (EPV2_Lock_Manager::is_active('process')) {
			return;
		}
		if (EPV2_Runs::has_recent_started('process', 300)) {
			return;
		}
		if (! EPV2_Queue::has_processable_items()) {
			return;
		}
		EPV2_AI_Processor::process_scheduled(false, false);
	}

	public static function run_publish_windowed(): void {
		if (EPV2_Lock_Manager::is_active('publish')) {
			return;
		}
		if (EPV2_Runs::has_recent_started('publish', 180)) {
			return;
		}
		EPV2_Publisher::publish_scheduled(false);
	}

	public static function run_collect_async(): void {
		if (EPV2_Lock_Manager::is_active('collect')) {
			return;
		}
		if (EPV2_Runs::has_recent_started('collect', 300)) {
			return;
		}
		EPV2_Collector::run_scheduled(true);
	}

	public static function run_process_async(): void {
		if (EPV2_Lock_Manager::is_active('process')) {
			return;
		}
		if (EPV2_Runs::has_recent_started('process', 300)) {
			return;
		}
		EPV2_AI_Processor::process_scheduled(true, false);
	}

	public static function run_publish_async(): void {
		if (EPV2_Lock_Manager::is_active('publish')) {
			return;
		}
		if (EPV2_Runs::has_recent_started('publish', 180)) {
			return;
		}
		EPV2_Publisher::publish_scheduled(true);
	}

	private static function schedule_hook(string $hook, int $minutes): void {
		$minutes = max(5, $minutes);
		$first_run = self::next_aligned_timestamp($minutes, self::hook_offset_seconds($hook));
		$key = 'epv2_' . $minutes . '_minutes';
		$next = wp_next_scheduled($hook);
		if ($next && ! self::timestamp_is_aligned((int) $next, $minutes, $hook)) {
			wp_clear_scheduled_hook($hook);
			$next = false;
		}
		if (! $next) {
			wp_schedule_event($first_run, $key, $hook);
		}
	}

	private static function next_scheduled_timestamp(string $hook): ?int {
		if ($hook === self::HOOK_PROCESS) {
			return null;
		}
		$next = wp_next_scheduled($hook);
		if (is_numeric($next) && (int) $next > 0) {
			return (int) $next;
		}
		if (function_exists('as_next_scheduled_action')) {
			$next = as_next_scheduled_action($hook, [], 'epv2');
			if (is_numeric($next) && (int) $next > 0) {
				return (int) $next;
			}
		}

		$minutes = match ($hook) {
			self::HOOK_COLLECT => (int) EPV2_Settings::get('collect_interval_minutes', 30),
			self::HOOK_PROCESS => (int) EPV2_Settings::get('process_interval_minutes', 5),
			self::HOOK_PUBLISH => (int) EPV2_Settings::get('publish_interval_minutes', 5),
			default => 30,
		};
		return self::next_aligned_timestamp($minutes, self::hook_offset_seconds($hook));
	}

	private static function next_aligned_timestamp(int $minutes, int $offset_seconds = 0): int {
		return self::next_aligned_timestamp_from(current_time('timestamp', true), $minutes, $offset_seconds);
	}

	private static function next_aligned_timestamp_from(int $fromTimestamp, int $minutes, int $offset_seconds = 0): int {
		$minutes = max(5, $minutes);
		$offset_minutes = (int) floor($offset_seconds / MINUTE_IN_SECONDS);
		$now = $fromTimestamp;
		$hour_start = (int) gmdate('U', strtotime(gmdate('Y-m-d H:00:00', $now)));
		$window_minutes = [];
		for ($minute = 0; $minute < 60; $minute += $minutes) {
			$candidate_minute = ($minute + $offset_minutes) % 60;
			$window_minutes[] = $candidate_minute;
		}
		sort($window_minutes);
		foreach ($window_minutes as $minute) {
			$candidate = $hour_start + ($minute * MINUTE_IN_SECONDS);
			if ($candidate > $now) {
				return $candidate;
			}
		}

		return $hour_start + HOUR_IN_SECONDS + ($window_minutes[0] * MINUTE_IN_SECONDS);
	}

	private static function hook_offset_seconds(string $hook): int {
		return match ($hook) {
			self::HOOK_COLLECT => 0,
			self::HOOK_PROCESS => 1 * MINUTE_IN_SECONDS,
			self::HOOK_PUBLISH => 2 * MINUTE_IN_SECONDS,
			default => 0,
		};
	}

	private static function async_hook_for(string $hook): string {
		return match ($hook) {
			self::HOOK_COLLECT => self::HOOK_COLLECT_ASYNC,
			self::HOOK_PROCESS => self::HOOK_PROCESS_ASYNC,
			self::HOOK_PUBLISH => self::HOOK_PUBLISH_ASYNC,
			default => $hook,
		};
	}

	private static function timestamp_is_aligned(int $timestamp, int $minutes, string $hook): bool {
		if ($timestamp <= 0) {
			return false;
		}
		$offset_minutes = (int) floor(self::hook_offset_seconds($hook) / MINUTE_IN_SECONDS);
		$minute = (int) gmdate('i', $timestamp);
		$second = (int) gmdate('s', $timestamp);
		if ($second !== 0) {
			return false;
		}
		$normalized = ($minute - $offset_minutes + 60) % 60;
		return $normalized % max(1, $minutes) === 0;
	}

	private static function has_pending_action(string $hook, string $group): bool {
		return self::pending_action_count($hook, $group) > 0;
	}

	private static function clear_hook(string $hook): void {
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions($hook, [], 'epv2');
		}
		wp_clear_scheduled_hook($hook);
	}

	private static function has_async_work_scheduled(string $hook): bool {
		if (wp_next_scheduled($hook)) {
			return true;
		}
		return self::pending_action_count($hook, 'epv2') > 0;
	}

	private static function async_dispatch_guard_active(string $hook): bool {
		$last = (int) get_option(self::async_dispatch_guard_key($hook), 0);
		if ($last <= 0) {
			return false;
		}
		return ($last + self::async_dispatch_guard_ttl($hook)) > time();
	}

	private static function touch_async_dispatch_guard(string $hook): void {
		update_option(self::async_dispatch_guard_key($hook), time(), false);
	}

	private static function async_dispatch_guard_key(string $hook): string {
		return 'epv2_async_guard_' . sanitize_key($hook);
	}

	private static function async_dispatch_guard_ttl(string $hook): int {
		return match ($hook) {
			self::HOOK_COLLECT_ASYNC => 45,
			self::HOOK_PROCESS_ASYNC => 30,
			self::HOOK_PUBLISH_ASYNC => 20,
			default => 20,
		};
	}

	private static function recover_orphan_process_lock(): void {
		$lock = get_option('epv2_lock_process', false);
		$processing_items = EPV2_Queue::get_items(['state' => 'processing_de', 'limit' => 3]);
		$has_processing_item = $processing_items !== [];
		$job_lock_ttl = max(300, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
		$stale_window = max(300, min(600, (int) floor($job_lock_ttl / 2)));
		$stale_after = time() - $stale_window;
		$stuck_without_item_after = time() - 60;
		if (is_array($lock) && ! empty($lock['token']) && ! $has_processing_item) {
			$has_worker = self::has_async_work_scheduled(self::HOOK_PROCESS_ASYNC);
			$heartbeat = (int) ($lock['heartbeat_at'] ?? 0);
			if ($heartbeat > 0 && $heartbeat <= $stuck_without_item_after && ! $has_worker) {
				delete_option('epv2_lock_process');
				$latest = EPV2_Runs::latest('process');
				if ($latest && (string) ($latest->status ?? '') === 'started') {
					$started = strtotime((string) ($latest->started_at ?? '')) ?: 0;
					if ($started > 0 && $started <= $stuck_without_item_after) {
						EPV2_Runs::finish((int) $latest->id, 'finished_with_errors', 0, 1, [
							'result' => 'stuck_without_processing_item_recovered',
						]);
					}
				}
				if (EPV2_Queue::has_processable_items()) {
					self::enqueue_process();
				}
				return;
			}
		}
		if ((! is_array($lock) || empty($lock['token'])) && $has_processing_item) {
			$requeued = false;
			foreach ($processing_items as $item) {
				$updated = strtotime((string) ($item->updated_at ?? '')) ?: 0;
				if ($updated > 0 && $updated > $stale_after) {
					continue;
				}
				$error = trim((string) ($item->error_message ?? ''));
				if ($error === '') {
					$error = 'Обработка прервалась: lock исчез до завершения, материал возвращён в очередь.';
				}
				EPV2_Queue::mark_state((int) $item->id, 'new', [
					'error_message' => $error,
				]);
				$requeued = true;
			}
			if ($requeued) {
				$latest = EPV2_Runs::latest('process');
				if ($latest && (string) ($latest->status ?? '') === 'started') {
					$started = strtotime((string) ($latest->started_at ?? '')) ?: 0;
					if ($started > 0 && $started <= $stale_after) {
						EPV2_Runs::finish((int) $latest->id, 'finished_with_errors', 0, 1, [
							'result' => 'missing_lock_recovered',
						]);
					}
				}
			}
			if ($requeued && EPV2_Queue::has_processable_items()) {
				self::enqueue_process();
			}
			return;
		}
		if (! is_array($lock) || empty($lock['token'])) {
			return;
		}
		if ($has_processing_item) {
			$has_worker = self::has_async_work_scheduled(self::HOOK_PROCESS_ASYNC);
			$heartbeat = (int) ($lock['heartbeat_at'] ?? 0);
			if ($heartbeat > 0 && $heartbeat > $stale_after) {
				return;
			}
			if ($has_worker) {
				return;
			}
			$requeued = false;
			foreach ($processing_items as $item) {
				$error = trim((string) ($item->error_message ?? ''));
				$updated = strtotime((string) ($item->updated_at ?? '')) ?: 0;
				if ($updated > 0 && $updated > $stale_after) {
					continue;
				}
				if ($error === '') {
					$error = 'Обработка прервалась: worker исчез до завершения, материал возвращён в очередь.';
				}
				if ($updated <= 0 || $updated <= $stale_after) {
					EPV2_Queue::mark_state((int) $item->id, 'new', [
						'error_message' => $error,
					]);
					$requeued = true;
				}
			}
			delete_option('epv2_lock_process');
			if ($requeued && EPV2_Queue::has_processable_items()) {
				self::enqueue_process();
			}
			return;
		}
		$heartbeat = (int) ($lock['heartbeat_at'] ?? 0);
		$has_worker = self::has_async_work_scheduled(self::HOOK_PROCESS_ASYNC);
		if ($has_worker || ($heartbeat > 0 && $heartbeat > $stale_after)) {
			return;
		}
		delete_option('epv2_lock_process');
		if (EPV2_Queue::has_processable_items()) {
			self::enqueue_process();
		}
	}

	private static function recover_orphan_collect_lock(): void {
		$lock = get_option('epv2_lock_collect', false);
		if (! is_array($lock) || empty($lock['token'])) {
			return;
		}
		$has_worker = self::has_async_work_scheduled(self::HOOK_COLLECT_ASYNC);
		$heartbeat = (int) ($lock['heartbeat_at'] ?? 0);
		$stale_after = time() - 180;
		if ($heartbeat > 0 && $heartbeat > $stale_after) {
			return;
		}
		if ($has_worker) {
			return;
		}
		delete_option('epv2_lock_collect');
		$latest = EPV2_Runs::latest('collect');
		if ($latest && (string) ($latest->status ?? '') === 'started') {
			$started = strtotime((string) ($latest->started_at ?? '')) ?: 0;
			if ($started > 0 && $started <= $stale_after) {
				EPV2_Runs::finish((int) $latest->id, 'finished_with_errors', 0, 1, [
					'result' => 'stuck_collect_recovered',
				]);
			}
		}
	}

	private static function recover_orphan_publish_lock(): void {
		$lock = get_option('epv2_lock_publish', false);
		$publishing_items = EPV2_Queue::get_items(['state' => 'publishing', 'limit' => 3]);
		$has_publishing_item = $publishing_items !== [];
		$stuck_without_item_after = time() - 30;
		if (is_array($lock) && ! empty($lock['token']) && ! $has_publishing_item) {
			$has_worker = self::has_async_work_scheduled(self::HOOK_PUBLISH_ASYNC);
			$heartbeat = (int) ($lock['heartbeat_at'] ?? 0);
			if ($heartbeat > 0 && $heartbeat <= $stuck_without_item_after && ! $has_worker) {
				delete_option('epv2_lock_publish');
				$latest = EPV2_Runs::latest('publish');
				if ($latest && (string) ($latest->status ?? '') === 'started') {
					$started = strtotime((string) ($latest->started_at ?? '')) ?: 0;
					if ($started > 0 && $started <= $stuck_without_item_after) {
						EPV2_Runs::finish((int) $latest->id, 'finished_with_errors', 0, 1, [
							'result' => 'stuck_without_publishing_item_recovered',
						]);
					}
				}
				if (EPV2_Queue::next_item_for_publish()) {
					self::enqueue_publish();
				}
			}
		}
	}

	private static function pending_action_count(string $hook, string $group): int {
		global $wpdb;
		$table_actions = $wpdb->prefix . 'actionscheduler_actions';
		$table_groups = $wpdb->prefix . 'actionscheduler_groups';
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(1)
			FROM {$table_actions} a
			INNER JOIN {$table_groups} g ON g.group_id = a.group_id
			WHERE a.hook = %s
			  AND g.slug = %s
			  AND a.status = 'pending'",
			$hook,
			$group
		));
	}

	private static function publish_item_is_overdue(object $item): bool {
		$interval_minutes = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5));
		$overdue_after = time() - (($interval_minutes * 2) * MINUTE_IN_SECONDS);
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$ready_at = strtotime((string) ($notes['_system']['ready_publish_at'] ?? '')) ?: 0;
		if ($ready_at <= 0) {
			$ready_at = strtotime((string) ($item->updated_at ?? '')) ?: 0;
		}
		return $ready_at > 0 && $ready_at <= $overdue_after;
	}
}
