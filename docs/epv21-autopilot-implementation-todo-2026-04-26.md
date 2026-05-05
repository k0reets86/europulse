# EPV21 Autopilot Implementation TODO 2026-04-26

## Purpose

Bring EuroPulse AutoPilot to a proven autonomous production loop:

`collect -> new -> active -> ready_publish -> published`

This file is the active checklist for the implementation pass started on 2026-04-26.
Update it after every implementation block with what was changed, verified, and left open.

## Rules

- Do not make destructive data changes without a fresh backup.
- Do not reset or overwrite unrelated dirty working-tree changes.
- After each block, verify:
  - site HTTP status is not `500/502`;
  - nginx, php-fpm, worker, orchestrator are healthy;
  - PHP syntax passes for touched plugin files;
  - queue/runtime health did not regress.
- Prefer small packages over large risky rewrites.
- Stop only if a step risks irreversible data loss.

## Block 1. Runtime Boundary

- [x] Keep `server_orchestrator + worker-v21` as the only production runtime.
- [x] Keep WP-Cron autopilot hooks compatibility-only.
- [x] Confirm no active `epv2_collect`, `epv2_process`, `epv2_publish` WP-Cron events.
- [x] Add or verify runtime health fields for orchestrator, worker, bridge, queue, publish, and recent runs.
- [x] Identify non-autopilot runaway processes that affect server load.

## Block 2. Idle Loop

- [x] Make `bridge/state.has_processable_items` the only process trigger for the external orchestrator.
- [x] Ensure `ready_publish` is never treated as processable.
- [x] Ensure orchestrator logs idle state instead of executing process when there is no processable work.
- [x] Add or verify a regression check for an empty processing queue.

## Block 3. Ready Publish Autopublish

- [x] Diagnose any `ready_publish` item that does not publish automatically.
- [x] Expose publish blocker diagnostics in bridge/admin health.
- [x] Verify `ready_publish -> published` with post ID, media, SEO, and metadata.

## Block 4. Full Autonomous Cycle

- [x] Run a controlled collect/process/publish cycle with small batch limits.
- [x] Trace every item by `queue_id` and `run_id`.
- [x] Prove multiple autonomous items without manual rescue.

## Block 5. Queue State Contract

- [x] Keep user-facing states canonical: `new`, `active`, `ready_publish`, `published`, `rejected`, `error`, `duplicate`, `publishing`.
- [x] Keep internal workflow state in `admin_notes._system`.
- [x] Make queue contract health return a fresh `ok`, not stale `pending`.
- [x] Add checks for stale payload flags, invalid media, terminal owner drift, and non-publish-grade ready rows.

## Block 6. Worker Externalization

- [ ] Ensure heavy stages are worker-owned in canonical mode.
- [ ] Ensure WordPress does not silently run heavy fallback when worker is unavailable.
- [x] Ensure worker failures become retry/error diagnostics, not ordinary rejects.

## Block 7. Memory And DB Load

- [ ] Keep persisted queue payloads compact.
- [ ] Avoid full payload decode in list views and bridge idle paths.
- [ ] Check DB indexes used by queue/runtime selectors.
- [ ] Keep PHP/worker memory bounded.

## Block 8. Source Quality

- [x] Add or verify source-level circuit breaker and cooldown.
- [x] Reject generic service/help/archive pages before AI.
- [ ] Deduplicate before AI.
- [x] Keep prioritization aligned with Germany, Ukraine, EU, migration, economy, politics, and practical reader value.

## Block 9. Media Contract

- [ ] Enforce source-first media.
- [ ] Reject HTML/article URLs as image URLs.
- [ ] Keep generated covers non-publish-grade unless explicitly approved.
- [ ] Route media failures to `repair_media`/retry, not ordinary reject.

## Block 10. Observability

- [ ] Standardize logs on `queue_id`, `run_id`, `workflow_step`, `owner_token`, `attempt`, `outcome`, `duration_ms`, `memory_mb`, and `error_class`.
- [x] Add or verify operator commands for health, contract checks, runtime snapshot, last runs, and stale-owner repair.
- [x] Surface acceptance streak and publish blockers in dashboard/bridge.

## Block 11. Security And WordPress Standards

- [ ] Verify admin actions use capability checks and nonces.
- [ ] Verify REST bridge auth, no public state-changing endpoints, and no cache on bridge control endpoints.
- [ ] Verify SQL uses prepared statements for user input.
- [ ] Verify output escaping and media validation.
- [ ] Verify secrets are not logged or stored plaintext.

## Block 12. Acceptance

- [ ] Prove 10-20 autonomous publish-grade items.
- [ ] Prove no stuck active owner.
- [ ] Prove no process loop when processing queue is empty.
- [ ] Prove no WP-Cron duplication.
- [ ] Prove no PHP fatal, `500`, `502`, or OOM.
- [ ] Prove `bridge_health.status=ok`.
- [ ] Prove `queue_contract.status=ok`.

## Implementation Log

### 2026-04-26

- Created checklist.
- Current known live queue snapshot before implementation: `published=93`, `ready_publish=1`, `rejected=1`, no `new/active`.
- Current known services before implementation: nginx, php-fpm, worker, orchestrator active.
- Current known risks before implementation: one old Playwright/Chrome process tree consuming CPU/RAM; recent process run history showed repeated `terminal_ready_payload_short_circuit`.
- Backups created before implementation:
  - `backups/epv21-repo-pre-autopilot-20260426-224251.tar.gz`
  - `backups/epv21-live-plugin-pre-autopilot-20260426-224251.tar.gz`
  - `backups/epv21-db-pre-autopilot-20260426-224251.sql`
- Fixed and deployed bridge processability parity: live `bridge_next_processable_row()` now uses the full read-only processability check and skips stale/non-processable `new` rows.
- Restarted `epv2-orchestrator`; verified idle state now logs `process idle` instead of running `process` when `has_processable_items=false`.
- Cleaned old non-published queue rows after backup: deleted `2` rows (`new=1`, `rejected=1`); published WordPress posts were not deleted.
- Re-enabled collection and ran one fresh collect manually. Result: collect run `24796`, `8` fresh `new` rows.
- Verified repeated fresh autonomous cycles:
  - collect run `24796`
  - process run `24797`, result `worker_rebuild_ready_publish`
  - publish run `24798`, result `published_items`
  - queue item `1006 -> published`, post IDs `6225`, `6226`, `6227`
  - process run `24799`, publish run `24800`, second fresh item published
  - process run `24801`, publish run `24802`, third fresh item published
- Latest observed cleaned queue snapshot: `new=5`, `published=3`.
- Post-check: nginx, php-fpm, worker, and orchestrator active; site returned HTTP `301` from WordPress without `500/502`; memory remained above `1.5 GB available`.
- Connected bridge queue contract health to the existing regression checker. It now returns cached fresh results with `checked`, `violations_count`, `top_issues`, `status`, and `checked_at`.
- Found and fixed a false-positive contract mismatch: published rows were checked with stricter translation readiness than the actual publish gate. The checker now uses strict readiness or routing readiness consistently.
- Temporarily paused automation while investigating the warning, then resumed after `queue_contract.status=ok`.
- Fresh batch completed fully: `8/8` items published from the cleaned queue.
- Final fresh batch runs ended with process/publish `24811/24812`.
- Final observed cleaned queue snapshot: `published=8`, no `new`, no active item.
- Final observed runtime snapshot: `has_processable_items=false`, `queue_contract.status=ok`, `violations_count=0`.
- Latest acceptance snapshot: `consecutive_autonomous_publish_grade=8`, `remaining_to_target=2`.
- Found the publish-lane timer violation reported by the user: `/bridge/publish` called `publish_scheduled(true)`, and `publish_scheduled()` passed that force flag into `next_due_item_for_publish_fast($force)`, so server-orchestrator publish calls could ignore `publish_not_before`.
- Fixed the timer gate: `publish_scheduled()` now always selects publish items with `next_due_item_for_publish_fast(false)`. A forced job may start the publish run, but it cannot bypass the per-item `publish_not_before` rule.
- Verified fix on live with control item `1015`: process run `24817` moved it to `ready_publish` at `2026-04-26 23:21:17`, `publish_not_before=1777246020` (`2026-04-26 23:27:00 UTC`); forced publish run `24818` returned `no_due_items` and did not publish it early.
- Updated admin queue block order: `Новые`, `В работе`, `Готово к публикации`, `Отклонённые`, `Опубликованные материалы`.
- Live syntax checks passed for `class-epv2-publisher.php` and `class-epv2-admin.php`; site and admin returned WordPress responses without `500/502`.
- User reported the countdown problem: the `Готово к публикации` block must visibly show a countdown from `05:00` to `00:00`, and new arrivals in `ready_publish` must not reset or extend the timer of the already-waiting first material.
- Paused live collection and automation at `2026-04-27 01:25 CEST`:
  - `epv2_collect_paused=1`
  - `epv2_automation_paused=1`
- Current paused queue snapshot:
  - `published=9`
  - `ready_publish=2`
  - `new=3`
  - item `1015`: `ready_publish_at=2026-04-26 23:21:17`, `publish_not_before=1777246320`
  - item `1016`: `ready_publish_at=2026-04-26 23:23:06`, `publish_not_before=1777246320`
- New confirmed defect: two `ready_publish` rows can share the same slot. This violates the required sequence: first ready item publishes after its 5-minute wait, second publishes 5 minutes after first, third 5 minutes after second.
- A partial source/live patch was deployed before the pause: `fast_transition_item_to_ready_publish()` now calls `EPV2_Queue::normalize_ready_publish_schedule(false)`. This is not enough and must be reviewed before automation is resumed.

## Resume Point: 2026-04-27 05:35 Europe/Berlin

- Do not resume collection or automation until the publish schedule contract is fixed and verified.
- First tasks after resume:
  - [x] Make the admin `Готово к публикации` countdown visible and stable as `MM:SS`, starting from the actual first due item's remaining time.
  - [x] Fix scheduler invariant: adding a new `ready_publish` item must never increase or move the first already-waiting item's `publish_not_before`.
  - [x] Fix queue invariant: `ready_publish` rows must have unique, strictly increasing publish slots with at least `publish_interval_minutes` between them.
  - [x] Re-check `normalize_ready_publish_schedule()`, `next_publish_slot_for_queue()`, `first_waiting_publish_slot()`, `bridge_next_ready_publish_timestamp()`, and the fast ready-publish path.
  - [x] Run a controlled test with at least 3 items: first remains at its original slot, second is first+5 min, third is second+5 min, and forced `/bridge/publish` returns `no_due_items` before the first slot.
  - [x] Only after that, unpause automation; keep collection paused until publish-lane behavior is proven.

### 2026-04-27

- Fixed admin countdown to use the actual first `ready_publish` slot and show `00:00` for due items.
- Fixed ready-publish normalization so the first waiting slot is not moved by later arrivals.
- Fixed bridge runtime `next_ready_publish` to read `_system.publish_not_before`.
- Verified autonomous publish lane: `1015`, `1016`, `1017`, `1018`, `1019` published by orchestrator without manual process/publish calls, spaced by the 5-minute lane.
- Resumed collection; collect run `24837` happened automatically and created item `1020`.
- Fixed AI provider failure loop: `All AI providers failed` now sets retry/backoff and clears active owner. Item `1020` is in backoff until `2026-04-27 11:40:26`.
- Final verification: `queue_contract.status=ok`, `acceptance.status=accepted`, `consecutive_autonomous_publish_grade=12`, site returns WordPress `301`, services active.
- Report: `docs/epv21-autopilot-report-2026-04-27.md`.

### 2026-04-27 Systemic Stabilization Update

- Systemic plan added: `docs/epv21-systemic-stabilization-plan-2026-04-27.md`.
- Backups before implementation:
  - `backups/epv21-live-plugin-pre-systemic-20260427-193120.tar.gz`
  - `backups/epv21-db-pre-systemic-20260427-193120.sql`
- Implemented canonical publish gate:
  - new class `EPV2_Publish_Gate`;
  - wired into ready transition, fast-ready path, queue `mark_state`, publish selector, and acceptance snapshot.
- Implemented terminal/quarantine policy for pathological loops:
  - `1022 -> error/workflow_quarantine`
  - `1029 -> rejected/selection_publish_blocked`
  - `1036 -> error/workflow_quarantine`
  - `1042 -> error/workflow_quarantine`
- Added workflow-stage circuit breaker:
  - option `epv2_workflow_stage_circuit`
  - worker stage refuses to start while stage circuit is open.
- Added hard pre-AI editorial filters in collector:
  - US-local without core geo relevance;
  - biotech/clinical PR without Europe/Germany/Ukraine relevance;
  - generic product tests;
  - event/opening-hours pages without news delta;
  - garbled mixed-script business titles.
- Added bridge incident counters:
  - `low_reject_published_24h`
  - `terminal_quarantine_rows`
  - `stale_retry_rows`
- Added regression script:
  - `scripts/epv2_systemic_stabilization_check.php`
- Follow-up fix:
  - disabled soft rejected reactivation in auto publish-grade mode to stop maintenance ping-pong.
- Verification:
  - live PHP lint passed for all touched files;
  - site returned WordPress `301`, no `500/502`;
  - worker and orchestrator active;
  - controlled collect run `25052` returned `0` new, `0` errors;
  - regression check passed for `[1022, 1029, 1036, 1042, 1050]`;
  - current health: `has_processable_items=false`, `next_ready_publish=0`, `queue_contract.status=ok`, `stale_retry_rows=0`.

### Current Open Items After Systemic Pass

- [ ] Wait for next natural collect window or controlled source update to produce quality candidates.
- [ ] Prove a fresh post-fix series: `new -> active -> ready_publish -> published`.
- [ ] Prove `low_reject_published_24h = 0` after the 24h historical window expires.
- [ ] Add DB/generated columns or indexes for hot JSON fields before queue volume grows.
- [ ] Add operator UI for quarantine reason and safe reset action.
- [ ] Decide whether to review/unpublish historical low/reject posts from the incident window.

### 2026-04-28 Live Chain/API Update

- [x] AI/API checked through plugin preflight: primary provider responded (`ok=true`); no current evidence of API outage.
- [x] Controlled collect run `25126`: `56/56` sources processed, `2` items collected, `0` errors.
- [x] Orchestrator picked up `1081` automatically after collect: `25127` rebuild, `25128` publish finish, then canonical gate correctly rejected it by `selection decision "reject"` instead of technical loop.
- [x] Orchestrator then picked up `1082`; `25129` finished rebuild with `error_count=0`, `25130` finished publish finish with `error_count=0`, then canonical gate rejected it by `selection decision "low"` without loop.
- [x] Fixed canonical media-gate mismatch that caused misleading `publish_ready_gate` quarantine loops.
- [x] Fixed ready-publish scheduling after daily time-sliced budget exhaustion to use the next same-day budget slot before daily reset.
- [x] Restored only safe rows `1075` and `1079` to `ready_publish`; kept `1073`, `1074`, `1078` quarantined because media contract still blocks.
- [x] Let `1082` finish from `finalize_media`; final outcome was clear gate reject, active owner cleared, `has_processable_items=false`.
- [ ] Let `1075` and `1079` publish at their scheduled slots: `09:22` and `09:27 UTC` (`11:22` and `11:27 Berlin`).
- [ ] Re-check acceptance after those publications; current `low_reject_published_24h=20` is still historical incident-window noise.
