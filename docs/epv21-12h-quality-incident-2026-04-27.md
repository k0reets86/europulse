# EPV2.1 12h Quality Incident - 2026-04-27

## Window

- Analysis time: `2026-04-27 19:08-19:15 UTC`.
- Source: live WordPress DB, `ep_epv2_queue`, `ep_epv2_runs`, systemd status for `epv2-worker` and `epv2-orchestrator`.

## Findings

- The site and services stayed up during analysis:
  - `curl -I http://127.0.0.1/` returned WordPress `301`, no `500/502`.
  - `epv2-worker.service` active, memory about `176 MB`.
  - `epv2-orchestrator.service` active, memory about `17 MB`.
- Last 12h queue results before fixes:
  - `published=37`, `new=4`, `ready_publish=1`, `rejected=2`.
  - Rejected rows were only `1021` and `1028`, but many bad candidates were published instead of being stopped.
- Published quality defect:
  - Published rows grouped by `ai_payload._meta.selection.decision`: `reject=12`, `low=8`, `review=8`, `strong=1`.
  - At least `20` published rows in the 12h window had `decision in (low, reject)`.
  - Examples: `1048` White House dinner shooting (`reject`), `1047` US teacher pay (`low`), `1044` broken Ukraine/business title (`reject`), `1050` biotech PR (`low`).
- Stuck/retry patterns:
  - `1036`: `queued_rebuild_bundle_stage=14`, `ai_provider_failure_retry=9`, repeated from `14:01` to `19:01 UTC`.
  - `1042`: repeated `rebuild_bundle`/AI-provider failure loop.
  - `1022`: `forced_publish_finish_continuation=13`, repeated publish-finish no-progress loop.
  - `1029`: stale `publish_ready_gate`/publish-finish recovery with retry timestamp already in the past.
  - `1020`: present in process run logs but missing from queue, consistent with a manually deleted stuck item.
- Root cause for bad publications:
  - Normal `publish_ready_gate_passes()` blocked `low/reject`.
  - `fast_transition_item_to_ready_publish()` trusted cached terminal-ready checklist and did not check `selection decision`.
  - `next_due_item_for_publish_fast()` selected due ready rows directly and returned them without `item_is_publishable_read_only()`.
  - Acceptance health did not include selection publishability, so bad publications were counted as green autonomous passes.

## Fixes Applied Live

- Backups created:
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php.bak-20260427-1911`
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21/includes/queue/class-epv2-queue.php.bak-20260427-1911`
- Changed `class-epv2-ai-processor.php`:
  - `payload_has_cached_terminal_ready_contract()` now rejects payloads blocked by selection, context reject, or stale context.
- Changed `class-epv2-queue.php`:
  - Added strict final selection guard for publish stage.
  - `sanitize_low_grade_ready_publish_items()` no longer skips technically ready payloads with blocking selection.
  - `next_due_item_for_publish_fast()` now calls `item_is_publishable_read_only()` before returning a row.
  - `mark_state(..., ready_publish)` no longer treats ready-like `low` as publish-allowed.
  - Acceptance snapshot now includes `selection_publishable` and fails rows where it is false.

## Verification

- PHP syntax:
  - Live `class-epv2-ai-processor.php`: no syntax errors.
  - Live `class-epv2-queue.php`: no syntax errors.
- Site health:
  - `curl -I http://127.0.0.1/` returned WordPress `301`, no `500/502`.
- Publish selector:
  - `EPV2_Queue::next_due_item_for_publish_fast(false)` returned `none` after fixes.
- Health after acceptance fix:
  - `next_ready_publish=0`.
  - `queue_contract.status=ok`.
  - `acceptance.status=not_proven`.
  - Latest bad publish `1050` now appears with `selection_publishable=false`, `passes=false`.

## Residual Work

- Decide whether to unpublish or review already published low/reject posts from the incident window, especially post IDs `6486`, `6472`, `6465`, `6453`, `6444`, `6437`, `6423`, `6416`, `6403`, `6390`, `6383`, `6370`, `6361`, `6352`, `6340`, `6334`, `6316`, `6309`, `6303`.
- Add a terminal/quarantine policy for repeated no-progress loops:
  - `1022` publish-finish continuation.
  - `1029` stale publish-ready gate.
  - `1036` and `1042` repeated AI-provider/rebuild loops.
- Tighten source/category fit before generation:
  - reject or manual-review US-local stories, weak PR/biotech items, generic product tests, and broken/odd source titles.
  - prevent category remapping like `world -> deutschland` or `ukraine -> politik` without explicit editorial reason.
- Extend health to expose:
  - count of recent `low/reject` publications;
  - repeated-run loop counters by item;
  - stale retry timestamps.
