#!/usr/bin/env bash
# run.sh — compile and run the iOS pure-logic checks on the Mac (no XCTest target exists).
# Each pair below is <app source file> + <checks file>; add a line per new logic file.
set -eu
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="$ROOT/ios/MowologyCRM/MowologyCRM"
OUT="$(mktemp -d "${TMPDIR:-/tmp}/mowology-ios-logic-tests.XXXXXX")"
mkdir -p "$OUT"
export CLANG_MODULE_CACHE_PATH="$OUT/module-cache"

run() {
  local name="$1"; shift
  swiftc -suppress-warnings -o "$OUT/$name" "$@"
  "$OUT/$name"
}

python3 -B "$ROOT/ios/LogicTests/test_swift_bridge.py"

# swiftc treats the file named main.swift as the entry point — copy the checks to that name.
cp "$ROOT/ios/LogicTests/BatchCameraModelsTests.swift" "$OUT/main.swift"
run batchcamera "$SRC/Features/Camera/BatchCameraModels.swift" "$OUT/main.swift"

# Ittai v1.2 design layer: contrast in light/dark/field, precedence, frozen symbol ladder, hexagon tap areas.
cp "$ROOT/ios/LogicTests/IttaiDesignTests.swift" "$OUT/main.swift"
run ittaidesign -DKNOWLEDGE_GEOMETRY_ONLY "$SRC/Core/Design/Ittai/ItTokens.swift" "$SRC/Core/Design/Ittai/ItTheme.swift" \
  "$SRC/Core/Design/Ittai/ItSymbol.swift" "$SRC/Core/Design/Ittai/ItHexagonGeometry.swift" \
  "$SRC/Features/Splash/CharlieBrainSplash.swift" \
  "$SRC/Core/Design/Ittai/ItKnowledgeGeometry.swift" \
  "$SRC/Core/Design/Ittai/ItIcons.swift" "$SRC/Core/Design/Ittai/ItComponents.swift" \
  "$SRC/Core/Models/KnowledgeModels.swift" "$SRC/Core/Models/BusinessIdentityModels.swift" \
  "$SRC/Core/Design/Ittai/ItKnowledgeBrain.swift" "$SRC/Core/Design/Ittai/ItPennyOnboarding.swift" "$OUT/main.swift"

# Business setup: real models, URLs and view model against a fake transport, in TestFlight Release mode.
run businessidentity -O -parse-as-library "$SRC/Core/Models/KnowledgeModels.swift" \
  "$SRC/Core/Models/BusinessIdentityModels.swift" "$SRC/Core/Network/APIEndpoints.swift" \
  "$SRC/Core/Network/APIError.swift" "$SRC/Core/Diagnostics/AppDiagnostics.swift" \
  "$SRC/Core/Diagnostics/ErrorDetails.swift" "$SRC/Core/Network/KnowledgeAPI.swift" \
  "$SRC/Features/Team/BusinessIdentityViewModel.swift" "$ROOT/ios/LogicTests/BusinessIdentityTests.swift"

# Persisted diagnostics, rotation/redaction and exact HTTP/transport/decoding errors.
run diagnostics -parse-as-library "$SRC/Core/Diagnostics/AppDiagnostics.swift" \
  "$SRC/Core/Diagnostics/ErrorDetails.swift" "$SRC/Core/Network/APIError.swift" \
  "$ROOT/ios/LogicTests/DiagnosticsTests.swift"

# Schedule interrupts only for a recorded, readable pending Otto decision.
run ottoattention -parse-as-library "$SRC/Core/Models/BookkeeperModels.swift" \
  "$SRC/Core/Models/TeamHeadModels.swift" "$ROOT/ios/LogicTests/OttoAttentionTests.swift"
