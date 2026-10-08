//
//  MarkupEditorView.swift
//  MowologyCRM
//
//  "Edit Image" for a batch-camera shot: Draw (pen / eraser, PencilKit), Shape (arrow /
//  circle / rectangle), Text, five colours, Undo, Cancel / Done.
//
//  The markup is FLATTENED into the uploaded JPEG at full resolution, with the shot's
//  EXIF (capture time, GPS) copied across. The server's annotation store
//  (crm/api/job-photo.php save_annotation) is admin-only, session + CSRF, so a phone
//  cannot write it — the burned-in image is what the office and the client see.
//

import SwiftUI
import PencilKit
import UIKit

// MARK: - Model

enum MarkupTool: Equatable {
    case pen, eraser, arrow, circle, rectangle, text

    var isDraw: Bool { self == .pen || self == .eraser }
    var isShape: Bool { self == .arrow || self == .circle || self == .rectangle }
}

enum MarkupPalette {
    static let colors: [UIColor] = [.systemRed, .systemBlue, .systemGreen, .systemYellow, .systemPurple]
    /// Stroke widths as a fraction of the image width, so the flattened full-res file
    /// looks the same as the screen.
    static let penWidth: CGFloat = 0.012
    static let shapeWidth: CGFloat = 0.010
    static let textSize: CGFloat = 0.055
}

/// Shapes and text are stored in unit coordinates (0…1 of the image) so they render the
/// same on screen and in the full-resolution file.
struct MarkupShape {
    let tool: MarkupTool
    let start: CGPoint
    let end: CGPoint
    let color: UIColor
}

struct MarkupText {
    let text: String
    let point: CGPoint
    let color: UIColor
}

struct MarkupState {
    var drawing = PKDrawing()
    var shapes: [MarkupShape] = []
    var texts: [MarkupText] = []
}

// MARK: - Rendering (shared by screen and flatten)

enum MarkupRenderer {

    static func draw(shapes: [MarkupShape], texts: [MarkupText], in ctx: CGContext, size: CGSize) {
        for s in shapes { draw(shape: s, in: ctx, size: size) }
        UIGraphicsPushContext(ctx)
        for t in texts { draw(text: t, size: size) }
        UIGraphicsPopContext()
    }

    static func draw(shape s: MarkupShape, in ctx: CGContext, size: CGSize) {
        let a = CGPoint(x: s.start.x * size.width, y: s.start.y * size.height)
        let b = CGPoint(x: s.end.x * size.width, y: s.end.y * size.height)
        let lw = max(2, MarkupPalette.shapeWidth * size.width)
        ctx.saveGState()
        ctx.setStrokeColor(s.color.cgColor)
        ctx.setLineWidth(lw)
        ctx.setLineCap(.round)
        ctx.setLineJoin(.round)
        // A soft dark halo keeps bright colours readable on grass and snow.
        ctx.setShadow(offset: .zero, blur: lw * 0.8, color: UIColor.black.withAlphaComponent(0.45).cgColor)
        let rect = CGRect(x: min(a.x, b.x), y: min(a.y, b.y), width: abs(b.x - a.x), height: abs(b.y - a.y))
        switch s.tool {
        case .rectangle:
            ctx.stroke(rect)
        case .circle:
            ctx.strokeEllipse(in: rect)
        case .arrow:
            let len = hypot(b.x - a.x, b.y - a.y)
            guard len > 1 else { break }
            let angle = atan2(b.y - a.y, b.x - a.x)
            let head = min(max(lw * 4, len * 0.25), lw * 9)
            let spread: CGFloat = .pi / 7
            ctx.move(to: a)
            ctx.addLine(to: b)
            ctx.move(to: CGPoint(x: b.x - head * cos(angle - spread), y: b.y - head * sin(angle - spread)))
            ctx.addLine(to: b)
            ctx.addLine(to: CGPoint(x: b.x - head * cos(angle + spread), y: b.y - head * sin(angle + spread)))
            ctx.strokePath()
        default:
            break
        }
        ctx.restoreGState()
    }

    /// Call between UIGraphicsPush/PopContext.
    static func draw(text t: MarkupText, size: CGSize) {
        let fontSize = max(12, MarkupPalette.textSize * size.width)
        let shadow = NSShadow()
        shadow.shadowColor = UIColor.black.withAlphaComponent(0.6)
        shadow.shadowBlurRadius = fontSize * 0.15
        let attrs: [NSAttributedString.Key: Any] = [
            .font: UIFont.systemFont(ofSize: fontSize, weight: .bold),
            .foregroundColor: t.color,
            .strokeColor: UIColor.black.withAlphaComponent(0.55),
            .strokeWidth: -2.5,
            .shadow: shadow,
        ]
        let str = NSAttributedString(string: t.text, attributes: attrs)
        let box = str.size()
        let origin = CGPoint(x: t.point.x * size.width - box.width / 2,
                             y: t.point.y * size.height - box.height / 2)
        str.draw(at: origin)
    }

    /// Full-resolution flatten: photo + ink + shapes + text, as JPEG with `properties`.
    static func flatten(base: UIImage, state: MarkupState, canvasSize: CGSize,
                        properties: [String: Any]) -> Data? {
        let size = base.size   // scale 1 → points == pixels
        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        format.opaque = true
        var ink: UIImage?
        if !state.drawing.strokes.isEmpty, canvasSize.width > 0 {
            // Render ink in light mode — PencilKit otherwise adapts colours for dark mode.
            UITraitCollection(userInterfaceStyle: .light).performAsCurrent {
                ink = state.drawing.image(from: CGRect(origin: .zero, size: canvasSize),
                                          scale: size.width / canvasSize.width)
            }
        }
        let out = UIGraphicsImageRenderer(size: size, format: format).image { r in
            base.draw(in: CGRect(origin: .zero, size: size))
            ink?.draw(in: CGRect(origin: .zero, size: size))
            draw(shapes: state.shapes, texts: state.texts, in: r.cgContext, size: size)
        }
        return PhotoMetadata.jpeg(from: out, properties: properties)
    }
}

// MARK: - Editor view

struct MarkupEditorView: View {

    /// The shot's current file (an earlier edit if there is one) — EXIF is carried over.
    let sourceData: Data
    let onDone: (Data) -> Void
    let onCancel: () -> Void

    @State private var display: UIImage?
    @State private var state = MarkupState()
    @State private var history: [MarkupState] = []
    @State private var tool: MarkupTool = .pen
    @State private var colorIndex = 0
    @State private var canvasSize: CGSize = .zero
    @State private var dragShape: MarkupShape?
    @State private var pendingTextPoint: CGPoint?
    @State private var textDraft = ""
    @State private var saving = false
    /// Bumped to push an undo into the PencilKit canvas.
    @State private var drawingRevision = 0

    private var color: UIColor { MarkupPalette.colors[colorIndex] }

    var body: some View {
        VStack(spacing: 0) {
            topBar
            GeometryReader { geo in
                if let img = display {
                    let rect = fitRect(image: img.size, in: geo.size)
                    ZStack {
                        Image(uiImage: img)
                            .resizable()
                            .frame(width: rect.width, height: rect.height)

                        MarkupCanvas(drawing: $state.drawing,
                                     revision: drawingRevision,
                                     tool: tool,
                                     color: color,
                                     penWidth: MarkupPalette.penWidth * rect.width,
                                     onStrokeWillChange: { old in pushHistory(drawing: old) })
                            .frame(width: rect.width, height: rect.height)
                            .allowsHitTesting(tool.isDraw)

                        Canvas { ctx, size in
                            ctx.withCGContext { cg in
                                var shapes = state.shapes
                                if let d = dragShape { shapes.append(d) }
                                MarkupRenderer.draw(shapes: shapes, texts: state.texts, in: cg, size: size)
                            }
                        }
                        .frame(width: rect.width, height: rect.height)
                        .allowsHitTesting(false)

                        if !tool.isDraw {
                            Color.clear
                                .contentShape(Rectangle())
                                .frame(width: rect.width, height: rect.height)
                                .gesture(shapeOrTextGesture(size: rect.size))
                        }
                    }
                    .frame(width: rect.width, height: rect.height)
                    .position(x: geo.size.width / 2, y: geo.size.height / 2)
                    .onAppear { canvasSize = rect.size }
                    .onChange(of: rect.size) { _, s in canvasSize = s }
                } else {
                    ProgressView().tint(.white)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }
            toolBar
        }
        .background(Color.black.ignoresSafeArea())
        .overlay {
            if saving {
                ZStack {
                    Color.black.opacity(0.5).ignoresSafeArea()
                    ProgressView("Saving…").tint(.white).foregroundStyle(.white)
                }
            }
        }
        .alert("Add text", isPresented: Binding(get: { pendingTextPoint != nil },
                                                set: { if !$0 { pendingTextPoint = nil } })) {
            TextField("Text", text: $textDraft)
            Button("Cancel", role: .cancel) { pendingTextPoint = nil; textDraft = "" }
            Button("Add") { commitText() }
        }
        .task {
            // Screen copy capped at 2048 px; the flatten re-reads the full file.
            display = PhotoMetadata.thumbnail(from: sourceData, maxPixel: 2048)
        }
        .statusBarHidden()
    }

    // MARK: Bars

    private var topBar: some View {
        HStack {
            Button("Cancel") { onCancel() }
                .foregroundStyle(.white)
            Spacer()
            Button {
                undo()
            } label: {
                Label("Undo", systemImage: "arrow.uturn.backward")
            }
            .foregroundStyle(history.isEmpty ? .gray : .white)
            .disabled(history.isEmpty)
            Spacer()
            Button {
                save()
            } label: {
                Label("Done", systemImage: "checkmark")
                    .font(.body.weight(.semibold))
                    .padding(.horizontal, 14)
                    .padding(.vertical, 7)
                    .background(Color.MW.green, in: Capsule())
                    .foregroundStyle(.white)
            }
            .disabled(saving || display == nil)
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 10)
    }

    private var toolBar: some View {
        VStack(spacing: 12) {
            // Sub-tools for the active group
            HStack(spacing: 22) {
                if tool.isDraw {
                    toolButton(.pen, "pencil.tip", "Pen")
                    toolButton(.eraser, "eraser", "Eraser")
                } else if tool.isShape {
                    toolButton(.arrow, "arrow.up.right", "Arrow")
                    toolButton(.circle, "circle", "Circle")
                    toolButton(.rectangle, "rectangle", "Rectangle")
                } else {
                    Text("Tap the photo to place text")
                        .font(.caption)
                        .foregroundStyle(.white.opacity(0.8))
                }
            }
            .frame(height: 30)

            // Colours
            HStack(spacing: 16) {
                ForEach(MarkupPalette.colors.indices, id: \.self) { i in
                    Button {
                        colorIndex = i
                        if tool == .eraser { tool = .pen }
                    } label: {
                        Circle()
                            .fill(Color(MarkupPalette.colors[i]))
                            .frame(width: 28, height: 28)
                            .overlay(Circle().stroke(.white, lineWidth: colorIndex == i ? 3 : 0))
                    }
                    .accessibilityLabel(["Red", "Blue", "Green", "Yellow", "Purple"][i])
                }
            }

            // Groups: Draw / Shape / Text
            HStack(spacing: 0) {
                groupButton("Draw", "scribble", active: tool.isDraw) { tool = .pen }
                groupButton("Shape", "square.on.circle", active: tool.isShape) { tool = .arrow }
                groupButton("Text", "textformat", active: tool == .text) { tool = .text }
            }
        }
        .padding(.top, 12)
        .padding(.bottom, 8)
        .background(Color.black)
    }

    private func toolButton(_ t: MarkupTool, _ icon: String, _ label: String) -> some View {
        Button { tool = t } label: {
            Image(systemName: icon)
                .font(.title3)
                .foregroundStyle(tool == t ? Color.MW.lime : .white)
        }
        .accessibilityLabel(label)
    }

    private func groupButton(_ title: String, _ icon: String, active: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 4) {
                Image(systemName: icon).font(.title3)
                Text(title).font(.caption.weight(.semibold))
            }
            .frame(maxWidth: .infinity)
            .foregroundStyle(active ? Color.MW.lime : .white)
        }
    }

    // MARK: Gestures

    private func shapeOrTextGesture(size: CGSize) -> some Gesture {
        DragGesture(minimumDistance: 0)
            .onChanged { v in
                guard tool.isShape else { return }
                dragShape = MarkupShape(tool: tool, start: unit(v.startLocation, size),
                                        end: unit(v.location, size), color: color)
            }
            .onEnded { v in
                if tool == .text {
                    pendingTextPoint = unit(v.location, size)
                    textDraft = ""
                    return
                }
                guard tool.isShape else { return }
                dragShape = nil
                let moved = hypot(v.location.x - v.startLocation.x, v.location.y - v.startLocation.y)
                guard moved > 8 else { return }
                history.append(state)
                state.shapes.append(MarkupShape(tool: tool, start: unit(v.startLocation, size),
                                                end: unit(v.location, size), color: color))
            }
    }

    private func unit(_ p: CGPoint, _ size: CGSize) -> CGPoint {
        CGPoint(x: min(max(p.x / size.width, 0), 1), y: min(max(p.y / size.height, 0), 1))
    }

    private func commitText() {
        let text = textDraft.trimmingCharacters(in: .whitespacesAndNewlines)
        defer { pendingTextPoint = nil; textDraft = "" }
        guard !text.isEmpty, let p = pendingTextPoint else { return }
        history.append(state)
        state.texts.append(MarkupText(text: String(text.prefix(80)), point: p, color: color))
    }

    // MARK: Undo / save

    private func pushHistory(drawing old: PKDrawing) {
        var snapshot = state
        snapshot.drawing = old
        history.append(snapshot)
    }

    private func undo() {
        guard let prev = history.popLast() else { return }
        state = prev
        drawingRevision += 1
    }

    private func save() {
        let hasMarkup = !state.drawing.strokes.isEmpty || !state.shapes.isEmpty || !state.texts.isEmpty
        guard hasMarkup else { onCancel(); return }
        saving = true
        let snapshot = state
        let canvas = canvasSize
        let source = sourceData
        Task.detached(priority: .userInitiated) {
            let result: Data? = PhotoMetadata.uprightImage(from: source).flatMap { base in
                MarkupRenderer.flatten(base: base, state: snapshot, canvasSize: canvas,
                                       properties: PhotoMetadata.properties(of: source))
            }
            await MainActor.run {
                saving = false
                if let result { onDone(result) } else { onCancel() }
            }
        }
    }

    private func fitRect(image: CGSize, in box: CGSize) -> CGRect {
        guard image.width > 0, image.height > 0, box.width > 0, box.height > 0 else { return .zero }
        let s = min(box.width / image.width, box.height / image.height)
        let w = image.width * s, h = image.height * s
        return CGRect(x: (box.width - w) / 2, y: (box.height - h) / 2, width: w, height: h)
    }
}

// MARK: - PencilKit canvas

private struct MarkupCanvas: UIViewRepresentable {
    @Binding var drawing: PKDrawing
    let revision: Int
    let tool: MarkupTool
    let color: UIColor
    let penWidth: CGFloat
    let onStrokeWillChange: (PKDrawing) -> Void

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIView(context: Context) -> PKCanvasView {
        let v = PKCanvasView()
        v.drawingPolicy = .anyInput        // finger drawing — no Apple Pencil on a mower
        v.backgroundColor = .clear
        v.isOpaque = false
        v.isScrollEnabled = false
        v.overrideUserInterfaceStyle = .light   // keep ink colours true in dark mode
        v.delegate = context.coordinator
        v.drawing = drawing
        context.coordinator.lastDrawing = drawing
        return v
    }

    func updateUIView(_ v: PKCanvasView, context: Context) {
        context.coordinator.parent = self
        switch tool {
        case .eraser: v.tool = PKEraserTool(.bitmap)
        default:      v.tool = PKInkingTool(.pen, color: color, width: max(2, penWidth))
        }
        if context.coordinator.revision != revision {
            // Undo from SwiftUI — push the restored drawing without recording it as a change.
            context.coordinator.revision = revision
            context.coordinator.isRestoring = true
            v.drawing = drawing
            context.coordinator.lastDrawing = drawing
            context.coordinator.isRestoring = false
        }
    }

    final class Coordinator: NSObject, PKCanvasViewDelegate {
        var parent: MarkupCanvas
        var lastDrawing = PKDrawing()
        var isRestoring = false
        var revision = 0

        init(_ parent: MarkupCanvas) {
            self.parent = parent
            self.revision = parent.revision
        }

        func canvasViewDrawingDidChange(_ canvasView: PKCanvasView) {
            guard !isRestoring else { return }
            parent.onStrokeWillChange(lastDrawing)
            lastDrawing = canvasView.drawing
            parent.drawing = canvasView.drawing
        }
    }
}
