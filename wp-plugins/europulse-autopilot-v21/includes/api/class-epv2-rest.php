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
			'callback' => static fn() => rest_ensure_response(EPV2_Queue::get_queue_items_summary(['limit' => 50])),
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
		return $provided !== '' && hash_equals($expected, $provided);
	}

	public static function bridge_health(WP_REST_Request $request): WP_REST_Response {
		return rest_ensure_response([
			'status' => 'ok',
			'plugin_version' => defined('EPV2_VERSION') ? EPV2_VERSION : 'unknown',
			'automation_paused' => EPV2_Jobs::automation_paused(),
			'collect_paused' => EPV2_Jobs::collect_paused(),
			'server_orchestrator_enabled' => EPV2_Jobs::server_orchestrator_enabled(),
			'worker_enabled' => class_exists('EPV2_Worker_Client') ? EPV2_Worker_Client::enabled() : false,
			'worker_available' => class_exists('EPV2_Worker_Client') && EPV2_Worker_Client::enabled() ? EPV2_Worker_Client::is_available() : false,
		]);
	}

	public static function bridge_state(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$queue_table = $wpdb->prefix . 'epv2_queue';
		$run_table = $wpdb->prefix . 'epv2_runs';
		$queue_states = $wpdb->get_results("SELECT state, COUNT(*) c FROM {$queue_table} GROUP BY state ORDER BY c DESC", ARRAY_A);
		$recent_runs = $wpdb->get_results("SELECT id, job_name, status, item_count, error_count, started_at, finished_at FROM {$run_table} ORDER BY id DESC LIMIT 10", ARRAY_A);
		$settings = EPV2_Settings::get_all();
		$summary = [
			'automation_paused' => EPV2_Jobs::automation_paused(),
			'collect_paused' => EPV2_Jobs::collect_paused(),
			'server_orchestrator_enabled' => EPV2_Jobs::server_orchestrator_enabled(),
			'active_automation_item' => (int) get_option('epv2_active_automation_item', 0),
			'next_collect' => self::timestamp_to_gmt(wp_next_scheduled('epv2_collect')),
			'next_process' => self::timestamp_to_gmt(wp_next_scheduled('epv2_process')),
			'next_publish' => self::timestamp_to_gmt(wp_next_scheduled('epv2_publish')),
			'next_ready_publish' => self::timestamp_to_gmt(EPV2_Queue::next_ready_publish_timestamp()),
			'publish_interval_minutes' => (int) ($settings['publish_interval_minutes'] ?? 5),
			'process_interval_minutes' => (int) ($settings['process_interval_minutes'] ?? 5),
			'collect_interval_minutes' => (int) ($settings['collect_interval_minutes'] ?? 30),
			'daily_publish_target' => (int) ($settings['daily_publish_target'] ?? 0),
			'enforce_daily_publish_target' => ! empty($settings['enforce_daily_publish_target']),
			'queue_states' => $queue_states,
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
		EPV2_Collector::run_scheduled(true);
		return rest_ensure_response([
			'ok' => true,
			'action' => 'collect',
		]);
	}

	public static function bridge_process(WP_REST_Request $request): WP_REST_Response {
		$ignore_retry_after = ! empty($request->get_json_params()['ignore_retry_after'] ?? false);
		EPV2_AI_Processor::process_scheduled(true, $ignore_retry_after);
		return rest_ensure_response([
			'ok' => true,
			'action' => 'process',
			'ignore_retry_after' => $ignore_retry_after,
		]);
	}

	public static function bridge_publish(WP_REST_Request $request): WP_REST_Response {
		EPV2_Publisher::publish_scheduled(false);
		return rest_ensure_response([
			'ok' => true,
			'action' => 'publish',
		]);
	}

	public static function bridge_maintenance(WP_REST_Request $request): WP_REST_Response {
		$cleanup = [];
		$cleanup['promoted_live_published_rows'] = EPV2_Queue::promote_live_published_rows(20);
		$cleanup['reactivated_media_rows'] = EPV2_Queue::reactivate_media_recoverable_rows(5);
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
