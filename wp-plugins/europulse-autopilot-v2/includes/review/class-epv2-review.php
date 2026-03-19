<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Review {
	public static function ensure_payload(object $item): array {
		$existing = self::decode_payload((string) ($item->ai_payload ?? ''));
		if (! self::needs_rebuild($existing)) {
			if (empty($existing['_meta']['source_dossier'])) {
				$existing['_meta']['source_dossier'] = EPV2_Source_Enricher::enrich_item($item);
				$existing['_meta']['source_count'] = 1 + count((array) ($existing['_meta']['source_dossier']['supporting'] ?? []));
			}
			$existing = EPV2_AI_Response_Validator::enrich_payload($existing);
			$existing['_meta'] = is_array($existing['_meta'] ?? null) ? $existing['_meta'] : [];
			$existing['_meta']['quality'] = EPV2_AI_Response_Validator::editorial_quality($existing);
			$existing['_meta']['seo_quality'] = EPV2_AI_Response_Validator::seo_quality($existing);
			$existing['_meta']['release_quality'] = EPV2_AI_Response_Validator::release_quality($existing);
			$existing['_meta']['google_quality'] = EPV2_AI_Response_Validator::google_preflight_quality($existing);
			return $existing;
		}

		return self::ensure_payload_without_ai(
			$item,
			self::normalize_categories((string) ($item->category_proposed ?: $item->category_final ?: 'deutschland')),
			(string) EPV2_Settings::get('rewrite_style', 'lively')
		);
	}

	public static function ensure_payload_without_ai(object $item, array $categories, string $style = 'strict'): array {
		$dossier = EPV2_Source_Enricher::enrich_item($item);
		$primary_categories = array_values(array_slice(array_values(array_unique(array_filter($categories))), 0, 1));
		if ($primary_categories === []) {
			$primary_categories = ['deutschland'];
		}
		$detected_primary = EPV2_Categorizer::detect(
			(string) ($dossier['primary']['title'] ?? $item->original_title ?? ''),
			(string) ($dossier['primary']['content'] ?? $item->original_content ?? ''),
			(string) ($primary_categories[0] ?? '')
		);
		$refined_primary = EPV2_Categorizer::refine_with_event_context(
			$detected_primary !== '' ? $detected_primary : (string) ($primary_categories[0] ?? ''),
			$dossier,
			(string) ($dossier['primary']['title'] ?? $item->original_title ?? ''),
			(string) ($dossier['primary']['content'] ?? $item->original_content ?? '')
		);
		if ($refined_primary !== '') {
			$primary_categories = self::normalize_categories($refined_primary);
		}
		$de_package = self::build_language_package($item, 'de', $style);
		$payload = [
			'languages' => [],
			'categories' => $primary_categories,
			'tags' => [],
			'media_url' => (string) ($de_package['media_url'] ?? $item->source_image_url ?? ''),
			'_meta' => [
				'style' => $style,
				'breaking' => false,
				'top_story' => false,
				'breaking_hours' => (int) EPV2_Settings::get('auto_breaking_hours', 6),
				'source_dossier' => $dossier,
				'source_count' => 1 + count((array) ($dossier['supporting'] ?? [])),
				'canonical_language' => 'de',
			],
		];

		foreach (EPV2_Settings::get('publish_languages', ['de', 'uk', 'en']) as $lang) {
			$payload['languages'][$lang] = $lang === 'de'
				? $de_package
				: self::empty_language_package($lang, (string) $payload['media_url']);
		}
		$payload = EPV2_AI_Response_Validator::enrich_payload($payload);
		$payload['_meta']['quality'] = EPV2_AI_Response_Validator::editorial_quality($payload);
		$payload['_meta']['seo_quality'] = EPV2_AI_Response_Validator::seo_quality($payload);
		$payload['_meta']['release_quality'] = EPV2_AI_Response_Validator::release_quality($payload);
		$payload['_meta']['google_quality'] = EPV2_AI_Response_Validator::google_preflight_quality($payload);

		return $payload;
	}

	public static function save_payload(int $item_id, array $payload): void {
		$fields = [
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
		];
		$categories = array_values(array_filter(array_map('sanitize_text_field', (array) ($payload['categories'] ?? []))));
		if ($categories !== []) {
			$fields['category_final'] = implode(',', $categories);
		}
		EPV2_Queue::update_fields($item_id, $fields);
		$item = EPV2_Queue::get_item($item_id);
		if (! $item) {
			return;
		}
		if ($categories !== [] && in_array((string) ($item->state ?? ''), ['published', 'partially_created'], true)) {
			EPV2_Publisher::synchronize_published_bundle_taxonomy($item_id, $categories);
		}
		$mode = (string) EPV2_Settings::get('mode', 'semi');
		$default_status = (string) EPV2_Settings::get('default_post_status', 'draft');
		if (
			$item->state === 'ready_review'
			&& $mode === 'auto'
			&& in_array($default_status, ['publish', 'pending'], true)
			&& EPV2_AI_Processor::payload_is_publish_ready($payload)
		) {
			EPV2_Queue::mark_state($item_id, 'ready_publish');
		}
	}

	public static function regenerate_field(object $item, string $lang, string $field, string $style = 'strict'): array {
		$payload = self::ensure_payload($item);
		$payload['_meta']['style'] = $style;
		$payload['languages'][$lang] = $payload['languages'][$lang] ?? self::build_language_package($item, $lang, $style);
		$fresh = self::build_language_package($item, $lang, $style);

		if ($field === 'media_url') {
			$payload['media_url'] = $fresh['media_url'];
		} elseif (isset($fresh[$field])) {
			$payload['languages'][$lang][$field] = $fresh[$field];
		}

		return $payload;
	}

	public static function build_language_package(object $item, string $lang, string $style = 'strict'): array {
		$title = self::clean_text((string) ($item->original_title ?? ''));
		$excerpt_source = self::extract_excerpt($item);
		$source_body = self::clean_source_body((string) ($item->original_content ?? ''));
		$content = self::build_article_body($title, $excerpt_source, $source_body, $lang, $style);

		return [
			'title' => self::styled_title($title, $style, $lang),
			'excerpt' => self::styled_excerpt($excerpt_source, $style, 0, $lang),
			'content' => $content,
			'media_url' => (string) ($item->source_image_url ?? ''),
			'media_type' => self::detect_media_type((string) ($item->source_image_url ?? '')),
			'lang' => $lang,
		];
	}

	public static function empty_language_package(string $lang, string $media_url = ''): array {
		return [
			'title' => '',
			'excerpt' => '',
			'content' => '',
			'media_url' => $media_url,
			'media_type' => self::detect_media_type($media_url),
			'lang' => $lang,
		];
	}

	public static function decode_payload(string $json): array {
		if ($json === '') {
			return [];
		}
		$data = json_decode($json, true);
		return is_array($data) ? $data : [];
	}

	private static function needs_rebuild(array $payload): bool {
		if (empty($payload['languages']) || ! is_array($payload['languages'])) {
			return true;
		}
		if (empty($payload['categories']) || ! is_array($payload['categories'])) {
			return true;
		}
		foreach ($payload['languages'] as $lang_payload) {
			$content = (string) ($lang_payload['content'] ?? '');
			$excerpt = (string) ($lang_payload['excerpt'] ?? '');
			if ($content === '' || $excerpt === '') {
				return true;
			}
			if (stripos($content, '<li><a ') !== false || stripos($content, '&nbsp;&nbsp;') !== false) {
				return true;
			}
			if (stripos($excerpt, '&nbsp;&nbsp;') !== false) {
				return true;
			}
		}
		return false;
	}


	private static function clean_text(string $text): string {
		$text = trim(wp_strip_all_tags($text));
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/(?<!\w)‚([^‚‘]{2,}?)‘(?!\w)/u', '„$1“', $text) ?: $text;
		$text = preg_replace('/(?<!\w)‘([^‘’]{2,}?)’(?!\w)/u', '“$1”', $text) ?: $text;
		return preg_replace('/\s+/u', ' ', $text) ?: $text;
	}

	private static function sentence_limit(string $text, int $limit): string {
		$text = self::clean_text($text);
		if ($text === '') {
			return '';
		}

		if (mb_strlen($text) <= $limit) {
			return self::ensure_period($text);
		}

		if (preg_match('/^.{1,' . $limit . '}[.!?]/u', $text, $match)) {
			return self::ensure_period(trim($match[0]));
		}

		$cut = mb_substr($text, 0, $limit);
		$space = mb_strrpos($cut, ' ');
		if ($space !== false) {
			$cut = mb_substr($cut, 0, $space);
		}

		return self::ensure_period(trim($cut));
	}

	private static function ensure_period(string $text): string {
		$text = rtrim($text);
		if ($text === '') {
			return '';
		}
		if (preg_match('/[.!?…]$/u', $text)) {
			return $text;
		}
		return $text . '.';
	}

	private static function extract_excerpt(object $item): string {
		$text = self::clean_text((string) ($item->original_excerpt ?? ''));
		if ($text === '') {
			$text = self::clean_text(self::clean_source_body((string) ($item->original_content ?? '')));
		}
		if ($text === '') {
			return '';
		}

		$text = preg_replace('/\s{2,}/u', ' ', $text) ?: $text;
		$text = preg_replace('/([a-zа-яіїє])([A-ZА-ЯІЇЄ])/u', '$1. $2', $text) ?: $text;
		$text = preg_replace('/\b(Merkur|FOCUS(?: online)?|Rosenheim24|VOL\.AT|WELT|n-tv|DW|Bild|FAZ|SZ)\b/u', '', $text) ?: $text;
		$text = preg_replace('/\s*[–-]\s*$/u', '', $text) ?: $text;
		$text = preg_replace('/\s{2,}/u', ' ', $text) ?: $text;

		return trim($text);
	}

	private static function build_article_body(string $title, string $excerpt, string $source_body, string $lang, string $style): string {
		$budget = EPV2_Site_Profile::text_budget($lang, 'news');
		$lead = self::styled_excerpt($excerpt, $style, (int) $budget['lead_chars'], $lang);
		$paragraphs = [];
		if ($lead !== '') {
			$paragraphs[] = '<p>' . esc_html($lead) . '</p>';
		}

		$quote = self::extract_quote($source_body, $lang);
		if ($quote !== '') {
			$paragraphs[] = '<blockquote><p>' . esc_html($quote) . '</p></blockquote>';
		}

		$body_parts = self::source_paragraphs($source_body, 7);
		foreach ($body_parts as $part) {
			if ($part !== '' && ! in_array('<p>' . esc_html($part) . '</p>', $paragraphs, true)) {
				$paragraphs[] = '<p>' . esc_html($part) . '</p>';
			}
		}

		if (count($paragraphs) < 4) {
			$bridge = self::context_bridge($title, $excerpt, $lang);
			if ($bridge !== '') {
				$paragraphs[] = '<p>' . esc_html($bridge) . '</p>';
			}
		}

		return implode("\n\n", $paragraphs);
	}

	private static function clean_source_body(string $text): string {
		$text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\b(?:Bild|Photo|Foto|Фото|Fotó|Image|Источник изображения|Credit)\s*:\s*[^\n\r\.]{2,180}(?:[\.]|$)/u', ' ', $text) ?: $text;
		$text = preg_replace('/\b(?:picture alliance|Getty Images|AP Photo|dpa|AFP|Reuters|Mercur|Merkur|Bild)\b[^.]{0,120}(?:[\.]|$)/iu', ' ', $text) ?: $text;
		$text = preg_replace('/([a-zа-яіїєß]{6,})([A-ZÄÖÜА-ЯІЇЄ])/u', '$1 $2', $text) ?: $text;
		$text = preg_replace('/\s+/u', ' ', $text) ?: $text;
		$noise = [
			'Wir verwenden Cookies',
			'Datenschutz',
			'Cookie',
			'Privacy',
			'Matomo',
			'Zustimmen',
			'Ablehnen',
			'Newsletter abonnieren',
			'Skip to content',
		];
		foreach ($noise as $piece) {
			$text = str_ireplace($piece, ' ', $text);
		}
		$text = preg_replace('/§\s*\d+[a-z]?(?:\s*Abs\.\s*\d+)?(?:\s*Satz\s*\d+)?(?:\s*[A-Z][A-Za-z.-]+)?/u', 'die Regelung', $text) ?: $text;
		$text = preg_replace('/\b[A-ZÄÖÜ][A-Za-zÄÖÜäöüß-]{5,}\s*\(\w+\)\b/u', '$1', $text) ?: $text;
		$text = preg_replace('/\b(gesetz|verordnung|richtlinie|paragraph)\b/iu', 'Regel', $text) ?: $text;
		return trim((string) preg_replace('/\s{2,}/u', ' ', $text));
	}

	private static function source_paragraphs(string $text, int $limit = 3): array {
		$text = self::clean_text($text);
		if ($text === '') {
			return [];
		}
		$sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
		$paragraphs = [];
		$current = '';
		foreach ($sentences as $sentence) {
			$sentence = trim($sentence);
			if ($sentence === '' || mb_strlen($sentence) < 40) {
				continue;
			}
			if (preg_match('/\b(?:Bild|Photo|Foto|Фото|Credit)\s*:/u', $sentence)) {
				continue;
			}
			if (preg_match('/^[\p{L}\p{N}\s-]{0,40}$/u', $sentence) && mb_strlen($sentence) < 70) {
				continue;
			}
			$next = trim($current . ' ' . $sentence);
			if (mb_strlen($next) > 420 && $current !== '') {
				$paragraphs[] = self::ensure_period($current);
				$current = $sentence;
			} else {
				$current = $next;
			}
			if (count($paragraphs) >= $limit) {
				break;
			}
		}
		if ($current !== '' && count($paragraphs) < $limit) {
			$paragraphs[] = self::ensure_period($current);
		}
		return array_slice(array_values(array_filter($paragraphs)), 0, $limit);
	}

	private static function extract_quote(string $text, string $lang): string {
		$text = trim((string) $text);
		if ($text === '') {
			return '';
		}
		if (preg_match('/[„"](.*?)[“"]/u', $text, $match)) {
			$quote = self::clean_text((string) $match[1]);
			if (mb_strlen($quote) >= 40) {
				return self::sentence_limit($quote, 220);
			}
		}
		$patterns = [
			'/\b(sagte|erklärte|betonte|warnte)\b[^.]{0,220}\./iu',
			'/\b(said|stressed|warned|explained)\b[^.]{0,220}\./iu',
			'/\b(заявив|пояснив|наголосив|попередив)\b[^.]{0,220}\./iu',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $text, $match)) {
				$quote = self::clean_text((string) $match[0]);
				if (mb_strlen($quote) >= 50) {
					return self::sentence_limit($quote, 220);
				}
			}
		}
		return '';
	}

	private static function context_bridge(string $title, string $excerpt, string $lang): string {
		$seed = trim(self::clean_text($excerpt !== '' ? $excerpt : $title));
		if ($seed === '') {
			return '';
		}
		return match ($lang) {
			'uk' => self::sentence_limit('Тема привертає увагу не лише фактом події, а й тим, як вона може вплинути на повсякденне життя, рішення влади або подальший розвиток ситуації. ' . $seed, 320),
			'en' => self::sentence_limit('The story matters not only because of the event itself, but because it can influence daily life, official decisions or the next phase of the situation. ' . $seed, 320),
			default => self::sentence_limit('Die Geschichte ist nicht nur wegen des Ereignisses wichtig, sondern auch wegen ihrer Folgen für den Alltag, politische Entscheidungen oder die weitere Entwicklung. ' . $seed, 320),
		};
	}

	private static function styled_title(string $title, string $style, string $lang = 'de'): string {
		$budget = EPV2_Site_Profile::text_budget($lang, 'news');
		$limit = (int) ($budget['title_chars'] ?? match ($style) {
			'analytic' => 90,
			'lively' => 78,
			default => 84,
		});
		return self::sentence_limit($title, $limit);
	}

	private static function styled_excerpt(string $excerpt, string $style, int $override_limit = 0, string $lang = 'de'): string {
		$budget = EPV2_Site_Profile::text_budget($lang, 'news');
		$limit = $override_limit ?: (int) ($budget['lead_chars'] ?? match ($style) {
			'analytic' => 200,
			'lively' => 170,
			default => 180,
		});
		$text = self::two_sentence_limit($excerpt, $limit);
		$text = preg_replace('/^(Warum das wichtig ist|Контекст|Розширений контекст|Expanded context|Why this matters)\s*[:\-]\s*/iu', '', $text) ?: $text;
		return $text;
	}

	private static function two_sentence_limit(string $text, int $limit): string {
		$text = self::clean_text($text);
		if ($text === '') {
			return '';
		}
		$sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
		$sentences = array_values(array_filter(array_map('trim', $sentences)));
		if (! empty($sentences)) {
			$text = implode(' ', array_slice($sentences, 0, 2));
		}
		return self::sentence_limit($text, $limit);
	}

	public static function normalize_categories(string $value): array {
		$allowed = array_keys(EPV2_Taxonomy_Map::categories());
		$parts = array_values(array_filter(array_map('sanitize_text_field', array_map('trim', explode(',', $value)))));
		$parts = array_values(array_filter($parts, static fn(string $slug): bool => in_array($slug, $allowed, true)));
		$parts = array_values(array_unique($parts));
		if ($parts === []) {
			return ['deutschland'];
		}
		return array_slice($parts, 0, 1);
	}

	private static function detect_media_type(string $url): string {
		if ($url === '') {
			return '';
		}
		$path = strtolower((string) parse_url($url, PHP_URL_PATH));
		if (preg_match('/\.(mp4|webm|ogg|mov|m4v)$/', $path)) {
			return 'video';
		}
		return 'image';
	}
}
