#!/usr/bin/env bash
# Mowology CRM — Dev Context auto-injector
# Called by the UserPromptSubmit hook.
# Fetches a compact snapshot of live DB schema, quiz IDs, employees, and
# recent errors from dev-context.php and outputs it so Claude can skip
# file-reading on session startup.
#
# Token: stored in .claude/context.token (gitignored)
# Cache: /tmp/mowology-ctx.json, refreshed every 30 minutes

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TOKEN_FILE="$REPO_DIR/.claude/context.token"
CACHE_FILE="/tmp/mowology-ctx.json"
CACHE_TTL=1800  # 30 minutes
API_URL="https://mowology.ca/crm/api/dev-context.php"

# Load token
if [ ! -f "$TOKEN_FILE" ]; then
    exit 0  # No token configured — skip silently
fi
TOKEN=$(cat "$TOKEN_FILE" | tr -d '[:space:]')
if [ -z "$TOKEN" ]; then
    exit 0
fi

# Check cache freshness (macOS + Linux compatible)
if [ -f "$CACHE_FILE" ]; then
    if command -v stat &>/dev/null; then
        if stat -f %m "$CACHE_FILE" &>/dev/null; then
            # macOS
            FILE_AGE=$(( $(date +%s) - $(stat -f %m "$CACHE_FILE") ))
        else
            # Linux
            FILE_AGE=$(( $(date +%s) - $(stat -c %Y "$CACHE_FILE") ))
        fi
        if [ "$FILE_AGE" -lt "$CACHE_TTL" ]; then
            # Cache is fresh — output it and exit
            echo "--- MOWOLOGY DEV CONTEXT (cached $(( FILE_AGE / 60 ))m ago) ---"
            cat "$CACHE_FILE"
            echo "--- END DEV CONTEXT ---"
            exit 0
        fi
    fi
fi

# Fetch fresh context
RESULT=$(curl -sf --max-time 8 "${API_URL}?token=${TOKEN}" 2>/dev/null || echo "")

if [ -z "$RESULT" ] || echo "$RESULT" | grep -q '"error"'; then
    # Silently skip if API unreachable or returns error
    exit 0
fi

# Cache it
echo "$RESULT" > "$CACHE_FILE"

echo "--- MOWOLOGY DEV CONTEXT (live $(date '+%H:%M')) ---"
cat "$CACHE_FILE"
echo "--- END DEV CONTEXT ---"
