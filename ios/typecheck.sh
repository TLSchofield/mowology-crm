#!/usr/bin/env bash
# Verify the entire app against the installed iPhone SDK without a simulator/device.
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SDK="$(xcrun --sdk iphoneos --show-sdk-path)"
OUT="$(mktemp -d "${TMPDIR:-/tmp}/mowology-ios-typecheck.XXXXXX")"
trap 'rm -rf "$OUT"' EXIT
CACHE="${MOWOLOGY_SWIFT_MODULE_CACHE:-${TMPDIR:-/tmp}/mowology-ios-typecheck-cache}"
python3 - "$ROOT/ios/MowologyCRM/MowologyCRM" "$OUT/sources" <<'PY'
import pathlib, sys
sources = sorted(pathlib.Path(sys.argv[1]).rglob('*.swift'))
pathlib.Path(sys.argv[2]).write_text('\n'.join('"' + str(p).replace('\\', '\\\\').replace('"', '\\"') + '"' for p in sources))
print(f'Type-checking {len(sources)} Swift files against the installed iPhone SDK.')
PY
xcrun swiftc -typecheck -whole-module-optimization -target arm64-apple-ios17.0 -sdk "$SDK" \
  -module-name MowologyCRM -module-cache-path "$CACHE" "@$OUT/sources"
echo 'iOS SDK type-check passed.'
