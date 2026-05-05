#!/usr/bin/env bash
set -euo pipefail

OUT="/tmp/europulse-codex-resume-20260427-0335.txt"
{
  echo "EuroPulse Codex resume reminder"
  echo "UTC: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
  echo "Berlin: $(TZ=Europe/Berlin date '+%Y-%m-%d %H:%M:%S %Z')"
  echo
  echo "Resume from:"
  echo "/root/projects/europulse/docs/epv21-autopilot-implementation-todo-2026-04-26.md"
  echo "/root/projects/europulse/docs/europulse-memory-brief.md"
  echo
  echo "Pause flags:"
  sudo -u www-data wp option get epv2_collect_paused --path=/var/www/europulse/public || true
  sudo -u www-data wp option get epv2_automation_paused --path=/var/www/europulse/public || true
  echo
  echo "Queue snapshot:"
  sudo -u www-data wp db query "SELECT state, COUNT(*) c FROM ep_epv2_queue GROUP BY state ORDER BY state; SELECT id,state,post_id,updated_at,JSON_UNQUOTE(JSON_EXTRACT(admin_notes,'$._system.ready_publish_at')) ready_publish_at,JSON_UNQUOTE(JSON_EXTRACT(admin_notes,'$._system.publish_not_before')) publish_not_before FROM ep_epv2_queue WHERE id >= 1015 ORDER BY id" --path=/var/www/europulse/public || true
  echo
  echo "Next task: fix publish countdown and strict 5-minute ready_publish schedule before unpausing automation."
} > "$OUT"
