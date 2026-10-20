#!/usr/bin/env bash
# Respaldo diario de PostgreSQL. Corre como el usuario postgres (cron).
# Formato custom (-Fc): comprimido y restaurable por tabla.
set -euo pipefail

DB="${DB_NAME:-trazabilidad}"
DIR="${BACKUP_DIR:-/var/backups/trazabilidad}"
DAYS="${RETENTION_DAYS:-30}"
FILE="$DIR/$DB-$(date +%Y%m%d-%H%M%S).dump"

umask 077
mkdir -p "$DIR"

pg_dump --format=custom --no-owner --file="$FILE.partial" "$DB"
mv "$FILE.partial" "$FILE"

# Un respaldo que no se puede leer no es respaldo.
pg_restore --list "$FILE" > /dev/null

find "$DIR" -name "$DB-*.dump" -mtime +"$DAYS" -delete
echo "$(date -Is) respaldo ok: $FILE ($(du -h "$FILE" | cut -f1))"
