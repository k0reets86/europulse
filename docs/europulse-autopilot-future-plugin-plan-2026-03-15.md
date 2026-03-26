# EuroPulse AutoPilot: Detailed Future Plugin Plan

Last updated: 2026-03-15

## 1. Goal

Build a production-grade WordPress plugin for EuroPulse that:

- collects news and event data from approved sources
- filters and deduplicates them
- rewrites them with AI under German legal constraints
- publishes them into the existing EuroPulse content model
- supports `de`, `uk`, `en`
- respects the current frontend rules already fixed on the site
- keeps a full editorial, legal and technical audit trail

The plugin must not invent its own publishing system. It must publish into the current EuroPulse WordPress model exactly as the site is now structured.

## 2. What exists already

### 2.1 Prototype strengths

The prototype in [`input/europulse-autopilot.zip`](/root/projects/europulse/input/europulse-autopilot.zip) already contains a good first split:

- sources
- collector
- deduplicator
- ai processor
- categorizer
- publisher
- compliance
- manual mode
- admin pages
- cron
- logs

This means the plugin does not need to be redesigned from zero.

### 2.2 Prototype weaknesses

The current prototype is not yet safe to install as-is because:

- it assumes a generic publishing model, not the exact EuroPulse frontend rules
- it uses queue fields too loosely for multilingual editorial control
- its AI prompt rules are still too broad for the current site layout
- source legality/risk handling is not strict enough yet
- category mapping is slug-based, but EuroPulse already has language-specific taxonomy mapping that must be canonical
- manual mode uses reflection into private AI methods, which is a bad production pattern
- compliance is still too generic and adds inline styles directly in HTML
- admin texts and UX are still draft-level
- no hard “site contract” exists yet between plugin output and frontend constraints

## 3. Non-negotiable system contract with the current site

The plugin must obey the current EuroPulse frontend system already fixed and stored in:

- [`europulse-publishing-profile.md`](/root/projects/europulse/docs/europulse-publishing-profile.md)
- [`europulse-publishing-profile.json`](/root/projects/europulse/docs/europulse-publishing-profile.json)
- WordPress options:
  - `europulse_publishing_profile_json`
  - `europulse_ui_system_profile_json`

### 3.1 Current homepage contract

- Hero slider: `5` latest suitable posts in current language
- `Weitere Themen`: `3` cards
- `Neueste Meldungen`: `6` latest cards
- Split modules:
  - `Deutschland`
  - `Ukraine`
  - `Community`
- Grid modules:
  - `Europa`
  - `Politik`
  - `Wirtschaft`
  - `Leben in Deutschland`
- Utility block:
  - `Wichtig im Blick`

### 3.2 Current content geometry contract

The plugin must generate content that fits these existing rules:

- story meta order: `date -> categories`
- card meta is 2-line
- category dots before each category
- hero headline box: up to `4` lines
- hero excerpt:
  - desktop `3` lines
  - mobile `4` lines
- latest card:
  - title `2` lines
  - excerpt `3` lines
  - fixed equal card height
- split section lists:
  - no thumbnails in side list on mobile
- archive/single metadata and separators must stay identical to site rules

### 3.3 Current SEO/meta contract

The plugin must fill and maintain:

- categories
- tags
- featured image
- image credit
- source block
- `rank_math_title`
- `rank_math_description`
- `rank_math_focus_keyword`
- optional:
  - `europulse_breaking`
  - `europulse_breaking_until`
  - `europulse_sponsored`
  - `europulse_popular_score`
  - `europulse_sources`
  - `europulse_video_poster`

## 4. Legal operating model

Based on the research in [`input/исследование.txt`](/root/projects/europulse/input/исследование.txt), the plugin must use a strict legal source policy.

### 4.1 Allowed source classes

#### Class A: safe / preferred

- German government and public institutions
- BAMF
- BMAS
- Bundestag
- Bundesregierung
- Bayern ministries
- official city and service portals
- institution RSS/XML

Use:

- direct RSS/XML ingestion
- minimal legal risk

#### Class B: Google News mediated

- commercial media discovered via Google News RSS
- use Google News feed collection plus canonical URL resolution
- no direct scraping of commercial publisher pages as a default collection strategy

Use:

- title + URL + minimal source facts
- fetch full text only if legally and technically appropriate
- always rewrite heavily, never republish publisher structure

#### Class C: allowed targeted scraping

- event/community sources without RSS
- local community calendars
- NGO/community pages
- local institutional pages

Use:

- DOM/XPath scrape by selector
- extract factual fields only

#### Class D: restricted / avoid by default

- direct scraping of commercial German publishers
- anything blocked by robots or terms
- anything with clear anti-bot or database-rights risk

Default policy:

- disabled unless explicitly approved

### 4.2 Compliance rules the plugin must enforce

- always preserve source provenance
- always store canonical source URL
- support source-specific attribution rules
- special handling for `Ukrinform`
- preserve image attribution and license provenance
- separate sponsored material from editorial material
- keep AI assistance disclosure configurable, not hard-coded everywhere

## 5. Final architecture for the real plugin

## 5.1 Module map

### A. Core bootstrap

Responsibilities:

- plugin boot
- module registration
- capability checks
- cron registration
- REST registration
- admin registration

Target structure:

- `plugin.php`
- `includes/bootstrap.php`
- `includes/container.php`

### B. Config and site contract module

Responsibilities:

- read current site rules from:
  - `europulse_publishing_profile_json`
  - `europulse_ui_system_profile_json`
- expose one typed configuration API to the plugin

This is mandatory. The plugin must not hardcode frontend assumptions in multiple places.

Suggested class:

- `EP_Site_Profile`

### C. Source registry module

Responsibilities:

- source CRUD
- source type
- fetch interval
- priority
- risk level
- parse rules
- attribution rules
- language
- intended category group
- source status

Keep current table idea, but expand explicitly:

- source policy status
- legal notes
- robots status
- last sample fetch status
- manual approval flag

### D. Ingestion module

Sub-modules:

- `RSS/Atom`
- `Google News RSS + resolver`
- `HTML scrape`
- later optional:
  - Telegram import
  - email ingest
  - API connectors

Responsibilities:

- fetch items
- normalize them into one internal item schema
- never publish directly
- pass to dedupe + queue

### E. Deduplication module

Current prototype already has a deduplicator. Keep the idea, but make it layered:

1. URL duplicate
2. title hash duplicate
3. normalized text hash duplicate
4. semantic duplicate
5. duplicate against existing WordPress posts

Important:

- dedupe must work across all three languages
- plugin must know that one source story can become `de + uk + en` linked posts

### F. Queue and workflow engine

The queue must become the real center of the system.

States:

- `new`
- `fetched`
- `normalized`
- `deduplicated`
- `rejected`
- `processing_ai`
- `rewritten`
- `ready_review`
- `ready_publish`
- `published`
- `error`

Modes:

- `manual`
- `semi-auto`
- `auto`

Each queue item should store:

- source metadata
- original title/content/url/date
- extracted facts
- chosen category proposal
- language plan
- AI result by language
- SEO result
- image result
- compliance result
- publish result

### G. AI orchestration module

The current prototype has provider routing already. Keep the idea, but productionize it.

Providers:

- Gemini
- OpenAI
- Anthropic
- DeepSeek

Responsibilities:

- prompt templates by content type
- provider failover
- cost tracking
- token tracking
- retry policy
- structured JSON response validation

Separate prompt families:

- news rewrite
- life in Germany explainer
- event announcement
- SEO enrichment
- title/dek/excerpt refinement
- tag suggestion
- image alt/caption generation
- social post generation

### H. Categorization module

The prototype keyword map is useful but too static as a final system.

Final categorization should be 3-stage:

1. rules/keywords
2. source default bias
3. AI classification fallback

And then map into the real WordPress taxonomy contract.

The plugin must not just output slugs. It must know exact language mappings for:

- top-level categories
- subcategories
- Polylang term relations

### I. Publishing module

The publisher must create posts in strict compliance with the site contract.

Responsibilities:

- create `de` base post
- create `uk` and `en` translations
- link translations in Polylang
- assign categories and tags correctly
- set Rank Math meta
- set featured image
- set image attribution/source
- set source block data
- set breaking/sponsored flags
- set video poster metadata if needed

Publishing must support:

- draft
- scheduled
- publish now

### J. Compliance module

Keep this module, but remove inline-style HTML generation.

It should produce structured content blocks or block-compatible HTML that matches the current theme layer.

Responsibilities:

- source attribution rules
- image attribution rules
- AI disclosure policy
- source block policy
- sponsored/ad disclosure policy
- open-data attribution policy

### K. Media module

Responsibilities:

- fetch remote image safely
- license-aware source handling
- alt text generation
- caption/credit generation
- assign featured image
- optional video handling

Important:

- video should support:
  - remote embed
  - uploaded file
  - poster generation or source poster fetch
- plugin must fill `europulse_video_poster`

### L. Manual mode module

Keep manual mode, but rebuild API boundaries.

Needed actions:

- generate from URL
- AI edit article
- SEO optimize
- image lookup
- save draft
- publish manually

Must not use reflection into private AI methods. Replace with a proper public application service.

### M. Admin UI module

Current prototype admin exists, but needs production redesign.

Pages:

- Dashboard
- Queue
- Sources
- Rules
- Prompt templates
- Manual mode
- Settings
- Logs
- Metrics

Need:

- clear Russian admin labels only if that is your chosen working language
- otherwise normalize all admin UI into one language

### N. Metrics and observability module

Responsibilities:

- collection counts
- queue throughput
- published posts
- duplicate count
- AI token usage
- AI cost
- source health
- per-category output
- per-language output
- failure rates

This module should also read site-side metrics:

- Koko Analytics
- publishing results
- search results if needed later

## 6. Database design for the real plugin

Current prototype tables are a good start:

- `ep_sources`
- `ep_queue`
- `ep_log`
- `ep_stats`

Recommended additions:

### `ep_runs`

Store each collector/process/publish run:

- module
- started_at
- finished_at
- status
- counts
- error summary

### `ep_source_items`

Optional but useful:

- every fetched item before queue admission
- allows debugging and re-ingestion

### `ep_prompt_profiles`

Store named prompt templates and versions:

- general news
- life in Germany
- event
- SEO
- social

### `ep_site_contract_cache`

Optional cache of parsed site profile, so plugin can detect contract changes.

## 7. Publishing rules the plugin must enforce

## 7.1 Titles

- editorial headline
- complete thought
- no ellipsis
- no raw source-title dump
- length adapted to destination zone

### Proposed limits

- hero headline:
  - longer allowed, but visual fit must remain within 4-line box
- default post title:
  - preferred `55–85` characters
- latest card derived title:
  - front end already clamps visually, but source title should aim shorter

## 7.2 Excerpts / dek

The plugin must generate destination-aware text:

- slider excerpt
- latest excerpt
- section list excerpt
- archive excerpt
- search excerpt

Recommended stored rule set:

- slider:
  - `de: 190`
  - `uk: 208`
  - `en: 186`
- latest:
  - `de: 108`
  - `uk: 96`
  - `en: 110`

The system should not generate one generic excerpt for every zone.

## 7.3 Categories

Primary rule:

- exactly 1 primary category always

Optional:

- 1–2 additional categories only if materially justified

The plugin must not spray 4–5 categories at random because that breaks home placement and editorial meaning.

## 7.4 Tags

Tags should be narrower than categories.

Recommended policy:

- `3–7` tags
- entity tags
- location tags
- policy/program tags
- no duplicate tag that equals the category name

## 7.5 Breaking

Breaking must be time-bound:

- `europulse_breaking = 1`
- `europulse_breaking_until = unix timestamp`

Rule:

- ticker always exists
- if no active breaking items exist, ticker falls back to latest/top items
- breaking chips in cards can disappear after expiry

## 7.6 Sponsored / ads

Sponsored is separate from breaking and categories.

The plugin must support:

- `europulse_sponsored = 1`
- ad/sponsored labels per language
- clear distinction from editorial material

## 8. How the plugin must integrate with the current theme layer

The plugin must not try to render the frontend itself.

It must publish content into the site so that the current rendering system picks it up:

- home shortcodes in `render.php`
- current Polylang language flow
- current `europulse-normalize.css`
- current source/meta/image/video conventions

That means:

- no custom front-end page builders
- no parallel CPT for news if normal `post` works
- no second homepage system
- no second meta system

For events, a separate model is acceptable later, but only if truly needed.

## 9. Recommended implementation phases

## Phase 1. Stabilized backend foundation

Build first:

- plugin bootstrap
- config/site-profile reader
- tables
- source registry
- logs
- runs
- cron

Exit condition:

- plugin activates/deactivates cleanly
- no publishing yet

## Phase 2. Safe ingestion

Build:

- RSS
- Google News RSS
- controlled scraping
- canonical URL normalization
- source testing tools

Exit condition:

- items land in queue
- duplicates are blocked

## Phase 3. Categorization and queue workflow

Build:

- keyword + source + AI categorization
- queue states
- review actions
- admin queue screen

Exit condition:

- admin can approve/reject items before AI publish

## Phase 4. AI rewrite engine

Build:

- provider abstraction
- prompt profiles
- structured output validation
- cost/token logging
- fallback provider

Exit condition:

- queue items produce valid `de/uk/en` rewrite packages

## Phase 5. Publishing integration

Build:

- post creation
- translation linking
- category and tag mapping
- SEO fields
- image and source meta
- breaking/sponsored/video meta

Exit condition:

- posts appear correctly in current home/archive/single layout

## Phase 6. Manual/editor workflow

Build:

- generate from URL
- AI edit
- SEO optimize
- manual publish

Exit condition:

- editor can use plugin without cron

## Phase 7. Compliance and production hardening

Build:

- legal policy enforcement
- source-specific attribution
- audit trail
- run logs
- retries
- rate limits
- health checks

Exit condition:

- safe enough for controlled production rollout

## Phase 8. Optional later additions

- social auto-post
- event ingestion as structured event objects
- newsletter export
- source-health alerts
- editorial scoring and ranking

## 10. What to keep from prototype vs what to rewrite

### Keep and refactor

- source table approach
- queue table approach
- logger/stats concept
- cron separation
- provider abstraction
- categorizer concept
- publisher + Polylang linking idea
- compliance module idea
- manual mode idea

### Rewrite or harden

- bootstrap and module loading
- AI service boundary
- manual mode reflection calls
- compliance HTML output
- source legality policy
- category mapping to real site contract
- prompt design
- admin UX
- dedupe against live WP content
- destination-aware excerpt/title generation

## 11. Final recommendation

Do not continue from the prototype as a “nearly finished plugin”.

Treat it as:

- a strong architectural draft
- a reusable module library
- a source of ideas and partial code

The real production plugin should be built as:

- `EuroPulse AutoPilot v2`
- using the current prototype selectively
- but driven by the already fixed current site contract

That is the only way to avoid creating a second parallel publishing logic that breaks the frontend again.

## 12. Immediate next engineering step

The correct next step is not coding the whole plugin blindly.

It is:

1. freeze this plan as the build contract
2. define the exact v2 module/file structure
3. implement Phase 1 only
4. test activation, tables, settings, source registry and queue foundation on this server

If Phase 1 is done correctly, the rest can be added without destabilizing the site.
