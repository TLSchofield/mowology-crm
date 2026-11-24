#!/usr/bin/env bash
# backup-nightly.sh — what the launchd agent ca.mowology.backup runs at 02:30 (2026-10-12).
#
#   1. scripts/prod-backup.sh        database dump → ~/Backups/mowology/ (keeps the newest 14)
#   2. scripts/prod-backup-files.sh  uploads, _media, storage PDFs/docs → ~/Backups/mowology/files/
#   3. after each, a heartbeat to the server (scripts/lib/heartbeat.sh → /crm/api/backup-heartbeat.php),
#      so Ada's nightly security checks can see the backups the server cannot (2026-10-13);
#   4. scripts/composer-audit-report.sh  composer audit → the same endpoint (kind 'composer').
#
# Log: ~/Backups/mowology/backup.log. A failure raises a macOS notification so it cannot go quiet.
# Install / re-point the agent: scripts/install-backup-agent.sh (run it from the checkout to use).
set -uo pipefail
cd "$(dirname "$0")/.."
export PATH="/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin"
BDIR="${BACKUP_DIR:-$HOME/Backups/mowology}"
LOG="$BDIR/backup.log"
. scripts/lib/heartbeat.sh
mkdir -p "$(dirname "$LOG")"; chmod 700 "$(dirname "$LOG")"

fail=0
{
  echo "=== $(date '+%Y-%m-%d %H:%M:%S') nightly backup ($(git rev-parse --short HEAD 2>/dev/null))"
  dbok=1; BACKUP_KEEP="${BACKUP_KEEP:-14}" scripts/prod-backup.sh || { echo "DB BACKUP FAILED"; fail=1; dbok=0; }
  newest="$(ls -1t "$BDIR"/mowology-db-*.sql.gz 2>/dev/null | head -1)"
  # A dump older than 2 h is not tonight's: report it as a failure even if the script said ok.
  if [ -z "$newest" ] || [ -n "$(find "$newest" -mmin +120 2>/dev/null)" ]; then dbok=0; fi
  mw_heartbeat db "$dbok" "$( [ -n "$newest" ] && stat -f %z "$newest" || echo '')" "" "$(basename "${newest:-none}")"
  # Read-back (audit-support, 2026-11-03): a backup is not a control until it has been opened. gzip-test the
  # dump and count its tables; Ada compares the count with the live database (posture:backup-verified).
  vok=0; vtables=""; vdetail="no dump"
  if [ -n "$newest" ] && [ "$dbok" = 1 ]; then
    if gzip -t "$newest" 2>/dev/null; then
      vtables="$(gzip -dc "$newest" | grep -c '^CREATE TABLE' || true)"
      [ "${vtables:-0}" -gt 0 ] && vok=1
      vdetail="$(basename "$newest"): gzip ok, $vtables tables"
    else
      vdetail="$(basename "$newest"): gzip test FAILED"; fail=1
    fi
  fi
  echo "read-back: $vdetail"
  mw_heartbeat verify "$vok" "" "$vtables" "$vdetail"
  fok=1; scripts/prod-backup-files.sh || { echo "FILE BACKUP FAILED"; fail=1; fok=0; }
  mw_heartbeat files "$fok" "$(du -sk "$BDIR/files" 2>/dev/null | awk '{print $1*1024}')" "" ""
  scripts/composer-audit-report.sh || echo "composer audit report failed (not a backup failure)"
  echo "=== done (fail=$fail)"
} >> "$LOG" 2>&1

if [ $fail -ne 0 ]; then
  osascript -e 'display notification "Nightly Mowology backup FAILED — see ~/Backups/mowology/backup.log" with title "Mowology backup" sound name "Basso"' 2>/dev/null || true
fi
exit $fail
