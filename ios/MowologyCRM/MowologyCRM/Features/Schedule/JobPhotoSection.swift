//
//  JobPhotoSection.swift
//  MowologyCRM
//
//  Self-contained before/after photo proof component for a single visit.
//  Owns its own ViewModel so it can be embedded inside VisitDetailView's
//  ForEach(visits) without needing an external state manager.
//
//  Mirrors the Capacitor schedule-pill-workflow.js photo state machine:
//    - Two slots: "before" and "after"
//    - Each captured immediately on camera dismiss (no confirm step)
//    - Upload runs in background; failures queue to JobPhotoQueue for retry
//    - Shows thumbnail + retake button once a slot is filled
//

import SwiftUI
import UIKit
import PhotosUI

// MARK: - JobPhotoType

/// Identifies which photo slot a capture belongs to.
enum JobPhotoType: String, CaseIterable, Identifiable {
    case before     = "before"
    case after      = "after"
    /// Extra proof photos — any number. Same category the web app and Salt report use.
    case additional = "additional"

    var id: String { rawValue }

    /// Before/after hold one photo each (a retake replaces it); extras are a list.
    var isSingleSlot: Bool { self != .additional }
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
    @Published var remoteExtras: [VisitPhoto] = []

    /// Extras captured in this session, newest last.
    @Published var localExtras: [LocalExtraPhoto] = []

    /// True when a photo is queued offline but not yet synced to the server.
    @Published var beforePendingSync: Bool = false
    @Published var afterPendingSync:  Bool = false
    @Published var extrasPendingSync: Int  = 0

    /// Active camera slot — drives the fullScreenCover in JobPhotoSection.
    @Published var captureSlot: JobPhotoType? = nil

    /// Bumped after each extra capture so the camera comes straight back for the next shot.
    @Published var cameraSession: Int = 0
    @Published var shotsThisSession: Int = 0

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

        // Drain queued photos automatically when connectivity returns.
        connectivityObserver = NotificationCenter.default.addObserver(
            forName: .mwPingQueueOnline,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                guard let self else { return }
                await JobPhotoQueue.shared.drain(using: self.apiClient)
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
        remoteExtras = photos.filter { $0.photoType == "additional" }
        // The server list now includes everything this session uploaded.
        if !isUploading && extrasPendingSync == 0 { localExtras = [] }
    }

    // MARK: - Capture Handling

    func beginCapture(_ slot: JobPhotoType) {
        shotsThisSession = 0
        captureSlot = slot
    }

    func handleCapture(_ image: UIImage, slot: JobPhotoType) {
        switch slot {
        case .before:
            captureSlot = nil
            beforeImage = image
        case .after:
            captureSlot = nil
            afterImage  = image
        case .additional:
            // Keep shooting: the camera reopens until the crew member taps Cancel.
            localExtras.append(LocalExtraPhoto(image: image))
            shotsThisSession += 1
            cameraSession    += 1
        }

        Task { await upload(image: image, slot: slot) }
    }

    /// Photos picked from the library, several at once.
    func addFromLibrary(_ images: [UIImage]) {
        for image in images {
            localExtras.append(LocalExtraPhoto(image: image))
            Task { await upload(image: image, slot: .additional) }
        }
    }

    func cancelCapture() {
        captureSlot = nil
    }

    // MARK: - Upload

    private func upload(image: UIImage, slot: JobPhotoType) async {
        guard let data = image.jpegData(compressionQuality: 0.78) else { return }

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
            case .additional: break
            }
        } catch let err as APIError {
            if case .networkError = err {
                // Offline — queue to disk and show a soft indicator instead of an error.
                JobPhotoQueue.shared.enqueue(imageData: data, visitId: visitId, photoType: slot)
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
    /// Nil = hide the heart slot entirely (backward-compat for callers that don't support flagging).
    let onFlagToggle:   (() async -> Void)?

    @StateObject private var vm: JobPhotoViewModel
    @State private var libraryItems: [PhotosPickerItem] = []

    init(visitId: Int, isActive: Bool, authSession: AuthSession,
         isFlagged: Bool = false, isFlagLoading: Bool = false,
         onFlagToggle: (() async -> Void)? = nil) {
        self.visitId      = visitId
        self.isActive     = isActive
        self.authSession  = authSession
        self.isFlagged    = isFlagged
        self.isFlagLoading = isFlagLoading
        self.onFlagToggle = onFlagToggle
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

            extrasStrip()
        }
        .task { await vm.loadExisting() }
        // Camera — presented when captureSlot is non-nil. For extra photos it comes straight
        // back after every shot (.id forces a fresh camera) until the crew member taps Cancel.
        .fullScreenCover(item: $vm.captureSlot) { slot in
            CameraPicker(
                onCapture: { image in vm.handleCapture(image, slot: slot) },
                onCancel:  { vm.cancelCapture() }
            )
            .id(vm.cameraSession)
            .ignoresSafeArea()
            .overlay(alignment: .top) {
                if slot == .additional {
                    Text(vm.shotsThisSession == 0
                         ? "Take as many as you need — Cancel when done"
                         : "\(vm.shotsThisSession) saved — keep going, or Cancel when done")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.white)
                        .padding(.horizontal, 12)
                        .padding(.vertical, 6)
                        .background(.black.opacity(0.55))
                        .clipShape(Capsule())
                        .padding(.top, 54)
                        .allowsHitTesting(false)
                }
            }
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

                PhotosPicker(selection: $libraryItems, maxSelectionCount: 10, matching: .images) {
                    Label("Choose", systemImage: "photo.on.rectangle")
                        .font(.subheadline.weight(.semibold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 10)
                        .background(Color(.systemGray6))
                        .foregroundStyle(.primary)
                        .clipShape(RoundedRectangle(cornerRadius: 10))
                }
                .buttonStyle(.plain)
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
                                    if !enabled {
                                        Text("Start job first")
                                            .font(.caption2)
                                            .foregroundStyle(.tertiary)
                                    }
                                }
                            }
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
            .disabled(!enabled || vm.isUploading)

            // Slot label + retake link
            HStack(spacing: 4) {
                if filled {
                    Image(systemName: "checkmark.circle.fill")
                        .font(.caption2)
                        .foregroundStyle(Color.MW.green)
                }
                Text(label)
                    .font(.caption2.weight(.medium))
                    .foregroundStyle(filled ? Color.MW.green : .secondary)
                Spacer()
                if filled && enabled {
                    Button {
                        vm.beginCapture(slot)
                    } label: {
                        Text("Retake")
                            .font(.caption2)
                            .foregroundStyle(Color.MW.green)
                    }
                    .disabled(vm.isUploading)
                }
            }
        }
        .frame(maxWidth: .infinity)
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
                        Image(systemName: isFlagged ? "heart.fill" : "heart")
                            .font(.system(size: 32))
                            .foregroundStyle(isFlagged ? Color.MW.orange : Color(.systemGray3))
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

            Text(isFlagged ? "Endorsed" : "Endorse")
                .font(.caption2.weight(.medium))
                .foregroundStyle(isFlagged ? Color.MW.orange : .secondary)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .frame(maxWidth: .infinity)
    }
}

