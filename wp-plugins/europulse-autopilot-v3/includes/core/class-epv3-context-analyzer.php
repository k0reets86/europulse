<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Context_Analyzer {
	public static function analyze(object $item): array {
		$title = trim((string) ($item->original_title ?? ''));
		$content = trim(wp_strip_all_tags((string) ($item->original_content ?? '')));
		$excerpt = trim(wp_strip_all_tags((string) ($item->original_excerpt ?? '')));
		$text = trim($content !== '' ? $content : $excerpt . ' ' . $title);

		$keywords = self::extract_keywords($text);
		$entities = self::extract_entities($title . ' ' . $text);
		$category = EPV3_Categorizer::detect($title, $text);
		$language = self::detect_language($text);
		$priority = self::detect_priority($title . ' ' . $text);
		$search_terms = array_values(array_slice(array_unique(array_filter(array_merge($keywords, $entities))), 0, 6));

		return [
			'detected_language' => $language,
			'category' => $category,
			'priority' => $priority,
			'keywords' => $keywords,
			'entities' => $entities,
			'search_terms' => $search_terms,
			'body_summary' => mb_substr($text, 0, 1200),
			'decision' => $text === '' ? 'reject' : 'keep',
			'reason' => $text === '' ? 'empty_body' : 'body_context_ok',
		];
	}

	private static function extract_keywords(string $text): array {
		$text = mb_strtolower($text);
		$tokens = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];
		$stopwords = [
			'ueber', 'unter', 'durch', 'gegen', 'heute', 'diese', 'dieser', 'dieses', 'dabei',
			'damit', 'sowie', 'werden', 'wurde', 'wurden', 'beraten', 'plant', 'neue', 'neuen',
			'klaeren', 'klare', 'regeln', 'schnelle', 'hilfe', 'bedarf', 'ziel', 'steht', 'stehts',
			'familie', 'familien', 'deutschland', 'berlin',
		];
		$tokens = array_values(array_filter($tokens, static function (string $token) use ($stopwords): bool {
			return mb_strlen($token) >= 5 && ! in_array($token, $stopwords, true);
		}));
		$counts = array_count_values($tokens);
		arsort($counts);
		return array_slice(array_keys($counts), 0, 8);
	}

	private static function extract_entities(string $text): array {
		preg_match_all('/\b[\p{Lu}][\p{L}\-]{2,}\b/u', $text, $matches);
		$entities = array_values(array_unique($matches[0] ?? []));
		return array_slice($entities, 0, 12);
	}

	private static function detect_language(string $text): string {
		$text = mb_strtolower($text);
		if (preg_match('/\b(der|die|das|und|mit|für|nicht|deutschland)\b/u', $text) === 1) {
			return 'de';
		}
		if (preg_match('/\b(та|і|що|це|україні|німеччині)\b/u', $text) === 1) {
			return 'uk';
		}
		if (preg_match('/\b(the|and|with|from|that|germany)\b/u', $text) === 1) {
			return 'en';
		}
		return '';
	}

	private static function detect_priority(string $text): int {
		$text = mb_strtolower($text);
		$score = 10;
		foreach (['breaking', 'urgent', 'sofort', 'wichtig', 'regierung', 'gesetz', 'ukraine', 'deutschland'] as $signal) {
			if (str_contains($text, $signal)) {
				$score += 10;
			}
		}
		return min(100, $score);
	}
}
