# EPV2 Automation Recovery Plan 2026-04-02

## Current Fault Classes

### A. War Coverage Contract Failure
- Symptom:
  - War stories about Russian military targets were published with the wrong category, weak framing, and overly soft tone.
  - Example: queue `521` published under `community` instead of `ukraine`.
- Root cause:
  - Categorization rules over-weighted `community` markers for Ukrainian-source content.
  - Rewrite prompt had no separate war-framing contract.
  - Validator did not penalize sympathetic framing toward losses of the aggressor's military assets.

### B. War Media Semantic Failure
- Symptom:
  - Source-first media could still be visually wrong for military-strike stories.
  - Editorial-source shortcut in media relevance was too permissive.
- Root cause:
  - `ukraine_attack` media intent was too narrow.
  - Editorial shortcut allowed portraits/meetings/handshakes to pass if the host looked legitimate.

### C. Queue Starvation / Handoff Failure
- Symptom:
  - `new` rows existed while selector preview returned `none`, or recoverable rows sat in `new` but were not processable.
- Root cause:
  - stale `manual_confirmation_required = media_terminal_auto`
  - stale retry metadata on translation path
  - owner slot could be captured by one looping translation row

### D. Translation Reject Backlog
- Symptom:
  - too many rows moved to translation reject after bounded auto-repair
- Root cause:
  - terminal resolver entered too early
  - repeated `translate_uk/en` retry path created churn before enough bounded attempts were used

### E. Reject Backlog Composition
- Current rejected distribution:
  - `context`: 13
  - `translation`: 6
  - `trim_new`: 6
  - `migration`: 5
  - `planner_replace`: 4
  - `empty`: 2
  - `stale`: 1

## Non-Negotiable Acceptance

1. War/Ukraine military stories must never publish in `community`.
2. War/Ukraine military stories must use dry factual framing and must not use sympathetic tone toward Russian military losses.
3. Source-first media must still be semantically relevant, not just technically valid.
4. If `new` contains processable rows, selector preview must never return `none`.
5. Recoverable media rows in `new + publish_finish` must be processable by automation.
6. Translation rows must exhaust bounded retry properly before terminal reject.
7. Rejected rows must be split into:
   - truly unsalvageable
   - salvageable and auto-recoverable
8. After fixes, new items must move one-by-one through automation without long idle gaps.

## Step-by-Step Work Plan

### Phase 1. Stop Publishing Wrong War Stories
- [x] Add military/Crimea/Russian-target overrides in categorizer.
- [x] Add war coverage framing contract into rewrite prompt.
- [x] Add validator warnings for sympathetic or false-symmetry war tone.
- [ ] Re-audit recent published war stories and identify incorrect category/media/tone outputs.
- [ ] Repair already-published wrong war posts with corrected taxonomy/media where needed.

### Phase 2. Fix War Media Matching
- [x] Tighten `ukraine_attack` intent patterns.
- [x] Reject portraits/handshakes/meeting-table imagery for military strike stories.
- [ ] Re-run media relevance checks for war stories published in the last 24h.
- [ ] Add post-audit replacement path for published war posts with semantically wrong featured images.

### Phase 3. Remove Queue Starvation
- [x] Allow `new + publish_finish + media_terminal_auto` rows to remain processable under v2.
- [ ] Confirm selector preview always picks a processable row when `new > 0`.
- [ ] Confirm process handoff after `ready_publish/published` immediately resumes the next `new`.
- [ ] Confirm no owner-slot sits idle while processable `new` exists.

### Phase 4. Fix Translation Loop / Rejects
- [x] Delay translation terminal resolver until attempt `>= 4`.
- [ ] Confirm `translate_uk/en` rows no longer bounce in low-attempt churn.
- [ ] Reactivate salvageable translation rejects.
- [ ] Recount translation reject class after fix.

### Phase 5. Clear Salvageable Rejected Rows
- [ ] Review `context` rejects and split into:
  - truly weak/noisy
  - false negatives caused by over-strict context gate
- [ ] Review `trim_new` rejects and confirm trim policy is not suppressing urgent/strong items.
- [ ] Review `planner_replace` rejects and confirm these are true replacements, not story duplication bugs.
- [ ] Requeue salvageable rows only after their class-level gate is fixed.

### Phase 6. Live Verification
- [ ] Site health:
  - `curl -I http://127.0.0.1`
  - `systemctl status php8.3-fpm nginx`
- [ ] Queue/runs:
  - `state` counts
  - selector preview
  - latest process/publish runs
- [ ] Prove:
  - `new -> processing`
  - `processing -> ready_publish`
  - `ready_publish -> published`
- [ ] Audit at least 10 recent auto-published materials against:
  - category correctness
  - live tone
  - featured media relevance
  - SEO present
  - published post opens

## Immediate Next Actions

1. Re-audit queue `521` and recent war publications after new contracts.
2. Repair published war posts with wrong category/media.
3. Confirm selector no longer stalls with processable `new`.
4. Re-run rejected class audit after translation/media gate fixes.
