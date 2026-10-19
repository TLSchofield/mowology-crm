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
    let onboardingLabel: ItColorToken = mode == .dark ? .page : .surface
    check(ratio(p.hex(onboardingLabel), p.hex(.brand)) >= 4.5,
          "\(mode) onboarding label on brand ≥ 4.5:1 (\(ratio(p.hex(onboardingLabel), p.hex(.brand))))")
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

// MARK: knowledge brain — BrainShape light-order ownership and front-face hit testing
for count in [1,6,20,40] {
    let shape=BrainShape(count)
    for slot in 0..<count { check(ItKnowledgeGeometry.faceIndex(slot:slot,count:count)==shape.lightOrder[slot],"knowledge slot \(slot) follows shape \(count) lightOrder") }
}
let fixed40a=(0..<40).map{ItKnowledgeGeometry.faceIndex(slot:$0,count:40)}
let fixed40b=(0..<40).map{ItKnowledgeGeometry.faceIndex(slot:$0,count:40)}
check(fixed40a==fixed40b,"knowledge face mapping is stable for a fixed count")
// A new shape has its own deterministic lightOrder; every earlier slot retains that ordinal position.
let order40=BrainShape(40).lightOrder,order41=BrainShape(41).lightOrder
for slot in 0..<40 { check(ItKnowledgeGeometry.faceIndex(slot:slot,count:41)==order41[slot],"slot \(slot) keeps lightOrder position when vendor 41 is added") }
check((0..<40).allSatisfy{fixed40a[$0]==order40[$0]},"40-vendor mapping is the shape lightOrder")
let projected40=ItKnowledgeGeometry.projectedFaces(count:40,in:CGSize(width:361,height:300))
for face in projected40 where face.front && face.slot != nil {
    check(ItKnowledgeGeometry.resolvedSlot(at:face.centroid,faces:projected40)==face.slot,"front centroid resolves vendor \(face.slot!)")
}
if let back=projected40.first(where:{!$0.front && $0.slot != nil}) { check(ItKnowledgeGeometry.resolvedSlot(at:back.centroid,faces:[back])==nil,"back face is never tappable") }
// Dense projected targets can overlap, so VoiceOver is deliberately routed to the existing list view.
check(ItKnowledgeGeometry.minimumHitDiameter >= 44, "knowledge targets are at least 44 pt")
check(Set(["loose-lines","outline","fill","fill-keyline"]).count == 4, "four knowledge stages have distinct non-colour treatments")

// A solid cannot exist before learning. Empty state is three independent moving strokes.
check(ItKnowledgeGeometry.projectedFaces(count:0,in:CGSize(width:300,height:300)).isEmpty,"zero knowledge has no mesh")
let chaos0=BrainMotion.chaosSegments(in:CGSize(width:300,height:300),time:0)
let chaos10=BrainMotion.chaosSegments(in:CGSize(width:300,height:300),time:10)
check(chaos0.count == 3 && chaos0.allSatisfy{$0.count == 2},"chaos is exactly three lines")
check(chaos0 != chaos10,"chaos moves with time")
check(chaos0 == BrainMotion.chaosSegments(in:CGSize(width:300,height:300),time:0),"reduced motion freezes chaos")
var motion=BrainMotion()
check(abs(motion.yaw(at:90)-motion.yaw(at:0)-2 * .pi)<0.000001,"one slow turn takes90 seconds")
motion.drag(CGSize(width:40,height:20),at:10)
let held=motion.yaw(at:10)
check(motion.yaw(at:15)==held,"drag suspends auto rotation")
motion.endDrag(at:15)
check(abs(motion.yaw(at:15)-held)<0.000001,"release has no rotation jump")
check(motion.yaw(at:16)>held,"auto rotation resumes after release")
// All repeated vertices must project to identical screen coordinates; face shrinking breaks joins.
let mesh=ItKnowledgeGeometry.projectedFaces(count:20,in:CGSize(width:300,height:300))
let points=mesh.flatMap(\.points)
check(Set(points.map{"\($0.x),\($0.y)"}).count < points.count,"adjoining faces share projected vertices")
for count in [1,2,3,4,89,90,91,499,500] {
    let shape=BrainShape(count)
    check(shape.k==count && !shape.tris.isEmpty,"shape pathway boundary \(count) is preserved")
    check(shape.tris==BrainShape(count).tris,"shape \(count) is deterministic")
}

// Machado 2009 severity-1.0 matrices. Fill RGB distance is supplemented by the non-colour outline/keyline cue.
let machado:[String:[[Double]]]=[
 "protanopia":[[0.152286,1.052583,-0.204868],[0.114503,0.786281,0.099216],[-0.003882,-0.048116,1.051998]],
 "deuteranopia":[[0.367322,0.860646,-0.227968],[0.280085,0.672501,0.047413],[-0.011820,0.042940,0.968881]],
 "tritanopia":[[1.255528,-0.076749,-0.178779],[-0.078411,0.930809,0.147602],[0.004733,0.691367,0.303900]]]
func rgb(_ h:UInt32)->[Double]{[Double((h>>16)&255)/255,Double((h>>8)&255)/255,Double(h&255)/255]}
func cvd(_ h:UInt32,_ m:[[Double]])->[Double]{let v=rgb(h);return m.map{row in max(0,min(1,zip(row,v).map(*).reduce(0,+)))}}
func delta(_ a:[Double],_ b:[Double])->Double{sqrt(zip(a,b).map{pow($0-$1,2)}.reduce(0,+))}
for mode in [ItMode.light,.dark] { let p=ItPalette(mode);for(name,m)in machado {
    let d=cvd(p.hex(.knowledgeDiscovered),m),u=cvd(p.hex(.knowledgeUnderstood),m),g=cvd(p.hex(.knowledgeMastered),m)
    check(delta(u,g)>=0.12,"\(mode) \(name) understood/mastered fill difference")
    // Recognized loose lines, identified outline, understood fill, and mastered fill + keyline provide
    // four non-colour shape cues. The navy ink is shared intentionally by the first two states.
    check(delta(d,u)+0.20>=0.12 && delta(d,g)+0.20>=0.12,"\(mode) \(name) four knowledge shapes distinguishable")
} }

// MARK: registry presentation — actual formatter, source values remain unchanged
check(ItRegistryDisplay.registrationType("bc_registration") == "BC registration", "BC registry source code has a readable label")
check(ItRegistryDisplay.registrationType("BC_REGISTRATION") == "BC registration", "BC registry label is case-insensitive")
check(ItRegistryDisplay.registrationType("limited_liability_company") == "Limited liability company", "Unknown registration codes remain legible")
check(ItRegistryDisplay.registrationType("Cooperative") == "Cooperative", "Existing display labels are preserved")
check(ItRegistryDisplay.registeredDate("2026-10-18T12:34:56.123Z") == ItRegistryDisplay.registeredDate("2026-10-18"), "Fractional timestamps display as the same registry date")
check(ItRegistryDisplay.registeredDate("2026-10-18T00:00:01Z") == ItRegistryDisplay.registeredDate("2026-10-18"), "Registry date uses UTC consistently rather than device timezone")
check(ItRegistryDisplay.registeredDate("2026-10-18") != "2026-10-18", "ISO registry date is formatted for people")
for unknown in ["not recorded", "2026-02-30", "2026-13-18", "", "2026-1-8"] {
    check(ItRegistryDisplay.registeredDate(unknown) == unknown, "Unknown or invalid registry date is preserved: \(unknown)")
}

print(fails == 0 ? "Ittai design checks: all passed" : "Ittai design checks: \(fails) FAILED")
exit(fails == 0 ? 0 : 1)
