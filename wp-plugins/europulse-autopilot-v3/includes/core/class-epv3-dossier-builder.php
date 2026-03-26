<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Dossier_Builder {
	public static function build(object $item, array $context): array {
		$sources = [[
			'type' => 'primary',
			'url' => (string) ($item->original_url ?? ''),
			'title' => (string) ($item->original_title ?? ''),
			'excerpt' => (string) ($item->original_excerpt ?? ''),
			'image' => (string) ($item->source_image_url ?? ''),
		]];

		$search_terms = self::build_queries($item, $context);
		$keywords = array_values(array_filter(array_map('strval', (array) ($context['keywords'] ?? []))));
		$seen = [
			md5((string) ($item->original_url ?? '')) => true,
		];
		foreach (array_slice($search_terms, 0, 2) as $query) {
			foreach (self::search_google_news($query) as $candidate) {
				$key = md5((string) ($candidate['url'] ?? ''));
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$sources[] = array_merge(['type' => 'supporting', 'search_term' => $query], $candidate);
				if (count($sources) >= 3) {
					break 2;
				}
			}
		}

		return [
			'context_memory' => [
				'category' => $context['category'] ?? 'news',
				'keywords' => $keywords,
				'entities' => $context['entities'] ?? [],
				'search_terms' => $context['search_terms'] ?? [],
			],
			'sources' => $sources,
			'source_count' => count($sources),
		];
	}

	private static function build_queries(object $item, array $context): array {
		$queries = [];
		$search_terms = array_values(array_filter(array_map('strval', (array) ($context['search_terms'] ?? []))));
		$keywords = array_values(array_filter(array_map('strval', (array) ($context['keywords'] ?? []))));
		$category = trim((string) ($context['category'] ?? ''));
		$title = trim((string) ($item->original_title ?? ''));

		if ($title !== '') {
			$queries[] = $title;
		}

		if ($search_terms !== []) {
			$queries[] = implode(' ', array_slice($search_terms, 0, 6));
			$queries[] = implode(' ', array_slice($search_terms, 0, 4));
		}

		if ($keywords !== []) {
			$queries[] = implode(' ', array_slice($keywords, 0, 5));
		}

		if ($category !== '' && $search_terms !== []) {
			$queries[] = $category . ' ' . implode(' ', array_slice($search_terms, 0, 4));
		}

		$queries = array_values(array_unique(array_filter(array_map(static function (string $query): string {
			$query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
			return mb_strlen($query) >= 8 ? $query : '';
		}, $queries))));

		return array_slice($queries, 0, 5);
	}

	private static function search_google_news(string $query): array {
		$url = 'https://news.google.com/rss/search?q=' . rawurlencode($query) . '&hl=de&gl=DE&ceid=DE:de';
		$response = wp_remote_get($url, [
			'timeout' => 8,
			'redirection' => 3,
			'user-agent' => 'EuroPulse AutoPilot V3',
		]);
		if (is_wp_error($response)) {
			return [];
		}
		$body = (string) wp_remote_retrieve_body($response);
		if ($body === '') {
			return [];
		}
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);
		libxml_clear_errors();
		if (! $xml || empty($xml->channel->item)) {
			return [];
		}
		$items = [];
		foreach ($xml->channel->item as $node) {
			$link = trim((string) ($node->link ?? ''));
			$title = trim((string) ($node->title ?? ''));
			$description = trim(wp_strip_all_tags((string) ($node->description ?? '')));
			if ($link === '' || $title === '') {
				continue;
			}
			$items[] = [
				'url' => esc_url_raw($link),
				'title' => sanitize_text_field($title),
				'excerpt' => sanitize_text_field(mb_substr($description, 0, 220)),
				'image' => '',
			];
			if (count($items) >= 4) {
				break;
			}
		}
		return $items;
	}
}
