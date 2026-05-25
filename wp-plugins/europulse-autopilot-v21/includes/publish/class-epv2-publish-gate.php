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
		$thin_source_publishable = true;
		$source_context_publishable = true;
		if ($payload === []) {
			$blockers[] = 'missing_payload';
		} else {
			$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			$is_urgent_signal = ! empty($meta['breaking'])
				|| ! empty($meta['breaking_watch']);
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
			if (class_exists('EPV2_AI_Response_Validator') && method_exists('EPV2_AI_Response_Validator', 'source_dossier_thin_signal')) {
					if (! $is_urgent_signal && EPV2_AI_Response_Validator::source_dossier_thin_signal($payload)) {
						$thin_source_publishable = false;
						$blockers[] = 'thin_source_dossier';
					}
				}
			$source_profile = self::source_support_profile($item, $payload);
			if (self::payload_has_source_expansion_risk($source_profile)) {
				$source_context_publishable = false;
				$blockers[] = 'source_expansion_risk';
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
			&& $thin_source_publishable
			&& $source_context_publishable
			&& $kind_publishable
			&& $schedule_publishable
			&& ! $already_published;

		$quality_shadow = null;
		if (
			$payload !== []
			&& $item
			&& in_array($context, ['ready_publish', 'publish', 'worker_terminal_outcome'], true)
			&& self::payload_has_generated_language($payload)
			&& class_exists('EPV2_Quality_Gate')
		) {
			$quality_shadow = EPV2_Quality_Gate::record_shadow($item, $payload, [
				'context' => $context,
				'publish_gate_allowed_before_filter' => $allowed,
				'publish_gate_blockers' => $blockers,
				'selection_decision' => $decision,
			]);
		}

		// R16 2026-05-14: extension filter — third-party может override allowed
		// decision based на business rules (e.g. manual hold, embargo, A/B test).
		$allowed = (bool) apply_filters('epv2_publish_gate_decision', $allowed, $item, $payload, $context);

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
			'thin_source_publishable' => $thin_source_publishable,
			'source_context_publishable' => $source_context_publishable,
			'enrichment_publishable' => $enrichment_publishable ?? true,
			'length_publishable' => $length_publishable ?? true,
			'sources_publishable' => $sources_publishable ?? true,
			'schedule_publishable' => $schedule_publishable,
			'manual_override' => $manual_override,
			'quality_shadow' => $quality_shadow,
		];
	}

	public static function allowed(?object $item, array $payload = [], array $args = []): bool {
		$gate = self::evaluate($item, $payload, $args);
		return ! empty($gate['allowed']);
	}

	public static function selection_decision(?object $item, array $payload = []): string {
		if ($item) {
			$notes = json_decode((string) ($item->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			$decision = sanitize_key((string) ($notes['selection']['decision'] ?? ''));
			if ($decision !== '') {
				return $decision;
			}
		}

		return sanitize_key((string) ($payload['_meta']['selection']['decision'] ?? ''));
	}

	private static function manual_override_present(?object $item, array $payload): bool {
		$meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
		if (! empty($meta['manual_mode'])) {
			return true;
		}
		return false;
	}

	// Per-kind quality thresholds now live in EPV2_Content_Kinds::specs().

	private static function payload_has_generated_language(array $payload): bool {
		foreach (['de', 'uk', 'en'] as $lang) {
			$pkg = is_array($payload['languages'][$lang] ?? null) ? $payload['languages'][$lang] : [];
			$title = trim((string) ($pkg['title'] ?? ''));
			$content = trim(wp_strip_all_tags((string) ($pkg['content'] ?? $pkg['body_html'] ?? '')));
			if ($title !== '' || $content !== '') {
				return true;
			}
		}
		return false;
	}

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

	private static function source_support_profile(?object $item, array $payload): array {
		$dossier = is_array($payload['_meta']['source_dossier'] ?? null) ? $payload['_meta']['source_dossier'] : [];
		$primary = is_array($dossier['primary'] ?? null) ? $dossier['primary'] : [];
		$body_parts = [
			$primary['content'] ?? '',
			$primary['excerpt'] ?? '',
			$item->original_content ?? '',
			$item->original_excerpt ?? '',
		];
		$total_parts = array_merge(
			[
				$primary['title'] ?? '',
				$item->original_title ?? '',
			],
			$body_parts
		);

		$real_supporting = 0;
		$title_only_supporting = 0;
		foreach (['supporting', 'related'] as $bucket) {
			foreach ((array) ($dossier[$bucket] ?? []) as $entry) {
				if (! is_array($entry)) {
					continue;
				}
				$text_len = self::unique_plain_text_chars([
					$entry['content'] ?? '',
					$entry['excerpt'] ?? '',
				]);
				if ($text_len >= 100) {
					$real_supporting++;
				} elseif (self::plain_text((string) ($entry['title'] ?? $entry['url'] ?? '')) !== '') {
					$title_only_supporting++;
				}
			}
		}

		$de = is_array($payload['languages']['de'] ?? null) ? $payload['languages']['de'] : [];
		return [
			'primary_body_chars' => self::unique_plain_text_chars($body_parts),
			'primary_total_chars' => self::unique_plain_text_chars($total_parts),
			'real_supporting_count' => $real_supporting,
			'title_only_supporting_count' => $title_only_supporting,
			'de_content_chars' => self::unique_plain_text_chars([(string) ($de['content'] ?? $de['body_html'] ?? '')]),
		];
	}

	private static function payload_has_source_expansion_risk(array $profile): bool {
		if ((int) ($profile['real_supporting_count'] ?? 0) > 0) {
			return false;
		}
		$primary_body_chars = (int) ($profile['primary_body_chars'] ?? 0);
		$primary_chars = (int) ($profile['primary_total_chars'] ?? 0);
		$de_chars = (int) ($profile['de_content_chars'] ?? 0);
		if ($primary_body_chars < 120 && $primary_chars < 260 && $de_chars > 280) {
			return true;
		}
		if ($primary_chars >= 700 || $de_chars <= 600) {
			return false;
		}
		$max_supported_chars = max(600, (int) ceil($primary_chars * 2.5));
		return $de_chars > $max_supported_chars;
	}

	private static function unique_plain_text_chars(array $parts): int {
		$texts = [];
		foreach ($parts as $part) {
			$text = self::plain_text((string) $part);
			if ($text !== '') {
				$texts[] = $text;
			}
		}
		usort($texts, static function (string $a, string $b): int {
			return mb_strlen($b) <=> mb_strlen($a);
		});

		$accepted = [];
		foreach ($texts as $text) {
			$needle = mb_strtolower($text);
			$contained = false;
			foreach ($accepted as $existing) {
				if (mb_strpos(mb_strtolower($existing), $needle) !== false) {
					$contained = true;
					break;
				}
			}
			if (! $contained) {
				$accepted[] = $text;
			}
		}

		return mb_strlen(trim(implode(' ', $accepted)));
	}

	private static function plain_text(string $text): string {
		$text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', $text) ?: $text;
		return trim($text);
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
