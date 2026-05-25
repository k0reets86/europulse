# EuroPulse Autopilot v21 — Autonomous Plugin Hardening Plan

**Дата**: 2026-05-21
**Статус**: Phase 1 + non-blocking Phase 2 deployed; остальные этапы еще не реализованы
**Назначение**: документ для новой LLM-сессии или другого инженера. Здесь описано, что именно усиливать в автономной работе плагина, куда это встраивать в существующую архитектуру и какие правила нельзя нарушать.

---

## Implementation checkpoint — 2026-05-21 20:06 UTC

Phase 1 and the non-blocking part of Phase 2 are implemented and deployed live.

Done:

- `ep_epv2_quality_audit` table added through `EPV2_Installer`.
- `EPV2_Quality_Audit` added with `record`, `latest`, `summary`, `source_breakdown`, and `failure_histogram`.
- `EPV2_Quality_Gate` added with initial source/payload/fact/language/media/SEO/category checks.
- `EPV2_Publish_Gate::evaluate()` calls `EPV2_Quality_Gate::record_shadow()` only for generated-language payloads and only in publish-relevant contexts.
- `quality_shadow` is returned in the gate result but does not affect `allowed`.
- Admin submenu `Качество` added for summary, source breakdown, histogram, and latest records.
- Live validation passed:
  - PHP lint on changed repo/live files;
  - live schema table exists;
  - live autoload sees quality classes;
  - write/delete smoke test passed;
  - read-only evaluation on latest published payload returned `pass` / `score=92`.

Still not implemented:

- Blocking/review mode for hard blockers.
- Worker `/quality_audit`.
- Source trust score.
- Clustering/dedupe angle control.
- Post-publish rendered audit.
- Cache manager/orchestrator purge.
- Regression fixtures/replay.

Operational rule after this checkpoint:

- Observe shadow data first. Do not enable hard blocking until enough `publish_gate_shadow` records exist to check false positives.
- Minimum observation threshold: at least `30` real `publish_gate_shadow` rows or at least `24h` runtime, whichever is later.
- Before Phase 3, inspect verdict distribution, top blockers, and false positives. Pay special attention to `unsupported_numbers`, `category_drift`, `thin_source_dossier`, `generic_stock_featured_media`, and language-leak blockers.
- Do not start worker `/quality_audit`, source trust score, clustering/dedupe angle control, post-publish rendered audit, or cache manager before this shadow review.

---

## 0. С чего начинать новую сессию

Перед любыми правками прочитать:

1. `LLM_START_HERE.md` — текущий runtime status и свежие инциденты.
2. `SESSION_HANDOFF.md` — операционный handoff.
3. `docs/PLUGIN_MAP_INDEX.md` — главная карта плагина.
4. `docs/PLUGIN_MAP_DATAFLOW.md` — end-to-end путь item: RSS -> queue -> worker -> publish.
5. `docs/PLUGIN_MAP_WORKER.md` и `docs/PLUGIN_MAP_QUEUE.md` — worker и очередь.
6. Этот документ — план усиления автономной работы.

Рабочие пути:

- Repo: `/root/projects/europulse`
- Plugin source: `/root/projects/europulse/wp-plugins/europulse-autopilot-v21`
- Worker source: `/root/projects/europulse/worker-v21`
- MU plugin source: `/root/projects/europulse/wp-mu-plugins/europulse-foundation`
- Live WordPress: `/var/www/europulse/public`
- Live plugin: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`

Правило старта: сначала менять repo, затем lint/tests, затем отдельно деплоить в live path и перезапускать только нужные сервисы.

---

## 1. Цель

Нужно усилить весь автономный контур плагина, а не только переводы:

- качество переводов UK/EN;
- фактическая корректность rewrite/translation относительно источника;
- source trust и защита от слабых/тонких источников;
- dedupe, clustering, story angle и update logic;
- publish gate;
- post-publish audit: HTML, SEO, Polylang, media, schema, cache;
- admin visibility и метрики;
- regression fixtures, чтобы старые ошибки не возвращались.

Главная архитектурная идея: **не переписывать pipeline с нуля**. Нужно добавить единый quality contour вокруг уже существующих choke points.

---

## 2. Контекст последнего инцидента

В прошлой сессии были исправлены конкретные опубликованные материалы и часть первопричин:

- Почищены украинские посты с русизмами, опечатками и плохими транслитерациями.
- Переписаны проблемные story groups:
  - `14208/14209/14210` — Zeit/Yermak corruption case.
  - `14200/14201/14202` — Politico drones podcast.
- Обновлены SEO title/meta description и очищен cache.
- В worker усилены `translator.py`, `pipeline.py`, `story_card.py`, `rewriter.py`.
- В PHP усилены story card builder и SEO fallback в MU plugin.

Это не заменяет системный план ниже. Уже сделанные фиксы уменьшают повторение конкретных ошибок, но для автономного режима нужен постоянный quality gate, audit trail и source trust loop.

---

## 3. Карта существующих точек встраивания

Не изобретать новые параллельные pipeline. Использовать эти места.

| Зона | Существующий файл | Роль сейчас | Что добавить |
|---|---|---|---|
| Autoload | `wp-plugins/europulse-autopilot-v21/includes/bootstrap.php` | Подключает классы плагина | Добавить новые классы `quality`, `metrics`, `sources`, `core/cache` |
| Boot | `includes/core/class-epv2-plugin.php` | Инициализация hooks/jobs/admin | Подключить init для новых компонентов только если нужно |
| Sources | `includes/sources/class-epv2-sources.php` | Источники RSS/HTML/social | Добавить trust fields через installer/upgrader |
| Collector | `includes/ingest/class-epv2-collector.php` | Забирает источники, создает queue rows | Учитывать source trust при `story_score`, не менять queue ordering |
| Queue | `includes/queue/class-epv2-queue.php` | State machine, claim, transitions | Не плодить states; писать quality metadata в `admin_notes` |
| Dedup | `includes/queue/class-epv2-deduplicator.php` | URL/title/event duplicate checks | Расширить результат до same angle/new angle/update candidate |
| Clusters | `includes/core/class-epv2-story-clusters.php` | Story cluster cache | Добавить canonical post/angle hashes/status |
| Source enrichment | `includes/core/class-epv2-source-enricher.php` | Source dossier/context | Использовать для fact/source coverage signals |
| Dossier enrichment | `includes/ai/class-epv2-dossier-enricher.php` | Доп. контекст | Не считать title-only support фактическим источником |
| AI Processor | `includes/ai/class-epv2-ai-processor.php` | Отправка в worker, stage orchestration | Не превращать в quality monolith; только сохранять результаты |
| Worker client | `includes/core/class-epv2-worker-client.php` | HTTP к worker | Добавить вызов `/quality_audit` по условиям |
| Publish gate | `includes/publish/class-epv2-publish-gate.php` | Главная точка допуска к публикации | Главная точка вызова `EPV2_Quality_Gate::evaluate()` |
| Publisher | `includes/publish/class-epv2-publisher.php` | Создает DE/UK/EN posts | После публикации запускать rendered audit/cache manager |
| Post audit | `includes/publish/class-epv2-post-audit.php` | Ремонт post/media/source block | Расширить до проверки rendered bundle |
| REST bridge | `includes/api/class-epv2-rest.php` | Orchestrator maintenance/publish/process endpoints | Добавить maintenance hooks и post-publish audit endpoint при необходимости |
| Resilience | `includes/core/class-epv2-resilience-manager.php` | Source failures, health/recovery | Подключить source quality feedback |
| Selection audit | `includes/metrics/class-epv2-selection-audit.php` | Intake/selection audit | Не смешивать с publish quality; создать отдельный quality audit |
| Stats | `includes/metrics/class-epv2-stats.php` | Dashboard aggregates | Добавить quality summaries |
| Admin | `includes/admin/class-epv2-admin.php` | Dashboard/pages | Добавить страницу "Качество" и issue summaries |
| Worker server | `worker-v21/src/epv2_worker/server.py` | `/health`, `/analyze_story`, `/process` | Добавить `/quality_audit` |
| Worker contracts | `worker-v21/src/epv2_worker/contracts.py` | Pydantic contracts | Добавить request/response для quality audit |
| Worker pipeline | `worker-v21/src/epv2_worker/pipeline.py` | Rewrite/translate/media/seo bundle | Не перегружать; передавать facts/signals |

---

## 4. Текущие DB таблицы, на которые опирается план

На момент проверки есть:

- `ep_epv2_queue`: `id`, `source_id`, `state`, `mode`, `language_plan`, `original_url`, `canonical_url`, `original_title`, `original_content`, `source_image_url`, hashes, duplicate fields, `ai_payload`, provider/model/tokens/cost, `publish_payload`, `post_id`, `error_message`, `admin_notes`, `cluster_id`, `story_format`, `story_score`, `topic_label`, generated `pipeline_stage`.
- `ep_epv2_sources`: `id`, `name`, `type`, `url`, `language`, `category_bias`, `priority`, `fetch_interval`, `is_active`, `risk_level`, `is_top_tier`, `is_aggregator`, `parse_rules`, `attribution_rule`, `robots_status`, `last_fetched`, `last_error`, `notes`, `fetch_failures`.
- `ep_epv2_selection_audit`: intake/selection history with `queue_id`, `source_id`, `phase`, `outcome`, `decision`, `tier`, `score`, `reject_class`, `source_name`, `original_url`, `selection_json`, `ai_gate_json`, `planner_json`, `context_json`.
- `ep_epv2_clusters`: `cluster_key`, `title_seed`, `primary_category`, `language_hint`, mentions/source counts, developing/analysis flags, timestamps, `topic_label`.

Новые таблицы/колонки добавлять через `EPV2_Installer` и `EPV2_Upgrader`, а не ручным SQL только в live DB.

---

## 5. Общий контракт quality gate

Добавить файл:

`wp-plugins/europulse-autopilot-v21/includes/quality/class-epv2-quality-gate.php`

Стандартный return format:

```php
[
    'verdict'  => 'pass|review|reject|retry',
    'score'    => 0, // 0-100
    'blockers' => [],
    'warnings' => [],
    'signals'  => [],
]
```

Семантика:

- `pass`: можно публиковать.
- `review`: не публиковать автономно; переводить в существующий review/manual route.
- `reject`: терминально отклонить, если дефект не технический.
- `retry`: только для инфраструктурных/временных ошибок, не для плохого качества.

Нельзя создавать новые queue states без крайней необходимости. Использовать существующие:

- `ready_publish`
- `ready_review`
- `manual_review`
- `rejected`
- `retry_process`

---

## 6. Quality gate checks

`EPV2_Quality_Gate::evaluate($item, $payload, $context = [])` должен собрать эти проверки:

1. `source_fitness`
   - источник не disabled;
   - `risk_level` и будущий `trust_score`;
   - source dossier не тонкий;
   - title-only supporting links не считаются фактической поддержкой.

2. `fact_integrity`
   - generated DE/UK/EN не содержит новых людей, организаций, дат, чисел и причинно-следственных claims без опоры на source/story_card/dossier;
   - для тонких источников обязательный worker `/quality_audit`;
   - multi-topic podcast/RSS не должен превращаться в одну смешанную статью.

3. `language_quality`
   - UK: русизмы, машинные кальки, плохие транслитерации, битые URL/brand names;
   - EN: немецкоподобный английский, кириллица, broken brand names;
   - DE: plagiarism/fabricated names/unsupported public figures.

4. `payload_integrity`
   - есть `languages.de|uk|en`;
   - title/excerpt/content/slug/meta description заполнены;
   - `_meta.story_card`, `_meta.source_dossier`, `_meta.worker_prompt_version`, `_meta.editorial_prompt_version` валидны;
   - category не противоречит selection/story_card.

5. `media_integrity`
   - featured image есть или явно допустим fallback;
   - media url валиден;
   - caption/source/alt не пустые;
   - нет broken inline media.

6. `seo_integrity`
   - `_epv2_seo_title` и `_epv2_meta_desc` будут сохранены;
   - Rank Math meta mirror будет заполнен;
   - title/meta не содержат мусор, обрезки, чужой язык.

7. `cluster_integrity`
   - same-angle duplicate не публикуется;
   - new-angle/update candidate не теряется;
   - cluster canonical post сохраняется.

Вызов встраивать в `EPV2_Publish_Gate::evaluate()`, потому что это последняя централизованная точка перед публикацией. Не размазывать hard decisions по worker, AI processor и publisher.

---

## 7. Quality audit table

Добавить таблицу `ep_epv2_quality_audit`.

Минимальная схема:

```sql
CREATE TABLE ep_epv2_quality_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_id BIGINT UNSIGNED NULL,
  post_id BIGINT UNSIGNED NULL,
  source_id BIGINT UNSIGNED NULL,
  phase VARCHAR(40) NOT NULL,
  verdict VARCHAR(20) NOT NULL,
  score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  blockers LONGTEXT NULL,
  warnings LONGTEXT NULL,
  signals LONGTEXT NULL,
  worker_json LONGTEXT NULL,
  context_json LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY queue_id (queue_id),
  KEY post_id (post_id),
  KEY source_id (source_id),
  KEY phase_created (phase, created_at)
);
```

Добавить класс:

`wp-plugins/europulse-autopilot-v21/includes/metrics/class-epv2-quality-audit.php`

Методы:

- `record(array $row): int`
- `latest_for_queue(int $queue_id): ?array`
- `latest_for_post(int $post_id): ?array`
- `summary(int $hours = 24): array`
- `source_breakdown(int $hours = 168): array`
- `failure_histogram(int $hours = 24): array`

Фазы:

- `publish_gate_shadow`
- `publish_gate_blocking`
- `worker_fact_audit`
- `post_publish_rendered`
- `source_quality_refresh`
- `cache_verify`

Сначала писать audit в shadow mode без изменения поведения.

---

## 8. Worker fact audit

Добавить:

- `worker-v21/src/epv2_worker/fact_audit.py`
- contracts в `worker-v21/src/epv2_worker/contracts.py`
- endpoint `POST /quality_audit` в `worker-v21/src/epv2_worker/server.py`

Input:

```json
{
  "queue_id": 123,
  "original_title": "...",
  "original_content": "...",
  "original_url": "...",
  "source_dossier": {},
  "story_card": {},
  "payload": {},
  "languages": ["de", "uk", "en"],
  "risk_level": "moderate|high|low",
  "reason": "thin_source|high_risk_source|worker_warnings|multi_topic|random_sample"
}
```

Output:

```json
{
  "verdict": "pass|review|reject",
  "score": 0,
  "unsupported_entities": [],
  "unsupported_numbers": [],
  "unsupported_dates": [],
  "unsupported_claims": [],
  "multi_topic_risk": false,
  "source_coverage_score": 0,
  "warnings": [],
  "blockers": []
}
```

PHP caller:

`wp-plugins/europulse-autopilot-v21/includes/core/class-epv2-worker-client.php`

Добавить метод:

`quality_audit(array $request): array`

Вызывать не для каждого item сразу, а по условиям:

- primary source thin: `<35 words` всегда blocker, `<90 words` требует `news_brief` или review;
- source `risk_level` high/moderate;
- `trust_score < 60`;
- worker already emitted warnings/blockers;
- article is long compared to source coverage;
- RSS/podcast/social item looks multi-topic;
- high-stakes categories: politics, war, corruption, health, migration, law, finance;
- random sample, например 5-10% published candidates, чтобы ловить drift.

---

## 9. Source trust score

Добавить в `ep_epv2_sources`:

```sql
ALTER TABLE ep_epv2_sources
  ADD trust_score TINYINT UNSIGNED NOT NULL DEFAULT 70,
  ADD quality_stats LONGTEXT NULL,
  ADD last_quality_at DATETIME NULL;
```

Добавить класс:

`wp-plugins/europulse-autopilot-v21/includes/sources/class-epv2-source-quality.php`

Методы:

- `refresh_all(int $limit = 50): array`
- `refresh_source(int $source_id): array`
- `compute_score(array $source, array $stats): int`
- `record_quality_result(int $source_id, array $quality_result): void`
- `should_cooldown(array $source): bool`

Score считать из:

- `ep_epv2_selection_audit`: reject/low/review ratio, planner decisions;
- `ep_epv2_quality_audit`: fact drift, language fail, media fail, post-publish fail;
- `fetch_failures`, `last_error`, robots/parser status;
- source type: official/top tier/aggregator/social;
- historical publish success.

Rules:

- `trust_score >= 75`: normal.
- `60-74`: normal, но чаще sampling fact audit.
- `45-59`: force review for risky categories and thin sources.
- `30-44`: cooldown or collect less often.
- `<30`: disable or quarantine until human review.

Встраивание:

- `EPV2_REST::bridge_maintenance()`:

```php
$cleanup['source_quality'] = EPV2_Source_Quality::refresh_all(50);
```

- `EPV2_Collector` при insert/score:
  - корректировать `story_score` upstream;
  - не переписывать `EPV2_Queue::bridge_next_processable_row()` ordering.

---

## 10. Queue priority без переписывания очереди

Сейчас `EPV2_Queue::bridge_next_processable_row()` уже сортирует по workflow step, owner token, `story_score`, `created_at`. Это трогать только если есть доказанная причина.

Правильное место улучшения priority:

- `EPV2_Collector`
- `EPV2_Budget_Manager`
- `EPV2_Importance_Score`
- source trust adjustment перед сохранением `story_score`

Формула должна учитывать:

- editorial importance;
- source trust;
- freshness;
- category balance;
- cluster status;
- breaking/developing flags;
- source risk penalty;
- thin source penalty.

Нельзя повышать item только потому, что он "breaking", если source thin или fact audit risk высокий.

---

## 11. Clustering and dedupe

Расширить `ep_epv2_clusters` через installer/upgrader:

```sql
ALTER TABLE ep_epv2_clusters
  ADD canonical_queue_id BIGINT UNSIGNED NULL,
  ADD canonical_post_id BIGINT UNSIGNED NULL,
  ADD last_published_at DATETIME NULL,
  ADD angle_hashes_json LONGTEXT NULL,
  ADD cluster_status VARCHAR(20) NOT NULL DEFAULT 'open';
```

`cluster_status` values:

- `open`
- `published`
- `developing`
- `closed`

Расширить `EPV2_Deduplicator::is_event_duplicate()` так, чтобы результат был не просто duplicate true/false:

```php
[
    'decision' => 'duplicate|same_story_same_angle|new_angle|update_candidate|new_story',
    'duplicate_of' => null,
    'cluster_id' => null,
    'angle_hash' => '',
    'reason' => '',
]
```

Phase 1 behavior:

- `duplicate` и `same_story_same_angle` блокировать.
- `new_angle` отправлять в review/developing route, не auto-publish сразу.
- `update_candidate` сохранять, но не auto-update existing post на первом этапе.

Auto-update existing posts включать только после отдельного audit, потому что это повышает риск SEO/cache/Polylang рассинхрона.

---

## 12. Post-publish rendered audit

Расширить:

`wp-plugins/europulse-autopilot-v21/includes/publish/class-epv2-post-audit.php`

Добавить:

`verify_rendered_bundle(array $post_ids, array $payload, array $item): array`

Проверять после создания постов:

- DE/UK/EN posts существуют;
- Polylang links между переводами корректны;
- public URL открывается;
- `<title>` соответствует `_epv2_seo_title` / Rank Math title;
- meta description соответствует `_epv2_meta_desc`;
- canonical корректный;
- hreflang есть для DE/UK/EN;
- OG/Twitter title/description/image не пустые и не stale;
- featured image есть и доступна;
- schema не сломана;
- source block есть;
- нет случайного `noindex`;
- cache не отдает старый head.

Встраивание в `EPV2_Publisher::publish_item()`:

1. Создать/обновить посты.
2. `EPV2_Post_Audit::repair_after_publish($post_ids, $payload, $item)` — уже существует.
3. Применить final status/visibility.
4. Затем `verify_rendered_bundle(...)`, потому что до final status draft может не открываться публично.
5. Записать результат в `EPV2_Quality_Audit`.
6. Если post-publish audit failed, не откатывать автоматически посты на первом этапе; логировать и поднимать admin alert.

---

## 13. Cache manager

Добавить:

`wp-plugins/europulse-autopilot-v21/includes/core/class-epv2-cache-manager.php`

Методы:

- `clean_post_bundle(array $post_ids): array`
- `purge_rendered_urls(array $urls): array`
- `verify_cache_fresh(array $urls, array $expected_head): array`
- `emit_purge_request(array $urls, string $reason): void`

Важное runtime правило:

PHP/www-data не должен напрямую полагаться на удаление `/var/cache/nginx/europulse/*`. Nginx fastcgi cache purge должен делать:

- root-side orchestrator;
- или защищенный Nginx purge endpoint;
- или отдельный privileged helper.

PHP часть должна:

- очищать WordPress object/transient cache;
- чистить post cache;
- записывать/отправлять purge request;
- потом verify rendered head.

Если нужен bridge endpoint, добавить в `EPV2_REST`:

- `/wp-json/epv2/v1/bridge/post-publish-audit`
- `/wp-json/epv2/v1/bridge/cache-purge`

Оба endpoints только по `X-EPV2-Bridge-Token`.

---

## 14. Admin UI: страница "Качество"

Файл:

`wp-plugins/europulse-autopilot-v21/includes/admin/class-epv2-admin.php`

В `EPV2_Admin::menus()` добавить submenu:

- title: `Качество`
- slug: `epv2-quality`
- callback: `quality_page()`

Показать:

- publish gate: pass/review/reject/retry за 24h/7d;
- top blockers;
- source fail rate;
- thin source rate;
- fact drift rate;
- UK language fail rate;
- EN language fail rate;
- media fail rate;
- post-publish rendered fail rate;
- cache stale incidents;
- sources with `trust_score < 60`;
- latest 50 quality failures with queue_id/post_id/source/reason.

В queue issue summary добавить:

- `_meta.quality_gate.blockers`
- latest `quality_audit.verdict`
- source `trust_score`

Не перегружать dashboard. Dashboard должен показывать только high-level alerts, детали на странице "Качество".

---

## 15. Regression fixtures

Добавить fixtures:

`worker-v21/tests/fixtures/quality/`

Минимальный набор:

- `thin_source_zeit_yermak.json`
- `multitopic_politico_drones.json`
- `uk_brand_transliteration.json`
- `seo_cache_stale_payload.json`
- `unsupported_public_figure.json`
- `title_only_supporting_source.json`

Тесты:

- `worker-v21/tests/test_quality_audit.py`
- `worker-v21/tests/test_translator_quality_regressions.py`
- `worker-v21/tests/test_pipeline_source_fitness.py`

Команды:

```bash
cd /root/projects/europulse/worker-v21
python3 -m pytest tests/test_quality_audit.py
python3 -m pytest tests/test_translator_quality_regressions.py
python3 -m pytest tests/test_pipeline_source_fitness.py
```

WP CLI replay command добавить позже:

```bash
wp epv2 qa replay --fixture=thin_source_zeit_yermak
wp epv2 qa replay --queue-id=12345 --phase=publish_gate_shadow
```

Место для WP CLI:

`wp-plugins/europulse-autopilot-v21/includes/core/class-epv2-cli-commands.php`

---

## 16. Rollout order

Делать строго по этапам. Не включать hard blocking сразу.

### Phase 1 — Audit foundation

Files:

- `includes/core/class-epv2-installer.php`
- `includes/core/class-epv2-upgrader.php`
- `includes/metrics/class-epv2-quality-audit.php`
- `includes/bootstrap.php`

Actions:

1. Добавить `ep_epv2_quality_audit`.
2. Добавить class autoload.
3. Добавить методы record/summary/latest.
4. Lint PHP.
5. Проверить создание таблицы на staging/live через upgrader.

Behavior: только запись audit, без блокировки публикаций.

### Phase 2 — Quality gate in shadow mode

Files:

- `includes/quality/class-epv2-quality-gate.php`
- `includes/publish/class-epv2-publish-gate.php`
- `includes/bootstrap.php`

Actions:

1. Реализовать `EPV2_Quality_Gate::evaluate()`.
2. В `EPV2_Publish_Gate::evaluate()` вызвать gate и записать `publish_gate_shadow`.
3. Не менять publish decision.
4. Собирать статистику 24-48 часов.

Behavior: publish unchanged, visibility appears.

### Phase 3 — Review mode for hard blockers

Files:

- `includes/publish/class-epv2-publish-gate.php`
- `includes/queue/class-epv2-queue.php` only if needed for metadata
- `includes/admin/class-epv2-admin.php`

Actions:

1. Для hard blockers переводить в review/manual route.
2. Не reject автоматически до проверки false positives.
3. Писать blockers в `admin_notes._meta.quality_gate`.

Behavior: high-risk items no longer auto-publish.

### Phase 4 — Worker `/quality_audit`

Files:

- `worker-v21/src/epv2_worker/fact_audit.py`
- `worker-v21/src/epv2_worker/contracts.py`
- `worker-v21/src/epv2_worker/server.py`
- `includes/core/class-epv2-worker-client.php`
- `includes/quality/class-epv2-quality-gate.php`

Actions:

1. Добавить endpoint.
2. Добавить contracts.
3. Добавить PHP client method.
4. Вызов только по risk triggers.
5. Записывать `worker_fact_audit` в quality audit.

Behavior: fact drift catches unsupported entities/numbers/dates/claims.

### Phase 5 — Source trust score

Files:

- `includes/core/class-epv2-installer.php`
- `includes/core/class-epv2-upgrader.php`
- `includes/sources/class-epv2-source-quality.php`
- `includes/api/class-epv2-rest.php`
- `includes/ingest/class-epv2-collector.php`

Actions:

1. Добавить колонки в `ep_epv2_sources`.
2. Реализовать score refresh.
3. Включить maintenance refresh.
4. Корректировать `story_score` при collection.
5. Добавить admin visibility.

Behavior: плохие источники постепенно теряют приоритет или уходят на review/cooldown.

### Phase 6 — Clustering/dedupe angle control

Files:

- `includes/core/class-epv2-installer.php`
- `includes/core/class-epv2-upgrader.php`
- `includes/core/class-epv2-story-clusters.php`
- `includes/queue/class-epv2-deduplicator.php`
- `includes/ingest/class-epv2-collector.php`

Actions:

1. Добавить cluster columns.
2. Расширить dedupe decision.
3. Блокировать same-angle duplicate.
4. New angle/update candidate route в review/developing.

Behavior: меньше повторов, меньше смешивания разных углов одной истории.

### Phase 7 — Post-publish rendered audit

Files:

- `includes/publish/class-epv2-post-audit.php`
- `includes/publish/class-epv2-publisher.php`
- `includes/metrics/class-epv2-quality-audit.php`

Actions:

1. Добавить `verify_rendered_bundle`.
2. Вызвать после final status.
3. Проверять SEO/Polylang/media/schema/source/cache.
4. Писать audit and alert.

Behavior: видим реальные проблемы после публикации, а не только payload до публикации.

### Phase 8 — Cache manager and orchestrator purge

Files:

- `includes/core/class-epv2-cache-manager.php`
- `includes/api/class-epv2-rest.php`
- `worker-v21/epv2_bridge_orchestrator.py`

Actions:

1. PHP emits purge request.
2. Orchestrator/root-side выполняет Nginx purge или вызывает protected endpoint.
3. Verify fresh head.

Behavior: SEO/meta fixes доходят до public HTML без ручной чистки cache.

### Phase 9 — Admin quality page

Files:

- `includes/admin/class-epv2-admin.php`
- `includes/metrics/class-epv2-stats.php`
- `includes/metrics/class-epv2-quality-audit.php`

Actions:

1. Добавить submenu.
2. Добавить widgets/tables.
3. Добавить queue issue integration.

Behavior: оператор видит качество автономной работы.

### Phase 10 — Regression fixtures and replay

Files:

- `worker-v21/tests/fixtures/quality/*`
- `worker-v21/tests/test_quality_audit.py`
- `worker-v21/tests/test_translator_quality_regressions.py`
- `includes/core/class-epv2-cli-commands.php`

Actions:

1. Добавить fixtures из реальных инцидентов.
2. Добавить worker tests.
3. Добавить WP CLI replay.
4. Перед каждым крупным релизом прогонять tests/replay.

Behavior: прошлые ошибки не возвращаются бесшумно.

---

## 17. Жесткие правила внедрения

1. Не складывать все проверки в `EPV2_AI_Processor`.
   - AI Processor должен оркестрировать stages, а не быть quality monolith.

2. Не полагаться только на prompt.
   - Prompt просит модель не ошибаться; gate проверяет результат.

3. Не считать title-only supporting URL фактическим источником.
   - Для факта нужен loaded content или явно trusted primary source.

4. Не публиковать long-form article из тонкого source.
   - Thin source -> `news_brief`, review или reject.

5. Не менять ordering очереди, пока можно корректировать `story_score`.
   - `EPV2_Queue` уже сложная; priority belongs upstream.

6. Не добавлять новые queue states без крайней необходимости.
   - Использовать существующие states и metadata в `admin_notes`.

7. Не делать Nginx cache purge только из PHP, если нет прав/endpoint.
   - PHP emits request; privileged side purges.

8. Не включать blocking mode сразу.
   - Сначала shadow, потом review, потом reject/blocking.

9. Не auto-update existing posts на первом этапе clustering.
   - Сначала audit и review route.

10. Не удалять старые ручные/legacy paths во время quality hardening.
    - Сначала добавить visibility и gates, затем cleanup отдельной задачей.

11. Не править live без repo source.
    - Любой live hotfix должен быть отражен в `/root/projects/europulse`.

12. Не делать фактологический audit только на языке перевода.
    - Сравнивать с original/source_dossier/story_card и проверять DE/UK/EN.

---

## 18. Verification checklist

После каждого этапа:

PHP:

```bash
php -l /root/projects/europulse/wp-plugins/europulse-autopilot-v21/includes/path/to/changed.php
```

Worker:

```bash
cd /root/projects/europulse/worker-v21
python3 -m py_compile src/epv2_worker/server.py src/epv2_worker/contracts.py
python3 -m pytest tests/test_quality_audit.py
```

Live health, когда деплой уже сделан:

```bash
systemctl status epv2-worker --no-pager -l
systemctl status epv2-orchestrator --no-pager -l
curl -s --max-time 10 http://127.0.0.1:8765/health
curl -s --max-time 10 http://127.0.0.1/index.php?rest_route=/epv2/v1/bridge/health
```

DB sanity:

```bash
wp db query "SHOW TABLES LIKE 'ep_epv2_quality_audit';"
wp db query "SELECT phase, verdict, COUNT(*) FROM ep_epv2_quality_audit GROUP BY phase, verdict;"
wp db query "SELECT id,name,trust_score,last_quality_at FROM ep_epv2_sources ORDER BY trust_score ASC LIMIT 20;"
```

Rendered audit spot checks:

```bash
curl -I --max-time 10 http://127.0.0.1/
curl -s --max-time 10 "POST_URL" | rg "canonical|description|og:title|hreflang|noindex"
```

---

## 19. Definition of done

Этот план считается реализованным не когда классы добавлены, а когда выполнены условия:

- `ep_epv2_quality_audit` регулярно получает записи по publish gate, worker fact audit и post-publish audit.
- Hard blockers не публикуются автономно.
- Source trust score влияет на collection/priority/review.
- Same-angle duplicates не публикуются повторно.
- UK/EN translation regressions покрыты fixtures/tests.
- Тонкие источники не порождают развернутые статьи без достаточного source coverage.
- После публикации проверяется public HTML/head/media/schema/Polylang/cache.
- В админке есть страница качества с actionable failures.
- Оператор может понять за 2 минуты: что сломалось, где, по какому источнику, и что делать.

---

## 20. Минимальный первый PR/patch

Если новая сессия должна начать работу, лучший первый безопасный кусок:

1. Добавить `EPV2_Quality_Audit` table/class.
2. Добавить `EPV2_Quality_Gate` с базовыми payload/source/language checks.
3. Подключить его к `EPV2_Publish_Gate` в shadow mode.
4. Добавить вывод последних quality audit записей в admin dashboard или отдельную простую страницу.
5. Прогнать PHP lint.
6. Деплоить live только после проверки.

Не начинать с worker `/quality_audit`, source trust score или cluster rewrite. Эти части лучше делать после появления audit foundation, иначе будет нечем измерять false positives.
