<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Deduplicator {
	public static function hashes(string $title, string $content): array {
		$normalized = self::normalize($content);
		return [
			'title_hash' => hash('sha256', mb_strtolower(trim($title))),
			'content_hash' => hash('sha256', $normalized),
			'semantic_hash' => hash('sha256', self::keywords($title . ' ' . $content)),
		];
	}

	public static function is_duplicate(string $title, string $content, string $url = ''): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$hashes = self::hashes($title, $content);
		$dup = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE title_hash = %s OR content_hash = %s LIMIT 1",
				$hashes['title_hash'],
				$hashes['content_hash']
			)
		);
		if ($dup) {
			return ['duplicate' => true, 'duplicate_of' => (int) $dup->id, 'reason' => 'hash'];
		}

		if ($url !== '') {
			$normalized_url = self::normalize_url($url);
			$wp = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','pending','future') AND guid = %s LIMIT 1", $url));
			if ($wp) {
				return ['duplicate' => true, 'duplicate_of' => (int) $wp, 'reason' => 'published_url'];
			}
			if ($normalized_url !== '') {
				$meta_post = $wpdb->get_var($wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_epv2_source_url' AND (meta_value = %s OR meta_value = %s) LIMIT 1",
					$url,
					$normalized_url
				));
				if ($meta_post) {
					return ['duplicate' => true, 'duplicate_of' => (int) $meta_post, 'reason' => 'published_source_url'];
				}
			}
		}

		$recent_post = $wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_epv2_semantic_hash' AND meta_value = %s LIMIT 1",
			$hashes['semantic_hash']
		));
		if ($recent_post) {
			return ['duplicate' => true, 'duplicate_of' => (int) $recent_post, 'reason' => 'published_semantic'];
		}

		$title_post = $wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_epv2_title_hash' AND meta_value = %s LIMIT 1",
			$hashes['title_hash']
		));
		if ($title_post) {
			return ['duplicate' => true, 'duplicate_of' => (int) $title_post, 'reason' => 'published_title_hash'];
		}

		return ['duplicate' => false];
	}

	public static function is_story_duplicate(array $item, array $cluster = []): array {
		global $wpdb;
		$cluster_id = (int) ($cluster['id'] ?? 0);
		if ($cluster_id > 0) {
			$post_id = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_epv2_cluster_id' AND meta_value = %s LIMIT 1",
				(string) $cluster_id
			));
			if ($post_id > 0) {
				return ['duplicate' => true, 'duplicate_of' => $post_id, 'reason' => 'published_cluster'];
			}
		}

		$topic = sanitize_text_field((string) ($cluster['topic_label'] ?? ''));
		$topic_key = self::canonical_topic_key($topic);
		$title = sanitize_text_field((string) ($item['title'] ?? ''));
		$categories = array_values(array_filter(array_map('trim', explode(',', sanitize_text_field((string) ($item['category'] ?? ''))))));
		$recent_posts = get_posts([
			'post_type' => 'post',
			'post_status' => 'publish',
			'posts_per_page' => 24,
			'date_query' => [
				[
					'after' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
					'inclusive' => true,
				],
			],
			'meta_query' => [
				[
					'key' => '_epv2_queue_id',
					'compare' => 'EXISTS',
				],
			],
			'fields' => 'ids',
			'ignore_sticky_posts' => true,
		]);
		foreach ($recent_posts as $post_id) {
			$post_id = (int) $post_id;
			$post_topic_key = self::canonical_topic_key((string) get_post_meta($post_id, '_epv2_topic_label', true));
			$post_title = (string) get_the_title($post_id);
			$post_categories = wp_get_post_categories($post_id, ['fields' => 'slugs']);
			if ($topic_key !== '' && $post_topic_key !== '' && $topic_key === $post_topic_key && self::titles_are_semantically_close($title, $post_title)) {
				return ['duplicate' => true, 'duplicate_of' => $post_id, 'reason' => 'published_topic_key'];
			}
			if (self::titles_are_semantically_close($title, $post_title) && self::categories_overlap($categories, is_array($post_categories) ? $post_categories : [])) {
				return ['duplicate' => true, 'duplicate_of' => $post_id, 'reason' => 'published_recent_semantic'];
			}
		}

		return ['duplicate' => false];
	}

	private static function canonical_topic_key(string $label): string {
		$label = mb_strtolower(trim($label));
		if ($label === '') {
			return '';
		}
		$map = [
			'krieg in der ukraine' => 'ukraine-war',
			'war in ukraine' => 'ukraine-war',
			'война в украине' => 'ukraine-war',
			'війна в україні' => 'ukraine-war',
			'iran and the middle east' => 'iran-middle-east',
			'iran und nahost' => 'iran-middle-east',
			'иран и ближний восток' => 'iran-middle-east',
			'іран і близький схід' => 'iran-middle-east',
			'fuel prices and energy' => 'fuel-energy',
			'spritpreise und energie' => 'fuel-energy',
			'цены на топливо и энергия' => 'fuel-energy',
			'ціни на пальне та енергію' => 'fuel-energy',
			'european politics' => 'european-politics',
			'europaeische politik' => 'european-politics',
			'европейская политика' => 'european-politics',
			'європейська політика' => 'european-politics',
			'benefits and the labour market' => 'benefits-labour',
			'leistungen und arbeitsmarkt' => 'benefits-labour',
			'выплаты и рынок труда' => 'benefits-labour',
			'виплати та ринок праці' => 'benefits-labour',
			'germany' => 'germany',
			'deutschland' => 'germany',
			'німеччина' => 'germany',
			'champions league' => 'uefa-cl',
			'uefa champions league' => 'uefa-cl',
			'liga chempioniv' => 'uefa-cl',
			'ліга чемпіонів' => 'uefa-cl',
			'europa league' => 'uefa-el',
			'liga yevropy' => 'uefa-el',
			'ліга європи' => 'uefa-el',
			'conference league' => 'uefa-ecl',
			'liga konferentsii' => 'uefa-ecl',
			'ліга конференцій' => 'uefa-ecl',
			'bundesliga' => 'bundesliga',
			'euroleague' => 'euroleague',
			'nba' => 'nba',
			'nhl' => 'nhl',
			'del' => 'del-hockey',
		];
		return $map[$label] ?? sanitize_title($label);
	}

	private static function titles_are_semantically_close(string $left, string $right): bool {
		$sports_left = self::sports_event_key($left);
		$sports_right = self::sports_event_key($right);
		if ($sports_left !== '' && $sports_right !== '' && $sports_left === $sports_right) {
			return true;
		}
		$left_tokens = self::title_tokens($left);
		$right_tokens = self::title_tokens($right);
		if ($left_tokens === [] || $right_tokens === []) {
			return false;
		}
		$overlap = count(array_intersect($left_tokens, $right_tokens));
		$minimum = max(2, (int) ceil(min(count($left_tokens), count($right_tokens)) * 0.45));
		return $overlap >= $minimum;
	}

	private static function sports_event_key(string $title): string {
		$title = mb_strtolower(html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$title = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $title) ?: $title;
		$title = preg_replace('/\s+/u', ' ', trim($title)) ?: trim($title);
		if ($title === '') {
			return '';
		}
		$tournament = '';
		foreach ([
			'champions league' => 'uefa-cl',
			'liga chempioniv' => 'uefa-cl',
			'ліга чемпіонів' => 'uefa-cl',
			'europa league' => 'uefa-el',
			'ліга європи' => 'uefa-el',
			'conference league' => 'uefa-ecl',
			'ліга конференцій' => 'uefa-ecl',
			'bundesliga' => 'bundesliga',
			'nba' => 'nba',
			'euroleague' => 'euroleague',
			'nhl' => 'nhl',
			'del' => 'del-hockey',
		] as $needle => $key) {
			if (str_contains($title, $needle)) {
				$tournament = $key;
				break;
			}
		}
		if ($tournament === '') {
			return '';
		}
		if (preg_match('/([a-zäöüß0-9]+(?:\s+[a-zäöüß0-9]+){0,2})\s+(gegen|vs|v)\s+([a-zäöüß0-9]+(?:\s+[a-zäöüß0-9]+){0,2})/u', $title, $m)) {
			$teams = [sanitize_title($m[1]), sanitize_title($m[3])];
			sort($teams);
			return $tournament . ':' . implode('-', $teams);
		}
		if (preg_match('/(auslosung|draw|жеребкування)/u', $title)) {
			return $tournament . ':draw';
		}
		return $tournament;
	}

	private static function title_tokens(string $title): array {
		$title = mb_strtolower(wp_strip_all_tags($title));
		$title = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $title) ?: $title;
		$stop = ['der','die','das','und','mit','von','für','fuer','des','dem','den','eine','einer','einem','ein','auch','sich','ist','sind','wird','werden','auf','im','in','an','am','zu','zum','zur','the','and','with','for','von','eine','news','ticker'];
		$tokens = [];
		foreach (preg_split('/\s+/u', trim($title)) ?: [] as $token) {
			$token = trim($token);
			if (mb_strlen($token) < 5 || in_array($token, $stop, true)) {
				continue;
			}
			$tokens[$token] = true;
		}
		return array_keys($tokens);
	}

	private static function categories_overlap(array $left, array $right): bool {
		$left = array_values(array_filter(array_map('sanitize_title', $left)));
		$right = array_values(array_filter(array_map('sanitize_title', $right)));
		return count(array_intersect($left, $right)) > 0;
	}

	private static function normalize(string $content): string {
		$content = wp_strip_all_tags($content);
		$content = preg_replace('/\s+/', ' ', $content);
		return mb_strtolower(trim((string) $content));
	}

	private static function keywords(string $text): string {
		$text = self::normalize($text);
		$words = array_filter(explode(' ', $text), static fn($word) => mb_strlen($word) > 3);
		sort($words);
		return implode('|', array_unique($words));
	}

	private static function normalize_url(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		$parts = wp_parse_url($url);
		if (! is_array($parts) || empty($parts['host'])) {
			return esc_url_raw($url);
		}
		$path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
		$query = '';
		if (! empty($parts['query'])) {
			parse_str((string) $parts['query'], $query_parts);
			ksort($query_parts);
			$query = http_build_query($query_parts);
		}
		return strtolower($parts['host']) . $path . ($query !== '' ? '?' . $query : '');
	}
}
