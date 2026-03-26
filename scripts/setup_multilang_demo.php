<?php

if (! function_exists('pll_get_post_language')) {
	throw new RuntimeException('Polylang is not active.');
}

function europulse_translate_text(string $text, string $target): string {
	$text = trim($text);

	if ($text === '') {
		return $text;
	}

	$url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=de&tl=' . rawurlencode($target) . '&dt=t&q=' . rawurlencode($text);
	$response = wp_remote_get($url, [
		'timeout' => 20,
		'user-agent' => 'EuroPulseDemo/1.0',
	]);

	if (is_wp_error($response)) {
		return $text;
	}

	$body = wp_remote_retrieve_body($response);
	$data = json_decode($body, true);

	if (! is_array($data) || empty($data[0]) || ! is_array($data[0])) {
		return $text;
	}

	$translated = '';

	foreach ($data[0] as $chunk) {
		if (! empty($chunk[0])) {
			$translated .= $chunk[0];
		}
	}

	return $translated !== '' ? $translated : $text;
}

function europulse_translate_html(string $html, string $target): string {
	$parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

	if (! is_array($parts)) {
		return $html;
	}

	foreach ($parts as &$part) {
		if ($part === '' || $part[0] === '<') {
			continue;
		}

		$decoded = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		if (! preg_match('/\p{L}/u', $decoded)) {
			continue;
		}

		$leading = '';
		$trailing = '';

		if (preg_match('/^\s+/u', $decoded, $match)) {
			$leading = $match[0];
		}

		if (preg_match('/\s+$/u', $decoded, $match)) {
			$trailing = $match[0];
		}

		$core = trim($decoded);
		$translated = europulse_translate_text($core, $target);
		$part = $leading . htmlspecialchars($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8') . $trailing;
	}

	unset($part);

	return implode('', $parts);
}

function europulse_ensure_language(array $args): void {
	if (PLL()->model->get_language($args['slug'])) {
		return;
	}

	$result = PLL()->model->add_language($args);

	if (is_wp_error($result)) {
		throw new RuntimeException($result->get_error_message());
	}
}

function europulse_ensure_term_translation(array $config, string $lang, array $map): int {
	$source_id = (int) $config['source_id'];
	$source_term = get_term($source_id, 'category');

	if (! $source_term || is_wp_error($source_term)) {
		throw new RuntimeException("Missing source category {$source_id}");
	}

	$existing = pll_get_term($source_id, $lang);
	if ($existing) {
		$term_id = (int) $existing;
		wp_update_term($term_id, 'category', [
			'name' => $config[$lang]['name'],
			'slug' => $config[$lang]['slug'],
			'description' => $config[$lang]['description'] ?? '',
			'parent' => ! empty($config['parent']) ? ($map[$lang][$config['parent']] ?? 0) : 0,
		]);
	} else {
		$result = wp_insert_term($config[$lang]['name'], 'category', [
			'slug' => $config[$lang]['slug'],
			'description' => $config[$lang]['description'] ?? '',
			'parent' => ! empty($config['parent']) ? ($map[$lang][$config['parent']] ?? 0) : 0,
		]);

		if (is_wp_error($result)) {
			$maybe_existing = term_exists($config[$lang]['slug'], 'category');

			if (! $maybe_existing) {
				$maybe_existing = term_exists($config[$lang]['name'], 'category');
			}

			if (! $maybe_existing) {
				throw new RuntimeException($result->get_error_message());
			}

			$term_id = (int) (is_array($maybe_existing) ? $maybe_existing['term_id'] : $maybe_existing);
		} else {
			$term_id = (int) $result['term_id'];
		}
		pll_set_term_language($term_id, $lang);
	}

	$translations = pll_get_term_translations($source_id);
	$translations[$lang] = $term_id;
	pll_save_term_translations($translations);

	return $term_id;
}

function europulse_map_post_categories(int $post_id, string $lang): array {
	$terms = get_the_terms($post_id, 'category');

	if (! is_array($terms)) {
		return [];
	}

	$translated = [];

	foreach ($terms as $term) {
		$target = pll_get_term($term->term_id, $lang);

		if ($target) {
			$translated[] = (int) $target;
		}
	}

	return array_values(array_unique($translated));
}

function europulse_translate_tags(int $post_id, string $lang): array {
	$tags = wp_get_post_tags($post_id, ['fields' => 'names']);

	if (! is_array($tags) || $tags === []) {
		return [];
	}

	$translated = [];

	foreach ($tags as $tag) {
		$translated[] = europulse_translate_text($tag, $lang);
	}

	return $translated;
}

function europulse_replace_category_ids(string $content, array $term_maps, string $lang): string {
	foreach ($term_maps['de'] as $source_id => $source_term_id) {
		$target_id = $term_maps[$lang][$source_id] ?? null;

		if (! $target_id) {
			continue;
		}

		$content = str_replace('"?cat=' . $source_term_id . '"', '"?cat=' . $target_id . '"', $content);
		$content = str_replace('category":[' . $source_term_id . ']', 'category":[' . $target_id . ']', $content);
	}

	return $content;
}

function europulse_duplicate_post(int $source_id, string $lang, string $post_type, array $term_maps = []): int {
	$existing = pll_get_post($source_id, $lang);

	$source = get_post($source_id);

	if (! $source) {
		throw new RuntimeException("Missing source post {$source_id}");
	}

	$title = europulse_translate_text($source->post_title, $lang);
	$excerpt = europulse_translate_text($source->post_excerpt, $lang);
	$content = europulse_translate_html($source->post_content, $lang);

	if ($term_maps !== []) {
		$content = europulse_replace_category_ids($content, $term_maps, $lang);
	}

	$postarr = [
		'post_type' => $post_type,
		'post_status' => $source->post_status,
		'post_author' => $source->post_author,
		'post_title' => $title,
		'post_name' => sanitize_title($title),
		'post_excerpt' => $excerpt,
		'post_content' => $content,
		'post_date' => $source->post_date,
		'post_date_gmt' => $source->post_date_gmt,
		'post_parent' => 0,
		'comment_status' => $source->comment_status,
		'ping_status' => $source->ping_status,
	];

	if ($existing) {
		$postarr['ID'] = $existing;
		$post_id = wp_update_post($postarr, true);
	} else {
		$post_id = wp_insert_post($postarr, true);
	}

	if (is_wp_error($post_id)) {
		throw new RuntimeException($post_id->get_error_message());
	}

	$post_id = (int) $post_id;
	pll_set_post_language($post_id, $lang);

	if ('post' === $post_type) {
		$categories = europulse_map_post_categories($source_id, $lang);

		if ($categories !== []) {
			wp_set_post_categories($post_id, $categories);
		}

		$tags = europulse_translate_tags($source_id, $lang);

		if ($tags !== []) {
			wp_set_post_tags($post_id, $tags, false);
		}

		$thumbnail_id = get_post_thumbnail_id($source_id);
		if ($thumbnail_id) {
			set_post_thumbnail($post_id, $thumbnail_id);
		}

		$popular = get_post_meta($source_id, 'europulse_popular_score', true);
		if ($popular !== '') {
			update_post_meta($post_id, 'europulse_popular_score', $popular);
		}

		if (get_post_meta($source_id, 'europulse_breaking', true)) {
			update_post_meta($post_id, 'europulse_breaking', '1');
		}

		if (get_post_meta($source_id, 'europulse_sponsored', true)) {
			update_post_meta($post_id, 'europulse_sponsored', '1');
		}

		$sources = get_post_meta($source_id, 'europulse_sources', true);
		if (is_array($sources)) {
			update_post_meta($post_id, 'europulse_sources', $sources);
		}
	}

	$translations = pll_get_post_translations($source_id);
	$translations[$lang] = $post_id;
	pll_save_post_translations($translations);

	return $post_id;
}

function europulse_build_menu(string $lang, string $name, array $labels, array $page_map, array $term_map): int {
	$existing = wp_get_nav_menu_object($name);
	$menu_id = $existing ? (int) $existing->term_id : (int) wp_create_nav_menu($name);

	$items = wp_get_nav_menu_items($menu_id);
	if (is_array($items)) {
		foreach ($items as $item) {
			wp_delete_post($item->ID, true);
		}
	}

	$menu = [];

	$menu['home'] = wp_update_nav_menu_item($menu_id, 0, [
		'menu-item-title' => $labels['startseite'],
		'menu-item-object-id' => $page_map['startseite'][$lang],
		'menu-item-object' => 'page',
		'menu-item-type' => 'post_type',
		'menu-item-status' => 'publish',
	]);

	$menu['deutschland'] = wp_update_nav_menu_item($menu_id, 0, [
		'menu-item-title' => $labels['deutschland'],
		'menu-item-object-id' => $term_map['deutschland'][$lang],
		'menu-item-object' => 'category',
		'menu-item-type' => 'taxonomy',
		'menu-item-status' => 'publish',
	]);

	foreach (['muenchen', 'bayern'] as $slug) {
		wp_update_nav_menu_item($menu_id, 0, [
			'menu-item-title' => $labels[$slug],
			'menu-item-object-id' => $term_map[$slug][$lang],
			'menu-item-object' => 'category',
			'menu-item-type' => 'taxonomy',
			'menu-item-status' => 'publish',
			'menu-item-parent-id' => $menu['deutschland'],
		]);
	}

	foreach (['ukraine', 'europa', 'politik', 'wirtschaft', 'leben', 'kultur', 'sport'] as $slug) {
		wp_update_nav_menu_item($menu_id, 0, [
			'menu-item-title' => $labels[$slug],
			'menu-item-object-id' => $term_map[$slug][$lang],
			'menu-item-object' => 'category',
			'menu-item-type' => 'taxonomy',
			'menu-item-status' => 'publish',
		]);
	}

	$menu['community'] = wp_update_nav_menu_item($menu_id, 0, [
		'menu-item-title' => $labels['community'],
		'menu-item-object-id' => $term_map['community'][$lang],
		'menu-item-object' => 'category',
		'menu-item-type' => 'taxonomy',
		'menu-item-status' => 'publish',
	]);

	foreach (['veranstaltungen', 'initiativen', 'vereine', 'treffen'] as $slug) {
		wp_update_nav_menu_item($menu_id, 0, [
			'menu-item-title' => $labels[$slug],
			'menu-item-object-id' => $term_map[$slug][$lang],
			'menu-item-object' => 'category',
			'menu-item-type' => 'taxonomy',
			'menu-item-status' => 'publish',
			'menu-item-parent-id' => $menu['community'],
		]);
	}

	return $menu_id;
}

europulse_ensure_language([
	'name' => 'English',
	'slug' => 'en',
	'locale' => 'en_US',
	'rtl' => 0,
	'flag' => 'us',
	'term_group' => 2,
]);

$category_configs = [
	['source_id' => 14, 'key' => 'deutschland', 'de' => ['name' => 'Deutschland', 'slug' => 'deutschland'], 'en' => ['name' => 'Germany', 'slug' => 'germany'], 'uk' => ['name' => 'Німеччина', 'slug' => 'nimechchyna']],
	['source_id' => 1, 'key' => 'muenchen', 'parent' => 14, 'de' => ['name' => 'München', 'slug' => 'muenchen'], 'en' => ['name' => 'Munich', 'slug' => 'munich'], 'uk' => ['name' => 'Мюнхен', 'slug' => 'miunkhen']],
	['source_id' => 12, 'key' => 'bayern', 'parent' => 14, 'de' => ['name' => 'Bayern', 'slug' => 'bayern'], 'en' => ['name' => 'Bavaria', 'slug' => 'bavaria'], 'uk' => ['name' => 'Баварія', 'slug' => 'bavariia']],
	['source_id' => 16, 'key' => 'ukraine', 'de' => ['name' => 'Ukraine', 'slug' => 'ukraine'], 'en' => ['name' => 'Ukraine', 'slug' => 'ukraine'], 'uk' => ['name' => 'Україна', 'slug' => 'ukraina']],
	['source_id' => 18, 'key' => 'europa', 'de' => ['name' => 'Europa', 'slug' => 'europa'], 'en' => ['name' => 'Europe', 'slug' => 'europe'], 'uk' => ['name' => 'Європа', 'slug' => 'yevropa']],
	['source_id' => 22, 'key' => 'politik', 'de' => ['name' => 'Politik', 'slug' => 'politik'], 'en' => ['name' => 'Politics', 'slug' => 'politics'], 'uk' => ['name' => 'Політика', 'slug' => 'polityka']],
	['source_id' => 24, 'key' => 'wirtschaft', 'de' => ['name' => 'Wirtschaft', 'slug' => 'wirtschaft'], 'en' => ['name' => 'Economy', 'slug' => 'economy'], 'uk' => ['name' => 'Економіка', 'slug' => 'ekonomika']],
	['source_id' => 26, 'key' => 'leben', 'de' => ['name' => 'Leben in Deutschland', 'slug' => 'leben-in-deutschland'], 'en' => ['name' => 'Life in Germany', 'slug' => 'life-in-germany'], 'uk' => ['name' => 'Життя в Німеччині', 'slug' => 'zhyttia-v-nimechchyni']],
	['source_id' => 28, 'key' => 'kultur', 'de' => ['name' => 'Kultur', 'slug' => 'kultur'], 'en' => ['name' => 'Culture', 'slug' => 'culture'], 'uk' => ['name' => 'Культура', 'slug' => 'kultura']],
	['source_id' => 30, 'key' => 'sport', 'de' => ['name' => 'Sport', 'slug' => 'sport'], 'en' => ['name' => 'Sport', 'slug' => 'sport'], 'uk' => ['name' => 'Спорт', 'slug' => 'sport']],
	['source_id' => 46, 'key' => 'community', 'de' => ['name' => 'Community', 'slug' => 'community'], 'en' => ['name' => 'Community', 'slug' => 'community'], 'uk' => ['name' => 'Спільнота', 'slug' => 'spilnota']],
	['source_id' => 48, 'key' => 'veranstaltungen', 'parent' => 46, 'de' => ['name' => 'Veranstaltungen', 'slug' => 'veranstaltungen'], 'en' => ['name' => 'Events', 'slug' => 'events'], 'uk' => ['name' => 'Події', 'slug' => 'podii']],
	['source_id' => 50, 'key' => 'initiativen', 'parent' => 46, 'de' => ['name' => 'Ukrainische Initiativen', 'slug' => 'ukrainische-initiativen'], 'en' => ['name' => 'Ukrainian Initiatives', 'slug' => 'ukrainian-initiatives'], 'uk' => ['name' => 'Українські ініціативи', 'slug' => 'ukrainski-initsiatyvy']],
	['source_id' => 52, 'key' => 'vereine', 'parent' => 46, 'de' => ['name' => 'Vereine & Projekte', 'slug' => 'vereine-projekte'], 'en' => ['name' => 'Associations & Projects', 'slug' => 'associations-projects'], 'uk' => ['name' => 'Обʼєднання та проєкти', 'slug' => 'obiednannia-ta-proiekty']],
	['source_id' => 54, 'key' => 'treffen', 'parent' => 46, 'de' => ['name' => 'Treffen & Networking', 'slug' => 'treffen-networking'], 'en' => ['name' => 'Meetings & Networking', 'slug' => 'meetings-networking'], 'uk' => ['name' => 'Зустрічі та нетворкінг', 'slug' => 'zustrichi-ta-netvorkinh']],
];

$term_maps = [
	'de' => [],
	'en' => [],
	'uk' => [],
];

$term_key_map = [];

foreach ($category_configs as $config) {
	$term_maps['de'][$config['source_id']] = $config['source_id'];
	$term_key_map[$config['key']]['de'] = $config['source_id'];
}

foreach ($category_configs as $config) {
	$term_id = europulse_ensure_term_translation($config, 'en', $term_maps);
	$term_maps['en'][$config['source_id']] = $term_id;
	$term_key_map[$config['key']]['en'] = $term_id;
}

foreach ($category_configs as $config) {
	$term_id = europulse_ensure_term_translation($config, 'uk', $term_maps);
	$term_maps['uk'][$config['source_id']] = $term_id;
	$term_key_map[$config['key']]['uk'] = $term_id;
}

$pages = [
	'startseite' => 29,
	'nachrichten' => 28,
	'ueber-uns' => 36,
	'kontakt' => 37,
	'werbung' => 38,
	'community-einreichen' => 39,
	'korrekturen' => 40,
	'impressum' => 41,
	'cookie' => 42,
	'nutzungsbedingungen' => 43,
	'datenschutz' => 3,
];

$page_map = [];

foreach ($pages as $key => $id) {
	$page_map[$key]['de'] = $id;
	$page_map[$key]['uk'] = pll_get_post($id, 'uk') ?: 0;
	$page_map[$key]['en'] = pll_get_post($id, 'en') ?: 0;
}

foreach ($pages as $key => $id) {
	if (! $page_map[$key]['uk']) {
		$page_map[$key]['uk'] = europulse_duplicate_post($id, 'uk', 'page', $term_maps);
	}

	if (! $page_map[$key]['en']) {
		$page_map[$key]['en'] = europulse_duplicate_post($id, 'en', 'page', $term_maps);
	}
}

$de_post_ids = get_posts([
	'post_type' => 'post',
	'post_status' => 'publish',
	'posts_per_page' => -1,
	'lang' => 'de',
	'fields' => 'ids',
]);

foreach ($de_post_ids as $post_id) {
	europulse_duplicate_post((int) $post_id, 'uk', 'post');
	europulse_duplicate_post((int) $post_id, 'en', 'post');
}

$labels = [
	'de' => [
		'startseite' => 'Startseite',
		'deutschland' => 'Deutschland',
		'muenchen' => 'München',
		'bayern' => 'Bayern',
		'ukraine' => 'Ukraine',
		'europa' => 'Europa',
		'politik' => 'Politik',
		'wirtschaft' => 'Wirtschaft',
		'leben' => 'Leben in Deutschland',
		'kultur' => 'Kultur',
		'sport' => 'Sport',
		'community' => 'Community',
		'veranstaltungen' => 'Veranstaltungen',
		'initiativen' => 'Ukrainische Initiativen',
		'vereine' => 'Vereine & Projekte',
		'treffen' => 'Treffen & Networking',
	],
	'en' => [
		'startseite' => 'Home',
		'deutschland' => 'Germany',
		'muenchen' => 'Munich',
		'bayern' => 'Bavaria',
		'ukraine' => 'Ukraine',
		'europa' => 'Europe',
		'politik' => 'Politics',
		'wirtschaft' => 'Economy',
		'leben' => 'Life in Germany',
		'kultur' => 'Culture',
		'sport' => 'Sport',
		'community' => 'Community',
		'veranstaltungen' => 'Events',
		'initiativen' => 'Ukrainian Initiatives',
		'vereine' => 'Associations & Projects',
		'treffen' => 'Meetings & Networking',
	],
	'uk' => [
		'startseite' => 'Головна',
		'deutschland' => 'Німеччина',
		'muenchen' => 'Мюнхен',
		'bayern' => 'Баварія',
		'ukraine' => 'Україна',
		'europa' => 'Європа',
		'politik' => 'Політика',
		'wirtschaft' => 'Економіка',
		'leben' => 'Життя в Німеччині',
		'kultur' => 'Культура',
		'sport' => 'Спорт',
		'community' => 'Спільнота',
		'veranstaltungen' => 'Події',
		'initiativen' => 'Українські ініціативи',
		'vereine' => 'Обʼєднання та проєкти',
		'treffen' => 'Зустрічі та нетворкінг',
	],
];

$menu_ids = [];

foreach (['de', 'en', 'uk'] as $lang) {
	$menu_ids[$lang] = europulse_build_menu(
		$lang,
		sprintf('Primary Navigation %s', strtoupper($lang)),
		$labels[$lang],
		$page_map,
		$term_key_map
	);
}

update_option('europulse_primary_menu_de', $menu_ids['de']);
update_option('europulse_primary_menu_en', $menu_ids['en']);
update_option('europulse_primary_menu_uk', $menu_ids['uk']);

echo "Multilingual structure prepared.\n";
