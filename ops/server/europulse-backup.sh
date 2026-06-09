#!/usr/bin/env bash
# EuroPulse daily backup — DB + uploads
# Local rotation as immediate safety net. Pair with UpdraftPlus → remote
# cloud для full disaster recovery.
set -euo pipefail

BACKUP_DIR=/var/backups/europulse
DB_NAME=europulse
DB_USER=europulse_wp
DB_PASS='XQ/ZGXipV82vWw2Ts/v3kuqCT4qNkqoZ'
DB_HOST=localhost
RETENTION_DAYS=14
WP_PATH=/var/www/europulse/public
UPLOADS_DIR="$WP_PATH/wp-content/uploads"
TS=$(date -u +%Y%m%d-%H%M%S)

mkdir -p "$BACKUP_DIR"

# 1. DB dump (compressed, single-transaction для консистентности).
DB_FILE="$BACKUP_DIR/db-${TS}.sql.gz"
mysqldump \
  --host="$DB_HOST" \
  --user="$DB_USER" \
  --password="$DB_PASS" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --default-character-set=utf8mb4 \
  --hex-blob \
  "$DB_NAME" 2>/tmp/europulse-backup.err | gzip -9 > "$DB_FILE"

DB_SIZE=$(du -h "$DB_FILE" | cut -f1)
echo "[$(date -u +%H:%M:%S)] DB dump: $DB_FILE ($DB_SIZE)"

# 2. Uploads tarball — раз в неделю (по воскресеньям UTC), чтобы не дублировать.
if [[ $(date -u +%u) -eq 7 ]]; then
  UP_FILE="$BACKUP_DIR/uploads-${TS}.tar.gz"
  tar -czf "$UP_FILE" -C "$WP_PATH/wp-content" uploads 2>/tmp/europulse-backup.err
  UP_SIZE=$(du -h "$UP_FILE" | cut -f1)
  echo "[$(date -u +%H:%M:%S)] Uploads tarball: $UP_FILE ($UP_SIZE)"
fi

# 3. Ротация: DB-дампы старше RETENTION_DAYS, uploads-тарболы старше 7 дней
# (uploads дублируются UpdraftPlus'ом — хватит одного локального тарбола).
find "$BACKUP_DIR" -type f -name 'db-*.sql.gz' -mtime +$RETENTION_DAYS -delete
find "$BACKUP_DIR" -type f -name 'uploads-*.tar.gz' -mtime +7 -delete

# 4. Sanity report — last N backups.
echo "Latest backups:"
ls -lh "$BACKUP_DIR" | tail -10
