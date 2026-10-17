// Logic checks for the Ittai v1.2 design layer (Core/Design/Ittai) — run by ios/LogicTests/run.sh.
// Contrast of every text/status pairing in light, dark and field; theme precedence; the frozen symbol
// ladder; the hexagon's tap areas (no overlap, 48 × 48 pt in each at phone size); the two vocabularies.
import Foundation
import CoreGraphics
import SwiftUI

var fails = 0
func check(_ c: Bool, _ m: String) { if !c { fails += 1; print("FAIL: \(m)") } }

// MARK: contrast (WCAG relative luminance)
func lum(_ h: UInt32) -> Double {
    func ch(_ v: UInt32) -> Double { let c = Double(v) / 255; return c <= 0.04045 ? c / 12.92 : pow((c + 0.055) / 1.055, 2.4) }
    return 0.2126 * ch((h >> 16) & 0xFF) + 0.7152 * ch((h >> 8) & 0xFF) + 0.0722 * ch(h & 0xFF)
}
func ratio(_ a: UInt32, _ b: UInt32) -> Double { let x = lum(a), y = lum(b); return (max(x, y) + 0.05) / (min(x, y) + 0.05) }

for mode in ItMode.allCases {
    let p = ItPalette(mode)
    let grounds: [ItColorToken] = [.page, .surface, .selected]
    for g in grounds {
        check(ratio(p.hex(.text), p.hex(g)) >= 7, "\(mode) text on \(g) \(ratio(p.hex(.text), p.hex(g)))")
        check(ratio(p.hex(.meta), p.hex(g)) >= (mode == .field ? 7 : 4.5), "\(mode) meta on \(g) \(ratio(p.hex(.meta), p.hex(g)))")
    }
    let textTokens: [ItColorToken] = [.statusComplete, .statusAttention, .statusBlocked, .statusProgress, .stateWorking, .stateApproval,
                                      .stateBlocked, .stateIdle, .stateUnavailable, .syncSaved, .syncSending, .syncSent, .syncFailed, .syncConflict, .tenant]
    for t in textTokens {
        check(ratio(p.hex(t), p.hex(.surface)) >= 4.5, "\(mode) \(t) as text on surface \(ratio(p.hex(t), p.hex(.surface)))")
        check(ratio(p.hex(t), p.hex(.selected)) >= 4.5, "\(mode) \(t) on selected \(ratio(p.hex(t), p.hex(.selected)))")
    }
    check(ratio(p.hex(.onTenant), p.hex(.tenant)) >= 7, "\(mode) label on filled button \(ratio(p.hex(.onTenant), p.hex(.tenant)))")
    check(ratio(p.hex(.tenant), p.hex(.surface)) >= 3, "\(mode) filled button against surface")
    check(ratio(p.hex(.brand), p.hex(.surface)) >= 3 && ratio(p.hex(.brand), p.hex(.page)) >= 3, "\(mode) symbol (brand) ≥ 3:1")
    check(ratio(p.hex(.focus), p.hex(.surface)) >= 3, "\(mode) focus ring ≥ 3:1")
}

// MARK: precedence: field > chosen theme > phone > light
check(ItMode.resolve(field: true, chosen: .dark, system: .dark) == .field, "field beats a dark theme and a dark phone")
check(ItMode.resolve(field: false, chosen: .light, system: .dark) == .light, "chosen light beats a dark phone")
check(ItMode.resolve(field: false, chosen: .dark, system: .light) == .dark, "chosen dark beats a light phone")
check(ItMode.resolve(field: false, chosen: nil, system: .dark) == .dark, "follows a dark phone")
check(ItMode.resolve(field: false, chosen: nil, system: .light) == .light, "default light")

// MARK: the frozen symbol ladder (spokes from 48 pt)
for s: CGFloat in [8, 16, 23, 24, 31, 32, 40, 47] { check(ItSymbolRow.row(for: s).spokes == nil, "no spokes at \(s)") }
for s: CGFloat in [48, 63] { check(ItSymbolRow.row(for: s).spokes == 2, "spokes 2 at \(s)") }
for s: CGFloat in [64, 128, 512] { check(ItSymbolRow.row(for: s).spokes == 1.3 && ItSymbolRow.row(for: s).side == 3.5, "full row at \(s)") }
check(ItSymbolRow.row(for: 16).ring == nil && ItSymbolRow.row(for: 24).ring == 4, "16 disc, 24 ring 4")
check(ItSymbolRow.spokeMinPt == 48, "threshold 48")
// Geometry against the design-system SVG: the top side lies on y = 8.62 and is centred on x = 32.
for row in ItSymbolRow.ladder {
    let (a, b) = ItSymbolGeometry.side(0, visible: row.visible)
    check(abs(a.y - 8.62) < 0.01 && abs(b.y - 8.62) < 0.01 && abs((a.x + b.x) / 2 - 32) < 0.01, "top side geometry \(a) \(b)")
}
// Visible length of the 64 row's top side = 75.34% of 26.35 minus the two half-arcs it doesn't reach.
let (ta, tb) = ItSymbolGeometry.side(0, visible: 0.7534)
check(abs((tb.x - ta.x) - (26.35 * 0.7534 - 0)) < 0.2, "64-row visible length \(tb.x - ta.x)")

// MARK: hexagon tap areas
for (scale, need, label) in [(CGFloat(340) / 232, CGFloat(48), "phone 340"), (CGFloat(1), CGFloat(44), "desktop 232")] {
    let w = 232 * scale, h = 204 * scale
    var overlaps = 0
    var y: CGFloat = 0
    while y <= h { var x: CGFloat = 0; while x <= w { if ItHexagonGeometry.owner(of: CGPoint(x: x, y: y), scale: scale).count > 1 { overlaps += 1 }; x += 1 }; y += 1 }
    check(overlaps == 0, "\(label): \(overlaps) points belong to two employees")
    // The tap maths and the drawn wedges agree: wherever a drawn wedge (or Charlie's circle) holds a point,
    // the tap never resolves to a DIFFERENT employee. (At an exact radius edge the maths may say "nobody"
    // where the drawn polygon's chord says one head: a tap there does nothing, never the wrong thing.)
    var disagree = 0
    y = 0
    while y <= h { var x: CGFloat = 0; while x <= w { let p = CGPoint(x: x, y: y); let o = ItHexagonGeometry.owner(of: p, scale: scale); if o.count == 1, let m = ItHexagonGeometry.ownerID(of: p, scale: scale), m != o[0] { disagree += 1 }; x += 1 }; y += 1 }
    check(disagree == 0, "\(label): a tap resolves to the wrong employee at \(disagree) points")
    for id in ["charlie"] + ItHexagonGeometry.order {
        var found = false
        var sy: CGFloat = 0
        search: while sy + need <= h {
            var sx: CGFloat = 0
            while sx + need <= w {
                var ok = true
                var yy = sy
                outer: while yy <= sy + need { var xx = sx; while xx <= sx + need { if ItHexagonGeometry.ownerID(of: CGPoint(x: xx, y: yy), scale: scale) != id { ok = false; break outer }; xx += 4 }; yy += 4 }
                if ok { found = true; break search }
                sx += 2
            }
            sy += 2
        }
        check(found, "\(label): \(id) holds a \(Int(need)) × \(Int(need)) square inside the frame")
    }
    for (i, id) in ItHexagonGeometry.order.enumerated() {
        check(ItHexagonGeometry.ownerID(of: ItHexagonGeometry.labelCentre(i, scale: scale), scale: scale) == id, "\(label): \(id)'s label is inside its own wedge")
        let mid = ItHexagonGeometry.point(ItHexagonGeometry.normals[i], 62.35, scale: scale)
        check(ItHexagonGeometry.ownerID(of: mid, scale: scale) == id, "\(label): \(id)'s drawn side is inside its own wedge")
    }
}

// MARK: two vocabularies, one icon set
let taskWords = Set(ItTaskStatus.allCases.map { $0.words.lowercased() })
let stateWords = Set(ItEmployeeState.allCases.map { $0.words.lowercased() })
check(taskWords.isDisjoint(with: stateWords), "a word is both a task status and an employee state: \(taskWords.intersection(stateWords))")
check(!taskWords.contains("working") && !taskWords.contains("blocked"), "no employee word as a task status")

print(fails == 0 ? "Ittai design checks: all passed" : "Ittai design checks: \(fails) FAILED")
exit(fails == 0 ? 0 : 1)
