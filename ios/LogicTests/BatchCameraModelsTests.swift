// Logic checks for Features/Camera/BatchCameraModels.swift — the app has no XCTest target,
// so this compiles the pure-logic file alone on the Mac and runs it:
//   bash ios/LogicTests/run.sh
import Foundation
import CoreGraphics

var fails = 0
func check(_ c: Bool, _ m: String) { if !c { fails += 1; print("FAIL: \(m)") } }

// Batch limit + counter + timer
check(BatchCameraLimits.counterText(count: 2) == "2/10", "counter")
check(BatchCameraLimits.canShoot(count: 9), "can shoot 9")
check(!BatchCameraLimits.canShoot(count: 10), "cannot shoot 10")
check(BatchCameraLimits.timerText(seconds: 2.9) == "00:02", "timer 2.9")
check(BatchCameraLimits.timerText(seconds: 61) == "01:01", "timer 61")
check(BatchCameraLimits.timerText(seconds: -3) == "00:00", "timer negative")

// Category availability + server photo_type mapping
check(BatchShotCategory.available(afterUnlocked: false, shotsSoFar: []) == [.before, .during, .additional], "after locked")
check(BatchShotCategory.available(afterUnlocked: false, shotsSoFar: [.before]).contains(.after), "before in batch unlocks after")
check(BatchShotCategory.available(afterUnlocked: true, shotsSoFar: []).count == 4, "all categories")
check(BatchShotCategory.allCases.map(\.rawValue) == ["before", "during", "after", "additional"], "raw values = server photo_type")
let order = BatchShotCategory.uploadOrder([BatchShotCategory.after, .additional, .before, .during, .before]) { $0 }
check(order == [.before, .before, .additional, .during, .after], "upload order \(order)")

// Lens chips
let tri = LensChip.chips(hasUltraWide: true, switchOvers: [2, 6], maxZoom: 123)
check(tri.map(\.label) == [".5", "1", "3"], "triple \(tri.map(\.label))")
check(tri.map(\.zoomFactor) == [1, 2, 6], "triple zoom factors")
check(LensChip.chips(hasUltraWide: true, switchOvers: [2, 10], maxZoom: 200).map(\.label) == [".5", "1", "5"], "5x tele")
check(LensChip.chips(hasUltraWide: true, switchOvers: [2], maxZoom: 30).map(\.label) == [".5", "1", "2"], "dual wide")
check(LensChip.chips(hasUltraWide: false, switchOvers: [], maxZoom: 16).map(\.label) == ["1", "2"], "single wide")
check(LensChip.chips(hasUltraWide: false, switchOvers: [], maxZoom: 1).map(\.label) == ["1"], "no zoom")
check(LensChip.chips(hasUltraWide: false, switchOvers: [2], maxZoom: 16).map(\.label) == ["1", "2"], "wide + tele")
check(LensChip.activeIndex(in: tri, zoomFactor: 1.5) == 0, "active .5")
check(LensChip.activeIndex(in: tri, zoomFactor: 2) == 1, "active 1")
check(LensChip.activeIndex(in: tri, zoomFactor: 8) == 2, "active 3")
check(LensChip.format(1.43) == "1.4" && LensChip.format(0.5) == ".5" && LensChip.format(5) == "5", "format")

// Zoom
check(ZoomMath.pinched(startZoom: 2, scale: 0.1, min: 1, max: 20) == 1, "pinch min")
check(ZoomMath.pinched(startZoom: 2, scale: 100, min: 1, max: 20) == 20, "pinch max")
check(ZoomMath.usableMax(deviceMax: 123, wideZoom: 2) == 20, "usable max")

print(fails == 0 ? "ALL PASS" : "\(fails) FAILED")
exit(fails == 0 ? 0 : 1)
