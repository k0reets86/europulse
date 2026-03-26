<?php

if (! defined('ABSPATH')) {
	exit;
}

	final class EPV2_Media {
	public static function resolve_featured_media(string $title, string $excerpt = '', array $categories = [], string $existing_url = '', array $source_dossier = [], int $queue_id = 0): string {
		$story_context = self::story_context_from_dossier($title, $excerpt, $categories, $source_dossier);
		$existing_url = esc_url_raw($existing_url);
		if ($existing_url !== '' && ! self::is_fallback_stock_url($existing_url)) {
			foreach (self::source_image_candidates($existing_url) as $candidate) {
				if (! self::looks_like_image_url($candidate) || self::looks_like_technical_asset($candidate) || self::looks_like_low_value_derivative($candidate)) {
					continue;
				}
				if (! self::recently_used($candidate, 30, 0, $queue_id) && self::media_relevant($candidate, $title, $excerpt, $categories, $source_dossier)) {
					return $candidate;
				}
			}
		}

		$dossier_image = self::source_dossier_image($source_dossier, $title, $excerpt, $categories, $queue_id, $story_context);
		if ($dossier_image !== '') {
			return $dossier_image;
		}

		$parsed_supporting_image = self::parsed_supporting_image($source_dossier, $title, $excerpt, $categories, $queue_id, $story_context);
		if ($parsed_supporting_image !== '') {
			return $parsed_supporting_image;
		}

		$context_supporting_image = self::context_supporting_image($title, $excerpt, $categories, $source_dossier, $queue_id, $story_context);
		if ($context_supporting_image !== '') {
			return $context_supporting_image;
		}

		if ($existing_url !== '') {
			foreach (self::source_image_candidates($existing_url) as $candidate) {
				if (! self::looks_like_image_url($candidate) || self::looks_like_technical_asset($candidate) || self::looks_like_low_value_derivative($candidate)) {
					continue;
				}
				if (! self::recently_used($candidate, 30, 0, $queue_id) && self::media_relevant($candidate, $title, $excerpt, $categories, $source_dossier)) {
					return $candidate;
				}
			}
		}

		$wikimedia_image = self::wikimedia_media($title, $excerpt, $categories, $queue_id, $source_dossier);
		if ($wikimedia_image !== '' && self::media_relevant($wikimedia_image, $title, $excerpt, $categories, $source_dossier)) {
			return $wikimedia_image;
		}

		if (self::prefer_no_stock_fallback($title, $excerpt, $categories)) {
			return '';
		}

		$pexels_image = self::pexels_media($title, $excerpt, $categories, $queue_id, $source_dossier);
		if ($pexels_image !== '' && self::media_relevant($pexels_image, $title, $excerpt, $categories, $source_dossier)) {
			return $pexels_image;
		}

		return '';
	}

	private static function parsed_supporting_image(array $source_dossier, string $title, string $excerpt, array $categories, int $queue_id = 0, array $story_context = []): string {
		$entries = [];
		if (is_array($source_dossier['primary'] ?? null)) {
			$entries[] = (array) $source_dossier['primary'];
		}
		foreach ((array) ($source_dossier['supporting'] ?? []) as $entry) {
			if (is_array($entry)) {
				$entries[] = $entry;
			}
		}
		foreach ($entries as $entry) {
			$url = esc_url_raw((string) ($entry['url'] ?? ''));
			if ($url === '') {
				continue;
			}
			try {
				$doc = EPV2_HTML_Reader::fetch_document($url);
			} catch (Throwable $e) {
				continue;
			}
			$image = esc_url_raw((string) ($doc['image'] ?? ''));
			$allow_recent_reuse = self::entry_allows_recent_reuse($entry, $image);
			if ($image === '' || self::looks_like_technical_asset($image) || self::looks_like_low_value_derivative($image) || (! $allow_recent_reuse && self::recently_used($image, 30, 0, $queue_id))) {
				continue;
			}
			$entryTitle = trim((string) ($doc['title'] ?? ($entry['title'] ?? '')));
			$entryExcerpt = trim((string) ($doc['excerpt'] ?? ($entry['excerpt'] ?? '')));
			if (! self::entry_context_relevant($title, $excerpt, $entryTitle, $entryExcerpt, $categories, $story_context)) {
				continue;
			}
			if (! self::media_relevant($image, trim($entryTitle . ' ' . $title), trim($entryExcerpt . ' ' . $excerpt), $categories, $source_dossier)) {
				continue;
			}
			$validated = self::validate_featured_media($image, 0, $title);
			if (empty($validated['ok'])) {
				continue;
			}
			return $image;
		}
		return '';
	}

	private static function pexels_media(string $title, string $excerpt = '', array $categories = [], int $queue_id = 0, array $source_dossier = []): string {
		$key = (string) (EPV2_Settings::get('image_keys', [])['pexels'] ?? '');
		if ($key === '') {
			return '';
		}
		$query = self::pexels_query($title, $excerpt, $categories, $source_dossier);
		if ($query === '') {
			return '';
		}
		$response = wp_remote_get('https://api.pexels.com/v1/search?per_page=8&orientation=landscape&query=' . rawurlencode($query), [
			'timeout' => 15,
			'headers' => [
				'Authorization' => $key,
			],
		]);
		if (is_wp_error($response)) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return '';
		}
		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		if (! is_array($data) || empty($data['photos'])) {
			return '';
		}
		foreach ((array) $data['photos'] as $photo) {
			$url = esc_url_raw((string) ($photo['src']['large2x'] ?? ''));
			if ($url === '' || self::recently_used($url, 30, 0, $queue_id)) {
				continue;
			}
			return $url;
		}
		return '';
	}

	private static function context_supporting_image(string $title, string $excerpt, array $categories, array $source_dossier, int $queue_id = 0, array $story_context = []): string {
		$queries = self::media_context_queries($story_context);
		if ($queries === []) {
			return '';
		}

		$started_at = microtime(true);
		$time_budget_seconds = 8.0;
		$seen = [];
		foreach (array_slice($queries, 0, 2) as $query) {
			if ((microtime(true) - $started_at) >= $time_budget_seconds) {
				break;
			}
			$feeds = [
				'https://www.bing.com/news/search?q=' . rawurlencode($query) . '&format=rss',
				'https://news.google.com/rss/search?q=' . rawurlencode($query) . '&hl=de&gl=DE&ceid=DE:de',
			];
			foreach ($feeds as $feed) {
				if ((microtime(true) - $started_at) >= $time_budget_seconds) {
					break 2;
				}
				try {
					$candidates = EPV2_Feed_Reader::fetch($feed, str_contains($feed, 'news.google.com'));
				} catch (Throwable $e) {
					continue;
				}
				foreach (array_slice($candidates, 0, 4) as $candidate) {
					if ((microtime(true) - $started_at) >= $time_budget_seconds) {
						break 3;
					}
					$url = esc_url_raw((string) ($candidate['url'] ?? ''));
					if ($url === '' || isset($seen[$url])) {
						continue;
					}
					$seen[$url] = true;
					try {
						$doc = EPV2_HTML_Reader::fetch_document($url);
					} catch (Throwable $e) {
						continue;
					}
					$image = esc_url_raw((string) ($doc['image'] ?? ''));
					if (
						$image === ''
						|| ! self::looks_like_image_url($image)
						|| self::looks_like_technical_asset($image)
						|| self::looks_like_low_value_derivative($image)
						|| self::recently_used($image, 30, 0, $queue_id)
					) {
						continue;
					}
					$entryTitle = trim((string) ($doc['title'] ?? ($candidate['title'] ?? '')));
					$entryExcerpt = trim((string) ($doc['excerpt'] ?? ($candidate['excerpt'] ?? '')));
					if (! self::entry_context_relevant($title, $excerpt, $entryTitle, $entryExcerpt, $categories, $story_context)) {
						continue;
					}
					if (! self::media_relevant($image, trim($entryTitle . ' ' . $title), trim($entryExcerpt . ' ' . $excerpt), $categories, $source_dossier)) {
						continue;
					}
					$validated = self::validate_featured_media($image, 0, $title);
					if (empty($validated['ok'])) {
						continue;
					}
					return $image;
				}
			}
		}

		return '';
	}

	private static function media_context_queries(array $story_context): array {
		$story_context = self::normalize_story_context($story_context);
		$queries = [];
		foreach ((array) ($story_context['phrases'] ?? []) as $phrase) {
			$phrase = trim((string) $phrase);
			if ($phrase === '') {
				continue;
			}
			$queries[] = $phrase;
			$queries[] = $phrase . ' foto';
			$queries[] = $phrase . ' bild';
		}
		$tokenQuery = implode(' ', array_slice((array) ($story_context['tokens'] ?? []), 0, 4));
		if ($tokenQuery !== '') {
			$queries[] = $tokenQuery;
			$queries[] = $tokenQuery . ' foto';
		}
		$queries = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string) $value), $queries))));
		return array_slice($queries, 0, 6);
	}

	public static function normalize_media_list($input): array {
		$items = is_array($input) ? $input : preg_split('/[\r\n,]+/u', (string) $input);
		$items = array_values(array_filter(array_map(static fn($value): string => esc_url_raw(trim((string) $value)), $items)));
		return array_values(array_unique($items));
	}

	public static function attachment_from_reference(string $url, int $post_id = 0): array {
		if ($url === '') {
			return [];
		}

		$attachment_id = attachment_url_to_postid($url);
		if ($attachment_id > 0) {
			$meta = wp_get_attachment_metadata($attachment_id);
			return [
				'attachment_id' => (int) $attachment_id,
				'source' => $url,
				'existing' => true,
				'type' => self::detect_type($url),
				'usable' => self::attachment_is_usable((int) $attachment_id, $meta),
			];
		}

		$existing_by_remote = self::find_existing_attachment_by_remote($url);
		if ($existing_by_remote > 0) {
			$meta = wp_get_attachment_metadata($existing_by_remote);
			return [
				'attachment_id' => (int) $existing_by_remote,
				'source' => $url,
				'existing' => true,
				'type' => self::detect_type($url),
				'usable' => self::attachment_is_usable((int) $existing_by_remote, $meta),
			];
		}

		$attached = self::attach_from_url($url, $post_id);
		if (! empty($attached)) {
			$attached['type'] = self::detect_type($url);
			if (! empty($attached['attachment_id'])) {
				$meta = wp_get_attachment_metadata((int) $attached['attachment_id']);
				$attached['usable'] = self::attachment_is_usable((int) $attached['attachment_id'], $meta);
			}
		}
		return $attached;
	}

	private static function find_existing_attachment_by_remote(string $url): int {
		$fingerprint = self::media_fingerprint($url);

		$posts = get_posts([
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_query' => [
				'relation' => 'OR',
				[
					'key' => '_epv2_remote_source_url',
					'value' => esc_url_raw($url),
				],
				[
					'key' => '_epv2_media_origin_fingerprint',
					'value' => $fingerprint,
				],
			],
		]);

		return ! empty($posts[0]) ? (int) $posts[0] : 0;
	}

	public static function attach_from_url(string $url, int $post_id = 0): array {
		if ($url === '') {
			return [];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url($url);
		if (is_wp_error($tmp)) {
			return [];
		}

		$file = [
			'name' => wp_basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg'),
			'tmp_name' => $tmp,
		];
		$attachment_id = media_handle_sideload($file, $post_id);
		if (is_wp_error($attachment_id)) {
			@unlink($tmp);
			return [];
		}

		update_post_meta((int) $attachment_id, '_epv2_remote_source_url', esc_url_raw($url));
		update_post_meta((int) $attachment_id, '_epv2_media_origin_fingerprint', self::media_fingerprint($url));
		update_post_meta((int) $attachment_id, '_epv2_media_fingerprint', self::attachment_fingerprint((int) $attachment_id, $url));
		self::apply_attachment_description((int) $attachment_id, $url, '');

		return [
			'attachment_id' => (int) $attachment_id,
			'source' => $url,
			'type' => self::detect_type($url),
		];
	}

	public static function detect_type(string $url): string {
		$path = strtolower((string) parse_url($url, PHP_URL_PATH));
		if (preg_match('/\.(mp4|webm|ogg|mov|m4v)$/', $path)) {
			return 'video';
		}
		if (preg_match('/youtube\.com|youtu\.be|vimeo\.com/i', $url)) {
			return 'embed';
		}
		return 'image';
	}

	public static function content_prefix(string $url, string $lang = 'de'): string {
		if ($url === '') {
			return '';
		}
		$type = self::detect_type($url);
		if ($type === 'video') {
			return '<!-- wp:video {"src":"' . esc_url($url) . '"} --><figure class="wp-block-video"><video controls src="' . esc_url($url) . '"></video></figure><!-- /wp:video -->';
		}
		if ($type === 'embed') {
			return '<!-- wp:embed {"url":"' . esc_url($url) . '","type":"video","providerNameSlug":"video"} --><figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">' . esc_url($url) . '</div></figure><!-- /wp:embed -->';
		}
		return '';
	}

	public static function inline_blocks(array $urls, string $lang = 'de'): string {
		$blocks = [];
		foreach (self::normalize_media_list($urls) as $url) {
			$block = self::inline_block($url, $lang);
			if ($block !== '') {
				$blocks[] = $block;
			}
		}
		return implode("\n\n", $blocks);
	}

	public static function validate_featured_media(string $url, int $post_id = 0, string $fallback_title = ''): array {
		if ($url === '') {
			return ['ok' => false, 'reason' => 'нет featured media'];
		}
		$media = self::attachment_from_reference($url, $post_id);
		if ($media === []) {
			return ['ok' => false, 'reason' => 'не удалось загрузить media'];
		}
		if (($media['type'] ?? '') !== 'image') {
			return ['ok' => true, 'reason' => 'media non-image allowed', 'media' => $media];
		}
		if (empty($media['usable'])) {
			return ['ok' => false, 'reason' => 'изображение слишком маленькое или техническое', 'media' => $media];
		}
		if (! empty($media['attachment_id']) && $fallback_title !== '') {
			update_post_meta((int) $media['attachment_id'], '_wp_attachment_image_alt', sanitize_text_field($fallback_title));
			self::apply_attachment_description((int) $media['attachment_id'], $url, $fallback_title);
		}
		return ['ok' => true, 'reason' => 'ok', 'media' => $media];
	}

	public static function is_relevant_media(string $url, string $title, string $excerpt = '', array $categories = [], array $source_dossier = []): bool {
		if ($url === '') {
			return false;
		}
		return self::media_relevant($url, $title, $excerpt, $categories, $source_dossier);
	}

	public static function can_use_featured_url(string $url): bool {
		return self::looks_like_image_url($url) && ! self::looks_like_low_value_derivative($url);
	}

	public static function is_technical_asset_url(string $url): bool {
		return self::looks_like_technical_asset($url);
	}

	public static function is_fallback_stock_url(string $url): bool {
		$host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		return str_contains($host, 'pexels.com') || str_contains($host, 'wikimedia.org');
	}

	public static function media_credit(string $url, string $lang = 'de'): string {
		return self::media_caption($url, $lang);
	}

	public static function sync_attachment_details(int $attachment_id, string $url, string $fallback_title = ''): void {
		if ($attachment_id <= 0 || $url === '') {
			return;
		}
		update_post_meta($attachment_id, '_epv2_media_origin_fingerprint', self::media_fingerprint($url));
		update_post_meta($attachment_id, '_epv2_media_fingerprint', self::attachment_fingerprint($attachment_id, $url));
		self::apply_attachment_description($attachment_id, $url, $fallback_title);
	}

	public static function media_fingerprint(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		$parts = wp_parse_url($url);
		$host = mb_strtolower((string) ($parts['host'] ?? ''));
		$path = trim((string) ($parts['path'] ?? ''));
		$query = [];
		parse_str((string) ($parts['query'] ?? ''), $query);
		unset($query['w'], $query['width'], $query['h'], $query['height'], $query['fit'], $query['quality'], $query['crop'], $query['auto']);
		$queryString = $query !== [] ? '?' . http_build_query($query) : '';
		return hash('sha256', $host . $path . $queryString);
	}

	public static function attachment_fingerprint(int $attachment_id, string $fallback_url = ''): string {
		$stored = (string) get_post_meta($attachment_id, '_epv2_media_fingerprint', true);
		if ($stored !== '') {
			return $stored;
		}
		$file = (string) get_attached_file($attachment_id);
		if ($file !== '' && file_exists($file) && is_file($file)) {
			$hash = md5_file($file);
			if ($hash) {
				return 'file:' . $hash;
			}
		}
		return self::media_fingerprint($fallback_url);
	}

	public static function recently_used(string $url, int $days = 30, int $exclude_post_id = 0, int $exclude_queue_id = 0): bool {
		$origin_fingerprint = self::media_fingerprint($url);
		if ($origin_fingerprint === '') {
			return false;
		}
		$args = [
			'post_type' => 'post',
			'post_status' => 'publish',
			'date_query' => [
				[
					'after' => gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS)),
					'inclusive' => true,
				],
			],
			'posts_per_page' => 5,
			'fields' => 'ids',
			'post__not_in' => $exclude_post_id > 0 ? [$exclude_post_id] : [],
			'meta_query' => [
				'relation' => 'OR',
				[
					'key' => '_epv2_featured_media_fingerprint',
					'value' => $origin_fingerprint,
				],
				[
					'key' => '_epv2_featured_media_origin_fingerprint',
					'value' => $origin_fingerprint,
				],
			],
		];
		$posts = get_posts($args);
		if ($posts === []) {
			return false;
		}
		foreach ($posts as $post_id) {
			if ($exclude_queue_id > 0 && (int) get_post_meta((int) $post_id, '_epv2_queue_id', true) === $exclude_queue_id) {
				continue;
			}
			return true;
		}
		return false;
	}

	private static function attachment_is_usable(int $attachment_id, $meta): bool {
		$mime = (string) get_post_mime_type($attachment_id);
		if ($mime !== '' && ! str_starts_with($mime, 'image/')) {
			return false;
		}
		$width = (int) ($meta['width'] ?? 0);
		$height = (int) ($meta['height'] ?? 0);
		// Many official sources expose editorial images around 475x316; treat those as usable.
		if ($width > 0 && $height > 0 && ($width < 420 || $height < 236)) {
			return false;
		}
		$file = strtolower((string) get_attached_file($attachment_id));
		if (preg_match('/logo|icon|avatar|sprite/', $file)) {
			return false;
		}
		return true;
	}

	private static function inline_block(string $url, string $lang = 'de'): string {
		$type = self::detect_type($url);
		$caption = self::media_caption($url, $lang);
		if ($type === 'image') {
			$html = '<figure class="wp-block-image size-large europulse-inline-media"><img src="' . esc_url($url) . '" alt=""/>';
			if ($caption !== '') {
				$html .= '<figcaption>' . esc_html($caption) . '</figcaption>';
			}
			$html .= '</figure>';
			return '<!-- wp:html -->' . $html . '<!-- /wp:html -->';
		}
		if ($type === 'video') {
			$html = '<figure class="wp-block-video europulse-inline-media"><video controls src="' . esc_url($url) . '"></video>';
			if ($caption !== '') {
				$html .= '<figcaption>' . esc_html($caption) . '</figcaption>';
			}
			$html .= '</figure>';
			return '<!-- wp:html -->' . $html . '<!-- /wp:html -->';
		}
		if ($type === 'embed') {
			$html = '<figure class="wp-block-embed is-type-video europulse-inline-media"><div class="wp-block-embed__wrapper">' . esc_url($url) . '</div>';
			if ($caption !== '') {
				$html .= '<figcaption>' . esc_html($caption) . '</figcaption>';
			}
			$html .= '</figure>';
			return '<!-- wp:html -->' . $html . '<!-- /wp:html -->';
		}
		return self::content_prefix($url, $lang);
	}

	private static function pexels_query(string $title, string $excerpt, array $categories, array $source_dossier = []): string {
		$text = mb_strtolower(trim(wp_strip_all_tags($title . ' ' . $excerpt)));
		$event_query = self::event_media_query($source_dossier, $categories, 'pexels');
		if ($event_query !== '') {
			return $event_query;
		}
		$rules = [
			'/\b(bildungsbörse|bildungsmesse|ausbildungsmesse|berufsmesse|karrieremesse|bildung|ausbildung|schüler|schueler|school|education fair|career fair|job fair)\b/u' => 'students consultation education fair germany',
			'/\b(kriminalstatistik|kriminalit[aä]t|crime|polizei|police|sicherheit|gewaltkriminalit[aä]t)\b/u' => 'police patrol city street germany',
			'/\b(tankstellen|sprit|benzin|diesel|kraftstoff|oil|fuel|gas prices|топлив|пальн)\b/u' => 'car refueling fuel nozzle gas station europe',
			'/\b(ees|entry\/exit|grenze|border control|passport control|einreise|ausreise|fingerabdr|паспорт|кордон)\b/u' => 'airport passport control traveler border gate',
			'/\b(bahn|db|deutsche bahn|s-bahn|zug|rail|train|mvg|tram|u-bahn|verkehr|transport|verkehrs)\b/u' => 'train platform railway station commuter train',
			'/\b(streaming|musiklabel|music label|vergütung|royalties|spotify)\b/u' => 'recording studio microphone music production',
			'/\b(wohnung|miete|housing|rent|immobil|житло|оренд)\b/u' => 'apartment building city housing exterior',
			'/\b(jobcenter|arbeitsagentur|arbeit|employment|labor|рынок труда|робот)\b/u' => 'office consultation employment desk people',
			'/\b(migration|bamf|integration|refugee|asyl|ukraine|ukrain|біжен|міграц)\b/u' => 'people support center documents consultation',
			'/\b(parlament|bundestag|regierung|kabinett|cabinet|government|minister|vote|election|санкц|уряд|парламент)\b/u' => 'government building parliament exterior flags',
			'/\b(kultur|culture|museum|concert|theater|festival|виставк|концерт|культур)\b/u' => 'museum exhibition hall audience stage',
			'/\b(sport|football|soccer|bundesliga|match|game|спорт|футбол)\b/u' => 'stadium match football players action',
			'/\b(europa|eu|european union|europaweit|єс|європ)\b/u' => 'european union flags building exterior',
			'/\b(welt|world|global|usa|china|middle east|nahost|asia|afrika|africa|israel|iran|gaza|lebanon|libanon|syria|syrien)\b/u' => 'world map international diplomacy city skyline',
			'/\b(münchen|munich|bayern|bavaria|muenchen)\b/u' => 'munich street cityscape germany',
		];
		foreach ($rules as $pattern => $query) {
			if (preg_match($pattern, $text)) {
				return $query;
			}
		}
		if ($categories !== []) {
			$map = [
				'wirtschaft' => 'office finance documents meeting',
				'politik' => 'government building parliament flags',
				'leben-in-deutschland' => 'documents office consultation city',
				'community' => 'people meeting community room',
				'kultur' => 'museum exhibition stage audience',
				'sport' => 'stadium sports action',
				'world' => 'world map diplomacy conference',
				'münchen' => 'munich street tram city',
				'bayern' => 'bavaria city building germany',
				'europa' => 'european union flags building',
				'ukraine' => 'ukraine people support center',
				'deutschland' => 'germany government building city',
			];
			foreach ($categories as $category) {
				if (! empty($map[(string) $category])) {
					return $map[(string) $category];
				}
			}
		}
		$title = trim(wp_strip_all_tags($title));
		$title = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $title) ?: $title;
		$title = preg_replace('/\s+/u', ' ', $title) ?: $title;
		$words = array_slice(array_values(array_filter(explode(' ', trim((string) $title)))), 0, 4);
		return implode(' ', $words);
	}

	private static function source_dossier_image(array $source_dossier, string $title, string $excerpt, array $categories, int $queue_id = 0, array $story_context = []): string {
		$primary = is_array($source_dossier['primary'] ?? null) ? (array) $source_dossier['primary'] : [];
		if ($primary !== []) {
			$entryTitle = trim((string) ($primary['title'] ?? ''));
			$entryExcerpt = trim((string) ($primary['excerpt'] ?? ''));
			$entryLooksRelevant = self::entry_context_relevant($title, $excerpt, $entryTitle, $entryExcerpt, $categories, $story_context);
			$entryHost = strtolower((string) wp_parse_url((string) ($primary['url'] ?? ''), PHP_URL_HOST));
			$candidate = (string) ($primary['image'] ?? '');
			foreach (self::source_image_candidates($candidate) as $variant) {
				$variant = esc_url_raw($variant);
				$allowRecentReuse = self::entry_allows_recent_reuse($primary, $variant);
				if ($variant === '' || ! self::looks_like_image_url($variant) || self::looks_like_technical_asset($variant) || self::looks_like_low_value_derivative($variant) || (! $allowRecentReuse && self::recently_used($variant, 30, 0, $queue_id))) {
					continue;
				}
				$variantHost = strtolower((string) wp_parse_url($variant, PHP_URL_HOST));
				$sameSourceHost = self::same_source_host($entryHost, $variantHost);
				$validatedPrimary = self::validate_featured_media($variant, 0, $title);
				$usablePrimary = ! empty($validatedPrimary['ok']) && ! empty($validatedPrimary['media']['usable']);
				if (
					$usablePrimary
					&& (
						self::media_relevant($variant, trim($entryTitle . ' ' . $title), trim($entryExcerpt . ' ' . $excerpt), $categories, $source_dossier)
						|| ($entryLooksRelevant && $sameSourceHost)
						|| self::entry_allows_recent_reuse($primary, $variant)
					)
				) {
					return $variant;
				}
			}
		}

		$entries = [];
		foreach ((array) ($source_dossier['supporting'] ?? []) as $entry) {
			if (is_array($entry)) {
				$entries[] = $entry;
			}
		}
		foreach ($entries as $index => $entry) {
			$entryTitle = trim((string) ($entry['title'] ?? ''));
			$entryExcerpt = trim((string) ($entry['excerpt'] ?? ''));
			$entryLooksRelevant = self::entry_context_relevant($title, $excerpt, $entryTitle, $entryExcerpt, $categories, $story_context);
			$candidate = (string) ($entry['image'] ?? '');
			foreach (self::source_image_candidates($candidate) as $variant) {
				$variant = esc_url_raw($variant);
				$allow_recent_reuse = self::entry_allows_recent_reuse($entry, $variant);
				if ($variant === '' || ! self::looks_like_image_url($variant) || self::looks_like_technical_asset($variant) || self::looks_like_low_value_derivative($variant) || (! $allow_recent_reuse && self::recently_used($variant, 30, 0, $queue_id))) {
					continue;
				}
				$visualLooksRelevant = self::media_relevant($variant, trim($entryTitle . ' ' . $title), trim($entryExcerpt . ' ' . $excerpt), $categories, $source_dossier);
				$validated = self::validate_featured_media($variant, 0, $title);
				if (empty($validated['ok']) || empty($validated['media']['usable'])) {
					continue;
				}
				if ($index === 0 && $visualLooksRelevant) {
					return $variant;
				}
				if ($index > 0 && ($entryLooksRelevant || $visualLooksRelevant)) {
					return $variant;
				}
			}
		}
		return '';
	}

	private static function entry_context_relevant(string $articleTitle, string $articleExcerpt, string $entryTitle, string $entryExcerpt, array $categories, array $story_context = []): bool {
		$story_context = self::normalize_story_context($story_context);
		$articleTokens = array_values(array_unique(array_merge(
			self::meaningful_tokens($articleTitle . ' ' . $articleExcerpt),
			(array) ($story_context['tokens'] ?? [])
		)));
		$entryTokens = self::meaningful_tokens($entryTitle . ' ' . $entryExcerpt);
		if ($articleTokens === [] || $entryTokens === []) {
			return false;
		}
		$overlap = count(array_intersect($articleTokens, $entryTokens));
		if ($overlap >= 2) {
			return true;
		}
		$entryJoined = mb_strtolower(trim($entryTitle . ' ' . $entryExcerpt));
		$phraseMatches = 0;
		foreach ((array) ($story_context['phrases'] ?? []) as $phrase) {
			$phrase = mb_strtolower(trim((string) $phrase));
			if ($phrase !== '' && mb_strlen($phrase) >= 8 && str_contains($entryJoined, $phrase)) {
				$phraseMatches++;
			}
		}
		if ($phraseMatches >= 1) {
			return true;
		}
		$joined = implode(' ', $categories);
		if ($joined !== '' && preg_match('/\b(lebanon|libanon|streaming|musik|music|ukraine|krieg|israel|iran|bahnhof|bundestag|bayern|münchen|muenchen)\b/ui', $entryTitle . ' ' . $entryExcerpt)) {
			return true;
		}
		return false;
	}

	private static function same_source_host(string $left, string $right): bool {
		$left = trim(strtolower($left));
		$right = trim(strtolower($right));
		if ($left === '' || $right === '') {
			return false;
		}
		if ($left === $right) {
			return true;
		}
		return str_ends_with($left, '.' . $right) || str_ends_with($right, '.' . $left);
	}

	private static function entry_allows_recent_reuse(array $entry, string $image_url = ''): bool {
		$image_host = strtolower((string) wp_parse_url($image_url, PHP_URL_HOST));
		$entry_host = strtolower((string) wp_parse_url((string) ($entry['url'] ?? ''), PHP_URL_HOST));
		$host = $image_host !== '' ? $image_host : $entry_host;
		$source_label = (string) ($entry['source_name'] ?? '');
		if (self::is_editorial_news_source($host, $source_label) || ! empty($entry['is_official'])) {
			return true;
		}
		$entry_family = self::publisher_family($entry_host);
		$image_family = self::publisher_family($image_host);
		return $source_label !== '' && $entry_family !== '' && $entry_family === $image_family;
	}

	private static function publisher_family(string $host): string {
		$host = strtolower(trim($host));
		if ($host === '') {
			return '';
		}
		$parts = array_values(array_filter(explode('.', preg_replace('/^www\./i', '', $host))));
		if (count($parts) < 2) {
			return $parts[0] ?? '';
		}
		return (string) ($parts[count($parts) - 2] ?? '');
	}

	private static function meaningful_tokens(string $text): array {
		$text = mb_strtolower(wp_strip_all_tags($text));
		$text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: $text;
		$stop = ['der','die','das','und','mit','von','fuer','für','des','dem','den','eine','einer','einem','ein','auch','sich','ist','sind','wird','werden','auf','im','in','an','am','zu','zum','zur','for','the','and','with','von','on'];
		$tokens = [];
		foreach (preg_split('/\s+/u', trim($text)) ?: [] as $token) {
			$token = trim($token);
			if (mb_strlen($token) < 5 || in_array($token, $stop, true)) {
				continue;
			}
			$tokens[$token] = true;
		}
		return array_keys($tokens);
	}

	private static function source_image_candidates(string $url): array {
		$url = esc_url_raw($url);
		if ($url === '') {
			return [];
		}
		$candidates = [];

		$decoded = rawurldecode($url);
		if (preg_match('#/(https?://[^\s]+)$#i', $decoded, $matches) === 1) {
			$candidates[] = esc_url_raw($matches[1]);
		}

		$candidates[] = $url;

		if (str_contains($url, 'images.tagesschau.de')) {
			$candidates[] = preg_replace('/\?width=\d+/i', '?width=1920', $url) ?: $url;
			$candidates[] = preg_replace('/\?width=\d+/i', '?width=1280', $url) ?: $url;
		}

		return array_values(array_unique(array_filter($candidates)));
	}

	private static function looks_like_image_url(string $url): bool {
		$url = esc_url_raw($url);
		if ($url === '') {
			return false;
		}
		$path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));
		if ($path !== '' && preg_match('/\.(jpg|jpeg|png|webp|avif|gif)(?:$|\?)/i', $path)) {
			return true;
		}
		if (str_contains($url, '/resource/image/') || str_contains($url, 'images.tagesschau.de/image/')) {
			return true;
		}
		static $cache = [];
		if (isset($cache[$url])) {
			return $cache[$url];
		}
		$response = wp_remote_head($url, [
			'timeout' => 8,
			'redirection' => 3,
			'user-agent' => 'EuroPulse AutoPilot',
		]);
		if (is_wp_error($response)) {
			return $cache[$url] = false;
		}
		$content_type = mb_strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
		return $cache[$url] = str_starts_with($content_type, 'image/');
	}

	private static function wikimedia_media(string $title, string $excerpt, array $categories, int $queue_id = 0, array $source_dossier = []): string {
		$query = self::wikimedia_query($title, $excerpt, $categories, $source_dossier);
		if ($query === '') {
			return '';
		}
		$response = wp_remote_get('https://commons.wikimedia.org/w/api.php?action=query&generator=search&gsrnamespace=6&gsrlimit=5&gsrsearch=' . rawurlencode($query) . '&prop=imageinfo&iiprop=url|extmetadata&iiurlwidth=1600&format=json', [
			'timeout' => 18,
			'user-agent' => 'EuroPulse AutoPilot',
		]);
		if (is_wp_error($response)) {
			return '';
		}
		if ((int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300) {
			return '';
		}
		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		$pages = (array) ($data['query']['pages'] ?? []);
		foreach ($pages as $page) {
			$url = (string) ($page['imageinfo'][0]['thumburl'] ?? $page['imageinfo'][0]['url'] ?? '');
			if ($url === '') {
				continue;
			}
			$titleText = mb_strtolower((string) ($page['title'] ?? ''));
			if (preg_match('/logo|map|flag|icon|diagram|seal|coat of arms|senegal|usa|united states|dollar|gallon|palm|pdf|official journal|journal|document|scan|page\d+/u', $titleText)) {
				continue;
			}
			if (preg_match('/\.pdf(?:\.|$)/iu', $url)) {
				continue;
			}
			if (self::recently_used($url, 30, 0, $queue_id)) {
				continue;
			}
			return esc_url_raw($url);
		}
		return '';
	}

	private static function wikimedia_query(string $title, string $excerpt, array $categories, array $source_dossier = []): string {
		$text = mb_strtolower(trim(wp_strip_all_tags($title . ' ' . $excerpt)));
		$event_query = self::event_media_query($source_dossier, $categories, 'wikimedia');
		if ($event_query !== '') {
			return $event_query;
		}
		$rules = [
			'/\b(bildungsbörse|bildungsmesse|ausbildungsmesse|berufsmesse|karrieremesse|bildung|ausbildung|schüler|schueler|school|education fair|career fair|job fair)\b/u' => 'education fair students classroom',
			'/\b(kriminalstatistik|kriminalit[aä]t|crime|polizei|police|sicherheit|gewaltkriminalit[aä]t)\b/u' => 'police patrol germany',
			'/\b(tankstellen|sprit|benzin|diesel|kraftstoff|топлив|пальн)\b/u' => 'Germany Europe gas station refueling car',
			'/\b(ees|entry\/exit|passport|border|einreise|ausreise|паспорт|кордон)\b/u' => 'airport passport control',
			'/\b(bahn|db|s-bahn|zug|rail|train|mvg|tram|verkehr|transport)\b/u' => 'railway station train platform',
			'/\b(streaming|musiklabel|music label|vergütung|royalties|spotify)\b/u' => 'recording studio microphone music',
			'/\b(jobcenter|arbeitsagentur|employment|arbeit)\b/u' => 'employment office consultation',
			'/\b(kultur|museum|concert|theater|festival|culture)\b/u' => 'museum exhibition audience',
			'/\b(sport|football|soccer|bundesliga|match)\b/u' => 'football match stadium',
			'/\b(parlament|regierung|bundestag|government|minister)\b/u' => 'government building parliament',
			'/\b(welt|world|global|usa|china|middle east|nahost|asia|afrika|africa|israel|iran|gaza|lebanon|libanon|syria|syrien)\b/u' => 'world map diplomacy',
		];
		foreach ($rules as $pattern => $query) {
			if (preg_match($pattern, $text)) {
				return $query;
			}
		}
		foreach ($categories as $category) {
			$category = (string) $category;
			if ($category === 'münchen') {
				return 'Munich street tram';
			}
			if ($category === 'bayern') {
				return 'Bavaria city germany';
			}
			if (in_array($category, ['deutschland', 'politik', 'wirtschaft', 'leben-in-deutschland'], true) && preg_match('/\b(tankstellen|sprit|benzin|diesel|kraftstoff|fuel)\b/u', $text)) {
				return 'Germany Europe gas station refueling car';
			}
		}
		return '';
	}

	private static function event_media_query(array $source_dossier, array $categories, string $provider): string {
		$context = is_array($source_dossier['event_context'] ?? null) ? $source_dossier['event_context'] : [];
		if ($context === []) {
			return '';
		}
		$kind = (string) ($context['kind'] ?? '');
		$title = mb_strtolower(trim((string) ($context['event_title'] ?? '')));
		$participants = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), (array) ($context['participants'] ?? []))));
		$venue = mb_strtolower(trim((string) ($context['venue'] ?? '')));

		if ($kind === 'kultur') {
			if (preg_match('/\b(museum|exhibition|ausstellung|gallery|galerie|installation|kunst|art|nationalgalerie|hamburger bahnhof)\b/u', $title) === 1) {
				return $provider === 'wikimedia'
					? 'museum exhibition installation contemporary art'
					: 'museum exhibition installation contemporary art gallery';
			}
			if (preg_match('/\b(the voice|tv|show|moderator|jury|casting)\b/u', $title) === 1) {
				return $provider === 'wikimedia' ? 'television studio presenter stage' : 'tv studio presenter microphone stage lights';
			}
			return $provider === 'wikimedia' ? 'theater festival concert stage' : 'stage audience lights cultural event';
		}

		if ($kind === 'community') {
			if (preg_match('/\b(job fair|career fair|bildungsmesse|berufsmesse|karrieremesse)\b/u', $title) === 1) {
				return $provider === 'wikimedia' ? 'job fair exhibition hall people' : 'career fair booth consultation people';
			}
			return $provider === 'wikimedia' ? 'community meeting information desk' : 'community center people meeting event';
		}

		if ($kind === 'sport') {
			if (preg_match('/\b(paralymp)\b/u', $title) === 1) {
				return $provider === 'wikimedia' ? 'paralympic athlete competition' : 'paralympic athlete sports competition';
			}
			if ($participants !== []) {
				$query = implode(' ', array_slice($participants, 0, 2));
				if ($provider === 'wikimedia') {
					return trim($query . ' football player');
				}
				return trim($query . ' football players match');
			}
			if ($venue !== '' && preg_match('/arena|stadion|halle/u', $venue) === 1) {
				return $provider === 'wikimedia' ? 'stadium exterior match' : 'stadium exterior sports event';
			}
			return $provider === 'wikimedia' ? 'sports competition arena' : 'sports arena competition action';
		}

		foreach ($categories as $category) {
			if (in_array((string) $category, ['sport', 'kultur', 'community'], true)) {
				return match ((string) $category) {
					'sport' => $provider === 'wikimedia' ? 'sports competition arena' : 'sports arena competition action',
					'kultur' => $provider === 'wikimedia' ? 'stage presenter audience' : 'stage audience lights cultural event',
					default => $provider === 'wikimedia' ? 'community event people' : 'community event people meeting',
				};
			}
		}

		return '';
	}

	private static function media_caption(string $url, string $lang = 'de'): string {
		$details = self::remote_media_details($url);
		$label = trim((string) ($details['source_label'] ?? ''));
		$alt = trim((string) ($details['alt'] ?? ''));
		if ($label === '') {
			$host = (string) wp_parse_url($url, PHP_URL_HOST);
			$label = $host !== '' ? preg_replace('/^www\./i', '', $host) : '';
		}
		if ($label === '') {
			return '';
		}
		if ($alt !== '' && preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s\-]{2,72}$/u', $alt) && preg_match('/\b(image|photo|bild|foto)\b/ui', $alt) !== 1) {
			return self::credit_prefix($lang) . ': ' . $alt . ' — ' . $label;
		}
		return self::credit_prefix($lang) . ': ' . $label;
	}

	private static function apply_attachment_description(int $attachment_id, string $url, string $fallback_title): void {
		$site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
		$url_host = (string) wp_parse_url($url, PHP_URL_HOST);
		$existing_remote = (string) get_post_meta($attachment_id, '_epv2_remote_source_url', true);
		$resolved_url = $url;
		if ($url_host !== '' && $site_host !== '' && $url_host === $site_host && $existing_remote !== '') {
			$resolved_url = $existing_remote;
		}
		update_post_meta($attachment_id, '_epv2_remote_source_url', esc_url_raw($resolved_url));
		$details = self::remote_media_details($resolved_url);
		$source_label = trim((string) ($details['source_label'] ?? ''));
		update_post_meta($attachment_id, '_epv2_remote_source_label', $source_label);
		$caption = $source_label !== '' ? self::credit_prefix('de') . ': ' . $source_label : '';
		$alt = trim((string) ($details['alt'] ?? ''));
		$provider = (string) ($details['provider'] ?? '');
		if ($caption === '' && $provider === 'source') {
			$caption = self::credit_prefix('de') . ': ' . self::source_label_from_url($resolved_url);
		}
		if ($caption !== '') {
			wp_update_post([
				'ID' => $attachment_id,
				'post_excerpt' => $caption,
			]);
		}
		if ($fallback_title !== '' && ($alt === '' || in_array($provider, ['pexels', 'wikimedia', 'source'], true))) {
			$alt = $fallback_title;
		}
		if ($alt !== '') {
			update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
		}
	}

	private static function remote_media_details(string $url): array {
		static $cache = [];
		if (isset($cache[$url])) {
			return $cache[$url];
		}

		$resolved_url = $url;
		$attachment_id = attachment_url_to_postid($url);
		if ($attachment_id > 0) {
			$remote_source = (string) get_post_meta($attachment_id, '_epv2_remote_source_url', true);
			if ($remote_source !== '') {
				$resolved_url = $remote_source;
			}
		}

		$host = (string) wp_parse_url($resolved_url, PHP_URL_HOST);
		$details = [
			'source_label' => $resolved_url !== '' ? self::source_label_from_url($resolved_url) : ($host !== '' ? preg_replace('/^www\./i', '', $host) : ''),
			'alt' => '',
			'provider' => 'source',
		];

		if (str_contains($host, 'pexels.com') && preg_match('~/photos/(\d+)/~', $resolved_url, $match)) {
			$key = (string) (EPV2_Settings::get('image_keys', [])['pexels'] ?? '');
			if ($key !== '') {
				$response = wp_remote_get('https://api.pexels.com/v1/photos/' . rawurlencode($match[1]), [
					'timeout' => 15,
					'headers' => [
						'Authorization' => $key,
					],
				]);
				if (! is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) >= 200 && (int) wp_remote_retrieve_response_code($response) < 300) {
					$data = json_decode((string) wp_remote_retrieve_body($response), true);
					if (is_array($data)) {
						$photographer = trim((string) ($data['photographer'] ?? ''));
						$alt = trim((string) ($data['alt'] ?? ''));
						$details['source_label'] = $photographer !== '' ? ($photographer . ' / Pexels') : 'Pexels';
						$details['alt'] = $alt;
						$details['provider'] = 'pexels';
					}
				}
			}
		}

		if (str_contains($host, 'wikimedia.org')) {
			$details['source_label'] = 'Wikimedia Commons';
			$details['provider'] = 'wikimedia';
		}

		$site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
		if ($host !== '' && $site_host !== '' && $host === $site_host && $details['provider'] === 'source') {
			$details['source_label'] = self::source_label_from_url($resolved_url);
		}

		$cache[$url] = $details;
		return $details;
	}

	private static function media_relevant(string $url, string $title, string $excerpt, array $categories, array $source_dossier = []): bool {
		$story_context = self::story_context_from_dossier($title, $excerpt, $categories, $source_dossier);
		$context = mb_strtolower(trim(wp_strip_all_tags(implode(' ', array_filter([
			$title,
			$excerpt,
			implode(' ', $categories),
			(string) ($story_context['text'] ?? ''),
			implode(' ', (array) ($story_context['phrases'] ?? [])),
		])))));
		$details = self::remote_media_details($url);
		$haystack = mb_strtolower(trim((string) ($details['alt'] ?? '') . ' ' . (string) ($details['caption'] ?? '') . ' ' . (string) wp_parse_url($url, PHP_URL_PATH)));
		$haystack = preg_replace('/[\/_-]+/u', ' ', $haystack) ?: $haystack;
		$haystack = preg_replace('/\s+/u', ' ', $haystack) ?: $haystack;
		$source_label = mb_strtolower(trim((string) ($details['source_label'] ?? '')));
		$host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$intent = self::media_intent($title, $excerpt, $categories);
		if (str_contains($host, 'wikimedia.org')) {
			$contextTokens = array_slice(self::meaningful_tokens($context), 0, 10);
			$haystackTokens = self::meaningful_tokens($haystack);
			if ($contextTokens !== [] && $haystackTokens !== [] && count(array_intersect($contextTokens, $haystackTokens)) === 0) {
				return false;
			}
		}

		if (
			$intent === 'sport_event'
			&& self::is_editorial_news_source($host, $source_label)
			&& preg_match('/\b(fc bayern|real madrid|bundesliga|champions league|uefa|team|spieler|goalkeeper|torwart|football|soccer|sport)\b/u', $context) === 1
			&& preg_match('/\b(camel|desert|parliament|government building|flag alone|gas station|royal palace)\b/u', $haystack) !== 1
		) {
			return true;
		}

		if (
			$intent === 'german_government'
			&& self::is_editorial_news_source($host, $source_label)
			&& preg_match('/\b(sweden|swedish|schweden|switzerland|swiss|schweiz|riksdag|stockholm|canberra|brisbane)\b/u', $haystack) !== 1
		) {
			return true;
		}

		if (! self::passes_intent_gate($intent, $context, $haystack)) {
			return false;
		}

		$context_has_usa = preg_match('/\b(trump|usa|us-|united states|white house|nato|hormuz)\b/u', $context) === 1;
		if ($haystack !== '' && preg_match('/\b(senegal|dollar|gallon|palm|beach|tropical|pdf|document|scan|official journal)\b/u', $haystack)) {
			return false;
		}
		if (! $context_has_usa && $haystack !== '' && preg_match('/\b(usa|united states)\b/u', $haystack)) {
			return false;
		}

		$needsCare = preg_match('/\b(pflege|pflegekr[aä]fte|altenpflege|care workers|care|hospital|krankenhaus|health|медич|догляд)\b/u', $context);
		if ($needsCare) {
			return (bool) preg_match('/\b(pflege|care|health|hospital|clinic|nurs|krankenhaus)\b/u', $haystack);
		}

		$needsStreaming = preg_match('/\b(streaming|musiklabel|music label|vergütung|royalties|spotify|music|tv-show|quizshow|fernsehen|mediathek)\b/u', $context);
		if ($needsStreaming) {
			if (preg_match('/logo-share-social-media|logo|icon|sprite/u', $haystack)) {
				return false;
			}
			if (preg_match('/\b(flag|flags|switzerland|swiss|sweden|schweden|eu|parliament|government|building|politician|camel|desert)\b/u', $haystack)) {
				return false;
			}
			return (bool) preg_match('/\b(stream|music|audio|concert|studio|microphone|vinyl|headphones)\b/u', $haystack);
		}

		$needsFuel = preg_match('/\b(tankstellen|sprit|benzin|diesel|kraftstoff|fuel|oil|gas)\b/u', $context);
		if ($needsFuel) {
			return (bool) preg_match('/\b(refuel|refueling|fuel|gas station|nozzle|petrol|diesel|pump|tankstelle)\b/u', $haystack);
		}

		$needsGermany = preg_match('/\b(deutschland|germany|bundesregierung|bundestag|berlin|münchen|munich|bayern|bonn|hamburg)\b/u', $context);
		if ($needsGermany) {
			if (preg_match('/\b(sweden|swedish|schweden|switzerland|swiss|schweiz|australia|australien|brisbane|riksdag|stockholm|canberra)\b/u', $haystack)) {
				return false;
			}
			if (preg_match('/\b(parliament house sweden|riksdag|stockholm)\b/u', $haystack)) {
				return false;
			}
		}

		$needsUkraine = preg_match('/\b(ukraine|ukrain|kyiv|kiew|київ|киев)\b/u', $context);
		if ($needsUkraine && preg_match('/\b(interview|job interview|office meeting|business handshake|corporate office)\b/u', $haystack)) {
			return false;
		}
		if ($needsUkraine && preg_match('/\b(australia|brisbane|camel|desert|tourism|beach)\b/u', $haystack)) {
			return false;
		}

		$needsCrime = preg_match('/\b(kriminalstatistik|kriminalit[aä]t|crime|polizei|police|sicherheit|gewaltkriminalit[aä]t)\b/u', $context);
		if ($needsCrime) {
			if (preg_match('/\b(airport|airfield|runway|terminal|passport|border|traveler|flight)\b/u', $haystack)) {
				return false;
			}
			return (bool) preg_match('/\b(police|patrol|security|street|city|siren|vehicle|officer)\b/u', $haystack);
		}

		$needsBorder = preg_match('/\b(ees|entry\/exit|border|passport|einreise|ausreise|grenze|fingerabdr|паспорт|кордон)\b/u', $context);
		if ($needsBorder) {
			return (bool) preg_match('/\b(passport|border|airport|control|traveler|terminal)\b/u', $haystack);
		}

		$needsRail = preg_match('/\b(bahn|s-bahn|db|zug|train|rail|tram|mvg|verkehr)\b/u', $context);
		if ($needsRail) {
			return (bool) preg_match('/\b(train|rail|station|platform|tram|commuter)\b/u', $haystack);
		}

		$needsGov = preg_match('/\b(regierung|bundestag|parlament|government|kabinett|bundeskanzler|kanzler|merz|steinmeier|bundespräsident)\b/u', $context);
		if ($needsGov) {
			if (preg_match('/\b(sweden|swedish|schweden|switzerland|swiss|schweiz|australia|australien)\b/u', $haystack)) {
				return false;
			}
			if (
				self::is_editorial_news_source($host, $source_label)
				&& preg_match('/\b(sweden|swedish|schweden|switzerland|swiss|schweiz|riksdag|stockholm|canberra|brisbane)\b/u', $haystack) !== 1
			) {
				return true;
			}
			return (bool) preg_match('/\b(government|parliament|building|flags|minister|bundestag|bundesregierung)\b/u', $haystack);
		}

		if (! self::passes_entity_gate($context, $haystack)) {
			return false;
		}

		$needsWorld = preg_match('/\b(world|welt|middle east|nahost|iran|israel|lebanon|libanon|usa|china|australia|britain|royal)\b/u', $context);
		if ($needsWorld && preg_match('/\b(gas station|tankstelle|refuel|office meeting|job interview|music studio|microphone)\b/u', $haystack)) {
			return false;
		}

			$needsSport = preg_match('/\b(sport|football|soccer|bundesliga|uefa|champions league|basketball|hockey|nhl|nba|euroleague|spieler|spielerinnen|match)\b/u', $context);
			if ($needsSport) {
				if (preg_match('/\b(camel|desert|parliament|government building|flag|gas station)\b/u', $haystack)) {
					return false;
				}
				if (
					$haystack !== ''
					&& ! preg_match('/\b(player|players|football|soccer|stadium|match|training|coach|team|sport|hockey|basketball|goalkeeper|champions league|uefa|live stream|fc bayern|bayern|real madrid|atalanta|galatasaray|liverpool)\b/u', $haystack)
				) {
					return false;
				}
			}

		return true;
	}

	private static function story_context_from_dossier(string $title, string $excerpt, array $categories, array $source_dossier = []): array {
		$story = is_array($source_dossier['story_context'] ?? null) ? (array) $source_dossier['story_context'] : [];
		$memory = is_array($source_dossier['context_memory'] ?? null) ? (array) $source_dossier['context_memory'] : [];
		$event = is_array($source_dossier['event_context'] ?? null) ? (array) $source_dossier['event_context'] : [];
		$primary = is_array($source_dossier['primary'] ?? null) ? (array) $source_dossier['primary'] : [];
		$shell = is_array($source_dossier['shell_primary'] ?? null) ? (array) $source_dossier['shell_primary'] : [];
		$supporting = array_values(array_filter((array) ($source_dossier['supporting'] ?? []), 'is_array'));

		$textParts = array_filter([
			$title,
			$excerpt,
			implode(' ', $categories),
			(string) ($primary['title'] ?? ''),
			(string) ($primary['excerpt'] ?? ''),
			(string) ($shell['title'] ?? ''),
			(string) ($shell['excerpt'] ?? ''),
			(string) ($event['event_title'] ?? ''),
			implode(' ', (array) ($event['participants'] ?? [])),
			(string) ($event['venue'] ?? ''),
			(string) ($event['stage'] ?? ''),
			implode(' ', (array) ($event['search_terms'] ?? [])),
			implode(' ', (array) ($memory['search_terms'] ?? [])),
			implode(' ', (array) ($story['search_terms'] ?? [])),
			implode(' ', (array) ($story['entities'] ?? [])),
			implode(' ', (array) ($story['theme_tokens'] ?? [])),
			implode(' ', (array) ($story['body_keywords'] ?? [])),
			(string) ($story['body_snippet'] ?? ''),
			self::content_snippet((string) ($primary['content'] ?? ''), 260),
		]);

		foreach (array_slice($supporting, 0, 3) as $entry) {
			$textParts[] = (string) ($entry['title'] ?? '');
			$textParts[] = (string) ($entry['excerpt'] ?? '');
		}

		$phrases = array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', array_merge(
			(array) ($story['search_terms'] ?? []),
			(array) ($memory['search_terms'] ?? []),
			(array) ($event['search_terms'] ?? []),
			(array) ($story['entities'] ?? []),
			(array) ($event['participants'] ?? []),
			array_values(array_filter([
				(string) ($event['event_title'] ?? ''),
				(string) ($event['venue'] ?? ''),
				(string) ($event['stage'] ?? ''),
				(string) ($story['body_snippet'] ?? ''),
			]))
		))))), 0, 16);
		$text = trim(implode(' ', $textParts));
		$tokens = self::meaningful_tokens($text . ' ' . implode(' ', $phrases));

		return array_filter([
			'text' => $text,
			'tokens' => $tokens,
			'phrases' => $phrases,
		], static function ($value): bool {
			if (is_array($value)) {
				return $value !== [];
			}
			return trim((string) $value) !== '';
		});
	}

	private static function normalize_story_context(array $story_context): array {
		if ($story_context === []) {
			return [];
		}
		$tokens = array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) ($story_context['tokens'] ?? $story_context['theme_tokens'] ?? []))))), 0, 12);
		$phrases = array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', array_merge(
			(array) ($story_context['phrases'] ?? []),
			(array) ($story_context['search_terms'] ?? []),
			(array) ($story_context['entities'] ?? []),
			(array) ($story_context['body_keywords'] ?? [])
		))))), 0, 16);
		return array_filter([
			'tokens' => $tokens,
			'phrases' => $phrases,
			'text' => sanitize_text_field((string) ($story_context['text'] ?? $story_context['body_snippet'] ?? '')),
		], static function ($value): bool {
			if (is_array($value)) {
				return $value !== [];
			}
			return trim((string) $value) !== '';
		});
	}

	private static function content_snippet(string $content, int $limit = 260): string {
		$content = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($content)) ?: '');
		if ($content === '') {
			return '';
		}
		return sanitize_text_field((string) mb_substr($content, 0, max(60, $limit)));
	}

	private static function media_intent(string $title, string $excerpt, array $categories): string {
		$text = mb_strtolower(trim(wp_strip_all_tags($title . ' ' . $excerpt . ' ' . implode(' ', $categories))));

		$rules = [
			'streaming_media' => '/\b(streaming|quizshow|quiz show|tv-show|fernsehen|ard|zdf|rtl|prosieben|mediathek|spotify|royalties|music label|musiklabel)\b/u',
			'museum_exhibition' => '/\b(museum|ausstellung|exhibition|gallery|galerie|installation|kunst|art|nationalgalerie|hamburger bahnhof)\b/u',
			'fuel_prices' => '/\b(kraftstoff|kraftstoffpreise|spritpreis|spritpreise|benzinpreis|benzinpreise|diesel|tankstellen|refuel|fuel prices|energiepreis-?entlastungen)\b/u',
			'crime_police' => '/\b(kriminalstatistik|kriminalität|crime|polizei|police|sicherheit|gewaltkriminalität)\b/u',
			'border_airport' => '/\b(ees|entry\/exit|border|passport|einreise|ausreise|grenze|fingerabdr|passport control)\b/u',
			'rail_transit' => '/\b(bahn|s-bahn|db|zug|train|rail|tram|mvg|öpnv|nahverkehr|verkehr)\b/u',
			'bundestag_memorial' => '/\b(volkskammer|demokratiegeschichte|gedenken|denkmal|mahnung und erinnerung|opfer der kommunistischen diktatur|kommunistischen diktatur)\b/u',
			'german_government' => '/\b(bundesregierung|regierung|kabinett|bundesrat|minister|bundespräsident|steinmeier|merz)\b/u',
			'ukraine_attack' => '/\b(ukraine-krieg|krieg in der ukraine|angriff auf kiew|angriff auf kyiv|kiew|kyiv|luftangriff|raketenangriff|дрон|обстріл|обстрел)\b/u',
			'middle_east_diplomacy' => '/\b(libanon|lebanon|israel|iran|gaza|nahost|middle east|hezbollah|hamas|joint statement|gemeinsame erklärung|diplomatic|diplom)\b/u',
			'paralympic_official_visit' => '/\b(paralymp|paralympics|paralympische spiele|parasport|deutsches team bei den paralympics|team d bei den paralympics)\b/u',
			'education_fair' => '/\b(bildungsbörse|bildungsmesse|ausbildungsmesse|berufsmesse|karrieremesse|bildung|ausbildung|schüler|schueler|bildungsweg|berufswahl|praktika|duale studienplätze)\b/u',
			'world_royals' => '/\b(royal|palace|monarchy|prince|princess|king|queen|british royal)\b/u',
			'sport_event' => '/\b(fc bayern|bundesliga|uefa|champions league|europa league|conference league|spieltag|stadium|trainer|match|torwart|goalkeeper|football|soccer|basketball|hockey)\b/u',
			'community_help' => '/\b(bahnhofsmission|initiative|verein|community|diaspora|netzwerktreffen|ehrenamt|hilfsangebot|freiwillige)\b/u',
		];

		foreach ($rules as $intent => $pattern) {
			if (preg_match($pattern, $text) === 1) {
				return $intent;
			}
		}

		return '';
	}

	private static function passes_intent_gate(string $intent, string $context, string $haystack): bool {
		if ($intent === '') {
			return true;
		}

		$deny = [
			'streaming_media' => '/\b(flag|flags|switzerland|swiss|sweden|schweden|schweiz|eu parliament|government building|camel|desert|gas station|runway)\b/u',
			'museum_exhibition' => '/\b(gas station|runway|passport control|stadium|football|soccer|government building|parliament|camel|desert)\b/u',
			'fuel_prices' => '/\b(parliament|bundestag|government building|camel|desert|royal|palace|stadium)\b/u',
			'crime_police' => '/\b(airport|airfield|runway|passport|border|terminal|interview|office meeting|camel|desert)\b/u',
			'border_airport' => '/\b(camel|desert|parliament|royal|stadium|concert)\b/u',
			'rail_transit' => '/\b(parliament|bundestag|government building|desert|camel|beach)\b/u',
			'bundestag_memorial' => '/\b(sweden|swedish|schweden|switzerland|swiss|schweiz|riksdag|stockholm|canberra|brisbane)\b/u',
			'german_government' => '/\b(sweden|swedish|schweden|switzerland|swiss|schweiz|riksdag|stockholm|canberra|brisbane)\b/u',
			'ukraine_attack' => '/\b(job interview|office meeting|corporate office|business handshake|camel|desert|beach|tourism|australia|brisbane)\b/u',
			'middle_east_diplomacy' => '/\b(switzerland|swiss|sweden|schweden|schweiz|riksdag|stockholm|australia|brisbane|gas station|job interview)\b/u',
			'paralympic_official_visit' => '/\b(football|soccer|bundesliga|stadium floodlights|goalkeeper|gas station|parliament|government building|flag alone)\b/u',
			'education_fair' => '/\b(stadium|football|soccer|parliament|government building|gas station|desert|camel|runway)\b/u',
			'world_royals' => '/\b(gas station|runway|passport control|stadium|football|microphone|concert)\b/u',
			'sport_event' => '/\b(camel|desert|parliament|government building|flag alone|gas station|royal palace)\b/u',
			'community_help' => '/\b(parliament|government building|stadium|runway|gas station|desert|camel)\b/u',
		];

		$allow = [
			'streaming_media' => '/\b(stream|music|audio|concert|studio|microphone|headphones|tv studio|television)\b/u',
			'museum_exhibition' => '/\b(museum|exhibition|gallery|installation|art|artist|artwork|nationalgalerie|bahnhof|contemporary)\b/u',
			'fuel_prices' => '/\b(refuel|refueling|fuel|gas station|nozzle|petrol|diesel|pump|tankstelle)\b/u',
			'crime_police' => '/\b(police|patrol|security|street|city|siren|vehicle|officer)\b/u',
			'border_airport' => '/\b(passport|border|airport|control|traveler|terminal)\b/u',
			'rail_transit' => '/\b(train|rail|station|platform|tram|commuter|bus)\b/u',
			'bundestag_memorial' => '/\b(bundestag|parliament|memorial|museum|exhibition|document|historical|berlin)\b/u',
			'german_government' => '/\b(government|parliament|building|bundestag|bundesregierung|minister|berlin)\b/u',
			'ukraine_attack' => '/\b(damage|destroyed|firefighters|rescue|debris|ukraine|kyiv|kiew|emergency|explosion)\b/u',
			'middle_east_diplomacy' => '/\b(diplomatic|delegation|meeting|conference|minister|leaders|middle east|israel|iran|lebanon)\b/u',
			'paralympic_official_visit' => '/\b(paralympic|parasport|wheelchair|athlete|athletes|team germany|deutsches team|sport delegation|minister visiting athletes|sports hall|training hall|track and field)\b/u',
			'education_fair' => '/\b(student|students|school|classroom|consultation|career|education|training|fair|exhibition|meeting|learning)\b/u',
			'world_royals' => '/\b(royal|palace|king|queen|prince|princess|british)\b/u',
				'sport_event' => '/\b(player|players|football|soccer|stadium|match|training|coach|team|sport|hockey|basketball|goalkeeper|champions league|uefa|live stream|fc bayern|bayern|real madrid|atalanta|galatasaray|liverpool)\b/u',
			'community_help' => '/\b(people|meeting|volunteer|community|support|help|station|service|group)\b/u',
		];

		if ($haystack !== '' && isset($deny[$intent]) && preg_match($deny[$intent], $haystack) === 1) {
			return false;
		}

		if ($haystack !== '' && isset($allow[$intent])) {
			return preg_match($allow[$intent], $haystack) === 1;
		}

		return true;
	}

	private static function passes_entity_gate(string $context, string $haystack): bool {
		if ($haystack === '') {
			return true;
		}

		$entityRules = [
			[
				'need' => '/\b(bundestag|berliner reichstag|reichstag)\b/u',
				'deny' => '/\b(riksdag|stockholm|sweden|swedish|schweden|schweiz|switzerland|swiss|canberra|brisbane)\b/u',
				'allow' => '/\b(bundestag|reichstag|berlin|plenary|plenarsaal|deutscher bundestag)\b/u',
			],
			[
				'need' => '/\b(bundesregierung|bundeskanzler|kanzleramt|merz|steinmeier|bundespräsident)\b/u',
				'deny' => '/\b(riksdag|stockholm|sweden|swedish|schweden|schweiz|switzerland|swiss|canberra|brisbane)\b/u',
				'allow' => '/\b(bundesregierung|bundeskanzleramt|berlin|regierung|kanzleramt|minister)\b/u',
			],
			[
				'need' => '/\b(ukraine|ukrain|kyiv|kiew|одеса|одес|харків|kharkiv|львів|lviv|обстріл|обстрел|ракетн|дрон)\b/u',
				'deny' => '/\b(sweden|swedish|switzerland|swiss|brisbane|australia|canberra|beach|tourism|office meeting|job interview)\b/u',
				'allow' => '/\b(ukraine|kyiv|kiew|kharkiv|odesa|odessa|lviv|rescue|debris|firefighters|damage|emergency|dsns)\b/u',
			],
			[
				'need' => '/\b(aliyev|alijew|azerbaijan|aserbaidschan|caucasus|kaukasus|middle east|nahost|libanon|lebanon|iran|israel)\b/u',
				'deny' => '/\b(riksdag|stockholm|sweden|swedish|schweden|schweiz|switzerland|swiss|brisbane|australia)\b/u',
				'allow' => '/\b(meeting|delegation|minister|leaders|conference|diplomatic|azerbaijan|baku|iran|israel|lebanon|beirut)\b/u',
			],
			[
				'need' => '/\b(paralymp|paralympics|paralympische spiele|parasport)\b/u',
				'deny' => '/\b(football|soccer|bundesliga|goalkeeper|stadium crowd|matchday)\b/u',
				'allow' => '/\b(paralympic|parasport|athlete|athletes|wheelchair|delegation|team germany|deutsches team|minister)\b/u',
			],
		];

		foreach ($entityRules as $rule) {
			if (preg_match($rule['need'], $context) !== 1) {
				continue;
			}
			if (preg_match($rule['deny'], $haystack) === 1) {
				return false;
			}
			if (preg_match($rule['allow'], $haystack) !== 1) {
				return false;
			}
		}

		return true;
	}

	private static function is_editorial_news_source(string $host, string $source_label): bool {
		$known = [
			'br.de',
			'img.br.de',
			'tagesschau.de',
			'images.tagesschau.de',
			'sportschau.de',
			'www.sportschau.de',
			'images.sportschau.de',
			'dw.com',
			'zdf.de',
			'www.zdf.de',
			'ardmediathek.de',
			'bundesregierung.de',
			'bundestag.de',
		];
		if ($host !== '' && in_array($host, $known, true)) {
			return true;
		}
		return preg_match('/\b(br|tagesschau|dw|zdf|bundesregierung|bundestag)\b/u', $source_label) === 1;
	}

	private static function prefer_no_stock_fallback(string $title, string $excerpt, array $categories): bool {
		$context = mb_strtolower(trim(wp_strip_all_tags($title . ' ' . $excerpt . ' ' . implode(' ', $categories))));
		return preg_match('/\b(lebanon|libanon|israel|hezbollah|hamas|iran|nahost|middle east|gaza|krieg|war|escalation|eskalation|joint statement|gemeinsame erkl[aä]rung|diplomatic|diplom|sanctions|waffenruhe|bundestag|bundesregierung|parlament|regierung|ukraine|kyiv|kiew|crime|polizei|tankstellen|spritpreis|royal|palace|usa|illinois|footballers|spielerinnen|the voice|quizshow|tv-show|unterhaltungsshow|moderator|moderation|jury|staffel|schölermann|fc bayern|bundesliga|champions league|community|verein|workshop|sprechstunde|bahnhofsmission|hilfsangebot)\b/u', $context) === 1;
	}

	private static function looks_like_technical_asset(string $url): bool {
		$haystack = mb_strtolower((string) wp_parse_url($url, PHP_URL_PATH));
		return preg_match('/logo|icon|sprite|share-social|social-media|csm_wbm|default|placeholder|blank[-_ ]?web|facebook[-_ ]?/u', $haystack) === 1;
	}

	private static function looks_like_low_value_derivative(string $url): bool {
		$url = esc_url_raw($url);
		if ($url === '') {
			return false;
		}

		$path = rawurldecode(mb_strtolower((string) wp_parse_url($url, PHP_URL_PATH)));
		if (preg_match('/(?:^|[^a-z0-9])(w|width|h|height)[_:=,-]?(0*[1-9]\\d?|1[0-7]\\d|180)(?:[^0-9]|$)/u', $path) === 1) {
			return true;
		}
		if (preg_match('/(?:^|[\\/_-])([3-9]\\d|1[0-7]\\d|180)x([3-9]\\d|1[0-7]\\d|180)(?:[\\/_-]|$)/u', $path) === 1) {
			return true;
		}

		$query = [];
		parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
		foreach (['w', 'width', 'h', 'height'] as $key) {
			$value = (int) ($query[$key] ?? 0);
			if ($value > 0 && $value <= 180) {
				return true;
			}
		}

		return false;
	}

	private static function credit_prefix(string $lang): string {
		return match ($lang) {
			'uk' => 'Фото',
			'en' => 'Photo',
			default => 'Bild',
		};
	}

	private static function source_label_from_url(string $url): string {
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$host = preg_replace('/^www\./i', '', $host);
		if ($host === '') {
			return '';
		}
		$site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		$site_host = preg_replace('/^www\./i', '', $site_host);
		if ($site_host !== '' && ($host === $site_host || str_ends_with($host, '.' . $site_host))) {
			return 'EuroPulse';
		}
		$map = [
			'pexels.com' => 'Pexels',
			'images.pexels.com' => 'Pexels',
			'cbc.ca' => 'CBC News',
			'i.cbc.ca' => 'CBC News',
			'commons.wikimedia.org' => 'Wikimedia Commons',
			'upload.wikimedia.org' => 'Wikimedia Commons',
			'br.de' => 'BR',
			'img.br.de' => 'BR',
			's.hs-data.com' => 'Sportdaten',
			'bundesregierung.de' => 'Bundesregierung',
			'bamf.de' => 'BAMF',
			'arbeitsagentur.de' => 'Bundesagentur fuer Arbeit',
			'bundestag.de' => 'Deutscher Bundestag',
			'tagesschau.de' => 'Tagesschau',
			'images.tagesschau.de' => 'Tagesschau',
			'stmi.bayern.de' => 'Bayerisches Innenministerium',
			'bayern.de' => 'Bayern.de',
			'muenchen.de' => 'muenchen.de',
			'stadt.muenchen.de' => 'Stadt Muenchen',
			'news.google.com' => 'Google News',
		];
		foreach ($map as $needle => $label) {
			if ($host === $needle || str_ends_with($host, '.' . $needle)) {
				return $label;
			}
		}
		return preg_replace('/^www\./i', '', $host);
	}
}
