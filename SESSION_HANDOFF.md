# SESSION HANDOFF

## Current Canonical Runtime

- Project repo: `/root/projects/europulse`
- Live WordPress root: `/var/www/europulse/public`
- Live plugin: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`
- Repo plugin source: `/root/projects/europulse/wp-plugins/europulse-autopilot-v21`
- External worker/orchestrator: `/root/projects/europulse/worker-v21`

## Archive Boundary

- This file is the only short operational handoff for active `v21`.
- Historical `v2` and `v3` notes were moved to:
  - `/root/projects/europulse/docs/archive/SESSION_HANDOFF-legacy-2026-04-10.md`
- That archive is reference material only and must not be treated as current runtime configuration.

## Active Architecture

- WordPress is the queue, review, publish, admin, SEO and site-integration shell.
- Heavy processing belongs to the external worker/orchestrator layer.
- The REST bridge must stay lightweight and must not become a heavy repair loop.

## Current Cleanup State

- Removed from the active project tree on 2026-04-10:
  - legacy `worker/`
  - legacy `input/`
  - empty `screenshots/`
  - legacy `config/epv3-*`
- Cleaned from the active worker tree:
  - Python `__pycache__`
  - `*.pyc`
  - `*.pyo`
  - stale runtime watch log

## Immediate Handoff 2026-04-28

- Current work package: editorial quality and autopilot output quality.
- Production deploy completed for the editorial pass. Live backup: `backups/epv21-live-plugin-pre-editorial-20260428-173520.tar.gz`. DB backup before settings: `backups/epv21-db-pre-editorial-settings-20260428-173810.sql`.
- Deployed fixes include canonical category routing, substring-safe categorizer keywords, migration/context handling, stronger release/editorial checks, contextual captions/alt, real source block labels, AI final editorial guard, language-aware tag cleanup and tag canonical-dedupe.
- Live settings changed: `blog_public=1`, `source_block_enabled=true`. Do not switch AI primary to `gpt-5-mini` yet; preflight returned an empty AI response, so current primary remains `deepseek/deepseek-chat` with fallback `openai/gpt-4o-mini`.
- REST bridge long-job safety deployed: in server-orchestrator mode `/bridge/collect` and `/bridge/process` return `202` and do not run heavy jobs inside nginx. This prevents the observed HTTP 504 ghost run/lock class.
- Controlled collect proof: WP-CLI run `25134`, active sources `56/56`, collected `9`, errors `0`, queue items `1083-1091`.
- Autonomous proof reached acceptance: bridge health reported `consecutive_autonomous_publish_grade=12`, target `10`, status `accepted`. Recent accepted items include `1066, 1067, 1075, 1079, 1080, 1083, 1084, 1086, 1087, 1088, 1089, 1090`.
- Known live state at handoff: `automation_paused=false`, `collect_paused=true`, locks empty, queue contract `ok`, worker/orchestrator active, front `301`, `/wp-login.php=200`, latest publish run `25152`, queue `published=52`, `ready_publish=1`. Decide explicitly before unpausing regular collect.
- Already remediated published category defect: queue `1083` and posts `6558/6559/6560` moved from wrong `welt` family to `politik/polityka/politics`.
- Tags were re-cleaned for control posts `1083-1087`; sanitizer now drops generic/currency/noise tags, wrong-language tags and duplicate canonical tags.
- Separate requested review remains open: audit the plugin for WordPress standards, security, cron duplication, DB load, memory issues and fatal-error risks.
- Next editorial block is now source/media/balance: add a real media-text fit gate, audit published mismatches, audit and expand sources, and implement dynamic rubric balance instead of blunt daily caps.
- New urgent finding at handoff: rejected volume is high. In the last 48h window there were `85` created queue rows: `published=53`, `rejected=23`, `error=9`. Rejected split: `15` selection `reject`, `8` selection `low`, no duplicates. Main sources: `Google News DE Top Test=9`, `Deutschlandfunk Nachrichten=6`, `Google News Ukraine=2`.
- Rejected problem is likely systemic, not manual cleanup: average `story_score` is almost the same for `published` and `rejected` (`47.4` vs `47.0`), and some fresh `3-7h` items were marked with old/archive-style reason. Also inspect `EPV2_Budget_Manager` for remaining old slug usage (`world`, `münchen`) after canonical runtime moved to `welt`, `muenchen`.
- Extended history note: active `ep_epv2_queue` only keeps `85` rows from `2026-04-26 22:49:43` to `2026-04-28 17:48:31` because `queue_retention_days=3`; `ep_epv2_queue_backup_pre_20260401_new` is empty. For deeper analysis, use SQL backups only, without importing them into live DB.
- Extended SQL-snapshot audit found `354` unique queue rows from `2026-04-01 04:00:05` to `2026-04-28 05:48:49`: `published=280`, `rejected=51`, `ready_publish=9`, `error=9`, `duplicate=3`, `retry_process=1`, `new=1`. The score overlap persists across the longer period: `published avg=45.3`, `rejected avg=44.3`; main rejected buckets were `selection_reject=25`, `selection_low=14`; main rejected sources were `Google News DE Top Test=15`, `Deutschlandfunk Nachrichten=12`, `UNIAN=5`, `ARD Tagesschau=4`, `Google News Ukraine=3`.
- Selection audit fix deployed live on `2026-04-28`: added `EPV2_Selection_Audit`, new table `ep_epv2_selection_audit`, installer schema, collector stage/ingest audit writes for all candidate outcomes, and calibration script summary support. Table exists and is currently empty because collect remains paused; verify it after the next controlled collect.
- Add preliminary scoring audit to the next rejected-rate pass. Current `EPV2_Budget_Manager` tiering is global: `A>=70`, `B>=52`, `C>=34`, `D<34`; `decision_for_score()` maps `A=priority`, `B=strong`, `C>=40=review`, `C<40=low`, `D=reject`.
- Do not blindly change policy to “publish only A/B”. That is probably too strict for a news site: some valuable news is informative/public-interest rather than directly useful. Better model: `A/B` fast autopublish, strong `C` publishable after source/quality/media gates, `D` reject, with per-category scorecards and dynamic thresholds.

## Latest Handoff 2026-05-05 23:50 UTC

- Pulse tuning continues. Both pause flags ON. Queue empty. Live healthy: `/wp-login.php=302`, front=200, all services active, worker `/health=ok`, no held locks, no `epv2_collect/process/publish` cron events (server-orchestrator mode); only `epv2_weekly_analysis` scheduled for 2026-05-10 06:00 UTC.
- Plugin hardening pass 1 deployed (commit `cb1be09`). Schema migration applied live: `ep_epv2_queue.pipeline_stage` STORED generated column from `ai_payload._meta.pipeline_stage` + `idx_pipeline_stage` index; replaces three `LIKE %"pipeline_stage":"..."%` LONGTEXT scans in `EPV2_AI_Processor::repair_persisted_publish_finish_*` with indexed equality. `EPV2_Queue::guard_payload_field_sizes()` rejects `ai_payload`/`publish_payload` UPDATEs over 10 MB at the central `update_fields()` / `mark_state()` choke points (smoke-tested). `normalize_persisted_queue_contracts` and `queue_contract_regression_check` refactored to two-step (id list, then per-row LONGTEXT load) to bound peak memory. `EPV2_Google_News::http_get_body/http_post_body` log `curl_error()` via `EPV2_Logger::warning`.
- Pulse-mode operator tooling added: `scripts/epv2_pulse_status.php` (read-only digest with optional `--json`/`EPV2_PULSE_JSON=1`) and `scripts/epv2_pulse.sh` wrapper. Subcommands: `collect | process | publish | status | status-json | recent N | audit-summary`. Each action subcommand bypasses pause for one canonical handler call without touching the option flags. Use these instead of editing pause flags during tuning.
- Source audit summary: `ep_epv2_selection_audit` shows 15 feeds at 100 % `selection_reject` with `reject_class=stale` — they fetch successfully and have no `last_error`, but every item's `original_date` is outside the freshness window. Decision: do not deactivate. Calibrate per-source freshness windows (Phase 3) instead.
- Phase 3 (per-category scoring) found already shipped. `EPV2_Budget_Manager::category_scorecard()` carries A/B/C/publish_c per-rubric thresholds and per-rubric `dimensions` for politik/welt/ukraine/europa/deutschland/wirtschaft/leben-in-deutschland/community/muenchen/bayern/kultur/sport. Closed as done.
- Open follow-ups: Phase 2 pulse with media backlog closure (`1086`, `1044`, `1027`, `1047`), Phase 5 pass 2 (`JSON_THROW_ON_ERROR` rollout, `wp_remote_*` consistent try/catch, batched `get_post_meta` in dedup, REST `can_bridge()` review), Phase 6 SLO targets after Phase 2 produces clean pulses.

## Latest Handoff 2026-05-05 23:30 UTC

- Phase 0 routing fix verified live for row `1114`. `EPV2_Queue::workflow_v2_preview_selection(true)` returned `mode=claim_oldest_new`, `item_id=1114`, `state=new`, `workflow_step=build_de_master` — selector correctly claims staged `new` rows after the patch. One `EPV2_AI_Processor::process_scheduled(true, true)` tick advanced `updated_at` from `22:45:14` → `23:18:18` without falling into a `rebuild_bundle` quarantine loop. State stayed `new` because the source is too thin for autopublish; that is acceptable for fix proof.
- Queue cleanup: DB backup `backups/epv21-db-pre-cleanup-20260505-231738.sql` (65 MB), then `DELETE FROM ep_epv2_queue WHERE id BETWEEN 1109 AND 1118` removed all 10 stale rows; `ep_epv2_queue` is now empty. `ep_epv2_selection_audit` retained (2085 rows of analytics).
- Tuning limits temporarily lifted; original `epv2_settings` snapshot saved in WP option `epv2_settings_pre_tuning_snapshot_20260505` (autoload=false). Effective values now: `enforce_daily_publish_target=false`, `daily_publish_target=999`, `max_collect_per_category=99`, `queue_new_max_per_category=99`, `queue_new_max_per_source=99`, `max_queue_batch=5`, all `category_plans[*].min/target/max=0/0/99` (`upgrade_threshold` preserved as score gate), `daily_category_publish_targets[*]=99`. To restore: `update_option('epv2_settings', get_option('epv2_settings_pre_tuning_snapshot_20260505'))` then `delete_option('epv2_settings_pre_tuning_snapshot_20260505')`.
- Operating mode is **pulse tuning**: both pause flags stay ON. Operator runs manual `wp eval` triggers between idle periods. Do not unpause automation or collect without explicit user approval.
- Repo↔live plugin sync (Phase 1 hygiene). Files where live had newer real code, copied live → repo: `includes/analytics/class-epv2-weekly-analysis.php` (refined docblock + `wp_clear_scheduled_hook('epv2_weekly_analysis_thursday')`), `includes/core/class-epv2-plugin.php` (legacy textdomain comments dropped), `includes/ingest/class-epv2-html-reader.php` (added `pick_language()` lookup of `<html lang>` and `og:locale`/Content-Language meta), `includes/manual/class-epv2-manual-mode.php` (lang-aware import: only populate detected language block; carry media caption/credit), `includes/queue/class-epv2-deduplicator.php` (added 24h AI fingerprint window check against `_epv2_story_fingerprint` post meta). Files where live had spurious extra indentation only, copied repo → live: `includes/queue/class-epv2-queue.php`, `includes/ai/class-epv2-ai-processor.php`. Pre-sync backups in `backups/phase1-sync-20260505-2326/`. PHP lint passed for all touched files; `php8.3-fpm` reloaded; `/wp-login.php=302`, front=200, worker `/health=ok` after sync. Only remaining intentional repo↔live divergence is the `Plugin Name` header in `europulse-autopilot.php` (live: `EuroPulse AutoPilot v2.1 Sandbox`, repo: `EuroPulse AutoPilot v21`).
- Worker runs the repo source directly: systemd `WorkingDirectory=/root/projects/europulse/worker-v21/src`, no separate live snapshot. Editing `worker-v21/src/...` plus `systemctl restart epv2-worker` is the deploy step.
- Plugin live state at handoff: services nginx/php8.3-fpm/mariadb/epv2-worker/epv2-orchestrator all active; `epv2-worker` since `2026-05-05 22:36:09 UTC`; orchestrator continues to log `automation paused` every 16s; queue empty; AI primary `openai/gpt-5-mini`, fallback `deepseek/deepseek-chat`.
- Next session may proceed to Phase 2+ pulse pipeline work, source audit and plugin hardening.

## Latest Handoff 2026-05-05 22:55 UTC

- Current goal is still controlled stabilization, not unpausing automation. Keep `epv2_automation_paused=1` and `epv2_collect_paused=1` until the post-deploy routing proof finishes.
- Live status before deploy was safe: `/wp-login.php=200`; `epv2-worker` active; `epv2-orchestrator` active and logging `automation paused`; both pause options were `1`.
- Queue snapshot before deploy: `error=3`, `new=2`, `rejected=5`. Rows `1109-1118` still have no `post_id`; `1114` is the current routing test row.
- `1114` state before deploy: `new`, `category_final=welt`, `pipeline_stage=rebuild_bundle`, `workflow_step=build_de_master`, `workflow_step_status=pending`; saved payload has ready DE/UK/EN, source FAZ media, no blockers, but release/google warnings (`96/85`).
- DB backup before the controlled 1114 retick: `/tmp/europulse-before-1114-routing-retick-20260505-2248.sql`.
- Diagnostic finding: current saved `1114` predicates resolve correctly (`payload_next_required_stage=publish_finish`, `payload_next_stage_from_cached_checklist=publish_finish`), but the v2 selector can still hide staged `new` rows when `workflow_user_state_for_row()` infers `ready_publish` from payload while `workflow_step` or `pipeline_stage` remains set.
- Repo patch deployed live in `includes/queue/class-epv2-queue.php`: `bridge_next_processable_row()` now skips inferred `ready_publish` only when the row has no explicit `workflow_step` and no `pipeline_stage`. Staged `new` rows remain claimable/processable.
- Repo patch deployed live in `includes/ai/class-epv2-ai-processor.php`: after worker `rebuild_bundle`, blocker-free bundles with viable DE master and ready UK/EN translations are forced to `publish_finish`; this prevents a complete multilingual payload from reopening the same rebuild loop.
- Local and live lint passed for both patched PHP files. Live backups:
  - `backups/class-epv2-ai-processor.php.pre-selector-routing-fix-20260505-2252`
  - `backups/class-epv2-queue.php.pre-selector-routing-fix-20260505-2252`
- PHP-FPM was reloaded after deploy. `/wp-login.php` stayed `200`; both pause options rechecked as `1`.
- Important limitation: the post-deploy live WP/DB proof command was rejected by the environment usage limit after deploy. Do not bypass with indirect DB/WP execution in the same constrained session.
- Immediate next live proof when usage is available: run `EPV2_Queue::workflow_v2_preview_selection(true)` and confirm it returns `1114`, then run exactly one `EPV2_AI_Processor::process_scheduled(true, true)` tick. Expected outcome: `queued_publish_finish_stage`, `worker_rebuild_ready_publish`, or controlled gate/manual terminal state; not `queued_rebuild_bundle_stage`.
- If `1114` reaches `publish_finish` or `ready_publish`, inspect media/text/category before any publish lane test. Source image is FAZ energy/Iran/Asia and looks contextually plausible, but do not publish without final media/text review.

## Latest Handoff 2026-04-29 00:04 UTC

- Live safety state at handoff: `epv2_collect_paused=1`, `epv2_automation_paused=1`; this pause is intentional after detecting queue quality risks. Do not unpause collection or automation until rows `1092-1098` are recalibrated and checked.
- Site health checked after live deploys: `/wp-login.php=200`; earlier checks showed front WordPress `301`, nginx/php-fpm/worker/orchestrator active, and no observed `500/502`.
- Queue states after controlled collect: `published=53`, `rejected=23`, `error=9`, `new=7`. The seven `new` rows are `1092-1098` and have not yet been recalibrated in DB.
- Controlled collect run `25154` completed via WP-CLI with `processed_sources=56`, `collected_items=7`, `error_count=0`. Audit table now has real data from this run.
- Audit outcome summary from the controlled collect: `selection_reject=488 avg_score 6.2`, `duplicate_precheck=119`, `staged_candidate=35 avg_score 43.8`, `freshness_block=31 avg_score 44.2`, `not_publish_grade=8 avg_score 37.3`, `queued=7 avg_score 45.7`, `hard_editorial_block=4 avg_score 45.0`.
- New rows currently waiting: `1092 deutschland score 48`, `1093 sport score 42`, `1094 politik score 62`, `1095 welt score 47`, `1096 wirtschaft score 40`, `1097 bayern score 40`, `1098 ukraine score 41`.
- Fresh data exposed two concrete classification defects. `1093` was wrongly `sport` because `sport` matched inside `Transport-Kahn`; `1097` was wrongly `bayern` because football context (`FC Bayern`, `Paris Saint-Germain`, `Champions League`) was treated as Bavaria/local context.
- Deployed live fix for categorizer: sport keywords are word-boundary safe; football/sport context suppresses `bayern/muenchen` source/local bias; control categorizer test now returns `1093=deutschland`, `1097=sport`.
- Deployed live fix for freshness: `candidate_is_fresh_enough()` now uses source/context-aware dynamic windows instead of one blunt cutoff. Strong `A/B`, trusted-primary serious `C`, service/community, and time-sensitive items are treated differently.
- Files deployed in this package: `includes/classify/class-epv2-categorizer.php`, `includes/ingest/class-epv2-collector.php`, plus earlier selection-audit files. Live PHP lint passed and repo/live checksums matched for deployed files.
- Local operational script added: `scripts/epv2_recalibrate_new_items.php`. It was patched to merge WP-CLI `$argv` and `$args`, but has not yet been rerun after that patch. Earlier dry-run showed intended actions only and did not apply DB changes.
- Current session attempted the live WP-CLI dry-run, but escalation was rejected by the environment due approval/usage limit. No live DB changes were made by that attempt; queue rows `1092-1098` should still be treated as unrecalibrated.
- Immediate next command path: lint the recalibration script, copy it to `/tmp`, run dry-run, then apply only if output is sane. Expected apply result: update `1092-1098`, change `1093` category to `deutschland`, change `1097` category to `sport` and terminalize it as `rejected/low`, leave the other acceptable rows in `new`.
- Keep `epv2_automation_paused=1` until after verifying recalibrated `new` rows. Only then decide whether to resume automation for a controlled pass; regular collect should remain paused unless explicitly needed.

## Latest Handoff 2026-04-29 17:50 UTC

- Live health checked after all deploys/repairs: `/wp-login.php=200`, front page returns expected `301` to canonical IP, `epv2-worker` and `epv2-orchestrator` are active, no 500/502 observed.
- Current live pause/queue state: `epv2_collect_paused=1`, `epv2_automation_paused=0`; queue counts are `published=58`, `rejected=25`, `error=9`, with no `new`/`ready_publish` rows.
- Deployed the repo memory-safe admin patch to live: `wp-plugins/europulse-autopilot-v21/includes/admin/class-epv2-admin.php` checksum matches live, PHP lint passed. Backup: `backups/class-epv2-admin.php.pre-memory-safe-media-table-20260429-1717`.
- Media diagnostics backfilled for 40 recent published posts after DB backup `backups/epv21-db-pre-media-diagnostics-backfill-20260429-1719.sql`.
- Deployed additional live media rule fixes in `includes/media/class-epv2-media.php`: canonical category normalization for `*-de/*-uk/*-en`, stricter Pexels high-context block for public/politics/Bavaria-service/migration/legal/specific-sport/biotech stories, no generic stock/Wikimedia for specific sport stories, and Deutschlandfunk/bilder.deutschlandfunk.de trusted as editorial source media. Backup before media deploy: `backups/class-epv2-media.php.pre-stock-context-tightening-20260429-1728`.
- Published-media repair package ran after DB backup `backups/epv21-db-pre-published-media-repair-20260429-1738.sql`. Repaired all language versions for: `1090` -> attachment `6659`, posts `6611/6612/6613`; `1067` -> `6660`, posts `6523/6524/6525`; `1050` -> `6661`, posts `6486/6487/6488`; `1041` -> `6662`, posts `6431/6432/6433`; `1035` -> `6663`, posts `6396/6397/6398`; `1030` -> `6664`, posts `6361/6362/6363`.
- Final media audit of latest 40 published rows leaves 4 intentional backlog cases: `1086` Adidas/DFL blocked Pexels and no safe auto replacement; `1044` Ukraine open-for-business blocked Pexels and no safe auto replacement; `1027` border-control ruling has mismatched Deutschlandfunk source image and no safe replacement; `1047` teacher-pay/inflation has weak Pexels and needs a better editorial/source image.
- Queue cleanup completed live on user request: removed `25` `rejected` rows after DB backup `backups/epv21-db-pre-clear-rejected-20260429-1752.sql`; inspected `9` `error` rows, all were technical quarantine with `post_id=0`, then removed them after DB backup `backups/epv21-db-pre-clear-error-garbage-20260429-1753.sql`. Direct queue count after cleanup: `published=58` only.
- Important next step: do not resume collect yet. Continue with manual/source search for the 4 backlog media cases, then continue AGENTS plugin review on DB hot queries/generated JSON indexes/memory/fatal-risk paths and add richer durable media audit fields.

## Latest Handoff 2026-04-29 09:55 UTC

- Live state reached in this session before escalation limit: `epv2_collect_paused=1`, `epv2_automation_paused=0`; queue had `published=58`, `rejected=25`, `error=9`, with no `new` and no `ready_publish` rows after controlled batch.
- Rows `1092-1098` were recalibrated and processed: `1092`, `1094`, `1095`, `1096`, `1098` published; `1093` rejected after animal/oddity public-value penalty; `1097` rejected by sport live/fixture hard block.
- Publish timing bug was fixed live: ready items now get `publish_not_before = ready_at + publish_interval` and subsequent items chain by exact interval, not cron-aligned slot. Controlled proof: `1092` published at `08:55:50`, `1094` at `09:01:04`, `1095` at `09:06:15`, `1096` at `09:11:23`, `1098` at `09:16:34`.
- Live media fixes deployed: `class-epv2-media.php` and `class-epv2-publisher.php` now block high-context Pexels fallback, allow named-entity Wikimedia fallback, keep Wikimedia metadata for diagnostics/credit, and prevent publisher from accepting existing stock media directly without context gate.
- Specific bad media example fixed live: queue `1015`, posts `6290/6291/6292` (`Nächster ranghoher CDU-Politiker geht auf Distanz zu Merz’ Renten-Aussage`) replaced unsuitable Pexels thumbnail `5657` with Wikimedia attachment `6658`, origin `https://upload.wikimedia.org/wikipedia/commons/a/ab/20240417-Friedrich_Merz_1808.jpg`, credit `Michael Lucan / Wikimedia Commons`.
- Backups created in this session include: `backups/epv21-db-pre-recalibrate-20260429-083634.sql`, `backups/class-epv2-budget-manager.php.pre-sport-fixture-20260429-0839`, `backups/class-epv2-queue.php.pre-exact-publish-timer-20260429-0851`, `backups/class-epv2-ai-processor.php.pre-exact-publish-timer-20260429-0851`, `backups/class-epv2-media.php.pre-media-diagnostics-20260429-0920`, `backups/class-epv2-publisher.php.pre-media-diagnostics-20260429-0920`, `backups/class-epv2-media.php.pre-wikimedia-context-20260429-0938`, `backups/class-epv2-publisher.php.pre-media-context-20260429-0938`, `backups/epv21-db-pre-merz-media-repair-20260429-0941.sql`, `backups/class-epv2-admin.php.pre-media-table-security-20260429-0950`.
- Live admin security/UI patch was deployed once: `regen_block` requires `manage_europulse_autopilot`, queue snapshot AJAX checks a nonce, and the queue table has a `Медиа` column. After that, repo was further improved to avoid loading full `ai_payload` in the lightweight table; this memory-safe admin patch is in repo only and was NOT deployed because the environment rejected further escalated commands due usage limit.
- Plugin review partial results: REST routes use capability/bridge token checks; admin-post handlers reviewed have nonce + capability checks; WP-Cron currently has no duplicate `epv2_collect/process/publish` events in server-orchestrator mode; root crontab contains only an old Codex reminder, not wp-cron/orchestrator duplication.
- Quick editorial/media audit after the fix surfaced remaining candidates for manual/systemic review: `1090` Deutschland air rescue uses Pexels, `1086` sport Adidas/DFL uses Pexels, `1067` sport Tedesco uses Pexels; diagnostics backfill for recent posts was attempted but rejected by environment usage limit, so do not retry by workaround in the same constrained session.
- Immediate next safe live step in a new session: deploy repo `wp-plugins/europulse-autopilot-v21/includes/admin/class-epv2-admin.php` to live, lint/checksum, then backfill `_epv2_media_diagnostics` for recent posts if approvals allow.
- Continue editorial QA after deploy: audit recent published text/photo/caption/category/source fit, add regression fixtures for bad stock media, extend admin diagnostics fields, and continue AGENTS review for DB hot queries, memory-heavy views, generated JSON indexes, and fatal-risk paths around media/network calls.

## Latest Handoff 2026-04-29 18:25 UTC

- User asked to test text/translation quality with ChatGPT/GPT-5-mini and investigate bad Ukrainian title class like `близькосхідний стрічка`.
- Live AI settings were switched to primary `openai/gpt-5-mini`, fallback `deepseek/deepseek-chat`. Safe config check confirmed provider/model and keys present without printing secrets.
- PHP/OpenAI direct test passed before production: `EPV2_AI_Client::generate()` with `gpt-5-mini` returned sane Ukrainian title `Живі оновлення з Близького Сходу`; PHP preflight for reasoning models was patched/deployed earlier to use enough completion budget and returned `AI provider responded`.
- Controlled collect run `25165` produced rows `1099-1108` while `collect_paused=1` remained in place. `1099` was rejected by canonical publish gate (`selection decision "reject"`), `1100` reached `ready_publish`, then published automatically at `18:16:32 UTC` after its 5-minute timer, creating posts `6668/6669/6670`.
- Editorial check of `1100` (`Bundeskabinett beschließt Beschleunigung des Stromnetz-Ausbaus`) found DE/UK/EN title/excerpt/content acceptable and source media from `bundesregierung.de`; however this is not valid proof of GPT-5-mini quality because worker metadata showed no provider/model.
- Root cause found in `epv2-worker` status logs: Python worker calls to OpenAI failed with `AsyncCompletions.create() got an unexpected keyword argument 'max_completion_tokens'` because worker venv uses `openai==1.14.0`; worker then fell back to DeepSeek. This explains why production payloads had empty `_meta.provider/_meta.model`.
- Repo patch completed locally for worker GPT-5-mini compatibility and traceability:
- `worker-v21/src/epv2_worker/rewriter.py`, `translator.py`, `seo.py` now pass `max_completion_tokens` via `extra_body` for `gpt-5*`/`o*` models, which the installed SDK supports.
- `RewriteResult`, `TranslationResult`, `SEOResult`, `WorkerResponse`, and `pipeline.py` now carry provider/model runtime into `_meta.provider`, `_meta.model`, `_meta.ai_runtime`, and `_meta.fallback_provider_used`.
- `translator.py` now has Ukrainian anti-calque prompt rules and `_normalize_ukrainian_title()` guard for `Ticker/стрічка`, including the `Nahost-Ticker` class.
- Local verification done: `python -m compileall -q worker-v21/src/epv2_worker` passed; SDK signature check confirmed `extra_body=True`, `max_completion_tokens=False`; local Ukrainian title normalizer test passed.
- Backups before worker edits: `backups/rewriter.py.pre-gpt5-worker-fix-20260429-1820`, `backups/translator.py.pre-gpt5-worker-fix-20260429-1820`, `backups/seo.py.pre-gpt5-worker-fix-20260429-1820`, `backups/contracts.py.pre-gpt5-worker-fix-20260429-1820`, `backups/pipeline.py.pre-gpt5-worker-fix-20260429-1820`.
- Blocker: live `systemctl restart epv2-worker` was rejected by environment approval usage limit. Do not bypass. Until worker is restarted, live production still uses the old running Python process and will continue to fail OpenAI GPT-5-mini calls.
- Last known live process state before approvals blocked: run `25175` finished on `1101` using old worker; `1101` remained in `new`/`rebuild_bundle` loop class. Need recheck live state first in next session.
- Immediate next actions: restart `epv2-worker`, check worker/orchestrator/site health, process one fresh/new row, verify no OpenAI `unexpected keyword max_completion_tokens` logs, verify `_meta.provider=openai`, `_meta.model=gpt-5-mini`, and then analyze DE/UK/EN text quality from actual GPT-5-mini output.

## Latest Handoff 2026-05-03 23:31 UTC

- User flagged current Ukrainian quality: bureaucratic style, source/source-like starts repeated in title/lead/body, lead and body duplicating each other, and immediate reduction/addition of names/surnames. Current focus is prompt/output quality, not unpausing automation.
- Live safety state during this package: `epv2_automation_paused=1`, `epv2_collect_paused=1`. Keep both paused until controlled quality checks pass. Site was repeatedly checked and `/wp-login.php` stayed `200`; no `500/502` observed.
- DB backup before the current controlled GPT-5 collect/test package: `/tmp/europulse-before-gpt5-collect-20260503-2250.sql`.
- Fresh queue rows from this package are `1109-1118`; `1109` is the active regression item. It is an ultra-thin Deutschlandfunk item: title about `Miersch und Söder`, source text only one sentence plus image/HTML noise. It must not be used as successful autopublish proof.
- PHP live fix deployed in `includes/core/class-epv2-worker-client.php`: non-200 worker responses now stringify array/object `detail` safely, preventing WP-CLI/PHP fatal on worker `HTTP 422`.
- PHP live fix deployed in the same client: empty `existing_payload` now serializes as `{}` instead of `[]`, fixing the Pydantic `existing_payload dict_type` 422 contract error.
- PHP lint passed for repo and live `class-epv2-worker-client.php`; live site health stayed `200` after deployment.
- Worker repo patches completed:
- `worker-v21/src/epv2_worker/rewriter.py`: provider errors preserved; unsupported model-added first names are stripped if source only has surname; thin source prompt forbids invented first names/roles/motives/consequences; source length remains `brief`.
- `worker-v21/src/epv2_worker/pipeline.py`: source HTML/images are stripped before rewrite; Google News search/homepage URLs are filtered out of supporting URLs; DE/UK/EN incomplete packages are blockers; sources with fewer than `35` clean words get blocker `Primary source too thin for autopublish`.
- `worker-v21/src/epv2_worker/translator.py`: Ukrainian prompt/postprocess now blocks `Ticker -> стрічка`, moves repeated source formulas away from paragraph starts, replaces bureaucratic calques like `доопрацювання`, normalizes Krankenkassen terms, and strips generated first names plus unsupported Bavaria/CSU roles from translations when absent in the DE master.
- Local checks passed after final patches: `worker-v21/.venv/bin/python -m compileall -q worker-v21/src/epv2_worker`; helper tests confirm removal of `Маттіас Мірш`, `Маркус Зедер`, `прем’єр-міністр Баварії Зедер`, `Bavaria’s Söder`, and term normalization to `каси обов’язкового медичного страхування`.
- Controlled rebuild before the final role/term patch already showed the correct safety direction: `1109` returned `outcome=ready_review` with blocker `Primary source too thin for autopublish`, not `ready_publish`.
- Blocker at handoff: final `systemctl restart epv2-worker` was rejected by environment usage limit. Do not bypass this. The latest Python code is in repo but is not yet running in the live worker process.
- Immediate next-session sequence: check live health and pause state, restart `epv2-worker`, verify worker health, rerun controlled rebuild for `1109` without unpausing automation, and inspect DE/UK/EN output.
- Expected post-restart result for `1109`: `ready_review`, not `ready_publish`; no `доопрацювання`; no generated first names; no unsupported `прем’єр-міністр Баварії` role; no empty language packages; no image-caption facts; no repeated source-formula starts.
- After `1109`, audit publish gate/fast-ready paths. C/review or ultra-thin items must not move to `ready_publish` just because the payload exists; inspect `EPV2_AI_Processor`, `EPV2_Publish_Gate`, and selection payload propagation.
- Only after that controlled proof should rows `1110-1118` be processed in batches and reviewed for DE/UK/EN quality, media fit, categories, and source sufficiency. Do not unpause automation before this.

## Latest Handoff 2026-05-04 05:50 UTC

- Live status checked before continuation: `/wp-login.php=200`; `epv2-worker` active since `2026-05-04 05:40:33 UTC`; `epv2-orchestrator` active and repeatedly logging `automation paused`.
- Intended pauses are still active: `epv2_automation_paused=1`, `epv2_collect_paused=1`. Keep both paused until controlled text, media and publish-gate checks finish.
- High-level queue snapshot succeeded before the environment blocked further escalated live DB commands: `new=10`. Treat rows `1109-1118` as still pending controlled evaluation, not as production-ready proof.
- Worker restart after the final Python quality patches was completed and the running worker now uses the repo worker code.
- Controlled direct rebuild for row `1109` after restart returned safe behavior: `outcome=ready_review`, blocker `Primary source too thin for autopublish`. This row is ultra-thin and must not be used as a successful autopublish proof.
- Worker text-quality patches now in repo and active include deterministic ultra-thin rewriting, stronger source cleaning, `ai_runtime` tracing, generated-name/role stripping, Ukrainian style/term normalization, and GPT-5-mini/OpenAI compatibility via `extra_body`.
- Publish-gate safety was patched in repo and deployed live: `_meta.blockers` now blocks `EPV2_Publish_Gate`, `EPV2_AI_Processor::publish_ready_gate_passes()` and fast-ready payload acceptance. Live gate proof for a blocker payload returned `allowed=false` with `payload_blockers`.
- Controlled direct rebuild for row `1110` returned `outcome=ready_publish`, no warnings/blockers, all AI stages on `openai/gpt-5-mini`; DE/UK/EN output was acceptable for continuing controlled validation.
- Local verification after this state: `php -l` passed for `includes/publish/class-epv2-publish-gate.php` and `includes/ai/class-epv2-ai-processor.php`; `worker-v21/.venv/bin/python -m compileall -q worker-v21/src/epv2_worker` passed.
- Added helper `scripts/epv2_controlled_rebuild_quality_audit.php`: run it with WP-CLI to direct-rebuild selected ids and print outcome, blockers, `ai_runtime`, language previews and media/source fields without saving queue state or publishing.
- Environment limitation: a detailed live DB query was rejected due the escalated-command usage limit at about `2026-05-04 05:50 UTC`. Do not attempt workarounds for the same WP/DB outcome. Resume live direct rebuilds only when approvals/usage are available again.
- Next live command sequence when available: rerun controlled direct rebuild for `1109` after the final translator normalizer patch; then run `wp eval-file scripts/epv2_controlled_rebuild_quality_audit.php -- --ids=1111-1118` without saving/publishing; inspect `outcome`, blockers/warnings, `_meta.ai_runtime`, DE/UK/EN style, source sufficiency, category and media fit.
- Before unpausing anything, decide terminal handling for `1109`: keep manual `ready_review` or reject/hold as source-too-thin. It must not enter `ready_publish`.

## Operational Rules

1. Treat only `wp-plugins/europulse-autopilot-v21` and `worker-v21` as live automation code.
2. Do not reintroduce paths, scripts, or docs that point new work back to `worker/`, `input/`, or `epv3-*`.
3. Keep good editorial items out of ordinary `rejected` when failure is technical.
4. Preserve source-first media behavior, with external media as fallback only.
5. Keep repair-first logic in automation and avoid discard-first behavior.

## Read Order For A New Session

1. `/root/projects/europulse/docs/active-runtime-boundary-2026-04-10.md`
2. `/root/projects/europulse/docs/europulse-memory-brief.md`
3. `/root/projects/europulse/docs/epv21-canonical-execution-plan-2026-04-09.md`
4. `/root/projects/europulse/docs/epv21-execution-todo-2026-04-09.md`
5. `/root/projects/europulse/SESSION_HANDOFF.md`

## Next Session First Actions

1. Check live health first: nginx, php8.3-fpm, epv2-worker, epv2-orchestrator, `/wp-login.php`, authorized bridge health, queue contract, locks.
2. Confirm pause state: latest known intended state after the 2026-05-03 text-quality session is `epv2_collect_paused=1`, `epv2_automation_paused=1`. Keep both paused until `1109` and the publish gate are rechecked after worker restart.
3. Continue the media backlog from the 17:50 UTC handoff: find safe source/editorial replacements for `1086`, `1044`, `1027`, `1047`; do not use generic Pexels/Wikimedia to hide a mismatch.
4. Inspect recent published posts as editor: text/photo/caption/source/category/tags; use `_epv2_media_diagnostics` instead of manual guessing where possible.
5. If new posts are generated, verify the stricter media rules on fresh cases before resuming regular collect.
6. Extend the admin/audit table with durable quality fields: provider, origin host, source image present, source-vs-stock, fit pass, risk flags, entity/geography/topic tokens, category mismatch, source dossier support count, fallback reason/query.
7. Continue AGENTS plugin review fixes: DB hot queries, memory-heavy admin views, generated columns/indexes for JSON filters, cron duplication guard, fatal-risk paths around media/download/network calls.
8. Query `ep_epv2_selection_audit` by outcome/source/category/score and calibrate hard reject vs salvageable borderline candidates from real fresh data.
9. Continue rejected-rate and preliminary-scoring audit before source expansion: classify rejected by selection reason/source/category, inspect `A/B/C/D` thresholds, fix false-reject causes, add metrics, then decide which sources to throttle/disable.
10. Build per-category scoring model: politics/world/Ukraine emphasize public impact and source confidence; life/community emphasize practical value; culture/sport may pass on informative/editorial value without direct utility; Munich/Bavaria emphasize local relevance.
11. Audit sources: disable useless/dead/empty/interstitial sources, score usable sources, then expand sources for Munich, Bavaria, Germany, world, Ukraine, society, culture, broader sport, Ukrainian communities in Germany/Bavaria.
12. Design dynamic rubric balance: diversity/freshness/importance/source-confidence/underrepresented-rubric weighting, not a simple hard daily cap.

## Historical Context

- Open the archive handoff only when you need incident forensics, old migration history, or rationale for deprecated `v2` and `v3` decisions.
