#!/usr/bin/env bash
#
# prod-file.sh — compare specific files against production. READ-ONLY.
#
#   scripts/prod-file.sh public/crm/js/capacitor-bridge.js
#   scripts/prod-file.sh public/service-worker.js public/crm/homebase.php
#   scripts/prod-file.sh -d public/crm/api/tags.php        # -d: show the full diff
#
# Why this exists: prod-drift.sh answers "what has drifted in this DIRECTORY",
# which means mirroring the whole tree — minutes, and useless when you are about
# to push three known files. The rule "ALWAYS cmp a file against prod before
# deploying it" needs something that costs seconds, or it gets skipped, and the
# one time it got skipped (global-search.php, 2026-09-25) it was safe by luck.
#
# Exit status: 0 = every file matches, 1 = at least one DIFFERS or is MISSING.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

SHOW_DIFF=0
if [ "${1:-}" = "-d" ]; then SHOW_DIFF=1; shift; fi
[ $# -gt 0 ] || { echo "usage: $0 [-d] <repo-path> [repo-path...]" >&2; exit 2; }

FTP_PASS="$(git config git-ftp.password)" || { echo "git config git-ftp.password is not set" >&2; exit 1; }
TMP="$(mktemp -d "${TMPDIR:-/tmp}/prod-file.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

# public/ IS public_html; everything else (app/, scripts/, …) sits inside it.
remote_of() {
  case "$1" in
    public)   echo "/" ;;
    public/*) echo "/${1#public/}" ;;
    *)        echo "/$1" ;;
  esac
}

GETS=""
for f in "$@"; do
  [ -f "$f" ] || { echo "not a file in this repo: $f" >&2; exit 2; }
  mkdir -p "$TMP/$(dirname "$f")"
  GETS+="get -O $TMP/$(dirname "$f") $(remote_of "$f") -o $TMP/$f; "
done

# `set cmd:fail-exit no` so ONE missing file does not abort the rest of the batch;
# a file that never arrives is reported as PROD-MISSING below.
lftp -u "claude@mowology.ca,$FTP_PASS" \
     -e "set ssl:verify-certificate no; set ftp:ssl-force true; set net:max-retries 3; set cmd:fail-exit no; ${GETS} quit" \
     ftp://ftp.mowology.ca >/dev/null 2>&1 || true

status=0
for f in "$@"; do
  if [ ! -f "$TMP/$f" ]; then
    printf 'PROD-MISSING  %s\n' "$f"; status=1
  elif cmp -s "$TMP/$f" "$f"; then
    printf 'same          %s\n' "$f"
  else
    printf 'DIFFERS       %s  (prod %s bytes, repo %s bytes)\n' \
      "$f" "$(wc -c <"$TMP/$f" | tr -d ' ')" "$(wc -c <"$f" | tr -d ' ')"
    status=1
    if [ "$SHOW_DIFF" = "1" ]; then
      diff -u "$TMP/$f" "$f" | sed 's/^/    /' || true
    fi
  fi
done
exit $status
