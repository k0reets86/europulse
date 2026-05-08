<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Content-kind taxonomy for EuroPulse.
 *
 * Replaces ad-hoc brief-ticker exemptions with explicit per-kind rules.
 * Each kind specifies its own length, source-count, enrichment, and
 * quality-score requirements — calibrated against real publishing
 * standards at top wire services and German/international news outlets.
 *
 * Detection priority (highest first):
 *   1. obituary       — death/Nachruf signals in title or excerpt
 *   2. sport_result   — category=sport with score/match indicators
 *   3. breaking_alert — story_card.kind=live_ticker AND thin source AND fresh
 *   4. feature        — story_card.kind=feature OR very-long source
 *   5. analysis       — story_card.kind=analysis OR rich-topic items
 *   6. extended_news  — multiple topics + entities + multi-source potential
 *   7. news_article   — default for items with ≥2 source potential
 *   8. news_brief     — single thin source, length_profile=brief
 */
final class EPV2_Content_Kinds {

	const KIND_BREAKING_ALERT = 'breaking_alert';
	const KIND_NEWS_BRIEF     = 'news_brief';
	const KIND_NEWS_ARTICLE   = 'news_article';
	const KIND_EXTENDED_NEWS  = 'extended_news';
	const KIND_ANALYSIS       = 'analysis';
	const KIND_FEATURE        = 'feature';
	const KIND_SPORT_RESULT   = 'sport_result';
	const KIND_OBITUARY       = 'obituary';
	const KIND_INTERVIEW      = 'interview';
	const KIND_OPINION        = 'opinion';
	const KIND_EXPLAINER      = 'explainer';
	const KIND_LIVE_BLOG      = 'live_blog';

	public static function all_kinds(): array {
		return [
			self::KIND_BREAKING_ALERT,
			self::KIND_NEWS_BRIEF,
			self::KIND_NEWS_ARTICLE,
			self::KIND_EXTENDED_NEWS,
			self::KIND_ANALYSIS,
			self::KIND_FEATURE,
			self::KIND_SPORT_RESULT,
			self::KIND_OBITUARY,
			self::KIND_INTERVIEW,
			self::KIND_OPINION,
			self::KIND_EXPLAINER,
			self::KIND_LIVE_BLOG,
		];
	}

	public static function specs(): array {
		return [
			self::KIND_BREAKING_ALERT => [
				'de_chars_min'         => 200,
				'de_chars_target'      => 400,
				'sources_min'          => 1,
				'enrichment_required'  => false,
				'quality_thresholds'   => [
					'quality'         => 90,
					'seo_quality'     => 90,
					'release_quality' => 75,
					'google_quality'  => 75,
				],
				'rewriter_profile'     => 'eilmeldung',
			],
			self::KIND_NEWS_BRIEF => [
				'de_chars_min'         => 600,
				'de_chars_target'      => 1100,
				'sources_min'          => 1,
				'enrichment_required'  => false,
				'quality_thresholds'   => [
					'quality'         => 95,
					'seo_quality'     => 95,
					'release_quality' => 85,
					'google_quality'  => 85,
				],
				'rewriter_profile'     => 'news_brief',
			],
			self::KIND_NEWS_ARTICLE => [
				'de_chars_min'         => 2000,
				'de_chars_target'      => 3000,
				'sources_min'          => 2,
				'enrichment_required'  => true,
				'quality_thresholds'   => [
					'quality'         => 100,
					'seo_quality'     => 100,
					'release_quality' => 90,
					'google_quality'  => 90,
				],
				'rewriter_profile'     => 'news_synthesis',
			],
			self::KIND_EXTENDED_NEWS => [
				'de_chars_min'         => 4500,
				'de_chars_target'      => 6000,
				'sources_min'          => 3,
				'enrichment_required'  => true,
				'quality_thresholds'   => [
					'quality'         => 100,
					'seo_quality'     => 100,
					'release_quality' => 95,
					'google_quality'  => 95,
				],
				'rewriter_profile'     => 'extended_synthesis',
			],
			self::KIND_ANALYSIS => [
				'de_chars_min'         => 7500,
				'de_chars_target'      => 10000,
				'sources_min'          => 4,
				'enrichment_required'  => true,
				'quality_thresholds'   => [
					'quality'         => 100,
					'seo_quality'     => 100,
					'release_quality' => 100,
					'google_quality'  => 100,
				],
				'rewriter_profile'     => 'analysis',
			],
			self::KIND_FEATURE => [
				'de_chars_min'         => 15000,
				'de_chars_target'      => 20000,
				'sources_min'          => 5,
				'enrichment_required'  => true,
				'quality_thresholds'   => [
					'quality'         => 100,
					'seo_quality'     => 100,
					'release_quality' => 100,
					'google_quality'  => 100,
				],
				'rewriter_profile'     => 'feature',
			],
			self::KIND_SPORT_RESULT => [
				'de_chars_min'         => 1500,
				'de_chars_target'      => 2200,
				'sources_min'          => 1,
				'enrichment_required'  => false,
				'quality_thresholds'   => [
					'quality'         => 95,
					'seo_quality'     => 95,
					'release_quality' => 85,
					'google_quality'  => 85,
				],
				'rewriter_profile'     => 'sport_result',
			],
			self::KIND_OBITUARY => [
				'de_chars_min'         => 5000,
				'de_chars_target'      => 7000,
				'sources_min'          => 3,
				'enrichment_required'  => true,
				'quality_thresholds'   => [
					'quality'         => 100,
					'seo_quality'     => 100,
					'release_quality' => 95,
					'google_quality'  => 95,
				],
				'rewriter_profile'     => 'obituary',
			],
			self::KIND_INTERVIEW => [
				'de_chars_min'         => 4000,
				'de_chars_target'      => 6500,
				'sources_min'          => 1,
				'enrichment_required'  => false,
				'quality_thresholds'   => [
					'quality'         => 100,
					'seo_quality'     => 100,
					'release_quality' => 95,
					'google_quality'  => 95,
				],
				'rewriter_profile'     => 'interview',
			],
			self::KIND_OPINION => [
				'de_chars_min'         => 2500,
				'de_chars_target'      => 4000,
				'sources_min'          => 1,
				'enrichment_required'  => false,
				'quality_thresholds'   => [
					'quality'         => 100,
					'seo_quality'     => 100,
					'release_quality' => 95,
					'google_quality'  => 95,
				],
				'rewriter_profile'     => 'opinion',
			],
			self::KIND_EXPLAINER => [
				'de_chars_min'         => 5000,
				'de_chars_target'      => 8000,
				'sources_min'          => 2,
				'enrichment_required'  => true,
				'quality_thresholds'   => [
					'quality'         => 100,
					'seo_quality'     => 100,
					'release_quality' => 95,
					'google_quality'  => 95,
				],
				'rewriter_profile'     => 'explainer',
			],
			self::KIND_LIVE_BLOG => [
				'de_chars_min'         => 800,
				'de_chars_target'      => 1500,
				'sources_min'          => 1,
				'enrichment_required'  => false,
				'quality_thresholds'   => [
					'quality'         => 95,
					'seo_quality'     => 95,
					'release_quality' => 85,
					'google_quality'  => 85,
				],
				'rewriter_profile'     => 'live_blog',
			],
		];
	}

	public static function spec_for(string $kind): array {
		$specs = self::specs();
		return $specs[$kind] ?? $specs[self::KIND_NEWS_BRIEF];
	}

	public static function detect_kind(array $payload): string {
		$cached = (string) ($payload['_meta']['content_kind'] ?? '');
		if ($cached !== '' && in_array($cached, self::all_kinds(), true)) {
			return $cached;
		}
		$card    = is_array($payload['_meta']['story_card'] ?? null) ? $payload['_meta']['story_card'] : [];
		$de      = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title   = strtolower((string) ($de['title'] ?? ''));
		$excerpt = strtolower((string) ($de['excerpt'] ?? ''));
		$haystack = $title . ' ' . $excerpt;

		// 1. Obituary detection
		$obit_re = '/(verstorben|gestorben|nachruf|trauer um|ist tot|zum tode|obituary|in memoriam|помер[ао]?|пішов з життя|похорон)/u';
		if (preg_match($obit_re, $haystack) === 1) {
			return self::KIND_OBITUARY;
		}

		// 2. Sport result detection
		$categories = array_map('strval', (array) ($payload['categories'] ?? []));
		$primary_cat = strtolower((string) ($categories[0] ?? ''));
		$card_category = strtolower((string) ($card['category']['primary'] ?? ''));
		$is_sport = in_array($primary_cat, ['sport', 'sports', 'fussball', 'football'], true)
			|| in_array($card_category, ['sport', 'sports'], true);
		if ($is_sport) {
			$score_re = '/\b\d+\s*[:\-]\s*\d+\b|\b(sieg|niederlage|unentschieden|final|halbfinale)\b/u';
			if (preg_match($score_re, $haystack) === 1) {
				return self::KIND_SPORT_RESULT;
			}
		}

		$source_words = self::primary_source_word_count($payload);
		$length_profile = strtolower((string) ($card['rewrite']['length_profile'] ?? ''));
		$card_kind = strtolower((string) ($card['kind'] ?? ''));
		$publishable = strtolower((string) ($card['publishable_estimate'] ?? ''));
		$topics = is_array($card['topics'] ?? null) ? count($card['topics']) : 0;
		$entities_people = is_array($card['entities_people'] ?? null) ? count($card['entities_people']) : 0;
		$source_count = (int) ($payload['_meta']['source_count'] ?? 1);

		// 3. Interview — explicit Q&A format
		if (in_array($card_kind, ['interview', 'q_a', 'qa', 'q_and_a'], true)
			|| preg_match('/(\binterview\b|\bим зустріч|интервью|gespräch mit|im gespräch)/iu', $haystack) === 1) {
			return self::KIND_INTERVIEW;
		}

		// 4. Opinion / column / op-ed
		$is_opinion_cat = in_array($primary_cat, ['meinung', 'opinion', 'думка', 'kolumne'], true)
			|| in_array($card_category, ['meinung', 'opinion'], true);
		if ($is_opinion_cat
			|| in_array($card_kind, ['opinion', 'op_ed', 'op-ed', 'kommentar', 'kolumne', 'meinung', 'editorial'], true)) {
			return self::KIND_OPINION;
		}

		// 5. Breaking alert: live_ticker / eilmeldung — short instant
		if ($card_kind === 'live_ticker' || $card_kind === 'eilmeldung') {
			$primary_date = (string) ($payload['_meta']['source_dossier']['primary']['date']
				?? $payload['_meta']['source_dossier']['primary']['published_at']
				?? '');
			$is_fresh = true;
			if ($primary_date !== '') {
				$ts = strtotime($primary_date);
				if ($ts && (time() - $ts) > (4 * HOUR_IN_SECONDS)) {
					$is_fresh = false;
				}
			}
			if ($is_fresh && $source_words > 0 && $source_words < 80) {
				return self::KIND_BREAKING_ALERT;
			}
		}

		// 6. Live blog — rolling coverage, distinct from breaking_alert
		if (in_array($card_kind, ['live_blog', 'liveblog', 'live_updates', 'rolling_coverage'], true)
			|| preg_match('/\b(liveblog|live[-\s]updates|live[-\s]ticker[-\s]aktuell)\b/iu', $haystack) === 1) {
			return self::KIND_LIVE_BLOG;
		}

		// 7. Feature
		if ($card_kind === 'feature' || $card_kind === 'reportage'
			|| ($length_profile === 'long' && $topics >= 5 && $source_words >= 800)) {
			return self::KIND_FEATURE;
		}

		// 8. Explainer — pure educational pieces (separated from analysis)
		if ($card_kind === 'explainer' || $card_kind === 'erklär' || $card_kind === 'q_explainer'
			|| preg_match('/\b(explainer|erklärt|was bedeutet|im überblick|was steckt dahinter)\b/iu', $haystack) === 1) {
			return self::KIND_EXPLAINER;
		}

		// 9. Analysis — opinion-laden interpretation, with multiple angles
		if ($card_kind === 'analysis'
			|| ($length_profile === 'long' && $topics >= 4)) {
			return self::KIND_ANALYSIS;
		}

		// 10. Extended news
		if ($source_count >= 2 && $topics >= 3 && $entities_people >= 2) {
			return self::KIND_EXTENDED_NEWS;
		}

		// 11. News article (multi-source potential, even if currently 1 source — enrichment will add)
		// Default for items that are NOT thin and NOT explicitly brief.
		if ($length_profile !== 'brief' && $source_words >= 80) {
			return self::KIND_NEWS_ARTICLE;
		}

		// 12. News brief (default fallthrough — thin or brief items)
		return self::KIND_NEWS_BRIEF;
	}

	public static function payload_meets_de_length(array $payload, ?string $kind = null): bool {
		$kind = $kind ?? self::detect_kind($payload);
		$spec = self::spec_for($kind);
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$content_plain = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));
		return mb_strlen($content_plain) >= (int) $spec['de_chars_min'];
	}

	public static function payload_meets_sources(array $payload, ?string $kind = null): bool {
		$kind = $kind ?? self::detect_kind($payload);
		$spec = self::spec_for($kind);
		$source_count = (int) ($payload['_meta']['source_count'] ?? 0);
		// related[] entries from enrichment also count as sources
		$related = is_array($payload['_meta']['source_dossier']['related'] ?? null)
			? $payload['_meta']['source_dossier']['related']
			: [];
		$source_count = max($source_count, 1 + count($related));
		return $source_count >= (int) $spec['sources_min'];
	}

	public static function payload_meets_quality(array $payload, ?string $kind = null): bool {
		$kind = $kind ?? self::detect_kind($payload);
		$spec = self::spec_for($kind);
		$thresholds = is_array($spec['quality_thresholds'] ?? null) ? $spec['quality_thresholds'] : [];
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		foreach ($thresholds as $key => $min_score) {
			$quality = is_array($meta[$key] ?? null) ? $meta[$key] : [];
			if (empty($quality['pass'])) {
				return false;
			}
			if ((int) ($quality['score'] ?? 0) < (int) $min_score) {
				return false;
			}
		}
		return true;
	}

	public static function payload_meets_enrichment(array $payload, ?string $kind = null): bool {
		$kind = $kind ?? self::detect_kind($payload);
		$spec = self::spec_for($kind);
		if (empty($spec['enrichment_required'])) {
			return true;
		}
		$enrichment = is_array($payload['_meta']['enrichment'] ?? null) ? $payload['_meta']['enrichment'] : [];
		if (! empty($enrichment['ran']) && (int) ($enrichment['related_count'] ?? 0) >= 1) {
			return true;
		}
		// Sources already present (organically multi-source): treat as enriched.
		$related = is_array($payload['_meta']['source_dossier']['related'] ?? null)
			? $payload['_meta']['source_dossier']['related']
			: [];
		return count($related) >= 1;
	}

	public static function payload_meets_kind_spec(array $payload, ?string $kind = null): bool {
		$kind = $kind ?? self::detect_kind($payload);
		return self::payload_meets_de_length($payload, $kind)
			&& self::payload_meets_sources($payload, $kind)
			&& self::payload_meets_quality($payload, $kind)
			&& self::payload_meets_enrichment($payload, $kind);
	}

	public static function primary_source_word_count(array $payload): int {
		$candidates = [
			$payload['_meta']['source_dossier']['primary']['content'] ?? '',
			$payload['_meta']['source_dossier']['primary']['excerpt'] ?? '',
			$payload['source']['content'] ?? '',
			$payload['source']['excerpt'] ?? '',
		];
		$best = 0;
		foreach ($candidates as $text) {
			$plain = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));
			if ($plain === '') {
				continue;
			}
			$count = str_word_count($plain);
			if ($count > $best) {
				$best = $count;
			}
		}
		return $best;
	}

	public static function rewriter_profile_for(array $payload): string {
		$kind = self::detect_kind($payload);
		$spec = self::spec_for($kind);
		return (string) ($spec['rewriter_profile'] ?? 'news_brief');
	}
}
