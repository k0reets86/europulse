<?php

if (! defined('ABSPATH')) {
	exit("Run via wp eval-file.\n");
}

function epv2_test_fetch_source(int $id): ?object {
	global $wpdb;
	return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}epv2_sources WHERE id = %d", $id));
}

function epv2_test_media_query(string $category, string $title): string {
	$map = [
		'deutschland' => 'Germany migration school housing',
		'münchen' => 'Munich train station traffic',
		'bayern' => 'Bavaria railway infrastructure',
		'ukraine' => 'Ukraine Europe news',
		'europa' => 'European Parliament Brussels',
		'politik' => 'Bundestag Berlin Germany',
		'wirtschaft' => 'Germany economy industry',
		'leben-in-deutschland' => 'Germany integration course office',
		'kultur' => 'Munich concert exhibition',
		'sport' => 'Bayern Munich football',
		'community' => 'Munich volunteering community event',
	];
	return $map[$category] ?? $title;
}

function epv2_test_pexels_search(string $query, int $count = 2): array {
	$key = (string) (EPV2_Settings::get('image_keys', [])['pexels'] ?? '');
	if ($key === '' || $query === '') {
		return [];
	}
	$response = wp_remote_get(
		'https://api.pexels.com/v1/search?' . http_build_query([
			'query' => $query,
			'per_page' => max(1, min(5, $count)),
			'orientation' => 'landscape',
		]),
		[
			'timeout' => 30,
			'headers' => [
				'Authorization' => $key,
			],
		]
	);
	if (is_wp_error($response)) {
		return [];
	}
	$code = (int) wp_remote_retrieve_response_code($response);
	$data = json_decode((string) wp_remote_retrieve_body($response), true);
	if ($code < 200 || $code >= 300 || ! is_array($data)) {
		return [];
	}
	$photos = [];
	foreach ((array) ($data['photos'] ?? []) as $photo) {
		$url = esc_url_raw((string) ($photo['src']['large2x'] ?? $photo['src']['large'] ?? $photo['src']['original'] ?? ''));
		if ($url !== '') {
			$photos[] = $url;
		}
	}
	return array_values(array_unique($photos));
}

function epv2_test_is_news_google(string $url): bool {
	$host = (string) parse_url($url, PHP_URL_HOST);
	return str_contains($host, 'news.google.');
}

function epv2_test_enrich_item(array $item): array {
	$url = (string) ($item['url'] ?? '');
	if ($url === '' || epv2_test_is_news_google($url)) {
		return $item;
	}
	try {
		$doc = EPV2_HTML_Reader::fetch_document($url);
		if (! empty($doc['title'])) {
			$item['title'] = (string) $doc['title'];
		}
		if (! empty($doc['excerpt'])) {
			$item['excerpt'] = (string) $doc['excerpt'];
		}
		if (! empty($doc['content'])) {
			$item['content'] = (string) $doc['content'];
		}
		if (! empty($doc['image'])) {
			$item['image'] = (string) $doc['image'];
		}
		if (! empty($doc['video'])) {
			$item['video'] = (string) $doc['video'];
		}
	} catch (Throwable $e) {
	}
	return $item;
}

function epv2_test_pick_candidate(object $source, array $config): ?array {
	$items = EPV2_Collector::collect_source($source);
	$skip_patterns = $config['skip_patterns'] ?? [];
	$pick_index = (int) ($config['pick_index'] ?? 0);
	$preferred_patterns = $config['preferred_patterns'] ?? [];
	$candidates = [];
	foreach ($items as $item) {
		$title = trim((string) ($item['title'] ?? ''));
		$url = trim((string) ($item['url'] ?? ''));
		if ($title === '' || $url === '') {
			continue;
		}
		$skip = false;
		foreach ($skip_patterns as $pattern) {
			if (preg_match($pattern, $title) || preg_match($pattern, $url)) {
				$skip = true;
				break;
			}
		}
		if ($skip) {
			continue;
		}
		$candidates[] = epv2_test_enrich_item($item);
	}
	if ($candidates === []) {
		return null;
	}
	if ($preferred_patterns !== []) {
		foreach ($candidates as $candidate) {
			$title = (string) ($candidate['title'] ?? '');
			foreach ($preferred_patterns as $pattern) {
				if (preg_match($pattern, $title)) {
					return $candidate;
				}
			}
		}
	}
	return $candidates[min($pick_index, count($candidates) - 1)];
}

function epv2_test_build_payload(object $item, string $category, string $style, array $editorial = []): array {
	$payload = EPV2_AI_Processor::generate_review_payload($item, [$category], $style);
	$payload['categories'] = [$category];
	$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
	foreach ($editorial as $key => $value) {
		$payload['_meta'][$key] = $value;
	}
	return EPV2_AI_Response_Validator::enrich_payload($payload);
}

function epv2_test_attach_media(array $payload, object $item, string $category): array {
	$featured = (string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? $item->source_image_url ?? '');
	$inline = EPV2_Media::normalize_media_list($payload['inline_media_urls'] ?? []);
	if ($featured === '') {
		$photos = epv2_test_pexels_search(epv2_test_media_query($category, (string) ($payload['languages']['de']['title'] ?? $item->original_title)), 2);
		if ($photos !== []) {
			$featured = $photos[0];
			if (! empty($photos[1])) {
				$inline[] = $photos[1];
			}
			$payload['_meta']['media_origin'] = 'pexels';
		}
	} else {
		$payload['_meta']['media_origin'] = 'source';
	}
	$payload['featured_media_url'] = $featured;
	$payload['media_url'] = $featured;
	$payload['inline_media_urls'] = array_values(array_unique($inline));
	foreach (['de', 'uk', 'en'] as $lang) {
		$payload['languages'][$lang] = array_merge(
			$payload['languages'][$lang] ?? [],
			[
				'media_url' => (string) ($payload['languages'][$lang]['media_url'] ?? $featured),
				'inline_media_urls' => $payload['inline_media_urls'],
			]
		);
	}
	return $payload;
}

function epv2_test_update_queue_item(int $item_id, string $category, array $payload, object $source): object {
	EPV2_Review::save_payload($item_id, $payload);
	EPV2_Queue::mark_state($item_id, 'ready_review', [
		'category_final' => $category,
		'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
		'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
		'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
		'source_id' => (int) $source->id,
	]);
	$item = EPV2_Queue::get_item($item_id);
	if (! $item) {
		throw new RuntimeException('Queue item not found after payload save.');
	}
	return $item;
}

function epv2_test_create_item_from_candidate(object $source, array $candidate, string $category): object {
	$item_id = EPV2_Queue::add_item([
		'source_id' => (int) $source->id,
		'url' => (string) ($candidate['url'] ?? ''),
		'title' => (string) ($candidate['title'] ?? ''),
		'content' => (string) ($candidate['content'] ?? ''),
		'excerpt' => (string) ($candidate['excerpt'] ?? ''),
		'date' => (string) ($candidate['date'] ?? ''),
		'author' => (string) ($candidate['author'] ?? ''),
		'image' => (string) ($candidate['image'] ?? ''),
		'category' => $category,
	]);
	$item = EPV2_Queue::get_item($item_id);
	if (! $item) {
		throw new RuntimeException('Failed to create queue item.');
	}
	return $item;
}

$category_plan = [
	'deutschland' => [
		'style' => 'analytic',
		'sources' => [
			['id' => 48, 'pick_index' => 0],
			['id' => 1, 'pick_index' => 3],
		],
	],
	'münchen' => [
		'style' => 'analytic',
		'sources' => [
			['id' => 36, 'pick_index' => 3],
			['id' => 35, 'pick_index' => 2, 'skip_patterns' => ['~readspeaker|geoportal|Vorlesen~iu']],
		],
	],
	'bayern' => [
		'style' => 'analytic',
		'editorial' => ['top_story' => true],
		'sources' => [
			['id' => 36, 'pick_index' => 2],
			['id' => 14, 'pick_index' => 3],
		],
	],
	'ukraine' => [
		'style' => 'analytic',
		'editorial' => ['breaking' => true, 'breaking_hours' => 6],
		'sources' => [
			['id' => 25, 'pick_index' => 3],
			['id' => 25, 'pick_index' => 4],
		],
	],
	'europa' => [
		'style' => 'analytic',
		'sources' => [
			['id' => 44, 'pick_index' => 0, 'skip_patterns' => ['~^Forum\b~iu']],
			['id' => 9, 'pick_index' => 0],
		],
	],
	'politik' => [
		'style' => 'analytic',
		'editorial' => ['breaking' => true, 'breaking_hours' => 4],
		'sources' => [
			['id' => 3, 'pick_index' => 2],
			['id' => 42, 'pick_index' => 0],
			['id' => 2, 'pick_index' => 1],
		],
	],
	'wirtschaft' => [
		'style' => 'analytic',
		'sources' => [
			['id' => 43, 'pick_index' => 2],
			['id' => 43, 'pick_index' => 1],
			['id' => 8, 'pick_index' => 2],
		],
	],
	'leben-in-deutschland' => [
		'style' => 'analytic',
		'sources' => [
			['id' => 45, 'pick_index' => 0],
			['id' => 20, 'pick_index' => 0],
		],
	],
	'kultur' => [
		'style' => 'lively',
		'sources' => [
			['id' => 39, 'pick_index' => 0, 'skip_patterns' => ['~Polizeiruf|Mittagskonzert~iu']],
			['id' => 19, 'pick_index' => 0],
		],
	],
	'sport' => [
		'style' => 'lively',
		'sources' => [
			['id' => 50, 'pick_index' => 1],
			['id' => 38, 'pick_index' => 0, 'skip_patterns' => ['~TV und Stream|live~iu']],
		],
	],
	'community' => [
		'style' => 'lively',
		'sources' => [
			['id' => 28, 'pick_index' => 2],
			['id' => 30, 'pick_index' => 0],
		],
	],
];

$targetCategory = getenv('EPV2_TARGET_CATEGORY') ?: '';
if ($targetCategory !== '') {
	$category_plan = array_intersect_key($category_plan, [$targetCategory => true]);
}

if ($targetCategory === '') {
	EPV2_Queue::clear_all();
}

$summary = [];
$errors = [];

foreach ($category_plan as $category => $config) {
	$source = null;
	$candidate = null;
	foreach ($config['sources'] as $sourceConfig) {
		$source = epv2_test_fetch_source((int) $sourceConfig['id']);
		if (! $source) {
			continue;
		}
		$candidate = epv2_test_pick_candidate($source, $sourceConfig);
		if ($candidate !== null) {
			break;
		}
	}

	if (! $source || ! $candidate) {
		$errors[$category] = 'No source candidate found';
		continue;
	}

	try {
		$item = epv2_test_create_item_from_candidate($source, $candidate, $category);
		$payload = epv2_test_build_payload($item, $category, (string) ($config['style'] ?? 'analytic'), (array) ($config['editorial'] ?? []));
		$payload = epv2_test_attach_media($payload, $item, $category);
		$payload = EPV2_AI_Response_Validator::enrich_payload($payload);

		$deLen = mb_strlen(wp_strip_all_tags((string) ($payload['languages']['de']['content'] ?? '')));
		$ukLen = mb_strlen(wp_strip_all_tags((string) ($payload['languages']['uk']['content'] ?? '')));
		$enLen = mb_strlen(wp_strip_all_tags((string) ($payload['languages']['en']['content'] ?? '')));
		if ($deLen < 1200 || $ukLen < 900 || $enLen < 900) {
			throw new RuntimeException('Payload too short for newsroom publish');
		}

		$item = epv2_test_update_queue_item((int) $item->id, $category, $payload, $source);
		$primaryPostId = EPV2_Publisher::publish_item($item, 'publish');
		$queueItem = EPV2_Queue::get_item((int) $item->id);
		$publishPayload = json_decode((string) ($queueItem->publish_payload ?? ''), true);
		$postIds = is_array($publishPayload['post_ids'] ?? null) ? $publishPayload['post_ids'] : [];

		$summary[$category] = [
			'queue_id' => (int) $item->id,
			'source_id' => (int) $source->id,
			'source_name' => (string) $source->name,
			'candidate_title' => (string) ($candidate['title'] ?? ''),
			'candidate_url' => (string) ($candidate['url'] ?? ''),
			'quality' => (int) ($payload['_meta']['quality']['score'] ?? 0),
			'seo' => (int) ($payload['_meta']['seo_quality']['score'] ?? 0),
			'media_origin' => (string) ($payload['_meta']['media_origin'] ?? ''),
			'featured_media_url' => (string) ($payload['featured_media_url'] ?? ''),
			'de_len' => $deLen,
			'uk_len' => $ukLen,
			'en_len' => $enLen,
			'primary_post_id' => $primaryPostId,
			'post_ids' => $postIds,
			'state' => (string) ($queueItem->state ?? ''),
		];
	} catch (Throwable $e) {
		$errors[$category] = $e->getMessage();
	}
}

echo wp_json_encode([
	'ok' => $errors === [],
	'published' => count($summary),
	'categories' => $summary,
	'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
