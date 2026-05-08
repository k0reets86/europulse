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
		$normalized_url = $url !== '' ? self::normalize_url($url) : '';
		$active_states = ['new', 'reserve', 'processing_de', 'retry_process', 'ready_review', 'ready_publish', 'publishing'];
		$active_placeholders = implode(',', array_fill(0, count($active_states), '%s'));
		$dup = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id
				FROM {$table}
				WHERE state IN ({$active_placeholders})
				  AND (title_hash = %s OR content_hash = %s)
				ORDER BY updated_at DESC
				LIMIT 1",
				...array_merge($active_states, [
					$hashes['title_hash'],
					$hashes['content_hash'],
				])
			)
		);
		if ($dup) {
			return ['duplicate' => true, 'duplicate_of' => (int) $dup->id, 'reason' => 'hash'];
		}

		$terminal_states = ['rejected', 'duplicate', 'error', 'manual_review', 'published'];
		$terminal_placeholders = implode(',', array_fill(0, count($terminal_states), '%s'));
		if ($url !== '' && $normalized_url !== '') {
			$terminal_dup = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT CASE WHEN duplicate_of IS NOT NULL AND duplicate_of > 0 THEN duplicate_of ELSE id END AS id
					FROM {$table}
					WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
					  AND state IN ({$terminal_placeholders})
					  AND (
						title_hash = %s
						OR content_hash = %s
						OR original_url IN (%s, %s)
						OR canonical_url IN (%s, %s)
					  )
					ORDER BY updated_at DESC
					LIMIT 1",
					...array_merge($terminal_states, [
						$hashes['title_hash'],
						$hashes['content_hash'],
						$url,
						$normalized_url,
						$url,
						$normalized_url,
					])
				)
			);
		} else {
			$terminal_dup = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT CASE WHEN duplicate_of IS NOT NULL AND duplicate_of > 0 THEN duplicate_of ELSE id END AS id
					FROM {$table}
					WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
					  AND state IN ({$terminal_placeholders})
					  AND (title_hash = %s OR content_hash = %s)
					ORDER BY updated_at DESC
					LIMIT 1",
					...array_merge($terminal_states, [
						$hashes['title_hash'],
						$hashes['content_hash'],
					])
				)
			);
		}
		if ($terminal_dup) {
			return ['duplicate' => true, 'duplicate_of' => (int) $terminal_dup->id, 'reason' => 'recent_terminal_exact'];
		}

		if ($url !== '') {
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
		$event_key = self::event_key_for_candidate($item, $cluster);
		$date_bucket = self::event_date_bucket((string) ($item['date'] ?? ''));
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

		if ($event_key !== '') {
			$event_posts = get_posts([
				'post_type' => 'post',
				'post_status' => 'publish',
				'posts_per_page' => 12,
				'date_query' => [
					[
						'after' => gmdate('Y-m-d H:i:s', time() - (72 * HOUR_IN_SECONDS)),
						'inclusive' => true,
					],
				],
				'meta_query' => [
					[
						'key' => '_epv2_event_key',
						'value' => $event_key,
					],
				],
				'fields' => 'ids',
				'ignore_sticky_posts' => true,
			]);
			foreach ($event_posts as $post_id) {
				$post_id = (int) $post_id;
				$post_title = (string) get_the_title($post_id);
				$post_excerpt = (string) get_post_field('post_excerpt', $post_id);
				$post_content = (string) get_post_field('post_content', $post_id);
				if (! self::material_delta_exists($item, [
					'title' => $post_title,
					'excerpt' => $post_excerpt,
					'content' => $post_content,
					'event_key' => $event_key,
				])) {
					return ['duplicate' => true, 'duplicate_of' => $post_id, 'reason' => 'published_event_key'];
				}
			}

			$queue_table = $wpdb->prefix . 'epv2_queue';
			$recent_rows = $wpdb->get_results(
				"SELECT id, state, original_title, original_excerpt, original_content, category_proposed, topic_label, created_at, admin_notes
				FROM {$queue_table}
				WHERE created_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
				  AND state NOT IN ('duplicate','rejected','error','manual_review')
				ORDER BY created_at DESC
				LIMIT 40"
			);
			foreach ((array) $recent_rows as $row) {
				$row_id = (int) ($row->id ?? 0);
				$existing_event_key = self::event_key_from_row($row);
				if ($existing_event_key === '' || $existing_event_key !== $event_key) {
					continue;
				}
				$row_bucket = self::event_date_bucket((string) ($row->created_at ?? ''));
				if ($date_bucket !== '' && $row_bucket !== '' && $date_bucket !== $row_bucket) {
					continue;
				}
				if (! self::material_delta_exists($item, [
					'title' => (string) ($row->original_title ?? ''),
					'excerpt' => (string) ($row->original_excerpt ?? ''),
					'content' => (string) ($row->original_content ?? ''),
					'event_key' => $existing_event_key,
				])) {
					return ['duplicate' => true, 'duplicate_of' => $row_id, 'reason' => 'queue_event_key'];
				}
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
					'after' => gmdate('Y-m-d H:i:s', time() - (72 * HOUR_IN_SECONDS)),
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
		$recent_post_ids = array_values(array_filter(array_map('intval', (array) $recent_posts)));
		if ($recent_post_ids !== []) {
			// Prime the post / meta / term caches once instead of paying
			// three cache misses per iteration (get_post_meta + get_the_title
			// + wp_get_post_categories = 3 separate lookups per post).
			update_post_caches(get_posts([
				'post_type' => 'post',
				'post__in' => $recent_post_ids,
				'posts_per_page' => count($recent_post_ids),
				'orderby' => 'post__in',
				'no_found_rows' => true,
				'ignore_sticky_posts' => true,
			]), 'post', true, true);
		}
		foreach ($recent_post_ids as $post_id) {
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

		// === AI fingerprint check — 24 h window, final fallback for semantic near-misses ===
		// The fingerprint is injected by EPV2_Collector::ingest_candidate() as $item['story_fingerprint'].
		// If not present (e.g. AI unavailable at collection time) the check is simply skipped.
		$candidate_fp = is_array($item['story_fingerprint'] ?? null) ? (array) $item['story_fingerprint'] : null;
		if (is_array($candidate_fp)) {
			// Check against published posts with a stored AI fingerprint (last 24 h).
			$fp_post_ids = get_posts([
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 25,
				'date_query'          => [
					[
						'after'     => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
						'inclusive' => true,
					],
				],
				'meta_query'          => [
					[
						'key'     => '_epv2_story_fingerprint',
						'compare' => 'EXISTS',
					],
				],
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
			]);
			$fp_post_ids = array_values(array_filter(array_map('intval', (array) $fp_post_ids)));
			if ($fp_post_ids !== []) {
				// Single SELECT instead of N get_post_meta calls.
				update_meta_cache('post', $fp_post_ids);
			}
			foreach ($fp_post_ids as $fp_pid) {
				$raw     = get_post_meta($fp_pid, '_epv2_story_fingerprint', true);
				$post_fp = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
				if (! is_array($post_fp)) {
					continue;
				}
				if (self::fingerprints_are_same_story($candidate_fp, $post_fp)) {
					return ['duplicate' => true, 'duplicate_of' => $fp_pid, 'reason' => 'published_ai_fingerprint'];
				}
			}
			// Check against queue items with stored fingerprints (last 24 h).
			$fp_rows = $wpdb->get_results(
				"SELECT id, admin_notes
				FROM {$wpdb->prefix}epv2_queue
				WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
				  AND state NOT IN ('duplicate','rejected','error','manual_review')
				  AND JSON_EXTRACT(admin_notes, '$._system.story_fingerprint') IS NOT NULL
				ORDER BY created_at DESC
				LIMIT 30"
			);
			foreach ((array) $fp_rows as $fp_row) {
				if (! ($fp_row instanceof stdClass)) {
					continue;
				}
				$fp_notes = json_decode((string) ($fp_row->admin_notes ?? ''), true);
				$row_fp   = is_array($fp_notes['_system']['story_fingerprint'] ?? null)
					? (array) $fp_notes['_system']['story_fingerprint']
					: null;
				if (! is_array($row_fp)) {
					continue;
				}
				if (self::fingerprints_are_same_story($candidate_fp, $row_fp)) {
					return ['duplicate' => true, 'duplicate_of' => (int) $fp_row->id, 'reason' => 'queue_ai_fingerprint'];
				}
			}
		}

		return ['duplicate' => false, 'event_key' => $event_key];
	}

	public static function event_key_for_candidate(array $item, array $cluster = []): string {
		$title = (string) ($item['title'] ?? '');
		$excerpt = (string) ($item['excerpt'] ?? '');
		$content = (string) ($item['content'] ?? '');
		$category = (string) ($item['category'] ?? '');
		$topic = (string) ($cluster['topic_label'] ?? '');
		return self::event_key($title, $excerpt, $content, $topic, $category, (string) ($item['date'] ?? ''));
	}

	private static function event_key_from_row(object $row): string {
		$notes = json_decode((string) ($row->admin_notes ?? ''), true);
		$stored = is_array($notes) ? (string) ($notes['event_key'] ?? '') : '';
		if ($stored !== '') {
			return sanitize_title($stored);
		}
		return self::event_key(
			(string) ($row->original_title ?? ''),
			(string) ($row->original_excerpt ?? ''),
			(string) ($row->original_content ?? ''),
			(string) ($row->topic_label ?? ''),
			(string) ($row->category_proposed ?? ''),
			(string) ($row->created_at ?? '')
		);
	}

	private static function event_key(string $title, string $excerpt, string $content, string $topic, string $category, string $datetime): string {
		$topic_key = self::canonical_topic_key($topic !== '' ? $topic : self::derive_topic_key_from_text($title . ' ' . $excerpt . ' ' . $content, $category));
		$summary = trim($title . ' ' . $excerpt);
		$tokens = self::event_tokens($summary);
		if (count($tokens) < 2) {
			$tokens = array_values(array_unique(array_merge($tokens, self::event_tokens($content))));
		}
		$essential = array_values(array_intersect($tokens, ['ceasefire', 'reject', 'talks', 'attack', 'criticizes', 'warns', 'vote', 'closure']));
		if ($essential !== []) {
			$tokens = $essential;
		}
		if ($topic_key === '' && $tokens === []) {
			return '';
		}
		$parts = array_values(array_filter(array_merge([$topic_key], array_slice($tokens, 0, 2))));
		return sanitize_title(implode('|', $parts));
	}

	private static function event_date_bucket(string $datetime): string {
		$datetime = trim($datetime);
		if ($datetime === '') {
			return gmdate('Y-m-d');
		}
		$ts = strtotime($datetime);
		if ($ts === false) {
			return gmdate('Y-m-d');
		}
		return gmdate('Y-m-d', $ts);
	}

	private static function derive_topic_key_from_text(string $text, string $category): string {
		$text = mb_strtolower(wp_strip_all_tags($text));
		$map = [
			'iran-middle-east' => ['iran', 'teheran', 'waffenruhe', 'ceasefire', 'nahost', 'middle east', 'israel', 'libanon', 'lebanon'],
			'ukraine-war' => ['ukraine', 'ukrain', 'kyiv', 'kiew', 'front', 'russland', 'russia'],
			'transport' => ['mvg', 'mvv', 's-bahn', 'deutsche bahn', 'u-bahn', 'sperrung', 'umleitung', 'baustelle'],
			'germany-politics' => ['bundestag', 'bundesregierung', 'merz', 'kanzler', 'koalition', 'gesetz'],
			'economy' => ['haushalt', 'inflation', 'wirtschaft', 'konjunktur', 'börse', 'boerse', 'finanz'],
		];
		foreach ($map as $key => $needles) {
			foreach ($needles as $needle) {
				if (str_contains($text, $needle)) {
					return $key;
				}
			}
		}
		return sanitize_title($category);
	}

	private static function event_tokens(string $text): array {
		$text = mb_strtolower(wp_strip_all_tags($text));
		$text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: $text;
		$text = preg_replace('/\s+/u', ' ', trim($text)) ?: trim($text);
		if ($text === '') {
			return [];
		}
		$priority = [];
		$phrase_map = [
			'ceasefire' => '/\b(waffenruhe|waffenstillstand|ceasefire)\b/u',
			'reject' => '/\b(dementiert|bestreitet|weist(?:.+)?zur[uü]ck|lehnt(?:.+)?ab|rejects?|denies?)\b/u',
			'talks' => '/\b(gespr[aä]che|gespr[aä]ch|verhandlungen|talks?|negotiations?)\b/u',
			'attack' => '/\b(angriff|angriffe|attack|attacks|strike|strikes)\b/u',
			'criticizes' => '/\b(kritisiert|criticizes|criticises)\b/u',
			'us' => '/\b(usa|us|united states|amerika)\b/u',
			'iran' => '/\b(iran|teheran)\b/u',
			'israel' => '/\b(israel)\b/u',
			'trump' => '/\b(trump)\b/u',
			'reeves' => '/\b(reeves)\b/u',
		];
		foreach ($phrase_map as $label => $pattern) {
			if (preg_match($pattern, $text) === 1) {
				$priority[$label] = true;
			}
		}
		$raw_tokens = preg_split('/\s+/u', $text) ?: [];
		$stop = [
			'iran-liveblog','liveblog','ticker','liveticker','news','aktuelle','aktueller','updates','live','mehr','heute',
			'der','die','das','und','mit','von','für','fuer','eine','einer','einem','einen','zum','zur','des','dem','den',
			'the','and','with','from','amid','after','before','that','this',
		];
		$synonyms = [
			'waffenruhe' => 'ceasefire',
			'waffenstillstand' => 'ceasefire',
			'ceasefire' => 'ceasefire',
			'gesuch' => 'request',
			'bitte' => 'request',
			'vorschlag' => 'proposal',
			'dementiert' => 'reject',
			'bestreitet' => 'reject',
			'weist' => 'reject',
			'zurück' => 'reject',
			'ablehnt' => 'reject',
			'deny' => 'reject',
			'denies' => 'reject',
			'rejects' => 'reject',
			'trump' => 'trump',
			'usa' => 'us',
			'us' => 'us',
			'israel' => 'israel',
			'iran' => 'iran',
			'teheran' => 'iran',
			'angriff' => 'attack',
			'angriffe' => 'attack',
			'attack' => 'attack',
			'attacks' => 'attack',
			'gespräche' => 'talks',
			'gespraeche' => 'talks',
			'gespräch' => 'talks',
			'gespraech' => 'talks',
			'talks' => 'talks',
			'verhandlungen' => 'talks',
			'warnt' => 'warns',
			'warning' => 'warns',
			'kritisiert' => 'criticizes',
			'criticizes' => 'criticizes',
			'criticises' => 'criticizes',
		];
		$weights = [];
		foreach ($raw_tokens as $token) {
			$token = trim((string) $token);
			if ($token === '' || mb_strlen($token) < 3) {
				continue;
			}
			$token = $synonyms[$token] ?? $token;
			if (in_array($token, $stop, true)) {
				continue;
			}
			$weights[$token] = ($weights[$token] ?? 0) + 1;
		}
		arsort($weights);
		$tokens = array_values(array_keys(array_slice($weights, 0, 6, true)));
		return array_values(array_unique(array_merge(array_keys($priority), $tokens)));
	}

	private static function material_delta_exists(array $item, array $existing): bool {
		$event_key = sanitize_title((string) ($existing['event_key'] ?? ''));
		if ($event_key === '') {
			return true;
		}
		$candidate_text = trim((string) ($item['title'] ?? '') . ' ' . (string) ($item['excerpt'] ?? '') . ' ' . wp_strip_all_tags((string) ($item['content'] ?? '')));
		$existing_text = trim((string) ($existing['title'] ?? '') . ' ' . (string) ($existing['excerpt'] ?? '') . ' ' . wp_strip_all_tags((string) ($existing['content'] ?? '')));
		if ($candidate_text === '' || $existing_text === '') {
			return false;
		}
		$candidate_tokens = self::event_tokens($candidate_text);
		$existing_tokens = self::event_tokens($existing_text);
		$shared = count(array_intersect($candidate_tokens, $existing_tokens));
		$baseline = max(1, min(count($candidate_tokens), count($existing_tokens)));
		$ratio = $shared / $baseline;
		if ($ratio >= 0.75) {
			return false;
		}
		if (self::titles_are_semantically_close((string) ($item['title'] ?? ''), (string) ($existing['title'] ?? ''))) {
			return false;
		}
		return true;
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

	// =========================================================================
	// AI story fingerprint — extraction and comparison
	// =========================================================================

	/**
	 * Call a lightweight AI model to extract a structured story fingerprint from
	 * a news article's title and body. Used for semantic deduplication at ingestion
	 * time. Returns null on any failure — callers always treat null as "no fingerprint".
	 */
	public static function extract_story_fingerprint(string $title, string $content): ?array {
		$config = EPV2_Settings::get_ai_config();
		if (empty($config['api_key'])) {
			return null;
		}
		$config['temperature'] = 0.1;
		$config['max_tokens']  = 150;
		$config['timeout']     = 10;
		$snippet = mb_substr(trim(wp_strip_all_tags($title . '. ' . $content)), 0, 500);
		if ($snippet === '') {
			return null;
		}
		try {
			$result = EPV2_AI_Client::generate($config, [
				[
					'role'    => 'system',
					'content' => 'Extract a news event fingerprint. Return ONLY valid JSON with exactly these keys: {"type":"<one of: sport_match|sport_violence|sport_transfer|politics|crime|accident|protest|economy|culture|other>","actors":["<entity>"],"location":"<city_or_country>","action":"<key_noun_or_verb>"}. All values lowercase. Max 3 actors. Empty string for unknown values. No markdown, no explanation.',
				],
				[
					'role'    => 'user',
					'content' => $snippet,
				],
			]);
			$text = trim((string) ($result['text'] ?? ''));
			// Strip markdown code fences some providers add.
			$text = (string) preg_replace('/^```(?:json)?\s*/i', '', $text);
			$text = rtrim((string) preg_replace('/\s*```$/i', '', $text));
			$fp   = json_decode(trim($text), true);
			if (! is_array($fp) || ($fp['type'] ?? '') === '') {
				return null;
			}
			return [
				'type'     => mb_strtolower((string) ($fp['type'] ?? 'other')),
				'actors'   => array_values(array_slice(array_filter(array_map('strval', (array) ($fp['actors'] ?? []))), 0, 3)),
				'location' => mb_strtolower(trim((string) ($fp['location'] ?? ''))),
				'action'   => mb_strtolower(trim((string) ($fp['action'] ?? ''))),
			];
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * Compare two story fingerprints. Returns true when both fingerprints describe
	 * the same real-world event. Uses conservative rules to avoid false positives.
	 */
	private static function fingerprints_are_same_story(array $fp1, array $fp2): bool {
		$t1 = $fp1['type'] ?? '';
		$t2 = $fp2['type'] ?? '';
		// Both must carry a specific type and they must match.
		if ($t1 === '' || $t2 === '' || $t1 === 'other' || $t2 === 'other' || $t1 !== $t2) {
			return false;
		}
		// Normalise actors to lowercase.
		$a1 = array_filter(array_map('mb_strtolower', (array) ($fp1['actors'] ?? [])));
		$a2 = array_filter(array_map('mb_strtolower', (array) ($fp2['actors'] ?? [])));
		// Actor overlap: names match when one string contains the other (handles
		// abbreviations like "FCN" vs "1. FC Nürnberg").
		$actor_overlap = 0;
		foreach ($a1 as $x) {
			foreach ($a2 as $y) {
				if (
					$x === $y
					|| (mb_strlen($x) >= 4 && mb_strlen($y) >= 4 && (str_contains($x, $y) || str_contains($y, $x)))
				) {
					$actor_overlap++;
					break;
				}
			}
		}
		// Location match (partial containment for "Munich" vs "München area").
		$loc1      = $fp1['location'] ?? '';
		$loc2      = $fp2['location'] ?? '';
		$loc_match = $loc1 !== '' && $loc2 !== '' && (
			$loc1 === $loc2 || str_contains($loc1, $loc2) || str_contains($loc2, $loc1)
		);
		// Action similarity — also handles German compound words (suffix match).
		$act1         = $fp1['action'] ?? '';
		$act2         = $fp2['action'] ?? '';
		$action_close = $act1 !== '' && $act2 !== '' && (
			$act1 === $act2
			|| (mb_strlen($act1) >= 4 && str_ends_with($act2, $act1))
			|| (mb_strlen($act2) >= 4 && str_ends_with($act1, $act2))
		);
		// Decision rules (most → least strict):
		if ($actor_overlap >= 2) {
			return true; // 2+ shared actors → strong match
		}
		if ($actor_overlap >= 1 && ($loc_match || $action_close)) {
			return true; // 1 actor + location or action → medium match
		}
		if ($loc_match && $action_close) {
			return true; // same place + same action, no named actors → cautious match
		}
		return false;
	}

	// =========================================================================

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
		// German compound word suffix matching: 'massenschlägerei' contains 'schlägerei',
		// so if one token (len >= 6) is a suffix of a longer token, count it as overlap.
		if ($overlap < $minimum) {
			$compound_matched_left  = [];
			$compound_matched_right = [];
			foreach ($left_tokens as $lt) {
				if (isset($compound_matched_left[$lt])) {
					continue;
				}
				foreach ($right_tokens as $rt) {
					if ($lt === $rt || isset($compound_matched_right[$rt])) {
						continue;
					}
					$lt_len = mb_strlen($lt);
					$rt_len = mb_strlen($rt);
					if (
						($lt_len > $rt_len && $rt_len >= 6 && str_ends_with($lt, $rt)) ||
						($rt_len > $lt_len && $lt_len >= 6 && str_ends_with($rt, $lt))
					) {
						$overlap++;
						$compound_matched_left[$lt]  = true;
						$compound_matched_right[$rt] = true;
						break;
					}
				}
			}
		}
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
