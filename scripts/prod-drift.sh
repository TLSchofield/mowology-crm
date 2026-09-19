#!/usr/bin/env bash
#
# prod-drift.sh — find files where production and this repo disagree. READ-ONLY.
#
#   scripts/prod-drift.sh                      # audits app/
#   scripts/prod-drift.sh app/Modules/Jobs     # audits one subtree
#   scripts/prod-drift.sh public/crm/api       # public/ maps to the FTP root
#
# Why this exists: production is deployed file-by-file over FTP, often from a
# side branch. Twice now (push pipeline, July 2026; ten schedule endpoints,
# Sept 2026) live code turned out to exist ONLY on the server because the branch
# it came from was never merged. `ls` in the repo cannot see that — only a
# mirror-and-diff can.
#
# Downloads the remote tree into a temp dir (nothing is uploaded or changed),
# then reports three buckets:
#   PROD-ONLY  — on the server, not in the repo   → at risk of being lost
#   DIFFERS    — both places, content differs     → undeployed work OR a prod hand-edit
#   REPO-ONLY  — in the repo, never deployed
#
# The mirror is kept and its path printed, so you can diff/copy individual files.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

LOCAL="${1:-app}"
LOCAL="${LOCAL%/}"
[ -d "$LOCAL" ] || { echo "not a directory: $LOCAL" >&2; exit 1; }

# app/ lives inside public_html as /app; public/ IS public_html.
case "$LOCAL" in
  public)   REMOTE="/" ;;
  public/*) REMOTE="/${LOCAL#public/}" ;;
  *)        REMOTE="/$LOCAL" ;;
esac

FTP_PASS="$(git config git-ftp.password)" || { echo "git config git-ftp.password is not set" >&2; exit 1; }
MIRROR="$(mktemp -d "${TMPDIR:-/tmp}/prod-drift.XXXXXX")"

echo "Mirroring ftp:$REMOTE → $MIRROR (read-only)…" >&2
EXCLUDES=""
for g in vendor/ node_modules/ .git/ uploads/ storage/ Storage/ sessions/ secrets.php \
         '*.log' '*.jpg' '*.jpeg' '*.png' '*.webp' '*.gif' '*.pdf' '*.zip' '*.apk'; do
  EXCLUDES+=" --exclude-glob $g"
done
# One line on purpose: lftp mis-parses a multi-line -e string ("mirror: Not connected").
lftp -u "claude@mowology.ca,$FTP_PASS" -e "set ssl:verify-certificate no; set ftp:ssl-force true; set net:max-retries 3; mirror --parallel=4 --verbose=0$EXCLUDES $REMOTE $MIRROR/tree; quit" ftp://ftp.mowology.ca >&2
# Target must NOT pre-exist: given an existing dir, lftp nests the source inside it
# (…/app/app/…) and every path misses. A fresh name makes it mirror INTO that path.
MIRROR="$MIRROR/tree"

# Compare against what git tracks, so local scratch files don't show up as "repo-only".
TRACKED="$(mktemp)"; trap 'rm -f "$TRACKED"' EXIT
git ls-files "$LOCAL" | sed "s|^$LOCAL/||" | sort > "$TRACKED"
REMOTE_FILES="$(cd "$MIRROR" && find . -type f | sed 's|^\./||' | sort)"

prod_only="$(comm -13 "$TRACKED" <(printf '%s\n' "$REMOTE_FILES") | grep -v '^$' || true)"
repo_only="$(comm -23 "$TRACKED" <(printf '%s\n' "$REMOTE_FILES") | grep -v '^$' || true)"
differs=""
while IFS= read -r f; do
  [ -n "$f" ] || continue
  cmp -s "$MIRROR/$f" "$LOCAL/$f" || differs+="$f"$'\n'
done < <(comm -12 "$TRACKED" <(printf '%s\n' "$REMOTE_FILES"))

count() { [ -n "$1" ] && printf '%s\n' "$1" | grep -c . || echo 0; }

echo
echo "=== PROD-ONLY ($(count "$prod_only")) — on the server, not tracked in git ==="
[ -n "$prod_only" ] && printf '%s\n' "$prod_only" | sed "s|^|  $LOCAL/|"
echo
echo "=== DIFFERS ($(count "$differs")) — undeployed repo work, or a hand-edit on prod ==="
[ -n "$differs" ] && printf '%s' "$differs" | sed "s|^|  $LOCAL/|"
echo
echo "=== REPO-ONLY ($(count "$repo_only")) — tracked but never deployed ==="
[ -n "$repo_only" ] && printf '%s\n' "$repo_only" | sed "s|^|  $LOCAL/|"
echo
echo "Mirror kept at: $MIRROR"
echo "  inspect one:  diff $MIRROR/<file> $LOCAL/<file>"
