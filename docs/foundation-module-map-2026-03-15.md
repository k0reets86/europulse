## EuroPulse Foundation Module Map

### Loader
- `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation.php`
- Only boots the module files.

### Core
- `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation/includes/core.php`
- Holds shared helpers:
  - language detection
  - localization strings
  - canonical/public URL logic
  - time/date formatting
  - breaking/sponsored/video checks
  - localized term rendering
  - excerpt/title shaping
  - ad slot rendering

### Front Hooks
- `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation/includes/front-hooks.php`
- Holds low-level integration hooks:
  - enqueue
  - Blocksy footer/menu integration
  - query-loop language translation
  - `post_class`

### Render
- `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation/includes/render.php`
- Holds shortcodes and render-oriented actions:
  - ad slot shortcode
  - most read/latest/home latest
  - section module
  - breaking ticker
  - top slider
  - utility bar
  - header/rail ad placement

### Content / SEO Hooks
- `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation/includes/content-seo-hooks.php`
- Holds content filters and SEO/head logic:
  - video poster handling
  - inline ad injection
  - widget translation layer
  - search improvements
  - localized post dates
  - term rendering filters
  - Rank Math canonical / Open Graph / JSON-LD overrides
  - single top/bottom content blocks
  - footer scripts and slider/mobile-menu JS

### CSS Authority
- Authoritative layer:
  - `/var/www/europulse/public/wp-content/mu-plugins/europulse-normalize.css`
- Legacy/base layer:
  - `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation.css`

### Rule
- New or corrected visual rules must go into `europulse-normalize.css`.
- `europulse-foundation.css` should only keep remaining base/theme glue until fully retired.
