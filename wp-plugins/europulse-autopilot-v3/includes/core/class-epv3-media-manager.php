<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Media_Manager {
	public static function resolve(object $item, array $context, array $dossier): array {
		$source_url = (string) ($item->source_image_url ?? '');
		$source_link = (string) ($item->original_url ?? '');
		$category = (string) ($context['category'] ?? 'news');

		if ($source_url !== '') {
			return [
				'featured_url' => $source_url,
				'attribution' => 'Bild: Primärquelle / ' . $category,
				'attribution_url' => $source_link,
				'relevance' => 'primary_source',
			];
		}

		return [
			'featured_url' => '',
			'attribution' => '',
			'attribution_url' => '',
			'relevance' => 'missing',
			'search_terms' => $context['keywords'] ?? [],
		];
	}
}
