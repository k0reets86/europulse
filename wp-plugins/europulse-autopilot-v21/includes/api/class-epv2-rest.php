<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_REST {
	public static function register_routes(): void {
		register_rest_route('epv2/v1', '/stats', [
			'methods' => 'GET',
			'callback' => static fn() => rest_ensure_response(EPV2_Stats::dashboard()),
			'permission_callback' => [self::class, 'can_manage'],
		]);

		register_rest_route('epv2/v1', '/sources', [
			'methods' => 'GET',
			'callback' => static fn() => rest_ensure_response(EPV2_Sources::all(false)),
			'permission_callback' => [self::class, 'can_manage'],
		]);

		register_rest_route('epv2/v1', '/queue', [
			'methods' => 'GET',
			'callback' => static fn() => rest_ensure_response(EPV2_Queue::queue_items_user_facing_summary(EPV2_Queue::get_queue_items_summary(['limit' => 50]))),
			'permission_callback' => [self::class, 'can_manage'],
		]);

		register_rest_route('epv2/v1', '/source-test', [
			'methods' => 'POST',
			'callback' => static function (WP_REST_Request $request) {
				return rest_ensure_response(EPV2_Source_Tester::test($request->get_json_params()));
			},
			'permission_callback' => [self::class, 'can_manage'],
		]);

		register_rest_route('epv2/v1', '/bridge/health', [
			'methods' => 'GET',
			'callback' => [self::class, 'bridge_health'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/state', [
			'methods' => 'GET',
			'callback' => [self::class, 'bridge_state'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/pause', [
			'methods' => 'POST',
			'callback' => [self::class, 'bridge_pause'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/pause-collect', [
			'methods' => 'POST',
			'callback' => [self::class, 'bridge_pause_collect'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/resume', [
			'methods' => 'POST',
			'callback' => [self::class, 'bridge_resume'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/resume-collect', [
			'methods' => 'POST',
			'callback' => [self::class, 'bridge_resume_collect'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/collect', [
			'methods' => 'POST',
			'callback' => [self::class, 'bridge_collect'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/process', [
			'methods' => 'POST',
			'callback' => [self::class, 'bridge_process'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/publish', [
			'methods' => 'POST',
			'callback' => [self::class, 'bridge_publish'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/maintenance', [
			'methods' => 'POST',
			'callback' => [self::class, 'bridge_maintenance'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/breaking_scan', [
			'methods' => 'POST',
			'callback' => [self::class, 'bridge_breaking_scan'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);

		register_rest_route('epv2/v1', '/bridge/server-orchestrator', [
			'methods' => 'POST',
			'callback' => [self::class, 'bridge_server_orchestrator'],
			'permission_callback' => [self::class, 'can_bridge'],
		]);
	}

	public static function can_manage(): bool {
		return current_user_can('manage_europulse_autopilot');
	}

	public static function can_bridge(WP_REST_Request $request): bool {
		if (self::can_manage()) {
			return true;
		}
		$expected = trim((string) EPV2_Settings::worker_shared_secret());
		if ($expected === '') {
			return false;
		}
		$provided = trim((string) $request->get_header('x-epv2-bridge-token'));
		if ($provided === '') {
			$auth = trim((string) $request->get_header('authorization'));
			if (stripos($auth, 'Bearer ') === 0) {
				$provided = trim(substr($auth, 7));
			}
		}
		// Token check must use hash_equals to avoid timing attacks; both
		// strings must be the same length, otherwise hash_equals returns
		// false with no leak. Empty $provided short-circuits.
		if ($provided === '' || strlen($provided) !== strlen($expected)) {
			return false;
		}
		$matched = hash_equals($expected, $provided);
		if ($matched && class_exists('EPV2_Logger')) {
			// Audit trail: log every successful token auth so a leaked
			// secret or unexpected caller is observable post-hoc. The
			// token itself is never logged.
			$user_id = get_current_user_id();
			EPV2_Logger::info('rest', 'bridge token auth ok', [
				'route'    => (string) $request->get_route(),
				'method'   => (string) $request->get_method(),
				'user_id'  => $user_id,
				'remote_ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
			]);
		}
		return $matched;
	}

	public static function bridge_health(WP_REST_Request $request): WP_REST_Response {
		$worker = class_exists('EPV2_Worker_Client')
			? EPV2_Worker_Client::health_snapshot()
			: [
				'enabled' => false,
				'mode' => 'missing',
				'available' => false,
				'http_code' => 0,
				'error' => 'worker client class missing',
				'payload' => [],
			];
		$queue = EPV2_Queue::bridge_health_snapshot();
		$checks = [
			'worker' => [
				'status' => ! empty($worker['enabled']) && empty($worker['available']) ? 'failed' : 'ok',
				'details' => $worker,
			],
			'locks' => [
				'collect' => EPV2_Lock_Manager::status_snapshot('collect'),
				'process' => EPV2_Lock_Manager::status_snapshot('process'),
				'publish' => EPV2_Lock_Manager::status_snapshot('publish'),
			],
			'runs' => [
				'collect' => EPV2_Runs::health_snapshot('collect', 300),
				'process' => EPV2_Runs::health_snapshot('process', 300),
				'publish' => EPV2_Runs::health_snapshot('publish', 180),
			],
			'queue' => [
				'status' => (($queue['queue_contract']['violations_count'] ?? 0) > 0) ? 'warn' : 'ok',
				'details' => $queue,
			],
			'acceptance' => [
				'status' => (($queue['acceptance']['status'] ?? 'not_proven') === 'accepted')
					? 'ok'
					: ((($queue['acceptance']['consecutive_autonomous_publish_grade'] ?? 0) > 0) ? 'in_progress' : 'warn'),
				'details' => $queue['acceptance'] ?? [],
			],
		];
		$status = 'ok';
		foreach ($checks as $check) {
			if (($check['status'] ?? 'ok') === 'failed') {
				$status = 'degraded';
				break;
			}
			if (($check['status'] ?? 'ok') === 'warn' && $status === 'ok') {
				$status = 'warn';
			}
		}

		return rest_ensure_response([
			'status' => $status,
			'plugin_version' => defined('EPV2_VERSION') ? EPV2_VERSION : 'unknown',
			'runtime_mode' => 'server_orchestrator',
			'cron_orchestration_policy' => 'compatibility_only',
			'automation_paused' => EPV2_Jobs::automation_paused(),
			'collect_paused' => EPV2_Jobs::collect_paused(),
			'server_orchestrator_enabled' => EPV2_Jobs::server_orchestrator_enabled(),
			'worker_enabled' => ! empty($worker['enabled']),
			'worker_available' => ! empty($worker['available']),
			'checks' => $checks,
		]);
	}

	public static function bridge_state(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$run_table = $wpdb->prefix . 'epv2_runs';
		$runtime = EPV2_Queue::bridge_runtime_snapshot();
		$recent_runs = $wpdb->get_results("SELECT id, job_name, status, item_count, error_count, started_at, finished_at FROM {$run_table} ORDER BY id DESC LIMIT 10", ARRAY_A);
		$settings = EPV2_Settings::get_all();
		$summary = [
			'runtime_mode' => 'server_orchestrator',
			'cron_orchestration_policy' => 'compatibility_only',
			'automation_paused' => EPV2_Jobs::automation_paused(),
			'collect_paused' => EPV2_Jobs::collect_paused(),
			'server_orchestrator_enabled' => EPV2_Jobs::server_orchestrator_enabled(),
			'active_automation_item' => (int) ($runtime['active_automation_item'] ?? 0),
			'has_processable_items' => ! empty($runtime['has_processable_items']),
			'next_collect' => self::timestamp_to_gmt(wp_next_scheduled('epv2_collect')),
			'next_process' => self::timestamp_to_gmt(wp_next_scheduled('epv2_process')),
			'next_publish' => self::timestamp_to_gmt(wp_next_scheduled('epv2_publish')),
			'next_ready_publish' => self::timestamp_to_gmt((int) ($runtime['next_ready_publish'] ?? 0)),
			'publish_interval_minutes' => (int) ($settings['publish_interval_minutes'] ?? 5),
			'process_interval_minutes' => (int) ($settings['process_interval_minutes'] ?? 5),
			'collect_interval_minutes' => (int) ($settings['collect_interval_minutes'] ?? 30),
			'daily_publish_target' => (int) ($settings['daily_publish_target'] ?? 0),
			'enforce_daily_publish_target' => ! empty($settings['enforce_daily_publish_target']),
			'queue_states' => is_array($runtime['queue_states'] ?? null) ? $runtime['queue_states'] : [],
			'health_checks' => [
				'queue_contract' => EPV2_Queue::bridge_health_snapshot()['queue_contract'] ?? [],
				'acceptance' => EPV2_Queue::bridge_health_snapshot()['acceptance'] ?? [],
				'worker_available' => class_exists('EPV2_Worker_Client') && EPV2_Worker_Client::enabled() ? EPV2_Worker_Client::is_available() : false,
			],
			'recent_runs' => $recent_runs,
		];
		// Time-window flags для orchestrator (2026-05-12 operator-spec:
		// night-quiet должен быть real, не только декларативный). Orchestrator
		// читает эти флаги вместо force=true bypass'а.
		if (class_exists('EPV2_Time_Planner')) {
			$window = EPV2_Time_Planner::current_window();
			$mode = (string) ($window['mode'] ?? '');
			$is_night = in_array($mode, ['night_monitor', 'wind_down_quiet'], true);
			$summary['current_window_mode'] = $mode;
			$summary['is_night_window'] = $is_night;
			$summary['collect_window_open'] = EPV2_Time_Planner::should_collect(false);
			$summary['publish_window_open'] = EPV2_Time_Planner::should_publish(false);
			$summary['has_breaking_watch'] = EPV2_Time_Planner::has_breaking_watch();
			// Breaking watch minutes — orchestrator calls /bridge/breaking_scan
			// only at these minute marks during night, regardless of overall
			// collect window status.
			$summary['breaking_watch_minutes'] = (array) ($window['breaking_watch_minutes']
				?? EPV2_Settings::get('time_schedule_profile', EPV2_Time_Planner::defaults())['breaking_watch_minutes']
				?? [0, 30]);
		}
		return rest_ensure_response($summary);
	}

	public static function bridge_breaking_scan(WP_REST_Request $request): WP_REST_Response {
		// Night-safe collect: только items с breaking-маркерами (BREAKING /
		// Eilmeldung / Срочно / RSS category=breaking). Bypass'ит time_planner
		// window guards (force=true) НО фильтрует на breaking heuristic в
		// stage_candidate. См. EPV2_Collector::run_breaking_scan().
		if (! class_exists('EPV2_Collector')) {
			return rest_ensure_response([
				'ok' => false,
				'error' => 'collector not available',
			]);
		}
		$started = microtime(true);
		$result = EPV2_Collector::run_breaking_scan();
		return rest_ensure_response([
			'ok' => true,
			'action' => 'breaking_scan',
			'duration_ms' => (int) round((microtime(true) - $started) * 1000),
			'result' => $result,
		]);
	}

	public static function bridge_pause(WP_REST_Request $request): WP_REST_Response {
		EPV2_Jobs::pause_automation();
		return rest_ensure_response([
			'ok' => true,
			'automation_paused' => true,
		]);
	}

	public static function bridge_pause_collect(WP_REST_Request $request): WP_REST_Response {
		EPV2_Jobs::pause_collect();
		return rest_ensure_response([
			'ok' => true,
			'collect_paused' => true,
			'automation_paused' => EPV2_Jobs::automation_paused(),
		]);
	}

	public static function bridge_resume(WP_REST_Request $request): WP_REST_Response {
		EPV2_Jobs::resume_automation();
		return rest_ensure_response([
			'ok' => true,
			'automation_paused' => false,
			'collect_paused' => EPV2_Jobs::collect_paused(),
			'next_collect' => self::timestamp_to_gmt(wp_next_scheduled('epv2_collect')),
			'next_process' => self::timestamp_to_gmt(wp_next_scheduled('epv2_process')),
			'next_publish' => self::timestamp_to_gmt(wp_next_scheduled('epv2_publish')),
		]);
	}

	public static function bridge_resume_collect(WP_REST_Request $request): WP_REST_Response {
		EPV2_Jobs::resume_collect();
		return rest_ensure_response([
			'ok' => true,
			'collect_paused' => false,
			'automation_paused' => EPV2_Jobs::automation_paused(),
			'next_collect' => self::timestamp_to_gmt(wp_next_scheduled('epv2_collect')),
		]);
	}

	public static function bridge_collect(WP_REST_Request $request): WP_REST_Response {
		if (EPV2_Jobs::server_orchestrator_enabled()) {
			return self::bridge_long_job_disabled_response('collect');
		}
		EPV2_Collector::run_scheduled(true);
		return rest_ensure_response([
			'ok' => true,
			'action' => 'collect',
		]);
	}

	public static function bridge_process(WP_REST_Request $request): WP_REST_Response {
		if (EPV2_Jobs::server_orchestrator_enabled()) {
			return self::bridge_long_job_disabled_response('process');
		}
		$ignore_retry_after = ! empty($request->get_json_params()['ignore_retry_after'] ?? false);
		EPV2_AI_Processor::process_scheduled(true, $ignore_retry_after);
		return rest_ensure_response([
			'ok' => true,
			'action' => 'process',
			'ignore_retry_after' => $ignore_retry_after,
		]);
	}

	public static function bridge_publish(WP_REST_Request $request): WP_REST_Response {
		EPV2_Publisher::publish_scheduled(true);
		return rest_ensure_response([
			'ok' => true,
			'action' => 'publish',
		]);
	}

	private static function bridge_long_job_disabled_response(string $action): WP_REST_Response {
		$response = rest_ensure_response([
			'ok' => false,
			'action' => sanitize_key($action),
			'skipped' => 'http_long_job_disabled',
			'reason' => 'server_orchestrator mode runs long jobs through WP-CLI to avoid HTTP gateway timeouts and ghost locks',
			'runner' => 'wp-cli-orchestrator',
		]);
		$response->set_status(202);
		return $response;
	}

	public static function bridge_maintenance(WP_REST_Request $request): WP_REST_Response {
		$cleanup = [];
		$cleanup['abandoned_started_runs'] = EPV2_Runs::cleanup_abandoned_started(120);
		$cleanup['promoted_live_published_rows'] = EPV2_Queue::promote_live_published_rows(20);
		$cleanup['reactivated_media_rows'] = EPV2_Queue::reactivate_media_recoverable_rows(5);
		// P1.7 (audit-found order conflict): prune_new_stale + trim_new_queue
		// run EARLY, перед reactivate/auto_route handlers. Если item over cap
		// или TTL exceeded, removing it FIRST prevents downstream handlers
		// from work on doomed items (auto_promote_complete on item that will
		// be trim'нут, reactivate on item that will be pruned, etc.).
		$ttl_hours = max(1, (int) EPV2_Settings::get('queue_new_ttl_hours', 5));
		$cleanup['pruned_new_stale'] = EPV2_Queue::prune_new_stale($ttl_hours);
		$cleanup['trimmed_new_queue'] = EPV2_Queue::trim_new_queue(
			max(1, (int) EPV2_Settings::get('queue_new_max_per_category', 8)),
			max(1, (int) EPV2_Settings::get('queue_new_max_per_source', 6))
		);
		$cleanup['reactivated_planner_soft_rejected_rows'] = EPV2_Queue::reactivate_planner_selected_soft_rejected_items(50);
		$cleanup['rejected_non_publish_grade_new_rows'] = EPV2_Queue::sanitize_non_publish_grade_new_items(150);
		$cleanup['rejected_low_grade_ready_publish_rows'] = EPV2_Queue::sanitize_low_grade_ready_publish_items(50);
		$cleanup['workflow_quarantine'] = EPV2_Queue::quarantine_pathological_workflow_loops(100);
		$cleanup['promoted_ready_like_rows'] = EPV2_Queue::promote_ready_like_rows(50);
		// Auto-router: state='new' items с готовым payload → ready_publish;
		// state='new' items с manual_confirmation_required / terminal_reason
		// → manual_review. Без этого они зависают в «Новые», но selector их
		// не берёт (bridge filter пропускает).
		$cleanup['auto_routed_new_rows'] = EPV2_Queue::auto_route_misclassified_new_items(50);
		// Items в ready_publish с quality ниже порога / carry-over quarantine,
		// которые publish-gate стабильно отклоняет → manual_review. Иначе
		// publish-таймер бесконечно растёт без фактической публикации.
		$cleanup['sanitized_stuck_ready_publish_rows'] = EPV2_Queue::sanitize_stuck_ready_publish_items(50);
		// Items в state='publishing' с post_id=NULL >8 мин — publish_item()
		// был убит mid-flight (FastCGI timeout / fatal). Возвращаем в
		// ready_publish для повторной попытки. Без этого guard'а row сидит
		// в 'publishing' навсегда, и никто его не освобождает.
		$cleanup['sanitized_stuck_publishing_rows'] = EPV2_Queue::sanitize_stuck_publishing_items(20);
		// Items в state='processing_de' с updated_at >30 мин и без
		// active_pointer'a — orchestrator не завершил, worker умер или
		// зависает. Освобождаем чтобы category_cap не блокировал ingest
		// (один stuck processing_de держит cap всю рубрику на часы).
		$cleanup['sanitized_stuck_processing_de_rows'] = EPV2_Queue::sanitize_stuck_processing_de_items(20);
		// Items в manual_review с qual=100 + всеми 3 языками + media —
		// auto-promote назад в retry_process (carry-over quarantine cleanup).
		$cleanup['auto_promoted_complete_manual_rows'] = EPV2_Queue::auto_promote_complete_manual_review_items(30);
		// Operator-агреемент 2026-05-09: «всё что больше 80 чисти». Терминальные
		// rejected/error/duplicate items, выходящие за пределы 80, удаляются
		// каждым maintenance тиком — admin (heavy path лимитирован 80 items по
		// created_at) больше не вытесняет manual_review/ready_publish из видимости.
		$cleanup['trimmed_terminal_rows'] = EPV2_Queue::trim_old_terminal_items(80);
		// prune_new_stale + trim_new_queue moved earlier in pipeline (P1.7
		// reorder 2026-05-11) — runs перед reactivate/auto_route handlers
		// to prevent work on doomed items.
		// Phase 3 watchdogs (architecture audit section 3): three background
		// safety nets — stuck-item release, Polylang link repair, dedup of
		// published posts. All idempotent, all return small status arrays.
		if (class_exists('EPV2_Watchdog')) {
			$cleanup['watchdog_stuck_active']    = EPV2_Watchdog::release_stuck_active_item(15);
			$cleanup['watchdog_polylang']        = EPV2_Watchdog::repair_polylang_links(30);
			$cleanup['watchdog_dedupe']          = EPV2_Watchdog::dedupe_published_posts(20);
			$cleanup['watchdog_legacy_reset']    = EPV2_Watchdog::auto_reset_legacy_quarantine(20);
		}
		return rest_ensure_response([
			'ok' => true,
			'action' => 'maintenance',
			'result' => $cleanup,
		]);
	}

	public static function bridge_server_orchestrator(WP_REST_Request $request): WP_REST_Response {
		$params = $request->get_json_params();
		$enabled = ! empty($params['enabled']);
		update_option('epv2_server_orchestrator_enabled', $enabled ? 1 : 0, false);
		EPV2_Jobs::maybe_schedule();
		return rest_ensure_response([
			'ok' => true,
			'action' => 'server_orchestrator',
			'enabled' => $enabled,
			'automation_paused' => EPV2_Jobs::automation_paused(),
			'next_collect' => self::timestamp_to_gmt(wp_next_scheduled('epv2_collect')),
			'next_process' => self::timestamp_to_gmt(wp_next_scheduled('epv2_process')),
			'next_publish' => self::timestamp_to_gmt(wp_next_scheduled('epv2_publish')),
		]);
	}

	private static function timestamp_to_gmt(?int $timestamp): ?string {
		if (! is_int($timestamp) || $timestamp <= 0) {
			return null;
		}
		return gmdate('Y-m-d H:i:s', $timestamp);
	}
}
