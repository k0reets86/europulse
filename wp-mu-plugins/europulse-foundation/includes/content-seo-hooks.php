<?php

/**
 * R18 2026-05-14: Dynamic term_id lookup with 1h transient cache.
 *
 * Заменяет inline hardcoded fallback IDs в content_replacements (line ~287+).
 * Cache 1h, auto-invalidated на created_category / edited_category / delete_category hooks.
 *
 * Priority order:
 *  1. Per-request static cache
 *  2. WP transient `europulse_term_ids_{lang}` (1h TTL)
 *  3. EPV2_Taxonomy_Map::map() — авторитетный resolver (plugin active)
 *  4. Direct ep_terms SQL query (last-resort, plugin disabled)
 *  5. Hardcoded fallback (legacy safety net)
 */
function europulse_resolve_term_id(string $slug, string $lang, int $fallback): int {
	static $request_cache = [];
	$key = $lang . ':' . $slug;
	if (isset($request_cache[$key])) {
		return $request_cache[$key];
	}
	$transient_key = 'europulse_term_ids_' . sanitize_key($lang);
	$cached = get_transient($transient_key);
	if (is_array($cached) && isset($cached[$slug])) {
		$request_cache[$key] = (int) $cached[$slug];
		return $request_cache[$key];
	}
	$resolved = 0;
	if (class_exists('EPV2_Taxonomy_Map')) {
		$mapped = EPV2_Taxonomy_Map::map($slug, $lang);
		if (is_array($mapped) && isset($mapped['term_id'])) {
			$resolved = (int) $mapped['term_id'];
		}
	}
	if ($resolved === 0) {
		// Last-resort direct SQL — handles plugin disabled scenario.
		global $wpdb;
		$resolved = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = 'category' AND t.slug = %s LIMIT 1",
			$slug
		));
	}
	if ($resolved === 0) {
		$resolved = $fallback;
	}
	if (! is_array($cached)) {
		$cached = [];
	}
	$cached[$slug] = $resolved;
	set_transient($transient_key, $cached, HOUR_IN_SECONDS);
	$request_cache[$key] = $resolved;
	return $resolved;
}

// Invalidate cache при category mutations.
add_action('created_category', static function (): void {
	delete_transient('europulse_term_ids_de');
	delete_transient('europulse_term_ids_en');
	delete_transient('europulse_term_ids_uk');
});
add_action('edited_category', static function (): void {
	delete_transient('europulse_term_ids_de');
	delete_transient('europulse_term_ids_en');
	delete_transient('europulse_term_ids_uk');
});
add_action('delete_category', static function (): void {
	delete_transient('europulse_term_ids_de');
	delete_transient('europulse_term_ids_en');
	delete_transient('europulse_term_ids_uk');
});

/**
 * R15 2026-05-14: Rank Math fallback — если RM disabled/uninstalled,
 * render SEO meta tags из mirror keys (_epv2_seo_title, _epv2_meta_desc)
 * прямо в wp_head. Иначе frontend теряет meta title/description на
 * 3300+ posts при RM crash или upgrade с breaking changes.
 */
add_action('wp_head', static function (): void {
	if (! is_singular('post')) {
		return;
	}
	// Rank Math активен? Если есть его main class — он сам отрендерит.
	if (class_exists('RankMath') || function_exists('rank_math')) {
		return;
	}
	$post_id = get_the_ID();
	if (! $post_id) {
		return;
	}
	$mirror_desc = trim((string) get_post_meta($post_id, '_epv2_meta_desc', true));
	$mirror_title = trim((string) get_post_meta($post_id, '_epv2_seo_title', true));
	if ($mirror_desc !== '') {
		echo "\n<!-- EuroPulse R15 fallback (Rank Math unavailable) -->\n";
		echo '<meta name="description" content="' . esc_attr($mirror_desc) . '">' . "\n";
		echo '<meta property="og:description" content="' . esc_attr($mirror_desc) . '">' . "\n";
	}
	if ($mirror_title !== '') {
		echo '<meta property="og:title" content="' . esc_attr($mirror_title) . '">' . "\n";
	}
}, 5);

// R15: Also override WP <title> if Rank Math missing.
add_filter('pre_get_document_title', static function ($title) {
	if (class_exists('RankMath') || function_exists('rank_math')) {
		return $title;
	}
	if (! is_singular('post')) {
		return $title;
	}
	$post_id = get_the_ID();
	if (! $post_id) {
		return $title;
	}
	$mirror_title = trim((string) get_post_meta($post_id, '_epv2_seo_title', true));
	return $mirror_title !== '' ? $mirror_title : $title;
}, 5);

function europulse_apply_video_poster_to_content(string $content, int $post_id): string {
	if (! str_contains($content, '<video')) {
		return $content;
	}

	$poster = europulse_video_poster_url($post_id);

	if (! $poster) {
		return $content;
	}

	return preg_replace(
		'/<video\b(?![^>]*\bposter=)/i',
		'<video class="wp-video-shortcode" controls playsinline preload="metadata" poster="' . esc_url($poster) . '"',
		$content,
		1
	) ?: $content;
}

function europulse_inject_inline_ads_into_content(string $content): string {
	if (
		! in_the_loop()
		|| ! is_main_query()
		|| ! europulse_ads_enabled()
	) {
		return $content;
	}

	$parts = preg_split('/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

	if (! is_array($parts) || count($parts) < 4) {
		return $content;
	}

	$paragraphs = [];

	for ($i = 0; $i < count($parts); $i += 2) {
		$paragraphs[] = ($parts[$i] ?? '') . ($parts[$i + 1] ?? '');
	}

	$early_slot = europulse_render_ad_slot('article-inline-1', 'europulse-ad-slot--inline');
	$late_slot = europulse_render_ad_slot('article-inline-2', 'europulse-ad-slot--inline');

	if (count($paragraphs) >= 3) {
		array_splice($paragraphs, 2, 0, $early_slot);
	}

	if (count($paragraphs) >= 6) {
		array_splice($paragraphs, -1, 0, $late_slot);
	}

	return implode('', $paragraphs);
}

add_filter('the_content', function ($content) {
	if (is_admin() || ! is_single() || get_post_type() !== 'post') {
		return $content;
	}

	$content = europulse_apply_video_poster_to_content($content, (int) get_the_ID());
	$content = europulse_inject_inline_ads_into_content($content);

	return $content;
}, 20);

add_filter('robots_txt', function ($output, $public) {
	$lines = preg_split('/\r\n|\r|\n/', trim((string) $output)) ?: [];
	$filtered = [];
	foreach ($lines as $line) {
		if (stripos($line, 'Sitemap:') === 0) {
			continue;
		}
		$filtered[] = $line;
	}
	$filtered[] = 'Sitemap: ' . home_url('/sitemap_index.xml');
	$filtered[] = 'News-sitemap: ' . home_url('/news-sitemap.xml');
	return implode("\n", array_values(array_filter($filtered)));
}, 20, 2);

add_action('template_redirect', function () {
	$request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
	if ($request_uri === '') {
		return;
	}
	$path = (string) wp_parse_url(home_url($request_uri), PHP_URL_PATH);
	if ($path === '/sitemap_index.xml') {
		wp_safe_redirect(home_url('/?sitemap=1'), 301);
		exit;
	}

	if (is_category() && class_exists('EPV2_Taxonomy_Map')) {
		$term = get_queried_object();
		if ($term instanceof WP_Term && $term->taxonomy === 'category') {
			$canonical_slug = EPV2_Taxonomy_Map::canonical_slug_for_term_id((int) $term->term_id);
			if ($canonical_slug !== '') {
				$request_lang = sanitize_key((string) ($_GET['lang'] ?? ''));
				$lang = in_array($request_lang, ['de', 'uk', 'en'], true)
					? $request_lang
					: '';
				if ($lang === '') {
					return;
				}
				$mapped = EPV2_Taxonomy_Map::map($canonical_slug, $lang);
				$expected_term_id = (int) ($mapped['term_id'] ?? 0);
				if ($expected_term_id > 0 && $expected_term_id !== (int) $term->term_id) {
					$target = function_exists('europulse_category_archive_url')
						? europulse_category_archive_url($expected_term_id, $lang)
						: add_query_arg('cat', $expected_term_id, home_url('/'));
					if ($target === '' && $lang !== 'de') {
						$target = add_query_arg('lang', $lang, add_query_arg('cat', $expected_term_id, home_url('/')));
					}
					wp_safe_redirect($target, 301);
					exit;
				}
			}
		}
	}
}, 1);

add_filter('redirect_canonical', function ($redirect_url, $requested_url) {
	if (is_category()) {
		return false;
	}

	return $redirect_url;
}, 10, 2);

add_action('pre_ping', function (&$links) {
	$home = wp_parse_url(home_url('/'));
	$host = mb_strtolower((string) ($home['host'] ?? ''));
	if ($host === '' || ! is_array($links)) {
		return;
	}

	foreach ($links as $index => $link) {
		$link_host = mb_strtolower((string) wp_parse_url((string) $link, PHP_URL_HOST));
		if ($link_host !== '' && $link_host === $host) {
			unset($links[$index]);
		}
	}
	$links = array_values($links);
}, 20);

add_filter('blocksy:footer:copyright:value', function () {
	return sprintf('&copy; %s EuroPulse', gmdate('Y'));
});

function europulse_category_hreflang_links(): array {
	if (! is_category() || ! function_exists('pll_get_term_translations')) {
		return [];
	}

	$term = get_queried_object();
	if (! ($term instanceof WP_Term) || $term->taxonomy !== 'category') {
		return [];
	}

	$links = [];

	$canonical_slug = class_exists('EPV2_Taxonomy_Map')
		? EPV2_Taxonomy_Map::canonical_slug_for_term_id((int) $term->term_id)
		: '';

	if ($canonical_slug !== '' && class_exists('EPV2_Taxonomy_Map')) {
		foreach (['de', 'uk', 'en'] as $lang) {
			$mapped = EPV2_Taxonomy_Map::map($canonical_slug, $lang);
			$term_id = (int) ($mapped['term_id'] ?? 0);
			if ($term_id <= 0 || ! get_term($term_id, 'category')) {
				continue;
			}
			$url = add_query_arg('cat', $term_id, home_url('/'));
			if ($lang !== 'de') {
				$url = add_query_arg('lang', $lang, $url);
			}
			$links[$lang] = $url;
		}
		return $links;
	}

	$translations = pll_get_term_translations((int) $term->term_id);
	if (! is_array($translations)) {
		$translations = [];
	}

	foreach (['de', 'uk', 'en'] as $lang) {
		$term_id = (int) ($translations[$lang] ?? 0);
		if ($term_id <= 0) {
			continue;
		}
		$url = add_query_arg('cat', $term_id, home_url('/'));
		if ($lang !== 'de') {
			$url = add_query_arg('lang', $lang, $url);
		}
		$links[$lang] = $url;
	}

	return $links;
}

function europulse_post_hreflang_links(): array {
	if (! is_singular('post') || ! function_exists('pll_get_post_translations')) {
		return [];
	}

	$post_id = (int) get_queried_object_id();
	if ($post_id <= 0) {
		return [];
	}

	$translations = pll_get_post_translations($post_id);
	if (! is_array($translations) || $translations === []) {
		return [];
	}

	$links = [];
	foreach (['de', 'uk', 'en'] as $lang) {
		$translated_id = (int) ($translations[$lang] ?? 0);
		if ($translated_id <= 0) {
			continue;
		}
		$url = get_permalink($translated_id);
		if (is_string($url) && $url !== '') {
			$links[$lang] = $url;
		}
	}

	if (! empty($links['de'])) {
		$links['x-default'] = $links['de'];
	}

	return $links;
}

add_action('wp_head', function () {
	$links = europulse_category_hreflang_links();
	if ($links === []) {
		return;
	}

	foreach ($links as $lang => $url) {
		printf("<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n", esc_attr($lang), esc_url($url));
	}
}, 2);

add_action('wp_head', function () {
	$links = europulse_post_hreflang_links();
	if ($links === []) {
		return;
	}

	foreach ($links as $lang => $url) {
		printf("<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n", esc_attr($lang), esc_url($url));
	}
}, 2);

add_action('wp_head', function () {
	if (is_admin() || ! function_exists('europulse_should_force_self_canonical') || ! europulse_should_force_self_canonical()) {
		return;
	}

	if (class_exists('RankMath') || function_exists('rank_math')) {
		return;
	}

	$canonical = function_exists('europulse_self_canonical_url') ? europulse_self_canonical_url() : '';
	if ($canonical === '') {
		return;
	}

	printf("<link rel=\"canonical\" href=\"%s\" />\n", esc_url($canonical));
}, 3);

add_filter('widget_block_content', function ($content) {
	$content = str_replace('<p><ul class="europulse-most-read-list">', '<ul class="europulse-most-read-list">', $content);
	$content = str_replace('<p><ul class="europulse-latest-widget-list">', '<ul class="europulse-latest-widget-list">', $content);
	$content = str_replace('</ul></p>', '</ul>', $content);
	$content = str_replace(
		'<li><a href="/?cat=18">Europa</a></li><li><a href="/?cat=22">Politik</a></li>',
		'<li><a href="/?cat=18">Europa</a></li><li><a href="/?cat=2801">Welt</a></li><li><a href="/?cat=22">Politik</a></li>',
		$content
	);

	if (function_exists('pll_current_language')) {
		$lang = pll_current_language('slug');

		if (in_array($lang, ['de', 'en', 'uk'], true)) {
			$replacements = [
				'de' => [
					// R18 2026-05-14: dynamic resolver replaces inline ternaries.
					'href="/?cat=14"' => 'href="/?cat=' . europulse_resolve_term_id('deutschland', 'de', 14) . '"',
					'href="/?cat=16"' => 'href="/?cat=' . europulse_resolve_term_id('ukraine', 'de', 16) . '"',
					'href="/?cat=18"' => 'href="/?cat=' . europulse_resolve_term_id('europa', 'de', 18) . '"',
					'href="/?cat=22"' => 'href="/?cat=' . europulse_resolve_term_id('politik', 'de', 22) . '"',
					'href="/?cat=24"' => 'href="/?cat=' . europulse_resolve_term_id('wirtschaft', 'de', 24) . '"',
					'href="/?cat=26"' => 'href="/?cat=' . europulse_resolve_term_id('leben-in-deutschland', 'de', 26) . '"',
					'href="/?cat=28"' => 'href="/?cat=' . europulse_resolve_term_id('kultur', 'de', 28) . '"',
					'href="/?cat=30"' => 'href="/?cat=' . europulse_resolve_term_id('sport', 'de', 249) . '"',
					'href="/?cat=46"' => 'href="/?cat=' . europulse_resolve_term_id('community', 'de', 46) . '"',
				],
				'en' => [
					'EuroPulse' => 'EuroPulse',
					'Ruhiges Nachrichtenportal für Deutschland, Europa, die Ukraine und Community-Themen.' => 'Calm editorial news portal for Germany, Europe, the world, Ukraine and community topics.',
					'Rubriken' => 'Sections',
					'Redaktion &amp; Service' => 'Editorial &amp; Service',
					'Rechtliches' => 'Legal',
					'Startseite' => 'Home',
					'Deutschland' => 'Germany',
					'Europa' => 'Europe',
					'Welt' => 'World',
					'Politik' => 'Politics',
					'Wirtschaft' => 'Economy',
					'Meinung' => 'Opinion',
					'Leben in Deutschland' => 'Life in Germany',
					'Kultur' => 'Culture',
					'Über uns' => 'About Us',
					'Kontakt' => 'Contact',
					'Werbung' => 'Advertising',
					'Community einreichen' => 'Submit Community',
					'Korrekturen' => 'Corrections',
					'Archiv' => 'Archive',
					'Impressum' => 'Imprint',
					'Datenschutz' => 'Privacy Policy',
					'Cookie-Einstellungen' => 'Cookie Settings',
					'Nutzungsbedingungen' => 'Terms of Use',
					'href="/"' => 'href="' . get_permalink(275) . '"',
					'href="/?cat=14"' => 'href="/?cat=' . pll_get_term(14, 'en') . '&amp;lang=en"',
					'href="/?cat=16"' => 'href="/?cat=' . pll_get_term(16, 'en') . '&amp;lang=en"',
					'href="/?cat=18"' => 'href="/?cat=' . pll_get_term(18, 'en') . '&amp;lang=en"',
					'href="/?cat=2801"' => 'href="/?cat=' . pll_get_term(2801, 'en') . '&amp;lang=en"',
					'href="/?cat=22"' => 'href="/?cat=' . pll_get_term(22, 'en') . '&amp;lang=en"',
					'href="/?cat=24"' => 'href="/?cat=' . pll_get_term(24, 'en') . '&amp;lang=en"',
					'href="/?cat=26"' => 'href="/?cat=' . pll_get_term(26, 'en') . '&amp;lang=en"',
					'href="/?cat=28"' => 'href="/?cat=' . pll_get_term(28, 'en') . '&amp;lang=en"',
					'href="/?cat=30"' => 'href="/?cat=' . pll_get_term(30, 'en') . '&amp;lang=en"',
					'href="/?cat=46"' => 'href="/?cat=' . pll_get_term(46, 'en') . '&amp;lang=en"',
					'href="/?page_id=36"' => 'href="' . get_permalink(279) . '"',
					'href="/?page_id=37"' => 'href="' . get_permalink(281) . '"',
					'href="/?page_id=38"' => 'href="' . get_permalink(283) . '"',
					'href="/?page_id=39"' => 'href="' . get_permalink(285) . '"',
					'href="/?page_id=40"' => 'href="' . get_permalink(287) . '"',
					'href="/?page_id=310"' => 'href="' . get_permalink(312) . '"',
					'href="/?page_id=41"' => 'href="' . get_permalink(289) . '"',
					'href="/?page_id=3"' => 'href="' . get_permalink(295) . '"',
					'href="/?page_id=42"' => 'href="' . get_permalink(291) . '"',
					'href="/?page_id=43"' => 'href="' . get_permalink(293) . '"',
				],
				'uk' => [
					'Ruhiges Nachrichtenportal für Deutschland, Europa, die Ukraine und Community-Themen.' => 'Спокійний редакційний новинний портал про Німеччину, Європу, світ, Україну та теми спільноти.',
					'Rubriken' => 'Розділи',
					'Redaktion &amp; Service' => 'Редакція та сервіс',
					'Rechtliches' => 'Правова інформація',
					'Startseite' => 'Головна',
					'Deutschland' => 'Німеччина',
					'Ukraine' => 'Україна',
					'Europa' => 'Європа',
					'Welt' => 'Світ',
					'Politik' => 'Політика',
					'Wirtschaft' => 'Економіка',
					'Meinung' => 'Думка',
					'Leben in Deutschland' => 'Життя в Німеччині',
					'Kultur' => 'Культура',
					'Sport' => 'Спорт',
					'Community' => 'Спільнота',
					'Über uns' => 'Про нас',
					'Kontakt' => 'Контакт',
					'Werbung' => 'Реклама',
					'Community einreichen' => 'Надіслати спільноту',
					'Korrekturen' => 'Виправлення',
					'Archiv' => 'Архів',
					'Impressum' => 'Вихідні дані',
					'Datenschutz' => 'Політика конфіденційності',
					'Cookie-Einstellungen' => 'Налаштування cookie',
					'Nutzungsbedingungen' => 'Умови використання',
					'href="/"' => 'href="' . get_permalink(33) . '"',
					'href="/?cat=14"' => 'href="/?cat=' . pll_get_term(14, 'uk') . '&amp;lang=uk"',
					'href="/?cat=16"' => 'href="/?cat=' . pll_get_term(16, 'uk') . '&amp;lang=uk"',
					'href="/?cat=18"' => 'href="/?cat=' . pll_get_term(18, 'uk') . '&amp;lang=uk"',
					'href="/?cat=2801"' => 'href="/?cat=' . pll_get_term(2801, 'uk') . '&amp;lang=uk"',
					'href="/?cat=22"' => 'href="/?cat=' . pll_get_term(22, 'uk') . '&amp;lang=uk"',
					'href="/?cat=24"' => 'href="/?cat=' . pll_get_term(24, 'uk') . '&amp;lang=uk"',
					'href="/?cat=26"' => 'href="/?cat=' . pll_get_term(26, 'uk') . '&amp;lang=uk"',
					'href="/?cat=28"' => 'href="/?cat=' . pll_get_term(28, 'uk') . '&amp;lang=uk"',
					'href="/?cat=30"' => 'href="/?cat=' . pll_get_term(30, 'uk') . '&amp;lang=uk"',
					'href="/?cat=46"' => 'href="/?cat=' . pll_get_term(46, 'uk') . '&amp;lang=uk"',
					'href="/?page_id=36"' => 'href="' . get_permalink(278) . '"',
					'href="/?page_id=37"' => 'href="' . get_permalink(280) . '"',
					'href="/?page_id=38"' => 'href="' . get_permalink(282) . '"',
					'href="/?page_id=39"' => 'href="' . get_permalink(284) . '"',
					'href="/?page_id=40"' => 'href="' . get_permalink(286) . '"',
					'href="/?page_id=310"' => 'href="' . get_permalink(311) . '"',
					'href="/?page_id=41"' => 'href="' . get_permalink(288) . '"',
					'href="/?page_id=3"' => 'href="' . get_permalink(294) . '"',
					'href="/?page_id=42"' => 'href="' . get_permalink(290) . '"',
					'href="/?page_id=43"' => 'href="' . get_permalink(292) . '"',
				],
			];

			$content = strtr($content, $replacements[$lang]);
		}
	}

	$content = preg_replace_callback(
		'~href="/\?cat=(\d+)(?:&amp;lang=(de|en|uk))?"~',
		static function (array $matches): string {
			$term_id = (int) ($matches[1] ?? 0);
			$lang = (string) ($matches[2] ?? '');
			if ($term_id <= 0 || ! function_exists('europulse_category_archive_url')) {
				return $matches[0];
			}
			$url = europulse_category_archive_url($term_id, $lang);
			if ($url === '') {
				return $matches[0];
			}
			return 'href="' . esc_url($url) . '"';
		},
		$content
	);

	return $content;
}, 20);

add_filter('render_block', function ($block_content) {
	if (is_admin() || ! function_exists('pll_current_language')) {
		return $block_content;
	}

	$lang = pll_current_language('slug');

	if (! in_array($lang, ['uk', 'en'], true)) {
		return $block_content;
	}

	return str_replace('>Weiterlesen<', '>' . esc_html(europulse_t('read_more')) . '<', $block_content);
}, 20);

add_filter('gettext', function ($translation, $text, $domain) {
	if (is_admin() || $domain !== 'blocksy' || ! function_exists('pll_current_language')) {
		return $translation;
	}

	$lang = pll_current_language('slug');

	if (! in_array($lang, ['de', 'en', 'uk'], true)) {
		return $translation;
	}

	$map = [
		'Category' => europulse_t('category_label'),
		'No results' => europulse_t('no_results'),
	];

	return $map[$text] ?? $translation;
}, 20, 3);

add_filter('get_the_archive_title', function ($title, $original_title = '', $prefix = '') {
	if (is_admin() || ! is_category() || ! function_exists('pll_current_language') || ! function_exists('europulse_t')) {
		return $title;
	}

	$lang = pll_current_language('slug');
	if (! in_array($lang, ['de', 'en', 'uk'], true)) {
		return $title;
	}

	$label = esc_html(europulse_t('category_label'));
	$updated = preg_replace(
		'/(<span\b[^>]*\bclass=(["\'])[^"\']*\bct-title-label\b[^"\']*\2[^>]*>)[^<]*(<\/span>)/i',
		'$1' . $label . '$3',
		(string) $title,
		1
	);

	return is_string($updated) && $updated !== '' ? $updated : $title;
}, 20, 3);

add_filter('posts_search', function ($search, $query) {
	global $wpdb;

	if (is_admin() || ! $query->is_main_query() || ! $query->is_search()) {
		return $search;
	}

	$term = trim((string) $query->get('s'));

	if ($term === '') {
		return $search;
	}

	$like = '%' . $wpdb->esc_like($term) . '%';

	return ' AND ('
		. $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $like)
		. ' OR '
		. $wpdb->prepare("{$wpdb->posts}.post_excerpt LIKE %s", $like)
		. ' OR '
		. $wpdb->prepare("{$wpdb->posts}.post_content LIKE %s", $like)
		. ') ';
}, 20, 2);

add_filter('render_block_core/post-date', function ($block_content) {
	if (is_admin() || $block_content === '') {
		return $block_content;
	}

	if (! preg_match('/datetime="([^"]+)"/', $block_content, $matches)) {
		return $block_content;
	}

	try {
		$datetime = new DateTimeImmutable($matches[1]);
	} catch (Exception $e) {
		return $block_content;
	}

	$formatted = europulse_format_timestamp($datetime->getTimestamp());

	return preg_replace_callback(
		'/(<time\b[^>]*>)(.*?)(<\/time>)/u',
		static function ($time_matches) use ($formatted) {
			return $time_matches[1] . esc_html($formatted) . $time_matches[3];
		},
		$block_content,
		1
	) ?: $block_content;
}, 20);

add_filter('get_the_date', function ($the_date, $format, $post) {
	if (is_admin()) {
		return $the_date;
	}

	$post_id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;

	if (! $post_id) {
		return $the_date;
	}

	if (is_home() && ! is_front_page()) {
		return get_post_time('H:i', false, $post_id);
	}

	return europulse_format_post_date($post_id);
}, 20, 3);

add_filter('blocksy:post-meta:items', function ($items) {
	if (is_admin() || ! is_string($items) || $items === '') {
		return $items;
	}

	return preg_replace(
		'/(<\/a>)\s*,\s*(<a\b[^>]*>)/u',
		'$1<span class="europulse-entry-term-separator" aria-hidden="true">•</span>$2',
		$items
	) ?: $items;
}, 20);

add_filter('render_block_core/post-terms', function ($block_content, $block) {
	if (is_admin() || $block_content === '') {
		return $block_content;
	}

	$term = $block['attrs']['term'] ?? 'category';

	if ($term !== 'category') {
		return $block_content;
	}

	$post_id = (int) ($block['context']['postId'] ?? 0);

	if (! $post_id) {
		$post_id = (int) get_the_ID();
	}

	if (! $post_id) {
		return $block_content;
	}

	$rendered = europulse_get_term_links_html($post_id, 'category');

	return $rendered !== '' ? $rendered : '';
}, 20, 2);

add_filter('render_block_core/video', function ($block_content, $block) {
	if (is_admin() || ! is_singular('post') || ! str_contains($block_content, '<video')) {
		return $block_content;
	}

	if (preg_match('/<video\b[^>]*\bposter=/i', $block_content)) {
		return $block_content;
	}

	$poster = europulse_video_poster_url((int) get_the_ID());

	if (! $poster) {
		return $block_content;
	}

	$replacement = '<video class="wp-video-shortcode" controls playsinline preload="metadata" poster="' . esc_url($poster) . '" ';

	return preg_replace('/<video\s+/i', $replacement, $block_content, 1) ?: $block_content;
}, 20, 2);

add_action('wp_head', function () {
	if (is_admin()) {
		return;
	}

	$label = europulse_t('ad');
	printf(
		'<style id="europulse-sponsored-label">.wp-block-post.europulse-sponsored .wp-block-post-title::before,.entry-card.europulse-sponsored .entry-title::before{content:"%s" !important;}</style>',
		esc_html($label)
	);
}, 30);

add_filter('rank_math/frontend/canonical', function ($canonical) {
	if (is_admin()) {
		return $canonical;
	}

	if (europulse_should_force_self_canonical()) {
		return europulse_self_canonical_url();
	}

	return $canonical;
}, 30);

function europulse_current_rank_math_post_id(): int {
	$post_id = (int) get_queried_object_id();
	if ($post_id <= 0 && isset($GLOBALS['post']) && $GLOBALS['post'] instanceof WP_Post) {
		$post_id = (int) $GLOBALS['post']->ID;
	}
	if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
		return 0;
	}
	return $post_id;
}

add_filter('rank_math/frontend/description', function ($description) {
	if (is_admin()) {
		return $description;
	}
	$post_id = europulse_current_rank_math_post_id();
	if ($post_id > 0) {
		$mirror_desc = trim((string) get_post_meta($post_id, '_epv2_meta_desc', true));
		if ($mirror_desc !== '') {
			return $mirror_desc;
		}
	}
	if (is_front_page() || is_home()) {
		$lang = function_exists('europulse_current_lang') ? europulse_current_lang() : 'de';
		$descriptions = [
			'de' => 'EuroPulse berichtet ueber Deutschland, Europa, die Welt, die Ukraine, Wirtschaft, Kultur, Sport und Community-Themen in drei Sprachen.',
			'en' => 'EuroPulse covers Germany, Europe, the world, Ukraine, economy, culture, sport and community stories in three languages.',
			'uk' => 'EuroPulse висвітлює Німеччину, Європу, світ, Україну, економіку, культуру, спорт і теми спільноти трьома мовами.',
		];
		return $descriptions[$lang] ?? $descriptions['de'];
	}
	return $description;
}, 30);

add_filter('rank_math/frontend/title', function ($title) {
	if (is_admin()) {
		return $title;
	}
	$post_id = europulse_current_rank_math_post_id();
	if ($post_id <= 0) {
		return $title;
	}
	$mirror_title = trim((string) get_post_meta($post_id, '_epv2_seo_title', true));
	return $mirror_title !== '' ? $mirror_title : $title;
}, 30);

add_filter('rank_math/frontend/robots', function ($robots) {
	if (is_admin() || ! is_singular('post')) {
		return $robots;
	}

	$post_id = get_queried_object_id();
	if ($post_id <= 0) {
		return $robots;
	}

	$decision = sanitize_key((string) get_post_meta($post_id, 'europulse_selection_decision', true));
	$score = (int) get_post_meta($post_id, 'europulse_selection_score', true);
	$is_breaking = (int) get_post_meta($post_id, 'europulse_breaking', true) === 1;
	$is_top_story = (int) get_post_meta($post_id, 'europulse_top_story', true) === 1;

	if ($is_breaking || $is_top_story) {
		return $robots;
	}

	$should_noindex = $decision === 'reject'
		|| $decision === 'low'
		|| ($decision === 'review' && $score > 0 && $score < 40);

	if (! $should_noindex) {
		return $robots;
	}

	$robots = is_array($robots) ? array_values(array_unique(array_map('strval', $robots))) : [];
	$robots = array_values(array_filter($robots, static function (string $directive): bool {
		$directive = strtolower(trim($directive));
		return $directive !== 'index' && $directive !== 'noindex';
	}));
	array_unshift($robots, 'follow');
	array_unshift($robots, 'noindex');

	return array_values(array_unique($robots));
}, 30);

add_filter('rank_math/sitemap/entry', function ($url, $type, $object) {
	if ($type !== 'post' || ! is_object($object) || empty($object->ID)) {
		return $url;
	}

	$post_id = (int) $object->ID;
	$decision = sanitize_key((string) get_post_meta($post_id, 'europulse_selection_decision', true));
	$score = (int) get_post_meta($post_id, 'europulse_selection_score', true);
	$is_breaking = (int) get_post_meta($post_id, 'europulse_breaking', true) === 1;
	$is_top_story = (int) get_post_meta($post_id, 'europulse_top_story', true) === 1;

	if ($is_breaking || $is_top_story) {
		return $url;
	}

	if ($decision === 'reject' || $decision === 'low' || ($decision === 'review' && $score > 0 && $score < 40)) {
		return [];
	}

	return $url;
}, 30, 3);

add_filter('rank_math/sitemap/post_sitemap_url', function ($url, $generator) {
	$loc = is_array($url) ? (string) ($url['loc'] ?? '') : '';
	$output = is_object($generator) && method_exists($generator, 'sitemap_url')
		? (string) $generator->sitemap_url(is_array($url) ? $url : [])
		: '';

	if ($loc === '') {
		return $output;
	}

	$post_id = url_to_postid($loc);
	if ($post_id <= 0) {
		return $output;
	}

	$decision = sanitize_key((string) get_post_meta($post_id, 'europulse_selection_decision', true));
	$score = (int) get_post_meta($post_id, 'europulse_selection_score', true);
	$is_breaking = (int) get_post_meta($post_id, 'europulse_breaking', true) === 1;
	$is_top_story = (int) get_post_meta($post_id, 'europulse_top_story', true) === 1;

	if ($is_breaking || $is_top_story) {
		return $output;
	}

	if ($decision === 'reject' || $decision === 'low' || ($decision === 'review' && $score > 0 && $score < 40)) {
		return '';
	}

	return $output;
}, 30, 2);

add_filter('rank_math/opengraph/url', function ($url) {
	if (is_admin()) {
		return $url;
	}

	if (europulse_should_force_self_canonical()) {
		return europulse_self_canonical_url();
	}

	return $url;
}, 30);

function europulse_home_og_image_url(): string {
	$posts = get_posts([
		'post_type' => 'post',
		'post_status' => 'publish',
		'numberposts' => 1,
		'orderby' => 'date',
		'order' => 'DESC',
		'no_found_rows' => true,
	]);
	if (! empty($posts[0])) {
		$thumb = get_the_post_thumbnail_url((int) $posts[0]->ID, 'full');
		if (is_string($thumb) && $thumb !== '') {
			return $thumb;
		}
	}
	$site_icon = get_site_icon_url(512);
	return is_string($site_icon) ? $site_icon : '';
}

add_action('wp_head', function () {
	if (is_admin() || (! is_front_page() && ! is_home())) {
		return;
	}
	$image = europulse_home_og_image_url();
	if ($image === '') {
		return;
	}
	echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
	echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
}, 31);

add_filter('rank_math/json_ld', function ($data, $jsonld = null) {
	if (is_admin() || ! europulse_should_force_self_canonical() || ! is_array($data)) {
		return $data;
	}

	$canonical = europulse_self_canonical_url();
	$canonical_root = untrailingslashit($canonical);
	$is_home_context = is_front_page() || is_home();
	$site_root = home_url('/');
	$site_root_id = untrailingslashit($site_root) . '/#website';
	$org_root_id = untrailingslashit($site_root) . '/#organization';
	$graph =& $data;

	if (isset($data['@graph']) && is_array($data['@graph'])) {
		$graph =& $data['@graph'];
	}

	foreach ($graph as $index => &$entity) {
		if (! is_array($entity)) {
			continue;
		}

		$type = $entity['@type'] ?? '';
		$types = is_array($type) ? $type : [$type];

		if ($is_home_context && in_array('NewsArticle', $types, true)) {
			unset($data[$index]);
			continue;
		}

		$is_org = $type === 'Organization'
			|| $type === 'NewsMediaOrganization'
			|| (is_array($type) && in_array('Organization', $type, true))
			|| (is_array($type) && in_array('NewsMediaOrganization', $type, true));
		$is_website = $type === 'WebSite' || (is_array($type) && in_array('WebSite', $type, true));

		if (isset($entity['url'])) {
			if ($is_org || $is_website) {
				$entity['url'] = $site_root;
			} else {
				$entity['url'] = $canonical;
			}
		}

		if (isset($entity['@id']) && is_string($entity['@id'])) {
			if (str_ends_with($entity['@id'], '#website')) {
				$entity['@id'] = $site_root_id;
			} elseif (str_ends_with($entity['@id'], '#webpage')) {
				$entity['@id'] = $canonical_root . '#webpage';
			} elseif (str_ends_with($entity['@id'], '#richSnippet')) {
				$entity['@id'] = $canonical_root . '#richSnippet';
			} elseif (str_ends_with($entity['@id'], '#organization')) {
				$entity['@id'] = $org_root_id;
			}
		}

		if (isset($entity['isPartOf']['@id']) && is_string($entity['isPartOf']['@id']) && str_ends_with($entity['isPartOf']['@id'], '#website')) {
			$entity['isPartOf']['@id'] = $site_root_id;
		}

		if (isset($entity['mainEntityOfPage']['@id']) && is_string($entity['mainEntityOfPage']['@id']) && str_ends_with($entity['mainEntityOfPage']['@id'], '#webpage')) {
			$entity['mainEntityOfPage']['@id'] = $canonical_root . '#webpage';
		}

		if ($is_home_context && isset($entity['headline'])) {
			unset($entity['headline']);
		}
	}
	unset($entity);

	if (isset($data['@graph']) && is_array($data['@graph'])) {
		$data['@graph'] = array_values($graph);
		return $data;
	}

	return array_values($graph);
}, 30, 2);

add_action('init', function () {
	remove_action('wp_head', 'rsd_link');
	remove_action('wp_head', 'wlwmanifest_link');
	if (class_exists('RankMath') || function_exists('rank_math')) {
		remove_action('wp_head', 'rel_canonical');
	}
}, 30);

add_action('blocksy:single:content:top', function () {
	if (! is_single() || get_post_type() !== 'post') {
		return;
	}

	$excerpt = trim(get_the_excerpt());
	$thumbnail_id = get_post_thumbnail_id();
	$caption = $thumbnail_id ? europulse_attachment_credit($thumbnail_id) : '';
	$image = $thumbnail_id ? wp_get_attachment_image($thumbnail_id, 'large', false, [
		'class' => 'europulse-article-image',
		'loading' => 'eager',
	]) : '';

	if (europulse_is_sponsored()) {
		echo '<div class="europulse-sponsored-note is-width-constrained"><strong>' . esc_html(europulse_t('ad')) . '.</strong> ' . esc_html(europulse_t('ad_note')) . '</div>';
	}

	if ($image) {
		echo '<figure class="europulse-article-figure is-width-constrained">';
		echo wp_kses_post($image);

		if ($caption) {
			printf(
				'<figcaption class="europulse-photo-credit">%s</figcaption>',
				wp_kses_post($caption)
			);
		}

		echo '</figure>';
	} elseif ($caption) {
		printf(
			'<div class="europulse-photo-credit is-width-constrained">%s</div>',
			wp_kses_post($caption)
		);
	}
});

add_action('blocksy:single:content:bottom', function () {
	if (! is_single() || get_post_type() !== 'post') {
		return;
	}

	$sources = get_post_meta(get_the_ID(), 'europulse_sources', true);

	if (empty($sources) || ! is_array($sources)) {
		return;
	}

	echo '<section class="europulse-sources is-width-constrained">';
	echo '<h2>Quellen</h2>';
	echo '<ul>';

	foreach ($sources as $source) {
		if (empty($source['url']) || empty($source['label'])) {
			continue;
		}

		printf(
			'<li><a href="%s" rel="nofollow noopener" target="_blank">%s</a></li>',
			esc_url($source['url']),
			esc_html($source['label'])
		);
	}

	echo '</ul>';
	echo '</section>';
});

add_action('wp_footer', function () {
	$search_label = europulse_t('search');
	$back_to_top_label = europulse_t('back_to_top');
	$current_lang = europulse_current_lang();
	?>
	<div class="europulse-search-popover" data-europulse-search-popover aria-hidden="true">
		<form class="europulse-search-popover-form" action="<?php echo esc_url(home_url('/')); ?>" method="get">
			<input class="europulse-search-popover-input" type="search" name="s" placeholder="<?php echo esc_attr($search_label); ?>" aria-label="<?php echo esc_attr($search_label); ?>" />
			<input type="hidden" name="lang" value="<?php echo esc_attr($current_lang); ?>" />
			<button class="europulse-search-popover-submit" type="submit"><?php echo esc_html($search_label); ?></button>
		</form>
	</div>
	<button class="europulse-back-to-top" type="button" aria-label="<?php echo esc_attr($back_to_top_label); ?>" data-europulse-back-to-top>
		<span aria-hidden="true">↑</span>
	</button>
	<script>
		const mobileSearchMarkup = <?php echo wp_json_encode(sprintf(
			'<form class="europulse-mobile-menu-search" action="%s" method="get"><input class="europulse-mobile-menu-search-input" type="search" name="s" placeholder="%s" aria-label="%s" /><input type="hidden" name="lang" value="%s" /><button class="europulse-mobile-menu-search-submit" type="submit">%s</button></form>',
			esc_url(home_url('/')),
			esc_attr($search_label),
			esc_attr($search_label),
			esc_attr($current_lang),
			esc_html($search_label)
		)); ?>;
		const searchPopover = document.querySelector('[data-europulse-search-popover]');
		const searchInput = searchPopover?.querySelector('.europulse-search-popover-input');
		const searchToggles = Array.from(document.querySelectorAll('.ct-header-search'));
		const backToTop = document.querySelector('[data-europulse-back-to-top]');
		const mobileMenuInner = document.querySelector('#offcanvas .ct-panel-content[data-device="mobile"] .ct-panel-content-inner');

		const closeSearchPopover = () => {
			if (! searchPopover) {
				return;
			}

			searchPopover.classList.remove('is-visible');
			searchPopover.setAttribute('aria-hidden', 'true');
		};

		const openSearchPopover = (toggle) => {
			if (! searchPopover || ! toggle) {
				return;
			}

			const rect = toggle.getBoundingClientRect();
			const width = Math.min(360, window.innerWidth - 24);
			let left = rect.right - width;

			if (left < 12) {
				left = 12;
			}

			searchPopover.style.top = `${Math.max(68, rect.bottom + 10)}px`;
			searchPopover.style.left = `${left}px`;
			searchPopover.style.right = 'auto';
			searchPopover.style.width = `${width}px`;
			searchPopover.classList.add('is-visible');
			searchPopover.setAttribute('aria-hidden', 'false');
			window.setTimeout(() => searchInput?.focus(), 40);
		};

		searchToggles.forEach((toggle) => {
			toggle.addEventListener('click', (event) => {
				event.preventDefault();
				event.stopPropagation();

				if (searchPopover?.classList.contains('is-visible')) {
					closeSearchPopover();
					return;
				}

				openSearchPopover(toggle);
			}, true);
		});

		document.addEventListener('click', (event) => {
			if (! searchPopover?.classList.contains('is-visible')) {
				return;
			}

			if (event.target.closest('[data-europulse-search-popover]') || event.target.closest('.ct-header-search')) {
				return;
			}

			closeSearchPopover();
		});

		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				closeSearchPopover();
			}
		});

		window.addEventListener('resize', closeSearchPopover);

		if (mobileMenuInner && ! mobileMenuInner.querySelector('.europulse-mobile-menu-search')) {
			mobileMenuInner.insertAdjacentHTML('afterbegin', mobileSearchMarkup);
		}

		if (backToTop) {
			const syncBackToTop = () => {
				backToTop.classList.toggle('is-visible', window.scrollY > 640);
			};

			backToTop.addEventListener('click', () => {
				window.scrollTo({ top: 0, behavior: 'smooth' });
			});

			window.addEventListener('scroll', syncBackToTop, { passive: true });
			syncBackToTop();
		}

		document.querySelectorAll('[data-europulse-slider]').forEach((slider) => {
			const slides = Array.from(slider.querySelectorAll('.europulse-top-slide'));
			const dots = Array.from(slider.querySelectorAll('[data-slider-dot]'));
			const prev = slider.querySelector('[data-slider-prev]');
			const next = slider.querySelector('[data-slider-next]');
			let current = 0;
			let timer = null;
			let touchStartX = 0;
			let touchStartY = 0;
			let touchDeltaX = 0;
			let touchActive = false;

			if (slides.length < 2) {
				if (prev) prev.hidden = true;
				if (next) next.hidden = true;
				return;
			}

			const show = (index) => {
				current = (index + slides.length) % slides.length;
				slides.forEach((slide, slideIndex) => {
					slide.classList.toggle('is-active', slideIndex === current);
				});
				dots.forEach((dot, dotIndex) => {
					dot.classList.toggle('is-active', dotIndex === current);
				});
			};

			const restart = () => {
				window.clearInterval(timer);
				timer = window.setInterval(() => show(current + 1), 6000);
			};

			prev?.addEventListener('click', () => {
				show(current - 1);
				restart();
			});
			next?.addEventListener('click', () => {
				show(current + 1);
				restart();
			});
			dots.forEach((dot, index) => dot.addEventListener('click', () => {
				show(index);
				restart();
			}));

			slider.addEventListener('mouseenter', () => window.clearInterval(timer));
			slider.addEventListener('mouseleave', restart);

			slider.addEventListener('touchstart', (event) => {
				const touch = event.changedTouches?.[0];

				if (! touch) {
					return;
				}

				touchActive = true;
				touchStartX = touch.clientX;
				touchStartY = touch.clientY;
				touchDeltaX = 0;
				window.clearInterval(timer);
			}, { passive: true });

			slider.addEventListener('touchmove', (event) => {
				if (! touchActive) {
					return;
				}

				const touch = event.changedTouches?.[0];

				if (! touch) {
					return;
				}

				touchDeltaX = touch.clientX - touchStartX;
				const deltaY = touch.clientY - touchStartY;

				if (Math.abs(touchDeltaX) > Math.abs(deltaY) && Math.abs(touchDeltaX) > 12) {
					event.preventDefault();
				}
			}, { passive: false });

			slider.addEventListener('touchend', (event) => {
				if (! touchActive) {
					return;
				}

				const touch = event.changedTouches?.[0];
				touchActive = false;

				if (! touch) {
					restart();
					return;
				}

				const deltaX = touch.clientX - touchStartX;
				const deltaY = touch.clientY - touchStartY;

				if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 48) {
					show(deltaX < 0 ? current + 1 : current - 1);
				}

				restart();
			}, { passive: true });

			slider.addEventListener('touchcancel', () => {
				touchActive = false;
				restart();
			}, { passive: true });
			restart();
		});

		const offcanvas = document.querySelector('#offcanvas');

		if (offcanvas) {
			let offcanvasStartX = 0;
			let offcanvasStartY = 0;

			offcanvas.addEventListener('touchstart', (event) => {
				const touch = event.changedTouches?.[0];

				if (! touch) {
					return;
				}

				offcanvasStartX = touch.clientX;
				offcanvasStartY = touch.clientY;
			}, { passive: true });

			offcanvas.addEventListener('touchend', (event) => {
				const touch = event.changedTouches?.[0];

				if (! touch) {
					return;
				}

				const deltaX = touch.clientX - offcanvasStartX;
				const deltaY = touch.clientY - offcanvasStartY;

				if (deltaX > 48 && Math.abs(deltaX) > Math.abs(deltaY)) {
					document.querySelector('.ct-toggle-close')?.click();
				}
			}, { passive: true });
		}
	</script>
	<?php
}, 100);
