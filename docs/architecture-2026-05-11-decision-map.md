# EuroPulse Autopilot v21 — Карта Decision-Points

**Status:** Phase 1 deliverable. Полная карта точек принятия решения в pipeline.
**Created:** 2026-05-11
**Source:** 4 parallel sub-agent audits (selection, category/kind/editorial, media/quality/gate, state-machine/maintenance).

---

## 1. Краткая статистика

| Класс решений | Точек | Конфликтных зон | Recovery paths |
|--------------|-------|-----------------|----------------|
| **Selection / Reject** | 23 | 4 | 3 |
| **Category** | 15 | 2 | — |
| **Kind** | 10 | 2 | — |
| **Editorial verdict** | 8 | 2 | 1 |
| **Media** | 7 (steps) + 5 gate-checks | 2 | 1 (circuit-breaker) |
| **Quality (4 streams)** | 4 parallel + relaxation | 1 (hardcoded 100 vs AI endorsement 70) | 1 |
| **Publish-gate** | 9 sub-checks | — | circuit-breaker |
| **State transitions** | 30+ | 4 race conditions | 5 maintenance recovery handlers |
| **Maintenance handlers** | 14 | 8 race scenarios | — |

**Итого: ~110+ независимых точек принятия решения. Pipeline эволюционировал слоями — каждый слой добавлял свою точку без удаления предыдущих.**

---

## 2. CLASS 1 — Selection / Reject (23 точки)

### Heuristic reject paths (budget_manager)

| # | Trigger | reject_class | Hard/Soft |
|---|---------|--------------|-----------|
| 1 | stale_news_block | stale | Hard |
| 2 | looks_like_hard_reject (regex) | hard_pattern | Hard |
| 3 | sport_live_fixture_block | sport_fixture_livepage | Hard |
| 4 | telegram_community_promo_block | community_promo | Hard |
| 5 | looks_like_noise | noise | Hard |
| 6 | routine_official (official URL + low impact) | routine_official | Hard |
| 7 | score < threshold (tier=D) | low_score | **Soft** |

### AI reject paths (story_card)

| # | Trigger | Path |
|---|---------|------|
| 8 | duplicate_precheck (hash) | ingest drop |
| 9 | editorial_match='reject_low_value' on ingest | mark_state('rejected') |
| 10 | editorial_match='reject_low_value' on AI pickup | mark_state('rejected') |
| 11 | publishable_estimate='reject' | mark_state('rejected') |
| 12 | is_event_duplicate (story_card signature) | mark_state('duplicate') |

### Re-evaluation reject paths

| # | Trigger | Where |
|---|---------|-------|
| 13 | force_reject_zombie_pre_ai_rejects (maintenance cleanup) | queue.php:1354 |
| 14 | enforce_new_item_selection_gating (sanitize_non_publish_grade) | queue.php:952 |
| 15 | sanitize_low_grade_ready_publish_items | queue.php:1432 |
| 16 | Post-card heuristic re-analyze decision='low'/'reject' | ai-processor.php:309 |
| 17 | Rebuild phase fresh decision='low'/'reject' | ai-processor.php:1116 |
| 18 | Rebuild final phase | ai-processor.php:3925 |

### Recovery paths (rejected→ready_review/manual_review)

| # | Path | Trigger |
|---|------|---------|
| 19 | **soft_terminal_state_guard** | salvageable: ingest_score≥30 OR card.publishable_estimate∈{high,medium} OR key_facts≥3 |
| 20 | reactivate_planner_soft_rejected_items | planner action ∈ {select,replace} + score≥30 + reject_class∈{'',low_score} |
| 21 | User manual override (admin UI Publish Now) | manual_override=true, salvageability skipped |

### Decision values in circulation

```
priority > strong > review > low > reject

Set in: analyze_item (heuristic) + collector.story_card_upvote + AI processor rebuild
Read in: 13+ locations (row_has_non_publish_grade, publish_gate, sanitize handlers, soft_terminal_guard)
```

### CONFLICT ZONE A — Heuristic↔AI Re-evaluation Loop

Item can pass through:
1. Heuristic ingest: `decision='strong'` → queue
2. AI worker pickup: `editorial_match`, `publishable_estimate` from story_card
3. **Post-card heuristic re-analyze**: budget_manager re-runs with story_card.category override → `decision='low'` (different rubric thresholds!)
4. mark_state('rejected') → **soft_terminal_state_guard** → 'ready_review'

**Это конфликт #1**, явившийся причиной #2082-2084 cycle. Phase 30 partially закрыл (skip re-analyze if AI endorsed), но точка осталась.

---

## 3. CLASS 2 — Category (15 точек)

```
INGEST BIAS (1) → category_proposed (queue column)
    ↓
CATEGORIZER.detect() (2) — keyword scoring
    ↓
RESOLVE_FOR_PAYLOAD (3) — Story Card primacy ≥0.6 OR keyword + geography guard
    ↓
REFINE_WITH_EVENT_CONTEXT (4) — dossier event override (sport/kultur/community)
    ↓
REFINE_WITH_STORY_CARD (5) — post-heuristic Story Card override
    ↓
WP TAXONOMY (6) — wp_set_post_terms per language (Polylang)
```

### Drift zones

- **Zone B1**: Story Card confidence 0.5 (subтрешхолд) → keyword+event refinement выписывает категорию, но low-confidence card остаётся в payload → следующий tick может override обратно.
- **Zone B2**: category_proposed (ingest bias) → category_final (publish) — может расходиться **3 раза** по pipeline (resolve → refine_event → refine_card).

---

## 4. CLASS 3 — Kind (10 точек)

**Two parallel taxonomies:**

| Worker (`story_card.py`) | PHP (`content-kinds.php`) |
|-------------------------|---------------------------|
| news | news_brief / news_article |
| live_ticker | breaking_alert OR live_blog |
| analysis | analysis ✓ |
| feature | feature ✓ |
| opinion | opinion ✓ |
| community_event | **NO MAPPING** → defaults to news_article |
| service_announcement | **NO MAPPING** → defaults to news_brief |

### Drift zone B3

`detect_kind()` (PHP) read story_card.kind но также re-derives from payload metrics (length, source count, topics). Если payload metrics не совпадают с card.kind expectations (e.g., card='feature' but payload only 300 chars) → разные kind'ы → разные quality thresholds → разные rewriter prompts.

---

## 5. CLASS 4 — Editorial verdict (8 точек)

**Source of truth: `story_card.editorial_match` + `story_card.publishable_estimate`**

Read in:
1. Collector ingest (hard gate `reject_low_value`)
2. Collector story_card_upvote (match+high overrides decision=low)
3. AI processor on pickup (`reject_low_value` → reject)
4. AI processor rebuild (re-check)
5. **Post-card heuristic re-analyze** (Phase 30 fixed: skip if match+high)
6. importance_score (match=+10, borderline=-15, reject_low_value=-30)
7. soft_terminal_state_guard (salvageability check)
8. Rewriter prompt + Translator prompt (tone hints)

### Drift zone B4

`editorial_match` may say 'borderline' but `publishable_estimate='reject'` — these don't have to align. Different prompt instructions for each. Editorial = "fits rubric?", Estimate = "publish-quality?". They can contradict.

---

## 6. CLASS 5 — Media (7 steps) + Publish-gate (9 sub-checks)

### Media resolution tree

```
1. source_dossier_image (highest priority)
2. existing_url candidates (source-host filtering)
3. parsed_supporting_image (supporting sources)
4. context_supporting_image (semantic context)
5. retry existing_url
6. stock_fallback (wikimedia/pexels) — RESOLVER passes them through
7. last_resort wikimedia/pexels query — RESOLVER returns them
```

**PUBLISH-GATE HARD BLOCK (Phase 11):** Wikimedia/Pexels URLs **никогда не проходят** `publish_ready_gate_media_contract_passes()` независимо от story_card.media_required → items без real photo идут в manual_review.

### 9-Step Publish-Gate Sequence (any fail → block)

1. blockers empty
2. selection NOT blocked
3. context NOT reject
4. NOT stale_context_signal
5. stage_contract (pipeline_stage = ready_publish/publish_finish)
6. language_contract (DE+UK+EN ≥220 chars)
7. seo_contract (seo_quality≥100 + title + desc + slug + keywords)
8. **media_contract** (NOT empty, NOT blocked, NOT generic_stock, IS publishable OR source-host)
9. **integrity_contract** (quality≥100 + release_quality≥100 + google_quality≥100 + substance)

### Quality threshold dual-system

| Gate | Threshold | Relaxation |
|------|-----------|------------|
| publish_ready_gate (final) | **100** | none |
| de_master_is_viable | 78 → **70** (if AI endorsed match+high/medium) | Phase 4 |
| translator_ready_for | 78 → **70** (same relaxation) | Phase 4 |

### Stuck publish patterns

- **Pattern A:** Same media-blocker 3x → circuit_breaker → manual_review (Phase 16)
- **Pattern B:** Quality 99.5 rounds to 99, gate requires ≥100 → stuck oscillation
- **Pattern C:** Media validates in process but fails relevance check in gate → ready_publish↔retry_process loop

---

## 7. CLASS 6 — State machine (13 states, 30+ transitions, 14 maintenance handlers)

### States

`new` · `reserve` · `processing_de` · `retry_process` · `ready_publish` · `retry_publish` · `publishing` · `published` · `ready_review` · `manual_review` · `rejected` · `error` · `duplicate`

### Workflow tokens

- `workflow_owner_token` (in admin_notes._system) — set on claim, cleared on terminal
- `epv2_active_automation_item` (option) — single-owner pointer
- `workflow_heartbeat_at` — TTL stale detection
- `workflow_not_before` / `retry_after` — defer
- `auto_promote_count` (Phase 16) — loop terminator
- `publish_blocker_breaker.count` (Phase 16) — 3-strike circuit

### 14 Maintenance handlers (bridge_maintenance every 5 min)

1. promote_live_published_rows — has WP posts → published
2. reactivate_media_recoverable_rows — clear retry_after if media found
3. sanitize_non_publish_grade_new_items — kill decision=low/reject in new
4. sanitize_low_grade_ready_publish_items — quality below threshold
5. quarantine_pathological_workflow_loops — workflow_step_attempts > limit
6. promote_ready_like_rows — payload ready → ready_publish
7. auto_route_misclassified_new_items — payload ready / manual_required
8. sanitize_stuck_ready_publish_items — 5min+ in ready_publish without post_id
9. sanitize_stuck_publishing_items (Phase 26) — 8min+ stuck publishing
10. sanitize_stuck_processing_de_items (Phase 28) — 30min+ processing_de orphan
11. auto_promote_complete_manual_review_items (Phase 16) — completed payload → retry_process (max 2 promotes)
12. trim_old_terminal_items — keep 80 newest per terminal state
13. force_reject_zombie_pre_ai_rejects — cleanup re-resurrected pre-AI rejects
14. Watchdog: release_stuck_active_item, repair_polylang_links, dedupe_published_posts, auto_reset_legacy_quarantine

### Race conditions (4 critical)

1. **Watchdog release vs orchestrator claim** — Phase 20 CAS guard added
2. **Maintenance vs orchestrator state-write** — Phase 15 guards added
3. **Sanitize_stuck_publishing vs publisher** — Phase 15 race window check
4. **Concurrent mark_state from two paths** — single writer assumption could break under high load

### Cleanup gaps (items stuck forever scenarios)

1. processing_de without active_pointer (covered Phase 28, 30min)
2. publishing without post_id (covered Phase 26, 8min)
3. ready_publish stuck on quality oscillation (`sanitize_stuck_ready_publish` 5min)
4. manual_review with completed payload (covered, but cap=30/run)
5. Dangling workflow_owner_token without option pointer — only cleared via `active_owner_pointer_item` discovery

---

## 8. ИТОГОВЫЕ КОНФЛИКТНЫЕ ЗОНЫ (приоритет)

| Зона | Conflict | Severity | Status |
|------|---------|----------|--------|
| **A: Heuristic↔AI re-evaluate** | budget_manager runs дважды (ingest + post-card) | **HIGH** | Phase 30 partially fixed (skip if match+high) |
| **B: Category drift** | 3 sequential overrides (resolve → event → card) | **MEDIUM** | Mostly fixed in resolve_for_payload, но event refinement может drift'ать |
| **C: Kind dual taxonomy** | Worker emits kinds NOT mapped to PHP KIND_SPECS | **MEDIUM** | Phase 18 added community_event/service_announcement mappings |
| **D: Editorial verdict propagation** | editorial_match vs publishable_estimate могут расходиться | **LOW** | Both read where needed; no single authority |
| **E: Quality dual threshold** | 78 vs 100 (relaxation 70 only для de_master_viable, not для publish_gate) | **MEDIUM** | Phase 4 partial: de_master only, publish_gate stays 100 |
| **F: State race conditions** | Multiple writers per row | **MEDIUM** | Phase 15, 20 added guards |
| **G: Maintenance handler overlap** | 14 handlers per 5-min tick — некоторые могут конфликтовать | **LOW** | Each guard'ит token check, но порядок exec'ии важен |
| **H: Stuck cleanup gaps** | 5 scenarios where item остаётся в неконсистентном state | **MEDIUM** | Phase 26, 28 закрыли 2 главных |

---

## 9. КЛЮЧЕВЫЕ ВЫВОДЫ

1. **Pipeline эволюционировал слоями**. Каждый bug-fix добавлял новую точку решения вместо удаления конфликтующей. За полгода накопилось 110+ точек.

2. **Heuristic budget_manager выполняется ≥2 раз на item** (ingest + post-card re-analyze) — главный источник конфликтов.

3. **Story Card AI задумано как single source of truth**, но post-card heuristic re-analyze может его переопределять. Это уже частично закрыто (Phase 30), но архитектурно проблема остаётся.

4. **Quality threshold 100 в publish_gate** — слишком жёсткий, особенно когда worker возвращает 99. Создаёт oscillation patterns.

5. **State machine не имеет explicit transition table** — каждый mark_state caller сам решает target. Race conditions неизбежны.

6. **Recovery via soft_terminal_state_guard** работает, но создаёт обратный поток (rejected→ready_review), который дальше может снова падать в rejected на следующем tick.

7. **Maintenance handlers не имеют strict ordering guarantee** — 14 handlers за 5-min tick могут друг друга override'ить.

---

**Этот документ — точка отсчёта для Phase 2 (Design SSOT). Он показывает что есть. Phase 2 — что должно быть.**
