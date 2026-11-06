// Logic checks for the meniscus tab bar geometry (Core/Design/Ittai/ItMeniscusGeometry.swift) — run by ios/LogicTests/run.sh.
import Foundation
import CoreGraphics

var fails = 0
func check(_ c: Bool, _ m: String) { if !c { fails += 1; print("FAIL: \(m)") } }
func near(_ a: CGFloat, _ b: CGFloat, _ e: CGFloat = 0.001) -> Bool { abs(a - b) <= e }

typealias G = ItMeniscusGeometry

// Slots and the sliding bead.
check((0..<5).map { G.slotCentre($0, count: 5, width: 400) } == [40, 120, 200, 280, 360], "slot centres")
check(near(G.beadX(2.5, count: 5, width: 400), 240), "bead between slots")
check(G.nearest(0.4, count: 5) == 0 && G.nearest(0.6, count: 5) == 1 && G.nearest(4.3, count: 5) == 4 && G.nearest(-0.2, count: 5) == 0,
      "nearest tab mid-slide")

// The notch leaves and rejoins the edge horizontally.
let cx: CGFloat = 100
let segs = G.notch(cx: cx)
check(near(segs.first!.c1.y, 0), "leaves horizontally")
check(near(segs.last!.c2.y, 0) && near(segs.last!.end.y, 0), "rejoins horizontally")
check(near(segs.last!.end.x, cx + G.halfWidth), "rejoins at the half width")

// It cups the bead: the bottom is the bead's bottom plus the gap, under the centre; every arc point is bowl from the bead's centre.
check(near(G.depth, G.bead - G.rise + G.gap), "depth = bead bottom + gap")
// Labels start 39 pt below the edge (ItMeniscusBar.iconTop 14 + 22 icon + 3): the collar must end at least 4 pt above
// them (at 1.3.82 it touched the selected label on Tim's iPhone).
check(39 - G.depth >= 4, "collar clears the labels (\(39 - G.depth) pt)")
let bottom = segs.map(\.end).max { $0.y < $1.y }!
check(near(bottom.x, cx) && near(bottom.y, G.depth), "deepest point under the centre")
for s in segs.dropFirst().dropLast() {
    let d = hypot(s.end.x - cx, s.end.y - G.centreBelowEdge)
    check(near(d, G.bowl), "arc point at bowl radius (\(d))")
}

// Symmetric, bounded, never above the edge.
let pts = [CGPoint(x: cx - G.halfWidth, y: 0)] + segs.flatMap { [$0.c1, $0.c2, $0.end] }
for p in pts {
    check(abs(p.x - cx) <= G.halfWidth + 0.001, "bounded \(p)")
    check(p.y >= -0.001, "never above the edge \(p)")
    check(pts.contains { near($0.x, 2 * cx - p.x) && near($0.y, p.y) }, "mirror of \(p)")
}

if fails == 0 { print("meniscus geometry: all checks passed") } else { print("meniscus geometry: \(fails) failed"); exit(1) }
