# Admin UI + REST + CLI — карта (2026-05-14)

Working tree: `/root/projects/europulse/wp-plugins/europulse-autopilot-v21/includes/`. Все file:line ниже относятся к этой папке. Регистрация классов — `bootstrap.php:32-107` (PSR-like autoload).

## 0. Menu (admin top-level + submenu)

`admin/class-epv2-admin.php:86-100`. Top-cap = `manage_options`, sub-cap = `manage_europulse_autopilot` (`core/class-epv2-capabilities.php`). Pages:

| slug | callback (admin.php) |
|---|---|
| `epv2-dashboard` | `dashboard():102` — обзор, статистика, AI usage, schedule preview, ручные кнопки |
| `epv2-sources` | `sources():250` — RSS/HTML/Google News, импорт рекомендованных |
| `epv2-queue` | `queue():327` — основной экран queue + countdown |
| `epv2-settings` | `settings():2011` — все ключевые опции |
| `epv2-manual` | `manual():2064` — manual rewrite/translate UI |
| `epv2-review` | `review():1827` — per-item review form (`/admin.php?page=epv2-review&item=ID`) |
| `epv2-schedule` | `schedule_page():2931` — 7-window publish/collect editor |
| `epv2-telegram` | `telegram_page():2849` — bot token + chat_id + warn/info toggles |
| `epv2-logs` | `logs():2117` — последние 100 строк `ep_epv2_log` |
| `epv2-runs` | `runs():2127` — последние 100 строк `ep_epv2_runs` |

`EPV2_Review` страница отдельного файла не имеет — это submenu plus класс `review/class-epv2-review.php` (helpers).

## 1. Queue page блоки (`queue():327` → `queue_lightweight_blocks_html():756`)

Lightweight путь (default): отдельный SQL по блоку (`queue_light_rows_by_states()`). Heavy путь (`queue_full_blocks_html():686`) — один LIMIT-80 fetch + per-block filter (при ?orderby=).

Группировка через `EPV2_Queue::user_facing_state_for_row()` (`queue/class-epv2-queue.php:4466`) + `workflow_user_state_for_row():4392` (fast-path читает `_meta.stage_checklist` без normalize).

| Блок | Источник | Routing |
|---|---|---|
| Новые | state ∈ (new, reserve, retry_process) + ufs=new | `admin:571-573` ufs-first |
| В работе | state=processing_de OR row.id==active_id OR workflow_owner_token!='' | `admin:564-570`. active_id = option `epv2_active_automation_item` |
| Готово к публикации | ufs ∈ (ready_publish, publishing); приоритет над active_id (`admin:549-553`) | mutex: processing_de/retry_process исключены через `is_ready_publish_item():1728` |
| Готов к проверке | state=ready_review (worker собрал, ждёт apply) | `admin:843` |
| Ручная правка | state=manual_review | `admin:844` |
| Отклонённые | state ∈ (rejected, error, duplicate) | `admin:845` |
| Опубликованные | state=published | `admin:846` (heavy делит recent/archive в `<details>`) |

Critical filter functions: `is_active_work_item():1670`, `is_ready_publish_item():1728`, `is_new_queue_item():1684`, `is_manual_confirmation_item():1702`, `is_recoverable_queue_item():1741`. Базируются на `user_facing_state_for_row()`.

## 2. Row action buttons

Рендер в `render_light_queue_table():912-1024` (light) и `render_queue_table():1752-1810` (heavy):

- **Проверить** → `?page=epv2-review&item=ID` (no mutation)
- **Готово к публикации** (ready_review only) → `queue_to_publish():2237` → `EPV2_AI_Processor::transition_item_to_ready_publish()` → state=`ready_publish` если payload terminal-ready; иначе redirect `queue_notice=not_publish_ready`
- **Опубликовать** (`ready_publish|retry_publish|ready_review`) → `publish_now():2367` → `EPV2_Publisher::publish_item($item, 'publish')` → state=`published`
- **⚡ Опубликовать всё равно** (rejected/manual_review) → `force_publish():2294`. Ставит `_meta.manual_mode=true`, прогоняет `Publish_Gate::evaluate(force=true)`. Selection-only blockers (`:2329-2338`) bypass → state=`ready_publish`. Real blockers (media/translations) → state=`manual_review` с error_message
- **📝 Title / 📰 Body / 🖼 Media / 🔍 SEO** (manual_review) → `regen_stage():2759`. `EPV2_Worker_Client::process($row, $stage, $existing)`, bust'ит `_meta.stage_checklist|quality|seo_quality|release_quality|google_quality` (`:2803-2809`) — иначе fast-path читает stale. Сбрасывает workflow counters в `_system` + DELETE `ep_epv2_runs` → state=`new`
- **Редактировать пост** (published) → `wp-admin/post.php?action=edit&post=...`
- **Удалить новость** (published, heavy) → `delete_published_post():3187` → `wp_trash_post()` + `Queue::delete_items()`
- **Удалить** → `delete_queue_item():3176`

Hint под кнопками: `queue_action_hint():3894` — текст по ufs.

## 3. Bulk operations

Top-bar формы в `queue():399-432`:

- **Удалить выбранные** → `delete_queue_items():2658` (декодирует hidden `ids_csv`)
- **→ В публикацию** → `promote_manual_review():2682`. Каждый item: ставит `manual_mode=true`, прогоняет `EPV2_Publish_Gate::evaluate(force=true)`. selection-only blockers (`selection_reject|selection_low|enrichment_required|publish_not_due|context_reject|stale_context`) bypass'ятся → state=`ready_publish`. Real blockers → остаётся `manual_review`, добавляется blocked counter в notice
- **↻ Перегенерить** → `reprocess_manual_review():3066`. Drops `_meta.content_kind` + `_meta.story_card` (рекомпилируется при следующем worker call), сбрасывает `_system.workflow_*|retries|importance_*`, DELETE'ит `ep_epv2_runs` где `last_item_id=ID`, state=`new`
- **✕ Отклонить** → `reject_manual_review():3138`. mark_state=`rejected` + log

Per-block bulk delete: `render_queue_block_actions():738-755`.

CSV export: НЕТ. Все `ids_csv` — hidden form-bridge от JS checkbox selection.

## 4. JS countdown timers (`admin:4441-4530`)

- `#epv2-next-collect-countdown` (`admin:371`), `#epv2-next-publish-countdown` (`:711, :900`) — data-target=ms, data-interval=ms. JS tick 1сек.
- AJAX refresh: `wp_ajax_epv2_queue_snapshot` (`admin:73`) → `queue_snapshot():439`. Nonce `epv2_queue_snapshot`, cap `manage_europulse_autopilot`. Возвращает `{html, sections, next_collect, next_publish, collect_running, throttled}`.
- `QUEUE_SNAPSHOT_REFRESH_MS=5000` (`admin:17` — R14 2026-05-14, было 10000). `QUEUE_SNAPSHOT_CACHE_TTL=1s` transient (было 3). `QUEUE_SNAPSHOT_REQUEST_COOLDOWN=2s` guard (было 5). Worst case lag 8s (vs 13s до R14).
- JS lastQueueRefreshAt guard (`:4534`). AJAX использует `queue_lightweight_snapshot_payload():491` (per-block fetch) — НЕ heavy path (silently empty'ит блоки если new забивает LIMIT-80, `:461-468`).

## 5. Queue rendering perf

- **Fast-path в `workflow_user_state_for_row():4392-4464`**: items с state ∈ (new, retry_process, processing_*, ready_review) возвращают свой state БЕЗ payload-чтения (`:4424-4428`) — иначе ~50 строк × normalize_existing_payload = 5+ сек/row.
- Items с `_meta.stage_checklist` читают checklist directly (`:4443-4454`). Slow path только для legacy rows без checklist (`:4456-4460`).
- `queue_light_issue_list():1366` — blocker summary из payload без `error_message` field (suppressed в operator UI — много шума worker stack-traces).
- `EPV2_Settings::get_all()` static cache (`settings.php:160`) — без него AES-256-CBC decrypt 7 секретов × десятки вызовов.

## 6. REST endpoints (`api/class-epv2-rest.php`)

Namespace `epv2/v1`. Auth `can_bridge():112` — cap `manage_europulse_autopilot` OR `X-EPV2-Bridge-Token`/`Authorization: Bearer` matching `Settings::worker_shared_secret()` (constant-time hash_equals, `:130-146`). Каждый успешный token-auth логируется.

| Route | Method | Callback | Назначение |
|---|---|---|---|
| `/stats` | GET | `Stats::dashboard()` | counters |
| `/sources` | GET | `Sources::all` | list |
| `/queue` | GET | `Queue::get_queue_items_summary(50)` | preview |
| `/source-test` | POST | `Source_Tester::test` | dry-run |
| `/bridge/health` | GET | `bridge_health():149` | worker/queue/runs composite |
| `/bridge/state` | GET | `bridge_state():212` | orchestrator poll: paused flags, queue_states, current_window_mode, is_night_window, collect/publish_window_open, has_breaking_watch, breaking_watch_minutes |
| `/bridge/pause`, `/pause-collect`, `/resume`, `/resume-collect` | POST | `bridge_pause*/resume*():286-323` | toggle Jobs flags |
| `/bridge/collect` | POST | `bridge_collect():325` | `Collector::run_scheduled(true)`. HTTP 202 `http_long_job_disabled` если `server_orchestrator_enabled` (default) |
| `/bridge/process` | POST | `bridge_process():336` | `AI_Processor::process_scheduled`. То же 202-disable |
| `/bridge/publish` | POST | `bridge_publish():349` | `Publisher::publish_scheduled(true)` — short, НЕ disabled |
| `/bridge/maintenance` | POST | `bridge_maintenance():369` | см. секцию 9 |
| `/bridge/breaking_scan` | POST | `bridge_breaking_scan():265` | `Collector::run_breaking_scan()` — force=true, фильтрует breaking heuristic |
| `/bridge/server-orchestrator` | POST | `bridge_server_orchestrator():462` | toggle option |

Long-job 202: orchestrator при `server_orchestrator_enabled=true` запускает collect/process через **WP-CLI** (не HTTP) — избегает FastCGI timeout (`:357-367`).

## 7. Settings (`core/class-epv2-settings.php`)

Single option `epv2_settings` (`:8`). Defaults в `defaults():58-153`. Secrets (AI keys, image keys, worker_shared_secret) AES-256-CBC encrypted at-rest (`:379-403`), key derived from WP salts. Static cache invalidates на `set_all()` (`:194`).

Key settings (operator-visible в Settings page `settings():2011`):

| Key | Default | Прим. |
|---|---|---|
| `mode` | `semi` | manual/semi/auto |
| `collect_interval_minutes` | 30 | Time_Planner override'ит по window |
| `process_interval_minutes` | 5 | backstop, orchestrator не использует |
| `publish_interval_minutes` | 5 | контролирует `publish_not_before` buffer + next slot |
| `daily_publish_target` | 24 | если `enforce_daily_publish_target=true` (default) |
| `daily_category_publish_targets` | dict | politik/ukraine/deutschland=5, wirtschaft=4, sport=3, europa/welt/kultur=2, leben_in_deutschland/community=1 |
| `queue_retention_days` | 3 | `prune_stale` |
| `queue_new_ttl_hours` | 5 | `prune_new_stale` cutoff |
| `queue_new_max_per_category` | 8 | `trim_new_queue` per-cat |
| `queue_new_max_per_source` | 6 | per-source cap (MEMORY note: restored 8/6 после 50/50 backlog) |
| `queue_state_new_hard_cap` | 10 | global cap |
| `max_collect_per_category` | 4 | per-collect run |
| `trend_signal_enabled` | true | в defaults, UI не показывает |
| `auto_breaking_hours` | 6 | breaking watch window |
| `worker_mode` | `disabled` | UI имеет CLI option, реально server-orchestrator path |
| `worker_shared_secret` | autogen | `worker_shared_secret():318` lazy |
| `time_schedule_profile` | `Time_Planner::defaults()` | editor на `epv2-schedule` |
| `prompts.{auto_rewrite, analysis_rewrite, manual_rewrite, seo_refine}` | sealed text | textarea в Settings |

`automation_paused` / `collect_paused` — отдельные options (`EPV2_Jobs::automation_paused()`, `collect_paused()`), НЕ в `epv2_settings`. `log_retention_days` — нет setting; cleanup через daily prune (memory `backup_setup`).

## 8. CLI (`core/class-epv2-cli-commands.php`)

- `wp epv2 mr stats` (`:99`) — count + by-age + by-reason classifier (build_cap, publish_ready_cap, translation_no_progress, circuit_breaker, media_blocker, thin_dossier, manual_move, inline_short_circuit, chronic_recycler)
- `wp epv2 mr list` (`:152`) — filtered candidates
- `wp epv2 mr reject` (`:168`) — bulk reject
- `wp epv2 mr promote` (`:209`) — bulk → retry_process (cleans `_system.quarantine_reason|workflow_step_*|workflow_owner_token|manual_confirmation_required`)
- `wp epv2 maintenance run` (`:351`) — subset (1,3,4,9,10,19), не полный rest.php maintenance

Args: `--age=24h|3d|1w`, `--reason=sub`, `--editorial-match=match|borderline|reject_low_value`, `--category=slug`, `--source=N`, `--has-media=yes|no`, `--min/max-importance=N`, `--limit=N`, `--dry-run`. Importance: `EPV2_Importance_Score::compute()` per row.

Capability (`:69-80`): WP-CLI как root → bypass (shell trust). Иначе требует `manage_europulse_autopilot` (fix 2026-05-13 — без root-bypass CLI был сломан).

## 9. Maintenance hook chain (`bridge_maintenance():369-460`)

Порядок СТРОГИЙ (P1.7 reorder 2026-05-11). Возвращает `{ok, action, result:{...}}`. Шаги (`Queue::*` если не указано иное):

1. `abandoned_started_runs` — `Runs::cleanup_abandoned_started(120min)` — releases runs >2h в status=started
2. `promoted_live_published_rows(20)` — queue rows с уже-published post, но state не sync
3. `reactivated_media_rows(5)` — media-recoverable обратно
4. **`pruned_new_stale(ttl=5h)`** — old new items **(EARLY — перед reactivate)**
5. **`trimmed_new_queue(cat=8, src=6)`** — per-category/source caps
6. `reactivated_planner_soft_rejected_rows(50)` — planner-picked rejected'ы вытягиваются
7. `rejected_non_publish_grade_new_rows(150)`
8. `rejected_low_grade_ready_publish_rows(50)`
9. `workflow_quarantine(100)` — `quarantine_pathological_workflow_loops` (workflow_step_attempts threshold)
10. `force_rejected_chronic_recyclers(20, min_runs_24h=25)` — lifetime cap (item 2530 ranger note)
11. `sanitized_stale_ready_review(6h, 50)` — ready_review >6h → terminal
12. `cleared_orphaned_workflow_owners(30min, 50)` — `Watchdog::*` — orphan token на state=new (item 2203 38h)
13. `promoted_ready_like_rows(50)`
14. `auto_routed_new_rows(50)` — new с готовым payload → ready_publish, manual_confirmation → manual_review
15. `sanitized_stuck_ready_publish_rows(50)` — gate стабильно блокирует → manual_review
16. `sanitized_stuck_publishing_rows(20)` — state=publishing с post_id=NULL >8min (FastCGI kill) → ready_publish retry
17. `sanitized_stuck_processing_de_rows(20)` — >30min без active pointer
18. `auto_promoted_complete_manual_rows(30)` — qual=100 + 3 langs + media → retry_process
19. `trimmed_terminal_rows(keep=80)` — operator «всё >80 чисти»
20. `trimmed_rejected_aged(2h)` — 2h cleanup; diagnostic в `ep_epv2_log` 14d
21. `trimmed_review_queue_aged(2h)` — manual_review/ready_review (active manual_override защищены)
22. `watchdog_stuck_active(15min)` — `Watchdog::release_stuck_active_item` — clear pointer if terminal/missing
23. `watchdog_polylang(30)` — `Watchdog::repair_polylang_links`
24. `watchdog_dedupe(20)` — `Watchdog::dedupe_published_posts`
25. `watchdog_legacy_reset(20)` — `Watchdog::auto_reset_legacy_quarantine`. **R1 2026-05-14**: gated by `mode='auto'` — returns 0 в auto mode (политика "rejected stays rejected").
26. **`alerts_fired`** (added 2026-05-14, R13) — `EPV2_Alerts::check_and_alert()` — 4 Tier 1 triggers via Telegram: pipeline_stall, ai_budget, worker_health, orchestrator_heartbeat. 5-min dedup per trigger.

CLI `wp epv2 maintenance run` (`cli-commands.php:351`) — НЕ полный, только subset (1,3,4,9,10,19).

## 9.1 Dashboard widgets (R6, R7, R12 — added 2026-05-14)

`dashboard():102+` рендерит 4 monitoring widgets после schedule preview:
- `render_publish_heartbeat_notice()` (R7): notice если `epv2_publish_thread_heartbeat` age > 120s ИЛИ `thread_alive=false`
- `render_throughput_24h_widget()` (R12): ASCII bar chart (`▁▂▃▅▇`) hourly publishes за 24h
- `render_ai_cost_summary_widget()` (R12): today vs yesterday vs 7d avg с delta % (color-coded)
- `render_rejected_histogram_widget()` (R6): 13 reason buckets, 24h + 7d table, sorted by 7d desc

`EPV2_Stats::rejected_reason_histogram($hours)` + `throughput_24h_hourly()` + `ai_cost_summary()` — data helpers.

## 10. Time_Planner (`core/class-epv2-time-planner.php`)

`defaults():8-52` — 8-window schedule Europe/Berlin (06–09 morning_catchup, 09–16 daytime_active, 16–19 daytime_peak, 19–22 evening_prime, 22–23 wind_down_final, 23–00 wind_down_quiet (collect off, publish drain), 00–01 night_open (collect-only), 01–06 night_monitor (всё off, только breaking)). Publish везде кроме night = каждые 5 мин на :00/:05/:10/.... Collect = [0] (1/час) во всех "open" окнах. `breaking_watch_minutes=[0,30]` — orchestrator calls `/bridge/breaking_scan` ночью только на этих минутах.

- `current_window():59` — match `H:i` в range
- `should_collect():76` — force | has_breaking_watch | (in collect_minutes & not night)
- `should_process():99` — force | queue_has_states([new,retry_process,processing_de]) | minute_allowed
- `should_publish():117` — force | has_due_publish_item | (minute_allowed & budget_allows). В night_monitor: только ready_publish/publishing OR breaking
- `has_breaking_watch():227` — SQL: payload._meta.breaking|top_story|breaking_watch OR notes.selection.breaking_* за 6h
- `publish_budget_allows_item():140` — daily target + ramp curve hour-of-day
- `allowed_publish_budget_now():326` — share curve per mode: morning 0–15%, daytime_active 15–40%, daytime_peak/lunch 40–55%, daytime_mid 55–70%, evening_prime 70–92%, wind_down 92–98%, night 98–100%
- `next_publish_budget_slot_timestamp():183`, `next_category_budget_slot_timestamp():161` — поиск следующего slot когда budget откроется

`current_mode_label():208` — labels для текущих и legacy modes.

## 11. Budget Manager (`core/class-epv2-budget-manager.php`)

`analyze_item():8-299` — точка входа. Композирует score:

- Early reject gates: stale, hard_pattern, sport live fixture, telegram community promo, noise (без rescue signal), routine_official (looks_official + low impact)
- Positive weights: category, source priority, risk, freshness, urgency, practical, public_impact, community_value, editorial_interest, consensus, breaking_signal, media, trend
- Negative: routine_bureaucracy, trivial_local, soft_low_value, old_story_penalty
- `uplift_borderline_newsworthy_score():1137` — lifts edge до publish_c review threshold

`category_scorecard():492-525` — per-cat {a, b, c, publish_c, ai_delta, queue_delta, dimensions}. Default `a=72, b=54, c=34, publish_c=40`. Hot (politik/welt/ukraine/europa/deutschland/wirtschaft) баз delta. Soft с delta: leben-in-deutschland/community (-6/-6), muenchen/bayern (-4/-4), kultur (-2/-2), sport (-3/-3). `welt.publish_c=45` (выше). `wirtschaft.publish_c=40` baseline после revert 2026-05-14.

`apply_category_ai_threshold():527` — effective ai = base + ai_delta, reject = base + min(0, queue_delta). `apply_category_queue_threshold():537` — queue = base + queue_delta. Минимумы: ai≥20, reject≥8, queue≥18.

Dynamic delta: `EPV2_Category_Planner::selection_adjustment()` → delta (− недопредставлена, + перепредставлена). Применяется в `decision_for_score():1125-1135`: `publish_c_effective = max(c, min(b-1, publish_c + dynamic_delta))`.

`should_send_to_ai():389`, `should_keep_in_queue():425` — потребители. `budget_state():444` — для dashboard.

## 12. Fragile points

- **Admin UI cache vs реальное state**: TTL=3s, cooldown=5s, JS refresh=10s. Между cycle ticks heavy operations (regen_stage, bulk reprocess) могут возвращать stale snapshot до 10s.
- **`workflow_user_state_for_row` fast-path vs real state** (`queue.php:4424-4460`): items с state ∈ (new/retry_process/processing_*) НИКОГДА не возвращают ufs=ready_publish, даже если `_meta.stage_checklist.ready_publish=true`. Защита от stale checklist после `watchdog_legacy_reset`. `regen_stage` ОБЯЗАН bust'ить checklist (`admin:2803-2809`) — иначе orchestrator не подбирает item.
- **Multiple promote variants**: `queue_to_publish` (single, ready_review only, gentle через `transition_item_to_ready_publish`), `publish_now` (требует `payload_is_terminal_publish_ready`, strict), `force_publish` (single rejected/manual_review, bypass selection blockers, real-block routing), `promote_manual_review` (bulk = force_publish over ids_csv). Все через `Publish_Gate::evaluate()`, разный force/context. UI bulk-bar кнопка «→ В публикацию» = `promote_manual_review`.
- **Active pointer & workflow_owner_token могут расходиться**: pointer global option, token per-row `_system`. Между ticks pointer кратко = 0, token может держаться. Lightweight router (`admin:535-569`) проверяет ОБА.
- **`enforce_daily_publish_target=true` default** — публикации блокируются если over budget; курва зависит от hour-of-day (`allowed_publish_budget_at():379`).
- **REST `/bridge/process` и `/bridge/collect` → 202 при server_orchestrator_enabled** — orchestrator вызывает WP-CLI, не HTTP. Legacy HTTP-вызовы тихо не выполнятся.
- **`epv2_settings` cache invalidation**: static cache cleared только на `set_all()`. Direct `update_option(...)` минуя класс → старое value до конца request.
- **Settings page exposes subset defaults**: `trend_signal_enabled`, `automation_paused`, `time_schedule_profile` — отдельные UI/options.
