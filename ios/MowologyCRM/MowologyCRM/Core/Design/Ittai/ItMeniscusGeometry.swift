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
    /// Bead diameter.
    static let bead: CGFloat = 50
    /// Ring of page colour between the bead and the notch.
    static let gap: CGFloat = 6
    /// How far the bead's top sits above the bar's top edge.
    static let rise: CGFloat = 20
    /// Horizontal run of each shoulder curve where the straight edge bends into the notch.
    static let shoulder: CGFloat = 14
    /// Where a shoulder hands over to the arc, in degrees from the bowl's bottom.
    static let joinDegrees: Double = 80

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
}
