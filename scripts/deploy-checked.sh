#!/usr/bin/env bash
# =============================================================================
# scripts/deploy-checked.sh — drift-checked FTP deploy of several files
# =============================================================================
# The "ALWAYS cmp against prod before deploying" rule as one command:
#   1. download each file's live copy and compare it with <base-ref>'s version —
#      if prod differs, someone changed prod (or the repo is behind it): STOP, upload
#      nothing, and say which file drifted;
#   2. upload every file atomically (temporary name, then rename over the live file);
#   3. read each back and compare with the local file.
# Files that don't exist at <base-ref> are new: no drift check, still read back.
#
# Mapping (same as scripts/deploy-file.sh, plus migrations):
#   public/<rest>               → /<rest>
#   app/<rest>                  → /app/<rest>
#   database/migrations/<file>  → /database/migrations/<file>
# Anything else is refused.
#
# Usage:
#   scripts/deploy-checked.sh <base-ref> <file>...     e.g.  HEAD~1 app/X.php public/crm/js/y.js
#   scripts/deploy-checked.sh --dry-run <base-ref> <file>...
# Then reset OPcache (/crm/api/opcache-reset.php) and verify the page renders to </html>.
#
# Exit: 0 ok · 2 bad args · 3 refused path · 4 no credentials · 5 drift · 6 upload/readback failed
# =============================================================================
set -euo pipefail

DRY=0
if [[ "${1:-}" == "--dry-run" ]]; then DRY=1; shift; fi
BASE="${1:-}"; shift || true
if [[ -z "$BASE" || $# -eq 0 ]]; then
    sed -n '2,24p' "$0"; exit 2
fi
git rev-parse --verify --quiet "$BASE^{commit}" >/dev/null || { echo "Unknown base ref: $BASE"; exit 2; }

remote_dir() {
    case "$1" in
        public/*)              dirname "/${1#public/}" ;;
        app/*)                 dirname "/$1" ;;
        database/migrations/*) echo "/database/migrations" ;;
        *) return 1 ;;
    esac
}

FILES=("$@")
for f in "${FILES[@]}"; do
    [[ -f "$f" ]] || { echo "Not a file: $f"; exit 2; }
    remote_dir "$f" >/dev/null || { echo "Refused (unknown destination): $f"; exit 3; }
done

U=$(git config git-ftp.user || true); P=$(git config git-ftp.password || true)
[[ -n "$U" && -n "$P" ]] || { echo "FTP credentials missing (git config git-ftp.user / git-ftp.password)"; exit 4; }
HOST="ftp.mowology.ca"
LFTP_SET="set ssl:verify-certificate no; set ftp:ssl-force true; set net:max-retries 2;"

TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

# 1. Drift check
GET=""
for i in "${!FILES[@]}"; do
    f="${FILES[$i]}"
    if git cat-file -e "$BASE:$f" 2>/dev/null; then
        GET+=" get $(remote_dir "$f")/$(basename "$f") -o $TMP/pre$i;"
    fi
done
[[ -n "$GET" ]] && lftp -u "$U,$P" -e "$LFTP_SET $GET quit" "$HOST" >/dev/null 2>&1 || true
for i in "${!FILES[@]}"; do
    f="${FILES[$i]}"
    if git cat-file -e "$BASE:$f" 2>/dev/null; then
        if [[ ! -f "$TMP/pre$i" ]]; then echo "DRIFT  $f — not found on prod (expected $BASE's copy)"; exit 5; fi
        git show "$BASE:$f" | cmp -s - "$TMP/pre$i" || { echo "DRIFT  $f — prod differs from $BASE. Nothing uploaded."; exit 5; }
        echo "same   $f"
    else
        echo "new    $f"
    fi
done
if [[ $DRY -eq 1 ]]; then
    for f in "${FILES[@]}"; do echo "would upload $f → $(remote_dir "$f")/"; done
    exit 0
fi

# 2 + 3. Upload, read back
PUT=""
for i in "${!FILES[@]}"; do
    f="${FILES[$i]}"; d=$(remote_dir "$f")
    b=$(basename "$f")
    # Atomic: upload under a temporary name, then rename over the live file — a page
    # loading mid-upload never reads a half-written (FTP truncates first) file.
    PUT+=" mkdir -pf $d; put $f -o $d/.$b.uploading; mv $d/.$b.uploading $d/$b; get $d/$b -o $TMP/post$i;"
done
lftp -u "$U,$P" -e "$LFTP_SET $PUT quit" "$HOST" >/dev/null 2>&1 || true
for i in "${!FILES[@]}"; do
    f="${FILES[$i]}"
    cmp -s "$f" "$TMP/post$i" 2>/dev/null || { echo "FAILED $f — read-back differs or missing"; exit 6; }
    echo "live   $f"
done
echo "Deployed ${#FILES[@]} file(s). Next: reset OPcache, then verify the page."
