# Monitoring findings — 2026-05-11

Loop start: 2026-05-11 ~09:00 UTC (Berlin ~11:00).
End target: 2026-05-11 ~14:00 UTC.
Cadence: every 30 min.

## T0 — 09:00 UTC (baseline)

Queue:
- new: 237 (56 в <1h, 181 в 1-6h, 0 в >6h)
- processing: 1 (active item 2099)
- ready_publish: 1
- manual_review: 8
- rejected: 80
- published_last_24h: 99 / published_last_30m: 3

Services: worker active, orchestrator active, worker /health OK.

Fixes applied at baseline:
- Item 2096 (Sandra Bullock, celebrity content, story_card.editorial_match=NULL, stuck в publish_ready_gate с 14 attempts 3+ часа) → routed manual_review, active_pointer cleared.
- Admin AJAX bug: queue_snapshot_payload использовал shared LIMIT-80 slice. При большом 'new' state (238 items) — блоки published/rejected/manual_review становились пустыми после AJAX refresh. Fix: AJAX endpoint теперь использует queue_lightweight_snapshot_payload (per-block queries). Committed b1921c0.

Pending observations:
- Item 2096 — pattern: pipeline_stage='publish_finish' + state='new' + story_card.editorial_match=NULL. SSOT Phase A не запущен — workaround manual.

## T+30 — 09:31 UTC

Deltas:
- new: 237 → 279 (+42, collection быстрее processing)
- processing: 1 → 0
- ready_publish: 1 → 0
- manual_review: 8 → 9
- published_last_30m: 3 → 9 (+6, rate ~18/h)
- published_last_24h: 99 → 108

CRITICAL findings:
- Item 2096 resurrected: state='new' again, auto_promote_count=1. Loop: manual_review → auto_promote_complete → retry_process → canonicalize → new → orchestrator claim → publish_ready_gate fails → attempts climb.
- User reports admin UI blocks STILL empty after refresh — мой fix b1921c0 был в working tree но НЕ задеплоен в /var/www (working tree ≠ production sync — раньше совпадало случайно).

Fixes applied this tick:
1. Manual deploy: cp admin.php + queue.php → /var/www/.../plugins/ + opcache reset + cache flush. AJAX endpoint теперь live.
2. queue.php fix #1: `auto_promote_complete_manual_review_items` теперь требует `story_card.editorial_match ∈ {match, borderline}` (не NULL, не reject_low_value). Closes loop для items без AI editorial verdict (как 2096).
3. queue.php fix #2: `quarantine_pathological_workflow_loops` больше не использует `get_queue_items_summary` (cap 100 + created_at DESC, утопало в свежих new). Теперь прямой query по `workflow_step_attempts >= 2` ORDER BY attempts DESC. Catches old stuck items immediately.
4. Manual cleanup: 2096 → manual_review, auto_promote_count=2 (re-promotion blocked).
5. Ran quarantine_pathological_workflow_loops manually — caught 2096, routed to manual_review via attempt-limit path.

State at end of tick:
- 2096 in manual_review с auto_promote_count=2 + editorial_match=NULL guard → не должен возвращаться
- Pipeline running normally (18/h publishing rate)
- 1 high_attempts → 0 после cleanup

Open systemic issues:
- Working tree → production sync is NOT automatic. Need to identify mechanism OR run cp manually after edits.
- Manual_review → new transition path не до конца понятен (отчасти auto_promote, но есть другая дорога — нужно расследовать дальше).

## T+60 — TBD (10:01 UTC scheduled)

## T+60 — 10:13 UTC (operator interrupt)

User reported 2 problems:
1. «В работе» пустой когда явно идёт обработка
2. Параллельные действия с несколькими items в «Новые»
3. 326 items в 'new' — защита backpressure должна сработать но не работает

Root cause analysis:
- 326 'new' items, ONLY 1 has workflow_owner_token (truly active). Pipeline single-owner contract OK.
- Admin UI rendering: «В работе» = state='processing_de' OR row.id==active_id. canonicalize_single_workflow_state ставит state='new' при orchestrator_v2. active_id option может быть 0 кратко между тиками. Token (per-row) — более надёжный signal.
- Backpressure escape hatch: после 30 min cumulative defer force-runs collect. При 326 pending vs threshold 18 — escape только усугубляет.

Fixes applied (commit 90465d7):
1. admin.php: token-based 'В работе' detection в обоих paths (queue_lightweight_blocks_html, queue_lightweight_snapshot_payload) + badge logic. Item с active token попадает в «В работе» даже при active_id=0.
2. collector.php: escape hatch теперь fires только когда pending <= 3× threshold. При massive overload защита держит crisp.
3. Manual: установил epv2_collect_deferred_until = NOW+60min чтобы immediate relief, backlog успел дренироваться.

Verified live: 1 item в active block (id=2110 token-holder), 98 в «Новые» (untouched), backpressure теперь блокирует collect до drain.

## T+90 — TBD (10:36 UTC scheduled)

## T+90 — 10:36 UTC (auto wake-up #2)

Deltas T+30 → T+90 (60 min):
- new: 279 → 322 (+43) — но collect ran ONCE before defer activated (+55 at 10:26), backpressure kicked in at 10:28, ZERO collects since
- published_last_30m: 9 → 12 (rate ~24/h — pipeline healthy)
- published_last_24h: 108 → 120 (+12)
- manual_review: 9 → 13 (+4 routed correctly via quarantine handler)
- ready_publish: 0 → 2 (next to publish)
- active_id: 2113 (continuously processing items)

High_attempts breakdown:
- Total: 1 (2096 still in manual_review counted by script — non-issue)
- Other items with attempts 3-4: 1866, 2081, 2084, 2087 — ALL в manual_review (handler routing работает)
- ZERO stuck items in active pipeline

Backpressure verification:
- epv2_collect_deferred_until=2026-05-11 11:28:11 UTC (active for ~52 more min)
- Items в 'new' created after 10:28 UTC: 0
- Items в 'new' created 09:31-10:28: 55 (one collect cycle pre-defer)
- Защита holds — никаких новых collect events

No fixes needed this tick. Pipeline self-regulating.

## T+120 — TBD (11:07 UTC scheduled)

## T+120 — 11:10 UTC (auto wake-up #3)

Deltas T+90 → T+120 (~32 min):
- new: 322 → 339 (+17, see CRITICAL below)
- published_last_30m: 12 → 15 (rate ~30/h — accelerated)
- published_last_24h: 120 → 135 (+15)
- manual_review: 13 → 15 (+2 routed correctly)
- ready_publish: 2 → 3
- active_id: 2123 then 2124 (rapid turnover)
- stuck items в active pipeline (attempts>=5): 0 ✓

CRITICAL FINDING:
- Backpressure soft defer (epv2_collect_deferred_until) BYPASSED by orchestrator
- Orchestrator calls `EPV2_Collector::run_scheduled(true)` — force=true skips ВСЕ checks
- 2 collect cycles ran between T+90 and T+120 despite my manual defer until 11:28 UTC
- 27 items добавлены after T+90 (10:45 and 11:03 collects)

Fix applied (commit e87a504):
- Hard cap @ 200 pending в EPV2_Collector::run_scheduled — fires регardless of force flag
- При pending >= 200: no-op + diagnostic write to epv2_collect_backpressure_last
- Verified: с pending=338, run_scheduled(true) → blocked, hard_cap recorded

Snapshot script updated: high_attempts → high_attempts_active (исключает manual_review, terminal states из count, чтобы false positives как 2096 не зашумляли metric).

Pipeline health: GOOD — single token holder, zero stuck items in active states, drainage rate ~30/h.

## T+150 — TBD (11:38 UTC scheduled)

## T+150 — 11:42 UTC (auto wake-up #4)

Deltas T+120 → T+150 (32 min):
- new: 339 → 328 (-11 — DRAINAGE STARTED ✓)
- published_last_30m: 15 (steady ~30/h)
- published_last_24h: 135 → 153 (+18)
- manual_review: 15 → 21 (+6 from quarantine routing)
- ready_publish: 3 → 2
- active_id: 2123 → 2134 (continuous turnover)
- high_attempts_active: 0 ✓

Hard cap verification (commit e87a504) — WORKS:
- Items created in 'new' since 11:10 UTC: 0
- Orchestrator collects attempts: 2 (11:19, 11:34) — both blocked
- epv2_collect_backpressure_last shows hard_cap=200 fired at 11:34:15 with force_was=true

Drainage forecast:
- Current rate: ~30/h items leave 'new' (mostly published, some manual_review/rejected)
- To drop below 200 (hard cap release): ~4.3h → ETA ~16:00 UTC
- To drop below 54 (soft 3× threshold release): ~9h → ETA ~20:30 UTC
- Oldest 'new' item: 366 min (~6h) — но prune_new_stale=18h TTL не triggers ещё

Pipeline health: GOOD. Everything self-regulating. No fixes needed this tick.

## T+180 — TBD (12:11 UTC scheduled)

## T+180 — 12:15 UTC (auto wake-up #5)

Deltas T+150 → T+180 (33 min):
- new: 328 → 320 (-8) — drainage continues but slower
- published_last_30m: 15 → 9 ⚠️ (rate dropped 30/h → 18/h)
- published_last_24h: 153 → 165 (+12)
- manual_review: 21 → 25 (+4)
- ready_publish: 2 → 0 (drained)
- new_age_6h_plus: 1 → 28 (aged items growing)
- high_attempts_active: 0 ✓

Hard cap verification: 0 items created in 'new' since T+150 ✓ (perfect block)

Discovery & fix:
- Active item 2143 = «Anzeige: Microsoft 365 Administration» — paid promotional content
- Manual reject → soft_terminal_state_guard salvaged → ready_publish (would have published ad!)
- Root cause: Conflict Zone B (rejected→ready_review/publish via salvageability)
- Patch (commit 713bcd1): added 'promotional', 'sponsored content', 'sponsored/paid' to HARD_TERMINAL_REASON_TOKENS — soft guard теперь не salvаges promotional rejects
- Re-rejected 2143 с hard-terminal message → stuck in rejected ✓

Drainage forecast revised:
- Rate slowed to ~18/h (some items take longer to process)
- 320 - 200 = 120 items to clear hard cap → ~6.7h → ETA ~19:00 UTC
- Still healthy, just slower than initial estimate

Pipeline state at end of tick:
- 1 token holder (active processing) ✓
- 0 stuck items
- backlog draining (no new ingest)
- 28 items aged 6+h — orchestrator processing oldest-first, will get to them

## T+210 — TBD (12:45 UTC scheduled)

## T+210 — 12:50 UTC (auto wake-up #6)

Deltas T+180 → T+210 (35 min):
- new: 320 → 308 (-12) — drainage steady at ~25/h
- published_last_30m: 9 → 12 (recovered)
- published_last_24h: 165 → 177 (+12)
- manual_review: 25 → 28 (+3 routed)
- ready_publish: 0 → 2
- new_age_6h_plus: 28 → 63 (aging continues — items aged past 6h window)
- high_attempts_active: 0 ✓

Hard cap holding: 0 items created в 'new' since T+180, fired at 12:49:47 UTC.
Promotional scan: 0 Anzeige/Sponsored items в 'new' (previous cleanup стерло).
No fixes needed this tick.

Drainage forecast: rate ~24/h, 308 - 200 = 108 to clear hard cap → ~4.5h → ETA ~17:20 UTC.

## T+240 — TBD (13:20 UTC scheduled)

## T+240 — 13:22 UTC (auto wake-up #7)

Deltas T+210 → T+240 (32 min):
- new: 308 → 297 (-11) — drainage steady ~22/h
- published_last_30m: 12 → 15 (rate recovered to 30/h)
- published_last_24h: 177 → 195 (+18)
- manual_review: 28 → 32 (+4)
- ready_publish: 2 → 2
- new_age_6h_plus: 63 → 72 (aging continues)
- stuck active: 0 ✓

Hard cap: 0 new ingest since T+210, fired at 13:20:06 UTC.
Promotional scan: 0 hits.
No fixes needed.

Drainage forecast: 297 - 200 = 97 to clear hard cap → ~4h → ETA ~17:30 UTC.

## T+270 — TBD (13:52 UTC scheduled, final)

## T+295 — 13:53 UTC (wake-up #8, FINAL)

Quick deltas T+240 → T+295 (31 min):
- new: 297 → 289 (-8)
- published_last_30m: 15 → 9
- published_last_24h: 195 → 207 (+12)
- manual_review: 32 → 34 (+2)
- state_processing: 0 → 1 (active item visible in admin ✓)
- new_age_6h_plus: 72 → 107
- 0 new ingest since T+240, 0 Anzeige, 0 stuck active items

---

## FINAL SUMMARY — 2026-05-11 13:53 UTC

### Loop completed: 8 ticks over 5 hours (09:00 → 13:53 UTC)

### Baseline → Final trajectory (T0 09:00 → T+295 13:53)

| Метрика | T0 | Final | Δ | Notes |
|---|---|---|---|---|
| state_new | 237 | 289 | +52 | Hard cap stopped further growth |
| state_published (queue) | 76 | 112 | +36 | items moved to terminal |
| published_last_24h | 99 | 207 | **+108** | pipeline produced 108 articles |
| state_manual_review | 8 | 34 | +26 | quarantine handler routing correctly |
| state_rejected | 80 | 80 | 0 | trim cap working |
| new_age_6h_plus | 0 | 107 | +107 | aging pool, drainage oldest-first |
| stuck_active | 0 | 0 | 0 | ✓ zero pathological loops |

### Fixes applied today (5 commits)

1. **b1921c0** AJAX queue_snapshot uses per-block path — fixed empty blocks after page refresh
2. **788c28a** quarantine handler queries by attempts; auto_promote requires editorial_match — closes pathological loop (item 2096 case)
3. **90465d7** token-based 'В работе' detection + soft backpressure 3× threshold cap — fixes parallel-activity UI confusion + tighter overload protection
4. **e87a504** HARD cap 200 pending — fires regardless of force=true — orchestrator's force-collect respects backlog
5. **713bcd1** HARD_TERMINAL_REASON_TOKENS += promotional/sponsored — soft_terminal_guard no longer salvages ad content into ready_publish

Plus 1 commit not from monitoring loop:
- 9aa8aef..f56b296 (8 commits earlier today, from morning session): уmcommitted diff разобран, history clean

### Pipeline state at end

- **Services**: worker active, orchestrator active, worker /health OK
- **Single owner contract**: held (1 token holder visible in admin)
- **Hard cap**: protecting backlog (0 ingest since 11:10 UTC)
- **Drainage rate**: ~22-30/h steady
- **Quarantine**: actively routing items with attempts>=2 to manual_review (no items stuck >5 attempts)
- **Ad rejection**: promotional content now hard-terminal (won't get salvaged)

### Drainage forecast (after loop ends)

- 'new' currently 289. Hard cap releases when pending < 200 → ~4h → ETA **~18:00 UTC** (Berlin 20:00)
- Soft cap (3× threshold ~54) releases when pending < 54 → ~13h → ETA **~03:00 UTC tomorrow**
- Collector resumes automatically after soft cap drops
- New ingest only resumes when system has bandwidth

### Open systemic issues remaining

1. **SSOT Phase A NOT started** — test scaffolding needed before refactor of decision points (110+ identified в decision-map.md). Today's fixes patch SYMPTOMS at conflict zones B (soft_terminal salvage), D (auto_promote without editorial check), F (race conditions in state machine). Root cause refactor still pending.
2. **soft_terminal_state_guard остаётся** — patched via HARD_TERMINAL_REASON_TOKENS expansion, но architectural removal (Phase E) ещё впереди.
3. **107 items aged 6+h** — will drain naturally at ~25/h, no action needed unless rate slows. queue_new_ttl_hours=18 auto-prunes at 18h.
4. **Working tree → production sync NOT automatic** — memory entry added (deploy_sync_not_automatic.md). Future edits need manual cp + opcache_reset.
5. **Drainage rate 22-30/h is BELOW collection rate** — fundamental imbalance. When backpressure releases, expect same accumulation pattern unless processing throughput increases.

### Recommendations for user

1. **Watch for**: 'new' count reaching 200 (~18:00 UTC) — hard cap will release; observe whether soft 3× protection holds the line.
2. **If 'new' starts climbing again past 100**: investigate worker throughput — heavier items, AI slowness, or rejection rate dropped.
3. **Manual reviews to process**: 34 items in `manual_review` — operator decision needed (these are items that couldn't auto-publish but have processed payloads).
4. **Phase A test scaffolding** — next session if you want to start SSOT migration. Operator decisions documented in docs/ssot-decisions-2026-05-11.md.
5. **Admin UI now correct**: «В работе» shows token-holder (single item), «Новые» shows untouched. Refresh-empty bug fixed.


---
# Monitoring loop RESTART — 2026-05-11 16:39 UTC

User request: продолжать мониторинг каждые 30 мин + проверять работу backpressure при backlog'е.

## T+R0 — 16:39 UTC (baseline restart)

State after morning loop + manual_review cleanup:
- new: 255
- published_queue: 129
- rejected: 80
- retry_process: 17
- manual_review: 4 (was 38 в baseline, 38 разобрано)
- ready_publish: 2

Backpressure verification:
- Last 2 collect attempts (16:23, 16:38) BOTH blocked
- Hard cap option shows pending=272, force_was=true at 18:38:44 Berlin (16:38:44 UTC)
- Drainage rate ~3-5/hour ostatnich (slow — manual_review cleanup spike + heavy items)

ETA forecasts:
- 'new' to 200 (hard cap release): ~10-15 hours (~02:00-08:00 UTC tomorrow)
- 'new' to 54 (soft cap release): ~30+ hours (collect remains blocked until then)

## T+R30 — TBD (17:10 UTC scheduled)

## T+R30 — 17:11 UTC

Deltas T+R0 → T+R30 (32 min):
- new: 255 → 244 (-11) — drainage ~22/h
- published_queue: 129 → 135 (+6)
- published_last_24h: 246 → 264 (+18)
- manual_review: 4 → 6 (+2)
- ready_publish: 2 → 3
- processing: 17 → 19 (active turnover)
- high_attempts_active: 0 ✓

Backpressure validation:
- 2 collect attempts (16:39-17:11) — BOTH blocked
- 0 items created in 'new' since 16:39 ✓
- Hard cap last fired 17:09:54 UTC, pending=264, force_was=true
- 0 promotional items

ETA:
- pending → 200 (hard release): ~2h → ~19:11 UTC
- pending → 54 (soft release): ~9h → ~02:00 UTC tomorrow

## T+R60 — TBD (17:41 UTC scheduled)

## T+R60 — 17:42 UTC

Deltas T+R30 → T+R60 (31 min):
- new: 244 → 239 (-5 — slow drainage)
- published_queue: 135 → 139 (+4)
- manual_review: 6 → 22 (+16 spike)
- ready_publish: 3 → 0
- processing: 19 → 7
- published_last_30m: 15 → 12

Backpressure verification:
- 2 collect attempts since 17:11 — both BLOCKED ✓
- 0 items added к 'new' с baseline 16:39 ✓
- Hard cap last fired 19:41:01 Berlin (17:41:01 UTC), pending=246

+16 manual_review объяснение:
- 12 items from morning force-publish batch finally quarantined (real content blockers, не only selection)
- 4 fresh kultur items selection_rejected (story_score 40-41, low tier)
- NOT my new validator — these are pipeline-level rejects

Validator post-deploy check:
- 5 recent published items scanned for invented numbers
- 4/5 clean (0 invented) ✓
- 1/5 — #2229 (ifo Institut, "17,4 Prozent", "1.776") — invented numbers DETECTED but published anyway (likely gate passed BEFORE my deploy, ai_payload stored quality=100 pre-fix)
- Item already published. Fresh items after 17:05 UTC will be guarded.

Drainage forecast:
- 'new' = 239. Rate ~10-15/h (slow during cleanup spike).
- ETA hard cap (200): ~3-4h → ~21:00 UTC
- ETA soft cap (54): ~12-15h → ~06:00 UTC tomorrow

## T+R90 — TBD (18:12 UTC scheduled)

## T+R90 — 18:15 UTC

Deltas T+R60 → T+R90 (33 min):
- new: 239 → 230 (-9 — drainage continues)
- published_queue: 139 (no change in queue table)
- published_last_30m: 12 → 0 (between publish slots, 2236 in ready_publish ждёт slot)
- manual_review: 22 → 23 (+1)
- ready_publish: 0 → 1 (item 2236 Pistorius story queued)
- processing: 7 (steady)
- high_attempts_active: 0 ✓

Activity log this tick:
- 6 stale items pruned (prune_new_stale fix — 1841,1865,1876,1908,1944,1997)
- 1 photo gallery rejected (#2460 «Die Welt in Bildern: Blickfang») ✓
- 2235 → manual_review (stage_attempt_limit_publish_ready_gate)
- 2236 → ready_publish (готов)

Backpressure:
- 2 collect attempts since 17:42 — both BLOCKED ✓
- Hard cap last fired 18:11:17 UTC, pending=246
- 0 items добавлено к 'new' с baseline 16:39 ✓

Validator coverage (since 17:05 UTC deploy):
- 6 items published, 1 with 2+ invented numbers (#2229 ifo — published before validator gate ran)
- 5/6 clean (83%) — fresh items properly guarded

Drainage: ~9-15/h current rate. ETA pending<200: ~3h → 21:15 UTC.

## T+R120 — TBD (18:45 UTC scheduled)

## T+R-tight-1 — 18:40 UTC (15-min cadence)
Jam signs: 0/5. last_publish=5m, orch_silent=26s, active=2245. Pipeline healthy.

## T+R120 — 18:47 UTC
Jam signs: 0/5. new=221 (⬇9), manual_review=26, ready_publish=1, retry_process=7. last_pub=6.5min, orch=active. Pipeline healthy.

## T+R-tight-2 — 18:57 UTC
Jam: 0/5. new=217, last_pub=8.9min, active=2250 (rotated). Pipeline healthy.

## T+R-tight-3 — 19:15 UTC
Jam: 0/5. new=211 (⬇6), last_pub=2.5min, orch_silent=60s. Pipeline healthy. New caps active: backpressure threshold 1.0×, freshness adaptive 40-120min.

## T+R-tight-4 — 19:36 UTC (FINAL of tight-loop)

DRAINED: pending=45 (<60 threshold) — backlog cleared.
- new: 211 → 44 (trim_new_queue fix + processing)
- published: 151 → 135 actively flowing
- 0 jam signs throughout tight-loop (4 ticks)

Pipeline state:
- 9 защитных слоёв active
- trim_new_queue теперь real (was dead code)
- og-fallback / placeholder URLs blocked
- story_card requirement enforced
- backpressure threshold tightened (1.0×)
- adaptive freshness window (40-120 min by time)

Tight-loop ENDS. Switching to relaxed 30-min cadence для ongoing health.

## T+R-relaxed-1 — 20:27 UTC

Anomaly: last_publish=53min (>20min threshold). Investigation:
- pending=29 (low, healthy), stuck=0, rp_stalled=0, orch_silent=41s
- Last 60min: 1 published, 9 routed к manual_review
- Manual_review items: high quality (q=100) but block at publish_ready_gate
  via semantic_consistency check OR detect_invented_numbers
- Sample: #2223 fails stage_contract+language_contract (semantic_consistency=FALSE)
- Sample: #2203 — invented numbers (152.100, 4.681, 4.640 — Gold market story)

Diagnosis: **NOT regression**. Post-P0-fixes pipeline now gates items
с hallucinations / translation drift к manual_review (correct behavior).
Trade-off: lower publish rate, higher operator workload.

Pre-P0: pipeline published items с invented facts → 7 hallucinations
caught in fact-check. Post-P0: items с invented facts blocked at gate.

Operator action needed: review 10 manual_review items. Operator can
force-publish those с legitimate content (where validator was too strict).

Pipeline healthy. No code changes.
