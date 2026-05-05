# EPV21 Canonical Execution Plan 2026-04-09

## Purpose

This document is the canonical execution plan for the next stabilization phase of EuroPulse.

It replaces ambiguous planning language with a strict engineering contract.

This plan must be read as operational truth for implementation sequencing.

## Cost Constraint

All newly introduced production dependencies, infrastructure components, and runtime services must be free to use.

Allowed classes:

- open-source software
- self-hosted services on the existing server
- standard operating system packages
- free local libraries bundled into the repository or installed on the server

Forbidden classes unless explicitly re-approved later:

- paid SaaS
- paid queues, brokers, or observability vendors
- paid managed AI middleware
- hosted ingestion services
- hosted clustering services
- hosted automation/orchestration platforms

The operating assumption is:

- the user pays only for the server
- the user may later pay for the domain
- no other recurring vendor cost is allowed

If two technical options are similar, the simpler free self-hosted option is mandatory.

## Final Architecture

### WordPress Responsibilities

WordPress is allowed to do only the following:

- queue storage
- admin dashboard
- review UI
- manual mode UI
- source configuration
- settings persistence
- media sideload and library persistence
- post creation and update
- SEO integration
- Polylang integration
- authenticated REST bridge
- run and log inspection

WordPress is not allowed to be the primary runtime orchestration engine.

### External Orchestrator Responsibilities

The external orchestrator is the only canonical runtime control plane.

It must do:

- inspect bridge state
- respect pause flags
- run maintenance
- decide when to collect
- decide when to process
- decide when to publish
- recover stale ownership and stale runs
- apply bounded retries and backoff
- record runtime logs

### Worker Responsibilities

The worker is a heavy-stage executor only.

It must do:

- execute one heavy stage or one normalized bundle stage
- return normalized payloads and outcomes
- avoid direct WordPress publishing ownership

## Approved Technology Direction

The following additions are approved as candidate implementation directions because they fit the free/self-hosted constraint and improve the target architecture:

- `newspaper4k` as a primary worker-side article extraction library
- `news-please` as a fallback extractor and bulk-ingestion/backfill tool
- source-level circuit breaker and cooldown logic for failing feeds
- multilingual embedding-based clustering using free local models
- a self-hosted queue truth in MariaDB during the stabilization phase

The following are explicitly not near-term mandatory:

- RabbitMQ
- Apache Flink
- any paid ingestion, queue, clustering, or orchestration product

RabbitMQ or another broker may be reconsidered later only if the current deterministic orchestrator plus database-backed queue becomes a proven scaling bottleneck.

## Non-Negotiable Rules

1. Only one non-publish item may own the processing lane at a time.
2. User-facing queue state must not encode internal workflow steps.
3. A technically recoverable item must not be moved to ordinary `rejected`.
4. `ready_publish` must be assigned by one gate only.
5. Source-first media is mandatory in normal operation.
6. Heavy orchestration must not live inside WordPress request lifecycle.
7. Every automation action must be traceable by `queue_id` and `run_id`.
8. No new competing orchestration mode may be introduced.

## Canonical User-Facing Queue States

The only allowed user-facing queue states are:

- `new`
- `active`
- `ready_publish`
- `published`
- `rejected`
- `error`

Allowed terminal or special machine states:

- `duplicate`
- `publishing`

The following values are forbidden as user-facing queue states:

- `processing_de`
- `retry_process`
- `ready_review`
- `reserve`
- `publish_finish`
- `translate_uk`
- `translate_en`
- any other stage-like or retry-like pseudo-state

## Canonical Internal Workflow Contract

Internal workflow truth must live in `admin_notes._system` until an explicit schema migration replaces it.

Required fields:

- `workflow_version`
- `workflow_owner_token`
- `workflow_claimed_at`
- `workflow_heartbeat_at`
- `workflow_step`
- `workflow_step_status`
- `workflow_step_attempts`
- `workflow_not_before`
- `workflow_last_error`
- `workflow_terminal_reason`

## Canonical Step Machine

The only allowed primary steps are:

1. `build_de_master`
2. `translate_uk`
3. `translate_en`
4. `finalize_media`
5. `finalize_seo`
6. `publish_ready_gate`

Allowed bounded side-steps:

- `refresh_context`
- `repair_translation`
- `repair_media`

Every step must end with exactly one outcome:

- `done`
- `retry_same_step_after_delay`
- `escalate_to_internal_repair_step`
- `terminal_reject`
- `terminal_error`

No step may emit a queue-level pseudo-state.

## Canonical Ownership Contract

### Claim

To claim a processing item, the engine must:

- set user-facing `state = active`
- assign `workflow_owner_token`
- assign `workflow_claimed_at`
- assign `workflow_heartbeat_at`

### Hold

While an item is active:

- the scheduler must always resume the same item
- no other `new` item may be selected
- retries must remain inside owner contract
- internal step delays must not release ownership into public queue semantics

### Release

Ownership may be released only on:

- `ready_publish`
- `published`
- `rejected`
- `error_terminal`

Ownership must not be released on:

- retry delay
- partial translation completion
- media repair
- SEO repair
- publish-finish style resume

## Canonical Selector Contract

The scheduler may use only these semantic operations:

1. `get_active_owned_item()`
2. `claim_next_new_item()`
3. `resume_or_claim_item()`

Selector rules:

- if a live active owner exists, return it
- else claim the oldest eligible `new`
- else return `null`

The selector must not scan pseudo-buckets such as:

- `retry_process`
- `ready_review`
- `reserve`
- stage-priority lanes
- fairness heuristics across legacy classes

## Canonical Publish-Ready Gate

`ready_publish` must be assigned by one gate function only.

That gate must validate all of the following:

- DE content exists and is publishable
- UK content exists and is publishable
- EN content exists and is publishable
- SEO metadata is complete
- featured media is publishable
- taxonomy resolution is complete
- no shell or placeholder content remains
- semantic validation passes
- normalized payload is internally consistent

If the gate fails, it must return an internal repair decision or a terminal decision.

It must not mutate public queue semantics into pseudo-states.

## Canonical Media Contract

Media is an internal bounded step.

Priority order is fixed:

1. primary source image
2. supporting-source image
3. external fallback image

Generated covers are not publish-grade media.

No queue-level media blocker pseudo-state may be introduced.

## Canonical Translation Contract

Translation is strictly sequential:

1. `translate_uk`
2. `translate_en`

Completed translations must not reopen DE generation unless a hard invalidation rule explicitly demands it.

Translation retries must remain bounded and internal to the owner contract.

## Implementation Order

The following sequence is mandatory.

No workstream may be skipped ahead of an earlier unfinished workstream unless the earlier workstream is explicitly blocked by external infrastructure.

1. Freeze architecture and declare orchestrator canonical
2. Normalize queue semantics
3. Implement single-owner selector
4. Build canonical step runner
5. Centralize publish-ready gate
6. Separate editorial/manual from technical recovery
7. Complete worker externalization
8. Normalize maintenance and recovery
9. Finalize media contract
10. Finalize translation contract
11. Finalize observability
12. Run live acceptance proof

## Explicitly Forbidden

The following actions are forbidden:

- adding new user-facing queue states
- adding new business logic to cron-first orchestration branches
- maintaining more than one production selector model
- storing workflow truth simultaneously in conflicting fields without hierarchy
- assigning `ready_publish` from multiple code paths
- treating recoverable technical failures as ordinary editorial reject
- keeping PHP and worker as equal long-term implementations of the same heavy workflow
- manually rescuing items without encoding the rescue rule into code

## Acceptance Criteria

This phase is complete only when all conditions below are true at the same time:

- one canonical production runtime mode exists
- user-facing queue semantics are simple and deterministic
- one active owner model controls the processing lane
- internal workflow is resumable and step-driven
- WordPress acts as a thin publishing bridge
- the worker owns heavy-stage execution
- the orchestrator is the only runtime control plane
- media and translation contracts are explicit and bounded
- recovery rules are formalized and observable
- live automation proves `new -> active -> ready_publish -> published` repeatedly without manual rescue

## Status Source Of Truth

The execution status of this plan must not be tracked in this file.

Execution status must be tracked in:

- `docs/epv21-execution-todo-2026-04-09.md`

This file defines what must be built.
The TODO file defines what has and has not been completed.
