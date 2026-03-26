<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_HTML_Reader {
	public static function fetch_document(string $url): array {
		$response = wp_remote_get($url, [
			'timeout' => 6,
			'redirection' => 5,
			'user-agent' => 'Mozilla/5.0 (compatible; EuroPulse AutoPilot)',
		]);
		if (is_wp_error($response)) {
			throw new RuntimeException($response->get_error_message());
		}
		self::assert_html_response($response);

		$body = (string) wp_remote_retrieve_body($response);
		if ($body === '') {
			throw new RuntimeException('Empty HTML response');
		}

		[$dom, $xpath] = self::load_dom($body);
		$base_url = self::pick_base_url($xpath, $url);
		$title = self::pick_title($xpath, $dom);
		if (self::looks_like_interstitial_page($url, $title, $body)) {
			throw new RuntimeException('Interstitial or consent page');
		}
		$image = self::pick_image($xpath, $base_url);
		$video = self::pick_meta_content($xpath, 'og:video', $base_url)
			?: self::pick_meta_content($xpath, 'twitter:player', $base_url)
			?: self::pick_video_src($xpath, $base_url);
		$paragraphs = self::pick_paragraphs($xpath);
		$content = trim(implode("\n\n", $paragraphs));
		$excerpt = trim(implode(' ', array_slice($paragraphs, 0, 2)));

		return [
			'title' => $title,
			'url' => esc_url_raw($url),
			'content' => $content,
			'excerpt' => $excerpt,
			'image' => $image,
			'video' => $video,
		];
	}

	private static function looks_like_interstitial_page(string $url, string $title, string $body): bool {
		$url = mb_strtolower($url);
		$title = mb_strtolower(trim($title));
		$body = mb_strtolower(wp_strip_all_tags($body));
		if (preg_match('#://(www\.)?news\.google\.com/#u', $url) === 1) {
			if (
				str_contains($title, 'before you continue to google')
				|| str_contains($body, 'before you continue to google')
				|| str_contains($body, 'continue to google')
				|| str_contains($body, 'consent')
				|| str_contains($body, 'cookies')
			) {
				return true;
			}
		}
		return preg_match('/\bbefore you continue to google\b|\bcontinue to google\b|\bconsent\.google\b|\bgoogle cookies\b/u', $title . ' ' . mb_substr($body, 0, 1200)) === 1;
	}

	public static function fetch_listing(string $url, int $limit = 10, array $rules = []): array {
		$response = wp_remote_get($url, [
			'timeout' => 6,
			'redirection' => 5,
			'user-agent' => 'Mozilla/5.0 (compatible; EuroPulse AutoPilot)',
		]);
		if (is_wp_error($response)) {
			throw new RuntimeException($response->get_error_message());
		}
		self::assert_html_response($response);

		$body = (string) wp_remote_retrieve_body($response);
		if ($body === '') {
			throw new RuntimeException('Empty HTML response');
		}

		[$dom, $xpath] = self::load_dom($body);
		$base_url = self::pick_base_url($xpath, $url);
		$query = trim((string) ($rules['listing_xpath'] ?? ''));
		if ($query === '') {
			$query = '//article//a[@href] | //main//h1/a[@href] | //main//h2/a[@href] | //main//h3/a[@href] | //main//a[@href] | //a[@href and (contains(@class,"headline") or contains(@class,"title") or contains(@class,"event"))]';
		}
		$nodes = $xpath->query($query);
		$items = [];
		$seen = [];

		if ($nodes) {
			foreach ($nodes as $node) {
				$link = trim((string) $node->getAttribute('href'));
				$title = trim(wp_strip_all_tags($node->textContent));
				if ($link === '' || $title === '' || mb_strlen($title) < 8) {
					continue;
				}
				if (str_starts_with($link, '#') || str_starts_with($link, 'mailto:') || str_starts_with($link, 'javascript:')) {
					continue;
				}
				$link = self::absolutize_url($base_url, $link);
				if (! filter_var($link, FILTER_VALIDATE_URL) || isset($seen[$link]) || self::looks_like_noise($title)) {
					continue;
				}
				$seen[$link] = true;
				$items[] = [
					'title' => $title,
					'url' => $link,
					'content' => '',
					'excerpt' => '',
					'date' => '',
					'author' => '',
					'image' => '',
				];
				if (count($items) >= $limit) {
					break;
				}
			}
		}

		return $items;
	}

	public static function preview_listing(string $url, int $limit = 5, array $rules = []): array {
		try {
			$items = self::fetch_listing($url, $limit, $rules);
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

	private static function load_dom(string $html): array {
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
		libxml_clear_errors();
		$xpath = new DOMXPath($dom);
		return [$dom, $xpath];
	}

	private static function pick_title(DOMXPath $xpath, DOMDocument $dom): string {
		$og = self::pick_meta_content($xpath, 'og:title');
		if ($og !== '') {
			return $og;
		}
		$queries = [
			'//main//*[self::h1 or contains(@class,"headline")][not(@hidden) and not(contains(@class,"aural")) and normalize-space()!=""]',
			'//article//*[self::h1 or contains(@class,"headline")][not(@hidden) and not(contains(@class,"aural")) and normalize-space()!=""]',
			'//h1[not(@hidden) and not(contains(@class,"aural")) and normalize-space()!=""]',
		];
		foreach ($queries as $query) {
			$nodes = $xpath->query($query);
			if (! $nodes || ! $nodes->length) {
				continue;
			}
			foreach ($nodes as $node) {
				$text = trim(wp_strip_all_tags($node->textContent));
				if ($text === '' || self::looks_like_noise($text) || preg_match('/^(navigation und service|suche|menü)$/iu', $text) === 1) {
					continue;
				}
				return $text;
			}
		}
		$titleNodes = $dom->getElementsByTagName('title');
		if ($titleNodes->length) {
			$title = trim(wp_strip_all_tags($titleNodes->item(0)->textContent));
			$title = preg_replace('/\s*[-–—|]\s*[^-–—|]+$/u', '', $title) ?: $title;
			return trim($title);
		}
		return '';
	}

	private static function pick_meta_content(DOMXPath $xpath, string $property, string $base_url = ''): string {
		$query = sprintf('//meta[@property="%1$s"]/@content | //meta[@name="%1$s"]/@content', $property);
		$nodes = $xpath->query($query);
		if ($nodes && $nodes->length) {
			$value = trim((string) $nodes->item(0)->nodeValue);
			if ($base_url !== '' && (preg_match('#^(https?:)?//#i', $value) || str_starts_with($value, '/'))) {
				return self::absolutize_url($base_url, $value);
			}
			return $value;
		}
		return '';
	}

	private static function pick_base_url(DOMXPath $xpath, string $fallback_url): string {
		$nodes = $xpath->query('//base[@href][1]/@href');
		if ($nodes && $nodes->length) {
			$base = trim((string) $nodes->item(0)->nodeValue);
			if ($base !== '') {
				return self::absolutize_url($fallback_url, $base);
			}
		}
		return $fallback_url;
	}

	private static function pick_video_src(DOMXPath $xpath, string $base_url = ''): string {
		$nodes = $xpath->query('//video/source[@src][1]/@src | //video[@src][1]/@src | //iframe[@src][contains(@src,"youtube.com") or contains(@src,"youtu.be") or contains(@src,"vimeo.com")][1]/@src');
		if ($nodes && $nodes->length) {
			return self::absolutize_url($base_url, trim((string) $nodes->item(0)->nodeValue));
		}
		return '';
	}

	private static function pick_image(DOMXPath $xpath, string $base_url): string {
		$candidates = [];
		foreach (['og:image', 'twitter:image', 'twitter:image:src'] as $property) {
			$image = self::pick_meta_content($xpath, $property, $base_url);
			if ($image !== '') {
				$candidates[] = $image;
			}
		}

		$nodes = $xpath->query('//article//img | //main//img | //img');
		if ($nodes) {
			foreach ($nodes as $node) {
				$src = self::pick_node_image_src($node);
				if ($src === '') {
					continue;
				}
				$candidates[] = self::absolutize_url($base_url, $src);
				if (count($candidates) >= 12) {
					break;
				}
			}
		}

		foreach (array_values(array_unique(array_filter($candidates))) as $candidate) {
			if (! self::looks_like_article_image($candidate)) {
				continue;
			}
			return $candidate;
		}

		return '';
	}

	private static function pick_node_image_src(DOMElement $node): string {
		foreach (['src', 'data-src', 'data-lazy-src', 'data-original', 'data-image-src'] as $attribute) {
			$value = trim((string) $node->getAttribute($attribute));
			if ($value !== '') {
				return $value;
			}
		}
		$srcset = trim((string) $node->getAttribute('srcset'));
		if ($srcset !== '') {
			$parts = array_map('trim', explode(',', $srcset));
			if ($parts !== []) {
				$best = trim((string) end($parts));
				$best = preg_replace('/\s+\d+[wx]$/i', '', $best) ?: $best;
				return trim($best);
			}
		}
		return '';
	}

	private static function pick_paragraphs(DOMXPath $xpath): array {
		$queries = [
			'//article//p',
			'//main//p',
			'//p',
		];
		foreach ($queries as $query) {
			$nodes = $xpath->query($query);
			$paragraphs = [];
			if ($nodes) {
				foreach ($nodes as $node) {
					$text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($node->textContent)) ?: '');
					if (mb_strlen($text) < 40 || self::looks_like_noise($text)) {
						continue;
					}
					$paragraphs[] = $text;
					if (count($paragraphs) >= 8) {
						break;
					}
				}
			}
			if (! empty($paragraphs)) {
				return $paragraphs;
			}
		}
		return [];
	}

	private static function absolutize_url(string $base, string $url): string {
		if ($url === '') {
			return '';
		}
		if (preg_match('#^https?://#i', $url)) {
			return $url;
		}
		$baseParts = wp_parse_url($base);
		if (empty($baseParts['scheme']) || empty($baseParts['host'])) {
			return $url;
		}
		if (str_starts_with($url, '//')) {
			return $baseParts['scheme'] . ':' . $url;
		}
		if (str_starts_with($url, '/')) {
			return $baseParts['scheme'] . '://' . $baseParts['host'] . $url;
		}
		$path = isset($baseParts['path']) ? dirname($baseParts['path']) : '';
		return $baseParts['scheme'] . '://' . $baseParts['host'] . rtrim($path, '/') . '/' . ltrim($url, '/');
	}

	private static function looks_like_article_image(string $url): bool {
		$path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));
		if ($path === '') {
			return false;
		}
		if (preg_match('/\.(svg|ico)$/i', $path)) {
			return false;
		}
		if (preg_match('/logo|icon|sprite|avatar|share|social|banner|placeholder/u', $path)) {
			return false;
		}
		return preg_match('/\.(jpg|jpeg|png|webp|avif)(?:$|\?)/i', $path) === 1;
	}

	private static function assert_html_response($response): void {
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			throw new RuntimeException('Unexpected HTTP status: ' . $code);
		}
		$content_type = (string) wp_remote_retrieve_header($response, 'content-type');
		if ($content_type !== '' && preg_match('#(text/html|application/xhtml\\+xml|application/xml|text/xml)#i', $content_type) !== 1) {
			throw new RuntimeException('Unexpected content-type: ' . $content_type);
		}
	}

	private static function looks_like_noise(string $text): bool {
		$text = mb_strtolower(trim($text));
		$phrases = [
			'wir verwenden cookies',
			'cookies',
			'datenschutz',
			'privacy',
			'matomo',
			'auswertung ihrer daten',
			'zustimmen',
			'ablehnen',
			'newsletter abonnieren',
			'skip to content',
			'mehr infos',
			'read more',
			'über uns',
			'e-mail',
			'kontakt',
			'impressum',
		];
		foreach ($phrases as $phrase) {
			if (str_contains($text, $phrase)) {
				return true;
			}
		}
		return false;
	}
}
