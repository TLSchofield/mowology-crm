#!/usr/bin/env bash
# native-shots.sh <outdir> — screenshots of the iOS simulator and an Android device/emulator IF one is running.
# Read-only: never boots, installs or launches anything (so it can't change what's on a device). Exit 0 always.
OUT="${1:?outdir}"; mkdir -p "$OUT"; n=0
ADB="${ADB:-$HOME/Library/Android/sdk/platform-tools/adb}"
if xcrun simctl list devices booted 2>/dev/null | grep -q '(Booted)'; then
  xcrun simctl io booted screenshot "$OUT/ios-current.png" >/dev/null 2>&1 && n=$((n+1)) && echo "ios: captured current screen"
else echo "ios: no simulator booted — not checked"; fi
if [ -x "$ADB" ] && "$ADB" devices 2>/dev/null | awk 'NR>1 && $2=="device"' | grep -q .; then
  "$ADB" exec-out screencap -p >"$OUT/android-current.png" 2>/dev/null && n=$((n+1)) && echo "android: captured current screen"
else echo "android: no device/emulator — not checked"; fi
# Latest daily parity pairs (made by scripts/parity) are reused rather than re-captured.
P="$HOME/.mowology-parity/shots"
if [ -d "$P" ]; then L="$(ls -dt "$P"/*/ 2>/dev/null | head -1)"; [ -n "$L" ] && cp "$L"*-pair.png "$OUT/" 2>/dev/null && echo "parity: reused $(ls "$OUT" | grep -c pair) pair image(s)"; fi
exit 0
