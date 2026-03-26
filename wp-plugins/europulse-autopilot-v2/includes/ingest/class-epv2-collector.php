<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Collector {
		public static function run_scheduled(bool $force = false): void {
			$started_at = microtime(true);
			if (! EPV2_Time_Planner::should_collect($force)) {
				return;
			}
		if (EPV2_Lock_Manager::is_active('collect')) {
			return;
		}
		if (EPV2_Runs::has_recent_started('collect', 300)) {
			return;
		}
		EPV2_Resilience_Manager::cleanup();
		$lock = EPV2_Lock_Manager::acquire('collect', (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
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
		$progress = [
			'status' => 'running',
			'total_sources' => count($sources),
			'processed_sources' => 0,
			'current_source' => '',
			'collected_items' => 0,
			'errors' => 0,
			'updated_at' => current_time('mysql'),
		];
		update_option('epv2_collect_progress', $progress, false);

		try {
		foreach ($sources as $index => $source) {
			EPV2_Lock_Manager::heartbeat('collect', $lock, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
			if (EPV2_Resilience_Manager::source_on_cooldown((int) $source->id)) {
				$progress['processed_sources'] = $index + 1;
				$progress['updated_at'] = current_time('mysql');
				update_option('epv2_collect_progress', $progress, false);
				continue;
			}
			try {
				$progress['current_source'] = (string) $source->name;
				$items = self::collect_source($source);
				foreach ($items as $item) {
					if (self::ingest_candidate($item, $source, $by_category)) {
						$count++;
					}
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
			update_option('epv2_collect_progress', $progress, false);
		}

		EPV2_Stats::bump('collected', $count);
		$created_story_candidates = EPV2_Story_Clusters::create_story_candidates();
		foreach (EPV2_Trends::candidate_items() as $trend_item) {
			$trend_source = (object) [
				'id' => 0,
				'category_bias' => EPV2_Categorizer::detect((string) ($trend_item['title'] ?? ''), (string) ($trend_item['content'] ?? ''), 'deutschland'),
				'priority' => 6,
				'risk_level' => 'low',
				'name' => 'Google Trends',
			];
			if (self::ingest_candidate($trend_item, $trend_source, $by_category)) {
				$count++;
			}
		}
		$trimmed_new = EPV2_Queue::trim_new_queue(
			(int) EPV2_Settings::get('queue_new_max_per_category', 8),
			(int) EPV2_Settings::get('queue_new_max_per_source', 10)
		);
		$progress['status'] = $errors ? 'finished_with_errors' : 'finished';
		$progress['current_source'] = '';
		update_option('epv2_collect_progress', $progress, false);
			EPV2_Runs::finish($run, $errors ? 'finished_with_errors' : 'finished', $count, $errors, [
				'total_sources' => count($sources),
				'processed_sources' => count($sources),
				'collected_items' => $count,
				'story_candidates_created' => $created_story_candidates,
				'trimmed_new' => $trimmed_new,
				'errors' => $errors,
				'by_category' => $by_category,
				'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
			]);
		if (EPV2_Queue::has_processable_items()) {
			EPV2_Jobs::enqueue_process();
		}
		} finally {
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

	private static function ingest_candidate(array $item, object $source, array &$by_category): bool {
		$category = (string) $source->category_bias;
		$active_load = EPV2_Queue::category_load(['new', 'processing_de', 'retry_process', 'ready_publish', 'publishing']);
		$by_category[$category] = $by_category[$category] ?? 0;
		if ($category !== '' && $by_category[$category] >= EPV2_Settings::get('max_collect_per_category', 2)) {
			return false;
		}
		if ($category !== '' && (int) ($active_load[$category] ?? 0) >= (int) EPV2_Settings::get('queue_new_max_per_category', 2)) {
			return false;
		}
		$duplicate = EPV2_Deduplicator::is_duplicate((string) ($item['title'] ?? ''), (string) ($item['content'] ?? ''), (string) ($item['url'] ?? ''));
		if (! empty($duplicate['duplicate'])) {
			EPV2_Stats::bump('duplicates');
			return false;
		}
		$item['source_id'] = (int) ($source->id ?? 0);
		$item['category'] = $category;
		$analysis = EPV2_Budget_Manager::analyze_item($item, $source);
		if (($analysis['decision'] ?? '') === 'reject') {
			return false;
		}
		$queue_gate = EPV2_Budget_Manager::should_keep_in_queue($analysis);
		if (empty($queue_gate['keep'])) {
			return false;
		}
		$planner = EPV2_Category_Planner::decide_for_candidate($category, (int) ($analysis['score'] ?? 0));
		if (($planner['action'] ?? '') === 'reject') {
			return false;
		}
		if (($planner['action'] ?? '') === 'replace' && ! empty($planner['replace_id'])) {
			EPV2_Queue::mark_state((int) $planner['replace_id'], 'rejected', [
				'error_message' => 'Более сильный материал по той же рубрике вытеснил этот кандидат.',
			]);
		}
		$cluster = EPV2_Story_Clusters::register_candidate($item, $source);
		$story_duplicate = EPV2_Deduplicator::is_story_duplicate($item, $cluster);
		if (! empty($story_duplicate['duplicate'])) {
			EPV2_Stats::bump('duplicates');
			return false;
		}
		$item['cluster_id'] = (int) ($cluster['id'] ?? 0);
		$item['topic_label'] = (string) ($cluster['topic_label'] ?? '');
		$item['story_score'] = (int) ($analysis['score'] ?? 0);
		$item['story_format'] = '';
		$item['state'] = 'new';
		$item['admin_notes'] = wp_json_encode(['selection' => $analysis, 'cluster' => $cluster, 'planner' => $planner], JSON_UNESCAPED_UNICODE);
		$item_id = EPV2_Queue::add_item($item);
		if ($item_id > 0 && ! empty($cluster['id'])) {
			$cluster = EPV2_Story_Clusters::refresh_cluster_metrics((int) $cluster['id']);
			EPV2_Queue::update_fields($item_id, [
				'admin_notes' => wp_json_encode(['selection' => $analysis, 'cluster' => $cluster], JSON_UNESCAPED_UNICODE),
			]);
		}
		if ($category !== '') {
			$by_category[$category]++;
		}
		return $item_id > 0;
	}
}
