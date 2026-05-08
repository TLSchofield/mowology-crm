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

// MARK: - JobPhotoViewModel

@MainActor
final class JobPhotoViewModel: ObservableObject {

    // MARK: - Published State

    @Published var beforeImage:  UIImage? = nil
    @Published var afterImage:   UIImage? = nil
    @Published var isUploading:  Bool     = false
    @Published var errorMessage: String?  = nil

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

    var hasBeforePhoto: Bool { beforeImage != nil }
    var hasAfterPhoto:  Bool { afterImage  != nil }
    var isComplete:     Bool { hasBeforePhoto && hasAfterPhoto }

    // MARK: - Capture Handling

    func handleCapture(_ image: UIImage, slot: JobPhotoType) {
        captureSlot = nil  // dismiss picker first

        switch slot {
        case .before: beforeImage = image
        case .after:  afterImage  = image
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

            // Photo slots + optional heart endorsement slot
            HStack(spacing: 12) {
                photoSlot(label: "Before", slot: .before, image: vm.beforeImage,
                          enabled: true)
                photoSlot(label: "After", slot: .after, image: vm.afterImage,
                          enabled: isActive || vm.hasBeforePhoto)
                if onFlagToggle != nil {
                    heartSlot()
                }
            }
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
                           image: UIImage?, enabled: Bool) -> some View {
        VStack(spacing: 6) {
            Button {
                guard enabled else { return }
                vm.captureSlot = slot
            } label: {
                ZStack {
                    if let img = image {
                        Image(uiImage: img)
                            .resizable()
                            .scaledToFill()
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
                            image != nil
                                ? Color.MW.green.opacity(0.5)
                                : (enabled ? Color.MW.green.opacity(0.25) : Color(.systemGray5)),
                            lineWidth: image != nil ? 2 : 1
                        )
                )
            }
            .disabled(!enabled || vm.isUploading)

            // Slot label + retake link
            HStack(spacing: 4) {
                if image != nil {
                    Image(systemName: "checkmark.circle.fill")
                        .font(.caption2)
                        .foregroundStyle(Color.MW.green)
                }
                Text(label)
                    .font(.caption2.weight(.medium))
                    .foregroundStyle(image != nil ? Color.MW.green : .secondary)
                Spacer()
                if image != nil && enabled {
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

// MARK: - JobPhotoType + Identifiable

extension JobPhotoType: Identifiable {
    var id: String { rawValue }
}
