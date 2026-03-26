# EuroPulse AutoPilot v2 Build Plan

Last updated: 2026-03-15

## Principle

The plugin must be flexible by design.

Nothing operational should be hardcoded if it can reasonably be managed in the dashboard.

That includes:

- source URLs
- RSS feeds
- source types
- source priorities
- source activation/deactivation
- category targeting
- language targeting
- AI providers
- API keys
- prompts
- cron intervals
- publishing mode
- breaking/sponsored behavior
- image providers
- SEO behavior

The dashboard must be the control center.

## Core product goal

Build a production-grade WordPress plugin for EuroPulse that:

- collects content from configurable sources
- filters and deduplicates it
- classifies it into the real EuroPulse structure
- rewrites it with AI in `de / uk / en`
- generates compliant titles, excerpts, SEO and metadata
- finds or assigns media
- publishes into the current WordPress + Polylang + Rank Math site model
- supports manual, semi-auto and auto workflows
- logs everything

## Hard constraints

- Must publish into current EuroPulse WordPress structure, not a parallel system
- Must respect current frontend rules already fixed on the site
- Must support `de`, `uk`, `en`
- Must keep admin control over all operational settings
- Must be legally safer for Germany than generic autoblog plugins

## Final architecture

### 1. Bootstrap layer

Purpose:

- load modules
- register hooks
- register admin
- register REST/AJAX
- register cron / Action Scheduler jobs
- load translations

Files:

- `europulse-autopilot.php`
- `includes/bootstrap.php`
- `includes/container.php`

### 2. Site contract layer

Purpose:

- read the current EuroPulse publishing/UI rules from:
  - `europulse_publishing_profile_json`
  - `europulse_ui_system_profile_json`
- expose one typed API to the plugin

This ensures the plugin generates content that fits the real site.

Files:

- `includes/class-ep-site-profile.php`

### 3. Settings and configuration layer

Purpose:

- centralize all plugin settings
- keep zero operational hardcoding

Settings must include:

- AI providers and keys
- image providers and keys
- source defaults
- publish modes
- language rules
- cron intervals
- dedupe thresholds
- SEO defaults
- compliance toggles
- social settings later

Files:

- `includes/class-ep-settings.php`
- `admin/views/settings.php`

### 4. Source registry layer

Purpose:

- admin-controlled source management

Each source must support:

- name
- URL
- source type
  - `rss`
  - `atom`
  - `google_news`
  - `scrape`
  - later optional `telegram`, `api`, `manual-import`
- source status:
  - active / paused / disabled
- language
- category bias
- priority
- fetch interval
- risk level
- parse rules
- attribution rules
- notes
- test button

Nothing should be seeded as permanent hardcoded behavior. Defaults can be imported once, but all rows must be editable in admin.

Files:

- `includes/class-ep-sources.php`
- `admin/views/sources.php`
- `admin/views/source-edit.php`

### 5. Queue and job engine

Purpose:

- run the whole workflow reliably

Recommended engine:

- **Action Scheduler** as the main orchestration layer

Jobs:

- collect source items
- normalize source items
- resolve Google URLs
- dedupe
- classify
- AI rewrite
- image handling
- publish
- retry failed items

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

Files:

- `includes/class-ep-queue.php`
- `includes/class-ep-jobs.php`
- `includes/class-ep-runs.php`

### 6. Ingestion layer

Purpose:

- fetch content from configurable sources

Submodules:

- RSS / Atom collector
- Google News RSS collector
- source-specific scrape adapters

Important rule:

- no universal “magic scraper”
- use adapters per source family

Source strategies:

- official/public institution sources: direct RSS/XML
- commercial media discovery: Google News RSS + final URL resolver
- local community pages without RSS: DOM/XPath adapters

Files:

- `includes/class-ep-collector.php`
- `includes/class-ep-feed-reader.php`
- `includes/class-ep-google-news.php`
- `includes/class-ep-scrape-adapter.php`
- `includes/adapters/*`

### 7. Deduplication layer

Purpose:

- stop duplicate stories before they hit AI or publishing

Checks:

1. exact source URL
2. canonical resolved URL
3. normalized title hash
4. normalized content hash
5. semantic similarity threshold
6. duplicate against already published WordPress posts

Files:

- `includes/class-ep-deduplicator.php`

### 8. Classification layer

Purpose:

- assign the story correctly into current EuroPulse structure

Method:

1. source bias
2. keyword/rule score
3. AI fallback classification
4. admin override

Output must map into real site taxonomies, not generic slugs only.

The plugin must know:

- top-level categories
- subcategories
- language-specific term IDs
- Polylang relations

Files:

- `includes/class-ep-categorizer.php`
- `includes/class-ep-taxonomy-map.php`

### 9. AI processing layer

Purpose:

- transform source material into EuroPulse-ready editorial output

Providers:

- Gemini
- OpenAI
- Anthropic
- DeepSeek

Rules:

- provider selection must be configurable in admin
- fallback provider configurable
- no provider hardcoded
- per-category prompt profiles
- per-language output
- strict JSON validation

Prompt families:

- general news
- life in Germany
- events/community
- SEO enrichment
- manual editing

Generated fields:

- editorial title
- excerpt/dek
- full article body
- tags
- SEO title
- SEO description
- optional focus keyword

Files:

- `includes/class-ep-ai-processor.php`
- `includes/class-ep-ai-client.php`
- `includes/class-ep-prompt-profiles.php`

### 10. Media layer

Purpose:

- attach legal and visually suitable media

Sources:

- source image from article if allowed
- Pexels
- Unsplash optional
- manual upload
- later AI image generation if desired

Rules:

- provider and API keys configurable
- attribution stored
- original source stored
- attachment reuse if same media already exists
- WordPress sideload pipeline only

Video support:

- remote or local video
- poster support
- `europulse_video_poster`

Files:

- `includes/class-ep-image-handler.php`
- `includes/class-ep-media-library.php`
- `includes/class-ep-video-handler.php`

### 11. Compliance layer

Purpose:

- keep publication legally and editorially safer

Responsibilities:

- source attribution
- image attribution
- source block generation
- Ukrinform first-paragraph rule
- open-data attribution
- AI disclosure toggle
- sponsored/ad distinction

Important:

- no inline ugly styles in generated HTML
- output should match current site rendering conventions

Files:

- `includes/class-ep-compliance.php`

### 12. Publishing layer

Purpose:

- create actual WordPress posts cleanly

Responsibilities:

- create `de` post
- create `uk` translation
- create `en` translation
- link translations in Polylang
- assign categories
- assign tags
- write Rank Math meta
- set image and attribution fields
- set source meta
- set breaking/sponsored flags
- set video poster if needed

Publishing modes:

- draft
- scheduled
- publish now

Files:

- `includes/class-ep-publisher.php`

### 13. Manual and semi-manual workflow

Purpose:

- let admin/editor work with the plugin directly

Manual functions:

- generate article from URL
- run AI rewrite on pasted text
- AI edit article
- SEO optimize
- find/select image
- save draft
- approve queue item
- publish queue item

Semi-manual functions:

- queue approval step
- category override
- title/excerpt correction
- language disable per item

Files:

- `includes/class-ep-manual-mode.php`
- `admin/views/manual.php`
- `admin/views/queue.php`

### 14. Dashboard and observability

Purpose:

- show real operational state

Dashboard widgets:

- collected today
- rewritten today
- published today
- duplicates stopped
- queue size by state
- source health
- AI token usage
- AI cost
- per-category output
- per-language output
- recent errors

Also useful:

- run history
- retry button
- source test results

Files:

- `includes/class-ep-logger.php`
- `includes/class-ep-stats.php`
- `admin/views/dashboard.php`
- `admin/views/logs.php`

## Database design

Keep and evolve:

- `ep_sources`
- `ep_queue`
- `ep_log`
- `ep_stats`

Add:

- `ep_runs`
- optional `ep_source_items`
- optional `ep_prompt_profiles`

## Admin UX requirements

Everything operational must be manageable in dashboard.

That includes:

- add/remove/edit/pause sources
- test source fetch
- switch source type
- edit parse rules
- edit attribution rules
- choose per-source target category
- choose per-source target language
- configure AI provider and fallback
- configure API keys
- choose publication mode
- choose cron intervals
- enable/disable auto-publish
- enable/disable translations
- edit prompt templates
- adjust dedupe threshold
- edit compliance toggles

There must be no need to edit code for routine operation.

## Content generation contract

The plugin must obey the current site rules already stored in the EuroPulse publishing/UI profile.

### Titles

- complete editorial thought
- no ellipsis
- no raw source dump
- no clickbait

### Excerpts

- destination-aware
- not one universal excerpt for all zones

Current operational targets:

- slider:
  - `de 190`
  - `uk 208`
  - `en 186`
- latest:
  - `de 108`
  - `uk 96`
  - `en 110`

### Categories

- 1 required primary category
- 1–2 additional categories only if strongly justified

### Tags

- `3–7` useful tags
- entity / location / policy / event tags
- no garbage tags

### Breaking

- `europulse_breaking`
- `europulse_breaking_until`

Ticker logic:

- ticker always visible
- if no active breaking items, fallback to top/latest

## Legal source policy

Default allowed source strategy:

- official/public sources
- Google News mediated discovery
- controlled local scrape adapters

Default restricted strategy:

- direct scraping of commercial publishers

Every source row in admin should have:

- risk level
- legal note
- recommended handling mode

## Recommended implementation phases

### Phase 1

- bootstrap
- settings
- source registry
- base tables
- Action Scheduler integration
- logs

### Phase 2

- ingestion
- source testing
- queue insertion
- dedupe

### Phase 3

- categorization
- queue workflow
- review UI

### Phase 4

- AI provider abstraction
- prompt profiles
- multilingual output
- JSON validation

### Phase 5

- publisher
- Polylang linking
- Rank Math integration
- image handling

### Phase 6

- manual mode
- semi-auto approval flow

### Phase 7

- compliance hardening
- source-specific rules
- dashboard stats
- retries and health

### Phase 8

- optional social publishing
- optional event-specific objects
- optional newsletter/export

## Final recommendation

Build `EuroPulse AutoPilot v2` as a new controlled plugin.

Use the prototype only as:

- module inspiration
- partial code donor
- first draft of tables/classes

Do not treat the prototype as a near-finished production plugin.

The production version must be:

- dashboard-driven
- source-configurable
- legally safer
- WordPress-native
- bound to the current EuroPulse frontend contract
