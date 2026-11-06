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
    /// The Ittai hexagon is a thin outline: at the size of a filled icon it reads small (Tim, 2026-11-04), so it is
    /// drawn larger. Everything else stays 1.
    var iconScale: CGFloat = 1
    /// What the bead shows while this tab is selected, when a tap on it does something (Receipts: a camera;
    /// tapping the bead again takes the photo — Tim, 2026-11-05). nil = the tab's own icon.
    var beadIcon: Image? = nil
    /// Instead of a second icon, the tab's own icon turns into the action by this many degrees (Add: + turns
    /// into ×). It spins in: two turns clockwise, then a slow turn back to lock (Tim, 2026-11-06).
    var beadTurn: Double? = nil
    /// Spoken name of that action ("Snap a receipt"), used for the selected tab instead of its title.
    var beadLabel: String? = nil
    /// What a long press does, spoken as a VoiceOver action ("Add a job"). nil = no long press.
    var longPressLabel: String? = nil
}

struct ItMeniscusBar: View {
    @Environment(\.itAppStyle) private var style
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    let items: [ItMeniscusItem]
    @Binding var selected: Int
    /// A tap on the tab that is already selected (the bead): the tab's own action, e.g. Receipts takes a photo.
    var onReselect: ((Int) -> Void)? = nil
    /// A long press on a tab (Add: straight to "Add a job" — Tim, 2026-11-05). The tap that ends it is ignored.
    var onLongPress: ((Int) -> Void)? = nil
    /// Animated copy of `selected`, so the bead and notch can be drawn mid-slide.
    @State private var position: CGFloat = 0

    /// Bar height below its top edge (labels sit here); the bead rises `rise` above it.
    static let barHeight: CGFloat = 58
    /// Icons start this far below the bar's edge, so labels start at iconTop + 25 (39 pt): 5 pt clear of the bead's
    /// collar, which ends at ItMeniscusGeometry.depth (34 pt). At 1.3.82 the collar touched the label (Tim's iPhone).
    static let iconTop: CGFloat = 14

    var body: some View {
        ItMeniscusBarBody(items: items, selected: selected, position: position, style: style, onSelect: { index in
            if index == selected { onReselect?(index) } else { selected = index }
        }, onLongPress: onLongPress, reduceMotion: reduceMotion)
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
        .onChange(of: selected) { new in   // the one-value form: the Mac design render targets macOS 13
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
    let onLongPress: ((Int) -> Void)?
    var reduceMotion = false
    /// The slot a long press just fired on: that slot's own tap, which follows the release, is dropped.
    @State private var longPressed: (id: Int, at: Date)?

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
            // Mid-slide the bead is passing over other slots: it takes no taps and shows no action until it lands.
            let settled = abs(position - CGFloat(selected)) < 0.01

            ZStack(alignment: .topLeading) {
                ItMeniscusSurface(cx: cx, edgeY: edgeY)
                    .fill(style.surface)
                ItMeniscusSurface(cx: cx, edgeY: edgeY, edgeOnly: true)
                    .stroke(style.line, lineWidth: 1)

                HStack(spacing: 0) {
                    ForEach(items) { item in
                        let active = item.id == selected
                        Button {
                            if let lp = longPressed, lp.id == item.id, Date().timeIntervalSince(lp.at) < 0.8 {
                                longPressed = nil
                            } else {
                                onSelect(item.id)
                            }
                        } label: {
                            // Every label on one line: the icon has a fixed 22 pt box and a larger one (Kaname's
                            // hexagon) grows upwards out of it, and the stack sits at a fixed top instead of being
                            // centred — centred, the taller Kaname stack pushed its label 4 pt lower (Tim, 1.3.82).
                            VStack(spacing: 3) {
                                item.icon
                                    .renderingMode(.template)
                                    .resizable()
                                    .scaledToFit()
                                    .frame(width: 22 * item.iconScale, height: 22 * item.iconScale)
                                    .frame(width: 22, height: 22, alignment: .bottom)
                                    .foregroundStyle(style.meta)
                                    .opacity(item.id == under ? 0 : 1)
                                Text(item.title)
                                    .font(.system(size: 11, weight: active ? .semibold : .medium))
                                    .foregroundStyle(active ? style.tenant : style.meta)
                                    .lineLimit(1)
                                    .minimumScaleFactor(0.8)
                            }
                            .padding(.top, ItMeniscusBar.iconTop)
                            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .simultaneousGesture(LongPressGesture(minimumDuration: 0.45).onEnded { _ in
                            guard let onLongPress, item.longPressLabel != nil else { return }
                            longPressed = (item.id, Date())
                            onLongPress(item.id)
                        })
                        .accessibilityLabel(item.title)
                        .accessibilityHint(active && item.beadLabel != nil ? "Tap again: \(item.beadLabel!)" : "")
                        .accessibilityAddTraits(active ? [.isSelected] : [])
                        .accessibilityActions {
                            if let label = item.longPressLabel, let onLongPress {
                                Button(label) { onLongPress(item.id) }
                            }
                        }
                    }
                }
                .frame(height: ItMeniscusBar.barHeight)
                .offset(y: edgeY)

                // The collar: the gap between bead and cup in the bar's own colour, outlined like the bar's edge
                // (its circle is the notch's arc). See-through, it let a navy button behind melt into the bead
                // (Tim's iPhone, Schedule, 2026-11-04).
                Circle()
                    .fill(style.surface)
                    .overlay { Circle().stroke(style.line, lineWidth: 1) }
                    .frame(width: ItMeniscusGeometry.bowl * 2, height: ItMeniscusGeometry.bowl * 2)
                    .offset(x: cx - ItMeniscusGeometry.bowl, y: ItMeniscusGeometry.centreBelowEdge + edgeY - ItMeniscusGeometry.bowl)
                    .allowsHitTesting(false)
                    .accessibilityHidden(true)

                // The bead.
                Circle()
                    .fill(style.tenant)
                    .frame(width: ItMeniscusGeometry.bead, height: ItMeniscusGeometry.bead)
                    .overlay {
                        if items.indices.contains(under) {
                            // Landing on the selected tab, a tab with an action turns into it (Receipts: the camera).
                            // The two cross-fade over the last third of the slide instead of swapping when it lands
                            // (Tim, 2026-11-06: "Receipts icon to camera jump"); `settle` comes from the animated
                            // position, so it follows the spring. No `.animation` here: inside
                            // this per-frame body it left the icon behind at the slot the bead came from.
                            let item = items[under]
                            if let turn = item.beadTurn {
                                ItBeadSpin(open: settled && item.id == selected, turn: turn, reduceMotion: reduceMotion) {
                                    beadGlyph(item.icon, scale: item.iconScale)
                                }
                            } else {
                            let action = item.id == selected ? item.beadIcon : nil
                            let settle = action == nil ? 0 : min(1, max(0, 1 - abs(position - CGFloat(selected)) / 0.33))
                            ZStack {
                                beadGlyph(item.icon, scale: item.iconScale)
                                    .opacity(1 - settle)
                                    .scaleEffect(1 - 0.35 * settle)
                                // Always drawn (hidden when there is no action): inserted conditionally inside
                                // this per-frame body it never appeared (simulator, 2026-11-06).
                                beadGlyph(action ?? item.icon, scale: item.iconScale)
                                    .opacity(settle)
                                    .scaleEffect(0.65 + 0.35 * settle)
                            }
                            }
                        }
                    }
                    .offset(x: cx - ItMeniscusGeometry.bead / 2, y: 0)
                    // The bead itself is a target: tapping it is tapping its tab (the slot button carries the
                    // accessibility label, so the bead stays hidden from VoiceOver).
                    .contentShape(Circle())
                    .onTapGesture { if settled, items.indices.contains(selected) { onSelect(items[selected].id) } }
                    .allowsHitTesting(settled)
                    .accessibilityHidden(true)
            }
        }
    }
}

private extension ItMeniscusBarBody {
    func beadGlyph(_ image: Image, scale: CGFloat) -> some View {
        image
            .renderingMode(.template)
            .resizable()
            .scaledToFit()
            .frame(width: 24 * scale, height: 24 * scale)
            .foregroundStyle(style.onTenant)
    }
}

/// The bead icon that turns into its action once the bead has landed: fast clockwise spins, then a slow turn back
/// that locks it in place. Closing unwinds it. The resting angle is `open ? turn : 0`; the keyframes add an offset
/// that ends on a whole number of turns, so nothing jumps when the animator lets go.
private struct ItBeadSpin<Content: View>: View {
    let open: Bool
    let turn: Double
    let reduceMotion: Bool
    @ViewBuilder let content: Content

    var body: some View {
        let rest = open ? turn : 0
        if reduceMotion {
            content.rotationEffect(.degrees(rest))
        } else {
            content.keyframeAnimator(initialValue: 0.0, trigger: open) { view, offset in
                view.rotationEffect(.degrees(rest + offset))
            } keyframes: { _ in
                if open {
                    // From the + (−turn), past the × by 30° after two turns, then slowly back onto it.
                    CubicKeyframe(-turn, duration: 0.01)
                    CubicKeyframe(720 + 30, duration: 0.6)
                    SpringKeyframe(720, duration: 0.55, spring: .init(response: 0.55, dampingRatio: 0.9))
                } else {
                    CubicKeyframe(turn, duration: 0.01)
                    CubicKeyframe(0, duration: 0.25)
                }
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
