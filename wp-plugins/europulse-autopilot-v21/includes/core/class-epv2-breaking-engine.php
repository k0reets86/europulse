<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Breaking_Engine {
	public static function analyze(array $item, ?object $source = null, int $consensus_mentions = 0, int $urgency = 0): array {
		$title = mb_strtolower(wp_strip_all_tags((string) ($item['title'] ?? '')));
		$excerpt = mb_strtolower(wp_strip_all_tags((string) ($item['excerpt'] ?? '')));
		$content = mb_strtolower(wp_strip_all_tags((string) ($item['content'] ?? '')));
		$url = (string) ($item['url'] ?? ($source->url ?? ''));
		$source_name = mb_strtolower((string) ($source->name ?? ''));
		$text = trim($title . ' ' . $excerpt . ' ' . mb_substr($content, 0, 600));

		$score = 0;
		$reasons = [];

		$source_signal = self::major_media_signal($url, $source_name);
		$score += $source_signal['weight'];
		if ($source_signal['weight'] > 0) {
			$reasons[] = $source_signal['reason'];
		}

		$label_weight = self::live_breaking_label_weight($text, $url);
		$score += $label_weight;
		if ($label_weight >= 8) {
			$reasons[] = 'есть live/breaking/update сигнал';
		}

		$velocity_weight = self::velocity_weight($item);
		$score += $velocity_weight;
		if ($velocity_weight >= 6) {
			$reasons[] = 'тема быстро растёт в коротком окне';
		}

		if ($consensus_mentions >= 2) {
			$score += min(12, $consensus_mentions * 3);
			$reasons[] = 'сюжет поддержан несколькими сильными источниками';
		}

		if ($urgency >= 10) {
			$score += 8;
			$reasons[] = 'высокая срочность по формулировкам';
		} elseif ($urgency >= 6) {
			$score += 4;
		}

		$score = max(0, min(100, $score));

		return [
			'confidence' => $score,
			'watch' => $score >= 18,
			'breaking' => $score >= 28,
			'reasons' => array_values(array_unique($reasons)),
		];
	}

	private static function major_media_signal(string $url, string $source_name): array {
		$host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
		$signals = [
			'reuters.com' => ['weight' => 10, 'reason' => 'сигнал Reuters'],
			'bbc.' => ['weight' => 10, 'reason' => 'сигнал BBC'],
			'dw.com' => ['weight' => 9, 'reason' => 'сигнал Deutsche Welle'],
			'rferl.org' => ['weight' => 9, 'reason' => 'сигнал RFE/RL'],
			'radiosvoboda.org' => ['weight' => 9, 'reason' => 'сигнал Радіо Свобода'],
			'apnews.com' => ['weight' => 8, 'reason' => 'сигнал AP'],
			'cnn.com' => ['weight' => 7, 'reason' => 'сигнал CNN'],
			'aljazeera.com' => ['weight' => 7, 'reason' => 'сигнал Al Jazeera'],
			'tagesschau.de' => ['weight' => 8, 'reason' => 'сигнал Tagesschau'],
		];
		foreach ($signals as $pattern => $meta) {
			if (($host !== '' && str_contains($host, $pattern)) || str_contains($source_name, str_replace('.com', '', $pattern))) {
				return $meta;
			}
		}
		return ['weight' => 0, 'reason' => ''];
	}

	private static function live_breaking_label_weight(string $text, string $url): int {
		$weight = 0;
		$terms = [
			'breaking news', 'breaking', 'live updates', 'live update', 'updates', 'what you need to know',
			'eilmeldung', 'liveticker', 'aktualisiert', 'liveblog',
			'терміново', 'наживо', 'оновлюється', 'breaking',
		];
		foreach ($terms as $term) {
			if (str_contains($text, $term) || str_contains(mb_strtolower($url), $term)) {
				$weight += 4;
			}
		}
		return min(14, $weight);
	}

	private static function velocity_weight(array $item): int {
		global $wpdb;
		$title = trim(mb_strtolower(wp_strip_all_tags((string) ($item['title'] ?? ''))));
		if ($title === '') {
			return 0;
		}
		$needle = mb_substr($title, 0, 42);
		$count = self::recent_title_mentions($needle, 90 * MINUTE_IN_SECONDS);
		return match (true) {
			$count >= 5 => 10,
			$count >= 3 => 6,
			$count >= 2 => 3,
			default => 0,
		};
	}

	private static function recent_title_mentions(string $needle, int $window_seconds): int {
		global $wpdb;
		$needle = trim(mb_strtolower($needle));
		if ($needle === '') {
			return 0;
		}
		static $cache = [];
		$cache_key = md5($needle . '|' . $window_seconds);
		if (array_key_exists($cache_key, $cache)) {
			return (int) $cache[$cache_key];
		}
		$table = $wpdb->prefix . 'epv2_queue';
		$max_id = (int) $wpdb->get_var("SELECT MAX(id) FROM {$table}");
		if ($max_id <= 0) {
			return 0;
		}
		$min_id = max(0, $max_id - 1000);
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT original_title, created_at FROM {$table} WHERE id >= %d ORDER BY id DESC LIMIT 250",
			$min_id
		));
		$cutoff = time() - max(60, $window_seconds);
		$count = 0;
		foreach ((array) $rows as $row) {
			$created = strtotime((string) ($row->created_at ?? ''));
			if ($created && $created < $cutoff) {
				continue;
			}
			$row_title = trim(mb_strtolower(wp_strip_all_tags((string) ($row->original_title ?? ''))));
			if ($row_title !== '' && str_contains($row_title, $needle)) {
				$count++;
			}
		}
		$cache[$cache_key] = $count;
		return (int) $cache[$cache_key];
	}
}
