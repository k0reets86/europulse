# EPV2 Single-Owner Orchestrator TODO

## Hard Rules

- [ ] Не делать feature work вне оркестратора, пока не закрыт `new -> active -> ready_publish -> published`
- [ ] Не лечить отдельный item как исключение
- [ ] Не считать прогрессом внутренние стадии `retry_process`, `ready_review`, `publish_finish`, `translate_*`
- [ ] После каждого изменения проверять сайт: `curl -I`, `php-fpm`, `nginx`, `wp cron event list`
- [ ] Не запускать длинные live-циклы до завершения соответствующего миграционного шага
- [ ] Любой найденный новый системный класс ошибки заносить в KB, handoff и этот TODO

## Acceptance Target

- [ ] Пользовательская модель очереди только: `Новые`, `В работе`, `Готово к публикации`, `Опубликовано`
- [ ] Одновременно существует не более одного `active` item
- [ ] Пока текущий item не стал `ready_publish` или terminal, следующий из `Новых` не берётся
- [ ] Нет loop-переходов между внутренними step'ами
- [ ] Нет псевдо-состояний очереди как пользовательской реальности
- [ ] Подтверждены минимум `10` материалов подряд:
- [ ] `new -> active -> ready_publish -> published`
- [ ] Без ручного rescue
- [ ] Без site degradation

## Phase 0. Freeze Point

- [x] Сделать timestamped backup live plugin
- [x] Сделать backup repo plugin mirror
- [x] Сохранить schema очереди
- [x] Сохранить dump данных очереди
- [x] Сохранить snapshots key options
- [x] Сохранить latest process/publish runs
- [x] Сохранить orchestrator reset manifest
- [x] Зафиксировать feature flag для нового оркестратора:
- [x] `epv2_orchestrator_v2_enabled`
- [x] По умолчанию flag должен быть выключен до dry-run миграции

## Phase 1. Define New State Contract

- [ ] Утвердить финальный user-facing state set:
- [ ] `new`
- [ ] `active`
- [ ] `ready_publish`
- [ ] `published`
- [ ] `rejected`
- [ ] `error`
- [ ] Запретить добавление новых user-facing queue states
- [x] Описать mapping legacy states -> new states
- [x] Добавить code-level guard, запрещающий показывать legacy internal states в UI
- [ ] Добавить regression check:
- [ ] UI queue blocks do not expose `retry_process`
- [ ] UI queue blocks do not expose `ready_review`
- [ ] UI queue blocks do not expose `publish_finish`
- [ ] UI queue blocks do not expose `translate_*`

## Phase 2. Define Internal Workflow Contract

- [x] Решить, где хранить v2 metadata:
- [x] `admin_notes._system` as phase-1 storage
- [ ] Описать поля:
- [x] `workflow_version`
- [x] `workflow_owner_token`
- [x] `workflow_claimed_at`
- [x] `workflow_heartbeat_at`
- [x] `workflow_step`
- [x] `workflow_step_status`
- [x] `workflow_step_attempts`
- [x] `workflow_not_before`
- [ ] `workflow_last_error`
- [x] `workflow_terminal_reason`
- [x] Добавить helper `workflow_system_payload(object $item): array`
- [x] Добавить helper `workflow_system_update(int $id, array $fields): void`
- [ ] Добавить regression check на shape internal workflow metadata

## Phase 3. Introduce Owner Model

- [x] Реализовать `active_owner_get()`
- [x] Реализовать `active_owner_claim(int $item_id)`
- [x] Реализовать `active_owner_heartbeat(int $item_id)`
- [x] Реализовать `active_owner_release(int $item_id, string $outcome)`
- [x] Реализовать `active_owner_is_stale(object $item)`
- [x] Реализовать `active_owner_recover_stale()`
- [x] Удалить зависимость ownership от legacy `processing_de` only
- [ ] Убрать старый pointer contract `epv2_active_automation_item` как единственный источник истины
- [ ] Если pointer временно сохраняется:
- [x] он должен быть secondary cache only
- [x] primary truth must live in workflow metadata
- [ ] Добавить regression check:
- [ ] active owner survives internal retries
- [ ] active owner survives non-terminal internal step changes
- [ ] active owner clears only on terminal outcomes

## Phase 4. Replace Selector With Claim/Resume Engine

- [x] Ввести `get_active_owned_item()`
- [x] Ввести `claim_next_new_item()`
- [x] Ввести `resume_or_claim_item()`
- [x] Переписать `next_item_for_processing()` на новую модель
- [ ] Удалить выбор из псевдо-buckets:
- [ ] `resume_stage`
- [ ] `resume_auto`
- [ ] stage-priority heuristics
- [ ] lane monopoly heuristics
- [ ] priority juggling between `new/retry_process/ready_review`
- [x] Selector должен делать только:
- [x] resume active owner
- [x] else claim oldest eligible `new`
- [x] else return null
- [ ] Добавить regression harness:
- [ ] if active owner exists, selector always returns it
- [ ] if no active owner, selector claims oldest `new`
- [ ] if active owner is stale, recovery returns it to `new`

## Phase 5. Introduce Canonical Step Machine

- [ ] Определить canonical steps:
- [ ] `build_de_master`
- [ ] `translate_uk`
- [ ] `translate_en`
- [ ] `finalize_media`
- [ ] `finalize_seo`
- [ ] `publish_ready_gate`
- [ ] Определить bounded repair side-steps:
- [ ] `refresh_context`
- [ ] `repair_translation`
- [ ] `repair_media`
- [ ] Для каждого шага описать входные инварианты
- [ ] Для каждого шага описать допустимые выходы:
- [ ] `done`
- [ ] `retry_same_step_after_delay`
- [ ] `escalate_to_next_internal_repair_step`
- [ ] `terminal_reject`
- [ ] `terminal_error`
- [ ] Добавить единый dispatcher:
- [ ] `run_active_workflow_step(object $item): array`
- [ ] Убрать queue-level stage routing from hot path
- [ ] Добавить regression check:
- [ ] no queue pseudo-state emitted by step machine

## Phase 6. Rebind Existing Business Logic Into Steps

- [ ] Привязать current DE generation logic to `build_de_master`
- [ ] Привязать translation logic to `translate_uk`
- [ ] Привязать translation logic to `translate_en`
- [ ] Привязать media logic to `finalize_media`
- [ ] Привязать SEO/release/google logic to `finalize_seo`
- [ ] Привязать final publish-ready decision to `publish_ready_gate`
- [ ] Убрать direct queue-state side effects from these code paths
- [ ] Пусть они возвращают только step outcomes
- [ ] Добавить regression check:
- [ ] internal step completes without mutating user-facing state directly

## Phase 7. Remove Legacy Queue Semantics

- [ ] Удалить `retry_process` as orchestration concept from selector
- [ ] Удалить `ready_review` as normal auto-flow destination
- [ ] Удалить `publish_finish` as visible queue-state concept
- [ ] Удалить `translate_*` as queue-state concept
- [ ] Удалить auto-ready-review sink behavior
- [ ] Удалить legacy fallback promotions in selection path
- [ ] Удалить hidden state rewrites from cleanup where possible
- [ ] Добавить regression check:
- [ ] legacy queue semantics no longer affect scheduling

## Phase 8. Migration Layer

- [x] Написать `workflow_v2_dry_run_migration()`
- [x] Написать `workflow_v2_apply_migration()`
- [ ] Для каждого non-terminal row:
- [x] вычислить new user-facing state
- [x] вычислить `workflow_step` из payload/stage
- [x] вычислить `workflow_not_before` из retry metadata
- [x] сохранить existing diagnostics
- [x] назначить не более одного active owner
- [x] остальные вернуть в `new`
- [ ] Для terminal rows:
- [x] оставить `published/rejected/error/duplicate` as terminal
- [ ] Вывести migration report:
- [x] total rows
- [x] active rows
- [x] new rows
- [x] ready_publish rows
- [x] terminal rows
- [x] ambiguous rows requiring manual classification
- [x] Прогнать dry-run
- [x] Сохранить dry-run report
- [ ] Прогнать apply migration only after report review

## Phase 9. Recovery Redesign

- [ ] Cleanup must only:
- [ ] detect stale owner
- [ ] release stale owner
- [ ] restore item to `new` with preserved `workflow_step`
- [ ] Cleanup must stop:
- [ ] inventing new queue semantics
- [ ] broad reclassification across pseudo-states
- [ ] random promotion/demotion based on heuristics
- [ ] Переписать stale recovery around owner model
- [ ] Добавить regression check:
- [ ] stale owner recovery preserves step
- [ ] stale owner recovery does not create duplicate active owners

## Phase 10. Media Contract Inside New Engine

- [ ] `finalize_media` must be a bounded internal step
- [ ] generated covers must remain non-publish-grade
- [ ] source-host media first
- [ ] supporting-source media second
- [ ] stock/media fallback only if explicitly allowed by contract
- [ ] no queue-level `media blocker` pseudo-state
- [ ] no `publish_finish -> retry_process -> publish_finish`
- [ ] If no valid real media:
- [ ] bounded internal retries
- [ ] then explicit terminal machine decision
- [ ] Добавить regression check:
- [ ] source-host editorial image does not false-reject
- [ ] generated cover never passes publish gate

## Phase 11. Translation Contract Inside New Engine

- [ ] `translate_uk` and `translate_en` must be strict sequential steps
- [ ] completed translations must not reopen DE build
- [ ] translation retry must stay in owner contract
- [ ] bounded retry count per translation step
- [ ] then explicit internal escalation or terminal decision
- [ ] Добавить regression check:
- [ ] completed translation does not backslide to build step

## Phase 12. Publish-Ready Gate

- [ ] Implement one canonical `payload_ready_for_publish_v2()`
- [ ] Gate must validate:
- [ ] DE title/excerpt/content
- [ ] UK title/excerpt/content
- [ ] EN title/excerpt/content
- [ ] SEO/meta presence
- [ ] publishable featured media
- [ ] taxonomy/category resolved
- [ ] no shell/placeholder content
- [ ] If gate fails:
- [ ] return next internal repair step
- [ ] not queue pseudo-state
- [ ] Add regression check:
- [ ] gate returns deterministic reason set

## Phase 13. UI Contract Finalization

- [ ] Queue page must show only:
- [ ] `В работе`
- [ ] `Новые`
- [ ] `Готово к публикации`
- [ ] `Опубликованные`
- [ ] Status labels must map:
- [ ] active owner -> `В работе`
- [ ] all inactive recoverable -> `Новый`
- [ ] publish queue -> `Готов к публикации`
- [ ] Remove user-facing language about retry/review/auto-repair internals
- [ ] Verify queue filters still work after simplification

## Phase 14. Cron/Runner Contract

- [x] Keep `collect`, `process`, `publish` as wake triggers only
- [x] Process wake must:
- [x] resume active owner
- [x] else claim next `new`
- [x] Publish wake must only publish `ready_publish`
- [ ] Confirm no duplicate async trigger path remains
- [ ] Confirm no hidden admin/view path triggers pipeline mutation

## Phase 15. Test Harness

- [x] Add orchestrator v2 regression harness script
- [ ] Add tests for:
- [ ] one active owner only
- [ ] no new claim while active owner exists
- [ ] owner survives internal retry
- [ ] owner released only on terminal outcome
- [x] migration maps legacy rows correctly
- [ ] publish-ready gate deterministic
- [ ] stale recovery deterministic

## Phase 16. Dry Run On Live Data

- [x] Run v2 migration in dry-run mode against current queue snapshot
- [x] Save dry-run report to `backups/` or `reports/`
- [ ] Inspect ambiguous rows
- [ ] Resolve migration edge cases before enabling v2

## Phase 17. Enable v2 Behind Feature Flag

- [ ] Add feature flag option
- [ ] Deploy code with v2 disabled
- [ ] Validate no site degradation
- [x] Enable v2 in controlled live window
- [ ] Disable old scheduler semantics when v2 is enabled

## Phase 18. Live Acceptance Series

- [ ] Collect multiple fresh news items
- [ ] Confirm they appear in `Новые`
- [ ] Confirm exactly one item moves to `В работе`
- [ ] Confirm same item remains active through all internal steps
- [ ] Confirm it reaches `Готово к публикации`
- [ ] Confirm only then second item moves from `Новые` to `В работе`
- [ ] Confirm at least `3` consecutive items reach `ready_publish`
- [ ] Confirm at least `3` consecutive items publish successfully
- [ ] Extend proof to `10` materials

## Phase 19. Quality Audit

- [ ] For each accepted published item verify:
- [ ] post exists
- [ ] `post_status = publish`
- [ ] title normal
- [ ] excerpt normal
- [ ] body complete
- [ ] no mixed languages
- [ ] SEO meta present
- [ ] featured image relevant
- [ ] taxonomy correct
- [ ] translated variants exist

## Phase 20. Cleanup After Acceptance

- [ ] Remove dead legacy selector code
- [ ] Remove unused pseudo-state helpers
- [ ] Remove outdated UI copy about retry/review sinks
- [ ] Update KB with final orchestrator architecture
- [ ] Update handoff with v2 invariants
- [ ] Keep rollback backup untouched

## Execution Order

- [ ] Execute Phase 1
- [ ] Execute Phase 2
- [ ] Execute Phase 3
- [ ] Execute Phase 4
- [ ] Execute Phase 5
- [ ] Execute Phase 6
- [ ] Execute Phase 7
- [ ] Execute Phase 8
- [ ] Execute Phase 9
- [ ] Execute Phase 10
- [ ] Execute Phase 11
- [ ] Execute Phase 12
- [ ] Execute Phase 13
- [ ] Execute Phase 14
- [ ] Execute Phase 15
- [ ] Execute Phase 16
- [ ] Execute Phase 17
- [ ] Execute Phase 18
- [ ] Execute Phase 19
- [ ] Execute Phase 20
