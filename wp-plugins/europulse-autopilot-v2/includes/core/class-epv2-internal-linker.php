<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Internal_Linker {
	public static function suggest(array $categories, string $lang = 'de', int $limit = 3, int $exclude_post_id = 0): array {
		$limit = max(1, min(4, $limit));
		$term_ids = [];
		foreach (array_slice(array_values(array_unique(array_filter($categories))), 0, 3) as $category) {
			$term = EPV2_Taxonomy_Map::map((string) $category, $lang);
			if (! empty($term['term_id'])) {
				$term_ids[] = (int) $term['term_id'];
			}
		}
		if ($term_ids === []) {
			return [];
		}
		$query = new WP_Query([
			'post_type' => 'post',
			'post_status' => 'publish',
			'posts_per_page' => $limit + 2,
			'post__not_in' => $exclude_post_id > 0 ? [$exclude_post_id] : [],
			'category__in' => $term_ids,
			'orderby' => 'date',
			'order' => 'DESC',
			'no_found_rows' => true,
		]);
		$links = [];
		foreach ((array) $query->posts as $post) {
			$links[] = [
				'post_id' => (int) $post->ID,
				'title' => get_the_title($post),
				'url' => get_permalink($post),
			];
			if (count($links) >= $limit) {
				break;
			}
		}
		wp_reset_postdata();
		return $links;
	}

	public static function block(array $links, string $lang = 'de'): string {
		if ($links === []) {
			return '';
		}
		$title = match ($lang) {
			'uk' => 'Читайте також',
			'en' => 'Related reading',
			default => 'Mehr zum Thema',
		};
		$html = '<!-- wp:group {"className":"epv2-related-links"} --><div class="wp-block-group epv2-related-links"><h3>' . esc_html($title) . '</h3><ul>';
		foreach ($links as $link) {
			$html .= '<li><a href="' . esc_url((string) $link['url']) . '">' . esc_html((string) $link['title']) . '</a></li>';
		}
		$html .= '</ul></div><!-- /wp:group -->';
		return $html;
	}
}
