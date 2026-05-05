#!/usr/bin/env bash
set -euo pipefail

OUT_DIR="/root/projects/europulse/logs"
OUT_FILE="$OUT_DIR/epv2_runtime_watch.log"
mkdir -p "$OUT_DIR"

source /etc/default/epv2-orchestrator

{
  echo "=== $(date -u '+%F %T UTC') ==="
  printf 'worker_health='
  timeout 5s curl -fsS http://127.0.0.1:8765/health || echo 'unreachable'
  printf 'bridge_health='
  timeout 8s curl -fsS -H "X-EPV2-Bridge-Token: ${EPV2_BRIDGE_TOKEN}" "${EPV2_SITE_URL}/index.php?rest_route=/epv2/v1/bridge/health" || echo 'unreachable'
  printf 'bridge_state='
  timeout 8s curl -fsS -H "X-EPV2-Bridge-Token: ${EPV2_BRIDGE_TOKEN}" "${EPV2_SITE_URL}/index.php?rest_route=/epv2/v1/bridge/state" || echo 'unreachable'
  printf 'service_worker='
  systemctl is-active epv2-worker || true
  printf 'service_orchestrator='
  systemctl is-active epv2-orchestrator || true
  echo
} >> "$OUT_FILE"
