<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Hard-rejection content filters for EuroPulse.
 *
 * Detects items that look like NEWS but are actually:
 *   - Hub / index / topic-thread pages ("Top Stories", "Live Updates" landing
 *     pages on Kyiv Post, BBC News, Reuters Live etc.)
 *   - Subscription / paywall placeholder pages ("Subscribe to read", "Continue
 *     for €X")
 *   - Tag / category browse pages
 *   - Newsletter-promo or "follow us" pages
 *
 * These items should NEVER reach the rewriter — they have no factual content,
 * just promo-text about the publisher's coverage. The central soft_terminal
 * guard recognises 'hard_editorial:meta_index_page' as a genuinely terminal
 * reason, so flagged items go to rejected and stay there.
 */
final class EPV2_Content_Filters {

	public static function detect_meta_index_page(string $url, string $title, string $excerpt = '', string $content = ''): string {
		$reason = self::detect_meta_index_by_url($url);
		if ($reason !== '') {
			return $reason;
		}
		$haystack = strtolower(trim($title . ' ' . $excerpt . ' ' . wp_strip_all_tags($content)));
		$reason = self::detect_meta_index_by_text($haystack);
		if ($reason !== '') {
			return $reason;
		}
		return '';
	}

	private static function detect_meta_index_by_url(string $url): string {
		if ($url === '') {
			return '';
		}
		$path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
		$path = strtolower($path);
		$patterns = [
			'/^\/thread\//'                        => 'thread_hub',
			'/^\/topic\//'                         => 'topic_hub',
			'/^\/topics\//'                        => 'topic_hub',
			'/^\/section\//'                       => 'section_hub',
			'/^\/category\//'                      => 'category_hub',
			'/^\/categories\//'                    => 'category_hub',
			'/^\/tag\//'                           => 'tag_hub',
			'/^\/tags\//'                          => 'tag_hub',
			'/^\/live\/?$/'                        => 'live_hub',
			'/^\/news\/?$/'                        => 'news_hub',
			'/^\/breaking-news\/?$/'               => 'breaking_hub',
			'/^\/latest\/?$/'                      => 'latest_hub',
			'/^\/(.*\/)?author\//'                 => 'author_hub',
			'/^\/(.*\/)?page\/\d+\/?$/'            => 'pagination_hub',
		];
		foreach ($patterns as $regex => $kind) {
			if (preg_match($regex, $path) === 1) {
				return 'meta_index_page:' . $kind;
			}
		}
		return '';
	}

	private static function detect_meta_index_by_text(string $haystack): string {
		if ($haystack === '') {
			return '';
		}
		// Title-style markers strongly indicating a hub/index page.
		$strong_title_patterns = [
			'/\btop stories?\s+(and|&)\s+breaking\b/iu',
			'/\bbreaking\s+(news|updates)\s+(and|&|from)\b/iu',
			'/\blive\s+(updates|blog|ticker)\s+(of|on|about)\b/iu',
			'/\b(news|updates)\s+today\s+[-—–]\s+top\s+stories\b/iu',
			'/\b(latest|all)\s+(news|stories)\s+from\b/iu',
			'/\bheadlines?\s+(and|&)\s+(updates|stories)\b/iu',
			'/\b(meta|themen)seite\s+/iu',
			'/\büberblick\s+aller\s+(meldungen|themen)\b/iu',
		];
		foreach ($strong_title_patterns as $regex) {
			if (preg_match($regex, $haystack) === 1) {
				return 'meta_index_page:hub_title_pattern';
			}
		}
		// Body-style markers used by hub pages: "this page compiles", "real-time
		// snapshot", "live-ticker-style overview", "we update this regularly".
		$body_patterns = [
			'/(kompiliert|compiles)\s+die\s+(seite|page)\s+(top|wichtig)/iu',
			'/(real-time|echtzeit)\s+(snapshot|schnappschuss)\b/iu',
			'/live[-\s]?ticker[-\s]?(ähnliche|style|like)\b/iu',
			'/this page\s+(compiles|lists|aggregates|gathers)\b/iu',
			'/(we|the team)\s+(update|aktualisieren)\s+(this|diese)\s+(page|seite)\s+(regularly|fortlaufend|continuously)/iu',
			'/\bsubscribe\s+(to read|to continue|for full)\b/iu',
			'/\babonnieren\s+sie\s+(zum|für|um)\b/iu',
		];
		foreach ($body_patterns as $regex) {
			if (preg_match($regex, $haystack) === 1) {
				return 'meta_index_page:hub_body_pattern';
			}
		}
		return '';
	}

	public static function payload_is_meta_index(array $payload, ?object $item = null): string {
		$url   = (string) ($item->original_url ?? $payload['_meta']['source_dossier']['primary']['url'] ?? '');
		$title = (string) ($item->original_title ?? $payload['languages']['de']['title'] ?? '');
		$excerpt = (string) ($item->original_excerpt ?? $payload['languages']['de']['excerpt'] ?? '');
		$content = (string) ($item->original_content ?? $payload['languages']['de']['content'] ?? '');
		return self::detect_meta_index_page($url, $title, $excerpt, $content);
	}
}
