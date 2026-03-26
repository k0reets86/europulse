# EuroPulse AutoPilot: Reference Patterns To Borrow

Last updated: 2026-03-15

This document records concrete implementation patterns worth borrowing for the future `EuroPulse AutoPilot v2`.

It is not a copy list. It is a shortlist of practical patterns that fit the current EuroPulse WordPress stack.

## 1. Background jobs and queue engine

### Recommended primary pattern

Use **Action Scheduler** as the main production queue engine.

Why:

- built specifically for WordPress plugin distribution
- traceable and battle-tested
- supports delayed and repeatable actions
- designed for large queues without requiring custom server daemons

What to borrow:

- scheduled action model instead of custom raw WP-Cron-only orchestration
- per-hook job model
- admin visibility of jobs
- retryable background workflow
- CLI-friendly scaling path

How it fits EuroPulse:

- collection jobs
- URL resolution jobs
- AI rewrite jobs
- image sideload jobs
- publish jobs
- retry jobs

Source:

- Action Scheduler GitHub: https://github.com/woocommerce/action-scheduler
- Action Scheduler usage docs: https://actionscheduler.org/usage/

### Recommended secondary pattern

Use **WP Background Processing** only for small async admin tasks, not as the core production queue.

Good use cases:

- “test source now”
- “rebuild SEO fields”
- “refresh one queue item”
- “recompute one translation package”

Source:

- https://github.com/deliciousbrains/wp-background-processing

## 2. RSS / Atom ingestion

### Recommended parser

Use **WordPress SimplePie** / bundled feed parsing first.

Why:

- WordPress-native
- mature feed parsing
- handles RSS and Atom
- keeps plugin closer to core expectations

What to borrow:

- standards-compliant feed normalization
- namespace handling
- date/author/media extraction

Fallback:

- keep custom XML cleanup for malformed feeds
- source-specific adapters only where needed

Source:

- SimplePie repo: https://github.com/simplepie/simplepie
- Feedzy mirror showing WP-native use of SimplePie: https://github.com/wp-plugins/feedzy-rss-feeds

## 3. Missing-feed / custom ingestion strategy

### Recommended pattern

Do not build a universal fragile scraper inside the plugin.

Instead:

- use adapter classes per source family
- for missing feeds, allow optional external bridge/service approach

Useful reference pattern:

- **RSS-Bridge** as a conceptual model for adapter-per-site bridging

What to borrow:

- “bridge” abstraction
- per-site extraction rules
- not pretending one selector engine fits everything

Important:

- do not bundle RSS-Bridge wholesale into the plugin
- use it as architectural inspiration only

Source:

- https://github.com/RSS-Bridge/rss-bridge

## 4. Google News ingestion

### Recommended pattern

Keep **Google News RSS** as the main low-risk discovery layer for commercial publisher content.

What to borrow:

- fetch from Google News RSS
- normalize title/source
- resolve canonical final URL
- avoid direct publisher scraping by default

Implementation note:

- resolver should be its own service with retry/backoff
- store:
  - raw Google URL
  - resolved canonical URL
  - final hostname

Reference:

- Google News redirect discussion and resolver approach:
  https://stackoverflow.com/questions/79444019/how-to-resolve-google-news-redirects-to-get-the-final-article-url-using-axios

## 5. Manual data mapping / importer UX

### Recommended pattern

Borrow the **mapping mindset** from Import WP, not the whole importer.

What to borrow:

- clear “record mapping” concept
- import field mapping UI
- remote attachment reuse logic
- attachment dedupe before sideloading

How it fits EuroPulse:

- source parser configuration
- scrape-rule field mapping
- image reuse instead of redownloading identical media
- future XML/CSV or admin bulk import

Source:

- https://github.com/importwp/importwp

## 6. Media ingestion

### Recommended pattern

Use **WordPress sideload pipeline**, not custom file hacks.

Core functions:

- `download_url()`
- `media_handle_sideload()`
- `media_sideload_image()`

What to borrow:

- proper temp download handling
- normal attachment creation
- built-in image size generation
- standard media library integration

Source:

- https://developer.wordpress.org/reference/functions/media_sideload_image/

### Recommended plugin-level media rules

- dedupe by source URL and file hash where possible
- store original remote URL
- store license/credit
- store photographer/source
- reuse existing attachment if same source already imported

This is inspired by Import WP’s attachment reuse behavior.

## 7. Image sourcing APIs

### Pexels

What to borrow:

- direct search endpoints for photos and video
- explicit attribution storage
- use of API key auth

Important:

- if used, store both media URL and photographer attribution
- add explicit credit support in publisher output

Sources:

- https://www.pexels.com/api/
- https://www.pexels.com/api/documentation/

### Unsplash

Useful, but stricter.

What to borrow:

- image search and sizing model
- responsive image URL logic

Important caveat:

- Unsplash API requires hotlinking / guideline compliance for many uses
- do not blindly sideload everything as local copies without policy review

Recommendation:

- prefer Pexels first for simpler operational model
- keep Unsplash as optional provider only

Sources:

- https://unsplash.com/documentation
- https://help.unsplash.com/en/articles/2511245-unsplash-api-guidelines

## 8. Admin dashboards and queue tables

### Recommended pattern

Use **WP_List_Table** patterns for:

- sources
- queue
- logs
- runs
- duplicates review

What to borrow:

- pagination
- filtering
- bulk actions
- row actions
- standard WordPress admin behavior

Warning:

- `WP_List_Table` is technically marked private in core docs
- still the practical WordPress-native admin pattern when used carefully

Source:

- https://developer.wordpress.org/reference/classes/wp_list_table/

## 9. SEO integration

### Recommended pattern

Keep using direct Rank Math meta writes, but formalize them behind one service.

What to borrow from Rank Math docs:

- support custom field driven metadata
- allow metadata templates where needed
- support schema/image inclusion later

How it fits EuroPulse:

- one service writes:
  - title
  - description
  - focus keyword
  - optional schema-related fields later

Sources:

- Rank Math ACF/custom field integration:
  https://rankmath.com/kb/advanced-custom-fields/
- Rank Math advanced mode:
  https://rankmath.com/kb/advanced-mode/

## 10. Queue design and observability

### Recommended pattern

Borrow queue concepts from real job systems, but implement them in a WordPress-native way.

What to borrow conceptually:

- separate queues by job type
- retries
- timeout awareness
- priority
- failure tracking
- manual retry

Reference concepts:

- php-resque:
  https://github.com/mjphaynes/php-resque

Do not use it as the core runtime unless we intentionally move away from Action Scheduler. For EuroPulse, Action Scheduler remains the better main path.

## 11. Content extraction from article pages

### Recommended pattern

Do not rely only on regex or naive DOM selectors.

Use:

- source-specific selectors where known
- fallback article extraction service

Potential future direction:

- article readability layer
- server-side extractor microservice if needed later

Practical note:

- many PHP readability ports are weak or abandoned
- if readability becomes necessary at scale, it may be cleaner as an external extraction worker rather than a heavy WordPress plugin dependency

Reference:

- readability.php exists but is abandoned:
  https://github.com/andreskrey/readability.php

Conclusion:

- do not anchor the first plugin version on readability-heavy extraction
- prefer feed/source adapters and factual extraction first

## 12. What to adopt into EuroPulse AutoPilot v2

### Adopt directly

- Action Scheduler as primary queue runner
- SimplePie / WP-native feed parsing
- WordPress sideload media flow
- WP_List_Table admin pattern
- direct Rank Math meta service

### Adopt as design inspiration

- RSS-Bridge adapter mindset
- Import WP field mapping mindset
- php-resque queue observability concepts

### Adopt carefully / optional

- Pexels API
- Unsplash API
- external readability extraction later

## 13. Final architecture recommendation after reference review

The plugin should be built as:

- WordPress-native first
- queue-driven
- source-adapter based
- compliance-aware
- contract-bound to the already fixed EuroPulse frontend

The biggest practical architectural choice from this reference sweep is:

**Replace the prototype’s “plain custom cron pipeline” with Action Scheduler as the central job orchestration layer.**

That one decision will improve:

- reliability
- retry behavior
- observability
- scaling to larger queues
- compatibility with WordPress hosting realities
