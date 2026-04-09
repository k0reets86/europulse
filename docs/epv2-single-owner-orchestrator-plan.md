# EPV2 Single-Owner Orchestrator Plan

## Goal

Replace the current mixed queue-orchestration model with a deterministic single-owner workflow engine that guarantees:

`new -> active -> ready_publish -> published`

for the user-facing model.

Internal workflow progress must remain resumable, but it must no longer fragment the queue into visible pseudo-states like `retry_process`, `ready_review`, `publish_finish`, `translate_*`.

## Non-Negotiable Rules

1. Only one non-publish item may be active at a time.
2. A claimed item keeps ownership of the single processing slot until one of these outcomes:
   - `ready_publish`
   - `published`
   - `rejected`
   - `error_terminal`
3. A partially processed item must never be returned to the general user-facing `new` pool as a separate logical class.
4. Internal step retries must stay inside the active owner contract.
5. Cron only wakes the engine. Cron does not decide workflow state transitions.
6. Every step must be idempotent and resumable from persisted state.

## Root Cause Summary

The current system mixes:
- user-facing queue states
- internal workflow steps
- retry scheduling
- ownership of the active slot
- recovery/cleanup rules

That allows the same item to re-enter the scheduler as if it were a fresh candidate, which causes repeated loops and starvation.

## Target Model

### User-Facing States

- `new`
- `active`
- `ready_publish`
- `published`
- `rejected`
- `error`

### Internal Workflow Fields

Add or derive the following internal orchestration contract:

- `workflow_owner_token`
- `workflow_claimed_at`
- `workflow_heartbeat_at`
- `workflow_step`
- `workflow_step_status`
- `workflow_step_attempts`
- `workflow_not_before`
- `workflow_last_error`
- `workflow_terminal_reason`
- `workflow_version`

These can live in `admin_notes._system` first to reduce schema risk.

## Canonical Step Machine

One active item moves through these steps only:

1. `build_de_master`
2. `translate_uk`
3. `translate_en`
4. `finalize_media`
5. `finalize_seo`
6. `publish_ready_gate`

Optional bounded side-steps:

- `refresh_context`
- `repair_translation`
- `repair_media`

Each step must end in exactly one of:

- `done`
- `retry_same_step_after_delay`
- `escalate_to_next_internal_repair_step`
- `terminal_reject`
- `terminal_error`

No step may emit a queue-level pseudo-state like `retry_process`.

## Ownership Contract

### Claim

The process runner claims one item by setting:

- user-facing `state = active`
- `workflow_owner_token`
- `workflow_claimed_at`
- `workflow_heartbeat_at`

Only one item may hold a live owner token at a time.

### Hold

While the item is active:

- scheduler must always resume the same active item
- no new item may be selected
- cleanup may recover stale ownership only if heartbeat expired

### Release

Ownership is released only on:

- `ready_publish`
- `published`
- `rejected`
- `error_terminal`

Not on:

- retry delay
- partial translation
- media repair
- quality repair
- publish-finish resume

## Scheduler Redesign

### Current Problem

`next_item_for_processing()` still reasons over many pseudo-buckets and priorities.

### Replacement

Implement:

1. `get_active_owned_item()`
2. `claim_next_new_item()`
3. `resume_or_claim_item()`

Rules:

- if active owner exists and is not stale: always return it
- else claim oldest eligible `new`
- do not scan `retry_process` or `ready_review` as separate user-facing pools

## Persistence Strategy

### Phase 1

Store new orchestration fields inside `admin_notes._system`.

Reason:
- minimal migration risk
- easy rollback
- avoids immediate schema-lock work on live table

### Phase 2

If stable, optionally promote orchestrator fields into explicit columns for performance.

## Migration Plan

### Phase A. Freeze Legacy Semantics

1. Stop adding new logic to `retry_process/ready_review` semantics.
2. Treat them as legacy internal remnants only.
3. Stop exposing them in UI as separate workflow classes.

### Phase B. Introduce New Owner Contract

1. Add helper methods:
   - `active_owner_get()`
   - `active_owner_claim()`
   - `active_owner_heartbeat()`
   - `active_owner_release()`
   - `active_owner_is_stale()`

2. Update process runner:
   - on wake: resume active owner first
   - if none: claim one `new`

### Phase C. Build Step Engine

1. Replace ad hoc stage routing with:
   - `run_active_workflow_step(item)`
2. Step dispatch based on `workflow_step`
3. Keep step attempts per step only
4. Remove queue-level backslides for partial progress

### Phase D. Legacy Queue Migration

For every non-terminal row:

1. Map legacy visible states to new user-facing state:
   - `processing_de`, `retry_process`, `ready_review`, `reserve` -> `new` or `active`
   - `ready_publish`, `retry_publish`, `publishing` -> `ready_publish`

2. Map legacy payload stage to new `workflow_step`
3. Recompute ownership:
   - at most one row may become `active`
   - all others revert to `new`

4. Preserve:
   - payload
   - diagnostics
   - step attempts
   - retry_after as `workflow_not_before`

### Phase E. Remove Legacy Selector Paths

Delete or bypass:

- stage-priority fairness heuristics
- lane monopoly heuristics
- auto-ready-review sinks as scheduler semantics
- pseudo-bucket selection between `new/retry_process/ready_review`

## Media Contract Redesign

Media must become a bounded internal step, not a queue class.

Rules:

1. Generated covers are not publish-grade.
2. Source-host editorial images are preferred and accepted if valid.
3. Supporting-source images are second priority.
4. If no valid real image is found:
   - stay on active owner
   - bounded internal retries only
   - then terminal machine decision

There must be no:

- `publish_finish -> retry_process -> publish_finish`

## Translation Contract Redesign

Translation steps must be strictly sequential and bounded:

1. `translate_uk`
2. `translate_en`

No item may re-open `build_de_master` after both translations are complete unless a specific hard invalidation rule is triggered.

## Publish-Ready Gate

`ready_publish` must be awarded only by a single gate function.

That gate must validate:

- DE present
- UK present
- EN present
- SEO meta complete
- publishable featured media exists
- no shell content
- taxonomy/category resolved

If gate fails, the engine must return a next internal repair step, not a queue pseudo-state.

## Recovery Rules

Recovery must only do these:

1. detect stale owner heartbeat
2. release stale owner
3. reassign released item back to `new` with preserved `workflow_step`

Recovery must not:

- create hidden new workflow branches
- mutate user-facing state based on guesswork
- promote/demote rows across multiple pseudo-queues

## UI Plan

User-facing queue sections:

1. `В работе`
   - exactly one item or empty
2. `Новые`
   - all not-yet-published items not currently active and not ready to publish
3. `Готово к публикации`
4. `Опубликованные`

No separate user-facing sections for:

- retry
- review
- auto-repair
- publish-finish
- translate

## Testing Plan

### Unit-Level

Add regression coverage for:

1. only one active owner at a time
2. active owner survives step retries
3. no fresh `new` claim while active owner exists
4. step retries do not change user-facing queue class
5. stale owner recovery returns exactly one item to `new`

### Integration-Level

Create a deterministic orchestrator harness that asserts:

1. collect inserts multiple `new`
2. process claims exactly one as `active`
3. repeated process runs keep same `active` until `ready_publish`
4. only after release may next `new` become `active`

### Live Acceptance

Need a series, not one case:

1. collect 3+ new items
2. confirm exactly one `active`
3. confirm the same item remains active through all internal steps
4. confirm it reaches `ready_publish`
5. confirm second item becomes active only after first release
6. confirm at least 3 consecutive items reach `ready_publish`
7. confirm publication moves them to `published`

## Rollout Strategy

### Step 1

Create new orchestrator code paths behind a feature flag:

- `epv2_orchestrator_v2_enabled`

### Step 2

Run migration in dry-run mode and emit report only.

### Step 3

Enable v2 on live while old queue data is preserved.

### Step 4

Block old scheduler paths when v2 is enabled.

### Step 5

Observe series acceptance.

## Rollback Strategy

Rollback means:

1. disable `epv2_orchestrator_v2_enabled`
2. restore plugin from backup archive
3. restore queue rows from `ep_epv2_queue.data.tsv` if needed
4. restore options snapshot if ownership metadata was corrupted

## First Implementation Slice

The first rebuild slice should be:

1. introduce v2 owner helpers
2. introduce `active/new/ready_publish/published` UI contract
3. claim/resume only one owner
4. map legacy payload stages to internal `workflow_step`
5. keep old business generation code temporarily
6. stop selecting from pseudo-buckets

This gives immediate structural stability before deeper content/media improvements.
