<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Trends {
	private const OPTION_HISTORY = 'epv2_trend_history';
	private const OPTION_LAST_REFRESH = 'epv2_trend_last_refresh';

	public static function refresh(bool $force = false): array {
		$enabled = (bool) EPV2_Settings::get('trend_signal_enabled', true);
		if (! $enabled) {
			return [];
		}
		$last = (int) get_option(self::OPTION_LAST_REFRESH, 0);
		if (! $force && $last > 0 && $last > (time() - (25 * MINUTE_IN_SECONDS))) {
			return self::history();
		}

		$regions = self::regions();
		$history = self::history();
		$snapshot = [
			'timestamp' => time(),
			'regions' => [],
		];
		foreach ($regions as $geo) {
			$items = self::fetch_region($geo);
			if ($items !== []) {
				$snapshot['regions'][$geo] = $items;
			}
		}
		if ($snapshot['regions'] !== []) {
			$history[] = $snapshot;
			$cutoff = time() - (max(3, (int) EPV2_Settings::get('trend_history_days', 7)) * DAY_IN_SECONDS);
			$history = array_values(array_filter($history, static function (array $row) use ($cutoff): bool {
				return (int) ($row['timestamp'] ?? 0) >= $cutoff;
			}));
			update_option(self::OPTION_HISTORY, $history, false);
			update_option(self::OPTION_LAST_REFRESH, time(), false);
		}
		return $history;
	}

	public static function trend_signal(string $title, string $content = ''): array {
		$text = self::normalize_text($title . ' ' . $content);
		if ($text === '') {
			return ['match' => false, 'weight' => 0, 'reasons' => []];
		}
		$topics = self::combined_topics();
		$best = null;
		foreach ($topics as $topic) {
			$query = (string) ($topic['query'] ?? '');
			$tokens = self::tokens($query);
			if ($tokens === []) {
				continue;
			}
			$overlap = count(array_intersect($tokens, self::tokens($text)));
			$need = max(2, (int) ceil(count($tokens) * 0.5));
			if ($overlap < $need) {
				continue;
			}
			$score = (int) ($topic['growth_score'] ?? 0) + ($overlap * 2);
			if ($best === null || $score > (int) ($best['score'] ?? 0)) {
				$best = [
					'match' => true,
					'weight' => min(16, 6 + $overlap + (int) floor(((int) ($topic['trend_score'] ?? 0)) / 8)),
					'reasons' => array_values(array_filter([
						! empty($topic['daily_score']) ? 'тема растёт в дневных Google Trends' : '',
						! empty($topic['weekly_score']) ? 'тема держится в недельном тренде' : '',
						'trend: ' . $query,
					])),
					'score' => $score,
					'topic' => $topic,
				];
			}
		}
		if ($best === null) {
			return ['match' => false, 'weight' => 0, 'reasons' => []];
		}
		unset($best['score']);
		return $best;
	}

	public static function rising_topics(): array {
		return self::daily_topics();
	}

	public static function daily_topics(): array {
		return self::topics_for_window(DAY_IN_SECONDS, 'daily');
	}

	public static function weekly_topics(): array {
		return self::topics_for_window(7 * DAY_IN_SECONDS, 'weekly');
	}

	public static function combined_topics(): array {
		$daily = [];
		foreach (self::daily_topics() as $topic) {
			$daily[self::topic_key((string) ($topic['query'] ?? ''))] = $topic;
		}
		$weekly = [];
		foreach (self::weekly_topics() as $topic) {
			$weekly[self::topic_key((string) ($topic['query'] ?? ''))] = $topic;
		}
		$keys = array_unique(array_merge(array_keys($daily), array_keys($weekly)));
		$out = [];
		foreach ($keys as $key) {
			$d = $daily[$key] ?? [];
			$w = $weekly[$key] ?? [];
			$query = (string) ($d['query'] ?? $w['query'] ?? '');
			if ($query === '') {
				continue;
			}
			$out[] = [
				'query' => $query,
				'hits' => (int) ($d['hits'] ?? 0) + (int) ($w['hits'] ?? 0),
				'regions' => array_values(array_unique(array_merge((array) ($d['regions'] ?? []), (array) ($w['regions'] ?? [])))),
				'daily_score' => (int) ($d['growth_score'] ?? 0),
				'weekly_score' => (int) ($w['growth_score'] ?? 0),
				'trend_score' => ((int) ($d['growth_score'] ?? 0)) + ((int) floor(((int) ($w['growth_score'] ?? 0)) * 1.25)),
				'snapshot_count' => max((int) ($d['snapshot_count'] ?? 0), (int) ($w['snapshot_count'] ?? 0)),
			];
		}
		usort($out, static function (array $a, array $b): int {
			return ((int) ($b['trend_score'] ?? 0)) <=> ((int) ($a['trend_score'] ?? 0));
		});
		return array_slice($out, 0, 20);
	}

	private static function topics_for_window(int $windowSeconds, string $mode): array {
		$history = self::history();
		if ($history === []) {
			return [];
		}
		$minHits = max(2, (int) EPV2_Settings::get('trend_min_hits', 2));
		$cutoff = time() - $windowSeconds;
		$history = array_values(array_filter($history, static function (array $snapshot) use ($cutoff): bool {
			return (int) ($snapshot['timestamp'] ?? 0) >= $cutoff;
		}));
		if ($history === []) {
			return [];
		}
		$topics = [];
		foreach ($history as $snapshot) {
			foreach ((array) ($snapshot['regions'] ?? []) as $geo => $items) {
				foreach ((array) $items as $item) {
					$query = trim((string) ($item['title'] ?? ''));
					if ($query === '') {
						continue;
					}
					$key = self::topic_key($query);
					if (! isset($topics[$key])) {
						$topics[$key] = [
							'query' => $query,
							'hits' => 0,
							'regions' => [],
							'traffic_series' => [],
							'snapshots' => [],
						];
					}
					$topics[$key]['hits']++;
					$topics[$key]['regions'][$geo] = true;
					$topics[$key]['snapshots'][(string) ($snapshot['timestamp'] ?? 0)] = true;
					$topics[$key]['traffic_series'][] = (int) ($item['traffic'] ?? 0);
				}
			}
		}

		$out = [];
		foreach ($topics as $key => $topic) {
			$hits = (int) ($topic['hits'] ?? 0);
			if ($hits < $minHits) {
				continue;
			}
			$snapshotCount = count((array) ($topic['snapshots'] ?? []));
			if ($snapshotCount < 2) {
				continue;
			}
			$series = array_values(array_filter(array_map('intval', (array) ($topic['traffic_series'] ?? []))));
			$first = $series[0] ?? 0;
			$last = $series[count($series) - 1] ?? 0;
			$growth = $last - $first;
			$growthScore = ($hits * 4) + (count((array) ($topic['regions'] ?? [])) * 2);
			if ($growth > 0) {
				$growthScore += min(12, (int) floor($growth / 20000));
			}
			if ($mode === 'weekly') {
				$growthScore += min(10, $snapshotCount * 2);
			}
			$topic['regions'] = array_keys((array) ($topic['regions'] ?? []));
			$topic['snapshot_count'] = $snapshotCount;
			$topic['growth_score'] = $growthScore;
			$out[] = $topic;
		}

		usort($out, static function (array $a, array $b): int {
			return ((int) ($b['growth_score'] ?? 0)) <=> ((int) ($a['growth_score'] ?? 0));
		});
		return array_slice($out, 0, 20);
	}

	public static function candidate_items(): array {
		$items = [];
		foreach (array_slice(self::combined_topics(), 0, 6) as $topic) {
			$query = (string) ($topic['query'] ?? '');
			if ($query === '') {
				continue;
			}
			$url = 'https://news.google.com/rss/search?q=' . rawurlencode($query) . '&hl=de&gl=DE&ceid=DE:de';
			try {
				foreach (EPV2_Feed_Reader::fetch($url, true) as $candidate) {
					$candidate['trend_query'] = $query;
					$candidate['trend_growth_score'] = (int) ($topic['growth_score'] ?? 0);
					$items[] = $candidate;
					break;
				}
			} catch (Throwable $e) {
				EPV2_Logger::warning('trends', 'Trend candidate fetch failed', ['query' => $query, 'error' => $e->getMessage()]);
			}
		}
		return $items;
	}

	private static function fetch_region(string $geo): array {
		$url = 'https://trends.google.com/trending/rss?geo=' . rawurlencode($geo);
		$response = wp_remote_get($url, [
			'timeout' => 20,
			'headers' => [
				'User-Agent' => 'Mozilla/5.0 (EuroPulse AutoPilot)',
			],
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
		if (! $xml || empty($xml->channel->item)) {
			return [];
		}
		$out = [];
		foreach ($xml->channel->item as $item) {
			$title = trim((string) $item->title);
			if ($title === '') {
				continue;
			}
			$children = $item->children('https://trends.google.com/trending/rss');
			$traffic = self::parse_traffic((string) ($children->approx_traffic ?? ''));
			$out[] = [
				'title' => $title,
				'traffic' => $traffic,
			];
		}
		return array_slice($out, 0, 20);
	}

	private static function parse_traffic(string $value): int {
		$value = trim($value);
		if ($value === '') {
			return 0;
		}
		$value = str_replace([',', '.'], '', $value);
		if (preg_match('/(\d+)\s*K/i', $value, $m)) {
			return ((int) $m[1]) * 1000;
		}
		if (preg_match('/(\d+)\s*M/i', $value, $m)) {
			return ((int) $m[1]) * 1000000;
		}
		if (preg_match('/(\d+)/', $value, $m)) {
			return (int) $m[1];
		}
		return 0;
	}

	private static function history(): array {
		$history = get_option(self::OPTION_HISTORY, []);
		return is_array($history) ? $history : [];
	}

	private static function regions(): array {
		$regions = EPV2_Settings::get('trend_regions', ['DE', 'FR', 'IT', 'ES', 'PL', 'NL']);
		if (! is_array($regions) || $regions === []) {
			return ['DE'];
		}
		return array_values(array_unique(array_filter(array_map(static fn($v) => strtoupper(trim((string) $v)), $regions))));
	}

	private static function topic_key(string $query): string {
		return sanitize_title(self::normalize_text($query));
	}

	private static function normalize_text(string $text): string {
		$text = mb_strtolower(wp_strip_all_tags($text));
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: $text;
		$text = preg_replace('/\s+/u', ' ', trim($text)) ?: trim($text);
		return $text;
	}

	private static function tokens(string $text): array {
		$text = self::normalize_text($text);
		$stop = ['der','die','das','und','mit','von','für','fuer','des','dem','den','eine','ein','the','and','for','mit','news','live'];
		$tokens = [];
		foreach (preg_split('/\s+/u', $text) ?: [] as $token) {
			if (mb_strlen($token) < 4 || in_array($token, $stop, true)) {
				continue;
			}
			$tokens[$token] = true;
		}
		return array_keys($tokens);
	}
}
