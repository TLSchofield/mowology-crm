//
//  JobPhotoSection.swift
//  MowologyCRM
//
//  Self-contained before/after photo proof component for a single visit.
//  Owns its own ViewModel so it can be embedded inside VisitDetailView's
//  ForEach(visits) without needing an external state manager.
//
//  Mirrors the Capacitor schedule-pill-workflow.js photo state machine:
//    - Two slots: "before" and "after", plus any number of extras
//    - Every camera button opens the native BatchCameraView (Features/Camera): up to 10
//      shots per batch, each tagged Before / During / After / More, optional markup
//    - The batch uploads on Done; failures queue to JobPhotoQueue for retry
//    - Shows thumbnail + retake button once a slot is filled
//

import SwiftUI
import UIKit
import PhotosUI
import AVFoundation
import CoreLocation

// MARK: - JobPhotoType

/// Identifies which photo slot a capture belongs to.
enum JobPhotoType: String, CaseIterable, Identifiable {
    case before     = "before"
    case after      = "after"
    /// Extra proof photos — any number. Same category the web app and Salt report use.
    case additional = "additional"
    /// Mid-job photos from the batch camera. The server lists them with the extras.
    case during     = "during"

    var id: String { rawValue }

    /// Before/after hold one photo each (a retake replaces it); extras are a list.
    var isSingleSlot: Bool { self == .before || self == .after }

    /// The batch camera category a slot button opens on.
    var batchCategory: BatchShotCategory {
        switch self {
        case .before:     return .before
        case .after:      return .after
        case .during:     return .during
        case .additional: return .additional
        }
    }
}

/// An extra photo taken on this phone in this session (may still be uploading or queued).
struct LocalExtraPhoto: Identifiable {
    let id = UUID()
    let image: UIImage
}

// MARK: - JobPhotoViewModel

@MainActor
final class JobPhotoViewModel: ObservableObject {

    // MARK: - Published State

    @Published var beforeImage:  UIImage? = nil
    @Published var afterImage:   UIImage? = nil
    @Published var errorMessage: String?  = nil

    /// What the server already holds — so reopening a visit shows the photos taken earlier
    /// instead of blank slots (and keeps the After slot unlocked).
    @Published var remoteBefore: VisitPhoto? = nil
    @Published var remoteAfter:  VisitPhoto? = nil
    /// Every before and after on the server, befores first — one per crew member who took one.
    @Published var remoteProofShots: [VisitPhoto] = []
    @Published var remoteExtras: [VisitPhoto] = []

    /// Extras captured in this session, newest last.
    @Published var localExtras: [LocalExtraPhoto] = []

    /// True when a photo is queued offline but not yet synced to the server.
    @Published var beforePendingSync: Bool = false
    @Published var afterPendingSync:  Bool = false
    @Published var extrasPendingSync: Int  = 0

    /// Active camera slot — drives the fullScreenCover in JobPhotoSection.
    @Published var captureSlot: JobPhotoType? = nil

    @Published private var uploadsInFlight: Int = 0
    var isUploading: Bool { uploadsInFlight > 0 }

    // MARK: - Private

    private let visitId:   Int
    private let apiClient: APIClient
    private var connectivityObserver: NSObjectProtocol?

    // MARK: - Init

    init(visitId: Int, authSession: AuthSession) {
        self.visitId   = visitId
        self.apiClient = APIClient(authSession: authSession)
        refreshPendingState()

        // When signal returns, wait for the app-wide photo upload (never a second drain of our
        // own — two runs uploaded every queued photo twice), then refresh this card.
        connectivityObserver = NotificationCenter.default.addObserver(
            forName: .mwPingQueueOnline,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                await AppPhotoQueueDrainService.shared.drain()
                guard let self else { return }
                self.refreshPendingState()
                await self.loadExisting()
            }
        }
    }

    deinit {
        if let obs = connectivityObserver {
            NotificationCenter.default.removeObserver(obs)
        }
    }

    // MARK: - Helpers

    private func refreshPendingState() {
        beforePendingSync = JobPhotoQueue.shared.hasQueued(visitId: visitId, photoType: .before)
        afterPendingSync  = JobPhotoQueue.shared.hasQueued(visitId: visitId, photoType: .after)
        extrasPendingSync = JobPhotoQueue.shared.queuedCount(visitId: visitId, photoType: .additional)
                          + JobPhotoQueue.shared.queuedCount(visitId: visitId, photoType: .during)
    }

    // MARK: - Computed

    var hasBeforePhoto: Bool { beforeImage != nil || remoteBefore != nil || beforePendingSync }
    var hasAfterPhoto:  Bool { afterImage  != nil || remoteAfter  != nil || afterPendingSync }
    var isComplete:     Bool { hasBeforePhoto && hasAfterPhoto }
    var extraCount:     Int  { remoteExtras.count + localExtras.count }

    // MARK: - Load

    /// Photos already on the server. Silent on failure — offline just means "nothing to show yet".
    func loadExisting() async {
        guard let response: VisitPhotosResponse = try? await apiClient.request(.scheduleVisitPhotos(visitId: visitId)),
              let photos = response.photos else { return }

        remoteBefore = photos.last { $0.photoType == "before" }
        remoteAfter  = photos.last { $0.photoType == "after" }
        remoteProofShots = photos.filter { $0.photoType == "before" } + photos.filter { $0.photoType == "after" }
        remoteExtras = photos.filter { $0.photoType == "additional" }
        // The server list now includes everything this session uploaded.
        if !isUploading && extrasPendingSync == 0 { localExtras = [] }
    }

    // MARK: - Capture Handling

    func beginCapture(_ slot: JobPhotoType) {
        captureSlot = slot
    }

    /// The batch camera's Done: show every shot at once, then upload them one by one —
    /// befores first, afters last, so the implied start/finish stamps land in order.
    func handleBatch(_ shots: [BatchShot]) {
        captureSlot = nil
        let photos = shots.filter { $0.kind == .photo }
        if photos.count < shots.count {
            // BatchCameraFeatures.visitVideo is off until the server stores visit video.
            errorMessage = "Videos can't be saved to a visit yet — only the photos were kept."
        }
        let ordered = BatchShotCategory.uploadOrder(photos) { $0.category }
        for shot in ordered {
            switch shot.category {
            case .before: beforeImage = shot.preview
            case .after:  afterImage  = shot.preview
            case .during, .additional: localExtras.append(LocalExtraPhoto(image: shot.preview))
            }
        }
        Task {
            for shot in ordered {
                let slot = JobPhotoType(rawValue: shot.category.rawValue) ?? .additional
                await upload(data: shot.uploadData, slot: slot)
            }
        }
    }

    /// Photos picked from the library, several at once.
    func addFromLibrary(_ images: [UIImage]) {
        for image in images {
            localExtras.append(LocalExtraPhoto(image: image))
            guard let data = image.jpegData(compressionQuality: 0.78) else { continue }
            Task { await upload(data: data, slot: .additional) }
        }
    }

    func cancelCapture() {
        captureSlot = nil
    }

    // MARK: - Upload

    private func upload(data: Data, slot: JobPhotoType) async {
        uploadsInFlight += 1
        errorMessage = nil
        defer { uploadsInFlight -= 1 }

        do {
            try await apiClient.uploadJobPhoto(imageData: data,
                                               visitId:   visitId,
                                               photoType: slot)
            // Success — clear any queued marker for this slot.
            switch slot {
            case .before:     beforePendingSync = false
            case .after:      afterPendingSync  = false
            case .additional, .during: break
            }
        } catch let err as APIError {
            if case .networkError = err {
                // Offline — queue to disk and show a soft indicator instead of an error.
                JobPhotoQueue.shared.enqueue(imageData: data, visitId: visitId, photoType: slot,
                                             userId: apiClient.currentUserId)
                refreshPendingState()
            } else {
                errorMessage = slot.isSingleSlot
                    ? "Upload failed — retake the photo to try again."
                    : "A photo failed to upload — take it again."
            }
        } catch {
            errorMessage = "Upload failed — retake the photo to try again."
        }
    }
}

// MARK: - JobPhotoSection View

/// Embeds inside a visit card to provide the before/after photo proof UI.
/// Pass `isActive` true once the job has started (timer running OR visit in progress — an
/// auto-stopped or reopened visit has no live timer but is still mid-job) to unlock After.
struct JobPhotoSection: View {

    let visitId:     Int
    let isActive:    Bool
    let authSession: AuthSession

    /// Current flag state — passed in from the owning ViewModel so the Visit struct
    /// stays immutable. Defaults to false so existing call sites need no changes.
    let isFlagged:      Bool
    let isFlagLoading:  Bool
    /// Everyone who endorsed this visit — several crew can each give their own heart.
    let endorsedBy:     [String]
    /// Nil = hide the heart slot entirely (backward-compat for callers that don't support flagging).
    let onFlagToggle:   (() async -> Void)?
    /// Special-request gate: returns true (and the owner shows the request) when this person
    /// hasn't read it yet — the camera / picker must NOT open on that tap. Nil = no gate.
    let captureGate:    (() -> Bool)?
    /// True while that gate would block — the library picker is swapped for a plain button.
    let photosLocked:   Bool

    private var chooseLabel: some View {
        Label("Choose", systemImage: "photo.on.rectangle")
            .font(.subheadline.weight(.semibold))
            .frame(maxWidth: .infinity)
            .padding(.vertical, 10)
            .background(Color(.systemGray6))
            .foregroundStyle(.primary)
            .clipShape(RoundedRectangle(cornerRadius: 10))
    }

    @StateObject private var vm: JobPhotoViewModel
    @State private var libraryItems: [PhotosPickerItem] = []
    @State private var showPlantId = false

    init(visitId: Int, isActive: Bool, authSession: AuthSession,
         isFlagged: Bool = false, isFlagLoading: Bool = false,
         endorsedBy: [String] = [],
         onFlagToggle: (() async -> Void)? = nil,
         captureGate: (() -> Bool)? = nil,
         photosLocked: Bool = false) {
        self.visitId      = visitId
        self.isActive     = isActive
        self.authSession  = authSession
        self.isFlagged    = isFlagged
        self.isFlagLoading = isFlagLoading
        self.endorsedBy   = endorsedBy
        self.onFlagToggle = onFlagToggle
        self.captureGate  = captureGate
        self.photosLocked = photosLocked
        _vm = StateObject(wrappedValue: JobPhotoViewModel(visitId: visitId,
                                                          authSession: authSession))
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {

            // Section header
            Label("Photo Proof", systemImage: "camera.fill")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)
                .textCase(.uppercase)

            // Upload progress indicator
            if vm.isUploading {
                HStack(spacing: 6) {
                    ProgressView().scaleEffect(0.75).tint(Color.MW.green)
                    Text("Uploading…")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            // Queued / pending sync banner (no signal at capture time)
            if vm.beforePendingSync || vm.afterPendingSync || vm.extrasPendingSync > 0 {
                HStack(spacing: 6) {
                    Image(systemName: "arrow.triangle.2.circlepath")
                        .font(.caption)
                        .foregroundStyle(Color.MW.green)
                    Text(vm.extrasPendingSync > 1
                         ? "\(vm.extrasPendingSync) photos saved — will upload when signal returns"
                         : "Photo saved — will upload when signal returns")
                        .font(.caption)
                        .foregroundStyle(Color.MW.green)
                }
                .padding(8)
                .background(Color.MW.green.opacity(0.08))
                .clipShape(RoundedRectangle(cornerRadius: 8))
            }

            // Error banner (server errors, not network-offline)
            if let err = vm.errorMessage {
                HStack(alignment: .top, spacing: 6) {
                    Image(systemName: "exclamationmark.triangle.fill")
                        .font(.caption)
                        .foregroundStyle(.orange)
                    Text(err)
                        .font(.caption)
                        .foregroundStyle(.orange)
                        .fixedSize(horizontal: false, vertical: true)
                }
                .padding(8)
                .background(Color.orange.opacity(0.08))
                .clipShape(RoundedRectangle(cornerRadius: 8))
            }

            // Photo slots + optional heart endorsement slot
            HStack(spacing: 12) {
                photoSlot(label: "Before", slot: .before, image: vm.beforeImage,
                          remote: vm.remoteBefore, enabled: true)
                photoSlot(label: "After", slot: .after, image: vm.afterImage,
                          remote: vm.remoteAfter, enabled: isActive || vm.hasBeforePhoto)
                if onFlagToggle != nil {
                    heartSlot()
                }
            }

            if !endorsedBy.isEmpty {
                Label("Endorsed by \(endorsedBy.joined(separator: ", "))", systemImage: "heart.fill")
                    .font(.caption)
                    .foregroundStyle(Color.MW.orange)
                    .lineLimit(2)
            }

            crewShotsStrip()

            extrasStrip()

            if !photosLocked {
                Button {
                    showPlantId = true
                } label: {
                    Label("What is this plant?", systemImage: "leaf")
                        .font(.subheadline.weight(.semibold))
                }
                .buttonStyle(.bordered)
                .tint(Color.MW.green)
            }
        }
        .fullScreenCover(isPresented: $showPlantId) {
            PlantIdView(visitId: visitId, authSession: authSession) { showPlantId = false }
        }
        .task { await vm.loadExisting() }
        // Batch camera — presented when captureSlot is non-nil. Opens on the tapped slot's
        // category; the crew can shoot up to 10, re-tag, mark up, then Done uploads them all.
        .fullScreenCover(item: $vm.captureSlot) { slot in
            BatchCameraView(
                initialCategory: slot.batchCategory,
                afterUnlocked:   isActive || vm.hasBeforePhoto,
                allowsVideo:     BatchCameraFeatures.visitVideo,
                onDone:   { shots in vm.handleBatch(shots) },
                onCancel: { vm.cancelCapture() }
            )
        }
        .onChange(of: libraryItems) { _, items in
            guard !items.isEmpty else { return }
            Task {
                var images: [UIImage] = []
                for item in items {
                    if let data = try? await item.loadTransferable(type: Data.self),
                       let image = UIImage(data: data) {
                        images.append(image)
                    }
                }
                vm.addFromLibrary(images)
                libraryItems = []
            }
        }
    }

    // MARK: - Extra Photos

    /// Any number of extra proof photos — salting and snow jobs routinely need four or more.
    @ViewBuilder
    private func extrasStrip() -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(vm.extraCount > 0 ? "More photos (\(vm.extraCount))" : "More photos")
                .font(.caption2.weight(.medium))
                .foregroundStyle(.secondary)

            if vm.extraCount > 0 {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(vm.remoteExtras) { photo in
                            AsyncImage(url: photo.thumbnailURL) { phase in
                                if let img = phase.image {
                                    img.resizable().scaledToFill()
                                } else {
                                    Color(.systemGray6)
                                }
                            }
                            .frame(width: 72, height: 72)
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                        }
                        ForEach(vm.localExtras) { extra in
                            Image(uiImage: extra.image)
                                .resizable()
                                .scaledToFill()
                                .frame(width: 72, height: 72)
                                .clipShape(RoundedRectangle(cornerRadius: 8))
                        }
                    }
                }
            }

            HStack(spacing: 10) {
                Button {
                    if captureGate?() == true { return }
                    vm.beginCapture(.additional)
                } label: {
                    Label("Take photos", systemImage: "camera.fill")
                        .font(.subheadline.weight(.semibold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 10)
                        .background(Color.MW.green.opacity(0.10))
                        .foregroundStyle(Color.MW.green)
                        .clipShape(RoundedRectangle(cornerRadius: 10))
                }
                .buttonStyle(.plain)

                if photosLocked {
                    // Special request not read yet: the tap shows it instead of the picker.
                    Button { _ = captureGate?() } label: { chooseLabel }
                        .buttonStyle(.plain)
                } else {
                    PhotosPicker(selection: $libraryItems, maxSelectionCount: 10, matching: .images) {
                        chooseLabel
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        .padding(.top, 4)
    }

    // MARK: - Photo Slot

    private func photoSlot(label: String, slot: JobPhotoType,
                           image: UIImage?, remote: VisitPhoto?, enabled: Bool) -> some View {
        let filled = image != nil || remote != nil
        return VStack(spacing: 6) {
            Button {
                guard enabled else { return }
                if captureGate?() == true { return }
                vm.beginCapture(slot)
            } label: {
                ZStack {
                    if let img = image {
                        Image(uiImage: img)
                            .resizable()
                            .scaledToFill()
                            .frame(maxWidth: .infinity)
                            .frame(height: 110)
                            .clipped()
                    } else if let remote {
                        // Taken earlier (this phone or another) — show what the server holds.
                        AsyncImage(url: remote.thumbnailURL) { phase in
                            if let img = phase.image {
                                img.resizable().scaledToFill()
                            } else {
                                Color.MW.green.opacity(0.08)
                                    .overlay { ProgressView().tint(Color.MW.green) }
                            }
                        }
                        .frame(maxWidth: .infinity)
                        .frame(height: 110)
                        .clipped()
                    } else {
                        Rectangle()
                            .fill(enabled
                                  ? Color.MW.green.opacity(0.08)
                                  : Color(.systemGray6))
                            .frame(maxWidth: .infinity)
                            .frame(height: 110)
                            .overlay {
                                VStack(spacing: 4) {
                                    Image(systemName: enabled
                                          ? "camera.badge.plus"
                                          : "lock.fill")
                                        .font(.title3)
                                        .foregroundStyle(enabled
                                                         ? Color.MW.green
                                                         : Color(.systemGray3))
                                    Text(label)
                                        .font(.caption.weight(.semibold))
                                        .foregroundStyle(enabled ? Color.MW.green : Color(.systemGray2))
                                    if !enabled {
                                        Text("Start job first")
                                            .font(.caption2)
                                            .foregroundStyle(.tertiary)
                                    }
                                }
                            }
                    }
                }
                // Label + retake sit ON the photo — a caption bar, not a row underneath — so the
                // tiles can be taller and nothing wraps ("Be-fore", "Re-take") on a narrow phone.
                .overlay(alignment: .bottom) {
                    if filled {
                        HStack(spacing: 4) {
                            Image(systemName: "checkmark.circle.fill")
                            Text(label).lineLimit(1)
                            Spacer(minLength: 2)
                            if enabled {
                                Image(systemName: "arrow.triangle.2.circlepath.camera.fill")
                                    .accessibilityLabel("Retake")
                            }
                        }
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(.white)
                        .padding(.horizontal, 8)
                        .padding(.top, 14)
                        .padding(.bottom, 6)
                        .background(
                            LinearGradient(colors: [.clear, .black.opacity(0.65)],
                                           startPoint: .top, endPoint: .bottom)
                        )
                    }
                }
                .clipShape(RoundedRectangle(cornerRadius: 10))
                .overlay(
                    RoundedRectangle(cornerRadius: 10)
                        .stroke(
                            filled
                                ? Color.MW.green.opacity(0.5)
                                : (enabled ? Color.MW.green.opacity(0.25) : Color(.systemGray5)),
                            lineWidth: filled ? 2 : 1
                        )
                )
            }
            .buttonStyle(.plain)
            .disabled(!enabled || vm.isUploading)
            .accessibilityLabel(filled ? "\(label) photo — tap to retake" : "Take \(label.lowercased()) photo")
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - Every before / after, by crew member

    /// The two slots above show one Before and one After. When several crew each took
    /// their own, nothing is hidden: every one is listed here with who took it.
    @ViewBuilder
    private func crewShotsStrip() -> some View {
        if vm.remoteProofShots.count > 1
            && (vm.remoteProofShots.filter { $0.photoType == "before" }.count > 1
                || vm.remoteProofShots.filter { $0.photoType == "after" }.count > 1) {
            VStack(alignment: .leading, spacing: 8) {
                Text("All before & after (\(vm.remoteProofShots.count))")
                    .font(.caption2.weight(.medium))
                    .foregroundStyle(.secondary)

                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(alignment: .top, spacing: 8) {
                        ForEach(vm.remoteProofShots) { photo in
                            VStack(alignment: .leading, spacing: 3) {
                                AsyncImage(url: photo.thumbnailURL) { phase in
                                    if let img = phase.image {
                                        img.resizable().scaledToFill()
                                    } else {
                                        Color(.systemGray6)
                                    }
                                }
                                .frame(width: 84, height: 84)
                                .clipShape(RoundedRectangle(cornerRadius: 8))
                                .overlay(alignment: .bottomLeading) {
                                    Text(photo.photoType.capitalized)
                                        .font(.system(size: 9, weight: .bold))
                                        .foregroundStyle(.white)
                                        .padding(.horizontal, 5)
                                        .padding(.vertical, 2)
                                        .background(.black.opacity(0.55), in: Capsule())
                                        .padding(4)
                                }

                                Text(photo.takenBy ?? "Unknown")
                                    .font(.caption2)
                                    .foregroundStyle(.secondary)
                                    .lineLimit(1)
                                    .frame(width: 84, alignment: .leading)
                            }
                        }
                    }
                }
            }
        }
    }

    // MARK: - Heart Endorsement Slot

    @ViewBuilder
    private func heartSlot() -> some View {
        VStack(spacing: 6) {
            Button {
                guard let toggle = onFlagToggle, !isFlagLoading else { return }
                Task { await toggle() }
            } label: {
                ZStack {
                    RoundedRectangle(cornerRadius: 10)
                        .fill(isFlagged
                              ? Color.MW.orange.opacity(0.12)
                              : Color(.systemGray6))
                        .frame(maxWidth: .infinity)
                        .frame(height: 110)
                    if isFlagLoading {
                        ProgressView()
                            .scaleEffect(0.85)
                            .tint(Color.MW.orange)
                    } else {
                        VStack(spacing: 4) {
                            Image(systemName: isFlagged ? "heart.fill" : "heart")
                                .font(.system(size: 28))
                            Text(isFlagged ? "Endorsed" : "Endorse")
                                .font(.caption.weight(.semibold))
                        }
                        .foregroundStyle(isFlagged ? Color.MW.orange : Color(.systemGray2))
                    }
                }
                .overlay(
                    RoundedRectangle(cornerRadius: 10)
                        .stroke(isFlagged
                                ? Color.MW.orange.opacity(0.4)
                                : Color(.systemGray5),
                                lineWidth: isFlagged ? 2 : 1)
                )
            }
            .buttonStyle(.plain)
            .scaleEffect(isFlagLoading ? 1.0 : 1.0)
            .disabled(isFlagLoading)

        }
        .frame(maxWidth: .infinity)
    }
}



// MARK: - Plant ID ("What is this plant?", 2026-10-15)
//
// Three photos of one plant — whole plant, closer, close-up — taken with the app's own camera
// (GPS written into each photo), plus the compass heading when the whole-plant shot was taken.
// They upload as visit photos ("other"), then POST /api/schedule/plant-id identifies them with
// Pl@ntNet on the visit's property and suggests the bed (inside it / facing it / near it).
// The crew picks the name and the bed; approved care from the plant library is shown back.

struct PlantMatchDTO: Decodable, Identifiable, Hashable {
    let scientific: String
    let common: String?
    let score: Double
    var id: String { scientific }
}

struct PlantBedRefDTO: Decodable, Hashable {
    let id: Int
    let name: String
    let sure: Bool?
}

struct PlantBedDTO: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
}

struct PlantRecordDTO: Decodable {
    let id: Int
    let status: String
    let scientificName: String?
    let commonName: String?
    let matches: [PlantMatchDTO]
    let bed: PlantBedRefDTO?

    enum CodingKeys: String, CodingKey {
        case id, status, matches, bed
        case scientificName = "scientific_name"
        case commonName = "common_name"
    }
}

struct PlantCareTaskDTO: Decodable, Hashable {
    let task: String
    let when: String
    let how: String?
}

struct PlantCareDTO: Decodable {
    let name: String?
    let general: Bool?
    let tasks: [PlantCareTaskDTO]
    let toxicPets: Bool?
    let toxicNote: String?
    let weed: Bool?
    let invasive: Bool?
    let invasiveNote: String?
    let safety: String?
    let disposal: String?

    enum CodingKeys: String, CodingKey {
        case name, general, tasks, weed, invasive, safety, disposal
        case toxicPets = "toxic_pets"
        case toxicNote = "toxic_note"
        case invasiveNote = "invasive_note"
    }
}

struct PlantIdResponse: Decodable {
    let ok: Bool
    let message: String?
    let record: PlantRecordDTO?
    let beds: [PlantBedDTO]?
    let care: PlantCareDTO?
}

/// The best GPS fix and the compass heading while the plant camera is open.
@MainActor
final class PlantFixReader: NSObject, ObservableObject, CLLocationManagerDelegate {
    @Published private(set) var best: CLLocation?
    @Published private(set) var heading: Double?
    private let manager = CLLocationManager()

    override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBest
    }

    func start() {
        manager.startUpdatingLocation()
        if CLLocationManager.headingAvailable() {
            manager.headingFilter = 3
            manager.startUpdatingHeading()
        }
    }

    func stop() {
        manager.stopUpdatingLocation()
        manager.stopUpdatingHeading()
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        Task { @MainActor in
            for l in locations where l.horizontalAccuracy >= 0 && l.timestamp.timeIntervalSinceNow > -30 {
                // Keep the most accurate recent fix (the first one is often the rough one).
                if let b = self.best, b.horizontalAccuracy <= l.horizontalAccuracy, b.timestamp.timeIntervalSinceNow > -60 { continue }
                self.best = l
            }
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateHeading newHeading: CLHeading) {
        guard newHeading.headingAccuracy >= 0 else { return }
        let value = newHeading.trueHeading >= 0 ? newHeading.trueHeading : newHeading.magneticHeading
        Task { @MainActor in self.heading = value }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {}
}

private struct PlantCameraPreview: UIViewRepresentable {
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

/// Holds an iOS background task for the length of a send, ending it once (either way it finishes).
@MainActor
final class BackgroundTaskHold {
    private var id: UIBackgroundTaskIdentifier = .invalid
    init(name: String) {
        id = UIApplication.shared.beginBackgroundTask(withName: name) { [weak self] in
            Task { @MainActor in self?.end() }
        }
    }
    func end() {
        guard id != .invalid else { return }
        UIApplication.shared.endBackgroundTask(id)
        id = .invalid
    }
}

struct PlantIdView: View {
    let visitId: Int
    let authSession: AuthSession
    let onClose: () -> Void

    private static let steps = [
        ("Whole plant", "Step back until the whole plant fills the frame. Stand still — this marks where it grows."),
        ("Closer", "Half the plant: stems, leaves and any flowers."),
        ("Close-up", "One leaf or flower, sharp and filling the frame."),
    ]

    @StateObject private var camera = CameraController()
    @StateObject private var fix = PlantFixReader()
    @State private var shots: [Data] = []
    @State private var wholeHeading: Double?
    @State private var busy = false
    @State private var status: String?
    @State private var result: PlantIdResponse?
    @State private var pick: String = ""
    @State private var other: String = ""
    @State private var bedId: Int = 0
    @State private var saved: PlantIdResponse?
    /// Media ids of the shots already uploaded, in order — a retry sends only the rest (2026-10-16).
    @State private var uploaded: [Int] = []
    /// The send stopped (no signal, or the app went to the background): the photos are kept for Try again.
    @State private var sendFailed = false
    @Environment(\.scenePhase) private var scenePhase

    var body: some View {
        Group {
            if let saved {
                doneView(saved)
            } else if let result, let record = result.record {
                pickView(result, record)
            } else {
                shootView
            }
        }
        .task {
            fix.start()
            await camera.start(videoMode: false)
        }
        // Back from the background after a send was cut off: carry on by itself.
        .onChange(of: scenePhase) { _, phase in
            if phase == .active, sendFailed, !busy, shots.count == 3 { Task { await identify() } }
        }
        .onDisappear {
            fix.stop()
            camera.stop()
        }
    }

    // MARK: Shooting

    private var shootView: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            PlantCameraPreview(camera: camera).ignoresSafeArea()
            VStack(spacing: 12) {
                HStack {
                    Button("Cancel") { onClose() }.foregroundStyle(.white)
                    Spacer()
                    Text(fixText).font(.caption).foregroundStyle(.white.opacity(0.9))
                }
                .padding(.horizontal)
                if shots.count < 3 {
                    VStack(alignment: .leading, spacing: 4) {
                        Text("\(shots.count + 1) of 3 — \(Self.steps[shots.count].0)").font(.headline)
                        Text(Self.steps[shots.count].1).font(.subheadline)
                    }
                    .foregroundStyle(.white)
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Color.black.opacity(0.55))
                    .clipShape(RoundedRectangle(cornerRadius: 10))
                    .padding(.horizontal)
                }
                Spacer()
                if let status {
                    Text(status).font(.callout).foregroundStyle(.white)
                        .padding(10).background(Color.black.opacity(0.6)).clipShape(RoundedRectangle(cornerRadius: 8))
                }
                HStack(spacing: 10) {
                    ForEach(Array(shots.enumerated()), id: \.offset) { _, data in
                        if let img = PhotoMetadata.thumbnail(from: data, maxPixel: 160) {
                            Image(uiImage: img).resizable().scaledToFill().frame(width: 52, height: 52).clipShape(RoundedRectangle(cornerRadius: 6))
                        }
                    }
                }
                if sendFailed && !busy {
                    HStack(spacing: 12) {
                        Button("Retake photos") { Task { await retake() } }
                            .buttonStyle(.bordered).tint(.white)
                        Button("Try again") { Task { await identify() } }
                            .buttonStyle(.borderedProminent)
                    }
                    .padding(.bottom, 24)
                } else {
                    Button(action: shoot) {
                        Circle().stroke(Color.white, lineWidth: 4).frame(width: 74, height: 74)
                            .overlay(Circle().fill(busy ? Color.gray : Color.white).frame(width: 60, height: 60))
                    }
                    .disabled(busy || shots.count >= 3)
                    .padding(.bottom, 24)
                }
            }
        }
    }

    private var fixText: String {
        guard let b = fix.best else { return "Finding location…" }
        return "Location ±\(Int(b.horizontalAccuracy.rounded())) m"
    }

    private func shoot() {
        guard !busy, shots.count < 3 else { return }
        busy = true
        if shots.isEmpty { wholeHeading = fix.heading }   // the direction the camera faced for the whole plant
        camera.capturePhoto(location: fix.best) { data in
            Task { @MainActor in
                busy = false
                guard let data else { status = "That photo didn't take — try again."; return }
                shots.append(data)
                if shots.count == 3 {
                    camera.stop()   // the camera's job is done — no indicator light during upload and picking
                    await identify()
                }
            }
        }
    }

    private func identify() async {
        guard !busy else { return }
        busy = true
        sendFailed = false
        // Switching apps mid-send: iOS lets a background task finish (about 30 s) instead of cutting it off.
        let bg = BackgroundTaskHold(name: "plant-id")
        defer { busy = false; bg.end() }
        let api = APIClient(authSession: authSession)
        do {
            // Only the shots not sent yet (a re-sent photo is recognised by the server anyway).
            for i in uploaded.count..<shots.count {
                status = "Uploading photo \(i + 1) of 3…"
                let data = shots[i]
                let res = try await api.uploadVisitPhoto(imageData: data, visitId: visitId, photoTypeRaw: "other")
                if let id = res["media_id"] as? Int { uploaded.append(id) }
                else if let s = res["media_id"] as? String, let id = Int(s) { uploaded.append(id) }
                else { throw APIError.serverError("A photo uploaded without an id") }
            }
            let ids = uploaded
            status = "Asking Pl@ntNet…"
            var body: [String: Any] = ["mode": "identify", "visit_id": visitId, "media_ids": ids]
            if let b = fix.best {
                body["lat"] = b.coordinate.latitude
                body["lng"] = b.coordinate.longitude
                body["accuracy"] = b.horizontalAccuracy
            }
            if let h = wholeHeading { body["heading"] = h }
            let res: PlantIdResponse = try await api.request(.plantId, body: body, timeout: 90)
            if res.ok, let rec = res.record {
                pick = rec.scientificName ?? (rec.matches.first?.scientific ?? "")
                bedId = rec.bed?.id ?? 0
                result = res
                status = nil
            } else {
                status = res.message ?? "Couldn't identify it — try again."
                await retake()
            }
        } catch {
            // Keep the photos: Try again sends what is left (or it carries on by itself when the app comes back).
            status = "Couldn't send — your photos are kept. Tap Try again."
            sendFailed = true
        }
    }

    /// Back to shooting: clear the shots and turn the camera on again.
    private func retake() async {
        shots = []
        uploaded = []
        sendFailed = false
        status = nil
        await camera.start(videoMode: false)
    }

    // MARK: Picking the name and the bed

    private func pickView(_ res: PlantIdResponse, _ rec: PlantRecordDTO) -> some View {
        NavigationStack {
            Form {
                if let m = res.message, !m.isEmpty { Section { Text(m).font(.headline) } }
                Section("What is it?") {
                    ForEach(rec.matches) { m in
                        Button {
                            pick = m.scientific
                        } label: {
                            HStack {
                                VStack(alignment: .leading) {
                                    Text(m.common?.isEmpty == false ? m.common! : m.scientific)
                                    Text(m.scientific).font(.caption).italic().foregroundStyle(.secondary)
                                }
                                Spacer()
                                Text("\(Int((m.score * 100).rounded()))%").foregroundStyle(.secondary)
                                if pick == m.scientific { Image(systemName: "checkmark").foregroundStyle(Color.MW.green) }
                            }
                        }
                        .foregroundStyle(.primary)
                    }
                    TextField("Something else (type the name)", text: $other)
                        .onChange(of: other) { _, v in if !v.isEmpty { pick = "" } }
                }
                Section("Which bed?") {
                    Picker("Bed", selection: $bedId) {
                        Text("No bed / not drawn yet").tag(0)
                        ForEach(res.beds ?? []) { b in Text(b.name).tag(b.id) }
                    }
                    if let b = rec.bed, b.sure != true {
                        Text("Probably \(b.name) — from where you stood. Change it if not.").font(.caption).foregroundStyle(.secondary)
                    }
                }
                if let status { Section { Text(status).foregroundStyle(.orange) } }
            }
            .navigationTitle("Plant")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Close") { onClose() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { Task { await confirm(rec) } }.disabled(busy || (pick.isEmpty && other.trimmingCharacters(in: .whitespaces).isEmpty))
                }
            }
        }
    }

    private func confirm(_ rec: PlantRecordDTO) async {
        busy = true
        defer { busy = false }
        var body: [String: Any] = ["mode": "confirm", "id": rec.id, "measurement_id": bedId]
        if pick.isEmpty { body["common_name"] = other.trimmingCharacters(in: .whitespaces) } else { body["scientific_name"] = pick }
        do {
            let res: PlantIdResponse = try await APIClient(authSession: authSession).request(.plantId, body: body)
            if res.ok { saved = res } else { status = res.message ?? "Couldn't save it." }
        } catch {
            status = "Couldn't save it — check the signal."
        }
    }

    // MARK: Done — with its care, when the library has an approved card

    private func doneView(_ res: PlantIdResponse) -> some View {
        NavigationStack {
            List {
                Section {
                    Text(res.record?.commonName ?? res.record?.scientificName ?? "Saved").font(.headline)
                    if let b = res.record?.bed { Text("In \(b.name)").foregroundStyle(.secondary) }
                }
                if let care = res.care, care.weed == true {
                    Section {
                        Label(care.invasive == true ? "Weed — invasive in BC" : "Weed", systemImage: "exclamationmark.octagon")
                            .foregroundStyle(care.invasive == true ? Color.MW.red : Color.MW.orange)
                        if let n = care.invasiveNote, !n.isEmpty { Text(n).font(.caption) }
                        if let s = care.safety, !s.isEmpty { Label(s, systemImage: "hand.raised").font(.caption).foregroundStyle(Color.MW.red) }
                        if let d = care.disposal, !d.isEmpty { Label(d, systemImage: "trash").font(.caption) }
                    }
                }
                if let care = res.care, !care.tasks.isEmpty {
                    Section(care.weed == true ? "How to remove it" : (care.general == true ? "Care (general for this kind)" : "Care")) {
                        ForEach(care.tasks, id: \.self) { t in
                            VStack(alignment: .leading, spacing: 2) {
                                HStack { Text(t.task).bold(); Spacer(); Text(t.when).foregroundStyle(.secondary) }
                                if let how = t.how, !how.isEmpty { Text(how).font(.caption).foregroundStyle(.secondary) }
                            }
                        }
                        if care.toxicPets == true {
                            Label(care.toxicNote?.isEmpty == false ? care.toxicNote! : "Toxic to pets", systemImage: "exclamationmark.triangle")
                                .foregroundStyle(Color.MW.red)
                        }
                    }
                } else {
                    Section { Text("Its care card isn't in the library yet — the office will add it.").font(.caption).foregroundStyle(.secondary) }
                }
            }
            .navigationTitle("Saved")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done") { onClose() } } }
        }
    }
}
