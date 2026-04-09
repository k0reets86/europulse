# EPV2 Rejected Audit 2026-04-02

## Snapshot

- `rejected = 36`
- `new = 16`
- `published = 70`

## Rejected Classes

- `context = 13`
- `translation = 6`
- `trim_new = 6`
- `migration = 5`
- `planner_replace = 4`
- `empty = 2`
- `stale = 1`

## Translation Rejects

### Still terminal after current bounded attempts
- `535` `translate_uk=4`
- `514` `translate_uk=4`
- `506` `translate_en=4`
- `493` `translate_en=4`

### Recovered back into automation after stale policy fix
- `527`
- `519`
- `499`

## High-Priority Notes

- `context` remains the largest reject class and needs second-pass audit for false negatives.
- `trim_new` and `planner_replace` need review against urgency, because these can suppress timely processing of strong items.
- `empty` reject messages must be normalized into explicit classes.
- `migration` rejects are historical and should stay out unless the new selection contract changes.

## Current Verified Fixes

- war stories no longer should route to `community`
- war stories now have dry factual framing contract
- `media_terminal_auto` no longer blocks `new + publish_finish` rows from automation
- stale translation terminal rejects with attempts below the current limit are requeued automatically
