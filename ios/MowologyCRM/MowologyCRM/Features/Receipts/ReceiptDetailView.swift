//
//  ReceiptDetailView.swift
//  MowologyCRM
//
//  Detail/action screen for an already-saved receipt — the piece that was missing on
//  iOS: capture and draft-save already worked, but there was no way to approve, reject,
//  or forward a receipt to accounting without switching to the web app. Buttons here are
//  not role-gated client-side (there's no cheap way to know the JWT user's exact RBAC
//  permissions without a dedicated lookup) — the server enforces expenses.approve /
//  expenses.send via jwtUserHasPermission() and returns a clear error if denied.
//

import SwiftUI

struct ReceiptDetailView: View {

    @ObservedObject var viewModel: ReceiptsViewModel
    let expense: Expense
    @Environment(\.dismiss) private var dismiss

    @State private var showRejectSheet = false
    @State private var rejectReason = ""
    @State private var showErrorAlert = false
    @State private var showFullImage = false

    var body: some View {
        NavigationStack {
            Form {
                imageSection
                statusSection
                detailsSection

                if expense.isArchived {
                    Section {
                        Label("Archived — original photo removed from the server; a thumbnail is shown here. The full-size image was emailed before removal.", systemImage: "archivebox")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }
            .navigationTitle("Receipt")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                        .foregroundStyle(Color.MW.green)
                }
            }
            .safeAreaInset(edge: .bottom) {
                if canActOn(expense) {
                    actionBar
                }
            }
        }
        .presentationDragIndicator(.visible)
        .sheet(isPresented: $showRejectSheet) {
            rejectSheet
        }
        .onChange(of: viewModel.actionError) { _, err in
            if err != nil { showErrorAlert = true }
        }
        .alert("Action Failed", isPresented: $showErrorAlert) {
            Button("OK") { viewModel.actionError = nil }
        } message: {
            Text(viewModel.actionError ?? "")
        }
        .fullScreenCover(isPresented: $showFullImage) {
            if let url = receiptURL {
                ZoomableReceiptView(url: url)
            }
        }
    }

    private var receiptURL: URL? {
        guard let s = expense.receiptUrl else { return nil }
        return URL(string: s)
    }

    /// Draft/pending expenses can be approved or rejected; approved ones can be sent.
    /// Already-forwarded or already-rejected expenses have no further action here.
    private func canActOn(_ expense: Expense) -> Bool {
        ["draft", "pending_approval", "approved"].contains(expense.status)
    }

    // MARK: - Sections

    private var imageSection: some View {
        Section {
            HStack {
                Spacer()
                if let url = receiptURL {
                    AsyncImage(url: url) { img in
                        img.resizable().scaledToFit()
                            .frame(maxHeight: 220)
                            .clipShape(RoundedRectangle(cornerRadius: 10))
                            .overlay(alignment: .bottomTrailing) {
                                Image(systemName: "arrow.up.left.and.arrow.down.right")
                                    .font(.caption.weight(.bold))
                                    .foregroundStyle(.white)
                                    .padding(6)
                                    .background(.black.opacity(0.55), in: Circle())
                                    .padding(8)
                            }
                            .contentShape(Rectangle())
                            .onTapGesture { showFullImage = true }
                    } placeholder: {
                        ProgressView().frame(height: 160)
                    }
                } else {
                    RoundedRectangle(cornerRadius: 10)
                        .fill(Color(.systemGray5))
                        .frame(height: 120)
                        .overlay { Image(systemName: "doc.text.image").font(.largeTitle).foregroundStyle(.secondary) }
                }
                Spacer()
            }
        }
    }

    private var statusSection: some View {
        Section {
            HStack {
                Text("Status")
                Spacer()
                statusBadge
            }
            if let score = expense.anomalyScore, score > 0 {
                HStack {
                    Text("Risk score")
                    Spacer()
                    Text("\(score)")
                        .foregroundStyle(score >= 60 ? .red : (score >= 30 ? Color.MW.orange : .secondary))
                }
            }
        }
    }

    private var statusBadge: some View {
        let (label, color): (String, Color) = switch expense.status {
        case "forwarded":        ("Sent", .blue)
        case "approved":         ("Approved", Color.MW.green)
        case "rejected":         ("Rejected", .red)
        case "pending_approval": ("Pending Approval", Color.MW.orange)
        default:                 ("Draft", Color.MW.orange)
        }
        return Text(label)
            .font(.caption.weight(.semibold))
            .padding(.horizontal, 8).padding(.vertical, 3)
            .background(color.opacity(0.12))
            .foregroundStyle(color)
            .clipShape(Capsule())
    }

    private var detailsSection: some View {
        Section("Details") {
            detailRow("Vendor", expense.displayVendor)
            detailRow("Date", expense.expenseDate)
            detailRow("Total", String(format: "$%.2f", expense.total))
            if let category = expense.accountingCategory, !category.isEmpty {
                detailRow("Category", category)
            }
            if let payment = expense.paymentMethod, !payment.isEmpty {
                detailRow("Payment", payment)
            }
            if let description = expense.description, !description.isEmpty {
                detailRow("Description", description)
            }
        }
    }

    private func detailRow(_ label: String, _ value: String) -> some View {
        HStack {
            Text(label).foregroundStyle(.secondary)
            Spacer()
            Text(value).multilineTextAlignment(.trailing)
        }
    }

    // MARK: - Actions

    private var actionBar: some View {
        HStack(spacing: 10) {
            if viewModel.isPerformingAction {
                ProgressView().frame(maxWidth: .infinity)
            } else if expense.status == "approved" {
                actionButton(title: "Send to Accounting", systemImage: "paperplane.fill", color: Color.MW.green) {
                    Task { await performSend() }
                }
            } else {
                actionButton(title: "Reject", systemImage: "xmark", color: .red) {
                    rejectReason = ""
                    showRejectSheet = true
                }
                actionButton(title: "Approve", systemImage: "checkmark", color: Color.MW.green) {
                    Task { await performApprove() }
                }
            }
        }
        .padding(.horizontal)
        .padding(.vertical, 10)
        .background(.bar)
    }

    private func actionButton(title: String, systemImage: String, color: Color, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Label(title, systemImage: systemImage)
                .font(.subheadline.weight(.semibold))
                .frame(maxWidth: .infinity, minHeight: 44)
                .background(color)
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 10))
        }
    }

    private var rejectSheet: some View {
        NavigationStack {
            Form {
                Section("Reason for rejection") {
                    TextField("Explain why this expense is being rejected…", text: $rejectReason, axis: .vertical)
                        .lineLimit(3...6)
                }
            }
            .navigationTitle("Reject Receipt")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showRejectSheet = false }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Reject") {
                        Task { await performReject() }
                    }
                    .foregroundStyle(.red)
                    .disabled(rejectReason.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
        }
        .presentationDetents([.medium])
    }

    private func performApprove() async {
        if await viewModel.approve(expenseId: expense.id) { dismiss() }
    }

    private func performReject() async {
        let reason = rejectReason.trimmingCharacters(in: .whitespacesAndNewlines)
        if await viewModel.reject(expenseId: expense.id, reason: reason) {
            showRejectSheet = false
            dismiss()
        }
    }

    private func performSend() async {
        if await viewModel.send(expenseId: expense.id) { dismiss() }
    }
}

// MARK: - Full-screen zoomable receipt

/// Tap a receipt thumbnail to inspect it full-screen. Supports pinch-to-zoom,
/// double-tap to toggle zoom, and drag-to-pan while zoomed — for reading faint
/// handwritten totals/GST that are illegible at thumbnail size.
private struct ZoomableReceiptView: View {
    let url: URL
    @Environment(\.dismiss) private var dismiss

    @State private var scale: CGFloat = 1
    @GestureState private var pinch: CGFloat = 1
    @State private var offset: CGSize = .zero
    @GestureState private var drag: CGSize = .zero

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()

            AsyncImage(url: url) { phase in
                if let img = phase.image {
                    img.resizable().scaledToFit()
                        .scaleEffect(max(1, scale * pinch))
                        .offset(x: offset.width + drag.width, y: offset.height + drag.height)
                        .gesture(
                            MagnificationGesture()
                                .updating($pinch) { value, state, _ in state = value }
                                .onEnded { value in
                                    scale = min(max(scale * value, 1), 5)
                                    if scale == 1 { offset = .zero }
                                }
                        )
                        .simultaneousGesture(
                            DragGesture()
                                .updating($drag) { value, state, _ in
                                    if scale > 1 { state = value.translation }
                                }
                                .onEnded { value in
                                    guard scale > 1 else { return }
                                    offset.width += value.translation.width
                                    offset.height += value.translation.height
                                }
                        )
                        .onTapGesture(count: 2) {
                            withAnimation(.spring(response: 0.3)) {
                                if scale > 1 { scale = 1; offset = .zero } else { scale = 2.5 }
                            }
                        }
                } else if phase.error != nil {
                    VStack(spacing: 8) {
                        Image(systemName: "exclamationmark.triangle").font(.largeTitle)
                        Text("Couldn't load image").font(.footnote)
                    }
                    .foregroundStyle(.white.opacity(0.8))
                } else {
                    ProgressView().tint(.white)
                }
            }

            VStack {
                HStack {
                    Spacer()
                    Button { dismiss() } label: {
                        Image(systemName: "xmark.circle.fill")
                            .font(.system(size: 30))
                            .symbolRenderingMode(.palette)
                            .foregroundStyle(.white, .black.opacity(0.4))
                            .padding()
                    }
                }
                Spacer()
            }
        }
    }
}
