#!/usr/bin/env bash
# run.sh — compile and run the iOS pure-logic checks on the Mac (no XCTest target exists).
# Each pair below is <app source file> + <checks file>; add a line per new logic file.
set -eu
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="$ROOT/ios/MowologyCRM/MowologyCRM"
OUT="${TMPDIR:-/tmp}/mowology-ios-logic-tests"
mkdir -p "$OUT"

run() {
  local name="$1"; shift
  swiftc -suppress-warnings -o "$OUT/$name" "$@"
  "$OUT/$name"
}

# swiftc treats the file named main.swift as the entry point — copy the checks to that name.
cp "$ROOT/ios/LogicTests/BatchCameraModelsTests.swift" "$OUT/main.swift"
run batchcamera "$SRC/Features/Camera/BatchCameraModels.swift" "$OUT/main.swift"
