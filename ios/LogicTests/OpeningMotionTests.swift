import Foundation

// Port of Android ItOpeningMotionTest.kt (same sample times, same expected values) so the Swift frames match Android.
var failures = 0
func check<T: Equatable>(_ got: T, _ want: T, _ label: String) {
    if got != want { failures += 1; print("FAIL \(label): got \(got), want \(want)") }
}
func near(_ got: Double, _ want: Double, _ d: Double, _ label: String) {
    if abs(got - want) > d { failures += 1; print("FAIL \(label): got \(got), want \(want) ±\(d)") }
}
typealias M = ItOpeningMotion
func rectNear(_ a: M.Rect, _ e: M.Rect, _ d: Double, _ label: String) {
    near(a.x, e.x, d, label + ".x"); near(a.y, e.y, d, label + ".y"); near(a.w, e.w, d, label + ".w")
    near(a.h, e.h, d, label + ".h"); near(a.rx, e.rx, d, label + ".rx")
}
func ovalNear(_ a: M.Oval, _ e: M.Oval, _ d: Double, _ label: String) {
    near(a.cx, e.cx, d, label + ".cx"); near(a.cy, e.cy, d, label + ".cy"); near(a.rx, e.rx, d, label + ".rx"); near(a.ry, e.ry, d, label + ".ry")
}

// Start: the people icon.
let f0 = M.frame(0)
rectNear(f0.t1, M.Rect(x: 33.5, y: 62.9, w: 13, h: 37.1), 1e-6, "t1@0")
near(f0.t2.x, 59.5, 1e-6, "t2.x@0")
rectNear(f0.bar, M.Rect(x: 28.8, y: 70.4, w: 48.4, h: 10.2, rx: 5.1), 1e-6, "bar@0")
check(f0.d1, M.Oval(cx: 40, cy: 48.9, rx: 7.9, ry: 7.9), "d1@0")
check(f0.d2, M.Oval(cx: 66, cy: 48.9, rx: 7.9, ry: 7.9), "d2@0")
check(f0.i1.h, 0, "i1 h@0"); check(f0.aStem.h, 0, "aStem h@0"); check(f0.i2.h, 0, "i2 h@0"); check(f0.ringScale, 0, "ring@0")
near(f0.camCx, 53.0, 1e-6, "camCx@0"); near(f0.camCy, 70.5, 1e-6, "camCy@0"); near(f0.camScale, 1.45, 1e-6, "camScale@0")

// End: the wordmark v1.3.
let fe = M.frame(M.T)
rectNear(fe.i1, M.Rect(x: 0, y: 44, w: 13, h: 56), 0.05, "i1@T")
rectNear(fe.t1, M.Rect(x: 31, y: 28, w: 13, h: 72), 0.05, "t1@T")
rectNear(fe.t2, M.Rect(x: 62, y: 28, w: 13, h: 72), 0.05, "t2@T")
rectNear(fe.aStem, M.Rect(x: 134.2, y: 44, w: 13, h: 56), 0.05, "aStem@T")
rectNear(fe.i2, M.Rect(x: 165.2, y: 44, w: 13, h: 56), 0.05, "i2@T")
rectNear(fe.bar, M.Rect(x: 25, y: 44, w: 56, h: 11, rx: 5.5), 0.05, "bar@T")
near(fe.ringScale, 1, 0.05, "ring@T")
ovalNear(fe.d1, M.Oval(cx: 6.5, cy: 31.5, rx: 6, ry: 6), 0.05, "d1@T")
ovalNear(fe.d2, M.Oval(cx: 171.7, cy: 31.5, rx: 6, ry: 6), 0.05, "d2@T")
near(fe.camCx, 89.1, 0.05, "camCx@T"); near(fe.camCy, 62, 0.05, "camCy@T"); near(fe.camScale, 0.92, 0.05, "camScale@T")

// The flick, the ring hit, the landing.
check(M.TC > M.FLICK0, true, "TC after flick start"); check(M.TC < M.FLICK1, true, "TC before flick end")
near(M.stemTop(M.TC), M.DOT_BOTTOM, 0.01, "stemTop(TC) = dot bottom")
let hit = M.frame(1.2)
ovalNear(hit.d1, M.Oval(cx: 21.505290947522287, cy: -2.729322678614878, rx: 5.9642564884661935, ry: 6.035743511533807), 1e-6, "d1@1.2")
ovalNear(hit.d2, M.Oval(cx: 118.6, cy: M.RING_TOP - M.R, rx: 6, ry: 6), 1e-6, "d2@1.2")
let landed = M.frame(1.55)
ovalNear(landed.d1, M.Oval(cx: 6.5, cy: 38, rx: 6, ry: 6), 1e-6, "d1@1.55")
ovalNear(landed.d2, M.Oval(cx: 171.7, cy: 38, rx: 6, ry: 6), 1e-6, "d2@1.55")

// Clock and splash scale.
near(M.timelineAt(elapsedMs: 0), 0, 0, "clock 0"); near(M.timelineAt(elapsedMs: 4800), 2.4, 0, "clock 4800")
near(M.timelineAt(elapsedMs: 10_000), 2.4, 0, "clock capped"); near(M.timelineAt(elapsedMs: -5), 0, 0, "clock negative")
near(f0.t1.w * M.unitDp * 1.45, 29.04, 0.01, "stem size")
near((f0.camCx - 53.0) * M.unitDp * f0.camScale, 0, 0, "icon centred x"); near((f0.camCy - 70.5) * M.unitDp * f0.camScale, 0, 0, "icon centred y")

// Mode.
check(ItOpeningLogic.mode(playedBefore: false, animationsOff: false), .full, "first run full")
check(ItOpeningLogic.mode(playedBefore: true, animationsOff: false), .end, "played -> end")
check(ItOpeningLogic.mode(playedBefore: false, animationsOff: true), .end, "reduce motion -> end")

// Extra frames used for the screenshots (0.3, 1.0): sane, not NaN, camera moving from icon to wordmark.
for t in [0.3, 1.0, 1.6] {
    let f = M.frame(t)
    check(f.camScale.isFinite && f.d1.cx.isFinite && f.d2.cy.isFinite, true, "finite@\(t)")
}
check(M.frame(0.6).camCx > 53.0 && M.frame(0.6).camCx < 89.1, true, "camera mid-move@0.6")

if failures == 0 { print("opening motion: all checks passed") } else { print("\(failures) FAILED"); exit(1) }
