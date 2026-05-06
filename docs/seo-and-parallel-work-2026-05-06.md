# EuroPulse — SEO state + parallel optimisation pipeline (2026-05-06)

## Что уже сделано (top-tier ready)

### Schema.org structured data (per-article JSON-LD)
- ✅ `NewsArticle` с `articleBody`, `wordCount`, `keywords`, `mentions [Person|Organization|Place]` (из story_card.entities), `about [Thing]` (из story_card.topics), `contentLocation`, `isAccessibleForFree=true`, `speakable`, `thumbnailUrl`
- ✅ `BreadcrumbList`, `WebPage`, `Person` (author), `NewsMediaOrganization` (publisher), `WebSite` — Rank Math автогенерит
- ✅ `inLanguage` на каждой языковой версии (de_DE, uk_UA, en_US)
- ✅ Hook `EPV2_Schema_Enricher` гнёт rank_math/json_ld filter — наш код добавляет всё что Rank Math пропустил

### Sitemaps
- ✅ `/sitemap_index.xml` (Rank Math): post-sitemap1.xml, post-sitemap2.xml, category, page, author
- ✅ `/news-sitemap.xml` (наш `EPV2_News_Sitemap`):
  - per-post Polylang language slug
  - `<news:keywords>` из story_card.tags + post tags
  - `<news:title>`, `<news:publication_date>`
  - `<image:image>/<image:loc>` per URL
  - 48h window per Google News spec
  - Eligibility: review-tier score ≥ 40 OR breaking/top-story flag

### robots.txt
- ✅ `Allow: /` для AI-агентов: GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, anthropic-ai, Claude-Web, PerplexityBot, Perplexity-User, YouBot, Diffbot, Google-Extended, meta-externalagent, cohere-ai
- ✅ `Disallow: /` для скраперов: Bytespider, MJ12bot, DotBot, BLEXBot
- ✅ `Crawl-delay: 30` для Ahrefs/Semrush
- ✅ Sitemap + News-sitemap entries

### Meta + Open Graph + Twitter
- ✅ Canonical URL per language
- ✅ `<link rel="alternate" hreflang>` на DE/UK/EN
- ✅ `<meta name="robots">` `index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1`
- ✅ Open Graph: `og:locale`, `og:type=article`, `og:title`, `og:description`, `og:url`, `og:site_name`, `og:image{width,height,alt,type}`, `article:tag`, `article:section`, `article:published_time`
- ✅ Twitter Card: `summary_large_image`, `twitter:title`, `twitter:description`, `twitter:image`, `twitter:label1`/`data1` (Time to read)

### Server / nginx
- ✅ HTTP security headers: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: geolocation=()/microphone=()/camera=()`
- ✅ `gzip on` (level 5) с правильными MIME-типами
- ✅ FastCGI cache (`X-FastCGI-Cache` header)
- ✅ Asset caching (immutable, max-age=2592000) для css/js/img/woff
- ✅ Redis Object Cache plugin active

### Crawler / agent surface area
- ✅ `/llms.txt` — описывает редполитику + индексационный режим для LLM-агентов
- ✅ `/.well-known/security.txt` (RFC 9116)
- ✅ `/humans.txt`

### Editorial pipeline (rewriter prompt — top-tier E-E-A-T)
- ✅ Inverted pyramid: who/what/when/where в lead 1-2 предложения
- ✅ Короткие абзацы 40-90 слов (поисковые движки читают начала)
- ✅ 2-3 H2 субтитулы на длинных пьесах (HTML `<h2>`)
- ✅ Anti-AI-tells: запрет em-dash spam, "im digitalen Zeitalter", rhetorical-question leads
- ✅ HTML body output (`<p>`/`<h2>`, не Markdown)
- ✅ Title 50-80 chars с primary keyword впереди
- ✅ Strong attribution: source named in lead
- ✅ Story card передаётся в rewriter с key_facts + entities + tone hints

### Story Card (semantic foundation)
- ✅ Один upfront AI-проход на каждой свежей строке → структурный JSON
- ✅ Drives: categorizer override, tags, rewriter context, media search terms, SEO keywords
- ✅ Fields: category{primary,confidence,rationale}, geography, entities{people,orgs,places}, key_facts, topics, tags, search_queries, media_search_terms, media_required, seo, rewrite hints, publishable_estimate

---

## Параллельные оптимизации (что можно/нужно делать сейчас)

### Tier 1: ждут SSL/домен (когда оператор поставит europulse.eu)
- [ ] **HTTPS / SSL cert** — Let's Encrypt через certbot. Без него HSTS, HTTP/2, HTTP/3 не работают.
- [ ] **HTTP/2** в nginx (`listen 443 ssl http2`)
- [ ] **HTTP/3 / QUIC** (опционально — nginx 1.25+)
- [ ] **HSTS header**: `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`
- [ ] **CSP header** — Content-Security-Policy. Сложный, требует тестирования с Rank Math + Polylang + комментариями.
- [ ] **Search Console verification** — добавить домен в Google Search Console + Bing Webmaster Tools + Yandex Webmaster. Submit sitemaps.
- [ ] **IndexNow** — мгновенное уведомление Bing/Yandex/Naver о новых публикациях. Простая интеграция.

### Tier 2: можно делать сейчас
- [ ] **Brotli compression** в nginx (лучше gzip) — `apt install libnginx-mod-http-brotli-static libnginx-mod-http-brotli-filter`
- [ ] **WebP/AVIF auto-conversion** для загруженных изображений — плагин `EWWW Image Optimizer` или `ShortPixel` (или JS WP-Optimize)
- [ ] **Lazy-loading verify** — WP 5.5+ default, проверить что у нас работает
- [ ] **Editorial pages**: `/about/`, `/impressum/` (немецкое legal требование), `/contact/`, `/privacy-policy/`, `/cookie-policy/`, `/editorial-guidelines/`. Для E-E-A-T + Google News approval.
- [ ] **Author bios** с фото для каждой языковой версии — пустой Person Schema без bio downgrade'ит E-E-A-T
- [ ] **Internal linking widget** — "Related articles" в template или плагином Contextual Related Posts
- [ ] **404 page** — SEO-friendly с поиском + link to home
- [ ] **301 redirects** для старых URL когда меняется slug
- [ ] **WordPress security**:
  - Hide WP version (`wp_generator` filter)
  - Disable XML-RPC если не используется
  - Limit Login Attempts уже active
- [ ] **Cron**: заменить wp-cron.php на system cron (быстрее, надёжнее)
- [ ] **Polylang**: убедиться что все категории/таксономии переведены на UK/EN; canonical чтобы НЕ дублировать контент

### Tier 3: контент и редполитика
- [ ] **About / Impressum / V.i.S.d.P.** — обязательные для немецких сайтов
- [ ] **Imprint Schema** (`Person` для V.i.S.d.P. + `Organization` для publisher)
- [ ] **Editorial guidelines** на 3 языках — open source ready
- [ ] **Corrections policy** — Google News favours
- [ ] **Author E-E-A-T**: имя + фото + bio + соцсети для каждого "автора" (даже если AI-driven, у нас должен быть human editor-in-chief named)
- [ ] **Source dossier transparency** — добавить блок "Источники" внизу каждой статьи со списком URL'ов dossier
- [ ] **AI-disclosure label** — Rank Math/Schema поддерживает; уже есть `epv2_settings['show_ai_disclaimer']`, нужно включить
- [ ] **FAQPage Schema** на статьях с QA-структурой (из card.key_facts можно генерить)

### Tier 4: performance + measurement
- [ ] **Core Web Vitals**: LCP, INP, CLS — измерить через PageSpeed Insights + Search Console
- [ ] **Critical CSS inline** — генерация critical-path CSS
- [ ] **Defer non-critical JS**
- [ ] **Self-hosted fonts** (если используются Google Fonts) для приватности + скорости
- [ ] **Image preload** для LCP кандидата (featured image)
- [ ] **DNS prefetch** для CDN/external domains
- [ ] **Plausible/Matomo/Koko Analytics** для cookieless метрик (Koko уже active)
- [ ] **Search Console Core Web Vitals** интеграция

### Tier 5: AI consumption / agent search (forefront)
- [ ] **JSON feed** (`/feed/json/`) для AI-friendly consumption
- [ ] **Atom feed** уже эмитится WP (`/feed/atom/`) — проверить
- [ ] **NewsArticle с `discoveryAt`** для agentic search
- [ ] **`isPartOf` chain**: NewsArticle → CollectionPage (категория) → WebSite
- [ ] **`citation` Schema array** — список dossier URL'ов как `CreativeWork` citations
- [ ] **`retrievedDate`** для оригинала источника
- [ ] **`copyrightHolder` + `copyrightNotice`**
- [ ] **`license` + `acquireLicensePage`**

### Tier 6: продуктовые/UX
- [ ] **AMP** — у Google News перестал быть обязательным, но боост в скорости. Опционально.
- [ ] **PWA / Service Worker** — offline-first для diaspora-аудитории с нестабильной связью
- [ ] **Web Push** notifications (через OneSignal или нативный)
- [ ] **Newsletter** — Mailchimp/Mailerlite интеграция
- [ ] **RSS to Telegram** автопостинг
- [ ] **Twitter/X** автопостинг

---

## Приоритет порядка для следующей сессии

1. **Когда домен поставлен** — SSL + HTTP/2 + HSTS + Search Console + IndexNow (Tier 1)
2. **Параллельно сейчас** — Brotli, WebP, Editorial pages (about/impressum), Author bios (Tier 2-3)
3. **После 50+ публикаций** — измерить Core Web Vitals, оптимизировать LCP (Tier 4)
4. **Постоянно** — расширять citation/license metadata в schema (Tier 5)
