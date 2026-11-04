#!/usr/bin/env bash
# =============================================================================
# scripts/deploy-migration.sh
# =============================================================================
# Upload ONE migration file to production's database/migrations/ folder, where the
# CRM's Migrations tab lists it ("Execute" there runs it). It does NOT run the SQL.
#
# deploy-file.sh only maps public/ and app/, so it refuses database/. This is the
# matching one-purpose script: it accepts exactly database/migrations/<name>.sql.
#
# Usage:
#   scripts/deploy-migration.sh database/migrations/1380_invoice_refunds.sql
#   scripts/deploy-migration.sh --dry-run database/migrations/1380_invoice_refunds.sql
#
# Credentials come from scripts/lib/ftp.sh (Keychain). One lftp session; the size on
# the server is checked afterwards and must equal the local size.
# =============================================================================
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"
# shellcheck source=scripts/lib/ftp.sh
. "$REPO_ROOT/scripts/lib/ftp.sh"

DRY_RUN=0
if [ "${1:-}" = "--dry-run" ]; then DRY_RUN=1; shift; fi
LOCAL="${1:-}"

[ -n "$LOCAL" ] || { echo "usage: $0 [--dry-run] database/migrations/<name>.sql" >&2; exit 2; }
case "$LOCAL" in
  database/migrations/*.sql) ;;
  *) echo "ERROR: only database/migrations/*.sql is accepted (got: $LOCAL)" >&2; exit 3 ;;
esac
case "$LOCAL" in *..*) echo "ERROR: '..' is not allowed" >&2; exit 3 ;; esac
[ -f "$LOCAL" ] || { echo "ERROR: local file does not exist: $LOCAL" >&2; exit 2; }

NAME="$(basename "$LOCAL")"
case "$NAME" in
  *[!A-Za-z0-9_.-]*) echo "ERROR: unexpected characters in file name: $NAME" >&2; exit 3 ;;
esac
REMOTE_DIR="/database/migrations/"
LOCAL_SIZE="$(wc -c <"$LOCAL" | tr -d ' ')"

echo "migration: $LOCAL  ->  ${REMOTE_DIR}${NAME}  (${LOCAL_SIZE} bytes)"
if [ "$DRY_RUN" = "1" ]; then echo "dry run, nothing uploaded"; exit 0; fi

mw_ftp_check
mw_lftp "put -O ${REMOTE_DIR} ${LOCAL}; quit"

# The size is the field just before the month name; the column count varies by server.
REMOTE_SIZE="$(mw_lftp "cls -l ${REMOTE_DIR}${NAME}; quit" | awk '{for(i=2;i<=NF;i++) if($i ~ /^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)$/){print $(i-1); exit}}')"
if [ "$REMOTE_SIZE" != "$LOCAL_SIZE" ]; then
  echo "ERROR: size mismatch — local ${LOCAL_SIZE}, server '${REMOTE_SIZE}'. Do not run this migration." >&2
  exit 5
fi
echo "uploaded and verified: ${NAME} (${REMOTE_SIZE} bytes). Run it from the CRM Migrations tab."
