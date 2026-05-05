# EPV21 Execution TODO 2026-04-09

## Usage Rules

- This file is the execution status source of truth for the current stabilization phase.
- Every completed item must be checked off explicitly.
- Do not mark an item complete if the code path exists but the runtime contract is not proven.
- Do not mark an item complete based on one-off manual rescue.
- If a task is partially implemented, leave it unchecked and add a short note under `Notes`.
- All newly added implementation dependencies must be free, open-source, and self-hosted or locally installed.
- Do not add paid SaaS, paid message brokers, paid observability, or paid automation platforms.

## Status Vocabulary

- `[ ]` not complete
- `[x]` complete and verified

## Workstream 1. Freeze Architecture

- [x] Declare server orchestrator the only canonical production runtime mode
- [x] Demote WP-Cron orchestration to compatibility or fallback only
- [ ] Stop adding new business logic to cron-first orchestration branches
- [x] Harden bridge and worker runtime secrets on the live server
- [x] Disable public `xmlrpc.php` access on the live server
- [x] Remove cache interference from bridge REST control endpoints

## Workstream 2. Normalize Queue Semantics

- [ ] Enforce the canonical user-facing state set:
- [ ] `new`
- [ ] `active`
- [ ] `ready_publish`
- [ ] `published`
- [ ] `rejected`
- [ ] `error`
- [x] Stop exposing legacy pseudo-states in queue UI and queue filters
- [x] Remove legacy pseudo-state dependence from scheduling logic
- [x] Complete legacy-to-canonical state migration for all non-terminal rows

## Workstream 3. Single-Owner Selector

- [x] Make active-owner contract the primary source of processing ownership
- [x] Keep at most one live active owner at a time
- [x] Ensure selector always resumes active owner first
- [x] Ensure selector claims oldest eligible `new` when no active owner exists
- [x] Remove legacy bucket selection logic from hot path
- [x] Stop idle orchestrator loop from triggering empty process passes

## Workstream 4. Canonical Step Runner

- [x] Introduce one canonical `run_active_workflow_step(item)` dispatcher
- [x] Drive workflow strictly by `workflow_step`
- [x] Persist step attempts per-step
- [x] Persist `workflow_not_before` for delayed retries
- [x] Remove queue-state-driven step routing from primary process path

## Workstream 5. Publish-Ready Gate

- [x] Create one canonical `publish_ready_gate`
- [x] Make it the only code path that can assign `ready_publish`
- [x] Validate DE/UK/EN completeness in the gate
- [x] Validate SEO completeness in the gate
- [x] Validate featured media publishability in the gate
- [x] Validate semantic and payload integrity in the gate

## Workstream 6. Manual Versus Technical Recovery

- [x] Separate editorial manual-confirmation semantics from technical retry semantics
- [x] Stop using ordinary `rejected` for recoverable technical problems
- [x] Formalize recoverable, manual, and terminal classifications

## Workstream 7. Worker Externalization

- [ ] Move heavy DE generation into worker-owned canonical stage execution
- [ ] Move translation into worker-owned canonical stage execution
- [ ] Move media finalization into worker-owned canonical stage execution where appropriate
- [ ] Move SEO finalization into worker-owned canonical stage execution where appropriate
- [ ] Keep WordPress focused on persistence, integration, and publishing only

## Workstream 8. Maintenance And Recovery

- [ ] Formalize stale owner recovery
- [ ] Formalize stale process lock recovery
- [ ] Formalize stale publish lock recovery
- [ ] Formalize zombie run recovery
- [ ] Ensure recovery preserves workflow step and diagnostics
- [x] Requeue orphaned `processing_de` live item into recoverable state on the live server
- [x] Replace heavyweight runtime watcher with a lightweight health snapshot script

## Workstream 9. Media Contract

- [ ] Enforce source-first media as default contract
- [ ] Keep generated covers non-publish-grade
- [ ] Bound media retries within internal workflow steps only
- [ ] Remove queue-level media blocker semantics

## Workstream 10. Translation Contract

- [ ] Enforce strict sequential translation:
- [ ] `translate_uk`
- [ ] `translate_en`
- [ ] Bound translation retries per-step
- [ ] Prevent completed translations from reopening DE build without explicit invalidation

## Workstream 11. Observability

- [ ] Standardize runtime logs on `queue_id`, `run_id`, owner token, step, outcome
- [ ] Ensure bridge state reflects live truth without cache distortion
- [ ] Add deterministic health checks for worker, orchestrator, bridge, and queue contract
- [x] Ensure bridge REST responses are no longer served from FastCGI cache on the live server

## Workstream 12. Live Acceptance Proof

- [ ] Prove repeated autonomous transition:
- [ ] `new -> active`
- [ ] `active -> ready_publish`
- [ ] `ready_publish -> published`
- [ ] Prove no empty process loops occur during normal idle periods
- [ ] Prove no item is lost to ordinary reject because of technical failure
- [ ] Prove published items have valid category, language, media, and SEO

## Workstream 13. Free-Only Ingestion And Clustering Enhancements

- [ ] Keep ingestion and clustering architecture free-only and self-hosted
- [ ] Evaluate `newspaper4k` as the primary worker-side extractor for article body, metadata, and lead image extraction
- [ ] Evaluate `news-please` as a fallback extractor and backfill ingestion tool
- [ ] Introduce source-level circuit breaker rules:
- [ ] three consecutive source failures trigger cooldown
- [ ] cooldown duration is explicit and configurable
- [ ] source health and cooldown state are visible in diagnostics
- [ ] Add multilingual event-clustering spike using a free local embeddings model
- [ ] Prefer multilingual embeddings over English-only defaults for DE/UK/EN clustering
- [ ] Cluster by normalized event signal, not title alone
- [ ] Keep clustering as a worker-side or auxiliary service concern, not a WordPress runtime concern
- [ ] Do not introduce RabbitMQ or Apache Flink before deterministic orchestrator limits are proven

## Workstream 14. Free-Only Delivery And Repo Hygiene

- [ ] Keep autonomous publish flow on self-hosted WordPress bridge until HTTPS and domain are ready
- [ ] After HTTPS is live, evaluate WordPress Application Passwords for external publish auth
- [ ] Add `.env.example` files where missing for worker and plugin-related runtime configuration
- [ ] Ensure each major project area keeps a current local `README.md` for local run and recovery steps
- [ ] Add free local lint and format automation where missing
- [ ] Add free CI checks for lint and tests when the repo workflow is ready

## Notes

- 2026-04-09: live server hardening completed for bridge and worker secrets.
- 2026-04-09: `xmlrpc.php` denied at nginx level.
- 2026-04-09: bridge control endpoints excluded from FastCGI cache.
- 2026-04-09: idle orchestrator loop was stopped by using `has_processable_items` in bridge state.
- 2026-04-09: orphan live queue item `749` moved out of `processing_de` into recoverable `retry_process`.
- 2026-04-09: runtime watcher simplified into lightweight service health snapshot.
- 2026-04-09: free-only constraint formalized. New work must avoid paid SaaS and prefer open-source self-hosted components.
- 2026-04-09: ingestion and clustering follow-up items added from external research, but RabbitMQ and Flink are intentionally deferred.
- 2026-04-09: canonical runtime mode hardened in code. New installs default to `server_orchestrator_enabled=1`, bridge reports `runtime_mode=server_orchestrator`, and EPV2 cron orchestration hooks are now compatibility-only no-ops when canonical mode is active.
- 2026-04-09: queue UI and bridge state no longer expose legacy pseudo-states as public states. User-facing filtering and summaries now collapse them into canonical `new`, `active`, `ready_publish`, `publishing`, `published`, `rejected`, `duplicate`, `error`.
- 2026-04-09: v2 scheduler hot path no longer chooses work from legacy pseudo-state buckets. Active owner resolution now follows `workflow_owner_token + canonical state`, and claim logic only takes the oldest eligible `new` item.
- 2026-04-09: live non-terminal backlog fully normalized. Raw queue states on the server are now `new:16`, `published:68`, `rejected:4`, and normalization now auto-collapses non-active legacy recoverable rows back to canonical `new`.
- 2026-04-09: canonical step-runner entrypoint added in `EPV2_AI_Processor`. Active processing now resolves a canonical workflow step first, maps it to pipeline execution, and persists `workflow_step_attempts` per active step.
- 2026-04-09: delayed retry windows now persist canonical `_system.workflow_not_before` and remain backward-compatible with legacy `_system.retry_after`. Queue selector and resilience retry checks now read the canonical window, and live verification confirmed `workflow_waiting_not_before=true` implies `retry_due=false`.
- 2026-04-09: primary `process_scheduled()` routing no longer gates stage resume on raw queue states like `retry_process` or `processing_de`. The main execution path now resumes from `workflow_step + payload pipeline stage`; legacy state writes still exist as compatibility markers but no longer drive stage selection.
- 2026-04-09: single-owner selector contract verified on live. V2 hot path now resolves only `resume active owner -> claim oldest eligible new -> none`, duplicate owner tokens are normalized away, and live checks showed `owner_count=0`, `active_option=0`, and deterministic preview mode `none`.
- 2026-04-09: Workstream 5 started. `AI_Processor` now routes direct `ready_publish` promotions through one helper instead of scattered `mark_state(..., 'ready_publish')` calls. The helper is still a first-pass wrapper around the existing publish-ready predicate; full canonical gate validation remains open.
- 2026-04-09: canonical `publish_ready_gate` is now explicit in code. It checks stage contract, multilingual completeness, SEO contract, featured media publishability, and payload integrity. Automatic runtime, queue normalization, recovery, and publish-promotion paths now use the shared transition helper. Remaining non-canonical `ready_publish` writes are limited to manual/editorial bypass paths and are intentionally not marked complete yet.
- 2026-04-09: Workstream 5 closed. Admin/review validation paths were also moved onto the shared `transition_item_to_ready_publish()` helper. In the plugin runtime, the only remaining direct `mark_state(..., 'ready_publish')` is the helper itself.
- 2026-04-09: Workstream 6 started. `Resilience_Manager` now routes internal `ready_review` transitions through an explicit manual-review helper that always sets `manual_confirmation_required` and `manual_confirmation_reason`. Repeated stale external-service failures no longer fall into ordinary `rejected`; they now stay in technical `error` with `workflow_terminal_reason=external_service_stale_error`.
- 2026-04-09: Workstream 6 closed. `EPV2_Queue::workflow_classification_for_row()` now formalizes `recoverable`, `manual`, and `terminal` classes with explicit reasons. Admin status labels and hints now consume that shared classification contract instead of inferring meaning ad hoc from raw states and messages.
- 2026-04-09: canonical runtime now also forces `orchestrator_v2_enabled=true` on live. Verified via WP-CLI with `worker_enabled=true`, `worker_available=true`, `orchestrator_v2=true`, and `server_orchestrator=true`.
- 2026-04-09: Workstream 7 started. Canonical mode now treats `rebuild_bundle`, translation stages, and `publish_finish` as worker-owned stages. When canonical worker mode is active, `AI_Processor` no longer silently falls back to local PHP execution for those heavy paths if the external worker is unavailable; it defers through retry/error handling instead. Workstream 7 remains unchecked until the contract is proven on a live processed item.
- 2026-04-09: Workstream 8 started. `Jobs::recover_orphan_process_lock()` no longer sends stale processing rows through raw `mark_state('retry_process')`; it now routes recovery through `Resilience_Manager::schedule_retry()` so canonical retry metadata, workflow step preservation, and diagnostics stay aligned with the main recovery contract. Workstream 8 remains unchecked until this path is observed on a real recovered item.
- 2026-04-09: Workstream 8 progressed further. `Jobs` now uses one helper to finish stale `started` runs for `process`, `collect`, and `publish`, and `EPV2_Runs::cleanup_abandoned_started()` now finishes zombie runs through `EPV2_Runs::finish()` with explicit `job_name` and `recovery_source=cleanup_abandoned_started` payload metadata instead of ad hoc row updates. This reduces drift between lock recovery and run recovery, but the workstream stays unchecked until a live recovered run exercises each path.
- 2026-04-09: stale owner recovery in `EPV2_Queue` now preserves workflow diagnostics on the row. When an active owner is recovered after a stale or missing heartbeat, the item keeps its current `workflow_step` and records `workflow_recovered_at`, `workflow_recovery_reason=stale_owner`, and a human-readable `workflow_last_error` instead of silently losing only the owner token.
- 2026-04-09: Workstream 9 started. `publish_ready_gate` now enforces source-first media more strictly: source-host media remains publish-grade, but generic stock fallback is accepted only when the payload has no source-dossier image candidates left. Generated covers remain non-publish-grade. This is deployed on live, but Workstream 9 stays unchecked until a live item proves the fallback behaviour cleanly.
- 2026-04-10: Workstream 9 progressed further. New media failures no longer use `publish_media` as the primary retry module. `AI_Processor` and `Publisher` now re-enter the canonical `process` path, and queue prioritization no longer boosts media cases by parsing `featured image` text from `retry_process` errors. Media retries remain backward-compatible in `Resilience_Manager`, but the runtime hot path is now closer to a pure `finalize_media` workflow-step model.
- 2026-04-10: Workstream 10 started. Translation routing now treats a complete `UK+EN` bundle as terminal for the translation phase unless the payload carries an explicit invalidation flag. After both translations are ready, `payload_next_required_stage*()` no longer reopens `rebuild_bundle` just because of generic quality or enrichment heuristics; it stays on `publish_finish` unless `_meta.translation_rebuild_invalidated`, `_meta.allow_translation_rebuild`, or `_meta.force_rebuild_after_translation` is explicitly set.
- 2026-04-10: Workstream 10 progressed further. Translation manual sinks now preserve explicit workflow context: `ready_review` translation cases carry `workflow_step=translate_<lang>`, `workflow_step_status=manual_confirmation`, per-step attempts, cleared retry windows, and a concrete `workflow_last_error`. This keeps bounded translation failure handling aligned with the canonical step contract instead of leaving manual translation review as an anonymous sink.
- 2026-04-10: Workstream 10 progressed further. Translation no-progress retries now update the canonical step metadata directly: `workflow_step`, `workflow_step_status`, `workflow_step_attempts`, and `translation_no_progress_attempts` are advanced together, while legacy `retries.translate_<lang>` remains only as compatibility storage. Recovery decisions for persisted translation manual reviews now read `workflow_step_attempts` alongside legacy counters instead of relying on the old retry bucket alone.
- 2026-04-10: Workstream 10 progressed further. Persisted translation recovery and reset paths now read one bounded attempt number from canonical `workflow_step_attempts` plus compatibility counters, and translation reset/resume no longer leaves stale `workflow_step_attempts` or `workflow_last_error` behind when the item is requeued or auto-recovered.
- 2026-04-10: Workstream 9 progressed further. The remaining generated-cover/media-repair tails now also write explicit `finalize_media` workflow context instead of acting like anonymous queue-level media blockers. Generated-cover demotion records `workflow_recovery_reason=generated_cover_demoted`, and persisted media repairs record `workflow_recovery_reason=persisted_media_repair` before returning items to `finalize_media`.
- 2026-04-10: Workstream 11 progressed further. `bridge/state` no longer calls the heavyweight queue selector path on every orchestrator tick. A dedicated lightweight `bridge_runtime_snapshot()` now serves orchestrator state, which removed the live `504/502` loop caused by slow bridge state requests.
- 2026-04-10: Workstream 11 progressed further. `bridge/health` now exposes deterministic checks for worker availability, collect/process/publish locks, recent started runs, and cached queue-contract regression status; `bridge/state` now also surfaces the queue-contract summary so the external orchestrator can see live truth without re-entering the hot selector path.
- 2026-04-10: Workstream 12 progressed further. Bridge health/state now also expose an acceptance snapshot with the recent published auto-item streak that still passes the publish-grade contract, so the `10`-item autonomous target is measurable by the orchestrator without manual wp-admin inspection.
- 2026-04-10: admin queue page was lightened to stop blocking wp-admin. Initial queue page render is now lazy-loaded through AJAX instead of rendering the full queue in the first request, queue rows reuse cached decoded payload/selection data, and queue refresh cadence was reduced from 5s to 15s to avoid repeated PHP pressure.
- 2026-04-10: stale `started` runs were cleaned on live via `EPV2_Runs::cleanup_abandoned_started()`, which closed 3 abandoned run rows after repeated diagnostics and php-fpm restarts.
