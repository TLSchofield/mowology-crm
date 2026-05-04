//
//  ReceiptsView.swift
//  MowologyCRM
//

import SwiftUI
import CoreLocation
import PhotosUI
import VisionKit

@MainActor
struct ReceiptsView: View {

    @EnvironmentObject private var authSession: AuthSession
    @StateObject private var viewModel: ReceiptsViewModel

    @State private var showCamera      = false
    @State private var showLibrary     = false
    @State private var showReview      = false
    @State private var pickerItem:     PhotosPickerItem?

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
        // Primary: DataScannerViewController with live bounding boxes
        .fullScreenCover(isPresented: $showCamera) {
            if DataScannerViewController.isSupported {
                LiveReceiptScanner(
                    onCapture: { image, lines in
                        showCamera = false
                        Task {
                            let compressed: Data = await Task.detached(priority: .userInitiated) {
                                ReceiptsView.resizeAndCompress(image)
                            }.value
                            guard !compressed.isEmpty else { return }
                            await handleCapture(compressed, localLines: lines)
                        }
                    },
                    onCancel: { showCamera = false }
                )
                .ignoresSafeArea()
            } else {
                CameraPicker(
                    onCapture: { image in
                        showCamera = false
                        Task {
                            let compressed: Data = await Task.detached(priority: .userInitiated) {
                                ReceiptsView.resizeAndCompress(image)
                            }.value
                            guard !compressed.isEmpty else { return }
                            await handleCapture(compressed, localLines: [])
                        }
                    },
                    onCancel: { showCamera = false }
                )
                .ignoresSafeArea()
            }
        }
        .sheet(isPresented: $showLibrary) {
            librarySheet
        }
        .sheet(isPresented: $showReview, onDismiss: { viewModel.clearCapture() }) {
            ReceiptReviewView(
                viewModel:  viewModel,
                intake:     viewModel.intakeResponse,
                localParse: viewModel.localParse,
                isPresented: $showReview
            )
        }
        .onChange(of: pickerItem) { _, newItem in
            guard let newItem else { return }
            showLibrary = false
            Task {
                guard let raw = try? await newItem.loadTransferable(type: Data.self) else { return }
                let compressed: Data = await Task.detached(priority: .userInitiated) {
                    ReceiptsView.resizeAndCompress(UIImage(data: raw) ?? UIImage())
                }.value
                await handleCapture(compressed, localLines: [])
            }
        }
    }

    // MARK: - Capture handler

    private func handleCapture(_ compressed: Data, localLines: [String]) async {
        // 1. Parse locally for instant review-form prefill
        let parse = LocalOCR.parseLines(localLines)
        viewModel.applyLocalParse(parse)

        // 2. Show review immediately — user can start editing while upload runs
        showReview = true

        // 3. Upload in background; intakeResponse published when done
        let loc = locationManager.location
        await viewModel.uploadImage(
            compressed,
            lat: loc?.coordinate.latitude,
            lng: loc?.coordinate.longitude
        )
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
            viewModel.clearCapture()
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
                pickerItem  = nil
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

    // MARK: - Image resize + compress

    nonisolated static func resizeAndCompress(_ image: UIImage) -> Data {
        let maxDim: CGFloat = 1920
        let size = image.size
        let scale: CGFloat = size.width > size.height
            ? min(1, maxDim / size.width)
            : min(1, maxDim / size.height)
        let newSize = CGSize(width: size.width * scale, height: size.height * scale)

        let renderer = UIGraphicsImageRenderer(size: newSize)
        let resized  = renderer.image { _ in image.draw(in: CGRect(origin: .zero, size: newSize)) }
        return resized.jpegData(compressionQuality: 0.78) ?? Data()
    }
}
