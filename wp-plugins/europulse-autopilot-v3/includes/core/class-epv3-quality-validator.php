<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Quality_Validator {
	public static function evaluate(array $de_payload, array $context, array $media, array $uk_payload, array $en_payload): array {
		$context_score = self::context_score($context);
		$seo = EPV3_SEO_Manager::build_meta($de_payload, $context, $media);
		$seo_score = self::seo_score($seo, $de_payload);
		$google_score = self::google_score($media, $seo);
		$translation_score = self::translation_score($uk_payload, $en_payload);
		$release_score = min(100, (int) floor(($context_score + $seo_score + $google_score + $translation_score) / 4));

		$warnings = [];
		if ($context_score < 100) {
			$warnings[] = 'context_not_full';
		}
		if ($seo_score < 100) {
			$warnings[] = 'seo_not_full';
		}
		if ($google_score < 100) {
			$warnings[] = 'google_not_full';
		}
		if ($translation_score < 100) {
			$warnings[] = 'translations_not_full';
		}

		return [
			'context_score' => $context_score,
			'seo_score' => $seo_score,
			'google_score' => $google_score,
			'translation_score' => $translation_score,
			'release_score' => $release_score,
			'seo' => $seo,
			'warnings' => $warnings,
			'publish_ready' => $warnings === [],
		];
	}

	private static function context_score(array $context): int {
		$score = 0;
		if (! empty($context['category'])) {
			$score += 20;
		}
		if (! empty($context['body_summary']) && mb_strlen((string) $context['body_summary']) >= 150) {
			$score += 20;
		}
		if (count((array) ($context['keywords'] ?? [])) >= 4) {
			$score += 20;
		}
		if (count((array) ($context['entities'] ?? [])) >= 3) {
			$score += 20;
		}
		if (count((array) ($context['search_terms'] ?? [])) >= 3) {
			$score += 20;
		}
		return min(100, $score);
	}

	private static function seo_score(array $seo, array $de_payload): int {
		$score = 0;
		if (! empty($de_payload['title']) && mb_strlen((string) $de_payload['title']) >= 12) {
			$score += 25;
		}
		if (! empty($seo['seo_title']) && mb_strlen((string) $seo['seo_title']) >= 15) {
			$score += 25;
		}
		if (! empty($seo['meta_description']) && mb_strlen((string) $seo['meta_description']) >= 80) {
			$score += 25;
		}
		if (! empty($seo['slug']) && count((array) ($seo['focus_keywords'] ?? [])) >= 3) {
			$score += 25;
		}
		return min(100, $score);
	}

	private static function google_score(array $media, array $seo): int {
		$score = 0;
		if (! empty($media['featured_url'])) {
			$score += 40;
		}
		if (! empty($media['attribution'])) {
			$score += 20;
		}
		if (! empty($media['attribution_url'])) {
			$score += 20;
		}
		if (! empty($seo['google_image_ready'])) {
			$score += 20;
		}
		return min(100, $score);
	}

	private static function translation_score(array $uk_payload, array $en_payload): int {
		$score = 0;
		if (! empty($uk_payload['content']) && ! empty($uk_payload['title'])) {
			$score += 50;
		}
		if (! empty($en_payload['content']) && ! empty($en_payload['title'])) {
			$score += 50;
		}
		return $score;
	}
}
