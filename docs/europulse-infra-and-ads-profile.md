# EuroPulse Infrastructure And Ad Profile

Last updated: 2026-03-14

## Current Server Baseline

- CPU: `2 vCPU`
- RAM: `3.7 GiB`
- Swap: `2 GiB`
- Disk: `38 GiB` total
- Disk used: about `5.1 GiB`
- Disk free: about `31 GiB`
- Stack:
  - `nginx 1.24`
  - `PHP 8.3`
  - `MariaDB 10.11`
  - `Redis 7`

## Caching Stack Now Enabled

- Full-page cache:
  - `nginx FastCGI cache`
  - cache key includes URL and Polylang language cookie
  - bypass for logged-in users, admin, preview, login, XML-RPC, comments/password cookies
- Object cache:
  - `Redis Object Cache` plugin
  - client: `phpredis`
  - host: `127.0.0.1:6379`
- Analytics:
  - `Koko Analytics`

## Measured Current Throughput

### Without full-page cache

- dynamic homepage benchmark before cache:
  - about `9.5 req/s`
  - `p50` about `1184 ms`
  - `p95` about `1767 ms`

### With full-page cache warmed

- local benchmark on warmed homepage:
  - about `1234 req/s`
  - `p50` about `7 ms`
  - `p95` about `18 ms`

Note:
- the warmed cached result shows the effect of page cache on anonymous traffic
- real internet traffic will still depend on CDN, bandwidth, bots, search, and admin/editor load

## CDN Status

- Static asset CDN is **not enabled yet**
- Reason:
  - no final domain
  - no external CDN account configured yet
- Site is now CDN-ready:
  - static assets already have browser caching
  - ad assets use versioned URLs
  - page cache is in front of PHP

Recommended CDN next step after domain:
- `Cloudflare` for DNS + CDN + WAF + edge caching

## Media Storage And Growth

- Media files are stored on server filesystem:
  - `/var/www/europulse/public/wp-content/uploads`
- WordPress stores only metadata and attachment records in DB

Current uploads snapshot:
- uploads size: about `100 MB`
- files: `176`
- attachments in WordPress: `29`

Practical storage rule for planning:
- current average aggregate disk per attachment with generated sizes is about `3.4 MB`

Growth estimate for photo-first workflow:
- `20` posts/day with `1` image each:
  - about `68 MB/day`
  - about `2.0 GB/month`
  - about `24 GB/year`
- `30` posts/day with `1` image each:
  - about `102 MB/day`
  - about `3.1 GB/month`
  - about `37 GB/year`

Video rule:
- do not plan long-term local archival video storage on this server
- for scale use object storage:
  - `Cloudflare R2`
  - `Backblaze B2`
  - `Bunny Storage`
  - S3-compatible storage

## Traffic Growth Plan

### Literal `1 / 5 / 10 / 20` visitors per day

- no infrastructure changes needed

### Practical plan for `1k / 5k / 10k / 20k` visitors per day

- `1k/day`
  - current server is enough
  - keep backups and updates in order

- `5k/day`
  - full-page cache required: enabled
  - object cache recommended: enabled
  - CDN for static assets required: prepare now, enable after final domain
  - analytics plugin available

- `10k/day`
  - CDN required
  - monitor `nginx`, `php-fpm`, `MariaDB`, `Redis`
  - consider moving media to object storage

- `20k/day`
  - plan upgrade to at least `4 vCPU / 8 GB RAM`
  - keep full-page cache + object cache + CDN
  - move media off local disk
  - add external uptime and resource monitoring

## Advertising Placement Rules

### What was observed from publisher patterns

- `UKR.NET` and similar portal layouts use outer, visually separate ad zones outside the main editorial column for branding or sitebar-style placements
- the editorial core remains in a light, readable central column
- darker ad creatives can work in those outer zones because the contrast makes the ad feel separate from editorial content instead of mixed into it

### Official/technical references used

- `UKR.NET / Adline` branding requirements indicate side-branding style placements with dedicated left/right outer areas and different layouts for `1280–1366px` and `1366–1600px` viewports
- `UKR.NET / Adline` technical requirements also expect:
  - HTTPS landing URLs
  - `<meta name="ad.size" content="width=...,height=...">` for HTML5 creatives
  - `clickTag` support
  - max archive size about `200 KB` for HTML5 upload package
  - rounded-corner safe zones because parts of the interface use radius clipping
- `UKR.NET` news-feed headline rules are also relevant for autopublishing:
  - no source name in title
  - no date/time in title
  - no category names in title
  - no SEO filler words
  - no service markers like `video`, `updated`, `live`
- IAB fixed sizes still relevant for booking and creative handoff
- German labeling requires clearly recognizable ad marking, typically `Anzeige` or `Werbung`

### EuroPulse ad inventory to prepare

- Header leaderboard:
  - primary sizes: `970x250`, `970x90`, fallback `728x90`
- In-content homepage banner:
  - `970x250`
- Right rail desktop:
  - `300x600`
  - fallback `300x250`
- In-article inline slot:
  - `300x250`
  - optional wide fallback `728x90` when layout supports it
- Mobile banner:
  - `320x100`
  - fallback `300x250`
- Optional desktop page-skin / outer rails:
  - reserve only for wide viewports
  - activate from about `1600px+` viewport width
  - do not squeeze the editorial column to make skin ads fit

### Outer rail / page-skin rule

- keep central editorial max width stable
- left/right branding or sitebar zones must live outside the core content column
- on smaller laptops do not force side rails
- dark or high-contrast creatives are acceptable there because the ad remains visually separated from the white newsroom canvas
- viewport guidance from the observed UKR.NET approach:
  - `1280–1366px`: only narrow branding/sitebar zones are reasonable
  - `1366–1600px`: wider side rails become realistic
  - for EuroPulse keep outer rails disabled below about `1600px` unless a dedicated page-skin format is sold

### Marking / labeling rule

- banner/display ad:
  - label with localized ad label at the top-left of the slot:
    - `Anzeige`
    - `Реклама`
    - `Advertisement`
- sponsored article / paid content:
  - label both in teaser and inside the article
  - keep this separate from editorial category metadata
- do not use unclear shorthand like only `ad`

### UX rule

- no empty ad holes when no campaign is active
- ad click opens in a new tab
- ad label must be visible before interaction
- ad visuals can be animated, but not in a way that breaks reading flow of adjacent editorial content

## Statistics / Monitoring

- WordPress analytics plugin enabled:
  - `Koko Analytics`
- good for quick publisher-side overview inside WP
- for future production reporting also consider:
  - server metrics
  - CDN analytics
  - Search Console

## Files And Config

- Cache config:
  - `/etc/nginx/conf.d/europulse-fastcgi-cache.conf`
  - `/etc/nginx/sites-enabled/europulse`
- Redis / WP config:
  - `/var/www/europulse/public/wp-config.php`
- WordPress theme/system logic:
  - `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation.php`
  - `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation.css`
