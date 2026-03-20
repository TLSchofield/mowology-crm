#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# deploy.sh — Mowology one-command FTP deployer
#
# Usage:
#   ./deploy.sh              # deploy all files changed since last deploy
#   ./deploy.sh --dry-run    # show what would be deployed, don't upload
#   ./deploy.sh path/to/file # deploy a specific file regardless of git status
#
# Requirements: lftp, git, git-ftp (for dry-run diffing only)
#
# How it works:
#   1. Reads FTP credentials from git config (git-ftp section).
#   2. Runs `git ftp push --dry-run` to get the list of changed files since
#      the last deploy (reads the remote .git-ftp.log SHA).
#   3. Uploads each file via lftp — one connection per file to avoid
#      ModSecurity/ImunifyAV 451 errors on large files.
#   4. Updates the remote .git-ftp.log so the next run knows the new baseline.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

FTP_HOST="ftp.mowology.ca"
FTP_USER=$(git config --get git-ftp.user   2>/dev/null || echo "")
FTP_PASS=$(git config --get git-ftp.password 2>/dev/null || echo "")
SYNCROOT=$(git config --get git-ftp.syncroot 2>/dev/null || echo "public/")
DRY_RUN=false
SPECIFIC_FILE=""

# ── Parse args ────────────────────────────────────────────────────────────────
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true ;;
    --help|-h)
      echo "Usage: ./deploy.sh [--dry-run] [file]"
      echo "  --dry-run    Show files that would be deployed"
      echo "  file         Deploy a specific local file path"
      exit 0
      ;;
    *) SPECIFIC_FILE="$arg" ;;
  esac
done

# ── Validate ──────────────────────────────────────────────────────────────────
if [[ -z "$FTP_USER" || -z "$FTP_PASS" ]]; then
  echo "ERROR: FTP credentials not found in git config."
  echo "  git config git-ftp.user     'your@email.com'"
  echo "  git config git-ftp.password 'yourpassword'"
  exit 1
fi

LFTP_BASE="lftp -u \"$FTP_USER,$FTP_PASS\" -e \"set ssl:verify-certificate no; set ftp:ssl-force true;"

# ── Helper: upload one file ───────────────────────────────────────────────────
upload_file() {
  local local_path="$1"           # e.g. public/crm/api/quiz.php
  # Strip the syncroot prefix to get the FTP-relative path
  local ftp_rel="${local_path#$SYNCROOT}"   # e.g. crm/api/quiz.php
  local ftp_dir="/$(dirname "$ftp_rel")"   # e.g. /crm/api

  if [[ "$DRY_RUN" == "true" ]]; then
    echo "  [dry-run] would upload: $local_path → $ftp_dir/"
    return
  fi

  echo "  Uploading: $local_path"
  lftp -u "$FTP_USER,$FTP_PASS" \
       -e "set ssl:verify-certificate no; set ftp:ssl-force true; put -O $ftp_dir/ $local_path; quit" \
       "ftp://$FTP_HOST" 2>&1
}

# ── Handle files outside syncroot (e.g. app/ directory) ──────────────────────
upload_file_absolute() {
  local local_path="$1"
  # For files outside public/ (e.g. app/Core/Auth/auth.php → /app/Core/Auth/)
  local ftp_dir="/$(dirname "$local_path")"

  if [[ "$DRY_RUN" == "true" ]]; then
    echo "  [dry-run] would upload (non-syncroot): $local_path → $ftp_dir/"
    return
  fi

  echo "  Uploading (non-syncroot): $local_path"
  lftp -u "$FTP_USER,$FTP_PASS" \
       -e "set ssl:verify-certificate no; set ftp:ssl-force true; put -O $ftp_dir/ $local_path; quit" \
       "ftp://$FTP_HOST" 2>&1
}

# ── Single-file mode ──────────────────────────────────────────────────────────
if [[ -n "$SPECIFIC_FILE" ]]; then
  echo "Deploying specific file: $SPECIFIC_FILE"
  if [[ "$SPECIFIC_FILE" == "$SYNCROOT"* ]]; then
    upload_file "$SPECIFIC_FILE"
  else
    upload_file_absolute "$SPECIFIC_FILE"
  fi
  echo "Done."
  exit 0
fi

# ── Changed-files mode ────────────────────────────────────────────────────────
echo "Detecting changed files since last deploy..."

# git ftp push --dry-run reads .git-ftp.log from the server to know last SHA
# Output lines look like:
#   M public/crm/api/quiz.php
#   A public/crm/css/mobile-nav.css
#   D public/old-file.php
CHANGED=$(git ftp push --dry-run 2>&1 | grep -E '^[MAD]\s' | awk '{print $2}' || true)

if [[ -z "$CHANGED" ]]; then
  echo "Nothing to deploy — remote is up to date with $(git rev-parse --short HEAD)."
  exit 0
fi

echo ""
echo "Files to deploy:"
echo "$CHANGED" | while read -r f; do echo "  $f"; done
echo ""

if [[ "$DRY_RUN" == "true" ]]; then
  echo "[dry-run] No files uploaded."
  exit 0
fi

read -rp "Proceed with deploy? [y/N] " confirm
[[ "$confirm" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }

echo ""
echo "Uploading..."
ERRORS=0
while IFS= read -r file; do
  [[ -z "$file" ]] && continue
  if [[ ! -f "$file" ]]; then
    echo "  SKIP (deleted/missing): $file"
    continue
  fi
  if [[ "$file" == "$SYNCROOT"* ]]; then
    upload_file "$file" || { echo "  ERROR uploading $file"; ERRORS=$((ERRORS+1)); }
  else
    upload_file_absolute "$file" || { echo "  ERROR uploading $file"; ERRORS=$((ERRORS+1)); }
  fi
done <<< "$CHANGED"

# ── Update remote .git-ftp.log ────────────────────────────────────────────────
if [[ $ERRORS -eq 0 ]]; then
  echo ""
  echo "Updating remote deploy marker..."
  HEAD=$(git rev-parse HEAD)
  echo "$HEAD" > /tmp/git-ftp-log.txt
  lftp -u "$FTP_USER,$FTP_PASS" \
       -e "set ssl:verify-certificate no; set ftp:ssl-force true; put -O / /tmp/git-ftp-log.txt -o .git-ftp.log; quit" \
       "ftp://$FTP_HOST" 2>&1
  rm -f /tmp/git-ftp-log.txt
  echo "Deploy complete. Remote is now at $(git rev-parse --short HEAD)."
else
  echo ""
  echo "Deploy finished with $ERRORS error(s). Remote .git-ftp.log NOT updated."
  echo "Fix errors and re-run."
  exit 1
fi
