<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Publish_Gate {
	public static function evaluate(?object $item, array $payload = [], array $args = []): array {
		$context = sanitize_key((string) ($args['context'] ?? 'ready_publish'));
		$force = ! empty($args['force']);
		$blockers = [];

		if ($payload !== []) {
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload, false);
			if (method_exists('EPV2_AI_Processor', 'payload_with_item_source_context')) {
				$payload = EPV2_AI_Processor::payload_with_item_source_context($item, $payload);
			}
		}

		$decision = self::selection_decision($item, $payload);
		$manual_override = self::manual_override_present($item, $payload);
		$selection_publishable = ! in_array($decision, ['low', 'reject'], true) || $manual_override;
		if (! $selection_publishable) {
			$blockers[] = 'selection_' . ($decision !== '' ? $decision : 'blocked');
		}

		$context_publishable = true;
		if ($payload !== [] && EPV2_AI_Processor::payload_requires_terminal_context_reject($payload)) {
			$context_publishable = false;
			$blockers[] = 'context_reject';
		}
		if ($payload !== [] && self::payload_has_stale_context_signal($payload)) {
			$context_publishable = false;
			$blockers[] = 'stale_context';
		}

		$live_angle_publishable = true;
		if ($item && EPV2_AI_Processor::item_has_expired_live_angle($item)) {
			$live_angle_publishable = false;
			$blockers[] = 'expired_live_angle';
		}

		$stage_publishable = false;
		$quality_publishable = false;
		$media_publishable = false;
		$payload_publishable = false;
		if ($payload === []) {
			$blockers[] = 'missing_payload';
		} else {
			$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			if (! empty($meta['blockers'])) {
				$blockers[] = 'payload_blockers';
			}
			$checklist = is_array($meta['stage_checklist'] ?? null) ? $meta['stage_checklist'] : [];
			$stage_publishable =
				! empty($checklist['ready_publish'])
				&& ! empty($checklist['translations_ready'])
				&& ! empty($checklist['publish_finish_ready'])
				&& empty($meta['translations_deferred'])
				&& EPV2_AI_Processor::payload_languages_are_semantically_consistent($payload);
			if (! $stage_publishable) {
				$blockers[] = 'stage_contract';
			}

			$quality_publishable = EPV2_Content_Kinds::payload_meets_quality($payload);
			if (! $quality_publishable) {
				$blockers[] = 'quality_contract';
			}
			$enrichment_publishable = EPV2_Content_Kinds::payload_meets_enrichment($payload);
			if (! $enrichment_publishable) {
				$blockers[] = 'enrichment_required';
			}
			$length_publishable = EPV2_Content_Kinds::payload_meets_de_length($payload);
			if (! $length_publishable) {
				$blockers[] = 'length_below_kind_minimum';
			}
			$sources_publishable = EPV2_Content_Kinds::payload_meets_sources($payload);
			if (! $sources_publishable) {
				$blockers[] = 'sources_below_kind_minimum';
			}

			$media_publishable = self::payload_media_publishable($payload);
			if (! $media_publishable) {
				$blockers[] = 'media_contract';
			}

			$payload_publishable = EPV2_AI_Processor::payload_is_publish_ready($payload);
			if (! $payload_publishable) {
				$blockers[] = 'payload_contract';
			}
		}

		$schedule_publishable = true;
		if ($context === 'publish' && $item) {
			$schedule_publishable = $force || self::publish_due($item);
			if (! $schedule_publishable) {
				$blockers[] = 'publish_not_due';
			}
		}

		$already_published = $item && (int) ($item->post_id ?? 0) > 0 && $context === 'publish';
		if ($already_published) {
			$blockers[] = 'already_published';
		}

		$blockers = array_values(array_unique(array_filter($blockers)));
		$kind_publishable = ($enrichment_publishable ?? true)
			&& ($length_publishable ?? true)
			&& ($sources_publishable ?? true);
		$allowed = $blockers === []
			&& $selection_publishable
			&& $context_publishable
			&& $live_angle_publishable
			&& $stage_publishable
			&& $quality_publishable
			&& $media_publishable
			&& $payload_publishable
			&& $kind_publishable
			&& $schedule_publishable
			&& ! $already_published;

		return [
			'allowed' => $allowed,
			'blockers' => $blockers,
			'context' => $context,
			'selection_decision' => $decision,
			'content_kind' => $payload === [] ? '' : EPV2_Content_Kinds::detect_kind($payload),
			'selection_publishable' => $selection_publishable,
			'context_publishable' => $context_publishable,
			'live_angle_publishable' => $live_angle_publishable,
			'stage_publishable' => $stage_publishable,
			'quality_publishable' => $quality_publishable,
			'media_publishable' => $media_publishable,
			'payload_publishable' => $payload_publishable,
			'enrichment_publishable' => $enrichment_publishable ?? true,
			'length_publishable' => $length_publishable ?? true,
			'sources_publishable' => $sources_publishable ?? true,
			'schedule_publishable' => $schedule_publishable,
			'manual_override' => $manual_override,
		];
	}

	public static function allowed(?object $item, array $payload = [], array $args = []): bool {
		$gate = self::evaluate($item, $payload, $args);
		return ! empty($gate['allowed']);
	}

	public static function selection_decision(?object $item, array $payload = []): string {
		$decision = sanitize_key((string) ($payload['_meta']['selection']['decision'] ?? ''));
		if ($decision !== '') {
			return $decision;
		}
		if (! $item) {
			return '';
		}
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		return sanitize_key((string) ($notes['selection']['decision'] ?? ''));
	}

	private static function manual_override_present(?object $item, array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if (! empty($meta['manual_mode']) || ! empty($meta['breaking']) || ! empty($meta['top_story'])) {
			return true;
		}
		if (! $item) {
			return false;
		}
		return EPV2_Queue::item_has_publish_limit_override($item) || EPV2_Queue::item_has_priority_publish_override($item);
	}

	// Per-kind quality thresholds now live in EPV2_Content_Kinds::specs().

	private static function payload_media_publishable(array $payload): bool {
		if (class_exists('EPV2_AI_Processor') && method_exists('EPV2_AI_Processor', 'payload_media_contract_passes')) {
			return EPV2_AI_Processor::payload_media_contract_passes($payload);
		}
		$url = trim((string) ($payload['featured_media_url'] ?? $payload['media_url'] ?? ''));
		if ($url === '') {
			return false;
		}
		// Generated story covers are accepted as last-resort media (Fix E2);
		// the article is otherwise publish-grade and the cover is category-
		// aware. Operator can swap the image after publish via WP admin.
		if (preg_match('/<[^>]+>/', $url) === 1) {
			return false;
		}
		return (bool) wp_http_validate_url($url);
	}

	private static function payload_has_stale_context_signal(array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		$context = is_array($meta['context_analysis'] ?? null) ? $meta['context_analysis'] : [];
		if (sanitize_key((string) ($context['reject_class'] ?? '')) === 'stale') {
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
		return $timestamp !== false && $timestamp > 0 && (time() - $timestamp) > (48 * HOUR_IN_SECONDS);
	}

	private static function publish_due(object $item): bool {
		$notes = json_decode((string) ($item->admin_notes ?? ''), true);
		$notes = is_array($notes) ? $notes : [];
		$not_before = (int) ($notes['_system']['publish_not_before'] ?? 0);
		if ($not_before <= 0) {
			return false;
		}
		return $not_before <= time();
	}
}
