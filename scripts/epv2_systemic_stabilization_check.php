<?php

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run with WP-CLI: wp eval-file scripts/epv2_systemic_stabilization_check.php\n");
	exit(1);
}

$failures = [];

if (! class_exists('EPV2_Publish_Gate')) {
	$failures[] = 'EPV2_Publish_Gate is not loaded';
}

foreach ([1022, 1036, 1042] as $id) {
	$item = EPV2_Queue::get_item_summary($id);
	if (! $item) {
		continue;
	}
	$system = EPV2_Queue::workflow_system_payload($item);
	if ((string) ($item->state ?? '') !== 'error') {
		$failures[] = "item {$id} expected error quarantine state";
	}
	if ((string) ($system['workflow_terminal_reason'] ?? '') !== 'workflow_quarantine') {
		$failures[] = "item {$id} missing workflow_quarantine terminal reason";
	}
	if ((string) ($system['quarantine_reason'] ?? '') === '') {
		$failures[] = "item {$id} missing quarantine_reason";
	}
}

$item_1029 = EPV2_Queue::get_item_summary(1029);
if ($item_1029) {
	$system = EPV2_Queue::workflow_system_payload($item_1029);
	if ((string) ($item_1029->state ?? '') !== 'rejected') {
		$failures[] = 'item 1029 expected rejected selection-blocked state';
	}
	if ((string) ($system['workflow_terminal_reason'] ?? '') !== 'selection_publish_blocked') {
		$failures[] = 'item 1029 missing selection_publish_blocked terminal reason';
	}
}

foreach ([1029, 1050] as $id) {
	$item = EPV2_Queue::get_item_summary($id);
	if (! $item) {
		continue;
	}
	$payload = json_decode((string) ($item->ai_payload ?? ''), true);
	$payload = is_array($payload) ? $payload : [];
	$gate = EPV2_Publish_Gate::evaluate($item, $payload, ['context' => 'ready_publish']);
	if (! empty($gate['allowed']) || ! empty($gate['selection_publishable'])) {
		$failures[] = "item {$id} low/reject gate unexpectedly publishable";
	}
}

$publish_item = EPV2_Queue::next_due_item_for_publish_fast(false);
if ($publish_item) {
	$failures[] = 'publish selector returned due item ' . (string) ($publish_item->id ?? 'unknown') . ' while queue should have no publishable rows';
}

$health = EPV2_Queue::bridge_health_snapshot();
if (! empty($health['has_processable_items'])) {
	$failures[] = 'health reports processable items after quarantine';
}
if ((int) ($health['incident_counters']['stale_retry_rows'] ?? 0) !== 0) {
	$failures[] = 'stale retry rows detected';
}

if ($failures !== []) {
	foreach ($failures as $failure) {
		if (class_exists('WP_CLI')) {
			WP_CLI::warning($failure);
		} else {
			fwrite(STDERR, $failure . "\n");
		}
	}
	exit(1);
}

echo wp_json_encode([
	'ok' => true,
	'checked_items' => [1022, 1029, 1036, 1042, 1050],
	'publish_selector' => 'none',
	'incident_counters' => $health['incident_counters'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
