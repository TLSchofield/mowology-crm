#!/usr/bin/env bash
# prod-pull.sh — download the LIVE copy of repo files from production (2026-10-13).
#
# For when production is ahead of this branch (another session deployed from its own branch):
# edit the live copy, not the stale repo one, then deploy with deploy-merged.py.
#
#   scripts/prod-pull.sh public/crm/js/action-board.js app/Modules/X/Y.php   # → <scratch>/prod-pull/<path>
#   scripts/prod-pull.sh --into-repo public/crm/js/action-board.js           # overwrite the repo file with live
#
# Read-only on production. Prints where each file landed, or PROD-MISSING.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"
. "$(cd "$(dirname "$0")" && pwd)/lib/ftp.sh"
mw_ftp_check

INTO_REPO=0
if [ "${1:-}" = "--into-repo" ]; then INTO_REPO=1; shift; fi
[ $# -gt 0 ] || { echo "usage: scripts/prod-pull.sh [--into-repo] <repo path>…" >&2; exit 2; }

OUT="${TMPDIR:-/tmp}/prod-pull"
mkdir -p "$OUT"

remote_of() {   # public/ IS public_html; everything else sits inside it
  case "$1" in
    public/*) echo "/${1#public/}" ;;
    *)        echo "/$1" ;;
  esac
}

GETS=""
for f in "$@"; do
  mkdir -p "$OUT/$(dirname "$f")"
  GETS+="get $(remote_of "$f") -o $OUT/$f; "
done
mw_lftp "set net:max-retries 3; set cmd:fail-exit no; ${GETS} quit" 2>&1 >/dev/null \
  | grep -i -E "fatal|certificate|login" >&2 || true

status=0
for f in "$@"; do
  if [ ! -s "$OUT/$f" ]; then
    printf 'PROD-MISSING  %s\n' "$f"; status=1
  elif [ $INTO_REPO -eq 1 ]; then
    mkdir -p "$(dirname "$f")"; cp "$OUT/$f" "$f"; printf 'into repo     %s\n' "$f"
  else
    printf 'pulled        %s → %s\n' "$f" "$OUT/$f"
  fi
done
exit $status
