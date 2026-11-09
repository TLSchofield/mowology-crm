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
    /// The text read already made of the held photo, so "Use this photo" does not read it again.
    @State private var weakCaptureRead: DeviceTextScanner.Prefetched?
    /// Between the shot and the upload (crop + the phone's text read, up to ~6 s): the overlay shows, a second snap waits.
    @State private var isPreparingCapture = false
    @State private var weakCaptureReason = ""
    /// The queue entry for the photo held at the hint, and for the receipt in the review sheet.
    @State private var weakCaptureId: String?
    @State private var reviewQueueId: String?
    /// What the phone made of the held photo, sent with it if the person uses it anyway.
    @State private var weakCaptureChars: Int?
    @State private var weakCaptureSharpness: Double?
    /// Further pages of one document-camera session, each its own receipt, already on the phone. They wait their turn.
    @State private var nextPages: [CapturedPage] = []
    @State private var captureFixTask: Task<CLLocation?, Never>?
    @State private var showWaiting = false
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
            // Receipts: our camera, which won't shoot until the phone is steady and keeps the light on (Apple's document
            // camera can do neither). Its photos get the usual crop, so scanned: false. Apple's stays as the switched-off
            // path; labels, and phones with neither (the simulator too), keep the system camera.
            if !labelMode && ReceiptCameraSwitch.isOn() && UIImagePickerController.isSourceTypeAvailable(.camera) {
                ReceiptCameraView(onCapture: { cameraCaptured($0, scanned: false) }, onCancel: cameraCancelled)
            } else if !labelMode && DocumentCameraPicker.isSupported {
                DocumentCameraPicker(onCapture: { cameraCaptured($0, scanned: true) }, onCancel: cameraCancelled)
                    .ignoresSafeArea()
            } else {
                CameraPicker(onCapture: { cameraCaptured([$0], scanned: false) }, onCancel: cameraCancelled)
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
            if retakePending {
                // Retake comes first, even mid-scan: the other pages wait, held, for the new shot to finish.
                snapAnotherPending = false
                impact.impactOccurred()
                showCamera = true
            } else if !nextPages.isEmpty {
                snapAnotherPending = false
                Task { await processNextPage() }
            } else if snapAnotherPending {
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
                    },
                    onDiscard: {
                        if let id = reviewQueueId { ReceiptQueue.shared.finish(id) }
                        reviewQueueId = nil
                        viewModel.activeQueueId = nil
                    }
                ) { followUp in
                    reviewQueueId = nil   // saved: its queue entry is finished, and a later capture must not touch the id
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
            if viewModel.isUploading || isPreparingCapture {
                uploadOverlay
            }
        }
        .onChange(of: viewModel.uploadError) { _, err in
            if err != nil { showErrorAlert = true }
        }
        // The capture hint: a blurry, skewed or distant receipt photo reads badly on the server.
        .alert(ReceiptPhotoPrep.hintText, isPresented: $showWeakCaptureHint) {
            Button(nextPages.isEmpty ? "Retake" : "Discard") {
                if let id = weakCaptureId { ReceiptQueue.shared.finish(id) }
                clearWeakCapture()
                impact.impactOccurred()
                if !nextPages.isEmpty {
                    Task { await processNextPage() }
                    return
                }
                // Let the alert finish leaving before the camera cover is presented.
                Task {
                    try? await Task.sleep(nanoseconds: 350_000_000)
                    showCamera = true
                }
            }
            Button("Use this photo") {
                guard let data = weakCapture else { return }
                let read = weakCaptureRead
                let id = weakCaptureId
                let capture = Self.captureJSON(chars: weakCaptureChars, sharpness: weakCaptureSharpness, usedAnyway: true)
                clearWeakCapture()
                isPreparingCapture = true
                Task {
                    defer { isPreparingCapture = false }
                    await handleCapture(data, asLabel: false, queueId: id, deviceRead: read, captureJSON: capture)
                }
            }
        } message: {
            Text(weakCaptureReason)
        }
        .alert(viewModel.uploadSavedForLater ? "Saved, will send" : "Upload Failed", isPresented: $showErrorAlert) {
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
        .sheet(isPresented: $showWaiting) {
            WaitingReceiptsView(queue: receiptQueue, onRetry: { Task { await viewModel.drainPendingQueue() } })
        }
        .onChange(of: pickerItem) { _, newItem in
            guard let newItem else { return }
            showLibrary = false
            isPreparingCapture = true
            Task {
                defer { isPreparingCapture = false }
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

    /// One photo, already written to the phone (no queue entry for labels, or when the phone had no room to write it).
    private struct CapturedPage {
        let data: Data
        let prep: ReceiptPhotoPrep.Outcome?
        let queueId: String?
        let asLabel: Bool
    }

    /// Photos from any camera. `scanned`: the document camera already cropped and straightened them.
    private func cameraCaptured(_ images: [UIImage], scanned: Bool) {
        showCamera = false
        // A retake replaces the receipt under review: only now, so a cancelled retake still has it.
        if retakePending, let old = reviewQueueId { ReceiptQueue.shared.finish(old); reviewQueueId = nil; viewModel.activeQueueId = nil }
        retakePending = false
        let asLabel = labelMode   // fixed now: another snap may change labelMode before this uploads
        isPreparingCapture = true
        captureFixTask = Task { @MainActor in await Self.captureFix() }   // from the shot, alongside the rest
        Task {
            // Encode + compress off @MainActor — 12MP photos take ~1-2s on main thread.
            // A receipt is cropped to its edges and straightened first (labels are left as shot; a scan is already cropped).
            let done: [(Data, ReceiptPhotoPrep.Outcome?)] = await Task.detached(priority: .userInitiated) {
                images.map { image in
                    guard !asLabel else { return (ReceiptsView.resizeAndCompress(image), nil) }
                    let prep = ReceiptPhotoPrep.prepare(image, alreadyCropped: scanned)
                    return (ReceiptsView.resizeAndCompress(prep.image), prep)
                }
            }.value
            let usable = done.filter { !$0.0.isEmpty }
            guard !usable.isEmpty else {
                isPreparingCapture = false
                viewModel.uploadError = "Couldn't read that photo — please take it again."
                return
            }
            // In front of any pages still waiting from a scan that a retake interrupted.
            nextPages = usable.map { CapturedPage(data: $0.0, prep: $0.1, queueId: asLabel ? nil : viewModel.reservePhoto($0.0), asLabel: asLabel) } + nextPages
            await processNextPage()
        }
    }

    /// The next waiting page: the same check as any photo, then upload and review.
    private func processNextPage() async {
        guard !nextPages.isEmpty else { isPreparingCapture = false; return }
        let page = nextPages.removeFirst()
        isPreparingCapture = true
        defer { isPreparingCapture = false }
        if let prep = page.prep, prep.needsHint {
            raiseWeakCapture(page, reason: Self.weakCaptureMessage(prep), read: nil, chars: nil)
            return
        }
        // Can the print be read? The phone's own text read (needed for the upload anyway) decides: a motion-blurred
        // auto-shot scored as sharp as a good photo on every pixel measure, but read 30 garbled characters where good
        // photos read ~550 (Tim's iPhone, 2026-11-06, media 1622). The same read then goes with the upload.
        var deviceRead: DeviceTextScanner.Prefetched? = nil
        var chars: Int? = nil
        if !page.asLabel {
            let read = DeviceTextScanner.Prefetched(page.data)
            if let result = await read.task.value {
                chars = result.text.filter { !$0.isWhitespace }.count
                if !Self.readLooksLikeAReceipt(result.text) {
                    raiseWeakCapture(page, reason: "The phone couldn't read the print — it looks blurred. Hold the phone steady "
                                     + "over the receipt until it takes the picture (or tap Auto at the top to take it yourself).",
                                     read: read, chars: chars)
                    return
                }
            }
            deviceRead = read
        }
        await handleCapture(page.data, asLabel: page.asLabel, queueId: page.queueId, deviceRead: deviceRead,
                            captureJSON: Self.captureJSON(chars: chars, sharpness: page.prep?.sharpness, usedAnyway: false))
    }

    /// A weak photo: held while the crew picks Retake or Use this photo.
    private func raiseWeakCapture(_ page: CapturedPage, reason: String, read: DeviceTextScanner.Prefetched?, chars: Int?) {
        weakCaptureReason = reason
        weakCapture = page.data
        weakCaptureId = page.queueId
        weakCaptureRead = read
        weakCaptureChars = chars
        weakCaptureSharpness = page.prep?.sharpness
        // Shown now if the camera has gone, otherwise from the cover's onDismiss.
        if !cameraOnScreen { showWeakCaptureHint = true }
    }

    private func clearWeakCapture() {
        weakCapture = nil
        weakCaptureRead = nil
        weakCaptureId = nil
        weakCaptureChars = nil
        weakCaptureSharpness = nil
    }

    /// `client_capture`: how the phone judged the shot, so the thresholds can be set from real photos. Pure.
    nonisolated static func captureJSON(chars: Int?, sharpness: Double?, usedAnyway: Bool) -> String? {
        var fields: [String: Any] = ["used_anyway": usedAnyway]
        if let chars { fields["chars"] = chars }
        if let sharpness, sharpness.isFinite { fields["sharpness"] = (sharpness * 10).rounded() / 10 }
        guard let data = try? JSONSerialization.data(withJSONObject: fields) else { return nil }
        return String(data: data, encoding: .utf8)
    }

    /// The phone read enough of the photo to be a receipt: some text and at least one money amount. Pure.
    /// Plenty of print read = a readable photo, even with no printed price: handwritten receipts (Champion's repair-order
    /// pads) print only the form and the amounts are in ink (Tim, 2026-11-06: "because it's handwritten"). The blurred
    /// shot this guards against read ~30 characters; good photos ~550, a handwritten Champion form ~400.
    nonisolated static func readLooksLikeAReceipt(_ text: String) -> Bool {
        let chars = text.filter { !$0.isWhitespace }.count
        if chars >= 150 { return true }
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
    /// `queueId`: the photo's place on the phone. Nil from the library, written here before anything else.
    private func handleCapture(_ compressed: Data, asLabel: Bool, queueId: String? = nil,
                               deviceRead prefetched: DeviceTextScanner.Prefetched? = nil, captureJSON: String? = nil) async {
        let queueId = asLabel ? nil : (queueId ?? viewModel.reservePhoto(compressed))
        // A fresh fix (≤4 s) so the receipt carries where it was photographed — that
        // teaches the vendor's store location and matches the job. The cached
        // CLLocationManager value alone was often nil on a cold start.
        // The fix started at the shot where there was one; the phone's own text read runs alongside it.
        let deviceRead: DeviceTextScanner.Prefetched? = asLabel ? nil : (prefetched ?? DeviceTextScanner.Prefetched(compressed))
        defer { deviceRead?.cancel() }   // owned by this capture; harmless once the upload has used it
        let fix: CLLocation?
        if let captureFixTask { fix = await captureFixTask.value } else { fix = await Self.captureFix() }
        let loc = fix ?? locationManager.location
        if asLabel {
            await viewModel.uploadLabel(compressed, lat: loc?.coordinate.latitude, lng: loc?.coordinate.longitude)
            if viewModel.labelResult != nil { showLabelResult = true }
            return
        }
        await viewModel.uploadImage(
            compressed,
            queueId: queueId,
            lat: loc?.coordinate.latitude,
            lng: loc?.coordinate.longitude,
            deviceRead: deviceRead,
            captureJSON: captureJSON
        )
        if viewModel.intakeResponse != nil {
            // Paired here, after this photo's own answer: nothing started meanwhile can swap the picture beside the numbers.
            capturedImageData = compressed
            captureLat = loc?.coordinate.latitude
            captureLng = loc?.coordinate.longitude
            reviewQueueId = queueId
            showReview = true
        } else {
            // It didn't go through now, so the other pages won't either: they send by themselves with it.
            for page in nextPages { if let id = page.queueId { ReceiptQueue.shared.release(id) } }
            nextPages = []
        }
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
        guard !isPreparingCapture, !viewModel.isUploading, nextPages.isEmpty, weakCapture == nil else { return }   // the last shot isn't finished
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
                    Button { showWaiting = true } label: {
                        Text("\(receiptQueue.pendingCount + actionQueue.pendingCount) pending")
                            .font(appStyle.font(.caption2, weight: .semibold))
                            .padding(.horizontal, 6).padding(.vertical, 2)
                            .background(appStyle.attention.opacity(0.15))
                            .foregroundStyle(appStyle.attention)
                            .clipShape(Capsule())
                    }
                    .buttonStyle(.plain)
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

// MARK: - Waiting receipts

/// Every receipt still on this phone, with why it hasn't gone: from the "N pending" badge.
private struct WaitingReceiptsView: View {
    @Environment(\.dismiss) private var dismiss
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var queue: ReceiptQueue
    var onRetry: () -> Void
    @State private var confirmDiscard: ReceiptQueue.PendingItem?

    var body: some View {
        NavigationStack {
            List {
                if queue.waiting.isEmpty {
                    Text("Nothing waiting.").foregroundStyle(appStyle.meta)
                }
                ForEach(queue.waiting, id: \.id) { item in
                    VStack(alignment: .leading, spacing: 6) {
                        Text(item.queuedAt, format: .dateTime.month().day().hour().minute())
                            .font(appStyle.font(.subheadline, weight: .semibold))
                        Text(item.refused.map { "Not accepted: \($0)" } ?? ((item.attempts ?? 0) > 0 ? "Will try again by itself" : "Waiting to send"))
                            .font(appStyle.font(.caption))
                            .foregroundStyle(item.refused == nil ? appStyle.meta : appStyle.blocked)
                        HStack(spacing: 16) {
                            Button("Try again") { queue.retry(item.id); onRetry() }
                            Button("Discard", role: .destructive) { confirmDiscard = item }
                        }
                        .font(appStyle.font(.subheadline))
                        .buttonStyle(.borderless)
                    }
                    .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
                }
            }
            .itAppForm()
            .itAppScreen()
            .navigationTitle("Waiting receipts")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }.foregroundStyle(appStyle.tenant)
                }
            }
            .alert("Discard this receipt?", isPresented: Binding(get: { confirmDiscard != nil }, set: { if !$0 { confirmDiscard = nil } })) {
                Button("Discard", role: .destructive) { if let i = confirmDiscard { queue.finish(i.id) }; confirmDiscard = nil }
                Button("Keep", role: .cancel) { confirmDiscard = nil }
            } message: {
                Text("The photo will be removed from this phone and can't be sent afterwards.")
            }
        }
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
