//
//  ReceiptsView.swift
//  MowologyCRM
//

import SwiftUI
import CoreLocation
import PhotosUI

@MainActor
struct ReceiptsView: View {

    @EnvironmentObject private var authSession: AuthSession
    @StateObject private var viewModel: ReceiptsViewModel
    @Environment(\.scenePhase) private var scenePhase

    // Camera as fullScreenCover — must be on the root view, not inside a sheet.
    @State private var showCamera   = false
    @State private var showLibrary  = false
    @State private var showReview   = false
    @State private var pickerItem: PhotosPickerItem?
    @State private var showErrorAlert = false
    @State private var showActionErrorAlert = false
    @State private var selectedExpense: Expense?

    // The just-captured photo + fix, handed to the review sheet so it can show the
    // real image (the old sheet built its preview URL from the vendor name and never
    // loaded) and persist receipt_lat/lng like Android does.
    @State private var capturedImageData: Data?
    @State private var captureLat: Double?
    @State private var captureLng: Double?
    // "Snap Another" from the review sheet — reopen the camera once the sheet is gone.
    @State private var snapAnotherPending = false

    // Observed so the "N pending" badge re-renders when a queue drains, instead of
    // only on unrelated redraws.
    @ObservedObject private var receiptQueue = ReceiptQueue.shared
    @ObservedObject private var actionQueue  = ReceiptActionQueue.shared

    // Multi-select bulk approve/reject — mirrors the Android mobile card "Select" mode.
    @State private var isSelectMode = false
    @State private var selectedIds: Set<Int> = []
    @State private var showBulkRejectSheet = false
    @State private var bulkRejectReason = ""

    private let impact          = UIImpactFeedbackGenerator(style: .medium)
    private let locationManager = CLLocationManager()

    init(authSession: AuthSession? = nil) {
        let session = authSession ?? AuthSession()
        let client  = APIClient(authSession: session)
        _viewModel  = StateObject(wrappedValue: ReceiptsViewModel(apiClient: client))
    }

    var body: some View {
        NavigationStack {
            ZStack(alignment: .bottomTrailing) {
                expenseList
                if !isSelectMode { captureButton }
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { toolbarContent }
            .safeAreaInset(edge: .bottom) {
                if isSelectMode && !selectedIds.isEmpty {
                    bulkActionBar
                }
            }
        }
        .sheet(isPresented: $showBulkRejectSheet) {
            bulkRejectSheet
        }
        .task {
            await viewModel.loadExpenses()
            // Wire up auto-drain for receipts that were queued while offline.
            viewModel.startReceiptQueueMonitor()
            viewModel.startActionQueueMonitor()
            // Also drain on view appear — covers timed-out uploads/actions enqueued
            // while network status never actually changed (so NWPathMonitor never re-fired).
            await viewModel.drainPendingQueue()
            await viewModel.drainPendingActionQueue()
        }
        .onChange(of: scenePhase) { _, phase in
            // App foregrounded — retry any receipts/actions queued by a previous timeout.
            // NWPathMonitor only fires on connectivity *changes*; this is the catch-all.
            if phase == .active {
                Task { await viewModel.drainPendingQueue() }
                Task { await viewModel.drainPendingActionQueue() }
            }
        }
        // Camera opens as fullScreenCover directly on this view — no intermediate sheet.
        .fullScreenCover(isPresented: $showCamera) {
            CameraPicker(
                onCapture: { image in
                    showCamera = false
                    Task {
                        // Encode + compress off @MainActor — 12MP photos take ~1-2s on main thread
                        let compressed: Data = await Task.detached(priority: .userInitiated) {
                            ReceiptsView.resizeAndCompress(image)
                        }.value
                        guard !compressed.isEmpty else { return }
                        await handleCapture(compressed)
                    }
                },
                onCancel: { showCamera = false }
            )
            .ignoresSafeArea()
        }
        // Library fallback (PhotosPicker sheet)
        .sheet(isPresented: $showLibrary) {
            librarySheet
        }
        // Review sheet shown after successful OCR upload. "Snap Another" reopens the
        // camera after the sheet has fully dismissed (a fullScreenCover can't be
        // presented while a sheet is still animating out).
        .sheet(isPresented: $showReview, onDismiss: {
            if snapAnotherPending {
                snapAnotherPending = false
                impact.impactOccurred()
                showCamera = true
            }
        }) {
            if let intake = viewModel.intakeResponse {
                ReceiptReviewView(
                    viewModel: viewModel,
                    intake: intake,
                    imageData: capturedImageData,
                    lat: captureLat,
                    lng: captureLng,
                    isPresented: $showReview
                ) { followUp in
                    snapAnotherPending = (followUp == .snapAnother)
                }
            }
        }
        // Detail/action sheet — tapping a row lets the user approve/reject/send a
        // saved receipt, closing the gap where iOS could only ever create drafts.
        .sheet(item: $selectedExpense) { expense in
            ReceiptDetailView(viewModel: viewModel, expense: expense)
        }
        // Upload spinner overlay
        .overlay {
            if viewModel.isUploading {
                uploadOverlay
            }
        }
        .onChange(of: viewModel.uploadError) { _, err in
            if err != nil { showErrorAlert = true }
        }
        .alert("Upload Failed", isPresented: $showErrorAlert) {
            Button("OK") { viewModel.uploadError = nil }
        } message: {
            Text(viewModel.uploadError ?? "")
        }
        // Bulk approve/reject and archive/export are triggered from this view directly
        // (not through ReceiptDetailView's sheet), so they need their own error surface.
        .onChange(of: viewModel.actionError) { _, err in
            if err != nil { showActionErrorAlert = true }
        }
        .alert("Action Failed", isPresented: $showActionErrorAlert) {
            Button("OK") { viewModel.actionError = nil }
        } message: {
            Text(viewModel.actionError ?? "")
        }
        .onChange(of: pickerItem) { _, newItem in
            guard let newItem else { return }
            showLibrary = false
            Task {
                guard let raw = try? await newItem.loadTransferable(type: Data.self) else { return }
                let compressed: Data = await Task.detached(priority: .userInitiated) {
                    ReceiptsView.resizeAndCompress(UIImage(data: raw) ?? UIImage())
                }.value
                await handleCapture(compressed)
            }
        }
    }

    // MARK: - Capture handler

    private func handleCapture(_ compressed: Data) async {
        let loc = locationManager.location
        capturedImageData = compressed
        captureLat = loc?.coordinate.latitude
        captureLng = loc?.coordinate.longitude
        await viewModel.uploadImage(
            compressed,
            lat: captureLat,
            lng: captureLng
        )
        if viewModel.intakeResponse != nil { showReview = true }
    }

    // MARK: - List

    private var expenseList: some View {
        Group {
            if viewModel.isLoadingList && viewModel.expenses.isEmpty {
                ProgressView("Loading…")
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if viewModel.expenses.isEmpty {
                emptyState
            } else {
                List {
                    ForEach(viewModel.expenses) { expense in
                        Button {
                            if isSelectMode {
                                if selectedIds.contains(expense.id) { selectedIds.remove(expense.id) }
                                else { selectedIds.insert(expense.id) }
                            } else {
                                selectedExpense = expense
                            }
                        } label: {
                            ExpenseRow(expense: expense, isSelectMode: isSelectMode, isSelected: selectedIds.contains(expense.id))
                        }
                        .buttonStyle(.plain)
                        .listRowInsets(EdgeInsets(top: 6, leading: 16, bottom: 6, trailing: 16))
                        // Swipe-to-delete — Android's mobile card has the same gesture.
                        // Forwarded receipts are part of the books; the server refuses
                        // them, so don't offer the action at all.
                        .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                            if !isSelectMode && !expense.isForwarded && expense.status != "forwarded" {
                                Button(role: .destructive) {
                                    Task { _ = await viewModel.deleteExpense(id: expense.id) }
                                } label: {
                                    Label("Delete", systemImage: "trash")
                                }
                            }
                        }
                    }
                    if viewModel.currentPage < viewModel.totalPages {
                        HStack { Spacer(); ProgressView(); Spacer() }
                            .onAppear { Task { await viewModel.loadNextPage() } }
                    }
                }
                .listStyle(.insetGrouped)
                .refreshable { await viewModel.loadExpenses() }
            }
        }
    }

    private var emptyState: some View {
        VStack(spacing: 16) {
            Image(systemName: "doc.text.image")
                .font(.system(size: 48))
                .foregroundStyle(Color(.systemGray3))
            Text("No receipts yet")
                .font(.title2.bold())
            Text("Tap + to snap a receipt")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - FAB

    private var captureButton: some View {
        Button {
            impact.impactOccurred()
            viewModel.uploadError = nil
            showCamera = true
        } label: {
            Image(systemName: "camera.fill")
                .font(.system(size: 22, weight: .semibold))
                .foregroundStyle(.white)
                .frame(width: 60, height: 60)
                .background(Color.MW.green)
                .clipShape(Circle())
                .shadow(color: Color.MW.green.opacity(0.4), radius: 8, y: 4)
        }
        .padding(24)
        .contextMenu {
            Button {
                pickerItem = nil
                showLibrary = true
            } label: {
                Label("Choose from Library", systemImage: "photo.on.rectangle")
            }
        }
    }

    // MARK: - Library sheet

    private var librarySheet: some View {
        NavigationStack {
            PhotosPicker(
                selection: $pickerItem,
                matching: .images,
                photoLibrary: .shared()
            ) {
                Label("Select Photo", systemImage: "photo.on.rectangle")
                    .font(.headline)
                    .frame(maxWidth: .infinity, minHeight: 52)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 14))
                    .padding()
            }
            .navigationTitle("Choose from Library")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showLibrary = false }
                        .foregroundStyle(Color.MW.green)
                }
            }
        }
        .presentationDetents([.height(160)])
    }

    // MARK: - Upload overlay

    private var uploadOverlay: some View {
        ZStack {
            Color.black.opacity(0.45).ignoresSafeArea()
            VStack(spacing: 14) {
                ProgressView().scaleEffect(1.5).tint(.white)
                Text("Reading receipt…")
                    .font(.headline)
                    .foregroundStyle(.white)
            }
        }
    }

    // MARK: - Bulk select actions

    private var bulkActionBar: some View {
        HStack(spacing: 10) {
            if viewModel.isPerformingAction {
                ProgressView().frame(maxWidth: .infinity)
            } else {
                bulkActionButton(title: "Reject (\(selectedIds.count))", systemImage: "xmark", color: .red) {
                    bulkRejectReason = ""
                    showBulkRejectSheet = true
                }
                bulkActionButton(title: "Approve (\(selectedIds.count))", systemImage: "checkmark", color: Color.MW.green) {
                    Task { await performBulkApprove() }
                }
            }
        }
        .padding(.horizontal)
        .padding(.vertical, 10)
        .background(.bar)
    }

    private func bulkActionButton(title: String, systemImage: String, color: Color, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Label(title, systemImage: systemImage)
                .font(.subheadline.weight(.semibold))
                .frame(maxWidth: .infinity, minHeight: 44)
                .background(color)
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 10))
        }
    }

    private var bulkRejectSheet: some View {
        NavigationStack {
            Form {
                Section("Reason for rejection (applies to all \(selectedIds.count) selected)") {
                    TextField("Explain why these expenses are being rejected…", text: $bulkRejectReason, axis: .vertical)
                        .lineLimit(3...6)
                }
            }
            .navigationTitle("Reject \(selectedIds.count) Receipts")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showBulkRejectSheet = false }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Reject") {
                        Task { await performBulkReject() }
                    }
                    .foregroundStyle(.red)
                    .disabled(bulkRejectReason.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
        }
        .presentationDetents([.medium])
    }

    private func performBulkApprove() async {
        let ids = Array(selectedIds)
        if await viewModel.batchApprove(expenseIds: ids) {
            isSelectMode = false
            selectedIds.removeAll()
        }
    }

    private func performBulkReject() async {
        let reason = bulkRejectReason.trimmingCharacters(in: .whitespacesAndNewlines)
        let ids = Array(selectedIds)
        if await viewModel.batchReject(expenseIds: ids, reason: reason) {
            showBulkRejectSheet = false
            isSelectMode = false
            selectedIds.removeAll()
        }
    }

    private func performArchiveExport() async {
        _ = await viewModel.archiveExport()
    }

    // MARK: - Toolbar

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .principal) {
            HStack(spacing: 6) {
                Text("Receipts").font(.headline.weight(.semibold))
                if receiptQueue.pendingCount + actionQueue.pendingCount > 0 {
                    Text("\(receiptQueue.pendingCount + actionQueue.pendingCount) pending")
                        .font(.caption2.weight(.semibold))
                        .padding(.horizontal, 6).padding(.vertical, 2)
                        .background(Color.MW.orange.opacity(0.15))
                        .foregroundStyle(Color.MW.orange)
                        .clipShape(Capsule())
                }
            }
        }
        ToolbarItem(placement: .navigationBarTrailing) {
            Button {
                isSelectMode.toggle()
                if !isSelectMode { selectedIds.removeAll() }
            } label: {
                Text(isSelectMode ? "Cancel" : "Select")
                    .foregroundStyle(Color.MW.green)
            }
        }
        if !isSelectMode {
            ToolbarItem(placement: .navigationBarTrailing) {
                // Not role-gated client-side, same rationale as ReceiptDetailView's action
                // bar — the server enforces expenses.send via jwtUserHasPermission().
                Menu {
                    Button {
                        Task { await performArchiveExport() }
                    } label: {
                        Label("Archive & Export Old Receipts", systemImage: "archivebox")
                    }
                } label: {
                    Image(systemName: "ellipsis.circle").foregroundStyle(Color.MW.green)
                }
            }
            ToolbarItem(placement: .navigationBarTrailing) {
                Button { Task { await viewModel.loadExpenses() } } label: {
                    Image(systemName: "arrow.clockwise").foregroundStyle(Color.MW.green)
                }
                .disabled(viewModel.isLoadingList)
            }
        }
    }

    // MARK: - Image resize + compress

    // Matches Capacitor behaviour: max 1920px wide, 78% JPEG quality → ~200-600 KB
    nonisolated static func resizeAndCompress(_ image: UIImage) -> Data {
        let maxDim: CGFloat = 1920
        let size = image.size
        let scale = size.width > maxDim || size.height > maxDim
            ? min(maxDim / size.width, maxDim / size.height) : 1.0
        let target = scale < 1.0
            ? CGSize(width: (size.width * scale).rounded(), height: (size.height * scale).rounded())
            : size
        let renderer = UIGraphicsImageRenderer(size: target)
        let resized  = renderer.image { _ in image.draw(in: CGRect(origin: .zero, size: target)) }
        // Try 78% first (Capacitor default), fall back if still over 1.5 MB
        if let d = resized.jpegData(compressionQuality: 0.78), d.count <= 1_500_000 { return d }
        for q: CGFloat in [0.6, 0.45, 0.3, 0.15] {
            if let d = resized.jpegData(compressionQuality: q), d.count <= 1_500_000 { return d }
        }
        return resized.jpegData(compressionQuality: 0.15) ?? Data()
    }
}

// MARK: - ExpenseRow

private struct ExpenseRow: View {
    let expense: Expense
    var isSelectMode: Bool = false
    var isSelected: Bool = false

    var body: some View {
        HStack(spacing: 12) {
            if isSelectMode {
                Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                    .font(.title3)
                    .foregroundStyle(isSelected ? Color.MW.green : Color(.systemGray3))
            }
            receiptThumb
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 4) {
                    Text(expense.displayVendor)
                        .font(.subheadline.weight(.semibold))
                        .lineLimit(1)
                    if expense.isArchived {
                        Image(systemName: "archivebox.fill")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                    anomalyIcon
                }
                HStack(spacing: 6) {
                    Text(formattedDate)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    if let cat = expense.accountingCategory {
                        Text(cat)
                            .font(.caption2.weight(.medium))
                            .padding(.horizontal, 5).padding(.vertical, 1)
                            .background(Color.MW.green.opacity(0.1))
                            .foregroundStyle(Color.MW.green)
                            .clipShape(Capsule())
                    }
                }
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 3) {
                HStack(spacing: 4) {
                    confidenceDot
                    Text("$\(expense.total, specifier: "%.2f")")
                        .font(.subheadline.weight(.semibold))
                }
                statusBadge
            }
        }
        .padding(.vertical, 2)
    }

    /// OCR extraction trust signal — matches Android's mw-mc-conf-dot thresholds.
    @ViewBuilder
    private var confidenceDot: some View {
        if let tier = expense.confidenceTier {
            let color: Color = tier == "high" ? Color.MW.green : (tier == "medium" ? Color.MW.orange : .red)
            Circle().fill(color).frame(width: 6, height: 6)
        }
    }

    /// Anomaly risk signal — matches the desktop/Android alert-triangle / alert-circle icons.
    @ViewBuilder
    private var anomalyIcon: some View {
        if let tier = expense.anomalyTier {
            Image(systemName: tier == "high" ? "exclamationmark.triangle.fill" : "exclamationmark.circle.fill")
                .font(.caption2)
                .foregroundStyle(tier == "high" ? .red : Color.MW.orange)
        }
    }

    private var receiptThumb: some View {
        Group {
            if let urlStr = expense.receiptUrl, let url = URL(string: urlStr) {
                AsyncImage(url: url) { img in
                    img.resizable().scaledToFill()
                } placeholder: {
                    Color(.systemGray5)
                }
                .frame(width: 44, height: 44)
                .clipShape(RoundedRectangle(cornerRadius: 8))
            } else {
                RoundedRectangle(cornerRadius: 8)
                    .fill(Color(.systemGray5))
                    .frame(width: 44, height: 44)
                    .overlay { Image(systemName: "doc.text").foregroundStyle(.secondary) }
            }
        }
    }

    private var statusBadge: some View {
        let (label, color): (String, Color) = switch expense.status {
        case "forwarded":        ("Sent", .blue)
        case "approved":         ("Approved", Color.MW.green)
        case "rejected":         ("Rejected", .red)
        case "pending_approval": ("Pending", Color.MW.orange)
        default:                 ("Draft", Color.MW.orange)
        }
        return Text(label)
            .font(.caption2.weight(.semibold))
            .padding(.horizontal, 5).padding(.vertical, 1)
            .background(color.opacity(0.12))
            .foregroundStyle(color)
            .clipShape(Capsule())
    }

    private var formattedDate: String {
        let iso = ISO8601DateFormatter(); iso.formatOptions = [.withFullDate]
        guard let date = iso.date(from: expense.expenseDate) else { return expense.expenseDate }
        let f = DateFormatter(); f.dateStyle = .medium; f.timeStyle = .none
        return f.string(from: date)
    }
}

#Preview {
    ReceiptsView().environmentObject(AuthSession())
}
