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

# Main-thread stall decisions and same-build crash-loop evaluation.
cp "$ROOT/ios/LogicTests/CrashGuardTests.swift" "$OUT/main.swift"
run crashguards -DCRASH_GUARD_LOGIC_ONLY "$SRC/Core/Diagnostics/HangWatchdog.swift" \
  "$SRC/Core/Diagnostics/CrashLoopBreaker.swift" "$SRC/Core/Diagnostics/AppDiagnostics.swift" "$OUT/main.swift"

# swiftc treats the file named main.swift as the entry point — copy the checks to that name.
cp "$ROOT/ios/LogicTests/BatchCameraModelsTests.swift" "$OUT/main.swift"
run batchcamera "$SRC/Features/Camera/BatchCameraModels.swift" "$OUT/main.swift"

# Receipt camera (2026-11-09): when it may shoot, retakes, the switch.
cp "$ROOT/ios/LogicTests/ReceiptSteadyGateTests.swift" "$OUT/main.swift"
run receiptsteady "$SRC/Features/Camera/ReceiptSteadyGate.swift" "$OUT/main.swift"

# Meniscus tab bar: notch shape (cups the bead with the gap, joins the edge horizontally, symmetric), slots, nearest tab.
cp "$ROOT/ios/LogicTests/MiaWhenTests.swift" "$OUT/main.swift"
run miawhen "$SRC/Features/Team/MiaWhen.swift" "$OUT/main.swift"

# Add → Quote totals (2026-11-08).
cp "$ROOT/ios/LogicTests/QuoteMathTests.swift" "$OUT/main.swift"
run quotemath "$SRC/Features/Tasks/QuoteMath.swift" "$OUT/main.swift"

# Offline outbox for what the phone creates (2026-11-08): whose entries, what to send, the status line.
cp "$ROOT/ios/LogicTests/CreateOutboxTests.swift" "$OUT/main.swift"
run createoutbox "$SRC/Core/Offline/CreateOutboxLogic.swift" "$OUT/main.swift"

# The head's spine (2026-11-07): right-hand counts, open state, role lines.
cp "$ROOT/ios/LogicTests/HeadSpineTests.swift" "$OUT/main.swift"
run headspine "$SRC/Features/Team/HeadSpineLogic.swift" "$OUT/main.swift"

cp "$ROOT/ios/LogicTests/MeniscusGeometryTests.swift" "$OUT/main.swift"
run meniscus "$SRC/Core/Design/Ittai/ItMeniscusGeometry.swift" "$SRC/Core/Design/Ittai/ItTokens.swift" "$OUT/main.swift"

# Opt-in Bar lab: launch parsing, keyboard overlap/curve, Dynamic Type metrics and dial height.
cp "$ROOT/ios/LogicTests/BarLabTests.swift" "$OUT/main.swift"
run barlab "$SRC/Core/Design/Ittai/ItTokens.swift" "$SRC/Core/Design/Ittai/ItBarLab.swift" "$OUT/main.swift"

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
  "$SRC/Core/Models/BusinessIdentityModels.swift" "$SRC/Core/Network/APIEndpoints.swift" "$SRC/Core/Network/Server.swift" \
  "$SRC/Core/Network/APIError.swift" "$SRC/Core/Diagnostics/AppDiagnostics.swift" \
  "$SRC/Core/Diagnostics/ErrorDetails.swift" "$SRC/Core/Network/KnowledgeAPI.swift" \
  "$SRC/Features/Team/BusinessIdentityViewModel.swift" "$ROOT/ios/LogicTests/BusinessIdentityTests.swift"

# Persisted diagnostics, rotation/redaction and exact HTTP/transport/decoding errors.
run diagnostics -parse-as-library "$SRC/Core/Diagnostics/AppDiagnostics.swift" \
  "$SRC/Core/Diagnostics/ErrorDetails.swift" "$SRC/Core/Network/APIError.swift" \
  "$ROOT/ios/LogicTests/DiagnosticsTests.swift"

# Schedule interrupts only for a recorded, readable pending Otto decision.
run ottoattention -parse-as-library "$SRC/Core/Models/BookkeeperModels.swift" \
  "$SRC/Core/Models/TeamHeadModels.swift" "$SRC/Features/Team/MiaWhen.swift" "$ROOT/ios/LogicTests/OttoAttentionTests.swift"

# Schedule timeline (Tim, 2026-11-05): stop states, one-line windows, timer text, next pick, left-site memory, switch.
cp "$ROOT/ios/LogicTests/ScheduleTimelineTests.swift" "$OUT/main.swift"
run scheduletimeline "$SRC/Core/Design/MWTheme.swift" "$SRC/Core/Models/ServiceHistory.swift" "$SRC/Core/Models/Visit.swift" \
  "$SRC/Core/Models/Stop.swift" "$SRC/Features/Schedule/ScheduleTimelineRules.swift" "$OUT/main.swift"

# Display-only schedule names and the 04:00-local first-open assembly gate.
cp "$ROOT/ios/LogicTests/SchedulePresentationTests.swift" "$OUT/main.swift"
run schedulepresentation "$SRC/Core/Design/MWTheme.swift" "$SRC/Core/Models/ServiceHistory.swift" "$SRC/Core/Models/Visit.swift" \
  "$SRC/Core/Models/Stop.swift" "$SRC/Core/Design/Ittai/ItTokens.swift" \
  "$SRC/Core/Design/Ittai/ItTheme.swift" "$SRC/Core/Design/Ittai/ItSymbol.swift" \
  "$SRC/Core/Design/Ittai/ItSymbolAssembly.swift" "$OUT/main.swift"

# Day carousel (2026-11-08): the strip's window, the day on the line, when it has landed, today after midnight.
cp "$ROOT/ios/LogicTests/DayCarouselTests.swift" "$OUT/main.swift"
run daycarousel "$SRC/Core/Models/ScheduleDay.swift" "$SRC/Features/Schedule/DayCarouselRules.swift" "$OUT/main.swift"

cp "$ROOT/ios/LogicTests/ScheduleFilterTests.swift" "$OUT/main.swift"
run schedulefilter "$SRC/Features/Schedule/ScheduleFilterRules.swift" "$OUT/main.swift"

# Server address (2026-11-09): the one place the app's server lives; paths become absolute URLs.
cp "$ROOT/ios/LogicTests/ServerTests.swift" "$OUT/main.swift"
run server "$SRC/Core/Network/Server.swift" "$OUT/main.swift"

# Phone contacts onboarding (2026-11-11): display name, search, select-all-shown, batches of 100, resume point, result tally, wire payload, and the real onboarding map decoding.
cp "$ROOT/ios/LogicTests/PhoneContactsLogicTests.swift" "$OUT/main.swift"
run phonecontacts "$SRC/Features/Onboarding/PhoneContactsLogic.swift" "$SRC/Core/Models/PhoneContactsModels.swift" "$ROOT/ios/LogicTests/GoogleContactsLogicChecks.swift" "$OUT/main.swift"

# MetricKit (2026-11-13): one readable line per Apple diagnostic (real crash + CPU-exception payloads) and the daily metrics row.
cp "$ROOT/ios/LogicTests/MetricKitSummaryTests.swift" "$OUT/main.swift"
swiftc -suppress-warnings -o "$OUT/metrickit" "$SRC/Core/Diagnostics/MetricKitSummary.swift" "$OUT/main.swift"
"$OUT/metrickit" "$ROOT/ios/LogicTests/Fixtures"

# Receipts empty-screen wording and the one-time Kaname welcome (2026-11-13).
cp "$ROOT/ios/LogicTests/KanameWelcomeTests.swift" "$OUT/main.swift"
run kanamewelcome "$SRC/Features/Receipts/ReceiptsEmptyWording.swift" "$SRC/Features/Splash/KanameWelcome.swift" "$OUT/main.swift"
