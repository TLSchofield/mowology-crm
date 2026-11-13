import SwiftUI

func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() { fatalError(message) }
}

expect(ItBarLab.launchOverrides(arguments: ["app"]) == [], "missing launch flag")
expect(ItBarLab.launchOverrides(arguments: ["app", "-mwBarLab", "haptics,dialScroll"]) == ["haptics", "dialScroll"], "launch subset")

let screen = CGRect(x: 0, y: 0, width: 390, height: 844)
expect(ItBarLab.keyboardOverlapsBar(keyboardFrame: CGRect(x: 0, y: 500, width: 390, height: 344), screenBounds: screen, barHeight: 80), "docked keyboard overlaps")
expect(!ItBarLab.keyboardOverlapsBar(keyboardFrame: CGRect(x: 100, y: 650, width: 200, height: 100), screenBounds: screen, barHeight: 80), "floating keyboard misses bar")
expect(!ItBarLab.keyboardOverlapsBar(keyboardFrame: CGRect(x: 0, y: 900, width: 390, height: 300), screenBounds: screen, barHeight: 80), "offscreen keyboard")

expect(ItBarLab.keyboardCurve(rawValue: 0) == .easeInOut, "ease in/out")
expect(ItBarLab.keyboardCurve(rawValue: 1) == .easeIn, "ease in")
expect(ItBarLab.keyboardCurve(rawValue: 2) == .easeOut, "ease out")
expect(ItBarLab.keyboardCurve(rawValue: 3) == .linear, "linear")
expect(ItBarLab.keyboardCurve(rawValue: 7) == .standardPrivate, "private keyboard curve")
_ = ItBarLab.keyboardAnimation(duration: 0.25, curveRaw: 7)

let sizes: [DynamicTypeSize] = [.xSmall, .small, .medium, .large, .xLarge, .xxLarge, .xxxLarge,
                                .accessibility1, .accessibility2, .accessibility3, .accessibility4, .accessibility5]
let metrics = sizes.map(ItBarLab.barLabelMetrics)
let expectedScales: [CGFloat] = [0.82, 0.88, 0.94, 1, 1.12, 1.23, 1.35, 1.64, 1.64, 1.64, 1.64, 1.64]
for (index, scale) in expectedScales.enumerated() {
    let expectedSize = ItMeniscusToken.labelSize * scale
    expect(metrics[index].fontSize == expectedSize, "font metric for size index \(index)")
    expect(metrics[index].extraBarHeight == max(0, expectedSize - ItMeniscusToken.labelSize),
           "height metric for size index \(index)")
}

expect(ItBarLab.dialMaxHeight(geometryHeight: 800, topSafeArea: 50, barReserve: 80) == 670, "available dial height")
expect(ItBarLab.dialMaxHeight(geometryHeight: 100, topSafeArea: 60, barReserve: 80) == 0, "dial height clamps")
expect(ItBarLab.dialItemsPerColumn(itemCount: 5, availableHeight: 600, itemHeight: 56, spacing: 12) == 5, "portrait: all five choices in one column")
expect(ItBarLab.dialItemsPerColumn(itemCount: 5, availableHeight: 229, itemHeight: 56, spacing: 12) == 3, "landscape (Tim's screenshot): three per column, the rest in a second")
expect(ItBarLab.dialItemsPerColumn(itemCount: 5, availableHeight: 10, itemHeight: 56, spacing: 12) == 1, "never fewer than one per column")
expect(ItBarLab.dialItemsPerColumn(itemCount: 3, availableHeight: 600, itemHeight: 56, spacing: 12) == 3, "never more than the choices there are")
expect(ItBarLab.dialItemsPerColumn(itemCount: 0, availableHeight: 600, itemHeight: 56, spacing: 12) == 1, "no choices is still safe")

print("BarLabTests passed")
