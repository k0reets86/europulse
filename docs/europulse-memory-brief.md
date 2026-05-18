# Europulse Memory Brief

This file gives MemPalace a compact operational picture of the Europulse system.

## Latest Checkpoint 2026-05-18 20:05 UTC

- For a new LLM session, start with `/root/projects/europulse/LLM_START_HERE.md`.
- Current branch: `review/plugin-audit`.
- Active checkpoint: post-incident stabilization after `epv2-worker` reached the `1.0G` systemd memory ceiling while OpenAI quota was exhausted.
- Repair focus:
  - DeepSeek/provider-order is explicit end-to-end.
  - OpenAI is no longer silently appended when the configured provider order does not include it.
  - OpenAI embeddings are skipped unless OpenAI is selected.
  - Worker provider cooldown is shared by story_card/rewrite/translation/SEO/embeddings and exposed through `/health`.
  - High-frequency logs are throttled.
  - Healthcheck is pause-aware and writes `epv2_active_alerts`.
- Observed runtime:
  - automation paused and collect paused
  - worker/orchestrator intentionally inactive while paused
  - only active alert is `swap_high`
  - `apt/dpkg` is blocked on `msmtp/apparmor`; preseed `false` and finish `dpkg --configure -a` before more apt work.

## Core Paths

- Project repo: `/root/projects/europulse`
- Live WordPress root: `/var/www/europulse/public`
- Live plugin: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`
- Live foundation mu-plugin: `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation`
- Local plugin source mirror: `/root/projects/europulse/wp-plugins/europulse-autopilot-v21`
- External worker: `/root/projects/europulse/worker-v21`

## Canonical Runtime Boundary

- The only active automation codepaths are `wp-plugins/europulse-autopilot-v21` and `worker-v21`.
- Legacy `worker/`, `input/`, `screenshots/`, and `config/epv3-*` artifacts were removed on 2026-04-10.
- Historical `v2` and `v3` references may still exist in archival notes, but they are not runtime sources.
- Primary operational handoff: `/root/projects/europulse/SESSION_HANDOFF.md`
- Historical handoff archive: `/root/projects/europulse/docs/archive/SESSION_HANDOFF-legacy-2026-04-10.md`

## Current Architecture

- WordPress is the live publishing shell.
- Heavy automation should stay outside the WordPress request lifecycle where possible.
- The system uses:
  - a WordPress plugin for queue, publishing, admin, SEO and site integration
  - an external worker/orchestrator layer for heavier automation tasks
  - live site foundation code in mu-plugins for rendering, archive URLs, schema and SEO hooks

## Current Live Reality

- Live repo branch in use: `review/plugin-audit`
- Latest pushed repo commit: `11c69d3`
- Server orchestrator is enabled.
- Collection was re-enabled on 2026-04-26 for a fresh controlled collect.
- Current queue snapshot after 2026-04-26 cleanup/fresh collect:
  - old non-published queue rows deleted after backup: `2`
  - fresh collect run `24796` added `8` new rows
  - all `8` fresh autonomous items reached `published`
  - remaining fresh queue at verification time: `new = 0`, `published = 8` in the cleaned queue window
  - runtime snapshot: `has_processable_items = false`, active owner `0`
  - queue contract health: `ok`, `violations_count = 0`
  - acceptance streak: `8`
- Current services:
  - `epv2-orchestrator.service = active`
  - `epv2-worker.service = active`
  - `epv2-runtime-watch.timer = inactive`
- Current verified fresh autonomous cycle:
  - collect run `24796`
  - process/publish runs `24797/24798`, `24799/24800`, `24801/24802`, `24803/24804`, `24805/24806`, `24807/24808`, `24809/24810`, `24811/24812`
  - first queue item `1006`
  - first post IDs `6225`, `6226`, `6227`
- Current runtime note:
  - `epv2-orchestrator` was restarted on 2026-04-26 after adding explicit `process idle` logging.
  - Old idle-loop symptom from repeated `terminal_ready_payload_short_circuit` was stopped after restart and processability parity fix.
  - Bridge queue contract health now runs the existing regression checker and no longer reports stale `pending`.
  - A stale non-autopilot Playwright/Chrome process tree was observed consuming CPU/RAM and should be cleaned separately when safe.
  - Publish timer bug fixed on 2026-04-26: server-orchestrator bridge calls used `publish_scheduled(true)`, and that force flag leaked into queue item selection, bypassing `publish_not_before`. `publish_scheduled()` now always uses due-only item selection.
  - Control verification after fix: item `1015` stayed in `ready_publish` with `publish_not_before = 2026-04-26 23:27:00 UTC`; forced publish run `24818` returned `no_due_items`.
  - Admin queue order is now: `Новые`, `В работе`, `Готово к публикации`, `Отклонённые`, `Опубликованные материалы`.
  - User then reported missing/incorrect visible countdown near `Готово к публикации`. Required behavior: show a clear countdown from `05:00` to `00:00`; new arrivals in `ready_publish` must not reset, extend, or move the timer for the first already-waiting item.
  - Live collection and automation were paused at `2026-04-27 01:25 CEST`: `epv2_collect_paused=1`, `epv2_automation_paused=1`.
  - Paused queue snapshot: `published=9`, `ready_publish=2`, `new=3`. Items `1015` and `1016` both had `publish_not_before=1777246320`, which is wrong because ready-publish slots must be strictly sequential.
  - Partial patch already deployed before pause: `fast_transition_item_to_ready_publish()` calls `EPV2_Queue::normalize_ready_publish_schedule(false)`. Treat it as incomplete; review before unpausing.
  - Resume target requested by user: continue at `2026-04-27 05:35 Europe/Berlin` (`2026-04-27 03:35 UTC`). Codex cannot self-open a new chat; use `docs/epv21-autopilot-implementation-todo-2026-04-26.md` resume section as the exact restart point.
  - 2026-04-27 continuation completed key publish-lane fixes:
    - admin countdown uses the real first `ready_publish` slot and shows `00:00` when due;
    - normalization preserves the first already-waiting `publish_not_before`;
    - bridge `next_ready_publish` reads `_system.publish_not_before`;
    - worker `All AI providers failed` payloads now go to retry/backoff and release active owner.
  - Verified automatic publishes without manual process/publish: `1015` at `10:25:21`, `1016` at `10:30:25`, `1017` at `10:35:30`, `1018` at `10:40:47`, `1019` at `10:46:13` UTC.
  - Verified acceptance snapshot: `consecutive_autonomous_publish_grade=12`, `remaining_to_target=0`, `queue_contract.status=ok`.
  - Automatic collect resumed and ran by orchestrator: collect run `24837`, item `1020`; item hit AI provider failure and is now in backoff until `2026-04-27 11:40:26`, with `active_automation_item=0` and `has_processable_items=false`.
  - Report written: `docs/epv21-autopilot-report-2026-04-27.md`.
  - 2026-04-27 19:08-19:15 UTC incident analysis found a quality/publish guard defect over the last 12h:
    - published rows by selection decision: `reject=12`, `low=8`, `review=8`, `strong=1`;
    - at least `20` low/reject materials were published instead of stopped;
    - deleted/stuck item detected: `1020` existed in process run logs but no longer existed in queue;
    - repeated loop hot spots: `1036`/`1042` rebuild + AI-provider failures, `1022` publish-finish continuation, `1029` stale publish-ready gate.
  - Root cause fixed live:
    - `fast_transition_item_to_ready_publish()` no longer trusts cached terminal-ready payloads when selection/context/stale signals block publish;
    - `next_due_item_for_publish_fast()` now re-checks `item_is_publishable_read_only()` before returning a due ready row;
    - final ready/publish selection guard no longer lets planner-selected soft `low/reject` pass into publish;
    - bridge acceptance snapshot now includes `selection_publishable`, so bad publications no longer count as green autonomous passes.
  - Live backups from this incident:
    - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php.bak-20260427-1911`
    - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21/includes/queue/class-epv2-queue.php.bak-20260427-1911`
  - Verification after fix:
    - live PHP lint passed for AI processor and queue class;
    - site responded with WordPress `301`, no `500/502`;
    - `EPV2_Queue::next_due_item_for_publish_fast(false)` returned `none`;
    - health now reports `acceptance.status=not_proven` because latest bad publish `1050` has `selection_publishable=false`.
  - Incident report written: `docs/epv21-12h-quality-incident-2026-04-27.md`.
  - Remaining decision: whether to unpublish/review already published low/reject posts from the incident window; do not mass-unpublish without explicit user approval.
  - 2026-04-27 systemic stabilization review completed:
    - old broad 12-block plan is directionally correct but too wide for the current failure mode;
    - priority changed to transition determinism, terminal retry/quarantine, provider circuit breaker, early source-quality gate, publish-lane simplification, DB hot-path cleanup, and observability;
    - current stuck rows at review time: `1022` publish_finish attempts `21`, `1029` stale publish_ready_gate with `decision=low`, `1036` rebuild/provider attempts `22`, `1042` rebuild/provider attempts `16`;
    - safe local cleanup done: removed `.playwright-cli` logs and `worker-v21/__pycache__`;
    - new implementation plan written: `docs/epv21-systemic-stabilization-plan-2026-04-27.md`.
  - Next implementation should start with backups, then Block B/C from the new plan:
    - canonical publish gate extraction;
    - terminal retry/quarantine policy;
    - then provider circuit breaker.
  - 2026-04-27 systemic stabilization implementation pass:
    - backups created: `backups/epv21-live-plugin-pre-systemic-20260427-193120.tar.gz`, `backups/epv21-db-pre-systemic-20260427-193120.sql`;
    - implemented `EPV2_Publish_Gate` and wired it into ready transition, fast-ready path, queue `mark_state`, publish selector, and acceptance snapshot;
    - added terminal quarantine pass `EPV2_Queue::quarantine_pathological_workflow_loops()` and wired it into bridge maintenance/resilience cleanup;
    - quarantined current loops: `1022`, `1036`, `1042` to `error/workflow_quarantine`, `1029` to `rejected/selection_publish_blocked`;
    - added workflow-stage circuit breaker state `epv2_workflow_stage_circuit` and worker-stage refusal while a stage circuit is open;
    - added first hard pre-AI editorial filter in collector for US-local, biotech PR, generic product test, event/opening-hours, and garbled title classes;
    - added health incident counters and regression script `scripts/epv2_systemic_stabilization_check.php`;
    - controlled collect run `25052` after filters produced `0` new items, `0` errors;
    - regression check passed for `[1022,1029,1036,1042,1050]`: publish selector `none`, stale retry rows `0`.
    - follow-up fix: stopped maintenance ping-pong between `reactivate_planner_selected_soft_rejected_items()` and `sanitize_non_publish_grade_new_items()` by disabling soft rejected reactivation in auto publish-grade mode; manual check returned `reactivated=0`, `rejected_new=0`.
  - 2026-04-28 live chain/API follow-up:
    - AI API preflight passed through `EPV2_AI_Client::preflight(EPV2_Settings::get_ai_config())`: `ok=true`, `AI provider responded`;
    - controlled collect run `25126` processed `56/56` active sources, collected `2` items, `0` errors;
    - active sources were healthy; only known inactive/dead source remains `MVG Betriebsmeldungen` with `404`;
    - new rows from collect: `1081` and `1082`;
    - orchestrator automatically processed `1081`: run `25127` did `rebuild_bundle`, run `25128` did `publish_finish`, then canonical publish gate correctly moved it to `rejected` with `selection decision "reject"` instead of technical loop/quarantine;
    - orchestrator then picked `1082`; run `25129` finished `rebuild_bundle` with `error_count=0`, run `25130` finished `publish_finish` with `error_count=0`, then canonical publish gate correctly moved it to `rejected` with `selection decision "low"`;
    - root cause of the apparent stop was not API failure but a gate mismatch: `publish_ready_gate` used a weaker/different media contract than AI processor, so rows could loop or quarantine with misleading `payload_contract` blockers;
    - deployed fix: canonical publish gate now calls `EPV2_AI_Processor::payload_media_contract_passes()`, and payloads are augmented with source context from the queue row before gate evaluation;
    - deployed fix: `publish_finish_workflow_step()` now routes strict media failures to `finalize_media`, not false `publish_ready_gate`;
    - deployed fix: `EPV2_Time_Planner::next_publish_budget_slot_timestamp()` schedules ready rows at the next same-day time-sliced budget slot before falling back to daily reset;
    - restored only gate-safe technical quarantine rows: `1075` and `1079` to `ready_publish`;
    - did not restore `1073`, `1074`, `1078` because their media contract still blocks correctly;
    - current scheduled ready slots: `1075 = 2026-04-28 09:22:00 UTC`, `1079 = 2026-04-28 09:27:00 UTC`;
    - current health after follow-up: queue contract `ok`, `active_automation_item=0`, `has_processable_items=false`, `ready_publish=2`, acceptance streak currently `5/10`, site `/wp-login.php=200`, front page returns WordPress `301`, no observed `500/502`.
  - 2026-04-28 server cleanup pass:
    - disk pressure was low before cleanup: `/` around `37-38%` used;
    - removed safe scratch only: `/tmp/epv2*`, `/tmp/europulse*`, old `/tmp/playwright_*`, `/tmp/playwright-artifacts-*`, `/tmp/com.google.Chrome.*`;
    - removed stale local Playwright artifacts from `/root/projects/europulse/output/playwright`;
    - removed local worker source cache `/root/projects/europulse/worker-v21/src/epv2_worker/__pycache__`;
    - cleaned completed WordPress upgrade temp folders: `wp-content/upgrade/updraftplus-1.26.3-de_de` and `wp-content/upgrade-temp-backup/plugins`;
    - moved live plugin `.bak` files out of active plugin tree into `/root/projects/europulse/backups/live-plugin-file-baks-20260428-cleanup`;
    - did not delete uploads, database backups, repo backups, `.venv`, active plugins/themes, or media assets;
  - verification after cleanup: `europulse-autopilot-v21` active, PHP lint clean for live AI processor and queue class, nginx/php-fpm/worker/orchestrator active, `/` returns WordPress `301`, `/wp-login.php` returns `200`, queue contract `ok`.
  - 2026-04-28 editorial-quality implementation completed live:
    - analysis found current quality risks in published/news output: weak low/reject history from the previous gate incident, category drift from non-canonical slugs (`world`/`welt`, `münchen`/`muenchen`), substring false positives (`usa` inside `Zusammenhalt`), polluted tags, credit-only media captions, too-frequent stock-media fallback, disabled visible source block, and global `noindex,nofollow` caused by WordPress `blog_public=0`;
    - live backup before deploy: `backups/epv21-live-plugin-pre-editorial-20260428-173520.tar.gz`;
    - DB backup before settings: `backups/epv21-db-pre-editorial-settings-20260428-173810.sql`;
    - deployed to live: taxonomy/category canonicalization, substring-safe categorizer keywords, domestic-migration vs world-migration routing, stronger release/editorial checks, contextual media captions/alt, real source block labels, final AI editorial guard, and stronger language-aware tag sanitizer;
    - deployed REST bridge safety: `/bridge/collect` and `/bridge/process` return `202` in server-orchestrator mode and no longer run heavy jobs inside nginx, preventing HTTP 504 ghost collect/process runs and stale locks;
    - live settings changed: `blog_public=1` and `source_block_enabled=true`;
    - AI primary was not changed: `gpt-5-mini` preflight returned an empty AI response, so primary remains `deepseek/deepseek-chat` with fallback `openai/gpt-4o-mini`;
    - controlled collect proof via WP-CLI: run `25134`, active sources `56/56`, collected `9`, errors `0`, created queue items `1083-1091`;
    - autonomous proof reached bridge acceptance: `consecutive_autonomous_publish_grade=12`, target `10`, status `accepted`; recent accepted items include `1066,1067,1075,1079,1080,1083,1084,1086,1087,1088,1089,1090`;
    - `1085` was correctly rejected as `selection decision "low"` and was not published;
    - remediated published category defect: queue `1083` and posts `6558/6559/6560` were moved from wrong `welt` family to `politik/polityka/politics`;
    - source labels were improved so post source blocks no longer show generic `Originalquelle` when a known source label can be derived;
    - control posts `1083-1087` had tags re-cleaned; sanitizer now drops generic/currency/noise tags, wrong-language tags and duplicate canonical tags;
    - health at handoff: nginx/php8.3-fpm/worker/orchestrator active, `/wp-login.php=200`, front page WordPress `301`, authorized bridge health `ok`, locks empty, queue contract `ok`, `automation_paused=false`, `collect_paused=true`, latest publish run `25152`, queue `published=52`, ready queue had `1` row.
  - 2026-04-28 quick rejected-rate finding:
    - last 48h by `created_at`: `85` queue rows total, `published=53`, `rejected=23`, `error=9`;
    - rejected split from `error_message`: `15` selection `reject`, `8` selection `low`, `duplicate_reason=NULL` for all `23`;
    - rejected sources: `Google News DE Top Test=9`, `Deutschlandfunk Nachrichten=6`, `Google News Ukraine=2`, and one each from `ARD Tagesschau`, `Bundesregierung`, `Google News Sport DE`, `Google News World EN`, `StMI Bayern`, `UNIAN`;
    - score overlap is suspicious: average `story_score` was almost identical for `published` and `rejected` (`47.4` vs `47.0`), so selection gate may be overcutting borderline material instead of separating clearly weak items;
    - some fresh items age `3-7h` received “old/archive/no new value” style reasons, so freshness/old-story reasoning needs a false-positive audit;
    - likely code follow-up: `EPV2_Budget_Manager` still contains legacy slug usage like `world` and `münchen`; canonical runtime should use `welt` and `muenchen`, otherwise category weight, public impact, soft thresholds and mix logic can under-score items.
  - 2026-04-28 extended queue-history audit:
    - active live `ep_epv2_queue` currently has only `85` rows from `2026-04-26 22:49:43` to `2026-04-28 17:48:31`; this is expected with `queue_retention_days=3`;
    - live `ep_epv2_queue_backup_pre_20260401_new` exists but is empty, so deeper history must come from SQL backups, not live tables;
    - SQL snapshots were parsed read-only without importing into live DB and yielded `354` unique queue rows from `2026-04-01 04:00:05` to `2026-04-28 05:48:49`;
    - snapshot state totals: `published=280`, `rejected=51`, `ready_publish=9`, `error=9`, `duplicate=3`, `retry_process=1`, `new=1`;
    - longer-period score overlap remains: `published avg=45.3`, `rejected avg=44.3`; this confirms that the selector was not cleanly separating good from weak material;
    - rejected buckets in snapshots: `selection_reject=25`, `selection_low=14`, plus `12` legacy/other reasons;
    - rejected sources in snapshots: `Google News DE Top Test=15`, `Deutschlandfunk Nachrichten=12`, `UNIAN=5`, `ARD Tagesschau=4`, `Google News Ukraine=3`;
    - rejected categories in snapshots: `politik=11`, `ukraine=9`, `deutschland=9`, `wirtschaft=6`, `sport=5`, so the problem is not isolated to one rubric.
  - 2026-04-28 selection audit fix deployed live:
    - added `EPV2_Selection_Audit` in `includes/metrics/class-epv2-selection-audit.php`;
    - installer now creates `ep_epv2_selection_audit` with indexes for `queue_id`, `candidate_hash`, `source_id`, `category`, `decision`, `outcome`, `created_at`;
    - collector now writes audit rows for stage and ingest decisions including duplicate precheck, selection reject, hard editorial block, freshness block, category active-load cap, not publish grade, AI gate block, queue gate block, planner reject, staged candidate, story duplicate, queued and queue insert failed;
    - deployed to live and ran `EPV2_Installer::maybe_upgrade_schema()` via WP-CLI; table exists and currently has `0` rows because collect remains paused;
    - updated `scripts/epv2_selection_calibration_audit.php` so it accepts positional days (`-- 7`) and reports `selection_audit` summary; read-only WP-CLI check passed.
  - 2026-04-29 controlled collect + classifier/freshness follow-up:
    - live pause state is intentional: `epv2_collect_paused=1`, `epv2_automation_paused=1`; do not unpause until current `new` rows are recalibrated and checked;
    - controlled collect run `25154` processed `56` sources, collected `7` items, produced `0` errors and created queue rows `1092-1098`;
    - current queue state after this run: `published=53`, `rejected=23`, `error=9`, `new=7`;
    - current `new` rows before recalibration: `1092 deutschland score 48`, `1093 sport score 42`, `1094 politik score 62`, `1095 welt score 47`, `1096 wirtschaft score 40`, `1097 bayern score 40`, `1098 ukraine score 41`;
    - `ep_epv2_selection_audit` now has useful live data from this collect: `selection_reject=488`, `duplicate_precheck=119`, `staged_candidate=35`, `freshness_block=31`, `not_publish_grade=8`, `queued=7`, `hard_editorial_block=4`;
    - audit exposed two concrete classifier bugs: `transport` matched `sport` inside `Transport-Kahn`, and `FC Bayern/Paris Saint-Germain/Champions League` was routed to Bavaria (`bayern`) instead of sport;
    - deployed live categorizer fix: word-boundary sport matching, football/sport context disambiguation, source/local `bayern/muenchen` bias suppression for sport context; control test now returns `1093=deutschland`, `1097=sport`;
    - deployed live freshness fix: `candidate_is_fresh_enough()` now uses source/context-aware dynamic windows for strong `A/B`, trusted-primary serious `C`, service/community and time-sensitive items;
    - added `scripts/epv2_recalibrate_new_items.php` to recalibrate existing `new` rows after classifier/scoring fixes; the script was patched to merge WP-CLI `$argv` and `$args`, but the patched version has not yet been rerun/applied;
    - current session attempted the live WP-CLI dry-run, but environment escalation was rejected due approval/usage limit; no live DB changes were made by that attempt;
    - next immediate action is to lint and run this script dry-run/apply, then verify `1093` becomes `deutschland`, `1097` becomes `sport` and is rejected as low-grade football, and the other acceptable rows remain `new`.
  - 2026-04-29 media quality + plugin review follow-up:
    - later in the same day the queue cleanup was completed live: `1092/1094/1095/1096/1098` published, `1093/1097` rejected, final known queue state was `published=58`, `rejected=25`, `error=9`, with no `new` and no `ready_publish`;
    - exact 5-minute publish timing was fixed live: publish slots now chain from `ready_at + publish_interval`, not cron alignment; controlled proof published `1092`, `1094`, `1095`, `1096`, `1098` about five minutes apart;
    - systemic selection fixes deployed: sport live/fixture pages without standalone news result are hard-rejected, and soft animal/oddity stories without public/practical value receive a penalty that pushed `1093` to reject;
    - media resolver/publisher fixes deployed live: high-context politics/public materials cannot fall back to Pexels, named-entity Wikimedia fallback is allowed, Wikimedia metadata is stored for fit/credit diagnostics, and publisher no longer accepts existing stock-media URLs directly without context gate;
    - fixed concrete bad media example: queue `1015`, posts `6290/6291/6292` (`Nächster ranghoher CDU-Politiker geht auf Distanz zu Merz’ Renten-Aussage`) replaced unsuitable Pexels attachment `5657` with Wikimedia attachment `6658`, origin `20240417-Friedrich_Merz_1808.jpg`, credit `Michael Lucan / Wikimedia Commons`;
    - media diagnostics for future publications now include provider, origin URL/host, source vs stock flags, media intent, fit pass, stock allowed flags, risk flags and context tokens/phrases;
    - partial AGENTS review was performed: REST bridge has capability/secret gate, admin-post actions reviewed have nonce/capability checks, live WP-Cron has no duplicate `epv2_collect/process/publish` events in server-orchestrator mode, root crontab has no wp-cron/orchestrator duplication;
    - admin security/UI patch was deployed live once: `regen_block` requires `manage_europulse_autopilot`, queue snapshot AJAX checks a nonce, and the queue table gained a lightweight `Медиа` column;
    - important caveat: repo contains a follow-up memory-safe admin patch that removes full `ai_payload` from lightweight queue-table SQL and uses only JSON_EXTRACT media fields, but this last admin patch did not deploy because the environment rejected further escalated commands due usage limit;
    - attempted live backfill of `_epv2_media_diagnostics` for recent posts was also rejected by the environment usage limit; do not retry via workaround in the same constrained session;
    - quick media audit still needs continuation: Pexels candidates surfaced for editorial review include `1090` (Deutschland air rescue), `1086` (Adidas/DFL sport), `1067` (Tedesco sport). Add richer admin/audit fields for provider, origin host, source image present, source-vs-stock, fit pass, risk flags, entity/geography/topic tokens, category mismatch, source dossier support count, fallback reason/query.
  - 2026-04-29 17:50 UTC media/admin continuation:
    - live admin memory-safe patch was deployed successfully after backup `backups/class-epv2-admin.php.pre-memory-safe-media-table-20260429-1717`; repo/live checksum matched and `php -l` passed;
    - `_epv2_media_diagnostics` was backfilled for 40 recent published posts after DB backup `backups/epv21-db-pre-media-diagnostics-backfill-20260429-1719.sql`;
    - additional media fixes were deployed live in `class-epv2-media.php`: canonical category normalization removes language suffixes like `sport-de`; Pexels is blocked for high-context public/politics/Bavaria-service/migration/legal/specific-sport/biotech stories; generic stock/Wikimedia is blocked for specific sport stories; Deutschlandfunk image hosts are trusted as editorial source media;
    - published-media repair package ran after DB backup `backups/epv21-db-pre-published-media-repair-20260429-1738.sql` and fixed all translations for `1090`, `1067`, `1050`, `1041`, `1035`, `1030` with `fit=yes` source images and refreshed origin/credit/caption/diagnostics;
    - final latest-40 media backlog is intentionally not auto-hidden: `1086` Adidas/DFL has blocked Pexels and no safe auto replacement; `1044` Ukraine open-for-business has blocked Pexels and no safe auto replacement; `1027` border-control ruling has a mismatched Deutschlandfunk source image and no safe replacement; `1047` teacher-pay/inflation has weak Pexels and needs a better editorial/source image;
    - queue garbage cleanup then removed `25` `rejected` rows after backup `backups/epv21-db-pre-clear-rejected-20260429-1752.sql` and `9` technical `error` quarantine rows with `post_id=0` after backup `backups/epv21-db-pre-clear-error-garbage-20260429-1753.sql`;
    - live state after cleanup: `collect_paused=1`, `automation_paused=0`, queue `published=58` only, no `new`/`ready_publish`/`rejected`/`error`; worker/orchestrator active and `/wp-login.php=200`.
  - 2026-04-29 GPT-5-mini text-quality test:
    - live AI settings were switched to primary `openai/gpt-5-mini` and fallback `deepseek/deepseek-chat`; safe check confirmed keys are present without printing them;
    - PHP OpenAI direct test succeeded and produced a sane Ukrainian title for Middle East live updates; PHP AI client preflight was already patched for reasoning models and returned `AI provider responded`;
    - controlled collect run `25165` created rows `1099-1108`; `1100` automatically reached `ready_publish` and published at `18:16:32 UTC` after the 5-minute timer as posts `6668/6669/6670`;
    - editorial read of `1100` found DE/UK/EN copy acceptable and source-first media from Bundesregierung, but it was not valid GPT-5-mini proof because payload provider/model were empty;
    - root cause found: live `epv2-worker` uses `openai==1.14.0`, whose Chat Completions method does not accept named `max_completion_tokens`; worker logs showed `AsyncCompletions.create() got an unexpected keyword argument 'max_completion_tokens'`, so OpenAI failed and production fell back to DeepSeek;
    - repo worker patch prepared: `rewriter.py`, `translator.py`, `seo.py` use `extra_body` for GPT-5/o completion budgets; result contracts and `pipeline.py` persist `_meta.provider`, `_meta.model`, `_meta.ai_runtime`, and fallback provider; Ukrainian translation prompt/guard now blocks `Ticker -> стрічка` calques like `близькосхідний стрічка`;
    - local checks passed: worker `compileall`, SDK signature `extra_body=True`, title normalizer test; backups saved under `backups/*pre-gpt5-worker-fix-20260429-1820`;
    - live restart is still pending because `systemctl restart epv2-worker` escalation was rejected by environment approval usage limit. Next session must restart worker first, then verify actual GPT-5-mini production payload before judging quality.
  - 2026-04-28 preliminary scoring model concern:
    - current `EPV2_Budget_Manager` tiering is global: `A>=70`, `B>=52`, `C>=34`, `D<34`;
    - current decisions are global too: `A=priority`, `B=strong`, `C>=40=review`, `C<40=low`, `D=reject`;
    - next pass must audit whether this start-of-pipeline scoring really predicts editorial value; do not assume that direct usefulness is the only criterion because news also needs informative/public-interest value;
    - avoid a blunt “publish only A/B” policy until calibrated. Better likely policy: `A/B` fast autopublish, strong `C` publishable after source/quality/media gates, `D` reject, with per-category scorecards;
    - proposed per-category model: politics/world/Ukraine prioritize public impact, source confidence and timeliness; life-in-Germany/community prioritize practical reader value; culture/sport can pass through information/editorial interest without direct utility; Munich/Bavaria prioritize local relevance and audience fit;
    - balance should use dynamic per-category thresholds and diversity weighting instead of fixed daily caps, so strong events are not blocked just because a rubric is temporarily full.
  - Immediate next-session order after editorial deploy:
    - read this memory plus `TODO.md` and `SESSION_HANDOFF.md`;
    - first check live health and bridge health; confirm no ghost locks, no `500/502`, no low/reject publish, and current pause state (`collect_paused` should remain true unless deliberately changed);
    - first code deploy in the next session should be the repo-only admin memory fix for `class-epv2-admin.php`; lint/checksum/live health after deploy;
    - then backfill `_epv2_media_diagnostics` for recent posts if approvals allow, so media/text/category audit can use data instead of manual inspection;
    - continue media-text fit audit: inspect posts from the last 24-48h for text/photo/caption/source/category/tag mismatch and turn bad examples into regression fixtures; start with `1090`, `1086`, `1067` Pexels candidates;
    - known media mismatch class to fix: a Bavaria migration/policy story can receive a football stadium or `Bayern München` club image because entity matching confuses Bavaria (`Bayern`) with the football club;
    - implement source-first media relevance gates: entity, geography, topic, source dossier image priority, fallback rejection when semantic fit is weak;
    - before expanding sources, run rejected-rate and preliminary-scoring audit/fix: classify rejected by source/category/reason, inspect `A/B/C/D` calibration, patch false-reject scoring, add rejected-rate metrics to bridge/admin, then decide which sources should be throttled or disabled;
    - after next controlled collect, inspect `ep_epv2_selection_audit` by outcome/source/category/score and use it to calibrate hard reject vs salvageable borderline decisions;
    - audit source portfolio: disable sources that produce empty/useless/interstitial/dead content and expand sources for Munich, Bavaria, Germany, world, Ukraine, society, culture, broader sport, Ukrainian communities/associations in Germany and Bavaria;
    - design dynamic rubric balance based on event importance, diversity, freshness, source confidence and underrepresented rubrics, not a blunt daily cap.
  - 2026-05-03 GPT-5-mini/text-quality continuation:
    - current safety state for this package: `epv2_automation_paused=1`, `epv2_collect_paused=1`; keep both paused until controlled text-quality checks pass;
    - DB backup before this controlled package: `/tmp/europulse-before-gpt5-collect-20260503-2250.sql`;
    - fresh queue rows from the controlled collect are `1109-1118`; `1109` is the current regression item and should not be published automatically because its source is ultra-thin;
    - user-specific quality complaint to preserve: Ukrainian copy must not be bureaucratic; title, lead and body must not start almost identically; body must continue the lead, not retell it; source formulas should not mechanically start every paragraph; translations must not add first names/roles absent from the source;
    - live PHP fixes were deployed in `includes/core/class-epv2-worker-client.php`: worker error `detail` arrays are stringified safely instead of causing fatal errors, and empty `existing_payload` now serializes as `{}` instead of `[]`;
    - PHP lint passed for repo/live worker client and site stayed `/wp-login.php=200` after the live deploy;
    - worker repo fixes completed: `rewriter.py` strips unsupported generated first names and preserves provider errors; `pipeline.py` strips source HTML/image tags, filters Google News search/homepage support URLs, blocks incomplete language packages, and marks `<35` clean-word sources as `ready_review` with `Primary source too thin for autopublish`; `translator.py` blocks `Ticker -> стрічка`, moves source formulas away from paragraph starts, replaces `доопрацювання`/Krankenkassen calques, and strips generated first names plus unsupported Bavaria/CSU roles in translations;
    - local verification after final worker patches passed: `worker-v21/.venv/bin/python -m compileall -q worker-v21/src/epv2_worker`; helper tests strip `Маттіас Мірш`, `Маркус Зедер`, `прем’єр-міністр Баварії Зедер`, `Bavaria’s Söder`, and normalize `державних лікарняних кас` to `кас обов’язкового медичного страхування`;
    - controlled rebuild before the final role/term patch already proved the key safety behavior: `1109` returned `outcome=ready_review` with blocker `Primary source too thin for autopublish`;
    - final blocker: `systemctl restart epv2-worker` after the latest Python patches was rejected by environment usage limit, so the latest worker code is in repo but not yet running live; do not bypass this in the same constrained session;
    - next session must restart `epv2-worker`, verify health, then rerun controlled rebuild for `1109` without unpausing automation; expected output is `ready_review`, with no `доопрацювання`, no added first names, no unsupported `прем’єр-міністр Баварії`, no empty UK/EN, no image-caption facts, and no repeated source-formula starts;
    - after `1109`, audit the publish gate/fast-ready path so C/review or ultra-thin items cannot reach `ready_publish` just because a payload exists; inspect `EPV2_AI_Processor`, `EPV2_Publish_Gate`, fast transitions, and selection payload propagation;
    - only after gate and text checks pass should rows `1110-1118` be processed in controlled batches and reviewed for DE/UK/EN text quality, media fit, categories and source sufficiency.
  - 2026-05-04 GPT-5-mini/text-quality continuation:
    - live health was rechecked: `/wp-login.php=200`, `epv2-worker` active since `2026-05-04 05:40:33 UTC`, `epv2-orchestrator` active and logging `automation paused`;
    - intended safety state remains `epv2_automation_paused=1`, `epv2_collect_paused=1`; do not unpause until controlled quality/gate/media checks pass;
    - high-level queue snapshot before live DB commands were blocked showed `new=10`, consistent with rows `1109-1118` still awaiting controlled evaluation;
    - worker restart after the final Python patches completed; the running worker now uses the repo worker code;
    - controlled direct rebuild of `1109` after restart returned `outcome=ready_review` with blocker `Primary source too thin for autopublish`; this source is ultra-thin and must not become autopublish proof;
    - active worker quality fixes now include deterministic ultra-thin rewrite, source cleaning, OpenAI/GPT-5-mini runtime tracing, generated-name/role stripping, Ukrainian term/style normalization, and guardrails against ticker/source-formula/caption hallucination classes;
    - publish gate was patched and deployed live so `_meta.blockers` blocks canonical gate, `publish_ready_gate_passes()` and fast-ready payload acceptance; live proof returned `allowed=false` with `payload_blockers`;
    - controlled direct rebuild of `1110` returned `outcome=ready_publish`, no warnings/blockers, and `ai_runtime` on `openai/gpt-5-mini` for all stages; DE/UK/EN output was acceptable enough to continue controlled validation;
    - local verification after this state passed: `php -l` for repo `class-epv2-publish-gate.php` and `class-epv2-ai-processor.php`, plus worker `compileall`;
    - added `scripts/epv2_controlled_rebuild_quality_audit.php` for the next live pass: WP-CLI direct rebuild audit for selected queue ids, printing outcome, blockers, runtime, language previews and media/source fields without saving or publishing;
    - environment blocked the detailed live DB query at about `2026-05-04 05:50 UTC` because escalated-command usage limit was reached; do not bypass with indirect WP/DB commands;
    - next live step when approvals/usage are available: rerun controlled direct rebuild for `1109` after the final translator normalizer patch, then run `wp eval-file scripts/epv2_controlled_rebuild_quality_audit.php -- --ids=1111-1118` without saving/publishing or unpausing automation;
    - before any real DB-processing or unpause, terminalize policy for `1109`: manual `ready_review` or reject/hold as source-too-thin, never `ready_publish`.
  - 2026-05-05 routing/selector continuation:
    - resumed from `TODO.md`, `SESSION_HANDOFF.md`, and this memory brief with the live system kept paused;
    - live safety before deploy: `/wp-login.php=200`, `epv2-worker` active, `epv2-orchestrator` active and logging `automation paused`, `epv2_automation_paused=1`, `epv2_collect_paused=1`;
    - queue before deploy: `error=3`, `new=2`, `rejected=5`; rows `1109-1118` still had no posts; `1114` was the active routing test row;
    - backup before controlled 1114 retick: `/tmp/europulse-before-1114-routing-retick-20260505-2248.sql`;
    - `1114` saved payload diagnostics: stage `rebuild_bundle`, workflow `build_de_master`, no blockers, ready DE/UK/EN, FAZ source media, `quality=100`, `seo=100`, `release=96`, `google=85`;
    - root selector bug found: staged `new` rows could be hidden because `workflow_user_state_for_row()` inferred `ready_publish` from payload while explicit `workflow_step`/`pipeline_stage` remained set, so `has_processable_items()` returned false even though the row still needed workflow completion;
    - repo/live patch in `includes/queue/class-epv2-queue.php`: `bridge_next_processable_row()` now skips inferred ready-publish only when there is no explicit workflow step and no pipeline stage;
    - repo/live patch in `includes/ai/class-epv2-ai-processor.php`: added `worker_rebuild_payload_should_continue_to_publish_finish()` and a rebuild-branch guard so complete blocker-free multilingual bundles move to `publish_finish` instead of looping back to `rebuild_bundle`;
    - local and live `php -l` passed for `class-epv2-ai-processor.php` and `class-epv2-queue.php`; live backups are `backups/class-epv2-ai-processor.php.pre-selector-routing-fix-20260505-2252` and `backups/class-epv2-queue.php.pre-selector-routing-fix-20260505-2252`;
    - patched files were deployed to live and `php8.3-fpm` was reloaded; `/wp-login.php` stayed `200`; both pause options remained `1`;
    - post-deploy WP/DB proof was blocked by environment usage limit, so do not bypass it in the same constrained session;
    - next session must first run the post-deploy proof: `EPV2_Queue::workflow_v2_preview_selection(true)` should return `1114`, then exactly one `EPV2_AI_Processor::process_scheduled(true, true)` tick should return `queued_publish_finish_stage`, `worker_rebuild_ready_publish`, or controlled gate/manual terminal state, never `queued_rebuild_bundle_stage`;
    - keep collection and automation paused until this proof and a final media/text/category review for `1114` are complete.

## Hard Invariants

- Do not drift from the intended architecture:
  - heavy repair / processing belongs to worker-orchestrator side
  - WordPress should keep queue state, review UI, publishing, integration and SEO glue
  - REST bridge should stay lightweight
- Good editorial items must not be thrown into ordinary `rejected` because of technical failure.
- Source-first media is mandatory:
  - in normal operation, most media should come from the primary source article
  - external media is fallback only
- User prefers repair-first automation:
  - technical failure should lead to repair / retry / quarantine, not silent discard
- New sessions must read memory artifacts first instead of improvising from partial chat context.

## Important Live Concepts

- Queue states such as `new`, `ready_publish`, `published`, `retry_process`, `rejected`
- Publish lane behavior
- Translation gates for `DE`, `UK`, `EN`
- Source-first media logic
- SEO correctness for canonical, schema, archive pages and news sitemap
- Safe operation without breaking the live site

## Practical Priorities

1. Keep the live site stable.
2. Keep automation deterministic.
3. Prove fresh post-fix autonomous series once new quality candidates exist.
4. Keep good material out of ordinary reject states, but quarantine technical loops explicitly.
5. Fix routing, publish, translation, media and SEO defects without introducing regressions.
6. Prefer explicit invariants over heuristic state branching.
7. Do not treat old `low_reject_published_24h` as new failures until the 24h historical incident window expires.
8. Next engineering priority: DB/index cleanup for hot JSON workflow fields and operator UI for quarantine reset.

## Common Search Themes

- `ready_publish`
- `retry_process`
- `processing_de stuck`
- `publish lane`
- `translation gate`
- `source-first media`
- `canonical`
- `schema`
- `news sitemap`
- `worker`
- `orchestrator`
