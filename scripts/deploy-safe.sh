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
# Live fingerprints enforce that each upload was built on the last copy pulled. Tim 2026-10-24.
set -uo pipefail
cd "$(dirname "$0")/.."
source scripts/lib/ftp.sh
source scripts/lib/deploy-fingerprints.sh

CURRENT_DIR="$(mktemp -d "${TMPDIR:-/tmp}/deploy-safe.XXXXXX")"
trap 'fp_lock_release; rm -rf "$CURRENT_DIR"' EXIT

if ! fp_lock_acquire; then
    echo "FAILED   could not acquire deployment lock in $DEPLOY_STATE_DIR" >&2
    exit 1
fi

show_live_diff() {
    repo="$1"; current="$2"
    previous="${TMPDIR:-/tmp}/prod-pull/$repo"
    [ -f "$previous" ] || return 0
    if [ "$current" = "MISSING" ]; then
        diff -u "$previous" /dev/null 2>/dev/null | sed -n '1,30p' || true
    else
        diff -u "$previous" "$current" 2>/dev/null | sed -n '1,30p' || true
    fi
}

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

    if [ "${DEPLOY_FORCE:-0}" = "1" ]; then
        decision="ALLOW"
    else
        current="$CURRENT_DIR/$repo"
        mkdir -p "$(dirname "$current")"
        fetch_errors="$CURRENT_DIR/fetch-errors"
        if mw_lftp "set cmd:fail-exit yes; get '$remote' -o '$current'; bye" >/dev/null 2>"$fetch_errors"; then
            live_sha="$(shasum -a 256 "$current" | awk '{print $1}')"
            current_for_diff="$current"
        elif grep -qiE '550|not found|no such file' "$fetch_errors"; then
            live_sha="MISSING"
            current_for_diff="MISSING"
        else
            echo "FAILED   $remote (could not fetch current live file)"
            sed -n '1,3p' "$fetch_errors" >&2
            fail=1
            continue
        fi

        decision="$(fp_decide "$repo" "$live_sha")"
    fi
    if [ "$decision" = "REFUSE-NEVER" ]; then
        echo "REFUSED  $repo: never pulled — run scripts/prod-pull.sh $repo, build on it, then deploy"
        fail=1
        continue
    elif [ "$decision" = "REFUSE-CHANGED" ]; then
        last_record="$(fp_last_record "$repo")"
        last_time="$(printf '%s\n' "$last_record" | awk -F '\t' '{print $1}')"
        last_event="$(printf '%s\n' "$last_record" | awk -F '\t' '{print $4}')"
        last_who="$(printf '%s\n' "$last_record" | awk -F '\t' '{print $5}')"
        echo "REFUSED  $repo: LIVE CHANGED since $last_time ($last_event by $last_who) — pull it, merge, then deploy"
        show_live_diff "$repo" "$current_for_diff"
        fail=1
        continue
    fi

    mw_lftp "mkdir -p -f '$dir'; put '$src' -o '$remote.deploying'; bye" >/dev/null 2>&1
    got=$(mw_lftp "cls -l '$remote.deploying'; bye" 2>/dev/null | awk '{print $4}' | head -1)
    if [[ "$got" != "$size" ]]; then
        echo "FAILED   $remote (uploaded size '${got:-none}' ≠ $size; live file untouched)"; fail=1; continue
    fi
    mw_lftp "mv '$remote.deploying' '$remote'; bye" >/dev/null 2>&1
    now=$(mw_lftp "cls -l '$remote'; bye" 2>/dev/null | awk '{print $4}' | head -1)
    if [[ "$now" == "$size" ]]; then
        if fp_record "$repo" "$src" deployed; then
            echo "LIVE     $remote ($size bytes)"
        else
            echo "FAILED   $remote is live, but its deployment fingerprint could not be recorded" >&2
            fail=1
        fi
    else
        echo "CHECK    $remote (size after mv '${now:-none}')"; fail=1
    fi
done
exit $fail
