# EPV2 Worker Cut Map

Date: 2026-03-19

## Why The Split Is Needed

The plugin is currently mixing two very different responsibilities:

1. WordPress orchestration
   - queue states
   - cron / backstop jobs
   - admin review
   - final publish
   - taxonomy/meta synchronization

2. Heavy content intelligence
   - context understanding
   - source enrichment
   - DE master drafting
   - translation
   - semantic validation
   - media candidate search

The second block is slow, network-heavy, retry-heavy, and stateful. That is the part that should move out of WordPress.

## The Correct DE-First Order

The worker must process stories in this order:

1. understand context
2. enrich facts and missing details
3. classify category
4. find media candidates
5. build one strong German master article
6. validate German master
7. derive Ukrainian and English from that German master
8. validate the multilingual bundle
9. hand the final bundle back to WordPress

Important:

- one story bundle across all languages
- one category bundle across all languages
- one media choice across all languages
- translations never run before German master is approved
- story length is decided by `story_kind` and `length_profile`, not by one global minimum

## What Stays In WordPress

These parts are tightly coupled to WP posts, terms, metadata, and admin UX and should remain in the plugin:

- `includes/queue/class-epv2-queue.php`
  - queue storage
  - state transitions
  - ready-publish scheduling
- `includes/jobs/class-epv2-jobs.php`
  - cron hooks
  - backstop triggers
- `includes/core/class-epv2-time-planner.php`
  - publish cadence
  - daypart rules
- `includes/admin/class-epv2-admin.php`
  - dashboard
  - review UI
  - archive edits
- `includes/review/class-epv2-review.php`
  - editor payload save/load
- `includes/publish/class-epv2-publisher.php`
  - post insert/update
  - taxonomy assignment
  - Polylang linking
  - SEO meta write
  - thumbnail attach/final publish

## What Must Move To The Worker

### 1. Context / Intake Intelligence

Current WP implementation:

- `EPV2_AI_Processor::process_scheduled()`
- `EPV2_Budget_Manager::analyze_item()`
- `EPV2_Categorizer::detect()`
- `EPV2_Categorizer::refine_with_event_context()`

Move out:

- source language detection
- story type detection
- event/news/service/sport/community intent detection
- first-pass category proposal
- stale/event-context detection

### 2. Research / Enrichment

Current WP implementation:

- `EPV2_Source_Enricher::enrich_item()`
- internal search-query generation and supporting-source filtering

Move out:

- source fetching
- supporting-source search
- event extraction
- quote extraction
- entity extraction
- search-term generation
- stale source contamination filtering

### 3. German Master Generation

Current WP implementation:

- `EPV2_AI_Processor::generate_review_payload()`
- `EPV2_AI_Processor::build_messages()`

Move out:

- one canonical German bundle
- title
- lead
- body
- tags
- SEO fields
- story format / intent metadata

### 4. Translation

Current WP implementation:

- `EPV2_AI_Processor::repair_payload_languages()`
- `translate_language_package()`
- `translate_language_fields_separately()`
- `translate_language_content_in_chunks()`

Move out:

- `DE -> UK`
- `DE -> EN`
- quote-safe translation
- slug/meta generation per language

### 5. Semantic Validation

Current WP implementation:

- `EPV2_AI_Response_Validator`
- `EPV2_AI_Processor::payload_is_review_ready()`
- `EPV2_AI_Processor::payload_is_publish_ready()`

Move out:

- factual completeness checks
- category fit checks
- translation integrity checks
- SEO/Google packaging checks
- warning vs blocker classification

### 6. Media Candidate Search

Current WP implementation:

- `EPV2_Media::resolve_featured_media()`
- `EPV2_Media::is_relevant_media()`

Move out:

- source-based media candidate collection
- supporting-source media collection
- Wikimedia/Pexels fallback search
- event/entity-aware candidate ranking

Important:

- candidate search moves out
- final attachment sideload and thumbnail assignment stay in WordPress publish

This keeps publishing safe and avoids trying to replicate WP media handling in the worker.

## What Should Not Move Yet

Do not move these in phase 1:

- `wp_insert_post`
- `wp_set_post_terms`
- `pll_set_post_language`
- `pll_save_post_translations`
- Rank Math meta writes
- actual attachment creation

Those are stable inside WordPress and do not cause the heavy semantic stalls.

## Cut Boundary Contract

WordPress sends to worker:

- queue item id
- raw source fields
- current category proposal
- current cluster/topic metadata
- current editorial flags
- current payload if this is a rebuild
- requested stage

Worker returns:

- bundle status:
  - `ready_publish`
  - `ready_review`
  - `retry_process`
  - `dead_letter`
- canonical category list
- German master
- Ukrainian package
- English package
- shared media candidates
- chosen shared media URL
- SEO package
- tags
- quality / seo / release / google scores
- warnings and blockers
- source dossier
- event context
- story kind / length profile

## Minimal Migration Sequence

### Phase 1

- keep current plugin live
- stop semantic drift in WP
- keep publish inside WP

### Phase 2

- add one local server worker
- initial contract:
  - CLI first
  - HTTP later if needed

### Phase 3

- move `research + DE master + translation + validation + media candidate search`

### Phase 4

- let WP call worker for:
  - new queue items
  - rebuilds
  - translation finish
  - media repair

## Proven Patterns And References

- Action Scheduler for WP-side job orchestration:
  - https://actionscheduler.org/usage/
  - https://actionscheduler.org/api/
- Trafilatura for article extraction:
  - https://trafilatura.readthedocs.io/en/latest/corefunctions.html
- Unstructured HTML partitioning:
  - https://docs.unstructured.io/open-source/core-functionality/partitioning
- spaCy EntityRuler for rule-based entity/event patterns:
  - https://spacy.io/api/entityruler
  - https://spacy.io/usage/rule-based-matching/
- dateparser for multilingual event/date extraction:
  - https://dateparser.readthedocs.io/en/latest/
- Celery retry model:
  - https://docs.celeryq.dev/en/stable/userguide/tasks.html#automatic-retry-for-known-exceptions
- Python RQ retry model:
  - https://python-rq.org/docs/exceptions/#retrying-failed-jobs
- Content Egg as reference for multi-source aggregation:
  - https://www.keywordrush.com/manuals/content_egg_manual.pdf
- TaxoPress as reference for content-driven term extraction:
  - https://taxopress.com/automatically-add-terms-wordpress-content/

## Immediate Next Implementation Step

Build one worker skeleton outside WordPress that already enforces:

- DE-first stage order
- shared multilingual bundle contract
- one shared media result
- explicit `ready_publish / ready_review / retry_process / dead_letter` outcomes
- flexible story-length policy by story type
