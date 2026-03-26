<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Publisher {
	public static function publish(object $item): int {
		$de = json_decode((string) ($item->de_payload ?? ''), true);
		$media = json_decode((string) ($item->publish_payload ?? ''), true);
		$context = json_decode((string) ($item->context_payload ?? ''), true);
		$uk = json_decode((string) ($item->uk_payload ?? ''), true);
		$en = json_decode((string) ($item->en_payload ?? ''), true);
		$de = is_array($de) ? $de : [];
		$media = is_array($media) ? $media : [];
		$context = is_array($context) ? $context : [];
		$uk = is_array($uk) ? $uk : [];
		$en = is_array($en) ? $en : [];
		$quality = EPV3_Quality_Validator::evaluate($de, $context, $media, $uk, $en);
		if (empty($quality['publish_ready'])) {
			throw new RuntimeException('Quality gate failed: ' . implode(', ', (array) ($quality['warnings'] ?? [])));
		}

		$content = self::build_publish_content($de);

		$postarr = [
			'post_title' => (string) ($de['title'] ?? $item->original_title ?? 'Untitled'),
			'post_excerpt' => (string) ($de['lead'] ?? ''),
			'post_content' => $content,
			'post_status' => (string) EPV3_Settings::get('default_post_status', 'draft'),
			'post_type' => 'post',
			'post_name' => (string) (($quality['seo']['slug'] ?? '') ?: sanitize_title((string) ($de['title'] ?? ''))),
		];

		$post_id = wp_insert_post(wp_slash($postarr), true);
		if (is_wp_error($post_id)) {
			throw new RuntimeException($post_id->get_error_message());
		}

		self::assign_taxonomy($post_id, $context, $de, $quality);
		self::attach_featured_media($post_id, $media);

		if (! empty($media['featured_url'])) {
			update_post_meta($post_id, '_epv3_featured_source_url', esc_url_raw((string) $media['featured_url']));
			update_post_meta($post_id, '_epv3_featured_attribution', sanitize_text_field((string) ($media['attribution'] ?? '')));
			update_post_meta($post_id, '_epv3_featured_attribution_url', esc_url_raw((string) ($media['attribution_url'] ?? '')));
		}
		update_post_meta($post_id, '_epv3_context_score', (int) ($quality['context_score'] ?? 0));
		update_post_meta($post_id, '_epv3_seo_score', (int) ($quality['seo_score'] ?? 0));
		update_post_meta($post_id, '_epv3_google_score', (int) ($quality['google_score'] ?? 0));
		update_post_meta($post_id, '_epv3_release_score', (int) ($quality['release_score'] ?? 0));
		update_post_meta($post_id, '_epv3_seo_title', sanitize_text_field((string) ($quality['seo']['seo_title'] ?? '')));
		update_post_meta($post_id, '_epv3_meta_description', sanitize_text_field((string) ($quality['seo']['meta_description'] ?? '')));
		update_post_meta($post_id, '_epv3_focus_keywords', wp_json_encode((array) ($quality['seo']['focus_keywords'] ?? []), JSON_UNESCAPED_UNICODE));

		return (int) $post_id;
	}

	private static function build_publish_content(array $de): string {
		$content = trim((string) ($de['content'] ?? ''));
		$quotes = (array) ($de['quotes'] ?? []);
		$citations = (array) ($de['citations'] ?? []);
		$source_block = [];

		if ($quotes !== []) {
			$source_block[] = '<h3>Zitate</h3><ul>';
			foreach ($quotes as $quote) {
				$source_block[] = '<li>' . esc_html((string) $quote) . '</li>';
			}
			$source_block[] = '</ul>';
		}

		if ($citations !== []) {
			$source_block[] = '<h3>Quellen</h3><ul>';
			foreach ($citations as $citation) {
				$title = esc_html((string) ($citation['title'] ?? 'Quelle'));
				$url = esc_url((string) ($citation['url'] ?? ''));
				$source_block[] = $url !== '' ? '<li><a href="' . $url . '">' . $title . '</a></li>' : '<li>' . $title . '</li>';
			}
			$source_block[] = '</ul>';
		}

		return $content . "\n\n" . implode('', $source_block);
	}

	private static function assign_taxonomy(int $post_id, array $context, array $de, array $quality): void {
		$category_slug = sanitize_title((string) ($context['category'] ?? ''));
		if ($category_slug !== '') {
			$term = get_category_by_slug($category_slug);
			if (! $term instanceof WP_Term) {
				$created = wp_insert_term(
					ucwords(str_replace('-', ' ', $category_slug)),
					'category',
					['slug' => $category_slug]
				);
				if (! is_wp_error($created)) {
					$term = get_term((int) $created['term_id'], 'category');
				}
			}
			if ($term instanceof WP_Term) {
				wp_set_post_terms($post_id, [(int) $term->term_id], 'category', false);
			}
		}

		$tags = array_merge(
			array_map('strval', (array) ($context['keywords'] ?? [])),
			array_map('strval', (array) ($context['entities'] ?? [])),
			array_map('strval', (array) (($quality['seo']['focus_keywords'] ?? [])))
		);
		$tags = array_slice(array_values(array_unique(array_filter(array_map(static function (string $tag): string {
			$tag = trim(wp_strip_all_tags($tag));
			return mb_strlen($tag) >= 3 ? $tag : '';
		}, $tags)))), 0, 12);
		if ($tags !== []) {
			wp_set_post_terms($post_id, $tags, 'post_tag', false);
		}

		if (! empty($de['lang'])) {
			update_post_meta($post_id, '_epv3_lang', sanitize_text_field((string) $de['lang']));
		}
	}

	private static function attach_featured_media(int $post_id, array $media): void {
		$url = esc_url_raw((string) ($media['featured_url'] ?? ''));
		if ($url === '' || has_post_thumbnail($post_id)) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url($url, 20);
		if (is_wp_error($tmp)) {
			update_post_meta($post_id, '_epv3_featured_import_error', $tmp->get_error_message());
			return;
		}

		$filename = wp_basename(parse_url($url, PHP_URL_PATH) ?: 'epv3-image.jpg');
		$file_array = [
			'name' => sanitize_file_name($filename),
			'tmp_name' => $tmp,
		];
		$attachment_id = media_handle_sideload($file_array, $post_id, (string) ($media['attribution'] ?? 'EPV3 featured media'));
		if (is_wp_error($attachment_id)) {
			@unlink($tmp);
			update_post_meta($post_id, '_epv3_featured_import_error', $attachment_id->get_error_message());
			return;
		}

		set_post_thumbnail($post_id, (int) $attachment_id);
		update_post_meta($post_id, '_epv3_featured_attachment_id', (int) $attachment_id);
	}
}
