<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Queue {
	private const OPTION_ACTIVE_AUTOMATION_ITEM = 'epv2_active_automation_item';
	private const WORKFLOW_VERSION = 2;
	private const SUMMARY_FIELDS = 'id, source_id, cluster_id, state, mode, story_format, topic_label, story_score, original_url, original_title, original_excerpt, source_image_url, category_proposed, category_final, ai_payload, publish_payload, post_id, error_message, admin_notes, created_at, updated_at';
	private const STAGE_RESUME_FIELDS = "id, state, story_score, category_proposed, category_final, error_message, admin_notes, created_at, updated_at, JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.pipeline_stage')) AS _epv2_pipeline_stage";
	private const PROCESS_LANE_MONOPOLY_STREAK = 3;
	private const PROCESS_LANE_MONOPOLY_WINDOW = 6;
	private const PROCESS_LANE_RESUME_COOLDOWN = 900;

	public static function get_item(int $id) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}epv2_queue WHERE id = %d", $id));
	}

	public static function get_item_summary(int $id) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$wpdb->prefix}epv2_queue WHERE id = %d",
			$id
		));
	}

	public static function add_item(array $item): int {
		global $wpdb;
		$hashes = EPV2_Deduplicator::hashes($item['title'] ?? '', $item['content'] ?? '');
		$original_url = esc_url_raw($item['url'] ?? '');
		if ($original_url !== '') {
			$existing = self::find_existing_original_url_row($original_url);
			if ($existing instanceof stdClass) {
				self::refresh_existing_original_url_row((int) $existing->id, $item);
				return (int) $existing->id;
			}
		}
		$state = sanitize_text_field((string) ($item['state'] ?? 'new'));
		$wpdb->insert(
			$wpdb->prefix . 'epv2_queue',
			[
				'source_id' => (int) ($item['source_id'] ?? 0) ?: null,
				'cluster_id' => (int) ($item['cluster_id'] ?? 0) ?: null,
				'state' => $state !== '' ? $state : 'new',
				'mode' => EPV2_Settings::get('mode', 'semi'),
				'story_format' => sanitize_text_field($item['story_format'] ?? ''),
				'topic_label' => sanitize_text_field($item['topic_label'] ?? ''),
				'story_score' => max(0, (int) ($item['story_score'] ?? 0)),
				'language_plan' => implode(',', EPV2_Settings::get('publish_languages', ['de', 'uk', 'en'])),
				'original_url' => $original_url,
				'canonical_url' => esc_url_raw($item['canonical_url'] ?? ''),
				'original_title' => sanitize_text_field($item['title'] ?? ''),
				'original_content' => wp_kses_post($item['content'] ?? ''),
				'original_excerpt' => sanitize_text_field($item['excerpt'] ?? ''),
				'original_date' => ! empty($item['date']) ? gmdate('Y-m-d H:i:s', strtotime((string) $item['date'])) : null,
				'original_author' => sanitize_text_field($item['author'] ?? ''),
				'source_image_url' => esc_url_raw($item['image'] ?? ''),
				'ai_payload' => ! empty($item['payload']) && is_array($item['payload']) ? wp_json_encode($item['payload'], JSON_UNESCAPED_UNICODE) : null,
				'admin_notes' => $item['admin_notes'] ?? null,
				'title_hash' => $hashes['title_hash'],
				'content_hash' => $hashes['content_hash'],
				'semantic_hash' => $hashes['semantic_hash'],
				'category_proposed' => sanitize_text_field($item['category'] ?? ''),
			]
		);
		return (int) $wpdb->insert_id;
	}

	private static function find_existing_original_url_row(string $url): ?object {
		global $wpdb;
		$url = esc_url_raw($url);
		if ($url === '') {
			return null;
		}
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
			WHERE original_url = %s
			AND state IN ('new','processing_de','retry_process','ready_review','ready_publish','retry_publish','publishing','reserve','published')
			ORDER BY updated_at DESC, id DESC
			LIMIT 10",
			$url
		));
		if (! is_array($rows) || $rows === []) {
			return null;
		}
		usort($rows, static function ($a, $b): int {
			$aPriority = self::duplicate_survivor_priority($a);
			$bPriority = self::duplicate_survivor_priority($b);
			if ($aPriority !== $bPriority) {
				return $bPriority <=> $aPriority;
			}
			return strcmp((string) ($b->updated_at ?? ''), (string) ($a->updated_at ?? ''));
		});
		return $rows[0] ?? null;
	}

	private static function refresh_existing_original_url_row(int $id, array $item): void {
		$current = self::get_item_summary($id);
		if (! $current) {
			return;
		}
		$fields = [];
		$incoming_score = max(0, (int) ($item['story_score'] ?? 0));
		if ($incoming_score > (int) ($current->story_score ?? 0)) {
			$fields['story_score'] = $incoming_score;
		}
		$incoming_title = sanitize_text_field($item['title'] ?? '');
		if ($incoming_title !== '' && mb_strlen($incoming_title) > mb_strlen((string) ($current->original_title ?? ''))) {
			$fields['original_title'] = $incoming_title;
		}
		$incoming_excerpt = sanitize_text_field($item['excerpt'] ?? '');
		if ($incoming_excerpt !== '' && mb_strlen($incoming_excerpt) > mb_strlen((string) ($current->original_excerpt ?? ''))) {
			$fields['original_excerpt'] = $incoming_excerpt;
		}
		$incoming_content = wp_kses_post($item['content'] ?? '');
		if (
			$incoming_content !== ''
			&& mb_strlen(wp_strip_all_tags($incoming_content)) > mb_strlen(wp_strip_all_tags((string) ($current->original_content ?? '')))
		) {
			$fields['original_content'] = $incoming_content;
		}
		$incoming_image = esc_url_raw($item['image'] ?? '');
		if ($incoming_image !== '' && trim((string) ($current->source_image_url ?? '')) === '') {
			$fields['source_image_url'] = $incoming_image;
		}
		$incoming_category = sanitize_text_field($item['category'] ?? '');
		if ($incoming_category !== '' && trim((string) ($current->category_proposed ?? '')) === '') {
			$fields['category_proposed'] = $incoming_category;
		}
		if ($fields !== []) {
			self::update_fields($id, $fields);
		}
	}

	private static function duplicate_survivor_priority(object $item): int {
		$priority = 0;
		if (self::workflow_owner_token($item) !== '') {
			$priority += 1000;
		}
		if (self::workflow_infer_step_from_row($item) !== '') {
			$priority += 400;
		}
		$payload = self::row_payload($item);
		if ($payload !== []) {
			$priority += min(300, strlen(wp_json_encode($payload, JSON_UNESCAPED_UNICODE)) / 40);
			$priority += mb_strlen(wp_strip_all_tags((string) ($payload['languages']['uk']['content'] ?? ''))) > 0 ? 120 : 0;
			$priority += mb_strlen(wp_strip_all_tags((string) ($payload['languages']['en']['content'] ?? ''))) > 0 ? 60 : 0;
			$priority += mb_strlen(wp_strip_all_tags((string) ($payload['languages']['de']['content'] ?? ''))) > 0 ? 40 : 0;
		}
		$state = (string) ($item->state ?? '');
		if ($state === 'published') {
			$priority += 200;
		} elseif ($state === 'ready_publish') {
			$priority += 150;
		}
		return (int) $priority;
	}

	private static function normalize_duplicate_recoverable_original_urls(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results(
			"SELECT original_url, COUNT(*) AS qty
			FROM {$table}
			WHERE original_url <> ''
			AND state IN ('new','processing_de','retry_process','ready_review','reserve')
			GROUP BY original_url
			HAVING COUNT(*) > 1
			LIMIT 50"
		);
		if (! is_array($rows) || $rows === []) {
			return 0;
		}
		$changed = 0;
		foreach ($rows as $group) {
			$url = trim((string) ($group->original_url ?? ''));
			if ($url === '') {
				continue;
			}
			$candidates = $wpdb->get_results($wpdb->prepare(
				"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
				WHERE original_url = %s
				AND state IN ('new','processing_de','retry_process','ready_review','reserve')
				ORDER BY updated_at DESC, id DESC",
				$url
			));
			if (! is_array($candidates) || count($candidates) < 2) {
				continue;
			}
			usort($candidates, static function ($a, $b): int {
				$aPriority = self::duplicate_survivor_priority($a);
				$bPriority = self::duplicate_survivor_priority($b);
				if ($aPriority !== $bPriority) {
					return $bPriority <=> $aPriority;
				}
				return strcmp((string) ($b->updated_at ?? ''), (string) ($a->updated_at ?? ''));
			});
			$canonical = $candidates[0] ?? null;
			if (! $canonical) {
				continue;
			}
			foreach (array_slice($candidates, 1) as $duplicate) {
				self::mark_state((int) $duplicate->id, 'duplicate', [
					'error_message' => sprintf(
						'Точный дубликат source URL объединён с canonical queue item #%d, чтобы automation не тратила токены повторно на один и тот же материал.',
						(int) $canonical->id
					),
				]);
				$changed++;
			}
		}
		return $changed;
	}

	public static function get_items(array $args = []): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$where = ['1=1'];
		if (! empty($args['state'])) {
			$where[] = $wpdb->prepare('state = %s', $args['state']);
		}
		if (! empty($args['states']) && is_array($args['states'])) {
			$states = array_values(array_filter(array_map('sanitize_text_field', $args['states'])));
			if ($states !== []) {
				$placeholders = implode(',', array_fill(0, count($states), '%s'));
				$where[] = $wpdb->prepare("state IN ({$placeholders})", ...$states);
			}
		}
		$limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
		$fields = self::select_fields($args['fields'] ?? '*');
		return $wpdb->get_results("SELECT {$fields} FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT {$limit}");
	}

	public static function get_queue_items_summary(array $args = []): array {
		$args['fields'] = self::SUMMARY_FIELDS;
		return self::get_items($args);
	}

	private static function processing_summary_fields(): string {
		return self::SUMMARY_FIELDS . ", JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.pipeline_stage')) AS _epv2_pipeline_stage";
	}

	private static function get_queue_items_processing_summary(array $args = []): array {
		$args['fields'] = self::processing_summary_fields();
		return self::get_items($args);
	}

	private static function get_stage_resume_items(array $args = []): array {
		$args['fields'] = self::processing_summary_fields();
		return self::get_items($args);
	}

	private static function orchestrator_v2_enabled(): bool {
		return class_exists('EPV2_Jobs') && EPV2_Jobs::orchestrator_v2_enabled();
	}

	public static function category_load(array $states = ['new', 'retry_process', 'ready_publish', 'publishing']): array {
		global $wpdb;
		$states = array_values(array_filter(array_map('sanitize_text_field', $states)));
		if ($states === []) {
			return [];
		}
		$placeholders = implode(',', array_fill(0, count($states), '%s'));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT category_proposed, category_final, COUNT(*) as qty
				FROM {$wpdb->prefix}epv2_queue
				WHERE state IN ({$placeholders})
				GROUP BY category_proposed, category_final",
				...$states
			),
			ARRAY_A
		);
		$load = [];
		foreach ($rows as $row) {
			$categories = self::categories_from_value((string) ($row['category_final'] ?: $row['category_proposed']));
			foreach ($categories as $category) {
				$load[$category] = ($load[$category] ?? 0) + (int) $row['qty'];
			}
		}
		return $load;
	}

	public static function next_item_for_processing(bool $ignore_retry_after = false): ?object {
		if (self::orchestrator_v2_enabled()) {
			self::log_selector_step('enter_v2', ['ignore_retry_after' => $ignore_retry_after ? 1 : 0]);
			return self::resume_or_claim_item($ignore_retry_after);
		}
		self::promote_live_published_rows(20);
		self::reactivate_media_recoverable_rows(5);
		self::log_selector_step('enter', ['ignore_retry_after' => $ignore_retry_after ? 1 : 0]);
		$focused_item = self::focused_automation_item($ignore_retry_after);
		self::log_selector_step('after_focused_item', ['found' => $focused_item instanceof stdClass ? 1 : 0]);
		if ($focused_item instanceof stdClass) {
			return $focused_item;
		}
		if (self::has_active_processing_item()) {
			self::log_selector_step('skip_active_processing_item');
			return null;
		}
		$fresh_new_item = self::next_fresh_new_item($ignore_retry_after);
		self::log_selector_step('after_fresh_new_item', ['found' => $fresh_new_item instanceof stdClass ? 1 : 0]);
		$resume_item = self::next_stage_resume_item($ignore_retry_after);
		self::log_selector_step('after_stage_resume_item', ['found' => $resume_item instanceof stdClass ? 1 : 0]);
		if (self::should_prefer_fresh_new_bucket($fresh_new_item, $resume_item)) {
			self::log_selector_step('return_fresh_new_preferred_over_stage');
			return $fresh_new_item;
		}
		if ($resume_item instanceof stdClass) {
			self::log_selector_step('return_stage_resume_item', ['item_id' => (int) $resume_item->id]);
			return $resume_item;
		}
		$auto_resume_item = self::next_auto_resume_item($ignore_retry_after);
		self::log_selector_step('after_auto_resume_item', ['found' => $auto_resume_item instanceof stdClass ? 1 : 0]);
		if (self::should_prefer_fresh_new_bucket($fresh_new_item, $auto_resume_item)) {
			self::log_selector_step('return_fresh_new_preferred_over_auto');
			return $fresh_new_item;
		}
		if ($auto_resume_item instanceof stdClass) {
			self::log_selector_step('return_auto_resume_item', ['item_id' => (int) $auto_resume_item->id]);
			return $auto_resume_item;
		}
		if ($fresh_new_item instanceof stdClass) {
			self::log_selector_step('return_fresh_new_item', ['item_id' => (int) $fresh_new_item->id]);
			return $fresh_new_item;
		}
		$states = ['new', 'retry_process', 'ready_review'];
		$items = self::get_queue_items_summary(['states' => $states, 'limit' => 30]);
		self::log_selector_step('after_fallback_summary', ['count' => is_array($items) ? count($items) : 0]);
		$items = array_values(array_filter($items, static function ($item) use ($ignore_retry_after): bool {
			return self::item_is_processable_read_only($item, $ignore_retry_after);
		}));
		self::log_selector_step('after_fallback_filter', ['count' => count($items)]);
		if ($items === []) {
			self::log_selector_step('return_none_after_fallback');
			return null;
		}
		$stage_items = array_values(array_filter($items, static function ($item): bool {
			return self::row_processing_stage($item) !== '';
		}));
		if ($stage_items !== [] && self::has_fresh_new_candidate_ready()) {
			$stage_items = self::filter_lane_monopolizing_items($stage_items);
			$preferred_stage_items = array_values(array_filter($stage_items, static function ($item): bool {
				return ! self::is_fatigued_rebuild_candidate($item);
			}));
			if ($preferred_stage_items !== []) {
				$stage_items = $preferred_stage_items;
			} else {
				$stage_items = [];
			}
		}
		if (self::has_fresh_new_candidate_ready()) {
			$items = self::filter_lane_monopolizing_items($items);
		}
		if ($stage_items !== []) {
			usort($stage_items, static function ($a, $b): int {
				$aStage = self::processing_stage_priority($a);
				$bStage = self::processing_stage_priority($b);
				if ($aStage !== $bStage) {
					return $bStage <=> $aStage;
				}
				return strcmp((string) ($a->updated_at ?? $a->created_at ?? ''), (string) ($b->updated_at ?? $b->created_at ?? ''));
			});
			self::log_selector_step('return_fallback_stage_item', ['item_id' => isset($stage_items[0]) ? (int) $stage_items[0]->id : 0]);
			return $stage_items[0] ?? null;
		}
			$load = self::category_load(['retry_process', 'ready_publish', 'publishing']);
			usort($items, static function ($a, $b) use ($load): int {
				$aStage = self::processing_stage_priority($a);
				$bStage = self::processing_stage_priority($b);
				if ($aStage !== $bStage) {
					return $bStage <=> $aStage;
				}
				if ($aStage > 0) {
					$stageOrder = strcmp((string) ($a->created_at ?? ''), (string) ($b->created_at ?? ''));
					if ($stageOrder !== 0) {
						return $stageOrder;
					}
				}
				$aBreaking = self::row_is_breaking($a) ? 1 : 0;
				$bBreaking = self::row_is_breaking($b) ? 1 : 0;
				if ($aBreaking !== $bBreaking) {
				return $bBreaking <=> $aBreaking;
			}
			$aScore = self::effective_processing_score((array) $a);
			$bScore = self::effective_processing_score((array) $b);
			if ($aScore !== $bScore) {
				return $bScore <=> $aScore;
			}
			$aLoad = self::row_category_load($a, $load);
			$bLoad = self::row_category_load($b, $load);
			if ($aLoad !== $bLoad) {
				return $aLoad <=> $bLoad;
			}
			return strcmp((string) $a->created_at, (string) $b->created_at);
		});
		self::log_selector_step('return_fallback_item', ['item_id' => isset($items[0]) ? (int) $items[0]->id : 0]);
		return $items[0] ?? null;
	}

	private static function get_active_owned_item(bool $ignore_retry_after = false): ?object {
		$recovered = self::active_owner_recover_stale();
		if ($recovered > 0) {
			self::log_selector_step('recover_stale_owner', ['item_id' => $recovered]);
		}
		$item = self::active_owner_get();
		if (! $item) {
			self::log_selector_step('active_owner_missing');
			return null;
		}
		if (! $ignore_retry_after && self::active_owner_waiting_on_retry($item)) {
			self::log_selector_step('active_owner_waiting_retry', ['item_id' => (int) $item->id]);
			return null;
		}
		if (self::item_requires_manual_confirmation_state($item)) {
			self::log_selector_step('active_owner_manual_confirmation', ['item_id' => (int) $item->id]);
			return null;
		}
		if (self::row_has_live_published_posts($item)) {
			self::log_selector_step('active_owner_already_published', ['item_id' => (int) $item->id]);
			return null;
		}
		$state = self::normalize_legacy_publish_state((string) ($item->state ?? ''));
		if (self::workflow_is_terminal_state($state) || in_array($state, ['ready_publish', 'retry_publish', 'publishing'], true)) {
			self::log_selector_step('active_owner_not_processable', ['item_id' => (int) $item->id]);
			return null;
		}
		self::active_owner_heartbeat((int) $item->id);
		self::log_selector_step('resume_active_owner', ['item_id' => (int) $item->id]);
		return self::get_item_summary((int) $item->id);
	}

	private static function claim_next_new_item(bool $ignore_retry_after = false): ?object {
		$active = self::active_owner_get();
		if ($active) {
			if (! $ignore_retry_after && self::active_owner_waiting_on_retry($active)) {
				self::log_selector_step('skip_claim_active_owner_waiting_retry', ['item_id' => (int) $active->id]);
			} else {
				self::log_selector_step('skip_claim_active_owner_exists');
				return null;
			}
		}
		$candidate = self::preview_next_claimable_new_item($ignore_retry_after);
		if (! ($candidate instanceof stdClass)) {
			self::log_selector_step('no_claimable_new_items');
			return null;
		}
		$item_id = (int) ($candidate->id ?? 0);
		if ($item_id <= 0) {
			self::log_selector_step('claim_new_item_failed_invalid_candidate');
			return null;
		}
		if (! self::active_owner_claim($item_id)) {
			self::log_selector_step('claim_new_item_failed');
			return null;
		}
		self::workflow_system_update($item_id, [
			'workflow_step_status' => 'claimed',
			'workflow_last_error' => '',
			'workflow_terminal_reason' => '',
		]);
		self::log_selector_step('claim_new_item', ['item_id' => $item_id]);
		return self::get_item_summary($item_id);
	}

	private static function preview_next_claimable_new_item(bool $ignore_retry_after = false): ?object {
		$candidate = self::bridge_next_processable_row('new');
		if (! ($candidate instanceof stdClass)) {
			return null;
		}
		$item_id = (int) ($candidate->id ?? 0);
		if ($item_id <= 0) {
			return null;
		}
		$item = self::get_item_summary($item_id);
		if (! ($item instanceof stdClass)) {
			return null;
		}
		if ((string) ($item->state ?? '') !== 'new') {
			return null;
		}
		if (! self::selector_allows_new_candidate($item)) {
			return null;
		}
		if (! $ignore_retry_after && self::workflow_waiting_not_before($item)) {
			return null;
		}
		return $item;
	}

	private static function selector_allows_new_candidate(object $item): bool {
		if (
			self::row_has_non_publish_grade_selection($item)
			&& self::workflow_owner_token($item) === ''
			&& self::workflow_step($item) === ''
		) {
			return false;
		}
		return true;
	}

	private static function resume_or_claim_item(bool $ignore_retry_after = false): ?object {
		$active = self::get_active_owned_item($ignore_retry_after);
		if ($active instanceof stdClass) {
			return $active;
		}
		$claimed = self::claim_next_new_item($ignore_retry_after);
		if ($claimed instanceof stdClass) {
			return $claimed;
		}
		self::log_selector_step('return_none_v2');
		return null;
	}

	public static function workflow_v2_preview_selection(bool $ignore_retry_after = false): array {
		$active = self::active_owner_get();
		if ($active && ! $ignore_retry_after && self::active_owner_waiting_on_retry($active)) {
			return [
				'mode' => 'blocked_active_owner',
				'item_id' => (int) $active->id,
				'state' => (string) ($active->state ?? ''),
				'workflow_step' => self::workflow_infer_step_from_row($active),
			];
		}
		if ($active && self::item_is_processable_read_only($active, $ignore_retry_after)) {
			return [
				'mode' => 'resume_active_owner',
				'item_id' => (int) $active->id,
				'state' => (string) ($active->state ?? ''),
				'workflow_step' => self::workflow_infer_step_from_row($active),
			];
		}

		$candidate = self::preview_next_claimable_new_item($ignore_retry_after);
		if ($candidate) {
			return [
				'mode' => 'claim_oldest_new',
				'item_id' => (int) $candidate->id,
				'state' => (string) ($candidate->state ?? ''),
				'workflow_step' => self::workflow_infer_step_from_row($candidate),
			];
		}

		return [
			'mode' => 'none',
			'item_id' => 0,
			'state' => '',
			'workflow_step' => '',
		];
	}

	private static function log_selector_step(string $step, array $context = []): void {
		$context['step'] = $step;
		$context['memory_mb'] = (int) round(memory_get_usage(true) / 1048576);
		$context['peak_mb'] = (int) round(memory_get_peak_usage(true) / 1048576);
		EPV2_Logger::info('queue_selector', 'next_item_for_processing step', $context);
	}

	public static function processing_bucket(object $item): string {
		$state = (string) ($item->state ?? '');
		if ($state === 'new' && self::row_processing_stage($item) === '') {
			return 'new';
		}
		if (self::row_processing_stage($item) !== '') {
			return 'resume_stage';
		}
		if (in_array($state, ['retry_process', 'ready_review'], true)) {
			return 'resume_auto';
		}
		return 'other';
	}

	private static function next_stage_resume_item(bool $ignore_retry_after = false): ?object {
		self::log_selector_step('before_stage_resume_query', [
			'ignore_retry_after' => $ignore_retry_after ? 1 : 0,
		]);
		$candidates = self::get_stage_resume_items(['states' => ['ready_review', 'retry_process'], 'limit' => 100]);
		self::log_selector_step('after_stage_resume_query', [
			'count' => count($candidates),
		]);
		$candidates = array_values(array_filter($candidates, static function ($item) use ($ignore_retry_after): bool {
			$stage = self::row_processing_stage($item);
			if ($stage === '') {
				return false;
			}
			$state = (string) ($item->state ?? '');
			if ($state === 'ready_review') {
				return self::ready_review_requires_automation_resume($item) && self::ready_review_due($item);
			}
			if ($state === 'retry_process') {
				return $ignore_retry_after || EPV2_Resilience_Manager::retry_due($item);
			}
			return false;
		}));
		self::log_selector_step('after_stage_resume_filter', [
			'count' => count($candidates),
		]);
		if ($candidates === []) {
			return null;
		}
		if (self::has_fresh_new_candidate_ready()) {
			$candidates = self::filter_lane_monopolizing_items($candidates);
			$preferred = array_values(array_filter($candidates, static function ($item): bool {
				return ! self::is_fatigued_rebuild_candidate($item);
			}));
			if ($preferred !== []) {
				$candidates = $preferred;
			}
		}
		usort($candidates, static function ($a, $b): int {
			$aStage = self::processing_stage_priority($a);
			$bStage = self::processing_stage_priority($b);
			if ($aStage !== $bStage) {
				return $bStage <=> $aStage;
			}
			return strcmp((string) ($a->updated_at ?? $a->created_at ?? ''), (string) ($b->updated_at ?? $b->created_at ?? ''));
		});
		return $candidates[0] ?? null;
	}

	private static function next_fresh_new_item(bool $ignore_retry_after = false): ?object {
		$items = self::get_queue_items_summary(['states' => ['new'], 'limit' => 30]);
		$items = array_values(array_filter($items, static function ($item) use ($ignore_retry_after): bool {
			return self::row_processing_stage($item) === '' && self::item_is_processable_read_only($item, $ignore_retry_after);
		}));
		if ($items === []) {
			return null;
		}
		$load = self::category_load(['retry_process', 'ready_publish', 'publishing']);
		usort($items, static function ($a, $b) use ($load): int {
			$aBreaking = self::row_is_breaking($a) ? 1 : 0;
			$bBreaking = self::row_is_breaking($b) ? 1 : 0;
			if ($aBreaking !== $bBreaking) {
				return $bBreaking <=> $aBreaking;
			}
			$aScore = self::effective_processing_score((array) $a);
			$bScore = self::effective_processing_score((array) $b);
			if ($aScore !== $bScore) {
				return $bScore <=> $aScore;
			}
			$aLoad = self::row_category_load($a, $load);
			$bLoad = self::row_category_load($b, $load);
			if ($aLoad !== $bLoad) {
				return $aLoad <=> $bLoad;
			}
			return strcmp((string) $a->created_at, (string) $b->created_at);
		});
		return $items[0] ?? null;
	}

	private static function next_auto_resume_item(bool $ignore_retry_after = false): ?object {
		$candidates = self::get_queue_items_summary(['states' => ['ready_review', 'retry_process'], 'limit' => 100]);
		$candidates = array_values(array_filter($candidates, static function ($item) use ($ignore_retry_after): bool {
			$stage = self::row_processing_stage($item);
			if ($stage !== '') {
				return false;
			}
			$state = (string) ($item->state ?? '');
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
			if ($payload === []) {
				return false;
			}
			if ($state === 'ready_review') {
				if (! self::ready_review_requires_automation_resume($item) || ! self::ready_review_due($item)) {
					return false;
				}
				return EPV2_AI_Processor::item_is_auto_finish_candidate($item, $payload)
					|| EPV2_AI_Processor::item_is_auto_rework_candidate($item, $payload);
			}
			if ($state === 'retry_process') {
				if (! ($ignore_retry_after || EPV2_Resilience_Manager::retry_due($item))) {
					return false;
				}
				return EPV2_AI_Processor::item_is_auto_finish_candidate($item, $payload)
					|| EPV2_AI_Processor::item_is_auto_rework_candidate($item, $payload);
			}
			return false;
		}));
		if ($candidates === []) {
			return null;
		}
		if (self::has_fresh_new_candidate_ready()) {
			$candidates = self::filter_lane_monopolizing_items($candidates);
			if ($candidates === []) {
				return null;
			}
		}
		usort($candidates, static function ($a, $b): int {
			$aPayload = json_decode((string) ($a->ai_payload ?? ''), true);
			$bPayload = json_decode((string) ($b->ai_payload ?? ''), true);
			$aPayload = is_array($aPayload) ? $aPayload : [];
			$bPayload = is_array($bPayload) ? $bPayload : [];
			$aFinish = EPV2_AI_Processor::item_is_auto_finish_candidate($a, $aPayload) ? 1 : 0;
			$bFinish = EPV2_AI_Processor::item_is_auto_finish_candidate($b, $bPayload) ? 1 : 0;
			if ($aFinish !== $bFinish) {
				return $bFinish <=> $aFinish;
			}
			return strcmp((string) ($a->updated_at ?? $a->created_at ?? ''), (string) ($b->updated_at ?? $b->created_at ?? ''));
		});
		return $candidates[0] ?? null;
	}

	public static function promote_publish_ready_payloads(array $states = ['retry_process']): int {
		$states = array_values(array_filter(array_map('strval', $states)));
		if ($states === []) {
			return 0;
		}
		$items = self::get_queue_items_summary(['states' => $states, 'limit' => 30]);
		if ($items === []) {
			return 0;
		}
		$promoted = 0;
		foreach ($items as $item) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			if (! is_array($payload) || $payload === []) {
				continue;
			}
			$normalized = EPV2_AI_Processor::normalize_existing_payload($payload, false);
			if ($normalized !== $payload) {
				self::update_fields((int) $item->id, [
					'ai_payload' => wp_json_encode($normalized, JSON_UNESCAPED_UNICODE),
				]);
			}
			if (! self::workflow_payload_is_ready_like($normalized)) {
				continue;
			}
			if (EPV2_AI_Processor::transition_item_to_ready_publish((int) $item->id, $normalized, [
				'error_message' => '',
			])) {
				$promoted++;
			}
		}
		return $promoted;
	}

	public static function has_processable_items(): bool {
		if (self::orchestrator_v2_enabled()) {
			$active = self::active_owner_get();
			if ($active && self::active_owner_waiting_on_retry($active)) {
				return false;
			}
			if ($active && self::item_is_processable_read_only($active, false)) {
				return true;
			}
			$candidate = self::preview_next_claimable_new_item(false);
			return $candidate instanceof stdClass;
		}
		$focused_item = self::focused_automation_item(false);
		if ($focused_item instanceof stdClass) {
			return true;
		}
		if (self::has_active_processing_item()) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
				WHERE state IN ('new','retry_process','ready_review')
				ORDER BY created_at DESC
				LIMIT 50"
		);
		if (! is_array($rows) || $rows === []) {
			return false;
		}
		foreach ($rows as $item) {
			if (self::item_is_processable_read_only($item, false)) {
				return true;
			}
		}
		return false;
	}

	private static function item_is_processable_read_only(object $item, bool $ignore_retry_after = false): bool {
		if (self::item_requires_manual_confirmation_state($item)) {
			return false;
		}
		if (self::row_is_low_grade_new_candidate($item)) {
			return false;
		}
		if (! $ignore_retry_after && self::workflow_waiting_not_before($item)) {
			return false;
		}
		if (self::row_has_live_published_posts($item)) {
			return false;
		}
		if (EPV2_AI_Processor::item_has_expired_live_angle($item)) {
			return false;
		}
		$error_message = (string) ($item->error_message ?? '');
		if ($error_message !== '' && preg_match('/устарел|устарела|lost relevance|потеряла актуальность/i', $error_message) === 1) {
			return false;
		}

		$payload = self::row_payload($item);
		if ($payload !== []) {
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
		}
		$workflow_step = self::workflow_infer_step_from_row($item);
		$v2_resume_step = self::orchestrator_v2_enabled()
			&& (string) ($item->state ?? '') === 'new'
			&& $workflow_step !== '';
		if ($payload !== []) {
			$stage_checklist = is_array($payload['_meta']['stage_checklist'] ?? null) ? $payload['_meta']['stage_checklist'] : [];
			if (! empty($stage_checklist['ready_publish']) && ! $v2_resume_step) {
				return false;
			}
			if (EPV2_AI_Processor::payload_is_publish_ready($payload) && ! $v2_resume_step) {
				return false;
			}
			if (! self::automation_requires_publish_grade() && EPV2_AI_Processor::payload_is_review_ready($payload) && (string) ($item->state ?? '') !== 'ready_review') {
				return false;
			}
		}

		if ((string) ($item->state ?? '') === 'ready_review') {
			if (! self::ready_review_requires_automation_resume($item)) {
				return false;
			}
			if (self::row_processing_stage($item) !== '') {
				return true;
			}
			if (! empty($payload['_meta']['translations_deferred'])) {
				return true;
			}
			return self::ready_review_due($item);
		}

		if (self::review_rebuild_exhausted($item) && ! self::automation_requires_publish_grade()) {
			return false;
		}

		if (self::retry_process_is_exhausted($item) && ! self::automation_requires_publish_grade()) {
			return false;
		}

		return (string) ($item->state ?? '') !== 'retry_process' || $ignore_retry_after || EPV2_Resilience_Manager::retry_due($item);
	}

	private static function row_selection_decision(object $item): string {
		$payload = self::row_payload($item);
		$decision = sanitize_key((string) ($payload['_meta']['selection']['decision'] ?? ''));
		if ($decision === '') {
			$notes = self::row_notes($item);
			$decision = sanitize_key((string) ($notes['selection']['decision'] ?? ''));
		}
		return $decision;
	}

	private static function row_has_non_publish_grade_selection(object $item): bool {
		$payload = self::row_payload($item);
		if ($payload !== []) {
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
			if (self::payload_has_publish_limit_override($payload, $item)) {
				return false;
			}
		}
		if (self::planner_soft_candidate_is_noise($item)) {
			return true;
		}
		if (self::planner_selected_soft_candidate($item)) {
			return false;
		}
		return in_array(self::row_selection_decision($item), ['low', 'reject'], true);
	}

	private static function row_has_blocking_publish_selection(object $item): bool {
		$payload = self::row_payload($item);
		if ($payload !== []) {
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
			if (self::payload_has_publish_limit_override($payload, $item)) {
				return false;
			}
		}
		return in_array(self::row_selection_decision($item), ['low', 'reject'], true);
	}

	private static function planner_selected_soft_candidate(object $item): bool {
		$notes = self::row_notes($item);
		$planner = is_array($notes['planner'] ?? null) ? $notes['planner'] : [];
		$selection = self::row_selection_analysis($item);
		$planner_action = sanitize_key((string) ($planner['action'] ?? ''));
		$reject_class = sanitize_key((string) ($selection['reject_class'] ?? ''));
		$score = (int) ($selection['score'] ?? 0);
		return in_array($planner_action, ['select', 'replace'], true)
			&& $score >= 30
			&& ! self::planner_soft_candidate_is_noise($item)
			&& in_array($reject_class, ['', 'low_score'], true);
	}

	private static function planner_soft_candidate_is_noise(object $item): bool {
		$text = mb_strtolower(trim((string) (($item->original_title ?? '') . ' ' . ($item->original_excerpt ?? '') . ' ' . ($item->original_url ?? ''))));
		if ($text === '') {
			return false;
		}
		return preg_match('/\b(let.?s dance|dschungelcamp|promi|celebrity|stalker|llambi|gammour|geweint|horoskop|sternzeichen|ranking|die besten|tv und stream)\b/u', $text) === 1;
	}

	private static function row_is_low_grade_new_candidate(object $item): bool {
		if ((string) ($item->state ?? '') !== 'new') {
			return false;
		}
		if (self::orchestrator_v2_enabled()) {
			$workflow_step = self::workflow_infer_step_from_row($item);
			if ($workflow_step !== '' || self::workflow_owner_token($item) !== '') {
				return false;
			}
		}
		return self::row_has_non_publish_grade_selection($item);
	}

	public static function sanitize_non_publish_grade_new_items(int $limit = 100): int {
		// Сначала вычищаем «зомби» — items, которым sanitize ранее уже выдал
		// «снят из автоматической очереди», но AI-processor воскресил их в
		// retry_process / processing_de через gate.mode=ai_publish_finish_resume.
		// Возвращаем в rejected окончательно (без resurrect-шанса).
		self::force_reject_zombie_pre_ai_rejects();

		$items = self::get_queue_items_summary(['states' => ['new'], 'limit' => max(1, min(500, $limit))]);
		if ($items === []) {
			return 0;
		}

		$changed = 0;
		foreach ($items as $item) {
			if (! ($item instanceof stdClass)) {
				continue;
			}
			if ((string) ($item->state ?? '') !== 'new') {
				continue;
			}
			if (! self::row_has_blocking_publish_selection($item)) {
				continue;
			}
			if (self::workflow_owner_token($item) !== '' || self::workflow_step($item) !== '') {
				continue;
			}
			// Anti-loop: если planner_selected_soft_candidate=true (планировщик
			// явно хочет этот item для добора рубрики), reactivate-handler
			// тут же вернёт его обратно в new при следующем maintenance run.
			// Это создавало бесконечный цикл sanitize→reject→reactivate→new
			// (item «мелькал» в админке часами). Просто пропускаем такие —
			// пусть остаются в new, planner их переварит через AI цикл.
			if (! self::automation_requires_publish_grade() && self::planner_selected_soft_candidate($item)) {
				continue;
			}
			$decision = self::row_selection_decision($item);
			self::mark_state((int) $item->id, 'rejected', [
				'error_message' => 'Материал снят из автоматической очереди: предварительный publish-priority "' . ($decision !== '' ? $decision : 'unknown') . '" не допускает автономную обработку.',
			]);
			$changed++;
		}

		return $changed;
	}

	/**
	 * Auto-router: items в state='new' с payload-сигналами «уже не свежие»
	 * перемещает в правильное user-visible место. Иначе они зависают в
	 * «Новые» и selector их игнорирует (бридж-фильтр пропускает items с
	 * manual_confirmation_required и items с user_facing=ready_publish без
	 * workflow_step).
	 *
	 * Конкретные правила:
	 * - state='new' + payload_is_publish_ready  → mark_state(ready_publish)
	 * - state='new' + admin_notes._system.manual_confirmation_required != ''
	 *                                           → mark_state(manual_review)
	 * - state='new' + admin_notes._system.workflow_terminal_reason != ''
	 *                                           → mark_state(manual_review)
	 *   (ранее worker уже terminate'нул, мы его вернули в 'new' reset'ом
	 *   counter'ов, но без очистки причины — selector такие игнорирует.)
	 */
	public static function auto_route_misclassified_new_items(int $limit = 50): int {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
			 WHERE state = 'new'
			 ORDER BY updated_at ASC
			 LIMIT %d",
			max(1, min(200, $limit))
		));
		if (! is_array($rows) || $rows === []) return 0;

		$active_owner_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		$moved = 0;
		foreach ($rows as $row) {
			if (! ($row instanceof stdClass)) continue;
			// Race guard: skip rows the orchestrator is actively claiming or
			// processing. Those carry workflow_owner_token / are the active
			// automation item; auto-routing under them creates a state-
			// vs-token mismatch the orchestrator cannot recover from.
			$row_id = (int) ($row->id ?? 0);
			if ($active_owner_id > 0 && $row_id === $active_owner_id) continue;
			if (self::workflow_owner_token($row) !== '') continue;
			$notes = self::row_notes($row);
			$sys = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];

			$manual_required = trim((string) ($sys['manual_confirmation_required'] ?? ''));
			$terminal_reason = trim((string) ($sys['workflow_terminal_reason'] ?? ''));

			// Узкий allowlist: workflow_terminal_reason бывает ∈
			// {ready_publish, published, rejected, error_terminal,
			//  workflow_quarantine, selection_publish_blocked, worker_terminal_outcome}.
			// Только последние три действительно требуют ручной проверки;
			// первые четыре — promote/finalize signals, для которых в new
			// item не должен сидеть, но и роуть его в manual_review мы НЕ
			// хотим. Без allowlist'а здоровый item (state='new' случайно с
			// terminal_reason='ready_publish' от старого resume) попадал в
			// очередь оператора.
			$manual_terminal_reasons = [
				'workflow_quarantine',
				'selection_publish_blocked',
				'worker_terminal_outcome',
			];
			$is_manual_terminal = $terminal_reason !== '' && in_array($terminal_reason, $manual_terminal_reasons, true);

			if ($manual_required !== '' || $is_manual_terminal) {
				self::mark_state((int) $row->id, 'manual_review', [
					'error_message' => (string) ($row->error_message ?? '')
						?: 'Material требует ручной проверки: '
						. ($manual_required !== '' ? "manual_confirmation_required=$manual_required" : "workflow_terminal_reason=$terminal_reason"),
				]);
				$moved++;
				continue;
			}

			$payload = self::row_payload($row);
			if ($payload !== [] && EPV2_AI_Processor::payload_is_publish_ready($payload)) {
				self::mark_state((int) $row->id, 'ready_publish');
				$moved++;
			}
		}
		if ($moved > 0 && class_exists('EPV2_Logger')) {
			EPV2_Logger::info('queue', "auto_route_misclassified_new_items: переместил $moved items из new");
		}
		return $moved;
	}

	/**
	 * Auto-promote: items в manual_review с idealными scores, всеми 3
	 * языками переведёнными, media URL — ВЕРНУТЬ в retry_process для
	 * повторной попытки публикации. Часто такие застревают потому что
	 * gate отклонил по contracts на одной попытке, а quarantine унаследовал
	 * stale reason после моего reset'а retry-counters. Если scores =
	 * threshold AND контент полный — это публикабельный материал.
	 */
	public static function auto_promote_complete_manual_review_items(int $limit = 30): int {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
			 WHERE state IN ('manual_review','ready_review')
			   AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
			 ORDER BY updated_at ASC
			 LIMIT %d",
			max(1, min(100, $limit))
		));
		if (! is_array($rows) || $rows === []) return 0;

		$promoted = 0;
		foreach ($rows as $row) {
			if (! ($row instanceof stdClass)) continue;
			$payload = self::row_payload($row);
			if ($payload === []) continue;

			// Quality scores — softer criteria (2026-05-10): items с
			// quality=100 + rel/goo >= 80 публикабельны. Раньше требовалось
			// rel/goo >= threshold (90) — items с 88/85 forever stuck в
			// manual_review. Pipeline теряет ~30% potential output.
			// Если qua=100 (worker уверен в content) + rel/goo >= 80
			// (acceptable scoring) — auto-promote.
			$rel_score = (int) ($payload['_meta']['release_quality']['score'] ?? 0);
			$goo_score = (int) ($payload['_meta']['google_quality']['score'] ?? 0);
			$qua_score = (int) ($payload['_meta']['quality']['score'] ?? 0);
			if ($qua_score < 95 || $rel_score < 80 || $goo_score < 80) {
				continue;
			}

			// All 3 languages have title + body
			$langs_ok = true;
			foreach (['de', 'uk', 'en'] as $l) {
				$title = trim((string) ($payload['languages'][$l]['title'] ?? ''));
				$body = trim((string) ($payload['languages'][$l]['body_html'] ?? ($payload['languages'][$l]['content'] ?? '')));
				if ($title === '' || mb_strlen(strip_tags($body)) < 200) {
					$langs_ok = false;
					break;
				}
			}
			if (! $langs_ok) continue;

			// Media URL present
			$has_media = ! empty($payload['featured_media_url']) || ! empty($payload['media_url'])
				|| ! empty($payload['languages']['de']['media_url']);
			if (! $has_media) continue;

			// Loop terminator: count how many times we've auto-promoted
			// this row. If ≥2, the item already failed the publish gate
			// twice after a "complete" verdict — leave it in manual_review
			// for operator triage instead of looping retry_process →
			// publish_ready_gate fail → manual_review → auto_promote forever.
			// Without this, items with quality=100 but failing some other
			// gate (media relevance, contract integrity) burn AI tokens
			// in an infinite cycle.
			$notes = self::row_notes($row);
			$sys_check = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$prior_promotes = (int) ($sys_check['auto_promote_count'] ?? 0);
			if ($prior_promotes >= 2) {
				continue;
			}

			// All clear → reset terminal signals, retry_process
			if (! is_array($notes['_system'] ?? null)) $notes['_system'] = [];
			$sys = &$notes['_system'];
			foreach ([
				'quarantine_reason','last_stage_blocker','workflow_terminal_reason',
				'workflow_step_attempts','workflow_step','workflow_step_status',
				'workflow_owner_token','workflow_heartbeat_at','workflow_claimed_at',
				'workflow_last_error','workflow_not_before','retry_after',
				'review_finish_signature','review_rebuild_signature',
				'next_operator_action','manual_confirmation_required',
			] as $k) {
				unset($sys[$k]);
			}
			$sys['admin_promote_at'] = current_time('mysql');
			$sys['admin_promote_reason'] = "auto_promote_complete: scores rel=$rel_score goo=$goo_score qua=$qua_score, all 3 langs, media OK";
			$sys['auto_promote_count'] = $prior_promotes + 1;
			unset($sys);
			self::update_fields((int) $row->id, [
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				'error_message' => '',
			]);
			self::mark_state((int) $row->id, 'retry_process');
			$promoted++;
		}
		if ($promoted > 0 && class_exists('EPV2_Logger')) {
			EPV2_Logger::info('queue', "auto_promote_complete_manual_review_items: $promoted items вернули в retry_process");
		}
		return $promoted;
	}

	/**
	 * Items в ready_publish, которые publish-gate стабильно отклоняет
	 * (release_quality / google_quality ниже текущих порогов content-kind,
	 * либо есть quarantine_reason без post_id, и item висит дольше 5 мин)
	 * — переезжают в manual_review. Иначе они вечно крутятся: publisher
	 * вызывается каждый тик, gate возвращает not allowed, publish_not_before
	 * сдвигается вперёд, таймер постоянно растёт, ничего не публикуется.
	 */
	public static function sanitize_stuck_ready_publish_items(int $limit = 50): int {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
			 WHERE state='ready_publish' AND (post_id IS NULL OR post_id = 0)
			   AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
			 ORDER BY updated_at ASC
			 LIMIT %d",
			max(1, min(200, $limit))
		));
		if (! is_array($rows) || $rows === []) return 0;

		$active_owner_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		$moved = 0;
		foreach ($rows as $row) {
			if (! ($row instanceof stdClass)) continue;
			// Race guard: don't sanitize while publisher / orchestrator owns
			// the row — they may flip it to publishing/published in the next
			// tick. The 5-minute updated_at filter already excludes recent
			// activity, but the active-owner option + workflow_owner_token
			// add a final compare-and-swap so concurrent writers cannot lose
			// each other's update.
			$row_id = (int) ($row->id ?? 0);
			if ($active_owner_id > 0 && $row_id === $active_owner_id) continue;
			if (self::workflow_owner_token($row) !== '') continue;
			$payload = self::row_payload($row);
			if ($payload === []) continue;

			$content_kind = sanitize_key((string) ($payload['_meta']['content_kind'] ?? 'news_brief'));
			$thresholds = [];
			if (class_exists('EPV2_Content_Kinds')) {
				$specs = EPV2_Content_Kinds::specs();
				$thresholds = (array) ($specs[$content_kind]['quality_thresholds'] ?? []);
			}
			$rel_threshold = (int) ($thresholds['release_quality'] ?? 0);
			$goo_threshold = (int) ($thresholds['google_quality'] ?? 0);
			$rel_score = (int) ($payload['_meta']['release_quality']['score'] ?? 0);
			$goo_score = (int) ($payload['_meta']['google_quality']['score'] ?? 0);

			$notes = self::row_notes($row);
			$sys = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$quarantine = trim((string) ($sys['quarantine_reason'] ?? ''));

			$below_quality = ($rel_threshold > 0 && $rel_score < $rel_threshold)
				|| ($goo_threshold > 0 && $goo_score < $goo_threshold);

			if (! $below_quality && $quarantine === '') continue;

			$reason_msg = $below_quality
				? "Quality ниже порога $content_kind: release=$rel_score/$rel_threshold, google=$goo_score/$goo_threshold."
				: "Carry-over quarantine: $quarantine.";
			self::mark_state((int) $row->id, 'manual_review', [
				'error_message' => 'Материал застрял в очереди публикации (gate отклонял каждый тик). ' . $reason_msg . ' Требует ручной правки или ребилда.',
			]);
			$moved++;
		}
		if ($moved > 0 && class_exists('EPV2_Logger')) {
			EPV2_Logger::info('queue', "sanitize_stuck_ready_publish_items: $moved items → manual_review");
		}
		return $moved;
	}

	/**
	 * State='publishing' is the window where mark_state('publishing') has
	 * fired but publish_item() has not yet reached state_for_post_statuses.
	 * Normally this lasts seconds. If an upstream timeout (FastCGI) or a
	 * fatal kills the PHP process mid-publish, the row stays in 'publishing'
	 * forever — neither watchdog (handles only 'processing_de'/'retry_*'
	 * legs) nor sanitize_stuck_ready_publish_items (handles only
	 * 'ready_publish' rows) cover it.
	 *
	 * Recovery rule:
	 *   - post_id NULL  → no posts were created yet, safe to bounce back to
	 *                     'ready_publish' for a fresh attempt.
	 *   - post_id set   → at least one language post exists. Leave it; the
	 *                     watchdog's repair_polylang_links + post_audit
	 *                     pipeline finishes the bundle on the next tick.
	 */
	public static function sanitize_stuck_publishing_items(int $limit = 50): int {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
			 WHERE state='publishing'
			   AND (post_id IS NULL OR post_id = 0)
			   AND updated_at < DATE_SUB(NOW(), INTERVAL 8 MINUTE)
			 ORDER BY updated_at ASC
			 LIMIT %d",
			max(1, min(200, $limit))
		));
		if (! is_array($rows) || $rows === []) return 0;

		$active_owner_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		$moved = 0;
		foreach ($rows as $row) {
			if (! ($row instanceof stdClass)) continue;
			$row_id = (int) ($row->id ?? 0);
			// Race guard: if publisher just claimed this row in the current
			// tick, leave it alone — its updated_at would already be fresh
			// and excluded by the 8-minute filter, but defence-in-depth.
			if ($active_owner_id > 0 && $row_id === $active_owner_id) continue;
			self::mark_state($row_id, 'ready_publish', [
				'error_message' => 'Watchdog: state="publishing" >8 min с post_id=NULL — publish_item() не завершился (вероятно upstream timeout или fatal). Сброс в ready_publish для повторной попытки.',
			]);
			$moved++;
		}
		if ($moved > 0 && class_exists('EPV2_Logger')) {
			EPV2_Logger::info('queue', "sanitize_stuck_publishing_items: $moved items → ready_publish");
		}
		return $moved;
	}

	/**
	 * State='processing_de' is the window where orchestrator claimed an
	 * item and worker is generating DE master. Normally this lasts 30-90
	 * seconds. If the worker process crashes, dies mid-call, or upstream
	 * (OpenAI/DeepSeek) hangs past timeout AND release_stuck_active_item
	 * (which only fires when option `epv2_active_automation_item` points
	 * to the stuck row) misses it — the row stays in processing_de forever.
	 *
	 * This becomes a category-cap blocker (queue_new_max_per_category counts
	 * processing_de active load, so 1 stuck item per rubric blocks ingest
	 * for that rubric).
	 *
	 * Recovery rule: state='processing_de' AND updated_at < NOW - 30min
	 * AND option-pointer DOESN'T point to this row → reset to 'new' so
	 * the orchestrator can re-claim on the next tick. CAS-guarded: skip
	 * if active_id matches (release_stuck_active_item is the canonical
	 * handler in that case).
	 */
	public static function sanitize_stuck_processing_de_items(int $limit = 20): int {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
			 WHERE state='processing_de'
			   AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
			 ORDER BY updated_at ASC
			 LIMIT %d",
			max(1, min(100, $limit))
		));
		if (! is_array($rows) || $rows === []) return 0;

		$active_owner_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		$moved = 0;
		foreach ($rows as $row) {
			if (! ($row instanceof stdClass)) continue;
			$row_id = (int) ($row->id ?? 0);
			// Defer to release_stuck_active_item when the pointer matches
			// — that's the canonical handler and it does its own CAS check.
			if ($active_owner_id > 0 && $row_id === $active_owner_id) continue;
			$notes = self::row_notes($row);
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$notes['_system']['workflow_owner_token'] = '';
			$notes['_system']['workflow_heartbeat_at'] = '';
			$notes['_system']['workflow_step'] = '';
			$notes['_system']['workflow_step_status'] = '';
			self::mark_state($row_id, 'new', [
				'error_message' => 'Watchdog: state="processing_de" >30 мин без active_pointer — worker завис или процесс убит. Сброс в new для повторной попытки.',
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			]);
			$moved++;
		}
		if ($moved > 0 && class_exists('EPV2_Logger')) {
			EPV2_Logger::info('queue', "sanitize_stuck_processing_de_items: $moved items → new");
		}
		return $moved;
	}

	/**
	 * Operator-агреемент 2026-05-09: «всё что больше 80 чисти». Чтобы admin
	 * (heavy path с лимитом 80 items по created_at) видел все живые секции
	 * без вытеснения, периодически удаляем самые старые rejected / error /
	 * duplicate items, оставляя только последние $keep штук в каждом из
	 * этих terminal-состояний. Published не трогаем (там post_id ссылка
	 * на актуальный пост, archive безопасен).
	 */
	public static function trim_old_terminal_items(int $keep = 80): int {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$total_deleted = 0;
		foreach (['rejected', 'error', 'duplicate'] as $state) {
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT id FROM {$table} WHERE state=%s ORDER BY updated_at DESC, id DESC LIMIT %d, 1000",
				$state,
				$keep
			));
			if (! $rows) continue;
			foreach ((array) $rows as $r) {
				$wpdb->delete($table, ['id' => (int) $r->id], ['%d']);
				$total_deleted++;
			}
		}
		if ($total_deleted > 0 && class_exists('EPV2_Logger')) {
			EPV2_Logger::info('queue', "trim_old_terminal_items: удалено $total_deleted (keep=$keep)");
		}
		return $total_deleted;
	}

	private static function force_reject_zombie_pre_ai_rejects(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$sql = $wpdb->prepare(
			"SELECT id, state, error_message FROM {$table}
			 WHERE state IN ('new','retry_process','processing_de','reserve','manual_review','ready_review')
			   AND error_message LIKE %s
			 LIMIT 200",
			'%снят из автоматической очереди: предварительный publish-priority%'
		);
		$rows = (array) $wpdb->get_results($sql);
		$changed = 0;
		foreach ($rows as $r) {
			self::mark_state((int) $r->id, 'rejected', [
				'error_message' => (string) $r->error_message,
			]);
			$changed++;
		}
		return $changed;
	}

	public static function reactivate_planner_selected_soft_rejected_items(int $limit = 50): int {
		if (self::automation_requires_publish_grade()) {
			return 0;
		}
		$items = self::get_queue_items_summary(['states' => ['rejected'], 'limit' => max(1, min(200, $limit))]);
		if ($items === []) {
			return 0;
		}

		$changed = 0;
		foreach ($items as $item) {
			if (! ($item instanceof stdClass) || (string) ($item->state ?? '') !== 'rejected') {
				continue;
			}
			if (! self::planner_selected_soft_candidate($item)) {
				continue;
			}
			$created_at = strtotime((string) ($item->created_at ?? '')) ?: 0;
			if ($created_at <= 0 || $created_at < (time() - DAY_IN_SECONDS)) {
				continue;
			}
			$error = mb_strtolower((string) ($item->error_message ?? ''));
			if (! str_contains($error, 'предварительный publish-priority') && ! str_contains($error, 'selection decision')) {
				continue;
			}
			$notes = self::row_notes($item);
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			unset($notes['_system']['workflow_not_before'], $notes['_system']['retry_after']);
			self::mark_state((int) $item->id, 'new', [
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				'error_message' => 'Возвращено автоматикой: planner выбрал материал, soft low-score больше не считается причиной для отклонения.',
			]);
			self::update_fields((int) $item->id, [
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			]);
			$changed++;
		}

		return $changed;
	}

	public static function sanitize_low_grade_ready_publish_items(int $limit = 50): int {
		$items = self::get_queue_items_summary(['states' => ['ready_publish', 'retry_publish'], 'limit' => max(1, min(500, $limit))]);
		if ($items === []) {
			return 0;
		}

		$changed = 0;
		foreach ($items as $item) {
			if (! self::row_has_non_publish_grade_selection($item)) {
				continue;
			}
			$payload = self::row_payload($item);
			if ($payload !== []) {
				$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
			}
			$decision = self::row_selection_decision($item);
			self::mark_state((int) $item->id, 'rejected', [
				'ai_payload' => $payload !== [] ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : (string) ($item->ai_payload ?? ''),
				'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($item->category_final ?: $item->category_proposed),
				'error_message' => 'Материал снят из очереди публикации: selection decision "' . ($decision !== '' ? $decision : 'unknown') . '" не допускает автоматическую публикацию.',
			]);
			$changed++;
		}

		return $changed;
	}

	public static function sanitize_duplicate_ready_publish_items(int $limit = 100): int {
		return 0;
	}

	public static function normalize_terminal_retry_process_items(int $limit = 25): int {
		if (self::automation_requires_publish_grade()) {
			return 0;
		}
		$items = self::get_queue_items_summary(['states' => ['retry_process'], 'limit' => max(1, min(100, $limit))]);
		if ($items === []) {
			return 0;
		}

		$changed = 0;
		foreach ($items as $item) {
			if (EPV2_AI_Processor::item_has_expired_live_angle($item)) {
				self::mark_state((int) $item->id, 'rejected', [
					'error_message' => 'Материал потерял актуальность по времени и live-углу, поэтому снят из автоматической очереди.',
				]);
				$changed++;
				continue;
			}

			if (! self::retry_process_is_exhausted($item) && ! self::review_rebuild_exhausted($item)) {
				continue;
			}

			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
			$score = self::score_from_row((array) $item);

			if ($score >= 55 || $payload !== []) {
				self::mark_state((int) $item->id, 'ready_review', [
					'error_message' => 'Материал не достиг publish-grade после нескольких автоматических попыток и переведён в редакционную доработку.',
				]);
			} else {
				self::mark_state((int) $item->id, 'rejected', [
					'error_message' => 'Материал снят из автоматической очереди после нескольких слабых безрезультатных попыток.',
				]);
			}
			$changed++;
		}

		return $changed;
	}

	public static function quarantine_pathological_workflow_loops(int $limit = 100): array {
		$items = self::get_queue_items_summary([
			'states' => ['new', 'retry_process'],
			'limit' => max(1, min(500, $limit)),
		]);
		$result = [
			'checked' => 0,
			'changed' => 0,
			'items' => [],
		];
		foreach ($items as $item) {
			if (! ($item instanceof stdClass)) {
				continue;
			}
			$result['checked']++;
			$system = self::workflow_system_payload($item);
			$status = sanitize_key((string) ($system['workflow_step_status'] ?? ''));
			if (in_array($status, ['claimed', 'running'], true) && self::workflow_owner_token($item) !== '') {
				continue;
			}
			$payload = self::row_payload($item);
			if ($payload !== []) {
				$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
			}
			$gate = EPV2_Publish_Gate::evaluate($item, $payload, [
				'context' => 'ready_publish',
			]);
			$stage = sanitize_key((string) ($system['workflow_step'] ?? ''));
			if ($stage === '') {
				$stage = self::row_processing_stage($item);
			}
			$attempts = max(
				(int) ($system['workflow_step_attempts'] ?? 0),
				self::recent_process_attempt_count((int) $item->id, $stage, 24 * HOUR_IN_SECONDS)
			);
			$selection_blocked = empty($gate['selection_publishable']);
			// Don't quarantine items whose ingest score / upfront story
			// card already cleared the publish bar. The worker's rebuild
			// drift can briefly relabel `_meta.selection.decision` as low
			// when it sees a thin Google-News stub, but ingest scored the
			// item at C-tier+ and the card said publishable_estimate=high.
			// Treat those signals as authoritative — quarantine is for
			// genuinely-rejected content, not for a transient mid-pipeline
			// re-score.
			if ($selection_blocked) {
				$ingest_score = (int) ($item->story_score ?? 0);
				$card_estimate = '';
				if (is_array($payload['_meta']['story_card'] ?? null)) {
					$card_estimate = strtolower((string) ($payload['_meta']['story_card']['publishable_estimate'] ?? ''));
				}
				$strong_ingest = $ingest_score >= 40;
				$strong_card = in_array($card_estimate, ['high', 'medium'], true);
				if ($strong_ingest || $strong_card) {
					$selection_blocked = false;
				}
			}
			$limit_for_stage = self::workflow_stage_attempt_limit($stage);
			$retry_exhausted = $stage !== '' && $attempts >= $limit_for_stage;
			$retry_at = self::workflow_not_before_timestamp($item);
			$stale_retry = $retry_at > 0 && $retry_at < (time() - 15 * MINUTE_IN_SECONDS);
			if (! $selection_blocked && ! $retry_exhausted && ! $stale_retry) {
				continue;
			}
			// Rescue path: an item that already passes every publish-gate
			// check should not be quarantined just because its workflow
			// heartbeat went stale or its retry timer fired. The watchdog
			// previously treated any expired stale_retry / unknown-stage
			// row as failed and routed publish-ready items to manual_review,
			// where they sat invisible and burned tokens on every reset.
			// If the gate evaluation is `allowed`, promote to ready_publish
			// so the publisher picks it up on the next slot.
			if (! $selection_blocked && ! empty($gate['allowed'])) {
				EPV2_AI_Processor::transition_item_to_ready_publish((int) $item->id, $payload, [
					'error_message' => '',
				]);
				self::clear_active_automation_item((int) $item->id);
				$result['changed']++;
				$result['items'][] = [
					'id' => (int) $item->id,
					'state' => 'ready_publish',
					'stage' => $stage,
					'attempts' => $attempts,
					'reason' => 'gate_passes_after_stale_or_retry',
				];
				continue;
			}
			$notes = self::row_notes($item);
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$notes['_system']['workflow_terminal_reason'] = $selection_blocked ? 'selection_publish_blocked' : 'workflow_quarantine';
			$notes['_system']['quarantine_reason'] = $selection_blocked
				? 'selection_' . sanitize_key((string) ($gate['selection_decision'] ?? 'blocked'))
				: 'stage_attempt_limit_' . ($stage !== '' ? $stage : 'unknown');
			$notes['_system']['last_stage_blocker'] = implode(',', array_values((array) ($gate['blockers'] ?? [])));
			$notes['_system']['next_operator_action'] = $selection_blocked ? 'review_source_or_restore_manually' : 'inspect_payload_or_reset_stage_after_fix';
			$notes['_system']['workflow_step_status'] = 'terminal';
			$notes['_system']['workflow_owner_token'] = '';
			$notes['_system']['workflow_heartbeat_at'] = '';
			unset($notes['_system']['retry_after'], $notes['_system']['workflow_not_before']);
			// Phase 2.6 — when stage attempt limit is exhausted, importance
			// score decides between manual_review (operator triages) and
			// rejected (low-value content, do not waste operator time).
			// selection_publish_blocked still always routes to rejected.
			$importance = 0;
			$importance_threshold = EPV2_Importance_Score::DEFAULT_THRESHOLD;
			if (! $selection_blocked && class_exists('EPV2_Importance_Score')) {
				$importance = EPV2_Importance_Score::compute($item, $payload);
			}
			if ($selection_blocked) {
				$state = 'rejected';
			} else {
				$state = $importance >= $importance_threshold ? 'manual_review' : 'rejected';
			}
			$notes['_system']['importance_score'] = $importance;
			$notes['_system']['importance_threshold'] = $importance_threshold;
			if (class_exists('EPV2_Learning_Journal')) {
				$event_type = $state === 'manual_review' ? 'manual_review_landed' : 'quarantine_rejected';
				EPV2_Learning_Journal::record($event_type, (int) $item->id,
					'stage_attempt_limit_' . ($stage !== '' ? $stage : 'unknown'),
					[
						'attempts' => $attempts,
						'limit' => $limit_for_stage,
						'importance' => $importance,
						'last_blocker' => (string) (is_array($gate['blockers'] ?? null) ? implode(',', (array) $gate['blockers']) : ''),
					]
				);
			}
			$message = $selection_blocked
				? 'Материал снят с автопубликации: canonical publish gate заблокировал selection decision "' . (string) ($gate['selection_decision'] ?? 'unknown') . '".'
				: ($state === 'manual_review'
					? 'Материал отправлен на ручную проверку: стадия "' . ($stage !== '' ? $stage : 'unknown') . '" превысила лимит попыток (' . (string) $attempts . '/' . (string) $limit_for_stage . '). Importance score=' . (string) $importance . '/' . (string) $importance_threshold . '.'
					: 'Материал отбракован после исчерпания попыток: стадия "' . ($stage !== '' ? $stage : 'unknown') . '", importance score=' . (string) $importance . ' ниже порога ' . (string) $importance_threshold . '.');
			self::mark_state((int) $item->id, $state, [
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				'error_message' => $message,
			]);
			self::clear_active_automation_item((int) $item->id);
			$result['changed']++;
			$result['items'][] = [
				'id' => (int) $item->id,
				'state' => $state,
				'stage' => $stage,
				'attempts' => $attempts,
				'reason' => $notes['_system']['quarantine_reason'],
			];
		}
		return $result;
	}

	public static function next_item_for_publish(bool $force = false): ?object {
		self::promote_live_published_rows(20);
		$active_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		if ($active_id > 0) {
			$active_item = self::get_item($active_id);
			if ($active_item && in_array((string) ($active_item->state ?? ''), ['ready_publish', 'retry_publish'], true) && self::item_is_publishable_read_only($active_item, $force)) {
				return $active_item;
			}
		}
		$items = self::get_queue_items_summary(['states' => ['ready_publish', 'retry_publish'], 'limit' => 30]);
		$items = array_values(array_filter($items, static function ($item) use ($force): bool {
			return self::item_is_publishable_read_only($item, $force);
		}));
		if ($items === []) {
			return null;
		}
		usort($items, [self::class, 'compare_publish_schedule_order']);
		return $items[0] ?? null;
	}

	public static function next_due_item_for_publish_fast(bool $force = false): ?object {
		global $wpdb;
		self::promote_live_published_rows(20);
		$table = $wpdb->prefix . 'epv2_queue';
		$now = time();
		$due_clause = $force ? '1=1' : $wpdb->prepare(
			"COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.publish_not_before')) AS UNSIGNED), 0) <= %d",
			$now
		);
		$ids = $wpdb->get_col(
			"SELECT id
			FROM {$table}
			WHERE state = 'ready_publish'
				AND post_id IS NULL
				AND {$due_clause}
			ORDER BY COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.publish_not_before')) AS UNSIGNED), 0) ASC, id ASC
			LIMIT 10"
		);
		foreach ((array) $ids as $id) {
			$item = self::get_item((int) $id);
			if (is_object($item) && (string) ($item->state ?? '') === 'ready_publish' && self::item_is_publishable_read_only($item, $force)) {
				return $item;
			}
		}
		return null;
	}

	private static function item_is_publishable_read_only(object $item, bool $force = false): bool {
		if (self::row_has_live_published_posts($item)) {
			return false;
		}
		$payload = self::row_payload($item);
		if ($payload !== []) {
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
		}
		$gate = EPV2_Publish_Gate::evaluate($item, $payload, [
			'context' => 'publish',
			'force' => $force,
		]);
		if (empty($gate['allowed'])) {
			return false;
		}
		// Keep the timer path cheap. The worker has already produced the
		// publish-ready checklist; publish_item() performs the final blocking
		// validation and sends the item back to repair if a real blocker remains.
		if ((string) ($item->state ?? '') === 'retry_publish' && ! EPV2_Resilience_Manager::retry_due($item)) {
			return false;
		}
		return true;
	}

	private static function row_selection_analysis(object $item): array {
		$payload = self::row_payload($item);
		$metaSelection = is_array($payload['_meta']['selection'] ?? null) ? $payload['_meta']['selection'] : [];
		if ($metaSelection !== []) {
			return $metaSelection;
		}
		$notes = self::row_notes($item);
		$selection = is_array($notes['selection'] ?? null) ? $notes['selection'] : [];
		return $selection;
	}

	public static function next_ready_publish_timestamp(bool $includeDeferredByDailyLimit = true): ?int {
		$items = self::get_queue_items_summary(['states' => ['ready_publish', 'retry_publish'], 'limit' => 30]);
		if ($items === []) {
			return null;
		}
		usort($items, [self::class, 'compare_publish_schedule_order']);
		foreach ($items as $item) {
			$notes = self::row_notes($item);
			if (
				! $includeDeferredByDailyLimit
				&& (
					! empty($notes['_system']['publish_deferred_by_daily_limit'])
					|| self::row_is_waiting_on_category_budget($item, $notes)
				)
			) {
				continue;
			}
			$not_before = (int) ($notes['_system']['publish_not_before'] ?? 0);
			if ($not_before > 0) {
				return $not_before;
			}
			// Lazy-инициализация: publish_not_before set'ится только когда
			// публикатор первый раз позовёт publish_due(). До того момента
			// в admin_notes этого поля нет — UI таймер падает на global
			// fallback и сразу истекает. Возвращаем aligned slot от
			// updated_at (момент, когда item стал ready_publish), чтобы
			// таймер показывал реальное время до публикации.
			$state = (string) ($item->state ?? '');
			if (in_array($state, ['ready_publish', 'retry_publish'], true)) {
				$updated_ts = (int) strtotime((string) ($item->updated_at ?? '')) ?: time();
				$slot = (int) EPV2_Jobs::next_publish_slot_after($updated_ts);
				if ($slot > 0) {
					return $slot;
				}
			}
		}
		return null;
	}

	public static function ready_publish_deferred_by_daily_limit_summary(): array {
		$items = self::get_queue_items_summary(['states' => ['ready_publish', 'retry_publish'], 'limit' => 50]);
		if ($items === []) {
			return ['count' => 0, 'next_timestamp' => null];
		}
		usort($items, [self::class, 'compare_publish_schedule_order']);
		$count = 0;
		$nextTimestamp = 0;
		foreach ($items as $item) {
			$notes = self::row_notes($item);
			if (empty($notes['_system']['publish_deferred_by_daily_limit'])) {
				continue;
			}
			$count++;
			$notBefore = (int) ($notes['_system']['publish_not_before'] ?? 0);
			if ($notBefore > 0 && ($nextTimestamp <= 0 || $notBefore < $nextTimestamp)) {
				$nextTimestamp = $notBefore;
			}
		}
		return [
			'count' => $count,
			'next_timestamp' => $nextTimestamp > 0 ? $nextTimestamp : null,
		];
	}

	public static function promote_ready_like_rows(int $limit = 25): int {
		$rows = self::get_queue_items_summary([
			'states' => ['new', 'retry_process', 'ready_review', 'reserve'],
			'limit' => max(1, min(200, $limit)),
		]);
		if ($rows === []) {
			return 0;
		}
		$promoted = 0;
		foreach ($rows as $row) {
			$updated = strtotime((string) ($row->updated_at ?? ''));
			if ($updated > 0 && (time() - $updated) > (6 * HOUR_IN_SECONDS)) {
				continue;
			}
			if (EPV2_AI_Processor::item_has_expired_live_angle($row)) {
				continue;
			}
			if (self::workflow_user_state_for_row($row) !== 'ready_publish') {
				continue;
			}
			$payload = self::row_payload($row);
			if ($payload !== []) {
				$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
			}
			if (EPV2_AI_Processor::transition_item_to_ready_publish((int) $row->id, $payload !== [] ? $payload : (array) json_decode((string) ($row->ai_payload ?? ''), true), [
				'error_message' => '',
			])) {
				$promoted++;
			}
		}
		return $promoted;
	}

	public static function normalize_ready_publish_schedule(bool $allow_current_slot = true, ?int $anchorTimestamp = null): void {
		self::sanitize_low_grade_ready_publish_items(100);
		self::sanitize_duplicate_ready_publish_items(100);
		$items = self::get_queue_items_summary(['states' => ['ready_publish', 'retry_publish', 'publishing'], 'limit' => 50]);
		if ($items === []) {
			return;
		}

		usort($items, [self::class, 'compare_publish_queue_order']);

		$interval = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS;
		$anchored = is_int($anchorTimestamp) && $anchorTimestamp > 0;
		$first_slot = $anchored ? EPV2_Jobs::next_publish_slot_after($anchorTimestamp) : self::first_waiting_publish_slot();
		if ($allow_current_slot && ! $anchored) {
			$next_publish = EPV2_Jobs::next_publish_timestamp();
			if (is_int($next_publish) && $next_publish > time()) {
				$first_slot = $next_publish;
			} else {
				$first_slot = EPV2_Jobs::next_publish_slot_after(time());
			}
		}
		$maxReasonable = $first_slot + ($interval * 8);
		$previous_slot = 0;

			foreach ($items as $item) {
				$payload = [];
				if (in_array((string) ($item->state ?? ''), ['ready_publish', 'retry_publish'], true)) {
					if (EPV2_AI_Processor::item_has_expired_live_angle($item)) {
						$payload = self::row_payload($item);
					if ($payload !== []) {
						$payload = EPV2_AI_Processor::force_payload_pipeline_stage($payload, 'publish_finish');
					}
					self::mark_state((int) $item->id, 'retry_process', [
						'ai_payload' => $payload !== [] ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : '',
						'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($item->category_final ?: $item->category_proposed),
						'error_message' => 'Материал снят из очереди публикации до publish-run: live angle устарел и отправлен в автоматическую финальную доводку.',
					]);
					continue;
				}
				$payload = self::row_payload($item);
				if ($payload !== []) {
					$normalized = EPV2_AI_Processor::normalize_existing_payload($payload, false);
					if ($normalized !== $payload) {
						self::update_fields((int) $item->id, [
							'ai_payload' => wp_json_encode($normalized, JSON_UNESCAPED_UNICODE),
						]);
						$item->ai_payload = wp_json_encode($normalized, JSON_UNESCAPED_UNICODE);
						unset($item->_epv2_payload_cache);
						$payload = $normalized;
					}
						if (! self::workflow_payload_is_ready_like($payload)) {
							if (self::workflow_payload_is_ready_like($payload)) {
								$payload = EPV2_AI_Processor::force_payload_pipeline_stage($payload, 'publish_finish');
							}
							$rework = EPV2_AI_Processor::item_is_auto_rework_candidate($item, $payload);
							$finish = ! $rework && EPV2_AI_Processor::item_is_auto_finish_candidate($item, $payload);
							self::mark_state((int) $item->id, 'retry_process', [
								'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
								'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
								'error_message' => 'Пакет снят из очереди публикации: финальный terminal publish-grade не пройден.',
							]);
							continue;
						}
				}
			}
			$notes = self::row_notes($item);
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$current = (int) ($notes['_system']['publish_not_before'] ?? 0);
			$ready_at = (string) ($notes['_system']['ready_publish_at'] ?? '');
			$publish_limit_override = self::payload_has_publish_limit_override($payload, $item);
			if (
				! empty($notes['_system']['publish_deferred_by_daily_limit'])
				&& EPV2_Time_Planner::publish_budget_allows_item($publish_limit_override)
			) {
				unset($notes['_system']['publish_deferred_by_daily_limit']);
				$current = 0;
			}
			if (! empty($notes['_system']['publish_deferred_by_daily_limit'])) {
				$deferred_slot = EPV2_Jobs::next_publish_slot_after(EPV2_Time_Planner::next_publish_budget_slot_timestamp());
				if ($current !== $deferred_slot) {
					$notes['_system']['ready_publish_at'] = ! empty($notes['_system']['ready_publish_at'])
						? (string) $notes['_system']['ready_publish_at']
						: ((string) ($item->updated_at ?? '') !== '' ? (string) $item->updated_at : gmdate('Y-m-d H:i:s'));
					$notes['_system']['publish_not_before'] = $deferred_slot;
					self::update_fields((int) $item->id, [
						'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
					]);
				}
				$current = $deferred_slot;
			}
			if (
				! empty($notes['_system']['publish_deferred_by_category_limit'])
				&& EPV2_Time_Planner::publish_category_budget_allows_item(self::row_primary_category($item, $payload), $publish_limit_override)
			) {
				unset($notes['_system']['publish_deferred_by_category_limit']);
				$current = 0;
			}
			if (! empty($notes['_system']['publish_deferred_by_category_limit'])) {
				$deferred_slot = EPV2_Jobs::next_publish_slot_after(
					EPV2_Time_Planner::next_category_budget_slot_timestamp(self::row_primary_category($item, $payload))
				);
				if ($current !== $deferred_slot) {
					$notes['_system']['ready_publish_at'] = ! empty($notes['_system']['ready_publish_at'])
						? (string) $notes['_system']['ready_publish_at']
						: ((string) ($item->updated_at ?? '') !== '' ? (string) $item->updated_at : gmdate('Y-m-d H:i:s'));
					$notes['_system']['publish_not_before'] = $deferred_slot;
					self::update_fields((int) $item->id, [
						'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
					]);
				}
				continue;
			}
			$earliest_slot = self::earliest_publish_slot_from_ready_at($ready_at);
			if ($previous_slot <= 0 && $current > 0 && ! $anchored) {
				$scheduled_slot = $current;
				$needsRepair = false;
			} else {
				$minimum_slot = $previous_slot > 0
					? max($previous_slot + $interval, $earliest_slot)
					: max($earliest_slot, $first_slot);
				$needsRepair = $anchored || $current <= 0 || $current < $minimum_slot;
				$scheduled_slot = $needsRepair ? $minimum_slot : $current;
			}
			if ($needsRepair) {
				$notes['_system']['ready_publish_at'] = ! empty($notes['_system']['ready_publish_at'])
					? (string) $notes['_system']['ready_publish_at']
					: ((string) ($item->updated_at ?? '') !== '' ? (string) $item->updated_at : gmdate('Y-m-d H:i:s'));
				$notes['_system']['publish_not_before'] = $scheduled_slot;
				self::update_fields((int) $item->id, [
					'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				]);
				$current = $scheduled_slot;
			}
			$previous_slot = $current > 0 ? $current : $scheduled_slot;
		}
		}

		public static function reanchor_ready_publish_schedule_after_publish(?int $published_at = null): void {
			$items = self::get_queue_items_summary(['states' => ['ready_publish', 'retry_publish'], 'limit' => 50]);
			if ($items === []) {
				return;
			}

			usort($items, [self::class, 'compare_publish_queue_order']);

			$interval = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS;
			$slot = max(time(), $published_at ?: time()) + $interval;
			foreach ($items as $item) {
				$notes = self::row_notes($item);
				$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
				if (self::row_is_waiting_on_category_budget($item, $notes)) {
					$payload = self::row_payload($item);
					$notes['_system']['ready_publish_at'] = ! empty($notes['_system']['ready_publish_at'])
						? (string) $notes['_system']['ready_publish_at']
						: ((string) ($item->updated_at ?? '') !== '' ? (string) $item->updated_at : gmdate('Y-m-d H:i:s'));
					$notes['_system']['publish_not_before'] = EPV2_Jobs::next_publish_slot_after(
						EPV2_Time_Planner::next_category_budget_slot_timestamp(self::row_primary_category($item, $payload))
					);
					self::update_fields((int) $item->id, [
						'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
					]);
					continue;
				}
				$notes['_system']['ready_publish_at'] = ! empty($notes['_system']['ready_publish_at'])
					? (string) $notes['_system']['ready_publish_at']
					: ((string) ($item->updated_at ?? '') !== '' ? (string) $item->updated_at : gmdate('Y-m-d H:i:s'));
				$notes['_system']['publish_not_before'] = $slot;
				self::update_fields((int) $item->id, [
					'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				]);
				$slot += $interval;
			}
		}
	
		public static function mark_state(int $id, string $state, array $extra = []): void {
		global $wpdb;
		$current = self::get_item_summary($id);
		if (! $current) {
			return;
		}
		// Central per-provider AI usage accounting. Any mark_state call that
		// persists a fresh ai_payload counts as a worker round-trip — read
		// the token total from _meta.ai_runtime[]/_meta.tokens directly so
		// callers that omit the ai_tokens column field (e.g.
		// transition_item_to_ready_publish) still feed the daily counter
		// and the per-provider Settings widget.
		// Memoize the incoming ai_payload decode — same JSON gets re-parsed
		// later (line ~2024 for ready_publish path) and that doubles cost
		// for 100-300 KB payloads on hot mark_state path.
		$incoming_payload_decoded = null;
		if (isset($extra['ai_payload'])) {
			$incoming_payload_decoded = json_decode((string) $extra['ai_payload'], true);
			if (! is_array($incoming_payload_decoded)) {
				$incoming_payload_decoded = null;
			}
		}
		if (class_exists('EPV2_Stats') && $incoming_payload_decoded !== null) {
			$bump_payload = $incoming_payload_decoded;
			$_meta = is_array($bump_payload['_meta'] ?? null) ? $bump_payload['_meta'] : [];
			$_runtime = is_array($_meta['ai_runtime'] ?? null) ? $_meta['ai_runtime'] : [];
			$_runtime_tokens = 0;
			foreach ($_runtime as $entry) {
				if (is_array($entry)) {
					$_runtime_tokens += max(0, (int) ($entry['tokens'] ?? 0));
				}
			}
			$_meta_total = (int) ($_meta['tokens'] ?? 0);
			if ($_runtime_tokens > 0 || $_meta_total > 0) {
				EPV2_Stats::record_payload_ai_usage($bump_payload);
			}
		}
		// Central terminal-state guard. Eight independent code paths used to
		// call mark_state(rejected) or mark_state(error) directly: worker
		// blocker terminalization, workflow_quarantine selection_blocked,
		// workflow_quarantine retry_exhausted, normalize_non_active_recoverable,
		// stale_time_sensitive, publisher media-fail, resilience-manager
		// retry-exhausted, and the older repair sites. Every one of those
		// could mass-reject items on a transient mid-pipeline issue. This
		// single guard converts intended-rejection / intended-error to
		// `ready_review` whenever the row carries a salvageable signal —
		// ingest score >= 30 OR upfront story_card flagged the piece as
		// publishable_estimate=high|medium — UNLESS the rejection reason
		// is a genuinely-hard editorial block (duplicate, stale time-
		// sensitive, hard_editorial_block, context_reject, sport_fixture).
		// Operator triages ready_review manually; nothing gets silently
		// killed.
		if (in_array($state, ['rejected', 'error'], true)) {
			$state = self::soft_terminal_state_guard((int) $id, $state, $extra, $current);
		}
		$state = self::canonicalize_single_workflow_state($id, $state, $current, $extra);
		if ($state === 'ready_publish') {
			// Reuse the already-decoded payload when the caller passed one
			// via $extra['ai_payload'] (memoized above for Stats accounting).
			// Falls back to fresh decode of $current->ai_payload otherwise.
			if (array_key_exists('ai_payload', $extra) && $incoming_payload_decoded !== null) {
				$payload = $incoming_payload_decoded;
			} else {
				$payload_json = array_key_exists('ai_payload', $extra) ? (string) $extra['ai_payload'] : (string) ($current->ai_payload ?? '');
				$payload = json_decode($payload_json, true);
				$payload = is_array($payload) ? $payload : [];
			}
				if ($payload !== []) {
					$payload = EPV2_AI_Processor::force_payload_pipeline_stage($payload, '');
					$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
				}
				$incoming_notes = [];
				if (! empty($extra['admin_notes'])) {
					$incoming_notes = json_decode((string) $extra['admin_notes'], true);
					$incoming_notes = is_array($incoming_notes) ? $incoming_notes : [];
				}
				$gate = EPV2_Publish_Gate::evaluate($current, $payload, [
					'context' => 'ready_publish',
				]);
				if (empty($gate['allowed'])) {
					$incoming_notes = is_array($incoming_notes ?? null) ? $incoming_notes : [];
					$incoming_notes['_system'] = is_array($incoming_notes['_system'] ?? null) ? $incoming_notes['_system'] : [];
					$incoming_notes['_system']['last_publish_gate_blockers'] = array_values((array) ($gate['blockers'] ?? []));
					$incoming_notes['_system']['workflow_step'] = '';
					$incoming_notes['_system']['workflow_step_status'] = '';
					if (empty($gate['selection_publishable'])) {
						$state = 'rejected';
						$incoming_notes['_system']['workflow_terminal_reason'] = 'selection_publish_blocked';
						$incoming_notes['_system']['quarantine_reason'] = 'selection_' . sanitize_key((string) ($gate['selection_decision'] ?? 'blocked'));
						$incoming_notes['_system']['next_operator_action'] = 'review_source_or_restore_manually';
						$extra['error_message'] = 'Материал снят с автопубликации: canonical publish gate заблокировал selection decision "' . (string) ($gate['selection_decision'] ?? 'unknown') . '".';
					} else {
						$state = 'retry_process';
						$incoming_notes['_system']['retry_after'] = gmdate('Y-m-d H:i:s', time() + (30 * MINUTE_IN_SECONDS));
						$incoming_notes['_system']['workflow_not_before'] = $incoming_notes['_system']['retry_after'];
						$incoming_notes['_system']['last_stage_blocker'] = implode(',', array_values((array) ($gate['blockers'] ?? [])));
						$extra['error_message'] = 'Пакет снят из ready_publish: canonical publish gate не пройден (' . implode(', ', array_values((array) ($gate['blockers'] ?? []))) . ').';
					}
					$extra['category_final'] = implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($current->category_final ?? $current->category_proposed ?? '');
					$extra['admin_notes'] = wp_json_encode($incoming_notes, JSON_UNESCAPED_UNICODE);
					$extra['ai_payload'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
			} elseif ($payload !== []) {
				$extra['ai_payload'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
			}
		}
		if ($state === 'published') {
			$payload_json = array_key_exists('ai_payload', $extra) ? (string) $extra['ai_payload'] : (string) ($current->ai_payload ?? '');
			$payload = json_decode($payload_json, true);
			$payload = is_array($payload) ? $payload : [];
			if ($payload !== []) {
				$payload = EPV2_AI_Processor::force_payload_pipeline_stage($payload, '');
				$extra['ai_payload'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
			}
		}
		$notes = json_decode((string) ($current->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		if (! empty($extra['admin_notes'])) {
			$incoming = json_decode((string) $extra['admin_notes'], true);
			if (is_array($incoming)) {
				$notes = array_replace_recursive($notes, $incoming);
			}
		}
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		if (
			in_array((string) ($notes['_system']['manual_confirmation_required'] ?? ''), ['media', 'translation'], true)
			&& in_array($state, ['processing_de', 'retry_process'], true)
		) {
			$state = 'ready_review';
		}
		if ($state === 'ready_publish') {
			$existing_not_before = (int) ($notes['_system']['publish_not_before'] ?? 0);
			$existing_ready_at = (string) ($notes['_system']['ready_publish_at'] ?? '');
			$current_state = (string) ($current->state ?? '');
			$is_existing_ready = in_array($current_state, ['ready_publish', 'retry_publish', 'publishing'], true);
			$incoming_payload_json = array_key_exists('ai_payload', $extra) ? (string) $extra['ai_payload'] : (string) ($current->ai_payload ?? '');
			$incoming_payload = json_decode($incoming_payload_json, true);
			$incoming_payload = is_array($incoming_payload) ? $incoming_payload : [];
			if ($incoming_payload !== []) {
				$incoming_payload = EPV2_AI_Processor::normalize_existing_payload($incoming_payload, false);
			}
			$publish_limit_override = self::payload_has_publish_limit_override($incoming_payload, $current);
			if (! $is_existing_ready && ! EPV2_Time_Planner::publish_budget_allows_item($publish_limit_override)) {
				$notes['_system']['ready_publish_at'] = gmdate('Y-m-d H:i:s');
				$notes['_system']['publish_not_before'] = EPV2_Jobs::next_publish_slot_after(EPV2_Time_Planner::next_publish_budget_slot_timestamp());
				$notes['_system']['publish_deferred_by_daily_limit'] = true;
			} elseif (
				! $is_existing_ready
				&& ! EPV2_Time_Planner::publish_category_budget_allows_item(self::row_primary_category($current, $incoming_payload), $publish_limit_override)
			) {
				$notes['_system']['ready_publish_at'] = gmdate('Y-m-d H:i:s');
				$notes['_system']['publish_not_before'] = EPV2_Jobs::next_publish_slot_after(
					EPV2_Time_Planner::next_category_budget_slot_timestamp(self::row_primary_category($current, $incoming_payload))
				);
				$notes['_system']['publish_deferred_by_category_limit'] = true;
			} elseif ($current_state === 'ready_publish' && $existing_not_before > 0) {
				$notes['_system']['ready_publish_at'] = $existing_ready_at !== '' ? $existing_ready_at : gmdate('Y-m-d H:i:s');
				$notes['_system']['publish_not_before'] = $existing_not_before;
			} else {
				$notes['_system']['ready_publish_at'] = gmdate('Y-m-d H:i:s');
				$notes['_system']['publish_not_before'] = self::next_publish_slot_for_queue($id);
				unset($notes['_system']['publish_deferred_by_daily_limit'], $notes['_system']['publish_deferred_by_category_limit']);
			}
			if (empty($extra['admin_notes'])) {
				$extra['admin_notes'] = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
			}
			if (! array_key_exists('error_message', $extra)) {
				$extra['error_message'] = '';
			}
		} elseif ($state === 'published') {
			unset($notes['_system']['publish_not_before'], $notes['_system']['ready_publish_at'], $notes['_system']['publish_deferred_by_daily_limit']);
			if (! array_key_exists('error_message', $extra)) {
				$extra['error_message'] = '';
			}
		} elseif (! in_array($state, ['ready_publish', 'retry_publish', 'publishing'], true)) {
			unset($notes['_system']['publish_not_before'], $notes['_system']['ready_publish_at'], $notes['_system']['publish_deferred_by_daily_limit']);
		}
		$preserve_retry_after_for_v2_new =
			self::orchestrator_v2_enabled()
			&& $state === 'new'
			&& (
				(string) ($notes['_system']['retry_after'] ?? '') !== ''
				|| (string) ($notes['_system']['workflow_step'] ?? '') !== ''
			);
		if (! in_array($state, ['retry_process', 'retry_publish'], true) && ! $preserve_retry_after_for_v2_new) {
			unset($notes['_system']['retry_after']);
		}
		if (in_array($state, ['ready_publish', 'published', 'rejected', 'error', 'duplicate'], true)) {
			$notes['_system']['workflow_step'] = '';
			$notes['_system']['workflow_step_status'] = '';
			$notes['_system']['workflow_owner_token'] = '';
			$notes['_system']['workflow_heartbeat_at'] = '';
			if (in_array($state, ['published', 'rejected', 'error', 'duplicate'], true)) {
				$notes['_system']['workflow_terminal_reason'] = ! empty($notes['_system']['workflow_terminal_reason'])
					? sanitize_key((string) $notes['_system']['workflow_terminal_reason'])
					: $state;
			}
		}
		$active_live_states = ['processing_de', 'publishing'];
		if (in_array($state, $active_live_states, true)) {
			$notes['_system']['live_status'] = self::default_live_status_for_state($state);
			$notes['_system']['live_status_code'] = $state;
		} else {
			unset($notes['_system']['live_status'], $notes['_system']['live_status_code']);
		}
		if (in_array($state, ['published', 'rejected', 'error', 'duplicate'], true)) {
			unset($notes['_system']['live_status'], $notes['_system']['live_status_code']);
		}
		if (empty($extra['admin_notes'])) {
			$extra['admin_notes'] = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
		} else {
			$extra['admin_notes'] = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
		}
		$data = array_merge(['state' => $state], $extra);
		self::guard_payload_field_sizes($id, $data);
		$wpdb->update($wpdb->prefix . 'epv2_queue', $data, ['id' => $id]);
		self::sync_active_automation_item($id, $state);
	}

	public static function set_active_automation_item(int $id): void {
		if ($id <= 0) {
			return;
		}
		$current = self::get_item_summary($id);
		if ($current) {
			$existing_token = self::workflow_owner_token($current);
			self::workflow_system_update($id, [
				'workflow_owner_token' => $existing_token !== '' ? $existing_token : wp_generate_password(24, false, false),
				'workflow_claimed_at' => gmdate('Y-m-d H:i:s'),
				'workflow_heartbeat_at' => gmdate('Y-m-d H:i:s'),
				'workflow_step_status' => 'claimed',
			]);
		}
		update_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, $id, false);
	}

	public static function clear_active_automation_item(int $id = 0): void {
		$current = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		if ($id > 0 && $current !== $id) {
			return;
		}
		if ($current > 0) {
			self::workflow_system_update($current, [
				'workflow_owner_token' => '',
				'workflow_heartbeat_at' => '',
				'workflow_step_status' => '',
			]);
		}
		delete_option(self::OPTION_ACTIVE_AUTOMATION_ITEM);
	}

	public static function active_owner_get(): ?object {
		$item = self::active_owner_pointer_item();
		if ($item instanceof stdClass) {
			return $item;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$item = $wpdb->get_row(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
			WHERE JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.workflow_owner_token')) <> ''
			ORDER BY updated_at DESC
			LIMIT 1"
		);
		if ($item && self::finalize_ready_publish_owned_row($item)) {
			return null;
		}
		if ($item && self::current_row_should_keep_active_pointer($item)) {
			update_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, (int) $item->id, false);
			return $item;
		}
		return null;
	}

	public static function active_owner_claim(int $item_id): bool {
		$item = self::get_item_summary($item_id);
		if (! $item) {
			return false;
		}
		$current = self::active_owner_pointer_item();
		if ($current && (int) ($current->id ?? 0) !== $item_id) {
			return false;
		}
		self::set_active_automation_item($item_id);
		return true;
	}

	public static function active_owner_heartbeat(int $item_id): void {
		$item = self::get_item_summary($item_id);
		if (! $item || self::workflow_owner_token($item) === '') {
			return;
		}
		self::workflow_system_update($item_id, [
			'workflow_heartbeat_at' => gmdate('Y-m-d H:i:s'),
			'workflow_step_status' => 'running',
		]);
	}

	public static function active_owner_release(int $item_id, string $outcome = ''): void {
		$item = self::get_item_summary($item_id);
		if (! $item) {
			return;
		}
		self::workflow_system_update($item_id, [
			'workflow_owner_token' => '',
			'workflow_heartbeat_at' => '',
			'workflow_step_status' => $outcome !== '' ? sanitize_key($outcome) : '',
			'workflow_terminal_reason' => in_array($outcome, ['ready_publish', 'published', 'rejected', 'error_terminal'], true) ? sanitize_key($outcome) : (string) (self::workflow_system_payload($item)['workflow_terminal_reason'] ?? ''),
		]);
		self::clear_active_automation_item($item_id);
	}

	public static function active_owner_is_stale(object $item): bool {
		$system = self::workflow_system_payload($item);
		$token = (string) ($system['workflow_owner_token'] ?? '');
		if ($token === '') {
			return false;
		}
		$heartbeat = strtotime((string) ($system['workflow_heartbeat_at'] ?? '')) ?: 0;
		$ttl = max(300, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
		$stale_after = max(90, min(300, (int) floor($ttl / 3)));
		return $heartbeat <= 0 || ($heartbeat + $stale_after) <= time();
	}

	public static function active_owner_recover_stale(): int {
		$item = self::active_owner_pointer_item();
		if (! $item || ! self::active_owner_is_stale($item)) {
			return 0;
		}
		$system = self::workflow_system_payload($item);
		$workflow_step = sanitize_key((string) ($system['workflow_step'] ?? ''));
		$heartbeat = (string) ($system['workflow_heartbeat_at'] ?? '');
		self::workflow_system_update((int) $item->id, [
			'workflow_owner_token' => '',
			'workflow_step' => $workflow_step,
			'workflow_step_status' => 'stale_recovered',
			'workflow_heartbeat_at' => '',
			'workflow_recovered_at' => gmdate('Y-m-d H:i:s'),
			'workflow_recovery_reason' => 'stale_owner',
			'workflow_last_error' => $heartbeat !== ''
				? 'Active owner was recovered after stale heartbeat at ' . $heartbeat . '.'
				: 'Active owner was recovered after missing workflow heartbeat.',
		]);
		delete_option(self::OPTION_ACTIVE_AUTOMATION_ITEM);
		return (int) $item->id;
	}

	private static function active_owner_pointer_item(): ?object {
		$active_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		if ($active_id <= 0) {
			return null;
		}
		$item = self::get_item_summary($active_id);
		if (! ($item instanceof stdClass)) {
			delete_option(self::OPTION_ACTIVE_AUTOMATION_ITEM);
			return null;
		}
		if (self::workflow_owner_token($item) === '') {
			delete_option(self::OPTION_ACTIVE_AUTOMATION_ITEM);
			return null;
		}
		if (self::row_has_live_published_posts($item)) {
			delete_option(self::OPTION_ACTIVE_AUTOMATION_ITEM);
			return null;
		}
		$state = self::normalize_legacy_publish_state((string) ($item->state ?? ''));
		if (self::workflow_is_terminal_state($state) || in_array($state, ['ready_publish', 'retry_publish', 'publishing'], true)) {
			delete_option(self::OPTION_ACTIVE_AUTOMATION_ITEM);
			return null;
		}
		return $item;
	}

	public static function normalize_non_active_recoverable_items(): void {
		global $wpdb;
		self::normalize_active_automation_pointer();
		self::sanitize_non_publish_grade_new_items(150);
		self::normalize_staged_new_items();
		self::normalize_duplicate_recoverable_original_urls();
		self::trim_old_terminal_items(80);
		$active_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		$table = $wpdb->prefix . 'epv2_queue';
		$states = ['processing_de', 'reserve', 'retry_process', 'ready_review'];
		$placeholders = implode(',', array_fill(0, count($states), '%s'));
		$params = $states;
		$sql = "SELECT " . self::SUMMARY_FIELDS . " FROM {$table} WHERE state IN ({$placeholders})";
		if ($active_id > 0) {
			$sql .= " AND id != %d";
			$params[] = $active_id;
		}
		$rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
		foreach ((array) $rows as $row) {
			if (self::row_notes_require_manual_confirmation($row)) {
				continue;
			}
			if (self::workflow_user_state_for_row($row) !== 'new') {
				continue;
			}
			$notes = json_decode((string) ($row->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			unset(
				$notes['_system']['live_status'],
				$notes['_system']['live_status_code'],
				$notes['_system']['publish_not_before'],
				$notes['_system']['ready_publish_at']
			);
			$wpdb->update($table, [
				'state' => 'new',
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			], ['id' => (int) $row->id]);
		}
	}

	public static function set_live_status(int $id, string $message, string $code = ''): void {
		$current = self::get_item_summary($id);
		if (! $current) {
			return;
		}
		$notes = json_decode((string) ($current->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['live_status'] = sanitize_text_field($message);
		if ($code !== '') {
			$notes['_system']['live_status_code'] = sanitize_key($code);
		}
		self::update_fields($id, [
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
	}

	private static function next_publish_slot_for_queue(int $item_id): int {
		$interval_minutes = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5));
		$base_slot = self::first_waiting_publish_slot();
		$items = self::get_queue_items_summary(['states' => ['ready_publish', 'retry_publish', 'publishing'], 'limit' => 50]);
		$latest_not_before = 0;
		foreach ($items as $item) {
			if ((int) ($item->id ?? 0) === $item_id) {
				continue;
			}
			$notes = self::row_notes($item);
			if (self::row_is_waiting_on_category_budget($item, $notes)) {
				continue;
			}
			$not_before = (int) ($notes['_system']['publish_not_before'] ?? 0);
			if ($not_before > $latest_not_before) {
				$latest_not_before = $not_before;
			}
		}
		if ($latest_not_before <= 0) {
			return $base_slot;
		}
		return max($base_slot, $latest_not_before + ($interval_minutes * MINUTE_IN_SECONDS));
	}

	private static function canonicalize_single_workflow_state(int $id, string $state, ?object $current = null, array $extra = []): string {
		$state = sanitize_key($state);
		$state = self::normalize_legacy_publish_state($state);
		if ($current instanceof stdClass && self::row_has_live_published_posts($current)) {
			return 'published';
		}
		if ($current instanceof stdClass && $state === 'ready_review') {
			$notes = json_decode((string) ($current->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			if (! empty($extra['admin_notes'])) {
				$incoming = json_decode((string) $extra['admin_notes'], true);
				if (is_array($incoming)) {
					$notes = array_replace_recursive($notes, $incoming);
				}
			}
			$is_auto = (string) ($current->mode ?? '') === 'auto';
			$manual = (string) ($notes['_system']['manual_confirmation_required'] ?? '');
			if ($manual !== '') {
				return 'ready_review';
			}
			if ($is_auto && $manual === '') {
				$payload_json = array_key_exists('ai_payload', $extra) ? (string) $extra['ai_payload'] : (string) ($current->ai_payload ?? '');
				$payload = json_decode($payload_json, true);
				$payload = is_array($payload) ? $payload : [];
				if ($payload !== []) {
					$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
				}
				if ($payload !== [] && self::workflow_payload_is_ready_like($payload)) {
					return 'ready_publish';
				}
				return 'retry_process';
			}
		}
		if (
			self::orchestrator_v2_enabled()
			&& in_array($state, ['processing_de', 'retry_process', 'ready_review', 'reserve'], true)
		) {
			return 'new';
		}
		if (! self::is_single_workflow_state($state)) {
			return $state;
		}
		if (in_array($state, ['processing_de', 'retry_process', 'ready_review', 'ready_publish', 'retry_publish', 'publishing'], true)) {
			return $state;
		}
		$active_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		if ($active_id > 0 && $active_id !== $id) {
			return 'new';
		}
		return $state;
	}

	private static function is_single_workflow_state(string $state): bool {
		return in_array($state, ['processing_de', 'retry_process', 'ready_review', 'ready_publish', 'retry_publish', 'publishing', 'reserve'], true);
	}

	private static function normalize_legacy_publish_state(string $state): string {
		return match ($state) {
			'draft_created', 'pending_review', 'partially_created' => 'ready_publish',
			default => $state,
		};
	}

	private static function first_waiting_publish_slot(): int {
		$interval = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS;
		return time() + $interval;
	}

	private static function earliest_publish_slot_from_ready_at(string $readyAt): int {
		$interval = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS;
		$readyTs = strtotime($readyAt);
		if ($readyTs === false || $readyTs <= 0) {
			return self::first_waiting_publish_slot();
		}
		return $readyTs + $interval;
	}

	private static function compare_publish_queue_order(object $a, object $b): int {
		$aNotes = self::row_notes($a);
		$bNotes = self::row_notes($b);
		$aSystem = is_array($aNotes['_system'] ?? null) ? $aNotes['_system'] : [];
		$bSystem = is_array($bNotes['_system'] ?? null) ? $bNotes['_system'] : [];
		$aReadyAt = (string) ($aSystem['ready_publish_at'] ?? '');
		$bReadyAt = (string) ($bSystem['ready_publish_at'] ?? '');
		if ($aReadyAt !== $bReadyAt) {
			if ($aReadyAt === '') {
				return 1;
			}
			if ($bReadyAt === '') {
				return -1;
			}
			return strcmp($aReadyAt, $bReadyAt);
		}
		return strcmp((string) $a->created_at, (string) $b->created_at);
	}

	private static function compare_publish_schedule_order(object $a, object $b): int {
		$aNotes = self::row_notes($a);
		$bNotes = self::row_notes($b);
		$aSystem = is_array($aNotes['_system'] ?? null) ? $aNotes['_system'] : [];
		$bSystem = is_array($bNotes['_system'] ?? null) ? $bNotes['_system'] : [];
		$aNotBefore = (int) ($aSystem['publish_not_before'] ?? 0);
		$bNotBefore = (int) ($bSystem['publish_not_before'] ?? 0);
		if ($aNotBefore !== $bNotBefore) {
			if ($aNotBefore <= 0) {
				return 1;
			}
			if ($bNotBefore <= 0) {
				return -1;
			}
			return $aNotBefore <=> $bNotBefore;
		}
		$aReadyAt = (string) ($aSystem['ready_publish_at'] ?? '');
		$bReadyAt = (string) ($bSystem['ready_publish_at'] ?? '');
		if ($aReadyAt !== $bReadyAt) {
			if ($aReadyAt === '') {
				return 1;
			}
			if ($bReadyAt === '') {
				return -1;
			}
			return strcmp($aReadyAt, $bReadyAt);
		}
		return strcmp((string) $a->created_at, (string) $b->created_at);
	}

	/**
	 * Convert a requested terminal state (rejected/error) to ready_review
	 * unless the row is genuinely unsalvageable. Genuinely-hard reasons:
	 * duplicates, stale time-sensitive, hard editorial block, context
	 * reject, sport-fixture livepage. Everything else routes to manual
	 * review so the operator (not a brittle automated re-score) decides.
	 */
	/**
	 * Reasons that are genuinely hard-terminal: duplicates, hub pages,
	 * paywall stubs, dead/sport-fixture livepages, hard-editorial blocks
	 * and the size-guard tripwires. Items hitting these never get
	 * re-promoted by maintenance — they stay where they were sent
	 * (rejected for ingest issues, manual_review for downstream).
	 *
	 * Adding a new reason: append a substring here, no regex needed.
	 * Checked case-insensitively as a substring match against the
	 * error_message; that's why "stale_time_sensitive" appears once.
	 */
	private const HARD_TERMINAL_REASON_TOKENS = [
		'duplicate',
		'stale_time_sensitive',
		'hard_editorial',
		'context_reject',
		'sport_fixture',
		'payload size guard',
		'max_allowed_packet',
		'community_promo',
		'routine_official',
		'meta_index_page',
		'hub_page',
		'paywall_only',
		// Selection algorithm verdicts: an item the worker scored as
		// `reject` or `low` should not be salvaged into ready_review by
		// the soft-terminal guard. Selection does not change with a
		// rebuild — recycling these only burns AI tokens and clogs the
		// admin manual-review bucket.
		'selection decision "reject"',
		'selection decision "low"',
		'selection_publish_blocked',
		'selection_reject',
		'selection_low',
		'не пересматривается перезапуском',
		// Pre-AI publish-priority sanitize verdict — terminal окончательно,
		// иначе soft-terminal guard конвертирует rejection в ready_review,
		// и item возвращается в processing цикл через ai_publish_finish_resume.
		'предварительный publish-priority',
	];

	/**
	 * True when the given error_message contains a hard-terminal token.
	 * Public so unit tests / admin tooling can introspect a reason
	 * without re-implementing the substring scan.
	 */
	public static function is_hard_terminal_reason(string $error_message): bool {
		if ($error_message === '') {
			return false;
		}
		$haystack = mb_strtolower($error_message);
		foreach (self::HARD_TERMINAL_REASON_TOKENS as $token) {
			if (mb_stripos($haystack, $token) !== false) {
				return true;
			}
		}
		return false;
	}

	private static function soft_terminal_state_guard(int $id, string $intended_state, array $extra, ?object $current): string {
		if (! $current) {
			return $intended_state;
		}
		$error_message = (string) ($extra['error_message'] ?? '');
		// Keep terminal for genuinely-hard reasons (see HARD_TERMINAL_REASON_TOKENS).
		if (self::is_hard_terminal_reason($error_message)) {
			return $intended_state;
		}
		// Read the current payload + admin notes so we can consult ingest
		// score and the upfront story card.
		$payload_json = '';
		if (array_key_exists('ai_payload', $extra) && is_string($extra['ai_payload'])) {
			$payload_json = $extra['ai_payload'];
		} else {
			$row = self::get_item($id);
			if ($row instanceof \stdClass) {
				$payload_json = (string) ($row->ai_payload ?? '');
			}
		}
		$payload = $payload_json !== '' ? json_decode($payload_json, true) : [];
		if (! is_array($payload)) {
			$payload = [];
		}
		$ingest_score = (int) ($current->story_score ?? 0);
		$card = is_array($payload['_meta']['story_card'] ?? null) ? $payload['_meta']['story_card'] : null;
		$card_estimate = '';
		if (is_array($card)) {
			$card_estimate = strtolower((string) ($card['publishable_estimate'] ?? ''));
		}
		$card_facts = is_array($card['key_facts'] ?? null) ? count($card['key_facts']) : 0;
		// Salvageable when EITHER the ingest score cleared C-tier OR the
		// upfront card thinks the piece is publishable OR the card pulled
		// 3+ atomic key facts from the source.
		$salvageable =
			$ingest_score >= 30
			|| in_array($card_estimate, ['high', 'medium'], true)
			|| $card_facts >= 3;
		if (! $salvageable) {
			return $intended_state;
		}
		return 'ready_review';
	}

	public static function update_fields(int $id, array $fields): void {
		if (empty($fields)) {
			return;
		}
		self::guard_payload_field_sizes($id, $fields);
		// Mirror the per-provider AI usage bump from mark_state so updates
		// that bypass mark_state (fast_transition_item_to_ready_publish,
		// regen flows) still feed the daily counter when they persist a
		// fresh ai_payload with token-bearing ai_runtime entries.
		if (class_exists('EPV2_Stats') && isset($fields['ai_payload'])) {
			$bump_payload = json_decode((string) $fields['ai_payload'], true);
			if (is_array($bump_payload)) {
				$bm = is_array($bump_payload['_meta'] ?? null) ? $bump_payload['_meta'] : [];
				$br = is_array($bm['ai_runtime'] ?? null) ? $bm['ai_runtime'] : [];
				$br_total = 0;
				foreach ($br as $entry) {
					if (is_array($entry)) {
						$br_total += max(0, (int) ($entry['tokens'] ?? 0));
					}
				}
				if ($br_total > 0 || (int) ($bm['tokens'] ?? 0) > 0) {
					EPV2_Stats::record_payload_ai_usage($bump_payload);
				}
			}
		}
		global $wpdb;
		$wpdb->update($wpdb->prefix . 'epv2_queue', $fields, ['id' => $id]);
	}

	/**
	 * Reject UPDATEs that would push ai_payload / publish_payload past the
	 * MariaDB max_allowed_packet (default 16 MB). We refuse anything over
	 * 10 MB to keep a safety margin: the SQL packet carries the full row,
	 * not just the column bytes. A logged warning gives the row id so the
	 * operator can inspect / quarantine it; a thrown LogicException fails
	 * the offending caller fast instead of silently corrupting state.
	 */
	private const MAX_PAYLOAD_BYTES = 10 * 1024 * 1024;

	private static function guard_payload_field_sizes(int $id, array $fields): void {
		foreach (['ai_payload', 'publish_payload'] as $field) {
			if (! array_key_exists($field, $fields)) {
				continue;
			}
			$value = $fields[$field];
			if (! is_string($value) || $value === '') {
				continue;
			}
			$len = strlen($value);
			if ($len > self::MAX_PAYLOAD_BYTES) {
				if (class_exists('EPV2_Logger')) {
					EPV2_Logger::warning('queue', 'payload size guard tripped', [
						'item_id' => $id,
						'field'   => $field,
						'bytes'   => $len,
						'limit'   => self::MAX_PAYLOAD_BYTES,
					]);
				}
				throw new \LogicException(sprintf(
					'EPV2_Queue::update_fields refused %s for item %d: %d bytes exceeds %d byte safety limit (max_allowed_packet guard).',
					$field,
					$id,
					$len,
					self::MAX_PAYLOAD_BYTES
				));
			}
		}
	}

	public static function delete_items(array $ids): void {
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if (empty($ids)) {
			return;
		}
		global $wpdb;
		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}epv2_queue WHERE id IN ({$placeholders})", ...$ids));
	}

	public static function clear_all(): void {
		global $wpdb;
		$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}epv2_queue");
	}

	public static function prune_stale(int $days = 3): int {
		global $wpdb;
		$days = max(1, min(30, $days));
		$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
		$terminal_states = ['duplicate', 'rejected', 'published', 'error'];
		$placeholders = implode(',', array_fill(0, count($terminal_states), '%s'));
		$sql = "DELETE FROM {$wpdb->prefix}epv2_queue WHERE state IN ({$placeholders}) AND created_at < %s";
		$args = array_merge($terminal_states, [$cutoff]);
		$result = $wpdb->query($wpdb->prepare($sql, ...$args));
		return (int) $result;
	}

	public static function prune_rejected(int $minutes = 1440): int {
		return 0;
	}

	public static function prune_new_stale(int $hours = 18): int {
		return 0;
	}

	public static function trim_new_queue(int $max_per_category = 8, int $max_per_source = 10): int {
		global $wpdb;
		$max_per_category = max(1, min(50, $max_per_category));
		$max_per_source = max(1, min(50, $max_per_source));
		$rows = $wpdb->get_results("SELECT id, source_id, category_proposed, story_score, admin_notes, created_at FROM {$wpdb->prefix}epv2_queue WHERE state = 'new' ORDER BY created_at DESC", ARRAY_A);
		if (! $rows) {
			return 0;
		}

		$kept_category = [];
		$kept_source = [];
		$delete_ids = [];

		usort($rows, static function (array $a, array $b): int {
			$score_a = self::score_from_row($a);
			$score_b = self::score_from_row($b);
			if ($score_a === $score_b) {
				return strcmp((string) $b['created_at'], (string) $a['created_at']);
			}
			return $score_b <=> $score_a;
		});

		foreach ($rows as $row) {
			$score = self::score_from_row($row);
			$keep_gate = EPV2_Budget_Manager::should_keep_in_queue([
				'score' => $score,
				'tier' => self::tier_from_score($score),
			]);
			if (empty($keep_gate['keep'])) {
				$delete_ids[] = (int) $row['id'];
				continue;
			}
			$category = (string) ($row['category_proposed'] ?? '');
			$source = (string) ((int) ($row['source_id'] ?? 0));
			$kept_category[$category] = $kept_category[$category] ?? 0;
			$kept_source[$source] = $kept_source[$source] ?? 0;

			if (($category !== '' && $kept_category[$category] >= $max_per_category) || ($source !== '0' && $kept_source[$source] >= $max_per_source)) {
				$delete_ids[] = (int) $row['id'];
				continue;
			}

			if ($category !== '') {
				$kept_category[$category]++;
			}
			if ($source !== '0') {
				$kept_source[$source]++;
			}
		}

		return 0;
	}

	private static function score_from_row(array $row): int {
		$score = (int) ($row['story_score'] ?? 0);
		if ($score > 0) {
			return $score;
		}
		$notes = json_decode((string) ($row['admin_notes'] ?? ''), true);
		$score = (int) ($notes['selection']['score'] ?? 0);
		if ($score > 0) {
			return $score;
		}
		$analysis = EPV2_Budget_Manager::analyze_item([
			'title' => (string) ($row['original_title'] ?? ''),
			'content' => (string) ($row['original_content'] ?? ''),
			'excerpt' => (string) ($row['original_excerpt'] ?? ''),
			'url' => (string) ($row['original_url'] ?? ''),
			'date' => (string) ($row['original_date'] ?? ''),
			'image' => (string) ($row['source_image_url'] ?? ''),
			'category' => (string) ($row['category_final'] ?? $row['category_proposed'] ?? ''),
		]);
		return (int) ($analysis['score'] ?? 0);
	}

	private static function effective_processing_score(array $row): int {
		$score = self::score_from_row($row);
		$state = (string) ($row['state'] ?? '');
		if ($state === 'retry_process' && self::row_processing_stage((object) $row) === '') {
			$score -= 28;
		} elseif ($state === 'ready_review') {
			$score -= 60;
		}
		if (self::is_fatigued_rebuild_candidate((object) $row)) {
			$score -= 80;
		}
		return $score;
	}

	private static function processing_stage_priority(object $row): int {
		$stage = self::row_processing_stage($row);
		if ($stage === 'publish_finish') {
			return 440;
		}
		if ($stage === 'translate_en') {
			return 500;
		}
		if ($stage === 'translate_uk') {
			return 480;
		}
		if ($stage === 'translate_finish') {
			$notes = self::row_notes($row);
			$review_rebuild = (int) ($notes['_system']['retries']['review_rebuild'] ?? 0);
			if ($review_rebuild >= 2) {
				return 220;
			}
			return 460;
		}
		if ($stage === 'rebuild_bundle') {
			$payload = self::row_payload($row);
			$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			$checklist = is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
			if (
				! empty($checklist['translations_ready'])
				&& ! empty($checklist['publish_finish_ready'])
			) {
				return 510;
			}
			if (self::is_fatigued_rebuild_candidate($row)) {
				return 0;
			}
			return 420;
		}
		if ($stage !== '') {
			return 360;
		}
		$state = (string) ($row->state ?? '');
		$message = mb_strtolower((string) ($row->error_message ?? ''));
		if ($state === 'retry_process' && preg_match('/publish threshold|minimum review threshold|broken multilingual/u', $message) === 1) {
			return 260;
		}
		if ($state === 'retry_process') {
			return 180;
		}
		if ($state === 'ready_review') {
			return -40;
		}
		return 0;
	}

	public static function row_processing_stage(object $row): string {
		if (isset($row->_epv2_pipeline_stage)) {
			return sanitize_key((string) $row->_epv2_pipeline_stage);
		}
		$payload = self::row_payload($row);
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		return sanitize_key((string) ($meta['pipeline_stage'] ?? ''));
	}

	public static function ready_review_requires_automation_resume(object $row): bool {
		if ((string) ($row->state ?? '') !== 'ready_review') {
			return false;
		}
		$stage = self::row_processing_stage($row);
		if (self::item_has_explicit_manual_confirmation_marker($row)) {
			return false;
		}
		if ($stage !== '') {
			return true;
		}
		$payload = self::row_payload($row);
		if ($payload === []) {
			return false;
		}
		if (! empty($payload['_meta']['translations_deferred'])) {
			return true;
		}
		$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
		return ! EPV2_AI_Processor::payload_is_publish_ready($payload) && ! self::workflow_payload_ready_publish_checklist($payload);
	}

	public static function ready_review_due(object $row): bool {
		if ((string) ($row->state ?? '') !== 'ready_review') {
			return false;
		}
		if (self::row_processing_stage($row) !== '') {
			return true;
		}
		$payload = self::row_payload($row);
		if (! empty($payload['_meta']['translations_deferred'])) {
			return true;
		}
		$notes = self::row_notes($row);
		$revisit_after = (string) ($notes['_system']['review_revisit_after'] ?? '');
		if ($revisit_after !== '') {
			return strtotime($revisit_after) <= time();
		}
		$updated = strtotime((string) ($row->updated_at ?? '')) ?: 0;
		if ($updated <= 0) {
			return true;
		}
		return (time() - $updated) >= (15 * MINUTE_IN_SECONDS);
	}

	private static function retry_process_is_exhausted(object $row): bool {
		if ((string) ($row->state ?? '') !== 'retry_process') {
			return false;
		}
		if (self::row_processing_stage($row) !== '') {
			return false;
		}
		$payload = self::row_payload($row);
		if (
			EPV2_AI_Processor::item_has_exhausted_auto_rework($row, $payload)
			|| EPV2_AI_Processor::item_has_exhausted_auto_finish($row, $payload)
		) {
			return true;
		}
		$notes = self::row_notes($row);
		$retries = (int) ($notes['_system']['retries']['process'] ?? 0);
		if ($retries < 2) {
			return false;
		}
		$message = (string) ($row->error_message ?? '');
		if (preg_match('/minimum review threshold|publish threshold/i', $message) !== 1) {
			return false;
		}
		return self::score_from_row((array) $row) < 46;
	}

	private static function review_rebuild_exhausted(object $row): bool {
		if (self::row_processing_stage($row) !== '') {
			return false;
		}
		$payload = self::row_payload($row);
		$notes = self::row_notes($row);
		$review_rebuild = (int) ($notes['_system']['retries']['review_rebuild'] ?? 0);
		$maxAttempts = self::configured_review_attempt_limit($payload);
		if ($review_rebuild >= $maxAttempts) {
			return true;
		}
		$process_retries = (int) ($notes['_system']['retries']['process'] ?? 0);
		$message = (string) ($row->error_message ?? '');
		if (
			$process_retries > $maxAttempts
			&& preg_match('/publish threshold|minimum review threshold|heuristic payload|broken multilingual/i', $message) === 1
		) {
			return true;
		}
		return false;
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

	private static function has_fresh_new_candidate_ready(): bool {
		return self::next_fresh_new_item(false) instanceof stdClass;
	}

	private static function should_prefer_fresh_new_bucket(?object $fresh_new_item, ?object $resume_item): bool {
		if (! ($fresh_new_item instanceof stdClass) || ! ($resume_item instanceof stdClass)) {
			return false;
		}
		$resume_stage_priority = self::processing_stage_priority($resume_item);
		$resume_stage = self::row_processing_stage($resume_item);
		if ($resume_stage === 'publish_finish') {
			$updated_at = strtotime((string) ($resume_item->updated_at ?? $resume_item->created_at ?? '')) ?: 0;
			if ($updated_at <= 0) {
				return true;
			}
			return (time() - $updated_at) >= (5 * MINUTE_IN_SECONDS);
		}
		if ($resume_stage_priority >= 500) {
			return false;
		}
		$latest_payload = EPV2_Runs::latest_finished_payload('process');
		$last_bucket = sanitize_key((string) ($latest_payload['selected_bucket'] ?? ''));
		if ($last_bucket === '') {
			return true;
		}
		return $last_bucket !== 'new';
	}

	private static function filter_lane_monopolizing_items(array $items): array {
		if ($items === []) {
			return [];
		}
		$filtered = array_values(array_filter($items, static function ($item): bool {
			return ! self::is_lane_monopolizing_process_candidate($item)
				&& ! self::is_infra_backlog_process_candidate($item);
		}));
		return $filtered !== [] ? $filtered : $items;
	}

	private static function is_lane_monopolizing_process_candidate(object $row): bool {
		$item_id = (int) ($row->id ?? 0);
		if ($item_id <= 0) {
			return false;
		}
		$state = (string) ($row->state ?? '');
		if (! in_array($state, ['retry_process', 'ready_review'], true)) {
			return false;
		}
		$stage = self::row_processing_stage($row);
		if ($stage === '') {
			return false;
		}
		$updated_at = strtotime((string) ($row->updated_at ?? '')) ?: 0;
		if ($updated_at > 0 && (time() - $updated_at) < self::PROCESS_LANE_RESUME_COOLDOWN) {
			return true;
		}
		$streak = EPV2_Runs::recent_processed_item_streak('process', $item_id, self::PROCESS_LANE_MONOPOLY_WINDOW);
		return $streak >= self::PROCESS_LANE_MONOPOLY_STREAK;
	}

	private static function is_infra_backlog_process_candidate(object $row): bool {
		if ((string) ($row->state ?? '') !== 'retry_process') {
			return false;
		}
		$stage = self::row_processing_stage($row);
		if (! in_array($stage, ['rebuild_bundle', 'translate_finish'], true)) {
			return false;
		}
		$message = (string) ($row->error_message ?? '');
		$updated_at = strtotime((string) ($row->updated_at ?? '')) ?: 0;
		if (
			preg_match('/worker disappeared|worker исчез|external worker failed|provider unavailable|cooldown|Could not open input file|worker timed out|legacy auto ready_review sink/i', $message) !== 1
		) {
			if (
				trim($message) !== ''
				|| $stage !== 'rebuild_bundle'
				|| $updated_at <= 0
				|| (time() - $updated_at) < 900
			) {
				return false;
			}
		}
		if ($updated_at > 0 && (time() - $updated_at) < 120) {
			return false;
		}
		return true;
	}

	private static function is_fatigued_rebuild_candidate(object $row): bool {
		if (self::row_processing_stage($row) !== 'rebuild_bundle') {
			return false;
		}
		$notes = self::row_notes($row);
		$retries = is_array($notes['_system']['retries'] ?? null) ? $notes['_system']['retries'] : [];
		$processRetries = (int) ($retries['process'] ?? 0);
		$reviewRebuild = (int) ($retries['review_rebuild'] ?? 0);
		$message = (string) ($row->error_message ?? '');
		if (
			$processRetries < 1
			&& $reviewRebuild < 1
		) {
			return false;
		}
		return preg_match('/minimum DE master quality|publish threshold|minimum review threshold|heuristic payload|broken multilingual/iu', $message) === 1;
	}

	private static function categories_from_value(string $value): array {
		return array_values(array_filter(array_map('trim', explode(',', $value))));
	}

	private static function row_category_load(object $row, array $load): int {
		$categories = self::categories_from_value((string) ($row->category_final ?: $row->category_proposed));
		if ($categories === []) {
			return 999;
		}
		$min = null;
		foreach ($categories as $category) {
			$count = (int) ($load[$category] ?? 0);
			$min = $min === null ? $count : min($min, $count);
		}
		return $min ?? 999;
	}

	private static function row_is_breaking(object $row): bool {
		$payload = self::row_payload($row);
		return ! empty($payload['_meta']['breaking']);
	}

	private static function row_is_top_story(object $row): bool {
		$payload = self::row_payload($row);
		return ! empty($payload['_meta']['top_story']);
	}

	public static function item_has_priority_publish_override(object $row): bool {
		if (self::row_is_breaking($row) || self::row_is_top_story($row)) {
			return true;
		}
		$analysis = self::row_selection_analysis($row);
		return (string) ($analysis['decision'] ?? '') === 'priority';
	}

	public static function item_has_publish_limit_override(object $row): bool {
		if (self::item_has_priority_publish_override($row)) {
			return true;
		}
		$payload = self::row_payload($row);
		return ! empty($payload['_meta']['manual_mode']);
	}

	private static function payload_has_publish_limit_override(array $payload, object $row): bool {
		if (! empty($payload['_meta']['breaking']) || ! empty($payload['_meta']['top_story']) || ! empty($payload['_meta']['manual_mode'])) {
			return true;
		}
		return self::item_has_publish_limit_override($row);
	}

	private static function row_is_waiting_on_category_budget(object $row, ?array $notes = null): bool {
		$notes = is_array($notes) ? $notes : self::row_notes($row);
		$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		if (empty($system['publish_deferred_by_category_limit'])) {
			return false;
		}
		$payload = self::row_payload($row);
		$override = self::payload_has_publish_limit_override($payload, $row);
		return ! EPV2_Time_Planner::publish_category_budget_allows_item(self::row_primary_category($row, $payload), $override);
	}

	private static function row_primary_category(object $row, array $payload = []): string {
		$payload_categories = array_values(array_filter(array_map('strval', (array) ($payload['categories'] ?? []))));
		if ($payload_categories !== []) {
			return $payload_categories[0];
		}
		foreach (['category_final', 'category_proposed'] as $field) {
			$value = trim((string) ($row->{$field} ?? ''));
			if ($value !== '') {
				$parts = array_values(array_filter(array_map('trim', explode(',', $value))));
				return $parts[0] ?? $value;
			}
		}
		return '';
	}

	public static function has_due_priority_publish_item(): bool {
		$items = self::get_queue_items_summary(['states' => ['ready_publish', 'retry_publish'], 'limit' => 30]);
		if ($items === []) {
			return false;
		}
		foreach ($items as $item) {
			if (self::row_has_live_published_posts($item)) {
				continue;
			}
			if (! self::item_has_priority_publish_override($item)) {
				continue;
			}
			if (self::publish_due($item)) {
				return true;
			}
		}
		return false;
	}

	public static function has_due_publish_item(): bool {
		$items = self::get_queue_items_summary(['states' => ['ready_publish', 'retry_publish'], 'limit' => 30]);
		if ($items === []) {
			return false;
		}
		foreach ($items as $item) {
			if (self::row_has_live_published_posts($item)) {
				continue;
			}
			if (self::publish_due($item)) {
				return true;
			}
		}
		return false;
	}

	private static function publish_due(object $row): bool {
		$notes = self::row_notes($row);
		if (self::row_is_waiting_on_category_budget($row, $notes)) {
			$payload = self::row_payload($row);
			$next = EPV2_Jobs::next_publish_slot_after(
				EPV2_Time_Planner::next_category_budget_slot_timestamp(self::row_primary_category($row, $payload))
			);
			if ((int) ($notes['_system']['publish_not_before'] ?? 0) !== $next) {
				$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
				$notes['_system']['publish_not_before'] = $next;
				self::update_fields((int) ($row->id ?? 0), [
					'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				]);
			}
			return false;
		}
		$notBefore = (int) ($notes['_system']['publish_not_before'] ?? 0);
		if ($notBefore <= 0 && (string) ($row->state ?? '') === 'ready_publish') {
			self::backfill_publish_schedule((int) ($row->id ?? 0), $notes, false, (string) ($row->updated_at ?? ''));
			return false;
		}
		return $notBefore > 0 && $notBefore <= time();
	}

	private static function backfill_publish_schedule(int $id, array $notes, bool $recover_immediately = false, string $fallback_ready_at = ''): void {
		if ($id <= 0) {
			return;
		}
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		if (empty($notes['_system']['ready_publish_at'])) {
			$notes['_system']['ready_publish_at'] = $fallback_ready_at !== '' ? $fallback_ready_at : gmdate('Y-m-d H:i:s');
		}
		$notes['_system']['publish_not_before'] = self::next_publish_slot_for_queue($id);
		self::update_fields($id, [
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
	}

	private static function tier_from_score(int $score): string {
		return match (true) {
			$score >= 70 => 'A',
			$score >= 52 => 'B',
			$score >= 34 => 'C',
			default => 'D',
		};
	}

	private static function row_has_live_published_posts(object $row): bool {
		$publish_payload = json_decode((string) ($row->publish_payload ?? ''), true);
		$post_ids = is_array($publish_payload['post_ids'] ?? null) ? $publish_payload['post_ids'] : [];
		if (! empty($row->post_id)) {
			$post_ids['de'] = (int) $row->post_id;
		}
		foreach ($post_ids as $post_id) {
			$post_id = (int) $post_id;
			if ($post_id <= 0) {
				continue;
			}
			if (get_post_status($post_id) === 'publish') {
				return true;
			}
		}
		return false;
	}

	private static function active_owner_waiting_on_retry(object $item): bool {
		return self::workflow_waiting_not_before($item);
	}

	public static function promote_live_published_rows(int $limit = 50): int {
		$limit = max(1, min(500, $limit));
		$rows = self::get_queue_items_summary([
			'states' => ['new', 'retry_process', 'ready_review', 'ready_publish', 'retry_publish', 'publishing', 'reserve'],
			'limit' => $limit,
		]);
		$active_owner_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		$changed = 0;
		foreach ($rows as $row) {
			if (! ($row instanceof stdClass) || ! self::row_has_live_published_posts($row)) {
				continue;
			}
			if ((string) ($row->state ?? '') === 'published') {
				continue;
			}
			// Race guard: don't trample an active orchestrator/publisher that
			// holds this row. If it's the active automation item or has a
			// non-empty workflow_owner_token, the owner is mid-pipeline and
			// will reach a terminal state on its own. Promoting from under
			// it can cause lost-update races (orchestrator writes
			// retry_process while we just wrote published).
			$row_id = (int) ($row->id ?? 0);
			if ($active_owner_id > 0 && $row_id === $active_owner_id) {
				continue;
			}
			if (self::workflow_owner_token($row) !== '') {
				continue;
			}
			self::mark_state($row_id, 'published', [
				'error_message' => '',
			]);
			$changed++;
		}
		return $changed;
	}

	public static function reactivate_media_recoverable_rows(int $limit = 10): int {
		if (! self::orchestrator_v2_enabled()) {
			return 0;
		}
		$limit = max(1, min(50, $limit));
		$active_owner_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		$rows = self::get_queue_items_summary([
			'states' => ['new'],
			'limit' => max(20, $limit * 4),
		]);
		$changed = 0;
		foreach ($rows as $row) {
			if ($changed >= $limit || ! ($row instanceof stdClass)) {
				break;
			}
			if (self::workflow_infer_step_from_row($row) !== 'publish_ready_gate') {
				continue;
			}
			if ($active_owner_id > 0 && (int) $row->id === $active_owner_id) {
				continue;
			}
			$notes = self::row_notes($row);
			$media_attempts = (int) ($notes['_system']['retries']['media_repair'] ?? 0);
			if ($media_attempts >= 10) {
				continue;
			}
			$retry_after = (string) ($notes['_system']['retry_after'] ?? '');
			if ($retry_after === '' || strtotime($retry_after) <= time()) {
				continue;
			}
			$payload = self::row_payload($row);
			if ($payload !== []) {
				$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
			}
			$featured_media = (string) ($payload['featured_media_url'] ?? '');
			$de_media = (string) ($payload['languages']['de']['media_url'] ?? '');
			if ($featured_media !== '' || $de_media !== '') {
				continue;
			}
			$full = self::get_item((int) $row->id);
			if (! $full) {
				continue;
			}
			$dossier = EPV2_Source_Enricher::enrich_item($full, [
				'force_supporting' => true,
				'allow_google_wrappers' => true,
				'deadline' => time() + 8,
			]);
			$primary_image = (string) ($dossier['primary']['image'] ?? '');
			if ($primary_image === '') {
				foreach ((array) ($dossier['supporting'] ?? []) as $entry) {
					$primary_image = (string) ($entry['image'] ?? '');
					if ($primary_image !== '') {
						break;
					}
				}
			}
			if ($primary_image === '') {
				continue;
			}
			unset($notes['_system']['retry_after']);
			$notes['_system']['workflow_step_status'] = 'pending';
			self::update_fields((int) $row->id, [
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				'error_message' => '',
			]);
			$changed++;
		}
		return $changed;
	}

	private static function automation_requires_publish_grade(): bool {
		$mode = (string) EPV2_Settings::get('mode', 'semi');
		return $mode === 'auto';
	}

	private static function has_active_processing_item(int $maxAge = 90): bool {
		self::normalize_active_automation_pointer();
		$active_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		if ($active_id > 0) {
			$active_item = self::get_item_summary($active_id);
			if ($active_item) {
				$active_state = (string) ($active_item->state ?? '');
				if (in_array($active_state, ['retry_process', 'ready_review', 'reserve', 'new'], true)) {
					return false;
				}
				if ($active_state !== 'processing_de') {
					self::clear_active_automation_item($active_id);
					return false;
				}
				if (in_array($active_state, ['processing_de'], true) && self::process_lock_is_live()) {
					return true;
				}
			}
		}
		$items = self::get_queue_items_summary(['states' => ['processing_de'], 'limit' => 10]);
		if ($items === []) {
			return false;
		}
		$lock = get_option('epv2_lock_process', false);
		$lockTtl = max(300, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
		$lockFreshWindow = max(90, min(300, (int) floor($lockTtl / 3)));
		if (is_array($lock) && ! empty($lock['token'])) {
			$heartbeat = (int) ($lock['heartbeat_at'] ?? 0);
			if ($heartbeat > 0 && ($heartbeat + $lockFreshWindow) > time()) {
				return true;
			}
		}
		// A row left in processing_de without a live process lock must not block the
		// whole single-lane automation queue. Recovery will move it out explicitly.
		return false;
	}


	private static function focused_automation_item(bool $ignore_retry_after = false): ?object {
		self::normalize_active_automation_pointer();
		$id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		if ($id <= 0) {
			return null;
		}
		$item = self::get_item_summary($id);
		if (! $item) {
			self::clear_active_automation_item($id);
			return null;
		}
		$state = (string) ($item->state ?? '');
		if (in_array($state, ['published', 'rejected', 'duplicate', 'error'], true)) {
			self::clear_active_automation_item($id);
			return null;
		}
		$state = self::normalize_legacy_publish_state($state);
		if (! in_array($state, ['processing_de', 'retry_process', 'ready_review', 'reserve', 'new'], true)) {
			self::clear_active_automation_item($id);
			return null;
		}
		if ($state === 'processing_de' && ! self::process_lock_is_live()) {
			self::clear_active_automation_item($id);
			return null;
		}
		if (! self::item_is_processable_read_only($item, $ignore_retry_after)) {
			return null;
		}
		return $item;
	}

	private static function ready_review_is_manual_confirmation(object $row): bool {
		if ((string) ($row->state ?? '') !== 'ready_review') {
			return false;
		}
		return self::item_has_explicit_manual_confirmation_marker($row);
	}

	private static function item_requires_manual_confirmation_state(object $row): bool {
		if (self::item_has_explicit_manual_confirmation_marker($row)) {
			return true;
		}
		return false;
	}

	private static function item_has_explicit_manual_confirmation_marker(object $row): bool {
		$notes = self::row_notes($row);
		$manual = (string) ($notes['_system']['manual_confirmation_required'] ?? '');
		if (
			$manual === 'media_terminal_auto'
			&& self::orchestrator_v2_enabled()
			&& (string) ($row->state ?? '') === 'new'
			&& self::row_processing_stage($row) === 'publish_finish'
		) {
			return false;
		}
		if (
			$manual === 'translation_terminal_auto'
			&& self::orchestrator_v2_enabled()
			&& (string) ($row->state ?? '') === 'new'
			&& in_array(self::row_processing_stage($row), ['translate_uk', 'translate_en'], true)
		) {
			return false;
		}
		if ($manual !== '') {
			return true;
		}
		$message = mb_strtolower((string) ($row->error_message ?? ''));
		return preg_match('/требует ручного подтверждения (media|translation)/u', $message) === 1;
	}

	private static function row_notes_require_manual_confirmation(object $row): bool {
		$notes = self::row_notes($row);
		return (($notes['_system']['manual_confirmation_required'] ?? '') !== '');
	}

	private static function sync_active_automation_item(int $id, string $state): void {
		$row = self::get_item_summary($id);
		if ($row && self::current_row_should_keep_active_pointer($row)) {
			self::set_active_automation_item($id);
			return;
		}
		self::clear_active_automation_item($id);
	}

	private static function default_live_status_for_state(string $state): string {
		return match ($state) {
			'processing_de' => 'Обрабатываю материал и усиливаю фактуру.',
			'publishing' => 'Собираю пакет публикации и записываю его на сайт.',
			default => '',
		};
	}

	private static function normalize_active_automation_pointer(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$owned_rows = $wpdb->get_results(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
			WHERE JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.workflow_owner_token')) <> ''
			ORDER BY updated_at DESC, id DESC"
		);
		$owned_rows = array_values(array_filter((array) $owned_rows, static function ($row): bool {
			return $row instanceof stdClass;
		}));
		foreach ($owned_rows as $row) {
			if (self::finalize_ready_publish_owned_row($row)) {
				continue;
			}
		}
		$owned_rows = array_values(array_filter($owned_rows, static function ($row): bool {
			return self::current_row_should_keep_active_pointer($row);
		}));
		if (is_array($owned_rows) && count($owned_rows) > 1) {
			usort($owned_rows, static function ($a, $b): int {
				$aPriority = self::duplicate_survivor_priority($a);
				$bPriority = self::duplicate_survivor_priority($b);
				if ($aPriority !== $bPriority) {
					return $bPriority <=> $aPriority;
				}
				return strcmp((string) ($b->updated_at ?? ''), (string) ($a->updated_at ?? ''));
			});
			$canonical = $owned_rows[0] ?? null;
			if ($canonical) {
				update_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, (int) $canonical->id, false);
				foreach (array_slice($owned_rows, 1) as $duplicate_owner) {
					self::workflow_system_update((int) $duplicate_owner->id, [
						'workflow_owner_token' => '',
						'workflow_heartbeat_at' => '',
						'workflow_step_status' => 'released_as_noncanonical_owner',
					]);
				}
			}
		}
		$active_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		if ($active_id <= 0) {
			return;
		}
		$item = self::get_item_summary($active_id);
		if (! $item) {
			delete_option(self::OPTION_ACTIVE_AUTOMATION_ITEM);
			return;
		}
		if (! self::current_row_should_keep_active_pointer($item)) {
			delete_option(self::OPTION_ACTIVE_AUTOMATION_ITEM);
		}
	}

	private static function finalize_ready_publish_owned_row(object $item): bool {
		if (self::workflow_owner_token($item) === '') {
			return false;
		}
		if (self::workflow_user_state_for_row($item) !== 'ready_publish') {
			return false;
		}
		$payload = self::row_payload($item);
		if ($payload !== []) {
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
		}
		if (! EPV2_AI_Processor::transition_item_to_ready_publish((int) $item->id, $payload !== [] ? $payload : (array) json_decode((string) ($item->ai_payload ?? ''), true), [
			'error_message' => '',
		])) {
			return false;
		}
		self::active_owner_release((int) $item->id, 'ready_publish');
		return true;
	}

	private static function current_row_should_keep_active_pointer(object $row): bool {
		if (self::workflow_owner_token($row) === '') {
			return false;
		}
		if (self::row_has_live_published_posts($row)) {
			return false;
		}
		$state = self::normalize_legacy_publish_state((string) ($row->state ?? ''));
		if (self::workflow_is_terminal_state($state)) {
			return false;
		}
		if (in_array($state, ['ready_publish', 'retry_publish', 'publishing'], true)) {
			return false;
		}
		if (! in_array($state, ['new', 'processing_de', 'retry_process', 'ready_review', 'reserve'], true)) {
			return false;
		}
		if ($state === 'processing_de' && ! self::process_lock_is_live()) {
			return false;
		}
		return true;
	}

	private static function normalize_staged_new_items(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results(
			"SELECT id, ai_payload, admin_notes
			FROM {$table}
			WHERE state = 'new'
			ORDER BY updated_at DESC
			LIMIT 50"
		);
		foreach ((array) $rows as $row) {
			if (self::row_notes_require_manual_confirmation($row)) {
				continue;
			}
			$notes = self::row_notes($row);
			$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$has_retry_after = (string) ($system['retry_after'] ?? '') !== '';
			$has_live_code = (string) ($system['live_status_code'] ?? '') !== '';
			$has_stage = self::row_processing_stage($row) !== '';
			if (! $has_retry_after && ! $has_live_code && ! $has_stage) {
				continue;
			}
			if (self::orchestrator_v2_enabled()) {
				$workflow_step = self::workflow_infer_step_from_row($row);
				self::workflow_system_update((int) $row->id, [
					'workflow_step' => $workflow_step,
					'workflow_step_status' => 'pending',
				]);
				continue;
			}
			$wpdb->update($table, [
				'state' => 'retry_process',
			], ['id' => (int) $row->id]);
		}
	}

	private static function process_lock_is_live(): bool {
		$lock = get_option('epv2_lock_process', false);
		$lock_ttl = max(300, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
		$fresh_window = max(90, min(300, (int) floor($lock_ttl / 3)));
		if (! is_array($lock) || empty($lock['token'])) {
			return false;
		}
		$heartbeat = (int) ($lock['heartbeat_at'] ?? 0);
		return $heartbeat > 0 && ($heartbeat + $fresh_window) > time();
	}

	private static function select_fields($fields): string {
		if (is_string($fields) && trim($fields) !== '') {
			return $fields;
		}
		if (is_array($fields) && $fields !== []) {
			$sanitized = array_values(array_filter(array_map(static function ($field): string {
				$field = preg_replace('/[^a-zA-Z0-9_,\s]/', '', (string) $field);
				return trim((string) $field);
			}, $fields)));
			if ($sanitized !== []) {
				return implode(', ', $sanitized);
			}
		}
		return '*';
	}

	private static function row_payload(object $row): array {
		if (isset($row->_epv2_payload_cache) && is_array($row->_epv2_payload_cache)) {
			return $row->_epv2_payload_cache;
		}
		$payload = json_decode((string) ($row->ai_payload ?? ''), true);
		$row->_epv2_payload_cache = is_array($payload) ? $payload : [];
		return $row->_epv2_payload_cache;
	}

	private static function row_notes(object $row): array {
		if (isset($row->_epv2_notes_cache) && is_array($row->_epv2_notes_cache)) {
			return $row->_epv2_notes_cache;
		}
		$notes = json_decode((string) ($row->admin_notes ?? ''), true);
		$row->_epv2_notes_cache = is_array($notes) ? $notes : [];
		return $row->_epv2_notes_cache;
	}

	public static function workflow_version(): int {
		return self::WORKFLOW_VERSION;
	}

	private static function workflow_is_terminal_state(string $state): bool {
		return in_array($state, ['published', 'rejected', 'duplicate', 'error'], true);
	}

	private static function workflow_payload_language_has_substance(array $payload, string $lang): bool {
		$lang_payload = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
		$title = trim((string) ($lang_payload['title'] ?? ''));
		$excerpt = trim((string) ($lang_payload['excerpt'] ?? ''));
		$content = trim(wp_strip_all_tags((string) ($lang_payload['content'] ?? '')));
		return $title !== '' && $excerpt !== '' && $content !== '';
	}

	private static function workflow_payload_ready_publish_checklist(array $payload): bool {
		if (! EPV2_AI_Processor::payload_media_contract_passes($payload)) {
			return false;
		}
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$stage_checklist = is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
		return
			! empty($stage_checklist['ready_publish'])
			&& ! empty($stage_checklist['translations_ready'])
			&& ! empty($stage_checklist['publish_finish_ready'])
			&& empty($meta['translations_deferred'])
			&& EPV2_AI_Processor::payload_languages_are_semantically_consistent($payload);
	}

	private static function workflow_payload_is_ready_like(array $payload): bool {
		if (! EPV2_AI_Processor::payload_languages_are_semantically_consistent($payload)) {
			return false;
		}
		return EPV2_AI_Processor::payload_is_publish_ready($payload)
			|| EPV2_AI_Processor::payload_is_terminal_publish_ready($payload)
			|| self::workflow_payload_ready_publish_checklist($payload);
	}

	public static function demote_generated_cover_ready_publish_items(int $limit = 100): array {
		$items = self::get_queue_items_summary(['states' => ['ready_publish'], 'limit' => max(1, min(500, $limit))]);
		$resolved = [];
		foreach ($items as $item) {
			$payload = self::row_payload($item);
			if ($payload === []) {
				continue;
			}
			$featured = (string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? '');
			if ($featured === '' || ! EPV2_Media::is_generated_story_cover_url($featured)) {
				continue;
			}
			self::workflow_system_update((int) $item->id, [
				'workflow_step' => 'finalize_media',
				'workflow_step_status' => 'pending',
				'workflow_recovery_reason' => 'generated_cover_demoted',
				'workflow_last_error' => 'Ready-publish item was demoted because only a generated cover remained.',
			]);
			self::mark_state((int) $item->id, 'new', [
				'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($item->category_final ?? $item->category_proposed ?? ''),
				'error_message' => 'Материал возвращён в finalize_media: generated cover не считается publish-grade.',
			]);
			$resolved[] = [
				'id' => (int) $item->id,
				'featured' => $featured,
			];
		}
		return [
			'resolved' => $resolved,
			'count' => count($resolved),
		];
	}

	private static function workflow_infer_step_from_row(object $row): string {
		$stage = self::row_processing_stage($row);
		$payload = self::row_payload($row);
		if ($payload !== []) {
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
		}

		if ($stage !== '') {
			return match ($stage) {
				'translate_uk' => 'translate_uk',
				'translate_en', 'translate_finish' => 'translate_en',
				'publish_finish' => 'publish_ready_gate',
				default => 'build_de_master',
			};
		}

		if ($payload === []) {
			return 'build_de_master';
		}

		if (! self::workflow_payload_language_has_substance($payload, 'de')) {
			return 'build_de_master';
		}
		if (! self::workflow_payload_language_has_substance($payload, 'uk')) {
			return 'translate_uk';
		}
		if (! self::workflow_payload_language_has_substance($payload, 'en')) {
			return 'translate_en';
		}

		if (! EPV2_AI_Processor::payload_media_contract_passes($payload)) {
			return 'finalize_media';
		}

		$seo_title = trim((string) ($payload['languages']['de']['seo_title'] ?? ''));
		$meta_description = trim((string) ($payload['languages']['de']['meta_description'] ?? ''));
		if ($seo_title === '' || $meta_description === '') {
			return 'finalize_seo';
		}

		return 'publish_ready_gate';
	}

	private static function workflow_user_state_for_row(object $row): string {
		$state = self::normalize_legacy_publish_state((string) ($row->state ?? ''));
		if (self::row_has_live_published_posts($row)) {
			return 'published';
		}
		if (self::workflow_is_terminal_state($state)) {
			return $state;
		}
		// Manual review is a deliberate human-decision state — do NOT
		// upgrade it to ready_publish based on payload completeness. The
		// admin queue, action hint, progress bar, and force-publish
		// button all rely on the row reading as 'manual_review' here so
		// they show consistent text. Without this branch the row reads
		// as 'ready_publish' (because payload happens to pass the
		// publish-ready check) while every other UI element keeps the
		// raw manual_review semantics — operator sees a "Готов к
		// публикации" header above a "Не наша тема" hint with a force-
		// publish button. Pick one truth.
		if ($state === 'manual_review') {
			return 'manual_review';
		}
		if ($state === 'ready_publish' || $state === 'retry_publish' || $state === 'publishing') {
			return 'ready_publish';
		}

		$payload = self::row_payload($row);
		if ($payload !== []) {
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
		}
		if ($payload !== [] && (EPV2_AI_Processor::payload_is_publish_ready($payload) || self::workflow_payload_ready_publish_checklist($payload))) {
			return 'ready_publish';
		}

		return 'new';
	}

	public static function user_facing_state_for_row(object $row): string {
		$state = self::normalize_legacy_publish_state((string) ($row->state ?? ''));
		if ($state === 'publishing') {
			return 'publishing';
		}
		$user_state = self::workflow_user_state_for_row($row);
		if ($user_state !== 'new') {
			return $user_state;
		}
		$active_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		$row_id = (int) ($row->id ?? 0);
		if ($row_id > 0 && $row_id === $active_id && ! self::workflow_is_terminal_state($user_state)) {
			return 'active';
		}
		if ($row_id > 0 && self::workflow_owner_token($row) !== '' && ! self::workflow_is_terminal_state($user_state)) {
			return 'active';
		}
		return $user_state;
	}

	public static function workflow_classification_for_row(object $row): array {
		$user_state = self::user_facing_state_for_row($row);
		$notes = self::row_notes($row);
		$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$manual_kind = sanitize_key((string) ($system['manual_confirmation_required'] ?? ''));
		$manual_reason = sanitize_key((string) ($system['manual_confirmation_reason'] ?? ''));
		$terminal_reason = sanitize_key((string) ($system['workflow_terminal_reason'] ?? ''));
		if ($manual_kind !== '' || $user_state === 'ready_review') {
			return [
				'class' => 'manual',
				'reason' => $manual_reason !== '' ? $manual_reason : ($manual_kind !== '' ? $manual_kind : 'manual_review'),
				'manual_kind' => $manual_kind !== '' ? $manual_kind : 'editorial',
				'user_state' => $user_state,
			];
		}
		if (in_array($user_state, ['new', 'active', 'ready_publish', 'publishing'], true)) {
			return [
				'class' => 'recoverable',
				'reason' => $user_state,
				'manual_kind' => '',
				'user_state' => $user_state,
			];
		}
		if (in_array($user_state, ['published', 'duplicate', 'rejected', 'error'], true)) {
			return [
				'class' => 'terminal',
				'reason' => $terminal_reason !== '' ? $terminal_reason : $user_state,
				'manual_kind' => '',
				'user_state' => $user_state,
			];
		}
		return [
			'class' => 'recoverable',
			'reason' => $user_state !== '' ? $user_state : 'unknown',
			'manual_kind' => '',
			'user_state' => $user_state,
		];
	}

	public static function queue_items_user_facing_summary(array $items): array {
		$summary = [];
		foreach ($items as $item) {
			if (! $item instanceof stdClass) {
				continue;
			}
			$clone = clone $item;
			$clone->state = self::user_facing_state_for_row($item);
			$summary[] = $clone;
		}
		return $summary;
	}

	public static function user_facing_queue_state_counts(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$raw_rows = $wpdb->get_results("SELECT state, COUNT(*) AS qty FROM {$table} GROUP BY state", ARRAY_A);
		if (! is_array($raw_rows) || $raw_rows === []) {
			return [];
		}
		$raw_counts = [];
		foreach ($raw_rows as $row) {
			$raw_state = sanitize_key((string) ($row['state'] ?? ''));
			if ($raw_state === '') {
				continue;
			}
			$raw_counts[$raw_state] = (int) ($row['qty'] ?? 0);
		}
		$recoverable_count =
			(int) ($raw_counts['new'] ?? 0)
			+ (int) ($raw_counts['processing_de'] ?? 0)
			+ (int) ($raw_counts['retry_process'] ?? 0)
			+ (int) ($raw_counts['ready_review'] ?? 0)
			+ (int) ($raw_counts['reserve'] ?? 0);
		$active_count = 0;
		$active_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		if ($active_id > 0) {
			$active_item = self::get_item_summary($active_id);
			if ($active_item instanceof stdClass) {
				$active_state = sanitize_key((string) ($active_item->state ?? ''));
				if (in_array($active_state, ['new', 'processing_de', 'retry_process', 'ready_review', 'reserve'], true)) {
					$active_count = 1;
				}
			}
		}
		$counts = [
			'new' => max(0, $recoverable_count - $active_count),
			'active' => $active_count,
			'ready_publish' => (int) ($raw_counts['ready_publish'] ?? 0) + (int) ($raw_counts['retry_publish'] ?? 0),
			'publishing' => (int) ($raw_counts['publishing'] ?? 0),
			'published' => (int) ($raw_counts['published'] ?? 0),
			'duplicate' => (int) ($raw_counts['duplicate'] ?? 0),
			'rejected' => (int) ($raw_counts['rejected'] ?? 0),
			'error' => (int) ($raw_counts['error'] ?? 0),
		];
		$rows = [];
		foreach ($counts as $state => $count) {
			if ($count <= 0) {
				continue;
			}
			$rows[] = [
				'state' => $state,
				'c' => $count,
			];
		}
		usort($rows, static function (array $a, array $b): int {
			$count_order = (int) ($b['c'] ?? 0) <=> (int) ($a['c'] ?? 0);
			if ($count_order !== 0) {
				return $count_order;
			}
			return strcmp((string) ($a['state'] ?? ''), (string) ($b['state'] ?? ''));
		});
		return $rows;
	}

	public static function bridge_runtime_snapshot(): array {
		return [
			'active_automation_item' => self::bridge_active_automation_item_id(),
			'has_processable_items' => self::bridge_has_processable_items(),
			'next_ready_publish' => self::bridge_next_ready_publish_timestamp(),
			'queue_states' => self::user_facing_queue_state_counts(),
		];
	}

	public static function bridge_health_snapshot(): array {
		$runtime = self::bridge_runtime_snapshot();
		$active = self::bridge_active_owner_row();
		$contract = self::cached_queue_contract_health();
		$acceptance = self::bridge_acceptance_snapshot();

		return [
			'active_automation_item' => (int) ($runtime['active_automation_item'] ?? 0),
			'active_workflow_step' => $active ? self::workflow_step($active) : '',
			'active_workflow_status' => $active ? self::workflow_step_status($active) : '',
			'has_processable_items' => ! empty($runtime['has_processable_items']),
			'next_ready_publish' => (int) ($runtime['next_ready_publish'] ?? 0),
			'queue_states' => is_array($runtime['queue_states'] ?? null) ? $runtime['queue_states'] : [],
			'queue_contract' => $contract,
			'acceptance' => $acceptance,
			'workflow_stage_circuit' => EPV2_Resilience_Manager::workflow_stage_circuit_snapshot(),
			'incident_counters' => self::bridge_incident_counters(),
		];
	}

	private static function bridge_incident_counters(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
		$low_reject_published = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$table}
			WHERE state = 'published'
				AND updated_at >= %s
				AND JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '$._meta.selection.decision')) IN ('low','reject')",
			$since
		));
		$terminal_quarantine = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$table}
			WHERE state IN ('error','rejected')
				AND JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.quarantine_reason')) <> ''"
		);
		$stale_retry = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$table}
			WHERE state IN ('new','retry_process')
				AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.retry_after')), ''), '') <> ''
				AND STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.retry_after')), '%%Y-%%m-%%d %%H:%%i:%%s') < %s",
			gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS)
		));
		return [
			'low_reject_published_24h' => $low_reject_published,
			'terminal_quarantine_rows' => $terminal_quarantine,
			'stale_retry_rows' => $stale_retry,
		];
	}

	private static function bridge_active_automation_item_id(): int {
		$active = self::bridge_active_owner_row();
		return $active ? (int) ($active->id ?? 0) : 0;
	}

	private static function bridge_has_processable_items(): bool {
		$active = self::bridge_active_owner_row();
		if ($active && ! self::workflow_waiting_not_before($active) && ! self::item_requires_manual_confirmation_state($active)) {
			return true;
		}

		foreach (['new', 'retry_process', 'ready_review', 'reserve'] as $state) {
			if (self::bridge_next_processable_row($state) instanceof stdClass) {
				return true;
			}
		}

		return false;
	}

	private static function bridge_next_ready_publish_timestamp(): ?int {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results(
			"SELECT id, state, admin_notes, created_at, updated_at
			FROM {$table}
			WHERE state IN ('ready_publish','retry_publish')
			ORDER BY updated_at ASC, id ASC
			LIMIT 30"
		);
		if (! is_array($rows) || $rows === []) {
			return null;
		}

		$earliest_wait = 0;
		foreach ($rows as $row) {
			if (! ($row instanceof stdClass)) {
				continue;
			}
			$notes = self::row_notes($row);
			$not_before = (int) ($notes['_system']['publish_not_before'] ?? 0);
			if ($not_before <= 0 || $not_before <= time()) {
				return time();
			}
			if ($earliest_wait <= 0 || $not_before < $earliest_wait) {
				$earliest_wait = $not_before;
			}
		}

		return $earliest_wait > 0 ? $earliest_wait : null;
	}

	private static function cached_queue_contract_health(): array {
		$cache_key = 'epv2_bridge_queue_contract_health';
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached;
		}
		if (class_exists('EPV2_AI_Processor') && method_exists('EPV2_AI_Processor', 'queue_contract_regression_check')) {
			$check = EPV2_AI_Processor::queue_contract_regression_check(300);
			$violations = is_array($check['violations'] ?? null) ? $check['violations'] : [];
			$issue_counts = [];
			foreach ($violations as $violation) {
				foreach ((array) ($violation['issues'] ?? []) as $issue) {
					$issue = sanitize_key((string) $issue);
					if ($issue === '') {
						continue;
					}
					$issue_counts[$issue] = ($issue_counts[$issue] ?? 0) + 1;
				}
			}
			arsort($issue_counts);
			$snapshot = [
				'checked' => (int) ($check['checked'] ?? 0),
				'violations_count' => count($violations),
				'top_issues' => array_slice($issue_counts, 0, 5, true),
				'status' => $violations === [] ? 'ok' : 'warn',
				'checked_at' => gmdate('Y-m-d H:i:s'),
			];
			set_transient($cache_key, $snapshot, 60);
			return $snapshot;
		}
		$snapshot = [
			'checked' => 0,
			'violations_count' => 0,
			'top_issues' => [],
			'status' => 'pending',
		];
		set_transient($cache_key, $snapshot, 30);
		return $snapshot;
	}

	private static function bridge_acceptance_snapshot(int $limit = 12): array {
		$cache_key = 'epv2_bridge_acceptance_snapshot';
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		$items = self::get_queue_items_summary([
			'states' => ['published'],
			'limit' => max(1, min(50, $limit)),
		]);
		$streak = 0;
		$checked = 0;
		$recent = [];

		foreach ($items as $item) {
			if (! ($item instanceof stdClass)) {
				continue;
			}
			$checked++;
			$notes = self::row_notes($item);
			$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$payload = self::row_payload($item);
			$auto_mode = (string) ($item->mode ?? '') === 'auto';
			$manual_required = (string) ($system['manual_confirmation_required'] ?? '') !== '';
			$has_posts = self::row_has_live_published_posts($item);
			$gate = EPV2_Publish_Gate::evaluate($item, $payload, [
				'context' => 'acceptance',
			]);
			$selection_publishable = ! empty($gate['selection_publishable']);
			$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			$checklist = is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
			$publish_grade = ! empty($checklist['ready_publish'])
				&& ! empty($checklist['translations_ready'])
				&& ! empty($checklist['publish_finish_ready'])
				&& (int) ($meta['quality']['score'] ?? 0) >= 90
				&& (int) ($meta['seo_quality']['score'] ?? 0) >= 90
				&& (int) ($meta['release_quality']['score'] ?? 0) >= 90
				&& (int) ($meta['google_quality']['score'] ?? 0) >= 90;
			$passes = $auto_mode && ! $manual_required && $has_posts && $publish_grade && ! empty($gate['allowed']);

			$recent[] = [
				'id' => (int) ($item->id ?? 0),
				'updated_at' => (string) ($item->updated_at ?? ''),
				'auto_mode' => $auto_mode,
				'manual_required' => $manual_required,
				'has_published_posts' => $has_posts,
				'selection_publishable' => $selection_publishable,
				'publish_gate_allowed' => ! empty($gate['allowed']),
				'publish_gate_blockers' => array_values((array) ($gate['blockers'] ?? [])),
				'publish_grade' => $publish_grade,
				'passes' => $passes,
			];

			if ($passes && $streak === ($checked - 1)) {
				$streak++;
				continue;
			}
			if (! $passes) {
				break;
			}
		}

		$snapshot = [
			'target' => 10,
			'checked' => $checked,
			'consecutive_autonomous_publish_grade' => $streak,
			'remaining_to_target' => max(0, 10 - $streak),
			'recent' => $recent,
			'status' => $streak >= 10 ? 'accepted' : ($streak > 0 ? 'in_progress' : 'not_proven'),
		];
		set_transient($cache_key, $snapshot, 30);
		return $snapshot;
	}

	private static function bridge_active_owner_row(): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$active_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		if ($active_id > 0) {
			$item = $wpdb->get_row($wpdb->prepare(
				"SELECT id, state, error_message, admin_notes, created_at, updated_at
				FROM {$table}
				WHERE id = %d
				LIMIT 1",
				$active_id
			));
			if ($item && self::current_row_should_keep_active_pointer($item)) {
				return $item;
			}
		}

		$item = $wpdb->get_row(
			"SELECT id, state, error_message, admin_notes, created_at, updated_at
			FROM {$table}
			WHERE JSON_UNQUOTE(JSON_EXTRACT(admin_notes, '$._system.workflow_owner_token')) <> ''
			ORDER BY updated_at DESC, id DESC
			LIMIT 1"
		);
		if ($item && self::current_row_should_keep_active_pointer($item)) {
			return $item;
		}

		return null;
	}

	private static function bridge_next_processable_row(string $state): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT " . self::SUMMARY_FIELDS . "
			FROM {$table}
			WHERE state = %s
			ORDER BY created_at ASC, id ASC
			LIMIT 25",
			$state
		));
		if (! is_array($rows) || $rows === []) {
			return null;
		}

		foreach ($rows as $row) {
			if (! ($row instanceof stdClass)) {
				continue;
			}
			if (self::item_requires_manual_confirmation_state($row)) {
				continue;
			}
			if (self::workflow_waiting_not_before($row)) {
				continue;
			}
			if (! self::item_is_processable_read_only($row, false)) {
				continue;
			}
			if ((string) ($row->state ?? '') === 'new') {
				if (
					self::row_has_non_publish_grade_selection($row)
					&& self::workflow_owner_token($row) === ''
					&& self::workflow_step($row) === ''
				) {
					continue;
				}
				if (
					self::workflow_user_state_for_row($row) === 'ready_publish'
					&& self::workflow_step($row) === ''
					&& self::row_processing_stage($row) === ''
				) {
					continue;
				}
			}
			return $row;
		}

		return null;
	}

	private static function workflow_migration_candidate_rows(int $limit = 500): array {
		global $wpdb;
		$table = $wpdb->prefix . 'epv2_queue';
		$limit = max(1, min(2000, $limit));
		return $wpdb->get_results(
			"SELECT " . self::SUMMARY_FIELDS . " FROM {$table}
			ORDER BY created_at ASC
			LIMIT {$limit}"
		);
	}

	public static function workflow_v2_dry_run_migration(int $limit = 500): array {
		$rows = self::workflow_migration_candidate_rows($limit);
		$report = [
			'total_rows' => 0,
			'active_rows' => 0,
			'new_rows' => 0,
			'ready_publish_rows' => 0,
			'terminal_rows' => 0,
			'ambiguous_rows' => [],
			'rows' => [],
		];

		$current_active_id = (int) get_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, 0);
		$assigned_active = false;

		foreach ($rows as $row) {
			$report['total_rows']++;
			$legacy_state = (string) ($row->state ?? '');
			$new_state = self::workflow_user_state_for_row($row);
			$workflow_step = self::workflow_infer_step_from_row($row);
			$notes = self::row_notes($row);
			$retry_after = (string) ($notes['_system']['retry_after'] ?? '');
			$not_before = $retry_after !== '' ? (string) strtotime($retry_after) : '';
			$is_terminal = self::workflow_is_terminal_state($new_state);
			$should_be_active = false;

			if (! $is_terminal && $new_state !== 'ready_publish' && ! $assigned_active) {
				$should_be_active = $current_active_id > 0
					? ((int) $row->id === $current_active_id)
					: false;
				if ($should_be_active) {
					$assigned_active = true;
				}
			}

			if ($is_terminal) {
				$report['terminal_rows']++;
			} elseif ($new_state === 'ready_publish') {
				$report['ready_publish_rows']++;
			} else {
				$report['new_rows']++;
			}
			if ($should_be_active) {
				$report['active_rows']++;
			}

			$ambiguous = false;
			if (! $is_terminal && $new_state === 'new' && $workflow_step === 'publish_ready_gate' && self::row_processing_stage($row) === '') {
				$ambiguous = true;
				$report['ambiguous_rows'][] = [
					'id' => (int) $row->id,
					'legacy_state' => $legacy_state,
					'workflow_step' => $workflow_step,
					'reason' => 'ready-like payload stayed recoverable without explicit ready_publish state',
				];
			}

			$report['rows'][] = [
				'id' => (int) $row->id,
				'legacy_state' => $legacy_state,
				'new_state' => $new_state,
				'workflow_step' => $workflow_step,
				'workflow_not_before' => $not_before,
				'active' => $should_be_active ? 1 : 0,
				'ambiguous' => $ambiguous ? 1 : 0,
			];
		}

		return $report;
	}

	public static function workflow_v2_apply_migration(int $limit = 500): array {
		$rows = self::workflow_migration_candidate_rows($limit);
		$report = self::workflow_v2_dry_run_migration($limit);
		$plan_by_id = [];
		foreach ((array) ($report['rows'] ?? []) as $planned_row) {
			$plan_by_id[(int) ($planned_row['id'] ?? 0)] = $planned_row;
		}

		delete_option(self::OPTION_ACTIVE_AUTOMATION_ITEM);

		foreach ($rows as $row) {
			$row_id = (int) ($row->id ?? 0);
			if ($row_id <= 0 || empty($plan_by_id[$row_id])) {
				continue;
			}
			$plan = $plan_by_id[$row_id];
			$new_state = (string) ($plan['new_state'] ?? 'new');
			$workflow_step = sanitize_key((string) ($plan['workflow_step'] ?? 'build_de_master'));
			$workflow_not_before = (string) ($plan['workflow_not_before'] ?? '');
			$is_active = ! empty($plan['active']);
			$terminal_reason = self::workflow_is_terminal_state($new_state) ? $new_state : '';

			self::workflow_system_update($row_id, [
				'workflow_version' => self::WORKFLOW_VERSION,
				'workflow_owner_token' => $is_active ? wp_generate_password(24, false, false) : '',
				'workflow_claimed_at' => $is_active ? gmdate('Y-m-d H:i:s') : '',
				'workflow_heartbeat_at' => $is_active ? gmdate('Y-m-d H:i:s') : '',
				'workflow_step' => $workflow_step,
				'workflow_step_status' => $is_active ? 'claimed' : 'pending',
				'workflow_step_attempts' => 0,
				'workflow_not_before' => $workflow_not_before,
				'workflow_terminal_reason' => $terminal_reason,
			]);

			if ((string) ($row->state ?? '') !== $new_state) {
				self::update_fields($row_id, ['state' => $new_state]);
			}

			if ($is_active) {
				update_option(self::OPTION_ACTIVE_AUTOMATION_ITEM, $row_id, false);
			}
		}

		return $report;
	}

	public static function workflow_system_payload(object $row): array {
		$notes = self::row_notes($row);
		$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		if (empty($system['workflow_version'])) {
			$system['workflow_version'] = self::WORKFLOW_VERSION;
		}
		return $system;
	}

	public static function workflow_system_update(int $id, array $fields): void {
		$current = self::get_item_summary($id);
		if (! $current) {
			return;
		}
		$notes = self::row_notes($current);
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$notes['_system']['workflow_version'] = self::WORKFLOW_VERSION;
		foreach ($fields as $key => $value) {
			$key = sanitize_key((string) $key);
			if ($key === '') {
				continue;
			}
			$notes['_system'][$key] = $value;
		}
		self::update_fields($id, [
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
	}

	public static function workflow_owner_token(object $row): string {
		$system = self::workflow_system_payload($row);
		return (string) ($system['workflow_owner_token'] ?? '');
	}

	public static function workflow_step(object $row): string {
		$system = self::workflow_system_payload($row);
		return sanitize_key((string) ($system['workflow_step'] ?? ''));
	}

	public static function workflow_step_status(object $row): string {
		$system = self::workflow_system_payload($row);
		return sanitize_key((string) ($system['workflow_step_status'] ?? ''));
	}

	public static function workflow_not_before_timestamp(object $row): int {
		$system = self::workflow_system_payload($row);
		$workflow_not_before = $system['workflow_not_before'] ?? '';
		if (is_numeric($workflow_not_before)) {
			$timestamp = (int) $workflow_not_before;
			if ($timestamp > 0) {
				return $timestamp;
			}
		}
		if (is_string($workflow_not_before) && $workflow_not_before !== '') {
			$timestamp = strtotime($workflow_not_before);
			if ($timestamp !== false && $timestamp > 0) {
				return $timestamp;
			}
		}
		$retry_after = $system['retry_after'] ?? '';
		if (is_numeric($retry_after)) {
			$timestamp = (int) $retry_after;
			if ($timestamp > 0) {
				return $timestamp;
			}
		}
		if (is_string($retry_after) && $retry_after !== '') {
			$timestamp = strtotime($retry_after);
			if ($timestamp !== false && $timestamp > 0) {
				return $timestamp;
			}
		}
		return 0;
	}

	public static function workflow_waiting_not_before(object $row): bool {
		$not_before = self::workflow_not_before_timestamp($row);
		return $not_before > time();
	}

	private static function workflow_stage_attempt_limit(string $stage): int {
		// Phase 2.4 — Architecture audit section 6 tradeoff #4:
		// "2 attempts per failed step, then manual_review".
		// Each stage gets the same independent budget; once exhausted,
		// quarantine routes the item to manual_review (Queue::quarantine).
		$stage = sanitize_key($stage);
		return match ($stage) {
			'build_de_master',
			'rebuild_bundle',
			'publish_finish',
			'publish_ready_gate',
			'translate_uk',
			'translate_en',
			'translate_finish' => 2,
			default => 2,
		};
	}

	private static function recent_process_attempt_count(int $item_id, string $stage = '', int $window_seconds = DAY_IN_SECONDS): int {
		global $wpdb;
		if ($item_id <= 0) {
			return 0;
		}
		$table = $wpdb->prefix . 'epv2_runs';
		// Architecture audit phase 2.4 mandates "2 attempts per failed
		// step then manual_review". The default 24-hour window meant an
		// item that failed twice in the morning and twice in the
		// afternoon got quarantined immediately on the next run, even
		// though the morning failures were stale. 2-hour window keeps
		// the per-step semantics tight: 2 attempts within 2 hours →
		// quarantine. Anything older than that does not count.
		$default_window = 2 * HOUR_IN_SECONDS;
		$effective_window = $window_seconds === DAY_IN_SECONDS
			? $default_window
			: max(HOUR_IN_SECONDS, $window_seconds);
		$since = gmdate('Y-m-d H:i:s', time() - $effective_window);
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$table}
			WHERE job_name = 'process'
				AND started_at >= %s
				AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.last_item_id')) AS UNSIGNED) = %d",
			$since,
			$item_id
		));
	}
}
