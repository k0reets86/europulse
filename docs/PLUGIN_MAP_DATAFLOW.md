# Data Flow + Integration Map — EuroPulse Autopilot v21 (2026-05-14)

> Цель: показать end-to-end жизнь item'а — от RSS до публикации, со всеми точками решения и mapping'ом файлов которые её трогают. Все пути absolute. Все file:line — это `wp-plugins/europulse-autopilot-v21/includes/...` если не указано иначе. Worker: `worker-v21/src/epv2_worker/...`.

---

## 1. End-to-end жизнь item'а — sequence diagram

```
RSS feed (или Google News / HTML / Social)
  │
  ├─ EPV2_Time_Planner::should_collect()                  core/class-epv2-time-planner.php:76
  │   • current_window().mode + minute_allowed()
  │   • breaking watch overrides (always-on)              :81
  │
  ├─ EPV2_Collector::run_collect_cycle()                  ingest/class-epv2-collector.php
  │   • feed_reader / google_news / html / social readers
  │   • EPV2_Deduplicator (URL hash, title fuzz, signature) queue/class-epv2-deduplicator.php
  │   • EPV2_Story_Card_Builder::build_from_array($item)  ingest/class-epv2-collector.php:573-577
  │       → AI call → _meta.story_card (single source of truth)
  │   • EPV2_Budget_Manager::analyze_item($item, $source) ingest/class-epv2-collector.php:384,560
  │       → {score, decision∈{publish,review,low,reject}, tier, scorecard, dimensions}
  │   • should_send_to_ai / should_keep_in_queue gates    :408,427,624,643
  │   • EPV2_Story_Card_Builder::attach_to_payload        :702
  │   • INSERT ep_epv2_queue (state='new', ai_payload seed, admin_notes)
  │
ep_epv2_queue (state='new')
  │
  ├─ orchestrator main loop (epv2_bridge_orchestrator.py:475)
  │   • GET /bridge/state   каждые LOOP_SECONDS=15s       api/class-epv2-rest.php:41
  │   • should_process(state) → POST /bridge/process via wp-cli  :77
  │   • should_publish + publish_thread (parallel) → POST /bridge/publish  :83
  │
  ├─ EPV2_AI_Processor::process_scheduled()               ai/class-epv2-ai-processor.php:17
  │   • EPV2_Queue::bridge_next_processable_row('new')    queue/class-epv2-queue.php:4957
  │   • selection short-circuit:
  │       if _meta.selection.decision ∈ {low,reject}     ai-processor.php:527-547
  │       → terminal quarantine, NO worker call
  │   • Story Card version drift check                    ai-processor.php:119-128
  │   • inline_stage_attempt_cap (3 retries)              :1377
  │   • run_worker_stage($item, $stage, $existing_payload) :2549
  │       worker_client.post → POST http://worker:port/process
  │
  ├─ Worker: server.py /process → pipeline.process(req)   worker-v21/src/epv2_worker/server.py:123
  │   • rewriter.rewrite_de()                              rewriter.py
  │       prompt = BASE_VOICE + type + rubric + story_card
  │       validators: dates, names, fabrication, filler
  │   • translator.translate_uk + translate_en (parallel)  translator.py:192
  │       editorial_match guard via story_card             :201
  │   • media.find_featured + inline_candidates           media.py
  │   • seo.generate                                       seo.py
  │   • build_normalized_payload(response)                pipeline.py:889
  │       → {languages, categories, tags, media_url, _meta.{provider,tokens,
  │          source_dossier, quality, warnings, blockers, worker_prompt_version}}
  │
  ├─ PHP: outcome decision                                 ai-processor.php:1657-1700
  │   • outcome == 'ready_publish' + no blockers + no soft_warning
  │       → fast_transition_item_to_ready_publish        :3434
  │       → transition_item_to_ready_publish (gate eval) :3411
  │   • outcome == 'ready_review' OR payload_blockers
  │       → manual_review state, manual_confirmation_required=worker_blockers
  │   • soft_warning_verdict (filler ≥5 OR fab ≥2)
  │       → manual_review, manual_confirmation_required=soft_warning_threshold :1689
  │   • иначе → queue_required_stage(next_stage)
  │
  ├─ state='ready_publish'
  │   • _system.ready_publish_at = gmdate                ai-processor.php:3444
  │   • _system.publish_not_before = now + 5min          ai-processor.php:3446
  │   • EPV2_Queue::normalize_ready_publish_schedule()   queue.php:2164+
  │
  ├─ orchestrator publish_thread (15-30s)                orchestrator.py:311 (PUBLISH_RETRY_COOLDOWN_SECONDS=30)
  │   • POST /bridge/publish → REST → EPV2_Publisher::publish_item
  │
  ├─ EPV2_Publish_Gate::evaluate()                       publish/class-epv2-publish-gate.php
  │   • event_signature dedup (story_card-based)         :204,246,358
  │   • compute_event_signature
  │
  ├─ EPV2_Publisher::publish_item                        publish/class-epv2-publisher.php
  │   • WP post (master DE) + UK/EN translations (Polylang)
  │   • EPV2_Categorizer::assign                         classify/class-epv2-categorizer.php
  │   • EPV2_Source_Linker (inline attribution / Zitatrecht) publish/class-epv2-source-linker.php
  │   • strip_unbacked_backlinks
  │
  └─ state='published' + _europulse_card_lead meta + EPV2_Stats::bump
```

---

## 2. Контракты между слоями

### 2.1 PHP → Worker (HTTP)

`core/class-epv2-worker-client.php` сериализует `WorkerRequest` (see `worker-v21/src/epv2_worker/contracts.py:36-77`):

| Field | Type | Назначение |
|------|------|-----------|
| `queue_id` | int | ID row в `ep_epv2_queue` |
| `stage` | str | `full_bundle` / `build_de_master` / `rebuild_bundle` / ... |
| `story_kind`, `length_profile` | str | content_kinds.php дает (`ai/class-epv2-content-kinds.php`) |
| `original_*` | str | title/excerpt/content/url/date/image |
| `category_proposed`, `category_final` | str | category state |
| `editorial_flags` | dict | breaking, rerun_reason, ... |
| `existing_payload` | dict | resume from this payload |

### 2.2 Worker → PHP (HTTP return)

`WorkerResponse` (`contracts.py:80-109`) → `build_normalized_payload` (`pipeline.py:889-977`):

```
{
  languages: {de, uk, en} — каждый {lang, title, excerpt, card_lead, content,
                                    seo_title, meta_description, slug, focus_keywords, media_url},
  categories: [...],
  tags: [...],
  media_url, featured_media_url, inline_media_urls,
  source_block: source_dossier,
  _meta: {
    provider, model, ai_runtime[], tokens, fallback_provider_used,
    source_dossier, source_count, event_context,
    quality, seo_quality, release_quality, google_quality,
    warnings[], blockers[],
    canonical_language: 'de',
    worker_prompt_version  ← pipeline.py:965, equals prompts/__init__.py:18 PROMPT_VERSION
  }
}
```

Outcome принимает PHP в `ai-processor.php:1657+` через `sanitize_key($worker_response['outcome'])` ∈ {`ready_publish`, `ready_review`, плюс stage-specific intermediates}.

### 2.3 Orchestrator → Worker (HTTP, прямой)

- `GET  /health` (`worker-v21/.../server.py:53`)
- `POST /analyze_story` (`server.py:73`) — Story Card generation (отдельный endpoint, вызывает PHP через cron, не оркестратор)
- `POST /process` (`server.py:123`) — основной pipeline

Timeout: `PROCESS_TIMEOUT_SECONDS = 900s` (`orchestrator.py:26`, was 600). При expire → process group killed.

### 2.4 Orchestrator → PHP (HTTP `/bridge/*`)

Auth: `x-epv2-bridge-token` или `Authorization: Bearer ...`, validated в `EPV2_REST::can_bridge` (`api/class-epv2-rest.php:112-141`) через `hash_equals` против `EPV2_Settings::worker_shared_secret()`. nginx ACL restrict `/wp-json/epv2/v1/bridge/*` к loopback/known IPs.

Cached endpoints: `/bridge/state` использует `set_transient` ~30-60s (queue.php:4774,4783). Live: `/bridge/process`, `/bridge/publish`, `/bridge/maintenance`, `/bridge/breaking_scan`.

---

## 3. Кто пишет в `admin_notes._system`

Каждый ключ + file:line главного writer:

| Ключ | Writer | Reader |
|------|--------|--------|
| `workflow_step` / `_status` / `_attempts` | ai-processor.php:1186,1452,1811 | queue.php (state machine), admin.php |
| `workflow_owner_token` | ai-processor.php (claim/release in process_scheduled) | queue.php:2697 (stale_owner recovery) |
| `workflow_heartbeat_at` | ai-processor.php:595,1186,1319,1381,1452,1617,1680,3450 | queue.php:3794,3875,3958 (watchdog) |
| `workflow_terminal_reason` | ai-processor.php:1377 (cap), queue.php:1250,1920,2432,2539,2668 | admin.php (UI hint), queue.php:1004 |
| `quarantine_reason` | ai-processor.php:1609,1673; queue.php:1187 | admin.php, /bridge/state |
| `manual_confirmation_required` ∈ {content, worker_blockers, soft_warning_threshold, translation, media} | ai-processor.php:570,1622,1685,1691; queue.php clears после auto-promotion / terminal cleanup (audit не смог точно подтвердить line — needs human review) | queue.php:1026, admin.php |
| `soft_warning_verdict` | ai-processor.php:1689 | admin.php (UI badge) |
| `worker_outcome` / `worker_blockers` | ai-processor.php:1610-1611,1674-1675 | admin.php, audit log |
| `ready_publish_at` | ai-processor.php:3444; queue.php:2244,2266,2288,2316,2327 | publish_gate, admin.php |
| `publish_not_before` | ai-processor.php:3446; queue.php:2247,2269,2291,2319,2330,2490,2497,2503,2506 | queue.php:2011,2084,2120,2231,2477; publish_thread |
| `last_publish_gate_blockers` | ai-processor.php:1613,1676 | admin.php (UI), publish_gate |
| `last_stage_blocker` | queue.php:1924,2440 | admin.php |
| `next_operator_action` | queue.php:1255,1925,2434 | admin.php (UI guidance) |
| `manual_override` / `_at` | admin.php (operator action) | queue.php:1602-1610 (auto-cleanup guard) |
| `admin_promote_at` / `_reason` / `auto_promote_count` | function `auto_promote_complete_manual_review_items` (queue.php:1060); handling now ~1100-1150 (specific lines unverified after refactor) | admin.php, audit |
| `review_rebuild_signature` | ai-processor.php:5201 (write), :5240 (compare) | ai-processor.php:5197,5240 (drift detect) |
| `retries` | ai-processor.php (per stage) | inline_stage_attempt_cap check |
| `importance_score` / `_threshold` | ai-processor.php (importance_score.php result) | publish_gate |
| `context_memory` | resilience-manager.php:701 (lift из payload._meta) | dedup, regeneration |
| `workflow_recovery_reason` / `workflow_last_error` | queue.php:2697 (stale_owner); ai-processor.php:4537 (persisted_media_repair); queue.php:4391 (generated_cover_demoted) | audit |
| `live_status` / `live_status_code` | (set by publish flow, cleared at fast_transition) | admin diagnostics |

---

## 4. Кто читает `_meta.ai_runtime` / token accounting

- `pipeline.py:945-949` — worker emits `ai_runtime[]`, `tokens` (sum).
- `EPV2_Stats::record_payload_ai_usage($payload)` — queue.php:2326,3070 (mark_state hook), bumps daily counters.
- `EPV2_Stats::bump('collected'|'duplicates'|...)` — collector.php:139,365,554,678.

---

## 5. Story Card primacy — где соблюдается

`_meta.story_card` is single source of truth. Слои которые **читают и не переопределяют**:

| Слой | File:line | Поле |
|------|-----------|------|
| Categorizer | classify/class-epv2-categorizer.php:28-29,73-74,686 | `category.primary` + `confidence ≥ 0.6` гарантирует trust |
| Translator (worker) | translator.py:192,255,272,295 | `entities_people`, `key_facts` → translator guardrail |
| Importance Score (PHP) | ai/class-epv2-importance-score.php:51 | `_meta.story_card` |
| Publish Gate (PHP) | publish/class-epv2-publish-gate.php:87-88 | `editorial_match` |
| Deduplicator (PHP) | queue/class-epv2-deduplicator.php:403-406 | `context_memory.dates`, signature |
| Publish Gate event_signature | publish/class-epv2-publish-gate.php:204,358 | `compute_event_signature($story_card, ...)` |
| AI Processor resume | ai-processor.php:188-189,210,223,2698 | `category.primary` для resume metadata |

---

## 6. PROMPT_VERSION sync

**Worker side:**
- `worker-v21/src/epv2_worker/prompts/__init__.py:18` — `PROMPT_VERSION = "2026-05-13-v22"` constant
- `pipeline.py:965` — emits в `payload._meta.worker_prompt_version`

**PHP side:**
- `ai/class-epv2-ai-processor.php:15` — `EDITORIAL_PROMPT_VERSION = '2026-05-13-v22'`
- `ai-processor.php:2584` — stamps `payload._meta.editorial_prompt_version`
- `ai-processor.php:1103-1107` — на resume сравнивает `existing_payload._meta.editorial_prompt_version` с константой → drop stale

**Drift gap:** PHP **не читает** `worker_prompt_version` отдельно — версии bump'аются вручную в двух местах. Если worker bumped а PHP не → новый payload пройдёт через stale gate (потому что в payload пишет PHP константу). Это known design; pipeline.py:961-964 коментарий описывает intent но не enforce.

---

## 7. STORY_CARD_PROMPT_VERSION sync

- `ai/class-epv2-story-card-builder.php:29` — `STORY_CARD_PROMPT_VERSION = '2026-05-11-v1'`
- `story-card-builder.php:91` — stamps `$card['prompt_version']`
- `ai-processor.php:119-128` — drift detect on resume: если `story_card.prompt_version` != константа → rebuild card

PHP-only stamp; worker не участвует (Story Card строится из PHP через AI Client).

---

## 8. Time_Planner mode → behaviour matrix

Источник: `core/class-epv2-time-planner.php:30-51` (Berlin TZ).

| Mode | Hours (Berlin) | collect minutes | publish minutes | Notes |
|------|---------------|-----------------|------------------|-------|
| morning_catchup | 06:00–09:00 | [0] (1/час) | 0,5,10,...,55 | breaking_watch :00,:30 |
| daytime_active | 09:00–16:00 | [0] | 0,5,...,55 | — |
| daytime_peak | 16:00–19:00 | [0] | 0,5,...,55 | reduced from 2/час 2026-05-12 |
| evening_prime | 19:00–22:00 | [0] | 0,5,...,55 | — |
| wind_down_final | 22:00–23:00 | [0] (last) | 0,5,...,55 | last collect of day |
| wind_down_quiet | 23:00–00:00 | [] (off) | 0,5,...,55 | queue drains, no new ingest |
| night_open | 00:00–01:00 | [0] (single) | [] (off) | one midnight collect |
| night_monitor | 01:00–06:00 | [] | [] | breaking-only override (:00,:30) |

Breaking always bypasses (`time-planner.php:81`).

Publish budget cap envelope (`time-planner.php:346-352`): morning_catchup 0.00–0.15 → evening_prime 0.70–0.92 → wind_down 0.92–0.98 → night_monitor 0.98–1.00.

---

## 9. Caching layers

| Layer | Что | TTL | File:line |
|-------|-----|-----|-----------|
| Static-class cache | `EPV2_Settings::get` per-request | request lifetime | core/class-epv2-settings.php:156-166 |
| `set_transient` | `epv2_worker_available` | AVAIL_TTL ~5min | core/class-epv2-worker-client.php:39,382 |
| `set_transient` | Google News feed cache | CACHE_TTL | ingest/class-epv2-google-news.php:70-93 |
| `set_transient` | Google News URL resolve | URL_CACHE_TTL | google-news.php:166 |
| `set_transient` | AI client backoff/result | 5 min | ai/class-epv2-ai-client.php:44,272 |
| `set_transient` | queue gating flags (`y`/`n`) | 30s | queue/class-epv2-queue.php:4698,4705,4711 |
| `set_transient` | queue snapshot | 30-60s | queue.php:4774,4783 |
| `set_transient` | dashboard snapshot | 180s | queue.php:4861 |
| `set_transient` | admin queue snapshot | QUEUE_SNAPSHOT_CACHE_TTL | admin/class-epv2-admin.php:460,469 |
| Redis object cache (опц.) | Persisted transients когда redis-cache plugin active | — | feed-reader.php:20 (комментарий) |
| FastCGI cache (nginx) | wp-admin bypass; bridge endpoints не должны кешироваться (cookie/no-cache headers) | n/a | nginx-level |

---

## 10. Failure & recovery scenarios

| Сценарий | Кто чинит | Где |
|---------|-----------|-----|
| Worker process died | systemd `Restart=always RestartSec=5` | `/etc/systemd/system/epv2-worker.service` |
| Orchestrator died | systemd `Restart=always RestartSec=5` | `/etc/systemd/system/epv2-orchestrator.service` |
| publish_thread silent death / stuck | orchestrator watchdog ≤ 60s + 5× stale-heartbeat → respawn | orchestrator.py:393-400,478-499 |
| SIGTERM during publish | signal handler sets stop_event, exit 0 (graceful) | orchestrator.py:443-454 |
| `processing_de` stuck | `EPV2_Queue::sanitize_stuck_processing_de_items(20)` | api/class-epv2-rest.php:422 |
| `ready_publish` stuck (no progress) | `sanitize_stuck_ready_publish_items(50)` | rest.php:412 |
| `publishing` stuck | `sanitize_stuck_publishing_items(20)` | rest.php:417 |
| `manual_review` где payload complete | `auto_promote_complete_manual_review_items(30)` (cap 3 promotions, queue.php:1060) | rest.php:425 |
| Misclassified `new` (over cap) | `auto_route_misclassified_new_items(50)` | rest.php:408 |
| Pre-AI zombie rejects | `force_reject_zombie_pre_ai_rejects()` | queue.php:921 |
| Old terminal rows | `trim_old_terminal_items(80)` | rest.php:430 |
| AI provider 5xx | worker-side fallback (DeepSeek/OpenAI), retry inside `/process`; PHP retries by stage cap | ai-client.php + worker openai_compat.py |
| WP in maintenance mode (503) | publish_thread inline retry with exponential backoff up to 60s | orchestrator.py:369-374 |
| DB lock / timeout | mark_state retry inside `EPV2_Queue::mark_state` (best-effort) | queue.php |
| Stale `workflow_owner_token` | `workflow_recovery_reason='stale_owner'` rewrite | queue.php:2697 |
| Persisted media repair | `workflow_recovery_reason='persisted_media_repair'` | ai-processor.php:4537 |
| **publish_thread heartbeat stale (R7, 2026-05-14)** | Admin notice + R13 alert if `now - epv2_publish_thread_heartbeat['ts'] > 120s` | orchestrator.py:499+ POST `/bridge/heartbeat` каждый 60s; admin.php `render_publish_heartbeat_notice()` |
| **Polylang missing (R17, 2026-05-14)** | Auto-pause + Notifier critical alert на boot | plugin.php `EPV2_Plugin::boot` init+20 hook |
| **Rank Math disabled (R15, 2026-05-14)** | mu-plugin fallback: `wp_head` + `pre_get_document_title` render meta из `_epv2_*` mirror keys | mu-plugins/europulse-foundation/includes/content-seo-hooks.php |
| **Pipeline stall (R13, 2026-05-14)** | EPV2_Alerts Tier 1 Telegram alert + `do_action('epv2_pipeline_stalled', ...)` | bridge_maintenance every 180s |
| **Worker rewrite_de hard-blocked (R20, 2026-05-14)** | Skip translate_uk/translate_en — save 10-12K tokens | worker pipeline.py:443+ short-circuit |

---

## 11. Configuration source map

| Setting | Где задаётся | Override |
|---------|-------------|----------|
| Time schedule windows + breaking_watch | `core/class-epv2-time-planner.php:30-51` (defaults), `EPV2_Settings::get('time_schedule_profile')` override | `wp_options.epv2_settings` |
| Budget category thresholds (a/b/c/publish_c, dimensions) | `core/class-epv2-budget-manager.php:510-517` | code constants |
| Publish interval (5 min) | `EPV2_Settings::get('publish_interval_minutes', 5)` | option |
| Queue caps (`queue_new_max_per_category=8`, `..._per_source=6`, `queue_new_ttl_hours=5`) | rest.php:379-383, settings | option |
| Worker shared secret / endpoint | `EPV2_Settings::worker_shared_secret()`, `worker_endpoint` | option / env |
| Orchestrator loop timings | `/etc/default/epv2-orchestrator` (`EPV2_LOOP_SECONDS=15`, `EPV2_IDLE_PROCESS_COOLDOWN_SECONDS=120`, `EPV2_ACTIVE_PROCESS_COOLDOWN_SECONDS=120`, `EPV2_PROCESS_TIMEOUT_SECONDS=900`, `EPV2_PUBLISH_RETRY_COOLDOWN_SECONDS=30`) | systemd EnvironmentFile |
| Worker secrets (OpenAI/DeepSeek/Pexels/Wikimedia) | `/etc/default/epv2-worker` | systemd EnvironmentFile |
| `EDITORIAL_PROMPT_VERSION` | ai/class-epv2-ai-processor.php:15 (constant) | code |
| `STORY_CARD_PROMPT_VERSION` | ai/class-epv2-story-card-builder.php:29 | code |
| Worker `PROMPT_VERSION` | worker-v21/src/epv2_worker/prompts/\_\_init\_\_.py:18 | code |
| Plugin/option storage key | `EPV2_Settings::OPTION_KEY = 'epv2_settings'` (settings.php:8) | wp_options |

---

## 12. Fragile interaction points

5 самых опасных взаимодействий — изменение в одном файле ломает другое тихо:

1. **`publish_not_before` шинная переменная.** Set в ai-processor.php:3446 + queue.php:2247,2269,2291,2319,2330. Read в queue.php:2011 (`bridge_next_publish_candidate` SQL), 2084, 2120; orchestrator `should_publish` через `next_ready_publish` JSON. Если формат изменится (int vs string vs gmdate) — SQL `CAST AS UNSIGNED` молча даст 0 → пакет публикуется немедленно.

2. **`selection.decision` + `selection.scorecard.publish_c` set-once contract.** Записано collector'ом (`budget-manager.php:255-291`) при ingest. Read'ится PHASES позже: ai-processor.php:527-547 (short-circuit), :1163 (re-evaluation on resume), publish_gate. Если новая категория добавлена в budget-manager но не в content-kinds или publish_gate → item застрянет с `decision=reject` без причины в UI.

3. **`workflow_user_state_for_row` (queue.php).** Используется admin UI rendering И orchestrator decisions (через `bridge_state`). Изменение state-machine label в `workflow_user_state_for_row` без update обоих consumer'ов даст false hint в UI И wrong action в auto-router (`auto_route_misclassified_new_items`).

4. **`_meta.story_card.category.primary` + `confidence`.** Categorizer (classify/class-epv2-categorizer.php:28-29,73-74) trust только при `confidence ≥ 0.6`. Если worker rewriter изменил category но Story Card остался stale → category в queue row и в payload разные → publish_gate gleans `editorial_match`, dedup использует stale signature → duplicate publish risk.

5. **`EDITORIAL_PROMPT_VERSION` vs `worker_prompt_version` (drift).** PHP константа stamp'ает payload (ai-processor.php:2584), worker emit'ит свою версию в тот же `_meta` (pipeline.py:965). PHP `EDITORIAL_PROMPT_VERSION` overwrites worker'ский в pipeline.py call chain (PHP read'ает после worker return). Bump worker'а без bump PHP константы → новые payload'ы стампятся **старой** PHP версией → stale-drop guard (ai-processor.php:1104) не сработает на resume.

6. **(бонус)** `_system.publish_thread` heartbeat checked в orchestrator only — нет PHP-side observability. Если orchestrator killed before respawning thread → `ready_publish` items накапливаются и admin UI не показывает причину.

---

**End of map.** ~2400 слов. Все file:line из живого репозитория `wp-plugins/europulse-autopilot-v21` + `worker-v21` на 2026-05-14.
