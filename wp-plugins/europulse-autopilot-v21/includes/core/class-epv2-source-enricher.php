<?php

if (! defined('ABSPATH')) {
	exit;
}

	final class EPV2_Source_Enricher {
	public static function enrich_item(object $item, array $options = []): array {
		$item = EPV2_Google_News::normalize_item_source($item);
		$force_supporting = ! empty($options['force_supporting']);
		$target_supporting = max(2, min(5, (int) ($options['target_supporting'] ?? 2)));
		$context_memory = self::normalize_context_memory((array) ($options['context_memory'] ?? []));
		// Budget bump 2026-05-11 (P0.1 fix): default 6s was too tight for
		// 3 supporting URLs × (200-900ms fetch + DOM parse). Live audit
		// of 20/20 published items showed empty supporting.content despite
		// successful fetches. Reasonable URL fetch + parse takes 1-2 sec,
		// 3 URLs = 3-6 sec + search latency (1-3 sec). Total 6-10 sec.
		// Bumped к 12 sec default — accommodates 3-4 URLs reliably.
		$max_runtime_seconds = max(2, min(30, (int) ($options['max_runtime_seconds'] ?? 12)));
		$deadline = microtime(true) + $max_runtime_seconds;
		$dossier = [
			'primary' => self::source_entry((string) ($item->original_url ?? ''), (string) ($item->original_title ?? ''), (string) ($item->original_excerpt ?? ''), (string) ($item->original_content ?? ''), (string) ($item->source_image_url ?? '')),
			'supporting' => [],
			'used_search' => false,
			'quotes' => [],
			'context_memory' => $context_memory,
			'story_context' => [],
			'event_context' => [],
		];

		$primaryDoc = self::fetch_document_safe((string) ($item->original_url ?? ''));
		if ($primaryDoc !== []) {
			$dossier['primary'] = self::source_entry(
				(string) ($primaryDoc['url'] ?? $item->original_url ?? ''),
				(string) ($primaryDoc['title'] ?? $item->original_title ?? ''),
				(string) ($primaryDoc['excerpt'] ?? $item->original_excerpt ?? ''),
				(string) ($primaryDoc['content'] ?? $item->original_content ?? ''),
				(string) ($primaryDoc['image'] ?? $item->source_image_url ?? '')
			);
		}
		if (self::primary_is_google_wrapper($dossier['primary'])) {
			$dossier['primary']['title'] = sanitize_text_field((string) ($item->original_title ?? $dossier['primary']['title'] ?? ''));
			$dossier['primary']['excerpt'] = sanitize_text_field((string) ($item->original_excerpt ?? $dossier['primary']['excerpt'] ?? ''));
			$dossier['primary']['content'] = sanitize_text_field(wp_strip_all_tags((string) ($item->original_content ?? $dossier['primary']['content'] ?? '')));
		}
		if (trim((string) ($dossier['primary']['image'] ?? '')) === '') {
			$primaryImageFallback = self::same_story_media_fallback($item, $dossier['primary'], $deadline);
			if ($primaryImageFallback !== '') {
				$dossier['primary']['image'] = $primaryImageFallback;
			}
		}
		$dossier['quotes'] = self::extract_quotes_from_entry($dossier['primary']);
		$dossier['story_context'] = self::extract_story_context($item, $dossier, $context_memory);
		$dossier['event_context'] = self::merge_context_memory(self::extract_event_context($item, $dossier), $context_memory);

		$should_search_supporting = $force_supporting
			|| self::is_poor_signal($dossier['primary'])
			|| self::should_force_supporting($dossier['primary'])
			|| self::needs_substance_support($dossier['primary'], $dossier['quotes'])
			|| self::needs_visual_support($dossier['primary']);
		$category = (string) ($item->category_proposed ?? '');
		if (in_array($category, ['sport', 'kultur', 'community', 'wirtschaft', 'world'], true)) {
			$should_search_supporting = true;
		}
		if (! $should_search_supporting) {
			return $dossier;
		}

		$candidates = self::search_supporting_sources($item, $dossier['primary'], $context_memory, $deadline);
		if ($force_supporting && $candidates === []) {
			$candidates = self::search_supporting_sources_from_broad_pool($item, $dossier['primary'], $context_memory, $deadline);
		}
		$dossier['used_search'] = true;
		$seen = [self::host((string) ($dossier['primary']['url'] ?? '')) => true];
		foreach ($candidates as $candidate) {
			if (self::timeout_exceeded($deadline)) {
				break;
			}
			$url = (string) ($candidate['url'] ?? '');
			$host = self::host($url);
			if ($url === '' || isset($seen[$host])) {
				continue;
			}
			$candidateEntry = self::source_entry(
				$url,
				(string) ($candidate['title'] ?? ''),
				(string) ($candidate['excerpt'] ?? ''),
				'',
				(string) ($candidate['image'] ?? '')
			);
			if (! self::candidate_entry_is_story_relevant($candidateEntry, $item, $dossier['primary'])) {
				continue;
			}
			$doc = self::fetch_document_safe($url);
			$entry = $doc !== []
				? self::source_entry((string) ($doc['url'] ?? $url), (string) ($doc['title'] ?? $candidate['title'] ?? ''), (string) ($doc['excerpt'] ?? ''), (string) ($doc['content'] ?? ''), (string) ($doc['image'] ?? ''))
				: self::source_entry($url, (string) ($candidate['title'] ?? ''), (string) ($candidate['excerpt'] ?? ''), '', (string) ($candidate['image'] ?? ''));
			if (! self::candidate_entry_is_story_relevant($entry, $item, $dossier['primary'])) {
				continue;
			}
			$has_visual_support = ! empty($entry['image']) && EPV2_Media::is_relevant_media(
				(string) $entry['image'],
				(string) ($dossier['primary']['title'] ?? $item->original_title ?? ''),
				(string) ($dossier['primary']['excerpt'] ?? $item->original_excerpt ?? ''),
				array_values(array_filter(array_map('trim', explode(',', (string) ($item->category_proposed ?? ''))))),
				$dossier
			);
			// STRICT: require actual content OR substantial excerpt (P0.1
			// fix 2026-05-11). support_entry_can_be_brief loophole previously
			// admitted entries с empty content if title ≥55 chars OR excerpt
			// ≥90 chars — these URL-only stubs (no content body) feed AI
			// nothing about supporting story, causing fabrication. Now:
			// either ≥280 chars content OR ≥200 chars excerpt — anything
			// less is useless context. has_visual_support remains valid path.
			$ent_content_len = mb_strlen((string) ($entry['content'] ?? ''));
			$ent_excerpt_len = mb_strlen((string) ($entry['excerpt'] ?? ''));
			if (
				$ent_content_len < 280
				&& $ent_excerpt_len < 200
				&& ! $has_visual_support
			) {
				if (class_exists('EPV2_Logger')) {
					EPV2_Logger::info('source_enricher', 'supporting_dropped_empty', [
						'url' => $url,
						'content_len' => $ent_content_len,
						'excerpt_len' => $ent_excerpt_len,
						'has_visual_support' => $has_visual_support ? 1 : 0,
					]);
				}
				continue;
			}
			$dossier['supporting'][] = $entry;
			$dossier['quotes'] = array_values(array_slice(array_merge($dossier['quotes'], self::extract_quotes_from_entry($entry)), 0, 5));
			$seen[$host] = true;
			if (count($dossier['supporting']) >= $target_supporting) {
				break;
			}
		}

		if (self::primary_needs_support_promotion($dossier['primary']) && $dossier['supporting'] !== []) {
			$promoted = self::best_supporting_primary_candidate($dossier['supporting']);
			if ($promoted !== []) {
				$dossier['shell_primary'] = $dossier['primary'];
				$dossier['primary'] = $promoted;
				$dossier['supporting'] = array_values(array_filter($dossier['supporting'], static function (array $entry) use ($promoted): bool {
					return (string) ($entry['url'] ?? '') !== (string) ($promoted['url'] ?? '');
				}));
			}
		}

		$dossier['supporting'] = self::filter_supporting_entries((array) ($dossier['supporting'] ?? []), $item, $dossier['primary']);
		if ($force_supporting && $dossier['supporting'] === [] && $candidates !== []) {
			$dossier['supporting'] = self::salvage_supporting_entries($candidates, $item, $dossier['primary'], $target_supporting, $deadline);
		}

		$dossier['quotes'] = self::extract_quotes_from_entry($dossier['primary']);
		foreach ((array) $dossier['supporting'] as $entry) {
			$dossier['quotes'] = array_values(array_slice(array_merge($dossier['quotes'], self::extract_quotes_from_entry($entry)), 0, 5));
		}
		$dossier['story_context'] = self::extract_story_context($item, $dossier, $context_memory);
		$dossier['event_context'] = self::merge_context_memory(self::extract_event_context($item, $dossier), $context_memory);

		return $dossier;
	}

	private static function same_story_media_fallback(object $item, array $primary, ?float $deadline = null): string {
		$primaryTitleRaw = trim(html_entity_decode((string) ($primary['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$itemTitleRaw = trim(html_entity_decode((string) ($item->original_title ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$coreTitle = $primaryTitleRaw !== '' ? preg_split('/[:\\-–—|]/u', $primaryTitleRaw, 2)[0] : '';
		$coreTitle = trim((string) $coreTitle);
		$queries = array_values(array_unique(array_filter([
			$coreTitle,
			$primaryTitleRaw,
			$itemTitleRaw,
			self::normalize_query($primaryTitleRaw, 10),
			self::normalize_query($itemTitleRaw, 10),
		])));
		if ($queries === []) {
			return '';
		}

		foreach ($queries as $query) {
			if (self::timeout_exceeded($deadline)) {
				break;
			}
			foreach (self::search_supporting_sources_bing($query, $deadline) as $candidate) {
				if (self::timeout_exceeded($deadline)) {
					break 2;
				}
				if (! self::same_story_syndicated_candidate($candidate, $item, $primary)) {
					continue;
				}
				$image = esc_url_raw((string) ($candidate['image'] ?? ''));
				if ($image === '') {
					continue;
				}
				$validation = EPV2_Media::validate_featured_media($image, 0, (string) ($primary['title'] ?? $item->original_title ?? ''));
				if (! empty($validation['ok']) && ! empty($validation['media']['usable'])) {
					return $image;
				}
			}
		}

		return '';
	}

	public static function has_meaningful_quote(array $dossier): bool {
		foreach ((array) ($dossier['quotes'] ?? []) as $quote) {
			if (! is_array($quote)) {
				continue;
			}
			$text = trim((string) ($quote['text'] ?? ''));
			if (mb_strlen($text) >= 35) {
				return true;
			}
		}
		return false;
	}

	public static function best_source_url(array $dossier, string $fallback = ''): string {
		$primaryUrl = (string) ($dossier['primary']['url'] ?? '');
		if ($primaryUrl !== '') {
			return $primaryUrl;
		}
		return $fallback;
	}

	private static function primary_needs_support_promotion(array $primary): bool {
		$url = (string) ($primary['url'] ?? '');
		$host = self::host($url);
		if ($host === 'news.google.com') {
			return true;
		}
		if (! empty($primary['is_official']) || self::is_official_url($url)) {
			return false;
		}
		if (
			trim((string) ($primary['title'] ?? '')) !== ''
			&& mb_strlen(trim((string) ($primary['content'] ?? ''))) >= 420
			&& ! self::needs_visual_support($primary)
		) {
			return false;
		}
		return self::is_poor_signal($primary);
	}

	private static function best_supporting_primary_candidate(array $supporting): array {
		if ($supporting === []) {
			return [];
		}
		usort($supporting, static function (array $left, array $right): int {
			$leftScore = mb_strlen((string) ($left['content'] ?? ''));
			$rightScore = mb_strlen((string) ($right['content'] ?? ''));
			if (! empty($left['image'])) {
				$leftScore += 180;
			}
			if (! empty($right['image'])) {
				$rightScore += 180;
			}
			if (! empty($left['is_official'])) {
				$leftScore += 120;
			}
			if (! empty($right['is_official'])) {
				$rightScore += 120;
			}
			if ($leftScore === $rightScore) {
				return strcmp((string) ($left['url'] ?? ''), (string) ($right['url'] ?? ''));
			}
			return $rightScore <=> $leftScore;
		});
		return (array) ($supporting[0] ?? []);
	}

	private static function search_supporting_sources(object $item, array $primary, array $context_memory = [], ?float $deadline = null): array {
		$results = [];
		$seen = [];
		$queries = self::build_search_queries($item, $primary, $context_memory);
		$prefer_bing = self::primary_is_google_wrapper($primary) || self::is_poor_signal($primary);
		if ($prefer_bing) {
			foreach ($queries as $query) {
				if (self::timeout_exceeded($deadline)) {
					return $results;
				}
				try {
					foreach (self::search_supporting_sources_bing($query, $deadline) as $candidate) {
						$key = md5((string) ($candidate['url'] ?? '') . '|' . (string) ($candidate['title'] ?? ''));
						if (isset($seen[$key])) {
							continue;
						}
						$seen[$key] = true;
						$results[] = $candidate;
						if (count($results) >= 10) {
							return $results;
						}
					}
				} catch (Throwable $e) {
					EPV2_Logger::warning('source_enricher', 'Bing support search failed', ['query' => $query, 'error' => $e->getMessage()]);
				}
			}
		}
		if (count($results) < 5) {
			foreach ($queries as $query) {
				if (self::timeout_exceeded($deadline)) {
					return $results;
				}
				$lang = 'de';
				$gl = 'DE';
				$ceid = 'DE:de';
				$url = 'https://news.google.com/rss/search?q=' . rawurlencode($query) . '&hl=' . $lang . '&gl=' . $gl . '&ceid=' . $ceid;
				try {
					foreach (EPV2_Feed_Reader::fetch($url, true) as $candidate) {
						if (self::timeout_exceeded($deadline)) {
							return $results;
						}
						$candidateUrl = (string) ($candidate['url'] ?? '');
						if (self::host($candidateUrl) === 'news.google.com') {
							continue;
						}
						$key = md5((string) ($candidate['url'] ?? '') . '|' . (string) ($candidate['title'] ?? ''));
						if (isset($seen[$key])) {
							continue;
						}
						$seen[$key] = true;
						$results[] = $candidate;
						if (count($results) >= 10) {
							return $results;
						}
					}
				} catch (Throwable $e) {
					EPV2_Logger::warning('source_enricher', 'Google News support search failed', ['query' => $query, 'error' => $e->getMessage()]);
				}
			}
		}
		if (count($results) < 5) {
			foreach (self::search_supporting_sources_from_active_sources($item, $primary, $context_memory, $deadline) as $candidate) {
				$key = md5((string) ($candidate['url'] ?? '') . '|' . (string) ($candidate['title'] ?? ''));
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$results[] = $candidate;
				if (count($results) >= 10) {
					return $results;
				}
			}
		}
		if (count($results) < 5) {
			foreach (self::search_supporting_sources_from_context_phrases($item, $primary, $context_memory, $deadline) as $candidate) {
				$key = md5((string) ($candidate['url'] ?? '') . '|' . (string) ($candidate['title'] ?? ''));
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$results[] = $candidate;
				if (count($results) >= 10) {
					return $results;
				}
			}
		}
		if (count($results) < 5 && ! $prefer_bing) {
			foreach ($queries as $query) {
				if (self::timeout_exceeded($deadline)) {
					return $results;
				}
				try {
					foreach (self::search_supporting_sources_bing($query, $deadline) as $candidate) {
						$key = md5((string) ($candidate['url'] ?? '') . '|' . (string) ($candidate['title'] ?? ''));
						if (isset($seen[$key])) {
							continue;
						}
						$seen[$key] = true;
						$results[] = $candidate;
						if (count($results) >= 10) {
							return $results;
						}
					}
				} catch (Throwable $e) {
					EPV2_Logger::warning('source_enricher', 'Bing support search failed', ['query' => $query, 'error' => $e->getMessage()]);
				}
			}
		}
		if (count($results) < 5) {
			foreach ($queries as $query) {
				if (self::timeout_exceeded($deadline)) {
					return $results;
				}
				try {
					foreach (self::search_supporting_sources_bing_web($query, $deadline) as $candidate) {
						$key = md5((string) ($candidate['url'] ?? '') . '|' . (string) ($candidate['title'] ?? ''));
						if (isset($seen[$key])) {
							continue;
						}
						$seen[$key] = true;
						$results[] = $candidate;
						if (count($results) >= 10) {
							return $results;
						}
					}
				} catch (Throwable $e) {
					EPV2_Logger::warning('source_enricher', 'Bing web support search failed', ['query' => $query, 'error' => $e->getMessage()]);
				}
			}
		}
		if (count($results) < 5) {
			foreach (self::search_supporting_sources_from_context_phrases($item, $primary, $context_memory, $deadline, true) as $candidate) {
				$key = md5((string) ($candidate['url'] ?? '') . '|' . (string) ($candidate['title'] ?? ''));
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$results[] = $candidate;
				if (count($results) >= 10) {
					return $results;
				}
			}
		}
		return $results;
	}

	private static function search_supporting_sources_from_context_phrases(object $item, array $primary, array $context_memory = [], ?float $deadline = null, bool $broad_pool = false): array {
		$phrases = self::context_phrase_queries($item, $primary, $context_memory);
		if ($phrases === []) {
			return [];
		}
		$referenceTokens = self::search_tokens(implode(' ', $phrases));
		if ($referenceTokens === []) {
			return [];
		}
		$category = self::effective_category($item, $primary);
		$sourceId = (int) ($item->source_id ?? 0);
		$language = (string) ($item->language ?? '');
		$candidates = [];
		$sources = $broad_pool ? EPV2_Sources::all(true) : self::candidate_source_pool($category, $language, $sourceId);
		foreach ($sources as $source) {
			if (self::timeout_exceeded($deadline)) {
				break;
			}
			$type = (string) ($source->type ?? '');
			if ($broad_pool && ! in_array($type, ['rss', 'atom', 'scrape', 'telegram'], true)) {
				continue;
			}
			try {
				foreach (EPV2_Collector::collect_source($source) as $candidate) {
					if (self::timeout_exceeded($deadline)) {
						break 2;
					}
					$title = trim((string) ($candidate['title'] ?? ''));
					$url = trim((string) ($candidate['url'] ?? ''));
					if ($title === '' || $url === '') {
						continue;
					}
					$phraseScore = self::context_phrase_score($candidate, $phrases);
					if ($phraseScore < 1) {
						continue;
					}
					$score = self::support_candidate_score($candidate, $referenceTokens, $phrases, '') + ($phraseScore * 2);
					if ($score < 2) {
						continue;
					}
					$candidate['_support_score'] = $score;
					$candidates[] = $candidate;
					if (count($candidates) >= 10) {
						break 2;
					}
				}
			} catch (Throwable $e) {
				continue;
			}
		}
		usort($candidates, static function (array $left, array $right): int {
			return (int) ($right['_support_score'] ?? 0) <=> (int) ($left['_support_score'] ?? 0);
		});
		return array_slice($candidates, 0, 10);
	}

	private static function search_supporting_sources_from_active_sources(object $item, array $primary, array $context_memory = [], ?float $deadline = null): array {
		$category = self::effective_category($item, $primary);
		$sourceId = (int) ($item->source_id ?? 0);
		$language = (string) ($item->language ?? '');
		$queries = self::build_search_queries($item, $primary, $context_memory);
		$storyContext = self::extract_story_context_from_primary($item, $primary, $context_memory);
		$referenceText = trim(implode(' ', array_filter([
			(string) ($item->original_title ?? ''),
			(string) ($primary['title'] ?? ''),
			(string) ($primary['excerpt'] ?? ''),
			(string) ($storyContext['body_snippet'] ?? ''),
			implode(' ', (array) ($storyContext['entities'] ?? [])),
			implode(' ', (array) ($storyContext['theme_tokens'] ?? [])),
			implode(' ', (array) ($storyContext['search_terms'] ?? [])),
			self::extract_named_entities((string) ($primary['content'] ?? '')),
		])));
		$referenceTokens = self::search_tokens($referenceText . ' ' . implode(' ', $queries));
		if ($referenceTokens === []) {
			return [];
		}

		$candidates = [];
		$sources = self::candidate_source_pool($category, $language, $sourceId);
		foreach ($sources as $source) {
			if (self::timeout_exceeded($deadline)) {
				break;
			}
			try {
				foreach (EPV2_Collector::collect_source($source) as $candidate) {
					if (self::timeout_exceeded($deadline)) {
						break 2;
					}
					$title = trim((string) ($candidate['title'] ?? ''));
					$url = trim((string) ($candidate['url'] ?? ''));
					if ($title === '' || $url === '') {
						continue;
					}
					$score = self::support_candidate_score($candidate, $referenceTokens, $queries, $category);
					if ($score < 3) {
						continue;
					}
					$candidate['_support_score'] = $score;
					$candidate['_support_source_id'] = (int) ($source->id ?? 0);
					$candidates[] = $candidate;
					if (count($candidates) >= 6) {
						break 2;
					}
				}
			} catch (Throwable $e) {
				EPV2_Logger::warning('source_enricher', 'Active source support search failed', [
					'source_id' => (int) ($source->id ?? 0),
					'source_name' => (string) ($source->name ?? ''),
					'error' => $e->getMessage(),
				]);
			}
		}

		usort($candidates, static function (array $left, array $right): int {
			return (int) ($right['_support_score'] ?? 0) <=> (int) ($left['_support_score'] ?? 0);
		});

		return array_slice($candidates, 0, 8);
	}

	private static function search_supporting_sources_from_broad_pool(object $item, array $primary, array $context_memory = [], ?float $deadline = null): array {
		$queries = self::build_search_queries($item, $primary, $context_memory);
		$referenceText = trim(implode(' ', array_filter([
			(string) ($item->original_title ?? ''),
			(string) ($item->original_excerpt ?? ''),
			(string) ($primary['title'] ?? ''),
			(string) ($primary['excerpt'] ?? ''),
			(string) ($primary['content'] ?? ''),
			implode(' ', $queries),
		])));
		$referenceTokens = self::search_tokens($referenceText);
		if ($referenceTokens === []) {
			return [];
		}
		$candidates = [];
		foreach (EPV2_Sources::all(true) as $source) {
			if (self::timeout_exceeded($deadline)) {
				break;
			}
			$type = (string) ($source->type ?? '');
			if (! in_array($type, ['rss', 'atom', 'scrape', 'telegram'], true)) {
				continue;
			}
			try {
				foreach (EPV2_Collector::collect_source($source) as $candidate) {
					if (self::timeout_exceeded($deadline)) {
						break 2;
					}
					$title = trim((string) ($candidate['title'] ?? ''));
					$url = trim((string) ($candidate['url'] ?? ''));
					if ($title === '' || $url === '') {
						continue;
					}
					$score = self::support_candidate_score($candidate, $referenceTokens, $queries, '');
					if ($score < 1) {
						continue;
					}
					$candidate['_support_score'] = $score;
					$candidates[] = $candidate;
					if (count($candidates) >= 10) {
						break 2;
					}
				}
			} catch (Throwable $e) {
				continue;
			}
		}
		usort($candidates, static function (array $left, array $right): int {
			return (int) ($right['_support_score'] ?? 0) <=> (int) ($left['_support_score'] ?? 0);
		});
		return array_slice($candidates, 0, 10);
	}

	private static function search_supporting_sources_bing(string $query, ?float $deadline = null): array {
		$query = trim($query);
		if ($query === '') {
			return [];
		}
		$results = [];
		$rssUrl = 'https://www.bing.com/news/search?q=' . rawurlencode($query) . '&format=rss';
		foreach (EPV2_Feed_Reader::fetch($rssUrl, false) as $candidate) {
			if (self::timeout_exceeded($deadline)) {
				break;
			}
			$url = self::decode_bing_news_url((string) ($candidate['url'] ?? ''));
			$title = trim((string) ($candidate['title'] ?? ''));
			if ($url === '' || $title === '') {
				continue;
			}
			$candidate['url'] = $url;
			$results[] = $candidate;
			if (count($results) >= 6) {
				break;
			}
		}
		return $results;
	}

	private static function search_supporting_sources_bing_web(string $query, ?float $deadline = null): array {
		$query = trim($query);
		if ($query === '') {
			return [];
		}
		$results = [];
		$rssUrl = 'https://www.bing.com/search?q=' . rawurlencode($query) . '&format=rss';
		foreach (EPV2_Feed_Reader::fetch($rssUrl, false) as $candidate) {
			if (self::timeout_exceeded($deadline)) {
				break;
			}
			$url = esc_url_raw((string) ($candidate['url'] ?? ''));
			$title = trim((string) ($candidate['title'] ?? ''));
			if ($url === '' || $title === '') {
				continue;
			}
			$candidate['url'] = $url;
			$results[] = $candidate;
			if (count($results) >= 6) {
				break;
			}
		}
		return $results;
	}

	private static function timeout_exceeded(?float $deadline): bool {
		return $deadline !== null && microtime(true) >= $deadline;
	}

	private static function decode_bing_news_url(string $url): string {
		$url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if ($url === '') {
			return '';
		}
		$host = self::host($url);
		if ($host !== 'www.bing.com' && $host !== 'bing.com') {
			return esc_url_raw($url);
		}
		$query = wp_parse_url($url, PHP_URL_QUERY);
		if (! is_string($query) || $query === '') {
			return esc_url_raw($url);
		}
		parse_str($query, $params);
		$encoded = (string) ($params['url'] ?? '');
		if ($encoded === '') {
			return esc_url_raw($url);
		}
		return esc_url_raw(urldecode($encoded));
	}

	private static function build_search_queries(object $item, array $primary, array $context_memory = []): array {
		$title = self::normalized_story_title((string) ($item->original_title ?? ''));
		$category = self::effective_category($item, $primary);
		$primaryTitle = self::normalized_story_title((string) ($primary['title'] ?? ''));
		$primaryExcerpt = self::strip_source_noise((string) ($primary['excerpt'] ?? ''));
		$primaryContent = self::strip_source_noise((string) ($primary['content'] ?? ''));
		$storyContext = self::extract_story_context_from_primary($item, $primary, $context_memory);
		$coreTitle = trim($primaryTitle !== '' ? $primaryTitle : $title);
		if ($coreTitle === '') {
			$coreTitle = $title !== '' ? $title : $primaryTitle;
		}
		$queries = [
			self::normalize_query($coreTitle, 6),
			self::normalize_query($coreTitle, 12),
			self::normalize_query((string) ($item->original_title ?? ''), 12),
			self::normalize_query((string) ($primary['title'] ?? ''), 12),
			self::normalize_query($title . ' ' . $category, 8),
			self::normalize_query($primaryTitle . ' ' . $category, 8),
		];
		$entityQuery = implode(' ', array_slice(self::story_entity_tokens($item, $primary, $category), 0, 4));
		if ($entityQuery !== '') {
			$queries[] = self::normalize_query($entityQuery, 6);
			$queries[] = self::normalize_query($entityQuery . ' photo', 7);
			$queries[] = self::normalize_query($entityQuery . ' bild', 7);
		}
		foreach (self::event_context_queries($item, $primary, $context_memory) as $query) {
			$queries[] = $query;
		}
		foreach ((array) ($context_memory['search_terms'] ?? []) as $term) {
			$queries[] = self::normalize_query((string) $term, 8);
		}
		foreach ([
			(string) ($context_memory['event_title'] ?? ''),
			implode(' ', (array) ($context_memory['participants'] ?? [])),
			trim(implode(' ', array_filter([
				(string) ($context_memory['event_title'] ?? ''),
				(string) ($context_memory['venue'] ?? ''),
				(string) ($context_memory['stage'] ?? ''),
			]))),
		] as $term) {
			$queries[] = self::normalize_query($term, 8);
		}
		foreach (self::category_queries($category, $coreTitle, $primaryTitle, $primaryExcerpt) as $query) {
			$queries[] = $query;
		}
		foreach (self::story_focus_tokens($item, $primary, $category) as $focusToken) {
			$queries[] = self::normalize_query($focusToken . ' ' . $coreTitle, 7);
		}

		$keywordPool = trim($primaryTitle . ' ' . $primaryExcerpt . ' ' . self::extract_keyword_snippet($primaryContent));
		$queries[] = self::normalize_query($keywordPool, 8);
		$queries[] = self::normalize_query($primaryTitle . ' ' . $primaryExcerpt, 12);
		$queries[] = self::normalize_query((string) ($item->original_title ?? '') . ' ' . (string) ($item->original_excerpt ?? ''), 12);
		$queries[] = self::normalize_query($title . ' ' . self::extract_keyword_snippet($primaryExcerpt), 8);
		$queries[] = self::normalize_query($coreTitle . ' photo', 7);
		$queries[] = self::normalize_query($coreTitle . ' bild', 7);

		$namedEntities = self::extract_named_entities($primaryContent . ' ' . $primaryExcerpt);
		if ($namedEntities !== '') {
			$queries[] = self::normalize_query($namedEntities . ' ' . $coreTitle, 8);
		}
		foreach ((array) ($storyContext['search_terms'] ?? []) as $term) {
			$queries[] = self::normalize_query((string) $term, 8);
			$queries[] = self::normalize_query((string) $term . ' foto', 9);
			$queries[] = self::normalize_query((string) $term . ' bild', 9);
		}
		$themeQuery = implode(' ', array_slice((array) ($storyContext['theme_tokens'] ?? []), 0, 5));
		if ($themeQuery !== '') {
			$queries[] = self::normalize_query($themeQuery, 8);
		}
		$entityTokens = array_values(array_filter(array_map('strval', (array) ($storyContext['entities'] ?? []))));
		$entityQuery = implode(' ', array_slice($entityTokens, 0, 3));
		if ($entityQuery !== '') {
			$queries[] = self::normalize_query($entityQuery . ' ' . $coreTitle, 8);
			$queries[] = self::normalize_query($entityQuery . ' photo', 7);
			$queries[] = self::normalize_query($entityQuery . ' bild', 7);
		}
		$locationTokens = array_values(array_filter(array_map('strval', (array) ($storyContext['locations'] ?? []))));
		$locationQuery = implode(' ', array_slice($locationTokens, 0, 2));
		if ($locationQuery !== '') {
			$queries[] = self::normalize_query($locationQuery . ' ' . $coreTitle, 8);
			$queries[] = self::normalize_query($locationQuery . ' ' . $entityQuery, 8);
		}
		$eventTitle = self::normalize_query((string) ($context_memory['event_title'] ?? ''), 10);
		if ($eventTitle !== '') {
			$queries[] = $eventTitle;
			$queries[] = self::normalize_query($eventTitle . ' ' . $locationQuery, 10);
			$queries[] = self::normalize_query($eventTitle . ' ' . $entityQuery, 10);
		}

		$queries = array_values(array_unique(array_filter($queries, static function (string $query): bool {
			$trimmed = trim($query);
			if ($trimmed === '') {
				return false;
			}
			if (preg_match('/\s/u', $trimmed) === 1) {
				return true;
			}
			return preg_match('/^(afd|fulda|bischof|katholiken|polizei|demo|protest|kirche)$/iu', $trimmed) === 1;
		})));
		return array_slice($queries, 0, 12);
	}

	private static function context_phrase_queries(object $item, array $primary, array $context_memory = []): array {
		$storyContext = self::extract_story_context_from_primary($item, $primary, $context_memory);
		$phrases = [];
		$phrases[] = self::normalize_query((string) ($context_memory['event_title'] ?? ''), 10);
		$phrases[] = self::normalize_query((string) ($item->original_title ?? ''), 10);
		$phrases[] = self::normalize_query((string) ($primary['title'] ?? ''), 10);
		$phrases[] = self::normalize_query(trim(implode(' ', array_slice((array) ($context_memory['participants'] ?? []), 0, 3))), 8);
		$phrases[] = self::normalize_query(trim(implode(' ', array_slice((array) ($storyContext['entities'] ?? []), 0, 3))), 8);
		$phrases[] = self::normalize_query(trim(implode(' ', array_slice((array) ($storyContext['locations'] ?? []), 0, 2))), 8);
		foreach ((array) ($context_memory['search_terms'] ?? []) as $term) {
			$phrases[] = self::normalize_query((string) $term, 8);
		}
		foreach ((array) ($storyContext['search_terms'] ?? []) as $term) {
			$phrases[] = self::normalize_query((string) $term, 8);
		}
		$phrases = array_values(array_unique(array_filter($phrases, static function (string $phrase): bool {
			return trim($phrase) !== '' && preg_match('/\s/u', trim($phrase)) === 1;
		})));
		return array_slice($phrases, 0, 8);
	}

	private static function context_phrase_score(array $candidate, array $phrases): int {
		if ($phrases === []) {
			return 0;
		}
		$haystack = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($candidate['title'] ?? ''),
			(string) ($candidate['excerpt'] ?? ''),
			(string) wp_parse_url((string) ($candidate['url'] ?? ''), PHP_URL_PATH),
		]))));
		if ($haystack === '') {
			return 0;
		}
		$score = 0;
		foreach ($phrases as $phrase) {
			$phrase = mb_strtolower(trim((string) $phrase));
			if ($phrase === '') {
				continue;
			}
			if (str_contains($haystack, $phrase)) {
				$score += 2;
				continue;
			}
			$tokens = array_values(array_filter(explode(' ', $phrase), static fn(string $token): bool => mb_strlen($token) >= 4));
			if ($tokens === []) {
				continue;
			}
			$matches = 0;
			foreach ($tokens as $token) {
				if (str_contains($haystack, $token)) {
					$matches++;
				}
			}
			if ($matches >= min(2, count($tokens))) {
				$score++;
			}
		}
		return $score;
	}

	private static function effective_category(object $item, array $primary): string {
		$seedCategory = (string) (($item->category_proposed ?? '') ?: ($item->category_final ?? '') ?: '');
		$detected = EPV2_Categorizer::detect(
			(string) ($primary['title'] ?? $item->original_title ?? ''),
			(string) ($primary['content'] ?? $item->original_content ?? ''),
			$seedCategory
		);
		$refined = EPV2_Categorizer::refine_with_event_context(
			$detected !== '' ? $detected : $seedCategory,
			['primary' => $primary],
			(string) ($primary['title'] ?? $item->original_title ?? ''),
			(string) ($primary['content'] ?? $item->original_content ?? '')
		);
		if ($refined !== '') {
			return $refined;
		}
		if ($detected !== '') {
			return $detected;
		}
		return $seedCategory;
	}

	private static function support_entry_matches_story(array $entry, object $item, array $primary): bool {
		$category = self::effective_category($item, $primary);
		$focusTokens = self::story_focus_tokens($item, $primary, $category);
		$signatureTokens = self::story_signature_tokens($item, $primary, $category);
		$anchorTokens = self::story_anchor_tokens($item, $primary, $category);
		if (
			in_array($category, ['politik', 'deutschland', 'ukraine'], true)
			&& count($anchorTokens) >= 2
			&& ! self::entry_has_focus_overlap($entry, $anchorTokens, 1, 1)
		) {
			return false;
		}
		if (
			in_array($category, ['politik', 'deutschland', 'ukraine', 'kultur', 'sport', 'community', 'leben-in-deutschland'], true)
			&& $signatureTokens !== []
			&& ! self::entry_has_focus_overlap($entry, $signatureTokens, 1, 1)
		) {
			return false;
		}
		if (in_array($category, ['politik', 'deutschland', 'ukraine'], true) && $focusTokens !== []) {
			return self::entry_has_focus_overlap($entry, $focusTokens, 1, 2);
		}
		if (self::entry_matches_category_context($entry, $item, $primary)) {
			if ($focusTokens === [] || self::entry_has_focus_overlap($entry, $focusTokens, 1, 2)) {
				return true;
			}
		}
		$entityTokens = self::story_entity_tokens($item, $primary, $category);
		if ($entityTokens === []) {
			return true;
		}
		if (self::looks_like_section_landing($entry)) {
			return false;
		}
		$titleExcerpt = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($entry['title'] ?? ''),
			(string) ($entry['excerpt'] ?? ''),
			(string) wp_parse_url((string) ($entry['url'] ?? ''), PHP_URL_PATH),
		]))));
		if ($titleExcerpt === '') {
			return false;
		}
		if (preg_match('/^(sport|news|politik|wetter|live|startseite)$/u', trim((string) ($entry['title'] ?? ''))) === 1) {
			return false;
		}
		$matches = 0;
		foreach ($entityTokens as $token) {
			if ($token !== '' && str_contains($titleExcerpt, $token)) {
				$matches++;
			}
		}
		if ($focusTokens !== [] && self::entry_has_focus_overlap($entry, $focusTokens, 1, 2)) {
			return true;
		}
		if ($matches >= min(2, count($entityTokens))) {
			return true;
		}

		$content = mb_strtolower(trim((string) ($entry['content'] ?? '')));
		if ($content === '') {
			return false;
		}
		$contentMatches = 0;
		foreach ($entityTokens as $token) {
			if ($token !== '' && str_contains($content, $token)) {
				$contentMatches++;
			}
		}
		if ($focusTokens !== [] && self::entry_has_focus_overlap($entry, $focusTokens, 1, 2)) {
			return true;
		}
		return $matches >= 1 && $contentMatches >= min(2, count($entityTokens));
	}

	private static function story_signature_tokens(object $item, array $primary, string $category = ''): array {
		$coreTitle = self::normalized_story_title((string) ($primary['title'] ?? $item->original_title ?? ''));
		$excerpt = self::strip_source_noise((string) ($primary['excerpt'] ?? $item->original_excerpt ?? ''));
		$content = self::strip_source_noise((string) ($primary['content'] ?? $item->original_content ?? ''));
		$entityTokens = self::search_tokens(self::extract_named_entities($coreTitle . ' ' . $excerpt . ' ' . $content));
		$tokens = self::search_tokens(implode(' ', array_filter([
			$coreTitle,
			$excerpt,
			self::extract_keyword_snippet($content),
		])));
		$generic = [
			'merz', 'friedrich', 'bundeskanzler', 'kanzler', 'regierung', 'deutschland', 'germany',
			'politik', 'europa', 'europe', 'bundestag', 'berlin', 'münchen', 'munich', 'show',
			'sport', 'match', 'spiel', 'community', 'event', 'kultur', 'the', 'voice', 'bayern',
			'deutsche', 'deutscher', 'deutschen', 'international', 'internationalen', 'heute',
			'staffel', 'sendung', 'moderator', 'jury', 'quizshow',
		];
		$filtered = [];
		foreach ($tokens as $token) {
			if (in_array($token, $generic, true) || in_array($token, $entityTokens, true)) {
				continue;
			}
			$filtered[] = $token;
		}
		return array_slice(array_values(array_unique($filtered)), 0, in_array($category, ['politik', 'deutschland'], true) ? 5 : 4);
	}

	private static function story_anchor_tokens(object $item, array $primary, string $category = ''): array {
		$coreTitle = self::normalized_story_title((string) ($primary['title'] ?? $item->original_title ?? ''));
		$tokens = self::search_tokens($coreTitle);
		$generic = [
			'steht', 'langen', 'lange', 'weg', 'ordnung', 'mehr', 'weniger', 'erste', 'ersten',
			'neue', 'neuen', 'deutschland', 'politik', 'regierung', 'bundesregierung', 'bundestag',
			'europa', 'europaeische', 'europäische', 'kanzler', 'bundeskanzler',
		];
		$tokens = array_values(array_filter($tokens, static fn(string $token): bool => ! in_array($token, $generic, true)));
		return array_slice($tokens, 0, in_array($category, ['politik', 'deutschland'], true) ? 4 : 3);
	}

	private static function support_entry_can_be_brief(array $entry, object $item, array $primary): bool {
		$title = trim((string) ($entry['title'] ?? ''));
		$excerpt = trim((string) ($entry['excerpt'] ?? ''));
		if ($title === '' && $excerpt === '') {
			return false;
		}
		if (self::entry_matches_category_context($entry, $item, $primary)) {
			return true;
		}
		return mb_strlen($title) >= 55 || mb_strlen($excerpt) >= 90;
	}

	private static function entry_matches_category_context(array $entry, object $item, array $primary): bool {
		$category = self::effective_category($item, $primary);
		$text = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($entry['title'] ?? ''),
			(string) ($entry['excerpt'] ?? ''),
			(string) ($entry['content'] ?? ''),
			(string) wp_parse_url((string) ($entry['url'] ?? ''), PHP_URL_PATH),
		]))));
		if ($text === '') {
			return false;
		}
		$host = self::host((string) ($entry['url'] ?? ''));
		$official_like = preg_match('/bundesregierung|bundestag|bundesrat|service\.bund|bayern\.de|muenchen\.de|tagesschau|dw\.com|zdf|br\.de/u', $host) === 1;
		return match ($category) {
			'politik', 'deutschland' => preg_match('/\b(merz|friedrich merz|bundeskanzler|kanzler|afd|regierungserklärung|regierungserklaerung|eu-selbstvertrauen|eu-selbstbewusstsein|europa|bundestag)\b/u', $text) === 1
				&& ($official_like || preg_match('/\b(rede|speech|statement|regierung|koalition|opposition)\b/u', $text) === 1),
			'sport' => preg_match('/\b(match|spiel|anpfiff|rückspiel|rueckspiel|halbfinale|viertelfinale|champions league|bundesliga|paralymp)\b/u', $text) === 1,
			'kultur' => preg_match('/\b(the voice|moderator|show|sendung|jury|casting|unterhaltung|quizshow)\b/u', $text) === 1,
			'community', 'leben-in-deutschland' => preg_match('/\b(beratung|sprechstunde|jobmesse|karrieremesse|bildungsmesse|anmeldung|community|verein|hilfsangebot|wohngeld|jobcenter|aufenthalt)\b/u', $text) === 1,
			default => false,
		};
	}

	private static function candidate_entry_is_story_relevant(array $entry, object $item, array $primary): bool {
		if (self::same_story_syndicated_candidate($entry, $item, $primary)) {
			return true;
		}

		if (! self::support_entry_matches_story($entry, $item, $primary)) {
			return false;
		}

		$category = self::effective_category($item, $primary);
		$storyContext = self::extract_story_context_from_primary($item, $primary);
		$storyTitle = trim((string) ($primary['title'] ?? $item->original_title ?? ''));
		$storyExcerpt = trim((string) ($primary['excerpt'] ?? $item->original_excerpt ?? ''));
		$storyContent = trim((string) ($primary['content'] ?? $item->original_content ?? ''));
		$entryTitle = trim((string) ($entry['title'] ?? ''));
		$entryExcerpt = trim((string) ($entry['excerpt'] ?? ''));
		$entryContent = trim((string) ($entry['content'] ?? ''));
		$categories = array_values(array_unique(array_filter([
			$category,
			(string) ($item->category_final ?? ''),
			(string) ($item->category_proposed ?? ''),
		])));
		$hasStoryContext = self::source_entry_matches_story_context($storyTitle, $storyExcerpt, $storyContent, $entryTitle, $entryExcerpt, $entryContent, $categories, $storyContext);
		$host = self::host((string) ($entry['url'] ?? ''));
		$officialLike = preg_match('/bundesregierung|bundestag|bundesrat|service\.bund|bayern\.de|muenchen\.de|tagesschau|dw\.com|zdf|br\.de/u', $host) === 1;
		$strictCategory = $category !== '';
		if (($strictCategory || $officialLike) && ! $hasStoryContext) {
			return false;
		}
		return true;
	}

	private static function same_story_syndicated_candidate(array $entry, object $item, array $primary): bool {
		$url = esc_url_raw((string) ($entry['url'] ?? ''));
		$title = trim(wp_strip_all_tags((string) ($entry['title'] ?? '')));
		$excerpt = trim(wp_strip_all_tags((string) ($entry['excerpt'] ?? '')));
		$image = esc_url_raw((string) ($entry['image'] ?? ''));
		if ($url === '' || $title === '' || $image === '') {
			return false;
		}

		$host = self::host($url);
		$is_syndication_host = preg_match('/(^|\.)msn\.com$|(^|\.)news\.yahoo\.com$|(^|\.)bing\.com$/u', $host) === 1;
		if (! $is_syndication_host) {
			return false;
		}

		$storyTitle = trim(wp_strip_all_tags((string) ($primary['title'] ?? $item->original_title ?? '')));
		$storyExcerpt = trim(wp_strip_all_tags((string) ($primary['excerpt'] ?? $item->original_excerpt ?? '')));
		if ($storyTitle === '') {
			return false;
		}

		$titleOverlap = self::token_overlap_count($storyTitle, $title);
		$excerptOverlap = self::token_overlap_count($storyExcerpt, $excerpt);
		if ($titleOverlap >= 4) {
			return true;
		}
		return $titleOverlap >= 3 && $excerptOverlap >= 2;
	}

	private static function token_overlap_count(string $left, string $right): int {
		$leftTokens = self::meaningful_context_tokens($left);
		$rightTokens = self::meaningful_context_tokens($right);
		if ($leftTokens === [] || $rightTokens === []) {
			return 0;
		}
		return count(array_intersect($leftTokens, $rightTokens));
	}

	private static function filter_supporting_entries(array $supporting, object $item, array $primary): array {
		$filtered = [];
		foreach ($supporting as $entry) {
			if (! is_array($entry) || ! self::candidate_entry_is_story_relevant($entry, $item, $primary)) {
				continue;
			}
			$filtered[] = $entry;
		}
		return array_values($filtered);
	}

	private static function salvage_supporting_entries(array $candidates, object $item, array $primary, int $target_supporting, ?float $deadline = null): array {
		$salvaged = [];
		$seen = [];
		foreach ($candidates as $candidate) {
			if (self::timeout_exceeded($deadline) || count($salvaged) >= $target_supporting) {
				break;
			}
			if (! is_array($candidate)) {
				continue;
			}
			$url = trim((string) ($candidate['url'] ?? ''));
			$title = trim((string) ($candidate['title'] ?? ''));
			if ($url === '' || $title === '') {
				continue;
			}
			$host = self::host($url);
			if ($host !== '' && isset($seen[$host])) {
				continue;
			}
			if (self::looks_like_section_landing($candidate)) {
				continue;
			}
			$doc = self::fetch_document_safe($url);
			$entry = $doc !== []
				? self::source_entry((string) ($doc['url'] ?? $url), (string) ($doc['title'] ?? $title), (string) ($doc['excerpt'] ?? ''), (string) ($doc['content'] ?? ''), (string) ($doc['image'] ?? ($candidate['image'] ?? '')))
				: self::source_entry($url, $title, (string) ($candidate['excerpt'] ?? ''), '', (string) ($candidate['image'] ?? ''));
			if (! self::candidate_entry_is_story_relevant_relaxed($entry, $item, $primary)) {
				continue;
			}
			// STRICT content требование 2026-05-11 (operator-feedback): salvage
			// path раньше возвращал URL-only stubs (title+url, content=0,
			// excerpt=0) когда fetch fail'ил — anti-bot, paywall, JS-rendering.
			// Эти stubs кормили AI как «supporting sources» — AI видел URL
			// list, ассумировал contents, fabricated attributions «wie WSJ
			// berichtet» / «Reuters meldet». Strict mirror основного loop:
			// content ≥ 280 chars OR excerpt ≥ 200 chars OR visual_support.
			$has_visual_support = ! empty($entry['image']) && EPV2_Media::is_relevant_media(
				(string) $entry['image'],
				(string) ($primary['title'] ?? $item->original_title ?? ''),
				(string) ($primary['excerpt'] ?? $item->original_excerpt ?? ''),
				array_values(array_filter(array_map('trim', explode(',', (string) ($item->category_proposed ?? ''))))),
				['primary' => $primary]
			);
			$ent_content_len = mb_strlen((string) ($entry['content'] ?? ''));
			$ent_excerpt_len = mb_strlen((string) ($entry['excerpt'] ?? ''));
			if ($ent_content_len < 280 && $ent_excerpt_len < 200 && ! $has_visual_support) {
				if (class_exists('EPV2_Logger')) {
					EPV2_Logger::info('source_enricher', 'salvage_dropped_empty', [
						'url' => $url,
						'content_len' => $ent_content_len,
						'excerpt_len' => $ent_excerpt_len,
					]);
				}
				continue;
			}
			$seen[$host] = true;
			$salvaged[] = $entry;
		}
		return $salvaged;
	}

	private static function candidate_entry_is_story_relevant_relaxed(array $entry, object $item, array $primary): bool {
		$title = trim((string) ($entry['title'] ?? ''));
		$excerpt = trim((string) ($entry['excerpt'] ?? ''));
		$content = trim((string) ($entry['content'] ?? ''));
		if ($title === '' && $excerpt === '' && $content === '') {
			return false;
		}
		if (self::looks_like_section_landing($entry)) {
			return false;
		}
		if (self::candidate_entry_is_story_relevant($entry, $item, $primary)) {
			return true;
		}
		$storyContext = self::extract_story_context_from_primary($item, $primary);
		$storyText = trim(implode(' ', array_filter([
			(string) ($primary['title'] ?? $item->original_title ?? ''),
			(string) ($primary['excerpt'] ?? $item->original_excerpt ?? ''),
			(string) ($storyContext['body_snippet'] ?? ''),
			implode(' ', (array) ($storyContext['entities'] ?? [])),
			implode(' ', (array) ($storyContext['search_terms'] ?? [])),
		])));
		$storyTokens = self::meaningful_context_tokens($storyText);
		$entryTokens = self::meaningful_context_tokens($title . ' ' . $excerpt . ' ' . $content);
		if ($storyTokens === [] || $entryTokens === []) {
			return false;
		}
		if (count(array_intersect($storyTokens, $entryTokens)) >= 1) {
			return true;
		}
		$joined = mb_strtolower(trim($title . ' ' . $excerpt . ' ' . $content));
		foreach ((array) ($storyContext['search_terms'] ?? []) as $term) {
			$term = mb_strtolower(trim((string) $term));
			if ($term !== '' && mb_strlen($term) >= 10 && str_contains($joined, $term)) {
				return true;
			}
		}
		return false;
	}

	private static function source_entry_matches_story_context(string $storyTitle, string $storyExcerpt, string $storyContent, string $entryTitle, string $entryExcerpt, string $entryContent, array $categories, array $storyContext = []): bool {
		$storyContext = self::normalize_story_context($storyContext);
		$storyTokens = array_values(array_unique(array_merge(
			self::meaningful_context_tokens($storyTitle . ' ' . $storyExcerpt . ' ' . $storyContent),
			self::meaningful_context_tokens(implode(' ', array_merge(
				(array) ($storyContext['search_terms'] ?? []),
				(array) ($storyContext['entities'] ?? []),
				(array) ($storyContext['theme_tokens'] ?? []),
				(array) ($storyContext['body_keywords'] ?? []),
				[(string) ($storyContext['body_snippet'] ?? '')]
			)))
		)));
		$entryTokens = self::meaningful_context_tokens($entryTitle . ' ' . $entryExcerpt . ' ' . $entryContent);
		if ($storyTokens === [] || $entryTokens === []) {
			return false;
		}
		if (count(array_intersect($storyTokens, $entryTokens)) >= 2) {
			return true;
		}
		$joined = mb_strtolower(trim($entryTitle . ' ' . $entryExcerpt . ' ' . $entryContent));
		if ($joined === '') {
			return false;
		}
		$phraseMatches = 0;
		foreach ((array) ($storyContext['search_terms'] ?? []) as $term) {
			$term = mb_strtolower(trim((string) $term));
			if ($term !== '' && mb_strlen($term) >= 8 && str_contains($joined, $term)) {
				$phraseMatches++;
			}
		}
		if ($phraseMatches >= 1) {
			return true;
		}
		$categoryFallbackAllowed = ['sport', 'kultur', 'community', 'leben-in-deutschland', 'wirtschaft'];
		foreach ($categories as $category) {
			$category = mb_strtolower((string) $category);
			if ($category !== '' && in_array($category, $categoryFallbackAllowed, true) && str_contains($joined, $category)) {
				return true;
			}
		}
		return false;
	}

	private static function meaningful_context_tokens(string $text): array {
		$text = mb_strtolower(wp_strip_all_tags($text));
		$text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: $text;
		$stop = ['der','die','das','und','mit','von','fuer','für','des','dem','den','eine','einer','einem','ein','auch','sich','ist','sind','wird','werden','auf','im','in','an','am','zu','zum','zur','for','the','and','with','von','on','heute','live'];
		$tokens = [];
		foreach (preg_split('/\s+/u', trim($text)) ?: [] as $token) {
			$token = trim((string) $token);
			if (mb_strlen($token) < 5 || in_array($token, $stop, true)) {
				continue;
			}
			$tokens[$token] = true;
		}
		return array_keys($tokens);
	}

	private static function extract_story_context(object $item, array $dossier, array $context_memory = []): array {
		$entries = [];
		if (is_array($dossier['primary'] ?? null)) {
			$entries[] = (array) $dossier['primary'];
		}
		foreach ((array) ($dossier['supporting'] ?? []) as $entry) {
			if (is_array($entry)) {
				$entries[] = $entry;
			}
		}
		return self::extract_story_context_from_entries($item, $entries, $context_memory);
	}

	private static function extract_story_context_from_primary(object $item, array $primary, array $context_memory = []): array {
		return self::extract_story_context_from_entries($item, [$primary], $context_memory);
	}

	private static function extract_story_context_from_entries(object $item, array $entries, array $context_memory = []): array {
		$context_memory = self::normalize_context_memory($context_memory);
		$titleParts = [];
		$textParts = [];
		foreach ($entries as $entry) {
			if (! is_array($entry)) {
				continue;
			}
			$titleParts[] = (string) ($entry['title'] ?? '');
			$titleParts[] = (string) ($entry['excerpt'] ?? '');
			$textParts[] = (string) ($entry['content'] ?? '');
		}
		$titleParts[] = (string) ($item->original_title ?? '');
		$titleParts[] = (string) ($item->original_excerpt ?? '');
		$textParts[] = (string) ($item->original_content ?? '');

		$titleText = trim(implode(' ', array_filter($titleParts)));
		$bodyText = trim(implode(' ', array_filter($textParts)));
		$bodySnippet = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($bodyText)) ?: '');
		$bodySnippet = $bodySnippet !== '' ? sanitize_text_field((string) mb_substr($bodySnippet, 0, 320)) : '';

		$entities = self::extract_named_entity_terms($titleText . ' ' . $bodyText);
		$bodyKeywords = self::extract_story_keyword_terms($bodyText);
		$themeTokens = array_slice(array_values(array_unique(array_filter(array_merge(
			self::search_tokens($titleText),
			self::search_tokens($bodySnippet),
			$bodyKeywords
		)))), 0, 8);

		$searchTerms = array_merge(
			(array) ($context_memory['search_terms'] ?? []),
			[$titleText],
			$entities,
			$bodyKeywords
		);

		$context = [
			'body_snippet' => $bodySnippet,
			'entities' => array_slice($entities, 0, 6),
			'body_keywords' => array_slice($bodyKeywords, 0, 6),
			'theme_tokens' => $themeTokens,
			'search_terms' => array_slice(array_values(array_unique(array_filter(array_map(
				static fn($term): string => self::normalize_query((string) $term, 10),
				$searchTerms
			)))), 0, 12),
		];

		return self::normalize_story_context($context);
	}

	private static function normalize_story_context(array $context): array {
		if ($context === []) {
			return [];
		}
		$normalized = [
			'body_snippet' => sanitize_text_field((string) ($context['body_snippet'] ?? '')),
			'entities' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($context['entities'] ?? [])))), 0, 6),
			'body_keywords' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($context['body_keywords'] ?? [])))), 0, 6),
			'theme_tokens' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($context['theme_tokens'] ?? [])))), 0, 8),
			'search_terms' => array_slice(array_values(array_filter(array_map(static fn($term): string => self::normalize_query((string) $term, 10), (array) ($context['search_terms'] ?? [])))), 0, 12),
		];
		return array_filter($normalized, static function ($value): bool {
			if (is_array($value)) {
				return $value !== [];
			}
			return trim((string) $value) !== '';
		});
	}

	private static function extract_story_keyword_terms(string $text): array {
		$snippet = self::extract_keyword_snippet($text);
		if ($snippet === '') {
			return [];
		}
		$terms = preg_split('/\s+/u', trim($snippet)) ?: [];
		$terms = array_values(array_filter(array_map('sanitize_text_field', $terms), static fn(string $term): bool => mb_strlen($term) >= 5));
		return array_slice(array_values(array_unique($terms)), 0, 6);
	}

	private static function extract_named_entity_terms(string $text): array {
		if ($text === '') {
			return [];
		}
		if (preg_match_all('/([A-ZÄÖÜ][a-zäöüß]+(?:\s+[A-ZÄÖÜ][a-zäöüß]+){0,2})/u', $text, $matches) < 1) {
			return [];
		}
		$entities = [];
		foreach ((array) ($matches[1] ?? []) as $entity) {
			$entity = trim((string) $entity);
			if (mb_strlen($entity) < 5) {
				continue;
			}
			$entities[$entity] = true;
			if (count($entities) >= 6) {
				break;
			}
		}
		return array_keys($entities);
	}

	private static function looks_like_section_landing(array $entry): bool {
		$title = mb_strtolower(trim((string) ($entry['title'] ?? '')));
		$urlPath = mb_strtolower(trim((string) wp_parse_url((string) ($entry['url'] ?? ''), PHP_URL_PATH)));
		if (preg_match('/^(sport|news|politik|wetter|live|startseite)$/u', $title) === 1) {
			return true;
		}
		if ($urlPath !== '' && preg_match('#/(tags?|topics?|category|categories|news|section|sections|sport|politik|wetter)(/|$)#u', $urlPath) === 1) {
			return true;
		}
		return false;
	}

	private static function category_queries(string $category, string $coreTitle, string $primaryTitle, string $primaryExcerpt): array {
		$base = trim($coreTitle !== '' ? $coreTitle : $primaryTitle);
		return match ($category) {
			'sport' => [
				self::normalize_query($base . ' match report', 7),
				self::normalize_query($base . ' trainer quote', 7),
				self::normalize_query($base . ' press conference photo', 7),
			],
			'kultur' => [
				self::normalize_query($base . ' premiere festival photo', 7),
				self::normalize_query($base . ' exhibition theater image', 7),
				self::normalize_query($primaryTitle . ' cultural event', 7),
			],
			'community' => [
				self::normalize_query($base . ' ukrainian community munich germany', 7),
				self::normalize_query($base . ' initiative meeting event munich', 7),
				self::normalize_query($primaryExcerpt . ' diaspora volunteer initiative', 7),
				self::normalize_query($base . ' ukraine germany association diaspora munich', 8),
				self::normalize_query($base . ' community event ukrainians in germany', 8),
				self::normalize_query($primaryTitle . ' verein initiative treff', 8),
				self::normalize_query($base . ' workshop beratung anmeldung ukrainische gemeinde bayern', 8),
				self::normalize_query($base . ' munich ukraine event diaspora telegram', 8),
				self::normalize_query($primaryTitle . ' voluntaries meetup community center munich', 8),
			],
			'world' => [
				self::normalize_query($primaryTitle . ' reuters ap bbc', 8),
				self::normalize_query($base . ' world news context background', 8),
				self::normalize_query($primaryExcerpt . ' usa asia middle east global', 8),
				self::normalize_query($base . ' international crisis reaction analysis', 8),
			],
			'politik' => [
				self::normalize_query($base . ' regierungserklärung europa afd analyse', 8),
				self::normalize_query($primaryTitle . ' bundeskanzler bundestag berlin', 8),
				self::normalize_query($base . ' opposition reaktion deutschland europa', 8),
				self::normalize_query($primaryExcerpt . ' politik berlin europa', 8),
			],
			'deutschland' => [
				self::normalize_query($base . ' deutschland berlin regierung analyse', 8),
				self::normalize_query($primaryTitle . ' bundespolitik merz europa', 8),
				self::normalize_query($base . ' innenpolitik deutschland', 8),
			],
			'wirtschaft' => [
				self::normalize_query($base . ' market companies report', 7),
				self::normalize_query($base . ' economic impact analysis', 7),
				self::normalize_query($primaryTitle . ' capital market companies', 7),
			],
			'leben-in-deutschland' => [
				self::normalize_query($base . ' germany migrants update official', 7),
				self::normalize_query($base . ' bamf jobcenter residence change', 7),
				self::normalize_query($primaryTitle . ' ukraine germany migrants', 7),
				self::normalize_query($base . ' arbeitsagentur jobcenter deutschland official', 8),
				self::normalize_query($base . ' aufenthalt jobcenter wohngeld kindergeld deutschland', 8),
				self::normalize_query($primaryExcerpt . ' germany4ukraine make it in germany', 8),
				self::normalize_query($base . ' ukrainians in germany service update residence insurance tax', 8),
				self::normalize_query($primaryTitle . ' documents registration benefits germany ukrainians', 8),
			],
			'ukraine' => [
				self::normalize_query($base . ' Ukraine air defense Europe Politico', 8),
				self::normalize_query($primaryTitle . ' missiles NATO allies Ukraine', 8),
				self::normalize_query($base . ' Patriot air defence Europe support Ukraine', 8),
				self::normalize_query($primaryExcerpt . ' Europe allies missiles Ukraine', 8),
				self::normalize_query($base . ' military aid Europe air defence stocks', 8),
			],
			default => [],
		};
	}

	private static function normalize_query(string $query, int $maxWords): string {
		$query = trim(wp_strip_all_tags($query));
		$query = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $query) ?: $query;
		$query = preg_replace('/\s+/u', ' ', $query) ?: $query;
		$words = array_slice(array_values(array_filter(explode(' ', trim((string) $query)))), 0, $maxWords);
		return trim(implode(' ', $words));
	}

	private static function candidate_source_pool(string $category, string $language, int $excludeSourceId): array {
		$related = [$category];
		if ($category === 'community') {
			$related[] = 'leben-in-deutschland';
		}
		if ($category === 'leben-in-deutschland') {
			$related[] = 'community';
			$related[] = 'deutschland';
		}
		if ($category === 'world') {
			$related[] = 'politik';
			$related[] = 'europa';
		}
		if ($category === 'politik') {
			$related[] = 'deutschland';
			$related[] = 'europa';
			$related[] = 'world';
		}
		if ($category === 'deutschland') {
			$related[] = 'politik';
			$related[] = 'leben-in-deutschland';
		}
		if ($category === 'sport') {
			$related[] = 'world';
		}

		$pool = [];
		foreach (EPV2_Sources::all(true) as $source) {
			if ((int) ($source->id ?? 0) === $excludeSourceId) {
				continue;
			}
			$type = (string) ($source->type ?? '');
			if (! in_array($type, ['rss', 'atom', 'scrape', 'telegram'], true)) {
				continue;
			}
			$sourceCategory = (string) ($source->category_bias ?? '');
			if ($related !== [''] && $sourceCategory !== '' && ! in_array($sourceCategory, $related, true)) {
				continue;
			}
			if ((int) ($source->priority ?? 0) < 7) {
				continue;
			}
			$sourceLanguage = (string) ($source->language ?? '');
			if ($language !== '' && $sourceLanguage !== '' && ! in_array($sourceLanguage, [$language, 'de', 'en', 'uk'], true)) {
				continue;
			}
			$pool[] = $source;
			if (count($pool) >= 4) {
				break;
			}
		}
		return $pool;
	}

	private static function search_tokens(string $text): array {
		$text = mb_strtolower(wp_strip_all_tags($text));
		$text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: $text;
		$tokens = preg_split('/\s+/u', trim($text)) ?: [];
		$stop = [
			'der', 'die', 'das', 'und', 'mit', 'von', 'für', 'den', 'dem', 'des', 'eine', 'einer',
			'dass', 'sich', 'wird', 'werden', 'auch', 'nicht', 'mehr', 'sind', 'ist', 'ein', 'eine',
			'auf', 'bei', 'als', 'zur', 'zum', 'über', 'durch', 'the', 'and', 'with', 'from', 'into',
			'this', 'that', 'für', 'oder', 'aber', 'news', 'update', 'kicker', 'bild', 'tagesschau',
			'merkur', 'focus', 'fokus', 'welt', 'ntv', 'dw', 'msn', 'yahoo', 'transfermarkt', 'sport',
			'show', 'artikel', 'news', 'meldung', 'jahr', 'jahre', 'picture', 'alliance', 'liveübertragung',
			'liveuebertragung', 'christian', 'ohde', 'chromorange', 'montag', 'dienstag', 'mittwoch',
			'donnerstag', 'freitag', 'samstag', 'sonntag', 'januar', 'februar', 'märz', 'maerz',
			'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'dezember',
			'через', 'кілька', 'днів', 'тижнів', 'доведеться', 'обирати', 'між', 'європі', 'європа',
			'україною', 'союзниками', 'сьогодні', 'завтра', 'після', 'перед', 'може', 'можуть', 'буде',
			'будуть', 'також', 'лише', 'несколько', 'недель', 'дней', 'между', 'будет', 'будут', 'может',
			'могут', 'после', 'сегодня',
		];
		$seen = [];
		$filtered = [];
		foreach ($tokens as $token) {
			$token = trim((string) $token);
			if (preg_match('/^\d+$/', $token) === 1 || mb_strlen($token) < 4 || in_array($token, $stop, true) || isset($seen[$token])) {
				continue;
			}
			$seen[$token] = true;
			$filtered[] = $token;
			if (count($filtered) >= 16) {
				break;
			}
		}
		return $filtered;
	}

	private static function story_entity_tokens(object $item, array $primary, string $category = ''): array {
		$title = self::normalized_story_title((string) ($primary['title'] ?? $item->original_title ?? ''));
		$excerpt = self::strip_source_noise((string) ($primary['excerpt'] ?? $item->original_excerpt ?? ''));
		$content = self::strip_source_noise((string) ($primary['content'] ?? $item->original_content ?? ''));
		$context = self::extract_event_context_from_entries($item, [$primary]);
		$text = implode(' ', array_filter([
			self::extract_keyword_snippet($excerpt . ' ' . $content),
			self::strip_source_noise(self::extract_named_entities($content)),
			$excerpt,
			$content,
			implode(' ', (array) ($context['participants'] ?? [])),
			(string) ($context['venue'] ?? ''),
			(string) ($context['stage'] ?? ''),
			implode(' ', (array) ($context['search_terms'] ?? [])),
			$title,
		]));
		$tokens = self::search_tokens($text);
		$generic = [
			'через', 'кілька', 'тижнів', 'днів', 'доведеться', 'обирати', 'між', 'європі', 'європа',
			'україною', 'союзниками', 'europe', 'ukraine', 'allies', 'ally',
		];
		if ($category !== 'sport' && self::story_has_transport_service_signal($item, $primary)) {
			$sportNoise = [
				'champions', 'league', 'allianz', 'arena', 'atalanta', 'bergamo', 'trainer',
				'heidenheim', 'fcbayern', 'bayern', 'spiel', 'spieltag', 'uefa', 'torwart',
			];
			$generic = array_merge($generic, $sportNoise);
		}
		$tokens = array_values(array_filter($tokens, static fn(string $token): bool => ! in_array($token, $generic, true)));
		return array_slice($tokens, 0, 8);
	}

	private static function story_focus_tokens(object $item, array $primary, string $category = ''): array {
		$coreTitle = self::normalized_story_title((string) ($primary['title'] ?? $item->original_title ?? ''));
		$excerpt = self::strip_source_noise((string) ($primary['excerpt'] ?? $item->original_excerpt ?? ''));
		$content = self::strip_source_noise((string) ($primary['content'] ?? $item->original_content ?? ''));
		$context = self::extract_event_context_from_entries($item, [$primary]);
		$text = implode(' ', array_filter([
			self::extract_keyword_snippet($excerpt . ' ' . $content),
			$excerpt,
			$content,
			implode(' ', (array) ($context['participants'] ?? [])),
			(string) ($context['venue'] ?? ''),
			(string) ($context['stage'] ?? ''),
			implode(' ', (array) ($context['search_terms'] ?? [])),
			$coreTitle,
		]));
		$tokens = self::search_tokens($text);
		$generic = [
			'merz', 'friedrich', 'bundeskanzler', 'kanzler', 'deutschland', 'europa', 'europe',
			'politik', 'regierung', 'voice', 'germany', 'community', 'event', 'sport', 'match',
			'show', 'sendung', 'moderator', 'bundestag', 'berlin', 'münchen', 'munich',
			'deutscher', 'deutsche', 'deutschen', 'internationalen', 'internationaler', 'internationales',
			'heute', 'bereits', 'ursprung', 'seinen', 'ihren', 'markiert', 'eröffnet', 'eroeffnet',
		];
		if ($category !== 'sport' && self::story_has_transport_service_signal($item, $primary)) {
			$generic = array_merge($generic, [
				'champions', 'league', 'allianz', 'arena', 'atalanta', 'bergamo', 'trainer',
				'heidenheim', 'fcbayern', 'fußball', 'fussball', 'spiel', 'spieltag', 'uefa',
			]);
		}
		$tokens = array_values(array_filter($tokens, static fn(string $token): bool => ! in_array($token, $generic, true)));
		return array_slice($tokens, 0, $category === 'politik' || $category === 'deutschland' ? 6 : 5);
	}

	private static function story_has_transport_service_signal(object $item, array $primary): bool {
		$text = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($item->original_title ?? ''),
			(string) ($item->original_excerpt ?? ''),
			(string) ($item->original_content ?? ''),
			(string) ($primary['title'] ?? ''),
			(string) ($primary['excerpt'] ?? ''),
			(string) ($primary['content'] ?? ''),
		]))));
		if ($text === '') {
			return false;
		}
		return preg_match('/\b(öpnv|oepnv|warnstreik|streik|verkehr|pendler|mvg|u-bahn|s-bahn|tram|busse|bahnverkehr|nahverkehr)\b/u', $text) === 1;
	}

	private static function entry_has_focus_overlap(array $entry, array $focusTokens, int $titleNeed = 1, int $contentNeed = 2): bool {
		if ($focusTokens === []) {
			return false;
		}
		$titleHaystack = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($entry['title'] ?? ''),
			(string) ($entry['excerpt'] ?? ''),
			(string) wp_parse_url((string) ($entry['url'] ?? ''), PHP_URL_PATH),
		]))));
		$contentHaystack = mb_strtolower(trim((string) ($entry['content'] ?? '')));
		$titleMatches = 0;
		$contentMatches = 0;
		foreach ($focusTokens as $token) {
			if ($token === '') {
				continue;
			}
			if ($titleHaystack !== '' && str_contains($titleHaystack, $token)) {
				$titleMatches++;
			}
			if ($contentHaystack !== '' && str_contains($contentHaystack, $token)) {
				$contentMatches++;
			}
		}
		return $titleMatches >= $titleNeed || $contentMatches >= $contentNeed;
	}

	private static function strip_source_noise(string $text): string {
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\bBefore you continue to Google\b/iu', ' ', $text) ?: $text;
		$text = preg_replace('/\b(Kicker|Bild|TAGESSCHAU|Tagesschau|Merkur|FOCUS(?: online)?|WELT|n-tv|DW|SZ\.de|MSN|Yahoo|Transfermarkt)\b/iu', ' ', $text) ?: $text;
		$text = preg_replace('/\s+/u', ' ', trim($text)) ?: trim($text);
		return $text;
	}

	private static function primary_is_google_wrapper(array $entry): bool {
		$host = self::host((string) ($entry['url'] ?? ''));
		$title = mb_strtolower(trim((string) ($entry['title'] ?? '')));
		return str_contains($host, 'news.google.com') && str_contains($title, 'before you continue to google');
	}

	private static function normalized_story_title(string $title): string {
		$title = self::strip_source_noise($title);
		if (preg_match('/^([^:]{1,40}):\s*(.+)$/u', $title, $match) !== 1) {
			return $title;
		}
		$prefix = trim((string) ($match[1] ?? ''));
		$rest = trim((string) ($match[2] ?? ''));
		if ($rest === '') {
			return $title;
		}
		$prefixLower = mb_strtolower($prefix);
		if (preg_match('/^(bmas|bmf|bmi|bundesregierung|bundestag|deutscher bundestag|dw|tagesschau|rtl|n-tv|ntv|merkur|focus|bild|faz|sz)$/u', $prefixLower) === 1) {
			return $rest;
		}
		if (preg_match('/^[A-ZÄÖÜ0-9 .&-]{2,20}$/u', $prefix) === 1) {
			return $rest;
		}
		return $title;
	}

	private static function support_candidate_score(array $candidate, array $referenceTokens, array $queries, string $category): int {
		$text = trim(implode(' ', array_filter([
			(string) ($candidate['title'] ?? ''),
			(string) ($candidate['excerpt'] ?? ''),
			(string) ($candidate['content'] ?? ''),
		])));
		$textLower = mb_strtolower($text);
		$score = 0;
		foreach ($referenceTokens as $token) {
			if (str_contains($textLower, $token)) {
				$score++;
			}
		}
		foreach ($queries as $query) {
			$query = mb_strtolower(trim($query));
			if ($query !== '' && str_contains($textLower, $query)) {
				$score += 3;
			}
		}
		if ($category !== '' && str_contains($textLower, mb_strtolower($category))) {
			$score += 1;
		}
		if (! empty($candidate['image'])) {
			$score += 1;
		}
		if (mb_strlen((string) ($candidate['excerpt'] ?? '')) >= 120) {
			$score += 1;
		}
		return $score;
	}

	private static function extract_keyword_snippet(string $text): string {
		$text = mb_strtolower(trim(wp_strip_all_tags($text)));
		if ($text === '') {
			return '';
		}
		$stop = [
			'der', 'die', 'das', 'und', 'mit', 'von', 'für', 'den', 'dem', 'des', 'eine', 'einer',
			'dass', 'sich', 'wird', 'werden', 'auch', 'nicht', 'mehr', 'sind', 'ist', 'ein', 'eine',
			'auf', 'bei', 'als', 'zur', 'zum', 'über', 'durch', 'die', 'das', 'den', 'der'
		];
		$words = preg_split('/\s+/u', $text) ?: [];
		$keywords = [];
		foreach ($words as $word) {
			$word = trim($word, " \t\n\r\0\x0B.,:;!?()[]{}\"'«»„“");
			if (mb_strlen($word) < 6 || in_array($word, $stop, true) || isset($keywords[$word])) {
				continue;
			}
			$keywords[$word] = true;
			if (count($keywords) >= 5) {
				break;
			}
		}
		return implode(' ', array_keys($keywords));
	}

	private static function extract_named_entities(string $text): string {
		if ($text === '') {
			return '';
		}
		if (! preg_match_all('/([A-ZÄÖÜ][a-zäöüß]+(?:\s+[A-ZÄÖÜ][a-zäöüß]+){0,2})/u', $text, $matches)) {
			return '';
		}
		$entities = [];
		foreach ((array) ($matches[1] ?? []) as $match) {
			$match = trim((string) $match);
			if (mb_strlen($match) < 6) {
				continue;
			}
			$entities[$match] = true;
			if (count($entities) >= 3) {
				break;
			}
		}
		return implode(' ', array_keys($entities));
	}

	private static function event_context_queries(object $item, array $primary, array $context_memory = []): array {
		$context = self::merge_context_memory(self::extract_event_context_from_entries($item, [$primary]), $context_memory);
		$queries = [];
		foreach ((array) ($context['search_terms'] ?? []) as $term) {
			$queries[] = self::normalize_query((string) $term, 8);
		}
		return array_values(array_unique(array_filter($queries)));
	}

	private static function normalize_context_memory(array $context): array {
		if ($context === []) {
			return [];
		}
		$normalized = [
			'kind' => sanitize_key((string) ($context['kind'] ?? '')),
			'event_title' => self::normalize_query((string) ($context['event_title'] ?? ''), 14),
			'summary' => sanitize_text_field((string) ($context['summary'] ?? '')),
			'body_snippet' => sanitize_text_field((string) ($context['body_snippet'] ?? '')),
			'participants' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($context['participants'] ?? [])))), 0, 4),
			'entities' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($context['entities'] ?? [])))), 0, 8),
			'locations' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($context['locations'] ?? [])))), 0, 8),
			'dates' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($context['dates'] ?? [])))), 0, 8),
			'money_values' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($context['money_values'] ?? [])))), 0, 8),
			'theses' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($context['theses'] ?? [])))), 0, 6),
			'datetime_text' => sanitize_text_field((string) ($context['datetime_text'] ?? '')),
			'venue' => sanitize_text_field((string) ($context['venue'] ?? '')),
			'stage' => sanitize_text_field((string) ($context['stage'] ?? '')),
			'referee' => sanitize_text_field((string) ($context['referee'] ?? '')),
			'head_to_head' => sanitize_text_field((string) ($context['head_to_head'] ?? '')),
			'next_step' => sanitize_text_field((string) ($context['next_step'] ?? '')),
			'fact_snippets' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($context['fact_snippets'] ?? [])))), 0, 6),
			'search_terms' => array_slice(array_values(array_filter(array_map(static fn(string $term): string => self::normalize_query($term, 10), (array) ($context['search_terms'] ?? [])))), 0, 8),
		];
		return array_filter($normalized, static function ($value): bool {
			if (is_array($value)) {
				return $value !== [];
			}
			return trim((string) $value) !== '';
		});
	}

	private static function merge_context_memory(array $current, array $memory): array {
		$current = self::normalize_context_memory($current);
		$memory = self::normalize_context_memory($memory);
		if ($memory === []) {
			return $current;
		}
		if ($current === []) {
			return $memory;
		}
		foreach (['kind', 'event_title', 'summary', 'body_snippet', 'datetime_text', 'venue', 'stage', 'referee', 'head_to_head', 'next_step'] as $field) {
			if (empty($current[$field]) && ! empty($memory[$field])) {
				$current[$field] = $memory[$field];
			}
		}
		foreach (['participants', 'entities', 'locations', 'dates', 'money_values', 'theses', 'fact_snippets', 'search_terms'] as $field) {
			$current[$field] = array_slice(array_values(array_unique(array_filter(array_merge(
				(array) ($current[$field] ?? []),
				(array) ($memory[$field] ?? [])
			)))), 0, $field === 'search_terms' ? 8 : 8);
		}
		return array_filter($current, static function ($value): bool {
			if (is_array($value)) {
				return $value !== [];
			}
			return trim((string) $value) !== '';
		});
	}

	private static function extract_event_context(object $item, array $dossier): array {
		$entries = [];
		if (is_array($dossier['primary'] ?? null)) {
			$entries[] = (array) $dossier['primary'];
		}
		foreach ((array) ($dossier['supporting'] ?? []) as $entry) {
			if (is_array($entry)) {
				$entries[] = $entry;
			}
		}
		return self::extract_event_context_from_entries($item, $entries);
	}

	private static function extract_event_context_from_entries(object $item, array $entries): array {
		$title = trim((string) ($item->original_title ?? ''));
		$excerpt = trim((string) ($item->original_excerpt ?? ''));
		$category = (string) ($item->category_proposed ?? '');
		$eventKind = self::detect_event_kind($title . ' ' . $excerpt, $category);
		if ($eventKind === '') {
			return [];
		}

		$sentences = [];
		foreach ($entries as $entry) {
			if (! is_array($entry)) {
				continue;
			}
			$sentences = array_merge($sentences, self::entry_sentences($entry));
		}
		$sentences = array_values(array_unique(array_filter(array_map(static fn($sentence): string => trim((string) $sentence), $sentences))));

		$participants = self::extract_event_participants($title, $sentences);
		$datetime = self::extract_event_datetime_text($title, $excerpt, $sentences);
		$venue = self::extract_event_venue_text($title, $excerpt, $sentences);
		$referee = self::extract_event_referee_text($sentences);
		$stage = self::extract_event_stage_text($title, $excerpt, $sentences);
		$nextStep = self::extract_event_next_step_text($sentences);
		$headToHead = self::extract_event_history_text($sentences);

		$searchTerms = [];
		$searchTerms[] = $title;
		$participantSearch = $eventKind === 'sport' ? array_slice($participants, 0, 2) : $participants;
		if ($participantSearch !== []) {
			$searchTerms[] = implode(' ', $participantSearch);
			if ($eventKind === 'sport') {
				$searchTerms[] = implode(' ', $participantSearch) . ' match';
				$searchTerms[] = implode(' ', $participantSearch) . ' photo';
			}
		}
		if ($venue !== '') {
			$searchTerms[] = trim($title . ' ' . $venue);
		}
		if ($datetime !== '') {
			$searchTerms[] = trim($title . ' ' . $datetime);
		}
		if ($eventKind === 'community') {
			$searchTerms[] = trim($title . ' event registration');
		}
		if ($eventKind === 'kultur') {
			$searchTerms[] = trim($title . ' show moderator photo');
		}

		$factSnippets = [];
		foreach ([$datetime, $venue, $stage, $referee, $headToHead, $nextStep] as $fact) {
			if ($fact !== '') {
				$factSnippets[] = $fact;
			}
		}

		$context = [
			'kind' => $eventKind,
			'event_title' => self::normalize_query($title, 14),
			'participants' => array_slice($participantSearch !== [] ? $participantSearch : $participants, 0, 4),
			'datetime_text' => $datetime,
			'venue' => $venue,
			'stage' => $stage,
			'referee' => $referee,
			'head_to_head' => $headToHead,
			'next_step' => $nextStep,
			'fact_snippets' => array_slice(array_values(array_unique(array_filter($factSnippets))), 0, 5),
			'search_terms' => array_slice(array_values(array_unique(array_filter(array_map(static fn($term): string => self::normalize_query((string) $term, 10), $searchTerms)))), 0, 6),
		];

		return array_filter($context, static function ($value): bool {
			if (is_array($value)) {
				return $value !== [];
			}
			return trim((string) $value) !== '';
		});
	}

	private static function detect_event_kind(string $text, string $category): string {
		$text = mb_strtolower($text);
		if (in_array($category, ['community', 'leben-in-deutschland'], true) || preg_match('/\b(job fair|career fair|bildungsmesse|berufsmesse|karrieremesse|workshop|sprechstunde|anmeldung|community event|vereinstreffen|diaspora)\b/u', $text) === 1) {
			return 'community';
		}
		if (in_array($category, ['kultur'], true) || preg_match('/\b(the voice|moderator|show|festival|premiere|theater|konzert|museum|ausstellung|tv-show|unterhaltungsshow|jury)\b/u', $text) === 1) {
			return 'kultur';
		}
		$serviceSignals = preg_match('/\b(streik|warnstreik|öpnv|oepnv|verkehr|s-bahn|sbahn|u-bahn|tram|tramverkehr|busse|bus|mvg|deutsche bahn|bahnverkehr|nahverkehr|fahrgäste|fahrgaeste|ausfall|ersatzverkehr|verdi|jobcenter|wohngeld|anmeldung|sprechstunde|beratung)\b/u', $text) === 1;
		$strongSportSignals = preg_match('/\b(anpfiff|rückspiel|rueckspiel|hinspiel|halbfinale|viertelfinale|bundesliga|champions league|paralymp|trainer|schiedsrichter|allianz arena|stadion|fc [a-zäöü]|borussia|real madrid|atalanta|heidenheim|torhüter|torhueter|elfmeter|tabellenplatz)\b/u', $text) === 1;
		if ($category === 'sport') {
			return 'sport';
		}
		if (! $serviceSignals && $strongSportSignals) {
			return 'sport';
		}
		return '';
	}

	private static function entry_sentences(array $entry): array {
		$text = trim(implode(' ', array_filter([
			(string) ($entry['title'] ?? ''),
			(string) ($entry['excerpt'] ?? ''),
			(string) ($entry['content'] ?? ''),
		])));
		if ($text === '') {
			return [];
		}
		$text = preg_replace('/\s+/u', ' ', $text) ?: $text;
		$parts = preg_split('/(?<=[\.\!\?])\s+/u', $text) ?: [];
		return array_slice(array_values(array_filter(array_map('trim', $parts))), 0, 24);
	}

	private static function extract_event_participants(string $title, array $sentences): array {
		$title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$patterns = [
			'/\b([A-ZÄÖÜ][\p{L}\- ]{1,40}?)\s+(?:gegen|vs\.?|versus|v\.)\s+([A-ZÄÖÜ][\p{L}\- ]{1,40})\b/u',
			'/\bbetween\s+([A-ZÄÖÜ][\p{L}\- ]{1,40}?)\s+and\s+([A-ZÄÖÜ][\p{L}\- ]{1,40})\b/ui',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $title, $match) === 1) {
				$pair = self::normalize_participant_pair([
					(string) ($match[1] ?? ''),
					(string) ($match[2] ?? ''),
				]);
				if (self::participant_pair_is_reasonable($pair)) {
					return $pair;
				}
			}
		}
		foreach ($sentences as $sentence) {
			if (preg_match('/\b(?:gegen|vs\.?|versus|opponent|gegner)\b/ui', $sentence) !== 1) {
				continue;
			}
			if (preg_match('/([A-ZÄÖÜ][\p{L}\- ]{1,40})\s+(?:gegen|vs\.?|versus)\s+([A-ZÄÖÜ][\p{L}\- ]{1,40})/u', $sentence, $match) === 1) {
				$pair = self::normalize_participant_pair([
					(string) ($match[1] ?? ''),
					(string) ($match[2] ?? ''),
				]);
				if (self::participant_pair_is_reasonable($pair)) {
					return $pair;
				}
			}
		}
		return self::extract_sport_team_entities($title, $sentences);
	}

	private static function extract_sport_team_entities(string $title, array $sentences): array {
		$weights = [];
		$pattern = '/\b((?:FC|SC|SV|VfB|TSG|RB)\s+[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+){0,2}|(?:Real|Atletico|Borussia|Bayer|Eintracht|Manchester|Paris|Juventus|Inter|Barcelona|Liverpool|Chelsea|Arsenal|Atalanta)\s+[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+){0,1}|Bayern(?:\s+München)?|Real\s+Madrid)\b/u';
		$texts = array_merge([$title, $title], $sentences);
		foreach ($texts as $text) {
			if (preg_match_all($pattern, (string) $text, $matches) !== 1) {
				continue;
			}
			foreach ((array) ($matches[1] ?? []) as $match) {
				$entity = self::normalize_participant_entity((string) $match);
				if ($entity === '') {
					continue;
				}
				$weights[$entity] = (int) ($weights[$entity] ?? 0) + 1;
			}
		}
		if ($weights === []) {
			return [];
		}
		arsort($weights);
		$entities = [];
		foreach (array_keys($weights) as $entity) {
			if (! self::participant_entity_is_reasonable($entity)) {
				continue;
			}
			$entities[] = $entity;
			if (count($entities) >= 2) {
				break;
			}
		}
		return $entities;
	}

	private static function extract_event_datetime_text(string $title, string $excerpt, array $sentences): string {
		$texts = array_merge([$title, $excerpt], $sentences);
		$patterns = [
			'/\b(?:am\s+)?\d{1,2}\.\s*(?:januar|februar|m[äa]rz|april|mai|juni|juli|august|september|oktober|november|dezember)(?:\s+\d{4})?(?:\s+(?:um|ab)\s+\d{1,2}[:\.]\d{2}\s*uhr)?/iu',
			'/\b(?:heute|morgen|tonight|today|tomorrow|сьогодні|завтра)(?:\s+(?:um|at|о)\s+\d{1,2}[:\.]\d{2})?/iu',
			'/\b(?:anpfiff|beginn|start|kick-?off|match starts?|starts?|beginn der veranstaltung|beginnt)\b[^\.]{0,80}?\b\d{1,2}[:\.]\d{2}\s*(?:uhr|cet|cest|utc)?/iu',
		];
		foreach ($texts as $text) {
			foreach ($patterns as $pattern) {
				if (preg_match($pattern, (string) $text, $match) === 1) {
					return trim((string) ($match[0] ?? ''));
				}
			}
		}
		return '';
	}

	private static function extract_event_venue_text(string $title, string $excerpt, array $sentences): string {
		$texts = array_merge([$title, $excerpt], $sentences);
		$patterns = [
			'/\b(?:im|in der|in den|at|in)\s+([A-ZÄÖÜ][\p{L}\- ]{2,60}?(?:Arena|Stadion|Hall|Halle|Messe|Studio|Theater|Museum|Center|Centre|Zentrum|Campus|Park))\b/u',
			'/\b(?:venue|spielort|veranstaltungsort)\s*[:\-]?\s*([A-ZÄÖÜ][\p{L}\- ]{2,60})\b/u',
		];
		foreach ($texts as $text) {
			foreach ($patterns as $pattern) {
				if (preg_match($pattern, (string) $text, $match) === 1) {
					return self::clean_entity((string) ($match[1] ?? ''));
				}
			}
		}
		return '';
	}

	private static function extract_event_referee_text(array $sentences): string {
		foreach ($sentences as $sentence) {
			if (preg_match('/\b(?:schiedsrichter|referee)\b[^\.]{0,60}?([A-ZÄÖÜ][a-zäöüß]+(?:\s+[A-ZÄÖÜ][a-zäöüß]+){0,2})/u', $sentence, $match) === 1) {
				return self::clean_entity((string) ($match[1] ?? ''));
			}
		}
		return '';
	}

	private static function extract_event_stage_text(string $title, string $excerpt, array $sentences): string {
		$texts = array_merge([$title, $excerpt], $sentences);
		foreach ($texts as $text) {
			if (preg_match('/\b(achtelfinale|viertelfinale|halbfinale|finale|play-off|gruppenphase|rückspiel|hinspiel|qualifikation|casting|staffel|vorrunde|runde)\b/iu', (string) $text, $match) === 1) {
				return trim((string) ($match[1] ?? ''));
			}
		}
		return '';
	}

	private static function extract_event_next_step_text(array $sentences): string {
		foreach ($sentences as $sentence) {
			if (preg_match('/\b(bei einem sieg|im falle eines sieges|winner will face|the winner will face|wer weiterkommt|in der nächsten runde|next round|next opponent)\b/iu', $sentence) === 1) {
				return self::trim_sentence($sentence, 180);
			}
		}
		return '';
	}

	private static function extract_event_history_text(array $sentences): string {
		foreach ($sentences as $sentence) {
			if (preg_match('/\b(head-?to-?head|bilanz|letzten?\s+\d+\s+duelle|previous meetings|last\s+\d+\s+meetings|bisherigen?\s+duelle)\b/iu', $sentence) === 1) {
				return self::trim_sentence($sentence, 180);
			}
		}
		return '';
	}

	private static function trim_sentence(string $sentence, int $limit): string {
		$sentence = trim(preg_replace('/\s+/u', ' ', $sentence) ?: $sentence);
		if (mb_strlen($sentence) <= $limit) {
			return $sentence;
		}
		$cut = mb_substr($sentence, 0, $limit);
		$space = mb_strrpos($cut, ' ');
		if ($space !== false) {
			$cut = mb_substr($cut, 0, $space);
		}
		return rtrim($cut, " \t\n\r\0\x0B,.;:-");
	}

	private static function clean_entity(string $value): string {
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?: $value);
		$value = preg_replace('/^(?:der|die|das|den|dem|des)\s+/u', '', $value) ?: $value;
		$value = preg_replace('/\s+(mit|ohne|nach|vor|wegen|trotz)\b.*$/u', '', $value) ?: $value;
		$value = preg_replace('/\s+(erledigt|spielt|spielte|trifft|traf|droht|hofft|kann|steht|stehen|ist|sind|war|werden|wird|bleibt|seine|sein|ihre|ihr|im|in|auf|bei|zum|zur)\b.*$/u', '', $value) ?: $value;
		if (mb_strlen($value) > 28 && str_contains($value, ' und ')) {
			$parts = preg_split('/\s+und\s+/u', $value) ?: [];
			if (! empty($parts[0])) {
				$value = (string) $parts[0];
			}
		}
		$value = preg_replace('/\s+und$/u', '', $value) ?: $value;
		$value = trim($value, " \t\n\r\0\x0B,.;:-");
		return sanitize_text_field($value);
	}

	private static function normalize_participant_pair(array $pair): array {
		$normalized = [];
		foreach ($pair as $entity) {
			$clean = self::normalize_participant_entity((string) $entity);
			if ($clean === '') {
				continue;
			}
			$normalized[] = $clean;
		}
		return array_values(array_unique($normalized));
	}

	private static function normalize_participant_entity(string $value): string {
		$value = self::clean_entity($value);
		$aliases = [
			'/\bBayern(?:\s+München)?\b/u' => 'FC Bayern München',
			'/\bReal\s+Madrid\b/u' => 'Real Madrid',
			'/\bAtalanta(?:\s+Bergamo)?\b/u' => 'Atalanta Bergamo',
			'/\bManchester\s+City\b/u' => 'Manchester City',
			'/\bAtl[eé]tico\s+Madrid\b/u' => 'Atlético Madrid',
		];
		foreach ($aliases as $pattern => $replacement) {
			if (preg_match($pattern, $value) === 1) {
				return $replacement;
			}
		}
		return $value;
	}

	private static function participant_pair_is_reasonable(array $pair): bool {
		if (count($pair) < 2) {
			return false;
		}
		if (mb_strtolower((string) $pair[0]) === mb_strtolower((string) $pair[1])) {
			return false;
		}
		foreach ($pair as $entity) {
			if (! self::participant_entity_is_reasonable((string) $entity)) {
				return false;
			}
		}
		return true;
	}

	private static function participant_entity_is_reasonable(string $entity): bool {
		$entity = trim($entity);
		if ($entity === '' || mb_strlen($entity) > 42) {
			return false;
		}
		$tokenCount = count(array_values(array_filter(preg_split('/\s+/u', $entity) ?: [])));
		if ($tokenCount > 4) {
			return false;
		}
		if (preg_match('/\b(erledigt|pflichtaufgabe|vorausgesetzt|möglichen?|wartet|droht|weiterkommen|weiterkommens|falle|sieges|sein(?:e|er)?|ihre|ihr|gegen den|gegen die)\b/ui', $entity) === 1) {
			return false;
		}
		return preg_match('/\b(?:FC|SC|SV|VfB|TSG|RB|Real|Bayern|Borussia|Bayer|Eintracht|Manchester|Paris|Juventus|Inter|Barcelona|Liverpool|Chelsea|Arsenal|Atalanta|Atl[eé]tico)\b/u', $entity) === 1;
	}

	private static function fetch_document_safe(string $url): array {
		if ($url === '') {
			return [];
		}
		try {
			return EPV2_HTML_Reader::fetch_document($url);
		} catch (Throwable $e) {
			return [];
		}
	}

	private static function source_entry(string $url, string $title, string $excerpt, string $content, string $image = ''): array {
		$host = self::host($url);
		return [
			'url' => esc_url_raw($url),
			'host' => $host,
			'source_name' => self::source_name_from_host($host),
			'title' => sanitize_text_field($title),
			'excerpt' => sanitize_textarea_field(trim(wp_strip_all_tags($excerpt))),
			'content' => trim(wp_strip_all_tags($content)),
			'image' => esc_url_raw($image),
			'is_official' => self::is_official_url($url),
		];
	}

	private static function is_poor_signal(array $entry): bool {
		$contentLen = mb_strlen((string) ($entry['content'] ?? ''));
		$excerptLen = mb_strlen((string) ($entry['excerpt'] ?? ''));
		return $contentLen < 900 && $excerptLen < 220;
	}

	private static function should_force_supporting(array $entry): bool {
		if (! empty($entry['is_official'])) {
			return true;
		}
		$content = mb_strtolower((string) ($entry['content'] ?? ''));
		foreach (['krieg', 'ukraine', 'iran', 'streik', 'sanktionen', 'mindestlohn', 'arbeitsmarkt', 'pflege', 'migration', 'asyl', 'bahn', 'nahverkehr'] as $term) {
			if (str_contains($content, $term)) {
				return true;
			}
		}
		return false;
	}

	private static function needs_substance_support(array $entry, array $quotes): bool {
		$content = trim((string) ($entry['content'] ?? ''));
		$title = trim((string) ($entry['title'] ?? ''));
		if (mb_strlen($content) < 1500) {
			return true;
		}
		if (! self::has_meaningful_quote(['quotes' => $quotes]) && preg_match('/\b(fachkr[aä]fte|pflege|asyl|migration|streik|bahn|nahverkehr|ukraine|iran|sanktionen|mindestlohn)\b/iu', $title . ' ' . $content)) {
			return true;
		}
		return false;
	}

	private static function needs_visual_support(array $entry): bool {
		$image = strtolower((string) ($entry['image'] ?? ''));
		if ($image === '') {
			return true;
		}
		return preg_match('/logo-share-social-media|logo|sprite|icon|csm_wbm|share-social|default/i', $image) === 1;
	}

	private static function is_official_url(string $url): bool {
		$host = preg_replace('/^www\./i', '', self::host($url));
		if ($host === '') {
			return false;
		}
		if (
			str_ends_with($host, '.museum')
			|| str_contains($host, 'museum')
			|| str_contains($host, 'museen')
		) {
			return true;
		}
		$official_hosts = [
			'bundesregierung.de',
			'bundestag.de',
			'europa.eu',
			'bamf.de',
			'arbeitsagentur.de',
			'jobcenter-muenchen.de',
			'stmi.bayern.de',
			'bayern.de',
			'muenchen.de',
			'stadt.muenchen.de',
			'ukrinform.net',
			'ukrinform.ua',
		];
		foreach ($official_hosts as $official) {
			if ($host === $official || str_ends_with($host, '.' . $official)) {
				return true;
			}
		}
		return str_contains($host, '.gov');
	}

	private static function host(string $url): string {
		return strtolower((string) parse_url($url, PHP_URL_HOST));
	}

	private static function extract_quotes_from_entry(array $entry): array {
		$text = trim((string) (($entry['content'] ?? '') . "\n\n" . ($entry['excerpt'] ?? '')));
		if ($text === '') {
			return [];
		}

		$quotes = [];
		if (preg_match_all('/[„"«]([^"»“]{35,260})[“"»]/u', $text, $matches)) {
			foreach ((array) ($matches[1] ?? []) as $quote) {
				$quote = trim(preg_replace('/\s+/u', ' ', (string) $quote) ?: (string) $quote);
				if (mb_strlen($quote) < 35) {
					continue;
				}
				if (self::quote_looks_spurious($quote)) {
					continue;
				}
				$speaker = self::guess_quote_speaker($text, $quote);
				if (self::quote_speaker_looks_spurious($speaker)) {
					$speaker = '';
				}
				if ($speaker === '') {
					continue;
				}
				$quotes[] = [
					'text' => $quote,
					'speaker' => $speaker,
					'url' => (string) ($entry['url'] ?? ''),
					'source_name' => self::source_name_from_host((string) ($entry['host'] ?? self::host((string) ($entry['url'] ?? '')))),
					'attribution' => self::build_quote_attribution($entry, $text, $quote),
				];
				if (count($quotes) >= 3) {
					break;
				}
			}
		}

		return $quotes;
	}

	private static function quote_looks_spurious(string $quote): bool {
		$quote = trim(preg_replace('/\s+/u', ' ', $quote) ?: $quote);
		if ($quote === '') {
			return true;
		}
		if (preg_match('/^\p{Ll}/u', $quote) === 1) {
			return true;
		}
		if (preg_match('/[:;]\s*$/u', $quote) === 1) {
			return true;
		}
		return preg_match('/\b(newsletter|anmeldung|postfach|hier geht|sign up here|private inbox|volltextsuche|symbolbild|der schriftzug|europäische perspektive|europaeische perspektive)\b/iu', $quote) === 1;
	}

	private static function quote_speaker_looks_spurious(string $speaker): bool {
		$normalized = trim(preg_replace('/\s+/u', ' ', $speaker) ?: $speaker);
		if ($normalized === '') {
			return false;
		}
		$lower = mb_strtolower($normalized);
		if (preg_match('/\b(der schriftzug|auf|spreewasser|innen mit der|volltextsuche|symbolbild|bild|kritik|innenpolitisch|rettungsdienstes|fraktion|analyse|bundestag|rundfunk)\b/iu', $lower) === 1) {
			return true;
		}
		if (preg_match('/^[\p{Ll}\s\-]+$/u', $normalized) === 1) {
			return true;
		}
		return preg_match('/^\p{Lu}[\p{L}\-\'’.]+(?:\s+(?:\p{Lu}[\p{L}\-\'’.]+|von|van|der|de|den|di|da)){0,4}$/u', $normalized) !== 1;
	}

	private static function guess_quote_speaker(string $text, string $quote): string {
		$pos = mb_strpos($text, $quote);
		$window = $pos === false ? $text : mb_substr($text, max(0, $pos - 240), 520);
		$patterns = [
			'/so\s+(?:Bundesarbeitsministerin|Bundesministerin|Ministerpräsident|Ministerin|Minister|Kanzler|Präsidentin|Präsident)?\s*([A-ZÄÖÜ][a-zäöüß]+(?:\s+[A-ZÄÖÜ][a-zäöüß]+){0,2})/u',
			'/([A-ZÄÖÜ][a-zäöüß]+(?:\s+[A-ZÄÖÜ][a-zäöüß]+){0,2}).{0,80}(?:sagte|erklärte|betonte|so)\b/uis',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $window, $match)) {
				return trim((string) ($match[1] ?? ''));
			}
		}
		return '';
	}

	private static function build_quote_attribution(array $entry, string $text, string $quote): string {
		$source_name = (string) ($entry['source_name'] ?? self::source_name_from_host((string) ($entry['host'] ?? self::host((string) ($entry['url'] ?? '')))));
		$context = $text;
		$pos = mb_strpos($text, $quote);
		if ($pos !== false) {
			$context = mb_substr($text, max(0, $pos - 260), 520);
		}
		$context = mb_strtolower($context);
		$interview_signal = preg_match('/\b(im interview mit|in einem interview mit|gegenüber|told|in interview with|заявив? у|сказав? в інтерв\'?ю|в интервью)\b/u', $context) === 1;
		if ($source_name === '') {
			return $interview_signal ? 'in interview' : '';
		}
		return $interview_signal ? ('in interview with ' . $source_name) : ('according to ' . $source_name);
	}

	private static function source_name_from_host(string $host): string {
		$host = strtolower(preg_replace('/^www\./i', '', trim($host)));
		if ($host === '') {
			return '';
		}
		return match (true) {
			str_contains($host, 'bundesregierung.de') => 'Bundesregierung',
			str_contains($host, 'bundestag.de') => 'Deutscher Bundestag',
			str_contains($host, 'bamf.de') => 'BAMF',
			str_contains($host, 'muenchen.de'), str_contains($host, 'stadt.muenchen.de') => 'Stadt München',
			str_contains($host, 'germany4ukraine.de') => 'Germany4Ukraine',
			str_contains($host, 'tagesschau.de') => 'Tagesschau',
			str_contains($host, 'dw.com') => 'DW',
			str_contains($host, 'ukrinform') => 'Ukrinform',
			str_contains($host, 't.me') => 'Telegram',
			default => ucfirst((string) preg_replace('/\.[a-z]{2,}$/i', '', $host)),
		};
	}
}
