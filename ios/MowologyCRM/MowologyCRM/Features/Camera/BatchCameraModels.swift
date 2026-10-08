//
//  BatchCameraModels.swift
//  MowologyCRM
//
//  Pure logic behind the in-app batch camera (BatchCameraView) — no UIKit/AVFoundation,
//  so it can be checked on the Mac with plain `swiftc` (the app has no test target).
//
//  Mirrors public/crm/js/batch-camera.js: up to 10 shots per session, a "2/10" counter,
//  each shot tagged with the visit photo category it will upload under.
//

import Foundation
import CoreGraphics

// MARK: - Feature flags

enum BatchCameraFeatures {
    /// VIDEO mode in the batch camera. OFF: the server's visit media pipeline is image-only
    /// (MediaUploadService allows jpeg/png/gif/webp and runs getimagesize), and every
    /// job_visit media consumer — PoW screens, Salt report, client PDFs — assumes an image.
    /// Turn on only once the server stores and lists visit videos.
    static let visitVideo = false
}

// MARK: - Limits

enum BatchCameraLimits {
    /// Same cap as the web/Android BatchCamera (`maxPhotos: 10`).
    static let maxShots = 10
    /// Longest clip the VIDEO mode records before it stops itself.
    static let maxVideoSeconds: Double = 60
    /// Long edge of the thumbnails kept in memory for the stack / slots.
    static let previewMaxPixel = 600
    /// JPEG quality for captured and edited photos (server re-encodes at 92 anyway).
    static let jpegQuality: Double = 0.9

    static func canShoot(count: Int, max: Int = maxShots) -> Bool { count < max }

    /// "2/10" — the counter on the thumbnail stack.
    static func counterText(count: Int, max: Int = maxShots) -> String { "\(count)/\(max)" }

    /// "00:02" — the red recording timer. Clamped at 0, minutes roll past 59 seconds.
    static func timerText(seconds: Double) -> String {
        let s = Swift.max(0, Int(seconds.rounded(.down)))
        return String(format: "%02d:%02d", s / 60, s % 60)
    }
}

// MARK: - Category

/// The visit photo category a shot uploads under (`photo_type` on /api/schedule/job-photo).
/// The server accepts before | after | additional | during; the phone's list folds
/// during into the "more photos" strip (VisitPhotoService::normalizeType).
enum BatchShotCategory: String, CaseIterable, Identifiable {
    case before
    case during
    case after
    case additional

    var id: String { rawValue }

    var label: String {
        switch self {
        case .before:     return "Before"
        case .during:     return "During"
        case .after:      return "After"
        case .additional: return "More"
        }
    }

    /// Which categories can be picked. After stays locked until the job has started or a
    /// before exists — the same rule as the After slot on the visit card — and a before
    /// taken earlier in this same batch counts.
    static func available(afterUnlocked: Bool, shotsSoFar: [BatchShotCategory]) -> [BatchShotCategory] {
        let after = afterUnlocked || shotsSoFar.contains(.before)
        return allCases.filter { $0 != .after || after }
    }

    /// Upload order for a finished batch: befores first (they stamp the visit's implied
    /// start time), afters after everything else (they stamp completion). Stable otherwise.
    static func uploadOrder<T>(_ items: [T], category: (T) -> BatchShotCategory) -> [T] {
        func rank(_ c: BatchShotCategory) -> Int {
            switch c { case .before: return 0; case .during, .additional: return 1; case .after: return 2 }
        }
        return items.enumerated()
            .sorted { (a, b) in
                let ra = rank(category(a.element)), rb = rank(category(b.element))
                return ra == rb ? a.offset < b.offset : ra < rb
            }
            .map(\.element)
    }
}

// MARK: - Lens chips

/// One ".5 / 1× / 5" chip. `zoomFactor` is the device's videoZoomFactor that selects it.
struct LensChip: Equatable {
    /// What the crew sees: 0.5, 1, 3, 5 … (relative to the main wide lens).
    let displayFactor: CGFloat
    let zoomFactor: CGFloat

    /// ".5", "1", "3", "2.5". The selected chip shows the live factor with "×" instead.
    var label: String { LensChip.format(displayFactor) }

    static func format(_ f: CGFloat) -> String {
        let rounded = (f * 10).rounded() / 10
        if rounded < 1 {
            // ".5" like the iOS camera, not "0.5"
            let s = String(format: "%.1f", Double(rounded))
            return s.hasPrefix("0") ? String(s.dropFirst()) : s
        }
        if rounded == rounded.rounded() { return String(Int(rounded)) }
        return String(format: "%.1f", Double(rounded))
    }

    /// Chips for a back camera.
    ///
    /// - `hasUltraWide`: the device is a virtual camera whose first constituent is the
    ///   ultra-wide (builtInTripleCamera / builtInDualWideCamera). Its zoom factor 1.0 is
    ///   then the ultra-wide, and the main wide lens sits at the first switch-over factor.
    /// - `switchOvers`: `virtualDeviceSwitchOverVideoZoomFactors` (empty for a single lens).
    /// - `maxZoom`: the device's usable max zoom; a phone with no telephoto gets a digital
    ///   "2" chip when it can reach it.
    static func chips(hasUltraWide: Bool, switchOvers: [CGFloat], maxZoom: CGFloat) -> [LensChip] {
        let wide: CGFloat = hasUltraWide ? (switchOvers.first ?? 2) : 1
        var chips: [LensChip] = []
        if hasUltraWide { chips.append(LensChip(displayFactor: 1 / wide, zoomFactor: 1)) }
        chips.append(LensChip(displayFactor: 1, zoomFactor: wide))

        // Optical telephoto = a switch-over beyond the wide lens.
        let teleZooms = switchOvers.filter { $0 > wide + 0.01 }
        if let tele = teleZooms.last {
            chips.append(LensChip(displayFactor: tele / wide, zoomFactor: tele))
        } else if maxZoom >= wide * 2 {
            chips.append(LensChip(displayFactor: 2, zoomFactor: wide * 2))
        }
        return chips
    }

    /// The chip that "owns" the current zoom: the largest chip at or below it.
    static func activeIndex(in chips: [LensChip], zoomFactor: CGFloat) -> Int? {
        guard !chips.isEmpty else { return nil }
        var best = 0
        for (i, c) in chips.enumerated() where zoomFactor + 0.01 >= c.zoomFactor { best = i }
        return best
    }
}

// MARK: - Zoom

enum ZoomMath {
    /// Pinch: scale the zoom at gesture start by the pinch scale, clamped to the device range.
    static func pinched(startZoom: CGFloat, scale: CGFloat, min: CGFloat, max: CGFloat) -> CGFloat {
        Swift.min(Swift.max(startZoom * scale, min), max)
    }

    /// Cap digital zoom at 10× the main lens — past that it is just mush.
    static func usableMax(deviceMax: CGFloat, wideZoom: CGFloat) -> CGFloat {
        Swift.min(deviceMax, wideZoom * 10)
    }
}
