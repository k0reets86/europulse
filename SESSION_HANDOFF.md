# SESSION HANDOFF

## Latest Handoff 2026-05-26 10:40 UTC — AI daily cap raised, normal/medium restored

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User rejected over-throttling/pacing that would make daytime publishing inadequate and explicitly directed to increase the AI limit. No manual `collect`, `process`, or `publish` was run.
- Repo/live code:
  - `wp-plugins/europulse-autopilot-v21/includes/core/class-epv2-settings.php` now accepts max `1000` daily AI requests and `12,000,000` daily AI tokens;
  - `wp-plugins/europulse-autopilot-v21/includes/admin/class-epv2-admin.php` shows the new max values in the settings UI;
  - both files were copied to the live plugin and passed `php -l` in repo and live paths.
- Validation after deploy: `tests/run.sh publish_gate`, `tests/run.sh quality_gate`, and `tests/run.sh home_pool` all passed when rerun with live WordPress access.
- Live setting applied:
  - `ai_budget_mode=normal`;
  - `ai_selection_strictness=medium`;
  - `ai_daily_request_soft_limit=800`;
  - `ai_daily_token_soft_limit=12000000`.
- Live budget proof after the change:
  - `rewritten_today=267`;
  - `tokens_today=6638384`;
  - `request_limit=800`;
  - `token_limit=12000000`;
  - `hard_stop=false`.
- Quality stance:
  - the system should not spend AI on weak candidates, but should not starve normal 06:00-22:00 daytime publishing;
  - weak-candidate filtering should come from publish-grade selection, serious-category score floor `45`, source sufficiency/source-expansion gates, and rendered quality audit;
  - do not use `economy` mode as the main quality filter unless the user explicitly wants lower throughput.
- Continue monitoring fresh autonomous output; do not reset AI counters, and do not manually force queue stages.
- GitHub push is still not done. It still requires the exact external-transfer approval phrase: `разрешаю push в GitHub origin/review/plugin-audit`.

## Latest Handoff 2026-05-26 10:20 UTC — publish floor verified, budget throttled, push still needs explicit approval

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User asked what remains, then asked to finish. No manual `collect`, `process`, or `publish` was run.
- Git:
  - branch `review/plugin-audit`;
  - clean working tree after this handoff update;
  - local commits not pushed: `9be2d40 Update home pool fixture after selection backfill`, `bd9f4f9 Enforce publish score floor and night schedule`;
  - push to `git@github.com:k0reets86/europulse.git` was blocked by approval policy. Required exact user approval: `разрешаю push в GitHub origin/review/plugin-audit`.
- Live health:
  - public IP returned `200 OK`;
  - `epv2-worker`, `epv2-orchestrator`, and `php8.3-fpm` active;
  - `epv2_active_alerts` empty;
  - worker RSS about `200M`; orchestrator active and processing/maintenance running.
- Fresh quality after the 2026-05-25 21:30 UTC score/night fix:
  - published posts after the fix with `selection_score <45`: `0`;
  - publishes in the closed night window after the fix: `0`;
  - `post_publish_rendered` after the fix: `33 pass`, average score `100`;
  - live audit since `2026-05-25 21:30:00` checked `11` published groups; `findings.hard=[]`, `findings.warn=[]`, `findings.info=[]`.
- Regression checks:
  - `publish_gate_test.php` passed;
  - `quality_gate_test.php` passed;
  - `home_pool_test.php` initially failed because fixture `6395` was no longer stale after canonical selection backfill; updated the test and it passed;
  - worker translator/rewriter unittests passed (`6` tests).
- Budget/backlog historical note, superseded by the `2026-05-26 10:40 UTC` handoff above:
  - live `ai_budget` hard stop was real at the old cap: about `254` rewrites and `6.3M` tokens against `500` / `5M`;
  - temporary `economy/medium` throttling was later replaced after the user explicitly chose a higher cap;
  - old queue snapshot was about `published=154`, `rejected=35`, `ready_review=1`, `new=27`, active processable `28`.
- Old known-bad live content still present:
  - published queue groups currently found: `6092`, `6230`, `6244`, `6296`, `6300`, `6325`, `6332`, `6352`, `6354`;
  - do not draft/quarantine/unpublish them without explicit approval for live content removal.

## Latest Handoff 2026-05-25 19:40 UTC — fresh translation/source audit clean; UK grammar repair deployed

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User asked to inspect what was done and verify plugin/news/translation quality against originals without hallucinations.
- Read-only live checks:
  - services/site healthy: public IP `200 OK`, `epv2-worker` health OK after restart, `epv2-orchestrator` active, active alerts option empty;
  - queue after final checks: `published=168`, `rejected=25`, `new=10`, `ready_publish=1`, `retry_process=1`;
  - `ai_budget` remains in hard stop (`rewritten_today=626`, `tokens_today=15851731`, limits `500` / `5000000`), so budget pressure is real and should not be misdiagnosed as a quality-gate crash.
- Live quality audits:
  - 80-row audit had hard findings none; warnings were old `published_item_source_risk` backlog plus legacy replay, not new-pipeline hallucinations;
  - fresh audit since `2026-05-25 19:00:00 UTC` checked `5` published rows across `politik`, `sport`, `ukraine`, `welt`: `findings.hard=[]`, `findings.warn=[]`, `findings.info=[]`;
  - current reject examples are mostly desired source/selection/stage blockers (`source_expansion_risk`, `thin_source_dossier`, `selection_low/reject`, `stage_attempt_limit`, `worker_blockers`).
- Manual source/translation spot-check:
  - queue `6632` matched its Ukrainska Pravda original facts: Lawrow/Rubio call, planned systematic strikes, evacuation recommendation, 24 May attack counts, casualties/building damage were all present in source;
  - found a real UK grammar defect, not a hallucination: `своє дипломатичне персонал` in post `17658`.
- Implemented/deployed narrow fix:
  - worker `worker-v21/src/epv2_worker/translator.py` now repairs the observed `своє дипломатичне персонал` class and treats `персонал` as masculine in the Ukrainian gender normalizer;
  - PHP `EPV2_Quality_Gate::repair_rendered_text()` now has a matching `uk_grammar` safety-net repair;
  - `detect_bad_brand_transliterations()` now uses case-sensitive matching so canonical `Українська правда` is not falsely flagged by the bad-key `Українська Правда`;
  - added `tests/suites/quality_gate_test.php`; `tests/run.sh` default suite now includes it.
- Live deployment/repair:
  - live backup: `/root/tmp/europulse-live-backups/20260525-uk-grammar-personal/class-epv2-quality-gate.php` plus `.before-case-detector`;
  - DB backup table before content repair: `ep_epv2_post_repair_backup_20260525_uk_grammar_personal`;
  - post `17658` repaired through `EPV2_Post_Audit::repair_rendered_text_after_publish`; final post-audit returned `pass`, score `100`, no blockers/warnings;
  - visible bad-personal counter is now `0`.
- Validation:
  - `python3 -m py_compile worker-v21/src/epv2_worker/translator.py`;
  - `PYTHONPATH=worker-v21/src worker-v21/.venv/bin/python -m unittest worker-v21/tests/test_translator_quality_regressions.py` => `5` tests OK;
  - PHP lint clean for repo/live `class-epv2-quality-gate.php` and `tests/suites/quality_gate_test.php`;
  - `tests/run.sh quality_gate`, `tests/run.sh publish_gate`, `tests/run.sh home_pool` all passed;
  - final worker health OK and publish thread heartbeat recovered to `thread_alive=true`.
- Operational note:
  - one overly broad diagnostic SQL join was killed after it held a metadata lock; processlist is clean afterward. Prefer simple queue queries or the audit script instead of multi-joining postmeta/terms for fresh published samples.

## Latest Handoff 2026-05-25 09:00 UTC — homepage selection regression covered; stale payload selection fixed

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User asked to continue autonomously, improve automation/quality, preserve Latin proper names, and write memory before limits. No manual `collect`, `process`, or `publish` was run. No published content was drafted/quarantined/deleted.
- Added and deployed a tighter homepage selection resolver:
  - repo/live `wp-mu-plugins/europulse-foundation/includes/core.php`;
  - new `europulse_home_resolve_selection()` makes final post meta authoritative and uses queue notes only as fallback;
  - avoids mixing a post decision with a stale queue score;
  - live backup: `/root/tmp/europulse-live-backups/20260525-home-selection-resolver/core.php`;
  - PHP lint clean, PHP-FPM reloaded, WP object cache flushed.
- Added homepage regression suite:
  - `tests/suites/home_pool_test.php`;
  - verifies post selection overrides stale queue selection, queue selection is fallback only, and live fixture `6395` remains eligible and uses post meta in home pool;
  - `tests/run.sh` default suite now includes `home_pool_test`;
  - `tests/run.sh home_pool` passed before later WP-CLI escalation usage-limit blocked a redundant rerun.
- Found and fixed a fresh reject false-positive source:
  - `EPV2_Publish_Gate::selection_decision()` previously preferred stale `payload._meta.selection.decision` over canonical `queue.admin_notes.selection.decision`;
  - recent rejected rows showed this clearly:
    - `6462` payload `reject:30`, notes `review:46`;
    - `6465` payload `low:41`, notes `review:40`;
    - `6454` payload `low:40`, notes `review:49`;
  - repo/live `wp-plugins/europulse-autopilot-v21/includes/publish/class-epv2-publish-gate.php` now prefers `admin_notes.selection.decision` and falls back to payload only if notes are absent;
  - live backup: `/root/tmp/europulse-live-backups/20260525-publish-gate-selection-precedence/class-epv2-publish-gate.php`;
  - PHP lint clean, PHP-FPM reloaded.
- Regression/verification:
  - `tests/suites/publish_gate_test.php` now covers canonical `admin_notes` overriding stale payload selection;
  - `tests/run.sh publish_gate` passed `7` checks after live deploy;
  - read-only gate replay after deploy:
    - `6461` still `decision=low`, blockers `selection_low,payload_contract,thin_source_dossier` (expected);
    - `6462` now `decision=review`, blockers `payload_contract,thin_source_dossier,source_expansion_risk`;
    - `6465` now `decision=review`, blockers `length_below_kind_minimum,sources_below_kind_minimum,source_expansion_risk`;
    - `6454` now `decision=review`, blockers `source_expansion_risk`.
- Audit tooling follow-up:
  - `scripts/epv2_live_quality_audit.php` now calls publish gate with context `audit_replay` instead of `ready_publish`, so future audits should not create `publish_gate_shadow` rows as a side effect;
  - the audit JSON now also includes `queue_state_counts`, `budget_state`, `recent_rejects`, and `reject_warn_counts`;
  - `recent_rejects` flags stale payload-vs-notes selection, stage attempt limits, and worker blockers;
  - `php -l scripts/epv2_live_quality_audit.php` passed;
  - this updated audit script was not re-run live because later WP-CLI escalations hit the platform usage-limit policy.
- Live audits/checks:
  - fresh audit since `2026-05-25 06:15:00 UTC`: `4` published rows across `deutschland`, `ukraine`, `welt`, `wirtschaft`; hard findings none; only `6332` flagged as old `thin_source_dossier/source_expansion_risk`;
  - large audit `120` published rows across `bayern`, `deutschland`, `kultur`, `politik`, `sport`, `ukraine`, `welt`, `wirtschaft`: hard findings none; warnings mostly old `published_item_source_risk=86`, `legacy_payload_contract_replay=1`;
  - queue counts at one read-only check: `published=203`, `rejected=18`;
  - public IP `http://204.168.148.47/` returned `200 OK`; `http://127.0.0.1/` redirects to the public IP; bridge REST without token returns expected `401`.
- Service state:
  - `epv2-worker` active since `2026-05-24 20:12:03 UTC`, health pings in logs return `200`;
  - `epv2-orchestrator` active since `2026-05-24 23:38:35 UTC`, currently idle/no processable items in logs;
  - orchestrator maintenance logs continue firing `alerts_fired: ["ai_budget"]`; next session should inspect budget settings/state before assuming collection/processing is stuck.
- Important limit:
  - later escalated WP-CLI reruns hit the platform usage-limit policy. Do not try to route around that. Continue with approved/read-only checks when available, or wait for approvals to reset.
- Still unresolved:
  - old known-bad published groups remain live and require explicit user approval before draft/quarantine: `6352, 6332, 6354, 6092, 6325, 6296, 6230, 5694, 5589, 6300, 6244`;
  - monitor the next autonomous cycle to confirm the stale payload selection fix reduces false `selection_low/reject` blockers;
  - calibrate `source_expansion_risk` / `thin_source_dossier` false positives only on fresh items, not old published replay;
  - inspect the `ai_budget` alert and budget/limit settings;
  - repo remains dirty with many older unrelated changes; do not revert user/previous-session work.

## Latest Handoff 2026-05-25 07:00 UTC — site visibility bug fixed; title-only gate deployed; regression/audit tooling added

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User asked to continue without confirmations, check/fix plugin and site, preserve proper names in Latin script, and avoid breaking working automation. No manual `collect`, `process`, or `publish` was run.
- Fixed live site visibility bug for queue `6395`:
  - `6395` was not missing from WordPress: DE/UK/EN posts `17315/17316/17317` were `publish`;
  - homepage/latest hid it because `europulse_autopilot_home_pool()` read stale `selection.decision=low` from `queue.admin_notes`, while final post meta was `europulse_selection_decision=review`, score `46`;
  - `wp-mu-plugins/europulse-foundation/includes/core.php` now prefers final post meta `europulse_selection_decision/europulse_selection_score` and uses queue notes only as fallback;
  - deployed live to `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation/includes/core.php`, PHP lint clean, PHP-FPM reloaded, WP object cache and Nginx fastcgi cache cleared;
  - proof via WP-CLI: `europulse_home_zone_ids('latest', 8)` now includes `17315|6395|Russland greift Charkiw und Sumy mit Drohnen an`.
- Deployed the previously repo-only title-only source guard:
  - live `EPV2_Publish_Gate` now blocks no-real-support/title-only cases as `source_expansion_risk` when `primary_body_chars < 120`, `primary_total_chars < 260`, and generated DE body is `> 280` chars;
  - backup: `/root/tmp/europulse-live-backups/20260525-title-only-source-gate/class-epv2-publish-gate.php`;
  - live PHP lint clean, repo/live diff clean, PHP-FPM reloaded.
- Automation health:
  - `epv2-worker` and `epv2-orchestrator` active;
  - autonomous publish-slot proved working: queue `6436` moved from `ready_publish` to `published` at `2026-05-25 06:27:40 UTC`, creating posts `17375/17376/17377`;
  - queue after checks: `published=205`, `rejected=34`, `new=4`.
- Reject interpretation:
  - high reject volume is not a service crash; current major reasons are `selection_low/reject`, `source_expansion_risk`, `thin_source_dossier`, and `auto_reject_review_policy`;
  - these are expected after the stricter source/quality gates, but still need false-positive calibration.
- Added automation/QA tooling:
  - `tests/suites/publish_gate_test.php` regression suite covers title-only source expansion, real-support allowance, long-primary allowance, and source profile behavior;
  - `tests/run.sh` now defaults to `wp --allow-root eval-file` because files under `/root` are not readable by `www-data`; `EPV2_TEST_WP_USER=www-data` remains available for readable paths;
  - `scripts/epv2_live_quality_audit.php` checks a configurable sample of published rows for missing translations, broken Ukrainian source/brand names, Cyrillic in English, source-risk blockers, queue/post selection mismatches, and latest visibility hints;
  - audit supports `EPV2_AUDIT_SINCE='YYYY-MM-DD HH:MM:SS'` for fresh-cycle checks and now prefers `ai_payload` over `publish_payload` so replay does not falsely evaluate post-id metadata as article content.
- Added dashboard visibility hint:
  - `wp-plugins/europulse-autopilot-v21/includes/admin/class-epv2-admin.php` now shows a `Сайт` column in lightweight queue tables;
  - published rows get `Latest`, `Slider`, `Архив`, `Скрыт`, or `Нет поста` with a tooltip showing post id, final post selection decision/score, and stale queue decision mismatch when present;
  - deployed live, backup: `/root/tmp/europulse-live-backups/20260525-admin-site-visibility/class-epv2-admin.php`;
  - live PHP lint clean, PHP-FPM reloaded, WP-CLI render proof returned `HAS_SITE_COLUMN`, `HAS_LATEST_BADGE`, `HAS_6395_ROW`.
- Validation:
  - `php -l` passed for live/repo `core.php`, live/repo `class-epv2-publish-gate.php`, `tests/suites/publish_gate_test.php`, and `scripts/epv2_live_quality_audit.php`;
  - `tests/run.sh publish_gate` passed all `6` checks;
  - `PYTHONPATH=worker-v21/src worker-v21/.venv/bin/python -m unittest worker-v21/tests/test_rewriter_thin_source.py worker-v21/tests/test_translator_quality_regressions.py` => `5` tests OK.
- Live quality audit result:
  - `EPV2_AUDIT_LIMIT=80 wp --allow-root eval-file scripts/epv2_live_quality_audit.php --path=/var/www/europulse/public` checked `80` published groups across `ukraine`, `welt`, `politik`, `deutschland`, `kultur`, `sport`, `wirtschaft`;
  - hard findings: none;
  - warnings after payload-source fix: `published_item_source_risk=58`, `legacy_payload_contract_replay=2`; these are mostly old published payloads that would be blocked by today's source rules. Treat as retro-audit backlog, not a fresh pipeline failure;
  - fresh audit since `2026-05-25 06:15:00 UTC` checked `2` rows: `6436` was clean, `6332` still flags `thin_source_dossier/source_expansion_risk` as expected known-bad old content.
- Still unresolved:
  - old known-bad published groups remain live and require explicit user approval before draft/quarantine: `6352, 6332, 6354, 6092, 6325, 6296, 6230, 5694, 5589, 6300, 6244`;
  - false-positive calibration for `thin_source_dossier` / `source_expansion_risk` on new items is still needed;
  - dashboard still lacks a clear "site visibility / hidden reason" indicator.

## Latest Handoff 2026-05-24 20:14 UTC — source-bound prompt deployed; repo-only title-only gate patch pending live deploy

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User asked to keep checking/fixing without confirmations, but do not break what works. No manual `collect`, `process`, or `publish` was run.
- Fresh service/site state after work:
  - public IP `http://204.168.148.47/` returns `200 OK`;
  - `epv2-worker` restarted at `20:12 UTC`, active, listening on `127.0.0.1:8765`; logs show repeated `/health 200 OK`;
  - direct escalated `curl` to worker health was rejected by approval usage limit, so do not retry/route around it;
  - `epv2-orchestrator` active and autonomous; it processed `6367` after restart;
  - PHP-FPM reload after AI prompt deploy succeeded.
- Quality/audit housekeeping:
  - accidental diagnostic call with `context=ready_publish` created `214` `publish_gate_shadow` rows; backed up to `/tmp/epv2_accidental_shadow_audit_backup_20260524_1958.json` and deleted `214` rows.
- Implemented/deployed source-bound prompt prevention:
  - `worker-v21/src/epv2_worker/rewriter.py`: sources under `120` words now force `length_profile=brief`, force composed prompt kind to `news_brief`, cap `max_tok` to `1024`, and inject a German `SOURCE-BOUND BRIEF` guard saying title/url-only supporting links are not factual support;
  - `wp-plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php`: adds `source_scope_for_prompt()` and sends `source_scope` in instructions;
  - the dangerous prompt sentence "обязательно усили материал..." is replaced with a rule that short/thin signals must not be expanded unless supporting sources contain real `excerpt/content`; title/url-only entries cannot justify new dates, numbers, names, places, causes, consequences, quotes, or background;
  - live AI processor backup: `/root/tmp/europulse-live-backups/20260524-source-bound-prompt/class-epv2-ai-processor.php`;
  - repo/live AI processor diff clean; live PHP lint clean; PHP-FPM reloaded; worker restarted.
- Validation:
  - `python3 -m py_compile worker-v21/src/epv2_worker/rewriter.py`;
  - `php -l wp-plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php`;
  - `php -l /var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php`;
  - `PYTHONPATH=worker-v21/src worker-v21/.venv/bin/python -m unittest worker-v21/tests/test_rewriter_thin_source.py worker-v21/tests/test_translator_quality_regressions.py` => `5` tests OK.
- Fresh autonomous proof/observations:
  - queue snapshot after restart included `published=211`, `rejected=24`, `new=18`;
  - active alerts option showed automation/collect not paused and no active alerts at `20:08:48Z`;
  - `6367` was rejected after attempts with `stage_attempt_limit_build_de_master` and blockers including `source_expansion_risk`; this is desired behavior for a thin FAZ/Haffner item.
- New gap found:
  - `6332` is still published and was updated/published around `20:00 UTC`; its payload has empty primary `content/excerpt`, supporting sources are URL-only, and `related` contains unrelated noise;
  - current live gate can still allow title-only sources when the generated DE body is short enough, because old `thin_source_dossier` did not catch the noisy `related/source_count` case.
- Implemented but NOT deployed:
  - repo `wp-plugins/europulse-autopilot-v21/includes/publish/class-epv2-publish-gate.php` now treats title-only/no-real-support cases as `source_expansion_risk` when `primary_body_chars < 120`, `primary_total_chars < 260`, and generated DE body is `> 280` chars;
  - repo PHP lint passes;
  - live backup prepared before deploy attempt: `/root/tmp/europulse-live-backups/20260524-title-only-source-gate/class-epv2-publish-gate.php`;
  - live deploy failed because escalation approval hit the usage-limit policy. Do not bypass this. Next session should deploy normally when approvals are available, lint live, reload PHP-FPM, and verify in diagnostic context.
- Unresolved live-content issue:
  - the `11` manually confirmed hallucination queue groups remain published as `33` WP posts;
  - explicit user approval is required before drafting/quarantining them: `6352, 6332, 6354, 6092, 6325, 6296, 6230, 5694, 5589, 6300, 6244`.

## Latest Handoff 2026-05-24 19:36 UTC — source-expansion hallucination gate deployed, visible UK proper names repaired

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User asked for a careful anti-hallucination audit: German and translations must match the source, and own names/proper names should not be translated or Cyrillicized.
- No manual `collect`, `process`, or `publish` was run.
- Large sample:
  - checked `44` published queue groups across multiple categories: `ukraine`, `welt`, `politik`, `deutschland`, `wirtschaft`, `kultur`, `sport`, `leben-in-deutschland`, `bayern`, `muenchen`;
  - automated flags showed many source/number/quote/proper-name risks;
  - manual review confirmed severe source-expansion hallucinations on short/empty sources with URL-only supporting entries.
- Confirmed bad representative IDs:
  - `6352` SPIEGEL Ukraine UNSC item expanded a short source into unsupported deaths/injuries/building damage/weapons/context;
  - `6332` Ukrinform DE had title only and generated a full article;
  - `6354`, `6092`, `6325`, `6296`, `6230`, `5694`, `5589`, `6300`, `6244` showed similar unsupported expansion or invented details.
- Implemented/deployed:
  - `EPV2_Publish_Gate` now computes a source support profile and blocks `source_expansion_risk` when there is no real supporting text, the primary source is short, and DE content expands beyond the safe ratio/floor;
  - `thin_source_dossier` is now active in publish gate;
  - `_meta.top_story` no longer bypasses source checks; urgent breaking may still bypass only the old thin-source check, not `source_expansion_risk`;
  - live backup: `/root/tmp/europulse-live-backups/20260524-anti-hallucination/class-epv2-publish-gate.php`;
  - repo/live diff clean, PHP lint passed, PHP-FPM reloaded.
- Proof:
  - all confirmed bad IDs now block in live gate evaluation:
    `6332 => thin_source_dossier`;
    `6352, 6354, 6092, 6325, 6296, 6230, 5694, 5589, 6300, 6244 => source_expansion_risk`.
- Proper-name preservation:
  - worker/PHP maps extended for `EuroPulse`, `Wall Street`, `The New York Times`, `The Washington Post`, `Reuters`, `Bloomberg`, `BBC`, and Wall Street variants;
  - keep-Latin tokens extended so hybrid repair does not turn canonical names back into Cyrillic;
  - validation passed: worker compile, translator regression unittest (`4` tests), PHP lint.
- Visible old-post repair:
  - dry-run found `17` published UK posts with old `ОйроПулсе`, broken `гттп`, Wall Street/proper-name defects;
  - backup JSON: `/tmp/epv2_rendered_repair_backup_20260524_1939.json`;
  - repaired all `17` through `EPV2_Post_Audit::repair_rendered_text_after_publish()`;
  - spot checks: post `8900` now starts `Wall Street: ...`, post `5992` excerpt ends with `Wall Street`;
  - follow-up SQL found no remaining visible hits for the checked defects.
- Unresolved live-content issue:
  - the `11` manually confirmed hallucination queue groups remain published as `33` WP posts;
  - attempted draft/quarantine was blocked by execution policy because unpublishing live posts requires explicit user approval;
  - target queue IDs if approval is given: `6352, 6332, 6354, 6092, 6325, 6296, 6230, 5694, 5589, 6300, 6244`.
- Final service/site state:
  - public IP `http://204.168.148.47/` returns `200 OK`;
  - `epv2-worker` active after restart at `19:34 UTC`, `/health` OK, RSS about `76M`;
  - `epv2-orchestrator` active and idle with no processable items;
  - `php8.3-fpm` active, `slow=0`;
  - queue snapshot: `published=214`, `rejected=34`;
  - `epv2_active_alerts` empty.
- Next:
  - observe autonomous cycles after `2026-05-24 19:36 UTC`;
  - check whether `source_expansion_risk` has false positives on valid compact briefs;
  - get explicit user approval before drafting/quarantining the `11` known-bad published groups;
  - if needed, tune only the ratio/floor thresholds;
  - do not remove the source-support guard and do not broaden other hard blockers until fresh data is reviewed.

## Latest Handoff 2026-05-24 18:46 UTC — read-only check clean, Ukrainiska Pravda guard deployed

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User said "проверяй"; performed read-only service/site/queue/quality checks and one narrow preventive patch based on observed shadow data. No manual `collect`, `process`, or `publish` was run.
- Service/site state:
  - `europulse-autopilot-v21` active;
  - `epv2-worker` active; after final restart at `18:45 UTC`, `/health` OK with RSS about `100.3M`, no recycle scheduled;
  - `epv2-orchestrator` active and autonomously collecting/processing/publishing;
  - `php8.3-fpm` active, `slow=0`;
  - public IP returned `200 OK`;
  - `epv2_automation_paused=0`, `epv2_collect_paused=0`;
  - `epv2_active_alerts` empty.
- Queue snapshot after final restart:
  - `new=15`, `new/publish_finish=1`, `ready_publish=2`, `published=210`, `rejected=29` split across stages;
  - no `processing` / `publishing` state in the snapshot.
- Quality after the `12:47 UTC` worker restart:
  - `post_publish_rendered`: `28` item groups / `84` rows, all `pass 100`, no rendered review rows;
  - `publish_gate_shadow`: `102 pass`, `139 review`;
  - main review blockers are expected shadow classes: `thin_source_dossier`, incomplete language/media payloads, `unsupported_numbers`, generic stock media;
  - remaining `uk_bad_brand_transliteration` examples were in shadow/rejected or thin-source payloads, mostly `Украінска/Українска Правда=>Українська правда`, with some old `Київ Пост=>Kyiv Post`.
- Implemented and deployed preventive guard:
  - worker normalizer now handles `Украінска`, `Украінська`, `Українска`, `Українська Правда` variants via explicit map and regex;
  - PHP quality gate safety-map now handles the same variants;
  - regression test expanded for `Українска Правда`;
  - validation passed: worker `py_compile`, translator regression unittest (`3` tests), PHP lint;
  - live backup before PHP copy: `/root/tmp/europulse-live-backups/20260524-1846/class-epv2-quality-gate.php`;
  - repo/live quality gate diff clean, PHP-FPM reloaded, worker restarted.
- Live smoke:
  - `EPV2_Quality_Gate::repair_rendered_text("<p>Про це повідомляє Українска Правда і Київ Пост.</p>", "uk")`
    returned `<p>Про це повідомляє Українська правда і Kyiv Post.</p>` with `uk_brand_transliteration=true`.
- Next:
  - observe fresh autonomous rows after `2026-05-24 18:46 UTC`;
  - specifically check whether new shadow rows still show `uk_bad_brand_transliteration` for `Ukrainska Pravda` or `Kyiv Post`;
  - rendered layer is currently clean, so do not backfill unless a visible published defect appears;
  - keep hard blocking/review routing disabled until fresh shadow data is clean enough.

## Latest Handoff 2026-05-24 12:42 UTC — Latin proper-name preservation + HTML block spacing deployed

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User clarified the editorial rule: Latin-script proper names, especially publishers/brands like `Kyiv Post`, must not be phonetically rewritten into Cyrillic in Ukrainian copy.
- Fresh post-12:15 audit found one new rendered UK review row:
  - queue `6216`, post `16927`, old audit score `79`;
  - blocker `uk_sentence_glue`;
  - examples `и.Г`, `т.С`;
  - root cause was compact HTML boundaries such as `</p><h2>` and `</h2><p>`, not actual sentence text.
- Implemented in repo:
  - `worker-v21/src/epv2_worker/translator.py`
    - prompt now forbids Cyrillicizing Latin-script publisher/brand/product/organization names;
    - `Kyivpost` / `KyivPost` normalize to `Kyiv Post`;
    - `Тагесспігел` and close variants normalize to `Tagesspiegel` after fresh shadow row `2266`;
    - keep-Latin token list extended for common publishers/brands/products;
    - Ukrainian normalizer inserts blank-line separators between `</p><h2>`, `</h2><p>`, and `</p><p>`;
  - `wp-plugins/europulse-autopilot-v21/includes/quality/class-epv2-quality-gate.php`
    - rendered repair adds the same HTML block spacing for UK posts;
    - UK brand map covers more `Kyiv Post` Cyrillic variants;
  - `worker-v21/tests/test_translator_quality_regressions.py`
    - regression now covers `Kyivpost -> Kyiv Post` and block spacing.
- Validation passed:
  - `python3 -m py_compile worker-v21/src/epv2_worker/translator.py`;
  - `PYTHONPATH=worker-v21/src worker-v21/.venv/bin/python -m unittest worker-v21/tests/test_translator_quality_regressions.py` passed (`3` tests);
  - repo and live PHP lint passed for `class-epv2-quality-gate.php`.
- Deployed live:
  - backup before copy: `/root/tmp/europulse-live-backups/20260524-1240/class-epv2-quality-gate.php`;
  - repo/live diff clean;
  - `php8.3-fpm` reloaded;
  - `epv2-worker` restarted at `2026-05-24 12:39 UTC` for the main translator changes, and again at `12:45 UTC` after the `Tagesspiegel` worker guard.
- Smoke/health:
  - worker `/health` OK after latest restart: RSS about `100.3M`, no recycle scheduled;
  - public IP returned `200 OK`;
  - live repair smoke converted `<p>Kyiv Post...</p><h2>...</h2><p>Киівпост...</p>` to separated blocks and `Kyiv Post`.
- Fresh autonomous proof after the restart:
  - queue `6213` published; posts `16956/16957/16958` all have `post_publish_rendered pass 100`;
  - queue `6220` produced `publish_gate_shadow pass 96`;
  - queue `6214` terminal review/reject was expected, with context `worker_terminal_outcome`, `thin_source_dossier`, missing translations/media, and no UK brand/glue defect.
- Targeted backfill:
  - backup table `ep_epv2_post_repair_backup_20260524_html_block_spacing_quality` with `3` rows;
  - repaired posts `16926`, `16927`, `16928` only, changing `post_content` but not `post_modified`;
  - compact block tags count is now `0`;
  - latest audit rows for `16926/16927/16928` are `post_publish_rendered pass 100`;
  - `16927` latest UK signals: `sentence_glue_count=0`, `placeholder_link_count=0`, `broken_transliterated_url_count=0`, `bad_brand_count=0`.
- Current queue snapshot after checks:
  - after the 12:45 restart and fresh publish: `new=6`, `ready_publish=2`, `published=197`, `rejected=35` split across stages.
- Active alerts option empty; automation/collect remain enabled.
- Next:
  - do not manually force `collect`, `process`, or `publish`;
  - observe fresh autonomous rows after `2026-05-24 12:42 UTC`;
  - recalculate UK repair warning rate after a larger sample;
  - continue upstream proper-name preservation if new Latin names are Cyrillicized;
  - keep hard blocking/review routing disabled until fresh shadow/rendered data is clean.

## Latest Handoff 2026-05-24 12:15 UTC — fresh cycle active, placeholder-anchor repair deployed

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User asked what is next by plan while checking plugin/site state.
- Read-only status:
  - `europulse-autopilot-v21` is active;
  - worker `/health` OK, RSS about `226.5M`, no recycle scheduled;
  - orchestrator active and autonomously processing;
  - public IP returned `200 OK`;
  - automation/collect enabled, active alerts option empty.
- Fresh data after `2026-05-24 00:24:00`:
  - `33` item groups / `99` rendered rows;
  - UK repair warnings down to `4/33 = 12.1%`, still above the roughly `5%` target;
  - broken transliterated URLs and bad source-name forms remained `0`;
  - found `2` placeholder cases inside anchor text, e.g. `(<a ...>посилання на статтю</a>)`.
- Implemented/deployed:
  - `EPV2_Quality_Gate` now detects and repairs placeholder anchor parentheticals;
  - backup before copy: `/root/tmp/europulse-live-backups/20260524-1212/class-epv2-quality-gate.php`;
  - repo/live diff clean; live PHP lint passed; PHP-FPM reloaded.
- Targeted backfill:
  - backup table `ep_epv2_post_repair_backup_20260524_placeholder_anchor_quality`;
  - repaired `2` posts;
  - final counters: `0` broken URLs, `0` bad source names, `0` sentence glue, `0` placeholders.
- Current queue:
  - `new=20`, `ready_publish=1`, `published=193`, `rejected=37`, `active_processing=21`.
- Next:
  - do not manually force jobs;
  - observe autonomous drain/publish after `2026-05-24 12:15 UTC`;
  - recalculate UK repair rate on post-12:15 rows;
  - if still above roughly `5%`, continue upstream prevention before Phase 3;
  - hard blocking/review routing remains disabled.

## Latest Handoff 2026-05-24 00:24 UTC — UK broken URL/source prevention deployed, targeted backfill clean

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User asked to keep working by plan without more confirmations, avoid breaking working runtime, and write memory for the next LLM before context limits.
- Runtime audit showed the 2026-05-23 safety net was still overactive:
  - after `2026-05-23 16:15:00`, all `22` new UK posts had `post_publish_rendered` repair warnings;
  - new visible defect class found: transliterated URLs like `гттп://204.168...` plus source/product names such as `Дойтшландфунк`, `Süddeutsche Цайтунг`, `ПроСібен`, `МагентаСпорт`.
- Implemented in repo:
  - `worker-v21/src/epv2_worker/translator.py`
    - preserves HTML tags and real `http(s)://` URLs before hybrid Latin/Cyrillic repair;
    - strips already-broken transliterated URLs from UK text;
    - extends UK source/product normalization for `Deutschlandfunk`, `Süddeutsche Zeitung`, `24tv`, `MagentaSport`, `ProSieben`;
  - `includes/quality/class-epv2-quality-gate.php`
    - added `broken_transliterated_url` detect/repair;
    - extended the UK source/product preservation map;
  - `worker-v21/tests/test_translator_quality_regressions.py`;
  - `worker-v21/tests/fixtures/quality/uk_rendered_repair_observed.json`.
- Validation:
  - `python3 -m py_compile` passed for changed worker files;
  - `PYTHONPATH=worker-v21/src worker-v21/.venv/bin/python -m unittest worker-v21/tests/test_translator_quality_regressions.py` passed (`2` tests);
  - PHP lint passed for changed PHP files;
  - live smoke of `EPV2_Quality_Gate::repair_rendered_text()` repaired a real broken sample and set the expected repair flags.
- Deployed live:
  - backed up live quality gate under `/root/tmp/europulse-live-backups/20260524-002051/`;
  - copied repo `class-epv2-quality-gate.php` to live plugin and confirmed repo/live diff clean;
  - reloaded `php8.3-fpm`;
  - restarted `epv2-worker` while queue was idle so translator fixes loaded.
- Targeted backfill:
  - backup table `ep_epv2_post_repair_backup_20260524_broken_url_quality` with `18` rows;
  - repaired `18` published posts via `/tmp/epv2_targeted_rendered_repair_20260524.php`;
  - repair counts: `broken_transliterated_url=13`, `uk_brand_transliteration=7`;
  - final counters: `0` broken transliterated URLs, `0` bad source-name forms, `0` sentence glue, `0` placeholders.
- Service state after deploy/backfill:
  - worker `/health` OK, RSS about `100.5M`, no recycle scheduled;
  - `epv2-orchestrator` active;
  - public site IP returned `200 OK`;
  - queue idle: `published=182`, `rejected=10`, `active_processing=0`.
- Next work:
  - observe the next fresh autonomous cycle after `2026-05-24 00:24 UTC`;
  - verify UK repair warnings drop below roughly `5%`;
  - do not enable hard blocking/review routing yet;
  - duplicate-story publishing remains separate work.

## Latest Handoff 2026-05-23 16:15 UTC — lightweight rendered repair deployed live, fresh UK defects backfilled

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; latest section there overrides older notes.
- User explicitly approved production deployment/backfill.
- Deployed live:
  - `includes/publish/class-epv2-post-audit.php`
    - added `repair_rendered_text_after_publish()` for lightweight title/excerpt/content repair plus `post_publish_rendered` recording;
  - `includes/publish/class-epv2-publisher.php`
    - auto-mode now runs the lightweight rendered text audit when heavy post-publish audit is skipped;
  - `includes/quality/class-epv2-quality-gate.php`
    - extended observed UK brand/outlet preservation map for `Киівпост`, `Украінска Правда`, `Тагесспігел`, `Дойтше Велле`, `Фінанке.уа`, `Поліке Аукс Фронтіèрес`.
- Backups:
  - live file backups under `/root/tmp/europulse-live-backups/20260523-160723/`;
  - DB backup table `ep_epv2_post_repair_backup_20260523_uk_quality`.
- Validation:
  - repo and live `php -l` passed;
  - PHP-FPM reloaded;
  - public site IP returned `200 OK`;
  - worker `/health` OK, RSS about `233M`, no recycle scheduled.
- Targeted backfill result for UK posts after `2026-05-22 23:21:00`:
  - `44` UK posts checked;
  - final counters: `0` sentence-glue SQL hits, `0` placeholder markers, `0` observed bad outlet/brand forms.
- New autonomous proof:
  - queue item `5923` published after deploy at `2026-05-23 16:09:33` as posts `16280/16281/16282`;
  - automatic `post_publish_rendered` rows appeared at `16:09:33`;
  - UK post `16281` was repaired and passed.
- Current queue is active after a fresh collect, not idle: `21` `new` rows at last check.
- Next work:
  - observe next autonomous publishes without manual collect/process/publish;
  - fix upstream worker translator/prompt prevention for UK spacing/placeholders/outlet names;
  - then handle duplicate-story publishing, observed with Denmark/Frederiksen from Tagesspiegel and SPIEGEL.

## Latest Handoff 2026-05-22 23:21 UTC — UK rendered audit/repair deployed, shadow still non-blocking

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; update there should override older sections below.
- Exact next-session runbook was written at `/root/projects/europulse/docs/NEXT_SESSION_RUNBOOK_2026_05_22.md`.
- Any new LLM should read that runbook before touching code or running non-read-only actions.
- User asked to proceed after the 24h shadow observation found real published-quality issues.
- Kept the important rule: no Phase 3 hard blocking was enabled yet.
- Implemented and deployed live:
  - `includes/quality/class-epv2-quality-gate.php`
    - added rendered-text evaluation/recording for `post_publish_rendered`;
    - added UK checks for sentence glue, placeholder link text, and bad brand transliteration;
    - added safe UK rendered text repair for spacing, `(посилання)` placeholders, and known brand preservation;
    - softened `thin_source_dossier` in shadow when publish gate already allowed the item and the source context is not egregiously thin;
    - softened single `unsupported_numbers` in lower-risk contexts.
  - `includes/publish/class-epv2-post-audit.php`
    - after publish, repairs title/excerpt/content with the safe rendered text repair;
    - records `post_publish_rendered` rows in `ep_epv2_quality_audit`.
  - `includes/ai/class-epv2-ai-response-validator.php`
    - strips URLs and IPv4-like hosts before invented-number detection, fixing false positives from internal links such as `204.168.148.47`.
- Live backfill completed for the last 24h UK posts:
  - backup table: `ep_epv2_post_repair_backup_20260522_uk_quality`;
  - checked `70` Ukrainian posts;
  - first pass changed `67`: `uk_sentence_glue=67`, `uk_brand_transliteration=27`, `placeholder_link_text=7`;
  - second pass changed `9`: `uk_sentence_glue=3`, `uk_brand_transliteration=6`;
  - final checks: `0` remaining sentence-glue regex hits, `0` remaining `(посилання)` placeholders, `0` hits for observed bad brand forms (`ОйроПулсе`, `Ваимо`, `Гайсе`, `ТехКрунх`, `24тв`, `Багн.де`, `Лінукс`, `Голем`).
- Validation:
  - repo and live `php -l` passed for the three changed PHP files;
  - live smoke test detected `15763` before repair as `uk_sentence_glue + placeholder_link_text + uk_bad_brand_transliteration`;
  - sample repair produced `EuroPulse`, `Waymo`, `TechCrunch` and inserted missing sentence space;
  - queue `5702` URL/IP invented-number false positive now returns no invented numbers;
  - `post_publish_rendered` audit rows recorded: latest hour showed `79` pass rows, avg score `95.8`;
  - worker `/health` OK (`rss_mb=229.2`, no recycle scheduled);
  - PHP-FPM active, `slow=0`;
  - public site IP returned `200 OK`.
- Continue from here:
  - first run the read-only service/queue/quality checks listed in `docs/NEXT_SESSION_RUNBOOK_2026_05_22.md`;
  - keep observing `publish_gate_shadow` and now `post_publish_rendered`;
  - do not enable hard blocking until `thin_source_dossier` and `unsupported_numbers` are re-reviewed after the calibration;
  - if more bad brand transliterations appear, extend the preservation map cautiously with observed concrete forms only;
  - next implementation candidate is worker prompt/translator prevention so post-audit repairs become rare, not the primary safety net.

## Latest Handoff 2026-05-21 20:06 UTC — autonomous hardening Phase 1/2 shadow deployed

- First file remains `/root/projects/europulse/LLM_START_HERE.md`; it now includes this checkpoint.
- User asked to continue with the new `2026-05-21` hardening plan, carefully and phase-by-phase.
- What we are doing:
  - building a quality/fact/source/cache hardening layer around the existing autonomous pipeline;
  - first collecting shadow evidence, then enabling review/blocking only where the data proves low false-positive risk.
- Expected result:
  - autonomous publishing remains live;
  - quality problems become visible in `ep_epv2_quality_audit` and admin page `Качество`;
  - after shadow observation, hard blockers can route risky items away from autonomous publishing without disrupting good items.
- Implemented and deployed only the safe audit/shadow foundation:
  - `includes/metrics/class-epv2-quality-audit.php`
  - `includes/quality/class-epv2-quality-gate.php`
  - `includes/bootstrap.php` autoload entries
  - `includes/core/class-epv2-installer.php` schema for `epv2_quality_audit`
  - `includes/publish/class-epv2-publish-gate.php` shadow call
  - `includes/admin/class-epv2-admin.php` submenu/page `Качество`
- Live schema upgrade created `ep_epv2_quality_audit`.
- Validation:
  - repo and live PHP lint passed for changed files;
  - live class autoload check returned `EPV2_Quality_Audit=true`, `EPV2_Quality_Gate=true`;
  - audit write/delete smoke test passed;
  - direct read-only gate check on latest published row `5338` / post `14977` returned `verdict=pass`, `score=92`;
  - seed-only payloads were excluded from shadow recording after a noisy first audit row showed they would otherwise pollute the table.
- Behavior remains unchanged:
  - `quality_shadow` is recorded/returned but does not affect `allowed`;
  - no hard blocker review mode is enabled;
  - no worker `/quality_audit`, source trust, clustering, post-publish rendered audit, or cache manager yet.
- No manual `collect`, `process`, or `publish` was triggered.
- Next safe step:
  - observe real `publish_gate_shadow` rows in the new `Качество` admin page / `ep_epv2_quality_audit`;
  - wait for at least `30` real shadow rows or `24h` runtime, whichever is later;
  - analyze top blockers and false positives, especially `unsupported_numbers`, `category_drift`, `thin_source_dossier`, and media/language blockers;
  - only after enough data, implement Phase 3 review-mode routing for confirmed hard blockers;
  - do not start worker `/quality_audit` before the shadow false-positive profile is known.
- Useful checks:
  - `sudo -u www-data wp db query "SELECT verdict, COUNT(*) c, ROUND(AVG(score),1) avg_score FROM ep_epv2_quality_audit GROUP BY verdict; SELECT blockers, COUNT(*) c FROM ep_epv2_quality_audit GROUP BY blockers ORDER BY c DESC LIMIT 10;" --path=/var/www/europulse/public`
  - `sudo -u www-data wp db query "SELECT id,queue_id,post_id,source_id,phase,verdict,score,LEFT(blockers,260) blockers,LEFT(warnings,260) warnings,created_at FROM ep_epv2_quality_audit ORDER BY id DESC LIMIT 30;" --path=/var/www/europulse/public`

## Latest Handoff 2026-05-20 09:45 UTC — watched collect resumed, rubric/Story Card preservation fixed

- First file for a new LLM session remains `/root/projects/europulse/LLM_START_HERE.md`; it now has the current 09:45 UTC checkpoint and overrides older sections below.
- User request in this pass: keep watching autonomy without manual pushing; also watch rubrication because the latest published item landed in `sport` though it was politics/world.
- Current live operating state:
  - `epv2_automation_paused=0`
  - `epv2_collect_paused=0`
  - next planned collect: `2026-05-20 10:00:00 UTC`
  - queue at `09:43 UTC`: `published=84`, no processable rows yet
  - `epv2-orchestrator` active and heartbeat fresh
  - `epv2-worker` active since `09:31:29 UTC`, `/health` OK on `127.0.0.1:8765`, RSS about `100M`
- Category incident analysis:
  - row `4842` / post `13694` was published under `sport`;
  - input selection was correct: `category_proposed=welt`, `admin_notes.selection.category=welt`;
  - downstream payload drifted: `ai_payload.categories[0]=sport`, `_meta.selection.category=sport`;
  - root cause was global: input `Story_Card_Builder` saved a semantic seed payload, but it lacked `_meta.editorial_prompt_version`; later `drop_stale_payload_version_mismatch` wiped the whole seed, so the worker lost the upfront semantic snapshot before rewrite.
- Code fixes made and deployed live:
  - `worker-v21/src/epv2_worker/pipeline.py`
    - `req.category_proposed` now remains the primary category;
    - local `content_type` category is only a fallback;
    - high-confidence Story Card can still override.
  - `worker-v21/src/epv2_worker/semantic.py`
    - keyword matching uses word boundaries;
    - weak sport words (`match`, `league`, `goal`, `Sieg`, etc.) cannot classify a story as sport without stronger sport evidence.
  - `includes/publish/class-epv2-publish-gate.php`
    - only explicit `_meta.manual_mode` bypasses `selection=low|reject`;
    - automatic `top_story` / `breaking` no longer bypass selection blockers.
  - `includes/queue/class-epv2-queue.php`
    - selection-block bypass is manual-mode only, not priority/top-story.
  - `includes/ai/class-epv2-ai-processor.php`
    - stale editorial payload reset now preserves semantic seed fields: `story_card`, `source_dossier`, `context_memory`;
    - seed-only payloads are not treated as reusable editorial context;
    - preserved semantic seed is merged into the fresh baseline before worker processing.
- Live deployment/validation:
  - PHP files were copied to `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`.
  - PHP-FPM was reloaded.
  - Worker was restarted to pick up Python changes.
  - `python3 -m py_compile` passed for changed worker files.
  - `php -l` passed for changed PHP files, including the live AI processor.
  - Worker semantic test for the Guardian politics title now returns `news`.
  - Live gate check on `4842`: `selection_publishable=false`, `manual_override=false`, blockers `["selection_reject"]`.
  - Reflection smoke test on live AI processor confirmed semantic seed extraction, seed-only detection, and merge work.
- Command-limit note:
  - At about `2026-05-20 09:49 UTC`, additional live `wp eval` and worker `curl /health` checks were rejected by the Codex environment usage limit: retry after `10:43 AM`.
  - Do not infer service downtime from the absence of checks after this point.
  - Local `ps` still showed worker about `103M` RSS and PHP-FPM workers about `94-96M` RSS.
- Continue from here:
  - Do not manually run `collect/process/publish`.
  - Wait for the planned `10:00 UTC` collect.
  - If live command access is still blocked, report the limit and wait for access instead of trying indirect workarounds.
  - If nothing happens after the slot, investigate scheduler/orchestrator, not manual collection.
  - For every new item, compare `admin_notes.selection.category`, `_meta.story_card.category`, `ai_payload.categories`, and final WP categories.
  - Watch resource usage and heartbeat while the first fresh autonomous cycle runs.

## Previous Handoff 2026-05-20 06:01 UTC — v2 selector stall fixed, queue drained, publish proven

- First file for a new LLM session remains `/root/projects/europulse/LLM_START_HERE.md`; it now has the current 06:01 UTC checkpoint and should override older sections below.
- User request in this pass: analyze remaining bottlenecks globally, not as one-off row fixes; identify what is rejected/stuck and why; observe that autonomous process/publish completes without manual publishing.
- Root cause found:
  - v2 selector path used by `next_item_for_processing()` only claimed `new` rows after active-owner recovery.
  - Other observability paths (`bridge_has_processable_items`, legacy selector/fallback, dashboard) could see `retry_process` / stage-resume rows.
  - Result: DB had processable `retry_process` rows, but process runs returned `no_processable_items`.
- Code fixes made and deployed live:
  - `includes/queue/class-epv2-queue.php`
    - added unified v2 workflow claim path for `new`, staged resume, auto resume, and reserve candidates;
    - `has_processable_items()`, `workflow_v2_preview_selection()`, and actual claim now use the same preview logic;
    - `bridge_next_processable_row()` respects `ignore_retry_after`;
    - stage/auto resume filters now also call `item_is_processable_read_only()`;
    - chronic recycler terminal markers block selector pickup;
    - chronic recycler terminal rows cannot be revived into non-terminal states by later `mark_state()` races;
    - chronic recycler rejection clears the processable transient and active pointer.
  - `includes/api/class-epv2-rest.php`, `includes/jobs/class-epv2-jobs.php`, `includes/core/class-epv2-cli-commands.php`
    - maintenance now runs `repair_persisted_retry_process_stage_contract()` and `repair_persisted_publish_finish_translation_contract()`.
- Live deployment/validation:
  - Changed PHP files were copied to `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`.
  - Repo and live `php -l` passed for queue, REST, jobs, and CLI command files.
  - `php8.3-fpm` was reloaded after deployment.
- Autonomous proof after fix:
  - maintenance at `2026-05-20 05:52:56 UTC` repaired `4843`, `4844`, `4845` from stale `rebuild_bundle` stage to required `translate_uk`.
  - process run `39912` processed `4842` and queued `publish_finish`.
  - process run `39913` processed `4829`; worker terminalized it to review because plagiarism gate failed DE uniqueness (`61.1% < 85%`).
  - maintenance at `2026-05-20 05:57:55 UTC` auto-promoted/cleaned the review row according to auto-mode policy.
  - publish run `39914` published `4842` at `2026-05-20 05:58:00 UTC`, post `13694`.
- Final live queue at `2026-05-20 06:00 UTC`:
  - `published=84`, `rejected=4`.
  - `4829`: rejected by auto review policy after worker plagiarism blocker.
  - `4842`: published, post `13694`.
  - `4843`: rejected, `manual_confirmation_required=worker_blockers`.
  - `4844`, `4845`: rejected as stale TTL (`hard_editorial — TTL exceeded`).
  - `workflow_v2_preview_selection(false)` returns `mode=none`.
  - `has_processable_items()` returns `false`.
- Resource state at final check:
  - worker `/health`: `status=ok`, `rss_mb=182.4`, `request_count=30`, `recycle_scheduled=false`.
  - orchestrator active, about `21.8M` RSS.
  - PHP-FPM active, `slow=0`, about `106.9M` memory.
  - Frontend/admin local HTTP checks returned expected redirects; no current `500/502`.
- Continue with collection still paused. Do not enable regular collect unless the user asks for a watched fresh-content test.

## Latest Handoff 2026-05-20 00:06 UTC — Worker memory fixed, false rejects repaired, autonomous publish proven

- First file for a new LLM session remains `/root/projects/europulse/LLM_START_HERE.md`. It has the current state and should override older sections below.
- User request in this pass: investigate what is stuck/rejected and why, keep observing, and make publish-grade items traverse the autonomous path without manual publishing.
- Critical incident found after the earlier controlled restart:
  - `epv2-worker` had grown to about `669M RSS` and `127M swap`; `/health` timed out.
  - Orchestrator kept trying worker-owned stages, causing false chronic recycler / ready_review rejections.
  - Automation was paused briefly, worker was killed/restarted, and then code fixes were deployed before requeueing false rejects.
- Code fixes made and deployed:
  - `worker-v21/src/epv2_worker/server.py`: `/health` exposes `rss_mb`, `request_count`, `recycle_scheduled`; worker now self-recycles by RSS/request thresholds and has a background RSS monitor.
  - `worker-v21/src/epv2_worker/rewriter.py`: large spaCy DE NER disabled by default; fabricated-name fallback no longer hard-blocks capitalized phrase pairs without spaCy; DE anti-plagiarism retry added.
  - `worker-v21/src/epv2_worker/translator.py`: large spaCy UK NER disabled by default; Ukrainian filler/style warnings are soft; English German-output and translation failures are retried.
  - `worker-v21/epv2_bridge_orchestrator.py`: worker `/health` guard before collect, breaking scan, process, and handoff, with cooldown on unhealthy worker.
  - `includes/core/class-epv2-worker-client.php`: shorter worker availability cache, forced health ping before `/process`, lower HTTP timeout caps.
  - `includes/ai/class-epv2-story-card-builder.php`: worker availability cache invalidates on `/analyze_story` failures.
  - `includes/queue/class-epv2-queue.php`: chronic recycler excludes infrastructure failures; stage-attempt quarantine messages include publish-gate blockers.
  - `includes/publish/class-epv2-publish-gate.php`: explicit `thin_source_dossier` blocker.
  - `includes/ai/class-epv2-ai-processor.php`: `translation failed: All providers failed` is treated as a provider/technical retry; rebuild short-circuit `selection=low/reject` is rescued for strong ingest/story-card rows.
- Live deploy/validation:
  - Changed PHP files were copied to `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`; live `php -l` passed.
  - Worker restarted after Python changes. Last successful worker health before approval limit: OK, RSS about `172.6M`, request count `6`, no recycle scheduled.
  - Publisher proved live: `4838` published autonomously at `2026-05-19 23:59:23 UTC` as post `13666`; `4831` published at `2026-05-20 00:05:00 UTC` as post `13674`.
- Queue false-reject recovery:
  - Requeued `4825` and `4826` after anti-plagiarism retry fix.
  - Requeued `4833` after selection-drift short-circuit fix.
  - Requeued `4837` after translation/provider failure retry fix.
  - Left `4830` rejected intentionally: source dossier is genuinely too thin (one-sentence primary content plus empty supporting URLs). It should not be auto-published without better source content.
  - `4839` was autonomously rejected as `selection=low`, score `40` vs threshold `41`; this is an editorial gate, not a resource/stuck issue.
- Latest observed live queue at `2026-05-20 00:05 UTC`:
  - `published=82`, `ready_publish=1`, `new=13`, `retry_process=5`, `rejected=2`.
  - `4832` was `ready_publish`, due at `2026-05-20 00:09:50 UTC`.
  - `4825/4826/4833/4837` are back in `new/rebuild_bundle`.
  - `4829/4842/4843/4844/4845` remain `retry_process/rebuild_bundle` and need observation.
- Important limitation:
  - After the `00:05` checks, escalated live commands started failing with the environment's approval/usage limit. Do not work around rejected live commands. Continue when approvals are available again, or ask the user to renew/approve live access.
- Next concrete commands when live access is available:
  - `systemctl status epv2-worker epv2-orchestrator --no-pager -l`
  - `curl -s --max-time 10 http://127.0.0.1:8765/health`
  - `sudo -u www-data wp db query "SELECT state, COUNT(*) c FROM ep_epv2_queue GROUP BY state ORDER BY state; SELECT id,state,pipeline_stage,post_id,updated_at,LEFT(error_message,260) error_message FROM ep_epv2_queue WHERE id BETWEEN 4825 AND 4847 ORDER BY id;" --path=/var/www/europulse/public`
  - `sudo -u www-data wp db query "SELECT id,job_name,status,item_count,error_count,started_at,finished_at,LEFT(payload,1500) payload FROM ep_epv2_runs WHERE id>=39600 ORDER BY id DESC LIMIT 40;" --path=/var/www/europulse/public`

## Latest Handoff 2026-05-19 21:46 UTC — Controlled restart, stale queue clear, memory/resource guards

- First file for a new LLM session remains `/root/projects/europulse/LLM_START_HERE.md`. It has the current state and should override older handoff sections below.
- User's current operational request: site became slow/unresponsive when autonomous work was enabled; dashboard showed `Publisher inactive` and no orchestrator heartbeat for ~94779 seconds. User asked to check `epv2-orchestrator`, clear old queue so stale news cannot publish, and start automation slowly under control.
- Root causes found in this pass:
  - Old `epv2-worker` previously reached `1.0G` memory peak and `567M` swap peak while repeatedly hitting OpenAI quota/rate failures.
  - `breaking_scan` was running via REST/PHP-FPM and slowlog showed it stuck in Google News `curl_exec()`, which can exhaust PHP-FPM children and make the site stall.
  - Broken source `id=11` (`muenchen.de Rathaus Umschau`, `http://www.muenchen.info/pia/RSS/RSS.xml`) returns `text/html`, not an RSS feed, causing repeated breaking source failures.
  - The dashboard `ready_publish` stall warning was stale/no longer matched DB state; DB had old active rows from `2026-05-18 19:00-19:04 UTC`.
- Code changes made in repo and deployed where needed:
  - `worker-v21/epv2_bridge_orchestrator.py`: breaking scan now runs by WP-CLI instead of REST/FPM; it has a timeout, kills its process group on timeout, recovers collect locks, respects `collect_paused`, and uses memory preflight guards for process/collect/breaking/publish paths.
  - `wp-plugins/europulse-autopilot-v21/includes/ingest/class-epv2-collector.php`: `run_breaking_scan()` returns `['skipped'=>'collect_paused']` when collection is paused. This was copied to the live plugin and smoke-tested with `{"skipped":"collect_paused"}`.
  - `worker-v21/worker-setup.sh` and `worker-v21/orchestrator-setup.sh`: systemd resource/restart guard defaults added.
  - New repo drop-ins under `ops/systemd/`: worker, orchestrator, and bot restart/resource guards.
- Live resource guards applied:
  - `epv2-worker`: `MemoryHigh=640M`, `MemoryMax=768M`, `MemorySwapMax=128M`, `CPUQuota=80%`, `TasksMax=64`, `RestartSec=60`, `StartLimitBurst=2/15min`, lower priority.
  - `epv2-orchestrator`: `MemoryHigh=384M`, `MemoryMax=512M`, `MemorySwapMax=128M`, `CPUQuota=60%`, `TasksMax=64`, `RestartSec=60`, `StartLimitBurst=2/15min`.
  - `epv2-bot`: `MemoryHigh=64M`, `MemoryMax=128M`, `MemorySwapMax=32M`, `CPUQuota=20%`, `TasksMax=32`.
- Host cleanup completed:
  - Stuck `msmtp/apparmor` `dpkg` prompt resolved non-interactively.
  - `dpkg --audit` clean; `apt-get check` OK.
  - Swap cleaned from about `1.1G` used to about `7M`.
  - Old detached `tmux` session `europulse` with two stale `claude` processes using about `1.4G` RSS was stopped.
- Queue/source cleanup before restart:
  - Before cleanup: `new=15`, `ready_review=1`, no live `ready_publish`.
  - Backup table created: `ep_epv2_queue_backup_pre_controlled_restart_20260519` with `16` stale active rows.
  - The 16 old active rows were marked `rejected` with `stale_queue_cleared_before_controlled_restart` notes so stale news cannot publish.
  - Source `id=11` was disabled with an admin note because its legacy Munich RSS URL returns HTML.
- Controlled restart state:
  - `systemctl start epv2-worker` at `2026-05-19 21:38:20 UTC`; worker is active, about `110M` RSS, `/health` returns OK from host curl.
  - `systemctl start epv2-orchestrator` at `2026-05-19 21:38:48 UTC`; orchestrator is active, about `13M` RSS.
  - `epv2_automation_paused=0`.
  - `epv2_collect_paused=1` intentionally remains set.
  - `epv2_publish_thread_heartbeat` became fresh again at/after `2026-05-19 21:38:49 UTC`; this is the heartbeat used by the dashboard warning.
  - Orchestrator maintenance ran at `2026-05-19 21:39:41 UTC` with `alerts_fired=[]`, then logged `process idle` because there are no processable rows.
- Latest verified live state:
  - `epv2_active_alerts={"checked_at":"2026-05-19T21:46:19Z","ram_pct":56,"disk_pct":52,"automation_paused":false,"collect_paused":true,"alerts":[]}`
  - Queue after the later `21:44:42 UTC` maintenance tick: `published=133` only; no active `new`, `ready_review`, `ready_publish`, `publishing`, `rejected`, or processable rows. The 16 stale active rows were backed up first, marked rejected, and then terminal rows were trimmed by maintenance.
  - Bridge state after restart: `automation_paused=false`, `collect_paused=true`, `worker_available=true`, `has_processable_items=false`, `next_ready_publish=null`, `current_window_mode=wind_down_quiet`, `collect_window_open=false`, `publish_window_open=true`, `has_breaking_watch=false`.
  - Host memory: about `2.1G/3.7G` used, `1.7G` available, swap about `7M/2.0G` used.
  - Front page returns through cache (`X-FastCGI-Cache: HIT`); no lingering healthcheck/WP-CLI process matched the diagnostic pgrep.
- Next safe steps:
  - Do not enable collection while `collect_window_open=false` unless the user explicitly wants a watched one-off collect.
  - Leave the system in this controlled state for another observation window: worker/orchestrator active, publisher heartbeat alive, no queue work, collection paused.
  - If collection is resumed later, first recheck active sources and keep source `id=11` disabled or replace it with a valid RSS feed.
  - Do not restore the cleared stale queue unless explicitly asked; the backup table exists for forensic recovery only.
  - Before committing, review the dirty tree because docs/code/systemd drop-ins were intentionally changed in this pass.

## Latest Handoff 2026-05-18 20:15 UTC — Provider/memory incident repair + LLM entrypoint

- First file for a new LLM session is now `/root/projects/europulse/LLM_START_HERE.md`. It defines read order, current runtime state, and next actions. Read it before older handoff sections.
- Active repo branch: `review/plugin-audit`. Runtime paths remain:
  - repo: `/root/projects/europulse`
  - live WP root: `/var/www/europulse/public`
  - live plugin: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`
  - repo plugin source: `/root/projects/europulse/wp-plugins/europulse-autopilot-v21`
  - worker: `/root/projects/europulse/worker-v21`
- Incident context: worker hit the `1.0G` systemd memory peak and used swap because OpenAI quota was exhausted while the worker still called OpenAI across story_card, embeddings, rewrite, translation, and SEO paths.
- Repair package committed locally:
  - `R9: enforce worker provider cooldowns`
  - `R10: add ops visibility and frontend foundation`
  - `R11: document LLM handoff checkpoint`
- Repair contents:
  - WordPress story-card builder sends `ai_provider`, `ai_model`, `ai_fallback_provider`, `ai_fallback_model` into `/analyze_story`.
  - Worker honors explicit provider order; DeepSeek-primary no longer silently appends OpenAI unless configured.
  - OpenAI embeddings are skipped unless OpenAI is in the provider order.
  - New worker provider cooldown tracks quota/rate/error failures and exposes provider state through `/health`.
  - Rewriter, translator, SEO, story_card, and embeddings now check provider cooldown and record provider failures/successes.
  - `EPV2_Logger::info()` throttles high-frequency REST/process/queue info logs to stop `ep_epv2_log` growth.
  - `ops/epv2-healthcheck.sh` is pause-aware and writes `epv2_active_alerts`.
  - `wp-mu-plugins/europulse-foundation` contains the current frontend foundation, including shared home-pool rendering and short persistent pool cache for the latest/news archives.
- Current live status observed 2026-05-18:
  - `epv2_automation_paused=1`, `epv2_collect_paused=1`
  - `nginx`, `php8.3-fpm`, `mariadb` active
  - `epv2-worker`, `epv2-orchestrator` inactive intentionally while paused
  - active alert: only `swap_high`
  - memory about `2.1G/3.7G` used, `1.6G` available; swap about `1.1G/2.0G` used
- Remaining operational blocker:
  - `apt/dpkg` is stuck on old `msmtp` whiptail prompt (`msmtp/apparmor`, default false). This blocks package maintenance. Next operator/LLM should preseed false and finish `dpkg --configure -a` non-interactively before more apt work.
- Do not unpause automation until package maintenance and swap decision are handled, then restart worker/orchestrator under watch.

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

## Latest Handoff 2026-05-06 17:35 UTC — Autonomous run validation + legal/SEO compliance state

- 24 commits on `review/plugin-audit` since `main`. Last seven: `493766c` (SEO assets + parallel pipeline doc), `4f7da49` (handoff for A–E), `ba66f90` (5 fixes for autonomous-run reject classes), `2f700c7` (handoff for top-tier SEO), `cef440b` (top-tier SEO: schema enricher + news sitemap + rewriter prompt + robots.txt), `24afac4` (handoff for story-card consumed by all stages), `ac08ea8` (story card → media resolver).
- Autonomous run since 10:39 UTC: 30 WP posts published (= 10 queue rows × DE/UK/EN trios). 50-post target NOT reached, but stuck-thin-source mass-rejection pattern is **gone**: post-fix the 21 process runs in 30 min produced **zero** rejected rows, items now route to `ready_review` for triage. State at handoff: `ready_review=145`, `new=78`, `rejected=23`, `published=10`, `error=2`, `publishing=1`. 87 previously mass-rejected rows lifted back to `ready_review` via one-shot WP-CLI eval. The publish-rate ceiling is paywalled feeds (Spiegel/Tagesspiegel/Welt/FAZ premium) — RSS gives 150–200-char excerpts, enricher cannot bypass paywall, worker emits `Primary source too thin for autopublish`, Fix E1 routes to `ready_review` (correct behaviour).
- Story Card now drives every major pipeline stage end-to-end: PHP categorizer override, worker pipeline category + tags + rewriter prompt + media search terms + SEO seed.
- Top-tier SEO infrastructure already live: Schema NewsArticle with articleBody / mentions / about / speakable / contentLocation / isAccessibleForFree / wordCount / thumbnailUrl; news sitemap with `<news:keywords>` + `<image:image>` + Polylang language slugs; robots.txt with explicit allow for GPTBot / ClaudeBot / PerplexityBot / Google-Extended / Gemini Web / Cohere / Diffbot / YouBot / OAI-SearchBot; `/llms.txt`, `/.well-known/security.txt`, `/humans.txt` deployed; rewriter system prompt rewritten for E-E-A-T (inverted pyramid, 40–90-word paragraphs, 2–3 H2 subheadings, anti-AI-tell rules, HTML body output).
- Legal-compliance audit + free-path strategy:
  - All required pages already exist on three languages with proper § 5 DDG / § 18 MStV / Art. 13/14 DSGVO scaffolding, but the placeholders `[Bitte ... eintragen]` have not been filled in yet. Pages: `/impressum/` DE=41 EN=289 UK=288, `/datenschutz/` DE=3, `/ueber-uns/` DE=36 EN=279 UK=278, `/kontakt/` DE=37 EN=281 UK=280, `/korrekturen/` DE=40 EN=287 UK=286, `/cookie-einstellungen/` DE=42, `/nutzungsbedingungen/` DE=43.
  - Plugins: `complianz-gdpr` active but Wizard not run (cookie blocker not configured); `limit-login-attempts-reloaded` active. Two-Factor / Wordfence not yet installed.
  - `epv2_settings['show_ai_disclaimer']` is `false`; DE/UK/EN texts already populated. Toggling on closes Article 50.4 AI Act transparency.
  - `admin_email = admin@europulse.local` is a placeholder; user `1` (europulse_admin) has the same fake email — password recovery impossible until replaced.
  - V.i.S.d.P. is the highest open Abmahnung risk: no real human named anywhere. **Step 1 of the legal-compliance interactive workflow is awaiting the user's V.i.S.d.P. answer (name + postal address + email).**
  - Operator chose Variant A (free path): Ukrainian ТОВ + free EU contact via `privacy@europulse.eu` + future appointment of EU-Representative when traffic justifies. Cost ~70 EUR/year (domain + Hetzner).
- Open question to the operator before next session can move: V.i.S.d.P. (full latinised name, postal address, email). Once answered, fill `/impressum/` DE+EN+UK in one shot, then iterate Datenschutz / Über uns / Kontakt / Korrekturen / Editorial Guidelines.
- Recommendation pending operator approval: disable paywalled feeds (Spiegel, Tagesspiegel, Welt, FAZ premium) — they pollute the queue with un-publishable items (paywall blocks enricher). Keep only feeds with full RSS body. This restores >90 % publish rate.

## Latest Handoff 2026-05-06 16:55 UTC — Five fixes for autonomous-run reject classes (A-E)

- First autonomous run (10:39–16:50 UTC, ~6h) published 10 queue rows = 30 WP posts (DE/UK/EN trios) but mass-rejected ~85 mainstream news items. Audit traced every rejection to one of five distinct false-positive classes; commit `ba66f90` ships fixes for all five.
- A. `core_geo` whitelist expanded to include Iran/Israel/Hormuz/China/Taiwan/Middle East/NATO partners/G7/WHO/IMF; `us_local` regex narrowed to actual local-US patterns (school district, state senate race, redistricting). Stops Trump-Hormuz / FDA-vaccines / Israel-Iran stories getting hard-blocked at ingest.
- B. `biotech_pr` regex narrowed to true investor-PR markers (`reports positive Phase X`, `topline results`, etc.) so generic FDA / Therapeutics mentions in regulatory news survive.
- C. `candidate_is_fresh_enough()` now grants the 18h freshness window to any heavyweight category (politik / welt / ukraine / wirtschaft / deutschland / bayern / muenchen) at score >= 30 and decision in {review, strong, priority}, not just at score >= 44. 12-17h-old yesterday-evening politik / welt pieces from Tagesschau / NDR / FAZ no longer get freshness-blocked.
- D. `uplift_borderline_newsworthy_score()` adds named-politician / state-leader pattern (Merz / Scholz / Trump / Putin / Zelensky / Macron / Erdogan / Netanyahu / Xi / von der Leyen / ...) as an additional `meaningful_signal`. Score-30 named-leader stories now uplift to C/review.
- E. Two stuck-publish fixes:
  - `process_scheduled` worker-blocker terminalization: when worker's only blocker is "Primary source too thin for autopublish" AND `item.story_score >= 40` OR `story_card.publishable_estimate in [high,medium]`, route to ready_review instead of rejecting. Paywalled Spiegel / FAZ-premium / NDR-live-ticker no longer poison the queue.
  - `Publisher::preflight_shared_publish_media_url()`: last-resort `generated_story_cover()` instead of throwing "у DE-версии не установлено featured image"; stops the whole publish run from stalling on one missing image.
- Backfill: 87 rows that had been mass-rejected with the only-thin-blocker pattern were lifted back to ready_review by a one-shot WP-CLI eval; operator can salvage.
- Live state at handoff: 133 ready_review, 93 new, 21 rejected, 10 published, 1 publishing. Both pause flags OFF. Orchestrator active. Worker /health=ok.
- Re-run scheduled: orchestrator will publish more queue rows under the new gate; wakeup in 30 min checks ≥50 published WP posts and confirms thin-source stall is gone.

## Latest Handoff 2026-05-06 10:40 UTC — Top-tier SEO + LLM-friendly schema

- Site is now optimized for Google News, Google Discover, and AI search agents (Perplexity, ChatGPT, Claude, Gemini Web).
- New `EPV2_Schema_Enricher` (commit `cef440b`) hooks into Rank Math's `rank_math/json_ld` filter and adds: `articleBody`, `wordCount`, `keywords` (story_card.tags + post tags), `mentions [Person|Organization|Place]` from card.entities, `about [Thing]` from card.topics, `contentLocation`, `isAccessibleForFree=true`, `speakable`, `thumbnailUrl`. Verified live on post 6680: `wordCount=451`, full `articleBody`, `isAccessibleForFree`, `speakable` now in JSON-LD; future story-card-aware posts will additionally carry `mentions`, `about`, `contentLocation`.
- News sitemap improved: per-post Polylang language slug, `<news:keywords>` from card.tags, `<image:image>/<image:loc>` per URL. Image namespace declared on the urlset.
- Rewriter system prompt rewritten for E-E-A-T: inverted pyramid lead, short paragraphs (40–90 words), 2–3 H2 subheadings on longer pieces, explicit anti-AI-tells (no em-dash spam, no "im digitalen Zeitalter", no rhetorical-question leads), HTML body output (<p>/<h2>), title 50–80 chars with primary keyword early. Validated on row 1168 (Bayer/Curevac/Gamestop multi-fact piece): 113-char title, 264-char lead with Wie-n-tv attribution, 2541-char body with `<p>` tags only, **zero em-dashes**, real money values preserved.
- Worker SEO stage now consumes story_card.seo (primary_keyword mandatory, secondary_keywords, tag_pool, title_pattern_hint).
- robots.txt rewritten: explicit Allow for Googlebot-News/Image, Bingbot, Applebot, Yandex, DuckDuckGo plus all major AI agents (GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, anthropic-ai, Claude-Web, PerplexityBot, Perplexity-User, YouBot, Diffbot, Google-Extended, meta-externalagent, cohere-ai). Explicit Disallow for Bytespider, MJ12bot, DotBot, BLEXBot. Crawl-delay 30 for Ahrefs/Semrush. Sitemap + News-sitemap entries.
- All hooks live: 22 commits on `review/plugin-audit` since main.

## Latest Handoff 2026-05-06 10:15 UTC — Story Card consumed by all major stages

- Story Card now drives every major pipeline stage end-to-end (commits `46a1e5c`, `dec3c8a`, `ac08ea8`):
  - **Categorizer**: PHP-side `EPV2_Categorizer::refine_with_story_card()` and worker-side `_story_card_init` in `pipeline.py` both override the keyword heuristic when `card.category.confidence >= 0.6`.
  - **Tags**: worker `pipeline.py` replaces TF-IDF `key_phrases[:5]` stub with curated `card.tags` (clean German nouns vetted by the LLM upfront).
  - **Rewriter**: worker `rewriter.py` injects a "STORY CARD (verbindliche Faktenbasis)" prompt block listing entities (people / orgs / places), key facts, geography, rewrite hints (tone / structure / length). Anchors fact preservation and stops fact drift.
  - **Media (Pexels + Wikimedia)**: `EPV2_Media::pexels_query()` and `wikimedia_query()` first check `$source_dossier['story_card']` and use `card.media_search_terms` directly when present. `EPV2_Publisher` injects `_meta.story_card` into the dossier copy at all three media-resolver call sites.
- Validation row 1178 (UA war story, Ukrainska Pravda source) shows the chain working:
  - card.category = `ukraine` 0.95 (was `deutschland` from heuristic)
  - card.tags = `["Ротація","ЗСУ","Сирський","Фронт","Військові"]`
  - card.media_search_terms = `["Сирський","український військовий на позиції","солдати на передовій"]`
  - card.key_facts[0] = `"Головком ЗСУ Сирський підписав наказ щодо обов'язкової ротації..."`
  - payload.tags inherited from card; payload.categories=["ukraine"]
  - DE title: `"Syrskij unterzeichnete Anordnung zur verpflichtenden Rotation an der Front"` — name transliterated correctly, factual structure matching card.
  - Media: `https://24tv.ua/resources/photos/.../3062439.jpg` — source-host image (24tv.ua), NOT generic Pexels stock. `card.media_required=source_first` respected end-to-end.
- The cruise-ship hantavirus / Phagentherapie / Ein-Jahr-Schwarz-Rot / Helgoland test set all routed correctly; the BMW Pexels-stock regression earlier today should now pull a BMW-on-bild.de image when the card names the company.
- Outstanding wiring (low priority, lower marginal value):
  - SEO stage (`worker-v21/src/epv2_worker/seo.py`) could read `card.seo.primary_keyword` and `card.seo.secondary_keywords` instead of re-deriving from rewritten German.
  - WP-CLI re-categorization sweep over existing `ready_publish` / `ready_review` rows (would only refresh the visible category column for old payloads; doesn't improve future automation).
- Selection-pipeline efficiency from earlier today still holds: noise rejects 96% drop, low-score 46% drop, staged→queued conversion 6%→94%, 32 active sources curated for top-tier German + Ukrainian outlets.

## Latest Handoff 2026-05-06 09:50 UTC — Story Card upfront semantic pass

- Implemented the user's "карта новости" architecture as a first-class upfront stage. One AI pass per fresh row produces a structured `_meta.story_card` (commit `46a1e5c`):
  - **Worker side** (`worker-v21/src/epv2_worker/story_card.py` + `/analyze_story` endpoint in `server.py`): single JSON-mode call (gpt-5-mini primary, deepseek-chat fallback) returns `{category, geography, entities (people/orgs/places), key_facts, topics, tags, search_queries, media_search_terms, media_required, seo, rewrite hints, publishable_estimate}`. Strict slug whitelist for category. ~7s typical, ≤1200 token response.
  - **PHP wrapper** (`includes/ai/class-epv2-story-card-builder.php`): `build()`, `from_payload()`, `attach_to_payload()`, `category_is_trusted($card, 0.6)`. Calls the worker via `wp_remote_post`, parses with `JSON_THROW_ON_ERROR`, fails gracefully when the worker is offline.
  - **Process integration** in `EPV2_AI_Processor::process_scheduled()`: right after `existing_payload` is decoded, if `_meta.story_card` is missing, build one, persist to ai_payload, and override `category_final` immediately when confidence ≥ 0.6.
  - **Round-trip preservation** in `run_worker_stage()`: worker's `build_normalized_payload` rebuilds `_meta` from scratch; copy `existing_payload._meta.story_card` onto the response so the card is never dropped after a worker call.
  - **Categorizer override** via `EPV2_Categorizer::refine_with_story_card()`. Replaces the keyword heuristic when the card is high-confidence.
- End-to-end verification across heterogenous inputs:
  - Cruise ship hantavirus (BBC EN) → welt 0.95 (was politik)
  - "Ein Jahr Schwarz-Rot" (NDR DE) → politik 0.95
  - Phagentherapie (FAZ DE) → leben-in-deutschland 0.70 (was politik)
  - Russland удар по Дніпру (UA) → ukraine 0.95
  - Helgoland Aufschüttung → deutschland 0.70
- Production tick on row 1178 (UA war story) logged `story_card_built_upfront ukraine/0.95/high` and `category_overridden_by_story_card_upfront deutschland→ukraine`. Card persisted across the subsequent worker rebuild step.
- Wiring for the rest of the consumers is left in place but not yet hooked up — explicit next steps:
  - Worker rewriter (`rewriter.py`) should read `existing_payload._meta.story_card.rewrite` (tone, structure, length) and `key_facts` to guide the German master prompt and prevent fact drift.
  - Worker media (`media.py`) should use `card.media_search_terms` instead of title for image search; respect `card.media_required` modes.
  - PHP `EPV2_Media::resolve_featured_media()` should similarly consult the card before falling back to Pexels.
  - Tag normalizer should seed from `card.tags`.
  - SEO stage should seed from `card.seo`.

## Latest Handoff 2026-05-06 09:05 UTC — staged→queued fix, categorizer hardening, 12 publish-ready

- `EPV2_Collector::commit_staged_candidates()` was hard-capping every collect pulse to one row per category via an inner `break` after the first successful ingest, regardless of `max_collect_per_category`. Replaced with `continue` (commit `161a466`). Effect on the next pulse: 231 staged_candidate × 217 queued (was 246 → 11). The collect_limit guard at the top of the loop now does its real job.
- Categorizer hardened (commit `f327a8f`):
  - Extended the 'world' keyword list with country names (Spanien/Niederlande/Frankreich/Italien/Türkei/Romania/Korea/Indonesien/Belarus/Kuba/etc.), pandemic & disease hooks (hantavirus, ebola, cholera, pandemie, outbreak), travel/global hooks (Kreuzfahrtschiff/cruise ship, internationale Gewässer), and supranational signals (G7/G20/OPEC/WHO/UNESCO/Weltbank).
  - Last-resort fallback no longer blindly returns `'deutschland'`. If source bias is `'europa'` or `'welt'` it now uses that, so BBC / Reuters / DW international stories don't land in a domestic-Germany rubric.
  - Verified: cruise-ship hantavirus, Romania PM confidence-vote, Iran-US, China-fireworks all now route to `welt`. Bayern-Hymnenpflicht → `bayern` (was `deutschland`). German inflation still → `deutschland`.
- Worker-blocker terminalization hoisted to all stages (commit `4602acc`). Three stuck rows triaged (`1138`/`1140`/`1141`).
- Per-category uplift floor lowered from 34 → 30 for politik/welt/ukraine/wirtschaft/deutschland (same commit). Score 30-33 strong political signal → C/review instead of D/reject.
- 12 ready_publish across pulses, all with publish-grade DE/UK/EN bodies (1.0–3.2 KB), correct source attribution, real source-domain media. Sample quality:
  - `1155` BBC Ukrainian: "Понад 20 загиблих..." accurate factual translation, 946/963/990 DE/UK/EN.
  - `1157` FAZ: Phagentherapie at Frankfurt clinic, 3198 DE chars, native UK with proper medical terminology.
  - `1160` Tagesschau: Leipzig Amokfahrer, 2558 DE chars, accurate UK rendering.
  - `1163` BILD: BMW Quartalsgewinn -23%, 2294 DE chars.
- Remaining quality issues:
  - Old payloads still carry mis-categorizations (1157 Phagentherapie → politik, 1160 Leipzig crime → politik). New categorizer fixes only apply to new pulse runs; legacy payloads need a re-categorize sweep or operator manual fix.
  - 1163 BMW used Pexels stock photo. Source-host media (BILD's own image) would be preferred; the media gate fell back to stock when source-image extraction missed.
  - Some `wirtschaft` source bias (Handelsblatt) overcategorizes non-business stories. Watch for next pulse and tighten if it persists.
- Live state: queue 252 rows (204 new from latest pulse + 12 ready_publish + 25 ready_review + 10 rejected + earlier strays). Pause flags ON. AI primary openai/gpt-5-mini, fallback deepseek.

## Latest Handoff 2026-05-06 07:25 UTC — source cleanup + 15 top-tier feeds added

- Worker-blocker terminalization hoisted (commit `4602acc`): publish_finish / translate_uk / translate_en / translate_finish now terminalize to ready_review when worker returns blockers or `outcome=ready_review`. Previously only rebuild_bundle did this; rows like `1138`/`1140`/`1141` were cycling on 30-min cooldowns instead of moving to manual review. Triaged the three stuck rows manually to ready_review.
- `EPV2_Budget_Manager::uplift_borderline_newsworthy_score` now fires from score 30 (was 34) for politik / welt / ukraine / wirtschaft / deutschland; lighter categories keep the 34 floor. Strong political signal at 30-33 now lands as C/review instead of D/reject.
- **Source cleanup**. Sources table backup `backups/sources-pre-cleanup-20260506-071622.sql`. Disabled 37 consistently-broken feeds (0 queued vs 50+ rejected across the full audit window 2026-04-28..2026-05-06): all narrow Google News searches that were either redundant with Tagesschau/BR24 or returned archive matches; institutional German feeds that produce only protocol/press-release content (BAMF, BMAS, IW Köln, Bundesbank, Deutscher Bundestag); slow Munich service feeds (S-Bahn, MVG, Deutsche Bahn, Rathaus Umschau); zero-signal community/diaspora feeds; DW Europe/World/Germany EN whose RSS items mostly hit dedup or low_score; RIS München (council recommendations only). Active source count went 54 → 18 active before adding new feeds.
- **Added 15 top-tier feeds**, all RSS, all verified live (HTTP 200 + ≥10 items returned):
  - German (9): ZEIT Online, FAZ Aktuell, SPIEGEL Schlagzeilen, Tagesspiegel, Handelsblatt Top, WELT Topnews, Tagesschau direkt (replaces narrower existing feed), NDR Home, ZDF Nachrichten.
  - Ukrainian (4): Ukrainska Pravda, LIGA.net, BBC Ukrainian, 24tv.ua.
  - English about Europe / Ukraine (2): Kyiv Post, BBC Europe.
  - Skipped because they returned 403/404 from outside the bot whitelist: Hromadske, Suspilne, Kyiv Independent, EPravda, NDR `/nachrichten/` endpoint, BBC German.
- Total active sources now: 22 DE + 5 EN + 6 UK = **33** (was 54 with mostly-broken; now lean and healthier).
- Validation pulse with the new lineup confirmed major lift:
  - Top-staging new feeds: NDR Home (3 queued / 14 staged), Handelsblatt (2/13), BBC Europe (2/7), Ukrainska Pravda (1/15 staged with avg score 46.3), WELT (1/12), ZDF (1/12), FAZ (1/11), 24tv.ua (0/15 staged avg 41.3), LIGA.net (0/15), Kyiv Post (0/14 avg 49.9), Tagesspiegel (0/13).
  - Outcome share for the pulse: 246 staged_candidate, 11 queued, 25 stale-reject (was 324), 18 low_score (was 256), 5 noise (was 136). **Selection-reject share dropped from ~95% to 12.5%**.
  - 11 queued real-news items including `1145` "Ein Jahr Schwarz-Rot: Was haben neue Gesetze den Menschen gebracht?" (score 75 A-tier), `1146` "Aktuelles zum Krieg in der Ukraine" (62), `1147` UA "Окупанти вранці з дрона атакували цивільне авто на Сумщині" (55).
- Live state: queue 35 rows (10 fresh `new` from this pulse + 5 ready_publish + 17 ready_review + 3 from triage). Both pause flags ON.

## Latest Handoff 2026-05-06 07:05 UTC — selection-reject false-positive fixes

- Diagnostic dive into `ep_epv2_selection_audit` exposed two systemic false-positive classes that were rejecting the bulk of legitimate news:
  - `looks_like_noise()` matched the German short token `'abo'` (Abonnement) as a bare substring, hitting English "ab**OUT**" in any English article body — every DW EN headline with the word "about" got noise-rejected with score=0. The same matcher checked short tokens like `'rss'`, `'feed'`, `'jobs'` against the FULL URL string, which matched the analytics token `?maca=en-rss-en-eu-...` carried by every DW article. Net effect: ~26 DW EN articles per pulse silently dropped before scoring, including "Russia offers Ukraine May 8-9 ceasefire" and "Romania's government collapses after PM loses confidence vote".
  - `editorial_interest_weight()` per-category term lists were German-only for politik / welt / ukraine / wirtschaft / deutschland. English-source coverage of Germany ("Difficult first year for Chancellor Friedrich Merz") and English geopolitical pieces ("US destroyer enters Persian Gulf") matched zero terms; the missing +3..+10 was the difference between score 33 (D-tier reject) and score 40+ (C-tier review).
- Commit `12626dd` rewrites both:
  - `looks_like_noise()` lowercases input once; long phrases substring-match body text; short tokens use word-boundary regex (`'abo'`, `'faq'`, `'kongress'`); short URL tokens are checked against `PHP_URL_PATH` only (not the query string); a whole-host blocklist covers `service.bund.de`. Tested across ceasefire / government-collapse / "Bayern Munich match preview" / Wochenarbeitszeit Vollzeit / Pressearchiv inputs.
  - `editorial_interest_weight()` extends politik / welt / ukraine / wirtschaft / deutschland with English equivalents (chancellor, parliament, government, vote, sanctions, ceasefire, named heads of state) and selected Ukrainian equivalents.
- Pre-fix vs post-fix re-scoring of previously rejected items:
  - "Russia offers Ukraine May 8-9 ceasefire": noise / score 0 → tier B / score 54 / strong
  - "Germany: Difficult first year for Chancellor Friedrich Merz": low_score / 33 → tier C / 49 / review
  - "US-Zerstörer in Persischen Golf eingedrungen + Iran-Angriffe auf die Emirate": low_score / 33 → tier B / 54 / strong
  - "Bayern Munich match preview": noise → tier C / 41 / low (passes the "about" false positive)
- Fresh validation pulse (rows `1136`-`1142`) confirmed end-to-end:
  - **`noise` rejects collapsed 136 → 5 (-96 %)** in the new audit window.
  - **`low_score` rejects dropped 256 → 137 (-46 %)**.
  - 1 ready_publish (`1142` Ukraine ceasefire, score 63, gpt-5-mini, 2364/2037/1864 DE/UK/EN with real DW media).
  - 3 ready_review (some via attempt-cap), 3 still in mid-pipeline `new` state with error "Publish-finish не дал прогресса" — these are the next class of stuck rows beyond the rebuild_bundle loop, worth investigation.
- `1142` "Dutzende Tote bei russischen Angriffen kurz vor ukrainischem einseitigem Waffenstillstand" is the kind of story that the previous filters would have killed silently as `noise`; it is now publish-ready end-to-end. Validates the user's hypothesis that "many were not passing for the wrong reasons".

## Latest Handoff 2026-05-06 06:45 UTC — dossier content propagation, 4 publish-ready articles

- Identified and fixed the systemic source-thinness bug. Two layers were silently shrinking the article body fed to the worker:
  - `EPV2_AI_Processor::compact_source_dossier()` was storing only an `excerpt` field truncated to 320–700 chars and dropping the `content` field entirely. Even when `EPV2_Source_Enricher` had fetched a 2.5–10 KB full article, only the headline + first paragraph survived in the saved compact dossier.
  - `EPV2_Worker_Client::build_payload()` always passed `$item->original_content` (the raw RSS snippet, ~350 chars) to the worker, so the worker never saw the enriched body.
- Commit `2c02bfa` adds a `content` field to compact_source_dossier with limits raised to primary 12 KB, shell_primary 8 KB, supporting 6 KB each (×4 the previous excerpt cap; well within the 10 MB ai_payload guard). And `build_payload` now prefers `_meta.source_dossier.primary.content` when it is at least 200 chars longer than `original_content`.
- Fresh 7-row pulse executed end-to-end with all the fixes (collect → 25 process ticks). Outcomes:
  - **4 ready_publish**: `1129` (welt, EU/Kazakhstan oil sanctions, 1974/1686/1756 DE/UK/EN), `1132` (leben-in-deutschland, NRW rescue costs, 2476/2265/2437), `1133` (sport, Ukraine drones, 2213/1640/1869), `1134` (ukraine, Selenskyj ceasefire, 3034/2808/2710). All real article bodies, all proper translations, all real source-domain media (tagesschau.de, abendzeitung-muenchen.de). `1132/1133/1134` ran on `openai/gpt-5-mini`; `1129` on `deepseek/deepseek-chat`.
  - 2 ready_review via attempt-cap (`1131` sport FC Bayern, `1135` kultur museum) — backstop fired correctly at 6 attempts.
  - 1 rejected via ultrathin-source-guard (`1130`) plus canonical publish-gate block.
- DE master length jumped from ~412 chars (looping) to 1974–3034 chars (publish-grade). No "слишком короткий" warnings on the publish-ready rows.
- Live state: queue 17 rows (9 stale ready_review from prior pulse + 4 fresh ready_publish + 2 fresh ready_review + 2 rejected). Pause flags ON. ai_payload avg 13.6 KB, max 22.7 KB — well under guards. `openai` saw 1 fresh failure during the run (cooldown 1607s remaining); `deepseek` healthy.
- Operator decision pending: review the 4 `ready_publish` rows in the WP admin and either publish manually (`scripts/epv2_pulse.sh publish` with both pause flags still ON dispatches the canonical handler), salvage the 2 fresh ready_review rows, or reject. The 9 stale ready_review rows from the earlier pulse are still in queue and should be triaged or cleared.

## Latest Handoff 2026-05-06 06:30 UTC — first end-to-end pulse + rebuild-loop fix

- First controlled pulse since freshness/source changes (commits `b29dcb2`, `6bc4d8e`). `scripts/epv2_pulse.sh collect` ran in 1m46s on 54 active sources, queueing 10 rows (`1119`-`1128`).
- New `selection_audit` window for the pulse: 579 events. **`stale` reject share dropped from 46% historical to 27% in this pulse** — `when:14d` Google News filter is working. UNIAN went 72% → 0% reject; Google News Ukraine 71.7% → 6.7%; BR24 40.8% → 26.7%. DW feeds stay high-reject but now under `noise`/`low_score`, not `stale` (different problem class — content quality, not freshness).
- During processing, every row whose source passed the 35-word ultra-thin gate but produced a too-short DE master entered an infinite `rebuild_bundle` loop in the `build_de_master` step. Two guards (`recent_rebuild_bundle_runs_stalled`, `maybe_cooldown_stagnated_rebuild`) failed to fire reliably:
  - `recent_rebuild_bundle_runs_stalled` filtered runs by `started_at >= queue.updated_at`; updated_at is bumped by every loop tick, excluding the very loops being counted.
  - `maybe_cooldown_stagnated_rebuild` compares payload signatures, but AI generates slightly different content each tick so the signature drifts and the counter resets.
- Both were addressed in commit `d3ab064`:
  - `recent_rebuild_bundle_runs_stalled` now uses a fixed 30-minute window (`started_at >= NOW() - 30 min`).
  - New backstop in `process_scheduled` triggers at `workflow_step_attempts >= 6` for `pipeline_stage=rebuild_bundle` + `workflow_step=build_de_master` and force-terminalizes to `ready_review` with `rebuild_bundle_attempt_cap` reason.
- Final pulse terminal states: `ready_review=9`, `rejected=1` (1128 wirtschaft via ultrathin-guard reject path), `published=0`. No infinite loops after the fix landed.
- Notable manual triage rows (`1119`/`1122`/`1123`) were directly SQL-flagged to `ready_review` before the attempt-cap was deployed; they're correct terminal-state entries but the operator may want to review/reject them.
- Systemic finding the operator should weigh: most queued sources are RSS headlines + 1-paragraph excerpts, not full articles. The rewriter cannot reliably hit publish-grade from such thin input even with a strong model. If the goal is autonomous publishing, source enrichment (full-article fetch from URL) needs a closer look — `EPV2_Source_Enricher` exists at 2338 LOC; auditing whether it pulls enough body text for these specific RSS sources is the right next investigation.

Live state at handoff: services active; queue 9 ready_review + 1 rejected; pause flags still ON; AI provider OpenAI/gpt-5-mini primary, deepseek fallback (no provider_health change). Tuning snapshot still present.

## Latest Handoff 2026-05-06 06:00 UTC

- Pulse tuning continues. Both pause flags ON. Queue empty. Live healthy across all checks: `/wp-login.php=302`, front=200, services active, worker `/health=ok`, locks free.
- Plugin hardening pass 2 deployed (commit `6bc4d8e`):
  - `EPV2_Deduplicator::is_story_duplicate()` primes post / meta / term caches with `update_post_caches()` before the 24-post recent-posts loop, and `update_meta_cache('post', $ids)` before the AI fingerprint loop. Kills the N × (`get_post_meta` + `get_the_title` + `wp_get_post_categories`) miss pattern in the dedup hot path.
  - `EPV2_AI_Client::parse_response()` wraps `json_decode` with `JSON_THROW_ON_ERROR`; catches `JsonException` and rethrows as `RuntimeException` with the body excerpt + decode error message instead of generic "AI transport error".
  - `EPV2_REST::can_bridge()` short-circuits when provided/expected token lengths differ, and now logs every successful token-auth call to `EPV2_Logger::info('rest', 'bridge token auth ok', …)` with route, method, user_id, remote_ip — the token itself is never logged. Audit trail for bridge invocations.
- Phase 4 deployed (commit `b29dcb2`):
  - `EPV2_Google_News::ensure_recent_filter($url, $days=14)` injects ` when:14d` into Google News RSS search queries that lack a `when:` operator. Wired into `EPV2_Collector` for `type='google_news'` sources so every fetch carries a 14-day recency cap. Smoke-tested against four URL shapes.
  - Sources `id=9` (European Parliament — URL pointed at the rss-feeds *directory* page; mean item age 3.5 YEARS) and `id=61` (SMB Museum News EN — archival museum feed; mean item age 35 days) deactivated with explanatory note in `ep_epv2_sources.notes`. Backup: `backups/sources-pre-disable-20260506-055638.sql`. Active source count 56 → 54.
- Audit data driving Phase 4 stays in `ep_epv2_selection_audit`. Per-source mean stale-reject age (computed from 2085 rows spanning 2026-04-28..2026-05-03) is in the commit message of `b29dcb2`. The 14-day Google News cap and archive-feed disablement are reversible: re-enable rows with `is_active=1`; remove the filter via comment-out in `EPV2_Collector::collect_source` lines around the new `ensure_recent_filter` call.
- Repo state: 6 commits on `review/plugin-audit` since last main: `6e0b242` (Phase 1 sync), `cb1be09` (hardening pass 1), `7babf87` (pulse tooling), `6bc4d8e` (hardening pass 2), `b29dcb2` (Phase 4 GN filter + archive deactivate), plus the next pending handoff/TODO bump.
- Phase 2 (controlled pulse + media backlog) is the right next step. Operator launches it with `scripts/epv2_pulse.sh collect` followed by `process` and `publish`. Watch `audit-summary` after `collect` to compare new outcome distribution against the pre-when:14d baseline.
- Phase 6 SLO targets: `consecutive_autonomous_publish_grade>=10`, `error<5%`, `rejected<40%`, OpenAI cooldown free, swap stable. Keep both pause flags ON until at least two clean controlled pulses confirm the new freshness behavior.

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
