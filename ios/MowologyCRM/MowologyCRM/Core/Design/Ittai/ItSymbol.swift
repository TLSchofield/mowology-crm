//
//  ItSymbol.swift
//  MowologyCRM
//
//  The permanent Ittai symbol — FROZEN (Tim, 2026-10-17): geometry, colour, proportions and the size
//  ladder change only if real-world testing shows a problem. Same geometry as the design system's SVGs
//  (docs/design/ittai-v1.2; 64-unit grid, vertex radius 27, corner radius 6, ring radius 9, spokes 12→18).
//  Size ladder: below 48 pt the six inward spokes are hidden; from 48 pt the complete symbol shows.
//  It never shows status: one colour only (brand, or surface reversed), never a state/status/tenant colour.
//

import SwiftUI

/// One row of the approved size ladder (stroke weights on the 64-unit grid).
struct ItSymbolRow: Equatable {
    let fromPt: CGFloat
    let side: CGFloat
    /// Visible share of each side's path length, centred on the side (SVG dasharray first value / 100).
    let visible: CGFloat
    /// Centre: nil = solid disc, else ring stroke width.
    let ring: CGFloat?
    /// Spoke stroke width, or nil = no spokes.
    let spokes: CGFloat?

    static let ladder: [ItSymbolRow] = [
        ItSymbolRow(fromPt: 0,  side: 6,   visible: 0.582,  ring: nil, spokes: nil),
        ItSymbolRow(fromPt: 24, side: 5,   visible: 0.658,  ring: 4,   spokes: nil),
        ItSymbolRow(fromPt: 32, side: 4,   visible: 0.7344, ring: 2.5, spokes: nil),   // spokes removed, v1.2 final
        ItSymbolRow(fromPt: 48, side: 4,   visible: 0.7344, ring: 2.5, spokes: 2),
        ItSymbolRow(fromPt: 64, side: 3.5, visible: 0.7534, ring: 2.5, spokes: 1.3),
    ]
    static let spokeMinPt: CGFloat = 48

    static func row(for size: CGFloat) -> ItSymbolRow {
        ladder.last(where: { size >= $0.fromPt }) ?? ladder[0]
    }
}

/// The symbol's geometry on the 64-unit grid. Pure maths, shared by the view and the logic checks.
enum ItSymbolGeometry {
    static let centre = CGPoint(x: 32, y: 32)
    static let vertexRadius: CGFloat = 27
    static let cornerRadius: CGFloat = 6
    static let ringRadius: CGFloat = 9
    /// Side normals, clockwise from the top side (Yui's side is always on top; never rotate).
    static let normals: [CGFloat] = [-90, -30, 30, 90, 150, 210]

    static func point(_ deg: CGFloat, _ r: CGFloat) -> CGPoint {
        let a = deg * .pi / 180
        return CGPoint(x: centre.x + r * cos(a), y: centre.y + r * sin(a))
    }

    /// The visible straight segment of one side. Each side's path runs from the middle of one rounded
    /// corner to the middle of the next (arc + straight + arc); `visible` of its length shows, centred.
    /// The trimmed ends always fall on the straight part (the shortest trim, 3.25 units, exceeds the
    /// 3.14-unit half-arc), so the visible part is exactly one straight segment.
    static func side(_ i: Int, visible: CGFloat) -> (CGPoint, CGPoint) {
        let n = normals[i]
        let v1 = point(n - 30, vertexRadius), v2 = point(n + 30, vertexRadius)
        let tangent = cornerRadius / tan(.pi / 3)           // 3.464: tangent point distance from each vertex
        let halfArc = cornerRadius * (.pi / 6)              // 3.142: half of each corner arc
        let straight = vertexRadius - 2 * tangent           // 20.07
        let total = straight + 2 * halfArc
        let trim = total * (1 - visible) / 2                // from each end of the path
        let fromVertex = tangent + (trim - halfArc)         // along the side, from the vertex
        let dx = (v2.x - v1.x) / vertexRadius, dy = (v2.y - v1.y) / vertexRadius
        return (CGPoint(x: v1.x + dx * fromVertex, y: v1.y + dy * fromVertex),
                CGPoint(x: v2.x - dx * fromVertex, y: v2.y - dy * fromVertex))
    }

    static func spoke(_ i: Int) -> (CGPoint, CGPoint) { (point(normals[i], 12), point(normals[i], 18)) }
}

/// The Ittai symbol at a given size. Inside the app it is drawn in code (not an image file), so it
/// follows the app's own mode, field mode included.
struct ItSymbol: View {
    var size: CGFloat = 24
    /// One colour only. nil = the palette's brand colour.
    var color: Color? = nil
    @Environment(\.it) private var it

    var body: some View {
        let row = ItSymbolRow.row(for: size)
        let ink = color ?? it.brand
        Canvas { ctx, canvas in
            let k = canvas.width / 64
            func p(_ q: CGPoint) -> CGPoint { CGPoint(x: q.x * k, y: q.y * k) }
            var sides = Path()
            for i in 0..<6 {
                let (a, b) = ItSymbolGeometry.side(i, visible: row.visible)
                sides.move(to: p(a)); sides.addLine(to: p(b))
            }
            ctx.stroke(sides, with: .color(ink), style: StrokeStyle(lineWidth: row.side * k, lineCap: .round))
            if let w = row.spokes {
                var spokes = Path()
                for i in 0..<6 {
                    let (a, b) = ItSymbolGeometry.spoke(i)
                    spokes.move(to: p(a)); spokes.addLine(to: p(b))
                }
                ctx.stroke(spokes, with: .color(ink), style: StrokeStyle(lineWidth: w * k))
            }
            let r = ItSymbolGeometry.ringRadius * k, c = p(ItSymbolGeometry.centre)
            let circle = Path(ellipseIn: CGRect(x: c.x - r, y: c.y - r, width: 2 * r, height: 2 * r))
            if let w = row.ring {
                ctx.stroke(circle, with: .color(ink), lineWidth: w * k)
            } else {
                ctx.fill(circle, with: .color(ink))
            }
        }
        .frame(width: size, height: size)
        .accessibilityElement()
        .accessibilityLabel("Ittai")
        .accessibilityAddTraits(.isImage)
    }
}
