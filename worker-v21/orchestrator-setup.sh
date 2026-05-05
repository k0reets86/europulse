#!/usr/bin/env bash
set -euo pipefail

WORKER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVICE_NAME="epv2-orchestrator"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
ENV_FILE="/etc/default/${SERVICE_NAME}"
WP_LOAD="${WP_LOAD:-/var/www/europulse/public/wp-load.php}"

if [[ $EUID -ne 0 ]]; then
  echo "ERROR: run as root"
  exit 1
fi

if [[ ! -f "${WP_LOAD}" ]]; then
  echo "ERROR: wp-load.php not found: ${WP_LOAD}"
  exit 1
fi

BRIDGE_TOKEN="${EPV2_BRIDGE_TOKEN:-}"
if [[ -z "${BRIDGE_TOKEN}" ]]; then
  BRIDGE_TOKEN="$(php -r "define('WP_USE_THEMES', false); require '${WP_LOAD}'; echo EPV2_Settings::worker_shared_secret();" 2>/dev/null || true)"
fi

SITE_URL="${EPV2_SITE_URL:-}"
if [[ -z "${SITE_URL}" ]]; then
  SITE_URL="$(php -r "define('WP_USE_THEMES', false); require '${WP_LOAD}'; echo rtrim((string) home_url('/'), '/');" 2>/dev/null || true)"
fi

if [[ -z "${BRIDGE_TOKEN}" || -z "${SITE_URL}" ]]; then
  echo "ERROR: Unable to resolve bridge token or site url"
  exit 1
fi

cat > "${ENV_FILE}" << EOF
EPV2_BRIDGE_TOKEN=${BRIDGE_TOKEN}
EPV2_SITE_URL=${SITE_URL}
EPV2_LOOP_SECONDS=15
EPV2_MAINTENANCE_SECONDS=180
EOF
chmod 600 "${ENV_FILE}"
chown root:root "${ENV_FILE}"

cat > "${SERVICE_FILE}" << EOF
[Unit]
Description=EuroPulse AutoPilot v21 External Orchestrator
After=network.target nginx.service php8.3-fpm.service mariadb.service

[Service]
Type=simple
User=root
WorkingDirectory=${WORKER_DIR}
EnvironmentFile=${ENV_FILE}
ExecStart=/usr/bin/env python3 ${WORKER_DIR}/epv2_bridge_orchestrator.py
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=${SERVICE_NAME}
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
EOF
chmod 644 "${SERVICE_FILE}"
chown root:root "${SERVICE_FILE}"

systemctl daemon-reload
systemctl enable "${SERVICE_NAME}"
systemctl restart "${SERVICE_NAME}"
sleep 2
systemctl --no-pager -l status "${SERVICE_NAME}"
