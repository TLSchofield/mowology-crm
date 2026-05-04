#!/usr/bin/env bash
# =============================================================================
# Mowology CRM — API Smoke Test
# =============================================================================
# Hits every iOS app API endpoint and checks for expected HTTP status codes.
# Run AFTER every production deploy to catch 500s, 404s, and routing breaks.
#
# Usage:
#   bash tests/smoke-test.sh
#   BASE_URL=https://staging.mowology.ca bash tests/smoke-test.sh
#
# Exit code: 0 = all pass, 1 = one or more failures
# =============================================================================

set -euo pipefail

BASE="${BASE_URL:-https://mowology.ca/api}"
PASS=0
FAIL=0
ERRORS=()

# ── Colour helpers ───────────────────────────────────────────────────────────
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Colour

ok()   { echo -e "  ${GREEN}✓${NC} $1"; ((PASS++)); }
fail() { echo -e "  ${RED}✗${NC} $1"; ((FAIL++)); ERRORS+=("$1"); }
info() { echo -e "${YELLOW}▶${NC} $1"; }

# ── Helpers ──────────────────────────────────────────────────────────────────

# http_status <method> <url> [extra curl args...]
http_status() {
    local method=$1
    local url=$2
    shift 2
    curl -s -o /dev/null -w "%{http_code}" \
        -X "$method" \
        --connect-timeout 10 \
        --max-time 20 \
        "$@" \
        "$url"
}

# expect <label> <method> <url> <expected_status> [extra curl args...]
expect() {
    local label=$1 method=$2 url=$3 expected=$4
    shift 4
    local got
    got=$(http_status "$method" "$url" "$@")
    if [[ "$got" == "$expected" ]]; then
        ok "$label → HTTP $got"
    else
        fail "$label → expected $expected, got $got  ($url)"
    fi
}

# expect_one_of <label> <method> <url> <status1> <status2> [extra curl args...]
expect_one_of() {
    local label=$1 method=$2 url=$3 s1=$4 s2=$5
    shift 5
    local got
    got=$(http_status "$method" "$url" "$@")
    if [[ "$got" == "$s1" || "$got" == "$s2" ]]; then
        ok "$label → HTTP $got"
    else
        fail "$label → expected $s1 or $s2, got $got  ($url)"
    fi
}

# =============================================================================
echo ""
info "Smoke testing $BASE"
echo ""

# =============================================================================
# 1. Auth endpoint — POST with bad credentials → 401 or 400
# =============================================================================
info "Auth"
expect \
    "POST /auth/token.php (bad credentials)" \
    POST \
    "$BASE/auth/token.php" \
    401 \
    -H "Content-Type: application/json" \
    -d '{"email":"smoke-test@example.com","password":"wrong"}'

# =============================================================================
# 2. JWT-protected GET endpoints — no token → 401
# =============================================================================
info "JWT-gated GET endpoints (no token → expect 401)"

TODAY=$(date '+%Y-%m-%d')
WEEK_START=$(date -v-Mon '+%Y-%m-%d' 2>/dev/null || date --date='last monday' '+%Y-%m-%d' 2>/dev/null || echo "$TODAY")

expect "GET /schedule/day?date=$TODAY"     GET "$BASE/schedule/day?date=$TODAY"           401
expect "GET /schedule/week?start=$TODAY"   GET "$BASE/schedule/week?start=$WEEK_START"    401
expect "GET /schedule/clock?action=status" GET "$BASE/schedule/clock?action=status"       401
expect "GET /expenses/expense-meta"        GET "$BASE/expenses/expense-meta"              401
expect "GET /expenses/expense-list?page=1" GET "$BASE/expenses/expense-list?page=1"       401
expect "GET /expenses/receipt-image?id=1"  GET "$BASE/expenses/receipt-image?id=1"        401

# =============================================================================
# 3. JWT-protected POST endpoints — no token → 401
# =============================================================================
info "JWT-gated POST endpoints (no token → expect 401)"

expect "POST /schedule/timer (no token)"    POST "$BASE/schedule/timer"    401 -H "Content-Type: application/json" -d '{}'
expect "POST /schedule/location (no token)" POST "$BASE/schedule/location" 401 -H "Content-Type: application/json" -d '{}'
expect "POST /schedule/clock (no token)"    POST "$BASE/schedule/clock"    401 -H "Content-Type: application/json" -d '{}'
expect "POST /expenses/expense-save (no token)" POST "$BASE/expenses/expense-save" 401 -H "Content-Type: application/json" -d '{}'

# receipt-upload is multipart — still 401 without token
expect "POST /expenses/receipt-upload (no token)" POST "$BASE/expenses/receipt-upload" 401

# =============================================================================
# 4. Routing — verify endpoints respond (not 404)
#    The responses above already confirm this, but double-check with HEAD
# =============================================================================
info "Route existence (endpoints respond, not 404)"

for path in \
    "auth/token.php" \
    "schedule/day" \
    "schedule/week" \
    "schedule/clock" \
    "schedule/timer" \
    "schedule/location" \
    "expenses/expense-meta" \
    "expenses/expense-list" \
    "expenses/expense-save" \
    "expenses/receipt-upload" \
    "expenses/receipt-image"
do
    got=$(http_status GET "$BASE/$path")
    if [[ "$got" == "404" ]]; then
        fail "Route $path → 404 (missing endpoint)"
    else
        ok "Route $path → $got (exists)"
    fi
done

# =============================================================================
# 5. Summary
# =============================================================================
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo -e "  ${GREEN}PASS: $PASS${NC}   ${RED}FAIL: $FAIL${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

if [[ $FAIL -gt 0 ]]; then
    echo ""
    echo -e "${RED}Failed checks:${NC}"
    for err in "${ERRORS[@]}"; do
        echo "  • $err"
    done
    echo ""
    exit 1
fi

echo ""
echo -e "  ${GREEN}All checks passed.${NC}"
echo ""
exit 0
