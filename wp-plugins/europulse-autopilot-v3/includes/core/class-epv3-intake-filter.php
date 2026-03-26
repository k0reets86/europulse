<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Intake_Filter {
	public static function analyze(object $item): array {
		$title = mb_strtolower(wp_strip_all_tags((string) ($item->original_title ?? '')));
		$excerpt = mb_strtolower(wp_strip_all_tags((string) ($item->original_excerpt ?? '')));
		$content = mb_strtolower(wp_strip_all_tags((string) ($item->original_content ?? '')));
		$text = trim($title . ' ' . $excerpt . ' ' . $content);
		$reasons = [];
		$score = 0;

		if ($text === '') {
			return [
				'decision' => 'reject',
				'score' => 0,
				'category' => 'news',
				'reasons' => ['empty content'],
			];
		}

		if (self::looks_like_noise($title, $excerpt, $content)) {
			return [
				'decision' => 'reject',
				'score' => 0,
				'category' => 'news',
				'reasons' => ['noise or utility page'],
			];
		}

		$category = EPV3_Categorizer::detect($title, $content !== '' ? $content : $excerpt);

		if (self::looks_official((string) ($item->original_url ?? ''))) {
			$score += 12;
			$reasons[] = 'official source';
		}

		if (preg_match('/\b(heute|today|breaking|aktuell|wichtig|sofort)\b/u', $text) === 1) {
			$score += 10;
			$reasons[] = 'freshness or urgency signal';
		}

		if (in_array($category, ['politik', 'leben-in-deutschland', 'wirtschaft', 'community'], true)) {
			$score += 15;
			$reasons[] = 'core editorial category';
		}

		if (preg_match('/\b(deutschland|berlin|bundesregierung|familien|soziale dienste|ukraine|eu)\b/u', $text) === 1) {
			$score += 10;
			$reasons[] = 'public relevance';
		}

		if (mb_strlen($content) >= 400) {
			$score += 8;
			$reasons[] = 'has enough body for context analysis';
		}

		return [
			'decision' => $score >= 10 ? 'keep' : 'review',
			'score' => min(100, $score),
			'category' => $category,
			'reasons' => $reasons,
		];
	}

	private static function looks_like_noise(string $title, string $excerpt, string $content): bool {
		$text = trim($title . ' ' . $excerpt . ' ' . $content);
		if ($text === '') {
			return true;
		}
		return preg_match('/\b(impressum|datenschutz|privacy|cookie|anmelden|login|registrierung|newsletter|agb)\b/u', $text) === 1;
	}

	private static function looks_official(string $url): bool {
		return preg_match('#\.(gov|gob|bund|europa)\b|/regierung|/ministerium#i', $url) === 1;
	}
}
