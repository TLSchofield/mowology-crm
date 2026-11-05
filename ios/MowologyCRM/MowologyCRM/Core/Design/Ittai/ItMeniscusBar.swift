//
//  ItMeniscusBar.swift
//  MowologyCRM
//
//  The tab bar with a "meniscus": the active tab's icon rises into a bead (tenant colour) sitting half above
//  the bar, and the bar's top edge cups it with a ring of page colour. Tapping another tab slides the bead and
//  the notch there together. One accent only, no glow, no dragging; Reduce Motion jumps instead of sliding.
//  Replaces the system tab bar in MainTabView (the TabView stays, so every tab keeps its own navigation).
//  Geometry: ItMeniscusGeometry (shared with Android's MeniscusGeometry).
//

import SwiftUI

struct ItMeniscusItem: Identifiable {
    let id: Int
    let title: String
    let icon: Image
}

struct ItMeniscusBar: View {
    @Environment(\.itAppStyle) private var style
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    let items: [ItMeniscusItem]
    @Binding var selected: Int
    /// Animated copy of `selected`, so the bead and notch can be drawn mid-slide.
    @State private var position: CGFloat = 0

    /// Bar height below its top edge (labels sit here); the bead rises `rise` above it.
    static let barHeight: CGFloat = 58

    var body: some View {
        ItMeniscusBarBody(items: items, selected: selected, position: position, style: style) { index in
            selected = index
        }
        .frame(height: ItMeniscusGeometry.rise + Self.barHeight)
        // The surface carries on under the home indicator. A fixed-height fill cannot grow into the safe area
        // (1.3.67 on Tim's iPhone showed the page through the home-indicator strip), so the fill is flexible:
        // clear above the bar's edge, surface below it, and the stack itself ignores the bottom safe area.
        .background {
            VStack(spacing: 0) {
                Color.clear.frame(height: ItMeniscusGeometry.rise + ItMeniscusGeometry.depth)
                style.surface
            }
            .ignoresSafeArea(.container, edges: .bottom)
        }
        .onAppear { position = CGFloat(selected) }
        .onChange(of: selected) { _, new in
            if reduceMotion { position = CGFloat(new) }
            else { withAnimation(.spring(response: 0.38, dampingFraction: 0.78)) { position = CGFloat(new) } }
        }
        .accessibilityElement(children: .contain)
    }
}

/// Drawn every animation frame with the interpolated `position` (Animatable), so the bead's icon is the tab
/// it is over right now and that slot hides its own icon.
private struct ItMeniscusBarBody: View, Animatable {
    let items: [ItMeniscusItem]
    let selected: Int
    var position: CGFloat
    let style: ItAppStyle
    let onSelect: (Int) -> Void

    var animatableData: CGFloat {
        get { position }
        set { position = newValue }
    }

    var body: some View {
        GeometryReader { geo in
            let width = geo.size.width
            let count = max(items.count, 1)
            let cx = ItMeniscusGeometry.beadX(position, count: count, width: width)
            let under = ItMeniscusGeometry.nearest(position, count: count)
            let edgeY = ItMeniscusGeometry.rise

            ZStack(alignment: .topLeading) {
                ItMeniscusSurface(cx: cx, edgeY: edgeY)
                    .fill(style.surface)
                ItMeniscusSurface(cx: cx, edgeY: edgeY, edgeOnly: true)
                    .stroke(style.line, lineWidth: 1)

                HStack(spacing: 0) {
                    ForEach(items) { item in
                        let active = item.id == selected
                        Button { onSelect(item.id) } label: {
                            VStack(spacing: 3) {
                                item.icon
                                    .renderingMode(.template)
                                    .resizable()
                                    .scaledToFit()
                                    .frame(width: 22, height: 22)
                                    .foregroundStyle(style.meta)
                                    .opacity(item.id == under ? 0 : 1)
                                Text(item.title)
                                    .font(.system(size: 11, weight: active ? .semibold : .medium))
                                    .foregroundStyle(active ? style.tenant : style.meta)
                                    .lineLimit(1)
                                    .minimumScaleFactor(0.8)
                            }
                            .frame(maxWidth: .infinity, minHeight: 48)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel(item.title)
                        .accessibilityAddTraits(active ? [.isSelected] : [])
                    }
                }
                .frame(height: ItMeniscusBar.barHeight)
                .offset(y: edgeY)

                // The bead.
                Circle()
                    .fill(style.tenant)
                    .frame(width: ItMeniscusGeometry.bead, height: ItMeniscusGeometry.bead)
                    .overlay {
                        if items.indices.contains(under) {
                            items[under].icon
                                .renderingMode(.template)
                                .resizable()
                                .scaledToFit()
                                .frame(width: 24, height: 24)
                                .foregroundStyle(style.onTenant)
                        }
                    }
                    .offset(x: cx - ItMeniscusGeometry.bead / 2, y: 0)
                    .allowsHitTesting(false)
                    .accessibilityHidden(true)
            }
        }
    }
}

/// The bar's surface (or just its top edge): straight, with the notch cut in around `cx`.
private struct ItMeniscusSurface: Shape {
    var cx: CGFloat
    let edgeY: CGFloat
    var edgeOnly = false

    var animatableData: CGFloat {
        get { cx }
        set { cx = newValue }
    }

    func path(in rect: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: rect.minX, y: edgeY))
        p.addLine(to: CGPoint(x: cx - ItMeniscusGeometry.halfWidth, y: edgeY))
        for s in ItMeniscusGeometry.notch(cx: cx) {
            p.addCurve(to: CGPoint(x: s.end.x, y: edgeY + s.end.y),
                       control1: CGPoint(x: s.c1.x, y: edgeY + s.c1.y),
                       control2: CGPoint(x: s.c2.x, y: edgeY + s.c2.y))
        }
        p.addLine(to: CGPoint(x: rect.maxX, y: edgeY))
        if !edgeOnly {
            p.addLine(to: CGPoint(x: rect.maxX, y: rect.maxY))
            p.addLine(to: CGPoint(x: rect.minX, y: rect.maxY))
            p.closeSubpath()
        }
        return p
    }
}
