#!/usr/bin/env bash
# deploy-step.sh <repo path> — fallback for deploy-file.sh when the FTP server answers "Not connected" to a
# put+mv in one session (2026-10-17, 2026-11-03): ONE lftp session per operation — put to <name>.deploying,
# cls size check against the local file, mv, cls again. Stops on any mismatch; the live file is never half-written.
# Same path mapping as deploy-file.sh (public/<x> → /<x>, app/<x> → /app/<x>). Then hit /crm/api/opcache-reset.php.
set -u
cd "$(git rev-parse --show-toplevel)" || exit 2
. scripts/lib/ftp.sh
LOCAL="$1"
case "$LOCAL" in
  public/*) REMOTE="/${LOCAL#public/}" ;;
  app/*)    REMOTE="/$LOCAL" ;;
  *) echo "refused: $LOCAL"; exit 2 ;;
esac
. scripts/lib/feature-gate.sh   # only what is on feature/language goes live; merges it there first (2026-11-06)
fg_gate "$LOCAL" || exit 1
DIR="$(dirname "$REMOTE")/"; NAME="$(basename "$REMOTE")"; TMP="$NAME.deploying"
SIZE=$(stat -f %z "$LOCAL")
run() { mw_lftp "$1; quit" 2>&1; }   # one shared-lib session per operation (TLS verification on, Keychain password)
run "put -O '$DIR' '$LOCAL' -o '$TMP'" | grep -iE "error|not connected|fail" && { echo "PUT FAILED $LOCAL"; exit 1; }
RS=$(run "cls -l '$DIR$TMP'" | awk '{print $4}' | tail -1)
[ "$RS" = "$SIZE" ] || { echo "SIZE MISMATCH $LOCAL local=$SIZE remote=$RS"; exit 1; }
run "mv '$DIR$TMP' '$DIR$NAME'" | grep -iE "error|not connected|fail" && { echo "MV FAILED $LOCAL"; exit 1; }
RS2=$(run "cls -l '$DIR$NAME'" | awk '{print $4}' | tail -1)
[ "$RS2" = "$SIZE" ] && echo "DEPLOYED $LOCAL ($SIZE bytes) -> $REMOTE" || { echo "POST-CHECK MISMATCH $LOCAL remote=$RS2"; exit 1; }
# Record the deploy for Ada's drift check (never fails the deploy). Until 2026-11-03 only deploy-checked.sh did this,
# so every file shipped this way read as "changed on the server" — 99 ghosts by Nov 3.
python3 scripts/lib/deploy_manifest.py "$LOCAL" || echo "WARNING: deploy manifest not updated (Ada's drift check will flag $LOCAL)"
