# EPV2.1 Systemic Stabilization Plan - 2026-04-27

## Plan Review

The previous 12-block plan is directionally correct, but it is too broad for the current failure mode. The live system is not failing because one UI block or one publish timer is wrong. It is failing because workflow state, retry policy, editorial selection, and publish scheduling are coupled inside the same queue row and several code paths can move an item forward.

Current evidence:

- Current queue: `published=38`, `new=4`, `rejected=2`.
- Current health: `has_processable_items=false`, `next_ready_publish=0`, `queue_contract.status=ok`, `acceptance.status=not_proven`.
- Current stuck candidates:
  - `1022`: `publish_finish`, `workflow_step_attempts=21`.
  - `1029`: `publish_ready_gate`, stale `retry_after=2026-04-27 15:10:51`, `decision=low`.
  - `1036`: `rebuild_bundle`, `workflow_step_attempts=22`, repeated AI-provider failures.
  - `1042`: `rebuild_bundle`, `workflow_step_attempts=16`, repeated AI-provider failures.
- Last 24h loop signatures:
  - old `942`: `terminal_ready_payload_short_circuit=64`.
  - `1036/1042`: rebuild/provider retry loops.
  - `1022/1029`: publish-finish no-progress loops.
  - previous incident: `20` low/reject rows published in 12h before final publish guard was patched.

Conclusion: the implementation must prioritize transition determinism, terminal retry policy, provider circuit breaker, early source-quality gating, and observability. UI and cosmetic admin work stay secondary unless needed to operate the queue safely.

## Hard Invariants

- A row must not advance to `ready_publish` unless the same canonical publish gate passes.
- A row must not publish unless the same canonical publish gate still passes at publish time.
- Any retryable stage must have a bounded attempt budget and a terminal outcome.
- `low` and `reject` selection decisions are never auto-publishable unless an explicit manual/editorial override exists.
- Provider outages must stop the affected stage globally for a cooldown window, not spin multiple items.
- Selection/read-only health paths must never mutate queue state.
- Publish scheduling must only manage slots; it must not repair, rebuild, enrich, or translate.

## Implementation Blocks

### Block A. Safety Baseline

- Backup live plugin and DB before code-changing blocks.
- Record current queue/run snapshot.
- Keep `epv2-orchestrator`, `epv2-worker`, nginx, php-fpm health checks in every block.
- Keep collection enabled only if no destructive migration runs.
- Do not mass-unpublish previous bad posts without explicit approval.

Acceptance:

- Site returns no `500/502`.
- Live PHP lint passes for touched files.
- Current queue snapshot is recorded.

### Block B. Canonical Gate Extraction

- Extract one machine-readable gate result, e.g. `EPV2_Publish_Gate::evaluate($item, $payload)`.
- Return structured fields: `allowed`, `blockers`, `selection_publishable`, `quality_publishable`, `media_publishable`, `schedule_publishable`, `manual_override`.
- Replace duplicated checks in:
  - `transition_item_to_ready_publish()`;
  - `fast_transition_item_to_ready_publish()`;
  - `mark_state(... ready_publish ...)`;
  - `next_due_item_for_publish_fast()`;
  - `item_is_publishable_read_only()`;
  - acceptance snapshot.

Acceptance:

- `decision=low/reject` cannot enter `ready_publish`.
- Existing `ready_publish` low/reject cannot be selected for publish.
- Acceptance fails when `selection_publishable=false`.

### Block C. Terminal Retry And Quarantine Policy

- Add a per-stage retry policy map:
  - `build_de_master`: max 3 attempts, then `quarantine`.
  - `rebuild_bundle`: max 4 attempts, then `quarantine`.
  - `publish_finish`: max 3 attempts, then `quarantine` or `manual_review` depending on blocker.
  - `publish_ready_gate`: max 2 attempts, then `quarantine`.
  - `translate_uk/en`: max 3 attempts, then repair/rebuild once, then `quarantine`.
- Add terminal fields:
  - `_system.workflow_terminal_reason`;
  - `_system.quarantine_reason`;
  - `_system.last_stage_blocker`;
  - `_system.next_operator_action`.
- Migrate current loop rows:
  - `1022`, `1029`, `1036`, `1042` should stop looping and receive explicit terminal/quarantine reasons.

Acceptance:

- No item can exceed stage attempt limit and remain ordinary `new`.
- Hot loop query over 24h returns no item/stage with excessive repeats.

### Block D. Provider Circuit Breaker

- Track provider failures separately from item failures.
- Add provider state: `provider`, `stage`, `failure_count`, `cooldown_until`, `last_error_class`.
- If all providers fail for a stage, mark the stage/provider circuit open and do not start more rebuild jobs until cooldown expires.
- Surface provider cooldown in health/admin.

Acceptance:

- Repeated AI outage does not create rebuild loops across multiple items.
- Health shows provider blocked state and next retry time.

### Block E. Source And Editorial Quality Gate Before AI

- Add pre-AI hard filters:
  - non-Europe/non-Germany/non-Ukraine US-local stories;
  - weak PR/biotech/company release unless explicitly important to Europe/Germany;
  - generic product/service tests;
  - event pages without news delta;
  - broken/garbled titles;
  - category mismatch without editorial reason.
- Store `selection_decision`, `selection_score`, `source_fit`, `geo_fit`, `reader_value` as columns or indexed metadata.
- Stop low-quality material before expensive worker stages.

Acceptance:

- Low/reject candidates do not enter worker rebuild by default.
- New collect batch produces fewer but stronger candidates.

### Block F. Publish Lane Simplification

- Keep publish path limited to:
  - select due `ready_publish`;
  - run canonical publish gate;
  - publish;
  - re-anchor next slot.
- Remove repair/rebuild behavior from publish lane.
- Add explicit blocker diagnostics when a `ready_publish` row is skipped.

Acceptance:

- Publish selector is deterministic and side-effect-light.
- A blocked `ready_publish` row is demoted/quarantined with reason, not silently skipped forever.

### Block G. DB Load And Query Shape

- Add or verify indexes/generated columns for:
  - `state`;
  - `updated_at`;
  - `workflow_step`;
  - `workflow_step_status`;
  - `retry_at`;
  - `publish_not_before`;
  - `selection_decision`.
- Remove `JSON_EXTRACT` from hot selectors where possible.
- Keep payload decode out of dashboard list views and bridge idle paths.

Acceptance:

- Health and selector calls do not decode large payloads for normal idle checks.
- Queue selectors remain fast under 1000+ rows.

### Block H. Observability And Incident Detection

- Add health counters:
  - low/reject published in last 24h;
  - stage loops above threshold;
  - stale retry timestamps;
  - provider circuit state;
  - ready_publish blockers;
  - average collect-to-publish time.
- Add daily operator command/report.
- Make acceptance status fail on editorial or loop defects, not only technical publication failure.

Acceptance:

- A future `1050`-type event shows red before or at publish gate, not after user notices.

### Block I. Replay Regression Tests

- Build fixtures from real bad cases:
  - `1022` publish-finish loop;
  - `1029` stale publish-ready low decision;
  - `1036/1042` provider/rebuild loop;
  - `1050` low decision published;
  - old `942` short-circuit loop.
- Add CLI replay harness that asserts final state and no publish bypass.

Acceptance:

- All known incident cases are reproducible and blocked by tests.

### Block J. Live Proof

- Clear or quarantine current loop rows.
- Run controlled collection.
- Prove 10 consecutive items through:
  - `new -> active -> ready_publish -> published`;
  - no manual push;
  - no low/reject publish;
  - no repeated stage loops;
  - source/category/media fit is acceptable;
  - no site `500/502`.

Acceptance:

- `acceptance.status=accepted`.
- `consecutive_autonomous_publish_grade >= 10`.
- `low/reject published last 24h = 0` after fix window.
- `stage loop violations = 0`.

## Cleanup Policy

Safe local cleanup already done:

- removed `.playwright-cli`;
- removed `worker-v21/__pycache__`.

Do not delete the following without explicit approval:

- live plugin backups;
- DB backups;
- old `worker/` deleted paths shown by git;
- untracked docs/ops/output artifacts;
- published WordPress posts from the incident window.

## References

- WordPress Cron is not a constantly running system cron; it runs when triggered by page load or external calls. This is why the external orchestrator must be the reliable runtime boundary: https://developer.wordpress.org/plugins/cron/
- WordPress scheduled events should avoid duplicate scheduling with `wp_next_scheduled()`: https://developer.wordpress.org/plugins/cron/scheduling-wp-cron-events/
- Action Scheduler is the best WordPress-native reference for traceable queues, claims, failed status, logs, batch limits, and stale-claim cleanup: https://actionscheduler.org/
- Action Scheduler scale notes define practical memory/time limits and continuation behavior for background queues: https://actionscheduler.org/perf/
- Action Scheduler admin docs show the operational model we should emulate for failed/in-progress actions and per-action logs: https://actionscheduler.org/admin/

## Implementation Log

### 2026-04-27 19:31-19:45 UTC

- Block A completed:
  - live plugin backup: `backups/epv21-live-plugin-pre-systemic-20260427-193120.tar.gz`;
  - DB backup: `backups/epv21-db-pre-systemic-20260427-193120.sql`;
  - baseline site check: WordPress `301`, no `500/502`;
  - nginx, php-fpm, worker, orchestrator active.
- Block B implemented:
  - added `EPV2_Publish_Gate` in `includes/publish/class-epv2-publish-gate.php`;
  - added autoload mapping in `includes/bootstrap.php`;
  - wired canonical gate into `transition_item_to_ready_publish()`, cached fast-ready path, `mark_state(... ready_publish ...)`, publish selector, and acceptance snapshot;
  - acceptance now exposes `publish_gate_allowed` and `publish_gate_blockers`.
- Block C implemented:
  - added `EPV2_Queue::quarantine_pathological_workflow_loops()`;
  - bridge maintenance and resilience cleanup now call quarantine pass;
  - current stuck rows were terminalized:
    - `1022 -> error`, `workflow_quarantine`, `stage_attempt_limit_publish_finish`;
    - `1029 -> rejected`, `selection_publish_blocked`, `selection_low`;
    - `1036 -> error`, `workflow_quarantine`, `stage_attempt_limit_build_de_master`;
    - `1042 -> error`, `workflow_quarantine`, `stage_attempt_limit_rebuild_bundle`.
- Block D partially implemented:
  - added workflow-stage circuit option `epv2_workflow_stage_circuit`;
  - worker stage execution now refuses to start while stage circuit is open;
  - `All AI providers failed` registers stage-level failure/cooldown;
  - health exposes `workflow_stage_circuit`.
- Block E first pass implemented:
  - collector now hard-blocks obvious pre-AI low-value classes: US-local without core geo relevance, biotech/clinical PR without core geo relevance, generic product tests, event/opening-hours pages without news delta, and garbled mixed-script business titles.
- Block H first pass implemented:
  - health exposes `incident_counters.low_reject_published_24h`, `terminal_quarantine_rows`, and `stale_retry_rows`.
- Block I first regression added:
  - `scripts/epv2_systemic_stabilization_check.php`;
  - verified via `/tmp` WP-CLI run because `www-data` cannot read `/root/projects` directly.
- Verification:
  - PHP lint passed for all touched plugin files;
  - controlled collect run `25052` finished with `0` new items and `0` errors after hard filters;
  - regression check result: `ok=true`, `publish_selector=none`, checked `[1022,1029,1036,1042,1050]`;
  - health: `has_processable_items=false`, `next_ready_publish=0`, `queue_contract.status=ok`, `stale_retry_rows=0`.
- Follow-up fix:
  - stopped maintenance ping-pong where `reactivate_planner_selected_soft_rejected_items()` reactivated a soft rejected row and `sanitize_non_publish_grade_new_items()` immediately rejected it again;
  - in auto publish-grade mode, soft rejected rows are no longer automatically reactivated;
  - manual maintenance check returned `quarantine.changed=0`, `reactivated=0`, `rejected_new=0`.

### Remaining Next

- Run the next natural collect window and monitor whether new candidates appear.
- Prove a fresh `new -> active -> ready_publish -> published` series after enough quality candidates exist.
- Add DB generated/indexed columns for hot JSON fields before queue volume grows.
- Add richer operator UI for quarantine reasons and reset actions.
- Decide separately whether to review/unpublish historical low/reject published posts from the incident window.
