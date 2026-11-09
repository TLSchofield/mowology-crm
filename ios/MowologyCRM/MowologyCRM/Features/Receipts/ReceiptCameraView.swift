//
//  ReceiptCameraView.swift
//  MowologyCRM
//
//  The receipt camera (Tim, 2026-11-09): won't shoot until the phone, the receipt and the focus are steady, and keeps the
//  light on. Built on the job-photo CameraController because Apple's document camera has no setting for either.
//  ReceiptSteadyGate decides when; a soft photo is thrown away and shot again here, before anyone has to answer for it.
//  The crop and the "Hold flat" question stay where they were, in ReceiptsView.
//

import SwiftUI
import AVFoundation
import CoreMotion
import Vision
import UIKit

@MainActor
final class ReceiptCameraModel: ObservableObject {
    let camera = CameraController()

    @Published fileprivate(set) var verdict = ReceiptSteadyVerdict(hint: .holdStill, shutterUnlocked: false, autoShoot: false)
    @Published fileprivate(set) var outline: [CGPoint]?
    @Published fileprivate(set) var frameSize: CGSize = .zero
    @Published fileprivate(set) var lightOn = true
    @Published fileprivate(set) var isChecking = false
    @Published fileprivate(set) var taken: [UIImage] = []
    @Published fileprivate(set) var notice: String?

    /// The sharpest try at the receipt being retaken. Counted as taken, so Done or Close can't lose it.
    private var bestTry: (image: UIImage, sharpness: Double)?
    private var retakes = 0
    private var gate = ReceiptSteadyGate()
    private let motion = CMMotionManager()
    private var rotationRate = 0.0
    private var acceleration = 0.0
    private var focusSettled = false
    private var closed = false
    /// While the "Discard?" question is up, nothing shoots behind it.
    var paused = false

    var photoCount: Int { taken.count + (bestTry == nil ? 0 : 1) }
    private var hasRoom: Bool { BatchCameraLimits.canShoot(count: taken.count) }
    private var canShoot: Bool { hasRoom && !isChecking && !paused }

    func start() async {
        camera.flash = .off   // the light is already on; a flash on top only adds glare
        camera.receiptFrames = { [weak self] buffer, settled in
            let found = Self.findOutline(buffer)
            let size = CGSize(width: CVPixelBufferGetWidth(buffer), height: CVPixelBufferGetHeight(buffer))
            DispatchQueue.main.async {
                guard let self else { return }
                self.outline = found
                self.focusSettled = settled
                if self.frameSize != size { self.frameSize = size }
                self.tick()
            }
        }
        await camera.start(videoMode: false)
        // Closed while the permission question was up: `start` has just queued the session behind the earlier stop.
        if closed { stop(); return }
        camera.setReceiptLight(lightOn)
        if motion.isDeviceMotionAvailable {
            motion.deviceMotionUpdateInterval = 1.0 / 20
            motion.startDeviceMotionUpdates(to: .main) { [weak self] m, _ in
                guard let self, let m else { return }
                self.rotationRate = Self.length(m.rotationRate.x, m.rotationRate.y, m.rotationRate.z)
                self.acceleration = Self.length(m.userAcceleration.x, m.userAcceleration.y, m.userAcceleration.z)
                self.tick()
            }
        }
    }

    func stop() {
        closed = true
        motion.stopDeviceMotionUpdates()
        camera.setReceiptLight(false)
        camera.stop()
    }

    func toggleLight() {
        lightOn.toggle()
        camera.setReceiptLight(lightOn)
    }

    /// Everything taken, the receipt mid-retake included.
    func allPhotos() -> [UIImage] {
        taken + (bestTry.map { [$0.image] } ?? [])
    }

    /// The same, once a shot still being judged has landed.
    func allPhotosWhenSettled() async -> [UIImage] {
        for _ in 0..<100 where isChecking {
            try? await Task.sleep(nanoseconds: 50_000_000)
        }
        return allPhotos()
    }

    private func tick() {
        guard canShoot else { return }
        let sample = ReceiptSteadySample(rotationRate: rotationRate, acceleration: acceleration,
                                         focusSettled: focusSettled, outline: outline)
        let next = gate.tick(now: CACurrentMediaTime(), sample)
        if next != verdict { verdict = next }
        if next.autoShoot { shoot(auto: true) }
    }

    func shoot(auto: Bool) {
        guard canShoot else { return }
        isChecking = true
        let shotOutline = outline
        let measured = String(format: "rot %.3f acc %.3f angle %.0f", rotationRate, acceleration, camera.receiptCaptureAngle())
        camera.capturePhoto(location: nil, matchPreview: true) { [weak self] data in
            Task { @MainActor in await self?.judge(data, outline: shotOutline, auto: auto, measured: measured) }
        }
    }

    /// Keep the shot, or throw it away and shoot again.
    private func judge(_ data: Data?, outline shotOutline: [CGPoint]?, auto: Bool, measured: String) async {
        defer { isChecking = false }
        guard let data, let image = UIImage(data: data) else {
            retakes += 1
            if retakes > ReceiptRetake.maxRetakes {
                // Stop firing by itself: the shutter still works once the phone is steady.
                retakes = 0
                notice = "That didn't take. Tap the button to try again"
                gate.didShoot(now: CACurrentMediaTime(), outline: shotOutline)
            } else {
                notice = "That didn't take. Trying again"
                gate.retry(now: CACurrentMediaTime())
            }
            return
        }
        let sharpness = await Task.detached(priority: .userInitiated) {
            ReceiptPhotoPrep.prepare(image).sharpness
        }.value
        AppDiagnostics.shared.record(category: "ReceiptCamera",
            message: "\(auto ? "auto" : "hand") \(measured) outline \(shotOutline != nil) light \(lightOn) sharp \(Int(min(sharpness, 99999))) retakes \(retakes)")

        if bestTry == nil || sharpness > (bestTry?.sharpness ?? 0) { bestTry = (image, sharpness) }
        if ReceiptRetake.shouldRetake(sharpness: sharpness, retakesSoFar: retakes) {
            retakes += 1
            notice = "Blurry. Hold still, taking it again"
            gate.retry(now: CACurrentMediaTime())
            return
        }
        if let best = bestTry { taken.append(best.image) }
        bestTry = nil
        retakes = 0
        notice = nil
        gate.didShoot(now: CACurrentMediaTime(), outline: shotOutline)
        if !hasRoom {
            notice = "That's the most at once. Tap Done"
            verdict.shutterUnlocked = false
        }
        UINotificationFeedbackGenerator().notificationOccurred(.success)
    }

    private nonisolated static func findOutline(_ buffer: CVPixelBuffer) -> [CGPoint]? {
        let request = VNDetectDocumentSegmentationRequest()
        try? VNImageRequestHandler(cvPixelBuffer: buffer, options: [:]).perform([request])
        guard let q = request.results?.first, q.confidence >= ReceiptPhotoPrep.minConfidence else { return nil }
        return [q.topLeft, q.topRight, q.bottomRight, q.bottomLeft]
    }

    private nonisolated static func length(_ x: Double, _ y: Double, _ z: Double) -> Double {
        (x * x + y * y + z * z).squareRoot()
    }

    #if DEBUG
    /// `-mwReceiptCameraDemo`: the screen with no camera (the simulator has none), for a look at the layout.
    func showDemo() {
        frameSize = CGSize(width: 3, height: 4)
        outline = [CGPoint(x: 0.28, y: 0.86), CGPoint(x: 0.72, y: 0.84), CGPoint(x: 0.75, y: 0.14), CGPoint(x: 0.25, y: 0.16)]
        verdict = ReceiptSteadyVerdict(hint: .holdStill, shutterUnlocked: false, autoShoot: false)
    }
    #endif
}

struct ReceiptCameraView: View {
    let onCapture: ([UIImage]) -> Void
    let onCancel: () -> Void
    var demo = false

    @StateObject private var model = ReceiptCameraModel()
    @State private var confirmDiscard = false
    @State private var blink = false
    @State private var access: CameraAccess = .unknown

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            if !demo && (access == .denied || access == .unavailable) {
                deniedView
            } else {
                cameraUI
            }
        }
        .statusBarHidden()
        .task {
            #if DEBUG
            if demo { model.showDemo(); return }
            #endif
            await model.start()
        }
        .onDisappear { model.stop() }
        .onChange(of: confirmDiscard) { _, open in model.paused = open }
        .onReceive(model.camera.$access) { access = $0 }
        .onReceive(model.camera.$shutterBlink.dropFirst()) { _ in
            blink = true
            withAnimation(.easeOut(duration: 0.25)) { blink = false }
        }
        .confirmationDialog("Discard \(model.photoCount) \(model.photoCount == 1 ? "receipt" : "receipts")?",
                            isPresented: $confirmDiscard, titleVisibility: .visible) {
            Button("Save \(model.photoCount == 1 ? "it" : "them") and close") {
                Task { onCapture(await model.allPhotosWhenSettled()) }
            }
            Button("Discard", role: .destructive) { onCancel() }
            Button("Keep shooting", role: .cancel) {}
        }
    }

    private var cameraUI: some View {
        VStack(spacing: 0) {
            topBar
            ZStack {
                CameraPreview(camera: model.camera)
                    .overlay(Color.black.opacity(blink ? 0.7 : 0).allowsHitTesting(false))
                outlineOverlay
                VStack {
                    Spacer()
                    Text(model.notice ?? model.verdict.hint.text)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.white)
                        .padding(.horizontal, 14)
                        .padding(.vertical, 8)
                        .background(.black.opacity(0.65), in: Capsule())
                        .padding(.bottom, 12)
                        .accessibilityAddTraits(.updatesFrequently)
                }
            }
            bottomControls
        }
    }

    private var topBar: some View {
        HStack {
            Button {
                if model.photoCount == 0 { onCancel() } else { confirmDiscard = true }
            } label: {
                Image(systemName: "xmark").font(.title2.weight(.semibold))
            }
            .accessibilityLabel("Close camera")

            Spacer()

            Button { model.toggleLight() } label: {
                Image(systemName: model.lightOn ? "flashlight.on.fill" : "flashlight.off.fill")
                    .font(.title3)
                    .foregroundStyle(model.lightOn ? .yellow : .white)
            }
            .accessibilityLabel(model.lightOn ? "Turn the light off" : "Turn the light on")
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 20)
        .frame(height: 52)
    }

    @ViewBuilder
    private var outlineOverlay: some View {
        if let corners = model.outline, model.frameSize != .zero {
            GeometryReader { geo in
                let shown = AVMakeRect(aspectRatio: model.frameSize, insideRect: CGRect(origin: .zero, size: geo.size))
                Path { path in
                    // Vision counts up from the bottom of the frame.
                    let points = corners.map { CGPoint(x: shown.minX + $0.x * shown.width, y: shown.maxY - $0.y * shown.height) }
                    path.addLines(points)
                    path.closeSubpath()
                }
                .stroke(model.verdict.shutterUnlocked ? Color.yellow : Color.white, lineWidth: 3)
            }
            .allowsHitTesting(false)
        }
    }

    private var bottomControls: some View {
        HStack {
            Text(model.photoCount == 0 ? "" : "\(model.photoCount)")
                .font(.headline)
                .foregroundStyle(.white)
                .frame(width: 80, alignment: .leading)
                .accessibilityLabel("\(model.photoCount) taken")

            Spacer()

            Button { model.shoot(auto: false) } label: {
                ZStack {
                    Circle().stroke(.white, lineWidth: 4).frame(width: 74, height: 74)
                    Circle().fill(.white).frame(width: 62, height: 62)
                    if model.isChecking { ProgressView().tint(.black) }
                }
                .opacity(model.verdict.shutterUnlocked ? 1 : 0.35)
            }
            .disabled(!model.verdict.shutterUnlocked || model.isChecking)
            .accessibilityLabel("Take photo")

            Spacer()

            Button { onCapture(model.allPhotos()) } label: {
                Text("Done").font(.headline).foregroundStyle(.white)
            }
            .frame(width: 80, alignment: .trailing)
            .disabled(model.photoCount == 0 || model.isChecking)
            .opacity(model.photoCount == 0 ? 0 : 1)
        }
        .padding(.horizontal, 20)
        .frame(height: 120)
    }

    private var deniedView: some View {
        VStack(spacing: 18) {
            Image(systemName: "camera.fill")
                .font(.system(size: 44))
                .foregroundStyle(.white.opacity(0.8))
            Text(access == .unavailable ? "No camera on this device" : "Camera access is off")
                .font(.title3.weight(.semibold))
                .foregroundStyle(.white)
            if access == .denied {
                Button {
                    if let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) }
                } label: {
                    Text("Open Settings")
                        .font(.headline)
                        .padding(.horizontal, 24)
                        .padding(.vertical, 12)
                        .background(Color.MW.green, in: Capsule())
                        .foregroundStyle(.white)
                }
            }
            Button("Close", action: onCancel).foregroundStyle(.white)
        }
    }
}

/// `-mwReceiptCameraDemo`, debug builds only: the camera screen laid over the app, for a screenshot.
struct ReceiptCameraDemoOverlay: View {
    var body: some View {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("-mwReceiptCameraDemo") {
            ReceiptCameraView(onCapture: { _ in }, onCancel: {}, demo: true)
        }
        #endif
    }
}
