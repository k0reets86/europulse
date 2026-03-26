## EuroPulse Publishing Rules Final

### Meta
- Order: `date -> categories`
- Term format: every category is prefixed with `•`
- `Anzeige/Advertisement/Реклама` has no category dot
- Card/archive/single meta uses one compact visual system

### Slider
- Hero badge stays top-right in its reserved zone
- Date and categories stay in a separate meta block
- Title and excerpt are context-shaped, not raw dumps
- Breaking label is independent from category meta

### Excerpt Limits
- Slider:
  - `de`: `190`
  - `uk`: `208`
  - `en`: `186`
- Latest:
  - `de`: `108`
  - `uk`: `96`
  - `en`: `110`
- Default:
  - `de`: `140`
  - `uk`: `148`
  - `en`: `142`

### Content Flags
- `europulse_breaking`
- `europulse_breaking_until`
- `europulse_sponsored`
- `europulse_video_poster`
- `europulse_sources`

### SEO
- Canonical for home/archive/search/tag/category is self-forced
- Open Graph URL follows canonical
- JSON-LD URL fields follow canonical
- Dates are localized by current site language

### Ads
- Labels:
  - `Anzeige`
  - `Advertisement`
  - `Реклама`
- Ad links open in new tab with `rel="noopener noreferrer sponsored"`
- Supported slots:
  - `header-leaderboard`
  - `sidebar-rail`
  - `article-inline-1`
  - `article-inline-2`
  - `homepage-between-sections`

### Video
- Native WordPress/MediaElement player
- Poster is required for front display

### Responsive
- Authoritative responsive layer lives in `europulse-normalize.css`
- Home/archive/single must follow one breakpoint system
- Mobile menu search must exist
- Back-to-top must exist on all page types
