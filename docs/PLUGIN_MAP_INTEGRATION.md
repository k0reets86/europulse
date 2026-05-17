# EuroPulse Autopilot v21 — Plugin Map (Integration / Boundary)

**Дата**: 2026-05-14
**Назначение**: Карта граничного слоя — где плагин пересекается с WordPress core, темой, mu-plugins, внешними плагинами и worker'ом. Дополняет внутренние карты (WORKER/QUEUE/AI_PROCESSOR/ADMIN/DATAFLOW) — те описывают, что плагин делает _внутри_; этот описывает, что плагин _проникает_ во внешний мир.

**Когда читать**: при изменениях, потенциально влияющих на consumer'ов плагин-данных (mu-plugins, theme), при отключении/замене Polylang/Rank Math, при добавлении новых meta-keys, при изменениях REST surface.

---

## 1. Active runtime tree (live)

| Слой | Путь | Роль |
|------|------|------|
| Plugin (live) | `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21/` | Производит данные (queue → posts + 30+ meta keys + 6 кастомных таблиц) |
| Plugin (repo working tree) | `/root/projects/europulse/wp-plugins/europulse-autopilot-v21/` | НЕ live; copy + opcache_reset обязательны после edits (см. memory: `deploy_sync_not_automatic`) |
| mu-plugin (live) | `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation/` | Главный consumer — читает плагинные meta keys для frontend и feature flags |
| mu-plugin (repo working tree, 2026-05-14) | `/root/projects/europulse/wp-mu-plugins/europulse-foundation/` | Working tree копия. Не live; `cp` + `opcache_reset` обязательны после edits. Не включает ad SVG assets. |
| Theme | `/var/www/europulse/public/wp-content/themes/blocksy/` | **Независим** от плагина (нет ни одной reference на `EPV2_*` / `_epv2_*`). Все hooks темы используются mu-plugin'ом, не плагином напрямую |
| Worker | `/root/projects/europulse/worker-v21/` | HTTP-клиент REST bridge (token-auth). Plugin → worker через wp_remote_post (`ai/class-epv2-ai-client.php`); worker → plugin через `index.php?rest_route=/epv2/v1/bridge/*` |

---

## 2. mu-plugins — единственный consumer плагин-данных

Live: `/var/www/europulse/public/wp-content/mu-plugins/`
- `europulse-foundation.php` — bootstrap loader: подключает 4 module files (core / front-hooks / render / content-seo-hooks)
- `europulse-foundation/includes/core.php` (2385 строк) — функции `europulse_*`, главные consumer'ы плагин-меты
- `europulse-foundation/includes/front-hooks.php` (178 строк) — WP filter wiring (`post_class`, `wp_nav_menu_args`, `query_loop_block_query_vars`)
- `europulse-foundation/includes/render.php` (771 строк) — Blocksy hooks, query-loop overrides для home-newsroom
- `europulse-foundation/includes/content-seo-hooks.php` (1134 строк) — `the_content` фильтры, Rank Math overrides, robots.txt, canonical, JSON-LD
- `europulse-foundation/templates/home-newsroom.php` — home-page template

### 2.1. Плагин-meta, которые читает foundation

Из `grep` в `includes/core.php`:
- `_epv2_queue_id` — gating: posts без queue_id фильтруются из home-newsroom pool (`europulse_home_post_is_eligible`) **(~10 reads)**
- `_epv2_primary_category` — canonical slug для localized term lookup (`europulse_get_localized_terms`) **(7+ reads)**
- `_epv3_primary_category` — fallback для legacy
- `_epv2_remote_source_url`, `_epv2_remote_source_label` — attachment credit prefix (`europulse_attachment_credit`) **на attachments**
- `_europulse_card_lead` — card lead для excerpts на listings (`europulse_context_excerpt`)
- `europulse_breaking`, `europulse_breaking_until` — breaking news ribbon (`europulse_is_breaking`)
- `europulse_top_story` — top-zone selection
- `europulse_sponsored` — sponsored chip
- `europulse_story_format`, `europulse_story_topic`, `europulse_popular_score` — home pool ranking
- `europulse_selection_decision`, `europulse_selection_score` — feature-priority routing
- `europulse_demo_post`, `europulse_slider_headline`, `europulse_video_poster` — demo/manual overrides

Foundation вызывает класс плагина `EPV2_Taxonomy_Map` напрямую (`class_exists`-gated):
- `EPV2_Taxonomy_Map::map($slug, $lang)` — для term_id из canonical slug + lang
- `EPV2_Taxonomy_Map::canonical_slug_for_term_id($term_id)` — обратная сторона

**Failure mode**: если plugin disabled — `class_exists('EPV2_Taxonomy_Map')` = false → mu-plugin gracefully falls back на hard-coded category IDs (см. render.php:287-295 — fallback term_id 14, 16, 18 и т.д.). **Эти hardcoded IDs хрупки** — если их сместит admin → ломается nav links.

**Failure mode (card_lead)**: `_europulse_card_lead` пустой → `europulse_context_excerpt` falls back на post_excerpt → wp_strip_all_tags(post_content). Не ломается, но качество подачи деградирует (длинные сырые excerpts).

### 2.2. Hooks, которые foundation регистрирует

Из `front-hooks.php`:
- `wp_enqueue_scripts` priority 20 — стили foundation
- `blocksy:footer:current_section_id` filter
- `wp_nav_menu_args` filter — подменяет primary menu по `europulse_primary_menu_<lang>` option
- `nav_menu_link_attributes` filter — переписывает category links через `pll_get_term` + `EPV2_Taxonomy_Map`
- `query_loop_block_query_vars` filter — Gutenberg Query Loop: inject `lang` + translate tax_query terms
- `post_class` filter — добавляет `europulse-breaking` / `europulse-sponsored` / `europulse-has-video` (читает плагин-мету напрямую)
- `get_template_part_template-parts/content` action — empty-archive fallback (DE/EN/UK)

Из `content-seo-hooks.php`:
- `the_content`, `robots_txt`, `template_redirect`, `redirect_canonical`, `pre_ping`, `widget_block_content`, `render_block`, `posts_search`
- `render_block_core/post-date`, `get_the_date`, `render_block_core/post-terms`, `render_block_core/video`
- `wp_head` (4 раза для разных meta), `wp_footer`
- `blocksy:footer:copyright:value`, `blocksy:post-meta:items`, `blocksy:single:content:top`, `blocksy:single:content:bottom`
- `rank_math/frontend/canonical`, `rank_math/frontend/description`, `rank_math/frontend/robots`, `rank_math/json_ld`, `rank_math/sitemap/entry`, `rank_math/sitemap/post_sitemap_url`, `rank_math/opengraph/url`

---

## 3. WordPress post_meta keys — writers vs readers

Из `grep update_post_meta /root/projects/europulse/wp-plugins/europulse-autopilot-v21/includes/`. Live counts из `ep_postmeta`.

### 3.1. Writers — `_epv2_*` namespace (плагин-internal)

| meta_key | Writer (file:line) | Reader | Live count |
|----------|--------------------|--------|----:|
| `_epv2_queue_id` | publisher.php:328, 600 | foundation/core.php (gating), foundation/render.php:175,364, seo/class-epv2-schema-enricher.php:190 | 3360 |
| `_epv2_source_url` | publisher.php:327, 599 | foundation, schema-enricher (sameAs) | 3360 |
| `_epv2_primary_category` | publisher.php:341, 511, 608 | foundation/core.php:496+ (localized terms) | 3360 |
| `_epv2_title_hash`, `_epv2_content_hash`, `_epv2_semantic_hash` | publisher.php:335-337, 604-606 | queue dedup (`EPV2_Deduplicator`) | 3360 каждый |
| `_epv2_event_key` | publisher.php:340, 607 | dedup (Variant-D event signature) | 2691 |
| `_epv2_cluster_id` | publisher.php:338 | clustering | 3360 |
| `_epv2_topic_label` | publisher.php:339 | analytics, foundation | 3357 |
| `_epv2_publish_media_url` | publisher.php:329, 601 | foundation media | 3313 |
| `_epv2_seo_title` | publisher.php:763 | mirror Rank Math title | 2352 |
| `_epv2_meta_desc` | publisher.php:764 | mirror Rank Math description | 3242 |
| `_epv2_media_origin_url` | publisher.php:381, 628 | media diagnostics | 2691 |
| `_epv2_media_credit` | publisher.php:382, 629 | foundation attachment_credit | 2691 |
| `_epv2_media_caption` | publisher.php:383, 630 | foundation | 2691 |
| `_epv2_media_diagnostics` | publisher.php:385, 632 | admin debug | 1357 |
| `_epv2_featured_media_fingerprint` | publisher.php:377 | dedup featured-image | 3404 |
| `_epv2_featured_media_origin_fingerprint` | publisher.php:375 | dedup | 3356 |
| `_epv2_dropped_siblings` | collector.php:911 | sibling enrichment audit | 9 |
| `_epv2_story_fingerprint` | (legacy, writes not in current code) | dedup | 423 |

### 3.2. Writers — `_epv2_*` namespace (на attachments)

| meta_key | Writer | Reader | Count |
|----------|--------|--------|------:|
| `_epv2_remote_source_url` | media.php:569, 1699 | foundation/core.php:281 (`europulse_attachment_credit`) | 1700 |
| `_epv2_remote_source_label` | media.php:1702 | foundation | 1684 |
| `_epv2_media_origin_fingerprint`, `_epv2_media_fingerprint` | media.php:570-571, 910-911 | dedup | 1689/1698 |
| `_wp_attachment_image_alt` (core key, plugin writes) | media.php:719, 1719 | WP core | — |

### 3.3. Writers — `europulse_*` namespace (consumed by foundation/theme)

Это семантически "shared protocol" — namespace, который мог бы быть написан вручную, но плагин пишет автоматически. **Меняй с осторожностью — foundation полагается.**

| meta_key | Writer (publisher.php) | Reader | Count |
|----------|------------------------|--------|------:|
| `_europulse_card_lead` | :334, 603 | foundation `europulse_context_excerpt` (cards/listings) | 1112 |
| `europulse_breaking` | :1106 | foundation `europulse_is_breaking`, post_class filter | 3371 |
| `europulse_breaking_until` | :1108 | foundation breaking expiry | 10 |
| `europulse_top_story` | :1112 | foundation home pool ranking | 3360 |
| `europulse_story_format` | :1114 | foundation home zone selection | 3360 (~) |
| `europulse_story_cluster_id` | :1117 | foundation clustering | 3222 |
| `europulse_story_topic` | :1124 | foundation topic badges | 3216 |
| `europulse_selection_decision` | :1142 | foundation feature-priority | 2346 |
| `europulse_selection_score` | :1148 | foundation feature ranking | 2331 |

Внимание: `_europulse_card_lead` (underscore-prefixed — protected meta) и `europulse_*` (без подчёркивания — public meta). WP отличает их в REST exposure (public meta видна в REST по умолчанию). Это сознательный выбор: foundation feature flags (breaking, top_story) могут быть отредактированы вручную через Block Editor.

### 3.4. Writers — Rank Math integration

Плагин **пишет напрямую** в Rank Math meta keys (тесная связь):
- `rank_math_title` — publisher.php:756
- `rank_math_description` — :757
- `rank_math_focus_keyword` — :758
- `rank_math_secondary_focus_keywords` — :760

Live counts (rank_math_*): 3373 / 3373 / 3371 / 759. **Failure mode mitigated 2026-05-14 (R15)**: если Rank Math disabled, mu-plugin foundation `wp_head` hook (priority 5) и `pre_get_document_title` filter автоматически render meta tags из mirror keys `_epv2_seo_title` / `_epv2_meta_desc`. Active fallback, не пассивный backup.

Плагин также **подмешивает в JSON-LD** через filter `rank_math/json_ld` (`seo/class-epv2-schema-enricher.php:35`) — augments NewsArticle schema (publisher.php:190 reads `_epv2_queue_id`).

---

## 4. WordPress hooks consumed

### 4.1. Bootstrap / init

| Hook | Registered | Callback | Note |
|------|------------|----------|------|
| `plugins_loaded` (prio 5) | bootstrap.php:24 | `EPV2_Plugin::boot` | Главный init |
| `plugins_loaded` (default) | bootstrap.php:23 | `EPV2_Plugin::load_textdomain` | |
| `plugins_loaded` (prio 1) | news-sitemap.php:11 | early render guard |
| `init` (prio 5) | news-sitemap.php:12 | rewrite rules `news-sitemap.xml` |
| `rest_api_init` | plugin.php:40 | `EPV2_REST::register_routes` |
| `query_vars` filter | news-sitemap.php:13 | inject `epv2_news_sitemap` |
| `template_redirect` | news-sitemap.php:14 | render sitemap |

### 4.2. Cron (custom hooks, через wp_schedule_event)

| Hook | Schedule | Callback | File |
|------|----------|----------|------|
| `epv2_collect` | every N min (settings) | `EPV2_Jobs::run_collect_windowed` | jobs.php:92 |
| `epv2_process` | every N min | `run_process_windowed` | :93 |
| `epv2_publish` | every N min | `run_publish_windowed` | :94 |
| `epv2_collect_async` / `_process_async` / `_publish_async` | single-event dispatch | async handoff | :95-97 |
| `epv2_weekly_analysis` | weekly, Sunday 08:00 | `EPV2_Weekly_Analysis::run` | weekly-analysis.php:28-33 |
| `epv2_log_prune` (PRUNE_HOOK) | daily | log prune | logger.php:12, 17 |

**Note**: фактически live runtime использует **server orchestrator** (external Python daemon — `epv2_bridge_orchestrator.py`), который дергает REST bridge endpoints. WP-cron hooks остаются registered, но `EPV2_Jobs::server_orchestrator_enabled() = true` (option `epv2_server_orchestrator_enabled=1`) → `clear_scheduled()` зачищает hooks. Эти hooks — fallback.

### 4.3. Filters

| Hook | Callback | File |
|------|----------|------|
| `cron_schedules` | inject `epv2_<N>_minutes` schedules | jobs.php:91 |
| `wp_feed_options` | RSS timeout config | feed-reader.php:27 |
| `wp_feed_cache_transient_lifetime` | RSS cache override | feed-reader.php:26 |
| `the_content` (prio 8) | `EPV2_Publisher::normalize_rendered_content` | publisher.php:9 |
| `rank_math/json_ld` (prio 20) | NewsArticle augmentation | schema-enricher.php:35 |
| `rest_endpoints` | block unauthenticated `/wp/v2/users` (security hardening) | plugin.php:45-73 |

### 4.4. Admin

- 41 `admin_post_epv2_*` actions — все POST handlers admin UI (см. полный список в PLUGIN_MAP_ADMIN.md)
- 2 `wp_ajax_epv2_*` actions: `epv2_queue_snapshot`, `epv2_regen_block`
- `admin_menu` + `admin_enqueue_scripts`

### 4.5. Save_post / transitions

**Не используются**. Плагин не подключается к `save_post`/`transition_post_status` — он сам создаёт posts через `wp_insert_post` (publisher.php:313) и пишет всю мету сразу.

---

## 5. WordPress hooks emitted by plugin

**R16 2026-05-14: 6 extension hooks added.**

| Hook | Type | File:line | Args |
|------|------|-----------|------|
| `epv2_after_publish` | action | publisher.php:445 | `(int $post_id, array $payload, int $queue_id)` |
| `epv2_after_reject` | action | queue.php:2566 | `(int $id, string $reason, string $state)` |
| `epv2_pipeline_stalled` | action | alerts.php | `(int $duration_min, int $ready_items)` |
| `epv2_publish_gate_decision` | filter | publish-gate.php:122 | `(bool $allowed, ?object $item, array $payload, string $context)` |
| `epv2_category_publish_c` | filter | budget-manager.php:528 | `(int $publish_c, string $category, array $scorecard)` |
| `epv2_should_send_to_ai` | filter | budget-manager.php:425 | `(bool $allow, array $analysis, array $verdict)` |

Third-party extensions могут подписаться на actions для observability (Slack, Sentry, analytics) или применить filters для override behavior (manual hold, A/B experiments, embargo rules).

Прежний state: единственное место с `apply_filters('the_content', ...)` в schema-enricher.php (это applying чужого filter, не emitting своего).

---

## 6. Polylang integration

### 6.1. Прямые вызовы

| Функция | Call site | Назначение |
|---------|-----------|-----------|
| `pll_set_post_language($post_id, $lang)` | publisher.php:398; weekly-analysis.php:357,375,404 | Назначить язык новому посту (DE/UK/EN) |
| `pll_save_post_translations($post_ids)` | publisher.php:404, 644; weekly-analysis.php:410 | Связать тройку (DE/UK/EN) как переводы |
| `pll_get_post_translations($post_id)` | watchdog.php:337 | Repair: получить текущую связку |
| `pll_get_post_language($post_id, 'slug')` | publisher.php:1102, 1208; watchdog.php:327, 392; news-sitemap.php:169; post-audit.php:267; time-planner.php:456 | Определить язык поста |

**Term language**: плагин **НЕ** использует `pll_get_term`/`pll_set_term`. Категории заранее заведены админом + Polylang config'ом; плагин обращается к ним через `EPV2_Taxonomy_Map::map($canonical_slug, $lang)`, который маппит canonical slug → term_id из site profile. Foundation использует `pll_get_term` сама (для frontend rendering).

### 6.2. Watchdog: `repair_polylang_links`

`queue/class-epv2-watchdog.php:295-400`. Сценарий: один из 3 переводов отсутствует / orphan'нул link. Watchdog:
1. Запрашивает sibling post_ids через `pll_get_post_translations`
2. Перестраивает по-lang carry в массив `[lang => post_id]`
3. Вызывает `pll_save_post_translations($by_lang)` — re-link
4. Также чинит posts без `_polylang_language` meta через прямой SQL JOIN на postmeta (watchdog.php:373)

Вызывается из `EPV2_REST::maintenance` (REST endpoint `/bridge/maintenance` — :451).

### 6.3. Failure modes без Polylang

`pll_*` функции guard'ятся через `function_exists()` во всех 30+ call sites. Если Polylang disabled:
- `pll_set_post_language` skip → новые posts создаются без языка → home pool в foundation выдаёт их fallback'ом на `de` (`europulse_current_lang() === 'de'`)
- `pll_save_post_translations` skip → DE/UK/EN posts остаются orphan'ами
- Watchdog `repair_polylang_links` устанавливает `$result['polylang_unavailable'] = true` и возвращает (watchdog.php:298)

**Hardline assumption**: production setup всегда has Polylang. Plugin не testить вне Polylang.

---

## 7. Action Scheduler — НЕ используется плагином

Live: таблицы `ep_actionscheduler_*` существуют (32 actions, 0.16 MB). Источник — **Rank Math vendor**: `seo-by-rank-math/vendor/woocommerce/action-scheduler/`.

Плагин **не вызывает** `as_schedule_*` / `as_unschedule_*` (grep пустой). Использует только нативный WP-cron (`wp_schedule_event`, `wp_schedule_single_event`).

Не путать с тем, что в orchestrator есть упоминания `actionscheduler` — это про мониторинг сторонних таблиц для здоровья сайта, не для плагин-операций.

---

## 8. Capabilities & roles

`core/class-epv2-capabilities.php` определяет 5 plugin-specific caps:
- `manage_europulse_autopilot` (admin)
- `edit_europulse_autopilot_sources`
- `run_europulse_autopilot`
- `publish_europulse_autopilot`
- `view_europulse_autopilot_logs`

Плюс bundle стандартных WP caps (read, edit_posts, publish_posts, edit_others_posts, …) — мирят интерактив с custom-cap-проверкой.

**Grant**: `EPV2_Capabilities::maybe_grant_caps()` присваивает все caps роли `administrator` once-on-activate + on version bump (`epv2_caps_version` option tracks version stamp).

**Checks**:
- `current_user_can('manage_europulse_autopilot')` — admin entry point (admin.php:21,88, rest.php:109)
- `current_user_can('edit_europulse_autopilot_sources')` — sources page (admin.php:27)
- WP-CLI: пропускает cap-check если effective UID = 0 (root), иначе требует `--user=<admin>` (`cli-commands.php:70-79`). 2026-05-13 fix: ранее WP-CLI без user context всегда падал.

---

## 9. Theme integration — независимый theme

Live theme: **Blocksy** (`/var/www/europulse/public/wp-content/themes/blocksy/`).

`grep "EPV2_\|_epv2_\|_europulse_\|europulse_"` в `functions.php` + `inc/*.php` → **пусто** (без node_modules / minified).

Theme не знает о плагине. Всё сопряжение делается через mu-plugin `europulse-foundation`, который:
- Подключается к Blocksy hooks (`blocksy:single:content:top`, `blocksy:footer:copyright:value`, etc.)
- Внутри callback'ов читает плагин-мету (`_epv2_*`, `europulse_*`)
- Регистрирует filters на Gutenberg blocks (`render_block_core/*`)

**Замена темы**: blocksy → другая theme не сломает плагин (нет direct coupling). Сломает foundation (≈10 Blocksy-specific filters в `content-seo-hooks.php`). Foundation в этом смысле — adapter между плагином и Blocksy.

---

## 10. REST surface — external consumers

Namespace: `epv2/v1`. Registered: `api/class-epv2-rest.php:9-101`. 16 routes:

| Route | Method | Caller |
|-------|--------|--------|
| `/stats` | GET | admin UI |
| `/sources` | GET | admin UI |
| `/queue` | GET | admin UI |
| `/source-test` | POST | admin |
| `/bridge/health` | GET | orchestrator + `scripts/epv2_runtime_watch.sh` |
| `/bridge/state` | GET | orchestrator + watch script |
| `/bridge/pause`, `/bridge/resume`, `/bridge/pause-collect`, `/bridge/resume-collect` | POST | orchestrator, manual ops |
| `/bridge/collect`, `/bridge/process`, `/bridge/publish` | POST | orchestrator drives pipeline |
| `/bridge/maintenance` | POST | orchestrator daily cleanup |
| `/bridge/breaking_scan` | POST | orchestrator breaking-news pass |
| `/bridge/server-orchestrator` | POST | toggle orchestrator on/off |

Auth: `can_bridge()` (rest.php:112) — либо cap `manage_europulse_autopilot`, либо `X-EPV2-Bridge-Token` / `Authorization: Bearer ...` matching `EPV2_Settings::worker_shared_secret()` (constant-time `hash_equals`, ≈line 130). Каждый token-auth логируется.

**External callers** найдены:
1. `/root/projects/europulse/worker-v21/epv2_bridge_orchestrator.py:55` — главный consumer
2. `/root/projects/europulse/scripts/epv2_runtime_watch.sh:15,17` — health monitor (curl)

Нет других callers. nginx ACL (выше REST) restrict'ит `/wp-json/epv2/v1/bridge/*` к loopback / known IPs.

---

## 11. WordPress options — writers vs readers

Live (из `wp option list`): 17 non-AI-usage option keys + N daily AI usage keys.

| Option | Writer | Reader | Purpose |
|--------|--------|--------|---------|
| `epv2_settings` | settings.php (set_all) | везде | главный config blob (JSON в DB) |
| `epv2_settings_pre_tuning_snapshot_20260505` | (one-off) | restore reference | freeze pre-tuning state (см. memory: `tuning_snapshot_20260505`) |
| `epv2_version` | installer.php:11 | upgrader (schema migrations) | version stamp |
| `epv2_caps_version` | capabilities.php:33 | capabilities.php:28 | one-shot cap grant guard |
| `epv2_installed_at` | installer.php:10 | admin display | install timestamp |
| `epv2_automation_paused` | jobs.php:57 | везде (gate) | global pause flag |
| `epv2_collect_paused` | jobs.php:68 | jobs.php:30 | partial pause (collect only) |
| `epv2_collect_deferred_until` | (backpressure write) | jobs.php:217 | backpressure stagger |
| `epv2_collect_backpressure_last`, `_total_seconds` | backpressure | admin UI | metrics |
| `epv2_collect_progress` | collector | admin UI | progress display |
| `epv2_server_orchestrator_enabled` | installer.php:12, admin toggle | jobs.php:34 | orchestrator mode flag |
| `epv2_runtime_maintenance_at` | maintenance run | TTL check | last maintenance timestamp |
| `epv2_active_alerts` | notifier | admin UI | active alerts blob |
| `epv2_async_guard_epv2_*_async` | jobs.php (touch_async_dispatch_guard) | dispatch_async | async dispatch double-fire guard |
| `epv2_provider_health` | ai-client | admin | AI provider health snapshot |
| `epv2_source_health` | source-tester | admin | source health snapshot |
| `epv2_workflow_stage_circuit` | watchdog | admin | circuit-breaker state |
| `epv2_lock_collect`, `_process`, `_publish` | lock-manager | везде (gate) | atomic locks для cron handlers |
| `epv2_lock_<id>` (per-item) | lock-manager | везде | item-level locks |
| `epv2_active_automation_item` | автоматика | admin display | currently-processed item id |
| `epv2_ai_usage_<YYYY-MM-DD>` | ai-client | budget-manager | daily AI cost ledger |
| `epv2_trend_history`, `epv2_trend_last_refresh` | trends | trends | trend snapshot |
| `epv2_last_resilience_cleanup` | resilience-manager | resilience-manager | TTL |
| `epv2_gemini_draft_test_profile_md` | (review draft / one-off) | admin | profile MD blob |
| `europulse_*` (`europulse_primary_menu_de/en/uk`, `europulse_editorial_contract_json`, …) | manual / one-off via admin | foundation/core.php (front-hooks.php) | site config — foundation reads, плагин может писать через UI helpers |

---

## 12. Database tables

DDL: `core/class-epv2-installer.php:36-280`. Live sizes (2026-05-14):

| Table | Rows | Size MB | Source | Purpose |
|-------|-----:|--------:|--------|---------|
| `ep_epv2_sources` | 136 | 0.19 | plugin | RSS / HTML / Google News sources |
| `ep_epv2_queue` | 150 | 17.19 | plugin | central item state machine (ai_payload LONGTEXT доминирует) |
| `ep_epv2_clusters` | 3270 | 2.44 | plugin | story-cluster dedup ledger |
| `ep_epv2_log` | 336181 | **99.22** | plugin | log entries — самая большая таблица. Daily prune через `epv2_log_prune` |
| `ep_epv2_runs` | 9466 | 4.58 | plugin | per-run audit |
| `ep_epv2_stats` | 47 | 0.03 | plugin | daily aggregates |
| `ep_epv2_selection_audit` | 15207 | 23.44 | plugin | per-candidate selection trace |
| `ep_epv2_learning_journal` | 1864 | 0.75 | plugin | adaptive feedback journal |
| `ep_epv2_queue_backup_pre_20260401_new` | 0 | 0.17 | manual snapshot | legacy backup |
| `ep_actionscheduler_actions` | 32 | 0.16 | **Rank Math vendor** | NOT used by plugin |
| `ep_actionscheduler_claims/groups/logs` | 0/2/94 | 0.03+ | Rank Math vendor | NOT used by plugin |

Generated column `pipeline_stage` (VARCHAR(64) STORED) на `ep_epv2_queue` — extracts `ai_payload._meta.pipeline_stage` для индексированных запросов вместо LIKE-scan LONGTEXT (installer.php:247-279).

**WP стандартные таблицы**, которые плагин активно использует:
- `ep_posts` — пишет через `wp_insert_post` (publisher.php:313, weekly-analysis.php:334,395). Live: 3308 published, 64 draft, 37 trash.
- `ep_postmeta` — пишет ≈25 уникальных meta_keys (см. секции 3.x), live ≈40K meta rows across plugin keys
- `ep_term_relationships` — через `wp_set_post_terms` (publisher.php:411, 509, 596)
- `ep_options` — см. секция 11
- `ep_users` — read only через `current_user_can()`. Плагин **блокирует** `/wp/v2/users` REST endpoint для unauthenticated callers (plugin.php:45-73 — security hardening)

---

## 13. Media library integration

Pipeline:
1. **Discovery**: `wp_remote_get` to Pexels / Wikimedia API (media.php:267, 1463, 1749, 1794) или прямой URL из RSS
2. **Sideload**: `media_handle_sideload($file, $post_id)` (media.php:563). До этого — `wp_handle_sideload`-стиль временный download.
3. **Fingerprinting**: после sideload плагин пишет в attachment meta:
   - `_epv2_remote_source_url` (esc_url_raw)
   - `_epv2_remote_source_label`
   - `_epv2_media_origin_fingerprint` (hash URL'а)
   - `_epv2_media_fingerprint` (hash attachment file)
   - `_wp_attachment_image_alt` (sanitized, foundation reads)
4. **Featured assignment**: `set_post_thumbnail($post_id, $attachment_id)` (publisher.php:351, 365, 624, 1355, 1389; post-audit.php:78; weekly-analysis.php:385).
5. **Post-level fingerprints**: на post meta — `_epv2_featured_media_fingerprint`, `_epv2_featured_media_origin_fingerprint` (publisher.php:375, 377) — для dedup featured images между bundle DE/UK/EN.

**Failure mode**: если `media_handle_sideload` падает — публикация не блокируется, post создаётся без featured. Watchdog (`post-audit`) пересматривает orphan posts и пытается re-sideload.

**Off-topic stock guard**: согласно memory `feedback_no_wiki_pexels_as_featured`, Wikimedia/Pexels результаты НЕ должны проходить publish gate. Если они и записываются — это manual_review pickup.

---

## 14. Fragile points — integration boundary

Места, где behavior зависит от assumption'ов о другом коде/конфиге, что хрупко:

1. ~~**Foundation hard-coded category IDs**~~ **RESOLVED 2026-05-14 (R18)**: `europulse_resolve_term_id($slug, $lang, $fallback)` в content-seo-hooks.php — 5-level lookup chain (request cache → 1h transient → Taxonomy_Map → SQL → hardcoded fallback). Cache invalidated on category mutations. **Bonus**: hardcoded fallbacks были устаревшими (ukraine real term_id=1140 ≠ hardcoded 16, community 1258 ≠ 46) — dynamic resolution исправил silent breakage.

2. **`pll_*` global dependency** — все `pll_*` calls guard'ятся `function_exists`, но logic полагается на то, что `pll_set_post_language` _работает_. Disable Polylang без миграции post_meta → home pool foundation отдаст fallback (все = `de`), но реальные UK/EN posts уже не будут отображены.

3. **Rank Math meta direct writes** — плагин пишет `rank_math_title/description/focus_keyword` напрямую (publisher.php:756-760). Если RM upgrade сменит meta_key naming convention — все 3300+ posts потеряют SEO meta. Mirror в `_epv2_seo_title`/`_epv2_meta_desc` — safety net, но foundation/RankMath frontend не читает их.

4. **`epv2_settings` schema drift** — single LONGTEXT JSON blob. Плагин читает через `EPV2_Settings::get($key, $default)`, поэтому graceful, но adding/renaming key в одном модуле без migration → silent default fallback везде. Best practice: всегда добавлять default в `EPV2_Settings::defaults()`.

5. **`ep_epv2_log` 99 MB** — самая большая таблица. Daily prune через `epv2_log_prune` cron hook. Если WP-cron заглохнет (и orchestrator не дергает maintenance) → таблица растёт без bound. Critical для disk space.

6. **Server orchestrator vs WP-cron dual-mode**: `epv2_server_orchestrator_enabled` option — single source of truth. `EPV2_Jobs::register()` всегда регистрирует action callbacks; `schedule_recurring()` только conditional schedule. Если option случайно `0` (admin toggle, manual SQL update) — WP-cron начнёт стрелять параллельно с orchestrator → double pipeline runs → race на lock-manager.

7. **`_europulse_card_lead` populated на 1112/3360 posts (33%)** — старые posts (pre-2026-05-09 dedicated card_lead field) не имеют. Foundation `europulse_context_excerpt` graceful fallback'ит, но качество excerpts разнится по corpus'у.

8. **Action Scheduler tables — sole owner Rank Math**. Если RankMath disabled → таблицы могут оказаться orphan, AS rows ref'нутые этим vendor'ом не очистятся. Плагин этого _не использует_ , но live infra ожидает наличие.

9. **`wp_insert_post` → meta writes — not atomic**. Если PHP timeout / fatal между insert_post и update_post_meta loop — будет post без `_epv2_queue_id` (`europulse_home_post_is_eligible` отфильтрует, не сломается) НО без `europulse_breaking`/`top_story` — feature flags pristine, что hidden bug. Watchdog `post-audit.php` пытается detect/repair.

10. **`pll_save_post_translations` invariant**: 3 posts должны быть `pll_set_post_language`'нуты ДО `pll_save_post_translations` (publisher.php:396-404). Если `pll_set_post_language` молча fail'нет для одного — translations save link'нёт unset language → broken triplet. Watchdog `repair_polylang_links` (watchdog.php:295) специально для этого случая.

---

## 15. Quick reference

### Если меняешь meta-key namespace `_epv2_*` или `europulse_*`
→ скан `europulse-foundation/includes/{core,front-hooks,render,content-seo-hooks}.php` на read sites. Обнови readers одновременно с writers.

### Если выводишь плагин из строя (debug)
→ foundation graceful fallback'ит (категории = hardcoded IDs, excerpts = post_excerpt fallback), но:
- home pool пуст (нет `_epv2_queue_id` posts)
- card leads = stripped post_content
- breaking ribbon исчезнет

### Если меняешь Polylang strategy
→ единственный writer translations — publisher.php:404,644 + weekly-analysis.php:410. Watchdog `repair_polylang_links` — единственный repair path. mu-plugin foundation также вызывает `pll_*` для frontend rendering — двойная dependency.

### Если переименовываешь Rank Math meta key (или мигрируешь на Yoast/AIOSEO)
→ publisher.php:756-760 + schema-enricher.php (`rank_math/json_ld` filter — поломается, нет fallback). Mirror keys `_epv2_seo_title`/`_epv2_meta_desc` доступны как replication source.

### Если повышаешь log volume / retention
→ ep_epv2_log уже 99 MB. Prune через `epv2_log_prune` (daily). Logger.php:17 — schedule timing.

### Если меняешь REST surface
→ orchestrator (Python) — единственный consumer бизнес endpoints. Watch script — health-only. Admin UI обращается к /stats /sources /queue. Менять auth (`can_bridge`) — обновить и `epv2_runtime_watch.sh`, и `epv2_bridge_orchestrator.py`.

---

## 16. Что специально не покрыто (handled in other maps)

- Internal worker → PHP contract (см. PLUGIN_MAP_DATAFLOW.md, PLUGIN_MAP_WORKER.md)
- Queue state machine, dedup logic (см. PLUGIN_MAP_QUEUE.md)
- Publish gate / validators (см. PLUGIN_MAP_AI_PROCESSOR.md)
- Admin UI блоки, Time_Planner, Budget_Manager (см. PLUGIN_MAP_ADMIN.md)

---

**Last verified**: 2026-05-14 live DB query + grep audit. Counts/hooks могут drift'нуть.
