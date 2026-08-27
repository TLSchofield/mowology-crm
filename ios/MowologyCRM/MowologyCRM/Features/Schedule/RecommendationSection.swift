//
//  RecommendationSection.swift
//  MowologyCRM
//
//  Crew spot sellable work while on site — a property needs a cleanup, a hedge
//  is overgrown — and that used to die in someone's head. Snap a few photos,
//  tap a service, and the client gets a priced quote they can accept from their
//  portal.
//
//  Self-contained ViewModel + view so it can drop straight into
//  VisitDetailView's ForEach(visits), same as JobPhotoSection.
//
//  Fixed-price packages send immediately; anything measurement-driven queues for
//  office review. That decision is made server-side — the chip only hints at it.
//

import SwiftUI
import UIKit

// MARK: - RecommendationViewModel

@MainActor
final class RecommendationViewModel: ObservableObject {

    @Published var options:      [RecommendationOption] = []
    @Published var selected:     RecommendationOption?  = nil
    @Published var images:       [UIImage]              = []
    @Published var note:         String                 = ""
    @Published var isLoading:    Bool                   = false
    @Published var isSubmitting: Bool                   = false
    @Published var errorMessage: String?                = nil
    @Published var successMessage: String?              = nil

    private let visitId: Int
    private let apiClient: APIClient

    init(visitId: Int, authSession: AuthSession) {
        self.visitId   = visitId
        self.apiClient = APIClient(authSession: authSession)
    }

    var canSubmit: Bool {
        selected != nil && !isSubmitting
    }

    /// Load the chips the office has published. Silent on failure — a crew member
    /// with no signal should see the rest of the job card working normally.
    func loadOptions() async {
        guard options.isEmpty, !isLoading else { return }
        isLoading = true
        defer { isLoading = false }

        do {
            let response: RecommendationOptionsResponse =
                try await apiClient.request(.recommendationOptions)
            options = response.options
        } catch {
            options = []
        }
    }

    func addImage(_ image: UIImage) {
        images.append(image)
    }

    func removeImage(at index: Int) {
        guard images.indices.contains(index) else { return }
        images.remove(at: index)
    }

    /// Upload the photos, then log the recommendation. On a network failure the
    /// whole thing queues rather than being lost.
    func submit() async {
        guard let option = selected else { return }

        isSubmitting = true
        errorMessage = nil
        defer { isSubmitting = false }

        // Compress off the main actor — several photos at full resolution would
        // otherwise stutter the UI.
        let payloads: [Data] = await Task.detached(priority: .userInitiated) { [images] in
            images.map { ReceiptsView.resizeAndCompress($0) }
        }.value

        do {
            var mediaIds: [Int] = []
            for data in payloads {
                let response = try await apiClient.uploadVisitPhoto(
                    imageData: data,
                    visitId: visitId,
                    photoTypeRaw: "issue"
                )
                if let mediaId = response["media_id"] as? Int {
                    mediaIds.append(mediaId)
                }
            }

            let result: RecommendationCreateResponse = try await apiClient.request(
                .recommendationCreate,
                body: [
                    "action":     "create",
                    "visit_id":   visitId,
                    "product_id": option.productId,
                    "note":       note,
                    "media_ids":  mediaIds
                ]
            )

            successMessage = result.message
            reset()

        } catch APIError.networkError {
            RecommendationQueue.shared.enqueue(
                visitId: visitId,
                productId: option.productId,
                note: note,
                images: payloads
            )
            successMessage = "Saved — will send when you're back in signal"
            reset()

        } catch APIError.serverError(let message) {
            errorMessage = message

        } catch {
            errorMessage = "Could not send that recommendation"
        }
    }

    private func reset() {
        selected = nil
        images   = []
        note     = ""
    }
}

// MARK: - RecommendationSection

struct RecommendationSection: View {

    let visitId: Int
    let authSession: AuthSession

    @StateObject private var viewModel: RecommendationViewModel
    @State private var isCapturing = false
    @State private var isExpanded  = false

    init(visitId: Int, authSession: AuthSession) {
        self.visitId     = visitId
        self.authSession = authSession
        _viewModel = StateObject(wrappedValue: RecommendationViewModel(
            visitId: visitId,
            authSession: authSession
        ))
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            header

            if isExpanded {
                if viewModel.options.isEmpty {
                    Text(viewModel.isLoading
                         ? "Loading services…"
                         : "No services published to the field yet.")
                        .font(.caption)
                        .foregroundColor(.secondary)
                } else {
                    chips
                    photoRow
                    noteField
                    submitButton
                }
            }

            if let success = viewModel.successMessage {
                banner(success, color: Color.MW.green, icon: "checkmark.circle.fill")
            }
            if let error = viewModel.errorMessage {
                banner(error, color: .orange, icon: "exclamationmark.triangle.fill")
            }
        }
        .task { await viewModel.loadOptions() }
        .fullScreenCover(isPresented: $isCapturing) {
            CameraPicker(
                onCapture: { image in
                    viewModel.addImage(image)
                    isCapturing = false
                },
                onCancel: { isCapturing = false }
            )
        }
    }

    // MARK: - Pieces

    private var header: some View {
        Button {
            withAnimation { isExpanded.toggle() }
        } label: {
            HStack(spacing: 8) {
                Image(systemName: "star.circle.fill")
                    .foregroundColor(Color.MW.green)
                Text("Recommend a Service")
                    .font(.subheadline.weight(.semibold))
                    .foregroundColor(.primary)
                Spacer()
                Image(systemName: isExpanded ? "chevron.up" : "chevron.down")
                    .font(.caption)
                    .foregroundColor(.secondary)
            }
        }
        .buttonStyle(.plain)
    }

    private var chips: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(viewModel.options) { option in
                    Button {
                        viewModel.selected = (viewModel.selected == option) ? nil : option
                    } label: {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(option.label)
                                .font(.caption.weight(.semibold))
                                .foregroundColor(.primary)
                            Text(option.formattedPrice)
                                .font(.caption2)
                                .foregroundColor(Color.MW.green)
                        }
                        .padding(.horizontal, 14)
                        .padding(.vertical, 10)
                        .background(
                            RoundedRectangle(cornerRadius: 10)
                                .stroke(viewModel.selected == option ? Color.MW.green : Color(.systemGray4),
                                        lineWidth: 2)
                        )
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    private var photoRow: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Photos")
                .font(.caption.weight(.semibold))
                .foregroundColor(.secondary)

            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(Array(viewModel.images.enumerated()), id: \.offset) { index, image in
                        ZStack(alignment: .topTrailing) {
                            Image(uiImage: image)
                                .resizable()
                                .scaledToFill()
                                .frame(width: 72, height: 72)
                                .clipShape(RoundedRectangle(cornerRadius: 8))

                            Button {
                                viewModel.removeImage(at: index)
                            } label: {
                                Image(systemName: "xmark.circle.fill")
                                    .foregroundColor(.white)
                                    .background(Circle().fill(Color.black.opacity(0.5)))
                            }
                            .buttonStyle(.plain)
                            .padding(2)
                        }
                    }

                    Button {
                        isCapturing = true
                    } label: {
                        VStack(spacing: 4) {
                            Image(systemName: "camera.fill")
                            Text("Add").font(.caption2)
                        }
                        .frame(width: 72, height: 72)
                        .foregroundColor(Color.MW.green)
                        .background(
                            RoundedRectangle(cornerRadius: 8)
                                .stroke(Color(.systemGray4), lineWidth: 1)
                        )
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    private var noteField: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Note to the client")
                .font(.caption.weight(.semibold))
                .foregroundColor(.secondary)

            TextField("e.g. Back garden is knee deep in leaves",
                      text: $viewModel.note, axis: .vertical)
                .textFieldStyle(.roundedBorder)
                .lineLimit(1...3)
        }
    }

    private var submitButton: some View {
        Button {
            Task { await viewModel.submit() }
        } label: {
            HStack {
                if viewModel.isSubmitting {
                    ProgressView().tint(.white)
                }
                Text(viewModel.isSubmitting ? "Sending…" : "Send Recommendation")
                    .font(.subheadline.weight(.semibold))
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 12)
            .background(viewModel.canSubmit ? Color.MW.green : Color(.systemGray4))
            .foregroundColor(.white)
            .clipShape(RoundedRectangle(cornerRadius: 10))
        }
        .buttonStyle(.plain)
        .disabled(!viewModel.canSubmit)
    }

    private func banner(_ text: String, color: Color, icon: String) -> some View {
        HStack(spacing: 8) {
            Image(systemName: icon).foregroundColor(color)
            Text(text).font(.caption).foregroundColor(.primary)
            Spacer()
        }
        .padding(10)
        .background(color.opacity(0.12))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }
}
