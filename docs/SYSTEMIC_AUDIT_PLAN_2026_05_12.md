# EuroPulse Autopilot — Systemic Audit Plan
**Created:** 2026-05-12 (late evening Berlin)
**Authored by:** Claude main thread + 4 parallel reviewer agents (content, architecture, worker/prompts, categorizer/UX)

## Context

Operator complaint: "одно лечишь — другое калечишь". Day of 20+ point-fixes
revealed systemic issues that need coordinated work, not more patches.

4 parallel auditors investigated. Findings consolidated below.

**Operator-set constraints:**
- AI provider remains gpt-4o-mini (primary) + DeepSeek (fallback) for now.
  No model switch until system stable.
- Tune prompts and conditions FIRST. Switch to gpt-5-mini later.
- Goal: 40-60 quality published items/day during 06:00-00:00 Berlin window.
- Quality over quantity.

## Problem Catalog

### 🔴 CRITICAL — Content quality (user-visible)

| ID | Problem | Root cause file |
|---|---|---|
| C1 | DeepSeek runs instead of Gemini — admin says "Gemini" but worker has no Gemini impl, falls to DeepSeek for UK (broken Ukrainian). | `worker-v21/src/epv2_worker/pipeline.py:60-84` |
| C2 | gpt-4o-mini produces broken UA grammar (`закликалий`, `Манюела` — 4 non-words in post 10203). | `worker-v21/src/epv2_worker/translator.py:215` |
| C3 | Fabricated proper nouns — ESC item invented Linda Lampenius, Pete Parkkonen, Liekinheitin, Noam Bettan, Michelle. Detect_invented_numbers catches digits, names slip through. | `worker-v21/src/epv2_worker/rewriter.py` — no proper-noun cross-check |
| C4 | UA grammar validator missing. _ukrainian_style_warnings catches only bureaucratese, lets invalid endings (-ій/-сє/-осє) through. | `worker-v21/src/epv2_worker/translator.py:784-837` |
| C5 | Lost news hook — Schwesig piece dropped actual news (€1000 Entlastungsprämie vote) for vague "Entscheidungen überdenken". | `worker-v21/src/epv2_worker/prompts/rubrics.py` politik rubric |
| C6 | Lost lead in UK translations — Jermak UK starts "Це розслідування…" with no antecedent (first DE paragraph dropped). | `translator.py` aggressive paragraph filter |
| C7 | FC Bayern → Байєр Мюнхен — brand-name confusion (Bayer ≠ Bayern). | `translator.py:510-531` `_normalize_ukrainian_names` (only 6 names) |
| C8 | DE→UA name transliteration drift — Manuela → Манюела (should be Мануела); Schwesig/Faeser/Lang/Klingbeil not whitelisted. | same dict |

### 🟠 HIGH — Categorizer overrides story_card

| ID | Problem | Root cause file:line |
|---|---|---|
| K1 | `refine_with_event_context` overrides story_card even at confidence ≥0.6 | `categorizer.php:80, 692-699` |
| K2 | `looks_like_sport_context` catches "halbfinale" → ESC routed as Sport | `categorizer.php:784` |
| K3 | `normalize_categories array_slice(0,1)` kills multi-category — drops subcategories | `publisher.php:665` |

### 🟡 MEDIUM — Architecture (single-threaded bottleneck)

| ID | Problem | Impact |
|---|---|---|
| A1 | Selector `ORDER BY created_at ASC` favors zombies; LIMIT-25 polluted by old items | Fresh items wait 100+ min |
| A2 | `soft_terminal_state_guard` silently rewrites state — callers think rejected, item goes to ready_review → recycle | 32-run zombies (e.g. item 2530) |
| A3 | `epv2_active_automation_item` option used as mutex with 15+ readers/8 writers + parallel `workflow_owner_token` channel desync | Race conditions; item 2203 38h orphan |
| A4 | Orchestrator single-threaded, wp-cli subprocess (3-5s cold start each) | 1 publish/hour ceiling |

### 🟢 LOW — Operator UX

| ID | Problem | Where |
|---|---|---|
| U1 | `ready_review` vs `manual_review` indistinguishable in UI — both in same pile | `admin.php:3789, queue.php:4373` |
| U2 | No bulk category edit | `admin.php:410` |
| U3 | CLI commands missing `--category`/`--source` filters | `cli-commands.php` |

## Execution Plan — 4 Waves

### WAVE 1 — Content quality (today/tomorrow)

Operator constraint: NO model switch. Skip C1/C2 (model selection).
Focus on prompts + validators + dictionaries.

| Step | Task | Effort | Owner |
|---|---|---|---|
| W1.1 | **C4** `_ukrainian_grammar_warnings()` — detect invalid verb endings (`-ій` after `л/в`, `-сє` after `тьс`, `-осє` after `л`) + lemma whitelist | 1h | translator.py |
| W1.2 | **C8** Extend `_normalize_ukrainian_names` — Schwesig/Faeser/Lang/Klingbeil/Habeck/Baerbock/Pistorius + 10 Ministerpräsidenten + UA-correct transliteration of common DE names (Manuela→Мануела) | 30min | translator.py:510 |
| W1.3 | **C3** `_unsupported_named_entities()` — cross-check person/song/work titles vs story_card.entities_people + source. Add as soft warning. | 2h | rewriter.py |
| W1.4 | **K1+K2** Trust story_card ≥0.7 — short-circuit `refine_with_event_context`; drop halbfinale from sport_context UNLESS sport-noun adjacent | 1h | categorizer.php |
| W1.5 | Bump EDITORIAL_PROMPT_VERSION to invalidate stale payloads | 1min | ai-processor.php:15 |

### WAVE 2 — Prompts + UX (this week)

| Step | Task | Effort | Owner |
|---|---|---|---|
| W2.1 | **C5+C6** Prompt rule: lead must preserve source numeric/decision; UK/EN translations cover all DE paragraphs | 45min | base_voice.py, translator.py |
| W2.2 | **C7** UA brand-name fixes (FC Bayern, Bayer Leverkusen, FC Köln, etc.) | 20min | translator.py |
| W2.3 | **K3** Multi-category в publisher — drop array_slice(0,1) | 15min | publisher.php:665 |
| W2.4 | **U1** Split ready_review/manual_review in admin UI — separate blocks with CTAs | 2h | admin.php |
| W2.5 | **U3** CLI commands — add `--category`, `--source`, `--has-media` filters | 30min | cli-commands.php |

### WAVE 3 — Architecture stability (next week)

| Step | Task | Effort |
|---|---|---|
| W3.1 | **A1** Selector ORDER BY `workflow_step != '' DESC, importance DESC, created_at DESC` | 1h |
| W3.2 | **A2** soft_terminal_state_guard opt-in — explicit `mark_terminal_with_salvage_check()` | 2h |
| W3.3 | **A3** Atomic claim via `UPDATE ... WHERE workflow_owner_token=''` CAS, deprecate option | 3h |

### WAVE 4 — Throughput scale (later)

| Step | Task | Effort |
|---|---|---|
| W4.1 | **A4** ThreadPoolExecutor in orchestrator (process/publish/maintenance parallel) | 1d |
| W4.2 | Replace wp-cli `eval` with REST in hot paths | 1d |
| W4.3 | Per-category workers (after A3 lands) | 1w |
| Future | Switch to gpt-5-mini primary, when stable | operator decision |

## What NOT to do

- ✋ Point fixes without updating related components
- ✋ Architectural changes (W3+) on shared production without staging window
- ✋ Manual moves item-by-item — created chaos today
- ✋ Model swaps before W1-W2 done
