//
//  ItOpeningMark.swift
//  MowologyCRM
//
//  The Ittai opening's drawing (Android ItOpening.kt `ItOpeningMark`) and the signed-out host that plays it and
//  then shows the Welcome words. Colours come from the Ittai tokens: the navy ground is `brand` (light), the gold
//  is the on-navy gold (`knowledgeMastered`, dark theme); letters are white as on Android. Frozen mark: geometry
//  is ItOpeningMotion's, not redrawn here.
//

import SwiftUI

enum ItOpeningColors {
    static var navy: Color { Color(itHex: ItTokenHex.light[.brand] ?? 0x233A5E) }
    static var gold: Color { Color(itHex: ItTokenHex.dark[.knowledgeMastered] ?? 0xC9A65A) }
    static let letters = Color.white
}

struct ItOpeningMark: View {
    let frame: ItOpeningMotion.Frame
    /// 1 = the opening's own size; a smaller value draws the same mark smaller (sign-in header).
    var zoom: CGFloat = 1

    var body: some View {
        Canvas { ctx, size in
            let unit = CGFloat(ItOpeningMotion.unitDp * frame.camScale) * zoom
            func point(_ x: Double, _ y: Double) -> CGPoint {
                CGPoint(x: size.width / 2 + CGFloat(x - frame.camCx) * unit,
                        y: size.height / 2 + CGFloat(y - frame.camCy) * unit)
            }
            func rect(_ r: ItOpeningMotion.Rect) {
                let o = point(r.x, r.y)
                ctx.fill(Path(CGRect(x: o.x, y: o.y, width: CGFloat(r.w) * unit, height: CGFloat(r.h) * unit)), with: .color(ItOpeningColors.letters))
            }
            func oval(_ o: ItOpeningMotion.Oval) {
                let p = point(o.cx - o.rx, o.cy - o.ry)
                ctx.fill(Path(ellipseIn: CGRect(x: p.x, y: p.y, width: CGFloat(o.rx * 2) * unit, height: CGFloat(o.ry * 2) * unit)),
                         with: .color(ItOpeningColors.gold))
            }
            rect(frame.i1); rect(frame.t1); rect(frame.t2)
            if frame.ringScale > 0 {
                let c = point(118.6, 72.0)
                let radius = CGFloat(22.1 * frame.ringScale) * unit
                ctx.stroke(Path(ellipseIn: CGRect(x: c.x - radius, y: c.y - radius, width: radius * 2, height: radius * 2)),
                           with: .color(ItOpeningColors.letters), lineWidth: CGFloat(13 * frame.ringScale) * unit)
            }
            rect(frame.aStem); rect(frame.i2)
            let b = point(frame.bar.x, frame.bar.y)
            let barRect = CGRect(x: b.x, y: b.y, width: CGFloat(frame.bar.w) * unit, height: CGFloat(frame.bar.h) * unit)
            ctx.fill(Path(roundedRect: barRect, cornerRadius: CGFloat(frame.bar.rx) * unit), with: .color(ItOpeningColors.gold))
            oval(frame.d1); oval(frame.d2)
        }
        .accessibilityHidden(true)
    }
}

/// The signed-out opening: the mark animates (or just shows its end state) on navy; once it ends, `content` fades in
/// over the bottom while the mark stays put. Tapping skips to the end.
struct ItOpeningStage<Content: View>: View {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var start = Date()
    @State private var skipped = false
    @AppStorage("ittai.opening.played") private var played = false
    let content: Content

    init(@ViewBuilder content: () -> Content) { self.content = content() }

    /// DEBUG: `-mwOpeningT <seconds>` freezes the timeline at that wordmark time.
    private var frozenT: Double? {
        #if DEBUG
        let args = ProcessInfo.processInfo.arguments
        if let i = args.firstIndex(of: "-mwOpeningT"), i + 1 < args.count, let t = Double(args[i + 1]) { return t }
        if args.contains("-mwWelcomeScreenDemo") { return ItOpeningMotion.T }
        #endif
        return nil
    }

    private var mode: ItOpeningLogic.Mode {
        if frozenT != nil { return .full }
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("-mwOpeningDemo"), !reduceMotion { return .full }   // the demo always plays
        #endif
        return ItOpeningLogic.mode(playedBefore: played, animationsOff: reduceMotion)
    }

    var body: some View {
        ZStack {
            ItOpeningColors.navy.ignoresSafeArea()
            TimelineView(.animation(paused: mode == .end || frozenT != nil || skipped)) { context in
                let t = timeline(at: context.date)
                ZStack {
                    ItOpeningMark(frame: ItOpeningMotion.frame(t)).ignoresSafeArea()
                    VStack {
                        Spacer()
                        content
                            .opacity(t >= ItOpeningMotion.T || frozenT != nil && (frozenT ?? 0) >= ItOpeningMotion.T ? 1 : 0)
                            .animation(.easeInOut(duration: 0.3), value: t >= ItOpeningMotion.T)
                    }
                }
            }
        }
        .contentShape(Rectangle())
        .onTapGesture { skipped = true; played = true }
        .onAppear { start = Date() }
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Ittai")
    }

    private func timeline(at date: Date) -> Double {
        if let frozenT { return min(ItOpeningMotion.T, frozenT) }
        if mode == .end || skipped { return ItOpeningMotion.T }
        let t = ItOpeningMotion.timelineAt(elapsedMs: Int64(date.timeIntervalSince(start) * 1000))
        if t >= ItOpeningMotion.T, !played { DispatchQueue.main.async { played = true } }
        return t
    }
}
