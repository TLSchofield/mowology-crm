//
//  ItHexagonGeometry.swift
//  MowologyCRM
//
//  Geometry of the live AI Office hexagon (Six + One), Ittai v1.2 — the approved geometry, unchanged:
//  canvas 232 × 204, centre (116, 102), flat-topped, vertex radius 72, corner radius 12, sides visible
//  84.44% of their path centred (soft ends wrapping 12 pt into the corners), spokes radius 40 → 52,
//  Charlie's circle radius 34. v1.2 tap areas: one wedge per side from radius 34.5 to 124 over the side's
//  60°, inset 0.5 on each straight edge, so the six wedges and Charlie's circle never overlap.
//  Pure CoreGraphics + SwiftUI Path, so ios/LogicTests can check it on the Mac.
//

import SwiftUI

enum ItHexagonGeometry {
    static let width: CGFloat = 232, height: CGFloat = 204
    static let centre = CGPoint(x: 116, y: 102)
    static let vertexRadius: CGFloat = 72, cornerRadius: CGFloat = 12
    static let visible: CGFloat = 0.8444
    static let charlieRadius: CGFloat = 34
    static let wedgeInner: CGFloat = 34.5, wedgeOuter: CGFloat = 124, wedgeInset: CGFloat = 0.5
    /// Fixed positions, clockwise from the top: Yui, Sam, Penny, Otto, Mia, Ada. Never reordered.
    static let order = ["yui", "sam", "penny", "otto", "mia", "ada"]
    static let normals: [CGFloat] = [-90, -30, 30, 90, 150, 210]

    static func point(_ deg: CGFloat, _ r: CGFloat, scale s: CGFloat = 1) -> CGPoint {
        let a = deg * .pi / 180
        return CGPoint(x: (centre.x + r * cos(a)) * s, y: (centre.y + r * sin(a)) * s)
    }

    /// One side, corner-arc middle to corner-arc middle, trimmed to its visible 84.44%, centred.
    static func side(_ i: Int, scale s: CGFloat) -> Path {
        let n = normals[i]
        let toArcCentre = vertexRadius - cornerRadius / sin(.pi / 3)   // arc centres sit 58.14 from the centre
        let c1 = point(n - 30, toArcCentre), c2 = point(n + 30, toArcCentre)
        func arc(_ c: CGPoint, from a: CGFloat, to b: CGFloat, into p: inout Path, start: Bool) {
            let steps = 16
            for k in 0...steps {
                let t = (a + (b - a) * CGFloat(k) / CGFloat(steps)) * .pi / 180
                let q = CGPoint(x: (c.x + cornerRadius * cos(t)) * s, y: (c.y + cornerRadius * sin(t)) * s)
                if start && k == 0 { p.move(to: q) } else { p.addLine(to: q) }
            }
        }
        var p = Path()
        arc(c1, from: n - 30, to: n, into: &p, start: true)      // first corner, middle → tangent
        arc(c2, from: n, to: n + 30, into: &p, start: false)     // straight edge (the gap), then second corner
        let trim = (1 - visible) / 2
        return p.trimmedPath(from: trim, to: 1 - trim)
    }

    static func spoke(_ i: Int, scale s: CGFloat) -> Path {
        var p = Path()
        p.move(to: point(normals[i], 40, scale: s)); p.addLine(to: point(normals[i], 52, scale: s))
        return p
    }

    /// The tap area for side i: an annular sector, inset so neighbours never share a point.
    static func wedge(_ i: Int, scale s: CGFloat) -> Path {
        let n = normals[i]
        let oa = asin(wedgeInset / wedgeOuter) * 180 / .pi, ia = asin(wedgeInset / wedgeInner) * 180 / .pi
        let a = n - 30, b = n + 30
        var p = Path()
        let steps = 24
        for k in 0...steps {
            let t = (a + oa) + ((b - oa) - (a + oa)) * CGFloat(k) / CGFloat(steps)
            let q = point(t, wedgeOuter, scale: s)
            if k == 0 { p.move(to: q) } else { p.addLine(to: q) }
        }
        for k in 0...steps {
            let t = (b - ia) - ((b - ia) - (a + ia)) * CGFloat(k) / CGFloat(steps)
            p.addLine(to: point(t, wedgeInner, scale: s))
        }
        p.closeSubpath()
        return p
    }

    static func charlie(scale s: CGFloat) -> Path {
        let r = charlieRadius * s, c = CGPoint(x: centre.x * s, y: centre.y * s)
        return Path(ellipseIn: CGRect(x: c.x - r, y: c.y - r, width: 2 * r, height: 2 * r))
    }

    /// Where a side's label (state icon + name) is centred.
    static func labelCentre(_ i: Int, scale s: CGFloat) -> CGPoint { point(normals[i], 84, scale: s) }

    /// Which employee a tap belongs to, by plain maths: Charlie inside radius 34; a side between radius
    /// 34.5 and 124 by which 60° sector the angle falls in. Exactly one owner (or none) by construction.
    /// (SwiftUI's Path.contains misreported a whole row of points as inside a wedge on 2026-10-17 —
    /// CGPath.contains was right — so taps never depend on path containment.)
    static func ownerID(of p: CGPoint, scale s: CGFloat) -> String? {
        let dx = p.x / s - centre.x, dy = p.y / s - centre.y
        let r = (dx * dx + dy * dy).squareRoot()
        if r <= charlieRadius { return "charlie" }
        guard r >= wedgeInner, r <= wedgeOuter else { return nil }
        var deg = atan2(dy, dx) * 180 / .pi          // -180…180, 0 = right, clockwise positive (y down)
        deg = (deg + 120).truncatingRemainder(dividingBy: 360); if deg < 0 { deg += 360 }
        return order[min(5, Int(deg / 60))]          // Yui's sector is -120…-60
    }

    /// Every region whose drawn wedge path holds the point (CGPath containment) — used only by the checks
    /// to prove the drawn wedges and the maths agree and never overlap.
    static func owner(of p: CGPoint, scale s: CGFloat) -> [String] {
        var hits: [String] = []
        if charlie(scale: s).cgPath.contains(p) { hits.append("charlie") }
        for i in 0..<6 where wedge(i, scale: s).cgPath.contains(p) { hits.append(order[i]) }
        return hits
    }
}
