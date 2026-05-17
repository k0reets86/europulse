# EuroPulse Autopilot v21 — Plugin Map (Index)

**Дата последнего обновления**: 2026-05-17
**Назначение**: Главный документ для **любой LLM при старте новой сессии**. Полная карта плагина + связи с сайтом + project status. Mandatory чтение перед любыми изменениями кода.

> Если ты только пришёл в проект — читай **с начала до конца раздела 5**. Дальше разделы становятся доменно-специфическими.

---

## 1. Что такое EuroPulse — TL;DR

EuroPulse — автоматический новостной агрегатор-переводчик. Берёт немецкие RSS, делает AI-rewrite на DE, переводит на UK и EN, публикует через Polylang на `https://europulse.eu/`. Полностью автономный (`mode='auto'`), без manual review queue.

**Стек**: WordPress (PHP 8.3 plugin) + Python FastAPI worker (`127.0.0.1:8765`) + systemd orchestrator + MariaDB + Redis + nginx.

## 2. Архитектура сайта — слои и связи

```
┌─────────────────────────────────────────────────────────────────┐
│  ВНЕШНИЙ МИР                                                    │
│  https://europulse.eu/  ←  Cloudflare/CDN  ←  nginx (port 443)  │
└──────────────────────────────┬──────────────────────────────────┘
                               │
                ┌──────────────┴──────────────┐
                │                             │
                │  nginx → php-fpm 8.3        │
                │                             │
                └──────────────┬──────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│  WORDPRESS (/var/www/europulse/public)                          │
│                                                                 │
│  Theme: blocksy + blocksy-companion                             │
│  Active plugins:                                                │
│    • europulse-autopilot-v21  ← НАШ ОСНОВНОЙ ПЛАГИН            │
│    • polylang (DE/UK/EN)                                        │
│    • seo-by-rank-math                                           │
│    • redis-cache (object cache)                                 │
│    • complianz-gdpr, koko-analytics                             │
│    • updraftplus (backups)                                      │
│    • limit-login-attempts-reloaded                              │
│                                                                 │
│  mu-plugins/ (5 SVG ads + entry files at root level):           │
│    • europulse-foundation.php           (entry, hooks all)      │
│    • europulse-foundation.css           (frontend CSS)          │
│    • europulse-normalize.css            (live-only, not in repo)│
│    • europulse-ad-*.svg × 5             (live-only)             │
│    • europulse-foundation/              (subdirectory)          │
│        ├── includes/                    (PHP modules)           │
│        └── templates/                   (template parts)        │
│                                                                 │
│  DB tables (prefix ep_):                                        │
│    • ep_posts, ep_postmeta, ep_term_*, ep_options (WP core)    │
│    • ep_epv2_queue          ← очередь pipeline                  │
│    • ep_epv2_selection_audit                                    │
│    • ep_epv2_stats                                              │
│    • ep_epv2_log, ep_epv2_runs                                  │
│    • ep_epv2_sources                                            │
│    • ep_epv2_clusters, ep_epv2_learning_journal                 │
└──────────────────────────────┬──────────────────────────────────┘
                               │
                               │  REST: /wp-json/epv2/v1/bridge/*
                               │  (HTTP, header X-EPV2-Bridge-Token)
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│  PYTHON WORKER (systemd: epv2-worker.service)                   │
│  /root/projects/europulse/worker-v21/                           │
│                                                                 │
│  FastAPI на 127.0.0.1:8765                                      │
│  ├─ /health                                                     │
│  ├─ /pipeline/build_de_master                                   │
│  ├─ /pipeline/translate                                         │
│  ├─ /pipeline/rebuild_bundle                                    │
│  ├─ /pipeline/publish_finish                                    │
│  └─ /bridge/heartbeat                                           │
│                                                                 │
│  Модули (src/epv2_worker/):                                     │
│    • pipeline.py     — pipeline stages, ai_runtime              │
│    • rewriter.py     — DE rewrite, validators                   │
│    • translator.py   — UK+EN translation, normalizers           │
│    • prompts/        — BASE_VOICE + TYPE_MODULES + RUBRICS      │
│    • story_card.py   — единый source of truth                   │
│    • contracts.py    — pydantic schemas                         │
│                                                                 │
│  venv: spaCy 3.8.14, OpenAI SDK, fastapi, uvicorn               │
│  spaCy models: de_core_news_lg (568MB), uk_core_news_lg         │
│                                                                 │
│  Worker BIND tokens (env, /etc/systemd/system/epv2-worker.service):│
│    • OPENAI_API_KEY                                             │
│    • BRIDGE_TOKEN (matches WP option `epv2_bridge_token`)       │
└──────────────────────────────┬──────────────────────────────────┘
                               │
                               │ HTTP outbound: api.openai.com
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│  ORCHESTRATOR (systemd: epv2-orchestrator.service)              │
│  /root/projects/europulse/worker-v21/epv2_bridge_orchestrator.py│
│                                                                 │
│  Что делает:                                                    │
│    • Каждые N секунд POST /wp-json/epv2/v1/bridge/maintenance   │
│    • Подтягивает Time_Planner mode                              │
│    • Дёргает collect_async + process_queue + publish_thread     │
│    • Отправляет Telegram alerts (R13)                           │
│    • Пишет cost_per_stage logs                                  │
└─────────────────────────────────────────────────────────────────┘
```

## 3. Site connection points — где плагин трогает WP

### REST endpoints
- `/wp-json/epv2/v1/bridge/*` — internal worker→PHP API. Auth: `X-EPV2-Bridge-Token` header. nginx upstream config: `/etc/nginx/sites-enabled/europulse`.
- `/wp-json/epv2/v1/admin/*` — admin UI AJAX (current_user_can permission).

### Hooks (WP actions/filters used)
- `init`, `admin_init`, `admin_menu`, `wp_ajax_*`
- `pre_get_posts` (для frontend filtering)
- `the_content`, `the_excerpt` (mu-plugin card_lead injection)
- `wp_head`, `pre_get_document_title` (R15 Rank Math fallback)
- Polylang: `pll_get_post_language`, `pll_set_post_language`

### Post meta keys (custom)
- `_epv2_pipeline_state` — current state
- `_epv2_ai_payload` — full payload JSON
- `_europulse_card_lead` — card lead для grid (R10)
- `_epv2_seo_title`, `_epv2_seo_desc` — SEO mirror (R15 fallback)
- `_epv2_*` (multiple) — translation, scoring, source attribution

### Options (WP options `epv2_*`)
- `epv2_settings` — main settings JSON
- `epv2_bridge_token` — worker auth
- `epv2_publish_thread_heartbeat` — heartbeat health (R7)
- `epv2_active_alerts` — Telegram alert dedup
- `epv2_ai_usage_YYYY-MM-DD` — daily AI budget tracking
- `epv2_settings_pre_tuning_snapshot_20260505` — restore key
- ~60 options total в epv2_* namespace

### DB tables (custom, prefix `ep_epv2_`)
- `ep_epv2_queue` — pipeline state + ai_payload + admin_notes
- `ep_epv2_selection_audit` — score history
- `ep_epv2_stats` — daily aggregates
- `ep_epv2_log` — error log
- `ep_epv2_runs` — pipeline run history
- `ep_epv2_sources` — RSS source config + per-hour counters
- `ep_epv2_clusters` — dedup cluster cache
- `ep_epv2_learning_journal` — anti-hallucination corpus

### Extension surface (R16, 2026-05-14)
| Hook | Type | File | Use case |
|------|------|------|----------|
| `epv2_after_publish` | action | publisher.php:445 | Post-publish side effects |
| `epv2_after_reject` | action | queue.php:2566 | Custom logging on reject |
| `epv2_pipeline_stalled` | action | alerts.php | Custom alert routing |
| `epv2_publish_gate_decision` | filter | publish-gate.php:122 | Override allow/block |
| `epv2_category_publish_c` | filter | budget-manager.php:528 | Per-category threshold |
| `epv2_should_send_to_ai` | filter | budget-manager.php:425 | AI-send veto |

## 4. Project Status (2026-05-17)

### Версии (live state)
| Stamp | Значение | Файл |
|---|---|---|
| `PROMPT_VERSION` (worker) | `2026-05-16-v23` | `prompts/__init__.py:18` |
| `EDITORIAL_PROMPT_VERSION` (PHP) | `2026-05-16-v23` | `class-epv2-ai-processor.php:15` |
| `STORY_CARD_PROMPT_VERSION` | `2026-05-11-v1` | `class-epv2-story-card-builder.php:29` |
| `VALIDATOR_VERSION` | `2026-05-12-v5` | `class-epv2-ai-response-validator.php` |

### Что работает (verified live 2026-05-17)
- ✅ Pipeline: 150-153 posts/day (≈50-51 bundles × 3 langs) — recovery после Q-fix #3
- ✅ Cache hit ≥60% (R5)
- ✅ Heartbeat <90s, thread_alive=true
- ✅ All 5 services active (worker, orchestrator, nginx, php-fpm, mariadb)
- ✅ Q-fix #1 UK Latin/Cyrillic normalizer
- ✅ Q-fix #2 EN Cyrillic/Latin normalizer + hard error fallback
- ✅ Q-fix #3 `inline_stage_attempt_cap` 2→4 для pipeline stages
- ✅ Q-fix #4 semantic translation prompt hints
- ✅ Story Card primacy (7 слоёв читают `_meta.story_card`)
- ✅ R8 fabricated_name (spaCy de NER), R9 plagiarism, R10 UK filler
- ✅ Anti-hallucination: detect_invented_numbers guard
- ✅ Source attribution linker (Zitatrecht)
- ✅ Auto mode policy (zero manual queue routes)

### Что отложено (observe-only)
- ⏸️ R3 `reactivate_media_recoverable_rows` — intentionally skipped (infra transient)
- ⏸️ R19 full code removal (semi mode + manual_review states) — 60-90 days observation
- ⏸️ R6 histogram tuning — accumulating ~1 week more data
- ⏸️ Hunspell DE/UK install — apt-get hang, not runtime-critical

### Known limitations
- Семантические AI ошибки шире prompt hints — ongoing prompt refinement
- Brand whitelist (35 entries) — новые бренды будут транслитерироваться
- Category confidence threshold 0.6 — иногда промах (raise до 0.75 если жалобы)

### Pending (track в TODO list)
- SEC: epv2-* services с root на dedicated user
- SEC: expose_php Off, nginx allow 127, admin email
- PERF: PHP-FPM + MariaDB + OPcache tuning
- Migration `europulse.eu` → `europulse.today`
- Legal: Impressum / V.i.S.d.P. / robots.txt / DSGVO

## 5. Где что лежит — canonical paths

```
/var/www/europulse/public/                                       ← WordPress live
├── wp-content/
│   ├── plugins/
│   │   └── europulse-autopilot-v21/                             ← Live plugin
│   ├── mu-plugins/
│   │   ├── europulse-foundation.php                             ← entry (autoloaded by WP)
│   │   ├── europulse-foundation.css
│   │   ├── europulse-normalize.css                              (live-only)
│   │   ├── europulse-ad-*.svg × 5                               (live-only)
│   │   └── europulse-foundation/                                ← subdirectory
│   │       ├── includes/
│   │       └── templates/
│   └── themes/
│       └── blocksy/                                             ← Theme

/root/projects/europulse/                                        ← Repo root
├── wp-plugins/
│   └── europulse-autopilot-v21/                                 ← Plugin SOURCE (нужен cp!)
├── wp-mu-plugins/
│   └── europulse-foundation/                                    ← mu-plugin SOURCE (нужен cp!)
├── worker-v21/
│   ├── src/epv2_worker/                                         ← Python worker (no separate live)
│   ├── epv2_bridge_orchestrator.py                              ← orchestrator
│   └── .venv/                                                   ← venv с spaCy
├── docs/
│   ├── PLUGIN_MAP_INDEX.md                                      ← этот файл
│   ├── PLUGIN_MAP_WORKER.md
│   ├── PLUGIN_MAP_QUEUE.md
│   ├── PLUGIN_MAP_AI_PROCESSOR.md
│   ├── PLUGIN_MAP_ADMIN.md
│   ├── PLUGIN_MAP_DATAFLOW.md
│   ├── PLUGIN_MAP_INTEGRATION.md
│   ├── PLUGIN_MAP_VISUAL.md
│   └── AUTONOMY_PLAN_DECISIONS.md
├── scripts/
│   └── cost_per_stage.sh                                        ← R22 cost report
└── backups/                                                     ← mysqldump daily
```

**КРИТИЧНО** (см. `deploy_sync_not_automatic.md`): repo `wp-plugins/` и `wp-mu-plugins/` НЕ автоматически синкаются с live. После edit:
```bash
cp -r /root/projects/europulse/wp-plugins/europulse-autopilot-v21/. \
      /var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21/
sudo -u www-data wp --path=/var/www/europulse/public cache flush
sudo systemctl reload php8.3-fpm
```
Worker — edit + `systemctl restart epv2-worker`. Source запускается напрямую, без копирования.

## 6. 8 доменных документов карты

| Документ | Покрывает | Когда читать |
|----------|-----------|--------------|
| **PLUGIN_MAP_INDEX.md** | этот документ + fragile points + site infrastructure | first-read для любой LLM |
| [PLUGIN_MAP_WORKER.md](PLUGIN_MAP_WORKER.md) | Python worker (13 модулей), orchestrator, AI calls, token economy | Меняешь rewriter/translator/prompts/orchestrator |
| [PLUGIN_MAP_QUEUE.md](PLUGIN_MAP_QUEUE.md) | `ep_epv2_queue`, state machine, admin_notes, watchdog, collector, deduplicator, cache | Меняешь queue logic, state transitions, watchdogs, cache |
| [PLUGIN_MAP_AI_PROCESSOR.md](PLUGIN_MAP_AI_PROCESSOR.md) | AI Processor, Publish Gate, Publisher, Categorizer, Story Card, Validators | Меняешь pipeline stages, gates, validators, publish flow |
| [PLUGIN_MAP_ADMIN.md](PLUGIN_MAP_ADMIN.md) | Admin UI блоки, AJAX, REST, CLI, Settings, Budget Manager, Time_Planner | Меняешь admin UI, REST, settings, schedule, scoring |
| [PLUGIN_MAP_DATAFLOW.md](PLUGIN_MAP_DATAFLOW.md) | End-to-end жизнь item'а, контракты PHP↔Worker, PROMPT_VERSION sync, failure & recovery | Меняешь cross-cutting integration, contracts, sync |
| [PLUGIN_MAP_INTEGRATION.md](PLUGIN_MAP_INTEGRATION.md) | mu-plugin, theme, post_meta, WP hooks, Polylang, capabilities, options | Меняешь interaction с WP core / theme / mu-plugin |
| [PLUGIN_MAP_VISUAL.md](PLUGIN_MAP_VISUAL.md) | 10 admin pages, queue blocks, AJAX, JS countdown, notice system | Меняешь admin UI / dashboard / queue page |

## 7. Quick reference — куда смотреть для типичных задач

### "Хочу поменять threshold/score logic"
- → PLUGIN_MAP_AI_PROCESSOR.md (Publish Gate, 15 blockers)
- → PLUGIN_MAP_ADMIN.md (Budget Manager publish_c, ai_delta, queue_delta)
- → PLUGIN_MAP_QUEUE.md (selection.scorecard storage)
- ⚠️ Effective threshold = publish_c + |ai_delta|. Менять publish_c у категорий с deltas = double effect!

### "Хочу поменять prompt'ы"
- → PLUGIN_MAP_WORKER.md (BASE_VOICE, TYPE_MODULES, RUBRIC_MODULES, translator system prompt)
- ⚠️ Обязательно bump `PROMPT_VERSION` в `prompts/__init__.py` + `EDITORIAL_PROMPT_VERSION` в `class-epv2-ai-processor.php:15` одновременно.

### "Хочу поменять Time_Planner schedule"
- → PLUGIN_MAP_ADMIN.md (8 окон mode schedule)
- → PLUGIN_MAP_DATAFLOW.md (Time_Planner mode → behaviour matrix)

### "Хочу поменять admin UI блок"
- → PLUGIN_MAP_ADMIN.md (queue blocks routing through `user_facing_state_for_row`)
- → PLUGIN_MAP_QUEUE.md (workflow_user_state_for_row fast-path)
- ⚠️ Fast-path читает stored stage_checklist — может быть stale.

### "Хочу поменять rate publishing"
- → PLUGIN_MAP_DATAFLOW.md (publish_not_before stagger)
- → PLUGIN_MAP_WORKER.md (publish_thread interval)
- ⚠️ **Спейсинг УЖЕ enforced через publish_not_before stagger в ai-processor.php:3446**.

### "Хочу поменять что считается duplicate"
- → PLUGIN_MAP_QUEUE.md (3-уровневый dedup: hash/URL → event_key → semantic 0.70 cosine)

### "Хочу добавить validator"
- → PLUGIN_MAP_AI_PROCESSOR.md (AI Response Validator + Publish Gate)
- → PLUGIN_MAP_WORKER.md (rewriter validators + ctx.warnings/blockers severity)

### "Перевод плохой / латиница в кириллице"
- → translator.py: `_fix_latin_cyrillic_hybrid_words` (UK) + `_fix_cyrillic_latin_hybrid_words_en` (EN)
- → memory: `translator_latin_cyrillic_fix.md`

### "Что-то застряло — куда смотреть"
- → PLUGIN_MAP_QUEUE.md (state machine + transitions + watchdogs)
- → PLUGIN_MAP_AI_PROCESSOR.md (10 кумулятивных gates)
- → PLUGIN_MAP_DATAFLOW.md (failure & recovery scenarios)

## 8. Главные fragile points (опасно менять без понимания)

### Multiple-enforcement points
1. **TTL** — `prune_new_stale` + `trim_*_aged` + watchdog cycles (3 механизма)
2. **Attempt caps** — `inline_short_circuit` + `workflow_quarantine_pathological_workflow_loops` + `recent_process_attempt_count` (3 enforcement). **Q-fix #3 2026-05-16**: limit для pipeline stages 2→4.
3. **Rate limiting publishing** — `publish_not_before` stagger + publish_thread interval + `publish_interval_minutes` setting
4. **State transitions** — `mark_state` + `update_fields` + DIRECT `$wpdb->update` (3 пути записи)
5. **Selection.decision** — set at collect, read by gate, cached в `admin_notes`
6. **`workflow_user_state_for_row`** — dual consumer: admin UI + orchestrator (fast path может lie)
7. **Story Card primacy** — 7 слоёв читают `_meta.story_card`. Confidence ≥ 0.6 override
8. **PROMPT_VERSION** — bump в PHP + worker одновременно (sync хрупкий, drop-stale mechanism)
9. **publish_thread heartbeat** — Python-only, без PHP visibility
10. **`admin_notes._system`** — 20+ ключей пишутся из разных мест. Race risk.

### Hidden traps
- **publish_c threshold с deltas**: kultur/sport/leben/community/muenchen/bayern имеют `ai_delta` -2..-6 — это compensating. Поднимать publish_c = double effect.
- **State 'new' + stored stage_checklist=true**: items reset'нутые watchdog'ом из rejected сохраняют `stage_checklist.ready_publish=true` → fast-path возвращает ufs=ready_publish ложно. (Fixed 2026-05-13)
- **`pgrep -f` matches its own bash command** — false alerts при мониторинге.
- **Effective threshold formula**: `publish_c + dynamic_delta`, см. `budget-manager.php:1133`.
- **Q-fix #3 `workflow_stage_attempt_limit`**: bumped 2→4 для pipeline stages. Не возвращать обратно — 60% throughput loss.

## 9. Q-fixes 2026-05-16 (translator quality + throughput)

### Q-fix #1: UK translator — Latin/Cyrillic hybrid
**Файл**: `worker-v21/src/epv2_worker/translator.py`
- `_LATIN_TO_CYRILLIC_CONFUSABLES` (40 chars)
- `_DE_TO_UK_DIGRAPHS` + `_DE_TO_UK_SINGLES` (DSTU 9112)
- `_KEEP_LATIN_TOKENS` (35 brand whitelist: AfD, CDU, NATO, EU, BMW, SAP...)
- `_fix_latin_cyrillic_hybrid_words()` — wired LAST в UK normalize chain
- Test: "Бärbel Bas" → "Бербел Бас", "чорнo-червонoї" → "чорно-червоної"

### Q-fix #2: EN translator — Cyrillic/Latin hybrid
- `_CYRILLIC_TO_LATIN_CONFUSABLES` (18 chars mirror)
- `_UK_RU_TO_EN_DIGRAPHS` + `_UK_RU_TO_EN_SINGLES` (BGN/PCGN + GOST)
- `_fix_cyrillic_latin_hybrid_words_en()` — wired BEFORE hard error check
- Test: "Mаrkus Söder" → "Markus Söder", "Зеленський" → "Zelens'kyy"

### Q-fix #3: throughput cap unblock
**Файл**: `queue/class-epv2-queue.php:5239` `workflow_stage_attempt_limit`
- limit 2→4 для `rebuild_bundle/translate_uk/translate_en/publish_finish/build_de_master/translate_finish`
- `publish_ready_gate` оставлен 2 (final check, retries rare)
- Recovered 60% throughput drop (15-16.05 50/51 bundles → 17.05 baseline)

### Q-fix #4: semantic translation prompt hints
- Added в `translator.py:_SYSTEM_PROMPT_TEMPLATE`
- "channel crossings" → "перетинання Ла-Маншу"
- "coalition" → "коаліція"
- "strike" → "страйк" vs "удар" (context)
- "operation" → "операція" vs "експлуатація"
- EN guards: no Cyrillic, German umlauts preserved
- UK guards: DSTU 9112, brand preserve

**PROMPT_VERSION bumped** v22 → v23 (worker + PHP).

## 10. Auto mode invariants

Все routes к `manual_review`/`ready_review` в `automation_requires_publish_grade()=true` (mode='auto') идут в `rejected`. См. `policy_auto_mode_no_manual_queue.md`. 7 мест в queue.php:
- `auto_route_misclassified_new_items` (~977)
- `auto_promote_complete_manual_review_items` (~1060) — flipped
- `sanitize_stale_ready_review` (~1302)
- `sanitize_stuck_ready_publish_items` (~1364)
- `quarantine_pathological_workflow_loops` (~1756) — drop importance gate
- `mark_state` redirect `manual_confirmation_required` (~2467)
- `soft_terminal_state_guard` (~3060) — bypass в auto

## 11. Baseline metrics (что считается normal)

| Метрика | Норма | Источник |
|---|---|---|
| Daily bundles published | 50-60 | published / 3 langs |
| Daily posts (DE+UK+EN) | 150-180 | `ep_posts WHERE post_status='publish'` |
| Conversion rate | ~24-30% | published / items с AI calls |
| AI cost / day | $2-3 | `cost_per_stage.sh 24` |
| Cached_tokens % | ≥60% | OpenAI cache hit (R5) |
| Heartbeat freshness | <90s | `epv2_publish_thread_heartbeat` |
| Avg process runs per item | ~2.06 | rebuild_bundle compounding |

**Drop signal**: <30 bundles/day = investigate (cap bug, gate change, AI budget hit).

## 12. Health check commands (skim первым делом)

```bash
# Services
systemctl is-active epv2-worker epv2-orchestrator nginx php8.3-fpm mariadb

# Worker
curl -sS http://127.0.0.1:8765/health

# Pipeline state
sudo -u www-data wp --path=/var/www/europulse/public db query \
  "SELECT state, COUNT(*) c FROM ep_epv2_queue GROUP BY state ORDER BY c DESC"

# Throughput
sudo -u www-data wp --path=/var/www/europulse/public db query \
  "SELECT DATE(post_date_gmt) d, COUNT(*) c FROM ep_posts WHERE post_status='publish' AND post_date_gmt >= CURDATE() - INTERVAL 3 DAY GROUP BY d"

# Heartbeat
sudo -u www-data wp --path=/var/www/europulse/public option get epv2_publish_thread_heartbeat

# Cost + cache
bash /root/projects/europulse/scripts/cost_per_stage.sh 24

# Version sync
grep PROMPT_VERSION /root/projects/europulse/worker-v21/src/epv2_worker/prompts/__init__.py
grep EDITORIAL_PROMPT_VERSION /var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php

# Q-fix #1/#2 verification (today's published)
sudo -u www-data wp --path=/var/www/europulse/public db query "
SELECT id, JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '\$.languages.uk.title')) uk_title
FROM ep_epv2_queue WHERE state='published' AND updated_at >= CURDATE()
  AND JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '\$.languages.uk.title')) REGEXP '[A-Za-zÄÖÜäöüß]'"
# Expect ONLY brands (AfD, EU, SAP, BMW, GLP-1...) — NO "Бärbel"-type hybrids
```

## 13. Cost-optimization notes (по результатам 2026-05-13 анализа)

### Что НЕ работает / не делать
- ❌ **Понижать rebuild_bundle cap 2→1** — 63% published items требовали 2-й cycle.
- ❌ **Повышать publish_c для всех категорий** — items отсеиваются раньше через `selection.decision`.
- ❌ **Добавлять rate limit в publish_thread** — система уже имеет 5-мин буфер через `publish_not_before` stagger.
- ❌ **Менять `rebuild_bundle → full_bundle` в worker-client.php:283** — quality fixes нужны в 2nd cycle.

### Что работает
- ✅ **OpenAI prompt caching** — auto с июля 2024 для system prompts ≥1024 tokens. R5 verified live 65% cache hit.
- ✅ **Auto-cleanup 2h** для rejected/manual_review/ready_review.
- ✅ **Hardening publish_thread** — try/except wrapper + heartbeat + watchdog respawn.

## 14. Расположение Session R-decisions (Waves 1-5 2026-05-14)

См. `/root/projects/europulse/docs/AUTONOMY_PLAN_DECISIONS.md` для деталей по каждому R-пункту. Кратко:

| Wave | R-pts | Theme |
|---|---|---|
| 1 | R1, R2 | P0 policy consistency (auto mode invariants) |
| 2 | R4, R5, R6, R7, R12, R13, R22 | Observability + tracking |
| 3 | R8, R9, R10 | Quality gates (spaCy NER, plagiarism, filler) |
| 4 | R14, R20 | Cost reduction |
| 5 | R11, R15, R16, R17, R18, R19, R21 | Resilience + extension surface |

**R3 intentionally skipped** (infra transient ≠ automation fail).

## 15. Files location

```
/root/projects/europulse/docs/
├── PLUGIN_MAP_INDEX.md              ← этот файл (first read for any LLM)
├── PLUGIN_MAP_WORKER.md             ← Python worker + orchestrator
├── PLUGIN_MAP_QUEUE.md              ← Queue + state machine + watchdog
├── PLUGIN_MAP_AI_PROCESSOR.md       ← AI Processor + gates + validators
├── PLUGIN_MAP_ADMIN.md              ← Admin UI + REST + CLI + Settings
├── PLUGIN_MAP_DATAFLOW.md           ← End-to-end flow + contracts
├── PLUGIN_MAP_INTEGRATION.md        ← mu-plugin + theme + WP hooks
├── PLUGIN_MAP_VISUAL.md             ← Admin pages, queue blocks, AJAX
└── AUTONOMY_PLAN_DECISIONS.md       ← Waves 1-5 R-decisions detail
```

## 16. Maintenance protocol

Карта **должна обновляться** при структурных изменениях:
1. После любого bump'а PROMPT_VERSION / EDITORIAL_PROMPT_VERSION — раздел "Версии"
2. После добавления validator / gate — PLUGIN_MAP_AI_PROCESSOR.md
3. После изменения Time_Planner schedule — PLUGIN_MAP_ADMIN.md
4. После добавления state — PLUGIN_MAP_QUEUE.md
5. После изменения admin блоков — PLUGIN_MAP_ADMIN.md
6. После significant Q-fix / fix — обновить раздел 9 + Project Status (раздел 4)

Также обновлять memory `project_status.md` чтобы snapshot держался свежим.
