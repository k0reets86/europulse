# CRITICAL DIRECTIVE

## Mandatory Next Session Entry

- [ ] Read `LLM_START_HERE.md`.
- [ ] Read the latest `2026-05-26 10:20 UTC` checkpoint before older notes.
- [ ] Run read-only health/queue/quality checks first.
- [ ] Do not run manual `collect`, `process`, or `publish`.
- [ ] Do not bypass approval limits. Push requires exact user approval: `разрешаю push в GitHub origin/review/plugin-audit`.

## Current Runtime Status 2026-05-26 10:20 UTC — score/night fix verified, AI budget throttled

- [x] Verified fresh live quality after the 2026-05-25 21:30 UTC fix:
  - post-fix published `<45`: `0`;
  - post-fix closed-night-window publishes: `0`;
  - `post_publish_rendered`: `33 pass`, average `100`;
  - live audit since `2026-05-25 21:30:00`: `11` groups, no hard/warn/info findings.
- [x] Regression checks passed:
  - `publish_gate_test.php`;
  - `quality_gate_test.php`;
  - `home_pool_test.php`;
  - worker translator/rewriter unittests.
- [x] Updated `home_pool_test.php` because fixture `6395` is no longer stale after canonical selection backfill; canonical `low/41` now correctly means homepage-ineligible.
- [x] Reduced future AI spend by changing live settings from `ai_budget_mode=normal`, `ai_selection_strictness=low` to `economy/medium`.
- [!] `ai_budget` remains hard-stopped today: about `254` rewrites and `6.3M` tokens against `500` / `5M`. Do not reset or raise the limit without explicit cost approval.
- [!] Local branch is clean but `ahead 2`: commits `9be2d40` and `bd9f4f9` are not pushed because GitHub push needs exact approval.
- [!] Old known-bad published groups still live: `6092`, `6230`, `6244`, `6296`, `6300`, `6325`, `6332`, `6352`, `6354`.
- [ ] Wait for daily AI budget reset or explicit budget decision; do not manually force queue stages.
- [ ] If user explicitly approves, push `review/plugin-audit` to GitHub.
- [ ] If user explicitly approves live content cleanup, draft/quarantine the old known-bad groups after backing up current post statuses/content.

## Current Runtime Status 2026-05-24 20:14 UTC — source-bound prompt live, title-only gate patch pending

- [x] Deployed source-bound prompt prevention:
  - worker rewriter forces `<120` source words into `news_brief`, caps tokens to `1024`, and injects `SOURCE-BOUND BRIEF`;
  - PHP AI prompt now computes `source_scope` and no longer tells the model to automatically "усиливать" thin signals;
  - title/url-only supporting entries are explicitly not factual support.
- [x] Validation passed:
  - Python compile for rewriter;
  - PHP lint for repo/live AI processor;
  - `5` worker regression tests OK, including new `test_rewriter_thin_source.py`.
- [x] Deployed live AI processor:
  - backup `/root/tmp/europulse-live-backups/20260524-source-bound-prompt/class-epv2-ai-processor.php`;
  - repo/live diff clean;
  - PHP-FPM reloaded;
  - `epv2-worker` restarted at `20:12 UTC`.
- [x] Checked site/services:
  - public IP `200 OK`;
  - worker active and listening on `127.0.0.1:8765`;
  - worker logs show `/health 200 OK`;
  - orchestrator active and autonomous.
- [x] Observed fresh autonomous outcome:
  - `6367` rejected after `build_de_master` attempt limit with `source_expansion_risk`, desired for a thin source.
- [!] Live gap found:
  - `6332` remains published with empty primary body, URL-only supporting, and noisy/unrelated `related` rows;
  - live gate can still allow title-only sources if the generated body is short.
- [x] Repo-only fix prepared:
  - `class-epv2-publish-gate.php` now blocks title-only/no-real-support profile as `source_expansion_risk` when generated body is more than a minimal brief;
  - repo PHP lint clean;
  - backup prepared: `/root/tmp/europulse-live-backups/20260524-title-only-source-gate/class-epv2-publish-gate.php`.
- [!] Repo-only fix NOT live:
  - deploy was rejected by escalation approval usage limit;
  - next session must deploy it normally when approvals are available, then lint live, reload PHP-FPM, and verify `6332` in diagnostic context.
- [!] Known-bad hallucination groups are still published and require explicit user approval before draft/quarantine:
  - `6352`, `6332`, `6354`, `6092`, `6325`, `6296`, `6230`, `5694`, `5589`, `6300`, `6244`.
- [ ] Recheck fresh posts after the source-bound prompt has had time to process new AI work under budget.
- [ ] Recheck active alerts; orchestrator maintenance mentioned `ai_budget`, while `epv2_active_alerts` still showed no pause/no alerts.
- [ ] Do not manually trigger processing to force proof.

## Current Runtime Status 2026-05-24 19:36 UTC — anti-hallucination source gate deployed

- [x] Audited a large sample:
  - `44` published queue groups across `ukraine`, `welt`, `politik`, `deutschland`, `wirtschaft`, `kultur`, `sport`, `leben-in-deutschland`, `bayern`, `muenchen`;
  - manual review confirmed severe source-expansion hallucinations in short-source / URL-only-support cases.
- [x] Confirmed representative bad IDs:
  - `6352`, `6332`, `6354`, `6092`, `6325`, `6296`, `6230`, `5694`, `5589`, `6300`, `6244`.
- [x] Deployed narrow publish hard stop:
  - `thin_source_dossier`;
  - `source_expansion_risk`;
  - `_meta.top_story` no longer bypasses source checks;
  - live backup `/root/tmp/europulse-live-backups/20260524-anti-hallucination/class-epv2-publish-gate.php`.
- [x] Verified live gate:
  - `6332` blocks with `thin_source_dossier`;
  - the other ten representative IDs block with `source_expansion_risk`.
- [x] Extended proper-name preservation:
  - `EuroPulse`, `Wall Street`, `The New York Times`, `The Washington Post`, `Reuters`, `Bloomberg`, `BBC`, and Wall Street variants;
  - worker keep-Latin tokens updated;
  - translator regression unittest now has `4` passing tests.
- [x] Repaired old visible UK proper-name defects:
  - backup JSON `/tmp/epv2_rendered_repair_backup_20260524_1939.json`;
  - `17` published UK posts repaired through existing `EPV2_Post_Audit`;
  - follow-up SQL found no remaining visible hits for checked patterns.
- [!] Known-bad hallucination groups are still published:
  - `11` queue groups / `33` WP posts;
  - draft/quarantine attempt was blocked because live unpublishing requires explicit user approval;
  - queue IDs: `6352`, `6332`, `6354`, `6092`, `6325`, `6296`, `6230`, `5694`, `5589`, `6300`, `6244`.
- [x] Final service state:
  - public IP `200 OK`;
  - worker active after `19:34 UTC` restart, `/health` OK, RSS about `76M`;
  - orchestrator active and idle;
  - PHP-FPM active, `slow=0`;
  - queue `published=214`, `rejected=34`;
  - active alerts empty.
- [ ] Observe fresh autonomous cycles after `2026-05-24 19:36 UTC`.
- [ ] Check false-positive rate for `source_expansion_risk` on valid compact briefs.
- [ ] Ask for explicit approval before drafting/quarantining the 11 known-bad published groups.
- [ ] If false positives are high, tune ratio/floor thresholds only.
- [ ] Continue proper-name map updates only from observed concrete defects or high-confidence source/brand names.

## Current Runtime Status 2026-05-24 18:46 UTC — rendered layer clean, Ukrainiska Pravda guard deployed

- [x] Checked plugin/site/services:
  - `europulse-autopilot-v21` active;
  - worker active and `/health` OK after restart, RSS about `100.3M`;
  - orchestrator active and autonomous;
  - PHP-FPM active, `slow=0`;
  - public IP `200 OK`;
  - automation/collect enabled;
  - active alerts empty.
- [x] Measured quality after `2026-05-24 12:47:00`:
  - `post_publish_rendered`: `28` item groups / `84` rows, all `pass 100`;
  - `publish_gate_shadow`: `102 pass`, `139 review`;
  - remaining review blockers are mainly `thin_source_dossier`, incomplete payload/media, `unsupported_numbers`, generic stock.
- [x] Found remaining shadow-only proper-name class:
  - `Украінска/Українска Правда=>Українська правда`;
  - some old `Київ Пост=>Kyiv Post`;
  - these were in shadow/rejected or thin-source payloads, not visible rendered posts.
- [x] Added and deployed narrow guard:
  - worker normalizes more `Ukrainska Pravda` variants;
  - PHP quality gate safety-map covers same variants;
  - regression test updated;
  - validation passed: worker compile, 3 translator tests, PHP lint;
  - live backup `/root/tmp/europulse-live-backups/20260524-1846/class-epv2-quality-gate.php`;
  - PHP-FPM reloaded and worker restarted.
- [x] Live smoke:
  - `Українска Правда` repairs to `Українська правда`;
  - `Київ Пост` repairs to `Kyiv Post`.
- [ ] Observe fresh autonomous rows after `2026-05-24 18:46 UTC`.
- [ ] Recheck whether `uk_bad_brand_transliteration` still appears for `Ukrainska Pravda` or `Kyiv Post`.
- [ ] Do not backfill unless a visible published rendered defect appears.

## Current Runtime Status 2026-05-24 12:42 UTC — proper-name preservation and HTML spacing fixed

- [x] Applied the user rule for Latin-script proper names in Ukrainian output:
  - do not Cyrillicize publisher/brand/product/org names like `Kyiv Post`, `Deutsche Welle`, `Tagesspiegel`, `OpenAI`, `Waymo`, `ProSieben`;
  - normalize `Kyivpost` / `KyivPost` to `Kyiv Post`.
- [x] Added fresh observed `Tagesspiegel` worker-side preservation after shadow audit row `2266` caught `Тагесспігел=>Tagesspiegel`.
- [x] Fixed fresh `uk_sentence_glue` false blocker from compact HTML block boundaries:
  - old blocker on post `16927` came from `</p><h2>` / `</h2><p>`;
  - worker and PHP rendered repair now insert separators between those tags.
- [x] Regression/lint passed:
  - worker `py_compile`;
  - translator regression unittest (`3` tests);
  - PHP lint for repo/live quality gate.
- [x] Deployed live:
  - backup `/root/tmp/europulse-live-backups/20260524-1240/class-epv2-quality-gate.php`;
  - live quality gate matches repo;
  - PHP-FPM reloaded;
  - `epv2-worker` restarted at `2026-05-24 12:39 UTC`.
- [x] Backfilled proven current defect:
  - backup table `ep_epv2_post_repair_backup_20260524_html_block_spacing_quality`;
  - repaired posts `16926`, `16927`, `16928`;
  - latest audit for all three is `post_publish_rendered pass 100`;
  - post `16927` UK rendered signals all zero.
- [x] Site/service smoke after deploy:
  - latest worker `/health` OK after `12:45 UTC` restart, RSS about `100.3M`;
  - public IP returns `200 OK`;
  - active alerts option empty.
- [x] Fresh autonomous proof after restart:
  - queue `6213` published with all rendered audits `pass 100`;
  - queue `6220` shadow audit `pass 96`;
  - queue `6214` was an expected terminal thin-source/incomplete-payload reject, not a UK brand/glue regression.
- [ ] Observe autonomous rows after `2026-05-24 12:42 UTC`; no manual forcing.
- [ ] Recalculate UK repair warning rate after a larger fresh sample.
- [ ] Add more Latin proper-name preservation cases only from observed data or high-confidence source/brand lists.
- [ ] Keep hard blocking/review routing disabled.

## Current Runtime Status 2026-05-24 12:15 UTC — fresh cycle active, placeholder-anchor repaired

- [x] Checked plugin/site state:
  - `europulse-autopilot-v21` active;
  - worker `/health` OK, RSS about `226.5M`, no recycle scheduled;
  - orchestrator active;
  - public IP `200 OK`;
  - automation/collect enabled, active alerts option empty.
- [x] Measured fresh data after `2026-05-24 00:24:00`:
  - `33` item groups / `99` rendered rows;
  - UK repair warnings `4/33 = 12.1%`;
  - broken transliterated URLs `0`;
  - bad source-name forms `0`.
- [x] Found and fixed two fresh placeholder-anchor cases:
  - pattern: `(<a ...>посилання на статтю</a>)`;
  - added detect/repair in `EPV2_Quality_Gate`;
  - deployed live with backup `/root/tmp/europulse-live-backups/20260524-1212/`;
  - backup table `ep_epv2_post_repair_backup_20260524_placeholder_anchor_quality`;
  - repaired `2` posts.
- [x] Final visible counters after targeted repair:
  - `0` broken URLs;
  - `0` bad source names;
  - `0` sentence glue;
  - `0` placeholders.
- [ ] Observe next autonomous publishes after `2026-05-24 12:15 UTC`; no manual forcing.
- [ ] Recalculate UK repair rate on post-12:15 rows.
- [ ] If still above roughly `5%`, continue upstream translator/prompt prevention before Phase 3.
- [ ] Keep hard blocking/review routing disabled.

## Current Runtime Status 2026-05-24 00:24 UTC — UK broken URL/source prevention deployed and backfilled

- [x] Confirmed post-2026-05-23 safety net recurrence was too high:
  - `22` new item groups / `66` posts after `2026-05-23 16:15:00`;
  - all `22` UK posts had repair warnings.
- [x] Identified new visible UK defect class:
  - transliterated URLs such as `гттп://204.168...`;
  - source/product names such as `Дойтшландфунк`, `Süddeutsche Цайтунг`, `ПроСібен`, `МагентаСпорт`.
- [x] Added worker-side prevention:
  - preserve HTML tags during Latin/Cyrillic hybrid repair;
  - preserve real `http(s)://` URLs so they do not become `гттп://`;
  - strip already-broken transliterated URLs from UK text;
  - normalize `Deutschlandfunk`, `Süddeutsche Zeitung`, `24tv`, `MagentaSport`, `ProSieben`.
- [x] Added PHP rendered safety net:
  - `broken_transliterated_url` detect/repair;
  - matching UK source/product preservation map.
- [x] Added regression coverage:
  - `worker-v21/tests/test_translator_quality_regressions.py`;
  - `worker-v21/tests/fixtures/quality/uk_rendered_repair_observed.json`.
- [x] Validation passed:
  - worker `py_compile`;
  - translator regression unittest (`2` tests);
  - PHP lint for changed PHP files;
  - live `EPV2_Quality_Gate::repair_rendered_text()` smoke test.
- [x] Deployed live:
  - live backup under `/root/tmp/europulse-live-backups/20260524-002051/`;
  - copied quality gate to live plugin;
  - reloaded `php8.3-fpm`;
  - restarted `epv2-worker` while queue was idle.
- [x] Backfilled proven published defects:
  - backup table `ep_epv2_post_repair_backup_20260524_broken_url_quality`;
  - `18` posts changed;
  - repair counts: `broken_transliterated_url=13`, `uk_brand_transliteration=7`;
  - final counters: `0` broken transliterated URLs, `0` bad source-name forms, `0` sentence glue, `0` placeholders.
- [x] Post-deploy service state:
  - worker `/health` OK, RSS about `100.5M`, no recycle scheduled;
  - orchestrator active;
  - public IP `200 OK`;
  - queue idle: `published=182`, `rejected=10`, `active_processing=0`.
- [ ] Observe next autonomous cycle after `2026-05-24 00:24 UTC`; no manual collect/process/publish.
- [ ] Measure new `post_publish_rendered` UK repair rate; target is below roughly `5%`.
- [ ] If repairs still recur, continue upstream translator/prompt prevention before Phase 3.
- [ ] Keep hard blocking/review routing disabled until post-fix shadow/rendered data is clean.
- [ ] Address duplicate-story publishing separately.

## Current Runtime Status 2026-05-23 16:15 UTC — lightweight rendered repair deployed and backfilled

- [x] Deployed live lightweight `post_publish_rendered` repair/audit for auto-mode.
- [x] Kept heavy post-publish audit skipped in auto-mode; only title/excerpt/content text repair runs.
- [x] Extended observed UK outlet/brand preservation map:
  - `Киівпост` -> `Kyiv Post`;
  - `Украінска Правда` -> `Українська правда`;
  - `Тагесспігел` -> `Tagesspiegel`;
  - `Дойтше Велле` -> `Deutsche Welle`;
  - `Фінанке.уа` -> `Finance.ua`;
  - `Поліке Аукс Фронтіèрес` -> `Police aux frontières`.
- [x] Backed up live PHP files under `/root/tmp/europulse-live-backups/20260523-160723/`.
- [x] Backfilled UK posts after `2026-05-22 23:21:00` with backup table `ep_epv2_post_repair_backup_20260523_uk_quality`.
- [x] Final visible-defect counters after backfill:
  - `44` UK posts checked;
  - `0` sentence-glue SQL hits;
  - `0` placeholder markers;
  - `0` observed bad outlet/brand forms.
- [x] Verified automatic post-publish rendered audit on fresh autonomous item `5923` / posts `16280`, `16281`, `16282`.
- [ ] Keep observing new posts; if repairs recur above roughly `5%`, fix worker translator/prompt prevention next.
- [ ] Add regression fixtures for rendered repair and URL/IP number false positives.
- [ ] Address duplicate-story publishing separately; Denmark/Frederiksen was published twice from Tagesspiegel/SPIEGEL.

## Current Runtime Status 2026-05-22 23:21 UTC — rendered UK audit/repair deployed

- [x] Completed the first post-shadow quality action without enabling hard blockers.
- [x] Added live `post_publish_rendered` audit rows through `EPV2_Quality_Gate` + `EPV2_Post_Audit`.
- [x] Added safe UK post-publish repair:
  - sentence glue after punctuation;
  - placeholder text `(посилання)` / `(посилання на статтю)`;
  - observed bad brand transliterations mapped to canonical forms.
- [x] Calibrated noisy fact/source shadow signals:
  - URLs and IPv4-like hosts are stripped before `detect_invented_numbers()`;
  - single unsupported-number hits become soft warnings in lower-risk contexts;
  - `thin_source_dossier` becomes a warning when publish gate already allowed the item and source context is not egregiously thin.
- [x] Deployed live and reloaded PHP-FPM.
- [x] Backfilled the last 24h Ukrainian posts:
  - backup table `ep_epv2_post_repair_backup_20260522_uk_quality`;
  - `70` checked;
  - `67` changed in pass 1;
  - `9` changed in pass 2.
- [x] Final defect counters after repair:
  - `0` known bad brand forms;
  - `0` placeholder link markers;
  - `0` Cyrillic sentence-glue regex hits.
- [x] Validation:
  - repo/live PHP lint passed;
  - live smoke tests passed;
  - worker `/health` OK;
  - PHP-FPM active with `slow=0`;
  - public site IP returned `200 OK`.
- [ ] Continue observing `post_publish_rendered` on new autonomous posts.
- [ ] Count new repairs after `2026-05-22 23:21:00`; if recurrence exceeds roughly `5%` of new UK posts, fix worker translator/prompt prevention next.
- [ ] If new brand mistransliterations appear, add only observed concrete forms to the map.
- [ ] Reassess `thin_source_dossier` / `unsupported_numbers` false positives before Phase 3 routing.
- [ ] Do not enable hard blocking yet.

## Current Runtime Status 2026-05-21 20:06 UTC — quality shadow gate deployed

- Active mission:
  - implement the `2026-05-21` autonomous hardening plan phase-by-phase;
  - first measure quality/fact/source problems in shadow mode;
  - then route confirmed hard blockers to review/blocking without harming normal autonomous publishing.
- Desired result:
  - visible quality telemetry in `ep_epv2_quality_audit` and admin page `Качество`;
  - autonomous publish remains live during observation;
  - later phases add worker fact audit, source trust, dedupe angle control, post-publish rendered audit, cache verification, and fixtures.
- [x] Started `docs/epv21-autonomous-plugin-hardening-plan-2026-05-21.md` with the safe audit foundation.
- [x] Added and deployed live:
  - `EPV2_Quality_Audit`;
  - `EPV2_Quality_Gate`;
  - `ep_epv2_quality_audit`;
  - publish-gate shadow recording;
  - admin page `Качество`.
- [x] Kept behavior shadow-only:
  - quality result is returned as `quality_shadow`;
  - publish `allowed` is unchanged;
  - seed-only payloads are skipped to avoid audit noise.
- [x] Verified live:
  - PHP lint passed for changed files;
  - table exists;
  - live autoload sees the quality classes;
  - write/delete audit smoke test passed;
  - read-only check on latest published payload returned `pass`, score `92`.
- [ ] Observe real `publish_gate_shadow` rows before turning any blockers into review/blocking behavior.
- [ ] Wait until at least `30` real `publish_gate_shadow` rows exist or at least `24h` runtime has passed, whichever is later.
- [ ] Analyze false positives before changing behavior:
  - `unsupported_numbers`;
  - `category_drift`;
  - `thin_source_dossier`;
  - `generic_stock_featured_media`;
  - language leak blockers.
- [ ] Next phase after observation: review-mode handling for hard blockers, not worker `/quality_audit` yet.
- [ ] Do not implement source trust, clustering, post-publish rendered audit, or cache manager before shadow data is reviewed.
- [ ] Do not manually trigger `collect`, `process`, or `publish` unless the user explicitly changes that instruction.

## Current Runtime Status 2026-05-20 09:45 UTC — watched collect resumed, rubric guard fixed

- [x] Re-enabled watched collection under user instruction:
  - `epv2_automation_paused=0`;
  - `epv2_collect_paused=0`;
  - next planned collect: `2026-05-20 10:00:00 UTC`.
- [x] Confirmed current idle baseline before collect:
  - queue `published=84`;
  - no processable rows;
  - orchestrator heartbeat fresh;
  - worker `/health` OK on port `8765`.
- [x] Investigated wrong category on latest published row:
  - `4842` / post `13694` went to `sport`;
  - input selector category was correct: `welt`;
  - payload category drifted later to `sport`.
- [x] Fixed the global category drift path:
  - worker preserves `category_proposed` as primary category;
  - semantic sport classification now uses token boundaries and stronger evidence;
  - input Story Card semantic seed is no longer wiped by editorial prompt-version reset;
  - semantic seed merges into fresh baseline before worker processing.
- [x] Fixed publish-gate bypass:
  - `selection=low|reject` is bypassed only by explicit manual mode;
  - automatic `top_story`/`breaking` no longer allows rejected payloads through.
- [x] Deployed and validated:
  - PHP copied live and PHP-FPM reloaded;
  - worker restarted;
  - `php -l` and `python3 -m py_compile` passed;
  - worker Guardian-politics test returns `news`;
  - live gate for `4842` now blocks `selection_reject`.
- [x] Saved current memory/handoff files before the environment limit:
  - `LLM_START_HERE.md`;
  - `SESSION_HANDOFF.md`;
  - `TODO.md`;
  - `docs/europulse-memory-brief.md`.
- [ ] Live observation is temporarily blocked by Codex usage limit after about `2026-05-20 09:49 UTC`; retry after `10:43 AM` or when access is available.
- [ ] Wait for the planned `10:00 UTC` collect without manual triggering.
- [ ] If no collect starts after the planned slot, debug scheduler/orchestrator rather than forcing the job manually.
- [ ] For fresh items, verify end-to-end rubric consistency:
  - input `admin_notes.selection.category`;
  - `_meta.story_card.category`;
  - `ai_payload.categories`;
  - final WP category terms.
- [ ] Continue watching CPU/RSS/swap during the first fresh autonomous cycle.
- [ ] Commit or explicitly hand off the dirty stabilization tree after the next stable observation window.

## Previous Runtime Status 2026-05-20 06:01 UTC — selector stall fixed, queue drained

- [x] Fixed the v2 selector stall globally:
  - real process claim no longer only looks at `new`;
  - v2 preview, `has_processable_items()`, and actual claim now share the same workflow candidate path;
  - stage/auto resume candidates must pass `item_is_processable_read_only()`.
- [x] Added maintenance repair for persisted stage drift:
  - `repair_persisted_retry_process_stage_contract()`;
  - `repair_persisted_publish_finish_translation_contract()`.
- [x] Added chronic-recycler guardrails:
  - selector blocks chronic recycler markers/history;
  - terminal chronic rows cannot be revived by later non-terminal `mark_state()` races.
- [x] Proved autonomous drain after the fix:
  - `4842` processed and published at `2026-05-20 05:58:00 UTC`, post `13694`;
  - `4829` left `retry_process` and was rejected for a real worker plagiarism blocker / auto-review policy;
  - `4843` rejected for worker blockers;
  - `4844` and `4845` rejected as stale TTL.
- [x] Verified final queue/process state:
  - `published=84`, `rejected=4`;
  - `workflow_v2_preview_selection(false) => mode=none`;
  - `has_processable_items() => false`.
- [x] Verified resource state:
  - worker `/health` OK, `rss_mb=182.4`;
  - orchestrator active;
  - PHP-FPM active with `slow=0`;
  - no current site `500/502`.
- [ ] Keep `epv2_collect_paused=1` until the user explicitly asks for a watched fresh collect.
- [ ] Commit or explicitly hand off the dirty stabilization tree.

## Current Runtime Status 2026-05-20 00:06 UTC — autonomous drain after memory/reject fixes

- [x] Confirmed the worker memory incident:
  - worker reached about `669M RSS` and `127M swap`;
  - `/health` timed out;
  - repeated process runs created false chronic/terminal failures.
- [x] Added worker memory self-protection:
  - `/health` now reports `rss_mb`, `request_count`, `recycle_scheduled`;
  - worker recycles by RSS/request thresholds;
  - background RSS monitor exits a long-running worker if it crosses the unhealthy RSS threshold.
- [x] Removed the largest Python memory source by default:
  - spaCy DE NER disabled unless `EPV2_ENABLE_SPACY_DE_NER` is set;
  - spaCy UK NER disabled unless `EPV2_ENABLE_SPACY_UK_NER` is set.
- [x] Fixed false blockers/rejects:
  - fabricated-name fallback no longer hard-blocks without spaCy;
  - Ukrainian filler/style warnings are soft, not fatal;
  - English translation that comes back German is retried;
  - `translation failed: All providers failed` is treated as provider/technical retry;
  - anti-plagiarism DE rewrite now gets one strict retry;
  - rebuild short-circuit `selection=low/reject` is rescued for strong ingest/story-card rows;
  - chronic recycler ignores infrastructure failures;
  - publish gate exposes `thin_source_dossier`.
- [x] Proved autonomous publisher:
  - `4838` published at `2026-05-19 23:59:23 UTC`, post `13666`;
  - `4831` published at `2026-05-20 00:05:00 UTC`, post `13674`.
- [x] Requeued technical/repairable false rejects:
  - `4825`, `4826`: anti-plagiarism retry added;
  - `4833`: selection-drift short-circuit fixed;
  - `4837`: translation/provider failure retry fixed.
- [x] Identified real non-publishable terminal cases:
  - `4830`: real `thin_source_dossier`; do not auto-publish without better source content.
  - `4839`: `selection=low`, score `40` vs publish threshold `41`; editorial gate, not a stall.
- [ ] Continue observation when live command approvals are available again:
  - verify `4832` publishes after due slot `2026-05-20 00:09:50 UTC`;
  - watch `4825/4826/4833/4837` complete after requeue;
  - watch `4829/4842/4843/4844/4845` retry rows and inspect any new terminal reasons.
- [ ] Keep `epv2_collect_paused=1` while draining this batch.
- [ ] Do not work around rejected live commands; the session hit approval/usage limit after the `00:05` checks.

## Current Runtime Status 2026-05-19 21:46 UTC — controlled automation restart under resource guards

- [x] Checked dashboard heartbeat incident:
  - `epv2-orchestrator` had been inactive since `2026-05-18 19:12:30 UTC`.
  - `epv2-worker` had also been inactive.
  - Dashboard heartbeat source is `epv2_publish_thread_heartbeat`; it is fresh again after restart.
- [x] Applied runtime guardrails so one process cannot fill memory/swap unchecked:
  - `epv2-worker`: `MemoryHigh=640M`, `MemoryMax=768M`, `MemorySwapMax=128M`, `CPUQuota=80%`, `TasksMax=64`, restart throttled.
  - `epv2-orchestrator`: `MemoryHigh=384M`, `MemoryMax=512M`, `MemorySwapMax=128M`, `CPUQuota=60%`, `TasksMax=64`, restart throttled.
  - `epv2-bot`: `MemoryHigh=64M`, `MemoryMax=128M`, `MemorySwapMax=32M`, `CPUQuota=20%`, `TasksMax=32`.
- [x] Added repo-side guard support:
  - `worker-v21/worker-setup.sh`
  - `worker-v21/orchestrator-setup.sh`
  - `ops/systemd/epv2-worker-restart-guard.conf`
  - `ops/systemd/epv2-orchestrator-restart-guard.conf`
  - `ops/systemd/epv2-bot-restart-guard.conf`
- [x] Moved `breaking_scan` away from PHP-FPM:
  - `worker-v21/epv2_bridge_orchestrator.py` now runs it by WP-CLI with timeout/kill/recovery.
  - Breaking scan and other job paths now have memory preflight guards.
  - `EPV2_Collector::run_breaking_scan()` skips immediately when `collect_paused=1`; live smoke test returned `{"skipped":"collect_paused"}`.
- [x] Fixed host maintenance/memory pressure:
  - resolved stuck `msmtp/apparmor` `dpkg` prompt;
  - `dpkg --audit` clean and `apt-get check` OK;
  - swap cleaned from about `1.1G` used to about `7M`;
  - stopped stale detached `tmux` session with two old `claude` processes using about `1.4G` RSS.
- [x] Cleared stale queue before restart:
  - backed up `16` stale active rows to `ep_epv2_queue_backup_pre_controlled_restart_20260519`;
  - marked those rows `rejected` with `stale_queue_cleared_before_controlled_restart` notes;
  - later maintenance trimmed terminal rejected rows;
  - current queue has `published=133` only and no active `new`, `ready_review`, `ready_publish`, or processable rows.
- [x] Disabled broken source:
  - source `id=11`, `muenchen.de Rathaus Umschau`, URL `http://www.muenchen.info/pia/RSS/RSS.xml`;
  - reason: returns HTTP 200 `text/html`, not a feed, causing repeated `breaking source failed` errors.
- [x] Started controlled automation:
  - `epv2-worker` active since `2026-05-19 21:38:20 UTC`, about `110M` RSS.
  - `epv2-orchestrator` active since `2026-05-19 21:38:48 UTC`, about `13M` RSS.
  - `epv2_automation_paused=0`.
  - `epv2_collect_paused=1` remains intentionally set.
  - Active alerts are empty as of `2026-05-19T21:46:19Z`.
- [ ] Continue observation without enabling collection:
  - check `systemctl status epv2-worker epv2-orchestrator --no-pager -l`;
  - check `epv2_active_alerts`;
  - check authorized bridge state/health if needed, but do not paste bridge tokens into docs.
- [ ] Do not enable regular collect while `current_window_mode=wind_down_quiet` and `collect_window_open=false`.
- [ ] If a fresh content test is needed, run one watched controlled collect during a safe daytime window; keep old cleared rows rejected.
- [ ] Before committing, review dirty tree and include the code/systemd/docs changes intentionally.

## Current Runtime Status 2026-05-18 20:15 UTC — post-repair checkpoint

Historical note: this section is superseded by the 2026-05-19 controlled-restart checkpoint above. The package-maintenance, swap, and service-restart blockers from this section were closed on 2026-05-19.

- [x] Create a single new-session entrypoint: `LLM_START_HERE.md`.
- [x] Stage provider-order repair so DeepSeek-primary does not silently call OpenAI fallback paths.
- [x] Add worker provider cooldown and `/health` provider snapshot.
- [x] Skip OpenAI embeddings when OpenAI is not in the explicit provider order.
- [x] Add cooldown checks to story_card, rewrite, translation, SEO, and embeddings.
- [x] Throttle high-frequency REST/process/queue info logs.
- [x] Make healthcheck pause-aware and write `epv2_active_alerts`.
- [x] Record frontend foundation and shared home-pool cache work in repo.
- [x] Finish local commits for the stabilization package and handoff.
- [x] Fix blocked package maintenance:
  - current blocker: old `msmtp` debconf prompt `msmtp/apparmor`
  - intended answer: `false`
  - finish with non-interactive `dpkg --configure -a`, then verify `dpkg --audit`
- [x] Decide whether to clear swap after package maintenance is clean.
  - current observed state: RAM has headroom, swap still about `1.1G/2.0G`
  - treat `swapoff -a && swapon -a` as explicit maintenance, not automatic
- [x] Before unpausing automation, verify/resolve the operational blockers:
  - provider settings are DeepSeek-primary as intended
  - worker `/health` includes provider snapshot
  - `epv2_active_alerts` is empty or only accepted warnings
  - no active old cron duplicate is running
- [x] Controlled restart sequence:
  - start `epv2-worker`
  - start `epv2-orchestrator`
  - unpause only while watching the first cycle

Do not start new feature work before these operational blockers are closed.

## Current Runtime Status 2026-05-06 10:15 UTC — Story Card now drives categorizer + tags + rewriter + media

- [x] Story Card built and persisted (commit `46a1e5c`).
- [x] Worker pipeline reads card from `existing_payload._meta.story_card` and:
  - overrides `ctx.categories` when card confidence ≥ 0.6 (commit `dec3c8a`)
  - replaces TF-IDF tag stub with `card.tags` (commit `dec3c8a`)
  - passes card to `rewrite_to_german()`; rewriter injects "STORY CARD (verbindliche Faktenbasis)" block with entities + key_facts + rewrite hints (commit `dec3c8a`)
- [x] Media resolver consumes card (commit `ac08ea8`):
  - `EPV2_Media::pexels_query` and `wikimedia_query` use `card.media_search_terms` ahead of title regex
  - Publisher copies `_meta.story_card` into dossier at all three resolver call sites
  - Honors `card.media_required` modes (skips when `generated`)
- [x] Validation row 1178 (Ukrainska Pravda war story) end-to-end: card.cat=ukraine/0.95, transliterated DE title, source-host media (24tv.ua), tags inherited verbatim from card.
- [ ] SEO stage (`worker-v21/src/epv2_worker/seo.py`) does not yet consume `card.seo`. Marginal quality lift; low priority since rewriter + tags already encode the semantic intent.
- [ ] Legacy backfill: walk existing rows in ready_publish/ready_review, re-build story card without re-running rewrite. Operator-visible only; doesn't affect future autonomy.
- [ ] Worker rewriter could also read `card.publishable_estimate=='reject'` and short-circuit before the AI rewrite call — pure cost-saving for items the upfront pass already flagged as unpublishable.

## Current Runtime Status 2026-05-06 17:35 UTC — Autonomous + legal compliance handoff

### Pipeline / autonomous run

- [x] Five fixes (A–E) for autonomous-run reject classes deployed (commit `ba66f90`). Stuck-thin-source mass-rejection pattern gone.
- [x] 87 mass-rejected rows recovered to `ready_review` via one-shot WP-CLI eval.
- [x] Story Card upfront pass + integration into categorizer / tags / rewriter / media / SEO.
- [x] Top-tier SEO live (schema enricher, news sitemap with keywords+images, robots.txt for AI agents, /llms.txt + /humans.txt + /security.txt).
- [ ] **Disable paywalled feeds** (Spiegel / Tagesspiegel / Welt / FAZ premium / Handelsblatt-paywall portion) — operator approval pending. Quick win: ~`UPDATE ep_epv2_sources SET is_active = 0 WHERE name IN ('SPIEGEL Schlagzeilen', 'Tagesspiegel', 'WELT Topnews', 'FAZ Aktuell', 'Handelsblatt Top');`. Restore by setting `is_active=1`.
- [ ] Operator triage of 145 `ready_review` rows: keep / reject / manual edit. Lots of paywalled-source items waiting.
- [ ] Watch for the rebuild_bundle_attempt_cap (commit `d3ab064`) firing on item 1286 / 1300+ — should terminate after 6 attempts; if not, raise priority.

### Legal compliance — interactive workflow with operator (Variant A free path)

- [ ] **Step 1 awaiting answer**: V.i.S.d.P. (name + postal address + email). Cannot fill Impressum without this.
- [ ] Step 2 — Ukrainian LLC details: full latinised name, EGRPOU code, director full name, full address, phone, registration date.
- [ ] Step 3 — confirm site emails: `editorial@europulse.eu`, `privacy@europulse.eu`, `security@europulse.eu` (any to skip / change?).
- [ ] Step 4 — toggle `epv2_settings['show_ai_disclaimer']` = true (texts already populated; one-line eval).
- [ ] Step 5 — `admin_email` replacement: `wp option update admin_email <real>` + `wp user update 1 --user_email=<real>`.
- [ ] Step 6 — fill placeholders in `/impressum/` DE=41, EN=289, UK=288.
- [ ] Step 7 — fill placeholders in `/datenschutz/` DE=3 (and EN/UK if exist).
- [ ] Step 8 — expand `/ueber-uns/` DE=36 with editorial team E-E-A-T (named human + bio + photo).
- [ ] Step 9 — fill `/korrekturen/` DE=40 with actual correction workflow.
- [ ] Step 10 — fill `/kontakt/` DE=37 with real channels.
- [ ] Step 11 — create `/editorial-guidelines/` page on three languages.
- [ ] Step 12 — Complianz Wizard (Settings → Complianz → Wizard) — Strict opt-in, cookie blocker on.
- [ ] Step 13 — install Two Factor plugin (free), enable for admin user 1.
- [ ] Step 14 — sign Hetzner AVV (Robot console).
- [ ] Step 15 — accept OpenAI / Anthropic / DeepSeek DPAs.

### Tier 1 — waits for SSL/domain (when europulse.eu DNS goes live)

- [ ] Let's Encrypt cert via certbot for europulse.eu (DE, UK paths).
- [ ] HTTP/2 + HTTP/3 in nginx (`listen 443 ssl http2`).
- [ ] HSTS header (`Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`).
- [ ] CSP header (cautious, test with Rank Math + Polylang).
- [ ] Cloudflare Free in front of origin (DDoS, basic WAF, HTTP/3).
- [ ] Submit sitemaps to Google Search Console + Bing Webmaster + Yandex Webmaster.
- [ ] IndexNow integration (instant ping to Bing/Yandex/Naver on publish).

### Tier 2 — can deploy now (free)

- [ ] Brotli compression in nginx.
- [ ] WebP/AVIF auto-conversion (free plugin: WP-Optimize / Imagify free).
- [ ] Author bios with photo + bio (E-E-A-T).
- [ ] Internal-linking / Related-Posts widget (Contextual Related Posts free).
- [ ] 404 page SEO-friendly (search + home link).
- [ ] Hide WP version (`add_filter('the_generator', '__return_empty_string')`).
- [ ] Disable XML-RPC unless used (`add_filter('xmlrpc_enabled', '__return_false')`).
- [ ] WP Mail SMTP Free for transactional emails.
- [ ] System cron instead of WP-Cron (`define('DISABLE_WP_CRON', true)` + `*/5 * * * * curl …/wp-cron.php`).

### Tier 3 — content E-E-A-T

- [ ] Editorial guidelines page on 3 languages.
- [ ] Corrections policy with workflow + email + SLA.
- [ ] Source dossier transparency block at article footer (publish dossier URLs).
- [ ] FAQPage Schema generated from `card.key_facts`.
- [ ] AI-disclosure label (toggle `epv2_settings['show_ai_disclaimer']`).

### Tier 4 — performance

- [ ] Measure CWV via PageSpeed Insights + Search Console after launch.
- [ ] Critical CSS inline.
- [ ] Defer non-critical JS.
- [ ] Self-host Google Fonts.
- [ ] Image preload for LCP candidate.

### Tier 5 — agent search

- [ ] JSON feed `/feed/json/`.
- [ ] `citation` Schema array — dossier URLs as `CreativeWork`.
- [ ] `retrievedDate`, `license`, `copyrightNotice` in Schema.
- [ ] `isPartOf` chain Article → CollectionPage → WebSite.

### Tier 6 — UX

- [ ] AMP (optional).
- [ ] PWA / Service Worker.
- [ ] Web Push (OneSignal Free or native).
- [ ] Newsletter (Mailchimp / Mailerlite Free).
- [ ] RSS-to-Telegram autoposting.

### Server / security hardening

- [ ] `apt install fail2ban` + enable for SSH and nginx 4xx flooding.
- [ ] SSH 2FA via `libpam-google-authenticator`.
- [ ] Disable root password login (key-only).
- [ ] Off-site backup: Hetzner Storage Box (~3 EUR/mo for 100 GB) or rclone+B2 with gpg encryption.
- [ ] Cron the existing `scripts/epv2_pulse.sh status-json` every 5 min into a status file for external monitoring.
- [ ] Uptime monitor (Uptime Kuma on a separate $5 VPS).

## Current Runtime Status 2026-05-06 09:50 UTC — Story Card upfront pass live

- [x] Story Card architecture built and shipped (commit `46a1e5c`):
  - Python `story_card.py` + `/analyze_story` endpoint
  - PHP `EPV2_Story_Card_Builder` + bootstrap registration
  - `process_scheduled` upfront hook with category override
  - `run_worker_stage` preservation across worker round-trips
  - `EPV2_Categorizer::refine_with_story_card` override path
- [x] Verified on 5 heterogenous inputs (DE/UK/EN cruise/war/medical/local) and live row 1178.
- [ ] Wire `card.rewrite` hints + `card.key_facts` + `card.entities` into worker rewriter prompt so the German master is grounded in the story-card facts and follows the suggested tone/structure/length. Edit `worker-v21/src/epv2_worker/rewriter.py` `_SYSTEM_PROMPT` and pass story card from `existing_payload._meta.story_card`.
- [ ] Wire `card.media_search_terms` + `card.media_required` into both the worker `media.py` and PHP `EPV2_Media::resolve_featured_media()`. Replace title-based search with the concrete visual hooks the card produced.
- [ ] Seed taxonomy tagger from `card.tags` (priority over keyword expansion in `EPV2_Categorizer::tags_from_text`).
- [ ] Seed SEO stage from `card.seo.primary_keyword` and `card.seo.secondary_keywords` instead of re-deriving keywords from rewritten German text.
- [ ] Backfill: small WP-CLI script to walk `state IN ('ready_publish','ready_review')` rows and build a story card for them, then re-categorize. Closes the legacy mis-categorization bucket without re-running expensive AI rewrites.

## Current Runtime Status 2026-05-06 09:05 UTC — staged→queued fix, categorizer hardening

- [x] Collector inner `break` removed → `continue` (commit `161a466`). Effective `max_collect_per_category` now respected. 231 staged → 217 queued (was 11).
- [x] Categorizer 'world' keyword expansion + smarter europa/welt fallback (commit `f327a8f`). Cruise-ship hantavirus, Romania PM, Iran-US, China-fireworks all route to welt now.
- [x] 12 ready_publish with publish-grade text quality (1–3 KB DE/UK/EN, accurate translations, source attribution, source-domain media).
- [ ] Re-categorize sweep on legacy payloads: some old ready_publish/ready_review rows carry pre-fix mis-categorizations (1157 Phagentherapie → politik, 1160 Leipzig crime → politik, 1152 cruise ship → politik). Needs `EPV2_AI_Processor::normalize_persisted_queue_contracts()` or a small targeted re-categorize pass.
- [ ] Watch for Handelsblatt source bias overcategorizing into wirtschaft on next pulses (set bias=wirtschaft in DB earlier — may be too sticky).
- [ ] BMW row got Pexels stock when BILD source-domain image was available — investigate why source-host media extraction missed.
- [ ] Operator review of the 12 ready_publish rows: read each in WP admin, decide which to publish via `scripts/epv2_pulse.sh publish` and which to send back to manual edit.
- [ ] HTML reader fails on NDR live-ticker / paywall pages (returned 404 / meta-only). Worth adding a `pick_paragraphs` fallback that targets `<article>` / Schema.org JSON-LD when the default xpath finds <300 chars.

## Current Runtime Status 2026-05-06 07:25 UTC — source cleanup + new top-tier feeds

- [x] Hoisted worker-blocker terminalization to all stages, lowered borderline uplift floor to 30 for heavyweight categories (commit `4602acc`).
- [x] Source cleanup: disabled 37 consistently-broken feeds (0 queued vs 50+ rejected over 2 weeks), kept 18 verified working ones, added 15 top-tier feeds (ZEIT/FAZ/SPIEGEL/Tagesspiegel/Handelsblatt/WELT/Tagesschau direkt/NDR Home/ZDF + Ukrainska Pravda/LIGA.net/BBC Ukrainian/24tv.ua + Kyiv Post/BBC Europe). Sources backup `backups/sources-pre-cleanup-20260506-071622.sql`. Validation pulse: reject share 95% → 12.5%; 246 staged_candidate vs 11 queued; new feeds account for the bulk of the staging lift.
- [ ] Investigate why staged_candidate=246 only converts to queued=11 in one pulse. Per-category caps in `EPV2_Budget_Manager::should_keep_in_queue` or `EPV2_Category_Planner::decide_for_candidate` are likely throttling. Audit those gates and consider raising effective queue admission cap during tuning.
- [ ] Try Hromadske / Suspilne / Kyiv Independent again with a different User-Agent or referrer — they responded 403 / 404 to a bare bot UA. Possible whitelist of `wp-cli` or browser UA needed.
- [ ] Process the 11 fresh queued rows from the validation pulse and see how DE/UK/EN translations look with the source-content propagation fix landed earlier.

## Current Runtime Status 2026-05-06 07:05 UTC — selection-reject false-positive fixes

- [x] Two systemic false-positive classes in selection scoring fixed (commit `12626dd`):
  - `looks_like_noise()`: split into long-phrase substring + short-token word-boundary + URL-path-segment matchers; lowercases input once; checks PHP_URL_PATH not full URL. Removes "abo↔about" and `?maca=...rss...` false positives.
  - `editorial_interest_weight()`: added English term variants for politik / welt / ukraine / wirtschaft / deutschland (chancellor, parliament, sanctions, ceasefire, etc.) plus Ukrainian variants where useful.
- [x] Validation pulse (`1136`-`1142`) confirms `noise` rejects collapsed 136 → 5 in audit window; `low_score` rejects -46 %; 1 ready_publish (DW Ukraine ceasefire) with full DE/UK/EN bodies.
- [ ] New stuck-state class to investigate: rows `1138/1140/1141` are in `state=new` with error "Publish-finish не дал прогресса после нескольких попыток" — different terminal class from `rebuild_bundle` loop. Likely the publish-finish stage refuses to advance even though DE/UK/EN are populated. Needs the same kind of attempt-cap / loop guard that we added for build_de_master.
- [ ] DER SPIEGEL via Google News still shows `low_score` for items like "Friedrich Merz: Wo der Kanzler bislang punkten konnte" (score 28). The German title contains `'kanzler'` and should hit editorial_interest_weight, but the source article probably lacks public_impact terms. Lower the per-category `c` threshold from 34 to 30 for politik/welt or extend uplift_borderline_newsworthy_score to fire from 30+ for serious categories with strong signal.
- [ ] Source-quality verification: 7 active Google News searches still produce mostly stale items even after `when:14d` (e.g. Google News Wirtschaft DE returned 20 stale events). Inspect whether their `q=` query terms are too narrow, or whether Google News is returning archive matches when fresh news is sparse.

## Current Runtime Status 2026-05-06 06:45 UTC — dossier content fix, 4 publish-ready articles

- [x] Fixed systemic source-thinness bug in commit `2c02bfa`: `compact_source_dossier` now retains a `content` field (12 KB primary / 8 KB shell / 6 KB supporting), and `EPV2_Worker_Client::build_payload` prefers `_meta.source_dossier.primary.content` over the RSS snippet `$item->original_content`.
- [x] Validation pulse on 7 fresh rows (`1129`-`1135`):
  - `1129/1132/1133/1134` reached `ready_publish` with proper 2K-3K-char DE/UK/EN translations and real-source media. No warnings, no blockers, no loops.
  - `1131/1135` terminalized via `rebuild_bundle_attempt_cap` after 6 attempts — backstop correct.
  - `1130` rejected via ultrathin-source-guard + publish-gate.
- [ ] Operator review of 4 `ready_publish` rows: read text in WP admin, decide whether to publish via `scripts/epv2_pulse.sh publish` (still pause-bypassing one canonical run) or hold for further edit.
- [ ] Triage the 9 stale `ready_review` rows from the previous pulse (`1119-1128`). They were terminalized with thin compact dossiers; either reject or re-run after force-clearing their dossier (`UPDATE ep_epv2_queue SET ai_payload = ... source_dossier removed` then move state to `new`).
- [ ] DW feeds and DER SPIEGEL via Google News are still high-reject under `noise`/`low_score`. Different problem class than freshness — content classification / scoring tuning.
- [ ] OpenAI `gpt-5-mini` saw a fresh "empty response" failure during the validation run (cooldown 1607s). Watch for repeating empty-response pattern; if persistent, switch primary to deepseek-chat for autonomous mode and keep gpt-5-mini in fallback.

## Current Runtime Status 2026-05-06 06:30 UTC — first pulse done, rebuild loop fixed

- [x] First end-to-end controlled pulse executed (collect → 12+ process ticks). 10 rows queued (`1119`-`1128`), all terminalized.
- [x] `when:14d` Google News filter validated: stale-reject share dropped 46% → 27%. UNIAN/Google News Ukraine moved from 70%+ to single digits.
- [x] Rebuild-loop bug fixed (commit `d3ab064`):
  - `recent_rebuild_bundle_runs_stalled` rewritten to use a fixed 30-minute `started_at` window instead of `started_at >= updated_at` (which was being defeated by every loop's own row update).
  - Hard backstop added in `process_scheduled`: any row with `pipeline_stage=rebuild_bundle` + `workflow_step=build_de_master` + `workflow_step_attempts >= 6` is force-terminalized to `ready_review` with `rebuild_bundle_attempt_cap` reason. Verified on row `1124` during the same pulse.
- [ ] Operator review of the 9 `ready_review` rows from the pulse: decide salvage vs reject. Most look like real news topics but with too-thin source material to hit publish-grade text. Rows: 1119 (politik EU/Ukraine), 1122 (sport), 1123 (kultur 007 game), 1124 (welt Iran), 1126 (community Herrmann integration), and 1120/1121/1125/1127 ultra-thin-guard. Row `1128` (wirtschaft) is auto-rejected.
- [ ] Source enrichment audit. Pulse exposed that most RSS sources return headline + one-paragraph excerpt only. `EPV2_Source_Enricher` at 2338 LOC needs an audit to confirm whether full-article body fetch is happening for these sources. If not, autonomous publish-grade is unreachable for many candidates regardless of model strength.
- [ ] DW feeds and DER SPIEGEL via Google News still hit high reject — but now under `noise` / `low_score`, not `stale`. Different problem class: content classification / scoring, not freshness. Lower priority than source enrichment.

## Current Runtime Status 2026-05-06 06:00 UTC Pulse Tuning + Hardening Pass 2 + Source Audit Acted

- [x] Plugin hardening pass 2 deployed (commit `6bc4d8e`): primed post/meta/term caches in `EPV2_Deduplicator::is_story_duplicate()`; `JSON_THROW_ON_ERROR` in `EPV2_AI_Client::parse_response()`; bridge token success logging in `EPV2_REST::can_bridge()`.
- [x] Phase 4 source audit acted on (commit `b29dcb2`):
  - `EPV2_Google_News::ensure_recent_filter()` adds ` when:14d` to Google News RSS searches that lack a `when:` operator; called from `EPV2_Collector::collect_source` for `type='google_news'`.
  - Sources `id=9` (European Parliament — URL pointed to a directory page, mean item age 3.5 years) and `id=61` (SMB Museum News EN — archive feed, mean item age 35 days) deactivated with notes; backup `backups/sources-pre-disable-20260506-055638.sql`. Active sources 56 → 54.
- [ ] Confirm `when:14d` actually reduces stale-reject rate in the next collect pulse; compare audit outcome distribution before/after via `scripts/epv2_pulse.sh audit-summary`. If still >40% stale per source, investigate whether Google News date parsing is wrong (audit field `original_date` populated from RSS `pubDate`).
- [ ] European Parliament source URL (`https://www.europarl.europa.eu/at-your-service/de/stay-informed/rss-feeds`) is a directory page, not a feed — replace with one of the listed individual EP RSS feeds before re-enabling.
- [ ] Rejected-rate audit deferred items still open: per-category scoring is in place but per-rubric scoreboard verification needs real fresh pulse data.

## Current Runtime Status 2026-05-05 23:45 UTC Pulse Tuning + Plugin Hardening Pass 1

- [x] Phase 0 routing fix verified live for row `1114`. `EPV2_Queue::workflow_v2_preview_selection(true)` returned `claim_oldest_new` with `item_id=1114`. One `EPV2_AI_Processor::process_scheduled(true, true)` tick advanced `updated_at` from `22:45:14` → `23:18:18` without falling into a `rebuild_bundle` quarantine loop. Selector correctly claims staged `new` rows; the deployed routing patch holds.
- [x] Stale queue cleanup. DB backup `backups/epv21-db-pre-cleanup-20260505-231738.sql` (65 MB), then `DELETE FROM ep_epv2_queue WHERE id BETWEEN 1109 AND 1118` removed all 10 stale rows; `ep_epv2_queue` is now empty. `ep_epv2_selection_audit` retained (2085 analytics rows).
- [x] Tuning limits temporarily lifted. Original `epv2_settings` snapshot saved in WP option `epv2_settings_pre_tuning_snapshot_20260505` (autoload=false). Active values: `enforce_daily_publish_target=false`, `daily_publish_target=999`, `max_collect_per_category=99`, `queue_new_max_per_category=99`, `queue_new_max_per_source=99`, `max_queue_batch=5`, `category_plans[*].min/target/max=0/0/99` (`upgrade_threshold` preserved). Restore: `update_option('epv2_settings', get_option('epv2_settings_pre_tuning_snapshot_20260505'))` then `delete_option('epv2_settings_pre_tuning_snapshot_20260505')`.
- [x] Phase 1 repo↔live sync. Live → repo for `class-epv2-weekly-analysis.php`, `class-epv2-plugin.php`, `class-epv2-html-reader.php` (new `pick_language()`), `class-epv2-manual-mode.php` (lang-aware import), `class-epv2-deduplicator.php` (24h AI fingerprint window). Repo → live for `class-epv2-queue.php` and `class-epv2-ai-processor.php` (whitespace cleanup). Pre-sync backups in `backups/phase1-sync-20260505-2326/`. Legacy `worker/` tree, `config/epv3-*` defaults, archived pre-v21 handoff to `docs/archive/` removed in commit `6e0b242`.
- [x] Phase 5 plugin hardening pass 1 (commit `cb1be09`):
  - `ep_epv2_queue.pipeline_stage` STORED generated column over `ai_payload._meta.pipeline_stage` plus `idx_pipeline_stage` index, applied via `EPV2_Installer::ensure_queue_generated_columns()`. The three `LIKE %"pipeline_stage":"..."%` LONGTEXT scans in `repair_persisted_publish_finish_translation_contract` / `_media_blockers` now use indexed equality.
  - `EPV2_Queue::guard_payload_field_sizes()` rejects `ai_payload`/`publish_payload` UPDATEs over 10 MB (default MariaDB `max_allowed_packet=16777216`); wired into `update_fields()` and `mark_state()`. Smoke-tested.
  - `normalize_persisted_queue_contracts` and `queue_contract_regression_check` refactored to two-step pattern: select ids only, fetch each row's LONGTEXT individually, free between iterations. Memory bounded by single-row payload, not by `LIMIT`.
  - `EPV2_Google_News::http_get_body/http_post_body` now log `curl_error()` via `EPV2_Logger::warning` so silent SSL/network failures during news ingestion are observable.
- [x] Phase 6 prep — pulse-mode operator tooling (commit pending):
  - `scripts/epv2_pulse_status.php` — read-only digest of pause flags, queue counts, pipeline stage distribution, recent runs, AI provider health, locks, cron, selection audit 24h window. Supports `--json` or `EPV2_PULSE_JSON=1`.
  - `scripts/epv2_pulse.sh` — wrapper with `collect | process | publish | status | status-json | recent N | audit-summary` subcommands. Each pulse subcommand bypasses pause for one canonical handler call (`run_scheduled(true)` etc.) without touching the option flags.
- [x] Phase 3 (per-category scoring) verified already implemented. `EPV2_Budget_Manager::category_scorecard()` carries A/B/C/publish_c thresholds and per-rubric `dimensions` for politik/welt/ukraine/europa/deutschland/wirtschaft/leben-in-deutschland/community/muenchen/bayern/kultur/sport. Was an open backlog item but already shipped.
- [x] Phase 4 (source audit) — analyzed `ep_epv2_selection_audit` (2085 rows, span 2026-04-28..2026-05-03). All "100% reject" sources (15 feeds: Google News Європа UK, RIS München, Google News Politics EN, etc.) reject under `reject_class=stale`, not via fetch failures. Last fetch is recent and `last_error` is empty. The fix is freshness-window calibration per source type (institutional/slow-moving feeds need a longer window), not deactivation. Documented; no source rows changed.
- [ ] Phase 2 (controlled pulse + media backlog) — execute when operator triggers a manual collect via `scripts/epv2_pulse.sh collect`. Then `process` and inspect output through `scripts/epv2_controlled_rebuild_quality_audit.php` before any `publish`. Then close the 4 remaining media backlog items: `1086` (Adidas/DFL), `1044` (UA open-for-business), `1027` (border ruling), `1047` (teacher-pay) — find source/editorial replacement; do not mask with stock.
- [ ] Phase 5 hardening pass 2 (deferred; not all of the agent's audit is closed):
  - `JSON_THROW_ON_ERROR` migration on `json_decode` calls in `class-epv2-media.php`, `class-epv2-ai-client.php` and other call sites.
  - Wrap `wp_remote_post`/`wp_remote_get` calls in `class-epv2-ai-client.php` with consistent try/catch + structured logger output.
  - Batched `get_post_meta` in `class-epv2-deduplicator.php` instead of per-post loop.
  - REST `can_bridge()` token semantics review.
- [ ] Phase 6 (resume + SLO) — only after Phase 2 produces clean pulses with acceptable text/media/category quality. SLO targets: `consecutive_autonomous_publish_grade>=10`, `error<5%`, `rejected<40%`, OpenAI cooldown free, swap usage stable.

## Current Runtime Status 2026-05-05 Routing/Selector Continuation

- [x] Read current TODO/session/memory handoff and resumed from the blocker around rows `1109-1118`.
- [x] Live safety verified before changes: `/wp-login.php=200`, `epv2-worker` active, `epv2-orchestrator` active and logging `automation paused`, `epv2_automation_paused=1`, `epv2_collect_paused=1`.
- [x] Current controlled queue snapshot before deploy: `error=3`, `new=2`, `rejected=5`; `1114` remained `new` with `pipeline_stage=rebuild_bundle`, `workflow_step=build_de_master`, no `post_id`.
- [x] DB backup before the 1114 retick exists: `/tmp/europulse-before-1114-routing-retick-20260505-2248.sql`.
- [x] Diagnosed why `1114` stalled: live predicates on saved payload now resolve to `publish_finish`, but v2 selector could hide staged `new` rows when `workflow_user_state_for_row()` inferred `ready_publish` from payload while `workflow_step/pipeline_stage` still existed.
- [x] Patched `wp-plugins/europulse-autopilot-v21/includes/queue/class-epv2-queue.php`: `bridge_next_processable_row()` no longer skips inferred `ready_publish` rows if they still have an explicit `workflow_step` or `pipeline_stage`.
- [x] Patched `wp-plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php`: after worker `rebuild_bundle`, a blocker-free bundle with ready UK/EN translations and viable DE master is forced to `publish_finish` instead of reopening `rebuild_bundle`.
- [x] Local lint passed for both patched PHP files; live backups created:
  - `backups/class-epv2-ai-processor.php.pre-selector-routing-fix-20260505-2252`
  - `backups/class-epv2-queue.php.pre-selector-routing-fix-20260505-2252`
- [x] Patched files deployed to live, live `php -l` passed for both, PHP-FPM reloaded, site stayed `/wp-login.php=200`, both pauses stayed `1`.
- [ ] Further live WP/DB verification was blocked by the environment usage limit after deploy; do not bypass with indirect WP/DB commands in the same constrained session.
- [ ] Next safe live step when usage is available: check `EPV2_Queue::workflow_v2_preview_selection(true)` for `1114`; expected candidate is `1114`, not `none`.
- [ ] Then run exactly one controlled `EPV2_AI_Processor::process_scheduled(true, true)` tick. Expected result is `queued_publish_finish_stage`, `worker_rebuild_ready_publish`, or a controlled gate/manual state, but never `queued_rebuild_bundle_stage`.
- [ ] Keep `epv2_automation_paused=1` and `epv2_collect_paused=1` until that post-deploy selector/routing proof completes.

## Current Runtime Status 2026-05-04 GPT-5/Text Quality Continuation

- [x] Live health rechecked before continuation: `/wp-login.php=200`, `epv2-worker` active since `2026-05-04 05:40:33 UTC`, `epv2-orchestrator` active and logging `automation paused`.
- [x] Safety pause rechecked: `epv2_automation_paused=1`, `epv2_collect_paused=1`. Do not unpause until controlled quality and gate checks finish.
- [x] Queue high-level snapshot before the environment blocked further live DB reads: `new=10`; this matches the controlled rows `1109-1118` still not being published.
- [x] Worker restart after the final Python quality patches completed successfully before this handoff; the running worker is now using the repo worker code.
- [x] Controlled direct rebuild for ultra-thin row `1109` proved safe behavior after restart: `outcome=ready_review`, blocker `Primary source too thin for autopublish`, no automatic publish proof should use this row.
- [x] Deterministic ultra-thin source guard added in `worker-v21/src/epv2_worker/rewriter.py`: sources under the clean-word threshold produce a short safe brief instead of hallucinated expansion.
- [x] Ukrainian/English translation postprocessing tightened in `worker-v21/src/epv2_worker/translator.py`: strips generated first names/roles, normalizes `Мірш/Зедер`, blocks `стрічка` ticker calques, and normalizes health-insurance/Kabinet wording.
- [x] Publish gate patched and deployed live: `_meta.blockers` now blocks `EPV2_Publish_Gate`, `publish_ready_gate_passes()` and fast-ready payload acceptance. Live WP-CLI gate proof returned `allowed=false` with `payload_blockers`.
- [x] Controlled direct rebuild for row `1110` returned `outcome=ready_publish`, no blockers/warnings, `ai_runtime` stages all on `openai/gpt-5-mini`; DE/UK/EN copy was materially better than `1109` and acceptable for further controlled testing.
- [x] Local verification after the latest repo state: `php -l` passes for `class-epv2-publish-gate.php` and `class-epv2-ai-processor.php`; `worker-v21/.venv/bin/python -m compileall -q worker-v21/src/epv2_worker` passes.
- [x] Added safe helper `scripts/epv2_controlled_rebuild_quality_audit.php`: WP-CLI direct rebuild audit for selected queue ids, prints outcome/blockers/runtime/text/media previews, and does not save queue state or publish posts.
- [ ] Live escalated commands are currently blocked by the environment usage limit after `2026-05-04 05:50 UTC`; do not try to bypass with indirect WP/DB commands. Continue live checks only when approvals/usage are available again.
- [ ] Immediate next live step when available: rerun controlled direct rebuild for `1109` after the final translator normalizer patch and confirm no `державних страхових фондів`, no `Кабінет Міністрів`, no generated first names/roles, and still `ready_review`.
- [ ] Then process rows `1111-1118` via `wp eval-file scripts/epv2_controlled_rebuild_quality_audit.php -- --ids=1111-1118`, without unpausing automation and without saving/publishing, inspecting DE/UK/EN quality, blockers, `ai_runtime`, media/category/source sufficiency.
- [ ] Before any real DB-processing/unpause, decide terminal policy for `1109`: leave as manual `ready_review` or reject/hold as source-too-thin; it must not enter `ready_publish`.

## Current Runtime Status 2026-05-03 GPT-5/Text Quality Session

- [x] Live safety pause confirmed during this quality session: `epv2_automation_paused=1`; collection was intentionally kept paused from earlier (`epv2_collect_paused=1`). Do not unpause until text-quality gates are proven.
- [x] Pre-test DB backup exists before this controlled collect/test package: `/tmp/europulse-before-gpt5-collect-20260503-2250.sql`.
- [x] Fresh controlled collect earlier in this session created queue rows `1109-1118`; `1109` is the current regression item and must not be published automatically.
- [x] PHP worker client fatal fixed and deployed live: `includes/core/class-epv2-worker-client.php` now stringifies array/object `detail` safely for non-200 worker errors, so `HTTP 422` no longer causes WP-CLI fatal.
- [x] PHP/Python worker contract fixed and deployed live: empty `existing_payload` now serializes as `{}` instead of `[]`, eliminating Pydantic `dict_type` 422 errors for direct rebuild calls.
- [x] Live PHP lint passed for repo and live `class-epv2-worker-client.php`; `/wp-login.php` stayed `200` after deploy.
- [x] Worker GPT-5 compatibility and traceability were already patched in repo: GPT-5/o completion budget via `extra_body`, `_meta.ai_runtime`, provider/model trace, and empty OpenAI response diagnostics.
- [x] Rewriter quality patch completed locally: thin sources are forced to `brief`; provider failure details are preserved; unsupported generated first names are stripped when source only provides surnames; ultra-thin source prompt forbids invented first names/roles/motives/consequences.
- [x] Pipeline quality patch completed locally: source HTML/image tags are stripped before rewriting; Google News search/homepage support URLs are filtered; missing DE/UK/EN language packages are blockers, not warnings; sources with `<35` clean words become `ready_review` via blocker `Primary source too thin for autopublish`.
- [x] Ukrainian translator prompt/postprocess patch completed locally: avoids `Ticker -> стрічка`, moves repeated `Як повідомляє...` source openings after the news sentence, replaces bureaucratic calques like `доопрацювання`, normalizes Krankenkassen terms, strips generated first names and unsupported Bavaria/CSU roles from translations when absent in DE master.
- [x] Local verification passed after final translator/pipeline patches: `worker-v21/.venv/bin/python -m compileall -q worker-v21/src/epv2_worker`; helper tests strip `Маттіас Мірш`, `Маркус Зедер`, `прем’єр-міністр Баварії Зедер`, `Bavaria’s Söder`, and normalize `державних лікарняних кас` to `кас обов’язкового медичного страхування`.
- [x] Controlled rebuild before the final role/term patch showed the intended safety behavior: `1109` returned `outcome=ready_review` with blocker `Primary source too thin for autopublish`, so it would not auto-publish.
- [ ] Important pending deploy step: the final Python translator/pipeline/rewrite code is in repo but the last `systemctl restart epv2-worker` was rejected by environment usage limit. Next session must restart `epv2-worker` before judging live output.
- [ ] After restart, rerun controlled rebuild for `1109` without unpausing automation. Expected: `ready_review`, no `доопрацювання`, no generated first names, no unsupported `прем’єр-міністр Баварії` role, no empty UK/EN, no image-caption facts, no source formula repetition at paragraph starts.
- [ ] If controlled rebuild passes, decide whether `1109` should remain `ready_review` or be rejected as too thin/low-value; do not publish it as autopilot proof.
- [ ] Audit/patch publish gate next: C/review or ultra-thin items must not fast-transition to `ready_publish`; inspect `EPV2_AI_Processor::transition_item_to_ready_publish`, fast-ready paths, `EPV2_Publish_Gate`, and selection payload propagation.
- [ ] Then process the remaining fresh rows `1110-1118` in controlled mode and inspect DE/UK/EN text quality plus media/category fit before unpausing automation.

## Current Runtime Status 2026-04-29 Media/Review Session

- [x] Rows `1092-1098` recalibrated and controlled batch completed: `1092/1094/1095/1096/1098` published, `1093/1097` rejected by systemic gates, no `new`/`ready_publish` rows left.
- [x] Five-minute publish lane fixed: `ready_publish` now schedules from actual ready time plus interval, not from a cron-aligned slot; controlled sequence published roughly every 5 minutes.
- [x] Systemic selection fixes deployed live: low-value sport live/fixture pages are hard-rejected; soft animal/oddity items without public/practical value are penalized.
- [x] Media fallback policy improved live: high-context politics/public stories no longer fall through to Pexels; Wikimedia entity fallback is allowed for named public figures when source media is absent.
- [x] Problem example fixed live: posts `6290/6291/6292` for `Nächster ranghoher CDU-Politiker geht auf Distanz zu Merz’ Renten-Aussage` no longer use the unsuitable Pexels image; all three now use Wikimedia image `20240417-Friedrich_Merz_1808.jpg` with credit `Michael Lucan / Wikimedia Commons`.
- [x] Publisher patched live so existing stock-media URLs in payload are not accepted directly for publish; they must be resolved through source-first/context media gate.
- [x] Media diagnostics added to publish meta for new publications: provider, origin, stock/source flags, intent, fit pass, stock allowed flags, risk flags, context tokens/phrases.
- [x] Admin security patch deployed live: `regen_block` now requires `manage_europulse_autopilot`; `queue_snapshot` AJAX now has nonce validation; queue table has a lightweight `Медиа` column.
- [x] Plugin review partial findings checked: REST bridge uses secret/capability gate; admin-post actions have nonce + capabilities; WP-Cron has no duplicate `epv2_collect/process/publish` events in server-orchestrator mode; root crontab has no wp-cron/orchestrator duplicate.
- [x] Memory-safe admin patch deployed live: `class-epv2-admin.php` now matches repo checksum, keeps queue UI lightweight, and avoids loading full `ai_payload` for the lightweight media table.
- [x] Backfilled `_epv2_media_diagnostics` for 40 recent published posts after SQL backup `backups/epv21-db-pre-media-diagnostics-backfill-20260429-1719.sql`.
- [x] Media-text fit rules tightened live: category slugs like `sport-de` normalize to canonical `sport`; Pexels is blocked for high-context public/politics/Bavaria-service/migration/legal/specific-sport/biotech stories; generic Wikimedia/stock is blocked for specific sport stories; Deutschlandfunk media is trusted as source media.
- [x] Published media repair package completed after SQL backup `backups/epv21-db-pre-published-media-repair-20260429-1738.sql`: repaired all translations for queue `1090`, `1067`, `1050`, `1041`, `1035`, `1030` with `fit=yes` source images and refreshed origin/credit/caption/diagnostics.
- [x] Queue garbage cleaned live after backups: removed `25` `rejected` rows after `backups/epv21-db-pre-clear-rejected-20260429-1752.sql`; removed `9` technical `error` quarantine rows with `post_id=0` after `backups/epv21-db-pre-clear-error-garbage-20260429-1753.sql`. Queue now has only `published=58`.
- [ ] Remaining manual media backlog from latest 40: `1086` Adidas/DFL still has blocked Pexels and no safe automatic replacement; `1044` Ukraine open-for-business still has blocked Pexels and no safe replacement; `1027` border-control ruling has source image mismatch and no safe replacement; `1047` teacher-pay/inflation has weak Pexels and needs better source/editorial image.
- [ ] Add richer media/debug parameters to the admin/audit table: provider, origin host, source image present, source-vs-stock, fit pass, risk flags, entity/geography/topic tokens, category mismatch, source dossier support count, fallback reason/query.
- [ ] Complete AGENTS plugin review report/fixes beyond the already fixed AJAX/capability and cron checks: DB hot queries, generated columns/indexes for JSON filters, memory-heavy admin views, fatal-risk paths around media/download/network calls.

## Current Runtime Status 2026-04-28 Editorial Quality Handoff

- [x] Анализ качества новостей проведён как редакционный аудит: категории, медиа, подписи, теги, стиль, источники, noindex и AI-настройки.
- [x] Live backup перед deploy создан: `backups/epv21-live-plugin-pre-editorial-20260428-173520.tar.gz`.
- [x] DB backup перед settings создан: `backups/epv21-db-pre-editorial-settings-20260428-173810.sql`.
- [x] Найдена и исправлена причина неправильной рубрикации: неканонические slug-значения и substring false-positive (`usa` внутри `Zusammenhalt`) больше не должны уводить материалы в `welt`.
- [x] Найдена и исправлена причина `noindex,nofollow`: `blog_public` переключён с `0` на `1`.
- [x] `source_block_enabled` включён; публикация теперь добавляет видимый блок источника.
- [x] В repo и live развёрнуты правки категорий, review/publish pipeline, captions/alt, source block labels, AI final editorial guard и очистка тегов.
- [x] REST bridge защищён от долгих HTTP-запусков: `/bridge/collect` и `/bridge/process` в server-orchestrator mode возвращают `202`, не создают ghost runs/locks и не запускают тяжёлые jobs внутри nginx.
- [x] Локальный и live `php -l` пройден для изменённых файлов; checksum repo/live совпадал после deploy.
- [x] Controlled collect через WP-CLI: run `25134`, active sources `56/56`, collected `9`, errors `0`, queue items `1083-1091`.
- [x] Автономная цепочка доказана без ручного проталкивания: acceptance `12/10`, recent items включают `1066,1067,1075,1079,1080,1083,1084,1086,1087,1088,1089,1090`.
- [x] `1085` корректно отклонён как `selection decision "low"` и не опубликован.
- [x] Исправлен уже опубликованный miscategory `1083`: posts `6558/6559/6560` переведены в `politik/polityka/politics`, queue `1083.category_final=politik`.
- [x] Источники в опубликованных постах перечищены: `Originalquelle` заменяется на реальные labels через source adapters.
- [x] Теги контрольных постов `1083-1087` перечищены; sanitizer теперь режет generic/currency/noise, wrong-language tags и canonical-duplicates.
- [x] Health после deploy: front `301` на canonical IP, `/wp-login.php=200`, nginx/php-fpm/worker/orchestrator active, bridge health `ok`, locks empty, queue contract `ok`.
- [ ] Проверить оставшийся `ready_publish` item `1091` и следующие публикации после текущего handoff.
- [ ] `collect_paused=true`: решить, когда безопасно возобновлять регулярный сбор, сейчас новый сбор выполнялся только controlled WP-CLI run.
- [ ] AI primary НЕ переключён на `gpt-5-mini`: preflight вернул пустой ответ; оставить DeepSeek primary и OpenAI fallback до повторного API-теста.
- [ ] Выполнить отдельный plugin review по запросу AGENTS: WordPress standards, security, cron duplication, DB load, memory issues, fatal-error risks.

## Next Editorial Quality Block

- [ ] Rejected-rate audit/fix: за последние 48 часов создано `85` queue rows: `published=53`, `rejected=23`, `error=9`; rejected-rate около `27%`, это слишком много для здорового автопилота.
- [x] Расширенный аудит глубже 48h выполнен по доступным SQL-снапшотам без импорта в live DB: активная `ep_epv2_queue` хранит только `85` строк за `2026-04-26 22:49:43` – `2026-04-28 17:48:31`, потому что `queue_retention_days=3`; backup-таблица `ep_epv2_queue_backup_pre_20260401_new` пустая.
- [x] По SQL-снапшотам найдено `354` уникальные queue rows за `2026-04-01 04:00:05` – `2026-04-28 05:48:49`: `published=280`, `rejected=51`, `ready_publish=9`, `error=9`, `duplicate=3`, `retry_process=1`, `new=1`.
- [x] Более длинная история подтвердила системность rejected/scoring проблемы: средний score почти не разделяет опубликованные и отклонённые (`published=45.3`, `rejected=44.3` по снапшотам; в live active `47.4` vs `47.0`).
- [x] Основные rejected buckets по снапшотам: `selection_reject=25`, `selection_low=14`, прочие/старые причины `12`; главные rejected sources: `Google News DE Top Test=15`, `Deutschlandfunk Nachrichten=12`, `UNIAN=5`, `ARD Tagesschau=4`, `Google News Ukraine=3`.
- [x] Основные rejected categories по снапшотам: `politik=11`, `ukraine=9`, `deutschland=9`, `wirtschaft=6`, `sport=5`; это не одна плохая рубрика, а общий gate/calibration дефект.
- [x] Первый системный фикс истории внедрён live: добавлена таблица `ep_epv2_selection_audit`, autoload `EPV2_Selection_Audit`, schema upgrade через installer и запись всех решений collector stage/ingest (`selection_reject`, `not_publish_grade`, `ai_gate_block`, `category_active_load_cap`, `queued` и т.д.).
- [x] `scripts/epv2_selection_calibration_audit.php` обновлён: принимает positional days (`-- 7`) и выводит summary по `selection_audit`; read-only WP-CLI проверка прошла, таблица доступна.
- [x] Controlled collect после audit table выполнен: run `25154`, `processed_sources=56`, `collected_items=7`, `error_count=0`; созданы queue rows `1092-1098`, все остались в `new`.
- [x] `ep_epv2_selection_audit` начал писать реальные решения: за контрольный сбор зафиксировано `692` audit rows (`selection_reject=488`, `duplicate_precheck=119`, `staged_candidate=35`, `freshness_block=31`, `not_publish_grade=8`, `queued=7`, `hard_editorial_block=4`).
- [x] Найдены два свежих класса ложной классификации: `transport` ошибочно давал `sport`, а `FC Bayern/Paris Saint-Germain/Champions League` уходил в `bayern` из-за source/local bias.
- [x] В repo и live внедрён фикс категоризации: sport-keywords стали word-boundary safe, добавлен sport-context disambiguation, source bias `bayern/muenchen` игнорируется для футбольного контекста; контроль: `1093=deutschland`, `1097=sport`.
- [x] В repo и live внедрён динамический freshness-window: сильные `A/B`, trusted primary serious `C`, service/community и time-sensitive материалы теперь получают разные окна вместо грубого silent drop.
- [x] Автоматизация намеренно поставлена на паузу после обнаружения риска: `epv2_collect_paused=1`, `epv2_automation_paused=1`; не возобновлять до рекалибровки и проверки rows `1092-1098`.
- [x] Память обновлена для следующей сессии; попытка live WP-CLI dry-run рекалибровки была остановлена системой approval/usage limit, поэтому live DB не изменялась после постановки паузы.
- [ ] Немедленный следующий шаг: запустить patched `scripts/epv2_recalibrate_new_items.php` в dry-run и apply; ожидаемо `1093` должен перейти в `deutschland`, `1097` должен стать `rejected` как low-grade football item, остальные `1092/1094/1095/1096/1098` остаются `new` с обновлёнными score/category.
- [ ] После рекалибровки проверить queue states, `admin_notes`, `category_proposed`, `story_score`, cluster primary category и только потом решать, можно ли снимать `epv2_automation_paused`.
- [ ] Проверить `ep_epv2_selection_audit`: распределение outcome/source/category/score после controlled collect и рекалибровки, найти false reject уже среди всех кандидатов, а не только среди тех, что попали в queue.
- [ ] Следующий кодовый пакет: на основе audit rows отделить hard reject от salvageable borderline; для `review/strong` и сильных `C` не допускать silent drop по рубричной перегрузке, а применять динамический порог/резерв/добор источников.
- [ ] Сделать нормальную историю для будущих редакционных аудитов до конца: сейчас есть audit table для новых решений; нужно решить retention/cleanup policy для `epv2_selection_audit`, чтобы хранить минимум 30 дней без DB-перегруза.
- [ ] Разобрать свежие `23` rejected не вручную, а системно: `15` ушли как `selection decision "reject"`, `8` как `low`; дублей среди них нет (`duplicate_reason=NULL`).
- [ ] Проверить и исправить selection scoring: средний score почти одинаковый у `published` и `rejected` (`47.4` vs `47.0`), значит gate режет не только явно слабое.
- [ ] Провести отдельный audit предварительной оценки материала: как на старте считаются `score`, `tier A/B/C/D` и `decision priority/strong/review/low/reject`; сейчас общий код: `A>=70`, `B>=52`, `C>=34`, `D<34`, а `C` ниже `40` становится `low`.
- [ ] Не переходить механически на “публиковать только A/B”: это может убить нормальные информативные новости, где нет прямой пользы, но есть общественная/редакционная значимость. Проверить модель: `A/B` как быстрый autopublish, сильный `C` как publishable после source/quality/media gates, `D` как reject.
- [ ] Разделить scoring dimensions: `важность`, `полезность для читателя`, `информативность`, `новизна`, `релевантность аудитории`, `редакционное разнообразие`, `source confidence`, `media/source completeness`; не сводить всё к “пользе”.
- [ ] Настроить scorecards индивидуально по рубрикам: политика/мир/Украина требуют public-impact и source confidence; жизнь в Германии/community требуют практической пользы; культура/спорт могут быть информативными без утилитарной пользы; Мюнхен/Бавария требуют локальной релевантности.
- [ ] Заменить грубые дневные лимиты рубрик на динамические per-category thresholds: повышать/понижать порог входа по рубрике на основе текущего потока, дефицита рубрики, важности события и качества источника, но не блокировать сильные события только из-за квоты.
- [ ] Сделать calibration set из последних `published/rejected/error` за 48h: вручную пометить минимум 30-50 материалов как `publish / maybe / reject`, сравнить с `A/B/C/D`, найти false rejects и false accepts.
- [ ] Исправить canonical slug drift в `EPV2_Budget_Manager`: там ещё встречаются старые `world`/`münchen`, а runtime уже должен работать на `welt`/`muenchen`; это может занижать score и ломать рубричный баланс.
- [ ] Проверить false “old/archive” reason: несколько свежих материалов возрастом `3-7h` получили причину “старая или архивная тема без новой ценности”; нужно отделить реальную устарелость от слабого single-source/low-consensus сигнала.
- [ ] Source-level rejected audit: главные источники rejected за 48h: `Google News DE Top Test=9`, `Deutschlandfunk Nachrichten=6`, `Google News Ukraine=2`; проверить не дают ли они слишком много слабого/однотипного входа или не режется ли качественный материал из-за gate.
- [ ] Ввести rejected-rate метрики в bridge/admin: rejected per 24h, rejected/source, rejected/category, false-reject candidates, score overlap published vs rejected.
- [ ] Добавить системный media-text fit gate: изображение должно соответствовать теме, сущностям и контексту текста; пример ошибки для регресса: материал о мигрантской политике в Баварии получил фото стадиона футбольной `Bayern München`.
- [ ] Проверить уже опубликованные посты за последние 24-48 часов на расхождение `текст -> фото -> подпись -> source credit`; спорные случаи исправить и внести как regression fixtures.
- [ ] Усилить source-first media: перед fallback на Pexels/Wikimedia проверять primary/source dossier images, entity/geography/topic relevance и blacklist ложных омонимов вроде `Bayern` football club vs Bavaria policy.
- [ ] Провести source portfolio audit: выключить источники, которые стабильно не дают usable content, 403/404/interstitial/пустые тексты или однотипный мусор.
- [ ] Расширить источники по рубрикам: Munich, Bavaria, Germany, world, Ukraine, society, culture, sport beyond football, Ukrainian communities/associations in Germany and Bavaria.
- [ ] Построить source quality score: usable-content rate, freshness, duplicate rate, media availability, topical value, error rate.
- [ ] Найти баланс новостей по рубрикам не грубым дневным лимитом, а динамической моделью: учитывать дефицит рубрик, важность события, diversity, freshness, source confidence и текущий поток.
- [ ] Проверить спорт: убрать однотипный футбольный перекос, добавить другие виды спорта и социально значимые спортивные новости, но не душить сильные события жёстким лимитом.

## Current Runtime Status 2026-04-27

- [x] Systemic stabilization plan created: `docs/epv21-systemic-stabilization-plan-2026-04-27.md`
- [x] Live plugin backup created before systemic pass: `backups/epv21-live-plugin-pre-systemic-20260427-193120.tar.gz`
- [x] DB backup created before systemic pass: `backups/epv21-db-pre-systemic-20260427-193120.sql`
- [x] Canonical publish gate implemented: `EPV2_Publish_Gate`
- [x] Gate wired into `ready_publish`, fast-ready path, queue `mark_state`, publish selector, and acceptance snapshot
- [x] Current pathological loops terminalized:
- [x] `1022 -> error/workflow_quarantine`
- [x] `1029 -> rejected/selection_publish_blocked`
- [x] `1036 -> error/workflow_quarantine`
- [x] `1042 -> error/workflow_quarantine`
- [x] Workflow-stage circuit breaker added for worker/AI provider failures
- [x] Hard pre-AI editorial filters added for obvious low-value candidate classes
- [x] Bridge incident counters added: `low_reject_published_24h`, `terminal_quarantine_rows`, `stale_retry_rows`
- [x] Regression check added: `scripts/epv2_systemic_stabilization_check.php`
- [x] Maintenance ping-pong fixed: soft rejected rows are not auto-reactivated in auto publish-grade mode
- [x] Verification passed: live PHP lint, site `301`, no `500/502`, worker/orchestrator active, regression `ok=true`
- [x] Controlled collect `25052`: `0` new, `0` errors after hard filters
- [ ] Wait for new quality candidates and prove fresh `new -> active -> ready_publish -> published` series
- [ ] Prove `low_reject_published_24h = 0` after historical 24h incident window expires
- [ ] Add DB/generated columns or indexes for hot JSON workflow fields
- [ ] Add operator UI for quarantine reason and safe reset action
- [ ] Decide whether to review/unpublish historical low/reject posts from the incident window

- STOPPING IS FORBIDDEN UNTIL THE AUTOMATION WORKS FULLY AUTONOMOUSLY END-TO-END WITHOUT HANGS, MANUAL RESCUE, RANDOM QUEUE STALLS, OR BROKEN PUBLISH FLOW.
- DO NOT TREAT `ready_review` AS A NORMAL DESTINATION FOR RECOVERABLE NEWS. IF A MATERIAL CAN BE FIXED, ENRICHED, REWRITTEN, TRANSLATED, OR COMPLETED, THE SYSTEM MUST CONTINUE AUTOMATICALLY.
- DO NOT SWITCH TO REPORTING, STATUS UPDATES, OR PARTIAL SUMMARIES INSTEAD OF WORK. THE ONLY ACCEPTABLE FINISH STATE IS A STABLE AUTONOMOUS PIPELINE THAT COLLECTS, PROCESSES, QUALITY-CHECKS, MOVES TO `ready_publish`, AND PUBLISHES ON ITS OWN.
- ANY PATCH, REVIEW, OR UI WORK MUST SERVE THE SINGLE GOAL OF FULL AUTONOMOUS AUTOMATION. IF IT DOES NOT MOVE THE SYSTEM TOWARD THAT GOAL, IT IS NOT PRIORITY WORK.

## EXECUTION RULE

- Не останавливаться на промежуточных отчётах.
- Не писать `продолжаю`, `работаю дальше` или аналогичные статусы без следующего реального действия.
- Для задач по автоматизации считать работу завершённой только после проверяемого end-to-end результата, а не после локального фикса.
- Любой найденный системный сбой:
  1. занести в журнал ошибок,
  2. классифицировать,
  3. исправить в коде системно,
  4. перепроверить на живой цепочке.
- Контекст новости хранить до подтверждённой публикации.
- Для слабых новостей source enrichment обязателен.
- `Pexels/Wikimedia` только крайний fallback, а не стандартный путь.
- Manual confirmation только для реально критичных media-cases.

## CURRENT ACCEPTANCE CRITERION

- [ ] Подтвердить `10` материалов подряд по пути:
- [ ] `Новый -> В работе -> Готов к публикации -> Опубликован`
- [ ] Без ложного `ручного подтверждения`
- [ ] Без фальшивого `в работе`
- [ ] Без зависаний в `publish_finish`
- [ ] С релевантным media
- [ ] С пройденными `quality/release/google`

# CURRENT GLOBAL BLOCKERS

- [x] Queue-wide payload contract migration added for stale persisted rows
- [x] Regression harness added: `/root/projects/europulse/scripts/epv2_queue_contract_check.php`
- [x] Live queue contract normalization executed without site degradation
- [x] Stale persisted payload violations reduced to zero by machine check
- [ ] Canonical publication policy must be enforced machine-wide
- [x] publication rules expanded in `/root/projects/europulse/docs/epv2-publication-rules.md`
- [ ] rewrite/quality validators must enforce headline/dek/lead/body size contract
- [ ] media validators must enforce source-first + source-credit contract
- [ ] duplicate validators must enforce `event_key + material_delta`
- [ ] Next blocker class: recoverable translation failures still can fall into `ready_review`
- [ ] Remove `ready_review` as a normal sink for auto-recoverable translation path
- [ ] Add regression check that fails on recoverable translation cases ending in `ready_review`

## 7. Deep Architecture Blockers For `EPV2 v2.1`

- [ ] Queue selector must become read-only:
- [ ] `next_item_for_processing()` must stop mutating payload/state during selection
- [ ] `has_processable_items()` must stop calling mutating selection logic
- [ ] payload normalization and promotion must move out of hot selection path
- [ ] Orchestration surface must be reduced:
- [ ] remove remaining heavy orchestration dependency on `admin_init`
- [ ] reduce duplicate scheduling surface across cron / async hooks / `spawn_cron()` / fallbacks
- [ ] make one primary process trigger and one primary publish trigger
- [ ] HTML ingest must hard-validate responses before parsing:
- [ ] reject bad HTTP statuses
- [ ] reject non-HTML content types
- [ ] reject interstitial / consent / 403 / 429 pages earlier
- [ ] Review path must stay lightweight:
- [ ] review page must not trigger heavy enrichment on open
- [ ] review render path must use stored payload/snapshot first
- [ ] move expensive recovery/build steps out of plain review rendering
- [ ] Worker transport must stay locked down:
- [ ] keep token auth mandatory
- [ ] review local HTTP trust model
- [ ] remove weak transport assumptions where possible
- [ ] Cleanup/resilience must be narrowed:
- [ ] split maintenance responsibilities into smaller predictable passes
- [ ] avoid broad payload/notes/state rewrites in one cleanup sweep
- [ ] Media/publish quality must stay source-first:
- [ ] source media before stock libraries
- [ ] dossier/supporting-source media before Wikimedia/Pexels
- [ ] semantic relevance checks before accepting fallback media
- [ ] Admin/UI code must be simplified over time:
- [ ] reduce large static controller/render classes
- [ ] separate render, persistence prep, and orchestration logic
- [ ] keep operator UI from executing pipeline side effects on view

## 8. Current Status Of The Eight Major Audit Points

- [x] Worker client fatal and broken key retrieval fixed
- [x] Worker auth restored from the original insecure `v2.1` state
- [x] Queue/runs schema indexes strengthened
- [x] Ghost `started` runs without active lock handled
- [x] Orphan `processing_de` reclaim added
- [x] `translate_uk -> rebuild -> translate_uk` loop cut
- [ ] Review/operator path fully stabilized and fully lightweight
- [ ] Full autonomous live queue `new -> ready_publish -> publish` proven stable end-to-end
- [x] stale routing quality must not survive stage queueing
- [x] cheap payload normalization must refresh fast quality before checklist rebuild
- [x] mass-normalize current `new/ready_publish` rows after quality-cache fix
- [x] review metrics refresh must rebuild readiness checklist
- [x] publish gate shortcut must not trust stale `ready_publish`
- [ ] Queue selector refactored to read-only selection semantics
- [ ] Remaining orchestration duplication removed
- [ ] HTML ingest hard validation added
- [ ] Source-first media policy enforced across all live cases
- [ ] Recoverable items no longer fall into manual review as a normal path
- [ ] Publish-quality gates enforce real completion instead of partial completion

## 9. Execution Order `P1 -> P8`

### P1. Queue Selector Isolation

- [x] Make `next_item_for_processing()` selection-only
- [x] Remove payload normalization writes from selection path
- [x] Remove state promotions from selection path
- [x] Make `has_processable_items()` use a read-only existence query
- [x] Verify queue checks do not mutate state on plain reads

### P2. Orchestration Simplification

- [ ] Remove remaining heavy orchestration dependence on `admin_init`
- [ ] Keep one canonical process trigger
- [ ] Keep one canonical publish trigger
- [ ] Reduce cron duplication across recurring hooks / async hooks / `spawn_cron()` / fallbacks
- [ ] Verify opening wp-admin does not enqueue or mutate pipeline work unexpectedly

### P3. Ingest Hard Validation

- [ ] Validate HTTP status before parsing source HTML
- [ ] Validate content type before DOM parsing
- [ ] Reject 403 / 429 / consent / interstitial responses explicitly
- [ ] Ensure bad source responses cannot become queue candidates

### P4. Review Path Stabilization

- [ ] Keep review screen on stored payload / lightweight snapshot path
- [ ] Prevent heavy enrichment on review page open
- [ ] Prevent plain review render from causing pipeline side effects
- [ ] Verify review page stays fast and deterministic on repeated opens

### P5. Resilience And Cleanup Narrowing

- [ ] Split broad cleanup responsibilities into smaller passes
- [ ] Reduce broad payload/note rewrites in maintenance path
- [ ] Keep orphan reclaim predictable and cheap
- [ ] Verify cleanup does not create random state churn

### P6. Source-First Media Enforcement

- [ ] Force primary source media evaluation first
- [ ] Force dossier/supporting-source media before stock libraries
- [ ] Add semantic/geographic/entity relevance checks before accepting fallback media
- [ ] Verify sensitive topics do not get unrelated stock imagery
- [ ] Preserve `media_credit`, `media_caption`, and `media_origin_url` on publish-grade items
- [ ] Ban generated covers for ordinary autopublished news

### P7. Recoverable Item Completion

- [ ] Eliminate `ready_review` as a normal destination for recoverable items in auto mode
- [ ] Keep one recoverable item advancing stage by stage until completion
- [ ] Confirm every stage transition only happens after checklist confirmation
- [ ] Verify items no longer stall before `ready_publish` when they are fixable

### P8. Final Publish-Grade Automation Proof

- [ ] Prove `new -> processing -> quality completion -> ready_publish` without manual rescue
- [ ] Prove publish queue advances without random stalls
- [ ] Prove media remains source-first on real queue cases
- [ ] Prove posts reach final site contract correctly
- [ ] Prove autonomous cycle is stable on live queue, not just on isolated items
- [ ] Prove the above on `10` consecutive live materials, not `1-2` isolated successes

### P9. Event-Level Duplicate Control

- [x] Replace lexical story dedupe with `event_key`
- [x] Build `event_key` from topic family + essential event tokens
- [x] Add `material_delta` gate for follow-up publication
- [x] Compare duplicate candidates against both `published` and active queue
- [x] Cut liveblog duplicates across different sources inside the same event window
- [x] Confirm regression on the Iran ceasefire duplicate family (`454` vs `466`)

# TODO

## 2026-03-30 Session Carryover

- [x] Добавить payload-contract invariant для persisted queue state
- [x] Добавить queue-wide migration helper в `EPV2_AI_Processor`
- [x] Добавить regression helper и standalone harness для queue contract
- [x] Прогнать live normalization: `checked 53 / changed 41 / violations 0`
- [ ] Следующий blocker class:
- [x] автоматический translation failure не должен по умолчанию уходить в `ready_review`
- [x] нужен automatic terminal split вместо manual sink для recoverable translation path
- [x] мигрировать старые translation-manual rows (`354`, `421`)
- [ ] следующий blocker:
- [x] media manual sink (`364`, `370`) не должен оставаться normal destination для auto queue
- [x] auto rows без explicit manual marker (`395-398`) не должны оседать в `ready_review`
- [x] добавить invariant: `mode=auto` не может сохраняться в `ready_review` без explicit manual marker
- [x] прогнать migration `--resolve-auto-ready-review`
- [x] убедиться regression harness, что `auto_ready_review_sink` исчез
- [x] добавить invariant: `retry_process` должен хранить тот же stage, что уже требует `payload_required_stage()`
- [x] прогнать migration `--repair-retry-stage-contract`
- [x] убедиться regression harness, что `retry_process_stage_drift` исчез
- [ ] следующий blocker:
- [x] после выравнивания queue contract подтвердить live process progression без возврата в stale sink/state-drift
- [x] добавить invariant: `publish_finish` не может жить на incomplete translation contract
- [x] добавить regression issue `publish_finish_incomplete_translation_contract`
- [x] добавить migration `--repair-publish-finish-translation-contract`
- [x] подтвердить live, что `419` больше не падает в `de_master_failed_requeued_to_rebuild` на следующем pass
- [ ] следующий blocker:
- [ ] довести `publish_finish -> ready_publish -> published` серией, а не единичным кейсом
- [ ] доказать, что `publish_finish` больше не откатывается в translation/rebuild loops на серии item
- [x] закрыть stale quality cache loop, который держал owner в ложном `publish_finish/translate` цикле
- [ ] подтвердить на серии, что после stale-quality fix successive owners доходят до `ready_publish`
- [x] закрыть false `ready_publish` после review-metrics refresh
- [x] подтвердить подряд `470 -> published` и `467 -> published`

- [x] Найти причину вылета `tmux`/тормозов `Termius`: подтверждён `OOM` на сервере `2026-03-30 22:11:59 UTC`
- [x] Поставить хостовую защиту от memory-pressure для `wp-cli` cron:
- [x] `systemd-run` transient units с лимитами памяти для `europulse-safe-cron`
- [x] emergency watchdog `europulse-memory-guard.service/.timer`
- [ ] Прогнать live-наблюдение на следующем memory spike и при необходимости подкрутить пороги:
- [ ] `MemoryHigh/MemoryMax/MemorySwapMax` в `/usr/local/bin/europulse-safe-cron.sh`
- [ ] аварийные пороги в `/usr/local/bin/europulse-memory-guard.sh`
- [x] Найти причину broken manual mode для non-DE source URLs
- [x] Исправить ручной режим `EPV2 v21`, чтобы master всегда строился на немецком:
- [x] `to_review` теперь идёт через `EPV2_AI_Processor::generate_review_payload(..., true)`
- [x] ручные `rewrite_title/rewrite_excerpt/rewrite_content/seo_optimize` теперь жёстко требуют немецкий output
- [ ] Проверить реальным кейсом в live admin/WP-CLI:
- [ ] импортировать URL не на немецком
- [ ] убедиться, что `ai_payload.languages.de` сохранён на немецком
- [ ] убедиться, что `translations_deferred = true`
- [ ] убедиться, что дальше работают `translate_uk` и `translate_en`
- [ ] Отдельно спроектировать UI/UX для ручного режима:
- [ ] добавить явную кнопку `доделать пакет / продолжить автоматически`
- [ ] добавить явные кнопки/статусы для запуска переводов из `de` master
- [ ] не оставлять `ready_review` как конечную точку для recoverable manual items
- [ ] Доработать первопричину memory spikes в `epv2_process`, а не только аварийный guard:
- [ ] найти, какой именно участок `wp-cli`/pipeline раздувает `php` до GiB
- [ ] сократить memory footprint long-running `process` path
- [ ] подтвердить, что новые лимиты больше не выбивают `tmux`/SSH

## 0. Блокировка Рисков

- [x] Выключить live-автопубликацию `EPV3`
- [x] Остановить `EPV3` cron
- [x] Снять тестовые `EPV3` посты с публикации
- [ ] Не включать `EPV3` auto-mode до завершения пунктов 1-6

## 1. Карта Сайта

- [x] Зафиксировать, как сайт реально показывает новости
- [x] Подтвердить, что главная строится не простым loop, а shortcode-блоками:
- [x] `europulse_top_slider`
- [x] `europulse_home_latest`
- [x] `europulse_section_module`
- [x] Подтвердить, что витрина берёт посты через `europulse_home_zone_ids()` / `europulse_home_section_ids()`
- [x] Подтвердить зависимость витрины от meta-контракта поста:
- [x] `_epv2_queue_id`
- [x] `_epv2_primary_category`
- [x] `europulse_story_topic`
- [x] `europulse_story_format`
- [x] `europulse_popular_score`
- [x] `europulse_breaking`
- [x] `europulse_top_story`
- [x] Подтвердить зависимость карточек от `featured image`
- [x] Подтвердить, что Polylang участвует в URL/термах/связке переводов
- [x] Выписать полный frontend contract в отдельный документ

## 2. Карта Дашборда EPV2

- [x] Снять фактическую структуру меню `EPV2`
- [x] Обзор
- [x] Источники
- [x] Очередь
- [x] Настройки
- [x] Ручной режим
- [x] Проверка материала
- [x] Логи
- [x] Запуски
- [x] Снять фактические действия `admin_post`
- [x] Пуск / Пауза автоматики
- [x] Collect / Process / Publish one-shot
- [x] Prune queue / reset stats
- [x] Управление источниками
- [x] Review -> ready_publish
- [x] Publish now
- [x] Regenerate field
- [x] Manual import / manual rewrite / manual to review
- [x] Выписать dashboard contract `EPV2` в отдельный документ

## 3. Правила Публикации И Очереди

- [x] Зафиксировать реальные состояния `EPV2` очереди:
- [x] `new`
- [x] `processing_de`
- [x] `retry_process`
- [x] `ready_review`
- [x] `ready_publish`
- [x] `publishing`
- [x] `published`
- [x] `error`
- [x] Зафиксировать правила queue UI:
- [x] живая очередь
- [x] ручная проверка
- [x] готово к публикации
- [x] опубликованные
- [x] архив опубликованных
- [x] countdown до collect / publish
- [x] фильтры по state / category / sorting
- [x] Зафиксировать правила publish gating:
- [x] publish only if payload publish-ready
- [x] featured media обязательно
- [x] категория и taxonomy обязательны
- [x] multilingual bundle связан через Polylang
- [x] пост должен получить frontend meta-контракт
- [x] Выписать формальный publish contract `EPV2 -> site`

## 3A. Последовательный Pipeline Без Права Бросить Новость

- [x] Зафиксировать единственный допустимый путь новости:
- [ ] `source_collected`
- [ ] `candidate_filtered`
- [ ] `context_analyzed`
- [ ] `dossier_enriched`
- [ ] `de_master_rewritten`
- [ ] `media_resolved`
- [ ] `seo_resolved`
- [ ] `translations_ready`
- [ ] `publish_validated`
- [ ] `ready_publish`
- [ ] `published`
- [ ] Для каждого шага описать:
- [ ] входные данные
- [ ] выходные данные
- [ ] критерий успеха
- [ ] критерий провала
- [ ] retry-механику
- [ ] следующий допустимый state
- [ ] Запретить переход к следующему шагу, пока предыдущий не подтверждён
- [ ] Запретить перескок через стадии
- [ ] Запретить переводы до полного завершения `DE master`
- [ ] Запретить `ready_publish` до завершения:
- [ ] контекстной проверки
- [ ] dossier enrichment
- [ ] media validation
- [ ] SEO/meta/tag validation
- [ ] release/google validation
- [ ] Не бросать новость, если проблема recoverable:
- [ ] слабый dossier
- [ ] слабый rewrite
- [ ] слабое или отсутствующее media
- [ ] слабый SEO/meta block
- [ ] слабые переводы
- [ ] Разрешить отказ только для:
- [ ] пустого/битого источника
- [ ] безусловного дубля
- [ ] явного мусора/нерелевантного спама
- [ ] юридически/редакционно недопустимого контента

## 4. Контентный Контур, Который Нельзя Потерять

- [x] Зафиксировать, что текущая проблема не в ядре очереди, а в качестве контента
- [ ] Вынуть из `EPV2` и описать:
- [ ] intake/filter rules
- [ ] body-first context analysis
- [ ] category/priority decision rules
- [ ] enrichment rules и minimum dossier
- [ ] DE-first rewrite rules
- [ ] citation/source-link rules
- [ ] media attribution/import rules
- [ ] SEO/meta/tag rules
- [ ] release/google quality rules
- [ ] правила перевода `UK/EN` только после готового `DE`
- [ ] Привязать эти правила к конкретным классам `EPV2`
- [ ] Вынуть и перенести редакторские промпты `EPV2`:
- [ ] `auto_rewrite`
- [ ] `analysis_rewrite`
- [ ] `manual_rewrite`
- [ ] prompt flags из настроек `EPV2`
- [ ] Зафиксировать обязательный стандарт текста:
- [ ] качественный рерайт, а не перепаковка исходника
- [ ] уникальность текста 90%+
- [ ] живой, лёгкий, интересный слог
- [ ] уровень подачи не ниже сильных мировых изданий
- [ ] без канцелярита
- [ ] без шаблонной AI-воды
- [ ] без однообразных заголовков и лидов
- [ ] Зафиксировать обязательный стандарт усиления:
- [ ] если материала мало, добирать 2-3 релевантных внешних источника
- [ ] строить dossier по смыслу текста, а не по одному заголовку
- [ ] усиливать фактуру до publish-grade, а не отправлять в ручной режим
- [ ] Зафиксировать обязательный стандарт цитирования и ссылок:
- [ ] ссылка на первоисточник обязательна
- [ ] source block обязателен
- [ ] цитаты только с понятной атрибуцией
- [ ] Зафиксировать обязательный стандарт media:
- [ ] сначала релевантное media первоисточника
- [ ] если remote нестабилен, локальный import
- [ ] если media слабое, поиск релевантного media по dossier/context
- [ ] обязательные caption / attribution / source link

## 5. Новый План Для EPV3

- [ ] Не публиковать ничего из `EPV3`, пока не будет завершён этот блок
- [ ] Перестроить `EPV3` в таком порядке:
- [ ] сначала site contract
- [ ] затем dashboard contract
- [ ] затем content contract
- [ ] затем queue contract
- [ ] только потом live automation
- [ ] Сделать `EPV3` dashboard не хуже `EPV2`:
- [ ] обзор с реальным состоянием автоматики
- [ ] понятные проблемы
- [ ] источники
- [ ] живая очередь
- [ ] ручная проверка
- [ ] ready to publish
- [ ] published/archive
- [ ] manual mode
- [ ] review screen
- [ ] logs/runs
- [ ] Сделать `EPV3` publish compatible с фронтом сайта из коробки, а не через post-fix
- [ ] Сделать `EPV3` строго state-driven:
- [ ] один orchestrator
- [ ] один state machine path
- [ ] persisted stage checklist
- [ ] stage-specific retries
- [ ] без скрытых side effects в selector path
- [ ] Сделать `EPV3` quality-driven:
- [ ] оценки `100/100` только по реальным критериям качества
- [ ] никаких формальных `100` только из-за заполненных полей
- [ ] quality gate должен проверять текст, dossier, media relevance и publish contract
- [ ] Сделать `EPV3` content loop без права бросить recoverable материал:
- [ ] weak dossier -> enrichment retry
- [ ] weak rewrite -> rewrite retry
- [ ] weak media -> media retry
- [ ] weak SEO -> seo retry
- [ ] weak translations -> translation retry

## 6. Порядок Возврата В Live

- [ ] Сначала dry-run / manual-run без автопубликации
- [ ] Затем ручной прогон одного полного quality материала
- [ ] Затем проверка появления:
- [ ] в single
- [ ] в category archive
- [ ] в homepage latest
- [ ] в homepage slider
- [ ] Затем только limited auto-mode
- [ ] Затем полная автоматика
- [ ] Перед возвратом в auto-mode проверить:
- [ ] рерайт не однообразный
- [ ] dossier реально усиливается
- [ ] media релевантно теме
- [ ] citations/source links стоят корректно
- [ ] `ready_publish` не зависает дольше окна
- [ ] очередь идёт без ручного пинка

## Документы, Которые Надо Подготовить Сейчас

- [x] `/root/projects/europulse/docs/active-runtime-boundary-2026-04-10.md`
- [x] `/root/projects/europulse/docs/epv2-dashboard-contract.md`
- [x] `/root/projects/europulse/docs/epv2-publication-rules.md`
- [x] `/root/projects/europulse/docs/epv21-canonical-execution-plan-2026-04-09.md`
- [x] `/root/projects/europulse/docs/epv21-execution-todo-2026-04-09.md`
- [x] Архивные `epv3-*` документы больше не считать источником runtime-решений

## Что Делать Следующим Первым

- [x] Не писать новый контентный pipeline вслепую
- [x] Сначала собрать 5 документов:
- [x] карта интеграции сайта
- [x] контракт дашборда `EPV2`
- [x] правила публикации и качества `EPV2`
- [x] последовательный stage contract
- [x] prompt migration map из `EPV2`
- [ ] После этого только переписывать `EPV3`
# EPV2 Hard Automation Contract

- [ ] Выполнять автоматику строго по [epv2-semantic-enrichment-and-media-contract.md](/root/projects/europulse/docs/epv2-semantic-enrichment-and-media-contract.md) без смягчения логики
- [ ] Контекст каждого материала хранить до подтверждённой публикации и не чистить на промежуточных стадиях
- [ ] Каждый слабый материал обязан проходить обязательный semantic search pack и source-first enrichment до перевода и до `ready_publish`
- [ ] `Wikimedia` и `Pexels` использовать только как крайний fallback после исчерпания source-first media path
- [ ] В `Требует ручного подтверждения` переводить только критичные кейсы, которые не решаются автоматическим допоиском

## EPV2 Live Critical Path

- [x] Закрыть starvation class: stale infra/backlog `rebuild_bundle` не должен удерживать single process lane при появлении fresh `new`
- [x] Закрыть media dead-end class: source-grounded generated cover должен поднимать recoverable `publish_finish`, а не вести в terminal reject
- [x] Добавить class-level migration для media-blocked `publish_finish`, чтобы фикс применился к старым row, а не только к новым
- [ ] Подтвердить ближайший scheduled `ready_publish -> published` без ручного rescue
- [x] Подтвердить ближайший scheduled `ready_publish -> published` без ручного rescue
- [x] После первого due publish подтвердить серию минимум из двух автоматических публикаций подряд
- [x] Закрыть v2 process bottleneck: один wake должен прожимать одного owner через несколько steps, а не по одному step на 5 минут
- [x] Закрыть stale workflow metadata на `ready_publish/published`, чтобы terminal rows не выглядели незавершёнными
- [ ] Добить оставшийся `publish_finish` хвост:
- [ ] `417`
- [ ] `422`
- [ ] `433`
- [ ] отдельно классифицировать, почему `415` после media-fix ушёл в `rejected`
- [ ] Disable or repair dead source `MVG Betriebsmeldungen` (`id=34`, returns 404) so collect runs stop ending with one recurring error.
- [ ] Verify process lane after selection hardening: fresh `review/strong` items should progress one-by-one without weak-candidate clogging.
- [ ] Подтвердить ещё минимум два scheduled publish подряд для `449` и `453`
- [ ] Провести финальный quality audit по `3133`, `3139`, `3146` и следующим автопубликациям
- [x] Move collector fairness to semantic category before queue caps and planner.
- [x] Raise live collect limits from starvation settings (`1/1`) to workable publish-grade intake (`4/8`).
- [x] Add narrow borderline-news uplift in budget manager for serious stories stuck at `35-39`.
- [x] Fix rubrication drift for migration/world and justice/domestic-politics cases.
- [ ] Review active source pool and quarantine/archive non-news stale sources like evergreen/press-library feeds that generate only stale rejects.
- [x] Audit rejected queue by class instead of item-level chasing.
- [x] Fix false editorial media rejects caused by zero-byte `download_url()` on `merkur.de` / `fr.de` CDN images.
- [x] Add reusable rejected media audit script: `/root/projects/europulse/scripts/epv2_rejected_media_audit.php`
- [x] Stop bounded translation no-progress from immediately rejecting viable DE/master rows.
- [x] Clear stale manual-confirmation flags when translation rows are requeued back into automation.
- [x] Add reusable translation rehab script: `/root/projects/europulse/scripts/epv2_reactivate_translation_rejects.php`
- [ ] Confirm active rehab owner `421` drains cleanly through `translate_uk -> ... -> ready_publish/published`
- [ ] Drain returned translation/media rehab rows (`514`, `506`, `493`, `455`, `446`, `421`, `515`, `477`, `456`) without manual rescue
- [ ] Classify remaining true media rejects (`513`, `502`, `438`, `433`, `424`, `422`, `417`, `416`, `415`) into:
- [ ] `recoverable via source/supporting enrichment`
- [ ] `blocked by genuinely absent source-first media`
- [ ] `generated-cover contamination / policy reject`
- [ ] Close war coverage contract failures: wrong `community` routing for Ukraine/Russia military stories, soft/sympathetic framing, and semantically wrong war media.
- [ ] Drain rejected backlog by class after 2026-04-02 fixes: `context`, `translation`, `trim_new`, `planner_replace`, `migration`.
- [ ] Confirm selector never returns `none` while processable `new` rows exist.

## 2026-04-28 Live Chain Follow-Up

- [x] Проверить live AI/API: `EPV2_AI_Client::preflight()` вернул `ok=true`, основной провайдер ответил; fallback API key тоже задан.
- [x] Запустить controlled collect после фиксов: run `25126`, `56/56` sources, `2` collected items, `0` errors.
- [x] Подтвердить, что цепочка сама подхватила новый материал: `1081` прошел run `25127` через `rebuild_bundle`, затем run `25128` через `publish_finish` без ручного publish-push.
- [x] Исправить ложный `publish_ready_gate` loop: canonical gate теперь использует тот же strict media contract, что AI processor, и source context добирается из queue row.
- [x] Исправить scheduling ready-publish после исчерпания дневного time-sliced budget: новые ready rows получают ближайший будущий publish-budget slot, а не midnight reset.
- [x] Восстановить только gate-safe technical quarantine rows: `1075` и `1079` возвращены в `ready_publish`.
- [x] Оставить реально blocked media rows в quarantine: `1073`, `1074`, `1078` не восстановлены, потому что media contract still blocks.
- [x] Проверить сайт после deploy: `/` отвечает WordPress `301`, `/wp-login.php` отвечает `200`, без `500/502`.
- [x] Дождаться автоматического завершения `1081`: строка не зависла, а корректно ушла в `rejected` по canonical publish gate (`selection decision "reject"`).
- [x] Дождаться следующего автоматического шага `1082`: run `25130` завершил `publish_finish` с `error_count=0` и перевел row в `finalize_media`.
- [x] Дождаться финального исхода `1082`: строка корректно ушла в `rejected` по canonical publish gate (`selection decision "low"`), active owner cleared, `has_processable_items=false`.
- [ ] Дождаться publish slots `1075` / `1079`: `2026-04-28 09:22:00 UTC` и `2026-04-28 09:27:00 UTC` (`11:22` / `11:27` Berlin).
- [ ] После публикаций проверить acceptance streak заново; старый `low_reject_published_24h=20` пока исторический шум 24h окна.

## 2026-04-28 Server Cleanup

- [x] Провести read-only аудит размеров: диск `/` занят примерно на `37-38%`, критического давления нет.
- [x] Удалить безопасный scratch-мусор из `/tmp`: `epv2*`, `europulse*`, старые `playwright_*`, `playwright-artifacts-*`, `com.google.Chrome.*`.
- [x] Удалить старые локальные Playwright-артефакты: `/root/projects/europulse/output/playwright`.
- [x] Удалить локальный worker cache: `/root/projects/europulse/worker-v21/src/epv2_worker/__pycache__`.
- [x] Очистить завершенные WordPress upgrade temp-папки: `wp-content/upgrade/updraftplus-1.26.3-de_de`, `wp-content/upgrade-temp-backup/plugins`.
- [x] Перенести live `.bak` файлы из активного дерева плагина в `backups/live-plugin-file-baks-20260428-cleanup`, не удаляя откаты.
- [x] Проверить после уборки: `europulse-autopilot-v21` active, PHP lint clean для ключевых live классов, nginx/php-fpm/worker/orchestrator active, `/` `301`, `/wp-login.php` `200`, `queue_contract=ok`.
- [ ] Не чистить `uploads`, DB backups, repo backups и `.venv` без отдельного решения: это рабочие данные или воспроизводимые, но нужные runtime-зависимости.

## 2026-04-29 GPT-5-Mini Text Quality Test

- [x] Переключить live AI settings на primary `openai/gpt-5-mini`, fallback `deepseek/deepseek-chat`; безопасный config-check показывал ключи заданы, без вывода секретов.
- [x] Исправить PHP preflight для OpenAI reasoning models: `gpt-5*`/`o*` получают достаточный `max_completion_tokens` budget; live `EPV2_AI_Client::preflight()` вернул `AI provider responded`.
- [x] Прямой тест OpenAI `gpt-5-mini` через WP/PHP API прошёл: украинский заголовок для `Middle East live updates` стал нормальным (`Живі оновлення з Близького Сходу`), не калькой `стрічка`.
- [x] Controlled collect run `25165`: собрано `10` новых rows `1099-1108`, regular collect оставлен paused.
- [x] Проверить publish timer на свежем row: `1100` вошёл в `ready_publish` at `18:11:21 UTC`, опубликовался at `18:16:32 UTC`, posts `6668/6669/6670`, без немедленного bypass.
- [x] Редакторски проверить первый опубликованный пакет `1100`: DE/UK/EN заголовки и лиды по смыслу нормальные, медиа source-first от `bundesregierung.de`, сайт отвечал `/wp-login.php=200`.
- [x] Найти системную причину, почему production-тексты всё ещё не GPT-5-mini: Python worker падает на OpenAI calls с `AsyncCompletions.create() got an unexpected keyword argument 'max_completion_tokens'` из-за `openai==1.14.0`, затем уходит на DeepSeek fallback.
- [x] Repo patch для worker подготовлен и локально скомпилирован: `rewriter.py`, `translator.py`, `seo.py` передают `max_completion_tokens` через `extra_body`, пишут provider/model runtime; `pipeline.py/contracts.py` сохраняют `_meta.provider`, `_meta.model`, `_meta.ai_runtime`; `translator.py` добавил Ukrainian anti-calque guard для `Ticker/стрічка`.
- [ ] Перезапустить live `epv2-worker`, чтобы патч реально вступил в силу. Блокер: escalation/restart отклонён environment approval usage limit; не обходить.
- [ ] После restart прогнать свежий материал через worker и подтвердить в `ai_payload`: `_meta.provider=openai`, `_meta.model=gpt-5-mini`, `ai_runtime` содержит `rewrite_de/translate_uk/translate_en/seo_de`, а systemctl logs больше не показывают `unexpected keyword max_completion_tokens`.
- [ ] После подтверждения GPT-5-mini провести quality audit на всех языках минимум по 3 свежим материалам: заголовок, лид, основной текст, цитирование источника, отсутствие кальки, соответствие категории и медиа.
