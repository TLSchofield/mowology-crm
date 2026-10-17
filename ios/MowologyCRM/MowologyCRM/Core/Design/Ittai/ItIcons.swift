//
//  ItIcons.swift
//  MowologyCRM
//
//  The shared Ittai status icons (v1.2 Option A): one icon set, used by task statuses and employee
//  states alike — the WORDS differ, never the shapes. Drawn on a 16-unit grid, in one colour.
//  Navigation/tab icons are separate and never reuse these shapes.
//

import SwiftUI

enum ItIconShape: String, CaseIterable {
    case clock, triangle, octagon, check, dashedCircle, slashedCircle
    case cloudSaved, cloudSending, cloudSent, cloudFailed, cloudAlert
}

struct ItIcon: View {
    let shape: ItIconShape
    var size: CGFloat = 14
    let color: Color

    var body: some View {
        Canvas { ctx, c in
            let k = c.width / 16
            func p(_ x: CGFloat, _ y: CGFloat) -> CGPoint { CGPoint(x: x * k, y: y * k) }
            func circle(_ cx: CGFloat, _ cy: CGFloat, _ r: CGFloat) -> Path {
                Path(ellipseIn: CGRect(x: (cx - r) * k, y: (cy - r) * k, width: 2 * r * k, height: 2 * r * k))
            }
            let line = StrokeStyle(lineWidth: 2 * k, lineCap: .round, lineJoin: .round)
            let thin = StrokeStyle(lineWidth: 1.5 * k, lineCap: .round, lineJoin: .round)
            // The cloud outline shared by the sync family.
            func cloud() -> Path {
                var q = Path()
                q.move(to: p(4.6, 12.75)); q.addLine(to: p(11.5, 12.75))
                q.addCurve(to: p(11.85, 6.77), control1: p(14.3, 12.75), control2: p(14.6, 7.2))
                q.addCurve(to: p(4.1, 7.4), control1: p(11.1, 3.2), control2: p(5.2, 3.0))
                q.addCurve(to: p(4.6, 12.75), control1: p(1.6, 7.9), control2: p(1.9, 12.75))
                return q
            }
            switch shape {
            case .clock:
                ctx.stroke(circle(8, 8, 6.2), with: .color(color), style: line)
                var h = Path(); h.move(to: p(8, 4.8)); h.addLine(to: p(8, 8)); h.addLine(to: p(10.2, 9.4))
                ctx.stroke(h, with: .color(color), style: line)
            case .triangle:
                var t = Path(); t.move(to: p(8, 1.5)); t.addLine(to: p(15, 14)); t.addLine(to: p(1, 14)); t.closeSubpath()
                ctx.fill(t, with: .color(color))
            case .octagon:
                var o = Path()
                o.move(to: p(5.1, 1)); o.addLine(to: p(10.9, 1)); o.addLine(to: p(15, 5.1)); o.addLine(to: p(15, 10.9))
                o.addLine(to: p(10.9, 15)); o.addLine(to: p(5.1, 15)); o.addLine(to: p(1, 10.9)); o.addLine(to: p(1, 5.1)); o.closeSubpath()
                ctx.fill(o, with: .color(color))
                var bar = Path(); bar.move(to: p(4.6, 8)); bar.addLine(to: p(11.4, 8))
                ctx.blendMode = .destinationOut
                ctx.stroke(bar, with: .color(.black), style: StrokeStyle(lineWidth: 2.2 * k))
                ctx.blendMode = .normal
            case .check:
                ctx.fill(circle(8, 8, 7), with: .color(color))
                var m = Path(); m.move(to: p(4.6, 8.2)); m.addLine(to: p(7, 10.6)); m.addLine(to: p(11.4, 5.8))
                ctx.blendMode = .destinationOut
                ctx.stroke(m, with: .color(.black), style: line)
                ctx.blendMode = .normal
            case .dashedCircle:
                ctx.stroke(circle(8, 8, 6), with: .color(color), style: StrokeStyle(lineWidth: 2 * k, dash: [2.6 * k, 2.1 * k]))
            case .slashedCircle:
                ctx.stroke(circle(8, 8, 6), with: .color(color), style: thin)
                var s = Path(); s.move(to: p(3.8, 12.2)); s.addLine(to: p(12.2, 3.8))
                ctx.stroke(s, with: .color(color), style: thin)
            case .cloudSaved:
                ctx.stroke(cloud(), with: .color(color), style: thin)
                ctx.fill(circle(8, 9.6, 1.3), with: .color(color))
            case .cloudSending:
                ctx.stroke(cloud(), with: .color(color), style: thin)
                var a = Path(); a.move(to: p(8, 11)); a.addLine(to: p(8, 7.9))
                a.move(to: p(6.6, 9.3)); a.addLine(to: p(8, 7.9)); a.addLine(to: p(9.4, 9.3))
                ctx.stroke(a, with: .color(color), style: thin)
            case .cloudSent:
                ctx.stroke(cloud(), with: .color(color), style: thin)
                var m = Path(); m.move(to: p(6.3, 9.6)); m.addLine(to: p(7.6, 10.9)); m.addLine(to: p(10, 8.3))
                ctx.stroke(m, with: .color(color), style: thin)
            case .cloudFailed:
                ctx.stroke(cloud(), with: .color(color), style: thin)
                var x = Path(); x.move(to: p(6.7, 8.4)); x.addLine(to: p(9.3, 11)); x.move(to: p(9.3, 8.4)); x.addLine(to: p(6.7, 11))
                ctx.stroke(x, with: .color(color), style: thin)
            case .cloudAlert:
                ctx.stroke(cloud(), with: .color(color), style: thin)
                var e = Path(); e.move(to: p(8, 7.9)); e.addLine(to: p(8, 9.9))
                ctx.stroke(e, with: .color(color), style: thin)
                ctx.fill(circle(8, 11.3, 0.8), with: .color(color))
            }
        }
        .frame(width: size, height: size)
        .accessibilityHidden(true)   // the word next to it carries the meaning
    }
}
