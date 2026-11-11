#!/usr/bin/env bash
# prod-restore.sh — restore a prod-backup.sh dump into a LOCAL MySQL database, and prove it (2026-10-12).
#
# Why (security audit 2026-10-12 #6): a backup that has never been restored is a hope, not a backup.
# This is the restore AND the monthly drill: it loads the dump, then compares every table's row
# count with the count the dump recorded ("-- rows `t` N" lines written by prod-backup.sh).
#
# Usage:
#   scripts/prod-restore.sh                                  # newest dump → mowology_restore_test
#   scripts/prod-restore.sh FILE.sql.gz [db_name]
#   scripts/prod-restore.sh --drill                          # newest dump → scratch DB, verify, DROP it
#   scripts/prod-restore.sh FILE.sql.gz db_name --replace    # allow overwriting a non-empty DB
#
# LOCAL ONLY. Refuses any MYSQL_HOST that is not localhost / 127.0.0.1 / ::1, refuses the
# production database name, and refuses a non-empty target DB unless --replace.
# Connection (defaults match scripts/local-site.sh): MYSQL_SOCKET=~/.mowology-local/mysql.sock,
# MYSQL_USER=root, MYSQL_BIN=mysql in PATH or MAMP's mysql80. MYSQL_HOST/MYSQL_PORT use TCP instead.
set -euo pipefail

DRILL=0; REPLACE=0; ARGS=()
for a in "$@"; do
  case "$a" in
    --drill) DRILL=1 ;;
    --replace) REPLACE=1 ;;
    -h|--help) sed -n 2,18p "$0"; exit 0 ;;
    *) ARGS+=("$a") ;;
  esac
done
BACKUPS="${BACKUP_DIR:-$HOME/Backups/mowology}"
FILE="${ARGS[0]:-$(ls -1t "$BACKUPS"/mowology-db-*.sql.gz 2>/dev/null | head -1)}"
DB="${ARGS[1]:-mowology_restore_test}"
[ -n "$FILE" ] && [ -f "$FILE" ] || { echo "no dump found (looked in $BACKUPS)" >&2; exit 2; }

# ── Local-only guard ─────────────────────────────────────────────────────────
HOST="${MYSQL_HOST:-}"
case "$HOST" in
  ""|localhost|127.0.0.1|::1) ;;
  *) echo "refused: MYSQL_HOST=$HOST is not local — this script only restores into a local MySQL" >&2; exit 3 ;;
esac
[[ "$DB" =~ ^[A-Za-z0-9_]+$ ]] || { echo "refused: bad database name '$DB'" >&2; exit 3; }
case "$DB" in
  mowology_landscape_crm|mowology_*crm) echo "refused: '$DB' is the production database name" >&2; exit 3 ;;
esac

MYSQL_BIN="${MYSQL_BIN:-$(command -v mysql || echo /Applications/MAMP/Library/bin/mysql80/bin/mysql)}"
[ -x "$MYSQL_BIN" ] || { echo "mysql client not found (set MYSQL_BIN)" >&2; exit 2; }
CONN=(-u "${MYSQL_USER:-root}")
if [ -n "$HOST" ]; then CONN+=(-h "$HOST" -P "${MYSQL_PORT:-3306}" --protocol=TCP)
else CONN+=(--socket="${MYSQL_SOCKET:-$HOME/.mowology-local/mysql.sock}"); fi
[ -n "${MYSQL_PWD:-}" ] && export MYSQL_PWD   # password via env only, never argv
q() { "$MYSQL_BIN" "${CONN[@]}" -N -B -e "$1" "${2:-}"; }

# Belt and braces: ask the server itself where it is.
srv_host="$(q 'SELECT @@hostname')"
[ "$srv_host" = "$(hostname)" ] || [ "$srv_host" = "$(hostname -s)" ] || [ "${srv_host%%.*}" = "$(hostname -s)" ] \
  || { echo "refused: server reports hostname '$srv_host', not this Mac ($(hostname -s))" >&2; exit 3; }

# ── Dump sanity ──────────────────────────────────────────────────────────────
gzip -t "$FILE" || { echo "corrupt gzip: $FILE" >&2; exit 4; }
gunzip -c "$FILE" | tail -c 300 | grep -q -- '^-- end$' || { echo "dump has no '-- end' line (incomplete): $FILE" >&2; exit 4; }

if [ $DRILL -eq 1 ] && [ -z "${ARGS[1]:-}" ]; then DB="mowology_restore_drill_$(date +%s)"; fi
existing="$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB'")"
if [ "$existing" -gt 0 ] && [ $REPLACE -eq 0 ]; then
  echo "refused: database '$DB' already has $existing tables — pass --replace to overwrite" >&2; exit 5
fi

echo "Restoring $(basename "$FILE") ($(du -h "$FILE" | cut -f1)) → local '$DB' …"
q "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
start=$(date +%s)
gunzip -c "$FILE" | "$MYSQL_BIN" "${CONN[@]}" --max-allowed-packet=256M "$DB"
echo "loaded in $(( $(date +%s) - start ))s"

# ── Verify: every table's row count must equal what the dump recorded ───────
EXP="$(mktemp)"; trap 'rm -f "$EXP"' EXIT
gunzip -c "$FILE" | sed -n 's/^-- rows `\(.*\)` \([0-9]*\)$/\1 \2/p' > "$EXP"
tables=0; rows=0; bad=0
while read -r t n; do
  got="$(q "SELECT COUNT(*) FROM \`$t\`" "$DB")"
  tables=$((tables + 1)); rows=$((rows + got))
  [ "$got" = "$n" ] || { echo "MISMATCH $t: dump $n, restored $got"; bad=$((bad + 1)); }
done < "$EXP"
views="$(q "SELECT COUNT(*) FROM information_schema.views WHERE table_schema='$DB'")"
echo "verified: $tables tables, $views views, $rows rows; mismatches: $bad"

if [ $DRILL -eq 1 ]; then
  q "DROP DATABASE \`$DB\`"; echo "drill database '$DB' dropped"
fi
[ $bad -eq 0 ] || exit 6
