import SwiftUI

/// First-open boundary: a new app day begins at 04:00 local time.
enum OpeningAssemblyStore {
    private static let key = "mw.openingAssembly.lastDay.v1"

    static func dayString(_ date: Date = Date(), calendar input: Calendar = .current) -> String {
        var calendar = input
        let shifted = calendar.date(byAdding: .hour, value: -4, to: date) ?? date
        let parts = calendar.dateComponents([.year, .month, .day], from: shifted)
        return String(format: "%04d-%02d-%02d", parts.year ?? 0, parts.month ?? 0, parts.day ?? 0)
    }

    static func shouldPlay(date: Date = Date(), defaults: UserDefaults = .standard, calendar: Calendar = .current) -> Bool {
        defaults.string(forKey: key) != dayString(date, calendar: calendar)
    }

    static func markPlayed(date: Date = Date(), defaults: UserDefaults = .standard, calendar: Calendar = .current) {
        defaults.set(dayString(date, calendar: calendar), forKey: key)
    }
}

/// The frozen 64-unit Ittai symbol, sampled at a point in its two-second assembly.
struct ItSymbolAssembly: View {
    var time: Double
    var size: CGFloat = 192
    var color: Color

    private let chaos: [(x: CGFloat, y: CGFloat, angle: CGFloat)] = [
        (28.2, 28.0, 16), (36.2, 31.5, -48), (29.4, 36.4, 72),
        (34.8, 25.8, 137), (25.7, 32.8, -101), (38.7, 37.0, 29)
    ]

    var body: some View {
        Canvas { context, canvas in
            let scale = canvas.width / 64
            let row = ItSymbolRow.row(for: size)
            let starts = [0.34, 0.40, 0.47, 0.54, 0.61, 0.68]
            func point(_ p: CGPoint) -> CGPoint { CGPoint(x: p.x * scale, y: p.y * scale) }
            func smooth(_ value: Double) -> CGFloat {
                let x = CGFloat(max(0, min(1, value)))
                return x * x * (3 - 2 * x)
            }
            for i in 0..<6 {
                let end = ItSymbolGeometry.side(i, visible: row.visible)
                let endCentre = CGPoint(x: (end.0.x + end.1.x) / 2, y: (end.0.y + end.1.y) / 2)
                let endAngle = atan2(end.1.y - end.0.y, end.1.x - end.0.x)
                let startAngle = chaos[i].angle * .pi / 180
                let progress = smooth((time - starts[i]) / (1.38 - starts[i]))
                let cx = chaos[i].x + (endCentre.x - chaos[i].x) * progress
                let cy = chaos[i].y + (endCentre.y - chaos[i].y) * progress
                var delta = endAngle - startAngle
                while delta > .pi { delta -= 2 * .pi }
                while delta < -.pi { delta += 2 * .pi }
                let angle = startAngle + delta * progress
                let length = hypot(end.1.x - end.0.x, end.1.y - end.0.y)
                let dx = cos(angle) * length / 2, dy = sin(angle) * length / 2
                var path = Path()
                path.move(to: point(CGPoint(x: cx - dx, y: cy - dy)))
                path.addLine(to: point(CGPoint(x: cx + dx, y: cy + dy)))
                context.stroke(path, with: .color(color), style: StrokeStyle(lineWidth: row.side * scale, lineCap: .round))
            }

            let unity = smooth((time - 1.38) / 0.28)
            context.opacity = unity
            if let spokeWidth = row.spokes {
                var spokes = Path()
                for i in 0..<6 {
                    let spoke = ItSymbolGeometry.spoke(i)
                    spokes.move(to: point(spoke.0)); spokes.addLine(to: point(spoke.1))
                }
                context.stroke(spokes, with: .color(color), style: StrokeStyle(lineWidth: spokeWidth * scale))
            }
            let centre = point(ItSymbolGeometry.centre), radius = ItSymbolGeometry.ringRadius * scale
            let ring = Path(ellipseIn: CGRect(x: centre.x - radius, y: centre.y - radius, width: radius * 2, height: radius * 2))
            context.stroke(ring, with: .color(color), lineWidth: (row.ring ?? row.side) * scale)
        }
        .frame(width: size, height: size)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Ittai assembling")
    }
}
