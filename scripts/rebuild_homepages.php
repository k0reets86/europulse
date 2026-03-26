<?php

$wp_load = '/var/www/europulse/public/wp-load.php';

if (! file_exists($wp_load)) {
	fwrite(STDERR, "wp-load.php not found\n");
	exit(1);
}

require $wp_load;

$pages = [
	29 => [
		'lang' => 'de',
		'latest_title' => 'Neueste Meldungen',
		'latest_link' => '/?page_id=28',
		'all_label' => 'Alle Artikel',
		'all_news_label' => 'Alle Nachrichten',
		'secondary' => 'Analyse & Hintergründe',
		'sections' => [
			['title' => 'Deutschland', 'cat' => 14, 'layout' => 'split', 'list' => 3],
			['title' => 'Ukraine', 'cat' => 16, 'layout' => 'split', 'list' => 3],
			['title' => 'Europa', 'cat' => 18, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Politik', 'cat' => 22, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Wirtschaft', 'cat' => 24, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Leben in Deutschland', 'cat' => 26, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Community', 'cat' => 46, 'layout' => 'split', 'list' => 4, 'class' => 'europulse-community-module'],
		],
	],
	33 => [
		'lang' => 'uk',
		'latest_title' => 'Останні новини',
		'latest_link' => '/?page_id=276&lang=uk',
		'all_label' => 'Усі матеріали',
		'all_news_label' => 'Усі новини',
		'secondary' => 'Аналітика та контекст',
		'sections' => [
			['title' => 'Німеччина', 'cat' => 14, 'layout' => 'split', 'list' => 3],
			['title' => 'Україна', 'cat' => 16, 'layout' => 'split', 'list' => 3],
			['title' => 'Європа', 'cat' => 18, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Політика', 'cat' => 22, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Економіка', 'cat' => 24, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Життя в Німеччині', 'cat' => 26, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Спільнота', 'cat' => 46, 'layout' => 'split', 'list' => 4, 'class' => 'europulse-community-module'],
		],
	],
	275 => [
		'lang' => 'en',
		'latest_title' => 'Latest News',
		'latest_link' => '/?page_id=277&lang=en',
		'all_label' => 'All Articles',
		'all_news_label' => 'All News',
		'secondary' => 'Analysis & Background',
		'sections' => [
			['title' => 'Germany', 'cat' => 14, 'layout' => 'split', 'list' => 3],
			['title' => 'Ukraine', 'cat' => 16, 'layout' => 'split', 'list' => 3],
			['title' => 'Europe', 'cat' => 18, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Politics', 'cat' => 22, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Economy', 'cat' => 24, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Life in Germany', 'cat' => 26, 'layout' => 'grid', 'grid' => 4],
			['title' => 'Community', 'cat' => 46, 'layout' => 'split', 'list' => 4, 'class' => 'europulse-community-module'],
		],
	],
];

function eu_build_section_shortcode(array $section, string $all_label): string {
	$parts = [
		'category="' . (int) $section['cat'] . '"',
		'title="' . esc_attr($section['title']) . '"',
		'all_label="' . esc_attr($all_label) . '"',
		'layout="' . esc_attr($section['layout']) . '"',
	];

	if (! empty($section['list'])) {
		$parts[] = 'list_posts="' . (int) $section['list'] . '"';
	}

	if (! empty($section['grid'])) {
		$parts[] = 'grid_posts="' . (int) $section['grid'] . '"';
	}

	if (! empty($section['class'])) {
		$parts[] = 'module_class="' . esc_attr($section['class']) . '"';
	}

	return '[europulse_section_module ' . implode(' ', $parts) . ']';
}

function eu_build_home_content(array $config): string {
	$section_blocks = [];

	for ($i = 0; $i < count($config['sections']); $i += 2) {
		$left = $config['sections'][$i] ?? null;
		$right = $config['sections'][$i + 1] ?? null;

		if ($right) {
			$section_blocks[] = <<<HTML
<!-- wp:columns {"className":"europulse-sections-row"} -->
<div class="wp-block-columns europulse-sections-row"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:shortcode -->
{LEFT}
<!-- /wp:shortcode --></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:shortcode -->
{RIGHT}
<!-- /wp:shortcode --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
HTML;
			$section_blocks[count($section_blocks) - 1] = str_replace(
				['{LEFT}', '{RIGHT}'],
				[eu_build_section_shortcode($left, $config['all_label']), eu_build_section_shortcode($right, $config['all_label'])],
				$section_blocks[count($section_blocks) - 1]
			);
		} elseif ($left) {
			$section_blocks[] = "<!-- wp:shortcode -->\n" . eu_build_section_shortcode($left, $config['all_label']) . "\n<!-- /wp:shortcode -->";
		}
	}

	$sections_markup = implode("\n\n<!-- wp:shortcode -->\n[europulse_ad_slot slot=\"homepage-between-sections\"]\n<!-- /wp:shortcode -->\n\n", array_slice($section_blocks, 0, 2))
		. "\n\n<!-- wp:shortcode -->\n[europulse_ad_slot slot=\"homepage-between-sections\"]\n<!-- /wp:shortcode -->\n\n"
		. implode("\n\n", array_slice($section_blocks, 2));

	return <<<HTML
<!-- wp:group {"align":"full","className":"europulse-home-shell","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull europulse-home-shell"><!-- wp:group {"align":"wide","className":"europulse-home-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide europulse-home-hero"><!-- wp:columns {"verticalAlignment":"top","className":"europulse-home-top","style":{"spacing":{"blockGap":{"left":"32px"}}}} -->
<div class="wp-block-columns are-vertically-aligned-top europulse-home-top"><!-- wp:column {"verticalAlignment":"top","width":"68%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:68%"><!-- wp:shortcode -->
[europulse_top_slider posts="5"]
<!-- /wp:shortcode --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"top","width":"32%"} -->
<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:32%"><!-- wp:group {"className":"europulse-utility-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group europulse-utility-card"><!-- wp:paragraph {"className":"europulse-kicker"} -->
<p class="europulse-kicker">Wichtig im Blick</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Ein schneller Überblick über häufig gelesene und dauerhaft relevante Themen.</p>
<!-- /wp:paragraph -->

<!-- wp:shortcode -->
[europulse_most_read posts="5" thumbs="1"]
<!-- /wp:shortcode -->
</div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:group {"align":"wide","className":"europulse-latest-block","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide europulse-latest-block"><!-- wp:group {"className":"europulse-block-head","layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
<div class="wp-block-group europulse-block-head"><!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">{$config['latest_title']}</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><a href="{$config['latest_link']}">{$config['all_news_label']}</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:shortcode -->
[europulse_home_latest posts="6"]
<!-- /wp:shortcode --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"wide","className":"europulse-secondary-strip","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide europulse-secondary-strip"><!-- wp:paragraph {"className":"europulse-kicker"} -->
<p class="europulse-kicker">{$config['secondary']}</p>
<!-- /wp:paragraph -->

<!-- wp:shortcode -->
[europulse_analysis_block posts="3" fallback_posts="3" offset="1"]
<!-- /wp:shortcode --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"wide","className":"europulse-sections-grid","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide europulse-sections-grid">
{$sections_markup}
</div>
<!-- /wp:group -->
<!-- /wp:group -->
HTML;
}

foreach ($pages as $page_id => $config) {
	wp_update_post([
		'ID' => $page_id,
		'post_content' => eu_build_home_content($config),
	]);
}

echo "Homepages rebuilt.\n";
