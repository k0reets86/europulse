<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_AI_Response_Validator {
	public static function validate(array $payload): array {
		$errors = [];
		if (empty($payload['languages']['de']['title'] ?? null)) {
			$errors[] = 'Missing languages.de.title';
		}
		if (empty($payload['languages']['de']['content'] ?? null)) {
			$errors[] = 'Missing languages.de.content';
		}
		if (empty($payload['categories']) || ! is_array($payload['categories'])) {
			$errors[] = 'Missing categories';
		}
		$quality = self::editorial_quality($payload);
		$seo = self::seo_quality($payload);
		$release = self::release_quality($payload);
		$google = self::google_preflight_quality($payload);
		return [
			'valid' => empty($errors),
			'errors' => $errors,
			'quality' => $quality,
			'seo' => $seo,
			'release' => $release,
			'google' => $google,
		];
	}

	public static function enrich_payload(array $payload): array {
		$categories = is_array($payload['categories'] ?? null) ? array_values(array_filter($payload['categories'])) : [];
		$payload['tags'] = is_array($payload['tags'] ?? null) ? array_values(array_filter(array_map('sanitize_text_field', $payload['tags']))) : [];
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload['_meta']['blocked_media_urls'] = self::normalize_blocked_media_urls((array) ($payload['_meta']['blocked_media_urls'] ?? []));
		if (is_array($payload['_meta']['source_dossier'] ?? null)) {
			$payload['_meta']['source_dossier'] = self::prune_blocked_media_from_dossier(
				(array) $payload['_meta']['source_dossier'],
				(array) $payload['_meta']['blocked_media_urls']
			);
		}
		$payload['featured_media_url'] = esc_url_raw((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''));
		$payload['inline_media_urls'] = EPV2_Media::normalize_media_list($payload['inline_media_urls'] ?? []);
		if (EPV2_Media::is_technical_asset_url((string) ($payload['featured_media_url'] ?? ''))) {
			$payload['featured_media_url'] = '';
		}
		if (EPV2_Media::is_technical_asset_url((string) ($payload['media_url'] ?? ''))) {
			$payload['media_url'] = '';
		}
		if (self::media_url_is_blocked($payload, (string) ($payload['featured_media_url'] ?? ''))) {
			$payload['featured_media_url'] = '';
		}
		if (self::media_url_is_blocked($payload, (string) ($payload['media_url'] ?? ''))) {
			$payload['media_url'] = '';
		}
		foreach (['de', 'uk', 'en'] as $lang) {
			$lang_payload = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
			if ($lang_payload === []) {
				continue;
			}
			$title = trim((string) ($lang_payload['title'] ?? ''));
			$excerpt = trim((string) ($lang_payload['excerpt'] ?? ''));
			$normalized_content = self::normalize_content_html((string) ($lang_payload['content'] ?? ''));
			$normalized_content = self::sanitize_embedded_quote_cites($normalized_content);
			$normalized_content = self::strip_trailing_translation_artifacts($normalized_content, $lang);
			$content = trim(wp_strip_all_tags($normalized_content));
			$slug = self::slug_from_title((string) ($lang_payload['slug'] ?? $title), $lang);
			$seo_title = trim((string) ($lang_payload['seo_title'] ?? ''));
			$meta_description = trim((string) ($lang_payload['meta_description'] ?? ''));
			$focus_keywords = is_array($lang_payload['focus_keywords'] ?? null) ? $lang_payload['focus_keywords'] : [];
			$lang_tags = is_array($lang_payload['tags'] ?? null) ? $lang_payload['tags'] : [];

			if ($excerpt === '' && $content !== '') {
				$excerpt = self::trim_chars($content, 220);
			}

			if (($seo_title === '' || self::seo_field_looks_wrong_for_language($seo_title, $lang)) && $title !== '') {
				$seo_title = self::seo_title_from_title($title, $lang);
			}
			$meta_source = $excerpt !== '' && mb_strlen($excerpt) >= 110 ? $excerpt : $content;
			if ($meta_source === '') {
				$meta_source = $excerpt;
			}
			if (
				$meta_description === ''
				|| self::seo_field_looks_wrong_for_language($meta_description, $lang)
				|| mb_strlen($meta_description) < 110
				|| mb_strlen($meta_description) > 170
			) {
				$meta_description = self::meta_description_from_text($meta_source, $lang);
			}
			if (
				$focus_keywords === []
				|| self::focus_keywords_look_wrong_for_language($focus_keywords, $lang)
				|| ! self::focus_keywords_fit_payload($focus_keywords, $title, $excerpt, $content, $seo_title, $meta_description)
			) {
				$focus_keywords = self::derive_focus_keywords($title, $excerpt, $content, $categories);
			}
			$focus_keywords = self::align_focus_keywords($focus_keywords, $title, $excerpt, $content, $seo_title, $meta_description);
			if ($slug === '' || self::slug_looks_wrong_for_language($slug, $lang)) {
				$slug = self::slug_from_title($title, $lang);
			}
			if (! empty($focus_keywords[0])) {
				$primary = (string) $focus_keywords[0];
				if (! self::contains_keyword($meta_description, $primary)) {
					$meta_description = self::inject_keyword_into_meta($meta_description, $primary, $lang);
				}
			}

			$payload['languages'][$lang]['seo_title'] = sanitize_text_field($seo_title);
			$payload['languages'][$lang]['meta_description'] = sanitize_textarea_field($meta_description);
			$payload['languages'][$lang]['slug'] = $slug;
			$payload['languages'][$lang]['focus_keywords'] = array_values(array_slice(array_filter(array_map('sanitize_text_field', $focus_keywords)), 0, 5));
			$payload['languages'][$lang]['excerpt'] = sanitize_textarea_field($excerpt);
			$payload['languages'][$lang]['content'] = $normalized_content;
			$payload['languages'][$lang]['tags'] = array_values(array_slice(array_unique(array_filter(array_map('sanitize_text_field', array_merge($lang_tags, $payload['languages'][$lang]['focus_keywords'])))), 0, 8));
			if (self::media_url_is_blocked($payload, (string) ($payload['languages'][$lang]['media_url'] ?? ''))) {
				$payload['languages'][$lang]['media_url'] = '';
			}
		}
		$seed_for_category = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$content_detected_primary = EPV2_Categorizer::detect(
			(string) ($seed_for_category['title'] ?? ''),
			(string) ($seed_for_category['content'] ?? ''),
			implode(',', $categories)
		);
		$refined_primary = EPV2_Categorizer::refine_with_event_context(
			$content_detected_primary !== '' ? $content_detected_primary : (string) ($categories[0] ?? ''),
			(array) ($payload['_meta']['source_dossier'] ?? []),
			(string) ($seed_for_category['title'] ?? ''),
			(string) ($seed_for_category['content'] ?? '')
		);
		if ($refined_primary !== '') {
			$categories = EPV2_Review::normalize_categories($refined_primary . ',' . implode(',', $categories));
			$payload['categories'] = $categories;
		}
		$seed = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$safe_existing_media = self::media_url_is_blocked($payload, (string) ($payload['media_url'] ?? ''))
			? ''
			: (string) ($payload['media_url'] ?? '');
		$resolved_featured = EPV2_Media::resolve_featured_media(
			(string) ($seed['title'] ?? ''),
			(string) ($seed['excerpt'] ?? ''),
			$categories,
			$safe_existing_media,
			(array) ($payload['_meta']['source_dossier'] ?? [])
		);
		if (self::media_url_is_blocked($payload, $resolved_featured)) {
			$resolved_featured = '';
		}
		if (
			$payload['featured_media_url'] === ''
			|| ! EPV2_Media::can_use_featured_url($payload['featured_media_url'])
			|| self::media_url_is_blocked($payload, $payload['featured_media_url'])
			|| (EPV2_Media::is_fallback_stock_url($payload['featured_media_url']) && $resolved_featured !== '' && ! EPV2_Media::is_fallback_stock_url($resolved_featured))
		) {
			$payload['featured_media_url'] = $resolved_featured;
			$payload['media_url'] = $payload['featured_media_url'];
		}
		if ($payload['featured_media_url'] !== '') {
			foreach (['de', 'uk', 'en'] as $lang) {
				if (is_array($payload['languages'][$lang] ?? null)) {
					$payload['languages'][$lang]['media_url'] = $payload['featured_media_url'];
				}
			}
		}
		$story_format = (string) ($payload['_meta']['story_format'] ?? '');
		if (! in_array($story_format, ['analysis', 'developing'], true)) {
			$payload['inline_media_urls'] = array_values(array_filter($payload['inline_media_urls'], static function (string $url): bool {
				$type = EPV2_Media::detect_type($url);
				return in_array($type, ['video', 'embed'], true);
			}));
		}
		if ($payload['inline_media_urls'] === []) {
			$payload['inline_media_urls'] = self::auto_inline_media_urls($payload, $categories);
		}
		$payload['seo'] = [
			'seo_title' => (string) ($payload['languages']['de']['seo_title'] ?? ''),
			'meta_description' => (string) ($payload['languages']['de']['meta_description'] ?? ''),
			'slug' => (string) ($payload['languages']['de']['slug'] ?? ''),
			'focus_keywords' => (array) ($payload['languages']['de']['focus_keywords'] ?? []),
		];

		return $payload;
	}

	public static function editorial_quality(array $payload): array {
		$warnings = [];
		$score = 100;
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$source_count = (int) ($meta['source_count'] ?? 0);
		$primary_url = (string) ($meta['source_dossier']['primary']['url'] ?? '');
		$has_strong_primary = self::looks_like_official_primary($primary_url);
		$categories = is_array($payload['categories'] ?? null) ? array_values(array_filter(array_map('strval', $payload['categories']))) : [];
		$primary_category = (string) ((array) ($payload['categories'] ?? ['']))[0];
		$profile = self::story_budget_profile($payload);
		$shape = sanitize_key((string) ($profile['de']['shape'] ?? 'news'));
		$length_targets = self::release_length_targets($payload);
		$soft_targets = self::release_soft_targets($payload);
		$de_reference = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$de_title_reference = trim((string) ($de_reference['title'] ?? ''));
		$de_excerpt_reference = trim((string) ($de_reference['excerpt'] ?? ''));
		$de_content_reference = trim(wp_strip_all_tags((string) ($de_reference['content'] ?? '')));
		$category_issue = self::category_fit_issue($payload, $de_title_reference, $de_excerpt_reference, $de_content_reference);
		if ($category_issue !== '') {
			$warnings['de'][] = $category_issue;
			$score -= 20;
		}
		$supporting_issue = self::supporting_context_issue($payload, $de_title_reference, $de_excerpt_reference, $categories);
		if ($supporting_issue !== '') {
			$warnings['de'][] = $supporting_issue;
			$score -= 18;
		}
		if (
			$source_count <= 1
			&& ! $has_strong_primary
			&& ! in_array($shape, ['preview', 'service_note', 'bulletin'], true)
			&& in_array($primary_category, ['politik', 'world', 'sport', 'kultur', 'community', 'leben-in-deutschland'], true)
		) {
			$warnings['de'][] = 'слишком слабое досье источников для надёжной автопубликации';
			$score -= 12;
		}
		if (
			preg_match('/\b(merz|kanzler|regierungserklärung|regierungserklaerung|afd|europäische union|europaeische union)\b/u', $de_title_reference . ' ' . $de_excerpt_reference . ' ' . $de_content_reference) === 1
			&& $primary_category !== 'politik'
		) {
			$warnings['de'][] = 'политический материал собран в слишком общей рубрике';
			$score -= 16;
		}
		foreach (['de', 'uk', 'en'] as $lang) {
			$lang_payload = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
			$title = trim((string) ($lang_payload['title'] ?? ''));
			$excerpt = trim((string) ($lang_payload['excerpt'] ?? ''));
			$content = trim(wp_strip_all_tags((string) ($lang_payload['content'] ?? '')));
			if ($title === '' && $excerpt === '' && $content === '') {
				if ($lang === 'de') {
					$warnings[$lang] = ['отсутствует обязательный основной текст'];
					$score -= 60;
				}
				continue;
			}

			$combined = $title . "\n" . $excerpt . "\n" . $content;
			$combined_lower = mb_strtolower($combined);
			$lang_warnings = [];

			if ($lang === 'de') {
				$de_issue = self::german_language_issue($combined);
				if ($de_issue !== '') {
					$lang_warnings[] = $de_issue;
					$score -= 28;
				}
			}

			$section_patterns = [
				'/\b(почему это важно|контекст|расширенный контекст|что дальше)\s*[:\-]/iu',
				'/\b(why this matters|context|expanded context|what happens next)\s*[:\-]/iu',
				'/\b(warum das wichtig ist|kontext|erweiterter kontext|wie es weitergeht)\s*[:\-]/iu',
			];
			foreach ($section_patterns as $pattern) {
				if (preg_match($pattern, $combined)) {
					$lang_warnings[] = 'шаблонные секции внутри текста';
					$score -= 12;
					break;
				}
			}

			$bureaucratic_terms = [
				'gemäß', 'verordnung', 'paragraph', 'paragraphen', 'amtlich', 'mitteilung', 'erlass',
				'відповідно до', 'згідно з', 'положення', 'розпорядження',
				'according to section', 'pursuant to', 'ordinance', 'official notice',
			];
			$bureaucratic_hits = 0;
			foreach ($bureaucratic_terms as $term) {
				if (str_contains($combined_lower, $term)) {
					$bureaucratic_hits++;
				}
			}
			if ($bureaucratic_hits >= 2) {
				$lang_warnings[] = 'слишком бюрократический тон';
				$score -= 14;
			}

			if (preg_match('/§\s*\d+/u', $combined)) {
				$lang_warnings[] = 'перегрузка номерами параграфов';
				$score -= 10;
			}

			$paragraphs = preg_split('/\n\s*\n/u', trim((string) ($lang_payload['content'] ?? ''))) ?: [];
			if (count($paragraphs) >= 5) {
				$very_short = 0;
				foreach ($paragraphs as $paragraph) {
					$plain = trim(wp_strip_all_tags((string) $paragraph));
					if ($plain !== '' && mb_strlen($plain) < 70) {
						$very_short++;
					}
				}
				if ($very_short >= 3) {
					$lang_warnings[] = 'слишком рубленый ритм абзацев';
					$score -= 10;
				}
			}

			if (mb_strlen($excerpt) > 0 && mb_strlen($excerpt) < 80) {
				$lang_warnings[] = 'слишком слабый или короткий лид';
				$score -= 6;
			}

			$soft_min = (int) ($soft_targets[$lang] ?? max(420, ((int) ($length_targets[$lang] ?? 900)) + 220));
			if ($content !== '' && mb_strlen($content) < $soft_min) {
				$lang_warnings[] = 'текст может быть слишком коротким и поверхностным';
				$score -= 5;
			}
			if ($lang === 'de' && self::body_repeats_lead_without_depth($excerpt, $content, $soft_min)) {
				$lang_warnings[] = 'тело материала почти не добавляет новой информации сверх лида';
				$score -= 16;
			}
			if ($lang === 'de' && $source_count >= 2 && mb_strlen($content) < max(420, $soft_min - 120) && ! str_contains((string) ($lang_payload['content'] ?? ''), '<blockquote>')) {
				$lang_warnings[] = 'материал не добирает фактуру из дополнительных источников';
				$score -= 12;
			}

			$integrity_issue = self::language_integrity_issue($lang, $title, $excerpt, $content, $de_title_reference, $de_excerpt_reference, $de_content_reference);
			if ($integrity_issue !== '') {
				$lang_warnings[] = $integrity_issue;
				$score -= 28;
			}

			$has_quote = str_contains((string) ($lang_payload['content'] ?? ''), '<blockquote>');
			$quote_really_needed = in_array((string) ($payload['_meta']['story_format'] ?? ''), ['analysis', 'developing'], true);
			if (! $has_quote && mb_strlen($content) >= 1400 && $quote_really_needed) {
				$lang_warnings[] = 'для полного материала не хватает прямой речи или подтверждённой цитаты';
				$score -= 6;
			}

			if ($lang_warnings !== []) {
				$warnings[$lang] = array_values(array_unique($lang_warnings));
			}
		}

		return [
			'score' => max(0, min(100, $score)),
			'warnings' => $warnings,
			'pass' => $score >= 72,
		];
	}

	public static function seo_quality(array $payload): array {
		$warnings = [];
		$score = 100;
		foreach (['de', 'uk', 'en'] as $lang) {
			$lang_payload = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
			if ($lang_payload === []) {
				continue;
			}
			$title = trim((string) ($lang_payload['title'] ?? ''));
			$excerpt = trim((string) ($lang_payload['excerpt'] ?? ''));
			$content = mb_strtolower(trim(wp_strip_all_tags((string) ($lang_payload['content'] ?? ''))));
			$seo_title = trim((string) ($lang_payload['seo_title'] ?? ''));
			$meta_description = trim((string) ($lang_payload['meta_description'] ?? ''));
			$slug = trim((string) ($lang_payload['slug'] ?? ''));
			$focus_keywords = array_values(array_filter(array_map('strval', (array) ($lang_payload['focus_keywords'] ?? []))));
			$lang_warnings = [];

			if ($seo_title === '') {
				$lang_warnings[] = 'нет SEO title';
				$score -= 12;
			} elseif (mb_strlen($seo_title) < 35 || mb_strlen($seo_title) > 70) {
				$lang_warnings[] = 'SEO title не в оптимальной длине';
				$score -= 6;
			}
			if ($meta_description === '') {
				$lang_warnings[] = 'нет meta description';
				$score -= 12;
			} elseif (mb_strlen($meta_description) < 110 || mb_strlen($meta_description) > 170) {
				$lang_warnings[] = 'meta description не в оптимальной длине';
				$score -= 6;
			}
			if ($slug === '') {
				$lang_warnings[] = 'нет slug';
				$score -= 10;
			}
			if ($focus_keywords === []) {
				$lang_warnings[] = 'нет focus keywords';
				$score -= 10;
			} else {
				$primary = mb_strtolower((string) $focus_keywords[0]);
				if ($primary !== '' && ! self::contains_keyword($seo_title . ' ' . $title, $primary)) {
					$lang_warnings[] = 'главный ключ не попадает в title';
					$score -= 8;
				}
				if ($primary !== '' && ! self::contains_keyword($excerpt . ' ' . $meta_description, $primary)) {
					$lang_warnings[] = 'главный ключ не попадает в lead/meta description';
					$score -= 6;
				}
				if ($primary !== '' && ! self::contains_keyword($content, $primary)) {
					$lang_warnings[] = 'главный ключ слабо встроен в текст';
					$score -= 6;
				}
			}

			if ($lang_warnings !== []) {
				$warnings[$lang] = array_values(array_unique($lang_warnings));
			}
		}

		return [
			'score' => max(0, min(100, $score)),
			'warnings' => $warnings,
			'pass' => $score >= 78,
		];
	}

	public static function release_quality(array $payload): array {
		$warnings = [];
		$score = 100;
		$length_targets = self::release_length_targets($payload);
		$de_reference = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$de_title_reference = trim((string) ($de_reference['title'] ?? ''));
		$de_excerpt_reference = trim((string) ($de_reference['excerpt'] ?? ''));
		$de_content_reference = trim(wp_strip_all_tags((string) ($de_reference['content'] ?? '')));
		$featured = trim((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''));
		$categories = is_array($payload['categories'] ?? null) ? array_values(array_filter(array_map('strval', $payload['categories']))) : [];
		if ($featured === '') {
			$warnings[] = 'нет featured media';
			$score -= 25;
		} elseif (! self::featured_media_passes_release_context($payload, $featured, $de_title_reference, $de_excerpt_reference, $categories)) {
			$warnings[] = 'featured media не соответствует теме материала';
			$score -= 25;
		} elseif (EPV2_Media::is_fallback_stock_url($featured) && self::stock_fallback_not_allowed($payload, $de_title_reference, $de_excerpt_reference, $de_content_reference)) {
			$warnings[] = 'generic stock featured media слишком слабое для конкретной темы материала';
			$score -= 20;
		}
		$supporting_issue = self::supporting_context_issue($payload, $de_title_reference, $de_excerpt_reference, $categories);
		if ($supporting_issue !== '') {
			$warnings[] = $supporting_issue;
			$score -= 14;
		}
		$inline = EPV2_Media::normalize_media_list($payload['inline_media_urls'] ?? []);
		$story_format = (string) ($payload['_meta']['story_format'] ?? '');
		$needs_inline_media = in_array($story_format, ['analysis', 'developing'], true);
		if ($inline === [] && $needs_inline_media) {
			$warnings[] = 'нет inline media';
			$score -= 2;
		}
		foreach (['de', 'uk', 'en'] as $lang) {
			$title = trim((string) ($payload['languages'][$lang]['title'] ?? ''));
			$excerpt = trim((string) ($payload['languages'][$lang]['excerpt'] ?? ''));
			$content = trim(wp_strip_all_tags((string) ($payload['languages'][$lang]['content'] ?? '')));
			$min_length = (int) ($length_targets[$lang] ?? 700);
			if ($content !== '' && mb_strlen($content) < $min_length) {
				$warnings[] = strtoupper($lang) . ': материал может быть слишком коротким для сильной публикации';
				$score -= 4;
			}
			$integrity_issue = self::language_integrity_issue($lang, $title, $excerpt, $content, $de_title_reference, $de_excerpt_reference, $de_content_reference);
			if ($integrity_issue !== '') {
				$warnings[] = strtoupper($lang) . ': сломанная языковая версия';
				$score -= 25;
			}
		}
		if (! empty($payload['_meta']['selection']['source_strength']) && (int) $payload['_meta']['selection']['source_strength'] <= 1) {
			$warnings[] = 'слабый исходный сигнал: материал лучше усиливать вторым подтверждением или публиковать как короткую новость';
			$score -= 6;
		}
		return [
			'score' => max(0, min(100, $score)),
			'warnings' => $warnings,
			'pass' => $score >= 72,
		];
	}

	private static function featured_media_passes_release_context(array $payload, string $featured, string $title, string $excerpt, array $categories): bool {
		if ($featured === '') {
			return false;
		}
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		if (EPV2_Media::is_relevant_media($featured, $title, $excerpt, $categories, $dossier)) {
			return true;
		}
		$primary = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
		$primary_image = esc_url_raw((string) ($primary['image'] ?? ''));
		$featured_fingerprint = EPV2_Media::media_fingerprint($featured);
		if (
			$primary_image !== ''
			&& $featured_fingerprint !== ''
			&& EPV2_Media::media_fingerprint($primary_image) === $featured_fingerprint
		) {
			$primary_title = trim((string) ($primary['title'] ?? ''));
			$primary_excerpt = trim((string) ($primary['excerpt'] ?? ''));
			if (self::source_entry_matches_payload_context($payload, $title, $excerpt, $primary_title, $primary_excerpt, $categories)) {
				return true;
			}
		}

		$supporting = array_values(array_filter((array) ($dossier['supporting'] ?? []), 'is_array'));
		if ($supporting === []) {
			return false;
		}
		if ($featured_fingerprint === '') {
			return false;
		}

		foreach ($supporting as $entry) {
			$image = esc_url_raw((string) ($entry['image'] ?? ''));
			if ($image === '') {
				continue;
			}
			if (EPV2_Media::media_fingerprint($image) !== $featured_fingerprint) {
				continue;
			}
			$entry_title = trim((string) ($entry['title'] ?? ''));
			$entry_excerpt = trim((string) ($entry['excerpt'] ?? ''));
			if (self::source_entry_matches_payload_context($payload, $title, $excerpt, $entry_title, $entry_excerpt, $categories)) {
				return true;
			}
		}

		return false;
	}

	private static function source_entry_matches_payload_context(array $payload, string $storyTitle, string $storyExcerpt, string $entryTitle, string $entryExcerpt, array $categories): bool {
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$storyContext = is_array($dossier['story_context'] ?? null) ? $dossier['story_context'] : [];
		$primary = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
		$storyContent = trim((string) ($primary['content'] ?? ''));
		if (self::source_entry_matches_story_context($storyTitle, $storyExcerpt, $storyContent, $entryTitle, $entryExcerpt, '', $categories, $storyContext)) {
			return true;
		}
		$primaryTitle = trim((string) ($primary['title'] ?? ''));
		$primaryExcerpt = trim((string) ($primary['excerpt'] ?? ''));
		if ($primaryTitle === '' && $primaryExcerpt === '') {
			return false;
		}
		return self::source_entry_matches_story_context($primaryTitle, $primaryExcerpt, $storyContent, $entryTitle, $entryExcerpt, '', $categories, $storyContext);
	}

	private static function source_entry_matches_story_context(string $storyTitle, string $storyExcerpt, string $storyContent, string $entryTitle, string $entryExcerpt, string $entryContent, array $categories, array $storyContext = []): bool {
		$storyTokens = array_values(array_unique(array_merge(
			self::meaningful_context_tokens($storyTitle . ' ' . $storyExcerpt . ' ' . $storyContent),
			self::meaningful_context_tokens(implode(' ', array_merge(
				(array) ($storyContext['search_terms'] ?? []),
				(array) ($storyContext['entities'] ?? []),
				(array) ($storyContext['theme_tokens'] ?? []),
				(array) ($storyContext['body_keywords'] ?? []),
				[(string) ($storyContext['body_snippet'] ?? '')]
			)))
		)));
		$entryTokens = self::meaningful_context_tokens($entryTitle . ' ' . $entryExcerpt . ' ' . $entryContent);
		if ($storyTokens === [] || $entryTokens === []) {
			return false;
		}
		if (count(array_intersect($storyTokens, $entryTokens)) >= 2) {
			return true;
		}
		$joined = mb_strtolower(trim($entryTitle . ' ' . $entryExcerpt . ' ' . $entryContent));
		if ($joined === '') {
			return false;
		}
		foreach ((array) ($storyContext['search_terms'] ?? []) as $term) {
			$term = mb_strtolower(trim((string) $term));
			if ($term !== '' && mb_strlen($term) >= 8 && str_contains($joined, $term)) {
				return true;
			}
		}
		$categoryFallbackAllowed = ['sport', 'kultur', 'community', 'leben-in-deutschland', 'wirtschaft', 'ukraine'];
		foreach ($categories as $category) {
			$category = mb_strtolower((string) $category);
			if ($category !== '' && in_array($category, $categoryFallbackAllowed, true) && str_contains($joined, $category)) {
				return true;
			}
		}
		return false;
	}

	private static function supporting_context_issue(array $payload, string $storyTitle, string $storyExcerpt, array $categories): string {
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$supporting = array_values(array_filter((array) ($dossier['supporting'] ?? []), 'is_array'));
		if ($supporting === []) {
			return '';
		}
		$mismatch = 0;
		$checked = 0;
		foreach (array_slice($supporting, 0, 3) as $entry) {
			$entry_title = trim((string) ($entry['title'] ?? ''));
			$entry_excerpt = trim((string) ($entry['excerpt'] ?? ''));
			if ($entry_title === '' && $entry_excerpt === '') {
				continue;
			}
			$checked++;
			if (! self::source_entry_matches_payload_context($payload, $storyTitle, $storyExcerpt, $entry_title, $entry_excerpt, $categories)) {
				$mismatch++;
			}
		}
		if ($checked === 1 && $mismatch === 1) {
			return 'дополнительный источник плохо соответствует основной теме и может уводить материал в сторону';
		}
		if ($checked >= 2 && $mismatch === $checked) {
			return 'дополнительные источники плохо соответствуют основной теме и могут уводить материал в сторону';
		}
		return '';
	}

	private static function meaningful_context_tokens(string $text): array {
		$text = mb_strtolower(wp_strip_all_tags($text));
		$text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: $text;
		$stop = ['der','die','das','und','mit','von','fuer','für','des','dem','den','eine','einer','einem','ein','auch','sich','ist','sind','wird','werden','auf','im','in','an','am','zu','zum','zur','for','the','and','with','von','on','heute','live'];
		$tokens = [];
		foreach (preg_split('/\s+/u', trim($text)) ?: [] as $token) {
			$token = trim((string) $token);
			if (mb_strlen($token) < 5 || in_array($token, $stop, true)) {
				continue;
			}
			$tokens[$token] = true;
		}
		return array_keys($tokens);
	}

	private static function category_fit_issue(array $payload, string $title, string $excerpt, string $content): string {
		$categories = is_array($payload['categories'] ?? null) ? array_values(array_filter(array_map('strval', $payload['categories']))) : [];
		$primary = (string) ($categories[0] ?? '');
		$text = mb_strtolower(trim($title . ' ' . $excerpt . ' ' . $content));
		if ($text === '') {
			return '';
		}
			$economy_text = preg_match('/\b(wirtschaft|wirtschaftsstärke|wirtschaftsstaerke|wirtschaftliche stärke|wirtschaftliche staerke|unternehmen|standort|wachstum|konjunktur|entlastungen|kartellrecht|investitionen|investition|industrie|arbeitsplätze|arbeitsplaetze|energiepreise|energiepreis|finanz|markt|haushalt|ministerium für wirtschaft|wirtschaftsministerium|katherina reiche)\b/u', $text) === 1;
			$political_regulation_text = preg_match('/\b(politische[- ]werbung|transparenzgesetz|digitalausschuss|bundesregierung|gesetzentwurf|eu-verordnung|regierungsvorlage|anhörung im digitalausschuss)\b/u', $text) === 1;
		$world_text = preg_match('/\b(washington|usa|united states|amerika|nahost|middle east|hormus|persischen golf|persischer golf|iran|israel|gaza|libanon|lebanon|china|taiwan|moskau|kreml|ukraine|krieg)\b/u', $text) === 1;
		$europe_text = preg_match('/\b(eu|europa|europäische union|europaeische union|eu-kommission|kommission|europäisches parlament|europaeisches parlament|brüssel|bruessel)\b/u', $text) === 1;
		if (
			preg_match('/\b(the voice|moderator|moderation|casting show|unterhaltungsshow|tv-show|quizshow|fernsehen|sendung|staffel|jury|schölermann)\b/u', $text) === 1
			&& $primary !== 'kultur'
		) {
			return 'рубрика не соответствует развлекательной или культурной теме материала';
		}
			if (
				preg_match('/\b(merz|friedrich merz|kanzler|regierungserklärung|regierungserklaerung|afd|europäische union|europaeische union)\b/u', $text) === 1
				&& $primary !== 'politik'
				&& ! ($primary === 'wirtschaft' && $economy_text)
			&& ! ($primary === 'world' && $world_text)
			&& ! ($primary === 'europa' && $europe_text)
		) {
					return 'рубрика не соответствует политической теме материала';
				}
				if (
					$political_regulation_text
					&& $primary !== 'politik'
					&& $primary !== 'europa'
				) {
					return 'рубрика не соответствует политико-регуляторной теме материала';
				}
		if (
			preg_match('/\b(fc bayern|bundesliga|champions league|uefa|trainer|match|spieltag|torwart|football|soccer|basketball|hockey|galatasaray|liverpool)\b/u', $text) === 1
			&& $primary !== 'sport'
		) {
			return 'рубрика не соответствует спортивной теме материала';
		}
		if (
			$world_text
			&& ! in_array($primary, ['world', 'politik', 'ukraine', 'europa'], true)
			&& ! ($primary === 'wirtschaft' && $economy_text)
		) {
			return 'рубрика не соответствует международной теме материала';
		}
		if (
			preg_match('/\b(workshop|sprechstunde|community|diaspora|hilfsangebot|bahnhofsmission|jobcenter|aufenthalt|wohngeld|beratungsstelle|beratungsangebot|netzwerktreffen|ehrenamt)\b/u', $text) === 1
			&& ! in_array($primary, ['community', 'leben-in-deutschland'], true)
		) {
			return 'рубрика не соответствует сервисной или community-теме материала';
		}
		return '';
	}

	private static function stock_fallback_not_allowed(array $payload, string $title, string $excerpt, string $content): bool {
		$categories = is_array($payload['categories'] ?? null) ? array_values(array_filter(array_map('strval', $payload['categories']))) : [];
		$primary = (string) ($categories[0] ?? '');
		$text = mb_strtolower(trim($title . ' ' . $excerpt . ' ' . $content));
		if ($text === '') {
			return false;
		}
		if (in_array($primary, ['politik', 'kultur', 'sport', 'community', 'ukraine', 'world'], true)) {
			return true;
		}
		return preg_match('/\b(the voice|quizshow|tv-show|moderator|staffel|jury|schölermann|merz|friedrich merz|kanzler|bundestag|regierungserklärung|regierungserklaerung|afd|fc bayern|bundesliga|champions league|match|spieltag|community|verein|workshop|sprechstunde|bahnhofsmission|ukraine|krieg|angriff)\b/u', $text) === 1;
	}

	public static function google_preflight_quality(array $payload): array {
		$warnings = [];
		$score = 100;
		$google_targets = self::google_depth_targets($payload);
		$de_reference = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$de_title_reference = trim((string) ($de_reference['title'] ?? ''));
		$de_excerpt_reference = trim((string) ($de_reference['excerpt'] ?? ''));
		$de_content_reference = trim(wp_strip_all_tags((string) ($de_reference['content'] ?? '')));
		foreach (['de', 'uk', 'en'] as $lang) {
			$lang_payload = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
			if ($lang_payload === []) {
				continue;
			}
			$seo_title = trim((string) ($lang_payload['seo_title'] ?? ''));
			$meta_description = trim((string) ($lang_payload['meta_description'] ?? ''));
			$slug = trim((string) ($lang_payload['slug'] ?? ''));
			$content = trim(wp_strip_all_tags((string) ($lang_payload['content'] ?? '')));
			$excerpt = trim((string) ($lang_payload['excerpt'] ?? ''));
			if ($seo_title === '' || mb_strlen($seo_title) < 35 || mb_strlen($seo_title) > 70) {
				$warnings[] = strtoupper($lang) . ': слабый SEO title для Google';
				$score -= 8;
			}
			if ($meta_description === '' || mb_strlen($meta_description) < 110 || mb_strlen($meta_description) > 170) {
				$warnings[] = strtoupper($lang) . ': слабый meta description для Google';
				$score -= 8;
			}
			if ($slug === '') {
				$warnings[] = strtoupper($lang) . ': отсутствует slug';
				$score -= 8;
			}
			if ($excerpt === '' || mb_strlen($excerpt) < 90) {
				$warnings[] = strtoupper($lang) . ': слишком слабый lead для snippet';
				$score -= 5;
			}
			$google_min_length = (int) ($google_targets[$lang] ?? 520);
			if ($content === '' || mb_strlen($content) < $google_min_length) {
				$warnings[] = strtoupper($lang) . ': материал может быть слишком поверхностным';
				$score -= 5;
			}
			$integrity_issue = self::language_integrity_issue($lang, trim((string) ($lang_payload['title'] ?? '')), $excerpt, $content, $de_title_reference, $de_excerpt_reference, $de_content_reference);
			if ($integrity_issue !== '') {
				$warnings[] = strtoupper($lang) . ': языковая версия выглядит невалидной для индексации';
				$score -= 25;
			}
		}
		if (trim((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? '')) === '') {
			$warnings[] = 'нет главного изображения для Google';
			$score -= 12;
		}
		return [
			'score' => max(0, min(100, $score)),
			'warnings' => $warnings,
			'pass' => $score >= 78,
		];
	}

	private static function release_length_targets(array $payload): array {
		$profile = self::story_budget_profile($payload);
		return [
			'de' => (int) ($profile['de']['content_min_chars'] ?? 700),
			'uk' => (int) ($profile['uk']['content_min_chars'] ?? 520),
			'en' => (int) ($profile['en']['content_min_chars'] ?? 520),
		];
	}

	private static function release_soft_targets(array $payload): array {
		$profile = self::story_budget_profile($payload);
		return [
			'de' => (int) ($profile['de']['content_soft_chars'] ?? 1100),
			'uk' => (int) ($profile['uk']['content_soft_chars'] ?? 800),
			'en' => (int) ($profile['en']['content_soft_chars'] ?? 800),
		];
	}

	private static function google_depth_targets(array $payload): array {
		$profile = self::story_budget_profile($payload);
		return [
			'de' => max(220, (int) (($profile['de']['content_soft_chars'] ?? 1100) - 120)),
			'uk' => max(180, (int) (($profile['uk']['content_soft_chars'] ?? 800) - 100)),
			'en' => max(180, (int) (($profile['en']['content_soft_chars'] ?? 800) - 100)),
		];
	}

	private static function story_budget_profile(array $payload): array {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$story_format = sanitize_key((string) ($meta['story_format'] ?? ''));
		$zone = in_array($story_format, ['analysis', 'developing'], true) ? $story_format : 'news';
		$category = (string) ((array) ($payload['categories'] ?? ['deutschland']))[0];
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$context = [
			'source_count' => max(1, (int) ($meta['source_count'] ?? 1)),
			'event_kind' => (string) ($meta['source_dossier']['event_context']['kind'] ?? ''),
			'title' => (string) ($de['title'] ?? ''),
			'excerpt' => (string) ($de['excerpt'] ?? ''),
			'content' => (string) ($de['content'] ?? ''),
			'datetime_text' => (string) ($meta['source_dossier']['event_context']['datetime_text'] ?? ''),
			'venue' => (string) ($meta['source_dossier']['event_context']['venue'] ?? ''),
			'stage' => (string) ($meta['source_dossier']['event_context']['stage'] ?? ''),
		];

		return [
			'de' => EPV2_Site_Profile::text_budget('de', $zone, $category, $context),
			'uk' => EPV2_Site_Profile::text_budget('uk', $zone, $category, $context),
			'en' => EPV2_Site_Profile::text_budget('en', $zone, $category, $context),
		];
	}

	private static function normalize_blocked_media_urls(array $blocked): array {
		$blocked = array_map(static fn($url): string => esc_url_raw(trim((string) $url)), $blocked);
		$blocked = array_values(array_filter($blocked));
		return array_values(array_unique($blocked));
	}

	private static function media_url_is_blocked(array $payload, string $url): bool {
		$url = esc_url_raw(trim($url));
		if ($url === '') {
			return false;
		}
		$blocked = self::normalize_blocked_media_urls((array) ($payload['_meta']['blocked_media_urls'] ?? []));
		return in_array($url, $blocked, true);
	}

	private static function prune_blocked_media_from_dossier(array $dossier, array $blocked): array {
		$blocked = self::normalize_blocked_media_urls($blocked);
		if ($dossier === [] || $blocked === []) {
			return $dossier;
		}
		if (is_array($dossier['primary'] ?? null)) {
			$image = esc_url_raw((string) ($dossier['primary']['image'] ?? ''));
			if ($image !== '' && in_array($image, $blocked, true)) {
				$dossier['primary']['image'] = '';
			}
		}
		if (is_array($dossier['supporting'] ?? null)) {
			foreach ($dossier['supporting'] as $index => $entry) {
				if (! is_array($entry)) {
					continue;
				}
				$image = esc_url_raw((string) ($entry['image'] ?? ''));
				if ($image !== '' && in_array($image, $blocked, true)) {
					$dossier['supporting'][$index]['image'] = '';
				}
			}
		}
		return $dossier;
	}

	private static function trim_chars(string $text, int $max): string {
		$text = trim(wp_strip_all_tags($text));
		if ($text === '' || mb_strlen($text) <= $max) {
			return $text;
		}
		$cut = mb_substr($text, 0, $max);
		$space = mb_strrpos($cut, ' ');
		if ($space !== false) {
			$cut = mb_substr($cut, 0, $space);
		}
		return rtrim($cut, " \t\n\r\0\x0B,.;:-");
	}

	private static function derive_focus_keywords(string $title, string $excerpt, string $content, array $categories): array {
		$title_plain = trim(wp_strip_all_tags($title));
		$excerpt_plain = trim(wp_strip_all_tags($excerpt));
		$text = mb_strtolower(trim(wp_strip_all_tags($title_plain . ' ' . $excerpt_plain . ' ' . mb_substr($content, 0, 1200))));
		$text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: $text;
		$stopwords = [
			'der','die','das','und','mit','für','von','dem','den','des','ein','eine','auf','nach','über','zum','zur',
			'durch','gegen','heute','morgen','müssen','sowie','weil','dabei',
			'the','and','for','from','with','this','that','into','over','more','will','amid','ahead','after','before',
			'про','для','після','перед','через','його','її','вони','також','що','це','як','були','будуть',
			'что','это','как','если','после','перед',
		];
		$generic = [
			'experten','expert','experts','streiten','argue','controversy','kontrovers','kontroverse','industry','industrie',
			'kontroversen','sagen','sagt','said','says','according','report','reported','reports',
			'щодо','експерти','сперечаються','контроверсії','повідомили','заявили',
			'dass','über','ueber','weiter','heute','morgen',
		];
		$weights = [];
		foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
			$word = trim((string) $word);
			if ($word === '' || ! self::keyword_token_is_meaningful($word) || in_array($word, $stopwords, true) || in_array($word, $generic, true)) {
				continue;
			}
			$weights[$word] = ($weights[$word] ?? 0) + 1;
		}
		foreach (self::title_focus_candidates($title_plain, $stopwords, $generic) as $candidate) {
			$weights[$candidate] = ($weights[$candidate] ?? 0) + (str_contains($candidate, ' ') ? 8 : 6);
		}
		$title_tokens = preg_split('/\s+/u', mb_strtolower($title_plain)) ?: [];
		foreach ($title_tokens as $word) {
			$word = trim((string) $word);
			if ($word !== '' && self::keyword_token_is_meaningful($word) && ! in_array($word, $stopwords, true) && ! in_array($word, $generic, true)) {
				$weights[$word] = ($weights[$word] ?? 0) + 3;
			}
		}
		$labels = EPV2_Taxonomy_Map::categories();
		foreach ($categories as $category) {
			$label = mb_strtolower((string) ($labels[(string) $category] ?? (string) $category));
			foreach (preg_split('/\s+/u', $label) ?: [] as $word) {
				$word = trim((string) $word);
				if ($word !== '' && self::keyword_token_is_meaningful($word) && ! in_array($word, $stopwords, true) && ! in_array($word, $generic, true)) {
					$weights[$word] = ($weights[$word] ?? 0) + 2;
				}
			}
		}
		foreach (array_keys($weights) as $candidate) {
			if (! str_contains((string) $candidate, '-')) {
				continue;
			}
			foreach (array_filter(array_map('trim', explode('-', (string) $candidate))) as $part) {
				if (! self::keyword_token_is_meaningful($part) || in_array($part, $stopwords, true) || in_array($part, $generic, true)) {
					continue;
				}
				$weights[$part] = max(($weights[$part] ?? 0), (int) ceil(((int) ($weights[$candidate] ?? 1)) / 2));
			}
		}
		arsort($weights);
		return array_slice(array_keys($weights), 0, 5);
	}

	private static function align_focus_keywords(array $keywords, string $title, string $excerpt, string $content, string $seo_title, string $meta_description): array {
		$keywords = array_values(array_filter(array_map('sanitize_text_field', $keywords)));
		if ($keywords === []) {
			return [];
		}
		$ranked = [];
		foreach ($keywords as $index => $keyword) {
			$score = 0;
			if (self::contains_keyword($title, $keyword)) {
				$score += 4;
			}
			if (self::contains_keyword($excerpt, $keyword)) {
				$score += 3;
			}
			if (self::contains_keyword($content, $keyword)) {
				$score += 5;
			}
			if (self::contains_keyword($seo_title, $keyword)) {
				$score += 2;
			}
			if (self::contains_keyword($meta_description, $keyword)) {
				$score += 2;
			}
			if (mb_strlen($keyword) >= 6) {
				$score += 1;
			}
			$ranked[] = [
				'keyword' => $keyword,
				'score' => $score,
				'index' => $index,
			];
		}
		usort($ranked, static function (array $a, array $b): int {
			if ($a['score'] !== $b['score']) {
				return $b['score'] <=> $a['score'];
			}
			return $a['index'] <=> $b['index'];
		});
		return array_values(array_unique(array_map(static fn(array $entry): string => (string) $entry['keyword'], $ranked)));
	}

	private static function focus_keywords_fit_payload(array $keywords, string $title, string $excerpt, string $content, string $seo_title, string $meta_description): bool {
		$keywords = array_values(array_filter(array_map('sanitize_text_field', $keywords)));
		if ($keywords === []) {
			return false;
		}
		$primary = (string) ($keywords[0] ?? '');
		if ($primary === '') {
			return false;
		}
		if (! self::contains_keyword($seo_title . ' ' . $title, $primary)) {
			return false;
		}
		if (! self::contains_keyword($excerpt . ' ' . $meta_description, $primary)) {
			return false;
		}
		return self::contains_keyword($content, $primary);
	}

	private static function contains_keyword(string $text, string $keyword): bool {
		$text = mb_strtolower(trim(wp_strip_all_tags($text)));
		$keyword = mb_strtolower(trim($keyword));
		if ($text === '' || $keyword === '') {
			return false;
		}
		if (str_contains($text, $keyword)) {
			return true;
		}

		$text_tokens = self::keyword_tokens($text);
		$keyword_tokens = self::keyword_tokens($keyword);
		if ($text_tokens === [] || $keyword_tokens === []) {
			return false;
		}

		foreach ($keyword_tokens as $token) {
			if (! self::keyword_token_present($text_tokens, $token)) {
				return false;
			}
		}
		return true;
	}

	private static function keyword_tokens(string $text): array {
		$text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: $text;
		$tokens = preg_split('/\s+/u', trim($text)) ?: [];
		return array_values(array_filter(array_map(static fn($token): string => trim((string) $token), $tokens), static function (string $token): bool {
			return self::keyword_token_is_meaningful($token);
		}));
	}

	private static function keyword_token_is_meaningful(string $token): bool {
		$token = trim(mb_strtolower($token));
		if ($token === '') {
			return false;
		}
		if (mb_strlen($token) >= 4) {
			return true;
		}
		return mb_strlen($token) >= 2 && preg_match('/\d/u', $token) === 1;
	}

	private static function title_focus_candidates(string $title, array $stopwords, array $generic): array {
		$title = mb_strtolower(trim(wp_strip_all_tags($title)));
		if ($title === '') {
			return [];
		}
		$title = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $title) ?: $title;
		$tokens = array_values(array_filter(array_map(static fn($token): string => trim((string) $token), preg_split('/\s+/u', $title) ?: []), static function (string $token) use ($stopwords, $generic): bool {
			return self::keyword_token_is_meaningful($token) && ! in_array($token, $stopwords, true) && ! in_array($token, $generic, true);
		}));
		if ($tokens === []) {
			return [];
		}
		$candidates = [];
		foreach ($tokens as $index => $token) {
			$candidates[] = $token;
			$next = $tokens[$index + 1] ?? '';
			if ($next !== '') {
				$candidates[] = $token . ' ' . $next;
			}
		}
		return array_values(array_unique(array_filter($candidates)));
	}

	private static function keyword_token_present(array $text_tokens, string $keyword): bool {
		$keyword = trim($keyword);
		if ($keyword === '') {
			return false;
		}
		$prefix_len = max(5, min(8, mb_strlen($keyword) - 1));
		$prefix = mb_substr($keyword, 0, min($prefix_len, mb_strlen($keyword)));
		foreach ($text_tokens as $token) {
			if ($token === $keyword) {
				return true;
			}
			if ($prefix !== '' && (str_starts_with($token, $prefix) || str_starts_with($keyword, mb_substr($token, 0, min($prefix_len, mb_strlen($token)))))) {
				return true;
			}
		}
		return false;
	}

	private static function inject_keyword_into_meta(string $meta, string $keyword, string $lang): string {
		$meta = trim(wp_strip_all_tags($meta));
		$keyword = trim($keyword);
		if ($meta === '' || $keyword === '') {
			return $meta;
		}
		if (self::contains_keyword($meta, $keyword)) {
			return $meta;
		}
		$prefix = match ($lang) {
			'uk' => $keyword . ': ',
			'en' => $keyword . ': ',
			default => $keyword . ': ',
		};
		return self::trim_chars($prefix . lcfirst($meta), 155);
	}

	private static function auto_inline_media_urls(array $payload, array $categories): array {
		$featured = trim((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''));
		$title = (string) ($payload['languages']['de']['title'] ?? '');
		$excerpt = (string) ($payload['languages']['de']['excerpt'] ?? '');
		$story_format = (string) ($payload['_meta']['story_format'] ?? '');
		$allow_images = in_array($story_format, ['analysis', 'developing'], true);
		$dossier = (array) ($payload['_meta']['source_dossier'] ?? []);
		$candidates = [];
		if (is_array($dossier['primary'] ?? null)) {
			$candidates[] = (string) ($dossier['primary']['image'] ?? '');
			$candidates[] = (string) ($dossier['primary']['video'] ?? '');
		}
		foreach ((array) ($dossier['supporting'] ?? []) as $entry) {
			if (! is_array($entry)) {
				continue;
			}
			$candidates[] = (string) ($entry['image'] ?? '');
			$candidates[] = (string) ($entry['video'] ?? '');
		}
		$selected = [];
		foreach (array_values(array_unique(array_filter(array_map('trim', $candidates)))) as $candidate) {
			if ($candidate === '' || $candidate === $featured) {
				continue;
			}
			$type = EPV2_Media::detect_type($candidate);
			if (! $allow_images && ! in_array($type, ['video', 'embed'], true)) {
				continue;
			}
			if (! EPV2_Media::can_use_featured_url($candidate) && $type !== 'video' && $type !== 'embed') {
				continue;
			}
			if (! EPV2_Media::is_relevant_media($candidate, $title, $excerpt, $categories, $dossier)) {
				continue;
			}
			$selected[] = $candidate;
			if (count($selected) >= ($allow_images ? 3 : 1)) {
				break;
			}
		}
		return $selected;
	}

	private static function seo_title_from_title(string $title, string $lang): string {
		$brand = match ($lang) {
			'uk' => ' | EuroPulse',
			'en' => ' | EuroPulse',
			default => ' | EuroPulse',
		};
		$title = self::trim_chars($title, 58);
		$full = trim($title . $brand);
		return self::trim_chars($full, 68);
	}

	private static function meta_description_from_text(string $text, string $lang): string {
		$text = trim(wp_strip_all_tags($text));
		if ($text === '') {
			return '';
		}
		$limit = 155;
		return self::trim_chars($text, $limit);
	}

	private static function seo_field_looks_wrong_for_language(string $text, string $lang): bool {
		if ($text === '') {
			return false;
		}
		if ($lang === 'uk') {
			return ! preg_match('/\p{Cyrillic}/u', $text)
				|| preg_match('/\b(die|der|das|und|mit|für|wird|nicht|bundesregierung|fachkr[aä]fte)\b/iu', $text)
				|| preg_match('/[ыэёъ]/u', $text) === 1;
		}
		if ($lang === 'en') {
			return preg_match('/\b(die|der|das|und|mit|für|wird|nicht|bundesregierung|fachkr[aä]fte|akteuren vor ort)\b/iu', $text) === 1
				|| preg_match('/\p{Cyrillic}/u', $text) === 1
				|| preg_match('/[A-Za-z]/u', $text) !== 1;
		}
		return false;
	}

	private static function focus_keywords_look_wrong_for_language(array $keywords, string $lang): bool {
		$joined = implode(' ', array_map('strval', $keywords));
		return self::seo_field_looks_wrong_for_language($joined, $lang);
	}

	private static function slug_looks_wrong_for_language(string $slug, string $lang): bool {
		if ($lang === 'de') {
			return false;
		}
		return preg_match('/fachkraeft|bundesregierung|akteuren-vor-ort/u', $slug) === 1
			|| str_contains($slug, '%');
	}

	private static function slug_from_title(string $value, string $lang): string {
		$value = trim(wp_strip_all_tags($value));
		if ($value === '') {
			return '';
		}
		$slug = sanitize_title($value);
		if ($slug !== '' && ! str_contains($slug, '%')) {
			return $slug;
		}
		$ascii = self::transliterate_slug_source($value, $lang);
		$slug = sanitize_title($ascii);
		if ($slug !== '') {
			return $slug;
		}
		return sanitize_title(remove_accents($value));
	}

	private static function transliterate_slug_source(string $value, string $lang): string {
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if ($lang === 'uk') {
			$map = [
				'А' => 'A', 'а' => 'a', 'Б' => 'B', 'б' => 'b', 'В' => 'V', 'в' => 'v',
				'Г' => 'H', 'г' => 'h', 'Ґ' => 'G', 'ґ' => 'g', 'Д' => 'D', 'д' => 'd',
				'Е' => 'E', 'е' => 'e', 'Є' => 'Ye', 'є' => 'ye', 'Ж' => 'Zh', 'ж' => 'zh',
				'З' => 'Z', 'з' => 'z', 'И' => 'Y', 'и' => 'y', 'І' => 'I', 'і' => 'i',
				'Ї' => 'Yi', 'ї' => 'yi', 'Й' => 'Y', 'й' => 'y', 'К' => 'K', 'к' => 'k',
				'Л' => 'L', 'л' => 'l', 'М' => 'M', 'м' => 'm', 'Н' => 'N', 'н' => 'n',
				'О' => 'O', 'о' => 'o', 'П' => 'P', 'п' => 'p', 'Р' => 'R', 'р' => 'r',
				'С' => 'S', 'с' => 's', 'Т' => 'T', 'т' => 't', 'У' => 'U', 'у' => 'u',
				'Ф' => 'F', 'ф' => 'f', 'Х' => 'Kh', 'х' => 'kh', 'Ц' => 'Ts', 'ц' => 'ts',
				'Ч' => 'Ch', 'ч' => 'ch', 'Ш' => 'Sh', 'ш' => 'sh', 'Щ' => 'Shch', 'щ' => 'shch',
				'Ю' => 'Yu', 'ю' => 'yu', 'Я' => 'Ya', 'я' => 'ya', 'Ь' => '', 'ь' => '',
				'’' => '', '\'' => '',
			];
			return strtr($value, $map);
		}
		$translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
		return is_string($translit) && $translit !== '' ? $translit : $value;
	}

	private static function strip_trailing_translation_artifacts(string $content, string $lang): string {
		$patterns = [
			'/(?:Mehr zum Thema|More on this topic|Більше на тему).*$/isu',
			'/(?:ist|sehr|wichtig|die|der|das|mit|für|und)\b[^<]{0,160}—\s*[A-Z][A-Za-z0-9._-]+(?=<\/p>\s*$|$)/iu',
			'/[A-Za-zÄÖÜäöüß]{2,}(?:\s+[A-Za-zÄÖÜäöüß]{2,}){0,6}\s+—\s*[A-Z][A-Za-z0-9._-]+(?=<\/p>\s*$|$)/u',
			'/(?:[.!?]|”|"|»)\s*(?:Watson(?:\.de)?|BR|Goal(?:\.com)?(?:\s+Deutschland)?|Kritik\s*[—-]\s*Br)\s*$/iu',
			'/(?:Watson(?:\.de)?|BR|Goal(?:\.com)?(?:\s+Deutschland)?|Kritik\s*[—-]\s*Br)\s*$/iu',
		];
		foreach ($patterns as $pattern) {
			$content = preg_replace($pattern, '', $content) ?: $content;
		}
		return trim($content);
	}

	private static function sanitize_embedded_quote_cites(string $content): string {
		return preg_replace_callback('/<cite>(.*?)<\/cite>/isu', static function (array $matches): string {
			$raw = trim(wp_strip_all_tags((string) ($matches[1] ?? '')));
			if ($raw === '') {
				return '';
			}
			if (
				str_contains($raw, '—')
				&& preg_match('/\b(ist sehr wichtig|die|der|das|mit|für|und|newsletter|volltextsuche|symbolbild)\b/iu', $raw) === 1
			) {
				$parts = array_values(array_filter(array_map('trim', explode('—', $raw))));
				$tail = end($parts);
				if (is_string($tail) && $tail !== '') {
					return '<cite>' . esc_html($tail) . '</cite>';
				}
				return '';
			}
			return '<cite>' . esc_html($raw) . '</cite>';
		}, $content) ?: $content;
	}

	private static function language_integrity_issue(string $lang, string $title, string $excerpt, string $content, string $de_title, string $de_excerpt, string $de_content): string {
		if ($lang === 'de') {
			return self::german_language_issue(trim($title . ' ' . $excerpt . ' ' . $content));
		}
		$combined = trim($title . ' ' . $excerpt . ' ' . $content);
		if ($combined === '') {
			return 'пустая языковая версия';
		}
		$combined_lower = mb_strtolower($combined);
		if (mb_strtolower(trim($title)) === mb_strtolower(trim($de_title)) || mb_strtolower(trim($excerpt)) === mb_strtolower(trim($de_excerpt))) {
			return 'языковая версия выглядит копией немецкой';
		}
		if ($de_content !== '' && $content !== '' && mb_strtolower(trim($content)) === mb_strtolower(trim($de_content))) {
			return 'языковая версия выглядит копией немецкой';
		}
		if ($lang === 'uk') {
			if (! preg_match('/\p{Cyrillic}/u', $combined)) {
				return 'украинская версия не переведена';
			}
			if (
				preg_match('/\b(die|der|das|und|mit|für|wird|nicht|bundesregierung|fachkr[aä]fte|akteuren vor ort)\b/iu', $combined_lower)
				|| preg_match('/[ыэёъ]/u', $combined) === 1
			) {
				return 'украинская версия смешана с немецким';
			}
		}
		if ($lang === 'en') {
			if (
				preg_match('/\b(die|der|das|und|mit|für|wird|nicht|bundesregierung|fachkr[aä]fte|akteuren vor ort)\b/iu', $combined_lower)
				|| preg_match('/\p{Cyrillic}/u', $combined) === 1
				|| preg_match('/[A-Za-z]/u', $combined) !== 1
			) {
				return 'английская версия смешана с немецким';
			}
			if (str_contains($combined, 'Die Bundesregierung')) {
				return 'английская версия не переведена';
			}
		}
		return '';
	}

	private static function german_language_issue(string $combined): string {
		$combined = trim($combined);
		if ($combined === '') {
			return 'немецкая master-версия пуста';
		}
		if (preg_match('/\p{Cyrillic}/u', $combined) === 1) {
			return 'немецкая master-версия не выглядит немецкой';
		}
		if (preg_match('/[A-Za-zÄÖÜäöüß]/u', $combined) !== 1) {
			return 'немецкая master-версия не выглядит немецкой';
		}
		return '';
	}

	private static function body_repeats_lead_without_depth(string $excerpt, string $content, int $soft_min): bool {
		$excerpt = mb_strtolower(trim(wp_strip_all_tags($excerpt)));
		$content = trim(wp_strip_all_tags($content));
		if ($excerpt === '' || $content === '') {
			return false;
		}
		$paragraphs = preg_split('/\n\s*\n/u', trim($content)) ?: [];
		$first = '';
		foreach ($paragraphs as $paragraph) {
			$plain = mb_strtolower(trim(wp_strip_all_tags((string) $paragraph)));
			if ($plain !== '') {
				$first = $plain;
				break;
			}
		}
		if ($first === '') {
			return false;
		}
		$sameLead = $first === $excerpt || str_contains($first, $excerpt) || str_contains($excerpt, $first);
		return $sameLead && mb_strlen($content) < max(420, $soft_min);
	}

	private static function normalize_content_html(string $content): string {
		$content = trim((string) $content);
		if ($content === '') {
			return '';
		}
		$content = preg_replace('/\r\n?/', "\n", $content) ?: $content;
		$content = preg_replace('/<(strong|b)>(Why this matters|Warum das wichtig ist|Почему это важно|Context|Kontext|Контекст|What happens next|Wie es weitergeht|Что дальше)<\/(strong|b)>:?\s*/iu', '', $content) ?: $content;
		$has_paragraphs = preg_match('/<p[\s>]/i', $content) === 1;
		if (! $has_paragraphs) {
			$plain = trim(wp_strip_all_tags($content));
			if ($plain === '') {
				return '';
			}
			$paragraphs = preg_split('/\n\s*\n/u', $plain) ?: [];
			$paragraphs = array_values(array_filter(array_map('trim', $paragraphs)));
			if (count($paragraphs) <= 1) {
				$paragraphs = self::split_plain_text_into_paragraphs($plain);
			}
			$content = implode("\n\n", array_map(static fn(string $paragraph): string => '<p>' . esc_html($paragraph) . '</p>', $paragraphs));
		}
		$content = preg_replace("/(<\/p>)\s*(<p>)/u", "$1\n\n$2", $content) ?: $content;
		return trim((string) wp_kses_post($content));
	}

	private static function split_plain_text_into_paragraphs(string $text): array {
		$sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
		$paragraphs = [];
		$current = '';
		$count = 0;
		foreach ($sentences as $sentence) {
			$sentence = trim($sentence);
			if ($sentence === '') {
				continue;
			}
			$current = trim($current . ' ' . $sentence);
			$count++;
			if (mb_strlen($current) >= 280 || $count >= 3) {
				$paragraphs[] = $current;
				$current = '';
				$count = 0;
			}
		}
		if ($current !== '') {
			$paragraphs[] = $current;
		}
		return array_values(array_filter($paragraphs));
	}

	private static function looks_like_official_primary(string $url): bool {
		$host = (string) wp_parse_url($url, PHP_URL_HOST);
		if ($host === '') {
			return false;
		}
		foreach ([
			'bundesregierung',
			'bundestag',
			'bundesrat',
			'arbeitsagentur',
			'bamf',
			'service.bund',
			'bundesgesundheitsministerium',
			'bundesministerium',
			'bayern.de',
			'muenchen.de',
			'europa.eu',
			'europarl.europa.eu',
			'ec.europa.eu',
			'tagesschau.de',
		] as $signal) {
			if (str_contains($host, $signal)) {
				return true;
			}
		}
		return false;
	}
}
