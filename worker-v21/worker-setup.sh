#!/usr/bin/env bash
# ============================================================
# EuroPulse AutoPilot v21 — Worker Setup Script
# Installs Python deps and creates systemd service
# Run as: sudo bash worker-setup.sh
# ============================================================

set -euo pipefail

WORKER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVICE_NAME="epv2-worker"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
ENV_FILE="/etc/default/${SERVICE_NAME}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
VENV_DIR="${WORKER_DIR}/.venv"
WP_LOAD="${WP_LOAD:-/var/www/europulse/public/wp-load.php}"

echo "====================================================="
echo " EuroPulse AutoPilot v21 — Worker Setup"
echo " Directory: ${WORKER_DIR}"
echo "====================================================="

WORKER_TOKEN="${EPV2_WORKER_TOKEN:-}"
if [[ -z "${WORKER_TOKEN}" && -f "${WP_LOAD}" ]]; then
  WORKER_TOKEN="$(php -r "define('WP_USE_THEMES', false); require '${WP_LOAD}'; echo EPV2_Settings::worker_shared_secret();" 2>/dev/null || true)"
fi

if [[ -z "${WORKER_TOKEN}" ]]; then
  echo "ERROR: Unable to resolve EPV2 worker token"
  exit 1
fi

# --- Check root ---
if [[ $EUID -ne 0 ]]; then
  echo "ERROR: This script must be run as root (sudo bash worker-setup.sh)"
  exit 1
fi

# --- Detect Python ---
for py in python3.11 python3.10 python3.9 python3; do
  if command -v "$py" &>/dev/null; then
    PYTHON_BIN="$py"
    break
  fi
done
echo "[1/5] Using Python: $($PYTHON_BIN --version)"

# --- Create venv ---
echo "[2/5] Creating virtual environment at ${VENV_DIR}"
"$PYTHON_BIN" -m venv "${VENV_DIR}"
source "${VENV_DIR}/bin/activate"

# --- Install dependencies ---
echo "[3/5] Installing Python dependencies"
pip install --upgrade pip --quiet
pip install -r "${WORKER_DIR}/requirements.txt" --quiet
echo "      Dependencies installed."

# --- Fix permissions ---
echo "[4/5] Setting file permissions"
WEB_USER="www-data"
if id "$WEB_USER" &>/dev/null; then
  # Worker dir needs to be readable by web user (for WordPress to check health)
  # but writable only by root
  chown -R root:root "${WORKER_DIR}"
  chmod -R 755 "${WORKER_DIR}"
  # Log directory writeable by www-data (for future file logging)
  mkdir -p "${WORKER_DIR}/logs"
  chown "${WEB_USER}:${WEB_USER}" "${WORKER_DIR}/logs"
  chmod 775 "${WORKER_DIR}/logs"
  echo "      Permissions set (owner: root, logs writeable by ${WEB_USER})"
else
  echo "      WARNING: ${WEB_USER} user not found, skipping permission fix"
fi

# --- Create systemd service ---
echo "[5/5] Creating systemd service: ${SERVICE_NAME}"
cat > "${ENV_FILE}" << ENVEOF
EPV2_WORKER_TOKEN=${WORKER_TOKEN}
PYTHONPATH=${WORKER_DIR}/src
ENVEOF
chmod 600 "${ENV_FILE}"
chown root:root "${ENV_FILE}"

cat > "${SERVICE_FILE}" << SVCEOF
[Unit]
Description=EuroPulse AutoPilot v21 Python Worker
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=${WORKER_DIR}/src
EnvironmentFile=${ENV_FILE}
ExecStart=${VENV_DIR}/bin/python -m epv2_worker
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=${SERVICE_NAME}
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
SVCEOF
chmod 644 "${SERVICE_FILE}"
chown root:root "${SERVICE_FILE}"

systemctl daemon-reload
systemctl enable "${SERVICE_NAME}"
systemctl restart "${SERVICE_NAME}"

sleep 2

# --- Verify ---
if systemctl is-active --quiet "${SERVICE_NAME}"; then
  echo ""
  echo "✅ Worker service is RUNNING"
  echo "   Status: systemctl status ${SERVICE_NAME}"
  echo "   Logs:   journalctl -u ${SERVICE_NAME} -f"
  echo "   Health: curl -s http://127.0.0.1:8765/health"
else
  echo ""
  echo "⚠️  Worker service failed to start. Check logs:"
  echo "   journalctl -u ${SERVICE_NAME} -n 50"
  exit 1
fi
