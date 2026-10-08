#!/usr/bin/env bash
# compile-check.sh — "does the iOS app compile and link?" on the iMac (Xcode 15.2).
#
# Why this exists (2026-10-08): this Mac has NO iOS simulator runtime installed, so
#   xcodebuild -scheme MowologyCRM -destination 'generic/platform=iOS Simulator'
# fails with "Unable to find a destination", and building the TARGET instead stops at the
# asset catalog ("Failed to locate any simulator runtime") before any Swift is compiled.
# Building the target with ContinueBuildingAfterErrors compiles and links every Swift file;
# the only expected failure is that asset-catalog step.
#
# Usage:  bash ios/compile-check.sh           → prints Swift errors/warnings in changed files + verdict
# Exit 0 when the only error is the asset catalog and the app binary was linked.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${TMPDIR:-/tmp}/mowology-ios-compile-check"
mkdir -p "$OUT"
LOG="$OUT/build.log"

xcodebuild build \
  -project "$ROOT/ios/MowologyCRM/MowologyCRM.xcodeproj" \
  -target MowologyCRM -configuration Debug -sdk iphoneos \
  CODE_SIGNING_ALLOWED=NO -IDEBuildingContinueBuildingAfterErrors=YES \
  SYMROOT="$OUT/build" OBJROOT="$OUT/obj" > "$LOG" 2>&1

SWIFT_ERRORS=$(grep -E "\.swift:[0-9]+:[0-9]+: error:" "$LOG" | sort -u)
OTHER_ERRORS=$(grep -E "error:" "$LOG" | grep -v "\.swift:" | grep -v "Assets.xcassets" | sort -u)
LINKED=$(grep -c "^Ld " "$LOG")

if [ -n "$SWIFT_ERRORS" ]; then echo "$SWIFT_ERRORS"; fi
if [ -n "$OTHER_ERRORS" ]; then echo "$OTHER_ERRORS"; fi
CHANGED=$(cd "$ROOT" && git diff --name-only HEAD -- 'ios/*.swift' | xargs -n1 basename 2>/dev/null | paste -sd'|' -)
if [ -n "$CHANGED" ]; then
  grep -E "warning:" "$LOG" | grep -E "($CHANGED)" | sort -u
fi

if [ -z "$SWIFT_ERRORS" ] && [ -z "$OTHER_ERRORS" ] && [ "$LINKED" -gt 0 ]; then
  echo "COMPILE OK — every Swift file compiled and the app linked (asset catalog skipped: no simulator runtime). Log: $LOG"
  exit 0
fi
echo "COMPILE FAILED — log: $LOG"
exit 1
