<?php

if (! defined('ABSPATH')) {
	exit;
}

	final class EPV2_Media {
	/**
	 * Build a search query from the upfront story card's media_search_terms.
	 * Returns "" when the card is missing, empty, or marks the story as
	 * 'generated' (no real-world image expected).
	 *
	 * Callers that pass a dossier built from `_meta.source_dossier` should
	 * include the card under `story_card` (see EPV2_Media::dossier_with_card()).
	 */
	private static function story_card_media_query(array $source_dossier): string {
		$card = $source_dossier['story_card'] ?? null;
		if (! is_array($card) || $card === []) {
			return '';
		}
		$mode = strtolower((string) ($card['media_required'] ?? 'source_first'));
		if ($mode === 'generated') {
			return '';
		}
		$terms = $card['media_search_terms'] ?? [];
		if (! is_array($terms)) {
			return '';
		}
		$cleaned = [];
		foreach ($terms as $term) {
			$t = trim((string) $term);
			if ($t === '') {
				continue;
			}
			$cleaned[] = $t;
			if (count($cleaned) >= 3) {
				break;
			}
		}
		if ($cleaned === []) {
			return '';
		}
		return implode(' ', $cleaned);
	}

	/**
	 * Helper for callers: enrich a dossier with the story card so that
	 * downstream media-resolver queries can use card.media_search_terms.
	 * Returns the dossier unchanged when no card.
	 */
	public static function dossier_with_card(array $source_dossier, array $story_card = []): array {
		if ($story_card !== [] && ! isset($source_dossier['story_card'])) {
			$source_dossier['story_card'] = $story_card;
		}
		return $source_dossier;
	}

	/**
	 * Список фото-агентств (2026-06-10). Их снимки публикуются изданиями на
	 * своих CDN, но принадлежат агентству — использование без лицензии = риск
	 * штрафа (особенно в Германии). Если подпись фото (image_credit) называет
	 * агентство, снимок не берём как featured → fallback на свой/легальный
	 * источник. Расширяется через epv2_settings['media_credit_blocklist'].
	 */
	private static function agency_credit_patterns(): array {
		static $defaults = [
			'reuters', 'dpa', 'afp', 'getty', 'associated press', 'ap photo', 'ap)',
			'epa-efe', 'epa)', 'anadolu', 'shutterstock', 'alamy', 'zuma', 'sipa',
			'imago', 'picture alliance', 'picture-alliance', 'picturedesk', 'action press',
			'keystone', 'abaca', 'backgrid', 'ddp images', 'profimedia', 'tass',
			'ria novosti', 'sputnik',
		];
		$extra = [];
		if (class_exists('EPV2_Settings')) {
			$raw = EPV2_Settings::get('media_credit_blocklist', []);
			if (is_string($raw)) {
				$raw = preg_split('/[,\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
			}
			if (is_array($raw)) {
				$extra = array_map(static fn($d) => mb_strtolower(trim((string) $d)), $raw);
			}
		}
		return array_values(array_unique(array_merge($defaults, array_filter($extra))));
	}

	/**
	 * True, если подпись фото называет фото-агентство из блоклиста.
	 * При пустой подписи — false (не блокируем: нет сигнала = не трогаем).
	 */
	public static function image_credit_is_blacklisted(string $credit): bool {
		$credit = mb_strtolower(trim($credit));
		if ($credit === '') {
			return false;
		}
		foreach (self::agency_credit_patterns() as $needle) {
			if ($needle !== '' && str_contains($credit, $needle)) {
				return true;
			}
		}
		return false;
	}

	public static function resolve_featured_media(string $title, string $excerpt = '', array $categories = [], string $existing_url = '', array $source_dossier = [], int $queue_id = 0): string {
		$categories = self::normalize_media_categories($categories);
		$story_context = self::story_context_from_dossier($title, $excerpt, $categories, $source_dossier);
		$existing_url = esc_url_raw($existing_url);
		$dossier_image = self::source_dossier_image($source_dossier, $title, $excerpt, $categories, $queue_id, $story_context);
		if ($dossier_image !== '') {
			return $dossier_image;
		}

		if ($existing_url !== '' && ! self::is_fallback_stock_url($existing_url)) {
			foreach (self::source_image_candidates($existing_url) as $candidate) {
				if (! self::looks_like_image_url($candidate) || self::looks_like_low_value_derivative($candidate)) {
					continue;
				}
				if (! self::recently_used($candidate, 30, 0, $queue_id) && self::media_relevant($candidate, $title, $excerpt, $categories, $source_dossier)) {
					return $candidate;
				}
			}
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
				if (! self::looks_like_image_url($candidate) || self::looks_like_low_value_derivative($candidate)) {
					continue;
				}
				if (! self::recently_used($candidate, 30, 0, $queue_id) && self::media_relevant($candidate, $title, $excerpt, $categories, $source_dossier)) {
					return $candidate;
				}
			}
		}

		$wikimedia_fallback_allowed = self::stock_fallback_allowed($title, $excerpt, $categories, $source_dossier, $story_context, 'wikimedia');
		$pexels_fallback_allowed = self::stock_fallback_allowed($title, $excerpt, $categories, $source_dossier, $story_context, 'pexels');
		if (! $wikimedia_fallback_allowed && ! $pexels_fallback_allowed) {
			return '';
		}

		if ($wikimedia_fallback_allowed) {
			$wikimedia_image = self::wikimedia_media($title, $excerpt, $categories, $queue_id, $source_dossier);
			if ($wikimedia_image !== '') {
				return $wikimedia_image;
			}
			// Openverse — после Commons (он шире: + Flickr CC, Europeana).
			// Гейтится той же CC-разрешалкой, что и Wikimedia.
			$openverse_image = self::openverse_media($title, $excerpt, $categories, $queue_id, $source_dossier);
			if ($openverse_image !== '') {
				return $openverse_image;
			}
			// DVIDS — public-domain гос-фото (оборона/Украина). Только при ключе.
			$dvids_image = self::dvids_media($title, $excerpt, $categories, $queue_id, $source_dossier);
			if ($dvids_image !== '') {
				return $dvids_image;
			}
		}

		if ($pexels_fallback_allowed) {
			$pexels_image = self::pexels_media($title, $excerpt, $categories, $queue_id, $source_dossier);
			if ($pexels_image !== '') {
				return $pexels_image;
			}
		}

		return '';
	}

	public static function generated_story_cover(string $title, string $excerpt = '', array $categories = [], array $source_dossier = []): string {
		// Disabled per editorial decision: synthetic title-baked covers were
		// rendered in DE only and reused on UK/EN translations, producing
		// language-mismatch on /uk/ and /en/ archives. Items without a real
		// photo now fall through to the publisher throw → manual_review,
		// where the operator either attaches a real image or rejects.
		return '';
		$title = trim(wp_strip_all_tags($title));
		$excerpt = trim(wp_strip_all_tags($excerpt));
		if ($title === '' || ! extension_loaded('gd')) {
			return '';
		}
		$upload = wp_upload_dir();
		if (! empty($upload['error']) || empty($upload['basedir']) || empty($upload['baseurl'])) {
			return '';
		}
		$dir = trailingslashit((string) $upload['basedir']) . 'epv2-generated-covers';
		if (! wp_mkdir_p($dir)) {
			return '';
		}
		$host = self::cover_source_host($source_dossier);
		$category = sanitize_key((string) ($categories[0] ?? ''));
		$signature = hash('sha256', wp_json_encode([
			'title' => $title,
			'excerpt' => $excerpt,
			'category' => $category,
			'host' => $host,
		], JSON_UNESCAPED_UNICODE));
		$file = $dir . '/' . $signature . '.png';
		$url = trailingslashit((string) $upload['baseurl']) . 'epv2-generated-covers/' . $signature . '.png';
		if (file_exists($file) && filesize($file) > 0) {
			return esc_url_raw($url);
		}
		$image = imagecreatetruecolor(1600, 900);
		if (! $image) {
			return '';
		}
		imageantialias($image, true);
		[$start, $end, $accent] = self::cover_palette($category);
		for ($y = 0; $y < 900; $y++) {
			$t = $y / 899;
			$r = (int) round($start[0] + (($end[0] - $start[0]) * $t));
			$g = (int) round($start[1] + (($end[1] - $start[1]) * $t));
			$b = (int) round($start[2] + (($end[2] - $start[2]) * $t));
			$color = imagecolorallocate($image, $r, $g, $b);
			imageline($image, 0, $y, 1600, $y, $color);
		}
		$white = imagecolorallocate($image, 248, 249, 250);
		$muted = imagecolorallocate($image, 214, 218, 224);
		$accentColor = imagecolorallocate($image, $accent[0], $accent[1], $accent[2]);
		imagefilledrectangle($image, 88, 84, 312, 96, $accentColor);
		$fontBold = self::cover_font_path(true);
		$fontRegular = self::cover_font_path(false);
		$sourceLine = strtoupper(str_replace(['http://', 'https://', 'www.'], '', $host !== '' ? $host : 'europulse'));
		$titleLines = self::cover_wrap_text($title, 52, 4);
		$excerptLines = self::cover_wrap_text($excerpt, 78, 3);
		if ($fontBold !== '') {
			imagettftext($image, 26, 0, 92, 150, $muted, $fontBold, $sourceLine);
			$y = 270;
			foreach ($titleLines as $line) {
				imagettftext($image, 46, 0, 92, $y, $white, $fontBold, $line);
				$y += 66;
			}
			$y += 24;
			foreach ($excerptLines as $line) {
				imagettftext($image, 24, 0, 96, $y, $muted, $fontRegular !== '' ? $fontRegular : $fontBold, $line);
				$y += 38;
			}
			$tag = strtoupper($category !== '' ? str_replace('-', ' ', $category) : 'NEWS');
			imagettftext($image, 22, 0, 92, 820, $accentColor, $fontBold, $tag);
		} else {
			imagestring($image, 5, 92, 126, $sourceLine, $muted);
			$y = 230;
			foreach ($titleLines as $line) {
				imagestring($image, 5, 92, $y, $line, $white);
				$y += 34;
			}
			$y += 18;
			foreach ($excerptLines as $line) {
				imagestring($image, 4, 96, $y, $line, $muted);
				$y += 24;
			}
		}
		if (! imagepng($image, $file, 8)) {
			imagedestroy($image);
			return '';
		}
		imagedestroy($image);
		return esc_url_raw($url);
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
			if (self::is_trusted_editorial_entry($entry)) {
				return $image;
			}
			if (! self::entry_context_relevant($title, $excerpt, $entryTitle, $entryExcerpt, $categories, $story_context)) {
				continue;
			}
			$validation = self::validate_featured_media($image, 0, $entryTitle !== '' ? $entryTitle : $title);
			if (empty($validation['ok'])) {
				continue;
			}
			if (! self::media_relevant($image, trim($entryTitle . ' ' . $title), trim($entryExcerpt . ' ' . $excerpt), $categories, $source_dossier)) {
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
			$details = [
				'alt' => trim((string) ($photo['alt'] ?? '')),
				'caption' => trim((string) ($photo['alt'] ?? '')),
				'source_label' => trim((string) (($photo['photographer'] ?? '') !== '' ? ($photo['photographer'] . ' / Pexels') : 'Pexels')),
			];
			if (self::media_relevant_with_details($url, $title, $excerpt, $categories, $source_dossier, $details)) {
				return $url;
			}
		}
		return '';
	}

	private static function context_supporting_image(string $title, string $excerpt, array $categories, array $source_dossier, int $queue_id = 0, array $story_context = []): string {
		$queries = self::media_context_queries($story_context);
		if ($queries === []) {
			return '';
		}
		$primary = is_array($source_dossier['primary'] ?? null) ? (array) $source_dossier['primary'] : [];
		$primary_host = mb_strtolower((string) ($primary['host'] ?? wp_parse_url((string) ($primary['url'] ?? ''), PHP_URL_HOST)));
		$primary_title = mb_strtolower(trim((string) ($primary['title'] ?? '')));
		$is_google_wrapper_primary = str_contains($primary_host, 'news.google.com')
			|| str_contains($primary_title, 'before you continue to google');

		$seen = [];
		foreach ($queries as $query) {
			$feeds = $is_google_wrapper_primary
				? [
					'https://www.bing.com/news/search?q=' . rawurlencode($query) . '&format=rss',
				]
				: [
					'https://www.bing.com/news/search?q=' . rawurlencode($query) . '&format=rss',
					'https://news.google.com/rss/search?q=' . rawurlencode($query) . '&hl=de&gl=DE&ceid=DE:de',
				];
			foreach ($feeds as $feed) {
				try {
					$candidates = EPV2_Feed_Reader::fetch($feed, str_contains($feed, 'news.google.com'));
				} catch (Throwable $e) {
					continue;
				}
				foreach ($candidates as $candidate) {
					$url = esc_url_raw((string) ($candidate['url'] ?? ''));
					$url_host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
					if ($is_google_wrapper_primary && str_contains($url_host, 'news.google.com')) {
						continue;
					}
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
					return $image;
				}
			}
		}

		return '';
	}

	private static function media_context_queries(array $story_context): array {
		$story_context = self::normalize_story_context($story_context);
		$queries = [];
		$text = trim((string) ($story_context['text'] ?? ''));
		if ($text !== '') {
			$titleish = self::title_keyword_query($text, 8);
			if ($titleish !== '') {
				$queries[] = $titleish;
				$queries[] = $titleish . ' foto';
				$queries[] = $titleish . ' bild';
			}
		}
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

	private static function cover_source_host(array $source_dossier): string {
		$primary = is_array($source_dossier['primary'] ?? null) ? $source_dossier['primary'] : [];
		$url = (string) ($primary['url'] ?? '');
		$host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$host = preg_replace('/^www\./i', '', $host ?? '');
		return trim((string) $host);
	}

	private static function cover_palette(string $category): array {
		return match ($category) {
			'sport' => [[15, 56, 44], [24, 102, 78], [135, 255, 179]],
			'politik' => [[44, 24, 24], [112, 34, 34], [255, 196, 92]],
			'wirtschaft' => [[24, 34, 52], [42, 86, 134], [136, 223, 255]],
			'kultur' => [[58, 32, 44], [117, 54, 89], [255, 195, 221]],
			'welt' => [[28, 31, 61], [48, 67, 130], [189, 211, 255]],
			default => [[28, 30, 36], [54, 61, 73], [255, 210, 128]],
		};
	}

	private static function cover_font_path(bool $bold): string {
		$candidates = $bold
			? [
				'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
				'/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
			]
			: [
				'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
				'/usr/share/fonts/TTF/DejaVuSans.ttf',
			];
		foreach ($candidates as $candidate) {
			if (is_file($candidate)) {
				return $candidate;
			}
		}
		return '';
	}

	private static function cover_wrap_text(string $text, int $maxChars, int $maxLines): array {
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
		if ($text === '') {
			return [];
		}
		$words = preg_split('/\s+/u', $text) ?: [];
		$lines = [];
		$current = '';
		foreach ($words as $word) {
			$candidate = $current === '' ? $word : ($current . ' ' . $word);
			if (mb_strlen($candidate) <= $maxChars) {
				$current = $candidate;
				continue;
			}
			if ($current !== '') {
				$lines[] = $current;
			}
			$current = $word;
			if (count($lines) >= ($maxLines - 1)) {
				break;
			}
		}
		if ($current !== '' && count($lines) < $maxLines) {
			$lines[] = $current;
		}
		if (count($lines) > $maxLines) {
			$lines = array_slice($lines, 0, $maxLines);
		}
		if ($lines !== [] && count($words) > 0) {
			$joined = implode(' ', $lines);
			if (mb_strlen($joined) < mb_strlen($text)) {
				$last = array_pop($lines);
				$last = rtrim(mb_substr($last, 0, max(8, $maxChars - 1))) . '…';
				$lines[] = $last;
			}
		}
		return $lines;
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

		$tmp = self::download_media_tempfile($url);
		if (is_wp_error($tmp)) {
			return [];
		}

		$file = [
			'name' => self::sideload_filename($url, $tmp),
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

	private static function sideload_filename(string $url, string $tmp): string {
		$path = (string) parse_url($url, PHP_URL_PATH);
		$name = sanitize_file_name(wp_basename($path !== '' ? $path : 'image'));
		$extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
		if ($extension !== '') {
			return $name;
		}

		$tmpExtension = strtolower((string) pathinfo($tmp, PATHINFO_EXTENSION));
		if ($tmpExtension !== '') {
			return $name . '.' . $tmpExtension;
		}

		$mime = function_exists('mime_content_type') ? (string) @mime_content_type($tmp) : '';
		$map = [
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/gif' => 'gif',
		];
		if ($mime !== '' && isset($map[$mime])) {
			return $name . '.' . $map[$mime];
		}

		return $name . '.jpg';
	}

	private static function download_media_tempfile(string $url) {
		$tmp = download_url($url, 20);
		if (! is_wp_error($tmp) && self::tempfile_has_content($tmp)) {
			return $tmp;
		}
		if (! is_wp_error($tmp) && is_string($tmp) && $tmp !== '' && file_exists($tmp)) {
			@unlink($tmp);
		}
		return self::download_media_tempfile_via_browser_request($url);
	}

	private static function download_media_tempfile_via_browser_request(string $url) {
		$tmp = wp_tempnam(wp_basename((string) parse_url($url, PHP_URL_PATH)) ?: 'epv2-media.tmp');
		if (! $tmp) {
			return new WP_Error('epv2_media_tempfile', 'Не удалось создать временный файл для media.');
		}
		$response = wp_safe_remote_get($url, [
			'timeout' => 20,
			'redirection' => 5,
			'stream' => true,
			'filename' => $tmp,
			'user-agent' => self::media_download_user_agent(),
			'headers' => self::media_download_headers($url),
		]);
		if (is_wp_error($response)) {
			@unlink($tmp);
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300 || ! self::tempfile_has_content($tmp)) {
			@unlink($tmp);
			return new WP_Error('epv2_media_download_failed', 'Не удалось скачать media-кандидат с редакционным user-agent.');
		}
		return $tmp;
	}

	private static function tempfile_has_content(string $tmp): bool {
		return $tmp !== '' && file_exists($tmp) && (int) @filesize($tmp) > 0;
	}

	private static function media_download_user_agent(): string {
		return 'Mozilla/5.0 (compatible; EuroPulse AutoPilot/1.0; +http://127.0.0.1)';
	}

	private static function media_download_headers(string $url): array {
		$host = (string) wp_parse_url($url, PHP_URL_HOST);
		$referer = $host !== '' ? ('https://' . preg_replace('/^www\./i', '', $host) . '/') : 'https://127.0.0.1/';
		return [
			'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
			'Referer' => $referer,
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
			// Alt-text единственный на attachment (Polylang без Pro не
			// translate'ит media). Если уже set — не overwrite каждый раз;
			// иначе финальная per-language публикация (EN) затирает alt
			// для всех 3 lang versions. Заполняем только если пусто.
			$existing_alt = (string) get_post_meta((int) $media['attachment_id'], '_wp_attachment_image_alt', true);
			if ($existing_alt === '') {
				update_post_meta((int) $media['attachment_id'], '_wp_attachment_image_alt', sanitize_text_field(self::alt_context_title($fallback_title, 'source')));
			}
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

	public static function normalize_featured_candidate_url(string $url): string {
		$url = esc_url_raw($url);
		if ($url === '' || ! self::can_use_featured_url($url)) {
			return '';
		}
		return $url;
	}

	public static function is_technical_asset_url(string $url): bool {
		return self::looks_like_technical_asset($url);
	}

	public static function is_fallback_stock_url(string $url): bool {
		$host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		return str_contains($host, 'pexels.com') || str_contains($host, 'wikimedia.org');
	}

	private static function fallback_stock_provider(string $url, array $details = []): string {
		$provider = sanitize_key((string) ($details['provider'] ?? ''));
		if (in_array($provider, ['pexels', 'wikimedia'], true)) {
			return $provider;
		}
		$host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		if (str_contains($host, 'pexels.com')) {
			return 'pexels';
		}
		if (str_contains($host, 'wikimedia.org')) {
			return 'wikimedia';
		}
		return '';
	}

	private static function normalize_media_categories(array $categories): array {
		$normalized = [];
		foreach ($categories as $category) {
			$slug = sanitize_key((string) $category);
			if ($slug === '') {
				continue;
			}
			$slug = preg_replace('/-(de|uk|en)$/i', '', $slug) ?: $slug;
			if (class_exists('EPV2_Taxonomy_Map')) {
				$slug = EPV2_Taxonomy_Map::normalize_slug($slug);
			}
			if ($slug !== '') {
				$normalized[$slug] = true;
			}
		}
		return array_keys($normalized);
	}

	public static function is_generated_story_cover_url(string $url): bool {
		$url = esc_url_raw($url);
		if ($url === '') {
			return false;
		}
		$path = rawurldecode(mb_strtolower((string) wp_parse_url($url, PHP_URL_PATH)));
		return str_contains($path, '/epv2-generated-covers/') && str_ends_with($path, '.png');
	}

	public static function is_source_host_media(string $url, array $source_dossier = []): bool {
		$url = esc_url_raw($url);
		if ($url === '') {
			return false;
		}
		$image_host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		if ($image_host === '') {
			return false;
		}
		$entries = [];
		foreach (['primary', 'shell_primary'] as $key) {
			if (is_array($source_dossier[$key] ?? null)) {
				$entries[] = (array) $source_dossier[$key];
			}
		}
		foreach ((array) ($source_dossier['supporting'] ?? []) as $entry) {
			if (is_array($entry)) {
				$entries[] = $entry;
			}
		}
		foreach ($entries as $entry) {
			$entry_url = esc_url_raw((string) ($entry['url'] ?? ''));
			$entry_host = mb_strtolower((string) wp_parse_url($entry_url, PHP_URL_HOST));
			if ($entry_host === '') {
				continue;
			}
			if (! self::same_source_host($image_host, $entry_host)) {
				continue;
			}
			$validation = self::validate_featured_media($url, 0, '');
			if (! empty($validation['ok']) && ! empty($validation['media']['usable'])) {
				return true;
			}
		}
		return false;
	}

	public static function media_credit(string $url, string $lang = 'de'): string {
		$details = self::remote_media_details($url);
		$label = trim((string) ($details['source_label'] ?? ''));
		if ($label === '') {
			$host = (string) wp_parse_url($url, PHP_URL_HOST);
			$label = $host !== '' ? preg_replace('/^www\./i', '', $host) : '';
		}
		return $label !== '' ? self::credit_prefix($lang) . ': ' . $label : '';
	}

	public static function media_metadata(string $url, string $lang = 'de', string $fallback_title = ''): array {
		$url = esc_url_raw(trim($url));
		if ($url === '') {
			return [
				'origin_url' => '',
				'credit' => '',
				'caption' => '',
				'source_label' => '',
				'provider' => '',
			];
		}
		$details = self::remote_media_details($url);
		return [
			'origin_url' => esc_url_raw((string) ($details['origin_url'] ?? $url)),
			'credit' => self::media_credit($url, $lang),
			'caption' => self::media_caption($url, $lang, $fallback_title),
			'source_label' => trim((string) ($details['source_label'] ?? '')),
			'provider' => (string) ($details['provider'] ?? ''),
		];
	}

	public static function media_diagnostics(string $url, string $title, string $excerpt = '', array $categories = [], array $source_dossier = []): array {
		$url = esc_url_raw(trim($url));
		$categories = self::normalize_media_categories($categories);
		$details = $url !== '' ? self::remote_media_details($url) : [];
		$story_context = self::story_context_from_dossier($title, $excerpt, $categories, $source_dossier);
		$provider = sanitize_key((string) ($details['provider'] ?? ''));
		$stock_provider = self::fallback_stock_provider($url, $details);
		if ($provider === '' && $stock_provider !== '') {
			$provider = $stock_provider;
		}
		$origin = esc_url_raw((string) ($details['origin_url'] ?? $url));
		$origin_host = mb_strtolower((string) wp_parse_url($origin, PHP_URL_HOST));
		$categories = self::normalize_media_categories($categories);
		$stock_allowed = [
			'wikimedia' => self::stock_fallback_allowed($title, $excerpt, $categories, $source_dossier, $story_context, 'wikimedia'),
			'pexels' => self::stock_fallback_allowed($title, $excerpt, $categories, $source_dossier, $story_context, 'pexels'),
		];
		$fit_pass = $url !== '' && self::media_relevant_with_details($url, $title, $excerpt, $categories, $source_dossier, $details);
		if ($stock_provider !== '') {
			$fit_pass = $fit_pass && ! empty($stock_allowed[$stock_provider]);
		}

		return [
			'provider' => $provider,
			'origin_url' => $origin,
			'origin_host' => $origin_host,
			'source_label' => trim((string) ($details['source_label'] ?? '')),
			'alt' => trim((string) ($details['alt'] ?? '')),
			'is_stock_fallback' => $stock_provider !== '',
			'is_source_host_media' => $url !== '' && self::is_source_host_media($url, $source_dossier),
			'intent' => self::media_intent($title, $excerpt, $categories),
			'fit_pass' => $fit_pass,
			'stock_allowed' => $stock_allowed,
			'risk_flags' => self::media_risk_flags($title, $excerpt, $categories, $details, $story_context, $source_dossier),
			'context' => [
				'categories' => $categories,
				'tokens' => array_slice((array) ($story_context['tokens'] ?? []), 0, 12),
				'phrases' => array_slice((array) ($story_context['phrases'] ?? []), 0, 12),
			],
		];
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
		// Story card override: when the upfront semantic pass identified
		// concrete visual hooks (e.g. "MV Hondius cruise ship",
		// "police academy graduation"), search Pexels directly with those
		// terms instead of running through the keyword heuristic. Card
		// terms are LLM-curated and far more precise than title regex.
		$card_query = self::story_card_media_query($source_dossier);
		if ($card_query !== '') {
			return $card_query;
		}
		if (self::is_heritage_story($text)) {
			$precise = self::title_keyword_query($title, 7);
			if ($precise !== '') {
				return $precise . ' deutschland kulturelles erbe tradition';
			}
			return 'deutschland kulturelles erbe tradition unesco';
		}
		$event_query = self::event_media_query($source_dossier, $categories, 'pexels');
		if ($event_query !== '') {
			return $event_query;
		}
		$rules = [
			'/\b(immateriell(?:es)?\s+kulturerbe|kulturerbe|unesco|heritage|verzeichnis)\b/u' => 'Germany cultural heritage tradition unesco',
			'/\b(tatort|fernsehgeschichte|fernseh|tv|kommissare|krimireihe|schauspieler|serie|sendung|fanpremiere)\b/u' => 'television studio audience actors germany',
			'/\b(polizeihochschule|polizeischule|kommissar(?:innen|e)?|polizeinachwuchs|polizei(?:studium|ausbildung)?|police academy|police graduates|police cadets)\b/u' => 'police academy cadets training germany',
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
				'welt' => 'world map diplomacy conference',
				'muenchen' => 'munich street tram city',
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
		$primary_entries = [];
		$primary = is_array($source_dossier['primary'] ?? null) ? (array) $source_dossier['primary'] : [];
		if ($primary !== []) {
			$primary_entries[] = $primary;
		}
		$shell_primary = is_array($source_dossier['shell_primary'] ?? null) ? (array) $source_dossier['shell_primary'] : [];
		if ($shell_primary !== []) {
			$primary_entries[] = $shell_primary;
		}
		foreach ($primary_entries as $primary_entry) {
			// Агентское фото (Reuters/dpa/AFP/Getty…) — не берём, юр-риск. 2026-06-10.
			if (self::image_credit_is_blacklisted((string) ($primary_entry['image_credit'] ?? ''))) {
				continue;
			}
			$entryTitle = trim((string) ($primary_entry['title'] ?? ''));
			$entryExcerpt = trim((string) ($primary_entry['excerpt'] ?? ''));
			$entryLooksRelevant = self::entry_context_relevant($title, $excerpt, $entryTitle, $entryExcerpt, $categories, $story_context);
			$entryHost = strtolower((string) wp_parse_url((string) ($primary_entry['url'] ?? ''), PHP_URL_HOST));
			$trustedEditorialEntry = self::is_trusted_editorial_entry($primary_entry);
			$candidate = (string) ($primary_entry['image'] ?? '');
			foreach (self::source_image_candidates($candidate) as $variant) {
				$variant = esc_url_raw($variant);
				$allowRecentReuse = self::entry_allows_recent_reuse($primary_entry, $variant);
				if ($variant === '' || ! self::looks_like_image_url($variant) || self::looks_like_technical_asset($variant) || self::looks_like_low_value_derivative($variant) || (! $allowRecentReuse && self::recently_used($variant, 30, 0, $queue_id))) {
					continue;
				}
				$variantHost = strtolower((string) wp_parse_url($variant, PHP_URL_HOST));
				$sameSourceHost = self::same_source_host($entryHost, $variantHost);
				$validatedPrimary = self::validate_featured_media($variant, 0, $title);
				$usablePrimary = ! empty($validatedPrimary['ok']) && ! empty($validatedPrimary['media']['usable']);
				if ($usablePrimary && $trustedEditorialEntry && $sameSourceHost) {
					return $variant;
				}
				if (
					$usablePrimary
					&& (
						self::media_relevant($variant, trim($entryTitle . ' ' . $title), trim($entryExcerpt . ' ' . $excerpt), $categories, $source_dossier)
						|| ($entryLooksRelevant && $sameSourceHost)
						|| self::entry_allows_recent_reuse($primary_entry, $variant)
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
			// Агентское фото в supporting-источнике — тоже пропускаем. 2026-06-10.
			if (self::image_credit_is_blacklisted((string) ($entry['image_credit'] ?? ''))) {
				continue;
			}
			$entryTitle = trim((string) ($entry['title'] ?? ''));
			$entryExcerpt = trim((string) ($entry['excerpt'] ?? ''));
			$entryLooksRelevant = self::entry_context_relevant($title, $excerpt, $entryTitle, $entryExcerpt, $categories, $story_context);
			$trustedEditorialEntry = self::is_trusted_editorial_entry($entry);
			$candidate = (string) ($entry['image'] ?? '');
			foreach (self::source_image_candidates($candidate) as $variant) {
				$variant = esc_url_raw($variant);
				$allow_recent_reuse = self::entry_allows_recent_reuse($entry, $variant);
				if ($variant === '' || ! self::looks_like_image_url($variant) || self::looks_like_technical_asset($variant) || self::looks_like_low_value_derivative($variant) || (! $allow_recent_reuse && self::recently_used($variant, 30, 0, $queue_id))) {
					continue;
				}
				$validated = self::validate_featured_media($variant, 0, $entryTitle !== '' ? $entryTitle : $title);
				$usable = ! empty($validated['ok']) && ! empty($validated['media']['usable']);
				if ($usable && $trustedEditorialEntry) {
					return $variant;
				}
				$visualLooksRelevant = self::media_relevant($variant, trim($entryTitle . ' ' . $title), trim($entryExcerpt . ' ' . $excerpt), $categories, $source_dossier);
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

	private static function is_trusted_editorial_entry(array $entry): bool {
		if (! empty($entry['is_official'])) {
			return true;
		}
		$url = esc_url_raw((string) ($entry['url'] ?? ''));
		$host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$source_label = mb_strtolower(trim((string) ($entry['source_label'] ?? $entry['source_name'] ?? '')));
		return self::is_editorial_news_source($host, $source_label);
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
		$left = preg_replace('/^www\./i', '', trim(strtolower($left)));
		$right = preg_replace('/^www\./i', '', trim(strtolower($right)));
		if ($left === '' || $right === '') {
			return false;
		}
		if ($left === $right) {
			return true;
		}
		if (
			(str_contains($left, 'bbc.') || str_contains($left, '.bbc') || str_contains($left, 'bbci.'))
			&& (str_contains($right, 'bbc.') || str_contains($right, '.bbc') || str_contains($right, 'bbci.'))
		) {
			return true;
		}
		$left_family = self::publisher_family($left);
		$right_family = self::publisher_family($right);
		if ($left_family !== '' && $left_family === $right_family) {
			return true;
		}
		// CDN-to-publisher aliases. Top-tier outlets serve hero images
		// from a separate CDN whose registered domain does not share a
		// family name with the article host. Without this, the publish
		// gate's "media must come from the source host" check fires
		// even when the image is unambiguously the article's own photo
		// (e.g., theguardian.com article → i.guim.co.uk image).
		$left_alias = self::cdn_publisher_alias($left);
		$right_alias = self::cdn_publisher_alias($right);
		if ($left_alias !== '' && ($left_alias === $right_family || $left_alias === $right_alias)) {
			return true;
		}
		if ($right_alias !== '' && ($right_alias === $left_family || $right_alias === $left_alias)) {
			return true;
		}
		return str_ends_with($left, '.' . $right) || str_ends_with($right, '.' . $left);
	}

	/**
	 * Map known publisher CDN hosts to the same family as their editorial
	 * domain so `same_source_host()` accepts CDN-served hero images.
	 * Returns '' for unknown hosts (caller falls back to publisher_family).
	 */
	private static function cdn_publisher_alias(string $host): string {
		$host = preg_replace('/^www\./i', '', trim(strtolower($host)));
		if ($host === '') {
			return '';
		}
		$aliases = [
			'guim.co.uk'         => 'theguardian',
			'guimcode.co.uk'     => 'theguardian',
			'static.guim.co.uk'  => 'theguardian',
			'images.handelsblatt.com' => 'handelsblatt',
			'spiegel.de'         => 'spiegel',
			'a1.spiegel.media'   => 'spiegel',
			'a2.spiegel.media'   => 'spiegel',
			'spiegel.media'      => 'spiegel',
			'cdn.faz.net'        => 'faz',
			'media.faz.net'      => 'faz',
			'static.zeit.de'     => 'zeit',
			'images.zeit.de'     => 'zeit',
			'img.zeit.de'        => 'zeit',
			'cdn.tagesschau.de'  => 'tagesschau',
			'images.tagesschau.de' => 'tagesschau',
			'image.tagesschau.de' => 'tagesschau',
			'reutersmedia.net'   => 'reuters',
			'cloudfront-eu-central-1.images.arcpublishing.com' => 'reuters',
			'media.zenfs.com'    => 'yahoo',
			's.yimg.com'         => 'yahoo',
			'img.welt.de'        => 'welt',
			'cdn.welt.de'        => 'welt',
			'static.dw.com'      => 'dw',
			'static.euronews.com' => 'euronews',
			'cdn.euronews.com'   => 'euronews',
			'kyivindependent.com' => 'kyivindependent',
			'cdn.kyivindependent.com' => 'kyivindependent',
			// Heise CDN — observed 2026-05-11: heise.cloudimg.io serves
			// hero images для heise.de articles.
			'heise.cloudimg.io'  => 'heise',
			// BR (Bayerischer Rundfunk) image CDN.
			'img.br.de'          => 'br',
			'cdn.br.de'          => 'br',
			'media.br.de'        => 'br',
			// MDR image CDN.
			'img.mdr.de'         => 'mdr',
			'cdn.mdr.de'         => 'mdr',
			// Major DE publishers CDNs (P1.5 added 2026-05-11):
			// ZDF
			'teaser.zdf.de'      => 'zdf',
			'bilder.zdf.de'      => 'zdf',
			'cdn.zdf.de'         => 'zdf',
			// ARD / Tagesschau (extends existing tagesschau aliases)
			'bilder.ardmediathek.de' => 'ardmediathek',
			'images.ardmediathek.de' => 'ardmediathek',
			'cdn.ardmediathek.de'    => 'ardmediathek',
			// Bild
			'bilder.bild.de'     => 'bild',
			'images.bild.de'     => 'bild',
			'cdn.bild.de'        => 'bild',
			// Süddeutsche
			'media.sz.de'        => 'sueddeutsche',
			'cdn.sueddeutsche.de' => 'sueddeutsche',
			'images.sueddeutsche.de' => 'sueddeutsche',
			// NZZ
			'img.nzz.ch'         => 'nzz',
			'cdn.nzz.ch'         => 'nzz',
			// Tagesspiegel
			'images.tagesspiegel.de' => 'tagesspiegel',
			'cdn.tagesspiegel.de' => 'tagesspiegel',
			// Stern
			'image.stern.de'     => 'stern',
			'cdn.stern.de'       => 'stern',
			// Focus
			'b.fcs.de'           => 'focus',
			'images.focus.de'    => 'focus',
			// n-tv (multiple shards)
			'bilder1.n-tv.de'    => 'n-tv',
			'bilder2.n-tv.de'    => 'n-tv',
			'bilder3.n-tv.de'    => 'n-tv',
			'bilder4.n-tv.de'    => 'n-tv',
			'cdn.n-tv.de'        => 'n-tv',
			// taz
			'static.taz.de'      => 'taz',
			'taz.de'             => 'taz',
			// Frankfurter Rundschau
			'cdn.fr.de'          => 'fr',
			'images.fr.de'       => 'fr',
			// FAZ-Frankfurter Rundschau (fr.de) and BR share regional family
			// nothing here — different publishers, NO alias. fr.de и mdr.de
			// остаются separate. Cross-publisher mismatch остаётся flagged.
		];
		foreach ($aliases as $suffix => $family) {
			if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
				return $family;
			}
		}
		return '';
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

	/**
	 * Openverse-провайдер (2026-06-10). Агрегирует ~800М CC/PD-работ (Commons,
	 * Flickr CC, Europeana и др.) с нормализованной лицензией и готовой строкой
	 * атрибуции. Берём ТОЛЬКО коммерчески-безопасные лицензии (cc0, pdm, by,
	 * by-sa) — NC/ND исключаем (сайт коммерческий). Запрос строится из тех же
	 * семантических терминов story card, что и Wikimedia. Атрибуция кладётся в
	 * store_media_attribution() и потом рендерится как кредит под фото.
	 */
	private static function openverse_media(string $title, string $excerpt, array $categories, int $queue_id = 0, array $source_dossier = []): string {
		$query = self::wikimedia_query($title, $excerpt, $categories, $source_dossier);
		if ($query === '') {
			return '';
		}
		// Openverse API за Cloudflare: анонимные серверные запросы блокируются
		// (403). Нужен OAuth bearer-токен (зарегистрировать приложение на
		// api.openverse.org → client_credentials). Без токена тихо no-op, и
		// CC-покрытие обеспечивает Wikimedia. Токен берём из настроек.
		$token = '';
		if (class_exists('EPV2_Settings')) {
			$token = trim((string) EPV2_Settings::get('openverse_token', ''));
		}
		if ($token === '') {
			return '';
		}
		$endpoint = 'https://api.openverse.org/v1/images/?q=' . rawurlencode($query)
			. '&license=cc0,pdm,by,by-sa&page_size=8&mature=false';
		$response = wp_remote_get($endpoint, [
			'timeout' => 18,
			'user-agent' => 'EuroPulse AutoPilot (+https://europulse.today)',
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
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
		$results = (array) ($data['results'] ?? []);
		foreach ($results as $item) {
			if (! is_array($item)) {
				continue;
			}
			$img = (string) ($item['url'] ?? '');
			if ($img === '') {
				continue;
			}
			$license = mb_strtolower((string) ($item['license'] ?? ''));
			// Двойная защита: NC/ND нельзя для коммерческого сайта.
			if ($license === '' || str_contains($license, 'nc') || str_contains($license, 'nd')) {
				continue;
			}
			$titleText = mb_strtolower((string) ($item['title'] ?? ''));
			if (preg_match('/logo|map|flag|icon|diagram|seal|coat of arms|clipart|chart|infographic|screenshot/u', $titleText)) {
				continue;
			}
			if (preg_match('/\.svg(?:\?|$)/iu', $img) || preg_match('/\.pdf(?:\.|$)/iu', $img)) {
				continue;
			}
			if (self::recently_used($img, 30, 0, $queue_id)) {
				continue;
			}
			$img = esc_url_raw($img);
			$creator = trim((string) ($item['creator'] ?? ''));
			$lic_name = trim(strtoupper((string) ($item['license'] ?? '')) . ' ' . (string) ($item['license_version'] ?? ''));
			$label_parts = array_filter([$creator !== '' ? $creator : '', $lic_name, 'Openverse']);
			$details = [
				'alt' => (string) ($item['title'] ?? ''),
				'caption' => '',
				'source_label' => implode(' / ', $label_parts),
				'provider' => 'openverse',
				'origin_url' => (string) ($item['foreign_landing_url'] ?? $img),
			];
			if (self::media_relevant_with_details($img, $title, $excerpt, $categories, $source_dossier, $details)) {
				self::store_media_attribution($img, $details);
				return $img;
			}
		}
		return '';
	}

	/**
	 * DVIDS-провайдер (2026-06-10) — Defense Visual Information Distribution
	 * Service армии США. Public domain, релевантные событийные фото по теме
	 * обороны/Украины/войны (ядро аудитории). Требует api_key (бесплатный,
	 * dvidshub.net) → epv2_settings['dvids_api_key']. Без ключа тихо no-op.
	 * Атрибуция «DVIDS / автор» (public domain — кредит вежливости).
	 */
	private static function dvids_media(string $title, string $excerpt, array $categories, int $queue_id = 0, array $source_dossier = []): string {
		$key = '';
		if (class_exists('EPV2_Settings')) {
			$key = trim((string) EPV2_Settings::get('dvids_api_key', ''));
		}
		if ($key === '') {
			return '';
		}
		$query = self::wikimedia_query($title, $excerpt, $categories, $source_dossier);
		if ($query === '') {
			return '';
		}
		$endpoint = 'https://api.dvidshub.net/search?api_key=' . rawurlencode($key)
			. '&q=' . rawurlencode($query) . '&type=image&sort=date&max_results=8';
		$response = wp_remote_get($endpoint, [
			'timeout' => 18,
			'user-agent' => 'EuroPulse AutoPilot (+https://europulse.today)',
		]);
		if (is_wp_error($response)) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return '';
		}
		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		foreach ((array) ($data['results'] ?? []) as $item) {
			if (! is_array($item)) {
				continue;
			}
			$img = (string) ($item['image'] ?? $item['url'] ?? '');
			if ($img === '' || ! self::looks_like_image_url($img)) {
				continue;
			}
			if (self::recently_used($img, 30, 0, $queue_id)) {
				continue;
			}
			$img = esc_url_raw($img);
			$author = trim((string) ($item['credit'] ?? ''));
			$details = [
				'alt' => (string) ($item['title'] ?? ''),
				'caption' => (string) ($item['description'] ?? ''),
				'source_label' => trim(($author !== '' ? $author . ' / ' : '') . 'DVIDS'),
				'provider' => 'dvids',
				'origin_url' => (string) ($item['url'] ?? $img),
			];
			if (self::media_relevant_with_details($img, $title, $excerpt, $categories, $source_dossier, $details)) {
				self::store_media_attribution($img, $details);
				return $img;
			}
		}
		return '';
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
			$url = esc_url_raw($url);
			$details = [
				'alt' => (string) ($page['title'] ?? ''),
				'caption' => (string) ($page['imageinfo'][0]['extmetadata']['ImageDescription']['value'] ?? ''),
				'source_label' => 'Wikimedia Commons',
			];
			if (self::media_relevant_with_details($url, $title, $excerpt, $categories, $source_dossier, $details)) {
				return $url;
			}
		}
		return '';
	}

	private static function wikimedia_query(string $title, string $excerpt, array $categories, array $source_dossier = []): string {
		$text = mb_strtolower(trim(wp_strip_all_tags($title . ' ' . $excerpt)));
		// Wikimedia is best for named-entity images (people, places,
		// monuments, organisations). The story card has already extracted
		// the concrete entities we want — use those directly when present.
		$card_query = self::story_card_media_query($source_dossier);
		if ($card_query !== '') {
			return $card_query;
		}
		$entity_query = self::entity_media_query($text);
		if ($entity_query !== '') {
			return $entity_query;
		}
		if (self::is_heritage_story($text)) {
			$precise = self::title_keyword_query($title, 8);
			if ($precise !== '') {
				return $precise . ' deutschland kulturelles erbe';
			}
			return 'deutschland kulturelles erbe unesco tradition';
		}
		$event_query = self::event_media_query($source_dossier, $categories, 'wikimedia');
		if ($event_query !== '') {
			return $event_query;
		}
		$rules = [
			'/\b(immateriell(?:es)?\s+kulturerbe|kulturerbe|unesco|heritage|verzeichnis)\b/u' => 'Germany cultural heritage unesco tradition',
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
			if ($category === 'muenchen') {
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

	private static function entity_media_query(string $text): string {
		$entities = [
			'/\bfriedrich\s+merz\b|\bmerz\b/u' => 'Friedrich Merz',
			'/\bboris\s+pistorius\b|\bpistorius\b/u' => 'Boris Pistorius',
			'/\bfrank-walter\s+steinmeier\b|\bsteinmeier\b/u' => 'Frank-Walter Steinmeier',
			'/\bklingbeil\b/u' => 'Lars Klingbeil',
			'/\bsoeder\b|\bsöder\b/u' => 'Markus Söder',
			'/\bweidel\b/u' => 'Alice Weidel',
			'/\bhabeck\b/u' => 'Robert Habeck',
			'/\bbaerbock\b/u' => 'Annalena Baerbock',
		];
		foreach ($entities as $pattern => $query) {
			if (preg_match($pattern, $text) === 1) {
				return $query;
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
			if (self::is_heritage_story($title)) {
				$precise = self::title_keyword_query((string) ($context['event_title'] ?? ''), 8);
				if ($precise !== '') {
					return $provider === 'wikimedia'
						? ($precise . ' deutschland kulturelles erbe')
						: ($precise . ' deutschland kulturelles erbe tradition');
				}
				return $provider === 'wikimedia'
					? 'deutschland kulturelles erbe unesco tradition'
					: 'deutschland kulturelles erbe tradition';
			}
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

	private static function title_keyword_query(string $title, int $limit = 6): string {
		$title = mb_strtolower(trim(wp_strip_all_tags($title)));
		if ($title === '') {
			return '';
		}
		$title = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $title) ?: $title;
		$title = preg_replace('/\s+/u', ' ', $title) ?: $title;
		$stop = [
			'in','im','am','an','der','die','das','den','dem','des','und','mit','für','fuer',
			'ein','eine','einer','eines','zu','zur','zum','von','vom','auf','bundesweiten'
		];
		$words = array_values(array_filter(explode(' ', trim($title)), static function ($word) use ($stop): bool {
			return $word !== '' && ! in_array($word, $stop, true) && mb_strlen($word) >= 3;
		}));
		if ($words === []) {
			return '';
		}
		return implode(' ', array_slice($words, 0, max(3, min(10, $limit))));
	}

	private static function media_caption(string $url, string $lang = 'de', string $fallback_title = ''): string {
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
		$fallback_title = self::caption_context_title($fallback_title);
		if ($fallback_title !== '') {
			return self::context_caption_prefix($lang, (string) ($details['provider'] ?? 'source')) . $fallback_title . '. ' . self::credit_prefix($lang) . ': ' . $label;
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
		$caption = $source_label !== '' ? self::media_caption($resolved_url, 'de', $fallback_title) : '';
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
			$alt = self::alt_context_title($fallback_title, $provider);
		}
		if ($alt !== '') {
			update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
		}
	}

	/**
	 * Персистентное хранение атрибуции картинки по URL (2026-06-10). Нужно для
	 * Openverse: он отдаёт картинки с разных хостов (Flickr, Commons, Europeana),
	 * а remote_media_details() резолвит атрибуцию по хосту — так автор+лицензия
	 * терялись бы. Провайдер кладёт сюда готовый credit, sideload/рендер читают.
	 * CC BY / BY-SA требуют атрибуцию по закону — без этого использование нелегально.
	 */
	private static function store_media_attribution(string $url, array $details): void {
		$url = esc_url_raw(trim($url));
		if ($url === '') {
			return;
		}
		set_transient('epv2_mattr_' . md5($url), $details, 60 * DAY_IN_SECONDS);
	}

	private static function stored_media_attribution(string $url): array {
		$stored = get_transient('epv2_mattr_' . md5($url));
		return is_array($stored) ? $stored : [];
	}

	private static function remote_media_details(string $url): array {
		static $cache = [];
		if (isset($cache[$url])) {
			return $cache[$url];
		}
		// Сохранённая провайдером атрибуция (Openverse и пр.) — высший приоритет.
		$stored = self::stored_media_attribution($url);
		if ($stored !== [] && trim((string) ($stored['source_label'] ?? '')) !== '') {
			$stored['origin_url'] = esc_url_raw((string) ($stored['origin_url'] ?? $url));
			$cache[$url] = $stored;
			return $stored;
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
			'origin_url' => esc_url_raw($resolved_url),
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
			$wikimedia_details = self::wikimedia_details_from_url($resolved_url);
			$details = array_merge($details, [
				'source_label' => 'Wikimedia Commons',
				'provider' => 'wikimedia',
			], $wikimedia_details);
		}

		$site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
		if ($host !== '' && $site_host !== '' && $host === $site_host && $details['provider'] === 'source') {
			$details['source_label'] = self::source_label_from_url($resolved_url);
		}

		$cache[$url] = $details;
		return $details;
	}

	private static function wikimedia_details_from_url(string $url): array {
		$title = self::wikimedia_file_title_from_url($url);
		$details = [
			'alt' => self::humanize_wikimedia_title($title),
		];
		if ($title === '') {
			return $details;
		}

		$response = wp_remote_get('https://commons.wikimedia.org/w/api.php?action=query&titles=' . rawurlencode($title) . '&prop=imageinfo&iiprop=extmetadata|url&iiurlwidth=1600&format=json', [
			'timeout' => 12,
			'user-agent' => 'EuroPulse AutoPilot',
		]);
		if (is_wp_error($response)) {
			return $details;
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return $details;
		}
		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		if (! is_array($data)) {
			return $details;
		}
		$page = null;
		foreach ((array) ($data['query']['pages'] ?? []) as $candidate) {
			if (is_array($candidate)) {
				$page = $candidate;
				break;
			}
		}
		if (! is_array($page)) {
			return $details;
		}
		$imageinfo = (array) ($page['imageinfo'][0] ?? []);
		$metadata = (array) ($imageinfo['extmetadata'] ?? []);
		$description = self::clean_wikimedia_text((string) ($metadata['ImageDescription']['value'] ?? ''));
		$object_name = self::clean_wikimedia_text((string) ($metadata['ObjectName']['value'] ?? ''));
		$artist = self::clean_wikimedia_text((string) ($metadata['Artist']['value'] ?? ''));
		$attribution = self::clean_wikimedia_text((string) ($metadata['Attribution']['value'] ?? ''));
		if ($object_name !== '') {
			$details['alt'] = $object_name;
		}
		if ($description !== '') {
			$details['caption'] = $description;
		}
		$credit = $attribution !== '' ? $attribution : $artist;
		if ($credit !== '') {
			$details['source_label'] = $credit . ' / Wikimedia Commons';
		}
		return $details;
	}

	private static function wikimedia_file_title_from_url(string $url): string {
		$path = rawurldecode((string) wp_parse_url($url, PHP_URL_PATH));
		if ($path === '') {
			return '';
		}
		$parts = array_values(array_filter(explode('/', $path), static fn($part): bool => $part !== ''));
		if ($parts === []) {
			return '';
		}
		$filename = end($parts);
		if (in_array('thumb', $parts, true) && count($parts) >= 2) {
			$filename = $parts[count($parts) - 2];
		}
		$filename = preg_replace('/^\d+px-/i', '', (string) $filename) ?: (string) $filename;
		$filename = trim($filename);
		if ($filename === '') {
			return '';
		}
		return str_starts_with($filename, 'File:') ? $filename : ('File:' . $filename);
	}

	private static function humanize_wikimedia_title(string $title): string {
		$title = preg_replace('/^File:/i', '', $title) ?: $title;
		$title = preg_replace('/\.(jpe?g|png|webp|gif|svg)$/i', '', $title) ?: $title;
		$title = preg_replace('/[_]+/u', ' ', $title) ?: $title;
		$title = preg_replace('/\s+/u', ' ', $title) ?: $title;
		return trim($title);
	}

	private static function clean_wikimedia_text(string $value): string {
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($value)) ?: '');
		if ($value === '') {
			return '';
		}
		return sanitize_text_field((string) mb_substr($value, 0, 360));
	}

	private static function media_relevant(string $url, string $title, string $excerpt, array $categories, array $source_dossier = []): bool {
		$story_context = self::story_context_from_dossier($title, $excerpt, $categories, $source_dossier);
		$details = self::remote_media_details($url);
		return self::media_relevant_with_details($url, $title, $excerpt, $categories, $source_dossier, $details);
	}

	/**
	 * True, если URL — это фото primary-источника с агентской подписью
	 * (Reuters/dpa/Getty…). Сравниваем по нормализованному URL и по базовому
	 * файлу (CDN-варианты с resize-параметрами). 2026-06-12.
	 */
	public static function url_is_blacklisted_source_photo(string $url, array $source_dossier): bool {
		$credit = '';
		$primary_img = '';
		foreach (['primary', 'shell_primary'] as $key) {
			$entry = is_array($source_dossier[$key] ?? null) ? $source_dossier[$key] : [];
			$c = (string) ($entry['image_credit'] ?? '');
			$img = (string) ($entry['image'] ?? '');
			if ($img !== '' && $c !== '' && self::image_credit_is_blacklisted($c)) {
				$credit = $c;
				$primary_img = $img;
				break;
			}
		}
		if ($primary_img === '' || $url === '') {
			return false;
		}
		$norm = static function (string $u): string {
			$u = strtok($u, '?') ?: $u;
			return mb_strtolower(rtrim($u, '/'));
		};
		$a = $norm($url);
		$b = $norm($primary_img);
		if ($a === $b) {
			return true;
		}
		// CDN-варианты: одинаковое имя файла (последний сегмент пути).
		$fa = basename(parse_url($a, PHP_URL_PATH) ?: '');
		$fb = basename(parse_url($b, PHP_URL_PATH) ?: '');
		return $fa !== '' && mb_strlen($fa) >= 12 && $fa === $fb;
	}

	private static function media_relevant_with_details(string $url, string $title, string $excerpt, array $categories, array $source_dossier, array $details): bool {
		// Агентское фото primary-источника — жёсткий стоп ДО всех trusted-
		// shortcut'ов. Воркер берёт og:image источника напрямую (без проверки
		// подписи), поэтому единственный надёжный шлагбаум — здесь, в общей
		// воронке релевантности, через которую идут все PHP-пути выбора/
		// валидации featured. 2026-06-12: 10 публикаций за 12ч взяли
		// dpa/Reuters-фото несмотря на блокировку в source_dossier_image.
		if (self::url_is_blacklisted_source_photo($url, $source_dossier)) {
			return false;
		}
		$categories = self::normalize_media_categories($categories);
		$story_context = self::story_context_from_dossier($title, $excerpt, $categories, $source_dossier);
		$context = mb_strtolower(trim(wp_strip_all_tags(implode(' ', array_filter([
			$title,
			$excerpt,
			implode(' ', $categories),
			(string) ($story_context['text'] ?? ''),
			implode(' ', (array) ($story_context['phrases'] ?? [])),
		])))));
		$haystack = mb_strtolower(trim((string) ($details['alt'] ?? '') . ' ' . (string) ($details['caption'] ?? '') . ' ' . (string) wp_parse_url($url, PHP_URL_PATH)));
		$haystack = preg_replace('/[\/_-]+/u', ' ', $haystack) ?: $haystack;
		$haystack = preg_replace('/\s+/u', ' ', $haystack) ?: $haystack;
		$source_label = mb_strtolower(trim((string) ($details['source_label'] ?? '')));
		$host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$intent = self::media_intent($title, $excerpt, $categories);
		$stock_provider = self::fallback_stock_provider($url, $details);
		if (
			$stock_provider !== ''
			&& ! self::stock_fallback_allowed($title, $excerpt, $categories, $source_dossier, $story_context, $stock_provider)
		) {
			return false;
		}
		$context_has_crypto_mismatch = (
			preg_match('/\b(world|welt|politics|politik|iran|israel|lebanon|libanon|middle east|nahost|trump|reeves|war|krieg|conflict|konflikt)\b/u', $context) === 1
			&& preg_match('/\b(bitcoin|crypto|kryptow[aä]hr|coinbase|ripple|token|blockchain)\b/u', $haystack) === 1
		);
		if ($context_has_crypto_mismatch) {
			return false;
		}
		$is_sport_story = (
			$intent === 'sport_event'
			|| in_array('sport', array_map('sanitize_key', $categories), true)
			|| preg_match('/\b(bundesliga|uefa|champions league|europa league|conference league|football|soccer|basketball|hockey|nhl|nba|euroleague|trainer|goalkeeper|torwart|spieltag)\b/u', $context) === 1
		);
		if (self::is_trusted_source_dossier_media($url, $source_dossier)) {
			if ($haystack !== '' && preg_match('/\b(logo|icon|sprite|pdf|document|scan|map|flag|seal|coat of arms)\b/u', $haystack) === 1) {
				return false;
			}
			return true;
		}

		// CC-провайдеры (Wikimedia/Openverse): запрос строился из семантических
		// терминов story card, поэтому результат уже отобран по сущности. Если
		// имя/alt картинки пересекается хотя бы одним значимым токеном с этими
		// терминами — доверяем поиску и принимаем (junk отсекаем). Это чинит
		// случай «Brandenburger Tor» story ↔ «Brandenburg Gate» файл, который
		// строгий passes_entity_gate отбраковывал из-за DE/EN-вариантов. 2026-06-10.
		if (str_contains($host, 'wikimedia.org') || in_array((string) ($details['provider'] ?? ''), ['openverse', 'dvids'], true)) {
			$card_query = mb_strtolower(self::story_card_media_query($source_dossier));
			if ($card_query !== '' && $haystack !== '') {
				if (preg_match('/\b(logo|icon|sprite|pdf|document|scan|map|flag|seal|coat of arms|clipart|chart)\b/u', $haystack) === 1) {
					return false;
				}
				$card_tokens = array_filter(preg_split('/\s+/u', $card_query) ?: [], static fn($t) => mb_strlen($t) >= 4);
				foreach ($card_tokens as $tok) {
					if (str_contains($haystack, $tok)) {
						return true;
					}
				}
			}
		}

		$is_culture_story = in_array('kultur', array_map('sanitize_key', $categories), true)
			|| preg_match('/\b(kultur|museum|galerie|gallery|exhibition|ausstellung|installation|artist|k[üu]nstler|kunst|beeple|regular animals|nationalgalerie)\b/u', $context) === 1;
		if (
			$is_culture_story
			&& (str_contains($host, 'museum') || str_contains($host, 'museen'))
			&& $haystack !== ''
			&& preg_match('/\b(beeple|regular animals|nationalgalerie|gallery|galerie|museum|exhibition|ausstellung|installation|kunst|art)\b/u', $haystack) === 1
			&& preg_match('/\b(logo|icon|sprite|pdf|document|scan|map|flag|seal|coat of arms)\b/u', $haystack) !== 1
		) {
			return true;
		}

		if (
			$is_sport_story
			&& self::is_editorial_news_source($host, $source_label)
			&& preg_match('/\b(fc bayern|real madrid|bundesliga|champions league|uefa|team|spieler|goalkeeper|torwart|football|soccer|sport)\b/u', $context) === 1
			&& preg_match('/\b(camel|desert|parliament|government building|flag alone|gas station|royal palace)\b/u', $haystack) !== 1
		) {
			return true;
		}

		if (
			$is_sport_story
			&& (str_contains($host, 'pexels.com') || str_contains($host, 'wikimedia.org'))
			&& preg_match('/\b(camel|desert|parliament|government building|flag|gas station|royal palace|politician)\b/u', $haystack) !== 1
			&& preg_match('/\b(player|players|football|soccer|stadium|match|training|coach|team|sport|hockey|basketball|goalkeeper|champions league|uefa|live stream|fc bayern|bayern|real madrid|atalanta|galatasaray|liverpool)\b/u', $haystack) === 1
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

		if (
			in_array($intent, ['middle_east_diplomacy', 'ukraine_attack', 'world_royals', 'market_reaction'], true)
			&& self::is_editorial_news_source($host, $source_label)
			&& preg_match('/\b(camel|desert|gas station|concert|microphone|football|soccer|stadium|royal palace|riksdag|stockholm|sweden|swedish|schweiz|switzerland|brisbane|canberra|sheikh|portrait|headshot|business handshake|conference table)\b/u', $haystack) !== 1
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

		$needsStreaming = ! $is_sport_story && preg_match('/\b(streaming|musiklabel|music label|vergütung|royalties|spotify|music|tv-show|quizshow|fernsehen|mediathek|tatort|fernsehgeschichte|krimireihe|serie|fanpremiere|schauspieler|actors?)\b/u', $context);
		if ($needsStreaming) {
			if (preg_match('/logo-share-social-media|logo|icon|sprite/u', $haystack)) {
				return false;
			}
			if (preg_match('/\b(flag|flags|switzerland|swiss|sweden|schweden|eu|parliament|government|building|politician|camel|desert)\b/u', $haystack)) {
				return false;
			}
			return (bool) preg_match('/\b(stream|music|audio|concert|studio|microphone|vinyl|headphones|actor|actors|film|movie|cinema|clapperboard|cameraman|cinematographer|set lighting)\b/u', $haystack);
		}

		$needsHeritage = self::is_heritage_story($context);
		if ($needsHeritage) {
			if (preg_match('/\b(flag|flags|parliament|politician|government building|gas station|airport|passport|runway|desert|camel|military|police|riot)\b/u', $haystack)) {
				return false;
			}
			if (self::is_editorial_news_source($host, $source_label) && $haystack !== '') {
				return true;
			}
			return (bool) preg_match('/\b(heritage|kulturerbe|unesco|tradition|cultural|culture|museum|craft|festival|ceremony|dance|music|orchestra|theatre|traditions|folk|custom|historic|exhibition)\b/u', $haystack);
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

	private static function is_trusted_source_dossier_media(string $url, array $source_dossier): bool {
		$url = esc_url_raw($url);
		if ($url === '') {
			return false;
		}
		$primary = is_array($source_dossier['primary'] ?? null) ? (array) $source_dossier['primary'] : [];
		$primary_url = esc_url_raw((string) ($primary['url'] ?? ''));
		$primary_host = mb_strtolower((string) wp_parse_url($primary_url, PHP_URL_HOST));
		$entries = [];
		foreach (['primary', 'shell_primary'] as $key) {
			if (is_array($source_dossier[$key] ?? null)) {
				$entries[] = (array) $source_dossier[$key];
			}
		}
		foreach ((array) ($source_dossier['supporting'] ?? []) as $entry) {
			if (is_array($entry)) {
				$entries[] = $entry;
			}
		}
		$image_host = mb_strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		foreach ($entries as $entry) {
			$image = esc_url_raw((string) ($entry['image'] ?? ''));
			if ($image === '' || $image !== $url) {
				continue;
			}
			$entry_url = esc_url_raw((string) ($entry['url'] ?? ''));
			$entry_host = mb_strtolower((string) wp_parse_url($entry_url, PHP_URL_HOST));
			if (! empty($entry['is_official'])) {
				return true;
			}
			if ($entry_host !== '' && self::same_source_host($image_host, $entry_host)) {
				return true;
			}
			if ($primary_host !== '' && self::same_source_host($image_host, $primary_host)) {
				return true;
			}
		}
		return false;
	}

	private static function is_heritage_story(string $text): bool {
		$text = mb_strtolower(trim(wp_strip_all_tags($text)));
		if ($text === '') {
			return false;
		}
		return preg_match('/(immateriell(?:es)?\s+kulturerbe|kulturerbe|unesco|heritage|bundesweite(?:n|s)?\s+verzeichnis|tradition(?:en)?)/u', $text) === 1;
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
		$categories = self::normalize_media_categories($categories);
		$text = mb_strtolower(trim(wp_strip_all_tags($title . ' ' . $excerpt . ' ' . implode(' ', $categories))));

		$rules = [
			'market_reaction' => '/\b(stock market|stock futures|futures|dow jones|nasdaq|s&p|wall street|aktienmarkt|aktien|börse|boerse|markets|market reaction|equity index|oil prices)\b/u',
			'streaming_media' => '/\b(streaming|quizshow|quiz show|tv-show|fernsehen|ard|zdf|rtl|prosieben|mediathek|spotify|royalties|music label|musiklabel|tatort|fernsehgeschichte|krimireihe|serie|fanpremiere|schauspieler|batic|leitmayr)\b/u',
			'museum_exhibition' => '/\b(museum|ausstellung|exhibition|gallery|galerie|installation|kunst|art|nationalgalerie|hamburger bahnhof)\b/u',
			'fuel_prices' => '/\b(kraftstoff|kraftstoffpreise|spritpreis|spritpreise|benzinpreis|benzinpreise|diesel|tankstellen|refuel|fuel prices|energiepreis-?entlastungen)\b/u',
			'crime_police' => '/\b(kriminalstatistik|kriminalität|crime|polizei|police|sicherheit|gewaltkriminalität)\b/u',
			'border_airport' => '/\b(ees|entry\/exit|border|passport|einreise|ausreise|grenze|fingerabdr|passport control)\b/u',
			'rail_transit' => '/\b(bahn|s-bahn|db|zug|train|rail|tram|mvg|öpnv|nahverkehr|verkehr)\b/u',
			'bundestag_memorial' => '/\b(volkskammer|demokratiegeschichte|gedenken|denkmal|mahnung und erinnerung|opfer der kommunistischen diktatur|kommunistischen diktatur)\b/u',
			'german_government' => '/\b(bundesregierung|regierung|kabinett|bundesrat|minister|bundespräsident|steinmeier|merz)\b/u',
			'ukraine_attack' => '/\b(ukraine-krieg|krieg in der ukraine|krieg gegen die ukraine|angriff auf kiew|angriff auf kyiv|kiew|kyiv|luftangriff|raketenangriff|krim|crimea|krym|sevastopol|sewastopol|drohnenlager|radarstation|milit[aä]rflugplatz|strategische werke|erd[oö]lanlagen|дрон|обстріл|обстрел)\b/u',
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
			'market_reaction' => '/\b(camel|desert|gas station|royal|palace|concert|microphone|football|soccer|stadium)\b/u',
			'streaming_media' => '/\b(flag|flags|switzerland|swiss|sweden|schweden|schweiz|eu parliament|government building|camel|desert|gas station|runway)\b/u',
			'museum_exhibition' => '/\b(gas station|runway|passport control|stadium|football|soccer|government building|parliament|camel|desert)\b/u',
			'fuel_prices' => '/\b(parliament|bundestag|government building|camel|desert|royal|palace|stadium)\b/u',
			'crime_police' => '/\b(airport|airfield|runway|passport|border|terminal|interview|office meeting|camel|desert)\b/u',
			'border_airport' => '/\b(camel|desert|parliament|royal|stadium|concert)\b/u',
			'rail_transit' => '/\b(parliament|bundestag|government building|desert|camel|beach)\b/u',
			'bundestag_memorial' => '/\b(sweden|swedish|schweden|switzerland|swiss|schweiz|riksdag|stockholm|canberra|brisbane)\b/u',
			'german_government' => '/\b(sweden|swedish|schweden|switzerland|swiss|schweiz|riksdag|stockholm|canberra|brisbane)\b/u',
			'ukraine_attack' => '/\b(job interview|office meeting|corporate office|business handshake|camel|desert|beach|tourism|australia|brisbane|sheikh|royal|palace|portrait|headshot|conference table|handshake|leader portrait|ministers meeting)\b/u',
			'middle_east_diplomacy' => '/\b(switzerland|swiss|sweden|schweden|schweiz|riksdag|stockholm|australia|brisbane|gas station|job interview)\b/u',
			'paralympic_official_visit' => '/\b(football|soccer|bundesliga|stadium floodlights|goalkeeper|gas station|parliament|government building|flag alone)\b/u',
			'education_fair' => '/\b(stadium|football|soccer|parliament|government building|gas station|desert|camel|runway)\b/u',
			'world_royals' => '/\b(gas station|runway|passport control|stadium|football|microphone|concert)\b/u',
			'sport_event' => '/\b(camel|desert|parliament|government building|flag alone|gas station|royal palace)\b/u',
			'community_help' => '/\b(parliament|government building|stadium|runway|gas station|desert|camel)\b/u',
		];

		$allow = [
			'market_reaction' => '/\b(market|markets|stock|stocks|equity|index|indices|nasdaq|dow|s&p|trading|trader|traders|wall street|börse|boerse|chart|oil prices)\b/u',
			'streaming_media' => '/\b(stream|music|audio|concert|studio|microphone|headphones|tv studio|television)\b/u',
			'museum_exhibition' => '/\b(museum|exhibition|gallery|installation|art|artist|artwork|nationalgalerie|bahnhof|contemporary)\b/u',
			'fuel_prices' => '/\b(refuel|refueling|fuel|gas station|nozzle|petrol|diesel|pump|tankstelle)\b/u',
			'crime_police' => '/\b(police|patrol|security|street|city|siren|vehicle|officer)\b/u',
			'border_airport' => '/\b(passport|border|airport|control|traveler|terminal)\b/u',
			'rail_transit' => '/\b(train|rail|station|platform|tram|commuter|bus)\b/u',
			'bundestag_memorial' => '/\b(bundestag|parliament|memorial|museum|exhibition|document|historical|berlin)\b/u',
			'german_government' => '/\b(government|parliament|building|bundestag|bundesregierung|minister|berlin|politician|portrait|merz|friedrich merz|pistorius|steinmeier|cdu|spd)\b/u',
			'ukraine_attack' => '/\b(damage|destroyed|firefighters|rescue|debris|ukraine|kyiv|kiew|emergency|explosion|smoke|drone|missile|airfield|radar|oil depot|refinery|crimea|krim|krym|sevastopol|black sea|military)\b/u',
			'middle_east_diplomacy' => '/\b(diplomatic|delegation|meeting|conference|minister|leaders|middle east|israel|iran|lebanon|netanjahu|netanyahu|araghtschi|araghchi|witkoff|foreign minister|aussenminister|außenminister|prime minister|premier)\b/u',
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
				'allow' => '/\b(bundesregierung|bundeskanzleramt|berlin|regierung|kanzleramt|minister|merz|friedrich merz|steinmeier|pistorius|politician|portrait|cdu|spd)\b/u',
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
			'deutschlandfunk.de',
			'www.deutschlandfunk.de',
			'bilder.deutschlandfunk.de',
			'br.de',
			'img.br.de',
			'kicker.de',
			'www.kicker.de',
			'images.kicker.de',
			'cdn.kicker.de',
			'tagesschau.de',
			'images.tagesschau.de',
			'sportschau.de',
			'www.sportschau.de',
			'images.sportschau.de',
			'dw.com',
			'cnbc.com',
			'image.cnbcfm.com',
			'editorial.fxsstatic.com',
			'zdf.de',
			'www.zdf.de',
			'ardmediathek.de',
			'bundesregierung.de',
			'bundestag.de',
			'merkur.de',
			'fr.de',
			'www.fr.de',
			'express.de',
			'static.express.de',
			'gn-online.de',
			'tagesspiegel.de',
			't-online.de',
			'images.t-online.de',
			'unian.net',
			'images.unian.net',
		];
		if ($host !== '' && in_array($host, $known, true)) {
			return true;
		}
		return preg_match('/\b(deutschlandfunk|br|kicker|tagesschau|dw|zdf|bundesregierung|bundestag|cnbc|merkur|fr|express|tagesspiegel|t-online|unian)\b/u', $source_label) === 1;
	}

	private static function prefer_no_stock_fallback(string $title, string $excerpt, array $categories): bool {
		$categories = self::normalize_media_categories($categories);
		$context = mb_strtolower(trim(wp_strip_all_tags($title . ' ' . $excerpt . ' ' . implode(' ', $categories))));
		return preg_match('/\b(lebanon|libanon|israel|hezbollah|hamas|iran|nahost|middle east|gaza|krieg|war|escalation|eskalation|joint statement|gemeinsame erkl[aä]rung|diplomatic|diplom|sanctions|waffenruhe|ukraine|kyiv|kiew|polizei|police|innenminister|bayerische polizei|bayern|bavaria|bundesregierung|bundestag|regierung|ministerium|minister|beh[oö]rden|deutschland|germany|protest|proteste|protesten|kundgebung|demonstration|demo|afd|bischof|bishop|katholik|katholiken|catholic|church|kirche|religion|religi[oö]s)\b/u', $context) === 1;
	}

	private static function stock_fallback_allowed(string $title, string $excerpt, array $categories, array $source_dossier, array $story_context, string $provider = 'any'): bool {
		$categories = self::normalize_media_categories($categories);
		$has_publishable_source_media = self::has_publishable_source_media($source_dossier);
		$supporting_count = count((array) ($source_dossier['supporting'] ?? []));
		$used_search = ! empty($source_dossier['used_search']);
		$provider = sanitize_key($provider);
		$story_context = self::normalize_story_context($story_context);
		$phrases = array_map(static fn($value): string => mb_strtolower(trim((string) $value)), (array) ($story_context['phrases'] ?? []));
		$text = mb_strtolower(trim(wp_strip_all_tags(implode(' ', array_filter([
			$title,
			$excerpt,
			implode(' ', $categories),
			(string) ($story_context['text'] ?? ''),
			implode(' ', $phrases),
		])))));

		if ($provider === 'pexels' && self::pexels_fallback_forbidden($text, $categories)) {
			return false;
		}
		if (self::specific_sport_stock_forbidden($text, $categories)) {
			return false;
		}
		if ($provider === 'pexels' && self::entity_media_query($text) !== '') {
			return false;
		}
		if ($provider === 'wikimedia' && self::entity_media_query($text) !== '') {
			return ! $has_publishable_source_media;
		}

		if (self::prefer_no_stock_fallback($title, $excerpt, $categories)) {
			if ($supporting_count < 1 || ! $used_search) {
				return false;
			}
			return ! $has_publishable_source_media;
		}
		if (preg_match('/\b(polizei|police|bundesregierung|bundestag|ministerium|minister|regierung|beh[oö]rde|bayern|bavaria|münchen|munich|berlin|hamburg|ukraine|kyiv|kiew|libanon|lebanon|israel|iran|gaza|merz|steinmeier|nato|eu|europa)\b/u', $text) === 1) {
			if ($supporting_count < 1 || ! $used_search) {
				return false;
			}
			return ! $has_publishable_source_media;
		}
		$has_specific_phrases = false;
		foreach ($phrases as $phrase) {
			if ($phrase === '') {
				continue;
			}
			if (preg_match('/\d/u', $phrase) === 1 || preg_match('/\b[a-zа-яёіїєüäöß-]{4,}\s+[a-zа-яёіїєüäöß-]{4,}\b/u', $phrase) === 1) {
				$has_specific_phrases = true;
				break;
			}
		}
		if ($has_specific_phrases) {
			if ($supporting_count < 1 || ! $used_search) {
				return false;
			}
			return ! $has_publishable_source_media;
		}
		return true;
	}

	private static function pexels_fallback_forbidden(string $text, array $categories): bool {
		$categories = self::normalize_media_categories($categories);
		$primary = (string) ($categories[0] ?? '');
		$is_public_news = array_intersect($categories, ['politik', 'deutschland', 'welt', 'ukraine', 'europa', 'bayern', 'muenchen']) !== [];

		if (
			$is_public_news
			&& preg_match('/\b(merz|cdu|csu|spd|gruene|grüne|afd|fdp|linke|rente|renten|pension|basisabsicherung|bundesregierung|bundestag|kanzler|minister|ministerium|regierung|parlament|wahl|migration|migrationspolitik|migrant|migranten|asyl|flucht|geflüchtete|gefluechtete|einbürgerung|einbuergerung|bamf|grenze|grenzkontrolle|grenzkontrollen|kontrolle|kontrollen|luxemburg|gericht|urteil|rechtswidrig|ukraine|israel|iran|gaza|libanon|lebanon|hisbollah|hamas|krieg|war|waffenruhe)\b/u', $text) === 1
		) {
			return true;
		}

		if (
			$is_public_news
			&& preg_match('/\b(bayern|bavaria|freistaat|ministerrat|innenminister|herrmann|polizei|feuerwehr|rettungsdienst|luftrettung|rettungshubschrauber|katastrophenschutz|beh[oö]rde|amt|verwaltung|gericht|staatsanwaltschaft)\b/u', $text) === 1
		) {
			return true;
		}

		if (self::specific_sport_stock_forbidden($text, $categories)) {
			return true;
		}

		if (
			in_array($primary, ['wirtschaft', 'gesundheit'], true)
			&& preg_match('/\b(intellia|therapeutics|biotech|biotechnologie|pharma|phase-?3|phase iii|clinical trial|klinische studie|gentherapie|gene editing|in-vivo|angio[oö]dem|zulassung|studienergebnis)\b/u', $text) === 1
		) {
			return true;
		}

		return false;
	}

	private static function specific_sport_stock_forbidden(string $text, array $categories): bool {
		$categories = self::normalize_media_categories($categories);
		$primary = (string) ($categories[0] ?? '');
		if ($primary !== 'sport') {
			return false;
		}
		return preg_match('/\b(adidas|dfl|dfb|fifa|uefa|bundesliga|champions league|europa league|conference league|trainer|coach|gefeuert|entlassen|vertrag|acht-jahres-vertrag|millionen|derby|istanbul|galatasaray|fenerbah[cç]e|fc bayern|borussia|dortmund|liverpool|real madrid|nationalmannschaft)\b/u', $text) === 1;
	}

	private static function media_risk_flags(string $title, string $excerpt, array $categories, array $details, array $story_context, array $source_dossier = []): array {
		$categories = self::normalize_media_categories($categories);
		$provider = sanitize_key((string) ($details['provider'] ?? ''));
		$text = mb_strtolower(trim(wp_strip_all_tags(implode(' ', array_filter([
			$title,
			$excerpt,
			implode(' ', $categories),
			(string) ($story_context['text'] ?? ''),
			implode(' ', (array) ($story_context['phrases'] ?? [])),
		])))));
		$flags = [];
		if ($provider === '') {
			$origin = esc_url_raw((string) ($details['origin_url'] ?? ''));
			$provider = self::fallback_stock_provider($origin, $details);
		}
		if ($provider === 'pexels' && self::pexels_fallback_forbidden($text, $categories)) {
			$flags[] = 'pexels_blocked_for_high_context_story';
		}
		if (in_array($provider, ['pexels', 'wikimedia'], true)) {
			$flags[] = 'stock_fallback';
			if (! self::stock_fallback_allowed($title, $excerpt, $categories, $source_dossier, $story_context, $provider)) {
				$flags[] = 'stock_fallback_not_allowed_for_context';
			}
		}
		if ($provider === 'source') {
			$flags[] = 'source_first';
		}
		return array_values(array_unique($flags));
	}

	private static function has_publishable_source_media(array $source_dossier): bool {
		$candidates = [];
		foreach (['primary', 'shell_primary'] as $key) {
			$entry = is_array($source_dossier[$key] ?? null) ? $source_dossier[$key] : [];
			$image = esc_url_raw((string) ($entry['image'] ?? ''));
			if ($image !== '') {
				$candidates[] = $image;
			}
		}
		foreach ((array) ($source_dossier['supporting'] ?? []) as $entry) {
			if (! is_array($entry)) {
				continue;
			}
			$image = esc_url_raw((string) ($entry['image'] ?? ''));
			if ($image !== '') {
				$candidates[] = $image;
			}
		}
		foreach (array_values(array_unique($candidates)) as $candidate) {
			$validation = self::validate_featured_media($candidate, 0, '');
			if (! empty($validation['ok'])) {
				return true;
			}
		}
		return false;
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

	private static function caption_context_title(string $title): string {
		$title = trim(wp_strip_all_tags($title));
		if ($title === '') {
			return '';
		}
		$title = preg_replace('/\s+/u', ' ', $title) ?: $title;
		$title = preg_replace('/\s*[|–-]\s*EuroPulse.*$/iu', '', $title) ?: $title;
		if (mb_strlen($title) > 96) {
			$title = mb_substr($title, 0, 96);
			$space = mb_strrpos($title, ' ');
			if ($space !== false) {
				$title = mb_substr($title, 0, $space);
			}
		}
		return rtrim($title, " \t\n\r\0\x0B.,;:!?");
	}

	private static function context_caption_prefix(string $lang, string $provider): string {
		$is_stock = in_array($provider, ['pexels', 'wikimedia'], true);
		return match ($lang) {
			'uk' => $is_stock ? 'Тематичне фото: ' : 'Зображення до теми: ',
			'en' => $is_stock ? 'Thematic image: ' : 'Image for the story: ',
			default => $is_stock ? 'Themenbild: ' : 'Bild zum Thema: ',
		};
	}

	private static function alt_context_title(string $title, string $provider): string {
		$title = self::caption_context_title($title);
		if ($title === '') {
			return '';
		}
		return in_array($provider, ['pexels', 'wikimedia'], true)
			? 'Themenbild zu: ' . $title
			: 'Bild zum Thema: ' . $title;
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
