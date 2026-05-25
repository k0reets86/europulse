<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Quality_Gate {
	public static function evaluate(?object $item, array $payload = [], array $context = []): array {
		$blockers = [];
		$warnings = [];
		$signals = [
			'context' => sanitize_key((string) ($context['context'] ?? 'publish_gate_shadow')),
		];

		if ($payload === []) {
			return [
				'verdict' => 'reject',
				'score' => 0,
				'blockers' => ['missing_payload'],
				'warnings' => [],
				'signals' => $signals,
			];
		}

		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$signals['content_kind'] = class_exists('EPV2_Content_Kinds') ? EPV2_Content_Kinds::detect_kind($payload) : '';
		$signals['source'] = self::source_signals($item);
		$signals['dossier'] = self::dossier_signals($payload);
		$signals['languages'] = self::language_signals($payload);
		$signals['publish_gate'] = [
			'allowed_before_filter' => ! empty($context['publish_gate_allowed_before_filter']),
			'blockers' => array_values(array_filter(array_map('sanitize_key', (array) ($context['publish_gate_blockers'] ?? [])))),
			'selection_decision' => sanitize_key((string) ($context['selection_decision'] ?? '')),
		];

		self::check_source_fitness($item, $payload, $signals, $blockers, $warnings);
		self::check_fact_integrity($payload, $signals, $blockers, $warnings);
		self::check_payload_integrity($item, $payload, $signals, $blockers, $warnings);
		self::check_language_quality($payload, $signals, $blockers, $warnings);
		self::check_media_integrity($payload, $signals, $blockers, $warnings);
		self::check_seo_integrity($payload, $signals, $blockers, $warnings);

		if (empty($meta['story_card']) || ! is_array($meta['story_card'])) {
			$warnings[] = 'story_card_missing';
		}
		if (empty($meta['source_dossier']) || ! is_array($meta['source_dossier'])) {
			$warnings[] = 'source_dossier_missing';
		}
		if (empty($meta['worker_prompt_version'])) {
			$warnings[] = 'worker_prompt_version_missing';
		}
		if (empty($meta['editorial_prompt_version'])) {
			$warnings[] = 'editorial_prompt_version_missing';
		}

		$blockers = array_values(array_unique(array_filter($blockers)));
		$warnings = array_values(array_unique(array_filter($warnings)));
		$score = max(0, min(100, 100 - (count($blockers) * 15) - (count($warnings) * 4)));
		$verdict = $blockers === [] ? ($score >= 85 ? 'pass' : 'review') : 'review';

		return [
			'verdict' => $verdict,
			'score' => $score,
			'blockers' => $blockers,
			'warnings' => $warnings,
			'signals' => $signals,
		];
	}

	public static function record_shadow(?object $item, array $payload, array $context = []): array {
		$result = self::evaluate($item, $payload, $context);
		if (class_exists('EPV2_Quality_Audit')) {
			EPV2_Quality_Audit::record([
				'queue_id' => $item ? (int) ($item->id ?? 0) : 0,
				'post_id' => $item ? (int) ($item->post_id ?? 0) : 0,
				'source_id' => $item ? (int) ($item->source_id ?? 0) : 0,
				'phase' => 'publish_gate_shadow',
				'verdict' => $result['verdict'],
				'score' => $result['score'],
				'blockers' => $result['blockers'],
				'warnings' => $result['warnings'],
				'signals' => $result['signals'],
				'context_json' => $context,
			]);
		}
		return $result;
	}

	public static function evaluate_rendered_text(string $lang, string $title, string $excerpt, string $content, array $context = []): array {
		$lang = sanitize_key($lang) ?: 'de';
		$text = trim(wp_strip_all_tags($title . ' ' . $excerpt . ' ' . $content));
		$blockers = [];
		$warnings = [];
		$signals = [
			'context' => sanitize_key((string) ($context['context'] ?? 'post_publish_rendered')),
			'lang' => $lang,
			'chars' => mb_strlen($text),
			'post_id' => (int) ($context['post_id'] ?? 0),
			'queue_id' => (int) ($context['queue_id'] ?? 0),
		];

		if ($text === '') {
			$blockers[] = 'rendered_empty';
		}

		if ($lang === 'en' && preg_match('/[А-Яа-яІіЇїЄєҐґ]/u', $text) === 1) {
			$blockers[] = 'en_cyrillic_leak';
		}

		if ($lang === 'uk') {
			$glue = self::detect_uk_sentence_glue($text);
			$placeholders = self::detect_placeholder_link_text($text);
			$broken_urls = self::detect_broken_transliterated_urls($text);
			$bad_brands = self::detect_bad_brand_transliterations($text);
			$signals['uk_rendered'] = [
				'sentence_glue_count' => count($glue),
				'sentence_glue_examples' => array_slice($glue, 0, 5),
				'placeholder_link_count' => count($placeholders),
				'placeholder_link_examples' => array_slice($placeholders, 0, 5),
				'broken_transliterated_url_count' => count($broken_urls),
				'broken_transliterated_url_examples' => array_slice($broken_urls, 0, 5),
				'bad_brand_count' => count($bad_brands),
				'bad_brand_examples' => array_slice($bad_brands, 0, 8),
			];
			if ($glue !== []) {
				$blockers[] = 'uk_sentence_glue';
			}
			if ($placeholders !== []) {
				$blockers[] = 'placeholder_link_text';
			}
			if ($broken_urls !== []) {
				$blockers[] = 'broken_transliterated_url';
			}
			if ($bad_brands !== []) {
				$blockers[] = 'uk_bad_brand_transliteration';
			}
			if (preg_match('/\b(?:und|oder|nicht|werden|sagte|according|officials)\b/iu', $text) === 1) {
				$warnings[] = 'uk_possible_foreign_language_leak';
			}
		}

		if (! empty($context['repairs']) && is_array($context['repairs'])) {
			foreach (array_keys(array_filter($context['repairs'])) as $repair) {
				$warnings[] = 'rendered_repaired_' . sanitize_key((string) $repair);
			}
		}

		$blockers = array_values(array_unique(array_filter($blockers)));
		$warnings = array_values(array_unique(array_filter($warnings)));
		$score = max(0, min(100, 100 - (count($blockers) * 18) - (count($warnings) * 3)));
		$verdict = $blockers === [] ? ($score >= 85 ? 'pass' : 'review') : 'review';

		return [
			'verdict' => $verdict,
			'score' => $score,
			'blockers' => $blockers,
			'warnings' => $warnings,
			'signals' => $signals,
		];
	}

	public static function record_rendered_post(int $post_id, string $lang = '', ?object $item = null, array $context = []): array {
		$post = $post_id > 0 ? get_post($post_id) : null;
		if (! $post instanceof WP_Post) {
			return [
				'verdict' => 'reject',
				'score' => 0,
				'blockers' => ['missing_post'],
				'warnings' => [],
				'signals' => ['context' => 'post_publish_rendered', 'post_id' => $post_id],
			];
		}

		$lang = sanitize_key($lang);
		if ($lang === '' && function_exists('pll_get_post_language')) {
			$lang = sanitize_key((string) pll_get_post_language($post_id, 'slug'));
		}
		if ($lang === '') {
			$lang = 'de';
		}

		$queue_id = (int) get_post_meta($post_id, '_epv2_queue_id', true);
		$source_id = $item ? (int) ($item->source_id ?? 0) : 0;
		$context['context'] = $context['context'] ?? 'post_publish_rendered';
		$context['post_id'] = $post_id;
		$context['queue_id'] = $queue_id;
		$result = self::evaluate_rendered_text(
			$lang,
			(string) $post->post_title,
			(string) $post->post_excerpt,
			(string) $post->post_content,
			$context
		);

		if (class_exists('EPV2_Quality_Audit')) {
			EPV2_Quality_Audit::record([
				'queue_id' => $queue_id ?: ($item ? (int) ($item->id ?? 0) : 0),
				'post_id' => $post_id,
				'source_id' => $source_id ?: ($item ? (int) ($item->source_id ?? 0) : 0),
				'phase' => 'post_publish_rendered',
				'verdict' => $result['verdict'],
				'score' => $result['score'],
				'blockers' => $result['blockers'],
				'warnings' => $result['warnings'],
				'signals' => $result['signals'],
				'context_json' => $context,
			]);
		}

		return $result;
	}

	public static function repair_rendered_text(string $text, string $lang): array {
		$lang = sanitize_key($lang) ?: 'de';
		$repaired = $text;
		$repairs = [
			'uk_sentence_glue' => false,
			'placeholder_link_text' => false,
			'broken_transliterated_url' => false,
			'uk_brand_transliteration' => false,
			'uk_grammar' => false,
		];

		if ($lang !== 'uk' || $repaired === '') {
			return ['text' => $text, 'changed' => false, 'repairs' => $repairs];
		}

		$next = preg_replace('/([А-Яа-яІіЇїЄєҐґ0-9»”])([.!?])([А-ЯІЇЄҐ])/u', '$1$2 $3', $repaired) ?? $repaired;
		if ($next !== $repaired) {
			$repairs['uk_sentence_glue'] = true;
			$repaired = $next;
		}

		$next = preg_replace('/(<\/p>)\s*(<h[2-6]\b)/iu', "$1\n\n$2", $repaired) ?? $repaired;
		$next = preg_replace('/(<\/h[2-6]>)\s*(<p\b)/iu', "$1\n\n$2", $next) ?? $next;
		$next = preg_replace('/(<\/p>)\s*(<p\b)/iu', "$1\n\n$2", $next) ?? $next;
		if ($next !== $repaired) {
			$repairs['uk_sentence_glue'] = true;
			$repaired = $next;
		}

		$next = preg_replace('/\s*\(\s*<a\b[^>]*>\s*(?:посилання|посилання\s+на\s+статтю|link)\s*<\/a>\s*\)/iu', '', $repaired) ?? $repaired;
		$next = preg_replace('/\s*\((?:посилання|посилання\s+на\s+статтю|link)\)/iu', '', $next) ?? $next;
		if ($next !== $repaired) {
			$repairs['placeholder_link_text'] = true;
			$repaired = $next;
		}

		$next = preg_replace('/\s*\((?:гттпс?|гттп|хттпс?|хттп):\/\/[^)]*\)/iu', '', $repaired) ?? $repaired;
		$next = preg_replace('/\s*(?:гттпс?|гттп|хттпс?|хттп):\/\/[^\s<)]+/iu', '', $next) ?? $next;
		if ($next !== $repaired) {
			$repairs['broken_transliterated_url'] = true;
			$repaired = $next;
		}

		foreach (self::uk_brand_preservation_map() as $bad => $canonical) {
			$next = str_replace($bad, $canonical, $repaired);
			if ($next !== $repaired) {
				$repairs['uk_brand_transliteration'] = true;
				$repaired = $next;
			}
		}

		$next = self::repair_uk_grammar_fragments($repaired);
		if ($next !== $repaired) {
			$repairs['uk_grammar'] = true;
			$repaired = $next;
		}

		return [
			'text' => $repaired,
			'changed' => $repaired !== $text,
			'repairs' => $repairs,
		];
	}

	private static function repair_uk_grammar_fragments(string $text): string {
		if ($text === '') {
			return $text;
		}
		$text = preg_replace('/\bсвоє\s+дипломатичне\s+персонал\b/iu', 'свій дипломатичний персонал', $text) ?? $text;
		$text = preg_replace('/\bсвоє\s+дипломатичний\s+персонал\b/iu', 'свій дипломатичний персонал', $text) ?? $text;
		$text = preg_replace('/\bдипломатичне\s+персонал\b/iu', 'дипломатичний персонал', $text) ?? $text;
		return $text;
	}

	private static function check_source_fitness(?object $item, array $payload, array $signals, array &$blockers, array &$warnings): void {
		$source = is_array($signals['source'] ?? null) ? $signals['source'] : [];
		if (! empty($source['loaded'])) {
			if (empty($source['is_active'])) {
				$blockers[] = 'source_inactive';
			}
			$risk = sanitize_key((string) ($source['risk_level'] ?? ''));
			if (in_array($risk, ['high', 'moderate'], true)) {
				$warnings[] = 'source_risk_' . $risk;
			}
		}

		if (class_exists('EPV2_AI_Response_Validator') && EPV2_AI_Response_Validator::source_dossier_thin_signal($payload)) {
			if (self::thin_source_is_soft_context($signals)) {
				$warnings[] = 'thin_source_dossier';
			} else {
				$blockers[] = 'thin_source_dossier';
			}
		}

		$dossier = is_array($signals['dossier'] ?? null) ? $signals['dossier'] : [];
		if ((int) ($dossier['primary_chars'] ?? 0) < 90) {
			$warnings[] = 'primary_source_very_short';
		}
		if ((int) ($dossier['real_supporting_count'] ?? 0) === 0 && (int) ($dossier['title_only_supporting_count'] ?? 0) > 0) {
			$warnings[] = 'title_only_supporting_sources';
		}
	}

	private static function check_fact_integrity(array $payload, array &$signals, array &$blockers, array &$warnings): void {
		if (! class_exists('EPV2_AI_Response_Validator')) {
			return;
		}

		$invented_numbers = EPV2_AI_Response_Validator::detect_invented_numbers($payload);
		$invented_quotes = EPV2_AI_Response_Validator::detect_invented_quote_attributions($payload);
		$title_substitutions = EPV2_AI_Response_Validator::detect_cross_lang_title_substitution($payload);
		$invented_publishers = EPV2_AI_Response_Validator::detect_invented_publishers($payload);

		$signals['fact_integrity'] = [
			'invented_numbers' => count($invented_numbers),
			'invented_quotes' => count($invented_quotes),
			'title_substitutions' => count($title_substitutions),
			'invented_publishers' => count($invented_publishers),
		];

		if ($invented_numbers !== []) {
			if (count($invented_numbers) === 1 && ! self::fact_context_high_risk($signals)) {
				$warnings[] = 'unsupported_numbers_soft';
			} else {
				$blockers[] = 'unsupported_numbers';
			}
		}
		if ($invented_quotes !== []) {
			$blockers[] = 'unsupported_quote_attributions';
		}
		if ($title_substitutions !== []) {
			$blockers[] = 'cross_lang_title_substitution';
		}
		if ($invented_publishers !== []) {
			$blockers[] = 'unsupported_publisher_attribution';
		}
	}

	private static function check_payload_integrity(?object $item, array $payload, array &$signals, array &$blockers, array &$warnings): void {
		foreach (['de', 'uk', 'en'] as $lang) {
			$pkg = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
			if (trim((string) ($pkg['title'] ?? '')) === '') {
				$blockers[] = 'missing_' . $lang . '_title';
			}
			if (trim(wp_strip_all_tags((string) ($pkg['content'] ?? $pkg['body_html'] ?? ''))) === '') {
				$blockers[] = 'missing_' . $lang . '_content';
			}
		}

		$category_proposed = sanitize_key((string) ($item->category_proposed ?? ''));
		$payload_categories = array_values((array) ($payload['categories'] ?? []));
		$payload_category = sanitize_key((string) ($payload_categories[0] ?? ''));
		$card = is_array($payload['_meta']['story_card'] ?? null) ? $payload['_meta']['story_card'] : [];
		$card_category = sanitize_key((string) ($card['category']['primary'] ?? ''));
		$card_confidence = (float) ($card['category']['confidence'] ?? 0);
		$signals['category'] = [
			'item_category_proposed' => $category_proposed,
			'payload_category' => $payload_category,
			'story_card_category' => $card_category,
			'story_card_confidence' => $card_confidence,
		];
		if ($category_proposed !== '' && $payload_category !== '' && $category_proposed !== $payload_category) {
			if ($card_category === $payload_category && $card_confidence >= 0.7) {
				$warnings[] = 'category_changed_by_high_confidence_story_card';
			} else {
				$blockers[] = 'category_drift';
			}
		}
	}

	private static function check_language_quality(array $payload, array &$signals, array &$blockers, array &$warnings): void {
		$en = self::language_text($payload, 'en');
		$uk = self::language_text($payload, 'uk');

		if ($en !== '' && preg_match('/[А-Яа-яІіЇїЄєҐґ]/u', $en) === 1) {
			$blockers[] = 'en_cyrillic_leak';
		}
		if ($uk !== '' && preg_match('/\b(?:und|oder|nicht|werden|sagte|according|officials)\b/iu', $uk) === 1) {
			$warnings[] = 'uk_possible_foreign_language_leak';
		}
		$uk_glue = $uk !== '' ? self::detect_uk_sentence_glue($uk) : [];
		$uk_placeholders = $uk !== '' ? self::detect_placeholder_link_text($uk) : [];
		$uk_broken_urls = $uk !== '' ? self::detect_broken_transliterated_urls($uk) : [];
		$uk_bad_brands = $uk !== '' ? self::detect_bad_brand_transliterations($uk) : [];
		if ($uk_glue !== []) {
			$blockers[] = 'uk_sentence_glue';
		}
		if ($uk_placeholders !== []) {
			$blockers[] = 'placeholder_link_text';
		}
		if ($uk_broken_urls !== []) {
			$blockers[] = 'broken_transliterated_url';
		}
		if ($uk_bad_brands !== []) {
			$blockers[] = 'uk_bad_brand_transliteration';
		}

		$signals['language_quality'] = [
			'en_has_cyrillic' => $en !== '' && preg_match('/[А-Яа-яІіЇїЄєҐґ]/u', $en) === 1,
			'uk_possible_foreign_leak' => $uk !== '' && preg_match('/\b(?:und|oder|nicht|werden|sagte|according|officials)\b/iu', $uk) === 1,
			'uk_sentence_glue_count' => count($uk_glue),
			'uk_placeholder_link_count' => count($uk_placeholders),
			'uk_broken_transliterated_url_count' => count($uk_broken_urls),
			'uk_broken_transliterated_url_examples' => array_slice($uk_broken_urls, 0, 5),
			'uk_bad_brand_count' => count($uk_bad_brands),
			'uk_bad_brand_examples' => array_slice($uk_bad_brands, 0, 8),
		];
	}

	private static function check_media_integrity(array $payload, array &$signals, array &$blockers, array &$warnings): void {
		$url = trim((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''));
		$signals['media'] = [
			'has_featured_url' => $url !== '',
			'host' => $url !== '' ? (string) wp_parse_url($url, PHP_URL_HOST) : '',
		];
		if ($url === '') {
			$blockers[] = 'media_missing';
			return;
		}
		if (preg_match('/<[^>]+>/', $url) === 1 || ! wp_http_validate_url($url)) {
			$blockers[] = 'media_url_invalid';
			return;
		}
		$host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		if ($host !== '' && preg_match('/(^|\.)(wikimedia\.org|pexels\.com)$/i', $host) === 1) {
			$blockers[] = 'generic_stock_featured_media';
		}
	}

	private static function check_seo_integrity(array $payload, array &$signals, array &$blockers, array &$warnings): void {
		foreach (['de', 'uk', 'en'] as $lang) {
			$pkg = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
			if (trim((string) ($pkg['seo_title'] ?? '')) === '') {
				$warnings[] = 'missing_' . $lang . '_seo_title';
			}
			if (trim((string) ($pkg['meta_description'] ?? '')) === '') {
				$warnings[] = 'missing_' . $lang . '_meta_description';
			}
			if (trim((string) ($pkg['slug'] ?? '')) === '') {
				$warnings[] = 'missing_' . $lang . '_slug';
			}
		}
		$signals['seo_checked'] = true;
	}

	private static function source_signals(?object $item): array {
		global $wpdb;

		$source_id = $item ? (int) ($item->source_id ?? 0) : 0;
		if ($source_id <= 0 || ! isset($wpdb) || ! $wpdb instanceof wpdb) {
			return ['loaded' => false, 'source_id' => $source_id];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, is_active, risk_level, priority, is_top_tier, is_aggregator
				FROM {$wpdb->prefix}epv2_sources
				WHERE id = %d
				LIMIT 1",
				$source_id
			),
			ARRAY_A
		);
		if (! is_array($row)) {
			return ['loaded' => false, 'source_id' => $source_id];
		}

		return [
			'loaded' => true,
			'source_id' => (int) $row['id'],
			'name' => (string) $row['name'],
			'is_active' => (bool) $row['is_active'],
			'risk_level' => sanitize_key((string) $row['risk_level']),
			'priority' => (int) $row['priority'],
			'is_top_tier' => (bool) $row['is_top_tier'],
			'is_aggregator' => (bool) $row['is_aggregator'],
		];
	}

	private static function dossier_signals(array $payload): array {
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$primary = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
		$primary_chars = mb_strlen(trim(wp_strip_all_tags(
			(string) ($primary['content'] ?? '') . ' ' . (string) ($primary['excerpt'] ?? '')
		)));
		$real_supporting = 0;
		$title_only = 0;
		foreach ((array) ($dossier['supporting'] ?? []) as $entry) {
			if (! is_array($entry)) {
				continue;
			}
			$text_len = mb_strlen(trim(wp_strip_all_tags(
				(string) ($entry['content'] ?? '') . ' ' . (string) ($entry['excerpt'] ?? '')
			)));
			if ($text_len >= 100) {
				$real_supporting++;
			} elseif (trim((string) ($entry['url'] ?? $entry['title'] ?? '')) !== '') {
				$title_only++;
			}
		}

		return [
			'primary_chars' => $primary_chars,
			'real_supporting_count' => $real_supporting,
			'title_only_supporting_count' => $title_only,
		];
	}

	private static function language_signals(array $payload): array {
		$out = [];
		foreach (['de', 'uk', 'en'] as $lang) {
			$text = self::language_text($payload, $lang);
			$out[$lang] = [
				'chars' => mb_strlen($text),
				'has_title' => trim((string) ($payload['languages'][$lang]['title'] ?? '')) !== '',
			];
		}
		return $out;
	}

	private static function language_text(array $payload, string $lang): string {
		$pkg = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
		return trim(wp_strip_all_tags(
			(string) ($pkg['title'] ?? '') . ' '
			. (string) ($pkg['excerpt'] ?? '') . ' '
			. (string) ($pkg['content'] ?? $pkg['body_html'] ?? '')
		));
	}

	private static function thin_source_is_soft_context(array $signals): bool {
		$source = is_array($signals['source'] ?? null) ? $signals['source'] : [];
		$dossier = is_array($signals['dossier'] ?? null) ? $signals['dossier'] : [];
		$publish_gate = is_array($signals['publish_gate'] ?? null) ? $signals['publish_gate'] : [];
		$pg_blockers = array_values((array) ($publish_gate['blockers'] ?? []));

		if (in_array('thin_source_dossier', $pg_blockers, true)) {
			return false;
		}
		if ((int) ($dossier['primary_chars'] ?? 0) < 120 && (int) ($dossier['real_supporting_count'] ?? 0) === 0) {
			return false;
		}
		if (! empty($publish_gate['allowed_before_filter'])) {
			return true;
		}
		if (! empty($source['is_top_tier'])) {
			return true;
		}
		return in_array((string) ($publish_gate['selection_decision'] ?? ''), ['strong', 'review'], true);
	}

	private static function fact_context_high_risk(array $signals): bool {
		$dossier = is_array($signals['dossier'] ?? null) ? $signals['dossier'] : [];
		$publish_gate = is_array($signals['publish_gate'] ?? null) ? $signals['publish_gate'] : [];
		$pg_blockers = array_values((array) ($publish_gate['blockers'] ?? []));
		return (int) ($dossier['primary_chars'] ?? 0) < 300
			|| (int) ($dossier['real_supporting_count'] ?? 0) === 0
			|| in_array('thin_source_dossier', $pg_blockers, true);
	}

	private static function detect_uk_sentence_glue(string $text): array {
		if ($text === '') {
			return [];
		}
		preg_match_all('/[а-яіїєґ0-9»”][.!?][А-ЯІЇЄҐ]/u', $text, $matches);
		return array_values(array_unique(array_slice($matches[0] ?? [], 0, 20)));
	}

	private static function detect_placeholder_link_text(string $text): array {
		if ($text === '') {
			return [];
		}
		preg_match_all('/\(\s*(?:<a\b[^>]*>\s*)?(?:посилання|посилання\s+на\s+статтю|link)(?:\s*<\/a>)?\s*\)/iu', $text, $matches);
		return array_values(array_unique(array_slice($matches[0] ?? [], 0, 20)));
	}

	private static function detect_broken_transliterated_urls(string $text): array {
		if ($text === '') {
			return [];
		}
		preg_match_all('/(?:гттпс?|гттп|хттпс?|хттп):\/\/[^\s<)]+/iu', $text, $matches);
		return array_values(array_unique(array_slice($matches[0] ?? [], 0, 20)));
	}

	private static function detect_bad_brand_transliterations(string $text): array {
		if ($text === '') {
			return [];
		}
		$hits = [];
		foreach (self::uk_brand_preservation_map() as $bad => $canonical) {
			if (mb_strpos($text, $bad) !== false) {
				$hits[] = $bad . '=>' . $canonical;
			}
		}
		return array_values(array_unique($hits));
	}

	private static function uk_brand_preservation_map(): array {
		return [
			'ОйроПулсе' => 'EuroPulse',
			'ОйроПульсе' => 'EuroPulse',
			'ЄвроПулсе' => 'EuroPulse',
			'Європульсе' => 'EuroPulse',
			'Ваимо' => 'Waymo',
			'Ваймо' => 'Waymo',
				'Гайсе' => 'Heise',
				'Хайзе' => 'Heise',
				'ТехКрунх' => 'TechCrunch',
				'ТехКранч' => 'TechCrunch',
				'ТекКранч' => 'TechCrunch',
				'Нью-Йорк Таймс' => 'The New York Times',
				'Нью Йорк Таймс' => 'The New York Times',
				'Вашингтон Пост' => 'The Washington Post',
				'Рейтерс' => 'Reuters',
				'Блумберг' => 'Bloomberg',
				'Бі-бі-сі' => 'BBC',
				'Бі Бі Сі' => 'BBC',
				'Валл Стреет' => 'Wall Street',
				'Валл Стріт' => 'Wall Street',
				'Валл-стріт' => 'Wall Street',
				'Валл-Стріт' => 'Wall Street',
				'Волл Стріт' => 'Wall Street',
				'Волл-стріт' => 'Wall Street',
				'Волл-Стріт' => 'Wall Street',
				'Уолл Стріт' => 'Wall Street',
				'Уолл-стріт' => 'Wall Street',
				'Уолл-Стріт' => 'Wall Street',
				'24тв' => '24tv',
				'24ТВ' => '24tv',
			'Киівпост' => 'Kyiv Post',
			'Київпост' => 'Kyiv Post',
			'Кіївпост' => 'Kyiv Post',
			'Киів Пост' => 'Kyiv Post',
			'Київ Пост' => 'Kyiv Post',
			'Кіїв Пост' => 'Kyiv Post',
			'Украінска Правда' => 'Українська правда',
			'Украінска правда' => 'Українська правда',
			'Украінська Правда' => 'Українська правда',
			'Українска Правда' => 'Українська правда',
			'Українска правда' => 'Українська правда',
			'Українська Правда' => 'Українська правда',
			'Тагесспігел' => 'Tagesspiegel',
			'Дойтше Велле' => 'Deutsche Welle',
			'Фінанке.уа' => 'Finance.ua',
			'Поліке Аукс Фронтіèрес' => 'Police aux frontières',
			'Багн.де' => 'Bahn.de',
			'багн.де' => 'Bahn.de',
			'Дойтше Багн' => 'Deutsche Bahn',
			'Дойтшландфунк' => 'Deutschlandfunk',
			'Дойчландфунк' => 'Deutschlandfunk',
			'Süddeutsche Цайтунг' => 'Süddeutsche Zeitung',
			'Зюддойче Цайтунг' => 'Süddeutsche Zeitung',
			'Зюддойче Zeitung' => 'Süddeutsche Zeitung',
			'МагентаСпорт' => 'MagentaSport',
			'Магента Спорт' => 'MagentaSport',
			'ПроСібен' => 'ProSieben',
			'Про Сібен' => 'ProSieben',
			'Лінукс' => 'Linux',
			'Голем' => 'Golem',
			'Флікстраін' => 'Flixtrain',
			'ГатеАід' => 'HateAid',
			'Смарт Метер' => 'Smart Meter',
		];
	}
}
