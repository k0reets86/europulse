# EuroPulse Cleanup Audit — 2026-04-08

## Scope

This audit covers:

- live WordPress site at `/var/www/europulse/public`
- live plugin/project at `/root/projects/europulse`
- related runtime/system footprint on the current server

The goal is to separate:

- safe-to-delete clutter
- needs-review candidates
- must-keep runtime components

## Current Disk Picture

Root filesystem:

- `/` total: `38G`
- used: `9.9G`
- free: `26G`
- usage: `28%`

Largest top-level consumers:

- `/root` = `2.8G`
- `/var` = `2.7G`
- `/usr` = `2.5G`

## Main Space Consumers

### `/root`

- `/root/projects` = `1015M`
- `/root/.cache` = `654M`
- `/root/.codex` = `504M`
- `/root/.npm` = `437M`
- `/root/archive` = `95M`
- `/root/.wp-cli` = `68M`

### Project `/root/projects/europulse`

- total project = `1015M`
- `backups` = `653M`
- `node_modules` = `240M`
- `worker-v21` = `103M`
- `logs` = `14M`
- `wp-plugins/europulse-autopilot-v21` = `1.5M`

### `/var`

- `/var/log` = `980M`
- `/var/www` = `799M`
- `/var/lib` = `657M`
- `/var/cache` = `232M`

### WordPress content

- `/var/www/europulse/public/wp-content` = `485M`
- `uploads` = `339M`
- `plugins` = `104M`
- `themes` = `15M`
- `languages` = `29M`

### Additional site-adjacent paths

- `/var/www/europulse/sandbox-v21` = `214M`
- `/var/www/europulse/disabled-plugins` = `2.5M`

## Confirmed Keep

These are runtime-critical and should not be deleted:

- `/var/www/europulse/public`
- `/root/projects/europulse/wp-plugins/europulse-autopilot-v21`
- `/root/projects/europulse/worker-v21`
- `/etc/systemd/system/epv2-worker.service`
- `/etc/systemd/system/epv2-orchestrator.service`
- `/etc/systemd/system/europulse-wp-cron.timer`
- `/etc/systemd/system/epv2-runtime-watch.timer`

## Confirmed Safe Cleanup Candidates

### 1. Project backups

Heavy backup artifacts currently dominate project clutter:

- `20260317-001937-site-audit/wp-content.tar.gz` = `230M`
- `20260317-233655-post-cleanup/site-files.tar.gz` = `211M`
- `20260318-093654-post-clean-audit/site-files.tar.gz` = `198M`

Recommended retention:

- keep latest strategic backups only:
  - `epv21-code-20260408T003954Z.tar.gz`
  - `epv21-live-state-20260408T003955Z.json`
  - `epv21-db-snapshot-20260408T003955Z.sql.json`
  - `obsolete-plugins-20260408T003242Z.tar.gz`
  - optionally `epv2-plugin-live-20260403T204811Z.tar.gz`
  - optionally `epv2-settings-20260403T204812Z.json`
- archive or delete the March audit/post-cleanup backup directories

Estimated savings:

- about `640M`

### 2. `node_modules`

`/root/projects/europulse/node_modules` = `240M`

This is tooling weight:

- Lighthouse
- Playwright
- Puppeteer
- TS/JS audit dependencies

It is not required for WordPress runtime or worker runtime.

Safe if:

- we accept reinstalling npm tooling later when needed

Estimated savings:

- `240M`

### 3. Project runtime log

- `/root/projects/europulse/logs/epv2_runtime_watch.log` ≈ `15M`

Safe action:

- truncate or rotate

Estimated savings:

- `15M`

### 4. System journals

- `/var/log/journal` = `795M`

Safe action:

- vacuum old journals with retention cap

Estimated savings:

- typically `500M+` depending on chosen cap

### 5. APT cache

- `/var/cache/apt` = `211M`

Safe action:

- `apt clean`

Estimated savings:

- about `200M`

### 6. Nginx cache

- `/var/cache/nginx/europulse` = `8.4M`

Safe action:

- already purgeable and non-critical

### 7. Old disabled plugin copies

- `/var/www/europulse/disabled-plugins` = `2.5M`

Contains:

- `europulse-autopilot-v2`
- `europulse-autopilot-v21`
- `europulse-autopilot-v3`

Search did not reveal runtime dependencies on this path.

Safe action:

- delete entire directory after final confirmation

### 8. Sandbox WordPress copy

- `/var/www/europulse/sandbox-v21` = `214M`

This is a separate full WP tree copy, not the live root.

Search did not reveal active service references to `sandbox-v21`.

Safe action:

- remove if no one is still using it for manual fallback/debug

Estimated savings:

- `214M`

## Needs Review Before Deletion

### 1. `uploads`

`/var/www/europulse/public/wp-content/uploads` = `339M`

This is not general trash by default.

The largest files are mostly editorial images and generated media. Some may be old or duplicated, but automatic deletion is risky without DB reference analysis.

Do not bulk-delete uploads yet.

Needs a second-pass audit:

- unattached media
- duplicate generated covers
- old oversized source originals

### 2. `input`

`/root/projects/europulse/input` contains:

- `europulse-autopilot-v21.zip`
- `worker-v21.zip`
- `INSTALL.md`
- `worker-setup.sh`
- `исследование.txt`

Some of this is still useful as deployment/import source.

Recommended:

- keep `исследование.txt`
- keep only one authoritative install package set
- remove duplicate zips if current code repo is authoritative

### 3. `docs`

`/root/projects/europulse/docs` is not large (`~608K`) but is cluttered.

Contains:

- architecture audits
- migration plans
- category/source audits
- old v2/v3 design notes

Not a disk issue, but a project hygiene issue.

Recommended:

- keep only:
  - current stabilization roadmap
  - current recovery/runbook
  - source strategy docs still in use
- move old design docs to `docs/archive/` or external archive

### 4. `scripts`

Most scripts are small and not a disk issue, but many are one-off repair/debug tools.

High-probability clutter:

- `debug_544_semantic.php`
- `fix_queue_544.php`
- `epv2_workflow_v2_migration.php`
- `epv2_workflow_v2_selector_check.php`
- `epv2_reactivate_translation_rejects.php`
- `epv2_rejected_media_audit.php`
- `epv2_source_intake_audit.php`
- `epv2_source_quarantine_apply.php`

Likely keep:

- `epv2_runtime_watch.sh`
- smoke tests still used
- compliance/audit scripts if still part of workflow

Recommended:

- split into:
  - `scripts/runtime/`
  - `scripts/tests/`
  - `scripts/archive/one-off/`

## Site/SEO Issues Found While Auditing

These are not cleanup items, but active defects:

- `/nachrichten/` currently carries canonical to homepage instead of self URL
- homepage is currently exposed as `NewsArticle` in schema, which is incorrect
- several category terms appear to be legacy/no-content leftovers:
  - `2801`
  - `2803`
  - `2805`
  - `allgemein`
  - `muenchen`
  - `veranstaltungen`
  - `ukrainische-initiativen`
  - `vereine-projekte`
  - `treffen-networking`

## Runtime Facts Relevant To Cleanup

Active services:

- `epv2-worker.service`
- `epv2-orchestrator.service`
- `europulse-wp-cron.timer`
- `epv2-runtime-watch.timer`
- `nginx`
- `php8.3-fpm`
- `mariadb`
- `redis`

Important:

- automation is currently paused
- worker/orchestrator still point to `/var/www/europulse/public`
- no active service references found for:
  - `/var/www/europulse/sandbox-v21`
  - `/var/www/europulse/disabled-plugins`

## Recommended Cleanup Order

1. Rotate/truncate logs
2. Vacuum system journal
3. `apt clean`
4. Delete `disabled-plugins`
5. Delete `sandbox-v21`
6. Apply backup retention and remove March heavyweight archives
7. Optionally remove `node_modules`
8. Archive old docs and one-off scripts
9. Only after separate audit: prune unused uploads

## Estimated Safe Reclaim

Without touching uploads:

- project backups: `~640M`
- `node_modules`: `240M`
- project log: `15M`
- sandbox: `214M`
- disabled-plugins: `2.5M`
- apt cache: `~200M`
- journals: `~500M+`

Expected reclaim:

- around `1.8G` conservatively
- around `2.0G+` if journal cleanup is aggressive
