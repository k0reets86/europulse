# CRITICAL DIRECTIVE

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
