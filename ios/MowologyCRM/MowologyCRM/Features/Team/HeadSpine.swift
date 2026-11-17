//
//  HeadSpine.swift
//  MowologyCRM
//
//  The head's spine (Tim's sketch, 2026-11-07; docs/plans/2026-11-07-head-spine-ios-plan.md): every head's screen is
//  one centred vertical line. The head's face, name and role line at the top, then the sections as circles down the
//  line, each named on the left with what is waiting on the right ("3 to link"). A tap opens the section in place under
//  its circle; tap again to close. Circles, not squares: the square is the mastery box's (SPEC v1.2).
//
//  Each piece draws its own stretch of the line, so any view can put a SpineNode in a VStack(spacing: 0) and the line
//  runs on unbroken. At accessibility text sizes there is no room for left · centre · right, so the line moves to the
//  leading edge and the name and count stack beside the circle.
//

import SwiftUI

enum SpineMetrics {
    static let ring: CGFloat = 44
    static let compactRing: CGFloat = 28
    static let lineWidth: CGFloat = 1
    static let nodeHalo: CGFloat = 6
    /// The circle's frame (ring + page gap); the line runs down its middle in the large-text layout.
    static let slot: CGFloat = ring + 6
}

/// The line's position across the screen: centred, or down the circles' middle at the leading edge (large text).
struct SpineLine: View {
    let large: Bool
    @Environment(\.itAppStyle) private var appStyle

    var body: some View {
        Rectangle().fill(appStyle.line).frame(width: SpineMetrics.lineWidth)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: large ? .leading : .center)
            .padding(.leading, large ? SpineMetrics.slot / 2 : 0)
            .accessibilityHidden(true)
    }
}

/// The top of the spine: the head's face on the line, the name to its left and the role line to its right, so the line
/// flows straight down from the face (Tim, 2026-11-07: names to the side of the face).
struct SpineHead: View {
    let slug: String
    let name: String
    var state: ItEmployeeState? = nil
    var brainUnits: Int? = nil
    var showsBrainProgress = false
    @Environment(\.it) private var it
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var displayedBrainUnits: Int?
    @State private var triangleReveal = 1.0
    @State private var brainYaw = ItKnowledgeGeometry.restingYaw
    @State private var brainOpacity = 1.0
    @State private var faceOpacity = 0.0

    init(slug: String, name: String, state: ItEmployeeState? = nil, brainUnits: Int? = nil,
         showsBrainProgress: Bool = false) {
        self.slug = slug
        self.name = name
        self.state = state
        self.brainUnits = brainUnits
        self.showsBrainProgress = showsBrainProgress
        _displayedBrainUnits = State(initialValue: brainUnits)
        _faceOpacity = State(initialValue: brainUnits == nil ? 1 : 0)
    }

    var body: some View {
        let large = dynamicTypeSize.isAccessibilitySize
        let role = HeadSpineLogic.role(slug)
        VStack(spacing: 0) {
            if large {
                HStack(spacing: ItSpace.s3) {
                    face
                    VStack(alignment: .leading, spacing: 2) {
                        nameRow
                        roleText(role).multilineTextAlignment(.leading)
                    }
                    Spacer(minLength: 0)
                }
            } else {
                HStack(spacing: ItSpace.s3) {
                    nameRow.frame(maxWidth: .infinity, alignment: .trailing)
                    face
                    roleText(role).multilineTextAlignment(.leading)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
            // The line starts under the face.
            SpineLine(large: large).frame(height: ItSpace.s4)
        }
        .frame(maxWidth: .infinity)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(accessibilityLabel(role: role))
        .accessibilityAddTraits(.isHeader)
        .onChange(of: brainUnits) { oldUnits, newUnits in
            apply(BrainOnSpineLogic.phase(from: oldUnits, to: newUnits, reduceMotion: reduceMotion))
        }
        .onChange(of: reduceMotion) { _, isReduced in
            guard isReduced else { return }
            apply(BrainOnSpineLogic.phase(from: displayedBrainUnits, to: brainUnits, reduceMotion: true))
        }
    }

    private var face: some View {
        ZStack {
            if let units = displayedBrainUnits {
                SpineBrain(units: units, newestTriangleOpacity: triangleReveal, yaw: brainYaw)
                    .opacity(brainOpacity)
            }
            ItPortrait(name: name, url: TeamHead.all.first { $0.slug == slug }?.faceURL, state: state,
                       size: 56, brandRing: slug == "charlie")
                .opacity(faceOpacity)
        }
            .frame(width: 56, height: 56)
            .frame(width: SpineMetrics.slot)
            .accessibilityHidden(true)
    }

    private var nameRow: some View {
        HStack(spacing: 6) {
            Text(name).itText(.title22, weight: 600).foregroundStyle(it.text)   // AI tag removed: the role line says it (2026-11-17 audit)
        }
    }

    private func roleText(_ role: String) -> some View {
        Text(role).itText(.meta13).foregroundStyle(it.meta)
            .fixedSize(horizontal: false, vertical: true)
    }

    private func accessibilityLabel(role: String) -> String {
        let identity = role.isEmpty ? name : "\(name), \(role)"
        guard showsBrainProgress else { return identity }
        return "\(identity), \(BrainOnSpineLogic.accessibilityValue(units: brainUnits))"
    }

    private func apply(_ phase: BrainOnSpineLogic.Phase) {
        switch phase {
        case let .brain(units):
            displayedBrainUnits = units
            triangleReveal = 1
            brainYaw = ItKnowledgeGeometry.restingYaw
            brainOpacity = 1
            faceOpacity = 0
        case let .build(_, units):
            displayedBrainUnits = units
            triangleReveal = 0
            brainOpacity = 1
            faceOpacity = 0
            withAnimation(.easeOut(duration: 0.5)) { triangleReveal = 1 }
        case let .turnToFace(units):
            displayedBrainUnits = units
            withAnimation(.easeInOut(duration: 1)) {
                brainYaw = ItKnowledgeGeometry.restingYaw + .pi
                brainOpacity = 0
                faceOpacity = 1
            }
        case .face:
            displayedBrainUnits = nil
            brainOpacity = 0
            faceOpacity = 1
        }
    }
}

private struct SpineBrain: View, Animatable {
    let units: Int
    var newestTriangleOpacity: Double
    var yaw: Double
    @Environment(\.itAppStyle) private var appStyle

    var animatableData: AnimatablePair<Double, Double> {
        get { AnimatablePair(newestTriangleOpacity, yaw) }
        set {
            newestTriangleOpacity = newValue.first
            yaw = newValue.second
        }
    }

    var body: some View {
        Canvas { context, size in
            let brand = appStyle.palette.color(.brand)
            let faces = ItKnowledgeGeometry.projectedFaces(count: max(1, units), in: size, yaw: yaw)
            for face in faces {
                let isPlaceholder = units == 0
                let opacity = isPlaceholder ? 0.18 : (face.slot == units - 1 ? newestTriangleOpacity : 1)
                var path = Path()
                for (index, point) in face.points.enumerated() {
                    index == 0 ? path.move(to: point) : path.addLine(to: point)
                }
                path.closeSubpath()
                if isPlaceholder {
                    context.stroke(path, with: .color(brand.opacity(opacity)), lineWidth: SpineMetrics.lineWidth)
                } else {
                    context.fill(path, with: .color(brand.opacity(face.front ? opacity : opacity * 0.28)))
                }
            }
        }
        .overlay(Circle().stroke(appStyle.palette.color(.brand), lineWidth: SpineMetrics.lineWidth))
        .accessibilityHidden(true)
    }
}

/// One section on the line. Closed: the circle shows a ring and a brand glyph. Open: filled brand with a white glyph
/// (the section icon on Penny's cards) and the content hangs under it in a thin frame, opaque so the line passes behind.
struct SpineNode<Content: View>: View {
    let slug: String
    let id: String
    let title: String
    let symbol: String
    var waiting: String? = nil
    var defaultOpen = false
    /// Off for content that draws its own frames on the line (Penny's cards); it still hides the line behind it.
    var framed = true
    @ViewBuilder var content: () -> Content

    @AppStorage private var stored: Int
    @Environment(\.it) private var it
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    init(slug: String, id: String, title: String, symbol: String, waiting: String? = nil, defaultOpen: Bool = false,
         framed: Bool = true, @ViewBuilder content: @escaping () -> Content) {
        self.slug = slug; self.id = id; self.title = title; self.symbol = symbol
        self.waiting = waiting; self.defaultOpen = defaultOpen; self.framed = framed; self.content = content
        _stored = AppStorage(wrappedValue: 0, HeadSpineLogic.key(slug, id))
    }

    /// Opens a section from outside (a push that lands in it), as if tapped.
    static func open(slug: String, id: String) {
        UserDefaults.standard.set(HeadSpineLogic.stored(open: true), forKey: HeadSpineLogic.key(slug, id))
    }

    private var isOpen: Bool { HeadSpineLogic.isOpen(stored: stored, defaultOpen: defaultOpen) }

    var body: some View {
        let large = dynamicTypeSize.isAccessibilitySize
        VStack(spacing: 0) {
            Button {
                withAnimation(reduceMotion ? nil : .easeOut(duration: 0.18)) {
                    stored = HeadSpineLogic.stored(open: !isOpen)
                }
            } label: {
                row(large: large)
                    .frame(minHeight: ItSize.targetPhone + 8)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(waiting.map { "\(title), \($0)" } ?? title)
            .accessibilityValue(isOpen ? "Open" : "Closed")
            .accessibilityHint(isOpen ? "Closes this section" : "Opens this section here")
            .accessibilityAddTraits([.isHeader, .isButton])
            if isOpen {
                hung(content())
                    .padding(.top, ItSpace.s2)
                    .transition(.opacity)
            }
        }
        .padding(.vertical, ItSpace.s2)
        .background(SpineLine(large: large))
    }

    @ViewBuilder private func row(large: Bool) -> some View {
        if large {
            HStack(spacing: ItSpace.s3) {
                circle
                VStack(alignment: .leading, spacing: 2) {
                    Text(title).itText(.body16, weight: 600).foregroundStyle(it.text)
                        .fixedSize(horizontal: false, vertical: true)
                    if let waiting {
                        Text(waiting).itText(.meta13).foregroundStyle(it.meta)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
                Spacer(minLength: 0)
            }
        } else {
            HStack(spacing: ItSpace.s3) {
                Text(title).itText(.body16, weight: 600).foregroundStyle(it.text)
                    .multilineTextAlignment(.trailing)
                    .frame(maxWidth: .infinity, alignment: .trailing)
                circle
                Text(waiting ?? "").itText(.meta13).foregroundStyle(it.meta)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
    }

    private var circle: some View {
        let brand = appStyle.isEnabled ? appStyle.palette.color(.brand) : Color.indigo
        let ring = SpineMetrics.ring
        return ZStack {
            Circle().fill(appStyle.page).frame(width: ring + SpineMetrics.nodeHalo, height: ring + SpineMetrics.nodeHalo)
            Circle().stroke(isOpen ? appStyle.line : brand, lineWidth: isOpen ? 1 : 1.5).frame(width: ring, height: ring)
            if isOpen { Circle().fill(brand).frame(width: ring - 10, height: ring - 10) }
            Image(systemName: symbol).font(.system(size: ring * 0.36, weight: .semibold))
                .foregroundStyle(isOpen ? Color.white : brand)
        }
        .frame(width: SpineMetrics.slot, height: SpineMetrics.slot)
        .accessibilityHidden(true)
    }

    @ViewBuilder private func hung(_ inner: Content) -> some View {
        if framed {
            inner
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(ItSpace.s4)
                .background(appStyle.page, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
                .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.line, lineWidth: 1)
                    .allowsHitTesting(false))
        } else {
            inner
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(appStyle.page)
        }
    }
}

/// The first node on every head. It opens in place once, then remembers its compact circled-i form per head.
struct SpineInfoNode<Content: View>: View {
    let slug: String
    @ViewBuilder let content: () -> Content
    @AppStorage private var read: Bool
    @State private var reopened = false
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    init(slug: String, @ViewBuilder content: @escaping () -> Content) {
        self.slug = slug
        self.content = content
        _read = AppStorage(wrappedValue: false, "spine.infoRead.\(slug)")
    }

    private var isOpen: Bool { !read || reopened }

    var body: some View {
        let large = dynamicTypeSize.isAccessibilitySize
        VStack(spacing: 0) {
            Button {
                withAnimation(reduceMotion ? nil : .easeOut(duration: 0.18)) {
                    if isOpen { read = true; reopened = false } else { reopened = true }
                }
            } label: {
                Image(systemName: "info.circle")
                    .font(.system(size: SpineMetrics.compactRing * 0.62, weight: .semibold))
                    .foregroundStyle(appStyle.palette.color(.brand))
                    .frame(width: SpineMetrics.compactRing, height: SpineMetrics.compactRing)
                    .background(appStyle.page, in: Circle())
                    .frame(width: SpineMetrics.slot, height: SpineMetrics.slot)
                    .frame(maxWidth: .infinity, alignment: large ? .leading : .center)
            }
            .buttonStyle(.plain)
            .zIndex(1)   // above the card that hangs under it: the card's solid fill must not cover the icon
            .accessibilityLabel("How this works")
            .accessibilityValue(isOpen ? "Open" : "Closed")
            if isOpen {
                content()
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.horizontal, ItSpace.s4)
                    .padding(.bottom, ItSpace.s4)
                    .padding(.top, SpineMetrics.slot / 2 + ItSpace.s2)
                    .background(appStyle.page, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
                    .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.line, lineWidth: SpineMetrics.lineWidth))
                    .padding(.top, -SpineMetrics.slot / 2)
            }
        }
        .padding(.vertical, ItSpace.s2)
        .background(SpineLine(large: large))
        .background {
            GeometryReader { proxy in
                Color.clear.onChange(of: proxy.frame(in: .global).maxY) { _, maxY in
                    // Reading is complete when the open card has passed above the viewport. Reopening remains explicit.
                    if isOpen, maxY < 0 { read = true; reopened = false }
                }
            }
        }
    }
}

/// The end of the spine: the line carries on down past the page toward the Kaname button, as Penny's cards do.
struct SpineTail: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        Color.clear.frame(height: ItSpace.s6)
            .background(alignment: .top) {
                SpineLine(large: dynamicTypeSize.isAccessibilitySize).frame(height: 1200)
                    .frame(height: ItSpace.s6, alignment: .top)
            }
            .accessibilityHidden(true)
    }
}

/// One step of a checklist on the same centre line as SpineNode (onboarding): the 44 pt circle ON the line, the title to
/// its left, a short detail to its right. The number inside turns into a white tick on a filled brand circle when done.
/// The open step's card hangs below the circle. SpineNode itself is untouched, so the Team screens look as before.
struct SpineStepNode<Content: View>: View {
    let number: String
    let title: String
    var detail: String = ""
    var detailIsChip = false
    var detailStatus: String? = nil
    var contentHorizontalPadding = ItSpace.s4
    var showsContent = true
    /// The current step's label is drawn elsewhere (onboarding types it into a pill inside the card): the node keeps
    /// only its number circle, so the same words are never on the page twice. VoiceOver still reads the title.
    var hidesCurrentTitle = false
    let done: Bool
    let current: Bool
    @ViewBuilder var content: () -> Content
    @Environment(\.it) private var it
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        let large = dynamicTypeSize.isAccessibilitySize
        VStack(spacing: 0) {
            Group {
                if large {
                    HStack(spacing: ItSpace.s3) {
                        circle
                        VStack(alignment: .leading, spacing: 2) {
                            Text(title).itText(.body16, weight: 600).foregroundStyle(it.text).fixedSize(horizontal: false, vertical: true)
                            if !detail.isEmpty { detailView }
                        }
                        Spacer(minLength: 0)
                    }
                } else {
                    HStack(spacing: ItSpace.s3) {
                        Group {
                            if current && hidesCurrentTitle {
                                Color.clear.frame(height: 1)
                            } else {
                                Text(title).itText(.body16, weight: 600).foregroundStyle(current || done ? it.text : it.meta)
                                    .multilineTextAlignment(.trailing).padding(.horizontal, current ? 6 : 0)
                                    .background(current ? appStyle.page : Color.clear)
                            }
                        }
                        .frame(maxWidth: .infinity, alignment: .trailing)
                        circle
                        detailView.frame(maxWidth: .infinity, alignment: .leading)
                    }
                }
            }
            .frame(minHeight: ItSize.targetPhone + 8)
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(
                "Step \(number), \(title)\(detail.isEmpty ? "" : ", \(detail)")\(done ? ", done" : "")"
            )
            .zIndex(1)
            if current && showsContent {
                // The card hangs from the circle: its top edge runs through the circle's middle, the line enters its top
                // and leaves its bottom, and the opaque fill keeps the line off the text.
                content()
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.horizontal, contentHorizontalPadding)
                    .padding(.bottom, ItSpace.s4)
                    .padding(.top, SpineMetrics.slot / 2 + ItSpace.s3)
                    .background(appStyle.page, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
                    .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.line, lineWidth: 1).allowsHitTesting(false))
                    .padding(.top, -SpineMetrics.slot / 2)
            }
        }
        .padding(.vertical, ItSpace.s2)
        .background(SpineLine(large: large))
    }

    private var detailView: some View {
        let legacyChipColor = appStyle.palette.color(.brand)
        let chipColor = detailStatus.map {
            appStyle.statusColor($0, legacy: legacyChipColor)
        } ?? legacyChipColor
        return Text(detail)
            .itText(.meta13, weight: detailIsChip ? 600 : 400)
            .foregroundStyle(detailIsChip ? chipColor : it.meta)
            .padding(.horizontal, detailIsChip ? ItSpace.s2 : 0)
            .padding(.vertical, detailIsChip ? ItSpace.s1 : 0)
            .background(detailIsChip ? chipColor.opacity(0.12) : Color.clear, in: Capsule())
            .fixedSize(horizontal: false, vertical: true)
    }

    private var circle: some View {
        let brand = appStyle.isEnabled ? appStyle.palette.color(.brand) : Color.indigo
        let ring = SpineMetrics.ring
        return ZStack {
            Circle().fill(appStyle.page).frame(width: ring + 6, height: ring + 6)
            Circle().stroke(done ? brand : (current ? brand : appStyle.line), lineWidth: current ? 2 : 1).frame(width: ring, height: ring)
            if done {
                Circle().fill(brand).frame(width: ring - 6, height: ring - 6)
                Image(systemName: "checkmark").font(.system(size: ring * 0.36, weight: .bold)).foregroundStyle(Color.white)
            } else {
                Text(number).itText(.body14, weight: 600).foregroundStyle(it.text)
            }
        }
        .frame(width: SpineMetrics.slot, height: SpineMetrics.slot)
        .accessibilityHidden(true)
    }
}
