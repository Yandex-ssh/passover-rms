#!/usr/bin/env bash
set -euo pipefail

: "${MYSQL_ROOT_PASSWORD:?Set MYSQL_ROOT_PASSWORD before restoring}"
: "${RESTORE_TARGET_DATABASE:?Set RESTORE_TARGET_DATABASE before restoring}"
DUMP_FILE="${1:?Usage: restore_database.sh /path/to/dump.sql}"
SERVICE="${MYSQL_SERVICE:-mysql}"

if [[ ! "$RESTORE_TARGET_DATABASE" =~ ^passover_restore_[A-Za-z0-9_]+$ ]]; then
  printf 'Refusing restore: target must match passover_restore_<safe-name>\n' >&2
  exit 1
fi
if [[ "${ALLOW_DESTRUCTIVE_RESTORE:-}" != "YES" ]]; then
  printf 'Refusing restore: set ALLOW_DESTRUCTIVE_RESTORE=YES for an isolated restore target.\n' >&2
  exit 1
fi
test -s "$DUMP_FILE"

docker compose exec -T "$SERVICE" mysql -uroot -p"$MYSQL_ROOT_PASSWORD" \
  -e "CREATE DATABASE IF NOT EXISTS $RESTORE_TARGET_DATABASE;"
docker compose exec -T "$SERVICE" mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$RESTORE_TARGET_DATABASE" < "$DUMP_FILE"
printf 'Restore completed into isolated database: %s\n' "$RESTORE_TARGET_DATABASE"
