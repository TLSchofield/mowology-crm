#!/usr/bin/env bash
# run.sh — render the Ittai design layer (Core/Design/Ittai) to PNGs on the Mac, light/dark/field.
# This iMac has no iOS simulator, so this is how the design views are reviewed before a TestFlight build.
# usage: bash ios/DesignRender/run.sh [out-dir] [schedule-only]   (default: $TMPDIR/ittai-ios-render)
set -eu
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="$ROOT/ios/MowologyCRM/MowologyCRM"
OUT="${1:-${TMPDIR:-/tmp}/ittai-ios-render}"
mkdir -p "$OUT"
export CLANG_MODULE_CACHE_PATH="$OUT/module-cache"
BIN="$OUT/.render"
# swiftc treats the file named main.swift as the entry point — copy the driver to that name.
cp "$ROOT/ios/DesignRender/RenderMain.swift" "$OUT/main.swift"
# Preview macros launch a plugin process that is unavailable in the desktop sandbox.
# Keep the production component bodies unchanged; omit only Xcode preview declarations.
sed '/^#Preview/,$d' "$SRC/Features/Schedule/WeekStripView.swift" > "$OUT/WeekStripView.swift"
sed '/^#Preview/,$d' "$SRC/Features/Schedule/StopCardView.swift" > "$OUT/StopCardView.swift"
swiftc -suppress-warnings -DKNOWLEDGE_GEOMETRY_ONLY -o "$BIN" "$SRC"/Core/Design/MWTheme.swift "$SRC"/Core/Models/Stop.swift "$SRC"/Core/Models/Visit.swift "$SRC"/Core/Models/ScheduleDay.swift "$SRC"/Core/Models/ServiceHistory.swift "$OUT/WeekStripView.swift" "$OUT/StopCardView.swift" "$SRC"/Core/Models/KnowledgeModels.swift "$SRC"/Core/Models/BusinessIdentityModels.swift "$SRC"/Features/Splash/CharlieBrainSplash.swift "$SRC"/Core/Design/Ittai/*.swift "$OUT/main.swift"
"$BIN" "$OUT" "$SRC/Resources/Assets.xcassets" "${2:-all}"
echo "renders in $OUT"
