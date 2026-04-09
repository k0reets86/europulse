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
	private const OPTION_RUNTIME_MAINTENANCE_AT = 'epv2_runtime_maintenance_at';
	private const RUNTIME_MAINTENANCE_TTL = 15;
	private const INLINE_PROCESS_HANDOFF_LIMIT = 12;
	private const INLINE_PUBLISH_HANDOFF_LIMIT = 6;
	private static int $inline_process_handoff_depth = 0;
	private static int $inline_publish_handoff_depth = 0;

	public static function automation_paused(): bool {
		return ! empty(get_option('epv2_automation_paused', false));
	}

	public static function collect_paused(): bool {
		return ! empty(get_option('epv2_collect_paused', false));
	}

	public static function server_orchestrator_enabled(): bool {
		return ! empty(get_option('epv2_server_orchestrator_enabled', false));
	}

	public static function orchestrator_v2_enabled(): bool {
		return ! empty(EPV2_Settings::get('orchestrator_v2_enabled', false));
	}

	public static function pause_automation(): void {
		update_option('epv2_automation_paused', true, false);
		self::clear_scheduled();
	}

	public static function resume_automation(): void {
		update_option('epv2_automation_paused', false, false);
		self::schedule_recurring();
	}

	public static function pause_collect(): void {
		update_option('epv2_collect_paused', true, false);
		if (! self::automation_paused() && ! self::server_orchestrator_enabled()) {
			self::schedule_recurring();
			return;
		}
		self::clear_hook(self::HOOK_COLLECT);
		self::clear_hook(self::HOOK_COLLECT_ASYNC);
	}

	public static function resume_collect(): void {
		update_option('epv2_collect_paused', false, false);
		if (! self::automation_paused() && ! self::server_orchestrator_enabled()) {
			self::schedule_recurring();
		}
	}

	public static function register(): void {
		add_filter('cron_schedules', [self::class, 'cron_schedules']);
		add_action(self::HOOK_COLLECT, [self::class, 'run_collect_windowed']);
		add_action(self::HOOK_PROCESS, [self::class, 'run_process_windowed']);
		add_action(self::HOOK_PUBLISH, [self::class, 'run_publish_windowed']);
		add_action(self::HOOK_COLLECT_ASYNC, [self::class, 'run_collect_async']);
		add_action(self::HOOK_PROCESS_ASYNC, [self::class, 'run_process_async']);
		add_action(self::HOOK_PUBLISH_ASYNC, [self::class, 'run_publish_async']);
		EPV2_Weekly_Analysis::register();
		EPV2_Weekly_Analysis::maybe_schedule();
	}

	public static function schedule_recurring(): void {
		self::clear_legacy_async_hooks();
		if (self::server_orchestrator_enabled()) {
			self::clear_scheduled();
			return;
		}
		if (self::collect_paused()) {
			self::clear_hook(self::HOOK_COLLECT);
			self::clear_hook(self::HOOK_COLLECT_ASYNC);
		} else {
			self::schedule_hook(self::HOOK_COLLECT, EPV2_Settings::get('collect_interval_minutes', 30));
		}
		self::schedule_hook(self::HOOK_PROCESS, EPV2_Settings::get('process_interval_minutes', 5));
		self::schedule_hook(self::HOOK_PUBLISH, EPV2_Settings::get('publish_interval_minutes', 5));
	}

	public static function clear_scheduled(): void {
		foreach ([self::HOOK_COLLECT, self::HOOK_PROCESS, self::HOOK_PUBLISH, self::HOOK_COLLECT_ASYNC, self::HOOK_PROCESS_ASYNC, self::HOOK_PUBLISH_ASYNC] as $hook) {
			self::clear_hook($hook);
		}
	}

	public static function maybe_schedule(): void {
		self::clear_legacy_async_hooks();
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
		if (self::async_dispatch_guard_active($hook)) {
			return;
		}
		if (self::has_async_work_scheduled($hook, $args)) {
			return;
		}
		if (! wp_next_scheduled($hook, $args)) {
			wp_schedule_single_event(time() + 1, $hook, $args);
			self::touch_async_dispatch_guard($hook);
			self::maybe_spawn_async_cron();
		}
	}

	public static function enqueue_collect(): void {
		if (self::collect_paused()) {
			return;
		}
		self::dispatch_async(self::HOOK_COLLECT_ASYNC);
	}

	public static function enqueue_process(): void {
		if (self::can_run_inline_process_handoff()) {
			self::$inline_process_handoff_depth++;
			try {
				self::run_process_async();
			} finally {
				self::$inline_process_handoff_depth--;
			}
			return;
		}
		self::dispatch_async(self::HOOK_PROCESS_ASYNC);
	}

	public static function enqueue_publish(): void {
		if (self::can_run_inline_publish_handoff()) {
			self::$inline_publish_handoff_depth++;
			try {
				self::run_publish_async();
			} finally {
				self::$inline_publish_handoff_depth--;
			}
			return;
		}
		self::dispatch_async(self::HOOK_PUBLISH_ASYNC);
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
		if (self::collect_paused()) {
			return;
		}
		self::maybe_schedule();
		if (EPV2_Lock_Manager::is_active('collect')) {
			return;
		}
		if (EPV2_Lock_Manager::is_active('collect') && EPV2_Runs::has_recent_started('collect', 300)) {
			return;
		}
		EPV2_Collector::run_scheduled(false);
	}

	public static function run_process_windowed(): void {
		EPV2_Logger::info('jobs', 'process windowed enter');
		self::maybe_schedule();
		self::maintain_runtime_state(false);
		EPV2_Logger::info('jobs', 'process windowed after maintenance');
		if (EPV2_Lock_Manager::is_active('process')) {
			EPV2_Logger::info('jobs', 'process windowed skip active lock');
			return;
		}
		if (EPV2_Lock_Manager::is_active('process') && EPV2_Runs::has_recent_started('process', 300)) {
			EPV2_Logger::info('jobs', 'process windowed skip recent started');
			return;
		}
		if (! EPV2_Queue::has_processable_items()) {
			EPV2_Logger::info('jobs', 'process windowed skip no processable items');
			return;
		}
		if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
			EPV2_Logger::info('jobs', 'process windowed invoke async inline');
			self::run_process_async();
			return;
		}
		if (! self::has_async_work_scheduled(self::HOOK_PROCESS_ASYNC)) {
			EPV2_Logger::info('jobs', 'process windowed enqueue async');
			self::enqueue_process();
		}
	}

	public static function run_publish_windowed(): void {
		EPV2_Logger::info('jobs', 'publish windowed enter');
		self::maybe_schedule();
		self::maintain_runtime_state(false);
		EPV2_Logger::info('jobs', 'publish windowed after maintenance');
		if (EPV2_Lock_Manager::is_active('publish')) {
			EPV2_Logger::info('jobs', 'publish windowed skip active lock');
			return;
		}
		if (EPV2_Lock_Manager::is_active('publish') && EPV2_Runs::has_recent_started('publish', 180)) {
			EPV2_Logger::info('jobs', 'publish windowed skip recent started');
			return;
		}
		if (! EPV2_Queue::next_item_for_publish()) {
			EPV2_Logger::info('jobs', 'publish windowed skip no publish item');
			return;
		}
		if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
			EPV2_Logger::info('jobs', 'publish windowed invoke async inline');
			self::run_publish_async();
			return;
		}
		if (! self::has_async_work_scheduled(self::HOOK_PUBLISH_ASYNC)) {
			EPV2_Logger::info('jobs', 'publish windowed enqueue async');
			self::enqueue_publish();
		}
	}

	public static function run_collect_async(): void {
		if (self::collect_paused()) {
			return;
		}
		self::maybe_schedule();
		if (EPV2_Lock_Manager::is_active('collect')) {
			return;
		}
		if (EPV2_Lock_Manager::is_active('collect') && EPV2_Runs::has_recent_started('collect', 300)) {
			return;
		}
		EPV2_Collector::run_scheduled(true);
	}

	public static function run_process_async(): void {
		self::maybe_schedule();
		self::maintain_runtime_state(false);
		EPV2_Logger::info('jobs', 'process async after maintenance');
		if (EPV2_Lock_Manager::is_active('process')) {
			return;
		}
		if (EPV2_Lock_Manager::is_active('process') && EPV2_Runs::has_recent_started('process', 300)) {
			return;
		}
		if (self::orchestrator_v2_enabled()) {
			self::run_process_owner_window();
			return;
		}
		EPV2_AI_Processor::process_scheduled(true, false);
	}

	public static function run_publish_async(): void {
		self::maybe_schedule();
		self::maintain_runtime_state(false);
		EPV2_Logger::info('jobs', 'publish async after maintenance');
		if (EPV2_Lock_Manager::is_active('publish')) {
			return;
		}
		if (EPV2_Lock_Manager::is_active('publish') && EPV2_Runs::has_recent_started('publish', 180)) {
			return;
		}
		EPV2_Publisher::publish_scheduled(true);
	}

	private static function run_process_owner_window(): void {
		$max_passes = max(2, min(8, (int) EPV2_Settings::get('max_retry_attempts', 3) + 3));
		$deadline = microtime(true) + 120;
		$preview = EPV2_Queue::workflow_v2_preview_selection(false);
		$locked_owner_id = (int) ($preview['item_id'] ?? 0);

		if ($locked_owner_id <= 0) {
			EPV2_Logger::info('jobs', 'process owner window skip no candidate');
			return;
		}

		for ($pass = 0; $pass < $max_passes; $pass++) {
			if (microtime(true) >= $deadline) {
				EPV2_Logger::info('jobs', 'process owner window stop deadline', [
					'owner_id' => $locked_owner_id,
					'pass' => $pass + 1,
				]);
				break;
			}

			$before = EPV2_Queue::workflow_v2_preview_selection(false);
			EPV2_AI_Processor::process_scheduled(true, false);
			$after = EPV2_Queue::workflow_v2_preview_selection(false);

			$active = EPV2_Queue::active_owner_get();
			$active_id = (int) ($active->id ?? 0);

			if ($active_id !== $locked_owner_id) {
				EPV2_Logger::info('jobs', 'process owner window stop terminal_or_released', [
					'owner_id' => $locked_owner_id,
					'pass' => $pass + 1,
					'active_id' => $active_id,
				]);
				break;
			}

			$before_sig = implode(':', [
				(string) ($before['mode'] ?? ''),
				(string) ($before['item_id'] ?? 0),
				(string) ($before['state'] ?? ''),
				(string) ($before['workflow_step'] ?? ''),
			]);
			$after_sig = implode(':', [
				(string) ($after['mode'] ?? ''),
				(string) ($after['item_id'] ?? 0),
				(string) ($after['state'] ?? ''),
				(string) ($after['workflow_step'] ?? ''),
			]);

			if ($before_sig === $after_sig) {
				$recovery = EPV2_AI_Processor::recover_stalled_owner($locked_owner_id);
				EPV2_Logger::info('jobs', 'process owner window stop no progress', [
					'owner_id' => $locked_owner_id,
					'pass' => $pass + 1,
					'signature' => $after_sig,
					'recovery' => $recovery,
				]);
				break;
			}
		}
	}

	private static function maintain_runtime_state(bool $allow_heavy_cleanup = true): void {
		self::recover_orphan_collect_lock();
		self::recover_orphan_process_lock();
		self::recover_orphan_publish_lock();
		if (! self::runtime_maintenance_due()) {
			return;
		}
		try {
			EPV2_Runs::cleanup_abandoned_started(120);
			EPV2_Queue::normalize_non_active_recoverable_items();
			if ($allow_heavy_cleanup) {
				EPV2_Resilience_Manager::cleanup();
				EPV2_Queue::promote_publish_ready_payloads(['retry_process', 'ready_review']);
				EPV2_Queue::normalize_ready_publish_schedule();
			}
			EPV2_Queue::normalize_non_active_recoverable_items();
		} catch (Throwable $e) {
			EPV2_Logger::warning('jobs', 'runtime maintenance skipped after recoverable failure', [
				'error' => $e->getMessage(),
				'heavy_cleanup' => $allow_heavy_cleanup ? 1 : 0,
			]);
		}
		self::touch_runtime_maintenance();
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

	private static function clear_hook(string $hook): void {
		wp_clear_scheduled_hook($hook);
	}

	private static function clear_legacy_async_hooks(): void {
		foreach ([self::HOOK_COLLECT_ASYNC, self::HOOK_PROCESS_ASYNC, self::HOOK_PUBLISH_ASYNC] as $hook) {
			wp_clear_scheduled_hook($hook);
		}
	}

	private static function has_async_work_scheduled(string $hook, array $args = []): bool {
		return (bool) wp_next_scheduled($hook, $args);
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
			self::HOOK_PROCESS_ASYNC => 2,
			self::HOOK_PUBLISH_ASYNC => 20,
			default => 20,
		};
	}

	private static function maybe_spawn_async_cron(): void {
		if (defined('WP_CLI') && WP_CLI) {
			return;
		}
		if (wp_doing_cron() || wp_doing_ajax()) {
			return;
		}
		if (! function_exists('spawn_cron')) {
			return;
		}
		@spawn_cron(time());
	}

	private static function can_run_inline_process_handoff(): bool {
		if (! (wp_doing_cron() || (defined('WP_CLI') && WP_CLI))) {
			return false;
		}
		if (self::$inline_process_handoff_depth >= self::INLINE_PROCESS_HANDOFF_LIMIT) {
			return false;
		}
		if (EPV2_Lock_Manager::is_active('process')) {
			return false;
		}
		return EPV2_Queue::has_processable_items();
	}

	private static function can_run_inline_publish_handoff(): bool {
		if (! (wp_doing_cron() || (defined('WP_CLI') && WP_CLI))) {
			return false;
		}
		if (self::$inline_publish_handoff_depth >= self::INLINE_PUBLISH_HANDOFF_LIMIT) {
			return false;
		}
		if (EPV2_Lock_Manager::is_active('publish')) {
			return false;
		}
		return (bool) EPV2_Queue::next_item_for_publish();
	}

	private static function runtime_maintenance_due(): bool {
		$last = (int) get_option(self::OPTION_RUNTIME_MAINTENANCE_AT, 0);
		return $last <= 0 || ($last + self::RUNTIME_MAINTENANCE_TTL) <= time();
	}

	private static function touch_runtime_maintenance(): void {
		update_option(self::OPTION_RUNTIME_MAINTENANCE_AT, time(), false);
	}

	private static function recover_orphan_process_lock(): void {
		$lock = get_option('epv2_lock_process', false);
		$processing_items = EPV2_Queue::get_queue_items_summary(['state' => 'processing_de', 'limit' => 3]);
		$has_processing_item = $processing_items !== [];
		$job_lock_ttl = max(300, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
		$stale_window = max(120, min(300, (int) floor($job_lock_ttl / 3)));
		$stale_after = time() - $stale_window;
		$workerless_processing_stale_window = max(30, min(90, (int) floor($job_lock_ttl / 10)));
		$workerless_processing_stale_after = time() - $workerless_processing_stale_window;
		$stuck_without_item_after = time() - 30;
		$has_worker = self::has_async_work_scheduled(self::HOOK_PROCESS_ASYNC);
		$latest = EPV2_Runs::latest('process');
		$latest_started = $latest ? (strtotime((string) ($latest->started_at ?? '')) ?: 0) : 0;
		if (is_array($lock) && ! empty($lock['token']) && ! $has_processing_item) {
			$heartbeat = (int) ($lock['heartbeat_at'] ?? 0);
			if ($heartbeat > 0 && $heartbeat <= $stuck_without_item_after && ! $has_worker) {
				delete_option('epv2_lock_process');
				if ($latest && (string) ($latest->status ?? '') === 'started') {
					$started = $latest_started;
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
					$error = 'Обработка прервалась: lock исчез до завершения, материал будет автоматически возобновлён с текущего этапа.';
				}
				EPV2_Queue::mark_state((int) $item->id, 'retry_process', [
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
			$effective_stale_after = $has_worker ? $stale_after : $workerless_processing_stale_after;
			if ($heartbeat > 0 && $heartbeat > $effective_stale_after) {
				return;
			}
			if ($has_worker) {
				return;
			}
			$requeued = false;
			foreach ($processing_items as $item) {
				$error = trim((string) ($item->error_message ?? ''));
				$updated = strtotime((string) ($item->updated_at ?? '')) ?: 0;
				if ($updated > 0 && $updated > $effective_stale_after) {
					continue;
				}
				if ($error === '') {
					$error = 'Обработка прервалась: worker исчез до завершения, материал будет автоматически возобновлён с текущего этапа.';
				}
				if ($updated <= 0 || $updated <= $effective_stale_after) {
					EPV2_Queue::mark_state((int) $item->id, 'retry_process', [
						'error_message' => $error,
					]);
					$requeued = true;
				}
			}
			delete_option('epv2_lock_process');
			if ($latest && (string) ($latest->status ?? '') === 'started') {
				$started = $latest_started;
				if ($started > 0 && $started <= $effective_stale_after) {
					EPV2_Runs::finish((int) $latest->id, 'finished_with_errors', 0, 1, [
						'result' => 'stale_processing_lock_recovered',
					]);
				}
			}
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
		$meta = is_array($lock['meta'] ?? null) ? $lock['meta'] : [];
		$stale_window = (int) ($meta['stale_after'] ?? 300);
		$stale_window = max(300, min(3600, $stale_window));
		$stale_after = time() - $stale_window;
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
		$publishing_items = EPV2_Queue::get_queue_items_summary(['state' => 'publishing', 'limit' => 3]);
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
				if (! self::server_orchestrator_enabled() && EPV2_Queue::next_item_for_publish()) {
					self::enqueue_publish();
				}
			}
		}
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
