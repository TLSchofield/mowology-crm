//
//  ItOpeningMotion.swift
//  MowologyCRM
//
//  The Ittai opening: the app icon becomes the lowercase "ittai" wordmark (the tt flick up like a pinball flipper,
//  the dots land on the i's, a gold beam). PURE port of the Android ItOpeningMotion.kt (itself a port of render(t)
//  in design-studio/sandbox/ittai-logo-motion/v4-pinball.html): same constants, same maths, wordmark units.
//  No SwiftUI here, so the logic checks compile it on the Mac. The wordmark and symbol are frozen: do not redesign.
//

import Foundation

enum ItOpeningMotion {
    static let T = 2.4
    static let R = 6.0
    static let durationMs: Int64 = 4_800

    static let splashIconDp: Double = 288
    static let iconViewport: Double = 108
    static let iconStem: Double = 10.89
    /// Points per wordmark unit at camera scale 1 (Android: dp).
    static let unitDp: Double = (iconStem * splashIconDp / iconViewport) / (13.0 * 1.45)

    struct Rect: Equatable { var x: Double, y: Double, w: Double, h: Double, rx: Double = 0 }
    struct Oval: Equatable { var cx: Double, cy: Double, rx: Double, ry: Double }
    struct Frame: Equatable {
        var i1: Rect, t1: Rect, t2: Rect, aStem: Rect, i2: Rect, bar: Rect
        var ringScale: Double, d1: Oval, d2: Oval
        var camCx: Double, camCy: Double, camScale: Double
    }

    // JS: ICON / WORD
    private struct IconGeometry {
        let s1x = 33.5, s2x = 59.5, top = 62.9
        let barX = 28.8, barY = 70.4, barW = 48.4, barH = 10.2
        let d1x = 40.0, d2x = 66.0, dy = 48.9, dr = 7.9
    }
    private struct WordGeometry {
        let t1x = 31.0, t2x = 62.0, top = 28.0
        let barX = 25.0, barY = 44.0, barW = 56.0, barH = 11.0
        let i1x = 0.0, i2x = 165.2, iTop = 44.0, aStemX = 134.2
    }
    private static let ICON = IconGeometry()
    private static let WORD = WordGeometry()

    // JS: clamp / seg / lerp / ioc / oc / ob
    private static func clamp(_ v: Double) -> Double { max(0, min(1, v)) }
    private static func seg(_ t: Double, _ a: Double, _ b: Double) -> Double { clamp((t - a) / (b - a)) }
    private static func lerp(_ a: Double, _ b: Double, _ p: Double) -> Double { a + (b - a) * p }
    private static func ioc(_ p: Double) -> Double { p < 0.5 ? 4 * p * p * p : 1 - pow(-2 * p + 2, 3) / 2 }
    private static func oc(_ p: Double) -> Double { 1 - pow(1 - p, 3) }
    private static func ob(_ p: Double, _ k: Double = 1.2) -> Double {
        p <= 0 ? 0 : 1 + (k + 1) * pow(p - 1, 3) + k * pow(p - 1, 2)
    }

    // JS: hop
    private static func hop(_ p: Double, _ x0: Double, _ y0: Double, _ x1: Double, _ y1: Double, _ h: Double) -> Oval {
        let x = lerp(x0, x1, ioc(p) * 0.35 + p * 0.65)
        let y = lerp(y0, y1, p) - h * 4 * p * (1 - p)
        let st = sin(Double.pi * p) * 0.12
        return Oval(cx: x, cy: y, rx: R * (1 - st), ry: R * (1 + st))
    }

    // JS: squash
    private static func squash(_ k: Double, _ cx: Double, _ floor: Double, _ amt: Double = 0.32) -> Oval {
        let s = sin(Double.pi * k) * amt
        let ry = R * (1 - s)
        return Oval(cx: cx, cy: floor - ry, rx: R * (1 + s * 0.9), ry: ry)
    }

    // JS: rise
    private static func rise(_ k: Double, _ cx: Double, _ y0: Double, _ y1: Double) -> Oval {
        let e = 1 + 2.2 * pow(k - 1, 3) + 1.2 * pow(k - 1, 2)
        let y = lerp(y0, y1, e)
        let st = sin(Double.pi * min(1, k * 1.6)) * 0.1
        return Oval(cx: cx, cy: y, rx: R * (1 - st), ry: R * (1 + st))
    }

    static let FLICK0 = 0.75
    static let FLICK1 = 1.05
    static func stemTop(_ t: Double) -> Double { lerp(ICON.top, WORD.top, oc(seg(t, FLICK0, FLICK1))) }
    static let DOT_BOTTOM = 48.9 + 7.9
    static let TC: Double = {
        var tc = FLICK0, lo = FLICK0, hi = FLICK1
        for _ in 0..<40 {
            tc = (lo + hi) / 2
            if stemTop(tc) > DOT_BOTTOM { lo = tc } else { hi = tc }
        }
        return tc
    }()
    static let V0 = 250.0
    static let LAND = 1.55
    static let RING_TOP = 72.0 - 22.1 - 6.5

    // JS: fly. Returns x, y, vy.
    private static func fly(_ t: Double, _ t0: Double, _ x0: Double, _ y0: Double, _ v0: Double,
                            _ t1: Double, _ x1: Double, _ y1: Double) -> [Double] {
        let d = t1 - t0
        let u = t - t0
        let gr = 2 * (y1 - y0 + v0 * d) / (d * d)
        return [lerp(x0, x1, u / d), y0 - v0 * u + 0.5 * gr * u * u, -v0 + gr * u]
    }

    // JS: flightShape
    private static func flightShape(_ t: Double, _ f: [Double]) -> Oval {
        let r = lerp(ICON.dr, R, clamp((t - TC) / 0.25))
        var y = f[1]
        if t < FLICK1 { y = min(y, stemTop(t) - r) }
        let hit = sin(Double.pi * seg(t, TC, TC + 0.06)) * 0.3
        let st = min(1, abs(f[2]) / V0) * 0.14 - hit
        return Oval(cx: f[0], cy: y, rx: r * (1 - st), ry: r * (1 + st))
    }

    // JS: landing
    private static func landing(_ t: Double, _ x: Double) -> Oval {
        let rest = 44 - R
        if t < LAND + 0.06 { return squash(seg(t, LAND, LAND + 0.06), x, 44) }
        if t < LAND + 0.36 {
            let p = seg(t, LAND + 0.06, LAND + 0.36)
            let st = cos(Double.pi * p) * 0.08
            return Oval(cx: x, cy: rest - 12 * 4 * p * (1 - p), rx: R * (1 - abs(st)), ry: R * (1 + abs(st)))
        }
        if t < LAND + 0.41 { return squash(seg(t, LAND + 0.36, LAND + 0.41), x, 44, 0.2) }
        return rise(seg(t, LAND + 0.41, LAND + 0.7), x, rest, 31.5)
    }

    static func timelineAt(elapsedMs: Int64) -> Double { min(T, Double(max(0, elapsedMs)) / 1000.0 * 0.5) }

    // JS: setR clamps width and height to zero.
    private static func rect(_ x: Double, _ y: Double, _ w: Double, _ h: Double, _ rx: Double = 0) -> Rect {
        Rect(x: x, y: y, w: max(0, w), h: max(0, h), rx: rx)
    }

    // JS: render
    static func frame(_ t: Double) -> Frame {
        let g = oc(seg(t, FLICK0, FLICK1))
        let top = stemTop(t)
        let t1 = rect(lerp(ICON.s1x, WORD.t1x, g), top, 13, 100 - top)
        let t2 = rect(lerp(ICON.s2x, WORD.t2x, g), top, 13, 100 - top)
        let bh = lerp(ICON.barH, WORD.barH, g)
        let bar = rect(lerp(ICON.barX, WORD.barX, g), lerp(ICON.barY, WORD.barY, g), lerp(ICON.barW, WORD.barW, g), bh, bh / 2)

        let i1Scale = ob(seg(t, 0.38, 0.72))
        let i1 = rect(WORD.i1x, 100 - 56 * i1Scale, 13, 56 * i1Scale)
        let ring = ob(seg(t, 0.46, 0.8), 1.0)
        let aScale = ob(seg(t, 0.54, 0.88))
        let aStem = rect(WORD.aStemX, 100 - 56 * aScale, 13, 56 * aScale)
        let i2Scale = ob(seg(t, 0.62, 0.98))
        let i2 = rect(WORD.i2x, 100 - 56 * i2Scale, 13, 56 * i2Scale)

        let d1: Oval
        let d2: Oval
        if t < TC {
            d1 = Oval(cx: ICON.d1x, cy: ICON.dy, rx: ICON.dr, ry: ICON.dr)
            d2 = Oval(cx: ICON.d2x, cy: ICON.dy, rx: ICON.dr, ry: ICON.dr)
        } else {
            d1 = t < LAND ? flightShape(t, fly(t, TC, ICON.d1x, ICON.dy, V0, LAND, 6.5, 44 - R)) : landing(t, 6.5)
            if t < 1.2 {
                d2 = flightShape(t, fly(t, TC, ICON.d2x, ICON.dy, V0, 1.2, 118.6, RING_TOP - R))
            } else if t < 1.26 {
                d2 = squash(seg(t, 1.2, 1.26), 118.6, RING_TOP, 0.26)
            } else if t < LAND {
                d2 = hop(seg(t, 1.26, LAND), 118.6, RING_TOP - R, 171.7, 44 - R, 10.0)
            } else {
                d2 = landing(t, 171.7)
            }
        }

        let c = ioc(seg(t, 0.3, 1.0))
        let cTop = min(top, min(d1.cy - d1.ry, d2.cy - d2.ry))
        let autoCy = (cTop + 100) / 2
        let autoS = min(1.45, 140 / (100 - cTop + 12))
        return Frame(i1: i1, t1: t1, t2: t2, aStem: aStem, i2: i2, bar: bar, ringScale: max(ring, 0),
                     d1: d1, d2: d2, camCx: lerp(53.0, 89.1, c), camCy: lerp(autoCy, 62.0, c), camScale: lerp(autoS, 0.92, c))
    }
}

enum ItOpeningLogic {
    enum Mode { case full, end }
    /// Full animation only on a fresh, animated run; reduce motion (or already played) shows the end state.
    static func mode(playedBefore: Bool, animationsOff: Bool) -> Mode {
        !playedBefore && !animationsOff ? .full : .end
    }
}
