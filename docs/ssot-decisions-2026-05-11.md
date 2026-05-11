# SSOT Design — Operator Decisions (2026-05-11)

Decisions on the 6 open questions from `architecture-2026-05-11-ssot-design.md` §9.
Locked here as authoritative input before Phase A starts.

---

## Q1. Quality threshold в publish-gate

**Decision: AI-endorsed relax до 70 везде.**

- Default strict: все 4 streams (editorial / release / google / seo) ≥100 + zero warnings.
- AI endorsement override: если `story_card.editorial_match='match'` AND `publishable_estimate ∈ {high, medium}` AND `confidence ≥ 0.7` → все 4 streams threshold = **70** + same "no warnings" requirement.
- **Единый контракт**: тот же threshold logic в `de_master_is_viable`, `translator_ready_for`, и `publish_gate`. Текущий split 78/100 убирается.
- Quality oscillation guard: если quality.score ∈ [98, 99] AND no_warnings → round up to 100 (рудиментарно, но safe).

Why: больше доверия AI verdict, меньше oscillation 99↔100 в publish_gate, меньше items застревает в manual_review из-за rounding.

---

## Q2. Editorial recovery policy

**Decision: Strict rejected — только operator может recover.**

- `soft_terminal_state_guard` удаляется. Rejected stays rejected.
- Спасение через admin UI:
  - **Force-publish button** (уже существует) — operator явно overrides.
  - **Manual reset** в admin — operator переводит rejected → new для re-process.
- Maintenance handler `force_reject_zombie_pre_ai_rejects` остаётся (cleanup для resurrected pre-AI rejects).
- Watchdog `auto_reset_legacy_quarantine` остаётся только для строго legacy reasons (явный whitelist).

Why: убирает Conflict Zone (rejected↔ready_review oscillation). Operator получает predictable pipeline, false rejects видны в логах как rejected (не теряются в loop'е).

Migration note: при switch authority в Phase D — `reactivate_planner_soft_rejected_items` тоже удаляется (planner action не может оживить rejected без operator action).

## Q3. Wikimedia / Pexels policy

**Decision: Strict block в publish-gate, никаких conditionals.**

- `payload_featured_media_is_generic_stock` всегда возвращает true для hosts: `commons.wikimedia.org`, `upload.wikimedia.org`, `pexels.com`, `images.pexels.com`.
- Независимо от `story_card.media_required` (`named_entity`, `stock_ok` — не оправдание).
- Item без real photo → manual_review через existing quarantine path.
- Resolver всё ещё может попытаться wiki/pexels как last-resort (это разрешено, не меняем) — gate всё равно отфильтрует на финальной проверке.

Why: подтверждение текущего поведения и memory doctrine (`feedback_no_wiki_pexels_as_featured.md`). Operator: «они дают говно по теме».

What works as featured:
- `source_dossier_image` (фото из RSS-поста-источника)
- `parsed_supporting_image` (из supporting sources)
- `context_supporting_image`
- `is_source_host_media` (медиа с домена источника)

## Q4. `ready_review` deprecation

**Decision: Полный rename — `ready_review` удаляется как state.**

Migration (выполнить в Phase E cleanup):
```sql
UPDATE ep_epv2_queue SET state='manual_review' WHERE state='ready_review';
```
(на момент решения было 1 row в ready_review против 7 в manual_review.)

Code changes:
- Удалить `'ready_review'` из ALLOWED_TRANSITIONS table.
- Удалить упоминания в admin UI labels, user_facing_state mapper.
- Удалить все `mark_state($id, 'ready_review', ...)` calls — заменить на `'manual_review'`.
- Удалить `is_ready_review_state` predicate (если есть) или alias к `is_manual_review_state`.

Why: один state вместо двух. Меньше confusion в admin UI ("ждёт решения" vs "ручной обзор" — одно и то же), меньше веток в state machine, не остаётся legacy aliases.

## Q5. Maintenance handler ordering

**Decision: Sanitize-first, потом promote. Explicit ordering table.**

Execution order per `bridge_maintenance` tick:

1. **CLEANUP** (terminal cleanup first, не трогает live work):
   - `force_reject_zombie_pre_ai_rejects` — pre-AI rejects не воскресают
   - `trim_old_terminal_items(80)` — keep 80 newest terminal rows
   - `promote_live_published_rows` — WP post exists → published

2. **SANITIZE** (active items → safe state):
   - `sanitize_non_publish_grade_new_items` — kill decision=low/reject в new
   - `sanitize_stuck_processing_de_items` (30 min orphan)
   - `sanitize_stuck_publishing_items` (8 min stuck publishing)
   - `sanitize_stuck_ready_publish_items` (5 min без post_id)
   - `sanitize_low_grade_ready_publish_items` — quality below threshold
   - `quarantine_pathological_workflow_loops` — workflow_step_attempts > limit

3. **PROMOTE** (rescue после sanitize):
   - `promote_ready_like_rows` — payload ready → ready_publish
   - `reactivate_media_recoverable_rows` — media найдено → clear retry_after
   - `auto_route_misclassified_new_items` — manual_required → manual_review, payload_ready → ready_publish
   - `auto_promote_complete_manual_review_items` (max 2 promotes) — completed payload → retry_process

4. **WATCHDOG** (independent):
   - `release_stuck_active_item`, `repair_polylang_links`, `dedupe_published_posts`, `auto_reset_legacy_quarantine`

Why: cleanup сначала освобождает state machine от мусора. Sanitize переводит залипшие active items в manual_review до того, как promote начнёт их искать — нет race "stuck publishing рывком ушёл в manual_review, а через 100ms promote попытался его как ready вытащить в retry_process". После Q2 (strict rejected) promote становится narrower scope — только manual_review→retry_process с auto_promote_count limit.

Implementation: каждый handler принимает `$tick_context` и логирует phase ('cleanup'|'sanitize'|'promote'|'watchdog'). Bridge_maintenance orchestrates strict order.

## Q6. AI cost ceiling в shadow mode

**Decision: Soft cap +30%, auto-off при +50%.**

Cost monitoring during Phase C (shadow burn-in 48-72h):
- Baseline = avg daily AI cost за 7 дней **до** shadow enable (rolling window).
- **+30% overhead**: alert в `ep_epv2_log` level=warn, в admin notice. Operator оценивает причины.
- **+50% overhead**: автоматически `epv2_authority_v2='off'`, alert level=error. Shadow disabled до investigation.

Implementation:
- Hook в `EPV2_Stats::record_payload_ai_usage` — каждый increment сравнивается с rolling baseline.
- Daily cron сравнивает `today_cost` vs `baseline * 1.30` / `baseline * 1.50`.
- Admin UI показывает текущий overhead percentage в shadow mode block.

Realistic expectation: реальный overhead ожидается +5-15% (большинство decisions — read из existing payload, не новые AI calls). Cap 30/50 — safety margin.

Why: оставляет пространство для legitimate variance (item mix может качаться ±20% от week to week независимо от code), но не позволяет undetected drift к 2x billing.
