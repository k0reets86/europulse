# SESSION HANDOFF

## Active Runtime Boundary

- Canonical runtime only:
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v21`
  - `/root/projects/europulse/worker-v21`
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`
- Removed from active project tree on 2026-04-10:
  - legacy `worker/`
  - legacy `input/`
  - empty `screenshots/`
  - legacy `config/epv3-*`
- Any `v2` / `v3` references below are archival handoff context only and must not be treated as current runtime instructions.

## Update 2026-04-09 19:38:53 UTC

- Текущий рабочий репозиторий:
  - `/root/projects/europulse`
  - branch: `review/plugin-audit`
  - latest pushed commit: `11c69d3` (`Stabilize EPV2 automation and add worker-v21 stack`)
- Live paths:
  - WordPress root: `/var/www/europulse/public`
  - Live plugin: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`
  - Mirror in repo: `/root/projects/europulse/wp-plugins/europulse-autopilot-v21`
  - External worker/orchestrator code: `/root/projects/europulse/worker-v21`

- Архитектурный инвариант, который больше нельзя размывать:
  - WordPress должен оставаться shell для queue/admin/review/publish/site integration.
  - Тяжёлые операции должны жить в server-side worker/orchestrator слое.
  - REST bridge должен быть лёгким управляющим слоем, а не местом тяжёлых repair/cleanup циклов.

- Подтверждённые последние live-fix изменения:
  - `includes/api/class-epv2-rest.php`
    - `/bridge/publish` больше не force-publish, а уважает slot scheduling
    - `/bridge/maintenance` облегчён и не должен выполнять тяжёлые repair-проходы через HTTP
  - `includes/publish/class-epv2-publisher.php`
    - publish path переведён на fast due selector
    - устранён hang на preflight/multilingual gate
    - после publish reanchor remaining ready queue к фактическому времени публикации
  - `includes/queue/class-epv2-queue.php`
    - добавлен быстрый due-selector для publish
    - исправлена логика schedule reanchor
    - частично ослаблен финальный guard для review+ материалов с ready-like payload

- Последние подтверждённые live публикации после фикса publish-path:
  - `732 -> post 4592`
  - `742 -> post 4598`
  - `743 -> post 4605`
  - `745 -> post 4611`

- Live state на момент handoff:
  - queue states:
    - `published = 68`
    - `new = 15`
    - `rejected = 4`
    - `processing_de = 1`
  - active processing item:
    - `749`
    - `state = processing_de`
    - `category = politik`
    - `updated_at = 2026-04-09 17:12:53 UTC`
  - automation flags:
    - `automation_paused = false`
    - `collect_paused = true`
    - `server_orchestrator_enabled = true`
    - `next_ready_publish = null`
  - services:
    - `epv2-orchestrator.service = active`
    - `epv2-worker.service = active`
    - `epv2-runtime-watch.timer = inactive`

- Критичные открытые проблемы, которые нельзя забыть:
  - automation всё ещё нестабильна на process side:
    - новые материалы не всегда уходят в работу предсказуемо
    - хорошие материалы могут откатываться guard-логикой вместо нормальной доводки
    - один item может подвисать в `processing_de`
  - user не принимает модель, где хорошие материалы (`review/strong/priority`) попадают в обычный `rejected`
  - user требует repair-first logic вместо тупого discard
  - media pipeline должен быть `source-first`
    - в норме в 90% случаев featured media должно браться из первоисточника
    - внешнее медиа допустимо только как fallback
  - user просит обновлять project memory / handoff / mempalace, чтобы новая сессия не начиналась “с нуля”

- Зафиксированная редакционная/системная позиция пользователя:
  - reject допустим только для:
    - реального мусора
    - дублей
    - stale / нерелевантного материала
    - hard editorial blocks
  - технический сбой не должен маскироваться под обычное `rejected`
  - тяжёлые процессы должны быть на сервере, WordPress должен публиковать и держать state

- Следующий правильный рабочий порядок для новой сессии:
  1. не править вслепую
  2. сначала перечитать:
     - `docs/europulse-memory-brief.md`
     - `docs/epv2-error-knowledge-base.md`
     - этот `SESSION_HANDOFF.md`
  3. проверить live logs / queue по `749` и current selector state
  4. отдельно разбирать process lane, а не расползаться на весь сайт сразу
  5. не смешивать editorial reject с tech-failure cases

## Update 2026-04-02 10:20:00 UTC

- Закрыт ещё один class-level blocker `KB-072`:
  - `refresh_review_metrics()` обновлял quality metrics, но оставлял stale `stage_checklist.ready_publish`
  - `payload_ready_for_publish()` принимал stale checklist как shortcut и давал ложный publish-ready
- Изменения:
  - `includes/review/class-epv2-review.php`
    - `refresh_review_metrics()` now returns `EPV2_AI_Processor::normalize_existing_payload(..., false)`
  - `includes/ai/class-epv2-ai-processor.php`
    - `payload_ready_for_publish()` now requires live full gates even when `stage_checklist.ready_publish` is already set
  - `includes/publish/class-epv2-publisher.php`
    - publish path now hydrates full queue row before using `original_*` fields
  - `includes/queue/class-epv2-queue.php`
    - `force` publish testing path now truly bypasses `publish_not_before`
- Live confirmations:
  - `470`:
    - `new -> ready_publish -> published`
    - `post_id = 3250`
  - `467`:
    - after the publish-gate fix also passed
    - `ready_publish -> published`
    - `post_id = 3256`
  - another next item `469` received terminal `rejected_by_context`, did not stall the lane
  - selector moved on to `471`
- Site stayed stable:
  - `curl 200`
  - `php8.3-fpm active`
  - `nginx active`

## Update 2026-04-02 10:13:00 UTC

- Закрыт новый class-level blocker `KB-071`:
  - stale quality metadata переживала `queue_next_processing_stage()`
  - из-за этого owner мог крутиться по ложному контуру:
    - `publish_finish -> translate_uk -> translate_en -> publish_finish`
- Root cause:
  - next stage persist path писал stage поверх stale `_meta.quality`
  - `fast_stage_routing_quality()` доверял старому quality cache
  - `normalize_existing_payload(..., false)` не обновлял cheap quality перед checklist refresh
- Изменения:
  - `includes/ai/class-epv2-ai-processor.php`
    - `queue_next_processing_stage()` теперь always re-finalizes payload before persisting next stage
    - `fast_stage_routing_quality()` now recomputes from current DE fields instead of reusing cached `_meta.quality`
    - `normalize_existing_payload(..., false)` now refreshes fast quality before `refresh_stage_checklist()`
- Live migration:
  - normalized current `new/ready_publish` rows
  - changed rows: `1`
- Live confirmations after patch:
  - `467`:
    - escaped the old loop
    - became `ready_publish`
    - `quality=100`, `release=100`, `google=100`
  - `470`:
    - also progressed automatically to `ready_publish`
  - selector then moved on to the next `new` (`469`)
- Site stayed stable:
  - `curl 200`
  - `php8.3-fpm active`
  - `nginx active`
  - no runaway `wp cron`

## Update 2026-04-02 10:40:00 UTC

- Собран и записан новый canonical document:
  - `/root/projects/europulse/docs/epv2-publication-rules.md`
- Документ теперь объединяет:
  - headline contract
  - dek/lead contract
  - body structure
  - length bands for short / standard / long / analysis materials
  - source-first media rules
  - source credit / caption preservation
  - duplicate and follow-up publishing policy
- Зафиксирован новый системный duplicate class:
  - одна и та же Iran ceasefire story была опубликована дважды:
    - queue `454` / posts `3174-3176`
    - queue `466` / posts `3242-3244`
  - обе истории имели `topic_label = Iran and the Middle East`, но разные `cluster_id`
    - `454 -> cluster_id 422`
    - `466 -> cluster_id 434`
- Root cause duplicate class:
  - `EPV2_Story_Clusters::cluster_key()` строится lexical-способом из title/excerpt stems
  - `EPV2_Deduplicator::titles_are_semantically_close()` слишком завязан на overlap title tokens
  - cross-source liveblogs одной темы могут расходиться в разные clusters и проходить как отдельные публикации
- Новый обязательный invariant:
  - duplicate gate должен работать по `event_key + material_delta`, а не только по lexical similarity
- TODO обновлён:
  - publication rules должны быть внедрены в prompts/validators/gates
  - duplicate layer должен быть переведён на event-level dedupe
  - нужен regression case на Iran ceasefire duplicate family

## Update 2026-04-02 09:25:00 UTC

- Publication rules внедрены в live code точечно:
  - `includes/core/class-epv2-settings.php`
    - default rewrite prompts now enforce headline/dek/body structure and format lengths
  - `includes/ai/class-epv2-ai-processor.php`
    - system prompt and translation prompt now enforce:
      - informative headline
      - 2-sentence lead
      - body priority order
      - source-first real media instead of generated cover
  - `includes/ai/class-epv2-ai-response-validator.php`
    - editorial quality now checks:
      - headline range
      - generic headline detection
      - lead sentence count
      - lead size
      - format-aware body ranges
      - filler-phrase detection
    - release/google quality now require:
      - no generated cover
      - media origin url
      - media credit
    - payload enrichment now writes:
      - `media_origin_url`
      - `media_credit`
      - `media_caption`
- Event-level duplicate fix implemented:
  - `includes/queue/class-epv2-deduplicator.php`
    - added `event_key`
    - compares against recent published posts and active queue
    - uses `material_delta_exists()`
  - `includes/ingest/class-epv2-collector.php`
    - stores `event_key` in queue `admin_notes`
  - `includes/publish/class-epv2-publisher.php`
    - stores `_epv2_event_key`, `_epv2_media_origin_url`, `_epv2_media_credit`, `_epv2_media_caption`
- Live checks:
  - site stayed `200 OK`
  - syntax clean on all touched PHP files
  - queue items `454` and `466` now produce the same `event_key`
  - synthetic Iran ceasefire candidate is now blocked before queue insert as duplicate

## Update 2026-04-01 17:55:00 UTC

- Повтор live-loop на item-level остановлен после подтверждения класса старых polluted payload rows.
- Закрыт новый системный класс `KB-054`:
  - persisted queue payload мог хранить stale `translations_deferred`
  - root `media_url/featured_media_url` мог хранить non-image article URL
  - из-за этого process/publish resume paths повторно входили в старые stage/media traps даже после локальных кодовых фиксов.
- В `includes/ai/class-epv2-ai-processor.php` добавлено:
  - `normalize_payload_contract_flags()`
  - `normalize_persisted_queue_contracts()`
  - `queue_contract_regression_check()`
- Добавлен воспроизводимый harness:
  - `/root/projects/europulse/scripts/epv2_queue_contract_check.php`
- Live migration выполнена:
  - checked `53`
  - changed `41`
  - исправлены polluted payload rows, включая `420`, `434`, `438`, `441`, `443`
- Live regression check после migration:
  - `violations = []`
- Сайт во время migration/check оставался стабилен:
  - `curl 200`
  - `php8.3-fpm active`
  - `nginx active`
  - cron без runaway процессов
- Следующий blocker class после этого:
  - translation-manual sink уже закрыт:
    - `354`
    - `421`
    ушли в automatic terminal outcome через bounded auto-repair + reject split
  - следующий blocker class теперь сместился на `media` manual sink (`364`, `370`) и отдельный auto `ready_review` sink без explicit manual marker (`395-398`)

## Update 2026-04-01 18:00:00 UTC

- Закрыт новый системный класс `KB-055`:
  - recoverable translation failures больше не отправляются в `ready_review`
  - после bounded auto-repair система делает terminal split:
    - recovery to next stage/state
    - либо automatic reject if translation remains unsalvageable
- Добавлено в `includes/ai/class-epv2-ai-processor.php`:
  - `resolve_translation_no_progress_terminally()`
  - `resolve_persisted_translation_manual_reviews()`
- Обновлён harness:
  - `/root/projects/europulse/scripts/epv2_queue_contract_check.php --resolve-translation-manual`
- Live migration выполнена:
  - `354 -> rejected_translation_unsalvageable`
  - `421 -> rejected_translation_unsalvageable`
- Regression check после migration:
  - `violations = []`
- Сайт при этом оставался стабилен:
  - `curl 200`
  - `php8.3-fpm active`
  - `nginx active`
  - no cron storm

## Update 2026-04-01 18:10:00 UTC

- Закрыт ещё один queue-contract blocker class `KB-056` на уровне инварианта и migration path:
  - `mode=auto` rows не имеют права сохраняться в `ready_review`, если нет explicit `manual_confirmation_required`
  - такие rows должны схлопываться в:
    - `ready_publish`, если payload уже terminal-ready
    - либо `retry_process`, если automation contract ещё продолжается
- В `includes/queue/class-epv2-queue.php`:
  - `canonicalize_single_workflow_state()` теперь получает текущую row + incoming extra и запрещает сохранять illegal `auto ready_review sink`
- В `includes/ai/class-epv2-ai-processor.php`:
  - regression harness теперь ловит `auto_ready_review_sink`
  - добавлен migration helper:
    - `resolve_persisted_auto_ready_review_sinks()`
- В harness:
  - `/root/projects/europulse/scripts/epv2_queue_contract_check.php`
  - добавлен флаг:
    - `--resolve-auto-ready-review`
- Live до migration уже подтверждено:
  - `media_manual_sink` схлопнут migration’ом:
    - `364 -> rejected_media_manual_sink`
    - `370 -> rejected_media_manual_sink`
  - regression после этого был `violations = []`
- Следующий immediate step:
  - прогнать live migration для `395-398`
  - убедиться, что `auto_ready_review_sink` больше не остаётся в очереди
  - только потом возвращаться к следующему class-level blocker

## Update 2026-04-01 18:12:00 UTC

- `KB-056` доведён до live-confirmed migration:
  - `395-398` больше не висят в illegal `ready_review`
  - all were moved to `retry_process`
  - regression harness after migration: `violations = []`
- Следующий повторившийся системный класс оказался уже не sink, а state drift:
  - `retry_process` rows хранили empty/stale `pipeline_stage`, хотя `payload_required_stage()` уже требовал конкретный stage
  - live series before fix:
    - `395-398`, `375-377`, `380+` required `rebuild_bundle`
    - `419` required `translate_uk`
- Закрыт новый class-level invariant `KB-057`:
  - `retry_process` persistence must align stored `pipeline_stage` with `payload_required_stage()`
- Изменения:
  - `includes/core/class-epv2-resilience-manager.php`
    - `schedule_retry()` now calls `attach_retry_process_stage_contract()`
  - `includes/ai/class-epv2-ai-processor.php`
    - regression issue `retry_process_stage_drift`
    - migration helper `repair_persisted_retry_process_stage_contract()`
  - harness:
    - `--repair-retry-stage-contract`
- Live migration выполнена:
  - repaired `20` rows
  - examples:
    - `395-398 -> rebuild_bundle`
    - `375-377 -> rebuild_bundle`
    - `419 -> translate_uk`
  - post-check showed:
    - `retry_process` stages now grouped as `rebuild_bundle=22`, `translate_uk=1`
    - drift query returned `[]`
  - site stayed stable:
    - `curl 200`
    - `php8.3-fpm active`
    - `nginx active`
    - no cron storm

## Update 2026-04-01 19:00:00 UTC

- Зафиксирован и системно разрезан новый runtime blocker class `KB-058`:
  - `publish_finish` запускался на неполном translation contract
  - это позволяло live path ходить:
    - `publish_finish -> translate_uk`
    - а в более слабом варианте ещё и падать в `de_master_failed_requeued_to_rebuild`
- Инвариант:
  - `publish_finish` допустим только при complete `UK/EN` bundle
  - incomplete translation contract обязан оставаться на `translate_uk/translate_en/translate_finish`
- Изменения в `includes/ai/class-epv2-ai-processor.php`:
  - added `publish_finish_requires_translation_repair()`
  - generic `auto_finish` branch no longer enters `run_publish_finish_stage()` prematurely
  - `run_publish_finish_stage()` now short-circuits to translation repair when bundle is incomplete
  - regression issue added:
    - `publish_finish_incomplete_translation_contract`
  - migration helper added:
    - `repair_persisted_publish_finish_translation_contract()`
- Harness updated:
  - `/root/projects/europulse/scripts/epv2_queue_contract_check.php --repair-publish-finish-translation-contract`
- Live checks after patch:
  - syntax: clean
  - site: `curl 200`
  - `php8.3-fpm/nginx active`
  - cron still canonical, no runaway swarm
  - migration run:
    - `resolved = []`
    - regression `violations = []`
- Live proof on repeated blocker item `419`:
  - before fix repeated bad history included:
    - `5198`: `publish_finish -> de_master_failed_requeued_to_rebuild`
    - `5200`: `publish_finish -> translate_uk`
  - after fix:
    - `5201`: `translate_uk -> translate_en`
    - `5202`: `translate_en -> publish_finish`
  - critical old `de_master_failed_requeued_to_rebuild` did not reproduce on the next pass after patch
- Current blocker after this update:
  - pipeline now returns `419` to `publish_finish` cleanly
  - next step is to prove `publish_finish -> ready_publish -> published` on a series, not just one successful translation-side recovery

## Update 2026-03-28 23:45:00 UTC

- Live/runtime work продолжен уже на реальном `EPV2 v2.1` в `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`.
- Подтверждён и исправлен live-blocker в orchestration:
  - `EPV2_Jobs::recover_orphan_process_lock()` держал fast recovery thresholds `45s/90s`;
  - после перевода process execution в inline cron/CLI mode длинные `rebuild_bundle` runs (`100-200s`) ошибочно считались hung;
  - эти fast ветки удалены, recovery оставлен только на нормальном stale window от lock TTL.
- Подтверждён и исправлен summary/full-row mismatch в AI path:
  - queue selector отдаёт summary rows;
  - `process_scheduled()` и `generate_review_payload()` местами всё ещё ожидали `original_content/original_date`;
  - добавлен targeted full-row fetch только для baseline path без payload;
  - добавлен lazy hydration внутри AI generation/publish-grade lift вместо возврата к глобальному full-row selector.
- Подтверждён новый runtime status после fixes:
  - live `wp cron event run epv2_process` завершился за `103.807s`, а не был убит fast orphan recovery;
  - `process #4186` прошёл полностью и завершился `rejected_by_context` для item `346` без summary warnings и без ложного зависания;
  - `process #4185` завершился `stagnated_rebuild_cooldown` для item `345`, то есть transport/orphan layer уже не основной blocker.
- Усилен rebuild escalation для weak items:
  - `translate_finish_rebuild` теперь тоже bump’ает rebuild attempt counter;
  - options для `generate_review_payload()` на rebuild path теперь attempt-aware:
    - больше supporting sources на повторных циклах;
    - больший runtime budget;
    - reuse `context_memory`.
- Дожат ещё один live loop case:
  - item `345` оказался не recoverable news, а generic service page Bahn (`/service/fahrplaene`);
  - forced supporting search стабильно давал `supporting_count = 0`;
  - в `EPV2_Budget_Manager::analyze_contextual()` добавлен terminal reject для non-news service/landing pages без supporting context и без event signal;
  - live `process #4196` после этого завершился `rejected_by_context`, item `345` перешёл в `rejected` вместо очередного rebuild loop.
- Live positive signals после этих фиксов:
  - item `350` прошёл `translate_uk -> translate_en -> ready_publish`
  - item `335` тоже дошёл до `ready_publish`
  - publish tick не висит; `ready_publish` scheduling соблюдается по `publish_not_before`
- На `2026-03-29` дожат ещё один stage-router blocker на live:
  - active loop сместился на item `354`, который ходил `publish_finish <-> rebuild_bundle`
  - root cause: translation readiness ошибочно зависела от final featured media
  - в `de_master_ready_for_translation()` убрана зависимость от final media
  - live proof после sync:
    - `payload_next_required_stage(354)` стал возвращать `translate_uk`
    - `process #4407` перевёл item `354` в `translate_en`
    - `process #4408` перевёл item `354` в `publish_finish`
    - `process #4409` уже не вернул item в старый `publish_finish/rebuild` loop, а честно сделал `requeued_translate_uk_after_rebuild`
  - это означает, что broken stage gating разрезан; дальше остаётся уже content/media progression, а не ложный router trap
- Новые кейсы занесены в KB:
  - `KB-021` false hung recovery on long inline process rebuild
  - `KB-022` summary row leaked into full AI generation path
  - `KB-023` generic service page incorrectly treated as recoverable rebuild candidate
  - `KB-024` translation path incorrectly blocked by final media gate
- Что остаётся главным blocker после этого update:
  - pipeline больше не валится на transport/orphan layer;
  - текущий главный хвост теперь content-stage:
    - weak items (`344`, `351`, текущий live case `354`) всё ещё требуют серии rebuild/translation/publish_finish проходов до `ready_publish`
    - причины:
      - `AI rewrite did not reach minimum DE master quality`
      - `publish threshold stalled rebuild bundle`
      - missing source-first media / publish-grade finish after translation
  - следующий practical step: резать уже узкий media/publish-finish blocker на live weak cases, пока больше материалов не начинают стабильно выходить в `ready_publish/published`.

## Update 2026-03-28 23:59:00 UTC

- Продолжение работ по `EPV2 v2.1` уже в repo:
  - `queue` page и `queue_snapshot` переведены в read-only render path:
    - убран `prune_rejected()` из `EPV2_Admin::queue()`
    - убран `normalize_non_active_recoverable_items()` из `EPV2_Admin::queue_snapshot()`
  - `EPV2_Jobs` сузили orchestration hot path:
    - удалён мёртвый Action Scheduler layer из `dispatch_async()`
    - фактический async transport оставлен один: `wp_schedule_single_event()` для `*_async`
    - `spawn_cron()` оставлен только как ограниченный kick вне cron/ajax контекста
    - тяжёлая часть `maintain_runtime_state()` получила throttle `15s`
    - orphan lock recovery оставлен без throttle, чтобы не терять fast recovery
  - `EPV2_Review` разделён на lightweight open path и commit validation path:
    - `REVIEW_METRICS_TTL` реально задействован
    - review open больше не обязан пересчитывать heavy metrics на каждом открытии
    - полный refresh review metrics теперь делается перед:
      - `save_review`
      - `queue_to_publish`
      - `review_ready_publish`
      - `publish_now`
  - из `EPV2_Publisher::publish_scheduled()` убран повторный broad cleanup sweep:
    - больше нет дублирующего `EPV2_Resilience_Manager::cleanup()`
    - больше нет дублирующего `EPV2_Queue::prune_rejected(1440)`
    - publish tick теперь не повторяет тяжёлую maintenance-работу поверх уже выполненного orchestration/runtime pass
  - сузили row fetches в hot queue/publish paths:
    - добавлен `EPV2_Queue::get_item_summary()`
    - `mark_state()`, `set_live_status()`, `focused_automation_item()`, `has_active_processing_item()` переведены на summary fetch
    - `EPV2_Publisher::promote_publishable_review_items()` больше не делает ручной `SELECT *`
  - сузили maintenance churn в `EPV2_Resilience_Manager`:
    - убран отдельный duplicate pass `normalize_failed_de_master_stages()`
    - stage-fix `minimum DE master quality -> rebuild_bundle` оставлен внутри `normalize_retry_metadata()`
    - `normalize_stale_error_items()`, `recover_worker_infra_errors()`, `restore_rejected_reviewable_items()` переведены на summary fetch
  - сузили queue hot-path fetches:
    - `next_item_for_processing()`
    - `next_stage_resume_item()`
    - `next_auto_resume_item()`
    - `promote_publish_ready_payloads()`
    - `normalize_terminal_retry_process_items()`
    - `next_item_for_publish()`
    - `next_ready_publish_timestamp()`
    - `normalize_ready_publish_schedule()`
    - `next_publish_slot_for_queue()`
    - `has_active_processing_item()`
    теперь используют `get_queue_items_summary()` вместо implicit full-row fetch
  - добавлен row-level decode cache в `EPV2_Queue`:
    - `row_payload()`
    - `row_notes()`
    - hot queue helpers больше не гоняют repeated `json_decode()` на одном и том же row object
    - при live-normalization payload cache сбрасывается вручную
  - из `EPV2_Publisher` убран глобальный cache flush anti-pattern:
    - `synchronize_published_bundle_taxonomy()` больше не вызывает `wp_cache_flush()`
    - `synchronize_published_bundle_from_payload()` больше не вызывает `wp_cache_flush()`
    - вместо этого добавлен scoped `clean_bundle_post_caches()` только по затронутым post IDs
  - снижён post-meta write churn в `EPV2_Publisher`:
    - добавлены `update_post_meta_if_changed()` и `delete_post_meta_if_exists()`
    - `_epv2_*`, Rank Math и editorial meta в publish/sync paths теперь пишутся идемпотентно
- В knowledge base занесены новые системные кейсы:
  - `KB-010` admin queue view side effects
  - `KB-011` orchestration dead async layer + maintenance churn
  - `KB-012` heavy review-open validation churn
  - `KB-013` duplicate cleanup inside publish executor
  - `KB-014` full-row fetch overuse in hot queue/publish paths
  - `KB-015` duplicate resilience pass over same recoverable states
  - `KB-016` implicit full-row fetch in queue hot selection/scheduling paths
  - `KB-017` repeated json_decode churn on same queue row
  - `KB-018` global wp_cache_flush in publisher bundle sync path
  - `KB-019` repeated unchanged post meta writes in publisher sync path
- Пользователь подтвердил жёсткий критерий завершения для `EPV2 v2.1`:
  - `10` материалов подряд обязаны пройти путь:
    - `Новый`
    - `В работе`
    - `Готов к публикации`
    - `Опубликован`
  - без ложного `ручного подтверждения`
  - без фальшивого `в работе`
  - без зависаний в `publish_finish`
  - с релевантным media
  - с пройденными `quality/release/google`
- Пользователь отдельно зафиксировал продуктовые правила:
  - если материал слабый, обязателен допоиск по семантике и source enrichment
  - `Pexels/Wikimedia` допускаются только как крайний fallback
  - контекст материала хранится до подтверждённой публикации
  - ручное подтверждение допускается только для реально критичных media-cases
  - если материал ушёл в ручное подтверждение, он не должен оставаться `В работе`
- Создан и заполнен рабочий журнал ошибок:
  - `/root/projects/europulse/docs/epv2-error-knowledge-base.md`
- Создан и зафиксирован жёсткий контракт по enrichment/media:
  - `/root/projects/europulse/docs/epv2-semantic-enrichment-and-media-contract.md`
- Что реально дожато в коде к этому моменту:
  - `active` item закреплён только за реальным `processing_de`
  - неактивные recoverable items нормализуются обратно в `new`
  - `retry_after` сохраняется и учитывается даже для `new`
  - разрезан rebuild loop через run-level guard для `rebuild_bundle`
  - failed `DE master quality` больше не оставляет item в `translate_finish`, а возвращает в `rebuild_bundle`
  - после release из `process/publish` оба контура заново диспетчеризуются автоматически
  - `ready_publish` отделён от `В работе`, publish queue и process queue могут идти параллельно
  - `review/manual` больше не должен быть нормальным путём для recoverable слабых кейсов
- Что ещё не доказано и остаётся главным хвостом:
  - полная автономная серия `10` подряд на live ещё не подтверждена
  - слабые one-source cases всё ещё могут требовать несколько циклов `rebuild_bundle`
  - source-first enrichment/media contract ещё нужно окончательно доказать на живой серии материалов
- Последние наблюдавшиеся live-кейсы:
  - `334` выведен из ложного loop-а и стоит как `new + rebuild_bundle + retry_after`
  - `342` был доведён до `ready_publish`
  - `344` нормализован из ложного `translate_finish` в `rebuild_bundle`
  - `345` тоже принудительно возвращён в `rebuild_bundle`, а не оставлен в переводческом хвосте
- Последний явный глобальный план работ:
  1. добить weak/rebuild path так, чтобы слабые материалы не зацикливались без роста dossier/supporting/media
  2. доказать стабильный handoff `process -> next process` и `ready_publish -> publish`
  3. прогнать и подтвердить серию из `10` материалов подряд
  4. все новые repeatable сбои сразу заносить в `epv2-error-knowledge-base.md`

## Update 2026-03-27 00:00:00 UTC

- Проведён повторный глубокий аудит `EPV2 v2.1` в repo:
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v21`
- Зафиксирована обновлённая карта глобального взаимодействия компонентов:
  - `bootstrap` поднимает plugin lifecycle
  - `plugin` регистрирует core subsystems
  - `jobs` управляет orchestration/scheduling
  - `ingest` собирает кандидатов из RSS/HTML/прочих источников
  - `queue` хранит state и приоритеты материалов
  - `ai processor` собирает и чинит payload/stages/translations
  - `source enricher` строит dossier/context/supporting sources
  - `media` выбирает featured/inline media
  - `review/admin` даёт операторский контур
  - `publisher` создаёт WP posts, postmeta, taxonomy и Polylang links
  - `resilience manager` лечит retries/orphans/review states
- Зафиксирована карта интеграции плагина с сайтом:
  - запись в `wp_posts` / `wp_postmeta`
  - установка taxonomy/meta-контракта публикации
  - работа через Polylang:
    - `pll_set_post_language`
    - `pll_save_post_translations`
  - featured image / attachments
  - cron / async hooks
  - wp-admin экраны / ajax / admin-post handlers
  - optional local worker
- Повторно подтверждены 8 главных архитектурных блокеров:
  1. selector очереди всё ещё не read-only и мутирует state/payload в hot path
  2. orchestration surface всё ещё слишком широкая: cron + async + `spawn_cron()` + admin hooks
  3. HTML ingest всё ещё принимает плохие HTML-ответы без жёсткой валидации статуса/типа
  4. review path облегчён, но всё ещё остаётся тяжёлым controller/render маршрутом
  5. worker transport security улучшена, но модель всё ещё минимально достаточная
  6. resilience/cleanup всё ещё слишком широкая и многозадачная
  7. media/publish path всё ещё нуждается в строгом source-first и relevance enforcement
  8. admin/UI код остаётся перегруженным большими статическими классами и смешением обязанностей
- Что уже считается реально улучшенным по сравнению с исходной `v2.1`:
  - исправлен worker client fatal
  - исправлено чтение ключей
  - восстановлен worker auth
  - усилены DB indexes
  - облегчен review open path через `ensure_payload_for_review()`
  - включено долгоживущее `context_memory` до публикации
  - queue UI стал прозрачнее:
    - `Автодоработка`
    - `Созданные черновики`
  - safe-mode больше не должен автоматически сливать `ready_publish` в `draft_created`
- Что остаётся главным техническим хвостом:
  - не одна конкретная fatal-ошибка, а архитектурная смесь selector side effects + orchestration duplication + weak ingest validation + still-heavy review/admin path
- `TODO.md` обновлён:
  - в верхнюю часть добавлены 8 архитектурных блокеров
  - отдельно зафиксирован статус закрытых и не закрытых крупных пунктов
  - добавлен жёсткий порядок исполнения `P1 -> P8`:
    - `P1` queue selector isolation
    - `P2` orchestration simplification
    - `P3` ingest hard validation
    - `P4` review path stabilization
    - `P5` resilience/cleanup narrowing
    - `P6` source-first media enforcement
    - `P7` recoverable item completion
    - `P8` final publish-grade automation proof

## Update 2026-03-26 22:25:00 UTC

- По текущему требованию пользователя зафиксирован жёсткий принцип для `EPV3`:
  - новость нельзя бросать, если проблема recoverable
  - слабый материал нужно усиливать через dossier из дополнительных источников
  - media подбирается по контекстному анализу, если media первоисточника отсутствует или не подходит
- В repo и live `EPV3` синхронно усилен runner:
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-runner.php`
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v3/includes/core/class-epv3-runner.php`
- Что изменено в runner:
  - recoverable ошибки больше идут через stage-aware retry helper
  - dossier/media/final quality payload сохраняются в item до retry
  - retry delay стал зависеть от стадии, а не всегда одинаковый
- Синтаксис проверен:
  - repo `class-epv3-runner.php` -> ok
  - live `class-epv3-runner.php` -> ok
- Созданы и заполнены документы-контракты:
  - `/root/projects/europulse/docs/epv3-site-integration-map.md`
  - `/root/projects/europulse/docs/epv2-dashboard-contract.md`
  - `/root/projects/europulse/docs/epv2-publication-rules.md`
  - `/root/projects/europulse/docs/epv3-content-contract.md`
  - `/root/projects/europulse/docs/epv3-stage-sequence-contract.md`
  - `/root/projects/europulse/docs/epv3-prompt-migration-map.md`
- `TODO.md` обновлён под эти контракты:
  - `/root/projects/europulse/TODO.md`
- Главный следующий шаг:
  1. перенести редакционные промпты и style flags из `EPV2` в `EPV3`
  2. заменить базовый DE rewrite на knowledge-driven DE master builder
  3. затем усиливать dossier/media/translation handlers уже по этим правилам

## Update 2026-03-26 21:45:00 UTC

- Пользователь остановил попытки прямой live-автоматизации `EPV3` до завершения полноценного анализа сайта и старого плагина.
- Зафиксирован новый порядок работ:
  1. разобрать механику сайта
  2. разобрать фактический dashboard contract `EPV2`
  3. разобрать фактические publication rules `EPV2`
  4. только после этого продолжать `EPV3`
- `TODO.md` полностью пересобран под этот план:
  - `/root/projects/europulse/TODO.md`
- Что уже подтверждено анализом:
  - главная страница сайта собирается shortcode-блоками из `europulse-foundation`, а не обычным WP loop
  - витрина зависит от post meta-контракта:
    - `_epv2_queue_id`
    - `_epv2_primary_category`
    - `europulse_story_topic`
    - `europulse_story_format`
    - `europulse_popular_score`
    - `europulse_breaking`
    - `europulse_top_story`
    - featured image
  - `EPV2` dashboard имел 8 обязательных разделов:
    - обзор
    - источники
    - очередь
    - настройки
    - ручной режим
    - проверка материала
    - логи
    - запуски
  - `EPV2` dashboard имел критичные функции:
    - пуск/пауза автоматики
    - ручной collect/process/publish
    - queue filters/sorting/countdowns
    - source CRUD/test
    - review -> ready_publish
    - publish now
    - regenerate field
    - manual draft/import/rewrite/to_review
  - `EPV3` нельзя дальше развивать без полного повторения этого контракта и без точной привязки к frontend contract сайта
- Live safety state:
  - `EPV3` auto-mode выключен
  - `EPV3` cron снят
  - тестовые `EPV3` посты сняты в `draft`
  - live больше не должен засоряться автопубликацией чернового качества

## Update 2026-03-26 20:58:00 UTC

- `EPV2` на live выключен, но не удалён; остаётся reference/fallback.
- `EPV3` активен на live:
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v3`
  - repo mirror:
    - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3`
- В `EPV3` уже работают:
  - собственные таблицы `ep_epv3_queue`, `ep_epv3_runs`
  - stage machine
  - batched orchestrator:
    - несколько process-stage transitions за один tick
    - несколько publish attempts за один tick
  - многостраничная админка:
    - `epv3-dashboard`
    - `epv3-sources`
    - `epv3-queue`
    - `epv3-settings`
    - `epv3-manual`
    - `epv3-review`
    - `epv3-logs`
    - `epv3-runs`
- Все новые страницы `EPV3` были прогнаны через `wp eval` render-check и не дали fatal.
- Live proof автономного нового контура:
  - item `#8`:
    - сам дошёл до `ready_publish`
    - затем опубликован как post `2679`
  - item `#6`:
    - был `ready_publish`
    - затем сам опубликован после наступления `publish_not_before` как post `2680`
  - item `#9`:
    - сам прошёл `ingested -> initial_filtered -> context_analyzed -> dossier_built -> de_master_ready -> media_ready`
    - затем дошёл до `ready_publish`
- Для published posts `EPV3` уже пишет meta:
  - `_epv3_context_score`
  - `_epv3_seo_score`
  - `_epv3_google_score`
  - `_epv3_release_score`
  - `_epv3_featured_source_url`
  - `_epv3_featured_attribution`
  - `_epv3_featured_attribution_url`
- Проверенный live post:
  - post `2679`
  - status `draft`
  - `CTX=100`
  - `SEO=100`
  - `GOOGLE=100`
  - `RELEASE=100`
- Ключевые новые файлы/правки в `EPV3`:
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-plugin.php`
    - полноценная админка и admin-post actions
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-orchestrator.php`
    - batched tick
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-runner.php`
    - process/publish cycle теперь возвращают bool и дренируются в loop
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-queue-repository.php`
    - `find()` и `get_items()`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-runs-repository.php`
    - `get_items()`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-dossier-builder.php`
    - multi-query dossier build через Google News RSS fallback
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-de-master-builder.php`
    - AI DE prompt усилен
    - добавлен guaranteed quote fallback
- Что ещё НЕ готово до production finish:
  - dossier всё ещё часто остаётся только с `primary source` на synthetic/live-simple кейсах; supporting sources не гарантированы
  - DE rewrite / UK / EN уже валидны, но ещё не доведены до полного editorial уровня `EPV2`
  - media flow пока ещё source-url/attribution oriented; локальный import attachment и более жёсткий relevance fallback нужно доделать
  - published content ещё нужно довести до финального editorial format:
    - полноценные цитаты в теле
    - richer source block
    - tags/meta polish
- Первый следующий шаг:
  1. проверить, что `#7` и `#9` публикуются сами после наступления окна
  2. затем усиливать именно реальные external supporting sources и media-import path
  3. после этого доводить editorial formatting published content

## Update 2026-03-26 19:45:00 UTC

- По явному решению пользователя старый черновик `EPV3` удалён полностью и собран заново с чистого листа.
- `EPV2` не удалён; остаётся reference/fallback и источник знаний.
- Новый `EPV3` теперь стартует как минимальный clean-slate каркас, без переноса логики старого черновика:
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/europulse-autopilot-v3.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/bootstrap.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-installer.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-settings.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-stage-machine.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-queue-repository.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-orchestrator.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-runner.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v3/includes/core/class-epv3-plugin.php`
- Что уже заложено в новом каркасе:
  - отдельные таблицы `epv3_queue` и `epv3_runs`
  - явная stage machine:
    - `ingested`
    - `initial_filtered`
    - `context_analyzed`
    - `dossier_built`
    - `de_master_ready`
    - `media_ready`
    - `uk_ready`
    - `en_ready`
    - `publish_ready`
    - `published`
  - отдельный repository для очереди
  - один orchestrator tick
  - короткий runner без legacy branching
- Что принципиально НЕ сделано в новом `EPV3` пока:
  - нет ещё body analyzer
  - нет dossier enrichment
  - нет DE rewrite
  - нет media attribution/search
  - нет translation pipeline
  - нет publish integration with WP posts
- Важный принцип на следующий ход:
  - не смешивать новый `EPV3` с логикой старого черновика
  - переносить в `EPV3` только знания из `EPV2`, а не его orchestration/retry хаос
- Следующий первый шаг:
  1. зафиксировать реальные editorial/pipeline rules из `EPV2` в docs/config
  2. после этого строить stage handlers в таком порядке:
     - intake filter
     - body context analyzer
     - dossier builder
     - DE master builder
     - media stage
     - UK/EN stages
     - publish delay/publish stage

## Update 2026-03-26 19:05:00 UTC

- Принято стратегическое решение:
  - `EPV2` не удалять
  - `EPV2` оставить как `reference/fallback`
  - основной дальнейший путь: не бесконечно латать legacy, а собрать новый `EPV3`
- Причина смены стратегии:
  - несмотря на большой объём fixes, live automation всё ещё не достигла состояния `полностью автономна и предсказуема`
  - особенно проблемны:
    - кривой отбор новых новостей
    - нестабильный auto-queue progression
    - непредсказуемый `ready_publish`
    - тяжёлый/залипающий `publish_finish`
  - цена дальнейшего patch-by-patch для `EPV2` уже выглядит выше, чем цена чистой реализации `EPV3`
- Очень важно:
  - нельзя потерять накопленные редакционные знания из `EPV2`
  - в `EPV2` зашиты не только баги, но и ценная логика:
    - промпты
    - правила отбора
    - body-based context analysis
    - style/editorial rules
    - source enrichment
    - media attribution
    - цитирование
    - ссылки на первоисточник
    - DE-first порядок
    - publish gates
- Для этого создан отдельный knowledge document:
  - `/root/projects/europulse/docs/epv3-mandatory-knowledge-pack.md`
- Новый обязательный порядок работ:
  1. не удалять и не ломать `EPV2`
  2. извлечь из `EPV2` knowledge pack
  3. использовать knowledge pack как спецификацию для `EPV3`
  4. только потом писать новый чистый plugin/engine
- Что считать правильным `EPV3`:
  - `intake`
  - `body-context analysis`
  - `enrichment from other sources`
  - `DE master`
  - `media with correct attribution/source link`
  - `UK/EN` только после финального `DE`
  - `publish_ready`
  - delayed publish after full extra cycle
- Что делать первым в следующем ходе:
  - не чинить ещё один случайный хвост в `EPV2`
  - а расширить и заполнить knowledge pack по фактическому коду `EPV2`
  - затем создать структуру нового `EPV3`

## Update 2026-03-26 11:45:00 UTC

- Текущий live-узел локализован точнее:
  - `303` больше не уходит в ложный `rebuild_bundle` после готовых `UK/EN`
  - у `process #2272` уже подтверждено:
    - `pipeline_stage = publish_finish`
    - `requires_fresh_rebuild = 0`
  - это значит, что главный ложный rebuild-loop для готового multilingual bundle разрезан
- Что дополнительно изменено в live + mirror:
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php`
    - внутренний 300s self-throttle в `process_scheduled()` отключён для `WP_CLI + server_orchestrator`
    - добавлен `refresh_payload_stage_markers()`:
      - пересчитывает `quality/seo/release/google/context/checklist`
      - не запускает полный тяжёлый `enrich_payload()`
    - stage-payload для `translate_uk|translate_en|translate_finish|publish_finish` теперь перед routing обновляется через lightweight refresh
    - `payload_needs_publish_finish_fast()` расширен:
      - теперь считает repairable не только `slug/seo/media`, но и
      - `слабый lead`
      - `поверхностность`
      - `невалидн.*индексац`
      - `сломанная языковая версия`
    - `payload_requires_fresh_rebuild_fast()` больше не форсирует rebuild, если:
      - `UK/EN` уже готовы
      - `translations_deferred = false`
      - remaining warnings относятся к `publish_finish` классу
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/core/class-epv2-worker-bridge.php`
    - translation worker теперь:
      - сначала пытается bounded translate
      - затем падает в полный translate pipeline
      - stage считается успешным только если целевой язык реально заполнен и валиден
    - post-translation refresh теперь идёт через lightweight `refresh_payload_stage_markers()`, а не через тяжёлый full finalize
- Live progression по `303` после этих правок:
  - `2265 -> queued_translate_uk_stage`
  - `2266 -> queued_translate_en_stage`
  - `2267 -> queued_publish_finish_stage`
  - `2272 -> after_stage_flags: requires_fresh_rebuild=0`
- Остаточный live-риск:
  - несколько старых started runs (`2268`, `2269`, `2271`) пришлось закрывать вручную как `manual_stale_process_recovered`, потому что они стартовали до последних fixes
  - automation уже сама подхватывает новые `process` runs, но legacy live-state всё ещё мешает чисто валидировать последний шаг `publish_finish -> ready_publish`
- Следующий первый шаг:
  - не продолжать чинить это поверх legacy live-state бесконечно
  - использовать новый shadow-runner для изолированного прогона queue item через внешний worker без записи в live queue
  - на нём дожать `publish_finish`/SEO/media до стабильного `ready_publish`
  - затем переносить этот path в production

## Update 2026-03-26 11:25:00 UTC

- Выполнен новый системный пакет стабилизации по итогам code audit:
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/jobs/class-epv2-jobs.php`
    - `maybe_schedule()` и `maybe_kick_pipeline()` теперь не запускают side effects на обычных фронтовых web hits
    - async bugs исправлены:
      - `run_collect_async()` больше не делает `true || $force`
      - `run_publish_async()` больше не делает `true || $force`
    - прямые SQL к Action Scheduler теперь обёрнуты guard-проверкой существования таблиц
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/core/class-epv2-resilience-manager.php`
    - `cleanup()` теперь не запускается на обычных frontend hits; только `cli/wp-cli/cron/admin/ajax`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/ingest/class-epv2-html-reader.php`
    - добавлена общая проверка `HTTP status` и `content-type` для document/listing fetch
    - теперь non-2xx и явно non-html ответы не принимаются как валидный источник
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/core/class-epv2-installer.php`
    - добавлены составные индексы:
      - `epv2_queue(state, updated_at)`
      - `epv2_queue(state, created_at)`
      - `epv2_queue(category_final, state)`
      - `epv2_runs(job_name, status, started_at)`
- Это пакет на снижение системной нагрузки и дублей, а не на ослабление quality gates.
- Следующий первый шаг:
  - синхронизировать этот пакет в live plugin
  - прогнать `php -l`
  - выполнить schema upgrade на live
  - проверить реальные индексы в MySQL
  - затем продолжить разрезание тяжёлого worker path и hot-path queue writes

## Update 2026-03-26 10:24:30 UTC

- Системный DE-first pipeline на live реально доведён до рабочего stage-router:
  - `rebuild_bundle -> translate_uk -> translate_en -> publish_finish -> ready_publish`
  - generic `translate_finish` loop разрезан
  - staged items теперь подхватываются server orchestrator без 5-минутного самоблокирования по `has_recent_started()`
- Что изменено в live + mirror:
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/jobs/class-epv2-jobs.php`
    - recent-run guard для `server_orchestrator_enabled=1` снижен:
      - `process/publish = 20s`
      - `collect = 120s`
  - `/var/www/europulse/worker/epv2_orchestrator.php`
    - orchestrator tick теперь дренирует несколько последовательных стадий за один запуск (`iterations<=6`, budget `210s`)
    - root cron переведён на `* * * * * /var/www/europulse/worker/run_orchestrator_once.sh`
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/core/class-epv2-worker-bridge.php`
    - `translations_deferred` больше не липкий
    - `rebuild_bundle` получает сохранённый `context_memory` и DE-derived search terms
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php`
    - queue transition больше не форсирует общий `translate_finish`, если checklist уже знает точный stage (`translate_uk` / `translate_en` / `publish_finish` / `rebuild_bundle`)
    - `payload_context_memory()` приоритизирует актуальный DE context над старым source-языком
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/core/class-epv2-source-enricher.php`
    - search queries переведены на context-first / DE-first order
    - Bing News поднят в начало fallback-chain
    - relevance filter теперь использует `context_memory/story_context`, а не только noisy primary/category
    - primary promotion перенесён после context-based filtering и усиливается `_support_score`
- Live proof:
  - item `298` прошёл:
    - `2181 rebuild_bundle -> queued_translate_uk_stage`
    - `2182 translate_uk -> queued_translate_en_stage`
    - `2183 translate_en -> queued_publish_finish_stage`
    - `2184 publish_finish -> processed_successfully`
  - текущее live state item `298`:
    - `state = ready_publish`
    - `source_count = 2`
    - `categories = ["leben-in-deutschland"]`
    - `translations_deferred = false`
    - `stage_checklist.ready_publish = true`
    - `publish_not_before = 2026-03-26 10:32:00 UTC`
  - следующий item уже автоматически подхвачен тем же контуром:
    - `2185 process` взял item `303`
- Что ещё остаётся проблемой:
  - для сырого non-DE input enrichment всё ещё иногда собирает не лучший `primary/supporting`, хотя dossier уже не пустой
  - item `303` сейчас второй live test на том же обновлённом pipeline
  - нужно дождаться автопубликации `298` после `publish_not_before` и убедиться, что следующий item тоже проходит без manual/review fallback

## Update 2026-03-26 07:45:00 UTC

- В код внесён ещё один принципиальный пакет под целевую схему:
  - первичная оценка остаётся только intake-filter
  - после dossier/source enrichment выполняется обязательная `context_analysis`
  - она опирается на body / dossier / event context, а не на headline
  - эта `context_analysis` теперь:
    - сохраняется в `_meta.context_analysis`
    - может переопределять категорию payload
    - участвует в `payload_is_review_worthy()` и `payload_ready_for_publish()`
    - блокирует выход дальше, если контекстный decision = `reject` или score < 20
- Новый материал по умолчанию больше не идёт в старый размытый `full_bundle`:
  - `worker_stage_for_request()` теперь по умолчанию возвращает `rebuild_bundle`
  - это выравнивает pipeline под `DE-first`
- Файлы live + mirror:
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/core/class-epv2-budget-manager.php`
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/core/class-epv2-budget-manager.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php`
- Все эти файлы проходят `php -l`
- Следующий первый шаг:
  - смотреть уже не на headline-driven gating, а на новый live path:
    - intake -> baseline lightweight -> rebuild_bundle -> context_analysis -> DE master
  - по свежим логам добить один оставшийся системный узел long-running `process`, если он всё ещё остаётся после этого упрощения

## Update 2026-03-26 07:30:00 UTC

- Главный контур всё ещё не завершён, но узкие места сужены до двух конкретных pre-worker узлов на `DE-first` пути:
  - `generate_review_payload()` для `299` зависал между `source_enriched` и `primary_ai_attempt`
  - baseline-path для новых item без `ai_payload` зависал у `304` между `before_baseline_payload` и входом в worker
- Что изменено в live и в repo mirror:
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/review/class-epv2-review.php`
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/review/class-epv2-review.php`
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php`
- Системные изменения по сути:
  - fallback до первого AI-вызова стал lightweight:
    - `build_payload_without_ai_from_dossier(..., true)` больше не вызывает тяжёлый `enrich_payload()` и media-resolution до AI
    - полная финализация fallback теперь выполняется только если AI реально не дал результат или нет API key
  - baseline для новых item без `ai_payload` больше не идёт через `finalize_payload_for_queue()`
    - теперь это только:
      - fast dossier
      - lightweight DE payload
      - selection/context memory
      - persist
    - без тяжёлой media/SEO/featured resolution до worker
  - добавлена точная telemetry на главном пути:
    - `before_baseline_dossier`
    - `after_baseline_dossier`
    - `before_baseline_persist`
    - `after_baseline_persist`
    - `before_worker_stage`
    - `after_worker_stage`
- Что подтверждено live:
  - `299` больше не держит очередь:
    - state `retry_process`
    - `error_message = stale processing job recovered after missing process lock`
  - новый process-run `#2104` уже взял следующий item:
    - `304 -> processing_de`
  - `304` реально живой:
    - `updated_at` двигается (`07:26:41 UTC`)
    - `epv2_lock_process` heartbeat обновляется (`1774510001`)
  - фронт и логин отвечают `200`
- Следующий первый шаг:
  - дождаться закрытия/рековери `#2104`
  - по новым stage-логам локализовать, где именно `304` тормозит:
    - `baseline_dossier`
    - `baseline_persist`
    - или уже `before_worker_stage -> run_worker_stage`
  - дальше резать только этот главный узел, не возвращаясь к побочным микрофиксам

## Update 2026-03-26 06:47:40 UTC

- Главный текущий блокер automation локализован и частично снят:
  - server orchestrator штатно вызывал `EPV2_Resilience_Manager::cleanup()`
  - но `cleanup()` мгновенно делал `return`, потому что `should_run_cleanup()` первым делом запрещал запуск при `epv2_server_orchestrator_enabled=1`
  - из-за этого stale item в `processing_de` не рековерился, и `process` на каждом тике писал `no_processable_items`, хотя в очереди были реальные `new/retry_process`
- Live fix внесён в:
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/core/class-epv2-resilience-manager.php`
  - mirror repo: `/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/core/class-epv2-resilience-manager.php`
  - `should_run_cleanup()` теперь разрешает cleanup для CLI (`PHP_SAPI === 'cli'`) до проверки `server_orchestrator_enabled`
- Что подтверждено live после фикса:
  - stale item `301` снят из `processing_de` в `retry_process`
    - `error_message = stale processing job recovered after missing process lock`
    - `updated_at = 2026-03-26 06:44:59 UTC`
  - orchestrator сразу взял реальный следующий item:
    - item `305` -> `processing_de`
    - `updated_at = 2026-03-26 06:45:03 UTC`
  - новый run появился не как fake idle, а как реальный process:
    - `process #2095 started at 2026-03-26 06:44:59 UTC`
  - фронт и логин остаются живыми:
    - `/` -> `200`
    - `/wp-login.php` -> `200`
- Это снимает главный ложный симптом "очередь пуста / automation не работает".
- Следующий фактический узел уже другой:
  - проследить, чем закончится `process #2095` для item `305`
  - если он снова упрётся не в lock/recovery, а в quality/media/worker path, разбирать уже следующий реальный bottleneck по live-стадии, а не queue gating

## Update 2026-03-26 07:02:30 UTC

- Подтверждён следующий системный блокер после queue/unlock fixes:
  - item `305` уходит в `worker_bridge` и доходит до `rebuild_generate_review_payload` / `rebuild_normalize`
  - затем зависание происходит уже внутри `try_lift_payload_to_publish_grade()`
  - payload `305` сам по себе broken:
    - `DE` не немецкий, а mixed/cyrillic
    - `UK/EN` пустые
    - `quality=11`, `release=21`
- Это подтвердило архитектурную проблему:
  - текущий pipeline всё ещё пытается дотягивать multilingual bundle слишком рано
  - вместо строгого `DE-first`
- Уже начат первый кодовый этап `DE-first` refactor:
  - создан план: `/root/projects/europulse/docs/epv2-de-first-refactor-plan-2026-03-26.md`
  - `try_lift_payload_to_publish_grade()` и `attempt_publish_grade_lift()` получили режим `DE-only`
  - worker `full_bundle` и `rebuild_bundle` теперь вызывают lift в `DE-only` режиме
  - worker/rebuild path в `AI_Processor` теперь может сразу queue `translate_finish`, если после rebuild/lift получен жизнеспособный `DE master`
- Следующий первый шаг после этого:
  - добить разрез `try_lift_payload_to_publish_grade()` на отдельные:
    - `lift_de_master_to_publish_grade()`
    - `lift_translations_to_publish_grade()`
  - и оставить генерацию/регенерацию `UK/EN` только в `translate_finish`

## Update 2026-03-26 00:33:58 UTC

- Live policy развёрнута дальше в сторону полной автоматики:
  - recoverable `process/publish` ветки больше не должны штатно падать в `ready_review`
  - `queue/class-epv2-queue.php`, `ai/class-epv2-ai-processor.php`, `publish/class-epv2-publisher.php`, `core/class-epv2-resilience-manager.php` обновлены и синхронизированы в live plugin
- Что подтверждено на live после этих правок:
  - `wp-login.php` отвечает `200` за `~0.27-0.33s`
  - queue summary:
    - `new = 6`
    - `retry_process = 5`
    - `rejected = 1`
    - `ready_review = 0`
    - `published = 1`
  - item `294` прошёл полный auto-cycle:
    - `process #2001 finished`
    - `worker_stage = rebuild_bundle`
    - `worker_duration_ms = 177943`
    - `final_item_state = ready_publish`
    - затем `publish #2002 finished`
    - итог: `294 = published`
- Остаточный активный процесс:
  - `process #2003 started` at `2026-03-26 00:31:38 UTC`
  - выбрал item `291`
  - ранние entry logs есть (`after_next_item queue_id=291`)
  - item остаётся в `retry_process`, `updated_at` продолжает двигаться, явного web/FPM деградационного эффекта нет
- Operational tweak:
  - live option `epv2_settings.worker_timeout_seconds` поднята до `600`
  - default в `class-epv2-settings.php` тоже поднят до `600`
- Что всё ещё требует внимания:
  - отдельные длинные `auto_finish/rebuild` ветки всё ещё живут минутами и требуют дальнейшего разреза/ускорения
  - нужно дождаться финала `#2003` и локализовать, где именно он тратит время после `after_next_item`

## 1. Текущее состояние проекта

- Проект: EuroPulse / WordPress + `europulse-autopilot-v2`.
- Сайт сейчас частично живой:
  - `/` отвечает `200`
  - `/wp-login.php` на срезе `2026-03-25 21:39:48 UTC` не ответил за `10s`
- Плагин `europulse-autopilot-v2` снова активен на live.
- Автоматизация переведена в безопасный серверный режим:
  - `epv2_server_orchestrator_enabled = 1`
  - `worker_mode = cli`
  - больше нет минутного системного `wp-cron.php`
  - запуск идёт через внешний orchestrator раз в 5 минут, тяжёлые стадии process уже могут уходить в встроенный PHP worker.

## 2. Что уже сделано

- Устранён последний `504`:
  - убиты зависшие ручные PHP-процессы и `wp-cron.php`
  - перезапущен `php8.3-fpm`
  - снят минутный `wp-cron` из root `crontab`
- Плагин был аварийно деактивирован, затем включён обратно уже в safe mode.
- Изменён runtime EPV2:
  - [class-epv2-jobs.php](/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/jobs/class-epv2-jobs.php)
    - `maybe_kick_pipeline()` больше не стартует pipeline от обычных веб-запросов; только CLI / WP-CRON.
  - [run_orchestrator_once.sh](/var/www/europulse/worker/run_orchestrator_once.sh)
    - добавлены `flock`, `timeout 240`, `nice`, `memory_limit=512M`
  - [epv2_orchestrator.php](/var/www/europulse/worker/epv2_orchestrator.php)
    - добавлены лимиты `memory_limit=512M`, `set_time_limit(240)`
- Усилена логика обработки:
  - [class-epv2-media.php](/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/media/class-epv2-media.php)
    - включён `context_supporting_image()`
    - media-repair больше не должен принимать только технически валидные, но смыслово левые картинки
  - [class-epv2-ai-processor.php](/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php)
    - `attempt_publish_grade_lift()` делает несколько targeted-итераций
  - [class-epv2-source-enricher.php](/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/core/class-epv2-source-enricher.php)
    - ослаблен слишком жёсткий фильтр supporting sources
  - [class-epv2-admin.php](/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/admin/class-epv2-admin.php)
    - quality badge в очереди теперь ближе к publish-grade, а не только к editorial-score
- Контекст live-инцидента и перезапуска записан в:
  - [epv2-live-context-2026-03-25.md](/root/projects/europulse/docs/epv2-live-context-2026-03-25.md)
- Начат hardening после plugin-audit:
  - в репо добавлен рабочий чеклист [TODO.md](/root/projects/europulse/TODO.md)
  - из [class-epv2-admin.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/admin/class-epv2-admin.php) убран `EPV2_Jobs::maybe_kick_pipeline()` из:
    - `queue()`
    - `queue_snapshot()`
  - на `admin_post` handlers добавлены явные capability checks:
    - service actions (`run_collect`, `run_process`, `run_publish`, `pause_automation`, `resume_automation`, `reset_stats`, `prune_queue`)
    - queue/review/manual actions
    - source actions
  - добавлен baseline instrumentation:
    - [class-epv2-collector.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/ingest/class-epv2-collector.php) пишет `duration_ms` в `ep_epv2_runs.payload`
    - [class-epv2-ai-processor.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php) пишет `duration_ms`, `worker_stage`, `worker_duration_ms`
    - [class-epv2-publisher.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/publish/class-epv2-publisher.php) пишет `duration_ms`
    - [epv2_orchestrator.php](/var/www/europulse/worker/epv2_orchestrator.php) теперь логирует `tick_ms`, `cleanup_ms`, `collect_ms`, `process_ms`, `publish_ms`
  - эти изменения синхронизированы и в active live plugin под `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2`
  - дополнительный hardening live/runtime:
    - [class-epv2-admin.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/admin/class-epv2-admin.php)
      - `queue_snapshot()` больше не запускает `EPV2_Resilience_Manager::cleanup()` на каждом AJAX refresh
    - [class-epv2-resilience-manager.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/core/class-epv2-resilience-manager.php)
      - `cleanup()` теперь не выполняется на обычных web hits при включённом `server_orchestrator_enabled`
      - добавлен throttle для non-CLI/non-cron cleanup
      - добавлена breakdown instrumentation по фазам slow cleanup
  - эти изменения тоже выложены в active live plugin

## 3. Что сломано или требует внимания

- Новый важный статус на `2026-03-26 00:01 UTC`:
  - web-контур остаётся живым, `wp-login.php` стабильно отвечает `200` около `0.25-0.38s`
  - heavy path больше не валит FPM, но automation всё ещё не доведена до конца
  - очередь на срезе:
    - `new = 7`
    - `ready_review = 3`
    - `rejected = 1`
    - `retry_process = 2`
- Что дополнительно сделано после предыдущего handoff:
  - [class-epv2-worker-bridge.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/core/class-epv2-worker-bridge.php)
    - добавлена step-level telemetry по стадиям worker execution
  - [class-epv2-ai-processor.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php)
    - добавлена telemetry внутри `generate_review_payload()`
    - убран duplicate enrichment: `generate_review_payload()` больше не гоняет `EPV2_Source_Enricher::enrich_item()` дважды через fallback path
    - budgets для `force_supporting` снижены до fast operational значений
  - [class-epv2-review.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/review/class-epv2-review.php)
    - `ensure_payload_without_ai()` и lazy dossier enrichment переведены на `fast_mode`
  - [class-epv2-source-enricher.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/core/class-epv2-source-enricher.php)
    - урезаны runtime budget и supporting-search fanout
    - `fast_mode` теперь отключает supporting search
    - `fast_mode` теперь отключает и primary document fetch
  - [class-epv2-html-reader.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/ingest/class-epv2-html-reader.php)
    - `wp_remote_get` timeouts снижены с `20s` до `6s`
  - все эти изменения уже синхронизированы в live plugin под `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2`
- Что локализовано фактически:
  - telemetry по item `294` показала, что старый bottleneck сидел в `EPV2_Source_Enricher::enrich_item()`:
    - `source_enriched duration_ms=89531`
    - `fallback_ready duration_ms=89582`
  - это объяснило, почему `process` раньше зависал на `full_bundle`
- Что выяснилось дальше:
  - даже после урезания source-enrichment ручной `wp eval 'EPV2_AI_Processor::process_scheduled(true, true)'` на live может подвисать ещё до записи нового run в `ep_epv2_runs`
  - на таких зависших ручных прогонах:
    - новый `process` run не появляется в БД
    - активного `php_worker.php` процесса нет
    - но сам PHP процесс может держать внешние соединения на `news.google.com` / Google HTTPS, то есть часть heavy path всё ещё стартует вне worker-контракта
  - это уже следующий реальный блокер: не FPM, не cleanup, не stale lock, а ранний direct-path до явного `EPV2_Runs::start()`
- Ручные recovery-правки во время отладки:
  - `process` runs `#1996` и `#1997` были вручную закрыты как `manual_kill_recovered`
  - stale `epv2_lock_process` был снят вручную через SQL
  - это был только recovery после тестовых зависших запусков; не считать финальным решением
- “Вынос тяжёлого контура” теперь частично доведён:
  - [class-epv2-worker-client.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/core/class-epv2-worker-client.php)
    - больше не исполняет произвольную shell-строку из `worker_cli_command`
    - использует `proc_open(array)` и только локальный PHP worker script внутри `worker/`
  - live `worker_mode=cli` включён
  - run `#1988` подтвердил реальный worker path:
    - `result = worker_translate_finish`
    - `worker_stage = translate_finish`
    - `processed_item_id = 302`
    - `worker_duration_ms = 56543`
- При этом вынос пока всё ещё неполный:
  - `/var/www/europulse/worker/php_worker.php` всё ещё грузит `wp-load.php`
  - это уже безопаснее по запуску и снимает shell-risk, но ещё не настоящий внешний автономный сервис
- По свежим problem items:
  - `291`, `292` остаются в `error` после stale-lock recovery
  - `302` больше не висит:
    - worker довёл его до `ready_publish`
    - publish run `#1989` честно отсеял item как stale
    - текущий state `rejected`
  - `298` больше не крутится бесконечно в `retry_process`:
    - worker снял `translations_deferred`
    - затем item упёрся в реальный quality gate `AI rewrite did not reach publish threshold`
    - после патча `catch` в `process_scheduled()` item переведён в `ready_review`
- На live подтверждён симптом долгого process-run:
  - `#1965` позже был автоматически помечен как `abandoned_started_run_cleaned`
  - затем появился новый `process` run `#1966`
  - `started_at = 2026-03-25 21:47:54 UTC`
  - на момент последнего среза он всё ещё `status = started`
- На live активен `epv2_lock_process`, но в очереди при этом нет `processing_de` item:
  - это отдельный симптом, который нужно разбирать первым
- `php8.3-fpm` упирался в `pm.max_children = 8` (`2026-03-25 21:18:08`), поэтому web/admin-контур надо дальше разгружать.
- После первых hardening-правок оба endpoint снова зависали, но после вывода cleanup из web/admin path:
  - `/wp-login.php` снова отвечает `200` локально (`2026-03-25 22:04:42 UTC`, ~0.2s)
  - новых nginx timeout записей после этого среза пока не видно
- Ключевое новое наблюдение:
  - `orchestrator` tick на `2026-03-25 22:01:38 UTC` показал `cleanup_ms=50188`, `process_ms=0`, `publish_ms=0`
  - значит текущий bottleneck живёт в `EPV2_Resilience_Manager::cleanup()`, а не только в AI/publish стадиях
- Breakdown slow cleanup уже снят через `ep_epv2_log`:
  - `2026-03-25 22:07:40 UTC`
  - `total_ms=39872`
  - самые дорогие шаги:
    - `normalize_terminal_retry_process_items=19375`
    - `normalize_retry_metadata=10941`
    - `promote_publish_ready_payloads=9552`
  - первопричина: recovery-пути повторно гоняли `normalize_existing_payload()` с тяжёлым `finalize_payload_for_queue()` и media/network side effects
- После live-патча recovery переведён на лёгкую нормализацию без дорогого media-ресолва:
  - `orchestrator` tick на `2026-03-25 22:10:01 UTC` уже показал `tick_ms=18`, `cleanup_ms=0`
  - это фактически сняло основной cleanup bottleneck
- Live state после cleanup-fix:
  - `process` run `#1967` закрыт как `missing_process_lock_recovered` в `2026-03-25 22:04:07 UTC`
  - item `291` переведён в `error` с `stale processing job recovered after missing process lock`
  - `epv2_lock_process` на этот момент уже отсутствовал
  - затем стартовал новый `process` run `#1968` (`2026-03-25 22:05:31 UTC`)
- Дополнительный live-факт после orphan-lock fix:
  - `process #1968` был закрыт `2026-03-25 22:12:43 UTC` как `abandoned_started_run_cleaned`
  - item `292` ушёл в `error` с `stale processing job recovered after missing process lock`
  - следующий run `#1970` уже отработал успешно:
    - `started_at = 2026-03-25 22:15:02 UTC`
    - `finished_at = 2026-03-25 22:15:49 UTC`
    - `result = queued_translation_finish_stage`
  - item `298` к этому моменту переведён в следующий stage `translating_variants`
  - `epv2_lock_process` после этого снова отсутствует
- Manual admin actions переведены на безопасную модель:
  - [class-epv2-admin.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/admin/class-epv2-admin.php)
    - `run_collect`, `run_process`, `run_publish` больше не делают тяжёлую работу в HTTP request
    - теперь они только queue/request stage и сразу редиректят
  - [class-epv2-jobs.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/jobs/class-epv2-jobs.php)
    - добавлены manual request flags для collect/process/publish
    - в `server_orchestrator_enabled=1` `enqueue_*()` теперь сохраняют manual request, который подхватывает ближайший orchestrator tick
    - `run_*_windowed()` и `run_*_async()` умеют consume manual request и выполнять stage в forced режиме
  - live smoke check:
    - `wp eval 'EPV2_Jobs::enqueue_process(); echo get_option(\"epv2_manual_process_requested_at\")'` вернул timestamp, после чего option была удалена вручную
    - `wp-login.php` всё ещё отвечает быстро (`2026-03-25 22:18:02 UTC`)
- Последующие live-тики после этих фиксов:
  - `process #1972` завершился штатно:
    - `started_at = 2026-03-25 22:20:01 UTC`
    - `finished_at = 2026-03-25 22:20:59 UTC`
    - `result = translated_and_finished_successfully`
    - item `298` перешёл в `ready_publish`
  - `process #1974` завершился штатно:
    - `started_at = 2026-03-25 22:25:02 UTC`
    - `finished_at = 2026-03-25 22:27:56 UTC`
    - `result = queued_translation_finish_stage`
    - `duration_ms = 173842`
    - item `302` ушёл в `retry_process`/`translate_finish`
  - `orchestrator` лог показывает, что теперь bottleneck не cleanup, а отдельные длинные process-runs:
    - `2026-03-25 22:20:59 UTC` → `process_ms=57564`
    - `2026-03-25 22:27:56 UTC` → `process_ms=173858`
  - новых nginx timeout по `wp-login.php` при этом нет; локальный HEAD на `2026-03-25 22:33:01 UTC` снова `200`
- Для следующих циклов добавлена item-level telemetry в [class-epv2-ai-processor.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php):
  - `branch`
  - `pipeline_stage_before`
  - `pipeline_stage_after`
  - `final_item_state`
  - `item_duration_ms`
  - `live_status_code`
  - первый run, который должен уже её нести, это `process #1975` (`started_at = 2026-03-25 22:30:01 UTC`)
- Реальные publish-проблемы, найденные уже после стабилизации runtime:
  - item `298` не опубликовался не из-за таймера как такового, а из-за двух отдельных дефектов:
    1. stale-filter в publish слишком агрессивно резал service/community материалы по возрасту `original_date`
    2. forced publish path (`publish_scheduled(true)`) не обходил `publish_not_before`, поэтому manual/forced publish возвращал `no_due_items`
  - оба дефекта исправлены:
    - [class-epv2-publisher.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/publish/class-epv2-publisher.php)
      - `is_stale_item()` больше не режет service/community/`leben-in-deutschland` материалы только по возрасту
      - `publish_scheduled(true)` теперь действительно берёт `ready_publish` item без slot-gating
    - [class-epv2-queue.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/queue/class-epv2-queue.php)
      - `next_item_for_publish(true)` обходит `publish_due()`
  - после этого forced publish честно взял item `298` в работу и обнаружил уже реальный blocker:
    - `publish run #1978`
    - `result = media_blocker_sent_to_repair`
    - item `298` переведён в `retry_process` с `Изображение не подошло по качеству или размеру.`
  - затем добавлен ещё один preventive fix:
    - [class-epv2-ai-processor.php](/root/projects/europulse/wp-plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php)
      - `payload_ready_for_publish()` теперь требует не просто media URL, а успешный `EPV2_Media::validate_featured_media(...)`
      - это должно закрыть разрыв “готово к публикации” → “валится в publish на image quality”
- Свежий live-срез на `2026-03-25 23:02:27 UTC`:
  - `wp-login.php` отвечает `200` за `~0.29s`
  - queue summary:
    - `new = 9`
    - `ready_review = 1`
    - `rejected = 1`
    - `retry_process = 0`
  - item states:
    - `298 = ready_review`
    - `302 = rejected`
- Следующий технический приоритет:
  - довести `php_worker.php` до настоящего внешнего worker без `wp-load.php`
  - затем оптимизировать DB/queue и убрать remaining long process durations

## 4. Текущая git-ветка и remote

- Ветка: `review/plugin-audit`
- Remote:
  - `origin https://github.com/k0reets86/europulse.git (fetch)`
  - `origin https://github.com/k0reets86/europulse.git (push)`

## 5. Пути к важным файлам и плагинам

- Корень проекта:
  - `/root/projects/europulse`
- Live WordPress:
  - `/var/www/europulse/public`
- Live плагин:
  - `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2`
- Репо-копия плагина:
  - `/root/projects/europulse/wp-plugins/europulse-autopilot-v2`
- Worker:
  - `/var/www/europulse/worker/epv2_orchestrator.php`
  - `/var/www/europulse/worker/run_orchestrator_once.sh`
  - `/var/www/europulse/worker/php_worker.php`
- Контекст-файлы:
  - `/root/projects/europulse/docs/epv2-live-context-2026-03-25.md`
  - `/root/projects/europulse/SESSION_HANDOFF.md`

## 6. Следующий шаг, который нужно делать первым

- Следующий шаг:
  - добавить very-early instrumentation в `process_scheduled()` до `EPV2_Runs::start()` и до `ensure_payload_without_ai()`, чтобы поймать ранний direct-path, который сейчас может виснуть без записи нового run
  - дожать вынос: baseline/new-item path тоже должен идти либо через worker, либо через полностью локальный fast path без внешней сети
  - отдельно зачистить poisoned queue items:
    - `295` с legacy ошибкой `worker_shared_secret()`
    - `303` после stale recovery
  - затем прогнать несколько тиков подряд и подтвердить:
    - `new` реально уменьшается
    - `retry_process` не ходит по кругу
    - `wp-login.php` остаётся быстрым

## 7. Команды, которые использовать дальше

```bash
curl -I --max-time 10 http://127.0.0.1/
curl -I --max-time 10 http://127.0.0.1/wp-login.php
ps -eo pid,ppid,etime,%mem,%cpu,cmd | rg "epv2_orchestrator|run_orchestrator_once|wp-cron|php-fpm"
crontab -l
tail -n 50 /var/www/europulse/worker/epv2_orchestrator.log
wp option get epv2_server_orchestrator_enabled --allow-root --path=/var/www/europulse/public --skip-plugins --skip-themes
wp option get epv2_settings --format=json --allow-root --path=/var/www/europulse/public --skip-plugins --skip-themes
wp db query "SELECT state, COUNT(*) AS qty FROM ep_epv2_queue GROUP BY state ORDER BY qty DESC;" --allow-root --path=/var/www/europulse/public --skip-plugins --skip-themes
wp db query "SELECT id,state,error_message,created_at,updated_at FROM ep_epv2_queue WHERE state IN ('new','processing_de','retry_process','ready_review','ready_publish') ORDER BY id DESC LIMIT 20;" --allow-root --path=/var/www/europulse/public --skip-plugins --skip-themes
wp db query "SELECT id,job_name,status,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.result')) AS result, started_at, finished_at FROM ep_epv2_runs ORDER BY id DESC LIMIT 12;" --allow-root --path=/var/www/europulse/public --skip-plugins --skip-themes
php -l /var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/jobs/class-epv2-jobs.php
php -l /var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/ai/class-epv2-ai-processor.php
php -l /var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/core/class-epv2-source-enricher.php
php -l /var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/media/class-epv2-media.php
php -l /var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/admin/class-epv2-admin.php
```
## 2026-03-26 08:25 UTC update

- В `EPV2_AI_Processor` добавлен единый stage-router `payload_next_required_stage()`:
  - `rebuild_bundle` если DE master ещё не дотянут или payload ещё не review-worthy
  - `translate_finish` если DE готов, но UK/EN ещё не готовы
  - `publish_finish` если языки готовы, но publish-grade ещё не достигнут
- Старые общие `throw` по `minimum review threshold` / `publish threshold` внутри основных process-веток заменены на явный переход к следующей стадии через `queue_required_stage()`.
- `worker_stage_for_request()` теперь сначала смотрит на новый stage-router, а не на старые эвристики.
- Для `context_reject` исправлено затирание `_meta.stage_checklist`: `mark_state(... ai_payload=...)` теперь получает payload после `refresh_stage_checklist()`.
- Live подтверждение:
  - `process #2112/#2115/#2117 -> rejected_by_context`
  - это уже реальное прохождение по новому fast-stop через `context_analysis`
  - актуальный active run: `process #2119`, item `293`, `state=processing_de`, `live_status_code=worker_rebuild`
- Остаточный системный узел прямо сейчас:
  - синхронный `EPV2_Worker_Client::run_cli_request()` всё ещё держит основной `process` открытым, пока `php_worker.php`/`wp-cli worker bridge` крутятся
  - это не очередь и не checklist, а ещё не разрезанный sync worker bridge

### Следующий шаг

Первым делом резать sync worker bridge:
- увести тяжёлый worker из blocking `proc_open + wait` внутри `process_scheduled()`
- либо в отдельную persisted stage job,
- либо в отдельный worker-run state с быстрым возвратом в очередь без долгого удержания process-run.

## 2026-03-28 23:25 UTC update

- Перечитаны:
  - `SESSION_HANDOFF.md`
  - `TODO.md`
  - `docs/epv2-semantic-enrichment-and-media-contract.md`
  - `docs/epv2-error-knowledge-base.md`
- По `wp-plugins/europulse-autopilot-v21` уже внесены локальные снижения churn:
  - убраны admin read-path side effects;
  - сужен async/runtime orchestration path;
  - review-open переведён на snapshot-first metrics path;
  - убраны duplicate cleanup/cache flush/meta churn в publisher;
  - hot queue/resilience paths переведены на summary rows и row-level decode cache.
- Реальная live-проверка (`/var/www/europulse/public`) показала:
  - plugin `europulse-autopilot-v21` на live отстаёт от workspace-копии;
  - `wp` команды с активным plugin зависают, но с `--exec='define("DISABLE_WP_CRON", true);'` отвечают быстро;
  - `EPV2_Queue::next_item_for_publish()` быстрый;
  - `EPV2_Queue::normalize_ready_publish_schedule(false)` зависает, даже когда в очереди только один `ready_publish` item (`id=342`);
  - strongest culprit: expensive `EPV2_AI_Processor::normalize_existing_payload($payload)` внутри scheduling path.
- Локальный фикс в workspace:
  - `EPV2_Queue::normalize_ready_publish_schedule()` теперь использует `EPV2_AI_Processor::normalize_existing_payload($payload, false)`.
- Что делать следующим шагом:
  - прогнать `php -l` на изменённом `v21`;
  - при возможности прогнать такую же проверку на окружении, где реально стоит свежий `v21` из workspace;
  - если нужен именно live hotfix, синхронизировать live plugin с актуальным `v21`, иначе runtime-проверка продолжит бить по старому коду.

## 2026-03-29 07:55 UTC update

- Продолжена живая runtime-проверка `v21` на `/var/www/europulse/public`.
- В `includes/ai/class-epv2-ai-processor.php` сделаны ещё три связанных фикса:
  - финальный path после `translate_finish` и review-ready resume path переведены на узкий `run_publish_finish_stage()` вместо broad `attempt_publish_grade_lift()`;
  - `payload_stage_requires_translation_finish()` больше не считает `translate_*` обязательным только из-за stale `pipeline_stage`, а смотрит на `translations_ready`;
  - `payload_needs_deeper_supporting_enrichment()` получил single-source relaxation для:
    - long-form official primary;
    - long-form low-risk `community` / `kultur` / local preview cases.
- Live-сигналы после фикса:
  - `351`:
    - до фикса `next_required_stage = rebuild_bundle`
    - после фикса `next_required_stage = publish_finish`
  - `354`:
    - после предыдущего translation-router fix уже не застревает в старом `publish_finish <-> rebuild_bundle` trap;
    - после текущего live check `next_required_stage = translate_uk`
  - `358`:
    - live runs `#4424/#4425/#4426` подтвердили реальный проход:
      - `queued_translate_uk_stage`
      - `queued_translate_en_stage`
      - `translated_en_successfully`
- Текущее состояние:
  - rebuild bias на живых single-source long-form кейсах заметно ослаблен;
  - но acceptance из `TODO.md` ещё не закрыт:
    - нужен не один успешный translation chain, а серия end-to-end `new -> ready_publish -> published`.
- Что делать следующим шагом:
  - дождаться завершения текущего live `process` run и проверить, какой item реально взял `#4427`;
  - затем прогнать ещё несколько forced/live ticks и проверить, переходят ли `351/354` в `publish_finish` / `translate_uk` уже не только по router probe, но и по фактическим runs;
  - после этого переходить к publish-proof серии по нескольким живым items подряд.

## 2026-03-29 08:05 UTC update

- Добит ещё один системный recovery-case в `v21`.
- В `includes/ai/class-epv2-ai-processor.php`:
  - добавлен публичный wrapper `payload_required_stage()` для reuse вне process loop.
- В `includes/core/class-epv2-resilience-manager.php`:
  - в `cleanup()` добавлена `normalize_stalled_rebuild_cooldowns()`;
  - если у item висит stale `publish threshold stalled rebuild bundle`, но текущий router уже не требует `rebuild_bundle`, cleanup теперь:
    - сбрасывает `retry_after`;
    - очищает stale error;
    - обнуляет `process/review_rebuild/review_finish` counters;
    - переписывает `pipeline_stage` на актуальный required stage.
- Live-подтверждение:
  - после recovery:
    - `351` -> `processing_de / publish_finish`
    - `353` -> `new / publish_finish`
    - `354` -> `new / translate_uk`
  - run `#4437` уже реально зафиксирован как:
    - `queued_publish_finish_stage`
    - item `353`
    - `stage_before = publish_finish`
    - `stage_after = publish_finish`
  - актуальный live run:
    - `#4438`
    - item `351`
    - пока ещё `started`
    - state item остаётся `processing_de / publish_finish` без старого stale error.
- Дополнительный runtime сигнал:
  - `361` ушёл в `rejected_by_context`;
  - `363` ушёл в `queued_translate_uk_stage`;
  - это подтверждает, что очередь снова двигается по разным stage branches, а не клинит на одном stale rebuild loop.
- Следующий шаг:
  - дождаться финала `#4438`;
  - проверить, ушёл ли `351` из `publish_finish` дальше в `ready_publish` или снова выявил новый финальный blocker;
  - затем перейти к publish-proof серии по `353/358/...` и собирать уже не router-probe, а end-to-end evidence.

## 2026-03-29 08:12 UTC update

- Подтверждён отдельный visibility bug:
  - item `362` отсутствует в `ep_epv2_queue`;
  - в `runs` и `epv2_log` по нему нет process/publish trail;
  - root cause найден в `EPV2_Queue::trim_new_queue()`, который физически удалял `new` rows.
- Исправление в `includes/queue/class-epv2-queue.php`:
  - trim больше не делает silent `DELETE`;
  - trimmed candidates переводятся в `rejected` с `_system.trimmed_from_new_queue`.
- Подтверждён и закрыт ещё один системный final-stage loop:
  - `353` зацикливался в repeated `queued_publish_finish_stage`;
  - root cause: `payload_requires_media_manual_confirmation()` не срабатывал на single-source exhausted media cases;
  - добавлена `payload_source_first_media_exhausted()` и расширен media manual-confirmation gate;
  - queue/UI manual media state теперь не зависит от старого `source_count >= 2` ограничения.
- Live-подтверждение:
  - `#4464 process finished queued_media_manual_confirmation` для item `353`;
  - актуальное состояние `353`:
    - `ready_review`
    - `manual_confirmation_required = media`
    - больше не повторяет бесконечный `publish_finish` loop.
- Трезвый итог на этот момент:
  - пользователь прав: раньше промежуточные stage transitions переоценивались как успех;
  - реальный progress нужно считать только по:
    - явной terminal visibility,
    - `ready_publish`,
    - `published`,
    - либо корректному terminal media/context stop с понятной причиной.
- Следующий шаг:
  - продолжать не по отдельным items, а по remaining system classes:
    - auto-completable translation/publish-finish cases (`351`, `354`, `363`);
    - сокращение manual-only surface;
    - доказательство реального `ready_publish -> published`, а не stage-loop.

## 2026-03-29 08:33 UTC update

- Закрыт системный translation no-progress loop.
- В `includes/ai/class-epv2-ai-processor.php`:
  - single-language translation path теперь считает no-progress attempts по языку;
  - после повторного invalid/no-progress result item уходит в `ready_review` с:
    - `_system.manual_confirmation_required = translation`
    - `_system.manual_confirmation_reason = <lang>_no_progress`
  - это снимает бесконечный `translate_uk`/`translate_en` loop.
- Live-подтверждение:
  - item `354`:
    - раньше многократно повторял `translate_uk` без появления UK package;
    - после post-sync runs получил `translate_uk = 1`, затем вышел в:
      - `ready_review`
      - `manual_confirmation_required = translation`
      - `manual_confirmation_reason = uk_no_progress`
      - понятный `error_message` про ручное подтверждение translation.

- Закрыт ещё один системный `publish_finish` no-op loop для source-first media.
- В `includes/ai/class-epv2-ai-processor.php`:
  - `payload_requires_media_manual_confirmation()` расширен;
  - теперь manual media fallback срабатывает не только на exhausted/no-image cases, но и когда:
    - source-first image есть,
    - `media_repair_failed = true`,
    - выбранный `featured_media_url` отсутствует или остаётся non-publishable,
    - deeper supporting rebuild уже не ожидается.
- Live-подтверждение:
  - item `351`:
    - после single-source relaxation больше не уходит в `rebuild_bundle`;
    - но зависал в repeated `publish_finish` без terminal progress;
    - после media fallback fix вышел в:
      - `ready_review`
      - `manual_confirmation_required = media`
      - явный `error_message` вместо повторного `publish_finish`.

- Дополнительный end-to-end факт:
  - item `363` реально опубликован:
    - `state = published`
    - `post_id = 2934`

- Трезвый итог на этот момент:
  - системно сняты два класса бесконечных loop:
    - translation no-progress
    - publish-finish media no-op
  - queue visibility стала лучше:
    - problem items больше не “живут” бесконечно в одинаковом active stage без explainable outcome;
    - они либо доходят до `published`, либо выходят в explicit manual-review state.

- Что делать следующим шагом:
  - сокращать manual-only surface:
    - почему source-first media на `351` не проходит publishable validator;
    - можно ли автоматически подтверждать безопасные museum/official imagery cases;
  - затем собирать следующую publish-proof серию уже по новым items, а не по зацикленным `351/354`.

## 2026-03-29 08:45 UTC update

- Разбор `docs/epv2-error-knowledge-base.md` показал повторяющийся системный паттерн:
  - stage-layer уже умеет правильно детектить manual stop / required stage;
  - но resilience/state-model местами всё ещё жили по старым эвристикам:
    - regex по `error_message`;
    - возврат stage-bearing item в `new`.
- Исправление в `includes/core/class-epv2-resilience-manager.php`:
  - `revive_auto_review_candidates()` теперь не трогает items с явным `_system.manual_confirmation_required`;
  - `normalize_stalled_rebuild_cooldowns()` больше не отправляет items с `required_stage != ''` в `new`, а переводит их в `retry_process`.
- Live-подтверждение после `cleanup()`:
  - `351` остаётся `ready_review / manual=media`
  - `354` остаётся `ready_review / manual=translation`
  - `353` остаётся `ready_review / manual=media`
  - manual-confirmation items больше не оживают обратно в automation loop только из-за resilience pass.

- Сводка найденных закономерностей по KB:
  - главный повторяющийся класс сбоев:
    - не “плохой один API”, а рассинхрон между:
      - `payload_next_required_stage()`
      - row `state`
      - recovery/revive logic
      - manual/terminal interpretation
  - вторичный повторяющийся класс:
    - loop без finite outcome:
      - `rebuild_bundle`
      - `translate_*`
      - `publish_finish`
  - практический вывод:
    - дальше системные фиксы надо строить вокруг unified state contract и finite stage outcomes, а не вокруг точечных item-specific веток.

## 2026-03-29 09:05 UTC update

- User-reported symptom confirmed on live:
  - queue looked “stuck” not потому что cron совсем мёртв, а потому что automation-path сочетал два системных choke points:
    - intake throttling в `collect`;
    - starvation на старых `rebuild_bundle` items в `process`.

- Что исправлено в коде:
  - `includes/ingest/class-epv2-collector.php`
    - в `auto` введены effective floors:
      - `max_collect_per_category >= 2`
      - `queue_new_max_per_category >= 2`
    - тот же cap используется при `trim_new_queue()`
  - `includes/queue/class-epv2-queue.php`
    - добавлен fatigued rebuild classifier;
    - `next_stage_resume_item()` больше не preempt’ит свежий `new`, если stage-item уже repeatedly проваливал weak-DE / threshold rebuild;
    - `next_item_for_processing()` больше не держит `rebuild_bundle` как privileged path при наличии свежих `new`;
    - fatigued rebuild получает zero stage priority и сильный processing-score penalty.

- Live-подтверждение:
  - после collector fix live queue снова начала наполняться свежими items:
    - `364`
    - `365`
    - `366`
    - `367`
    - `368`
  - это впервые за текущую сессию дало реальный свежий intake вместо повторного кручения только `344/357`.

- Что ещё не закрыто:
  - текущий live process run `#4512` всё ещё держит `344` в `processing_de`;
  - в момент handoff нельзя честно утверждать, что post-run handoff уже доказан end-to-end на fresh items;
  - следующий нужный фронт:
    - добить process lock/run contract для orphan/long-running path;
    - затем проверить, что после освобождения lane selector реально берёт `364-368`, а не возвращается к `357`.

## 2026-03-29 10:05 UTC update

- Найден и исправлен ещё один системный queue/runtime drift в `includes/queue/class-epv2-queue.php`:
  - раньше `processing_de -> retry_process/ready_review` проходил через `clear_active_automation_item()`;
  - та сразу запускала broad normalization и схлопывала non-active workflow states обратно в `new`;
  - затем `queue_next_processing_stage()` дописывал `live_status_code`, из-за чего появлялись broken rows вида:
    - `state = new`
    - но `pipeline_stage/retry_after/live_status` уже stage-bearing.

- Что изменено:
  - active pointer теперь валиден только при `processing_de + live process lock`;
  - `normalize_non_active_recoverable_items()` больше не схлопывает `retry_process/ready_review` в `new`;
  - добавлен repair-pass для legacy drift:
    - любой `new` item с `pipeline_stage`, `retry_after` или `live_status_code` автоматически возвращается в `retry_process`.

- Live-подтверждение после синка на production:
  - `351`: `new + translating_en` -> `retry_process / translate_en`
  - `370`: `new + rebuild_bundle + retry_after` -> `retry_process / rebuild_bundle`
  - `372`: канонически `processing_de`, `active_item = 372`
  - selector снова возвращает активный `processing_de`, а не broken hybrid state.

- Практический вывод:
  - это был не item-specific кейс, а системная причина “новости как будто не идут в работу”:
    - automation жила по payload/stage,
    - а queue state и active pointer уже говорили другое.
  - после этого invariants стали жёстче:
    - stage-bearing rows должны жить в `retry_process/ready_review`;
    - `new` больше не может быть carrier для скрытой stage-работы.

## 2026-03-29 10:20 UTC update

- Найден ещё один отдельный systemic class в intake/runtime:
  - `collect` мог продолжать живой source pass уже без lock;
  - при этом `epv2_collect_progress` оставался `running`, а последний run в `epv2_runs` оставался `started`.

- Что было сломано:
  - collect heartbeat обновлялся только между source-итерациями;
  - единый stale window `300s` был слишком коротким для некоторых long source fetch’ей;
  - stale-диагностика collect activity опиралась на локальную строку `current_time('mysql')`, что делало orphan detection timezone-sensitive;
  - unexpected exit из collect-path мог оставить run/progress без финализации.

- Что исправлено:
  - `includes/core/class-epv2-lock-manager.php`
    - stale heartbeat теперь может быть job-specific через `meta.stale_after`;
  - `includes/ingest/class-epv2-collector.php`
    - collect acquire теперь ставит расширенное stale window;
    - `collect_progress` пишет ещё и `updated_at_ts`;
    - в `finally` collect гарантированно финализирует `run/progress`, если не дошёл до штатного `finish`;
  - `includes/jobs/class-epv2-jobs.php`
    - orphan recovery для collect уважает расширенное stale window;
  - `includes/jobs/class-epv2-runs.php`
    - collect stale-activity теперь читает `updated_at_ts` и не даёт future-ish local time сломать cleanup.

- Live-снимок до фикса:
  - `collect_progress.status = running`
  - `latest_collect.status = started`
  - `epv2_lock_collect = false`

- Практический смысл:
  - это закрывает ещё один класс “automation выглядит живой, но контракт выполнения уже мёртв”;
  - intake теперь должен жить по тем же строгим invariants, что и process/publish:
    - live run
    - live lock
    - корректно финализированный run/progress

## 2026-03-29 10:35 UTC update

- Закрыт ещё один state-contract defect между processing и publish:
  - payload больше не может считаться `publish_ready`, если у него всё ещё есть `pipeline_stage` или ненулевой `payload_required_stage()`;
  - transition в `ready_publish/published` теперь сам очищает stale `pipeline_stage` в `ai_payload`.

- Почему это было важно:
  - live item `351` показал broken contradiction:
    - `state = ready_publish`
    - `payload_required_stage = translate_en`
    - `payload_is_publish_ready = true`
  - это означало, что publish-grade predicate был недостаточно строгим и позволял terminal publish-state с незавершённым processing contract.

- Параллельно закрыт отдельный media-core false-negative:
  - trusted source-first images от того же publisher family ломались на host comparison `www` vs CDN subdomain;
  - для item `372` `derivates.kicker.de` image уже проходил `validate_featured_media()`, но падал на relevance path только из-за mismatch `www.kicker.de` vs `derivates.kicker.de`.

- Что изменено:
  - `includes/ai/class-epv2-ai-processor.php`
    - `payload_ready_for_publish()` теперь запрещает publish-ready при любом required stage / pipeline stage;
  - `includes/queue/class-epv2-queue.php`
    - `mark_state()` очищает payload stage при `ready_publish/published`;
  - `includes/media/class-epv2-media.php`
    - `same_source_host()` нормализует `www.` перед family matching.

- Live-факты после фикса:
  - trusted `kicker` image для `372` теперь проходит relevance;
  - `repair_media_for_automation()` уже перестал требовать manual media review для этого подтипа;
  - `372` снят с explicit manual stop и возвращён в automation bucket:
    - `ready_review`
    - без `manual_confirmation_required`
    - со stage `publish_finish`

- Оставшийся media split после этого:
  - `372`-класс: trusted publisher image false-negative — уже закрыт;
  - `364`-класс: Google wrapper source image отсутствует на ingest-side — это отдельный unresolved source-normalization/media path.

## 2026-03-29 11:15 UTC update

- Найден и разрезан отдельный systemic starvation class в `process` lane:
  - queue реально содержала fresh `new` item `369`,
  - но selector всё равно выбирал stage-bearing `retry_process` (`372`, затем `365`);
  - это и было прямым объяснением пользовательского симптома “новости висят в новых, в работу не идут”.

- Что оказалось недостаточным:
  - blacklist одного hot item;
  - rebuild-fatigue penalties;
  - simple streak-based filter.
  После снятия одного monopolizer lane сразу захватывал следующий resume item.

- Что изменено системно:
  - `includes/jobs/class-epv2-runs.php`
    - добавлены:
      - `recent_processed_item_streak()`
      - `latest_finished_payload()`
  - `includes/queue/class-epv2-queue.php`
    - введены явные processing buckets:
      - `new`
      - `resume_stage`
      - `resume_auto`
    - added `processing_bucket()`
    - added `next_fresh_new_item()`
    - selector в `next_item_for_processing()` теперь умеет отдавать слот fresh `new` bucket отдельно от resume bucket;
    - added fairness gate:
      - recent monopolizer filter
      - short resume cooldown
      - preference for `new`, если предыдущий finished process run не был `new`
  - `includes/ai/class-epv2-ai-processor.php`
    - `process` run payload теперь пишет `selected_bucket`

- Жёсткое live-подтверждение:
  - до bucket fix:
    - `next_item_for_processing(false) => 372`
    - после первого fairness patch => `365`
    - while `369` оставался `new`
  - после bucket fix:
    - `next_item_for_processing(false) => 369`
    - `next_state = new`
    - `next_bucket = new`

- Практический смысл:
  - это первый прямой live-proof, что selector больше не обязан бесконечно крутить broken `retry_process` bucket;
  - intake и resume теперь отделены явно, а не только через implicit priority rules.

## 2026-03-29 11:35 UTC update

- После bucket-fairness выяснился ещё один отдельный orchestration blocker:
  - selector уже честно отдавал `369`,
  - но automation всё равно визуально “не брала новые новости”,
  - потому что `process` зависал раньше реального item pick.

- Live telemetry показала точную причину:
  - `process #4595` зависал в cleanup path;
  - `before_cleanup -> after_cleanup` занимал примерно `10m+`;
  - даже direct `run_process_async()` висел ещё до старта нового item run.

- Что изменено:
  - `includes/ai/class-epv2-ai-processor.php`
    - forced/scheduled `process_scheduled()` больше не делает второй inline `EPV2_Resilience_Manager::cleanup()`;
  - `includes/jobs/class-epv2-jobs.php`
    - `maintain_runtime_state()` разделён на:
      - light mode для `process`
      - heavy mode для `publish`
    - process path теперь делает только orphan recovery + queue normalization, без global heavy cleanup.

- Жёсткое live-подтверждение после патча:
  - старый stuck run `#4595` recovery-нулся как `stuck_without_processing_item_recovered`;
  - новый run `#4596` уже реально взял `369`;
  - в `ep_epv2_log` видны шаги:
    - `after_next_item` для `queue_id=369`
    - `after_analysis`
    - `after_baseline_payload`
    - `after_baseline_persist`
  - сам item `369` теперь уже `processing_de`.

- Практический вывод:
  - прежний пользовательский симптом был составным:
    - сначала starvation selector-а,
    - затем duplicate/heavy maintenance в process hot path.
  - оба узла теперь разделены и исправлены независимо.

## 2026-03-30 17:10 UTC update

- Продовый outage локализован уже не в plugin-only path, а в инфраструктурном runtime envelope:
  - сайт отдавал `502`;
  - `php8.3-fpm` был в `failed (oom-kill)`;
  - `/etc/cron.d/europulse-wp-cron` каждую минуту запускал `wp cron event run --due-now` без non-overlap guard;
  - это вызывало cron storm, swap thrash и лаги SSH/Termius.

- Что изменено системно:
  - `/var/www/europulse/public/wp-config.php`
    - включён `DISABLE_WP_CRON`;
  - `/etc/cron.d/europulse-wp-cron`
    - заменён на safe runner;
  - `/usr/local/bin/europulse-safe-cron.sh`
    - added `flock`
    - added `timeout`
    - added `nice/ionice`
    - added memory guard по `MemAvailable` и `SwapFree`
    - лог перенесён в `/var/log/europulse-wp-cron.log`;
  - `/etc/php/8.3/fpm/pool.d/www.conf`
  - `/etc/php/8.3/fpm/pool.d/zz-europulse-safety.conf`
    - effective `php-fpm` envelope сужен до:
      - `pm.max_children = 6`
      - `pm.max_requests = 200`
      - `request_slowlog_timeout = 15s`
      - `request_terminate_timeout = 90s`
  - `/etc/systemd/system/php8.3-fpm.service.d/override.conf`
    - added `Restart=on-failure`
  - `/etc/sysctl.d/99-europulse-memory.conf`
  - `/etc/sysctl.d/99-europulse-memory-guard.conf`
    - effective:
      - `vm.swappiness = 5`
      - `vm.min_free_kbytes = 262144`

- Жёсткое live-подтверждение:
  - сайт снова отвечает `200 OK`;
  - следующий cron tick после замены runner-а создал ровно один live process, без старого storm multiplication;
  - спустя следующий tick:
    - `php8.3-fpm` active
    - `nginx` active
    - `MemAvailable ~ 2.8 GiB`
    - swap mostly free
    - runaway `wp cron` processes отсутствуют

- Практический смысл:
  - теперь есть не только “поднять после падения”, а отдельный infrastructure guard layer;
  - даже при тяжёлых WordPress jobs сервер больше не должен уходить в мгновенное memory exhaustion из-за overlapping cron runs.

## 2026-03-30 17:37 UTC update

- Дальнейшая live-диагностика показала отдельный transport/runtime class после стабилизации сайта:
  - один общий `wp cron event run --due-now` оказался слишком грубым для смешанного WP + EPV2 workload;
  - generic hooks и EPV2 hooks должны иметь разные execution envelopes;
  - selector path для process всё ещё тратил лишний payload decode уже на стадии выбора следующего item.

- Что изменено:
  - `/usr/local/bin/europulse-safe-cron.sh`
    - runner разделён на отдельные lanes:
      - generic due-now с `--exclude=epv2_*`
      - `epv2_publish`
      - `epv2_process`
      - `epv2_collect`
    - per-lane locks/timeouts/memory guards;
    - исправлен logging bug в non-zero rc handling;
  - `wp-plugins/europulse-autopilot-v21/includes/queue/class-epv2-queue.php`
    - added cheap processing summary via `JSON_EXTRACT(... pipeline_stage ...)`;
    - `row_processing_stage()` теперь использует extracted stage marker без полного payload decode;
    - `next_stage_resume_item()` переведён на processing summary path;
  - `wp-plugins/europulse-autopilot-v21/includes/jobs/class-epv2-jobs.php`
    - added reason-logging around `run_process_windowed()` / `run_publish_windowed()` early-return branches.

- Что подтверждено live:
  - сайт остаётся `200`;
  - cron storm не вернулся;
  - `epv2_collect` исполняется как отдельный hook-run в новом transport path;
  - отдельный `epv2_process` run может потреблять `~3.2 GiB RSS`, то есть основной незакрытый blocker сейчас уже не scheduler storm, а extremely heavy process runtime itself.

- Текущий незакрытый blocker:
  - `epv2_process` всё ещё может входить в pathological high-memory execution без нового `ep_epv2_runs` row и без свежего app-log trail;
  - acceptance по autonomous publish пока не достигнут;
  - следующий фронт — локализовать pre-run / pre-log heavy path у `epv2_process` и убрать сам memory blowup, а не только transport around it.

## 2026-03-30 18:22 UTC update

- Продолжение live-работы сняло несколько следующих системных классов и вернуло automation в реальное движение:
  - `process selector` больше не зависает в stage-resume path;
  - `publish` больше не тащит heavy maintenance до start path;
  - `manual_confirmation_required=translation` больше не прячется в `retry_process`;
  - `collect` больше не блокируется тяжёлым `cleanup()` до source intake;
  - plugin-level runtime теперь дополнительно запрещает overlap `collect` с `process/publish`.

- Что изменено:
  - `wp-plugins/europulse-autopilot-v21/includes/queue/class-epv2-queue.php`
    - manual-confirmation state coercion расширен с `media` на `media|translation`;
    - selector trace показал реальный `return_fresh_new_item` для fresh queue items;
  - `wp-plugins/europulse-autopilot-v21/includes/jobs/class-epv2-jobs.php`
    - `run_publish_windowed()` / `run_publish_async()` переведены на light maintenance;
    - `run_process_*` и `run_publish_*` теперь early-return при active collect lock;
  - `wp-plugins/europulse-autopilot-v21/includes/core/class-epv2-resilience-manager.php`
    - added `normalize_manual_confirmation_queue_states()`;
  - `wp-plugins/europulse-autopilot-v21/includes/ingest/class-epv2-collector.php`
    - removed heavy `EPV2_Resilience_Manager::cleanup()` from collect preflight;
    - left only light lock/queue normalization before intake;
  - `/usr/local/bin/europulse-safe-cron.sh`
    - memory guard thresholds снижены до practically usable guarded envelope.

- Что подтверждено live:
  - `process #4603/#4604/#4605/#4606` быстро схлопнули старый retry backlog в terminal rejects вместо старых hangs;
  - `354` переведён из hidden `retry_process` в explicit `ready_review/manual=translation`;
  - forced `collect #4607` реально стартовал и пошёл по источникам:
    - `total_sources=78`
    - `processed_sources` live вырос до `46`
    - `collected_items` live вырос до `12`
  - queue снова получила fresh intake:
    - `373..384` появились как `new`
  - fresh `process #4608` выбрал именно новый item:
    - `queue_id=381`
    - selector log: `return_fresh_new_item`
    - item `381` переведён в `processing_de`

- Важный runtime риск, вскрывшийся во время forced parallel verification:
  - при ручном overlap `collect + process` внешний `curl http://204.168.148.47` intermittently падал, хотя:
    - `nginx` оставался active
    - `php8.3-fpm` оставался active
    - локальный `curl http://127.0.0.1` отвечал `200`
  - это стало основанием для plugin-level collect/process serialization guard.

- Текущее состояние на момент handoff:
  - queue содержит:
    - fresh `new` items from current collect run
    - manual `ready_review` items `364/354/370`
  - `collect #4607` ещё был in-flight during verification;
  - `process #4608` был started on fresh item `381`;
  - acceptance `10` autonomous publish-grade publications подряд всё ещё не достигнут.

- Следующий фронт:
  - дождаться outcome `4607/4608`;
  - проверить, что после collect completion process продолжает забирать remaining `new` items уже без forced overlap;
  - затем снова выйти на `ready_publish -> published` proof по fresh items, а не только на intake/processing recovery.

## 2026-03-30 21:44 UTC update

- Продолжение live-работы закрыло ещё один системный класс orphan recovery и локализовало следующий hot-path blocker уже после AI response.

- Что изменено:
  - `wp-plugins/europulse-autopilot-v21/includes/jobs/class-epv2-jobs.php`
    - workerless stale recovery для `processing_de` теперь использует единый `effective_stale_after` и для lock heartbeat, и для queue row `updated_at`;
  - `wp-plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php`
    - deferred `defer_translations=true` path больше не тащит full `enrich_payload()+validate()` после ответа модели;
    - added `finalize_payload_for_stage_routing()`;
    - hot path переведён на lightweight `context_memory`;
    - added tracing `before_stage_routing_finalize/after_stage_routing_finalize/before_intermediate_persist/after_intermediate_persist`.

- Что подтверждено live:
  - сайт стабилен:
    - `curl -I http://127.0.0.1/` => `200 OK`
    - `php8.3-fpm` / `nginx` active
    - runaway `wp cron` нет;
  - stale run `#4638` автоматически схлопнулся как `stale_processing_lock_recovered`;
  - orphaned `377` был снят с lane;
  - fresh items снова реально входят в работу:
    - `373` был выбран из `new` и переведён в `processing_de`
    - затем после class-level stale recovery fresh `390` был выбран из `new` и переведён в `processing_de`;
  - для `390` новый trace дошёл до:
    - `primary_ai_attempt`
    - `return_primary_ai`
    - `before_stage_routing_finalize`
    - то есть прежний blind spot до `return_primary_ai` закрыт.

- Что ещё не закрыто:
  - acceptance по autonomous publish не достигнут;
  - текущий stop point сместился внутрь `finalize_payload_for_stage_routing()` / следующего hot path после AI return;
  - runs `#4639/#4640/#4641` показывают, что lane уже умеет recover orphaned workers и снова выбирать fresh `new`, но process terminal decision для свежего item всё ещё не доказан как стабильная серия.

- Следующий фронт:
  - добить точный hotspot после `before_stage_routing_finalize`;
  - подтвердить, что fresh item автоматически доходит минимум до `retry_process/translate_*` без orphaned run;
  - затем восстановить серию `new -> ready_publish -> published` и финальный post audit.

## 2026-03-30 21:53 UTC update

- Продолжение live-работы зафиксировало ещё два системных класса:
  - oversized raw `source_dossier` inside persisted queue payload;
  - sticky `process` lock without explicit short stale window.

- Что изменено:
  - `wp-plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php`
    - persisted `_meta['source_dossier']` switched to compact dossier only;
    - added `compact_payload_source_dossier()` and applied it on all hot read/finalize paths;
    - `process` lock acquire now sets explicit short `stale_after` for new locks.

- Что подтверждено live:
  - сайт и php-fpm remained stable during repeated forced process recovery;
  - queue selector продолжал реально брать fresh `new` items:
    - `373`
    - `390`
    - `386`
    - `383`
  - это подтверждает, что one broken resume item больше не monopolizes the lane indefinitely.

- Что ещё не достигнуто:
  - fresh item всё ещё не доводится до stable `ready_publish/published`;
  - process lane пока остаётся stuck around post-AI stage-routing finalization and still requires orphan recovery between attempts;
  - acceptance remains not reached.

## 2026-03-30 22:06 UTC update

- Продолжение live-работы сузило текущий blocker до точного системного recursion loop inside post-AI routing, а не до cron/lock/site stability.

- Что уже сделано в этом проходе:
  - `wp-plugins/europulse-autopilot-v21/includes/jobs/class-epv2-jobs.php`
    - stale `processing_de` recovery больше не возвращает item в `new`;
    - orphaned process rows теперь системно переводятся в `retry_process` с resume semantics.
  - `wp-plugins/europulse-autopilot-v21/includes/core/class-epv2-source-enricher.php`
    - added context-phrase fallback search over active source pool and broad pool;
    - enrichment больше не зависит только от Bing/Google phrase search.
  - `wp-plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php`
    - stage-routing finalize переведён на lightweight `context_memory`;
    - added internal substep tracing:
      - `stage_routing_after_compact_dossier`
      - `stage_routing_after_normalize_quotes`
      - `stage_routing_after_align_selection`
      - `stage_routing_after_primary_media`
      - `stage_routing_after_fast_quality`
      - `stage_routing_after_light_context_memory`
      - `stage_routing_after_routing_checklist`
    - introduced `refresh_stage_checklist_for_routing()`
    - introduced `payload_next_required_stage_for_routing()`
    - introduced `payload_language_ready_for_routing()`
    - introduced `de_master_ready_for_routing()`
    - post-AI path now calls routing finalize with trace context `(queue_id/run_id/item_started_at)`.

- Что подтверждено live:
  - сайт стабилен:
    - `curl -I http://127.0.0.1/` => `200 OK`
    - `php8.3-fpm` / `nginx` active
    - cron hooks не размножены: `epv2_collect/process/publish` по одному recurring hook each;
  - memory guard реально работает как защитный предохранитель:
    - в `/var/log/europulse-wp-cron.log` есть `skip low-memory ...`
    - при этом guard не уронил сайт и не породил runaway workers;
  - contract “processing item must not return to new” уже live:
    - `383` после orphan recovery ушёл в `retry_process`, а не в `new`;
    - затем `394` также ушёл в `retry_process` с auto-resume message;
  - fresh intake продолжает работать:
    - run `#4648` честно выбрал fresh `new` item `380` в пользу backlog resume items;
    - это подтверждено `queue_selector` logs:
      - `after_fresh_new_item found=1`
      - `after_stage_resume_item found=1`
      - `return_fresh_new_preferred_over_stage`.

- Точный текущий blocker:
  - run `#4648` дошёл до:
    - `before_stage_routing_finalize`
    - `stage_routing_after_compact_dossier`
    - `stage_routing_after_normalize_quotes`
    - `stage_routing_after_align_selection`
    - `stage_routing_after_primary_media`
    - `stage_routing_after_fast_quality`
    - `stage_routing_after_light_context_memory`
  - и завис до `stage_routing_after_routing_checklist`.
  - Это локализовало recursion loop:
    - routing checklist inside post-AI path still re-entered expensive/full stage logic.
  - Важное открытие:
    - `payload_needs_deeper_supporting_enrichment()` делает `normalize_existing_payload(false)`;
    - это недопустимо inside routing checklist because it can re-enter stage normalization while the item is still inside stage-routing finalize.

- Следующий TODO, строго по critical path:
  1. Добить новый fast routing contract так, чтобы `refresh_stage_checklist_for_routing()` и `payload_next_required_stage_for_routing()` не входили ни в какой recursive/full normalization path.
  2. После этого дать один bounded live process run.
  3. Подтвердить, что fresh item проходит дальше из `processing_de` минимум в:
     - `retry_process` с конкретным `pipeline_stage`, либо
     - `ready_review`, либо
     - `ready_publish`,
     а не висит на `36%`.
  4. Затем продолжить до `ready_publish -> published` и final audit.

## 2026-04-01 19:49 UTC update

- Закрыты два повторяющихся blocker class из live-анализа:
  - `KB-059`: stale infra/backlog rows больше не должны съедать single process lane при появлении fresh `new`
  - `KB-060`: source-grounded generated cover признан валидным terminal featured-media fallback, а не ложным media mismatch

- Что изменено:
  - `wp-plugins/europulse-autopilot-v21/includes/queue/class-epv2-queue.php`
    - `is_infra_backlog_process_candidate()` расширен:
      - quarantine теперь покрывает не только worker/provider failures
      - но и `legacy auto ready_review sink`
      - а также пустые stale `rebuild_bundle` rows older than 15 minutes
  - `wp-plugins/europulse-autopilot-v21/includes/core/class-epv2-resilience-manager.php`
    - `normalize_targeted_retry_windows()` теперь ставит retry window и для stale `rebuild_bundle` backlog class
  - `wp-plugins/europulse-autopilot-v21/includes/media/class-epv2-media.php`
    - добавлен `is_generated_story_cover_url()`
    - generated cover теперь считается допустимым релевантным media contract
  - `wp-plugins/europulse-autopilot-v21/includes/ai/class-epv2-ai-processor.php`
    - добавлен `repair_persisted_publish_finish_media_blockers()`
    - batch migration теперь:
      - быстро re-finalize’ит payload с уже готовым generated cover
      - и только при реальном отсутствии media идёт в тяжёлый media repair

- Live-подтверждение:
  - сайт всё время оставался `200 OK`
  - `php8.3-fpm` / `nginx` active
  - runaway `wp cron` / `wp eval` не осталось
  - class-level migration подняла media-blocked items:
    - `424`, `429`, `445`, `416` -> `ready_publish`
  - у них `featured_media_url` уже нормализован в local generated cover и `release_quality = 100`
  - `ready_publish` count вырос до `4`

- Что ещё не закрыто:
  - ближайший publish blocker уже не media contract, а live confirmation следующего scheduled slot:
    - `ready_publish` rows получили `publish_not_before` по очереди слотов
    - на `2026-04-01 19:49 UTC` они ещё не due, поэтому `next_item_for_publish(true)` возвращает `NULL`
  - после ближайшего due-slot нужно подтвердить `ready_publish -> published` серией.

## 2026-04-01 21:36 UTC update

- Закрыт новый инфраструктурный blocker class:
  - `KB-061`: automation idle under `DISABLE_WP_CRON` because EPV2 stayed paused and no external runner existed

- Что изменено:
  - добавлены systemd units:
    - `/etc/systemd/system/europulse-wp-cron.service`
    - `/etc/systemd/system/europulse-wp-cron.timer`
  - timer вызывает:
    - `/usr/bin/php /var/www/europulse/public/wp-cron.php`
    каждую минуту от `www-data`
  - automation resumed:
    - `epv2_automation_paused = false`
  - recurring hooks restored:
    - `epv2_collect`
    - `epv2_process`
    - `epv2_publish`

- Live-подтверждение:
  - сайт остался `200 OK`
  - `php8.3-fpm` / `nginx` active
  - `europulse-wp-cron.timer` active
  - confirmed scheduled process run:
    - run `#5234`
    - `started_at = 2026-04-01 21:36:01 UTC`
    - `processed_item_id = 428`
    - `result = queued_rebuild_bundle_stage`

- Важный вывод:
  - часть жалобы “новые не идут в работу” была не только про selector/process semantics, но и про то, что orchestrator literally was asleep.
  - теперь scheduler path живой, и следующий системный blocker снова чисто orchestration-level:
    - legacy recoverable rows still live in `retry_process`
    - v2 selector preview returns `none` until migration maps recoverable backlog into the new `new/active` contract

- Следующий TODO:
  1. Добить Phase 15 harness вокруг v2 selector/migration.
  2. Разобрать ambiguous migration case `439`.
  3. Подготовить controlled `workflow_v2_apply_migration()` window.
  4. Только после этого включать `orchestrator_v2_enabled`.

## 2026-04-01 22:04 UTC update

- v2 orchestrator switch выполнен на live:
  - сделан дополнительный switch backup:
    - `/root/projects/europulse/backups/orchestrator-v2-switch-20260401T2157Z`
  - применён `workflow_v2_apply_migration()`
  - `orchestrator_v2_enabled = true`
  - automation resumed after controlled migration window

- Что подтверждено live:
  - queue contract migrated:
    - recoverable queue now lives primarily as `new`
    - publishable queue as `ready_publish`
    - terminal rows remain `published/rejected`
  - active owner contract confirmed:
    - active owner remains `428`
    - runtime preview returns:
      - `resume_active_owner`
      - `item_id = 428`
      - `state = new`
      - `workflow_step = publish_ready_gate`
  - first v2 process runs:
    - `#5240` at `2026-04-01 22:01:04 UTC`
    - `#5241` at `2026-04-01 22:02:45 UTC`
    both resumed the same owner `428`

- Новый найденный blocker class:
  - `KB-062`
  - some legacy write paths still push recoverable rows back into raw `retry_process`
  - partial fix already deployed:
    - `canonicalize_single_workflow_state()` collapses recoverable legacy states to `new` under v2
    - `normalize_staged_new_items()` no longer rewrites `new -> retry_process` in v2

- Current live state after switch:
  - site `200 OK`
  - `php8.3-fpm` / `nginx` active
  - `europulse-wp-cron.timer` active
  - active process owner remains single
  - queue counts at checkpoint:
    - `new = 27`
    - `ready_publish = 4`
    - `published = 11`
    - `rejected = 13`

- Next critical path:
  1. remove remaining legacy direct `retry_process` writes from AI processor / resilience paths
  2. confirm repeated v2 process ticks keep the same owner in `new` until terminal transition
  3. confirm `ready_publish -> published` on due slot under v2
## 2026-04-01 23:20 UTC

- Deleted stale `new` rows created before `2026-04-01`.
- Closed `KB-063`: selection gate mismatch.
  - `collect` previously admitted `low/reject` candidates into `new`.
  - `class-epv2-collector.php` now allows queue insertion in auto/publish-grade mode only for `review/strong/priority` plus positive `should_send_to_ai()`.
  - Existing `new` rows with stored `selection.decision in {low,reject}` were migrated to `rejected`.
  - Forced collect run `#5263` confirmed only `review/strong` rows with `ai_gate=ai_full`.
- Open `KB-064`: source `MVG Betriebsmeldungen` (`id=34`) returns 404 and is the current recurring collect error.

## 2026-04-02 01:03 UTC

- Closed `KB-065`: one-step process bottleneck under v2.
  - `run_process_async()` now uses bounded owner burst via `run_process_owner_window()`.
  - Result: one wake now pushes the same owner through multiple internal stages instead of one stage per 5-minute tick.
- Closed `KB-066`: terminal workflow metadata drift.
  - `mark_state()` now clears stale workflow step/owner metadata on `ready_publish/published/rejected/error/duplicate`.
  - Existing terminal rows were migrated in-place.

- Live confirmations:
  - `450 -> published (post_id 3133)` on scheduled publish slot
  - `451 -> published (post_id 3139)` on scheduled publish slot
  - `447 -> published (post_id 3146)` on scheduled publish slot
  - `453 -> ready_publish` reached automatically without manual rescue
  - after `453` completion, automation automatically advanced to next owner `452`
    and moved it through:
    - `queued_translate_uk_stage`
    - `queued_translate_en_stage`
    - `translated_en_successfully`

- Queue checkpoint:
  - `new = 5`
  - `ready_publish = 3`
  - `published = 14`
  - `rejected = 25`

- Current ready queue:
  - `449`
  - `453`
  - plus one more ready item in current queue snapshot

- Current open blockers:
  1. complete publish series for remaining `ready_publish` rows on scheduled slots
  2. verify post-level audit for freshly auto-published posts
  3. decide whether to disable/fix dead source `MVG Betriebsmeldungen` (`KB-064`)
## 2026-04-02 Intake / Rubrication Corrections

- Collector no longer applies per-category intake caps from `source->category_bias` before semantic analysis.
- Live settings were too restrictive (`1/1`) and were raised to `max_collect_per_category=4`, `queue_new_max_per_category=8`, `collect_interval_minutes=15`.
- Budget manager was starving intake by classifying all borderline serious stories (`35-39`) as `low`; added narrow uplift for genuinely newsworthy borderline items.
- Categorizer fixed for:
  - migration tragedies -> `world`
  - deepfake + justice + party politics -> `politik`
  - domestic migration politics (`Merz + Syrer + Deutschland`) -> `politik`
- Live confirmation:
  - diagnostic before fix: `896 items`, `175 duplicate`, `707 reject`, `14 low`, `0 accepted_like`
  - diagnostic after fix: `896 items`, `175 duplicate`, `707 reject`, `4 low`, `10 accepted_like`
  - forced collect run `#5482` produced `8` new rows after previous collect `#5481` produced `0`

## 2026-04-02 Rejected Queue Audit / Rehab

- Rejected queue was audited class-by-class.
- Current dominant reject classes observed:
  - `media`: editorial/source-first media missing or falsely exhausted
  - `translation`: bounded `UK/EN` repair reaching reject too early
  - `context`: hard context rejects after dossier analysis
  - smaller classes: `trim_new`, `planner_replace`, migration leftovers

- Closed `KB-071`: false media reject from editorial CDN download path.
  - `download_url()` with default WP UA produced zero-byte temp files for some editorial images.
  - Added browser-like fallback downloader in `EPV2_Media::attach_from_url()`.
  - Live-confirmed on:
    - `515`
    - `477`
    - `456`
    - `493`
  - Reusable audit script added:
    - `/root/projects/europulse/scripts/epv2_rejected_media_audit.php`

- Closed `KB-072`: translation reject loop backed by stale manual-confirmation flags.
  - `resolve_translation_no_progress_terminally()` now requeues viable rows to `new + translate_uk/en` up to 4 attempts before terminal sink.
  - Stale `_system.manual_confirmation_*` flags are cleared on requeue.
  - Rehab script added:
    - `/root/projects/europulse/scripts/epv2_reactivate_translation_rejects.php`
  - Live rows returned from `rejected` to `new`:
    - `514`
    - `506`
    - `493`
    - `455`
    - `446`
    - `421`

- Queue checkpoint after rehab:
  - `published = 61`
  - `rejected = 39`
  - `new = 21`

- Important current live note:
  - `next_item_for_processing()` now again returns the active rehab item (`421`).
  - Latest finished process run:
    - `#6164`
    - `result = requeued_translate_uk_after_rebuild`
    - `item_id = 421`
  - There is still an active `process started` run `#6165`; next step is to confirm it finishes cleanly and keeps draining the reactivated queue.
## 2026-04-02 18:00 UTC

- New systemic blocker classes confirmed:
  - war stories about Russian military targets could route into `community`
  - war rewrite prompt lacked a specific Russia-vs-Ukraine framing contract
  - media semantic gate for `ukraine_attack` was too permissive for portraits/handshakes
  - selector starvation occurred because `new + publish_finish` rows retained `manual_confirmation_required = media_terminal_auto`
  - translation path still called terminal resolver too early and created reject churn
- Live fixes already deployed:
  - `class-epv2-categorizer.php`
  - `class-epv2-ai-processor.php`
  - `class-epv2-ai-response-validator.php`
  - `class-epv2-media.php`
  - `class-epv2-queue.php`
- Live remediation:
  - queue/post package `521` was corrected from `community` to `ukraine`
  - posts `3519`, `3520`, `3521` now carry Ukraine category terms
- Working plan saved:
  - `/root/projects/europulse/docs/epv2-automation-recovery-plan-2026-04-02.md`
