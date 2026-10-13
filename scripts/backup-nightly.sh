#!/usr/bin/env bash
# backup-nightly.sh — what the launchd agent ca.mowology.backup runs at 02:30 (2026-10-12).
#
#   1. scripts/prod-backup.sh        database dump → ~/Backups/mowology/ (keeps the newest 14)
#   2. scripts/prod-backup-files.sh  uploads, _media, storage PDFs/docs → ~/Backups/mowology/files/
#
# Log: ~/Backups/mowology/backup.log. A failure raises a macOS notification so it cannot go quiet.
# Install / re-point the agent: scripts/install-backup-agent.sh (run it from the checkout to use).
set -uo pipefail
cd "$(dirname "$0")/.."
export PATH="/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin"
LOG="${BACKUP_DIR:-$HOME/Backups/mowology}/backup.log"
mkdir -p "$(dirname "$LOG")"; chmod 700 "$(dirname "$LOG")"

fail=0
{
  echo "=== $(date '+%Y-%m-%d %H:%M:%S') nightly backup ($(git rev-parse --short HEAD 2>/dev/null))"
  BACKUP_KEEP="${BACKUP_KEEP:-14}" scripts/prod-backup.sh || { echo "DB BACKUP FAILED"; fail=1; }
  scripts/prod-backup-files.sh || { echo "FILE BACKUP FAILED"; fail=1; }
  echo "=== done (fail=$fail)"
} >> "$LOG" 2>&1

if [ $fail -ne 0 ]; then
  osascript -e 'display notification "Nightly Mowology backup FAILED — see ~/Backups/mowology/backup.log" with title "Mowology backup" sound name "Basso"' 2>/dev/null || true
fi
exit $fail
