<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_SEO_Manager {
	public static function build_meta(array $de_payload, array $context, array $media): array {
		$title = trim((string) ($de_payload['title'] ?? ''));
		$lead = trim((string) ($de_payload['lead'] ?? ''));
		$keywords = array_values(array_filter(array_map('strval', (array) ($context['keywords'] ?? []))));
		$slug_base = $title !== '' ? $title : (string) ($context['category'] ?? 'news');

		return [
			'seo_title' => self::trim_title($title),
			'meta_description' => self::trim_description($lead !== '' ? $lead : (string) ($de_payload['content'] ?? '')),
			'slug' => sanitize_title($slug_base),
			'focus_keywords' => array_slice($keywords, 0, 5),
			'google_image_ready' => ! empty($media['featured_url']),
		];
	}

	private static function trim_title(string $title): string {
		$title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
		return mb_substr($title, 0, 65);
	}

	private static function trim_description(string $text): string {
		$text = trim(wp_strip_all_tags($text));
		$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
		return mb_substr($text, 0, 160);
	}
}
