# EuroPulse QA Audit Summary

Дата: 2026-03-15

## Что проверено

- smoke-тест основных страниц и языков
- accessibility через `axe`
- crawl/check внутренних ссылок, canonical и hreflang
- Lighthouse mobile для немецкой главной
- серверные заголовки, cache и gzip

## Что исправлено во время аудита

- восстановлен `robots.txt`
- добавлен `Sitemap` в `robots.txt`
- включён gzip в `nginx`
- static cache TTL увеличен до `30d`
- `UKR/EN` home получили self-canonical
- `og:url` и schema URL для мультиязычной главной исправлены
- category/search/home canonical перестали уводить в другой язык
- убран `rsd/xmlrpc` мусор из `<head>`
- устранена битая внутренняя ссылка `?cat=1944`

## Фактические результаты

### Smoke

- `de-home-desktop`: ok
- `de-home-mobile`: ok
- `uk-home-mobile`: ok
- `en-home-desktop`: ok
- `de-post-desktop`: ok

### Accessibility

- все 5 тестовых сценариев: `0` violations

### Crawl / internal links

- проверено seed-страниц: `15`
- проверено внутренних ссылок: `120`
- битых внутренних ссылок после фиксов: `0`

### Lighthouse mobile, DE home

- performance: `0.74`
- accessibility: `0.96`
- best practices: `0.75`
- SEO: `1.00`
- FCP: `1.9s`
- LCP: `11.3s`
- TTI: `11.5s`
- TBT: `10ms`
- CLS: `0`

## Главные слабые места сейчас

1. `Mobile LCP` всё ещё слишком высокий.
2. Первый экран и hero-изображения остаются тяжёлыми.
3. Legacy CSS всё ещё раздут и создаёт лишнюю нагрузку.
4. HTTPS/HTTP2 ещё не включены, пока нет финального домена.
5. Мультиязычность стала чище, но ей всё ещё нужен полный regression-pass на всех типах страниц.

## Что уже достаточно надёжно

- базовый multilingual SEO-контур
- page cache
- object cache
- robots/sitemap
- accessibility базового фронта
- smoke/health-check слой
