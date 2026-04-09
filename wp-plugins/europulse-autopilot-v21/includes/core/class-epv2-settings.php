<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Settings {
	private const OPTION_KEY = 'epv2_settings';

	public static function ai_provider_options(): array {
		return [
			'gemini' => 'Google Gemini',
			'openai' => 'OpenAI',
			'deepseek' => 'DeepSeek',
			'anthropic' => 'Anthropic Claude',
		];
	}

	public static function ai_model_options(): array {
		return [
			'gemini' => [
				'gemini-auto-free' => 'Авто (доступная free-модель)',
				'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite',
				'gemini-2.5-flash' => 'Gemini 2.5 Flash',
				'gemini-2.5-pro' => 'Gemini 2.5 Pro',
				'gemini-2.0-flash' => 'Gemini 2.0 Flash',
				'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash-Lite',
			],
			'openai' => [
				'gpt-5.4' => 'GPT-5.4',
				'gpt-5.2' => 'GPT-5.2',
				'gpt-5.1' => 'GPT-5.1',
				'gpt-5' => 'GPT-5',
				'gpt-5-mini' => 'GPT-5 Mini',
				'gpt-5-nano' => 'GPT-5 Nano',
				'gpt-4o' => 'GPT-4o',
				'gpt-4o-mini' => 'GPT-4o Mini',
				'gpt-4.1-mini' => 'GPT-4.1 Mini',
				'gpt-4.1' => 'GPT-4.1',
			],
			'deepseek' => [
				'deepseek-chat' => 'DeepSeek Chat',
				'deepseek-reasoner' => 'DeepSeek Reasoner',
			],
			'anthropic' => [
				'claude-opus-4-6' => 'Claude Opus 4.6',
				'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
				'claude-haiku-4-5' => 'Claude Haiku 4.5',
				'claude-opus-4-0' => 'Claude Opus 4',
				'claude-sonnet-4-0' => 'Claude Sonnet 4',
				'claude-3-7-sonnet-latest' => 'Claude 3.7 Sonnet',
				'claude-3-5-sonnet-latest' => 'Claude 3.5 Sonnet',
				'claude-3-5-haiku-latest' => 'Claude 3.5 Haiku',
			],
		];
	}

	public static function defaults(): array {
		return [
			'mode' => 'semi',
			'default_post_status' => 'draft',
			'primary_language' => 'de',
			'publish_languages' => ['de', 'uk', 'en'],
			'enable_polylang' => true,
			'collect_interval_minutes' => 30,
			'process_interval_minutes' => 5,
			'publish_interval_minutes' => 5,
			'orchestrator_v2_enabled' => false,
			'max_queue_batch' => 1,
			'max_collect_per_category' => 4,
			'ai_budget_mode' => 'normal',
			'ai_selection_strictness' => 'medium',
			'ai_daily_request_soft_limit' => 18,
			'ai_daily_token_soft_limit' => 180000,
			'daily_publish_target' => 24,
			'enforce_daily_publish_target' => true,
			'daily_category_publish_targets' => [
				'politik' => 5,
				'ukraine' => 5,
				'deutschland' => 5,
				'wirtschaft' => 4,
				'sport' => 3,
				'europa' => 2,
				'welt' => 2,
				'kultur' => 2,
				'leben_in_deutschland' => 1,
				'community' => 1,
			],
			'queue_retention_days' => 3,
			'queue_new_ttl_hours' => 12,
			'queue_new_max_per_category' => 8,
			'queue_new_max_per_source' => 6,
			'trend_signal_enabled' => true,
			'trend_regions' => ['DE', 'FR', 'IT', 'ES', 'PL', 'NL'],
			'trend_min_hits' => 2,
			'trend_history_days' => 7,
			'job_lock_ttl_seconds' => 900,
			'provider_circuit_failures' => 3,
			'provider_circuit_cooldown_minutes' => 30,
			'source_cooldown_failures' => 3,
			'source_cooldown_minutes' => 60,
			'max_retry_attempts' => 3,
			'worker_mode' => 'disabled',
			'worker_python_bin' => 'python3',
			'worker_cli_command' => '/root/projects/europulse/worker-v21/.venv/bin/python -m epv2_worker',
			'worker_src_dir' => '/root/projects/europulse/worker-v21/src',
			'worker_timeout_seconds' => 180,
			'worker_shared_secret' => '',
			'category_plans' => EPV2_Category_Planner::defaults(),
			'time_schedule_profile' => EPV2_Time_Planner::defaults(),
			'dedup_threshold' => 0.82,
			'ai_provider' => 'gemini',
			'ai_model' => 'gemini-2.5-flash-lite',
			'ai_fallback_provider' => 'deepseek',
			'ai_fallback_model' => 'deepseek-chat',
			'rewrite_style' => 'lively',
			'ai_temperature' => 0.65,
			'ai_max_tokens' => 3000,
			'gemini_search_grounding_enabled' => true,
			'gemini_url_context_enabled' => true,
			'gemini_use_source_url_in_prompt' => true,
			'gemini_require_citations' => false,
			'ai_keys' => [
				'openai' => '',
				'anthropic' => '',
				'gemini' => '',
				'deepseek' => '',
			],
			'image_provider' => 'pexels',
			'image_provider_unsplash_enabled' => false,
			'image_keys' => [
				'pexels' => '',
				'unsplash' => '',
			],
			'show_ai_disclaimer' => true,
			'ai_disclaimer_text_de' => 'Dieser Artikel wurde mit Unterstützung von KI erstellt und redaktionell überprüft.',
			'ai_disclaimer_text_uk' => 'Ця стаття створена за допомогою ШІ та пройшла редакційну перевірку.',
			'ai_disclaimer_text_en' => 'This article was created with AI assistance and editorially reviewed.',
			'source_block_enabled' => true,
			'sponsor_block_enabled' => true,
			'auto_breaking_hours' => 6,
			'prompts' => [
				'auto_rewrite' => "Сгенерируй качественный новостной материал на основе исходных данных и дополнительных подтвержденных источников.\n\nТребования:\n- только подтвержденный фактаж;\n- без выдумок;\n- без плагиата;\n- глубокий рерайт с высокой уникальностью;\n- понятный, живой, редакционный стиль;\n- новостной тон по умолчанию должен быть обычным информационным, а не тяжёлой аналитикой;\n- заголовок должен быть информативным, а не просто тематическим: субъект + действие + главный поворот или последствие;\n- заголовок обычно 6-14 слов, без кликбейта и без пустых общих формул;\n- лид должен состоять из 2 предложений, обычно 25-55 слов суммарно, и сразу отвечать: что произошло и почему это важно;\n- статья должна начинаться с проблемы, изменения или главного последствия для читателя;\n- body нужно строить по смысловому приоритету: главный факт, подтверждение, детали, последствия, контекст, что дальше;\n- не пиши хронологию ради хронологии;\n- предложения должны быть в основном короткими или средними, без тяжёлых длинных конструкций;\n- язык должен быть простым, доступным, ясным и человеческим;\n- обычную новость не раздувай: если фактуры мало, лучше плотная короткая новость, чем пустой длинный текст;\n- короткая новость обычно 300-450 слов, стандартная 450-800, developing story 800-1200;\n- SEO-оптимизация без спама;\n- H1, SEO title, meta description, slug, excerpt, основной текст, 3-7 ключей;\n- если в фактах есть сомнения, честно укажи это;\n- если тема важна для жизни в Германии / Украине / ЕС, обязательно раскрой практическую ценность;\n- не разбивай текст на служебные блоки вроде «Почему это важно», «Контекст», «Расширенный контекст», «Что дальше»;\n- текст должен течь как нормальная статья сильного медиа, а не как шаблонный отчёт;\n- избегай канцеляризмов, официоза и длинных официальных названий законов с номерами, если смысл можно передать человеческим языком.\n\nПиши как сильное европейское цифровое медиа уровня Deutsche Welle, Tagesschau, BBC, AP или Reuters: профессионально, ясно, современно, живо, без воды и без дешевого кликбейта.",
				'analysis_rewrite' => "Подготовь расширенный аналитический материал по развивающейся теме. Обязательно собери и сопоставь не менее 4-5 подтвержденных источников или подтвержденных сигналов по сюжету. Построй материал как настоящую аналитику: что произошло, как тема развивалась, какие есть подтвержденные позиции и цифры, что это значит, какие могут быть последствия дальше. Аналитика должна иметь сильный информативный заголовок, лид из 2 предложений и цельную структуру без служебных подзаголовков. Рабочая длина аналитики обычно 1400-2200 слов: не делай огрызок, но и не раздувай без новой фактуры. Не делай сухую хронику и не разбивай текст на служебные блоки вроде «Контекст», «Почему это важно» или «Что дальше». Это должен быть цельный, плавный журналистский текст уровня Deutsche Welle, BBC, Reuters или Al Jazeera. Избегай канцелярита, пресс-релизного тона и перегруженных юридических формулировок. Если данных недостаточно для полноценной аналитики, честно укажи это и не выдумывай.",
				'news_default' => 'Сделай глубокий фактологический рерайт для EuroPulse. Соблюдай newsroom-стиль, не копируй синтаксис источника, не добавляй вымышленных фактов. Заголовок должен быть коротким, точным и цепляющим без кликбейта. Лид должен состоять из двух предложений и сразу объяснять суть и проблему. Основной текст пиши простыми, понятными, короткими или средними предложениями, без канцелярита и без тяжёлой аналитической манеры.',
				'leben_in_deutschland' => '',
				'community_event' => '',
				'seo_refine' => "Ты SEO-редактор EuroPulse. Твоя задача — не переспамить статью, а сделать её максимально понятной для Google News и обычного поиска.\n\nПравила:\n- SEO title должен быть точным, новостным и естественным, обычно 50-65 символов;\n- meta description должна коротко пересказывать главный факт и причину важности, обычно 130-160 символов;\n- slug должен быть коротким, чистым и отражать главный факт;\n- focus keywords: 3-5 реальных поисковых формулировок без спама и дублей;\n- не делай кликбейт, не вставляй бренд в начало, не пиши капсом;\n- ключи должны соответствовать реальному тексту, заголовку и лиду;\n- если новость time-sensitive, в SEO title можно отражать главное развитие события, но без мусорных слов;\n- не придумывай фактов, которых нет в тексте.\n\nВерни структуру, которая помогает индексации и CTR, но выглядит как нормальный сильный newsroom SEO.",
				'manual_rewrite' => '',
			],
		];
	}

	public static function get_all(): array {
		$saved = get_option(self::OPTION_KEY, []);
		$merged = self::merge(self::defaults(), is_array($saved) ? $saved : []);
		if (empty($merged['prompts']['manual_rewrite']) && ! empty($merged['prompts']['news_default'])) {
			$merged['prompts']['manual_rewrite'] = (string) $merged['prompts']['news_default'];
		}
		if (! empty($merged['prompts']['manual_rewrite'])) {
			$merged['prompts']['news_default'] = (string) $merged['prompts']['manual_rewrite'];
		}
		if (empty($merged['prompts']['auto_rewrite'])) {
			$merged['prompts']['auto_rewrite'] = (string) self::defaults()['prompts']['auto_rewrite'];
		}
		if (empty($merged['prompts']['seo_refine'])) {
			$merged['prompts']['seo_refine'] = (string) self::defaults()['prompts']['seo_refine'];
		}
		return $merged;
	}

	public static function get(string $key, $default = null) {
		$all = self::get_all();
		return $all[$key] ?? $default;
	}

	public static function set_all(array $data): array {
		$clean = self::sanitize($data);
		update_option(self::OPTION_KEY, self::merge(self::defaults(), $clean), false);
		return self::get_all();
	}

	public static function sanitize(array $data): array {
		$current = self::defaults();
		$clean = $current;
		$provider_options = self::ai_provider_options();
		$model_options = self::ai_model_options();
		$clean['mode'] = in_array(($data['mode'] ?? $current['mode']), ['manual', 'semi', 'auto'], true) ? $data['mode'] : $current['mode'];
		$clean['default_post_status'] = in_array(($data['default_post_status'] ?? $current['default_post_status']), ['draft', 'pending', 'publish', 'future'], true) ? $data['default_post_status'] : $current['default_post_status'];
		$clean['primary_language'] = sanitize_text_field($data['primary_language'] ?? $current['primary_language']);
		$clean['publish_languages'] = array_values(array_filter(array_map('sanitize_text_field', is_array($data['publish_languages'] ?? null) ? $data['publish_languages'] : $current['publish_languages'])));
		$clean['enable_polylang'] = ! empty($data['enable_polylang']);
		$clean['collect_interval_minutes'] = max(5, (int) ($data['collect_interval_minutes'] ?? $current['collect_interval_minutes']));
		$clean['process_interval_minutes'] = max(5, (int) ($data['process_interval_minutes'] ?? $current['process_interval_minutes']));
		$clean['publish_interval_minutes'] = max(5, (int) ($data['publish_interval_minutes'] ?? $current['publish_interval_minutes']));
		$clean['orchestrator_v2_enabled'] = ! empty($data['orchestrator_v2_enabled']);
		$clean['max_queue_batch'] = max(1, min(25, (int) ($data['max_queue_batch'] ?? $current['max_queue_batch'])));
		$clean['max_collect_per_category'] = max(1, min(20, (int) ($data['max_collect_per_category'] ?? $current['max_collect_per_category'])));
		$clean['ai_budget_mode'] = in_array(($data['ai_budget_mode'] ?? $current['ai_budget_mode']), ['normal', 'economy', 'critical'], true) ? $data['ai_budget_mode'] : $current['ai_budget_mode'];
		$clean['ai_selection_strictness'] = in_array(($data['ai_selection_strictness'] ?? $current['ai_selection_strictness']), ['low', 'medium', 'high'], true) ? $data['ai_selection_strictness'] : $current['ai_selection_strictness'];
		$clean['ai_daily_request_soft_limit'] = max(1, min(500, (int) ($data['ai_daily_request_soft_limit'] ?? $current['ai_daily_request_soft_limit'])));
		$clean['ai_daily_token_soft_limit'] = max(1000, min(5000000, (int) ($data['ai_daily_token_soft_limit'] ?? $current['ai_daily_token_soft_limit'])));
		$clean['daily_publish_target'] = max(1, min(100, (int) ($data['daily_publish_target'] ?? $current['daily_publish_target'])));
		$clean['enforce_daily_publish_target'] = ! empty($data['enforce_daily_publish_target']);
		$clean['daily_category_publish_targets'] = self::sanitize_daily_category_targets(
			is_array($data['daily_category_publish_targets'] ?? null) ? $data['daily_category_publish_targets'] : $current['daily_category_publish_targets']
		);
		$clean['queue_retention_days'] = max(1, min(30, (int) ($data['queue_retention_days'] ?? $current['queue_retention_days'])));
		$clean['queue_new_ttl_hours'] = max(1, min(168, (int) ($data['queue_new_ttl_hours'] ?? $current['queue_new_ttl_hours'])));
		$clean['queue_new_max_per_category'] = max(1, min(50, (int) ($data['queue_new_max_per_category'] ?? $current['queue_new_max_per_category'])));
		$clean['queue_new_max_per_source'] = max(1, min(50, (int) ($data['queue_new_max_per_source'] ?? $current['queue_new_max_per_source'])));
		$clean['trend_signal_enabled'] = ! empty($data['trend_signal_enabled']);
		$clean['trend_regions'] = array_values(array_filter(array_map(static fn($v) => strtoupper(trim((string) $v)), is_array($data['trend_regions'] ?? null) ? $data['trend_regions'] : $current['trend_regions'])));
		$clean['trend_min_hits'] = max(2, min(6, (int) ($data['trend_min_hits'] ?? $current['trend_min_hits'])));
		$clean['trend_history_days'] = max(3, min(30, (int) ($data['trend_history_days'] ?? $current['trend_history_days'])));
		$clean['job_lock_ttl_seconds'] = max(60, min(3600, (int) ($data['job_lock_ttl_seconds'] ?? $current['job_lock_ttl_seconds'])));
		$clean['provider_circuit_failures'] = max(2, min(10, (int) ($data['provider_circuit_failures'] ?? $current['provider_circuit_failures'])));
		$clean['provider_circuit_cooldown_minutes'] = max(5, min(240, (int) ($data['provider_circuit_cooldown_minutes'] ?? $current['provider_circuit_cooldown_minutes'])));
		$clean['source_cooldown_failures'] = max(2, min(10, (int) ($data['source_cooldown_failures'] ?? $current['source_cooldown_failures'])));
		$clean['source_cooldown_minutes'] = max(10, min(1440, (int) ($data['source_cooldown_minutes'] ?? $current['source_cooldown_minutes'])));
		$clean['max_retry_attempts'] = max(1, min(10, (int) ($data['max_retry_attempts'] ?? $current['max_retry_attempts'])));
		$clean['worker_mode'] = in_array((string) ($data['worker_mode'] ?? $current['worker_mode']), ['disabled', 'cli'], true) ? (string) ($data['worker_mode'] ?? $current['worker_mode']) : $current['worker_mode'];
		$clean['worker_python_bin'] = sanitize_text_field($data['worker_python_bin'] ?? $current['worker_python_bin']);
		$clean['worker_cli_command'] = sanitize_text_field($data['worker_cli_command'] ?? $current['worker_cli_command']);
		$clean['worker_src_dir'] = sanitize_text_field($data['worker_src_dir'] ?? $current['worker_src_dir']);
		$clean['worker_timeout_seconds'] = max(10, min(600, (int) ($data['worker_timeout_seconds'] ?? $current['worker_timeout_seconds'])));
		$clean['worker_shared_secret'] = sanitize_text_field($data['worker_shared_secret'] ?? $current['worker_shared_secret']);
		$clean['category_plans'] = is_array($data['category_plans'] ?? null) ? $data['category_plans'] : $current['category_plans'];
		$clean['time_schedule_profile'] = is_array($data['time_schedule_profile'] ?? null) ? $data['time_schedule_profile'] : $current['time_schedule_profile'];
		$clean['dedup_threshold'] = min(0.99, max(0.5, (float) ($data['dedup_threshold'] ?? $current['dedup_threshold'])));
		$clean['ai_provider'] = array_key_exists((string) ($data['ai_provider'] ?? ''), $provider_options) ? (string) $data['ai_provider'] : $current['ai_provider'];
		$clean['ai_model'] = array_key_exists((string) ($data['ai_model'] ?? ''), $model_options[$clean['ai_provider']] ?? []) ? (string) $data['ai_model'] : array_key_first($model_options[$clean['ai_provider']] ?? [$current['ai_model'] => $current['ai_model']]);
		$clean['ai_fallback_provider'] = array_key_exists((string) ($data['ai_fallback_provider'] ?? ''), $provider_options) ? (string) $data['ai_fallback_provider'] : $current['ai_fallback_provider'];
		$clean['ai_fallback_model'] = array_key_exists((string) ($data['ai_fallback_model'] ?? ''), $model_options[$clean['ai_fallback_provider']] ?? []) ? (string) $data['ai_fallback_model'] : array_key_first($model_options[$clean['ai_fallback_provider']] ?? [$current['ai_fallback_model'] => $current['ai_fallback_model']]);
		$clean['rewrite_style'] = in_array(($data['rewrite_style'] ?? $current['rewrite_style']), ['strict', 'analytic', 'lively'], true) ? $data['rewrite_style'] : $current['rewrite_style'];
		$clean['ai_temperature'] = min(1, max(0, (float) ($data['ai_temperature'] ?? $current['ai_temperature'])));
		$clean['ai_max_tokens'] = max(256, min(12000, (int) ($data['ai_max_tokens'] ?? $current['ai_max_tokens'])));
		$clean['gemini_search_grounding_enabled'] = ! empty($data['gemini_search_grounding_enabled']);
		$clean['gemini_url_context_enabled'] = ! empty($data['gemini_url_context_enabled']);
		$clean['gemini_use_source_url_in_prompt'] = ! empty($data['gemini_use_source_url_in_prompt']);
		$clean['gemini_require_citations'] = ! empty($data['gemini_require_citations']);
		$clean['image_provider'] = sanitize_text_field($data['image_provider'] ?? $current['image_provider']);
		$clean['image_provider_unsplash_enabled'] = ! empty($data['image_provider_unsplash_enabled']);
		$clean['show_ai_disclaimer'] = ! empty($data['show_ai_disclaimer']);
		$clean['source_block_enabled'] = ! empty($data['source_block_enabled']);
		$clean['sponsor_block_enabled'] = ! empty($data['sponsor_block_enabled']);
		$clean['auto_breaking_hours'] = max(0, min(48, (int) ($data['auto_breaking_hours'] ?? $current['auto_breaking_hours'])));

		foreach (['openai', 'anthropic', 'gemini', 'deepseek'] as $provider) {
			$clean['ai_keys'][$provider] = sanitize_text_field($data['ai_keys'][$provider] ?? $current['ai_keys'][$provider]);
		}

		foreach (['pexels', 'unsplash'] as $provider) {
			$clean['image_keys'][$provider] = sanitize_text_field($data['image_keys'][$provider] ?? $current['image_keys'][$provider]);
		}

		foreach (['de', 'uk', 'en'] as $lang) {
			$clean['ai_disclaimer_text_' . $lang] = wp_kses_post($data['ai_disclaimer_text_' . $lang] ?? $current['ai_disclaimer_text_' . $lang]);
		}

		foreach (array_keys($current['prompts']) as $prompt_key) {
			$clean['prompts'][$prompt_key] = wp_kses_post($data['prompts'][$prompt_key] ?? $current['prompts'][$prompt_key]);
		}
		if (! empty($clean['prompts']['manual_rewrite'])) {
			$clean['prompts']['news_default'] = (string) $clean['prompts']['manual_rewrite'];
		}

		return $clean;
	}

	public static function get_ai_config(): array {
		$settings = self::get_all();
		$fallback_provider = (string) ($settings['ai_fallback_provider'] ?? '');
		$fallback_model = (string) ($settings['ai_fallback_model'] ?? '');
		$fallback_api_key = (string) ($settings['ai_keys'][$fallback_provider] ?? '');
		return [
			'provider' => $settings['ai_provider'],
			'model' => $settings['ai_model'],
			'fallback_provider' => $fallback_provider,
			'fallback_model' => $fallback_model,
			'temperature' => $settings['ai_temperature'],
			'max_tokens' => $settings['ai_max_tokens'],
			'api_key' => $settings['ai_keys'][$settings['ai_provider']] ?? '',
			'fallback_api_key' => $fallback_api_key,
			'allow_cross_vendor_fallback' => $fallback_provider !== '' && $fallback_model !== '' && $fallback_api_key !== '' && $fallback_provider !== (string) ($settings['ai_provider'] ?? ''),
			'gemini_search_grounding_enabled' => ! empty($settings['gemini_search_grounding_enabled']),
			'gemini_url_context_enabled' => ! empty($settings['gemini_url_context_enabled']),
			'gemini_use_source_url_in_prompt' => ! empty($settings['gemini_use_source_url_in_prompt']),
			'gemini_require_citations' => ! empty($settings['gemini_require_citations']),
		];
	}

	public static function worker_shared_secret(): string {
		$all = self::get_all();
		$current = trim((string) ($all['worker_shared_secret'] ?? ''));
		if ($current !== '') {
			return $current;
		}
		$current = wp_generate_password(64, false, false);
		$all['worker_shared_secret'] = $current;
		update_option(self::OPTION_KEY, $all, false);
		return $current;
	}

	private static function merge(array $defaults, array $saved): array {
		foreach ($saved as $key => $value) {
			if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
				$defaults[$key] = self::merge($defaults[$key], $value);
			} else {
				$defaults[$key] = $value;
			}
		}

		return $defaults;
	}

	private static function sanitize_daily_category_targets(array $targets): array {
		$clean = [];
		foreach ($targets as $category => $limit) {
			$key = sanitize_key((string) $category);
			if ($key === '') {
				continue;
			}
			$clean[$key] = max(0, min(30, (int) $limit));
		}
		return $clean;
	}
}
