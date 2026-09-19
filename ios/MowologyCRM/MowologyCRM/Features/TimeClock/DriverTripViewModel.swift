//
//  DriverTripViewModel.swift
//  MowologyCRM
//
//  Who must log a vehicle inspection is decided per SHIFT, not per person: whoever is
//  driving the company vehicle today. So the app asks at clock-in, lets the answer
//  change mid-shift in either direction, and only holds someone at clock-out if they
//  have an open trip to close.
//
//  Server first, phone as the BACKUP. With signal, an entry is filed straight to the server
//  and the driver sees it confirmed (or sees the problem while still on the form). Only
//  when the server can't be reached is it saved to TripReportQueue — with the time it was
//  actually done — and filed automatically later. The driver is responsible for their own
//  log, so a dead zone must never be the reason one doesn't exist.
//

import Foundation
import Combine
import UIKit

@MainActor
final class DriverTripViewModel: ObservableObject {

    @Published private(set) var status: TripReportStatus?
    @Published private(set) var isWorking = false
    @Published var errorMessage: String?

    /// Sheets
    @Published var showDeclaration = false
    @Published var showPreTrip     = false
    @Published var showPostTrip    = false
    /// Set when an inspection was saved but the vehicle may NOT be driven.
    @Published var groundedMessage: String?

    /// "Filed" / "Saved on this phone" — shown on the card after an entry.
    @Published var confirmation: String?

    /// Mirrors of the queue, for the card.
    @Published private(set) var waitingToSync = 0
    @Published private(set) var rejectedMessage: String?

    private let apiClient: APIClient
    private let authSession: AuthSession
    private var userId: Int { authSession.user?.id ?? 0 }
    private let queue = TripReportQueue.shared
    private let haptic = UINotificationFeedbackGenerator()
    private var isSyncing = false
    private var sinks = Set<AnyCancellable>()

    init(authSession: AuthSession) {
        self.authSession = authSession
        apiClient = APIClient(authSession: authSession)

        queue.$items
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in self?.mirrorQueue() }
            .store(in: &sinks)

        // Back in range → file whatever is waiting.
        NotificationCenter.default.publisher(for: .mwPingQueueOnline)
            .merge(with: NotificationCenter.default.publisher(for: UIApplication.willEnterForegroundNotification))
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in Task { await self?.sync() } }
            .store(in: &sinks)

        mirrorQueue()
    }

    // MARK: - State (queue overrides the server's last-known answer)

    var hasOpenTrip: Bool {
        if queue.impliedOpenTrip(for: userId) != nil { return true }
        if queue.impliesClosed(for: userId) { return false }
        return status?.tripState == "open"
    }

    var openTripVehicle: String? { queue.impliedOpenTrip(for: userId)?.vehicleId ?? status?.openTrip?.vehicleId }
    var openTripOdometerStart: Int? { queue.impliedOpenTrip(for: userId)?.odometerStart ?? status?.openTrip?.odometerStart }

    var vehicles: [TripVehicle] { status?.vehicles ?? Self.cachedVehicles() }
    var checks: [TripCheckItem] { (status?.checks).flatMap { $0.isEmpty ? nil : $0 } ?? Self.fallbackChecks }

    private func mirrorQueue() {
        waitingToSync   = queue.waitingCount(for: userId)
        rejectedMessage = queue.rejected(for: userId).first?.rejection
    }

    // MARK: - Load

    func refresh() async {
        await sync()
        do {
            let s: TripReportStatus = try await apiClient.request(.tripReportStatus)
            status = s
            if let v = s.vehicles, !v.isEmpty { Self.cacheVehicles(v) }
        } catch {
            // Offline: the queue and the last-known status carry the screen.
        }
    }

    /// Call right after a successful clock-in.
    func askIfNeeded() async {
        await refresh()
        guard !hasOpenTrip, !answeredToday, status?.declared == nil else { return }
        showDeclaration = true
    }

    /// The server is the record, but it can't be asked offline — don't re-ask on every launch.
    private static var todayKey: String {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_US_POSIX")
        return "mw.driverAsked." + f.string(from: Date())
    }
    private var answeredToday: Bool { UserDefaults.standard.bool(forKey: Self.todayKey) }
    private func markAnswered() { UserDefaults.standard.set(true, forKey: Self.todayKey) }

    // MARK: - Filing (server first, queue as backup)

    private enum FileResult {
        case filed(TripReportStatus)   // the server has it
        case queued                    // no signal — saved on this phone, will file later
        case rejected(String)          // the server said no — fix it on the form
    }

    private func file(action: String, body: [String: Any], vehicleId: String? = nil,
                      odometerStart: Int? = nil, mayDrive: Bool? = nil) async -> FileResult {
        isWorking    = true
        errorMessage = nil
        defer { isWorking = false }

        // Anything already waiting goes first, so entries reach the server in the order they
        // happened. If it still can't be sent, this one joins the back of the queue.
        await sync()
        if queue.waitingCount(for: userId) > 0 {
            queue.enqueue(userId: userId, action: action, body: body, vehicleId: vehicleId,
                          odometerStart: odometerStart, mayDrive: mayDrive)
            return .queued
        }

        var live = body
        live["performed_at"] = Int64(Date().timeIntervalSince1970 * 1000)
        do {
            let result: TripReportStatus = try await apiClient.request(.tripReportAction, body: live)
            guard result.success else { return .rejected(result.message ?? "The server did not accept this entry.") }
            status = result
            return .filed(result)
        } catch let error as APIError {
            switch error {
            case .serverError(let message):
                return .rejected(message)
            default:
                // No signal, timeout, or signed out mid-request: keep the driver's work.
                queue.enqueue(userId: userId, action: action, body: body, vehicleId: vehicleId,
                              odometerStart: odometerStart, mayDrive: mayDrive)
                return .queued
            }
        } catch {
            queue.enqueue(userId: userId, action: action, body: body, vehicleId: vehicleId,
                          odometerStart: odometerStart, mayDrive: mayDrive)
            return .queued
        }
    }

    private static let savedLocally = "No signal — saved on this phone. It will be filed automatically, under the time you did it, when you're back in range."

    // MARK: - Declaration

    func declareNotDriving() async {
        showDeclaration = false
        markAnswered()
        _ = await file(action: "declare", body: ["action": "declare", "driving": false])
    }

    func declareDriving() {
        showDeclaration = false
        markAnswered()
        showPreTrip = true
    }

    // MARK: - Pre-trip

    func submitPreTrip(vehicleId: String?, odometer: String, checked: Set<String>,
                       criticalDefects: String, unhitched: Bool, otherDefects: String,
                       safeToDrive: Bool) async {
        let critical = criticalDefects.trimmingCharacters(in: .whitespacesAndNewlines)
        let km       = Int(odometer.filter(\.isNumber))

        var body: [String: Any] = [
            "action":             "pre_trip",
            "defects_critical":   critical,
            "defect_unhitch":     unhitched,
            "defects_non_urgent": otherDefects.trimmingCharacters(in: .whitespacesAndNewlines),
            "safe_to_drive":      safeToDrive
        ]
        if let vehicleId { body["vehicle_id"] = vehicleId }
        if let km { body["odometer_start"] = km }
        for item in checks { body[item.field] = checked.contains(item.field) }

        // Same rule the server applies: a recorded critical defect overrides a ticked box.
        let mayDrive = safeToDrive && critical.isEmpty

        let result = await file(action: "pre_trip", body: body, vehicleId: vehicleId, odometerStart: km, mayDrive: mayDrive)

        let filed: Bool
        switch result {
        case .rejected(let message):
            errorMessage = message          // stay on the form so it can be fixed
            haptic.notificationOccurred(.error)
            return
        case .filed:  filed = true
        case .queued: filed = false
        }

        markAnswered()
        showPreTrip = false

        if mayDrive {
            haptic.notificationOccurred(.success)
            groundedMessage = nil
            confirmation    = filed ? "Pre-trip inspection filed." : Self.savedLocally
        } else {
            haptic.notificationOccurred(.error)
            confirmation    = nil
            groundedMessage = filed
                ? "Do not drive this vehicle. Your inspection is filed and the office has been notified."
                : "Do not drive this vehicle. No signal — your inspection is saved on this phone, but the office has NOT been told yet. Call them."
        }
    }

    // MARK: - Post-trip

    /// Returns a problem to confirm (implausible odometer), or nil when the trip was closed.
    func submitPostTrip(odometer: String, remarks: String, hosDriving: String, hosOther: String,
                        hosOff: String, confirmOdometer: Bool) async -> String? {
        let km = Int(odometer.filter(\.isNumber))

        // Checked here too, because offline there is no server to ask "are you sure?".
        if !confirmOdometer, let start = openTripOdometerStart, let end = km {
            if end < start { return "The end odometer (\(end)) is lower than the start (\(start)). Check it, or confirm it's right." }
            if end - start > 1500 { return "That's more than 1,500 km in one trip. Check it, or confirm it's right." }
        }

        var body: [String: Any] = [
            "action":              "post_trip",
            "end_of_day_remarks":  remarks,
            "hos_on_duty_driving": hosDriving,
            "hos_on_duty_other":   hosOther,
            "hos_off_duty":        hosOff,
            "confirm_odometer":    confirmOdometer
        ]
        if let km { body["odometer_end"] = km }

        switch await file(action: "post_trip", body: body) {
        case .rejected(let message):
            haptic.notificationOccurred(.error)
            return message                   // shown on the form; "This reading is correct" re-submits
        case .filed:
            confirmation = "Trip closed and filed."
        case .queued:
            confirmation = Self.savedLocally
        }
        haptic.notificationOccurred(.success)
        groundedMessage = nil
        showPostTrip    = false
        return nil
    }

    func discardRejected() {
        queue.discardRejected(for: userId)
    }

    // MARK: - Sync

    /// Files queued entries oldest-first. Stops at the first network failure (try again
    /// later); a definite refusal from the server is kept and shown, never dropped silently.
    func sync() async {
        guard !isSyncing, userId > 0 else { return }
        isSyncing = true
        isWorking = queue.waitingCount(for: userId) > 0
        defer { isSyncing = false; isWorking = false }

        for item in queue.pending(for: userId) {
            do {
                let result: TripReportStatus = try await apiClient.request(.tripReportAction, body: queue.requestBody(for: item))
                if result.success {
                    queue.remove(item.id)
                    status = result
                } else {
                    queue.markRejected(item.id, message: result.message ?? "The server did not accept this entry.")
                    break
                }
            } catch let error as APIError {
                if case .serverError(let message) = error {
                    queue.markRejected(item.id, message: message)
                }
                break    // network / auth: leave it queued, in order
            } catch {
                break
            }
        }
    }

    // MARK: - Offline fallbacks

    /// The legal checklist must be available with no signal on a fresh install too.
    private static let fallbackChecks: [TripCheckItem] = [
        ("chk_leaks", "Check beneath truck for leaks"), ("chk_hitch", "Hitch secure & light plug attached"),
        ("chk_rear_gate", "Rear truck gate secure"), ("chk_loads_secure", "Loads secure"),
        ("chk_trailer_lights", "Trailer lights"), ("chk_truck_brakes", "Truck brakes"),
        ("chk_truck_lights", "Truck lights"), ("chk_mirrors", "Mirrors"),
        ("chk_tire_pressure", "Tire pressure"), ("chk_washer_wipers", "Washer fluids & wipers")
    ].map { TripCheckItem(field: $0.0, label: $0.1) }

    private static let vehiclesKey = "mw.tripVehicles"
    private static func cacheVehicles(_ v: [TripVehicle]) {
        UserDefaults.standard.set(v.map { "\($0.id)|\($0.label)" }, forKey: vehiclesKey)
    }
    private static func cachedVehicles() -> [TripVehicle] {
        (UserDefaults.standard.stringArray(forKey: vehiclesKey) ?? []).compactMap { entry in
            let parts = entry.split(separator: "|", maxSplits: 1).map(String.init)
            guard let id = parts.first, !id.isEmpty else { return nil }
            return TripVehicle(id: id, label: parts.count > 1 ? parts[1] : id)
        }
    }
}
