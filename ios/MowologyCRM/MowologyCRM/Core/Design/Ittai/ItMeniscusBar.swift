//
//  ItMeniscusBar.swift
//  MowologyCRM
//
//  The tab bar with a "meniscus": the active tab's icon rises into a bead (tenant colour) sitting half above
//  the bar, and the bar's top edge cups it with a ring of page colour. Tapping another tab slides the bead and
//  the notch there together. One accent only, no glow, no dragging; Reduce Motion cross-fades instead of sliding.
//  Replaces the system tab bar in MainTabView (the TabView stays, so every tab keeps its own navigation).
//  Geometry and the slide's maths: ItMeniscusGeometry (shared with Android's MeniscusGeometry). The moving parts
//  are Core Animation layers: ItMeniscusMotion.
//

import SwiftUI
import UIKit

/// A tab's icon: an SF Symbol by name, or a template image (Kaname's hexagon). Both the SwiftUI buttons and the
/// bead's layers draw it.
enum ItMeniscusIcon: Equatable {
    case system(String)
    case image(UIImage)

    /// Resolves the icon to a template image, using a drawn glyph when a system symbol is unavailable.
    var image: Image {
        switch self {
        // The vector symbol whenever it exists (pixel-identical to before); the drawn fallback only when it does not.
        case .system(let name):
            return UIImage(systemName: name) == nil ? Image(uiImage: Self.systemImage(named: name)) : Image(systemName: name)
        case .image(let image): return Image(uiImage: image)
        }
    }

    /// Returns a non-empty template glyph for a system symbol that is unavailable (renamed or missing in this OS).
    private static func systemImage(named name: String) -> UIImage {
        if let image = UIImage(systemName: name) { return image }
        let configuration = UIGraphicsImageRendererFormat()
        configuration.opaque = false
        let image = UIGraphicsImageRenderer(size: CGSize(width: 24, height: 24), format: configuration).image { context in
            let glyphColour = UIColor(ItPalette(.light).text)
            glyphColour.setStroke()
            glyphColour.setFill()
            let path = UIBezierPath(ovalIn: CGRect(x: 3, y: 3, width: 18, height: 18))
            path.lineWidth = 2
            path.stroke()
            context.cgContext.fillEllipse(in: CGRect(x: 11, y: 16, width: 2, height: 2))
        }
        return image.withRenderingMode(.alwaysTemplate)
    }
}

/// Describes one stable tab identity and the glyphs and actions shown in its current slot.
struct ItMeniscusItem: Identifiable, Equatable {
    /// The tab's stable identity; its array position determines its logical slot.
    let id: Int
    let title: String
    let icon: ItMeniscusIcon
    /// The Ittai hexagon is a thin outline: at the size of a filled icon it reads small (Tim, 2026-11-04), so it is
    /// drawn larger. Everything else stays 1.
    var iconScale: CGFloat = 1
    /// What the bead shows while this tab is selected, when a tap on it does something (Receipts: a camera;
    /// tapping the bead again takes the photo — Tim, 2026-11-05). nil = the tab's own icon.
    var beadIcon: ItMeniscusIcon? = nil
    /// Instead of a second icon, the tab's own icon turns into the action by this many degrees (Add: + turns
    /// into ×), rolling there with the bead: one turn per slot travelled (Tim, 2026-11-07).
    var beadTurn: Double? = nil
    /// Spoken name of that action ("Snap a receipt"): VoiceOver reads it after the selected tab's name.
    var beadLabel: String? = nil
    /// What a long press does, spoken as a VoiceOver action ("Add a job"). nil = no long press.
    var longPressLabel: String? = nil
}

/// Presents stable tab identities while Core Animation moves the meniscus between their physical slots.
struct ItMeniscusBar: View {
    @Environment(\.itAppStyle) private var style
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.layoutDirection) private var layoutDirection

    let items: [ItMeniscusItem]
    /// The slot the screen is on. When it changes from outside (a push, the Add dial closing) the bead follows.
    let selected: Int
    /// A tap on another tab. The bead leaves first; this is called a moment later (see `tap`).
    let onSelect: (Int) -> Void
    /// A tap on the tab that is already selected (the bead): the tab's own action, e.g. Receipts takes a photo.
    var onReselect: ((Int) -> Void)? = nil
    /// A long press on a tab (Add: straight to "Add a job" — Tim, 2026-11-05). The tap that ends it is ignored.
    var onLongPress: ((Int) -> Void)? = nil

    /// The slide's clock, shared by the two moving layers.
    @State private var motion = ItMeniscusMotion()
    /// The slot just tapped: the bar shows it as selected at once, until the owner has answered the tap (then nil,
    /// and `selected` is the truth again — also when the owner did not move there).
    @State private var shown: Int?
    /// The slot a long press just fired on: that slot's own tap, which follows the release, is dropped.
    @State private var longPressed: Int?

    /// Bar height below its top edge (labels sit here); the bead rises `rise` above it.
    static let barHeight = ItMeniscusToken.barHeight
    /// Icons start this far below the bar's edge, so labels start at iconTop + 25 (39 pt): 5 pt clear of the bead's
    /// collar, which ends at ItMeniscusGeometry.depth (34 pt). At 1.3.82 the collar touched the label (Tim's iPhone).
    static let iconTop = ItMeniscusToken.iconTop

    /// The identity currently presented as selected.
    private var on: Int { shown ?? selected }

    /// Whether logical leading-to-trailing slots are physically mirrored.
    private var isRightToLeft: Bool { layoutDirection == .rightToLeft }

    /// Returns the logical array slot for a stable item identity.
    private func logicalSlot(forID id: Int) -> Int? {
        ItMeniscusGeometry.slotIndex(forID: id, in: items.map(\.id))
    }

    /// Returns the physical left-to-right slot for a stable item identity.
    private func physicalSlot(forID id: Int) -> Int? {
        logicalSlot(forID: id).map {
            ItMeniscusGeometry.physicalSlotIndex($0, count: items.count, isRightToLeft: isRightToLeft)
        }
    }

    private var look: ItMeniscusLook {
        ItMeniscusLook(items: isRightToLeft ? Array(items.reversed()) : items, surface: UIColor(style.surface), line: UIColor(style.line),
                       tenant: UIColor(style.tenant), onTenant: UIColor(style.onTenant))
    }

    var body: some View {
        GeometryReader { geo in
            ZStack(alignment: .topLeading) {
                ItMeniscusLayer(kind: .surface, motion: motion, look: look)
                    .allowsHitTesting(false)
                    .accessibilityHidden(true)

                HStack(spacing: 0) {
                    ForEach(items) { item in slot(item) }
                }
                .frame(height: Self.barHeight)
                .offset(y: ItMeniscusGeometry.rise)

                // Above the buttons: the collar covers the icon of a slot the bead is passing over.
                ItMeniscusLayer(kind: .bead, motion: motion, look: look)
                    .allowsHitTesting(false)
                    .accessibilityHidden(true)

                // The bead itself is a target, its raised half included: tapping it is tapping its tab (the slot's
                // button carries the accessibility label). It sits where the bead lands, so a tap there mid-slide
                // never falls through to the page behind. Shape and tap stay on its own frame; the offset is outside.
                Color.clear
                    .frame(width: ItMeniscusGeometry.bead, height: ItMeniscusGeometry.bead)
                    .contentShape(Circle())
                    .onTapGesture { tap(on) }
                    .offset(x: ItMeniscusGeometry.slotCentre(physicalSlot(forID: on) ?? motion.selected,
                                                             count: max(items.count, 1), width: geo.size.width)
                                - ItMeniscusGeometry.bead / 2)
                    .accessibilityHidden(true)
            }
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
        .onAppear {
            if let slot = physicalSlot(forID: selected) { motion.jump(to: slot) }
        }
        // The bead follows the owner: a change from outside slides it there; after our own tap it is there already.
        .onChange(of: selected) { _, new in
            shown = nil
            if let slot = physicalSlot(forID: new) { motion.slide(to: slot, reduceMotion: reduceMotion) }
        }
        .onChange(of: shown) { _, now in
            if now == nil, let slot = physicalSlot(forID: selected) {
                motion.slide(to: slot, reduceMotion: reduceMotion)
            }
        }
        // Another sign-in (admin or crew) changes the slot identities: put the bead in the new row without a slide.
        .onChange(of: items.map(\.id)) { _, _ in
            shown = nil
            if let slot = physicalSlot(forID: selected) { motion.jump(to: slot) }
        }
        // A live direction change mirrors every physical slot without treating it as a user selection.
        .onChange(of: layoutDirection) { _, _ in
            shown = nil
            if let slot = physicalSlot(forID: selected) { motion.jump(to: slot) }
        }
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Tab bar")
    }

    /// A tap on a slot or on the bead. Another tab: the bead leaves now and the screen changes a moment later, so the
    /// slide is already with the system when the app starts drawing the new screen (drawn in the same instant, a heavy
    /// screen held the bead back). The selected tab: its own action, once the bead is on it or nearly there.
    private func tap(_ index: Int) {
        if longPressed == index {
            longPressed = nil
        } else if index == on {
            if motion.isLanding { onReselect?(index) }
        } else {
            // An identity with no slot is ignored before it can leave the highlight on nothing.
            guard let slot = physicalSlot(forID: index) else { return }
            shown = index
            motion.slide(to: slot, reduceMotion: reduceMotion)
            CATransaction.flush()
            DispatchQueue.main.async {
                onSelect(index)
                shown = nil
            }
        }
    }

    private func slot(_ item: ItMeniscusItem) -> some View {
        let active = item.id == on
        let longPress = onLongPress != nil && item.longPressLabel != nil
        let position = physicalSlot(forID: item.id).map { $0 + 1 } ?? 1
        return Button {
            tap(item.id)
        } label: {
            // Every label on one line: the icon has a fixed 22 pt box and a larger one (Kaname's hexagon) grows
            // upwards out of it, and the stack sits at a fixed top instead of being centred — centred, the taller
            // Kaname stack pushed its label 4 pt lower (Tim, 1.3.82).
            VStack(spacing: ItMeniscusToken.iconLabelGap) {
                item.icon.image
                    .renderingMode(.template)
                    .resizable()
                    .scaledToFit()
                    .frame(width: ItMeniscusToken.icon * item.iconScale, height: ItMeniscusToken.icon * item.iconScale)
                    .frame(width: ItMeniscusToken.icon, height: ItMeniscusToken.icon, alignment: .bottom)
                    .foregroundStyle(style.meta)
                    // The selected tab's icon lives in the bead. A slot the bead passes over is covered by the collar.
                    .opacity(active ? 0 : 1)
                    .animation(reduceMotion ? nil : .easeOut(duration: ItMeniscusToken.iconFade), value: active)
                Text(item.title)
                    .font(.system(size: ItMeniscusToken.labelSize, weight: active ? .semibold : .medium))
                    .foregroundStyle(active ? style.tenant : style.meta)
                    .lineLimit(1)
                    .minimumScaleFactor(0.8)
            }
            .padding(.top, Self.iconTop)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .contentShape(Rectangle())
        }
        // When the finger lifts, a long press that was never followed by its tap (the finger slid off) is forgotten;
        // a moment later, so the tap that does follow is still dropped however long the press was held.
        .buttonStyle(ItMeniscusSlotStyle {
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.15) {
                if longPressed == item.id { longPressed = nil }
            }
        })
        .simultaneousGesture(LongPressGesture(minimumDuration: ItMeniscusToken.longPress).onEnded { _ in
            longPressed = item.id
            onLongPress?(item.id)
        }, including: longPress ? .all : .subviews)
        .accessibilityLabel(item.title)
        // "Receipts, selected, tab 4 of 5", as the system's tab bar reads (SwiftUI has no tab-bar trait in this SDK).
        .accessibilityValue("Tab \(position) of \(items.count)")
        .accessibilityHint(active ? item.beadLabel.map { "Tap again: \($0)" } ?? "" : "")
        .accessibilityAddTraits(active ? [.isSelected] : [])
        .accessibilityActions {
            if let label = item.longPressLabel, let onLongPress {
                Button(label) { onLongPress(item.id) }
            }
        }
        // The labels are 11 pt and do not grow with the text size (a bar has no room): at the large accessibility
        // sizes a long touch shows the tab enlarged in the middle of the screen, as the system's own tab bar does.
        .accessibilityShowsLargeContentViewer {
            Label { Text(item.title) } icon: { item.icon.image }
        }
    }
}

/// A slot's press: the label dims under the finger, and `onRelease` runs when the finger lifts or slides off.
private struct ItMeniscusSlotStyle: ButtonStyle {
    let onRelease: () -> Void

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .opacity(configuration.isPressed ? ItMeniscusToken.pressedOpacity : 1)
            .onChange(of: configuration.isPressed) { _, pressed in
                if !pressed { onRelease() }
            }
    }
}
