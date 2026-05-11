# EuroPulse Autopilot v21 — Single-Source-of-Truth Design

**Status:** Phase 2 deliverable. Authoritative redesign — one decision-authority per class, explicit state machine, migration plan.
**Created:** 2026-05-11
**Depends on:** [architecture-2026-05-11-decision-map.md](./architecture-2026-05-11-decision-map.md)

---

## 1. ПРИНЦИПЫ

### Принцип 1: Story Card = Source of Truth для семантики

Story Card AI builds ONE TIME per item на ingest. После этого:
- Категория: `story_card.category.primary` при confidence≥0.6, иначе heuristic. **Никогда не override после**.
- Editorial verdict: `editorial_match` ∈ {match, borderline, reject_low_value} — final, не пересматривается.
- Kind: `story_card.kind` — final, PHP `detect_kind()` только маппит на KIND_SPECS slot.
- Publishable estimate: `story_card.publishable_estimate` — final.

**Если Story Card не построен (ошибка worker'а) — heuristic берёт верх. Но как только Card построен, он immutable для всех downstream stages.**

### Принцип 2: Heuristic = только pre-AI filter

`EPV2_Budget_Manager::analyze_item()` запускается **ровно один раз** на ingest:
- Hard-reject patterns (stale, hard_pattern, sport_fixture, community_promo, noise, routine_official)
- Initial score+decision (для quick filter перед AI bills)

После того как Story Card построен, **повторных budget_manager calls нет**. Никаких post-card re-analyze. Никаких category overrides через budget_manager.

### Принцип 3: Один publish-gate

Текущие 9 sub-checks → **1 explicit contract**:
```
PublishGate.allowed(item) →
    is_terminal: NO (not rejected/error/duplicate)
    has_post_data: YES (DE+UK+EN titles, ≥220 chars each)
    has_seo: YES (title, desc, slug, keywords; ≥1 of 4 streams ≥100)
    has_media: YES (NOT Wikimedia/Pexels; valid+usable OR source-host)
    AI endorsed: editorial_match=match AND publishable_estimate ∈ {high,medium}
    publish_slot: NOW ≥ publish_not_before
```

**Without AI endorsement**: same checks but quality threshold 100 strict.
**With AI endorsement**: quality threshold relaxed to 70.

Никаких других gates.

### Принцип 4: State machine — explicit transition table

```
new        → processing_de (orchestrator claim)
            → rejected (selection.decision=reject + hard reason)
            → manual_review (selection.decision=reject + salvageable)

processing_de → ready_publish (gate passes)
              → retry_process (transient worker fail)
              → manual_review (gate fails non-recoverable + salvageable)
              → rejected (hard reason)
              → error (resilience exhausted)

retry_process → processing_de (retry tick)
              → manual_review (retry exhausted + salvageable)
              → rejected (retry exhausted + hard reason)

ready_publish → publishing (publisher claim, slot OK)
              → manual_review (5min+ stuck without progress)

publishing → published (all 3 langs OK)
           → ready_publish (publish_item failed transient)
           → manual_review (publish_item failed non-recoverable, post_id=NULL >8min)

manual_review → new (operator reset OR auto_promote_count<2 + completed payload)
              → published (operator manual publish via admin)
              → rejected (operator manual reject)

ready_review = alias for manual_review (deprecate)

published → (immutable)
rejected → (immutable, may auto-reset via watchdog if legacy reason)
duplicate → (immutable)
error → (immutable)
```

**Запрещённые transitions** explicit:
- new → published (without processing_de)
- ready_publish → new (без явного operator action)
- processing_de → published (без publishing window)

### Принцип 5: Workflow token — single-writer guarantee

- ONLY orchestrator может set `workflow_owner_token` + `epv2_active_automation_item`
- Maintenance handlers: NEVER touch row with non-empty workflow_owner_token UNLESS heartbeat stale >15min
- Token TTL = job_lock_ttl_seconds; stale_after = ttl/3
- Watchdog releases stale tokens; orchestrator не должен re-claim того же item в течение 60 сек после release

---

## 2. AUTHORITY HIERARCHY PER DECISION CLASS

### Selection / Reject

```
INGEST (one heuristic pass):
    Budget_Manager.analyze_item → decision ∈ {priority, strong, review, low, reject}
    If decision = 'reject' AND reject_class ∈ HARD_TERMINAL_REASONS → mark_state(rejected) terminal
    If decision ∈ {low, reject} AND reject_class = soft (low_score) →
        Story_Card.build() → editorial_match
        If editorial_match=match AND publishable_estimate∈{high,medium} → upgrade to 'review'
        Else → mark_state(rejected) with soft_terminal_guard salvage check

AI PROCESSING (no re-analyze):
    Story Card.editorial_match='reject_low_value' → mark_state(rejected)
    Else proceed
    
    NO post-card budget_manager re-analyze. NO decision drift.

REBUILD / RETRY:
    Worker output may have its own selection.decision — BUT it does NOT override
    the original ingest decision unless editorial_match='reject_low_value'.
```

**Single source: ingest analyze + story_card.editorial_match.** Everything else reads, не пишет.

### Category

```
SOURCE OF TRUTH: payload._meta.story_card.category.primary (when confidence≥0.6)
FALLBACK: keyword heuristic + geography guard

WRITERS (in order, last-writer-wins):
    1. Ingest bias → category_proposed
    2. Story_Card.build → story_card.category.primary (final)
    3. resolve_for_payload at AI processor → reads story_card, writes payload.categories[0]

CONSUMERS (read-only):
    - publisher → wp_set_post_terms
    - admin UI → category_final display
    - importance_score → per-category weights
    - budget_manager.decision_for_score → per-category threshold

NO MORE: refine_with_event_context overriding after story_card.
NO MORE: post-card re-analyze with different category.
```

### Kind

```
SOURCE OF TRUTH: payload._meta.story_card.kind (AI-emitted)
PHP detect_kind = MAPPER, not deciders

MAPPING TABLE (story_card.kind → KIND_SPECS slot):
    news → news_brief (if <300 chars) | news_article | extended_news (≥3 sources)
    live_ticker → breaking_alert (if fresh, <80 words) | live_blog
    analysis → analysis
    feature → feature
    opinion → opinion
    community_event → news_article (new explicit mapping)
    service_announcement → news_brief (new explicit mapping)
    (no match) → news_brief (default)

NO MORE: detect_kind re-deriving from payload metrics that contradict story_card.kind.
```

### Editorial verdict

```
SOURCE OF TRUTH: story_card.editorial_match AND story_card.publishable_estimate

PROPAGATION:
    Pipeline: editorial_match=reject_low_value → mark_state(rejected) immediate
    Pipeline: editorial_match=match + publishable_estimate=high → bypass quality strict (78→70)
    Rewriter prompt: receives editorial_match + reason for tone
    Translator prompt: receives editorial_match + reason for invariance
    Importance_score: editorial_match=match → +10, borderline → -15, reject_low_value → -30
    Soft_terminal_guard: publishable_estimate ∈ {high, medium} → salvageable

NO duplicate consumers. NO override.
```

### Media

```
RESOLVER (EPV2_Media::resolve_featured_media):
    Priority hierarchy:
    1. source_dossier_image
    2. existing_url candidates (source-host preferred)
    3. parsed_supporting_image
    4. context_supporting_image
    5. NULL (item → manual_review for human curation)

NO MORE: wikimedia/pexels fallback. Resolver returns '' instead.
Operator preference (Phase 11): off-topic stock хуже чем no-publish.

PUBLISH GATE MEDIA CHECK:
    media_url empty → block (manual_review)
    media_url ∈ blocked list → block
    media_url host = wikimedia/pexels → block (never)
    media_url passes validate_featured_media → pass
    media_url ∈ source_dossier hosts → pass (source-host fallback)
    Else → block (manual_review)
```

### Quality

```
SOURCE OF TRUTH: 4 streams in payload._meta:
    - quality.score (editorial)
    - release_quality.score
    - google_quality.score
    - seo_quality.score

UNIFIED THRESHOLD (post-Phase 2):
    Default: all 4 streams ≥100 AND zero warnings
    AI endorsement (editorial_match=match + publishable_estimate∈{high,medium} + confidence≥0.7):
        - Lower bound 70 for ALL 4 streams (not just de_master_viable)
        - Same "no warnings" requirement
    
    Quality oscillation guard: if quality.score in [98, 99] AND no_warnings → round up to 100.

NO MORE: different thresholds for de_master_viable vs publish_gate. One contract.
```

### Publish-gate

```
SINGLE ENTRY: EPV2_Publish_Gate::evaluate($item, $payload) → ['allowed' => bool, 'blockers' => array]

Checks (parallel evaluation, all collected before return):
    1. terminal_state — item NOT in [rejected, error, duplicate, published]
    2. post_data_ready — DE/UK/EN titles + content ≥220 chars + excerpts
    3. seo_ready — at least 1 stream ≥ threshold AND title/desc/slug/keywords present
    4. media_ready — valid URL, not generic stock, passes validate_featured_media
    5. ai_endorsement — editorial_match check, sets quality threshold dynamically
    6. quality_ready — all 4 streams ≥ dynamic threshold + no warnings
    7. publish_slot — workflow_not_before ≤ NOW AND publish_minutes window allows
    8. duplicate_check — final story_card event-signature dedup
    9. category_taxonomy — all 3 language categories resolve to valid WP terms

NO MORE: nested sub-gates with implicit dependencies. One flat check.
```

### State machine

```
SINGLE ENTRY: EPV2_State_Machine::transition($id, $from, $to, $context) → bool

Validates:
    - $from matches current row.state (CAS protection)
    - ($from, $to) in ALLOWED_TRANSITIONS table
    - workflow_owner_token check (if non-empty, only owner can transition)
    - Hard terminal reasons block soft state changes (rejected stays rejected unless legacy reset)

Replaces: EPV2_Queue::mark_state (becomes thin wrapper around State_Machine)

ALLOWED_TRANSITIONS = [
    'new' => ['processing_de', 'rejected', 'manual_review'],
    'processing_de' => ['ready_publish', 'retry_process', 'manual_review', 'rejected', 'error'],
    'retry_process' => ['processing_de', 'manual_review', 'rejected', 'new'],
    'ready_publish' => ['publishing', 'manual_review', 'retry_publish'],
    'retry_publish' => ['publishing', 'ready_publish', 'manual_review'],
    'publishing' => ['published', 'ready_publish', 'manual_review'],
    'manual_review' => ['new', 'rejected', 'published'],  // last via admin
    'published' => [],  // terminal
    'rejected' => ['new'],  // only via watchdog auto_reset_legacy
    'error' => ['new'],  // only via manual recovery
    'duplicate' => [],
    // 'ready_review' deprecated alias for manual_review
];
```

---

## 3. MIGRATION PLAN (5 phases, behind feature flag)

### Phase A: Test scaffolding (1 session, ~6 hours)

Create `/root/projects/europulse/tests/`:
- `fixtures/` — 25 synthetic items covering each edge case
- `pipeline_contracts.php` — assertion library (`assert_state`, `assert_payload_invariant`, `assert_authority`)
- `state_machine_test.php` — exercise each ALLOWED_TRANSITIONS row
- `selection_test.php` — verify single-pass heuristic
- `category_authority_test.php` — verify story_card primacy
- `publish_gate_test.php` — verify single gate contract

Tests **run without worker** (mock story_card responses).

Acceptance: all 25 fixtures route correctly with current code (baseline before any change).

### Phase B: SSOT helper introduction (1 session)

Add new code WITHOUT removing old:

- `EPV2_State_Machine::transition()` — new explicit transitions table
- `EPV2_Publish_Gate::evaluate()` — new single gate
- `EPV2_Authority::story_card_or_fallback()` — single read-helper for category/kind/editorial

Old `mark_state`, old publish_ready_gate_*, old detect_kind continue to work.

Acceptance: new helpers behave identically to old paths on all 25 fixtures.

### Phase C: Feature-flag dual run (2 sessions, ~12 hours)

Add `epv2_authority_v2` option (default off).

When ON:
- `mark_state` → forwards to `EPV2_State_Machine::transition`
- `publish_ready_gate_passes` → calls `EPV2_Publish_Gate::evaluate`
- `EPV2_Categorizer::resolve_for_payload` → calls `EPV2_Authority::story_card_or_fallback`
- `EPV2_Content_Kinds::detect_kind` → reads `story_card.kind` first, fallback to heuristic

When OFF: existing code path runs unchanged.

**Dual-path comparison logging**: для каждого decision, compare old vs new. Log mismatches в `_log` table. Operator manually reviews mismatches.

Acceptance: <1% mismatch rate over 48 hours of live traffic with flag ON in shadow mode (new path runs but old path's decision wins).

### Phase D: Switch authority (2 sessions, ~10 hours)

Flag enters "authoritative" mode:
- New path's decision wins
- Old path still runs for comparison
- Log every divergence для operator review

Specifically remove sources of conflict:
1. **Remove post-card heuristic re-analyze** (ai-processor.php line 309) — Phase 30 partial → final removal
2. **Remove redundant category override** in `refine_with_event_context` after story_card already set
3. **Unify quality threshold** — kill the 78 vs 100 split, use single dynamic threshold from authority
4. **Consolidate maintenance handlers** — order them explicitly: cleanup → promote → sanitize → quarantine. Each handler checks state machine guard before write.

Acceptance: 7 days of operation. <0.5% items requiring manual_review due to pipeline conflict (vs 5-10% baseline).

### Phase E: Cleanup (1 session)

Remove old code paths.
Mark `ready_review` state как deprecated alias for `manual_review`.
Update memory documents:
- `STATE_MACHINE.md` (single canonical doc)
- `AUTHORITY.md` (decision hierarchy per class)
- Remove conflicting feedback files в auto-memory.

---

## 4. FEATURE FLAG STRATEGY

```
epv2_authority_v2 ∈ {off, shadow, authoritative}

off (default): No new code runs. Safe rollback.
shadow: New code runs, compare to old, log mismatches. Old decision wins.
authoritative: New decision wins. Old still runs (для comparison). 7-day burn-in.

Single setting in epv2_settings — operator controls via admin.
Switch back to 'off' instantly if anything breaks.
```

---

## 5. WHAT GETS DELETED (after Phase E)

| File | Lines | Reason |
|------|-------|--------|
| ai-processor.php:303-360 | Post-card heuristic re-analyze | Story Card primacy enforced |
| ai-processor.php:6608-6627 | de_master_is_viable (78 threshold) | Unified single quality contract |
| ai-processor.php:7216-7224 | de_master_is_viable_fast (78 threshold) | Same |
| budget_manager.php:316 | refine_with_event_context in analyze_contextual | event override removed |
| queue.php:soft_terminal_state_guard | Salvageability check | Replaced by explicit state transitions table |
| queue.php:canonicalize_single_workflow_state | Implicit normalization | Replaced by explicit transitions |
| categorizer.php:refine_with_event_context | Called multiple places | Consolidate into resolve_for_payload |
| content-kinds.php:detect_kind metric recomputation | Heuristic re-derivation | story_card.kind → mapper only |

**Net: ~800 lines removed, ~400 lines added (new helpers). ~50% reduction in decision points.**

---

## 6. RISK ASSESSMENT

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Flag mode transition breaks live pipeline | Low | High | Shadow mode burn-in 48h before authoritative |
| Mismatch rate >5% in shadow mode | Medium | Medium | Investigate each divergence; either fix new logic or accept (document why) |
| Story Card AI returns malformed JSON edge cases | Medium | Low | New code falls back to heuristic when card invalid (same as current) |
| Maintenance handler ordering causes regression | Medium | Medium | Explicit ordering table + integration tests |
| Operator confused by transition during shadow → authoritative | Low | Low | Single setting in admin, clear UI labels |

---

## 7. WHAT'S NOT IN SCOPE

- Worker (Python) refactoring — stays as is for now
- Editorial-calibration.md rule sync — Phase 25 covered, stays separate from this refactor
- RSS source / dedup tuning — Phase 29 covered
- Admin UI redesign — only minimal updates (state labels for new transitions)
- Performance optimization (N+1 queries) — separate task; this refactor doesn't make it worse

---

## 8. SUCCESS METRICS

После полной миграции (Phase E):

1. **Pipeline conflict rate** (items routing through 2+ decision points that disagree): from ~15% → <2%.
2. **Manual review backlog**: from 5-10/day → <2/day (только genuine editorial decisions).
3. **Items per AI rebuild cycle**: from 1.5 (re-analyze adds 0.5) → 1.0 (single pass).
4. **AI cost reduction**: ~20-30% (no redundant re-analyze, no oscillation rebuilds).
5. **Mean time to publish** (ingest → published): from ~40-60 min → 15-25 min.
6. **Visual flicker reports**: 0 (state transitions explicit, admin sections consistent).

---

## 9. DECISION POINTS FOR OPERATOR

Перед началом Phase A, нужно подтвердить:

1. **Quality threshold preferences**: keep 100 strict в publish_gate, OR allow consistent relaxation 70 with AI endorsement everywhere?
2. **Editorial recovery policy**: rejected items → ready_review via salvageability (current) OR strict rejected unless operator manual recovers (new)?
3. **Wikimedia/Pexels policy**: strict block (current Phase 11) или conditional allow (если story_card.media_required=named_entity)?
4. **State `ready_review` deprecation**: rename все existing `ready_review` rows to `manual_review`? Or keep alias forever?
5. **Maintenance ordering**: should `auto_promote_complete_manual_review` run BEFORE or AFTER `sanitize_stuck_publishing`? (Affects rescue priority.)
6. **AI cost ceiling**: какой acceptable cost increase в shadow mode (running both paths) — 2x acceptable for 48h, or harder cap?

Эти 6 вопросов нужны от user для finalize design. Без них Phase B/C/D могут разойтись с editorial intent.

---

## 10. TIME ESTIMATE

| Phase | Sessions | Hours per session | Total |
|-------|----------|-------------------|-------|
| A. Tests | 1 | 6 | 6h |
| B. SSOT helpers | 1 | 6 | 6h |
| C. Dual-run shadow | 2 | 6 | 12h |
| D. Authority switch | 2 | 5 | 10h |
| E. Cleanup | 1 | 4 | 4h |
| **TOTAL** | **7** | — | **38h** |

Plus 48-72h live shadow burn-in (passive, no manual work).

**Real elapsed time: ~10-14 days** if 1 session per day.

---

**Этот документ — план. Не код. Запуск Phase A — следующее concrete действие.**
