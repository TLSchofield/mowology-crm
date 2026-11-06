//
//  ReceiptsView.swift
//  MowologyCRM
//

import SwiftUI
import CoreLocation
import PhotosUI

@MainActor
struct ReceiptsView: View {
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.itAppStyle) private var appStyle
    /// Room for the meniscus bar under pinned bottom controls (Tim, 2026-11-05).
    @Environment(\.itBarClearance) private var barClearance

    @EnvironmentObject private var authSession: AuthSession
    @StateObject private var viewModel: ReceiptsViewModel
    /// Penny's missing-receipt chaser — "Penny needs N receipts" on top of the list.
    @StateObject private var chase: PennyChaseViewModel
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
    /// Camera opened from the review's Retake: cancelling it brings the same review back.
    @State private var retakePending = false
    /// A receipt photo that failed the edge/sharpness check, held while the crew picks Retake or Use this photo.
    @State private var weakCapture: Data?
    @State private var weakCaptureReason = ""
    @State private var showWeakCaptureHint = false
    /// True from the camera opening until its cover has fully gone: an alert raised while it is still leaving is dropped.
    @State private var cameraOnScreen = false

    // Label mode: the next photo is a product bag / machine nameplate, not a receipt.
    // Same upload + OCR, never an expense; Penny or Otto proposes it on the web.
    @State private var labelMode = false
    @State private var showLabelResult = false

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
        _chase      = StateObject(wrappedValue: PennyChaseViewModel(client: client))
    }

    var body: some View {
        let _ = RenderRate.tick("ReceiptsView")
        NavigationStack {
            expenseList
            .navigationBarTitleDisplayMode(.inline)
            .itAppForm()
            .itAppScreen()
            .toolbar { toolbarContent }
            .safeAreaInset(edge: .bottom) {
                // The Snap bar is gone: the Receipts bead in the tab bar is the camera (Tim, 2026-11-05).
                // Label photos and the photo library are in the ⋯ menu.
                if isSelectMode, !selectedIds.isEmpty {
                    bulkActionBar.padding(.bottom, barClearance)
                }
            }
        }
        .sheet(isPresented: $showBulkRejectSheet) {
            bulkRejectSheet
        }
        // The Receipts bead in the tab bar, tapped while Receipts shows: the receipt camera.
        .onReceive(NotificationCenter.default.publisher(for: .mwSnapReceipt)) { _ in snap(label: false) }
        .task {
            Task { await chase.loadMine() }   // alongside the list, never in front of it
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
                Task { await chase.loadMine() }
                Task { await viewModel.drainPendingQueue() }
                Task { await viewModel.drainPendingActionQueue() }
            }
        }
        // Camera opens as fullScreenCover directly on this view — no intermediate sheet.
        .onChange(of: showCamera) { _, open in
            if open { cameraOnScreen = true }
        }
        .fullScreenCover(isPresented: $showCamera, onDismiss: {
            cameraOnScreen = false
            if weakCapture != nil { showWeakCaptureHint = true }
        }) {
            // Receipts: Apple's document camera with live edge guides and auto-capture (it hands back the page
            // already cropped). Labels, and phones without it (the simulator too), keep the system camera.
            if !labelMode && DocumentCameraPicker.isSupported {
                DocumentCameraPicker(onCapture: { cameraCaptured($0, scanned: true) }, onCancel: cameraCancelled)
                    .ignoresSafeArea()
            } else {
                CameraPicker(onCapture: { cameraCaptured($0, scanned: false) }, onCancel: cameraCancelled)
                    .ignoresSafeArea()
            }
        }
        // Library fallback (PhotosPicker sheet)
        .sheet(isPresented: $showLibrary) {
            librarySheet
        }
        // Review sheet shown after successful OCR upload. "Snap Another" reopens the
        // camera after the sheet has fully dismissed (a fullScreenCover can't be
        // presented while a sheet is still animating out).
        .sheet(isPresented: $showReview, onDismiss: {
            // A saved receipt may have answered one of Penny's missing charges (server-side match).
            Task { await chase.loadMine() }
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
                    isPresented: $showReview,
                    onRetake: {
                        // Same path as "Snap Another": close the sheet, then reopen the camera.
                        snapAnotherPending = true
                        retakePending = true
                        showReview = false
                    }
                ) { followUp in
                    snapAnotherPending = (followUp == .snapAnother)
                }
            }
        }
        // Detail/action sheet — tapping a row lets the user approve/reject/send a
        // saved receipt, closing the gap where iOS could only ever create drafts.
        .sheet(item: $selectedExpense) { expense in
            ReceiptDetailView(viewModel: viewModel, expense: expense, isAdmin: authSession.user?.isAdmin == true)
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
        // The capture hint: a blurry, skewed or distant receipt photo reads badly on the server.
        .alert(ReceiptPhotoPrep.hintText, isPresented: $showWeakCaptureHint) {
            Button("Retake") {
                weakCapture = nil
                impact.impactOccurred()
                // Let the alert finish leaving before the camera cover is presented.
                Task {
                    try? await Task.sleep(nanoseconds: 350_000_000)
                    showCamera = true
                }
            }
            Button("Use this photo") {
                guard let data = weakCapture else { return }
                weakCapture = nil
                Task { await handleCapture(data, asLabel: false) }
            }
        } message: {
            Text(weakCaptureReason)
        }
        .alert("Upload Failed", isPresented: $showErrorAlert) {
            Button("OK") { viewModel.uploadError = nil }
        } message: {
            Text(viewModel.uploadError ?? "")
        }
        // Label result — read-only: the one-tap Add / Not now is on Penny's / Otto's web card.
        .alert(viewModel.labelResult?.title ?? "Label read", isPresented: $showLabelResult) {
            Button("OK") { viewModel.labelResult = nil }
        } message: {
            Text(viewModel.labelResult.map { $0.message + ($0.kind != nil ? "\n\n\($0.head) will ask on the dashboard — nothing is added until you tap Add there." : "") } ?? "")
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
        // A queued receipt or action the server refused (review round 2): say so once, then clear it.
        .onChange(of: receiptQueue.lastProblem) { _, problem in
            guard let problem else { return }
            viewModel.uploadError = problem
            receiptQueue.clearProblem()
        }
        .onChange(of: actionQueue.lastProblem) { _, problem in
            guard let problem else { return }
            viewModel.actionError = problem
            actionQueue.clearProblem()
        }
        .onChange(of: pickerItem) { _, newItem in
            guard let newItem else { return }
            showLibrary = false
            Task {
                guard let raw = try? await newItem.loadTransferable(type: Data.self),
                      let picked = UIImage(data: raw) else {
                    viewModel.uploadError = "Couldn't open that photo — try another, or take a picture of the receipt."
                    return
                }
                let compressed: Data = await Task.detached(priority: .userInitiated) {
                    ReceiptsView.resizeAndCompress(picked)
                }.value
                // Same guard as the camera path: an empty JPEG is never uploaded or queued.
                guard !compressed.isEmpty else {
                    viewModel.uploadError = "Couldn't read that photo — try another, or take a picture of the receipt."
                    return
                }
                await handleCapture(compressed, asLabel: false)   // the library is for receipts
            }
        }
    }

    // MARK: - Camera result

    /// A photo from either camera. `scanned`: the document camera already cropped and straightened it.
    private func cameraCaptured(_ image: UIImage, scanned: Bool) {
        showCamera = false
        retakePending = false
        let asLabel = labelMode   // fixed now: another snap may change labelMode before this uploads
        Task {
            // Encode + compress off @MainActor — 12MP photos take ~1-2s on main thread.
            // A receipt is cropped to its edges and straightened first (labels are left as shot; a scan is already cropped).
            let (compressed, prep): (Data, ReceiptPhotoPrep.Outcome?) = await Task.detached(priority: .userInitiated) {
                guard !asLabel else { return (ReceiptsView.resizeAndCompress(image), nil) }
                let prep = ReceiptPhotoPrep.prepare(image, alreadyCropped: scanned)
                return (ReceiptsView.resizeAndCompress(prep.image), prep)
            }.value
            guard !compressed.isEmpty else { return }
            if let prep, prep.needsHint {
                raiseWeakCapture(compressed, reason: Self.weakCaptureMessage(prep))
                return
            }
            // Can the print be read? The phone's own text read (needed for the upload anyway) decides: a motion-blurred
            // auto-shot scored as sharp as a good photo on every pixel measure, but read 30 garbled characters where good
            // photos read ~550 (Tim's iPhone, 2026-11-06, media 1622). The same read then goes with the upload.
            var deviceRead: DeviceTextScanner.Prefetched? = nil
            if !asLabel {
                let read = DeviceTextScanner.Prefetched(compressed)
                if let result = await read.task.value, !Self.readLooksLikeAReceipt(result.text) {
                    raiseWeakCapture(compressed, reason: "The phone couldn't read the print — it looks blurred. Hold the phone steady "
                                     + "over the receipt until it takes the picture (or tap Auto at the top to take it yourself).")
                    return
                }
                deviceRead = read
            }
            await handleCapture(compressed, asLabel: asLabel, deviceRead: deviceRead)
        }
    }

    /// A weak photo: held while the crew picks Retake or Use this photo.
    private func raiseWeakCapture(_ data: Data, reason: String) {
        weakCaptureReason = reason
        weakCapture = data
        // Shown now if the camera has gone, otherwise from the cover's onDismiss.
        if !cameraOnScreen { showWeakCaptureHint = true }
    }

    /// The phone read enough of the photo to be a receipt: some text and at least one money amount. Pure.
    nonisolated static func readLooksLikeAReceipt(_ text: String) -> Bool {
        let chars = text.filter { !$0.isWhitespace }.count
        return chars >= 60 && text.range(of: #"\d[.,]\d{2}\b"#, options: .regularExpression) != nil
    }

    private func cameraCancelled() {
        showCamera = false
        // Retake abandoned: the uploaded receipt (intake + photo) was kept, so reopen it.
        if retakePending {
            retakePending = false
            if viewModel.intakeResponse != nil { showReview = true }
        }
    }

    // MARK: - Capture handler

    private static func captureFix() async -> CLLocation? {
        let lm = GPSTrackingService.shared.locationManager
        return await withTaskGroup(of: CLLocation?.self) { group in
            group.addTask { @MainActor in try? await lm.currentLocation() }
            group.addTask {
                try? await Task.sleep(nanoseconds: 4_000_000_000)
                return nil
            }
            let first = await group.next() ?? nil
            group.cancelAll()
            return first
        }
    }

    /// `asLabel` is fixed when the photo is taken (not read from `labelMode` later): a second snap started while
    /// this one compresses must not turn this receipt into a label or the other way round.
    /// `deviceRead`: a text read already made of this photo (the readability check), reused for the upload.
    private func handleCapture(_ compressed: Data, asLabel: Bool, deviceRead prefetched: DeviceTextScanner.Prefetched? = nil) async {
        // A fresh fix (≤4 s) so the receipt carries where it was photographed — that
        // teaches the vendor's store location and matches the job. The cached
        // CLLocationManager value alone was often nil on a cold start.
        // The phone's own text read starts now and runs while the fix settles; upload waits for both.
        let deviceRead: DeviceTextScanner.Prefetched? = asLabel ? nil : (prefetched ?? DeviceTextScanner.Prefetched(compressed))
        defer { deviceRead?.cancel() }   // owned by this capture; harmless once the upload has used it
        let loc = await Self.captureFix() ?? locationManager.location
        if asLabel {
            await viewModel.uploadLabel(compressed, lat: loc?.coordinate.latitude, lng: loc?.coordinate.longitude)
            if viewModel.labelResult != nil { showLabelResult = true }
            return
        }
        capturedImageData = compressed
        captureLat = loc?.coordinate.latitude
        captureLng = loc?.coordinate.longitude
        await viewModel.uploadImage(
            compressed,
            lat: captureLat,
            lng: captureLng,
            deviceRead: deviceRead
        )
        if viewModel.intakeResponse != nil { showReview = true }
    }

    // MARK: - List

    /// "Snap it" on Penny's card: the receipt camera. The saved receipt answers the charge on the
    /// server (same amount ±2 %, ±3 days); the card reloads when the review sheet closes.
    private func snapForPenny() {
        labelMode = false
        impact.impactOccurred()
        showCamera = true
    }

    private var expenseList: some View {
        Group {
            if viewModel.isLoadingList && viewModel.expenses.isEmpty {
                ProgressView("Loading…")
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if viewModel.expenses.isEmpty {
                VStack(spacing: 0) {
                    if !chase.items.isEmpty {
                        PennyCrewChaseCard(vm: chase, onSnap: snapForPenny)
                            .padding(16)
                            .background(appStyle.surface, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
                            .padding(16)
                    }
                    emptyState
                }
            } else {
                List {
                    if !chase.items.isEmpty || chase.message != nil {
                        Section {
                            PennyCrewChaseCard(vm: chase, onSnap: snapForPenny)
                        }
                        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
                    }
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
                        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
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
                .itAppForm()
                .refreshable {
                    await chase.loadMine()
                    await viewModel.loadExpenses()
                }
            }
        }
    }

    private var emptyState: some View {
        VStack(spacing: 16) {
            Image(systemName: "doc.text.image")
                .font(appStyle.font(size: 48))
                .foregroundStyle(appStyle.meta)
            Text("No receipts yet")
                .font(appStyle.font(.title2, weight: .bold))
            Text("Tap the camera in the Receipts tab to snap a receipt")
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.meta)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(appStyle.page)
    }

    // MARK: - Snap (the Receipts bead in the tab bar, or "Snap a label" in the ⋯ menu)

    /// Opens the camera for a receipt, or for a label (a product bag or a machine's nameplate; never an expense).
    private func snap(label: Bool) {
        isSelectMode = false
        selectedIds.removeAll()
        labelMode = label
        impact.impactOccurred()
        viewModel.uploadError = nil
        showCamera = true
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
                    .font(appStyle.font(.headline))
                    .frame(maxWidth: .infinity, minHeight: 52)
                    .background(appStyle.tenant)
                    .foregroundStyle(appStyle.onTenant)
                    .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
                    .padding()
            }
            .navigationTitle("Choose from Library")
            .navigationBarTitleDisplayMode(.inline)
            .itAppForm()
            .itAppScreen()
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showLibrary = false }
                        .foregroundStyle(appStyle.tenant)
                }
            }
        }
        .presentationDetents(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? [.large] : [.height(160)])
    }

    // MARK: - Upload overlay

    private var uploadOverlay: some View {
        ZStack {
            Color.black.opacity(0.45).ignoresSafeArea()
            VStack(spacing: 14) {
                ProgressView().scaleEffect(1.5).tint(.white)
                Text("Reading receipt…")
                    .font(appStyle.font(.headline))
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
                bulkActionButton(title: "Reject (\(selectedIds.count))", systemImage: "xmark", color: appStyle.blocked) {
                    bulkRejectReason = ""
                    showBulkRejectSheet = true
                }
                bulkActionButton(title: "Approve (\(selectedIds.count))", systemImage: "checkmark", color: appStyle.tenant) {
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
                .font(appStyle.font(.subheadline, weight: .semibold))
                .frame(maxWidth: .infinity, minHeight: appStyle.isEnabled ? 48 : 44)
                .background(color)
                .foregroundStyle(appStyle.isEnabled && color == appStyle.tenant ? appStyle.onTenant : .white)
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
        }
    }

    private var bulkRejectSheet: some View {
        NavigationStack {
            Form {
                Section("Reason for rejection (applies to all \(selectedIds.count) selected)") {
                    TextField("Explain why these expenses are being rejected…", text: $bulkRejectReason, axis: .vertical)
                        .lineLimit(3...6)
                }
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
            }
            .navigationTitle("Reject \(selectedIds.count) Receipts")
            .navigationBarTitleDisplayMode(.inline)
            .itAppForm()
            .itAppScreen()
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showBulkRejectSheet = false }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Reject") {
                        Task { await performBulkReject() }
                    }
                    .foregroundStyle(appStyle.blocked)
                    .disabled(bulkRejectReason.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
        }
        .presentationDetents(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? [.large] : [.medium])
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
        // Penny owns Receipts (Kaname spec §2). Her "needs receipts" card is already on this screen, so no line.
        ToolbarItem(placement: .navigationBarLeading) { ScreenHeadFace(slug: "penny") }
        ToolbarItem(placement: .principal) {
            HStack(spacing: 6) {
                Text("Receipts").font(appStyle.font(.headline, weight: .semibold))
                if receiptQueue.pendingCount + actionQueue.pendingCount > 0 {
                    Text("\(receiptQueue.pendingCount + actionQueue.pendingCount) pending")
                        .font(appStyle.font(.caption2, weight: .semibold))
                        .padding(.horizontal, 6).padding(.vertical, 2)
                        .background(appStyle.attention.opacity(0.15))
                        .foregroundStyle(appStyle.attention)
                        .clipShape(Capsule())
                }
            }
        }
        // The shift, as on every tab (Time left the bar, 2026-11-05). Selecting needs the room, so it steps aside.
        if !isSelectMode {
            ToolbarItem(placement: .navigationBarTrailing) { TimeChip() }
        }
        ToolbarItem(placement: .navigationBarTrailing) {
            Button {
                isSelectMode.toggle()
                if !isSelectMode { selectedIds.removeAll() }
            } label: {
                Text(isSelectMode ? "Cancel" : "Select")
                    .foregroundStyle(appStyle.tenant)
            }
        }
        if !isSelectMode {
            ToolbarItem(placement: .navigationBarTrailing) {
                // Not role-gated client-side, same rationale as ReceiptDetailView's action
                // bar — the server enforces expenses.send via jwtUserHasPermission().
                Menu {
                    Button { snap(label: true) } label: {
                        Label("Snap a label", systemImage: "tag.fill")
                    }
                    Button {
                        labelMode = false
                        pickerItem = nil
                        showLibrary = true
                    } label: {
                        Label("Choose from Library", systemImage: "photo.on.rectangle")
                    }
                    Button {
                        Task { await performArchiveExport() }
                    } label: {
                        Label("Archive & Export Old Receipts", systemImage: "archivebox")
                    }
                } label: {
                    Image(systemName: "ellipsis.circle").foregroundStyle(appStyle.tenant)
                }
            }
            ToolbarItem(placement: .navigationBarTrailing) {
                Button { Task { await viewModel.loadExpenses() } } label: {
                    Image(systemName: "arrow.clockwise").foregroundStyle(appStyle.tenant)
                }
                .disabled(viewModel.isLoadingList)
            }
        }
    }

    // MARK: - Image resize + compress

    // Matches Capacitor behaviour: max 1920px wide, 78% JPEG quality → ~200-600 KB
    /// What was wrong with the photo, in the crew's words.
    nonisolated static func weakCaptureMessage(_ prep: ReceiptPhotoPrep.Outcome) -> String {
        var why: [String] = []
        if prep.isSmall { why.append(prep.cropped ? "the receipt is small in the photo" : "the receipt's edges weren't found") }
        if prep.isSkewed { why.append("it was taken at an angle") }
        if prep.isBlurry { why.append("the print looks blurry") }
        let reason = why.isEmpty ? "" : why.joined(separator: ", ").prefix(1).uppercased() + why.joined(separator: ", ").dropFirst() + ". "
        return reason + "Lay it flat, hold the phone straight above it and let it fill the screen."
    }

    nonisolated static func resizeAndCompress(_ image: UIImage) -> Data {
        let maxDim: CGFloat = 1920
        let size = image.size
        let scale = size.width > maxDim || size.height > maxDim
            ? min(maxDim / size.width, maxDim / size.height) : 1.0
        let target = scale < 1.0
            ? CGSize(width: (size.width * scale).rounded(), height: (size.height * scale).rounded())
            : size
        // Scale 1: the default format draws at the screen's scale (3× on an iPhone), so a "1920" photo went up as
        // 5760 px squeezed under 1.5 MB — harder JPEG compression, softer print for the reader (2026-11-06, #533).
        let format = UIGraphicsImageRendererFormat.default()
        format.scale = 1
        let renderer = UIGraphicsImageRenderer(size: target, format: format)
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
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.itAppStyle) private var appStyle
    let expense: Expense
    var isSelectMode: Bool = false
    var isSelected: Bool = false

    var body: some View {
        (dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(alignment: .leading, spacing: 12)) : AnyLayout(HStackLayout(spacing: 12))) {
            if isSelectMode {
                Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                    .font(appStyle.font(.title3))
                    .foregroundStyle(isSelected ? appStyle.tenant : appStyle.meta)
            }
            receiptThumb
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 4) {
                    Text(expense.displayVendor)
                        .font(appStyle.font(.subheadline, weight: .semibold))
                        .lineLimit(appStyle.isEnabled ? nil : 1)
                    if expense.isArchived {
                        Image(systemName: "archivebox.fill")
                            .font(appStyle.font(.caption2))
                            .foregroundStyle(appStyle.meta)
                    }
                    anomalyIcon
                }
                HStack(spacing: 6) {
                    Text(formattedDate)
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                    if let cat = expense.accountingCategory {
                        Text(cat)
                            .font(appStyle.font(.caption2, weight: .medium))
                            .padding(.horizontal, 5).padding(.vertical, 1)
                            .background(appStyle.tenant.opacity(0.1))
                            .foregroundStyle(appStyle.tenant)
                            .clipShape(Capsule())
                    }
                }
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 3) {
                HStack(spacing: 4) {
                    confidenceDot
                    Text("$\(expense.total, specifier: "%.2f")")
                        .font(appStyle.font(.subheadline, weight: .semibold))
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
            let color: Color = tier == "high" ? appStyle.tenant : (tier == "medium" ? appStyle.attention : appStyle.blocked)
            Circle().fill(color).frame(width: 6, height: 6)
        }
    }

    /// Anomaly risk signal — matches the desktop/Android alert-triangle / alert-circle icons.
    @ViewBuilder
    private var anomalyIcon: some View {
        if let tier = expense.anomalyTier {
            Image(systemName: tier == "high" ? "exclamationmark.triangle.fill" : "exclamationmark.circle.fill")
                .font(appStyle.font(.caption2))
                .foregroundStyle(tier == "high" ? appStyle.blocked : appStyle.attention)
        }
    }

    private var receiptThumb: some View {
        Group {
            if let urlStr = expense.receiptUrl, let url = URL(string: urlStr) {
                AsyncImage(url: url) { img in
                    img.resizable().scaledToFill()
                } placeholder: {
                    appStyle.sunk
                }
                .frame(width: 44, height: 44)
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
            } else {
                RoundedRectangle(cornerRadius: appStyle.cornerRadius(8))
                    .fill(appStyle.sunk)
                    .frame(width: 44, height: 44)
                    .overlay { Image(systemName: "doc.text").foregroundStyle(appStyle.meta) }
            }
        }
    }

    private var statusBadge: some View {
        let (label, color): (String, Color) = switch expense.status {
        case "forwarded":        ("Sent", appStyle.progress)
        case "approved":         ("Approved", appStyle.tenant)
        case "rejected":         ("Rejected", appStyle.blocked)
        case "pending_approval": ("Pending", appStyle.attention)
        default:                 ("Draft", appStyle.attention)
        }
        return Text(label)
            .font(appStyle.font(.caption2, weight: .semibold))
            .padding(.horizontal, 5).padding(.vertical, 1)
            .background(appStyle.statusColor(expense.status, legacy: color).opacity(0.12))
            .foregroundStyle(appStyle.statusColor(expense.status, legacy: color))
            .clipShape(Capsule())
    }

    private var formattedDate: String {
        // A receipt's date is a Vancouver calendar day; read and shown in that zone (GMT showed it a day early).
        guard let date = VancouverDay.date(from: expense.expenseDate) else { return expense.expenseDate }
        return Self.dateFormatter.string(from: date)
    }

    /// Built once: a new DateFormatter per row per redraw showed up hot in the CPU reports (2026-11-04).
    private static let dateFormatter: DateFormatter = {
        let f = DateFormatter(); f.dateStyle = .medium; f.timeStyle = .none; f.timeZone = VancouverDay.zone
        return f
    }()
}

#Preview {
    ReceiptsView().environmentObject(AuthSession()).environmentObject(ReadyBriefStore())
}
