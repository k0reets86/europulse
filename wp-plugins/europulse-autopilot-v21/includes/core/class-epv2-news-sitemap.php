<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_News_Sitemap {
	private const QUERY_VAR = 'epv2_news_sitemap';

	public static function register(): void {
		add_action('plugins_loaded', [self::class, 'maybe_render_early'], 1);
		add_action('init', [self::class, 'register_rewrite'], 5);
		add_filter('query_vars', [self::class, 'register_query_var']);
		add_action('template_redirect', [self::class, 'maybe_render']);
	}

	public static function maybe_render_early(): void {
		if (! self::is_news_sitemap_request()) {
			return;
		}
		self::render();
	}

	public static function register_rewrite(): void {
		add_rewrite_rule('^news-sitemap\.xml$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
	}

	public static function register_query_var(array $vars): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_render(): void {
		if (! self::is_news_sitemap_request() && (int) get_query_var(self::QUERY_VAR) !== 1) {
			return;
		}
		self::render();
	}

	private static function is_news_sitemap_request(): bool {
		$request_path = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
		return $request_path === 'news-sitemap.xml';
	}

	private static function render(): void {
		status_header(200);
		nocache_headers();
		header('Content-Type: application/xml; charset=UTF-8');

		$posts = self::recent_posts();
		$site_name = self::site_name();
		$language = self::site_language();

		echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
			. ' xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"'
			. ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

		foreach ($posts as $post) {
			$post_id = (int) $post->ID;
			$permalink = get_permalink($post_id);
			if (! $permalink) {
				continue;
			}
			// 2026-06-11: принудительный завершающий слеш. В веб-контексте
			// sitemap (запрос без языкового префикса) get_permalink иногда
			// отдаёт URL БЕЗ слеша, а пермалинки сайта — /%postname%/ → 301.
			// Google News отвергает редиректящие <loc> → новости не попадают
			// в индекс. trailingslashit выравнивает с каноническим URL.
			if (strpos($permalink, '?') === false) {
				$permalink = trailingslashit($permalink);
			}
			$title = wp_strip_all_tags(get_the_title($post_id));
			$published_at = get_post_time('c', true, $post_id);
			$keywords = self::keywords_for_post($post_id);
			$post_lang = self::post_language_or_site_language($post_id, $language);
			echo "  <url>\n";
			echo '    <loc>' . esc_xml($permalink) . "</loc>\n";
			echo "    <news:news>\n";
			echo "      <news:publication>\n";
			echo '        <news:name>' . esc_xml($site_name) . "</news:name>\n";
			echo '        <news:language>' . esc_xml($post_lang) . "</news:language>\n";
			echo "      </news:publication>\n";
			echo '      <news:publication_date>' . esc_xml($published_at) . "</news:publication_date>\n";
			echo '      <news:title>' . esc_xml($title) . "</news:title>\n";
			if ($keywords !== '') {
				echo '      <news:keywords>' . esc_xml($keywords) . "</news:keywords>\n";
			}
			echo "    </news:news>\n";
			$image_url = (string) get_the_post_thumbnail_url($post_id, 'large');
			if ($image_url !== '') {
				echo "    <image:image>\n";
				echo '      <image:loc>' . esc_xml($image_url) . "</image:loc>\n";
				echo "    </image:image>\n";
			}
			echo "  </url>\n";
		}

		echo "</urlset>\n";
		exit;
	}

	private static function recent_posts(): array {
		$posts = get_posts([
			'post_type' => 'post',
			'post_status' => 'publish',
			'numberposts' => 100,
			'orderby' => 'date',
			'order' => 'DESC',
			'date_query' => [
				[
					'after' => gmdate('Y-m-d H:i:s', time() - (48 * HOUR_IN_SECONDS)),
					'inclusive' => true,
					'column' => 'post_date_gmt',
				],
			],
		]);

		return array_values(array_filter($posts, static function ($post): bool {
			return $post instanceof WP_Post && self::post_is_news_sitemap_eligible((int) $post->ID);
		}));
	}

	private static function post_is_news_sitemap_eligible(int $post_id): bool {
		if ($post_id <= 0) {
			return false;
		}

		if ((int) get_post_meta($post_id, 'europulse_demo_post', true) === 1) {
			return false;
		}

		if ((int) get_post_meta($post_id, 'europulse_breaking', true) === 1 || (int) get_post_meta($post_id, 'europulse_top_story', true) === 1) {
			return true;
		}

		$queue_id = (int) get_post_meta($post_id, '_epv2_queue_id', true);
		if ($queue_id <= 0) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$row = $wpdb->get_row($wpdb->prepare("SELECT admin_notes FROM {$table} WHERE id = %d", $queue_id), ARRAY_A);
		if (! is_array($row)) {
			return false;
		}

		$notes = json_decode((string) ($row['admin_notes'] ?? ''), true);
		$decision = sanitize_key((string) ($notes['selection']['decision'] ?? ''));
		$score = (int) ($notes['selection']['score'] ?? 0);

		if (in_array($decision, ['priority', 'strong'], true)) {
			return true;
		}

		return $decision === 'review' && $score >= 40;
	}

	private static function site_name(): string {
		$name = trim((string) get_bloginfo('name'));
		return $name !== '' ? $name : 'EuroPulse';
	}

	private static function site_language(): string {
		$lang = strtolower(substr((string) get_bloginfo('language'), 0, 2));
		if ($lang === '') {
			return 'de';
		}
		return $lang;
	}

	private static function post_language_or_site_language(int $post_id, string $fallback): string {
		// Polylang: read the post's actual language so each language version
		// reports its own locale to Google News.
		if (function_exists('pll_get_post_language')) {
			$lang = (string) pll_get_post_language($post_id, 'slug');
			if ($lang !== '') {
				return strtolower(substr($lang, 0, 2));
			}
		}
		return $fallback;
	}

	private static function keywords_for_post(int $post_id): string {
		$keywords = [];
		// Prefer the upfront story_card tags — clean German nouns,
		// LLM-curated, aligned with the rest of the SEO graph.
		$queue_id = (int) get_post_meta($post_id, '_epv2_queue_id', true);
		if ($queue_id > 0) {
			global $wpdb;
			$table = $wpdb->prefix . 'epv2_queue';
			$row = $wpdb->get_row($wpdb->prepare("SELECT ai_payload FROM {$table} WHERE id = %d", $queue_id), ARRAY_A);
			if (is_array($row)) {
				$payload = json_decode((string) ($row['ai_payload'] ?? ''), true);
				if (is_array($payload)) {
					$card_tags = (array) ($payload['_meta']['story_card']['tags'] ?? []);
					foreach ($card_tags as $tag) {
						$tag = trim((string) $tag);
						if ($tag !== '' && ! in_array($tag, $keywords, true)) {
							$keywords[] = $tag;
						}
					}
				}
			}
		}
		// Fall back / supplement with WP post tags.
		$tags = wp_get_post_tags($post_id, ['fields' => 'names']);
		if (is_array($tags)) {
			foreach ($tags as $name) {
				$name = trim((string) $name);
				if ($name !== '' && ! in_array($name, $keywords, true)) {
					$keywords[] = $name;
				}
				if (count($keywords) >= 10) {
					break;
				}
			}
		}
		return implode(', ', array_slice($keywords, 0, 10));
	}
}
