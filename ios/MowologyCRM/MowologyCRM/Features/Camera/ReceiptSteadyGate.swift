//
//  ReceiptSteadyGate.swift
//  MowologyCRM
//
//  When the receipt camera may shoot (Tim, 2026-11-09: "doesn't force the camera to be steady so blurred images are
//  being taken"). Pure logic, checked by `bash ios/LogicTests/run.sh`. ReceiptCameraModel feeds it ~20 samples a second.
//
//  The numbers are a cautious start and have not been measured on real hand-held shots: every shot writes what it saw to
//  AppDiagnostics ("ReceiptCamera"), so they can be tuned from real receipts.
//

import Foundation
import CoreGraphics

struct ReceiptSteadySample: Equatable {
    /// Device rotation, radians a second.
    var rotationRate: Double
    /// Device acceleration with gravity removed, in g.
    var acceleration: Double
    /// False while the lens is still hunting for focus or exposure.
    var focusSettled: Bool
    /// The receipt's four corners (0–1 of the frame), nil when none is seen.
    var outline: [CGPoint]?
}

enum ReceiptCameraHint: Equatable {
    case holdStill, focusing, findingReceipt, moveCloser, moveToNext

    var text: String {
        switch self {
        case .holdStill:      return "Hold still"
        case .focusing:       return "Focusing"
        case .findingReceipt: return "Looking for the receipt"
        case .moveCloser:     return "Move closer"
        case .moveToNext:     return "Got it. Next receipt, or Done"
        }
    }
}

struct ReceiptSteadyVerdict: Equatable {
    var hint: ReceiptCameraHint
    var shutterUnlocked: Bool
    var autoShoot: Bool
}

struct ReceiptSteadyGate {
    static let maxRotation = 0.12
    static let maxAcceleration = 0.05
    /// Everything must stay steady this long before a shot.
    static let holdSeconds = 0.5
    /// A corner moving further than this (share of the frame) means the receipt or the view is still moving.
    static let maxOutlineDrift = 0.025
    /// Smaller than this share of the frame and the print is too small to read.
    static let minOutlineArea = 0.15
    /// After a shot, the outline must move this far (or be gone) before the next automatic shot, or it re-shoots the same receipt.
    static let nextReceiptShift = 0.10
    static let nextReceiptGoneSeconds = 0.7
    /// Nobody is ever stuck: in an idling truck the phone may never read as still, so the shutter opens after this long.
    static let unlockAfterSeconds = 10.0

    private var waitStart: TimeInterval?
    private var steadySince: TimeInterval?
    private var outlineAnchor: [CGPoint]?
    private var outlineSince: TimeInterval?
    private var outlineGoneSince: TimeInterval?
    private var shotOutline: [CGPoint]?
    private var armed = true

    mutating func tick(now: TimeInterval, _ s: ReceiptSteadySample) -> ReceiptSteadyVerdict {
        if waitStart == nil { waitStart = now }

        let still = s.rotationRate <= Self.maxRotation && s.acceleration <= Self.maxAcceleration
        if still && s.focusSettled {
            if steadySince == nil { steadySince = now }
        } else {
            steadySince = nil
        }
        let held = steadySince.map { now - $0 >= Self.holdSeconds } ?? false

        if let outline = s.outline {
            outlineGoneSince = nil
            if !armed, let shot = shotOutline, Self.drift(outline, shot) >= Self.nextReceiptShift { armed = true }
            if let anchor = outlineAnchor, Self.drift(outline, anchor) <= Self.maxOutlineDrift {
                // Still the same place.
            } else {
                outlineAnchor = outline
                outlineSince = now
            }
        } else {
            if outlineGoneSince == nil { outlineGoneSince = now }
            if !armed, now - (outlineGoneSince ?? now) >= Self.nextReceiptGoneSeconds { armed = true }
            outlineAnchor = nil
            outlineSince = nil
        }

        let bigEnough = s.outline.map { Self.area($0) >= Self.minOutlineArea } ?? false
        let outlineHeld = outlineSince.map { now - $0 >= Self.holdSeconds } ?? false
        let timedOut = now - (waitStart ?? now) >= Self.unlockAfterSeconds

        let hint: ReceiptCameraHint
        if !armed { hint = .moveToNext }
        else if !still { hint = .holdStill }
        else if !s.focusSettled { hint = .focusing }
        else if s.outline == nil { hint = .findingReceipt }
        else if !bigEnough { hint = .moveCloser }
        else { hint = .holdStill }

        return ReceiptSteadyVerdict(hint: hint,
                                    shutterUnlocked: held || timedOut,
                                    autoShoot: armed && held && outlineHeld && bigEnough)
    }

    /// A good photo was kept: wait for the next receipt.
    mutating func didShoot(now: TimeInterval, outline: [CGPoint]?) {
        armed = outline == nil
        shotOutline = outline
        restartWait(now)
    }

    /// The photo came out soft or failed: stay on this receipt and shoot again.
    mutating func retry(now: TimeInterval) {
        armed = true
        restartWait(now)
    }

    private mutating func restartWait(_ now: TimeInterval) {
        waitStart = now
        steadySince = nil
        outlineAnchor = nil
        outlineSince = nil
        outlineGoneSince = nil
    }

    /// The largest distance any corner has moved.
    static func drift(_ a: [CGPoint], _ b: [CGPoint]) -> Double {
        guard a.count == b.count else { return .greatestFiniteMagnitude }
        return zip(a, b).map { Double(hypot($0.x - $1.x, $0.y - $1.y)) }.max() ?? 0
    }

    /// Share of the frame inside the outline.
    static func area(_ c: [CGPoint]) -> Double {
        guard c.count > 2 else { return 0 }
        var sum: CGFloat = 0
        for i in c.indices {
            let j = (i + 1) % c.count
            sum += c[i].x * c[j].y - c[j].x * c[i].y
        }
        return Double(abs(sum) / 2)
    }
}

/// A soft photo is thrown away and shot again, at most `maxRetakes` times; the sharpest is kept, so faded print that never
/// scores well still gets through (and meets the usual "Hold flat" question if it's really poor).
enum ReceiptRetake {
    /// Same measure as ReceiptPhotoPrep.sharpness. Higher than its 40 because a retake costs a second, not a question:
    /// on synthetic receipts 1 px of blur scored 78–250 and sharp print 520 and up.
    static let retakeBelow = 120.0
    static let maxRetakes = 2

    static func shouldRetake(sharpness: Double, retakesSoFar: Int) -> Bool {
        sharpness < retakeBelow && retakesSoFar < maxRetakes
    }
}

/// `ios.receipt_own_camera: false` in app-version.json goes back to Apple's document camera without a new build.
enum ReceiptCameraSwitch {
    private static let key = "receipt_own_camera_off"

    static func isOn(defaults: UserDefaults = .standard) -> Bool { !defaults.bool(forKey: key) }

    static func apply(serverValue: Bool?, defaults: UserDefaults = .standard) {
        defaults.set(serverValue == false, forKey: key)
    }
}
