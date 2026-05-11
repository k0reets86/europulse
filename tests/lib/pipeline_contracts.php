<?php
/**
 * Pipeline Contracts — assertion library for Phase A tests.
 *
 * Status: Phase A skeleton. Each function returns ['pass' => bool, 'reason' => string].
 * Suites collect results and print summary.
 *
 * Usage:
 *   require_once __DIR__ . '/pipeline_contracts.php';
 *   $r = PipelineContracts::assert_state(123, 'ready_publish');
 *   if (!$r['pass']) echo "FAIL: " . $r['reason'] . PHP_EOL;
 */

if (! defined('ABSPATH')) {
	echo "ERROR: pipeline_contracts.php must run via `wp eval-file` (needs ABSPATH)\n";
	exit(1);
}

class PipelineContracts {

	/** Item's current DB state matches expected. */
	public static function assert_state(int $id, string $expected_state): array {
		$row = EPV2_Queue::get_item($id);
		if (! $row) {
			return ['pass' => false, 'reason' => "Item #$id not found"];
		}
		$actual = (string) ($row->state ?? '');
		if ($actual !== $expected_state) {
			return ['pass' => false, 'reason' => "Item #$id state=$actual, expected $expected_state"];
		}
		return ['pass' => true, 'reason' => ''];
	}

	/** A specific key in ai_payload matches expected scalar. Dot-path supported. */
	public static function assert_payload_invariant(int $id, string $dot_path, $expected): array {
		$row = EPV2_Queue::get_item($id);
		if (! $row) {
			return ['pass' => false, 'reason' => "Item #$id not found"];
		}
		$payload = json_decode((string) ($row->ai_payload ?? ''), true);
		$payload = is_array($payload) ? $payload : [];
		$actual = $payload;
		foreach (explode('.', $dot_path) as $segment) {
			if (! is_array($actual) || ! array_key_exists($segment, $actual)) {
				return ['pass' => false, 'reason' => "Item #$id payload path '$dot_path' missing at segment '$segment'"];
			}
			$actual = $actual[$segment];
		}
		if ($actual !== $expected) {
			$a = is_scalar($actual) ? (string) $actual : json_encode($actual, JSON_UNESCAPED_UNICODE);
			$e = is_scalar($expected) ? (string) $expected : json_encode($expected, JSON_UNESCAPED_UNICODE);
			return ['pass' => false, 'reason' => "Item #$id payload.$dot_path = $a, expected $e"];
		}
		return ['pass' => true, 'reason' => ''];
	}

	/** Item's _system.workflow_step matches expected (or '' for "not started"). */
	public static function assert_workflow_step(int $id, string $expected_step): array {
		$row = EPV2_Queue::get_item($id);
		if (! $row) {
			return ['pass' => false, 'reason' => "Item #$id not found"];
		}
		$notes = json_decode((string) ($row->admin_notes ?? ''), true);
		$sys = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$actual = (string) ($sys['workflow_step'] ?? '');
		if ($actual !== $expected_step) {
			return ['pass' => false, 'reason' => "Item #$id workflow_step='$actual', expected '$expected_step'"];
		}
		return ['pass' => true, 'reason' => ''];
	}

	/** Item's quarantine_reason matches (substring match — allows partial). */
	public static function assert_quarantine_reason(int $id, string $reason_substring): array {
		$row = EPV2_Queue::get_item($id);
		if (! $row) {
			return ['pass' => false, 'reason' => "Item #$id not found"];
		}
		$notes = json_decode((string) ($row->admin_notes ?? ''), true);
		$sys = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$actual = (string) ($sys['quarantine_reason'] ?? '');
		if (stripos($actual, $reason_substring) === false) {
			return ['pass' => false, 'reason' => "Item #$id quarantine_reason='$actual' does not contain '$reason_substring'"];
		}
		return ['pass' => true, 'reason' => ''];
	}

	/** Item has story_card in _meta with non-empty editorial_match. */
	public static function assert_story_card_present(int $id): array {
		$row = EPV2_Queue::get_item($id);
		if (! $row) {
			return ['pass' => false, 'reason' => "Item #$id not found"];
		}
		$payload = json_decode((string) ($row->ai_payload ?? ''), true);
		$card = $payload['_meta']['story_card'] ?? null;
		if (! is_array($card) || empty($card)) {
			return ['pass' => false, 'reason' => "Item #$id has no story_card"];
		}
		$em = (string) ($card['editorial_match'] ?? '');
		if ($em === '') {
			return ['pass' => false, 'reason' => "Item #$id story_card.editorial_match empty"];
		}
		return ['pass' => true, 'reason' => ''];
	}

	/** No active workflow_owner_token (item not currently claimed). */
	public static function assert_no_active_token(int $id): array {
		$row = EPV2_Queue::get_item($id);
		if (! $row) {
			return ['pass' => false, 'reason' => "Item #$id not found"];
		}
		$notes = json_decode((string) ($row->admin_notes ?? ''), true);
		$sys = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
		$token = (string) ($sys['workflow_owner_token'] ?? '');
		if ($token !== '') {
			return ['pass' => false, 'reason' => "Item #$id has active token '$token'"];
		}
		return ['pass' => true, 'reason' => ''];
	}

	/** Soft-rich helper: run multiple asserts, return aggregated result. */
	public static function assert_all(array $checks): array {
		$results = [];
		$failed = 0;
		foreach ($checks as $name => $check) {
			$r = is_callable($check) ? $check() : $check;
			$results[$name] = $r;
			if (empty($r['pass'])) $failed++;
		}
		return [
			'pass' => $failed === 0,
			'failed' => $failed,
			'total' => count($checks),
			'results' => $results,
		];
	}

	/** Pretty-print a test result. */
	public static function print_result(string $test_name, array $result): void {
		if (! empty($result['pass'])) {
			echo "[PASS] $test_name\n";
		} else {
			echo "[FAIL] $test_name: " . ($result['reason'] ?? 'no reason') . "\n";
		}
	}
}
