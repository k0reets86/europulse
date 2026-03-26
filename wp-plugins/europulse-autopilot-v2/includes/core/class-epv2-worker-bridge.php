<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Worker_Bridge {
	public static function execute_request(array $request): array {
		$started_at = microtime(true);
		$queue_id = (int) ($request['queue_id'] ?? 0);
		$stage = sanitize_key((string) ($request['stage'] ?? 'rebuild_bundle'));
		if ($stage === 'full_bundle') {
			$stage = 'rebuild_bundle';
		}
		$existing_payload = is_array($request['existing_payload'] ?? null) ? $request['existing_payload'] : [];
		$existing_payload = EPV2_AI_Processor::normalize_existing_payload($existing_payload, false);
		self::log_step('start', $queue_id, $stage, ['has_existing_payload' => $existing_payload !== [] ? 1 : 0]);

		$item = EPV2_Queue::get_item($queue_id);
		self::log_step('after_get_item', $queue_id, $stage, ['item_found' => $item ? 1 : 0]);
		if (! $item) {
			throw new RuntimeException('Worker queue item not found');
		}

		$category_seed = implode(',', array_values(array_filter((array) ($existing_payload['categories'] ?? []))));
		self::log_step('before_category_seed', $queue_id, $stage, ['category_seed' => $category_seed]);
		if ($category_seed === '') {
			$detected = EPV2_Categorizer::detect(
				(string) ($item->original_title ?? ''),
				(string) ($item->original_content ?? ''),
				(string) ($item->category_final ?: $item->category_proposed ?: 'deutschland')
			);
			$category_seed = $detected !== '' ? $detected : (string) ($item->category_final ?: $item->category_proposed ?: 'deutschland');
		}
		self::log_step('after_category_seed', $queue_id, $stage, ['category_seed' => $category_seed]);
		$categories = EPV2_Review::normalize_categories($category_seed);
		$style = (string) ($existing_payload['_meta']['style'] ?? EPV2_Settings::get('rewrite_style', 'lively'));
		self::log_step('after_categories_style', $queue_id, $stage, ['category_count' => count($categories), 'style' => $style]);

		$payload = [];
		$stage_result = $stage;
		switch ($stage) {
			case 'publish_finish':
				if ($existing_payload === []) {
					throw new RuntimeException('Worker publish_finish requires existing payload');
				}
				$lift_started_at = microtime(true);
				$payload = EPV2_AI_Processor::run_publish_finish_stage($item, $existing_payload, $categories, $style);
				$de_only = ! self::payload_has_ready_translations($payload);
				self::log_step('publish_finish_lift', $queue_id, $stage, ['duration_ms' => self::duration_ms($lift_started_at), 'de_only' => $de_only ? 1 : 0]);
				$stage_result = $de_only ? 'worker_de_publish_finish' : 'worker_publish_finish';
				break;

			case 'translate_uk':
			case 'translate_en':
				if ($existing_payload === []) {
					throw new RuntimeException('Worker ' . $stage . ' requires existing payload');
				}
				$lang = $stage === 'translate_uk' ? 'uk' : 'en';
				$translate_started_at = microtime(true);
				$payload = EPV2_AI_Processor::repair_payload_language($existing_payload, $lang);
				self::log_step('translate_language', $queue_id, $stage, ['duration_ms' => self::duration_ms($translate_started_at), 'lang' => $lang]);
				$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
				$payload['_meta']['translations_deferred'] = ! self::payload_has_ready_translations($payload);
				unset($payload['_meta']['pipeline_stage']);
				$normalize_started_at = microtime(true);
				$payload = EPV2_AI_Processor::refresh_payload_stage_markers($payload);
				self::log_step('translate_language_normalize', $queue_id, $stage, ['duration_ms' => self::duration_ms($normalize_started_at), 'lang' => $lang]);
				if (! self::payload_has_ready_language($payload, $lang)) {
					throw new RuntimeException('Worker translation stage completed without a valid ' . $lang . ' package');
				}
				$stage_result = 'worker_translate_' . $lang;
				break;

			case 'translate_finish':
				if ($existing_payload === []) {
					throw new RuntimeException('Worker translate_finish requires existing payload');
				}
				$lang = self::payload_has_ready_language($existing_payload, 'uk') ? 'en' : 'uk';
				$translate_started_at = microtime(true);
				$payload = EPV2_AI_Processor::repair_payload_language($existing_payload, $lang);
				self::log_step('translate_finish_language', $queue_id, $stage, ['duration_ms' => self::duration_ms($translate_started_at), 'lang' => $lang]);
				$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
				$payload['_meta']['translations_deferred'] = ! self::payload_has_ready_translations($payload);
				unset($payload['_meta']['pipeline_stage']);
				$normalize_started_at = microtime(true);
				$payload = EPV2_AI_Processor::refresh_payload_stage_markers($payload);
				self::log_step('translate_finish_normalize', $queue_id, $stage, ['duration_ms' => self::duration_ms($normalize_started_at), 'lang' => $lang]);
				if (! self::payload_has_ready_language($payload, $lang)) {
					throw new RuntimeException('Worker translate_finish completed without a valid ' . $lang . ' package');
				}
				$stage_result = 'worker_translate_' . $lang;
				break;

			case 'rebuild_bundle':
				$context_memory = [];
				if ($existing_payload !== []) {
					$meta = is_array($existing_payload['_meta'] ?? null) ? $existing_payload['_meta'] : [];
					$context_memory = is_array($meta['context_memory'] ?? null) ? $meta['context_memory'] : [];
					$de = is_array($existing_payload['languages']['de'] ?? null) ? $existing_payload['languages']['de'] : [];
					if (! empty($de['title'])) {
						$context_memory['event_title'] = sanitize_text_field((string) $de['title']);
						$context_memory['search_terms'] = array_values(array_unique(array_filter(array_merge(
							[(string) $de['title'], (string) ($de['excerpt'] ?? '')],
							(array) ($context_memory['search_terms'] ?? [])
						))));
					}
				}
				$generate_started_at = microtime(true);
				$payload = EPV2_AI_Processor::generate_review_payload(
					$item,
					$categories,
					$style,
					true,
					[
						'force_supporting' => true,
						'target_supporting' => 2,
						'max_runtime_seconds' => 5,
						'context_memory' => $context_memory,
					]
				);
				self::log_step('rebuild_generate_review_payload', $queue_id, $stage, ['duration_ms' => self::duration_ms($generate_started_at)]);
				if (self::payload_is_context_reject($payload)) {
					$stage_result = 'worker_context_reject';
					break;
				}
				$normalize_started_at = microtime(true);
				$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
				self::log_step('rebuild_normalize', $queue_id, $stage, ['duration_ms' => self::duration_ms($normalize_started_at)]);
				$lift_started_at = microtime(true);
				$payload = EPV2_AI_Processor::try_lift_payload_to_publish_grade($item, $payload, true);
				self::log_step('rebuild_lift', $queue_id, $stage, ['duration_ms' => self::duration_ms($lift_started_at)]);
				$stage_result = ! empty($payload['_meta']['translations_deferred']) ? 'worker_de_master_ready' : 'worker_rebuild_bundle';
				break;

			default:
				$generate_started_at = microtime(true);
				$payload = EPV2_AI_Processor::generate_review_payload($item, $categories, $style, true);
				self::log_step('full_generate_review_payload', $queue_id, $stage, ['duration_ms' => self::duration_ms($generate_started_at)]);
				if (self::payload_is_context_reject($payload)) {
					$stage_result = 'worker_context_reject';
					break;
				}
				$normalize_started_at = microtime(true);
				$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
				self::log_step('full_normalize', $queue_id, $stage, ['duration_ms' => self::duration_ms($normalize_started_at)]);
				$lift_started_at = microtime(true);
				$payload = EPV2_AI_Processor::try_lift_payload_to_publish_grade($item, $payload, true);
				self::log_step('full_lift', $queue_id, $stage, ['duration_ms' => self::duration_ms($lift_started_at)]);
				$stage_result = ! empty($payload['_meta']['translations_deferred']) ? 'worker_de_master_ready' : 'worker_rebuild_bundle';
				break;
		}

		$requires_publish_gate = $stage === 'publish_finish';
		if (! self::payload_is_context_reject($payload)) {
			$final_normalize_started_at = microtime(true);
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload, $requires_publish_gate);
			self::log_step('final_normalize', $queue_id, $stage, [
				'duration_ms' => self::duration_ms($final_normalize_started_at),
				'publish_gate' => $requires_publish_gate ? 1 : 0,
			]);
		}
		if (self::payload_is_context_reject($payload)) {
			$outcome = 'rejected';
		} elseif ($requires_publish_gate && EPV2_AI_Processor::payload_is_publish_ready($payload)) {
			$outcome = 'ready_publish';
		} else {
			$outcome = 'retry_process';
		}
		self::log_step('finish', $queue_id, $stage, [
			'duration_ms' => self::duration_ms($started_at),
			'outcome' => $outcome,
			'stage_result' => $stage_result,
		]);

		return [
			'queue_id' => $queue_id,
			'stage' => $stage,
			'stage_result' => $stage_result,
			'outcome' => $outcome,
			'payload' => $payload,
		];
	}

	private static function duration_ms(float $started_at): int {
		return (int) round((microtime(true) - $started_at) * 1000);
	}

	private static function log_step(string $step, int $queue_id, string $stage, array $context = []): void {
		if (! class_exists('EPV2_Logger')) {
			return;
		}
		$context['queue_id'] = $queue_id;
		$context['stage'] = $stage;
		$context['step'] = $step;
		EPV2_Logger::info('worker_bridge', 'Worker bridge step', $context);
	}

	private static function payload_is_context_reject(array $payload): bool {
		return EPV2_AI_Processor::payload_requires_terminal_context_reject($payload);
	}

	private static function payload_has_ready_translations(array $payload): bool {
		return self::payload_has_ready_language($payload, 'uk')
			&& self::payload_has_ready_language($payload, 'en');
	}

	private static function payload_has_ready_language(array $payload, string $lang): bool {
		$lang = in_array($lang, ['uk', 'en'], true) ? $lang : '';
		if ($lang === '') {
			return false;
		}
		$entry = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
		if ($entry === []) {
			return false;
		}
		return trim((string) ($entry['title'] ?? '')) !== ''
			&& trim((string) ($entry['excerpt'] ?? '')) !== ''
			&& trim(wp_strip_all_tags((string) ($entry['content'] ?? ''))) !== '';
	}
}
