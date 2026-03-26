# EuroPulse Google Search Improvement Plan

Дата: 2026-03-15

Основа плана собрана по официальным источникам Google и `web.dev`, затем сопоставлена с фактическим состоянием сайта, Lighthouse и QA-проверками.

## Официальные источники

- Google Search Essentials: https://developers.google.com/search/docs/fundamentals/seo-starter-guide
- Search Essentials / технические требования: https://developers.google.com/search/docs/fundamentals/creating-helpful-content
- Control titles in Search: https://developers.google.com/search/docs/appearance/title-link
- Snippets / meta descriptions: https://developers.google.com/search/docs/appearance/snippet
- Google image best practices: https://developers.google.com/search/docs/appearance/google-images
- Image structured data + image guidelines: https://developers.google.com/search/docs/appearance/structured-data/article
- Video SEO / Video best practices: https://developers.google.com/search/docs/appearance/video
- Multilingual / hreflang: https://developers.google.com/search/docs/specialty/international/localized-versions
- Canonicalization: https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls
- Core Web Vitals / performance: https://web.dev/articles/lcp
- Responsive images: https://web.dev/articles/serve-responsive-images

## Уже исправлено

- Рабочий `robots.txt` с `Sitemap`.
- Self-canonical для `UKR/EN` главной.
- `og:url` и schema URL на главной больше не ссылаются на немецкий root у переводов.
- Включено серверное gzip-сжатие.
- Увеличен cache TTL для статики до `30d`.
- Включены:
  - `FastCGI page cache`
  - `Redis object cache`
  - `Koko Analytics`

## Критичные текущие слабые места

### 1. Нет HTTPS и, как следствие, нет HTTP/2

Это ограничивает производительность и доверие поисковых систем. По Lighthouse это уже видно как `uses-http2`.

Что сделать:
- перевести сайт на финальный домен `europulse.today`
- выпустить TLS-сертификат
- включить `HTTP/2`
- обновить `siteurl/home`, canonical, sitemap и Search Console

### 2. Мобильный LCP остаётся слабым

По Lighthouse mobile:
- `LCP ~ 10.2s`
- `Interactive ~ 10.4s`

Основные причины:
- тяжёлые hero-изображения
- слишком большой CSS-слой
- часть изображений ещё не оптимизирована под responsive delivery

Что сделать:
- подготовить отдельные уменьшенные hero-версии изображений
- перевести ключевые изображения в `WebP/AVIF`
- ужать и разгрести legacy CSS
- исключить с первого экрана всё несущественное

### 3. Изображения не оптимизированы достаточно хорошо

По Lighthouse:
- `modern-image-formats`
- `uses-optimized-images`
- `uses-responsive-images`
- `offscreen-images`

Что сделать:
- для всех hero/featured изображений обеспечить responsive sizes
- при генерации материалов хранить:
  - оригинал
  - hero-large
  - card-medium
  - sidebar-small
  - social-share
- запретить автоплагину публиковать изображение без alt/caption/source

### 4. Избыточный CSS и DOM

По Lighthouse:
- `unused-css-rules`
- `unminified-css`
- `dom-size`

Что сделать:
- продолжить перенос финальных правил в `europulse-normalize.css`
- сокращать legacy-override слой
- не плодить новые блоки/обёртки в home и archive

### 5. Мультиязычность нужно добить до production-уровня

Что уже нормально:
- `hreflang` есть
- отдельные языки есть

Что добить:
- добавить `x-default`
- пройти все canonical/hreflang сценарии для:
  - home
  - archive
  - single
  - search
- убедиться, что translated posts/pages всегда self-canonical

## Контентные правила для лучшей выдачи

### Заголовки

- Заголовок статьи должен быть законченным, без обрубков и многоточий.
- В `slider/latest/cards` нужен отдельный короткий display-headline, но canonical SEO-title должен оставаться осмысленным.
- Автоплагин должен проверять:
  - headline для сайта
  - SEO title
  - excerpt/dek

### Snippets / descriptions

- Каждая публикация должна иметь отдельный meta description.
- Описание должно быть самостоятельным, не автоматически склеенным из первых слов статьи.

### Изображения

- Alt-text обязателен.
- Подпись и источник обязательны.
- Картинка должна соответствовать теме рубрики и языку публикации.

### Видео

- Для video-post нужен корректный player + poster.
- Для future-плагина желательно хранить:
  - poster
  - video title
  - duration
  - source
- Позже добавить/проверить `VideoObject`.

## Structured Data

Проверить и закрепить:
- `Organization / NewsMediaOrganization`
- `WebSite`
- `WebPage`
- `NewsArticle`
- `VideoObject` для публикаций с видео
- breadcrumb schema там, где уместно

Важно:
- schema URL и canonical не должны конфликтовать
- мультиязычные страницы должны иметь корректный language context

## Что должен знать будущий автоплагин публикации

- язык публикации
- основная рубрика
- вторичные рубрики
- теги
- display headline
- SEO title
- meta description
- excerpt/dek нужной длины для каждой зоны сайта
- alt/caption/source изображения
- video poster / video flag
- `breaking` / `sponsored`
- срок жизни `breaking`

## Приоритет доработок

### P0

- Финальный домен + HTTPS + HTTP/2
- Search Console
- self-canonical и hreflang для всех типов страниц
- mobile LCP
- image optimization pipeline

### P1

- Очистка legacy CSS
- `VideoObject` / media rules
- x-default hreflang
- automated SEO validation in publishing plugin

### P2

- CDN for media/static
- object storage for uploads
- richer structured data QA
- editorial checklist for snippets/headlines before auto-publish
