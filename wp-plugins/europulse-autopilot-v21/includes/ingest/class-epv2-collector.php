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
		if (in_array($type, ['rss', 'atom'], true)) {
			return EPV2_Feed_Reader::fetch((string) $source->url, false);
		}
		if ($type === 'google_news') {
			return EPV2_Feed_Reader::fetch((string) $source->url, true);
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
			return;
		}

		$item['source_id'] = (int) ($source->id ?? 0);
		$item['category'] = $source_category;
		$analysis = EPV2_Budget_Manager::analyze_item($item, $source);
		$category = sanitize_text_field((string) ($analysis['category'] ?? $source_category));
		$item['category'] = $category !== '' ? $category : $source_category;
		if ($category === '' || ($analysis['decision'] ?? '') === 'reject') {
			return;
		}
		if (! self::candidate_is_fresh_enough($item, $analysis)) {
			return;
		}
		$active_load = EPV2_Queue::category_load(['new', 'processing_de', 'retry_process', 'ready_publish', 'publishing']);
		if ((int) ($active_load[$category] ?? 0) >= self::effective_queue_new_max_per_category()) {
			return;
		}

		$ai_gate = EPV2_Budget_Manager::should_send_to_ai($analysis);
		if (
			self::automation_requires_publish_grade()
			&& ! in_array((string) ($analysis['decision'] ?? ''), ['review', 'strong', 'priority'], true)
		) {
			return;
		}
		if (
			self::automation_requires_publish_grade()
			&& empty($ai_gate['allow'])
			&& (string) ($ai_gate['reason'] ?? '') !== 'достигнут AI-бюджет дня'
		) {
			return;
		}
		$queue_gate = EPV2_Budget_Manager::should_keep_in_queue($analysis);
		if (empty($queue_gate['keep'])) {
			return;
		}
		$planner = EPV2_Category_Planner::decide_for_candidate($category, (int) ($analysis['score'] ?? 0), $analysis);
		if (($planner['action'] ?? '') === 'reject') {
			return;
		}

		$staged_candidates[$category] = $staged_candidates[$category] ?? [];
		$staged_candidates[$category][] = [
			'item' => $item,
			'source' => $source,
			'analysis' => $analysis,
			'score' => self::candidate_rank_score($item, $source, $analysis),
		];
	}

	private static function commit_staged_candidates(array $staged_candidates, array &$by_category): int {
		$count = 0;
		$collect_limit = self::effective_collect_per_category_limit();
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
				if (self::ingest_candidate((array) $candidate['item'], (object) $candidate['source'], $by_category)) {
					$count++;
					break;
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
			return false;
		}
		$item['source_id'] = (int) ($source->id ?? 0);
		$item['category'] = $source_category;
		$analysis = EPV2_Budget_Manager::analyze_item($item, $source);
		$category = sanitize_text_field((string) ($analysis['category'] ?? $source_category));
		$item['category'] = $category !== '' ? $category : $source_category;
		if (($analysis['decision'] ?? '') === 'reject') {
			return false;
		}
		if (! self::candidate_is_fresh_enough($item, $analysis)) {
			return false;
		}
		$active_load = EPV2_Queue::category_load(['new', 'processing_de', 'retry_process', 'ready_publish', 'publishing']);
		$category = sanitize_text_field((string) ($item['category'] ?? ''));
		$by_category[$category] = $by_category[$category] ?? 0;
		$collect_limit = self::effective_collect_per_category_limit();
		$queue_limit = self::effective_queue_new_max_per_category();
		if ($category !== '' && $by_category[$category] >= $collect_limit) {
			return false;
		}
		if ($category !== '' && (int) ($active_load[$category] ?? 0) >= $queue_limit) {
			return false;
		}
		$ai_gate = EPV2_Budget_Manager::should_send_to_ai($analysis);
		if (
			self::automation_requires_publish_grade()
			&& ! in_array((string) ($analysis['decision'] ?? ''), ['review', 'strong', 'priority'], true)
		) {
			return false;
		}
		if (
			self::automation_requires_publish_grade()
			&& empty($ai_gate['allow'])
			&& (string) ($ai_gate['reason'] ?? '') !== 'достигнут AI-бюджет дня'
		) {
			return false;
		}
		$queue_gate = EPV2_Budget_Manager::should_keep_in_queue($analysis);
		if (empty($queue_gate['keep'])) {
			return false;
		}
		$planner = EPV2_Category_Planner::decide_for_candidate($category, (int) ($analysis['score'] ?? 0), $analysis);
		if (($planner['action'] ?? '') === 'reject') {
			return false;
		}
		$cluster = EPV2_Story_Clusters::register_candidate($item, $source);
		$story_duplicate = EPV2_Deduplicator::is_story_duplicate($item, $cluster);
		if (! empty($story_duplicate['duplicate'])) {
			EPV2_Stats::bump('duplicates');
			return false;
		}
		$event_key = sanitize_title((string) ($story_duplicate['event_key'] ?? EPV2_Deduplicator::event_key_for_candidate($item, $cluster)));
		$item['cluster_id'] = (int) ($cluster['id'] ?? 0);
		$item['topic_label'] = (string) ($cluster['topic_label'] ?? '');
		$item['story_score'] = (int) ($analysis['score'] ?? 0);
		$item['story_format'] = '';
		$item['state'] = 'new';
		$item['admin_notes'] = wp_json_encode(['selection' => $analysis, 'ai_gate' => $ai_gate, 'cluster' => $cluster, 'planner' => $planner, 'event_key' => $event_key], JSON_UNESCAPED_UNICODE);
		$item_id = EPV2_Queue::add_item($item);
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

	private static function candidate_is_fresh_enough(array $item, array $analysis): bool {
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
		$window = ($serviceCategory || $serviceText) ? 18 * HOUR_IN_SECONDS : 8 * HOUR_IN_SECONDS;

		return $age <= $window;
	}

	private static function effective_collect_per_category_limit(): int {
		return max(1, (int) EPV2_Settings::get('max_collect_per_category', 2));
	}

	private static function effective_queue_new_max_per_category(): int {
		return max(1, (int) EPV2_Settings::get('queue_new_max_per_category', 2));
	}
}
