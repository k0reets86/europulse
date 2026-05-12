<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_AI_Processor {
	/**
	 * Editorial prompt version stamp. Поднимаем при существенных изменениях
	 * worker prompt'ов (Russia-Ukraine line, anti-filler rules,
	 * anti-repetition в body и т.п.). Saved AI payload помечается этой
	 * версией; при resume проверяется mismatch и устаревшие payload'ы
	 * принудительно пересгенерируются вместо silent reuse'а.
	 */
	public const EDITORIAL_PROMPT_VERSION = '2026-05-12-v15';

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
		$recentRunGuardSeconds = $force ? 0 : ((defined('WP_CLI') && WP_CLI && EPV2_Jobs::server_orchestrator_enabled()) ? 0 : 300);
		if (
			$recentRunGuardSeconds > 0
			&& EPV2_Lock_Manager::is_active('process')
			&& EPV2_Runs::has_recent_started('process', $recentRunGuardSeconds)
		) {
			self::log_process_entry_step('skip_recent_started', ['duration_ms' => self::duration_ms_since($started_at)]);
			return;
		}
		$skip_inline_cleanup = $force;
		if (! $skip_inline_cleanup) {
			self::log_process_entry_step('before_cleanup', ['duration_ms' => self::duration_ms_since($started_at)]);
			EPV2_Resilience_Manager::cleanup();
			self::log_process_entry_step('after_cleanup', ['duration_ms' => self::duration_ms_since($started_at)]);
		} else {
			self::log_process_entry_step('skip_inline_cleanup', ['duration_ms' => self::duration_ms_since($started_at)]);
		}
		self::log_process_entry_step('skip_prune_rejected', ['duration_ms' => self::duration_ms_since($started_at)]);
		$process_lock_ttl = (int) EPV2_Settings::get('job_lock_ttl_seconds', 900);
		$process_lock_stale_after = max(90, min(180, (int) floor(max(300, $process_lock_ttl) / 5)));
		$lock = EPV2_Lock_Manager::acquire('process', $process_lock_ttl, [
			'stale_after' => $process_lock_stale_after,
		]);
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
			EPV2_Queue::set_active_automation_item((int) $item->id);
			$run_payload['last_item_id'] = (int) $item->id;
			$run_payload['selected_bucket'] = EPV2_Queue::processing_bucket($item);
			$run_payload['attempts'] = $attempts + 1;
			$attempts++;
			$payload = [];
			$baseline_payload = [];
			$analysis = [];
			$gate = [];
			$category = '';
			$source_item = $item;
			$auto_rework = false;
			$auto_finish = false;
			$item_started_at = microtime(true);
			try {
				EPV2_Lock_Manager::heartbeat('process', $lock, $process_lock_ttl);
				$existing_payload = json_decode((string) ($item->ai_payload ?? ''), true);
				$existing_payload = is_array($existing_payload) ? $existing_payload : [];
				$source_item = EPV2_Queue::get_item((int) $item->id) ?: $item;
				if (self::item_is_stale_time_sensitive_story($source_item, $existing_payload)) {
					EPV2_Queue::mark_state((int) $item->id, 'rejected', [
						'error_message' => 'Материал снят автоматически: time-sensitive сюжет потерял актуальность и не должен занимать automation lane.',
					]);
					$count++;
					$run_payload['processed_item_id'] = (int) $item->id;
					$run_payload['result'] = 'rejected_stale_time_sensitive_story';
					break;
					}
					self::log_process_item_step('after_decode_existing_payload', (int) $item->id, ['run_id' => $run, 'has_existing_payload' => $existing_payload !== [] ? 1 : 0]);
					// Story-card upfront pass. Build once per item and cache
					// in `_meta.story_card`. Every later stage (worker rewrite,
					// translate, publish_finish, media resolver, tagger, SEO)
					// reads the same card. Worker-side rewriter receives it
					// inside existing_payload, so its rewrite prompt is
					// grounded in the same semantic snapshot the categorizer
					// used.
					// Invalidate stale-version story_card (P1.8 2026-05-11):
					// when STORY_CARD_PROMPT_VERSION bumps, old cards с
					// outdated editorial_match / per-rubric stop-lists
					// treated as missing → rebuild fresh on next tick.
					if (
						class_exists('EPV2_Story_Card_Builder')
						&& ! empty($existing_payload['_meta']['story_card'])
						&& defined('EPV2_Story_Card_Builder::STORY_CARD_PROMPT_VERSION')
					) {
						$card_version = (string) ($existing_payload['_meta']['story_card']['prompt_version'] ?? '');
						if ($card_version !== '' && $card_version !== EPV2_Story_Card_Builder::STORY_CARD_PROMPT_VERSION) {
							unset($existing_payload['_meta']['story_card']);
							self::log_process_item_step('story_card_version_mismatch_dropped', (int) $item->id, [
								'stored' => $card_version,
								'current' => EPV2_Story_Card_Builder::STORY_CARD_PROMPT_VERSION,
							]);
						}
					}
					if (
						class_exists('EPV2_Story_Card_Builder')
						&& empty($existing_payload['_meta']['story_card'])
					) {
						$story_card = EPV2_Story_Card_Builder::build($source_item, (array) ($existing_payload['_meta']['source_dossier'] ?? []));
						// Operator-feedback 2026-05-11: items published без
						// story_card (12 items today, including #2152 China
						// inflation с hallucinated 36.4%/22.4%, #2088 Arda
						// Saatci с invented 604). Pipeline без editorial
						// verdict не имеет защиты от halucinations и
						// от-rubric content. Hard requirement: story_card
						// MUST be built before any worker rewrite.
						if (empty($story_card['success'])) {
							// Build failed. Track attempts; retry до 3 раз
							// then route to manual_review с clear reason.
							$build_attempts = (int) ($existing_payload['_meta']['story_card_build_attempts'] ?? 0);
							$build_attempts++;
							$existing_payload['_meta']['story_card_build_attempts'] = $build_attempts;
							$existing_payload['_meta']['story_card_last_error'] = (string) ($story_card['reason'] ?? 'unknown');
							EPV2_Queue::update_fields((int) $item->id, [
								'ai_payload' => wp_json_encode($existing_payload, JSON_UNESCAPED_UNICODE),
							]);
							self::log_process_item_step('story_card_build_failed', (int) $item->id, [
								'attempt' => $build_attempts,
								'reason' => (string) ($story_card['reason'] ?? 'unknown'),
								'duration_ms' => self::duration_ms_since($item_started_at),
							]);
							if ($build_attempts >= 3) {
								// Persistent failure → manual_review с clear reason.
								// hard_editorial token prevents soft_terminal salvage.
								$reason_msg = sprintf(
									'hard_editorial — story_card build failed %dx (last: %s). Pipeline cannot continue без AI editorial verdict.',
									$build_attempts,
									(string) ($story_card['reason'] ?? 'unknown')
								);
								EPV2_Queue::mark_state((int) $item->id, 'manual_review', [
									'error_message' => $reason_msg,
								]);
								EPV2_Queue::clear_active_automation_item((int) $item->id);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'story_card_build_exhausted';
								break;
							}
							// Otherwise release ownership и пробуем на следующем
							// orchestrator tick (worker может быть transient down).
							EPV2_Queue::clear_active_automation_item((int) $item->id);
							$run_payload['result'] = 'story_card_build_retry';
							break;
						}
						if (! empty($story_card['success'])) {
							$existing_payload = EPV2_Story_Card_Builder::attach_to_payload($existing_payload, $story_card);
							// Persist immediately so subsequent ticks reuse it.
							EPV2_Queue::update_fields((int) $item->id, [
								'ai_payload' => wp_json_encode($existing_payload, JSON_UNESCAPED_UNICODE),
							]);
							self::log_process_item_step('story_card_built_upfront', (int) $item->id, [
								'run_id' => $run,
								'category' => (string) ($story_card['category']['primary'] ?? ''),
								'confidence' => (float) ($story_card['category']['confidence'] ?? 0.0),
								'estimate' => (string) ($story_card['publishable_estimate'] ?? ''),
								'duration_ms' => self::duration_ms_since($item_started_at),
							]);
							// Apply story-card category override to category_final
							// before any worker call so downstream consumers see
							// the right category from the very first stage.
							if (
								class_exists('EPV2_Categorizer')
								&& EPV2_Story_Card_Builder::category_is_trusted($story_card, 0.6)
							) {
								$current_cat = (string) ($source_item->category_final ?? $source_item->category_proposed ?? '');
								$override = EPV2_Categorizer::refine_with_story_card($current_cat, $story_card, 0.6);
								if ($override !== '' && $override !== $current_cat) {
									EPV2_Queue::update_fields((int) $item->id, [
										'category_final' => $override,
									]);
									$source_item = EPV2_Queue::get_item((int) $item->id) ?: $source_item;
									self::log_process_item_step('category_overridden_by_story_card_upfront', (int) $item->id, [
										'before' => $current_cat,
										'after' => $override,
										'confidence' => (float) ($story_card['category']['confidence'] ?? 0.0),
									]);
								}
							}

							// Editorial-calibration filter (docs/editorial-calibration.md).
							// Story Card already classified the item against the
							// per-rubric stop-list. reject_low_value items are
							// marked rejected immediately — no further AI tokens.
							$editorial_match = strtolower((string) ($story_card['editorial_match'] ?? 'match'));
							$editorial_reason = (string) ($story_card['editorial_reason'] ?? '');
							$source_id_for_health = (int) ($source_item->source_id ?? 0);
							if ($editorial_match === 'reject_low_value') {
								$reason_slug = sanitize_key((string) ($story_card['category']['primary'] ?? 'unknown'));
								$reason_msg = trim('Editorial calibration reject: ' . ($editorial_reason !== '' ? $editorial_reason : 'matches per-rubric stop-list')) . ' [' . $reason_slug . ']';
								EPV2_Queue::mark_state((int) $item->id, 'rejected', [
									'error_message' => $reason_msg,
								]);
								if ($source_id_for_health > 0 && class_exists('EPV2_Resilience_Manager')) {
									EPV2_Resilience_Manager::register_source_quality_reject(
										$source_id_for_health,
										$reason_slug . ': ' . $editorial_reason
									);
								}
								if (class_exists('EPV2_Learning_Journal')) {
									EPV2_Learning_Journal::record('editorial_reject', (int) $item->id, $editorial_reason, [
										'category_primary' => $reason_slug,
										'editorial_match' => $editorial_match,
									]);
								}
								self::log_process_item_step('editorial_calibration_reject', (int) $item->id, [
									'category' => $reason_slug,
									'editorial_reason' => $editorial_reason,
									'duration_ms' => self::duration_ms_since($item_started_at),
								]);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'rejected_editorial_calibration';
								break;
							}
							// editorial_match='match' resets the quality-reject
							// counter so a healthy source recovers from
							// occasional bad items.
							if ($editorial_match === 'match' && $source_id_for_health > 0 && class_exists('EPV2_Resilience_Manager')) {
								EPV2_Resilience_Manager::register_source_quality_success($source_id_for_health);
							}

							// Variant-D event-signature dedup: with Story Card
							// in hand, look up existing items in the same
							// event cluster (top entity + event keyword +
							// date_day) within the last 24 hours and apply
							// time-tiered rules: 0-30 min strict drop;
							// 30 min-3 h drop unless breaking/top_story or
							// new key_facts; 3-24 h allow as update; >24 h
							// new cluster.
							if (class_exists('EPV2_Deduplicator')) {
								// Pass full payload so signature can read
								// _meta.context_memory.event_title (richest
								// source for the event keyword).
								$dup_payload = is_array($existing_payload) && $existing_payload !== [] ? $existing_payload : [];
								if ($dup_payload === []) {
									$row_payload = json_decode((string) ($item->ai_payload ?? ''), true);
									if (is_array($row_payload)) {
										$dup_payload = $row_payload;
									}
								}
								$event_dup = EPV2_Deduplicator::is_event_duplicate((int) $item->id, $story_card, $dup_payload);
								if (! empty($event_dup['duplicate'])) {
									$dup_of = (int) ($event_dup['duplicate_of'] ?? 0);
									$age_min = (int) round((int) ($event_dup['age_seconds'] ?? 0) / 60);
									$reason_msg = sprintf(
										'Дубликат события: то же сюжетное событие что и материал #%d (опубликован %d мин назад, причина: %s).',
										$dup_of,
										$age_min,
										(string) ($event_dup['reason'] ?? 'event_signature')
									);
									EPV2_Queue::update_fields((int) $item->id, [
										'duplicate_of' => $dup_of > 0 ? $dup_of : null,
										'duplicate_reason' => sanitize_key((string) ($event_dup['reason'] ?? 'event_dup')),
									]);
									EPV2_Queue::mark_state((int) $item->id, 'duplicate', [
										'error_message' => $reason_msg,
									]);
									if (class_exists('EPV2_Learning_Journal')) {
										EPV2_Learning_Journal::record('event_duplicate', (int) $item->id,
											(string) ($event_dup['reason'] ?? 'event_dup'),
											[
												'duplicate_of' => $dup_of,
												'signature' => (string) ($event_dup['signature'] ?? ''),
												'age_seconds' => (int) ($event_dup['age_seconds'] ?? 0),
												'similarity' => (float) ($event_dup['similarity'] ?? 0.0),
											]
										);
									}
									self::log_process_item_step('event_signature_duplicate', (int) $item->id, [
										'duplicate_of' => $dup_of,
										'reason' => (string) ($event_dup['reason'] ?? ''),
										'signature' => (string) ($event_dup['signature'] ?? ''),
										'age_seconds' => (int) ($event_dup['age_seconds'] ?? 0),
									]);
									$count++;
									$run_payload['processed_item_id'] = (int) $item->id;
									$run_payload['result'] = 'event_duplicate';
									break;
								}
							}
						} else {
							self::log_process_item_step('story_card_build_failed', (int) $item->id, [
								'run_id' => $run,
								'error' => (string) ($story_card['error'] ?? 'unknown'),
								'duration_ms' => self::duration_ms_since($item_started_at),
							]);
							// 2026-05-10 (refined): block rewrite ТОЛЬКО при двойном
							// риск-сигнале: (a) story_card пустой И (b) primary URL
							// — высокорискованный (liveblog/ticker/aggregator). Без
							// (b) даже без grounding rewriter обычно справляется
							// если source — normal news article. Жёсткий блок на
							// ВСЕ empty-story_card items останавливал 30% pipeline,
							// что противоречит «правила не должны становиться
							// проблемами». Keep flagging но не block по default.
							$prev_card = is_array($existing_payload['_meta']['story_card'] ?? null) ? $existing_payload['_meta']['story_card'] : [];
							$has_entities = ! empty($prev_card['entities_people'])
								|| ! empty($prev_card['entities_organizations'])
								|| ! empty($prev_card['entities_places']);
							$primary_url = (string) ($source_item->original_url ?? '');
							$is_high_risk_primary = preg_match(
								'/(ticker|liveblog|live-blog|news-ticker|im-news|aktuelle-news-vom|nahost-ticker)/iu',
								$primary_url
							) === 1;
							if (! $has_entities && $is_high_risk_primary) {
								EPV2_Queue::mark_state((int) $item->id, 'manual_review', [
									'error_message' => 'Story Card empty + primary URL = liveblog/ticker (двойной риск hallucination). AI rewrite без grounding на ticker-source даст спутанный контекст. Требует ручной проверки источника.',
								]);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'story_card_empty_liveblog_primary';
								break;
							}
							// Иначе — flag, but continue (item probably OK).
							self::log_process_item_step('story_card_empty_continuing', (int) $item->id, [
								'has_entities' => $has_entities ? 1 : 0,
								'is_high_risk_primary' => $is_high_risk_primary ? 1 : 0,
								'primary_url' => $primary_url,
							]);
						}
					}
					// Post-story-card selection gate — срабатывает ВСЕГДА когда
					// story_card в payload, не зависит от того был ли он только
					// что построен или существовал ранее (retry/resume пути).
					// Re-run analyze_item с обогащённой категорией от story-card
					// + расширенными heuristic паттернами; если low/reject —
					// режем здесь, до rewriter/translator/SEO (~3-4 AI calls
					// саэкономлено per item). Это корневой fix waste-cycle:
					// 12+ items сегодня имели story_card.publishable_estimate=high
					// (worker AI разрешил), но PHP heuristic после AI давал
					// hard_pattern reject. Теперь heuristic применяется ДО AI.
					if (! empty($existing_payload['_meta']['story_card']) && ! self::payload_is_publish_ready_fast($existing_payload)) {
						$_card = (array) ($existing_payload['_meta']['story_card'] ?? []);
						$_card_category = (string) ($_card['category']['primary'] ?? '');
						$_active_category = $_card_category !== ''
							? $_card_category
							: ((string) ($source_item->category_final ?? $source_item->category_proposed ?? ''));
						// Story Card primacy guard (2026-05-11): если AI в
						// Story Card явно сказал editorial_match=match
						// AND publishable_estimate ∈ {high, medium} —
						// НЕ делать post-card heuristic re-analyze который
						// reject'ит. AI verdict trumps heuristic. Раньше
						// items с initial score=46 'review' после category
						// override от story_card → re-analyze давал score=39
						// 'low' → reject. Pipeline терял valid items из-за
						// per-rubric score thresholds.
						$_ed_match = strtolower(trim((string) ($_card['editorial_match'] ?? '')));
						$_est = strtolower(trim((string) ($_card['publishable_estimate'] ?? '')));
						$_card_ai_endorsed = ($_ed_match === 'match' && in_array($_est, ['high', 'medium'], true));
						$_post_card_analysis = $_card_ai_endorsed ? [] : EPV2_Budget_Manager::analyze_item([
							'title' => (string) $source_item->original_title,
							'content' => (string) ($source_item->original_content ?? ''),
							'excerpt' => (string) $source_item->original_excerpt,
							'url' => (string) $source_item->original_url,
							'date' => (string) ($source_item->original_date ?? ''),
							'image' => (string) ($source_item->source_image_url ?? ''),
							'category' => $_active_category,
						]);
						$_post_decision = (string) ($_post_card_analysis['decision'] ?? '');
						if (
							! $_card_ai_endorsed
							&& in_array($_post_decision, ['low', 'reject'], true)
							&& empty($_post_card_analysis['top_story_candidate'])
							&& empty($_post_card_analysis['breaking_candidate'])
							&& empty($_post_card_analysis['breaking_watch'])
						) {
							$_reject_class = (string) ($_post_card_analysis['reject_class'] ?? '');
							$_reject_score = (int) ($_post_card_analysis['score'] ?? 0);
							$_reason_msg = sprintf(
								'Снят после story-card: pre-AI verdict "%s" (score=%d%s). AI-rewrite не запускается.',
								$_post_decision,
								$_reject_score,
								$_reject_class !== '' ? ', class=' . $_reject_class : ''
							);
							$existing_payload['_meta']['selection'] = $_post_card_analysis;
							EPV2_Queue::mark_state((int) $item->id, 'rejected', [
								'error_message' => $_reason_msg,
								'ai_payload' => wp_json_encode($existing_payload, JSON_UNESCAPED_UNICODE),
							]);
							EPV2_Queue::clear_active_automation_item((int) $item->id);
							if (class_exists('EPV2_Learning_Journal')) {
								EPV2_Learning_Journal::record('post_story_card_reject', (int) $item->id, $_reason_msg, [
									'decision' => $_post_decision,
									'reject_class' => $_reject_class,
									'score' => $_reject_score,
									'category' => $_active_category,
								]);
							}
							self::log_process_item_step('post_story_card_reject', (int) $item->id, [
								'decision' => $_post_decision,
								'reject_class' => $_reject_class,
								'score' => $_reject_score,
								'category' => $_active_category,
								'duration_ms' => self::duration_ms_since($item_started_at),
							]);
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'rejected_post_story_card';
							break;
						}
					}

					$stored_pipeline_stage = self::payload_pipeline_stage($existing_payload);
					$payload_for_stage = $existing_payload !== [] ? self::normalize_existing_payload($existing_payload, false) : [];
					self::log_process_item_step('after_normalize_existing_payload', (int) $item->id, ['run_id' => $run, 'has_payload_for_stage' => $payload_for_stage !== [] ? 1 : 0]);
					$step_context = self::run_active_workflow_step($item, $payload_for_stage, $existing_payload);
					$payload_for_stage = $step_context['payload'];
					$pipeline_stage = (string) ($step_context['pipeline_stage'] ?? '');
					$workflow_step = (string) ($step_context['workflow_step'] ?? '');
					if ($pipeline_stage === '' && $stored_pipeline_stage !== '') {
						$pipeline_stage = $stored_pipeline_stage;
						$payload_for_stage = self::set_payload_pipeline_stage($payload_for_stage, $pipeline_stage);
					}
					if (
						$payload_for_stage !== []
						&& in_array($pipeline_stage, ['translate_uk', 'translate_en', 'translate_finish', 'publish_finish'], true)
				) {
					$payload_for_stage = self::refresh_payload_stage_markers($payload_for_stage);
					self::log_process_item_step('after_stage_payload_refresh', (int) $item->id, ['run_id' => $run, 'pipeline_stage' => $pipeline_stage]);
					}
					if (
						$payload_for_stage !== []
						&& (
							self::fast_transition_item_to_ready_publish((int) $item->id, $payload_for_stage)
							|| self::transition_item_to_ready_publish((int) $item->id, $payload_for_stage)
						)
					) {
						self::log_process_item_step('terminal_ready_payload_short_circuit', (int) $item->id, [
							'run_id' => $run,
							'pipeline_stage' => $pipeline_stage,
							'duration_ms' => self::duration_ms_since($item_started_at),
						]);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'terminal_ready_payload_short_circuit';
						break;
					}
					if ($payload_for_stage !== []) {
						self::log_process_item_step('before_resume_stage_reconcile', (int) $item->id, [
							'run_id' => $run,
							'pipeline_stage' => $pipeline_stage,
							'duration_ms' => self::duration_ms_since($item_started_at),
						]);
						// Do not run full stage resolution in the resume preflight. It can do
						// queue/profile analysis; the explicit routing block below owns it.
						$required_stage = '';
						self::log_process_item_step('after_resume_stage_reconcile', (int) $item->id, [
							'run_id' => $run,
							'pipeline_stage' => $pipeline_stage,
						'required_stage' => $required_stage,
						'duration_ms' => self::duration_ms_since($item_started_at),
					]);
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
				$run_payload['workflow_step_before'] = $workflow_step;
				self::log_process_item_step('after_pipeline_stage_detected', (int) $item->id, ['run_id' => $run, 'pipeline_stage' => $pipeline_stage, 'workflow_step' => $workflow_step]);
				// Hard cap on workflow_step_attempts when stuck in rebuild_bundle.
				// Two earlier guards (recent_rebuild_bundle_runs_stalled,
				// maybe_cooldown_stagnated_rebuild) can fail to fire because
				// either the signature drifts each tick (AI generates fresh
				// text) or the cooldown event itself looks like a non-loop run
				// in subsequent history checks. This is a backstop: any time
				// build_de_master has been retried >= 6 times for a payload
				// still on rebuild_bundle, terminalize to ready_review so the
				// pulse can move past the stuck row instead of burning AI on
				// the next 100 rebuilds. Operator can salvage from review.
				$existing_attempts = (int) (json_decode((string) ($item->admin_notes ?? ''), true)['_system']['workflow_step_attempts'] ?? 0);
				// Short-circuit on selection-rejected items. The earlier
				// rebuild loop kept calling the worker (~5K tokens per run)
				// even when every prior attempt had _meta.selection.decision
				// set to "reject" or "low" — selection does not change with
				// rebuild, so all six-plus retries were guaranteed to fail
				// at the publish gate. Terminate immediately to rejected
				// instead of burning AI on a foregone outcome.
				$existing_selection_decision = sanitize_key((string) ($payload_for_stage['_meta']['selection']['decision'] ?? ''));
				if (
					$pipeline_stage === 'rebuild_bundle'
					&& in_array($existing_selection_decision, ['reject', 'low'], true)
				) {
					$fresh_item = EPV2_Queue::get_item((int) $item->id) ?: $item;
					$notes_for_terminal = is_array(json_decode((string) $fresh_item->admin_notes, true)) ? json_decode((string) $fresh_item->admin_notes, true) : [];
					$notes_for_terminal['_system'] = is_array($notes_for_terminal['_system'] ?? null) ? $notes_for_terminal['_system'] : [];
					$notes_for_terminal['_system']['workflow_terminal_reason'] = 'selection_publish_blocked';
					$notes_for_terminal['_system']['workflow_step_status'] = 'terminal';
					$notes_for_terminal['_system']['workflow_owner_token'] = '';
					EPV2_Queue::mark_state((int) $item->id, 'rejected', [
						'admin_notes' => wp_json_encode($notes_for_terminal, JSON_UNESCAPED_UNICODE),
						'error_message' => sprintf(
							'Материал снят на rebuild_bundle: предварительный selection decision "%s" не пересматривается перезапуском.',
							$existing_selection_decision
						),
					]);
					self::log_process_item_step('rebuild_bundle_short_circuit_selection_reject', (int) $item->id, [
						'run_id' => $run,
						'selection_decision' => $existing_selection_decision,
						'attempts_saved' => max(0, 6 - $existing_attempts),
					]);
					$count++;
					$run_payload['processed_item_id'] = (int) $item->id;
					$run_payload['result'] = 'rebuild_bundle_short_circuit_selection_reject';
					break;
				}
				if (
					$pipeline_stage === 'rebuild_bundle'
					&& (string) $workflow_step === 'build_de_master'
					&& $existing_attempts >= 6
				) {
					$fresh_item = EPV2_Queue::get_item((int) $item->id) ?: $item;
					$payload_for_terminal = is_array($payload_for_stage) ? $payload_for_stage : [];
					$payload_for_terminal['_meta'] = is_array($payload_for_terminal['_meta'] ?? null) ? $payload_for_terminal['_meta'] : [];
					$payload_for_terminal['_meta']['pipeline_stage'] = '';
					$notes_for_terminal = is_array(json_decode((string) $fresh_item->admin_notes, true)) ? json_decode((string) $fresh_item->admin_notes, true) : [];
					$notes_for_terminal['_system'] = is_array($notes_for_terminal['_system'] ?? null) ? $notes_for_terminal['_system'] : [];
					$notes_for_terminal['_system']['workflow_step'] = '';
					$notes_for_terminal['_system']['workflow_step_status'] = '';
					$notes_for_terminal['_system']['workflow_owner_token'] = '';
					$notes_for_terminal['_system']['workflow_terminal_reason'] = 'rebuild_bundle_attempt_cap';
					$notes_for_terminal['_system']['manual_confirmation_required'] = 'content';
					EPV2_Queue::mark_state((int) $item->id, 'ready_review', [
						'ai_payload' => wp_json_encode($payload_for_terminal, JSON_UNESCAPED_UNICODE),
						'admin_notes' => wp_json_encode($notes_for_terminal, JSON_UNESCAPED_UNICODE),
						'error_message' => sprintf(
							'rebuild_bundle attempt cap reached (workflow_step_attempts=%d): manual review required.',
							$existing_attempts
						),
					]);
					self::log_process_item_step('rebuild_bundle_attempt_cap_terminated', (int) $item->id, [
						'run_id' => $run,
						'attempts' => $existing_attempts,
						'duration_ms' => self::duration_ms_since($item_started_at),
					]);
					$count++;
					$run_payload['processed_item_id'] = (int) $item->id;
					$run_payload['result'] = 'rebuild_bundle_attempt_cap_terminated';
					break;
				}
				if ($pipeline_stage === 'rebuild_bundle' && self::recent_rebuild_bundle_runs_stalled((int) $item->id)) {
					$fresh_item = EPV2_Queue::get_item((int) $item->id) ?: $item;
					EPV2_Resilience_Manager::schedule_retry($fresh_item, 'retry_process', 'process', 'publish threshold stalled rebuild bundle');
					EPV2_Queue::workflow_system_update((int) $item->id, [
						'workflow_step_status' => 'pending',
						'workflow_owner_token' => '',
						'workflow_heartbeat_at' => '',
					]);
					self::log_process_item_step('stalled_rebuild_run_history_cooldown', (int) $item->id, [
						'run_id' => $run,
						'duration_ms' => self::duration_ms_since($item_started_at),
					]);
					$count++;
					$run_payload['processed_item_id'] = (int) $item->id;
					$run_payload['result'] = 'stalled_rebuild_run_history_cooldown';
					break;
				}
				$requires_translation_finish = $payload_for_stage !== [] && self::payload_stage_requires_translation_finish($payload_for_stage);
				$is_publish_finish_stage = ($pipeline_stage === 'publish_finish');
				if ($requires_translation_finish) {
					$requires_fresh_rebuild = false;
					$repairing_media_blocker = false;
					$auto_rework = false;
					$auto_finish = false;
				} else {
					$requires_fresh_rebuild = $payload_for_stage !== [] && self::payload_requires_fresh_rebuild_fast($payload_for_stage);
					$publish_finish_resume_viable = ! $is_publish_finish_stage || self::publish_finish_resume_is_viable($payload_for_stage);
						$repairing_media_blocker = ! in_array($pipeline_stage, ['rebuild_bundle', 'translate_uk', 'translate_en', 'translate_finish', 'publish_finish'], true) && ! $requires_fresh_rebuild && self::item_needs_media_repair($item, $payload_for_stage);
						if ($is_publish_finish_stage && $publish_finish_resume_viable) {
							$auto_rework = false;
							$auto_finish = ! $repairing_media_blocker;
						} else {
							$auto_rework = ! $repairing_media_blocker && ($requires_fresh_rebuild || self::item_is_auto_rework_candidate($item, $payload_for_stage));
							$auto_finish = ! $repairing_media_blocker && ! $auto_rework && self::item_is_auto_finish_candidate($item, $payload_for_stage);
						}
					}
					self::log_process_item_step('after_stage_flags', (int) $item->id, [
						'run_id' => $run,
						'requires_translation_finish' => $requires_translation_finish ? 1 : 0,
						'requires_fresh_rebuild' => $requires_fresh_rebuild ? 1 : 0,
						'repairing_media_blocker' => $repairing_media_blocker ? 1 : 0,
						'auto_rework' => $auto_rework ? 1 : 0,
						'auto_finish' => $auto_finish ? 1 : 0,
					]);
					if ($is_publish_finish_stage && self::review_finish_exhausted($item, $payload_for_stage)) {
						$requires_fresh_rebuild = true;
						$repairing_media_blocker = false;
						$auto_rework = true;
						$auto_finish = false;
						self::log_process_item_step('publish_finish_exhausted_forced_rebuild', (int) $item->id, [
							'run_id' => $run,
							'duration_ms' => self::duration_ms_since($item_started_at),
						]);
					}
					EPV2_Queue::set_live_status((int) $item->id, 'Проверяю рубрику, фактуру и приоритет материала.', 'analyzing');
					if ($repairing_media_blocker && $existing_payload !== []) {
					$run_payload['branch'] = 'repairing_media';
					EPV2_Queue::set_live_status((int) $item->id, 'Ищу новое фото и перепроверяю визуальный контекст.', 'repairing_media');
					$existing_payload = self::repair_payload_media($item, $existing_payload);
					EPV2_Queue::update_fields((int) $item->id, [
						'ai_payload' => wp_json_encode($existing_payload, JSON_UNESCAPED_UNICODE),
					]);
					if (self::transition_item_to_ready_publish((int) $item->id, $existing_payload)) {
						$count++;
						$run_payload['result'] = 'media_repaired_to_publish_ready';
						$run_payload['processed_item_id'] = (int) $item->id;
						break;
					}
					if (! self::payload_has_media_candidate($existing_payload)) {
						$next_stage = self::payload_next_required_stage($existing_payload);
						if ($next_stage === 'rebuild_bundle') {
							self::queue_required_stage((int) $item->id, $existing_payload, 'rebuild_bundle', $analysis, $gate);
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'requeued_rebuild_bundle_after_media_repair';
							break;
						}
						EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'process', 'Нельзя публиковать: у DE-версии не установлено featured image.');
						$run_payload['result'] = 'media_repair_pending';
						continue;
						}
					}
					if ($existing_payload !== [] && in_array($pipeline_stage, ['translate_uk', 'translate_en'], true) && self::worker_pipeline_enabled()) {
						$run_payload['branch'] = 'worker_single_translation_stage';
						$worker_stage = $pipeline_stage;
						self::log_process_item_step('before_worker_stage', (int) $item->id, ['run_id' => $run, 'worker_stage' => $worker_stage]);
						EPV2_Queue::mark_state((int) $item->id, 'processing_de', [
							'error_message' => '',
						]);
						EPV2_Queue::set_live_status(
							(int) $item->id,
							$worker_stage === 'translate_uk'
								? 'Внешний worker собирает украинскую версию из финального DE master.'
								: 'Внешний worker собирает английскую версию из финального DE master.',
							$worker_stage === 'translate_uk' ? 'translating_uk' : 'translating_en'
						);
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
						$worker_response = self::run_worker_stage($item, $worker_stage, $existing_payload);
						$payload = is_array($worker_response['payload'] ?? null) ? $worker_response['payload'] : [];
						$run_payload['worker_stage'] = $worker_stage;
						$run_payload['worker_duration_ms'] = (int) ($worker_response['duration_ms'] ?? 0);
						self::log_process_item_step('after_worker_stage', (int) $item->id, ['run_id' => $run, 'worker_stage' => $worker_stage, 'duration_ms' => self::duration_ms_since($item_started_at)]);
						if (self::fast_transition_item_to_ready_publish((int) $item->id, $payload)) {
							self::log_process_item_step('after_worker_single_translation_ready_publish', (int) $item->id, [
								'run_id' => $run,
								'worker_stage' => $worker_stage,
								'duration_ms' => self::duration_ms_since($item_started_at),
							]);
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'worker_single_translation_ready_publish';
							break;
						}
						$worker_lang = $worker_stage === 'translate_uk' ? 'uk' : 'en';
						$payload = self::refresh_stage_checklist_for_routing($payload);
						$worker_checklist = self::payload_stage_checklist($payload);
						if (empty($worker_checklist[$worker_lang . '_ready'])) {
							$translation_attempts = self::bump_translation_no_progress_attempt((int) $item->id, $worker_lang, $payload);
							if ($translation_attempts >= 3) {
								$result = self::resolve_translation_no_progress_terminally($item, $payload, $worker_lang, $translation_attempts, $analysis, $gate);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = $result;
								break;
							}
							$next_translation_stage = $worker_stage;
							self::queue_incomplete_translation_stage((int) $item->id, $payload, $next_translation_stage, $analysis, $gate);
						} elseif ($worker_stage === 'translate_uk') {
							self::reset_translation_no_progress_attempt((int) $item->id, $worker_lang);
							$next_translation_stage = 'translate_en';
						} else {
							self::reset_translation_no_progress_attempt((int) $item->id, $worker_lang);
							$next_translation_stage = 'publish_finish';
						}
						if ($next_translation_stage !== $worker_stage) {
							try {
								self::queue_required_stage((int) $item->id, $payload, $next_translation_stage, $analysis, $gate);
							} catch (Throwable $e) {
								self::log_process_item_step('after_worker_single_translation_transition_fallback', (int) $item->id, [
									'run_id' => $run,
									'worker_stage' => $worker_stage,
									'blocked_stage' => $next_translation_stage,
									'error' => $e->getMessage(),
									'duration_ms' => self::duration_ms_since($item_started_at),
								]);
								$translation_attempts = self::bump_translation_no_progress_attempt((int) $item->id, $worker_lang, $payload);
								if ($translation_attempts >= 3) {
									$result = self::resolve_translation_no_progress_terminally($item, $payload, $worker_lang, $translation_attempts, $analysis, $gate);
									$count++;
									$run_payload['processed_item_id'] = (int) $item->id;
									$run_payload['result'] = $result;
									break;
								}
								$next_translation_stage = $worker_stage;
								self::queue_incomplete_translation_stage((int) $item->id, $payload, $next_translation_stage, $analysis, $gate);
							}
						}
						self::log_process_item_step('after_worker_single_translation_queue_next', (int) $item->id, [
							'run_id' => $run,
							'worker_stage' => $worker_stage,
							'next_stage' => $next_translation_stage,
							'duration_ms' => self::duration_ms_since($item_started_at),
						]);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'queued_' . $next_translation_stage . '_stage';
						break;
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
						if (in_array($worker_stage, ['translate_uk', 'translate_en'], true)) {
							$worker_lang = $worker_stage === 'translate_uk' ? 'uk' : 'en';
							if (! self::payload_language_ready($payload, $worker_lang) && self::de_master_ready_for_translation($payload)) {
								$payload = self::repair_payload_language($payload, $worker_lang);
							}
						}
						$run_payload['worker_stage'] = $worker_stage;
						$run_payload['worker_duration_ms'] = (int) ($worker_response['duration_ms'] ?? 0);
						self::log_process_item_step('after_worker_stage', (int) $item->id, ['run_id' => $run, 'worker_stage' => $worker_stage, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					// Do not persist here: queue_required_stage()/terminal state below
					// owns persistence. Double-saving large worker payloads can burn CPU.
					self::log_process_item_step('after_worker_persist_skipped_before_routing', (int) $item->id, ['run_id' => $run, 'worker_stage' => $worker_stage, 'duration_ms' => self::duration_ms_since($item_started_at)]);
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
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = (string) ($worker_response['stage_result'] ?? 'worker_translation_stage_success');
						break;
					}
					if (in_array($pipeline_stage, ['translate_uk', 'translate_en'], true)) {
						$run_payload['branch'] = 'translate_single_language';
						$lang_to_repair = $pipeline_stage === 'translate_uk' ? 'uk' : 'en';
						EPV2_Queue::mark_state((int) $item->id, 'processing_de', [
							'error_message' => '',
						]);
						EPV2_Queue::set_live_status(
							(int) $item->id,
							$lang_to_repair === 'uk'
								? 'Собираю украинскую версию из финальной немецкой master-версии.'
								: 'Собираю английскую версию из финальной немецкой master-версии.',
							$lang_to_repair === 'uk' ? 'translating_uk' : 'translating_en'
						);
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
						$payload = self::repair_payload_language($existing_payload, $lang_to_repair);
						if (! self::payload_language_ready($payload, $lang_to_repair) && self::de_master_ready_for_translation($payload)) {
							// Official and service-source items often succeed only on the broader
							// multi-language repair path. Try it before treating the stage as
							// bounded no-progress.
							$payload = self::repair_payload_languages($payload);
						}
						$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
						$payload['_meta']['translations_deferred'] = ! (
							self::payload_language_ready($payload, 'uk') && self::payload_language_ready($payload, 'en')
						);
						$payload = self::set_payload_pipeline_stage($payload, '');
						$payload = self::finalize_payload_for_queue($payload);
						if (! self::payload_language_ready($payload, $lang_to_repair)) {
							if (
								$lang_to_repair === 'uk'
								&& self::de_master_ready_for_translation($payload)
								&& ! self::payload_language_ready($payload, 'en')
							) {
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = self::force_item_continuation(
									$item,
									$payload,
									'translate_en',
									'Украинская ветка не собралась напрямую: сначала принудительно дособираю английскую bridge-версию, затем повторю UK.'
								);
								break;
							}
							$translation_attempts = self::bump_translation_no_progress_attempt((int) $item->id, $lang_to_repair, $payload);
							if ($translation_attempts >= 4) {
								$result = self::resolve_translation_no_progress_terminally($item, $payload, $lang_to_repair, $translation_attempts, $analysis, $gate);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = $result;
								break;
							}
						} else {
							self::reset_translation_no_progress_attempt((int) $item->id, $lang_to_repair);
						}
						self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
						$next_stage = self::payload_next_required_stage($payload);
					if ($next_stage !== '') {
						self::queue_required_stage((int) $item->id, $payload, $next_stage, $analysis, $gate);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'queued_' . $next_stage . '_stage';
						break;
					}
					$next_stage = self::payload_next_required_stage_for_routing($payload);
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
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'translated_' . $lang_to_repair . '_successfully';
						break;
					}
					if (self::payload_needs_enrichment_rebuild($existing_payload)) {
						$run_payload['branch'] = 'translate_finish_rebuild';
						$categories = EPV2_Review::normalize_categories(self::working_category_seed($item, $existing_payload));
						$style = (string) ($existing_payload['_meta']['style'] ?? EPV2_Settings::get('rewrite_style', 'lively'));
						self::bump_review_rebuild_attempt((int) $item->id, $existing_payload);
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
							self::rebuild_generation_options($item, $existing_payload)
						);
						$payload = self::finalize_payload_for_queue($payload);
						self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
						if (! self::de_master_is_viable($payload)) {
							throw new RuntimeException('AI rewrite did not reach minimum DE master quality');
						}
						$next_stage = self::payload_next_required_stage($payload);
						if ($next_stage === '') {
							$next_stage = 'translate_finish';
						}
						if (self::maybe_cooldown_stagnated_rebuild($item, $existing_payload, $payload, $next_stage)) {
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'stagnated_rebuild_cooldown';
							break;
						}
						self::queue_required_stage((int) $item->id, $payload, $next_stage, $analysis, $gate);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'requeued_' . $next_stage . '_after_rebuild';
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
					$payload = self::finalize_payload_for_queue($payload, false);
					self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
					if (! self::languages_look_publishable($payload)) {
						throw new RuntimeException('Language translation repair failed');
					}
					EPV2_Queue::set_live_status((int) $item->id, 'Дотягиваю SEO, мета-описания, языковые хвосты и media до publish-grade.', 'publish_finish');
					EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
					// This is already the dedicated publish-finish pass after translations.
					// Keep it on the narrow finisher path so we do not bounce back into a
					// broad rebuild-style lift that can regress completed language work.
					$payload = self::run_publish_finish_stage($item, $payload, EPV2_Review::normalize_categories(self::working_category_seed($item, $payload)), (string) ($payload['_meta']['style'] ?? EPV2_Settings::get('rewrite_style', 'lively')));
					$payload = self::set_payload_pipeline_stage($payload, '');
					$payload = self::finalize_payload_for_stage_routing($payload, (int) $item->id, $run, $item_started_at);
					self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
					$real_media_candidate = self::payload_primary_media_url($payload) !== ''
						&& ! EPV2_Media::is_generated_story_cover_url(self::payload_primary_media_url($payload));
					if (self::payload_requires_terminal_media_reject($payload) && ! $real_media_candidate) {
						if (self::queue_media_repair_retry($item, $payload, 'terminal_publish_finish')) {
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'requeued_media_repair';
							break;
						}
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = self::force_item_continuation(
							$item,
							$payload,
							'publish_finish',
							'Source-first media ещё не подготовлено: продолжаю обязательную автоматическую медиадоводку вместо terminal reject.',
							30 * MINUTE_IN_SECONDS
						);
						break;
					}
					if (self::payload_requires_media_manual_confirmation($payload)) {
						self::reject_media_manual_sink($item, $payload);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'rejected_media_manual_sink';
						break;
					}
					$next_stage = self::payload_next_required_stage($payload);
					if ($next_stage !== '') {
						if (self::maybe_cooldown_stagnated_rebuild($item, $existing_payload, $payload, $next_stage)) {
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'stagnated_rebuild_cooldown';
							break;
						}
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
					$count++;
					$run_payload['processed_item_id'] = (int) $item->id;
					$run_payload['result'] = 'translated_and_finished_successfully';
					break;
				}
				if ($existing_payload !== [] && ! $repairing_media_blocker && ! $requires_translation_finish && ! $auto_rework && ! $auto_finish) {
					if (self::transition_item_to_ready_publish((int) $item->id, $existing_payload, [
						'error_message' => '',
					])) {
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
				// Anti-resurrect: если sanitize ранее уже отклонил материал
				// как pre-AI publish-priority reject, не воскрешаем через
				// сохранённый payload (ai_publish_finish_resume / ai_rebuild_enrichment).
				// Без этого item циклится: sanitize→reject→resume→retry_process→...
				$prev_error = (string) ($item->error_message ?? '');
				if (mb_stripos($prev_error, 'предварительный publish-priority') !== false) {
					EPV2_Queue::mark_state((int) $item->id, 'rejected', [
						'error_message' => $prev_error,
					]);
					continue;
				}
				// Editorial prompt version mismatch: payload сгенерён старой
				// версией prompt'а (до Russia-Ukraine line / anti-filler /
				// anti-repetition). Drop payload, force fresh AI run на новом
				// prompt'е. Без этой проверки stale payload reused as-is через
				// gate.mode=ai_publish_finish_resume и устаревшие формулировки
				// проходят на сайт (видели 7 violation постов в production).
				$payload_prompt_version = (string) ($existing_payload['_meta']['editorial_prompt_version'] ?? '');
				if ($existing_payload !== [] && $payload_prompt_version !== self::EDITORIAL_PROMPT_VERSION) {
					self::log_process_item_step('drop_stale_payload_version_mismatch', (int) $item->id, [
						'stored' => $payload_prompt_version,
						'current' => self::EDITORIAL_PROMPT_VERSION,
					]);
					$existing_payload = [];
					$stored_selection = [];
				}
				$reused_existing_context = $existing_payload !== [];
				if ($reused_existing_context) {
					$analysis = $stored_selection !== [] ? $stored_selection : [
						'score' => 0,
						'decision' => 'resume_existing_payload',
						'category' => self::working_category_seed($item, $existing_payload),
					];
				} else {
					$analysis = EPV2_Budget_Manager::analyze_item([
						'title' => (string) $source_item->original_title,
						'content' => (string) ($source_item->original_content ?? ''),
						'excerpt' => (string) $source_item->original_excerpt,
						'url' => (string) $source_item->original_url,
						'date' => (string) ($source_item->original_date ?? ''),
						'image' => (string) $source_item->source_image_url,
						'category' => (string) $source_item->category_proposed,
					]);
					$analysis = self::preserve_planner_selected_candidate_analysis($item, $analysis);
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
					$run_payload['result'] = self::force_item_continuation(
						$item,
						$existing_payload !== [] ? $existing_payload : $baseline_payload,
						'build_de_master',
						'Материал не списан внутри очереди: запускаю принудительную доводку вместо reject.'
					);
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
				$fresh_selection_decision = sanitize_key((string) ($analysis['decision'] ?? ''));
				if (
					$existing_payload === []
					&& in_array($fresh_selection_decision, ['low', 'reject'], true)
					&& empty($analysis['top_story_candidate'])
					&& empty($analysis['breaking_candidate'])
					&& empty($analysis['breaking_watch'])
				) {
					// Operator-approved A: don't park reject/low items in
					// 'new' for 2 hours waiting for a maintenance scan to
					// flip them to rejected. The Story-Card analysis was
					// already paid for; selection has its verdict; spinning
					// the row through retry/maintenance only clutters the
					// queue and confuses the operator. Mark rejected
					// immediately with a hard-terminal phrasing so the
					// soft_terminal_state_guard doesn't rescue it.
					$terminal_notes = [
						'selection' => $analysis,
						'gate' => $gate,
						'_system' => [
							'workflow_terminal_reason' => 'selection_publish_blocked',
							'workflow_step_status' => 'terminal',
							'workflow_owner_token' => '',
							'workflow_heartbeat_at' => '',
							'quarantine_reason' => 'selection_' . $fresh_selection_decision,
						],
					];
					EPV2_Queue::mark_state((int) $item->id, 'rejected', [
						'error_message' => sprintf(
							'Материал снят: предварительный publish-priority "%s" не пересматривается перезапуском.',
							$fresh_selection_decision
						),
						'admin_notes' => wp_json_encode($terminal_notes, JSON_UNESCAPED_UNICODE),
					]);
					EPV2_Queue::clear_active_automation_item((int) $item->id);
					if (class_exists('EPV2_Learning_Journal')) {
						EPV2_Learning_Journal::record('quarantine_rejected', (int) $item->id,
							'selection_' . $fresh_selection_decision,
							[
								'score' => (int) ($analysis['score'] ?? 0),
								'category' => (string) ($analysis['category'] ?? ''),
							]
						);
					}
					$count++;
					$run_payload['processed_item_id'] = (int) $item->id;
					$run_payload['result'] = 'rejected_by_preselection';
					break;
				}
				if ($reused_existing_context) {
					$category = self::working_category_seed($item, $existing_payload);
				} else {
					// Use Story-Card-aware detect: if existing_payload (the
					// pre-rewrite payload that already contains the Story
					// Card from analyze_story) carries a high-confidence
					// category.primary, trust it instead of running the
					// keyword regex. The keyword regex over-promotes
					// "ukraine-krieg" mentions and flipped Moscow parade
					// stories to ukraine despite Story Card saying welt.
					$payload_for_detect = is_array($existing_payload) && $existing_payload !== []
						? $existing_payload
						: ((array) (json_decode((string) ($source_item->ai_payload ?? ''), true) ?: []));
					$category = EPV2_Categorizer::detect_with_payload(
						(string) $source_item->original_title,
						(string) ($source_item->original_content ?? ''),
						(string) $source_item->category_proposed,
						$payload_for_detect
					);
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
					$baseline_dossier = EPV2_Source_Enricher::enrich_item($source_item, ['fast_mode' => true]);
					self::log_process_item_step('after_baseline_dossier', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					$baseline_payload = EPV2_Review::build_payload_without_ai_from_dossier(
						$source_item,
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
					$baseline_payload['_meta']['context_memory'] = self::payload_context_memory_light($baseline_payload);
					self::log_process_item_step('before_baseline_persist', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					self::persist_intermediate_payload((int) $item->id, $baseline_payload, $analysis, $gate);
					self::log_process_item_step('after_baseline_persist', (int) $item->id, ['run_id' => $run, 'duration_ms' => self::duration_ms_since($item_started_at)]);
					$existing_payload = $baseline_payload;
				}
				if (! empty($gate['mode']) && $gate['mode'] === 'reject') {
					$gate['allow'] = true;
					$gate['mode'] = 'ai_forced_enrichment';
					$gate['reason'] = 'queued material forced through completion contract';
				}
				{
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
					self::assert_worker_owned_stage_available($pipeline_stage, $auto_rework, $auto_finish, $existing_payload, $gate);
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
							if (self::payload_has_ai_provider_failure($payload)) {
								EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'process', 'AI provider unavailable: All AI providers failed');
								$not_before = gmdate('Y-m-d H:i:s', time() + (30 * MINUTE_IN_SECONDS));
								EPV2_Queue::workflow_system_update((int) $item->id, [
									'workflow_not_before' => $not_before,
									'retry_after' => $not_before,
									'workflow_step_status' => 'pending',
									'workflow_owner_token' => '',
									'workflow_heartbeat_at' => '',
									'workflow_last_error' => 'AI provider unavailable: All AI providers failed',
								]);
								EPV2_Queue::clear_active_automation_item((int) $item->id);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'ai_provider_failure_retry';
								break;
							}
							EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
							self::log_process_item_step('after_worker_publish_ready_short_circuit_skipped', (int) $item->id, [
								'run_id' => $run,
								'duration_ms' => self::duration_ms_since($item_started_at),
							]);
					} elseif (($auto_finish || ! $auto_rework) && $existing_payload !== [] && self::payload_is_review_ready($existing_payload) && self::automation_requires_publish_grade()) {
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
						if (self::payload_needs_enrichment_rebuild($existing_payload) && ! self::publish_finish_resume_is_viable($existing_payload)) {
							$payload = self::attempt_publish_grade_lift(
								$item,
								$existing_payload,
								EPV2_Review::normalize_categories($category),
								(string) EPV2_Settings::get('rewrite_style', 'lively'),
								true
							);
							$payload = self::finalize_payload_for_queue($payload, false);
						} elseif (self::publish_finish_requires_translation_repair($existing_payload)) {
							$payload = self::repair_payload_languages($existing_payload);
							$payload = self::finalize_payload_for_queue($payload, false);
						} else {
							// Review-ready payloads that are being resumed for final publish-grade
							// completion should stay on the publish-finish path. Using the broad
							// lift here can re-enter rebuild-oriented logic and re-open stages
							// that were already completed.
							$payload = self::run_publish_finish_stage($item, $existing_payload, EPV2_Review::normalize_categories($category), (string) EPV2_Settings::get('rewrite_style', 'lively'));
						}
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
							$source_item,
							EPV2_Review::normalize_categories($category),
							(string) EPV2_Settings::get('rewrite_style', 'lively'),
							true,
							$auto_rework ? self::rebuild_generation_options($item, $existing_payload) : []
						);
						EPV2_Lock_Manager::heartbeat('process', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
					}
					if (self::payload_context_rejects($payload)) {
						$payload = self::refresh_stage_checklist($payload);
						self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
						$run_payload['result'] = self::force_item_continuation(
							$item,
							$payload,
							'rebuild_bundle',
							'Контекстный сигнал слабый: запускаю дополнительную автоматическую доводку вместо reject.',
							15 * MINUTE_IN_SECONDS
						);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						break;
					}
					$payload['_meta']['selection'] = $analysis;
					$payload['_meta']['breaking_watch'] = ! empty($analysis['breaking_watch']);
					$payload['_meta']['breaking'] = ! empty($payload['_meta']['breaking']) || ! empty($analysis['breaking_candidate']);
					$payload['_meta']['top_story'] = ! empty($payload['_meta']['top_story']) || ! empty($analysis['top_story_candidate']);
					$payload['_meta']['story_format'] = sanitize_text_field((string) ($item->story_format ?? ''));
					$payload['_meta']['cluster_id'] = (int) ($item->cluster_id ?? 0);
					$payload['_meta']['topic_label'] = sanitize_text_field((string) ($item->topic_label ?? ''));
					self::log_process_item_step('before_stage_routing_finalize', (int) $item->id, [
						'run_id' => $run,
						'duration_ms' => self::duration_ms_since($item_started_at),
					]);
					$payload = self::finalize_payload_for_stage_routing($payload, (int) $item->id, $run, $item_started_at);
					self::log_process_item_step('after_stage_routing_finalize', (int) $item->id, [
						'run_id' => $run,
						'duration_ms' => self::duration_ms_since($item_started_at),
					]);
					if (! empty($payload['_meta']['translations_deferred'])) {
						if (! self::de_master_is_viable($payload)) {
							throw new RuntimeException('AI rewrite did not reach minimum DE master quality');
						}
						$translation_stage = self::payload_next_required_stage_for_routing($payload);
						if ($translation_stage === '') {
							$translation_stage = 'translate_finish';
						}
						self::queue_required_stage((int) $item->id, $payload, $translation_stage, $analysis, $gate);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'queued_' . $translation_stage . '_stage';
							break;
						}
					self::log_process_item_step('before_intermediate_persist', (int) $item->id, [
						'run_id' => $run,
						'duration_ms' => self::duration_ms_since($item_started_at),
					]);
						self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
						self::log_process_item_step('after_intermediate_persist', (int) $item->id, [
							'run_id' => $run,
							'duration_ms' => self::duration_ms_since($item_started_at),
						]);
							// Worker-blocker terminalization. Previously this was nested
							// inside `if ($worker_stage === 'rebuild_bundle')`, so when
							// publish_finish or translate_* returned `_meta.blockers`
							// (e.g. "Primary source too thin for autopublish") the row
							// kept cycling under a 30-minute cooldown instead of moving
							// to ready_review. Hoisted out: any worker stage that
							// returns blockers or ready_review outcome terminalizes
							// immediately.
							$worker_outcome_global = sanitize_key((string) ($worker_response['outcome'] ?? ''));
							$worker_blockers_global = self::payload_blocker_strings($payload);
							if (
								isset($worker_stage)
								&& in_array((string) $worker_stage, ['rebuild_bundle', 'publish_finish', 'translate_uk', 'translate_en', 'translate_finish'], true)
								&& ($worker_outcome_global === 'ready_review' || $worker_blockers_global !== [])
							) {
								$payload = self::set_payload_pipeline_stage($payload, '');
								$terminal_gate_global = EPV2_Publish_Gate::evaluate($item, $payload, [
									'context' => 'worker_terminal_outcome',
								]);
								// Fix E: don't mass-reject items whose only sin is a paywalled
								// thin-source feed. The original ingest score already cleared the
								// publish_c threshold, and the upfront story_card explicitly
								// labels the piece as publishable. Worker re-scoring during
								// rebuild often drifts to selection.decision='low' purely because
								// the rewrite saw only the thin RSS excerpt — that's a tooling
								// artefact, not an editorial verdict. Trust the ingest signal +
								// card and route to ready_review for human triage instead of
								// killing the row.
								$ingest_score = (int) ($item->story_score ?? 0);
								$story_card_payload = is_array($payload['_meta']['story_card'] ?? null)
									? $payload['_meta']['story_card']
									: [];
								$card_estimate = strtolower((string) ($story_card_payload['publishable_estimate'] ?? ''));
								$only_thin_blocker =
									count($worker_blockers_global) === 1
									&& stripos($worker_blockers_global[0] ?? '', 'too thin') !== false;
								$strong_ingest = $ingest_score >= 40;
								$strong_card = in_array($card_estimate, ['high', 'medium'], true);
								$selection_publishable = ! empty($terminal_gate_global['selection_publishable']);
								if (! $selection_publishable && $only_thin_blocker && ($strong_ingest || $strong_card)) {
									$selection_publishable = true;
								}
								$terminal_state_global = $selection_publishable ? 'ready_review' : 'rejected';
								$terminal_notes_global = [
									'selection' => $analysis,
									'gate' => $gate,
									'_system' => [
										'workflow_terminal_reason' => 'worker_terminal_outcome',
										'quarantine_reason' => $worker_blockers_global !== [] ? 'worker_blockers' : 'worker_ready_review',
										'worker_outcome' => $worker_outcome_global,
										'worker_blockers' => $worker_blockers_global,
										'worker_stage_at_terminal' => (string) $worker_stage,
										'last_publish_gate_blockers' => array_values((array) ($terminal_gate_global['blockers'] ?? [])),
										'workflow_step' => '',
										'workflow_step_status' => 'terminal',
										'workflow_owner_token' => '',
										'workflow_heartbeat_at' => '',
										'next_operator_action' => $terminal_state_global === 'ready_review' ? 'manual_editorial_review' : 'review_source_or_restore_manually',
									],
								];
								if ($terminal_state_global === 'ready_review') {
									$terminal_notes_global['_system']['manual_confirmation_required'] = 'worker_blockers';
								}
								EPV2_Queue::mark_state((int) $item->id, $terminal_state_global, [
									'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
									'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
									'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
									'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
									'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
									'error_message' => $terminal_state_global === 'ready_review'
										? sprintf(
											'Материал остановлен для ручной проверки на стадии %s: worker вернул blockers (%s).',
											(string) $worker_stage,
											implode(', ', $worker_blockers_global)
										)
										: sprintf(
											'Материал снят с автопубликации на стадии %s: canonical publish gate заблокировал selection decision "%s".',
											(string) $worker_stage,
											(string) ($terminal_gate_global['selection_decision'] ?? 'unknown')
										),
									'admin_notes' => wp_json_encode($terminal_notes_global, JSON_UNESCAPED_UNICODE),
								]);
								self::log_process_item_step('after_worker_terminal_outcome_global', (int) $item->id, [
									'run_id' => $run,
									'state' => $terminal_state_global,
									'worker_stage' => (string) $worker_stage,
									'worker_outcome' => $worker_outcome_global,
									'blockers' => $worker_blockers_global,
									'duration_ms' => self::duration_ms_since($item_started_at),
								]);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'worker_terminal_' . $terminal_state_global . '_' . (string) $worker_stage;
								break;
							}
							if (isset($worker_stage) && (string) $worker_stage === 'rebuild_bundle') {
							$worker_outcome = sanitize_key((string) ($worker_response['outcome'] ?? ''));
							$payload_blockers = self::payload_blocker_strings($payload);
							if ($worker_outcome === 'ready_review' || $payload_blockers !== []) {
								$payload = self::set_payload_pipeline_stage($payload, '');
								$terminal_gate = EPV2_Publish_Gate::evaluate($item, $payload, [
									'context' => 'worker_terminal_outcome',
								]);
								$terminal_state = empty($terminal_gate['selection_publishable']) ? 'rejected' : 'ready_review';
								$terminal_notes = [
									'selection' => $analysis,
									'gate' => $gate,
									'_system' => [
										'workflow_terminal_reason' => 'worker_terminal_outcome',
										'quarantine_reason' => $payload_blockers !== [] ? 'worker_blockers' : 'worker_ready_review',
										'worker_outcome' => $worker_outcome,
										'worker_blockers' => $payload_blockers,
										'last_publish_gate_blockers' => array_values((array) ($terminal_gate['blockers'] ?? [])),
										'workflow_step' => '',
										'workflow_step_status' => 'terminal',
										'workflow_owner_token' => '',
										'workflow_heartbeat_at' => '',
										'next_operator_action' => $terminal_state === 'ready_review' ? 'manual_editorial_review' : 'review_source_or_restore_manually',
									],
								];
								if ($terminal_state === 'ready_review') {
									$terminal_notes['_system']['manual_confirmation_required'] = 'worker_blockers';
								}
								EPV2_Queue::mark_state((int) $item->id, $terminal_state, [
									'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
									'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
									'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
									'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
									'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
									'error_message' => $terminal_state === 'ready_review'
										? 'Материал остановлен для ручной проверки: worker вернул terminal review/blockers (' . implode(', ', $payload_blockers) . ').'
										: 'Материал снят с автопубликации: canonical publish gate заблокировал selection decision "' . (string) ($terminal_gate['selection_decision'] ?? 'unknown') . '".',
									'admin_notes' => wp_json_encode($terminal_notes, JSON_UNESCAPED_UNICODE),
								]);
								self::log_process_item_step('after_worker_terminal_outcome', (int) $item->id, [
									'run_id' => $run,
									'state' => $terminal_state,
									'worker_outcome' => $worker_outcome,
									'blockers' => $payload_blockers,
									'duration_ms' => self::duration_ms_since($item_started_at),
								]);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'worker_terminal_' . $terminal_state;
								break;
								}
								if (self::worker_rebuild_payload_should_continue_to_publish_finish($payload)) {
									self::queue_required_stage((int) $item->id, $payload, 'publish_finish', $analysis, $gate);
									self::log_process_item_step('after_worker_rebuild_force_publish_finish', (int) $item->id, [
										'run_id' => $run,
										'duration_ms' => self::duration_ms_since($item_started_at),
									]);
									$count++;
									$run_payload['processed_item_id'] = (int) $item->id;
									$run_payload['result'] = 'queued_publish_finish_stage';
									break;
								}
								$next_stage = self::payload_next_stage_from_cached_checklist($payload);
								if ($next_stage !== '') {
									self::queue_required_stage((int) $item->id, $payload, $next_stage, $analysis, $gate);
								self::log_process_item_step('after_worker_rebuild_queue_next', (int) $item->id, [
									'run_id' => $run,
									'next_stage' => $next_stage,
									'duration_ms' => self::duration_ms_since($item_started_at),
								]);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'queued_' . $next_stage . '_stage';
								break;
							}
							if (
								self::fast_transition_item_to_ready_publish((int) $item->id, $payload)
								|| self::transition_item_to_ready_publish((int) $item->id, $payload)
							) {
								self::log_process_item_step('after_worker_rebuild_ready_publish', (int) $item->id, [
									'run_id' => $run,
									'duration_ms' => self::duration_ms_since($item_started_at),
								]);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'worker_rebuild_ready_publish';
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
							self::log_process_item_step('after_worker_rebuild_mark_state', (int) $item->id, [
								'run_id' => $run,
								'next_state' => $next_state,
								'duration_ms' => self::duration_ms_since($item_started_at),
							]);
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'processed_successfully';
								break;
							}
							if (isset($worker_stage) && (string) $worker_stage === 'publish_finish') {
								$next_stage = self::payload_next_stage_from_cached_checklist($payload);
								if ($next_stage !== '' && $next_stage !== 'publish_finish') {
									self::queue_required_stage((int) $item->id, $payload, $next_stage, $analysis, $gate);
									self::log_process_item_step('after_worker_publish_finish_queue_next', (int) $item->id, [
										'run_id' => $run,
										'next_stage' => $next_stage,
										'duration_ms' => self::duration_ms_since($item_started_at),
									]);
									$count++;
									$run_payload['processed_item_id'] = (int) $item->id;
									$run_payload['result'] = 'queued_' . $next_stage . '_stage';
									break;
								}
									if (self::transition_item_to_ready_publish((int) $item->id, $payload)) {
										self::log_process_item_step('after_worker_publish_finish_ready_publish', (int) $item->id, [
											'run_id' => $run,
											'duration_ms' => self::duration_ms_since($item_started_at),
										]);
									$count++;
									$run_payload['processed_item_id'] = (int) $item->id;
									$run_payload['result'] = 'worker_publish_finish_ready_publish';
									break;
								}
									$next_state = self::next_state_after_processing($payload);
									if ($next_state !== 'ready_publish') {
										$fresh_finish_item = EPV2_Queue::get_item((int) $item->id) ?: $item;
										$no_progress_count = (int) ($payload['_meta']['publish_finish_no_progress'] ?? 0);
										$fresh_finish_notes = json_decode((string) ($fresh_finish_item->admin_notes ?? ''), true);
										$fresh_finish_notes = is_array($fresh_finish_notes) ? $fresh_finish_notes : [];
										$fresh_finish_system = is_array($fresh_finish_notes['_system'] ?? null) ? $fresh_finish_notes['_system'] : [];
										$finish_retries = (int) ($fresh_finish_system['retries']['review_finish'] ?? 0);
										$workflow_attempts = (int) ($fresh_finish_system['workflow_step_attempts'] ?? 0);
										if (self::review_finish_exhausted($fresh_finish_item, $payload) || $no_progress_count >= 2 || $finish_retries >= 2 || $workflow_attempts >= 4) {
											self::log_process_item_step('after_worker_publish_finish_bounded_cooldown', (int) $item->id, [
												'run_id' => $run,
												'next_state' => $next_state,
												'no_progress' => $no_progress_count,
												'finish_retries' => $finish_retries,
												'workflow_attempts' => $workflow_attempts,
												'duration_ms' => self::duration_ms_since($item_started_at),
											]);
											$count++;
											$run_payload['processed_item_id'] = (int) $item->id;
											$run_payload['result'] = self::force_item_continuation(
												$fresh_finish_item,
												$payload,
												'publish_finish',
												'Publish-finish не дал прогресса после нескольких попыток; автоматика освободила очередь и повторит доводку позже.',
												30 * MINUTE_IN_SECONDS
											);
											break;
										}
										self::queue_required_stage((int) $item->id, $payload, 'publish_finish', $analysis, $gate);
										self::log_process_item_step('after_worker_publish_finish_requeue_same_stage', (int) $item->id, [
											'run_id' => $run,
											'next_state' => $next_state,
											'duration_ms' => self::duration_ms_since($item_started_at),
									]);
									$count++;
									$run_payload['processed_item_id'] = (int) $item->id;
									$run_payload['result'] = 'queued_publish_finish_stage';
									break;
								}
								EPV2_Queue::mark_state((int) $item->id, $next_state, [
									'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
									'ai_payload' => wp_json_encode(self::set_payload_pipeline_stage($payload, ''), JSON_UNESCAPED_UNICODE),
									'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
									'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
									'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
									'error_message' => '',
								]);
								self::log_process_item_step('after_worker_publish_finish_mark_state', (int) $item->id, [
									'run_id' => $run,
									'next_state' => $next_state,
									'duration_ms' => self::duration_ms_since($item_started_at),
								]);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'processed_successfully';
								break;
							}
							if (isset($worker_stage) && in_array((string) $worker_stage, ['translate_uk', 'translate_en'], true)) {
							$worker_lang = (string) $worker_stage === 'translate_uk' ? 'uk' : 'en';
							$payload = self::refresh_stage_checklist_for_routing($payload);
							$worker_checklist = self::payload_stage_checklist($payload);
							if (empty($worker_checklist[$worker_lang . '_ready'])) {
								$next_translation_stage = (string) $worker_stage;
								$translation_attempts = self::bump_translation_no_progress_attempt((int) $item->id, $worker_lang, $payload);
								if ($translation_attempts >= 3) {
									$result = self::resolve_translation_no_progress_terminally($item, $payload, $worker_lang, $translation_attempts, $analysis, $gate);
									$count++;
									$run_payload['processed_item_id'] = (int) $item->id;
									$run_payload['result'] = $result;
									break;
								}
								self::queue_incomplete_translation_stage((int) $item->id, $payload, $next_translation_stage, $analysis, $gate);
								self::log_process_item_step('after_staged_translation_requeue_incomplete', (int) $item->id, [
									'run_id' => $run,
									'worker_stage' => (string) $worker_stage,
									'next_stage' => $next_translation_stage,
									'duration_ms' => self::duration_ms_since($item_started_at),
								]);
								$count++;
								$run_payload['processed_item_id'] = (int) $item->id;
								$run_payload['result'] = 'queued_' . $next_translation_stage . '_stage';
								break;
							} elseif ((string) $worker_stage === 'translate_uk') {
								self::reset_translation_no_progress_attempt((int) $item->id, $worker_lang);
								$next_translation_stage = 'translate_en';
							} else {
								self::reset_translation_no_progress_attempt((int) $item->id, $worker_lang);
								$next_translation_stage = 'publish_finish';
							}
							try {
								self::queue_required_stage((int) $item->id, $payload, $next_translation_stage, $analysis, $gate);
							} catch (Throwable $e) {
								self::log_process_item_step('after_staged_translation_transition_fallback', (int) $item->id, [
									'run_id' => $run,
									'worker_stage' => (string) $worker_stage,
									'blocked_stage' => $next_translation_stage,
									'error' => $e->getMessage(),
									'duration_ms' => self::duration_ms_since($item_started_at),
								]);
								$next_translation_stage = (string) $worker_stage;
								$translation_attempts = self::bump_translation_no_progress_attempt((int) $item->id, $worker_lang, $payload);
								if ($translation_attempts >= 3) {
									$result = self::resolve_translation_no_progress_terminally($item, $payload, $worker_lang, $translation_attempts, $analysis, $gate);
									$count++;
									$run_payload['processed_item_id'] = (int) $item->id;
									$run_payload['result'] = $result;
									break;
								}
								self::queue_incomplete_translation_stage((int) $item->id, $payload, $next_translation_stage, $analysis, $gate);
							}
							self::log_process_item_step('after_staged_translation_queue_next', (int) $item->id, [
								'run_id' => $run,
								'worker_stage' => (string) $worker_stage,
								'next_stage' => $next_translation_stage,
								'duration_ms' => self::duration_ms_since($item_started_at),
							]);
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'queued_' . $next_translation_stage . '_stage';
							break;
						}
						if (self::payload_requires_retry_after_ai($payload, $gate)) {
							throw new RuntimeException('AI rewrite fell back to heuristic payload');
						}
					if (self::payload_context_rejects($payload)) {
						$payload = self::refresh_stage_checklist($payload);
						$run_payload['result'] = self::force_item_continuation(
							$item,
							$payload,
							'rebuild_bundle',
							'Контекстный сигнал слабый после анализа: материал отправлен на дополнительную автоматическую доводку.',
							15 * MINUTE_IN_SECONDS
						);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						break;
					}
					$real_media_candidate = self::payload_primary_media_url($payload) !== ''
						&& ! EPV2_Media::is_generated_story_cover_url(self::payload_primary_media_url($payload));
					if (self::payload_requires_terminal_media_reject($payload) && ! $real_media_candidate) {
						if (self::queue_media_repair_retry($item, $payload, 'terminal_post_analysis')) {
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'requeued_media_repair';
							break;
						}
						$run_payload['result'] = self::force_item_continuation(
							$item,
							$payload,
							'publish_finish',
							'Source-first media не найдено сразу: материал возвращён в обязательную media-доводку вместо reject.',
							30 * MINUTE_IN_SECONDS
						);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						break;
					}
					if (self::payload_requires_media_manual_confirmation($payload)) {
						self::force_item_continuation(
							$item,
							$payload,
							'publish_finish',
							'Материал не отправлен в media terminal sink: продолжаю автоматическую media-доводку.',
							30 * MINUTE_IN_SECONDS
						);
						$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'requeued_media_manual_sink';
						break;
					}
						$next_stage = self::payload_next_stage_from_cached_checklist($payload);
						if ($next_stage !== '') {
							self::queue_required_stage((int) $item->id, $payload, $next_stage, $analysis, $gate);
							$count++;
						$run_payload['processed_item_id'] = (int) $item->id;
						$run_payload['result'] = 'queued_' . $next_stage . '_stage';
						break;
					}
					$next_stage = self::payload_next_required_stage_for_routing($payload);
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
					if ($next_state !== 'ready_publish') {
						$stored_item = EPV2_Queue::get_item((int) $item->id);
						$stored_payload = $stored_item ? json_decode((string) ($stored_item->ai_payload ?? ''), true) : [];
						$stored_payload = is_array($stored_payload) ? $stored_payload : [];
						$next_stage = self::payload_next_required_stage_for_routing($stored_payload);
						if ($next_stage !== '') {
							self::queue_required_stage((int) $item->id, $stored_payload, $next_stage, $analysis, $gate);
							$count++;
							$run_payload['processed_item_id'] = (int) $item->id;
							$run_payload['result'] = 'queued_' . $next_stage . '_stage_after_save';
							break;
						}
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
					$run_payload['result'] = self::force_item_continuation(
						$item,
						$payload_for_review,
						'rebuild_bundle',
						'Контекстный reject переведён в обязательную автоматическую доводку.',
						15 * MINUTE_IN_SECONDS
					);
					break;
				}
				if (
					is_array($payload_for_review)
					&& ! empty($payload_for_review)
					&& preg_match('/minimum DE master quality/i', $e->getMessage()) === 1
				) {
					$result = self::resolve_de_master_quality_failure($item, $payload_for_review, $analysis, $gate);
					$run_payload['result'] = $result;
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
					$payload_for_retry = is_array($payload_for_review ?? null) ? $payload_for_review : [];
					$run_payload['result'] = self::force_item_continuation(
						$item,
						$payload_for_retry,
						$pipeline_stage !== '' ? $pipeline_stage : 'rebuild_bundle',
						'Автоматическая обработка дала жёсткую ошибку, но материал остаётся в обязательной доводке вместо terminal reject: ' . $e->getMessage(),
						30 * MINUTE_IN_SECONDS
					);
					break;
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
		} finally {
			EPV2_Lock_Manager::release('process', $lock);
			if (EPV2_Queue::has_processable_items() && ! EPV2_Lock_Manager::is_active('process')) {
				EPV2_Jobs::enqueue_process();
			}
		}
	}

	private static function persist_intermediate_payload(int $item_id, array $payload, array $analysis = [], array $gate = []): void {
		if ($item_id <= 0 || $payload === []) {
			return;
		}
		$payload = self::compact_payload_source_dossier($payload);
		$payload = self::refresh_stage_checklist_for_routing($payload);
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
		$notes['_system']['context_memory'] = is_array($payload['_meta']['context_memory'] ?? null)
			? (array) $payload['_meta']['context_memory']
			: self::payload_context_memory_light($payload);
		$fields['admin_notes'] = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
		EPV2_Queue::update_fields($item_id, $fields);
	}

	private static function queue_next_processing_stage(int $item_id, array $payload, string $stage, string $status, string $status_code, array $analysis = [], array $gate = []): void {
		// Never persist a next processing stage on top of stale quality or stale
		// translation metadata. Re-finalize first so v2 routing decisions operate
		// on the current payload, not on cached warnings from a previous step.
		$payload = self::finalize_payload_for_queue($payload);
		if ($stage === 'translate_en' && ! self::payload_language_ready_for_routing($payload, 'uk')) {
			$stage = 'translate_uk';
			$definition = self::stage_status_definition($stage);
			$status = (string) ($definition['status'] ?? $status);
			$status_code = (string) ($definition['code'] ?? $status_code);
		} elseif ($stage === 'publish_finish' && ! self::payload_language_ready_for_routing($payload, 'uk')) {
			$stage = 'translate_uk';
			$definition = self::stage_status_definition($stage);
			$status = (string) ($definition['status'] ?? $status);
			$status_code = (string) ($definition['code'] ?? $status_code);
		} elseif ($stage === 'publish_finish' && ! self::payload_language_ready_for_routing($payload, 'en')) {
			$stage = 'translate_en';
			$definition = self::stage_status_definition($stage);
			$status = (string) ($definition['status'] ?? $status);
			$status_code = (string) ($definition['code'] ?? $status_code);
		}
			self::assert_stage_transition_ready($payload, $stage);
			$payload = self::set_payload_pipeline_stage($payload, $stage);
			$payload = in_array($stage, ['translate_uk', 'translate_en', 'translate_finish', 'publish_finish'], true)
				? self::refresh_stage_checklist_for_routing($payload)
				: self::refresh_stage_checklist($payload);
			$payload = self::set_payload_pipeline_stage($payload, $stage);
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
		$notes['_system']['context_memory'] = is_array($payload['_meta']['context_memory'] ?? null)
			? (array) $payload['_meta']['context_memory']
			: self::payload_context_memory_light($payload);
		self::clear_workflow_retry_window($notes);
		$target_state = (class_exists('EPV2_Jobs') && EPV2_Jobs::orchestrator_v2_enabled()) ? 'new' : 'retry_process';
		EPV2_Queue::mark_state($item_id, $target_state, [
			'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			'error_message' => '',
		]);
		if (class_exists('EPV2_Jobs') && EPV2_Jobs::orchestrator_v2_enabled()) {
			$workflow_step = self::canonical_workflow_step_from_stage($stage, $payload);
			EPV2_Queue::workflow_system_update($item_id, [
				'workflow_step' => $workflow_step,
				'workflow_step_status' => 'pending',
			]);
		}
		EPV2_Queue::set_live_status($item_id, $status, $status_code);
	}

	private static function set_workflow_retry_window(array &$notes, int $delay_seconds): void {
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		if ($delay_seconds <= 0) {
			self::clear_workflow_retry_window($notes);
			return;
		}
		$not_before = gmdate('Y-m-d H:i:s', time() + $delay_seconds);
		$notes['_system']['workflow_not_before'] = $not_before;
		$notes['_system']['retry_after'] = $not_before;
	}

	private static function clear_workflow_retry_window(array &$notes): void {
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		unset($notes['_system']['workflow_not_before'], $notes['_system']['retry_after']);
	}

	private static function queue_required_stage(int $item_id, array $payload, string $stage, array $analysis = [], array $gate = []): void {
		$definition = self::stage_status_definition($stage);
		if ($definition === []) {
			return;
		}
		self::queue_next_processing_stage($item_id, $payload, $stage, $definition['status'], $definition['code'], $analysis, $gate);
	}

	private static function queue_incomplete_translation_stage(int $item_id, array $payload, string $stage, array $analysis = [], array $gate = []): void {
		$definition = self::stage_status_definition($stage);
		if ($definition === [] || ! in_array($stage, ['translate_uk', 'translate_en'], true)) {
			return;
		}
		$payload = self::set_payload_pipeline_stage($payload, $stage);
		$item = EPV2_Queue::get_item($item_id);
		$notes = $item ? json_decode((string) ($item->admin_notes ?? ''), true) : [];
		$notes = is_array($notes) ? $notes : [];
		if ($analysis !== []) {
			$notes['selection'] = $analysis;
		}
		if ($gate !== []) {
			$notes['gate'] = $gate;
		}
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['context_memory'] = is_array($payload['_meta']['context_memory'] ?? null)
			? (array) $payload['_meta']['context_memory']
			: self::payload_context_memory_light($payload);
		self::set_workflow_retry_window($notes, 2 * MINUTE_IN_SECONDS);
		$notes['_system']['workflow_step'] = self::canonical_workflow_step_from_stage($stage, $payload);
		$notes['_system']['workflow_step_status'] = 'pending';
		$notes['_system']['live_status'] = (string) ($definition['status'] ?? '') . ' Следующая попытка ограничена защитным cooldown.';
		$notes['_system']['live_status_code'] = (string) ($definition['code'] ?? '');
		EPV2_Queue::update_fields($item_id, [
			'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			'error_message' => '',
		]);
	}

	private static function run_active_workflow_step(object $item, array $payload_for_stage, array $existing_payload = []): array {
		$workflow_step = self::resolve_active_workflow_step($item, $payload_for_stage, $existing_payload);
		$pipeline_stage = self::pipeline_stage_from_workflow_step($workflow_step, $payload_for_stage !== [] ? $payload_for_stage : $existing_payload);
		if ($pipeline_stage !== '' && $payload_for_stage !== []) {
			$payload_for_stage = self::set_payload_pipeline_stage($payload_for_stage, $pipeline_stage);
		}
		EPV2_Queue::workflow_system_update((int) $item->id, [
			'workflow_step' => $workflow_step,
			'workflow_step_status' => 'running',
			'workflow_step_attempts' => self::next_workflow_step_attempt((int) $item->id, $workflow_step),
		]);
		return [
			'workflow_step' => $workflow_step,
			'pipeline_stage' => $pipeline_stage,
			'payload' => $payload_for_stage,
		];
	}

	private static function resolve_active_workflow_step(object $item, array $payload_for_stage, array $existing_payload = []): string {
		$stored_step = '';
		if (class_exists('EPV2_Queue')) {
			$stored_step = EPV2_Queue::workflow_step($item);
		}
		if ($stored_step !== '') {
			return $stored_step;
		}
		$payload = $payload_for_stage !== [] ? $payload_for_stage : $existing_payload;
		$stored_pipeline_stage = self::payload_pipeline_stage($payload);
		if ($stored_pipeline_stage !== '') {
			return self::canonical_workflow_step_from_stage($stored_pipeline_stage, $payload);
		}
		if (self::payload_has_fast_ready_publish_markers($payload)) {
			return 'publish_ready_gate';
		}
		$required_stage = self::payload_next_required_stage($payload);
		if ($required_stage !== '') {
			return self::canonical_workflow_step_from_stage($required_stage, $payload);
		}
		if ($payload !== [] && self::payload_is_terminal_publish_ready($payload)) {
			return 'publish_ready_gate';
		}
		return 'build_de_master';
	}

	private static function payload_has_fast_ready_publish_markers(array $payload): bool {
		if ($payload === []) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$stage_checklist = is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
		if (! empty($stage_checklist['ready_publish'])) {
			return true;
		}
		if (empty($stage_checklist['translations_ready']) || empty($stage_checklist['publish_finish_ready'])) {
			return false;
		}
		$primary_media = self::payload_primary_media_url($payload);
		if ($primary_media === '' || EPV2_Media::is_generated_story_cover_url($primary_media)) {
			return false;
		}
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$seo_title = trim((string) ($de['seo_title'] ?? ''));
		$meta_description = trim((string) ($de['meta_description'] ?? ''));
		return $seo_title !== '' && $meta_description !== '';
	}

	private static function canonical_workflow_step_from_stage(string $stage, array $payload = []): string {
		$stage = sanitize_key($stage);
		return match ($stage) {
			'rebuild_bundle' => 'build_de_master',
			'translate_uk' => 'translate_uk',
			'translate_en', 'translate_finish' => 'translate_en',
			'publish_finish' => self::publish_finish_workflow_step($payload),
			default => 'build_de_master',
		};
	}

	private static function pipeline_stage_from_workflow_step(string $workflow_step, array $payload = []): string {
		$workflow_step = sanitize_key($workflow_step);
		return match ($workflow_step) {
			'build_de_master' => 'rebuild_bundle',
			'translate_uk' => 'translate_uk',
			'translate_en' => 'translate_en',
			'finalize_media', 'finalize_seo', 'publish_ready_gate' => 'publish_finish',
			default => self::payload_next_required_stage($payload),
		};
	}

	private static function publish_finish_workflow_step(array $payload): string {
		$payload = self::refresh_stage_checklist($payload);
		if (! self::publish_ready_gate_media_contract_passes($payload)) {
			return 'finalize_media';
		}
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$seo_title = trim((string) ($de['seo_title'] ?? ''));
		$meta_description = trim((string) ($de['meta_description'] ?? ''));
		if ($seo_title === '' || $meta_description === '') {
			return 'finalize_seo';
		}
		return 'publish_ready_gate';
	}

	private static function next_workflow_step_attempt(int $item_id, string $workflow_step): int {
		$item = EPV2_Queue::get_item_summary($item_id);
		if (! $item) {
			return 1;
		}
		$system = EPV2_Queue::workflow_system_payload($item);
		$current_step = sanitize_key((string) ($system['workflow_step'] ?? ''));
		$current_attempts = (int) ($system['workflow_step_attempts'] ?? 0);
		if ($current_step === sanitize_key($workflow_step) && $current_attempts > 0) {
			return $current_attempts + 1;
		}
		return 1;
	}

	private static function assert_stage_transition_ready(array $payload, string $stage): void {
		$checklist = self::payload_stage_checklist(self::refresh_stage_checklist($payload));
		switch ($stage) {
			case 'rebuild_bundle':
				return;
			case 'translate_uk':
				if (empty($checklist['de_master_ready'])) {
					throw new RuntimeException('Переход к translate_uk запрещён: DE master не подтверждён.');
				}
				return;
			case 'translate_en':
				if (
					empty($checklist['de_master_ready'])
					|| (empty($checklist['uk_ready']) && ! self::payload_language_ready_for_routing($payload, 'uk'))
				) {
					throw new RuntimeException('Переход к translate_en запрещён: UK этап не подтверждён.');
				}
				return;
			case 'translate_finish':
				if (empty($checklist['de_master_ready'])) {
					throw new RuntimeException('Переход к translate_finish запрещён: DE master не подтверждён.');
				}
				return;
			case 'publish_finish':
				$de_publish_ready = ! empty($checklist['publish_finish_ready']) || ! empty($checklist['de_master_ready']);
				$translations_ready = ! empty($checklist['translations_ready'])
					|| (
						self::payload_language_ready_for_routing($payload, 'uk')
						&& self::payload_language_ready_for_routing($payload, 'en')
					);
				if (! $de_publish_ready && ! $translations_ready) {
					throw new RuntimeException('Переход к publish_finish запрещён: DE master и переводы не подтверждены.');
				}
				return;
			default:
				return;
		}
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
		if (! class_exists('EPV2_Worker_Client') || ! EPV2_Worker_Client::enabled()) {
			return false;
		}
		if (class_exists('EPV2_Jobs') && EPV2_Jobs::server_orchestrator_enabled() && ! EPV2_Jobs::orchestrator_v2_enabled()) {
			return false;
		}
		return EPV2_Worker_Client::is_available();
	}

	private static function worker_owned_canonical_mode(): bool {
		return class_exists('EPV2_Jobs')
			&& EPV2_Jobs::server_orchestrator_enabled()
			&& EPV2_Jobs::orchestrator_v2_enabled()
			&& class_exists('EPV2_Worker_Client')
			&& EPV2_Worker_Client::enabled();
	}

	private static function worker_owned_stage_required(string $pipeline_stage, bool $auto_rework, bool $auto_finish, array $existing_payload, array $gate): bool {
		if (! self::worker_owned_canonical_mode()) {
			return false;
		}
		if (in_array($pipeline_stage, ['rebuild_bundle', 'translate_uk', 'translate_en', 'translate_finish', 'publish_finish'], true)) {
			return true;
		}
		if ($auto_rework || $auto_finish || ! empty($gate['allow'])) {
			return true;
		}
		return $existing_payload !== []
			&& self::payload_is_review_ready($existing_payload)
			&& self::automation_requires_publish_grade();
	}

	private static function assert_worker_owned_stage_available(string $pipeline_stage, bool $auto_rework, bool $auto_finish, array $existing_payload, array $gate): void {
		if (! self::worker_owned_stage_required($pipeline_stage, $auto_rework, $auto_finish, $existing_payload, $gate)) {
			return;
		}
		if (! class_exists('EPV2_Worker_Client') || ! EPV2_Worker_Client::enabled()) {
			throw new RuntimeException('Canonical worker-owned stage requires external worker, but worker mode is disabled.');
		}
		if (! EPV2_Worker_Client::is_available()) {
			throw new RuntimeException('Canonical worker-owned stage deferred: external worker unavailable.');
		}
	}

		private static function worker_stage_for_request(array $existing_payload, bool $auto_rework, bool $auto_finish): string {
			$stored_stage = self::payload_pipeline_stage($existing_payload);
			if (in_array($stored_stage, ['rebuild_bundle', 'translate_uk', 'translate_en', 'translate_finish', 'publish_finish'], true)) {
				return $stored_stage;
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
			if (! EPV2_Resilience_Manager::workflow_stage_available($stage)) {
				$until = EPV2_Resilience_Manager::workflow_stage_cooldown_until($stage);
				throw new RuntimeException('Workflow stage circuit open for ' . $stage . ' until ' . ($until > 0 ? gmdate('Y-m-d H:i:s', $until) : 'unknown'));
			}
			try {
				$response = EPV2_Worker_Client::run_for_item($item, $stage, $existing_payload);
			} catch (Throwable $e) {
				if (preg_match('/all ai providers failed|no ready provider|provider unavailable/iu', $e->getMessage()) === 1) {
					EPV2_Resilience_Manager::register_workflow_stage_failure($stage, $e->getMessage());
				}
				throw $e;
			}
			$payload = is_array($response['payload'] ?? null) ? $response['payload'] : [];
			if ($payload === []) {
				throw new RuntimeException('External worker returned empty payload');
			}
			// Preserve the upfront story_card across worker round-trips. The
			// worker rebuilds `_meta` from scratch and would otherwise drop
			// the card, forcing every later stage to either rebuild it (extra
			// AI cost) or fall back to the keyword categorizer.
			$existing_card = is_array($existing_payload['_meta']['story_card'] ?? null)
				? $existing_payload['_meta']['story_card']
				: [];
			if ($existing_card !== []) {
				$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
				$payload['_meta']['story_card'] = $existing_card;
			}
			// Stamp prompt version on each successful worker payload so the
			// drop_stale_payload_version_mismatch check at process_scheduled
			// can identify fresh-prompt payloads и не re-rewrite их каждый
			// тик. Без stamp каждый item бесконечно re-rewriting'ся —
			// burning tokens на uneven payloads (observed 2026-05-11 audit).
			$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			$payload['_meta']['editorial_prompt_version'] = self::EDITORIAL_PROMPT_VERSION;
			// External worker already returns a normalized payload for the requested
			// stage. Re-running heavy finalization here reintroduces the same long
			// blocking path we are trying to remove from the parent process.
			$response['payload'] = self::refresh_stage_checklist($payload);
			if (self::payload_has_ai_provider_failure($response['payload'])) {
				EPV2_Resilience_Manager::register_workflow_stage_failure($stage, 'AI provider unavailable: All AI providers failed');
			} else {
				EPV2_Resilience_Manager::register_workflow_stage_success($stage);
			}
			$response['duration_ms'] = (int) round((microtime(true) - $started_at) * 1000);
			return $response;
		}

	private static function hydrate_item_for_generation(object $item): object {
		if (property_exists($item, 'original_content') && property_exists($item, 'original_date')) {
			return $item;
		}
		$full_item = EPV2_Queue::get_item((int) ($item->id ?? 0));
		return is_object($full_item) ? $full_item : $item;
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
		$payload = EPV2_Review::ensure_payload($item);
		$current_categories = EPV2_Review::normalize_categories(self::working_category_seed($item, $payload));
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
		$item = self::hydrate_item_for_generation($item);
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
			$enrichment_options['max_runtime_seconds'] = max(5, min(15, (int) ($options['max_runtime_seconds'] ?? 8)));
		}
		$enrich_started_at = microtime(true);
		$dossier = EPV2_Source_Enricher::enrich_item($item, $enrichment_options);
		self::log_generate_review_payload_step('source_enriched', $item, [
			'duration_ms' => self::duration_ms_since($enrich_started_at),
			'supporting_count' => count((array) ($dossier['supporting'] ?? [])),
		]);
		if (self::dossier_requires_supporting_boost($item, $dossier)) {
			$boost_started_at = microtime(true);
			$boost_options = [
				'force_supporting' => true,
				'target_supporting' => max(2, min(3, (int) ($options['target_supporting'] ?? 3))),
				'max_runtime_seconds' => max(8, min(15, (int) ($options['max_runtime_seconds'] ?? 12))),
				'context_memory' => self::seed_context_memory_from_item($item, $dossier),
			];
			$boosted_dossier = EPV2_Source_Enricher::enrich_item($item, $boost_options);
			if (self::dossier_is_stronger($boosted_dossier, $dossier)) {
				$dossier = $boosted_dossier;
			}
			self::log_generate_review_payload_step('source_supporting_boost', $item, [
				'duration_ms' => self::duration_ms_since($boost_started_at),
				'supporting_count' => count((array) ($dossier['supporting'] ?? [])),
				'used_search' => ! empty($dossier['used_search']) ? 1 : 0,
			]);
		}
		$stored_dossier = self::compact_source_dossier($dossier, false);

		// Build the upfront story card. This is the single AI pass that
		// produces a structured semantic snapshot (category, geography,
		// entities, key facts, tags, media hints, SEO hints, publishability)
		// that every later stage consumes. We attempt it once per generated
		// review payload; on worker failure we fall back to the heuristic
		// categorizer below.
		$story_card = [];
		if (class_exists('EPV2_Story_Card_Builder')) {
			$story_card_started_at = microtime(true);
			$story_card = EPV2_Story_Card_Builder::build($item, $dossier);
			self::log_generate_review_payload_step('story_card_built', $item, [
				'duration_ms' => self::duration_ms_since($story_card_started_at),
				'success' => ! empty($story_card['success']) ? 1 : 0,
				'category' => (string) ($story_card['category']['primary'] ?? ''),
				'confidence' => (float) ($story_card['category']['confidence'] ?? 0.0),
				'estimate' => (string) ($story_card['publishable_estimate'] ?? ''),
			]);
		}

		$categorize_started_at = microtime(true);
		$tmp_payload_for_categorize = ['_meta' => ['story_card' => $story_card, 'source_dossier' => $dossier]];
		$resolved_primary = EPV2_Categorizer::resolve_for_payload(
			$tmp_payload_for_categorize,
			(string) ($dossier['primary']['title'] ?? $item->original_title ?? ''),
			(string) ($dossier['primary']['content'] ?? $item->original_content ?? ''),
			(string) ($categories[0] ?? '')
		);
		if ($resolved_primary !== '') {
			$categories = EPV2_Review::normalize_categories($resolved_primary);
		}
		// Story-card override: if AI says category with confidence ≥ 0.6,
		// trust it over the keyword heuristic. This fixes the 'cruise ship
		// hantavirus' → politik / 'Phagentherapie' → politik class of
		// mis-routings the keyword categorizer cannot correct.
		if ($story_card !== [] && class_exists('EPV2_Story_Card_Builder')) {
			$current_primary = (string) ($categories[0] ?? '');
			$card_primary = EPV2_Categorizer::refine_with_story_card($current_primary, $story_card, 0.6);
			if ($card_primary !== '' && $card_primary !== $current_primary) {
				$categories = EPV2_Review::normalize_categories($card_primary);
				self::log_generate_review_payload_step('category_overridden_by_story_card', $item, [
					'before' => $current_primary,
					'after' => $card_primary,
					'confidence' => (float) ($story_card['category']['confidence'] ?? 0.0),
				]);
			}
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
		$fallback['_meta']['source_dossier'] = $stored_dossier;
		$fallback['_meta']['source_count'] = 1 + count((array) ($dossier['supporting'] ?? []));
		// Persist the story card so downstream stages (worker rewrite, media
		// resolver, tagger, SEO) can read the same semantic snapshot we just
		// built. Stored once; downstream re-runs reuse the cached card.
		if ($story_card !== []) {
			$fallback['_meta']['story_card'] = $story_card;
		}
		if (self::context_analysis_requires_terminal_reject($context_analysis)) {
			$fallback['_meta']['gate_mode'] = 'context_reject';
			$fallback['_meta']['gate_reason'] = 'context analysis rejected item before AI rewrite';
			$fallback['_meta']['context_memory'] = self::payload_context_memory_light($fallback);
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
				// Propagate story_card from fallback (built at line ~2527) into
				// the AI-success payload. Без этого story_card теряется на
				// каждом successful AI call — observed на 2464/2465/2468 где
				// _meta.story_card отсутствовал. Story Card primacy doctrine:
				// _meta.story_card должна быть единым source of truth для
				// downstream stages (categorizer override, media resolver,
				// tagger, SEO).
				if (empty($parsed['_meta']['story_card']) && ! empty($fallback['_meta']['story_card'])) {
					$parsed['_meta']['story_card'] = $fallback['_meta']['story_card'];
				}
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
					if (empty($parsed['_meta']['story_card']) && ! empty($fallback['_meta']['story_card'])) {
						$parsed['_meta']['story_card'] = $fallback['_meta']['story_card'];
					}
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
					if (empty($parsed['_meta']['story_card']) && ! empty($fallback['_meta']['story_card'])) {
						$parsed['_meta']['story_card'] = $fallback['_meta']['story_card'];
					}
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
						if (empty($parsed['_meta']['story_card']) && ! empty($fallback['_meta']['story_card'])) {
							$parsed['_meta']['story_card'] = $fallback['_meta']['story_card'];
						}
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

	private static function dossier_requires_supporting_boost(object $item, array $dossier): bool {
		$primary = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
		$supporting_count = count((array) ($dossier['supporting'] ?? []));
		$used_search = ! empty($dossier['used_search']);
		$primary_title = trim((string) ($primary['title'] ?? $item->original_title ?? ''));
		$primary_content = trim(wp_strip_all_tags((string) ($primary['content'] ?? $item->original_content ?? '')));
		$primary_image = trim((string) ($primary['image'] ?? $item->source_image_url ?? ''));
		$text_weak = mb_strlen($primary_content) < 900;
		$title_weak = mb_strlen($primary_title) < 30;
		$image_missing = $primary_image === '';
		return $supporting_count < 1 || ! $used_search || $text_weak || $title_weak || $image_missing;
	}

	private static function dossier_is_stronger(array $candidate, array $baseline): bool {
		$candidate_supporting = count((array) ($candidate['supporting'] ?? []));
		$baseline_supporting = count((array) ($baseline['supporting'] ?? []));
		if ($candidate_supporting > $baseline_supporting) {
			return true;
		}
		$candidate_search = ! empty($candidate['used_search']) ? 1 : 0;
		$baseline_search = ! empty($baseline['used_search']) ? 1 : 0;
		if ($candidate_search > $baseline_search) {
			return true;
		}
		$candidate_primary = is_array($candidate['primary'] ?? null) ? $candidate['primary'] : [];
		$baseline_primary = is_array($baseline['primary'] ?? null) ? $baseline['primary'] : [];
		$candidate_image = trim((string) ($candidate_primary['image'] ?? ''));
		$baseline_image = trim((string) ($baseline_primary['image'] ?? ''));
		if ($candidate_image !== '' && $baseline_image === '') {
			return true;
		}
		$candidate_content = trim(wp_strip_all_tags((string) ($candidate_primary['content'] ?? '')));
		$baseline_content = trim(wp_strip_all_tags((string) ($baseline_primary['content'] ?? '')));
		return mb_strlen($candidate_content) > mb_strlen($baseline_content) + 150;
	}

	private static function seed_context_memory_from_item(object $item, array $dossier): array {
		$primary = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
		$text = trim(implode(' ', array_filter([
			(string) ($primary['title'] ?? ''),
			(string) ($primary['excerpt'] ?? ''),
			(string) ($primary['content'] ?? ''),
			(string) ($item->original_title ?? ''),
			(string) ($item->original_excerpt ?? ''),
		])));
		$text = preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)) ?: $text;
		$search_terms = array_slice(array_values(array_unique(array_filter([
			sanitize_text_field((string) ($primary['title'] ?? '')),
			sanitize_text_field((string) ($item->original_title ?? '')),
			sanitize_text_field((string) ($primary['source'] ?? '')),
		]))), 0, 6);
		return array_filter([
			'event_title' => sanitize_text_field((string) ($primary['title'] ?? $item->original_title ?? '')),
			'body_snippet' => sanitize_text_field((string) mb_substr((string) $text, 0, 260)),
			'search_terms' => $search_terms,
		], static function ($value): bool {
			if (is_array($value)) {
				return $value !== [];
			}
			return trim((string) $value) !== '';
		});
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
			$current_media = [
				'media_url' => (string) ($current['media_url'] ?? $payload['media_url'] ?? ''),
				'media_origin_url' => (string) ($current['media_origin_url'] ?? ''),
				'media_credit' => (string) ($current['media_credit'] ?? ''),
				'media_caption' => (string) ($current['media_caption'] ?? ''),
				'media_type' => (string) ($current['media_type'] ?? ''),
				'lang' => $lang,
			];
			if ($base === [] || ($current !== [] && ! self::needs_language_repair($base, $current, $lang))) {
				return $payload;
			}
			$bridge = self::translation_bridge_base($payload, $lang);
			$localeLabel = $lang === 'uk' ? 'украинский' : 'английский';
			$current_is_empty = trim((string) ($current['title'] ?? '')) === ''
				&& trim((string) ($current['excerpt'] ?? '')) === ''
				&& trim(wp_strip_all_tags((string) ($current['content'] ?? ''))) === '';
			$primary_base = ($lang === 'uk' && $current_is_empty && $bridge !== []) ? $bridge : $base;
			$fallback_base = $primary_base === $base ? $bridge : $base;
			EPV2_Logger::info('ai', 'Single language repair start', [
				'lang' => $lang,
				'content_length' => mb_strlen(trim(wp_strip_all_tags((string) ($primary_base['content'] ?? '')))),
				'bridge_first' => ($primary_base !== $base) ? 1 : 0,
			]);
			$fixed = null;
			if ($primary_base !== $base) {
				$fixed = self::translate_language_fields_separately($primary_base, $lang, $localeLabel, $config);
			}
			if (! is_array($fixed)) {
				$fixed = self::translate_language_package_bounded($primary_base, $lang, $config);
			}
			if (! is_array($fixed)) {
				if ($fallback_base !== []) {
					EPV2_Logger::warning('ai', 'Bounded language translation returned empty package, trying fallback translation base', [
						'lang' => $lang,
						'fallback_is_bridge' => ($fallback_base === $bridge) ? 1 : 0,
					]);
					$fixed = self::translate_language_fields_separately($fallback_base, $lang, $localeLabel, $config);
					if (! is_array($fixed)) {
						$fixed = self::translate_language_package_bounded($fallback_base, $lang, $config);
					}
					if (! is_array($fixed)) {
						$fixed = self::translate_language_package($fallback_base, $lang, $config);
					}
				}
			}
			if (! is_array($fixed)) {
				EPV2_Logger::warning('ai', 'Bounded language translation returned empty package, falling back to full translation pipeline', ['lang' => $lang]);
				$fixed = self::translate_language_package($primary_base, $lang, $config);
				if (! is_array($fixed)) {
					if ($fallback_base !== []) {
						$fixed = self::translate_language_package($fallback_base, $lang, $config);
					}
				}
			}
			if (! is_array($fixed)) {
				$fixed = self::translate_language_package_rescue($primary_base, $lang, $config);
				if (! is_array($fixed) && $fallback_base !== []) {
					$fixed = self::translate_language_package_rescue($fallback_base, $lang, $config);
				}
			}
			EPV2_Logger::info('ai', 'Single language repair translated', ['lang' => $lang, 'translated' => is_array($fixed) ? 1 : 0]);
			if (is_array($fixed)) {
				$payload['languages'][$lang] = array_merge($current_media, $fixed);
				unset(
					$payload['languages'][$lang]['seo_title'],
					$payload['languages'][$lang]['meta_description'],
					$payload['languages'][$lang]['slug'],
					$payload['languages'][$lang]['focus_keywords'],
					$payload['languages'][$lang]['tags']
				);
				$validation_base = $primary_base;
				if ($validation_base === [] || ! is_array($validation_base)) {
					$validation_base = $base;
				}
				if (
					self::needs_language_repair($validation_base, (array) ($payload['languages'][$lang] ?? []), $lang)
					&& ! self::translated_package_is_rescue_acceptable($validation_base, (array) ($payload['languages'][$lang] ?? []), $lang)
				) {
					EPV2_Logger::warning('ai', 'Translated package still fails language validation after merge', ['lang' => $lang]);
					$payload['languages'][$lang] = $current_media;
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
		if (self::publish_ready_gate_passes($payload)) {
			return 'ready_publish';
		}
		return self::payload_next_required_stage_for_routing($payload) !== '' ? 'retry_process' : 'retry_process';
	}

	private static function publish_ready_gate_passes(array $payload): bool {
		$payload = self::refresh_stage_checklist_for_routing($payload);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if (! empty($meta['blockers'])) {
			return false;
		}
		$stage_checklist = is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
		if (self::payload_selection_blocks_automatic_publish($payload)) {
			return false;
		}
		if (self::payload_context_rejects($payload)) {
			return false;
		}
		if (self::payload_has_stale_context_signal($payload)) {
			return false;
		}
		if (! self::publish_ready_gate_stage_contract_passes($payload, $stage_checklist, $meta)) {
			return false;
		}
		if (! self::publish_ready_gate_language_contract_passes($payload)) {
			return false;
		}
		if (! self::publish_ready_gate_seo_contract_passes($payload, $meta)) {
			return false;
		}
		if (! self::publish_ready_gate_media_contract_passes($payload)) {
			return false;
		}
		// Hallucination re-check at publish moment (live-trace 2026-05-11):
		// Stored ai_payload._meta.quality.score may be from BEFORE validator
		// deploy. detect_invented_numbers added later doesn't retro-apply
		// to stored scores. Item 2257 case: DE excerpt «DAX bei 24.350
		// Punkten» — invented number, stored quality=100, would publish.
		// Fresh check at gate ensures latest rules always apply.
		if (class_exists('EPV2_AI_Response_Validator') && method_exists('EPV2_AI_Response_Validator', 'detect_invented_numbers')) {
			$invented_nums = EPV2_AI_Response_Validator::detect_invented_numbers($payload);
			// 1+ invented specific number (price/index/percent) at publish
			// gate = block. -8 penalty в editorial_quality was insufficient
			// для items с stored stale score. Strict block here.
			if (count($invented_nums) >= 1) {
				return false;
			}
		}
		if (! self::publish_ready_gate_payload_integrity_passes($payload, $meta)) {
			return false;
		}
		return true;
	}

	private static function publish_ready_gate_stage_contract_passes(array $payload, array $stage_checklist, array $meta): bool {
		$terminal_ready_despite_stale_stage =
			! empty($stage_checklist['ready_publish'])
			&& ! empty($stage_checklist['translations_ready'])
			&& ! empty($stage_checklist['publish_finish_ready'])
			&& empty($meta['translations_deferred']);
		if (self::payload_pipeline_stage($payload) !== '' && ! $terminal_ready_despite_stale_stage) {
			return false;
		}
		if (! $terminal_ready_despite_stale_stage && self::payload_next_required_stage_for_routing($payload) !== '') {
			return false;
		}
		if (! empty($meta['gate_mode']) && (string) $meta['gate_mode'] !== 'ai_priority_only') {
			return false;
		}
			return
				! empty($stage_checklist['ready_publish'])
				&& ! empty($stage_checklist['translations_ready'])
				&& ! empty($stage_checklist['publish_finish_ready'])
				&& empty($meta['translations_deferred'])
				&& self::payload_languages_are_semantically_consistent($payload);
	}

	private static function publish_ready_gate_language_contract_passes(array $payload): bool {
		return self::languages_look_publishable($payload)
			&& self::payload_languages_are_semantically_consistent($payload);
	}

	private static function publish_ready_gate_seo_contract_passes(array $payload, array $meta): bool {
		$seo_quality = is_array($meta['seo_quality'] ?? null) ? $meta['seo_quality'] : [];
		if (! self::quality_meets_publish_gate($seo_quality, 'seo')) {
			return false;
		}
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$seo_title = trim((string) ($de['seo_title'] ?? $payload['seo']['seo_title'] ?? ''));
		$meta_description = trim((string) ($de['meta_description'] ?? $payload['seo']['meta_description'] ?? ''));
		$slug = trim((string) ($de['slug'] ?? $payload['seo']['slug'] ?? ''));
		$focus_keywords = array_values(array_filter(array_map('strval', (array) ($de['focus_keywords'] ?? $payload['seo']['focus_keywords'] ?? []))));
		return $seo_title !== '' && $meta_description !== '' && $slug !== '' && $focus_keywords !== [];
	}

	private static function publish_ready_gate_media_contract_passes(array $payload): bool {
		$featured_media_url = self::payload_primary_media_url($payload);
		if ($featured_media_url === '' || self::payload_media_is_blocked($payload, $featured_media_url)) {
			return false;
		}
		// Hard editorial rule (operator 2026-05-10): Wikimedia / Pexels never
		// pass the publish gate as featured media — they produce off-topic
		// stock that hurts brand trust. The resolver may still try them as a
		// last-resort fallback but the gate refuses regardless of how the
		// item arrived. Items lacking a real photo land in manual_review.
		if (self::payload_featured_media_is_generic_stock($payload)) {
			return false;
		}
		if (self::payload_featured_media_is_publishable($payload)) {
			return true;
		}
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? (array) $payload['_meta']['source_dossier'] : [];
		if (EPV2_Media::is_source_host_media($featured_media_url, $dossier)) {
			return true;
		}
		// Generated story covers are no longer accepted at the publish gate:
		// they were DE-baked and re-rendered as DE text on UK/EN archives.
		// Items lacking a real image now fail this gate and land in
		// manual_review via the existing stage-attempt → quarantine path,
		// where the operator can attach a real photo or reject.
		return false;
	}

	private static function publish_ready_gate_payload_integrity_passes(array $payload, array $meta): bool {
		// NOTE: previous attempt to re-validate ALL 4 quality streams here
		// on validator_version mismatch (reverted 2026-05-11 self-review)
		// caused 5/5 random published items to fail — editorial_quality
		// computes more warnings now than when scores were originally
		// stored. Re-validation cascade-blocked legitimate items.
		// Approach: targeted re-checks only (invented_numbers added at
		// the gate in commit 3597fa6 caught actual bugs without false
		// positives). Future validator bumps require explicit handling
		// not broad re-evaluation.
		$quality = is_array($meta['quality'] ?? null) ? $meta['quality'] : [];
		$release_quality = is_array($meta['release_quality'] ?? null) ? $meta['release_quality'] : [];
		$google_quality = is_array($meta['google_quality'] ?? null) ? $meta['google_quality'] : [];
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$content_plain = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));
		$short_factual_ready = self::payload_allows_short_factual_bulletin($payload, $content_plain)
			|| self::payload_meets_kind_short_form($payload, $content_plain);
		if (! self::quality_meets_publish_gate($quality, 'editorial')) {
			return false;
		}
		if (! self::quality_meets_publish_gate($release_quality, 'release') && ! ($short_factual_ready && ! empty($release_quality['pass']))) {
			return false;
		}
		if (! self::quality_meets_publish_gate($google_quality, 'google') && ! ($short_factual_ready && ! empty($google_quality['pass']))) {
			return false;
		}
		return self::payload_has_publish_grade_substance($payload);
	}

	public static function transition_item_to_ready_publish(int $item_id, array $payload, array $extra_fields = []): bool {
		$payload = self::normalize_existing_payload($payload, false);
		$item = EPV2_Queue::get_item_summary($item_id);
		$payload = self::payload_with_item_source_context($item instanceof stdClass ? $item : null, $payload);
		$gate = EPV2_Publish_Gate::evaluate($item instanceof stdClass ? $item : null, $payload, [
			'context' => 'ready_publish',
		]);
		if (empty($gate['allowed'])) {
			return false;
		}
		$fields = [
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
			'error_message' => '',
		];
		if ($extra_fields !== []) {
			$fields = array_replace($fields, $extra_fields);
		}
			EPV2_Queue::mark_state($item_id, 'ready_publish', $fields);
			$updated = EPV2_Queue::get_item($item_id);
			return $updated && in_array((string) ($updated->state ?? ''), ['ready_publish', 'retry_publish', 'publishing'], true);
	}

	private static function fast_transition_item_to_ready_publish(int $item_id, array $payload): bool {
		$item = EPV2_Queue::get_item_summary($item_id);
		$payload = self::payload_with_item_source_context($item instanceof stdClass ? $item : null, $payload);
		if (! self::payload_has_cached_terminal_ready_contract($payload)) {
			return false;
		}
		$payload = self::set_payload_pipeline_stage($payload, '');
		$notes = $item ? json_decode((string) ($item->admin_notes ?? ''), true) : [];
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['ready_publish_at'] = gmdate('Y-m-d H:i:s');
		$publish_interval = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS;
		$notes['_system']['publish_not_before'] = time() + $publish_interval;
		$notes['_system']['workflow_step'] = '';
		$notes['_system']['workflow_step_status'] = '';
		$notes['_system']['workflow_owner_token'] = '';
		$notes['_system']['workflow_heartbeat_at'] = '';
		unset($notes['_system']['retry_after'], $notes['_system']['workflow_not_before'], $notes['_system']['live_status'], $notes['_system']['live_status_code']);
		EPV2_Queue::update_fields($item_id, [
			'state' => 'ready_publish',
			'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			'error_message' => '',
			]);
			EPV2_Queue::clear_active_automation_item($item_id);
			EPV2_Queue::normalize_ready_publish_schedule(false);
			return true;
		}

	private static function payload_has_cached_terminal_ready_contract(array $payload): bool {
		$gate = EPV2_Publish_Gate::evaluate(null, $payload, [
			'context' => 'ready_publish',
		]);
		if (empty($gate['allowed'])) {
			return false;
		}
		if (self::payload_selection_blocks_automatic_publish($payload) || self::payload_context_rejects($payload) || self::payload_has_stale_context_signal($payload)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$checklist = is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
		$quality = is_array($meta['quality'] ?? null) ? $meta['quality'] : [];
		$seo = is_array($meta['seo_quality'] ?? null) ? $meta['seo_quality'] : [];
		$release = is_array($meta['release_quality'] ?? null) ? $meta['release_quality'] : [];
		$google = is_array($meta['google_quality'] ?? null) ? $meta['google_quality'] : [];
		$media = trim((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''));
		return
			$media !== ''
			&& ! empty($checklist['ready_publish'])
			&& ! empty($checklist['translations_ready'])
				&& ! empty($checklist['publish_finish_ready'])
				&& empty($meta['translations_deferred'])
				&& self::payload_languages_are_semantically_consistent($payload)
				&& ! empty($quality['pass'])
			&& (int) ($quality['score'] ?? 0) >= 90
			&& ! empty($seo['pass'])
			&& (int) ($seo['score'] ?? 0) >= 90
			&& ! empty($release['pass'])
			&& (int) ($release['score'] ?? 0) >= 90
			&& ! empty($google['pass'])
			&& (int) ($google['score'] ?? 0) >= 90;
	}

	public static function payload_is_publish_ready(array $payload): bool {
		return self::payload_ready_for_publish($payload);
	}

	public static function payload_is_terminal_publish_ready(array $payload): bool {
		if (self::payload_ready_for_publish($payload) && self::payload_has_publish_grade_substance($payload)) {
			return true;
		}
		$payload = self::refresh_stage_checklist($payload);
		$primary_media = (string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? '');
		if ($primary_media === '' || EPV2_Media::is_generated_story_cover_url($primary_media)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$stage_checklist = is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
			return
				! empty($stage_checklist['ready_publish'])
				&& ! empty($stage_checklist['translations_ready'])
				&& ! empty($stage_checklist['publish_finish_ready'])
				&& empty($meta['translations_deferred'])
				&& self::payload_languages_are_semantically_consistent($payload)
			&& self::payload_has_publish_grade_substance($payload);
	}

	private static function payload_blocker_strings(array $payload): array {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		return array_values(array_filter(array_map(static function ($value): string {
			return trim((string) $value);
		}, (array) ($meta['blockers'] ?? []))));
	}

	public static function payload_is_review_ready(array $payload): bool {
		return self::payload_is_review_worthy($payload);
	}

	public static function payload_has_publish_substance(array $payload): bool {
		return self::payload_has_publish_grade_substance($payload);
	}

	public static function payload_with_item_source_context(?object $item, array $payload): array {
		if (! $item || $payload === []) {
			return $payload;
		}
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		if (! empty($dossier['primary']) || ! empty($dossier['shell_primary'])) {
			return $payload;
		}
		$source_url = esc_url_raw((string) ($item->original_url ?? $item->canonical_url ?? ''));
		$media_url = esc_url_raw(self::payload_primary_media_url($payload));
		if ($source_url === '' || $media_url === '' || EPV2_Media::is_fallback_stock_url($media_url)) {
			return $payload;
		}
		$dossier['primary'] = [
			'url' => $source_url,
			'image' => $media_url,
			'title' => sanitize_text_field((string) ($item->original_title ?? '')),
			'excerpt' => sanitize_textarea_field((string) ($item->original_excerpt ?? '')),
		];
		$payload['_meta']['source_dossier'] = $dossier;
		$payload['_meta']['source_count'] = max(1, (int) ($payload['_meta']['source_count'] ?? 0));
		return $payload;
	}

	public static function payload_requires_media_manual_review(array $payload): bool {
		return self::payload_requires_media_manual_confirmation($payload);
	}

	public static function payload_media_contract_passes(array $payload): bool {
		$payload = self::normalize_existing_payload($payload, false);
		return self::publish_ready_gate_media_contract_passes($payload);
	}

	public static function repair_media_for_automation(object $item, array $payload): array {
		return self::repair_payload_media($item, $payload);
	}

	public static function normalize_existing_payload(array $payload, bool $allow_expensive = true): array {
		if ($payload === []) {
			return [];
		}
		$payload = self::compact_payload_source_dossier($payload);
		$payload = self::normalize_payload_contract_flags($payload);
		if (! $allow_expensive) {
			$payload = self::normalize_payload_without_stage_refresh($payload);
			$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			$payload['_meta']['quality'] = self::fast_stage_routing_quality($payload);
			return self::refresh_stage_checklist($payload);
		}
		return self::finalize_payload_for_queue($payload);
	}

	public static function normalize_persisted_queue_contracts(int $limit = 500): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(2000, $limit));
		// Two-step: id list first (cheap), then load each row's ai_payload
		// individually so we never hold 2000 LONGTEXT rows in PHP memory.
		// Each ai_payload can be hundreds of KB; 2000 * 200 KB ~= 400 MB.
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT id FROM {$table} WHERE ai_payload IS NOT NULL AND ai_payload <> '' ORDER BY id ASC LIMIT %d",
			$limit
		));
		$checked = 0;
		$changed = 0;
		$changed_ids = [];
		foreach ((array) $ids as $id) {
			$id = (int) $id;
			if ($id <= 0) {
				continue;
			}
			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT id, state, category_final, ai_payload FROM {$table} WHERE id = %d",
				$id
			));
			if (! $row) {
				continue;
			}
			$payload = json_decode((string) ($row->ai_payload ?? ''), true);
			if (! is_array($payload) || $payload === []) {
				continue;
			}
			$checked++;
			$normalized = self::normalize_existing_payload($payload, false);
			$before = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
			$after = wp_json_encode($normalized, JSON_UNESCAPED_UNICODE);
			$normalized_categories = implode(',', array_values(array_filter((array) ($normalized['categories'] ?? []))));
			$category_changed = $normalized_categories !== '' && $normalized_categories !== (string) ($row->category_final ?? '');
			if ($before === $after && ! $category_changed) {
				unset($row, $payload, $normalized, $before, $after);
				continue;
			}
			EPV2_Review::save_payload($id, $normalized);
			if ((string) ($row->state ?? '') === 'published') {
				EPV2_Publisher::synchronize_published_bundle_from_payload($id, $normalized);
			}
			$changed++;
			$changed_ids[] = $id;
			unset($row, $payload, $normalized, $before, $after);
		}
		return [
			'checked' => $checked,
			'changed' => $changed,
			'changed_ids' => $changed_ids,
		];
	}

	public static function recover_stalled_owner(int $item_id): string {
		$item_id = (int) $item_id;
		if ($item_id <= 0) {
			return 'no_item';
		}
		$item = EPV2_Queue::get_item($item_id);
		if (! $item) {
			return 'missing_item';
		}
		$payload = json_decode((string) ($item->ai_payload ?? ''), true);
		$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
		if ($payload === []) {
			return 'empty_payload';
		}
		if (self::transition_item_to_ready_publish($item_id, $payload)) {
			return 'forced_ready_publish';
		}
		$checklist = self::payload_stage_checklist(self::refresh_stage_checklist($payload));
		if (
			! empty($checklist['de_master_ready'])
			&& self::payload_language_ready($payload, 'uk')
			&& self::payload_language_ready($payload, 'en')
			&& self::payload_languages_are_semantically_consistent($payload)
		) {
			if (
				! self::payload_has_media_candidate($payload)
				|| ! self::publish_ready_gate_media_contract_passes($payload)
			) {
				if (self::queue_media_repair_retry($item, $payload, 'stalled_owner_media')) {
					return 'queued_media_repair_for_stalled_owner';
				}
			}
			return self::force_item_continuation(
				$item,
				$payload,
				'publish_finish',
				'Owner stalled without state change: принудительно продолжаю финальную publish-grade доводку.',
				5 * MINUTE_IN_SECONDS
			);
		}
		$next_stage = self::payload_next_required_stage_for_routing($payload);
		if ($next_stage !== '') {
			self::queue_required_stage($item_id, $payload, $next_stage);
			return 'forced_' . $next_stage;
		}
		if (! empty($checklist['de_master_ready']) && self::payload_language_ready($payload, 'uk') && ! self::payload_language_ready($payload, 'en')) {
			return self::force_item_continuation(
				$item,
				$payload,
				'translate_en',
				'Owner stalled without state change: принудительно продолжаю английскую ветку.'
			);
		}
		if (! empty($checklist['de_master_ready']) && ! self::payload_language_ready($payload, 'uk')) {
			return self::force_item_continuation(
				$item,
				$payload,
				'translate_uk',
				'Owner stalled without state change: принудительно продолжаю украинскую ветку.'
			);
		}
		return self::force_item_continuation(
			$item,
			$payload,
			'rebuild_bundle',
			'Owner stalled without state change: возвращаю материал в обязательную пересборку пакета.'
		);
	}

	public static function repair_false_stale_rejects(int $limit = 100): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(500, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id FROM {$table} WHERE state = 'rejected' AND ai_payload IS NOT NULL AND ai_payload <> '' ORDER BY updated_at DESC LIMIT %d",
			$limit
		));
		$repaired = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
			if ($payload === []) {
				continue;
			}
			if (self::payload_context_rejects($payload) || self::payload_has_stale_context_signal($payload)) {
				continue;
			}
			if (! self::payload_ready_for_publish($payload)) {
				continue;
			}
			self::transition_item_to_ready_publish((int) $item->id, $payload);
			$repaired[] = (int) $item->id;
		}
		return [
			'count' => count($repaired),
			'ids' => $repaired,
		];
	}

	public static function repair_stalled_translation_loops(int $limit = 50): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(200, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id FROM {$table}
			WHERE state = 'new'
				AND JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.workflow_step')) IN ('translate_uk','translate_en')
				AND (
					CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.workflow_step_attempts')) AS UNSIGNED) >= 6
					OR CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.translation_no_progress_attempts')) AS UNSIGNED) >= 3
					OR CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.retries.translate_uk')) AS UNSIGNED) >= 3
					OR CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.retries.translate_en')) AS UNSIGNED) >= 3
				)
			ORDER BY updated_at ASC
			LIMIT %d",
			$limit
		));
		$repaired = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$system = EPV2_Queue::workflow_system_payload($item);
			$status = sanitize_key((string) ($system['workflow_step_status'] ?? ''));
			$heartbeat = strtotime((string) ($system['workflow_heartbeat_at'] ?? '')) ?: 0;
			$process_active = class_exists('EPV2_Lock_Manager') && EPV2_Lock_Manager::is_active('process');
			if ($process_active && in_array($status, ['claimed', 'running'], true) && $heartbeat > 0 && ($heartbeat + 10 * MINUTE_IN_SECONDS) > time()) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
			if ($payload === []) {
				continue;
			}
			$step = sanitize_key((string) ($system['workflow_step'] ?? ''));
			$lang = $step === 'translate_en' ? 'en' : 'uk';
			$payload = self::refresh_stage_checklist_for_routing($payload);
			$next_stage = '';
			if (self::payload_language_ready_for_routing($payload, $lang)) {
				$next_stage = $lang === 'uk' && ! self::payload_language_ready_for_routing($payload, 'en')
					? 'translate_en'
					: self::payload_next_required_stage_for_routing($payload);
			}
			if ($next_stage === '' || $next_stage === $step) {
				$next_stage = self::de_master_ready_for_translation($payload) ? 'rebuild_bundle' : '';
			}
			if ($next_stage === '') {
				continue;
			}
			try {
				self::queue_required_stage((int) $item->id, $payload, $next_stage);
			} catch (Throwable $e) {
				if ($next_stage !== 'rebuild_bundle') {
					self::queue_required_stage((int) $item->id, $payload, 'rebuild_bundle');
					$next_stage = 'rebuild_bundle';
				} else {
					continue;
				}
			}
			EPV2_Queue::workflow_system_update((int) $item->id, [
				'retry_after' => '',
				'workflow_not_before' => '',
				'workflow_step_status' => 'pending',
				'translation_loop_repaired_at' => gmdate('Y-m-d H:i:s'),
			]);
			EPV2_Queue::update_fields((int) $item->id, [
				'error_message' => '',
			]);
			$repaired[] = [
				'id' => (int) $item->id,
				'from' => $step,
				'to' => $next_stage,
			];
		}
		return [
			'count' => count($repaired),
			'items' => $repaired,
		];
	}

	public static function repair_stalled_publish_finish_loops(int $limit = 50): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(200, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id FROM {$table}
			WHERE state = 'new'
				AND JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.workflow_step')) IN ('publish_finish','publish_ready_gate')
				AND (
					CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.workflow_step_attempts')) AS UNSIGNED) >= 4
					OR CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.retries.review_finish')) AS UNSIGNED) >= 1
					OR NULLIF(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.retry_after')), '') IS NOT NULL
				)
			ORDER BY updated_at ASC
			LIMIT %d",
			$limit
		));
		$repaired = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$system = EPV2_Queue::workflow_system_payload($item);
			$status = sanitize_key((string) ($system['workflow_step_status'] ?? ''));
			$heartbeat = strtotime((string) ($system['workflow_heartbeat_at'] ?? '')) ?: 0;
			$process_active = class_exists('EPV2_Lock_Manager') && EPV2_Lock_Manager::is_active('process');
			if ($process_active && in_array($status, ['claimed', 'running'], true) && $heartbeat > 0 && ($heartbeat + 10 * MINUTE_IN_SECONDS) > time()) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
			if ($payload === [] || self::payload_context_rejects($payload) || self::payload_has_stale_context_signal($payload)) {
				continue;
			}
			$from = sanitize_key((string) ($system['workflow_step'] ?? ''));
			$payload = self::refresh_stage_checklist_for_routing($payload);
			$to = '';
			if (self::transition_item_to_ready_publish((int) $item->id, $payload)) {
				$to = 'ready_publish';
				EPV2_Queue::workflow_system_update((int) $item->id, [
					'workflow_step' => '',
					'workflow_step_status' => '',
					'workflow_owner_token' => '',
					'workflow_heartbeat_at' => '',
					'retry_after' => '',
					'workflow_not_before' => '',
					'publish_finish_loop_repaired_at' => gmdate('Y-m-d H:i:s'),
				]);
				EPV2_Queue::clear_active_automation_item((int) $item->id);
			} else {
				$next_stage = self::payload_next_required_stage_for_routing($payload);
				if ($next_stage === '' || $next_stage === $from) {
					$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
					$payload['_meta']['translation_rebuild_invalidated'] = true;
					$payload['_meta']['force_rebuild_after_translation'] = true;
					$payload['_meta']['publish_finish_gate_rebuild_reason'] = 'ready checklist but publish gate failed';
					$next_stage = 'rebuild_bundle';
				}
				try {
					self::queue_required_stage((int) $item->id, $payload, $next_stage);
				} catch (Throwable $e) {
					continue;
				}
				$to = $next_stage;
				EPV2_Queue::workflow_system_update((int) $item->id, [
					'retry_after' => '',
					'workflow_not_before' => '',
					'workflow_step_status' => 'pending',
					'publish_finish_loop_repaired_at' => gmdate('Y-m-d H:i:s'),
				]);
			}
			EPV2_Queue::update_fields((int) $item->id, [
				'error_message' => '',
			]);
			$repaired[] = [
				'id' => (int) $item->id,
				'from' => $from,
				'to' => $to,
			];
		}
		return [
			'count' => count($repaired),
			'items' => $repaired,
		];
	}

	public static function repair_stranded_rebuild_outputs(int $limit = 50): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(200, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id FROM {$table}
			WHERE state = 'new'
				AND JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.workflow_step')) = 'build_de_master'
				AND JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.workflow_step_status')) = 'claimed'
				AND CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.workflow_step_attempts')) AS UNSIGNED) >= 4
			ORDER BY updated_at ASC
			LIMIT %d",
			$limit
		));
		$repaired = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$system = EPV2_Queue::workflow_system_payload($item);
			$heartbeat = strtotime((string) ($system['workflow_heartbeat_at'] ?? '')) ?: 0;
			if ($heartbeat > 0 && ($heartbeat + 60) > time()) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
			if ($payload === [] || self::payload_context_rejects($payload) || self::payload_has_stale_context_signal($payload)) {
				continue;
			}
			$payload = self::refresh_stage_checklist_for_routing($payload);
			$next_stage = self::payload_next_required_stage_for_routing($payload);
			if ($next_stage === '') {
				if (self::transition_item_to_ready_publish((int) $item->id, $payload)) {
					$next_stage = 'ready_publish';
				} else {
					continue;
				}
			} else {
				try {
					self::queue_required_stage((int) $item->id, $payload, $next_stage);
				} catch (Throwable $e) {
					continue;
				}
			}
			EPV2_Queue::workflow_system_update((int) $item->id, [
				'retry_after' => '',
				'workflow_not_before' => '',
				'workflow_step_status' => $next_stage === 'ready_publish' ? '' : 'pending',
				'workflow_owner_token' => '',
				'workflow_heartbeat_at' => '',
				'stranded_rebuild_repaired_at' => gmdate('Y-m-d H:i:s'),
			]);
			EPV2_Queue::update_fields((int) $item->id, [
				'error_message' => '',
			]);
			$repaired[] = [
				'id' => (int) $item->id,
				'to' => $next_stage,
			];
		}
		return [
			'count' => count($repaired),
			'items' => $repaired,
		];
	}

	public static function queue_contract_regression_check(int $limit = 500): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(2000, $limit));
		// Two-step: ids first, then per-row LONGTEXT fetch — bounded memory.
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT id FROM {$table} WHERE ai_payload IS NOT NULL AND ai_payload <> '' ORDER BY updated_at DESC LIMIT %d",
			$limit
		));
		$violations = [];
		foreach ((array) $ids as $id) {
			$id = (int) $id;
			if ($id <= 0) {
				continue;
			}
			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT id, state, mode, ai_payload, admin_notes FROM {$table} WHERE id = %d",
				$id
			));
			if (! $row) {
				continue;
			}
			$payload = json_decode((string) ($row->ai_payload ?? ''), true);
			if (! is_array($payload) || $payload === []) {
				unset($row);
				continue;
			}
			$issues = [];
			$featured = trim((string) ($payload['featured_media_url'] ?? ''));
			$media = trim((string) ($payload['media_url'] ?? ''));
			if ($featured !== '' && EPV2_Media::normalize_featured_candidate_url($featured) === '') {
				$issues[] = 'invalid_featured_media_url';
			}
			if ($media !== '' && EPV2_Media::normalize_featured_candidate_url($media) === '') {
				$issues[] = 'invalid_media_url';
			}
			$normalized = self::normalize_existing_payload($payload, false);
			$uk_ready = self::payload_language_ready($normalized, 'uk') || self::payload_language_ready_for_routing($normalized, 'uk');
			$en_ready = self::payload_language_ready($normalized, 'en') || self::payload_language_ready_for_routing($normalized, 'en');
			if ($uk_ready && $en_ready && ! empty($payload['_meta']['translations_deferred'])) {
				$issues[] = 'stale_translations_deferred';
			}
			$state = sanitize_key((string) ($row->state ?? ''));
			if (in_array($state, ['ready_publish', 'published'], true) && self::payload_pipeline_stage($payload) !== '') {
				$issues[] = 'terminal_stage_tail';
			}
			if ($state === 'published' && (! $uk_ready || ! $en_ready || ! empty($payload['_meta']['translations_deferred']))) {
				$issues[] = 'published_incomplete_translation_contract';
			}
			$notes = [];
			if (property_exists($row, 'admin_notes')) {
				$notes = json_decode((string) ($row->admin_notes ?? ''), true);
				$notes = is_array($notes) ? $notes : [];
			}
			if ($state === 'ready_review' && (string) ($notes['_system']['manual_confirmation_required'] ?? '') === 'translation') {
				$issues[] = 'translation_manual_sink';
			}
			if ($state === 'ready_review' && (string) ($row->mode ?? '') === 'auto' && (string) ($notes['_system']['manual_confirmation_required'] ?? '') === 'media') {
				$issues[] = 'media_manual_sink';
			}
			if ($state === 'ready_review' && (string) ($row->mode ?? '') === 'auto' && (string) ($notes['_system']['manual_confirmation_required'] ?? '') === '') {
				$issues[] = 'auto_ready_review_sink';
			}
			if ($state === 'retry_process') {
				$stored_stage = (string) ($payload['_meta']['pipeline_stage'] ?? '');
				$required_stage = self::payload_required_stage($normalized);
				if ($required_stage !== '' && $stored_stage !== $required_stage) {
					$issues[] = 'retry_process_stage_drift';
				}
			}
			if (self::payload_pipeline_stage($normalized) === 'publish_finish' && (! $uk_ready || ! $en_ready || ! empty($payload['_meta']['translations_deferred']))) {
				$issues[] = 'publish_finish_incomplete_translation_contract';
			}
			if ($issues === []) {
				unset($row, $payload, $normalized, $notes);
				continue;
			}
			$violations[] = [
				'id' => (int) $row->id,
				'state' => $state,
				'issues' => $issues,
			];
			unset($row, $payload, $normalized, $notes);
		}
		return [
			'checked' => count((array) $ids),
			'violations' => $violations,
		];
	}

	private static function normalize_payload_without_stage_refresh(array $payload): array {
		$payload = self::normalize_payload_quotes($payload);
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload = self::recategorize_payload_from_de_master($payload);
		$payload = self::refresh_selection_from_payload($payload);
		return self::align_selection_with_payload_category($payload);
	}

	private static function normalize_payload_contract_flags(array $payload): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		foreach (['featured_media_url', 'media_url'] as $field) {
			$payload[$field] = EPV2_Media::normalize_featured_candidate_url((string) ($payload[$field] ?? ''));
		}
		if (is_array($payload['languages'] ?? null)) {
			foreach ($payload['languages'] as $lang => $lang_payload) {
				if (! is_array($lang_payload)) {
					continue;
				}
				$payload['languages'][$lang]['media_url'] = EPV2_Media::normalize_featured_candidate_url((string) ($lang_payload['media_url'] ?? ''));
			}
		}
		$uk_ready = self::payload_language_ready($payload, 'uk') || self::payload_language_ready_for_routing($payload, 'uk');
		$en_ready = self::payload_language_ready($payload, 'en') || self::payload_language_ready_for_routing($payload, 'en');
		$has_translation_contract =
			array_key_exists('translations_deferred', $payload['_meta'])
			|| is_array($payload['languages']['uk'] ?? null)
			|| is_array($payload['languages']['en'] ?? null)
			|| self::de_master_ready_for_translation($payload);
		if ($has_translation_contract) {
			$payload['_meta']['translations_deferred'] = ! ($uk_ready && $en_ready);
		}
		return $payload;
	}

	public static function resolve_persisted_translation_manual_reviews(int $limit = 100): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(500, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id FROM {$table} WHERE state = 'ready_review' AND admin_notes LIKE %s ORDER BY updated_at DESC LIMIT %d",
			'%manual_confirmation_required":"translation"%',
			$limit
		));
		$resolved = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
					$payload = is_array($payload) ? $payload : [];
			if ($payload === []) {
				continue;
			}
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$lang = (string) ($notes['_system']['translation_manual_lang'] ?? '');
			$lang = in_array($lang, ['uk', 'en'], true) ? $lang : (empty($payload['_meta']['stage_checklist']['uk_ready']) ? 'uk' : 'en');
			$attempts = max(2, self::translation_attempts_from_notes($notes, $lang));
			$result = self::resolve_translation_no_progress_terminally($item, $payload, $lang, $attempts, [], []);
			$resolved[] = [
				'id' => (int) $item->id,
				'lang' => $lang,
				'result' => $result,
			];
		}
		return [
			'resolved' => $resolved,
			'count' => count($resolved),
		];
	}

	public static function repair_persisted_translation_terminal_rejects(int $limit = 100): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(500, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id FROM {$table} WHERE state = 'rejected' AND ai_payload IS NOT NULL AND ai_payload <> '' ORDER BY updated_at DESC LIMIT %d",
			$limit
		));
		$resolved = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
			if ($payload === []) {
				continue;
			}
			$selection_decision = sanitize_key((string) ($payload['_meta']['selection']['decision'] ?? ''));
			if (in_array($selection_decision, ['low', 'reject'], true)) {
				continue;
			}
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$lang = (string) ($notes['_system']['translation_manual_lang'] ?? '');
			if (! in_array($lang, ['uk', 'en'], true)) {
				$lang = empty($payload['languages']['uk']['content']) ? 'uk' : 'en';
			}
			if (! in_array($lang, ['uk', 'en'], true)) {
				continue;
			}
			$attempts = self::translation_attempts_from_notes($notes, $lang);
			if ($attempts >= 50) {
				continue;
			}
			$notes['_system']['manual_confirmation_required'] = '';
			$notes['_system']['manual_confirmation_reason'] = '';
			$notes['_system']['translation_manual_lang'] = '';
			$notes['_system']['translation_no_progress_attempts'] = 0;
			$notes['_system']['workflow_terminal_reason'] = '';
			self::clear_workflow_retry_window($notes);
			$notes['_system']['workflow_step'] = 'translate_' . $lang;
			$notes['_system']['workflow_step_status'] = 'pending';
			$notes['_system']['workflow_step_attempts'] = max(1, $attempts);
			$payload = self::force_payload_pipeline_stage($payload, 'translate_' . $lang);
			EPV2_Queue::mark_state((int) $item->id, 'new', [
				'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($item->category_final ?? ''),
				'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
				'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
				'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				'error_message' => '',
			]);
			$resolved[] = [
				'id' => (int) $item->id,
				'lang' => $lang,
				'attempts' => $attempts,
				'result' => 'requeued_translate_' . $lang,
			];
		}
		return [
			'resolved' => $resolved,
			'count' => count($resolved),
		];
	}

	public static function resolve_persisted_media_manual_reviews(int $limit = 100): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(500, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id FROM {$table} WHERE state = 'ready_review' AND mode = 'auto' AND admin_notes LIKE %s ORDER BY updated_at DESC LIMIT %d",
			'%manual_confirmation_required":"media"%',
			$limit
		));
		$resolved = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
			if ($payload === []) {
				continue;
			}
			self::reject_media_manual_sink($item, $payload);
			$resolved[] = [
				'id' => (int) $item->id,
				'result' => 'rejected_media_manual_sink',
			];
		}
		return [
			'resolved' => $resolved,
			'count' => count($resolved),
		];
	}

	public static function resolve_persisted_auto_ready_review_sinks(int $limit = 100): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(500, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id FROM {$table} WHERE state = 'ready_review' AND mode = 'auto' ORDER BY updated_at DESC LIMIT %d",
			$limit
		));
		$resolved = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			if ((string) ($notes['_system']['manual_confirmation_required'] ?? '') !== '') {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
			if ($payload === []) {
				EPV2_Queue::mark_state((int) $item->id, 'rejected', [
					'error_message' => 'Материал снят автоматически: auto ready_review sink без payload не подлежит безопасному восстановлению.',
				]);
				$resolved[] = [
					'id' => (int) $item->id,
					'result' => 'rejected_auto_ready_review_sink_empty_payload',
				];
				continue;
			}
			$target_state = self::publish_ready_gate_passes($payload) ? 'ready_publish' : 'retry_process';
			if ($target_state === 'ready_publish') {
				self::transition_item_to_ready_publish((int) $item->id, $payload);
			} else {
				EPV2_Queue::mark_state((int) $item->id, $target_state, [
					'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
					'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($item->category_final ?? $item->category_proposed ?? ''),
					'error_message' => 'Материал автоматически восстановлен из legacy auto ready_review sink и возвращён в automation queue.',
				]);
			}
			$resolved[] = [
				'id' => (int) $item->id,
				'result' => $target_state === 'ready_publish'
					? 'promoted_from_auto_ready_review_sink'
					: 'requeued_from_auto_ready_review_sink',
			];
		}
		return [
			'resolved' => $resolved,
			'count' => count($resolved),
		];
	}

	public static function repair_persisted_retry_process_stage_contract(int $limit = 200): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(1000, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id FROM {$table} WHERE state = 'retry_process' AND ai_payload IS NOT NULL AND ai_payload <> '' ORDER BY updated_at DESC LIMIT %d",
			$limit
		));
		$resolved = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
			if ($payload === []) {
				continue;
			}
			$required_stage = self::payload_required_stage($payload);
			$stored_stage = (string) ($payload['_meta']['pipeline_stage'] ?? '');
			if ($required_stage === '' || $stored_stage === $required_stage) {
				continue;
			}
			$payload = self::force_payload_pipeline_stage($payload, $required_stage);
			EPV2_Queue::mark_state((int) $item->id, 'retry_process', [
				'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($item->category_final ?? $item->category_proposed ?? ''),
				'error_message' => (string) ($item->error_message ?? ''),
			]);
			$resolved[] = [
				'id' => (int) $item->id,
				'stage' => $required_stage,
			];
		}
		return [
			'resolved' => $resolved,
			'count' => count($resolved),
		];
	}

	public static function repair_persisted_publish_finish_translation_contract(int $limit = 200): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(500, $limit));
		// pipeline_stage is a STORED generated column on ai_payload._meta.pipeline_stage
		// added by EPV2_Installer::ensure_queue_generated_columns(); avoids LIKE on LONGTEXT.
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, state FROM {$table} WHERE pipeline_stage = %s ORDER BY updated_at DESC LIMIT %d",
			'publish_finish',
			$limit
		));
		$resolved = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
			if ($payload === [] || ! self::publish_finish_requires_translation_repair($payload)) {
				continue;
			}
			$required_stage = self::payload_required_stage($payload);
			if (! in_array($required_stage, ['translate_uk', 'translate_en', 'translate_finish'], true)) {
				continue;
			}
			$payload = self::force_payload_pipeline_stage($payload, $required_stage);
			$fields = [
				'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				'error_message' => '',
			];
			if ((string) ($row->state ?? '') !== 'retry_process') {
				EPV2_Queue::mark_state((int) $item->id, 'retry_process', $fields);
			} else {
				EPV2_Queue::update_fields((int) $item->id, $fields);
			}
			$resolved[] = [
				'id' => (int) $item->id,
				'stage' => $required_stage,
			];
		}
		return [
			'resolved' => $resolved,
			'count' => count($resolved),
		];
	}

	public static function repair_persisted_publish_finish_media_blockers(int $limit = 100): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(250, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, state FROM {$table} WHERE state IN ('retry_process','rejected') AND pipeline_stage = %s ORDER BY updated_at DESC LIMIT %d",
			'publish_finish',
			$limit
		));
		$resolved = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
			if ($payload === [] || self::payload_pipeline_stage($payload) !== 'publish_finish') {
				continue;
			}
			$warning_text = mb_strtolower(implode(' | ', array_merge(
				self::warning_strings($payload['_meta']['release_quality']['warnings'] ?? []),
				self::warning_strings($payload['_meta']['google_quality']['warnings'] ?? []),
				self::warning_strings($payload['_meta']['quality']['warnings'] ?? [])
			)));
			$message = mb_strtolower(trim((string) ($item->error_message ?? '')));
			$has_media_blocker =
				$message !== '' && preg_match('/featured image|featured media|изображение не подошло/u', $message) === 1;
			if (! $has_media_blocker) {
				$has_media_blocker = preg_match('/generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|нет featured media|нет главного изображения/u', $warning_text) === 1;
			}
			if (! $has_media_blocker) {
				continue;
			}
			$current_media = (string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? '');
			if ($current_media !== '' && EPV2_Media::is_generated_story_cover_url($current_media)) {
				$payload['featured_media_url'] = '';
				$payload['media_url'] = '';
				if (is_array($payload['languages'] ?? null)) {
					foreach ($payload['languages'] as $lang => $lang_payload) {
						if (is_array($lang_payload)) {
							$payload['languages'][$lang]['media_url'] = '';
						}
					}
				}
			}
			$repaired = self::repair_media_for_automation($item, $payload);
			$target_state = self::publish_ready_gate_passes($repaired) ? 'ready_publish' : 'retry_process';
			if ($target_state === 'ready_publish') {
				self::transition_item_to_ready_publish((int) $item->id, $repaired);
			} else {
				EPV2_Queue::mark_state((int) $item->id, $target_state, [
					'ai_payload' => wp_json_encode($repaired, JSON_UNESCAPED_UNICODE),
					'category_final' => implode(',', array_values(array_filter((array) ($repaired['categories'] ?? [])))) ?: (string) ($item->category_final ?? $item->category_proposed ?? ''),
					'error_message' => 'Материал восстановлен после media-blocker и возвращён в publish_finish.',
				]);
			}
			$resolved[] = [
				'id' => (int) $item->id,
				'state' => $target_state,
				'featured' => (string) ($repaired['featured_media_url'] ?? $repaired['media_url'] ?? ''),
			];
		}
		return [
			'resolved' => $resolved,
			'count' => count($resolved),
		];
	}

	public static function repair_persisted_finalize_media_blockers(int $limit = 100): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(250, $limit));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, state FROM {$table}
			WHERE state IN ('new','rejected')
			AND (
				error_message LIKE %s
				OR error_message LIKE %s
				OR error_message LIKE %s
				OR ai_payload LIKE %s
			)
			ORDER BY updated_at DESC
			LIMIT %d",
			'%featured media%',
			'%generated cover%',
			'%unrecoverable_media%',
			'%"media_repair_failed":true%',
			$limit
		));
		$resolved = [];
		foreach ((array) $rows as $row) {
			$item = EPV2_Queue::get_item((int) ($row->id ?? 0));
			if (! $item) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? self::normalize_existing_payload($payload, false) : [];
			if ($payload === []) {
				continue;
			}
			$current_media = (string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? '');
			if ($current_media !== '' && EPV2_Media::is_generated_story_cover_url($current_media)) {
				$payload['featured_media_url'] = '';
				$payload['media_url'] = '';
				if (is_array($payload['languages'] ?? null)) {
					foreach ($payload['languages'] as $lang => $lang_payload) {
						if (is_array($lang_payload)) {
							$payload['languages'][$lang]['media_url'] = '';
						}
					}
				}
			}
			$repaired = self::repair_media_for_automation($item, $payload);
			$repaired_media = self::payload_primary_media_url($repaired);
			$real_media = $repaired_media !== '' && ! EPV2_Media::is_generated_story_cover_url($repaired_media);
			if (! $real_media) {
				continue;
			}
			$target_state = self::publish_ready_gate_passes($repaired) ? 'ready_publish' : 'new';
			if ($target_state === 'ready_publish') {
				self::transition_item_to_ready_publish((int) $item->id, $repaired);
			} else {
				$notes = json_decode((string) ($item->admin_notes ?? ''), true);
				$notes = is_array($notes) ? $notes : [];
				$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
				$notes['_system']['workflow_step'] = 'finalize_media';
				$notes['_system']['workflow_step_status'] = 'pending';
				$notes['_system']['workflow_recovery_reason'] = 'persisted_media_repair';
				$notes['_system']['workflow_last_error'] = 'Persisted item was returned to finalize_media after media repair recovery.';
				EPV2_Queue::mark_state((int) $item->id, $target_state, [
					'ai_payload' => wp_json_encode($repaired, JSON_UNESCAPED_UNICODE),
					'category_final' => implode(',', array_values(array_filter((array) ($repaired['categories'] ?? [])))) ?: (string) ($item->category_final ?? $item->category_proposed ?? ''),
					'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
					'error_message' => 'Материал восстановлен и возвращён в finalize_media.',
				]);
			}
			$resolved[] = [
				'id' => (int) $item->id,
				'state' => $target_state,
				'featured' => $repaired_media,
			];
		}
		return [
			'resolved' => $resolved,
			'count' => count($resolved),
		];
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
		$payload['_meta']['context_memory'] = self::payload_context_memory_light($payload);
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
		// Anti-cycle: если selection окончательно сказал reject (особенно
		// hard_pattern), не пытаемся rebuild — это бесполезная трата AI-cost,
		// item всё равно блокируется на publish_finish gate. Сразу выводим
		// item из rework cycle, дальше maintenance переведёт в rejected.
		$selection = is_array($payload['_meta']['selection'] ?? null) ? $payload['_meta']['selection'] : [];
		if ((string) ($selection['decision'] ?? '') === 'reject'
		    || (string) ($selection['reject_class'] ?? '') === 'hard_pattern'
		) {
			return false;
		}
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
		// Same anti-cycle guard как для rework — если selection rejected,
		// finish-cycle тоже бесполезен.
		$selection = is_array($payload['_meta']['selection'] ?? null) ? $payload['_meta']['selection'] : [];
		if ((string) ($selection['decision'] ?? '') === 'reject'
		    || (string) ($selection['reject_class'] ?? '') === 'hard_pattern'
		) {
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
		if (self::publish_finish_requires_translation_repair($payload)) {
			$payload = self::repair_payload_languages($payload);
			return self::finalize_payload_for_queue($payload, false);
		}
		$release_text = mb_strtolower(implode(' | ', self::warning_strings($payload['_meta']['release_quality']['warnings'] ?? [])));
		$media_contract_passes = self::publish_ready_gate_media_contract_passes($payload);
		$needs_media_repair = ! self::payload_has_media_candidate($payload)
			|| ! $media_contract_passes
			|| (! $media_contract_passes && preg_match('/generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|нет featured media|нет главного изображения/u', $release_text) === 1);
		if ($needs_media_repair) {
			$payload = self::repair_payload_media($item, $payload);
			if (! self::payload_has_media_candidate($payload)) {
				if (self::payload_needs_deeper_supporting_enrichment($payload)) {
					$payload = self::refresh_payload_context_for_publish_lift($item, $payload, $categories);
					$payload = self::repair_payload_media($item, $payload);
				}
				return self::finalize_payload_for_queue($payload);
			}
		}
		return self::lift_multilingual_payload_to_publish_grade($item, $payload, $categories, $style);
	}

	private static function payload_requires_media_manual_confirmation(array $payload): bool {
		$payload = self::normalize_existing_payload($payload, false);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$has_media_candidate = self::payload_has_media_candidate($payload);
		$featured_media_url = self::payload_primary_media_url($payload);
		$featured_media_publishable = $featured_media_url !== '' && self::publish_ready_gate_media_contract_passes($payload);
		$warning_text = mb_strtolower(implode(' | ', array_merge(
			self::warning_strings($meta['release_quality']['warnings'] ?? []),
			self::warning_strings($meta['google_quality']['warnings'] ?? [])
		)));
		$media_warning_signal = preg_match('/generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|нет featured media|нет главного изображения/u', $warning_text) === 1;
		if ($featured_media_publishable) {
			return false;
		}
		if (empty($meta['media_repair_failed'])) {
			return false;
		}
		if (
			$featured_media_url === ''
			&& self::payload_has_source_first_media_options($payload)
			&& ! self::payload_needs_deeper_supporting_enrichment($payload)
		) {
			return true;
		}
		if (
			$featured_media_url !== ''
			&& ! $featured_media_publishable
			&& self::payload_has_source_first_media_options($payload)
			&& ! self::payload_needs_deeper_supporting_enrichment($payload)
		) {
			return true;
		}
		if (! self::payload_source_first_media_exhausted($payload)) {
			return false;
		}
		$blocked = array_values(array_filter(array_map('strval', (array) ($meta['blocked_media_urls'] ?? []))));
		if ($featured_media_url === '' && count($blocked) >= 2) {
			return true;
		}
		if ($featured_media_url !== '' && EPV2_Media::is_fallback_stock_url($featured_media_url) && count($blocked) >= 2) {
			return true;
		}
		return true;
	}

	private static function payload_requires_terminal_media_reject(array $payload): bool {
		$payload = self::normalize_existing_payload($payload, false);
		$current_media = self::payload_primary_media_url($payload);
		if (
			$current_media !== ''
			&& ! EPV2_Media::is_generated_story_cover_url($current_media)
			&& (
				self::publish_ready_gate_media_contract_passes($payload)
				|| EPV2_Media::is_source_host_media($current_media, is_array($payload['_meta']['source_dossier'] ?? null) ? (array) $payload['_meta']['source_dossier'] : [])
			)
		) {
			return false;
		}
		if (! self::payload_source_first_media_exhausted($payload)) {
			return false;
		}
		if (self::payload_has_source_first_media_options($payload)) {
			return false;
		}
		if (self::payload_needs_deeper_supporting_enrichment($payload)) {
			return false;
		}
		return true;
	}

	private static function payload_has_source_first_media_options(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$dossier = is_array($meta['source_dossier'] ?? null) ? $meta['source_dossier'] : [];
		foreach ([
			(string) ($dossier['primary']['image'] ?? ''),
			(string) ($dossier['shell_primary']['image'] ?? ''),
		] as $url) {
			if (EPV2_Media::normalize_featured_candidate_url($url) !== '') {
				return true;
			}
		}
		foreach ((array) ($dossier['supporting'] ?? []) as $entry) {
			if (! is_array($entry)) {
				continue;
			}
			if (EPV2_Media::normalize_featured_candidate_url((string) ($entry['image'] ?? '')) !== '') {
				return true;
			}
		}
		return false;
	}

	private static function manual_confirmation_notes(object $item, string $kind, string $reason, array $extra_system = []): string {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['manual_confirmation_required'] = $kind;
		$notes['_system']['manual_confirmation_reason'] = $reason;
		foreach ($extra_system as $key => $value) {
			$notes['_system'][$key] = $value;
		}
		return wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
	}

	private static function queue_translation_manual_confirmation(object $item, array $payload, string $lang, int $attempts): void {
		$lang_label = $lang === 'uk' ? 'UK' : strtoupper($lang);
		$workflow_step = 'translate_' . $lang;
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['manual_confirmation_required'] = 'translation';
		$notes['_system']['manual_confirmation_reason'] = $lang . '_no_progress';
		$notes['_system']['translation_manual_lang'] = $lang;
		$notes['_system']['translation_no_progress_attempts'] = $attempts;
		$notes['_system']['workflow_step'] = $workflow_step;
		$notes['_system']['workflow_step_status'] = 'manual_confirmation';
		$notes['_system']['workflow_step_attempts'] = $attempts;
		$notes['_system']['workflow_terminal_reason'] = '';
		$notes['_system']['workflow_last_error'] = sprintf(
			'Automatic translation %s did not pass validation after %d attempts.',
			$lang_label,
			$attempts
		);
		unset($notes['_system']['retry_after'], $notes['_system']['workflow_not_before']);
		$notes_json = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
		EPV2_Queue::mark_state((int) $item->id, 'ready_review', [
			'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
			'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
			'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
			'admin_notes' => $notes_json,
			'error_message' => sprintf(
				'Требует ручного подтверждения translation: автоматический перевод %s стабильно не проходит валидатор после %d попыток.',
				$lang_label,
				$attempts
			),
		]);
	}

	private static function force_item_continuation(object $item, array $payload, string $stage, string $message, int $delay_seconds = 0): string {
		$payload = self::force_payload_pipeline_stage($payload, $stage);
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['manual_confirmation_required'] = '';
		$notes['_system']['manual_confirmation_reason'] = '';
		$notes['_system']['translation_manual_lang'] = '';
		$notes['_system']['workflow_terminal_reason'] = '';
		$notes['_system']['workflow_step'] = $stage;
		$notes['_system']['workflow_step_status'] = 'pending';
		self::set_workflow_retry_window($notes, $delay_seconds);
		EPV2_Queue::mark_state((int) $item->id, 'new', [
			'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($item->category_final ?? $item->category_proposed ?? ''),
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
			'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
			'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			'error_message' => $message,
		]);
		if ($delay_seconds > 0) {
			EPV2_Queue::active_owner_release((int) $item->id, '');
		}
		return 'forced_' . $stage . '_continuation';
	}

	private static function reject_media_manual_sink(object $item, array $payload): void {
		if (self::queue_media_repair_retry($item, $payload, 'manual_sink')) {
			return;
		}
		self::force_item_continuation(
			$item,
			$payload,
			'publish_finish',
			'Source-first media требует дополнительного автоматического поиска и повторной финализации.',
			30 * MINUTE_IN_SECONDS
		);
	}

	private static function queue_media_repair_retry(object $item, array $payload, string $reason = 'media_repair'): bool {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$current_media = self::payload_primary_media_url($payload);
		if ($current_media !== '' && ! EPV2_Media::is_generated_story_cover_url($current_media)) {
			return false;
		}
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['retries'] = is_array($notes['_system']['retries'] ?? null) ? $notes['_system']['retries'] : [];
		$attempts = (int) ($notes['_system']['retries']['media_repair'] ?? 0);
		$context_memory = self::payload_context_memory($payload);
		$refreshed_dossier = [];
		if ($attempts < 10 || ! empty($reason) && $reason === 'stalled_owner_media') {
			$refreshed_dossier = EPV2_Source_Enricher::enrich_item($item, [
				'force_supporting' => true,
				'target_supporting' => 4,
				'max_runtime_seconds' => $attempts >= 10 ? 16 : 10,
				'context_memory' => $context_memory,
			]);
		}
		if (is_array($refreshed_dossier) && $refreshed_dossier !== []) {
			$dossier = self::merge_supporting_dossiers($dossier, $refreshed_dossier);
			if (empty($dossier['primary']) && ! empty($refreshed_dossier['primary'])) {
				$dossier['primary'] = $refreshed_dossier['primary'];
			}
			if (empty($dossier['shell_primary']) && ! empty($refreshed_dossier['shell_primary'])) {
				$dossier['shell_primary'] = $refreshed_dossier['shell_primary'];
			}
		}
		$title = (string) (($payload['languages']['de']['title'] ?? $item->original_title) ?: '');
		$excerpt = (string) (($payload['languages']['de']['excerpt'] ?? $item->original_excerpt) ?: '');
		$categories = EPV2_Review::normalize_categories(self::working_category_seed($item, $payload));
		$blocked = self::rehabilitate_media_candidates(
			array_values(array_filter(array_map('strval', (array) ($payload['_meta']['blocked_media_urls'] ?? [])))),
			$title,
			$excerpt,
			$categories,
			$dossier
		);
		$payload['_meta']['source_dossier'] = $dossier;
		$payload['_meta']['source_count'] = max(1, 1 + count((array) ($dossier['supporting'] ?? [])));
		$payload['_meta']['context_memory'] = $context_memory;
		$payload['_meta']['blocked_media_urls'] = $blocked;
		$payload['_meta']['media_repair_failed'] = true;
		$payload = self::force_payload_pipeline_stage($payload, 'publish_finish');
		unset(
			$notes['_system']['manual_confirmation_required'],
			$notes['_system']['manual_confirmation_reason']
		);
		$notes['_system']['retries']['media_repair'] = $attempts + 1;
		$notes['_system']['workflow_step'] = 'publish_finish';
		$notes['_system']['workflow_step_status'] = 'pending';
		$delay_seconds = $attempts >= 10 ? 30 * MINUTE_IN_SECONDS : 3 * MINUTE_IN_SECONDS;
		self::set_workflow_retry_window($notes, $delay_seconds);
		if ($blocked !== []) {
			$notes['_system']['blocked_media_urls'] = $blocked;
		} else {
			unset($notes['_system']['blocked_media_urls']);
		}
		EPV2_Queue::mark_state((int) $item->id, 'new', [
			'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
			'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
			'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			'error_message' => '',
		]);
		EPV2_Queue::active_owner_release((int) $item->id, '');
		return true;
	}

	private static function resolve_translation_no_progress_terminally(object $item, array $payload, string $lang, int $attempts, array $analysis, array $gate): string {
		$lang = in_array($lang, ['uk', 'en'], true) ? $lang : 'uk';
		$lang_label = strtoupper($lang);
		$fresh_item = EPV2_Queue::get_item((int) ($item->id ?? 0));
		if ($fresh_item instanceof stdClass) {
			$item = $fresh_item;
		}
		$routing_payload = self::refresh_stage_checklist_for_routing($payload);
		if (self::payload_language_ready_for_routing($routing_payload, $lang)) {
			self::reset_translation_no_progress_attempt((int) $item->id, $lang);
			self::persist_intermediate_payload((int) $item->id, $routing_payload, $analysis, $gate);
			$next_stage = $lang === 'uk' && ! self::payload_language_ready_for_routing($routing_payload, 'en')
				? 'translate_en'
				: self::payload_next_required_stage_for_routing($routing_payload);
			if ($next_stage !== '' && $next_stage !== 'translate_' . $lang) {
				self::queue_required_stage((int) $item->id, $routing_payload, $next_stage, $analysis, $gate);
				return 'queued_' . $next_stage . '_after_translation_routing_recovery';
			}
			if (self::transition_item_to_ready_publish((int) $item->id, $routing_payload)) {
				return 'translation_routing_recovered_ready_publish';
			}
		}
		$payload = self::repair_payload_languages($payload);
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload['_meta']['translations_deferred'] = ! (
			self::payload_language_ready($payload, 'uk') && self::payload_language_ready($payload, 'en')
		);
		$payload = self::set_payload_pipeline_stage($payload, '');
		$payload = self::finalize_payload_for_queue($payload, false);
		if (self::payload_language_ready($payload, $lang)) {
			self::reset_translation_no_progress_attempt((int) $item->id, $lang);
			self::persist_intermediate_payload((int) $item->id, $payload, $analysis, $gate);
			$next_stage = self::payload_next_required_stage($payload);
			if ($next_stage !== '') {
				self::queue_required_stage((int) $item->id, $payload, $next_stage, $analysis, $gate);
				return 'queued_' . $next_stage . '_after_translation_recovery';
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
			return 'translation_recovered_to_' . $next_state;
		}
		if (
			$lang === 'uk'
			&& ! self::payload_language_ready($payload, 'en')
			&& self::de_master_ready_for_translation($payload)
		) {
			return self::force_item_continuation(
				$item,
				$payload,
				'translate_en',
				'Украинская ветка пока не собирается напрямую из DE: сначала дособираю EN bridge-версию, потом повторю UK.',
				5 * MINUTE_IN_SECONDS
			);
		}
		if ($attempts < 10 && self::de_master_ready_for_translation($payload)) {
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$notes['_system']['manual_confirmation_required'] = '';
			$notes['_system']['manual_confirmation_reason'] = '';
			$notes['_system']['translation_manual_lang'] = '';
			$notes['_system']['translation_no_progress_attempts'] = 0;
			$notes['_system']['workflow_terminal_reason'] = '';
			$delay = $attempts >= 6 ? 30 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
			self::set_workflow_retry_window($notes, $delay);
			$notes['_system']['workflow_step'] = 'translate_' . $lang;
			$notes['_system']['workflow_step_status'] = 'pending';
			$notes['_system']['workflow_step_attempts'] = max(1, $attempts);
			EPV2_Queue::mark_state((int) $item->id, 'new', [
				'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
				'ai_payload' => wp_json_encode(self::force_payload_pipeline_stage($payload, 'translate_' . $lang), JSON_UNESCAPED_UNICODE),
				'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
				'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
				'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				'error_message' => '',
			]);
			return 'requeued_translate_' . $lang . '_after_bounded_retry';
		}
		return self::force_item_continuation(
			$item,
			$payload,
			'translate_' . $lang,
			sprintf(
				'Языковая доводка %s ещё невалидна: продолжаю обязательную автоматическую доработку вместо terminal reject.',
				$lang_label
			),
			30 * MINUTE_IN_SECONDS
		);
	}

	private static function bump_translation_no_progress_attempt(int $item_id, string $lang, array $payload = []): int {
		$item = EPV2_Queue::get_item($item_id);
		if (! $item) {
			return 0;
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['retries'] = is_array($notes['_system']['retries'] ?? null) ? $notes['_system']['retries'] : [];
		$key = 'translate_' . $lang;
		$legacy_attempts = (int) ($notes['_system']['retries'][$key] ?? 0);
		$workflow_attempts = (int) ($notes['_system']['workflow_step_attempts'] ?? 0);
		$attempts = max($legacy_attempts, $workflow_attempts, 0) + 1;
		$notes['_system']['retries'][$key] = $attempts;
		$notes['_system']['translation_no_progress_attempts'] = $attempts;
		$notes['_system']['workflow_step'] = $key;
		$notes['_system']['workflow_step_status'] = 'running';
		$notes['_system']['workflow_step_attempts'] = $attempts;
		if ($payload !== []) {
			$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			$payload['_meta']['translation_failures'] = is_array($payload['_meta']['translation_failures'] ?? null) ? $payload['_meta']['translation_failures'] : [];
			$payload['_meta']['translation_failures'][$lang] = (int) ($payload['_meta']['translation_failures'][$lang] ?? 0) + 1;
		}
		EPV2_Queue::update_fields($item_id, [
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			'ai_payload' => $payload !== [] ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
		]);
		return $attempts;
	}

	private static function translation_attempts_from_notes(array $notes, string $lang): int {
		$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$retries = is_array($system['retries'] ?? null) ? $system['retries'] : [];
		$step_key = 'translate_' . $lang;
		return max(
			0,
			(int) ($system['translation_no_progress_attempts'] ?? 0),
			(int) ($system['workflow_step_attempts'] ?? 0),
			(int) ($retries[$step_key] ?? 0)
		);
	}

	private static function reset_translation_no_progress_attempt(int $item_id, string $lang): void {
		$item = EPV2_Queue::get_item($item_id);
		if (! $item) {
			return;
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['retries'] = is_array($notes['_system']['retries'] ?? null) ? $notes['_system']['retries'] : [];
		$step_key = 'translate_' . $lang;
		unset($notes['_system']['retries'][$step_key]);
		$notes['_system']['translation_no_progress_attempts'] = 0;
		if ((string) ($notes['_system']['workflow_step'] ?? '') === $step_key) {
			$notes['_system']['workflow_step_attempts'] = 0;
			$notes['_system']['workflow_last_error'] = '';
		}
		EPV2_Queue::update_fields($item_id, [
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
	}

	private static function payload_source_first_media_exhausted(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$dossier = is_array($meta['source_dossier'] ?? null) ? $meta['source_dossier'] : [];
		$current_media = self::payload_primary_media_url($payload);
		if (
			$current_media !== ''
			&& ! EPV2_Media::is_generated_story_cover_url($current_media)
			&& EPV2_Media::is_source_host_media($current_media, $dossier)
		) {
			return false;
		}
		$source_images = [];
		foreach ([
			(string) ($dossier['primary']['image'] ?? ''),
			(string) ($dossier['shell_primary']['image'] ?? ''),
		] as $url) {
			$url = EPV2_Media::normalize_featured_candidate_url($url);
			if ($url !== '') {
				$source_images[] = $url;
			}
		}
		foreach ((array) ($dossier['supporting'] ?? []) as $entry) {
			if (! is_array($entry)) {
				continue;
			}
			$url = EPV2_Media::normalize_featured_candidate_url((string) ($entry['image'] ?? ''));
			if ($url !== '') {
				$source_images[] = $url;
			}
		}
		$source_images = array_values(array_unique($source_images));
		if ($source_images !== []) {
			return false;
		}
		$blocked = array_values(array_filter(array_map('strval', (array) ($meta['blocked_media_urls'] ?? []))));
		if (count($blocked) >= 2) {
			return true;
		}
		return ! empty($dossier['used_search']) || (int) ($meta['source_count'] ?? 0) >= 1;
	}

	public static function force_payload_pipeline_stage(array $payload, string $stage): array {
		return self::set_payload_pipeline_stage($payload, $stage);
	}

	public static function payload_required_stage(array $payload): string {
		return self::payload_next_required_stage($payload);
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
		self::clear_workflow_retry_window($notes);
		EPV2_Queue::update_fields($item_id, [
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
	}

	private static function rebuild_generation_options(object $item, array $payload = []): array {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$retries = is_array($system['retries'] ?? null) ? $system['retries'] : [];
		$rebuild_attempts = (int) ($retries['review_rebuild'] ?? 0);
		$process_retries = (int) ($retries['process'] ?? 0);
		$source_count = (int) ($payload['_meta']['source_count'] ?? 0);
		$target_supporting = ($source_count <= 1 || $rebuild_attempts >= 2 || $process_retries >= 2) ? 3 : 2;
		$max_runtime_seconds = ($rebuild_attempts >= 3 || $process_retries >= 3) ? 15 : ($target_supporting >= 3 ? 12 : 8);
		$options = [
			'force_supporting' => true,
			'target_supporting' => $target_supporting,
			'max_runtime_seconds' => $max_runtime_seconds,
		];
		$context_memory = self::payload_context_memory($payload);
		if ($context_memory !== []) {
			$options['context_memory'] = $context_memory;
		}
		return $options;
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
		self::clear_workflow_retry_window($notes);
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
		$uk_ready = self::payload_language_ready($payload, 'uk') || self::payload_language_ready_for_routing($payload, 'uk');
		$en_ready = self::payload_language_ready($payload, 'en') || self::payload_language_ready_for_routing($payload, 'en');
		$translations_ready = $uk_ready && $en_ready;
		// A complete multilingual bundle should stay on the publish_finish path for
		// final media/SEO repair instead of reopening rebuild loops.
		if (
			$translations_ready
			&& empty($meta['translations_deferred'])
			&& self::de_master_ready_for_translation($payload)
			&& self::payload_needs_publish_finish_fast($payload)
		) {
			return false;
		}
		if (
			$translations_ready
			&& empty($meta['translations_deferred'])
			&& ! self::payload_translation_rebuild_explicitly_invalidated($payload)
		) {
			return false;
		}
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
				if (preg_match('/рубрика не соответствует/u', $warning) === 1) {
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

	private static function publish_finish_requires_translation_repair(array $payload): bool {
		if ($payload === []) {
			return false;
		}
		$payload = self::normalize_existing_payload($payload, false);
		if (! self::de_master_ready_for_translation($payload)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if (
			self::payload_language_ready($payload, 'uk')
			&& self::payload_language_ready($payload, 'en')
			&& empty($meta['translations_deferred'])
		) {
			return false;
		}
		$required_stage = self::payload_next_required_stage_for_routing($payload);
		return in_array($required_stage, ['translate_uk', 'translate_en', 'translate_finish'], true);
	}

	private static function payload_can_finish_without_rebuild(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$source_count = (int) ($meta['source_count'] ?? 0);
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
		$primary_media = self::payload_primary_media_url($payload);
		if (
			$source_count <= 1
			&& (
				$primary_media === ''
				|| EPV2_Media::is_fallback_stock_url($primary_media)
				|| preg_match('/слабое досье источников|рубрика не соответствует|generic stock featured media|слишком слабое.*featured media|не соответствует теме материала/u', mb_strtolower(implode(' | ', $warnings))) === 1
			)
		) {
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
		if (! empty($meta['blockers'])) {
			return false;
		}
		if (self::payload_primary_media_url($payload) === '') {
			return false;
		}
		$quality = (int) ($meta['quality']['score'] ?? 0);
		$seo = (int) ($meta['seo_quality']['score'] ?? 0);
		$release = (int) ($meta['release_quality']['score'] ?? 0);
		$google = (int) ($meta['google_quality']['score'] ?? 0);
		if ($quality >= 100 && $seo >= 100 && $release >= 100 && $google >= 100) {
			return true;
		}
		// Per-kind quality thresholds: short forms (breaking_alert,
		// news_brief, sport_result) accept lower release_quality /
		// google_quality scores by spec — those scorers tax brief items
		// for "may be too short" warnings that don't apply when the kind
		// is intentionally brief. EPV2_Content_Kinds enforces the actual
		// per-kind thresholds, here we just delegate.
		if (EPV2_Content_Kinds::payload_meets_quality($payload)) {
			return true;
		}
		return false;
	}

	private static function payload_requires_fresh_rebuild_fast(array $payload): bool {
		if ($payload === [] || self::payload_stage_requires_translation_finish($payload)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$single_source_relaxation = self::payload_allows_single_source_completion($payload);
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
		$quality = (int) ($meta['quality']['score'] ?? 0);
		$release = (int) ($meta['release_quality']['score'] ?? 0);
		$google = (int) ($meta['google_quality']['score'] ?? 0);
		$selection_category = sanitize_text_field((string) ($meta['selection']['category'] ?? ''));
		$payload_category = sanitize_text_field((string) ((array) ($payload['categories'] ?? ['']))[0]);
		if ($source_count <= 1) {
			$warnings = array_merge(
				self::warning_strings($meta['quality']['warnings'] ?? []),
				self::warning_strings($meta['release_quality']['warnings'] ?? []),
				self::warning_strings($meta['google_quality']['warnings'] ?? [])
			);
			$warning_text = mb_strtolower(implode(' | ', $warnings));
			if ($single_source_relaxation) {
				$allowed_single_source_warnings = self::strip_single_source_relaxable_warnings($warning_text);
				if (
					$allowed_single_source_warnings === ''
					&& self::de_master_ready_for_translation($payload)
					&& self::payload_is_review_ready_fast($payload)
				) {
					return false;
				}
			}
			if (
				self::de_master_ready_for_translation($payload)
				&& self::payload_primary_media_url($payload) !== ''
				&& $quality >= 100
				&& $release >= 100
				&& $google >= 100
			) {
				return false;
			}
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

	private static function publish_finish_resume_is_viable(array $payload): bool {
		if ($payload === []) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$source_count = (int) ($meta['source_count'] ?? 0);
		if ($source_count <= 0) {
			return false;
		}
		$quality = (int) ($meta['quality']['score'] ?? 0);
		$release = (int) ($meta['release_quality']['score'] ?? 0);
		$google = (int) ($meta['google_quality']['score'] ?? 0);
		if ($quality <= 0 || $release <= 0 || $google <= 0) {
			return false;
		}
		$translations_ready =
			self::payload_language_ready($payload, 'uk')
			&& self::payload_language_ready($payload, 'en')
			&& empty($meta['translations_deferred']);
		if (
			$translations_ready
			&& self::de_master_ready_for_translation($payload)
			&& self::payload_needs_publish_finish_fast($payload)
		) {
			return true;
		}
		if (! self::de_master_is_viable_fast($payload)) {
			if (
				! $translations_ready
				|| self::payload_primary_media_url($payload) === ''
				|| $source_count < 2
				|| $quality < 70
				|| $release < 90
				|| $google < 90
			) {
				return false;
			}
		}
		return true;
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
		return self::publish_ready_gate_passes($payload);
	}

	private static function payload_has_stale_context_signal(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$context = is_array($meta['context_analysis'] ?? null) ? $meta['context_analysis'] : [];
		$reject_class = sanitize_key((string) ($context['reject_class'] ?? ''));
		if ($reject_class === 'stale') {
			return true;
		}
		$primary_date = trim((string) ($meta['source_dossier']['primary']['date'] ?? ''));
		if ($primary_date === '') {
			$primary_date = trim((string) ($meta['source_dossier']['primary']['published_at'] ?? ''));
		}
		if ($primary_date === '') {
			$primary_date = trim((string) ($meta['source_dossier']['primary']['datetime'] ?? ''));
		}
		if ($primary_date === '' && is_array($payload['source'] ?? null)) {
			$primary_date = trim((string) ($payload['source']['date'] ?? ''));
		}
		if ($primary_date === '') {
			return false;
		}
		$timestamp = strtotime($primary_date);
		if (! $timestamp) {
			return false;
		}
		return (time() - $timestamp) > (48 * HOUR_IN_SECONDS);
	}

	private static function item_is_stale_time_sensitive_story(object $item, array $payload = []): bool {
		$date = trim((string) ($item->original_date ?? ''));
		if ($date === '') {
			return false;
		}
		$timestamp = strtotime($date);
		if (! $timestamp) {
			return false;
		}
		$age = time() - $timestamp;
		if ($age <= 0) {
			return false;
		}
		$text = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($item->original_title ?? ''),
			(string) ($item->original_excerpt ?? ''),
			wp_strip_all_tags((string) ($item->original_content ?? '')),
			(string) ($payload['languages']['de']['title'] ?? ''),
			(string) ($payload['languages']['de']['excerpt'] ?? ''),
		]))));
		if ($text === '') {
			return false;
		}
		$is_time_sensitive = preg_match('/\bsommerzeit\b|\bwinterzeit\b|\bzeitumstellung\b|\buhr(?:en)?\s+(vor|zur[üu]ck|umstellen)\b|перев[ео]д.*час|літн[ійого].*час|зимов[ийого].*час/u', $text) === 1;
		if (! $is_time_sensitive) {
			return false;
		}
		return $age > (6 * HOUR_IN_SECONDS);
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

	private static function payload_context_reject_should_discard(array $payload): bool {
		return false;
	}

	private static function payload_has_editorial_publish_override(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		return ! empty($meta['breaking'])
			|| ! empty($meta['top_story'])
			|| ! empty($meta['manual_mode']);
	}

	private static function payload_selection_blocks_automatic_publish(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$decision = sanitize_key((string) ($meta['selection']['decision'] ?? ''));
		if ($decision === 'low' && self::low_selection_payload_earned_publish_gate($payload)) {
			return false;
		}
		if (! in_array($decision, ['low', 'reject'], true)) {
			return false;
		}
		return ! self::payload_has_editorial_publish_override($payload);
	}

	private static function low_selection_payload_earned_publish_gate(array $payload): bool {
		$payload = self::refresh_stage_checklist_for_routing($payload);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$checklist = is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
		foreach (['quality', 'seo_quality', 'release_quality', 'google_quality'] as $key) {
			$quality = is_array($meta[$key] ?? null) ? $meta[$key] : [];
			if (empty($quality['pass']) || (int) ($quality['score'] ?? 0) < 100 || ! self::quality_has_no_warnings($quality)) {
				return false;
			}
		}
		return
			! empty($checklist['ready_publish'])
			&& ! empty($checklist['translations_ready'])
			&& ! empty($checklist['publish_finish_ready'])
			&& self::publish_ready_gate_language_contract_passes($payload)
			&& self::publish_ready_gate_media_contract_passes($payload)
			&& self::payload_has_publish_grade_substance($payload);
	}

	private static function context_reject_can_be_overridden_by_completed_payload(array $payload, array $context): bool {
		if ((string) ($context['decision'] ?? '') !== 'reject') {
			return false;
		}
		$reject_class = sanitize_key((string) ($context['reject_class'] ?? ''));
		if (! in_array($reject_class, ['low_score', 'thin_context', 'weak_supporting'], true)) {
			return false;
		}
		if (! self::payload_has_editorial_publish_override($payload)) {
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
		return false;
	}

	private static function payload_featured_media_is_publishable(array $payload): bool {
		$featured_media_url = self::payload_primary_media_url($payload);
		if ($featured_media_url === '' || self::payload_media_is_blocked($payload, $featured_media_url)) {
			return false;
		}
		if (EPV2_Media::is_generated_story_cover_url($featured_media_url)) {
			return false;
		}
		if (! self::payload_featured_media_meets_source_first_contract($payload, $featured_media_url)) {
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

	private static function payload_featured_media_meets_source_first_contract(array $payload, string $featured_media_url = ''): bool {
		$featured_media_url = $featured_media_url !== '' ? $featured_media_url : self::payload_primary_media_url($payload);
		if ($featured_media_url === '') {
			return false;
		}
		// CHECK ORDER FIX (P0.2, 2026-05-11): generic_stock check FIRST,
		// перед is_source_host_media. Если publisher serves placeholder
		// (orf.at/og-fallback-news.png) с своего CDN, is_source_host_media
		// возвращает true для orf.at — но image still generic placeholder.
		// Item 2253 (post 8779/8780/8781) случай — euronews article published
		// с orf.at og-fallback.png because dossier supporting содержал orf.at,
		// so is_source_host matched. Inverting order — generic-stock always
		// blocks regardless of source-host membership.
		if (self::payload_featured_media_is_generic_stock($payload)) {
			return false;
		}
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		if (EPV2_Media::is_source_host_media($featured_media_url, $dossier)) {
			return true;
		}
		return ! self::payload_has_source_dossier_media_candidates($payload);
	}

	private static function payload_has_source_dossier_media_candidates(array $payload): bool {
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$candidates = [];
		foreach (['primary', 'shell_primary'] as $key) {
			if (is_array($dossier[$key] ?? null)) {
				$candidates[] = (string) ($dossier[$key]['image'] ?? '');
			}
		}
		foreach ((array) ($dossier['supporting'] ?? []) as $entry) {
			if (is_array($entry)) {
				$candidates[] = (string) ($entry['image'] ?? '');
			}
		}
		foreach ($candidates as $candidate) {
			$candidate = EPV2_Media::normalize_featured_candidate_url($candidate);
			if ($candidate === '' || EPV2_Media::is_fallback_stock_url($candidate)) {
				continue;
			}
			return true;
		}
		return false;
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
		$item = EPV2_Google_News::normalize_item_source($item);
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
				$image = EPV2_Media::normalize_featured_candidate_url((string) ($doc['image'] ?? ''));
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
		$current_media_urls = array_values(array_filter(array_map(
			[EPV2_Media::class, 'normalize_featured_candidate_url'],
			[
				(string) ($payload['featured_media_url'] ?? ''),
				(string) ($payload['media_url'] ?? ''),
				(string) ($de['media_url'] ?? ''),
			]
		)));
		$current_media_urls = array_values(array_unique($current_media_urls));
		$media_warning_text = mb_strtolower(implode(' | ', array_merge(
			self::warning_strings($payload['_meta']['release_quality']['warnings'] ?? []),
			self::warning_strings($payload['_meta']['google_quality']['warnings'] ?? [])
		)));
		$blocked = array_values(array_unique(array_filter(array_map(
			[EPV2_Media::class, 'normalize_featured_candidate_url'],
			array_merge(
				(array) ($payload['_meta']['blocked_media_urls'] ?? []),
				(array) ($notes['_system']['blocked_media_urls'] ?? [])
			)
		))));
		$must_replace_current_media = $current_media_urls !== [] && self::payload_current_media_requires_reselection($payload, $title, $excerpt, $categories, $dossier, $media_warning_text);
		if ($must_replace_current_media) {
			$blocked = array_values(array_unique(array_merge($blocked, $current_media_urls)));
		}
		$blocked = self::rehabilitate_media_candidates($blocked, $title, $excerpt, $categories, $dossier);
		if ($must_replace_current_media) {
			$blocked = array_values(array_unique(array_merge($blocked, $current_media_urls)));
		}
		$payload['_meta']['blocked_media_urls'] = $blocked;
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		if ($blocked !== []) {
			$dossier = self::prune_blocked_media_from_dossier($dossier, $blocked);
			$notes['_system']['blocked_media_urls'] = $blocked;
		} else {
			unset($notes['_system']['blocked_media_urls']);
		}
		$payload['_meta']['source_dossier'] = $dossier;
		$source_first_exhausted = self::payload_source_first_media_exhausted($payload);
		$needs_deeper_enrichment = self::payload_needs_deeper_supporting_enrichment($payload);
		$attempt = 0;
		$new_media = '';
		$preferred_existing = EPV2_Media::normalize_featured_candidate_url((string) ($item->source_image_url ?: ($payload['featured_media_url'] ?? $payload['media_url'] ?? $de['media_url'] ?? '')));
		while ($attempt < 2) {
			$attempt++;
			$candidate = EPV2_Media::resolve_featured_media($title, $excerpt, $categories, $preferred_existing, $dossier, (int) $item->id);
			if ($candidate === '' || in_array($candidate, $blocked, true) || in_array($candidate, $current_media_urls, true)) {
				if ($attempt === 1) {
					if ($source_first_exhausted && ! $needs_deeper_enrichment) {
						break;
					}
					$preferred_existing = '';
					$refreshed_dossier = EPV2_Source_Enricher::enrich_item($item, [
						'force_supporting' => true,
						'target_supporting' => 3,
						'max_runtime_seconds' => 8,
						'context_memory' => self::payload_context_memory($payload),
					]);
					$shell_primary = is_array($dossier['shell_primary'] ?? null) ? $dossier['shell_primary'] : [];
					if (
						(is_array($refreshed_dossier) && count((array) ($refreshed_dossier['supporting'] ?? [])) === 0)
						&& is_array($shell_primary)
						&& trim((string) ($shell_primary['url'] ?? '')) !== ''
					) {
						$shell_item = clone $item;
						$shell_item->original_url = (string) ($shell_primary['url'] ?? $item->original_url ?? '');
						$shell_item->original_title = (string) ($shell_primary['title'] ?? $item->original_title ?? '');
						$shell_item->original_excerpt = (string) ($shell_primary['excerpt'] ?? $item->original_excerpt ?? '');
						$shell_item->original_content = (string) ($shell_primary['content'] ?? $item->original_content ?? '');
						$shell_item->source_image_url = (string) ($shell_primary['image'] ?? '');
						$shell_dossier = EPV2_Source_Enricher::enrich_item($shell_item, [
							'force_supporting' => true,
							'target_supporting' => 3,
							'max_runtime_seconds' => 8,
							'context_memory' => self::payload_context_memory($payload),
						]);
						if (is_array($shell_dossier) && $shell_dossier !== []) {
							$refreshed_dossier = self::merge_supporting_dossiers($refreshed_dossier, $shell_dossier);
							if (! is_array($refreshed_dossier['shell_primary'] ?? null)) {
								$refreshed_dossier['shell_primary'] = $shell_primary;
							}
						}
					}
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
			$preferred_existing = '';
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
			$fields = [
				'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			];
			if ($notes !== []) {
				$fields['admin_notes'] = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
			}
			EPV2_Queue::update_fields((int) $item->id, $fields);
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

	private static function merge_supporting_dossiers(array $base, array $extra): array {
		$base_supporting = array_values(array_filter((array) ($base['supporting'] ?? []), 'is_array'));
		$extra_supporting = array_values(array_filter((array) ($extra['supporting'] ?? []), 'is_array'));
		$seen = [];
		foreach ($base_supporting as $entry) {
			$key = md5((string) ($entry['url'] ?? '') . '|' . (string) ($entry['title'] ?? ''));
			$seen[$key] = true;
		}
		foreach ($extra_supporting as $entry) {
			$key = md5((string) ($entry['url'] ?? '') . '|' . (string) ($entry['title'] ?? ''));
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$base_supporting[] = $entry;
		}
		$base['supporting'] = $base_supporting;
		if (empty($base['quotes']) && ! empty($extra['quotes'])) {
			$base['quotes'] = $extra['quotes'];
		}
		if (empty($base['story_context']) && ! empty($extra['story_context'])) {
			$base['story_context'] = $extra['story_context'];
		}
		if (empty($base['event_context']) && ! empty($extra['event_context'])) {
			$base['event_context'] = $extra['event_context'];
		}
		return $base;
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

	private static function payload_current_media_requires_reselection(array $payload, string $title, string $excerpt, array $categories, array $dossier, string $warningText = ''): bool {
		$current_urls = array_values(array_unique(array_filter(array_map('strval', [
			(string) ($payload['featured_media_url'] ?? ''),
			(string) ($payload['media_url'] ?? ''),
			(string) ($payload['languages']['de']['media_url'] ?? ''),
		]))));
		if ($current_urls === []) {
			return false;
		}
		$warningText = mb_strtolower($warningText);
		$rejectStock = preg_match('/generic stock featured media|слишком слабое.*featured media/u', $warningText) === 1;
		$rejectMismatch = preg_match('/не соответствует теме материала|нет featured media|нет главного изображения/u', $warningText) === 1;
		foreach ($current_urls as $url) {
			if ($url === '') {
				continue;
			}
			if ($rejectStock && EPV2_Media::is_fallback_stock_url($url)) {
				return true;
			}
			if ($rejectMismatch && ! EPV2_Media::is_relevant_media($url, $title, $excerpt, $categories, $dossier)) {
				return true;
			}
		}
		return false;
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
			$validation = EPV2_Media::validate_featured_media($primary, 0, (string) (($payload['languages']['de']['title'] ?? '')));
			if (! empty($validation['ok'])) {
				return true;
			}
		}
		if (is_array($payload['languages'] ?? null)) {
			foreach ($payload['languages'] as $lang_payload) {
				if (! is_array($lang_payload)) {
					continue;
				}
				$url = (string) ($lang_payload['media_url'] ?? '');
				if ($url === '' || self::payload_media_is_blocked($payload, $url)) {
					continue;
				}
				$validation = EPV2_Media::validate_featured_media($url, 0, (string) ($lang_payload['title'] ?? ''));
				if (! empty($validation['ok'])) {
					return true;
				}
			}
		}
		return false;
	}

	private static function payload_primary_media_url(array $payload): string {
		foreach ([
			(string) ($payload['featured_media_url'] ?? ''),
			(string) ($payload['media_url'] ?? ''),
			(string) ($payload['languages']['de']['media_url'] ?? ''),
		] as $candidate) {
			$candidate = EPV2_Media::normalize_featured_candidate_url($candidate);
			if ($candidate !== '') {
				return $candidate;
			}
		}
		return '';
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

	private static function preserve_planner_selected_candidate_analysis(object $item, array $analysis): array {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$planner = is_array($notes['planner'] ?? null) ? $notes['planner'] : [];
		$stored_selection = is_array($notes['selection'] ?? null) ? $notes['selection'] : [];
		$planner_action = sanitize_key((string) ($planner['action'] ?? ''));
		if (! in_array($planner_action, ['select', 'replace'], true)) {
			return $analysis;
		}
		if (self::planner_selected_candidate_looks_like_noise($item)) {
			return $analysis;
		}
		$decision = sanitize_key((string) ($analysis['decision'] ?? ''));
		if (! in_array($decision, ['low', 'reject'], true)) {
			return $analysis;
		}
		$reject_class = sanitize_key((string) ($analysis['reject_class'] ?? ''));
		if (! in_array($reject_class, ['', 'low_score'], true)) {
			return $analysis;
		}
		$score = max((int) ($analysis['score'] ?? 0), (int) ($stored_selection['score'] ?? 0), 40);
		$analysis['score'] = $score;
		$analysis['tier'] = 'C';
		$analysis['decision'] = 'review';
		$analysis['reject_class'] = '';
		$analysis['planner_preserved'] = true;
		$analysis['reasons'] = array_values(array_unique(array_filter(array_merge(
			(array) ($analysis['reasons'] ?? []),
			['planner selected candidate; soft repeat-analysis downgrade ignored']
		))));
		return $analysis;
	}

	private static function planner_selected_candidate_looks_like_noise(object $item): bool {
		$text = mb_strtolower(trim((string) (($item->original_title ?? '') . ' ' . ($item->original_excerpt ?? '') . ' ' . ($item->original_url ?? ''))));
		if ($text === '') {
			return false;
		}
		return preg_match('/\b(let.?s dance|dschungelcamp|promi|celebrity|stalker|llambi|gammour|geweint|horoskop|sternzeichen|ranking|die besten|tv und stream)\b/u', $text) === 1;
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

	/**
	 * Story Card primacy: when the upfront AI verdict explicitly endorses
	 * the item as match + publishable_estimate ∈ {high, medium}, downstream
	 * heuristic thresholds (the 78-score wall on de_master viability and
	 * quality.pass) relax by a small margin. The AI verdict has primacy —
	 * heuristic quality scoring is a tie-breaker, not a veto.
	 *
	 * Returns the relaxed minimum quality score that should be enforced
	 * for de_master viability paths. Default 78 stays the wall when no
	 * trusted card endorsement exists; with endorsement we drop to 70.
	 */
	private static function payload_ai_endorsement_minimum_quality(array $payload): int {
		$default = 78;
		$card = is_array($payload['_meta']['story_card'] ?? null) ? $payload['_meta']['story_card'] : [];
		if ($card === []) {
			return $default;
		}
		$editorial = strtolower(trim((string) ($card['editorial_match'] ?? '')));
		$estimate = strtolower(trim((string) ($card['publishable_estimate'] ?? '')));
		if ($editorial !== 'match') {
			return $default;
		}
		if (! in_array($estimate, ['high', 'medium'], true)) {
			return $default;
		}
		$confidence = (float) ($card['category']['confidence'] ?? 0.0);
		if ($confidence < 0.7) {
			return $default;
		}
		return 70;
	}

	private static function payload_requires_retry_after_ai(array $payload, array $gate): bool {
		if (empty($gate['allow'])) {
			return false;
		}
		if (($gate['mode'] ?? '') !== 'ai_full') {
			return false;
		}
		$pipeline_stage = self::payload_pipeline_stage($payload);
		if (in_array($pipeline_stage, ['translate_uk', 'translate_en', 'translate_finish'], true)) {
			return false;
		}
		if (! self::languages_look_publishable($payload)) {
			return true;
		}

		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$has_runtime_accounting = (int) ($meta['tokens'] ?? 0) > 0
			|| trim((string) ($meta['provider'] ?? '')) !== ''
			|| trim((string) ($meta['model'] ?? '')) !== '';

		if (! $has_runtime_accounting) {
			// Keep this guard cheap. Full stage resolution may call budget/profile
			// analysis and must remain in the explicit routing block below.
			$checklist = self::payload_stage_checklist($payload);
			if ($checklist === []) {
				return false;
			}
			return empty($checklist['de_master_ready']) || empty($checklist['translations_ready']);
		}

			return false;
		}

		private static function payload_has_ai_provider_failure(array $payload): bool {
			$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			$messages = array_merge(
				(array) ($meta['blockers'] ?? []),
				(array) ($meta['warnings'] ?? [])
			);
			foreach ($messages as $message) {
				if (preg_match('/all ai providers failed|ai provider unavailable|provider unavailable/i', (string) $message) === 1) {
					return true;
				}
			}
			return false;
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
		// 2026-05-11: split contamination heuristics. Short German function
		// words (die/der/das/und/mit/für/wird/nicht) legitimately appear в
		// proper nouns в любом не-немецком тексте про Германию: "Haus der
		// Kunst", "Süddeutsche Zeitung", "Frankfurter Allgemeine", "Land der
		// Berge" и т.п. — поэтому single hit на short stopword недостаточно.
		// Считаем количество: ≥3 совпадений = real contamination. Domain
		// phrases (bundesregierung/fachkräfte/akteuren vor ort) — fail на
		// первом hit, они не встречаются в proper nouns. Item 2215 был
		// заблокирован на "Haus der Kunst" — operator-feedback 2026-05-11.
		$short_stopword_pattern = '/\b(die|der|das|und|mit|für|wird|nicht)\b/iu';
		$domain_phrase_pattern  = '/\b(bundesregierung|fachkr[aä]fte|akteuren vor ort)\b/iu';
		$short_hits = preg_match_all($short_stopword_pattern, $combined);
		$domain_hits = preg_match_all($domain_phrase_pattern, $combined);
		if ($lang === 'uk') {
			return preg_match('/\p{Cyrillic}/u', $combined) === 1
				&& preg_match('/[ыэёъ]/u', $combined) !== 1
				&& $short_hits < 3
				&& $domain_hits === 0;
		}
		if ($lang === 'en') {
			return preg_match('/[A-Za-z]/u', $combined) === 1
				&& preg_match('/\p{Cyrillic}/u', $combined) !== 1
				&& $short_hits < 3
				&& $domain_hits === 0
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
			return (mb_strlen($content_plain) >= $de_min && $source_count >= 1)
				|| self::payload_allows_short_factual_bulletin($payload, $content_plain);
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
		if (self::payload_allows_short_factual_bulletin($payload, $content_plain)) {
			return true;
		}
		if (self::payload_meets_kind_short_form($payload, $content_plain)) {
			return true;
		}
		if ($source_count >= 2 && mb_strlen($content_plain) >= max($de_soft, $de_min + 120)) {
			return true;
		}
		return false;
	}

	private static function payload_meets_kind_short_form(array $payload, string $content_plain): bool {
		$kind = EPV2_Content_Kinds::detect_kind($payload);
		$short_kinds = [
			EPV2_Content_Kinds::KIND_BREAKING_ALERT,
			EPV2_Content_Kinds::KIND_NEWS_BRIEF,
			EPV2_Content_Kinds::KIND_SPORT_RESULT,
		];
		if (! in_array($kind, $short_kinds, true)) {
			return false;
		}
		return EPV2_Content_Kinds::payload_meets_de_length($payload, $kind)
			&& EPV2_Content_Kinds::payload_meets_quality($payload, $kind);
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
		if (! in_array(self::payload_pipeline_stage($payload), ['translate_finish', 'translate_uk', 'translate_en'], true)) {
			return false;
		}
		$checklist = self::payload_stage_checklist(self::refresh_stage_checklist($payload));
		return empty($checklist['translations_ready']);
	}

	private static function payload_translation_rebuild_explicitly_invalidated(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		foreach (['translation_rebuild_invalidated', 'allow_translation_rebuild', 'force_rebuild_after_translation'] as $flag) {
			if (! empty($meta[$flag])) {
				return true;
			}
		}
		return false;
	}

	private static function maybe_cooldown_stagnated_rebuild(object $item, array $before, array $after, string $next_stage): bool {
		if ($next_stage !== 'rebuild_bundle') {
			self::reset_rebuild_stagnation($item);
			return false;
		}
		$after_signature = self::rebuild_stagnation_signature($after);
		if ($after_signature === '') {
			self::reset_rebuild_stagnation($item);
			return false;
		}
		$current = EPV2_Queue::get_item((int) ($item->id ?? 0));
		if (! $current) {
			return false;
		}
		$option_key = self::rebuild_stagnation_option_key((int) $item->id);
		$stagnation = get_option($option_key, []);
		$stagnation = is_array($stagnation) ? $stagnation : [];
		$stored_signature = (string) ($stagnation['signature'] ?? '');
		$count = (int) ($stagnation['count'] ?? 0);
		$count = ($stored_signature === $after_signature) ? ($count + 1) : 1;
		update_option($option_key, [
			'signature' => $after_signature,
			'count' => $count,
			'updated_at' => time(),
		], false);
		if ($count < 3) {
			return false;
		}
		EPV2_Resilience_Manager::schedule_retry($current, 'retry_process', 'process', 'publish threshold stalled rebuild bundle');
		delete_option($option_key);
		return true;
	}

	private static function reset_rebuild_stagnation(object $item): void {
		delete_option(self::rebuild_stagnation_option_key((int) ($item->id ?? 0)));
	}

	private static function resolve_de_master_quality_failure(object $item, array $payload, array $analysis, array $gate): string {
		$payload = self::refresh_stage_checklist($payload);
		if (self::de_master_is_viable($payload) || self::de_master_is_viable_fast($payload)) {
			$next_stage = self::payload_next_required_stage_for_routing($payload);
			if ($next_stage !== '') {
				self::queue_required_stage((int) $item->id, $payload, $next_stage, $analysis, $gate);
				return 'de_master_quality_false_negative_recovered_to_' . $next_stage;
			}
			$next_state = self::next_state_after_processing($payload);
			EPV2_Queue::mark_state((int) $item->id, $next_state, [
				'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
				'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				'ai_provider' => (string) ($payload['_meta']['provider'] ?? ''),
				'ai_model' => (string) ($payload['_meta']['model'] ?? ''),
				'ai_tokens' => (int) ($payload['_meta']['tokens'] ?? 0),
				'error_message' => '',
				'admin_notes' => wp_json_encode(['selection' => $analysis, 'gate' => $gate], JSON_UNESCAPED_UNICODE),
			]);
			return 'de_master_quality_false_negative_recovered_terminal';
		}

		if (self::review_rebuild_exhausted($item, $payload)) {
			return self::force_item_continuation(
				$item,
				$payload,
				'rebuild_bundle',
				'Немецкая master-версия не достигла publish-grade: продолжаю обязательную автоматическую доводку.',
				30 * MINUTE_IN_SECONDS
			);
		}

		$recent_same_item_streak = EPV2_Runs::recent_processed_item_streak('process', (int) ($item->id ?? 0), 4);
		$selection_decision = sanitize_key((string) ($payload['_meta']['selection']['decision'] ?? ''));
		if (
			$recent_same_item_streak >= 3
			|| in_array($selection_decision, ['reject', 'low'], true)
		) {
			return self::force_item_continuation(
				$item,
				self::refresh_stage_checklist(self::set_payload_pipeline_stage($payload, 'rebuild_bundle')),
				'rebuild_bundle',
				'DE master зациклился на повторном rebuild: ставлю холодный backoff и освобождаю slot для следующих материалов.',
				30 * MINUTE_IN_SECONDS
			);
		}

		$payload = self::set_payload_pipeline_stage($payload, 'rebuild_bundle');
		$payload = self::refresh_stage_checklist($payload);
		EPV2_Queue::update_fields((int) $item->id, [
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
		]);
		EPV2_Resilience_Manager::schedule_retry($item, 'retry_process', 'process', 'AI rewrite did not reach minimum DE master quality');
		return 'de_master_failed_requeued_to_rebuild';
	}

	private static function rebuild_stagnation_signature(array $payload): string {
		if ($payload === []) {
			return '';
		}
		$payload = self::finalize_payload_for_queue($payload);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$dossier = is_array($meta['source_dossier'] ?? null) ? $meta['source_dossier'] : [];
		return sha1(wp_json_encode([
			'stage' => (string) ($meta['pipeline_stage'] ?? ''),
			'source_count' => (int) ($meta['source_count'] ?? 0),
			'supporting_count' => count((array) ($dossier['supporting'] ?? [])),
			'used_search' => ! empty($dossier['used_search']) ? 1 : 0,
			'featured' => (string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''),
			'quality' => (int) ($meta['quality']['score'] ?? 0),
			'release' => (int) ($meta['release_quality']['score'] ?? 0),
			'google' => (int) ($meta['google_quality']['score'] ?? 0),
		], JSON_UNESCAPED_UNICODE));
	}

	private static function rebuild_stagnation_option_key(int $item_id): string {
		return 'epv2_rebuild_stagnation_' . max(0, $item_id);
	}

	private static function recent_rebuild_bundle_runs_stalled(int $item_id, int $threshold = 3): bool {
		global $wpdb;
		$item_id = max(0, $item_id);
		$threshold = max(2, $threshold);
		if ($item_id <= 0) {
			return false;
		}
		// Look at the most recent process runs scoped to this item across
		// the last 30-minute window. The previous filter compared run
		// started_at against the queue row's updated_at, which is bumped
		// every time we touch the row — including by the previous tick's
		// own rebuild — so the filter excluded the very loop runs we
		// were trying to count and the guard never fired (item kept
		// looping indefinitely while burning AI budget).
		//
		// Scope by `started_at >= NOW() - 30 minutes` instead: long enough
		// to span half a dozen back-to-back ticks, short enough that an
		// older legitimate retry from yesterday does not poison today.
		$cutoff = gmdate('Y-m-d H:i:s', time() - (30 * MINUTE_IN_SECONDS));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT payload, started_at
			FROM {$wpdb->prefix}epv2_runs
			WHERE job_name = 'process'
			  AND status IN ('finished', 'finished_with_errors')
			  AND started_at >= %s
			ORDER BY id DESC
			LIMIT 30",
			$cutoff
		));
		$matching = 0;
		foreach ((array) $rows as $row) {
			$payload = json_decode((string) ($row->payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
			$run_item_id = (int) ($payload['processed_item_id'] ?? $payload['last_item_id'] ?? 0);
			if ($run_item_id !== $item_id) {
				continue;
			}
			$result = sanitize_key((string) ($payload['result'] ?? ''));
			$before = sanitize_key((string) ($payload['pipeline_stage_before'] ?? ''));
			$after = sanitize_key((string) ($payload['pipeline_stage_after'] ?? ''));
			$branch = sanitize_key((string) ($payload['branch'] ?? ''));
			$is_rebuild_loop = in_array($result, ['queued_rebuild_bundle_stage', 'requeued_rebuild_bundle_after_rebuild'], true)
				|| (
					$branch === 'generate_review_payload'
					&& (
						strpos($result, 'rebuild_bundle') !== false
						|| $before === 'rebuild_bundle'
						|| $after === 'rebuild_bundle'
					)
				);
			if (! $is_rebuild_loop) {
				// A successful non-loop run interrupts the streak; don't
				// terminalize an item that recently progressed.
				return false;
			}
			$matching++;
			if ($matching >= $threshold) {
				return true;
			}
		}
		return false;
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
		if (self::payload_needs_deeper_supporting_enrichment($payload)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$min_quality = self::payload_ai_endorsement_minimum_quality($payload);
		if ((int) ($meta['quality']['score'] ?? 0) < $min_quality) {
			return false;
		}
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$content_plain = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));
		if (mb_strlen($content_plain) < 320) {
			return false;
		}
		// Translation readiness must depend on the DE master itself, not on final
		// featured media. Media/SEO cleanup belongs to publish_finish; otherwise
		// items with strong DE text but missing media oscillate between rebuild and
		// publish_finish instead of progressing through translate_uk/translate_en.
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
		$min_quality = self::payload_ai_endorsement_minimum_quality($payload);
		if (empty($quality['pass']) || (int) ($quality['score'] ?? 0) < $min_quality) {
			return false;
		}
		$profile = self::payload_story_budget_profile($payload);
		$content_plain = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));
		$de_min = max(120, (int) ($profile['de']['content_min_chars'] ?? 320));
		$source_count = (int) ($payload['_meta']['source_count'] ?? 0);
		if ($source_count <= 0) {
			return false;
		}
		return mb_strlen($content_plain) >= $de_min || self::payload_allows_short_factual_bulletin($payload, $content_plain);
	}

	private static function looks_like_official_primary(string $url): bool {
		$host = (string) wp_parse_url($url, PHP_URL_HOST);
		if ($host === '') {
			return false;
		}
		$host = strtolower(preg_replace('/^www\./i', '', $host));
		if (
			str_ends_with($host, '.museum')
			|| str_contains($host, 'museum')
			|| str_contains($host, 'museen')
		) {
			return true;
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

	private static function finalize_payload_for_queue(array $payload, bool $resolve_media = true, bool $full_quality = true): array {
		$payload = self::compact_payload_source_dossier($payload);
		$payload = self::normalize_payload_quotes($payload);
		$payload = EPV2_AI_Response_Validator::enrich_payload($payload, $resolve_media);
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload = self::recategorize_payload_from_de_master($payload);
		$payload = self::refresh_selection_from_payload($payload);
		$payload = self::align_selection_with_payload_category($payload);
		$primary_media = self::payload_primary_media_url($payload);
		if ($primary_media !== '') {
			$payload['featured_media_url'] = $primary_media;
			$payload['media_url'] = $primary_media;
			if (is_array($payload['languages'] ?? null)) {
				foreach ($payload['languages'] as $lang => $lang_payload) {
					if (is_array($lang_payload) && trim((string) ($lang_payload['media_url'] ?? '')) === '') {
						$payload['languages'][$lang]['media_url'] = $primary_media;
					}
				}
			}
		}
		$payload['_meta']['quality'] = EPV2_AI_Response_Validator::editorial_quality($payload);
		if ($full_quality) {
			$payload['_meta']['seo_quality'] = EPV2_AI_Response_Validator::seo_quality($payload);
			$payload['_meta']['release_quality'] = EPV2_AI_Response_Validator::release_quality($payload);
			$payload['_meta']['google_quality'] = EPV2_AI_Response_Validator::google_preflight_quality($payload);
		} else {
			$payload['_meta']['seo_quality'] = is_array($payload['_meta']['seo_quality'] ?? null) ? $payload['_meta']['seo_quality'] : [
				'score' => 0,
				'pass' => false,
				'warnings' => [],
			];
			$payload['_meta']['release_quality'] = is_array($payload['_meta']['release_quality'] ?? null) ? $payload['_meta']['release_quality'] : [
				'score' => 0,
				'pass' => false,
				'warnings' => [],
			];
			$payload['_meta']['google_quality'] = is_array($payload['_meta']['google_quality'] ?? null) ? $payload['_meta']['google_quality'] : [
				'score' => 0,
				'pass' => false,
				'warnings' => [],
			];
		}
		$payload['_meta']['context_memory'] = self::payload_context_memory_light($payload);
		$payload = self::refresh_stage_checklist($payload);
		$payload['quality'] = $payload['_meta']['quality'];
		$payload['seo_quality'] = $payload['_meta']['seo_quality'];
		$payload['release_quality'] = $payload['_meta']['release_quality'];
		$payload['google_quality'] = $payload['_meta']['google_quality'];
		$payload['stage'] = self::payload_pipeline_stage($payload);
		return $payload;
	}

	private static function finalize_payload_for_stage_routing(array $payload, ?int $trace_queue_id = null, ?int $run_id = null, ?float $started_at = null): array {
		$payload = self::compact_payload_source_dossier($payload);
		self::log_stage_routing_finalize_step('after_compact_dossier', $trace_queue_id, $run_id, $started_at);
		$payload = self::normalize_payload_quotes($payload);
		self::log_stage_routing_finalize_step('after_normalize_quotes', $trace_queue_id, $run_id, $started_at);
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload = self::recategorize_payload_from_de_master($payload);
		self::log_stage_routing_finalize_step('after_recategorize_de_master', $trace_queue_id, $run_id, $started_at, [
			'category' => (string) (((array) ($payload['categories'] ?? []))[0] ?? ''),
		]);
		$payload = self::refresh_selection_from_payload($payload);
		self::log_stage_routing_finalize_step('after_refresh_selection', $trace_queue_id, $run_id, $started_at, [
			'selection_decision' => (string) ($payload['_meta']['selection']['decision'] ?? ''),
		]);
		$payload = self::align_selection_with_payload_category($payload);
		self::log_stage_routing_finalize_step('after_align_selection', $trace_queue_id, $run_id, $started_at);
		$primary_media = self::payload_primary_media_url($payload);
		if ($primary_media !== '') {
			$payload['featured_media_url'] = $primary_media;
			$payload['media_url'] = $primary_media;
		}
		self::log_stage_routing_finalize_step('after_primary_media', $trace_queue_id, $run_id, $started_at, [
			'has_primary_media' => $primary_media !== '' ? 1 : 0,
		]);
		$payload['_meta']['quality'] = self::fast_stage_routing_quality($payload);
		self::log_stage_routing_finalize_step('after_fast_quality', $trace_queue_id, $run_id, $started_at, [
			'quality_score' => (int) ($payload['_meta']['quality']['score'] ?? 0),
		]);
		$payload['_meta']['seo_quality'] = is_array($payload['_meta']['seo_quality'] ?? null) ? $payload['_meta']['seo_quality'] : [
			'score' => 0,
			'pass' => false,
			'warnings' => [],
		];
		$payload['_meta']['release_quality'] = is_array($payload['_meta']['release_quality'] ?? null) ? $payload['_meta']['release_quality'] : [
			'score' => 0,
			'pass' => false,
			'warnings' => [],
		];
		$payload['_meta']['google_quality'] = is_array($payload['_meta']['google_quality'] ?? null) ? $payload['_meta']['google_quality'] : [
			'score' => 0,
			'pass' => false,
			'warnings' => [],
		];
		$payload['_meta']['context_memory'] = self::payload_context_memory_light($payload);
		self::log_stage_routing_finalize_step('after_light_context_memory', $trace_queue_id, $run_id, $started_at);
		$payload = self::refresh_stage_checklist_for_routing($payload);
		self::log_stage_routing_finalize_step('after_routing_checklist', $trace_queue_id, $run_id, $started_at, [
			'pipeline_stage' => (string) ($payload['_meta']['pipeline_stage'] ?? ''),
		]);
		$payload['quality'] = $payload['_meta']['quality'];
		$payload['seo_quality'] = $payload['_meta']['seo_quality'];
		$payload['release_quality'] = $payload['_meta']['release_quality'];
		$payload['google_quality'] = $payload['_meta']['google_quality'];
		$payload['stage'] = self::payload_pipeline_stage($payload);
		return $payload;
	}

	private static function log_stage_routing_finalize_step(string $step, ?int $queue_id, ?int $run_id, ?float $started_at, array $context = []): void {
		if ($queue_id === null || $queue_id <= 0) {
			return;
		}
		if ($run_id !== null && $run_id > 0) {
			$context['run_id'] = $run_id;
		}
		if ($started_at !== null) {
			$context['duration_ms'] = self::duration_ms_since($started_at);
		}
		self::log_process_item_step('stage_routing_' . $step, $queue_id, $context);
	}

	private static function fast_stage_routing_quality(array $payload): array {
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title = trim((string) ($de['title'] ?? ''));
		$excerpt = trim((string) ($de['excerpt'] ?? ''));
		$content_plain = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));
		$source_count = (int) ($payload['_meta']['source_count'] ?? 0);
		$score = 0;
		$warnings = [];
		if ($title !== '') {
			$score += 20;
		} else {
			$warnings[] = 'отсутствует DE title';
		}
		if ($excerpt !== '') {
			$score += 20;
		} else {
			$warnings[] = 'отсутствует DE excerpt';
		}
		if ($content_plain !== '') {
			$score += 20;
		} else {
			$warnings[] = 'отсутствует DE content';
		}
		if (mb_strlen($content_plain) >= 280) {
			$score += 20;
		} elseif ($content_plain !== '') {
			$warnings[] = 'слишком короткий DE content для stage routing';
		}
		if ($source_count > 0) {
			$score += 20;
		} else {
			$warnings[] = 'source dossier not confirmed';
		}
		return [
			'score' => $score,
			'pass' => $score >= 78,
			'warnings' => $warnings,
		];
	}

	private static function payload_context_memory_light(array $payload): array {
		$existing = is_array($payload['_meta']['context_memory'] ?? null) ? $payload['_meta']['context_memory'] : [];
		if ($existing !== []) {
			return $existing;
		}
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title = sanitize_text_field((string) ($de['title'] ?? ''));
		$excerpt = sanitize_text_field((string) ($de['excerpt'] ?? ''));
		$category = sanitize_text_field((string) ((array) ($payload['categories'] ?? ['']))[0]);
		$search_terms = array_values(array_filter(array_unique(array_map('sanitize_text_field', [
			$title,
			$excerpt,
			$category,
		]))));
		return [
			'kind' => '',
			'summary' => $excerpt !== '' ? $excerpt : $title,
			'event_title' => $title,
			'body_snippet' => $excerpt,
			'participants' => [],
			'entities' => [],
			'locations' => [],
			'dates' => [],
			'money_values' => [],
			'theses' => [],
			'datetime_text' => '',
			'venue' => '',
			'stage' => '',
			'referee' => '',
			'head_to_head' => '',
			'next_step' => '',
			'fact_snippets' => [],
			'search_terms' => array_slice($search_terms, 0, 8),
		];
	}

	private static function compact_payload_source_dossier(array $payload): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		if ($dossier === []) {
			return $payload;
		}
		$payload['_meta']['source_dossier'] = self::compact_source_dossier($dossier, false);
		return $payload;
	}

	private static function refresh_stage_checklist(array $payload): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload = self::ensure_fast_publish_quality_meta($payload);
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
		if (
			$ready_publish_fast
			&& ! empty($completed['translations_ready'])
			&& ! empty($completed['publish_finish_ready'])
		) {
			$payload['_meta']['pipeline_stage'] = '';
		}
		return $payload;
	}

	private static function refresh_stage_checklist_for_routing(array $payload): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload = self::ensure_fast_publish_quality_meta($payload);
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$context = is_array($payload['_meta']['context_analysis'] ?? null) ? $payload['_meta']['context_analysis'] : [];
		$uk_ready = self::payload_language_ready_for_routing($payload, 'uk');
		$en_ready = self::payload_language_ready_for_routing($payload, 'en');
		$translations_ready = $uk_ready && $en_ready && empty($payload['_meta']['translations_deferred']);
		$publish_finish_ready = self::de_master_ready_for_routing($payload);
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$content_plain = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));
		$short_factual_ready =
			self::payload_allows_short_factual_bulletin($payload, $content_plain)
			&& self::payload_has_publish_grade_substance($payload);
		$kind_short_form_ready =
			self::payload_meets_kind_short_form($payload, $content_plain)
			&& self::payload_has_publish_grade_substance($payload);
		$ready_publish_fast =
			$translations_ready
			&& $publish_finish_ready
			&& self::payload_primary_media_url($payload) !== ''
			&& (
				(
					(int) ($payload['_meta']['quality']['score'] ?? 0) >= 100
					&& (int) ($payload['_meta']['seo_quality']['score'] ?? 0) >= 100
					&& (int) ($payload['_meta']['release_quality']['score'] ?? 0) >= 100
					&& (int) ($payload['_meta']['google_quality']['score'] ?? 0) >= 100
				)
				|| $short_factual_ready
				|| $kind_short_form_ready
			);
		$completed = [
			'source_received' => true,
			'initial_analysis_done' => ! empty($payload['_meta']['selection']),
			'context_saved' => ! empty($payload['_meta']['context_memory']),
			'context_analysis_done' => $context !== [],
			'dossier_built' => $dossier !== [],
			'de_master_ready' => self::de_master_is_viable_fast($payload),
			'uk_ready' => $uk_ready,
			'en_ready' => $en_ready,
			'translations_ready' => $translations_ready,
			'publish_finish_ready' => $publish_finish_ready,
			'ready_publish' => $ready_publish_fast,
		];
		$payload['_meta']['stage_checklist'] = $completed;
		if ($ready_publish_fast) {
			$payload['_meta']['pipeline_stage'] = '';
		}
		return $payload;
	}

	private static function ensure_fast_publish_quality_meta(array $payload): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$meta = &$payload['_meta'];
		foreach (['quality', 'seo_quality', 'release_quality', 'google_quality'] as $key) {
			$top = is_array($payload[$key] ?? null) ? $payload[$key] : [];
			$existing = is_array($meta[$key] ?? null) ? $meta[$key] : [];
			if ((int) ($top['score'] ?? 0) > (int) ($existing['score'] ?? 0)) {
				$meta[$key] = $top;
			}
		}

		$existing_quality = is_array($meta['quality'] ?? null) ? $meta['quality'] : [];
		if ((int) ($existing_quality['score'] ?? 0) <= 0) {
			$meta['quality'] = self::fast_stage_routing_quality($payload);
		}

		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$seo_title = trim((string) ($de['seo_title'] ?? $payload['seo']['seo_title'] ?? ''));
		$meta_description = trim((string) ($de['meta_description'] ?? $payload['seo']['meta_description'] ?? ''));
		$slug = trim((string) ($de['slug'] ?? $payload['seo']['slug'] ?? ''));
		$focus_keywords = (array) ($de['focus_keywords'] ?? $payload['seo']['focus_keywords'] ?? []);
		$seo_score = 0;
		$seo_warnings = [];
		if ($seo_title !== '') {
			$seo_score += 30;
		} else {
			$seo_warnings[] = 'missing seo title';
		}
		if ($meta_description !== '') {
			$seo_score += 30;
		} else {
			$seo_warnings[] = 'missing meta description';
		}
		if ($slug !== '') {
			$seo_score += 20;
		} else {
			$seo_warnings[] = 'missing slug';
		}
		if (array_values(array_filter(array_map('strval', $focus_keywords))) !== []) {
			$seo_score += 20;
		} else {
			$seo_warnings[] = 'missing focus keywords';
		}
		$existing_seo = is_array($meta['seo_quality'] ?? null) ? $meta['seo_quality'] : [];
		if ((int) ($existing_seo['score'] ?? 0) <= 0) {
			$meta['seo_quality'] = [
				'score' => $seo_score,
				'pass' => $seo_score >= 100 && $seo_warnings === [],
				'warnings' => $seo_warnings,
			];
		}

		$translations_ready =
			(self::payload_language_ready($payload, 'uk') || self::payload_language_ready_for_routing($payload, 'uk'))
			&& (self::payload_language_ready($payload, 'en') || self::payload_language_ready_for_routing($payload, 'en'))
			&& empty($meta['translations_deferred']);
		$release_google_ready =
			self::de_master_ready_for_translation($payload)
			&& self::payload_primary_media_url($payload) !== ''
			&& $translations_ready;
		foreach (['release_quality', 'google_quality'] as $key) {
			$existing = is_array($meta[$key] ?? null) ? $meta[$key] : [];
			if ((int) ($existing['score'] ?? 0) > 0) {
				continue;
			}
			$warnings = $release_google_ready ? [] : ['bundle not yet publish-complete'];
			$score = $release_google_ready ? 100 : 0;
			$meta[$key] = [
				'score' => $score,
				'pass' => $score >= 100 && $warnings === [],
				'warnings' => $warnings,
			];
		}

		return $payload;
	}

	private static function payload_next_required_stage_for_routing(array $payload): string {
		if ($payload === [] || self::payload_context_rejects($payload)) {
			return '';
		}
		if (self::payload_blocker_strings($payload) !== []) {
			return '';
		}
		$checklist = self::payload_stage_checklist(self::refresh_stage_checklist_for_routing($payload));
		$requires_rebuild_fast = self::payload_requires_fresh_rebuild_fast($payload);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$translations_ready =
			! empty($checklist['uk_ready'])
			&& ! empty($checklist['en_ready'])
			&& empty($meta['translations_deferred']);
		$allow_translation_rebuild = $translations_ready && self::payload_translation_rebuild_explicitly_invalidated($payload);
		$can_finish_without_rebuild = $translations_ready && self::publish_finish_resume_is_viable($payload);
		if (empty($checklist['de_master_ready'])) {
			if ($can_finish_without_rebuild) {
				return 'publish_finish';
			}
			return 'rebuild_bundle';
		}
		if (empty($checklist['uk_ready'])) {
			return 'translate_uk';
		}
		if (empty($checklist['en_ready'])) {
			return 'translate_en';
		}
		if (empty($checklist['publish_finish_ready'])) {
			if ($can_finish_without_rebuild || ($translations_ready && ! $allow_translation_rebuild)) {
				return 'publish_finish';
			}
			return 'rebuild_bundle';
		}
		if (! self::payload_is_review_ready_fast($payload)) {
			return ($requires_rebuild_fast && ! ($translations_ready && ! $allow_translation_rebuild)) ? 'rebuild_bundle' : 'publish_finish';
		}
		if (empty($checklist['ready_publish'])) {
			return ($requires_rebuild_fast && ! ($translations_ready && ! $allow_translation_rebuild)) ? 'rebuild_bundle' : 'publish_finish';
		}
		if (! self::payload_has_publish_grade_substance($payload)) {
			return ($translations_ready && ! $allow_translation_rebuild) ? 'publish_finish' : 'rebuild_bundle';
		}
		if (! self::payload_is_terminal_publish_ready($payload)) {
			return 'publish_finish';
		}
		return '';
	}

		private static function payload_stage_checklist(array $payload): array {
			$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			return is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
		}

			private static function payload_next_stage_from_cached_checklist(array $payload): string {
				$pipeline_stage = self::payload_pipeline_stage($payload);
				if (self::payload_blocker_strings($payload) !== []) {
					return '';
				}
			$checklist = self::payload_stage_checklist($payload);
			$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			$translations_ready =
				! empty($checklist['uk_ready'])
				&& ! empty($checklist['en_ready'])
				&& empty($meta['translations_deferred']);
			if (
				$pipeline_stage === 'rebuild_bundle'
				&& $translations_ready
				&& ! self::payload_translation_rebuild_explicitly_invalidated($payload)
			) {
				return 'publish_finish';
			}
			if (in_array($pipeline_stage, ['translate_uk', 'translate_en', 'translate_finish', 'publish_finish', 'rebuild_bundle'], true)) {
				return $pipeline_stage;
			}
			if ($checklist === []) {
				return '';
			}
			if (empty($checklist['de_master_ready'])) {
				return 'rebuild_bundle';
			}
			if (empty($checklist['uk_ready'])) {
				return 'translate_uk';
			}
			if (empty($checklist['en_ready'])) {
				return 'translate_en';
			}
			if (empty($checklist['publish_finish_ready']) || empty($checklist['ready_publish'])) {
				return 'publish_finish';
				}
				return '';
			}

			private static function worker_rebuild_payload_should_continue_to_publish_finish(array $payload): bool {
				if ($payload === [] || self::payload_context_rejects($payload) || self::payload_blocker_strings($payload) !== []) {
					return false;
				}
				$payload = self::refresh_stage_checklist_for_routing($payload);
				$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
				$checklist = self::payload_stage_checklist($payload);
				$translations_ready = (
					! empty($checklist['translations_ready'])
					|| (
						self::payload_language_ready_for_routing($payload, 'uk')
						&& self::payload_language_ready_for_routing($payload, 'en')
						&& empty($meta['translations_deferred'])
					)
				);
				if (! $translations_ready) {
					return false;
				}
				return ! empty($checklist['publish_finish_ready'])
					|| self::de_master_is_viable_fast($payload)
					|| self::de_master_ready_for_translation($payload);
			}

			private static function payload_next_required_stage(array $payload): string {
		if ($payload === [] || self::payload_context_rejects($payload)) {
			return '';
		}
		$checklist = self::payload_stage_checklist(self::refresh_stage_checklist($payload));
		$requires_rebuild_fast = self::payload_requires_fresh_rebuild_fast($payload);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$translations_ready =
			! empty($checklist['uk_ready'])
			&& ! empty($checklist['en_ready'])
			&& empty($meta['translations_deferred']);
		$allow_translation_rebuild = $translations_ready && self::payload_translation_rebuild_explicitly_invalidated($payload);
		if ($translations_ready && self::payload_needs_deeper_supporting_enrichment($payload)) {
			return $allow_translation_rebuild ? 'rebuild_bundle' : 'publish_finish';
		}
		if (empty($checklist['de_master_ready'])) {
			if ($translations_ready && self::publish_finish_resume_is_viable($payload)) {
				return 'publish_finish';
			}
			return 'rebuild_bundle';
		}
		if (empty($checklist['uk_ready'])) {
			return 'translate_uk';
		}
		if (empty($checklist['en_ready'])) {
			return 'translate_en';
		}
		if (empty($checklist['publish_finish_ready'])) {
			return ($requires_rebuild_fast && ! ($translations_ready && ! $allow_translation_rebuild)) ? 'rebuild_bundle' : 'publish_finish';
		}
		// Once the full language bundle exists, finish on the publish path instead
		// of re-opening rebuild loops for media/SEO polish. The publish_finish
		// stage will still make the final terminal decision if the package cannot
		// be salvaged automatically.
		if ($translations_ready && self::publish_finish_resume_is_viable($payload)) {
			if (! self::payload_is_review_ready_fast($payload) || empty($checklist['ready_publish'])) {
				return 'publish_finish';
			}
		}
		if (! self::payload_is_review_ready_fast($payload)) {
			return ($requires_rebuild_fast && ! ($translations_ready && ! $allow_translation_rebuild)) ? 'rebuild_bundle' : 'publish_finish';
		}
		if (empty($checklist['ready_publish'])) {
			return ($requires_rebuild_fast && ! ($translations_ready && ! $allow_translation_rebuild)) ? 'rebuild_bundle' : 'publish_finish';
		}
		if (! self::payload_has_publish_grade_substance($payload)) {
			return ($translations_ready && ! $allow_translation_rebuild) ? 'publish_finish' : 'rebuild_bundle';
		}
		if (! self::payload_is_terminal_publish_ready($payload)) {
			return 'publish_finish';
		}
		return '';
	}

	private static function de_master_is_viable_fast(array $payload): bool {
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		if ($de === []) {
			return false;
		}
		$title = trim((string) ($de['title'] ?? ''));
		$excerpt = trim((string) ($de['excerpt'] ?? ''));
		$content_plain = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));
		if ($title === '' || $excerpt === '' || $content_plain === '') {
			return false;
		}
		$de_combined = trim(implode(' ', [$title, $excerpt, $content_plain]));
		if (preg_match('/\p{Cyrillic}/u', $de_combined) === 1 || preg_match('/[A-Za-zÄÖÜäöüß]/u', $de_combined) !== 1) {
			return false;
		}
		$quality = is_array($payload['_meta']['quality'] ?? null) ? $payload['_meta']['quality'] : [];
		$min_quality = self::payload_ai_endorsement_minimum_quality($payload);
		if (empty($quality['pass']) || (int) ($quality['score'] ?? 0) < $min_quality) {
			return false;
		}
		$profile = self::payload_story_budget_profile($payload);
		$de_min = max(120, (int) ($profile['de']['content_min_chars'] ?? 320));
		$source_count = (int) ($payload['_meta']['source_count'] ?? 0);
		if ($source_count <= 0) {
			return false;
		}
		// Default budget profile is calibrated for the news_article kind.
		// Shorter kinds (breaking_alert / news_brief / sport_result) have
		// their own length floor in EPV2_Content_Kinds and don't need to
		// satisfy the budget profile's de_min.
		if (mb_strlen($content_plain) >= $de_min) {
			return true;
		}
		if (self::payload_allows_short_factual_bulletin($payload, $content_plain)) {
			return true;
		}
		return self::payload_meets_kind_short_form($payload, $content_plain);
	}

	private static function payload_allows_short_factual_bulletin(array $payload, string $content_plain): bool {
		if (mb_strlen($content_plain) < 480) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if (! empty($meta['blockers'])) {
			return false;
		}
		foreach (['quality', 'seo_quality', 'release_quality', 'google_quality'] as $key) {
			$quality = is_array($meta[$key] ?? null) ? $meta[$key] : [];
			if (empty($quality['pass'])) {
				return false;
			}
		}
		return (int) ($meta['source_count'] ?? 0) >= 1;
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
		return ! self::needs_language_repair($de, $candidate, $lang)
			|| self::translated_package_is_rescue_acceptable($de, $candidate, $lang);
	}

	private static function payload_language_ready_for_routing(array $payload, string $lang): bool {
		$lang = in_array($lang, ['uk', 'en'], true) ? $lang : '';
		if ($lang === '') {
			return false;
		}
		$candidate = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
		if ($candidate === []) {
			return false;
		}
		$title = trim((string) ($candidate['title'] ?? ''));
		$excerpt = trim((string) ($candidate['excerpt'] ?? ''));
		$content_plain = trim(wp_strip_all_tags((string) ($candidate['content'] ?? '')));
		if ($title === '' || $excerpt === '' || $content_plain === '') {
			return false;
		}
		if ($lang === 'uk') {
			$combined = $title . ' ' . $excerpt . ' ' . $content_plain;
			if (preg_match('/\p{Cyrillic}/u', $combined) !== 1 || preg_match('/[ыэёъ]/u', $combined) === 1) {
				return false;
			}
		}
		if ($lang === 'en') {
			$combined = $title . ' ' . $excerpt . ' ' . $content_plain;
			if (preg_match('/[A-Za-z]/u', $combined) !== 1 || preg_match('/\p{Cyrillic}/u', $combined) === 1) {
				return false;
			}
		}
		return mb_strlen($content_plain) >= 220;
	}

	private static function de_master_ready_for_routing(array $payload): bool {
		if (self::payload_context_rejects($payload) || ! self::de_master_is_viable_fast($payload)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$min_quality = self::payload_ai_endorsement_minimum_quality($payload);
		if ((int) ($meta['quality']['score'] ?? 0) < $min_quality) {
			return false;
		}
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$content_plain = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));
		return mb_strlen($content_plain) >= 320;
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
		$summary = self::context_summary_text($payload, $de, $story, $event, $selection);
		$locations = self::extract_location_terms(implode(' ', array_filter([
			(string) ($event['venue'] ?? ''),
			(string) ($event['event_title'] ?? ''),
			(string) ($de['title'] ?? ''),
			(string) ($de['excerpt'] ?? ''),
			$de_content_snippet,
			(string) ($story['body_snippet'] ?? ''),
		])));
		$dates = self::extract_date_terms(implode(' ', array_filter([
			(string) ($event['datetime_text'] ?? ''),
			(string) ($de['title'] ?? ''),
			(string) ($de['excerpt'] ?? ''),
			$de_content_snippet,
		])));
		$money_values = self::extract_money_terms(implode(' ', array_filter([
			(string) ($de['title'] ?? ''),
			(string) ($de['excerpt'] ?? ''),
			$de_content_snippet,
			(string) ($story['body_snippet'] ?? ''),
		])));
		$theses = self::extract_context_theses($de_content, (string) ($de['excerpt'] ?? ''), (array) ($event['fact_snippets'] ?? []), (array) ($story['body_keywords'] ?? []));

		$memory = [
			'kind' => sanitize_key((string) ($event['kind'] ?? '')),
			'summary' => $summary,
			'event_title' => sanitize_text_field((string) (($de['title'] ?? '') !== '' ? ($de['title'] ?? '') : ($event['event_title'] ?? ''))),
			'body_snippet' => sanitize_text_field((string) (($story['body_snippet'] ?? '') !== '' ? ($story['body_snippet'] ?? '') : $de_content_snippet)),
			'participants' => array_values(array_filter(array_map('sanitize_text_field', (array) ($event['participants'] ?? [])))),
			'entities' => array_values(array_filter(array_map('sanitize_text_field', array_merge(
				(array) ($story['entities'] ?? []),
				(array) ($existing['entities'] ?? [])
			)))),
			'locations' => $locations,
			'dates' => $dates,
			'money_values' => $money_values,
			'theses' => $theses,
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

		foreach (['participants', 'entities', 'locations', 'dates', 'money_values', 'theses', 'fact_snippets', 'search_terms'] as $field) {
			$memory[$field] = array_slice(array_values(array_unique(array_filter(array_merge(
				(array) ($memory[$field] ?? []),
				(array) ($existing[$field] ?? [])
			)))), 0, $field === 'search_terms' ? 8 : 8);
		}
		if (! empty($de['title'])) {
			$memory['search_terms'] = self::prioritize_search_terms_for_de((array) ($memory['search_terms'] ?? []));
		}

		foreach (['kind', 'summary', 'event_title', 'body_snippet', 'datetime_text', 'venue', 'stage', 'referee', 'head_to_head', 'next_step'] as $field) {
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

	public static function stabilize_context_memory(array $payload): array {
		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$payload['_meta']['context_memory'] = self::payload_context_memory($payload);
		return $payload;
	}

	public static function persist_context_memory_snapshot(int $item_id, array $payload): array {
		$payload = self::stabilize_context_memory($payload);
		$item = EPV2_Queue::get_item($item_id);
		if (! $item) {
			return $payload;
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['context_memory'] = is_array($payload['_meta']['context_memory'] ?? null) ? $payload['_meta']['context_memory'] : [];
		EPV2_Queue::update_fields($item_id, [
			'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
		return $payload;
	}

	private static function context_summary_text(array $payload, array $de, array $story, array $event, array $selection): string {
		$parts = array_filter([
			(string) ($de['title'] ?? ''),
			(string) ($de['excerpt'] ?? ''),
			(string) ($event['event_title'] ?? ''),
			(string) ($story['body_snippet'] ?? ''),
			(string) ($selection['reason'] ?? ''),
		]);
		$text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(implode(' ', $parts))) ?: '');
		return $text !== '' ? sanitize_text_field((string) mb_substr($text, 0, 320)) : '';
	}

	private static function extract_context_theses(string $content, string $excerpt, array $factSnippets = [], array $keywords = []): array {
		$theses = [];
		foreach ($factSnippets as $fact) {
			$fact = sanitize_text_field((string) $fact);
			if ($fact !== '') {
				$theses[] = $fact;
			}
		}
		foreach (preg_split('/(?<=[\.\!\?])\s+/u', trim($excerpt . ' ' . $content)) ?: [] as $sentence) {
			$sentence = trim(wp_strip_all_tags((string) $sentence));
			if ($sentence === '' || mb_strlen($sentence) < 40) {
				continue;
			}
			$theses[] = sanitize_text_field((string) mb_substr($sentence, 0, 180));
			if (count($theses) >= 4) {
				break;
			}
		}
		if ($theses === []) {
			foreach ($keywords as $keyword) {
				$keyword = sanitize_text_field((string) $keyword);
				if ($keyword !== '') {
					$theses[] = $keyword;
				}
			}
		}
		return array_slice(array_values(array_unique(array_filter($theses))), 0, 6);
	}

	private static function extract_money_terms(string $text): array {
		if ($text === '') {
			return [];
		}
		preg_match_all('/(?:€|\$|£)\s?\d[\d\.\,\s]{0,18}|\d[\d\.\,\s]{0,18}\s?(?:€|euro|eur|usd|dollar|руб|грн|zloty|zl)/iu', $text, $matches);
		return array_slice(array_values(array_unique(array_filter(array_map(static function ($value): string {
			return sanitize_text_field(trim((string) $value));
		}, (array) ($matches[0] ?? []))))), 0, 8);
	}

	private static function extract_date_terms(string $text): array {
		if ($text === '') {
			return [];
		}
		preg_match_all('/\b\d{1,2}[\.\/\-]\d{1,2}(?:[\.\/\-]\d{2,4})?\b|\b\d{4}-\d{2}-\d{2}\b|\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|январ|феврал|март|апрел|май|июн|июл|август|сентябр|октябр|ноябр|декабр)[\p{L}]*\s+\d{1,2}\b/iu', $text, $matches);
		return array_slice(array_values(array_unique(array_filter(array_map(static function ($value): string {
			return sanitize_text_field(trim((string) $value));
		}, (array) ($matches[0] ?? []))))), 0, 8);
	}

	private static function extract_location_terms(string $text): array {
		if ($text === '') {
			return [];
		}
		preg_match_all('/\b(?:in|im|bei|near|nearby|aus|von)\s+([A-ZÄÖÜ][\p{L}\-]{2,}(?:\s+[A-ZÄÖÜ][\p{L}\-]{2,}){0,2})\b/u', $text, $matches);
		$locations = array_map(static function ($value): string {
			return sanitize_text_field(trim((string) $value));
		}, (array) ($matches[1] ?? []));
		return array_slice(array_values(array_unique(array_filter($locations))), 0, 8);
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

	private static function recategorize_payload_from_de_master(array $payload): array {
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title = trim((string) ($de['title'] ?? ''));
		$content = trim((string) ($de['content'] ?? ''));
		$excerpt = trim((string) ($de['excerpt'] ?? ''));
		if ($title === '' && $content === '' && $excerpt === '') {
			return $payload;
		}

		$seed = sanitize_title((string) (((array) ($payload['categories'] ?? ['']))[0] ?? ''));
		if ($seed === '') {
			$seed = sanitize_title((string) ($payload['_meta']['selection']['category'] ?? ''));
		}
		if ($seed === '') {
			$seed = 'deutschland';
		}

		$resolved = EPV2_Categorizer::resolve_for_payload($payload, $title, $content !== '' ? $content : $excerpt, $seed);
		if ($resolved !== '') {
			$payload['categories'] = EPV2_Review::normalize_categories($resolved);
		}

		return $payload;
	}

	private static function refresh_selection_from_payload(array $payload): array {
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$title = trim((string) ($de['title'] ?? ''));
		$excerpt = trim((string) ($de['excerpt'] ?? ''));
		$content = trim((string) ($de['content'] ?? ''));
		if ($title === '' && $excerpt === '' && $content === '') {
			return $payload;
		}

		$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$current_selection = is_array($payload['_meta']['selection'] ?? null) ? $payload['_meta']['selection'] : [];
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$primary = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
		$category = sanitize_title((string) (((array) ($payload['categories'] ?? ['']))[0] ?? 'deutschland'));

		$analysis = EPV2_Budget_Manager::analyze_item([
			'title' => $title,
			'excerpt' => $excerpt,
			'content' => $content,
			'url' => (string) ($primary['url'] ?? ($payload['source']['url'] ?? '')),
			'date' => (string) ($primary['date'] ?? ($primary['published_at'] ?? ($primary['datetime'] ?? ''))),
			'image' => (string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''),
			'category' => $category,
		]);

		if (! empty($current_selection['category']) && empty($analysis['initial_category']) && (string) $current_selection['category'] !== (string) ($analysis['category'] ?? '')) {
			$analysis['initial_category'] = (string) $current_selection['category'];
		}

		$payload['_meta']['selection'] = $analysis;
		return $payload;
	}

	private static function attempt_publish_grade_lift(object $item, array $payload, array $categories, string $style, bool $de_only = false): array {
		$item = self::hydrate_item_for_generation($item);
		return $de_only
			? self::lift_de_master_to_publish_grade($item, $payload, $categories, $style)
			: self::lift_multilingual_payload_to_publish_grade($item, $payload, $categories, $style);
	}

	private static function lift_de_master_to_publish_grade(object $item, array $payload, array $categories, string $style): array {
		$payload = self::normalize_existing_payload($payload, false);
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$de_text = mb_strtolower(trim(
			(string) ($de['title'] ?? $item->original_title ?? '') . ' ' .
			(string) ($de['excerpt'] ?? $item->original_excerpt ?? '')
		));
		$detected_category = EPV2_Categorizer::resolve_for_payload(
			$payload,
			(string) ($de['title'] ?? $item->original_title ?? ''),
			(string) ($de['content'] ?? $item->original_content ?? ''),
			(string) ($item->category_proposed ?? '')
		);
		if (preg_match('/\b(tatort|batic|leitmayr|fernsehgeschichte|krimireihe|fanpremiere)\b/u', $de_text) === 1) {
			$detected_category = 'kultur';
		}
		if ($detected_category !== '') {
			$payload['categories'] = [$detected_category];
			$payload = self::align_selection_with_payload_category($payload);
			$categories = EPV2_Review::normalize_categories($detected_category);
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$has_fresh_dossier = ! empty($meta['source_dossier']) && (int) ($meta['source_count'] ?? 0) >= 2;
		$needs_rebuild = self::payload_needs_enrichment_rebuild($payload);
		$needs_context_refresh = self::payload_publish_finish_needs_context_refresh($payload);
		if (($needs_rebuild || $needs_context_refresh) && ! $has_fresh_dossier) {
			return self::refresh_payload_context_for_publish_lift($item, $payload, $categories);
		}
		$release_text = mb_strtolower(implode(' | ', self::warning_strings($payload['_meta']['release_quality']['warnings'] ?? [])));
		$media_contract_passes = self::publish_ready_gate_media_contract_passes($payload);
		$needs_media_repair = ! self::payload_has_media_candidate($payload)
			|| ! $media_contract_passes
			|| (! $media_contract_passes && preg_match('/generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|нет featured media|нет главного изображения/u', $release_text) === 1);
		if ($needs_media_repair) {
			return self::repair_payload_media($item, $payload);
		}

		$release_warnings = array_map('strval', (array) ($meta['release_quality']['warnings'] ?? []));
		$quality_warnings = array_map('strval', (array) (($meta['quality']['warnings']['de'] ?? [])));
		$de_requires_lift = false;
		foreach ($release_warnings as $warning) {
			if (preg_match('/^(DE|UK|EN): .*?(слишком коротким|сломанная языковая версия)/iu', $warning, $matches)) {
				$lang = strtolower((string) $matches[1]);
				if ($lang === 'de') {
					$de_requires_lift = true;
				}
			}
		}
		foreach ($quality_warnings as $warning) {
			$text = mb_strtolower(trim((string) $warning));
			if (preg_match('/короче ожидаемого редакционного диапазона|слишком коротк|почти не добавляет новой информации|поверхностн/u', $text) === 1) {
				$de_requires_lift = true;
				break;
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
		if ($before_de !== $after_de && ! self::payload_is_terminal_publish_ready($payload)) {
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
		$finalized = self::finalize_payload_for_queue($payload);
		$no_progress = $before_de === md5((string) wp_json_encode($finalized, JSON_UNESCAPED_UNICODE));
		if (! self::payload_is_terminal_publish_ready($finalized) && $no_progress && self::payload_next_required_stage($finalized) === 'publish_finish') {
			// Final-stage no-progress must stay on publish_finish for bounded media/SEO repair.
			// Sending a complete multilingual bundle back to rebuild_bundle reopens the same
			// loop and blocks the single-owner lane without adding any new information.
			$finalized['_meta'] = is_array($finalized['_meta'] ?? null) ? $finalized['_meta'] : [];
			$finalized['_meta']['publish_finish_no_progress'] = (int) ($finalized['_meta']['publish_finish_no_progress'] ?? 0) + 1;
			$finalized = self::set_payload_pipeline_stage($finalized, 'publish_finish');
			return self::finalize_payload_for_queue($finalized, false);
		}

		return $finalized;
	}

	private static function payload_publish_finish_needs_context_refresh(array $payload): bool {
		$payload = self::finalize_payload_for_queue($payload);
		if (! self::payload_is_review_ready($payload) || self::payload_is_terminal_publish_ready($payload)) {
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
			&& preg_match('/слабое досье источников|нет featured media|нет главного изображения|generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|рубрика не соответствует/u', $text) === 1
		) {
			return true;
		}

		if (! self::payload_has_media_candidate($payload) && $source_count <= 2) {
			return true;
		}

		return false;
	}

	private static function refresh_payload_context_for_publish_lift(object $item, array $payload, array $categories): array {
		$item = EPV2_Google_News::normalize_item_source($item);
		$target_supporting = self::payload_needs_deeper_supporting_enrichment($payload) ? 3 : 2;
		$max_runtime = self::payload_needs_deeper_supporting_enrichment($payload) ? 8 : 5;
		$dossier = EPV2_Source_Enricher::enrich_item($item, [
			'force_supporting' => true,
			'target_supporting' => $target_supporting,
			'max_runtime_seconds' => $max_runtime,
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
		$resolved = EPV2_Categorizer::resolve_for_payload($payload, $title, $content, $seed);
		if ($resolved !== '') {
			$payload['categories'] = EPV2_Review::normalize_categories($resolved);
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

	private static function payload_needs_deeper_supporting_enrichment(array $payload): bool {
		if ($payload === []) {
			return false;
		}
		$payload = self::normalize_payload_without_stage_refresh($payload);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$source_count = (int) ($meta['source_count'] ?? 0);
		if ($source_count > 1) {
			return false;
		}
		$single_source_relaxation = self::payload_allows_single_source_completion($payload);
		if (
			! is_array($meta['quality'] ?? null)
			|| ! is_array($meta['release_quality'] ?? null)
			|| ! is_array($meta['google_quality'] ?? null)
		) {
			if ($single_source_relaxation && self::de_master_is_viable_fast($payload)) {
				return false;
			}
			return ! self::de_master_is_viable_fast($payload) || self::payload_primary_media_url($payload) === '';
		}
		$warning_text = mb_strtolower(implode(' | ', array_merge(
			self::warning_strings($meta['quality']['warnings'] ?? []),
			self::warning_strings($meta['release_quality']['warnings'] ?? []),
			self::warning_strings($meta['google_quality']['warnings'] ?? [])
		)));
		if ($single_source_relaxation) {
			$warning_text = self::strip_single_source_relaxable_warnings($warning_text);
			if ($warning_text === '') {
				return false;
			}
		}
		return preg_match('/слаб(ый|ое|ая) текст|слабое досье источников|generic stock featured media|слишком слабое.*featured media|не соответствует теме материала|нет featured media|нет главного изображения/u', $warning_text) === 1;
	}

	private static function strip_single_source_relaxable_warnings(string $warning_text): string {
		// Long-form official primary and low-risk preview/community items often
		// have no meaningful supporting-search upside. Strip warnings that only
		// restate the single-source/media limitation so routing can continue to
		// publish-finish/manual-review instead of looping in rebuild.
		$warning_text = preg_replace('/\bсломанная языковая версия\b/iu', '', $warning_text);
		$warning_text = preg_replace('/\bневалидн.*индексац[^\|]*\b/iu', '', $warning_text);
		$warning_text = preg_replace('/\bнет featured media\b/iu', '', $warning_text);
		$warning_text = preg_replace('/\bнет главного изображения(?:\s+для google)?\b/iu', '', $warning_text);
		$warning_text = preg_replace('/\bgeneric stock featured media\b/iu', '', $warning_text);
		$warning_text = preg_replace('/\bслишком слабое.*featured media\b/iu', '', $warning_text);
		$warning_text = preg_replace('/\bне соответствует теме материала\b/iu', '', $warning_text);
		$warning_text = preg_replace('/\b(?:[a-z]{2}\s*:\s*)?материал может быть слишком коротким[^\|]*/iu', '', $warning_text);
		$warning_text = preg_replace('/\b(?:[a-z]{2}\s*:\s*)?материал может быть слишком поверхностным[^\|]*/iu', '', $warning_text);
		$warning_text = preg_replace('/\b(?:[a-z]{2}\s*:\s*)?слишком\s+слабое\s+досье\s+источников[^\|]*/iu', '', $warning_text);
		$warning_text = preg_replace('/\b(?:[a-z]{2}\s*:\s*)?слабое\s+досье\s+источников[^\|]*/iu', '', $warning_text);
		return trim((string) $warning_text, " |\t\n\r\0\x0B");
	}

	private static function payload_allows_single_source_completion(array $payload): bool {
		if ($payload === []) {
			return false;
		}
		$payload = self::normalize_payload_without_stage_refresh($payload);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if ((int) ($meta['source_count'] ?? 0) > 1) {
			return false;
		}
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		$content_plain = trim(wp_strip_all_tags((string) ($de['content'] ?? '')));
		$primary_url = (string) ($meta['source_dossier']['primary']['url'] ?? '');
		if ($primary_url === '') {
			$primary_url = (string) ($payload['original_url'] ?? $meta['original_url'] ?? '');
		}
		$official_primary_longform = self::looks_like_official_primary($primary_url) && mb_strlen($content_plain) >= 520;
		$primary_category = sanitize_key((string) ((array) ($payload['categories'] ?? ['']))[0]);
		$event_kind = sanitize_key((string) ($meta['source_dossier']['event_context']['kind'] ?? ''));
		$single_source_longform_preview = (
			in_array($primary_category, ['community', 'leben-in-deutschland', 'kultur', 'bayern', 'munchen', 'muenchen'], true)
			|| in_array($event_kind, ['community', 'kultur'], true)
		) && mb_strlen($content_plain) >= 900;
		$single_source_longform_sport = (
			$primary_category === 'sport'
			|| $event_kind === 'sport'
		) && mb_strlen($content_plain) >= 1400;
		return $official_primary_longform
			|| $single_source_longform_preview
			|| $single_source_longform_sport
			|| self::payload_allows_short_factual_bulletin($payload, $content_plain);
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
		// Editorial preference (operator 2026-05-10): Wikimedia and Pexels
		// produce off-topic / low-relevance images for news stories. They
		// must NEVER pass the publish gate as featured media — items that
		// fall through to these hosts go to manual_review for hand-curation
		// or rejection. The resolver still tries them as last-resort, but
		// the gate refuses them regardless of story_card.media_required.
		foreach (['pexels.com', 'images.pexels.com', 'commons.wikimedia.org', 'upload.wikimedia.org'] as $signal) {
			if (str_contains($host, $signal)) {
				return true;
			}
		}
		// URL pattern detection (operator-feedback 2026-05-11): some publishers
		// serve generic OG-fallback / placeholder / default images when
		// article-specific image не available (e.g., orf.at og-fallback-news.png,
		// generic «teaser-fallback», «default-cover», «placeholder.jpg»).
		// These are content-agnostic graphics that look broken для news cards.
		// Item 2253 case: euronews article published с orf.at og-fallback.
		$path = (string) wp_parse_url($url, PHP_URL_PATH);
		$haystack = strtolower($path);
		foreach ([
			'og-fallback',
			'og_fallback',
			'/fallback-',
			'/default-cover',
			'/default-image',
			'/default-thumb',
			'placeholder',
			'teaser-fallback',
			'common/images/og-',
			'/social-share-default',
		] as $pattern) {
			if (str_contains($haystack, $pattern)) {
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
		return $mode === 'auto';
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
			'analytic' => 'Стиль: спокойный аналитический newsroom уровня Deutsche Welle, BBC или Reuters, но всё равно живой и ясный. Аналитика не должна превращаться в канцелярский отчёт. Нужны естественные переходы, короткие понятные предложения и человеческий ритм. Заголовок должен быть информативным, а не литературным. Лид в 2 предложениях. Основной текст обязан объяснять развитие темы, последствия и следующий вероятный шаг.',
			'lively' => 'Стиль: обычная сильная новостная статья уровня Deutsche Welle, Tagesschau, BBC, AP или CNN. Тон живой, лёгкий, интересный, но профессиональный. Заголовок короткий, информативный и смысловой, обычно 6-14 слов, без жёсткого кликбейта. Лид ровно в 2 предложениях. Текст начинается с проблемы, изменения или последствия для читателя. Предложения в основном короткие или средние, без громоздких конструкций.',
			default => 'Стиль: обычный сильный newsroom-материал уровня Tagesschau, BBC, AP или Reuters. Не сухой, не бюрократический, не похожий на пресс-релиз. Пиши короткими, понятными предложениями и держи живой темп текста. Заголовок должен сообщать новость, а не просто обозначать тему.',
		};
		$format_hint = match ($story_format) {
			'developing' => 'Формат: developing story. Покажи развитие темы в плавном новостном тексте. Не дели статью на шаблонные секции с подписями вроде "Контекст", "Почему это важно", "Что дальше".',
			'analysis' => 'Формат: weekly analysis. Построй более крупный аналитический материал как нормальную журналистскую статью, а не как отчёт по шаблону. Не дели текст на формальные блоки с подписями "Контекст", "Расширенный контекст", "Почему это важно".',
			default => '',
		};
		$url_hint = $use_source_url ? 'При анализе учитывай URL первоисточника и, если инструмент модели это поддерживает, используй web/url context для проверки фактов.' : '';
		$citation_hint = $require_citations ? 'Если провайдер умеет grounding, опирайся на него. Внутрь текста статьи цитаты не вставляй, но допускается вернуть ссылки/опоры в raw-метаданных ответа.' : '';
		$shape_hint = match ((string) ($budget_de['shape'] ?? 'news')) {
			'bulletin' => 'Это короткая информационная заметка. Держи рабочий диапазон примерно 300-450 слов, 4-7 абзацев. Важны точность, польза и ясность, а не искусственная длина.',
			'service_note' => 'Это сервисная или community-заметка. Нужны конкретика, сроки, место, последствия для читателя и практическая польза. Не раздувай текст пустым контекстом. Рабочая длина обычно 350-650 слов.',
			'preview' => 'Это preview/event-материал. Если подтверждено, добавь когда, где, стадия, участники, что дальше и при желании короткий дополнительный контекст. Рабочая длина обычно 450-800 слов.',
			'article' => 'Это полноценная новость-статья, а не короткая заметка. Нужен плотный, но не раздутый материал с ясным объяснением последствий. Рабочая длина обычно 450-800 слов.',
			default => 'Длина должна соответствовать реальной наполненности материала: короткая новость 300-450 слов, стандартная 450-800, developing story 800-1200, аналитика 1400-2200.',
		};
		$war_tone_hint = 'Если тема связана с войной России против Украины, ударами по военным объектам, потерями российской армии, оккупированным Крымом или действиями украинской обороны, держи тон сухим, фактическим и стратегическим. Не используй сочувствующие или траурные формулы по отношению к потерям российской армии и военным объектам агрессора. Не создавай ложного морального симметризма. Допустимо ясно указывать, что Украина обороняется от российской агрессии, а удары по российской военной инфраструктуре являются частью этой войны. При этом не скатывайся в лозунги: только точные факты, контекст и последствия.';
		$source_hint = 'Если исходный сигнал короткий или бедный, обязательно усили материал на основе первоисточника и ещё 1-3 подтверждающих публикаций из досье. Старайся ссылаться по смыслу на первоисточник и опираться именно на него как на основную фактуру. Если в досье есть короткая подтверждённая цитата с атрибуцией, используй одну такую цитату естественно внутри текста, а не как служебный блок. Когда в статье появляется прямая речь, указывай не только автора, но и площадку или контекст: например, что человек заявил это в интервью конкретному изданию, в заявлении для конкретного источника или по данным конкретной публикации. Не повторяй такую отсылку в каждом абзаце, но не оставляй цитату без ясной привязки. Для media_url используй только реальное релевантное изображение из первоисточника или из подтверждающих источников по той же теме. Не предлагай generated cover, абстрактный сток и декоративную заглушку для обычной news automation. Если у изображения есть авторство или подпись в источнике, сохрани это в raw-поле caption/source_label, если провайдер ответа это поддерживает. Если в source_dossier.event_context есть подтверждённые детали события, используй их естественно и только по делу: когда проходит матч или событие, где оно проходит, кто участвует, какая стадия, кто судит, что ждёт победителя дальше. Не выдумывай отсутствующие детали и не перенасыщай текст спортивным или сервисным фоном. Исходные тексты и сигналы могут быть на любом языке, но итоговый мастер-текст должен быть нормальным немецким newsroom-материалом без следов исходного языка. ' . $war_tone_hint . ' ' . $shape_hint;
		$final_editorial_guard = 'Финальные жёсткие правила важнее любых пользовательских промптов ниже: не пиши списки служебных разделов, не добавляй подзаголовки "Контекст/Почему это важно/Что дальше", не растягивай короткий сигнал. Обычная новость должна быть умной, но лёгкой: короткие абзацы, простые предложения, без воды, без повторов и без фраз "es bleibt abzuwarten", "weitere Details werden bekannt", "Fans können sich freuen". Каждый существенный факт должен быть взят из original_* или source_dossier. Если фактов мало, пиши коротко и точно, а не длинно.';
		$original_excerpt = self::trim_input_text((string) $item->original_excerpt, 1200);
		$original_content = self::trim_input_text((string) $item->original_content, $reduced_context ? 4500 : 9000);
		$input_dossier = self::compact_source_dossier($dossier, $reduced_context);
		return [
				[
					'role' => 'system',
					'content' => 'Ты редакционный AI для новостного сайта EuroPulse. Верни только JSON. Не добавляй комментарии. Не копируй исходный текст дословно. Сначала создай сильный мастер-материал только на немецком языке. Это глубокий фактологический рерайт, а не выдумка: все твёрдые факты должны опираться на original_* или source_dossier. Запрещено придумывать даты, годы, время, место, числа, имена, должности, цитаты, причины, последствия, организации, участников, результаты и будущие шаги. Если в исходнике написано "gestern", "morgen", "am Abend", "kurz vor der Wahl" или другая относительная дата, не превращай её в конкретную календарную дату, если конкретной даты нет в исходнике. Если детали не хватает, пиши обобщённо или пропусти её; лучше короткий точный материал, чем длинный текст с заполнителями. Пиши как современное европейское цифровое медиа: профессионально, ясно, живо и плавно. Запрещены канцелярит, чиновничья сухость, язык пресс-релиза, советский бюллетень и рубленая структура из служебных подзаголовков. Не пиши блоками вида "Почему это важно:", "Контекст:", "Расширенный контекст:", "Что дальше:". Вместо этого строй цельную статью с естественными переходами, как в DW, Tagesschau, BBC, Reuters, AP или Al Jazeera. Начинай материал с проблемы, изменения, риска или главного последствия для читателя. Заголовок должен сообщать новость, а не просто тему: субъект + действие + главный поворот или последствие. Обычно держи заголовок в диапазоне 6-14 слов и без пустых общих формул. Лид должен состоять ровно из двух предложений и сразу объяснять, о чём статья и почему это важно. Для dek/lead держи рабочий диапазон примерно 25-55 слов суммарно. Основной текст строй по редакционному приоритету: сначала главный факт, затем подтверждение, затем ключевые детали, затем последствия, затем контекст и следующий шаг. Не перегружай текст полными официальными названиями законов и номерами параграфов, если это можно передать человеческим языком без потери точности. Для обычной новости не раздувай длину искусственно: если фактуры немного, лучше 4-7 сильных абзацев с плотной информацией, чем длинный пустой текст. Нужны человеческий ритм, сильный лид, понятные переходы, практическая польза для читателя и ясное объяснение, почему тема важна. Избегай длинных предложений: предпочитай короткие и средние конструкции. Соблюдай реальные лимиты интерфейса сайта: заголовки и лиды должны помещаться в карточки и слайдер без грязного обрезания. Если текст не помещается, не обрубай смысл, а переформулируй короче и чище. Особенно строго следи за украинской версией: она должна полностью влезать в самые узкие карточки сайта без троеточий и обрубленных хвостов. ' . $style_hint . ' ' . $format_hint . ' ' . $url_hint . ' ' . $citation_hint . ' ' . $source_hint . ' ' . $custom_prompt . ' ' . $final_editorial_guard . ' Формат JSON: {"categories":["slug1","slug2"],"media_url":"...","languages":{"de":{"title":"","excerpt":"","content":"","media_url":""}}}',
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
							'anti_hallucination' => 'no_new_dates_numbers_names_quotes_causes_consequences_unless_explicitly_supported_by_original_or_source_dossier',
							'relative_time_rule' => 'do_not_convert_relative_time_words_to_calendar_dates_without_explicit_source_date',
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
				if (! $defer_translations) {
					$data = EPV2_AI_Response_Validator::enrich_payload($data);
				}
				$data['categories'] = EPV2_Review::normalize_categories(implode(',', $data['categories'] ?? $categories));
				$de_seed = EPV2_Review::build_language_package($item, 'de', $style);
				$data['languages']['de'] = array_merge(
					$de_seed,
					is_array($data['languages']['de'] ?? null) ? $data['languages']['de'] : []
				);
				$data['languages']['de']['title'] = sanitize_text_field((string) ($data['languages']['de']['title'] ?? ''));
				$data['languages']['de']['excerpt'] = sanitize_textarea_field((string) ($data['languages']['de']['excerpt'] ?? ''));
				$data['languages']['de']['content'] = wp_kses_post((string) ($data['languages']['de']['content'] ?? ''));
				$data['media_url'] = EPV2_Media::normalize_featured_candidate_url((string) ($data['media_url'] ?? $data['languages']['de']['media_url'] ?? $item->source_image_url ?? ''));
				$data['languages']['de']['media_url'] = EPV2_Media::normalize_featured_candidate_url((string) ($data['languages']['de']['media_url'] ?? $data['media_url']));
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
				if (! $defer_translations) {
					$data = EPV2_AI_Response_Validator::enrich_payload($data);
					$validation = EPV2_AI_Response_Validator::validate($data);
					if (! $validation['valid']) {
						$last_error = new RuntimeException('AI payload validation failed');
						continue;
					}
				} else {
					$validation = [
						'valid' => trim((string) ($data['languages']['de']['title'] ?? '')) !== ''
							&& trim((string) ($data['languages']['de']['excerpt'] ?? '')) !== ''
							&& trim(wp_strip_all_tags((string) ($data['languages']['de']['content'] ?? ''))) !== '',
						'quality' => self::fast_stage_routing_quality($data),
						'seo' => ['score' => 0, 'pass' => false, 'warnings' => []],
						'release' => ['score' => 0, 'pass' => false, 'warnings' => []],
						'google' => ['score' => 0, 'pass' => false, 'warnings' => []],
					];
					if (! $validation['valid']) {
						$last_error = new RuntimeException('AI payload validation failed');
						continue;
					}
				}
				$data['_meta'] = [
					'provider' => (string) ($config['provider'] ?? ''),
					'model' => (string) ($config['model'] ?? ''),
					'tokens' => (int) ($result['tokens'] ?? 0),
					'style' => $style,
					'canonical_language' => 'de',
					'breaking' => ! empty($data['_meta']['breaking']),
					'top_story' => ! empty($data['_meta']['top_story']),
					'source_dossier' => self::compact_source_dossier($dossier, false),
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
		// Earlier versions of this function dropped `content` entirely and
		// stored only an `excerpt` truncated to 320–700 chars — that meant
		// the worker only ever saw a few hundred characters of the source
		// even when the enricher had fetched a 5–10 KB full article. The
		// "DE master too short" warnings + rebuild_bundle loop traced
		// directly to that truncation. Now we keep both: a short `excerpt`
		// for previews / contracts that already expect it, and a much
		// larger `content` field with the cleaned full body. With the
		// 10 MB ai_payload safety guard in place we have headroom; even a
		// worst-case 4 sources × 12 KB body + meta is well under 1 MB.
		$compact = [];
		$primary = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
		if ($primary !== []) {
			$compact['primary'] = [
				'title' => self::trim_input_text((string) ($primary['title'] ?? ''), 240),
				'url' => (string) ($primary['url'] ?? ''),
				'excerpt' => self::trim_input_text((string) ($primary['excerpt'] ?? $primary['content'] ?? ''), $reduced_context ? 320 : 700),
				'content' => self::trim_input_text((string) ($primary['content'] ?? $primary['excerpt'] ?? ''), $reduced_context ? 6000 : 12000),
				'image' => EPV2_Media::normalize_featured_candidate_url((string) ($primary['image'] ?? '')),
				'date' => sanitize_text_field((string) ($primary['date'] ?? '')),
			];
		}
		$shell_primary = is_array($dossier['shell_primary'] ?? null) ? $dossier['shell_primary'] : [];
		if ($shell_primary !== []) {
			$compact['shell_primary'] = [
				'title' => self::trim_input_text((string) ($shell_primary['title'] ?? ''), 220),
				'url' => (string) ($shell_primary['url'] ?? ''),
				'excerpt' => self::trim_input_text((string) ($shell_primary['excerpt'] ?? $shell_primary['content'] ?? ''), $reduced_context ? 240 : 520),
				'content' => self::trim_input_text((string) ($shell_primary['content'] ?? $shell_primary['excerpt'] ?? ''), $reduced_context ? 4000 : 8000),
				'image' => EPV2_Media::normalize_featured_candidate_url((string) ($shell_primary['image'] ?? '')),
				'date' => sanitize_text_field((string) ($shell_primary['date'] ?? '')),
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
				'content' => self::trim_input_text((string) ($source['content'] ?? $source['excerpt'] ?? ''), $reduced_context ? 3000 : 6000),
				'image' => EPV2_Media::normalize_featured_candidate_url((string) ($source['image'] ?? '')),
				'date' => sanitize_text_field((string) ($source['date'] ?? '')),
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
		if ($lang === 'en' && self::translation_has_excessive_german_leakage($combined)) {
			return true;
		}
		if ($lang === 'en' && (preg_match('/\p{Cyrillic}/u', $combined) === 1 || preg_match('/[A-Za-z]/u', $combined) !== 1)) {
			return true;
		}
		if ($lang === 'uk' && self::translation_has_excessive_german_leakage($combined)) {
			return true;
		}
		if (! self::translation_preserves_core_entities($de, $combined, $lang)) {
			return true;
		}
		if (! self::translation_preserves_topic_markers($de, $combined, $lang)) {
			return true;
		}
		if (self::translation_has_semantic_drift($de, $combined, $lang)) {
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

	private static function translate_language_package_rescue(array $base, string $lang, array $config): ?array {
		$configs = self::translation_candidate_configs($config);
		if ($configs === []) {
			return null;
		}
		$localeLabel = $lang === 'uk' ? 'украинский' : 'английский';
		$contentLength = mb_strlen(trim(wp_strip_all_tags((string) ($base['content'] ?? ''))));
		foreach ($configs as $candidate_config) {
			if (! self::provider_candidate_is_ready($candidate_config, 'translation_rescue')) {
				continue;
			}
			$request = $candidate_config;
			$request['gemini_search_grounding_enabled'] = false;
			$request['gemini_url_context_enabled'] = false;
			$request['timeout'] = max(26, min(42, self::translation_timeout_for_content($request, $contentLength, false) + 8));
			$request['max_tokens'] = max(1800, min(2600, (int) ($request['max_tokens'] ?? 2200)));
			try {
				self::heartbeat_active_process_lock();
				$result = EPV2_AI_Client::generate($request, self::translation_messages($base, $lang, $localeLabel, false));
				self::heartbeat_active_process_lock();
				$data = json_decode(self::clean_json_response((string) ($result['text'] ?? '')), true);
				if (! is_array($data)) {
					continue;
				}
				$package = [
					'title' => sanitize_text_field((string) ($data['title'] ?? '')),
					'excerpt' => sanitize_textarea_field((string) ($data['excerpt'] ?? '')),
					'content' => wp_kses_post((string) ($data['content'] ?? '')),
				];
				if (self::translated_package_is_rescue_acceptable($base, $package, $lang)) {
					return $package;
				}
			} catch (Throwable $e) {
				EPV2_Logger::warning('ai', 'Rescue language translation failed', [
					'lang' => $lang,
					'provider' => (string) ($request['provider'] ?? ''),
					'error' => $e->getMessage(),
				]);
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

	private static function translation_bridge_base(array $payload, string $lang): array {
		if ($lang === 'uk') {
			$bridge = is_array($payload['languages']['en'] ?? null) ? $payload['languages']['en'] : [];
			if ($bridge !== []) {
				return $bridge;
			}
		}
		return [];
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
				'content' => 'Верни только JSON вида {"title":"","excerpt":"","content":""}. Переведи и редакционно адаптируй материал на ' . $localeLabel . ' язык. В ответе должен быть только целевой язык: ни одного немецкого предложения, ни одной немецкой служебной фразы, ни одного немецкого заголовка. Это должна быть полноценная newsroom-версия, а не буквальный перевод с немецкого. Нужен плавный, естественный, современный новостной текст уровня BBC, Reuters, AP, CNN, Al Jazeera или Deutsche Welle. Заголовок должен быть информативным и новостным, а не просто тематическим; обычно 6-14 слов. Лид должен состоять из 2 предложений и быстро объяснять, что произошло и почему это важно. Основной текст должен идти по смысловому приоритету: главный факт, подтверждение, детали, последствия, контекст, следующий шаг. Запрещены бюрократический тон, шаблонные секции, канцелярит, тяжёлые обороты и калька с немецкого синтаксиса. Не пиши подзаголовками внутри текста: никаких "Контекст:", "Почему это важно:", "Что дальше:". Если исходный заголовок похож на номер документа, название решения, служебную ссылку или административный реестр, не переводи его буквально: создай нормальный читабельный newsroom-заголовок по смыслу материала. Если немецкий текст перегружен названиями законов, длинными официальными формулами или номерами документов, переводи смысл человеческим языком, сохраняя точность. Нельзя менять географию, страну, город, регион, институции и базовые факты исходного немецкого текста. Если в исходнике речь о Германии или Мюнхене, нельзя заменять это на Украину, Польшу или любую другую страну/локацию.',
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
				self::translation_has_excessive_german_leakage($combined)
				|| preg_match('/[ыэёъ]/u', $combined) === 1
			) {
				return false;
			}
		}
		if ($lang === 'en') {
			if (
				self::translation_has_excessive_german_leakage($combined)
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
		if (! self::translation_preserves_core_entities($base, $combined, $lang)) {
			return false;
		}
		if (self::translation_has_semantic_drift($base, $combined, $lang)) {
			return false;
		}
		return mb_strlen($content) >= self::translated_content_min_length($deContent);
	}

	private static function translated_package_is_rescue_acceptable(array $base, array $candidate, string $lang): bool {
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
			if (self::translation_has_excessive_german_leakage($combined) || preg_match('/[ыэёъ]/u', $combined) === 1) {
				return false;
			}
		}
		if ($lang === 'en') {
			if (
				self::translation_has_excessive_german_leakage($combined)
				|| preg_match('/\p{Cyrillic}/u', $combined) === 1
				|| preg_match('/[A-Za-z]/u', $combined) !== 1
			) {
				return false;
			}
		}
		if (! self::translation_preserves_geography($base, $combined, $lang)) {
			return false;
		}
		if (! self::translation_preserves_core_entities($base, $combined, $lang)) {
			return false;
		}
		if (! self::translation_preserves_topic_markers($base, $combined, $lang)) {
			return false;
		}
		return mb_strlen($content) >= max(220, (int) floor(self::translated_content_min_length($deContent) * 0.55));
	}

	public static function payload_languages_are_semantically_consistent(array $payload): bool {
		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		if ($de === []) {
			return false;
		}
		foreach (['uk', 'en'] as $lang) {
			$candidate = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
			if (self::translated_package_is_rescue_acceptable($de, $candidate, $lang)) {
				continue;
			}
			$combined = trim(implode(' ', array_filter([
				(string) ($candidate['title'] ?? ''),
				(string) ($candidate['excerpt'] ?? ''),
				wp_strip_all_tags((string) ($candidate['content'] ?? '')),
			])));
			if ($combined === '') {
				return false;
			}
			if (! self::translation_preserves_core_entities($de, $combined, $lang)) {
				return false;
			}
			if (! self::translation_preserves_topic_markers($de, $combined, $lang)) {
				return false;
			}
			if (self::translation_has_semantic_drift($de, $combined, $lang)) {
				return false;
			}
		}
		return true;
	}

	private static function translation_has_excessive_german_leakage(string $text): bool {
		if ($text === '') {
			return false;
		}
		$text = mb_strtolower($text);
		if (preg_match('/\b(die bundesregierung|bundesregierung|akteuren vor ort|fachkr[aä]fte|integrationsmittel|berlin will)\b/u', $text) === 1) {
			return true;
		}
		preg_match_all('/\b(der|die|das|und|mit|für|wird|nicht|mehr|hilfe|deutschland|kommunen|bund)\b/u', $text, $matches);
		return count((array) ($matches[0] ?? [])) >= 3;
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
		return true;
	}

	private static function translation_preserves_core_entities(array $base, string $translatedCombined, string $lang): bool {
		$source = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($base['title'] ?? ''),
			(string) ($base['excerpt'] ?? ''),
			wp_strip_all_tags((string) ($base['content'] ?? '')),
		]))));
		$translated = mb_strtolower($translatedCombined);
		$entityMap = [
			'/\bukraine\b|\bukrainisch/u' => [
				'uk' => '/\bукраїн[аиіює]\b|\bукраїнськ/u',
				'en' => '/\bukraine\b|\bukrainian\b/u',
			],
			'/\biran\b/u' => [
				'uk' => '/\bіран[а-яіїєґ]*\b/u',
				'en' => '/\biran\b/u',
			],
			'/\bisrael\b/u' => [
				'uk' => '/\bізраїл[а-яіїєґ]*\b/u',
				'en' => '/\bisrael\b/u',
			],
			'/\brussland\b|\brussisch\b|\bmoskau\b|\bkrim\b/u' => [
				'uk' => '/\bрос(і|и)(я|ї|єю|ю|йськ)|\bросійськ|\bмоскв|\bкрим/u',
				'en' => '/\brussia\b|\brussian\b|\bmoscow\b|\bcrimea\b/u',
			],
			'/\bbundesregierung\b/u' => [
				'uk' => '/\bфедеральн[а-яіїєґ]*.*уряд[а-яіїєґ]*\b|\bуряд[а-яіїєґ]* німеччин[а-яіїєґ]*\b|\bbundesregierung\b/u',
				'en' => '/\bfederal government\b|\bgerman government\b|\bbundesregierung\b/u',
			],
			'/\bbbk\b|\bbundesamt für bevölkerungsschutz\b/u' => [
				'uk' => '/\bbbк\b|\bцивільн.*захист/u',
				'en' => '/\bbbk\b|\bcivil protection\b/u',
			],
			'/\btrump\b/u' => [
				'uk' => '/\bтрамп[ауом]?\b/u',
				'en' => '/\btrump\b/u',
			],
			'/\bnato\b/u' => [
				'uk' => '/\bнато\b/u',
				'en' => '/\bnato\b/u',
			],
			'/\beu\b|\beuropäische union\b/u' => [
				'uk' => '/\bєс\b|\bєвропейськ.*союз/u',
				'en' => '/\beu\b|\beuropean union\b/u',
			],
		];
		foreach ($entityMap as $sourcePattern => $targets) {
			if (preg_match($sourcePattern, $source) !== 1) {
				continue;
			}
			$targetPattern = (string) ($targets[$lang] ?? '');
			if ($targetPattern !== '' && preg_match($targetPattern, $translated) !== 1) {
				return false;
			}
		}
		return true;
	}

	private static function translation_has_semantic_drift(array $base, string $translatedCombined, string $lang): bool {
		$source = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($base['title'] ?? ''),
			(string) ($base['excerpt'] ?? ''),
			wp_strip_all_tags((string) ($base['content'] ?? '')),
		]))));
		$translated = mb_strtolower($translatedCombined);
		$driftGroups = [
			[
				'source' => '/\bprotest|\bdemo|\bdemonstration|\bkundgebung|\benergie|\bstrom|\bgas|\btarif|\bpreise\b/u',
				'uk' => '/\bпротест|\bдемонстрац|\bтариф|\bенерг|\bгаз\b|\bелектроенерг/u',
				'en' => '/\bprotest|\bdemonstration|\btariff|\benergy|\bgas\b|\belectricity\b/u',
			],
			[
				'source' => '/\bbitcoin|\bkrypto|\bcrypto\b/u',
				'uk' => '/\bбіткоїн|\bкрипт/u',
				'en' => '/\bbitcoin|\bcrypto\b/u',
			],
			[
				'source' => '/\btraining|\bübung|\bnotfall|\bemergency|\bdrill|\breaction exercise\b/u',
				'uk' => '/\bтренуван|\bнавчан|\bнавчання|\bреагуван|\bнадзвичайн(і|их|і ситуац)/u',
				'en' => '/\btraining|\bdrill|\bemergency|\bresponse exercise\b/u',
			],
			[
				'source' => '/\bauto|\bfahrzeug|\bemission|\bklima|\bumwelt|\bco2\b/u',
				'uk' => '/\bавтомобіл|\bвикид|\bекологічн|\bклімат/u',
				'en' => '/\bcar\b|\bvehicle\b|\bemission|\becolog|\bclimate\b/u',
			],
		];
		foreach ($driftGroups as $group) {
			$targetPattern = (string) ($group[$lang] ?? '');
			if ($targetPattern === '') {
				continue;
			}
			if (preg_match($targetPattern, $translated) === 1 && preg_match((string) $group['source'], $source) !== 1) {
				return true;
			}
		}
		return false;
	}

	private static function translation_preserves_topic_markers(array $base, string $translatedCombined, string $lang): bool {
		$source = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($base['title'] ?? ''),
			(string) ($base['excerpt'] ?? ''),
			wp_strip_all_tags((string) ($base['content'] ?? '')),
		]))));
		$translated = mb_strtolower($translatedCombined);
		$topicMap = [
			[
				'source' => '/\br[üu]stung|\br[üu]stungstechnologien|\bverteidigungsmarkt|\bdefen[sc]e market\b|\bdefen[sc]e industr/u',
				'uk' => '/\bоборонн|\bозброєн|\bрин(ок|ку)\b|\bоборонн.*технолог/u',
				'en' => '/\bdefen[sc]e\b|\barmament\b|\bweapons\b|\bmarket\b|\bindustry\b/u',
			],
			[
				'source' => '/\bmilliarden\b|\bbillion\b|\bus-dollar\b|\bdollar\b/u',
				'uk' => '/\bмільярд|\bдолар/u',
				'en' => '/\bbillion\b|\bdollar\b/u',
			],
			[
				// Restrict funding/research markers to actual Foerderung terms, not generic "fordert".
				'source' => '/\bf(?:örder|oerder)(?:ung|mittel|programm|gelder|topf|projekt|projekte|linie|bescheid|bescheide|initiative)\b|\bforschungsprojekt|\bforschung|\bnachwuchsgruppen|\bsozialpolitik|\bbundesministerium f[üu]r arbeit\b/u',
				'uk' => '/\bфінансуван|\bгрант|\bдосліджен|\bдослідницьк|\bпроєкт|\bміністерств|\bпідтрим/u',
				'en' => '/\bfunding\b|\bgrant\b|\bresearch\b|\bproject\b|\bministry\b|\bsubsid|\bsupport/u',
			],
		];
		foreach ($topicMap as $group) {
			if (preg_match((string) $group['source'], $source) !== 1) {
				continue;
			}
			$targetPattern = (string) ($group[$lang] ?? '');
			if ($targetPattern !== '' && preg_match($targetPattern, $translated) !== 1) {
				return false;
			}
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
