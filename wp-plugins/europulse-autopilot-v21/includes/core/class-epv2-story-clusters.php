<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Story_Clusters {
	public static function register_candidate(array $item, ?object $source = null): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_clusters';
		$key = self::cluster_key((string) ($item['title'] ?? ''), (string) ($item['excerpt'] ?? ''), (string) ($item['category'] ?? ($source->category_bias ?? '')));
		$title = sanitize_text_field((string) ($item['title'] ?? ''));
		$category = sanitize_text_field((string) ($item['category'] ?? ($source->category_bias ?? '')));
		$lang = sanitize_text_field((string) ($source->language ?? 'de'));
		$topic_label = self::derive_topic_label((string) ($item['title'] ?? ''), (string) ($item['excerpt'] ?? ''), $category);
		$existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE cluster_key = %s LIMIT 1", $key), ARRAY_A);

		if ($existing) {
			$wpdb->update(
				$table,
				[
					'title_seed' => $existing['title_seed'] !== '' ? $existing['title_seed'] : $title,
					'topic_label' => $existing['topic_label'] !== '' ? $existing['topic_label'] : $topic_label,
					'primary_category' => $existing['primary_category'] !== '' ? $existing['primary_category'] : $category,
					'language_hint' => $existing['language_hint'] !== '' ? $existing['language_hint'] : $lang,
					'last_seen_at' => current_time('mysql'),
				],
				['id' => (int) $existing['id']]
			);
			$cluster_id = (int) $existing['id'];
		} else {
			$wpdb->insert(
				$table,
				[
						'cluster_key' => $key,
						'title_seed' => $title,
						'topic_label' => $topic_label,
					'primary_category' => $category,
					'language_hint' => $lang,
					'first_seen_at' => current_time('mysql'),
					'last_seen_at' => current_time('mysql'),
				]
			);
			$cluster_id = (int) $wpdb->insert_id;
		}

		return self::refresh_cluster_metrics($cluster_id);
	}

	public static function refresh_cluster_metrics(int $cluster_id): array {
		global $wpdb;
		$cluster_table = $wpdb->prefix . 'epv2_clusters';
		$queue_table = $wpdb->prefix . 'epv2_queue';
		$mentions_24h = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$queue_table} WHERE cluster_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
			$cluster_id
		));
		$mentions_7d = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$queue_table} WHERE cluster_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
			$cluster_id
		));
		$source_count_24h = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT source_id) FROM {$queue_table} WHERE cluster_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
			$cluster_id
		));
		$source_count_7d = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT source_id) FROM {$queue_table} WHERE cluster_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
			$cluster_id
		));
		$developing = $mentions_24h >= 3 && $source_count_24h >= 2;
		$analysis = $mentions_7d >= 5 && $source_count_7d >= 3;

		$wpdb->update(
			$cluster_table,
			[
				'mentions_24h' => $mentions_24h,
				'mentions_7d' => $mentions_7d,
				'source_count_24h' => $source_count_24h,
				'source_count_7d' => $source_count_7d,
				'developing_candidate' => $developing ? 1 : 0,
				'analysis_candidate' => $analysis ? 1 : 0,
				'last_seen_at' => current_time('mysql'),
			],
			['id' => $cluster_id]
		);

		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$cluster_table} WHERE id = %d LIMIT 1", $cluster_id), ARRAY_A);
		return is_array($row) ? $row : [];
	}

	public static function create_story_candidates(): int {
		global $wpdb;
		$cluster_table = $wpdb->prefix . 'epv2_clusters';
		$created = 0;
		$clusters = $wpdb->get_results(
			"SELECT * FROM {$cluster_table} WHERE developing_candidate = 1 OR analysis_candidate = 1 ORDER BY analysis_candidate DESC, developing_candidate DESC, mentions_24h DESC, mentions_7d DESC LIMIT 20",
			ARRAY_A
		);

		foreach ($clusters as $cluster) {
			$cluster_id = (int) $cluster['id'];
			if (! empty($cluster['developing_candidate']) && ! self::has_recent_story_item($cluster_id, 'developing', 12)) {
				if (self::queue_story_item($cluster, 'developing')) {
					$created++;
					$wpdb->update($cluster_table, ['last_promoted_developing' => current_time('mysql')], ['id' => $cluster_id]);
				}
			}
			if (! empty($cluster['analysis_candidate']) && self::is_analysis_window() && ! self::has_recent_story_item($cluster_id, 'analysis', 120)) {
				if (self::queue_story_item($cluster, 'analysis')) {
					$created++;
					$wpdb->update($cluster_table, ['last_promoted_analysis' => current_time('mysql')], ['id' => $cluster_id]);
				}
			}
		}

		return $created;
	}

	private static function queue_story_item(array $cluster, string $format): bool {
		$cluster_id = (int) ($cluster['id'] ?? 0);
		if ($cluster_id <= 0) {
			return false;
		}
		$summary = self::cluster_summary($cluster_id, $format);
		if ($summary['content'] === '') {
			return false;
		}
		$category = sanitize_text_field((string) ($cluster['primary_category'] ?? 'deutschland'));
		$item_id = EPV2_Queue::add_item([
			'source_id' => 0,
			'url' => '',
			'canonical_url' => '',
			'title' => $summary['title'],
			'excerpt' => $summary['excerpt'],
			'content' => $summary['content'],
			'date' => current_time('mysql'),
			'author' => 'EuroPulse AutoPilot',
			'image' => '',
			'category' => $category,
			'cluster_id' => $cluster_id,
			'story_format' => $format,
			'topic_label' => (string) ($cluster['topic_label'] ?? ''),
			'story_score' => $format === 'analysis' ? 88 : 76,
			'admin_notes' => wp_json_encode([
				'cluster' => [
					'id' => $cluster_id,
					'format' => $format,
					'topic_label' => (string) ($cluster['topic_label'] ?? ''),
					'mentions_24h' => (int) ($cluster['mentions_24h'] ?? 0),
					'mentions_7d' => (int) ($cluster['mentions_7d'] ?? 0),
					'source_count_24h' => (int) ($cluster['source_count_24h'] ?? 0),
					'source_count_7d' => (int) ($cluster['source_count_7d'] ?? 0),
					'title_seed' => (string) ($cluster['title_seed'] ?? ''),
				],
			], JSON_UNESCAPED_UNICODE),
		]);

		return $item_id > 0;
	}

	private static function cluster_summary(int $cluster_id, string $format): array {
		global $wpdb;
		$queue_table = $wpdb->prefix . 'epv2_queue';
		$cluster_table = $wpdb->prefix . 'epv2_clusters';
		$sources_table = $wpdb->prefix . 'epv2_sources';
		$cluster = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$cluster_table} WHERE id = %d LIMIT 1",
			$cluster_id
		), ARRAY_A);
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT q.original_title, q.original_excerpt, q.original_content, q.original_url, q.created_at, q.category_proposed, s.name AS source_name
			FROM {$queue_table} q
			LEFT JOIN {$sources_table} s ON s.id = q.source_id
			WHERE q.cluster_id = %d AND q.state NOT IN ('duplicate','rejected')
			ORDER BY q.created_at DESC
			LIMIT 8",
			$cluster_id
		), ARRAY_A);
		if (! $rows) {
			return ['title' => '', 'excerpt' => '', 'content' => ''];
		}
		$topic_label = sanitize_text_field((string) ($cluster['topic_label'] ?? ''));
		$title_seed = sanitize_text_field((string) ($cluster['title_seed'] ?? ($rows[0]['original_title'] ?? '')));
		$category = sanitize_text_field((string) ($cluster['primary_category'] ?? ($rows[0]['category_proposed'] ?? 'deutschland')));
		$bullets = [];
		$sources = [];
		foreach ($rows as $row) {
			$when = mysql2date('d.m H:i', (string) ($row['created_at'] ?? ''), false);
			$title = sanitize_text_field((string) ($row['original_title'] ?? ''));
			$excerpt = sanitize_text_field((string) ($row['original_excerpt'] ?? ''));
			$url = esc_url_raw((string) ($row['original_url'] ?? ''));
			$source_name = sanitize_text_field((string) ($row['source_name'] ?? 'Источник'));
			if ($title !== '') {
				$bullets[] = trim(($when !== '' ? $when . ' — ' : '') . $title . ($excerpt !== '' ? ': ' . $excerpt : ''));
			}
			if ($url !== '' && count($sources) < 5) {
				$sources[$url] = [
					'label' => $source_name !== '' ? $source_name : parse_url($url, PHP_URL_HOST),
					'url' => $url,
				];
			}
		}
		$source_lines = [];
		foreach (array_values($sources) as $source) {
			$source_lines[] = '- ' . $source['label'] . ': ' . $source['url'];
		}
		$topic_title = $topic_label !== '' ? $topic_label : $title_seed;
		if ($format === 'analysis') {
			$intro = 'Это аналитическая заготовка по устойчивой теме недели. Используй не менее 4-5 подтвержденных источников из списка ниже, собери развитие сюжета, выдели главное, покажи контекст, последствия и что может быть дальше.';
			$excerpt = 'Большая аналитика по теме недели с контекстом, развитием и практическим значением для читателя.';
			$title = 'Analyse: ' . $topic_title;
		} else {
			$intro = 'Это развивающаяся тема. Ниже собраны подтвержденные сигналы и обновления, чтобы на их основе подготовить material формата developing story.';
			$excerpt = 'Сюжет развивается: есть несколько подтвержденных обновлений и растущая важность темы.';
			$title = 'Developing Story: ' . $topic_title;
		}
		$content_parts = [
			$intro,
			'Тема: ' . $topic_title,
			'Категория публикации: ' . $category,
			'Ключевые обновления:',
			implode("\n", array_map(static fn($line) => '- ' . $line, array_slice($bullets, 0, 8))),
		];
		if ($source_lines !== []) {
			$content_parts[] = 'Подтвержденные источники и сигналы:';
			$content_parts[] = implode("\n", $source_lines);
		}
		$content = trim(implode("\n\n", array_filter($content_parts)));
		return [
			'title' => $title,
			'excerpt' => $excerpt,
			'content' => $content,
		];
	}

	private static function has_recent_story_item(int $cluster_id, string $format, int $hours): bool {
		global $wpdb;
		$queue_table = $wpdb->prefix . 'epv2_queue';
		$count = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$queue_table} WHERE cluster_id = %d AND story_format = %s AND created_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)",
			$cluster_id,
			$format,
			$hours
		));
		return $count > 0;
	}

	private static function is_analysis_window(): bool {
		return (int) gmdate('N') === 7;
	}

	private static function cluster_key(string $title, string $excerpt, string $category): string {
		$text = mb_strtolower(trim(wp_strip_all_tags($title . ' ' . $excerpt . ' ' . $category)));
		$text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: $text;
		$text = preg_replace('/\b\d+\b/u', ' ', $text) ?: $text;
		$text = preg_replace('/\s+/u', ' ', $text) ?: $text;
		$stopwords = [
			'der','die','das','und','mit','für','von','dem','den','des','ein','eine','einer','auf','bei','nach',
			'über','wie','sich','mehr','noch','alle','zur','zum','vom','ist','sind','will','wollen',
			'the','and','for','with','from','that','this','into','more','will','than','over',
			'про','для','після','перед','понад','через','також','його','її','вони','йде','буде',
		];
		$words = preg_split('/\s+/u', $text) ?: [];
		$keywords = [];
		foreach ($words as $word) {
			$word = trim($word);
			if ($word === '' || mb_strlen($word) < 5 || in_array($word, $stopwords, true)) {
				continue;
			}
			$prefix = mb_substr($word, 0, min(7, mb_strlen($word)));
			$keywords[$prefix] = ($keywords[$prefix] ?? 0) + 1;
		}
		arsort($keywords);
		$selected = array_keys(array_slice($keywords, 0, 6, true));
			sort($selected);
			return sha1($category . '|' . implode('|', $selected));
	}

	private static function derive_topic_label(string $title, string $excerpt, string $category): string {
		$text = mb_strtolower(trim(wp_strip_all_tags($title . ' ' . $excerpt)));
		$topic_map = [
			'War in Ukraine' => ['ukraine', 'ukrain', 'київ', 'киев', 'kyiv', 'zelensky', 'selenskyj', 'russland', 'russia', 'front'],
			'Iran and the Middle East' => ['iran', 'teheran', 'israel', 'gaza', 'nahost', 'middle east', 'hamas'],
			'Fuel Prices and Energy' => ['öl', 'oil', 'gas', 'energie', 'kraftstoff', 'benzin', 'diesel', 'fuel'],
			'Transport and Mobility' => ['mvg', 'mvv', 's-bahn', 'deutsche bahn', 'db', 'bahn', 'bus', 'tram', 'u-bahn', 'verkehr', 'baustelle', 'sperrung', 'umleitung', 'betriebslage'],
			'Migration and Residency' => ['bamf', 'aufenthalt', 'asyl', 'schutzstatus', 'migration', 'migrat', 'visa', 'visum'],
			'Benefits and the Labour Market' => ['bürgergeld', 'jobcenter', 'arbeitsagentur', 'arbeit', 'job', 'salary', 'lohn', 'рынок труда'],
			'Housing and Rent' => ['miete', 'wohn', 'housing', 'rent', 'аренда', 'жиль'],
			'Education and Children' => ['schule', 'bildung', 'kita', 'kinder', 'дет', 'school'],
			'European Politics' => ['eu', 'europa', 'brüssel', 'brussels', 'europarlament', 'kommission'],
		];
		foreach ($topic_map as $label => $terms) {
			foreach ($terms as $term) {
				if (str_contains($text, $term)) {
					return $label;
				}
			}
		}

		$words = preg_split('/\s+/u', $text) ?: [];
		$stopwords = [
			'der','die','das','und','mit','für','von','dem','den','des','ein','eine','auf','nach','über','zum','zur',
			'the','and','for','from','with','this','that','into','over',
			'про','для','після','перед','понад','через','його','її','вони','також','что','это','как','для',
		];
		$keywords = [];
		foreach ($words as $word) {
			$word = trim((string) $word);
			if ($word === '' || mb_strlen($word) < 5 || in_array($word, $stopwords, true)) {
				continue;
			}
			$keywords[$word] = ($keywords[$word] ?? 0) + 1;
		}
		arsort($keywords);
		$top = array_slice(array_keys($keywords), 0, 2);
		if ($top !== []) {
			$label = implode(' / ', array_map(static function (string $word): string {
				return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
			}, $top));
			return sanitize_text_field($label);
		}

		$fallback = self::category_fallback_label($category);
		return $fallback !== '' ? $fallback : 'Topic of the Week';
	}

	private static function category_fallback_label(string $category): string {
		return match ($category) {
			'deutschland' => 'Germany',
			'bayern' => 'Bavaria',
			'münchen' => 'Munich',
			'ukraine' => 'Ukraine',
			'europa' => 'Europe',
			'world' => 'World',
			'politik' => 'Politics',
			'wirtschaft' => 'Economy',
			'leben-in-deutschland' => 'Life in Germany',
			'community' => 'Community',
			'kultur' => 'Culture',
			'sport' => 'Sport',
			default => '',
		};
	}
}
