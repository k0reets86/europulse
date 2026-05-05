#!/usr/bin/env bash
set -euo pipefail

echo "EuroPulse cleanup preparation manifest"
echo

echo "[KEEP]"
cat <<'EOF'
/var/www/europulse/public
/root/projects/europulse/wp-plugins/europulse-autopilot-v21
/root/projects/europulse/worker-v21
/root/projects/europulse/docs/active-runtime-boundary-2026-04-10.md
/etc/systemd/system/epv2-worker.service
/etc/systemd/system/epv2-orchestrator.service
/etc/systemd/system/europulse-wp-cron.timer
/etc/systemd/system/epv2-runtime-watch.timer
EOF

echo
echo "[SAFE DELETE OR ROTATE]"
cat <<'EOF'
/var/www/europulse/disabled-plugins
/var/www/europulse/sandbox-v21
/root/projects/europulse/backups/20260317-001937-site-audit
/root/projects/europulse/backups/20260317-233655-post-cleanup
/root/projects/europulse/backups/20260318-093654-post-clean-audit
/root/projects/europulse/node_modules
APT cache: /var/cache/apt
systemd journal: /var/log/journal
nginx cache: /var/cache/nginx/europulse
EOF

echo
echo "[REVIEW BEFORE DELETE]"
cat <<'EOF'
/var/www/europulse/public/wp-content/uploads
/root/projects/europulse/docs
/root/projects/europulse/scripts
EOF

echo
echo "[TOP LARGE FILES IN PROJECT]"
find /root/projects/europulse -type f -printf '%s %p\n' | sort -nr | head -n 40
