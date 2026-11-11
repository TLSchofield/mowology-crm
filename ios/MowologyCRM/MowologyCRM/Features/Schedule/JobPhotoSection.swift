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
import UserNotifications

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
            // 1920 px / ~0.2-1.5 MB (same as receipts): full-size library photos timed out on weak signal.
            let data = ReceiptsView.resizeAndCompress(image)
            guard !data.isEmpty else { continue }
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
                errorMessage = ErrorDetails.describe(err)
            }
        } catch {
            AppDiagnostics.shared.record(category: "JobPhotoSection", message: ErrorDetails.describe(error))
            errorMessage = ErrorDetails.describe(error)
        }
    }
}

// MARK: - JobPhotoSection View

/// Embeds inside a visit card to provide the before/after photo proof UI.
/// Pass `isActive` true once the job has started (timer running OR visit in progress — an
/// auto-stopped or reopened visit has no live timer but is still mid-job) to unlock After.
struct JobPhotoSection: View {
    @Environment(\.itAppStyle) private var appStyle

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

    @ViewBuilder
    private func plantLine(_ it: PlantSendQueue.Item) -> some View {
        HStack(spacing: 10) {
            Image(systemName: it.state == "failed" ? "exclamationmark.circle" : "leaf")
                .foregroundStyle(it.state == "failed" ? appStyle.attention : appStyle.tenant)
            VStack(alignment: .leading, spacing: 2) {
                switch it.state {
                case "ready" where it.response?.record?.choose == true && (it.response?.record?.matches.count ?? 0) >= 2:
                    Text("Not sure — which is it?").font(appStyle.font(.subheadline, weight: .semibold))
                    ForEach(Array((it.response?.record?.matches ?? []).prefix(2))) { m in
                        Button { plantPick = m.scientific; plantToConfirm = it } label: { plantChoice(m) }
                            .buttonStyle(.plain)
                            .accessibilityLabel("\(m.name), \(Int((m.score * 100).rounded())) percent. Tap if it is this one.")
                    }
                    Button("Something else, or leave it for the office") { plantPick = nil; plantToConfirm = it }
                        .font(appStyle.font(.caption)).foregroundStyle(appStyle.text).underline().frame(minHeight: 44)
                case "ready":
                    Text("Plant: \(it.answerLine ?? "identified")").font(appStyle.font(.subheadline, weight: .semibold))
                    Text("Confirm it, or leave it for the office.").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                case "failed":
                    Text("Plant not identified").font(appStyle.font(.subheadline, weight: .semibold))
                    Text(it.message ?? "Try again with closer photos.").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                default:
                    Text("Plant: identifying…").font(appStyle.font(.subheadline, weight: .semibold))
                    Text(it.mediaIds.count < it.files.count ? "Sending photos — it carries on by itself." : "Asking Pl@ntNet.")
                        .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                }
            }
            Spacer(minLength: 4)
            switch it.state {
            case "ready" where it.response?.record?.choose == true && (it.response?.record?.matches.count ?? 0) >= 2:
                EmptyView()   // the choices above are the buttons
            case "ready":
                Button("Confirm") { plantPick = nil; plantToConfirm = it }
                    .buttonStyle(.borderedProminent).tint(appStyle.tenant).frame(minHeight: 44)
            case "failed":
                Button("Clear") { PlantSendQueue.shared.remove(it.id) }
                    .buttonStyle(.bordered).tint(appStyle.meta).frame(minHeight: 44)
            default:
                ProgressView().tint(appStyle.tenant)
            }
        }
        .padding(12)
        .background(appStyle.sunk, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
    }

    /// One choice: Pl@ntNet's reference photo, the name and how sure it is, the photo's credit (CC BY-SA requires it).
    private func plantChoice(_ m: PlantMatchDTO) -> some View {
        HStack(spacing: 10) {
            PlantRefPhoto(url: m.photo, size: 56)
            VStack(alignment: .leading, spacing: 1) {
                Text(m.name).font(appStyle.font(.subheadline, weight: .semibold)).foregroundStyle(appStyle.text)
                Text("\(m.scientific) · \(Int((m.score * 100).rounded()))%").font(appStyle.font(.caption)).italic().foregroundStyle(appStyle.meta)
                if let c = m.photoCredit { Text(c).font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta).lineLimit(1) }
            }
            Spacer(minLength: 0)
            Image(systemName: "chevron.right").foregroundStyle(appStyle.meta)
        }
        .padding(8)
        .frame(minHeight: 48)
        .background(appStyle.surface, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
        .contentShape(Rectangle())
    }

    private var chooseLabel: some View {
        Label("Choose", systemImage: "photo.on.rectangle")
            .font(appStyle.font(.subheadline, weight: .semibold))
            .frame(maxWidth: .infinity)
            .padding(.vertical, 10)
            .frame(minHeight: 48)
            .background(appStyle.sunk)
            .foregroundStyle(appStyle.text)
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
    }

    @StateObject private var vm: JobPhotoViewModel
    @State private var libraryItems: [PhotosPickerItem] = []
    @State private var showPlantId = false
    @ObservedObject private var plantSends = PlantSendQueue.shared
    @State private var plantToConfirm: PlantSendQueue.Item?
    /// The choice tapped on the card (opens the confirm screen with it picked); nil = let the person pick there.
    @State private var plantPick: String?

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
                .font(appStyle.font(.caption, weight: .semibold))
                .foregroundStyle(appStyle.meta)
                .textCase(.uppercase)

            // Upload progress indicator
            if vm.isUploading {
                HStack(spacing: 6) {
                    ProgressView().scaleEffect(0.75).tint(appStyle.tenant)
                    Text("Uploading…")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                }
            }

            // Queued / pending sync banner (no signal at capture time)
            if vm.beforePendingSync || vm.afterPendingSync || vm.extrasPendingSync > 0 {
                HStack(spacing: 6) {
                    Image(systemName: "arrow.triangle.2.circlepath")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.tenant)
                    Text(vm.extrasPendingSync > 1
                         ? "\(vm.extrasPendingSync) photos saved — will upload when signal returns"
                         : "Photo saved — will upload when signal returns")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.tenant)
                }
                .padding(8)
                .background(appStyle.tenant.opacity(0.08))
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
            }

            // Error banner (server errors, not network-offline)
            if let err = vm.errorMessage {
                HStack(alignment: .top, spacing: 6) {
                    Image(systemName: "exclamationmark.triangle.fill")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.attention)
                    Text(err)
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.attention)
                        .fixedSize(horizontal: false, vertical: true)
                }
                .padding(8)
                .background(appStyle.attention.opacity(0.08))
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
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
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.attention)
                    .lineLimit(2)
            }

            crewShotsStrip()

            extrasStrip()

            if !photosLocked {
                Button {
                    showPlantId = true
                } label: {
                    Label("What is this plant?", systemImage: "leaf")
                        .font(appStyle.font(.subheadline, weight: .semibold))
                }
                .buttonStyle(.bordered)
                .tint(appStyle.tenant)
            }

            // Plants sent from this job (PlantSendQueue): identifying → the answer → confirm (optional).
            ForEach(plantSends.sent(forVisit: visitId, userId: authSession.user?.id)) { it in
                plantLine(it)
            }
        }
        .fullScreenCover(isPresented: $showPlantId) {
            PlantIdView(visitId: visitId, authSession: authSession) { showPlantId = false }
        }
        .fullScreenCover(item: $plantToConfirm) { it in
            PlantIdView(visitId: visitId, authSession: authSession, preset: it.response, presetItemId: it.id, presetPick: plantPick) { plantToConfirm = nil }
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
                .font(appStyle.font(.caption2, weight: .medium))
                .foregroundStyle(appStyle.meta)

            if vm.extraCount > 0 {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(vm.remoteExtras) { photo in
                            AsyncImage(url: photo.thumbnailURL) { phase in
                                if let img = phase.image {
                                    img.resizable().scaledToFill()
                                } else {
                                    appStyle.sunk
                                }
                            }
                            .frame(width: 72, height: 72)
                            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
                        }
                        ForEach(vm.localExtras) { extra in
                            Image(uiImage: extra.image)
                                .resizable()
                                .scaledToFill()
                                .frame(width: 72, height: 72)
                                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
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
                        .font(appStyle.font(.subheadline, weight: .semibold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 10)
                        .frame(minHeight: 48)
                        .background(appStyle.tenant.opacity(0.10))
                        .foregroundStyle(appStyle.tenant)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
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
                                appStyle.tenant.opacity(0.08)
                                    .overlay { ProgressView().tint(appStyle.tenant) }
                            }
                        }
                        .frame(maxWidth: .infinity)
                        .frame(height: 110)
                        .clipped()
                    } else {
                        Rectangle()
                            .fill(enabled
                                  ? appStyle.tenant.opacity(0.08)
                                  : appStyle.sunk)
                            .frame(maxWidth: .infinity)
                            .frame(height: 110)
                            .overlay {
                                VStack(spacing: 4) {
                                    Image(systemName: enabled
                                          ? "camera.badge.plus"
                                          : "lock.fill")
                                        .font(appStyle.font(.title3))
                                        .foregroundStyle(enabled
                                                         ? appStyle.tenant
                                                         : (appStyle.isEnabled ? appStyle.meta : Color(.systemGray3)))
                                    Text(label)
                                        .font(appStyle.font(.caption, weight: .semibold))
                                        .foregroundStyle(enabled ? appStyle.tenant : (appStyle.isEnabled ? appStyle.meta : Color(.systemGray2)))
                                    if !enabled {
                                        Text("Start job first")
                                            .font(appStyle.font(.caption2))
                                            .foregroundStyle(appStyle.isEnabled ? appStyle.meta : Color(.tertiaryLabel))
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
                        .font(appStyle.font(.caption2, weight: .semibold))
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
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
                .overlay(
                    RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                        .stroke(
                            filled
                                ? appStyle.tenant.opacity(0.5)
                                : (enabled ? appStyle.tenant.opacity(0.25) : (appStyle.isEnabled ? appStyle.line : Color(.systemGray5))),
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
                    .font(appStyle.font(.caption2, weight: .medium))
                    .foregroundStyle(appStyle.meta)

                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(alignment: .top, spacing: 8) {
                        ForEach(vm.remoteProofShots) { photo in
                            VStack(alignment: .leading, spacing: 3) {
                                AsyncImage(url: photo.thumbnailURL) { phase in
                                    if let img = phase.image {
                                        img.resizable().scaledToFill()
                                    } else {
                                        appStyle.sunk
                                    }
                                }
                                .frame(width: 84, height: 84)
                                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
                                .overlay(alignment: .bottomLeading) {
                                    Text(photo.photoType.capitalized)
                                        .font(appStyle.font(size: 9, weight: .bold))
                                        .foregroundStyle(.white)
                                        .padding(.horizontal, 5)
                                        .padding(.vertical, 2)
                                        .background(.black.opacity(0.55), in: Capsule())
                                        .padding(4)
                                }

                                Text(photo.takenBy ?? "Unknown")
                                    .font(appStyle.font(.caption2))
                                    .foregroundStyle(appStyle.meta)
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
                    RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                        .fill(isFlagged
                              ? appStyle.attention.opacity(0.12)
                              : appStyle.sunk)
                        .frame(maxWidth: .infinity)
                        .frame(height: 110)
                    if isFlagLoading {
                        ProgressView()
                            .scaleEffect(0.85)
                            .tint(appStyle.attention)
                    } else {
                        VStack(spacing: 4) {
                            Image(systemName: isFlagged ? "heart.fill" : "heart")
                                .font(appStyle.font(size: 28))
                            Text(isFlagged ? "Endorsed" : "Endorse")
                                .font(appStyle.font(.caption, weight: .semibold))
                        }
                        .foregroundStyle(isFlagged ? appStyle.attention : (appStyle.isEnabled ? appStyle.meta : Color(.systemGray2)))
                    }
                }
                .overlay(
                    RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                        .stroke(isFlagged
                                ? appStyle.attention.opacity(0.4)
                                : (appStyle.isEnabled ? appStyle.line : Color(.systemGray5)),
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



// MARK: - Plant sender (snap and walk away, owner 2026-10-19)
//
// "Once the 3rd photo is taken the snap button should change to Submit and the screen should transition
// to a thinking screen that says submitted, so the user can continue working (it takes a while to ID)."
// The camera hands the three photos here and closes; this sends them and asks Pl@ntNet with no screen
// open. Photos wait on the phone (QueueStorage) until sent, so no signal, a locked phone or a closed app
// only delays it — the app resumes it on open and on reconnect (MowologyCRMApp, like job photos).
// The answer comes back as a notification and a line on the job card; confirming it is optional.
// Same rules as JobPhotoQueue: one run at a time; no signal / expired login keeps everything; a server
// fault backs off and gives up after 5, shown on the card; re-sending the same photos is safe (the server
// answers a re-sent set with its existing record).
@MainActor
final class PlantSendQueue: ObservableObject {
    static let shared = PlantSendQueue()

    struct Item: Codable, Identifiable {
        let id: String
        let visitId: Int
        let userId: Int?
        let files: [String]              // QueueStorage filenames: whole, closer, close-up
        var mediaIds: [Int] = []         // shots already uploaded, in order
        let lat: Double?
        let lng: Double?
        let accuracy: Double?
        let heading: Double?
        let queuedAt: Date
        var attempts = 0
        var nextTryAt: Date?
        var state = "sending"            // sending | ready | failed
        var message: String?
        var response: PlantIdResponse?

        /// "Bigleaf Hydrangea (86%)", from the best match.
        var answerLine: String? {
            guard let m = response?.record?.matches.first else { return nil }
            let name = (m.common?.isEmpty == false ? m.common! : m.scientific)
            return "\(name) (\(Int((m.score * 100).rounded()))%)"
        }
    }

    @Published private(set) var items: [Item] = []
    private let storeKey = "mw.plantsend.queue.v1"
    private let maxAttempts = 5
    private var apiClient: APIClient?
    private var running: Task<Void, Never>?

    private init() {
        if let data = UserDefaults.standard.data(forKey: storeKey),
           let saved = try? JSONDecoder().decode([Item].self, from: data) {
            // Answers are kept two days for the job card; anything older has been seen or dealt with.
            items = saved.filter { $0.state == "sending" || $0.queuedAt > Date().addingTimeInterval(-2 * 86_400) }
        }
        // Signal back (the app's reconnect ping, as for job photos): send what is waiting.
        NotificationCenter.default.addObserver(forName: .mwPingQueueOnline, object: nil, queue: .main) { _ in
            Task { @MainActor in await PlantSendQueue.shared.drain() }
        }
    }

    private func save() { UserDefaults.standard.set(try? JSONEncoder().encode(items), forKey: storeKey) }

    func configure(apiClient: APIClient) { self.apiClient = apiClient }

    func sent(forVisit visitId: Int, userId: Int?) -> [Item] {
        items.filter { $0.visitId == visitId && ($0.userId == nil || $0.userId == userId) }
    }

    /// The camera's three shots. Returns false when the phone could not keep them (no storage).
    @discardableResult
    func submit(shots: [Data], visitId: Int, userId: Int?, fix: CLLocation?, heading: Double?, apiClient: APIClient) -> Bool {
        let id = UUID().uuidString
        var files: [String] = []
        for (i, data) in shots.enumerated() {
            let name = "plant-\(id)-\(i).jpg"
            guard QueueStorage.write(data, filename: name) else { files.forEach(QueueStorage.remove); return false }
            files.append(name)
        }
        items.append(Item(id: id, visitId: visitId, userId: userId, files: files,
                          lat: fix?.coordinate.latitude, lng: fix?.coordinate.longitude,
                          accuracy: fix?.horizontalAccuracy, heading: heading, queuedAt: Date()))
        save()
        self.apiClient = apiClient
        Task { await drain() }
        return true
    }

    /// The owner confirmed it, or cleared a failed one, on the job card.
    func remove(_ id: String) {
        if let it = items.first(where: { $0.id == id }) { it.files.forEach(QueueStorage.remove) }
        items.removeAll { $0.id == id }
        save()
    }

    func drain() async {
        if let running { await running.value; return }
        let task = Task { await drainOnce() }
        running = task
        await task.value
        running = nil
    }

    private func drainOnce() async {
        guard let api = apiClient else { return }
        let me = api.currentUserId
        let bg = BackgroundTaskHold(name: "plant-send")
        defer { bg.end() }
        for id in items.filter({ $0.state == "sending" }).map(\.id) {
            guard var it = items.first(where: { $0.id == id }) else { continue }
            if let owner = it.userId, owner != me { continue }                 // only its taker's login sends it
            if let next = it.nextTryAt, next > Date() { continue }
            do {
                for i in it.mediaIds.count..<it.files.count {
                    guard let data = QueueStorage.read(it.files[i]) else { throw APIError.serverError("A plant photo is missing from this phone") }
                    let res = try await api.uploadVisitPhoto(imageData: data, visitId: it.visitId, photoTypeRaw: "other", queuedAt: it.queuedAt)
                    if let mid = res["media_id"] as? Int { it.mediaIds.append(mid) }
                    else if let s = res["media_id"] as? String, let mid = Int(s) { it.mediaIds.append(mid) }
                    else { throw APIError.serverError("A photo uploaded without an id") }
                    update(it)                                                // a resend sends only the rest
                }
                var body: [String: Any] = ["mode": "identify", "visit_id": it.visitId, "media_ids": it.mediaIds]
                if let v = it.lat { body["lat"] = v }
                if let v = it.lng { body["lng"] = v }
                if let v = it.accuracy { body["accuracy"] = v }
                if let v = it.heading { body["heading"] = v }
                let res: PlantIdResponse = try await api.request(.plantId, body: body, timeout: 90)
                if res.ok, res.record != nil {
                    it.state = "ready"; it.response = res; it.message = res.message
                } else {
                    it.state = "failed"; it.message = res.message ?? "Pl@ntNet couldn't tell what it is. Try closer photos."
                }
                it.files.forEach(QueueStorage.remove)                          // sent: the server has the photos
                update(it)
                notify(it)
            } catch let err as APIError {
                switch err {
                case .networkError, .unauthorized:
                    update(it)
                    return                                                     // no signal / signed out: keep everything
                default:
                    it.attempts += 1
                    if it.attempts >= maxAttempts {
                        it.state = "failed"; it.message = err.localizedDescription
                        notify(it)
                    } else {
                        it.nextTryAt = Date().addingTimeInterval(60 * pow(2, Double(it.attempts)))
                    }
                    update(it)
                }
            } catch {
                update(it)
                return
            }
        }
    }

    private func update(_ it: Item) {
        if let i = items.firstIndex(where: { $0.id == it.id }) { items[i] = it; save() }
    }

    private func notify(_ it: Item) {
        let c = UNMutableNotificationContent()
        if it.state == "ready", let line = it.answerLine {
            c.title = "Plant identified"
            c.body = "It looks like \(line). Open the job to confirm it, or leave it for the office."
        } else {
            c.title = "Plant not identified"
            c.body = it.message ?? "Try the plant again with closer photos."
        }
        c.sound = .default
        c.userInfo = ["visit_id": it.visitId]
        UNUserNotificationCenter.current().add(UNNotificationRequest(identifier: "plant-\(it.id)", content: c, trigger: nil))
    }
}

// MARK: - Plant ID ("What is this plant?", 2026-10-15)
//
// Three photos of one plant — whole plant, closer, close-up — taken with the app's own camera
// (GPS written into each photo), plus the compass heading when the whole-plant shot was taken.
// They upload as visit photos ("other"), then POST /api/schedule/plant-id identifies them with
// Pl@ntNet on the visit's property and suggests the bed (inside it / facing it / near it).
// The crew picks the name and the bed; approved care from the plant library is shown back.

struct PlantMatchDTO: Codable, Identifiable, Hashable {
    let scientific: String
    let common: String?
    let score: Double
    /// Pl@ntNet's reference photo of this species (CC BY-SA) and its required credit — 2026-10-20.
    let photo: String?
    let photoCredit: String?
    var id: String { scientific }
    var name: String { common?.isEmpty == false ? common! : scientific }

    enum CodingKeys: String, CodingKey {
        case scientific, common, score, photo
        case photoCredit = "photo_credit"
    }
}

struct PlantBedRefDTO: Codable, Hashable {
    let id: Int
    let name: String
    let sure: Bool?
}

struct PlantBedDTO: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
}

struct PlantRecordDTO: Codable {
    let id: Int
    let status: String
    let scientificName: String?
    let commonName: String?
    let matches: [PlantMatchDTO]
    let bed: PlantBedRefDTO?
    /// Not sure (best under 50%, or the top two close): offer the top matches as choices, not one Confirm.
    let choose: Bool?

    enum CodingKeys: String, CodingKey {
        case id, status, matches, bed, choose
        case scientificName = "scientific_name"
        case commonName = "common_name"
    }
}

struct PlantCareTaskDTO: Codable, Hashable {
    let task: String
    let when: String
    let how: String?
}

struct PlantCareDTO: Codable {
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

struct PlantIdResponse: Codable {
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
    @Environment(\.itAppStyle) private var appStyle
    let visitId: Int
    let authSession: AuthSession
    /// An answer the sender already has (PlantSendQueue): open straight at "What is it?" — no camera.
    var preset: PlantIdResponse? = nil
    var presetItemId: String? = nil
    /// The match tapped on the job card — opens already picked.
    var presetPick: String? = nil
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
    /// Handed to PlantSendQueue: the "Submitted" screen shows, then closes by itself.
    @State private var submitted = false

    var body: some View {
        Group {
            if let saved {
                doneView(saved)
            } else if let result, let record = result.record {
                pickView(result, record)
            } else if submitted {
                submittedView
            } else {
                shootView
            }
        }
        .task {
            if let preset, let rec = preset.record {
                pick = presetPick ?? rec.scientificName ?? (rec.choose == true ? "" : (rec.matches.first?.scientific ?? ""))
                bedId = rec.bed?.id ?? 0
                result = preset
                return
            }
            fix.start()
            await camera.start(videoMode: false)
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
                    Text(fixText).font(appStyle.font(.caption)).foregroundStyle(.white.opacity(0.9))
                }
                .padding(.horizontal)
                if shots.count < 3 {
                    VStack(alignment: .leading, spacing: 4) {
                        Text("\(shots.count + 1) of 3 — \(Self.steps[shots.count].0)").font(appStyle.font(.headline))
                        Text(Self.steps[shots.count].1).font(appStyle.font(.subheadline))
                    }
                    .foregroundStyle(.white)
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Color.black.opacity(0.55))
                    .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
                    .padding(.horizontal)
                }
                Spacer()
                if let status {
                    Text(status).font(appStyle.font(.callout)).foregroundStyle(.white)
                        .padding(10).background(Color.black.opacity(0.6)).clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
                }
                HStack(spacing: 10) {
                    ForEach(Array(shots.enumerated()), id: \.offset) { _, data in
                        if let img = PhotoMetadata.thumbnail(from: data, maxPixel: 160) {
                            Image(uiImage: img).resizable().scaledToFill().frame(width: 52, height: 52).clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(6)))
                        }
                    }
                }
                if shots.count == 3 {
                    // All three taken: the shutter becomes Submit (owner, 2026-10-19).
                    HStack(spacing: 12) {
                        Button("Retake") { Task { await retake() } }
                            .font(appStyle.font(.headline))
                            .frame(minWidth: 110, minHeight: 56)
                            .buttonStyle(.bordered).tint(.white)
                        Button(action: submit) {
                            Text("Submit").font(appStyle.font(.headline))
                                .frame(minWidth: 170, minHeight: 56)
                        }
                        .buttonStyle(.borderedProminent).tint(appStyle.tenant)
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
                    camera.stop()   // the camera's job is done — Submit or Retake
                }
            }
        }
    }

    /// Hand the three photos to the sender and let the crew get back to work.
    private func submit() {
        let ok = PlantSendQueue.shared.submit(shots: shots, visitId: visitId, userId: authSession.user?.id,
                                              fix: fix.best, heading: wholeHeading, apiClient: APIClient(authSession: authSession))
        guard ok else { status = "This phone is out of space, so the photos couldn't be kept. Free some space and try again."; return }
        fix.stop()
        submitted = true
        Task {
            try? await Task.sleep(for: .seconds(4))
            onClose()
        }
    }

    // MARK: Submitted — the "thinking" screen

    private var submittedView: some View {
        ZStack {
            appStyle.page.ignoresSafeArea()
            VStack(spacing: 18) {
                Spacer()
                ProgressView().controlSize(.large).tint(appStyle.tenant)
                Text("Submitted").font(appStyle.font(.title2, weight: .bold)).foregroundStyle(appStyle.text)
                Text("Identifying the plant. Carry on working — the answer comes to this job and as a notification.")
                    .font(appStyle.font(.body)).foregroundStyle(appStyle.meta)
                    .multilineTextAlignment(.center).padding(.horizontal, 32)
                HStack(spacing: 10) {
                    ForEach(Array(shots.enumerated()), id: \.offset) { _, data in
                        if let img = PhotoMetadata.thumbnail(from: data, maxPixel: 160) {
                            Image(uiImage: img).resizable().scaledToFill().frame(width: 64, height: 64)
                                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
                        }
                    }
                }
                Spacer()
                Button { onClose() } label: {
                    Text("Done").font(appStyle.font(.headline)).frame(maxWidth: .infinity, minHeight: 56)
                }
                .buttonStyle(.borderedProminent).tint(appStyle.tenant)
                .padding(.horizontal, 24).padding(.bottom, 24)
            }
        }
    }

    /// Back to shooting: clear the shots and turn the camera on again.
    private func retake() async {
        shots = []
        status = nil
        await camera.start(videoMode: false)
    }

    // MARK: Picking the name and the bed

    private func pickView(_ res: PlantIdResponse, _ rec: PlantRecordDTO) -> some View {
        NavigationStack {
            Form {
                if let m = res.message, !m.isEmpty { Section { Text(m).font(appStyle.font(.headline)) } }
                Section("What is it?") {
                    ForEach(rec.matches) { m in
                        Button {
                            pick = m.scientific
                        } label: {
                            HStack(spacing: 10) {
                                PlantRefPhoto(url: m.photo, size: 48)
                                VStack(alignment: .leading) {
                                    Text(m.name)
                                    Text(m.scientific).font(appStyle.font(.caption)).italic().foregroundStyle(appStyle.meta)
                                    if let c = m.photoCredit { Text(c).font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta).lineLimit(1) }
                                }
                                Spacer()
                                Text("\(Int((m.score * 100).rounded()))%").foregroundStyle(appStyle.meta)
                                if pick == m.scientific { Image(systemName: "checkmark").foregroundStyle(appStyle.tenant) }
                            }
                        }
                        .foregroundStyle(appStyle.text)
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
                        Text("Probably \(b.name) — from where you stood. Change it if not.").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    }
                }
                if let status { Section { Text(status).foregroundStyle(appStyle.attention) } }
            }
            .itAppForm()
            .itAppScreen()
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
            if res.ok {
                saved = res
                if let presetItemId { PlantSendQueue.shared.remove(presetItemId) }   // dealt with: off the job card
            } else { status = res.message ?? "Plant save response ok=false; server returned no message field." }
        } catch {
            AppDiagnostics.shared.record(category: "JobPhotoSection", message: ErrorDetails.describe(error))
            status = ErrorDetails.describe(error)
        }
    }

    // MARK: Done — with its care, when the library has an approved card

    private func doneView(_ res: PlantIdResponse) -> some View {
        NavigationStack {
            List {
                Section {
                    Text(res.record?.commonName ?? res.record?.scientificName ?? "Saved").font(appStyle.font(.headline))
                    if let b = res.record?.bed { Text("In \(b.name)").foregroundStyle(appStyle.meta) }
                }
                if let care = res.care, care.weed == true {
                    Section {
                        Label(care.invasive == true ? "Weed — invasive in BC" : "Weed", systemImage: "exclamationmark.octagon")
                            .foregroundStyle(care.invasive == true ? appStyle.blocked : appStyle.attention)
                        if let n = care.invasiveNote, !n.isEmpty { Text(n).font(appStyle.font(.caption)) }
                        if let s = care.safety, !s.isEmpty { Label(s, systemImage: "hand.raised").font(appStyle.font(.caption)).foregroundStyle(appStyle.blocked) }
                        if let d = care.disposal, !d.isEmpty { Label(d, systemImage: "trash").font(appStyle.font(.caption)) }
                    }
                }
                if let care = res.care, !care.tasks.isEmpty {
                    Section(care.weed == true ? "How to remove it" : (care.general == true ? "Care (general for this kind)" : "Care")) {
                        ForEach(care.tasks, id: \.self) { t in
                            VStack(alignment: .leading, spacing: 2) {
                                HStack { Text(t.task).bold(); Spacer(); Text(t.when).foregroundStyle(appStyle.meta) }
                                if let how = t.how, !how.isEmpty { Text(how).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta) }
                            }
                        }
                        if care.toxicPets == true {
                            Label(care.toxicNote?.isEmpty == false ? care.toxicNote! : "Toxic to pets", systemImage: "exclamationmark.triangle")
                                .foregroundStyle(appStyle.blocked)
                        }
                    }
                } else {
                    Section { Text("Its care card isn't in the library yet — the office will add it.").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta) }
                }
            }
            .itAppForm()
            .itAppScreen()
            .navigationTitle("Saved")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done") { onClose() } } }
        }
    }
}

/// Pl@ntNet's reference photo of a species: a small square, a quiet leaf while it loads or when there is none.
struct PlantRefPhoto: View {
    @Environment(\.itAppStyle) private var appStyle
    let url: String?
    let size: CGFloat

    var body: some View {
        Group {
            if let url, let u = URL(string: url) {
                AsyncImage(url: u) { phase in
                    if let img = phase.image { img.resizable().scaledToFill() } else { placeholder }
                }
            } else { placeholder }
        }
        .frame(width: size, height: size)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
        .accessibilityHidden(true)
    }

    private var placeholder: some View {
        ZStack { appStyle.sunk; Image(systemName: "leaf").foregroundStyle(appStyle.meta) }
    }
}

