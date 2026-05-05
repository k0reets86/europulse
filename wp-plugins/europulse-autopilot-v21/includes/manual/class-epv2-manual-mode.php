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
		$detected_lang = self::detect_import_lang($data);
		if ($detected_lang === 'de') {
			$draft['title'] = (string) ($data['title'] ?? $draft['title']);
			$draft['excerpt'] = (string) ($data['excerpt'] ?? $draft['excerpt']);
			$draft['content'] = (string) ($data['content'] ?? $draft['content']);
			// normalize_draft() will sync these to languages['de'] automatically
		} else {
			// UK or EN source: populate only the detected language block.
			// Clear top-level and DE so normalize_draft() does not override other blocks.
			$draft['title'] = '';
			$draft['excerpt'] = '';
			$draft['content'] = '';
			$draft['languages']['de'] = ['title' => '', 'excerpt' => '', 'content' => ''];
			$draft['languages'][$detected_lang] = [
				'title' => (string) ($data['title'] ?? ''),
				'excerpt' => (string) ($data['excerpt'] ?? ''),
				'content' => (string) ($data['content'] ?? ''),
			];
		}
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

	public static function rewrite_language_field(array $draft, string $lang, string $field, string $style = 'strict'): array {
		$draft = self::normalize_draft($draft);
		$config = EPV2_Settings::get_ai_config();
		if (empty($config['api_key'])) {
			throw new RuntimeException('AI API ключ не настроен.');
		}

		$lang = in_array($lang, ['de', 'uk', 'en'], true) ? $lang : 'de';
		$field = in_array($field, ['title', 'excerpt', 'content'], true) ? $field : 'content';
		$prompt = (string) (EPV2_Settings::get('prompts', [])['manual_rewrite'] ?: EPV2_Settings::get('prompts', [])['news_default'] ?? '');
		$instruction = match ($field) {
			'title' => 'Перепиши только заголовок. Он должен быть законченным, сильным, журналистским и без кликбейта.',
			'excerpt' => 'Перепиши только лид/дек. Он должен коротко объяснять суть, значимость и пользу для читателя.',
			default => 'Перепиши только основной текст статьи. Улучши структуру, ясность, полезность и редакционное качество.',
		};

		$result = self::generate_with_fallback($config, self::messages_for_language_field($draft, $lang, $field, $instruction, $prompt, $style));
		$data = json_decode(self::clean_json((string) ($result['text'] ?? '')), true);
		$value = is_array($data) ? trim((string) ($data['value'] ?? '')) : '';
		if ($value === '') {
			throw new RuntimeException('AI не вернул новое значение поля.');
		}

		if ($field === 'content') {
			$draft['languages'][$lang][$field] = wp_kses_post($value);
		} elseif ($field === 'excerpt') {
			$draft['languages'][$lang][$field] = sanitize_textarea_field($value);
		} else {
			$draft['languages'][$lang][$field] = sanitize_text_field($value);
		}

		if ($lang === 'de') {
			$draft['title'] = (string) $draft['languages']['de']['title'];
			$draft['excerpt'] = (string) $draft['languages']['de']['excerpt'];
			$draft['content'] = (string) $draft['languages']['de']['content'];
		}

		return self::save_draft($draft);
	}

	public static function optimize_seo(array $draft): array {
		$draft = self::normalize_draft($draft);
		$config = EPV2_Settings::get_ai_config();
		if (empty($config['api_key'])) {
			throw new RuntimeException('AI API ключ не настроен.');
		}
		[$sourceLang, $sourceBlock] = self::best_seo_source_block($draft);
		$prompt = (string) (EPV2_Settings::get('prompts', [])['seo_refine'] ?: EPV2_Settings::get('prompts', [])['news_default'] ?? '');
		$result = self::generate_with_fallback($config, [
			[
				'role' => 'system',
				'content' => 'Ты SEO-редактор EuroPulse. Верни только JSON вида {"seo_title":"","meta_description":"","slug":"","focus_keywords":["",""]}. ' . $prompt,
			],
			[
				'role' => 'user',
				'content' => wp_json_encode([
					'title' => $sourceBlock['title'],
					'excerpt' => $sourceBlock['excerpt'],
					'content' => wp_strip_all_tags($sourceBlock['content']),
					'source_language' => $sourceLang,
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
		$draft = self::finalize_submission_draft($draft);
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

		$dossier = self::build_manual_dossier($item, $draft);
		$payload = EPV2_Review::build_payload_without_ai_from_dossier($item, $draft['categories'], $draft['style'], $dossier, true);
		$payload['media_url'] = $draft['featured_media_url'];
		$payload['featured_media_url'] = $draft['featured_media_url'];
		$payload['inline_media_urls'] = $draft['inline_media_urls'];
		$payload['seo'] = $draft['seo'];
		$payload['_meta']['style'] = $draft['style'];
		$payload['_meta']['manual_mode'] = true;
		$payload['_meta']['media_caption'] = (string) ($draft['media_caption'] ?? '');
		$payload['_meta']['media_credit'] = (string) ($draft['media_credit'] ?? '');
		$payload['_meta']['breaking'] = ! empty($draft['editorial']['breaking']);
		$payload['_meta']['top_story'] = ! empty($draft['editorial']['top_story']);
		$payload['_meta']['breaking_hours'] = max(1, min(24, (int) ($draft['editorial']['breaking_hours'] ?? EPV2_Settings::get('auto_breaking_hours', 6))));
		$payload['_meta']['translations_deferred'] = true;

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

		$payload = EPV2_AI_Processor::force_payload_pipeline_stage($payload, 'translate_uk');
		EPV2_Review::save_payload($item_id, $payload);
		EPV2_Queue::mark_state($item_id, 'new', [
			'category_final' => implode(',', $draft['categories']),
			'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
			'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
			'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
			'error_message' => '',
		]);

		return $item_id;
	}

	public static function generate_de_master_draft(array $draft): array {
		return self::save_draft(self::prepare_de_master_draft($draft, true));
	}

	private static function finalize_submission_draft(array $draft): array {
		$draft = self::normalize_draft($draft);
		[$sourceLang] = self::pick_source_language_block($draft);
		$hasAi = self::ai_available();
		$deNeedsBuild =
			! self::language_block_has_content($draft['languages']['de'] ?? [])
			|| self::de_master_looks_incomplete($draft['languages']['de'] ?? []);

		if ($deNeedsBuild || ($hasAi && $sourceLang !== '' && $sourceLang !== 'de')) {
			$draft = self::prepare_de_master_draft($draft, $hasAi);
		} else {
			$draft = self::prepare_de_master_draft($draft, false);
		}

		if ($hasAi && self::seo_looks_incomplete($draft['seo'] ?? [])) {
			$draft = self::optimize_seo($draft);
		}

		return self::normalize_draft($draft);
	}

	private static function build_manual_dossier(object $item, array $draft): array {
		$source_url = (string) ($draft['source_url'] ?: ($item->original_url ?? ''));
		$source_name = wp_parse_url($source_url, PHP_URL_HOST);
		$source_name = is_string($source_name) && $source_name !== '' ? $source_name : 'manual';

		return [
			'primary' => [
				'url' => $source_url,
				'title' => (string) ($draft['title'] ?? $item->original_title ?? ''),
				'excerpt' => (string) ($draft['excerpt'] ?? $item->original_excerpt ?? ''),
				'content' => (string) ($draft['content'] ?? $item->original_content ?? ''),
				'image' => (string) ($draft['featured_media_url'] ?? $item->source_image_url ?? ''),
				'source_name' => $source_name,
			],
			'supporting' => [],
			'context_memory' => [],
			'story_context' => [],
		];
	}

	public static function normalize_draft(array $draft): array {
		$defaults = [
			'source_url' => '',
			'title' => '',
			'excerpt' => '',
			'content' => '',
			'languages' => [
				'de' => ['title' => '', 'excerpt' => '', 'content' => ''],
				'uk' => ['title' => '', 'excerpt' => '', 'content' => ''],
				'en' => ['title' => '', 'excerpt' => '', 'content' => ''],
			],
			'categories' => ['deutschland'],
			'style' => (string) EPV2_Settings::get('rewrite_style', 'strict'),
			'featured_media_url' => '',
			'media_caption' => '',
			'media_credit' => '',
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
		$draft['languages'] = is_array($draft['languages'] ?? null) ? $draft['languages'] : [];
		foreach (['de', 'uk', 'en'] as $lang) {
			$langBlock = is_array($draft['languages'][$lang] ?? null) ? $draft['languages'][$lang] : [];
			$draft['languages'][$lang] = [
				'title' => sanitize_text_field((string) ($langBlock['title'] ?? '')),
				'excerpt' => sanitize_textarea_field((string) ($langBlock['excerpt'] ?? '')),
				'content' => wp_kses_post((string) ($langBlock['content'] ?? '')),
			];
		}
		if ($draft['title'] !== '' || $draft['excerpt'] !== '' || $draft['content'] !== '') {
			$draft['languages']['de'] = [
				'title' => $draft['title'],
				'excerpt' => $draft['excerpt'],
				'content' => $draft['content'],
			];
		}
		if ($draft['languages']['de']['title'] !== '' || $draft['languages']['de']['excerpt'] !== '' || $draft['languages']['de']['content'] !== '') {
			$draft['title'] = $draft['languages']['de']['title'];
			$draft['excerpt'] = $draft['languages']['de']['excerpt'];
			$draft['content'] = $draft['languages']['de']['content'];
		}
		$draft['categories'] = EPV2_Review::normalize_categories(implode(',', is_array($draft['categories']) ? $draft['categories'] : explode(',', (string) $draft['categories'])));
		if (empty($draft['categories'])) {
			$draft['categories'] = ['deutschland'];
		}
		$draft['style'] = in_array((string) $draft['style'], ['strict', 'analytic', 'lively'], true) ? (string) $draft['style'] : (string) EPV2_Settings::get('rewrite_style', 'strict');
		$draft['featured_media_url'] = esc_url_raw((string) $draft['featured_media_url']);
		$draft['media_caption'] = sanitize_textarea_field((string) $draft['media_caption']);
		$draft['media_credit'] = sanitize_text_field((string) $draft['media_credit']);
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

	private static function prepare_de_master_draft(array $draft, bool $force_regenerate = false): array {
		$draft = self::normalize_draft($draft);
		if (! $force_regenerate && self::language_block_has_content($draft['languages']['de'] ?? [])) {
			$draft['title'] = (string) ($draft['languages']['de']['title'] ?? '');
			$draft['excerpt'] = (string) ($draft['languages']['de']['excerpt'] ?? '');
			$draft['content'] = (string) ($draft['languages']['de']['content'] ?? '');
			return $draft;
		}

		[$sourceLang, $sourceBlock] = self::pick_source_language_block($draft);
		if ($sourceLang === '' || ! self::language_block_has_content($sourceBlock)) {
			throw new RuntimeException('Для ручного режима нужен текст хотя бы в одном из блоков DE, UK или EN.');
		}

		if ($sourceLang === 'de' && ! $force_regenerate) {
			$draft['title'] = (string) ($sourceBlock['title'] ?? '');
			$draft['excerpt'] = (string) ($sourceBlock['excerpt'] ?? '');
			$draft['content'] = (string) ($sourceBlock['content'] ?? '');
			$draft['languages']['de'] = [
				'title' => $draft['title'],
				'excerpt' => $draft['excerpt'],
				'content' => $draft['content'],
			];
			return $draft;
		}

		$config = EPV2_Settings::get_ai_config();
		if (empty($config['api_key'])) {
			throw new RuntimeException('AI API ключ не настроен: нельзя собрать немецкий master из не-немецкого текста.');
		}

		$prompt = (string) (EPV2_Settings::get('prompts', [])['manual_rewrite'] ?: EPV2_Settings::get('prompts', [])['news_default'] ?? '');
		$sourceLabel = match ($sourceLang) {
			'uk' => 'украинского',
			'en' => 'английского',
			default => 'немецкого',
		};
		$result = self::generate_with_fallback($config, self::messages_for_de_master($draft, $sourceLang, $sourceLabel, $sourceBlock, $prompt, false));
		$draft['languages']['de'] = self::decode_de_master_result($result);
		if ($sourceLang !== 'de' && self::de_block_looks_wrong_language($draft['languages']['de'])) {
			$result = self::generate_with_fallback($config, self::messages_for_de_master($draft, $sourceLang, $sourceLabel, $sourceBlock, $prompt, true));
			$draft['languages']['de'] = self::decode_de_master_result($result);
		}
		if (! self::language_block_has_content($draft['languages']['de'])) {
			throw new RuntimeException('Немецкий master не был собран из исходного текста.');
		}
		if ($sourceLang !== 'de' && self::de_block_looks_wrong_language($draft['languages']['de'])) {
			throw new RuntimeException('AI не собрал DE master на немецком языке. Проверьте входной текст или повторите попытку.');
		}
		$draft['title'] = $draft['languages']['de']['title'];
		$draft['excerpt'] = $draft['languages']['de']['excerpt'];
		$draft['content'] = $draft['languages']['de']['content'];

		return $draft;
	}

	private static function detect_import_lang(array $data): string {
		$lang = strtolower(substr((string) ($data['lang'] ?? ''), 0, 2));
		if (in_array($lang, ['de', 'uk', 'en'], true)) {
			return $lang;
		}
		// Cyrillic in title or content → assume Ukrainian
		$sample = (string) ($data['title'] ?? '') . ' ' . (string) ($data['content'] ?? '');
		if (preg_match('/\p{Cyrillic}/u', $sample) === 1) {
			return 'uk';
		}
		return 'de';
	}

	private static function pick_source_language_block(array $draft): array {
		foreach (['de', 'uk', 'en'] as $lang) {
			$block = is_array($draft['languages'][$lang] ?? null) ? $draft['languages'][$lang] : [];
			if (self::language_block_has_content($block)) {
				return [$lang, $block];
			}
		}
		return ['', []];
	}

	private static function language_block_has_content(array $block): bool {
		return trim((string) ($block['title'] ?? '')) !== ''
			|| trim((string) ($block['excerpt'] ?? '')) !== ''
			|| trim(wp_strip_all_tags((string) ($block['content'] ?? ''))) !== '';
	}

	private static function normalize_keywords($keywords): array {
		if (! is_array($keywords)) {
			$keywords = preg_split('/[,\\n]+/u', (string) $keywords) ?: [];
		}
		$keywords = array_values(array_filter(array_map(static fn($value): string => sanitize_text_field(trim((string) $value)), $keywords)));
		return array_slice(array_values(array_unique($keywords)), 0, 7);
	}

	private static function ai_available(): bool {
		$config = EPV2_Settings::get_ai_config();
		return ! empty($config['api_key']);
	}

	private static function de_master_looks_incomplete(array $block): bool {
		$title = trim((string) ($block['title'] ?? ''));
		$excerpt = trim((string) ($block['excerpt'] ?? ''));
		$content = trim(wp_strip_all_tags((string) ($block['content'] ?? '')));

		return $title === '' || $excerpt === '' || $content === '';
	}

	private static function seo_looks_incomplete(array $seo): bool {
		$seoTitle = trim((string) ($seo['seo_title'] ?? ''));
		$metaDescription = trim((string) ($seo['meta_description'] ?? ''));
		$slug = trim((string) ($seo['slug'] ?? ''));
		$keywords = self::normalize_keywords($seo['focus_keywords'] ?? []);

		return $seoTitle === '' || $metaDescription === '' || $slug === '' || $keywords === [];
	}

	private static function de_block_looks_wrong_language(array $block): bool {
		$text = trim(
			(string) ($block['title'] ?? '') . ' '
			. (string) ($block['excerpt'] ?? '') . ' '
			. wp_strip_all_tags((string) ($block['content'] ?? ''))
		);
		if ($text === '') {
			return true;
		}

		return preg_match('/\p{Cyrillic}/u', $text) === 1;
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

	private static function messages_for_de_master(array $draft, string $sourceLang, string $sourceLabel, array $sourceBlock, string $prompt, bool $strictGerman): array {
		$strictInstruction = $strictGerman
			? 'Ответ должен быть только на немецком языке (de-DE). Не используй украинский, русский или английский в title, excerpt и content. Если исходник не на немецком, переведи и перепиши его в полноценный немецкий newsroom master.'
			: 'Собери сильный немецкий DE master для дальнейшей newsroom-доводки.';

		return [
			[
				'role' => 'system',
				'content' => 'Ты редактор EuroPulse. На входе текст новости на одном из языков. Верни только JSON вида {"title":"","excerpt":"","content":""}. '
					. $strictInstruction
					. ' Заголовок должен быть новостным и конкретным. Лид — 2 предложения. Основной текст — живой, ясный, без канцелярита. Не выдумывай факты. '
					. $prompt,
			],
			[
				'role' => 'user',
				'content' => wp_json_encode([
					'target_language' => 'de',
					'source_language' => $sourceLang,
					'source_language_label' => $sourceLabel,
					'categories' => $draft['categories'],
					'source_url' => $draft['source_url'],
					'title' => (string) ($sourceBlock['title'] ?? ''),
					'excerpt' => (string) ($sourceBlock['excerpt'] ?? ''),
					'content' => wp_strip_all_tags((string) ($sourceBlock['content'] ?? '')),
				], JSON_UNESCAPED_UNICODE),
			],
		];
	}

	private static function messages_for_language_field(array $draft, string $lang, string $field, string $instruction, string $prompt, string $style): array {
		$block = is_array($draft['languages'][$lang] ?? null) ? $draft['languages'][$lang] : [];
		return [
			[
				'role' => 'system',
				'content' => 'Ты редактор EuroPulse. Верни только JSON вида {"value":""}. Пиши живо, ясно, по-редакторски, без канцелярита и без пресс-релизного тона. Сохраняй язык входного поля. ' . $prompt,
			],
			[
				'role' => 'user',
				'content' => wp_json_encode([
					'style' => $style,
					'language' => $lang,
					'field' => $field,
					'instruction' => $instruction,
					'source_url' => $draft['source_url'],
					'categories' => $draft['categories'],
					'title' => (string) ($block['title'] ?? ''),
					'excerpt' => (string) ($block['excerpt'] ?? ''),
					'content' => wp_strip_all_tags((string) ($block['content'] ?? '')),
				], JSON_UNESCAPED_UNICODE),
			],
		];
	}

	private static function best_seo_source_block(array $draft): array {
		if (self::language_block_has_content($draft['languages']['de'] ?? [])) {
			return ['de', $draft['languages']['de']];
		}
		return self::pick_source_language_block($draft);
	}

	private static function decode_de_master_result(array $result): array {
		$data = json_decode(self::clean_json((string) ($result['text'] ?? '')), true);
		if (! is_array($data)) {
			throw new RuntimeException('AI не вернул структуру немецкого master.');
		}

		return [
			'title' => sanitize_text_field((string) ($data['title'] ?? '')),
			'excerpt' => sanitize_textarea_field((string) ($data['excerpt'] ?? '')),
			'content' => wp_kses_post((string) ($data['content'] ?? '')),
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
