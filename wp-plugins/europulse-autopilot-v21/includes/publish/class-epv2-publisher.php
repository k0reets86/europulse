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
		if (! $force && ! self::auto_publish_enabled()) {
			return;
		}
		$breaking_only = class_exists('EPV2_Time_Planner') && EPV2_Time_Planner::publish_requires_breaking_only();
		if (! EPV2_Time_Planner::should_publish($force && ! $breaking_only)) {
			return;
		}
		if (EPV2_Lock_Manager::is_active('publish')) {
			return;
		}
		if (! $force && EPV2_Lock_Manager::is_active('publish') && EPV2_Runs::has_recent_started('publish', 180)) {
			return;
		}
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
				$run_payload['promoted_ready_like_rows'] = 0;
				$item = EPV2_Queue::next_due_item_for_publish_fast(false, $breaking_only);
			if (! $item) {
				$run_payload['result'] = $breaking_only ? 'no_due_breaking_items' : 'no_due_items';
			} else {
				$run_payload['last_item_id'] = (int) $item->id;
				$run_payload['attempts'] = 1;
				try {
					EPV2_Lock_Manager::heartbeat('publish', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
					EPV2_Queue::mark_state((int) $item->id, 'publishing');
					EPV2_Queue::set_live_status((int) $item->id, 'Собираю языковые версии, фото, теги и метаданные перед публикацией.', 'publishing_bundle');
					self::publish_item($item);
					$count++;
					$run_payload['published_item_id'] = (int) $item->id;
						$run_payload['result'] = 'published_items';
				} catch (Throwable $e) {
					$errors++;
					$run_payload['last_error'] = $e->getMessage();
					$run_payload['last_error_class'] = get_class($e);
					if (self::is_stale_publish_blocker($e)) {
						$errors = max(0, $errors - 1);
						self::cleanup_partial_drafts_for_queue((int) $item->id);
						$fresh_item = EPV2_Queue::get_item((int) $item->id) ?: $item;
						$payload = [];
						try {
							$payload = EPV2_Review::ensure_payload($fresh_item);
						} catch (Throwable $payloadError) {
							$payload = [];
						}
						self::retire_stale_publish_item($fresh_item, $payload, $e->getMessage());
						$run_payload['result'] = 'stale_ready_item_retired';
					} elseif (self::is_media_publish_blocker($e) && self::publish_blocker_circuit_breaker_trips((int) $item->id, 'media:' . $e->getMessage())) {
						$run_payload['result'] = 'circuit_breaker_manual_review';
					} elseif (self::is_media_publish_blocker($e)) {
						self::invalidate_payload_media((int) $item->id);
						self::cleanup_partial_drafts_for_queue((int) $item->id);
						$fresh_item = EPV2_Queue::get_item((int) $item->id) ?: $item;
						$payload = [];
						try {
							$payload = EPV2_Review::ensure_payload($fresh_item);
						} catch (Throwable $payloadError) {
							$payload = [];
						}
						if ($payload !== []) {
							$payload = EPV2_AI_Processor::force_payload_pipeline_stage($payload, 'publish_finish');
							$notes = json_decode((string) ($fresh_item->admin_notes ?? ''), true);
							$notes = is_array($notes) ? $notes : [];
							$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
							unset($notes['_system']['retry_after']);
							$notes['_system']['live_status'] = 'Возвращаю материал в автоматическую финальную доводку: source-first media, SEO и publish-grade.';
							$notes['_system']['live_status_code'] = 'publish_finish';
							EPV2_Queue::mark_state((int) $item->id, 'retry_process', [
								'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
								'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($fresh_item->category_final ?? ''),
								'error_message' => 'Материал возвращён в автоматическую финальную доводку после media publish-blocker: ' . $e->getMessage(),
								'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
							]);
						} else {
							EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'process', $e->getMessage());
						}
						EPV2_Jobs::enqueue_process();
						$run_payload['result'] = 'media_blocker_sent_to_repair';
					} elseif (self::is_hard_publish_blocker($e) && self::publish_blocker_circuit_breaker_trips((int) $item->id, 'hard:' . $e->getMessage())) {
						$run_payload['result'] = 'circuit_breaker_manual_review';
					} elseif (self::is_hard_publish_blocker($e)) {
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
						} else {
							if ($payload !== []) {
								$payload = EPV2_AI_Processor::force_payload_pipeline_stage($payload, 'publish_finish');
							}
							EPV2_Queue::mark_state((int) $item->id, 'retry_process', [
								'ai_payload' => $payload !== [] ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : '',
								'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
								'error_message' => 'Материал возвращён в автоматическую доводку после publish-blocker: ' . $e->getMessage(),
							]);
							EPV2_Jobs::enqueue_process();
							$run_payload['result'] = 'hard_blocker_sent_to_retry_process';
						}
					} else {
						self::cleanup_partial_drafts_for_queue((int) $item->id);
						EPV2_Resilience_Manager::schedule_retry($item, 'retry_publish', 'publish', $e->getMessage());
						$run_payload['result'] = 'transient_publish_error';
					}
				}
			}

			EPV2_Stats::bump('published', $count);
			$run_payload['duration_ms'] = (int) round((microtime(true) - $started_at) * 1000);
			EPV2_Runs::finish($run, $errors ? 'finished_with_errors' : 'finished', $count, $errors, $run_payload);
		} finally {
			EPV2_Lock_Manager::release('publish', $lock);
			if (EPV2_Queue::has_processable_items() && ! EPV2_Lock_Manager::is_active('process')) {
				EPV2_Jobs::enqueue_process();
			}
		}
	}

	private static function requeue_review_candidate_for_process(object $item, array $payload, bool $auto_rework): void {
		if ($payload !== []) {
			$payload = EPV2_AI_Processor::force_payload_pipeline_stage($payload, $auto_rework ? 'rebuild_bundle' : 'publish_finish');
		}
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

	private static function retire_stale_publish_item(object $item, array $payload, string $reason): void {
		$category_final = (string) ($item->category_final ?? $item->category_proposed ?? '');
		if ($payload !== []) {
			$payload = EPV2_AI_Processor::force_payload_pipeline_stage($payload, '');
			$category_final = implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: $category_final;
		}
		$error = 'Материал снят из очереди публикации как устаревший: ' . $reason;
		EPV2_Queue::mark_state((int) $item->id, 'rejected', [
			'ai_payload' => $payload !== [] ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : '',
			'category_final' => $category_final,
			'error_message' => $error,
		]);
	}

	private static function promote_publishable_review_items(int $limit = 10): void {
		$limit = max(1, min(10, $limit));
		$rows = EPV2_Queue::get_queue_items_summary([
			'states' => ['ready_review'],
			'limit' => $limit,
		]);
		usort($rows, static function ($a, $b): int {
			$aScore = (int) ($a->story_score ?? 0);
			$bScore = (int) ($b->story_score ?? 0);
			if ($aScore !== $bScore) {
				return $bScore <=> $aScore;
			}
			return strcmp((string) ($a->created_at ?? ''), (string) ($b->created_at ?? ''));
		});
		foreach ((array) $rows as $item) {
			try {
				$payload = EPV2_Review::ensure_payload($item);
				$payload = EPV2_AI_Processor::try_lift_payload_to_publish_grade($item, $payload);
				if (EPV2_AI_Processor::transition_item_to_ready_publish((int) $item->id, $payload, [
					'error_message' => '',
				])) {
					EPV2_Review::save_payload((int) $item->id, $payload);
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
		if (
			! property_exists($item, 'original_content')
			|| ! property_exists($item, 'original_excerpt')
			|| ! property_exists($item, 'original_title')
		) {
			$full_item = EPV2_Queue::get_item((int) ($item->id ?? 0));
			if (is_object($full_item)) {
				$item = $full_item;
			}
		}
		$publish_started_at = microtime(true);
		$log_step = static function (string $step, array $extra = []) use ($item, $publish_started_at): void {
			$context = array_merge([
				'item_id' => (int) ($item->id ?? 0),
				'elapsed_ms' => (int) round((microtime(true) - $publish_started_at) * 1000),
			], $extra);
			EPV2_Logger::info('publish_item', $step, $context);
		};

		$log_step('start');
		$payload = EPV2_Review::ensure_payload($item);
		$log_step('payload_ready', [
			'has_featured_media' => ! empty($payload['featured_media_url']) ? 1 : 0,
		]);
		if (! empty($payload['_meta']['translations_deferred'])) {
			$payload = EPV2_AI_Processor::repair_payload_languages($payload);
			EPV2_Review::save_payload((int) $item->id, $payload);
			$log_step('translations_repaired');
		}
		self::assert_multilingual_payload_ready($payload, $item);
		$log_step('multilingual_ready');
		$categories = self::normalize_categories((string) (implode(',', $payload['categories'] ?? []) ?: $item->category_proposed ?: $item->category_final ?: 'deutschland'));
		// 2026-05-12: hierarchical augmentation. Если AI вернул только 'wirtschaft'
		// но контент про Tesla/Rechenzentrum — добавляем 'auto'/'technologie' как
		// подкатегорию (term_id 31377 / 31389). Аналогично для muenchen/bayern
		// добавляется 'deutschland' parent.
		if (class_exists('EPV2_Categorizer')) {
			$de_lang = $payload['languages']['de'] ?? [];
			$cat_title = (string) ($de_lang['title'] ?? $item->original_title ?? '');
			$cat_body = (string) ($de_lang['content'] ?? $item->original_content ?? '');
			$categories = EPV2_Categorizer::expand_with_subcategories($categories, $cat_title, $cat_body);
		}
		$existing_posts = self::extract_existing_posts($item);
		$post_ids = [];
		$post_term_ids = [];
		$primary_post_id = 0;
		$created_statuses = [];
		$final_status = $status_override ?: (string) EPV2_Settings::get('default_post_status', 'draft');
		$working_status = in_array($final_status, ['publish', 'pending'], true) ? 'draft' : $final_status;

		$sourceDossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		// Pass the upfront story card alongside the dossier so the media
		// resolver can use card.media_search_terms (concrete LLM-curated
		// visual hooks) ahead of the title-keyword regex heuristic.
		if (is_array($payload['_meta']['story_card'] ?? null) && ! isset($sourceDossier['story_card'])) {
			$sourceDossier['story_card'] = $payload['_meta']['story_card'];
		}
		$sourceUrl = EPV2_Source_Enricher::best_source_url($sourceDossier, (string) $item->original_url);
		$shared_media_url = self::resolve_shared_publish_media_url($item, $payload, $categories, $sourceDossier);
		$shared_media_url = self::preflight_shared_publish_media_url($shared_media_url, $payload, $item, $categories, $sourceDossier);
		$event_key = EPV2_Deduplicator::event_key_for_candidate([
			'title' => (string) $item->original_title,
			'excerpt' => (string) $item->original_excerpt,
			'content' => (string) $item->original_content,
			'category' => (string) ($categories[0] ?? $item->category_final ?? $item->category_proposed ?? ''),
			'date' => (string) ($item->created_at ?? current_time('mysql')),
		], [
			'topic_label' => (string) ($item->topic_label ?? ''),
		]);
		$log_step('shared_media_ready', [
			'media_url' => $shared_media_url !== '' ? 1 : 0,
		]);
		foreach (EPV2_Settings::get('publish_languages', ['de', 'uk', 'en']) as $lang) {
			$log_step('language_start', ['lang' => (string) $lang]);
			$lang_payload = $payload['languages'][$lang] ?? EPV2_Review::build_language_package($item, $lang);
			$term_ids = self::term_ids_for_language($categories, $lang);
			$media_url = $shared_media_url;
			$inline_media_urls = EPV2_Media::normalize_media_list($lang_payload['inline_media_urls'] ?? $payload['inline_media_urls'] ?? []);
			$seo_title = (string) ($lang_payload['seo_title'] ?? $payload['seo']['seo_title'] ?? '');
			$meta_description = (string) ($lang_payload['meta_description'] ?? $payload['seo']['meta_description'] ?? '');
			$slug = (string) ($lang_payload['slug'] ?? $payload['seo']['slug'] ?? '');
			$focus_keywords = $lang_payload['focus_keywords'] ?? $payload['seo']['focus_keywords'] ?? [];
			$link_sources_initial = class_exists('EPV2_Source_Linker') ? EPV2_Source_Linker::sources_for_item($item, $payload) : [];
			$post_data = [
				'post_type' => 'post',
				'post_status' => $working_status,
				'post_title' => (string) ($lang_payload['title'] ?? $item->original_title),
				'post_content' => self::build_post_content((string) ($lang_payload['content'] ?? $item->original_content), (string) ($lang_payload['excerpt'] ?? ''), $media_url, $inline_media_urls, $sourceUrl, $lang, $categories, (int) ($existing_posts[$lang] ?? 0), $link_sources_initial),
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
			$log_step('language_post_inserted', ['lang' => (string) $lang, 'post_id' => $post_id]);
			if ($primary_post_id === 0 || $lang === 'de') {
				$primary_post_id = $post_id;
			}
			$post_ids[$lang] = $post_id;
			$post_term_ids[$lang] = $term_ids;

			self::update_post_meta_if_changed($post_id, '_epv2_source_url', $sourceUrl);
			self::update_post_meta_if_changed($post_id, '_epv2_queue_id', (int) $item->id);
			self::update_post_meta_if_changed($post_id, '_epv2_publish_media_url', $media_url);
			// Card-lead — выделенный AI-сгенерированный лид-магнит для карточек на главной.
			// Если worker сгенерил его и провалидировал — он уже sanitised на стороне Python.
			// Mu-plugin читает это поле первым; пустота → fallback на post_excerpt.
			$card_lead_payload = isset($lang_payload['card_lead']) ? trim((string) $lang_payload['card_lead']) : '';
			self::update_post_meta_if_changed($post_id, '_europulse_card_lead', sanitize_text_field($card_lead_payload));
			self::update_post_meta_if_changed($post_id, '_epv2_title_hash', hash('sha256', mb_strtolower(trim((string) ($lang_payload['title'] ?? $item->original_title)))));
			self::update_post_meta_if_changed($post_id, '_epv2_content_hash', hash('sha256', mb_strtolower(trim(wp_strip_all_tags((string) ($lang_payload['content'] ?? $item->original_content))))));
			self::update_post_meta_if_changed($post_id, '_epv2_semantic_hash', hash('sha256', self::semantic_keywords((string) ($lang_payload['title'] ?? '') . ' ' . (string) ($lang_payload['excerpt'] ?? '') . ' ' . wp_strip_all_tags((string) ($lang_payload['content'] ?? '')))));
			self::update_post_meta_if_changed($post_id, '_epv2_cluster_id', (string) ((int) ($item->cluster_id ?? 0)));
			self::update_post_meta_if_changed($post_id, '_epv2_topic_label', sanitize_text_field((string) ($item->topic_label ?? '')));
			self::update_post_meta_if_changed($post_id, '_epv2_event_key', $event_key);
			self::update_post_meta_if_changed($post_id, '_epv2_primary_category', (string) ($categories[0] ?? 'deutschland'));
			self::apply_editorial_meta($post_id, $payload);
			self::apply_selection_meta($post_id, $payload, $item);
			self::apply_seo_meta($post_id, $seo_title !== '' ? $seo_title : (string) ($lang_payload['title'] ?? $item->original_title), $meta_description !== '' ? $meta_description : (string) ($lang_payload['excerpt'] ?? ''), $categories, $focus_keywords);
			self::apply_tags($post_id, $payload, $lang, $focus_keywords, $categories);
			$log_step('language_meta_applied', ['lang' => (string) $lang, 'post_id' => $post_id]);
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
							$media_url = $fallback_media_url;
						}
					}
				}
			}
			$thumb_id = (int) get_post_thumbnail_id($post_id);
				if ($thumb_id > 0) {
					$remote = (string) get_post_meta($thumb_id, '_epv2_remote_source_url', true);
					if ($remote !== '') {
						self::update_post_meta_if_changed($post_id, '_epv2_featured_media_origin_fingerprint', EPV2_Media::media_fingerprint($remote));
					}
					self::update_post_meta_if_changed($post_id, '_epv2_featured_media_fingerprint', EPV2_Media::attachment_fingerprint($thumb_id, $remote));
				}
				$log_step('language_media_applied', ['lang' => (string) $lang, 'post_id' => $post_id, 'thumb_id' => $thumb_id]);
				$media_meta = EPV2_Media::media_metadata($media_url, $lang, (string) ($lang_payload['title'] ?? $item->original_title));
				self::update_post_meta_if_changed($post_id, '_epv2_media_origin_url', (string) ($media_meta['origin_url'] ?? ''));
				self::update_post_meta_if_changed($post_id, '_epv2_media_credit', (string) ($media_meta['credit'] ?? ''));
				self::update_post_meta_if_changed($post_id, '_epv2_media_caption', (string) ($media_meta['caption'] ?? ''));
				self::sync_featured_attachment_caption($post_id, (string) $lang, (string) ($media_meta['caption'] ?? ''));
				if (method_exists(EPV2_Media::class, 'media_diagnostics')) {
					self::update_post_meta_if_changed($post_id, '_epv2_media_diagnostics', wp_json_encode(EPV2_Media::media_diagnostics(
						$media_url,
						(string) ($lang_payload['title'] ?? $item->original_title),
						(string) ($lang_payload['excerpt'] ?? ''),
						$categories,
						$sourceDossier
					), JSON_UNESCAPED_UNICODE));
				}
		}
		$log_step('language_loop_complete', ['post_count' => count($post_ids)]);

		if (function_exists('pll_set_post_language')) {
			foreach ($post_ids as $lang => $post_id) {
				pll_set_post_language($post_id, $lang);
			}
		}
		$log_step('pll_languages_set', ['post_count' => count($post_ids)]);

		if (function_exists('pll_save_post_translations') && count($post_ids) > 1) {
			pll_save_post_translations($post_ids);
		}
		$log_step('pll_translations_saved', ['post_count' => count($post_ids)]);

		foreach ($post_ids as $lang => $post_id) {
			$term_ids = array_values(array_unique(array_map('intval', $post_term_ids[$lang] ?? [])));
			if ($term_ids !== []) {
				wp_set_post_terms((int) $post_id, $term_ids, 'category', false);
			}
		}
		$log_step('terms_synced', ['post_count' => count($post_ids)]);

		self::ensure_published_posts_have_thumbnails($post_ids);
		self::synchronize_bundle_thumbnail($post_ids);
		$log_step('thumbnails_synced', ['post_count' => count($post_ids)]);

		if (self::skip_heavy_post_publish_audit()) {
			EPV2_Post_Audit::repair_rendered_text_after_publish($post_ids, $item);
			$log_step('post_text_audit_complete', ['post_count' => count($post_ids)]);
		} else {
			EPV2_Post_Audit::repair_after_publish($post_ids, $payload, $item);
			$log_step('post_audit_complete', ['post_count' => count($post_ids)]);
		}
		if ($working_status !== $final_status) {
			foreach ($post_ids as $post_id) {
				wp_update_post([
					'ID' => (int) $post_id,
					'post_status' => $final_status,
				]);
			}
		}
		$log_step('final_status_applied', ['final_status' => $final_status, 'post_count' => count($post_ids)]);
		foreach ($post_ids as $post_id) {
			$created_statuses[] = (string) get_post_status((int) $post_id);
		}

			EPV2_Queue::mark_state((int) $item->id, self::state_for_post_statuses($created_statuses), [
				'post_id' => $primary_post_id,
				'publish_payload' => wp_json_encode(['post_ids' => $post_ids], JSON_UNESCAPED_UNICODE),
				'error_message' => '',
			]);
			EPV2_Queue::reanchor_ready_publish_schedule_after_publish(time());
			self::cleanup_stale_queue_posts((int) $item->id, $post_ids);
			$log_step('finish', ['primary_post_id' => $primary_post_id]);

			// R16 2026-05-14: extension hook для third-party integrations
			// (analytics, search index, archive, etc.) после успешной publication.
			do_action('epv2_after_publish', (int) $primary_post_id, $payload, (int) $item->id);

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
			self::update_post_meta_if_changed($post_id, '_epv2_primary_category', (string) ($normalized[0] ?? 'deutschland'));
		}
		self::clean_bundle_post_caches($posts);
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
		if (class_exists('EPV2_Categorizer')) {
			$de_lang_r = $payload['languages']['de'] ?? [];
			$categories = EPV2_Categorizer::expand_with_subcategories(
				$categories,
				(string) ($de_lang_r['title'] ?? $item->original_title ?? ''),
				(string) ($de_lang_r['content'] ?? $item->original_content ?? '')
			);
		}
		$source_dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$source_url = EPV2_Source_Enricher::best_source_url($source_dossier, (string) $item->original_url);
		$shared_media_url = trim((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''));
		$event_key = EPV2_Deduplicator::event_key_for_candidate([
			'title' => (string) $item->original_title,
			'excerpt' => (string) $item->original_excerpt,
			'content' => (string) $item->original_content,
			'category' => (string) ($categories[0] ?? $item->category_final ?? $item->category_proposed ?? ''),
			'date' => (string) ($item->created_at ?? current_time('mysql')),
		], [
			'topic_label' => (string) ($item->topic_label ?? ''),
		]);

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

			$link_sources_update = class_exists('EPV2_Source_Linker') ? EPV2_Source_Linker::sources_for_item($item, $payload) : [];
			$post_update = [
				'ID' => $post_id,
				'post_excerpt' => $excerpt,
				'post_content' => self::build_post_content($content, $excerpt, $media_url, $inline_media_urls, $source_url, (string) $lang, $categories, $post_id, $link_sources_update),
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

			self::update_post_meta_if_changed($post_id, '_epv2_source_url', $source_url);
			self::update_post_meta_if_changed($post_id, '_epv2_queue_id', (int) $item->id);
			self::update_post_meta_if_changed($post_id, '_epv2_publish_media_url', $media_url);
			$card_lead_payload = isset($lang_payload['card_lead']) ? trim((string) $lang_payload['card_lead']) : '';
			self::update_post_meta_if_changed($post_id, '_europulse_card_lead', sanitize_text_field($card_lead_payload));
			self::update_post_meta_if_changed($post_id, '_epv2_title_hash', hash('sha256', mb_strtolower(trim($title !== '' ? $title : (string) get_the_title($post_id)))));
			self::update_post_meta_if_changed($post_id, '_epv2_content_hash', hash('sha256', mb_strtolower(trim(wp_strip_all_tags($content)))));
			self::update_post_meta_if_changed($post_id, '_epv2_semantic_hash', hash('sha256', self::semantic_keywords(($title !== '' ? $title : (string) get_the_title($post_id)) . ' ' . $excerpt . ' ' . wp_strip_all_tags($content))));
			self::update_post_meta_if_changed($post_id, '_epv2_event_key', $event_key);
			self::update_post_meta_if_changed($post_id, '_epv2_primary_category', (string) ($categories[0] ?? 'deutschland'));
			self::apply_editorial_meta($post_id, $payload);
			self::apply_selection_meta($post_id, $payload, $item);
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
			$media_meta = EPV2_Media::media_metadata($media_url, (string) $lang, $title !== '' ? $title : (string) get_the_title($post_id));
			self::update_post_meta_if_changed($post_id, '_epv2_media_origin_url', (string) ($media_meta['origin_url'] ?? ''));
			self::update_post_meta_if_changed($post_id, '_epv2_media_credit', (string) ($media_meta['credit'] ?? ''));
			self::update_post_meta_if_changed($post_id, '_epv2_media_caption', (string) ($media_meta['caption'] ?? ''));
			self::sync_featured_attachment_caption($post_id, (string) $lang, (string) ($media_meta['caption'] ?? ''));
			if (method_exists(EPV2_Media::class, 'media_diagnostics')) {
				self::update_post_meta_if_changed($post_id, '_epv2_media_diagnostics', wp_json_encode(EPV2_Media::media_diagnostics(
					$media_url,
					$title !== '' ? $title : (string) get_the_title($post_id),
					$excerpt,
					$categories,
					$source_dossier
				), JSON_UNESCAPED_UNICODE));
			}

		}

		if (function_exists('pll_save_post_translations') && count($posts) > 1) {
			pll_save_post_translations($posts);
		}

		self::synchronize_bundle_thumbnail($posts);
		self::clean_bundle_post_caches($posts);
	}

	private static function normalize_categories(string $value): array {
		$allowed = array_keys(EPV2_Taxonomy_Map::categories());
		$parts = array_values(array_filter(array_map(static function ($part): string {
			$slug = sanitize_text_field(trim((string) $part));
			if ($slug === '') {
				return '';
			}
			return EPV2_Taxonomy_Map::normalize_slug($slug);
		}, explode(',', $value))));
		$parts = array_values(array_filter($parts, static fn(string $slug): bool => in_array($slug, $allowed, true)));
		$parts = array_values(array_unique($parts));
		if ($parts === []) {
			return ['deutschland'];
		}
		// 2026-05-12 W2.3: keep up to 3 categories (was: array_slice 0,1 — drop'ало
		// все кроме первой). expand_with_subcategories добавляет parent+child;
		// если ограничивать до 1 — теряем sub-category. Limit 3 разумно для
		// большинства items (primary + secondary + subcategory).
		return array_slice($parts, 0, 3);
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
			return 'ready_publish';
		}
		return match ($statuses[0]) {
			'publish' => 'published',
			default => 'ready_publish',
		};
	}

	/**
	 * Trim text к последнему complete sentence ≤ $max_len chars (2026-05-12).
	 *
	 * Audit нашёл 1396 / 2888 posts с rank_math_description обрезанным на
	 * середине слова (DE 389, UK 672, EN 724 — concentration в UK/EN excerpt
	 * fallback). Truncation source: translator не гарантирует sentence boundary,
	 * publisher принимал excerpt as-is.
	 *
	 * Logic:
	 *  - Если text уже ≤ max_len и заканчивается терминалом → возвращаем.
	 *  - Иначе trim к max_len, find последний терминал (.!?»"…) → trim там.
	 *  - Если в trimmed диапазоне нет терминала — последний space + ellipsis,
	 *    но не короче 60 chars (иначе слишком обрубленно — отдаём текст с …).
	 */
	private static function ensure_complete_sentence(string $text, int $max_len = 160): string {
		$text = trim($text);
		if ($text === '') return '';
		$ends_terminal = preg_match('/[.!?»"…]\s*$/u', $text) === 1;
		if (mb_strlen($text) <= $max_len && $ends_terminal) {
			return $text;
		}
		if (mb_strlen($text) > $max_len) {
			$text = mb_substr($text, 0, $max_len);
		}
		// Find last sentence terminator within the trimmed range
		if (preg_match('/^(.*[.!?»"…])[^.!?»"…]*$/u', $text, $m)) {
			$candidate = trim($m[1]);
			if (mb_strlen($candidate) >= 50) {
				return $candidate;
			}
		}
		// Fallback: last space + ellipsis (avoid mid-word cut)
		$cut = mb_strrpos($text, ' ');
		if ($cut !== false && $cut > 60) {
			return trim(mb_substr($text, 0, $cut)) . '…';
		}
		return trim($text) . '…';
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
		// P1.4 fix 2026-05-11: Rank Math is the active SEO plugin; Yoast
		// meta writes were dead writes consuming DB rows. Also: Rank Math's
		// focus_keyword expects ONE primary term, не CSV. Storing primary
		// в focus_keyword, secondary остальные в rank_math_secondary_focus_keywords.
		$primary_focus = (string) ($focus[0] ?? '');
		$secondary_focus = array_values(array_slice($focus, 1, 4));
		$description = $excerpt !== '' ? $excerpt : wp_trim_words(wp_strip_all_tags($title), 20, '');
		$description = self::ensure_complete_sentence($description, 160);
		self::update_post_meta_if_changed($post_id, 'rank_math_title', $title);
		self::update_post_meta_if_changed($post_id, 'rank_math_description', $description);
		self::update_post_meta_if_changed($post_id, 'rank_math_focus_keyword', $primary_focus);
		if ($secondary_focus !== []) {
			self::update_post_meta_if_changed($post_id, 'rank_math_secondary_focus_keywords', implode(',', $secondary_focus));
		}
		// Internal mirror for non-SEO-plugin reads (mu-plugin, admin UI).
		self::update_post_meta_if_changed($post_id, '_epv2_seo_title', $title);
		self::update_post_meta_if_changed($post_id, '_epv2_meta_desc', $description);
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
		if (class_exists('EPV2_AI_Response_Validator') && method_exists('EPV2_AI_Response_Validator', 'sanitize_tags_for_language')) {
			$tags = EPV2_AI_Response_Validator::sanitize_tags_for_language($tags, $lang, $categories, 8);
		} else {
			$tags = array_slice(array_values(array_unique(array_filter(array_map('trim', $tags)))), 0, 8);
		}
		if ($tags !== []) {
			wp_set_post_tags($post_id, $tags, false);
		}
	}

	/**
	 * Strip generic "EuroPulse berichtete zuvor über X" sentences that the AI
	 * sometimes invents even when no prior_coverage entry was supplied. The
	 * rule (base_voice.py 11c): such a backlink phrase is only allowed when
	 * an actual prior coverage URL/date exists in the payload AND is included
	 * in the sentence. Without a URL — strip the entire sentence.
	 *
	 * Сделано 2026-05-12 после audit'а: 4 из 4 sample-статей содержали
	 * unbacked phrase «EuroPulse berichtete zuvor über X.» AI игнорирует
	 * prompt rule 11c, нужен hard cleanup.
	 */
	public static function strip_unbacked_backlinks(string $content): string {
		if ($content === '') return $content;
		$content = self::strip_foreign_europulse_links($content);
		// Markers: phrase variations across DE/UK/EN. If none present → fast return.
		// 2026-05-13 hotfix: добавлены "EuroPulse раніше" (catches "EuroPulse раніше повідомляв"),
		// "EuroPulse has " (EN aux verb form), "EuroPulse hatte" (DE perfect). Старые markers
		// были substring-only и не ловили variations с adverbs между "EuroPulse" и глаголом.
		$markers = ['EuroPulse berichtete', 'EuroPulse hat ', 'EuroPulse hatte', 'EuroPulse повідом', 'EuroPulse раніше', 'EuroPulse писав', 'EuroPulse reported', 'EuroPulse previously', 'EuroPulse earlier', 'EuroPulse has '];
		$has_any = false;
		foreach ($markers as $m) {
			if (stripos($content, $m) !== false) { $has_any = true; break; }
		}
		if (! $has_any) return $content;
		// Split into paragraphs / sentences; drop any paragraph that contains a
		// EuroPulse-self-reference phrase but has no <a href=...> inside.
		// (A legitimate 11c-format backlink includes an URL — Source_Linker
		// would have left the <a> already, or the AI was instructed to embed
		// it. Without URL → it's the unbacked generic form.)
		$paragraphs = preg_split('/(<\/p>|<\/li>|<br\s*\/?>(?:\s*<br\s*\/?>)?|\n\s*\n)/iu', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (! is_array($paragraphs)) return $content;
		$out = [];
		foreach ($paragraphs as $chunk) {
			$lower = mb_strtolower($chunk);
			$hit = false;
			foreach ($markers as $m) {
				if (mb_stripos($lower, mb_strtolower($m)) !== false) { $hit = true; break; }
			}
			if ($hit) {
				// Has the chunk a real URL? Then it's a legit backlink — keep.
				if (preg_match('/<a\s[^>]*href=|https?:\/\//iu', $chunk)) {
					$out[] = $chunk;
					continue;
				}
				// Strip just the offending sentence inside the chunk (keep surrounding text).
				// 2026-05-13: regex симметричен — opening/closing character classes
				// синхронизированы [^.!?\n<>], чтобы не зацепить HTML attributes если
				// AI вставил backlink внутри тега. Также non-greedy не съест соседние
				// предложения между двумя markers подряд.
				$cleaned = preg_replace(
					'/[^.!?\n<>]*?(' . implode('|', array_map(static fn($m) => preg_quote($m, '/'), $markers)) . ')[^.!?\n<>]*[.!?]/iu',
					'',
					$chunk
				);
				if (is_string($cleaned)) {
					// Collapse any double-space / orphan <p></p>.
					$cleaned = preg_replace('/\s{2,}/u', ' ', $cleaned);
					$cleaned = preg_replace('/<p>\s*<\/p>/iu', '', (string) $cleaned);
					$out[] = $cleaned;
				} else {
					$out[] = $chunk;
				}
				continue;
			}
			$out[] = $chunk;
		}
		$joined = implode('', $out);
		// Final cleanup after reassembly — split delimiter (e.g. </p>) lives in
		// a separate array element, so empty paragraph shells only surface
		// once the chunks are re-joined. Strip them now.
		$joined = preg_replace('/<p[^>]*>\s*<\/p>/iu', '', $joined);
		$joined = preg_replace('/(\n\s*){3,}/u', "\n\n", (string) $joined);
		return (string) $joined;
	}

	private static function strip_foreign_europulse_links(string $content): string {
		$home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		$home_host = preg_replace('/^www\./i', '', $home_host) ?: '';
		$hosts = ['europulse.today', 'europulse.eu'];
		foreach ($hosts as $host) {
			if ($home_host !== '' && $home_host === $host) {
				continue;
			}
			$content = preg_replace(
				'/<a\b[^>]*href=["\']https?:\/\/(?:www\.)?' . preg_quote($host, '/') . '\b[^"\']*["\'][^>]*>(.*?)<\/a>/isu',
				'$1',
				$content
			) ?? $content;
			$content = preg_replace(
				'/https?:\/\/(?:www\.)?' . preg_quote($host, '/') . '\b[^\s<]*/iu',
				'',
				$content
			) ?? $content;
		}
		return trim((string) preg_replace("/\n{3,}/", "\n\n", $content));
	}

	public static function link_plain_urls(string $content): string {
		if ($content === '' || stripos($content, 'http') === false) {
			return $content;
		}
		$parts = preg_split('/(<a\b[^>]*>.*?<\/a>)/isu', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (! is_array($parts)) {
			return $content;
		}
		foreach ($parts as $idx => $part) {
			if ($part === '' || preg_match('/^<a\b/iu', $part)) {
				continue;
			}
			$parts[$idx] = preg_replace_callback(
				'/(?<!["\'=])\bhttps?:\/\/[^\s<>()]+/iu',
				static function (array $match): string {
					$raw = (string) ($match[0] ?? '');
					$url = rtrim($raw, '.,;:');
					$trail = substr($raw, strlen($url));
					if ($url === '' || ! wp_http_validate_url($url)) {
						return $raw;
					}
					$href = esc_url($url);
					if ($href === '') {
						return $raw;
					}
					$home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
					$url_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
					$home_host = preg_replace('/^www\./i', '', $home_host) ?: $home_host;
					$url_host = preg_replace('/^www\./i', '', $url_host) ?: $url_host;
					$external_attrs = ($home_host !== '' && $url_host !== '' && $home_host !== $url_host)
						? ' target="_blank" rel="noopener nofollow"'
						: '';
					return '<a href="' . $href . '"' . $external_attrs . '>' . esc_html($url) . '</a>' . $trail;
				},
				$part
			) ?? $part;
		}
		return implode('', $parts);
	}

	private static function build_post_content(string $content, string $excerpt, string $media_url, array $inline_media_urls, string $source_url, string $lang, array $categories, int $post_id = 0, array $link_sources = []): string {
		$prefix = EPV2_Media::content_prefix($media_url, $lang);
		$inline_media_urls = array_values(array_filter(EPV2_Media::normalize_media_list($inline_media_urls), static function (string $url) use ($media_url): bool {
			return $url !== '' && $url !== $media_url;
		}));
		$content = self::strip_unbacked_backlinks($content);
		$content = self::link_plain_urls($content);
		$clean_content = self::strip_duplicate_lead($content, $excerpt);
		// Source-linking: первое упоминание каждого источника оборачиваем
		// в <a>...</a> для legal-attribution. Применяется ДО media-инжекции
		// и compliance-block'а, потому что атрибуция живёт в основном
		// тексте AI rewriter'а.
		if ($link_sources !== [] && class_exists('EPV2_Source_Linker')) {
			$clean_content = EPV2_Source_Linker::link_attributions($clean_content, $link_sources);
		}
		$clean_content = self::inject_inline_media_into_content($clean_content, $inline_media_urls, $lang);
		$related = '';
		$body = trim($prefix . "\n\n" . $clean_content . "\n\n" . $related);
		return EPV2_Compliance::append_source_block($body, $source_url, '', $lang);
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
		$source_dossier = (array) ($payload['_meta']['source_dossier'] ?? []);
		if (is_array($payload['_meta']['story_card'] ?? null) && ! isset($source_dossier['story_card'])) {
			$source_dossier['story_card'] = $payload['_meta']['story_card'];
		}
		if (
			$media_url !== ''
			&& ! EPV2_Media::is_fallback_stock_url($media_url)
			&& self::media_candidate_passes_publish_context($media_url, $title, $excerpt, $categories, $source_dossier)
		) {
			return $media_url;
		}
		return EPV2_Media::resolve_featured_media($title, $excerpt, $categories, $media_url, $source_dossier, $queue_id);
	}

	private static function resolve_shared_publish_media_url(object $item, array $payload, array $categories, array $source_dossier): string {
		$de_payload = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title = (string) ($de_payload['title'] ?? $item->original_title);
		$excerpt = (string) ($de_payload['excerpt'] ?? $item->original_excerpt ?? '');
		$current_media_url = (string) ($de_payload['media_url'] ?? $payload['featured_media_url'] ?? $payload['media_url'] ?? '');
		if ($current_media_url !== '' && ! EPV2_Media::is_fallback_stock_url($current_media_url)) {
			$current_check = EPV2_Media::validate_featured_media($current_media_url, 0, $title);
			if (! empty($current_check['ok']) && self::media_candidate_passes_publish_context($current_media_url, $title, $excerpt, $categories, $source_dossier)) {
				return esc_url_raw($current_media_url);
			}
		}
		$source_first = EPV2_Media::resolve_featured_media($title, $excerpt, $categories, (string) ($item->source_image_url ?? $current_media_url), $source_dossier, (int) $item->id);
		if ($source_first !== '') {
			return $source_first;
		}
		return self::resolve_publish_media_url($de_payload, $payload, $categories, $title, $excerpt, (int) $item->id);
	}

	private static function preflight_shared_publish_media_url(string $media_url, array $payload, object $item, array $categories, array $source_dossier): string {
		$media_url = esc_url_raw($media_url);
		$de_payload = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title = (string) ($de_payload['title'] ?? $item->original_title ?? '');
		$excerpt = (string) ($de_payload['excerpt'] ?? $item->original_excerpt ?? '');
		if ($media_url !== '' && ! EPV2_Media::is_fallback_stock_url($media_url)) {
			$current_check = EPV2_Media::validate_featured_media($media_url, 0, $title);
			if (! empty($current_check['ok']) && self::media_candidate_passes_publish_context($media_url, $title, $excerpt, $categories, $source_dossier)) {
				return $media_url;
			}
		}
		$source_first = EPV2_Media::resolve_featured_media($title, $excerpt, $categories, (string) ($item->source_image_url ?? ''), $source_dossier, (int) $item->id);
		if ($source_first !== '') {
			$source_check = EPV2_Media::validate_featured_media($source_first, 0, $title);
			if (! empty($source_check['ok'])) {
				$media_url = $source_first;
			}
		}
		if ($media_url === '') {
			$media_url = EPV2_Media::resolve_featured_media($title, $excerpt, $categories, (string) ($item->source_image_url ?? ''), $source_dossier, (int) $item->id);
		}
		if ($media_url === '') {
			// Fix E: last-resort stock fallback. Previously this throw stalled
			// the entire publish queue when a paywalled source (Spiegel /
			// FAZ premium / NDR live ticker) refused to surface a usable
			// featured image. Operator's only recourse was to delete the
			// row by hand. Now we generate a category-aware story cover or
			// pull a Pexels/Wikimedia stock image of last resort, mark the
			// row's media_quality as 'fallback' for editorial review, and
			// keep the queue moving. Operator can swap the image after
			// publish via the WP admin without losing the article.
			$generated = EPV2_Media::generated_story_cover($title, $excerpt, $categories, $source_dossier);
			if ($generated !== '') {
				$media_url = $generated;
			}
		}
		if ($media_url === '') {
			throw new RuntimeException('Нельзя публиковать: у DE-версии не установлено featured image.');
		}

		$check = EPV2_Media::validate_featured_media($media_url, 0, $title);
		if (! empty($check['ok'])) {
			return $media_url;
		}

		$fallback = EPV2_Media::resolve_featured_media($title, $excerpt, $categories, (string) ($item->source_image_url ?? ''), $source_dossier, (int) $item->id);
		if ($fallback !== '' && $fallback !== $media_url) {
			$fallback_check = EPV2_Media::validate_featured_media($fallback, 0, $title);
			if (! empty($fallback_check['ok'])) {
				return $fallback;
			}
		}

		// Same Fix E rationale at the second exit: if validate failed even
		// on the resolver fallback, accept the unvalidated URL we got.
		// Worst case the image preview is suboptimal, but the article does
		// not get permanently stuck in publishing limbo.
		if ($media_url !== '') {
			return $media_url;
		}

		throw new RuntimeException('Нельзя публиковать: у DE-версии не установлено featured image.');
	}

	private static function media_candidate_passes_publish_context(string $media_url, string $title, string $excerpt, array $categories, array $source_dossier): bool {
		$media_url = esc_url_raw($media_url);
		if ($media_url === '') {
			return false;
		}
		if (! EPV2_Media::is_fallback_stock_url($media_url)) {
			// Source-host media (image domain matches one of the supporting
			// source URLs) is trusted by definition: AI / RSS chose the
			// original article photo. Filename-based relevance heuristic
			// fires false negatives when source uses generic slugs like
			// "image-12345.webp" or "eine-entschaerfte.webp" — those don't
			// contain title tokens but ARE the correct editorial photo.
			// Skip the heuristic for source-host; rely on the source choice.
			if (EPV2_Media::is_source_host_media($media_url, $source_dossier)) {
				return true;
			}
			return EPV2_Media::is_relevant_media($media_url, $title, $excerpt, $categories, $source_dossier);
		}
		$diagnostics = EPV2_Media::media_diagnostics($media_url, $title, $excerpt, $categories, $source_dossier);
		$risk_flags = array_map('sanitize_key', (array) ($diagnostics['risk_flags'] ?? []));
		if (in_array('pexels_blocked_for_high_context_story', $risk_flags, true)) {
			return false;
		}
		return ! empty($diagnostics['fit_pass']);
	}

	private static function skip_heavy_post_publish_audit(): bool {
		return self::auto_publish_enabled();
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
		self::update_post_meta_if_changed($post_id, 'europulse_breaking', $is_breaking ? 1 : 0);
		if ($is_breaking) {
			self::update_post_meta_if_changed($post_id, 'europulse_breaking_until', time() + ($hours * HOUR_IN_SECONDS));
		} else {
			self::delete_post_meta_if_exists($post_id, 'europulse_breaking_until');
		}
		self::update_post_meta_if_changed($post_id, 'europulse_top_story', $is_top_story ? 1 : 0);
		if (! empty($meta['story_format'])) {
			self::update_post_meta_if_changed($post_id, 'europulse_story_format', sanitize_text_field((string) $meta['story_format']));
		}
			if (! empty($meta['cluster_id'])) {
				self::update_post_meta_if_changed($post_id, 'europulse_story_cluster_id', (int) $meta['cluster_id']);
			}
			if (! empty($meta['topic_label'])) {
				$topic = sanitize_text_field((string) $meta['topic_label']);
				if ($lang !== '' && function_exists('europulse_localize_topic_label')) {
					$topic = europulse_localize_topic_label($topic, $lang);
				}
				self::update_post_meta_if_changed($post_id, 'europulse_story_topic', $topic);
			}
		}

	private static function update_post_meta_if_changed(int $post_id, string $meta_key, $value): void {
		$current = get_post_meta($post_id, $meta_key, true);
		if (maybe_serialize($current) === maybe_serialize($value)) {
			return;
		}
		update_post_meta($post_id, $meta_key, $value);
	}

	private static function sync_featured_attachment_caption(int $post_id, string $lang, string $caption): void {
		$primary_lang = (string) EPV2_Settings::get('primary_language', 'de');
		if ($lang !== $primary_lang || $caption === '') {
			return;
		}
		$thumb_id = (int) get_post_thumbnail_id($post_id);
		if ($thumb_id <= 0) {
			return;
		}
		$caption = sanitize_text_field(wp_strip_all_tags($caption));
		$update = ['ID' => $thumb_id];
		if ((string) get_post_field('post_excerpt', $thumb_id) !== $caption) {
			$update['post_excerpt'] = $caption;
		}
		if (trim((string) get_post_field('post_content', $thumb_id)) === '') {
			$update['post_content'] = $caption;
		}
		if (count($update) > 1) {
			wp_update_post($update);
		}
	}

	private static function apply_selection_meta(int $post_id, array $payload, ?object $item = null): void {
		$selection = self::canonical_selection_meta($payload, $item);
		$decision = sanitize_key((string) ($selection['decision'] ?? ''));
		$score = (int) ($selection['score'] ?? 0);

		if ($decision !== '') {
			self::update_post_meta_if_changed($post_id, 'europulse_selection_decision', $decision);
		} else {
			self::delete_post_meta_if_exists($post_id, 'europulse_selection_decision');
		}

		if ($score > 0) {
			self::update_post_meta_if_changed($post_id, 'europulse_selection_score', $score);
		} else {
			self::delete_post_meta_if_exists($post_id, 'europulse_selection_score');
		}
	}

	private static function canonical_selection_meta(array $payload, ?object $item = null): array {
		if ($item) {
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$selection = is_array($notes['selection'] ?? null) ? $notes['selection'] : [];
			$decision = sanitize_key((string) ($selection['decision'] ?? ''));
			$score = (int) ($selection['score'] ?? 0);
			if ($decision !== '' || $score > 0) {
				return [
					'decision' => $decision,
					'score' => $score,
				];
			}
		}
		return is_array($payload['_meta']['selection'] ?? null) ? $payload['_meta']['selection'] : [];
	}

	private static function delete_post_meta_if_exists(int $post_id, string $meta_key): void {
		if (metadata_exists('post', $post_id, $meta_key)) {
			delete_post_meta($post_id, $meta_key);
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
		$item_state = $item ? (string) ($item->state ?? '') : '';
		$queue_state_ready = in_array($item_state, ['ready_publish', 'publishing', 'published'], true);
		if (! $queue_state_ready && ! EPV2_AI_Processor::payload_is_publish_ready($payload)) {
			throw new RuntimeException('Нельзя публиковать: материал не дотянул до полного publish-grade quality gate.');
		}
		if (! self::payload_has_publish_grade_substance($payload)) {
			throw new RuntimeException('Нельзя публиковать: материал недостаточно усилен источниками или цитатами.');
		}
		if ($item && self::is_stale_item($item, $payload)) {
			throw new RuntimeException('Нельзя публиковать: новость устарела и потеряла актуальность для ленты.');
		}
		if ($item && self::payload_has_unsupported_explicit_event_date($item, $payload)) {
			throw new RuntimeException('Нельзя публиковать: AI-текст содержит неподтверждённую календарную дату события, которой нет в исходнике.');
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
		if (! EPV2_AI_Processor::payload_languages_are_semantically_consistent($payload)) {
			throw new RuntimeException('Нельзя публиковать: одна или несколько языковых версий ушли на другой сюжет и не соответствуют немецкому master-материалу.');
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

	private static function clean_bundle_post_caches(array $post_ids): void {
		foreach ($post_ids as $post_id) {
			$post_id = (int) $post_id;
			if ($post_id <= 0) {
				continue;
			}
			clean_post_cache($post_id);
		}
	}

	private static function automation_requires_publish_grade(): bool {
		$mode = (string) EPV2_Settings::get('mode', 'semi');
		$default_status = (string) EPV2_Settings::get('default_post_status', 'draft');
		return $mode === 'auto' && in_array($default_status, ['publish', 'pending'], true);
	}

	private static function auto_publish_enabled(): bool {
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

	/**
	 * Loop circuit-breaker: if the same publish blocker (media / hard /
	 * stale) fires ≥3 times for the same item, escalate straight to
	 * manual_review instead of looping ready_publish ↔ retry_process and
	 * burning AI tokens on the same self-rebuilding payload.
	 *
	 * Returns true when the breaker tripped (caller skips the requeue).
	 */
	private static function publish_blocker_circuit_breaker_trips(int $item_id, string $reason): bool {
		if ($reason === '') {
			return false;
		}
		$fingerprint = substr(hash('sha256', mb_strtolower($reason)), 0, 16);
		$item = EPV2_Queue::get_item_summary($item_id);
		$notes = $item ? json_decode((string) ($item->admin_notes ?? ''), true) : [];
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$breaker = is_array($notes['_system']['publish_blocker_breaker'] ?? null) ? $notes['_system']['publish_blocker_breaker'] : [];
		$current_fp = (string) ($breaker['fingerprint'] ?? '');
		$count = (int) ($breaker['count'] ?? 0);
		if ($current_fp === $fingerprint) {
			$count++;
		} else {
			$current_fp = $fingerprint;
			$count = 1;
		}
		$notes['_system']['publish_blocker_breaker'] = [
			'fingerprint' => $current_fp,
			'count' => $count,
			'last_reason' => mb_substr($reason, 0, 240),
			'updated_at' => gmdate('Y-m-d H:i:s'),
		];
		if ($count >= 3) {
			EPV2_Queue::mark_state($item_id, 'manual_review', [
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				'error_message' => 'Циркуит-брейкер: один и тот же publish-blocker сработал ' . $count . ' раз подряд. Требует ручной правки. Last reason: ' . $reason,
			]);
			return true;
		}
		// Persist incremented counter without state change so the next
		// retry sees the running count.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'epv2_queue',
			['admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE)],
			['id' => $item_id]
		);
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
		$stale_after_hours = 96;
		$breaking_candidate = ! empty($payload['_meta']['breaking']) || ! empty($payload['_meta']['top_story']);
		if ($breaking_candidate) {
			$stale_after_hours = 48;
		}
		if ((time() - $timestamp) > ($stale_after_hours * HOUR_IN_SECONDS)) {
			return true;
		}
		$haystack = mb_strtolower(trim(
			(string) ($item->original_title ?? '') . ' ' .
			(string) ($item->original_excerpt ?? '') . ' ' .
			wp_strip_all_tags((string) ($item->original_content ?? ''))
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

	private static function payload_has_unsupported_explicit_event_date(object $item, array $payload): bool {
		$source_text = mb_strtolower(trim(
			(string) ($item->original_title ?? '') . ' ' .
			(string) ($item->original_excerpt ?? '') . ' ' .
			wp_strip_all_tags((string) ($item->original_content ?? ''))
		));
		if ($source_text === '') {
			return false;
		}
		$generated_text = mb_strtolower(trim(
			(string) ($payload['languages']['de']['title'] ?? '') . ' ' .
			(string) ($payload['languages']['de']['excerpt'] ?? '') . ' ' .
			wp_strip_all_tags((string) ($payload['languages']['de']['content'] ?? ''))
		));
		if ($generated_text === '') {
			return false;
		}
		$source_ts = strtotime((string) ($item->original_date ?? '')) ?: time();
		$current_year = (int) gmdate('Y');
		$month_map = [
			'januar' => 1, 'februar' => 2, 'märz' => 3, 'marz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6,
			'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12,
		];
		if (preg_match_all('/\b(\d{1,2})\.\s*(januar|februar|m[äa]rz|april|mai|juni|juli|august|september|oktober|november|dezember)\s+(\d{4})\b/ui', $generated_text, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$day = (int) ($match[1] ?? 0);
				$month_label = mb_strtolower((string) ($match[2] ?? ''));
				$month = $month_map[$month_label] ?? 0;
				$year = (int) ($match[3] ?? 0);
				if ($day <= 0 || $month <= 0 || $year <= 0) {
					continue;
				}
				$date_label = mb_strtolower(trim((string) $match[0]));
				if (str_contains($source_text, $date_label)) {
					continue;
				}
				$event_ts = gmmktime(12, 0, 0, $month, $day, $year);
				if ($event_ts > 0 && $year < $current_year && $event_ts < ($source_ts - 36 * HOUR_IN_SECONDS)) {
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
