# EPV2 Audit And Worker Plan

Date: 2026-03-19

## Goals That Must Stay Intact

- Source language can be anything: German, Ukrainian, Russian, English, Hindi, Latin, etc.
- Processing is `DE-first`:
  - ingest source
  - enrich and validate source facts
  - build one strong German master article
  - only then derive Ukrainian and English versions
- One article bundle across languages:
  - same story
  - same media
  - same category
  - same tags/SEO intent
- Publish gate remains strict:
  - no weak semantic package in `ready_publish`
  - strong SEO/Google/release quality only
- Short but valid news must be enriched, not discarded.

## Current Plugin Audit

### What Is Still Good Inside WordPress

- Queue storage and state transitions:
  - `includes/queue/class-epv2-queue.php`
- Job dispatch and publish slot timing:
  - `includes/jobs/class-epv2-jobs.php`
  - `includes/core/class-epv2-time-planner.php`
- Review/admin UI:
  - `includes/admin/class-epv2-admin.php`
  - `includes/review/class-epv2-review.php`
- Final post creation and multilingual bundle publish:
  - `includes/publish/class-epv2-publisher.php`
- Site-specific taxonomy and editorial mapping:
  - `includes/classify/class-epv2-taxonomy-map.php`

These parts are tightly coupled to WordPress posts, terms, metadata, cron, admin review, and should stay in WP.

### What Is Too Heavy Inside WordPress/PHP

- `includes/ai/class-epv2-ai-processor.php`
  - orchestration
  - prompt building
  - DE generation
  - translation
  - retry logic
  - payload repair
  - media repair triggers
- `includes/core/class-epv2-source-enricher.php`
  - search engine queries
  - active-source search
  - document fetching
  - quote extraction
  - event extraction
  - entity extraction
- `includes/media/class-epv2-media.php`
  - image search and validation
  - remote attachment handling
  - semantic relevance heuristics
  - Wikimedia/Pexels fallback chain
- `includes/ai/class-epv2-ai-response-validator.php`
  - semantic validation
  - SEO validation
  - Google preflight
  - language integrity checks

These are the modules most likely to hang, time out, or grow unmaintainably inside PHP request lifecycles.

## Proposed Split

### Keep In WordPress

- source registry
- queue table
- runs table
- admin UI
- review UI
- publish scheduler
- final post insert/update
- taxonomy assignment
- analytics storage

### Move To One Server Worker Service

One worker service, not many microservices.

Responsibilities:

1. Fetch and parse source documents
2. Run supporting-source search
3. Extract entities, dates, event context, quote candidates
4. Build `DE master`
5. Validate DE master semantically
6. Repair missing facts and media
7. Translate `DE -> UK/EN`
8. Validate language packages
9. Return one final JSON bundle to WP

That worker should return only structured bundle data, not publish directly.

## Minimal Reliable Pipeline

1. `collect`
   - create raw queue item
   - no publish logic here

2. `worker: research`
   - fetch primary
   - fetch supporting sources
   - extract event context
   - extract media candidates

3. `worker: de_master`
   - produce German master article
   - category
   - tags
   - SEO
   - structured metadata

4. `worker: validate_de`
   - reject shell/noise
   - require factual depth
   - require category fit
   - require correct media intent

5. `worker: translate_bundle`
   - generate UK and EN only from validated DE master

6. `worker: validate_bundle`
   - same media across languages
   - slugs valid
   - language integrity valid
   - no broken quotes

7. `wp: ready_publish`
   - store validated bundle
   - wait for publish slot

8. `wp: publish`
   - create/update posts
   - assign terms/meta
   - synchronize bundle metadata

## Retry And Failure Policy

- Retry only inside a concrete stage.
- Never bounce failed review items back to generic `new`.
- Each stage has bounded retry:
  - source fetch
  - enrichment
  - DE generation
  - translation
  - media recovery
- When bounded retry is exhausted:
  - `manual_review` if material is good but incomplete
  - `dead_letter` only for shell/noise/stale hopeless items

## Tools Worth Reusing In Worker

### Extraction / Parsing

- Trafilatura
  - article text and metadata extraction from web pages
  - https://trafilatura.readthedocs.io/en/latest/corefunctions.html
- Unstructured `partition_html`
  - structured HTML partitioning
  - https://docs.unstructured.io/open-source/core-functionality/partitioning

### Language / Semantics

- spaCy `EntityRuler`
  - robust rule-based entity patterns for politicians, parties, clubs, institutions, event phrases
  - https://spacy.io/usage/rule-based-matching/
  - https://spacy.io/api/entityruler
- Haystack `DocumentLanguageClassifier`
  - fast language routing for arbitrary source language
  - https://docs.haystack.deepset.ai/docs/documentlanguageclassifier
- dateparser
  - multilingual date/time extraction from noisy texts
  - https://dateparser.readthedocs.io/en/latest/

### Job / Retry Worker

- Celery
  - mature retry/backoff model for slow I/O and AI calls
  - https://docs.celeryq.dev/en/stable/userguide/tasks.html#automatic-retry-for-known-exceptions
- Python RQ
  - simpler alternative if we want smaller operational footprint
  - https://python-rq.org/docs/exceptions/#retrying-failed-jobs

## WordPress/Product References Worth Borrowing From

- Action Scheduler
  - proven queue pattern for WordPress background jobs
  - https://actionscheduler.org/api/
  - https://actionscheduler.org/usage/
- Feedzy
  - image fallback order and full-text import logic are useful references for ingest/media strategy
  - https://docs.themeisle.com/feedzy-rss-feeds/image-not-showing-in-feedzy
- TaxoPress
  - auto-term/content-analysis approach is a useful reference for category/tag extraction layers
  - https://taxopress.com/docs/analyze-content-with-regular-expressions/
- Rank Math Content AI
  - useful reference for server-side AI assistance + SEO field integration
  - https://rankmath.com/kb/how-to-use-content-ai/

## Analytics / Learning Layer To Add Later

This should not be freeform “memory”.
It should be structured post-publication analytics.

Collect:

- impressions from home/section
- CTR
- engaged time
- device
- language
- category
- story format
- topic label

Then run weekly/monthly analyzer:

- which categories hold attention best
- which title styles drive higher CTR
- which formats underperform
- which community/service topics overperform

References:

- Chartbeat reports / engaged time
  - https://help.chartbeat.com/hc/en-us/articles/360017785874-Guide-to-Reports
- Parse.ly engaged time benchmark
  - https://www.parse.ly/analysts-corner-benchmark-engaged-time/

## Migration Path

### Phase 1

- keep current plugin live
- tighten queue correctness in WP
- stop semantic drift and metadata drift

### Phase 2

- build one local worker service
- expose one endpoint or CLI contract:
  - input: queue item id + source payload
  - output: validated bundle JSON

### Phase 3

- move:
  - enrichment
  - event extraction
  - media search
  - DE generation
  - translation
  - semantic validation

### Phase 4

- keep publish, admin review, queue and analytics in WP

## Immediate Local Priorities

1. Finish current queue stabilization with multiple live cycles
2. Repair published bundle category/meta sync everywhere
3. Tighten quote extraction and translation quote safety
4. Fix remaining rendered content tail artifacts
5. Then start worker scaffold outside WP
