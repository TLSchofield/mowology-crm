//
//  TimeClockViewModel.swift
//  MowologyCRM
//

import Foundation
import UIKit
import Combine
import UserNotifications

@MainActor
final class TimeClockViewModel: ObservableObject {

    // MARK: - Published State

    @Published var clockedIn: Bool      = false
    @Published var entryId: Int?        = nil
    @Published var clockInTime: String? = nil
    @Published var elapsedSeconds: Int  = 0

    @Published var activeJob: ActiveJobTimer? = nil

    @Published var isLoading: Bool        = false
    @Published var errorMessage: String?  = nil

    /// True when a clock action was accepted locally but not yet confirmed by the server.
    @Published private(set) var isPendingSync: Bool = false
    /// A punch the server refused 5 times — still saved on the phone; the person must tell the office.
    @Published private(set) var rejectedPunch: String? = nil

    // MARK: - Private

    private let apiClient: APIClient
    private var tickTimer: AnyCancellable?
    private var connectivityObserver: NSObjectProtocol?
    private var serverStopObserver: NSObjectProtocol?

    // MARK: - Tracking consent

    /// Non-nil while the location disclosure should be on screen (first clock-in, or
    /// whenever the disclosure version changes). Clock-in continues once it is agreed.
    @Published var pendingDisclosure: TrackingDisclosure?
    /// True when the office has made consent a condition of tracking.
    @Published private(set) var consentRequired = false
    /// Why the server ended tracking, when it wasn't the crew member clocking out.
    @Published var serverStopNotice: String?

    // MARK: - Init

    private let authSession: AuthSession

    init(authSession: AuthSession) {
        self.authSession = authSession
        self.apiClient   = APIClient(authSession: authSession)

        // Drain queued clock actions automatically when connectivity is restored.
        connectivityObserver = NotificationCenter.default.addObserver(
            forName: .mwPingQueueOnline,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                await self?.drainAndReconcile()
            }
        }

        // The server ended tracking (auto clock-out, office edit, consent withdrawn…).
        // Re-read the truth so this screen can't keep saying "Clocked In".
        serverStopObserver = NotificationCenter.default.addObserver(
            forName: .mwTrackingStoppedByServer,
            object: nil,
            queue: .main
        ) { [weak self] note in
            let reason = note.userInfo?["reason"] as? String
            Task { @MainActor [weak self] in
                self?.serverStopNotice = Self.notice(forServerStop: reason)
                await self?.loadStatus()
            }
        }
    }

    deinit {
        if let obs = connectivityObserver { NotificationCenter.default.removeObserver(obs) }
        if let obs = serverStopObserver   { NotificationCenter.default.removeObserver(obs) }
    }

    private static func notice(forServerStop reason: String?) -> String {
        switch reason {
        case "not_clocked_in":    return "You were clocked out by the office or the end-of-day auto clock-out. Location tracking has stopped."
        case "consent_required":  return "Location tracking is paused until you review and agree to the location disclosure."
        case "tracking_disabled": return "Location tracking has been turned off for your account."
        case "on_break":          return "You're on a break — location sharing is paused until you tap End break."
        default:                  return "Location tracking has stopped."
        }
    }

    // MARK: - Consent

    /// Clock-in entry point for the UI: shows the location disclosure first when the
    /// crew member hasn't agreed to the current version, then clocks in.
    func clockInWithConsentCheck() async {
        do {
            let response: TrackingConsentResponse = try await apiClient.request(.trackingConsent)
            consentRequired = response.consent?.required ?? false
            if response.consent?.current == false, let disclosure = response.disclosure {
                pendingDisclosure = disclosure
                return
            }
        } catch {
            // Offline or an older server — never block a clock-in on the disclosure fetch.
        }
        await clockIn()
    }

    func agreeToDisclosure() async {
        guard let disclosure = pendingDisclosure else { return }
        do {
            let _: TrackingStatusResponse = try await apiClient.request(.trackingAction, body: [
                "action":  "consent",
                "version": disclosure.version,
                "device":  [
                    "id":          UIDevice.current.identifierForVendor?.uuidString ?? "unknown",
                    "platform":    "ios",
                    "app_version": Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? ""
                ]
            ])
            pendingDisclosure = nil
            await clockIn()
        } catch {
            errorMessage = (error as? APIError)?.localizedDescription ?? error.localizedDescription
        }
    }

    /// "Not now" — allowed only while consent is not yet a condition of tracking.
    func declineDisclosure() async {
        pendingDisclosure = nil
        if !consentRequired { await clockIn() }
    }

    // MARK: - Load

    func loadStatus() async {
        isLoading    = true
        errorMessage = nil

        // Send any punch made offline BEFORE reading the server — it carries the time it was tapped.
        // (This used to clear the queue whenever the server answered, so opening the app after
        // signal came back could throw an offline clock-in away.)
        if ClockQueue.shared.hasPending {
            if await ClockQueue.shared.drain(using: apiClient) == .offline {
                restorePersistedClockState()
                isPendingSync = true
                isLoading     = false
                return
            }
            isPendingSync = ClockQueue.shared.waitingCount(for: authSession.user?.id) > 0
        }
        rejectedPunch = ClockQueue.shared.rejectedMessage(for: authSession.user?.id)

        do {
            let response: ClockStatusResponse = try await apiClient.request(
                .scheduleClockStatus
            )
            applyStatus(response)
        } catch let err as APIError {
            if case .networkError = err {
                // Offline — restore last-known clock state from disk so the UI
                // shows the correct clocked-in/out status without an error message.
                restorePersistedClockState()
                if ClockQueue.shared.hasPending { isPendingSync = true }
            } else {
                errorMessage = friendlyError(err)
            }
        } catch {
            errorMessage = friendlyError(error)
        }

        isLoading = false
    }

    // MARK: - Clock In / Out

    /// Where the punch happened. Callers rarely have a coordinate to hand, so the
    /// view model resolves one itself — otherwise every punch reaches the server
    /// with no location. Reuses the tracking service's fix when it is ≤30 s old,
    /// so a clock-out during a tracked shift costs nothing.
    private func punchLocation() async -> (Double?, Double?) {
        let lm = GPSTrackingService.shared.locationManager
        lm.requestWhenInUsePermission()
        guard lm.canUseLocation else { return (nil, nil) }
        let loc = (try? await lm.currentLocation()) ?? lm.lastLocation
        return (loc?.coordinate.latitude, loc?.coordinate.longitude)
    }

    func clockIn(lat: Double? = nil, lng: Double? = nil) async {
        isLoading    = true
        errorMessage = nil
        let tappedAt = Date()   // before the location wait — this is when the punch happened

        var (lat, lng) = (lat, lng)
        if lat == nil || lng == nil { (lat, lng) = await punchLocation() }

        var body: [String: Any] = ["action": "clock_in", "client_time": tappedAt.mwClientTime]
        if let lat { body["lat"] = lat }
        if let lng { body["lng"] = lng }

        do {
            let response: ClockActionResponse = try await apiClient.request(
                .scheduleClock,
                body: body
            )
            clockedIn      = response.clockedIn
            entryId        = response.entryId
            clockInTime    = response.clockIn
            elapsedSeconds = response.elapsedSeconds ?? 0
            isPendingSync  = false
            persistClockState()
            if clockedIn {
                startTicking()
                BreakController.shared.clockedOut()          // a new shift starts with no break
                GPSTrackingService.shared.start(authSession: authSession)
            }
        } catch let err as APIError {
            if case .networkError = err {
                // Offline — accept locally and queue for sync.
                clockedIn     = true
                isPendingSync = true
                ClockQueue.shared.enqueue(action: "clock_in", lat: lat, lng: lng, at: tappedAt, userId: authSession.user?.id)
                persistClockState()
                startTicking()
                GPSTrackingService.shared.start(authSession: authSession)
            } else {
                errorMessage = err.localizedDescription
            }
        } catch {
            errorMessage = friendlyError(error)
        }

        isLoading = false
    }

    func clockOut(lat: Double? = nil, lng: Double? = nil) async {
        isLoading    = true
        errorMessage = nil
        let tappedAt = Date()   // before the location wait — this is when the punch happened

        var (lat, lng) = (lat, lng)
        if lat == nil || lng == nil { (lat, lng) = await punchLocation() }

        var body: [String: Any] = ["action": "clock_out", "client_time": tappedAt.mwClientTime]
        if let lat { body["lat"] = lat }
        if let lng { body["lng"] = lng }

        do {
            let response: ClockActionResponse = try await apiClient.request(
                .scheduleClock,
                body: body
            )
            clockedIn      = false
            entryId        = nil
            clockInTime    = nil
            elapsedSeconds = 0
            activeJob      = nil
            isPendingSync  = false
            persistClockState()
            stopTicking()
            BreakController.shared.clockedOut()   // clocking out during a break ends it (server side too)
            GPSTrackingService.shared.stop()
            _ = response  // totalMinutes available if needed for summary display
        } catch let err as APIError {
            if case .networkError = err {
                // Offline — accept locally and queue for sync.
                clockedIn      = false
                entryId        = nil
                clockInTime    = nil
                elapsedSeconds = 0
                activeJob      = nil
                isPendingSync  = true
                ClockQueue.shared.enqueue(action: "clock_out", lat: lat, lng: lng, at: tappedAt, userId: authSession.user?.id)
                persistClockState()
                stopTicking()
                BreakController.shared.clockedOut()
                GPSTrackingService.shared.stop()
            } else {
                errorMessage = err.localizedDescription
            }
        } catch {
            errorMessage = friendlyError(error)
        }

        isLoading = false
    }

    // MARK: - Formatted Elapsed

    var elapsedFormatted: String {
        let h = elapsedSeconds / 3600
        let m = (elapsedSeconds % 3600) / 60
        let s = elapsedSeconds % 60
        if h > 0 {
            return String(format: "%d:%02d:%02d", h, m, s)
        }
        return String(format: "%02d:%02d", m, s)
    }

    /// The person has dealt with a refused punch another way (told the office).
    func discardRejectedPunches() {
        ClockQueue.shared.discardRejected(for: authSession.user?.id)
        rejectedPunch = nil
    }

    // MARK: - Private

    private func drainAndReconcile() async {
        let result = await ClockQueue.shared.drain(using: apiClient)
        rejectedPunch = ClockQueue.shared.rejectedMessage(for: authSession.user?.id)
        switch result {
        case .empty:
            isPendingSync = false
        case .success, .reconcile:
            isPendingSync = false
            // Reload from server to confirm our optimistic state.
            await loadStatus()
        case .offline:
            break  // still no signal — keep pending state
        }
    }

    private func applyStatus(_ response: ClockStatusResponse) {
        clockedIn      = response.clockedIn
        entryId        = response.entryId
        clockInTime    = response.clockIn
        elapsedSeconds = response.elapsedSeconds ?? 0
        activeJob      = response.activeJob
        persistClockState()
        BreakController.shared.apply(response)

        if clockedIn {
            startTicking()
            // Resume always-on tracking if still clocked in after app relaunch — unless on a
            // break, when sharing stays paused until End break.
            if BreakController.shared.isOnBreak {
                GPSTrackingService.shared.pauseForBreak()
            } else {
                GPSTrackingService.shared.clearBreakPause()
                GPSTrackingService.shared.start(authSession: authSession)
            }
        } else {
            stopTicking()
            GPSTrackingService.shared.stop()
        }
    }

    // MARK: - Clock State Persistence

    private enum PersistKey {
        static let clockedIn  = "mw.clock.state.clockedIn"
        static let entryId    = "mw.clock.state.entryId"
        static let clockInTime = "mw.clock.state.clockInTime"
    }

    /// Persisted "am I clocked in" flag — read by the sign-out prompt when there is no signal.
    static var persistedClockedIn: Bool {
        UserDefaults.standard.bool(forKey: PersistKey.clockedIn)
    }

    /// After a clock-out made outside this view model (the sign-out prompt), so the next
    /// sign-in does not restore a stale "clocked in" and restart location capture.
    static func clearPersistedClockState() {
        let d = UserDefaults.standard
        d.set(false, forKey: PersistKey.clockedIn)
        d.set(0, forKey: PersistKey.entryId)
        d.removeObject(forKey: PersistKey.clockInTime)
    }

    private func persistClockState() {
        let d = UserDefaults.standard
        d.set(clockedIn, forKey: PersistKey.clockedIn)
        d.set(entryId ?? 0, forKey: PersistKey.entryId)
        d.set(clockInTime, forKey: PersistKey.clockInTime)
    }

    private func restorePersistedClockState() {
        let d         = UserDefaults.standard
        clockedIn     = d.bool(forKey: PersistKey.clockedIn)
        let savedId   = d.integer(forKey: PersistKey.entryId)
        entryId       = savedId > 0 ? savedId : nil
        clockInTime   = d.string(forKey: PersistKey.clockInTime)
        // Elapsed seconds will restart from 0 — server reconciles on reconnect.
        if clockedIn {
            startTicking()
            GPSTrackingService.shared.start(authSession: authSession)   // a no-op while paused for a break
        }
    }

    private func startTicking() {
        stopTicking()
        tickTimer = Timer.publish(every: 1, on: .main, in: .common)
            .autoconnect()
            .sink { [weak self] _ in
                self?.elapsedSeconds += 1
            }
    }

    private func stopTicking() {
        tickTimer?.cancel()
        tickTimer = nil
    }

    private func friendlyError(_ error: Error) -> String {
        if let apiError = error as? APIError {
            return apiError.localizedDescription
        }
        return error.localizedDescription
    }
}


// MARK: - Breaks

extension Notification.Name {
    /// The break started or ended (from this screen, a notification action or the server).
    static let mwBreakChanged = Notification.Name("ca.mowology.breakChanged")
}

/// Start break / End break (2026-10-13). One owner for the break so the Time Clock screen and the
/// "Still on break?" notification actions share it — the notification can end a break with the
/// Time Clock tab never opened. Server first; with no signal the tap is queued in ClockQueue (same
/// order as the clock punches) with the time it was tapped, and the screen says it is saved on the
/// phone. Truck logins never get the button (the server says `breaks.allowed = false`).
@MainActor
final class BreakController: ObservableObject {

    static let shared = BreakController()

    static let categoryId    = "MW_BREAK_REMINDER"
    static let endActionId   = "MW_END_BREAK"
    static let stillActionId = "MW_STILL_ON_BREAK"
    static let reminderId    = "mw.break.reminder"

    /// When the break started (server time, or the tap time while it waits in the queue).
    @Published private(set) var startedAt: Date?
    @Published private(set) var allowed = false
    @Published private(set) var paid = true
    @Published private(set) var reminderMinutes = 60
    @Published private(set) var isBusy = false
    /// True while the break tap is saved on the phone but not yet filed.
    @Published private(set) var savedLocally = false
    @Published var message: String?

    var isOnBreak: Bool { startedAt != nil }

    private let kLocal = "mw.break.localStart"

    private init() {}

    // MARK: Notification category

    static func registerNotificationCategory() {
        let end   = UNNotificationAction(identifier: endActionId, title: "End break", options: [.foreground])
        let still = UNNotificationAction(identifier: stillActionId, title: "Still on break", options: [])
        let cat   = UNNotificationCategory(identifier: categoryId, actions: [end, still], intentIdentifiers: [], options: [])
        let center = UNUserNotificationCenter.current()
        center.getNotificationCategories { existing in
            var all = existing.filter { $0.identifier != categoryId }
            all.insert(cat)
            center.setNotificationCategories(all)
        }
    }

    // MARK: State from the server

    func apply(_ status: ClockStatusResponse) {
        allowed         = status.breaks?.allowed ?? false
        paid            = status.breaks?.paid ?? true
        reminderMinutes = max(5, status.breaks?.reminderMinutes ?? 60)
        if !status.clockedIn {
            clockedOut()
            return
        }
        if let b = status.onBreak {
            startedAt    = Self.parse(b.startedAt) ?? startedAt ?? Date()
            savedLocally = false
            UserDefaults.standard.removeObject(forKey: kLocal)
            scheduleReminder(at: nil)
        } else if let local = UserDefaults.standard.object(forKey: kLocal) as? Date, ClockQueue.shared.hasPending {
            // The tap is still waiting in the queue — keep showing it.
            startedAt    = local
            savedLocally = true
        } else {
            startedAt    = nil
            savedLocally = false
            UserDefaults.standard.removeObject(forKey: kLocal)
            cancelReminder()
        }
        NotificationCenter.default.post(name: .mwBreakChanged, object: nil)
    }

    /// Clock-out (or a fresh clock-in): no break is running.
    func clockedOut() {
        startedAt    = nil
        savedLocally = false
        UserDefaults.standard.removeObject(forKey: kLocal)
        cancelReminder()
        GPSTrackingService.shared.clearBreakPause()
    }

    /// Foreground while paused: is the break still running? Resume sharing if not.
    func refreshFromServer(authSession: AuthSession) async {
        guard authSession.token != nil else { return }
        let api = APIClient(authSession: authSession)
        do {
            let status: ClockStatusResponse = try await api.request(.scheduleClockStatus)
            apply(status)
            if status.clockedIn && !isOnBreak {
                GPSTrackingService.shared.resumeAfterBreak(authSession: authSession)
            }
        } catch {
            // No signal: stay paused — the person is on a break as far as this phone knows.
        }
    }

    // MARK: Start / End

    func startBreak(authSession: AuthSession) async {
        guard !isBusy, !isOnBreak else { return }
        isBusy = true; defer { isBusy = false }
        message = nil
        let tappedAt = Date()
        let api = APIClient(authSession: authSession)
        do {
            let r: ClockActionResponse = try await api.request(.scheduleClock, body: [
                "action": "break_start", "client_time": tappedAt.mwClientTime,
            ])
            guard r.recorded != false else { message = r.message ?? "Break not recorded."; return }
            guard let b = r.onBreak else { await refreshFromServer(authSession: authSession); return }
            startedAt    = Self.parse(b.startedAt) ?? tappedAt
            savedLocally = false
        } catch let err as APIError {
            if case .networkError = err {
                ClockQueue.shared.enqueue(action: "break_start", lat: nil, lng: nil, at: tappedAt, userId: authSession.user?.id)
                UserDefaults.standard.set(tappedAt, forKey: kLocal)
                startedAt    = tappedAt
                savedLocally = true
                message      = "Break saved on this phone — it will file itself when signal returns."
            } else {
                message = err.localizedDescription   // a refusal (not clocked in, truck login) is shown, never queued
                return
            }
        } catch {
            message = error.localizedDescription
            return
        }
        GPSTrackingService.shared.pauseForBreak()
        scheduleReminder(at: nil)
        NotificationCenter.default.post(name: .mwBreakChanged, object: nil)
    }

    func endBreak(authSession: AuthSession) async {
        guard !isBusy, isOnBreak else { return }
        isBusy = true; defer { isBusy = false }
        message = nil
        let tappedAt = Date()
        let api = APIClient(authSession: authSession)
        do {
            let r: ClockActionResponse = try await api.request(.scheduleClock, body: [
                "action": "break_end", "client_time": tappedAt.mwClientTime,
            ])
            if r.recorded == false { message = r.message }
        } catch let err as APIError {
            if case .networkError = err {
                ClockQueue.shared.enqueue(action: "break_end", lat: nil, lng: nil, at: tappedAt, userId: authSession.user?.id)
                message = "Break end saved on this phone — it will file itself when signal returns."
            } else {
                message = err.localizedDescription
                return
            }
        } catch {
            message = error.localizedDescription
            return
        }
        startedAt    = nil
        savedLocally = false
        UserDefaults.standard.removeObject(forKey: kLocal)
        cancelReminder()
        GPSTrackingService.shared.resumeAfterBreak(authSession: authSession)
        NotificationCenter.default.post(name: .mwBreakChanged, object: nil)
    }

    // MARK: "Still on break?"

    func handleReminderAction(_ actionId: String) async {
        switch actionId {
        case Self.endActionId:
            if !isOnBreak { await refreshFromServer(authSession: AuthSession.shared) }
            await endBreak(authSession: AuthSession.shared)
        case Self.stillActionId:
            scheduleReminder(at: Date().addingTimeInterval(TimeInterval(reminderMinutes * 60)))
        default:
            break   // the banner itself was tapped: the app opens on whatever was showing
        }
    }

    /// Default: break start + reminder_minutes (from the office's setting, 60 by default).
    private func scheduleReminder(at when: Date?) {
        guard let started = startedAt else { return }
        let fire = when ?? started.addingTimeInterval(TimeInterval(reminderMinutes * 60))
        let content = UNMutableNotificationContent()
        content.title = "Still on break?"
        content.body  = "Your break started at \(Self.clock(started)). Tap End break when you're back at work."
        content.sound = .default
        content.categoryIdentifier = Self.categoryId
        content.userInfo = ["type": "break_reminder"]
        let trigger = UNTimeIntervalNotificationTrigger(timeInterval: max(5, fire.timeIntervalSinceNow), repeats: false)
        let center = UNUserNotificationCenter.current()
        center.removePendingNotificationRequests(withIdentifiers: [Self.reminderId])
        center.add(UNNotificationRequest(identifier: Self.reminderId, content: content, trigger: trigger))
    }

    private func cancelReminder() {
        let center = UNUserNotificationCenter.current()
        center.removePendingNotificationRequests(withIdentifiers: [Self.reminderId])
        center.removeDeliveredNotifications(withIdentifiers: [Self.reminderId])
    }

    // MARK: Formatting

    /// "12:30" — the clock time the break started.
    var sinceLabel: String { startedAt.map(Self.clock) ?? "" }

    static func clock(_ d: Date) -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_CA")
        f.dateFormat = "h:mm"
        return f.string(from: d)
    }

    static func parse(_ raw: String) -> Date? {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        f.locale = Locale(identifier: "en_US_POSIX")
        return f.date(from: raw)
    }
}
