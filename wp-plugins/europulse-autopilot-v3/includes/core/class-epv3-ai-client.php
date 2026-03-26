<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_AI_Client {
	public static function config(): array {
		$epv2 = get_option('epv2_settings', []);
		$epv2 = is_array($epv2) ? $epv2 : [];
		$provider = (string) ($epv2['ai_provider'] ?? EPV3_Settings::get('ai_provider', 'gemini'));
		$model = (string) ($epv2['ai_model'] ?? EPV3_Settings::get('ai_model', 'gemini-2.5-flash-lite'));
		$keys = is_array($epv2['ai_keys'] ?? null) ? $epv2['ai_keys'] : [];
		$api_key = (string) ($keys[$provider] ?? '');

		return [
			'provider' => $provider,
			'model' => $model,
			'api_key' => $api_key,
			'temperature' => 0.4,
			'max_tokens' => 2200,
			'gemini_search_grounding_enabled' => ! empty($epv2['gemini_search_grounding_enabled']),
			'gemini_url_context_enabled' => ! empty($epv2['gemini_url_context_enabled']),
			'gemini_use_source_url_in_prompt' => ! empty($epv2['gemini_use_source_url_in_prompt']),
		];
	}

	public static function available(): bool {
		$config = self::config();
		return ! empty($config['provider']) && ! empty($config['model']) && ! empty($config['api_key']);
	}

	public static function generate_json(string $system_prompt, string $user_prompt, int $max_tokens = 2200): array {
		$config = self::config();
		if (empty($config['api_key'])) {
			throw new RuntimeException('AI config missing');
		}
		$config['max_tokens'] = $max_tokens;
		$result = self::generate($config, [
			['role' => 'system', 'content' => $system_prompt],
			['role' => 'user', 'content' => $user_prompt],
		]);
		$data = json_decode((string) ($result['text'] ?? ''), true);
		if (! is_array($data)) {
			throw new RuntimeException('AI returned non-JSON content');
		}
		return $data;
	}

	public static function generate(array $config, array $messages): array {
		$provider = (string) ($config['provider'] ?? 'gemini');
		return match ($provider) {
			'openai' => self::openai_like_request('https://api.openai.com/v1/chat/completions', $config, $messages, (string) $config['api_key']),
			'deepseek' => self::openai_like_request('https://api.deepseek.com/chat/completions', $config, $messages, (string) $config['api_key']),
			default => self::gemini_request($config, $messages),
		};
	}

	private static function gemini_request(array $config, array $messages): array {
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
				'maxOutputTokens' => (int) ($config['max_tokens'] ?? 2200),
			],
		];
		if (! empty($config['gemini_search_grounding_enabled']) || ! empty($config['gemini_url_context_enabled'])) {
			$tools = [];
			if (! empty($config['gemini_search_grounding_enabled'])) {
				$tools[] = ['google_search' => (object) []];
			}
			if (! empty($config['gemini_url_context_enabled'])) {
				$tools[] = ['url_context' => (object) []];
			}
			$body['tools'] = $tools;
		} else {
			$body['generationConfig']['responseMimeType'] = 'application/json';
		}
		$response = wp_remote_post($url, [
			'timeout' => 45,
			'headers' => ['Content-Type' => 'application/json'],
			'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
		]);

		return self::parse_response($response, static function (array $data): array {
			return [
				'text' => (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''),
				'tokens' => (int) ($data['usageMetadata']['totalTokenCount'] ?? 0),
				'raw' => $data,
			];
		});
	}

	private static function openai_like_request(string $url, array $config, array $messages, string $api_key): array {
		$response = wp_remote_post($url, [
			'timeout' => 60,
			'headers' => [
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body' => wp_json_encode([
				'model' => (string) ($config['model'] ?? ''),
				'messages' => array_map(static fn(array $m): array => [
					'role' => (string) ($m['role'] ?? 'user'),
					'content' => (string) ($m['content'] ?? ''),
				], $messages),
				'temperature' => (float) ($config['temperature'] ?? 0.4),
				'max_tokens' => (int) ($config['max_tokens'] ?? 2200),
				'response_format' => ['type' => 'json_object'],
			], JSON_UNESCAPED_UNICODE),
		]);

		return self::parse_response($response, static function (array $data): array {
			return [
				'text' => (string) ($data['choices'][0]['message']['content'] ?? ''),
				'tokens' => (int) ($data['usage']['total_tokens'] ?? 0),
				'raw' => $data,
			];
		});
	}

	private static function parse_response($response, callable $extractor): array {
		if (is_wp_error($response)) {
			throw new RuntimeException($response->get_error_message());
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		if ($code < 200 || $code >= 300 || ! is_array($data)) {
			throw new RuntimeException('AI transport error: ' . $code . ' ' . wp_strip_all_tags(substr($body, 0, 240)));
		}
		$result = $extractor($data);
		if (empty($result['text'])) {
			throw new RuntimeException('AI returned empty response');
		}
		return $result;
	}
}
