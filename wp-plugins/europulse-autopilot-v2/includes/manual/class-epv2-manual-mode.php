<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Manual_Mode {
	private const META_KEY = 'epv2_manual_draft';

	public static function generate_from_url(string $url): array {
		try {
			return [
				'success' => true,
				'data' => EPV2_HTML_Reader::fetch_document($url),
			];
		} catch (Throwable $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	public static function queue_from_url(string $url): array {
		try {
			$draft = self::import_from_url($url, self::get_draft());
			$item_id = self::create_review_item($draft);
			return ['success' => true, 'item_id' => $item_id, 'data' => $draft];
		} catch (Throwable $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}

	public static function get_draft(): array {
		$user_id = get_current_user_id();
		$saved = $user_id > 0 ? get_user_meta($user_id, self::META_KEY, true) : [];
		return self::normalize_draft(is_array($saved) ? $saved : []);
	}

	public static function save_draft(array $draft): array {
		$normalized = self::normalize_draft($draft);
		$user_id = get_current_user_id();
		if ($user_id > 0) {
			update_user_meta($user_id, self::META_KEY, $normalized);
		}
		return $normalized;
	}

	public static function reset_draft(): array {
		$user_id = get_current_user_id();
		if ($user_id > 0) {
			delete_user_meta($user_id, self::META_KEY);
		}
		return self::normalize_draft([]);
	}

	public static function import_from_url(string $url, array $draft = []): array {
		$draft = self::normalize_draft($draft);
		if ($url === '') {
			throw new RuntimeException('URL не указан.');
		}
		$data = EPV2_HTML_Reader::fetch_document($url);
		$draft['source_url'] = (string) ($data['url'] ?? $url);
		$draft['title'] = (string) ($data['title'] ?? $draft['title']);
		$draft['excerpt'] = (string) ($data['excerpt'] ?? $draft['excerpt']);
		$draft['content'] = (string) ($data['content'] ?? $draft['content']);
		$draft['featured_media_url'] = (string) ($data['image'] ?? $draft['featured_media_url']);
		if (! empty($data['video'])) {
			array_unshift($draft['inline_media_urls'], (string) $data['video']);
		}
		$draft['inline_media_urls'] = EPV2_Media::normalize_media_list($draft['inline_media_urls']);
		return self::save_draft($draft);
	}

	public static function rewrite_field(array $draft, string $field, string $style = 'strict'): array {
		$draft = self::normalize_draft($draft);
		$config = EPV2_Settings::get_ai_config();
		if (empty($config['api_key'])) {
			throw new RuntimeException('AI API ключ не настроен.');
		}

		$field = in_array($field, ['title', 'excerpt', 'content'], true) ? $field : 'content';
		$prompt = (string) (EPV2_Settings::get('prompts', [])['manual_rewrite'] ?: EPV2_Settings::get('prompts', [])['news_default'] ?? '');
		$instruction = match ($field) {
			'title' => 'Перепиши только заголовок. Он должен быть законченным, сильным, журналистским и без кликбейта.',
			'excerpt' => 'Перепиши только лид/дек. Он должен коротко объяснять суть, значимость и пользу для читателя.',
			default => 'Перепиши только основной текст статьи. Улучши структуру, ясность, полезность и редакционное качество.',
		};

		$result = self::generate_with_fallback($config, self::messages_for_field($draft, $field, $instruction, $prompt, $style));

		$data = json_decode(self::clean_json((string) ($result['text'] ?? '')), true);
		$value = is_array($data) ? trim((string) ($data['value'] ?? '')) : '';
		if ($value === '') {
			throw new RuntimeException('AI не вернул новое значение поля.');
		}

		if ($field === 'content') {
			$draft[$field] = wp_kses_post($value);
		} elseif ($field === 'excerpt') {
			$draft[$field] = sanitize_textarea_field($value);
		} else {
			$draft[$field] = sanitize_text_field($value);
		}

		return self::save_draft($draft);
	}

	public static function optimize_seo(array $draft): array {
		$draft = self::normalize_draft($draft);
		$config = EPV2_Settings::get_ai_config();
		if (empty($config['api_key'])) {
			throw new RuntimeException('AI API ключ не настроен.');
		}
		$prompt = (string) (EPV2_Settings::get('prompts', [])['seo_refine'] ?: EPV2_Settings::get('prompts', [])['news_default'] ?? '');
		$result = self::generate_with_fallback($config, [
			[
				'role' => 'system',
				'content' => 'Ты SEO-редактор EuroPulse. Верни только JSON вида {"seo_title":"","meta_description":"","slug":"","focus_keywords":["",""]}. ' . $prompt,
			],
			[
				'role' => 'user',
				'content' => wp_json_encode([
					'title' => $draft['title'],
					'excerpt' => $draft['excerpt'],
					'content' => wp_strip_all_tags($draft['content']),
					'categories' => $draft['categories'],
					'source_url' => $draft['source_url'],
				], JSON_UNESCAPED_UNICODE),
			],
		]);

		$data = json_decode(self::clean_json((string) ($result['text'] ?? '')), true);
		if (! is_array($data)) {
			throw new RuntimeException('AI не вернул SEO-структуру.');
		}

		$draft['seo'] = [
			'seo_title' => sanitize_text_field((string) ($data['seo_title'] ?? $draft['seo']['seo_title'])),
			'meta_description' => sanitize_textarea_field((string) ($data['meta_description'] ?? $draft['seo']['meta_description'])),
			'slug' => sanitize_title((string) ($data['slug'] ?? $draft['seo']['slug'])),
			'focus_keywords' => self::normalize_keywords($data['focus_keywords'] ?? $draft['seo']['focus_keywords']),
		];

		return self::save_draft($draft);
	}

	public static function create_review_item(array $draft): int {
		$draft = self::save_draft($draft);
		$item_id = EPV2_Queue::add_item([
			'url' => $draft['source_url'],
			'title' => $draft['title'],
			'content' => $draft['content'],
			'excerpt' => $draft['excerpt'],
			'image' => $draft['featured_media_url'],
			'category' => implode(',', $draft['categories']),
		]);

		$item = EPV2_Queue::get_item($item_id);
		if (! $item) {
			throw new RuntimeException('Не удалось создать элемент очереди.');
		}

		$payload = EPV2_AI_Processor::generate_review_payload($item, $draft['categories'], $draft['style']);
		$payload['media_url'] = $draft['featured_media_url'];
		$payload['featured_media_url'] = $draft['featured_media_url'];
		$payload['inline_media_urls'] = $draft['inline_media_urls'];
		$payload['seo'] = $draft['seo'];
		$payload['_meta']['style'] = $draft['style'];
		$payload['_meta']['breaking'] = ! empty($draft['editorial']['breaking']);
		$payload['_meta']['top_story'] = ! empty($draft['editorial']['top_story']);
		$payload['_meta']['breaking_hours'] = max(1, min(24, (int) ($draft['editorial']['breaking_hours'] ?? EPV2_Settings::get('auto_breaking_hours', 6))));

		foreach (EPV2_Settings::get('publish_languages', ['de', 'uk', 'en']) as $lang) {
			$payload['languages'][$lang] = array_merge(
				$payload['languages'][$lang] ?? EPV2_Review::build_language_package($item, $lang, $draft['style']),
				[
					'media_url' => (string) ($payload['languages'][$lang]['media_url'] ?? $draft['featured_media_url']),
				]
			);
			if ($lang === 'de' && $draft['seo']['seo_title'] !== '') {
				$payload['languages'][$lang]['seo_title'] = $draft['seo']['seo_title'];
				$payload['languages'][$lang]['meta_description'] = $draft['seo']['meta_description'];
				$payload['languages'][$lang]['slug'] = $draft['seo']['slug'];
				$payload['languages'][$lang]['focus_keywords'] = $draft['seo']['focus_keywords'];
			}
		}

		EPV2_Review::save_payload($item_id, $payload);
		EPV2_Queue::mark_state($item_id, 'ready_review', [
			'category_final' => implode(',', $draft['categories']),
			'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
			'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
			'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
		]);

		return $item_id;
	}

	public static function normalize_draft(array $draft): array {
		$defaults = [
			'source_url' => '',
			'title' => '',
			'excerpt' => '',
			'content' => '',
			'categories' => ['deutschland'],
			'style' => (string) EPV2_Settings::get('rewrite_style', 'strict'),
			'featured_media_url' => '',
			'inline_media_urls' => [],
			'seo' => [
				'seo_title' => '',
				'meta_description' => '',
				'slug' => '',
				'focus_keywords' => [],
			],
			'editorial' => [
				'breaking' => false,
				'top_story' => false,
				'breaking_hours' => (int) EPV2_Settings::get('auto_breaking_hours', 6),
			],
		];
		$draft = wp_parse_args($draft, $defaults);
		$draft['source_url'] = esc_url_raw((string) $draft['source_url']);
		$draft['title'] = sanitize_text_field((string) $draft['title']);
		$draft['excerpt'] = sanitize_textarea_field((string) $draft['excerpt']);
		$draft['content'] = wp_kses_post((string) $draft['content']);
		$draft['categories'] = EPV2_Review::normalize_categories(implode(',', is_array($draft['categories']) ? $draft['categories'] : explode(',', (string) $draft['categories'])));
		if (empty($draft['categories'])) {
			$draft['categories'] = ['deutschland'];
		}
		$draft['style'] = in_array((string) $draft['style'], ['strict', 'analytic', 'lively'], true) ? (string) $draft['style'] : (string) EPV2_Settings::get('rewrite_style', 'strict');
		$draft['featured_media_url'] = esc_url_raw((string) $draft['featured_media_url']);
		$draft['inline_media_urls'] = EPV2_Media::normalize_media_list($draft['inline_media_urls']);
		$draft['seo'] = wp_parse_args(is_array($draft['seo']) ? $draft['seo'] : [], $defaults['seo']);
		$draft['seo']['seo_title'] = sanitize_text_field((string) $draft['seo']['seo_title']);
		$draft['seo']['meta_description'] = sanitize_textarea_field((string) $draft['seo']['meta_description']);
		$draft['seo']['slug'] = sanitize_title((string) $draft['seo']['slug']);
		$draft['seo']['focus_keywords'] = self::normalize_keywords($draft['seo']['focus_keywords']);
		$draft['editorial'] = wp_parse_args(is_array($draft['editorial']) ? $draft['editorial'] : [], $defaults['editorial']);
		$draft['editorial']['breaking'] = ! empty($draft['editorial']['breaking']);
		$draft['editorial']['top_story'] = ! empty($draft['editorial']['top_story']);
		$draft['editorial']['breaking_hours'] = max(1, min(24, (int) $draft['editorial']['breaking_hours']));
		return $draft;
	}

	private static function normalize_keywords($keywords): array {
		if (! is_array($keywords)) {
			$keywords = preg_split('/[,\\n]+/u', (string) $keywords) ?: [];
		}
		$keywords = array_values(array_filter(array_map(static fn($value): string => sanitize_text_field(trim((string) $value)), $keywords)));
		return array_slice(array_values(array_unique($keywords)), 0, 7);
	}

	private static function clean_json(string $text): string {
		$text = trim($text);
		if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches)) {
			$text = trim((string) $matches[1]);
		}
		return $text;
	}

	private static function messages_for_field(array $draft, string $field, string $instruction, string $prompt, string $style): array {
		return [
			[
				'role' => 'system',
				'content' => 'Ты редактор EuroPulse. Верни только JSON вида {"value":""}. Пиши как современное сильное медиа: живо, ясно, без канцелярита, без пресс-релизного тона и без механического нейростиля. ' . $prompt,
			],
			[
				'role' => 'user',
				'content' => wp_json_encode([
					'style' => $style,
					'field' => $field,
					'instruction' => $instruction,
					'source_url' => $draft['source_url'],
					'categories' => $draft['categories'],
					'title' => $draft['title'],
					'excerpt' => $draft['excerpt'],
					'content' => wp_strip_all_tags($draft['content']),
				], JSON_UNESCAPED_UNICODE),
			],
		];
	}

	private static function generate_with_fallback(array $config, array $messages): array {
		try {
			return EPV2_AI_Client::generate($config, $messages);
		} catch (Throwable $e) {
			$fallback = [
				'provider' => (string) ($config['fallback_provider'] ?? ''),
				'model' => (string) ($config['fallback_model'] ?? ''),
				'api_key' => (string) ($config['fallback_api_key'] ?? ''),
				'temperature' => (float) ($config['temperature'] ?? 0.4),
				'max_tokens' => (int) ($config['max_tokens'] ?? 3000),
				'gemini_search_grounding_enabled' => false,
				'gemini_url_context_enabled' => false,
				'gemini_use_source_url_in_prompt' => false,
				'gemini_require_citations' => false,
			];
			if ($fallback['provider'] === '' || $fallback['api_key'] === '' || $fallback['provider'] === ($config['provider'] ?? '')) {
				throw $e;
			}
			return EPV2_AI_Client::generate($fallback, $messages);
		}
	}
}
