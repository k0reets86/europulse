<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Queue {
	public static function get_item(int $id) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}epv2_queue WHERE id = %d", $id));
	}

	public static function add_item(array $item): int {
		global $wpdb;
		$hashes = EPV2_Deduplicator::hashes($item['title'] ?? '', $item['content'] ?? '');
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
				'original_url' => esc_url_raw($item['url'] ?? ''),
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
		return $wpdb->get_results("SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT {$limit}");
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
		if (self::has_active_processing_item()) {
			return null;
		}
		$states = ['new', 'retry_process', 'ready_review'];
		$items = self::get_items(['states' => $states, 'limit' => 30]);
		$items = array_values(array_filter($items, static function ($item) use ($ignore_retry_after): bool {
			if (self::row_has_live_published_posts($item)) {
				self::mark_state((int) $item->id, 'published', ['error_message' => '']);
				return false;
			}
			if (EPV2_AI_Processor::item_has_expired_live_angle($item)) {
				self::mark_state((int) $item->id, 'rejected', [
					'error_message' => 'Материал потерял актуальность по времени и live-углу, поэтому снят из автоматической очереди.',
				]);
				return false;
			}
			if (self::review_rebuild_exhausted($item)) {
				self::mark_state((int) $item->id, 'ready_review', [
					'error_message' => 'Материал не удалось автоматически дотянуть даже после углублённой автодоработки. Пакет переведён в ручную редакционную проверку.',
				]);
				return false;
			}
			$error_message = (string) ($item->error_message ?? '');
			if ($error_message !== '' && preg_match('/устарел|устарела|lost relevance|потеряла актуальность/i', $error_message) === 1) {
				self::mark_state((int) $item->id, 'rejected', [
					'error_message' => $error_message,
				]);
				return false;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			if (is_array($payload) && ! empty($payload)) {
				$normalized_payload = EPV2_AI_Processor::normalize_existing_payload($payload);
				if ($normalized_payload !== $payload) {
					self::update_fields((int) $item->id, [
						'ai_payload' => wp_json_encode($normalized_payload, JSON_UNESCAPED_UNICODE),
					]);
					$item->ai_payload = wp_json_encode($normalized_payload, JSON_UNESCAPED_UNICODE);
					$payload = $normalized_payload;
				}
				if (EPV2_AI_Processor::payload_is_publish_ready($payload)) {
					self::mark_state((int) $item->id, 'ready_publish', [
						'error_message' => '',
					]);
					return false;
				}
				if (! self::automation_requires_publish_grade() && EPV2_AI_Processor::payload_is_review_ready($payload)) {
					self::mark_state((int) $item->id, 'ready_review', [
						'error_message' => '',
					]);
					return false;
				}
			}
			if ((string) ($item->state ?? '') === 'ready_review') {
				if (! self::ready_review_requires_automation_resume($item)) {
					return false;
				}
				return self::ready_review_due($item);
			}
			if (self::retry_process_is_exhausted($item)) {
				$score = self::score_from_row((array) $item);
				if ($score >= 55 || (is_array($payload) && ! empty($payload))) {
					self::mark_state((int) $item->id, 'ready_review', [
						'error_message' => 'Материал не достиг publish-grade после нескольких автоматических попыток и переведён в редакционную доработку.',
					]);
				} else {
					self::mark_state((int) $item->id, 'rejected', [
						'error_message' => 'Материал снят из автоматической очереди после нескольких слабых безрезультатных попыток.',
					]);
				}
				return false;
			}
			return $item->state !== 'retry_process' || $ignore_retry_after || EPV2_Resilience_Manager::retry_due($item);
		}));
		if ($items === []) {
			return null;
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
		return $items[0] ?? null;
	}

	public static function promote_publish_ready_payloads(array $states = ['retry_process']): int {
		$states = array_values(array_filter(array_map('strval', $states)));
		if ($states === []) {
			return 0;
		}
		$items = self::get_items(['states' => $states, 'limit' => 30]);
		if ($items === []) {
			return 0;
		}
		$promoted = 0;
		foreach ($items as $item) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			if (! is_array($payload) || $payload === []) {
				continue;
			}
			$normalized = EPV2_AI_Processor::normalize_existing_payload($payload);
			if ($normalized !== $payload) {
				self::update_fields((int) $item->id, [
					'ai_payload' => wp_json_encode($normalized, JSON_UNESCAPED_UNICODE),
				]);
			}
			if (! EPV2_AI_Processor::payload_is_publish_ready($normalized)) {
				continue;
			}
			self::mark_state((int) $item->id, 'ready_publish', [
				'ai_payload' => wp_json_encode($normalized, JSON_UNESCAPED_UNICODE),
				'error_message' => '',
			]);
			$promoted++;
		}
		return $promoted;
	}

	public static function has_processable_items(): bool {
		return self::next_item_for_processing(false) !== null;
	}

	public static function normalize_terminal_retry_process_items(int $limit = 25): int {
		$items = self::get_items(['states' => ['retry_process'], 'limit' => max(1, min(100, $limit))]);
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

	public static function next_item_for_publish(): ?object {
		$items = self::get_items(['states' => ['ready_publish', 'retry_publish'], 'limit' => 30]);
		$items = array_values(array_filter($items, static function ($item): bool {
			if (self::row_has_live_published_posts($item)) {
				self::mark_state((int) $item->id, 'published', ['error_message' => '']);
				return false;
			}
			if ($item->state === 'retry_publish' && ! EPV2_Resilience_Manager::retry_due($item)) {
				return false;
			}
			return self::publish_due($item);
		}));
		if ($items === []) {
			return null;
		}
		usort($items, [self::class, 'compare_publish_schedule_order']);
		return $items[0] ?? null;
	}

	public static function next_ready_publish_timestamp(): ?int {
		$items = self::get_items(['states' => ['ready_publish', 'retry_publish'], 'limit' => 30]);
		if ($items === []) {
			return null;
		}
		usort($items, [self::class, 'compare_publish_schedule_order']);
		foreach ($items as $item) {
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$not_before = (int) ($notes['_system']['publish_not_before'] ?? 0);
			if ($not_before > 0) {
				return $not_before;
			}
		}
		return null;
	}

	public static function normalize_ready_publish_schedule(bool $allow_current_slot = true): void {
		$items = self::get_items(['states' => ['ready_publish', 'retry_publish', 'publishing'], 'limit' => 50]);
		if ($items === []) {
			return;
		}

		usort($items, [self::class, 'compare_publish_queue_order']);

		$interval = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS;
		$first_slot = self::first_waiting_publish_slot();
		$maxReasonable = $first_slot + ($interval * 8);
		$previous_slot = 0;

		foreach ($items as $item) {
			if (in_array((string) ($item->state ?? ''), ['ready_publish', 'retry_publish'], true)) {
				$payload = json_decode((string) ($item->ai_payload ?? ''), true);
				$payload = is_array($payload) ? $payload : [];
				if ($payload !== []) {
					$normalized = EPV2_AI_Processor::normalize_existing_payload($payload);
					if ($normalized !== $payload) {
						self::update_fields((int) $item->id, [
							'ai_payload' => wp_json_encode($normalized, JSON_UNESCAPED_UNICODE),
						]);
						$item->ai_payload = wp_json_encode($normalized, JSON_UNESCAPED_UNICODE);
						$payload = $normalized;
					}
					if (! EPV2_AI_Processor::payload_is_publish_ready($payload)) {
						$rework = EPV2_AI_Processor::item_is_auto_rework_candidate($item, $payload);
						$finish = ! $rework && EPV2_AI_Processor::item_is_auto_finish_candidate($item, $payload);
						self::mark_state((int) $item->id, $rework || $finish ? 'retry_process' : 'ready_review', [
							'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
							'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
							'error_message' => 'Пакет снят из очереди публикации: после пересчёта больше не проходит publish-grade.',
						]);
						continue;
					}
				}
			}
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$current = (int) ($notes['_system']['publish_not_before'] ?? 0);
			$ready_at = (string) ($notes['_system']['ready_publish_at'] ?? '');
			$isDue = $current > 0 && $current <= time();
			$earliest_slot = self::earliest_publish_slot_from_ready_at($ready_at);
			$minimum_slot = $previous_slot > 0 ? max($previous_slot + $interval, $earliest_slot) : max($earliest_slot, $current > 0 ? $current : $first_slot);
			$needsRepair = ! $isDue && (
				$current <= 0
				|| $current > $maxReasonable
				|| $current !== $minimum_slot
			);
			if ($needsRepair) {
				$notes['_system']['ready_publish_at'] = ! empty($notes['_system']['ready_publish_at'])
					? (string) $notes['_system']['ready_publish_at']
					: ((string) ($item->updated_at ?? '') !== '' ? (string) $item->updated_at : gmdate('Y-m-d H:i:s'));
				$notes['_system']['publish_not_before'] = $minimum_slot;
				self::update_fields((int) $item->id, [
					'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
				]);
				$current = $minimum_slot;
			}
			$previous_slot = $current > 0 ? $current : $minimum_slot;
		}
	}

	public static function mark_state(int $id, string $state, array $extra = []): void {
		global $wpdb;
		$current = self::get_item($id);
		$notes = json_decode((string) ($current->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		if (! empty($extra['admin_notes'])) {
			$incoming = json_decode((string) $extra['admin_notes'], true);
			if (is_array($incoming)) {
				$notes = array_replace_recursive($notes, $incoming);
			}
		}
		$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		if ($state === 'ready_publish') {
			$existing_not_before = (int) ($notes['_system']['publish_not_before'] ?? 0);
			$existing_ready_at = (string) ($notes['_system']['ready_publish_at'] ?? '');
			$current_state = (string) ($current->state ?? '');
			if ($current_state === 'ready_publish' && $existing_not_before > 0) {
				$notes['_system']['ready_publish_at'] = $existing_ready_at !== '' ? $existing_ready_at : gmdate('Y-m-d H:i:s');
				$notes['_system']['publish_not_before'] = $existing_not_before;
			} else {
				$notes['_system']['ready_publish_at'] = gmdate('Y-m-d H:i:s');
				$notes['_system']['publish_not_before'] = self::next_publish_slot_for_queue($id);
			}
			if (empty($extra['admin_notes'])) {
				$extra['admin_notes'] = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
			}
			if (! array_key_exists('error_message', $extra)) {
				$extra['error_message'] = '';
			}
		} elseif ($state === 'published') {
			unset($notes['_system']['publish_not_before'], $notes['_system']['ready_publish_at']);
			if (! array_key_exists('error_message', $extra)) {
				$extra['error_message'] = '';
			}
		} elseif (! in_array($state, ['ready_publish', 'retry_publish', 'publishing'], true)) {
			unset($notes['_system']['publish_not_before'], $notes['_system']['ready_publish_at']);
		}
		if (! in_array($state, ['retry_process', 'retry_publish'], true)) {
			unset($notes['_system']['retry_after']);
		}
		$active_live_states = ['processing_de', 'publishing'];
		if (in_array($state, $active_live_states, true)) {
			$notes['_system']['live_status'] = self::default_live_status_for_state($state);
			$notes['_system']['live_status_code'] = $state;
		} else {
			unset($notes['_system']['live_status'], $notes['_system']['live_status_code']);
		}
		if (in_array($state, ['published', 'rejected', 'error', 'duplicate', 'draft_created', 'pending_review', 'partially_created'], true)) {
			unset($notes['_system']['live_status'], $notes['_system']['live_status_code']);
		}
		if (empty($extra['admin_notes'])) {
			$extra['admin_notes'] = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
		} else {
			$extra['admin_notes'] = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
		}
		$data = array_merge(['state' => $state], $extra);
		$wpdb->update($wpdb->prefix . 'epv2_queue', $data, ['id' => $id]);
	}

	public static function set_live_status(int $id, string $message, string $code = ''): void {
		$current = self::get_item($id);
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
		$items = self::get_items(['states' => ['ready_publish', 'retry_publish', 'publishing'], 'limit' => 50]);
		$latest_not_before = 0;
		foreach ($items as $item) {
			if ((int) ($item->id ?? 0) === $item_id) {
				continue;
			}
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$not_before = (int) ($notes['_system']['publish_not_before'] ?? 0);
			if ($not_before > $latest_not_before) {
				$latest_not_before = $not_before;
			}
		}
		if ($latest_not_before <= 0) {
			return $base_slot;
		}
		$queued = max($base_slot, $latest_not_before + ($interval_minutes * MINUTE_IN_SECONDS));
		return max($base_slot, EPV2_Jobs::next_publish_slot_after($queued - 1));
	}

	private static function first_waiting_publish_slot(): int {
		$interval = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS;
		$next_publish = EPV2_Jobs::next_publish_timestamp();
		if (is_int($next_publish) && $next_publish > time()) {
			return EPV2_Jobs::next_publish_slot_after($next_publish + 1);
		}
		return EPV2_Jobs::next_publish_slot_after(time() + $interval);
	}

	private static function earliest_publish_slot_from_ready_at(string $readyAt): int {
		$interval = max(5, (int) EPV2_Settings::get('publish_interval_minutes', 5)) * MINUTE_IN_SECONDS;
		$readyTs = strtotime($readyAt);
		if ($readyTs === false || $readyTs <= 0) {
			return self::first_waiting_publish_slot();
		}
		return EPV2_Jobs::next_publish_slot_after($readyTs + $interval);
	}

	private static function compare_publish_queue_order(object $a, object $b): int {
		$aNotes = json_decode((string) ($a->admin_notes ?? ''), true);
		$bNotes = json_decode((string) ($b->admin_notes ?? ''), true);
		$aNotes = is_array($aNotes) ? $aNotes : [];
		$bNotes = is_array($bNotes) ? $bNotes : [];
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

	private static function compare_publish_schedule_order(object $a, object $b): int {
		$aNotes = json_decode((string) ($a->admin_notes ?? ''), true);
		$bNotes = json_decode((string) ($b->admin_notes ?? ''), true);
		$aNotes = is_array($aNotes) ? $aNotes : [];
		$bNotes = is_array($bNotes) ? $bNotes : [];
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

	public static function update_fields(int $id, array $fields): void {
		if (empty($fields)) {
			return;
		}
		global $wpdb;
		$wpdb->update($wpdb->prefix . 'epv2_queue', $fields, ['id' => $id]);
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
		$terminal_states = ['duplicate', 'rejected', 'published', 'draft_created', 'pending_review', 'partially_created', 'error'];
		$placeholders = implode(',', array_fill(0, count($terminal_states), '%s'));
		$sql = "DELETE FROM {$wpdb->prefix}epv2_queue WHERE state IN ({$placeholders}) AND created_at < %s";
		$args = array_merge($terminal_states, [$cutoff]);
		$result = $wpdb->query($wpdb->prepare($sql, ...$args));
		return (int) $result;
	}

	public static function prune_rejected(int $minutes = 1440): int {
		global $wpdb;
		$minutes = max(1, min(1440, $minutes));
		$cutoff = gmdate('Y-m-d H:i:s', time() - ($minutes * MINUTE_IN_SECONDS));
		$result = $wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}epv2_queue WHERE state = 'rejected' AND updated_at < %s",
			$cutoff
		));
		return (int) $result;
	}

	public static function prune_new_stale(int $hours = 18): int {
		global $wpdb;
		$hours = max(1, min(168, $hours));
		$cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));
		$result = $wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}epv2_queue WHERE state = 'new' AND created_at < %s",
			$cutoff
		));
		return (int) $result;
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

		if ($delete_ids !== []) {
			self::delete_items($delete_ids);
		}

		return count($delete_ids);
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
		return $score;
	}

	private static function processing_stage_priority(object $row): int {
		$stage = self::row_processing_stage($row);
		if ($stage === 'translate_finish') {
			$notes = json_decode((string) ($row->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$review_rebuild = (int) ($notes['_system']['retries']['review_rebuild'] ?? 0);
			if ($review_rebuild >= 2) {
				return 220;
			}
			return 400;
		}
		if ($stage !== '') {
			return 320;
		}
		$state = (string) ($row->state ?? '');
		$message = mb_strtolower((string) ($row->error_message ?? ''));
		if ($state === 'retry_process' && preg_match('/featured image|featured media|publish threshold|minimum review threshold|broken multilingual/u', $message) === 1) {
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

	private static function row_processing_stage(object $row): string {
		$payload = json_decode((string) ($row->ai_payload ?? ''), true);
		$payload = is_array($payload) ? $payload : [];
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		return sanitize_key((string) ($meta['pipeline_stage'] ?? ''));
	}

	private static function ready_review_requires_automation_resume(object $row): bool {
		if ((string) ($row->state ?? '') !== 'ready_review') {
			return false;
		}
		$payload = json_decode((string) ($row->ai_payload ?? ''), true);
		$payload = is_array($payload) ? $payload : [];
		if ($payload === []) {
			return false;
		}
		$stage = self::row_processing_stage($row);
		if ($stage !== '') {
			return true;
		}
		if (! empty($payload['_meta']['translations_deferred'])) {
			return true;
		}
		return ! EPV2_AI_Processor::payload_is_publish_ready($payload);
	}

	private static function ready_review_due(object $row): bool {
		if ((string) ($row->state ?? '') !== 'ready_review') {
			return false;
		}
		$notes = json_decode((string) ($row->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
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
		$payload = json_decode((string) ($row->ai_payload ?? ''), true);
		$payload = is_array($payload) ? $payload : [];
		if (
			EPV2_AI_Processor::item_has_exhausted_auto_rework($row, $payload)
			|| EPV2_AI_Processor::item_has_exhausted_auto_finish($row, $payload)
		) {
			return true;
		}
		$notes = json_decode((string) ($row->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
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
		$payload = json_decode((string) ($row->ai_payload ?? ''), true);
		$payload = is_array($payload) ? $payload : [];
		$notes = json_decode((string) ($row->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
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
		$payload = json_decode((string) ($row->ai_payload ?? ''), true);
		return ! empty($payload['_meta']['breaking']);
	}

	private static function row_is_top_story(object $row): bool {
		$payload = json_decode((string) ($row->ai_payload ?? ''), true);
		return ! empty($payload['_meta']['top_story']);
	}

	private static function publish_due(object $row): bool {
		$notes = json_decode((string) ($row->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$notBefore = (int) ($notes['_system']['publish_not_before'] ?? 0);
		if ($notBefore <= 0 && (string) ($row->state ?? '') === 'ready_publish') {
			self::backfill_publish_schedule((int) ($row->id ?? 0), $notes, true, (string) ($row->updated_at ?? ''));
			return true;
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

	private static function automation_requires_publish_grade(): bool {
		$mode = (string) EPV2_Settings::get('mode', 'semi');
		$default_status = (string) EPV2_Settings::get('default_post_status', 'draft');
		return $mode === 'auto' && in_array($default_status, ['publish', 'pending'], true);
	}

	private static function has_active_processing_item(int $maxAge = 90): bool {
		$items = self::get_items(['states' => ['processing_de'], 'limit' => 10]);
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
		$maxAge = max($maxAge, $lockFreshWindow);
		foreach ($items as $item) {
			$updated = strtotime((string) ($item->updated_at ?? '')) ?: 0;
			if ($updated <= 0) {
				continue;
			}
			if ((time() - $updated) < $maxAge) {
				return true;
			}
		}
		return false;
	}

	private static function default_live_status_for_state(string $state): string {
		return match ($state) {
			'processing_de' => 'Обрабатываю материал и усиливаю фактуру.',
			'publishing' => 'Собираю пакет публикации и записываю его на сайт.',
			default => '',
		};
	}
}
