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
    /// How many slots the bead last travelled: Add's + spins more the further it came (Tim, 2026-11-07).
    @State private var travel: CGFloat = 0

    /// Bar height below its top edge (labels sit here); the bead rises `rise` above it.
    static let barHeight: CGFloat = 58
    /// Icons start this far below the bar's edge, so labels start at iconTop + 25 (39 pt): 5 pt clear of the bead's
    /// collar, which ends at ItMeniscusGeometry.depth (34 pt). At 1.3.82 the collar touched the label (Tim's iPhone).
    static let iconTop: CGFloat = 14

    var body: some View {
        ItMeniscusBarBody(items: items, selected: selected, position: position, travel: travel, style: style, onSelect: { index in
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
            travel = abs(CGFloat(new) - position)
            if reduceMotion { position = CGFloat(new) }
            else { withAnimation(.spring(response: 0.38, dampingFraction: 0.78)) { position = CGFloat(new) } }
        }
        .accessibilityElement(children: .contain)
    }
}

/// The bar. Smoothness (Tim, 2026-11-07: "the main bottom navbar is still not smooth"): until now this whole view was
/// Animatable, so every frame of the slide rebuilt all five buttons with their gestures and labels. Now the buttons are
/// built once per selection; the notch (an animatable Shape) and the collar and bead (plain offsets) move on their
/// own, and only the bead's glyph (ItBeadFace) is worked out per frame.
private struct ItMeniscusBarBody: View {
    let items: [ItMeniscusItem]
    let selected: Int
    let position: CGFloat
    let travel: CGFloat
    let style: ItAppStyle
    let onSelect: (Int) -> Void
    let onLongPress: ((Int) -> Void)?
    var reduceMotion = false
    /// The slot a long press just fired on: that slot's own tap, which follows the release, is dropped.
    @State private var longPressed: (id: Int, at: Date)?

    var body: some View {
        GeometryReader { geo in
            let width = geo.size.width
            let count = max(items.count, 1)
            let cx = ItMeniscusGeometry.beadX(position, count: count, width: width)
            let edgeY = ItMeniscusGeometry.rise

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
                                    // The selected tab's icon lives in the bead. A slot the bead passes over is
                                    // covered by the collar, so it no longer has to hide frame by frame.
                                    .opacity(active ? 0 : 1)
                                    .animation(reduceMotion ? nil : .easeOut(duration: 0.18), value: active)
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

                // The bead: its face (the glyph of the tab it is over) is the only part worked out per frame.
                ItBeadFace(items: items, selected: selected, position: position, travel: travel, style: style,
                           reduceMotion: reduceMotion, onSelect: onSelect)
                    .frame(width: ItMeniscusGeometry.bead, height: ItMeniscusGeometry.bead)
                    .offset(x: cx - ItMeniscusGeometry.bead / 2, y: 0)
                    .accessibilityHidden(true)
            }
        }
    }
}

/// The bead and its glyph, drawn every frame of the slide with the interpolated `position` (Animatable): the icon is
/// the tab it is over right now, and on landing a tab with an action turns into it.
private struct ItBeadFace: View, Animatable {
    let items: [ItMeniscusItem]
    let selected: Int
    var position: CGFloat
    let travel: CGFloat
    let style: ItAppStyle
    let reduceMotion: Bool
    let onSelect: (Int) -> Void

    var animatableData: CGFloat {
        get { position }
        set { position = newValue }
    }

    var body: some View {
        let count = max(items.count, 1)
        let under = ItMeniscusGeometry.nearest(position, count: count)
        // Mid-slide the bead is passing over other slots: it takes no taps and shows no action until it lands.
        let settled = abs(position - CGFloat(selected)) < 0.01
        Circle()
            .fill(style.tenant)
            .overlay {
                if items.indices.contains(under) {
                    // A tab whose icon turns into its action (Add: + into ×) rolls there with the bead (Tim, 2026-11-07:
                    // "the plus sign animation isn't fluid ... it needs to be smooth and consistent"). Its angle is a
                    // function of the bead's animated position, one turn per slot, so it moves on the same spring as
                    // the slide: no pause before it starts, no separate clock, more turns and faster from further away
                    // (exyte/AnimatedTabBar drives its ball the same way, from one animatable value).
                    // The + is always in the bead, hidden until needed ("have the plus sign always loaded behind the
                    // bead"): a glyph inserted mid-slide in this per-frame body has stuttered or not appeared before.
                    let rollIndex = items.firstIndex { $0.beadTurn != nil }
                    let roll = rollIndex.map { items[$0] }
                    let headingToRoll = rollIndex == selected && !settled
                    let fadeIn = travel < 0.01 || reduceMotion ? 1 : min(1, max(0, travel - abs(CGFloat(selected) - position)) / 0.25)
                    let rollShown: Double = rollIndex == nil ? 0 : (under == rollIndex ? 1 : (headingToRoll ? Double(fadeIn) : 0))
                    ZStack {
                        baseGlyph(under)
                            .opacity(1 - rollShown)
                        if let roll, let rollIndex {
                            beadGlyph(roll.icon, scale: roll.iconScale)
                                .rotationEffect(.degrees(Self.rollAngle(turn: roll.beadTurn ?? 0, position: position,
                                                                        at: rollIndex, reduceMotion: reduceMotion)))
                                .opacity(rollShown)
                        }
                    }
                }
            }
            // The bead itself is a target: tapping it is tapping its tab (the slot button carries the accessibility
            // label). Shape and tap stay on the bead's own frame; the offset is applied by the bar, outside.
            .contentShape(Circle())
            .onTapGesture { if settled, items.indices.contains(selected) { onSelect(items[selected].id) } }
            .allowsHitTesting(settled)
    }

    /// The rolling icon's angle: `turn` (the action, e.g. 45° for ×) when the bead sits on its tab, one full turn per
    /// slot away from it, clockwise as the bead moves right. Pure, so the angle can never jump between frames.
    static func rollAngle(turn: Double, position: CGFloat, at index: Int, reduceMotion: Bool) -> Double {
        reduceMotion ? turn : turn + Double(position - CGFloat(index)) * 360
    }

    /// The bead's ordinary face over a slot. Landing on the selected tab, a tab with an action turns into it (Receipts:
    /// the camera), cross-fading over the last third of the slide (Tim, 2026-11-06: "Receipts icon to camera jump").
    /// Both glyphs are always drawn: one inserted conditionally inside this per-frame body never appeared (2026-11-06).
    private func baseGlyph(_ under: Int) -> some View {
        let item = items[under]
        let action = item.id == selected ? item.beadIcon : nil
        let settle = action == nil ? 0 : min(1, max(0, 1 - abs(position - CGFloat(selected)) / 0.33))
        return ZStack {
            beadGlyph(item.icon, scale: item.iconScale)
                .opacity(1 - settle)
                .scaleEffect(1 - 0.35 * settle)
            beadGlyph(action ?? item.icon, scale: item.iconScale)
                .opacity(settle)
                .scaleEffect(0.65 + 0.35 * settle)
        }
    }

    private func beadGlyph(_ image: Image, scale: CGFloat) -> some View {
        image
            .renderingMode(.template)
            .resizable()
            .scaledToFit()
            .frame(width: 24 * scale, height: 24 * scale)
            .foregroundStyle(style.onTenant)
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
