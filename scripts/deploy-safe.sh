#!/usr/bin/env bash
# deploy-safe.sh — upload files to production one FTP operation per session (2026-10-19).
#
# Why: deploy-file.sh puts and renames in ONE lftp session, and on this host the second command
# often fails with "Not connected" — leaving a stray .deploying file, or worse, nothing. The steps
# that work (memory feedback_ftp_one_op_per_session) are: put to <name>.deploying, check its size
# (cls -l, field 4) matches the local file, then mv over the live file — each its own session.
#
# Usage:
#   scripts/deploy-safe.sh public/crm/ops/equipment-item.php app/Modules/Foo/Bar.php ...
#   scripts/deploy-safe.sh /tmp/patched.php:public/crm/expenses_appstack.php   # SOURCE:REPO_PATH
# The repo path decides where it goes: public/x → /x, app/x → /app/x.
# Compare each file with its live copy BEFORE running this (CLAUDE.md: check the live copy first).
set -uo pipefail
cd "$(dirname "$0")/.."
source scripts/lib/ftp.sh

fail=0
for arg in "$@"; do
    src="${arg%%:*}"; repo="${arg#*:}"
    [[ "$arg" == *:* ]] || { src="$arg"; repo="$arg"; }
    [[ -f "$src" ]] || { echo "MISSING  $src"; fail=1; continue; }
    case "$repo" in
        public/*) remote="/${repo#public/}" ;;
        app/*)    remote="/$repo" ;;
        *) echo "SKIP     $repo (only public/ and app/ deploy)"; fail=1; continue ;;
    esac
    dir="$(dirname "$remote")"; name="$(basename "$remote")"
    size=$(wc -c < "$src" | tr -d ' ')

    mw_lftp "mkdir -p -f '$dir'; put '$src' -o '$remote.deploying'; bye" >/dev/null 2>&1
    got=$(mw_lftp "cls -l '$remote.deploying'; bye" 2>/dev/null | awk '{print $4}' | head -1)
    if [[ "$got" != "$size" ]]; then
        echo "FAILED   $remote (uploaded size '${got:-none}' ≠ $size; live file untouched)"; fail=1; continue
    fi
    mw_lftp "mv '$remote.deploying' '$remote'; bye" >/dev/null 2>&1
    now=$(mw_lftp "cls -l '$remote'; bye" 2>/dev/null | awk '{print $4}' | head -1)
    if [[ "$now" == "$size" ]]; then echo "LIVE     $remote ($size bytes)"; else echo "CHECK    $remote (size after mv '${now:-none}')"; fail=1; fi
done
exit $fail
