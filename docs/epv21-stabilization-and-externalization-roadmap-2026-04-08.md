# EPV21 Stabilization And Externalization Roadmap

## Goal

Turn `europulse-autopilot-v21` from a WordPress-heavy, partially stalled newsroom plugin into a stable server-driven system where:

1. WordPress is a thin CMS and publishing bridge.
2. Heavy processing runs outside WordPress.
3. Queue state transitions are deterministic and observable.
4. Ready items publish reliably.
5. The system can self-recover from stuck runs, publish blockers, translation drift, and media failures.

The work is not complete until the system can automatically execute:

- collect
- enrich
- rewrite DE master
- translate UK/EN
- finalize media + SEO
- publish

for a sustained sequence without manual intervention.

## Current Findings

### Infrastructure

- Server is healthy.
- MariaDB, Redis, nginx, php-fpm are healthy.
- Systemd timers are alive.
- Internet is available.

### WordPress / live site

- Plugin `europulse-autopilot-v21/europulse-autopilot.php` is active.
- `siteurl` and `home` currently point to the raw IP instead of the final domain.
- `news-sitemap.xml` redirects incorrectly and is not healthy for Google News.

### Live automation state

- `epv2_automation_paused = 1`
- No scheduled WP hooks for:
  - `epv2_collect`
  - `epv2_process`
  - `epv2_publish`
- Queue is frozen in partial states.
- Last real automation movement was on `2026-04-03`.
- Last `process` run is still `started`.

### Architectural reality

`v21` already contains three partially overlapping models:

1. Classic WP-cron execution
2. Worker-assisted processing
3. Half-built “server orchestrator” concept

The codebase already has:

- worker settings
- worker client
- queue state machine fragments
- publish-finish recovery logic
- manual mode
- REST API skeleton

But it does **not** yet have a complete external orchestration contract.

## Core Design Decision

### Final target architecture

#### WordPress responsibilities

- admin dashboard
- settings
- manual mode UI
- queue and run inspection
- source configuration
- media library persistence
- post creation / post updates
- SEO plugin integration
- Polylang / WPML integration
- authenticated REST bridge

#### External orchestrator responsibilities

- collect scheduling
- source polling
- queue claiming
- heavy AI generation
- translation
- media extraction / fallback
- semantic validation
- retries / backoff
- stuck-run recovery
- publish dispatch orchestration
- health monitoring

#### Worker responsibilities

- run a single heavy stage or full bundle
- return normalized payloads
- never own WordPress publishing directly

### Non-goals

- Do not keep heavy orchestration inside WordPress requests.
- Do not depend on WP-Cron for critical automation.
- Do not continue supporting multiple conflicting orchestration modes indefinitely.

## Mandatory Invariants

These invariants must be enforced in code, not just assumed:

1. One queue item cannot hold the automation lane indefinitely.
2. A `started` run must either finish or be force-recovered after timeout.
3. `ready_publish` must never silently drift back without an explicit reason.
4. A multilingual bundle may not publish if semantic drift is detected.
5. A trusted source image must be accepted by default if technically valid.
6. WordPress publish must not depend on long-running AI work.
7. Already-ready items must drain predictably on the publish schedule.
8. `breaking`, `top_story`, and manual items may bypass the normal daily budget.
9. Daily budget applies to **admission into ready queue**, not to already-ready backlog.
10. Every external action must be traceable by queue ID and run ID.

## Workstreams

## Workstream A: Freeze And Observe

### Objective

Create a stable baseline before changing orchestration.

### Tasks

1. Keep `v21` isolated as the only active development target.
2. Keep automation paused during structural migration.
3. Preserve backups for:
   - code
   - settings
   - queue snapshot
   - runs snapshot
4. Preserve live watcher logs.
5. Keep deletion of old branches complete.

### Acceptance

- Only `v21` remains as the active plugin codebase.
- Backups exist and are timestamped.

## Workstream B: Build WordPress Bridge API

### Objective

Make WordPress publishable and controllable from an external orchestrator without using wp-admin clicks or WP-Cron as the primary engine.

### Required new REST endpoints

1. `GET /epv2/v1/bridge/health`
   - plugin version
   - paused flag
   - queue counts
   - scheduler mode

2. `GET /epv2/v1/bridge/state`
   - settings summary
   - active automation item
   - queue state counts
   - next ready publish timestamp

3. `POST /epv2/v1/bridge/collect`
   - trigger collect pass safely

4. `POST /epv2/v1/bridge/process`
   - trigger one processing pass safely

5. `POST /epv2/v1/bridge/publish`
   - trigger one publish pass safely

6. `POST /epv2/v1/bridge/resume`
   - clear pause
   - optionally re-schedule legacy hooks only if server orchestrator mode is off

7. `POST /epv2/v1/bridge/pause`
   - pause automation

8. `POST /epv2/v1/bridge/maintenance`
   - run cleanup / stale-lock repair / queue normalization

### Security model

- Use shared secret auth via header.
- Reuse `worker_shared_secret` initially.
- Never expose these endpoints to public unauthenticated traffic.
- Localhost-only callers are preferred, but auth is still required.

### Acceptance

- External service can inspect and control WordPress without wp-admin interaction.

## Workstream C: External Orchestrator Service

### Objective

Introduce a persistent server process that drives the newsroom cycle.

### Required behavior

1. Poll `bridge/state`
2. Respect `paused`
3. Run maintenance regularly
4. Trigger collect based on source freshness windows
5. Trigger process while processable items exist
6. Trigger publish while due ready items exist
7. Keep bounded retries and exponential backoff
8. Record its own logs
9. Recover stale `started` runs

### Preferred runtime

- Python service in `worker-v21`
- systemd service + timer or persistent service loop

### Acceptance

- Orchestrator can run the cycle without WP-Cron dependency.

## Workstream D: Queue Contract Cleanup

### Objective

Make queue transitions deterministic.

### Target queue semantics

- `new` = admitted, waiting for processing
- `processing_de` = currently held by processing pass
- `retry_process` = recoverable processing failure / staged rework
- `ready_publish` = fully prepared, waiting only for publish schedule
- `publishing` = currently in publish transaction
- `published` = terminal success
- `rejected` = true editorial hard reject only
- `duplicate` = duplicate only

### Tasks

1. Remove technical misuse of `rejected`.
2. Eliminate orphan `started` runs.
3. Ensure one source of truth for:
   - `state`
   - `workflow_step`
   - `publish_not_before`
4. Add explicit maintenance repair for:
   - stale active owner
   - stale process lock
   - stale publish lock
   - old `started` runs
5. Add queue contract checker script and REST maintenance endpoint hook.

### Acceptance

- Queue does not get stuck in contradictory combinations.

## Workstream E: Translation And Semantic Consistency

### Objective

Eliminate multilingual drift.

### Tasks

1. Keep semantic consistency gate as a hard pre-publish requirement.
2. Make drift recovery deterministic:
   - drift => `retry_process`
   - required stage => `translate_uk` or `translate_en` or `publish_finish`
3. Never publish when one language switched story.
4. Add bridge maintenance that scans:
   - recent `retry_process`
   - `ready_publish`
   - recent published bundles if needed
5. Ensure worker and WP normalization produce the same payload contract.

### Acceptance

- No bundle publishes if `DE`, `UK`, `EN` are semantically inconsistent.

## Workstream F: Media Architecture

### Objective

Make trusted-source image acquisition reliable and boring.

### Rules

1. For trusted editorial sources:
   - primary article image is valid by default.
2. Only reject for technical reasons:
   - not an image
   - tiny/icon/logo/sprite
   - broken fetch
   - obvious promo banner
3. Do not overthink relevance for trusted hero images.

### Tasks

1. Add explicit source-first image harvesting:
   - `og:image`
   - `twitter:image`
   - JSON-LD `image`
   - hero/article images
   - `srcset`
2. Store harvested image candidates in payload/source dossier.
3. Force `publish_finish` to materialize the candidate into the payload.
4. Add blocked-host strategy for Cloudflare-like hosts:
   - browser-like headers
   - referer
   - source page parse fallback
5. Only fall back to stock/secondary image search if source image truly absent.

### Acceptance

- Trusted-source stories stop failing publication just because media finalization is flaky.

## Workstream G: Publish Lane

### Objective

Make ready items publish reliably and predictably.

### Tasks

1. Keep publish interval at `5 min`.
2. First item in an empty ready queue waits:
   - remaining current window
   - plus one full publish cycle
3. Existing backlog drains at exact 5-minute steps.
4. Ready backlog is not blocked by daily budget.
5. Normal daily budget applies only when admitting new normal items into ready queue.
6. `breaking`, `top_story`, `priority`, `manual_mode` bypass normal budget.
7. Stale ready items are removed from publish lane before publish attempts, not by crashing the publish run.

### Acceptance

- `ready_publish` behaves as a real dispatch queue.

## Workstream H: Source Layer

### Objective

Keep only productive and safe sources.

### Tasks

1. Maintain source quarantine lists.
2. Separate sources into:
   - productive editorial
   - official/service
   - transport/special parser
   - disabled noise
3. For transport:
   - add proper source/parser strategy
   - do not rely on dead endpoints
4. Keep intake freshness strict:
   - time-sensitive: very short window
   - hard news: short window
   - service/community: controlled longer window

### Acceptance

- Intake creates relevant, fresh queue items without clogging.

## Workstream I: WordPress SEO And Publishing Surface

### Objective

Make WordPress a healthy receiver of finished newsroom payloads.

### Tasks

1. Restore proper canonical domain in `siteurl` and `home`.
2. Fix `news-sitemap.xml`.
3. Keep JSON-LD `NewsArticle`.
4. Keep Rank Math / Yoast meta writing.
5. Keep source attribution block.
6. Keep manual mode with AI assistance and media attach.

### Acceptance

- Site is indexable and Google News-friendly again.

## Workstream J: Testing

### Required test levels

1. Unit-like payload normalization tests
2. Queue contract checks
3. REST bridge auth tests
4. Worker pipeline smoke tests
5. End-to-end collect/process/publish dry run
6. Live staging-like verification on paused system
7. Controlled unpause
8. Observation window with watcher

### Critical acceptance sequence

System is only acceptable after all of these:

1. pause off
2. external orchestrator active
3. fresh items collected automatically
4. process path advances automatically
5. ready items publish automatically
6. no semantic drift in published bundles
7. no stuck `started` runs
8. at least `10` consecutive automatic successful publications

## Implementation Order

### Phase 1

- backups
- isolation
- roadmap
- live audit

### Phase 2

- REST bridge with token auth
- orchestration health/state endpoints
- maintenance endpoint

### Phase 3

- external orchestrator loop in `worker-v21`
- server mode toggle
- systemd service/unit

### Phase 4

- queue maintenance and stale-run repair
- publish lane normalization
- media materialization guarantees

### Phase 5

- semantic drift hardening
- translation repair loop cleanup

### Phase 6

- source cleanup and transport parser follow-up
- SEO/canonical repair

### Phase 7

- full end-to-end testing
- controlled unpause
- 10-publication acceptance run

## Immediate Next Tasks

1. Add authenticated REST bridge endpoints.
2. Add bridge maintenance runner.
3. Add external orchestrator control path.
4. Test bridge locally against paused live WP.
5. Then move the scheduling brain out of WP-Cron.

