# EPV2 Error Knowledge Base

## Назначение

Этот документ фиксирует типовые сбои, узкие места и реальные инциденты `EPV2 v2.1`.
Цель:
- быстро распознавать повторяющиеся проблемы;
- видеть их симптоматику;
- понимать корневую причину;
- иметь короткий проверенный способ диагностики и исправления;
- накапливать карту архитектурных слабых мест.

## Формат записи

Для каждого кейса хранить:
- `ID`
- `Категория`
- `Счётчик сбоев`
- `Частота`
- `Последний зафиксированный случай`
- `Симптом`
- `Где проявляется`
- `Корневая причина`
- `Быстрая диагностика`
- `Исправление`
- `Профилактика`
- `Статус`

## Категории

- `QUEUE`
- `WORKFLOW`
- `MEDIA`
- `PUBLISH`
- `ADMIN_UI`
- `STATE_MODEL`
- `VALIDATION`
- `ORCHESTRATION`
- `SOURCE_INGEST`
- `PERFORMANCE`

## Кейсы

## KB-071. Stale quality metadata could survive stage queueing and trap the owner in false `publish_finish/translate` loops

- `Дата`: `2026-04-02`
- `Категория`: `WORKFLOW`
- `Симптом`:
  - owner item kept cycling through `publish_finish -> translate_uk -> translate_en -> publish_finish`;
  - live payload already had long DE content and valid dossier, but `_meta.quality` still said the DE master was too short or weak;
  - the same item held the single processing lane until manual inspection.
- `Где проявляется`:
  - `includes/ai/class-epv2-ai-processor.php`
  - `queue_next_processing_stage()`
  - `normalize_existing_payload(..., false)`
  - `fast_stage_routing_quality()`
- `Корневая причина`:
  - stage queueing persisted the next step on top of an only partially refreshed payload;
  - `fast_stage_routing_quality()` returned cached `_meta.quality` if it already existed;
  - cheap normalization path refreshed checklist flags, but not the quality cache used by `de_master_is_viable_fast()`;
  - as a result, routing kept trusting stale quality warnings after DE lift already fixed the content.
- `Быстрая диагностика`:
  - inspect queue item payload and compare:
    - actual DE content length
    - `_meta.quality.score`
    - `_meta.quality.warnings`
  - if DE content is already well above budget but quality still says "too short", the payload is stale;
  - confirm repeated run sequence in `ep_epv2_runs`:
    - `queued_translate_uk_stage`
    - `queued_translate_en_stage`
    - `queued_publish_finish_stage`
    - same item repeatedly.
- `Исправление`:
  - `queue_next_processing_stage()` now re-runs `finalize_payload_for_queue()` before asserting and persisting the next stage;
  - `fast_stage_routing_quality()` no longer blindly reuses cached `_meta.quality`;
  - `normalize_existing_payload(..., false)` now refreshes fast routing quality before rebuilding the checklist;
  - existing `new/ready_publish` rows were normalized to remove stale quality metadata.
- `Профилактика`:
  - no stage transition may be persisted on stale payload metadata;
  - cheap routing helpers may cache less, but they cannot trust stale quality when content has changed.
- `Статус`: `исправлено`

## KB-072. Review/publish refresh could keep stale `stage_checklist.ready_publish` and create false `ready_publish`

- `Дата`: `2026-04-02`
- `Категория`: `PUBLISH`
- `Симптом`:
  - item looked `ready_publish` in queue and could even enter publish;
  - once publish refreshed review metrics, editorial quality dropped below publish gate;
  - the item bounced back to auto-finish because publish and queue disagreed about readiness.
- `Где проявляется`:
  - `includes/review/class-epv2-review.php`
  - `includes/ai/class-epv2-ai-processor.php`
  - `includes/publish/class-epv2-publisher.php`
- `Корневая причина`:
  - `refresh_review_metrics()` recalculated `quality/seo/release/google`, but did not rebuild `stage_checklist`;
  - `payload_ready_for_publish()` trusted `stage_checklist.ready_publish` as a shortcut, even when current metrics no longer met the full publish gate.
- `Быстрая диагностика`:
  - compare on the same payload:
    - `_meta.quality.score`
    - `_meta.stage_checklist.ready_publish`
    - `EPV2_AI_Processor::payload_is_publish_ready($payload)`
  - if quality is below `100` but checklist still says `ready_publish`, the payload is stale.
- `Исправление`:
  - `EPV2_Review::refresh_review_metrics()` now normalizes payload again after refreshing metrics;
  - `payload_ready_for_publish()` no longer accepts `ready_publish` shortcut unless current `quality/seo/release/google` all pass the full publish gate.
- `Профилактика`:
  - any metrics refresh must rebuild readiness checklist in the same pass;
  - publish shortcut flags may never outrank current metrics.
- `Статус`: `исправлено`

### KB-021

- `Категория`: `ORCHESTRATION`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется на long-running rebuild`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: `process` run живёт `100-200s`, но runtime maintenance добивает его как hung/orphan примерно через `45-90s`.
- `Где проявляется`: live `epv2_process`, `recover_orphan_process_lock()`, long AI/enrichment rebuild path.
- `Корневая причина`:
  - после перевода `run_process_windowed()` на inline executor внутри cron/CLI исчез второй async-hop;
  - `recover_orphan_process_lock()` всё ещё держал агрессивные fast-recovery thresholds `45s/90s`;
  - длинный AI/network rebuild шёл без промежуточного heartbeat и ошибочно классифицировался как hung, хотя lock TTL был нормальный (`900s`).
- `Быстрая диагностика`:
  - посмотреть `ep_epv2_runs`:
    - `hung_process_lock_recovered`
    - `stuck_process_lock_quick_recovered`
    - `stuck_without_processing_item_recovered`
  - сопоставить с `ep_epv2_log`, где run успевает дойти до `after_stage_flags`/AI step и потом срезается recovery-pass’ом;
  - проверить `epv2_lock_process.heartbeat_at` против fast threshold и `job_lock_ttl_seconds`.
- `Исправление`:
  - убрать `45s/90s` fast orphan-recovery ветки для `process`;
  - оставлять recovery только по нормальному stale window, привязанному к lock TTL.
- `Профилактика`:
  - orphan recovery не должен быть жёстче, чем реальная длительность blocking AI/network call в inline orchestration mode.
- `Статус`: `исправлено`

## KB-048. Workerless stale `processing_de` lock recovery был несимметричен и оставлял lane заблокированным

- `Дата`: `2026-03-30`
- `Симптом`:
  - после убитого или timeout `wp eval EPV2_Jobs::run_process_async()` fresh `new` больше не подхватывались;
  - `epv2_lock_process` и `epv2_active_automation_item` оставались живы, а row продолжала висеть в `processing_de`;
  - следующий `process` early-return из-за active lock, хотя живого worker уже не было.
- `Корневая причина`:
  - `recover_orphan_process_lock()` уже ввёл fast workerless stale window `90-180s` для heartbeat;
  - но сам `processing_de` item возвращался в очередь только по старому `updated_at <= stale_after` с half-TTL (`300-600s`);
  - lock и row оценивались по разным stale thresholds.
- `Исправление`:
  - `includes/jobs/class-epv2-jobs.php`
    - workerless recovery теперь использует один и тот же `effective_stale_after` и для heartbeat, и для `processing_de.updated_at`.
- `Live-подтверждение`:
  - stale run `#4638` автоматически закрылся как `stale_processing_lock_recovered`;
  - orphaned `377` ушёл из зависшего `processing_de`;
  - после recovery selector снова взял fresh `new` item в работу.
- `Профилактика`:
  - orphan recovery не должен оставлять row в `processing_de`, если lock уже признан workerless-stale;
  - stale thresholds для lock и queue state должны совпадать.
- `Статус`: `исправлено`

## KB-049. Deferred DE-first AI path всё ещё делал full enrich/validation после ответа модели и держал process lane

- `Дата`: `2026-03-30`
- `Симптом`:
  - после `Review payload AI response` process worker раздувался до сотен MiB / ~1 GiB RSS;
  - логи останавливались сначала до `return_primary_ai`, затем после `return_primary_ai`;
  - fresh items входили в `processing_de`, но lane не принимал terminal decision и оставлял orphaned runs.
- `Корневая причина`:
  - даже при `defer_translations=true` код внутри `attempt_ai_payload()` выполнял heavy `EPV2_AI_Response_Validator::enrich_payload()` и полный `validate()` с quality/media/SEO stack;
  - общий post-AI path продолжал использовать full queue finalize вместо дешёвого stage-routing finalize;
  - hot path тащил больше publish-finish работы, чем нужно для DE-first routing decision.
- `Исправление`:
  - `includes/ai/class-epv2-ai-processor.php`
    - deferred AI path переведён на cheap validation без full enrich/validate;
    - added `finalize_payload_for_stage_routing()` и fast stage-routing quality/context memory;
    - full `payload_context_memory()` removed from hot routing/persist path in favor of lightweight seed;
    - added trace points around stage-routing finalize/persist.
- `Live-подтверждение`:
  - для item `390` после патча появились логи:
    - `primary_ai_attempt`
    - `return_primary_ai`
    - `before_stage_routing_finalize`
  - это подтвердило, что heavy stop point сдвинут дальше и предыдущий blind spot закрыт.
- `Профилактика`:
  - DE-first process hot path должен готовить только routing-ready DE master, а не publish-grade bundle;
  - full SEO/media/release validation допустим только в publish-finish/publish stages.
- `Статус`: `в работе`

## KB-050. Raw `source_dossier` внутри queue payload раздувал PHP memory на каждом retry/process resume

- `Дата`: `2026-03-30`
- `Симптом`:
  - process worker после AI response разрастался до сотен MiB RSS;
  - каждый resume одного и того же item снова зависал на post-AI finalize;
  - даже после облегчения AI/deferred path backlog items продолжали повторять тот же memory spike.
- `Корневая причина`:
  - в `_meta['source_dossier']` сохранялся полный raw dossier с большими `content/excerpt/supporting` blobs;
  - на каждом `normalize/finalize/persist` PHP копировал этот массив снова;
  - backlog items, уже записанные в queue с raw dossier, продолжали rehydrate the same bloat on every retry.
- `Исправление`:
  - `includes/ai/class-epv2-ai-processor.php`
    - stored payload dossier switched to `compact_source_dossier(...)`;
    - added `compact_payload_source_dossier()` and applied it on read paths:
      - `normalize_existing_payload()`
      - `finalize_payload_for_queue()`
      - `finalize_payload_for_stage_routing()`
- `Live-подтверждение`:
  - после фикса selector продолжил брать новые `new` items (`383`, затем remaining fresh queue) вместо вечного удержания одним stale oversized payload;
  - post-AI trace дошёл до более поздних step boundaries, чем до compaction patch.
- `Профилактика`:
  - queue payload должен хранить только compact dossier contract;
  - raw source bundle допустим только локально внутри generation flow, но не в persisted queue state.
- `Статус`: `в работе`

## KB-051. Process lock слишком долго оставался active после смерти worker и замедлял self-recovery

- `Дата`: `2026-03-30`
- `Симптом`:
  - после смерти worker `epv2_lock_process` мог ещё долго считаться active;
  - последующие `run_process_async()` быстро выходили без нового run из-за sticky lock;
  - recovery зависел от ручного или дополнительного orphan pass.
- `Корневая причина`:
  - process lock acquire использовал default stale heartbeat envelope lock-manager;
  - даже после live recovery fixes новый process lock не нёс короткий explicit `stale_after`.
- `Исправление`:
  - `includes/ai/class-epv2-ai-processor.php`
    - `EPV2_Lock_Manager::acquire('process', ...)` теперь передаёт meta `stale_after=90..180s`;
    - heartbeat использует тот же process TTL variable consistently.
- `Live-подтверждение`:
  - current stale lock class formalized and new locks are now created with shorter stale window;
  - site stayed stable while repeated process recoveries were executed.
- `Профилактика`:
  - long-running process lane must advertise its own stale window explicitly;
  - default generic lock stale policy is too weak for fragile AI worker paths.
- `Статус`: `исправлено`

## KB-052. Host-level OOM from wp-cli cron killed tmux/user session instead of self-contained job

- `Дата`: `2026-03-30`
- `Категория`: `PERFORMANCE`
- `Симптом`:
  - user lost previous `tmux` session;
  - `Termius` became sluggish/unresponsive;
  - kernel logged global `OOM killer`;
  - `systemd` reported OOM impact on `user.slice` / `tmux` scope.
- `Где проявляется`:
  - host runtime around `/usr/local/bin/europulse-safe-cron.sh`
  - `wp-cli cron event run ...`
  - especially `epv2_process`
- `Корневая причина`:
  - safe-cron checked free memory only before starting commands;
  - already-running `php` process could still expand into multi-GiB RSS;
  - under global memory pressure kernel killed the biggest victim and collateral damage reached user session scope.
- `Быстрая диагностика`:
  - `dmesg -T | tail -n 100`
  - `journalctl --since '2026-03-30 22:10:00' --no-pager`
  - `tail -n 120 /var/log/europulse-wp-cron.log`
  - look for:
    - `Out of memory: Killed process ... (php)`
    - `tmux-spawn...scope: A process of this unit has been killed by the OOM killer`
    - repeated `skip low-memory`
- `Исправление`:
  - run each `wp-cli` cron task in transient `systemd` unit with:
    - `MemoryHigh`
    - `MemoryMax`
    - `MemorySwapMax`
    - `OOMPolicy=kill`
  - add emergency watchdog timer that kills `europulse-wpcron-*`/legacy `wp-cli` jobs when memory PSI and free memory cross red lines.
- `Профилактика`:
  - long-running cron jobs must fail in isolation before host-wide OOM;
  - protection must target the job cgroup, not rely only on pre-launch free-memory checks.
- `Статус`: `смягчено, root cause process ballooning still under investigation`

## KB-053. Manual mode built non-German DE master from foreign-language source URL and broke downstream translations

- `Дата`: `2026-03-30`
- `Категория`: `WORKFLOW`
- `Симптом`:
  - in manual mode operator pasted a source URL in non-German language;
  - imported draft/master stayed in source language;
  - later `uk/en` translations could not be generated correctly because canonical base was not German.
- `Где проявляется`:
  - `EPV2` admin manual mode
  - `manual_op=import_url`
  - `manual_op=to_review`
  - follow-up `rewrite_*` / SEO refinement
- `Корневая причина`:
  - manual `to_review` path did not use DE-first AI review payload generation;
  - it used `EPV2_Review::build_payload_without_ai_from_dossier(..., true)` and directly copied raw imported text into `languages.de`;
  - manual field rewrite/SEO prompts also did not explicitly force German output.
- `Быстрая диагностика`:
  - import a non-German URL in manual mode;
  - send to review;
  - inspect `ai_payload.languages.de.title/excerpt/content`;
  - if DE fields remain in source language while `_meta.canonical_language = de`, bug is present.
- `Исправление`:
  - in manual mode `create_review_item()` switch primary path to:
    - `EPV2_AI_Processor::generate_review_payload($item, $categories, $style, true)`
  - keep lightweight dossier build only as last fallback;
  - update manual rewrite/SEO prompts to require German-only output.
- `Профилактика`:
  - every manual-mode path must preserve the DE-first contract;
  - canonical DE payload must never be populated by untranslated foreign-language source text.
- `Статус`: `исправлено, live admin path still needs one real end-to-end verification`

## KB-054. Persisted payload contract drift kept stale translation flags and invalid media references after code fixes

- `Дата`: `2026-04-01`
- `Категория`: `STATE_MODEL`
- `Симптом`:
  - terminal or near-terminal rows kept contradictory payload state after earlier logic fixes;
  - examples observed on live:
    - `published` row with `translations_deferred = true` despite complete `uk/en`
    - `retry_process/rebuild_bundle` rows with `media_url` pointing to article HTML page instead of image
    - stage router re-entered translation/rebuild paths from polluted persisted payload.
- `Где проявляется`:
  - persisted `ep_epv2_queue.ai_payload`
  - resume paths using `normalize_existing_payload(..., false)`
  - publish/process routing on old rows created before newer invariants were introduced
- `Корневая причина`:
  - code fixes on hot path did not retroactively sanitize already persisted payloads;
  - `_meta.translations_deferred` could remain stale even when `uk/en` were ready;
  - root/media fields could keep invalid non-image URLs from older payload generation paths;
  - selector/process then resumed rows with stale internal contract.
- `Быстрая диагностика`:
  - run regression check against queue payloads and look for:
    - `stale_translations_deferred`
    - `invalid_media_url`
    - `invalid_featured_media_url`
    - `terminal_stage_tail`
    - `published_incomplete_translation_contract`
- `Исправление`:
  - `includes/ai/class-epv2-ai-processor.php`
    - added `normalize_payload_contract_flags()`
    - `normalize_existing_payload()` now sanitizes root/lang media refs and synchronizes `translations_deferred`
    - added queue-wide migration helper `normalize_persisted_queue_contracts()`
    - added regression helper `queue_contract_regression_check()`
  - added reproducible harness:
    - `/root/projects/europulse/scripts/epv2_queue_contract_check.php`
- `Migration`:
  - live normalization run checked `53` payload rows and rewrote `41`
  - examples repaired in-place included `420`, `434`, `438`, `441`, `443`
- `Regression check`:
  - `php /root/projects/europulse/scripts/epv2_queue_contract_check.php --normalize --limit=800`
  - result after migration: `violations = []`
- `Профилактика`:
  - any new invariant on payload contract must be followed by queue-wide normalization of existing rows;
  - process/publish fixes are incomplete until old persisted payloads are migrated;
  - queue health must be machine-checked, not inferred from one live item.
- `Статус`: `исправлено`

## KB-055. Auto translation failures were sent to `ready_review` instead of automatic terminal split

- `Дата`: `2026-04-01`
- `Категория`: `WORKFLOW`
- `Симптом`:
  - recoverable automation items could end in `ready_review` with `manual_confirmation_required=translation`;
  - queue then required operator rescue even though the failure came from bounded automatic translation attempts, not from a true data-loss/manual-only situation.
- `Где проявляется`:
  - single-language repair branch in `process_scheduled()`
  - old persisted rows `354`, `421`
- `Корневая причина`:
  - after two failed `translate_uk/translate_en` attempts the process path called `queue_translation_manual_confirmation()` directly;
  - there was no automatic terminal split between:
    - successful bounded full-bundle repair
    - automatic rejection when language repair remained unsalvageable.
- `Быстрая диагностика`:
  - inspect `ready_review` rows for:
    - `manual_confirmation_required = translation`
    - `translations_deferred = true`
    - missing `uk` or `en`
- `Исправление`:
  - `includes/ai/class-epv2-ai-processor.php`
    - single-language no-progress branch now calls `resolve_translation_no_progress_terminally()`
    - bounded full-bundle repair is attempted once more automatically
    - if language still fails, item is auto-rejected instead of being sent to manual review
    - added migration helper `resolve_persisted_translation_manual_reviews()`
  - harness extended:
    - `/root/projects/europulse/scripts/epv2_queue_contract_check.php --resolve-translation-manual`
- `Migration`:
  - live migration resolved old translation-manual rows:
    - `354 -> rejected_translation_unsalvageable`
    - `421 -> rejected_translation_unsalvageable`
- `Regression check`:
  - queue regression after migration returned `violations = []`
- `Профилактика`:
  - translation no-progress is not a manual-review class by default;
  - after bounded automatic repair, the system must choose terminal auto outcome, not `ready_review`.
- `Статус`: `исправлено`

### KB-022

- `Категория`: `PERFORMANCE`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется на new items без payload`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: live `process` пишет warnings `Undefined property: stdClass::$original_content/original_date` и шумит на baseline/generation path.
- `Где проявляется`: `EPV2_AI_Processor::process_scheduled()`, `generate_review_payload()`, `build_messages()`.
- `Корневая причина`:
  - queue selector был правильно облегчён до summary rows;
  - но AI generation/rebuild helpers продолжали ожидать full queue row с `original_content/original_date`;
  - в итоге summary item проходил в expensive AI path без нужных полей.
- `Быстрая диагностика`:
  - запустить live `wp cron event run epv2_process`;
  - увидеть warnings по `class-epv2-ai-processor.php` на обращении к `original_content`;
  - проверить, что item пришёл из `get_queue_items_summary()`, а не из `get_item()`.
- `Исправление`:
  - в `process_scheduled()` добирать full row только для baseline path, где payload ещё пустой;
  - в `generate_review_payload()` и publish-grade lift добавить lazy hydration full row через `EPV2_Queue::get_item()` только когда summary object не содержит text/date fields.
- `Профилактика`:
  - после любого перехода на summary rows проверять downstream helpers на скрытую зависимость от full queue schema.
- `Статус`: `исправлено`

### KB-023

- `Категория`: `WORKFLOW`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется на generic service pages`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: weak item уходит в `rebuild_bundle` по нескольку циклов, хотя source — не news story, а evergreen service/landing page без событийного сигнала.
- `Где проявляется`: `generate_review_payload()`, contextual scoring, live loop case `queue item 345`.
- `Корневая причина`:
  - primary source мог быть сервисной страницей вроде Bahn `/service/fahrplaene`;
  - supporting search честно отрабатывал, но не находил story-relevant support;
  - contextual analyzer всё ещё оставлял такой item в recoverable path, и automation гоняла бессмысленный rebuild loop.
- `Быстрая диагностика`:
  - проверить primary URL/path и title;
  - если это generic service/help/info page, supporting count остаётся `0`, а `story_context.search_terms` состоит из служебных фраз;
  - в live logs смотреть:
    - `source_enriched` / `source_supporting_boost` с `supporting_count=0`
    - затем repeated `rebuild_bundle`
- `Исправление`:
  - в `EPV2_Budget_Manager::analyze_contextual()` добавить terminal reject для non-news service/landing pages без supporting context и без event signal;
  - оставить такой case в `rejected_by_context`, а не в endless rebuild.
- `Профилактика`:
  - generic service/help/FAQ pages не должны считаться recoverable news, если forced supporting search не дал story context.
- `Статус`: `исправлено`

### KB-024

- `Категория`: `WORKFLOW`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется на weak publish_finish cases`
- `Последний зафиксированный случай`: `2026-03-29`
- `Симптом`: item застревает в ложной oscillation между `publish_finish` и `rebuild_bundle`, хотя проблема в том, что переводы ещё не готовы, а не в самом DE master.
- `Где проявляется`: `payload_next_required_stage()`, `de_master_ready_for_translation()`, live loop case `queue item 354`.
- `Корневая причина`:
  - readiness для translation path зависела от final featured media;
  - item с сильным DE master, но без media и без готовых `UK/EN`, prematurely уходил в `publish_finish`;
  - дальше `publish_finish` пытался лечить media/SEO слишком рано и порождал stage oscillation.
- `Быстрая диагностика`:
  - проверить payload:
    - `translations_deferred = true`
    - `uk_ready = false`
    - `en_ready = false`
    - `featured_media = ''`
  - если при этом `payload_next_required_stage()` возвращает `publish_finish` вместо `translate_uk`, значит gate сломан.
- `Исправление`:
  - убрать зависимость translation readiness от final featured media в `de_master_ready_for_translation()`;
  - после этого router снова отправляет item в `translate_uk/en`, а media cleanup оставляет на реальный `publish_finish`.
- `Профилактика`:
  - media/SEO gates не должны блокировать языковой pipeline, если DE master уже пригоден для перевода.
- `Статус`: `исправлено`

### KB-001

- `Категория`: `ADMIN_UI`
- `Счётчик сбоев`: `1`
- `Частота`: `редко`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: страница `Очередь` падает с критической ошибкой.
- `Где проявляется`: `wp-admin -> AutoPilot -> Очередь`
- `Корневая причина`: после переделки модели очереди остались старые переменные в рендере:
  - использовался `$draft_queue_items`, хотя блок черновиков уже убран;
  - в `queue_snapshot()` не создавался `$ready_publish_items`.
- `Быстрая диагностика`:
  - открыть `EPV2_Admin::queue()` через `wp eval`;
  - проверить все вызовы `queue_blocks_html()`;
  - проверить соответствие списка аргументов фактической сигнатуре.
- `Исправление`:
  - добавить `$ready_publish_items` в оба рендера;
  - убрать остаточный `$draft_queue_items`;
  - синхронизировать live-копию `class-epv2-admin.php`.
- `Профилактика`:
  - после каждого изменения сигнатуры render-helper сразу проверять все вызовы `rg queue_blocks_html`.
- `Статус`: `исправлено`

### KB-002

- `Категория`: `STATE_MODEL`
- `Счётчик сбоев`: `2`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: материал переходит в `ready_publish`, но остаётся в блоке `В работе`.
- `Где проявляется`: очередь в админке.
- `Корневая причина`:
  - active slot не освобождался при переходе в `ready_publish`;
  - `is_active_work_item()` принимал слишком широкий набор состояний.
- `Быстрая диагностика`:
  - сравнить `epv2_active_automation_item` и `state` item;
  - проверить `sync_active_automation_item()` и `is_active_work_item()`.
- `Исправление`:
  - active slot держать только для:
    - `processing_de`
    - `retry_process`
    - `ready_review`
  - `ready_publish/retry_publish/publishing` показывать только в блоке `Готово к публикации`.
- `Профилактика`:
  - любое новое состояние сразу классифицировать как:
    - `active work`
    - `new queue`
    - `ready publish`
    - `terminal`
- `Статус`: `исправлено`

### KB-003

- `Категория`: `MEDIA`
- `Счётчик сбоев`: `3`
- `Частота`: `часто`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: валидный fallback media находится, но payload остаётся без `featured_media_url`.
- `Где проявляется`: `publish_finish`, auto-repair media, `ready_publish` не наступает.
- `Корневая причина`:
  - валидный `Pexels` мог попасть в `blocked_media_urls`;
  - source-media был невалиден;
  - `repair_payload_media()` не реабилитировал валидный fallback достаточно последовательно.
- `Быстрая диагностика`:
  - проверить:
    - `featured_media_url`
    - `media_url`
    - `_meta.blocked_media_urls`
    - `validate_featured_media(candidate)`
  - отдельно проверить кандидаты:
    - `source_dossier_image`
    - `parsed_supporting_image`
    - `context_supporting_image`
    - `pexels_media`
    - `wikimedia_media`
- `Исправление`:
  - реабилитировать валидные blocked-candidates;
  - сначала пробовать source-first;
  - если source-media реально непригодно, разрешать fallback.
- `Профилактика`:
  - blocked list не должен навсегда банить валидный media-кандидат.
- `Статус`: `частично исправлено`

### KB-004

- `Категория`: `VALIDATION`
- `Счётчик сбоев`: `2`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: у item уже есть валидный `featured_media_url`, но `release/google` продолжают писать:
  - `нет featured media`
  - `нет главного изображения для Google`
- `Где проявляется`: `publish_finish`, final scoring.
- `Корневая причина`:
  - `enrich_payload()` заново вызывал `resolve_featured_media()` и мог обнулять уже валидный `featured_media_url`.
- `Быстрая диагностика`:
  - сравнить payload до и после `EPV2_AI_Response_Validator::enrich_payload()`;
  - если `featured_media_url` был, а после enrich стал пустым, значит проблема в validator path.
- `Исправление`:
  - если `featured_media_url` уже валиден и не заблокирован, сохранять его и не переопределять повторным resolver-проходом.
- `Профилактика`:
  - resolver не должен работать как destructive-normalizer.
- `Статус`: `исправлено`

### KB-005

- `Категория`: `MEDIA`
- `Счётчик сбоев`: `2`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: для чувствительной темы source-media отсутствует или невалидно, но stock fallback полностью запрещён, из-за чего item застревает без изображения.
- `Где проявляется`: `publish_finish`, `resolve_featured_media()`.
- `Корневая причина`:
  - `stock_fallback_allowed()` был слишком жёстким и запрещал fallback даже когда publishable source-media фактически не существовало.
- `Быстрая диагностика`:
  - проверить:
    - `stock_fallback_allowed()`
    - `has_publishable_source_media()`
    - `validate_featured_media(source_image)`
- `Исправление`:
  - сохранять правило `source-first`;
  - но если source/dossier не дают ни одного реально publishable media, разрешать последний stock fallback.
- `Профилактика`:
  - запрет stock должен зависеть не только от темы, но и от факта наличия пригодного source-media.
- `Статус`: `исправлено`

### KB-006

- `Категория`: `QUEUE`
- `Счётчик сбоев`: `4`
- `Частота`: `часто`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: в очереди видны несколько материалов `в доработке`, хотя должен быть только один активный.
- `Где проявляется`: queue UI и storage states.
- `Корневая причина`:
  - active item нормализовался не везде;
  - неактивные recoverable states не всегда принудительно возвращались в `new`.
- `Быстрая диагностика`:
  - проверить:
    - `epv2_active_automation_item`
    - все items в `processing_de/retry_process/ready_review/reserve`
- `Исправление`:
  - принудительная нормализация всех неактивных recoverable item в `new`.
- `Профилактика`:
  - запускать нормализацию:
    - в runtime maintenance
    - при смене active slot
    - при queue snapshot
- `Статус`: `исправлено`

### KB-007

- `Категория`: `WORKFLOW`
- `Счётчик сбоев`: `4`
- `Частота`: `часто`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: item зависает в `publish_finish`.
- `Где проявляется`: активный material в automation loop.
- `Корневая причина`:
  - один из финальных publish-grade gates не пройден:
    - media
    - release
    - google
    - scoring bundle
    - multilingual integrity
- `Быстрая диагностика`:
  - проверить:
    - `_meta.stage_checklist`
    - `_meta.quality`
    - `_meta.seo_quality`
    - `_meta.release_quality`
    - `_meta.google_quality`
    - `payload_is_publish_ready()`
    - `payload_next_required_stage()`
- `Исправление`:
  - добивать конкретный финальный blocker, а не гонять item по кругу.
- `Профилактика`:
  - `publish_finish` должен быть диагностическим stage, а не “чёрным ящиком”.
- `Статус`: `актуально`

### KB-008

- `Категория`: `PUBLISH`
- `Счётчик сбоев`: `4`
- `Частота`: `часто`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: `ready_publish` не публикуется на сайте вовремя.
- `Где проявляется`: блок `Готово к публикации`, publish cron.
- `Корневая причина`:
  - исторически было смешано:
    - safe mode drafts
    - ready_publish scheduling
    - active slot
    - publish preflight media
- `Быстрая диагностика`:
  - проверить:
    - `default_post_status`
    - `publish_interval_minutes`
    - `next_ready_publish_timestamp()`
    - `state`
    - `publish_not_before`
- `Исправление`:
  - убрать drafts как рабочий этап;
  - держать готовые материалы только в `ready_publish`;
  - публиковать на полном автоматическом 5-минутном цикле.
- `Профилактика`:
  - не смешивать UI-блоки и технические publish-state.
- `Статус`: `в работе`

### KB-009

- `Категория`: `MEDIA`
- `Счётчик сбоев`: `2`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: публикация зависает до первой вставки поста, хотя payload уже имеет валидное featured image.
- `Где проявляется`: `publish_item()`, стадия `shared publish media`.

### KB-010

- `Категория`: `ADMIN_UI`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: простое открытие страницы `Очередь` или её AJAX-обновление меняет state очереди и создаёт лишние записи в БД.
- `Где проявляется`: `wp-admin -> AutoPilot -> Очередь`, `wp_ajax_epv2_queue_snapshot`
- `Корневая причина`:
  - `queue()` вызывал `prune_rejected()` прямо на render path;
  - `queue_snapshot()` вызывал `normalize_non_active_recoverable_items()` на read-only AJAX path.
- `Быстрая диагностика`:
  - открыть страницу очереди несколько раз подряд;
  - проверить `updated_at/state` у recoverable items и число `UPDATE/DELETE` в slow query log;
  - убедиться, что view path ничего не чинит сам по себе.
- `Исправление`:
  - убрать `prune_rejected()` из `EPV2_Admin::queue()`;
  - убрать `normalize_non_active_recoverable_items()` из `EPV2_Admin::queue_snapshot()`;
  - оставить нормализацию и cleanup только в orchestration/runtime paths.
- `Профилактика`:
  - все admin/list/review render-paths считать read-only по умолчанию;
  - любые repair/cleanup выносить в runner, cron или явные maintenance actions.
- `Статус`: `исправлено`

### KB-011

- `Категория`: `ORCHESTRATION`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: process/publish orchestration делает лишние scheduler-проверки и broad cleanup слишком часто, что раздувает DB load и усложняет диагностику cron duplication.
- `Где проявляется`: `EPV2_Jobs::dispatch_async()`, `EPV2_Jobs::maintain_runtime_state()`
- `Корневая причина`:
  - в `dispatch_async()` остался мёртвый слой Action Scheduler, хотя фактически использовались только `*_async` single cron events;
  - тяжёлый runtime maintenance вызывался на каждом windowed и async tick без throttle.
- `Быстрая диагностика`:
  - проверить, что `dispatch_async()` всегда получает `epv2_*` и переводит их в `*_async`;
  - проверить repeated вызовы `cleanup/normalize/promote` в течение нескольких секунд;
  - сравнить число scheduler-checks и option writes на одном process/publish цикле.
- `Исправление`:
  - оставить один фактический async transport через `wp_schedule_single_event()`;
  - удалить мёртвые ветки Action Scheduler из hot path;
  - добавить короткий throttle на тяжёлую часть `maintain_runtime_state()`, сохранив быстрый orphan-lock recovery без задержки.
- `Профилактика`:
  - не держать несколько асинхронных транспортов в одном orchestration path без реальной необходимости;
  - expensive cleanup/normalization выполнять по cadence, а не на каждом тике.
- `Статус`: `исправлено`

### KB-012

- `Категория`: `ADMIN_UI`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: простое открытие review-страницы может запускать тяжёлый `validator/enrich` пересчёт и создавать лишнюю CPU/DB нагрузку даже без сохранения.
- `Где проявляется`: `wp-admin -> AutoPilot -> Проверка материала`
- `Корневая причина`:
  - `EPV2_Review::prepare_review_payload()` игнорировал `REVIEW_METRICS_TTL`;
  - review-open path не был чётко отделён от commit/publish validation path.
- `Быстрая диагностика`:
  - открыть один и тот же item review несколько раз подряд;
  - проверить repeated вызовы `EPV2_AI_Response_Validator::enrich_payload()` и quality calculators;
  - сравнить поведение open path и `save/review_ready_publish/publish_now`.
- `Исправление`:
  - включить реальный TTL для review metrics snapshot;
  - не обновлять heavy metrics на каждом open для свежего stored payload;
  - full refresh выполнять перед `save_review`, `queue_to_publish`, `review_ready_publish`, `publish_now`.
- `Профилактика`:
  - review render path должен быть snapshot-first;
  - publish-grade validation должен жить в commit/gating actions, а не в простом UI render.
- `Статус`: `исправлено`

### KB-013

- `Категория`: `PERFORMANCE`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: publish tick делает повторный broad cleanup sweep перед самой публикацией, хотя runtime maintenance уже был выполнен в orchestration layer.
- `Где проявляется`: `EPV2_Publisher::publish_scheduled()`
- `Корневая причина`:
  - `EPV2_Jobs::maintain_runtime_state()` уже запускал cleanup/normalization;
  - `publish_scheduled()` повторно вызывал `EPV2_Resilience_Manager::cleanup()` и `EPV2_Queue::prune_rejected()`.
- `Быстрая диагностика`:
  - посмотреть call chain `run_publish_async() -> publish_scheduled()`;
  - убедиться, что на одном publish tick broad cleanup идёт дважды;
  - сравнить число queue updates / option writes до и после удаления дубликата.
- `Исправление`:
  - убрать повторный `cleanup()` и `prune_rejected()` из `publish_scheduled()`;
  - оставить broad maintenance в orchestration/runtime layer, а publish path держать focused on publish work.
- `Профилактика`:
  - не дублировать broad repair sweeps внутри stage executors, если они уже есть в dispatcher/runtime layer;
  - publish/process executors должны выполнять только stage-specific работу.
- `Статус`: `исправлено`

### KB-014

- `Категория`: `PERFORMANCE`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: hot queue/publish paths читают полный row очереди там, где реально используются только summary-поля, что раздувает память и JSON churn.
- `Где проявляется`: `EPV2_Queue::mark_state()`, `EPV2_Queue::set_live_status()`, active-item checks, `EPV2_Publisher::promote_publishable_review_items()`
- `Корневая причина`:
  - использовался `SELECT *` / `get_item()` даже для операций, которым достаточно `SUMMARY_FIELDS`;
  - publish review-promotion вручную делал `SELECT *` по `ready_review`.
- `Быстрая диагностика`:
  - проверить call sites `get_item()` в hot path;
  - сравнить фактически используемые поля с `SUMMARY_FIELDS`;
  - посмотреть memory/CPU на repeated queue/process/publish ticks.
- `Исправление`:
  - добавить `EPV2_Queue::get_item_summary()`;
  - перевести `mark_state()`, `set_live_status()`, `focused_automation_item()`, `has_active_processing_item()` на summary fetch;
  - перевести `promote_publishable_review_items()` на `get_queue_items_summary()`.
- `Профилактика`:
  - full row читать только там, где реально нужны тяжёлые поля вне `SUMMARY_FIELDS`;
  - для orchestration/hot selection/render paths держать отдельные summary-level fetch helpers.
- `Статус`: `исправлено`

### KB-015

- `Категория`: `PERFORMANCE`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: `Resilience` делает отдельный лишний pass по тем же recoverable states, что уже обрабатываются в соседнем normalize-pass.
- `Где проявляется`: `EPV2_Resilience_Manager::cleanup()`, `EPV2_Resilience_Manager::cleanup_review_support()`
- `Корневая причина`:
  - `normalize_failed_de_master_stages()` отдельно проходил `new/retry_process/ready_review/error`;
  - тот же `minimum DE master quality -> rebuild_bundle` fix уже выполнялся внутри `normalize_retry_metadata()` на том же наборе состояний.
- `Быстрая диагностика`:
  - сравнить state coverage `normalize_failed_de_master_stages()` и `normalize_retry_metadata()`;
  - убедиться, что один и тот же item может декодироваться и переписываться дважды за один cleanup cycle.
- `Исправление`:
  - удалить отдельный `normalize_failed_de_master_stages()` pass;
  - оставить stage-fix внутри `normalize_retry_metadata()`;
  - перевести error/rejected recovery passes на `get_queue_items_summary()`.
- `Профилактика`:
  - не плодить отдельные maintenance passes по тем же state groups, если их логика уже встроена в соседний normalize-pass;
  - broad cleanup должен быть минимальным по числу обходов одной и той же очереди.
- `Статус`: `исправлено`

### KB-016

- `Категория`: `PERFORMANCE`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: hot queue selection and publish scheduling paths используют implicit `SELECT *`, хотя им достаточно summary rows.
- `Где проявляется`: `EPV2_Queue::next_item_for_processing()`, `next_stage_resume_item()`, `next_auto_resume_item()`, `promote_publish_ready_payloads()`, `normalize_terminal_retry_process_items()`, `next_item_for_publish()`, `next_ready_publish_timestamp()`, `normalize_ready_publish_schedule()`, `next_publish_slot_for_queue()`, `has_active_processing_item()`
- `Корневая причина`:
  - выборка через `get_items()` по умолчанию тянула полный row;
  - selection/scheduling/normalization hot paths реально используют только поля из `SUMMARY_FIELDS`.
- `Быстрая диагностика`:
  - проверить вызовы `get_items()` в `class-epv2-queue.php`;
  - сравнить используемые поля с `SUMMARY_FIELDS`;
  - посмотреть memory/CPU churn на repeated process/publish ticks.
- `Исправление`:
  - перевести перечисленные hot paths на `get_queue_items_summary()`;
  - оставить full-row fetch только для мест, где реально нужны тяжёлые поля вне summary.
- `Профилактика`:
  - для queue hot paths использовать summary-level fetch по умолчанию;
  - `get_items()` с implicit `*` не применять в selection/scheduling loops без явной необходимости.
- `Статус`: `исправлено`

### KB-017

- `Категория`: `PERFORMANCE`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: в queue hot helpers один и тот же row многократно декодирует `ai_payload` и `admin_notes` в пределах одного selection/scheduling pass.
- `Где проявляется`: `EPV2_Queue` hot publish/process helpers
- `Корневая причина`:
  - repeated `json_decode()` на одних и тех же `stdClass` rows;
  - особенно заметно в publish scheduling, processability checks и manual-confirmation checks.
- `Быстрая диагностика`:
  - проследить repeated `json_decode((string) $row->ai_payload/admin_notes)` по одному row object;
  - сравнить число decode calls до и после row-level cache helpers.
- `Исправление`:
  - добавить `row_payload()` и `row_notes()` с row-level cache;
  - перевести hot queue helpers на эти методы;
  - при on-the-fly изменении payload сбрасывать `_epv2_payload_cache`.
- `Профилактика`:
  - для repeated row inspection в hot loops использовать cached decode helpers;
  - не дублировать `json_decode()` по одному row object без необходимости.
- `Статус`: `исправлено`

### KB-018

- `Категория`: `PERFORMANCE`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: после sync/update опубликованного bundle плагин сбрасывает весь object cache сайта, хотя менялись только конкретные post bundles.
- `Где проявляется`: `EPV2_Publisher::synchronize_published_bundle_taxonomy()`, `EPV2_Publisher::synchronize_published_bundle_from_payload()`
- `Корневая причина`:
  - использовался глобальный `wp_cache_flush()` после локальных изменений постов;
  - это WordPress anti-pattern для обычного post-sync path.
- `Быстрая диагностика`:
  - проверить `publisher` на вызовы `wp_cache_flush()`;
  - убедиться, что до flush уже известен ограниченный набор `post_id`.
- `Исправление`:
  - удалить глобальный `wp_cache_flush()`;
  - заменить его scoped cleanup через `clean_post_cache()` только для затронутых bundle posts.
- `Профилактика`:
  - не использовать `wp_cache_flush()` в обычных post update/sync paths;
  - чистить только кэш конкретных сущностей, которые реально были изменены.
- `Статус`: `исправлено`

### KB-019

- `Категория`: `PERFORMANCE`
- `Счётчик сбоев`: `1`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: `publisher` повторно пишет одинаковые post meta значения при каждом sync/publish pass, создавая лишний DB write load и cache churn.
- `Где проявляется`: `EPV2_Publisher` meta write paths для `_epv2_*`, Rank Math и editorial meta
- `Корневая причина`:
  - повсеместный прямой `update_post_meta()` без проверки, изменилось ли значение;
  - это особенно дорого в bundle sync/update loops.
- `Быстрая диагностика`:
  - посмотреть repeated `update_post_meta()` на одни и те же ключи после повторного sync одного и того же bundle;
  - сравнить write count до и после idempotent helper.
- `Исправление`:
  - добавить `update_post_meta_if_changed()` и `delete_post_meta_if_exists()`;
  - перевести на них write-heavy paths в `publisher`.
- `Профилактика`:
  - в массовых publish/sync loops не вызывать `update_post_meta()` без проверки изменения значения;
  - для frequently-updated post bundles использовать idempotent meta helpers по умолчанию.
- `Статус`: `исправлено`
- `Корневая причина`:
  - `resolve_shared_publish_media_url()` повторно гонял тяжёлый `resolve_featured_media()` даже когда в payload уже лежал валидный `featured_media_url`;
  - на отдельных кейсах это вело к длинному resolver-path и таймауту до первого `wp_insert_post()`.
- `Быстрая диагностика`:
  - проверить время шагов:
    - `resolve_shared_publish_media_url()`
    - `preflight_shared_publish_media_url()`
  - если post rows по `_epv2_queue_id` не создаются вообще, а `publish_item()` висит, сначала смотреть этот узел.
- `Исправление`:
  - если текущий `featured_media_url` уже проходит `validate_featured_media()`, использовать его напрямую;
  - только потом уходить в source-first resolver.
- `Профилактика`:
  - не запускать дорогой media resolve повторно перед самой публикацией, если publish-grade media уже подтверждено.
- `Статус`: `исправлено`

### KB-020

- `Категория`: `PERFORMANCE`
- `Счётчик сбоев`: `1`
- `Частота`: `редко`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: `wp cron event run epv2_publish` и даже обычные `wp-cli` команды могут зависать надолго, когда due publish item попадает в `normalize_ready_publish_schedule()`.
- `Где проявляется`: `EPV2_Queue::normalize_ready_publish_schedule()`, publish/runtime maintenance path.
- `Корневая причина`:
  - hot scheduling path гонял `EPV2_AI_Processor::normalize_existing_payload($payload)` в expensive-режиме;
  - это тянет полный `finalize_payload_for_queue()` с enrichment/validator churn, хотя для queue scheduling нужны только cheap normalization и уже сохранённые metrics;
  - в live CLI это проявляется как CPU-bound hang ещё до фактической публикации.
- `Быстрая диагностика`:
  - `wp --exec='define("DISABLE_WP_CRON", true);' ... option get siteurl` отвечает быстро, а без `DISABLE_WP_CRON` команда подвисает;
  - `EPV2_Queue::next_item_for_publish()` отвечает быстро;
  - `EPV2_Queue::normalize_ready_publish_schedule(false)` не возвращается быстро;
  - в publish-очереди при этом может быть всего один `ready_publish` item.
- `Исправление`:
  - в `normalize_ready_publish_schedule()` использовать `normalize_existing_payload($payload, false)`;
  - не запускать full payload finalize на scheduling/read-maintenance path.
- `Профилактика`:
  - full payload recompute должен жить только в process/review commit gates, не в queue ordering и cron preflight.
- `Статус`: `исправлено`

### KB-010

- `Категория`: `ORCHESTRATION`
- `Счётчик сбоев`: `1`
- `Частота`: `редко`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: active item уже есть и `next_item_for_processing()` его видит, но `run_process_windowed()` не ставит `epv2_process_async`, из-за чего очередь делает длинную паузу.
- `Где проявляется`: handoff между process-циклами, особенно на `retry_process`.
- `Корневая причина`:
  - `has_processable_items()` проверял только общее наличие processable rows и сначала резался об `has_active_processing_item()`;
  - при этом `focused_automation_item()` мог возвращать активный processable item, но windowed-dispatch до него не доходил.
- `Быстрая диагностика`:
  - сравнить:
    - `EPV2_Queue::next_item_for_processing()`
    - `EPV2_Queue::has_processable_items()`
    - `wp_next_scheduled('epv2_process_async')`
    - `epv2_active_automation_item`
  - если `next_item_for_processing()` возвращает item, а `has_processable_items()` = `false`, это этот кейс.
- `Исправление`:
  - в `has_processable_items()` сначала учитывать `focused_automation_item()`;
  - если активный recoverable item уже фокусный и processable, возвращать `true`.
- `Профилактика`:
  - read-only presence check не должен расходиться с фактическим селектором следующего item.
- `Статус`: `исправлено`

### KB-011

- `Категория`: `ORCHESTRATION`
- `Счётчик сбоев`: `2`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: материал выглядит как активный `В работе`, хотя фактически process-run уже завершился или worker отсутствует.
- `Где проявляется`: queue UI, active work slot, process handoff.
- `Корневая причина`:
  - UI считал активными и `retry_process`, хотя это уже не живое выполнение;
  - из-за этого пользователь видел фиктивный прогресс вроде `81%`, когда реального активного воркера уже не было.
  - дополнительно `epv2_active_automation_item` удерживался на `retry_process/new/ready_review`, из-за чего item мог выпадать из одних блоков очереди и не попадать в другие.
- `Быстрая диагностика`:
  - сравнить:
    - `state`
    - `epv2_active_automation_item`
    - `wp_next_scheduled('epv2_process_async')`
    - `epv2_lock_process`
  - если item в `retry_process`, а async/lock уже нет, это не активная работа.
- `Исправление`:
  - считать активной работой только `processing_de`;
  - `retry_process` без живого worker больше не показывать как `В работе`.
  - `active` слот держать только на реальном `processing_de`, а при выходе в `retry_process/new/ready_review` сразу очищать.
- `Профилактика`:
  - UI-статус должен опираться на фактическое выполнение, а не только на stored active id.
- `Статус`: `исправлено`

### KB-012

- `Категория`: `WORKFLOW`
- `Счётчик сбоев`: `2`
- `Частота`: `повторяется`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: weak one-source материал бесконечно крутится в `rebuild_bundle` без прироста `supporting_count`, media или quality-gates.
- `Где проявляется`: process loop, `generate_review_payload`, source enrichment.
- `Корневая причина`:
  - weak package мог повторно уходить в тот же `rebuild_bundle` с идентичной сигнатурой payload;
  - при этом очередь продолжала брать тот же item снова и снова, блокируя продвижение следующих материалов.
- `Быстрая диагностика`:
  - проверить последние `process` runs для одного и того же item;
  - если последние `3+` finished run подряд дают:
    - `result = queued_rebuild_bundle_stage` или `requeued_rebuild_bundle_after_rebuild`
    - и item не наращивает `supporting/media/quality`,
    - это именно этот loop.
- `Исправление`:
  - добавлен anti-stagnation guard по сигнатуре weak `rebuild_bundle`;
  - добавлен второй run-level guard по истории последних finished `process` runs самого item;
  - при нормализации неактивных recoverable item `retry_after` больше не стирается;
  - `new` item с активным `retry_after` временно исключается из processable-пула до конца cooldown-окна;
  - после нескольких идентичных циклов item переводится в автоматический cooldown через retry-window, а не крутится бесконечно.
- `Профилактика`:
  - любой rebuild loop обязан подтверждать реальный прирост пакета;
  - при отсутствии изменений loop должен уходить в cooldown или в другой корректирующий path, а не занимать process-slot бесконечно.
- `Статус`: `исправляется системно`

### KB-013

- `Категория`: `WORKFLOW`
- `Счётчик сбоев`: `1`
- `Частота`: `редко`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: слабый материал после ошибки `AI rewrite did not reach minimum DE master quality` остаётся в `new`, но с ложной стадией `translate_finish`.
- `Где проявляется`: process error path после `generate_review_payload()`.
- `Корневая причина`:
  - при падении DE master payload уже содержал `translations_deferred/pipeline_stage=translate_finish`;
  - generic retry-path сохранял этот stage, хотя master DE ещё не был жизнеспособен.
- `Быстрая диагностика`:
  - проверить item после `finished_with_errors`;
  - если `error = AI rewrite did not reach minimum DE master quality`, а `pipeline_stage = translate_finish`, это этот кейс.
- `Исправление`:
  - при такой ошибке принудительно возвращать payload в `rebuild_bundle`;
  - только после успешного DE master разрешать `translate_finish`.
- `Профилактика`:
  - translation-stage не должен переживать failed DE-master path.
- `Статус`: `исправлено`

### KB-014

- `Категория`: `WORKFLOW`
- `Счётчик сбоев`: `1`
- `Частота`: `редко`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: one-source материал с уже готовыми переводами и валидным media бесконечно крутится в `publish_finish`, хотя `quality` остаётся ниже publish-grade.
- `Где проявляется`: stage resolver, `payload_requires_fresh_rebuild_fast()`, `payload_next_required_stage()`.
- `Корневая причина`:
  - для `source_count <= 1` код считал пакет “достаточно хорошим для finish-only”, если `DE master` уже жив и есть media;
  - из-за этого item не возвращался в дообогащение и по кругу получал `queued_publish_finish_stage`.
- `Быстрая диагностика`:
  - в runs подряд идут `queued_publish_finish_stage`;
  - у item:
    - `source_count = 1`
    - `featured != ''`
    - `translations_ready = true`
    - `quality < 100`
- `Исправление`:
  - weak one-source пакет не может считаться finish-only, пока `quality/release/google` не достигли publish-grade;
  - в таком случае следующий stage обязан быть `rebuild_bundle`.
- `Профилактика`:
  - `publish_finish` не должен использоваться как замена обязательному source enrichment.
- `Статус`: `исправлено`

### KB-015

- `Категория`: `ORCHESTRATION`
- `Счётчик сбоев`: `1`
- `Частота`: `редко`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: после завершения process/publish в очереди остаются `new` и/или `ready_publish`, но `process_async/publish_async` не стоят.
- `Где проявляется`: handoff между циклами автоматики.
- `Корневая причина`:
  - автодиспетчеризация зависела от windowed-cron или от частичных handoff-путей;
  - после завершения одного контура второй мог просто не быть поставлен.
- `Быстрая диагностика`:
  - проверить одновременно:
    - `EPV2_Queue::has_processable_items()`
    - `EPV2_Queue::next_item_for_publish()`
    - `wp_next_scheduled('epv2_process_async')`
    - `wp_next_scheduled('epv2_publish_async')`
  - если работа есть, а async-задач нет, это этот кейс.
- `Исправление`:
  - после `process` release всегда перепроверять и ставить оба контура;
  - после `publish` release делать то же самое.
- `Профилактика`:
  - process и publish не должны зависеть от отдельного windowed-dispatch для возобновления.
- `Статус`: `исправлено`

### KB-016

- `Категория`: `WORKFLOW`
- `Счётчик сбоев`: `1`
- `Частота`: `редко`
- `Последний зафиксированный случай`: `2026-03-28`
- `Симптом`: weak one-source материал получает `translate_uk/translate_en/translate_finish`, хотя ещё не добран supporting/context-grade пакет.
- `Где проявляется`: stage gating перед переводами.
- `Корневая причина`:
  - `publish_finish_ready` и stage resolver разрешали переводческий путь слишком рано;
  - deeper-supporting enrichment учитывался только вместе с пустым media, что было слишком мягко.
- `Быстрая диагностика`:
  - у item:
    - `source_count = 1`
    - `supporting_count = 0`
    - warnings по слабому тексту/досье
    - при этом `stage` уже `translate_*` или `translate_finish`
- `Исправление`:
  - пока payload требует deeper supporting enrichment, `de_master_ready_for_translation = false`;
  - следующий stage в таком случае всегда `rebuild_bundle`.
- `Профилактика`:
  - переводы не могут подменять обязательное смысловое дообогащение master-материала.
- `Статус`: `исправлено`

## Текущие активные кейсы

- `330`
  - `state = processing_de`
  - `active = true`
  - `stage = -`
  - `featured = ''`
  - `source_count = 0`
  - `quality = 0`
  - `seo = 0`
  - `release = 0`
  - `google = 0`
  - текущий класс проблемы:
    - `наблюдение`

- `328`
  - `state = published`
  - `active = false`
  - `featured = source-first`
  - `source_count = 2`
  - `quality = 100`
  - `release = 100`
  - `google = 100`
  - текущий класс проблемы:
    - `KB-008`
    - `KB-009`

## Правило обновления документа

Каждый новый сбой добавлять сюда только если:
- он повторяемый;
- у него есть явный симптом;
- понятна хотя бы предварительная корневая причина;
- есть проверяемый способ диагностики.

## Правило обновления счётчиков

- если это новый повтор уже известного кейса, увеличивать `Счётчик сбоев` на `1`;
- обновлять `Последний зафиксированный случай`;
- пересматривать `Частота` по шкале:
  - `редко` = `1`
  - `повторяется` = `2-3`
  - `часто` = `4-7`
  - `критически часто` = `8+`

## KB-025. `translate_finish` и `publish_finish` уходят в лишний broad lift/rebuild вместо узкого финального stage path

- `Дата`: `2026-03-29`
- `Симптом`:
  - items с уже собранным DE master и partially repaired translations снова уходят в `rebuild_bundle`;
  - особенно заметно на `publish_finish` и сразу после `translate_finish`;
  - stage router показывает oscillation вместо детерминированного `translate_* -> publish_finish -> ready_publish`.
- `Корневая причина`:
  - main process path для финального добора использовал broad `attempt_publish_grade_lift()`;
  - этот путь повторно заходил в rebuild-oriented logic, хотя для stage `publish_finish` уже существовал более узкий `run_publish_finish_stage()`.
- `Быстрая диагностика`:
  - в `process` runs видны последовательности вида:
    - `translate_en -> publish_finish`
    - затем `publish_finish -> rebuild_bundle`
    - затем новый rebuild/requeue cycle
- `Исправление`:
  - после `translate_finish` и в review-ready resume path финальный добор переведён на `run_publish_finish_stage()`;
  - `publish_finish` больше не должен заново открывать broad rebuild path без необходимости.
- `Профилактика`:
  - stage-specific paths не должны использовать общий enrichment lifter, если уже существует узкий final-stage helper.
- `Статус`: `исправлено`

## KB-026. Single-source long-form items слишком агрессивно отправлялись в `rebuild_bundle`

- `Дата`: `2026-03-29`
- `Симптом`:
  - long-form official/community/culture preview items с `source_count = 1` повторно уходят в `rebuild_bundle`, хотя DE master уже содержательно собран;
  - live examples:
    - `351` до фикса: `next_required_stage = rebuild_bundle`
    - `354` до фикса несколько раз oscillated через rebuild loops
- `Корневая причина`:
  - `payload_needs_deeper_supporting_enrichment()` и stale translation-stage interpretation были слишком жёсткими для long-form single-source cases;
  - media/translation/dossier gaps трактовались как mandatory deeper supporting rebuild даже там, где supporting search не даёт нового сигнала.
- `Быстрая диагностика`:
  - item имеет:
    - длинный DE content;
    - `source_count = 1`;
    - low-risk category (`kultur`/`community`/local official case);
    - warnings в основном по weak dossier/media/translation, без явного category mismatch или weak DE text.
- `Исправление`:
  - `payload_stage_requires_translation_finish()` теперь не держится только за stale `pipeline_stage`, а смотрит на реальную `translations_ready`;
  - `payload_needs_deeper_supporting_enrichment()` получил single-source relaxation для:
    - long-form official primary;
    - long-form low-risk `community`/`kultur`/local preview cases;
  - для таких кейсов router больше не обязан возвращать `rebuild_bundle` только из-за single-source/media/translation tails.
- `Live-подтверждение`:
  - после фикса:
    - `351`: `next_required_stage = publish_finish` вместо `rebuild_bundle`
    - `354`: `next_required_stage = translate_uk`
    - `358` на live прошёл `translate_uk -> translate_en -> translated_en_successfully`
- `Профилактика`:
  - single-source long-form preview items должны добираться через translation/publish-finish/manual-media path, а не бесконечным supporting rebuild.
- `Статус`: `исправлено`

## KB-027. Stale `stalled rebuild` cooldown не снимался после изменения stage-router

- `Дата`: `2026-03-29`
- `Симптом`:
  - item уже больше не требует `rebuild_bundle` по текущему router, но остаётся в `new/retry_process` с:
    - `error_message = publish threshold stalled rebuild bundle`
    - старым `retry_after`
    - stale `pipeline_stage = rebuild_bundle`
  - из-за этого queue продолжает обходить item, хотя алгоритм уже разрешает `publish_finish` или `translate_uk`.
- `Корневая причина`:
  - rebuild cooldown записывался корректно, но recovery path не пересматривал его после изменения routing logic;
  - cleanup не снимал stale backoff для `new/retry_process`, если `payload_required_stage()` уже изменился.
- `Быстрая диагностика`:
  - `payload_required_stage(payload)` возвращает не `rebuild_bundle`;
  - в row всё ещё висят `retry_after` и `publish threshold stalled rebuild bundle`.
- `Исправление`:
  - добавлен публичный wrapper `EPV2_AI_Processor::payload_required_stage()`;
  - в `EPV2_Resilience_Manager::cleanup()` добавлена нормализация stale rebuild cooldown:
    - reset `process/review_rebuild/review_finish` counters;
    - unset `retry_after`;
    - clear stale error;
    - переписать `pipeline_stage` на актуальный required stage.
- `Live-подтверждение`:
  - после cleanup:
    - `351` -> `processing_de / publish_finish`
    - `353` -> `new / publish_finish`
    - `354` -> `new / translate_uk`
  - реальный run `#4437` уже прошёл как `queued_publish_finish_stage` для `353`.
- `Профилактика`:
  - любой backoff, завязанный на stage-loop, должен сниматься автоматически после изменения stage-router или acceptance criteria.
- `Статус`: `исправлено`

## KB-028. `trim_new_queue()` физически удалял новые items без terminal state и audit trail

- `Дата`: `2026-03-29`
- `Симптом`:
  - материал может “пропасть” из поля зрения без `runs`, без terminal state и без понятной причины в очереди;
  - live example:
    - item `362` отсутствует в `ep_epv2_queue`, а в `runs/logs` по нему нет следов process/publish path.
- `Корневая причина`:
  - `EPV2_Queue::trim_new_queue()` делал прямой `DELETE` для слабых/лишних `new` items по category/source limits;
  - удаление происходило без перевода в видимый terminal state и без audit metadata.
- `Исправление`:
  - trim больше не удаляет silently;
  - trimmed items переводятся в `rejected` с причиной и `_system.trimmed_from_new_queue` в `admin_notes`.
- `Профилактика`:
  - queue items не должны физически исчезать из automation visibility без terminal state и объяснения.
- `Статус`: `исправлено`

## KB-029. `publish_finish` мог бесконечно переочередиваться при нерешаемом source-first media gap

- `Дата`: `2026-03-29`
- `Симптом`:
  - item много раз проходит как `queued_publish_finish_stage`, но не двигается ни в `ready_publish`, ни в явный terminal state;
  - live example:
    - item `353` генерировал серию `queued_publish_finish_stage` runs подряд.
- `Корневая причина`:
  - `payload_requires_media_manual_confirmation()` была слишком узкой и не срабатывала на single-source cases, где source-first media уже реально исчерпана;
  - в итоге `publish_finish` loop продолжался без прогресса.
- `Исправление`:
  - добавлена проверка `payload_source_first_media_exhausted()`;
  - manual media confirmation теперь допускается для реально исчерпанных single-source cases;
  - queue/UI больше не скрывают такие media-confirmation items из-за старого `source_count >= 2` условия.
- `Live-подтверждение`:
  - item `353`:
    - до фикса: repeated `queued_publish_finish_stage`
    - после фикса: run `#4464 = queued_media_manual_confirmation`
    - state: `ready_review`
    - `_system.manual_confirmation_required = media`
- `Профилактика`:
  - финальный `publish_finish` path не должен повторяться бесконечно, если source-first media объективно неразрешима автоматически.
- `Статус`: `исправлено`

## KB-030. `translate_uk`/`translate_en` могли бесконечно повторяться при stable invalid translation result

- `Дата`: `2026-03-29`
- `Симптом`:
  - item снова и снова проходит single-language translation stage, но целевой язык не появляется;
  - live example:
    - item `354` многократно запускал `translate_uk`;
    - в `ep_epv2_log` повторялись:
      - `Single language repair start`
      - `Bounded language translation returned empty package, falling back to full translation pipeline`
      - `Single language repair translated {"translated":0}`
- `Корневая причина`:
  - translation branch возвращал payload без прогресса, но не считал это explicit failure;
  - stage снова ставился в `translate_uk`, и item уходил в ещё один identical run.
- `Исправление`:
  - добавлен no-progress counter по языку (`_system.retries.translate_uk` / `translate_en`);
  - после ограниченного числа безуспешных попыток item переводится в `ready_review` с:
    - `_system.manual_confirmation_required = translation`
    - явной причиной в `error_message`.
- `Live-подтверждение`:
  - item `354`:
    - после первого post-sync run получил `translate_uk = 1`;
    - после следующего run вышел в:
      - `ready_review`
      - `manual_confirmation_required = translation`
      - `manual_confirmation_reason = uk_no_progress`
- `Профилактика`:
  - any single-language repair path must have finite no-progress accounting and manual escape hatch.
- `Статус`: `исправлено`

## KB-031. `publish_finish` мог крутиться без конца, если source-first image есть, но publishable featured media так и не выбрана

- `Дата`: `2026-03-29`
- `Симптом`:
  - item остаётся на `publish_finish`, `live_status_code = publish_finish`, но не переходит ни в `ready_publish`, ни в manual review;
  - при этом:
    - `payload_primary_media_url()` в raw snapshot пуст;
    - `media_repair_failed = true`;
    - quality/release/google warnings уже могут быть пустыми, поэтому старые warning-based predicates не срабатывают.
- `Корневая причина`:
  - manual media fallback был завязан в основном на warning text и “exhausted source-first” cases;
  - case, где source-first image существует, но publishable featured media не удаётся safely выбрать/подтвердить, выпадал из обоих условий и застревал в no-op `publish_finish`.
- `Исправление`:
  - `payload_requires_media_manual_confirmation()` расширен:
    - учитывает отсутствие выбранного `featured_media_url`, а не только общую image availability;
    - отдельно ловит кейс, где source-first image есть, но `payload_featured_media_is_publishable()` остаётся `false`;
    - такие items переводятся в explicit manual media review вместо повторного `publish_finish`.
- `Live-подтверждение`:
  - item `351`:
    - до фикса повторял `publish_finish` без terminal progress;
    - после forced/live run перешёл в:
      - `ready_review`
      - `manual_confirmation_required = media`
      - понятный `error_message` вместо loop.
- `Профилактика`:
  - final media gate must treat “source image exists but remains non-publishable” as explicit manual-review outcome, not as implicit retry loop.
- `Статус`: `исправлено`

## KB-032. `Resilience` и state-model расходились в трактовке manual-confirmation и stage-bearing items

- `Дата`: `2026-03-29`
- `Симптом`:
  - item уже выведен stage-layer в explicit manual review, но cleanup/revive path всё ещё считает его auto-resumable;
  - item после снятия stale rebuild cooldown может иметь ненулевой `pipeline_stage`, но внешний state остаётся `new`, из-за чего orchestration и queue UI читают его по-разному.
- `Где проявляется`:
  - `EPV2_Resilience_Manager::revive_auto_review_candidates()`
  - `EPV2_Resilience_Manager::normalize_stalled_rebuild_cooldowns()`
- `Корневая причина`:
  - recovery-layer ориентировался на message regex вроде `manual review`, а не на явный `_system.manual_confirmation_required`;
  - stale stage recovery переписывал payload `pipeline_stage`, но возвращал row в `new`, хотя по факту item уже требовал `retry_process`.
- `Быстрая диагностика`:
  - item в `ready_review` имеет `_system.manual_confirmation_required`, но после `cleanup()` внезапно возвращается в `retry_process`;
  - item имеет `payload_required_stage() != ''`, но row state после recovery = `new`.
- `Исправление`:
  - `revive_auto_review_candidates()` теперь пропускает любой item с явным `_system.manual_confirmation_required`;
  - `normalize_stalled_rebuild_cooldowns()` переводит такие stage-bearing items в `retry_process`, а не в `new`.
- `Live-подтверждение`:
  - после cleanup:
    - `351` остаётся `ready_review / manual=media`
    - `354` остаётся `ready_review / manual=translation`
    - `353` остаётся `ready_review / manual=media`
  - manual-confirmation items больше не “оживают” обратно в auto loop только из-за resilience pass.
- `Профилактика`:
  - manual-review decision должен жить в явном state contract, а не в разрозненных regex по `error_message`;
  - если payload уже несёт required stage, row state обязан отражать это напрямую.
- `Статус`: `исправлено`

## KB-033. `Auto` intake throttling был слишком жёстким и оставлял pipeline без свежих кандидатов

- `Дата`: `2026-03-29`
- `Симптом`:
  - automation крутит 1-2 старых weak items, а новые новости почти не попадают в queue;
  - user видит “ничего не идёт в работу”, хотя cron `collect` формально жив.
- `Где проявляется`:
  - `EPV2_Collector::ingest_candidate()`
  - `EPV2_Collector::run_scheduled()`
- `Корневая причина`:
  - в live `auto`-режиме `max_collect_per_category = 1` и `queue_new_max_per_category = 1`;
  - это превращало один проблемный item в category-level choke point: collector больше не впускал свежий материал той же рубрики.
- `Исправление`:
  - в `auto` collector теперь использует effective floor:
    - `max_collect_per_category >= 2`
    - `queue_new_max_per_category >= 2`
  - тот же effective cap используется и в `trim_new_queue()`.
- `Live-подтверждение`:
  - после патча `collect` снова начал добавлять свежие items:
    - `364`
    - `365`
    - `366`
    - `367`
    - `368`
  - queue перестала состоять только из старых weak rebuild cases.
- `Профилактика`:
  - в full-auto queue category caps не должны быть настолько низкими, чтобы один recoverable item блокировал intake всей рубрики.
- `Статус`: `исправлено`

## KB-034. Fatigued `rebuild_bundle` items вызывали scheduler starvation и перехватывали `process`

- `Дата`: `2026-03-29`
- `Симптом`:
  - новые `new` items уже есть в queue, но `process` снова выбирает старый weak item с `pipeline_stage = rebuild_bundle`;
  - automation выглядит “застрявшей на одном материале”, хотя intake жив.
- `Где проявляется`:
  - `EPV2_Queue::next_item_for_processing()`
  - `EPV2_Queue::next_stage_resume_item()`
  - `EPV2_Queue::processing_stage_priority()`
- `Корневая причина`:
  - любой stage-bearing item получал приоритет выше свежего `new`;
  - repeated weak-DE rebuild cases после нескольких `minimum DE master quality` failures всё ещё ранжировались как нормальный resume candidate.
- `Исправление`:
  - введён класс fatigued rebuild candidate:
    - `pipeline_stage = rebuild_bundle`
    - repeated `process` / `review_rebuild` retries
    - threshold-style error (`minimum DE master quality`, `publish threshold`, и т.п.)
  - если в queue уже есть свежие due `new` candidates:
    - такие fatigued rebuild items больше не preempt’ят `next_stage_resume_item()`;
    - в general selector они получают zero stage priority и сильный score penalty.
- `Live-подтверждение`:
  - до фикса при наличии свежих `364-368` selector всё ещё продолжал тянуть старые `344/357`;
  - после патча starvation path больше не считается “привилегированным” только из-за старого `rebuild_bundle`.
- `Профилактика`:
  - repeated recoverable rebuild не должен монополизировать single-item process lane, если свежие новости уже ждут первичной обработки.
- `Статус`: `исправлено`

## KB-035. `active_item` cleanup схлопывал stage-bearing rows в `new` и делал automation невидимой

- `Дата`: `2026-03-29`
- `Симптом`:
  - item уже несёт `pipeline_stage`, `retry_after` или `live_status_code`, но row state остаётся `new`;
  - queue UI и orchestration показывают противоречивую картину:
    - материал как будто “не в работе” по state;
    - но одновременно выглядит активным по live status;
  - user видит, что новости “исчезают” или “не идут в работу”, хотя stage-фактура в row ещё есть.
- `Где проявляется`:
  - `EPV2_Queue::mark_state()`
  - `EPV2_Queue::clear_active_automation_item()`
  - `EPV2_Queue::normalize_non_active_recoverable_items()`
  - `EPV2_Queue::focused_automation_item()`
  - `EPV2_AI_Processor::queue_next_processing_stage()`
- `Корневая причина`:
  - при переходе `processing_de -> retry_process/ready_review` `mark_state()` очищал `active_item`;
  - `clear_active_automation_item()` сразу запускал broad normalization, который превращал non-active `retry_process/ready_review` обратно в `new`;
  - после этого `queue_next_processing_stage()` ещё и дописывал `live_status_code`, так что получался broken hybrid:
    - `state = new`
    - но `pipeline_stage/live_status` уже stage-bearing.
- `Исправление`:
  - active pointer теперь нормализуется отдельно и считается валидным только если:
    - row действительно `processing_de`
    - и жив process lock;
  - broad normalization больше не схлопывает `retry_process/ready_review` в `new`;
  - добавлен repair-pass для legacy drift:
    - если row уже `new`, но несёт `pipeline_stage`, `retry_after` или `live_status_code`, он автоматически возвращается в `retry_process`.
- `Live-подтверждение`:
  - до фикса:
    - `351` = `new + translating_en`
    - `370` = `new + rebuild_bundle + retry_after`
    - `371` = `new + generating_payload`
  - после repair-pass:
    - `351` -> `retry_process / translate_en`
    - `370` -> `retry_process / rebuild_bundle`
    - `372` -> `processing_de`, `active_item = 372`
  - selector снова видит stage-bearing rows в каноническом состоянии, а не в broken `new`.
- `Профилактика`:
  - `retry_process` и `ready_review` нельзя автоматически коллапсировать в `new`, если они несут workflow semantics;
  - `active_item` имеет право жить только вместе с `processing_de + live process lock`;
  - любой `new` row с stage-bearing metadata должен считаться drift и автоматически чиниться.
- `Статус`: `исправлено`

## KB-036. `collect` мог продолжать работать уже без lock и оставлять вечные `started/running`

- `Дата`: `2026-03-29`
- `Симптом`:
  - `epv2_collect_progress` остаётся `running`, а последний `collect` run остаётся `started`;
  - при этом `epv2_lock_collect` уже отсутствует;
  - intake cadence становится недостоверной:
    - scheduler думает, что collect ещё жив;
    - lock-contract думает, что collect уже умер.
- `Где проявляется`:
  - `EPV2_Collector::run_scheduled()`
  - `EPV2_Runs::cleanup_abandoned_started()`
  - `EPV2_Lock_Manager::is_stale()`
  - `EPV2_Jobs::recover_orphan_collect_lock()`
- `Корневая причина`:
  - collect heartbeat обновлялся только между source-итерациями;
  - единый stale window `300s` мог удалить `collect` lock посреди длинного source fetch;
  - progress option хранил `updated_at` как локальную строку времени без надёжного UTC marker, из-за чего stale-диагностика могла считать orphan activity “свежей” слишком долго;
  - при неожиданном выходе collect-path не всегда финализировал `run/progress`.
- `Исправление`:
  - `Lock_Manager` теперь поддерживает job-specific `meta.stale_after`;
  - `collect` acquire получает расширенное stale window, чтобы длинный source fetch не убивал lock раньше времени;
  - `recover_orphan_collect_lock()` теперь уважает тот же stale window из lock meta;
  - `collect_progress` дополнен `updated_at_ts`;
  - `collector` теперь в `finally` финализирует `run/progress` как `finished_with_errors`, если execution вышел неожиданно до штатного `finish`.
- `Live-подтверждение`:
  - на live был зафиксирован broken state:
    - `collect_progress.status = running`
    - `latest_collect.status = started`
    - `epv2_lock_collect = false`
  - после патча stale-telemetry и lock semantics больше не расходятся по разным clocks/contracts.
- `Профилактика`:
  - long-running ingest jobs не должны зависеть от общего stale threshold, рассчитанного под `process/publish`;
  - activity freshness для recovery должна опираться на unix timestamp, а не на локальную строку времени без timezone;
  - любой automation job должен иметь guaranteed finalizer для `run/progress` даже при unexpected exit.
- `Статус`: `исправлено`

## KB-037. `publish_ready` predicate допускал payload одновременно с `required_stage`

- `Дата`: `2026-03-29`
- `Симптом`:
  - row мог оказаться в `ready_publish` или даже `published`, пока payload всё ещё нёс `pipeline_stage`/`required_stage`;
  - telemetry показывала противоречие:
    - `payload_is_publish_ready() = true`
    - но `payload_required_stage() = translate_en` или `publish_finish`.
- `Где проявляется`:
  - `EPV2_AI_Processor::payload_ready_for_publish()`
  - `EPV2_Queue::mark_state()`
  - `EPV2_Queue::normalize_ready_publish_schedule()`
- `Корневая причина`:
  - publish-grade predicate проверял quality/media/languages, но не запрещал незавершённый processing stage;
  - state transitions в `ready_publish/published` не вычищали stale `pipeline_stage` из payload.
- `Исправление`:
  - `payload_ready_for_publish()` теперь сразу возвращает `false`, если у payload есть:
    - `pipeline_stage != ''`
    - или `payload_required_stage() != ''`;
  - `mark_state()` для `ready_publish/published` теперь принудительно очищает `pipeline_stage` в `ai_payload`.
- `Live-подтверждение`:
  - на live item `351` был в broken состоянии:
    - `state = ready_publish`
    - `payload_required_stage = translate_en`
    - `payload_is_publish_ready = true`
  - после фикса такой contradiction больше не допускается: false publish-ready payload должен быть вытолкнут обратно в `retry_process`, а terminal states больше не хранят stale stage.
- `Профилактика`:
  - `publish_ready` должен быть terminal processing predicate, а не только quality/media predicate;
  - payload в `ready_publish/published` не имеет права хранить незавершённый stage contract.
- `Статус`: `исправлено`

## KB-038. Trusted source-first media ломалась на `www/CDN` host mismatch

- `Дата`: `2026-03-29`
- `Симптом`:
  - editorial source image с того же publisher family существует и валидна по размеру/типу;
  - но automation всё равно уходит в `manual_confirmation_required = media`;
  - чаще всего это проявляется на CDN/subdomain image hosts вроде:
    - `www.publisher.tld`
    - `derivates.publisher.tld`
- `Где проявляется`:
  - `EPV2_Media::same_source_host()`
  - `EPV2_Media::source_dossier_image()`
  - `EPV2_Media::is_trusted_source_dossier_media()`
  - `EPV2_AI_Processor::payload_requires_media_manual_confirmation()`
- `Корневая причина`:
  - trusted source-first path сравнивал host strings слишком буквально;
  - пары вроде `www.kicker.de` и `derivates.kicker.de` не считались одним source family, хотя это один publisher;
  - из-за этого valid primary image не проходила trusted relevance path и item лишне уходил в manual media review.
- `Исправление`:
  - `same_source_host()` теперь нормализует `www.` перед family matching;
  - trusted source-dossier images от того же publisher family снова считаются valid source-first candidates.
- `Live-подтверждение`:
  - для item `372` primary image `derivates.kicker.de/...jpg`:
    - `validate_featured_media()` already returned `ok = true`
    - до фикса `is_relevant_media() = false`
    - после фикса `is_relevant_media() = true`
  - `repair_media_for_automation()` после этого уже может поднять тот же trusted source image и снять obsolete `manual=media` stop.
- `Профилактика`:
  - publisher-family matching для editorial media должен быть tolerant к `www`/CDN subdomains;
  - source-first trusted path должен отрабатывать раньше, чем fallback stock/context paths.
- `Статус`: `исправлено`

## KB-039. `process` lane starvation: stage-bearing `retry_process` душил `new`

- `Дата`: `2026-03-29`
- `Симптом`:
  - в queue есть свежие `new` items, но `в работе` пусто или постоянно крутится один и тот же resume-item;
  - `EPV2_Queue::next_item_for_processing()` снова и снова выбирает `retry_process/ready_review` со stage вместо свежего `new`;
  - live history показывает repeated selection пары resume-items (`372`, затем `365/370`), пока `369` остаётся в `new`.
- `Где проявляется`:
  - `EPV2_Queue::next_item_for_processing()`
  - `EPV2_Queue::next_stage_resume_item()`
  - `EPV2_AI_Processor::process_scheduled()`
  - `ep_epv2_runs`
- `Корневая причина`:
  - selector prioritised stage-bearing resume-path почти без fairness между bucket `resume` и bucket `new`;
  - blacklist одного hot item недостаточен: lane тут же захватывал следующий stage-bearing candidate;
  - у process history не было явной bucket telemetry, поэтому starvation не фиксировался как отдельный class.
- `Исправление`:
  - введены отдельные processing buckets:
    - `new`
    - `resume_stage`
    - `resume_auto`
  - добавлен helper `EPV2_Queue::processing_bucket()`;
  - `process` run теперь пишет `selected_bucket` в `epv2_runs.payload`;
  - selector получил явный `new` bucket path через `next_fresh_new_item()`;
  - при наличии processable `new` selector теперь предпочитает `new`, если предыдущий finished `process` run не был из bucket `new`;
  - added fairness guard against recent monopolizing resume-items:
    - recent processed-item streak
    - short resume cooldown after recent stage processing.
- `Live-подтверждение`:
  - до фикса live selector возвращал:
    - `next_item = 372`
    - после первого fairness-filter сразу `next_item = 365`
    - while `369` оставался `new`
  - после bucket fix live selector стал возвращать:
    - `next_item = 369`
    - `next_state = new`
    - `next_bucket = new`
- `Профилактика`:
  - single-lane automation не должна позволять одному broken resume-bucket душить intake bucket;
  - fairness должна быть explicit и telemetry-backed, а не implicit через stage priority;
  - history of `selected_bucket` обязательна для последующей runtime диагностики starvation.
- `Статус`: `исправлено`

## KB-040. Duplicate heavy maintenance блокировал `process` до выбора item

- `Дата`: `2026-03-29`
- `Симптом`:
  - automation выглядит как “ничего не идёт в работу”;
  - `next_item_for_processing()` уже возвращает fresh `new`, но реальный `process` run либо висит десятки минут, либо вообще не доходит до `after_next_item`;
  - live logs показывают гигантский разрыв между `before_cleanup` и `after_cleanup`.
- `Где проявляется`:
  - `EPV2_Jobs::maintain_runtime_state()`
  - `EPV2_AI_Processor::process_scheduled()`
- `Корневая причина`:
  - `process` hot path тащил global maintenance дважды:
    - в scheduler через `maintain_runtime_state()`
    - и затем ещё раз через `EPV2_Resilience_Manager::cleanup()` внутри `process_scheduled()`
  - даже прямой `run_process_async()` висел до старта item-processing, потому что heavy cleanup стоял перед выбором item.
- `Исправление`:
  - `process_scheduled()` больше не запускает inline `cleanup()` для forced/scheduled execution;
  - `EPV2_Jobs::maintain_runtime_state()` разделён на:
    - light path для `process`
    - heavy path для `publish`
  - process lane теперь держит:
    - orphan recovery
    - queue normalization
    без global heavy cleanup перед каждым run.
- `Live-подтверждение`:
  - до фикса:
    - `process #4595` зависал в cleanup window;
    - telemetry: `before_cleanup` -> `after_cleanup` заняло около `10m+`
  - после фикса:
    - `process #4596` стартовал сразу;
    - live log показал:
      - `after_next_item` для `queue_id=369`
      - затем baseline dossier/payload steps
    - item `369` реально перешёл в `processing_de`
- `Профилактика`:
  - global cleanup не должен жить на intake hot path;
  - maintenance должен быть разделён на light/orphan recovery и heavy/global normalization;
  - process lane должен тратить время на item progression, а не на fleet-wide repair.
- `Статус`: `исправлено`

## KB-041. System cron storm и отсутствие memory guard валили весь сайт

- `Дата`: `2026-03-30`
- `Симптом`:
  - сайт периодически отдавал `502 Bad Gateway`;
  - `php8.3-fpm` падал с `Result: oom-kill`;
  - SSH/Termius становился вязким: медленный connect, лаги ввода, долгий echo текста;
  - на сервере накапливались одновременно несколько `wp cron event run --due-now`.
- `Где проявляется`:
  - `/etc/cron.d/europulse-wp-cron`
  - `/var/www/europulse/public/wp-config.php`
  - `/etc/php/8.3/fpm/pool.d/*.conf`
  - `/etc/systemd/system/php8.3-fpm.service.d/override.conf`
  - `/etc/sysctl.d/*.conf`
- `Корневая причина`:
  - system cron каждую минуту запускал `wp cron event run --due-now` без non-overlap guard;
  - WordPress parallel self-spawn не был отключён;
  - на узле `2 CPU / 3.7 GiB RAM` `php-fpm` был слишком широким для тяжёлых cron/job bursts;
  - не было memory headroom policy и auto-restart policy после OOM.
- `Исправление`:
  - отключён старый storm-source cron;
  - включён `DISABLE_WP_CRON`;
  - установлен safe runner с:
    - `flock`
    - `timeout`
    - `nice`
    - `ionice`
    - memory guard по `MemAvailable` и `SwapFree`
  - `php-fpm` сужен до более безопасного envelope:
    - `pm.max_children = 6`
    - `pm.max_requests = 200`
    - `request_slowlog_timeout = 15s`
    - `request_terminate_timeout = 90s`
  - добавлен `systemd` auto-restart на failure;
  - kernel reserve усилен:
    - `vm.min_free_kbytes = 262144`
    - `vm.swappiness = 5`
- `Live-подтверждение`:
  - до фикса:
    - `curl -I` => `502`
    - `systemctl status php8.3-fpm` => `failed (Result: oom-kill)`
    - `ps` показывал несколько параллельных `wp cron event run --due-now`
  - после фикса:
    - сайт стабильно отвечает `200`
    - следующий cron tick создаёт только один runner;
    - память держится с запасом:
      - `MemAvailable ~ 2.8 GiB`
      - swap mostly free
    - `php8.3-fpm` active и ограничен до `pm.max_children = 6`
- `Профилактика`:
  - на слабом узле cron runner обязан быть non-overlapping и time-bounded;
  - memory guard должен пропускать heavy cron tick при low-memory состоянии;
  - `php-fpm` нельзя оставлять шире реального memory budget сервера;
  - инфраструктурный защитный слой должен существовать независимо от plugin-level fixes.
- `Статус`: `исправлено`

## KB-042. Один общий cron runner оказался слишком грубым для mixed WordPress + EPV2 workload

- `Дата`: `2026-03-30`
- `Симптом`:
  - после отключения storm сайт стабилизировался, но automation всё равно не ехала;
  - generic `wp cron event run --due-now` успевал выполнять обычные WP hooks, а тяжёлые `epv2_process/epv2_publish` либо не доезжали до своего окна, либо конфликтовали по timeout envelope;
  - cron log смешивал обычные hooks и EPV2 hooks в один непрозрачный поток.
- `Корневая причина`:
  - один общий runner для всех cron events не подходит, когда обычные WP hooks короткие, а EPV2 hooks существенно тяжелее;
  - для EPV2 нужен отдельный lock/timeout/memory envelope.
- `Исправление`:
  - safe runner разделён на independent lanes:
    - generic WP due-now с `--exclude=epv2_collect,epv2_process,epv2_publish`
    - отдельный `epv2_publish`
    - отдельный `epv2_process`
    - отдельный `epv2_collect`
  - для каждого lane:
    - свой `flock`
    - свой timeout
    - свой memory guard
    - единый лог в `/var/log/europulse-wp-cron.log`
- `Live-подтверждение`:
  - generic hooks (`action_scheduler_run_queue`, `cmplz`, `rank_math`) продолжили выполняться отдельно;
  - `epv2_collect` начал исполняться как отдельный hook-run, а не теряться внутри общего due-now;
  - cron storm не вернулся.
- `Профилактика`:
  - тяжёлые application hooks нельзя возить в том же delivery envelope, что и generic WP cron;
  - scheduler transport должен быть lane-aware.
- `Статус`: `исправлено`

## KB-043. Process selector слишком широко декодировал payload уже на стадии выбора item

- `Дата`: `2026-03-30`
- `Симптом`:
  - `has_processable_items()` отвечал быстро `true`, но `next_item_for_processing()` мог зависать до десятков секунд ещё до фактического старта process run;
  - особенно это проявлялось на stage-bearing `retry_process/ready_review` items, где для выбора следующего item происходил лишний full JSON decode `ai_payload`.
- `Корневая причина`:
  - hot selector path определял `pipeline_stage` через полный decode payload даже там, где достаточно cheap stage extraction;
  - stage-resume path выполнял expensive reads ещё до реального item processing.
- `Исправление`:
  - в `EPV2_Queue` добавлен cheap processing summary field через `JSON_EXTRACT(ai_payload, '$._meta.pipeline_stage')`;
  - `row_processing_stage()` теперь использует `_epv2_pipeline_stage`, если он уже извлечён SQL-ом;
  - `next_stage_resume_item()` переведён на processing summary path вместо полного payload-driven summary path.
- `Профилактика`:
  - selector не должен тратить память и CPU на full payload hydration, если ему нужен только stage marker;
  - queue selection и queue processing должны иметь разные read contracts.
- `Статус`: `исправлено`

## KB-044. Overly strict memory guard начал блокировать automation даже после стабилизации сервера

- `Дата`: `2026-03-30`
- `Симптом`:
  - safe cron больше не ронял сайт, но `epv2_process/epv2_publish/epv2_collect` часто вообще не стартовали;
  - в `/var/log/europulse-wp-cron.log` повторялось `skip low-memory ...` даже при `MemAvailable ~ 516804 KB` и свободном swap;
  - automation выглядела как idle, хотя сервер уже был способен выполнять хотя бы один EPV2 lane.
- `Корневая причина`:
  - новые memory thresholds оказались слишком консервативными для реального lightweight envelope после hot-path fixes;
  - guard защищал сервер, но при этом ложно блокировал рабочие cron lanes.
- `Исправление`:
  - `/usr/local/bin/europulse-safe-cron.sh`
    - thresholds lowered to practical guarded envelope:
      - `GENERIC_MIN_MEM_KB=196608`
      - `GENERIC_MIN_SWAP_KB=131072`
      - `EPV2_MIN_MEM_KB=393216`
      - `EPV2_MIN_SWAP_KB=131072`
      - `COLLECT_MIN_MEM_KB=524288`
      - `COLLECT_MIN_SWAP_KB=196608`
- `Live-подтверждение`:
  - после снижения thresholds `collect/process/publish` перестали системно отбрасываться только по guard;
  - automation снова дошла до реальных live runs вместо вечных `skip low-memory`.
- `Профилактика`:
  - memory guard должен оставлять резерв, но не должен блокировать lane при уже безопасном текущем envelope;
  - thresholds надо сверять с фактическим RSS hot-path после последних performance fixes.
- `Статус`: `исправлено`

## KB-045. Manual-confirmation state drift прятал items в `retry_process` вместо явного `ready_review`

- `Дата`: `2026-03-30`
- `Симптом`:
  - item `354` нес `manual_confirmation_required=translation`, но оставался в `retry_process`;
  - такие rows не были ни processable fresh/resume, ни честно видимыми manual-review items;
  - automation backlog мог выглядеть пустым, хотя manual debt всё ещё скрыто сидел в queue.
- `Корневая причина`:
  - queue/state contract принудительно переводил в `ready_review` только `manual=media`, но не `manual=translation`;
  - cleanup-процедуры не гарантировали немедленную нормализацию manual-confirmation items из `retry_process/new`.
- `Исправление`:
  - `EPV2_Queue::mark_state()`
    - теперь схлопывает в `ready_review` и `manual=media`, и `manual=translation`, если входящий state был `processing_de/retry_process`;
  - `EPV2_Resilience_Manager::cleanup()`
    - added `normalize_manual_confirmation_queue_states()`, чтобы manual-confirmation rows в `new/retry_process` не оставались скрытыми orphan states;
  - generic one-shot normalization re-saved affected rows через `mark_state()`.
- `Live-подтверждение`:
  - `354` переведён из скрытого `retry_process` в явный `ready_review`;
  - queue больше не содержит hidden manual-confirmation retry tail.
- `Профилактика`:
  - manual-confirmation item должен жить только в `ready_review`;
  - никакой manual-required row не должен оставаться в `new/retry_process`.
- `Статус`: `исправлено`

## KB-046. Heavy `EPV2_Resilience_Manager::cleanup()` в collect preflight блокировал intake

- `Дата`: `2026-03-30`
- `Симптом`:
  - direct `do_action('epv2_collect')` / forced collect path часто не давал ни run row, ни новых items;
  - collect доезжал до источников только нестабильно и слишком тяжело для live envelope.
- `Корневая причина`:
  - `EPV2_Collector::run_scheduled()` вызывал полный `EPV2_Resilience_Manager::cleanup()` ещё до acquire collect lock и до начала source iteration;
  - intake hot path зависел от heavy fleet-wide maintenance, как раньше зависели `process/publish`.
- `Исправление`:
  - в `EPV2_Collector::run_scheduled()` heavy cleanup removed from preflight;
  - оставлен light preflight:
    - `EPV2_Lock_Manager::cleanup()`
    - `EPV2_Queue::normalize_non_active_recoverable_items()`
- `Live-подтверждение`:
  - forced collect `#4607` реально стартовал;
  - live progress дошёл до `processed_sources=46/78`, `collected_items=12`;
  - queue снова пополнилась fresh items `373+` со state `new`.
- `Профилактика`:
  - collect hot path не должен зависеть от global heavy cleanup;
  - fleet-wide normalization должна жить вне source intake loop.
- `Статус`: `исправлено`

## KB-047. Parallel `collect + process/publish` снова создавал resource pressure и делал runtime нестабильным

- `Дата`: `2026-03-30`
- `Симптом`:
  - во время одновременных forced collect + process runs процесс `do_action("epv2_process")` разрастался до `~1.1 GiB RSS`;
  - внешний `curl http://204.168.148.47` intermittently падал, хотя локальный `127.0.0.1` и сами `nginx/php-fpm` оставались живы;
  - runtime оставался слишком хрупким при параллельном EPV2 workload.
- `Корневая причина`:
  - safe-cron уже сериализовал cron lanes через global lock, но сам plugin runtime не запрещал overlap `collect` с `process/publish`;
  - ручной или внутренний trigger мог снова собрать overlap heavy lanes.
- `Исправление`:
  - `EPV2_Jobs`
    - `run_process_windowed()`, `run_process_async()`, `run_publish_windowed()`, `run_publish_async()`
    - теперь early-return, если активен collect lock;
    - добавлены explicit log reasons `skip active collect`.
- `Live-подтверждение`:
  - collect и fresh intake удалось запустить;
  - после фикса concurrency contract закреплён на plugin-level, а не только на shell runner level.
- `Профилактика`:
  - heavy lanes должны сериализоваться не только в safe-cron, но и внутри plugin runtime;
  - automation contract должен быть robust даже при manual/do_action invocation.
- `Статус`: `исправлено`

## KB-048. Orphaned `processing_de` recovery возвращал item в `new` и ломал processing contract

- `Дата`: `2026-03-30`
- `Симптом`:
  - item входил в processing lane, worker умирал, и row возвращался в `new`;
  - это ломало системный контракт: material that already entered processing could reappear as fresh `new`;
  - backlog выглядел как будто item “ещё не трогали”, хотя частичный AI/enrichment state уже существовал.
- `Корневая причина`:
  - `EPV2_Jobs::recover_orphan_process_lock()` при missing/stale worker переводил `processing_de` rows обратно в `new`;
  - fallback normalization потом пыталась чинить часть таких rows в `retry_process`, но это было вторичным и не должно было быть основным contract.
- `Исправление`:
  - stale/missing process lock recovery теперь переводит orphaned rows directly в `retry_process`;
  - resume message explicitly says that item will be resumed from current stage, not returned as fresh.
- `Live-подтверждение`:
  - `383` после recovery перешёл в `retry_process`, а не в `new`;
  - `394` получил auto-resume message and stayed in resume lane.
- `Профилактика`:
  - once item entered processing lane, it must never go back to `new`;
  - orphan recovery must preserve resume semantics and stage continuity.
- `Статус`: `исправлено`

## KB-049. External search engine dependency made supporting-enrichment brittle

- `Дата`: `2026-03-30`
- `Симптом`:
  - when Bing/Google phrase search underperformed, supporting dossier enrichment could stall or remain too shallow;
  - weak source search could cascade into rebuild loops and weak media/SEO readiness.
- `Корневая причина`:
  - enrichment fallback hierarchy still depended primarily on Bing News / Google News / Bing Web before fully exploiting local source pool by context phrases;
  - there was no dedicated engine-independent phrase matching path over active/broad source pools.
- `Исправление`:
  - added `search_supporting_sources_from_context_phrases(...)`;
  - added `context_phrase_queries(...)`;
  - added `context_phrase_score(...)`;
  - source enricher now scans active pool and then broad pool using context phrases even when search engines do not provide good results.
- `Live-подтверждение`:
  - code deployed live; no site instability introduced; cron/process envelope stayed stable after patch.
- `Профилактика`:
  - enrichment must have a source-pool-native fallback that does not rely on third-party phrase search quality;
  - search-engine failures must degrade to slower source scanning, not to stalled dossier quality.
- `Статус`: `исправлено`

## KB-050. Post-AI routing checklist recursively re-entered full normalization and stalled at `36%`

- `Дата`: `2026-03-30`
- `Симптом`:
  - multiple fresh items (`386`, `383`, `394`, `380`) consistently hung at the same visible progress point;
  - live logs showed progress up to:
    - `return_primary_ai`
    - `before_stage_routing_finalize`
    - later, after extra tracing:
      - `stage_routing_after_compact_dossier`
      - `stage_routing_after_normalize_quotes`
      - `stage_routing_after_align_selection`
      - `stage_routing_after_primary_media`
      - `stage_routing_after_fast_quality`
      - `stage_routing_after_light_context_memory`
    - and then no progress beyond routing checklist.
- `Корневая причина`:
  - routing checklist still pulled stage helpers that can re-enter expensive/full normalization paths;
  - specifically, `payload_needs_deeper_supporting_enrichment()` calls `normalize_existing_payload(false)`, which is unsafe inside post-AI routing finalization because it can recursively re-enter stage normalization.
- `Исправление`:
  - added explicit routing-only helpers:
    - `refresh_stage_checklist_for_routing()`
    - `payload_next_required_stage_for_routing()`
    - `payload_language_ready_for_routing()`
    - `de_master_ready_for_routing()`
  - added internal routing substep trace to isolate the exact recursion point.
- `Live-подтверждение`:
  - `#4648` proved the new trace:
    - worker reached `stage_routing_after_light_context_memory`;
    - freeze point narrowed to routing checklist only;
  - wake path, fresh-item selection, site stability, cron, and memory guard were all confirmed healthy during the same run.
- `Профилактика`:
  - post-AI routing must use only cheap, non-recursive readiness checks;
  - full normalization / publish-grade validation belongs to later stages, not to stage-decision hot path.
- `Статус`: `in_progress`

## KB-056. Auto queue rows must not persist in `ready_review` without explicit manual-confirmation contract

- `Дата`: `2026-04-01`
- `Категория`: `QUEUE`
- `Симптом`:
  - `mode=auto` rows accumulated in `ready_review` with no `manual_confirmation_required`;
  - live examples observed: `395-398`;
  - rows were neither valid manual cases nor valid terminal outcomes and polluted automation semantics.
- `Где проявляется`:
  - persisted `ep_epv2_queue` rows where:
    - `state = ready_review`
    - `mode = auto`
    - no explicit manual marker in `admin_notes`.
- `Корневая причина`:
  - older queue logic allowed payload-bearing rows to settle in `ready_review` as a soft editorial sink;
  - after switching to strict auto mode, no invariant collapsed those rows back into automation or terminal state;
  - old rows stayed persisted and kept violating the queue contract.
- `Быстрая диагностика`:
  - regression harness must fail on issue `auto_ready_review_sink`;
  - inspect latest queue rows for `state=ready_review`, `mode=auto`, empty `manual_confirmation_required`.
- `Исправление`:
  - `includes/queue/class-epv2-queue.php`
    - `canonicalize_single_workflow_state()` now forbids saving `ready_review` for auto rows unless explicit manual marker exists;
    - auto rows collapse to:
      - `ready_publish` if payload is already publish-ready;
      - otherwise `retry_process`.
  - `includes/ai/class-epv2-ai-processor.php`
    - added `resolve_persisted_auto_ready_review_sinks()` migration helper.
  - harness:
    - `/root/projects/europulse/scripts/epv2_queue_contract_check.php --resolve-auto-ready-review`
- `Профилактика`:
  - in auto mode, `ready_review` is legal only for explicit manual confirmation contracts;
  - legacy rows must be migrated immediately after invariant changes, not left in queue.
- `Статус`: `in_progress`

## KB-057. `retry_process` rows must persist the same stage that `payload_required_stage()` already demands

- `Дата`: `2026-04-01`
- `Категория`: `STATE_MODEL`
- `Симптом`:
  - many `retry_process` rows stored empty or stale `pipeline_stage`, while normalized payload already required a concrete next stage;
  - live series showed at least `20` rows with drift, including:
    - `395-398`: stored stage empty, required `rebuild_bundle`
    - `375-377`, `380+`: stored stage empty, required `rebuild_bundle`
    - `419`: stored `rebuild_bundle`, required `translate_uk`
- `Где проявляется`:
  - persisted `ep_epv2_queue.ai_payload`
  - rows requeued after worker disappearance, provider cooldown, heuristic fallback, or weak DE quality retry.
- `Корневая причина`:
  - generic retry paths wrote `retry_process` state and retry metadata, but did not always realign persisted payload stage to current stage contract;
  - queue then stored a weaker contract than the payload already implied, and old rows kept re-entering with stale/empty stage.
- `Быстрая диагностика`:
  - for `state=retry_process` compare:
    - stored `_meta.pipeline_stage`
    - `EPV2_AI_Processor::payload_required_stage(normalize_existing_payload(..., false))`
  - any mismatch is a violation.
- `Исправление`:
  - `includes/core/class-epv2-resilience-manager.php`
    - `schedule_retry()` now calls `attach_retry_process_stage_contract()`
    - before persisting `retry_process`, payload is normalized and aligned to required stage.
  - `includes/ai/class-epv2-ai-processor.php`
    - regression harness now reports `retry_process_stage_drift`
    - added migration helper:
      - `repair_persisted_retry_process_stage_contract()`
  - harness:
    - `/root/projects/europulse/scripts/epv2_queue_contract_check.php --repair-retry-stage-contract`
- `Профилактика`:
  - retry state cannot carry a weaker stage contract than payload already requires;
  - every retry write path must persist stage-aligned payload, not just error message and retry counters.
- `Статус`: `in_progress`

## KB-058. `publish_finish` must not run on incomplete translation contract or persist as a pseudo-terminal stage

- `Дата`: `2026-04-01`
- `Категория`: `WORKFLOW`
- `Симптом`:
  - item could enter `publish_finish` and then bounce back into `translate_uk/translate_en`;
  - repeated live case `419` showed:
    - `publish_finish -> translate_uk`
    - earlier runs also hit `de_master_failed_requeued_to_rebuild` on the same branch `auto_finish`
- `Где проявляется`:
  - `process_scheduled()` branch `auto_finish`
  - `run_publish_finish_stage()`
  - persisted payload rows carrying `_meta.pipeline_stage = publish_finish`
- `Корневая причина`:
  - review-ready DE payloads could be sent into `publish_finish` before `UK/EN` bundle was complete;
  - `publish_finish` then mixed two responsibilities:
    - final publish-grade polish
    - unfinished translation repair
  - as a result stage routing could reopen `translate_uk/translate_en`, and in weak cases the same path could also degrade into false DE-quality rebuild handling.
- `Быстрая диагностика`:
  - any row with:
    - `_meta.pipeline_stage = publish_finish`
    - and missing `uk_ready` or `en_ready`
    - or `translations_deferred = true`
    is a contract violation.
- `Инвариант`:
  - `publish_finish` may only run on a completed translation contract;
  - if `UK/EN` are incomplete, the payload must stay on `translate_uk`, `translate_en`, or `translate_finish`, never on `publish_finish`.
- `Исправление`:
  - `includes/ai/class-epv2-ai-processor.php`
    - added `publish_finish_requires_translation_repair()`
    - generic `auto_finish` path now repairs/queues translations first instead of entering `run_publish_finish_stage()` prematurely
    - `run_publish_finish_stage()` now short-circuits back to translation repair when translation contract is incomplete
    - regression harness now reports `publish_finish_incomplete_translation_contract`
    - added migration helper:
      - `repair_persisted_publish_finish_translation_contract()`
  - harness:
    - `/root/projects/europulse/scripts/epv2_queue_contract_check.php --repair-publish-finish-translation-contract`
- `Migration`:
  - live migration run after patch returned `resolved = []`, meaning no persisted `publish_finish` rows remained in broken translation state at check time.
- `Live-подтверждение`:
  - after patch live case `419` progressed:
    - `5201`: `translate_uk -> translate_en`
    - `5202`: `translate_en -> publish_finish`
  - old repeated failure:
    - `5198`: `publish_finish -> de_master_failed_requeued_to_rebuild`
    did not reproduce on the next pass after the fix.
- `Профилактика`:
  - final publish-grade stage must never double as translation completion stage;
  - every terminal-stage invariant must be checked both in runtime branch selection and in persisted queue migration/harness.
- `Статус`: `in_progress`

## KB-059. Infra retry backlog must not starve fresh `new` items in the single process lane

- `Дата`: `2026-04-01`
- `Категория`: `ORCHESTRATION`
- `Симптом`:
  - `new` items entered queue, but old `retry_process/rebuild_bundle` rows with worker/provider failures kept competing for the same process lane;
  - user-visible effect: fresh news often did not start promptly or got buried under infrastructure backlog.
- `Где проявляется`:
  - `EPV2_Queue::next_item_for_processing()`
  - `retry_process` rows with `rebuild_bundle/translate_finish` and infra errors:
    - worker disappeared
    - provider cooldown
    - external worker failed
- `Корневая причина`:
  - fairness logic only guarded against recent monopolizing stage resumes;
  - stale infra backlog rows were still eligible whenever retry window opened, so the single automation lane kept revisiting recovery debt instead of moving fresh `new`.
- `Инвариант`:
  - when a fresh `new` candidate exists, stale infra-recovery backlog rows must not win the same lane by default.
- `Исправление`:
  - `includes/queue/class-epv2-queue.php`
    - `filter_lane_monopolizing_items()` now also filters `is_infra_backlog_process_candidate()`
    - infra backlog means:
      - `state = retry_process`
      - stage in `rebuild_bundle/translate_finish`
      - worker/provider infrastructure error
      - not freshly updated
  - `includes/core/class-epv2-resilience-manager.php`
    - `normalize_targeted_retry_windows()` now enforces quarantine-style retry windows for infra errors too
    - `wake_idle_recoverable_process_queue()` can still wake that class when the lane is otherwise idle
- `Профилактика`:
  - backlog recovery must use a separate priority regime from fresh ingest;
  - single-lane automation needs explicit shielding from infra debt.
- `Статус`: `исправлено`

## KB-060. Source-grounded generated cover must replace terminal media dead-end when no safe real image exists

- `Дата`: `2026-04-01`
- `Категория`: `MEDIA`
- `Симптом`:
  - a large class of items reached full `DE/UK/EN` bundle and still died in `publish_finish` with:
    - `source-first media отсутствует`
    - `rejected_unrecoverable_media`
  - text quality was often already publish-grade; only featured media contract blocked publication.
- `Где проявляется`:
  - `EPV2_Media::resolve_featured_media()`
  - `EPV2_AI_Processor::repair_payload_media()`
  - `publish_finish` / final media gate
- `Корневая причина`:
  - source-first resolver had no safe terminal fallback between:
    - real source/context images
    - risky stock/irrelevant image
    - hard reject
  - this created a dead-end for many otherwise publishable materials.
- `Инвариант`:
  - if no safe real image exists, the system may not jump directly to terminal reject while a source-grounded non-stock cover can be generated safely from the article itself.
- `Исправление`:
  - `includes/media/class-epv2-media.php`
    - added `generated_story_cover()`
    - generates a local upload-backed cover image from title/excerpt/category/source host
    - `resolve_featured_media()` now tries this source-grounded cover before stock fallback and before terminal empty result
    - added `is_generated_story_cover_url()`
    - generated cover now counts as valid relevant media for release/publish gates
  - `includes/ai/class-epv2-ai-processor.php`
    - added `repair_persisted_publish_finish_media_blockers()`
    - class-level migration re-finalizes payloads that already have generated cover instead of re-entering heavy network media repair
- live confirmation:
  - generated cover file created under `/wp-content/uploads/epv2-generated-covers/`
  - URL served `200 OK`
  - media-dead-end class requeued from `rejected` back to `retry_process/publish_finish` by cleanup
- `Migration / live recovery`:
  - `cleanup()` revived media-terminal rejects:
    - `439, 433, 429, 428, 424, 422, 417, 416, 415`
    back into automation.
  - follow-up class-level migration promoted recovered items:
    - `424, 429, 445, 416 -> ready_publish`
    - generated cover payloads now hold `release_quality = 100`
- `Профилактика`:
  - media policy must prefer:
    - source image
    - dossier/supporting image
    - source-grounded generated cover
    - only then stock or terminal reject.
- `Статус`: `исправлено`

## KB-061. Automation can go completely idle when EPV2 is paused under `DISABLE_WP_CRON` and no external runner exists

- `Дата`: `2026-04-01`
- `Категория`: `ORCHESTRATION`
- `Симптом`:
  - visually `Новые` accumulate and nothing goes into work;
  - `wp cron event list` contains no `epv2_collect`, `epv2_process`, `epv2_publish`;
  - queue state appears frozen even though plugin code is loaded.
- `Где проявляется`:
  - `EPV2_Jobs::automation_paused()`
  - `EPV2_Jobs::schedule_recurring()`
  - server runtime with `DISABLE_WP_CRON = true`
- `Корневая причина`:
  - automation was left in paused mode:
    - `epv2_automation_paused = 1`
  - at the same time WordPress pseudo-cron was disabled in `wp-config.php`:
    - `DISABLE_WP_CRON = true`
  - and the server had no crontab nor systemd timer invoking `wp-cron.php`;
  - as a result no EPV2 recurring hooks were scheduled or executed, so the orchestrator looked broken while the actual issue was a sleeping runner path.
- `Инвариант`:
  - if `DISABLE_WP_CRON = true`, a real external runner must exist and automation must not remain paused silently.
- `Исправление`:
  - resumed automation:
    - `epv2_automation_paused -> false`
  - added systemd runner:
    - `/etc/systemd/system/europulse-wp-cron.service`
    - `/etc/systemd/system/europulse-wp-cron.timer`
  - timer now runs:
    - `/usr/bin/php /var/www/europulse/public/wp-cron.php`
    every minute as `www-data`
  - recurring hooks returned:
    - `epv2_collect`
    - `epv2_process`
    - `epv2_publish`
- `Live-подтверждение`:
  - site stayed `200 OK`
  - `php8.3-fpm` / `nginx` remained active
  - `europulse-wp-cron.timer` became active
  - first confirmed scheduled process run after recovery:
    - `EPV2_Runs::latest('process')`
    - `started_at = 2026-04-01 21:36:01 UTC`
    - `processed_item_id = 428`
- `Профилактика`:
  - paused automation state must be included in every stabilization checklist;
  - when page-load cron is disabled, runner presence must be verified before debugging queue logic.
- `Статус`: `исправлено`

## KB-062. V2 queue contract can be bypassed by direct legacy state writes from normalization and stage-queue paths

- `Дата`: `2026-04-01`
- `Категория`: `ORCHESTRATION`
- `Симптом`:
  - even after enabling orchestrator v2 and migrating rows into `new/ready_publish/published`,
    active recoverable items could fall back to raw `retry_process`;
  - user-facing model then drifted back toward legacy queue semantics.
- `Где проявляется`:
  - `EPV2_Queue::normalize_staged_new_items()`
  - legacy process/stage queue paths that still schedule work through `retry_process`
- `Корневая причина`:
  - some code paths bypassed `mark_state()` / v2 state-mapper and wrote legacy recoverable state directly;
  - additionally `normalize_staged_new_items()` explicitly rewrote staged `new` rows into `retry_process`.
- `Инвариант`:
  - when `orchestrator_v2_enabled = true`, any recoverable non-terminal queue transition must collapse to `new`;
  - internal progress belongs in workflow metadata (`workflow_step`, owner token, heartbeat), not in visible queue states.
- `Исправление`:
  - `includes/queue/class-epv2-queue.php`
    - `canonicalize_single_workflow_state()` now maps:
      - `processing_de`
      - `retry_process`
      - `ready_review`
      - `reserve`
      to `new` under v2
    - `normalize_staged_new_items()` no longer rewrites staged `new` rows to `retry_process` when v2 is enabled;
      it only refreshes `workflow_step` metadata
  - reapplied `workflow_v2_apply_migration()` after patch to sanitize rows already rewritten by the old path
- `Live-подтверждение`:
  - active owner `428` is now resolved by runtime as:
    - `state = new`
    - `workflow_step = publish_ready_gate`
    - owner token present
  - selector preview returns:
    - `resume_active_owner`
    - item `428`
    - state `new`
- `Профилактика`:
  - every remaining direct `retry_process` writer must be removed or funneled through v2 metadata
  - no raw `$wpdb->update(... state='retry_process')` is allowed in v2 path
- `Статус`: `in_progress`
## KB-063 Selection Gate Mismatch

- Symptom:
  - `collect` добавлял в `new` кандидаты с `selection.decision=low` и даже `selection.decision=reject`.
  - Визуально блок `Новые` наполнялся слабым материалом, который потом застревал в process lane.
- Root cause:
  - В `EPV2_Collector::ingest_candidate()` hard-reject от `analyze_item()` срабатывал, но затем queue-gate был мягче editorial decision.
  - Для auto publish-grade режима это разрешало попадание в queue слабых кандидатов, если они проходили lowered `should_keep_in_queue()` threshold.
- Invariant:
  - В auto/publish-grade automation в `new` могут попадать только кандидаты с `selection.decision in {review,strong,priority}` и положительным `should_send_to_ai()`.
- Fix:
  - `class-epv2-collector.php`: добавлен жёсткий gate на `decision` и `should_send_to_ai()` перед записью в queue.
  - В `admin_notes` теперь сохраняется `ai_gate` для диагностики.
- Migration:
  - Existing `new` rows with stored `selection.decision in {low,reject}` removed from active queue by moving them to `rejected`.
- Regression check:
  - Forced collect run `#5263` after patch created only `review/strong` candidates with `ai_gate.mode=ai_full`.
- Live confirmation status:
  - confirmed

## KB-064 Broken Source Feed 404

- Symptom:
  - Each collect run finished with one error even when source processing was otherwise stable.
- Root cause:
  - Source `MVG Betriebsmeldungen` (`id=34`) returns `404` for `https://www.mvg.de/services/betriebsaenderungen.html`.
- Invariant:
  - Repeated dead source endpoints must not be treated as unexplained collect noise.
- Fix:
  - not yet applied
- Migration:
  - none
- Regression check:
  - Source query after collect run shows the same endpoint in `last_error`.
- Live confirmation status:
  - confirmed open

## KB-065 Single-Step Process Tick Bottleneck

- `Дата`: `2026-04-02`
- `Категория`: `ORCHESTRATION`
- `Симптом`:
  - один active owner визуально "висел" в `new`, хотя progress шёл;
  - `process` делал только один внутренний шаг на один cron tick, поэтому `translate_uk -> translate_en -> publish_finish -> ready_publish` растягивался на 15-20 минут и выглядел как зависание.
- `Где проявляется`:
  - `EPV2_Jobs::run_process_async()`
  - `EPV2_AI_Processor::process_scheduled()`
- `Корневая причина`:
  - `process_scheduled()` обрабатывал только один step за запуск (`while ($attempts < 1)`), а job layer не делал owner burst;
  - при v2 single-owner contract это превращало нормальный progress в UX- и throughput-bottleneck.
- `Инвариант`:
  - один `process` wake должен прожимать одного и того же active owner через несколько внутренних steps до terminal outcome или bounded runtime budget;
  - тот же wake не должен забирать следующий `new`, пока locked owner не завершён.
- `Исправление`:
  - `includes/jobs/class-epv2-jobs.php`
  - добавлен `run_process_owner_window()`
  - при `orchestrator_v2_enabled=1` `run_process_async()` теперь делает bounded multi-pass burst по одному locked owner:
    - выбирает owner через `workflow_v2_preview_selection()`
    - повторно запускает `process_scheduled(true, false)`
    - останавливается, когда owner released/terminal или исчерпан time/pass budget
- `Migration`:
  - не требуется для row-level data
- `Regression check`:
  - live series:
    - `453`: `queued_translate_uk_stage -> queued_translate_en_stage -> translated_en_successfully -> ready_publish`
    - сразу после завершения `453` system self-advanced to next owner `452`
- `Live confirmation status`:
  - confirmed

## KB-066 Terminal Workflow Metadata Drift

- `Дата`: `2026-04-02`
- `Категория`: `QUEUE CONTRACT`
- `Симптом`:
  - `ready_publish/published` rows сохраняли старые `workflow_step` вроде `translate_en`;
  - из-за этого terminal-ready rows диагностически выглядели как незавершённые и путали дальнейшую отладку.
- `Где проявляется`:
  - `EPV2_Queue::mark_state()`
- `Корневая причина`:
  - при переходе в terminal/user-visible states queue очищала active pointer, но не чистила workflow metadata в `admin_notes._system`.
- `Инвариант`:
  - rows в `ready_publish/published/rejected/error/duplicate` не должны хранить active workflow step/owner heartbeat.
- `Исправление`:
  - `includes/queue/class-epv2-queue.php`
  - `mark_state()` теперь при terminal-ready / terminal states очищает:
    - `workflow_step`
    - `workflow_step_status`
    - `workflow_owner_token`
    - `workflow_heartbeat_at`
  - для terminal states также выставляет `workflow_terminal_reason`
- `Migration`:
  - existing terminal rows migrated by rewriting `_system` metadata in queue table
- `Regression check`:
  - rows `447`, `451`, `453` now show empty workflow step/owner after `published/ready_publish`
- `Live confirmation status`:
  - confirmed

## KB-067 Source-Family Media Mismatch And Sticky Bad Featured Media

- `Дата`: `2026-04-02`
- `Категория`: `MEDIA`
- `Симптом`:
  - published world/politics story could end up with irrelevant supporting image from another thematic domain;
  - once such image was written into payload, media resolver could keep reusing it instead of preferring source-first dossier media.
- `Где проявляется`:
  - `EPV2_Media::same_source_host()`
  - `EPV2_Media::media_relevant_with_details()`
  - `EPV2_Media::resolve_featured_media()`
- `Корневая причина`:
  - `bbc.com` primary story and `ichef.bbci.co.uk` image host were not recognized as the same source family;
  - crypto/finance supporting art could bypass thematic mismatch through trusted-source short-circuit;
  - resolver checked current `existing_url` before dossier-first candidates, so one bad chosen image could become sticky.
- `Инвариант`:
  - source-first image from the primary story family must outrank previously chosen supporting media;
  - cross-topic crypto/bitcoin art must not be accepted for world/politics conflict stories.
- `Исправление`:
  - `includes/media/class-epv2-media.php`
  - `same_source_host()` now recognizes BBC family (`bbc` / `bbci`) as same-source
  - thematic crypto mismatch is rejected before trusted-source short-circuit
  - `resolve_featured_media()` now prefers source dossier media before reusing existing featured URL
- `Migration`:
  - published queue item `457` / post `3181` repaired manually to BBC source image
- `Regression check`:
  - `resolve_featured_media()` for queue item `457` now resolves to BBC primary image
- `Live confirmation status`:
  - confirmed

## KB-068 Lexical Cluster Split Causes Event-Level Duplicate Publish

- `Дата`: `2026-04-02`
- `Категория`: `DEDUPE`
- `Симптом`:
  - one and the same story can be published twice from different liveblogs/sources within the same news window;
  - example family:
    - queue `454`: `Nahost-Ticker: Iran dementiert Waffenruhe-Gesuch an USA`
    - queue `466`: `Iran-Liveblog: ++ Iran bestreitet Bitte nach Waffenruhe ++`
- `Где проявляется`:
  - `EPV2_Story_Clusters::cluster_key()`
  - `EPV2_Deduplicator::is_story_duplicate()`
  - `EPV2_Deduplicator::titles_are_semantically_close()`
- `Корневая причина`:
  - story clustering is built from lexical title/excerpt stems instead of a real event fingerprint;
  - duplicate detection relies too much on title-token overlap;
  - cross-source liveblogs with the same event but different wording can split into different `cluster_id` and evade duplicate blocking.
- `Инвариант`:
  - duplicate gate must work on `event_key + material_delta`, not on title similarity alone.
- `Исправление`:
  - `includes/queue/class-epv2-deduplicator.php`
  - added `event_key` contract based on topic family + essential event tokens
  - duplicate gate now checks:
    - recent `published` posts by `_epv2_event_key`
    - recent non-terminal queue rows by computed/stored `event_key`
  - duplicate decision now also uses `material_delta_exists()` instead of title similarity alone
  - `includes/ingest/class-epv2-collector.php`
    - stores `event_key` in `admin_notes`
  - `includes/publish/class-epv2-publisher.php`
    - stores `_epv2_event_key` on published posts
- `Migration`:
  - future-safe
  - old published duplicates remain historical, but new candidates are checked against both post meta and recent queue state
- `Regression check`:
  - queue items `454` and `466` now produce the same `event_key`
  - synthetic ceasefire candidate is now blocked as `queue_event_key` duplicate before queue insert
- `Live confirmation status`:
  - fixed and confirmed
## KB-069 Intake Starvation From Early Source-Bias Gating And Overstrict Score Contract

- Symptom:
  - при 78 активных источниках collect-run регулярно давал `0` новых материалов
  - forced diagnostic по 896 candidate items показал `175 duplicate`, `707 reject`, `14 low`, `0 accepted_like`
- Root cause:
  - collector применял per-category limits по `source->category_bias` до нормального semantic analysis
  - live settings были зажаты до `max_collect_per_category=1`, `queue_new_max_per_category=1`
  - budget manager убивал borderline newsworthy items в диапазоне `35-39` как `low`
- Invariant:
  - intake fairness должен работать по семантически определённой рубрике кандидата, а не по грубому source bias
  - serious borderline news items не должны умирать как `low`, если у них уже есть public-impact/editorial/practical signal
- Fix:
  - `EPV2_Collector::ingest_candidate()` переведён на analyzed category до queue caps и planner
  - auto-mode collect limits подняты до publish-grade-safe floor `4/8`
  - `EPV2_Budget_Manager` получил узкий uplift для borderline newsworthy items
- Migration:
  - live settings обновлены: `max_collect_per_category=4`, `queue_new_max_per_category=8`, `collect_interval_minutes=15`
- Regression check:
  - до фикса collect `5481` дал `0`
  - после фикса collect `5482` дал `8` новых items

## KB-070 Rubrication Drift For World/Migration/Justice Stories

- Symptom:
  - world migration and justice/political items уходили в неверные рубрики (`politik`/`kultur`/`world`)
- Root cause:
  - lexical weights в `EPV2_Categorizer` недооценивали migration/world patterns и не имели точного legal-political rule
- Invariant:
  - foreign migration tragedies -> `world`
  - `deepfake + staatsanwaltschaft + CDU` -> `politik`
  - domestic Germany migration politics (`Merz + Syrer + Deutschland`) -> `politik`
- Fix:
  - добавлены targeted categorizer rules для migration/middle-east conflict/legal-political/domestic migration politics
- Regression check:
  - `19 tote Migranten im Mittelmeer geborgen` => `world`
  - `Niedersachsen: Staatsanwaltschaft stellt Ermittlungen wegen Deepfake in der CDU ein` => `politik`
  - `Merz will Syrer nach Hause schicken ... in Deutschland` => `politik`

## KB-071 False Media Reject From Editorial CDN Download Path

- `Дата`: `2026-04-02`
- `Категория`: `MEDIA`
- `Симптом`:
  - часть rejected-row уже содержала реальный `featured_media_url` из редакционного источника, но система всё равно считала media exhausted;
  - типичные live кейсы: `515`, `477`, `456`, `493`.
- `Корневая причина`:
  - `EPV2_Media::attach_from_url()` использовал `download_url()` со стандартным WordPress user-agent;
  - часть editorial CDN (`merkur.de`, `fr.de`) отдавала `200 image/jpeg`, но присылала пустое тело на default WP UA;
  - в итоге temp-file был `0 bytes`, attachment не создавался, `validate_featured_media()` возвращал false и payload ложнопопадал в media reject.
- `Инвариант`:
  - editorial source image не может считаться exhausted, если direct browser-like download получает валидный image body.
- `Исправление`:
  - `includes/media/class-epv2-media.php`
  - добавлен robust download path:
    - сначала `download_url()`
    - если temp file пустой, fallback на `wp_safe_remote_get(... stream=true ...)` с browser-like UA, `Accept` и source `Referer`
- `Migration`:
  - false media rejects `515`, `477`, `456` возвращены в `new`
  - добавлен reusable audit script:
    - `scripts/epv2_rejected_media_audit.php`
- `Regression check`:
  - `validate_featured_media()` теперь успешно импортирует и валидирует editorial images:
    - `515 -> attachment 3499`
    - `456 -> attachment 3500`
    - `493 -> attachment 3501`
- `Live confirmation status`:
  - confirmed

## KB-072 Translation Reject Loops Back Into Manual Sink

- `Дата`: `2026-04-02`
- `Категория`: `TRANSLATION`
- `Симптом`:
  - row с готовым DE/master и валидным media уходил в `rejected` после двух bounded translation attempts по `UK/EN`;
  - даже после ручной реанимации в `new` selector продолжал считать row non-processable.
- `Корневая причина`:
  - `resolve_translation_no_progress_terminally()` слишком рано делал terminal reject;
  - при реанимации stale `_system.manual_confirmation_required` оставался в `admin_notes` и сам же блокировал selector.
- `Инвариант`:
  - если `DE master` жизнеспособен, missing `UK/EN` не должен вести в terminal reject после двух попыток;
  - reactivated translation row не может сохранять stale manual-confirmation flags.
- `Исправление`:
  - `includes/ai/class-epv2-ai-processor.php`
    - translation no-progress теперь до `4` попыток возвращает row в controlled `new + translate_uk/en`, а не в `rejected`
    - stale manual-confirmation flags очищаются перед requeue
  - `scripts/epv2_reactivate_translation_rejects.php`
    - reusable rehab script для возврата translation rejects в automation
- `Migration`:
  - в `new` возвращены:
    - `514`
    - `506`
    - `493`
    - `455`
    - `446`
    - `421`
- `Regression check`:
  - `item_is_processable_read_only()` для `421/514/506` теперь снова возвращает `true`
  - `next_item_for_processing()` снова выдаёт `421` как `resume_active_owner`
- `Live confirmation status`:
  - partially confirmed
## KB-073 War Coverage Contract Failure

- Symptom:
  - Ukraine/Russia military stories could be published in `community`, with weak framing or semantically wrong source-first media.
- Root cause:
  - categorizer over-weighted community markers for some Ukrainian-source items
  - rewrite prompt had no explicit war-framing rule
  - media semantic shortcut for editorial hosts was too permissive for `ukraine_attack`
- Invariant:
  - war stories about Russian aggression, Crimea, occupied territories, and strikes on Russian military infrastructure must not route to `community`
  - such stories must use dry factual framing and must not use sympathetic language toward losses of the aggressor's military assets
  - source-first media must still pass war-specific semantic relevance
- Fix:
  - added stronger `ukraine` categorizer overrides
  - added war framing hint into rewrite prompt
  - added war-tone validator
  - tightened `ukraine_attack` media intent deny/allow rules
- Live status:
  - deployed on 2026-04-02
  - queue/post package `521` corrected from `community` to `ukraine`

## KB-074 Selector Starvation Via Stale Media Manual Flag

- Symptom:
  - selector preview could return `none` while `new` rows existed
  - reactivated media rows stayed in `new` but were non-processable
- Root cause:
  - stale `manual_confirmation_required = media_terminal_auto` remained on `new + publish_finish` rows
- Invariant:
  - recoverable `new + publish_finish` rows under v2 must remain processable by automation
- Fix:
  - queue manual-confirmation gate now ignores `media_terminal_auto` for `new + publish_finish` rows when v2 is enabled
- Live status:
  - deployed on 2026-04-02
  - selector preview resumed active owner instead of `none`
