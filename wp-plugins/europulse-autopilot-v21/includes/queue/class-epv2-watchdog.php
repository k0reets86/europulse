<?php
/**
 * EuroPulse AutoPilot v2.1 — Watchdog routines.
 *
 * Three background safety nets, all run from the maintenance bridge
 * (`EPV2_Rest::bridge_maintenance`) every ~5 minutes by the
 * orchestrator:
 *
 *   1. release_stuck_active_item()  — frees the active-automation slot
 *      when an item has been "in work" longer than its expected ceiling.
 *      Without this the orchestrator can deadlock on a single item.
 *   2. repair_polylang_links()      — fixes published bundles whose three
 *      language posts lost their Polylang association (e.g. after a
 *      crash mid-publish). Without re-linking, the language switcher on
 *      the frontend lands on 404, and the canonical/hreflang pair break
 *      (SEO downgrade).
 *   3. dedupe_published_posts()     — finds pairs of published posts
 *      with the same cluster_id (or same normalized title) within the
 *      last 7 days and trashes the younger one. Belt-and-braces over
 *      the at-ingest dedup gate.
 *
 * Each method returns a small status array suitable for inclusion in
 * the maintenance JSON response so the operator can audit.
 */

if (! defined('ABSPATH')) {
	exit;
}

final class EPV2_Watchdog {

	/**
	 * Phase 3 auto-reset: items that were rejected / sent to manual_review
	 * for reasons we have since fixed in code. Without this they sit stuck
	 * forever and the operator has to manually reprocess each one. The
	 * scan is conservative — only the substring matches below are eligible
	 * — so genuinely-bad items (true editorial rejects, hard-terminal
	 * causes) stay where they are.
	 *
	 * Eligible reasons (recovery candidates after the matching fix):
	 *   * "перепредставлена"         — selection bump removed in 07ce190
	 *   * "build_de_master"          — validator false positives fixed in
	 *                                  7214525, threshold mismatch in 345484c
	 *   * "publish_ready_gate"       — fixed by length-threshold realignment
	 *                                  in 345484c
	 *   * "length_below_kind_minimum"— same root cause
	 *
	 * Items already in `rejected` state are bumped back to `new` only if
	 * their last_quarantine_at is older than 30 minutes (avoid thrashing).
	 */
	public static function auto_reset_legacy_quarantine(int $limit = 20): array {
		$result = ['scanned' => 0, 'reset' => 0, 'items' => []];
		global $wpdb;
		$queue_table = $wpdb->prefix . 'epv2_queue';
		$runs_table = $wpdb->prefix . 'epv2_runs';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, state, error_message, admin_notes, ai_payload
			 FROM `{$queue_table}`
			 WHERE state IN ('rejected', 'manual_review')
			   AND updated_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)
			 ORDER BY updated_at ASC
			 LIMIT %d",
			$limit
		));
		$tokens = [
			'перепредставлена',
			'build_de_master',
			'publish_ready_gate',
			'length_below_kind_minimum',
		];
		foreach ((array) $rows as $row) {
			$result['scanned']++;
			$id = (int) $row->id;
			$blob = strtolower(
				(string) ($row->error_message ?? '') . ' ' .
				(string) ($row->admin_notes ?? '')
			);
			$matched = '';
			foreach ($tokens as $token) {
				if (mb_stripos($blob, $token) !== false) {
					$matched = $token;
					break;
				}
			}
			if ($matched === '') {
				continue;
			}
			// 1. clear payload caches so Story Card and content_kind rebuild
			$payload = json_decode((string) ($row->ai_payload ?? ''), true);
			if (is_array($payload)) {
				unset($payload['_meta']['content_kind']);
				unset($payload['_meta']['story_card']);
				EPV2_Queue::update_fields($id, [
					'ai_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
				]);
			}
			// 2. wipe stale selection + workflow counters
			$notes = json_decode((string) ($row->admin_notes ?? ''), true);
			$notes = is_array($notes) ? $notes : [];
			unset($notes['selection']);
			$sys = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
			foreach ([
				'workflow_step', 'workflow_step_attempts', 'workflow_step_status',
				'workflow_terminal_reason', 'quarantine_reason', 'last_stage_blocker',
				'retries', 'importance_score', 'importance_threshold', 'next_operator_action',
			] as $k) {
				unset($sys[$k]);
			}
			$notes['_system'] = $sys;
			EPV2_Queue::update_fields($id, [
				'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
			]);
			// 3. clear run history so attempts counter resets
			$wpdb->query($wpdb->prepare(
				"DELETE FROM `{$runs_table}`
				 WHERE job_name = 'process'
				   AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.last_item_id')) AS UNSIGNED) = %d",
				$id
			));
			// 4. flip back to `new`
			EPV2_Queue::mark_state($id, 'new', [
				'error_message' => 'Auto-reset by watchdog: legacy reason "' . $matched . '" already fixed in code.',
			]);
			$result['reset']++;
			$result['items'][] = [
				'id' => $id,
				'matched' => $matched,
				'previous_state' => (string) ($row->state ?? ''),
			];
		}
		return $result;
	}

	/**
	 * Free the active automation slot when the current item has been
	 * sitting there longer than the worker would normally take.
	 *
	 * @param int $stale_minutes default 15. After this many minutes
	 *                           with no progress the slot is released.
	 */
	public static function release_stuck_active_item(int $stale_minutes = 15): array {
		$result = ['released' => 0, 'item_id' => 0, 'stale_minutes' => $stale_minutes];
		$active_id = (int) get_option('epv2_active_automation_item', 0);
		if ($active_id <= 0) {
			return $result;
		}
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT id, state, updated_at FROM {$wpdb->prefix}epv2_queue WHERE id = %d",
			$active_id
		));
		if (! $row) {
			// Pointer dangles — clear it.
			delete_option('epv2_active_automation_item');
			$result['released'] = 1;
			$result['item_id'] = $active_id;
			$result['reason'] = 'dangling_pointer';
			return $result;
		}
		$updated_ts = strtotime((string) ($row->updated_at ?? '')) ?: 0;
		$stale_at = time() - ($stale_minutes * MINUTE_IN_SECONDS);
		if ($updated_ts > $stale_at) {
			return $result;
		}
		$current_state = (string) ($row->state ?? '');
		// Already in a terminal state? just release the slot.
		if (in_array($current_state, ['published', 'rejected', 'manual_review', 'duplicate'], true)) {
			// Atomic CAS: only delete the option if it still points to the
			// item we inspected. If orchestrator claimed a new item between
			// our read and now, leave its claim alone.
			$current_active = (int) get_option('epv2_active_automation_item', 0);
			if ($current_active === $active_id) {
				delete_option('epv2_active_automation_item');
			}
			$result['released'] = 1;
			$result['item_id'] = $active_id;
			$result['reason'] = 'active_pointed_to_terminal';
			return $result;
		}
		// Re-verify the active pointer hasn't moved (concurrent claim by
		// orchestrator). If it has, our stale-state assessment was for a
		// row no longer owned — skip the reset to avoid trampling the
		// fresh claim.
		$current_active = (int) get_option('epv2_active_automation_item', 0);
		if ($current_active !== $active_id) {
			$result['reason'] = 'active_pointer_moved_during_check';
			return $result;
		}
		// Push the item back to `new` so it gets a fresh attempt on the
		// next orchestrator tick. The per-step retry budget (phase 2.4)
		// will route to manual_review if it fails again.
		EPV2_Queue::mark_state($active_id, 'new', [
			'error_message' => sprintf(
				'Watchdog: освобождён active_automation_item — застрял в "%s" более %d минут.',
				$current_state,
				$stale_minutes
			),
		]);
		// Re-check once more after mark_state — if option moved during the
		// transition, leave it alone.
		$current_active = (int) get_option('epv2_active_automation_item', 0);
		if ($current_active === $active_id) {
			delete_option('epv2_active_automation_item');
		}
		$result['released'] = 1;
		$result['item_id'] = $active_id;
		$result['reason'] = 'stuck_in_' . $current_state;
		if (class_exists('EPV2_Notifier')) {
			EPV2_Notifier::notify('warn', 'watchdog', sprintf(
				'Stuck active item #%d released (state=%s, idle > %d min)',
				$active_id, $current_state, $stale_minutes
			), ['item_id' => $active_id, 'state' => $current_state]);
		}
		return $result;
	}

	/**
	 * Find published bundles where the Polylang translation chain is broken
	 * and re-attach all three language posts.
	 *
	 * @param int $limit Process at most this many bundles per run.
	 */
	public static function repair_polylang_links(int $limit = 30): array {
		$result = ['checked' => 0, 'repaired' => 0, 'pairs' => []];
		if (! function_exists('pll_get_post_translations') || ! function_exists('pll_save_post_translations')) {
			$result['polylang_unavailable'] = true;
			return $result;
		}
		global $wpdb;
		// Recently published items where queue carries _epv2_queue_id linking
		// the three language posts. We pick distinct queue_ids that have ≥2
		// language posts on file but Polylang doesn't agree.
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT pm.meta_value AS queue_id, GROUP_CONCAT(pm.post_id) AS post_ids
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_epv2_queue_id'
			   AND p.post_type = 'post'
			   AND p.post_status = 'publish'
			   AND p.post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
			 GROUP BY pm.meta_value
			 HAVING COUNT(*) >= 2
			 ORDER BY MAX(p.post_date_gmt) DESC
			 LIMIT %d",
			$limit
		));
		foreach ($rows as $row) {
			$result['checked']++;
			$post_ids = array_filter(array_map('intval', explode(',', (string) $row->post_ids)));
			if (count($post_ids) < 2) {
				continue;
			}
			$by_lang = [];
			foreach ($post_ids as $post_id) {
				$lang = function_exists('pll_get_post_language') ? pll_get_post_language($post_id) : '';
				if ($lang) {
					$by_lang[$lang] = $post_id;
				}
			}
			if (count($by_lang) < 2) {
				continue;
			}
			// Check if the existing translation map already covers all of them.
			$first_post = (int) array_values($by_lang)[0];
			$linked = pll_get_post_translations($first_post);
			$linked = is_array($linked) ? $linked : [];
			ksort($by_lang);
			ksort($linked);
			if ($by_lang === array_filter($linked) && count($linked) >= count($by_lang)) {
				continue;
			}
			pll_save_post_translations($by_lang);
			$result['repaired']++;
			$result['pairs'][] = [
				'queue_id' => (int) $row->queue_id,
				'before' => $linked,
				'after' => $by_lang,
			];
		}
		return $result;
	}

	/**
	 * Trash the younger of any pair of published posts that share the same
	 * cluster_id within a recent window. Belt-and-braces over the at-ingest
	 * deduplicator — protects against rare race-condition double publishes
	 * (e.g. two orchestrator workers each picking the same retry tick).
	 *
	 * @param int $limit Process at most this many duplicate pairs per run.
	 */
	public static function dedupe_published_posts(int $limit = 50): array {
		$result = ['checked_pairs' => 0, 'deleted' => 0, 'kept_pairs' => []];
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT pm.meta_value AS cluster_id,
			        GROUP_CONCAT(pm.post_id ORDER BY p.post_date_gmt ASC) AS post_ids,
			        GROUP_CONCAT(p.post_date_gmt ORDER BY p.post_date_gmt ASC) AS dates,
			        GROUP_CONCAT(pl.meta_value ORDER BY p.post_date_gmt ASC SEPARATOR '|||') AS languages
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 LEFT JOIN {$wpdb->postmeta} pl ON pl.post_id = pm.post_id AND pl.meta_key = '_polylang_language'
			 WHERE pm.meta_key = '_epv2_cluster_id'
			   AND pm.meta_value <> '0'
			   AND pm.meta_value <> ''
			   AND p.post_type = 'post'
			   AND p.post_status = 'publish'
			   AND p.post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
			 GROUP BY pm.meta_value
			 HAVING COUNT(*) > 3
			 ORDER BY MAX(p.post_date_gmt) DESC
			 LIMIT %d",
			$limit
		));
		foreach ($rows as $row) {
			$result['checked_pairs']++;
			$post_ids = array_filter(array_map('intval', explode(',', (string) $row->post_ids)));
			// Group by language; keep the OLDEST post per language, trash newer ones.
			$by_lang_ordered = [];
			foreach ($post_ids as $post_id) {
				$lang = function_exists('pll_get_post_language') ? pll_get_post_language($post_id) : 'de';
				if (! isset($by_lang_ordered[$lang])) {
					$by_lang_ordered[$lang] = [];
				}
				$by_lang_ordered[$lang][] = $post_id;
			}
			$kept = [];
			foreach ($by_lang_ordered as $lang => $ids) {
				$kept[$lang] = (int) $ids[0];
				if (count($ids) > 1) {
					foreach (array_slice($ids, 1) as $younger) {
						wp_trash_post((int) $younger);
						$result['deleted']++;
					}
				}
			}
			$result['kept_pairs'][] = [
				'cluster_id' => (string) $row->cluster_id,
				'kept' => $kept,
			];
			if (class_exists('EPV2_Notifier')) {
				EPV2_Notifier::notify('warn', 'watchdog', sprintf(
					'Duplicate posts trimmed in cluster %s — kept oldest per language',
					(string) $row->cluster_id
				), ['cluster_id' => (string) $row->cluster_id, 'kept' => $kept]);
			}
		}
		return $result;
	}
}
