# Active Runtime Boundary 2026-04-10

## Active

- Live WordPress plugin: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21`
- Repo source of truth: `/root/projects/europulse/wp-plugins/europulse-autopilot-v21`
- Active external worker/orchestrator: `/root/projects/europulse/worker-v21`
- Runtime mode: `server_orchestrator_enabled = true`

## Not Active

- Legacy worker tree: `/root/projects/europulse/worker`
- EPV3 config drafts: `/root/projects/europulse/config/epv3-prompts`, `/root/projects/europulse/config/epv3-rules`
- Input zip artifacts used only for manual upload: `/root/projects/europulse/input/europulse-autopilot-v21.zip`, `/root/projects/europulse/input/worker-v21.zip`
- Python bytecode caches under legacy and active worker trees

## Cleanup Rule

- Keep only `europulse-autopilot-v21` and `worker-v21` as executable project runtime.
- Keep backups under `/root/projects/europulse/backups`.
- Keep docs/history unless they directly interfere with runtime.
- Remove generated caches and abandoned legacy trees that are no longer referenced by `v21`.
