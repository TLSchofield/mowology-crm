//
//  ReceiptsView.swift
//  MowologyCRM
//

import SwiftUI
import CoreLocation
import PhotosUI

struct ReceiptsView: View {

    @EnvironmentObject private var authSession: AuthSession
    @StateObject private var viewModel: ReceiptsViewModel

    // Camera as fullScreenCover — must be on the root view, not inside a sheet.
    @State private var showCamera   = false
    @State private var showLibrary  = false
    @State private var showReview   = false
    @State private var pickerItem: PhotosPickerItem?

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
                captureButton
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { toolbarContent }
        }
        .task { await viewModel.loadExpenses() }
        // Camera opens as fullScreenCover directly on this view — no intermediate sheet.
        .fullScreenCover(isPresented: $showCamera) {
            CameraPicker(
                onCapture: { image in
                    showCamera = false
                    Task {
                        // Encode + compress off @MainActor — 12MP photos take ~1-2s on main thread
                        let compressed: Data = await Task.detached(priority: .userInitiated) {
                            guard let raw = image.jpegData(compressionQuality: 0.85) else { return Data() }
                            if raw.count <= 1_500_000 { return raw }
                            for q: CGFloat in [0.7, 0.55, 0.4, 0.25, 0.1] {
                                if let d = image.jpegData(compressionQuality: q), d.count <= 1_500_000 { return d }
                            }
                            return image.jpegData(compressionQuality: 0.1) ?? raw
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
        // Review sheet shown after successful OCR upload
        .sheet(isPresented: $showReview) {
            if let intake = viewModel.intakeResponse {
                ReceiptReviewView(viewModel: viewModel, intake: intake, isPresented: $showReview)
            }
        }
        // Upload spinner overlay
        .overlay {
            if viewModel.isUploading {
                uploadOverlay
            }
        }
        .onChange(of: pickerItem) { _, newItem in
            guard let newItem else { return }
            showLibrary = false
            Task {
                guard let raw = try? await newItem.loadTransferable(type: Data.self) else { return }
                let compressed: Data = await Task.detached(priority: .userInitiated) {
                    guard let img = UIImage(data: raw) else { return raw }
                    if raw.count <= 1_500_000 { return raw }
                    for q: CGFloat in [0.85, 0.7, 0.55, 0.4, 0.25, 0.1] {
                        if let d = img.jpegData(compressionQuality: q), d.count <= 1_500_000 { return d }
                    }
                    return img.jpegData(compressionQuality: 0.1) ?? raw
                }.value
                await handleCapture(compressed)
            }
        }
    }

    // MARK: - Capture handler

    private func handleCapture(_ compressed: Data) async {
        let loc = locationManager.location
        await viewModel.uploadImage(
            compressed,
            lat: loc?.coordinate.latitude,
            lng: loc?.coordinate.longitude
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
                        ExpenseRow(expense: expense)
                            .listRowInsets(EdgeInsets(top: 6, leading: 16, bottom: 6, trailing: 16))
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

    // MARK: - Toolbar

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .principal) {
            HStack(spacing: 6) {
                Text("Receipts").font(.headline.weight(.semibold))
                if ReceiptQueue.shared.pendingCount > 0 {
                    Text("\(ReceiptQueue.shared.pendingCount) pending")
                        .font(.caption2.weight(.semibold))
                        .padding(.horizontal, 6).padding(.vertical, 2)
                        .background(Color.MW.orange.opacity(0.15))
                        .foregroundStyle(Color.MW.orange)
                        .clipShape(Capsule())
                }
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

// MARK: - ExpenseRow

private struct ExpenseRow: View {
    let expense: Expense

    var body: some View {
        HStack(spacing: 12) {
            receiptThumb
            VStack(alignment: .leading, spacing: 3) {
                Text(expense.displayVendor)
                    .font(.subheadline.weight(.semibold))
                    .lineLimit(1)
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
                Text("$\(expense.total, specifier: "%.2f")")
                    .font(.subheadline.weight(.semibold))
                statusBadge
            }
        }
        .padding(.vertical, 2)
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
        case "forwarded": ("Sent", .blue)
        case "approved":  ("Approved", Color.MW.green)
        case "rejected":  ("Rejected", .red)
        default:          ("Draft", Color.MW.orange)
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
