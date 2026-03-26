# SESSION HANDOFF

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
