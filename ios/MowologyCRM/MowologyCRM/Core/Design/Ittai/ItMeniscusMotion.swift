//
//  ItMeniscusMotion.swift
//  MowologyCRM
//
//  The meniscus bar's moving parts, drawn as Core Animation layers (Tim, 2026-11-08: "ensure smoothness of movement of
//  the bar is AAA, perhaps page load should happen after the bead lands?").
//  Until 1.3.128 SwiftUI worked the slide out frame by frame in the app: the notch's path, the bead's glyphs. The new
//  tab's screen is drawn by the app too, in the same frames, so the bead stuttered whenever the screen was heavy.
//  Now a tap samples the whole slide once (ItMeniscusGeometry.track) and hands it to the system as keyframes: the
//  bead leaves at once and keeps moving while the app draws the screen. Two layers share the one clock, because the
//  tab buttons sit between them: the bar's surface with its notch below, the collar and bead above.
//

import SwiftUI
import UIKit

/// What the layers are drawn with.
struct ItMeniscusLook: Equatable {
    let items: [ItMeniscusItem]
    let surface: UIColor
    let line: UIColor
    let tenant: UIColor
    let onTenant: UIColor
}

/// The slide's clock: where the bead is, where it is going, and the layers that follow it. Main thread only.
final class ItMeniscusMotion {
    /// One slide, sampled: the bead's position every `ItMeniscusGeometry.trackStep` from `began`.
    struct Run {
        let positions: [CGFloat]
        let began: CFTimeInterval
        let selected: Int
        let travel: CGFloat

        var duration: CFTimeInterval { Double(positions.count - 1) * ItMeniscusGeometry.trackStep }
    }

    /// The slot the bead rests on, or is sliding to.
    private(set) var selected = 0
    private var spring = ItMeniscusGeometry.Spring(from: 0, to: 0, velocity: 0)
    private var began: CFTimeInterval = 0
    private var run: Run?
    private let layers = NSHashTable<ItMeniscusLayerView>.weakObjects()

    /// The slide in progress, if the bead has not landed yet.
    var activeRun: Run? {
        guard let run, CACurrentMediaTime() < run.began + run.duration else { return nil }
        return run
    }

    /// Where the bead is right now, in slots.
    var position: CGFloat { spring.state(at: CACurrentMediaTime() - began).position }

    /// The bead is on its tab, or close enough that a tap on it is meant for that tab.
    var isLanding: Bool { abs(position - CGFloat(selected)) < 0.25 }

    func attach(_ layer: ItMeniscusLayerView) {
        layers.add(layer)
        layer.motion = self
        layer.sync()
    }

    /// Put the bead on a slot with no slide. `dissolve`: a short cross-fade instead (Reduce Motion).
    func jump(to index: Int, dissolve: Bool = false) {
        spring = ItMeniscusGeometry.Spring(from: CGFloat(index), to: CGFloat(index), velocity: 0)
        began = CACurrentMediaTime()
        run = nil
        selected = index
        layers.allObjects.forEach { $0.sync(dissolve: dissolve) }
    }

    /// Slide the bead to a slot from wherever it is, at whatever speed it has (a second tap mid-slide).
    func slide(to index: Int, reduceMotion: Bool) {
        guard index != selected else { return }
        guard !reduceMotion else { return jump(to: index, dissolve: true) }
        let now = CACurrentMediaTime()
        let state = spring.state(at: now - began)
        spring = ItMeniscusGeometry.Spring(from: state.position, to: CGFloat(index), velocity: state.velocity)
        began = now
        selected = index
        run = Run(positions: ItMeniscusGeometry.track(spring), began: now, selected: index,
                  travel: abs(CGFloat(index) - state.position))
        layers.allObjects.forEach { $0.sync() }
    }
}

/// One of the bar's two moving layers. Everything that moves hangs off `carriage`, whose x is the bead's centre.
final class ItMeniscusLayerView: UIView {
    enum Kind { case surface, bead }

    let kind: Kind
    weak var motion: ItMeniscusMotion?
    var look: ItMeniscusLook? {
        didSet { if look != oldValue { build(); sync() } }
    }

    private let carriage = CALayer()
    private let fill = CAShapeLayer()
    private let edge = CAShapeLayer()
    private let collar = CAShapeLayer()
    private let bead = CALayer()
    /// Per slot: the tab's icon, and the action it turns into when selected (Receipts: the camera).
    private var icons: [CALayer] = []
    private var actions: [CALayer?] = []
    /// The icon that rolls into its action (Add: + into ×), always in the bead, hidden until needed.
    private var roll: CALayer?
    private var builtWidth: CGFloat = -1

    init(kind: Kind) {
        self.kind = kind
        super.init(frame: .zero)
        isUserInteractionEnabled = false
        backgroundColor = .clear
        // The surface is twice the bar's width, so the notch can sit anywhere along it; only the bar's own width shows.
        clipsToBounds = kind == .surface
        layer.addSublayer(carriage)
        switch kind {
        case .surface:
            edge.fillColor = nil
            edge.lineWidth = 1
            carriage.addSublayer(fill)
            carriage.addSublayer(edge)
        case .bead:
            let g = ItMeniscusGeometry.self
            let centre = CGPoint(x: 0, y: g.rise + g.centreBelowEdge)
            // The collar: the gap between bead and cup in the bar's own colour, outlined like the bar's edge. See-through,
            // it let a navy button behind melt into the bead (Tim's iPhone, Schedule, 2026-11-04).
            collar.path = UIBezierPath(arcCenter: centre, radius: g.bowl, startAngle: 0, endAngle: 2 * .pi, clockwise: true).cgPath
            collar.lineWidth = 1
            bead.bounds = CGRect(x: 0, y: 0, width: g.bead, height: g.bead)
            bead.position = centre
            bead.cornerRadius = g.bead / 2
            carriage.addSublayer(collar)
            carriage.addSublayer(bead)
        }
    }

    required init?(coder: NSCoder) { fatalError("init(coder:) is not used") }

    override func layoutSubviews() {
        super.layoutSubviews()
        guard bounds.width != builtWidth else { return }
        builtWidth = bounds.width
        build()
        sync()
    }

    /// The classic look's colours follow the system's light and dark; a layer's colour is fixed when it is set.
    override func traitCollectionDidChange(_ previous: UITraitCollection?) {
        super.traitCollectionDidChange(previous)
        guard traitCollection.hasDifferentColorAppearance(comparedTo: previous) else { return }
        build()
        sync()
    }

    /// Colours, the surface's shape for this width, and the bead's glyphs. Nothing here moves.
    private func build() {
        guard let look else { return }
        CATransaction.begin()
        CATransaction.setDisableActions(true)
        defer { CATransaction.commit() }
        traitCollection.performAsCurrent { draw(look) }
    }

    private func draw(_ look: ItMeniscusLook) {
        switch kind {
        case .surface:
            let g = ItMeniscusGeometry.self
            let reach = max(bounds.width, 1), edgeY = g.rise
            let path = UIBezierPath()
            path.move(to: CGPoint(x: -reach, y: edgeY))
            path.addLine(to: CGPoint(x: -g.halfWidth, y: edgeY))
            for s in g.notch(cx: 0) {
                path.addCurve(to: CGPoint(x: s.end.x, y: edgeY + s.end.y),
                              controlPoint1: CGPoint(x: s.c1.x, y: edgeY + s.c1.y),
                              controlPoint2: CGPoint(x: s.c2.x, y: edgeY + s.c2.y))
            }
            path.addLine(to: CGPoint(x: reach, y: edgeY))
            edge.path = path.cgPath
            edge.strokeColor = look.line.cgColor
            path.addLine(to: CGPoint(x: reach, y: bounds.height))
            path.addLine(to: CGPoint(x: -reach, y: bounds.height))
            path.close()
            fill.path = path.cgPath
            fill.fillColor = look.surface.cgColor
        case .bead:
            collar.fillColor = look.surface.cgColor
            collar.strokeColor = look.line.cgColor
            bead.backgroundColor = look.tenant.cgColor
            bead.sublayers?.forEach { $0.removeFromSuperlayer() }
            icons = look.items.map { glyph($0.icon, scale: $0.iconScale, colour: look.onTenant) }
            actions = look.items.map { item in item.beadIcon.map { glyph($0, scale: item.iconScale, colour: look.onTenant) } }
            roll = look.items.first { $0.beadTurn != nil }.map { glyph($0.icon, scale: $0.iconScale, colour: look.onTenant) }
        }
    }

    /// An icon as a layer in the bead's centre, drawn once by SwiftUI exactly as the bead's glyphs were drawn before
    /// (a symbol drawn by UIKit came out a fifth smaller in the same box).
    private func glyph(_ icon: ItMeniscusIcon, scale: CGFloat, colour: UIColor) -> CALayer {
        let side = 24 * scale
        let renderer = ImageRenderer(content: icon.image
            .renderingMode(.template)
            .resizable()
            .scaledToFit()
            .frame(width: side, height: side)
            .foregroundStyle(Color(colour)))
        renderer.scale = max(traitCollection.displayScale, 1)
        let glyph = CALayer()
        glyph.contents = renderer.cgImage
        glyph.contentsScale = renderer.scale
        glyph.bounds = CGRect(x: 0, y: 0, width: side, height: side)
        glyph.position = CGPoint(x: bead.bounds.midX, y: bead.bounds.midY)
        bead.addSublayer(glyph)
        return glyph
    }

    /// Show the bead where the clock says: at rest on its slot, or part-way through the slide in progress. Safe to call
    /// again mid-slide (a rebuilt glyph picks the slide up where it is, because the keyframes are timed from its start).
    func sync(dissolve: Bool = false) {
        guard let motion, let look, bounds.width > 0 else { return }
        let g = ItMeniscusGeometry.self
        let count = max(look.items.count, 1), width = bounds.width
        let run = motion.activeRun
        if dissolve {
            let fade = CATransition()
            fade.type = .fade
            fade.duration = 0.18
            layer.add(fade, forKey: "meniscus.dissolve")
        }
        CATransaction.begin()
        CATransaction.setDisableActions(true)
        defer { CATransaction.commit() }

        carriage.position = CGPoint(x: g.beadX(CGFloat(motion.selected), count: count, width: width), y: 0)
        animate(carriage, "position.x", run?.positions.map { g.beadX($0, count: count, width: width) }, run)
        guard kind == .bead else { return }

        let actionSlots = Set(look.items.indices.filter { look.items[$0].beadIcon != nil })
        let rollAt = look.items.firstIndex { $0.beadTurn != nil }
        let turn = rollAt.flatMap { look.items[$0].beadTurn } ?? 0
        func face(_ position: CGFloat, travel: CGFloat) -> ItMeniscusGeometry.Face {
            g.face(position: position, selected: motion.selected, travel: travel, count: count,
                   actions: actionSlots, rollAt: rollAt, turn: turn)
        }
        let rest = face(CGFloat(motion.selected), travel: 0)
        let faces = run.map { run in run.positions.map { face($0, travel: run.travel) } }

        // Landing on the selected tab, its icon shrinks away as its action grows in (Tim, 2026-11-06: "Receipts icon to
        // camera jump"); a rolling icon takes the bead over from either.
        func iconOpacity(_ f: ItMeniscusGeometry.Face, _ i: Int) -> CGFloat { f.under == i ? CGFloat((1 - f.settle) * (1 - f.roll)) : 0 }
        func iconScale(_ f: ItMeniscusGeometry.Face, _ i: Int) -> CGFloat { f.under == i ? CGFloat(1 - 0.35 * f.settle) : 1 }
        func actionOpacity(_ f: ItMeniscusGeometry.Face, _ i: Int) -> CGFloat { f.under == i ? CGFloat(f.settle * (1 - f.roll)) : 0 }
        func actionScale(_ f: ItMeniscusGeometry.Face, _ i: Int) -> CGFloat { f.under == i ? CGFloat(0.65 + 0.35 * f.settle) : 1 }

        for (i, icon) in icons.enumerated() {
            set(icon, "opacity", iconOpacity(rest, i), faces?.map { iconOpacity($0, i) }, run)
            set(icon, "transform.scale", iconScale(rest, i), faces?.map { iconScale($0, i) }, run)
            guard let action = actions[i] else { continue }
            set(action, "opacity", actionOpacity(rest, i), faces?.map { actionOpacity($0, i) }, run)
            set(action, "transform.scale", actionScale(rest, i), faces?.map { actionScale($0, i) }, run)
        }
        if let roll {
            set(roll, "opacity", CGFloat(rest.roll), faces?.map { CGFloat($0.roll) }, run)
            set(roll, "transform.rotation.z", CGFloat(rest.angle) * .pi / 180, faces?.map { CGFloat($0.angle) * .pi / 180 }, run)
        }
    }

    private func set(_ layer: CALayer, _ keyPath: String, _ rest: CGFloat, _ values: [CGFloat]?, _ run: ItMeniscusMotion.Run?) {
        layer.setValue(rest, forKeyPath: keyPath)
        animate(layer, keyPath, values, run)
    }

    /// Play `values` (one per step of the run) on the layer, from the run's own start time. The layer's own value is
    /// where it rests, so when the keyframes end nothing moves.
    private func animate(_ layer: CALayer, _ keyPath: String, _ values: [CGFloat]?, _ run: ItMeniscusMotion.Run?) {
        let key = "meniscus." + keyPath
        layer.removeAnimation(forKey: key)
        guard let values, let run, let first = values.first, values.contains(where: { $0 != first }) else { return }
        let frames = CAKeyframeAnimation(keyPath: keyPath)
        frames.values = values
        frames.calculationMode = .linear
        frames.duration = run.duration
        frames.beginTime = layer.convertTime(run.began, from: nil)
        frames.fillMode = .backwards
        // 120 Hz on ProMotion iPhones (needs CADisableMinimumFrameDurationOnPhone in Info.plist).
        frames.preferredFrameRateRange = CAFrameRateRange(minimum: 80, maximum: 120, preferred: 120)
        layer.add(frames, forKey: key)
    }
}

/// One of the bar's moving layers in SwiftUI. It takes no touches: the bar's buttons do.
struct ItMeniscusLayer: UIViewRepresentable {
    let kind: ItMeniscusLayerView.Kind
    let motion: ItMeniscusMotion
    let look: ItMeniscusLook

    func makeUIView(context: Context) -> ItMeniscusLayerView {
        let view = ItMeniscusLayerView(kind: kind)
        view.look = look
        motion.attach(view)
        return view
    }

    func updateUIView(_ view: ItMeniscusLayerView, context: Context) {
        view.look = look
    }
}
