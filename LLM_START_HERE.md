# EuroPulse LLM Start Here

Last updated: 2026-05-18 20:05 UTC.

This file is the first entry point for any new LLM session on EuroPulse.
Read it before opening old handoffs, TODOs, or plugin maps.

## Read Order

1. `LLM_START_HERE.md` — current checkpoint and next actions.
2. `SESSION_HANDOFF.md` — operational handoff, latest section at the top.
3. `TODO.md` — actionable task list, latest section at the top.
4. `docs/SYSTEMIC_AUDIT_PLAN_2026_05_12.md` — systemic quality/architecture plan.
5. `docs/PLUGIN_MAP_INDEX.md` — map of the WordPress plugin.
6. `docs/PLUGIN_MAP_WORKER.md` and `docs/PLUGIN_MAP_QUEUE.md` — worker/queue internals.

Do not start from archived v2/v3 notes unless a current file explicitly points there.

## Current Repo State

- Repo: `/root/projects/europulse`
- Branch: `review/plugin-audit`
- Runtime WordPress root: `/var/www/europulse/public`
- Live plugin path: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`
- Repo plugin source: `wp-plugins/europulse-autopilot-v21`
- Worker source: `worker-v21`

The active work package is a post-incident stabilization pass after the worker hit the `1.0G` systemd memory ceiling while OpenAI quota was exhausted.

## What Was Just Fixed

- DeepSeek/provider-order repair:
  - WordPress now sends `ai_provider`, `ai_model`, `ai_fallback_provider`, and `ai_fallback_model` to `/analyze_story`.
  - Worker respects explicit provider order. If primary is DeepSeek and OpenAI is not configured as fallback, OpenAI is not silently appended.
  - OpenAI embeddings are skipped unless OpenAI is actually in provider order.
  - Worker-side provider cooldown prevents repeated OpenAI quota/rate-limit loops.
- Frontend stability:
  - `europulse_latest_list` uses the shared home pool when available instead of a heavy `WP_Query` + `meta_query` path.
  - `europulse_autopilot_home_pool` has a short persistent cache to avoid rebuilding the same pool on every PHP request.
- Operational noise reduction:
  - REST bridge auth success logs and high-frequency process logs are throttled.
  - Healthcheck is pause-aware: when `epv2_automation_paused=1`, inactive worker/orchestrator are not treated as incidents.
  - Alert visibility lives in `epv2_active_alerts`.

## Current Live State Observed 2026-05-18

- `epv2_automation_paused=1`
- `epv2_collect_paused=1`
- `nginx`, `php8.3-fpm`, and `mariadb` are active.
- `epv2-worker` and `epv2-orchestrator` are intentionally inactive while automation is paused.
- `epv2_active_alerts` contains only `swap_high`.
- Memory: about `2.1G/3.7G` used, `1.6G` available.
- Swap: about `1.1G/2.0G` still used after the previous overload.
- `apt/dpkg` is still blocked by an old `msmtp` debconf prompt:
  - prompt template: `msmtp/apparmor`
  - default answer: `false`
  - observed process chain: `apt-get install -y msmtp msmtp-mta mailutils-common bsd-mailx` -> `dpkg --configure --pending` -> `whiptail --yesno Enable AppArmor support?`

## Next Actions

1. Finish committing the stabilization package and this handoff.
2. Fix package maintenance:
   - preseed `msmtp msmtp/apparmor boolean false`
   - finish `dpkg --configure -a` non-interactively
   - verify `apt-get install -f` / `dpkg --audit`
3. Only after package maintenance is clean, decide whether to clear swap.
   - Current RAM has enough headroom, but `swapoff -a && swapon -a` must be treated as an explicit maintenance action.
4. Before unpausing automation, verify:
   - provider settings are DeepSeek-primary as intended
   - worker `/health` exposes provider cooldown snapshot
   - active alerts are empty or only accepted warnings
5. Then restart controlled:
   - start `epv2-worker`
   - start `epv2-orchestrator`
   - unpause only when ready to watch the first cycle

## Guardrails

- Do not re-enable OpenAI as implicit fallback while quota is exhausted.
- Do not unpause automation just to test healthcheck.
- Do not treat inactive worker/orchestrator as an incident while `epv2_automation_paused=1`.
- Do not commit local artifacts: `.claude/`, `prepare`, `*.bak_pre_*`, raw `monitoring/snap-*.tsv`.
- If editing live code, remember repo source and `/var/www/...` live plugin are separate paths unless explicitly synced.
