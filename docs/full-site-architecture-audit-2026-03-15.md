# EuroPulse Full Site Architecture Audit

Date: 2026-03-15

## Scope

Full audit of:
- WordPress runtime
- active theme and plugins
- MU-plugin custom logic
- frontend CSS/JS layers
- multilingual rendering
- slider/ticker/meta systems
- caching and object cache
- smoke/crawl/accessibility/stress checks

## Active Runtime

- Theme:
  - `blocksy`
- Active plugins:
  - `blocksy-companion`
  - `complianz-gdpr`
  - `koko-analytics`
  - `limit-login-attempts-reloaded`
  - `polylang`
  - `redis-cache`
  - `seo-by-rank-math`
  - `updraftplus`
- MU-plugin:
  - `europulse-foundation.php`
- Drop-in:
  - `object-cache.php`

## Custom Frontend Sources

Primary custom logic:
- `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation.php`

Legacy style layer:
- `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation.css`

Authoritative normalization layer:
- `/var/www/europulse/public/wp-content/mu-plugins/europulse-normalize.css`

## Findings

### 1. Single real source of custom runtime

Almost all site-specific logic is concentrated in the MU-plugin.

This is good for control, but risky because:
- PHP rendering rules
- JS behavior
- multilingual filters
- search tweaks
- canonical logic
- slider and ticker logic
- ad slot output
- media/player behavior

all live in one file.

### 2. Main technical debt source

The biggest instability source was not plugins or theme templates, but legacy CSS in:
- `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation.css`

That file contains a long history of overrides and partial rewrites.

### 3. Normalized layer now exists

Authoritative final overrides are now intentionally concentrated in:
- `/var/www/europulse/public/wp-content/mu-plugins/europulse-normalize.css`

This is now the intended source of truth for:
- ticker timing
- logo cleanup
- latest block sizing
- story meta system
- slider badge/meta layout
- section equal heights
- mobile search
- back-to-top button

### 4. Slider meta conflict root cause

The slider was unstable because generic card metadata and slider-specific badge logic were mixed together.

Resolution:
- slider metadata no longer reuses generic card link-layout
- slider now uses its own dedicated meta markup and layout
- dot-before-first-rubric rule is preserved visually

### 5. Logo conflict root cause

Logo had conflicting old and new pseudo-element logic.

Resolution:
- `Euro` and `Pulse` wordmark preserved
- extra moving dot removed
- pulse line remains

## Hook Audit

Repeated hooks found in MU-plugin:

- `add_action('blocksy:header:after', ...)` x2
  - intentional: ticker and additional header output
- `add_action('wp_enqueue_scripts', ...)` x2
  - intentional split: frontend assets and video player assets
- `add_action('wp_footer', ...)` x2
  - intentional split: search popover markup and client-side JS
- `add_filter('the_content', ...)` x2
  - currently intentional but should be merged later:
    - ad insertion
    - video poster normalization

No harmful duplicate shortcode registrations were found.

## CSS Audit

Selector duplication summary:

- `europulse-normalize.css`
  - duplicate selectors: `0`
- `europulse-foundation.css`
  - still large legacy file
  - duplicate/conflicting slider badge rules were partially removed in this pass

## Current Verified Rules

### Story Meta

Unified system:
- date first
- categories second
- dot before first category
- dot between categories
- `Anzeige/Advertisement/Реклама` excluded from category-dot system

### Slider

Unified system:
- dedicated slider meta layer
- badge chip separated from category row
- logo no longer loses `Euro`
- hero no longer uses unstable generic term-link layout

### Ticker

Unified system:
- ticker always visible
- duplicated marquee groups
- no language-specific speed drift
- desktop and mobile can differ by viewport only

## Test Results

### Smoke

Passed:
- DE home desktop
- DE home mobile
- UK home mobile
- EN home desktop
- DE post desktop

Verified:
- ticker exists
- latest cards equal height
- `Deutschland / Ukraine / Community` populated
- back-to-top exists
- mobile sections are not collapsed to a single story

### Crawl

- internal links sampled: `120`
- broken links: `0`

### Accessibility

`axe` on key pages:
- no violations in tested set

### Stress

Cached homepage:
- `1500` requests
- concurrency `50`
- about `164 req/s`
- all `200`
- all cache `HIT`

Mixed uncached dynamic:
- `500` requests
- concurrency `20`
- about `10.8 req/s`
- all `200`
- all cache `MISS`

## What Was Cleaned In This Pass

- slider meta moved to dedicated structure
- logo duplicate rule conflict fixed
- old slider badge duplicate chip rules removed from legacy CSS
- caches reloaded and FastCGI cache cleared after changes

## Remaining Technical Debt

Still not clean enough:
- `europulse-foundation.css` remains too large and should be reduced further
- some behavior is still split between:
  - legacy CSS
  - normalize CSS
  - MU-plugin render layer
- `the_content` filters should be consolidated
- responsive system still needs a formal external-reference-backed redesign pass

## Next Cleanup Steps

1. Continue reducing legacy CSS safely by moving surviving authoritative rules into `normalize`.
2. Merge duplicate `the_content` filters in MU-plugin.
3. Build one formal responsive system from official references:
   - MDN
   - web.dev
   - WCAG
4. Re-run full smoke/crawl/accessibility/stress after responsive normalization.
