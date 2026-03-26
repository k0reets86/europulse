# EPV2 DE-First Refactor Plan

## Goal

Make the pipeline strictly `source -> DE master -> UK/EN -> publish`.

## Required Rules

1. Any input language is normalized into German first.
2. All heavy work happens only on the German master:
   - body-based topic/context understanding
   - enrichment and supporting sources
   - category decision
   - media resolution
   - SEO/meta/tags
   - quality/release/google validation
3. `UK/EN` are generated only after the German master is valid.
4. Stage retries must be local:
   - media issue must not trigger full multilingual rebuild
   - translation issue must not trigger DE rebuild
   - publish issue must not trigger source re-enrichment
5. `ready_review` remains only for hard dead ends.

## Target State Machine

- `new`
- `processing_de`
- `de_ready_for_translation`
- `translating_variants`
- `ready_publish`
- `publishing`
- `published`
- `retry_process`
- `ready_review`
- `rejected`

## What Must Be Removed

- implicit multilingual generation during `full_bundle`
- implicit multilingual generation during `rebuild_bundle`
- repeated `rebuild -> translate -> rebuild -> lift` loops on one item
- queue blocking by stale `processing_de` / orphan lock without server-side recovery

## Immediate Refactor Sequence

### Phase 1

- enforce DE-only lift in worker `full_bundle/rebuild_bundle`
- queue `translate_finish` only after viable DE master
- keep `publish_finish` multilingual-only after translations exist

### Phase 2

- split `try_lift_payload_to_publish_grade()` into:
  - `lift_de_master_to_publish_grade()`
  - `lift_translations_to_publish_grade()`
- stop using one mixed lift method for all stages

### Phase 3

- replace current mixed worker bridge outcomes with explicit stage outcomes:
  - `de_master_ready`
  - `translations_ready`
  - `publish_ready`
  - `retry_media`
  - `retry_enrichment`
  - `retry_translation`

### Phase 4

- remove `wp-load.php` fallback from `php_worker.php`
- keep only CLI/worker bridge path with bounded timeout and explicit stage result

## Current First-Step Changes Already Applied

- server orchestrator cleanup now runs in CLI and recovers stale `processing_de`
- orphan process-lock recovery is now invoked from `run_process_windowed()`
- worker `full_bundle/rebuild_bundle` now call publish-grade lift in `DE-only` mode
- worker/rebuild response can now queue `translate_finish` immediately after viable DE master instead of demanding full multilingual review/publish readiness

## Next First Change

Refactor `try_lift_payload_to_publish_grade()` into separate DE-only and translation-only methods, then make `translate_finish` the only place where `UK/EN` are generated or regenerated.
