<?php
/**
 * R13 2026-05-14: Tier 1 critical alerts через EPV2_Notifier (Telegram).
 *
 * Triggers:
 *  - pipeline_stall — 0 publishes за 30m в active publish window
 *  - ai_budget — Budget_Manager hard_stop fired
 *  - worker_health — /health 4xx/5xx или connection refused
 *  - orchestrator_heartbeat — R7 heartbeat stale >180s
 *
 * Called from bridge_maintenance каждые 180s. Per-trigger dedup 5min через
 * transient locks предотвращает spam при sustained incidents.
 */

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Alerts {

	public static function check_and_alert(): array {
		$fired = [];
		if (self::check_pipeline_stall()) {
			$fired[] = 'pipeline_stall';
		}
		if (self::check_ai_budget_hard_stop()) {
			$fired[] = 'ai_budget';
		}
		if (self::check_worker_health()) {
			$fired[] = 'worker_health';
		}
		if (self::check_orchestrator_heartbeat()) {
			$fired[] = 'orchestrator_heartbeat';
		}
		return $fired;
	}

	/**
	 * Pipeline stall: 0 publishes за 30 мин при том что мы в active publish
	 * window И есть items в ready_publish (т.е. могли бы publish'ить но не делаем).
	 */
	private static function check_pipeline_stall(): bool {
		if (! self::in_active_publish_window()) {
			return false; // night / wind_down — no expectation
		}
		global $wpdb;
		$published_30m = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)"
		);
		if ($published_30m > 0) {
			return false;
		}
		$ready_items = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}epv2_queue
			 WHERE state = 'publishing'
			    OR (
			      state IN ('ready_publish', 'retry_publish')
			      AND (
			        JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.publish_not_before')) IS NULL
			        OR JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.publish_not_before')) = ''
			        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.publish_not_before')) AS UNSIGNED) <= UNIX_TIMESTAMP(UTC_TIMESTAMP())
			      )
			    )"
		);
		if ($ready_items === 0) {
			return false; // no items to publish — legitimate idle
		}
		// R16 2026-05-14: extension hook для third-party monitoring (Slack,
		// Sentry, custom dashboards). Fires только на active stall, не на каждый tick.
		do_action('epv2_pipeline_stalled', 30, $ready_items);
		return self::fire_alert(
			'pipeline_stall',
			'⚠️ Pipeline stall',
			sprintf('0 publishes за 30 мин при %d items в ready_publish. Проверить orchestrator + publish_thread.', $ready_items)
		);
	}

	/**
	 * AI budget hard_stop — daily limit exhausted.
	 */
	private static function check_ai_budget_hard_stop(): bool {
		if (! class_exists('EPV2_Budget_Manager')) {
			return false;
		}
		$state = EPV2_Budget_Manager::budget_state();
		if (empty($state['hard_stop'])) {
			return false;
		}
		return self::fire_alert(
			'ai_budget',
			'🚨 AI budget exhausted',
			sprintf(
				'Requests: %d/%d. Tokens: %d/%d. Items идут в rejected.',
				(int) ($state['rewritten_today'] ?? 0),
				(int) ($state['request_limit'] ?? 0),
				(int) ($state['tokens_today'] ?? 0),
				(int) ($state['token_limit'] ?? 0)
			)
		);
	}

	/**
	 * Worker /health endpoint — 4xx/5xx или connection refused.
	 */
	private static function check_worker_health(): bool {
		$url = 'http://127.0.0.1:8765/health';
		$response = wp_remote_get($url, ['timeout' => 3, 'redirection' => 0]);
		if (is_wp_error($response)) {
			return self::fire_alert(
				'worker_health',
				'🔴 Worker unreachable',
				'Worker /health не отвечает: ' . $response->get_error_message()
			);
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code >= 400 || $code === 0) {
			return self::fire_alert(
				'worker_health',
				'🔴 Worker /health error',
				sprintf('Worker /health returned HTTP %d. Check `systemctl status epv2-worker`.', $code)
			);
		}
		return false;
	}

	/**
	 * R7 heartbeat stale >180s — orchestrator dead or wedged.
	 */
	private static function check_orchestrator_heartbeat(): bool {
		$hb = get_option('epv2_publish_thread_heartbeat', null);
		if (! is_array($hb) || empty($hb['ts'])) {
			return false; // heartbeat never recorded (boot state) — skip
		}
		$ts = strtotime((string) $hb['ts'] . ' UTC');
		if (! $ts) {
			return false;
		}
		$age = time() - $ts;
		if ($age <= 180) {
			return false;
		}
		return self::fire_alert(
			'orchestrator_heartbeat',
			'🔴 Orchestrator heartbeat stale',
			sprintf('Last heartbeat %d сек назад. Check `systemctl status epv2-orchestrator`.', $age)
		);
	}

	/**
	 * Fire alert через EPV2_Notifier с 5-min transient dedup.
	 */
	private static function fire_alert(string $key, string $title, string $message): bool {
		$lock_key = 'epv2_alert_lock_' . $key;
		if (get_transient($lock_key)) {
			return false; // already fired в последние 5 мин
		}
		set_transient($lock_key, time(), 5 * MINUTE_IN_SECONDS);
		if (class_exists('EPV2_Notifier')) {
			EPV2_Notifier::notify('alert', 'autopilot', $title . "\n" . $message);
		}
		return true;
	}

	/**
	 * Active publish window check — Time_Planner mode'ы где expect publishes.
	 */
	private static function in_active_publish_window(): bool {
		if (! class_exists('EPV2_Time_Planner')) {
			return false;
		}
		$window = EPV2_Time_Planner::current_window();
		$mode = (string) ($window['mode'] ?? '');
		if (method_exists('EPV2_Time_Planner', 'timestamp_allows_regular_publish')) {
			$offset = method_exists('EPV2_Jobs', 'publish_slot_offset_seconds') ? EPV2_Jobs::publish_slot_offset_seconds() : 2 * MINUTE_IN_SECONDS;
			return EPV2_Time_Planner::timestamp_allows_regular_publish(time(), $offset)
				|| (class_exists('EPV2_Queue') && (EPV2_Queue::has_due_publish_item() || EPV2_Queue::has_due_breaking_publish_item()));
		}
		return in_array($mode, [
			'morning_catchup',
			'daytime_active',
			'daytime_peak',
			'evening_prime',
		], true);
	}
}
