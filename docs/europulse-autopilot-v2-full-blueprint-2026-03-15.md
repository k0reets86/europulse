# EuroPulse AutoPilot v2 Full Blueprint

Last updated: 2026-03-15

## 1. Purpose

This is the full project blueprint for `EuroPulse AutoPilot v2`.

It defines the target structure of the entire plugin, not only Phase 1.

The plugin must:

- work inside the current EuroPulse WordPress site
- respect the already fixed frontend contract
- remain fully admin-configurable
- support multilingual publishing
- support legal/compliance constraints for Germany
- support manual, semi-auto and auto workflows

Nothing operational should depend on editing code after installation.

## 2. Product definition

`EuroPulse AutoPilot v2` is a WordPress plugin that:

- collects content from configurable sources
- normalizes and deduplicates incoming items
- classifies them into the EuroPulse taxonomy model
- rewrites them with AI
- enriches them with SEO/meta/media
- publishes them into the site in `de`, `uk`, `en`
- supports editorial review and overrides
- keeps logs, stats, and queue history

## 3. Design principles

### 3.1 Dashboard-first

All operational controls live in admin:

- sources
- URLs
- RSS feeds
- parse rules
- attribution rules
- API keys
- providers
- prompts
- category biases
- publish modes
- cron intervals
- language settings
- compliance toggles

### 3.2 No parallel publishing system

The plugin publishes into:

- standard `post`
- current categories
- current Polylang language system
- current Rank Math setup
- current EuroPulse frontend rules

It must not create a second homepage system or second content model for news.

### 3.3 Contract-driven publishing

The plugin must read and obey:

- `europulse_publishing_profile_json`
- `europulse_ui_system_profile_json`

This is the site contract.

### 3.4 Source legality first

The source layer must explicitly track risk and handling mode.

### 3.5 Queue-centered workflow

Nothing goes directly from source to publish.

Every item enters the queue first.

## 4. Full project structure

```text
europulse-autopilot/
  europulse-autopilot.php
  uninstall.php
  readme.txt
  languages/
  assets/
    admin/
      css/
        admin.css
      js/
        admin.js
    public/
      css/
      js/
      img/
  includes/
    bootstrap.php
    container.php
    functions.php

    core/
      class-ep-plugin.php
      class-ep-site-profile.php
      class-ep-settings.php
      class-ep-capabilities.php
      class-ep-installer.php
      class-ep-uninstaller.php
      class-ep-upgrader.php

    admin/
      class-ep-admin.php
      class-ep-admin-menu.php
      class-ep-admin-assets.php
      class-ep-admin-notices.php
      class-ep-dashboard-page.php
      class-ep-queue-page.php
      class-ep-sources-page.php
      class-ep-source-editor-page.php
      class-ep-settings-page.php
      class-ep-manual-page.php
      class-ep-logs-page.php
      class-ep-runs-page.php
      class-ep-prompts-page.php
      class-ep-tools-page.php
      class-ep-list-table-queue.php
      class-ep-list-table-sources.php
      class-ep-list-table-logs.php
      class-ep-list-table-runs.php

    api/
      class-ep-rest.php
      class-ep-rest-sources-controller.php
      class-ep-rest-queue-controller.php
      class-ep-rest-manual-controller.php
      class-ep-rest-dashboard-controller.php
      class-ep-rest-settings-controller.php
      class-ep-ajax.php

    jobs/
      class-ep-jobs.php
      class-ep-action-scheduler.php
      class-ep-runs.php
      class-ep-job-locks.php
      class-ep-retries.php

    sources/
      class-ep-sources.php
      class-ep-source-validator.php
      class-ep-source-policy.php
      class-ep-source-tester.php
      class-ep-source-mapper.php

    ingest/
      class-ep-collector.php
      class-ep-feed-reader.php
      class-ep-google-news.php
      class-ep-url-resolver.php
      class-ep-scrape-adapter.php
      adapters/
        class-ep-adapter-generic-rss.php
        class-ep-adapter-google-news.php
        class-ep-adapter-bamf.php
        class-ep-adapter-jobcenter.php
        class-ep-adapter-muenchen-events.php
        class-ep-adapter-ukrinform.php
        class-ep-adapter-community-calendar.php

    queue/
      class-ep-queue.php
      class-ep-queue-item.php
      class-ep-queue-repository.php
      class-ep-normalizer.php
      class-ep-deduplicator.php
      class-ep-duplicate-checker.php
      class-ep-queue-review.php

    classify/
      class-ep-categorizer.php
      class-ep-taxonomy-map.php
      class-ep-keyword-rules.php
      class-ep-ai-classifier.php
      class-ep-tag-generator.php

    ai/
      class-ep-ai-processor.php
      class-ep-ai-client.php
      class-ep-ai-response-validator.php
      class-ep-prompt-profiles.php
      providers/
        class-ep-provider-gemini.php
        class-ep-provider-openai.php
        class-ep-provider-anthropic.php
        class-ep-provider-deepseek.php

    media/
      class-ep-image-handler.php
      class-ep-media-library.php
      class-ep-image-provider.php
      class-ep-video-handler.php
      class-ep-video-poster.php
      providers/
        class-ep-image-provider-pexels.php
        class-ep-image-provider-unsplash.php
        class-ep-image-provider-source.php

    compliance/
      class-ep-compliance.php
      class-ep-source-attribution.php
      class-ep-image-attribution.php
      class-ep-ai-disclosure.php
      class-ep-sponsored-disclosure.php
      class-ep-legal-policy.php

    publish/
      class-ep-publisher.php
      class-ep-post-builder.php
      class-ep-polylang-sync.php
      class-ep-rank-math.php
      class-ep-post-meta-writer.php
      class-ep-breaking-manager.php

    manual/
      class-ep-manual-mode.php
      class-ep-manual-generator.php
      class-ep-manual-editor.php
      class-ep-manual-seo.php
      class-ep-manual-media.php

    metrics/
      class-ep-logger.php
      class-ep-stats.php
      class-ep-dashboard-stats.php
      class-ep-health.php
      class-ep-koko-bridge.php

    support/
      class-ep-utils.php
      class-ep-html.php
      class-ep-http.php
      class-ep-json.php
      class-ep-date.php
      class-ep-lang.php

  templates/
    admin/
      dashboard.php
      queue.php
      queue-row-details.php
      sources.php
      source-edit.php
      settings.php
      manual.php
      logs.php
      runs.php
      prompts.php
      tools.php
```

## 5. Module responsibilities

## 5.1 Core

### `class-ep-plugin.php`

Main orchestrator.

Responsibilities:

- boot plugin
- load config
- initialize modules
- register global hooks

### `class-ep-site-profile.php`

Reads and validates:

- `europulse_publishing_profile_json`
- `europulse_ui_system_profile_json`

Responsibilities:

- expose frontend contract to plugin modules
- prevent publishing that would violate layout rules

### `class-ep-settings.php`

Central settings API.

Responsibilities:

- get/set settings
- sanitize settings
- typed getters
- provider config
- language config

## 5.2 Admin

### Dashboard

Show:

- collected today
- rewritten today
- published today
- duplicates blocked
- queue by status
- source health
- AI token and cost stats
- errors

### Queue page

Show:

- incoming items
- source
- category proposal
- language plan
- status
- duplicate score
- actions:
  - approve
  - reject
  - retry
  - regenerate
  - edit
  - publish

### Sources page

Show:

- all sources
- type
- active state
- category bias
- language
- risk level
- fetch interval
- last fetch
- last error

### Source editor

Fields:

- source name
- source type
- source URL
- active/pause
- priority
- language
- category bias
- fetch interval
- risk level
- parse rules
- attribution rules
- notes
- test fetch button

### Settings page

Settings sections:

- General
- Languages
- AI providers
- Image providers
- Publishing
- SEO
- Compliance
- Cron/jobs
- Dedupe
- Debug/tools

### Manual page

Capabilities:

- generate from URL
- paste raw source text
- AI edit
- SEO optimize
- find media
- save as draft
- publish

### Logs page

Show:

- module
- level
- message
- context
- timestamp

### Runs page

Show:

- job run history
- counts
- errors
- duration

### Prompts page

Manage prompt templates for:

- news
- life in Germany
- events
- SEO
- social

## 5.3 API layer

Two interfaces:

- REST API for structured admin actions
- AJAX fallback for classic admin screens

Endpoints should cover:

- source CRUD/test
- queue actions
- manual generation
- dashboard stats
- settings save
- prompt save

## 5.4 Jobs / scheduler

Use **Action Scheduler** as the main queue engine.

Job types:

- `ep_collect_source`
- `ep_resolve_source_item`
- `ep_dedupe_item`
- `ep_classify_item`
- `ep_rewrite_item`
- `ep_attach_media`
- `ep_publish_item`
- `ep_retry_failed_item`
- `ep_refresh_stats`

Rules:

- per-job retries
- timeouts
- locking
- run logging

## 5.5 Sources

### Source types

- `rss`
- `atom`
- `google_news`
- `scrape`
- later:
  - `telegram`
  - `api`
  - `manual-import`

### Source fields

- `id`
- `name`
- `type`
- `url`
- `language`
- `category_bias`
- `priority`
- `fetch_interval`
- `is_active`
- `risk_level`
- `parse_rules`
- `attribution_rule`
- `robots_status`
- `last_fetched`
- `last_error`
- `notes`

### Important rule

No production source should be hardcoded as permanent truth.

You may ship optional starter defaults, but they must become editable rows in the admin UI.

## 5.6 Ingestion

### `class-ep-feed-reader.php`

Use WordPress/SimplePie-compatible parsing for RSS and Atom.

### `class-ep-google-news.php`

Responsibilities:

- fetch Google News RSS
- normalize title/source
- resolve final article URL

### `class-ep-scrape-adapter.php`

Responsibilities:

- route to source-specific adapters
- no single generic scraping logic as the only strategy

### Adapter system

Each adapter can define:

- selector map
- extraction map
- cleanup rules
- date rules
- image rules

## 5.7 Queue and normalization

Every fetched item must be normalized into one internal schema:

- source id
- original URL
- canonical URL
- original title
- original content
- original date
- original author
- source-provided image
- language
- source category bias
- source risk level

Queue states:

- `new`
- `fetched`
- `normalized`
- `duplicate`
- `rejected`
- `processing_ai`
- `rewritten`
- `ready_review`
- `ready_publish`
- `published`
- `error`

## 5.8 Deduplication

Checks:

1. exact URL
2. canonical URL
3. title hash
4. content hash
5. semantic similarity
6. duplicate against already published posts

Store:

- duplicate reason
- duplicate target id
- similarity score

## 5.9 Classification

Pipeline:

1. source bias
2. keyword rules
3. AI fallback
4. admin override

Output:

- primary category
- optional secondary category
- optional subcategory
- suggested tags

Must map to:

- real Polylang term IDs
- real site taxonomy structure

## 5.10 AI layer

### Providers

- Gemini
- OpenAI
- Anthropic
- DeepSeek

### Provider fields

- enabled
- priority
- API key
- model
- fallback enabled

### Prompt profiles

Named prompt profiles:

- `news_default`
- `leben_in_deutschland`
- `community_event`
- `seo_refine`
- `manual_rewrite`

### AI output package

For each queue item:

- `de.title`
- `de.excerpt`
- `de.content`
- `uk.title`
- `uk.excerpt`
- `uk.content`
- `en.title`
- `en.excerpt`
- `en.content`
- seo title
- seo description
- tags
- optional focus keyword

### Validation

Must validate:

- valid JSON
- all required fields present
- title not empty
- excerpt not empty
- content not empty
- no forbidden ellipsis if final title
- content length within range

## 5.11 Media layer

### Providers

- source image
- Pexels
- Unsplash optional
- manual upload

### Media flow

1. choose source image if legally acceptable
2. otherwise search image provider
3. sideload through WordPress
4. store attribution
5. reuse attachment if already known

### Video support

Fields:

- `video_url`
- `video_type`
- `europulse_video_poster`

Capabilities:

- remote video
- local upload
- poster handling

## 5.12 Compliance

Responsibilities:

- source attribution
- image credit
- AI disclosure toggle
- Ukrinform special rule
- sponsored/ad disclosure
- source block
- open data license note

Important:

- no ugly inline styles
- output should match theme/front layer conventions

## 5.13 Publishing

Publishing responsibilities:

- create German primary post
- create UKR and EN translations
- link them with Polylang
- assign categories
- assign tags
- set Rank Math fields
- set source/image/video meta
- set breaking/sponsored fields
- set featured image

Publish modes:

- draft
- scheduled
- immediate publish

## 5.14 Manual workflow

Manual mode must support:

- generate from URL
- generate from pasted source text
- AI rewrite current draft
- SEO optimize current draft
- choose or replace image
- category override
- tags override
- language override
- save draft
- publish

## 5.15 Metrics and health

Metrics:

- items collected
- items rewritten
- items published
- duplicates blocked
- queue size per status
- per-language output
- per-category output
- source success/failure
- AI token usage
- AI cost

Health:

- source fetch failures
- provider failures
- stuck queue items
- publish failures
- repeated duplicate storms

## 6. Database design

## 6.1 Required tables

### `wp_ep_sources`

- source registry

### `wp_ep_queue`

- queue items

### `wp_ep_log`

- event log

### `wp_ep_stats`

- daily counters

### `wp_ep_runs`

- job run history

## 6.2 Optional tables

### `wp_ep_source_items`

- raw fetched item archive

### `wp_ep_prompt_profiles`

- prompt templates and versions

## 7. WordPress options

These must be configurable in dashboard and stored in options.

### General

- mode
- articles_per_day
- enabled_languages
- primary_language

### Providers

- ai provider configs
- fallback provider configs
- image provider configs

### Publishing

- default status
- auto publish toggle
- schedule policy

### Queue

- max queue process size
- retry count
- duplicate threshold

### Compliance

- ai disclaimer toggle
- ai disclaimer text
- image disclaimer text
- source block toggle

### Prompts

- prompt profile definitions

### Cron/jobs

- collect interval
- process interval
- publish interval
- stats refresh interval

## 8. Taxonomy and language mapping

The plugin must never guess blindly.

It must keep a canonical taxonomy map for:

- `de`
- `uk`
- `en`

Mapping must include:

- top-level category term IDs
- subcategory term IDs
- Polylang term relationships

## 9. Frontend contract rules the plugin must obey

These are read from the current site profile.

### Story meta

- order: `date -> categories`
- cards/slider use two-line meta
- dots before each category
- ad labels do not get category dots

### Slider

- 5 posts
- title must fit hero rules
- excerpt must fit hero rules

### Latest

- equal-height cards
- title/excerpt limits by language

### Split sections

- one lead with image
- text list beside/below
- no thumbs in mobile list

### Grid sections

- compact-card layout only

### Archive/single

- keep native site meta/SEO/video/image rules

## 10. Security and robustness

Must include:

- capability checks
- nonce checks
- URL sanitization
- output escaping
- API key masking in UI
- job locking
- retries with limits
- timeout handling
- source pause on repeated failure
- logs for every critical action

## 11. Testing strategy

### Unit-like tests

- parser normalization
- taxonomy mapping
- prompt validation
- JSON validation
- compliance output

### Integration tests

- source fetch -> queue
- queue -> AI
- AI -> publish
- publish -> Polylang linking
- image attach
- SEO meta write

### Site-level checks

- smoke
- crawl
- accessibility
- viewport matrix

## 12. Build phases

## Phase 1. Foundation

- bootstrap
- settings
- installer
- base tables
- Action Scheduler integration
- admin shell

## Phase 2. Sources and ingestion

- source registry
- source tester
- RSS
- Google News
- scrape adapters
- queue insertion

## Phase 3. Queue and dedupe

- normalization
- dedupe
- queue statuses
- queue admin table

## Phase 4. Classification

- taxonomy map
- keyword rules
- AI fallback categorization
- tag generator

## Phase 5. AI processing

- provider abstraction
- prompt profiles
- multilingual output
- response validation

## Phase 6. Media and compliance

- image providers
- sideload pipeline
- attribution
- source block
- disclosure rules
- video handling

## Phase 7. Publishing

- post builder
- Polylang linking
- Rank Math writer
- publish modes
- breaking/sponsored support

## Phase 8. Manual workflow

- manual generation
- AI edit
- SEO optimize
- approval UI

## Phase 9. Metrics and health

- dashboard stats
- source health
- AI cost stats
- run logs
- retry tools

## Phase 10. Hardening and rollout

- test matrix
- performance checks
- source policy review
- controlled production rollout

## 13. What is configurable and must never be hardcoded

These must stay dashboard-configurable:

- source URLs
- source activation
- source type
- source language
- source category bias
- source parse rules
- source attribution rules
- fetch intervals
- AI provider choice
- fallback provider choice
- API keys
- model names
- prompt texts
- image provider choice
- publishing mode
- language publishing set
- dedupe threshold
- AI disclosure settings
- cron/job intervals

Hardcoding is allowed only for:

- internal plugin structure
- defaults
- validation rules
- non-operational constants

## 14. Final recommendation

Build this as a clean `AutoPilot v2`.

Use the existing prototype as:

- an idea bank
- a source of partial code
- a draft of some classes/tables

But the production plugin must follow this blueprint, not the prototype’s current shape.
