#!/usr/bin/env bash
# LOCAL TEST ONLY — run the crew Android app on an emulator against a second business on the local test site,
# the way a founding business (Claudia) will see it: https://<key>.ittai.ca, its own database, every native switch on.
#
#   scripts/local-android.sh up        everything below, in order (safe to re-run; each step is skipped when done)
#   scripts/local-android.sh build     build the tenanttest DEBUG apk for the host (-PtenantTestHost)
#   scripts/local-android.sh install   install the apk on the emulator and open the app
#   scripts/local-android.sh logins    the tenant's test logins (from scripts/local/.credentials.local)
#   scripts/local-android.sh status    what is running
#   scripts/local-android.sh down      stop the TLS proxy and the emulator (the local site keeps running)
#
# Settings (env): TENANT_KEY=claudia  TENANT_NAME="Claudia's Garden Care (LOCAL TEST)"  TENANT_TRADE=gardening
#                 TENANT_CITY=Vancouver  ANDROID_DIR=~/Projects/mowology-android-codex  AVD=claudia
#
# How it fits together (nothing here touches production):
#   local site (PHP :8000) <- scripts/local/tls-proxy.py (https :8443, local CA) <- `adb reverse tcp:443`
#   <- emulator /system/etc/hosts: <key>.ittai.ca -> 127.0.0.1  <- the app (https only, no port: ServerConfig.kt)
#   Tenant.php picks the business from the Host header; the feed override (scripts/local/app-version.local.json)
#   turns every android.native_* switch on; the tenanttest debug build trusts the local CA (debug-overrides).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE="${MOWOLOGY_LOCAL_STATE:-$HOME/.mowology-local}"
TENANT_KEY="${TENANT_KEY:-claudia}"
DEFAULT_NAME="Claudia's Garden Care (LOCAL TEST)"   # kept out of ${:-}: macOS bash 3.2 misreads the apostrophe there
TENANT_NAME="${TENANT_NAME:-$DEFAULT_NAME}"
TENANT_TRADE="${TENANT_TRADE:-gardening}"
TENANT_CITY="${TENANT_CITY:-Vancouver}"
HOST="${TENANT_KEY}.ittai.ca"
ANDROID_DIR="${ANDROID_DIR:-$HOME/Projects/mowology-android-codex}"
AVD="${AVD:-claudia}"
SYSIMG="system-images;android-34;google_apis;x86_64"
PROXY_PORT="${PROXY_PORT:-8443}"
SITE_PORT="${LOCAL_SITE_PORT:-8000}"
APP_ID="ca.mowology.crew.test"
APK="$ANDROID_DIR/android/app/build/outputs/apk/tenanttest/debug/app-tenanttest-debug.apk"

SDK="${ANDROID_HOME:-$HOME/Library/Android/sdk}"
ADB="$SDK/platform-tools/adb"
EMU="$SDK/emulator/emulator"
export JAVA_HOME="${JAVA_HOME:-$HOME/.jdks/jdk-17.0.18+8/Contents/Home}"
export ANDROID_HOME="$SDK"
TLS="$STATE/tls"
PROXY_PID="$STATE/tls-proxy.pid"
mkdir -p "$TLS"; chmod 700 "$TLS"

say() { printf '\033[1m» %s\033[0m\n' "$*"; }

# ── 1. Local site + tenant ─────────────────────────────────────────────────────
site_up() {
  "$ROOT/scripts/local-site.sh" start >/dev/null
  if ! grep -q "'$TENANT_KEY'" "$ROOT/public/app_config/tenants.php" 2>/dev/null; then
    say "Provisioning $HOST (database mowology_$TENANT_KEY) …"
    "$ROOT/scripts/local-site.sh" php "$ROOT/scripts/local/provision-tenant.php" "$TENANT_KEY" "$TENANT_NAME" "owner@$TENANT_KEY.test" Owner
    say "Seeding fake $TENANT_TRADE data …"
    MW_TENANT="$TENANT_KEY" LOCAL_SEED_TRADE="$TENANT_TRADE" LOCAL_SEED_CITY="$TENANT_CITY" \
      "$ROOT/scripts/local-site.sh" php "$ROOT/scripts/local/seed.php" | tail -3
  fi
  local code; code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $HOST" "http://127.0.0.1:$SITE_PORT/loginAuth/login.php")"
  [ "$code" = 200 ] || { echo "The local site does not answer for $HOST (HTTP $code)"; exit 1; }
  if [ ! -f "$ROOT/scripts/local/app-version.local.json" ]; then
    cp "$ROOT/scripts/local/app-version.tenanttest.json" "$ROOT/scripts/local/app-version.local.json"
    say "Feed override written: every android.native_* switch on (scripts/local/app-version.local.json)"
  fi
}

# ── 2. Local CA + certificate + TLS proxy ──────────────────────────────────────
tls_up() {
  if [ ! -f "$TLS/ca.pem" ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -keyout "$TLS/ca.key" -out "$TLS/ca.pem" -days 3650 -subj "/CN=Ittai Local Test CA" 2>/dev/null
  fi
  if [ ! -f "$TLS/site.pem" ] || ! openssl x509 -in "$TLS/site.pem" -noout -ext subjectAltName 2>/dev/null | grep -q "DNS:$HOST"; then
    openssl req -newkey rsa:2048 -nodes -keyout "$TLS/site.key" -out "$TLS/site.csr" -subj "/CN=$HOST" 2>/dev/null
    printf 'subjectAltName=DNS:%s,DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n' "$HOST" > "$TLS/ext.cnf"
    openssl x509 -req -in "$TLS/site.csr" -CA "$TLS/ca.pem" -CAkey "$TLS/ca.key" -CAcreateserial -out "$TLS/site.pem" -days 825 -extfile "$TLS/ext.cnf" 2>/dev/null
    chmod 600 "$TLS"/*.key
    proxy_stop
  fi
  if ! proxy_running; then
    nohup python3 "$ROOT/scripts/local/tls-proxy.py" --cert "$TLS/site.pem" --key "$TLS/site.key" \
      --listen "127.0.0.1:$PROXY_PORT" --upstream "127.0.0.1:$SITE_PORT" >> "$STATE/tls-proxy.log" 2>&1 &
    echo $! > "$PROXY_PID"; sleep 1
  fi
  curl -s -o /dev/null --cacert "$TLS/ca.pem" --resolve "$HOST:$PROXY_PORT:127.0.0.1" "https://$HOST:$PROXY_PORT/loginAuth/login.php" \
    || { echo "TLS proxy not answering — see $STATE/tls-proxy.log"; exit 1; }
  say "https://$HOST -> 127.0.0.1:$PROXY_PORT -> local site :$SITE_PORT"
}
proxy_running() { lsof -nP -iTCP:"$PROXY_PORT" -sTCP:LISTEN -t >/dev/null 2>&1; }   # whoever holds the port, pid file or not
proxy_stop() { lsof -nP -iTCP:"$PROXY_PORT" -sTCP:LISTEN -t 2>/dev/null | xargs kill 2>/dev/null || true; rm -f "$PROXY_PID"; }

# ── 3. Emulator: boot, hosts entry, trust the local CA, reverse port ───────────
emu_serial() { "$ADB" devices | awk '/^emulator-/{print $1; exit}'; }
emu_up() {
  if [ -z "$(emu_serial)" ]; then
    if ! "$EMU" -list-avds 2>/dev/null | grep -qx "$AVD"; then
      [ -d "$SDK/system-images/android-34/google_apis/x86_64" ] \
        || { echo "System image missing: yes | $SDK/cmdline-tools/latest/bin/sdkmanager --install emulator \"$SYSIMG\""; exit 1; }
      echo no | "$SDK/cmdline-tools/latest/bin/avdmanager" create avd -n "$AVD" -k "$SYSIMG" -d pixel_6 >/dev/null
    fi
    say "Booting emulator $AVD (first boot takes a few minutes) …"
    # Renderer (2026-10-26, iMac 2017): swiftshader never got past the boot animation (composer pegged a core);
    # -gpu host booted but QEMU's main loop hung and the emulator died minutes later; angle_indirect boots in ~80 s.
    nohup "$EMU" -avd "$AVD" -writable-system -no-snapshot-load -no-boot-anim -no-audio -no-metrics -gpu angle_indirect -memory 2048 -cores 2 >> "$STATE/emulator.log" 2>&1 &
  fi
  "$ADB" wait-for-device
  for _ in $(seq 1 200); do [ "$("$ADB" shell getprop sys.boot_completed 2>/dev/null | tr -d '\r')" = 1 ] && break; sleep 3; done
  [ "$("$ADB" shell getprop sys.boot_completed 2>/dev/null | tr -d '\r')" = 1 ] || { echo "Emulator did not finish booting"; exit 1; }
  "$ADB" root >/dev/null 2>&1 || true; sleep 2; "$ADB" wait-for-device
  # Hosts entry: the app only speaks https to a plain hostname, and Tenant.php needs <key>.ittai.ca as the Host.
  if ! "$ADB" shell cat /system/etc/hosts 2>/dev/null | grep -q "$HOST"; then
    if ! "$ADB" remount >/dev/null 2>&1; then
      "$ADB" disable-verity >/dev/null 2>&1 || true; "$ADB" reboot; "$ADB" wait-for-device
      for _ in $(seq 1 200); do [ "$("$ADB" shell getprop sys.boot_completed 2>/dev/null | tr -d '\r')" = 1 ] && break; sleep 3; done
      "$ADB" root >/dev/null 2>&1 || true; sleep 2; "$ADB" wait-for-device; "$ADB" remount >/dev/null
    fi
    printf '127.0.0.1       localhost\n::1             ip6-localhost\n127.0.0.1       %s\n' "$HOST" > "$STATE/hosts"
    "$ADB" push "$STATE/hosts" /system/etc/hosts >/dev/null
    say "Emulator hosts: $HOST -> 127.0.0.1"
  fi
  # The local CA in the emulator's USER store (Android names the file by the old-style subject hash).
  local hash; hash="$(openssl x509 -in "$TLS/ca.pem" -noout -subject_hash_old)"
  if ! "$ADB" shell ls "/data/misc/user/0/cacerts-added/$hash.0" >/dev/null 2>&1; then
    "$ADB" shell mkdir -p /data/misc/user/0/cacerts-added
    "$ADB" push "$TLS/ca.pem" "/data/misc/user/0/cacerts-added/$hash.0" >/dev/null
    "$ADB" shell "chmod 644 /data/misc/user/0/cacerts-added/$hash.0; chown system:system /data/misc/user/0/cacerts-added/$hash.0"
    say "Local CA installed in the emulator's user store ($hash.0)"
  fi
  # A slow emulator throws "System UI isn't responding" dialogs over the app every minute; they are noise here.
  "$ADB" shell settings put global hide_error_dialogs 1 >/dev/null 2>&1 || true
  "$ADB" reverse "tcp:443" "tcp:$PROXY_PORT" >/dev/null
  say "adb reverse: emulator :443 -> Mac :$PROXY_PORT"
}

# ── 4. Build + install ─────────────────────────────────────────────────────────
build() {
  say "Building tenanttest DEBUG apk for $HOST …"
  ( cd "$ANDROID_DIR" && ./android/gradlew -p android assembleTenanttestDebug "-PtenantTestHost=$HOST" ${GRADLE_OFFLINE:+--offline} -q )
  ls -la "$APK"
}
install_app() {
  [ -f "$APK" ] || build
  "$ADB" install -r "$APK" | tail -1
  "$ADB" shell monkey -p "$APP_ID" -c android.intent.category.LAUNCHER 1 >/dev/null 2>&1 || true
  say "$APP_ID installed and opened"
}

logins() {
  awk -v k="$TENANT_KEY" 'index($0, k ".test") || (/^#/ && index($0, k ".ittai.ca"))' "$ROOT/scripts/local/.credentials.local"
}
status() {
  "$ROOT/scripts/local-site.sh" status | sed -n '2,5p'
  proxy_running && echo "TLS proxy : https://127.0.0.1:$PROXY_PORT (pid $(cat "$PROXY_PID"))" || echo "TLS proxy : stopped"
  local s; s="$(emu_serial)"
  echo "Emulator  : ${s:-none} ${s:+Android $("$ADB" shell getprop ro.build.version.release 2>/dev/null | tr -d '\r')}"
  echo "Tenant    : https://$HOST (db mowology_$TENANT_KEY)"
  [ -f "$APK" ] && echo "APK       : $APK ($(date -r "$APK" '+%Y-%m-%d %H:%M'))"
}

case "${1:-}" in
  up)      site_up; tls_up; emu_up; install_app; echo; logins ;;
  build)   build ;;
  install) install_app ;;
  logins)  logins ;;
  status)  status ;;
  down)    proxy_stop; s="$(emu_serial)"; [ -n "$s" ] && "$ADB" -s "$s" emu kill >/dev/null 2>&1 || true; echo "proxy and emulator stopped" ;;
  *) sed -n '2,12p' "$0"; exit 1 ;;
esac
