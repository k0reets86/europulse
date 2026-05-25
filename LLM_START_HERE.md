# EuroPulse LLM Start Here

Last updated: 2026-05-25 19:40 UTC.

This file is the first entry point for any new LLM session on EuroPulse.
Read it before opening old handoffs, TODOs, or plugin maps.

## Read Order

1. `LLM_START_HERE.md` — current checkpoint and next actions.
2. `docs/NEXT_SESSION_RUNBOOK_2026_05_22.md` — exact next checks, commands, decision rules.
3. `SESSION_HANDOFF.md` — operational handoff, latest section at the top.
4. `TODO.md` — actionable task list, latest section at the top.
5. `docs/SYSTEMIC_AUDIT_PLAN_2026_05_12.md` — systemic quality/architecture plan.
6. `docs/epv21-autonomous-plugin-hardening-plan-2026-05-21.md` — concrete autonomous quality/fact/cache/source-trust implementation plan.
7. `docs/PLUGIN_MAP_INDEX.md` — map of the WordPress plugin.
8. `docs/PLUGIN_MAP_WORKER.md` and `docs/PLUGIN_MAP_QUEUE.md` — worker/queue internals.

Do not start from archived v2/v3 notes unless a current file explicitly points there.

## Current Repo State

- Repo: `/root/projects/europulse`
- Branch: `review/plugin-audit`
- Runtime WordPress root: `/var/www/europulse/public`
- Live plugin path: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`
- Repo plugin source: `wp-plugins/europulse-autopilot-v21`
- Worker source: `worker-v21`
- Domain state: no canonical public domain is attached yet. Current WordPress
  `home` / `siteurl` may point to `http://204.168.148.47`; do not treat
  `europulse.eu` or `europulse.today` responses as plugin stability blockers.
  `europulse.today` will be attached later by the operator after automation is
  stable.

The active work package is now the 2026-05-21 autonomous quality hardening rollout, after the post-incident stabilization pass. The goal is to make autonomous publishing safer by adding a measured quality contour around existing choke points, not by rewriting the pipeline. The branch is dirty with intentional safety edits; do not discard them.

## Current Mission For Any New LLM

Authoritative next-session runbook: `docs/NEXT_SESSION_RUNBOOK_2026_05_22.md`.

If a new LLM only has time for one operational file after this one, read that runbook. It contains exact read-only checks, expected healthy baselines, decision rules, and the forbidden actions.

We are building the autonomous quality/fact/source/cache hardening plan from `docs/epv21-autonomous-plugin-hardening-plan-2026-05-21.md` in phases.

Expected end result:

- autonomous publishing continues, but risky payloads are measured, then routed to review/blocking only after false positives are understood;
- bad source coverage, unsupported facts, category drift, language/media/SEO defects become visible in `ep_epv2_quality_audit` and the admin page `Качество`;
- later phases add worker fact audit, source trust, dedupe angle control, post-publish rendered audit, cache verification, and regression fixtures.

Current phase/result:

- Phase 1 and the non-blocking part of Phase 2 are deployed live.
- The system records `publish_gate_shadow` rows for generated payloads.
- The system now also records `post_publish_rendered` rows after publish.
- Publish behavior now has a narrow hard stop for source sufficiency / source-expansion hallucination risk. `quality_shadow` itself still does not directly affect `allowed`.
- Safe post-publish UK text repair is active for visible formatting/brand/placeholder/broken-URL defects.
- Worker translator prevention now preserves HTML tags and real URLs before Latin/Cyrillic hybrid repair, normalizes observed outlet/product names, and explicitly preserves Latin-script publisher/brand/product/organization names in Ukrainian copy (`Kyiv Post`, `Deutsche Welle`, `Tagesspiegel`, `OpenAI`, `Waymo`, `ProSieben`, etc.).
- The latest 2026-05-24 12:42-12:45 UTC pass fixed a fresh false `uk_sentence_glue` class caused by compact HTML block boundaries (`</p><h2>` / `</h2><p>`) and added worker-side `Tagesspiegel` preservation after shadow audit row `2266`. `EPV2_Quality_Gate` and the worker now insert separators between block tags. Backfilled posts `16926`, `16927`, `16928` after backup table `ep_epv2_post_repair_backup_20260524_html_block_spacing_quality`.
- Fresh post-restart proof: queue `6213` published with all rendered audits `pass 100`; queue `6220` shadow audit `pass 96`; queue `6214` terminal reject was expected thin-source/incomplete-payload behavior, not a UK brand/glue regression.
- 2026-05-24 18:43-18:46 UTC check: services/site healthy, automation/collect enabled, alerts empty. Since 12:47, `post_publish_rendered` has `28` item groups / `84` rows and all are `pass 100`. Shadow data still showed `Ukrainska Pravda` variants (`Украінска/Українска Правда`) and some old `Київ Пост` on rejected/thin-source payloads, so the worker/PHP safety maps were extended and `epv2-worker` was restarted at `18:45 UTC`. Live smoke repairs `Українска Правда` to `Українська правда` and `Київ Пост` to `Kyiv Post`.
- 2026-05-24 19:36 UTC anti-hallucination pass: a 44-item published sample across categories found severe source-expansion hallucinations when primary RSS/source text was short and supporting sources were URL-only. `EPV2_Publish_Gate` now blocks `thin_source_dossier` and `source_expansion_risk`; `_meta.top_story` no longer bypasses these source checks. Manual problem IDs `6352, 6332, 6354, 6092, 6325, 6296, 6230, 5694, 5589, 6300, 6244` now all block in gate evaluation. Proper-name maps were also extended for `EuroPulse`, `Wall Street`, `The New York Times`, `The Washington Post`, `Reuters`, `Bloomberg`, `BBC`, etc.; 17 visible old UK posts were repaired after JSON backup `/tmp/epv2_rendered_repair_backup_20260524_1939.json`.
- 2026-05-24 20:14 UTC source-bound prompt pass: generator prevention was strengthened so thin sources produce short source-bound briefs instead of long unsupported articles. `worker-v21/src/epv2_worker/rewriter.py` now forces `<120` source-word inputs into `news_brief`, caps tokens to `1024`, and injects a `SOURCE-BOUND BRIEF` guard that treats title/url-only supporting links as non-factual. `wp-plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php` now computes `source_scope` and replaced the dangerous "обязательно усили материал" instruction with a rule that URL-only/title-only supporting entries cannot justify new dates, names, numbers, quotes, causes, consequences, or context. Live AI processor backup: `/root/tmp/europulse-live-backups/20260524-source-bound-prompt/class-epv2-ai-processor.php`; repo/live diff clean; PHP lint clean; PHP-FPM reloaded; `epv2-worker` restarted at `20:12 UTC`. Validation: `test_rewriter_thin_source.py` plus translator regressions, `5` tests OK.
- 2026-05-25 07:00 UTC site visibility bug fixed: queue `6395` had published WP posts (`17315/17316/17317`) but homepage/latest hid it because `europulse_autopilot_home_pool()` read stale `selection.decision=low` from `queue.admin_notes`. `wp-mu-plugins/europulse-foundation/includes/core.php` now prefers final post meta `europulse_selection_decision/europulse_selection_score` and falls back to queue notes only when post meta is absent. Deployed live, PHP lint clean, PHP-FPM reloaded, WP object cache and Nginx fastcgi cache cleared. Proof: `europulse_home_zone_ids('latest', 8)` includes `17315|6395|Russland greift Charkiw und Sumy mit Drohnen an`.
- 2026-05-25 07:00 UTC title-only source guard deployed live: `EPV2_Publish_Gate` blocks no-real-support/title-only cases as `source_expansion_risk` when `primary_body_chars <120 && primary_total_chars <260 && de_content_chars >280`. Backup: `/root/tmp/europulse-live-backups/20260525-title-only-source-gate/class-epv2-publish-gate.php`; live PHP lint clean; PHP-FPM reloaded.
- 2026-05-25 07:00 UTC automation proof: `epv2-worker` and `epv2-orchestrator` active; queue `6436` autonomously moved from `ready_publish` to `published` at `2026-05-25 06:27:40 UTC`, creating posts `17375/17376/17377`. High reject volume is mostly `selection_low/reject`, `source_expansion_risk`, `thin_source_dossier`, and `auto_reject_review_policy`, not a service crash.
- 2026-05-25 07:00 UTC tooling/dashboard added: `tests/suites/publish_gate_test.php` regression suite covers title-only source risk and source-support profile behavior; `tests/run.sh` defaults to `wp --allow-root eval-file` because `/root` tests are not readable by `www-data`; `scripts/epv2_live_quality_audit.php` checks published samples for missing translations, broken Ukrainian source/brand names, Cyrillic in English, source-risk blockers, queue/post selection mismatches, and latest visibility hints. The audit supports `EPV2_AUDIT_SINCE` and now prefers full `ai_payload` over `publish_payload`. The queue dashboard now has a live `Сайт` column for published rows (`Latest`, `Slider`, `Архив`, `Скрыт`, `Нет поста`) with post decision/score tooltip; deployed live with backup `/root/tmp/europulse-live-backups/20260525-admin-site-visibility/class-epv2-admin.php`. Validation: `tests/run.sh publish_gate` passed `6` checks; worker thin-source/translator tests still `5` OK; WP-CLI dashboard render proof returned `HAS_SITE_COLUMN`, `HAS_LATEST_BADGE`, `HAS_6395_ROW`. Live audit of `80` published groups had no hard translation/language findings and `58` source-risk warnings; fresh audit since `2026-05-25 06:15:00 UTC` shows `6436` clean and `6332` still source-risk as expected known-bad old content.
- 2026-05-25 09:00 UTC follow-up hardening: `europulse_home_resolve_selection()` was added and deployed so homepage eligibility/pool consistently treat post meta as canonical and queue notes as fallback only; `tests/suites/home_pool_test.php` now covers stale queue notes and live fixture `6395`. Backup: `/root/tmp/europulse-live-backups/20260525-home-selection-resolver/core.php`; PHP lint clean; PHP-FPM reloaded; WP object cache flushed. `tests/run.sh home_pool` passed before a later redundant WP-CLI rerun was blocked by platform usage-limit.
- 2026-05-25 09:00 UTC reject false-positive fix: recent rejected rows showed stale payload selection overriding canonical queue selection (`6462` payload `reject:30` vs notes `review:46`, `6465` payload `low:41` vs notes `review:40`, `6454` payload `low:40` vs notes `review:49`). `EPV2_Publish_Gate::selection_decision()` now prefers `admin_notes.selection.decision` and falls back to payload only when notes are absent. Backup: `/root/tmp/europulse-live-backups/20260525-publish-gate-selection-precedence/class-epv2-publish-gate.php`; PHP lint clean; PHP-FPM reloaded; `tests/run.sh publish_gate` passed `7` checks. Replay now removes false `selection_low/reject` blockers on those review items; remaining blockers are source/length/payload-related.
- 2026-05-25 09:00 UTC current live state: fresh audit since `2026-05-25 06:15:00 UTC` checked `4` published rows, hard findings none, only old `6332` source-risk warning. Large `120`-row audit had hard findings none and source-risk warnings mostly from old published payloads. Queue count read-only check showed `published=203`, `rejected=18`. Public IP `http://204.168.148.47/` returns `200 OK`; unauthenticated bridge REST returns expected `401`; worker/orchestrator are active and idle. Orchestrator logs still show `alerts_fired: ["ai_budget"]`, so inspect budget state next.
- 2026-05-25 09:00 UTC audit script safety fix: `scripts/epv2_live_quality_audit.php` now evaluates publish gate with context `audit_replay`, not `ready_publish`, to avoid creating shadow audit rows during diagnostics. It also reports `queue_state_counts`, `budget_state`, `recent_rejects`, and `reject_warn_counts` for stale payload selection, stage attempt limits, and worker blockers. PHP lint passed; live rerun was blocked by WP-CLI usage-limit.
- 2026-05-25 19:40 UTC translation/source audit pass: 80-row live audit had no hard findings, and a fresh audit since `2026-05-25 19:00:00 UTC` checked `5` published rows with no hard/warn/info findings. Manual source check of queue `6632` found facts aligned with the Ukrainska Pravda original but exposed a UK grammar defect (`своє дипломатичне персонал`) in post `17658`. Deployed a narrow worker/PHP repair for this `персонал` agreement class, fixed a case-insensitive false positive where canonical `Українська правда` was flagged as a bad brand, backed up live PHP and post `17658`, repaired the post through `EPV2_Post_Audit`, and verified final post-audit `pass 100`. Tests passed: worker translator unittest (`5`), PHP lint, `tests/run.sh quality_gate`, `publish_gate`, and `home_pool`. `ai_budget` remains in hard stop and should be handled separately.

What to do next:

1. Run the service/queue/quality checks in `docs/NEXT_SESSION_RUNBOOK_2026_05_22.md`.
2. Inspect the `ai_budget` alert/settings/state before assuming automation is stuck; latest orchestrator logs were idle with no processable items.
3. Run `tests/run.sh publish_gate`, `tests/run.sh home_pool`, and `EPV2_AUDIT_LIMIT=80 EPV2_AUDIT_REJECT_LIMIT=30 EPV2_AUDIT_SINCE='YYYY-MM-DD HH:MM:SS' wp --allow-root eval-file scripts/epv2_live_quality_audit.php --path=/var/www/europulse/public` after the next autonomous publish cycle, when WP-CLI approvals/usage are available again.
4. Calibrate false positives for `thin_source_dossier`, `source_expansion_risk`, and title-only source blocks on new items only; do not use old published payload replay as a fresh-pipeline failure signal.
5. Watch whether stale payload selection false rejects disappear on fresh rows after `2026-05-25 09:00 UTC`.
6. Add deeper dashboard visibility only if needed: category-section visibility, sitemap/noindex, and hidden reason beyond the current `Сайт` column.
7. Watch whether UK rendered repairs recur on new posts after the 2026-05-24 19:34 UTC worker restart.
8. If UK repairs still recur on more than roughly 5 percent of new UK posts, continue upstream worker translator/prompt prevention first.
9. Only then implement Phase 3: review-mode routing for confirmed hard blockers.

What not to do yet:

- Do not broaden hard blocking beyond the deployed source-sufficiency/source-expansion guards until fresh false positives are reviewed.
- Do not start worker `/quality_audit` yet.
- Do not implement source trust, clustering/dedupe rewrite, post-publish rendered audit, or cache manager before reviewing shadow data.
- Do not manually trigger `collect`, `process`, or `publish` unless the user explicitly changes that rule.
- Do not try to route around platform usage-limit rejections for escalated WP-CLI commands. Wait for approvals/usage to reset or use materially different safe checks.

Useful shadow-data checks:

```bash
sudo -u www-data wp db query "SELECT verdict, COUNT(*) c, ROUND(AVG(score),1) avg_score FROM ep_epv2_quality_audit GROUP BY verdict; SELECT blockers, COUNT(*) c FROM ep_epv2_quality_audit GROUP BY blockers ORDER BY c DESC LIMIT 10;" --path=/var/www/europulse/public
sudo -u www-data wp db query "SELECT id,queue_id,post_id,source_id,phase,verdict,score,LEFT(blockers,260) blockers,LEFT(warnings,260) warnings,created_at FROM ep_epv2_quality_audit ORDER BY id DESC LIMIT 30;" --path=/var/www/europulse/public
```

Recent local commits:

- `R9: enforce worker provider cooldowns`
- `R10: add ops visibility and frontend foundation`
- `R11: document LLM handoff checkpoint`

## Latest Implementation Checkpoint 2026-05-24 19:36 UTC

- User asked for a careful hallucination audit, correct German/translation alignment with the source, and a large sample across categories.
- Large sample audited: `44` published queue groups across `ukraine`, `welt`, `politik`, `deutschland`, `wirtschaft`, `kultur`, `sport`, `leben-in-deutschland`, `bayern`, `muenchen`.
- Manual review confirmed severe source-expansion hallucinations in multiple categories:
  - short or empty primary source + URL-only supporting sources were expanded into full articles with unsupported names, numbers, quotes, locations, and context;
  - representative queue IDs: `6352`, `6332`, `6354`, `6092`, `6325`, `6296`, `6230`, `5694`, `5589`, `6300`, `6244`.
- Implemented/deployed a narrow hard publish gate:
  - `wp-plugins/europulse-autopilot-v21/includes/publish/class-epv2-publish-gate.php`;
  - adds source support profiling and `source_expansion_risk`;
  - keeps `thin_source_dossier` blocking;
  - `_meta.top_story` no longer bypasses source checks; only urgent breaking signals keep the old thin-source bypass, and source-expansion still blocks;
  - live backup: `/root/tmp/europulse-live-backups/20260524-anti-hallucination/class-epv2-publish-gate.php`;
  - repo/live diff clean, PHP lint passed, PHP-FPM reloaded.
- Gate proof after deploy:
  - all manually confirmed bad IDs now block:
    `6332` with `thin_source_dossier`;
    `6352, 6354, 6092, 6325, 6296, 6230, 5694, 5589, 6300, 6244` with `source_expansion_risk`.
- Proper-name preservation:
  - worker and PHP maps extended for `EuroPulse`, `Wall Street`, `The New York Times`, `The Washington Post`, `Reuters`, `Bloomberg`, `BBC`, and Wall Street spelling variants;
  - keep-Latin token list extended so the hybrid repair does not re-Cyrillicize those canonical names;
  - translator regression tests now cover this, and `4` tests pass.
- Existing visible repair:
  - dry-run found `17` published Ukrainian posts with old proper-name / broken URL defects;
  - backup file: `/tmp/epv2_rendered_repair_backup_20260524_1939.json`;
  - repaired all `17` via `EPV2_Post_Audit::repair_rendered_text_after_publish()`;
  - follow-up SQL found no remaining visible hits for the checked defects.
- Important unresolved live-content issue:
  - the `11` manually confirmed hallucination queue groups are still published (`33` WP posts);
  - attempted quarantine/draft action was blocked by execution policy because unpublishing live posts needs explicit user approval;
  - if the user explicitly approves, draft/quarantine queue IDs `6352, 6332, 6354, 6092, 6325, 6296, 6230, 5694, 5589, 6300, 6244` after backing up statuses/content.
- Final service state:
  - public IP returns `200 OK`;
  - `epv2-worker` active after restart at `19:34 UTC`, `/health` OK, RSS about `76M`;
  - `epv2-orchestrator` active and idle with no processable items;
  - `php8.3-fpm` active, `slow=0`;
  - queue snapshot: `published=214`, `rejected=34`;
  - active alerts empty.
- Next:
  - observe new autonomous cycles after `2026-05-24 19:36 UTC`;
  - check whether valid compact briefs are being falsely blocked by `source_expansion_risk`;
  - ask for explicit approval before unpublishing/drafting the `11` known-bad published groups;
  - if false positives are high, tune only the ratio/floor thresholds, do not remove the source-support guard.

## Latest Implementation Checkpoint 2026-05-24 00:24 UTC

- User asked to continue by plan without further confirmations, avoid breaking what works, and write memory before context limits.
- Runtime audit after the 2026-05-23 checkpoint proved the safety net was still doing too much work:
  - after `2026-05-23 16:15:00`, `22` new item groups / `66` posts had `post_publish_rendered` rows;
  - all `22` UK posts had repair warnings, far above the roughly `5%` threshold;
  - additional visible defects were found: transliterated URLs such as `гттп://204.168...` and source names such as `Дойтшландфунк`, `Süddeutsche Цайтунг`, `ПроСібен`, `МагентаСпорт`.
- Implemented repo-side prevention/repair:
  - `worker-v21/src/epv2_worker/translator.py`
    - preserves HTML tags and real `http(s)://` URLs before Latin/Cyrillic hybrid repair so links do not become `гттп://`;
    - removes already-broken transliterated URLs from UK text;
    - extends outlet/product normalization for `Deutschlandfunk`, `Süddeutsche Zeitung`, `24tv`, `MagentaSport`, `ProSieben`;
  - `includes/quality/class-epv2-quality-gate.php`
    - detects and repairs `broken_transliterated_url`;
    - extends the same UK brand/source preservation map;
  - added `worker-v21/tests/test_translator_quality_regressions.py`;
  - added fixture `worker-v21/tests/fixtures/quality/uk_rendered_repair_observed.json`.
- Validation before deploy:
  - `python3 -m py_compile` passed for changed worker files;
  - `PYTHONPATH=worker-v21/src worker-v21/.venv/bin/python -m unittest worker-v21/tests/test_translator_quality_regressions.py` passed (`2` tests);
  - `php -l` passed for changed PHP files.
- Deployed live:
  - copied `wp-plugins/europulse-autopilot-v21/includes/quality/class-epv2-quality-gate.php` to the live plugin;
  - backup: `/root/tmp/europulse-live-backups/20260524-002051/class-epv2-quality-gate.php`;
  - repo/live diff for that file was clean;
  - reloaded `php8.3-fpm`;
  - restarted `epv2-worker` while queue was idle so translator fixes were loaded.
- Live smoke:
  - `EPV2_Quality_Gate::repair_rendered_text()` on a real broken sample returned `EuroPulse`, `Deutschlandfunk`, `24tv`, `ProSieben`, removed `гттп://...`, and set `broken_transliterated_url` / `uk_brand_transliteration` repair flags.
- Targeted backfill:
  - proved remaining published defects in the last 48h: `13` broken transliterated URLs and `7` bad source-name forms;
  - backup table: `ep_epv2_post_repair_backup_20260524_broken_url_quality` (`18` rows);
  - repaired `18` posts with `/tmp/epv2_targeted_rendered_repair_20260524.php`;
  - repair counts: `broken_transliterated_url=13`, `uk_brand_transliteration=7`;
  - final counters: `0` broken transliterated URLs, `0` bad source-name forms, `0` sentence glue, `0` placeholders.
- Service state after deploy/backfill:
  - worker `/health` OK: RSS about `100.5M`, `recycle_scheduled=false`;
  - `epv2-orchestrator` active;
  - public IP returned `200 OK`;
  - queue remained idle: `published=182`, `rejected=10`, `active_processing=0`.
- Still not done:
  - observe the next fresh autonomous cycle after `2026-05-24 00:24 UTC`;
  - measure whether UK repairs drop below the roughly `5%` threshold;
  - do not enable Phase 3 hard/review blocking until post-fix shadow/rendered data is clean;
  - duplicate-story publishing remains separate work.

## Follow-up Checkpoint 2026-05-24 12:15 UTC

- Read-only state check showed the site/plugin are running:
  - `europulse-autopilot-v21` active;
  - worker `/health` OK, RSS about `226.5M`, `recycle_scheduled=false`;
  - `epv2-orchestrator` active and processing autonomous items;
  - public IP returned `200 OK`;
  - automation/collect enabled and active alerts option empty.
- Fresh post-fix data after `2026-05-24 00:24:00`:
  - `33` item groups / `99` rendered audit rows;
  - UK repair warnings were down to `4/33 = 12.1%`, better but still above the roughly `5%` target;
  - broken transliterated URLs and bad source-name forms stayed at `0`;
  - two new placeholder cases were found inside anchor text: `(<a ...>посилання на статтю</a>)`.
- Implemented/deployed a narrow PHP safety-net fix:
  - `EPV2_Quality_Gate::detect_placeholder_link_text()` now detects placeholder anchor text;
  - `EPV2_Quality_Gate::repair_rendered_text()` removes placeholder anchor parentheticals;
  - backup before live copy: `/root/tmp/europulse-live-backups/20260524-1212/class-epv2-quality-gate.php`;
  - repo/live diff for quality gate was clean and `php -l` passed;
  - PHP-FPM reloaded.
- Targeted placeholder backfill:
  - backup table: `ep_epv2_post_repair_backup_20260524_placeholder_anchor_quality`;
  - repaired `2` posts;
  - final visible counters after repair: `0` broken URLs, `0` bad source names, `0` sentence glue, `0` placeholders.
- Current queue at the check:
  - `new=20`, `ready_publish=1`, `published=193`, `rejected=37`, `active_processing=21`;
  - do not manually force jobs; continue observing autonomous drain/publish.
- Next:
  - observe the next autonomous publishes after `2026-05-24 12:15 UTC`;
  - recalculate UK repair rate on only post-12:15 rows;
  - continue upstream prevention if still above roughly `5%`;
  - still do not enable hard blocking/review routing.

## Latest Implementation Checkpoint 2026-05-23 16:15 UTC

- User explicitly approved production deployment and targeted backfill.
- Deployed live:
  - `EPV2_Post_Audit::repair_rendered_text_after_publish()` for lightweight post-publish text repair/audit in auto-mode;
  - `EPV2_Publisher::publish_item()` now runs the lightweight rendered text audit when heavy post-publish audit is skipped;
  - `EPV2_Quality_Gate::uk_brand_preservation_map()` extended with observed concrete forms:
    `Киівпост`, `Украінска Правда`, `Тагесспігел`, `Дойтше Велле`, `Фінанке.уа`, `Поліке Аукс Фронтіèрес`.
- Live deployment:
  - backup files saved under `/root/tmp/europulse-live-backups/20260523-160723/`;
  - repo/live diffs for deployed files were clean;
  - live `php -l` passed for changed files;
  - `php8.3-fpm` reloaded and public site returned `200 OK`.
- Targeted backfill:
  - backup table: `ep_epv2_post_repair_backup_20260523_uk_quality`;
  - repaired UK posts published after `2026-05-22 23:21:00`;
  - final counters: `44` UK posts checked, `0` sentence-glue SQL hits, `0` placeholder markers, `0` observed bad outlet/brand forms.
- New autonomous proof:
  - item `5923` published at `2026-05-23 16:09:33` as posts `16280/16281/16282`;
  - new post-publish rendered rows were recorded automatically before backfill;
  - UK post `16281` was repaired and passed with no blockers.
- Service status after deployment:
  - worker `/health` OK, RSS about `233M`, `recycle_scheduled=false`;
  - automation/collect enabled, heartbeat fresh, active alerts empty;
  - queue is active after a fresh collect (`21` new rows), not stuck.
- Next:
  - keep observing new autonomous posts for recurring UK repairs;
  - move prevention upstream into worker translator/prompt so repair becomes rare;
  - separately address duplicate-story publishing, e.g. Denmark/Frederiksen appeared twice from Tagesspiegel and SPIEGEL.

## Latest Implementation Checkpoint 2026-05-22 23:21 UTC

- The 24h shadow observation threshold was passed: `ep_epv2_quality_audit` had hundreds of `publish_gate_shadow` rows.
- Real published-quality problems were confirmed in UK posts:
  - sentence glue after punctuation;
  - placeholder text such as `(посилання)`;
  - bad transliteration of known brands/outlets such as `ОйроПулсе`, `Ваимо`, `Гайсе`, `ТехКрунх`, `24тв`, `Багн.де`, `Лінукс`, `Голем`.
- Code deployed live:
  - `EPV2_Quality_Gate` now evaluates rendered text and records `post_publish_rendered`;
  - `EPV2_Post_Audit` now safely repairs UK title/excerpt/content after publish and records rendered quality;
  - `EPV2_AI_Response_Validator::detect_invented_numbers()` strips URLs/IPs before number matching.
- Backfill repair:
  - backup table: `ep_epv2_post_repair_backup_20260522_uk_quality`;
  - checked `70` UK posts from the last 24h;
  - repaired `67` on first pass and `9` on second pass;
  - final DB checks showed `0` remaining known bad brand forms, `0` placeholder link markers, and `0` Cyrillic sentence-glue regex hits.
- Validation:
  - repo/live `php -l` passed for changed files;
  - live sample post `15763` was detected correctly before repair;
  - queue `5702` no longer reports URL/IP as invented numbers;
  - latest-hour `post_publish_rendered` audit rows were all `pass`;
  - worker, PHP-FPM, and public HTTP checks were healthy.
- Still do not enable hard blocking yet; use the new rendered audit data to decide the next Phase 3 blockers.

## Latest Implementation Checkpoint 2026-05-21 20:06 UTC

- Started the 2026-05-21 autonomous hardening plan with the safe audit/shadow phase only.
- Deployed live:
  - `includes/metrics/class-epv2-quality-audit.php`
  - `includes/quality/class-epv2-quality-gate.php`
  - autoload entries in `includes/bootstrap.php`
  - `epv2_quality_audit` schema in `includes/core/class-epv2-installer.php`
  - shadow call from `EPV2_Publish_Gate::evaluate()`
  - admin submenu/page `Качество`
- Live schema upgrade created `ep_epv2_quality_audit`.
- Validation:
  - live PHP lint passed for all changed files;
  - live autoload sees `EPV2_Quality_Audit` and `EPV2_Quality_Gate`;
  - write/delete smoke test passed;
  - read-only quality gate check on latest published payload returned `verdict=pass`, `score=92`;
  - seed-only payloads are now skipped by publish-gate shadow recording to avoid noisy audit rows.
- Behavior remains shadow-only:
  - `quality_shadow` does not change `allowed`;
  - no worker `/quality_audit`, source trust, clustering, post-publish rendered audit, or cache purge integration yet;
  - no manual `collect`, `process`, or `publish` was triggered.
- Next safe action is observation: let real publish-gate calls populate `ep_epv2_quality_audit`, then review the `Качество` admin page / DB counts before enabling review/blocking behavior.

## Latest Live State Observed 2026-05-20 09:45 UTC

- User re-enabled the watched autonomous run and explicitly asked not to manually push `collect/process/publish`.
  - `epv2_automation_paused=0`
  - `epv2_collect_paused=0`
  - next planned collect from `EPV2_Jobs::next_collect_timestamp()` is `2026-05-20 10:00:00 UTC`.
  - Queue at `09:43 UTC`: `published=84`, no `new`, `ready_publish`, `retry_process`, or processable rows.
- Services/resources are stable:
  - `epv2-orchestrator` active since `2026-05-19 23:33:31 UTC`, about `21.8M` memory, heartbeat fresh (`thread_alive=true`, heartbeat timestamp `2026-05-20 09:43:42 UTC` at last check).
  - `epv2-worker` active since `2026-05-20 09:31:29 UTC`, `/health` OK on port `8765`, RSS around `100M` after restart.
  - `php8.3-fpm` was reloaded after live plugin deploy; no current site `500/502` observed in this checkpoint.
  - Resource snapshot was calm: worker about `102M` RSS, orchestrator about `25M` RSS, PHP-FPM children about `85-90M` RSS.
- Dashboard heartbeat warning cause:
  - the old `Publisher inactive / Orchestrator heartbeat не приходит ...` warning was stale relative to live checks;
  - `systemctl status epv2-orchestrator` is active and WP option `epv2_publish_thread_heartbeat` is fresh.
- Latest category incident:
  - User noticed latest published row `4842` / post `13694` went to `sport`, although it is politics/world.
  - DB proved the input selector did work: `category_proposed=welt` and `admin_notes.selection.category=welt`.
  - The category was overwritten later: `ai_payload.categories[0]=sport`, `ai_payload._meta.selection.category=sport`.
  - Root cause: the upfront semantic `story_card` seed was saved without `_meta.editorial_prompt_version`; `drop_stale_payload_version_mismatch` treated that seed as stale and wiped it before worker processing. Then worker-side shallow `content_type=sport` heuristic could override `welt`.
- Latest global fixes deployed live:
  - `worker-v21/src/epv2_worker/pipeline.py`: worker keeps `request.category_proposed` as the primary category; local semantic `content_type` is only a fallback when no category exists; high-confidence Story Card may still override.
  - `worker-v21/src/epv2_worker/semantic.py`: keyword matching now uses token boundaries; weak sport words (`match`, `league`, `goal`, etc.) cannot flip content type without stronger sport evidence.
  - `includes/publish/class-epv2-publish-gate.php`: only explicit `_meta.manual_mode` bypasses selection blockers; `top_story` / `breaking` no longer make `selection=reject|low` publishable.
  - `includes/queue/class-epv2-queue.php`: low/reject selection is bypassed only by manual mode, not automatic priority/top-story flags.
  - `includes/ai/class-epv2-ai-processor.php`: stale editorial payload reset now preserves semantic seed fields (`story_card`, `source_dossier`, `context_memory`); seed-only payloads do not count as reusable editorial context and are merged into the fresh baseline.
- Validation completed:
  - `python3 -m py_compile` passed for changed worker files.
  - `php -l` passed for changed PHP files, including live `class-epv2-ai-processor.php`.
  - Worker test for the Guardian politics title now returns `content_type=news`, not `sport`.
  - Live publish gate check on row `4842` now returns `selection_publishable=false`, `manual_override=false`, `blockers=["selection_reject"]`.
  - Live reflection test confirmed semantic seed preservation/merge functions work.
  - PHP changes were copied to `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`, PHP-FPM reloaded, worker restarted.
- Command-limit note:
  - At about `2026-05-20 09:49 UTC`, new escalated live checks (`wp eval`, worker `curl /health`) were rejected by the Codex environment usage limit with "try again at 10:43 AM".
  - Do not treat missing post-09:49 live checks as service failure. Continue when live command access is available again.
  - A local `ps` still succeeded and showed worker about `103M` RSS, PHP-FPM workers about `94-96M` RSS, MariaDB about `519M` RSS.

## Previous Live State Observed 2026-05-20 06:01 UTC

- Automation is running, collection remains paused intentionally:
  - `epv2_automation_paused=0`
  - `epv2_collect_paused=1`
- Resource state is stable:
  - `epv2-worker` active since `2026-05-19 23:59:22 UTC`; `/health` OK with `rss_mb=182.4`, `request_count=30`, `recycle_scheduled=false`.
  - `epv2-orchestrator` active since `2026-05-19 23:33:31 UTC`; about `21.8M` RSS.
  - `php8.3-fpm` active, `slow=0`, memory about `106.9M`.
  - Frontend/admin HTTP checks responded with expected redirects; no current `500/502`.
- Queue/process state is drained:
  - `published=84`, `rejected=4`.
  - `EPV2_Queue::workflow_v2_preview_selection(false)` returns `mode=none`.
  - `EPV2_Queue::has_processable_items()` returns `false`.
  - Orchestrator logged `process idle` at `2026-05-20 05:59:12 UTC`.
- Autonomous proof after selector repair:
  - `4842` moved from stuck `retry_process/rebuild_bundle` through process to `ready_publish`, then published at `2026-05-20 05:58:00 UTC` as post `13694`.
  - `4829` moved out of `retry_process` and was terminalized by automation: worker plagiarism gate failed DE uniqueness (`61.1% < 85%`), then auto-mode review policy rejected it at `2026-05-20 05:57:51 UTC`.
  - `4843` rejected with `manual_confirmation_required=worker_blockers`.
  - `4844` and `4845` rejected as stale TTL (`hard_editorial — TTL exceeded`).
- Latest global queue fixes deployed live:
  - v2 selector now previews/claims the whole processable workflow (`new`, stage resume, auto resume, reserve), not only `new`.
  - `has_processable_items()`, workflow preview, and real claim now share the same processable path.
  - Maintenance now repairs persisted `retry_process` stage-contract drift (`repair_persisted_retry_process_stage_contract`) and publish-finish translation drift.
  - Stage/auto resume filters now honor `item_is_processable_read_only()`.
  - Chronic recyclers are blocked at selector level and terminal markers cannot be revived by later `mark_state()` calls.

## What Was Just Fixed

Latest 2026-05-19 22:00-2026-05-20 00:06 UTC fixes:

- Worker memory protection:
  - `worker-v21/src/epv2_worker/server.py` reports RSS/request/recycle fields in `/health`.
  - Worker recycles itself on RSS/request thresholds and has a background RSS monitor for long requests.
  - Large spaCy DE/UK NER models are disabled by default in `rewriter.py` and `translator.py`; enable only with `EPV2_ENABLE_SPACY_DE_NER` / `EPV2_ENABLE_SPACY_UK_NER`.
- Orchestrator worker guard:
  - `worker-v21/epv2_bridge_orchestrator.py` checks worker `/health` before collect, breaking scan, process, and handoff; unhealthy worker causes a cooldown skip instead of PHP/WP work piling up.
- False retry/reject fixes:
  - `class-epv2-worker-client.php` pings `/health` before `/process`, uses a shorter availability cache, and caps long HTTP timeouts.
  - `class-epv2-story-card-builder.php` invalidates worker availability on `/analyze_story` transport failures.
  - `class-epv2-queue.php` no longer counts worker-unavailable/timeouts as chronic recycler attempts and now reports publish-gate blockers more clearly on stage attempt quarantine.
  - `class-epv2-publish-gate.php` exposes `thin_source_dossier` explicitly instead of hiding it behind generic `payload_contract`.
  - `class-epv2-ai-processor.php` treats `translation failed: All providers failed` as provider/technical retry, and rescues rebuild short-circuit `selection=low/reject` when the row has strong ingest/story-card signals.
  - `rewriter.py` retries once with an anti-plagiarism directive when the DE rewrite fails the uniqueness gate.
  - `translator.py` retries German-looking EN output and demotes Ukrainian style filler warnings from fatal blockers.
- Live deployment:
  - PHP changes were copied into `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`.
  - PHP lint passed for changed live files.
  - Worker was restarted after Python changes; latest successful `/health` before approval limit: `status=ok`, RSS about `172.6M`, `request_count=6`.

- Runtime resource protection:
  - `epv2-worker`, `epv2-orchestrator`, and `epv2-bot` now have live systemd resource guards: memory high/max/swap limits, CPU quotas, task limits, restart throttles, lower priority, and OOM stop policy.
  - Worker guard currently: `MemoryHigh=640M`, `MemoryMax=768M`, `MemorySwapMax=128M`, `CPUQuota=80%`, `TasksMax=64`.
  - Orchestrator guard currently: `MemoryHigh=384M`, `MemoryMax=512M`, `MemorySwapMax=128M`, `CPUQuota=60%`, `TasksMax=64`.
  - Repo has matching setup/drop-in changes in `worker-v21/*setup.sh` and `ops/systemd/`.
- Orchestrator/collector safety:
  - `worker-v21/epv2_bridge_orchestrator.py` runs `breaking_scan` via WP-CLI instead of REST/PHP-FPM, with timeout/process-group kill and collect-lock recovery.
  - Breaking scan now respects `collect_paused`.
  - Orchestrator added memory preflight guards for process, collect, breaking scan, publish, and process handoff.
  - `EPV2_Collector::run_breaking_scan()` returns `{"skipped":"collect_paused"}` when collection is paused; this was deployed to the live plugin.
- Server cleanup:
  - The stuck `msmtp/apparmor` `dpkg` prompt was resolved non-interactively.
  - `dpkg --audit` and `apt-get check` were clean.
  - Swap was cleaned from about `1.1G` used to about `7-8M`.
  - A stale detached `tmux` session with two old `claude` processes using about `1.4G` RSS was stopped.
- Controlled automation restart:
  - Stale active queue was backed up to `ep_epv2_queue_backup_pre_controlled_restart_20260519` and cleared by marking `16` active rows as `rejected`.
  - Broken source `id=11` (`muenchen.de Rathaus Umschau`, `http://www.muenchen.info/pia/RSS/RSS.xml`) was disabled because it returns HTML, not RSS.
  - `epv2-worker` and `epv2-orchestrator` were started under guards.
  - `epv2_automation_paused=0`; `epv2_collect_paused=1` intentionally remains set.
  - Publisher heartbeat is fresh again, so the dashboard warning "Publisher inactive / Orchestrator heartbeat" should clear.
- DeepSeek/provider-order repair:
  - WordPress now sends `ai_provider`, `ai_model`, `ai_fallback_provider`, and `ai_fallback_model` to `/analyze_story`.
  - Worker respects explicit provider order. If primary is DeepSeek and OpenAI is not configured as fallback, OpenAI is not silently appended.
  - OpenAI embeddings are skipped unless OpenAI is actually in provider order.
  - Worker-side provider cooldown prevents repeated OpenAI quota/rate-limit loops.
- Frontend stability:
  - `europulse_latest_list` uses the shared home pool when available instead of a heavy `WP_Query` + `meta_query` path.
  - `europulse_autopilot_home_pool` has a short persistent cache to avoid rebuilding the same pool on every PHP request.
- Operational noise reduction:
  - REST bridge auth success logs and high-frequency process logs are throttled.
  - Healthcheck is pause-aware: when `epv2_automation_paused=1`, inactive worker/orchestrator are not treated as incidents.
  - Alert visibility lives in `epv2_active_alerts`.

## Previous Live State Observed 2026-05-20 00:06 UTC

- `epv2_automation_paused=0`
- `epv2_collect_paused=1`
- Last successful service observation before command-approval limit:
  - `epv2-worker` active after restart at `2026-05-19 23:59:22 UTC`, RSS stayed roughly `100-173M`, not the previous `650M+`.
  - `epv2-orchestrator` active and executing process/publish threads.
  - Later `curl /health` and `systemctl status` attempts were rejected by the environment approval/usage limit, not by a service failure. Do not infer downtime from that.
- Publisher proof:
  - `4838` reached `ready_publish` autonomously and published at `2026-05-19 23:59:23 UTC`, post `13666`.
  - `4831` published at `2026-05-20 00:05:00 UTC`, post `13674`.
  - `4832` was still `ready_publish`; due slot observed as `2026-05-20 00:09:50 UTC`.
- Latest queue snapshot at `2026-05-20 00:05 UTC`:
  - `published=82`
  - `ready_publish=1`
  - `new=13`
  - `retry_process=5`
  - `rejected=2`
- Current notable rows:
  - `4832`: `ready_publish`, should publish on the next due publish tick.
  - `4825`, `4826`: requeued after anti-plagiarism retry fix.
  - `4833`: requeued after selection-drift short-circuit fix.
  - `4837`: requeued after translation/provider-failure retry fix; latest run kept it in `rebuild_bundle`.
  - `4829`, `4842`, `4843`, `4844`, `4845`: still `retry_process/rebuild_bundle` from earlier false-reject recovery; continue observing.
  - `4830`: left rejected intentionally; root cause is genuinely thin source dossier (primary one-sentence source and empty supporting URLs), not resource failure. It should not be auto-published without better source content.
  - `4839`: rejected autonomously as `selection=low`, score `40` vs publish threshold `41`; not a stuck/resource issue.
- Approval-limit note:
  - The session hit the environment's escalation approval/usage limit after the `00:05` checks. Do not try to work around rejected live commands. Continue from docs or ask the user to approve/renew live command access.

## Previous Live State Observed 2026-05-19 21:46 UTC

- `epv2_automation_paused=0`
- `epv2_collect_paused=1`
- `nginx`, `php8.3-fpm`, `mariadb`, `epv2-worker`, and `epv2-orchestrator` are active.
- Worker: active since `2026-05-19 21:38:20 UTC`, about `110M` RSS, guarded by `MemoryMax=768M`.
- Orchestrator: active since `2026-05-19 21:38:48 UTC`, about `13M` RSS, guarded by `MemoryMax=512M`.
- `epv2_active_alerts`: `alerts=[]`, checked at `2026-05-19T21:46:19Z`.
- Memory: about `2.1G/3.7G` used, `1.7G` available.
- Swap: about `7M/2.0G` used.
- Queue has no active `new`, `ready_review`, or `ready_publish` rows. Latest observed state after maintenance: `published=133` only. The stale rows were backed up first, then rejected, then trimmed by maintenance.
- Bridge state after restart: `worker_available=true`, `has_processable_items=false`, `next_ready_publish=null`, `current_window_mode=wind_down_quiet`, `collect_window_open=false`, `publish_window_open=true`, `has_breaking_watch=false`.
- Front page responds through FastCGI cache (`X-FastCGI-Cache: HIT`).

## Next Actions

1. Continue observation of the watched autonomous run. Do not manually trigger `collect`, `process`, or `publish` unless the user changes that instruction.
2. Wait for the planned `2026-05-20 10:00:00 UTC` collect. If no collect happens after the planned slot, investigate scheduler/orchestrator state instead of forcing collection manually.
3. After new rows appear, verify the whole autonomous path:
   - input `admin_notes.selection.category`;
   - `_meta.story_card.category`;
   - `ai_payload.categories`;
   - `_meta.selection.decision`;
   - process stage transitions;
   - publish gate result and final WP categories.
4. Keep watching resource pressure while automation runs; no process should be allowed to fill memory/swap despite resource guards.
5. Useful checks:
   - `systemctl status epv2-worker epv2-orchestrator --no-pager -l`
   - `curl -s --max-time 10 http://127.0.0.1:8765/health`
   - `sudo -u www-data wp db query "SELECT state, COUNT(*) c FROM ep_epv2_queue GROUP BY state ORDER BY c DESC; SELECT id,state,pipeline_stage,post_id,updated_at,LEFT(error_message,260) error_message FROM ep_epv2_queue WHERE id BETWEEN 4825 AND 4847 ORDER BY id;" --path=/var/www/europulse/public`
   - `sudo -u www-data wp eval 'var_export(EPV2_Queue::workflow_v2_preview_selection(false)); echo PHP_EOL; var_export(EPV2_Queue::has_processable_items()); echo PHP_EOL;' --path=/var/www/europulse/public`
   - If the environment still rejects escalated commands, pause live observation and report the limit instead of attempting a workaround.
6. Confirm source `id=11` stays disabled or replaced with a real RSS feed.
7. Do not restore or publish stale queue content. The stale active rows were cleared and backed up in `ep_epv2_queue_backup_pre_controlled_restart_20260519`.
8. Commit or explicitly hand off the uncommitted safety edits after the next stable observation window.

## Guardrails

- Do not re-enable OpenAI as implicit fallback while quota is exhausted.
- Do not force collection just to test healthcheck.
- Do not treat inactive worker/orchestrator as an incident while `epv2_automation_paused=1`.
- With the current state, inactive worker/orchestrator would be an incident because `epv2_automation_paused=0`.
- Do not commit local artifacts: `.claude/`, `prepare`, `*.bak_pre_*`, raw `monitoring/snap-*.tsv`.
- If editing live code, remember repo source and `/var/www/...` live plugin are separate paths unless explicitly synced.
