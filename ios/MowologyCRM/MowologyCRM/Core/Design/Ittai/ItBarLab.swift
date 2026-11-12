//
//  ItBarLab.swift
//  MowologyCRM
//

import SwiftUI

/// Defines the device-local Bar lab switches, launch overrides, and testable layout rules.
enum ItBarLab {
    static let hapticsKey = "ittai.bar.haptics"
    static let scrollTopKey = "ittai.bar.scrollTop"
    static let keyboardCurveKey = "ittai.bar.keyboardCurve"
    static let largeLabelsKey = "ittai.bar.largeLabels"
    static let dialScrollKey = "ittai.bar.dialScroll"
    /// ON = the system search drawer (`.searchable`); OFF (the default since 2026-11-12) = a plain field in the list.
    /// Every freeze in the diagnostic log followed the first visit to the Search tab.
    static let searchDrawerKey = "ittai.bar.searchDrawer"

    /// Returns the lab names requested by a `-mwBarLab` comma-separated launch argument.
    static func launchOverrides(arguments: [String]) -> Set<String> {
        guard let flag = arguments.firstIndex(of: "-mwBarLab"), arguments.indices.contains(flag + 1) else { return [] }
        return Set(arguments[flag + 1].split(separator: ",").map(String.init))
    }

    /// Combines a stored opt-in with a DEBUG-only, non-persisted launch override.
    static func isEnabled(_ name: String, stored: Bool, arguments: [String] = ProcessInfo.processInfo.arguments) -> Bool {
#if DEBUG
        stored || launchOverrides(arguments: arguments).contains(name)
#else
        stored
#endif
    }

    /// Returns whether the keyboard reaches the top of the bar at the bottom of the screen.
    static func keyboardOverlapsBar(keyboardFrame: CGRect, screenBounds: CGRect, barHeight: CGFloat) -> Bool {
        guard keyboardFrame.intersects(screenBounds), keyboardFrame.height > 0 else { return false }
        let barFrame = CGRect(x: screenBounds.minX, y: screenBounds.maxY - barHeight,
                              width: screenBounds.width, height: barHeight)
        return keyboardFrame.minY < barFrame.minY && keyboardFrame.intersects(barFrame)
    }

    /// The keyboard curve categories published by UIKit, plus its standard private curve.
    enum KeyboardCurve: Equatable { case easeInOut, easeIn, easeOut, linear, standardPrivate }

    /// Maps UIKit's curve raw value to a stable category that can be tested without rendering.
    static func keyboardCurve(rawValue: Int) -> KeyboardCurve {
        switch rawValue {
        case 1: return .easeIn
        case 2: return .easeOut
        case 3: return .linear
        case 7: return .standardPrivate
        default: return .easeInOut
        }
    }

    /// Maps a keyboard duration and curve to the matching SwiftUI animation.
    static func keyboardAnimation(duration: Double, curveRaw: Int) -> Animation {
        switch keyboardCurve(rawValue: curveRaw) {
        case .easeInOut: return .easeInOut(duration: duration)
        case .easeIn: return .easeIn(duration: duration)
        case .easeOut: return .easeOut(duration: duration)
        case .linear: return .linear(duration: duration)
        // UIKit's undocumented raw curve 7 is conventionally approximated by these control points.
        case .standardPrivate: return .timingCurve(0.17, 0.59, 0.4, 0.77, duration: duration)
        }
    }

    /// Returns a label font and the extra bar body height required at this Dynamic Type size.
    static func barLabelMetrics(contentSize: DynamicTypeSize) -> (fontSize: CGFloat, extraBarHeight: CGFloat) {
        let capped = min(contentSize, .accessibility1)
        let scale: CGFloat
        switch capped {
        case .xSmall: scale = 0.82
        case .small: scale = 0.88
        case .medium: scale = 0.94
        case .large: scale = 1
        case .xLarge: scale = 1.12
        case .xxLarge: scale = 1.23
        case .xxxLarge: scale = 1.35
        default: scale = 1.64
        }
        let size = ItMeniscusToken.labelSize * scale
        return (size, max(0, size - ItMeniscusToken.labelSize))
    }

    /// Clamps the dial's scroll viewport to the space between the top safe area and the bar reserve.
    static func dialMaxHeight(geometryHeight: CGFloat, topSafeArea: CGFloat, barReserve: CGFloat) -> CGFloat {
        max(0, geometryHeight - topSafeArea - barReserve)
    }

}

extension Notification.Name {
    static let mwSearchTop = Notification.Name("ca.mowology.searchTop")
    static let mwAccountHome = Notification.Name("ca.mowology.accountHome")
}
