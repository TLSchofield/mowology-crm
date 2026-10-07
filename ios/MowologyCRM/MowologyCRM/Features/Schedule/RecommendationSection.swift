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
//  Two ways to send it (migration 1180):
//    Ask first  — a short note with the photos and NO price to the person who decides
//                 the work (on-site contact, else site contact). An admin/manager reads
//                 and edits it in AskDraftSheet before it goes; crew save it for them.
//    Send quote — the priced quote to the billing contact. Fixed-price packages flagged
//                 auto-send go straight out; everything else queues for office review.
//  Those decisions are made server-side — the buttons only describe them.
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
    @Published var recipients: RecommendationRecipientsResponse? = nil
    /// Set when the server hands back an Ask-first email for this user to read and send.
    @Published var askDraft: AskDraft?                  = nil
    @Published var draftSubject: String                 = ""
    @Published var draftBody: String                    = ""
    @Published var isSendingAsk: Bool                   = false
    @Published var askError: String?                    = nil

    private let visitId: Int
    private let apiClient: APIClient

    init(visitId: Int, authSession: AuthSession) {
        self.visitId   = visitId
        self.apiClient = APIClient(authSession: authSession)
    }

    var canSubmit: Bool {
        selected != nil && !isSubmitting
    }

    /// Ask first needs a service, the 1180 server, someone with an email, and consent.
    var canAsk: Bool {
        canSubmit && (selected?.canAsk ?? false) && recipients?.askBlockedReason == nil
    }

    /// Label for the quote button — a service with no price goes to the office first.
    var quoteButtonTitle: String {
        (selected?.hasPrice ?? true) ? "Send quote" : "Send to office for pricing"
    }

    /// Who the recommendation goes to. Silent on failure, like the chips.
    func loadRecipients() async {
        guard recipients == nil else { return }
        recipients = try? await apiClient.request(.recommendationRecipients(visitId: visitId))
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
    /// whole thing queues rather than being lost (an ask replays as a draft only).
    func submit(intent: String = "quote") async {
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
                    "intent":     intent,
                    "visit_id":   visitId,
                    "product_id": option.productId,
                    "note":       note,
                    "media_ids":  mediaIds
                ]
            )

            if let draft = result.draft {
                draftSubject = draft.subject
                draftBody    = draft.body
                askError     = nil
                askDraft     = draft          // presents AskDraftSheet
            } else {
                successMessage = result.message
            }
            reset()

        } catch APIError.networkError {
            RecommendationQueue.shared.enqueue(
                visitId: visitId,
                productId: option.productId,
                note: note,
                images: payloads,
                intent: intent
            )
            successMessage = intent == "ask"
                ? "Saved — it will reach the office when you're back in signal"
                : "Saved — will send when you're back in signal"
            reset()

        } catch APIError.serverError(let message) {
            errorMessage = message

        } catch {
            errorMessage = "Could not send that recommendation"
        }
    }

    /// Send the Ask-first email as edited. The server re-checks who may send, the
    /// recipient and consent; on failure it stays a draft in the office's Ask first tab.
    func sendAsk() async {
        guard let draft = askDraft else { return }
        isSendingAsk = true
        askError = nil
        defer { isSendingAsk = false }
        do {
            let r: AskSendResponse = try await apiClient.request(
                .recommendationAskSend,
                body: [
                    "action":         "ask_send",
                    "observation_id": draft.observationId,
                    "subject":        draftSubject,
                    "body":           draftBody
                ]
            )
            if r.success {
                successMessage = r.message
                askDraft = nil
            } else {
                askError = r.message.isEmpty ? "It didn't send — it's still saved" : r.message
            }
        } catch APIError.serverError(let message) {
            askError = message
        } catch {
            askError = "No signal — it's still saved. Send it later from the office."
        }
    }

    /// Close the sheet without sending; the draft waits in the office's Ask first tab.
    func saveAskForLater() {
        askDraft = nil
        successMessage = "Saved — it's in the Ask first tab under Recommendations"
    }

    private func reset() {
        selected = nil
        images   = []
        note     = ""
    }
}

// MARK: - AskDraftSheet

/// The Ask-first email, editable, for an admin/manager on site. Nothing is sent until
/// they press Send. Kept in this file so no new Xcode file registration is needed.
struct AskDraftSheet: View {
    @ObservedObject var viewModel: RecommendationViewModel
    let draft: AskDraft

    var body: some View {
        NavigationView {
            Form {
                Section {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(draft.to?.name ?? "—").font(.subheadline.weight(.semibold))
                        Text(draft.to?.email ?? "No email").font(.caption).foregroundColor(.secondary)
                        Text(draft.to?.roleLabel ?? "").font(.caption2).foregroundColor(.secondary)
                    }
                    if let consent = draft.consent {
                        Label(consent.reason, systemImage: consent.ok ? "checkmark.seal" : "exclamationmark.triangle")
                            .font(.caption)
                            .foregroundColor(consent.ok ? Color.MW.green : .orange)
                    }
                } header: { Text("To") }

                if !draft.photos.isEmpty {
                    Section {
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 8) {
                                ForEach(draft.photos, id: \.self) { photo in
                                    AsyncImage(url: URL(string: photo.url.hasPrefix("http") ? photo.url : "https://mowology.ca" + photo.url)) { img in
                                        img.resizable().scaledToFill()
                                    } placeholder: {
                                        Color(.systemGray5)
                                    }
                                    .frame(width: 64, height: 64)
                                    .clipShape(RoundedRectangle(cornerRadius: 8))
                                }
                            }
                        }
                    } header: { Text("Attached at 1024px") }
                }

                Section {
                    TextField("Subject", text: $viewModel.draftSubject)
                } header: { Text("Subject") }

                Section {
                    TextEditor(text: $viewModel.draftBody)
                        .frame(minHeight: 260)
                        .font(.body)
                } header: {
                    Text(draft.draftedBy == "learned" ? "Email · written your way" : "Email")
                } footer: {
                    Text("No price goes in this one. Our name, address and an unsubscribe line are added underneath.")
                }

                if let error = viewModel.askError {
                    Section {
                        Label(error, systemImage: "exclamationmark.triangle.fill")
                            .font(.caption)
                            .foregroundColor(.orange)
                    }
                }
            }
            .navigationTitle("Ask first")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Save for later") { viewModel.saveAskForLater() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button {
                        Task { await viewModel.sendAsk() }
                    } label: {
                        if viewModel.isSendingAsk { ProgressView() } else { Text("Send").bold() }
                    }
                    .disabled(viewModel.isSendingAsk || !(draft.consent?.ok ?? false))
                }
            }
        }
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
                    pathLines
                    submitButtons
                }
            }

            if let success = viewModel.successMessage {
                banner(success, color: Color.MW.green, icon: "checkmark.circle.fill")
            }
            if let error = viewModel.errorMessage {
                banner(error, color: .orange, icon: "exclamationmark.triangle.fill")
            }
        }
        .task {
            await viewModel.loadOptions()
            await viewModel.loadRecipients()
        }
        .sheet(item: $viewModel.askDraft) { draft in
            AskDraftSheet(viewModel: viewModel, draft: draft)
        }
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
            Text("What you saw (optional)")
                .font(.caption.weight(.semibold))
                .foregroundColor(.secondary)

            TextField("e.g. Beds along the front are overgrown",
                      text: $viewModel.note, axis: .vertical)
                .textFieldStyle(.roundedBorder)
                .lineLimit(1...3)
        }
    }

    /// Who each button goes to, so the crew know before they press.
    @ViewBuilder
    private var pathLines: some View {
        if let r = viewModel.recipients {
            VStack(alignment: .leading, spacing: 6) {
                pathLine("ASK FIRST", r.askBlockedReason ?? {
                    let who = r.ask.map { "\($0.name) (\($0.roleLabel))" } ?? ""
                    return who + " · photos, no price · " + (r.canSend ? "you read it before it goes" : "a manager reads it first")
                }(), muted: r.askBlockedReason != nil)
                pathLine("SEND QUOTE", r.quote.map {
                    "\($0.name) (billing) · " + ((viewModel.selected?.hasPrice ?? true) ? "the office checks it first" : "the office prices it first")
                } ?? "No site contact on file", muted: r.quote == nil)
            }
        }
    }

    private func pathLine(_ key: String, _ text: String, muted: Bool) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: 8) {
            Text(key)
                .font(.caption2.weight(.bold))
                .foregroundColor(.secondary)
                .frame(width: 78, alignment: .leading)
            Text(text)
                .font(.caption)
                .foregroundColor(muted ? .secondary : .primary)
        }
    }

    private var submitButtons: some View {
        HStack(spacing: 10) {
            Button {
                Task { await viewModel.submit(intent: "ask") }
            } label: {
                Text("Ask first")
                    .font(.subheadline.weight(.semibold))
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                    .foregroundColor(viewModel.canAsk ? Color.MW.green : Color(.systemGray3))
                    .background(
                        RoundedRectangle(cornerRadius: 10)
                            .stroke(viewModel.canAsk ? Color.MW.green : Color(.systemGray4), lineWidth: 2)
                    )
            }
            .buttonStyle(.plain)
            .disabled(!viewModel.canAsk)

            Button {
                Task { await viewModel.submit(intent: "quote") }
            } label: {
                HStack {
                    if viewModel.isSubmitting {
                        ProgressView().tint(.white)
                    }
                    Text(viewModel.isSubmitting ? "Sending…" : viewModel.quoteButtonTitle)
                        .font(.subheadline.weight(.semibold))
                        .lineLimit(1)
                        .minimumScaleFactor(0.8)
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
