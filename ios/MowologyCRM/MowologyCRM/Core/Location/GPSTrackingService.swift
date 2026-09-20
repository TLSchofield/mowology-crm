//
//  GPSTrackingService.swift
//  MowologyCRM
//
//  Shift-long location tracking. Starts at clock-in, ends at clock-out — and the
//  SERVER has the last word on "clocked in": every upload and status poll returns a
//  policy, and `tracking_allowed == false` stops capture on the spot. That is how an
//  auto clock-out or an office edit reaches a phone that is in someone's pocket.
//
//  Pipeline:  CoreLocation fix → quality filter → FixStore (disk) → batched upload
//             → server names each stored fix → only then is it removed.
//  Nothing is ever re-sent as if it were fresh, and nothing is deleted on a guess.
//

import Foundation
import Combine
import CoreLocation
import UIKit
import UserNotifications

// MARK: - Response models

struct AutoStartedPayload: Decodable {
    let visitId: Int
    let jobTitle: String?
    let propertyAddress: String?
    let distanceMeters: Int?
    let clockInCreated: Bool?

    enum CodingKeys: String, CodingKey {
        case visitId         = "visit_id"
        case jobTitle        = "job_title"
        case propertyAddress = "property_address"
        case distanceMeters  = "distance_meters"
        case clockInCreated  = "clock_in_created"
    }
}

extension Notification.Name {
    /// The server said tracking must stop (userInfo["reason"]: String). The Time Clock
    /// listens so its "Clocked In" state can't outlive the server's.
    static let mwTrackingStoppedByServer = Notification.Name("ca.mowology.trackingStoppedByServer")
}

// MARK: - GPSTrackingService

@MainActor
final class GPSTrackingService: ObservableObject {

    static let shared = GPSTrackingService()

    enum StopReason: Equatable {
        case clockedOut
        case signedOut
        case serverPolicy(String?)
    }

    // MARK: - Published (drives the tracking pill + diagnostics)

    @Published private(set) var isTracking = false
    @Published private(set) var trackingSince: Date?
    @Published private(set) var tier: TrackingTier = .off
    @Published private(set) var problem: TrackingProblem?
    @Published private(set) var queueDepth = 0
    @Published private(set) var lastFixAt: Date?
    @Published private(set) var lastUploadAt: Date?

    /// Set when a proximity auto-start fires; observers mirror the running timer.
    @Published private(set) var autoStartedPayload: AutoStartedPayload? = nil
    /// Set when the server stops a timer because the crew left the site.
    @Published private(set) var autoStoppedPayload: AutoStoppedPayload? = nil

    let locationManager = LocationManager()

    // MARK: - UserDefaults keys (shared with MowologyCRMApp BGTask handler)

    static let kShiftActive = "mw.shiftActive"
    static let kLastPingAt  = "mw.lastPingAt"

    // MARK: - Private

    private var apiClient: APIClient?
    private var loopTask:  Task<Void, Never>?
    private var isUploading = false
    private(set) var activeVisitId: Int? = nil

    private var serverTier: TrackingTier = .baseline
    private var insideRegions = Set<Int>()
    private var baselineInterval: TimeInterval = 60
    private var enhancedInterval: TimeInterval = 15
    private var statusPollInterval: TimeInterval = 300

    private var lastStoredFixAt: Date?
    private var lastServerContactAt: Date?
    private var lastGeofenceRefreshAt: Date?
    private var retryCounts: [String: Int] = [:]
    private var notifiedProblems = Set<TrackingProblem>()
    private var connectivityObserver: NSObjectProtocol?

    private var uploadInterval: TimeInterval { tier == .enhanced ? enhancedInterval : baselineInterval }
    /// Densest we ever store — the distance filter alone would flood the queue while driving.
    private var minStoreGap: TimeInterval { tier == .enhanced ? 5 : 20 }

    private init() {
        UIDevice.current.isBatteryMonitoringEnabled = true
        queueDepth = FixStore.shared.count
        connectivityObserver = NotificationCenter.default.addObserver(
            forName: .mwPingQueueOnline, object: nil, queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in await self?.upload() }
        }
    }

    // MARK: - Lifecycle

    func start(authSession: AuthSession) {
        apiClient = APIClient(authSession: authSession)   // refresh on every start — a re-login must not keep the old client
        guard !isTracking else { return }

        isTracking    = true
        trackingSince = Date()
        serverTier    = .baseline
        insideRegions.removeAll()
        notifiedProblems.removeAll()
        UserDefaults.standard.set(true, forKey: Self.kShiftActive)

        locationManager.onAcceptedFix = { [weak self] fix in self?.accept(fix) }
        locationManager.onRegionEvent = { [weak self] visitId, entered in self?.regionEvent(visitId: visitId, entered: entered) }
        locationManager.onAuthorizationChanged = { [weak self] in self?.authorizationChanged() }

        locationManager.requestAlwaysPermission()
        locationManager.startBackgroundTracking()
        recomputeTier()
        authorizationChanged()
        startLoop()

        Task {
            // Anything the old single-ping queue was still holding goes out once.
            if let client = apiClient, PingQueue.shared.pendingCount > 0 {
                await PingQueue.shared.drain(using: client)
            }
            await refreshGeofences()
            await upload()
        }
    }

    func stop(reason: StopReason = .clockedOut) {
        if reason == .signedOut {
            // Whatever is still on disk belongs to the person signing out — never the next user.
            FixStore.shared.removeAll()
            queueDepth = 0
        }
        guard isTracking || UserDefaults.standard.bool(forKey: Self.kShiftActive) else {
            if reason == .signedOut { apiClient = nil }
            return
        }

        isTracking    = false
        trackingSince = nil
        tier          = .off
        activeVisitId = nil
        insideRegions.removeAll()
        loopTask?.cancel()
        loopTask = nil
        locationManager.onAcceptedFix = nil
        locationManager.onRegionEvent = nil
        locationManager.onAuthorizationChanged = nil
        locationManager.stopBackgroundTracking()
        locationManager.resetSessionMetrics()
        problem = nil
        UserDefaults.standard.set(false, forKey: Self.kShiftActive)
        FixStore.shared.flushNow()

        switch reason {
        case .clockedOut:
            // Fixes captured during the shift are still owed to the server.
            Task { await upload() }
        case .signedOut:
            FixStore.shared.removeAll()
            queueDepth = 0
            apiClient  = nil
        case .serverPolicy(let why):
            NotificationCenter.default.post(name: .mwTrackingStoppedByServer, object: nil,
                                            userInfo: ["reason": why ?? ""])
        }
    }

    /// Cold launch, background relaunch (significant-change / geofence) or foreground:
    /// pick the shift back up WITHOUT needing the Time Clock tab to appear. The server
    /// confirms via policy within one upload/poll; if the shift is over, that stops us.
    func resumeIfShiftActive(authSession: AuthSession) {
        guard authSession.token != nil,
              UserDefaults.standard.bool(forKey: Self.kShiftActive) else { return }
        if !isTracking { start(authSession: authSession) }
        Task { await pollStatus() }
    }

    /// Fixes recorded during a shift are still owed to the server after it ends — an
    /// offline clock-out, or an app kill, can leave some on disk. Send them when we can.
    func flushBacklog(authSession: AuthSession) {
        guard authSession.token != nil, FixStore.shared.count > 0 else { return }
        if apiClient == nil { apiClient = APIClient(authSession: authSession) }
        Task { await upload() }
    }

    /// Call when a job timer starts (visitId) or stops (nil).
    func setActiveVisit(_ visitId: Int?) {
        activeVisitId = visitId
        if visitId != nil { locationManager.resetSessionMetrics() }
        // Don't wait for the server to notice — go dense the moment the job starts.
        serverTier = visitId != nil ? .enhanced : .baseline
        recomputeTier()
    }

    // MARK: - Fix intake

    private func accept(_ fix: CLLocation) {
        guard isTracking else { return }
        lastFixAt = fix.timestamp

        if let vid = activeVisitId {
            RouteStore.shared.record(visitId: vid, location: fix)
            ArrivalMonitor.shared.observe(fix: fix)
        }

        if let last = lastStoredFixAt, fix.timestamp.timeIntervalSince(last) < minStoreGap { return }
        lastStoredFixAt = fix.timestamp
        FixStore.shared.append(fix, visitId: activeVisitId, tier: tier)
        queueDepth = FixStore.shared.count

        if uploadIsDue { Task { await upload() } }
    }

    private var uploadIsDue: Bool {
        guard let last = lastUploadAt else { return true }
        return Date().timeIntervalSince(last) >= uploadInterval - 1
    }

    // MARK: - Tier

    private func recomputeTier() {
        guard isTracking else { return }
        let wanted: TrackingTier = (serverTier == .enhanced || !insideRegions.isEmpty) ? .enhanced : .baseline
        if wanted != tier {
            tier = wanted
            locationManager.setTier(wanted)
        }
    }

    private func regionEvent(visitId: Int, entered: Bool) {
        guard isTracking else { return }
        if entered { insideRegions.insert(visitId) } else { insideRegions.remove(visitId) }
        recomputeTier()
        if entered {
            // Arrived: get a precise fix on the record now. The server needs two fixes
            // inside the fence, ≥45 s apart, before it will start the job.
            locationManager.nudge()
            Task { await upload() }
        }
    }

    // MARK: - Authorization health

    private func authorizationChanged() {
        problem = locationManager.problem
        guard isTracking, let problem, !notifiedProblems.contains(problem) else { return }
        notifiedProblems.insert(problem)

        let content = UNMutableNotificationContent()
        content.title = "Location tracking needs attention"
        content.body  = problem.message
        content.sound = .default
        UNUserNotificationCenter.current().add(
            UNNotificationRequest(identifier: "mw.tracking-problem.\(problem.rawValue)", content: content, trigger: nil)
        )
    }

    // MARK: - Loop (stationary nudge, upload cadence, status poll, geofence refresh)

    private func startLoop() {
        loopTask?.cancel()
        loopTask = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 15_000_000_000)
                guard !Task.isCancelled, let self, self.isTracking else { break }
                await self.tick()
            }
        }
    }

    private func tick() async {
        let now = Date()

        // A crew standing still on site produces no distance-filtered fixes. Ask for a
        // fresh one rather than re-sending an old position with a new timestamp.
        if tier == .enhanced, now.timeIntervalSince(lastFixAt ?? .distantPast) > enhancedInterval * 2 {
            locationManager.nudge()
        }

        if uploadIsDue, FixStore.shared.count > 0 { await upload() }

        // Nothing to upload (parked, no fixes) — still ask whether the shift is alive.
        if now.timeIntervalSince(lastServerContactAt ?? .distantPast) >= statusPollInterval {
            await pollStatus()
        }
        if now.timeIntervalSince(lastGeofenceRefreshAt ?? .distantPast) >= 1800 {
            await refreshGeofences()
        }
    }

    // MARK: - Upload

    /// Used by the BGTask handler as a best-effort flush.
    func sendPing() async { await upload() }

    func upload() async {
        guard let client = apiClient, !isUploading else { return }
        isUploading = true
        defer { isUploading = false }

        while true {
            let batch = FixStore.shared.nextBatch()
            guard !batch.isEmpty else { break }

            let body: [String: Any] = ["points": batch.map(\.payload), "device": deviceHealth()]
            do {
                let response: FixUploadResponse = try await client.request(.scheduleLocation, body: body)
                lastServerContactAt = Date()

                var done = Set((response.accepted ?? []).compactMap(\.id))
                for verdict in response.rejected ?? [] {
                    guard let id = verdict.id else { continue }
                    if verdict.retryable == true {
                        // e.g. an offline clock-in that hasn't synced yet. Try a few times, not forever.
                        retryCounts[id, default: 0] += 1
                        if retryCounts[id, default: 0] >= 4 { done.insert(id) }
                    } else {
                        done.insert(id)
                    }
                }
                // A server predating per-fix verdicts answers success with no lists.
                if response.accepted == nil, response.rejected == nil, response.success {
                    done = Set(batch.map(\.id))
                }
                FixStore.shared.remove(ids: done)
                done.forEach { retryCounts.removeValue(forKey: $0) }
                queueDepth = FixStore.shared.count

                if response.success {
                    lastUploadAt = Date()
                    UserDefaults.standard.set(Date().timeIntervalSince1970, forKey: Self.kLastPingAt)
                }
                if let payload = response.autoStarted { handleAutoStart(payload) }
                if let payload = response.autoStopped { handleAutoStop(payload) }
                if let policy = response.policy { apply(policy) }

                // Keep draining a backlog, but never spin on a batch that isn't shrinking.
                if done.isEmpty || batch.count < 150 { break }
            } catch {
                break   // offline / server error — every fix is still on disk
            }
        }
    }

    private func pollStatus() async {
        guard let client = apiClient else { return }
        do {
            let response: TrackingStatusResponse = try await client.request(.trackingStatus)
            lastServerContactAt = Date()
            if let policy = response.policy { apply(policy) }
        } catch { }
    }

    private func apply(_ policy: TrackingPolicy) {
        baselineInterval   = TimeInterval(max(15, policy.baseline.intervalS))
        enhancedInterval   = TimeInterval(max(5,  policy.enhanced.intervalS))
        statusPollInterval = TimeInterval(max(60, policy.statusPollS ?? 300))

        guard policy.trackingAllowed else {
            // An offline clock-in that hasn't synced yet looks exactly like "not clocked
            // in" to the server. Don't end the shift's tracking over a sync-order race —
            // the clock queue drains on the same reconnect and the next policy settles it.
            if policy.reason == "not_clocked_in", ClockQueue.shared.hasPending { return }
            if isTracking { stop(reason: .serverPolicy(policy.reason)) }
            return
        }
        guard isTracking else { return }

        serverTier = policy.tier == .enhanced ? .enhanced : .baseline
        // A timer started elsewhere (web, another device) — stamp our fixes onto it too.
        if let vid = policy.activeVisitId, activeVisitId == nil { activeVisitId = vid }
        if policy.activeVisitId == nil, policy.tier != .enhanced, activeVisitId != nil { activeVisitId = nil }
        recomputeTier()
    }

    private func refreshGeofences() async {
        guard let client = apiClient, isTracking else { return }
        lastGeofenceRefreshAt = Date()
        do {
            let response: TrackingGeofencesResponse = try await client.request(.trackingGeofences)
            let open = (response.geofences ?? []).filter { ($0.status ?? "scheduled") != "completed" }
            locationManager.monitor(open)
        } catch { }
    }

    private func deviceHealth() -> [String: Any] {
        var d: [String: Any] = [
            "id":          UIDevice.current.identifierForVendor?.uuidString ?? "unknown",
            "platform":    "ios",
            "app_version": Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "",
            "permission":  locationManager.permissionLabel,
            "precise":     locationManager.isPrecise,
            "low_power":   ProcessInfo.processInfo.isLowPowerModeEnabled,
            "queue_depth": FixStore.shared.count
        ]
        let level = UIDevice.current.batteryLevel
        if level >= 0 { d["battery"] = Int(level * 100) }
        return d
    }

    // MARK: - Proximity auto-start

    /// The server started this visit's timer because the crew arrived. Bring local state
    /// in line and tell the crew — a phone in a pocket otherwise runs a timer nobody knows about.
    private func handleAutoStart(_ payload: AutoStartedPayload) {
        let today = Self.isoDay.string(from: Date())
        if let stop = ScheduleCache.shared.load(forDate: today)?
            .first(where: { $0.visits.contains { $0.visitId == payload.visitId } }),
           let lat = stop.latitude, let lng = stop.longitude {
            ArrivalMonitor.shared.configure(site: CLLocationCoordinate2D(latitude: lat, longitude: lng))
        }
        setActiveVisit(payload.visitId)
        if let fix = locationManager.lastLocation { ArrivalMonitor.shared.observe(fix: fix) }
        ArrivalMonitor.shared.jobStarted()

        let content = UNMutableNotificationContent()
        content.title = "Job started automatically"
        let place = payload.propertyAddress ?? payload.jobTitle ?? "the job site"
        content.body  = payload.clockInCreated == true
            ? "You arrived at \(place). You've been clocked in and the job timer is running."
            : "You arrived at \(place). The job timer is running."
        content.sound    = .default
        content.userInfo = ["type": "visit_auto_started", "visit_id": payload.visitId]
        UNUserNotificationCenter.current().add(
            UNNotificationRequest(identifier: "mw.auto-start.\(payload.visitId)", content: content, trigger: nil)
        )

        autoStartedPayload = payload
        Task {
            // Reset after a tick so observers see the change even if the same visit fires twice.
            try? await Task.sleep(nanoseconds: 100_000_000)
            autoStartedPayload = nil
        }
    }

    /// The crew left the site and the server stopped the timer at the moment they did. Tell
    /// them — the phone is in a pocket — and drop back to the between-jobs tier.
    private func handleAutoStop(_ payload: AutoStoppedPayload) {
        if activeVisitId == payload.visitId { setActiveVisit(nil) }

        let place   = payload.propertyAddress ?? payload.jobTitle ?? "the job site"
        let minutes = payload.durationMinutes.map { " after \($0) min" } ?? ""
        let content = UNMutableNotificationContent()
        content.title    = "Job timer stopped"
        content.body     = "You left \(place)\(minutes). Open the app to mark it complete — or head back and it resumes."
        content.sound    = .default
        content.userInfo = ["type": "visit_auto_started", "visit_id": payload.visitId]   // same route: opens the stop
        UNUserNotificationCenter.current().add(
            UNNotificationRequest(identifier: "mw.auto-stop.\(payload.visitId)", content: content, trigger: nil)
        )

        autoStoppedPayload = payload
        Task {
            try? await Task.sleep(nanoseconds: 100_000_000)
            autoStoppedPayload = nil
        }
    }

    private static let isoDay: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_US_POSIX")
        return f
    }()
}
