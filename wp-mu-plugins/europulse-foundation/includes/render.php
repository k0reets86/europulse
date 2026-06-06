<?php

function europulse_fragment_cache_key(string $fragment, array $parts = []): string {
	$last_changed = function_exists('wp_cache_get_last_changed') ? (string) wp_cache_get_last_changed('posts') : '';
	if ($last_changed === '') {
		$last_changed = (string) get_lastpostmodified('GMT');
	}

	$parts = array_merge(
		[
			'fragment' => sanitize_key($fragment),
			'lang' => function_exists('europulse_current_lang') ? europulse_current_lang() : 'de',
			'posts_changed' => $last_changed,
		],
		$parts
	);

	return 'europulse_frag_' . md5((string) wp_json_encode($parts));
}

function europulse_fragment_cache_get(string $key): ?string {
	$cached = get_transient($key);

	return is_string($cached) && $cached !== '' ? $cached : null;
}

function europulse_fragment_cache_set(string $key, string $html, int $ttl = 600): string {
	if ($html !== '') {
		set_transient($key, $html, max(60, $ttl));
	}

	return $html;
}

function europulse_render_post_media_link(int $post_id, string $size, string $class, string $placeholder_class): string {
	$title = trim(wp_strip_all_tags(get_the_title($post_id)));
	$aria_label = $title !== '' ? $title : europulse_t('read_more');
	$thumbnail = get_the_post_thumbnail(
		$post_id,
		$size,
		[
			'class' => $class,
			'alt' => $title,
			'loading' => 'lazy',
		]
	);

	if (! $thumbnail) {
		return '';
	}

	$video = europulse_has_video($post_id)
		? '<span class="europulse-video-play europulse-video-play--thumb" aria-hidden="true"></span>'
		: '';

	return '<a class="' . esc_attr($class . '-link') . '" href="' . esc_url(get_permalink($post_id)) . '" aria-label="' . esc_attr($aria_label) . '">' . $thumbnail . $video . '</a>';
}

function europulse_render_section_compact_card(int $post_id): string {
	$title = get_the_title($post_id);

	return '<article class="europulse-compact-card">'
		. europulse_render_post_media_link($post_id, 'medium_large', 'europulse-compact-image', 'europulse-compact-image europulse-compact-image--empty')
		. '<div class="europulse-story-meta-wrap">' . europulse_render_story_meta($post_id) . '</div>'
		. europulse_label_chip($post_id)
		. '<h3 class="wp-block-post-title has-medium-font-size"><a href="' . esc_url(get_permalink($post_id)) . '">' . esc_html($title) . '</a></h3>'
		. '</article>';
}

function europulse_render_secondary_card(int $post_id): string {
	$title = get_the_title($post_id);
	$story_topic = europulse_story_topic_for_post($post_id);
	$topic_markup = $story_topic !== ''
		? '<p class="europulse-story-topic">' . esc_html($story_topic) . '</p>'
		: '';

	return '<article class="europulse-secondary-card">'
		. europulse_render_post_media_link($post_id, 'medium_large', 'europulse-secondary-image', 'europulse-secondary-image europulse-secondary-image--empty')
		. europulse_render_story_meta($post_id)
		. europulse_label_chip($post_id)
		. $topic_markup
		. '<h3 class="wp-block-post-title"><a href="' . esc_url(get_permalink($post_id)) . '">' . esc_html($title) . '</a></h3>'
		. '<p class="wp-block-post-excerpt">' . esc_html(europulse_context_excerpt($post_id, 'latest')) . '</p>'
		. '</article>';
}

function europulse_render_section_list_card(int $post_id): string {
	$title = get_the_title($post_id);

	return '<article class="europulse-section-list-card">'
		. '<div class="europulse-section-list-copy">'
		. europulse_render_story_meta($post_id)
		. europulse_label_chip($post_id)
		. '<h3 class="wp-block-post-title has-medium-font-size"><a href="' . esc_url(get_permalink($post_id)) . '">' . esc_html($title) . '</a></h3>'
		. '<p class="europulse-section-list-excerpt">' . esc_html(europulse_context_excerpt($post_id, 'latest')) . '</p>'
		. '</div></article>';
}

add_shortcode('europulse_ad_slot', function ($atts) {
	$atts = shortcode_atts(
		[
			'slot' => 'generic',
			'class' => '',
		],
		$atts,
		'europulse_ad_slot'
	);

	return europulse_render_ad_slot($atts['slot'], $atts['class']);
});

add_shortcode('europulse_most_read', function ($atts) {
	$atts = shortcode_atts(
		[
			'posts' => 5,
			'title' => '',
			'thumbs' => 0,
		],
		$atts,
		'europulse_most_read'
	);

	$limit = max(1, (int) $atts['posts']);
	$cache_key = europulse_fragment_cache_key('most_read', [
		'limit' => $limit,
		'thumbs' => ! empty($atts['thumbs']) ? 1 : 0,
		'today' => wp_date('Y-m-d', current_time('timestamp')),
	]);
	$cached = europulse_fragment_cache_get($cache_key);
	if ($cached !== null) {
		return $cached;
	}

	$slider_ids = europulse_home_zone_ids('slider', 3);
	$latest_ids = europulse_home_zone_ids('latest', 6, $slider_ids);
	$analysis_ids = europulse_home_zone_ids('analysis', 3, array_merge($slider_ids, $latest_ids));
	$post_ids = europulse_home_zone_ids('most_read', $limit, array_merge($slider_ids, $latest_ids, $analysis_ids));

	if ($post_ids === []) {
		return europulse_fragment_cache_set($cache_key, '<p>' . esc_html(europulse_t('most_read_empty')) . '</p>', 300);
	}

	$query = new WP_Query([
		'post_type' => 'post',
		'post_status' => 'publish',
		'post__in' => $post_ids,
		'posts_per_page' => count($post_ids),
		'orderby' => 'post__in',
		'ignore_sticky_posts' => true,
		'lang' => europulse_current_lang(),
	]);

	ob_start();
	?>
	<ul class="europulse-most-read-list">
		<?php
		$index = 1;
		while ($query->have_posts()) {
			$query->the_post();
			?>
			<li class="europulse-most-read-item<?php echo ! empty($atts['thumbs']) ? ' europulse-most-read-item--with-thumb' : ''; ?>">
				<?php if (! empty($atts['thumbs'])) : ?>
					<a class="europulse-most-read-thumb" href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr(get_the_title()); ?>">
						<?php
						if (has_post_thumbnail()) {
							the_post_thumbnail('thumbnail', ['loading' => 'lazy', 'alt' => get_the_title()]);
						} else {
							echo '<span class="europulse-most-read-thumb-placeholder"></span>';
						}

						if (europulse_has_video()) {
							echo '<span class="europulse-video-play europulse-video-play--thumb" aria-hidden="true"></span>';
						}
						?>
					</a>
				<?php else : ?>
					<span class="europulse-most-read-rank"><?php echo esc_html($index); ?></span>
				<?php endif; ?>
				<div class="europulse-most-read-copy">
					<?php echo europulse_label_chip(); ?>
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					<span class="europulse-most-read-meta"><?php echo esc_html(europulse_format_post_date(get_the_ID(), 'dd.MM.yyyy')); ?></span>
				</div>
			</li>
			<?php
			$index++;
		}
		wp_reset_postdata();
		?>
	</ul>
	<?php

	return europulse_fragment_cache_set($cache_key, trim(ob_get_clean()), 300);
});

add_shortcode('europulse_latest_list', function ($atts) {
	$atts = shortcode_atts(
		[
			'posts' => 5,
			'thumbs' => 0,
		],
		$atts,
		'europulse_latest_list'
	);

	$current_page = max(1, isset($_GET['ep_page']) ? (int) $_GET['ep_page'] : 0);
	$per_page = max(1, (int) $atts['posts']);
	$today_label = europulse_t('today');
	$block_title = europulse_t('all_news');
	$today_key = wp_date('Y-m-d', current_time('timestamp'));
	$groups = [];
	$max_num_pages = 1;
	$request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
	$request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
	$cache_key = europulse_fragment_cache_key('latest_list', [
		'per_page' => $per_page,
		'current_page' => $current_page,
		'thumbs' => ! empty($atts['thumbs']) ? 1 : 0,
		'today' => $today_key,
		'request_path' => $request_path,
	]);
	$cached = europulse_fragment_cache_get($cache_key);
	if ($cached !== null) {
		return $cached;
	}

	$add_post_to_groups = static function (int $post_id, array $entry = []) use (&$groups, $today_key, $today_label): void {
		$post = get_post($post_id);
		if (! $post || $post->post_status !== 'publish') {
			return;
		}
		if (! europulse_home_post_is_eligible($post_id, $post)) {
			return;
		}
		$localized_terms = europulse_get_localized_terms($post_id, 'category');
		$category = '';
		$category_slug = '';
		if ($localized_terms !== []) {
			$category = (string) $localized_terms[0]->name;
			$category_slug = sanitize_html_class((string) $localized_terms[0]->slug);
		}
		$post_local_key = get_post_time('Y-m-d', false, $post_id);
		if ($post_local_key === $today_key) {
			$group_key = 'today';
			$group_label = $today_label;
		} else {
			$group_key = $post_local_key;
			$group_label = europulse_format_post_date($post_id);
		}
		if (! isset($groups[$group_key])) {
			$groups[$group_key] = [
				'label' => $group_label,
				'items' => [],
			];
		}
		$groups[$group_key]['items'][] = [
			'id' => $post_id,
			'title' => get_the_title($post_id),
			'permalink' => get_permalink($post_id),
			'time' => get_post_time('H:i', false, $post_id),
			'date' => europulse_format_post_date($post_id, 'dd.MM.yyyy'),
			'category' => $category,
			'category_slug' => $category_slug,
			'has_video' => array_key_exists('has_video', $entry) ? (bool) $entry['has_video'] : europulse_has_video($post_id),
			'timestamp' => (int) get_post_time('U', false, $post_id),
		];
	};

	$pool = function_exists('europulse_autopilot_home_pool') ? europulse_autopilot_home_pool() : [];
	if ($pool !== []) {
		uasort($pool, static function (array $a, array $b): int {
			return (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0);
		});
		$post_ids = array_map('intval', array_keys($pool));
		$max_num_pages = max(1, (int) ceil(count($post_ids) / $per_page));
		$current_page = min($current_page, $max_num_pages);
		$page_ids = array_slice($post_ids, ($current_page - 1) * $per_page, $per_page);
		foreach ($page_ids as $post_id) {
			$add_post_to_groups((int) $post_id, (array) ($pool[$post_id] ?? []));
		}
	} else {
		$query = new WP_Query([
			'post_type' => 'post',
			'post_status' => 'publish',
			'posts_per_page' => $per_page,
			'paged' => $current_page,
			'ignore_sticky_posts' => true,
			'lang' => europulse_current_lang(),
			'meta_query' => [
				[
					'key' => '_epv2_queue_id',
					'compare' => 'EXISTS',
				],
			],
		]);

		if (! $query->have_posts()) {
			return europulse_fragment_cache_set($cache_key, '<p>' . esc_html(europulse_t('latest_empty')) . '</p>', 300);
		}
		$max_num_pages = max(1, (int) $query->max_num_pages);
		while ($query->have_posts()) {
			$query->the_post();
			$add_post_to_groups(get_the_ID());
		}
		wp_reset_postdata();
	}

	if ($groups === []) {
		return europulse_fragment_cache_set($cache_key, '<p>' . esc_html(europulse_t('latest_empty')) . '</p>', 300);
	}

	ob_start();
	echo '<section class="europulse-latest-stream">';
	echo '<header class="europulse-latest-stream-header"><h2>' . esc_html($block_title) . '</h2></header>';

	$render_group = static function (string $label, array $items): void {
		if ($items === []) {
			return;
		}
		echo '<div class="europulse-latest-stream-group">';
		echo '<h3 class="europulse-latest-stream-group-title">' . esc_html($label) . '</h3>';
		echo '<ul class="europulse-latest-widget-list europulse-latest-widget-list--stream">';
		foreach ($items as $item) {
			echo '<li class="europulse-latest-widget-item europulse-latest-widget-item--stream">';
			echo '<div class="europulse-latest-widget-copy">';
			echo '<div class="europulse-latest-widget-side">';
			echo '<span class="europulse-latest-widget-time">' . esc_html($item['time']) . '</span>';
			echo '</div>';
			echo '<div class="europulse-latest-widget-line">';
			echo '<a href="' . esc_url($item['permalink']) . '">' . esc_html($item['title']) . '</a>';
			if ($item['category'] !== '') {
				echo '<span class="europulse-latest-widget-meta">' . esc_html($item['category']) . '</span>';
			}
			echo '</div>';
			echo '</div>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
	};

	foreach ($groups as $group) {
		$render_group((string) $group['label'], (array) $group['items']);
	}

	if ($max_num_pages > 1) {
		$pagination_base_url = $request_path !== '' ? home_url($request_path) : home_url('/');
		$pagination = paginate_links([
			'base' => esc_url_raw(add_query_arg('ep_page', '%#%', $pagination_base_url)),
			'format' => '',
			'current' => $current_page,
			'total' => $max_num_pages,
			'prev_text' => '&larr;',
			'next_text' => '&rarr;',
			'type' => 'list',
		]);
		if (is_string($pagination) && $pagination !== '') {
			echo '<nav class="europulse-latest-pagination" aria-label="' . esc_attr(europulse_t('all_news')) . '">';
			echo $pagination;
			echo '</nav>';
		}
	}

	echo '</section>';

	return europulse_fragment_cache_set($cache_key, trim(ob_get_clean()), 300);
});

add_shortcode('europulse_home_latest', function ($atts) {
	$atts = shortcode_atts(
		[
			'posts' => 6,
		],
		$atts,
		'europulse_home_latest'
	);

	$limit = max(1, (int) $atts['posts']);
	$slider_ids = europulse_home_zone_ids('slider', 3);
	$post_ids = europulse_home_zone_ids('latest', $limit, $slider_ids);

	$query = new WP_Query([
		'post_type' => 'post',
		'post_status' => 'publish',
		'post__in' => $post_ids,
		'orderby' => 'post__in',
		'posts_per_page' => count($post_ids),
		'ignore_sticky_posts' => true,
		'lang' => europulse_current_lang(),
	]);

	if (! $query->have_posts()) {
		return '<p>' . esc_html(europulse_t('home_latest_empty')) . '</p>';
	}

	ob_start();
	echo '<div class="europulse-home-latest-grid">';

	while ($query->have_posts()) {
		$query->the_post();
		echo '<article class="europulse-home-latest-card">';
		if (has_post_thumbnail()) {
			echo '<a class="europulse-home-latest-thumb" href="' . esc_url(get_permalink()) . '" aria-label="' . esc_attr(get_the_title()) . '">';
			echo get_the_post_thumbnail(get_the_ID(), 'medium_large', ['loading' => 'lazy', 'alt' => get_the_title()]);
			if (europulse_has_video()) {
				echo '<span class="europulse-video-play europulse-video-play--thumb" aria-hidden="true"></span>';
			}
			echo '</a>';
		}
		echo '<div class="europulse-home-latest-copy">';
		echo '<div class="europulse-story-meta">';
		echo '<span>' . esc_html(europulse_format_post_date(get_the_ID())) . '</span>';
		echo wp_kses_post(europulse_get_term_links_html(get_the_ID(), 'category'));
		echo '</div>';
		echo europulse_label_chip();
		echo '<h3 class="europulse-home-latest-title"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3>';
		echo '<p class="europulse-home-latest-excerpt">' . esc_html(europulse_context_excerpt(get_the_ID(), 'latest')) . '</p>';
		echo '</div>';
		echo '</article>';
	}

	wp_reset_postdata();
	echo '</div>';

	return trim(ob_get_clean());
});

add_shortcode('europulse_secondary_stories', function ($atts) {
	$atts = shortcode_atts(
		[
			'posts' => 3,
			'offset' => 1,
		],
		$atts,
		'europulse_secondary_stories'
	);

	$query = new WP_Query([
		'post_type' => 'post',
		'post_status' => 'publish',
		'posts_per_page' => max(1, (int) $atts['posts']),
		'offset' => max(0, (int) $atts['offset']),
		'ignore_sticky_posts' => true,
		'lang' => europulse_current_lang(),
		'meta_query' => [
			[
				'key' => '_epv2_queue_id',
				'compare' => 'EXISTS',
			],
		],
	]);

	if (! $query->have_posts()) {
		return '<p>' . esc_html(europulse_t('secondary_empty')) . '</p>';
	}

	ob_start();
	echo '<div class="europulse-secondary-grid"><div class="wp-block-post-template">';
	while ($query->have_posts()) {
		$query->the_post();
		echo europulse_render_secondary_card(get_the_ID());
	}
	wp_reset_postdata();
	echo '</div></div>';

	return trim(ob_get_clean());
});

add_shortcode('europulse_analysis_block', function ($atts) {
	$atts = shortcode_atts(
		[
			'posts' => 3,
			'fallback_posts' => 3,
			'offset' => 1,
		],
		$atts,
		'europulse_analysis_block'
	);

	$exclude = array_merge(
		europulse_home_zone_ids('slider', 3),
		europulse_home_zone_ids('latest', 6, europulse_home_zone_ids('slider', 3))
	);
	$post_ids = europulse_home_zone_ids('analysis', max(1, (int) $atts['posts']), $exclude);

	if ($post_ids === []) {
		return '<p>' . esc_html(europulse_t('analysis_empty')) . '</p>';
	}

	ob_start();
	echo '<div class="europulse-secondary-grid"><div class="wp-block-post-template">';
	foreach ($post_ids as $post_id) {
		echo europulse_render_secondary_card((int) $post_id);
	}
	echo '</div></div>';

	return trim(ob_get_clean());
});

add_shortcode('europulse_section_module', function ($atts) {
	$atts = shortcode_atts(
		[
			'category' => 0,
			'title' => '',
			'all_label' => 'Alle Artikel',
			'all_url' => '',
			'list_posts' => 3,
			'grid_posts' => 4,
			'layout' => 'split',
			'module_class' => '',
		],
		$atts,
		'europulse_section_module'
	);

	$base_term_id = (int) $atts['category'];

	if (! $base_term_id) {
		return '';
	}

	$term_id = europulse_localized_term_id($base_term_id);
	$term = get_term($term_id, 'category');

	if (! $term || is_wp_error($term)) {
		return '';
	}

	$title = $atts['title'] !== '' ? $atts['title'] : $term->name;
	$all_url = $atts['all_url'] !== '' ? $atts['all_url'] : get_term_link($term);

	$layout = in_array($atts['layout'], ['split', 'grid'], true) ? $atts['layout'] : 'split';
	$canonical_slug = class_exists('EPV2_Taxonomy_Map') ? EPV2_Taxonomy_Map::canonical_slug_for_term_id($term_id) : '';
	if ($canonical_slug !== '') {
		$lead_ids = europulse_home_section_ids($canonical_slug, 1, 0);
		$list_ids = europulse_home_section_ids($canonical_slug, max(1, (int) $atts['list_posts']), 1, $lead_ids);
		$grid_ids = europulse_home_section_ids($canonical_slug, max(2, (int) $atts['grid_posts']), 0);
	} else {
		$lead_ids = europulse_category_post_ids_for_current_lang($term_id, 1, 0);
		$list_ids = europulse_category_post_ids_for_current_lang($term_id, max(1, (int) $atts['list_posts']), 1);
		$grid_ids = europulse_category_post_ids_for_current_lang($term_id, max(2, (int) $atts['grid_posts']), 0);
	}

	if ($lead_ids === [] && $list_ids === [] && $grid_ids === []) {
		return '';
	}

	ob_start();
	?>
	<div class="wp-block-group europulse-section-module <?php echo esc_attr($atts['module_class']); ?>">
		<div class="wp-block-group europulse-block-head is-content-justification-space-between is-layout-flex wp-block-group-is-layout-flex">
			<h2 class="wp-block-heading"><?php echo esc_html($title); ?></h2>
			<p><a href="<?php echo esc_url($all_url); ?>"><?php echo esc_html($atts['all_label']); ?></a></p>
		</div>
		<?php if ('grid' === $layout) : ?>
			<div class="europulse-card-grid">
				<div class="wp-block-post-template">
					<?php if ($grid_ids !== []) : ?>
						<?php foreach ($grid_ids as $grid_id) : ?>
							<?php echo europulse_render_section_compact_card($grid_id); ?>
						<?php endforeach; ?>
					<?php else : ?>
						<p><?php echo esc_html(sprintf(europulse_t('section_empty'), $title)); ?></p>
					<?php endif; ?>
				</div>
			</div>
		<?php else : ?>
			<div class="wp-block-columns europulse-section-layout">
				<div class="wp-block-column" style="flex-basis:52%">
					<div class="wp-block-query europulse-section-lead">
						<?php if ($lead_ids !== []) : $lead_id = (int) $lead_ids[0]; ?>
							<?php echo get_the_post_thumbnail($lead_id, 'large', ['class' => 'wp-post-image', 'loading' => 'lazy', 'alt' => get_the_title($lead_id)]); ?>
							<?php echo europulse_render_story_meta($lead_id); ?>
							<?php echo europulse_label_chip($lead_id); ?>
								<h3 class="wp-block-post-title"><a href="<?php echo esc_url(get_permalink($lead_id)); ?>"><?php echo esc_html(get_the_title($lead_id)); ?></a></h3>
							<div class="wp-block-post-excerpt"><?php echo esc_html(europulse_context_excerpt($lead_id, 'default')); ?></div>
						<?php else : ?>
							<p><?php echo esc_html(sprintf(europulse_t('section_empty'), $title)); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<div class="wp-block-column" style="flex-basis:48%">
					<div class="wp-block-query europulse-section-list">
						<?php if ($list_ids !== []) : ?>
							<?php foreach ($list_ids as $list_id) : ?>
								<?php echo europulse_render_section_list_card($list_id); ?>
							<?php endforeach; ?>
						<?php else : ?>
							<p><?php echo esc_html(sprintf(europulse_t('section_more_soon'), $title)); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php

	return trim(ob_get_clean());
});

add_shortcode('europulse_breaking_ticker', function ($atts) {
	$atts = shortcode_atts(
		[
			'posts' => 6,
		],
		$atts,
		'europulse_breaking_ticker'
	);

	$want = max(1, (int) $atts['posts']);
	$slider_ids = europulse_home_zone_ids('slider', max(3, $want));

	// Operator-feedback 2026-05-10: ticker must always show FRESH news.
	// Editorial flags (europulse_breaking / europulse_top_story) fire
	// rarely (score ≥ 72/64) and old flagged posts from weeks/months ago
	// would otherwise fill all 6 slots, hiding today's actual news.
	//
	// New strategy: query latest published posts in the last 24 h
	// directly. Inside that window, breaking/top_story bubble up first
	// as priority signal; remaining slots fill by date DESC.
	$items = [];
	$seen_ids = [];

	$fresh_query = new WP_Query([
		'post_type' => 'post',
		'post_status' => 'publish',
		'posts_per_page' => max(24, $want * 4),
		'orderby' => ['date' => 'DESC'],
		'ignore_sticky_posts' => true,
		'lang' => europulse_current_lang(),
		'date_query' => [
			[
				'after' => '24 hours ago',
				'inclusive' => true,
			],
		],
	]);

	$fresh_priority = []; // breaking / top_story within 24h
	$fresh_regular = [];
	while ($fresh_query->have_posts()) {
		$fresh_query->the_post();
		$id = get_the_ID();
		if (in_array($id, $slider_ids, true)) {
			continue;
		}
		if (isset($seen_ids[$id])) {
			continue;
		}
		$seen_ids[$id] = true;
		$entry = sprintf(
			'<a href="%s">%s</a>',
			esc_url(get_permalink()),
			esc_html(get_the_title())
		);
		$is_priority = ((int) get_post_meta($id, 'europulse_breaking', true) === 1)
			|| ((int) get_post_meta($id, 'europulse_top_story', true) === 1);
		if ($is_priority) {
			$fresh_priority[] = $entry;
		} else {
			$fresh_regular[] = $entry;
		}
	}
	wp_reset_postdata();

	// Priority first, then regular fresh — capped at $want total.
	foreach ($fresh_priority as $entry) {
		if (count($items) >= $want) break;
		$items[] = $entry;
	}
	foreach ($fresh_regular as $entry) {
		if (count($items) >= $want) break;
		$items[] = $entry;
	}

	// Fallback B: slider IDs (last resort, kept for backwards compat).
	if ($items === []) {
		foreach ($slider_ids as $fallback_id) {
			if (isset($seen_ids[$fallback_id])) {
				continue;
			}
			$seen_ids[$fallback_id] = true;
			$items[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(get_permalink($fallback_id)),
				esc_html(get_the_title($fallback_id))
			);
			if (count($items) >= $want) {
				break;
			}
		}
	}

	if ($items === []) {
		return '';
	}

	$marquee_group = implode('<span class="europulse-breaking-sep">•</span>', $items);
	$marquee_group_duplicate = preg_replace('/<a\b/i', '<a tabindex="-1" aria-hidden="true"', $marquee_group) ?: $marquee_group;

	return sprintf(
		'<div class="europulse-breaking-bar"><div class="ct-container"><span class="europulse-breaking-label">%s</span><div class="europulse-breaking-track"><div class="europulse-breaking-marquee"><span class="europulse-breaking-marquee-group">%s</span><span class="europulse-breaking-marquee-group" aria-hidden="true">%s</span></div></div></div></div>',
		esc_html(europulse_t('breaking')),
		$marquee_group,
		$marquee_group_duplicate
	);
});

add_shortcode('europulse_top_slider', function ($atts) {
	$atts = shortcode_atts(
		[
			'posts' => 3,
		],
		$atts,
		'europulse_top_slider'
	);

	$post_ids = europulse_home_zone_ids('slider', max(1, (int) $atts['posts']));

	if ($post_ids === []) {
		return '<div class="europulse-top-slider europulse-top-slider--empty"><p>' . esc_html(europulse_t('slider_empty')) . '</p></div>';
	}

	ob_start();
	?>
	<div class="europulse-top-slider" data-europulse-slider>
		<div class="europulse-top-slider-track">
			<?php
			$index = 0;
			foreach ($post_ids as $slider_post_id) {
				$slider_post_id = (int) $slider_post_id;
				if ($slider_post_id <= 0 || ! get_post($slider_post_id)) {
					continue;
				}
				$thumbnail = get_the_post_thumbnail(
					$slider_post_id,
					'large',
					[
						'class' => 'europulse-top-slide-image',
						'alt' => get_the_title($slider_post_id),
						'loading' => $index === 0 ? 'eager' : 'lazy',
					]
				);

				if (! $thumbnail) {
					$thumbnail = sprintf(
						'<div class="europulse-top-slide-image europulse-top-slide-image--empty" aria-hidden="true"></div>'
					);
				}
				?>
				<article class="europulse-top-slide<?php echo 0 === $index ? ' is-active' : ''; ?><?php echo europulse_has_video($slider_post_id) ? ' europulse-top-slide--video' : ''; ?>" data-slide="<?php echo esc_attr($index); ?>">
					<a class="europulse-top-slide-media" href="<?php echo esc_url(get_permalink($slider_post_id)); ?>" aria-label="<?php echo esc_attr(get_the_title($slider_post_id)); ?>">
						<?php echo wp_kses_post($thumbnail); ?>
						<?php if (europulse_has_video($slider_post_id)) : ?>
							<span class="europulse-video-play europulse-video-play--hero" aria-hidden="true"></span>
						<?php endif; ?>
					</a>
					<div class="europulse-top-slide-panel">
						<div class="europulse-top-slide-badge">
						<div class="europulse-top-slide-meta">
							<span class="europulse-top-slide-date"><?php echo esc_html(europulse_format_post_date($slider_post_id)); ?></span>
							<span class="europulse-top-slide-terms"><?php echo esc_html(europulse_get_term_names_text($slider_post_id, 'category')); ?></span>
						</div>
						<?php if (europulse_is_sponsored($slider_post_id)) : ?>
							<span class="europulse-sponsored-chip"><?php echo esc_html(europulse_t('ad')); ?></span>
						<?php elseif (europulse_is_breaking($slider_post_id)) : ?>
							<span class="europulse-breaking-chip"><?php echo esc_html(europulse_t('breaking')); ?></span>
						<?php elseif (europulse_is_top_story($slider_post_id)) : ?>
							<span class="europulse-kicker"><?php echo esc_html(europulse_t('top_story')); ?></span>
						<?php endif; ?>
						</div>
						<?php $slider_headline = europulse_slider_headline($slider_post_id); ?>
						<h2 class="europulse-top-slide-title <?php echo esc_attr(europulse_title_length_class($slider_headline)); ?>"><a href="<?php echo esc_url(get_permalink($slider_post_id)); ?>"><?php echo esc_html($slider_headline); ?></a></h2>
						<div class="europulse-top-slide-excerpt"><?php echo esc_html(europulse_context_excerpt($slider_post_id, 'slider')); ?></div>
					</div>
				</article>
				<?php
				$index++;
			}
			?>
		</div>
		<div class="europulse-top-slider-controls">
			<button type="button" class="europulse-top-slider-arrow" data-slider-prev aria-label="<?php echo esc_attr(europulse_t('previous_topic')); ?>"><?php echo esc_html(europulse_t('previous')); ?></button>
			<div class="europulse-top-slider-dots" aria-label="<?php echo esc_attr(europulse_t('slider_navigation')); ?>">
				<?php for ($i = 0; $i < $index; $i++) : ?>
					<button type="button" class="europulse-top-slider-dot<?php echo 0 === $i ? ' is-active' : ''; ?>" data-slider-dot="<?php echo esc_attr($i); ?>" aria-label="<?php echo esc_attr(sprintf(europulse_t('slider_topic_aria'), $i + 1)); ?>"></button>
				<?php endfor; ?>
			</div>
			<button type="button" class="europulse-top-slider-arrow" data-slider-next aria-label="<?php echo esc_attr(europulse_t('next_topic')); ?>"><?php echo esc_html(europulse_t('next')); ?></button>
		</div>
	</div>
	<?php

	return trim(ob_get_clean());
});

add_action('blocksy:header:before', function () {
	$language_switcher = europulse_render_lang_switcher();
	$social_links = europulse_social_links();
	?>
	<div class="europulse-utility-bar">
		<div class="ct-container">
			<div class="europulse-utility-start">
				<span class="europulse-utility-date"><?php echo esc_html(europulse_format_timestamp(time(), 'EEE, dd MMM yyyy')); ?></span>
				<span class="europulse-utility-time"><?php echo wp_kses_post(europulse_render_utility_times()); ?></span>
				<span class="europulse-utility-lang"><?php echo wp_kses_post($language_switcher); ?></span>
			</div>

			<div class="europulse-utility-end">
				<div class="europulse-social-links" aria-label="EuroPulse Social Media">
					<?php foreach ($social_links as $network => $url) : ?>
						<?php
						$label = ucfirst($network);
						$class = empty($url) ? ' is-disabled' : '';
						$tag = empty($url) ? 'span' : 'a';
						?>
						<<?php echo $tag; ?>
							<?php if ('a' === $tag) : ?>
								href="<?php echo esc_url($url); ?>"
								target="_blank"
								rel="noopener noreferrer"
							<?php endif; ?>
							class="europulse-social-link europulse-social-link--<?php echo esc_attr($network . $class); ?>"
							aria-label="<?php echo esc_attr($label); ?>"
						><?php echo wp_kses(europulse_social_icon($network), [
							'svg' => [
								'viewBox' => true,
								'aria-hidden' => true,
							],
							'path' => [
								'd' => true,
								'fill' => true,
							],
						]); ?></<?php echo $tag; ?>>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
	<?php
});

add_action('blocksy:header:after', function () {
	echo do_shortcode('[europulse_breaking_ticker posts="6"]');
	echo europulse_render_ad_slot('header-leaderboard', 'europulse-ad-slot--header');
}, 15);

add_action('blocksy:sidebar:start', function () {
	if (! is_single()) {
		return;
	}

	echo europulse_render_ad_slot('sidebar-rail', 'europulse-ad-slot--rail');
});
