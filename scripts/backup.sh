#!/usr/bin/env bash
# =============================================================================
# scripts/backup.sh
# OK Veggies. Dump the database and archive the uploads folder into ~/backups.
# Reads DB credentials from .env. Keeps the 14 most recent backups.
# =============================================================================
set -uo pipefail

APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$APP_ROOT/.env"
[ -f "$ENV_FILE" ] || { echo "[backup] no .env"; exit 2; }

getenv() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2-; }
DB_HOST="$(getenv DB_HOST)"; DB_NAME="$(getenv DB_NAME)"
DB_USER="$(getenv DB_USER)"; DB_PASS="$(getenv DB_PASS)"

DEST="${BACKUP_DIR:-$HOME/backups}"
mkdir -p "$DEST"
STAMP="$(date +%Y%m%d_%H%M%S)"

echo "[backup] dumping database $DB_NAME ..."
mysqldump --no-tablespaces -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$DEST/okveggies_db_$STAMP.sql" \
  && gzip -f "$DEST/okveggies_db_$STAMP.sql" && echo "[backup] db -> $DEST/okveggies_db_$STAMP.sql.gz"

if [ -d "$APP_ROOT/uploads" ]; then
  tar czf "$DEST/okveggies_uploads_$STAMP.tar.gz" -C "$APP_ROOT" uploads \
    && echo "[backup] uploads -> $DEST/okveggies_uploads_$STAMP.tar.gz"
fi

# Keep the 14 newest of each kind.
ls -1t "$DEST"/okveggies_db_*.sql.gz 2>/dev/null      | tail -n +15 | xargs -r rm -f
ls -1t "$DEST"/okveggies_uploads_*.tar.gz 2>/dev/null | tail -n +15 | xargs -r rm -f
echo "[backup] done."
