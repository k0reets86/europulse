<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Publisher {
	public static function register(): void {
		add_filter('the_content', [self::class, 'normalize_rendered_content'], 8);
	}

		public static function publish_scheduled(bool $force = false): void {
			$started_at = microtime(true);
			if (! EPV2_Time_Planner::should_publish($force)) {
				return;
			}
		if (EPV2_Lock_Manager::is_active('publish')) {
			return;
		}
		if (EPV2_Runs::has_recent_started('publish', 180)) {
			return;
		}
		EPV2_Resilience_Manager::cleanup();
		EPV2_Queue::prune_rejected(1440);
		$lock = EPV2_Lock_Manager::acquire('publish', (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
		if ($lock === null) {
			return;
		}
		$run = EPV2_Runs::start('publish');
		$count = 0;
		$errors = 0;
		$run_payload = [
			'result' => 'started',
		];
		try {
			EPV2_Queue::normalize_ready_publish_schedule(! $force);
			$attempts = 0;
			while ($attempts < 5) {
			$item = EPV2_Queue::next_item_for_publish($force);
			if (! $item) {
				if (($run_payload['result'] ?? 'started') === 'started') {
					$run_payload['result'] = $count > 0 ? 'published_items' : 'no_due_items';
				}
				break;
			}
			$run_payload['last_item_id'] = (int) $item->id;
			$run_payload['attempts'] = $attempts + 1;
			$attempts++;
			try {
				EPV2_Lock_Manager::heartbeat('publish', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
				EPV2_Queue::mark_state((int) $item->id, 'publishing');
				EPV2_Queue::set_live_status((int) $item->id, 'Собираю языковые версии, фото, теги и метаданные перед публикацией.', 'publishing_bundle');
				self::publish_item($item);
				$count++;
				$run_payload['published_item_id'] = (int) $item->id;
				EPV2_Queue::normalize_ready_publish_schedule(false);
				break;
			} catch (Throwable $e) {
				$errors++;
				$run_payload['last_error'] = $e->getMessage();
				$run_payload['last_error_class'] = get_class($e);
				if (self::is_stale_publish_blocker($e)) {
					self::cleanup_partial_drafts_for_queue((int) $item->id);
					EPV2_Queue::mark_state((int) $item->id, 'rejected', [
						'error_message' => $e->getMessage(),
					]);
					$run_payload['result'] = 'stale_blocker_skipped';
					continue;
				}
				if (self::is_media_publish_blocker($e)) {
					self::invalidate_payload_media((int) $item->id);
					self::cleanup_partial_drafts_for_queue((int) $item->id);
					EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'publish_media', $e->getMessage());
					EPV2_Jobs::enqueue_process();
					$run_payload['result'] = 'media_blocker_sent_to_repair';
					continue;
				}
					if (self::is_hard_publish_blocker($e)) {
						self::cleanup_partial_drafts_for_queue((int) $item->id);
						$fresh_item = EPV2_Queue::get_item((int) $item->id) ?: $item;
						$payload = [];
						try {
							$payload = EPV2_Review::ensure_payload($fresh_item);
						} catch (Throwable $payloadError) {
							$payload = [];
						}
						$auto_rework = $payload !== [] && EPV2_AI_Processor::item_is_auto_rework_candidate($fresh_item, $payload);
						$auto_finish = ! $auto_rework && $payload !== [] && EPV2_AI_Processor::item_is_auto_finish_candidate($fresh_item, $payload);
						if ($auto_rework || $auto_finish) {
							self::requeue_review_candidate_for_process($fresh_item, $payload, $auto_rework);
							EPV2_Jobs::enqueue_process();
							$run_payload['result'] = $auto_rework ? 'hard_blocker_sent_to_rebuild' : 'hard_blocker_sent_to_finish';
							continue;
						}
						EPV2_Queue::mark_state((int) $item->id, 'retry_process', [
							'ai_payload' => $payload !== [] ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : '',
							'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
							'error_message' => 'Материал возвращён в автоматическую доводку после publish-blocker: ' . $e->getMessage(),
						]);
						EPV2_Jobs::enqueue_process();
						$run_payload['result'] = 'hard_blocker_sent_to_retry_process';
						continue;
					} else {
					self::cleanup_partial_drafts_for_queue((int) $item->id);
					EPV2_Resilience_Manager::schedule_retry($item, 'retry_publish', 'publish', $e->getMessage());
					$run_payload['result'] = 'transient_publish_error';
				}
				break;
			}
		}

			EPV2_Stats::bump('published', $count);
			$run_payload['duration_ms'] = (int) round((microtime(true) - $started_at) * 1000);
			EPV2_Runs::finish($run, $errors ? 'finished_with_errors' : 'finished', $count, $errors, $run_payload);
			if (EPV2_Queue::has_processable_items() && ! EPV2_Lock_Manager::is_active('process')) {
				EPV2_Jobs::enqueue_process();
			}
			} finally {
			EPV2_Lock_Manager::release('publish', $lock);
		}
	}

	private static function requeue_review_candidate_for_process(object $item, array $payload, bool $auto_rework): void {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		unset($notes['_system']['retry_after']);
		$notes['_system']['live_status'] = $auto_rework
			? 'Возвращаю материал в автоматическую углублённую доработку и добираю фактуру, источники и фото.'
			: 'Возвращаю материал в автоматическую финальную доводку SEO, media и publish-grade.';
		$notes['_system']['live_status_code'] = $auto_rework ? 'enrichment_rebuild' : 'publish_finish';
		EPV2_Queue::mark_state((int) $item->id, 'retry_process', [
			'ai_payload' => $payload !== [] ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : '',
			'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($item->category_final ?? ''),
			'error_message' => $auto_rework
				? 'Материал возвращён в автоматическую углублённую доработку после провала финального publish-grade.'
				: 'Материал возвращён в автоматическую финальную доводку после провала publish-grade.',
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
	}

	private static function promote_publishable_review_items(int $limit = 10): void {
		global $wpdb;
		$limit = max(1, min(10, $limit));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}epv2_queue
				WHERE state = 'ready_review'
				ORDER BY story_score DESC, created_at ASC
				LIMIT %d",
				$limit
			)
		);
		foreach ((array) $rows as $item) {
			try {
				$payload = EPV2_Review::ensure_payload($item);
				$payload = EPV2_AI_Processor::try_lift_payload_to_publish_grade($item, $payload);
				if (EPV2_AI_Processor::payload_is_publish_ready($payload)) {
					EPV2_Review::save_payload((int) $item->id, $payload);
					EPV2_Queue::mark_state((int) $item->id, 'ready_publish', [
						'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
						'error_message' => '',
					]);
				}
			} catch (Throwable $e) {
				EPV2_Logger::warning('publish', 'Review promotion failed', [
					'item_id' => (int) ($item->id ?? 0),
					'error' => $e->getMessage(),
				]);
			}
		}
	}

	public static function publish_item(object $item, ?string $status_override = null): int {
		$payload = EPV2_Review::ensure_payload($item);
		if (! empty($payload['_meta']['translations_deferred'])) {
			$payload = EPV2_AI_Processor::repair_payload_languages($payload);
			EPV2_Review::save_payload((int) $item->id, $payload);
		}
		self::assert_multilingual_payload_ready($payload, $item);
		$categories = self::normalize_categories((string) (implode(',', $payload['categories'] ?? []) ?: $item->category_proposed ?: $item->category_final ?: 'deutschland'));
		$existing_posts = self::extract_existing_posts($item);
		$post_ids = [];
		$post_term_ids = [];
		$primary_post_id = 0;
		$created_statuses = [];
		$final_status = $status_override ?: (string) EPV2_Settings::get('default_post_status', 'draft');
		$working_status = in_array($final_status, ['publish', 'pending'], true) ? 'draft' : $final_status;

		$sourceDossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$sourceUrl = EPV2_Source_Enricher::best_source_url($sourceDossier, (string) $item->original_url);
		$shared_media_url = self::resolve_shared_publish_media_url($item, $payload, $categories, $sourceDossier);
		$shared_media_url = self::preflight_shared_publish_media_url($shared_media_url, $payload, $item, $categories, $sourceDossier);
		foreach (EPV2_Settings::get('publish_languages', ['de', 'uk', 'en']) as $lang) {
			$lang_payload = $payload['languages'][$lang] ?? EPV2_Review::build_language_package($item, $lang);
			$term_ids = self::term_ids_for_language($categories, $lang);
			$media_url = $shared_media_url;
			$inline_media_urls = EPV2_Media::normalize_media_list($lang_payload['inline_media_urls'] ?? $payload['inline_media_urls'] ?? []);
			$seo_title = (string) ($lang_payload['seo_title'] ?? $payload['seo']['seo_title'] ?? '');
			$meta_description = (string) ($lang_payload['meta_description'] ?? $payload['seo']['meta_description'] ?? '');
			$slug = (string) ($lang_payload['slug'] ?? $payload['seo']['slug'] ?? '');
			$focus_keywords = $lang_payload['focus_keywords'] ?? $payload['seo']['focus_keywords'] ?? [];
			$post_data = [
				'post_type' => 'post',
				'post_status' => $working_status,
				'post_title' => (string) ($lang_payload['title'] ?? $item->original_title),
				'post_content' => self::build_post_content((string) ($lang_payload['content'] ?? $item->original_content), (string) ($lang_payload['excerpt'] ?? ''), $media_url, $inline_media_urls, $sourceUrl, $lang, $categories, (int) ($existing_posts[$lang] ?? 0)),
				'post_excerpt' => (string) ($lang_payload['excerpt'] ?? wp_trim_words(wp_strip_all_tags((string) $item->original_excerpt), 24, '')),
				'post_category' => $term_ids,
			];
			if ($slug !== '') {
				$post_data['post_name'] = sanitize_title($slug);
			}
			if (! empty($existing_posts[$lang])) {
				$post_data['ID'] = (int) $existing_posts[$lang];
			}
			$post_id = wp_insert_post($post_data, true);

			if (is_wp_error($post_id)) {
				throw new RuntimeException($post_id->get_error_message());
			}

			$post_id = (int) $post_id;
			if ($primary_post_id === 0 || $lang === 'de') {
				$primary_post_id = $post_id;
			}
			$post_ids[$lang] = $post_id;
			$post_term_ids[$lang] = $term_ids;

			update_post_meta($post_id, '_epv2_source_url', $sourceUrl);
			update_post_meta($post_id, '_epv2_queue_id', (int) $item->id);
			update_post_meta($post_id, '_epv2_publish_media_url', $media_url);
			update_post_meta($post_id, '_epv2_title_hash', hash('sha256', mb_strtolower(trim((string) ($lang_payload['title'] ?? $item->original_title)))));
			update_post_meta($post_id, '_epv2_content_hash', hash('sha256', mb_strtolower(trim(wp_strip_all_tags((string) ($lang_payload['content'] ?? $item->original_content))))));
			update_post_meta($post_id, '_epv2_semantic_hash', hash('sha256', self::semantic_keywords((string) ($lang_payload['title'] ?? '') . ' ' . (string) ($lang_payload['excerpt'] ?? '') . ' ' . wp_strip_all_tags((string) ($lang_payload['content'] ?? '')))));
			update_post_meta($post_id, '_epv2_cluster_id', (string) ((int) ($item->cluster_id ?? 0)));
			update_post_meta($post_id, '_epv2_topic_label', sanitize_text_field((string) ($item->topic_label ?? '')));
			update_post_meta($post_id, '_epv2_primary_category', (string) ($categories[0] ?? 'deutschland'));
			self::apply_editorial_meta($post_id, $payload);
			self::apply_seo_meta($post_id, $seo_title !== '' ? $seo_title : (string) ($lang_payload['title'] ?? $item->original_title), $meta_description !== '' ? $meta_description : (string) ($lang_payload['excerpt'] ?? ''), $categories, $focus_keywords);
			self::apply_tags($post_id, $payload, $lang, $focus_keywords, $categories);
			if ($media_url !== '') {
				$media_check = EPV2_Media::validate_featured_media($media_url, $post_id, (string) ($lang_payload['title'] ?? $item->original_title));
				$media = is_array($media_check['media'] ?? null) ? $media_check['media'] : [];
				if (($media['type'] ?? 'image') === 'image' && ! empty($media['attachment_id']) && ! empty($media['usable'])) {
					set_post_thumbnail($post_id, (int) $media['attachment_id']);
				} elseif (($media['type'] ?? 'image') === 'image') {
					$fallback_media_url = EPV2_Media::resolve_featured_media(
						(string) ($lang_payload['title'] ?? $item->original_title),
						(string) ($lang_payload['excerpt'] ?? ''),
						$categories,
						'',
						$sourceDossier,
						(int) $item->id
					);
					if ($fallback_media_url !== '') {
						$fallback_check = EPV2_Media::validate_featured_media($fallback_media_url, $post_id, (string) ($lang_payload['title'] ?? $item->original_title));
						$fallback_media = is_array($fallback_check['media'] ?? null) ? $fallback_check['media'] : [];
						if (($fallback_media['type'] ?? 'image') === 'image' && ! empty($fallback_media['attachment_id']) && ! empty($fallback_media['usable'])) {
							set_post_thumbnail($post_id, (int) $fallback_media['attachment_id']);
						}
					}
				}
			}
			$thumb_id = (int) get_post_thumbnail_id($post_id);
			if ($thumb_id > 0) {
				$remote = (string) get_post_meta($thumb_id, '_epv2_remote_source_url', true);
				if ($remote !== '') {
					update_post_meta($post_id, '_epv2_featured_media_origin_fingerprint', EPV2_Media::media_fingerprint($remote));
				}
				update_post_meta($post_id, '_epv2_featured_media_fingerprint', EPV2_Media::attachment_fingerprint($thumb_id, $remote));
			}
		}

		if (function_exists('pll_set_post_language')) {
			foreach ($post_ids as $lang => $post_id) {
				pll_set_post_language($post_id, $lang);
			}
		}

		if (function_exists('pll_save_post_translations') && count($post_ids) > 1) {
			pll_save_post_translations($post_ids);
		}

		foreach ($post_ids as $lang => $post_id) {
			$term_ids = array_values(array_unique(array_map('intval', $post_term_ids[$lang] ?? [])));
			if ($term_ids !== []) {
				wp_set_post_terms((int) $post_id, $term_ids, 'category', false);
			}
		}

		self::ensure_published_posts_have_thumbnails($post_ids);
		self::synchronize_bundle_thumbnail($post_ids);

		EPV2_Post_Audit::repair_after_publish($post_ids, $payload, $item);
		if ($working_status !== $final_status) {
			foreach ($post_ids as $post_id) {
				wp_update_post([
					'ID' => (int) $post_id,
					'post_status' => $final_status,
				]);
			}
		}
		foreach ($post_ids as $post_id) {
			$created_statuses[] = (string) get_post_status((int) $post_id);
		}

		EPV2_Queue::mark_state((int) $item->id, self::state_for_post_statuses($created_statuses), [
			'post_id' => $primary_post_id,
			'publish_payload' => wp_json_encode(['post_ids' => $post_ids], JSON_UNESCAPED_UNICODE),
			'error_message' => '',
		]);
		self::cleanup_stale_queue_posts((int) $item->id, $post_ids);

		return $primary_post_id;
	}

	public static function normalize_rendered_content(string $content): string {
		if (is_admin() || ! is_singular('post')) {
			return $content;
		}

		$post_id = get_the_ID();
		if (! $post_id || ! get_post_meta($post_id, '_epv2_queue_id', true)) {
			return $content;
		}

		$excerpt = trim(wp_strip_all_tags((string) get_the_excerpt($post_id)));
		if ($excerpt !== '' && preg_match('/^\s*<p>(.*?)<\/p>\s*/isu', $content, $match)) {
			$first_paragraph = trim(wp_strip_all_tags((string) $match[1]));
			if ($first_paragraph !== '' && mb_strtolower($first_paragraph) === mb_strtolower($excerpt)) {
				$content = preg_replace('/^\s*<p>.*?<\/p>\s*/isu', '', $content, 1) ?? $content;
			}
		}

		$thumbnail_id = get_post_thumbnail_id($post_id);
		$thumbnail_url = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'full') : '';
		if ($thumbnail_url) {
			$quoted = preg_quote((string) $thumbnail_url, '/');
			$content = preg_replace('/^\s*(?:<!-- wp:html -->)?<figure[^>]*>.*?<img[^>]+src="' . $quoted . '"[^>]*>.*?<\/figure>(?:<!-- \/wp:html -->)?\s*/isu', '', $content, 1) ?? $content;
			$content = preg_replace('/^\s*<!-- wp:image\b.*?-->.*?<img[^>]+src="' . $quoted . '"[^>]*>.*?<!-- \/wp:image -->\s*/isu', '', $content, 1) ?? $content;
		}

		return $content;
	}

	public static function synchronize_published_bundle_taxonomy(int $queue_id, array $categories = []): void {
		$queue_id = (int) $queue_id;
		if ($queue_id <= 0) {
			return;
		}

		$item = EPV2_Queue::get_item($queue_id);
		if (! $item) {
			return;
		}

		$normalized = array_values(array_filter(array_map('sanitize_text_field', $categories)));
		if ($normalized === []) {
			$normalized = self::normalize_categories((string) ($item->category_proposed ?: $item->category_final ?: 'deutschland'));
		}
		if ($normalized === []) {
			$normalized = ['deutschland'];
		}

		$posts = self::extract_existing_posts($item);
		if ($posts === []) {
			return;
		}

		foreach ($posts as $lang => $post_id) {
			$post_id = (int) $post_id;
			if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
				continue;
			}
			$term_ids = self::term_ids_for_language($normalized, (string) $lang);
			if ($term_ids !== []) {
				wp_set_post_terms($post_id, $term_ids, 'category', false);
			}
			update_post_meta($post_id, '_epv2_primary_category', (string) ($normalized[0] ?? 'deutschland'));
			clean_post_cache($post_id);
		}

		wp_cache_flush();
	}

	public static function synchronize_published_bundle_from_payload(int $queue_id, array $payload = []): void {
		$queue_id = (int) $queue_id;
		if ($queue_id <= 0) {
			return;
		}

		$item = EPV2_Queue::get_item($queue_id);
		if (! $item) {
			return;
		}

		$posts = self::extract_existing_posts($item);
		if ($posts === []) {
			return;
		}

		if ($payload === []) {
			$payload = EPV2_Review::ensure_payload($item);
		}

		$categories = self::normalize_categories((string) (implode(',', (array) ($payload['categories'] ?? [])) ?: $item->category_final ?: $item->category_proposed ?: 'deutschland'));
		$source_dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$source_url = EPV2_Source_Enricher::best_source_url($source_dossier, (string) $item->original_url);
		$shared_media_url = trim((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''));

		foreach ($posts as $lang => $post_id) {
			$post_id = (int) $post_id;
			if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
				continue;
			}

			$lang_payload = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
			$title = trim((string) ($lang_payload['title'] ?? ''));
			$excerpt = trim((string) ($lang_payload['excerpt'] ?? ''));
			$content = (string) ($lang_payload['content'] ?? '');
			$media_url = $shared_media_url !== '' ? $shared_media_url : trim((string) ($lang_payload['media_url'] ?? ''));
			$inline_media_urls = EPV2_Media::normalize_media_list($lang_payload['inline_media_urls'] ?? $payload['inline_media_urls'] ?? []);
			$term_ids = self::term_ids_for_language($categories, (string) $lang);
			$slug = sanitize_title((string) ($lang_payload['slug'] ?? ''));
			$seo_title = trim((string) ($lang_payload['seo_title'] ?? ''));
			$meta_description = trim((string) ($lang_payload['meta_description'] ?? ''));
			$focus_keywords = is_array($lang_payload['focus_keywords'] ?? null) ? $lang_payload['focus_keywords'] : [];

			$post_update = [
				'ID' => $post_id,
				'post_excerpt' => $excerpt,
				'post_content' => self::build_post_content($content, $excerpt, $media_url, $inline_media_urls, $source_url, (string) $lang, $categories, $post_id),
				'post_category' => $term_ids,
			];
			if ($title !== '') {
				$post_update['post_title'] = $title;
			}
			if ($slug !== '') {
				$post_update['post_name'] = $slug;
			}

			$result = wp_update_post($post_update, true);
			if (is_wp_error($result)) {
				throw new RuntimeException($result->get_error_message());
			}

			if ($term_ids !== []) {
				wp_set_post_terms($post_id, $term_ids, 'category', false);
			}

			update_post_meta($post_id, '_epv2_source_url', $source_url);
			update_post_meta($post_id, '_epv2_queue_id', (int) $item->id);
			update_post_meta($post_id, '_epv2_publish_media_url', $media_url);
			update_post_meta($post_id, '_epv2_title_hash', hash('sha256', mb_strtolower(trim($title !== '' ? $title : (string) get_the_title($post_id)))));
			update_post_meta($post_id, '_epv2_content_hash', hash('sha256', mb_strtolower(trim(wp_strip_all_tags($content)))));
			update_post_meta($post_id, '_epv2_semantic_hash', hash('sha256', self::semantic_keywords(($title !== '' ? $title : (string) get_the_title($post_id)) . ' ' . $excerpt . ' ' . wp_strip_all_tags($content))));
			update_post_meta($post_id, '_epv2_primary_category', (string) ($categories[0] ?? 'deutschland'));
			self::apply_editorial_meta($post_id, $payload);
			self::apply_seo_meta(
				$post_id,
				$seo_title !== '' ? $seo_title : ($title !== '' ? $title : (string) get_the_title($post_id)),
				$meta_description !== '' ? $meta_description : $excerpt,
				$categories,
				$focus_keywords
			);
			self::apply_tags($post_id, $payload, (string) $lang, $focus_keywords, $categories);

			if ($media_url !== '') {
				$media_check = EPV2_Media::validate_featured_media($media_url, $post_id, $title !== '' ? $title : (string) get_the_title($post_id));
				$media = is_array($media_check['media'] ?? null) ? $media_check['media'] : [];
				if (($media['type'] ?? 'image') === 'image' && ! empty($media['attachment_id']) && ! empty($media['usable'])) {
					set_post_thumbnail($post_id, (int) $media['attachment_id']);
				}
			}

			clean_post_cache($post_id);
		}

		if (function_exists('pll_save_post_translations') && count($posts) > 1) {
			pll_save_post_translations($posts);
		}

		self::synchronize_bundle_thumbnail($posts);
		wp_cache_flush();
	}

	private static function normalize_categories(string $value): array {
		$parts = array_values(array_filter(array_map('trim', explode(',', $value))));
		$parts = array_values(array_unique($parts));
		if ($parts === []) {
			return ['deutschland'];
		}
		return array_slice($parts, 0, 1);
	}

	private static function term_ids_for_language(array $categories, string $lang): array {
		$ids = [];
		foreach ($categories as $category) {
			$term = EPV2_Taxonomy_Map::map((string) $category, $lang);
			if (! empty($term['term_id'])) {
				$ids[] = (int) $term['term_id'];
			}
		}
		return array_values(array_unique($ids));
	}

	private static function state_for_post_statuses(array $statuses): string {
		$statuses = array_values(array_filter($statuses));
		if (empty($statuses)) {
			return 'error';
		}
		if (count(array_unique($statuses)) > 1) {
			return 'partially_created';
		}
		return match ($statuses[0]) {
			'publish' => 'published',
			'pending' => 'pending_review',
			default => 'draft_created',
		};
	}

	private static function apply_seo_meta(int $post_id, string $title, string $excerpt, array $categories, $focus_keywords = []): void {
		$focus = [];
		if (is_array($focus_keywords) && ! empty($focus_keywords)) {
			foreach ($focus_keywords as $keyword) {
				$focus[] = sanitize_text_field((string) $keyword);
			}
		}
		if (empty($focus)) {
			foreach ($categories as $category) {
				$focus[] = (string) $category;
			}
		}
		$focus_keyword = implode(', ', array_slice($focus, 0, 3));
		update_post_meta($post_id, 'rank_math_title', $title);
		update_post_meta($post_id, 'rank_math_description', $excerpt !== '' ? $excerpt : wp_trim_words(wp_strip_all_tags($title), 20, ''));
		update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
	}

	private static function apply_tags(int $post_id, array $payload, string $lang, $focus_keywords, array $categories): void {
		$tags = [];
		$lang_tags = $payload['languages'][$lang]['tags'] ?? [];
		if (is_array($lang_tags)) {
			foreach ($lang_tags as $tag) {
				$tags[] = sanitize_text_field((string) $tag);
			}
		}
		$payload_tags = $payload['tags'] ?? [];
		if (is_array($payload_tags)) {
			foreach ($payload_tags as $tag) {
				$tags[] = sanitize_text_field((string) $tag);
			}
		}
		if (is_array($focus_keywords)) {
			foreach ($focus_keywords as $tag) {
				$tags[] = sanitize_text_field((string) $tag);
			}
		}
		if (empty($tags)) {
			foreach ($categories as $category) {
				$tags[] = sanitize_text_field((string) $category);
			}
		}
		$tags = array_slice(array_values(array_unique(array_filter(array_map('trim', $tags)))), 0, 8);
		if ($tags !== []) {
			wp_set_post_tags($post_id, $tags, false);
		}
	}

	private static function build_post_content(string $content, string $excerpt, string $media_url, array $inline_media_urls, string $source_url, string $lang, array $categories, int $post_id = 0): string {
		$prefix = EPV2_Media::content_prefix($media_url, $lang);
		$inline_media_urls = array_values(array_filter(EPV2_Media::normalize_media_list($inline_media_urls), static function (string $url) use ($media_url): bool {
			return $url !== '' && $url !== $media_url;
		}));
		$clean_content = self::strip_duplicate_lead($content, $excerpt);
		$clean_content = self::inject_inline_media_into_content($clean_content, $inline_media_urls, $lang);
		$related = EPV2_Internal_Linker::block(EPV2_Internal_Linker::suggest($categories, $lang, 3, $post_id), $lang);
		$body = trim($prefix . "\n\n" . $clean_content . "\n\n" . $related);
		return EPV2_Compliance::append_source_block($body, $source_url, 'Originalquelle', $lang);
	}

	private static function inject_inline_media_into_content(string $content, array $inline_media_urls, string $lang): string {
		$urls = array_values(array_filter(EPV2_Media::normalize_media_list($inline_media_urls)));
		if ($urls === []) {
			return $content;
		}

		preg_match_all('/<p\b[^>]*>.*?<\/p>/isu', $content, $matches);
		$paragraphs = $matches[0] ?? [];
		if ($paragraphs === []) {
			return trim($content . "\n\n" . EPV2_Media::inline_blocks($urls, $lang));
		}

		$insertion_points = [2, 5, 8];
		$rendered = [];
		foreach ($urls as $url) {
			$block = EPV2_Media::inline_blocks([$url], $lang);
			if ($block !== '') {
				$rendered[] = $block;
			}
		}
		if ($rendered === []) {
			return $content;
		}

		$total_paragraphs = count($paragraphs);
		if ($total_paragraphs <= 3) {
			return trim($content . "\n\n" . implode("\n\n", $rendered));
		}

		if ($total_paragraphs <= 5) {
			$insertion_points = [3, 5];
		} else {
			$insertion_points = [3, 6, 9];
		}

		$result = '';
		$media_index = 0;
		foreach ($paragraphs as $index => $paragraph) {
			$result .= ($result === '' ? '' : "\n\n") . $paragraph;
			$position = $index + 1;
			$target = $insertion_points[$media_index] ?? ($total_paragraphs - 1);
			if ($media_index < count($rendered) && $position >= max(2, min($target, $total_paragraphs - 1))) {
				$result .= "\n\n" . $rendered[$media_index];
				$media_index++;
			}
		}
		while ($media_index < count($rendered)) {
			$result .= "\n\n" . $rendered[$media_index];
			$media_index++;
		}

		return trim($result);
	}

	private static function resolve_publish_media_url(array $lang_payload, array $payload, array $categories, string $title, string $excerpt, int $queue_id = 0): string {
		$media_url = (string) ($lang_payload['media_url'] ?? $payload['featured_media_url'] ?? $payload['media_url'] ?? '');
		if ($media_url !== '') {
			return $media_url;
		}
		return EPV2_Media::resolve_featured_media($title, $excerpt, $categories, '', (array) ($payload['_meta']['source_dossier'] ?? []), $queue_id);
	}

	private static function resolve_shared_publish_media_url(object $item, array $payload, array $categories, array $source_dossier): string {
		$de_payload = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title = (string) ($de_payload['title'] ?? $item->original_title);
		$excerpt = (string) ($de_payload['excerpt'] ?? $item->original_excerpt ?? '');
		return self::resolve_publish_media_url($de_payload, $payload, $categories, $title, $excerpt, (int) $item->id);
	}

	private static function preflight_shared_publish_media_url(string $media_url, array $payload, object $item, array $categories, array $source_dossier): string {
		$media_url = esc_url_raw($media_url);
		$de_payload = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title = (string) ($de_payload['title'] ?? $item->original_title ?? '');
		$excerpt = (string) ($de_payload['excerpt'] ?? $item->original_excerpt ?? '');
		if ($media_url === '') {
			$media_url = EPV2_Media::resolve_featured_media($title, $excerpt, $categories, '', $source_dossier, (int) $item->id);
		}
		if ($media_url === '') {
			throw new RuntimeException('Нельзя публиковать: у DE-версии не установлено featured image.');
		}

		$check = EPV2_Media::validate_featured_media($media_url, 0, $title);
		if (! empty($check['ok'])) {
			return $media_url;
		}

		$fallback = EPV2_Media::resolve_featured_media($title, $excerpt, $categories, '', $source_dossier, (int) $item->id);
		if ($fallback !== '' && $fallback !== $media_url) {
			$fallback_check = EPV2_Media::validate_featured_media($fallback, 0, $title);
			if (! empty($fallback_check['ok'])) {
				return $fallback;
			}
		}

		throw new RuntimeException('Нельзя публиковать: у DE-версии не установлено featured image.');
	}

	private static function strip_duplicate_lead(string $content, string $excerpt): string {
		$excerpt_plain = trim(wp_strip_all_tags($excerpt));
		if ($excerpt_plain === '') {
			return $content;
		}
		if (preg_match('/^\s*<p>(.*?)<\/p>/isu', $content, $match)) {
			$first_paragraph = trim(wp_strip_all_tags((string) $match[1]));
			if ($first_paragraph !== '' && mb_strtolower($first_paragraph) === mb_strtolower($excerpt_plain)) {
				$content = preg_replace('/^\s*<p>.*?<\/p>\s*/isu', '', $content, 1) ?? $content;
			}
		}
		return trim($content);
	}

	private static function semantic_keywords(string $text): string {
		$text = mb_strtolower(trim(wp_strip_all_tags($text)));
		$text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?: $text;
		$text = preg_replace('/\s+/u', ' ', $text) ?: $text;
		$words = array_filter(explode(' ', $text), static fn($word) => mb_strlen($word) > 3);
		sort($words);
		return implode('|', array_unique($words));
	}

	private static function apply_editorial_meta(int $post_id, array $payload): void {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$lang = function_exists('pll_get_post_language') ? (string) pll_get_post_language($post_id, 'slug') : '';
		$is_breaking = ! empty($meta['breaking']);
		$is_top_story = ! empty($meta['top_story']);
		$hours = max(1, min(24, (int) ($meta['breaking_hours'] ?? EPV2_Settings::get('auto_breaking_hours', 6))));
		update_post_meta($post_id, 'europulse_breaking', $is_breaking ? 1 : 0);
		if ($is_breaking) {
			update_post_meta($post_id, 'europulse_breaking_until', time() + ($hours * HOUR_IN_SECONDS));
		} else {
			delete_post_meta($post_id, 'europulse_breaking_until');
		}
		update_post_meta($post_id, 'europulse_top_story', $is_top_story ? 1 : 0);
		if (! empty($meta['story_format'])) {
			update_post_meta($post_id, 'europulse_story_format', sanitize_text_field((string) $meta['story_format']));
		}
			if (! empty($meta['cluster_id'])) {
				update_post_meta($post_id, 'europulse_story_cluster_id', (int) $meta['cluster_id']);
			}
			if (! empty($meta['topic_label'])) {
				$topic = sanitize_text_field((string) $meta['topic_label']);
				if ($lang !== '' && function_exists('europulse_localize_topic_label')) {
					$topic = europulse_localize_topic_label($topic, $lang);
				}
				update_post_meta($post_id, 'europulse_story_topic', $topic);
			}
		}

	private static function extract_existing_posts(object $item): array {
		$valid = self::find_existing_posts_by_queue((int) $item->id);
		$payload = json_decode((string) ($item->publish_payload ?? ''), true);
		if (is_array($payload) && ! empty($payload['post_ids']) && is_array($payload['post_ids'])) {
			foreach ($payload['post_ids'] as $lang => $post_id) {
				$post_id = (int) $post_id;
				if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
					continue;
				}
				if ((int) get_post_meta($post_id, '_epv2_queue_id', true) !== (int) $item->id) {
					continue;
				}
				if ((int) get_post_thumbnail_id($post_id) <= 0) {
					self::demote_invalid_existing_post($post_id, (int) $item->id);
					continue;
				}
				$valid[$lang] = $post_id;
			}
		}
		return $valid;
	}

	private static function find_existing_posts_by_queue(int $queue_id): array {
		if ($queue_id <= 0) {
			return [];
		}
		global $wpdb;
		$posts = $wpdb->get_col($wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm
				ON pm.post_id = p.ID
				AND pm.meta_key = '_epv2_queue_id'
				AND pm.meta_value = %s
			WHERE p.post_type = 'post'
			  AND p.post_status IN ('publish','draft','pending','future','private')
			ORDER BY p.ID DESC",
			(string) $queue_id
		));

		$found = [];
		$fallback = [];
		foreach ((array) $posts as $post_id) {
			$post_id = (int) $post_id;
			if ($post_id <= 0) {
				continue;
			}

			$lang = function_exists('pll_get_post_language') ? (string) pll_get_post_language($post_id, 'slug') : '';
			if ($lang === '') {
				continue;
			}
			$has_thumb = (int) get_post_thumbnail_id($post_id) > 0;
			if (! isset($fallback[$lang])) {
				$fallback[$lang] = $post_id;
			}
			if ($has_thumb && ! isset($found[$lang])) {
				$found[$lang] = $post_id;
				continue;
			}
			if (! $has_thumb) {
				self::demote_invalid_existing_post($post_id, $queue_id);
			}
		}

		foreach ($fallback as $lang => $post_id) {
			if (! isset($found[$lang])) {
				$found[$lang] = $post_id;
			}
		}

		return $found;
	}

	private static function demote_invalid_existing_post(int $post_id, int $queue_id): void {
		if ($post_id <= 0) {
			return;
		}
		if ((int) get_post_meta($post_id, '_epv2_queue_id', true) !== $queue_id) {
			return;
		}
		$post = get_post($post_id);
		if (! $post instanceof WP_Post || $post->post_type !== 'post') {
			return;
		}
		if ($post->post_status !== 'draft') {
			wp_update_post([
				'ID' => $post_id,
				'post_status' => 'draft',
			]);
		}
	}

	private static function cleanup_partial_drafts_for_queue(int $queue_id): void {
		if ($queue_id <= 0) {
			return;
		}
		$posts = get_posts([
			'post_type' => 'post',
			'post_status' => ['draft', 'pending', 'future', 'private'],
			'numberposts' => -1,
			'meta_key' => '_epv2_queue_id',
			'meta_value' => $queue_id,
			'fields' => 'ids',
		]);
		foreach ((array) $posts as $post_id) {
			$post_id = (int) $post_id;
			if ($post_id <= 0) {
				continue;
			}
			wp_trash_post($post_id);
		}
	}

	private static function cleanup_stale_queue_posts(int $queue_id, array $keep_post_ids): void {
		if ($queue_id <= 0) {
			return;
		}
		$keep_post_ids = array_values(array_filter(array_map('intval', $keep_post_ids)));
		$posts = get_posts([
			'post_type' => 'post',
			'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
			'numberposts' => -1,
			'meta_key' => '_epv2_queue_id',
			'meta_value' => $queue_id,
			'fields' => 'ids',
		]);
		foreach ((array) $posts as $post_id) {
			$post_id = (int) $post_id;
			if ($post_id <= 0 || in_array($post_id, $keep_post_ids, true)) {
				continue;
			}
			$status = (string) get_post_status($post_id);
			if (in_array($status, ['draft', 'pending', 'future', 'private'], true)) {
				wp_trash_post($post_id);
				continue;
			}
			wp_update_post([
				'ID' => $post_id,
				'post_status' => 'draft',
			]);
		}
	}

	private static function assert_multilingual_payload_ready(array $payload, ?object $item = null): void {
		if (! EPV2_AI_Processor::payload_is_publish_ready($payload)) {
			throw new RuntimeException('Нельзя публиковать: материал не дотянул до полного publish-grade quality gate.');
		}
		if (! self::payload_has_publish_grade_substance($payload)) {
			throw new RuntimeException('Нельзя публиковать: материал недостаточно усилен источниками или цитатами.');
		}
		if ($item && self::is_stale_item($item, $payload)) {
			throw new RuntimeException('Нельзя публиковать: новость устарела и потеряла актуальность для ленты.');
		}
		$de = $payload['languages']['de'] ?? [];
		$uk = $payload['languages']['uk'] ?? [];
		$en = $payload['languages']['en'] ?? [];
		$provider = (string) ($payload['_meta']['provider'] ?? '');

		$deTitle = trim((string) ($de['title'] ?? ''));
		$ukTitle = trim((string) ($uk['title'] ?? ''));
		$enTitle = trim((string) ($en['title'] ?? ''));
		$ukContent = trim(wp_strip_all_tags((string) ($uk['content'] ?? '')));
		$enContent = trim(wp_strip_all_tags((string) ($en['content'] ?? '')));
		$deContent = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));

		$ukLooksBroken = $ukTitle === '' || $ukTitle === $deTitle || $ukContent === '' || $ukContent === $deContent || ! preg_match('/\p{Cyrillic}/u', $ukTitle . ' ' . $ukContent);
		$enLooksBroken = $enTitle === '' || $enTitle === $deTitle || $enContent === '' || $enContent === $deContent;

		if ($ukLooksBroken || $enLooksBroken) {
			$message = $provider === ''
				? 'Нельзя публиковать: multilingual payload не создан. Вероятно, AI ушёл в fallback или закончилась квота API.'
				: 'Нельзя публиковать: одна или несколько языковых версий не прошли multilingual validation.';
			throw new RuntimeException($message);
		}
	}

	private static function ensure_published_posts_have_thumbnails(array $post_ids): void {
		foreach ($post_ids as $lang => $post_id) {
			if ((int) get_post_thumbnail_id((int) $post_id) > 0) {
				continue;
			}
			$media_url = (string) get_post_meta((int) $post_id, '_epv2_publish_media_url', true);
			if ($media_url !== '') {
				$check = EPV2_Media::validate_featured_media($media_url, (int) $post_id, (string) get_the_title((int) $post_id));
				$media = is_array($check['media'] ?? null) ? $check['media'] : [];
				if (($media['type'] ?? 'image') === 'image' && ! empty($media['attachment_id']) && ! empty($media['usable'])) {
					set_post_thumbnail((int) $post_id, (int) $media['attachment_id']);
				}
			}
			if ((int) get_post_thumbnail_id((int) $post_id) > 0) {
				continue;
			}
			wp_update_post([
				'ID' => (int) $post_id,
				'post_status' => 'draft',
			]);
			throw new RuntimeException(sprintf('Нельзя публиковать: у %s-версии не установлено featured image.', strtoupper((string) $lang)));
		}
	}

	private static function synchronize_bundle_thumbnail(array $post_ids): void {
		$primary_id = 0;
		foreach (['de', 'uk', 'en'] as $lang) {
			if (! empty($post_ids[$lang])) {
				$thumb_id = (int) get_post_thumbnail_id((int) $post_ids[$lang]);
				if ($thumb_id > 0) {
					$primary_id = $thumb_id;
					break;
				}
			}
		}
		if ($primary_id <= 0) {
			return;
		}
		foreach ($post_ids as $post_id) {
			$post_id = (int) $post_id;
			if ($post_id <= 0) {
				continue;
			}
			if ((int) get_post_thumbnail_id($post_id) !== $primary_id) {
				set_post_thumbnail($post_id, $primary_id);
			}
		}
	}

	private static function automation_requires_publish_grade(): bool {
		$mode = (string) EPV2_Settings::get('mode', 'semi');
		$default_status = (string) EPV2_Settings::get('default_post_status', 'draft');
		return $mode === 'auto' && in_array($default_status, ['publish', 'pending'], true);
	}

	private static function payload_has_publish_grade_substance(array $payload): bool {
		return EPV2_AI_Processor::payload_has_publish_substance($payload);
	}

	private static function looks_like_official_primary(string $url): bool {
		$host = (string) wp_parse_url($url, PHP_URL_HOST);
		if ($host === '') {
			return false;
		}
		$signals = [
			'bundesregierung',
			'bundestag',
			'bundesrat',
			'arbeitsagentur',
			'bamf',
			'service.bund',
			'bundesgesundheitsministerium',
			'bundesministerium',
			'bayern.de',
			'muenchen.de',
			'europa.eu',
			'europarl.europa.eu',
			'ec.europa.eu',
		];
		foreach ($signals as $signal) {
			if (str_contains($host, $signal)) {
				return true;
			}
		}
		return false;
	}

	private static function is_hard_publish_blocker(Throwable $e): bool {
		$message = trim($e->getMessage());
		if ($message === '') {
			return false;
		}
		if (str_starts_with($message, 'Нельзя публиковать:')) {
			return true;
		}
		return false;
	}

	private static function is_stale_publish_blocker(Throwable $e): bool {
		$message = trim($e->getMessage());
		if ($message === '') {
			return false;
		}
		return str_contains($message, 'новость устарела') || str_contains($message, 'lost relevance');
	}

	private static function is_media_publish_blocker(Throwable $e): bool {
		$message = trim($e->getMessage());
		if ($message === '') {
			return false;
		}
		return str_contains($message, 'featured image') || str_contains($message, 'featured media');
	}

	private static function invalidate_payload_media(int $item_id): void {
		$item = EPV2_Queue::get_item($item_id);
		if (! $item) {
			return;
		}
		$blocked_url = '';
		$existing_posts = self::extract_existing_posts($item);
		foreach ((array) $existing_posts as $post_id) {
			$post_id = (int) $post_id;
			if ($post_id <= 0) {
				continue;
			}
			$blocked_url = (string) get_post_meta($post_id, '_epv2_publish_media_url', true);
			if ($blocked_url !== '') {
				break;
			}
		}
		$payload = json_decode((string) ($item->ai_payload ?? ''), true);
		if (! is_array($payload) || $payload === []) {
			return;
		}
		if ($blocked_url === '') {
			$blocked_url = (string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? '');
		}
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload['_meta']['blocked_media_urls'] = array_values(array_unique(array_filter(array_merge(
			(array) ($payload['_meta']['blocked_media_urls'] ?? []),
			$blocked_url !== '' ? [$blocked_url] : []
		))));
		$payload['featured_media_url'] = '';
		$payload['media_url'] = '';
		if (is_array($payload['languages'] ?? null)) {
			foreach ($payload['languages'] as $lang => $lang_payload) {
				if (! is_array($lang_payload)) {
					continue;
				}
				$payload['languages'][$lang]['media_url'] = '';
			}
		}
		if ($blocked_url !== '' && is_array($payload['_meta']['source_dossier'] ?? null)) {
			if (($payload['_meta']['source_dossier']['primary']['image'] ?? '') === $blocked_url) {
				$payload['_meta']['source_dossier']['primary']['image'] = '';
			}
			if (is_array($payload['_meta']['source_dossier']['supporting'] ?? null)) {
				foreach ($payload['_meta']['source_dossier']['supporting'] as $index => $entry) {
					if (! is_array($entry)) {
						continue;
					}
					if ((string) ($entry['image'] ?? '') === $blocked_url) {
						$payload['_meta']['source_dossier']['supporting'][$index]['image'] = '';
					}
				}
			}
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['blocked_media_urls'] = array_values(array_unique(array_filter(array_merge(
			(array) ($notes['_system']['blocked_media_urls'] ?? []),
			$blocked_url !== '' ? [$blocked_url] : []
		))));
		EPV2_Queue::update_fields($item_id, [
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
	}

	private static function is_stale_item(object $item, array $payload): bool {
		$categories = array_values(array_filter(array_map('sanitize_key', (array) ($payload['categories'] ?? []))));
		$primary_category = (string) ($categories[0] ?? sanitize_key((string) ($item->category_final ?? $item->category_proposed ?? '')));
		$story_format = sanitize_key((string) ($payload['_meta']['story_format'] ?? ''));
		$selection = is_array($payload['_meta']['selection'] ?? null) ? $payload['_meta']['selection'] : [];
		$practical_reasons = implode(' ', array_map('strval', (array) ($selection['reasons'] ?? [])));
		$is_service_like = $story_format === 'service_note'
			|| in_array($primary_category, ['community', 'leben-in-deutschland'], true)
			|| preg_match('/praktische ценность|praktische wert|service|community|beratung|wohngeld|krankenkasse|возврат|льгот|пособ|выплат/u', $practical_reasons . ' ' . (string) ($item->original_title ?? '')) === 1;
		if ($is_service_like) {
			return false;
		}
		$date = (string) ($item->original_date ?? '');
		if ($date === '') {
			return false;
		}
		$timestamp = strtotime($date);
		if (! $timestamp) {
			return false;
		}
		if ((time() - $timestamp) > (48 * HOUR_IN_SECONDS)) {
			return true;
		}
		$haystack = mb_strtolower(trim(
			(string) ($item->original_title ?? '') . ' ' .
			(string) ($item->original_excerpt ?? '') . ' ' .
			wp_strip_all_tags((string) ($payload['languages']['de']['content'] ?? ''))
		));
		if (self::has_future_or_active_event_window($haystack)) {
			return false;
		}
		$currentYear = (int) gmdate('Y');
		$monthMap = [
			'januar' => 1, 'februar' => 2, 'märz' => 3, 'marz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6,
			'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12,
		];
		if (preg_match_all('/\b(\d{1,2})\.\s*(januar|februar|m[äa]rz|april|mai|juni|juli|august|september|oktober|november|dezember)(?:\s+(\d{4}))?\b/ui', $haystack, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$day = (int) ($match[1] ?? 0);
				$month = $monthMap[mb_strtolower((string) ($match[2] ?? ''))] ?? 0;
				$year = (int) ($match[3] ?? $currentYear);
				if ($day <= 0 || $month <= 0) {
					continue;
				}
				$eventTs = gmmktime(12, 0, 0, $month, $day, $year > 0 ? $year : $currentYear);
				if ($eventTs > 0 && (time() - $eventTs) > (36 * HOUR_IN_SECONDS) && preg_match('/\b(wird|soll|startet|beginnt|am)\b/ui', $haystack)) {
					return true;
				}
			}
		}
		return false;
	}

	private static function has_future_or_active_event_window(string $text): bool {
		$timestamps = [];
		if (preg_match_all('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/u', $text, $numericMatches, PREG_SET_ORDER)) {
			foreach ($numericMatches as $match) {
				$day = (int) ($match[1] ?? 0);
				$month = (int) ($match[2] ?? 0);
				$year = (int) ($match[3] ?? 0);
				if ($day <= 0 || $month <= 0 || $year <= 0) {
					continue;
				}
				$ts = gmmktime(12, 0, 0, $month, $day, $year);
				if ($ts > 0) {
					$timestamps[] = $ts;
				}
			}
		}

		$monthMap = [
			'januar' => 1, 'februar' => 2, 'märz' => 3, 'marz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6,
			'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12,
		];
		$currentYear = (int) gmdate('Y');
		if (preg_match_all('/\b(\d{1,2})\.\s*(januar|februar|m[äa]rz|april|mai|juni|juli|august|september|oktober|november|dezember)(?:\s+(\d{4}))?\b/ui', $text, $namedMatches, PREG_SET_ORDER)) {
			foreach ($namedMatches as $match) {
				$day = (int) ($match[1] ?? 0);
				$month = $monthMap[mb_strtolower((string) ($match[2] ?? ''))] ?? 0;
				$year = (int) ($match[3] ?? $currentYear);
				if ($day <= 0 || $month <= 0 || $year <= 0) {
					continue;
				}
				$ts = gmmktime(12, 0, 0, $month, $day, $year);
				if ($ts > 0) {
					$timestamps[] = $ts;
				}
			}
		}

		if ($timestamps === []) {
			return false;
		}
		rsort($timestamps);
		return (int) $timestamps[0] >= (time() - (36 * HOUR_IN_SECONDS));
	}
}
