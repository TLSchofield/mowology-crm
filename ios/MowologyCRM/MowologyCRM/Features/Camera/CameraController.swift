//
//  CameraController.swift
//  MowologyCRM
//
//  AVFoundation side of the batch camera: one capture session with a photo output
//  (and, in VIDEO mode, a movie output + microphone), lens chips via the back virtual
//  camera, flash, flip, pinch zoom and a capped recording timer.
//
//  All session work runs on `sessionQueue`; @Published state is only touched on main.
//

import AVFoundation
import UIKit
import CoreLocation

enum FlashSetting: String, CaseIterable {
    case auto, on, off

    var next: FlashSetting {
        switch self { case .auto: return .on; case .on: return .off; case .off: return .auto }
    }
    var systemImage: String {
        switch self { case .auto: return "bolt.badge.automatic.fill"; case .on: return "bolt.fill"; case .off: return "bolt.slash.fill" }
    }
    var avMode: AVCaptureDevice.FlashMode {
        switch self { case .auto: return .auto; case .on: return .on; case .off: return .off }
    }
}

enum CameraAccess: Equatable {
    case unknown, granted, denied, unavailable
}

final class CameraController: NSObject, ObservableObject {

    // MARK: - Published (main thread only)

    @Published private(set) var access: CameraAccess = .unknown
    @Published private(set) var isRunning = false
    @Published private(set) var position: AVCaptureDevice.Position = .back
    @Published private(set) var chips: [LensChip] = []
    @Published private(set) var zoomFactor: CGFloat = 1
    @Published private(set) var wideZoomFactor: CGFloat = 1
    @Published private(set) var hasFlash = false
    @Published var flash: FlashSetting = .auto
    @Published private(set) var isRecording = false
    @Published private(set) var recordingSeconds: Double = 0
    @Published private(set) var microphoneAvailable = true
    /// Toggled on each shutter so the view can blink the preview.
    @Published private(set) var shutterBlink = 0
    @Published var errorText: String?

    // MARK: - Session

    let session = AVCaptureSession()
    private let sessionQueue = DispatchQueue(label: "ca.mowology.batchcamera.session")
    private let photoOutput = AVCapturePhotoOutput()
    private let movieOutput = AVCaptureMovieFileOutput()
    private var videoInput: AVCaptureDeviceInput?
    private var audioInput: AVCaptureDeviceInput?
    private var configured = false

    /// Session-queue copy of the zoom range (UI reads the published copies).
    private var minZoom: CGFloat = 1
    private var maxZoom: CGFloat = 1

    private var inFlight: [Int64: PhotoCaptureProcessor] = [:]
    private var recordingDone: ((URL?) -> Void)?
    private var recordingTimer: Timer?
    private var recordingStartedAt: Date?

    // Rotation (main thread).
    private weak var previewLayer: AVCaptureVideoPreviewLayer?
    private var rotationCoordinator: AVCaptureDevice.RotationCoordinator?
    private var rotationObservation: NSKeyValueObservation?

    deinit {
        rotationObservation?.invalidate()
        recordingTimer?.invalidate()
    }

    // MARK: - Permission + start

    /// Ask for camera access if needed, then build and start the session.
    @MainActor
    func start(videoMode: Bool) async {
        switch AVCaptureDevice.authorizationStatus(for: .video) {
        case .authorized:
            break
        case .notDetermined:
            let ok = await AVCaptureDevice.requestAccess(for: .video)
            if !ok { access = .denied; return }
        default:
            access = .denied
            return
        }
        guard AVCaptureDevice.default(for: .video) != nil else { access = .unavailable; return }
        access = .granted

        sessionQueue.async { [weak self] in
            guard let self else { return }
            if !self.configured { self.configureSession() }
            if !self.session.isRunning { self.session.startRunning() }
            let running = self.session.isRunning
            DispatchQueue.main.async { self.isRunning = running }
        }
        if videoMode { await setVideoMode(true) }
    }

    func stop() {
        sessionQueue.async { [weak self] in
            guard let self else { return }
            if self.movieOutput.isRecording { self.movieOutput.stopRecording() }
            if self.session.isRunning { self.session.stopRunning() }
            DispatchQueue.main.async { self.isRunning = false }
        }
    }

    // MARK: - Preview attach (main)

    @MainActor
    func attach(previewLayer: AVCaptureVideoPreviewLayer) {
        self.previewLayer = previewLayer
        previewLayer.session = session
        previewLayer.videoGravity = .resizeAspect
        if let device = videoInput?.device { makeRotationCoordinator(for: device) }
    }

    /// Main thread only.
    private func makeRotationCoordinator(for device: AVCaptureDevice) {
        rotationObservation?.invalidate()
        guard let layer = previewLayer else { return }
        let coordinator = AVCaptureDevice.RotationCoordinator(device: device, previewLayer: layer)
        rotationCoordinator = coordinator
        applyPreviewAngle(coordinator.videoRotationAngleForHorizonLevelPreview)
        rotationObservation = coordinator.observe(\.videoRotationAngleForHorizonLevelPreview, options: [.new]) { [weak self] c, _ in
            let angle = c.videoRotationAngleForHorizonLevelPreview
            DispatchQueue.main.async { self?.applyPreviewAngle(angle) }
        }
    }

    /// Main thread only.
    private func applyPreviewAngle(_ angle: CGFloat) {
        guard let conn = previewLayer?.connection, conn.isVideoRotationAngleSupported(angle) else { return }
        conn.videoRotationAngle = angle
    }

    // MARK: - Configuration (session queue)

    private func backCamera() -> AVCaptureDevice? {
        let discovery = AVCaptureDevice.DiscoverySession(
            deviceTypes: [.builtInTripleCamera, .builtInDualWideCamera, .builtInDualCamera, .builtInWideAngleCamera],
            mediaType: .video, position: .back)
        // Prefer the virtual camera with the most lenses — it switches lens by zoom factor.
        let order: [AVCaptureDevice.DeviceType] = [.builtInTripleCamera, .builtInDualWideCamera, .builtInDualCamera, .builtInWideAngleCamera]
        for type in order {
            if let d = discovery.devices.first(where: { $0.deviceType == type }) { return d }
        }
        return discovery.devices.first
    }

    private func frontCamera() -> AVCaptureDevice? {
        AVCaptureDevice.DiscoverySession(
            deviceTypes: [.builtInTrueDepthCamera, .builtInWideAngleCamera],
            mediaType: .video, position: .front).devices.first
    }

    private func configureSession() {
        session.beginConfiguration()
        session.sessionPreset = .photo
        if let device = backCamera(), let input = try? AVCaptureDeviceInput(device: device),
           session.canAddInput(input) {
            session.addInput(input)
            videoInput = input
        }
        if session.canAddOutput(photoOutput) {
            session.addOutput(photoOutput)
            photoOutput.maxPhotoQualityPrioritization = .quality
        }
        session.commitConfiguration()
        configured = true
        if let device = videoInput?.device { deviceDidChange(device) }
    }

    /// Recompute chips / zoom range for the active lens and reset to 1×.
    private func deviceDidChange(_ device: AVCaptureDevice) {
        let constituents = device.constituentDevices.map(\.deviceType)
        let hasUltraWide = device.isVirtualDevice && constituents.first == .builtInUltraWideCamera
        let switchOvers = device.virtualDeviceSwitchOverVideoZoomFactors.map { CGFloat(truncating: $0) }
        let wide: CGFloat = hasUltraWide ? (switchOvers.first ?? 1) : 1
        minZoom = device.minAvailableVideoZoomFactor
        maxZoom = ZoomMath.usableMax(deviceMax: device.maxAvailableVideoZoomFactor, wideZoom: wide)
        let chips = LensChip.chips(hasUltraWide: hasUltraWide, switchOvers: switchOvers, maxZoom: maxZoom)
        setZoomOnQueue(wide, ramp: false)
        let flash = device.hasFlash
        let pos = device.position
        DispatchQueue.main.async {
            self.chips = chips
            self.wideZoomFactor = wide
            self.hasFlash = flash
            self.position = pos
            self.makeRotationCoordinator(for: device)
        }
    }

    // MARK: - Flip

    func flip() {
        guard !isRecording else { return }
        sessionQueue.async { [weak self] in
            guard let self, let current = self.videoInput else { return }
            let target = current.device.position == .back ? self.frontCamera() : self.backCamera()
            guard let device = target, let input = try? AVCaptureDeviceInput(device: device) else { return }
            self.session.beginConfiguration()
            self.session.removeInput(current)
            if self.session.canAddInput(input) {
                self.session.addInput(input)
                self.videoInput = input
            } else {
                self.session.addInput(current)
            }
            self.session.commitConfiguration()
            if let d = self.videoInput?.device { self.deviceDidChange(d) }
        }
    }

    // MARK: - Zoom

    func setZoom(_ factor: CGFloat, ramp: Bool = false) {
        sessionQueue.async { [weak self] in self?.setZoomOnQueue(factor, ramp: ramp) }
    }

    private func setZoomOnQueue(_ factor: CGFloat, ramp: Bool) {
        guard let device = videoInput?.device else { return }
        let z = min(max(factor, minZoom), maxZoom)
        do {
            try device.lockForConfiguration()
            if ramp { device.ramp(toVideoZoomFactor: z, withRate: 8) } else { device.videoZoomFactor = z }
            device.unlockForConfiguration()
        } catch { return }
        DispatchQueue.main.async { self.zoomFactor = z }
    }

    /// Range the pinch gesture may use (main-thread copy, refreshed with the chips).
    var zoomRange: ClosedRange<CGFloat> {
        let lo = chips.first?.zoomFactor ?? 1
        return lo...max(lo, wideZoomFactor * 10)
    }

    // MARK: - Photo

    /// Take one photo. `completion` runs on main with the JPEG file data (EXIF kept,
    /// GPS added when a fix is available) or nil on failure.
    @MainActor
    func capturePhoto(location: CLLocation?, completion: @escaping (Data?) -> Void) {
        let captureAngle = rotationCoordinator?.videoRotationAngleForHorizonLevelCapture
        let flashMode = flash.avMode
        sessionQueue.async { [weak self] in
            guard let self else { return }
            let settings: AVCapturePhotoSettings
            if self.photoOutput.availablePhotoCodecTypes.contains(.jpeg) {
                settings = AVCapturePhotoSettings(format: [AVVideoCodecKey: AVVideoCodecType.jpeg])
            } else {
                settings = AVCapturePhotoSettings()
            }
            if self.photoOutput.supportedFlashModes.contains(flashMode) { settings.flashMode = flashMode }
            // .balanced keeps rapid shooting snappy; .quality can stall a second per shot.
            settings.photoQualityPrioritization = .balanced
            if let angle = captureAngle, let conn = self.photoOutput.connection(with: .video),
               conn.isVideoRotationAngleSupported(angle) {
                conn.videoRotationAngle = angle
            }

            let id = settings.uniqueID
            let processor = PhotoCaptureProcessor(location: location,
                willCapture: { [weak self] in
                    guard let owner = self else { return }
                    DispatchQueue.main.async { owner.shutterBlink += 1 }
                },
                completion: { [weak self] data in
                    if let owner = self {
                        owner.sessionQueue.async { owner.inFlight[id] = nil }
                    }
                    DispatchQueue.main.async { completion(data) }
                })
            self.inFlight[id] = processor
            self.photoOutput.capturePhoto(with: settings, delegate: processor)
        }
    }

    // MARK: - Video mode

    /// Adds / removes the movie output and microphone. Photo mode stays on the .photo preset
    /// (full-resolution stills); video mode uses .high (1080p).
    @MainActor
    func setVideoMode(_ on: Bool) async {
        var micOK = false
        if on {
            switch AVCaptureDevice.authorizationStatus(for: .audio) {
            case .authorized: micOK = true
            case .notDetermined: micOK = await AVCaptureDevice.requestAccess(for: .audio)
            default: micOK = false
            }
            microphoneAvailable = micOK
        }
        let wantsMic = micOK
        sessionQueue.async { [weak self] in
            guard let self else { return }
            self.session.beginConfiguration()
            if on {
                self.session.sessionPreset = .high
                if self.session.canAddOutput(self.movieOutput) { self.session.addOutput(self.movieOutput) }
                self.movieOutput.maxRecordedDuration = CMTime(seconds: BatchCameraLimits.maxVideoSeconds,
                                                              preferredTimescale: 600)
                if wantsMic, self.audioInput == nil, let mic = AVCaptureDevice.default(for: .audio),
                   let input = try? AVCaptureDeviceInput(device: mic), self.session.canAddInput(input) {
                    self.session.addInput(input)
                    self.audioInput = input
                }
            } else {
                if self.session.outputs.contains(self.movieOutput) { self.session.removeOutput(self.movieOutput) }
                if let a = self.audioInput { self.session.removeInput(a); self.audioInput = nil }
                self.session.sessionPreset = .photo
            }
            self.session.commitConfiguration()
        }
    }

    @MainActor
    func startRecording(completion: @escaping (URL?) -> Void) {
        guard !isRecording else { return }
        let captureAngle = rotationCoordinator?.videoRotationAngleForHorizonLevelCapture
        let torch = flash == .on
        recordingDone = completion
        isRecording = true
        recordingSeconds = 0
        recordingStartedAt = Date()
        recordingTimer = Timer.scheduledTimer(withTimeInterval: 0.25, repeats: true) { [weak self] _ in
            guard let self, let start = self.recordingStartedAt else { return }
            self.recordingSeconds = Date().timeIntervalSince(start)
        }
        sessionQueue.async { [weak self] in
            guard let self else { return }
            if let angle = captureAngle, let conn = self.movieOutput.connection(with: .video),
               conn.isVideoRotationAngleSupported(angle) {
                conn.videoRotationAngle = angle
            }
            self.setTorch(torch)
            let url = FileManager.default.temporaryDirectory
                .appendingPathComponent("mw-clip-\(UUID().uuidString).mov")
            self.movieOutput.startRecording(to: url, recordingDelegate: self)
        }
    }

    func stopRecording() {
        sessionQueue.async { [weak self] in
            guard let self, self.movieOutput.isRecording else { return }
            self.movieOutput.stopRecording()
        }
    }

    private func setTorch(_ on: Bool) {
        guard let device = videoInput?.device, device.hasTorch else { return }
        try? device.lockForConfiguration()
        device.torchMode = on ? .on : .off
        device.unlockForConfiguration()
    }
}

// MARK: - Movie delegate

extension CameraController: AVCaptureFileOutputRecordingDelegate {
    func fileOutput(_ output: AVCaptureFileOutput, didFinishRecordingTo outputFileURL: URL,
                    from connections: [AVCaptureConnection], error: Error?) {
        setTorch(false)
        // Hitting maxRecordedDuration reports an "error" but the file is complete.
        var ok = error == nil
        if let ns = error as NSError?,
           let finished = ns.userInfo[AVErrorRecordingSuccessfullyFinishedKey] as? Bool {
            ok = finished
        }
        DispatchQueue.main.async {
            self.recordingTimer?.invalidate()
            self.recordingTimer = nil
            self.isRecording = false
            let done = self.recordingDone
            self.recordingDone = nil
            done?(ok ? outputFileURL : nil)
        }
    }
}

// MARK: - Photo processor

/// One per shot, so several shots can be in flight while the crew keeps tapping.
private final class PhotoCaptureProcessor: NSObject, AVCapturePhotoCaptureDelegate,
                                           AVCapturePhotoFileDataRepresentationCustomizer {
    private let location: CLLocation?
    private let willCapture: () -> Void
    private let completion: (Data?) -> Void
    private var data: Data?

    init(location: CLLocation?, willCapture: @escaping () -> Void, completion: @escaping (Data?) -> Void) {
        self.location = location
        self.willCapture = willCapture
        self.completion = completion
    }

    func photoOutput(_ output: AVCapturePhotoOutput, willCapturePhotoFor resolvedSettings: AVCaptureResolvedPhotoSettings) {
        willCapture()
    }

    func photoOutput(_ output: AVCapturePhotoOutput, didFinishProcessingPhoto photo: AVCapturePhoto, error: Error?) {
        guard error == nil else { return }
        // The file's own EXIF (DateTimeOriginal, orientation) is kept; GPS is added here.
        data = photo.fileDataRepresentation(with: self) ?? photo.fileDataRepresentation()
    }

    func photoOutput(_ output: AVCapturePhotoOutput, didFinishCaptureFor resolvedSettings: AVCaptureResolvedPhotoSettings, error: Error?) {
        completion(data)
    }

    func replacementMetadata(for photo: AVCapturePhoto) -> [String: Any]? {
        guard let location else { return nil }
        var meta = photo.metadata
        meta[kCGImagePropertyGPSDictionary as String] = PhotoMetadata.gpsDictionary(for: location)
        return meta
    }
}
