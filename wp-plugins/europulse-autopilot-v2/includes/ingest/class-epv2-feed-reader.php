<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Feed_Reader {
	public static function fetch(string $url, bool $google_news = false): array {
		if ($url === '') {
			return [];
		}

		include_once ABSPATH . WPINC . '/feed.php';
		$feed = fetch_feed($url);
		if (is_wp_error($feed)) {
			throw new RuntimeException($feed->get_error_message());
		}

		$items = [];
		foreach ((array) $feed->get_items(0, 15) as $item) {
			$link = (string) $item->get_link();
			if ($google_news) {
				$resolved = EPV2_Google_News::resolve_url($link);
				if ($resolved) {
					$link = $resolved;
				}
			}

			$title = (string) $item->get_title();
			if ($google_news && preg_match('/^(.+?)\s*-\s*([^-]+)$/u', $title, $m)) {
				$title = trim($m[1]);
			}

			$items[] = [
				'title' => $title,
				'url' => $link,
				'content' => (string) ($item->get_content() ?: $item->get_description()),
				'excerpt' => (string) $item->get_description(),
				'date' => (string) $item->get_date('c'),
				'author' => ($author = $item->get_author()) ? (string) $author->get_name() : '',
				'image' => '',
			];
		}

		return $items;
	}

	public static function preview(string $url, bool $google_news = false): array {
		try {
			$items = self::fetch($url, $google_news);
			return [
				'success' => true,
				'count' => count($items),
				'preview' => array_slice($items, 0, 5),
			];
		} catch (Throwable $e) {
			return ['success' => false, 'message' => $e->getMessage()];
		}
	}
}
