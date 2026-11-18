//
//  ItMeniscusGeometry.swift
//  MowologyCRM
//
//  The "meniscus" tab bar's geometry (Tim, 2026-11-04: "Do the navbar" / "Build yes" for the iPhone).
//  The active tab's icon rides in a bead that sits half above the bar; the bar's top edge dips around it in
//  a cupped notch with a ring of page colour (gap) all round. Pure CoreGraphics so ios/LogicTests can run it.
//  Same shape as the Android app (ui/ittai/MeniscusGeometry.kt).
//

import CoreGraphics
import Foundation

enum ItMeniscusGeometry {
    /// Returns the array slot occupied by an item identity, or `nil` when the identity is unknown.
    static func slotIndex(forID id: Int, in ids: [Int]) -> Int? {
        ids.firstIndex(of: id)
    }

    /// Converts a logical leading-to-trailing slot into its physical left-to-right index.
    static func physicalSlotIndex(_ logicalIndex: Int, count: Int, isRightToLeft: Bool) -> Int {
        isRightToLeft ? count - logicalIndex - 1 : logicalIndex
    }

    /// Bead diameter.
    static let bead = ItMeniscusToken.bead
    /// Ring of page colour between the bead and the notch.
    static let gap = ItMeniscusToken.gap
    /// How far the bead's top sits above the bar's top edge.
    static let rise = ItMeniscusToken.rise   // 20 until 1.3.84 (the collar touched the selected label, Tim 1.3.82); above 22 the notch rises over the edge
    /// Horizontal run of each shoulder curve where the straight edge bends into the notch.
    static let shoulder = ItMeniscusToken.shoulder
    /// Where a shoulder hands over to the arc, in degrees from the bowl's bottom.
    static let joinDegrees = ItMeniscusToken.joinAngle

    /// The bowl's radius: the bead plus its ring.
    static var bowl: CGFloat { bead / 2 + gap }
    /// How far below the bar's top edge the bead's centre sits.
    static var centreBelowEdge: CGFloat { bead / 2 - rise }
    /// Deepest point of the notch below the edge: the bead's bottom plus the gap.
    static var depth: CGFloat { centreBelowEdge + bowl }
    /// Half the notch's width, from its centre to where it rejoins the straight edge.
    static var halfWidth: CGFloat { bowl + shoulder }

    /// One cubic segment, in the bar's coordinates: y = 0 is the top edge, positive is down.
    struct Seg: Equatable {
        let c1: CGPoint
        let c2: CGPoint
        let end: CGPoint
    }

    static func slotCentre(_ index: Int, count: Int, width: CGFloat) -> CGFloat {
        (CGFloat(index) + 0.5) * width / CGFloat(count)
    }

    /// Linear between slot centres, so an animated fractional position slides the bead.
    static func beadX(_ position: CGFloat, count: Int, width: CGFloat) -> CGFloat {
        (position + 0.5) * width / CGFloat(count)
    }

    /// The tab the bead is over right now (mid-slide too): its icon rides in the bead.
    static func nearest(_ position: CGFloat, count: Int) -> Int {
        min(max(Int(position.rounded()), 0), max(count - 1, 0))
    }

    /// The edge around one notch centred at `cx`. The drawing moves to (cx - halfWidth, 0) first:
    /// a shoulder leaves the edge horizontally, a circular arc keeps exactly `gap` from the bead, and the
    /// mirrored shoulder rejoins the edge horizontally.
    static func notch(cx: CGFloat) -> [Seg] {
        let r = Double(bowl), c = Double(centreBelowEdge), x0 = Double(cx)
        func p(_ a: Double) -> CGPoint { CGPoint(x: x0 + r * sin(a), y: c + r * cos(a)) }
        func t(_ a: Double) -> CGPoint { CGPoint(x: r * cos(a), y: -r * sin(a)) }   // d/da
        func add(_ a: CGPoint, _ b: CGPoint, _ k: Double) -> CGPoint {
            CGPoint(x: a.x + b.x * CGFloat(k), y: a.y + b.y * CGFloat(k))
        }
        let join = joinDegrees * .pi / 180
        let left = x0 - r - Double(shoulder)
        var segs: [Seg] = []
        let pull = Double(shoulder) * 0.6 / r
        segs.append(Seg(c1: CGPoint(x: left + Double(shoulder) * 0.7, y: 0),
                        c2: add(p(-join), t(-join), -pull), end: p(-join)))
        let n = 4, step = 2 * join / Double(n), k = 4.0 / 3.0 * tan(step / 4)
        for i in 0..<n {
            let a0 = -join + Double(i) * step, a1 = a0 + step
            segs.append(Seg(c1: add(p(a0), t(a0), k), c2: add(p(a1), t(a1), -k), end: p(a1)))
        }
        let f = segs[0]
        segs.append(Seg(c1: CGPoint(x: 2 * cx - f.c2.x, y: f.c2.y),
                        c2: CGPoint(x: 2 * cx - f.c1.x, y: f.c1.y),
                        end: CGPoint(x: cx + halfWidth, y: 0)))
        return segs
    }

    // MARK: - Motion (Tim, 2026-11-08: "ensure smoothness of movement of the bar is AAA")
    // The slide is one spring, sampled once when a tab is tapped; every moving part (notch, collar, bead, the bead's
    // glyphs) is a function of the sampled position and is handed to Core Animation whole, so a screen that is busy
    // drawing itself cannot stall a slide that has started (ItMeniscusMotion.swift).

    /// The slide's spring, in slots. The same feel as the SwiftUI spring it replaces (response 0.38, damping 0.78).
    struct Spring: Equatable {
        static let response = ItMeniscusToken.springResponse
        static let damping = ItMeniscusToken.springDamping
        /// Closer to the target than this (a seventh of a point on an iPhone) counts as landed.
        static let rest = ItMeniscusToken.springRest

        let from: CGFloat
        let to: CGFloat
        /// Slots per second at the start: a tap mid-slide carries the bead's speed into the new slide.
        let velocity: CGFloat

        /// Seconds until the bead has landed for good.
        var settleTime: Double {
            let (rate, _, y0, c) = terms
            let reach = abs(y0) + abs(c)
            return reach <= Self.rest ? 0 : min(ItMeniscusToken.springCap, log(reach / Self.rest) / rate)
        }

        /// Where the bead is and how fast it moves, `t` seconds in.
        func state(at t: Double) -> (position: CGFloat, velocity: CGFloat) {
            guard t > 0 else { return (from, velocity) }
            guard t < settleTime else { return (to, 0) }
            let (rate, wd, y0, c) = terms
            let e = exp(-rate * t), cs = cos(wd * t), sn = sin(wd * t)
            let w = rate / Self.damping
            let y = e * (y0 * cs + c * sn)
            let v = e * (Double(velocity) * cs - (rate * Double(velocity) + w * w * y0) / wd * sn)
            return (to + CGFloat(y), CGFloat(v))
        }

        /// Decay rate, damped frequency, start offset from the target, and the sine term's weight.
        private var terms: (rate: Double, wd: Double, y0: Double, c: Double) {
            let w = 2 * Double.pi / Self.response
            let rate = Self.damping * w
            let wd = w * (1 - Self.damping * Self.damping).squareRoot()
            let y0 = Double(from - to)
            return (rate, wd, y0, (Double(velocity) + rate * y0) / wd)
        }
    }

    /// Seconds between the samples of a slide (one per frame at 120 Hz).
    static let trackStep = 1.0 / ItMeniscusToken.sampleRate

    /// The bead's position at every step of a slide; the last is the target exactly.
    static func track(_ spring: Spring) -> [CGFloat] {
        let steps = max(1, Int((spring.settleTime / trackStep).rounded(.up)))
        return (0..<steps).map { spring.state(at: Double($0) * trackStep).position } + [spring.to]
    }

    /// What the bead shows at one position of a slide.
    struct Face: Equatable {
        /// The tab the bead is over: its icon rides in the bead.
        let under: Int
        /// 0…1: how far that tab's icon has turned into its action (Receipts: the camera), over the last third of a slot.
        let settle: Double
        /// 0…1: how much of the rolling icon (Add's +) shows instead.
        let roll: Double
        /// The rolling icon's angle in degrees: `turn` on its own tab, one full turn per slot away from it, so it rolls
        /// with the bead like a wheel.
        let angle: Double
    }

    /// `selected` is where the bead is going, `travel` how many slots this slide covers, `actions` the tabs whose icon
    /// turns into an action when selected, `rollAt` the tab whose icon rolls into its action by `turn` degrees.
    static func face(position: CGFloat, selected: Int, travel: CGFloat, count: Int, actions: Set<Int>,
                     rollAt: Int?, turn: Double, reduceMotion: Bool = false) -> Face {
        let under = nearest(position, count: count)
        let away = abs(position - CGFloat(selected))
        let settle = under == selected && actions.contains(under) ? Double(min(1, max(0, 1 - away / CGFloat(ItMeniscusToken.actionMorph)))) : 0
        guard let rollAt else { return Face(under: under, settle: settle, roll: 0, angle: 0) }
        // Heading for the rolling tab its icon fades in over the first quarter slot, so it is there to be seen rolling.
        let fadeIn = travel < CGFloat(ItMeniscusToken.stillEpsilon) || reduceMotion ? 1 : Double(min(1, max(0, (travel - away) / CGFloat(ItMeniscusToken.rollFade))))
        let roll = under == rollAt ? 1 : (rollAt == selected && away >= CGFloat(ItMeniscusToken.stillEpsilon) ? fadeIn : 0)
        let angle = reduceMotion ? turn : turn + Double(position - CGFloat(rollAt)) * 360
        return Face(under: under, settle: settle, roll: roll, angle: angle)
    }
}
