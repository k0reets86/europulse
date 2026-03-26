<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV3_Runner {
	public static function run_process_cycle(): bool {
		$item = EPV3_Queue_Repository::next_processable_item();
		if (! $item) {
			return false;
		}

		$run_id = EPV3_Runs_Repository::start('process', (int) $item->id, (string) $item->stage);
		EPV3_Queue_Repository::mark_processing((int) $item->id);
		try {
			self::handle_stage($item);
			EPV3_Runs_Repository::finish($run_id, 'finished', 'stage processed');
		} catch (Throwable $e) {
			EPV3_Queue_Repository::mark_retry((int) $item->id, $e->getMessage());
			EPV3_Runs_Repository::finish($run_id, 'failed', $e->getMessage());
		}

		return true;
	}

	public static function run_publish_cycle(): bool {
		$item = EPV3_Queue_Repository::next_publishable_item();
		if (! $item) {
			return false;
		}

		$run_id = EPV3_Runs_Repository::start('publish', (int) $item->id, (string) $item->stage);
		try {
			$post_id = EPV3_Publisher::publish($item);
			EPV3_Queue_Repository::update((int) $item->id, [
				'state' => 'published',
				'stage' => EPV3_Stage_Machine::STAGE_PUBLISHED,
				'publish_not_before' => null,
				'post_id' => $post_id,
			]);
			EPV3_Runs_Repository::finish($run_id, 'finished', 'published', ['post_id' => $post_id]);
		} catch (Throwable $e) {
			EPV3_Queue_Repository::mark_retry((int) $item->id, $e->getMessage(), 600);
			EPV3_Runs_Repository::finish($run_id, 'failed', $e->getMessage());
		}

		return true;
	}

	private static function handle_stage(object $item): void {
		switch ((string) $item->stage) {
			case EPV3_Stage_Machine::STAGE_INGESTED:
				$filter = EPV3_Intake_Filter::analyze($item);
				if (($filter['decision'] ?? '') === 'reject') {
					throw new RuntimeException('Intake filter rejected item');
				}
				EPV3_Queue_Repository::advance_stage((int) $item->id, EPV3_Stage_Machine::STAGE_FILTERED, [
					'context_payload' => wp_json_encode([
						'intake' => $filter,
					], JSON_UNESCAPED_UNICODE),
					'priority' => (int) ($filter['score'] ?? 0),
				]);
				return;

			case EPV3_Stage_Machine::STAGE_FILTERED:
				$context = EPV3_Context_Analyzer::analyze($item);
				$existing = self::decode_payload((string) $item->context_payload);
				if ($existing !== []) {
					$context['intake'] = $existing['intake'] ?? [];
				}
				if (($context['decision'] ?? '') === 'reject') {
					throw new RuntimeException('Context analysis rejected item');
				}
				EPV3_Queue_Repository::advance_stage((int) $item->id, EPV3_Stage_Machine::STAGE_CONTEXT, [
					'context_payload' => wp_json_encode($context, JSON_UNESCAPED_UNICODE),
					'priority' => (int) ($context['priority'] ?? 0),
					'original_language' => sanitize_text_field((string) ($context['detected_language'] ?? $item->original_language ?? '')),
				]);
				return;

			case EPV3_Stage_Machine::STAGE_CONTEXT:
				$context = self::decode_payload((string) $item->context_payload);
				$dossier = EPV3_Dossier_Builder::build($item, $context);
				EPV3_Queue_Repository::advance_stage((int) $item->id, EPV3_Stage_Machine::STAGE_DOSSIER, [
					'dossier_payload' => wp_json_encode($dossier, JSON_UNESCAPED_UNICODE),
				]);
				return;

			case EPV3_Stage_Machine::STAGE_DOSSIER:
				$context = self::decode_payload((string) $item->context_payload);
				$dossier = self::decode_payload((string) $item->dossier_payload);
				$de = EPV3_DE_Master_Builder::build($item, $context, $dossier);
				EPV3_Queue_Repository::advance_stage((int) $item->id, EPV3_Stage_Machine::STAGE_DE, [
					'de_payload' => wp_json_encode($de, JSON_UNESCAPED_UNICODE),
				]);
				return;

			case EPV3_Stage_Machine::STAGE_DE:
				$context = self::decode_payload((string) $item->context_payload);
				$dossier = self::decode_payload((string) $item->dossier_payload);
				$media = EPV3_Media_Manager::resolve($item, $context, $dossier);
				EPV3_Queue_Repository::advance_stage((int) $item->id, EPV3_Stage_Machine::STAGE_MEDIA, [
					'publish_payload' => wp_json_encode($media, JSON_UNESCAPED_UNICODE),
				]);
				return;

			case EPV3_Stage_Machine::STAGE_MEDIA:
				$de = self::decode_payload((string) $item->de_payload);
				$uk = EPV3_Translation_Manager::to_uk($de);
				EPV3_Queue_Repository::advance_stage((int) $item->id, EPV3_Stage_Machine::STAGE_UK, [
					'uk_payload' => wp_json_encode($uk, JSON_UNESCAPED_UNICODE),
				]);
				return;

			case EPV3_Stage_Machine::STAGE_UK:
				$de = self::decode_payload((string) $item->de_payload);
				$en = EPV3_Translation_Manager::to_en($de);
				EPV3_Queue_Repository::advance_stage((int) $item->id, EPV3_Stage_Machine::STAGE_EN, [
					'en_payload' => wp_json_encode($en, JSON_UNESCAPED_UNICODE),
				]);
				return;

			case EPV3_Stage_Machine::STAGE_EN:
				$context = self::decode_payload((string) $item->context_payload);
				$de = self::decode_payload((string) $item->de_payload);
				$media = self::decode_payload((string) $item->publish_payload);
				$uk = self::decode_payload((string) $item->uk_payload);
				$en = self::decode_payload((string) $item->en_payload);
				$quality = EPV3_Quality_Validator::evaluate($de, $context, $media, $uk, $en);
				if (! empty($quality['publish_ready'])) {
					EPV3_Queue_Repository::mark_ready_publish((int) $item->id);
					EPV3_Queue_Repository::update((int) $item->id, [
						'publish_payload' => wp_json_encode(array_merge($media, ['quality' => $quality, 'seo' => $quality['seo']]), JSON_UNESCAPED_UNICODE),
					]);
					return;
				}
				throw new RuntimeException('Publish validation failed: ' . implode(', ', (array) ($quality['warnings'] ?? [])));
				return;
		}

		throw new RuntimeException('Unknown stage: ' . (string) $item->stage);
	}

	private static function decode_payload(string $json): array {
		$data = json_decode($json, true);
		return is_array($data) ? $data : [];
	}
}
