# Worker + Orchestrator — карта (2026-05-14)

Документ-карта Python-стека EuroPulse Autopilot v21. Читать ПЕРЕД любыми правками.

Канонические пути:
- Worker: `/root/projects/europulse/worker-v21/src/epv2_worker/`
- Orchestrator: `/root/projects/europulse/worker-v21/epv2_bridge_orchestrator.py`
- WP-plugin caller side: `/root/projects/europulse/wp-plugins/europulse-autopilot-v21/includes/`

---

## 1. Архитектура

**Роли**
- **Orchestrator** (`epv2_bridge_orchestrator.py`) — systemd-демон без AI-кода. Только драйвер: timer-loop, дёргает PHP-bridge + spawn'ит wp-cli subprocess для collect/process.
- **Worker** (`src/epv2_worker/server.py`) — FastAPI на `127.0.0.1:8765`, persistent. Принимает HTTP от PHP. Всё AI (OpenAI/DeepSeek/Pexels) здесь.
- **PHP plugin** — фактический conductor. Owns БД, queue, gates. Worker и orchestrator — периферия.

**Lifecycle** (детали в §4)
1. `EPV2_Collector::run_scheduled()` — orchestrator triggers via wp-cli (collect_every ~60 мин).
2. `EPV2_Story_Card_Builder::build_for_queue_item()` — POST `/analyze_story`. Один upfront AI-call.
3. `EPV2_AI_Processor::process_scheduled()` — orchestrator triggers через wp-cli каждые 1-5 мин. PHP вытаскивает `new`/`retry_process`, POST `/process` к worker'у.
4. Worker возвращает payload → `EPV2_AI_Response_Validator` гейтит → mark_state.
5. `EPV2_Publisher::publish_due()` — orchestrator POST `/bridge/publish` (publish_thread каждые 30s).

**Network**
- Worker HTTP (`server.py:53,73,123`):
  - `GET /health`.
  - `POST /analyze_story` → `{queue_id, card, embedding}`. Caller: `class-epv2-story-card-builder.php:59`. Auth: `X-EPV2-Worker-Token`.
  - `POST /process` → `WorkerResponse + payload`. Caller: `class-epv2-worker-client.php:308` (`PROCESS_URL=http://127.0.0.1:8765/process`).
- Orchestrator → PHP-bridge через `index.php?rest_route=/epv2/v1/<path>` (`orchestrator:54-62`, header `X-EPV2-Bridge-Token`). Endpoints в `api/class-epv2-rest.php:9-101`: `/bridge/state`, `/bridge/publish`, `/bridge/maintenance`, `/bridge/breaking_scan`, `/bridge/server-orchestrator`. Plus wp-cli direct для collect/process.

---

## 2. Worker — модули и pipeline

### `server.py` (174)
FastAPI app. `ProcessRequest` (`server.py:24-49`) собирает API keys+content от PHP. `analyze_story()` (`server.py:73-119`) — `build_story_card()` + `compute_embedding()` параллельно (`asyncio.gather`). `process()` (`server.py:123-174`) → `run_pipeline()`. Validates `X-EPV2-Worker-Token`. Propagates `existing_payload._meta.content_kind` обратно для PHP `EPV2_Content_Kinds::detect_kind` cache hit.

### `pipeline.py` (992) — главный orchestrator
`PipelineContext` (`pipeline.py:40-93`) — `provider_order` приоритизирует OpenAI→DeepSeek. `run_pipeline()` (`pipeline.py:96`) ветвится по `stage`: `full_bundle` (default) или per-block regen (`title/lead/body/media/seo`).

`_run_full_bundle()` (`pipeline.py:139-540`) — 6 стадий:
1. **semantic** (`semantic_analyze`, `pipeline.py:144`) — TF-IDF key phrases, content_type, quality, word/sentence count.
2. **story_card seed** (`pipeline.py:156-203`) — читает `existing_payload._meta.story_card`. Card.category.confidence ≥ 0.6 → перетирает `ctx.categories`. Card.tags перетирают TF-IDF.
3. **enrichment** (`pipeline.py:213-256`) — Bing News RSS `_search_supporting_sources_rich()` для thin source (<500 words) или `needs_enrichment=True`. Returns `{url,title,domain}` для dossier-block.
4. **rewrite_de** (`pipeline.py:362-432`) — `rewrite_to_german()`. `kind+rubric_slug` → composed prompt (`compose_rewrite_prompt`). Иначе legacy length-profile. Plagiarism gate (≥85%). Soft validators (date/name/fabricated_name) → ctx.warnings; hard → ctx.blockers.
5. **translate** (`pipeline.py:434-512`) — UK+EN параллельно. `translate_from_german()` со story_card invariants + cross-language quote fidelity (target_lang == original_lang → передаём original_text).
6. **media + seo** (`pipeline.py:514-539`) — `find_media()` chain, `generate_seo()`.

`build_normalized_payload()` (`pipeline.py:889-977`) — финальный JSON: `languages.{de,uk,en}` + `card_lead/seo_title/meta_description/slug/focus_keywords`; `_meta.worker_prompt_version` (`pipeline.py:965`); `_meta.warnings/blockers`.

### `rewriter.py` (1359)
`rewrite_to_german()` (`rewriter.py:439-564`) — DE master. Token budget per `length_profile` (`rewriter.py:478`): brief 1024, standard 1536, long 2560, analysis 3500. `source_word_count < 35` → deterministic `_safe_ultrathin_rewrite()` (no AI). `_SYSTEM_PROMPT` (~328 lines): E-E-A-T, Quellenangaben, Faktenregeln, Anti-AI-tells, Russia-Ukraine editorial line (`rewriter.py:197-234`).

Soft validators в `_parse_json_result()` (`rewriter.py:776-847`):
- `_unsupported_explicit_dates()` (`rewriter.py:881-936`) — даты не в source. Whitelist: yearless current-year, today±2 с relative-hint.
- `_unsupported_generated_full_names()` (`rewriter.py:1223`) — surname без first_name в source. **Не adjacent** к `_unsupported_explicit_dates` — функция живёт ~342 строки ниже.
- `_detect_fabricated_proper_nouns()` (`rewriter.py:1227`) — R8 2026-05-14: severity=`hard` restored. Принимает `supporting_text` для cross-ref (Phase 1) + spaCy `de_core_news_lg` PER entity filter (Phase 2). FP epidemic resolved — compound nouns ("Bundesverteidigungsminister") отфильтрованы NER'ом.

`_sanitize_card_lead()` (`rewriter.py:672-704`) — валидирует AI-card_lead: 60-200 chars, заканчивается `.!?`, single sentence, нет attribution. Fail → "" (mu-plugin fallback).

### `translator.py` (1180)
`translate_from_german()` (`translator.py:183-252`) — UK/EN. `_SYSTEM_PROMPT_TEMPLATE` (`translator.py:46+`) ~100+ строк: voice, Genus-agreement, compound-nouns map, anti-filler (UK/EN), Russia-Ukraine vocabulary. Cross-language fidelity block (`translator.py:202-228`) если source_lang == target_lang. Story_card invariants через `_format_story_card_for_translator()` (`translator.py:255-329`). Post-AI normalizers `_normalize_ukrainian_*` (`translator.py:529-850`) — names, gender agreement, style, source-attribution position. `_count_ukrainian_filler_phrases()` (`translator.py:951`) → `style_filler_count` → ctx.warnings.

### `story_card.py` (512)
`build_story_card()` (`story_card.py:430-512`) — один upfront AI-call (gpt-5-mini primary, deepseek fallback). `_SYSTEM_PROMPT` (~177 lines): canonical category slugs, editorial stop-lists по rubric'ам, cross-tag rule. Output: `StoryCard` с `category{primary,confidence,secondary}`, `geography`, `entities_people/organizations/places`, `key_facts`, `tags`, `editorial_match`, `publishable_estimate`. PHP stores в `payload._meta.story_card` — single source of truth.

### `media.py` (238)
`find_media()` (`media.py:37-69`) — fallback chain: og:image/twitter:image из primary HTML → supporting URLs (max 3) → Pexels → Wikimedia → empty. NB: Wikimedia/Pexels никогда не featured (PHP gate, `feedback_no_wiki_pexels_as_featured.md`).

### `seo.py` (200)
`generate_seo()` (`seo.py:70-136`) — gpt-4o-mini → seo_title 50-60, meta_description 140-160, slug, keywords[5-8]. Story_card seo.primary_keyword → baseline. `_ensure_complete_sentence()` (`seo.py:21-43`) — sentence-aware truncate. Heuristic fallback.

### `semantic.py` (293)
Non-AI. TF-IDF key phrases, langdetect, quality score, content_type. `SemanticResult(detected_language, key_phrases[10], quality_score, content_type, needs_enrichment, word_count, sentence_count)`. `needs_enrichment = quality < 0.40 or wc < 150`.

### `plagiarism.py` (173)
`check_uniqueness()` (`plagiarism.py:81-138`) — Jaccard trigram overlap, stopwords + story_card entities drop. Default 85%. Callers: `rewriter._annotate_uniqueness()`, `translator._annotate_translation_uniqueness()`. Warning, не блокирует.

### `embeddings.py` (119)
`compute_embedding()` — text-embedding-3-small, 1536-dim. Title+excerpt+first 500 chars content. Phase 1: store-only (`payload._meta.semantic_embedding`), для dedup ещё не используется.

### `contracts.py` (122)
Dataclasses: `WorkerRequest`, `WorkerResponse`, `LanguagePackage`, `MediaCandidate`, `SemanticInfo`. `WORKER_OUTCOMES = {ready_publish, ready_review, retry_process}`.

### `openai_compat.py` (112)
SDK helpers для pinned openai==1.14. `reasoning_extra_body()` — `max_completion_tokens` + `reasoning_effort=minimal` через extra_body для gpt-5/o-family. `completion_total_tokens/cached_tokens` — prompt caching tracking.

**R5 2026-05-14: cached_tokens активно используется**. Pipeline emits в каждый ai_runtime entry (rewrite_de, translate_uk, translate_en, seo_de + analyze_story из R4). `_record_ai_runtime` (pipeline.py) принимает `cached_tokens` param; добавляется в entry только если > 0.

### spaCy NER (R8 Phase 2, 2026-05-14)
- `spacy==3.8.14` + `de_core_news_lg` (568MB) — German PER entity detection в `rewriter._detect_fabricated_proper_nouns`. Lazy-loaded singleton `_get_de_nlp()`.
- `uk_core_news_lg` (568MB) — installed для future R10 Phase 2 (filler detection enhancement, awaits R6 data).

### `prompts/`
- `__init__.py:18` — **`PROMPT_VERSION = "2026-05-13-v22"`** (single source of truth).
- `base_voice.py` (228) — `BASE_VOICE`: inverted pyramid, Quellenangabe, anti-AI-tells, anti-fabrication, uniqueness.
- `types.py` (324) — `TYPE_MODULES` dict (12 kinds: breaking_alert, news_brief, news_article, extended_news, analysis, feature, sport_result, obituary, interview, opinion, explainer, live_blog). Structural specs.
- `rubrics.py` (422) — `RUBRIC_MODULES` per category. `rubric_module()` (`rubrics.py:406`) inheritance: bayern/muenchen→deutschland, europa→welt.
- `compose.py` (70) — `compose_rewrite_prompt()` склеивает `BASE_VOICE + type + rubric + story_card + dossier + attribution + original + JSON-schema task`.

---

## 3. Orchestrator (609 строк)

**ENV** (`orchestrator:18-28`): `EPV2_SITE_URL`, `EPV2_BRIDGE_TOKEN`, `EPV2_LOOP_SECONDS` (15), `EPV2_MAINTENANCE_SECONDS` (180), `EPV2_IDLE/ACTIVE_PROCESS_COOLDOWN_SECONDS` (120), `EPV2_PROCESS_TIMEOUT_SECONDS` (900), `EPV2_COLLECT_TIMEOUT_SECONDS` (1200), `EPV2_PUBLISH_RETRY_COOLDOWN_SECONDS` (30).

**Main loop** (`orchestrator:475-605`), каждые 15s:
1. **Publish-thread watchdog** раз в 60s (`orchestrator:483-499`) — `publish_thread_healthy()`: `is_alive()` + `_publish_thread_last_beat` ≤ 5×interval. Stale → join(2s) + respawn.
2. `request_json("/bridge/state")`.
3. **Maintenance** каждые 180s (`orchestrator:510-513`) — POST `/bridge/maintenance` (PHP self-heal: auto_route, sanitize_stuck, trim_old, auto_promote, force_reject_zombie).
4. **Collect** каждые `collect_interval_minutes`×60 (`orchestrator:515-531`) — `run_collect_job()` → `EPV2_Collector::run_scheduled(true)` через wp-cli subprocess. Respects `collect_window_open` (night_monitor).
5. **Breaking-scan** на :00/:30 минуте + overshoot guard >32min (`orchestrator:533-562`) — POST `/bridge/breaking_scan`.
6. **Process** (`orchestrator:570-595`) — `run_process_job()` если `should_process(state)` + cooldown. После — refresh state, handoff immediate run если `active_automation_item` сменился.

**Publish thread** (`publish_thread_loop`, `orchestrator:311-378`): запускается через `start_publish_thread()` (`orchestrator:381-390`) при boot. Каждые 30s: `/bridge/state` → `should_publish()` → POST `/bridge/publish`. Heartbeat `_publish_thread_last_beat` после state-fetch и publish-fire. Inline retry 1× через 3s на 502/503/504. Back-off до 60s при consecutive_errors ≥ 5. **2026-05-13: main-loop publish УДАЛЁН** (`orchestrator:564-568`) — race за PHP publish_lock.

**Hardening**
- `_install_signal_handlers()` (`orchestrator:443-454`) — SIGTERM/SIGINT → `_publish_thread_stop.set()` → `sys.exit(0)`.
- `recover_timed_out_process_job()` / `recover_timed_out_collect_job()` (`orchestrator:103-187`) — kill process group, clean `epv2_lock_*`, item → `retry_process`, finish_with_errors stale run rows.
- `subprocess.Popen(..., start_new_session=True)` чтобы `os.killpg()` group целиком.

---

## 4. State flow per item

| State | Кто переводит туда | Когда | Куда дальше |
|---|---|---|---|
| `new` | `EPV2_Collector` после ingest | RSS-fetch + dedup pass | → `processing_de` (PHP processor takeover) |
| `processing_de` | `EPV2_AI_Processor::process_scheduled()` | Перед POST `/process` к worker'у | → `ready_publish` или `ready_review` или `retry_process` |
| `ready_publish` | Worker returned `outcome=ready_publish`, PHP validator passed | После `/process` 200 OK без blockers | → `publishing` |
| `publishing` | `EPV2_Publisher::publish_due()` | POST `/bridge/publish` fired | → `published` или `retry_publish` |
| `published` | `wp_insert_post()` succeeded | Permanent (можно reroll к `republish_pending`) | terminal |
| `ready_review` | Worker `outcome=ready_review` (blockers != []) ИЛИ validator failed | После `/process` | manual operator action |
| `retry_process` | Worker error / orchestrator timeout recovery / soft validator на retry-able bound | До retry-cap | → `processing_de` или `manual_review` |
| `manual_review` | Retry cap exceeded ИЛИ hard validator | `EPV2_Queue::mark_state` | manual operator |
| `rejected` | Story_card.publishable_estimate=reject ИЛИ editorial_match=reject_low_value ИЛИ dedup | Категоризатор / re-run | terminal |

**Transitions** (кто owner)
- Worker НИКОГДА не пишет в БД. Возвращает `outcome` в response — PHP `EPV2_AI_Response_Validator` решает `mark_state()`.
- Self-heal logic (раз в 180s) — auto_route stuck `processing_de` → `retry_process`, force_reject zombie `publishing` старше 10 мин (см. `automation_self_heal_layers.md`). **Класса `EPV2_Bridge_Maintenance` нет** — вся логика inline в REST callback `EPV2_REST::bridge_maintenance` (`api/class-epv2-rest.php:369-461`).
- Orchestrator recovery — только на собственный wp-cli subprocess timeout (`recover_timed_out_*`).

---

## 5. Token economy

Замеры из `_record_ai_runtime` payload entries в production (примерные средние per item):

| Stage | Model | Input tokens | Output tokens | Total per item |
|---|---|---|---|---|
| `analyze_story` (Story Card + embedding) | gpt-5-mini + text-embedding-3-small | ~1.5-2K prompt | ~600-900 (JSON card) | ~2.5-3K + ~700 (embed) |
| `rewrite_de` | gpt-4o-mini (или gpt-5-mini reasoning) | ~3-5K (system+composed+story_card+dossier+original) | brief 600 / standard 1200 / long 2000 / analysis 3000 | ~4-8K |
| `translate_uk` | gpt-4o-mini | ~2.5-4K (system+DE master+story_card+optional original) | ~1.5-2K | ~4-6K |
| `translate_en` | gpt-4o-mini | ~2.5-4K | ~1.5-2K | ~4-6K |
| `seo_de` | gpt-4o-mini | ~1-1.5K | ~400 (JSON) | ~1.5-2K |

**Total per item ~17-25K tokens** (full_bundle). Rebuild_bundle (per-block regen) — single stage, 2-8K depending on block.

Cached prompt tokens (OpenAI auto): tracked в `completion_cached_tokens` (`openai_compat.py:45-73`), 50% discount apply при repeat system-prompt. Worker emits в `ai_runtime[].tokens` total для PHP budget tracking.

---

## 6. Что точно НЕЛЬЗЯ менять без полного понимания

### A. PROMPT_VERSION
`prompts/__init__.py:18` И `class-epv2-ai-processor.php:15` ОДНОВРЕМЕННО. Иначе stale payloads drop'аются (`processor:1104`) ИЛИ новые prompts молча reuse'ятся через resume. Bump = full reprocess `processing_de`/`retry_process`.

### B. Publish stagger
Worker НЕ enforces timing — только `outcome`. `should_publish()` (`orchestrator:414-418`) читает `state.next_ready_publish` (PHP-computed). PHP `EPV2_Time_Planner` + `EPV2_Publisher::publish_due()` — единственный owner. Не возвращай main-loop publish call (был удалён 2026-05-13) — race за `epv2_lock_publish`.

### C. State transitions
Worker возвращает только `ready_publish/ready_review/retry_process` (`contracts.py:10`). Новый outcome — silent skip в PHP handler. `dead_letter` уже был удалён — schema lie (`contracts.py:7-9`).

### D. Story Card primacy
Order в `pipeline._run_full_bundle()` (`pipeline.py:139-256`): card load → semantic override → tags override. Card должна быть ИЛИ из PHP `analyze_story` ИЛИ пустая, никогда worker-mutated. Иначе downstream stages видят inconsistent state.

### E. Length profile mutation
`effective_length_profile` (`pipeline.py:237-248`) может отличаться от request (brief auto-promote к standard). Token budget из `length_profile` (`rewriter.py:478`) — не меняй один без другого.

### F. Card-lead pipeline
`rewriter.card_lead_de` → `pipeline.py:430,449,462` → `translator.card_lead_de` → `build_normalized_payload.languages.{lang}.card_lead` → PHP publisher → mu-plugin `_europulse_card_lead`. Любой missing step = mu-plugin fallback to excerpt.

### G. Soft vs hard validators
`rewriter.py:397-407`: hard → `ctx.blockers` (forces `ready_review`); soft → `ctx.warnings` (PHP decides). Не делай soft→hard без operator-обсуждения — FP epidemic на German compound nouns (`rewriter.py:830-843`).

---

## 7. PROMPT_VERSION пайп

**Emit (worker side):**
- `prompts/__init__.py:18` — `PROMPT_VERSION = "2026-05-13-v22"` (constant).
- `pipeline._get_prompt_version()` (`pipeline.py:31-37`) — lazy import.
- `build_normalized_payload()` (`pipeline.py:965`) — пишет в `payload._meta.worker_prompt_version`.

**Compare (PHP side):**
- `class-epv2-ai-processor.php:15` — `EDITORIAL_PROMPT_VERSION = '2026-05-13-v22'` (должна совпадать).
- `class-epv2-ai-processor.php:1103-1111` — в `process_item()` resume branch: если `existing_payload._meta.editorial_prompt_version` != current → log `drop_stale_payload_version_mismatch`, обнуляет `$existing_payload` + `$stored_selection` → forces fresh AI run на новом prompt'е.
- `class-epv2-ai-processor.php:2584` — пишет `EDITORIAL_PROMPT_VERSION` в `_meta` при store payload.
- `class-epv2-review.php:110` — pre-publish recheck.

**Mismatch behaviour:** payload reset, item проходит full pipeline заново. Item не теряется, retry budget не consumed. См. memory `editorial_prompt_version_stamp.md`.

---

## 8. Failure modes

**Worker dies (kill -9/OOM):** PHP `wp_remote_post` → connection refused → `EPV2_Worker_Client::call_worker()` returns WP_Error → item `retry_process`. systemd auto-restarts. Orchestrator не зависит от worker, продолжает.

**Provider 503:** `rewrite_to_german()` iterates `provider_order` (`rewriter.py:549`). OpenAI fail → DeepSeek. Both fail → `pipeline.py:379` → `ctx.blockers` → `outcome=ready_review`. Item не теряется. Story Card builder тоже provider-iterate (`story_card.py:460-507`).

**Orchestrator виснет:** systemd watchdog рестартанёт (если настроен). Single-point-of-failure: orchestrator dead → ничего не collect/process/publish. publish_thread daemon=True → killed с parent.

**wp-cli subprocess hangs:** `subprocess.communicate(timeout=...)` (`orchestrator:233-269`,`190-230`). Timeout → `os.killpg(SIGKILL)` → `recover_timed_out_process_job()` (`orchestrator:103-149`): `delete_option('epv2_lock_process')`, stale run rows → finished_with_errors, item → `retry_process`.

**publish_thread hangs (urlopen blocked):** `is_alive()=True` но `_publish_thread_last_beat` stale (>150s). Main-loop watchdog (`orchestrator:483-499`) каждые 60s → stop.set + join(2.0) + stop.clear + respawn. Реально случилось 2026-05-13 утром (25 мин без публикаций); watchdog решает.

**Worker token mismatch:** `server.py:84-86,127-128` → 403. PHP WP_Error → retry_process. `epv2_worker_shared_secret` settings == `EPV2_WORKER_TOKEN` env.

**Self-heal layers (PHP-side):** REST callback `EPV2_REST::bridge_maintenance` (`api/class-epv2-rest.php:369-461`; **отдельного класса `EPV2_Bridge_Maintenance` нет**) — auto_route, sanitize_stuck, trim_old, auto_promote, force_reject_zombie через `/bridge/maintenance` каждые 180s (`automation_self_heal_layers.md`). Сафтнет — queue не корруптится при падениях worker/orchestrator.
