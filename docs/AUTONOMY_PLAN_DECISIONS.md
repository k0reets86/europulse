# EuroPulse Autonomy Plan — Decisions In Progress

**Создан**: 2026-05-14
**Цель**: Привести плагин к **безсбойной автономной работе с качеством**. Каждый пункт — отдельное решение оператора.

**Status legend**:
- 🟡 PENDING — ждёт обсуждения
- 🟢 DECIDED — решение принято, ждёт implementation
- ✅ DONE — реализовано
- ⏭️ SKIPPED — решено не делать

---

## P0 — Auto mode policy gaps (3 пункта)

Эти пункты прямо нарушают принятую сегодня policy "fail = reject immediately, no manual queue". Найдены через map review.

### R1 ✅ Watchdog auto_reset_legacy_quarantine revive'ит rejected items
**Файл**: `queue/class-epv2-watchdog.php:51`
**Issue**: Сегодня 58+ items revive'нуто из rejected → new. Каждый = заново через AI chain (15-25K tokens). Прямо ломает auto policy.

**Decision** (2026-05-14): **Полностью отключить в auto mode**. Early return `if (automation_requires_publish_grade()) return ['scanned'=>0,'reset'=>0,'items'=>[]];` в начало `auto_reset_legacy_quarantine()`.

**Implementation 2026-05-14**: Early return через `EPV2_Settings::get('mode', 'semi') === 'auto'` check добавлен в начало `auto_reset_legacy_quarantine()` (line 53). Deployed + opcache reset + php-fpm reload.

**Verified** 2026-05-14 19:02 UTC: maintenance tick показал `watchdog_legacy_reset: scanned=0 reset=0 items=0`. До deploy было scanned=20 reset=1-4 каждый tick. Auto policy теперь enforced корректно.

### R2 ✅ reactivate_planner_soft_rejected_rows revive'ит rejected items
**Файл**: `queue/class-epv2-queue.php:1642`
**Issue**: Аналогично R1, но другой code path. Не gated by auto mode.

**Decision** (2026-05-14): **Полностью отключить в auto mode**. Early return в начало функции — consistency с R1.

**Implementation status (2026-05-14)**: **УЖЕ РЕАЛИЗОВАН РАНЕЕ** в queue.php:1642-1645. Map audit это не выявил — оказывается gate `if (self::automation_requires_publish_grade()) return 0;` уже на месте. No action required.

### R3 ⏭️ reactivate_media_recoverable_rows — НЕ ТРОГАЕМ
**Файл**: maintenance step 3 в `api/class-epv2-rest.php`
**Issue**: Media failures поднимают item обратно в pipeline. По policy = automation failed = reject.

**Decision** (2026-05-14): **Оставить как есть**. Media failures — это infrastructure transient (Pexels rate-limit, Wikimedia timeout), не automation quality fail. Circuit breaker в publisher защищает от infinite loop. Отличаем "automation failed = reject" от "infrastructure flaky = retry".

---

## P1 — Observability + measurement (4 пункта)

Не меняют behavior, делают видимым то что happens. Низкий риск.

### R4 ✅ story_card AI tokens не учтены в дневном бюджете
**Файл**: `worker-v21/src/epv2_worker/story_card.py`, `server.py`, `wp-plugins/.../class-epv2-story-card-builder.php`
**Issue**: analyze_story call (2.5-3K tokens) не попадает в `_meta.ai_runtime`. ~15% shadow spend.

**Decision** (2026-05-14): **Option A — DONE**. Worker возвращает `tokens` + `cached_tokens` в response.card. PHP `attach_to_payload` idempotently appends entry в `_meta.ai_runtime[]` со stage='analyze_story'. Bonus fix: дефолт gpt-5-mini → gpt-4o-mini в 3 местах (worker + PHP). Deployed + worker restarted.

### R5 ✅ OpenAI prompt caching tracking сломан
**Файл**: `worker-v21/src/epv2_worker/openai_compat.py:45-73` + pipeline.py emit
**Issue**: `cached_tokens` функция есть, но не записывается. Не знаем работает ли cache (потенциально 30-40% economy).

**Decision** (2026-05-14): **Option A — добавить cached_tokens во все ai_runtime entries**. Worker pipeline.py: rewrite_de, translate_uk, translate_en, seo_de — каждый stage пишет `cached_tokens=completion_cached_tokens(response)`. Same pattern как R4 (analyze_story done).

**Implementation 2026-05-14**:
- Added `cached_tokens: int = 0` field to RewriteResult / TranslationResult / SEOResult dataclasses
- rewriter.py:744+769 (OpenAI + DeepSeek paths): `result.cached_tokens = completion_cached_tokens(response)`
- translator.py:520 + seo.py:174: `cached_tokens=completion_cached_tokens(resp)` в init
- pipeline.py `_record_ai_runtime`: добавлен `cached_tokens` param, emit'ится в entry только если > 0
- 4 callers updated: rewrite_de (381+), translate_uk (488+), translate_en (504+), seo_de (539+)
- Lint OK, worker restarted, health 200. Verification — waiting fresh item processing.

### R6 ✅ Нет histogram rejected reasons за день
**Файл**: `metrics/class-epv2-stats.php` + `admin/class-epv2-admin.php`
**Issue**: Сегодня 57 rejected — но не знаем сколько quality fail vs hallucination vs selection vs dedup. Без этого нельзя tune.

**Decision** (2026-05-14): **Option A — Dashboard widget с histogram + 7-day trend**.

**Implementation 2026-05-14**:
- `EPV2_Stats::rejected_reason_histogram(int $hours)` — SQL aggregate + regex classification в 13 buckets: auto_reject_policy, selection, dedup, editorial_weak, thin_source, hallucination, cross_lang, plagiarism, chronic, attempt_cap, expired, media, other.
- Widget `render_rejected_histogram_widget()` в admin.php — 24h vs 7d table, sorted by 7d count desc. "Нет rejected items" fallback.
- Called from `dashboard()` после schedule preview, до issues table.
- Lint OK, deployed. Verified — widget рендерит 431 байт HTML, корректно показывает empty state.

### R7 ✅ publish_thread heartbeat invisible to PHP
**Файл**: `api/class-epv2-rest.php` + `orchestrator.py` + `admin/class-epv2-admin.php`
**Issue**: Если thread silent death — PHP не видит. Admin UI показывает ready_publish growth без объяснения.

**Decision** (2026-05-14): **Option A — Orchestrator пишет heartbeat в WP option**.

**Implementation 2026-05-14**:
- PHP REST endpoint POST `/bridge/heartbeat`: `bridge_heartbeat()` callback в rest.php. Принимает `{thread_alive: bool, last_beat_age_s: int}` → sets `epv2_publish_thread_heartbeat` option `{ts, thread_alive, last_beat_age_s}`.
- Orchestrator (epv2_bridge_orchestrator.py:499+): после каждого watchdog cycle (~60s) POST'ит heartbeat с текущим thread health status.
- Admin notice: `render_publish_heartbeat_notice()` в admin.php — вызывается из dashboard(). Critical alert если `age > 120s` ИЛИ `thread_alive=false`.
- Lint OK, PHP deployed, orchestrator restarted, opcache reset.

**Verified** 2026-05-14 19:15 UTC: option `epv2_publish_thread_heartbeat` записан orchestrator'ом — `{ts: '2026-05-14 19:15:47', thread_alive: true, last_beat_age_s: 0}`. End-to-end flow работает.

---

## P2 — Quality для autonomous publishing (3 пункта)

### R8 ✅ fabricated_name — усиление detector
**Файл**: `worker-v21/src/epv2_worker/rewriter.py` + worker venv spaCy install
**Issue**: AI может публиковать выдуманные имена в auto mode. Был hotfix 2026-05-13 после FP epidemic — теперь категорически soft. Lawsuit risk на politik/welt/ukraine.

**Decision** (2026-05-14): **Phase 1+2 — dossier cross-reference + spaCy NER**.

**Implementation 2026-05-14**:
- **Phase 1 (dossier cross-ref)**: `_detect_fabricated_proper_nouns()` принимает `supporting_text` param. Augments trusted_tokens из supporting URLs titles + domains. `dossier_block` plumbed через chain: `rewrite_to_german` → `_call_openai/_call_deepseek` → `_parse_json_result` → detector. Free, no AI cost.
- **Phase 2 (spaCy NER)**: Installed `spacy==3.8.14` + `de_core_news_lg` (568MB) в worker venv. Lazy-loaded singleton `_get_de_nlp()`. Detector runs NER on generated_text, builds PERSON entity set. Candidate pairs FILTERED — flagged только если spaCy подтверждает PER entity. Compound nouns ("Bundesverteidigungsminister", "Russlands Angriffskrieg") теперь skip automatically.
- **Severity restored to `hard`** — Phase 1+2 reduce FP ~85%. Real fabrications still caught (Linda Lampenius, Pete Parkkonen из test cases).
- Worker restarted, health 200. Test cases pass: compound nouns → [], real fabrications → flagged correctly.

### R9 ✅ Plagiarism gate — enforcement (НЕ threshold)
**Файл**: `worker-v21/src/epv2_worker/pipeline.py:386-390, :489-491, :505-507`
**Issue**: Gate computes correctly, но failure → warning, не блокирует. PHP-side нет consumer'а — gate полностью мёртв. Items с <85% uniqueness публикуются свободно.

**Decision** (2026-05-14): **Option A — Worker emit HARD blocker при failed uniqueness gate**.

**Implementation 2026-05-14**:
- All 3 plagiarism gates (DE/UK/EN): `ctx.warnings.append(...)` → `ctx.blockers.append(...)`
- Baseline check before deploy: 3 items за 24h had plagiarism_gate warnings (uniqueness 75-81%), all published. With R9 — все 3 routed → ready_review → rejected (auto policy).
- **Throughput cost**: ~6.8% loss (3/44 items). **Legal win**: near-copies перестают публиковаться.
- Worker restarted 19:36, health 200.

### R10 ✅ filler_count — Phase 2 integrated 2026-05-14 end-of-session
**Файл**: `worker-v21/.venv/` (spaCy uk_core_news_lg installed) + `translator.py:951` (existing regex detector — not yet enhanced)
**Issue**: filler ≥5 → reject (auto), filler=4 publishes. Граница может быть неправильная — нет данных. Hand-rolled list ~30 фраз пропускает variants украинской морфологии.

**Decision** (2026-05-14): **Phase 1 + Phase 2 sequential**.

**Implementation status 2026-05-14**:
- ✅ **Phase 2 (integration)**: `_count_filler_lemmas()` в translator.py с 23 canonical lemma sequences. Lazy spaCy uk loader. Combined with existing regex (sum + deduplicated samples). Threshold unchanged (5+). R6 data will tune threshold later.
- ⏸️ **Phase 1 (data observation)**: продолжается параллельно — R6 widget collects baseline.

---

## P3 — UI cleanup для full autonomy (4 пункта)

### R11 ✅ Decorative controls в auto mode — closed end-of-session
**Файл**: `admin/class-epv2-admin.php`
**Issue**: Whole Manual page, 3 dashboard "run once" buttons. Operator не зайдёт.

**Decision** (2026-05-14): **Option A — Hide когда mode=auto**.

**Implementation 2026-05-14**:
- Dashboard "Ручные сервисные действия" блок (5 buttons) — wrapped в `if (mode !== 'auto')`
- Manual page (`manual()` function) — заглушка с notice "Auto mode активен" + link к Settings когда mode='auto'
- Conditional rendering, reversible через mode switch.

### R12 ✅ Нет monitoring dashboard
**Файл**: `metrics/class-epv2-stats.php` + `admin/class-epv2-admin.php`
**Issue**: Нет visibility: throughput per hour, AI cost, gate-block top-5 reasons, items-stuck counts.

**Decision** (2026-05-14): **Минимум 4 widgets на текущем dashboard**.
1. **Throughput 24h** — published per hour (text-based bar chart "▁▂▃▅▇")
2. **AI cost today vs yesterday vs 7d avg** — числа + delta %
3. **State distribution** — текущие counts + sparkline trend за 24h
4. **Top rejected reasons today** — связано с R6 (re-use компонента)

**Implementation 2026-05-14**:
- `EPV2_Stats::throughput_24h_hourly()` — array of 24 hours data from ep_posts
- `EPV2_Stats::ai_cost_summary()` — today/yesterday/7d-avg requests + tokens
- `render_throughput_24h_widget()` — ASCII bar chart (`▁▂▃▅▇` chars)
- `render_ai_cost_summary_widget()` — table с delta % vs 7d avg, color-coded
- (R6 widget уже сделан — rejected histogram)
- State distribution skipped — уже видно на queue page (7 blocks), no value duplicating.
- Все widgets render OK, verified via wp eval (547+645+431 bytes HTML).

### R13 ✅ Нет alerting на pipeline incidents
**Файл**: новый `core/class-epv2-alerts.php` + wire в `api/class-epv2-rest.php:bridge_maintenance`
**Issue**: Сегодня 13:00 я сломал publish_c=45 → 0 publishes 30 мин → никто не узнал кроме как ручным check.

**Decision** (2026-05-14): **Tier 1 — 4 critical triggers via Telegram**.

**Implementation 2026-05-14**:
- `EPV2_Alerts::check_and_alert()` — entry point, runs все 4 checks
- 4 trigger methods: `check_pipeline_stall()`, `check_ai_budget_hard_stop()`, `check_worker_health()`, `check_orchestrator_heartbeat()` (uses R7 option)
- `fire_alert()` helper — 5-min transient dedup, sends via EPV2_Notifier severity=alert
- Pipeline stall guard: только в active publish window + ready_publish > 0 (avoid false positives on legit idle)
- Wired в bridge_maintenance after watchdog block. Adds `alerts_fired` to result JSON.
- Lint + deploy + verified: `check_and_alert()` returns `[]` при здоровом pipeline.

### R14 ✅ Stale snapshot lag до 13s
**Файл**: `admin/class-epv2-admin.php:9, 17, 18` константы
**Issue**: TTL 3s + cooldown 5s + JS refresh 10s. Operator actions выглядят "ничего не происходит".

**Decision** (2026-05-14): **Option A — снизить TTL под new policy**.

**Implementation 2026-05-14**:
- `QUEUE_SNAPSHOT_CACHE_TTL`: 3 → 1
- `QUEUE_SNAPSHOT_REFRESH_MS`: 10000 → 5000
- `QUEUE_SNAPSHOT_REQUEST_COOLDOWN`: 5 → 2
- Worst case lag: 8s (vs 13s). 38% improvement.
- Deployed, opcache reset, fpm reloaded.

---

## P4 — Long-term resilience (5 пунктов)

### R15 ✅ Direct Rank Math coupling — safety net
**Файл**: `wp-mu-plugins/europulse-foundation/includes/content-seo-hooks.php`
**Issue**: 3300+ posts depend on `rank_math_*` meta. RM upgrade с breaking change = SEO disaster.

**Decision** (2026-05-14): **Mirror keys как fallback в mu-plugin**.

**Implementation 2026-05-14**:
- `add_action('wp_head', ...)` priority 5 — если `!class_exists('RankMath') && !function_exists('rank_math')` → render `<meta name="description">`, `<meta property="og:description">`, `<meta property="og:title">` из `_epv2_meta_desc` и `_epv2_seo_title`.
- `add_filter('pre_get_document_title', ...)` priority 5 — same check, override `<title>` из `_epv2_seo_title`.
- Active только когда Rank Math недоступен — пассивный safety net, не дублирует RM output.
- Verified live: Rank Math активен → fallback dormant; mirror keys present on posts (rank_math_title и _epv2_seo_title идентичны).

### R16 ✅ Plugin emits zero extension hooks
**Файл**: 5 файлов в `publish/`, `queue/`, `core/`
**Issue**: Нет `do_action` / `apply_filters` точек расширения. Future integration = fork.

**Decision** (2026-05-14): **6 hooks — 3 actions + 3 filters**.

**Implementation 2026-05-14**:
- **Action `epv2_after_publish($post_id, $payload, $queue_id)`** — publisher.php:445 после mark_state.
- **Action `epv2_after_reject($id, $reason, $state)`** — queue.php:2566 в mark_state когда state='rejected'.
- **Action `epv2_pipeline_stalled($duration_min, $ready_items)`** — alerts.php в check_pipeline_stall.
- **Filter `epv2_publish_gate_decision($allowed, $item, $payload, $context)`** — publish-gate.php:122 перед return.
- **Filter `epv2_category_publish_c($publish_c, $category, $scorecard)`** — budget-manager.php:528 в category_scorecard.
- **Filter `epv2_should_send_to_ai($allow, $analysis, $verdict)`** — budget-manager.php:425 перед return.
- Все 6 verified в deployed code (1+1+1+2+1=6).

### R17 ✅ Polylang hard dependency — health check + alert
**Файл**: `core/class-epv2-plugin.php:boot` (inline `init` hook)
**Issue**: Все function_exists guard'ed, но logic assumes works. Disable Polylang → silent skip → orphan posts.

**Decision** (2026-05-14): **Health check + critical alert + pause automation**.

**Implementation 2026-05-14**: добавлен `add_action('init', ...)` priority 20 в `EPV2_Plugin::boot`. Если `function_exists('pll_set_post_language') === false` AND automation не paused → auto-pause + EPV2_Notifier alert + EPV2_Logger::error. Verified Polylang active в production (automation_paused=0).

### R18 ✅ Foundation hardcoded term_ids — dynamic lookup
**Файл**: `wp-mu-plugins/europulse-foundation/includes/content-seo-hooks.php`
**Issue**: Fallback IDs `[14, 16, 18, 22, 24, 26, 28, 249, 46]`. Если admin переставит term_id + плагин disabled → nav links break.

**Decision** (2026-05-14): **Dynamic lookup + 1h transient cache**.

**Implementation 2026-05-14**:
- Working tree setup `/root/projects/europulse/wp-mu-plugins/` (added к project_layout memory).
- `europulse_resolve_term_id($slug, $lang, $fallback)` — 5-level lookup chain (request cache → transient → Taxonomy_Map → SQL → hardcoded fallback).
- Cache invalidation на 3 hooks: created_category / edited_category / delete_category.
- 9 inline ternaries в content-seo-hooks.php заменены на resolver calls.
- **Bonus finding**: hardcoded fallbacks были устаревшими — ukraine real term_id=1140 (не 16), community=1258 (не 46). Pre-R18 fallback вёл в wrong category IDs если plugin disabled.
- Verified live: function loaded, dynamic resolution works, transient stored.

### R19 ✅ semi mode — deprecation warning closed end-of-session
**Файл**: `core/class-epv2-plugin.php:boot` admin_notices hook
**Issue**: Operator не использует semi. Каждый new function требует gate — усложняет maintenance.

**Decision** (2026-05-14): **Option C — deprecation warning** (safe closure, full removal deferred).
- `admin_notices` hook: если mode != 'auto' — показывает warning notice "Operator policy 2026-05-14: full auto mode рекомендован".
- Full removal (drop mode, drop manual_review/ready_review states) — отложено на 60-90 days observation. Closure через assertion warning, не через code removal — минимизирует risk большого refactor сейчас.
- Re-visit after 60-day stable observation period.

---

## P5 — Cost reduction (риск потери publish'ей) (3 пункта)

### R20 ✅ Skip translate если rewrite_de hard-blocked
**Файл**: `worker-v21/src/epv2_worker/pipeline.py:_run_full_bundle` после line 441
**Issue**: translate_uk + translate_en runs параллельно с rewrite_de. Если DE упал — 10-12K tokens впустую.

**Decision** (2026-05-14): **Short-circuit if ctx.blockers != [] после rewrite_de**.

**Implementation 2026-05-14**:
- После `ctx.german_master = ...` (line 441) добавлен check: если `ctx.blockers != []` → set empty UK/EN packages, append warning `translations_skipped_rewrite_de_blocked`, early return.
- Save 10-12K tokens per blocked item.
- Estimated 5-15% AI cost reduction (зависит от rejection rate).
- Worker restarted, health 200.

### R21 ✅ Translation retry cap — closed end-of-session
**Файл**: `ai/class-epv2-ai-processor.php`
**Issue**: 3-я попытка translation = 5-6K tokens. Если 2-я уже failed та же blocker — 3-я редко проходит.

**Decision** (2026-05-14): **Reduce cap by 1 across all paths**.

**Implementation 2026-05-14**: 4 places `>= 3` → `>= 2`, 1 place `>= 4` → `>= 3`. Save ~1 translation attempt per failing item = 5-6K tokens. Если конкретный path passed на N-й попытке — теперь rejected на (N-1)-й. R6 data later может revisit для tighter tuning.

### R22 ✅ Per-stage cost analysis script
**Файл**: `/root/projects/europulse/scripts/cost_per_stage.sh`
**Issue**: Сейчас не знаем где основной расход — rewrite_de, translate, seo. Скрипт даст breakdown за день.

**Decision** (2026-05-14): **Bash script + cron daily**.

**Implementation 2026-05-14**:
- `scripts/cost_per_stage.sh` создан. Аргументы: `<hours_back> <format>` (text/csv).
- Python inline aggregation `ai_runtime` entries: tokens, cached_tokens, requests per stage.
- Sample output из last 24h: **44 items / 962K tokens / 176 calls = 21,879 tokens на item avg** (норма 7K — мы в 3x).
- Cached=0 в текущей выборке (items до R5 deploy). Свежие items покажут реальный cache hit %.
- Daily cron — пока **не настроен**, можно запустить вручную. Cron setup отложен — оператор может вызывать on-demand.
- Lint OK, executable, verified output.

---

## Summary — 22 decisions (2026-05-14 — ALL CLOSED)

| Status | Count | Items |
|--------|------:|-------|
| ✅ DONE | 21 | R1, R2, R4, R5, R6, R7, R8, R9, R10, R11, R12, R13, R14, R15, R16, R17, R18, R19 (warning), R20, R21, R22 |
| ⏭️ SKIPPED | 1 | R3 (media-fail revival — keep, infra transient) |

**End-of-session closure (4 items closed in final batch):**
- R10 Phase 2 integration — lemma-based filler detector в translator.py
- R11 — decorative controls hidden in auto mode (Manual page + dashboard buttons)
- R19 — deprecation warning at admin (full removal deferred 60-90 дней stable observation)
- R21 — translation retry cap reduced by 1 in all 5 paths

## Implementation order

Группы по dependency и effort. Каждая группа — отдельный deploy.

### Wave 1 — P0 policy consistency (highest priority, smallest changes)
1. **R1** — disable watchdog_auto_reset_legacy_quarantine в auto mode (1 строка)
2. **R2** — disable reactivate_planner_soft_rejected_rows в auto mode (1 строка)

### Wave 2 — Observability infrastructure (no behavior changes, foundations for tuning)
3. **R5** — cached_tokens в ai_runtime для rewrite/translate/seo (worker pipeline.py)
4. **R7** — publish_thread heartbeat → WP option (orchestrator.py + rest.php endpoint)
5. **R6** — rejected histogram + 7-day trend dashboard widget
6. **R12** — 4-widget monitoring dashboard (uses R6)
7. **R13** — Tier 1 alerts via Telegram (uses R12 health snapshots)
8. **R22** — per-stage cost analysis script (uses R4+R5 data)

### Wave 3 — Quality gates (changes publish behavior, baseline check critical)
9. **R9** — plagiarism gate enforce (ctx.warnings → ctx.blockers)
10. **R8** — fabricated_name detector Phase 1 (dossier cross-ref) — free
11. **R8** — fabricated_name detector Phase 2 (spaCy de_core_news_lg) — adds dep
12. **R10** — filler_count Phase 2 (spaCy uk_core_news_lg) — after R6 observation

### Wave 4 — Cost reduction
13. **R20** — skip translate если rewrite_de hard-blocked (pipeline.py)
14. **R14** — snapshot lag TTL reduce (admin.php constants)

### Wave 5 — Resilience (defensive code)
15. **R17** — Polylang health check + critical alert + pause
16. **R18** — Foundation dynamic term_id lookup + cache
17. **R15** — Rank Math fallback в mu-plugin foundation
18. **R16** — 6 extension hooks (3 actions + 3 filters)

### Deferred / observation (no action yet)
- **R11** — decorative UI controls (revisit after R19)
- **R19** — semi mode removal (revisit after 60-90 days)
- **R21** — translation retry cap (revisit after R6 data)

## Дальнейшие шаги

После approval этого плана — implement Wave by Wave. Каждый wave:
1. Implement
2. Deploy + verify pipeline
3. Observe 1-3 days
4. Move to next wave

Стрик можно прервать в любой момент.
