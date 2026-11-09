// Logic checks for Features/Camera/ReceiptSteadyGate.swift — bash ios/LogicTests/run.sh
import Foundation
import CoreGraphics

var fails = 0
func check(_ c: Bool, _ m: String) { if !c { fails += 1; print("FAIL: \(m)") } }

let receipt = [CGPoint(x: 0.2, y: 0.9), CGPoint(x: 0.8, y: 0.9), CGPoint(x: 0.8, y: 0.1), CGPoint(x: 0.2, y: 0.1)]
let next = receipt.map { CGPoint(x: $0.x - 0.15, y: $0.y) }
let tiny = [CGPoint(x: 0.4, y: 0.6), CGPoint(x: 0.6, y: 0.6), CGPoint(x: 0.6, y: 0.4), CGPoint(x: 0.4, y: 0.4)]
func still(_ o: [CGPoint]?, focus: Bool = true) -> ReceiptSteadySample {
    ReceiptSteadySample(rotationRate: 0.02, acceleration: 0.01, focusSettled: focus, outline: o)
}
func shaky(_ o: [CGPoint]?) -> ReceiptSteadySample {
    ReceiptSteadySample(rotationRate: 0.6, acceleration: 0.2, focusSettled: true, outline: o)
}
/// Feeds the same sample every 50 ms from `from` for `seconds`; returns the last verdict and whether any tick auto-shot.
func run(_ g: inout ReceiptSteadyGate, from: Double, seconds: Double, _ s: ReceiptSteadySample) -> (ReceiptSteadyVerdict, Bool) {
    var last = ReceiptSteadyVerdict(hint: .holdStill, shutterUnlocked: false, autoShoot: false), fired = false
    var t = from
    while t <= from + seconds + 0.0001 {
        last = g.tick(now: t, s)
        fired = fired || last.autoShoot
        t += 0.05
    }
    return (last, fired)
}

do { // Steady over a receipt: fires only after the hold.
    var g = ReceiptSteadyGate()
    check(!run(&g, from: 0, seconds: 0.4, still(receipt)).1, "no shot before the hold")
    let v = run(&g, from: 0.45, seconds: 0.2, still(receipt))
    check(v.1, "shoots after the hold"); check(v.0.shutterUnlocked, "shutter open when steady")
}
do { // Shaking: never fires, shutter locked, says hold still.
    var g = ReceiptSteadyGate()
    let v = run(&g, from: 0, seconds: 5, shaky(receipt))
    check(!v.1, "never shoots while shaking"); check(!v.0.shutterUnlocked, "shutter locked while shaking")
    check(v.0.hint == .holdStill, "says hold still")
}
do { // One jolt restarts the hold.
    var g = ReceiptSteadyGate()
    _ = run(&g, from: 0, seconds: 0.4, still(receipt))
    _ = g.tick(now: 0.45, shaky(receipt))
    check(!run(&g, from: 0.5, seconds: 0.4, still(receipt)).1, "jolt restarts the hold")
}
do { // Not focused: no shot.
    var g = ReceiptSteadyGate()
    let v = run(&g, from: 0, seconds: 2, still(receipt, focus: false))
    check(!v.1, "no shot while focusing"); check(v.0.hint == .focusing, "says focusing")
}
do { // Steady with no receipt seen: the person can shoot by hand; nothing fires by itself.
    var g = ReceiptSteadyGate()
    let v = run(&g, from: 0, seconds: 2, still(nil))
    check(!v.1, "no auto shot without an outline"); check(v.0.shutterUnlocked, "manual shot allowed")
    check(v.0.hint == .findingReceipt, "says looking")
}
do { // Too small in frame.
    var g = ReceiptSteadyGate()
    let v = run(&g, from: 0, seconds: 2, still(tiny))
    check(!v.1, "no auto shot when small"); check(v.0.hint == .moveCloser, "says move closer")
}
do { // A receipt still moving in the frame doesn't fire, though the phone is still.
    var g = ReceiptSteadyGate(); var t = 0.0, fired = false
    for i in 0..<60 {
        let shifted = receipt.map { CGPoint(x: $0.x + (i % 2 == 0 ? 0 : 0.05), y: $0.y) }
        fired = fired || g.tick(now: t, still(shifted)).autoShoot
        t += 0.05
    }
    check(!fired, "moving outline never fires")
}
do { // Never stuck: shutter opens after the wait even in a shaking truck.
    var g = ReceiptSteadyGate()
    check(!run(&g, from: 0, seconds: 9.5, shaky(nil)).0.shutterUnlocked, "locked before the wait")
    check(run(&g, from: 9.55, seconds: 0.6, shaky(nil)).0.shutterUnlocked, "opens after the wait")
}
do { // After a shot the same receipt isn't shot again; a new position is.
    var g = ReceiptSteadyGate()
    check(run(&g, from: 0, seconds: 0.7, still(receipt)).1, "first shot")
    g.didShoot(now: 0.8, outline: receipt)
    let same = run(&g, from: 0.85, seconds: 3, still(receipt))
    check(!same.1, "same receipt not re-shot"); check(same.0.hint == .moveToNext, "says next")
    check(same.0.shutterUnlocked, "can still shoot by hand")
    check(run(&g, from: 3.9, seconds: 0.8, still(next)).1, "next receipt shoots")
}
do { // Receipt taken away and another put in the same spot.
    var g = ReceiptSteadyGate()
    g.didShoot(now: 0, outline: receipt)
    _ = run(&g, from: 0.05, seconds: 1, still(nil))
    check(run(&g, from: 1.1, seconds: 0.8, still(receipt)).1, "swap in the same spot shoots")
}
do { // A one-frame dropout doesn't count as the receipt leaving.
    var g = ReceiptSteadyGate()
    g.didShoot(now: 0, outline: receipt)
    _ = run(&g, from: 0.05, seconds: 1, still(receipt))
    _ = g.tick(now: 1.1, still(nil))
    check(!run(&g, from: 1.15, seconds: 1, still(receipt)).1, "dropout doesn't re-shoot")
}
do { // A soft photo: same receipt shoots again straight away.
    var g = ReceiptSteadyGate()
    _ = run(&g, from: 0, seconds: 0.7, still(receipt))
    g.retry(now: 0.8)
    check(!run(&g, from: 0.85, seconds: 0.3, still(receipt)).1, "retry waits for a new hold")
    check(run(&g, from: 1.2, seconds: 0.5, still(receipt)).1, "retry shoots")
}

// Retakes
check(ReceiptRetake.shouldRetake(sharpness: 60, retakesSoFar: 0), "soft retakes")
check(!ReceiptRetake.shouldRetake(sharpness: 60, retakesSoFar: 2), "stops after two")
check(!ReceiptRetake.shouldRetake(sharpness: 500, retakesSoFar: 0), "sharp keeps")

// Geometry
check(abs(ReceiptSteadyGate.area(receipt) - 0.48) < 0.0001, "area")
check(abs(ReceiptSteadyGate.drift(receipt, next) - 0.15) < 0.0001, "drift")

// Switch: on unless the server says false, and back on when it stops saying so.
let d = UserDefaults(suiteName: "receipt-camera-tests")!
d.removePersistentDomain(forName: "receipt-camera-tests")
check(ReceiptCameraSwitch.isOn(defaults: d), "on by default")
ReceiptCameraSwitch.apply(serverValue: false, defaults: d); check(!ReceiptCameraSwitch.isOn(defaults: d), "server off")
ReceiptCameraSwitch.apply(serverValue: nil, defaults: d); check(ReceiptCameraSwitch.isOn(defaults: d), "key gone = on")

if fails > 0 { print("\(fails) receipt steady check(s) failed"); exit(1) }
print("receipt steady gate OK")
