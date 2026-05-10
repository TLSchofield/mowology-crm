//
//  VisitWorkViewModel.swift
//  MowologyCRM
//
//  Drives the native on-site job timer view (equivalent of visit-work.php
//  for iOS native). Manages PoW state transitions, local elapsed timer,
//  and the 30s GPS breadcrumb flush loop.
//
//  ┌─ PRODUCTION SAFETY ──────────────────────────────────────────────────────┐
//  │  1. SINGLE GPS LOOP. `gpsFlushTask` is cancelled before any restart.     │
//  │     Never call startGpsLoop() twice — it would double-post breadcrumbs   │
//  │     and create duplicate rows in visit_gps_points.                        │
//  │                                                                           │
//  │  2. FLUSH BEFORE END. endVisit() drains pendingPoints to the server      │
//  │     before posting end_visit. Reversing the order would lose the final   │
//  │     GPS segment recorded during the visit.                                │
//  │                                                                           │
//  │  3. TIMER vs PoW TIMER. The 1s tickTimer here drives the on-screen       │
//  │     elapsed display only. The authoritative duration is computed server-  │
//  │     side from job_time_entries.start_time → end_time (UTC). Do NOT use   │
//  │     elapsedSeconds for billing calculations.                               │
//  │                                                                           │
//  │  4. IDEMPOTENT ACTIONS. pow-actions.php rejects duplicate start_visit    │
//  │     for an already-in_progress visit with a 409. Callers should treat    │
//  │     409 as "already started" and update UI state rather than retrying.   │
//  └───────────────────────────────────────────────────────────────────────────┘

import Foundation
import Combine
import CoreLocation

// MARK: - Response models

struct PowActionResponse: Decodable {
    let success: Bool
    let message: String?
    let startTime: String?
    let pdfUrl: String?

    enum CodingKeys: String, CodingKey {
        case success
        case message
        case startTime = "start_time"
        case pdfUrl   = "pdf_url"
    }
}

struct PowGpsSyncResponse: Decodable {
    let success: Bool
    let inserted: Int
    let skipped: Int
}

// MARK: - VisitWorkViewModel

@MainActor
final class VisitWorkViewModel: ObservableObject {

    // MARK: - Published state

    @Published var visitStatus: String   // mirrors API values: scheduled|in_progress|completed|cancelled
    @Published var elapsedSeconds: Int   = 0
    @Published var isLoading: Bool       = false
    @Published var errorMessage: String? = nil
    @Published var pendingNoteText: String = ""
    @Published var noteSaved: Bool       = false

    /// Non-nil after a successful end_visit + invoice-prefill fetch — triggers the compose sheet.
    @Published var invoicePrefill: InvoicePrefill? = nil

    // MARK: - Private

    private let visit: Visit
    private let visitId: Int
    let apiClient: APIClient
    private var tickTimer: AnyCancellable?
    private var gpsFlushTask: Task<Void, Never>?
    // Pending GPS breadcrumbs — appended on each flush tick, sent in batch.
    private var pendingPoints: [[String: Any]] = []
    private var lastLocation: CLLocation?

    let isAdmin: Bool

    // MARK: - Init

    init(visit: Visit, authSession: AuthSession) {
        self.visit     = visit
        self.visitId   = visit.visitId
        self.visitStatus = visit.visitStatus
        self.isAdmin   = authSession.user?.isAdmin ?? false
        self.apiClient = APIClient(authSession: authSession)

        // If app is launched mid-visit, resume the on-screen timer.
        // The authoritative elapsed is server-side; here we default to 0
        // and the worker can see the wall-clock for reference.
        if visit.visitStatus.lowercased() == "in_progress" {
            startLocalTimer()
            startGpsLoop()
        }
    }

    deinit {
        tickTimer?.cancel()
        gpsFlushTask?.cancel()
    }

    // MARK: - Public: visit state transitions

    func startVisit() async {
        guard visitStatus.lowercased() == "scheduled" else { return }
        isLoading    = true
        errorMessage = nil

        let loc = GPSTrackingService.shared.locationManager.lastLocation
        var body: [String: Any] = ["action": "start_visit", "visit_id": visitId]
        if let loc { body["lat"] = loc.coordinate.latitude; body["lng"] = loc.coordinate.longitude }

        do {
            let response: PowActionResponse = try await apiClient.request(.powActions, body: body)
            if response.success {
                visitStatus    = "in_progress"
                elapsedSeconds = 0
                startLocalTimer()
                startGpsLoop()
            } else {
                errorMessage = response.message ?? "Could not start visit."
            }
        } catch {
            errorMessage = friendlyError(error)
        }

        isLoading = false
    }

    func endVisit() async {
        guard visitStatus.lowercased() == "in_progress" else { return }
        isLoading    = true
        errorMessage = nil

        // Flush any buffered GPS points before marking the visit complete.
        // If the flush fails, the points are lost — acceptable since the visit
        // end timestamp is recorded server-side regardless.
        await flushGpsPoints()

        stopLocalTimer()
        gpsFlushTask?.cancel()
        gpsFlushTask = nil

        let loc = GPSTrackingService.shared.locationManager.lastLocation
        var body: [String: Any] = ["action": "end_visit", "visit_id": visitId]
        if let loc { body["lat"] = loc.coordinate.latitude; body["lng"] = loc.coordinate.longitude }
        if !pendingNoteText.isEmpty { body["notes"] = pendingNoteText }

        do {
            let response: PowActionResponse = try await apiClient.request(.powActions, body: body)
            if response.success {
                visitStatus = "completed"
            } else {
                errorMessage = response.message ?? "Could not end visit."
                // Resume timer — end failed, visit is still in progress
                startLocalTimer()
                startGpsLoop()
            }
        } catch {
            errorMessage = friendlyError(error)
            startLocalTimer()
            startGpsLoop()
        }

        isLoading = false
    }

    // MARK: - Complete & Invoice (admin-only combined gesture)

    /// Marks the visit complete then fetches invoice prefill data, triggering the compose sheet.
    /// If the prefill fetch fails (e.g., no signal), the visit is still marked complete
    /// server-side and `errorMessage` informs the admin to invoice manually later.
    func completeAndInvoice() async {
        guard visitStatus.lowercased() == "in_progress" else { return }
        isLoading = true; errorMessage = nil

        await flushGpsPoints()
        stopLocalTimer()
        gpsFlushTask?.cancel()
        gpsFlushTask = nil

        let loc = GPSTrackingService.shared.locationManager.lastLocation
        var endBody: [String: Any] = ["action": "end_visit", "visit_id": visitId]
        if let loc {
            endBody["lat"] = loc.coordinate.latitude
            endBody["lng"] = loc.coordinate.longitude
        }
        if !pendingNoteText.isEmpty { endBody["notes"] = pendingNoteText }

        do {
            let endResp: PowActionResponse = try await apiClient.request(.powActions, body: endBody)
            guard endResp.success else {
                errorMessage = endResp.message ?? "Could not complete visit."
                startLocalTimer()
                startGpsLoop()
                isLoading = false
                return
            }
            visitStatus = "completed"

            // Fetch prefill — triggers compose sheet via .sheet(item:) in VisitWorkView
            let prefillResp: InvoicePrefillResponse = try await apiClient.request(
                .scheduleInvoicePrefill(visitId: visitId)
            )

            if !prefillResp.success, let existingId = prefillResp.existingInvoiceId {
                // Already invoiced (race condition) — visit is complete, nothing more to do
                errorMessage = "Visit complete. Already invoiced (#\(existingId))."
            } else if let prefillData = prefillResp.data {
                invoicePrefill = prefillData   // triggers .sheet(item: $vm.invoicePrefill)
            } else {
                errorMessage = "Visit complete. Open Invoices to create the invoice manually."
            }

        } catch {
            if visitStatus.lowercased() == "completed" {
                // end_visit succeeded but prefill failed (network dropped after completion)
                errorMessage = "Visit complete. Open Invoices to create the invoice manually."
            } else {
                // end_visit itself failed — resume the job
                errorMessage = friendlyError(error)
                startLocalTimer()
                startGpsLoop()
            }
        }

        isLoading = false
    }

    func saveNote() async {
        let text = pendingNoteText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return }

        let body: [String: Any] = ["action": "save_notes", "visit_id": visitId, "note": text]
        do {
            let response: PowActionResponse = try await apiClient.request(.powActions, body: body)
            if response.success {
                pendingNoteText = ""
                noteSaved = true
                // Auto-dismiss the confirmation after 2s
                Task {
                    try? await Task.sleep(nanoseconds: 2_000_000_000)
                    noteSaved = false
                }
            } else {
                errorMessage = response.message ?? "Could not save note."
            }
        } catch {
            errorMessage = friendlyError(error)
        }
    }

    // MARK: - Formatted elapsed

    var elapsedFormatted: String {
        let h = elapsedSeconds / 3600
        let m = (elapsedSeconds % 3600) / 60
        let s = elapsedSeconds % 60
        if h > 0 { return String(format: "%d:%02d:%02d", h, m, s) }
        return String(format: "%02d:%02d", m, s)
    }

    // MARK: - Private: local timer

    private func startLocalTimer() {
        stopLocalTimer()
        tickTimer = Timer.publish(every: 1, on: .main, in: .common)
            .autoconnect()
            .sink { [weak self] _ in
                self?.elapsedSeconds += 1
                self?.collectGpsPoint()
            }
    }

    private func stopLocalTimer() {
        tickTimer?.cancel()
        tickTimer = nil
    }

    // MARK: - Private: GPS breadcrumb collection + flush

    /// Collects the current GPS position into pendingPoints.
    /// Called on every 1s tick so the buffer stays dense; flushed every 30s.
    private func collectGpsPoint() {
        guard let loc = GPSTrackingService.shared.locationManager.lastLocation else { return }

        // De-duplicate: skip if position hasn't changed since last collect.
        if let prev = lastLocation, loc.distance(from: prev) < 1.0 { return }
        lastLocation = loc

        pendingPoints.append([
            "lat":       loc.coordinate.latitude,
            "lng":       loc.coordinate.longitude,
            "accuracy":  loc.horizontalAccuracy,
            "speed":     max(0, loc.speed),
            "heading":   loc.course >= 0 ? loc.course : 0,
            "altitude":  loc.altitude,
            "source":    "ios_native",
            "timestamp": Int(loc.timestamp.timeIntervalSince1970 * 1000),
        ])

        // Flush every 30 accumulated points (≈ 30s of motion)
        if pendingPoints.count >= 30 {
            Task { await flushGpsPoints() }
        }
    }

    /// Background task that flushes any pending GPS points every 30s.
    private func startGpsLoop() {
        gpsFlushTask?.cancel()
        gpsFlushTask = Task {
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 30_000_000_000)
                guard !Task.isCancelled else { break }
                await flushGpsPoints()
            }
        }
    }

    /// Posts the current pendingPoints batch to pow-gps-sync.php.
    /// Empties the buffer on success; leaves it intact on failure so the
    /// next flush attempt includes the unsent points.
    private func flushGpsPoints() async {
        guard !pendingPoints.isEmpty else { return }

        let batch = pendingPoints
        let body: [String: Any] = [
            "action":   "sync_points",
            "visit_id": visitId,
            "points":   batch,
        ]

        do {
            let response: PowGpsSyncResponse = try await apiClient.request(.powGpsSync, body: body)
            if response.success {
                pendingPoints.removeAll()
            }
            // On failure: keep pendingPoints — next flush will retry
        } catch {
            // Silently swallow — GPS sync is non-critical for PoW validity.
            // The visit end timestamp is the authoritative record.
        }
    }

    private func friendlyError(_ error: Error) -> String {
        if let apiError = error as? APIError { return apiError.localizedDescription }
        return error.localizedDescription
    }
}
