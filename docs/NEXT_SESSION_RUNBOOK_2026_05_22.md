# EuroPulse Next Session Runbook — 2026-05-22 23:30 UTC

This document is the base runbook. Read it immediately after `LLM_START_HERE.md`, but the latest `2026-05-26 10:40 UTC` checkpoint in `LLM_START_HERE.md` overrides stale baselines below.

## Addendum — 2026-05-26 10:40 UTC

- User explicitly chose raising the AI budget over pacing that would make daytime publishing weak.
- Live AI settings are now `ai_budget_mode=normal`, `ai_selection_strictness=medium`, `ai_daily_request_soft_limit=800`, `ai_daily_token_soft_limit=12000000`.
- Repo/live settings code now permits up to `1000` AI requests and `12,000,000` AI tokens per day; admin UI displays those max values.
- Live budget proof after the change: `rewritten_today=269`, `tokens_today=6691309`, `request_limit=800`, `token_limit=12000000`, `hard_stop=false`. Counters were not reset.
- Quality protection should come from publish-grade selection, serious-category score floor `45`, source sufficiency/source-expansion gates, and rendered quality audit. Do not use `economy` mode as the main quality filter unless the user explicitly requests lower throughput.
- Branch `review/plugin-audit` is clean and `ahead 4`; local commits are `3f86e01`, `dcb1587`, `9be2d40`, `bd9f4f9`. Push still requires exact user approval: `разрешаю push в GitHub origin/review/plugin-audit`.
- Next live work: observe fresh autonomous cycles under the raised budget, especially 06:00-22:00 Europe/Berlin output quality and backlog drain. Do not manually trigger `collect`, `process`, or `publish`.

## Addendum — 2026-05-24 00:24 UTC

- UK broken URL/source prevention was deployed after this runbook was written.
- New live backup directory: `/root/tmp/europulse-live-backups/20260524-002051/`.
- New DB backup table: `ep_epv2_post_repair_backup_20260524_broken_url_quality`.
- New regression test: `PYTHONPATH=worker-v21/src worker-v21/.venv/bin/python -m unittest worker-v21/tests/test_translator_quality_regressions.py`.
- Healthy baseline after deploy/backfill:
  - worker `/health` OK, RSS about `100.5M`, `recycle_scheduled=false`;
  - public IP `200 OK`;
  - queue idle: `published=182`, `rejected=10`, `active_processing=0`;
  - final counters: `0` broken transliterated URLs, `0` bad source-name forms, `0` sentence glue, `0` placeholders.
- Next session should observe fresh posts after `2026-05-24 00:24:00 UTC`, not manually force jobs.
- Keep hard blocking/review routing disabled until the post-fix `post_publish_rendered` repair rate is measured and acceptable.

## Current State

- Repo: `/root/projects/europulse`
- Branch: `review/plugin-audit`
- Live WordPress: `/var/www/europulse/public`
- Live plugin: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`
- Automation is intended to stay live: `epv2_automation_paused=0`, `epv2_collect_paused=0`.
- The dirty tree is intentional. Do not revert unrelated changes.
- Quality hardening is still shadow/observability-first. Hard blocking is not enabled.

## What Was Just Completed

- `EPV2_Quality_Audit` / `ep_epv2_quality_audit` are live.
- `publish_gate_shadow` records payload quality before publish decisions.
- `post_publish_rendered` records rendered post quality after publishing.
- `EPV2_Post_Audit` safely repairs Ukrainian rendered text after publish:
  - missing space after sentence punctuation;
  - placeholder text such as `(посилання)`;
  - observed bad brand transliterations.
- Live backfill repaired Ukrainian posts from the previous 24h.
- Backup table for that backfill exists: `ep_epv2_post_repair_backup_20260522_uk_quality`.
- Final post-backfill counters were all zero for the observed defects.

## Absolute Do Not

- Do not run manual `collect`, `process`, or `publish` unless the user explicitly asks.
- Do not enable hard blocking/review routing yet.
- Do not start worker `/quality_audit` yet.
- Do not implement source trust, clustering/dedupe rewrite, cache manager, or rendered-cache verification before the checks below are reviewed.
- Do not repeat the 2026-05-22 backfill unless new defects are proven; the repaired posts already have a backup table.
- Do not paste bridge tokens or secrets into docs.

## First Commands To Run

Use read-only checks first.

```bash
date -u '+%Y-%m-%d %H:%M:%S UTC'
git status --short
systemctl status epv2-worker --no-pager -l
systemctl status epv2-orchestrator --no-pager -l
systemctl status php8.3-fpm --no-pager -l
curl -s --max-time 10 http://127.0.0.1:8765/health
curl -I --max-time 10 http://204.168.148.47/
```

Then check live options and queue:

```bash
sudo -u www-data wp db query "SELECT option_name, LEFT(option_value,700) option_value FROM ep_options WHERE option_name IN ('epv2_automation_paused','epv2_collect_paused','epv2_active_alerts','epv2_publish_thread_heartbeat') ORDER BY option_name" --path=/var/www/europulse/public

sudo -u www-data wp db query "SELECT state, COUNT(*) c FROM ep_epv2_queue GROUP BY state ORDER BY state; SELECT COUNT(*) active_processing FROM ep_epv2_queue WHERE state IN ('new','processing','retry_process','publishing','ready_review','manual_review','ready_publish')" --path=/var/www/europulse/public
```

Expected healthy baseline from 2026-05-22 23:22 UTC:

- worker `/health` OK, RSS around `229M`, `recycle_scheduled=false`;
- PHP-FPM active, `slow=0`;
- site IP returns `200 OK`;
- `active_processing=0` after the last observed cycle;
- active alerts empty.

If this baseline changed, diagnose services/queue first. Do not force jobs manually.

## Quality Checks To Run Next

Check the quality audit distribution:

```bash
sudo -u www-data wp db query "SELECT phase, verdict, COUNT(*) c, ROUND(AVG(score),1) avg_score, MIN(created_at) first_seen, MAX(created_at) last_seen FROM ep_epv2_quality_audit WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR GROUP BY phase, verdict ORDER BY phase, verdict; SELECT id,queue_id,post_id,source_id,phase,verdict,score,LEFT(blockers,260) blockers,LEFT(warnings,260) warnings,created_at FROM ep_epv2_quality_audit ORDER BY id DESC LIMIT 40" --path=/var/www/europulse/public
```

Check whether new Ukrainian rendered repairs are still happening:

```bash
sudo -u www-data wp db query "SELECT warnings, COUNT(*) c FROM ep_epv2_quality_audit WHERE phase='post_publish_rendered' AND created_at >= '2026-05-22 23:21:00' GROUP BY warnings ORDER BY c DESC LIMIT 20; SELECT verdict, COUNT(*) c, ROUND(AVG(score),1) avg_score FROM ep_epv2_quality_audit WHERE phase='post_publish_rendered' AND created_at >= '2026-05-22 23:21:00' GROUP BY verdict" --path=/var/www/europulse/public
```

Check that the fixed visible defects have not returned:

```bash
sudo -u www-data wp db query "SELECT COUNT(*) glue FROM ep_posts WHERE post_type='post' AND post_status='publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR AND post_content REGEXP '[А-Яа-яІіЇїЄєҐґ][.!?][А-Яа-яІіЇїЄєҐґ]'; SELECT COUNT(*) placeholders FROM ep_posts WHERE post_type='post' AND post_status='publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR AND (post_content LIKE '%(посилання)%' OR post_content LIKE '%посилання на статтю%' OR post_content LIKE '%(link)%'); SELECT 'ОйроПулсе' pattern, COUNT(*) c FROM ep_posts WHERE post_type='post' AND post_status='publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR AND post_content LIKE '%ОйроПулсе%' UNION ALL SELECT 'Ваимо', COUNT(*) FROM ep_posts WHERE post_type='post' AND post_status='publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR AND (post_content LIKE '%Ваимо%' OR post_title LIKE '%Ваимо%') UNION ALL SELECT 'Гайсе', COUNT(*) FROM ep_posts WHERE post_type='post' AND post_status='publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR AND post_content LIKE '%Гайсе%' UNION ALL SELECT 'ТехКрунх', COUNT(*) FROM ep_posts WHERE post_type='post' AND post_status='publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR AND post_content LIKE '%ТехКрунх%' UNION ALL SELECT '24тв', COUNT(*) FROM ep_posts WHERE post_type='post' AND post_status='publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR AND post_content LIKE '%24тв%' UNION ALL SELECT 'Багн.де', COUNT(*) FROM ep_posts WHERE post_type='post' AND post_status='publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR AND (post_content LIKE '%Багн.де%' OR post_title LIKE '%Багн.де%') UNION ALL SELECT 'Лінукс', COUNT(*) FROM ep_posts WHERE post_type='post' AND post_status='publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR AND (post_content LIKE '%Лінукс%' OR post_title LIKE '%Лінукс%') UNION ALL SELECT 'Голем', COUNT(*) FROM ep_posts WHERE post_type='post' AND post_status='publish' AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 24 HOUR AND post_content LIKE '%Голем%'" --path=/var/www/europulse/public
```

Check the calibrated payload blockers:

```bash
sudo -u www-data wp db query "SELECT qa.verdict,q.state,COUNT(*) items FROM ep_epv2_quality_audit qa JOIN (SELECT MAX(id) id FROM ep_epv2_quality_audit WHERE phase='publish_gate_shadow' GROUP BY queue_id) latest ON latest.id=qa.id JOIN ep_epv2_queue q ON q.id=qa.queue_id WHERE qa.created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR GROUP BY qa.verdict,q.state ORDER BY qa.verdict,q.state; SELECT qa.blockers, COUNT(*) items FROM ep_epv2_quality_audit qa JOIN (SELECT MAX(id) id FROM ep_epv2_quality_audit WHERE phase='publish_gate_shadow' GROUP BY queue_id) latest ON latest.id=qa.id WHERE qa.created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR GROUP BY qa.blockers ORDER BY items DESC LIMIT 20" --path=/var/www/europulse/public
```

## Decision Rules

1. If service health is bad, fix services/resource pressure first.
2. If the queue is stuck, debug selector/orchestrator state. Do not manually publish.
3. If `post_publish_rendered` has blockers after `2026-05-22 23:21:00`, inspect examples before changing code.
4. If new bad brand forms appear, add only observed concrete mappings in `EPV2_Quality_Gate::uk_brand_preservation_map()`.
5. If UK repairs keep happening on more than roughly 5 percent of new UK posts, fix worker translator/prompt generation next. Post-audit repair is a safety net, not the primary solution.
6. If `unsupported_numbers` still fires on internal URLs or harmless single numbers, calibrate the detector further.
7. If `thin_source_dossier` still marks published good items as review, keep it shadow/soft until false positives are understood.
8. Only after at least one fresh autonomous cycle after 2026-05-22 23:21 UTC has clean rendered audit and acceptable false positives, implement Phase 3 review routing for confirmed hard blockers.

## Likely Next Code Work

Preferred order:

1. Worker/translator prevention for UK defects:
   - preserve known outlet/product names;
   - never output placeholder link text;
   - ensure paragraph/sentence spacing after translation.
2. Regression checks/fixtures for:
   - `ОйроПулсе` -> `EuroPulse`;
   - `Ваимо` -> `Waymo`;
   - `(посилання)` removed;
   - Cyrillic punctuation glue repaired/detected;
   - URL/IP does not trigger invented numbers.
3. Recalibrate `thin_source_dossier` and `unsupported_numbers` using latest `publish_gate_shadow` rows.
4. Implement Phase 3 review routing only for blockers proven low-false-positive.

## Files Changed In The Last Pass

- `wp-plugins/europulse-autopilot-v21/includes/quality/class-epv2-quality-gate.php`
- `wp-plugins/europulse-autopilot-v21/includes/publish/class-epv2-post-audit.php`
- `wp-plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-response-validator.php`
- `LLM_START_HERE.md`
- `SESSION_HANDOFF.md`
- `TODO.md`
- `docs/europulse-memory-brief.md`

Live copies of the three PHP plugin files were deployed and PHP-FPM was reloaded.

## Success Criteria For Next Session

A next session can move forward when all are true:

- services healthy;
- no active stuck queue rows;
- new `post_publish_rendered` rows are mostly `pass`;
- known UK visible defects remain at `0`;
- false positives for `thin_source_dossier` and `unsupported_numbers` are understood from current data;
- no manual job forcing was needed.

Then proceed to worker prevention/regression fixtures first, not hard blocking.
