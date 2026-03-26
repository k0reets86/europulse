<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_DE_Master_Builder {
	public static function build(object $item, array $context, array $dossier): array {
		$title = trim((string) ($item->original_title ?? ''));
		$content = trim((string) ($item->original_content ?? ''));
		$excerpt = trim((string) ($item->original_excerpt ?? ''));
		$body = $content !== '' ? $content : $excerpt;
		$body = wp_strip_all_tags($body);

		$lead = self::build_lead($excerpt !== '' ? $excerpt : $body);

		$quotes = self::extract_quotes($content !== '' ? $content : $excerpt);
		$citations = self::build_citations($dossier);
		$fallback = [
			'lang' => 'de',
			'title' => $title,
			'lead' => $lead,
			'content' => $body,
			'category' => $context['category'] ?? 'news',
			'keywords' => $context['keywords'] ?? [],
			'entities' => $context['entities'] ?? [],
			'source_count' => (int) ($dossier['source_count'] ?? 1),
			'quotes' => $quotes,
			'citations' => $citations,
			'status' => 'draft_de_master',
		];
		if (! EPV3_AI_Client::available()) {
			return $fallback;
		}

		try {
			$payload = EPV3_AI_Client::generate_json(
				'Return only compact JSON for a German newsroom article. Keys: title, lead, content, quotes. Always include at least one short direct quote or attributed statement in quotes.',
				wp_json_encode([
					'task' => 'Build final German master article from source and context.',
					'style' => EPV3_Settings::get('rewrite_style', 'lively'),
					'category' => $context['category'] ?? 'news',
					'keywords' => $context['keywords'] ?? [],
					'entities' => $context['entities'] ?? [],
					'source_title' => $title,
					'source_excerpt' => $excerpt,
					'source_content' => $body,
					'citations' => $citations,
				], JSON_UNESCAPED_UNICODE),
				2600
			);

			$fallback['title'] = sanitize_text_field((string) ($payload['title'] ?? $fallback['title']));
			$fallback['lead'] = sanitize_text_field((string) ($payload['lead'] ?? $fallback['lead']));
			$fallback['content'] = wp_kses_post((string) ($payload['content'] ?? $fallback['content']));
			$fallback['quotes'] = self::normalize_quotes((array) ($payload['quotes'] ?? $fallback['quotes']), $title, $excerpt !== '' ? $excerpt : $body);
			$fallback['status'] = 'ai_de_master';
		} catch (Throwable $e) {
			$fallback['status'] = 'fallback_de_master';
			$fallback['ai_error'] = $e->getMessage();
		}

		if ($fallback['quotes'] === []) {
			$fallback['quotes'] = self::build_fallback_quote($title, $excerpt !== '' ? $excerpt : $body);
		}

		return $fallback;
	}

	private static function build_lead(string $text): string {
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
		if ($text === '') {
			return '';
		}
		$sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
		$lead = implode(' ', array_slice($sentences, 0, 2));
		return trim($lead);
	}

	private static function extract_quotes(string $text): array {
		$quotes = [];
		if (preg_match_all('/[«"]([^»"]{20,220})[»"]/u', $text, $matches)) {
			foreach ((array) ($matches[1] ?? []) as $quote) {
				$quotes[] = trim((string) $quote);
			}
		}
		return array_slice(array_values(array_unique($quotes)), 0, 3);
	}

	private static function normalize_quotes(array $quotes, string $title, string $summary): array {
		$result = [];
		foreach ($quotes as $quote) {
			$quote = trim(wp_strip_all_tags((string) $quote));
			if ($quote === '' || mb_strlen($quote) < 12) {
				continue;
			}
			$result[] = $quote;
		}
		$result = array_slice(array_values(array_unique($result)), 0, 3);
		if ($result === []) {
			return self::build_fallback_quote($title, $summary);
		}
		return $result;
	}

	private static function build_fallback_quote(string $title, string $summary): array {
		$basis = trim($summary !== '' ? $summary : $title);
		if ($basis === '') {
			return [];
		}
		$basis = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($basis)) ?? $basis);
		$basis = mb_substr($basis, 0, 180);
		return ['„' . $basis . '“, heißt es im berichteten Kontext.'];
	}

	private static function build_citations(array $dossier): array {
		$citations = [];
		foreach ((array) ($dossier['sources'] ?? []) as $source) {
			if (! is_array($source)) {
				continue;
			}
			$url = (string) ($source['url'] ?? '');
			$title = (string) ($source['title'] ?? '');
			if ($url === '' && $title === '') {
				continue;
			}
			$citations[] = [
				'title' => $title,
				'url' => $url,
				'type' => (string) ($source['type'] ?? 'source'),
			];
		}
		return array_slice($citations, 0, 5);
	}
}
