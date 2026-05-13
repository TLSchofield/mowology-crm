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
//    - On appear: loads existing server photos so they survive navigation
//

import SwiftUI
import UIKit

// MARK: - Response model

private struct VisitPhotosResponse: Decodable {
    let success: Bool
    let photos: [ServerPhoto]

    struct ServerPhoto: Decodable {
        let id: Int
        let photoType: String
        let photoUrl: String
        let thumbUrl: String

        enum CodingKeys: String, CodingKey {
            case id
            case photoType = "photo_type"
            case photoUrl  = "photo_url"
            case thumbUrl  = "thumb_url"
        }
    }
}

// MARK: - JobPhotoViewModel

@MainActor
final class JobPhotoViewModel: ObservableObject {

    // MARK: - Published State

    @Published var beforeImage:     UIImage? = nil
    @Published var afterImage:      UIImage? = nil
    @Published var isUploading:     Bool     = false
    @Published var errorMessage:    String?  = nil

    /// Server-side thumbnail URLs loaded on appear — survive navigation away and back.
    @Published var serverBeforeURL: URL?     = nil
    @Published var serverAfterURL:  URL?     = nil

    /// Active camera slot — drives the fullScreenCover in JobPhotoSection.
    @Published var captureSlot: JobPhotoType? = nil

    // MARK: - Private

    private let visitId:   Int
    private let apiClient: APIClient

    // MARK: - Init

    init(visitId: Int, authSession: AuthSession) {
        self.visitId   = visitId
        self.apiClient = APIClient(authSession: authSession)
    }

    // MARK: - Computed

    var hasBeforePhoto: Bool { beforeImage != nil || serverBeforeURL != nil }
    var hasAfterPhoto:  Bool { afterImage  != nil || serverAfterURL  != nil }
    var isComplete:     Bool { hasBeforePhoto && hasAfterPhoto }

    // MARK: - Load existing server photos

    /// Called on appear. Fetches any photos already uploaded for this visit so
    /// they display after navigating away and back (UIImage in-memory is lost).
    func loadServerPhotos() async {
        do {
            let response: VisitPhotosResponse = try await apiClient.request(.visitPhotos(visitId: visitId))
            guard response.success else { return }

            for photo in response.photos {
                let thumbStr = photo.thumbUrl.hasPrefix("/")
                    ? "https://mowology.ca\(photo.thumbUrl)"
                    : photo.thumbUrl
                let url = URL(string: thumbStr)

                switch photo.photoType {
                case "before":
                    if beforeImage == nil { serverBeforeURL = url }
                case "after":
                    if afterImage  == nil { serverAfterURL  = url }
                default: break
                }
            }
        } catch {
            // Non-fatal — photos just won't show thumbnails from server
        }
    }

    // MARK: - Capture Handling

    func handleCapture(_ image: UIImage, slot: JobPhotoType) {
        captureSlot = nil  // dismiss picker first

        switch slot {
        case .before:
            beforeImage    = image
            serverBeforeURL = nil  // local image takes precedence
        case .after:
            afterImage    = image
            serverAfterURL = nil
        }

        Task { await upload(image: image, slot: slot) }
    }

    func cancelCapture() {
        captureSlot = nil
    }

    // MARK: - Upload

    private func upload(image: UIImage, slot: JobPhotoType) async {
        guard let data = image.jpegData(compressionQuality: 0.78) else { return }

        isUploading  = true
        errorMessage = nil

        do {
            try await apiClient.uploadJobPhoto(imageData: data,
                                               visitId:   visitId,
                                               photoType: slot)
        } catch {
            // Network failure — persist to disk queue, drain when online
            JobPhotoQueue.shared.enqueue(imageData: data,
                                         visitId:   visitId,
                                         photoType: slot)
            errorMessage = "Saved offline — will sync when connection restores."
        }

        isUploading = false
    }
}

// MARK: - JobPhotoSection View

/// Embeds inside a visit card to provide the before/after photo proof UI.
/// Pass `isActive` true when the visit timer is running to unlock the after-photo slot.
struct JobPhotoSection: View {

    let visitId:      Int
    let isActive:     Bool
    let authSession:  AuthSession

    /// Crew endorsement heart — passed from owning ViewModel so Visit stays immutable.
    let isFlagged:     Bool
    let isFlagLoading: Bool
    /// Nil = hide the heart slot (backward-compat for callers that don't support flagging).
    let onFlagToggle:  (() async -> Void)?

    @StateObject private var vm: JobPhotoViewModel

    init(visitId: Int, isActive: Bool, authSession: AuthSession,
         isFlagged: Bool = false, isFlagLoading: Bool = false,
         onFlagToggle: (() async -> Void)? = nil) {
        self.visitId       = visitId
        self.isActive      = isActive
        self.authSession   = authSession
        self.isFlagged     = isFlagged
        self.isFlagLoading = isFlagLoading
        self.onFlagToggle  = onFlagToggle
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

            // Error banner
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

            // Photo slots + optional heart endorsement
            HStack(spacing: 12) {
                photoSlot(label: "Before", slot: .before,
                          localImage: vm.beforeImage,
                          serverURL:  vm.serverBeforeURL,
                          enabled: true)
                photoSlot(label: "After", slot: .after,
                          localImage: vm.afterImage,
                          serverURL:  vm.serverAfterURL,
                          enabled: isActive || vm.hasBeforePhoto)
                if onFlagToggle != nil {
                    heartSlot()
                }
            }
        }
        .task {
            // Load server photos on first appear — restores thumbnails after navigation
            await vm.loadServerPhotos()
        }
        // Camera picker — presented when captureSlot is non-nil
        .fullScreenCover(item: $vm.captureSlot) { slot in
            CameraPicker(
                onCapture: { image in vm.handleCapture(image, slot: slot) },
                onCancel:  { vm.cancelCapture() }
            )
            .ignoresSafeArea()
        }
    }

    // MARK: - Photo Slot

    private func photoSlot(label: String, slot: JobPhotoType,
                           localImage: UIImage?, serverURL: URL?,
                           enabled: Bool) -> some View {
        let hasPhoto = localImage != nil || serverURL != nil
        return VStack(spacing: 6) {
            Button {
                guard enabled else { return }
                vm.captureSlot = slot
            } label: {
                ZStack {
                    if let img = localImage {
                        // Freshly captured — show local UIImage for zero-latency display
                        Image(uiImage: img)
                            .resizable()
                            .scaledToFill()
                            .frame(maxWidth: .infinity)
                            .frame(height: 110)
                            .clipped()
                    } else if let url = serverURL {
                        // Loaded from server — show AsyncImage thumbnail
                        AsyncImage(url: url) { phase in
                            switch phase {
                            case .success(let image):
                                image
                                    .resizable()
                                    .scaledToFill()
                                    .frame(maxWidth: .infinity)
                                    .frame(height: 110)
                                    .clipped()
                            case .failure:
                                serverPhotoFallback
                            case .empty:
                                ProgressView()
                                    .frame(maxWidth: .infinity)
                                    .frame(height: 110)
                            default:
                                serverPhotoFallback
                            }
                        }
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
                            hasPhoto
                                ? Color.MW.green.opacity(0.5)
                                : (enabled ? Color.MW.green.opacity(0.25) : Color(.systemGray5)),
                            lineWidth: hasPhoto ? 2 : 1
                        )
                )
            }
            .disabled(!enabled || vm.isUploading)

            // Slot label + retake link
            HStack(spacing: 4) {
                if hasPhoto {
                    Image(systemName: "checkmark.circle.fill")
                        .font(.caption2)
                        .foregroundStyle(Color.MW.green)
                }
                Text(label)
                    .font(.caption2.weight(.medium))
                    .foregroundStyle(hasPhoto ? Color.MW.green : .secondary)
                Spacer()
                if hasPhoto && enabled {
                    Button {
                        vm.captureSlot = slot
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

    private var serverPhotoFallback: some View {
        Rectangle()
            .fill(Color.MW.green.opacity(0.08))
            .frame(maxWidth: .infinity)
            .frame(height: 110)
            .overlay {
                Image(systemName: "photo.badge.checkmark")
                    .font(.title3)
                    .foregroundStyle(Color.MW.green)
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
            .disabled(isFlagLoading)

            Text(isFlagged ? "Endorsed" : "Endorse")
                .font(.caption2.weight(.medium))
                .foregroundStyle(isFlagged ? Color.MW.orange : .secondary)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .frame(maxWidth: .infinity)
    }
}

// MARK: - JobPhotoType + Identifiable

extension JobPhotoType: Identifiable {
    var id: String { rawValue }
}
