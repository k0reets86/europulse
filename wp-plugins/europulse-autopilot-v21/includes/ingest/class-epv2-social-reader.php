<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Social_Reader {
	public static function fetch(string $type, string $url, int $limit = 12, array $rules = []): array {
		return match ($type) {
			'telegram' => self::fetch_telegram($url, $limit),
			'facebook' => self::fetch_facebook($url, $limit, $rules),
			default => [],
		};
	}

	public static function preview(string $type, string $url, int $limit = 5, array $rules = []): array {
		try {
			$items = self::fetch($type, $url, $limit, $rules);
			return [
				'success' => true,
				'count' => count($items),
				'preview' => $items,
			];
		} catch (Throwable $e) {
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}
	}

	private static function fetch_telegram(string $url, int $limit): array {
		$url = self::normalize_telegram_url($url);
		$response = wp_remote_get($url, [
			'timeout' => 20,
			'redirection' => 5,
			'user-agent' => 'Mozilla/5.0 (compatible; EuroPulse AutoPilot)',
		]);
		if (is_wp_error($response)) {
			throw new RuntimeException($response->get_error_message());
		}
		self::assert_html_response($response, $url);
		$body = (string) wp_remote_retrieve_body($response);
		self::assert_html_body($body, $url);
		[$dom, $xpath] = self::load_dom($body);
		$nodes = $xpath->query('//*[contains(@class,"tgme_widget_message_wrap")]');
		$items = [];
		if (! $nodes) {
			return $items;
		}
		foreach ($nodes as $node) {
			$textNode = $xpath->query('.//*[contains(@class,"tgme_widget_message_text")]', $node);
			$text = $textNode && $textNode->length ? trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($textNode->item(0)->textContent)) ?: '') : '';
			if (mb_strlen($text) < 40) {
				continue;
			}
			$linkNode = $xpath->query('.//a[contains(@class,"tgme_widget_message_date")]', $node);
			$link = $linkNode && $linkNode->length ? trim((string) $linkNode->item(0)->getAttribute('href')) : '';
			if ($link === '') {
				continue;
			}
			$title = self::excerpt_words($text, 14);
			$dateNode = $xpath->query('.//time[@datetime]', $node);
			$date = $dateNode && $dateNode->length ? trim((string) $dateNode->item(0)->getAttribute('datetime')) : '';
			$image = '';
			$photoNode = $xpath->query('.//*[contains(@class,"tgme_widget_message_photo_wrap")]', $node);
			if ($photoNode && $photoNode->length) {
				$style = (string) $photoNode->item(0)->getAttribute('style');
				if (preg_match('/url\\([\'"]?([^\'")]+)[\'"]?\\)/i', $style, $m)) {
					$image = $m[1];
				}
			}
			$items[] = [
				'title' => sanitize_text_field($title),
				'url' => esc_url_raw($link),
				'content' => wp_kses_post($text),
				'excerpt' => sanitize_text_field(self::excerpt_chars($text, 220)),
				'date' => $date,
				'author' => '',
				'image' => esc_url_raw($image),
			];
			if (count($items) >= $limit) {
				break;
			}
		}
		return $items;
	}

	private static function fetch_facebook(string $url, int $limit, array $rules): array {
		if (preg_match('#/rss|rss-bridge|format=Atom|format=rss#i', $url)) {
			return EPV2_Feed_Reader::fetch($url, false);
		}
		return EPV2_HTML_Reader::fetch_listing($url, $limit, $rules);
	}

	private static function normalize_telegram_url(string $url): string {
		$url = trim($url);
		if (preg_match('#^https?://t\.me/([^/?\#]+)$#i', $url, $m)) {
			return 'https://t.me/s/' . $m[1];
		}
		return preg_replace('#^http://#i', 'https://', $url) ?: $url;
	}

	private static function load_dom(string $html): array {
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
		libxml_clear_errors();
		return [$dom, new DOMXPath($dom)];
	}

	private static function assert_html_response(array $response, string $url): void {
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			throw new RuntimeException(sprintf('Unexpected HTTP status %d for %s', $code, $url));
		}
		$content_type = strtolower(trim((string) wp_remote_retrieve_header($response, 'content-type')));
		if ($content_type !== '' && strpos($content_type, 'html') === false) {
			throw new RuntimeException(sprintf('Unexpected content type "%s" for %s', $content_type, $url));
		}
	}

	private static function assert_html_body(string $body, string $url): void {
		$body = trim($body);
		if ($body === '') {
			throw new RuntimeException(sprintf('Empty HTML response for %s', $url));
		}
		$snippet = strtolower(substr(wp_strip_all_tags($body), 0, 4000));
		foreach ([
			'captcha',
			'access denied',
			'forbidden',
			'cloudflare',
			'attention required',
			'consent',
			'cookie settings',
			'verify you are human',
			'please enable javascript',
			'temporarily unavailable',
			'too many requests',
		] as $pattern) {
			if (strpos($snippet, $pattern) !== false) {
				throw new RuntimeException(sprintf('Blocked or interstitial HTML response detected for %s', $url));
			}
		}
		if (stripos($body, '<html') === false && stripos($body, '<!doctype html') === false) {
			throw new RuntimeException(sprintf('Response is not an HTML document for %s', $url));
		}
	}

	private static function excerpt_words(string $text, int $words): string {
		$parts = preg_split('/\s+/u', trim($text)) ?: [];
		return trim(implode(' ', array_slice($parts, 0, $words)));
	}

	private static function excerpt_chars(string $text, int $limit): string {
		$text = trim($text);
		return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)) . '…' : $text;
	}
}
