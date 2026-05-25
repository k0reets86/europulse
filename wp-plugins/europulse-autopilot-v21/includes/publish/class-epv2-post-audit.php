<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Post_Audit {
	public static function repair_after_publish(array $post_ids, array $payload = [], ?object $item = null): array {
		$results = [];
		foreach ($post_ids as $lang => $post_id) {
			$results[$lang] = self::audit_and_repair((int) $post_id, $payload, $item);
		}
		return $results;
	}

	public static function repair_rendered_text_after_publish(array $post_ids, ?object $item = null): array {
		$results = [];
		foreach ($post_ids as $lang => $post_id) {
			$results[$lang] = self::audit_and_repair_rendered_text((int) $post_id, $item);
		}
		return $results;
	}

	private static function audit_and_repair_rendered_text(int $post_id, ?object $item = null): array {
		if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
			return ['ok' => false, 'reason' => 'invalid_post'];
		}

		$post = get_post($post_id);
		if (! $post instanceof WP_Post) {
			return ['ok' => false, 'reason' => 'missing_post'];
		}

		$lang = self::post_language($post_id);
		$updated = [
			'title' => false,
			'excerpt' => false,
			'content' => false,
		];
		$text_repairs = [];

		if (class_exists('EPV2_Quality_Gate')) {
			$title = (string) $post->post_title;
			$excerpt = (string) $post->post_excerpt;
			$content = (string) $post->post_content;

			$title_repair = EPV2_Quality_Gate::repair_rendered_text($title, $lang);
			$excerpt_repair = EPV2_Quality_Gate::repair_rendered_text($excerpt, $lang);
			if (! empty($title_repair['changed'])) {
				$title = (string) ($title_repair['text'] ?? $title);
				$updated['title'] = true;
				$text_repairs = self::merge_repair_flags($text_repairs, (array) ($title_repair['repairs'] ?? []));
			}
			if (! empty($excerpt_repair['changed'])) {
				$excerpt = (string) ($excerpt_repair['text'] ?? $excerpt);
				$updated['excerpt'] = true;
				$text_repairs = self::merge_repair_flags($text_repairs, (array) ($excerpt_repair['repairs'] ?? []));
			}
			if ($updated['title'] || $updated['excerpt']) {
				wp_update_post([
					'ID' => $post_id,
					'post_title' => $title,
					'post_excerpt' => $excerpt,
				]);
			}

			$content_repair = EPV2_Quality_Gate::repair_rendered_text($content, $lang);
			if (! empty($content_repair['changed'])) {
				wp_update_post([
					'ID' => $post_id,
					'post_content' => (string) ($content_repair['text'] ?? $content),
				]);
				$updated['content'] = true;
				$text_repairs = self::merge_repair_flags($text_repairs, (array) ($content_repair['repairs'] ?? []));
			}

			$quality = EPV2_Quality_Gate::record_rendered_post($post_id, $lang, $item, [
				'context' => 'post_publish_rendered',
				'repairs' => $text_repairs,
				'updated' => $updated,
				'audit_scope' => 'rendered_text_only',
			]);
		} else {
			$quality = [];
		}

		return [
			'ok' => true,
			'post_id' => $post_id,
			'updated' => $updated,
			'quality' => $quality,
		];
	}

	public static function audit_and_repair(int $post_id, array $payload = [], ?object $item = null): array {
		if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
			return ['ok' => false, 'reason' => 'invalid_post'];
		}

		$post = get_post($post_id);
		if (! $post instanceof WP_Post) {
			return ['ok' => false, 'reason' => 'missing_post'];
		}

		$title = (string) $post->post_title;
		$excerpt = (string) $post->post_excerpt;
		$content = (string) $post->post_content;
		$categories = self::category_slugs($post_id);
		$source_dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$source_url = EPV2_Source_Enricher::best_source_url($source_dossier, (string) get_post_meta($post_id, '_epv2_source_url', true));
		$lang = self::post_language($post_id);

		$updated = [
			'title' => false,
			'excerpt' => false,
			'content' => false,
			'media' => false,
			'caption' => false,
			'source_block' => false,
			'inline_media' => false,
			'slug' => false,
		];

		$text_repairs = [];
		if (class_exists('EPV2_Quality_Gate')) {
			$title_repair = EPV2_Quality_Gate::repair_rendered_text($title, $lang);
			$excerpt_repair = EPV2_Quality_Gate::repair_rendered_text($excerpt, $lang);
			if (! empty($title_repair['changed'])) {
				$title = (string) ($title_repair['text'] ?? $title);
				$updated['title'] = true;
				$text_repairs = self::merge_repair_flags($text_repairs, (array) ($title_repair['repairs'] ?? []));
			}
			if (! empty($excerpt_repair['changed'])) {
				$excerpt = (string) ($excerpt_repair['text'] ?? $excerpt);
				$updated['excerpt'] = true;
				$text_repairs = self::merge_repair_flags($text_repairs, (array) ($excerpt_repair['repairs'] ?? []));
			}
			if ($updated['title'] || $updated['excerpt']) {
				wp_update_post([
					'ID' => $post_id,
					'post_title' => $title,
					'post_excerpt' => $excerpt,
				]);
			}
		}

		$clean_content = self::remove_duplicate_excerpt_paragraphs($content, $excerpt);
		$clean_content = self::remove_duplicate_featured_media_block($clean_content, $post_id);
		$clean_content = self::sync_inline_media_blocks($clean_content, $payload, $lang, $updated);
		$clean_content = self::strip_trailing_weak_quote_blocks($clean_content);
		$clean_content = self::strip_standalone_source_fragments($clean_content);
		$clean_content = self::refresh_related_and_source_blocks($clean_content, $post_id, $categories, $lang, $source_url);
		$clean_content = self::strip_trailing_content_artifacts($clean_content);
		if (class_exists('EPV2_Quality_Gate')) {
			$content_repair = EPV2_Quality_Gate::repair_rendered_text($clean_content, $lang);
			if (! empty($content_repair['changed'])) {
				$clean_content = (string) ($content_repair['text'] ?? $clean_content);
				$text_repairs = self::merge_repair_flags($text_repairs, (array) ($content_repair['repairs'] ?? []));
			}
		}
		if ($clean_content !== $content && ! self::has_source_block($content) && self::has_source_block($clean_content)) {
			$updated['source_block'] = true;
		}

		if ($clean_content !== $content) {
			wp_update_post([
				'ID' => $post_id,
				'post_content' => $clean_content,
			]);
			$updated['content'] = true;
		}

		$updated['slug'] = self::sync_post_slug($post_id, $payload, $lang);

		$thumbnail_id = (int) get_post_thumbnail_id($post_id);
		$thumbnail_url = $thumbnail_id ? (string) wp_get_attachment_image_url($thumbnail_id, 'full') : '';
		$media_ok = false;
		if ($thumbnail_url !== '') {
			$validation = EPV2_Media::validate_featured_media($thumbnail_url, $post_id, $title);
			$media_ok = ! empty($validation['ok']) && EPV2_Media::is_relevant_media($thumbnail_url, $title, $excerpt, $categories);
		}

		if (! $media_ok) {
			$replacement = EPV2_Media::resolve_featured_media($title, $excerpt, $categories, '', $source_dossier, (int) get_post_meta($post_id, '_epv2_queue_id', true));
			if ($replacement !== '') {
				$validation = EPV2_Media::validate_featured_media($replacement, $post_id, $title);
				$media = is_array($validation['media'] ?? null) ? $validation['media'] : [];
				if (! empty($validation['ok']) && ($media['type'] ?? 'image') === 'image' && ! empty($media['attachment_id']) && ! empty($media['usable'])) {
					set_post_thumbnail($post_id, (int) $media['attachment_id']);
					$thumbnail_id = (int) $media['attachment_id'];
					$thumbnail_url = $replacement;
					$updated['media'] = true;
				}
			}
		}

		if ($thumbnail_id > 0 && $thumbnail_url !== '') {
			$current_caption = trim((string) wp_get_attachment_caption($thumbnail_id));
			if ($current_caption === '') {
				EPV2_Media::sync_attachment_details($thumbnail_id, $thumbnail_url, $title);
				$updated['caption'] = trim((string) wp_get_attachment_caption($thumbnail_id)) !== '';
			}
		}

		$quality = [];
		if (class_exists('EPV2_Quality_Gate')) {
			$quality = EPV2_Quality_Gate::record_rendered_post($post_id, $lang, $item, [
				'context' => 'post_publish_rendered',
				'repairs' => $text_repairs,
				'updated' => $updated,
			]);
		}

		return [
			'ok' => true,
			'post_id' => $post_id,
			'updated' => $updated,
			'quality' => $quality,
		];
	}

	private static function merge_repair_flags(array $base, array $next): array {
		foreach ($next as $key => $value) {
			$key = sanitize_key((string) $key);
			if ($key === '') {
				continue;
			}
			$base[$key] = ! empty($base[$key]) || ! empty($value);
		}
		return $base;
	}

	private static function remove_duplicate_excerpt_paragraphs(string $content, string $excerpt): string {
		$excerpt_plain = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($excerpt)) ?? '');
		if ($excerpt_plain === '') {
			return $content;
		}

		$pattern = '/<p\b[^>]*>(.*?)<\/p>/isu';
		preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
		if (empty($matches[0])) {
			return $content;
		}

		$remove = [];
		$seen = 0;
		foreach ($matches[0] as $index => $fullMatch) {
			$paragraph_html = (string) $fullMatch[0];
			$plain = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $matches[1][$index][0])) ?? '');
			if ($plain === '' || mb_strtolower($plain) !== mb_strtolower($excerpt_plain)) {
				continue;
			}
			$seen++;
			if ($seen >= 1) {
				$remove[] = $paragraph_html;
			}
		}

		if ($remove === []) {
			return $content;
		}

		foreach ($remove as $paragraph_html) {
			$content = preg_replace('/\s*' . preg_quote($paragraph_html, '/') . '\s*/u', "\n\n", $content, 1) ?? $content;
		}

		return trim((string) preg_replace("/\n{3,}/", "\n\n", $content));
	}

	private static function remove_duplicate_featured_media_block(string $content, int $post_id): string {
		$thumbnail_id = (int) get_post_thumbnail_id($post_id);
		$thumbnail_url = $thumbnail_id ? (string) wp_get_attachment_image_url($thumbnail_id, 'full') : '';
		if ($thumbnail_url === '') {
			return $content;
		}

		$quoted = preg_quote($thumbnail_url, '/');
		$content = preg_replace('/^\s*(?:<!-- wp:html -->)?<figure[^>]*>.*?<img[^>]+src="' . $quoted . '"[^>]*>.*?<\/figure>(?:<!-- \/wp:html -->)?\s*/isu', '', $content, 1) ?? $content;
		$content = preg_replace('/^\s*<!-- wp:image\b.*?-->.*?<img[^>]+src="' . $quoted . '"[^>]*>.*?<!-- \/wp:image -->\s*/isu', '', $content, 1) ?? $content;

		return trim($content);
	}

	private static function has_source_block(string $content): bool {
		return (bool) preg_match('/<p><strong>(?:Quelle|Джерело|Source):<\/strong>/u', $content);
	}

	private static function sync_inline_media_blocks(string $content, array $payload, string $lang, array &$updated): string {
		$inline_urls = EPV2_Media::normalize_media_list($payload['inline_media_urls'] ?? []);
		if ($inline_urls !== []) {
			return $content;
		}

		$cleaned = preg_replace('/\s*(?:<!-- wp:html -->)?<figure[^>]*europulse-inline-media[^>]*>.*?<\/figure>(?:<!-- \/wp:html -->)?\s*/isu', "\n\n", $content) ?? $content;
		if ($cleaned !== $content) {
			$updated['inline_media'] = true;
		}

		return trim((string) preg_replace("/\n{3,}/", "\n\n", $cleaned));
	}

	private static function refresh_related_and_source_blocks(string $content, int $post_id, array $categories, string $lang, string $source_url): string {
		$content = preg_replace('/\s*<!-- wp:group {"className":"epv2-related-links"} -->.*?<!-- \/wp:group -->\s*/isu', "\n\n", $content) ?? $content;
		$content = preg_replace('/\s*<p><strong>(?:Quelle|Джерело|Source):<\/strong>.*?<\/p>\s*$/isu', '', $content) ?? $content;
		$related = EPV2_Internal_Linker::block(EPV2_Internal_Linker::suggest($categories, $lang, 3, $post_id), $lang);
		$content = trim($content);
		if ($related !== '') {
			$content .= "\n\n" . $related;
		}
		if ($source_url !== '') {
			$content = EPV2_Compliance::append_source_block($content, $source_url, 'Originalquelle', $lang);
		}
		return trim($content);
	}

	private static function strip_trailing_content_artifacts(string $content): string {
		$patterns = [
			'/(?:Mehr zum Thema|More on this topic|Більше на тему).*$/isu',
			'/(?:[.!?]|”|"|»)\s*(?:Watson(?:\.de)?|BR|Goal(?:\.com)?(?:\s+Deutschland)?|Kritik\s*[—-]\s*Br)\s*$/iu',
			'/(?:Watson(?:\.de)?|BR|Goal(?:\.com)?(?:\s+Deutschland)?|Kritik\s*[—-]\s*Br)\s*$/iu',
		];
		foreach ($patterns as $pattern) {
			$content = preg_replace($pattern, '', $content) ?: $content;
		}
		return trim($content);
	}

	private static function strip_trailing_weak_quote_blocks(string $content): string {
		if (stripos($content, '<blockquote') === false) {
			return $content;
		}
		$patterns = [
			'/\s*<blockquote\b[^>]*>\s*<p>[^<]{1,220}<\/p>\s*<cite>\s*(?:Watson(?:\.de)?|BR|Goal(?:\.com)?(?:\s+Deutschland)?|Kritik\s*[—-]\s*Br)\s*<\/cite>\s*<\/blockquote>\s*$/isu',
		];
		foreach ($patterns as $pattern) {
			$content = preg_replace($pattern, '', $content) ?: $content;
		}
		return trim($content);
	}

	private static function strip_standalone_source_fragments(string $content): string {
		if (str_contains($content, '<p')) {
			return preg_replace_callback('/<p\b[^>]*>(.*?)<\/p>/isu', static function (array $matches): string {
				$html = (string) ($matches[0] ?? '');
				$plain = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) ($matches[1] ?? ''))) ?? '');
				if ($plain === '') {
					return $html;
				}
				if (preg_match('/^(?:Mehr zum Thema|More on this topic|Більше на тему)/iu', $plain) === 1) {
					return '';
				}
				if (
					mb_strlen($plain) <= 180
					&& preg_match('/(?:Watson(?:\.de)?|Goal(?:\.com)?(?:\s+Deutschland)?|Kritik\s*[—-]\s*Br|BR)\s*$/iu', $plain) === 1
				) {
					return '';
				}
				return $html;
			}, $content) ?: $content;
		}

		$blocks = preg_split('/\n\s*\n/u', $content) ?: [$content];
		$clean = [];
		foreach ($blocks as $block) {
			$plain = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $block)) ?? '');
			if ($plain === '') {
				continue;
			}
			if (preg_match('/^(?:Mehr zum Thema|More on this topic|Більше на тему)/iu', $plain) === 1) {
				continue;
			}
			if (
				mb_strlen($plain) <= 180
				&& preg_match('/(?:Watson(?:\.de)?|Goal(?:\.com)?(?:\s+Deutschland)?|Kritik\s*[—-]\s*Br|BR)\s*$/iu', $plain) === 1
			) {
				continue;
			}
			$clean[] = $block;
		}
		return trim(implode("\n\n", $clean));
	}

	private static function category_slugs(int $post_id): array {
		$terms = get_the_terms($post_id, 'category');
		if (! is_array($terms)) {
			return [];
		}
		$slugs = [];
		foreach ($terms as $term) {
			if ($term instanceof WP_Term && $term->slug !== '') {
				$slugs[] = (string) $term->slug;
			}
		}
		return array_values(array_unique($slugs));
	}

	private static function post_language(int $post_id): string {
		if (function_exists('pll_get_post_language')) {
			$lang = (string) pll_get_post_language($post_id, 'slug');
			if ($lang !== '') {
				return $lang;
			}
		}
		return 'de';
	}

	private static function sync_post_slug(int $post_id, array $payload, string $lang): bool {
		$post = get_post($post_id);
		if (! $post instanceof WP_Post) {
			return false;
		}
		$lang_payload = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
		$target = sanitize_title((string) ($lang_payload['slug'] ?? ''));
		if ($target === '') {
			return false;
		}
		$current = (string) ($post->post_name ?? '');
		if ($current === $target) {
			return false;
		}
		if ($current !== '' && ! str_contains($current, '%')) {
			return false;
		}
		wp_update_post([
			'ID' => $post_id,
			'post_name' => $target,
		]);
		return true;
	}
}
