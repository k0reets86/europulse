# EPV21 Autopilot Report 2026-04-27

## What Changed

- Fixed publish timer bypass: forced bridge publish runs no longer bypass `publish_not_before`.
- Fixed admin publish countdown: the `Готово к публикации` block now uses the real first `ready_publish` slot and shows `00:00` when due instead of replacing it with a future WP-Cron slot.
- Fixed ready-publish scheduling invariant: the first already-waiting item keeps its existing `publish_not_before`; later items are spaced by the configured 5-minute interval.
- Fixed bridge `next_ready_publish`: runtime state now reads `_system.publish_not_before`, not workflow retry timestamps, so the orchestrator does not spam `publish` before the slot.
- Fixed AI provider failure loop: worker payloads with `All AI providers failed` now go into explicit retry/backoff and release the active owner instead of repeating `rebuild_bundle`.
- Admin queue order is now `Новые`, `В работе`, `Готово к публикации`, `Отклонённые`, `Опубликованные материалы`.

## Autonomous Verification

- Existing ready queue published without manual process/publish calls:
  - `1015` published at `2026-04-27 10:25:21`
  - `1016` published at `2026-04-27 10:30:25`
  - `1017` published at `2026-04-27 10:35:30`
  - `1018` published at `2026-04-27 10:40:47`
  - `1019` published at `2026-04-27 10:46:13`
- The 5-minute lane held after the first due item: each following publish was reanchored from the previous actual publish time.
- `bridge_health.acceptance.consecutive_autonomous_publish_grade = 12`, `remaining_to_target = 0`, `status = accepted`.
- `queue_contract.status = ok`, `violations_count = 0`.
- After the ready queue emptied: `active_automation_item = 0`, `has_processable_items = false`, `next_ready_publish = 0/null`.
- Automatic collect resumed and ran by orchestrator without manual collect:
  - collect run `24837`, `collected_items = 1`, item `1020`
  - process runs advanced it until AI-provider failure backoff took over
  - final state: item `1020` is not processable until `2026-04-27 11:40:26`

## Final Live State

- Queue: `published=14`, `new=1` (`1020` in retry/backoff).
- Automation flags: `epv2_collect_paused=0`, `epv2_automation_paused=0`.
- Services active: nginx, php8.3-fpm, `epv2-worker`, `epv2-orchestrator`.
- Site check: `http://127.0.0.1/` returns WordPress `301`, no `500/502`.
- PHP lint passed for touched plugin files:
  - `includes/queue/class-epv2-queue.php`
  - `includes/admin/class-epv2-admin.php`
  - `includes/ai/class-epv2-ai-processor.php`
  - `includes/publish/class-epv2-publisher.php`

## Remaining Work

- Watch item `1020` after `2026-04-27 11:40:26`; if all AI providers are still unavailable, it should extend retry/backoff or move to manual review, not loop.
- Continue gathering fresh autonomous collect/process/publish cycles; current acceptance target is met, but only one new post-collection item was available in the latest collect.
- Review AI provider health/cooldown storage; `epv2_ai_provider_health` option was not present, while runtime still reported provider-disabled behavior.
- Keep an eye on php-fpm memory peak; current service is healthy, but the reported peak reached `1.5G`.
