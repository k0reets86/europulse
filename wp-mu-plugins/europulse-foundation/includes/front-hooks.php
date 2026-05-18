<?php

add_action('wp_enqueue_scripts', function () {
	$path = WPMU_PLUGIN_DIR . '/europulse-foundation.css';

	if (! file_exists($path)) {
		return;
	}

	wp_enqueue_style(
		'europulse-foundation',
		content_url('/mu-plugins/europulse-foundation.css'),
		[],
		(string) filemtime($path)
	);

	$normalize_path = WPMU_PLUGIN_DIR . '/europulse-normalize.css';

	if (file_exists($normalize_path)) {
		wp_enqueue_style(
			'europulse-normalize',
			content_url('/mu-plugins/europulse-normalize.css'),
			['europulse-foundation'],
			(string) filemtime($normalize_path)
		);
	}

	if (is_singular('post') && europulse_has_video()) {
		wp_enqueue_style('wp-mediaelement');
		wp_enqueue_script('wp-mediaelement');
		wp_add_inline_script(
			'wp-mediaelement',
			"document.addEventListener('DOMContentLoaded',function(){if(!window.MediaElementPlayer){return;}document.querySelectorAll('.entry-content video, .wp-block-video video').forEach(function(video){if(video.dataset.europulseMejs==='1'){return;}video.dataset.europulseMejs='1';try{new MediaElementPlayer(video,{stretching:'responsive'});}catch(e){}});});",
			'after'
		);
	}
}, 20);

add_filter('blocksy:footer:current_section_id', function ($section_id, $value) {
	if (is_array($value) && ! empty($value['current_section'])) {
		return $value['current_section'];
	}

	return $section_id;
}, 10, 2);

add_filter('wp_nav_menu_args', function ($args) {
	if (! empty($args['theme_location']) && in_array($args['theme_location'], ['menu_1', 'menu_mobile'], true)) {
		$lang = function_exists('pll_current_language') ? pll_current_language('slug') : 'de';
		$menu = (int) get_option('europulse_primary_menu_' . $lang, 0);

		if (! $menu) {
			$menu = (int) get_option('europulse_primary_menu_de', 37);
		}

		$args['menu'] = $menu;
	}

	return $args;
});

add_filter('nav_menu_link_attributes', function ($atts, $item, $args, $depth) {
	if (! is_object($item) || (($item->type ?? '') !== 'taxonomy') || (($item->object ?? '') !== 'category')) {
		return $atts;
	}

	$term_id = (int) ($item->object_id ?? 0);
	if ($term_id <= 0) {
		return $atts;
	}

	$lang = function_exists('pll_current_language') ? (string) pll_current_language('slug') : 'de';
	if ($lang === '') {
		$lang = 'de';
	}

	if (function_exists('pll_get_term')) {
		$translated = (int) (pll_get_term($term_id, $lang) ?: $term_id);
		if ($translated > 0) {
			$term_id = $translated;
		}
	}

	$link = function_exists('europulse_category_archive_url')
		? europulse_category_archive_url($term_id, $lang)
		: get_term_link($term_id, 'category');
	if (! is_wp_error($link) && is_string($link) && $link !== '') {
		$atts['href'] = $link;
	}

	return $atts;
}, 20, 4);

add_filter('query_loop_block_query_vars', function ($query, $block) {
	if (is_admin() || ! function_exists('pll_current_language')) {
		return $query;
	}

	$lang = pll_current_language('slug');

	if ($lang) {
		$query['lang'] = $lang;
	}

	if (! empty($query['tax_query']) && is_array($query['tax_query'])) {
		foreach ($query['tax_query'] as $index => $tax_query) {
			if (! is_array($tax_query) || empty($tax_query['taxonomy']) || empty($tax_query['terms']) || ! function_exists('pll_get_term')) {
				continue;
			}

			$translated_terms = [];

			foreach ((array) $tax_query['terms'] as $term_id) {
				$translated_terms[] = pll_get_term((int) $term_id, $lang) ?: (int) $term_id;
			}

			$query['tax_query'][$index]['terms'] = array_values(array_filter($translated_terms));
		}
	}

	return $query;
}, 10, 2);

add_filter('post_class', function ($classes, $class, $post_id) {
	if (europulse_is_breaking($post_id)) {
		$classes[] = 'europulse-breaking';
	}

	if (europulse_is_sponsored($post_id)) {
		$classes[] = 'europulse-sponsored';
	}

	if (europulse_has_video($post_id)) {
		$classes[] = 'europulse-has-video';
	}

	return $classes;
}, 10, 3);

add_action('get_template_part_template-parts/content', function ($slug, $name, $args) {
	if ($name !== 'none') {
		return;
	}

	if (! (is_category() || is_tag() || is_tax())) {
		return;
	}

	$lang = function_exists('pll_current_language') ? (string) pll_current_language('slug') : 'de';

	$messages = [
		'de' => [
			'title' => 'Hier entsteht gerade etwas.',
			'body'  => 'In dieser Rubrik gibt es noch keine veröffentlichten Beiträge. Schauen Sie bald wieder vorbei oder besuchen Sie unsere Startseite.',
			'cta'   => 'Zur Startseite',
		],
		'en' => [
			'title' => 'New stories coming soon.',
			'body'  => 'No articles have been published in this section yet. Please check back later or visit the homepage.',
			'cta'   => 'Go to homepage',
		],
		'uk' => [
			'title' => 'Скоро тут з\'являться нові матеріали.',
			'body'  => 'У цій рубриці поки що немає опублікованих матеріалів. Завітайте пізніше або перейдіть на головну сторінку.',
			'cta'   => 'На головну',
		],
	];

	$copy = $messages[$lang] ?? $messages['de'];

	$home = function_exists('pll_home_url') ? pll_home_url($lang) : home_url('/');

	echo '<div class="europulse-empty-archive" style="margin:2rem 0 1rem;padding:1.75rem 1.5rem;background:#f5f7fb;border-radius:8px;text-align:center;">';
	echo '<h2 style="margin:0 0 0.5rem;font-size:1.25rem;color:#1a3268;">' . esc_html($copy['title']) . '</h2>';
	echo '<p style="margin:0 0 1rem;color:#4b5563;">' . esc_html($copy['body']) . '</p>';
	echo '<a href="' . esc_url($home) . '" style="display:inline-block;padding:0.6rem 1.25rem;background:#1a3268;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">' . esc_html($copy['cta']) . '</a>';
	echo '</div>';
}, 10, 3);
