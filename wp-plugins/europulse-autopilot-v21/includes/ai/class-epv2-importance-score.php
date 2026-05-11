<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Importance score for queue items.
 *
 * Architecture audit section 6 tradeoff #7: when a pipeline step fails
 * after its retry budget is exhausted, the item's importance decides
 * whether the operator gets a chance to fix it (manual_review) or it
 * is dropped (rejected).
 *
 * Score is 0–100, computed from already-available signals — no new AI
 * calls. Weights are intentionally conservative for the first version;
 * they can be tuned later from operator-trash patterns once we have
 * enough manual_review data to learn from.
 */
final class EPV2_Importance_Score {

	/** Items at/above this score on quarantine go to manual_review; below go to rejected. */
	const DEFAULT_THRESHOLD = 30;

	/**
	 * Compute the score for a queue row + payload.
	 *
	 * @param object|null $item    Queue row (ep_epv2_queue) — for source/story_score signals.
	 * @param array       $payload Queue payload (decoded) — for breaking/top_story/card signals.
	 */
	public static function compute(?object $item, array $payload): int {
		$score = 0;

		// 1. Source priority (0–30 pts). Top-tier outlets matter more for
		// E-E-A-T even when the article itself is short — Reuters / AP /
		// Spiegel single-source content is usually still worth saving.
		$source_priority = self::source_priority_for_item($item);
		$score += min(30, (int) round(($source_priority / 10) * 30));

		// 2. Ingest story_score (0–30 pts). This is the upstream editorial
		// hint; it captures topic match, recency, source-content depth.
		$ingest_score = (int) ($item->story_score ?? 0);
		$score += min(30, (int) round($ingest_score * 0.3));

		// 3. Story-card publishable estimate (0–20 pts).
		$card = is_array($payload['_meta']['story_card'] ?? null) ? $payload['_meta']['story_card'] : [];
		$estimate = strtolower((string) ($card['publishable_estimate'] ?? ''));
		if ($estimate === 'high') {
			$score += 20;
		} elseif ($estimate === 'medium') {
			$score += 10;
		}

		// 4. Editorial flags (0–25 pts combined). Breaking news and
		// top-story candidates always get their day with the operator
		// even if mid-pipeline failed.
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if (! empty($meta['breaking'])) {
			$score += 15;
		}
		if (! empty($meta['top_story'])) {
			$score += 10;
		}

		// 5. Multi-source bonus (0–10 pts). If we already have ≥2 distinct
		// sources, the item is materially different from a single rewrite
		// and deserves preservation.
		$dossier = is_array($meta['source_dossier'] ?? null) ? $meta['source_dossier'] : [];
		$related = is_array($dossier['related'] ?? null) ? $dossier['related'] : [];
		if (count($related) >= 2) {
			$score += 10;
		} elseif (count($related) === 1) {
			$score += 5;
		}

		// 6. Editorial-calibration adjustment — Story Card editorial_match.
		// Story Card primacy: AI's editorial verdict has direct vote on
		// quarantine routing.
		//   match            → +10 bonus (matches editorial scope)
		//   borderline       → -15 penalty (точно НЕ берём fallback)
		//   reject_low_value → -30 penalty (operator should not fix this)
		$story_card = is_array($meta['story_card'] ?? null) ? $meta['story_card'] : [];
		$editorial_match = strtolower((string) ($story_card['editorial_match'] ?? ''));
		if ($editorial_match === 'match') {
			$score += 10;
		} elseif ($editorial_match === 'borderline') {
			$score -= 15;
		} elseif ($editorial_match === 'reject_low_value') {
			$score -= 30;
		}

		return (int) max(0, min(100, $score));
	}

	/**
	 * Decide whether an item should land in manual_review (true) or
	 * rejected (false) when its retry budget is exhausted.
	 */
	public static function deserves_manual_review(?object $item, array $payload, int $threshold = self::DEFAULT_THRESHOLD): bool {
		return self::compute($item, $payload) >= $threshold;
	}

	private static function source_priority_for_item(?object $item): int {
		if (! $item) {
			return 5;
		}
		$source_id = (int) ($item->source_id ?? 0);
		if ($source_id <= 0) {
			return 5;
		}
		// Cache per-request: source priorities don't change mid-cycle.
		static $cache = [];
		if (isset($cache[$source_id])) {
			return $cache[$source_id];
		}
		global $wpdb;
		$priority = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT priority FROM {$wpdb->prefix}epv2_sources WHERE id = %d",
			$source_id
		));
		if ($priority <= 0) {
			$priority = 5;
		}
		$cache[$source_id] = $priority;
		return $priority;
	}
}
