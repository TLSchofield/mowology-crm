#!/usr/bin/env bash
# =============================================================================
# scripts/deploy-file.sh
# =============================================================================
# Safely FTP-deploy a single local file to mowology.ca production.
#
# Computes the FTP destination path from the local path using a fixed mapping,
# validates the result, and refuses any combination that would land a file
# outside its mirrored directory (the failure mode that took the site down on
# 2026-05-05 — a misdirected upload of public/api/index.php to /index.php).
#
# Mapping
# -------
#   public/<file>           →  /<file>          (public root: index.php, etc.)
#   public/<sub>/<file>     →  /<sub>/<file>    (public sub-dir mirror)
#   public/<a>/<b>/<file>   →  /<a>/<b>/<file>
#   app/<rest>              →  /app/<rest>      (app dir: shipped inside web root)
#
# Anything else is refused.
#
# Usage
# -----
#   scripts/deploy-file.sh <local-path>
#   scripts/deploy-file.sh --dry-run <local-path>     # print mapping, no upload
#   scripts/deploy-file.sh --help
#
# Exit codes
# ----------
#   0  success
#   2  bad arguments / file not found
#   3  refused — destination would be unsafe
#   4  ftp credentials missing
#   5  upload failed
# =============================================================================

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: scripts/deploy-file.sh [--dry-run] <local-path>

Safely deploy a single file to production via FTP.

Options:
  --dry-run     Print the computed FTP destination without uploading.
  --help, -h    Show this help and exit.

Examples:
  scripts/deploy-file.sh public/index.php
  scripts/deploy-file.sh public/crm/jobs/schedule.php
  scripts/deploy-file.sh app/Modules/Team/Api/time-clock.php
  scripts/deploy-file.sh --dry-run public/api/index.php
USAGE
}

# ── Parse args ──────────────────────────────────────────────────────────────

DRY_RUN=0
LOCAL=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --help|-h)
            usage
            exit 0
            ;;
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        --*)
            echo "ERROR: unknown option: $1" >&2
            usage >&2
            exit 2
            ;;
        *)
            if [[ -n "$LOCAL" ]]; then
                echo "ERROR: only one local path may be supplied (got: '$LOCAL' and '$1')" >&2
                echo "       For multiple files, invoke this script once per file." >&2
                exit 2
            fi
            LOCAL="$1"
            shift
            ;;
    esac
done

if [[ -z "$LOCAL" ]]; then
    echo "ERROR: no local file path provided" >&2
    usage >&2
    exit 2
fi

# ── Path safety checks (run BEFORE existence so traversal/abs paths get
#     a specific error, not a misleading "file not found") ─────────────────

# Reject absolute paths — must be repo-relative so the mapping rules apply.
case "$LOCAL" in
    /*)
        echo "ERROR: absolute paths are not supported (must be repo-relative)" >&2
        echo "  Got: $LOCAL" >&2
        exit 2
        ;;
esac

# Reject path traversal.
case "$LOCAL" in
    *..*)
        echo "ERROR: '..' path traversal is not allowed" >&2
        echo "  Got: $LOCAL" >&2
        exit 3
        ;;
esac

# Existence check
if [[ ! -f "$LOCAL" ]]; then
    echo "ERROR: local file does not exist: $LOCAL" >&2
    exit 2
fi

# ── Compute the remote destination ──────────────────────────────────────────

FILENAME="$(basename "$LOCAL")"
LOCAL_DIR="$(dirname "$LOCAL")"

REMOTE_DIR=""

case "$LOCAL_DIR" in
    public)
        # public/<file> — the public web-root files (index.php, robots.txt, etc.)
        REMOTE_DIR="/"
        ;;
    public/*)
        # public/<sub>/<file> — strip the public/ prefix.
        # The leading and trailing slashes give lftp's `put -O` a directory.
        REMOTE_DIR="/${LOCAL_DIR#public/}/"
        ;;
    app|app/*)
        # app/<rest> — app/ ships inside web root on production.
        REMOTE_DIR="/${LOCAL_DIR}/"
        ;;
    *)
        echo "ERROR: refusing to deploy a file outside public/ or app/" >&2
        echo "  Local path:    $LOCAL" >&2
        echo "  Local dir:     $LOCAL_DIR" >&2
        echo "  Allowed roots: public/, app/" >&2
        exit 3
        ;;
esac

# ── Validation ──────────────────────────────────────────────────────────────

# The web root (REMOTE_DIR == "/") is reserved for files that live at
# `public/<file>` only — i.e. LOCAL_DIR exactly "public". A computed
# REMOTE_DIR of "/" with a non-root LOCAL_DIR means we'd be flattening a
# nested file onto the web root — exactly the failure mode that took the
# site down (public/api/index.php landing at /index.php).
if [[ "$REMOTE_DIR" = "/" && "$LOCAL_DIR" != "public" ]]; then
    echo "ERROR: refusing to deploy a non-root file to the web root" >&2
    echo "  Local path:    $LOCAL" >&2
    echo "  Local dir:     $LOCAL_DIR" >&2
    echo "  Computed dest: ${REMOTE_DIR}${FILENAME}" >&2
    exit 3
fi

# Refuse anything that doesn't begin and end with a slash — a malformed
# remote dir would let `put -O` interpret it as a filename.
# Note: bare "/" is valid (the web root); patterns below cover both shapes.
case "$REMOTE_DIR" in
    /)    : ;;  # web root
    /*/)  : ;;  # nested directory: starts and ends with /
    *)
        echo "ERROR: computed remote directory is malformed: '$REMOTE_DIR'" >&2
        echo "  Local:  $LOCAL" >&2
        exit 3
        ;;
esac

EXPECTED_DEST="${REMOTE_DIR}${FILENAME}"

echo "Deploy mapping:"
echo "  Local:  $LOCAL"
echo "  Remote: $EXPECTED_DEST"

if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "(dry-run — no upload performed)"
    exit 0
fi

# ── Read FTP credentials ────────────────────────────────────────────────────

FTP_PASS="$(git config git-ftp.password 2>/dev/null || true)"
if [[ -z "$FTP_PASS" ]]; then
    echo "ERROR: git config git-ftp.password is not set" >&2
    echo "       Configure with: git config git-ftp.password '<password>'" >&2
    exit 4
fi

FTP_USER="claude@mowology.ca"
FTP_HOST="ftp.mowology.ca"

# ── Upload via lftp ─────────────────────────────────────────────────────────
#
# Defensive lftp invocation:
#   - `put -O <dir> <local> -o <basename>` is explicit on both directory and
#     filename. Even if shell quoting were ever broken, the `-o` argument
#     pins the destination filename to the source basename — preventing any
#     trick where a bad LOCAL value relocates the file.
#   - `set ftp:ssl-force true` forces TLS on the data channel.

if ! command -v lftp >/dev/null 2>&1; then
    echo "ERROR: lftp is not installed in PATH" >&2
    exit 5
fi

LFTP_OUT=$(mktemp)
trap 'rm -f "$LFTP_OUT"' EXIT

# Atomic deploy: upload to a .deploying temp file, then rename.
# If the transfer is interrupted the live file is never touched.
TEMP_NAME="${FILENAME}.deploying"
TEMP_DEST="${REMOTE_DIR}${TEMP_NAME}"

if ! lftp -u "$FTP_USER,$FTP_PASS" -e "
    set ssl:verify-certificate no
    set ftp:ssl-force true
    put -O '$REMOTE_DIR' '$LOCAL' -o '$TEMP_NAME'
    mv '$TEMP_DEST' '$EXPECTED_DEST'
    quit
" "ftp://$FTP_HOST" >"$LFTP_OUT" 2>&1; then
    echo "ERROR: lftp upload failed" >&2
    cat "$LFTP_OUT" >&2
    exit 5
fi

echo "Uploaded: $LOCAL -> $EXPECTED_DEST"
