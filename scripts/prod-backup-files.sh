#!/usr/bin/env bash
# prod-backup-files.sh — copy production's uploaded files to this Mac. DOWNLOAD ONLY (2026-10-12).
#
# Why (security audit 2026-10-12 #6): receipts, job photos, signatures and _media existed only on
# the shared host. The CRA wants receipts kept 7 years.
#
# Usage:  scripts/prod-backup-files.sh        # → ~/Backups/mowology/files/<remote path>/
#
# What it copies (remote → local, relative to the FTP root = public_html):
#   /uploads                    public/uploads   (receipts, photos, signatures, cms, quiz …)
#   /_media                     public/_media    (media library originals + renditions)
#   /app/Storage/company-docs   company documents
#   /app/Storage/supplier-forms supplier forms
#   /app/Storage/pdfs           invoices, salt/trip reports, proof-of-work PDFs
#
# Safety: lftp `mirror` WITHOUT -R (so it only ever downloads), WITHOUT --delete (a file removed on
# the server is never removed here — the Mac copy is the archive) and WITHOUT --Remove-source-files.
# tests/Unit/Docs/BackupScriptsTest.php fails if any of those flags appear. Incremental: only new or
# changed files are fetched after the first run.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
. scripts/lib/ftp.sh
mw_ftp_check

DEST="${BACKUP_DIR:-$HOME/Backups/mowology}/files"
mkdir -p "$DEST"; chmod 700 "$DEST" "$(dirname "$DEST")"

SOURCES=(/uploads /_media /app/Storage/company-docs /app/Storage/supplier-forms /app/Storage/pdfs)
CMDS="set net:max-retries 3; set net:timeout 30; set cmd:fail-exit no;"
for src in "${SOURCES[@]}"; do
  mkdir -p "$DEST$src"
  CMDS+=" mirror --only-newer --no-perms --parallel=4 --verbose=0 $src $DEST$src;"
done

status=0
mw_lftp "$CMDS bye" 2>"$DEST/.last-errors.log" || status=$?
chmod -R go-rwx "$DEST"

errs="$(grep -c -i -E 'fatal|error|denied' "$DEST/.last-errors.log" || true)"
for src in "${SOURCES[@]}"; do
  printf '%-28s %6s files  %s\n' "$src" "$(find "$DEST$src" -type f | wc -l | tr -d ' ')" "$(du -sh "$DEST$src" | cut -f1)"
done
echo "TOTAL $(du -sh "$DEST" | cut -f1)  →  $DEST"
if [ "$status" -ne 0 ] || [ "$errs" -gt 0 ]; then
  echo "file backup finished WITH ERRORS ($errs) — see $DEST/.last-errors.log" >&2
  exit 1
fi
