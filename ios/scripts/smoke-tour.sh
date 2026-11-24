#!/usr/bin/env bash
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PROJECT="$ROOT/ios/MowologyCRM/MowologyCRM.xcodeproj"
DERIVED="$(mktemp -d "${TMPDIR:-/tmp}/mowology-smoke-tour.XXXXXX")"
LOG="$DERIVED/simulator.log"

cleanup() {
  if [ -n "${LOG_PID:-}" ]; then kill "$LOG_PID" 2>/dev/null || true; fi
}
trap cleanup EXIT

DEVICE_ID="$(xcrun simctl list devices available -j | python3 -c '
import json, sys
devices = json.load(sys.stdin)["devices"]
for runtime in devices.values():
    for device in runtime:
        if device["name"] == "iPhone 15":
            print(device["udid"])
            raise SystemExit
raise SystemExit("No available iPhone 15 simulator")
')"

xcrun simctl boot "$DEVICE_ID" 2>/dev/null || true
open -a Simulator --args -CurrentDeviceUDID "$DEVICE_ID"
xcrun simctl bootstatus "$DEVICE_ID" -b

xcodebuild -project "$PROJECT" -scheme MowologyCRM -configuration Debug \
  -destination "platform=iOS Simulator,id=$DEVICE_ID" -derivedDataPath "$DERIVED" \
  CODE_SIGNING_ALLOWED=NO build

APP="$(find "$DERIVED/Build/Products/Debug-iphonesimulator" -maxdepth 1 -name '*.app' -print -quit)"
if [ -z "$APP" ]; then echo "Built app was not found" >&2; exit 1; fi
BUNDLE_ID="$(/usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' "$APP/Info.plist")"
xcrun simctl install "$DEVICE_ID" "$APP"
# The tour prints its marker to the app's console; read that (os_log capture missed it).
xcrun simctl launch --console --terminate-running-process "$DEVICE_ID" "$BUNDLE_ID" -mwSmokeTour >"$LOG" 2>&1 &
LOG_PID=$!

for _ in $(seq 1 "${SMOKE_TIMEOUT:-90}"); do
  if grep -q 'SMOKE_TOUR_OK' "$LOG"; then
    echo "SMOKE_TOUR_OK"
    exit 0
  fi
  sleep 1
done

echo "Smoke tour did not complete within ${SMOKE_TIMEOUT:-90} seconds" >&2
tail -100 "$LOG" >&2
exit 1
