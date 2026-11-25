#!/usr/bin/env bash
# scripts/parity/start-android.sh — bring up what the parity screenshots need: the local site on :8030, the TLS proxy,
# the Android emulator (AVD claudia) and the tenanttest debug app. Safe to run when it is already up.
# It must run from a checkout that holds the gitignored local files (public/app_config/secrets.php + tenants.php,
# scripts/local/.credentials.local). install.sh records that checkout in ~/.mowology-parity/site-root; override with PARITY_SITE_ROOT.
# The app stays signed in between runs (emulator data is kept). Exit 0 = ready, 1 = not up (the daily run then skips Android shots).
set -uo pipefail
export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:$PATH"
STATE="${PARITY_HOME:-$HOME/.mowology-parity}"
ROOT="${PARITY_SITE_ROOT:-$(cat "$STATE/site-root" 2>/dev/null || true)}"
[ -x "$ROOT/scripts/local-android.sh" ] || { echo "start-android: no checkout with local-android.sh at '$ROOT' (set PARITY_SITE_ROOT or re-run install.sh)"; exit 1; }
for f in public/app_config/secrets.php public/app_config/tenants.php scripts/local/.credentials.local; do
  [ -f "$ROOT/$f" ] || { echo "start-android: $ROOT/$f is missing — that checkout cannot serve the local tenant"; exit 1; }
done
export LOCAL_SITE_PORT="${LOCAL_SITE_PORT:-8030}" ANDROID_DIR="${ANDROID_DIR:-$HOME/Projects/mowology-android-codex}"
# local-android.sh hides the site's own start output; the first call after a reboot can fail while MySQL warms up, so try twice.
"$ROOT/scripts/local-android.sh" up || { sleep 20; "$ROOT/scripts/local-android.sh" up; } || { echo "start-android: local-android.sh up failed twice (try: LOCAL_SITE_PORT=8030 $ROOT/scripts/local-site.sh start)"; exit 1; }
ADB="${ANDROID_HOME:-$HOME/Library/Android/sdk}/platform-tools/adb"
# Signed in? Open the app and look for its sign-in fields; say so rather than letting the shots capture a sign-in screen.
"$ADB" shell am start -n ca.mowology.crew.test/ca.mowology.crew.MainActivity >/dev/null 2>&1; sleep 25
"$ADB" shell uiautomator dump /sdcard/ui.xml >/dev/null 2>&1
"$ADB" shell cat /sdcard/ui.xml 2>/dev/null | grep -qiE 'text="(Sign in|Email|Password)"' \
  && echo "start-android: the app is at its sign-in screen — sign in once (claudia-owner in scripts/local/.credentials.local); Android shots are blank until then"
exit 0
