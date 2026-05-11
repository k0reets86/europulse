<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Collector {
	public static function run_scheduled(bool $force = false): void {
		if (! EPV2_Time_Planner::should_collect($force)) {
			return;
		}
		if (EPV2_Lock_Manager::is_active('collect')) {
			return;
		}
		if (EPV2_Runs::has_recent_started('collect', 300)) {
			return;
		}
		// Backpressure-deferred until timestamp (set by previous over-
		// capacity tick). Если сейчас < deferred_until — выходим, не
		// триггерим should_defer повторно (иначе counter растёт каждый
		// WP-cron tick за 15 мин а physical defer должен быть строго 10).
		if (! $force) {
			$deferred_until = (int) get_option('epv2_collect_deferred_until', 0);
			if ($deferred_until > time()) {
				return;
			}
		}
		// Backpressure: if the queue already holds more pending items than
		// the worker can plausibly drain in the next hour, defer this
		// collect by 10 minutes. Hard ceiling of 30 минут total cumulative
		// defer prevents skipping collects forever если queue stuck.
		if (! $force && self::should_defer_for_backpressure()) {
			return;
		}
		EPV2_Lock_Manager::cleanup();
		EPV2_Queue::normalize_non_active_recoverable_items();
		$collect_lock_ttl = max(300, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
		$lock = EPV2_Lock_Manager::acquire('collect', $collect_lock_ttl, [
			'stale_after' => max(900, min(3600, $collect_lock_ttl)),
		]);
		if ($lock === null) {
			return;
		}
		EPV2_Queue::prune_stale((int) EPV2_Settings::get('queue_retention_days', 3));
		EPV2_Queue::prune_rejected(1440);
		EPV2_Queue::prune_new_stale((int) EPV2_Settings::get('queue_new_ttl_hours', 18));
		EPV2_Trends::refresh($force);
		$sources = EPV2_Sources::all(true);
		// Source-diversity: внутри одной priority группы порядок shuffle'ится
		// каждый collect cycle. Без этого источники с поздним alphabet ordering
		// (ZEIT, ZDF, Tagesspiegel) ВСЕГДА проигрывали dup-precheck гонку
		// раньше-staged'нутым (Süddeutsche, Spiegel, FAZ). Random в priority-
		// bucket даёт всем top-tier источникам равный шанс зайти первыми.
		$sources = self::shuffle_sources_within_priority((array) $sources);
		$run = EPV2_Runs::start('collect', ['total_sources' => count($sources)]);
		$count = 0;
		$errors = 0;
		$by_category = [];
		$staged_candidates = [];
		$progress = [
			'status' => 'running',
			'total_sources' => count($sources),
			'processed_sources' => 0,
			'current_source' => '',
			'collected_items' => 0,
			'errors' => 0,
			'updated_at' => current_time('mysql'),
			'updated_at_ts' => time(),
		];
		update_option('epv2_collect_progress', $progress, false);
		$run_finished = false;

		try {
		foreach ($sources as $index => $source) {
			EPV2_Lock_Manager::heartbeat('collect', $lock, $collect_lock_ttl);
			if (EPV2_Resilience_Manager::source_on_cooldown((int) $source->id)) {
				$progress['processed_sources'] = $index + 1;
				$progress['updated_at'] = current_time('mysql');
				$progress['updated_at_ts'] = time();
				update_option('epv2_collect_progress', $progress, false);
				continue;
			}
			try {
				$progress['current_source'] = (string) $source->name;
				$items = self::collect_source($source);
				$items = self::trim_to_recent_per_source($items, $source);
				foreach ($items as $item) {
					self::stage_candidate($item, $source, $staged_candidates);
				}
				EPV2_Sources::update_fetch((int) $source->id, $count, null);
				EPV2_Resilience_Manager::register_source_success((int) $source->id);
			} catch (Throwable $e) {
				$errors++;
				$human = EPV2_Resilience_Manager::humanize_error($e->getMessage());
				EPV2_Sources::update_fetch((int) $source->id, 0, $human);
				EPV2_Resilience_Manager::register_source_failure((int) $source->id, $e->getMessage());
				EPV2_Logger::error('collector', 'Source failed', ['source_id' => (int) $source->id, 'error' => $human]);
			}
			$progress['processed_sources'] = $index + 1;
			$progress['collected_items'] = $count;
			$progress['errors'] = $errors;
			$progress['updated_at'] = current_time('mysql');
			$progress['updated_at_ts'] = time();
			update_option('epv2_collect_progress', $progress, false);
		}

		$created_story_candidates = EPV2_Story_Clusters::create_story_candidates();
		foreach (EPV2_Trends::candidate_items() as $trend_item) {
			$trend_source = (object) [
				'id' => 0,
				'category_bias' => EPV2_Categorizer::detect((string) ($trend_item['title'] ?? ''), (string) ($trend_item['content'] ?? ''), 'deutschland'),
				'priority' => 6,
				'risk_level' => 'low',
				'name' => 'Google Trends',
			];
			self::stage_candidate($trend_item, $trend_source, $staged_candidates);
		}
		$count = self::commit_staged_candidates($staged_candidates, $by_category);
		EPV2_Stats::bump('collected', $count);
		$trimmed_new = EPV2_Queue::trim_new_queue(
			self::effective_queue_new_max_per_category(),
			(int) EPV2_Settings::get('queue_new_max_per_source', 10)
		);
		$progress['status'] = $errors ? 'finished_with_errors' : 'finished';
		$progress['current_source'] = '';
		$progress['collected_items'] = $count;
		$progress['updated_at'] = current_time('mysql');
		$progress['updated_at_ts'] = time();
		update_option('epv2_collect_progress', $progress, false);
		EPV2_Runs::finish($run, $errors ? 'finished_with_errors' : 'finished', $count, $errors, [
			'total_sources' => count($sources),
			'processed_sources' => count($sources),
			'collected_items' => $count,
			'story_candidates_created' => $created_story_candidates,
			'trimmed_new' => $trimmed_new,
			'errors' => $errors,
			'by_category' => $by_category,
		]);
		$run_finished = true;
		if (EPV2_Queue::has_processable_items()) {
			EPV2_Jobs::enqueue_process();
		}
		} finally {
			if (! $run_finished) {
				$progress['status'] = 'finished_with_errors';
				$progress['current_source'] = '';
				$progress['errors'] = max(1, (int) ($progress['errors'] ?? 0));
				$progress['updated_at'] = current_time('mysql');
				$progress['updated_at_ts'] = time();
				update_option('epv2_collect_progress', $progress, false);
				EPV2_Runs::finish($run, 'finished_with_errors', $count, max(1, $errors), [
					'total_sources' => count($sources),
					'processed_sources' => (int) ($progress['processed_sources'] ?? 0),
					'collected_items' => $count,
					'errors' => max(1, $errors),
					'result' => 'collector_unexpected_exit_recovered',
				]);
			}
			EPV2_Lock_Manager::release('collect', $lock);
		}
	}

	public static function collect_source(object $source): array {
		$type = (string) $source->type;
		// Phase 3: is_aggregator flag generalises the Google-News-specific
		// path. Any source that wraps third-party content in an RSS stub
		// (Google News today; Bing News / Yahoo News / RSS-bridges in the
		// future) carries is_aggregator=1 and follows the same flow:
		//   1. fetch the wrapper RSS;
		//   2. hand individual items downstream where the URL gets resolved
		//      to the real publisher (handled in stage_candidate / story
		//      card pipeline);
		//   3. when resolution fails, the worker's
		//      _search_supporting_sources_rich() finds the same topic on
		//      other publishers (phase 2.7).
		// Right now only Google News has wrapper-specific URL syntax we
		// need to massage (when:14d freshness filter), so that branch
		// stays GN-syntax-bound, but the flag is what selects the path.
		$is_aggregator = ! empty($source->is_aggregator) || $type === 'google_news';
		if (in_array($type, ['rss', 'atom'], true) && ! $is_aggregator) {
			return EPV2_Feed_Reader::fetch((string) $source->url, false);
		}
		if ($is_aggregator) {
			$feed_url = (string) $source->url;
			if ($type === 'google_news' && class_exists('EPV2_Google_News') && method_exists('EPV2_Google_News', 'ensure_recent_filter')) {
				// GN-specific: inject when:14d so we don't re-ingest items
				// that are thousands of hours old.
				$feed_url = EPV2_Google_News::ensure_recent_filter($feed_url, 14);
			}
			return EPV2_Feed_Reader::fetch($feed_url, true);
		}
		if ($type === 'scrape') {
			$rules = json_decode((string) ($source->parse_rules ?? ''), true);
			$rules = is_array($rules) ? $rules : [];
			if (($rules['mode'] ?? '') === 'single_page') {
				$doc = EPV2_HTML_Reader::fetch_document((string) $source->url);
				return [[
					'title' => (string) ($doc['title'] ?? ''),
					'url' => (string) ($doc['url'] ?? $source->url),
					'content' => (string) ($doc['content'] ?? ''),
					'excerpt' => (string) ($doc['excerpt'] ?? ''),
					'date' => '',
					'author' => '',
					'image' => (string) ($doc['image'] ?? ''),
				]];
			}
			return EPV2_HTML_Reader::fetch_listing((string) $source->url, 12, $rules);
		}
		if (in_array($type, ['telegram', 'facebook'], true)) {
			$rules = json_decode((string) ($source->parse_rules ?? ''), true);
			$rules = is_array($rules) ? $rules : [];
			return EPV2_Social_Reader::fetch($type, (string) $source->url, 12, $rules);
		}
		return [];
	}

	/**
	 * Backpressure: skip this collect if the queue's pending pool is
	 * larger than the worker's effective hourly throughput. We keep a
	 * cumulative-defer counter in the wp_options so the collector
	 * doesn't loop indefinitely — after 30 minutes of total deferral
	 * we collect anyway and reset the counter (the operator probably
	 * has a stuck row to investigate; new freshness shouldn't suffer
	 * forever just because one row got jammed).
	 */
	private static function should_defer_for_backpressure(): bool {
		global $wpdb;
		$pending = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}epv2_queue
			 WHERE state IN ('new', 'retry_process', 'processing_de')"
		);
		// Effective hourly capacity — measured from real publishing
		// throughput in the last hour, with a floor based on the
		// configured publish slot interval (default 5 min → 12/hr).
		$published_last_hour = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}epv2_queue
			 WHERE state = 'published' AND updated_at >= %s",
			gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)
		));
		$publish_interval = max(3, (int) EPV2_Settings::get('publish_interval_minutes', 5));
		$capacity_floor = max(6, (int) floor(60 / $publish_interval));
		$capacity = max($capacity_floor, $published_last_hour);
		// Pending pool > capacity × 1.5 means even if we publish at
		// max rate every slot for the next 60 min we still won't
		// drain. Defer.
		$threshold = (int) ceil($capacity * 1.5);
		if ($pending <= $threshold) {
			delete_option('epv2_collect_backpressure_total_seconds');
			delete_option('epv2_collect_deferred_until');
			return false;
		}
		$deferred_total = (int) get_option('epv2_collect_backpressure_total_seconds', 0);
		// Escape hatch для slight overload only. Если pending в пределах
		// 1-3× threshold — после 30 мин cumulative defer можно force-run
		// (вероятно один stuck row, не overload). Но если pending МАССОВО
		// over threshold (>3×), force-run только усугубит — backlog никогда
		// не дренируется. Backlog нужно clear'ить через quarantine handler
		// (catches stuck items via workflow_step_attempts) или operator
		// intervention, не через игнорирование backpressure.
		// Operator-feedback 2026-05-11: «мы договаривались, что там будет
		// срабатывать защита» — escape hatch нарушал contract.
		if ($deferred_total >= 30 * MINUTE_IN_SECONDS && $pending <= $threshold * 3) {
			// Slight overload + 30 min waited. Probably one stuck row.
			// Force run, reset counters.
			delete_option('epv2_collect_backpressure_total_seconds');
			delete_option('epv2_collect_deferred_until');
			return false;
		}
		// Physical defer: next attempt НЕ ранее чем now+10 мин. Это
		// гарантирует что pipeline получит реальное окно на обработку
		// backlog, а не просто skip'ает текущий tick (WP-cron всё равно
		// fires every 15 мин — без deferred_until мы бы skipped только
		// этот один tick, не давая backlog'у драенуться).
		$defer_seconds = 10 * MINUTE_IN_SECONDS;
		update_option(
			'epv2_collect_deferred_until',
			time() + $defer_seconds,
			false
		);
		update_option(
			'epv2_collect_backpressure_total_seconds',
			$deferred_total + $defer_seconds,
			false
		);
		update_option(
			'epv2_collect_backpressure_last',
			[
				'pending' => $pending,
				'capacity' => $capacity,
				'threshold' => $threshold,
				'deferred_total_seconds' => $deferred_total + $defer_seconds,
				'deferred_until' => gmdate('Y-m-d H:i:s', time() + $defer_seconds),
				'at' => current_time('mysql'),
			],
			false
		);
		return true;
	}

	private static function automation_requires_publish_grade(): bool {
		$mode = (string) EPV2_Settings::get('mode', 'semi');
		$default_status = (string) EPV2_Settings::get('default_post_status', 'draft');
		return $mode === 'auto' && in_array($default_status, ['publish', 'pending'], true);
	}

	private static function stage_candidate(array $item, object $source, array &$staged_candidates): void {
		$source_category = (string) $source->category_bias;
		$duplicate = EPV2_Deduplicator::is_duplicate((string) ($item['title'] ?? ''), (string) ($item['content'] ?? ''), (string) ($item['url'] ?? ''));
		if (! empty($duplicate['duplicate'])) {
			EPV2_Stats::bump('duplicates');
			self::audit_candidate($item, $source, [], 'stage', 'duplicate_precheck', ['duplicate' => $duplicate]);
			// Sibling-enrichment: вместо простого drop'а регистрируем
			// дропнутый кандидат как «sibling source» на existing item /
			// post. Его URL / source / publication можно потом использовать
			// в dossier'е как подтверждение от другого редактора. Для
			// ZEIT/ZDF/ARD которые проигрывают первенство Süddeutsche/FAZ
			// это сохраняет их вклад в attribution.
			self::register_sibling_source(
				(int) ($duplicate['duplicate_of'] ?? 0),
				(string) ($duplicate['reason'] ?? ''),
				$item,
				$source
			);
			return;
		}

		$item['source_id'] = (int) ($source->id ?? 0);
		$item['category'] = $source_category;
		$analysis = EPV2_Budget_Manager::analyze_item($item, $source);
		$category = sanitize_text_field((string) ($analysis['category'] ?? $source_category));
		$item['category'] = $category !== '' ? $category : $source_category;
		if ($category === '' || ($analysis['decision'] ?? '') === 'reject') {
			self::audit_candidate($item, $source, $analysis, 'stage', 'selection_reject');
			return;
		}
		if (self::candidate_has_hard_editorial_block($item, $source, $analysis)) {
			self::audit_candidate($item, $source, $analysis, 'stage', 'hard_editorial_block');
			return;
		}
		if (! self::candidate_is_fresh_enough($item, $analysis, $source)) {
			self::audit_candidate($item, $source, $analysis, 'stage', 'freshness_block');
			return;
		}
		$active_load = EPV2_Queue::category_load(['new', 'processing_de', 'retry_process', 'ready_publish', 'publishing']);
		if ((int) ($active_load[$category] ?? 0) >= self::effective_queue_new_max_per_category()) {
			self::audit_candidate($item, $source, $analysis, 'stage', 'category_active_load_cap', [
				'active_load' => (int) ($active_load[$category] ?? 0),
				'queue_limit' => self::effective_queue_new_max_per_category(),
			]);
			return;
		}

		$ai_gate = EPV2_Budget_Manager::should_send_to_ai($analysis);
		if (
			self::automation_requires_publish_grade()
			&& ! in_array((string) ($analysis['decision'] ?? ''), ['review', 'strong', 'priority'], true)
		) {
			self::audit_candidate($item, $source, $analysis, 'stage', 'not_publish_grade', ['ai_gate' => $ai_gate]);
			return;
		}
		if (
			self::automation_requires_publish_grade()
			&& empty($ai_gate['allow'])
			&& (string) ($ai_gate['reason'] ?? '') !== 'достигнут AI-бюджет дня'
		) {
			self::audit_candidate($item, $source, $analysis, 'stage', 'ai_gate_block', [
				'ai_gate' => $ai_gate,
				'gate_reason' => (string) ($ai_gate['reason'] ?? ''),
			]);
			return;
		}
		$queue_gate = EPV2_Budget_Manager::should_keep_in_queue($analysis);
		if (empty($queue_gate['keep'])) {
			self::audit_candidate($item, $source, $analysis, 'stage', 'queue_gate_block', [
				'queue_gate' => $queue_gate,
				'gate_reason' => (string) ($queue_gate['reason'] ?? ''),
			]);
			return;
		}
		$planner = EPV2_Category_Planner::decide_for_candidate($category, (int) ($analysis['score'] ?? 0), $analysis);
		if (($planner['action'] ?? '') === 'reject') {
			self::audit_candidate($item, $source, $analysis, 'stage', 'planner_reject', [
				'planner' => $planner,
				'planner_reason' => (string) ($planner['reason'] ?? ''),
			]);
			return;
		}

		$staged_candidates[$category] = $staged_candidates[$category] ?? [];
		$staged_candidates[$category][] = [
			'item' => $item,
			'source' => $source,
			'analysis' => $analysis,
			'score' => self::candidate_rank_score($item, $source, $analysis),
		];
		self::audit_candidate($item, $source, $analysis, 'stage', 'staged_candidate', [
			'ai_gate' => $ai_gate,
			'planner' => $planner,
		]);
	}

	private static function commit_staged_candidates(array $staged_candidates, array &$by_category): int {
		$count = 0;
		$collect_limit = self::effective_collect_per_category_limit();
		// Per-hour rolling cap (replaces per-collect cap). Independence from
		// collect frequency — operator может менять collect_interval_minutes
		// без перекоса в распределении источников по рубрикам. Считаем
		// сколько уже взяли с этого source_id в эту рубрику за последний
		// час (created_at > now-1h, любые состояния включая rejected).
		// Top-tier (priority >= 9): 3/час в одну рубрику.
		// Остальные:               2/час в одну рубрику.
		// breaking/top-story:       без капа.
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$existing_per_source_rubric = [];
		$rows = $wpdb->get_results(
			"SELECT source_id, category_proposed, COUNT(*) c
			 FROM {$table}
			 WHERE source_id > 0
			   AND created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
			   AND state NOT IN ('duplicate','rejected','error')
			 GROUP BY source_id, category_proposed"
		);
		foreach ((array) $rows as $r) {
			$key = (int) $r->source_id . '|' . (string) $r->category_proposed;
			$existing_per_source_rubric[$key] = (int) $r->c;
		}

		foreach ($staged_candidates as $category => $candidates) {
			if ((string) $category === '' || $candidates === []) {
				continue;
			}
			usort($candidates, static fn(array $a, array $b): int => (int) $b['score'] <=> (int) $a['score']);
			foreach ($candidates as $candidate) {
				$by_category[$category] = $by_category[$category] ?? 0;
				if ((int) $by_category[$category] >= $collect_limit) {
					break;
				}
				$source_obj = (object) ($candidate['source'] ?? new stdClass());
				$source_id = (int) ($source_obj->id ?? 0);
				$analysis = (array) ($candidate['analysis'] ?? []);
				$is_top_news = ! empty($analysis['breaking_candidate'])
					|| ! empty($analysis['top_story_candidate'])
					|| (string) ($analysis['decision'] ?? '') === 'priority';
				if (! $is_top_news && $source_id > 0) {
					$priority = (int) ($source_obj->priority ?? 5);
					$per_hour_cap = $priority >= 9 ? 3 : 2;
					$key = $source_id . '|' . (string) $category;
					$already = (int) ($existing_per_source_rubric[$key] ?? 0);
					if ($already >= $per_hour_cap) {
						// Cap-hit logging (2026-05-10): silently filtered раньше,
						// сейчас audit'им чтобы оператор видел почему items не
						// queued от данного источника в этой рубрике.
						self::audit_candidate(
							(array) $candidate['item'], $source_obj, $analysis,
							'ingest', 'per_source_per_rubric_cap',
							[
								'source_id' => $source_id,
								'priority' => $priority,
								'category' => (string) $category,
								'already_in_hour' => $already,
								'cap' => $per_hour_cap,
							]
						);
						continue;
					}
				}
				if (self::ingest_candidate((array) $candidate['item'], $source_obj, $by_category)) {
					$count++;
					if (! $is_top_news && $source_id > 0) {
						$key = $source_id . '|' . (string) $category;
						$existing_per_source_rubric[$key] = ($existing_per_source_rubric[$key] ?? 0) + 1;
					}
					continue;
				}
			}
		}
		return $count;
	}

	private static function candidate_rank_score(array $item, object $source, array $analysis): int {
		$score = ((int) ($analysis['score'] ?? 0)) * 10;
		$score += ! empty($analysis['breaking_candidate']) ? 250 : 0;
		$score += ! empty($analysis['top_story_candidate']) ? 180 : 0;
		$score += (string) ($analysis['decision'] ?? '') === 'priority' ? 160 : 0;
		$score += min(80, max(0, (int) ($source->priority ?? 0) * 10));
		$ts = strtotime((string) ($item['date'] ?? '')) ?: 0;
		if ($ts > 0) {
			$age_hours = max(0, (time() - $ts) / HOUR_IN_SECONDS);
			$score += max(0, 80 - (int) round($age_hours * 10));
		}
		return $score;
	}

	private static function ingest_candidate(array $item, object $source, array &$by_category): bool {
		$source_category = (string) $source->category_bias;
		$duplicate = EPV2_Deduplicator::is_duplicate((string) ($item['title'] ?? ''), (string) ($item['content'] ?? ''), (string) ($item['url'] ?? ''));
		if (! empty($duplicate['duplicate'])) {
			EPV2_Stats::bump('duplicates');
			self::audit_candidate($item, $source, [], 'ingest', 'duplicate_precheck', ['duplicate' => $duplicate]);
			return false;
		}
		$item['source_id'] = (int) ($source->id ?? 0);
		$item['category'] = $source_category;
		$analysis = EPV2_Budget_Manager::analyze_item($item, $source);
		$category = sanitize_text_field((string) ($analysis['category'] ?? $source_category));
		$item['category'] = $category !== '' ? $category : $source_category;
		// Story Card primacy — AI voice BEFORE pre-AI rejection. When the
		// score-only heuristic says decision ∈ {low, reject}, ask the AI
		// directly (Story Card editorial_match) before dropping the item.
		// If editorial_match='match' AND publishable_estimate='high', the
		// score is wrong (heuristic missed the topic value); we upgrade
		// decision to 'review' so the item gets a fair AI rebuild.
		// Cost: ~$0.001 per item that would otherwise be rejected.
		$story_card = [];
		$pre_reject_decision = (string) ($analysis['decision'] ?? '');
		if (
			class_exists('EPV2_Story_Card_Builder')
			&& self::automation_requires_publish_grade()
			&& in_array($pre_reject_decision, ['low', 'reject'], true)
		) {
			$story_card = EPV2_Story_Card_Builder::build_from_array($item);
			if (! empty($story_card['success'])) {
				$ed_match = strtolower(trim((string) ($story_card['editorial_match'] ?? '')));
				$est = strtolower(trim((string) ($story_card['publishable_estimate'] ?? '')));
				if ($ed_match === 'match' && $est === 'high') {
					$analysis['decision'] = 'review';
					$analysis['reasons'][] = 'story_card_upvote: editorial=match, estimate=high (was: ' . $pre_reject_decision . ')';
					self::audit_candidate($item, $source, $analysis, 'ingest', 'story_card_upvote', [
						'editorial_match' => $ed_match,
						'estimate' => $est,
						'reason' => (string) ($story_card['editorial_reason'] ?? ''),
						'previous_decision' => $pre_reject_decision,
					]);
				}
			}
		}
		if (($analysis['decision'] ?? '') === 'reject') {
			self::audit_candidate($item, $source, $analysis, 'ingest', 'selection_reject');
			return false;
		}
		if (self::candidate_has_hard_editorial_block($item, $source, $analysis)) {
			self::audit_candidate($item, $source, $analysis, 'ingest', 'hard_editorial_block');
			return false;
		}
		if (! self::candidate_is_fresh_enough($item, $analysis, $source)) {
			self::audit_candidate($item, $source, $analysis, 'ingest', 'freshness_block');
			return false;
		}
		$active_load = EPV2_Queue::category_load(['new', 'processing_de', 'retry_process', 'ready_publish', 'publishing']);
		$category = sanitize_text_field((string) ($item['category'] ?? ''));
		$by_category[$category] = $by_category[$category] ?? 0;
		$collect_limit = self::effective_collect_per_category_limit();
		$queue_limit = self::effective_queue_new_max_per_category();
		if ($category !== '' && $by_category[$category] >= $collect_limit) {
			self::audit_candidate($item, $source, $analysis, 'ingest', 'collect_category_cap', [
				'by_category' => (int) $by_category[$category],
				'collect_limit' => $collect_limit,
			]);
			return false;
		}
		if ($category !== '' && (int) ($active_load[$category] ?? 0) >= $queue_limit) {
			self::audit_candidate($item, $source, $analysis, 'ingest', 'category_active_load_cap', [
				'active_load' => (int) ($active_load[$category] ?? 0),
				'queue_limit' => $queue_limit,
			]);
			return false;
		}
		$ai_gate = EPV2_Budget_Manager::should_send_to_ai($analysis);
		if (
			self::automation_requires_publish_grade()
			&& ! in_array((string) ($analysis['decision'] ?? ''), ['review', 'strong', 'priority'], true)
		) {
			self::audit_candidate($item, $source, $analysis, 'ingest', 'not_publish_grade', ['ai_gate' => $ai_gate]);
			return false;
		}
		if (
			self::automation_requires_publish_grade()
			&& empty($ai_gate['allow'])
			&& (string) ($ai_gate['reason'] ?? '') !== 'достигнут AI-бюджет дня'
		) {
			self::audit_candidate($item, $source, $analysis, 'ingest', 'ai_gate_block', [
				'ai_gate' => $ai_gate,
				'gate_reason' => (string) ($ai_gate['reason'] ?? ''),
			]);
			return false;
		}
		$queue_gate = EPV2_Budget_Manager::should_keep_in_queue($analysis);
		if (empty($queue_gate['keep'])) {
			self::audit_candidate($item, $source, $analysis, 'ingest', 'queue_gate_block', [
				'queue_gate' => $queue_gate,
				'gate_reason' => (string) ($queue_gate['reason'] ?? ''),
			]);
			return false;
		}
		// Hub / index / topic-thread page filter — runs BEFORE we spend AI
		// tokens on items like kyivpost.com/thread/X "Top Stories and Live
		// Updates" landing pages. These have no factual content to rewrite,
		// just promo-text about the publisher's coverage.
		$meta_index_reason = EPV2_Content_Filters::detect_meta_index_page(
			(string) ($item['original_url'] ?? ''),
			(string) ($item['original_title'] ?? ''),
			(string) ($item['original_excerpt'] ?? ''),
			(string) ($item['original_content'] ?? '')
		);
		if ($meta_index_reason !== '') {
			self::audit_candidate($item, $source, $analysis, 'ingest', 'meta_index_page', [
				'reason' => $meta_index_reason,
			]);
			return false;
		}
		$planner = EPV2_Category_Planner::decide_for_candidate($category, (int) ($analysis['score'] ?? 0), $analysis);
		if (($planner['action'] ?? '') === 'reject') {
			self::audit_candidate($item, $source, $analysis, 'ingest', 'planner_reject', [
				'planner' => $planner,
				'planner_reason' => (string) ($planner['reason'] ?? ''),
			]);
			return false;
		}
		$cluster = EPV2_Story_Clusters::register_candidate($item, $source);
		$story_duplicate = EPV2_Deduplicator::is_story_duplicate($item, $cluster);
		if (! empty($story_duplicate['duplicate'])) {
			EPV2_Stats::bump('duplicates');
			self::audit_candidate($item, $source, $analysis, 'ingest', 'story_duplicate', [
				'duplicate' => $story_duplicate,
				'cluster' => $cluster,
			]);
			return false;
		}
		$event_key = sanitize_title((string) ($story_duplicate['event_key'] ?? EPV2_Deduplicator::event_key_for_candidate($item, $cluster)));

		// Smart event-signature dedup на ingest: вместо crude hash или
		// heuristic event_key — используем Story Card AI (entities + event
		// keyword + date_day) для семантического distinguish'а двух статей
		// про того же entity (Putin parade vs Putin meeting Zelensky).
		// Story Card строится один раз здесь и сохраняется в payload — AI
		// processor потом не пересчитывает (см. class-epv2-ai-processor.php
		// line 112-114). Cost: ~1.5K tokens per ingested item ≈ $0.001.
		$ai_payload_seed = ['_meta' => []];
		if (class_exists('EPV2_Story_Card_Builder')) {
			// Reuse the card we may have built earlier for the up-vote check;
			// otherwise build fresh now (cost: ~$0.001 per item).
			if (empty($story_card['success'])) {
				$story_card = EPV2_Story_Card_Builder::build_from_array($item);
			}
			if (! empty($story_card['success'])) {
				$ai_payload_seed = EPV2_Story_Card_Builder::attach_to_payload($ai_payload_seed, $story_card);
				// Editorial signal от Story Card: «reject_low_value» = AI говорит
				// что материал не публикуем. Раньше это поле игнорировалось,
				// решения принимал только Budget Manager по score.
				$editorial_match = (string) ($story_card['editorial_match'] ?? '');
				if ($editorial_match === 'reject_low_value') {
					self::audit_candidate($item, $source, $analysis, 'ingest', 'story_card_editorial_reject', [
						'reason' => (string) ($story_card['editorial_reason'] ?? ''),
						'estimate' => (string) ($story_card['publishable_estimate'] ?? ''),
					]);
					return false;
				}
				// Семантический event-signature dedup. current_id=0 (item ещё
				// не save'нут), функция skip'ает self-check.
				$event_dup = EPV2_Deduplicator::is_event_duplicate(0, $story_card, $ai_payload_seed);
				if (! empty($event_dup['duplicate'])) {
					self::audit_candidate($item, $source, $analysis, 'ingest', 'event_signature_duplicate', [
						'duplicate_of' => (int) ($event_dup['duplicate_of'] ?? 0),
						'reason' => (string) ($event_dup['reason'] ?? ''),
						'age_seconds' => (int) ($event_dup['age_seconds'] ?? 0),
						'signature' => (string) ($event_dup['signature'] ?? ''),
					]);
					return false;
				}
			}
		}

		$item['cluster_id'] = (int) ($cluster['id'] ?? 0);
		$item['topic_label'] = (string) ($cluster['topic_label'] ?? '');
		$item['story_score'] = (int) ($analysis['score'] ?? 0);
		$item['story_format'] = '';
		$item['state'] = 'new';
		$item['admin_notes'] = wp_json_encode(['selection' => $analysis, 'ai_gate' => $ai_gate, 'cluster' => $cluster, 'planner' => $planner, 'event_key' => $event_key], JSON_UNESCAPED_UNICODE);
		// Сохраняем Story Card в ai_payload — AI processor его reuse'нёт
		// без повторного AI call (saves ~1.5K tokens на pickup).
		// add_item() читает $item['payload'] (array) — не 'ai_payload' (string).
		if (! empty($story_card['success'])) {
			$item['payload'] = $ai_payload_seed;
		}
		$item_id = EPV2_Queue::add_item($item);
		self::audit_candidate($item, $source, $analysis, 'ingest', $item_id > 0 ? 'queued' : 'queue_insert_failed', [
			'ai_gate' => $ai_gate,
			'planner' => $planner,
			'cluster_id' => (int) ($cluster['id'] ?? 0),
			'event_key' => $event_key,
		], $item_id);
		if ($item_id > 0 && ! empty($cluster['id'])) {
			$cluster = EPV2_Story_Clusters::refresh_cluster_metrics((int) $cluster['id']);
			EPV2_Queue::update_fields($item_id, [
				'admin_notes' => wp_json_encode(['selection' => $analysis, 'ai_gate' => $ai_gate, 'cluster' => $cluster, 'planner' => $planner, 'event_key' => $event_key], JSON_UNESCAPED_UNICODE),
			]);
		}
		if ($category !== '') {
			$by_category[$category]++;
		}
		return $item_id > 0;
	}

	/**
	 * Запись sibling-source на existing target после dup-precheck отказа.
	 *
	 * При dup-precheck'е target_id может ссылаться либо на active queue row
	 * (state IN new/processing/...), либо на ID published WP post (через
	 * reason=published_url/published_semantic/published_source_url).
	 * В обоих случаях добавляем sibling в meta-поле для последующего
	 * использования в dossier'е enrichment'а: title, url, source name,
	 * source priority, reason, fetched_at.
	 *
	 * Сохраняем максимум 8 siblings (FIFO) — больше не имеет смысла,
	 * dossier ограничен по токенам.
	 */
	private static function register_sibling_source(int $target_id, string $dup_reason, array $item, object $source): void {
		if ($target_id <= 0) {
			return;
		}
		$candidate = [
			'title' => mb_substr((string) ($item['title'] ?? ''), 0, 250),
			'url' => (string) ($item['url'] ?? ''),
			'source_name' => (string) ($source->name ?? ''),
			'source_id' => (int) ($source->id ?? 0),
			'priority' => (int) ($source->priority ?? 5),
			'language' => (string) ($source->language ?? ''),
			'reason' => $dup_reason,
			'recorded_at' => gmdate('Y-m-d H:i:s'),
		];
		if ($candidate['url'] === '') {
			return;
		}
		// active-queue target: пишем в ai_payload._meta.dropped_siblings[]
		// (для аудита) И в _meta.source_dossier.related[] (worker уже
		// читает related для построения rewriter dossier'а — sibling
		// автоматически попадает в синтез без изменений в Python).
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT id, ai_payload FROM {$wpdb->prefix}epv2_queue WHERE id=%d",
			$target_id
		));
		if ($row) {
			$payload = json_decode((string) $row->ai_payload, true);
			$payload = is_array($payload) ? $payload : [];
			if (! is_array($payload['_meta'] ?? null)) {
				$payload['_meta'] = [];
			}
			$changed = false;

			// 1) dropped_siblings — audit-trail
			$siblings = is_array($payload['_meta']['dropped_siblings'] ?? null) ? $payload['_meta']['dropped_siblings'] : [];
			$seen = [];
			foreach ($siblings as $existing) {
				$seen[(string) ($existing['url'] ?? '')] = true;
			}
			if (! isset($seen[$candidate['url']])) {
				$siblings[] = $candidate;
				$siblings = array_slice($siblings, -8);
				$payload['_meta']['dropped_siblings'] = $siblings;
				$changed = true;
			}

			// 2) source_dossier.related[] — что worker реально потребляет
			if (! is_array($payload['_meta']['source_dossier'] ?? null)) {
				$payload['_meta']['source_dossier'] = [];
			}
			$related = is_array($payload['_meta']['source_dossier']['related'] ?? null)
				? $payload['_meta']['source_dossier']['related'] : [];
			$rseen = [];
			foreach ($related as $existing) {
				$rseen[(string) ($existing['url'] ?? '')] = true;
			}
			if (! isset($rseen[$candidate['url']])) {
				$domain = '';
				if ($candidate['url'] !== '') {
					$h = wp_parse_url($candidate['url'], PHP_URL_HOST);
					$domain = is_string($h) ? preg_replace('/^www\./', '', $h) : '';
				}
				$related[] = [
					'title' => $candidate['title'],
					'url' => $candidate['url'],
					'domain' => $domain,
					'source_name' => $candidate['source_name'],
					'priority' => $candidate['priority'],
				];
				$related = array_slice($related, -8);
				$payload['_meta']['source_dossier']['related'] = $related;
				$changed = true;
			}

			if ($changed) {
				$wpdb->update(
					$wpdb->prefix . 'epv2_queue',
					['ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE)],
					['id' => $target_id]
				);
			}
			return;
		}
		// published-post target: _epv2_dropped_siblings post_meta
		$post = get_post($target_id);
		if ($post) {
			$siblings = get_post_meta($target_id, '_epv2_dropped_siblings', true);
			$siblings = is_array($siblings) ? $siblings : [];
			$seen = [];
			foreach ($siblings as $existing) {
				$seen[(string) ($existing['url'] ?? '')] = true;
			}
			if (! isset($seen[$candidate['url']])) {
				$siblings[] = $candidate;
				$siblings = array_slice($siblings, -8);
				update_post_meta($target_id, '_epv2_dropped_siblings', $siblings);
			}
		}
	}


	/**
	 * Shuffle sources внутри одинаковой priority. Public API остаётся
	 * priority DESC (top-tier раньше regular), но в одном бакете порядок
	 * рандомизируется каждый collect — это даёт честное распределение
	 * первенства в dedup-precheck гонке между равноценными источниками.
	 */
	private static function shuffle_sources_within_priority(array $sources): array {
		if (count($sources) < 2) {
			return $sources;
		}
		$buckets = [];
		foreach ($sources as $s) {
			$prio = (int) ($s->priority ?? 5);
			$buckets[$prio] = $buckets[$prio] ?? [];
			$buckets[$prio][] = $s;
		}
		krsort($buckets); // priority DESC
		$out = [];
		foreach ($buckets as $bucket) {
			if (count($bucket) > 1) {
				shuffle($bucket);
			}
			foreach ($bucket as $s) {
				$out[] = $s;
			}
		}
		return $out;
	}


	private static function audit_candidate(array $item, object $source, array $analysis, string $phase, string $outcome, array $context = [], int $queue_id = 0): void {
		if (! class_exists('EPV2_Selection_Audit')) {
			return;
		}
		try {
			EPV2_Selection_Audit::record_candidate($item, $source, $analysis, $phase, $outcome, $context, $queue_id);
		} catch (Throwable $e) {
			EPV2_Logger::warning('selection_audit', 'Selection audit write failed', [
				'outcome' => $outcome,
				'error' => $e->getMessage(),
			]);
		}
	}

	private static function candidate_has_hard_editorial_block(array $item, object $source, array $analysis): bool {
		if (! empty($analysis['breaking_candidate']) || ! empty($analysis['top_story_candidate']) || (string) ($analysis['decision'] ?? '') === 'priority') {
			return false;
		}
		$text = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($item['title'] ?? ''),
			(string) ($item['excerpt'] ?? ''),
			wp_strip_all_tags((string) ($item['content'] ?? '')),
			(string) ($item['url'] ?? ''),
		]))));
		if ($text === '') {
			return true;
		}

		if (preg_match('/[єіїґ]{2,}.*\b(open for business|business|planning)\b/iu', $text) === 1) {
			return true;
		}

		// Fix A: core_geo expanded with international hubs that the
		// previous list ignored. Without these, every Trump-Hormuz /
		// Israel-Iran / China-Taiwan story got hard-blocked because it
		// contained a US/biotech token but no German/EU/Russia/Ukraine
		// term. Now any major geopolitical region counts as core.
		$core_geo = preg_match(
			'/\b('
			. 'deutschland|bundes|berlin|bayern|m[üu]nchen|europa|eu\b|europe|'
			. 'ukraine|ukrain|україн|украин|russland|russian|moskau|kreml|'
			. 'br[üu]ssel|nato|g7|g20|opec|'
			. 'iran|iranian|israel|israeli|gaza|nahost|middle\s+east|hormuz|hormus|persian\s+gulf|'
			. 'china|chinese|beijing|peking|taiwan|taiwanese|nordkorea|south\s+korea|'
			. 'syrien|syrian|irak|iraq|libanon|lebanon|t[üu]rkei|turkey|erdogan|'
			. 'belarus|wei[ßs]russland|polen|poland|tschechien|czech|slowakei|slovakia|'
			. '[öo]sterreich|austria|ungarn|hungary|rumänien|rumaenien|romania|'
			. 'serbien|serbia|kroatien|croatia|moldau|moldova|'
			. 'who\b|world\s+health\s+organization|imf|world\s+bank|weltbank|unesco|unicef|'
			. 'bbc|reuters|associated\s+press'
			. ')\b/iu',
			$text
		) === 1;
		$us_local = preg_match(
			// Fix A: only block CLEARLY US-LOCAL items (school district
			// elections, state senate races, town-level news). General
			// Trump / White House / USA mentions in international news
			// are core_geo material now.
			'/\b('
			. 'school\s+district|local\s+schools|state\s+senate\s+(race|district|seat)|'
			. 'u\.s\.\s+teacher\s+pay|american\s+school\s+district|washington\s+dinner|'
			. 'press\s+dinner|state\s+governor\s+race|county\s+commissioner|'
			. 'redistricting|local\s+ballot|local\s+primary|town\s+hall|'
			. 'mock\s+draft|nfl\s+mock|nhl\s+mock|college\s+football|'
			. 'sheriff(?!s)\b|fbi\s+(?:probe|investigation)|kash\s+patel'
			. ')\b/iu',
			$text
		) === 1;
		if ($us_local && ! $core_geo) {
			return true;
		}

		// Fix B: biotech_pr was blocking ALL FDA / Phase-trial / Therapeutics
		// mentions, which killed regulatory news of global interest
		// (vaccine policy, drug approvals). Narrow to true PR/press-release
		// language; major regulatory actions still flow through.
		$biotech_pr = preg_match(
			'/\b('
			. '(reports|announces|reported)\s+positive\s+(phase|results|topline)|'
			. 'topline\s+results|pivotal\s+trial\s+results|'
			. 'investor\s+(presentation|update|conference)|'
			. 'press\s+release.{0,80}(therapeutics|biotech|pharmaceutical)|'
			. 'hereditary\s+angioedema|orphan\s+drug\s+designation'
			. ')\b/iu',
			$text
		) === 1;
		if ($biotech_pr && ! $core_geo) {
			return true;
		}

		$product_test = preg_match('/\b(öko-?test|stiftung warentest|vitamin-?d|präparate|ranking|die besten|produkttest|testbericht)\b/iu', $text) === 1;
		$public_warning = preg_match('/\b(r[üu]ckruf|warnung|verbot|gesundheitsgefahr|beh[öo]rde warnt)\b/iu', $text) === 1;
		if ($product_test && ! $public_warning) {
			return true;
		}

		$event_page = preg_match('/\b(special opening hours|opening hours|programme at|concert version|gallery weekend|ausstellung|vernissage|programm am|sonder[öo]ffnungszeiten)\b/iu', $text) === 1;
		$news_delta = preg_match('/\b(er[öo]ffnet|beschlossen|kritisiert|warnt|fordert|angek[üu]ndigt|streik|protest|urteil|gesetz|investition|angriff|tote|verletzte)\b/iu', $text) === 1;
		if ($event_page && ! $news_delta) {
			return true;
		}

		return false;
	}

	private static function candidate_is_fresh_enough(array $item, array $analysis, ?object $source = null): bool {
		$date = trim((string) ($item['date'] ?? ''));
		if ($date === '') {
			return true;
		}
		$ts = strtotime($date);
		if (! $ts) {
			return true;
		}
		$age = time() - $ts;
		if ($age <= 0) {
			return true;
		}
		$category = sanitize_key((string) ($analysis['category'] ?? $item['category'] ?? ''));
		$text = mb_strtolower(trim(implode(' ', array_filter([
			(string) ($item['title'] ?? ''),
			(string) ($item['excerpt'] ?? ''),
			wp_strip_all_tags((string) ($item['content'] ?? '')),
		]))));

		// Time-sensitive stories must be near-real-time. Old "clock change" or
		// "today/tomorrow event" items should never enter automation late.
		if (preg_match('/\bsommerzeit\b|\bwinterzeit\b|\bzeitumstellung\b|\buhr(?:en)?\s+(vor|zur[üu]ck|umstellen)\b|перев[ео]д.*час|літн[ійого].*час|зимов[ийого].*час/u', $text) === 1) {
			return $age <= (6 * HOUR_IN_SECONDS);
		}

		$serviceCategory = in_array($category, ['leben-in-deutschland', 'community'], true);
		$serviceText = preg_match('/jobcenter|arbeitsagentur|bamf|einb[üu]rger|wohngeld|kindergeld|sprachkurs|aufenthalt|community|beratung|krankenkasse|допомог|інтеграц|посвідк|страхов|громад/u', $text) === 1;
		$score = max(0, (int) ($analysis['score'] ?? 0));
		$decision = sanitize_key((string) ($analysis['decision'] ?? ''));
		$tier = strtoupper(sanitize_key((string) ($analysis['tier'] ?? '')));
		$source_type = is_object($source) ? sanitize_key((string) ($source->type ?? '')) : '';
		$source_risk = is_object($source) ? sanitize_key((string) ($source->risk_level ?? '')) : '';
		$trusted_primary = $source_type !== 'google_news' && in_array($source_risk, ['safe', 'low', 'moderate'], true);
		$seriousCategory = in_array($category, ['politik', 'welt', 'ukraine', 'europa', 'deutschland', 'wirtschaft', 'bayern', 'muenchen'], true);
		$softCategory = in_array($category, ['sport', 'kultur'], true);
		$window = ($serviceCategory || $serviceText) ? 24 * HOUR_IN_SECONDS : 8 * HOUR_IN_SECONDS;

		if ($score >= 70 || $decision === 'priority' || $tier === 'A') {
			$window = max($window, 48 * HOUR_IN_SECONDS);
		} elseif ($score >= 52 || $decision === 'strong' || $tier === 'B') {
			$window = max($window, $softCategory ? 24 * HOUR_IN_SECONDS : 36 * HOUR_IN_SECONDS);
		} elseif ($decision === 'review' && $score >= 44 && ($seriousCategory || $trusted_primary)) {
			$window = max($window, 18 * HOUR_IN_SECONDS);
		} elseif ($decision === 'review' && $score >= 40 && $trusted_primary && $seriousCategory) {
			$window = max($window, 18 * HOUR_IN_SECONDS);
		} elseif (
			// Fix C: any heavyweight category C-tier item with a meaningful
			// score (>=30) and a non-reject/non-low decision deserves the
			// 18h window. Yesterday-evening politik/welt/ukraine pieces
			// from Tagesschau / NDR / FAZ were getting freshness-blocked
			// at 9-17h despite landing in C/review after the borderline
			// uplift.
			in_array($decision, ['review', 'strong', 'priority'], true)
			&& $score >= 30
			&& $seriousCategory
		) {
			$window = max($window, 18 * HOUR_IN_SECONDS);
		}

		if ($serviceCategory || $serviceText) {
			$window = max($window, $score >= 52 ? 48 * HOUR_IN_SECONDS : 30 * HOUR_IN_SECONDS);
		}

		return $age <= $window;
	}

	private static function effective_collect_per_category_limit(): int {
		return max(1, (int) EPV2_Settings::get('max_collect_per_category', 2));
	}

	private static function effective_queue_new_max_per_category(): int {
		return max(1, (int) EPV2_Settings::get('queue_new_max_per_category', 2));
	}

	/**
	 * Phase 3.B prep — selective ingest.
	 *
	 * Cap how many items we ingest per source per cycle:
	 *   - drop items older than 2 × fetch_interval (default cutoff = 60 min);
	 *   - sort remainder by date DESC (newest first); items without parsable
	 *     date stay in their relative order at the tail;
	 *   - keep only the top N where N depends on source priority:
	 *       priority ≥ 9   → 5 items (top-tier outlets)
	 *       priority 7–8   → 3 items
	 *       priority ≤ 6   → 2 items
	 *
	 * Reduces typical incoming volume from ~300/cycle to ~50/cycle so the
	 * downstream selection pyramid (Story Card → importance score → gate)
	 * does not burn AI tokens on items that would never publish anyway.
	 */
	private static function trim_to_recent_per_source(array $items, object $source): array {
		if ($items === []) {
			return $items;
		}
		$priority = (int) ($source->priority ?? 5);
		if ($priority >= 9) {
			$cap = 5;
		} elseif ($priority >= 7) {
			$cap = 3;
		} else {
			$cap = 2;
		}

		// Freshness window (operator spec 2026-05-11):
		//   • base = 80 минут (НЕ выше для general slots)
		//   • morning_catchup (06:00-09:00 Berlin) = 120 минут — ловим
		//     overnight news которые могли просочиться позже на feed'ы
		// Раньше было `min(80min, interval*2)` — для tagesschau (30-min
		// interval) давало 60 мин окно → утренние items 78 мин старше
		// → silent drop без audit'a. Pipeline терял весь утренний поток.
		$is_morning_catchup = false;
		if (class_exists('EPV2_Time_Planner')) {
			$window = EPV2_Time_Planner::current_window();
			$is_morning_catchup = (string) ($window['mode'] ?? '') === 'morning_catchup';
		}
		$cutoff_minutes = $is_morning_catchup ? 120 : 80;
		$cutoff_ts = time() - ($cutoff_minutes * MINUTE_IN_SECONDS);

		$indexed = [];
		foreach ($items as $idx => $item) {
			$date_str = (string) ($item['date'] ?? '');
			$ts = $date_str !== '' ? strtotime($date_str) : false;
			$indexed[] = [
				'item' => $item,
				'ts' => $ts === false ? 0 : (int) $ts,
				'idx' => $idx,
			];
		}

		// Drop items strictly older than cutoff. Items without a parsable
		// date keep ts=0 and are NOT dropped here — they still get a chance
		// (Google News stubs sometimes lack date in the entry).
		$indexed = array_values(array_filter(
			$indexed,
			static function (array $row) use ($cutoff_ts): bool {
				return $row['ts'] === 0 || $row['ts'] >= $cutoff_ts;
			}
		));

		usort($indexed, static function (array $a, array $b): int {
			if ($a['ts'] === $b['ts']) {
				return $a['idx'] <=> $b['idx'];
			}
			// Newest (highest ts) first; ts=0 sinks to the bottom.
			if ($a['ts'] === 0) return 1;
			if ($b['ts'] === 0) return -1;
			return $b['ts'] <=> $a['ts'];
		});

		$indexed = array_slice($indexed, 0, $cap);
		return array_map(static fn (array $row) => $row['item'], $indexed);
	}
}
