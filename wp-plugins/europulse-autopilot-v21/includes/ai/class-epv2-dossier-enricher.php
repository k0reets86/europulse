<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Sibling-based dossier enrichment for EuroPulse.
 *
 * For items whose KIND requires multi-source synthesis (news_article and
 * above), this finds related coverage already in the queue/published table:
 * different publishers writing about the same event/topic. Their content is
 * aggregated into source_dossier.related[] so the rewriter can synthesize
 * a NEW article from 2-3 perspectives instead of paraphrasing one source.
 *
 * Sibling discovery uses three independent signals:
 *   1. topic_label exact match (highest precision — e.g. "War in Ukraine")
 *   2. category_final match + ≥2 shared entities from story_card
 *   3. cluster_id match (when populated)
 *
 * Candidates are filtered to:
 *   - Different domain than primary (multi-source = multi-publisher)
 *   - Last 72 hours by created_at
 *   - Substantial content (≥100 words original_content)
 *   - Not the item itself
 */
final class EPV2_Dossier_Enricher {

	const RECENT_HOURS = 72;
	// RSS-stub feeds (Tagesschau, Spiegel, NDR, Welt, BBC) typically expose
	// 30-80 words of plain prose per item once HTML is stripped. Setting
	// the threshold higher excludes most real items. Aggregating 4-5 stubs
	// from different publishers still gives the rewriter meaningful
	// multi-perspective input.
	const MIN_CONTENT_WORDS = 30;
	const MAX_RELATED = 4;
	const RELATED_CONTENT_TRUNCATE_WORDS = 600;

	public static function enrich(int $item_id, array $payload): array {
		$kind = EPV2_Content_Kinds::detect_kind($payload);
		$spec = EPV2_Content_Kinds::spec_for($kind);
		if (empty($spec['enrichment_required'])) {
			return $payload;
		}
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$existing_related = is_array($payload['_meta']['source_dossier']['related'] ?? null)
			? $payload['_meta']['source_dossier']['related']
			: [];
		$candidates = self::find_sibling_candidates($item_id, $payload);
		$related = self::merge_related($existing_related, $candidates);
		$payload['_meta']['source_dossier'] = is_array($payload['_meta']['source_dossier'] ?? null)
			? $payload['_meta']['source_dossier']
			: [];
		$payload['_meta']['source_dossier']['related'] = $related;
		$payload['_meta']['source_count'] = max(
			(int) ($payload['_meta']['source_count'] ?? 1),
			1 + count($related)
		);
		$payload['_meta']['enrichment'] = [
			'ran'           => true,
			'method'        => 'inhouse_siblings',
			'related_count' => count($related),
			'sources_min'   => (int) $spec['sources_min'],
			'kind'          => $kind,
			'ran_at'        => gmdate('Y-m-d H:i:s'),
		];
		return $payload;
	}

	public static function find_sibling_candidates(int $item_id, ?array $payload = null): array {
		$payload = is_array($payload) ? $payload : [];
		global $wpdb;
		$tbl = $wpdb->prefix . 'epv2_queue';
		$primary_domain = self::extract_primary_domain($item_id, $payload);
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT topic_label, category_final, cluster_id, created_at FROM $tbl WHERE id = %d",
			$item_id
		));
		if (! $row) {
			return [];
		}
		$cutoff = gmdate('Y-m-d H:i:s', time() - (self::RECENT_HOURS * HOUR_IN_SECONDS));
		$entity_names = self::extract_card_entity_names($payload);

		$candidate_ids = [];

		// Signal 1: topic_label match
		if (! empty($row->topic_label)) {
			$ids = $wpdb->get_col($wpdb->prepare(
				"SELECT id FROM $tbl
				 WHERE topic_label = %s
				   AND id != %d
				   AND created_at >= %s
				   AND original_content IS NOT NULL
				   AND CHAR_LENGTH(original_content) >= 600
				 ORDER BY created_at DESC
				 LIMIT 20",
				$row->topic_label,
				$item_id,
				$cutoff
			));
			$candidate_ids = array_merge($candidate_ids, array_map('intval', $ids));
		}

		// Signal 2: cluster_id match (when populated and not self-referential)
		if (! empty($row->cluster_id) && (int) $row->cluster_id !== $item_id) {
			$ids = $wpdb->get_col($wpdb->prepare(
				"SELECT id FROM $tbl
				 WHERE cluster_id = %s
				   AND id != %d
				   AND created_at >= %s
				   AND original_content IS NOT NULL
				   AND CHAR_LENGTH(original_content) >= 600
				 LIMIT 10",
				$row->cluster_id,
				$item_id,
				$cutoff
			));
			$candidate_ids = array_merge($candidate_ids, array_map('intval', $ids));
		}

		// Signal 3: same category + recency (broader fallback)
		if (! empty($row->category_final) && count($candidate_ids) < self::MAX_RELATED * 3) {
			$ids = $wpdb->get_col($wpdb->prepare(
				"SELECT id FROM $tbl
				 WHERE category_final = %s
				   AND id != %d
				   AND created_at >= %s
				   AND original_content IS NOT NULL
				   AND CHAR_LENGTH(original_content) >= 600
				 ORDER BY created_at DESC
				 LIMIT 30",
				$row->category_final,
				$item_id,
				$cutoff
			));
			$candidate_ids = array_merge($candidate_ids, array_map('intval', $ids));
		}

		$candidate_ids = array_values(array_unique($candidate_ids));
		if ($candidate_ids === []) {
			return [];
		}

		$placeholders = implode(',', array_fill(0, count($candidate_ids), '%d'));
		$candidates = $wpdb->get_results($wpdb->prepare(
			"SELECT id, original_url, original_title, original_content, original_excerpt, original_date,
			        category_final, topic_label, ai_payload
			 FROM $tbl
			 WHERE id IN ($placeholders)
			 ORDER BY created_at DESC",
			...$candidate_ids
		));
		if (! $candidates) {
			return [];
		}

		$ranked = [];
		foreach ($candidates as $c) {
			$cand_domain = self::extract_url_domain((string) $c->original_url);
			if ($cand_domain === '' || $cand_domain === $primary_domain) {
				continue;
			}
			$word_count = str_word_count(trim(wp_strip_all_tags((string) $c->original_content)));
			if ($word_count < self::MIN_CONTENT_WORDS) {
				continue;
			}
			$score = self::score_candidate($c, $row, $entity_names);
			$ranked[] = [
				'score'       => $score,
				'id'          => (int) $c->id,
				'url'         => (string) $c->original_url,
				'domain'      => $cand_domain,
				'title'       => (string) $c->original_title,
				'excerpt'     => self::truncate_words((string) $c->original_excerpt, 80),
				'content'     => self::truncate_words(wp_strip_all_tags((string) $c->original_content), self::RELATED_CONTENT_TRUNCATE_WORDS),
				'date'        => (string) $c->original_date,
				'word_count'  => $word_count,
				'topic_label' => (string) $c->topic_label,
			];
		}

		// Diverse-domain selection: at most 1 candidate per domain.
		usort($ranked, static fn($a, $b) => $b['score'] <=> $a['score']);
		$by_domain = [];
		$selected = [];
		foreach ($ranked as $r) {
			if (isset($by_domain[$r['domain']])) {
				continue;
			}
			$by_domain[$r['domain']] = true;
			$selected[] = $r;
			if (count($selected) >= self::MAX_RELATED) {
				break;
			}
		}
		return $selected;
	}

	private static function score_candidate(object $c, object $primary_row, array $entity_names): float {
		$score = 0.0;
		if ((string) $c->topic_label !== '' && $c->topic_label === $primary_row->topic_label) {
			$score += 100.0;
		}
		if ((string) $c->category_final !== '' && $c->category_final === $primary_row->category_final) {
			$score += 30.0;
		}
		if ($entity_names !== []) {
			$cand_text = strtolower((string) ($c->original_title . ' ' . $c->original_excerpt));
			$matches = 0;
			foreach ($entity_names as $name) {
				if ($name === '') continue;
				if (mb_strpos($cand_text, mb_strtolower($name)) !== false) {
					$matches++;
				}
			}
			$score += $matches * 25.0;
		}
		// Recency bonus (newer = better)
		$age_hours = (time() - strtotime((string) ($c->original_date ?: 'now'))) / HOUR_IN_SECONDS;
		$score += max(0.0, 24.0 - $age_hours) * 2.0;
		return $score;
	}

	private static function extract_card_entity_names(array $payload): array {
		$card = is_array($payload['_meta']['story_card'] ?? null) ? $payload['_meta']['story_card'] : [];
		$names = [];
		foreach ((array) ($card['entities_people'] ?? []) as $p) {
			if (is_array($p)) {
				$names[] = (string) ($p['name'] ?? '');
			} elseif (is_string($p)) {
				$names[] = $p;
			}
		}
		foreach ((array) ($card['entities_organizations'] ?? []) as $o) {
			if (is_array($o)) {
				$names[] = (string) ($o['name'] ?? '');
			} elseif (is_string($o)) {
				$names[] = $o;
			}
		}
		foreach ((array) ($card['entities_places'] ?? []) as $place) {
			$names[] = (string) (is_array($place) ? ($place['name'] ?? '') : $place);
		}
		return array_values(array_filter(array_unique($names)));
	}

	private static function extract_primary_domain(int $item_id, array $payload): string {
		global $wpdb;
		$tbl = $wpdb->prefix . 'epv2_queue';
		$url = (string) ($payload['_meta']['source_dossier']['primary']['url'] ?? '');
		if ($url === '') {
			$url = (string) $wpdb->get_var($wpdb->prepare(
				"SELECT original_url FROM $tbl WHERE id = %d",
				$item_id
			));
		}
		return self::extract_url_domain($url);
	}

	private static function extract_url_domain(string $url): string {
		if ($url === '') {
			return '';
		}
		$parts = wp_parse_url($url);
		$host = strtolower((string) ($parts['host'] ?? ''));
		$host = preg_replace('/^www\./', '', $host) ?? $host;
		return $host;
	}

	private static function truncate_words(string $text, int $max_words): string {
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
		if ($text === '') {
			return '';
		}
		$words = preg_split('/\s+/u', $text) ?: [];
		if (count($words) <= $max_words) {
			return $text;
		}
		return implode(' ', array_slice($words, 0, $max_words)) . '…';
	}

	private static function merge_related(array $existing, array $candidates): array {
		$by_url = [];
		foreach ($existing as $e) {
			if (! is_array($e)) continue;
			$url = (string) ($e['url'] ?? '');
			if ($url !== '') {
				$by_url[$url] = $e;
			}
		}
		foreach ($candidates as $c) {
			$url = (string) ($c['url'] ?? '');
			if ($url === '') continue;
			if (! isset($by_url[$url])) {
				$by_url[$url] = $c;
			}
		}
		return array_values($by_url);
	}
}
