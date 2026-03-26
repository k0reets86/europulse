<?php

require '/var/www/europulse/public/wp-load.php';

wp_set_current_user(1);

$draft = EPV2_Manual_Mode::save_draft([
	'title' => 'Smoke: Europa und Politik im manuellen Workflow',
	'excerpt' => 'Короткий smoke-лид для проверки ручного редактора.',
	'content' => '<p>Smoke-тест проверяет ручной редактор, review payload и перенос SEO/медиа.</p>',
	'categories' => ['europa', 'politik'],
	'featured_media_url' => 'https://images.unsplash.com/photo-1494526585095-c41746248156?auto=format&fit=crop&w=1200&q=80',
	'inline_media_urls' => [
		'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=1200&q=80',
	],
	'seo' => [
		'seo_title' => 'Smoke SEO Europa Politik',
		'meta_description' => 'Smoke meta description',
		'slug' => 'smoke-europa-politik',
		'focus_keywords' => ['europa politik', 'smoke test'],
	],
]);

$item_id = EPV2_Manual_Mode::create_review_item($draft);
$item = EPV2_Queue::get_item($item_id);
$payload = json_decode((string) $item->ai_payload, true);

$result = [
	'item_id' => $item_id,
	'state' => (string) ($item->state ?? ''),
	'category_final' => (string) ($item->category_final ?? ''),
	'featured_media_url' => (string) ($payload['featured_media_url'] ?? ''),
	'inline_media_count' => count($payload['inline_media_urls'] ?? []),
	'seo_title' => (string) ($payload['languages']['de']['seo_title'] ?? ''),
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
