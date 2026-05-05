<?php

if (! defined('ABSPATH')) {
	exit;
}

	final class EPV2_AI_Client {
	public static function preflight(array $config): array {
		$provider = sanitize_key((string) ($config['provider'] ?? ''));
		$model = sanitize_key((string) ($config['model'] ?? ''));
		if ($provider === '' || $model === '' || empty($config['api_key'])) {
			return ['ok' => false, 'message' => 'AI API key or model missing'];
		}
		$cache_key = 'epv2_ai_preflight_' . md5($provider . '|' . $model);
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached;
		}
		$probe = $config;
		$probe['timeout'] = 12;
		$probe['max_tokens'] = self::preflight_token_budget($provider, $model);
		$probe['temperature'] = 0.1;
		try {
			$result = self::generate($probe, [
				[
					'role' => 'system',
					'content' => 'Return only compact JSON.',
				],
				[
					'role' => 'user',
					'content' => '{"ping":"ok"}',
				],
			]);
			$response = [
				'ok' => ! empty($result['text']),
				'message' => ! empty($result['text']) ? 'AI provider responded' : 'AI provider returned empty content',
			];
		} catch (Throwable $e) {
			$response = [
				'ok' => false,
				'message' => EPV2_Resilience_Manager::humanize_error($e->getMessage()),
			];
		}
		set_transient($cache_key, $response, 5 * MINUTE_IN_SECONDS);
		return $response;
	}

	public static function generate(array $config, array $messages): array {
		if (empty($config['api_key'])) {
			throw new RuntimeException('AI API key missing');
		}
		$provider = (string) ($config['provider'] ?? 'gemini');
		if ($provider === 'deepseek') {
			self::maybe_wake_provider($config);
		}
		if (! EPV2_Resilience_Manager::provider_available($provider)) {
			throw new RuntimeException('AI provider unavailable: cooldown active');
		}
		try {
			$result = match ($provider) {
				'openai' => self::openai_like_request('https://api.openai.com/v1/chat/completions', $config, $messages, (string) $config['api_key']),
				'deepseek' => self::openai_like_request('https://api.deepseek.com/chat/completions', $config, $messages, (string) $config['api_key']),
				'anthropic' => self::anthropic_request($config, $messages),
				default => self::gemini_request($config, $messages),
			};
			EPV2_Stats::bump_ai_request($provider, (string) ($config['model'] ?? ''), (int) ($result['tokens'] ?? 0), 0.0);
			EPV2_Resilience_Manager::register_provider_success($provider);
			return $result;
		} catch (Throwable $e) {
			EPV2_Resilience_Manager::register_provider_failure($provider, $e->getMessage());
			throw $e;
		}
	}

	private static function preflight_token_budget(string $provider, string $model): int {
		$is_openai_reasoning_model = $provider === 'openai' && (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o'));
		return $is_openai_reasoning_model ? 800 : 120;
	}

	private static function gemini_request(array $config, array $messages): array {
		if (($config['model'] ?? '') === 'gemini-auto-free') {
			return self::gemini_auto_free_request($config, $messages);
		}
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode((string) $config['model']) . ':generateContent?key=' . rawurlencode((string) $config['api_key']);
		$parts = [];
		foreach ($messages as $message) {
			$parts[] = ['text' => (string) ($message['content'] ?? '')];
		}
		$body = [
			'contents' => [
				['parts' => $parts],
			],
			'generationConfig' => [
				'temperature' => (float) ($config['temperature'] ?? 0.4),
				'maxOutputTokens' => (int) ($config['max_tokens'] ?? 3000),
			],
		];
		$tools = [];
		if (! empty($config['gemini_search_grounding_enabled'])) {
			$tools[] = ['google_search' => (object) []];
		}
		if (! empty($config['gemini_url_context_enabled'])) {
			$tools[] = ['url_context' => (object) []];
		}
		if (! empty($tools)) {
			$body['tools'] = $tools;
		} else {
			$body['generationConfig']['responseMimeType'] = 'application/json';
		}

			$response = wp_remote_post($url, [
				'timeout' => max(10, min(45, (int) ($config['timeout'] ?? 35))),
				'headers' => ['Content-Type' => 'application/json'],
				'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
			]);

		return self::parse_response($response, static function (array $data): array {
			$text = (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
			$tokens = (int) ($data['usageMetadata']['totalTokenCount'] ?? 0);
			return [
				'text' => $text,
				'tokens' => $tokens,
				'raw' => $data,
				'grounding_metadata' => $data['candidates'][0]['groundingMetadata'] ?? [],
				'url_context_metadata' => $data['candidates'][0]['urlContextMetadata'] ?? [],
			];
		});
	}

	private static function openai_like_request(string $url, array $config, array $messages, string $api_key): array {
		$is_deepseek = str_contains($url, 'deepseek.com');
		$model = (string) $config['model'];
		$is_openai_reasoning_model = ! $is_deepseek && (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o'));
		$body = [
			'model' => $model,
			'messages' => array_map(static function (array $message): array {
				return [
					'role' => (string) ($message['role'] ?? 'user'),
					'content' => (string) ($message['content'] ?? ''),
				];
			}, $messages),
			'response_format' => ['type' => 'json_object'],
		];
		if ($is_deepseek) {
			$body['temperature'] = (float) ($config['temperature'] ?? 0.4);
			$body['max_tokens'] = (int) ($config['max_tokens'] ?? 3000);
		} elseif ($is_openai_reasoning_model) {
			$body['max_completion_tokens'] = (int) ($config['max_tokens'] ?? 3000);
		} else {
			$body['temperature'] = (float) ($config['temperature'] ?? 0.4);
			$body['max_tokens'] = (int) ($config['max_tokens'] ?? 3000);
		}

		$default_timeout = $is_deepseek ? 40 : 35;
		$request = [
			'timeout' => max(15, min($is_deepseek ? 40 : 60, (int) ($config['timeout'] ?? $default_timeout))),
			'headers' => [
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
		];

		$response = null;
		$attempts = $is_deepseek ? 2 : 1;
		for ($attempt = 1; $attempt <= $attempts; $attempt++) {
			$response = wp_remote_post($url, $request);
			if (! is_wp_error($response)) {
				break;
			}
			$message = $response->get_error_message();
			if (! $is_deepseek || preg_match('/timed out|cURL error 28/i', $message) !== 1 || $attempt >= $attempts) {
				break;
			}
			usleep(250000);
		}

		return self::parse_response($response, static function (array $data): array {
			$text = (string) ($data['choices'][0]['message']['content'] ?? '');
			$tokens = (int) ($data['usage']['total_tokens'] ?? 0);
			return ['text' => $text, 'tokens' => $tokens, 'raw' => $data];
		});
	}

	private static function anthropic_request(array $config, array $messages): array {
		$system = '';
		$content = [];
		foreach ($messages as $message) {
			if (($message['role'] ?? '') === 'system') {
				$system .= ($system ? "\n\n" : '') . (string) ($message['content'] ?? '');
			} else {
				$content[] = [
					'role' => (string) ($message['role'] ?? 'user'),
					'content' => (string) ($message['content'] ?? ''),
				];
			}
		}

			$response = wp_remote_post('https://api.anthropic.com/v1/messages', [
				'timeout' => max(10, min(45, (int) ($config['timeout'] ?? 35))),
				'headers' => [
				'Content-Type' => 'application/json',
				'x-api-key' => (string) $config['api_key'],
				'anthropic-version' => '2023-06-01',
			],
			'body' => wp_json_encode([
				'model' => (string) $config['model'],
				'system' => $system,
				'messages' => $content,
				'temperature' => (float) ($config['temperature'] ?? 0.4),
				'max_tokens' => (int) ($config['max_tokens'] ?? 3000),
			], JSON_UNESCAPED_UNICODE),
		]);

		return self::parse_response($response, static function (array $data): array {
			$text = (string) ($data['content'][0]['text'] ?? '');
			$tokens = (int) (($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0));
			return ['text' => $text, 'tokens' => $tokens, 'raw' => $data];
		});
	}

	private static function parse_response($response, callable $extractor): array {
		if (is_wp_error($response)) {
			throw new RuntimeException($response->get_error_message());
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		if ($code < 200 || $code >= 300 || ! is_array($data)) {
			throw new RuntimeException('AI transport error: ' . $code . ' ' . wp_strip_all_tags(substr((string) $body, 0, 280)));
		}
		$result = $extractor($data);
		if (empty($result['text'])) {
			throw new RuntimeException('AI provider returned empty content');
		}
		return $result;
	}

	private static function maybe_wake_provider(array $config): void {
		$cache_key = 'epv2_ai_wake_' . md5((string) ($config['provider'] ?? '') . '|' . (string) ($config['model'] ?? ''));
		if (get_transient($cache_key)) {
			return;
		}
		$probe = $config;
		$probe['timeout'] = 8;
		$probe['max_tokens'] = 24;
		$probe['temperature'] = 0.0;
		try {
			self::openai_like_request('https://api.deepseek.com/chat/completions', $probe, [
				[
					'role' => 'system',
					'content' => 'Return compact JSON only.',
				],
				[
					'role' => 'user',
					'content' => '{"ping":"wake"}',
				],
			], (string) ($config['api_key'] ?? ''));
		} catch (Throwable $e) {
			EPV2_Logger::warning('ai', 'Provider wake probe failed', [
				'provider' => (string) ($config['provider'] ?? ''),
				'error' => $e->getMessage(),
			]);
		}
		set_transient($cache_key, 1, 5 * MINUTE_IN_SECONDS);
	}

	private static function gemini_auto_free_request(array $config, array $messages): array {
		$candidates = [
			'gemini-2.5-flash-lite',
			'gemini-2.5-flash',
			'gemini-2.0-flash-lite',
			'gemini-2.0-flash',
		];
		$errors = [];
		foreach ($candidates as $model) {
			$try = $config;
			$try['model'] = $model;
			try {
				$result = self::gemini_request($try, $messages);
				$result['resolved_model'] = $model;
				return $result;
			} catch (Throwable $e) {
				$errors[] = $model . ': ' . $e->getMessage();
				$message = $e->getMessage();
				if (! preg_match('/\b(429|404|403|quota|not found|rate limit)\b/i', $message)) {
					throw $e;
				}
			}
		}
		throw new RuntimeException('Gemini auto-router failed: ' . implode(' | ', $errors));
	}
}
