#!/usr/bin/env bash
# LOCAL TEST ONLY — make an UNCLAIMED test business on the local site so the phone sign-up + onboarding walk can be run
# again and again (Tim, 2026-11-13: "we need to be able to do this multiple times").
#
#   scripts/local-signup.sh reset     drop and re-create the business: no password yet, nobody has signed in, no fake data
#   scripts/local-signup.sh code      the newest 6-digit sign-up code from the local mail log (nothing is really emailed)
#   scripts/local-signup.sh status    owner email, claimed or not, who exists
#
# Sign up in the app with the OWNER EMAIL that `reset` prints (any name, any cell, a password you choose). The code is
# in `scripts/local-signup.sh code`. Then walk: About you, Company, QuickBooks, Accountant, You're set up.
#
# Why it reuses the host claudia.ittai.ca: the Android tenanttest build only talks to the host it was built for (and the
# local emulator maps that one host), so the reset business takes that host. Change the business with env vars:
#   TENANT_KEY=claudia  TENANT_NAME="Heritage Tree Service (LOCAL TEST)"  OWNER_EMAIL=owner@heritage.test
# `reset` replaces the whole business database (mowology_<key>) — the fake data `local-android.sh up` seeds is gone until
# you run `local-android.sh up` again. The default business (mowology_local) is never touched.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE="${MOWOLOGY_LOCAL_STATE:-$HOME/.mowology-local}"
TENANT_KEY="${TENANT_KEY:-claudia}"
TENANT_NAME="${TENANT_NAME:-Heritage Tree Service (LOCAL TEST)}"
OWNER_EMAIL="${OWNER_EMAIL:-owner@heritage.test}"
DB="mowology_${TENANT_KEY}"
MYSQL="${MYSQL_BIN:-/Applications/MAMP/Library/bin/mysql80/bin/mysql}"
SOCK="$STATE/mysql.sock"

say() { printf '\033[1m» %s\033[0m\n' "$*"; }
die() { echo "$*" >&2; exit 1; }
sql() { "$MYSQL" -S "$SOCK" -u root "$@"; }

[[ "$TENANT_KEY" =~ ^[a-z0-9]{3,20}$ ]] || die "TENANT_KEY must be 3-20 lowercase letters or digits"
[ "$DB" != "mowology_local" ] || die "refusing: mowology_local is the default local business"
[ -S "$SOCK" ] || die "local MySQL is not running — scripts/local-site.sh start"
grep -q "LOCAL TEST ONLY" "$ROOT/public/app_config/secrets.php" 2>/dev/null || die "refusing: secrets.php is not the LOCAL TEST ONLY file"

case "${1:-}" in
  reset)
    say "Dropping $DB and provisioning \"$TENANT_NAME\" (owner $OWNER_EMAIL) …"
    sql -e "DROP DATABASE IF EXISTS \`$DB\`"
    "$ROOT/scripts/local-site.sh" php "$ROOT/scripts/local/provision-tenant.php" "$TENANT_KEY" "$TENANT_NAME" "$OWNER_EMAIL" Owner | grep -v "^Owner one-time" || true
    # Unclaimed = the first admin has never logged in, has no password yet (sign-up sets its own), and no tenant_claimed_at.
    sql "$DB" -e "UPDATE users SET password_hash = '', last_login = NULL WHERE role = 'admin' ORDER BY id LIMIT 1;
                  DELETE FROM ops_settings WHERE setting_key IN ('tenant_claimed_at','signup_code_hash','signup_code_expires_at','signup_code_tries');"
    # The provisioner wrote a one-time password for the owner into .credentials.local; it no longer applies.
    CRED="$ROOT/scripts/local/.credentials.local"
    [ -f "$CRED" ] && sed -i '' "/^${TENANT_KEY}-owner /d" "$CRED" || true
    say "Unclaimed. Sign up in the app as: $OWNER_EMAIL   (host ${TENANT_KEY}.ittai.ca)"
    echo "  then read the code with: scripts/local-signup.sh code"
    ;;
  code)
    LOG="$STATE/mail.log"
    [ -f "$LOG" ] || die "no mail log yet ($LOG)"
    # The code mail reads "Your Ittai code is <strong>123456</strong>" (HTML) or "Your Ittai code is 123456." (SMS gateway).
    CODE="$(tail -n 400 "$LOG" | grep -Eo 'Your Ittai code is[^0-9]*[0-9]{6}' | grep -Eo '[0-9]{6}$' | tail -1 || true)"
    [ -n "$CODE" ] || die "no 6-digit code in the mail log yet — request one in the app first"
    echo "$CODE"
    ;;
  status)
    sql "$DB" -e "SELECT id, email, role, is_active, (password_hash <> '') AS has_password, last_login FROM users WHERE role = 'admin' ORDER BY id LIMIT 1;
                  SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ('tenant_claimed_at','business_name') ;
                  SELECT COUNT(*) AS users FROM users;"
    ;;
  *)
    sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'
    exit 2
    ;;
esac
