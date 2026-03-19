#!/usr/bin/env php
<?php

declare(strict_types=1);

$input = null;
for ($i = 1; $i < $argc; $i++) {
	if ($argv[$i] === '--input' && isset($argv[$i + 1])) {
		$input = $argv[$i + 1];
		break;
	}
}

if (! is_string($input) || $input === '' || ! is_file($input)) {
	fwrite(STDERR, "Missing --input request file\n");
	exit(1);
}

$wp_load = '/var/www/europulse/public/wp-load.php';
if (! is_file($wp_load)) {
	fwrite(STDERR, "Unable to locate wp-load.php\n");
	exit(1);
}

require_once $wp_load;

try {
	$request = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
	$request = is_array($request) ? $request : [];
	$queue_id = (int) ($request['queue_id'] ?? 0);
	$stage = sanitize_key((string) ($request['stage'] ?? 'full_bundle'));
	$existing_payload = is_array($request['existing_payload'] ?? null) ? $request['existing_payload'] : [];
	$existing_payload = EPV2_AI_Processor::normalize_existing_payload($existing_payload);

	$item = EPV2_Queue::get_item($queue_id);
	if (! $item) {
		throw new RuntimeException('Worker queue item not found');
	}

	$category_seed = implode(',', array_values(array_filter((array) ($existing_payload['categories'] ?? []))));
	if ($category_seed === '') {
		$detected = EPV2_Categorizer::detect(
			(string) ($item->original_title ?? ''),
			(string) ($item->original_content ?? ''),
			(string) ($item->category_final ?: $item->category_proposed ?: 'deutschland')
		);
		$category_seed = $detected !== '' ? $detected : (string) ($item->category_final ?: $item->category_proposed ?: 'deutschland');
	}
	$categories = EPV2_Review::normalize_categories($category_seed);
	$style = (string) ($existing_payload['_meta']['style'] ?? EPV2_Settings::get('rewrite_style', 'lively'));

	$payload = [];
	$stage_result = $stage;
	switch ($stage) {
		case 'publish_finish':
			if ($existing_payload === []) {
				throw new RuntimeException('Worker publish_finish requires existing payload');
			}
			$payload = EPV2_AI_Processor::try_lift_payload_to_publish_grade($item, $existing_payload);
			$stage_result = 'worker_publish_finish';
			break;

		case 'translate_finish':
			if ($existing_payload === []) {
				throw new RuntimeException('Worker translate_finish requires existing payload');
			}
			$payload = EPV2_AI_Processor::repair_payload_languages($existing_payload);
			$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
			$payload['_meta']['translations_deferred'] = false;
			unset($payload['_meta']['pipeline_stage']);
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload);
			$payload = EPV2_AI_Processor::try_lift_payload_to_publish_grade($item, $payload);
			$stage_result = 'worker_translate_finish';
			break;

		case 'rebuild_bundle':
			$payload = EPV2_AI_Processor::generate_review_payload(
				$item,
				$categories,
				$style,
				true,
				['force_supporting' => true, 'target_supporting' => 4]
			);
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload);
			if (! empty($payload['_meta']['translations_deferred'])) {
				$payload = EPV2_AI_Processor::repair_payload_languages($payload);
				$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
				$payload['_meta']['translations_deferred'] = false;
				unset($payload['_meta']['pipeline_stage']);
				$payload = EPV2_AI_Processor::normalize_existing_payload($payload);
			}
			$payload = EPV2_AI_Processor::try_lift_payload_to_publish_grade($item, $payload);
			$stage_result = 'worker_rebuild_bundle';
			break;

		case 'full_bundle':
		default:
			$payload = EPV2_AI_Processor::generate_review_payload($item, $categories, $style, true);
			$payload = EPV2_AI_Processor::normalize_existing_payload($payload);
			if (! empty($payload['_meta']['translations_deferred'])) {
				$payload = EPV2_AI_Processor::repair_payload_languages($payload);
				$payload['_meta'] = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
				$payload['_meta']['translations_deferred'] = false;
				unset($payload['_meta']['pipeline_stage']);
				$payload = EPV2_AI_Processor::normalize_existing_payload($payload);
			}
			$payload = EPV2_AI_Processor::try_lift_payload_to_publish_grade($item, $payload);
			$stage_result = 'worker_full_bundle';
			break;
	}

	$payload = EPV2_AI_Processor::normalize_existing_payload($payload);
	$outcome = EPV2_AI_Processor::payload_is_publish_ready($payload)
		? 'ready_publish'
		: (EPV2_AI_Processor::payload_is_review_ready($payload) ? 'ready_review' : 'retry_process');

	$response = [
		'queue_id' => $queue_id,
		'stage' => $stage,
		'stage_result' => $stage_result,
		'outcome' => $outcome,
		'payload' => $payload,
	];

	echo wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit(0);
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
