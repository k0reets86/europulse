# CRITICAL DIRECTIVE

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

- [x] `/root/projects/europulse/docs/epv3-site-integration-map.md`
- [x] `/root/projects/europulse/docs/epv2-dashboard-contract.md`
- [x] `/root/projects/europulse/docs/epv2-publication-rules.md`
- [x] `/root/projects/europulse/docs/epv3-content-contract.md`
- [x] `/root/projects/europulse/docs/epv3-mandatory-knowledge-pack.md`
- [x] `/root/projects/europulse/docs/epv3-stage-sequence-contract.md`
- [x] `/root/projects/europulse/docs/epv3-prompt-migration-map.md`

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
