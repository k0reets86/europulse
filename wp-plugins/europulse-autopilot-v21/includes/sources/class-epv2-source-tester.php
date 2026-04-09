<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Source_Tester {
	public static function test(array $source): array {
		$type = $source['type'] ?? 'rss';
		if ($type === 'rss' || $type === 'atom' || $type === 'google_news') {
			return EPV2_Feed_Reader::preview((string) ($source['url'] ?? ''), $type === 'google_news');
		}
		if ($type === 'scrape') {
			$rules = $source['parse_rules'] ?? [];
			if (is_string($rules)) {
				$decoded = json_decode($rules, true);
				$rules = is_array($decoded) ? $decoded : [];
			}
			if (empty($rules)) {
				$rules = EPV2_Source_Adapters::rules_for((string) ($source['url'] ?? ''));
			}
			if (($rules['mode'] ?? '') === 'single_page') {
				try {
					$data = EPV2_HTML_Reader::fetch_document((string) ($source['url'] ?? ''));
					return [
						'success' => true,
						'count' => 1,
						'preview' => [[
							'title' => (string) ($data['title'] ?? ''),
							'url' => (string) ($data['url'] ?? ''),
							'excerpt' => (string) ($data['excerpt'] ?? ''),
						]],
					];
				} catch (Throwable $e) {
					return ['success' => false, 'message' => $e->getMessage()];
				}
			}
			return EPV2_HTML_Reader::preview_listing((string) ($source['url'] ?? ''), 5, is_array($rules) ? $rules : []);
		}
		if (in_array($type, ['telegram', 'facebook'], true)) {
			$rules = $source['parse_rules'] ?? [];
			if (is_string($rules)) {
				$decoded = json_decode($rules, true);
				$rules = is_array($decoded) ? $decoded : [];
			}
			if (empty($rules)) {
				$rules = EPV2_Source_Adapters::rules_for((string) ($source['url'] ?? ''));
			}
			return EPV2_Social_Reader::preview($type, (string) ($source['url'] ?? ''), 5, is_array($rules) ? $rules : []);
		}

		return [
			'success' => false,
			'message' => 'Source tester for this type is not implemented yet.',
		];
	}
}
