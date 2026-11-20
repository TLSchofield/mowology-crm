// Logic checks for the meniscus tab bar geometry and motion (Core/Design/Ittai/ItMeniscusGeometry.swift) — run by ios/LogicTests/run.sh.
import Foundation
import CoreGraphics

var fails = 0
func check(_ c: Bool, _ m: String) { if !c { fails += 1; print("FAIL: \(m)") } }
func near(_ a: CGFloat, _ b: CGFloat, _ e: CGFloat = 0.001) -> Bool { abs(a - b) <= e }

typealias G = ItMeniscusGeometry

// Slots and the sliding bead.
for count in 3...6 {
    let width = CGFloat(count * 80)
    check((0..<count).map { G.slotCentre($0, count: count, width: width) }
          == (0..<count).map { CGFloat($0 * 80 + 40) }, "\(count) slot centres")
    for position in stride(from: CGFloat(0), through: CGFloat(count - 1), by: 0.5) {
        let expected = (position + 0.5) * 80
        check(near(G.beadX(position, count: count, width: width), expected),
              "\(count) tabs bead at position \(position)")
        let notch = G.notch(cx: G.beadX(position, count: count, width: width))
        let notchBottom = notch.map(\.end).max { $0.y < $1.y }!
        check(near(notchBottom.x, expected) && near(notchBottom.y, G.depth),
              "\(count) tabs notch follows bead at position \(position)")
    }
    for logical in 0..<count {
        check(G.physicalSlotIndex(logical, count: count, isRightToLeft: false) == logical,
              "\(count) slots LTR keeps logical slot \(logical)")
        check(G.physicalSlotIndex(logical, count: count, isRightToLeft: true) == count - logical - 1,
              "\(count) slots RTL mirrors logical slot \(logical)")
    }
}
let stableIDs = [10, 30, 20, 90]
check(G.slotIndex(forID: 20, in: stableIDs) == 2, "stable identity maps to its array slot")
check(G.slotIndex(forID: 999, in: stableIDs) == nil, "unknown identity has no slot")
check(near(G.beadX(2.5, count: 5, width: 400), 240), "bead between slots")
check(G.nearest(0.4, count: 5) == 0 && G.nearest(0.6, count: 5) == 1 && G.nearest(4.3, count: 5) == 4 && G.nearest(-0.2, count: 5) == 0,
      "nearest tab mid-slide")
// Today's invalid-input policy is deliberately documented: nearest returns slot zero for zero/one tab, while
// geometric positions continue to extrapolate outside the row instead of silently clamping selection.
check(G.nearest(-20, count: 0) == 0 && G.nearest(20, count: 1) == 0, "zero or one tab has a non-crashing nearest slot")
check(near(G.slotCentre(-1, count: 5, width: 400), -40)
      && near(G.beadX(6, count: 5, width: 400), 520), "out-of-range positions retain today's extrapolation")

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

// The slide's spring: starts where and as fast as it is told, lands exactly, overshoots a little, never runs away.
let slide = G.Spring(from: 0, to: 4, velocity: 0)
check(near(slide.state(at: 0).position, 0) && near(slide.state(at: 0).velocity, 0), "spring starts at rest on its slot")
check(slide.settleTime > 0.3 && slide.settleTime < 1, "four slots settle in under a second (\(slide.settleTime))")
check(slide.state(at: slide.settleTime).position == 4 && slide.state(at: 9).velocity == 0, "spring lands exactly")
let run = G.track(slide)
check(run.first == 0 && run.last == 4, "track runs from the start to the target")
check(run.max()! > 4 && run.max()! < 4.12, "overshoot under 3% of the travel (\(run.max()!))")
check(zip(run, run.dropFirst()).allSatisfy { abs($1 - $0) < 0.3 }, "no jump between frames (top speed is about a quarter slot a frame)")
check(abs(run[run.count - 2] - 4) <= 0.003, "the last step is not a visible snap")
check(G.Spring(from: 2, to: 2, velocity: 0).settleTime == 0 && G.track(G.Spring(from: 2, to: 2, velocity: 0)).allSatisfy { $0 == 2 },
      "a bead that is not going anywhere stays put")
check(G.Spring(from: 2, to: 2 + CGFloat(G.Spring.rest), velocity: 0).settleTime > 0,
      "today's envelope includes its sine weight at the positional rest threshold")
let capped = G.Spring(from: 0, to: 1_000_000_000, velocity: 0)
check(capped.settleTime == 1.5 && capped.state(at: 1.5).position == capped.to,
      "spring caps at 1.5 seconds and lands exactly")
// The spring's speed is the slope of its position (so a tap mid-slide can hand the speed on).
let h = 0.0001, at = 0.12
let slope = (slide.state(at: at + h).position - slide.state(at: at - h).position) / CGFloat(2 * h)
check(near(slide.state(at: at).velocity, slope, 0.01), "velocity is the slope (\(slide.state(at: at).velocity) vs \(slope))")
// A second tap mid-slide: the new slide starts where the bead is, at the speed it has.
let mid = slide.state(at: at)
let back = G.Spring(from: mid.position, to: 1, velocity: mid.velocity)
check(near(back.state(at: 0).position, mid.position), "retarget starts where the bead is")
check(back.state(at: 0.004).position > mid.position, "and keeps moving the way it was going at first")
check(G.track(back).last == 1, "then lands on the new tab")

// The bead's face. Slots: 0 Schedule, 3 Receipts (turns into the camera), 4 Add (its + rolls 45° into ×).
func face(_ p: CGFloat, to: Int, travel: CGFloat = 1, still: Bool = false) -> G.Face {
    G.face(position: p, selected: to, travel: travel, count: 5, actions: [3], rollAt: 4, turn: 45, reduceMotion: still)
}
check(face(0, to: 0, travel: 0) == G.Face(under: 0, settle: 0, roll: 0, angle: 45 - 4 * 360), "resting on a plain tab")
check(face(3, to: 3).settle == 1 && face(2.9, to: 3).settle > 0.6 && face(2.6, to: 3).settle == 0, "Receipts turns into the camera as it lands")
check(face(2.67, to: 3).settle == 0 && face(2.6701, to: 3).settle > 0,
      "action face begins strictly inside the 0.33-slot threshold")
check(face(3, to: 2).settle == 0, "passing over Receipts shows its own icon")
check(face(4, to: 4).roll == 1 && near(CGFloat(face(4, to: 4).angle), 45), "on Add the + shows as ×")
check(near(CGFloat(face(3, to: 4).angle), 45 - 360), "one slot away the + is one turn back")
check(face(0, to: 4, travel: 4).roll == 0 && face(0.125, to: 4, travel: 4).roll == 0.5 && face(0.3, to: 4, travel: 4).roll == 1,
      "the + fades in over the first quarter slot on the way to Add")
check(face(3.49, to: 4, travel: 0.01).roll == 0 && face(3.49, to: 4, travel: 0.0099).roll == 1,
      "0.01-slot stationary threshold keeps today's strict boundary")
check(face(3.4, to: 0, travel: 4).roll == 0 && face(3.6, to: 0, travel: 4).roll == 1, "leaving Add the + goes at the half slot")
check(face(2, to: 4, travel: 2, still: true).angle == 45 && face(2, to: 4, travel: 2, still: true).roll == 1, "Reduce Motion: no roll, no fade")

if fails == 0 { print("meniscus geometry: all checks passed") } else { print("meniscus geometry: \(fails) failed"); exit(1) }
