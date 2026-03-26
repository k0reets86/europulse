<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_AI_Processor {
		public static function process_scheduled(bool $force = false, bool $ignore_retry_after = false): void {
			$started_at = microtime(true);
			self::log_process_entry_step('enter', ['force' => $force ? 1 : 0, 'ignore_retry_after' => $ignore_retry_after ? 1 : 0]);
			if (! EPV2_Time_Planner::should_process($force)) {
				self::log_process_entry_step('skip_should_process', ['duration_ms' => self::duration_ms_since($started_at)]);
				return;
			}
		if (EPV2_Lock_Manager::is_active('process')) {
			self::log_process_entry_step('skip_active_lock', ['duration_ms' => self::duration_ms_since($started_at)]);
			return;
		}
		$recentRunGuardSeconds = (defined('WP_CLI') && WP_CLI && EPV2_Jobs::server_orchestrator_enabled()) ? 0 : 300;
		if ($recentRunGuardSeconds > 0 && EPV2_Runs::has_recent_started('process', $recentRunGuardSeconds)) {
			self::log_process_entry_step('skip_recent_started', ['duration_ms' => self::duration_ms_since($started_at)]);
			return;
		}
		$skip_inline_cleanup = $force && EPV2_Jobs::server_orchestrator_enabled();
		if (! $skip_inline_cleanup) {
			self::log_process_entry_step('before_cleanup', ['duration_ms' => self::duration_ms_since($started_at)]);
			EPV2_Resilience_Manager::cleanup();
			self::log_process_entry_step('after_cleanup', ['duration_ms' => self::duration_ms_since($started_at)]);
		} else {
			self::log_process_entry_step('skip_inline_cleanup', ['duration_ms' => self::duration_ms_since($started_at)]);
		}
		EPV2_Queue::prune_rejected(1440);
		self::log_process_entry_step('after_prune_rejected', ['duration_ms' => self::duration_ms_since($started_at)]);
		$lock = EPV2_Lock_Manager::acquire('process', (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
		if ($lock === null) {
			self::log_process_entry_step('skip_lock_acquire_failed', ['duration_ms' => self::duration_ms_since($started_at)]);
			return;
		}
		self::log_process_entry_step('after_lock_acquire', ['duration_ms' => self::duration_ms_since($started_at)]);
		$run = EPV2_Runs::start('process');
		self::log_process_entry_step('after_run_start', ['duration_ms' => self::duration_ms_since($started_at), 'run_id' => $run]);
		$count = 0;
		$errors = 0;
		$attempts = 0;
		$run_payload = [
			'result' => 'started',
		];
		try {
		while ($attempts < 1) {
			self::log_process_item_step('before_next_item', null, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($started_at)]);
			$item = EPV2_Queue::next_item_for_processing($ignore_retry_after);
			if (! $item) {
				self::log_process_item_step('no_next_item', null, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($started_at)]);
				if (($run_payload['result'] ?? 'started') === 'started') {
					$run_payload['result'] = $count > 0 ? 'processed_items' : 'no_processable_items';
				}
				break;
			}
			self::log_process_item_step('after_next_item', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($started_at)]);
			$run_payload['last_item_id'] = (int) $item->id;
			$run_payload['attempts'] = $attempts + 1;
			$attempts++;
			$payload = [];
			$baseline_payload = [];
			$analysis = [];
			$gate = [];
			$category = '';
			$auto_rework = false;
			$auto_finish = false;
			$item_started_at = microtime(true);
			try {
				EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
				$existing_payload = json_decode((string) ($item->ai_payload ?? ''), true);
				$existing_payload = is_array($existing_payload) ? $existing_payload : [];
				self::log_process_item_step('after_decode_existing_payload', (int) $item->id, ['run_id' => $run, 'has_existing_payload' => $existing_payload !== [] ? 1 : 0]);
				$payload_for_stage = $existing_payload !== [] ? self::normalize_existing_payload($existing_payload, false) : [];
				self::log_process_item_step('after_normalize_existing_payload', (int) $item->id, ['run_id' => $run, 'has_payload_for_stage' => $payload_for_stage !== [] ? 1 : 0]);
				$pipeline_stage = self::payload_pipeline_stage($payload_for_stage);
				if (
					$payload_for_stage !== []
					&& in_array($pipeline_stage, ['translate_uk', 'translate_en', 'translate_finish', 'publish_finish'], true)
				) {
					$payload_for_stage = self::refresh_payload_stage_markers($payload_for_stage);
					self::log_process_item_step('after_stage_payload_refresh', (int) $item->id, ['run_id' => $run, 'pipeline_stage' => $pipeline_stage]);
				}
				if ($payload_for_stage !== []) {
					$required_stage = self::payload_next_required_stage($payload_for_stage);
					if ($required_stage !== '' && $required_stage !== $pipeline_stage) {
						$pipeline_stage = $required_stage;
						$payload_for_stage = self::set_payload_pipeline_stage($payload_for_stage, $pipeline_stage);
						EPV2_Queue::update_fields((int) $item->id, [
							'ai_payload' => wp_json_encode($payload_for_stage, JSON_UNESCAPED_UNICODE),
						]);
						self::log_process_item_step('after_pipeline_stage_reconciled', (int) $item->id, [
							'run_id' => $run,
							'pipeline_stage' => $pipeline_stage,
							'duration_ms' => self::duration_ms_since($item_started_at),
						]);
					}
				}
				$run_payload['pipeline_stage_before'] = $pipeline_stage;
				self::log_process_item_step('after_pipeline_stage_detected', (int) $item->id, ['run_id' => $run, 'pipeline_stage' => $pipeline_stage]);
				$requires_translation_finish = $payload_for_stage !== [] && self::payload_stage_requires_translation_finish($payload_for_stage);
				$is_publish_finish_stage = ($pipeline_stage === 'publish_finish');
				if ($requires_translation_finish) {
					$requires_fresh_rebuild = false;
					$repairing_media_blocker = false;
					$auto_rework = false;
					$auto_finish = false;
				} else {
					$requires_fresh_rebuild = $payload_for_stage !== [] && self::payload_requires_fresh_rebuild_fast($payload_for_stage);
					$repairing_media_blocker = ! $is_publish_finish_stage && ! $requires_fresh_rebuild && self::item_needs_media_repair($item, $payload_for_stage);
					$auto_rework = ! $repairing_media_blocker && ($requires_fresh_rebuild || self::item_is_auto_rework_candidate($item, $payload_for_stage));
					$auto_finish = ! $repairing_media_blocker && ! $auto_rework && ($is_publish_finish_stage || self::item_is_auto_finish_candidate($item, $payload_for_stage));
				}
				self::log_process_item_step('after_stage_flags', (int) $item->id, [
					'run_id' => $run,
					'requires_translation_finish' => $requires_translation_finish ? 1 : 0,
					'requires_fresh_rebuild' => $requires_fresh_rebuild ? 1 : 0,
					'repairing_media_blocker' => $repairing_media_blocker ? 1 : 0,
					'auto_rework' => $auto_rework ? 1 : 0,
					'auto_finish' => $auto_finish ? 1 : 0,
				]);
				EPV2_Queue::set_live_status((int) $item->id, 'Проверяю рубрику, фактуру и приоритет материала.', 'analyzing');
				if ($repairing_media_blocker && $existing_payload !== []) {
					$run_payload['branch'] = 'repairing_media';
					EPV2_Queue::set_live_status((int) $item->id, 'Ищу новое фото и перепроверяю визуальный контекст.', 'repairing_media');
					$existing_payload = self::repair_payload_media($item, $existing_payload);
					EPV2_Queue::update_fields((int) $item->id, [
						'ai_payload' => wp_json_encode($existing_payload, JSON_UNESCAPED_UNICODE),
					]);
					if (self::payload_is_publish_ready($existing_payload)) {
						EPV2_Queue::mark_state((int) $item->id, 'ready_publish', [
							'ai_payload' => wp_json_encode($existing_payload, JSON_UNESCAPED_UNICODE),
							'error_message' => '',
						]);
						$count++;
						$run_payload['result'] = 'media_repaired_to_publish_ready';
						$run_payload['processed_item_id'] = (int) $item->id;
						break;
					}
					if (! self::payload_has_media_candidate($existing_payload)) {
						EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'publish_media', 'Нельзя публиковать: у DE-версии не установлено featured image.');
						$run_payload['result'] = 'media_repair_pending';
						continue;
					}
				}
				if ($existing_payload !== [] && self::payload_stage_requires_translation_finish($existing_payload)) {
					$run_payload['branch'] = 'translate_finish';
					if (self::worker_pipeline_enabled()) {
						$worker_stage = self::worker_stage_for_request($existing_payload, true, false);
						self::log_process_item_step('before_worker_stage', (int) $item->id, ['run_id' => $run, 'worker_stage' => $worker_stage]);
						EPV2_Queue::mark_state((int) $item->id, 'processing_de', [
							'error_message' => '',
						]);
						if ($worker_stage === 'rebuild_bundle') {
							EPV2_Queue::set_live_status((int) $item->id, 'Внешний worker пересобирает DE master, добирает источники, медиа и только потом завершает языки.', 'worker_rebuild');
						} else {
							EPV2_Queue::set_live_status((int) $item->id, 'Внешний worker завершает языковые версии и доводит пакет до publish-grade.', 'worker_translate_finish');
						}
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
						$worker_response = self::run_worker_stage($item, $worker_stage, $existing_payload);
						$payload = is_array($worker_response['payload'] ?? null) ? $worker_response['payload'] : [];
						$run_payload['worker_stage'] = $worker_stage;
						$run_payload['worker_duration_ms'] = (int) ($worker_response['duration_ms'] ?? 0);
						self::log_process_item_step('after_worker_stage', (int) $item->id, ['run_id' => $run, 'worker_stage' => $worker_stage, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
					self::log_process_item_step('after_worker_persist', (int) $item->id, ['run_id' => $run, 'worker_stage' => $worker_stage, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					if (! empty($payload['_meta']['translations_deferred'])) {
						if (! self::de_master_ready_for_translation($payload)) {
							throw new RuntimeException('AI rewrite did not reach minimum DE master quality');
						}
						$stage_result = sanitize_key((string) ($worker_response['stage_result'] ?? ''));
						$translation_stage = '';
						if ($stage_result === 'worker_translate_uk') {
							$translation_stage = 'translate_en';
						} elseif ($stage_result === 'worker_translate_en') {
							$translation_stage = 'publish_finish';
						}
						if ($translation_stage === '') {
							$translation_stage = self::payload_next_required_stage($payload);
						}
						if ($stage_result === 'worker_de_master_ready' && $translation_stage === '') {
							$translation_stage = 'translate_uk';
						} elseif ($translation_stage === '') {
							$translation_stage = 'translate_finish';
						}
						self::queue_required_stage((int) $item->id, $payload, $translation_stage, $analysis, $gate);
						self::log_process_item_step('after_worker_queue_translate_finish', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at)]);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'queued_' . $translation_stage . '_stage';
						break;
					}
						if (self::payload_requires_retry_after_ai($payload, $gate)) {
							throw new RuntimeException('AI rewrite fell back to heuristic payload');
						}
						$next_stage = self::payload_next_required_stage($payload);
						self::log_process_item_step('after_worker_next_stage', (int) $item->id, ['run_id' => $run, 'next_stage' => $next_stage, 'duration_ms' => self::duration_ms_since($item_started_at)]);
						if ($next_stage !== '') {
							self::queue_required_stage((int) $item->id, $payload, $next_stage, $analysis, $gate);
							self::log_process_item_step('after_worker_queue_required_stage', (int) $item->id, ['run_id' => $run, 'next_stage' => $next_stage, 'duration_ms' => self::duration_ms_since($item_started_at)]);
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'queued_' . $next_stage . '_stage';
							break;
						}
						$next_state = self::next_state_after_processing($payload);
						self::log_process_item_step('after_worker_next_state', (int) $item->id, ['run_id' => $run, 'next_state' => $next_state, 'duration_ms' => self::duration_ms_since($item_started_at)]);
						EPV2_Queue::mark_state((int) $item->id, $next_state, [
							'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
							'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
							'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
							'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
							'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
							'error_message' => '',
						]);
						self::log_process_item_step('after_worker_mark_state', (int) $item->id, ['run_id' => $run, 'next_state' => $next_state, 'duration_ms' => self::duration_ms_since($item_started_at)]);
						if (! empty($payload['_meta']['tokens'])) {
							EPV2_Stats::bump('ai_tokens', (int) $payload['_meta']['tokens']);
						}
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = (string) ($worker_response['stage_result'] ?? 'worker_translation_stage_success');
						break;
					}
					if (self::payload_needs_enrichment_rebuild($existing_payload)) {
						$run_payload['branch'] = 'translate_finish_rebuild';
						$categories = EPV2_Review::normalize_categories(self::working_category_seed($item, $existing_payload));
						$style = (string) ($existing_payload['_meta']['style'] ?? EPV2_Settings::get('rewrite_style', 'lively'));
						EPV2_Queue::mark_state((int) $item->id, 'processing_de', [
							'error_message' => '',
						]);
						EPV2_Queue::set_live_status((int) $item->id, 'Пересобираю немецкую master-версию, добираю источники и только потом снова запускаю переводы.', 'enrichment_rebuild');
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
						$payload = self::generate_review_payload(
							$item,
							$categories,
							$style,
							true,
							['force_supporting' => true, 'target_supporting' => 2, 'max_runtime_seconds' => 5]
						);
						$payload = self::finalize_payload_for_queue($payload);
						self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
						if (! self::de_master_is_viable($payload)) {
							throw new RuntimeException('AI rewrite did not reach minimum DE master quality');
						}
						self::queue_next_processing_stage((int) $item->id, $payload, 'translate_finish', 'Собираю украинскую и английскую версии из финальной немецкой master-версии.', 'translating_variants', $analysis, $gate);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'requeued_translation_finish_after_rebuild';
						break;
					}
					$auto_finish = true;
					$run_payload['branch'] = 'translate_finish_finalize';
					EPV2_Queue::mark_state((int) $item->id, 'processing_de', [
						'error_message' => '',
					]);
					EPV2_Queue::set_live_status((int) $item->id, 'Собираю украинскую и английскую версии из финальной немецкой master-версии.', 'translating_variants');
					EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
					$payload = self::repair_payload_languages($existing_payload);
					$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
					$payload['_meta']['translations_deferred'] = false;
					$payload = self::set_payload_pipeline_stage($payload, 'translate_finish');
					$payload = self::finalize_payload_for_queue($payload);
					self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
					if (! self::languages_look_publishable($payload)) {
						throw new RuntimeException('Language translation repair failed');
					}
					EPV2_Queue::set_live_status((int) $item->id, 'Дотягиваю SEO, мета-описания, языковые хвосты и media до publish-grade.', 'publish_finish');
					EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
					$payload = self::attempt_publish_grade_lift($item, $payload, EPV2_Review::normalize_categories(self::working_category_seed($item, $payload)), (string) ($payload['_meta']['style'] ?? EPV2_Settings::get('rewrite_style', 'lively')));
					$payload = self::set_payload_pipeline_stage($payload, '');
					$payload = self::finalize_payload_for_queue($payload);
					self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
					$next_stage = self::payload_next_required_stage($payload);
					if ($next_stage !== '') {
						self::queue_required_stage((int) $item->id, $payload, $next_stage, $analysis, $gate);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'queued_' . $next_stage . '_stage';
						break;
					}
					$next_state = self::next_state_after_processing($payload);
					EPV2_Queue::mark_state((int) $item->id, $next_state, [
						'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
						'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
						'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
						'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
						'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
						'error_message' => '',
					]);
					if (! empty($payload['_meta']['tokens'])) {
						EPV2_Stats::bump('ai_tokens', (int) $payload['_meta']['tokens']);
					}
					$count++;
					$run_payload['processed_item_id'] = (int) $item->id;
					$run_payload['result'] = 'translated_and_finished_successfully';
					break;
				}
				if ($existing_payload !== [] && ! $repairing_media_blocker && ! $requires_translation_finish && ! $auto_rework && ! $auto_finish) {
					if (self::payload_is_publish_ready($existing_payload)) {
						EPV2_Queue::mark_state((int) $item->id, 'ready_publish', [
							'error_message' => '',
						]);
						$count++;
						$run_payload['result'] = 'existing_publish_ready_payload';
						break;
					}
					if (! self::automation_requires_publish_grade() && self::payload_is_review_ready($existing_payload)) {
						EPV2_Queue::mark_state((int) $item->id, self::next_state_after_processing($existing_payload), [
							'error_message' => '',
						]);
						$count++;
						$run_payload['result'] = 'existing_review_ready_payload';
						break;
					}
				}
				EPV2_Queue::mark_state((int) $item->id, 'processing_de', [
					'error_message' => '',
				]);
				self::log_process_item_step('after_mark_processing_de', (int) $item->id, [
					'run_id' => $run,
					'duration_ms' => self::duration_ms_since($item_started_at),
					'branch' => (string) ($run_payload['branch'] ?? ''),
				]);
				$run_payload['branch'] = $auto_rework
					? 'auto_rework'
					: ($auto_finish ? 'auto_finish' : 'generate_review_payload');
				if ($auto_rework) {
					self::bump_review_rebuild_attempt((int) $item->id, $existing_payload);
					EPV2_Queue::set_live_status((int) $item->id, 'Дособираю источники, цитаты и фото для углублённой автодоработки.', 'enrichment_rebuild');
				} elseif ($auto_finish) {
					self::bump_review_finish_attempt((int) $item->id, $existing_payload);
					EPV2_Queue::set_live_status((int) $item->id, 'Дотягиваю SEO, мета-описания, языковые хвосты и media до publish-grade.', 'publish_finish');
				} else {
					EPV2_Queue::set_live_status((int) $item->id, 'Переписываю текст, добираю контекст, цитаты и теги.', 'rewriting');
				}
				$stored_selection = is_array($existing_payload['_meta']['selection'] ?? null) ? $existing_payload['_meta']['selection'] : [];
				$reused_existing_context = $existing_payload !== [];
				if ($reused_existing_context) {
					$analysis = $stored_selection !== [] ? $stored_selection : [
						'score' => 0,
						'decision' => 'resume_existing_payload',
						'category' => self::working_category_seed($item, $existing_payload),
					];
				} else {
					$analysis = EPV2_Budget_Manager::analyze_item([
						'title' => (string) $item->original_title,
						'content' => (string) $item->original_content,
						'excerpt' => (string) $item->original_excerpt,
						'url' => (string) $item->original_url,
						'date' => (string) $item->original_date,
						'image' => (string) $item->source_image_url,
						'category' => (string) $item->category_proposed,
					]);
				}
				self::log_process_item_step('after_analysis', (int) $item->id, [
					'run_id' => $run,
					'duration_ms' => self::duration_ms_since($item_started_at),
					'analysis_score' => (int) ($analysis['score'] ?? 0),
					'analysis_decision' => (string) ($analysis['decision'] ?? ''),
					'analysis_category' => (string) ($analysis['category'] ?? ''),
					'reused_selection' => ($reused_existing_context && $stored_selection !== []) ? 1 : 0,
				]);
				if (! $reused_existing_context && self::should_reject_inside_queue($item, $analysis)) {
					EPV2_Queue::mark_state((int) $item->id, 'rejected', [
						'error_message' => 'Материал отсеян уже внутри очереди как слабый или шумовой кандидат.',
						'admin_notes' => wp_json_encode(['selection' => $analysis], JSON_UNESCAPED_UNICODE),
					]);
					$run_payload['result'] = 'rejected_inside_queue';
					continue;
				}
				if ($reused_existing_context) {
					$gate = [
						'allow' => true,
						'mode' => $auto_rework ? 'ai_rebuild_enrichment' : ($auto_finish ? 'ai_publish_finish_resume' : 'resume_existing_payload'),
						'reason' => 'existing payload resumes from saved context',
					];
				} else {
					$gate = EPV2_Budget_Manager::should_send_to_ai($analysis);
				}
				self::log_process_item_step('after_gate', (int) $item->id, [
					'run_id' => $run,
					'duration_ms' => self::duration_ms_since($item_started_at),
					'gate_allow' => ! empty($gate['allow']) ? 1 : 0,
					'gate_mode' => (string) ($gate['mode'] ?? ''),
				]);
				if ($reused_existing_context) {
					$category = self::working_category_seed($item, $existing_payload);
				} else {
					$category = EPV2_Categorizer::detect((string) $item->original_title, (string) $item->original_content, (string) $item->category_proposed);
				}
				self::log_process_item_step('after_category_detect', (int) $item->id, [
					'run_id' => $run,
					'duration_ms' => self::duration_ms_since($item_started_at),
					'category' => $category,
					'reused_category' => $reused_existing_context ? 1 : 0,
				]);
				if ($existing_payload === []) {
					self::log_process_item_step('before_baseline_payload', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					self::log_process_item_step('before_baseline_dossier', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					$baseline_dossier = EPV2_Source_Enricher::enrich_item($item, ['fast_mode' => true]);
					self::log_process_item_step('after_baseline_dossier', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					$baseline_payload = EPV2_Review::build_payload_without_ai_from_dossier(
						$item,
						EPV2_Review::normalize_categories($category),
						(string) EPV2_Settings::get('rewrite_style', 'lively'),
						$baseline_dossier,
						true
					);
					self::log_process_item_step('after_baseline_payload', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					$baseline_payload['_meta'] = is_array($baseline_payload['_meta'] ?? null) ? $baseline_payload['_meta'] : [];
					$baseline_payload['_meta']['selection'] = $analysis;
					$baseline_payload['_meta']['breaking_watch'] = ! empty($analysis['breaking_watch']);
					$baseline_payload['_meta']['breaking'] = ! empty($analysis['breaking_candidate']);
					$baseline_payload['_meta']['top_story'] = ! empty($analysis['top_story_candidate']);
					$baseline_payload['_meta']['story_format'] = sanitize_text_field((string) ($item->story_format ?? ''));
					$baseline_payload['_meta']['cluster_id'] = (int) ($item->cluster_id ?? 0);
					$baseline_payload['_meta']['topic_label'] = sanitize_text_field((string) ($item->topic_label ?? ''));
					$baseline_payload = self::normalize_payload_quotes($baseline_payload);
					$baseline_payload = self::align_selection_with_payload_category($baseline_payload);
					$baseline_payload['_meta']['context_memory'] = self::payload_context_memory($baseline_payload);
					self::log_process_item_step('before_baseline_persist', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					self::persist_intermediate_payload((int) $item->id, $baseline_payload, $analysis, $gate);
					self::log_process_item_step('after_baseline_persist', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					$existing_payload = $baseline_payload;
				}
				if (! empty($gate['mode']) && $gate['mode'] === 'reject') {
					EPV2_Queue::mark_state((int) $item->id, 'rejected', ['admin_notes' => wp_json_encode(['selection' => $analysis, 'gate' => $gate], JSON_UNESCAPED_UNICODE)]);
					$run_payload['result'] = 'rejected_by_gate';
					continue;
				} else {
					if (self::automation_requires_publish_grade() && empty($gate['allow'])) {
						$gate['allow'] = true;
						$gate['mode'] = 'ai_forced_enrichment';
						$gate['reason'] = 'queued candidate forced through enrichment to reach publish-grade';
					}
					if ($auto_rework) {
						$gate['allow'] = true;
						$gate['mode'] = 'ai_rebuild_enrichment';
						$gate['reason'] = 'ready_review candidate sent to automatic enrichment rebuild';
					}
					$use_worker = self::worker_pipeline_enabled()
						&& (
							$auto_rework
							|| $auto_finish
							|| ! empty($gate['allow'])
							|| ($existing_payload !== [] && self::payload_is_review_ready($existing_payload) && self::automation_requires_publish_grade())
						);
					if ($use_worker) {
						$config = EPV2_Settings::get_ai_config();
						if (! self::provider_chain_is_ready($config, 'review_payload')) {
							throw new RuntimeException('AI provider unavailable: no ready provider in chain');
						}
						$worker_stage = self::worker_stage_for_request($existing_payload, $auto_rework, $auto_finish);
						self::log_process_item_step('before_worker_stage', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at), 'worker_stage' => $worker_stage]);
						if ($worker_stage === 'rebuild_bundle') {
							EPV2_Queue::set_live_status((int) $item->id, 'Внешний worker собирает контекст, добирает источники, медиа и строит финальный DE-first bundle.', 'worker_rebuild');
						} elseif ($worker_stage === 'publish_finish') {
							EPV2_Queue::set_live_status((int) $item->id, 'Внешний worker дотягивает SEO, мета, медиа и финальную publish-ready упаковку.', 'worker_publish_finish');
						} else {
							EPV2_Queue::set_live_status((int) $item->id, 'Внешний worker собирает полный DE-first пакет и затем завершает переводы.', 'worker_rebuild');
						}
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
							$worker_response = self::run_worker_stage($item, $worker_stage, $existing_payload);
							$payload = is_array($worker_response['payload'] ?? null) ? $worker_response['payload'] : [];
							self::log_process_item_step('after_worker_stage', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at), 'worker_stage' => $worker_stage]);
							$run_payload['worker_stage'] = $worker_stage;
							$run_payload['worker_duration_ms'] = (int) ($worker_response['duration_ms'] ?? 0);
							EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
					} elseif (($auto_finish || ! $auto_rework) && $existing_payload !== [] && self::payload_is_review_ready($existing_payload) && self::automation_requires_publish_grade()) {
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
						$payload = self::attempt_publish_grade_lift($item, $existing_payload, EPV2_Review::normalize_categories($category), (string) EPV2_Settings::get('rewrite_style', 'lively'));
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
					} elseif (empty($gate['allow'])) {
						$payload = $existing_payload !== [] ? $existing_payload : EPV2_Review::ensure_payload_without_ai($item, EPV2_Review::normalize_categories($category), (string) EPV2_Settings::get('rewrite_style', 'lively'));
						$payload['_meta']['gate_mode'] = (string) ($gate['mode'] ?? 'review_without_ai');
						$payload['_meta']['gate_reason'] = (string) ($gate['reason'] ?? '');
					} else {
						$config = EPV2_Settings::get_ai_config();
						if (! self::provider_chain_is_ready($config, 'review_payload')) {
							throw new RuntimeException('AI provider unavailable: no ready provider in chain');
						}
						EPV2_Queue::set_live_status((int) $item->id, 'Генерирую финальный пакет для всех языков и проверяю ограничения.', 'generating_payload');
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
						$payload = self::generate_review_payload(
							$item,
							EPV2_Review::normalize_categories($category),
							(string) EPV2_Settings::get('rewrite_style', 'lively'),
							true,
							$auto_rework ? ['force_supporting' => true, 'target_supporting' => 2, 'max_runtime_seconds' => 5] : []
						);
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
					}
					if (self::payload_context_rejects($payload)) {
						$payload = self::refresh_stage_checklist($payload);
						self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
						EPV2_Queue::mark_state((int) $item->id, 'rejected', [
							'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
							'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
							'error_message' => 'Материал отсеян после контекстного анализа текста и dossier.',
						]);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'rejected_by_context';
						break;
					}
					$payload['_meta']['selection'] = $analysis;
					$payload['_meta']['breaking_watch'] = ! empty($analysis['breaking_watch']);
					$payload['_meta']['breaking'] = ! empty($payload['_meta']['breaking']) || ! empty($analysis['breaking_candidate']);
					$payload['_meta']['top_story'] = ! empty($payload['_meta']['top_story']) || ! empty($analysis['top_story_candidate']);
					$payload['_meta']['story_format'] = sanitize_text_field((string) ($item->story_format ?? ''));
					$payload['_meta']['cluster_id'] = (int) ($item->cluster_id ?? 0);
					$payload['_meta']['topic_label'] = sanitize_text_field((string) ($item->topic_label ?? ''));
					$payload = self::finalize_payload_for_queue($payload);
					if (! empty($payload['_meta']['translations_deferred'])) {
						if (! self::de_master_is_viable($payload)) {
							throw new RuntimeException('AI rewrite did not reach minimum DE master quality');
						}
						$translation_stage = self::payload_next_required_stage($payload);
						if ($translation_stage === '') {
							$translation_stage = 'translate_finish';
						}
						self::queue_required_stage((int) $item->id, $payload, $translation_stage, $analysis, $gate);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'queued_' . $translation_stage . '_stage';
						break;
					}
					self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
					if (self::payload_requires_retry_after_ai($payload, $gate)) {
						throw new RuntimeException('AI rewrite fell back to heuristic payload');
					}
					if (self::payload_context_rejects($payload)) {
						$payload = self::refresh_stage_checklist($payload);
						EPV2_Queue::mark_state((int) $item->id, 'rejected', [
							'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
							'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
							'error_message' => 'Материал отсеян после контекстного анализа текста и dossier.',
						]);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'rejected_by_context';
						break;
					}
					$next_stage = self::payload_next_required_stage($payload);
					if ($next_stage !== '') {
						self::queue_required_stage((int) $item->id, $payload, $next_stage, $analysis, $gate);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'queued_' . $next_stage . '_stage';
						break;
					}
					$next_state = self::next_state_after_processing($payload);
					EPV2_Queue::mark_state((int) $item->id, $next_state, [
						'category_final' => implode(',', $payload['categories']),
						'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
						'ai_provider' => ! empty($gate['allow']) ? EPV2_Settings::get('ai_provider', 'gemini') : '',
						'ai_model' => ! empty($gate['allow']) ? EPV2_Settings::get('ai_model', '') : '',
						'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
						'error_message' => '',
						'admin_notes' => wp_json_encode(['selection' => $analysis, 'gate' => $gate], JSON_UNESCAPED_UNICODE),
					]);
					if (! empty($payload['_meta']['tokens'])) {
						EPV2_Stats::bump('ai_tokens', (int) $payload['_meta']['tokens']);
					}
					$count++;
					$run_payload['processed_item_id'] = (int) $item->id;
					$run_payload['result'] = 'processed_successfully';
					break;
				}
			} catch (Throwable $e) {
				$errors++;
				$run_payload['last_error'] = $e->getMessage();
				$run_payload['last_error_class'] = get_class($e);
				if (is_array($payload) && ! empty($payload)) {
					self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
				} elseif (is_array($baseline_payload) && ! empty($baseline_payload)) {
					self::persist_intermediate_payload((int) $item->id, $baseline_payload, $analysis, $gate);
				}
				$fresh_item = EPV2_Queue::get_item((int) $item->id) ?: $item;
				$payload_for_review = is_array($payload) && ! empty($payload)
					? $payload
					: (is_array($baseline_payload) && ! empty($baseline_payload) ? $baseline_payload : $existing_payload);
				if (is_array($payload_for_review) && ! empty($payload_for_review) && self::payload_context_rejects($payload_for_review)) {
					$payload_for_review = self::refresh_stage_checklist($payload_for_review);
					EPV2_Queue::mark_state((int) $item->id, 'rejected', [
						'ai_payload' => wp_json_encode($payload_for_review, JSON_UNESCAPED_UNICODE),
						'error_message' => 'Материал отсеян после контекстного анализа текста и dossier.',
					]);
					$run_payload['result'] = 'rejected_by_context';
					break;
				}
				if (
					is_array($payload_for_review)
					&& ! empty($payload_for_review)
					&& self::payload_is_review_worthy($payload_for_review)
					&& self::is_reworkable_process_error($e->getMessage())
					&& self::payload_pipeline_stage($payload_for_review) === ''
				) {
					EPV2_Queue::update_fields((int) $item->id, [
						'ai_payload' => wp_json_encode($payload_for_review, JSON_UNESCAPED_UNICODE),
					]);
					EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'process', $e->getMessage());
					$run_payload['result'] = 'requeued_after_process_error';
					break;
				}
				if (
					$auto_rework
					&& self::is_reworkable_process_error($e->getMessage())
					&& self::review_rebuild_exhausted($fresh_item)
				) {
					if (is_array($payload_for_review) && ! empty($payload_for_review)) {
						EPV2_Queue::update_fields((int) $item->id, [
							'ai_payload' => wp_json_encode($payload_for_review, JSON_UNESCAPED_UNICODE),
						]);
					}
					EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'process', 'AI rewrite did not reach publish threshold');
					$run_payload['result'] = 'auto_rework_exhausted_requeued';
					break;
				}
				if (
					$auto_finish
					&& self::is_reworkable_process_error($e->getMessage())
					&& self::review_finish_exhausted($fresh_item)
				) {
					if (is_array($payload_for_review) && ! empty($payload_for_review)) {
						EPV2_Queue::update_fields((int) $item->id, [
							'ai_payload' => wp_json_encode($payload_for_review, JSON_UNESCAPED_UNICODE),
						]);
					}
					EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'process', 'AI rewrite did not reach publish threshold');
					$run_payload['result'] = 'auto_finish_exhausted_requeued';
					break;
				}
				if (self::should_reject_after_process_failure($item, $e->getMessage())) {
					EPV2_Queue::mark_state((int) $item->id, 'rejected', [
						'error_message' => $e->getMessage(),
					]);
				} elseif (self::is_reworkable_process_error($e->getMessage())) {
					EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'process', $e->getMessage());
				} else {
					EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'process', $e->getMessage());
				}
				$run_payload['result'] = 'item_failed';
				break;
			} finally {
				$fresh_item = EPV2_Queue::get_item((int) $item->id);
				$fresh_notes = $fresh_item ? json_decode((string) ($fresh_item->admin_notes ?? ''), true) : [];
				$fresh_notes = is_array($fresh_notes) ? $fresh_notes : [];
				$run_payload['item_duration_ms'] = (int) round((microtime(true) - $item_started_at) * 1000);
				$run_payload['live_status_code'] = sanitize_key((string) ($fresh_notes['_system']['live_status_code'] ?? ''));
				if ($fresh_item) {
					$fresh_payload = json_decode((string) ($fresh_item->ai_payload ?? ''), true);
					$fresh_payload = is_array($fresh_payload) ? $fresh_payload : [];
					$run_payload['pipeline_stage_after'] = self::payload_pipeline_stage($fresh_payload);
					$run_payload['final_item_state'] = sanitize_key((string) ($fresh_item->state ?? ''));
				}
			}
		}

			EPV2_Stats::bump('rewritten', $count);
			$run_payload['duration_ms'] = (int) round((microtime(true) - $started_at) * 1000);
			EPV2_Runs::finish($run, $errors ? 'finished_with_errors' : 'finished', $count, $errors, $run_payload);
			if (EPV2_Queue::has_processable_items() && ! EPV2_Lock_Manager::is_active('process')) {
				EPV2_Jobs::enqueue_process();
			}
			} finally {
			EPV2_Lock_Manager::release('process', $lock);
		}
	}

	private static function persist_intermediate_payload(int $item_id, array $payload, array $analysis = [], array $gate = []): void {
		if ($item_id <= 0 || $payload === []) {
			return;
		}
		$payload = self::refresh_stage_checklist($payload);
		$categories = array_values(array_filter(array_map('sanitize_text_field', (array) ($payload['categories'] ?? []))));
		$fields = [
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
		];
		if ($categories !== []) {
			$fields['category_final'] = implode(',', $categories);
		}

		$current = EPV2_Queue::get_item($item_id);
		$notes = [];
		if ($current) {
			$notes = json_decode((string) ($current->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
		}
		if ($analysis !== []) {
			$notes['selection'] = $analysis;
		}
		if ($gate !== []) {
			$notes['gate'] = $gate;
		}
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['context_memory'] = self::payload_context_memory($payload);
		$fields['admin_notes'] = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
		EPV2_Queue::update_fields($item_id, $fields);
	}

	private static function queue_next_processing_stage(int $item_id, array $payload, string $stage, string $status, string $status_code, array $analysis = [], array $gate = []): void {
		$payload = self::set_payload_pipeline_stage($payload, $stage);
		$payload = self::refresh_stage_checklist($payload);
		$notes = [];
		$item = EPV2_Queue::get_item($item_id);
		if ($item) {
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
		}
		if ($analysis !== []) {
			$notes['selection'] = $analysis;
		}
		if ($gate !== []) {
			$notes['gate'] = $gate;
		}
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['context_memory'] = self::payload_context_memory($payload);
		unset($notes['_system']['retry_after']);
		EPV2_Queue::mark_state($item_id, 'retry_process', [
			'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			'error_message' => '',
		]);
		EPV2_Queue::set_live_status($item_id, $status, $status_code);
	}

	private static function queue_required_stage(int $item_id, array $payload, string $stage, array $analysis = [], array $gate = []): void {
		$definition = self::stage_status_definition($stage);
		if ($definition === []) {
			return;
		}
		self::queue_next_processing_stage($item_id, $payload, $stage, $definition['status'], $definition['code'], $analysis, $gate);
	}

	private static function stage_status_definition(string $stage): array {
		switch ($stage) {
			case 'rebuild_bundle':
				return [
					'status' => 'Дособираю контекст, источники, рерайт и медиа для немецкой master-версии.',
					'code'   => 'enrichment_rebuild',
				];
			case 'translate_uk':
				return [
					'status' => 'Собираю украинскую версию из финальной немецкой master-версии.',
					'code'   => 'translating_uk',
				];
			case 'translate_en':
				return [
					'status' => 'Собираю английскую версию из финальной немецкой master-версии.',
					'code'   => 'translating_en',
				];
			case 'translate_finish':
				return [
					'status' => 'Последовательно завершаю языковые версии из финальной немецкой master-версии.',
					'code'   => 'translating_variants',
				];
			case 'publish_finish':
				return [
					'status' => 'Дотягиваю SEO, media и финальные мета-поля до publish-grade.',
					'code'   => 'publish_finish',
				];
			default:
				return [];
		}
	}

	private static function worker_pipeline_enabled(): bool {
		return class_exists('EPV2_Worker_Client') && EPV2_Worker_Client::enabled();
	}

	private static function worker_stage_for_request(array $existing_payload, bool $auto_rework, bool $auto_finish): string {
		$next_stage = self::payload_next_required_stage($existing_payload);
		if ($next_stage !== '') {
			return $next_stage;
		}
		$checklist = self::payload_stage_checklist($existing_payload);
		if ($existing_payload !== [] && ! empty($checklist['de_master_ready']) && empty($checklist['translations_ready'])) {
			return 'translate_finish';
		}
		if ($existing_payload !== [] && ! empty($checklist['translations_ready']) && empty($checklist['ready_publish'])) {
			return 'publish_finish';
		}
		if ($existing_payload !== [] && empty($checklist['de_master_ready'])) {
			return 'rebuild_bundle';
		}
		if ($existing_payload !== [] && self::payload_stage_requires_translation_finish($existing_payload)) {
			return self::payload_needs_enrichment_rebuild($existing_payload) ? 'rebuild_bundle' : 'translate_finish';
		}
		if ($auto_rework) {
			return 'rebuild_bundle';
		}
		if ($auto_finish) {
			return 'publish_finish';
		}
		if ($existing_payload !== [] && self::payload_is_review_ready($existing_payload) && self::automation_requires_publish_grade()) {
			return 'publish_finish';
		}
		return 'rebuild_bundle';
	}

	private static function run_worker_stage(object $item, string $stage, array $existing_payload = []): array {
			$started_at = microtime(true);
			$response = EPV2_Worker_Client::run_for_item($item, $stage, $existing_payload);
			$payload = is_array($response['payload'] ?? null) ? $response['payload'] : [];
			if ($payload === []) {
				throw new RuntimeException('External worker returned empty payload');
			}
			// External worker already returns a normalized payload for the requested
			// stage. Re-running heavy finalization here reintroduces the same long
			// blocking path we are trying to remove from the parent process.
			$response['payload'] = self::refresh_stage_checklist($payload);
			$response['duration_ms'] = (int) round((microtime(true) - $started_at) * 1000);
			return $response;
		}

	private static function log_process_entry_step(string $step, array $context = []): void {
		$context['step'] = $step;
		EPV2_Logger::info('process_entry', 'process_scheduled step', $context);
	}

	private static function log_process_item_step(string $step, ?int $queue_id, array $context = []): void {
		if ($queue_id !== null && $queue_id > 0) {
			$context['queue_id'] = $queue_id;
		}
		$context['step'] = $step;
		EPV2_Logger::info('process_item', 'process item step', $context);
	}

	public static function regenerate_for_field($item, string $lang, string $field, string $style = 'strict'): array {
		if (! is_object($item)) {
			return [];
		}
		$current_categories = EPV2_Review::normalize_categories(self::working_category_seed($item, $payload));
		$payload = EPV2_Review::ensure_payload($item);
		$payload['_meta']['style'] = $style;
		$payload['languages'][$lang] = $payload['languages'][$lang] ?? EPV2_Review::build_language_package($item, $lang, $style);
		if ($field === 'media_url') {
			$payload['languages'][$lang]['media_url'] = (string) ($item->source_image_url ?? '');
			return $payload;
		}

		$fresh = EPV2_Review::build_language_package($item, $lang, $style);
		$fallback_value = (string) ($fresh[$field] ?? '');
		$generated = self::generate_single_field($item, $payload, $lang, $field, $style, $current_categories);
		if ($generated === '') {
			$generated = $fallback_value;
		}
		if ($generated !== '') {
			$payload['languages'][$lang][$field] = $generated;
		}
		return $payload;
	}

	public static function generate_review_payload(object $item, array $categories, string $style = 'strict', bool $defer_translations = false, array $options = []): array {
		$trace_started_at = microtime(true);
		$config = self::review_payload_primary_config(EPV2_Settings::get_ai_config());
		$enrichment_options = [
			'fast_mode' => empty($options['force_supporting']),
		];
		if (! empty($options['context_memory']) && is_array($options['context_memory'])) {
			$enrichment_options['context_memory'] = $options['context_memory'];
		}
		if (! empty($options['force_supporting'])) {
			$enrichment_options['force_supporting'] = true;
			$enrichment_options['target_supporting'] = max(1, min(3, (int) ($options['target_supporting'] ?? 2)));
			$enrichment_options['max_runtime_seconds'] = max(3, min(8, (int) ($options['max_runtime_seconds'] ?? 5)));
		}
		$enrich_started_at = microtime(true);
		$dossier = EPV2_Source_Enricher::enrich_item($item, $enrichment_options);
		self::log_generate_review_payload_step('source_enriched', $item, [
			'duration_ms' => self::duration_ms_since($enrich_started_at),
			'supporting_count' => count((array) ($dossier['supporting'] ?? [])),
		]);
		$categorize_started_at = microtime(true);
		$detected_primary = EPV2_Categorizer::detect(
			(string) ($dossier['primary']['title'] ?? $item->original_title ?? ''),
			(string) ($dossier['primary']['content'] ?? $item->original_content ?? ''),
			(string) ($categories[0] ?? '')
		);
		$refined_primary = EPV2_Categorizer::refine_with_event_context(
			$detected_primary !== '' ? $detected_primary : (string) ($categories[0] ?? ''),
			$dossier,
			(string) ($dossier['primary']['title'] ?? $item->original_title ?? ''),
			(string) ($dossier['primary']['content'] ?? $item->original_content ?? '')
		);
		if ($refined_primary !== '') {
			$categories = EPV2_Review::normalize_categories($refined_primary);
		}
		$fallback_started_at = microtime(true);
		$fallback = EPV2_Review::build_payload_without_ai_from_dossier($item, $categories, $style, $dossier, true);
		self::log_generate_review_payload_step('fallback_ready', $item, ['duration_ms' => self::duration_ms_since($trace_started_at)]);
		self::log_generate_review_payload_step('category_refined', $item, [
			'duration_ms' => self::duration_ms_since($categorize_started_at),
			'category' => (string) ($categories[0] ?? ''),
		]);
		self::log_generate_review_payload_step('fallback_built_lightweight', $item, [
			'duration_ms' => self::duration_ms_since($fallback_started_at),
		]);
		$context_analysis = EPV2_Budget_Manager::analyze_contextual([
			'title' => (string) ($item->original_title ?? ''),
			'excerpt' => (string) ($item->original_excerpt ?? ''),
			'content' => (string) ($item->original_content ?? ''),
			'url' => (string) ($item->original_url ?? ''),
			'date' => (string) ($item->original_date ?? ''),
			'image' => (string) ($item->source_image_url ?? ''),
			'category' => (string) ($categories[0] ?? ''),
		], $dossier, [
			'category' => (string) ($categories[0] ?? ''),
		]);
		$fallback['_meta'] = is_array($fallback['_meta'] ?? null) ? $fallback['_meta'] : [];
		$fallback['_meta']['context_analysis'] = $context_analysis;
		if (! empty($context_analysis['category'])) {
			$fallback['categories'] = EPV2_Review::normalize_categories((string) $context_analysis['category']);
			$categories = $fallback['categories'];
		}
		self::log_generate_review_payload_step('context_analysis_ready', $item, [
			'duration_ms' => self::duration_ms_since($trace_started_at),
			'decision' => (string) ($context_analysis['decision'] ?? ''),
			'score' => (int) ($context_analysis['score'] ?? 0),
			'category' => (string) ($context_analysis['category'] ?? ''),
		]);
		$fallback['_meta']['source_dossier'] = $dossier;
		$fallback['_meta']['source_count'] = 1 + count((array) ($dossier['supporting'] ?? []));
		if (self::context_analysis_requires_terminal_reject($context_analysis)) {
			$fallback['_meta']['gate_mode'] = 'context_reject';
			$fallback['_meta']['gate_reason'] = 'context analysis rejected item before AI rewrite';
			$fallback['_meta']['context_memory'] = self::payload_context_memory($fallback);
			self::log_generate_review_payload_step('return_context_reject_fallback', $item, ['duration_ms' => self::duration_ms_since($trace_started_at)]);
			return $fallback;
		}
		if (empty($config['api_key'])) {
			$fallback = EPV2_AI_Response_Validator::enrich_payload($fallback);
			$fallback['_meta']['quality'] = EPV2_AI_Response_Validator::editorial_quality($fallback);
			$fallback['_meta']['seo_quality'] = EPV2_AI_Response_Validator::seo_quality($fallback);
			$fallback['_meta']['release_quality'] = EPV2_AI_Response_Validator::release_quality($fallback);
			$fallback['_meta']['google_quality'] = EPV2_AI_Response_Validator::google_preflight_quality($fallback);
			self::log_generate_review_payload_step('no_api_key_fallback', $item, ['duration_ms' => self::duration_ms_since($trace_started_at)]);
			return $fallback;
		}

		try {
			$attempt_started_at = microtime(true);
			$parsed = self::attempt_ai_payload($config, $item, $categories, $style, $dossier, $defer_translations);
			self::log_generate_review_payload_step('primary_ai_attempt', $item, [
				'duration_ms' => self::duration_ms_since($attempt_started_at),
				'parsed' => $parsed !== null ? 1 : 0,
				'provider' => (string) ($config['provider'] ?? ''),
			]);
			if ($parsed !== null) {
				$parsed['_meta'] = is_array($parsed['_meta'] ?? null) ? $parsed['_meta'] : [];
				$parsed['_meta']['context_analysis'] = $context_analysis;
				if (! empty($context_analysis['category'])) {
					$parsed['categories'] = EPV2_Review::normalize_categories((string) $context_analysis['category'] . ',' . implode(',', (array) ($parsed['categories'] ?? [])));
				}
				self::log_generate_review_payload_step('return_primary_ai', $item, ['duration_ms' => self::duration_ms_since($trace_started_at)]);
				return $parsed;
			}

			if (($config['provider'] ?? '') === 'gemini' && (! empty($config['gemini_search_grounding_enabled']) || ! empty($config['gemini_url_context_enabled']))) {
				$retry = $config;
				$retry['gemini_search_grounding_enabled'] = false;
				$retry['gemini_url_context_enabled'] = false;
				$gemini_retry_started_at = microtime(true);
				$parsed = self::attempt_ai_payload($retry, $item, $categories, $style, $dossier, $defer_translations);
				self::log_generate_review_payload_step('gemini_retry_attempt', $item, [
					'duration_ms' => self::duration_ms_since($gemini_retry_started_at),
					'parsed' => $parsed !== null ? 1 : 0,
				]);
				if ($parsed !== null) {
					$parsed['_meta'] = is_array($parsed['_meta'] ?? null) ? $parsed['_meta'] : [];
					$parsed['_meta']['fallback_mode'] = 'gemini_without_tools';
					$parsed['_meta']['context_analysis'] = $context_analysis;
					if (! empty($context_analysis['category'])) {
						$parsed['categories'] = EPV2_Review::normalize_categories((string) $context_analysis['category'] . ',' . implode(',', (array) ($parsed['categories'] ?? [])));
					}
					self::log_generate_review_payload_step('return_gemini_retry', $item, ['duration_ms' => self::duration_ms_since($trace_started_at)]);
					return $parsed;
				}
			}

			$fallback_config = self::fallback_provider_config($config);
			if (! empty($fallback_config['api_key'])) {
				$fallback_attempt_started_at = microtime(true);
				$parsed = self::attempt_ai_payload($fallback_config, $item, $categories, $style, $dossier, $defer_translations);
				self::log_generate_review_payload_step('fallback_provider_attempt', $item, [
					'duration_ms' => self::duration_ms_since($fallback_attempt_started_at),
					'parsed' => $parsed !== null ? 1 : 0,
					'provider' => (string) ($fallback_config['provider'] ?? ''),
				]);
				if ($parsed !== null) {
					$parsed['_meta'] = is_array($parsed['_meta'] ?? null) ? $parsed['_meta'] : [];
					$parsed['_meta']['fallback_provider_used'] = (string) ($fallback_config['provider'] ?? '');
					$parsed['_meta']['context_analysis'] = $context_analysis;
					if (! empty($context_analysis['category'])) {
						$parsed['categories'] = EPV2_Review::normalize_categories((string) $context_analysis['category'] . ',' . implode(',', (array) ($parsed['categories'] ?? [])));
					}
					self::log_generate_review_payload_step('return_fallback_provider', $item, ['duration_ms' => self::duration_ms_since($trace_started_at)]);
					return $parsed;
				}
			}

			$fallback = EPV2_AI_Response_Validator::enrich_payload($fallback);
			$fallback['_meta']['quality'] = EPV2_AI_Response_Validator::editorial_quality($fallback);
			$fallback['_meta']['seo_quality'] = EPV2_AI_Response_Validator::seo_quality($fallback);
			$fallback['_meta']['release_quality'] = EPV2_AI_Response_Validator::release_quality($fallback);
			$fallback['_meta']['google_quality'] = EPV2_AI_Response_Validator::google_preflight_quality($fallback);
			self::log_generate_review_payload_step('return_heuristic_fallback', $item, ['duration_ms' => self::duration_ms_since($trace_started_at)]);
			return $fallback;
		} catch (Throwable $e) {
			self::log_generate_review_payload_step('primary_exception', $item, [
				'duration_ms' => self::duration_ms_since($trace_started_at),
				'error' => $e->getMessage(),
			]);
			$fallback_config = self::fallback_provider_config($config);
			if (! empty($fallback_config['api_key'])) {
				try {
					$fallback_attempt_started_at = microtime(true);
					$parsed = self::attempt_ai_payload($fallback_config, $item, $categories, $style, $dossier, $defer_translations);
					self::log_generate_review_payload_step('fallback_after_exception_attempt', $item, [
						'duration_ms' => self::duration_ms_since($fallback_attempt_started_at),
						'parsed' => $parsed !== null ? 1 : 0,
						'provider' => (string) ($fallback_config['provider'] ?? ''),
					]);
					if ($parsed !== null) {
						$parsed['_meta'] = is_array($parsed['_meta'] ?? null) ? $parsed['_meta'] : [];
						$parsed['_meta']['fallback_provider_used'] = (string) ($fallback_config['provider'] ?? '');
						$parsed['_meta']['context_analysis'] = $context_analysis;
						if (! empty($context_analysis['category'])) {
							$parsed['categories'] = EPV2_Review::normalize_categories((string) $context_analysis['category'] . ',' . implode(',', (array) ($parsed['categories'] ?? [])));
						}
						self::log_generate_review_payload_step('return_fallback_after_exception', $item, ['duration_ms' => self::duration_ms_since($trace_started_at)]);
						return $parsed;
					}
				} catch (Throwable $fallback_error) {
					EPV2_Logger::warning('ai', 'Fallback provider failed', ['error' => $fallback_error->getMessage()]);
				}
			}
			EPV2_Logger::warning('ai', 'Fallback to heuristic review payload', ['error' => $e->getMessage()]);
			$fallback = EPV2_AI_Response_Validator::enrich_payload($fallback);
			$fallback['_meta']['quality'] = EPV2_AI_Response_Validator::editorial_quality($fallback);
			$fallback['_meta']['seo_quality'] = EPV2_AI_Response_Validator::seo_quality($fallback);
			$fallback['_meta']['release_quality'] = EPV2_AI_Response_Validator::release_quality($fallback);
			$fallback['_meta']['google_quality'] = EPV2_AI_Response_Validator::google_preflight_quality($fallback);
			self::log_generate_review_payload_step('return_exception_fallback', $item, ['duration_ms' => self::duration_ms_since($trace_started_at)]);
			return $fallback;
		}
	}

	private static function duration_ms_since(float $started_at): int {
		return (int) round((microtime(true) - $started_at) * 1000);
	}

	private static function log_generate_review_payload_step(string $step, object $item, array $context = []): void {
		if (! class_exists('EPV2_Logger')) {
			return;
		}
		$context['queue_id'] = (int) ($item->id ?? 0);
		$context['step'] = $step;
		EPV2_Logger::info('ai_generate_payload', 'generate_review_payload step', $context);
	}

	public static function repair_payload_languages(array $payload): array {
		$config = EPV2_Settings::get_ai_config();
		if (empty($config['api_key']) || ! is_array($payload['languages']['de'] ?? null)) {
			return $payload;
		}
		try {
			$repaired = self::repair_language_variants($payload, $config);
			return EPV2_AI_Response_Validator::enrich_payload($repaired);
		} catch (Throwable $e) {
			EPV2_Logger::warning('ai', 'Payload language repair failed', ['error' => $e->getMessage()]);
			return $payload;
		}
	}

	public static function repair_payload_language(array $payload, string $lang): array {
		$lang = in_array($lang, ['uk', 'en'], true) ? $lang : '';
		$config = EPV2_Settings::get_ai_config();
		if ($lang === '' || empty($config['api_key']) || ! is_array($payload['languages']['de'] ?? null)) {
			return $payload;
		}
		try {
			$base = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
			$current = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
			if ($base === [] || ($current !== [] && ! self::needs_language_repair($base, $current, $lang))) {
				return $payload;
			}
			EPV2_Logger::info('ai', 'Single language repair start', ['lang' => $lang, 'content_length' => mb_strlen(trim(wp_strip_all_tags((string) ($base['content'] ?? ''))))]);
			$fixed = self::translate_language_package_bounded($base, $lang, $config);
			if (! is_array($fixed)) {
				EPV2_Logger::warning('ai', 'Bounded language translation returned empty package, falling back to full translation pipeline', ['lang' => $lang]);
				$fixed = self::translate_language_package($base, $lang, $config);
			}
			EPV2_Logger::info('ai', 'Single language repair translated', ['lang' => $lang, 'translated' => is_array($fixed) ? 1 : 0]);
			if (is_array($fixed)) {
				$payload['languages'][$lang] = array_merge($current, $fixed, [
					'media_url' => (string) ($current['media_url'] ?? $payload['media_url'] ?? ''),
				]);
				unset(
					$payload['languages'][$lang]['seo_title'],
					$payload['languages'][$lang]['meta_description'],
					$payload['languages'][$lang]['slug'],
					$payload['languages'][$lang]['focus_keywords'],
					$payload['languages'][$lang]['tags']
				);
				if (self::needs_language_repair($base, (array) ($payload['languages'][$lang] ?? []), $lang)) {
					EPV2_Logger::warning('ai', 'Translated package still fails language validation after merge', ['lang' => $lang]);
					return $payload;
				}
			}
			$payload = self::ensure_quote_blocks_in_translations($payload, $config);
			EPV2_Logger::info('ai', 'Single language repair finish', ['lang' => $lang]);
			return $payload;
		} catch (Throwable $e) {
			EPV2_Logger::warning('ai', 'Single payload language repair failed', ['lang' => $lang, 'error' => $e->getMessage()]);
			return $payload;
		}
	}

	private static function translate_language_package_bounded(array $base, string $lang, array $config): ?array {
		$localeLabel = $lang === 'uk' ? 'украинский' : 'английский';
		$contentLength = mb_strlen(trim(wp_strip_all_tags((string) ($base['content'] ?? ''))));
		$compact = $contentLength >= 2400;
		foreach (self::translation_provider_chain($config) as $candidate) {
			if (empty($candidate['api_key'])) {
				continue;
			}
			$request = $candidate;
			$request['gemini_search_grounding_enabled'] = false;
			$request['gemini_url_context_enabled'] = false;
			$request['timeout'] = max(16, min(28, self::translation_timeout_for_content($request, $contentLength, $compact)));
			$request['max_tokens'] = min($compact ? 1200 : 1500, (int) ($request['max_tokens'] ?? 1500));
			try {
				EPV2_Logger::info('ai', 'Bounded language translation attempt', ['lang' => $lang, 'provider' => (string) ($request['provider'] ?? ''), 'timeout' => (int) ($request['timeout'] ?? 0), 'compact' => $compact ? 1 : 0]);
				self::heartbeat_active_process_lock();
				$result = EPV2_AI_Client::generate($request, self::translation_messages($base, $lang, $localeLabel, $compact));
				self::heartbeat_active_process_lock();
				EPV2_Logger::info('ai', 'Bounded language translation response', ['lang' => $lang, 'provider' => (string) ($request['provider'] ?? ''), 'tokens' => (int) ($result['tokens'] ?? 0)]);
				$data = json_decode(self::clean_json_response((string) ($result['text'] ?? '')), true);
				if (! is_array($data)) {
					continue;
				}
				$package = [
					'title' => sanitize_text_field((string) ($data['title'] ?? '')),
					'excerpt' => sanitize_textarea_field((string) ($data['excerpt'] ?? '')),
					'content' => wp_kses_post((string) ($data['content'] ?? '')),
				];
				if (self::translated_package_is_valid($base, $package, $lang)) {
					return $package;
				}
			} catch (Throwable $e) {
				EPV2_Logger::warning('ai', 'Bounded language translation failed', [
					'lang' => $lang,
					'provider' => (string) ($request['provider'] ?? ''),
					'error' => $e->getMessage(),
				]);
			}
		}
		return null;
	}

	private static function fallback_provider_config(array $config): array {
		if (empty($config['fallback_api_key']) || empty($config['fallback_provider']) || empty($config['fallback_model'])) {
			return [];
		}
		if (($config['provider'] ?? '') === ($config['fallback_provider'] ?? '')) {
			return [];
		}
		if (empty($config['allow_cross_vendor_fallback'])) {
			return [];
		}
		return [
			'provider' => (string) $config['fallback_provider'],
			'model' => (string) $config['fallback_model'],
			'api_key' => (string) $config['fallback_api_key'],
			'temperature' => (float) ($config['temperature'] ?? 0.4),
			'max_tokens' => (int) ($config['max_tokens'] ?? 3000),
			'gemini_search_grounding_enabled' => false,
			'gemini_url_context_enabled' => false,
			'gemini_use_source_url_in_prompt' => false,
			'gemini_require_citations' => false,
		];
	}

	private static function review_payload_primary_config(array $config): array {
		$primary = sanitize_key((string) ($config['provider'] ?? ''));
		$fallback = self::fallback_provider_config($config);
		$fallback_provider = sanitize_key((string) ($fallback['provider'] ?? ''));
		if ($primary === 'deepseek' && $fallback_provider === 'openai' && ! empty($fallback['api_key'])) {
			$config['provider'] = (string) ($fallback['provider'] ?? $config['provider']);
			$config['model'] = (string) ($fallback['model'] ?? $config['model']);
			$config['api_key'] = (string) ($fallback['api_key'] ?? $config['api_key']);
		}
		$config['timeout'] = max(18, min(40, (int) ($config['timeout'] ?? 28)));
		$config['max_tokens'] = max(1400, min(2600, (int) ($config['max_tokens'] ?? 2200)));
		return $config;
	}

	private static function next_state_after_processing(array $payload): string {
		if (self::payload_context_rejects($payload)) {
			return 'rejected';
		}
		if (self::payload_next_required_stage($payload) !== '') {
			return 'retry_process';
		}
		return self::payload_ready_for_publish($payload) ? 'ready_publish' : 'retry_process';
	}

	public static function payload_is_publish_ready(array $payload): bool {
		return self::payload_ready_for_publish($payload);
	}

	public static function payload_is_review_ready(array $payload): bool {
		return self::payload_is_review_worthy($payload);
	}

	public static function payload_has_publish_substance(array $payload): bool {
		return self::payload_has_publish_grade_substance($payload);
	}

	public static function normalize_existing_payload(array $payload, bool $allow_expensive = true): array {
		if ($payload === []) {
			return [];
		}
		if (! $allow_expensive) {
			$payload = self::normalize_payload_quotes($payload);
			$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			return self::align_selection_with_payload_category($payload);
		}
		return self::finalize_payload_for_queue($payload);
	}

	public static function refresh_payload_stage_markers(array $payload): array {
		if ($payload === []) {
			return [];
		}
		$payload = self::normalize_payload_quotes($payload);
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload = self::align_selection_with_payload_category($payload);
		$payload['_meta']['quality'] = EPV2_AI_Response_Validator::editorial_quality($payload);
		$payload['_meta']['seo_quality'] = EPV2_AI_Response_Validator::seo_quality($payload);
		$payload['_meta']['release_quality'] = EPV2_AI_Response_Validator::release_quality($payload);
		$payload['_meta']['google_quality'] = EPV2_AI_Response_Validator::google_preflight_quality($payload);
		$payload['_meta']['context_memory'] = self::payload_context_memory($payload);
		return self::refresh_stage_checklist($payload);
	}

	private static function working_category_seed(object $item, array $payload = []): string {
		$payload_categories = implode(',', array_values(array_filter((array) ($payload['categories'] ?? []))));
		if ($payload_categories !== '') {
			return $payload_categories;
		}
		$selection_category = sanitize_text_field((string) ($payload['_meta']['selection']['category'] ?? ''));
		if ($selection_category !== '') {
			return $selection_category;
		}
		$final = sanitize_text_field((string) ($item->category_final ?? ''));
		if ($final !== '') {
			return $final;
		}
		$proposed = sanitize_text_field((string) ($item->category_proposed ?? ''));
		if ($proposed !== '') {
			return $proposed;
		}
		return 'deutschland';
	}

	public static function item_is_auto_rework_candidate(object $item, array $payload = []): bool {
		if (self::looks_like_shell_item($item)) {
			return false;
		}
		if ($payload === []) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
		}
		if ($payload !== []) {
			$payload = self::normalize_existing_payload($payload, false);
		}
		if ($payload === [] || self::payload_is_publish_ready_fast($payload)) {
			return false;
		}
		if (
			self::payload_is_review_ready_fast($payload)
			&& self::payload_needs_publish_finish_fast($payload)
			&& ! self::payload_requires_fresh_rebuild_fast($payload)
		) {
			return false;
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$attempts = (int) ($notes['_system']['retries']['review_rebuild'] ?? 0);
		$processRetries = (int) ($notes['_system']['retries']['process'] ?? 0);
		$maxAttempts = self::configured_review_attempt_limit($payload);
		$message = (string) ($item->error_message ?? '');
		if (
			self::review_rebuild_exhausted($item, $payload)
			|| ($processRetries > $maxAttempts && preg_match('/publish threshold|minimum review threshold|heuristic payload|broken multilingual/i', $message) === 1)
		) {
			return false;
		}
		return self::payload_requires_fresh_rebuild_fast($payload);
	}

	public static function item_is_auto_finish_candidate(object $item, array $payload = []): bool {
		if (self::looks_like_shell_item($item)) {
			return false;
		}
		if ($payload === []) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
		}
		if ($payload !== []) {
			$payload = self::normalize_existing_payload($payload, false);
		}
		if ($payload === [] || self::payload_is_publish_ready_fast($payload) || ! self::payload_is_review_ready_fast($payload)) {
			return false;
		}
		if (self::review_finish_exhausted($item, $payload)) {
			return false;
		}
		return self::payload_needs_publish_finish_fast($payload);
	}

	public static function try_lift_payload_to_publish_grade(object $item, array $payload, bool $de_only = false): array {
		$categories = EPV2_Review::normalize_categories(self::working_category_seed($item, $payload));
		$style = (string) ($payload['_meta']['style'] ?? EPV2_Settings::get('rewrite_style', 'lively'));
		if ($de_only) {
			return self::lift_de_master_to_publish_grade($item, $payload, $categories, $style);
		}
		return self::lift_multilingual_payload_to_publish_grade($item, $payload, $categories, $style);
	}

	public static function run_publish_finish_stage(object $item, array $payload, array $categories, string $style): array {
		$payload = self::normalize_existing_payload($payload, false);
		$release_text = mb_strtolower(implode(' | ', self::warning_strings($payload['_meta']['release_quality']['warnings'] ?? [])));
		$needs_media_repair = ! self::payload_has_media_candidate($payload)
			|| ! self::payload_featured_media_is_publishable($payload)
			|| preg_match('/generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|нет featured media|нет главного изображения/u', $release_text) === 1;
		if ($needs_media_repair) {
			$payload = self::repair_payload_media($item, $payload);
			if (! self::payload_has_media_candidate($payload)) {
				return self::finalize_payload_for_queue($payload);
			}
		}
		return self::lift_multilingual_payload_to_publish_grade($item, $payload, $categories, $style);
	}

	public static function item_has_exhausted_auto_rework(object $item, array $payload = []): bool {
		return self::review_rebuild_exhausted($item, $payload);
	}

	public static function item_has_exhausted_auto_finish(object $item, array $payload = []): bool {
		return self::review_finish_exhausted($item, $payload);
	}

	public static function payload_requires_terminal_context_reject(array $payload): bool {
		return self::payload_context_rejects($payload);
	}

	public static function auto_rework_signature(array $payload): string {
		return self::review_rebuild_signature($payload);
	}

	public static function auto_finish_signature(array $payload): string {
		return self::review_finish_signature($payload);
	}

	private static function bump_review_rebuild_attempt(int $item_id, array $payload = []): void {
		$item = EPV2_Queue::get_item($item_id);
		if (! $item) {
			return;
		}
		if ($payload === []) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['retries'] = is_array($notes['_system']['retries'] ?? null) ? $notes['_system']['retries'] : [];
		$signature = self::review_rebuild_signature($payload);
		$stored_signature = (string) ($notes['_system']['review_rebuild_signature'] ?? '');
		if ($signature !== '' && $stored_signature !== $signature) {
			$notes['_system']['retries']['review_rebuild'] = 0;
			$notes['_system']['review_rebuild_signature'] = $signature;
		}
		$notes['_system']['retries']['review_rebuild'] = (int) ($notes['_system']['retries']['review_rebuild'] ?? 0) + 1;
		unset($notes['_system']['retry_after']);
		EPV2_Queue::update_fields($item_id, [
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
	}

	private static function review_rebuild_exhausted(object $item, array $payload = []): bool {
		if ($payload === []) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$attempts = (int) ($notes['_system']['retries']['review_rebuild'] ?? 0);
		$current_signature = self::review_rebuild_signature($payload);
		$stored_signature = (string) ($notes['_system']['review_rebuild_signature'] ?? '');
		if ($current_signature !== '' && $stored_signature !== '' && $stored_signature !== $current_signature) {
			return false;
		}
		if ($current_signature !== '' && $stored_signature === '') {
			return false;
		}
		$maxAttempts = self::configured_review_attempt_limit($payload);
		return $attempts >= $maxAttempts;
	}

	private static function bump_review_finish_attempt(int $item_id, array $payload = []): void {
		$item = EPV2_Queue::get_item($item_id);
		if (! $item) {
			return;
		}
		if ($payload === []) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['retries'] = is_array($notes['_system']['retries'] ?? null) ? $notes['_system']['retries'] : [];
		$signature = self::review_finish_signature($payload);
		$stored_signature = (string) ($notes['_system']['review_finish_signature'] ?? '');
		if ($signature !== '' && $stored_signature !== $signature) {
			$notes['_system']['retries']['review_finish'] = 0;
			$notes['_system']['review_finish_signature'] = $signature;
		}
		$notes['_system']['retries']['review_finish'] = (int) ($notes['_system']['retries']['review_finish'] ?? 0) + 1;
		unset($notes['_system']['retry_after']);
		EPV2_Queue::update_fields($item_id, [
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
	}

	private static function review_finish_exhausted(object $item, array $payload = []): bool {
		if ($payload === []) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$attempts = (int) ($notes['_system']['retries']['review_finish'] ?? 0);
		$current_signature = self::review_finish_signature($payload);
		$stored_signature = (string) ($notes['_system']['review_finish_signature'] ?? '');
		if ($current_signature !== '' && $stored_signature !== '' && $stored_signature !== $current_signature) {
			return false;
		}
		if ($current_signature !== '' && $stored_signature === '') {
			return false;
		}
		$maxAttempts = self::configured_review_attempt_limit($payload);
		return $attempts >= $maxAttempts;
	}

	private static function configured_review_attempt_limit(array $payload = []): int {
		$base = max(1, min(6, (int) EPV2_Settings::get('max_retry_attempts', 3)));
		$categories = array_values(array_filter(array_map('sanitize_key', (array) ($payload['categories'] ?? []))));
		$primary_category = (string) ($categories[0] ?? '');
		$event_kind = sanitize_key((string) ($payload['_meta']['source_dossier']['event_context']['kind'] ?? ''));
		$source_count = (int) ($payload['_meta']['source_count'] ?? 0);
		$extended = $source_count <= 1
			|| in_array($primary_category, ['community', 'leben-in-deutschland', 'sport', 'kultur', 'bayern', 'munchen', 'muenchen'], true)
			|| in_array($event_kind, ['community', 'sport', 'kultur'], true);
		if ($extended) {
			return min(6, max($base, $base + 2));
		}
		return $base;
	}

	private static function review_rebuild_signature(array $payload): string {
		if ($payload === []) {
			return '';
		}
		$payload = self::normalize_existing_payload($payload, false);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$signals = [
			'publishable' => self::payload_is_publish_ready_fast($payload) ? 1 : 0,
			'reviewable' => self::payload_is_review_ready_fast($payload) ? 1 : 0,
			'has_media' => self::payload_has_media_candidate($payload) ? 1 : 0,
			'source_count' => (int) ($meta['source_count'] ?? 0),
			'seo' => self::warning_strings($meta['seo_quality']['warnings'] ?? []),
			'release' => self::warning_strings($meta['release_quality']['warnings'] ?? []),
			'google' => self::warning_strings($meta['google_quality']['warnings'] ?? []),
		];
		return sha1(wp_json_encode($signals, JSON_UNESCAPED_UNICODE));
	}

	private static function review_finish_signature(array $payload): string {
		if ($payload === []) {
			return '';
		}
		$payload = self::normalize_existing_payload($payload, false);
		return sha1(wp_json_encode([
			'publishable' => self::payload_is_publish_ready_fast($payload) ? 1 : 0,
			'warnings' => self::warning_strings(array_merge(
				self::warning_strings($payload['_meta']['seo_quality']['warnings'] ?? []),
				self::warning_strings($payload['_meta']['release_quality']['warnings'] ?? []),
				self::warning_strings($payload['_meta']['google_quality']['warnings'] ?? [])
			)),
		], JSON_UNESCAPED_UNICODE));
	}

	private static function payload_needs_enrichment_rebuild(array $payload): bool {
		$payload = self::finalize_payload_for_queue($payload);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$source_count = (int) ($meta['source_count'] ?? 0);
		$selection_category = sanitize_text_field((string) ($meta['selection']['category'] ?? ''));
		$payload_category = sanitize_text_field((string) ((array) ($payload['categories'] ?? ['']))[0]);
		$translation_stage = self::payload_stage_requires_translation_finish($payload);
		$uk_ready = self::payload_language_ready($payload, 'uk');
		$en_ready = self::payload_language_ready($payload, 'en');
		$translations_ready = $uk_ready && $en_ready;
		if (self::payload_can_finish_without_rebuild($payload)) {
			return false;
		}
		if ($translation_stage) {
			if (! self::de_master_is_viable($payload)) {
				return true;
			}
			if ($source_count <= 0) {
				return true;
			}
		} elseif ($source_count <= 1 && ! $translations_ready) {
			return true;
		}
		if ($selection_category !== '' && $payload_category !== '' && $selection_category !== $payload_category) {
			return true;
		}
		if (! $translation_stage && ! self::payload_is_review_ready($payload)) {
			return true;
		}
		if (! self::payload_has_media_candidate($payload)) {
			return true;
		}
		$warnings = array_merge(
			self::warning_strings($meta['quality']['warnings'] ?? []),
			self::warning_strings($meta['release_quality']['warnings'] ?? []),
			self::warning_strings($meta['google_quality']['warnings'] ?? [])
		);
		if ($warnings === []) {
			return false;
		}
		if ($translation_stage) {
			$warnings = array_values(array_filter($warnings, static function (string $warning): bool {
				if (preg_match('/^(UK|EN):\s/u', $warning) === 1) {
					return false;
				}
				if (preg_match('/generic stock featured media|нет featured media|нет главного изображения|не соответствует теме материала|слишком слабое.*featured media/u', $warning) === 1) {
					return false;
				}
				return true;
			}));
		}
		$text = mb_strtolower(implode(' | ', $warnings));
		if ($translation_stage && $text === '') {
			return false;
		}
		if ($translations_ready && self::payload_needs_publish_finish_fast($payload)) {
			$text = preg_replace('/\bсломанная языковая версия\b/iu', '', $text);
			$text = preg_replace('/\bневалидн.*индексац[^\|]*\b/iu', '', $text);
			$text = trim((string) $text, " |\t\n\r\0\x0B");
			if ($text === '') {
				return false;
			}
		}
		return preg_match('/слишком коротк|поверхностн|слабый.*lead|сломанная языковая версия|невалидн.*индексац|копи.*немецк|смешан.*немецк|нет featured media|нет главного изображения|generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|слабое досье источников|дополнительн(ый|ые)\s+источник[аи]?\s+плохо соответств|рубрика не соответствует/u', $text) === 1;
	}

	private static function payload_requires_fresh_rebuild_before_media_repair(array $payload): bool {
		$payload = self::finalize_payload_for_queue($payload);
		if ($payload === [] || self::payload_stage_requires_translation_finish($payload)) {
			return false;
		}
		if (self::payload_needs_enrichment_rebuild($payload)) {
			return true;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$warnings = array_merge(
			self::warning_strings($meta['quality']['warnings'] ?? []),
			self::warning_strings($meta['release_quality']['warnings'] ?? []),
			self::warning_strings($meta['google_quality']['warnings'] ?? [])
		);
		if ($warnings === []) {
			return false;
		}
		$text = mb_strtolower(implode(' | ', $warnings));
		return preg_match('/дополнительные источники плохо соответствуют|рубрика не соответствует|не соответствует теме материала|слабое досье источников/u', $text) === 1;
	}

	private static function payload_needs_publish_finish(array $payload): bool {
		$payload = self::finalize_payload_for_queue($payload);
		return self::payload_needs_publish_finish_fast($payload);
	}

	private static function payload_needs_publish_finish_fast(array $payload): bool {
		$warnings = array_merge(
			self::warning_strings($payload['_meta']['seo_quality']['warnings'] ?? []),
			self::warning_strings($payload['_meta']['release_quality']['warnings'] ?? []),
			self::warning_strings($payload['_meta']['google_quality']['warnings'] ?? [])
		);
		if ($warnings === []) {
			return false;
		}
		$has_fixable = false;
		foreach ($warnings as $warning) {
			$text = mb_strtolower((string) $warning);
			if (preg_match('/seo title|meta description|focus keywords|главный ключ|slug|snippet|featured media|главного изображения|слабый.*lead|поверхностн|невалидн.*индексац|сломанная языковая версия/u', $text) === 1) {
				$has_fixable = true;
				continue;
			}
			return false;
		}
		return $has_fixable;
	}

	private static function payload_can_finish_without_rebuild(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$translations_ready = self::payload_language_ready($payload, 'uk')
			&& self::payload_language_ready($payload, 'en')
			&& empty($meta['translations_deferred']);
		if (! $translations_ready) {
			return false;
		}
		$warnings = array_merge(
			self::warning_strings($meta['quality']['warnings'] ?? []),
			self::warning_strings($meta['seo_quality']['warnings'] ?? []),
			self::warning_strings($meta['release_quality']['warnings'] ?? []),
			self::warning_strings($meta['google_quality']['warnings'] ?? [])
		);
		if ($warnings === []) {
			return false;
		}
		$has_fixable = false;
		foreach ($warnings as $warning) {
			$text = mb_strtolower((string) $warning);
			if (preg_match('/слабое досье источников|seo title|meta description|focus keywords|главный ключ|slug|snippet|featured media|главного изображения|слабый.*lead|поверхностн|невалидн.*индексац|сломанная языковая версия|shared_media_missing|generic stock featured media|слишком слабое.*featured media|не соответствует теме материала/u', $text) === 1) {
				$has_fixable = true;
				continue;
			}
			return false;
		}
		return $has_fixable;
	}

	private static function payload_is_review_ready_fast(array $payload): bool {
		if ($payload === []) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$quality = (int) ($meta['quality']['score'] ?? 0);
		$seo = (int) ($meta['seo_quality']['score'] ?? 0);
		$release = (int) ($meta['release_quality']['score'] ?? 0);
		$google = (int) ($meta['google_quality']['score'] ?? 0);
		if ($quality < 70 || $seo < 80 || $release < 70 || $google < 70) {
			return false;
		}
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		return trim((string) ($de['title'] ?? '')) !== ''
			&& trim((string) ($de['excerpt'] ?? '')) !== ''
			&& trim(wp_strip_all_tags((string) ($de['content'] ?? ''))) !== '';
	}

	private static function payload_is_publish_ready_fast(array $payload): bool {
		if (! self::payload_is_review_ready_fast($payload)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		return (int) ($meta['quality']['score'] ?? 0) >= 100
			&& (int) ($meta['seo_quality']['score'] ?? 0) >= 100
			&& (int) ($meta['release_quality']['score'] ?? 0) >= 100
			&& (int) ($meta['google_quality']['score'] ?? 0) >= 100
			&& self::payload_primary_media_url($payload) !== '';
	}

	private static function payload_requires_fresh_rebuild_fast(array $payload): bool {
		if ($payload === [] || self::payload_stage_requires_translation_finish($payload)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$translations_ready = self::payload_language_ready($payload, 'uk')
			&& self::payload_language_ready($payload, 'en')
			&& empty($meta['translations_deferred']);
		if ($translations_ready && self::payload_needs_publish_finish_fast($payload)) {
			return false;
		}
		if (self::payload_can_finish_without_rebuild($payload)) {
			return false;
		}
		$source_count = (int) ($meta['source_count'] ?? 0);
		$selection_category = sanitize_text_field((string) ($meta['selection']['category'] ?? ''));
		$payload_category = sanitize_text_field((string) ((array) ($payload['categories'] ?? ['']))[0]);
		if ($source_count <= 1) {
			return true;
		}
		if ($selection_category !== '' && $payload_category !== '' && $selection_category !== $payload_category) {
			return true;
		}
		if (! self::payload_is_review_ready_fast($payload)) {
			return true;
		}
		if (self::payload_primary_media_url($payload) === '') {
			return true;
		}
		$warnings = array_merge(
			self::warning_strings($meta['quality']['warnings'] ?? []),
			self::warning_strings($meta['release_quality']['warnings'] ?? []),
			self::warning_strings($meta['google_quality']['warnings'] ?? [])
		);
		$text = mb_strtolower(implode(' | ', $warnings));
		return preg_match('/слишком коротк|поверхностн|слабый.*lead|сломанная языковая версия|невалидн.*индексац|копи.*немецк|смешан.*немецк|нет featured media|нет главного изображения|generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|слабое досье источников|дополнительн(ый|ые)\s+источник[аи]?\s+плохо соответств|рубрика не соответствует/u', $text) === 1;
	}

	private static function warning_strings($warnings): array {
		$result = [];
		if (! is_array($warnings)) {
			$warning = trim((string) $warnings);
			return $warning !== '' ? [$warning] : [];
		}
		foreach ($warnings as $warning) {
			if (is_array($warning)) {
				$result = array_merge($result, self::warning_strings($warning));
				continue;
			}
			$warning = trim((string) $warning);
			if ($warning !== '') {
				$result[] = $warning;
			}
		}
		return array_values(array_unique($result));
	}

	private static function payload_ready_for_publish(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if (self::payload_context_rejects($payload)) {
			return false;
		}
		$quality = is_array($meta['quality'] ?? null) ? $meta['quality'] : [];
		$seo_quality = is_array($meta['seo_quality'] ?? null) ? $meta['seo_quality'] : [];
		$release_quality = is_array($meta['release_quality'] ?? null) ? $meta['release_quality'] : [];
		$google_quality = is_array($meta['google_quality'] ?? null) ? $meta['google_quality'] : [];

		if (! empty($meta['gate_mode']) && (string) $meta['gate_mode'] !== 'ai_priority_only') {
			return false;
		}
		if (! self::quality_meets_publish_gate($quality, 'editorial')) {
			return false;
		}
		if (! self::quality_meets_publish_gate($seo_quality, 'seo')) {
			return false;
		}
		if (! self::quality_meets_publish_gate($release_quality, 'release')) {
			return false;
		}
		if (! self::quality_meets_publish_gate($google_quality, 'google')) {
			return false;
		}
		$featured_media_url = self::payload_primary_media_url($payload);
		if ($featured_media_url === '' || self::payload_media_is_blocked($payload, $featured_media_url)) {
			return false;
		}
		if (! self::payload_featured_media_is_publishable($payload)) {
			return false;
		}
		if (! self::payload_has_publish_grade_substance($payload)) {
			return false;
		}

		return self::languages_look_publishable($payload);
	}

	private static function payload_context_rejects(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$context = is_array($meta['context_analysis'] ?? null) ? $meta['context_analysis'] : [];
		if ($context === []) {
			return false;
		}
		if (self::context_reject_can_be_overridden_by_completed_payload($payload, $context)) {
			return false;
		}
		return self::context_analysis_requires_terminal_reject($context);
	}

	private static function context_reject_can_be_overridden_by_completed_payload(array $payload, array $context): bool {
		if ((string) ($context['decision'] ?? '') !== 'reject') {
			return false;
		}
		$reject_class = sanitize_key((string) ($context['reject_class'] ?? ''));
		if (! in_array($reject_class, ['low_score', 'thin_context', 'weak_supporting'], true)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$has_top_scores = (int) ($meta['quality']['score'] ?? 0) >= 100
			&& (int) ($meta['seo_quality']['score'] ?? 0) >= 100
			&& (int) ($meta['release_quality']['score'] ?? 0) >= 100
			&& (int) ($meta['google_quality']['score'] ?? 0) >= 100;
		if (! $has_top_scores) {
			return false;
		}
		if (! self::de_master_is_viable_fast($payload)) {
			return false;
		}
		if (! self::languages_look_publishable($payload)) {
			return false;
		}
		if (self::payload_primary_media_url($payload) === '') {
			return false;
		}
		return true;
	}

	private static function context_analysis_requires_terminal_reject(array $context): bool {
		if ((string) ($context['decision'] ?? '') !== 'reject') {
			return false;
		}
		$reject_class = sanitize_key((string) ($context['reject_class'] ?? ''));
		return in_array($reject_class, ['stale', 'noise', 'routine_official', 'hard_pattern'], true);
	}

	private static function payload_featured_media_is_publishable(array $payload): bool {
		$featured_media_url = self::payload_primary_media_url($payload);
		if ($featured_media_url === '' || self::payload_media_is_blocked($payload, $featured_media_url)) {
			return false;
		}
		$de_payload = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title = trim((string) ($de_payload['title'] ?? ''));
		$excerpt = trim((string) ($de_payload['excerpt'] ?? ''));
		$categories = array_values(array_filter((array) ($payload['categories'] ?? [])));
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$validation = EPV2_Media::validate_featured_media($featured_media_url, 0, $title);
		if (empty($validation['ok'])) {
			return false;
		}
		return EPV2_Media::is_relevant_media($featured_media_url, $title, $excerpt, $categories, $dossier);
	}

	private static function is_reworkable_process_error(string $message): bool {
		return preg_match('/publish threshold|minimum review threshold|heuristic payload|broken multilingual/i', $message) === 1;
	}

	private static function should_reject_after_process_failure(object $item, string $message): bool {
		$message = trim($message);
		$title = trim((string) ($item->original_title ?? ''));
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$selection = is_array($notes['selection'] ?? null) ? $notes['selection'] : [];
		if (self::item_has_expired_live_angle($item)) {
			return true;
		}
		if ($selection === []) {
			$selection = EPV2_Budget_Manager::analyze_item([
				'title' => (string) ($item->original_title ?? ''),
				'content' => (string) ($item->original_content ?? ''),
				'excerpt' => (string) ($item->original_excerpt ?? ''),
				'url' => (string) ($item->original_url ?? ''),
				'date' => (string) ($item->original_date ?? ''),
				'image' => (string) ($item->source_image_url ?? ''),
				'category' => self::working_category_seed($item),
			]);
		}
		$reasons = is_array($selection['reasons'] ?? null) ? $selection['reasons'] : [];
		$looks_generic = $title === '' || preg_match('/^(news|update|ticker|meldung)$/iu', $title) === 1;
		$looks_stale = preg_match('/archive|устар|старую|alte oder archiv/i', implode(' ', array_map('strval', $reasons))) === 1;
		if (self::looks_like_shell_item($item)) {
			return true;
		}
		if ($looks_generic && $looks_stale) {
			return true;
		}
		$retry_count = (int) ($notes['_system']['retries']['process'] ?? 0);
		if ($retry_count >= 1 && preg_match('/\bliveblog\b|\bim video\b|\b\| \d{1,2}\.\s*m[äa]rz\b/ui', $title) === 1 && preg_match('/minimum review threshold|publish threshold/i', $message) === 1) {
			return true;
		}
		return false;
	}

	public static function item_has_expired_live_angle(object $item): bool {
		$reference = strtotime((string) ($item->original_date ?? '')) ?: strtotime((string) ($item->created_at ?? '')) ?: 0;
		if ($reference <= 0) {
			return false;
		}
		$ageHours = (time() - $reference) / HOUR_IN_SECONDS;
		if ($ageHours < 4) {
			return false;
		}
		$text = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($item->original_title ?? ''),
			(string) ($item->original_excerpt ?? ''),
			wp_strip_all_tags((string) ($item->original_content ?? '')),
			self::working_category_seed($item),
		]))));
		if ($text === '') {
			return false;
		}

		$previewLike = preg_match('/\b(vor dem|vor dem spiel|vor dem rückspiel|vor dem rueckspiel|rückspiel|rueckspiel|heute abend|morgen|preview|entscheidenden champions-league-spiel)\b/u', $text) === 1;
		$eventLike = preg_match('/\b(bildungsbörse|bildungsmesse|berufsmesse|karrieremesse|job fair|career fair|education fair|workshop|sprechstunde|community event|anmeldung|anmeldefrist)\b/u', $text) === 1;
		$sportLiveAngle = preg_match('/\b(liverpool|galatasaray|bundesliga|champions league|spieltag|match|trainer|torwart|football|soccer)\b/u', $text) === 1;

		if ($previewLike && ($sportLiveAngle || $eventLike) && $ageHours >= 6) {
			return true;
		}
		if ($eventLike && $ageHours >= 12) {
			return true;
		}
		return false;
	}

	private static function looks_like_shell_item(object $item): bool {
		$title = trim(wp_strip_all_tags((string) ($item->original_title ?? '')));
		$excerpt = trim(wp_strip_all_tags((string) ($item->original_excerpt ?? '')));
		$content = trim(wp_strip_all_tags((string) ($item->original_content ?? '')));
		$url = trim((string) ($item->original_url ?? ''));
		$title_lc = mb_strtolower($title);
		$excerpt_lc = mb_strtolower($excerpt);
		$content_lc = mb_strtolower($content);

		$short_brand_only = ['tagesschau', 'bild', 'reuters', 'dpa', 'sz.de'];
		if (in_array($title_lc, $short_brand_only, true) && ($excerpt_lc === '' || $excerpt_lc === $title_lc)) {
			return true;
		}

		$link_shell = preg_match('/\bmehr\b/i', $content_lc) === 1
			&& preg_match_all('/https?:\/\//i', (string) ($item->original_content ?? ''), $matches) >= 1
			&& mb_strlen($content_lc) < 120;
		if ($link_shell && ($excerpt_lc === '' || $excerpt_lc === $title_lc)) {
			return true;
		}

		if ($title_lc !== '' && $excerpt_lc === $title_lc && mb_strlen($title_lc) <= 20 && mb_strlen($content_lc) < 80) {
			return true;
		}

		if ($url !== '' && str_contains($url, '/tagesschau/ts-') && mb_strlen($content_lc) < 120) {
			return true;
		}

		return false;
	}

	private static function item_needs_media_repair(object $item, array $payload = []): bool {
		$message = trim((string) ($item->error_message ?? ''));
		if ($message !== '' && preg_match('/featured image|featured media|изображение не подошло/i', $message) === 1) {
			return true;
		}
		if ($payload === []) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
		}
		$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
		$warnings = self::warning_strings($payload['_meta']['release_quality']['warnings'] ?? []);
		$text = mb_strtolower(implode(' | ', $warnings));
		return preg_match('/generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|нет featured media|нет главного изображения/u', $text) === 1;
	}

	private static function repair_payload_media(object $item, array $payload): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$categories = EPV2_Review::normalize_categories(self::working_category_seed($item, $payload));
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title = (string) ($de['title'] ?? $item->original_title ?? '');
		$excerpt = (string) ($de['excerpt'] ?? $item->original_excerpt ?? '');
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		if (! is_array($dossier['context_memory'] ?? null)) {
			$dossier['context_memory'] = self::payload_context_memory($payload);
		}
		$has_dossier_image = trim((string) ($dossier['primary']['image'] ?? '')) !== '';
		if (! $has_dossier_image) {
			foreach ((array) ($dossier['supporting'] ?? []) as $entry) {
				if (is_array($entry) && trim((string) ($entry['image'] ?? '')) !== '') {
					$has_dossier_image = true;
					break;
				}
			}
		}
		if (! $has_dossier_image && ! empty($item->original_url)) {
			try {
				$doc = EPV2_HTML_Reader::fetch_document((string) $item->original_url);
				$image = esc_url_raw((string) ($doc['image'] ?? ''));
				if ($image !== '') {
					$dossier['primary'] = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
					if (trim((string) ($dossier['primary']['url'] ?? '')) === '') {
						$dossier['primary']['url'] = esc_url_raw((string) $item->original_url);
					}
					if (trim((string) ($dossier['primary']['title'] ?? '')) === '') {
						$dossier['primary']['title'] = sanitize_text_field((string) ($doc['title'] ?? $title));
					}
					if (trim((string) ($dossier['primary']['excerpt'] ?? '')) === '') {
						$dossier['primary']['excerpt'] = sanitize_text_field((string) ($doc['excerpt'] ?? $excerpt));
					}
					$dossier['primary']['image'] = $image;
				}
			} catch (Throwable $e) {
				// Leave dossier unchanged; media repair continues with existing candidates.
			}
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$current_media_urls = array_values(array_filter(array_map('strval', [
			(string) ($payload['featured_media_url'] ?? ''),
			(string) ($payload['media_url'] ?? ''),
			(string) ($de['media_url'] ?? ''),
		])));
		$current_media_urls = array_values(array_unique($current_media_urls));
		$blocked = array_values(array_unique(array_filter(array_map('strval', array_merge(
			(array) ($payload['_meta']['blocked_media_urls'] ?? []),
			(array) ($notes['_system']['blocked_media_urls'] ?? [])
		)))));
		$payload['_meta']['blocked_media_urls'] = $blocked;
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		if ($blocked !== []) {
			$dossier = self::prune_blocked_media_from_dossier($dossier, $blocked);
			$notes['_system']['blocked_media_urls'] = $blocked;
		} else {
			unset($notes['_system']['blocked_media_urls']);
		}
		$payload['_meta']['source_dossier'] = $dossier;
		$attempt = 0;
		$new_media = '';
		while ($attempt < 2) {
			$attempt++;
			$candidate = EPV2_Media::resolve_featured_media($title, $excerpt, $categories, '', $dossier, (int) $item->id);
			if ($candidate === '' || in_array($candidate, $blocked, true) || in_array($candidate, $current_media_urls, true)) {
				if ($attempt === 1) {
					$refreshed_dossier = EPV2_Source_Enricher::enrich_item($item, [
						'force_supporting' => true,
						'target_supporting' => 2,
						'max_runtime_seconds' => 5,
						'context_memory' => self::payload_context_memory($payload),
					]);
					if ($refreshed_dossier !== []) {
						if ($blocked !== []) {
							$refreshed_dossier = self::prune_blocked_media_from_dossier($refreshed_dossier, $blocked);
						}
						$dossier = $refreshed_dossier;
						$payload['_meta']['source_dossier'] = $dossier;
						$payload['_meta']['source_count'] = 1 + count((array) ($dossier['supporting'] ?? []));
						$payload['_meta']['context_memory'] = self::payload_context_memory($payload);
						continue;
					}
				}
				break;
			}
			$validation = EPV2_Media::validate_featured_media($candidate, 0, $title);
			if (! empty($validation['ok'])) {
				$new_media = $candidate;
				break;
			}
			$blocked[] = $candidate;
			$blocked = array_values(array_unique(array_filter($blocked)));
			$dossier = self::prune_blocked_media_from_dossier($dossier, [$candidate]);
			$payload['_meta']['source_dossier'] = $dossier;
			$payload['_meta']['blocked_media_urls'] = $blocked;
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$notes['_system']['blocked_media_urls'] = $blocked;
		}
		if ($new_media === '') {
			$payload['featured_media_url'] = '';
			$payload['media_url'] = '';
			if (is_array($payload['languages'] ?? null)) {
				foreach ($payload['languages'] as $lang => $lang_payload) {
					if (is_array($lang_payload)) {
						$payload['languages'][$lang]['media_url'] = '';
					}
				}
			}
			$payload['_meta']['media_repair_failed'] = true;
			if ($notes !== []) {
				EPV2_Queue::update_fields((int) $item->id, [
					'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
					'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				]);
			}
			return self::finalize_payload_for_queue($payload);
		}
		$payload['featured_media_url'] = $new_media;
		$payload['media_url'] = $new_media;
		if (is_array($payload['languages'] ?? null)) {
			foreach ($payload['languages'] as $lang => $lang_payload) {
				if (! is_array($lang_payload)) {
					continue;
				}
				$payload['languages'][$lang]['media_url'] = $new_media;
			}
		}
		unset($payload['_meta']['media_repair_failed']);
		EPV2_Queue::update_fields((int) $item->id, [
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
		return self::finalize_payload_for_queue($payload);
	}

	private static function rehabilitate_media_candidates(array $blocked, string $title, string $excerpt, array $categories, array $dossier = []): array {
		if ($blocked === []) {
			return [];
		}
		$remaining = [];
		foreach ($blocked as $url) {
			$url = esc_url_raw((string) $url);
			if ($url === '') {
				continue;
			}
			$validation = EPV2_Media::validate_featured_media($url, 0, $title);
			if (! empty($validation['ok']) && EPV2_Media::is_relevant_media($url, $title, $excerpt, $categories, $dossier)) {
				continue;
			}
			$remaining[] = $url;
		}
		return array_values(array_unique($remaining));
	}

	private static function prune_blocked_media_from_dossier(array $dossier, array $blocked): array {
		if ($dossier === [] || $blocked === []) {
			return $dossier;
		}
		if (is_array($dossier['primary'] ?? null)) {
			$image = (string) ($dossier['primary']['image'] ?? '');
			if ($image !== '' && in_array($image, $blocked, true)) {
				$dossier['primary']['image'] = '';
			}
		}
		if (is_array($dossier['supporting'] ?? null)) {
			foreach ($dossier['supporting'] as $index => $entry) {
				if (! is_array($entry)) {
					continue;
				}
				$image = (string) ($entry['image'] ?? '');
				if ($image !== '' && in_array($image, $blocked, true)) {
					$dossier['supporting'][$index]['image'] = '';
				}
			}
		}
		return $dossier;
	}

	private static function payload_has_media_candidate(array $payload): bool {
		$primary = self::payload_primary_media_url($payload);
		if ($primary !== '' && ! self::payload_media_is_blocked($payload, $primary)) {
			return true;
		}
		if (is_array($payload['languages'] ?? null)) {
			foreach ($payload['languages'] as $lang_payload) {
				if (! is_array($lang_payload)) {
					continue;
				}
				$url = (string) ($lang_payload['media_url'] ?? '');
				if ($url !== '' && ! self::payload_media_is_blocked($payload, $url)) {
					return true;
				}
			}
		}
		return false;
	}

	private static function payload_primary_media_url(array $payload): string {
		return trim((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''));
	}

	private static function payload_media_is_blocked(array $payload, string $url): bool {
		$url = trim($url);
		if ($url === '') {
			return false;
		}
		$blocked = array_values(array_filter(array_map('strval', (array) ($payload['_meta']['blocked_media_urls'] ?? []))));
		$blocked = array_values(array_unique($blocked));
		return in_array($url, $blocked, true);
	}

	private static function should_reject_inside_queue(object $item, array $analysis): bool {
		$title = trim((string) ($item->original_title ?? ''));
		$score = (int) ($analysis['score'] ?? 0);
		$reasons = is_array($analysis['reasons'] ?? null) ? $analysis['reasons'] : [];
		$looks_generic = $title === '' || preg_match('/^(news|update|ticker|meldung)$/iu', $title) === 1;
		$looks_stale = preg_match('/archive|устар|старую|alte oder archiv|ohne neue wert|без новой ценности/i', implode(' ', array_map('strval', $reasons))) === 1;
		if (self::item_has_expired_live_angle($item)) {
			return true;
		}
		if (self::looks_like_shell_item($item)) {
			return true;
		}
		if ($looks_generic && $looks_stale) {
			return true;
		}
		if ($looks_generic && $score < 45) {
			return true;
		}
		return false;
	}

	private static function quality_has_no_warnings(array $quality): bool {
		$warnings = $quality['warnings'] ?? [];
		if (! is_array($warnings) || $warnings === []) {
			return true;
		}
		foreach ($warnings as $warning) {
			if (is_array($warning) && $warning !== []) {
				return false;
			}
			if (! is_array($warning) && trim((string) $warning) !== '') {
				return false;
			}
		}
		return true;
	}

	private static function quality_meets_publish_gate(array $quality, string $kind): bool {
		$score = (int) ($quality['score'] ?? 0);
		$pass = ! empty($quality['pass']);
		if (! $pass) {
			return false;
		}
		return $score >= 100 && self::quality_has_no_warnings($quality);
	}

	private static function payload_requires_retry_after_ai(array $payload, array $gate): bool {
		if (empty($gate['allow'])) {
			return false;
		}
		if (($gate['mode'] ?? '') !== 'ai_full') {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if ((int) ($meta['tokens'] ?? 0) <= 0) {
			return true;
		}
		return ! self::languages_look_publishable($payload);
	}

	private static function languages_look_publishable(array $payload): bool {
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$uk = is_array($payload['languages']['uk'] ?? null) ? $payload['languages']['uk'] : [];
		$en = is_array($payload['languages']['en'] ?? null) ? $payload['languages']['en'] : [];
		if ($de === [] || $uk === [] || $en === []) {
			return false;
		}

		$de_title = self::normalized_language_field($de, 'title');
		$uk_title = self::normalized_language_field($uk, 'title');
		$en_title = self::normalized_language_field($en, 'title');
		$de_excerpt = self::normalized_language_field($de, 'excerpt');
		$uk_excerpt = self::normalized_language_field($uk, 'excerpt');
		$en_excerpt = self::normalized_language_field($en, 'excerpt');
		$de_content = self::normalized_language_field($de, 'content');
		$uk_content = self::normalized_language_field($uk, 'content');
		$en_content = self::normalized_language_field($en, 'content');

		if ($de_title === '' || $uk_title === '' || $en_title === '' || $de_excerpt === '' || $uk_excerpt === '' || $en_excerpt === '' || $de_content === '' || $uk_content === '' || $en_content === '') {
			return false;
		}
		if ($de_title === $uk_title || $de_title === $en_title) {
			return false;
		}
		if ($de_excerpt === $uk_excerpt || $de_excerpt === $en_excerpt) {
			return false;
		}
		if ($de_content === $uk_content || $de_content === $en_content) {
			return false;
		}
		if (! self::language_package_matches_target($uk_title . ' ' . $uk_excerpt . ' ' . $uk_content, 'uk')) {
			return false;
		}
		if (! self::language_package_matches_target($en_title . ' ' . $en_excerpt . ' ' . $en_content, 'en')) {
			return false;
		}

		return true;
	}

	private static function language_package_matches_target(string $combined, string $lang): bool {
		$combined = trim($combined);
		if ($combined === '') {
			return false;
		}
		if ($lang === 'uk') {
			return preg_match('/\p{Cyrillic}/u', $combined) === 1
				&& preg_match('/[ыэёъ]/u', $combined) !== 1
				&& preg_match('/\b(die|der|das|und|mit|für|wird|nicht|bundesregierung|fachkr[aä]fte|akteuren vor ort)\b/iu', $combined) !== 1;
		}
		if ($lang === 'en') {
			return preg_match('/[A-Za-z]/u', $combined) === 1
				&& preg_match('/\p{Cyrillic}/u', $combined) !== 1
				&& preg_match('/\b(die|der|das|und|mit|für|wird|nicht|bundesregierung|fachkr[aä]fte|akteuren vor ort)\b/iu', $combined) !== 1
				&& ! str_contains($combined, 'Die Bundesregierung');
		}
		return true;
	}

	private static function normalized_language_field(array $language_payload, string $field): string {
		$value = (string) ($language_payload[$field] ?? '');
		$value = wp_strip_all_tags($value);
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = preg_replace('/\s+/u', ' ', trim($value)) ?: trim($value);
		return mb_strtolower($value);
	}

	private static function payload_has_publish_grade_substance(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$source_count = (int) ($meta['source_count'] ?? 0);
		$primary_url = (string) ($meta['source_dossier']['primary']['url'] ?? '');
		$categories = array_values(array_filter(array_map('strval', (array) ($payload['categories'] ?? []))));
		$primary_category = (string) ($categories[0] ?? '');
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$content_html = (string) ($de['content'] ?? '');
		$content_plain = trim(wp_strip_all_tags($content_html));
		$has_blockquote = str_contains($content_html, '<blockquote>');
		$has_direct_speech = preg_match('/[«„“"][^"«»„“]{20,}[»"“]/u', $content_plain) === 1;
		$profile = self::payload_story_budget_profile($payload);
		$shape = (string) ($profile['shape'] ?? 'news');
		$de_min = (int) ($profile['de']['content_min_chars'] ?? 700);
		$de_soft = (int) ($profile['de']['content_soft_chars'] ?? 1100);
		$event_kind = sanitize_key((string) ($meta['source_dossier']['event_context']['kind'] ?? ''));
		if (in_array($shape, ['bulletin', 'service_note'], true)) {
			return mb_strlen($content_plain) >= $de_min && $source_count >= 1;
		}
		if ($shape === 'preview') {
			return mb_strlen($content_plain) >= $de_min
				&& ($source_count >= 1 || $event_kind === 'sport');
		}
		if ($shape === 'developing') {
			return ($source_count >= 2 && mb_strlen($content_plain) >= $de_soft)
				|| ($source_count >= 1 && ($has_blockquote || $has_direct_speech) && mb_strlen($content_plain) >= $de_min);
		}
		if ($shape === 'analysis') {
			return ($source_count >= 2 && ($has_blockquote || $has_direct_speech) && mb_strlen($content_plain) >= $de_soft)
				|| ($source_count >= 3 && mb_strlen($content_plain) >= (int) ($profile['de']['content_target_chars'] ?? 2200));
		}
		if ($source_count >= 2 && ($has_blockquote || $has_direct_speech)) {
			return true;
		}
		if ($source_count >= 3 && mb_strlen($content_plain) >= (int) ($profile['de']['content_target_chars'] ?? 2200)) {
			return true;
		}
		if (
			in_array($primary_category, ['community', 'leben-in-deutschland', 'sport', 'kultur', 'bayern', 'münchen'], true)
			&& mb_strlen($content_plain) >= $de_min
		) {
			return true;
		}
		if (self::looks_like_official_primary($primary_url) && mb_strlen($content_plain) >= max($de_min, 520)) {
			return true;
		}
		if ($source_count >= 1 && mb_strlen($content_plain) >= $de_soft) {
			return true;
		}
		if ($source_count >= 2 && mb_strlen($content_plain) >= max($de_soft, $de_min + 120)) {
			return true;
		}
		return false;
	}

	private static function payload_story_budget_profile(array $payload): array {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$story_format = sanitize_key((string) ($meta['story_format'] ?? ''));
		$zone = in_array($story_format, ['analysis', 'developing'], true) ? $story_format : 'news';
		$categories = array_values(array_filter(array_map('strval', (array) ($payload['categories'] ?? []))));
		$category = (string) ($categories[0] ?? 'deutschland');
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$context = [
			'source_count' => max(1, (int) ($meta['source_count'] ?? 1)),
			'event_kind' => (string) ($meta['source_dossier']['event_context']['kind'] ?? ''),
			'title' => (string) ($de['title'] ?? ''),
			'excerpt' => (string) ($de['excerpt'] ?? ''),
			'content' => (string) ($de['content'] ?? ''),
			'datetime_text' => (string) ($meta['source_dossier']['event_context']['datetime_text'] ?? ''),
			'venue' => (string) ($meta['source_dossier']['event_context']['venue'] ?? ''),
			'stage' => (string) ($meta['source_dossier']['event_context']['stage'] ?? ''),
		];

		return [
			'de' => EPV2_Site_Profile::text_budget('de', $zone, $category, $context),
			'uk' => EPV2_Site_Profile::text_budget('uk', $zone, $category, $context),
			'en' => EPV2_Site_Profile::text_budget('en', $zone, $category, $context),
			'shape' => (string) (EPV2_Site_Profile::text_budget('de', $zone, $category, $context)['shape'] ?? 'news'),
		];
	}

	private static function payload_pipeline_stage(array $payload): string {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		return sanitize_key((string) ($meta['pipeline_stage'] ?? ''));
	}

	private static function set_payload_pipeline_stage(array $payload, string $stage): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if ($stage === '') {
			unset($payload['_meta']['pipeline_stage']);
			return $payload;
		}
		$payload['_meta']['pipeline_stage'] = sanitize_key($stage);
		return $payload;
	}

	private static function payload_stage_requires_translation_finish(array $payload): bool {
		if ($payload === []) {
			return false;
		}
		if (! empty($payload['_meta']['translations_deferred'])) {
			return true;
		}
		return in_array(self::payload_pipeline_stage($payload), ['translate_finish', 'translate_uk', 'translate_en'], true);
	}

	private static function payload_is_review_worthy(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if (self::payload_context_rejects($payload)) {
			return false;
		}
		$quality = (int) ($meta['quality']['score'] ?? 0);
		$seo = (int) ($meta['seo_quality']['score'] ?? 0);
		$release = (int) ($meta['release_quality']['score'] ?? 0);
		$google = (int) ($meta['google_quality']['score'] ?? 0);
		if ($quality < 70 || $seo < 80 || $release < 70 || $google < 70) {
			return false;
		}
		return self::languages_look_publishable($payload);
	}

	private static function de_master_ready_for_translation(array $payload): bool {
		if (self::payload_context_rejects($payload) || ! self::de_master_is_viable_fast($payload)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if ((int) ($meta['quality']['score'] ?? 0) < 78) {
			return false;
		}
		if (! self::payload_has_publish_grade_substance($payload)) {
			return false;
		}
		$featured_media_url = self::payload_primary_media_url($payload);
		if ($featured_media_url === '' || self::payload_media_is_blocked($payload, $featured_media_url)) {
			return false;
		}
		$mediaWarnings = mb_strtolower(implode(' | ', array_merge(
			self::warning_strings($meta['release_quality']['warnings'] ?? []),
			self::warning_strings($meta['google_quality']['warnings'] ?? [])
		)));
		if (preg_match('/нет featured media|нет главного изображения|generic stock featured media|слишком слабое.*featured media|не соответствует теме материала/u', $mediaWarnings) === 1) {
			return false;
		}
		return true;
	}

	private static function de_master_is_viable(array $payload): bool {
		$payload = self::finalize_payload_for_queue($payload);
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		if ($de === []) {
			return false;
		}
		if (trim((string) ($de['title'] ?? '')) === '' || trim((string) ($de['excerpt'] ?? '')) === '' || trim(wp_strip_all_tags((string) ($de['content'] ?? ''))) === '') {
			return false;
		}
		$de_combined = trim(implode(' ', [
			(string) ($de['title'] ?? ''),
			(string) ($de['excerpt'] ?? ''),
			wp_strip_all_tags((string) ($de['content'] ?? '')),
		]));
		if (preg_match('/\p{Cyrillic}/u', $de_combined) === 1 || preg_match('/[A-Za-zÄÖÜäöüß]/u', $de_combined) !== 1) {
			return false;
		}
		$quality = is_array($payload['_meta']['quality'] ?? null) ? $payload['_meta']['quality'] : [];
		if (empty($quality['pass']) || (int) ($quality['score'] ?? 0) < 78) {
			return false;
		}
		$profile = self::payload_story_budget_profile($payload);
		$content_plain = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));
		$de_min = max(120, (int) ($profile['de']['content_min_chars'] ?? 320));
		$source_count = (int) ($payload['_meta']['source_count'] ?? 0);
		if ($source_count <= 0) {
			return false;
		}
		return mb_strlen($content_plain) >= $de_min;
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

	private static function finalize_payload_for_queue(array $payload): array {
		$payload = self::normalize_payload_quotes($payload);
		$payload = EPV2_AI_Response_Validator::enrich_payload($payload);
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload = self::align_selection_with_payload_category($payload);
		$payload['_meta']['quality'] = EPV2_AI_Response_Validator::editorial_quality($payload);
		$payload['_meta']['seo_quality'] = EPV2_AI_Response_Validator::seo_quality($payload);
		$payload['_meta']['release_quality'] = EPV2_AI_Response_Validator::release_quality($payload);
		$payload['_meta']['google_quality'] = EPV2_AI_Response_Validator::google_preflight_quality($payload);
		$payload['_meta']['context_memory'] = self::payload_context_memory($payload);
		return self::refresh_stage_checklist($payload);
	}

	private static function refresh_stage_checklist(array $payload): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$context = is_array($payload['_meta']['context_analysis'] ?? null) ? $payload['_meta']['context_analysis'] : [];
		$uk_ready = self::payload_language_ready($payload, 'uk');
		$en_ready = self::payload_language_ready($payload, 'en');
		// Stage routing must stay cheap and deterministic. Full publish-grade media validation
		// happens later on the final gate, not while selecting the next processing stage.
		$ready_publish_fast = self::payload_is_publish_ready_fast($payload);
		$completed = [
			'source_received' => true,
			'initial_analysis_done' => ! empty($payload['_meta']['selection']),
			'context_saved' => ! empty($payload['_meta']['context_memory']),
			'context_analysis_done' => $context !== [],
			'dossier_built' => $dossier !== [],
			'de_master_ready' => self::de_master_is_viable_fast($payload),
			'uk_ready' => $uk_ready,
			'en_ready' => $en_ready,
			'translations_ready' => $uk_ready && $en_ready && empty($payload['_meta']['translations_deferred']),
			'publish_finish_ready' => self::de_master_ready_for_translation($payload),
			'ready_publish' => $ready_publish_fast,
		];
		$payload['_meta']['stage_checklist'] = $completed;
		return $payload;
	}

	private static function payload_stage_checklist(array $payload): array {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		return is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
	}

	private static function payload_next_required_stage(array $payload): string {
		if ($payload === [] || self::payload_context_rejects($payload)) {
			return '';
		}
		$checklist = self::payload_stage_checklist(self::refresh_stage_checklist($payload));
		$requires_rebuild_fast = self::payload_requires_fresh_rebuild_fast($payload);
		if (empty($checklist['de_master_ready'])) {
			return 'rebuild_bundle';
		}
		if (empty($checklist['uk_ready'])) {
			return 'translate_uk';
		}
		if (empty($checklist['en_ready'])) {
			return 'translate_en';
		}
		if (empty($checklist['publish_finish_ready'])) {
			return $requires_rebuild_fast ? 'rebuild_bundle' : 'publish_finish';
		}
		if (! self::payload_is_review_ready_fast($payload)) {
			return $requires_rebuild_fast ? 'rebuild_bundle' : 'publish_finish';
		}
		if (empty($checklist['ready_publish'])) {
			return $requires_rebuild_fast ? 'rebuild_bundle' : 'publish_finish';
		}
		return '';
	}

	private static function de_master_is_viable_fast(array $payload): bool {
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		if ($de === []) {
			return false;
		}
		return trim((string) ($de['title'] ?? '')) !== ''
			&& trim((string) ($de['excerpt'] ?? '')) !== ''
			&& trim(wp_strip_all_tags((string) ($de['content'] ?? ''))) !== '';
	}

	private static function payload_language_ready(array $payload, string $lang): bool {
		$lang = in_array($lang, ['uk', 'en'], true) ? $lang : '';
		if ($lang === '') {
			return false;
		}
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$candidate = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
		if ($de === [] || $candidate === []) {
			return false;
		}
		return ! self::needs_language_repair($de, $candidate, $lang);
	}

	private static function payload_context_memory(array $payload): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$existing = is_array($payload['_meta']['context_memory'] ?? null) ? $payload['_meta']['context_memory'] : [];
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$event = is_array($dossier['event_context'] ?? null) ? $dossier['event_context'] : [];
		$story = is_array($dossier['story_context'] ?? null) ? $dossier['story_context'] : [];
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$selection = is_array($payload['_meta']['selection'] ?? null) ? $payload['_meta']['selection'] : [];
		$de_content = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) ($de['content'] ?? ''))) ?: '');
		$de_content_snippet = $de_content !== '' ? sanitize_text_field((string) mb_substr($de_content, 0, 260)) : '';

		$memory = [
			'kind' => sanitize_key((string) ($event['kind'] ?? '')),
			'event_title' => sanitize_text_field((string) (($de['title'] ?? '') !== '' ? ($de['title'] ?? '') : ($event['event_title'] ?? ''))),
			'participants' => array_values(array_filter(array_map('sanitize_text_field', (array) ($event['participants'] ?? [])))),
			'datetime_text' => sanitize_text_field((string) ($event['datetime_text'] ?? '')),
			'venue' => sanitize_text_field((string) ($event['venue'] ?? '')),
			'stage' => sanitize_text_field((string) ($event['stage'] ?? '')),
			'referee' => sanitize_text_field((string) ($event['referee'] ?? '')),
			'head_to_head' => sanitize_text_field((string) ($event['head_to_head'] ?? '')),
			'next_step' => sanitize_text_field((string) ($event['next_step'] ?? '')),
			'fact_snippets' => array_values(array_filter(array_map('sanitize_text_field', (array) ($event['fact_snippets'] ?? [])))),
			'search_terms' => array_values(array_filter(array_map('sanitize_text_field', array_merge(
				(array) ($event['search_terms'] ?? []),
				(array) ($story['search_terms'] ?? []),
				(array) ($story['entities'] ?? []),
				(array) ($story['theme_tokens'] ?? []),
				(array) ($story['body_keywords'] ?? []),
				array_values(array_filter([
					(string) ($de['title'] ?? ''),
					(string) ($de['excerpt'] ?? ''),
					(string) ($de['title'] ?? ''),
					(string) ($story['body_snippet'] ?? ''),
					$de_content_snippet,
					(string) ($selection['category'] ?? ''),
				]))
			)))),
		];

		foreach (['participants', 'fact_snippets', 'search_terms'] as $field) {
			$memory[$field] = array_slice(array_values(array_unique(array_filter(array_merge(
				(array) ($memory[$field] ?? []),
				(array) ($existing[$field] ?? [])
			)))), 0, $field === 'search_terms' ? 8 : 6);
		}
		if (! empty($de['title'])) {
			$memory['search_terms'] = self::prioritize_search_terms_for_de((array) ($memory['search_terms'] ?? []));
		}

		foreach (['kind', 'event_title', 'datetime_text', 'venue', 'stage', 'referee', 'head_to_head', 'next_step'] as $field) {
			if (empty($memory[$field]) && ! empty($existing[$field])) {
				$memory[$field] = sanitize_text_field((string) $existing[$field]);
			}
		}

		return array_filter($memory, static function ($value): bool {
			if (is_array($value)) {
				return $value !== [];
			}
			return trim((string) $value) !== '';
		});
	}

	private static function align_selection_with_payload_category(array $payload): array {
		$primary_category = sanitize_title((string) ((array) ($payload['categories'] ?? ['']))[0]);
		if ($primary_category === '') {
			return $payload;
		}
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload['_meta']['selection'] = is_array($payload['_meta']['selection'] ?? null) ? $payload['_meta']['selection'] : [];
		$current_category = sanitize_title((string) ($payload['_meta']['selection']['category'] ?? ''));
		if ($current_category !== '' && $current_category !== $primary_category && empty($payload['_meta']['selection']['initial_category'])) {
			$payload['_meta']['selection']['initial_category'] = $current_category;
		}
		$payload['_meta']['selection']['category'] = $primary_category;
		return $payload;
	}

	private static function attempt_publish_grade_lift(object $item, array $payload, array $categories, string $style, bool $de_only = false): array {
		return $de_only
			? self::lift_de_master_to_publish_grade($item, $payload, $categories, $style)
			: self::lift_multilingual_payload_to_publish_grade($item, $payload, $categories, $style);
	}

	private static function lift_de_master_to_publish_grade(object $item, array $payload, array $categories, string $style): array {
		$payload = self::normalize_existing_payload($payload, false);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$has_fresh_dossier = ! empty($meta['source_dossier']) && (int) ($meta['source_count'] ?? 0) >= 2;
		$needs_rebuild = self::payload_needs_enrichment_rebuild($payload);
		$needs_context_refresh = self::payload_publish_finish_needs_context_refresh($payload);
		if (($needs_rebuild || $needs_context_refresh) && ! $has_fresh_dossier) {
			return self::refresh_payload_context_for_publish_lift($item, $payload, $categories);
		}
		$release_text = mb_strtolower(implode(' | ', self::warning_strings($payload['_meta']['release_quality']['warnings'] ?? [])));
		$needs_media_repair = ! self::payload_has_media_candidate($payload)
			|| ! self::payload_featured_media_is_publishable($payload)
			|| preg_match('/generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|нет featured media|нет главного изображения/u', $release_text) === 1;
		if ($needs_media_repair) {
			return self::repair_payload_media($item, $payload);
		}

		$release_warnings = array_map('strval', (array) ($meta['release_quality']['warnings'] ?? []));
		$de_requires_lift = false;
		foreach ($release_warnings as $warning) {
			if (preg_match('/^(DE|UK|EN): .*?(слишком коротким|сломанная языковая версия)/iu', $warning, $matches)) {
				$lang = strtolower((string) $matches[1]);
				if ($lang === 'de') {
					$de_requires_lift = true;
				}
			}
		}

		if ($de_requires_lift) {
			foreach (['content', 'excerpt', 'title'] as $field) {
				$value = self::generate_single_field($item, $payload, 'de', $field, $style, $categories);
				if ($value !== '' && $value !== (string) ($payload['languages']['de'][$field] ?? '')) {
					$payload['languages']['de'][$field] = $value;
				}
			}
			return self::finalize_payload_for_queue($payload);
		}

		return self::finalize_payload_for_queue($payload);
	}

	private static function lift_multilingual_payload_to_publish_grade(object $item, array $payload, array $categories, string $style): array {
		$before_de = md5((string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE));
		$payload = self::lift_de_master_to_publish_grade($item, $payload, $categories, $style);
		$after_de = md5((string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE));
		if ($before_de !== $after_de && ! self::payload_is_publish_ready($payload)) {
			return self::refresh_stage_checklist($payload);
		}

		$target_langs = [];
		$uk = is_array($payload['languages']['uk'] ?? null) ? $payload['languages']['uk'] : [];
		$en = is_array($payload['languages']['en'] ?? null) ? $payload['languages']['en'] : [];
		if ($uk === [] || ! self::language_package_matches_target(
			self::normalized_language_field($uk, 'title') . ' ' . self::normalized_language_field($uk, 'excerpt') . ' ' . self::normalized_language_field($uk, 'content'),
			'uk'
		)) {
			$target_langs[] = 'uk';
		}
		if ($en === [] || ! self::language_package_matches_target(
			self::normalized_language_field($en, 'title') . ' ' . self::normalized_language_field($en, 'excerpt') . ' ' . self::normalized_language_field($en, 'content'),
			'en'
		)) {
			$target_langs[] = 'en';
		}

		$release_warnings = array_map('strval', (array) ($payload['_meta']['release_quality']['warnings'] ?? []));
		foreach ($release_warnings as $warning) {
			if (preg_match('/^(UK|EN): .*?(слишком коротким|сломанная языковая версия)/iu', $warning, $matches)) {
				$target_langs[] = strtolower((string) $matches[1]);
			}
		}

		$target_langs = array_values(array_unique(array_filter($target_langs)));
		if ($target_langs !== []) {
			return self::finalize_payload_for_queue(self::repair_payload_language($payload, (string) $target_langs[0]));
		}

		$field_plan = self::publish_finish_field_plan($payload);
		foreach ($field_plan as $lang => $fields) {
			if ($lang === 'de') {
				continue;
			}
			foreach ($fields as $field) {
				$value = self::generate_single_field($item, $payload, $lang, $field, $style, $categories);
				if ($value === '') {
					continue;
				}
				$payload['languages'][$lang][$field] = $value;
			}
			return self::finalize_payload_for_queue($payload);
		}

		return self::finalize_payload_for_queue($payload);
	}

	private static function payload_publish_finish_needs_context_refresh(array $payload): bool {
		$payload = self::finalize_payload_for_queue($payload);
		if (! self::payload_is_review_ready($payload) || self::payload_is_publish_ready($payload)) {
			return false;
		}

		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$source_count = (int) ($meta['source_count'] ?? 0);
		$warnings = array_merge(
			self::warning_strings($meta['quality']['warnings'] ?? []),
			self::warning_strings($meta['release_quality']['warnings'] ?? []),
			self::warning_strings($meta['google_quality']['warnings'] ?? [])
		);
		$text = mb_strtolower(implode(' | ', $warnings));

		if (
			$source_count <= 1
			&& preg_match('/слабое досье источников|нет featured media|нет главного изображения|generic stock featured media|слишком слабое.*featured media|не соответствует теме материала/u', $text) === 1
		) {
			return true;
		}

		if (! self::payload_has_media_candidate($payload) && $source_count <= 2) {
			return true;
		}

		return false;
	}

	private static function refresh_payload_context_for_publish_lift(object $item, array $payload, array $categories): array {
		$dossier = EPV2_Source_Enricher::enrich_item($item, [
			'force_supporting' => true,
			'target_supporting' => 2,
			'max_runtime_seconds' => 5,
			'context_memory' => self::payload_context_memory($payload),
		]);
		if ($dossier === []) {
			return self::finalize_payload_for_queue($payload);
		}

		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$blocked = array_values(array_filter(array_map('strval', (array) ($payload['_meta']['blocked_media_urls'] ?? []))));
		if ($blocked !== []) {
			$dossier = self::prune_blocked_media_from_dossier($dossier, $blocked);
		}
		$payload['_meta']['source_dossier'] = $dossier;
		$payload['_meta']['source_count'] = 1 + count((array) ($dossier['supporting'] ?? []));
		$payload['_meta']['context_memory'] = self::payload_context_memory($payload);

		$title = (string) ($dossier['primary']['title'] ?? $item->original_title ?? '');
		$content = (string) ($dossier['primary']['content'] ?? $item->original_content ?? '');
		$seed = (string) ($categories[0] ?? self::working_category_seed($item, $payload));
		$detected = EPV2_Categorizer::detect($title, $content, $seed);
		$refined = EPV2_Categorizer::refine_with_event_context($detected !== '' ? $detected : $seed, $dossier, $title, $content);
		if ($refined !== '') {
			$payload['categories'] = EPV2_Review::normalize_categories($refined);
		}

		if (self::payload_featured_media_is_generic_stock($payload)) {
			$payload['featured_media_url'] = '';
			$payload['media_url'] = '';
			foreach (['de', 'uk', 'en'] as $lang) {
				if (is_array($payload['languages'][$lang] ?? null)) {
					$payload['languages'][$lang]['media_url'] = '';
				}
			}
		}

		return self::finalize_payload_for_queue($payload);
	}

	private static function payload_featured_media_is_generic_stock(array $payload): bool {
		$url = self::payload_primary_media_url($payload);
		if ($url === '') {
			return false;
		}
		$host = (string) wp_parse_url($url, PHP_URL_HOST);
		if ($host === '') {
			return false;
		}
		foreach (['pexels.com', 'images.pexels.com', 'commons.wikimedia.org', 'upload.wikimedia.org'] as $signal) {
			if (str_contains($host, $signal)) {
				return true;
			}
		}
		return false;
	}

	private static function warning_requires_language_lift(array $warnings, string $lang): bool {
		$prefix = strtoupper($lang);
		foreach ($warnings as $warning) {
			if (preg_match('/^' . preg_quote($prefix, '/') . ': .*?(слишком коротким|сломанная языковая версия)/iu', (string) $warning) === 1) {
				return true;
			}
		}
		return false;
	}

	private static function publish_finish_field_plan(array $payload): array {
		$plan = [];
		$seo_warnings = is_array($payload['_meta']['seo_quality']['warnings'] ?? null) ? $payload['_meta']['seo_quality']['warnings'] : [];
		$google_warnings = array_map('strval', (array) ($payload['_meta']['google_quality']['warnings'] ?? []));

		foreach (['de', 'uk', 'en'] as $lang) {
			$lang_warnings = array_map('strval', (array) ($seo_warnings[$lang] ?? []));
			$prefix = strtoupper($lang) . ':';
			foreach ($google_warnings as $warning) {
				if (str_starts_with($warning, $prefix)) {
					$lang_warnings[] = trim(mb_substr($warning, mb_strlen($prefix)));
				}
			}
			$fields = [];
			foreach ($lang_warnings as $warning) {
				$text = mb_strtolower(trim($warning));
				if ($text === '') {
					continue;
				}
				if (preg_match('/главный ключ не попадает в title|нет seo title|слабый seo title|seo title не в оптимальной длине/u', $text) === 1) {
					$fields[] = 'seo_title';
				}
				if (preg_match('/главный ключ не попадает в lead|meta description|слишком слабый lead|snippet/u', $text) === 1) {
					$fields[] = 'meta_description';
				}
				if (preg_match('/главный ключ слабо встроен в текст|материал может быть слишком поверхностным/u', $text) === 1) {
					$fields[] = 'content';
				}
				if (preg_match('/отсутствует slug|нет slug/u', $text) === 1) {
					$fields[] = 'slug';
				}
			}
			$fields = array_values(array_unique(array_filter($fields)));
			if ($fields !== []) {
				$plan[$lang] = $fields;
			}
		}

		return $plan;
	}

	private static function normalize_payload_quotes(array $payload): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if (is_array($payload['_meta']['source_dossier'] ?? null)) {
			$payload['_meta']['source_dossier']['quotes'] = array_values(array_filter((array) ($payload['_meta']['source_dossier']['quotes'] ?? []), static function ($quote): bool {
				if (! is_array($quote)) {
					return false;
				}
				$text = trim((string) ($quote['text'] ?? ''));
				if (mb_strlen($text) < 35) {
					return false;
				}
				return ! self::quote_is_unsafe_for_translation_lift($quote);
			}));
		}
		foreach (['de', 'uk', 'en'] as $lang) {
			if (! is_array($payload['languages'][$lang] ?? null)) {
				continue;
			}
			foreach (['title', 'excerpt', 'content', 'seo_title', 'meta_description'] as $field) {
				$value = (string) ($payload['languages'][$lang][$field] ?? '');
				if ($value === '') {
					continue;
				}
				$value = preg_replace('/(?<!\w)‚([^‚‘]{2,}?)‘(?!\w)/u', '„$1“', $value) ?: $value;
				$value = preg_replace('/(?<!\w)‘([^‘’]{2,}?)’(?!\w)/u', '“$1”', $value) ?: $value;
				if ($field === 'content') {
					$value = self::strip_unsafe_quote_blocks($value);
				}
				$payload['languages'][$lang][$field] = $value;
			}
		}
		return $payload;
	}

	private static function strip_unsafe_quote_blocks(string $content): string {
		if ($content === '' || stripos($content, '<blockquote') === false) {
			return $content;
		}
		$content = preg_replace('/<blockquote\b[^>]*>.*?(newsletter|anmeldung|postfach|sign up here|private inbox|volltextsuche|symbolbild|der schriftzug|europäische perspektive|europaeische perspektive|innen mit der|spreewasser).*?<\/blockquote>\s*/isu', '', $content) ?: $content;
		$content = preg_replace('/<blockquote\b[^>]*>\s*<p>[^<]{1,220}<\/p>\s*<cite>\s*(?:Watson(?:\.de)?|BR|Goal(?:\.com)?(?:\s+Deutschland)?|Kritik\s*[—-]\s*Br)\s*<\/cite>\s*<\/blockquote>\s*$/isu', '', $content) ?: $content;
		return $content;
	}

	private static function automation_requires_publish_grade(): bool {
		$mode = (string) EPV2_Settings::get('mode', 'semi');
		$default_status = (string) EPV2_Settings::get('default_post_status', 'draft');
		return $mode === 'auto' && in_array($default_status, ['publish', 'pending'], true);
	}

	private static function build_messages(object $item, array $categories, string $style, array $dossier = [], bool $reduced_context = false): array {
		$categories = array_values(array_filter($categories));
		$prompts = EPV2_Settings::get('prompts', []);
		$story_format = sanitize_text_field((string) ($item->story_format ?? ''));
		$zone = in_array($story_format, ['analysis', 'developing'], true) ? $story_format : 'news';
		$primary_category = (string) ($categories[0] ?? self::working_category_seed($item));
		$budget_context = [
			'source_count' => 1 + count((array) ($dossier['supporting'] ?? [])),
			'event_kind' => (string) ($dossier['event_context']['kind'] ?? ''),
			'title' => (string) $item->original_title,
			'excerpt' => (string) $item->original_excerpt,
			'content' => (string) $item->original_content,
			'datetime_text' => (string) ($dossier['event_context']['datetime_text'] ?? ''),
			'venue' => (string) ($dossier['event_context']['venue'] ?? ''),
			'stage' => (string) ($dossier['event_context']['stage'] ?? ''),
		];
		$budget_de = EPV2_Site_Profile::text_budget('de', $zone, $primary_category, $budget_context);
		$budget_uk = EPV2_Site_Profile::text_budget('uk', $zone, $primary_category, $budget_context);
		$budget_en = EPV2_Site_Profile::text_budget('en', $zone, $primary_category, $budget_context);
		$custom_prompt = $story_format === 'analysis'
			? (string) ($prompts['analysis_rewrite'] ?? $prompts['auto_rewrite'] ?? $prompts['news_default'] ?? '')
			: (string) ($prompts['auto_rewrite'] ?? $prompts['news_default'] ?? '');
		$topic_label = sanitize_text_field((string) ($item->topic_label ?? ''));
		$use_source_url = ! empty(EPV2_Settings::get('gemini_use_source_url_in_prompt', true));
		$require_citations = ! empty(EPV2_Settings::get('gemini_require_citations', false));
		$style_hint = match ($style) {
			'analytic' => 'Стиль: спокойный аналитический newsroom уровня Deutsche Welle, BBC или Reuters, но всё равно живой и ясный. Аналитика не должна превращаться в канцелярский отчёт. Нужны естественные переходы, короткие понятные предложения и человеческий ритм.',
			'lively' => 'Стиль: обычная сильная новостная статья уровня Deutsche Welle, Tagesschau, BBC, AP или CNN. Тон живой, лёгкий, интересный, но профессиональный. Заголовок короткий и цепляющий по сути, без жёсткого кликбейта. Лид ровно в 2 предложениях. Текст начинается с проблемы, изменения или последствия для читателя. Предложения в основном короткие или средние, без громоздких конструкций.',
			default => 'Стиль: обычный сильный newsroom-материал уровня Tagesschau, BBC, AP или Reuters. Не сухой, не бюрократический, не похожий на пресс-релиз. Пиши короткими, понятными предложениями и держи живой темп текста.',
		};
		$format_hint = match ($story_format) {
			'developing' => 'Формат: developing story. Покажи развитие темы в плавном новостном тексте. Не дели статью на шаблонные секции с подписями вроде "Контекст", "Почему это важно", "Что дальше".',
			'analysis' => 'Формат: weekly analysis. Построй более крупный аналитический материал как нормальную журналистскую статью, а не как отчёт по шаблону. Не дели текст на формальные блоки с подписями "Контекст", "Расширенный контекст", "Почему это важно".',
			default => '',
		};
		$url_hint = $use_source_url ? 'При анализе учитывай URL первоисточника и, если инструмент модели это поддерживает, используй web/url context для проверки фактов.' : '';
		$citation_hint = $require_citations ? 'Если провайдер умеет grounding, опирайся на него. Внутрь текста статьи цитаты не вставляй, но допускается вернуть ссылки/опоры в raw-метаданных ответа.' : '';
		$shape_hint = match ((string) ($budget_de['shape'] ?? 'news')) {
			'bulletin' => 'Это короткая информационная заметка. Нормально, если финальный DE-текст займёт 1-2 плотных абзаца без воды: важны точность, польза и ясность, а не искусственная длина.',
			'service_note' => 'Это сервисная или community-заметка. Нужны конкретика, сроки, место, последствия для читателя и практическая польза. Не раздувай текст пустым контекстом.',
			'preview' => 'Это preview/event-материал. Если подтверждено, добавь когда, где, стадия, участники, что дальше и при желании короткий дополнительный контекст.',
			'article' => 'Это полноценная новость-статья, а не короткая заметка. Нужен плотный, но не раздутый материал с ясным объяснением последствий.',
			default => 'Длина должна соответствовать реальной наполненности материала, а не искусственному минимуму символов.',
		};
		$source_hint = 'Если исходный сигнал короткий или бедный, обязательно усили материал на основе первоисточника и ещё 1-3 подтверждающих публикаций из досье. Старайся ссылаться по смыслу на первоисточник и опираться именно на него как на основную фактуру. Если в досье есть короткая подтверждённая цитата с атрибуцией, используй одну такую цитату естественно внутри текста, а не как служебный блок. Когда в статье появляется прямая речь, указывай не только автора, но и площадку или контекст: например, что человек заявил это в интервью конкретному изданию, в заявлении для конкретного источника или по данным конкретной публикации. Не повторяй такую отсылку в каждом абзаце, но не оставляй цитату без ясной привязки. Если в source_dossier.event_context есть подтверждённые детали события, используй их естественно и только по делу: когда проходит матч или событие, где оно проходит, кто участвует, какая стадия, кто судит, что ждёт победителя дальше. Не выдумывай отсутствующие детали и не перенасыщай текст спортивным или сервисным фоном. Исходные тексты и сигналы могут быть на любом языке, но итоговый мастер-текст должен быть нормальным немецким newsroom-материалом без следов исходного языка. ' . $shape_hint;
		$original_excerpt = self::trim_input_text((string) $item->original_excerpt, 1200);
		$original_content = self::trim_input_text((string) $item->original_content, $reduced_context ? 4500 : 9000);
		$input_dossier = self::compact_source_dossier($dossier, $reduced_context);
		return [
				[
					'role' => 'system',
					'content' => 'Ты редакционный AI для новостного сайта EuroPulse. Верни только JSON. Не добавляй комментарии. Не копируй исходный текст дословно. Сначала создай сильный мастер-материал только на немецком языке. Пиши как современное европейское цифровое медиа: профессионально, ясно, живо и плавно. Запрещены канцелярит, чиновничья сухость, язык пресс-релиза, советский бюллетень и рубленая структура из служебных подзаголовков. Не пиши блоками вида "Почему это важно:", "Контекст:", "Расширенный контекст:", "Что дальше:". Вместо этого строй цельную статью с естественными переходами, как в DW, Tagesschau, BBC, CNN, Al Jazeera. Начинай материал с проблемы, изменения, риска или главного последствия для читателя. Лид должен состоять ровно из двух предложений и сразу объяснять, о чём статья и почему это важно. Заголовок должен быть коротким, хлёстким и смысловым, без дешёвого кликбейта. Не перегружай текст полными официальными названиями законов и номерами параграфов, если это можно передать человеческим языком без потери точности. Для обычной новости не раздувай длину искусственно: если фактуры немного, лучше 3-5 сильных абзацев с плотной информацией, чем длинный пустой текст. Нужны человеческий ритм, сильный лид, понятные переходы, практическая польза для читателя и ясное объяснение, почему тема важна. Избегай длинных предложений: предпочитай короткие и средние конструкции. Соблюдай реальные лимиты интерфейса сайта: заголовки и лиды должны помещаться в карточки и слайдер без грязного обрезания. Если текст не помещается, не обрубай смысл, а переформулируй короче и чище. Особенно строго следи за украинской версией: она должна полностью влезать в самые узкие карточки сайта без троеточий и обрубленных хвостов. ' . $style_hint . ' ' . $format_hint . ' ' . $url_hint . ' ' . $citation_hint . ' ' . $source_hint . ' ' . $custom_prompt . ' Формат JSON: {"categories":["slug1","slug2"],"media_url":"...","languages":{"de":{"title":"","excerpt":"","content":"","media_url":""}}}',
				],
			[
				'role' => 'user',
				'content' => wp_json_encode([
					'suggested_categories' => $categories,
					'original_title' => (string) $item->original_title,
					'original_excerpt' => $original_excerpt,
						'original_content' => $original_content,
						'original_url' => (string) $item->original_url,
						'source_dossier' => $input_dossier,
						'image_url' => (string) $item->source_image_url,
						'topic_label' => $topic_label,
						'story_format' => $story_format,
						'instructions' => [
							'categories_max' => 3,
							'de_title_max_chars' => $budget_de['title_chars'],
						'uk_title_max_chars' => $budget_uk['title_chars'],
						'en_title_max_chars' => $budget_en['title_chars'],
							'excerpt_max_chars' => $budget_de['lead_chars'],
							'excerpt_card_max_chars_de' => $budget_de['card_excerpt_chars'],
							'excerpt_card_max_chars_uk' => $budget_uk['card_excerpt_chars'],
							'excerpt_card_max_chars_en' => $budget_en['card_excerpt_chars'],
							'lead_sentences' => 2,
							'content_style' => $style,
							'rewrite_depth' => 'deep_factual_rewrite',
							'opening_mode' => 'start_with_problem_or_consequence',
							'sentence_length' => 'short_to_medium',
							'analysis_sources_target' => $story_format === 'analysis' ? 5 : 0,
							'story_shape' => (string) ($budget_de['shape'] ?? 'news'),
							'de_content_min_chars' => (int) ($budget_de['content_min_chars'] ?? 700),
							'de_content_target_chars' => (int) ($budget_de['content_target_chars'] ?? 1500),
						],
					], JSON_UNESCAPED_UNICODE),
			],
		];
	}

	private static function clean_json_response(string $text): string {
		$text = trim($text);
		if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches)) {
			$text = trim((string) $matches[1]);
		}
		return $text;
	}

	private static function attempt_ai_payload(array $config, object $item, array $categories, string $style, array $dossier = [], bool $defer_translations = false): ?array {
		$last_error = null;
		foreach ([false, true] as $reduced_context) {
			try {
				EPV2_Logger::info('ai', 'Review payload AI attempt', [
					'provider' => (string) ($config['provider'] ?? ''),
					'model' => (string) ($config['model'] ?? ''),
					'reduced_context' => $reduced_context ? 1 : 0,
					'timeout' => (int) ($config['timeout'] ?? 0),
				]);
				self::heartbeat_active_process_lock();
				$result = EPV2_AI_Client::generate($config, self::build_messages($item, $categories, $style, $dossier, $reduced_context));
				self::heartbeat_active_process_lock();
				EPV2_Logger::info('ai', 'Review payload AI response', [
					'provider' => (string) ($config['provider'] ?? ''),
					'model' => (string) ($config['model'] ?? ''),
					'reduced_context' => $reduced_context ? 1 : 0,
					'tokens' => (int) ($result['tokens'] ?? 0),
				]);
				$data = json_decode(self::clean_json_response((string) $result['text']), true);
				if (! is_array($data)) {
					$last_error = new RuntimeException('AI JSON invalid');
					continue;
				}
				if (! empty($data['de']) && empty($data['languages'])) {
					$data = [
						'languages' => $data,
						'categories' => $categories,
						'media_url' => (string) ($item->source_image_url ?? ''),
					];
				}
				$data = EPV2_AI_Response_Validator::enrich_payload($data);
				$data['categories'] = EPV2_Review::normalize_categories(implode(',', $data['categories'] ?? $categories));
				$de_seed = EPV2_Review::build_language_package($item, 'de', $style);
				$data['languages']['de'] = array_merge(
					$de_seed,
					is_array($data['languages']['de'] ?? null) ? $data['languages']['de'] : []
				);
				$data['languages']['de']['title'] = sanitize_text_field((string) ($data['languages']['de']['title'] ?? ''));
				$data['languages']['de']['excerpt'] = sanitize_textarea_field((string) ($data['languages']['de']['excerpt'] ?? ''));
				$data['languages']['de']['content'] = wp_kses_post((string) ($data['languages']['de']['content'] ?? ''));
				$data['media_url'] = esc_url_raw((string) ($data['media_url'] ?? $data['languages']['de']['media_url'] ?? $item->source_image_url ?? ''));
				$data['languages']['de']['media_url'] = esc_url_raw((string) ($data['languages']['de']['media_url'] ?? $data['media_url']));
				foreach (['uk', 'en'] as $lang) {
					$data['languages'][$lang] = EPV2_Review::empty_language_package($lang, (string) $data['media_url']);
				}
				$data['_meta'] = is_array($data['_meta'] ?? null) ? $data['_meta'] : [];
				$data['_meta']['canonical_language'] = 'de';
				if (! $defer_translations) {
					$data = self::repair_language_variants($data, $config);
				} else {
					$data['_meta']['translations_deferred'] = true;
				}
				$data = self::lift_payload_from_dossier($data, $dossier);
				$data = EPV2_AI_Response_Validator::enrich_payload($data);
				$validation = EPV2_AI_Response_Validator::validate($data);
				if (! $validation['valid']) {
					$last_error = new RuntimeException('AI payload validation failed');
					continue;
				}
				$data['_meta'] = [
					'provider' => (string) ($config['provider'] ?? ''),
					'model' => (string) ($config['model'] ?? ''),
					'tokens' => (int) ($result['tokens'] ?? 0),
					'style' => $style,
					'canonical_language' => 'de',
					'breaking' => ! empty($data['_meta']['breaking']),
					'top_story' => ! empty($data['_meta']['top_story']),
					'source_dossier' => $dossier,
					'source_count' => 1 + count((array) ($dossier['supporting'] ?? [])),
					'breaking_hours' => max(1, min(24, (int) ($data['_meta']['breaking_hours'] ?? EPV2_Settings::get('auto_breaking_hours', 6)))),
					'grounding' => ! empty($result['grounding_metadata']) ? $result['grounding_metadata'] : [],
					'url_context' => ! empty($result['url_context_metadata']) ? $result['url_context_metadata'] : [],
					'quality' => $validation['quality'] ?? [],
					'seo_quality' => $validation['seo'] ?? [],
					'release_quality' => $validation['release'] ?? [],
					'google_quality' => $validation['google'] ?? [],
					'context_mode' => $reduced_context ? 'reduced' : 'full',
					'translations_deferred' => $defer_translations,
					'pipeline_stage' => $defer_translations ? 'translate_finish' : '',
				];
				return $data;
			} catch (Throwable $e) {
				EPV2_Logger::warning('ai', 'Review payload AI failed', [
					'provider' => (string) ($config['provider'] ?? ''),
					'model' => (string) ($config['model'] ?? ''),
					'reduced_context' => $reduced_context ? 1 : 0,
					'error' => $e->getMessage(),
				]);
				$last_error = $e;
				if (! $reduced_context && preg_match('/timeout|timed out|cURL error 28/i', $e->getMessage())) {
					continue;
				}
				throw $e;
			}
		}
		if ($last_error !== null) {
			throw $last_error;
		}
		return null;
	}

	private static function trim_input_text(string $text, int $limit): string {
		$text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', trim($text)) ?: trim($text);
		if ($text === '' || mb_strlen($text) <= $limit) {
			return $text;
		}
		$cut = mb_substr($text, 0, $limit);
		$space = mb_strrpos($cut, ' ');
		if ($space !== false) {
			$cut = mb_substr($cut, 0, $space);
		}
		return trim($cut);
	}

	private static function compact_source_dossier(array $dossier, bool $reduced_context): array {
		if ($dossier === []) {
			return [];
		}
		$compact = [];
		$primary = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
		if ($primary !== []) {
			$compact['primary'] = [
				'title' => self::trim_input_text((string) ($primary['title'] ?? ''), 240),
				'url' => (string) ($primary['url'] ?? ''),
				'excerpt' => self::trim_input_text((string) ($primary['excerpt'] ?? $primary['content'] ?? ''), $reduced_context ? 320 : 700),
			];
		}
		$supporting = [];
		foreach (array_slice((array) ($dossier['supporting'] ?? []), 0, $reduced_context ? 2 : 3) as $source) {
			if (! is_array($source)) {
				continue;
			}
			$supporting[] = [
				'title' => self::trim_input_text((string) ($source['title'] ?? ''), 220),
				'url' => (string) ($source['url'] ?? ''),
				'excerpt' => self::trim_input_text((string) ($source['excerpt'] ?? $source['content'] ?? ''), $reduced_context ? 220 : 420),
			];
		}
		if ($supporting !== []) {
			$compact['supporting'] = $supporting;
		}
		$quotes = [];
		foreach (array_slice((array) ($dossier['quotes'] ?? []), 0, 3) as $quote) {
			if (! is_array($quote)) {
				continue;
			}
			$text = self::trim_input_text((string) ($quote['text'] ?? ''), $reduced_context ? 160 : 240);
			if ($text === '') {
				continue;
			}
			$quotes[] = [
				'text' => $text,
				'speaker' => self::trim_input_text((string) ($quote['speaker'] ?? ''), 80),
				'source_name' => self::trim_input_text((string) ($quote['source_name'] ?? ''), 80),
				'attribution' => self::trim_input_text((string) ($quote['attribution'] ?? ''), 120),
				'url' => (string) ($quote['url'] ?? ''),
			];
		}
		if ($quotes !== []) {
			$compact['quotes'] = $quotes;
		}
		$event_context = is_array($dossier['event_context'] ?? null) ? $dossier['event_context'] : [];
		if ($event_context !== []) {
			$compact['event_context'] = array_filter([
				'kind' => sanitize_text_field((string) ($event_context['kind'] ?? '')),
				'event_title' => self::trim_input_text((string) ($event_context['event_title'] ?? ''), 180),
				'participants' => array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($event_context['participants'] ?? [])))), 0, 4),
				'datetime_text' => self::trim_input_text((string) ($event_context['datetime_text'] ?? ''), 120),
				'venue' => self::trim_input_text((string) ($event_context['venue'] ?? ''), 120),
				'stage' => self::trim_input_text((string) ($event_context['stage'] ?? ''), 80),
				'referee' => self::trim_input_text((string) ($event_context['referee'] ?? ''), 80),
				'head_to_head' => self::trim_input_text((string) ($event_context['head_to_head'] ?? ''), $reduced_context ? 120 : 180),
				'next_step' => self::trim_input_text((string) ($event_context['next_step'] ?? ''), $reduced_context ? 120 : 180),
				'fact_snippets' => array_slice(array_values(array_filter(array_map(static fn($value): string => self::trim_input_text((string) $value, $reduced_context ? 120 : 180), (array) ($event_context['fact_snippets'] ?? [])))), 0, $reduced_context ? 2 : 4),
			], static function ($value): bool {
				if (is_array($value)) {
					return $value !== [];
				}
				return trim((string) $value) !== '';
			});
		}
		return $compact;
	}

	private static function lift_payload_from_dossier(array $payload, array $dossier): array {
		$quotes = array_values(array_filter((array) ($dossier['quotes'] ?? []), static function ($quote): bool {
			if (! is_array($quote)) {
				return false;
			}
			$text = trim((string) ($quote['text'] ?? ''));
			if (mb_strlen($text) < 35) {
				return false;
			}
			return ! self::quote_is_unsafe_for_translation_lift($quote);
		}));
		if ($quotes === []) {
			return $payload;
		}

		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$content = (string) ($de['content'] ?? '');
		if ($content === '' || preg_match('/<blockquote\b/i', $content) || preg_match('/[«„“"][^"«»„“]{20,}[»"“]/u', wp_strip_all_tags($content))) {
			return $payload;
		}

		$quote = $quotes[0];
		$text = trim((string) ($quote['text'] ?? ''));
		if ($text === '') {
			return $payload;
		}
		$speaker = trim((string) ($quote['speaker'] ?? ''));
		$source_name = trim((string) ($quote['source_name'] ?? ''));
		$cite_parts = array_values(array_filter([$speaker, $source_name]));
		$cite = $cite_parts !== [] ? '<cite>' . esc_html(implode(' — ', $cite_parts)) . '</cite>' : '';
		$blockquote = '<blockquote><p>' . esc_html($text) . '</p>' . $cite . '</blockquote>';

		if (preg_match('/(<\/p>)/i', $content, $match, PREG_OFFSET_CAPTURE)) {
			$offset = (int) $match[1][1] + strlen((string) $match[1][0]);
			$content = substr($content, 0, $offset) . "\n\n" . $blockquote . "\n\n" . substr($content, $offset);
		} else {
			$content .= "\n\n" . $blockquote;
		}

		$payload['languages']['de']['content'] = $content;
		$payload['_meta']['quote_lifted'] = true;
		return $payload;
	}

	private static function generate_single_field(object $item, array $payload, string $lang, string $field, string $style, array $categories): string {
		$config = EPV2_Settings::get_ai_config();
		if (empty($config['api_key'])) {
			return '';
		}
		$field = in_array($field, ['title', 'excerpt', 'content', 'seo_title', 'meta_description', 'slug'], true) ? $field : '';
		if ($field === '') {
			return '';
		}
		$configs = [$config];
		$fallback = self::fallback_provider_config($config);
		if ($fallback !== []) {
			$configs[] = $fallback;
		}
		foreach ($configs as $candidate) {
			if (! self::provider_candidate_is_ready($candidate, 'field_regenerate')) {
				continue;
			}
			$candidate['max_tokens'] = match ($field) {
				'title' => 180,
				'excerpt' => 320,
				'seo_title' => 120,
				'meta_description' => 220,
				'slug' => 80,
				default => 1200,
			};
			$candidate['timeout'] = match ($field) {
				'title' => 12,
				'excerpt' => 14,
				'seo_title' => 10,
				'meta_description' => 10,
				'slug' => 8,
				default => 18,
			};
			try {
				self::heartbeat_active_process_lock();
				$result = EPV2_AI_Client::generate($candidate, self::build_field_messages($item, $payload, $lang, $field, $style, $categories));
				self::heartbeat_active_process_lock();
				$data = json_decode(self::clean_json_response((string) ($result['text'] ?? '')), true);
				if (! is_array($data)) {
					continue;
				}
				$value = trim((string) ($data['value'] ?? ''));
				if ($value === '') {
					continue;
				}
				return match ($field) {
					'title' => sanitize_text_field($value),
					'excerpt' => sanitize_textarea_field($value),
					'seo_title' => sanitize_text_field($value),
					'meta_description' => sanitize_textarea_field($value),
					'slug' => sanitize_title($value),
					default => wp_kses_post($value),
				};
			} catch (Throwable $e) {
				EPV2_Logger::warning('ai', 'Field regenerate fallback', [
					'field' => $field,
					'lang' => $lang,
					'provider' => (string) ($candidate['provider'] ?? ''),
					'error' => $e->getMessage(),
				]);
			}
		}
		return '';
	}

	private static function build_field_messages(object $item, array $payload, string $lang, string $field, string $style, array $categories): array {
		$story_format = sanitize_text_field((string) ($item->story_format ?? ''));
		$zone = in_array($story_format, ['analysis', 'developing'], true) ? $story_format : 'news';
		$primary_category = (string) ($categories[0] ?? self::working_category_seed($item));
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$budget = EPV2_Site_Profile::text_budget($lang, $zone, $primary_category, [
			'source_count' => 1 + count((array) ($dossier['supporting'] ?? [])),
			'event_kind' => (string) ($dossier['event_context']['kind'] ?? ''),
			'title' => (string) ($payload['languages']['de']['title'] ?? $item->original_title),
			'excerpt' => (string) ($payload['languages']['de']['excerpt'] ?? $item->original_excerpt),
			'content' => (string) ($payload['languages']['de']['content'] ?? $item->original_content),
			'datetime_text' => (string) ($dossier['event_context']['datetime_text'] ?? ''),
			'venue' => (string) ($dossier['event_context']['venue'] ?? ''),
			'stage' => (string) ($dossier['event_context']['stage'] ?? ''),
		]);
		$topic_label = sanitize_text_field((string) ($item->topic_label ?? ($payload['_meta']['topic_label'] ?? '')));
		$current = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
		$focus_keywords = array_values(array_filter(array_map('strval', (array) ($current['focus_keywords'] ?? []))));
		$primary_keyword = trim((string) ($focus_keywords[0] ?? ''));
		$field_labels = [
			'title' => 'заголовок',
			'excerpt' => 'лид',
			'content' => 'основной текст',
			'seo_title' => 'SEO title',
			'meta_description' => 'meta description',
			'slug' => 'slug',
		];
		$field_label = $field_labels[$field] ?? $field;
		$limit_hint = match ($field) {
			'title' => 'До ' . $budget['title_chars'] . ' символов. Заголовок короткий, хлёсткий, по сути, без многоточия и без кликбейта. Он обязан целиком помещаться в самые узкие карточки и слайдер сайта без обрезания.',
			'excerpt' => 'Ровно 2 предложения. Короткий сильный лид, который сразу раскрывает суть и поднимает проблему. До ' . $budget['lead_chars'] . ' символов. Он обязан целиком помещаться в карточки сайта без обрезания и без потери смысла.',
			'seo_title' => 'SEO title длиной примерно 45-65 символов. Он должен быть естественным, кликабельным и без SEO-мусора.',
			'meta_description' => 'Meta description длиной примерно 110-160 символов. Он должен ясно объяснять материал и не звучать как набор ключей.',
			'slug' => 'Короткий латинский slug через дефисы, без дат, процентов и мусорных слов.',
			default => 'Цельный newsroom-текст: начинается с проблемы или главного последствия, использует короткие и средние предложения, без канцелярита и без рубленых служебных блоков.',
		};
		$lang_hint = match ($lang) {
			'uk' => 'Украинская версия особенно строгая: это самый узкий интерфейсный сценарий. Любой хвост, который рискует не влезть, нужно переформулировать короче заранее.',
			'en' => 'Английская версия тоже должна влезать без обрезания в mobile slider и latest cards. Предпочитай более короткие конструкции, чем буквальный перевод.',
			default => 'Немецкая версия тоже должна полностью помещаться в слайдер и карточки без визуального обрезания.',
		};
		$seo_hint = $primary_keyword !== ''
			? 'Главный ключ этого языка: "' . $primary_keyword . '". Вшивай его естественно, без спама и без ломки синтаксиса.'
			: 'Если есть очевидный главный смысловой ключ, вшивай его естественно в поле без SEO-спама.';
		return [
			[
				'role' => 'system',
				'content' => 'Ты редакционный AI для EuroPulse. Нужно перегенерировать только одно поле материала. Верни только JSON вида {"value":""}. Не переписывай остальные поля. Стиль должен быть редакционный, живой, ясный и новостной. Избегай канцелярита, официоза и длинных тяжёлых предложений. ' . $limit_hint . ' ' . $lang_hint . ' ' . $seo_hint,
			],
			[
				'role' => 'user',
				'content' => wp_json_encode([
					'lang' => $lang,
					'field' => $field_label,
					'style' => $style,
					'categories' => $categories,
					'story_format' => $story_format,
					'topic_label' => $topic_label,
					'focus_keywords' => $focus_keywords,
					'primary_keyword' => $primary_keyword,
					'original_title' => (string) $item->original_title,
					'original_excerpt' => (string) $item->original_excerpt,
					'original_content' => wp_strip_all_tags((string) $item->original_content),
					'current_language_payload' => [
						'title' => (string) ($current['title'] ?? ''),
						'excerpt' => (string) ($current['excerpt'] ?? ''),
						'content' => wp_strip_all_tags((string) ($current['content'] ?? '')),
						'seo_title' => (string) ($current['seo_title'] ?? ''),
						'meta_description' => (string) ($current['meta_description'] ?? ''),
						'slug' => (string) ($current['slug'] ?? ''),
					],
					'instruction' => 'Перегенерируй только указанное поле, сохраняя смысл материала и делая подачу более живой, лёгкой, интересной и естественной для сильного новостного медиа. Если есть главный ключ, он должен звучать органично и действительно присутствовать в результате.',
				], JSON_UNESCAPED_UNICODE),
			],
		];
	}

	private static function repair_language_variants(array $data, array $config): array {
		$base = $data['languages']['de'] ?? null;
		if (! is_array($base)) {
			return $data;
		}

		foreach (['uk', 'en'] as $lang) {
			$current = $data['languages'][$lang] ?? null;
			if (! is_array($current) || ! self::needs_language_repair($base, $current, $lang)) {
				continue;
			}
			$fixed = self::translate_language_package($base, $lang, $config);
			if (is_array($fixed)) {
				$data['languages'][$lang] = array_merge($current, $fixed, [
					'media_url' => (string) ($current['media_url'] ?? $data['media_url'] ?? ''),
				]);
				unset(
					$data['languages'][$lang]['seo_title'],
					$data['languages'][$lang]['meta_description'],
					$data['languages'][$lang]['slug'],
					$data['languages'][$lang]['focus_keywords'],
					$data['languages'][$lang]['tags']
				);
				$data['_meta']['repaired_languages'][] = $lang;
			}
		}

		$data = self::ensure_quote_blocks_in_translations($data, $config);

		return $data;
	}

	private static function needs_language_repair(array $de, array $candidate, string $lang): bool {
		$deTitle = trim((string) ($de['title'] ?? ''));
		$deExcerpt = trim((string) ($de['excerpt'] ?? ''));
		$deContent = trim((string) ($de['content'] ?? ''));
		$title = trim((string) ($candidate['title'] ?? ''));
		$excerpt = trim((string) ($candidate['excerpt'] ?? ''));
		$content = trim((string) ($candidate['content'] ?? ''));
		$combined = $title . ' ' . $excerpt . ' ' . wp_strip_all_tags($content);

		if ($title === '' || $excerpt === '' || $content === '') {
			return true;
		}
		if (mb_strlen(wp_strip_all_tags($content)) < self::translated_content_min_length($deContent)) {
			return true;
		}
		if ($title === $deTitle || $excerpt === $deExcerpt || $content === $deContent) {
			return true;
		}
		if ($lang === 'uk' && (! preg_match('/\p{Cyrillic}/u', $title) || ! preg_match('/\p{Cyrillic}/u', $excerpt))) {
			return true;
		}
		if ($lang === 'uk' && ! preg_match('/\p{Cyrillic}/u', $combined)) {
			return true;
		}
		if ($lang === 'uk' && preg_match('/[ыэёъ]/u', $combined) === 1) {
			return true;
		}
			if ($lang === 'en' && preg_match('/\b(der|die|das|und|mit|für|wird|nicht|mehr|kommunen|bund|hilfe|deutschland|berlin will|integrationsmittel)\b/iu', $combined)) {
				return true;
			}
			if ($lang === 'en' && (preg_match('/\p{Cyrillic}/u', $combined) === 1 || preg_match('/[A-Za-z]/u', $combined) !== 1)) {
				return true;
			}
			if ($lang === 'uk' && preg_match('/\b(der|die|das|und|mit|für|wird|nicht|mehr|kommunen|bund|hilfe|deutschland)\b/iu', $combined)) {
				return true;
			}
			if (preg_match('/\b(Nr\.|E\s*\d{3,}|Vorgangs-Link|Beschluss|Empfehlung)\b/u', $title)) {
				return true;
			}

			return false;
	}

	private static function translate_language_package(array $base, string $lang, array $config): ?array {
		$configs = self::translation_candidate_configs($config);
		if ($configs === []) {
			return null;
		}
		$localeLabel = $lang === 'uk' ? 'украинский' : 'английский';
		$contentLength = mb_strlen(trim(wp_strip_all_tags((string) ($base['content'] ?? ''))));
		$preferChunkedTranslation = $contentLength >= 2200;
		foreach ($configs as $candidate_config) {
			$retry = $candidate_config;
			if (! self::provider_candidate_is_ready($retry, 'translation')) {
				continue;
			}
			$retry['gemini_search_grounding_enabled'] = false;
			$retry['gemini_url_context_enabled'] = false;
			$retry['timeout'] = self::translation_timeout_for_content($retry, $contentLength, false);
			$retry['max_tokens'] = min($preferChunkedTranslation ? 1600 : 1800, (int) ($retry['max_tokens'] ?? 1800));
			if ($preferChunkedTranslation) {
				self::heartbeat_active_process_lock();
				$separate = self::translate_language_fields_separately($base, $lang, $localeLabel, $retry);
				if (is_array($separate) && self::translated_package_is_valid($base, $separate, $lang)) {
					return $separate;
				}
			}
			$transport_error = '';
			try {
				self::heartbeat_active_process_lock();
				$result = EPV2_AI_Client::generate($retry, self::translation_messages($base, $lang, $localeLabel, false));
				self::heartbeat_active_process_lock();
				$data = json_decode(self::clean_json_response((string) ($result['text'] ?? '')), true);
				if (! is_array($data)) {
					throw new RuntimeException('translation json invalid');
				}
				$package = [
					'title' => sanitize_text_field((string) ($data['title'] ?? '')),
					'excerpt' => sanitize_textarea_field((string) ($data['excerpt'] ?? '')),
					'content' => wp_kses_post((string) ($data['content'] ?? '')),
				];
				if (self::translated_package_is_valid($base, $package, $lang)) {
					return $package;
				}
				throw new RuntimeException('translation invalid for target language');
			} catch (Throwable $e) {
				$transport_error = $e->getMessage();
				if (! self::is_timeout_or_cooldown_error($transport_error)) {
					try {
						$retry['timeout'] = self::translation_timeout_for_content($retry, $contentLength, true);
						$retry['max_tokens'] = min(1300, (int) ($retry['max_tokens'] ?? 1300));
						self::heartbeat_active_process_lock();
						$result = EPV2_AI_Client::generate($retry, self::translation_messages($base, $lang, $localeLabel, true));
						self::heartbeat_active_process_lock();
						$data = json_decode(self::clean_json_response((string) ($result['text'] ?? '')), true);
						if (is_array($data)) {
							$package = [
								'title' => sanitize_text_field((string) ($data['title'] ?? '')),
								'excerpt' => sanitize_textarea_field((string) ($data['excerpt'] ?? '')),
								'content' => wp_kses_post((string) ($data['content'] ?? '')),
							];
							if (self::translated_package_is_valid($base, $package, $lang)) {
								return $package;
							}
						}
					} catch (Throwable $fallbackError) {
						EPV2_Logger::warning('ai', 'Language translation repair failed', [
							'lang' => $lang,
							'error' => $fallbackError->getMessage(),
						]);
					}
				}
				$separate = self::translate_language_fields_separately($base, $lang, $localeLabel, $retry);
				if (is_array($separate) && self::translated_package_is_valid($base, $separate, $lang)) {
					return $separate;
				}
				if (self::is_timeout_or_cooldown_error($transport_error)) {
					continue;
				}
			}
		}
		return null;
	}

	private static function translation_candidate_configs(array $config): array {
		return self::translation_provider_chain($config);
	}

	private static function translation_provider_chain(array $config): array {
		$configs = self::provider_candidate_chain($config);
		if (count($configs) < 2) {
			return $configs;
		}
		usort($configs, static function (array $a, array $b): int {
			$aProvider = sanitize_key((string) ($a['provider'] ?? ''));
			$bProvider = sanitize_key((string) ($b['provider'] ?? ''));
			$aPriority = ($aProvider === 'openai') ? 2 : (($aProvider === 'deepseek') ? 0 : 1);
			$bPriority = ($bProvider === 'openai') ? 2 : (($bProvider === 'deepseek') ? 0 : 1);
			return $bPriority <=> $aPriority;
		});
		return array_values($configs);
	}

	private static function provider_candidate_is_ready(array $config, string $context): bool {
		$provider = sanitize_key((string) ($config['provider'] ?? ''));
		$model = sanitize_text_field((string) ($config['model'] ?? ''));
		if ($provider === '' || $model === '' || empty($config['api_key'])) {
			EPV2_Logger::warning('ai', 'AI candidate config incomplete', [
				'context' => $context,
				'provider' => $provider,
				'model' => $model,
			]);
			return false;
		}
		if (! EPV2_Resilience_Manager::provider_available($provider)) {
			EPV2_Logger::warning('ai', 'AI candidate on cooldown', [
				'context' => $context,
				'provider' => $provider,
				'model' => $model,
			]);
			return false;
		}
		return true;
	}

	private static function provider_chain_is_ready(array $config, string $context): bool {
		foreach (self::provider_candidate_chain($config) as $candidate) {
			if (self::provider_candidate_is_ready($candidate, $context)) {
				return true;
			}
		}
		return false;
	}

	private static function provider_candidate_chain(array $config): array {
		$configs = [];
		if (! empty($config['api_key'])) {
			$configs[] = $config;
		}
		$fallback = self::fallback_provider_config($config);
		if (! empty($fallback['api_key'])) {
			$configs[] = $fallback;
		}
		if (count($configs) < 2) {
			return $configs;
		}
		$primary = sanitize_key((string) ($config['provider'] ?? ''));
		usort($configs, static function (array $a, array $b) use ($primary): int {
			$aProvider = sanitize_key((string) ($a['provider'] ?? ''));
			$bProvider = sanitize_key((string) ($b['provider'] ?? ''));
			$aAvailable = EPV2_Resilience_Manager::provider_available($aProvider) ? 1 : 0;
			$bAvailable = EPV2_Resilience_Manager::provider_available($bProvider) ? 1 : 0;
			if ($aAvailable !== $bAvailable) {
				return $bAvailable <=> $aAvailable;
			}
			$aPrimary = $aProvider === $primary ? 1 : 0;
			$bPrimary = $bProvider === $primary ? 1 : 0;
			return $bPrimary <=> $aPrimary;
		});
		return array_values($configs);
	}

	private static function is_timeout_or_cooldown_error(string $message): bool {
		return preg_match('/timed out|cURL error 28|cooldown active|provider unavailable/i', $message) === 1;
	}

	private static function translation_messages(array $base, string $lang, string $localeLabel, bool $compact): array {
		$instruction = $compact
			? 'Сделай компактную, но полноценную newsroom-версию на целевом языке. Можно чуть короче исходного немецкого текста, но нельзя терять ключевые факты, лид и итог для читателя.'
			: 'Сделай естественную newsroom-версию на целевом языке, а не буквальный перевод. Сохрани факты, но перепиши ритм и синтаксис под живое сильное медиа. Текст должен читаться как оригинальная статья на целевом языке.';
		$content = wp_strip_all_tags((string) ($base['content'] ?? ''));
		if ($compact) {
			$content = mb_substr($content, 0, 2200);
		}
		return [
			[
				'role' => 'system',
				'content' => 'Верни только JSON вида {"title":"","excerpt":"","content":""}. Переведи и редакционно адаптируй материал на ' . $localeLabel . ' язык. В ответе должен быть только целевой язык: ни одного немецкого предложения, ни одной немецкой служебной фразы, ни одного немецкого заголовка. Это должна быть полноценная newsroom-версия, а не буквальный перевод с немецкого. Нужен плавный, естественный, современный новостной текст уровня BBC, Reuters, AP, CNN, Al Jazeera или Deutsche Welle. Запрещены бюрократический тон, шаблонные секции, канцелярит, тяжёлые обороты и калька с немецкого синтаксиса. Не пиши подзаголовками внутри текста: никаких "Контекст:", "Почему это важно:", "Что дальше:". Статья должна течь естественно: сильный лид, затем детали, затем пояснение последствий и следующего шага. Если исходный заголовок похож на номер документа, название решения, служебную ссылку или административный реестр, не переводи его буквально: создай нормальный читабельный newsroom-заголовок по смыслу материала. Если немецкий текст перегружен названиями законов, длинными официальными формулами или номерами документов, переводи смысл человеческим языком, сохраняя точность. Нельзя менять географию, страну, город, регион, институции и базовые факты исходного немецкого текста. Если в исходнике речь о Германии или Мюнхене, нельзя заменять это на Украину, Польшу или любую другую страну/локацию.',
			],
			[
				'role' => 'user',
				'content' => wp_json_encode([
					'title' => (string) ($base['title'] ?? ''),
					'excerpt' => (string) ($base['excerpt'] ?? ''),
					'content' => $content,
					'target_lang' => $lang,
					'compact' => $compact,
					'instruction' => $instruction,
				], JSON_UNESCAPED_UNICODE),
			],
		];
	}

	private static function translate_language_fields_separately(array $base, string $lang, string $localeLabel, array $config): ?array {
		$out = [];
		foreach (['title', 'excerpt'] as $field) {
			$value = self::translate_language_field_value($base, $lang, $localeLabel, $field, $config);
			if ($value === '') {
				return null;
			}
			$out[$field] = $field === 'title' ? sanitize_text_field($value) : sanitize_textarea_field($value);
		}
		$content = self::translate_language_content_in_chunks($base, $lang, $localeLabel, $config);
		if ($content === '') {
			return null;
		}
		$out['content'] = wp_kses_post($content);
		return self::translated_package_is_valid($base, $out, $lang) ? $out : null;
	}

	private static function translate_language_field_value(array $base, string $lang, string $localeLabel, string $field, array $config): string {
		$request = $config;
		$request['gemini_search_grounding_enabled'] = false;
		$request['gemini_url_context_enabled'] = false;
		$request['timeout'] = $field === 'title' ? 18 : 22;
		$request['max_tokens'] = $field === 'title' ? 180 : 260;
		try {
			self::heartbeat_active_process_lock();
			$result = EPV2_AI_Client::generate($request, self::translation_field_messages($base, $lang, $localeLabel, $field));
			self::heartbeat_active_process_lock();
			$data = json_decode(self::clean_json_response((string) ($result['text'] ?? '')), true);
			if (! is_array($data)) {
				return '';
			}
			return trim((string) ($data['value'] ?? ''));
		} catch (Throwable $e) {
			EPV2_Logger::warning('ai', 'Field translation repair failed', [
				'lang' => $lang,
				'field' => $field,
				'error' => $e->getMessage(),
			]);
			return '';
		}
	}

	private static function translate_language_content_in_chunks(array $base, string $lang, string $localeLabel, array $config): string {
		$source = trim(wp_strip_all_tags((string) ($base['content'] ?? '')));
		if ($source === '') {
			return '';
		}
		$chunks = self::translation_content_chunks($source);
		if ($chunks === []) {
			return '';
		}
		if (count($chunks) === 1) {
			return self::translate_language_content_chunk($chunks[0], $lang, $localeLabel, 1, 1, $config);
		}
		$translated = [];
		foreach ($chunks as $index => $chunk) {
			$piece = self::translate_language_content_chunk($chunk, $lang, $localeLabel, $index + 1, count($chunks), $config);
			if ($piece === '') {
				return '';
			}
			$translated[] = $piece;
		}
		return trim(implode("\n\n", $translated));
	}

	private static function translate_language_content_chunk(string $source, string $lang, string $localeLabel, int $index, int $total, array $config): string {
		$request = $config;
		$request['gemini_search_grounding_enabled'] = false;
		$request['gemini_url_context_enabled'] = false;
		$request['timeout'] = self::translation_timeout_for_content($config, mb_strlen($source), true);
		$request['max_tokens'] = 900;
		try {
			self::heartbeat_active_process_lock();
			$result = EPV2_AI_Client::generate($request, self::translation_content_chunk_messages($source, $lang, $localeLabel, $index, $total));
			self::heartbeat_active_process_lock();
			$data = json_decode(self::clean_json_response((string) ($result['text'] ?? '')), true);
			if (! is_array($data)) {
				return '';
			}
			return trim((string) ($data['value'] ?? ''));
		} catch (Throwable $e) {
			EPV2_Logger::warning('ai', 'Field translation repair failed', [
				'lang' => $lang,
				'field' => 'content_chunk_' . $index,
				'error' => $e->getMessage(),
			]);
			return '';
		}
	}

	private static function translation_content_chunks(string $source): array {
		$source = trim(preg_replace("/\r\n?/", "\n", $source));
		if ($source === '') {
			return [];
		}
		$paragraphs = preg_split('/\n\s*\n/u', $source) ?: [];
		$paragraphs = array_values(array_filter(array_map(static function ($paragraph): string {
			return trim((string) $paragraph);
		}, $paragraphs)));
		if ($paragraphs === []) {
			return [mb_substr($source, 0, 1400)];
		}
		$chunks = [];
		$current = '';
		$limit = 1350;
		foreach ($paragraphs as $paragraph) {
			if ($current === '') {
				$current = $paragraph;
				continue;
			}
			$next = $current . "\n\n" . $paragraph;
			if (mb_strlen($next, 'UTF-8') <= $limit) {
				$current = $next;
				continue;
			}
			$chunks[] = $current;
			$current = $paragraph;
		}
		if ($current !== '') {
			$chunks[] = $current;
		}
		if (count($chunks) <= 5) {
			return $chunks;
		}
		$fallback = [];
		$sourceLength = mb_strlen($source, 'UTF-8');
		for ($offset = 0; $offset < $sourceLength; $offset += 1200) {
			$fallback[] = trim(mb_substr($source, $offset, 1200, 'UTF-8'));
		}
		return array_values(array_filter($fallback, static fn($chunk): bool => $chunk !== ''));
	}

	private static function translation_content_chunk_messages(string $source, string $lang, string $localeLabel, int $index, int $total): array {
		return [
			[
				'role' => 'system',
				'content' => 'Верни только JSON вида {"value":""}. Переведи только фрагмент основного текста на ' . $localeLabel . ' язык. Никаких немецких слов, никаких пояснений, никаких заголовков и подзаголовков. Это chunk ' . $index . ' из ' . $total . ' одного newsroom-материала, поэтому держи естественный новостной ритм и не повторяй заново уже известный контекст.',
			],
			[
				'role' => 'user',
				'content' => wp_json_encode([
					'field' => 'content',
					'target_lang' => $lang,
					'chunk_index' => $index,
					'chunks_total' => $total,
					'source' => $source,
				], JSON_UNESCAPED_UNICODE),
			],
		];
	}

	private static function translation_timeout_for_content(array $config, int $contentLength, bool $compact): int {
		$base = (int) ($config['timeout'] ?? 18);
		if ($contentLength >= 3200) {
			$base = 30;
		} elseif ($contentLength >= 2200) {
			$base = 26;
		} elseif ($contentLength >= 1400) {
			$base = 22;
		} else {
			$base = max($base, 18);
		}
		if ($compact) {
			$base = max(18, min(26, $base - 2));
		}
		return max(18, min(32, $base));
	}

	private static function translation_field_messages(array $base, string $lang, string $localeLabel, string $field): array {
		$source = match ($field) {
			'title' => (string) ($base['title'] ?? ''),
			'excerpt' => (string) ($base['excerpt'] ?? ''),
			default => wp_strip_all_tags((string) ($base['content'] ?? '')),
		};
		if ($field === 'content') {
			$source = mb_substr($source, 0, 3500);
		}
		$fieldLabel = match ($field) {
			'title' => 'заголовок',
			'excerpt' => 'лид',
			default => 'основной текст',
		};
		return [
			[
				'role' => 'system',
				'content' => 'Верни только JSON вида {"value":""}. Переведи на ' . $localeLabel . ' только одно поле: ' . $fieldLabel . '. В ответе должен быть только целевой язык, без немецких фраз, без смешения языков, без служебных пометок.',
			],
			[
				'role' => 'user',
				'content' => wp_json_encode([
					'field' => $field,
					'target_lang' => $lang,
					'source' => $source,
				], JSON_UNESCAPED_UNICODE),
			],
		];
	}

	private static function translated_package_is_valid(array $base, array $candidate, string $lang): bool {
		$title = trim((string) ($candidate['title'] ?? ''));
		$excerpt = trim((string) ($candidate['excerpt'] ?? ''));
		$content = trim(wp_strip_all_tags((string) ($candidate['content'] ?? '')));
		$deTitle = trim((string) ($base['title'] ?? ''));
		$deExcerpt = trim((string) ($base['excerpt'] ?? ''));
		$deContent = trim(wp_strip_all_tags((string) ($base['content'] ?? '')));
		if ($title === '' || $excerpt === '' || $content === '') {
			return false;
		}
		if (mb_strtolower($title) === mb_strtolower($deTitle) || mb_strtolower($excerpt) === mb_strtolower($deExcerpt)) {
			return false;
		}
		if ($deContent !== '' && mb_strtolower($content) === mb_strtolower($deContent)) {
			return false;
		}
		$combined = trim($title . ' ' . $excerpt . ' ' . $content);
		if ($lang === 'uk') {
			if (! preg_match('/\p{Cyrillic}/u', $combined)) {
				return false;
			}
			if (
				preg_match('/\b(die|der|das|und|mit|für|wird|nicht|bundesregierung|fachkr[aä]fte|akteuren vor ort)\b/iu', $combined)
				|| preg_match('/[ыэёъ]/u', $combined) === 1
			) {
				return false;
			}
		}
		if ($lang === 'en') {
			if (
				preg_match('/\b(die|der|das|und|mit|für|wird|nicht|bundesregierung|fachkr[aä]fte|akteuren vor ort)\b/iu', $combined)
				|| preg_match('/\p{Cyrillic}/u', $combined) === 1
				|| preg_match('/[A-Za-z]/u', $combined) !== 1
			) {
				return false;
			}
			if (str_contains($combined, 'Die Bundesregierung')) {
				return false;
			}
		}
		if (! self::translation_preserves_geography($base, $combined, $lang)) {
			return false;
		}
		return mb_strlen($content) >= self::translated_content_min_length($deContent);
	}

	private static function prioritize_search_terms_for_de(array $terms): array {
		$scored = [];
		foreach (array_values(array_filter(array_map('sanitize_text_field', $terms))) as $index => $term) {
			$score = 0;
			if (preg_match('/[A-Za-zÄÖÜäöüß]/u', $term) === 1) {
				$score += 20;
			}
			if (preg_match('/\p{Cyrillic}/u', $term) === 1) {
				$score -= 15;
			}
			if (mb_strlen(trim($term)) >= 12) {
				$score += 4;
			}
			$scored[] = ['term' => $term, 'score' => $score, 'index' => $index];
		}
		usort($scored, static function (array $a, array $b): int {
			if ($a['score'] !== $b['score']) {
				return $b['score'] <=> $a['score'];
			}
			return $a['index'] <=> $b['index'];
		});
		return array_slice(array_values(array_unique(array_map(static fn(array $row): string => $row['term'], $scored))), 0, 8);
	}

	private static function translation_preserves_geography(array $base, string $translatedCombined, string $lang): bool {
		$source = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($base['title'] ?? ''),
			(string) ($base['excerpt'] ?? ''),
			wp_strip_all_tags((string) ($base['content'] ?? '')),
		]))));
		$translated = mb_strtolower($translatedCombined);
		$mustKeep = [];
		if (preg_match('/\bdeutschland\b|in deutschland/u', $source) === 1) {
			$mustKeep[] = $lang === 'uk' ? '/\bнімеччин[аії]\b|у німеччині/u' : '/\bgermany\b|in germany/u';
		}
		if (preg_match('/\bmünchen\b|\bmunich\b/u', $source) === 1) {
			$mustKeep[] = $lang === 'uk' ? '/\bмюнхен[іауом]?\b/u' : '/\bmunich\b/u';
		}
		foreach ($mustKeep as $pattern) {
			if (preg_match($pattern, $translated) !== 1) {
				return false;
			}
		}
		if ($lang === 'uk' && preg_match('/\bв україні\b|\bукраїн[аії]\b/u', $translated) === 1 && preg_match('/\bdeutschland\b|in deutschland/u', $source) === 1) {
			return false;
		}
		return true;
	}

	private static function translated_content_min_length(string $deContent): int {
		$deLength = mb_strlen(trim(wp_strip_all_tags($deContent)));
		if ($deLength <= 0) {
			return 180;
		}
		if ($deLength <= 360) {
			return max(120, (int) floor($deLength * 0.55));
		}
		if ($deLength <= 700) {
			return max(180, (int) floor($deLength * 0.5));
		}
		if ($deLength <= 1400) {
			return max(260, (int) floor($deLength * 0.48));
		}
		return max(420, (int) floor($deLength * 0.45));
	}

	private static function ensure_quote_blocks_in_translations(array $data, array $config): array {
		$quotes = array_values(array_filter((array) ($data['_meta']['source_dossier']['quotes'] ?? []), static function ($quote): bool {
			if (! is_array($quote)) {
				return false;
			}
			$text = trim((string) ($quote['text'] ?? ''));
			if (mb_strlen($text) < 35) {
				return false;
			}
			return ! self::quote_is_unsafe_for_translation_lift($quote);
		}));
		if ($quotes === []) {
			return $data;
		}
		$quote = $quotes[0];
		$text = trim((string) ($quote['text'] ?? ''));
		$speaker = trim((string) ($quote['speaker'] ?? ''));
		$source_name = trim((string) ($quote['source_name'] ?? ''));
		if (self::quote_cite_part_is_unsafe($speaker)) {
			$speaker = '';
		}
		if (self::quote_cite_part_is_unsafe($source_name)) {
			$source_name = '';
		}
		if ($text === '') {
			return $data;
		}
		foreach (['uk', 'en'] as $lang) {
			$content = (string) ($data['languages'][$lang]['content'] ?? '');
			if ($content === '' || preg_match('/<blockquote\b/i', $content)) {
				continue;
			}
			$translated = self::translate_quote_text($text, $speaker, $lang, $config);
			if ($translated === '') {
				continue;
			}
			$cite_parts = array_values(array_filter([$speaker, $source_name]));
			$cite = $cite_parts !== [] ? '<cite>' . esc_html(implode(' — ', $cite_parts)) . '</cite>' : '';
			$blockquote = '<blockquote><p>' . esc_html($translated) . '</p>' . $cite . '</blockquote>';
			if (preg_match('/(<\/p>)/i', $content, $match, PREG_OFFSET_CAPTURE)) {
				$offset = (int) $match[1][1] + strlen((string) $match[1][0]);
				$content = substr($content, 0, $offset) . "\n\n" . $blockquote . "\n\n" . substr($content, $offset);
			} else {
				$content .= "\n\n" . $blockquote;
			}
			$data['languages'][$lang]['content'] = $content;
			$data['_meta']['repaired_languages'][] = $lang;
			$data['_meta']['quote_lifted_' . $lang] = true;
		}
		return $data;
	}

	private static function quote_is_unsafe_for_translation_lift(array $quote): bool {
		$text = trim(mb_strtolower((string) ($quote['text'] ?? '')));
		$speaker_raw = trim((string) ($quote['speaker'] ?? ''));
		$speaker = trim(mb_strtolower($speaker_raw));
		$source_name = trim(mb_strtolower((string) ($quote['source_name'] ?? '')));
		$attribution = trim(mb_strtolower((string) ($quote['attribution'] ?? '')));
		if ($text === '') {
			return true;
		}
		if (preg_match('/\b(newsletter|anmeldung|postfach|hier geht|sign up here|private inbox|volltextsuche|symbolbild|der schriftzug|europäische perspektive|europaeische perspektive)\b/iu', $text) === 1) {
			return true;
		}
		if ($speaker !== '' && preg_match('/\b(der schriftzug|auf|spreewasser|innen mit der|volltextsuche|symbolbild|bild)\b/iu', $speaker) === 1) {
			return true;
		}
		if ($speaker === '') {
			return true;
		}
		if (preg_match('/^[\p{Ll}\s\-]+$/u', $speaker_raw) === 1) {
			return true;
		}
		if (preg_match('/^\p{Lu}[\p{L}\-\'’.]+(?:\s+(?:\p{Lu}[\p{L}\-\'’.]+|von|van|der|de|den|di|da)){0,4}$/u', $speaker_raw) !== 1) {
			return true;
		}
		if (
			$speaker === ''
			&& $attribution === ''
			&& preg_match('/\b(watson(?:\.de)?|goal(?:\.com)?(?:\s+deutschland)?|kritik\s*[—-]\s*br|br)\b/iu', $source_name) === 1
		) {
			return true;
		}
		if ($speaker === '' && mb_strlen($text) < 120 && $source_name !== '') {
			return true;
		}
		return false;
	}

	private static function quote_cite_part_is_unsafe(string $value): bool {
		$value = trim(mb_strtolower($value));
		if ($value === '') {
			return false;
		}
		return preg_match('/\b(newsletter|anmeldung|postfach|volltextsuche|symbolbild|der schriftzug|innen mit der|ist sehr wichtig|die ist|das ist|bild|watson(?:\.de)?|goal(?:\.com)?(?:\s+deutschland)?|kritik\s*[—-]\s*br|^br$)\b/iu', $value) === 1;
	}

	private static function translate_quote_text(string $quote, string $speaker, string $lang, array $config): string {
		if (empty($config['api_key'])) {
			return '';
		}
		$localeLabel = $lang === 'uk' ? 'украинский' : 'английский';
		$retry = $config;
		$retry['gemini_search_grounding_enabled'] = false;
		$retry['gemini_url_context_enabled'] = false;
		$retry['timeout'] = max(15, min(30, (int) ($retry['timeout'] ?? 25)));
		$retry['max_tokens'] = 180;
		try {
			self::heartbeat_active_process_lock();
			$result = EPV2_AI_Client::generate($retry, [
				[
					'role' => 'system',
					'content' => 'Верни только JSON вида {"value":""}. Переведи короткую прямую цитату на ' . $localeLabel . ' язык. В ответе только целевой язык, без немецких слов, без пояснений.',
				],
				[
					'role' => 'user',
					'content' => wp_json_encode([
						'quote' => $quote,
						'speaker' => $speaker,
						'target_lang' => $lang,
					], JSON_UNESCAPED_UNICODE),
				],
			]);
			self::heartbeat_active_process_lock();
			$data = json_decode(self::clean_json_response((string) ($result['text'] ?? '')), true);
			$value = trim((string) ($data['value'] ?? ''));
			if ($value === '') {
				return '';
			}
			if ($lang === 'uk' && ! preg_match('/\p{Cyrillic}/u', $value)) {
				return '';
			}
			if ($lang === 'en' && preg_match('/\b(die|der|das|und|mit|für|wird|nicht)\b/iu', $value)) {
				return '';
			}
			return $value;
		} catch (Throwable $e) {
			EPV2_Logger::warning('ai', 'Quote translation lift failed', [
				'lang' => $lang,
				'error' => $e->getMessage(),
			]);
			return '';
		}
	}

	private static function heartbeat_active_process_lock(): void {
		$current = EPV2_Lock_Manager::current('process');
		$token = (string) ($current['token'] ?? '');
		if ($token === '') {
			return;
		}
		EPV2_Lock_Manager::heartbeat('process', $token, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
	}
}
