<?php

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Selection_Audit {
	public static function record_candidate(array $item, ?object $source, array $analysis = [], string $phase = 'stage', string $outcome = '', array $context = [], int $queue_id = 0): void {
		global $wpdb;

		if (! isset($wpdb) || ! $wpdb instanceof wpdb) {
			return;
		}

		$title = sanitize_text_field((string) ($item['title'] ?? ''));
		$url = esc_url_raw((string) ($item['url'] ?? ''));
		$category = sanitize_text_field((string) ($analysis['category'] ?? ($item['category'] ?? ($source->category_bias ?? ''))));
		$reason = self::reason_text($analysis, $context, $outcome);
		$original_date = self::mysql_date_or_null((string) ($item['date'] ?? ''));

		$row = [
			'queue_id' => $queue_id > 0 ? $queue_id : null,
			'source_id' => is_object($source) ? ((int) ($source->id ?? 0) ?: null) : null,
			'candidate_hash' => self::candidate_hash($title, $url, (string) ($item['content'] ?? ''), (string) ($item['excerpt'] ?? '')),
			'phase' => sanitize_key($phase) ?: 'stage',
			'outcome' => sanitize_key($outcome),
			'decision' => sanitize_key((string) ($analysis['decision'] ?? '')),
			'tier' => strtoupper(sanitize_key((string) ($analysis['tier'] ?? ''))),
			'score' => max(0, min(100, (int) ($analysis['score'] ?? 0))),
			'category' => $category,
			'reject_class' => sanitize_key((string) ($analysis['reject_class'] ?? '')),
			'source_name' => is_object($source) ? sanitize_text_field((string) ($source->name ?? '')) : '',
			'source_type' => is_object($source) ? sanitize_key((string) ($source->type ?? '')) : '',
			'source_priority' => is_object($source) ? max(0, min(10, (int) ($source->priority ?? 0))) : 0,
			'source_risk' => is_object($source) ? sanitize_key((string) ($source->risk_level ?? '')) : '',
			'original_url' => $url !== '' ? $url : null,
			'original_title' => $title,
			'original_date' => $original_date,
			'reason' => $reason,
			'selection_json' => $analysis !== [] ? wp_json_encode($analysis, JSON_UNESCAPED_UNICODE) : null,
			'ai_gate_json' => isset($context['ai_gate']) && is_array($context['ai_gate']) ? wp_json_encode($context['ai_gate'], JSON_UNESCAPED_UNICODE) : null,
			'planner_json' => isset($context['planner']) && is_array($context['planner']) ? wp_json_encode($context['planner'], JSON_UNESCAPED_UNICODE) : null,
			'context_json' => self::safe_context_json($context),
			'created_at' => gmdate('Y-m-d H:i:s'),
		];

		$wpdb->insert(
			$wpdb->prefix . 'epv2_selection_audit',
			$row,
			['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
		);
	}

	public static function summary(int $days = 7): array {
		global $wpdb;

		$days = max(1, min(30, $days));
		$table = $wpdb->prefix . 'epv2_selection_audit';
		if (! self::table_exists($table)) {
			return [
				'available' => false,
				'reason' => 'selection_audit_table_missing',
			];
		}

		$since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
		$totals = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT outcome, decision, COUNT(*) AS n, ROUND(AVG(score), 1) AS avg_score
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY outcome, decision
				ORDER BY n DESC",
				$since
			),
			ARRAY_A
		);
		$sources = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_name, outcome, COUNT(*) AS n, ROUND(AVG(score), 1) AS avg_score
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY source_name, outcome
				ORDER BY n DESC
				LIMIT 40",
				$since
			),
			ARRAY_A
		);
		$categories = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT category, outcome, COUNT(*) AS n, ROUND(AVG(score), 1) AS avg_score
				FROM {$table}
				WHERE created_at >= %s
				GROUP BY category, outcome
				ORDER BY n DESC
				LIMIT 40",
				$since
			),
			ARRAY_A
		);

		return [
			'available' => true,
			'window_days' => $days,
			'totals' => is_array($totals) ? $totals : [],
			'sources' => is_array($sources) ? $sources : [],
			'categories' => is_array($categories) ? $categories : [],
		];
	}

	private static function table_exists(string $table): bool {
		global $wpdb;
		return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
	}

	private static function candidate_hash(string $title, string $url, string $content, string $excerpt): string {
		$basis = $url !== '' ? $url : mb_strtolower(trim($title . ' ' . wp_strip_all_tags($excerpt . ' ' . $content)));
		return sha1($basis);
	}

	private static function reason_text(array $analysis, array $context, string $outcome): string {
		$parts = [];
		if ($outcome !== '') {
			$parts[] = sanitize_key($outcome);
		}
		foreach ((array) ($analysis['reasons'] ?? []) as $reason) {
			$reason = trim(wp_strip_all_tags((string) $reason));
			if ($reason !== '') {
				$parts[] = $reason;
			}
			if (count($parts) >= 6) {
				break;
			}
		}
		foreach (['block_reason', 'gate_reason', 'planner_reason'] as $key) {
			$value = trim(wp_strip_all_tags((string) ($context[$key] ?? '')));
			if ($value !== '') {
				$parts[] = $value;
			}
		}
		return mb_substr(implode(' | ', array_values(array_unique($parts))), 0, 2000);
	}

	private static function safe_context_json(array $context): ?string {
		unset($context['ai_gate'], $context['planner']);
		if ($context === []) {
			return null;
		}
		$json = wp_json_encode($context, JSON_UNESCAPED_UNICODE);
		return is_string($json) ? $json : null;
	}

	private static function mysql_date_or_null(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}
		$ts = strtotime($value);
		return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
	}
}
