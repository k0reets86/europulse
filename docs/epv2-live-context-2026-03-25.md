# EPV2 Live Context - 2026-03-25

## Current Goal

Re-enable `europulse-autopilot-v2` on the live WordPress site, identify what breaks immediately, fix it, and keep this file updated so the next session can resume without re-discovery.

## Live Environment

- Site root: `/var/www/europulse/public`
- Live plugin: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2`
- Repo mirror of plugin: `/root/projects/europulse/wp-plugins/europulse-autopilot-v2`
- WordPress repo workspace: `/root/projects/europulse`
- Temporary git dir used for repo sync/push: `/tmp/europulse_git/.git`

## Current Status Before Re-Enable

- Front page responds with HTTP `200` on `http://127.0.0.1/`
- `wp-login.php` was previously reachable
- Plugin `europulse-autopilot-v2` is currently `Inactive`
- No `epv2_*` cron events are currently scheduled
- No `epv2` worker processes are currently running
- `nginx`, `php8.3-fpm`, `mariadb` are active

## Recent Relevant History

- The plugin previously caused process storms and `php-fpm` saturation.
- The site was stabilized by disabling the plugin and clearing stale active-plugin state.
- The plugin code was copied into GitHub under `wp-plugins/europulse-autopilot-v2`.
- A fresh deploy key `europulse-github` was created and push to `origin/main` is working.

## Known Risks

- Re-enabling the plugin may re-schedule heavy cron hooks.
- The media/context enrichment path was previously implicated in runaway load.
- `php-fpm` has recently logged `pm.max_children` saturation, so any repeat loop must be caught quickly.

## Next Steps

1. Activate the plugin on live.
2. Inspect cron hooks, HTTP response, nginx/php-fpm logs, and running processes immediately after activation.
3. Fix the first reproducible breakage.
4. Update this file after every meaningful state change.

## 2026-03-25 Activation Notes

- Plugin activated successfully at `2026-03-25 20:49:33 UTC`.
- Front page remained healthy after activation and continued returning HTTP `200`.
- `epv2_process`, `epv2_publish`, and `epv2_collect` hooks were re-registered in WP cron.
- Immediate breakage was not a fatal error; the real failure was missing system cron pulse:
  - root crontab had been empty
  - WP cron hooks existed but automation was not being triggered reliably
- Root crontab was restored with:
  - `* * * * * /usr/bin/php /var/www/europulse/public/wp-cron.php >/dev/null 2>&1`

## 2026-03-25 Live Findings After Fixing Cron Pulse

- `process` runs resumed and new runs were written to `ep_epv2_runs`.
- Old stuck queue item `287` no longer remained in `processing_de`; cleanup moved it out of the dead state.
- Recent run sequence after re-enable:
  - `1950 process -> rejected_by_gate` for item `286`
  - `1951 process -> rejected_by_gate` for item `287`
  - `1952 process -> rejected_by_gate` for item `290`
- These rejections are not infrastructure failures. They are content gate decisions on stale March 19 items.
- Example: item `290` was rejected with gate:
  - `mode=reject`
  - `reason=низкий рейтинг материала`
  - selection reason says the source item is stale for current publication.

## Current Live State

- Infrastructure is stable:
  - `nginx` healthy
  - `php8.3-fpm` healthy
  - `mariadb` healthy
  - front page HTTP `200`
- Plugin is active.
- System cron pulse is restored.
- Remaining work is now functional pipeline tuning, not outage recovery.

## Outstanding Functional Issues

1. `ready_review` backlog still exists for old March 19 items (`270`, `276`, `278`, `282`, `285`).
2. The current queue mostly contains aged items, so `process` is spending cycles rejecting stale backlog instead of proving a fresh end-to-end pass.
3. `collect` forced from CLI appears slow/hanging and should be investigated separately.

## Latest Live Snapshot

- Root cron restored and active:
  - `* * * * * /usr/bin/php /var/www/europulse/public/wp-cron.php >/dev/null 2>&1`
- Plugin remains active.
- Front page still returns HTTP `200`.
- `collect` is not dead:
  - run `1954`
  - status `started`
  - latest progress observed: `48/74` sources, `9` collected items, `0` errors
  - current source observed: `Google News Sport DE`
- `process` is alive again and writing new runs, but current workload is mostly stale backlog:
  - `1950`, `1951`, `1952` ended as `rejected_by_gate`
  - reasons point to stale/low-value old candidates, not infrastructure failure

## Queue Cleanup On 2026-03-25

- User requested queue cleanup while preserving only today's live candidates.
- Deleted old non-published/non-duplicate queue items created before `UTC_DATE()`.
- Removed count: `5`.
- After cleanup, active queue contained only `2026-03-25` items:
  - `new`
  - `processing_de`
  - `retry_process`

## Proof That Fresh Items Are Moving

- Fresh today items did not stay idle after cleanup.
- Observed transitions:
  - item `298` moved from `processing_de` to `retry_process`
  - item `291` moved into `processing_de`
  - item `292` remained in `retry_process`
- Recent process runs on today's items:
  - `1957` -> `item_failed`, `last_item_id=298`, `AI rewrite did not reach publish threshold`
  - `1959` -> `started` while item `291` is in `processing_de`
- This confirms fresh items are entering the process contour; the remaining issue is content-quality/threshold handling, not queue paralysis.

## Resume Guidance

When resuming in a new session:

1. Check `crontab -l` first and confirm the WP cron pulse still exists.
2. Check latest collect progress:
   - `sudo -u www-data wp option get epv2_collect_progress --format=json --path=/var/www/europulse/public`
3. Check latest queue/runs:
   - queue table `ep_epv2_queue`
   - runs table `ep_epv2_runs`
4. Focus next on fresh candidates created by the current collect cycle, not on the stale March 19 backlog.
# Incident 2026-03-25 21:20 UTC

- Site returned `504 Gateway Timeout` after live debugging of EPV2 processing/media/source-enrichment.
- Immediate cause of outage:
  - several manual long-running `php` probes were left alive;
  - concurrent `wp-cron.php` jobs were also running;
  - `php-fpm` workers were saturated and front/login started hanging.
- Stabilization actions performed:
  - killed hanging manual PHP processes and `wp-cron.php` processes;
  - restarted `php8.3-fpm`;
  - removed root crontab entry that was forcing `/usr/bin/php /var/www/europulse/public/wp-cron.php` every minute;
  - deactivated plugin `europulse-autopilot-v2`;
  - confirmed plugin disappeared from `active_plugins` after cache flush;
  - confirmed `http://127.0.0.1/` and `http://127.0.0.1/wp-login.php` return `200 OK`.
- Current safe live state after stabilization:
  - WordPress is up;
  - EPV2 plugin is deactivated;
  - server-side automation is paused;
  - root crontab is empty.

# EPV2 findings before incident

- `291` / `292`: not publish-ready because `release_quality=80`, weak/irrelevant or missing featured media, `source_count=1`, `supporting_count=0`.
- `298`: not publish-ready because editorial quality is `88`, with weak source dossier (`source_count=1`, `supporting_count=0`), even though release/google are `100`.
- Root product issues identified:
  - media repair accepted technically valid but semantically weak image candidates;
  - `context_supporting_image()` in media layer had an early `return ''`, so context-based image recovery was effectively disabled;
  - publish-finish attempted only one pass, so items with fixable warnings often fell back to `retry_process` too early;
  - source enrichment was too strict and often discarded supporting sources, leaving `supporting_count=0`;
  - queue UI showed editorial quality too optimistically and hid publish-grade minimum.

# Code changes already applied to live plugin before deactivation

- `includes/media/class-epv2-media.php`
  - removed dead early `return ''` in `context_supporting_image()`;
- `includes/ai/class-epv2-ai-processor.php`
  - `repair_payload_media()` now requires semantic relevance in addition to technical media validation;
  - `attempt_publish_grade_lift()` now performs up to 3 targeted publish-finish iterations in one pass;
- `includes/core/class-epv2-source-enricher.php`
  - relaxed supporting-source acceptance when there is context/category/media evidence;
  - brief supporting entries can now survive if they bring relevant visual/context signal;
- `includes/admin/class-epv2-admin.php`
  - queue quality badge now reflects publish-grade minimum instead of only editorial score.

# Next safe restart plan

- Do not reactivate EPV2 directly on live until:
  - root crontab strategy is redesigned to avoid duplicate/minutely blind `wp-cron.php` forcing;
  - EPV2 is re-enabled in controlled mode first without unattended loops;
  - a single manual smoke cycle is run and observed end-to-end;
  - only then restore scheduled execution.

# Relaunch 2026-03-25 21:25 UTC

- EPV2 relaunched in safe mode, not in previous unsafe mode.
- Live configuration now:
  - plugin `europulse-autopilot-v2` is active again;
  - `epv2_server_orchestrator_enabled = 1`;
  - `worker_mode = disabled`;
  - root crontab is now:
    - `*/5 * * * * /var/www/europulse/worker/run_orchestrator_once.sh >/dev/null 2>&1`
- Important architecture correction:
  - previous `worker_mode=cli` was not a true heavy-contour offload;
  - `php_worker.php` still loaded `wp-load.php`, so it only created nested WordPress/PHP execution and extra memory/process pressure;
  - for live stability, nested worker was disabled.

# Additional code changes for safe runtime

- `includes/jobs/class-epv2-jobs.php`
  - `maybe_kick_pipeline()` now runs only in CLI/WP-CRON context;
  - front-end and ordinary admin web requests no longer self-kick the pipeline on `init`.
- `worker/run_orchestrator_once.sh`
  - added `timeout 240`;
  - added `nice -n 10`;
  - kept `flock` as the single-executor guard;
  - added CLI `memory_limit=512M`.
- `worker/epv2_orchestrator.php`
  - explicit `memory_limit=512M`;
  - explicit `set_time_limit(240)`.

# Live verification after relaunch

- Front page:
  - `curl -I http://127.0.0.1/` => `200 OK`
- Login page:
  - `curl -I http://127.0.0.1/wp-login.php` => `200 OK`
- Current runtime pattern:
  - one CLI orchestrator process is active under `flock`;
  - front stays up while orchestrator is running;
  - no forced `wp-cron.php` minute loop remains.

# Interpretation

- The split done earlier improved operational isolation only partially.
- Real finding:
  - moving execution into a separate CLI entrypoint is useful for site stability;
  - but as long as the nested worker still boots full WordPress, it is not a true offload;
  - safe live mode therefore uses:
    - plugin active,
    - one external orchestrator,
    - no nested worker,
    - no front-request auto-kicks.
