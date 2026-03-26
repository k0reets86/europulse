# EuroPulse Publishing Profile

Last updated: 2026-03-15

## Core

- CMS: WordPress
- Theme: `Blocksy`
- Primary public language: `de`
- Public languages: `de`, `uk`, `en`
- Admin language for main admin: `ru_RU`
- Active foundation layer:
  - [`europulse-foundation.php`](/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation.php)
  - [`europulse-normalize.css`](/var/www/europulse/public/wp-content/mu-plugins/europulse-normalize.css)

## Active Plugins

- `blocksy-companion`
- `complianz-gdpr`
- `koko-analytics`
- `limit-login-attempts-reloaded`
- `polylang`
- `redis-cache`
- `seo-by-rank-math`
- `updraftplus`

## Information Architecture

### Primary menu

- Startseite / Головна / Home page
- Deutschland / Німеччина / Germany
  - München / Мюнхен / Munich
  - Bayern / Баварія / Bavaria
- Ukraine / Україна / Ukraine
- Europa / Європа / Europe
- Politik / Політика / Politics
- Wirtschaft / Економіка / Economy
- Leben in Deutschland / Життя в Німеччині / Life in Germany
- Kultur / Культура / Culture
- Sport / Спорт / Sport
- Community / Спільнота / Community
  - Veranstaltungen / Події / Events
  - Ukrainische Initiativen / Українські ініціативи / Ukrainian Initiatives
  - Vereine & Projekte / Обʼєднання та проєкти / Associations & Projects
  - Treffen & Networking / Зустрічі та нетворкінг / Meetings & Networking

### Homepage order

1. Hero slider
2. Weitere Themen
3. Neueste Meldungen
4. Deutschland
5. Ukraine
6. Europa
7. Politik
8. Wirtschaft
9. Leben in Deutschland
10. Community
11. Wichtig im Blick

## Category Mapping

### German

- `14` Deutschland
- `1` München, parent `14`
- `12` Bayern, parent `14`
- `16` Ukraine
- `18` Europa
- `22` Politik
- `24` Wirtschaft
- `26` Leben in Deutschland
- `28` Kultur
- `30` Sport
- `46` Community
- `48` Veranstaltungen, parent `46`
- `50` Ukrainische Initiativen, parent `46`
- `52` Vereine & Projekte, parent `46`
- `54` Treffen & Networking, parent `46`

### Ukrainian

- `184` Німеччина
- `187` Мюнхен, parent `184`
- `189` Баварія, parent `184`
- `191` Україна
- `194` Європа
- `197` Політика
- `200` Економіка
- `203` Життя в Німеччині
- `206` Культура
- `209` Спорт
- `212` Спільнота
- `215` Події, parent `212`
- `217` Українські ініціативи, parent `212`
- `219` Обʼєднання та проєкти, parent `212`
- `221` Зустрічі та нетворкінг, parent `212`

### English

- `151` Germany
- `154` Munich, parent `151`
- `156` Bavaria, parent `151`
- `157` Ukraine
- `159` Europe
- `162` Politics
- `165` Economy
- `168` Life in Germany
- `171` Culture
- `249` Sport
- `173` Community
- `176` Events, parent `173`
- `178` Ukrainian Initiatives, parent `173`
- `180` Associations & Projects, parent `173`
- `182` Meetings & Networking, parent `173`

## System Rules

### Story meta

- One order only: `date`, then `categories`
- Cards and slider use two-line meta:
  - line 1: date
  - line 2: categories
- Native archive/single `entry-meta` also follows one visual standard
- Dot marker appears before every category item
- `Anzeige / Реклама / Advertisement` never receives a category dot
- Story meta typography:
  - desktop cards: `8.5px`, `line-height 1.22`
  - native archive/single meta: `10px`, `line-height 1.34`
  - mobile cards: `8px`, `line-height 1.2`
  - single-post mobile meta: effectively `10px`

### Breaking / sponsored chips

- Ticker label desktop:
  - font `11px`
  - dot `8px`
  - gap `7px`
- Ticker label mobile:
  - font `8px`
  - dot `6px`
  - gap `4px`
- Hero chip desktop:
  - font `11px`
  - dot `8px`
  - right offset `18px`
  - max width `136px`
- Hero chip mobile:
  - font `8px`
  - dot `6px`
  - right offset `10px`
  - max width `74px`
- Card chip:
  - font `9px`
  - dot `6px`
  - smaller than hero/ticker chip
- Priority:
  1. sponsored
  2. breaking
  3. top story

### Editorial publication minimum

- Every publication must be a full newsroom article, not a raw snippet.
- Every publication must include:
  - finished headline;
  - lead / excerpt;
  - structured multi-paragraph body;
  - SEO title;
  - meta description;
  - slug;
  - focus keywords;
  - featured media.
- Media priority:
  1. source image;
  2. editorial fallback image;
  3. inline media inside article body.
- Quotes should be used when a source contains meaningful direct speech, but not mechanically.
- Articles must read as smooth newsroom text even in `developing` and `analysis` formats.

### News selection contract

- Queue should contain only items with real public value or clear practical value.
- Hard reject before queue:
  - routine official visits;
  - appointment notes;
  - condolence-only or ceremonial items;
  - generic calendar/start pages;
  - trivial local noise without reader impact;
  - “where to watch” or generic listing pages;
  - press releases without a new fact or consequence.
- Strong positive signals:
  - transport disruptions and timetable changes;
  - migration, documents, benefits, labour market;
  - housing, fuel, inflation, taxes, economy;
  - votes, reforms, sanctions, security;
  - Ukraine developments with wider consequences;
  - community items with direct practical usefulness.

### Header

- Utility bar:
  - font `12px` desktop, `10px` mobile
  - Berlin date/time only
  - language switcher `DE / UKR / EN`
  - social icons inline
- Main header:
  - row height `74px` desktop, `60px` mobile
  - `EuroPulse` wordmark with animated pulse line
  - no extra logo icon
- Search:
  - desktop uses compact popover
  - mobile search lives in offcanvas menu
- Back-to-top:
  - fixed button
  - desktop `46x46`
  - mobile `42x42`

### Hero slider

- Source: latest 5 published posts in current language
- Layout:
  - desktop: image left, text panel right
  - mobile: image top, text panel below
- Headline:
  - desktop font `clamp(19px, 1.28vw, 24px)`
  - mobile font `clamp(19px, 5.6vw, 24px)`
  - very small mobile `16px`
  - visual box up to 4 lines
- Excerpt:
  - desktop: 3 lines
  - mobile: 4 lines
  - very small mobile: 3 lines
- Meta must not collide with hero chip
- Headline and excerpt must be complete editorial thoughts, not raw oversized titles

### Weitere Themen

- 3 cards
- desktop grid: `repeat(3, 1fr)`
- tablet grid: `repeat(2, 1fr)`
- mobile grid: `1 column`
- Card image:
  - aspect ratio `16 / 10`
  - height `128px`
- Title:
  - desktop `16px`
  - mobile `17px` for lead/secondary condensed cards
- Excerpt:
  - desktop `11px`
  - hidden or shortened naturally by card layout on smaller screens

### Neueste Meldungen

- Source: latest 6 published posts in current language
- desktop grid: `3 columns`
- tablet/mobile:
  - `2 columns` under `1000px`

### Text budgets

- Title budgets used by the publication generator:
  - `de`: `82` chars
  - `uk`: `92` chars
  - `en`: `82` chars
- Lead/dek budgets come from the live UI profile:
  - slider excerpt limits:
    - `de`: `190`
    - `uk`: `208`
    - `en`: `186`
  - latest-card compact budgets:
    - `de`: `108`
    - `uk`: `96`
    - `en`: `110`
- Generator must respect these budgets so headlines and leads fit homepage cards and slider cleanly.
  - `1 column` under `781px`
- Card size:
  - desktop `158px`
  - mobile `150px`
- Thumb:
  - desktop `92px x 64px`
  - mobile scales but card height stays fixed
- Title:
  - desktop `14px`
  - mobile `13px`
  - 2 lines
- Excerpt:
  - desktop `10.5px`, 3 lines
  - mobile `10px`, 3 lines
- This block must always stay equal-height across cards

### Split section modules

Applies to:

- Deutschland
- Ukraine
- Community

Rules:

- desktop:
  - one lead story with image on left
  - text-only list on right
- tablet/mobile:
  - lead story on top
  - text-only list below
- lead image:
  - aspect ratio `16 / 10`
  - desktop height `168px`
  - mobile height `148px`
- list item title:
  - desktop `14px`
  - mobile `13px`
- list excerpt:
  - desktop `11px`
  - mobile `10.5px`
- list cards must never reintroduce mini-thumbnails on mobile

### Grid section modules

Applies to:

- Europa
- Politik
- Wirtschaft
- Leben in Deutschland

Rules:

- desktop `2-column` compact grid
- tablet `2-column`
- mobile `1-column`
- compact card target height:
  - desktop `303px`
  - mobile `328px`

### Utility card

Applies to `Wichtig im Blick`

- Intro copy:
  - desktop `11px`, `line-height 1.32`
  - mobile `10.5px`, `line-height 1.28`
- list items can show thumbs
- `Breaking` chip in this block uses card-chip size, not hero size

### Archive pages

- Cards use one standardized Blocksy/news layout
- meta separators are normalized to `•`
- mobile card media height:
  - default `188px`
  - tightened in compact mobile pass to `176px`
- archive title mobile:
  - around `20px`

### Single post

- mobile page title:
  - main small-screen target `27px`
  - extra-small target `21px`
- page description/dek:
  - `14px`
- body:
  - main small-screen target `14.5px`, `line-height 1.66`
- figcaption:
  - `11px`
- video uses WordPress core `MediaElement`

## Responsive System

### Breakpoints

- `<= 999.98px`
- `<= 839.98px`
- `<= 781px`
- `<= 689.98px`
- `<= 599.98px`
- `<= 389.98px`

### Verified widths

- phones:
  - `375`
  - `390`
  - `428`
- matrix also exists in:
  - [`full-viewport-matrix-2026-03-15.json`](/root/projects/europulse/docs/full-viewport-matrix-2026-03-15.json)

### Mobile rules that matter for automation

- no horizontal overflow
- `Deutschland / Ukraine / Community` stay as lead + text list
- `Neueste Meldungen` cards keep equal height
- search exists in mobile menu
- ticker always remains visible
- hero chip never overlaps hero meta

## Content Rules For Automation

### Required

- editorial headline, complete thought, no trailing ellipsis
- excerpt/dek must end cleanly
- at least 1 primary category in correct language taxonomy
- featured image
- source block or explicit source paragraph
- image credit
- SEO fields

### Supported meta fields

- `europulse_breaking`
- `europulse_breaking_until`
- `europulse_sponsored`
- `europulse_popular_score`
- `europulse_sources`
- `europulse_video_poster`

### SEO fields

- `rank_math_title`
- `rank_math_description`
- `rank_math_focus_keyword`
- `rank_math_seo_score`

### Search

- language-aware
- matches:
  - `post_title`
  - `post_excerpt`
  - `post_content`

### Video

- video detection from `<video>` / `wp-block-video`
- poster meta: `europulse_video_poster`
- fallback poster: featured image
- cards can show play indicator

### Ads

- ad click opens in new tab
- labels:
  - `Anzeige`
  - `Реклама`
  - `Advertisement`
- ad label is separate from category meta

## Files To Use

- Runtime hooks:
  - [`europulse-foundation.php`](/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation.php)
  - [`core.php`](/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation/includes/core.php)
  - [`front-hooks.php`](/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation/includes/front-hooks.php)
  - [`render.php`](/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation/includes/render.php)
  - [`content-seo-hooks.php`](/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation/includes/content-seo-hooks.php)
- Styles:
  - [`europulse-normalize.css`](/var/www/europulse/public/wp-content/mu-plugins/europulse-normalize.css)
- QA:
  - [`smoke_check.js`](/root/projects/europulse/scripts/smoke_check.js)
  - [`deep_ui_audit.js`](/root/projects/europulse/scripts/deep_ui_audit.js)
  - [`full_viewport_matrix.js`](/root/projects/europulse/scripts/full_viewport_matrix.js)
