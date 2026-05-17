# Queue + State Machine — карта (2026-05-14)

Карта читается **перед** любым изменением очереди. Все file-paths абсолютные.
Источник: `wp-plugins/europulse-autopilot-v21/includes/queue/`,
`ingest/`, `core/class-epv2-installer.php`, `api/class-epv2-rest.php`.

---

## 1. Таблица `ep_epv2_queue`

DDL — `includes/core/class-epv2-installer.php:71-120`. Колонки:

| Колонка | Тип | Назначение |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | item id |
| `source_id` | BIGINT NULL | FK на `ep_epv2_sources` |
| `cluster_id` | BIGINT NULL | FK на `ep_epv2_clusters` |
| `state` | VARCHAR(32) | state machine (см. §4) |
| `mode` | VARCHAR(16) | `semi` или `auto` |
| `story_format` | VARCHAR(32) | news/feature/analysis |
| `topic_label` | VARCHAR(255) | derived в collector'е |
| `story_score` | INT | ingest score (используется для priority) |
| `language_plan` | VARCHAR(64) | `de,uk,en` |
| `original_url`, `canonical_url` | TEXT | source URLs |
| `original_title`, `original_content`, `original_excerpt`, `original_date`, `original_author`, `source_image_url` | RSS-payload |
| `title_hash`, `content_hash`, `semantic_hash` | CHAR(64) | dedup hashes (see `EPV2_Deduplicator::hashes`) |
| `duplicate_of`, `duplicate_reason` | предыдущий item для drop'а |
| `category_proposed`, `category_final`, `tags_proposed`, `tags_final` | classification |
| `ai_payload` | LONGTEXT | главный JSON, см. §3 |
| `ai_provider`, `ai_model`, `ai_tokens`, `ai_cost` | AI-метрики |
| `publish_payload` | LONGTEXT | snapshot для publisher'а |
| `post_id` | BIGINT | WP post id (DE master) |
| `error_message` | LONGTEXT | UI-видимая ошибка |
| `admin_notes` | LONGTEXT | JSON, см. §2 |
| `created_at`, `updated_at` | DATETIME | |

Indexes (line 109-119): `state`, `(state,updated_at)`, `(state,created_at)`,
`source_id`, `cluster_id`, `story_format`, `topic_label(191)`,
`category_final`, `(category_final,state)`, `duplicate_of`.

Generated column: `pipeline_stage VARCHAR(64) STORED` mirrors
`ai_payload._meta.pipeline_stage` для дешевых WHERE.
`installer.php:247-280`.

**Payload size cap**: `MAX_PAYLOAD_BYTES = 10 MB` —
`class-epv2-queue.php:3147`. Размер-guard implemented inline в `update_fields`
body (~3147-3175) — отдельной функции `guard_payload_field_sizes` нет;
`LogicException` raised inline если `ai_payload`/`publish_payload` превысил cap.

---

## 2. JSON структура `admin_notes`

Top-level keys:

- **`selection`** — пишет collector (`collector.php:776`): `score, tier,
  decision (priority/strong/review/low/reject), scorecard, reasons[],
  breaking_candidate, top_story_candidate, breaking_watch`.
- **`ai_gate`** — `Budget_Manager::should_send_to_ai` snapshot.
- **`cluster`** — `Story_Clusters` snapshot.
- **`planner`** — `Category_Planner::decide_for_candidate` snapshot.
- **`event_key`** — string для dedup.
- **`_system`** — runtime/workflow state. Поля:
  - `workflow_step` ∈ {`build_de_master`, `publish_ready_gate`,
    `rebuild_bundle`, `translate_uk`, `translate_en`, `translate_finish`,
    `publish_finish`} (см. `workflow_stage_attempt_limit`:5232).
  - `workflow_step_status` ∈ {`claimed`, `running`, `terminal`,
    `stale_recovered`}.
  - `workflow_step_attempts` (limit=2/stage).
  - `workflow_owner_token` — claim guard (clear by
    `clear_orphaned_workflow_owners`).
  - `workflow_heartbeat_at`, `workflow_claimed_at`, `workflow_recovered_at`,
    `workflow_recovery_reason`, `workflow_last_error`, `workflow_not_before`.
  - `workflow_terminal_reason`, `quarantine_reason`, `last_stage_blocker`,
    `next_operator_action`.
  - `publish_not_before` (UTC ts), `ready_publish_at`,
    `publish_deferred_by_daily_limit` / `_by_category_limit`.
  - `retry_after` (Y-m-d H:i:s).
  - `live_status`, `live_status_code`.
  - `manual_confirmation_required` ∈ {`media`, `translation`},
    `manual_confirmation_reason`, `manual_override_at` (protects
    `trim_review_queue_older_than_hours`).
  - `auto_promote_count` (loop terminator; handled внутри
    `auto_promote_complete_manual_review_items` ~1100-1150),
    `importance_score`/`_threshold` (quarantine decision).
  - `_reset_note`, `_promoted_by_prune`, `admin_promote_at/_reason` — audit.

`mark_state` (queue.php:2338) — единственная функция, которая обновляет
`_system` + транслирует runtime в state. Прочие пути — `update_fields`
+ ручное JSON merge.

---

## 3. JSON структура `ai_payload`

Writer = worker `WorkerResponse.to_dict`
(`worker-v21/src/epv2_worker/contracts.py:81-109`).

Top-level: `languages: {de, uk, en}` каждый с `title, excerpt, card_lead,
content/body_html, seo_title, meta_description, slug, focus_keywords,
media_url`; `featured_media_url, media_candidates[], tags[], categories[],
source_dossier, event_context`; `quality, seo_quality, release_quality,
google_quality` (`{score,...}`).

**`_meta`** (AI processor + worker):
- `story_card` — single source of truth: entities_people/organizations,
  topics, editorial_match, publishable_estimate, breaking_candidate,
  top_story_candidate, `semantic_embedding.vector` (1536-dim).
- `stage_checklist: {ready_publish, translations_ready,
  publish_finish_ready}` — fast path в `workflow_user_state_for_row`
  (4453; fast-path block ~4505) и `auto_promote_complete_manual_review_items`
  (1110, inside fn at 1060).
- `ai_runtime[]` — `{stage, tokens, model}`; сумма → daily AI usage
  (queue.php:2376-2386).
- `tokens`, `pipeline_stage` (mirror'ится в generated column),
  `selection` (decision/score), `content_kind`, `media_contract` (pass/fail),
  `translations_deferred`, `warnings[]`, `blockers[]`.
- `dropped_siblings[]` — sibling-enrichment (collector:849, max 8 FIFO).
- `breaking`, `top_story`, `breaking_watch` — TTL escape
  (`row_is_top_story_or_breaking`, queue.php:3245).
- `importance_score`, `importance_threshold`.

---

## 4. State machine

States: `new`, `processing_de`, `processing_uk`, `processing_en`,
`retry_process`, `ready_review`, `ready_publish`, `retry_publish`,
`publishing`, `manual_review`, `published`, `rejected`, `duplicate`,
`error`, `reserve` (legacy).

Terminal: `published`, `rejected`, `duplicate`, `error`
(`workflow_is_terminal_state`, queue.php:4341).

Главные переходы (через `mark_state`, queue.php:2338):

- `new` → `processing_de` — orchestrator claims (`active_owner_claim`).
- `processing_de` → `ready_publish` — worker returns publishable bundle.
- `processing_de` → `retry_process` — soft failure / publish_gate fail.
- любой → `ready_publish` — `Publish_Gate.evaluate(...)`.
  Если `!allowed`: `selection_publishable=false` → `rejected`,
  иначе → `retry_process` + `retry_after = +30 min`
  (queue.php:2421-2451).
- `ready_publish` → `publishing` → `published` — publisher.
- `processing_de`/`retry_process` + `manual_confirmation_required` →
  `ready_review` (queue.php:2467-2472).
- `manual_review` → `retry_process` — `auto_promote_complete_manual_review_items`
  (queue.php:1060) если qual=100, rel/goo≥80, editorial_match ∈
  {match,borderline}, ≤2 prior promotes.
- `rejected`/`error` сначала проходят `soft_terminal_state_guard`
  (queue.php:3060) — может конвертнуть в `ready_review` если есть
  spasable signal (`story_score ≥ 30` или story_card publishable_estimate
  high/medium), кроме hard reasons (`HARD_TERMINAL_REASON_TOKENS`,
  queue.php:2960).
- `published`/`rejected`/`error`/`duplicate` — очищаются
  `workflow_step`/`owner_token`/`heartbeat`, ставится
  `workflow_terminal_reason` (queue.php:2533-2543).

`canonicalize_single_workflow_state` (queue.php:2816) collapses
`processing_de/uk/en` + `retry_process` в логический `new` для
orchestrator_v2 при определенных условиях.

---

## 5. Главные функции (queue.php)

| Функция:line | Описание |
|---|---|
| `add_item`:29 | insert + dedupe-precheck refresh |
| `get_item` / `get_item_summary`:16,21 | row fetch |
| `mark_state`:2338 | **central state writer**; busts `epv2_bridge_has_processable_v1`, accounts AI usage, runs `soft_terminal_state_guard` + `canonicalize_single_workflow_state` + `Publish_Gate.evaluate` для `ready_publish`, ставит `publish_not_before`/`live_status`, очищает workflow fields на terminal |
| `update_fields`:3110 | raw column update + payload-size guard (inline) |
| `bridge_runtime_snapshot`:4661 | `{active_id, has_processable, next_ready_publish, queue_states}` |
| `bridge_health_snapshot`:4675 | + queue_contract + acceptance + workflow_stage_circuit; memoized |
| `bridge_has_processable_items`:4738 | 30s transient `epv2_bridge_has_processable_v1` |
| `bridge_next_processable_row`:4957 | per-state SELECT с ORDER BY workflow_step/owner_token first (W3.1 fix 2026-05-12) |
| `has_processable_items`:740 | orchestrator_v2 + legacy fallback |
| `next_item_for_processing`:287 | selector чтобы claim |
| `next_item_for_publish`:1985 | publisher pickup (один canonical вариант — `_fast` companion не существует в live tree) |
| `workflow_user_state_for_row`:4453 | UI fast path читает stage_checklist без full normalize |
| `user_facing_state_for_row`:4527 | + `active`/`publishing` derive |
| `workflow_classification_for_row`:4547 | `{class: recoverable\|manual\|terminal, reason, manual_kind, user_state}` |
| `prune_new_stale`:3206 | TTL (default 5h). **Только `created_at`** (bug #18 fix 2026-05-12). TOP/breaking exempt. Если `Publish_Gate.allowed` → promote ready_publish |
| `trim_new_queue`:3338 | per-cat 8 + per-source 6; skips workflow_step/owner_token set |
| `trim_old_terminal_items`:1545 | keep last 80 per terminal state |
| `trim_rejected_older_than_hours`:1573 | 2h cleanup для rejected/error/duplicate |
| `trim_review_queue_older_than_hours`:1598 | 2h cleanup manual_review/ready_review; `manual_override_at` protect |
| `quarantine_pathological_workflow_loops`:1756 | targets `step_attempts ≥ 2`; short-circuit для AI selection=low/reject + attempts≥1 и hard-gate signals (thin_source, invented_quotes) при attempts≥2; rescue если gate `allowed` → ready_publish; иначе terminal с importance routing |
| `auto_promote_complete_manual_review_items`:1060 | qual=100, rel/goo≥80, 3 langs+media, editorial_match ∈ {match,borderline}, ≤2 prior promotes |
| `force_reject_chronic_recyclers`:1221 | ≥25 process runs/24h → permanent rejected |
| `sanitize_non_publish_grade_new_items`:916 | drop low/reject new rows |
| `auto_route_misclassified_new_items`:977 | stuck new с ready payload → ready_publish/manual_review |
| `sanitize_stale_ready_review_items`:1302 | 6h cleanup |
| `sanitize_stuck_ready_publish_items`:1364 | gate-stuck → manual_review |
| `sanitize_stuck_publishing_items`:1446 | >8min no post_id → ready_publish |
| `sanitize_stuck_processing_de_items`:1498 | >30 min no active_pointer → release |
| `promote_live_published_rows`:3916 | post exists → state=published |
| `reactivate_media_recoverable_rows`:3952 | media-fail recover |
| `normalize_ready_publish_schedule`:2164 / `reanchor_*_after_publish`:2301 | publish slot stagger |
| `active_owner_*`:2597-2685 | single-owner orchestrator (claim/heartbeat/release/recover_stale) |
| `recent_process_attempt_count`:5266 | counts `epv2_runs`; default window **2h** (audit mandate), не 24h |
| `stage_recent_attempts`:5258 | public wrapper для inline short-circuit в AI processor |
| `workflow_stage_attempt_limit`:5232 | =2 для всех текущих stages |
| `workflow_not_before_timestamp` / `_waiting_not_before`:5196,5227 | combined `workflow_not_before` + `retry_after` |
| `bridge_acceptance_snapshot`:4848 | last-12 published streak vs publish-grade (qual/seo/rel/goo ≥ 90); 180s transient |
| `cached_queue_contract_health`:4808 | `AI_Processor::queue_contract_regression_check`, 60s transient |
| `bridge_incident_counters`:4700 | `low_reject_published_24h`, `terminal_quarantine_rows`, `stale_retry_rows` |

---

## 6. Watchdog (`class-epv2-watchdog.php`)

Все public, все idempotent, все возвращают status-array.

| Method | Цель | Триггер |
|---|---|---|
| `release_stuck_active_item(stale_minutes=15)` (155) | освобождает `epv2_active_automation_item` если updated_at старше N min; CAS-проверка чтобы не trample fresh claim; если state terminal — просто delete pointer |
| `clear_orphaned_workflow_owners(stale=30, limit=50)` (246) | state='new' + admin_notes LIKE '%workflow_owner_token%' + updated_at > N min ago; clears `workflow_owner_token/heartbeat/step/step_attempts`. **Critical fix 2026-05-12 для item 2203 zombie**: orphaned owner блокировал селектор resume'ом каждый цикл |
| `repair_polylang_links(limit=30)` (295) | находит publish'ed bundles с broken Polylang chain (≥2 lang posts but Polylang disagrees); вызывает `pll_save_post_translations` |
| `dedupe_published_posts(limit=50)` (363) | пары published posts с same `_epv2_cluster_id` за 7d, HAVING COUNT>3, trash youngest per lang |
| `auto_reset_legacy_quarantine(limit=20)` (51) | rejected/manual_review старше 30 min с error/admin_notes matching `перепредставлена`, `build_de_master`, `publish_ready_gate`, `length_below_kind_minimum` — wipe payload story_card+content_kind, wipe `selection` + `_system` workflow counters, delete `epv2_runs` history, `mark_state('new', error_message='')`. **R1 2026-05-14: early return в auto mode** — items в rejected остаются rejected, no revival. До fix revive'ло ~58 items/day. |

### R16 extension hooks (added 2026-05-14)
`mark_state(id, state, extra)` — после wpdb->update (line 2566): если `$state === 'rejected'` → `do_action('epv2_after_reject', $id, $error_message, $state)`. Третьи стороны могут subscribe для archival / monitoring / external pipelines.

---

## 7. Maintenance cycle

`EPV2_Rest::bridge_maintenance` —
`includes/api/class-epv2-rest.php:369`. Вызывается orchestrator'ом
~5 min. Порядок (важен — P1.7 reorder 2026-05-11):

1. `EPV2_Runs::cleanup_abandoned_started(120)`
2. `promote_live_published_rows(20)`
3. `reactivate_media_recoverable_rows(5)`
4. **`prune_new_stale($queue_new_ttl_hours)`** (default 5h)
5. **`trim_new_queue($queue_new_max_per_category=8, $queue_new_max_per_source=6)`**
6. `reactivate_planner_selected_soft_rejected_items(50)`
7. `sanitize_non_publish_grade_new_items(150)`
8. `sanitize_low_grade_ready_publish_items(50)`
9. `quarantine_pathological_workflow_loops(100)`
10. `force_reject_chronic_recyclers(20, 25)`
11. `sanitize_stale_ready_review_items(6, 50)`
12. `EPV2_Watchdog::clear_orphaned_workflow_owners(30, 50)`
13. `promote_ready_like_rows(50)`
14. `auto_route_misclassified_new_items(50)`
15. `sanitize_stuck_ready_publish_items(50)`
16. `sanitize_stuck_publishing_items(20)`
17. `sanitize_stuck_processing_de_items(20)`
18. `auto_promote_complete_manual_review_items(30)`
19. `trim_old_terminal_items(80)`
20. `trim_rejected_older_than_hours(2)`
21. `trim_review_queue_older_than_hours(2)`
22. `EPV2_Watchdog::release_stuck_active_item(15)`
23. `EPV2_Watchdog::repair_polylang_links(30)`
24. `EPV2_Watchdog::dedupe_published_posts(20)`
25. `EPV2_Watchdog::auto_reset_legacy_quarantine(20)`

Response JSON собирается в `$cleanup` массиве и возвращается.

---

## 8. Кэши и transient'ы

| Хранилище | TTL | Кто busts |
|---|---|---|
| `EPV2_Queue::$bridge_health_cache` (per-request, queue.php:4673) | request | автоматически |
| `EPV2_Queue::$normalize_cache` (per-request hash, AI processor) | request | автоматически |
| transient `epv2_bridge_has_processable_v1` (queue.php:4749) | 30s | `mark_state` для `new/ready_publish/retry_process/ready_review` (queue.php:2348) |
| transient `epv2_bridge_queue_contract_health` (queue.php:4808) | 60s (`ok`) / 30s (`pending`) | TTL only |
| transient `epv2_bridge_acceptance_snapshot` (queue.php:4848) | 180s | TTL only (перф fix 30→180 2026-05-13) |
| `EPV2_Deduplicator::$cached_recent_embeddings` (per-request, dedup.php:1380) | request | автоматически |
| `EPV2_Trends::$history_cache` (per-request, trends.php:287) | request | `bust_history_cache` (line 300) |
| option `epv2_active_automation_item` | persistent | `clear_active_automation_item`, watchdog `release_stuck_active_item`, `active_owner_release` |
| option `epv2_collect_deferred_until`, `epv2_collect_backpressure_total_seconds`, `epv2_collect_backpressure_last` | persistent | collector escape-hatch |

---

## 9. Collector (`class-epv2-collector.php`)

- `run_scheduled(force=false)`:8 — entry, `force=true` bypasses backpressure.
- `should_defer_for_backpressure`:246:
  - **Hard cap**: `COUNT(state='new') ≥ queue_state_new_hard_cap` (default 10)
    → defer +10 min (`epv2_collect_deferred_until`).
  - **Capacity-based**: pending (`new+retry_process+processing_de`) >
    capacity (last-hour published, floor `60/publish_interval_minutes`).
    Threshold = 1.0× capacity. После 30 min cumulative defer — force run
    если pending в 1-3× threshold; >3× — defer.
- `collect_source`:183 — RSS / aggregator (GN `when:14d`) / scrape / social.
- `ingest_candidate`:550 chain:
  1. `Deduplicator::is_duplicate` → on dup, `register_sibling_source`.
  2. `Budget_Manager::analyze_item` → score+decision.
  3. **Story Card up-vote**: decision low/reject + editorial_match=match
     + pub_est=high → upgrade to review (~$0.001). collector:572-592.
  4. `candidate_has_hard_editorial_block`, `candidate_is_fresh_enough`.
  5. `effective_collect_per_category_limit` / `_queue_new_max_per_category`.
  6. `Budget_Manager::should_send_to_ai`, `should_keep_in_queue`.
  7. `Content_Filters::detect_meta_index_page` — hub/index page.
  8. `Category_Planner::decide_for_candidate`.
  9. `Story_Clusters::register_candidate` + `is_story_duplicate`.
  10. `Story_Card_Builder::build_from_array` (~$0.001) — saves Card в
      `_meta` для AI processor reuse.
  11. Pre-AI cuts: pub_est=low + не breaking + source_priority<9 → reject;
      borderline + pub_est ∈ {low,medium} + not breaking + priority<9 → reject.
  12. `Deduplicator::is_event_duplicate`.
  13. `add_item(state='new')`.
- **Sibling-enrichment** (`register_sibling_source`:815): пишет
  `_meta.dropped_siblings[]` (max 8 FIFO) на active queue target
  ИЛИ `_epv2_dropped_siblings` post_meta на published target.

---

## 10. Deduplicator (`class-epv2-deduplicator.php`)

Уровни (от дешёвого к дорогому, in-order):

1. **`is_duplicate(title, content, url)`** (line 17) — pre-AI.
   - `title_hash`/`content_hash` exact match against active states.
   - URL or hash match in terminal states (1d window, исключая `rejected`).
   - URL-exact + 4h rejected window (cost-saving short-circuit).
   - WP `posts.guid`, `postmeta._epv2_source_url`,
     `_epv2_semantic_hash`, `_epv2_title_hash`.
2. **`is_story_duplicate(item, cluster)`** (504) — event_key based; matches
   `_epv2_cluster_id` postmeta, `_epv2_event_key` postmeta, recent queue
   rows; `material_delta_exists` allow override.
3. **`is_event_duplicate(current_id, story_card, payload, url, title)`** (181):
   a. `find_url_or_title_duplicate` (slug-token + Jaccard).
   b. `find_semantic_duplicate` (cosine on `_meta.story_card.semantic_embedding.vector`,
      threshold **0.70**, last 24h, ≤200 candidates).
   c. `compute_event_signature` =
      canonical_entity (people→organizations→topics fallback) |
      `event_date_bucket`. Empty signature → skip.
   d. Walk all same-signature items in 24h, compute max key_facts Jaccard.
      Adaptive threshold: 2 members=0.70, 3=0.60, 4=0.55, 5=0.50, ≥6=0.45.
      Grace: first 2 в кластере свободны.

Hashes (line 8): `title_hash = SHA256(lowercase trim)`,
`content_hash = SHA256(normalized)`,
`semantic_hash = SHA256(keyword bag)`.

Semantic vector dim ≥ 100 (production = 1536 OpenAI ada).

---

## 11. Fragile points

Места с пересекающейся логикой — менять одно без понимания других опасно.

1. **TTL/cleanup overlap**: `prune_new_stale(5h)` drop'ает `new` by
   `created_at`; `trim_new_queue` cap per-cat/per-source;
   `trim_old_terminal_items(80)` per-state slice;
   `trim_rejected_older_than_hours(2)` / `trim_review_queue_older_than_hours(2)`
   DELETE by `updated_at`; `auto_reset_legacy_quarantine` (watchdog)
   revive'ит rejected → new. Maintenance order (§7) рулит этот conflict —
   P1.7 reorder 2026-05-11.

2. **Publish stagger**: `publish_not_before` (`_system`) пишется
   `next_publish_slot_for_queue` (2792) + `Jobs::next_publish_slot_after`
   (jobs.php:284) с `publish_interval_minutes` (default 5).
   `normalize_ready_publish_schedule` (2164) +
   `reanchor_ready_publish_schedule_after_publish` (2301) переписывают
   слоты для всех ready_publish rows. `Time_Planner::publish_budget_allows_item`
   / `publish_category_budget_allows_item` — daily/category cap defer.
   Trap: смена `publish_interval_minutes` без `normalize_ready_publish_schedule`
   оставляет старые слоты.

3. **Attempt caps** — 3 independent points:
   - inline short-circuit в `AI_Processor::process_item` (line 1361)
     через `Queue::stage_recent_attempts`, window 1h.
   - watchdog `quarantine_pathological_workflow_loops` (1756) каждые 5 min;
     читает `workflow_step_attempts` И `recent_process_attempt_count`
     (внутри window жёстко 2h: `default_window = 2 * HOUR_IN_SECONDS`,
     line 5279).
   - lifetime cap `force_reject_chronic_recyclers(≥25 runs/24h)` (1221).

4. **State-write paths**: `mark_state` — единственный правильный (cache
   bust, AI usage account, publish_gate guard, publish_not_before).
   `update_fields` — raw, не bust'ает cache. Direct `wpdb->update` в
   `clear_orphaned_workflow_owners` + `force_reject_chronic_recyclers`
   bypass'ает invalidation. Trap: state-change через `wpdb->update`
   ломает `epv2_bridge_has_processable_v1`.

5. **Active automation pointer**: option `epv2_active_automation_item` +
   `_system.workflow_owner_token` должны быть consistent.
   `bridge_active_owner_row` (4926) tolerates either signal.
   `release_stuck_active_item` чистит option only;
   `clear_orphaned_workflow_owners` чистит token only. Trap: clean один
   без другого = stale active claim ИЛИ ghost claim (item 2203 fix
   2026-05-12).

6. **Story Card primacy**: `_meta.story_card` строится 1× в collector'е,
   AI processor reuse'ит (line 112-114). Watchdog `auto_reset_legacy_quarantine`
   wipes story_card + content_kind для rebuild. Dedup (Variant D),
   publish_gate (editorial_match), category resolver, importance score —
   все читают story_card. Mutation после ingest требует полный
   `normalize_existing_payload`.

7. **Soft terminal guard** (3060, called from mark_state:2398): конвертит
   `rejected`/`error` → `ready_review` если `story_score ≥ 30` OR
   `publishable_estimate ∈ {high,medium}`. HARD reasons
   (`HARD_TERMINAL_REASON_TOKENS`, 2960) — `duplicate`,
   `stale_time_sensitive`, `hard_editorial_block`, `context_reject`,
   `sport_fixture` — bypass guard. Trap: новая reject reason требует
   решения по HARD list. **2026-05-14 auto-mode policy**: guard fully
   bypassed когда `automation_requires_publish_grade()=true` — см. INDEX.md
   "Auto mode dead code".

8. **Publish gate evaluation** (3 caller'а): `mark_state(ready_publish)`
   (2421), `prune_new_stale` publish-ready exception (3267),
   `quarantine_pathological_workflow_loops` rescue path (1814). Изменение
   criteria влияет на все три одновременно.
