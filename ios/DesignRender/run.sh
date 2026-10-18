#!/usr/bin/env bash
# run.sh — render the Ittai design layer (Core/Design/Ittai) to PNGs on the Mac, light/dark/field.
# This iMac has no iOS simulator, so this is how the design views are reviewed before a TestFlight build.
# usage: bash ios/DesignRender/run.sh [out-dir]   (default: $TMPDIR/ittai-ios-render)
set -eu
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="$ROOT/ios/MowologyCRM/MowologyCRM"
OUT="${1:-${TMPDIR:-/tmp}/ittai-ios-render}"
mkdir -p "$OUT"
export CLANG_MODULE_CACHE_PATH="$OUT/module-cache"
BIN="$OUT/.render"
# swiftc treats the file named main.swift as the entry point — copy the driver to that name.
cp "$ROOT/ios/DesignRender/RenderMain.swift" "$OUT/main.swift"
swiftc -suppress-warnings -DKNOWLEDGE_GEOMETRY_ONLY -o "$BIN" "$SRC"/Core/Models/KnowledgeModels.swift "$SRC"/Core/Models/BusinessIdentityModels.swift "$SRC"/Features/Splash/CharlieBrainSplash.swift "$SRC"/Core/Design/Ittai/*.swift "$OUT/main.swift"
"$BIN" "$OUT" "$SRC/Resources/Assets.xcassets"
echo "renders in $OUT"
