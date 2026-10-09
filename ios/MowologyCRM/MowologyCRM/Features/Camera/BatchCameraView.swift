//
//  BatchCameraView.swift
//  MowologyCRM
//
//  Native in-app batch camera — the iOS counterpart of public/crm/js/batch-camera.js,
//  laid out like Jobber's: X / flash / flip on top, .5 · 1× · 5 lens chips, PHOTO / VIDEO,
//  a thumbnail stack with a "2/10" counter, an "Edit Image" pill after each shot, and
//  "Done" to hand the whole batch back. Each shot carries the visit photo category it
//  was taken under (Before / During / After / More).
//
//  The caller uploads on Done (JobPhotoViewModel.handleBatch) through the existing
//  job-photo endpoint + JobPhotoQueue, so offline behaviour is unchanged.
//

import SwiftUI
import AVFoundation
import UIKit

// MARK: - Shot

struct BatchShot: Identifiable {
    enum Kind { case photo, video }

    let id = UUID()
    let kind: Kind
    var category: BatchShotCategory
    /// Photo: the camera's JPEG file (EXIF + GPS). Video: empty.
    let fileData: Data
    /// Photo with markup flattened in, when edited.
    var editedData: Data?
    /// Video: the recorded clip in tmp/.
    let videoURL: URL?
    var preview: UIImage

    /// What gets uploaded.
    var uploadData: Data { editedData ?? fileData }
}

// MARK: - View

struct BatchCameraView: View {

    let initialCategory: BatchShotCategory
    /// After is pickable when the job has started or already has a before.
    let afterUnlocked: Bool
    let allowsVideo: Bool
    let onDone: ([BatchShot]) -> Void
    let onCancel: () -> Void

    @StateObject private var camera = CameraController()
    @State private var shots: [BatchShot] = []
    @State private var category: BatchShotCategory
    @State private var videoMode = false
    @State private var editing: BatchShot?
    @State private var showReview = false
    @State private var confirmDiscard = false
    @State private var showEditPill = false
    @State private var pinchStart: CGFloat?
    @State private var blink = false
    @State private var pendingShots = 0

    init(initialCategory: BatchShotCategory, afterUnlocked: Bool, allowsVideo: Bool = false,
         onDone: @escaping ([BatchShot]) -> Void, onCancel: @escaping () -> Void) {
        self.initialCategory = initialCategory
        self.afterUnlocked = afterUnlocked
        self.allowsVideo = allowsVideo
        self.onDone = onDone
        self.onCancel = onCancel
        _category = State(initialValue: initialCategory)
    }

    private var canShoot: Bool {
        BatchCameraLimits.canShoot(count: shots.count + pendingShots)
    }

    private var categories: [BatchShotCategory] {
        BatchShotCategory.available(afterUnlocked: afterUnlocked, shotsSoFar: shots.map(\.category))
    }

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            switch camera.access {
            case .denied, .unavailable:
                deniedView
            default:
                cameraUI
            }
        }
        .statusBarHidden()
        .task { await camera.start(videoMode: false) }
        .onDisappear { camera.stop() }
        .onChange(of: camera.shutterBlink) { _, _ in
            blink = true
            withAnimation(.easeOut(duration: 0.25)) { blink = false }
        }
        .fullScreenCover(item: $editing) { shot in
            MarkupEditorView(sourceData: shot.uploadData,
                             onDone: { data in applyEdit(shotId: shot.id, data: data) },
                             onCancel: { editing = nil })
        }
        .sheet(isPresented: $showReview) {
            BatchReviewSheet(shots: $shots,
                             categories: categories,
                             onEdit: { shot in
                                 showReview = false
                                 DispatchQueue.main.asyncAfter(deadline: .now() + 0.35) { editing = shot }
                             })
        }
        .confirmationDialog("Discard \(shots.count) \(shots.count == 1 ? "photo" : "photos")?",
                            isPresented: $confirmDiscard, titleVisibility: .visible) {
            Button("Save \(shots.count == 1 ? "it" : "them") and close") { finish() }
            Button("Discard", role: .destructive) { onCancel() }
            Button("Keep shooting", role: .cancel) {}
        }
    }

    // MARK: Camera UI

    private var cameraUI: some View {
        VStack(spacing: 0) {
            topBar

            ZStack {
                CameraPreview(camera: camera)
                    .gesture(pinch)
                    .overlay(Color.black.opacity(blink ? 0.7 : 0).allowsHitTesting(false))

                VStack {
                    if camera.isRecording {
                        recordingTimer.padding(.top, 10)
                    }
                    Spacer()
                    if showEditPill, !camera.isRecording, let last = shots.last, last.kind == .photo {
                        editPill(last).padding(.bottom, 10)
                    }
                    lensChips.padding(.bottom, 10)
                }

                if let err = camera.errorText {
                    Text(err)
                        .font(.footnote.weight(.semibold))
                        .foregroundStyle(.white)
                        .padding(10)
                        .background(.black.opacity(0.7), in: RoundedRectangle(cornerRadius: 10))
                }
            }

            bottomControls
        }
    }

    private var topBar: some View {
        HStack {
            Button {
                if shots.isEmpty { onCancel() } else { confirmDiscard = true }
            } label: {
                Image(systemName: "xmark").font(.title2.weight(.semibold))
            }
            .accessibilityLabel("Close camera")
            .disabled(camera.isRecording)

            Spacer()

            if camera.hasFlash {
                Button {
                    camera.flash = camera.flash.next
                } label: {
                    Image(systemName: camera.flash.systemImage)
                        .font(.title3)
                        .foregroundStyle(camera.flash == .off ? .white : .yellow)
                }
                .accessibilityLabel("Flash \(camera.flash.rawValue)")
                .disabled(camera.isRecording)
            }

            Spacer()

            Button {
                camera.flip()
            } label: {
                Image(systemName: "arrow.triangle.2.circlepath.camera").font(.title2)
            }
            .accessibilityLabel("Switch camera")
            .disabled(camera.isRecording)
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 20)
        .padding(.vertical, 12)
    }

    private var recordingTimer: some View {
        HStack(spacing: 6) {
            Circle().fill(.white).frame(width: 8, height: 8)
            Text(BatchCameraLimits.timerText(seconds: camera.recordingSeconds))
                .font(.system(.subheadline, design: .monospaced).weight(.semibold))
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 10)
        .padding(.vertical, 5)
        .background(Color.red, in: RoundedRectangle(cornerRadius: 6))
    }

    private var lensChips: some View {
        let active = LensChip.activeIndex(in: camera.chips, zoomFactor: camera.zoomFactor)
        return HStack(spacing: 8) {
            ForEach(Array(camera.chips.enumerated()), id: \.offset) { i, chip in
                let isActive = i == active
                let live = camera.zoomFactor / max(camera.wideZoomFactor, 0.01)
                Button {
                    camera.setZoom(chip.zoomFactor, ramp: true)
                } label: {
                    Text(isActive ? LensChip.format(live) + "×" : chip.label)
                        .font(.caption.weight(.bold))
                        .foregroundStyle(isActive ? .yellow : .white)
                        .frame(width: isActive ? 40 : 32, height: isActive ? 40 : 32)
                        .background(.black.opacity(0.5), in: Circle())
                }
                .accessibilityLabel("Zoom \(chip.label)")
            }
        }
        .padding(4)
        .background(.black.opacity(0.25), in: Capsule())
        .opacity(camera.chips.count > 1 ? 1 : 0)
    }

    private func editPill(_ shot: BatchShot) -> some View {
        Button {
            showEditPill = false
            editing = shot
        } label: {
            Label("Edit Image", systemImage: "pencil")
                .font(.subheadline.weight(.semibold))
                .padding(.horizontal, 14)
                .padding(.vertical, 8)
                .background(.white, in: Capsule())
                .foregroundStyle(.black)
                .labelStyle(TrailingIconLabelStyle())
        }
    }

    private var bottomControls: some View {
        VStack(spacing: 14) {
            // Category for the next shot
            HStack(spacing: 6) {
                ForEach(categories) { c in
                    Button {
                        category = c
                    } label: {
                        Text(c.label)
                            .font(.caption.weight(.bold))
                            .padding(.horizontal, 12)
                            .padding(.vertical, 6)
                            .background(category == c ? Color.MW.green : Color.white.opacity(0.15),
                                        in: Capsule())
                            .foregroundStyle(.white)
                    }
                    .disabled(camera.isRecording)
                }
            }

            if allowsVideo {
                HStack(spacing: 24) {
                    modeButton("PHOTO", video: false)
                    modeButton("VIDEO", video: true)
                }
            }

            HStack {
                thumbnailStack
                    .frame(width: 80, alignment: .leading)
                Spacer()
                shutterButton
                Spacer()
                Button {
                    finish()
                } label: {
                    // A big green tick circle (owner, 2026-10-08) — no text, so it can't wrap
                    // the way the "Done" pill did at larger text sizes ("Do / ne").
                    Image(systemName: "checkmark")
                        .font(.system(size: 26, weight: .bold))
                        .foregroundStyle(.white)
                        .frame(width: 60, height: 60)
                        .background(shots.isEmpty ? Color.white.opacity(0.15) : Color.MW.green, in: Circle())
                        .overlay(Circle().stroke(Color.white.opacity(shots.isEmpty ? 0.25 : 0.9), lineWidth: 2))
                }
                .accessibilityLabel("Done")
                .frame(width: 80, alignment: .trailing)
                .disabled(shots.isEmpty || camera.isRecording || pendingShots > 0)
            }

            if !canShoot {
                Text("\(BatchCameraLimits.maxShots) is the most per batch — tap Done")
                    .font(.caption)
                    .foregroundStyle(.white.opacity(0.8))
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 12)
        .padding(.bottom, 16)
    }

    private func modeButton(_ title: String, video: Bool) -> some View {
        Button {
            guard videoMode != video, !camera.isRecording else { return }
            videoMode = video
            Task { await camera.setVideoMode(video) }
        } label: {
            Text(title)
                .font(.footnote.weight(.bold))
                .foregroundStyle(videoMode == video ? .yellow : .white)
        }
    }

    private var thumbnailStack: some View {
        Button {
            if !shots.isEmpty { showReview = true }
        } label: {
            ZStack(alignment: .topTrailing) {
                ZStack {
                    ForEach(Array(shots.suffix(3).enumerated()), id: \.element.id) { i, shot in
                        Image(uiImage: shot.preview)
                            .resizable()
                            .scaledToFill()
                            .frame(width: 52, height: 52)
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                            .overlay(RoundedRectangle(cornerRadius: 8).stroke(.white, lineWidth: 1.5))
                            .rotationEffect(.degrees(Double(i - min(shots.count, 3) + 1) * 6))
                    }
                    if shots.isEmpty {
                        RoundedRectangle(cornerRadius: 8)
                            .stroke(.white.opacity(0.35), lineWidth: 1.5)
                            .frame(width: 52, height: 52)
                    }
                }
                Text(BatchCameraLimits.counterText(count: shots.count))
                    .font(.caption2.weight(.bold))
                    .foregroundStyle(.white)
                    .padding(.horizontal, 5)
                    .padding(.vertical, 2)
                    .background(Color.MW.green, in: Capsule())
                    .offset(x: 14, y: -10)
            }
        }
        .accessibilityLabel("\(shots.count) of \(BatchCameraLimits.maxShots) taken — review")
    }

    private var shutterButton: some View {
        Button {
            shutterTapped()
        } label: {
            ZStack {
                Circle().stroke(.white, lineWidth: 4).frame(width: 74, height: 74)
                if videoMode {
                    if camera.isRecording {
                        RoundedRectangle(cornerRadius: 6).fill(.red).frame(width: 30, height: 30)
                    } else {
                        Circle().fill(.red).frame(width: 60, height: 60)
                    }
                } else {
                    Circle().fill(.white).frame(width: 60, height: 60)
                }
            }
            .opacity(canShoot || camera.isRecording ? 1 : 0.35)
        }
        .disabled(!camera.isRunning || (!canShoot && !camera.isRecording))
        .accessibilityLabel(videoMode ? (camera.isRecording ? "Stop recording" : "Record video") : "Take photo")
    }

    private var pinch: some Gesture {
        MagnificationGesture()
            .onChanged { scale in
                let start = pinchStart ?? camera.zoomFactor
                if pinchStart == nil { pinchStart = start }
                let range = camera.zoomRange
                camera.setZoom(ZoomMath.pinched(startZoom: start, scale: scale,
                                                min: range.lowerBound, max: range.upperBound))
            }
            .onEnded { _ in pinchStart = nil }
    }

    // MARK: Denied

    private var deniedView: some View {
        VStack(spacing: 18) {
            Image(systemName: "camera.fill")
                .font(.system(size: 44))
                .foregroundStyle(.white.opacity(0.8))
            Text(camera.access == .unavailable ? "No camera on this device" : "Camera access is off")
                .font(.title3.weight(.semibold))
                .foregroundStyle(.white)
            Text(camera.access == .unavailable
                 ? "Use Choose to add photos from the library instead."
                 : "Turn on Camera for Mowology in Settings to take job photos.")
                .font(.subheadline)
                .multilineTextAlignment(.center)
                .foregroundStyle(.white.opacity(0.8))
                .padding(.horizontal, 32)
            if camera.access == .denied {
                Button {
                    if let url = URL(string: UIApplication.openSettingsURLString) {
                        UIApplication.shared.open(url)
                    }
                } label: {
                    Text("Open Settings")
                        .font(.headline)
                        .padding(.horizontal, 24)
                        .padding(.vertical, 12)
                        .background(Color.MW.green, in: Capsule())
                        .foregroundStyle(.white)
                }
            }
            Button("Close") { onCancel() }
                .foregroundStyle(.white)
                .padding(.top, 4)
        }
    }

    // MARK: Actions

    private func shutterTapped() {
        if videoMode {
            if camera.isRecording {
                camera.stopRecording()
            } else if canShoot {
                let cat = category
                camera.startRecording { url in
                    guard let url else {
                        camera.errorText = "That clip didn't save — try again."
                        return
                    }
                    let thumb = Self.videoThumbnail(url) ?? UIImage(systemName: "video.fill") ?? UIImage()
                    shots.append(BatchShot(kind: .video, category: cat, fileData: Data(),
                                           editedData: nil, videoURL: url, preview: thumb))
                    showEditPill = false
                }
            }
            return
        }

        guard canShoot else { return }
        let cat = category
        pendingShots += 1
        camera.errorText = nil
        camera.capturePhoto(location: PhotoMetadata.recentLocation()) { data in
            pendingShots -= 1
            guard let data, let preview = PhotoMetadata.thumbnail(from: data) else {
                camera.errorText = "That photo didn't save — try again."
                return
            }
            shots.append(BatchShot(kind: .photo, category: cat, fileData: data,
                                   editedData: nil, videoURL: nil, preview: preview))
            showEditPill = true
            // One before is the usual — move on to "More" so the next shots aren't all befores.
            if cat == .before || cat == .after { category = .additional }
        }
    }

    private func applyEdit(shotId: UUID, data: Data) {
        editing = nil
        guard let i = shots.firstIndex(where: { $0.id == shotId }) else { return }
        shots[i].editedData = data
        if let p = PhotoMetadata.thumbnail(from: data) { shots[i].preview = p }
    }

    private func finish() {
        guard !shots.isEmpty else { onCancel(); return }
        onDone(shots)
    }

    private static func videoThumbnail(_ url: URL) -> UIImage? {
        let gen = AVAssetImageGenerator(asset: AVURLAsset(url: url))
        gen.appliesPreferredTrackTransform = true
        gen.maximumSize = CGSize(width: 400, height: 400)
        guard let cg = try? gen.copyCGImage(at: .zero, actualTime: nil) else { return nil }
        return UIImage(cgImage: cg)
    }
}

// MARK: - Preview layer

private struct CameraPreview: UIViewRepresentable {
    let camera: CameraController

    final class PreviewView: UIView {
        override class var layerClass: AnyClass { AVCaptureVideoPreviewLayer.self }
        var previewLayer: AVCaptureVideoPreviewLayer { layer as! AVCaptureVideoPreviewLayer }
    }

    func makeUIView(context: Context) -> PreviewView {
        let v = PreviewView()
        v.backgroundColor = .black
        camera.attach(previewLayer: v.previewLayer)
        return v
    }

    func updateUIView(_ uiView: PreviewView, context: Context) {}
}

// MARK: - Review sheet

/// Tap the thumbnail stack: every shot in the batch, with edit / re-tag / delete.
private struct BatchReviewSheet: View {
    @Binding var shots: [BatchShot]
    let categories: [BatchShotCategory]
    let onEdit: (BatchShot) -> Void
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            ScrollView {
                LazyVGrid(columns: [GridItem(.adaptive(minimum: 150), spacing: 12)], spacing: 16) {
                    ForEach(shots) { shot in
                        VStack(alignment: .leading, spacing: 6) {
                            Image(uiImage: shot.preview)
                                .resizable()
                                .scaledToFill()
                                .frame(height: 150)
                                .frame(maxWidth: .infinity)
                                .clipShape(RoundedRectangle(cornerRadius: 10))
                                .overlay(alignment: .topLeading) {
                                    if shot.kind == .video {
                                        Image(systemName: "video.fill")
                                            .foregroundStyle(.white)
                                            .padding(6)
                                    } else if shot.editedData != nil {
                                        Image(systemName: "pencil.tip.crop.circle.fill")
                                            .foregroundStyle(.white)
                                            .padding(6)
                                    }
                                }
                            HStack {
                                Menu {
                                    ForEach(categories) { c in
                                        Button(c.label) { retag(shot.id, c) }
                                    }
                                } label: {
                                    Label(shot.category.label, systemImage: "tag")
                                        .font(.caption.weight(.semibold))
                                }
                                Spacer()
                                if shot.kind == .photo {
                                    Button { onEdit(shot) } label: { Image(systemName: "pencil") }
                                        .accessibilityLabel("Edit")
                                }
                                Button(role: .destructive) {
                                    shots.removeAll { $0.id == shot.id }
                                    if shots.isEmpty { dismiss() }
                                } label: { Image(systemName: "trash") }
                                .accessibilityLabel("Delete")
                            }
                        }
                    }
                }
                .padding(16)
            }
            .navigationTitle("\(shots.count) in this batch")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) { Button("Back to camera") { dismiss() } }
            }
        }
    }

    private func retag(_ id: UUID, _ c: BatchShotCategory) {
        guard let i = shots.firstIndex(where: { $0.id == id }) else { return }
        shots[i].category = c
    }
}

private struct TrailingIconLabelStyle: LabelStyle {
    func makeBody(configuration: Configuration) -> some View {
        HStack(spacing: 6) {
            configuration.title
            configuration.icon
        }
    }
}
