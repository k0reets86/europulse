# EuroPulse Site SEO/LLM Audit - 2026-06-06

## Scope

- Live domain: `https://europulse.today`
- Audited 70 URLs from home pages, page sitemap, category sitemap, news hubs, and recent news URLs.
- Rendered key templates on desktop and mobile: DE/EN/UK home, news hubs, populated categories, and empty opinion categories.
- Checked robots, sitemaps, llms.txt, canonical URLs, meta robots, meta descriptions, H1 structure, visible empty blocks, old IP references, and internal `?page_id=` links.

## External Guidance Used

- Google: sitemaps should include canonical URLs that should appear in Search; sitemaps can also carry image/news/localized data.
  https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap
- Google: localized versions should be explicitly connected with `hreflang`/localized URL signals.
  https://developers.google.com/search/docs/specialty/international/localized-versions
- Google: canonical and sitemap signals should not point to HTTP when HTTPS is canonical.
  https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls
- Google: robots meta directives affect Search, Discover, AI Overviews, and AI Mode handling.
  https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag
- Google: Article/NewsArticle structured data helps Search understand news pages.
  https://developers.google.com/search/docs/appearance/structured-data/article
- Bing: sitemaps improve URL discovery, accuracy, and freshness; list canonical URLs.
  https://www.bing.com/webmaster/help/Webmaster-Guidelines-30fba23a

## Fixed During Audit

1. `robots.txt`
   - Replaced stale `http://204.168.148.47` sitemap URLs with:
     - `https://europulse.today/sitemap_index.xml`
     - `https://europulse.today/news-sitemap.xml`
   - Added:
     - `https://europulse.today/localized-category-sitemap.xml`

2. `llms.txt`
   - Updated article URL patterns after domain cutover:
     - DE: `https://europulse.today/<slug>/`
     - UK: `https://europulse.today/uk/<slug>/`
     - EN: `https://europulse.today/en/<slug>/`
   - Added language news hubs and localized category sitemap.
   - Updated stale contact/about references and date.

3. Home H1 structure
   - Fixed top slider markup in `wp-mu-plugins/europulse-foundation/includes/render.php`.
   - Slider headlines now render as `h2`, not `h1`.
   - Verified live:
     - `/`: `h1=1`
     - `/en/`: `h1=1`
     - `/uk/`: `h1=1`

4. Empty home block fallback
   - Fixed `europulse_home_section_ids()` in `wp-mu-plugins/europulse-foundation/includes/core.php`.
   - If curated/home pool is empty for a rare category, the module now falls back to latest posts from the localized category archive.
   - Verified DE/EN/UK home Community/Спільнота block now renders instead of leaving a blank column.

5. Category meta descriptions
   - Added/updated term descriptions for non-empty core categories across DE/EN/UK.
   - Verified examples:
     - `/category/sport/`: description length 118, `h1=1`
     - `/category/welt/`: description length 123, `h1=1`
     - `/en/category/politics/`: description length 113, `h1=1`
     - `/uk/category/polityka/`: description length 97, `h1=1`

6. Internal links
   - Replaced published content links like `/?page_id=...` with canonical pretty permalinks.
   - Fixed EN/UK service-page links so they point to same-language pages.
   - Verified published content count with `?page_id=` links: `0`.

7. Localized category sitemap
   - Added static `localized-category-sitemap.xml` with 30 non-empty EN/UK category URLs.
   - Published at:
     - `https://europulse.today/localized-category-sitemap.xml`

## Findings After Fixes

- Public home pages now have one H1 each and valid meta descriptions.
- Main category pages now have one H1 and useful meta descriptions.
- Empty opinion categories (`Meinung`, `Opinion`, `Думка`) are still accessible but have `robots=follow, noindex`, which is correct until content exists.
- Old IP references are not in public post/page URLs checked for SEO:
  - Remaining `ep_posts` hits are `revision` rows plus one Contact Form 7 config.
  - Remaining `postmeta` hits are media diagnostics (`origin_host`) and contact form mail sender metadata.
- Rank Math category sitemap still lists only DE categories; the new localized category sitemap covers the EN/UK discovery gap.

## Remaining Work

1. Fill empty/niche sections with real content:
   - Opinion/Meinung/Думка should stay `noindex` until the autopilot can safely assign dual-category opinion/analysis items or dedicated sources exist.
   - Community/event extraction still needs source parser repair for MORGEN, House of Resources, Hilfe-UA and similar event sources.

2. Legal/contact readiness:
   - Static legal/contact pages still contain placeholder launch language.
   - Contact Form 7 sender still uses `wordpress@204.168.148.47`; replace only after domain mail/SPF/SMTP decision.

3. Dynamic sitemap improvement:
   - Current localized category sitemap is static. A cleaner long-term fix is a dynamic MU-plugin sitemap endpoint or Rank Math integration so category lastmod updates automatically.

4. Quality audit:
   - Continue translation/rewrite/source-fidelity checks against original sources for hallucinations, distorted facts, and bad internal article links.

5. Performance:
   - HTTPS FastCGI cache is now effective, but run Lighthouse after the site has a stable crawl window and plugin traffic is normal.
