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
		$timeout_filter = static function ($feed): void {
			if (is_object($feed) && method_exists($feed, 'set_timeout')) {
				$feed->set_timeout(8);
			}
		};
		// WP/SimplePie default cache = 12h через transients (хранятся в
		// Redis когда redis-cache active). Это блокирует свежие items:
		// pipeline видит stale RSS из кеша часами. Cut до 5 мин — больше
		// чем collect interval (15 мин), но мало для freshness.
		$cache_lifetime_filter = static function (): int {
			return 5 * MINUTE_IN_SECONDS;
		};
		add_filter('wp_feed_cache_transient_lifetime', $cache_lifetime_filter);
		add_action('wp_feed_options', $timeout_filter, 10, 1);
		$feed = fetch_feed($url);
		remove_action('wp_feed_options', $timeout_filter, 10);
		remove_filter('wp_feed_cache_transient_lifetime', $cache_lifetime_filter);
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

			$image = self::extract_item_image($item, $url);

			$items[] = [
				'title' => $title,
				'url' => $link,
				'content' => (string) ($item->get_content() ?: $item->get_description()),
				'excerpt' => (string) $item->get_description(),
				'date' => (string) $item->get_date('c'),
				'author' => ($author = $item->get_author()) ? (string) $author->get_name() : '',
				'image' => $image,
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

	private static function extract_item_image($item, string $feed_url = ''): string {
		if (! is_object($item)) {
			return '';
		}

		$bingNamespaces = [];
		$feed_url = trim($feed_url);
		if ($feed_url !== '' && str_contains($feed_url, 'bing.com/news/search')) {
			$bingNamespaces = array_values(array_unique(array_filter([
				$feed_url,
				html_entity_decode($feed_url, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				str_replace('&', '&amp;', $feed_url),
			])));
		}

		$namespaceCandidates = [];
		foreach ($bingNamespaces as $namespace) {
			$namespaceCandidates[] = [$namespace, 'Image'];
		}
		$namespaceCandidates = array_merge($namespaceCandidates, [
			['http://search.yahoo.com/mrss/', 'content'],
			['http://search.yahoo.com/mrss/', 'thumbnail'],
			['http://search.yahoo.com/mrss', 'content'],
			['http://search.yahoo.com/mrss', 'thumbnail'],
		]);

		foreach ($namespaceCandidates as [$namespace, $tag]) {
			$tagImage = self::first_item_tag_image($item, $namespace, $tag);
			if ($tagImage !== '') {
				if ($tag === 'Image' && str_contains($tagImage, 'bing.com/th?id=')) {
					return self::expand_bing_news_image($item, $namespace, $tagImage);
				}
				return $tagImage;
			}
		}

		$enclosure = $item->get_enclosure();
		if (is_object($enclosure)) {
			foreach (['get_link', 'link'] as $accessor) {
				if (! method_exists($enclosure, $accessor) && ! property_exists($enclosure, $accessor)) {
					continue;
				}
				$value = method_exists($enclosure, $accessor)
					? (string) $enclosure->{$accessor}()
					: (string) ($enclosure->{$accessor} ?? '');
				$value = esc_url_raw(trim($value));
				if (self::looks_like_image_url($value)) {
					return $value;
				}
			}
		}

		return '';
	}

	private static function first_item_tag_image($item, string $namespace, string $tag): string {
		$values = $item->get_item_tags($namespace, $tag);
		if (! is_array($values) || $values === []) {
			return '';
		}
		foreach ($values as $value) {
			if (! is_array($value)) {
				continue;
			}
			$attrs = (array) ($value['attribs'][''] ?? []);
			foreach (['url', 'href', 'src'] as $key) {
				$candidate = esc_url_raw(trim((string) ($attrs[$key] ?? '')));
				if (self::looks_like_image_url($candidate)) {
					return $candidate;
				}
			}
			$data = esc_url_raw(trim((string) ($value['data'] ?? '')));
			if (self::looks_like_image_url($data)) {
				return $data;
			}
		}
		return '';
	}

	private static function expand_bing_news_image($item, string $namespace, string $url): string {
		$sizeTemplate = self::first_item_tag_value($item, $namespace, 'ImageSize');
		$maxWidth = (int) self::first_item_tag_value($item, $namespace, 'ImageMaxWidth');
		$maxHeight = (int) self::first_item_tag_value($item, $namespace, 'ImageMaxHeight');
		$width = $maxWidth > 0 ? $maxWidth : 1200;
		$height = $maxHeight > 0 ? $maxHeight : 675;

		if ($sizeTemplate !== '') {
			$sizeTemplate = str_replace(['{0}', '{1}'], [(string) $width, (string) $height], $sizeTemplate);
			$sizeTemplate = ltrim($sizeTemplate, '?&');
			if ($sizeTemplate !== '') {
				$separator = str_contains($url, '?') ? '&' : '?';
				return $url . $separator . $sizeTemplate;
			}
		}

		$separator = str_contains($url, '?') ? '&' : '?';
		return $url . $separator . 'w=' . $width . '&h=' . $height . '&c=14';
	}

	private static function first_item_tag_value($item, string $namespace, string $tag): string {
		$values = $item->get_item_tags($namespace, $tag);
		if (! is_array($values) || $values === []) {
			return '';
		}
		foreach ($values as $value) {
			if (! is_array($value)) {
				continue;
			}
			$data = trim((string) ($value['data'] ?? ''));
			if ($data !== '') {
				return $data;
			}
		}
		return '';
	}

	private static function looks_like_image_url(string $url): bool {
		if ($url === '') {
			return false;
		}
		if (preg_match('#^https?://#i', $url) !== 1) {
			return false;
		}
		$path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));
		if ($path === '') {
			return str_contains($url, 'th?id=');
		}
		if (preg_match('/\.(svg|ico)$/i', $path) === 1) {
			return false;
		}
		if (preg_match('/\.(jpg|jpeg|png|webp|avif|gif)(?:$|\?)/i', $path) === 1) {
			return true;
		}
		return str_contains($url, 'th?id=');
	}
}
