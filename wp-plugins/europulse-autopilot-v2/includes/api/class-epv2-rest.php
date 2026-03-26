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
			'callback' => static fn() => rest_ensure_response(EPV2_Queue::get_items(['limit' => 50])),
			'permission_callback' => [self::class, 'can_manage'],
		]);

		register_rest_route('epv2/v1', '/source-test', [
			'methods' => 'POST',
			'callback' => static function (WP_REST_Request $request) {
				return rest_ensure_response(EPV2_Source_Tester::test($request->get_json_params()));
			},
			'permission_callback' => [self::class, 'can_manage'],
		]);

		register_rest_route('epv2/v1', '/worker/execute', [
			'methods' => 'POST',
			'callback' => static function (WP_REST_Request $request) {
				try {
					return rest_ensure_response(EPV2_Worker_Bridge::execute_request($request->get_json_params()));
				} catch (Throwable $e) {
					return new WP_REST_Response([
						'error' => $e->getMessage(),
					], 500);
				}
			},
			'permission_callback' => [self::class, 'can_run_worker'],
		]);
	}

	public static function can_manage(): bool {
		return current_user_can('manage_europulse_autopilot');
	}

	public static function can_run_worker(WP_REST_Request $request): bool {
		$token = trim((string) $request->get_header('x-epv2-worker-token'));
		if ($token === '') {
			return false;
		}
		return hash_equals(EPV2_Settings::worker_shared_secret(), $token);
	}
}
