<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Resilience_Manager {
	private const OPTION_PROVIDER_HEALTH = 'epv2_provider_health';
	private const OPTION_SOURCE_HEALTH = 'epv2_source_health';

	public static function cleanup(): void {
		EPV2_Lock_Manager::cleanup();
		EPV2_Runs::cleanup_abandoned_started(120);
		self::normalize_stale_error_items();
		self::recover_worker_infra_errors();
		self::restore_rejected_reviewable_items();
		self::normalize_retry_metadata();
		self::normalize_terminal_review_candidates();
		self::normalize_targeted_retry_windows();
		self::hydrate_review_payloads();
		EPV2_Queue::normalize_terminal_retry_process_items();
		self::reclaim_stuck_items();
		$promoted = EPV2_Queue::promote_publish_ready_payloads(['retry_process']);
		if ($promoted > 0 && ! EPV2_Lock_Manager::is_active('publish')) {
			EPV2_Queue::normalize_ready_publish_schedule();
			EPV2_Jobs::enqueue_publish();
		}
	}

	public static function provider_available(string $provider): bool {
		$provider = sanitize_key($provider);
		if ($provider === '') {
			return true;
		}
		$health = get_option(self::OPTION_PROVIDER_HEALTH, []);
		$until = (int) (($health[$provider]['cooldown_until'] ?? 0));
		return $until <= time();
	}

	public static function register_provider_success(string $provider): void {
		$provider = sanitize_key($provider);
		if ($provider === '') {
			return;
		}
		$health = get_option(self::OPTION_PROVIDER_HEALTH, []);
		$health[$provider] = [
			'consecutive_failures' => 0,
			'cooldown_until' => 0,
			'last_error' => '',
			'last_success_at' => time(),
		];
		update_option(self::OPTION_PROVIDER_HEALTH, $health, false);
	}

	public static function register_provider_failure(string $provider, string $message): void {
		$provider = sanitize_key($provider);
		if ($provider === '') {
			return;
		}
		$health = get_option(self::OPTION_PROVIDER_HEALTH, []);
		$current = is_array($health[$provider] ?? null) ? $health[$provider] : [];
		$failures = (int) ($current['consecutive_failures'] ?? 0) + 1;
		$cooldownUntil = (int) ($current['cooldown_until'] ?? 0);
		$threshold = max(2, (int) EPV2_Settings::get('provider_circuit_failures', 3));
		if ($failures >= $threshold) {
			$cooldownUntil = time() + (max(5, (int) EPV2_Settings::get('provider_circuit_cooldown_minutes', 30)) * MINUTE_IN_SECONDS);
		}
		$health[$provider] = [
			'consecutive_failures' => $failures,
			'cooldown_until' => $cooldownUntil,
			'last_error' => self::humanize_error($message),
			'last_failure_at' => time(),
		];
		update_option(self::OPTION_PROVIDER_HEALTH, $health, false);
	}

	public static function source_on_cooldown(int $source_id): bool {
		$health = get_option(self::OPTION_SOURCE_HEALTH, []);
		$until = (int) (($health[$source_id]['cooldown_until'] ?? 0));
		return $until > time();
	}

	public static function register_source_success(int $source_id): void {
		$health = get_option(self::OPTION_SOURCE_HEALTH, []);
		$health[$source_id] = [
			'consecutive_failures' => 0,
			'cooldown_until' => 0,
			'last_error' => '',
			'last_success_at' => time(),
		];
		update_option(self::OPTION_SOURCE_HEALTH, $health, false);
	}

	public static function register_source_failure(int $source_id, string $message): void {
		$health = get_option(self::OPTION_SOURCE_HEALTH, []);
		$current = is_array($health[$source_id] ?? null) ? $health[$source_id] : [];
		$failures = (int) ($current['consecutive_failures'] ?? 0) + 1;
		$cooldownUntil = (int) ($current['cooldown_until'] ?? 0);
		$threshold = max(2, (int) EPV2_Settings::get('source_cooldown_failures', 3));
		if ($failures >= $threshold) {
			$cooldownUntil = time() + (max(10, (int) EPV2_Settings::get('source_cooldown_minutes', 60)) * MINUTE_IN_SECONDS);
		}
		$health[$source_id] = [
			'consecutive_failures' => $failures,
			'cooldown_until' => $cooldownUntil,
			'last_error' => self::humanize_error($message),
			'last_failure_at' => time(),
		];
		update_option(self::OPTION_SOURCE_HEALTH, $health, false);
	}

	public static function schedule_retry(object $item, string $state, string $module, string $message): void {
		$notes = self::item_notes($item);
		$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$retries = is_array($system['retries'] ?? null) ? $system['retries'] : [];
		$attempt = (int) ($retries[$module] ?? 0) + 1;
		$maxAttempts = max(1, (int) EPV2_Settings::get('max_retry_attempts', 3));
		if ($module === 'process' && self::is_external_worker_infra_error($message)) {
			$maxAttempts = max($maxAttempts, 8);
		}
		$human = self::humanize_error($message);
		if ($attempt > $maxAttempts) {
			$system['retries'][$module] = $attempt;
			$system['last_error_human'] = $human;
			$notes['_system'] = $system;
			$terminal = self::terminal_state_for_exhausted_retry($module, $message, $human);
			EPV2_Queue::mark_state((int) $item->id, $terminal['state'], [
				'error_message' => $terminal['message'],
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			]);
			return;
		}

		$delay = self::retry_delay_seconds($attempt, $module, $message);
		$system['retries'][$module] = $attempt;
		$system['retry_after'] = gmdate('Y-m-d H:i:s', time() + $delay);
		$system['last_error_human'] = $human;
		$notes['_system'] = $system;
		EPV2_Queue::mark_state((int) $item->id, $state, [
			'error_message' => $human,
			'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
		]);
	}

	public static function humanize_error(string $message): string {
		$message = trim($message);
		if ($message === '') {
			return 'Неизвестная ошибка внешнего сервиса.';
		}
		$patterns = [
			'/\b429\b|quota|rate limit/i' => 'Квота AI исчерпана или достигнут лимит провайдера.',
			'/\b401\b|auth|authentication|api key/i' => 'Ошибка авторизации внешнего сервиса. Проверь API key.',
			'/timeout|timed out|cURL error 28/i' => 'Внешний сервис отвечает слишком долго. Попробуем позже.',
			'/empty content|returned empty/i' => 'AI вернул пустой ответ. Попробуем позже.',
			'/provider unavailable|cooldown/i' => 'Провайдер временно отключён после серии ошибок.',
			'/image.*small|featured media|featured image/i' => 'Изображение не подошло по качеству или размеру.',
		];
		foreach ($patterns as $pattern => $human) {
			if (preg_match($pattern, $message)) {
				return $human;
			}
		}
		return wp_strip_all_tags(mb_substr($message, 0, 220));
	}

	public static function retry_due($item): bool {
		if (! is_object($item)) {
			return false;
		}
		$notes = self::item_notes($item);
		$retryAfter = (string) (($notes['_system']['retry_after'] ?? ''));
		if ($retryAfter === '') {
			return true;
		}
		return strtotime($retryAfter) <= time();
	}

	private static function retry_delay_seconds(int $attempt, string $module = '', string $message = ''): int {
		$module = sanitize_key($module);
		$message = wp_strip_all_tags((string) $message);
		if ($module === 'process' && self::is_external_worker_infra_error($message)) {
			return match (true) {
				$attempt <= 1 => MINUTE_IN_SECONDS,
				$attempt === 2 => 2 * MINUTE_IN_SECONDS,
				$attempt === 3 => 5 * MINUTE_IN_SECONDS,
				default => 10 * MINUTE_IN_SECONDS,
			};
		}
		if ($module === 'process' && preg_match('/stale processing job recovered|missing process lock|stalled process state/i', $message) === 1) {
			return match (true) {
				$attempt <= 1 => MINUTE_IN_SECONDS,
				$attempt === 2 => 2 * MINUTE_IN_SECONDS,
				$attempt === 3 => 5 * MINUTE_IN_SECONDS,
				default => 10 * MINUTE_IN_SECONDS,
			};
		}
		if ($module === 'publish_media') {
			return match (true) {
				$attempt <= 1 => 2 * MINUTE_IN_SECONDS,
				$attempt === 2 => 5 * MINUTE_IN_SECONDS,
				$attempt === 3 => 10 * MINUTE_IN_SECONDS,
				default => 20 * MINUTE_IN_SECONDS,
			};
		}
		if (
			$module === 'process'
			&& preg_match('/publish threshold|minimum review threshold|heuristic payload|broken multilingual/i', $message)
		) {
			return match (true) {
				$attempt <= 1 => 2 * MINUTE_IN_SECONDS,
				$attempt === 2 => 5 * MINUTE_IN_SECONDS,
				$attempt === 3 => 10 * MINUTE_IN_SECONDS,
				default => 30 * MINUTE_IN_SECONDS,
			};
		}
		return match (true) {
			$attempt <= 1 => 10 * MINUTE_IN_SECONDS,
			$attempt === 2 => 30 * MINUTE_IN_SECONDS,
			$attempt === 3 => 2 * HOUR_IN_SECONDS,
			default => 6 * HOUR_IN_SECONDS,
		};
	}

	private static function terminal_state_for_exhausted_retry(string $module, string $message, string $human): array {
		$module = sanitize_key($module);
		$message = wp_strip_all_tags((string) $message);
		if (
			$module === 'process'
			&& preg_match('/publish threshold|minimum review threshold|heuristic payload|broken multilingual/i', $message)
		) {
			return [
				'state' => 'ready_review',
				'message' => 'Материал не достиг полного publish-grade автоматически и переведён в ручную редакционную доработку, а не удалён.',
			];
		}
		if (
			$module === 'publish_media'
			|| preg_match('/featured image|featured media/i', $message)
		) {
			return [
				'state' => 'ready_review',
				'message' => 'Материал снят с автопубликации: после нескольких попыток не удалось подготовить корректное featured image.',
			];
		}
		if (preg_match('/устарел|устарела|lost relevance/i', $message)) {
			return [
				'state' => 'rejected',
				'message' => $human,
			];
		}
		return [
			'state' => 'error',
			'message' => $human,
		];
	}

	private static function item_notes(object $item): array {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		return is_array($notes) ? $notes : [];
	}

	private static function reclaim_stuck_items(): void {
		$jobLockTtl = max(300, (int) EPV2_Settings::get('job_lock_ttl_seconds', 900));
		$maxAge = max(240, min(480, (int) floor($jobLockTtl / 2) + 60));
		$missingLockProcessAge = max(20, min(45, (int) floor($jobLockTtl / 20)));
		$stuckProcess = EPV2_Queue::get_items(['states' => ['processing_de'], 'limit' => 50]);
		$stuckPublish = EPV2_Queue::get_items(['states' => ['publishing'], 'limit' => 50]);

		foreach ($stuckProcess as $item) {
			if (! self::process_item_should_be_recovered($item)) {
				continue;
			}
			if (preg_match('/publish threshold|minimum review threshold|heuristic payload|broken multilingual/i', (string) ($item->error_message ?? ''))) {
				self::schedule_retry($item, 'retry_process', 'process', (string) ($item->error_message ?? 'stale processing job recovered after threshold failure'));
				continue;
			}
			self::schedule_retry($item, 'retry_process', 'process', (string) ($item->error_message ?: 'stale processing job recovered after stalled process state'));
		}

		if (! EPV2_Lock_Manager::is_active('process')) {
			$recoveredMissingProcessLock = false;
			foreach ($stuckProcess as $item) {
				if (! self::row_is_stale($item, $missingLockProcessAge)) {
					continue;
				}
				self::schedule_retry($item, 'retry_process', 'process', 'stale processing job recovered after missing process lock');
				$recoveredMissingProcessLock = true;
			}
			if ($recoveredMissingProcessLock) {
				self::finish_latest_started_run('process', 'missing_process_lock_recovered');
			}
		}

		if (! EPV2_Lock_Manager::is_active('publish')) {
			$recoveredMissingPublishLock = false;
			foreach ($stuckPublish as $item) {
				if (! self::row_is_stale($item, $maxAge)) {
					continue;
				}
				self::schedule_retry($item, 'retry_publish', 'publish', 'stale publish job recovered after missing publish lock');
				$recoveredMissingPublishLock = true;
			}
			if ($recoveredMissingPublishLock) {
				self::finish_latest_started_run('publish', 'missing_publish_lock_recovered');
			}
		}
	}

	private static function normalize_terminal_review_candidates(): void {
		$maxAttempts = max(1, (int) EPV2_Settings::get('max_retry_attempts', 3));
		$rows = EPV2_Queue::get_items([
			'states' => ['retry_process', 'error'],
			'limit' => 50,
		]);
		foreach ($rows as $item) {
			$notes = self::item_notes($item);
			$attempt = (int) ($notes['_system']['retries']['process'] ?? 0);
			if ($attempt <= $maxAttempts) {
				continue;
			}
			$message = (string) ($item->error_message ?? '');
			if (preg_match('/publish threshold|minimum review threshold|heuristic payload|broken multilingual|featured image|featured media/i', $message) !== 1) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			if (! is_array($payload)) {
				$payload = [];
			}
			$extra = [
				'error_message' => self::humanize_terminal_review_message($message),
			];
			if ($payload !== []) {
				$extra['ai_payload'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
			}
			EPV2_Queue::mark_state((int) $item->id, 'ready_review', $extra);
		}
		$review_rows = EPV2_Queue::get_items([
			'states' => ['ready_review'],
			'limit' => 50,
		]);
		foreach ($review_rows as $item) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$hasPayload = is_array($payload) && $payload !== [];
			if ($hasPayload) {
				continue;
			}
			$score = (int) ($item->story_score ?? 0);
			$created = strtotime((string) ($item->created_at ?? '')) ?: 0;
			$ageSeconds = $created > 0 ? time() - $created : PHP_INT_MAX;
			if ($score < 60 || $ageSeconds > (6 * HOUR_IN_SECONDS)) {
				continue;
			}
			$notes = self::item_notes($item);
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$notes['_system']['retries'] = is_array($notes['_system']['retries'] ?? null) ? $notes['_system']['retries'] : [];
			$notes['_system']['retries']['process'] = 0;
			unset($notes['_system']['retry_after']);
			EPV2_Queue::mark_state((int) $item->id, 'retry_process', [
				'error_message' => 'Материал возвращён в автоматическую доработку после восстановления контура промежуточного payload.',
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			]);
		}
	}

	private static function normalize_retry_metadata(): void {
		$rows = EPV2_Queue::get_items([
			'states' => ['retry_process', 'ready_review', 'error'],
			'limit' => 50,
		]);
		foreach ($rows as $item) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? EPV2_AI_Processor::normalize_existing_payload($payload) : [];
			$notes = self::item_notes($item);
			$original_notes = $notes;
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$notes['_system']['retries'] = is_array($notes['_system']['retries'] ?? null) ? $notes['_system']['retries'] : [];

			if ($payload !== []) {
				$context_memory = is_array($payload['_meta']['context_memory'] ?? null) ? $payload['_meta']['context_memory'] : [];
				if ($context_memory !== []) {
					$notes['_system']['context_memory'] = $context_memory;
				}

				$review_rebuild = (int) ($notes['_system']['retries']['review_rebuild'] ?? 0);
				$review_finish = (int) ($notes['_system']['retries']['review_finish'] ?? 0);
				if ($review_finish === 0 && $review_rebuild > 0 && EPV2_AI_Processor::item_is_auto_finish_candidate($item, $payload)) {
					$notes['_system']['retries']['review_finish'] = $review_rebuild;
					$notes['_system']['review_finish_signature'] = EPV2_AI_Processor::auto_finish_signature($payload);
				}
				if ($review_rebuild === 0 && $review_finish > 0 && EPV2_AI_Processor::item_is_auto_rework_candidate($item, $payload)) {
					$notes['_system']['retries']['review_rebuild'] = $review_finish;
					$notes['_system']['review_rebuild_signature'] = EPV2_AI_Processor::auto_rework_signature($payload);
				}
				if ($review_finish > 0 && empty($notes['_system']['review_finish_signature'])) {
					$notes['_system']['review_finish_signature'] = EPV2_AI_Processor::auto_finish_signature($payload);
				}
				if ($review_rebuild > 0 && empty($notes['_system']['review_rebuild_signature'])) {
					$notes['_system']['review_rebuild_signature'] = EPV2_AI_Processor::auto_rework_signature($payload);
				}

				if ((string) ($item->state ?? '') === 'ready_review' && ! EPV2_AI_Processor::payload_is_publish_ready($payload)) {
					$updated = strtotime((string) ($item->updated_at ?? '')) ?: time();
					$target = gmdate('Y-m-d H:i:s', $updated + (15 * MINUTE_IN_SECONDS));
					$current = (string) ($notes['_system']['review_revisit_after'] ?? '');
					if ($current === '' || strtotime($current) < $updated) {
						$notes['_system']['review_revisit_after'] = $target;
					}
				}
			}

			$fields = [];
			if ($payload !== []) {
				$fields['ai_payload'] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
			}
			if ($notes !== $original_notes) {
				$fields['admin_notes'] = wp_json_encode($notes, JSON_UNESCAPED_UNICODE);
			}
			if ($fields !== []) {
				EPV2_Queue::update_fields((int) $item->id, $fields);
			}
		}
	}

	private static function normalize_targeted_retry_windows(): void {
		$rows = EPV2_Queue::get_items([
			'states' => ['retry_process', 'retry_publish'],
			'limit' => 50,
		]);
		foreach ($rows as $item) {
			$notes = self::item_notes($item);
			$system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$retries = is_array($system['retries'] ?? null) ? $system['retries'] : [];
			$message = (string) ($item->error_message ?? '');

			if ((int) ($retries['publish_media'] ?? 0) > 0 && preg_match('/featured image|featured media|изображение не подошло/iu', $message) === 1) {
				$attempt = max(1, (int) ($retries['publish_media'] ?? 1));
				$target = gmdate('Y-m-d H:i:s', time() + self::retry_delay_seconds($attempt, 'publish_media', $message));
				$current = (string) ($system['retry_after'] ?? '');
				if ($current === '' || strtotime($current) > strtotime($target)) {
					$system['retry_after'] = $target;
					$notes['_system'] = $system;
					EPV2_Queue::update_fields((int) $item->id, [
						'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
					]);
				}
			}
		}
	}

	private static function hydrate_review_payloads(): void {
		$rows = EPV2_Queue::get_items([
			'states' => ['ready_review'],
			'limit' => 20,
		]);
		foreach ($rows as $item) {
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			if (is_array($payload) && $payload !== []) {
				continue;
			}
			$categories = EPV2_Review::normalize_categories((string) ($item->category_proposed ?: $item->category_final ?: 'deutschland'));
			$payload = EPV2_Review::ensure_payload_without_ai($item, $categories, (string) EPV2_Settings::get('rewrite_style', 'lively'));
			if (! is_array($payload) || $payload === []) {
				continue;
			}
			EPV2_Queue::mark_state((int) $item->id, 'ready_review', [
				'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
				'error_message' => (string) ($item->error_message ?: 'Материал переведён в редакционную доработку с подготовленным базовым пакетом.'),
			]);
		}
	}

	private static function revive_auto_review_candidates(): void {
		$rows = EPV2_Queue::get_items([
			'states' => ['ready_review'],
			'limit' => 25,
		]);
		$processQueued = false;
		$publishQueued = false;
		foreach ($rows as $item) {
			$message = (string) ($item->error_message ?? '');
			if (preg_match('/перевед[её]н в ручн|manual review|manual check|редакционную доработку/u', $message) === 1) {
				continue;
			}
			if (EPV2_AI_Processor::item_has_expired_live_angle($item)) {
				EPV2_Queue::mark_state((int) $item->id, 'rejected', [
					'error_message' => 'Материал потерял актуальность по времени и live-углу, поэтому снят из автоматической очереди.',
				]);
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
			if ($payload !== [] && EPV2_AI_Processor::payload_is_publish_ready($payload)) {
				EPV2_Queue::mark_state((int) $item->id, 'ready_publish', [
					'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
					'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))),
					'error_message' => '',
				]);
				$publishQueued = true;
				continue;
			}
			$autoRework = EPV2_AI_Processor::item_is_auto_rework_candidate($item, $payload);
			$autoFinish = ! $autoRework && EPV2_AI_Processor::item_is_auto_finish_candidate($item, $payload);
			if (! $autoRework && ! $autoFinish) {
				continue;
			}
			$notes = self::item_notes($item);
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$notes['_system']['retries'] = is_array($notes['_system']['retries'] ?? null) ? $notes['_system']['retries'] : [];
			$notes['_system']['retries']['process'] = 0;
			$reviewAttempt = max(
				(int) ($notes['_system']['retries']['review_rebuild'] ?? 0),
				(int) ($notes['_system']['retries']['review_finish'] ?? 0),
				1
			);
			$notes['_system']['retry_after'] = gmdate(
				'Y-m-d H:i:s',
				time() + self::review_retry_delay_seconds($reviewAttempt, $autoRework ? 'rebuild' : 'finish')
			);
			$notes['_system']['live_status'] = $autoRework
				? 'Возвращаю материал в автоматическую углублённую доработку и добираю фактуру, источники и фото.'
				: 'Возвращаю материал в автоматическую доводку SEO, media и финального publish-grade.';
			$notes['_system']['live_status_code'] = $autoRework ? 'enrichment_rebuild' : 'publish_finish';
			EPV2_Queue::mark_state((int) $item->id, 'retry_process', [
				'ai_payload' => $payload !== [] ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE) : '',
				'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($item->category_final ?? ''),
				'error_message' => $autoRework
					? 'Материал возвращён в автоматическую углублённую доработку после промежуточной редакционной остановки.'
					: 'Материал возвращён в автоматическую финальную доводку после промежуточной редакционной остановки.',
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			]);
			$processQueued = true;
		}
		if ($publishQueued && ! EPV2_Lock_Manager::is_active('publish')) {
			EPV2_Jobs::enqueue_publish();
		}
		if ($processQueued && ! EPV2_Lock_Manager::is_active('process')) {
			EPV2_Jobs::enqueue_process();
		}
	}

	private static function review_retry_delay_seconds(int $attempt, string $mode): int {
		$attempt = max(1, $attempt);
		$mode = sanitize_key($mode);
		if ($mode === 'finish') {
			return match (true) {
				$attempt <= 1 => 2 * MINUTE_IN_SECONDS,
				$attempt === 2 => 5 * MINUTE_IN_SECONDS,
				$attempt === 3 => 10 * MINUTE_IN_SECONDS,
				default => 20 * MINUTE_IN_SECONDS,
			};
		}
		return match (true) {
			$attempt <= 1 => 2 * MINUTE_IN_SECONDS,
			$attempt === 2 => 5 * MINUTE_IN_SECONDS,
			$attempt === 3 => 10 * MINUTE_IN_SECONDS,
			default => 30 * MINUTE_IN_SECONDS,
		};
	}

	private static function humanize_terminal_review_message(string $message): string {
		if (preg_match('/featured image|featured media/i', $message) === 1) {
			return 'Материал снят с автопубликации до подбора корректного featured image.';
		}
		return 'Материал не дотянул до полного publish-grade автоматически и переведён в ручную редакционную доработку.';
	}

	private static function row_is_stale(object $item, int $maxAge): bool {
		$updated = strtotime((string) ($item->updated_at ?? '')) ?: 0;
		if ($updated <= 0) {
			return true;
		}
		return (time() - $updated) >= $maxAge;
	}

	private static function is_external_worker_infra_error(string $message): bool {
		return preg_match('/External worker failed|Could not open input file|worker timed out|Failed to start external worker process|invalid JSON/i', $message) === 1;
	}

	private static function process_item_should_be_recovered(object $item): bool {
		$error = trim((string) ($item->error_message ?? ''));
		if ($error === '') {
			return false;
		}
		$updated = strtotime((string) ($item->updated_at ?? '')) ?: 0;
		if ($updated <= 0) {
			return true;
		}
		return (time() - $updated) >= 20;
	}

	private static function normalize_stale_error_items(): void {
		$rows = EPV2_Queue::get_items([
			'states' => ['error'],
			'limit' => 50,
		]);
		foreach ($rows as $item) {
			$created = strtotime((string) ($item->created_at ?? '')) ?: 0;
			$updated = strtotime((string) ($item->updated_at ?? '')) ?: 0;
			$stale = ($updated > 0 ? (time() - $updated) : PHP_INT_MAX) > (90 * MINUTE_IN_SECONDS)
				|| ($created > 0 ? (time() - $created) : PHP_INT_MAX) > (6 * HOUR_IN_SECONDS);
			if (! $stale) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$hasPayload = is_array($payload) && $payload !== [];
			$score = (int) ($item->story_score ?? 0);
			$message = (string) ($item->error_message ?? '');
			$reworkable = preg_match('/publish threshold|minimum review threshold|heuristic payload|broken multilingual|featured image|featured media/i', $message) === 1;
			$reviewablePayload = $hasPayload && (
				EPV2_AI_Processor::payload_is_review_ready($payload)
				|| EPV2_AI_Processor::payload_has_publish_substance($payload)
			);
			if ($hasPayload && ($reviewablePayload || $score >= 60 || $reworkable)) {
				EPV2_Queue::mark_state((int) $item->id, 'ready_review', [
					'error_message' => 'Материал снят с автоматического цикла после повторяющихся ошибок внешнего сервиса и ждёт ручной проверки.',
				]);
				continue;
			}
			EPV2_Queue::mark_state((int) $item->id, 'rejected', [
				'error_message' => 'Материал снят из автоматической очереди после повторяющихся устаревших ошибок внешнего сервиса.',
			]);
		}
	}

	private static function finish_latest_started_run(string $jobName, string $result): void {
		$latest = EPV2_Runs::latest($jobName);
		if (! $latest || (string) ($latest->status ?? '') !== 'started') {
			return;
		}
		EPV2_Runs::finish((int) $latest->id, 'finished_with_errors', 0, 1, [
			'result' => $result,
		]);
	}

	private static function recover_worker_infra_errors(): void {
		if (! class_exists('EPV2_Worker_Client') || ! EPV2_Worker_Client::enabled()) {
			return;
		}
		$rows = EPV2_Queue::get_items([
			'states' => ['error'],
			'limit' => 25,
		]);
		foreach ($rows as $item) {
			$message = (string) ($item->error_message ?? '');
			if (! self::is_external_worker_infra_error($message)) {
				continue;
			}
			if (EPV2_AI_Processor::item_has_expired_live_angle($item)) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
			if ($payload === []) {
				continue;
			}
			$notes = self::item_notes($item);
			$notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			$notes['_system']['retries'] = is_array($notes['_system']['retries'] ?? null) ? $notes['_system']['retries'] : [];
			$notes['_system']['retries']['process'] = 0;
			unset($notes['_system']['retry_after']);
			EPV2_Queue::mark_state((int) $item->id, 'retry_process', [
				'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				'category_final' => implode(',', array_values(array_filter((array) ($payload['categories'] ?? [])))) ?: (string) ($item->category_final ?? ''),
				'error_message' => 'Материал возвращён в автоматическую очередь после восстановления внешнего worker-контура.',
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			]);
		}
	}

	private static function restore_rejected_reviewable_items(): void {
		$rows = EPV2_Queue::get_items([
			'states' => ['rejected'],
			'limit' => 50,
		]);
		foreach ($rows as $item) {
			$updated = strtotime((string) ($item->updated_at ?? '')) ?: 0;
			if ($updated > 0 && (time() - $updated) > (48 * HOUR_IN_SECONDS)) {
				continue;
			}
			$message = (string) ($item->error_message ?? '');
			if (preg_match('/внешнего сервиса|автоматической очереди|автопубликации|featured image|featured media|publish-grade/i', $message) !== 1) {
				continue;
			}
			$payload = json_decode((string) ($item->ai_payload ?? ''), true);
			if (! is_array($payload) || $payload === []) {
				continue;
			}
			if (! EPV2_AI_Processor::payload_is_review_ready($payload)) {
				continue;
			}
			EPV2_Queue::mark_state((int) $item->id, 'ready_review', [
				'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				'error_message' => 'Материал восстановлен после ошибочного terminal-state и возвращён в редакционную проверку.',
			]);
		}
	}
}
