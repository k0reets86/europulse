<?php
/**
 * EuroPulse AutoPilot v2.1 — Notifier
 *
 * Operator-facing alert channel. The architecture audit (section 3,
 * «6 категорий что не лечится автоматически + Telegram-алерт»)
 * specifies a Telegram bot as the single feedback channel: when a
 * background watchdog can't auto-recover (or when something operator
 * needs to know about happens), this class posts a short message to
 * @europulse_alerts_bot.
 *
 * Without a configured token the class becomes a logger-only no-op —
 * notify() returns early after writing to EPV2_Logger so production
 * traffic is never blocked on the absence of TG configuration.
 *
 * Configuration lives in epv2_settings:
 *   telegram_bot_token  — bot token from @BotFather
 *   telegram_chat_id    — numeric chat id (or "@channel_username")
 *
 * Severity convention:
 *   info     — quiet, log only by default
 *   warn     — TG only if include_warnings = true
 *   error    — TG always
 *   alert    — TG always with @here / loud header
 */

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Notifier {

	/** @var bool deduplication marker per process tick */
	private static $sent_in_request = [];

	public static function notify(string $severity, string $module, string $message, array $context = []): bool {
		$severity = strtolower($severity);
		if (! in_array($severity, ['info', 'warn', 'error', 'alert'], true)) {
			$severity = 'info';
		}

		// 1. Always log to plugin journal — we don't want any alert lost
		// just because TG isn't configured.
		if (class_exists('EPV2_Logger')) {
			$method = $severity === 'info' ? 'info' : ($severity === 'warn' ? 'warning' : 'error');
			if (method_exists('EPV2_Logger', $method)) {
				EPV2_Logger::$method($module, $message, $context);
			}
		}

		// 2. Dedup within a single request — prevents flooding when a
		// failing watchdog runs three times during one maintenance tick.
		$dedup_key = md5($severity . '|' . $module . '|' . $message);
		if (isset(self::$sent_in_request[$dedup_key])) {
			return false;
		}
		self::$sent_in_request[$dedup_key] = true;

		// 3. Don't send `info` to TG by default — too chatty.
		if ($severity === 'info' && ! self::include_info()) {
			return false;
		}
		if ($severity === 'warn' && ! self::include_warnings()) {
			return false;
		}

		[$token, $chat_id] = self::credentials();
		if ($token === '' || $chat_id === '') {
			return false;
		}

		$text = self::format_message($severity, $module, $message, $context);
		return self::post_to_telegram($token, $chat_id, $text);
	}

	/**
	 * Test send (used by the settings "Send test alert" button).
	 */
	public static function test_send(string $message = ''): array {
		[$token, $chat_id] = self::credentials();
		if ($token === '' || $chat_id === '') {
			return ['ok' => false, 'error' => 'token or chat_id not configured in epv2_settings'];
		}
		$payload = $message !== '' ? $message : '✅ EuroPulse AutoPilot — test alert from ' . wp_parse_url(home_url(), PHP_URL_HOST);
		$ok = self::post_to_telegram($token, $chat_id, $payload);
		return ['ok' => $ok, 'sent' => $ok ? $payload : '', 'chat_id' => $chat_id];
	}

	private static function credentials(): array {
		$opt = (array) get_option('epv2_settings', []);
		$token = trim((string) ($opt['telegram_bot_token'] ?? ''));
		$chat_id = trim((string) ($opt['telegram_chat_id'] ?? ''));
		return [$token, $chat_id];
	}

	private static function include_warnings(): bool {
		$opt = (array) get_option('epv2_settings', []);
		return ! empty($opt['telegram_include_warnings']);
	}

	private static function include_info(): bool {
		$opt = (array) get_option('epv2_settings', []);
		return ! empty($opt['telegram_include_info']);
	}

	private static function format_message(string $severity, string $module, string $message, array $context): string {
		$header = match ($severity) {
			'alert' => '🚨 *ALERT* — ',
			'error' => '❗️ *ERROR* — ',
			'warn'  => '⚠️ ',
			default => 'ℹ️ ',
		};
		$ctx_pretty = '';
		if ($context !== []) {
			$lines = [];
			foreach ($context as $k => $v) {
				if (is_scalar($v) || $v === null) {
					$lines[] = '• `' . $k . '`: ' . self::escape_md(self::scalar_to_string($v));
				} else {
					$lines[] = '• `' . $k . '`: ' . self::escape_md(wp_json_encode($v, JSON_UNESCAPED_UNICODE) ?: '?');
				}
			}
			$ctx_pretty = "\n" . implode("\n", $lines);
		}
		$site = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'europulse';
		return $header . '`' . self::escape_md($module) . '` @ `' . self::escape_md($site) . "`\n" . self::escape_md($message) . $ctx_pretty;
	}

	private static function scalar_to_string($v): string {
		if (is_bool($v)) return $v ? 'true' : 'false';
		if ($v === null) return 'null';
		return (string) $v;
	}

	private static function escape_md(string $text): string {
		// Telegram MarkdownV2 reserved characters
		return preg_replace('/([_*\[\]\(\)~`>#\+\-=\|\{\}\.\!])/u', '\\\\$1', $text);
	}

	private static function post_to_telegram(string $token, string $chat_id, string $text): bool {
		$url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
		$response = wp_remote_post($url, [
			'timeout' => 10,
			'body' => [
				'chat_id' => $chat_id,
				'text' => $text,
				'parse_mode' => 'MarkdownV2',
				'disable_web_page_preview' => true,
			],
		]);
		if (is_wp_error($response)) {
			if (class_exists('EPV2_Logger')) {
				EPV2_Logger::warning('notifier', 'TG sendMessage failed', [
					'error' => $response->get_error_message(),
				]);
			}
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			if (class_exists('EPV2_Logger')) {
				EPV2_Logger::warning('notifier', 'TG sendMessage non-2xx', [
					'code' => $code,
					'body' => substr((string) wp_remote_retrieve_body($response), 0, 200),
				]);
			}
			return false;
		}
		return true;
	}
}
