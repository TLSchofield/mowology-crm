//
//  DriverTripViewModel.swift
//  MowologyCRM
//
//  Who must log a vehicle inspection is decided per SHIFT, not per person: whoever is
//  driving the company vehicle today. So the app asks at clock-in, lets the answer
//  change mid-shift in either direction, and only holds someone at clock-out if they
//  have an open trip to close.
//

import Foundation
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

    private let apiClient: APIClient
    private let haptic = UINotificationFeedbackGenerator()

    init(authSession: AuthSession) {
        apiClient = APIClient(authSession: authSession)
    }

    var hasOpenTrip: Bool { status?.tripState == "open" }
    var vehicles: [TripVehicle] { status?.vehicles ?? [] }
    var checks: [TripCheckItem] { status?.checks ?? [] }

    // MARK: - Load

    func refresh() async {
        do {
            let s: TripReportStatus = try await apiClient.request(.tripReportStatus)
            status = s
        } catch {
            // Offline: keep whatever we knew. The gate at clock-out re-checks.
        }
    }

    /// Call right after a successful clock-in.
    func askIfNeeded() async {
        await refresh()
        if let status, status.declared == nil, status.tripState != "open", !answeredToday {
            showDeclaration = true
        }
    }

    /// The server is the record (shift_driver_declarations), but until that migration has
    /// run it cannot remember a "not driving" answer — don't re-ask on every app launch.
    private static var todayKey: String {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_US_POSIX")
        return "mw.driverAsked." + f.string(from: Date())
    }
    private var answeredToday: Bool { UserDefaults.standard.bool(forKey: Self.todayKey) }
    private func markAnswered() { UserDefaults.standard.set(true, forKey: Self.todayKey) }

    // MARK: - Declaration

    func declareNotDriving() async {
        showDeclaration = false
        markAnswered()
        _ = await post(["action": "declare", "driving": false])
    }

    func declareDriving() {
        showDeclaration = false
        markAnswered()
        showPreTrip     = true
    }

    // MARK: - Pre-trip

    /// Returns true when the sheet should close.
    func submitPreTrip(vehicleId: String?, odometer: String, checked: Set<String>,
                       criticalDefects: String, unhitched: Bool, otherDefects: String,
                       safeToDrive: Bool) async -> Bool {
        var body: [String: Any] = [
            "action":             "pre_trip",
            "defects_critical":   criticalDefects,
            "defect_unhitch":     unhitched,
            "defects_non_urgent": otherDefects,
            "safe_to_drive":      safeToDrive
        ]
        if let vehicleId { body["vehicle_id"] = vehicleId }
        if let km = Int(odometer.filter(\.isNumber)) { body["odometer_start"] = km }
        for item in checks { body[item.field] = checked.contains(item.field) }

        guard let result = await post(body) else { return false }
        if result.mayDrive == false {
            haptic.notificationOccurred(.error)
            groundedMessage = "Do not drive this vehicle. Your inspection has been recorded and the office has been notified."
        } else {
            haptic.notificationOccurred(.success)
        }
        showPreTrip = false
        return true
    }

    // MARK: - Post-trip

    /// `confirmOdometer` lets the driver override an "are you sure?" on an unusual reading.
    func submitPostTrip(odometer: String, remarks: String, hosDriving: String, hosOther: String,
                        hosOff: String, confirmOdometer: Bool) async -> Bool {
        var body: [String: Any] = [
            "action":              "post_trip",
            "end_of_day_remarks":  remarks,
            "hos_on_duty_driving": hosDriving,
            "hos_on_duty_other":   hosOther,
            "hos_off_duty":        hosOff,
            "confirm_odometer":    confirmOdometer
        ]
        if let km = Int(odometer.filter(\.isNumber)) { body["odometer_end"] = km }

        guard await post(body) != nil else { return false }
        haptic.notificationOccurred(.success)
        showPostTrip = false
        return true
    }

    // MARK: - Private

    private func post(_ body: [String: Any]) async -> TripReportStatus? {
        isWorking    = true
        errorMessage = nil
        defer { isWorking = false }
        do {
            let result: TripReportStatus = try await apiClient.request(.tripReportAction, body: body)
            guard result.success else {
                errorMessage = result.message ?? "Could not save."
                return nil
            }
            status = result
            return result
        } catch let error as APIError {
            if case .networkError = error {
                errorMessage = "No signal. The inspection has NOT been saved — keep this screen open and try again, or complete a paper log."
            } else {
                errorMessage = error.localizedDescription
            }
            return nil
        } catch {
            errorMessage = error.localizedDescription
            return nil
        }
    }
}
