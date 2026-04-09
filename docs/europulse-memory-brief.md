# Europulse Memory Brief

This file gives MemPalace a compact operational picture of the Europulse system.

## Core Paths

- Project repo: `/root/projects/europulse`
- Live WordPress root: `/var/www/europulse/public`
- Live plugin: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`
- Live foundation mu-plugin: `/var/www/europulse/public/wp-content/mu-plugins/europulse-foundation`
- Local plugin source mirror: `/root/projects/europulse/wp-plugins/europulse-autopilot-v21`
- External worker: `/root/projects/europulse/worker-v21`

## Current Architecture

- WordPress is the live publishing shell.
- Heavy automation should stay outside the WordPress request lifecycle where possible.
- The system uses:
  - a WordPress plugin for queue, publishing, admin, SEO and site integration
  - an external worker/orchestrator layer for heavier automation tasks
  - live site foundation code in mu-plugins for rendering, archive URLs, schema and SEO hooks

## Important Live Concepts

- Queue states such as `new`, `ready_publish`, `published`, `retry_process`, `rejected`
- Publish lane behavior
- Translation gates for `DE`, `UK`, `EN`
- Source-first media logic
- SEO correctness for canonical, schema, archive pages and news sitemap
- Safe operation without breaking the live site

## Practical Priorities

1. Keep the live site stable.
2. Keep automation deterministic.
3. Fix routing, publish, translation, media and SEO defects without introducing regressions.
4. Prefer explicit invariants over heuristic state branching.

## Common Search Themes

- `ready_publish`
- `retry_process`
- `publish lane`
- `translation gate`
- `source-first media`
- `canonical`
- `schema`
- `news sitemap`
- `worker`
- `orchestrator`
