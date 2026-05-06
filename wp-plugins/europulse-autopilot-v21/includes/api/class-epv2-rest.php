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
		return rest_ensure_response($summary);
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
		$cleanup['reactivated_planner_soft_rejected_rows'] = EPV2_Queue::reactivate_planner_selected_soft_rejected_items(50);
		$cleanup['rejected_non_publish_grade_new_rows'] = EPV2_Queue::sanitize_non_publish_grade_new_items(150);
		$cleanup['rejected_low_grade_ready_publish_rows'] = EPV2_Queue::sanitize_low_grade_ready_publish_items(50);
		$cleanup['workflow_quarantine'] = EPV2_Queue::quarantine_pathological_workflow_loops(100);
		$cleanup['promoted_ready_like_rows'] = EPV2_Queue::promote_ready_like_rows(50);
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
