#!/usr/bin/env bash
set -euo pipefail

: "${MYSQL_ROOT_PASSWORD:?Set MYSQL_ROOT_PASSWORD before backing up}"
DATABASE="${MYSQL_DATABASE:-passover}"
SERVICE="${MYSQL_SERVICE:-mysql}"
OUTPUT="${1:-backups/${DATABASE}-$(date +%Y-%m-%d_%H%M%S).sql}"
mkdir -p "$(dirname "$OUTPUT")"

docker compose exec -T "$SERVICE" mysqldump \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --no-tablespaces \
  -uroot -p"$MYSQL_ROOT_PASSWORD" "$DATABASE" > "$OUTPUT"

test -s "$OUTPUT"
printf 'Backup written: %s\n' "$OUTPUT"
