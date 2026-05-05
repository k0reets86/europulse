<?php

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run with WP-CLI: wp eval-file scripts/epv2_controlled_rebuild_quality_audit.php -- --ids=1109,1111-1118\n");
	exit(1);
}

$cli_args = array_merge((array) ($argv ?? []), (array) ($args ?? []));
$ids = [];
$stage = 'rebuild_bundle';
$content_chars = 1400;

foreach ($cli_args as $arg) {
	$arg = trim((string) $arg);
	if ($arg === '') {
		continue;
	}
	if (preg_match('/^--stage=(.+)$/', $arg, $m) === 1 || preg_match('/^stage=(.+)$/', $arg, $m) === 1) {
		$stage = sanitize_key((string) $m[1]);
		continue;
	}
	if (preg_match('/^--content-chars=(\d+)$/', $arg, $m) === 1 || preg_match('/^content-chars=(\d+)$/', $arg, $m) === 1) {
		$content_chars = max(200, min(5000, (int) $m[1]));
		continue;
	}
	if (preg_match('/^--ids=(.+)$/', $arg, $m) === 1 || preg_match('/^ids=(.+)$/', $arg, $m) === 1) {
		$ids = array_merge($ids, epv2_controlled_rebuild_parse_ids((string) $m[1]));
		continue;
	}
	if (preg_match('/^\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*$/', $arg) === 1) {
		$ids = array_merge($ids, epv2_controlled_rebuild_parse_ids($arg));
	}
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
sort($ids);

if ($ids === []) {
	fwrite(STDERR, "No ids supplied. Example: wp eval-file scripts/epv2_controlled_rebuild_quality_audit.php -- --ids=1109,1111-1118\n");
	exit(1);
}

if (! class_exists('EPV2_Queue') || ! class_exists('EPV2_Worker_Client')) {
	fwrite(STDERR, "EPV2 queue/worker classes are not loaded\n");
	exit(1);
}

$summary = [
	'dry_run_only' => true,
	'note' => 'Calls the external worker directly and does not save queue state or publish posts.',
	'stage' => $stage,
	'content_chars' => $content_chars,
	'ids' => $ids,
	'checked' => 0,
	'items' => [],
];

foreach ($ids as $id) {
	$item = EPV2_Queue::get_item((int) $id);
	if (! $item) {
		$summary['items'][] = [
			'id' => (int) $id,
			'status' => 'missing',
		];
		continue;
	}

	$summary['checked']++;
	$result = [
		'id' => (int) $id,
		'status' => 'ok',
		'queue' => [
			'state' => (string) ($item->state ?? ''),
			'category_final' => (string) ($item->category_final ?? ''),
			'category_proposed' => (string) ($item->category_proposed ?? ''),
			'story_score' => (int) ($item->story_score ?? 0),
			'title' => epv2_controlled_rebuild_excerpt((string) ($item->original_title ?? ''), 180),
			'url' => (string) ($item->original_url ?? ''),
			'source_image_url' => (string) ($item->source_image_url ?? ''),
		],
	];

	try {
		$response = EPV2_Worker_Client::process($item, $stage, []);
		$result['outcome'] = (string) ($response['outcome'] ?? '');
		$result['warnings'] = array_values((array) ($response['warnings'] ?? []));
		$result['blockers'] = array_values((array) ($response['blockers'] ?? []));
		$result['ai_runtime'] = array_values((array) ($response['ai_runtime'] ?? []));
		$result['quality'] = (array) ($response['quality'] ?? []);
		$result['categories'] = array_values((array) ($response['categories'] ?? []));
		$result['tags'] = array_values((array) ($response['tags'] ?? []));
		$result['media'] = [
			'featured_media_url' => (string) ($response['featured_media_url'] ?? ''),
			'media_candidates' => array_values((array) ($response['media_candidates'] ?? [])),
			'source_dossier_primary_image' => (string) ($response['source_dossier']['primary']['image'] ?? ''),
			'source_dossier_primary_url' => (string) ($response['source_dossier']['primary']['url'] ?? ''),
			'supporting_count' => count((array) ($response['source_dossier']['supporting'] ?? [])),
		];
		$result['languages'] = [
			'de' => epv2_controlled_rebuild_language_preview((array) ($response['german_master'] ?? []), $content_chars),
			'uk' => epv2_controlled_rebuild_language_preview((array) ($response['ukrainian'] ?? []), $content_chars),
			'en' => epv2_controlled_rebuild_language_preview((array) ($response['english'] ?? []), $content_chars),
		];
	} catch (Throwable $e) {
		$result['status'] = 'error';
		$result['error'] = $e->getMessage();
	}

	$summary['items'][] = $result;
}

echo wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

function epv2_controlled_rebuild_parse_ids(string $value): array {
	$ids = [];
	foreach (explode(',', $value) as $part) {
		$part = trim($part);
		if ($part === '') {
			continue;
		}
		if (preg_match('/^(\d+)-(\d+)$/', $part, $m) === 1) {
			$start = (int) $m[1];
			$end = (int) $m[2];
			if ($end < $start) {
				[$start, $end] = [$end, $start];
			}
			for ($id = $start; $id <= $end; $id++) {
				$ids[] = $id;
			}
			continue;
		}
		if (preg_match('/^\d+$/', $part) === 1) {
			$ids[] = (int) $part;
		}
	}
	return $ids;
}

function epv2_controlled_rebuild_language_preview(array $package, int $content_chars): array {
	return [
		'title' => epv2_controlled_rebuild_excerpt((string) ($package['title'] ?? ''), 220),
		'excerpt' => epv2_controlled_rebuild_excerpt((string) ($package['excerpt'] ?? ''), 520),
		'content' => epv2_controlled_rebuild_excerpt((string) ($package['content'] ?? ''), $content_chars),
		'seo_title' => epv2_controlled_rebuild_excerpt((string) ($package['seo_title'] ?? ''), 220),
		'meta_description' => epv2_controlled_rebuild_excerpt((string) ($package['meta_description'] ?? ''), 320),
		'slug' => (string) ($package['slug'] ?? ''),
	];
}

function epv2_controlled_rebuild_excerpt(string $value, int $limit): string {
	$value = trim(wp_strip_all_tags($value));
	$value = preg_replace('/[ \t\r\n]+/u', ' ', $value);
	if (! is_string($value)) {
		return '';
	}
	if (mb_strlen($value) <= $limit) {
		return $value;
	}
	return mb_substr($value, 0, max(0, $limit - 1)) . '…';
}
