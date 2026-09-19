//
//  NotificationRouter.swift
//  MowologyCRM
//
//  Receives notification taps (remote APNs and local) and turns them into a
//  route the UI can follow. Without a UNUserNotificationCenterDelegate the app
//  registered for push but did nothing with it: banners never showed while the
//  app was open, and tapping "Job Assigned" just opened whatever screen was last up.
//
//  Server payload (ApnsService): { aps: {...}, data: { screen, visit_id?, stop_id?, date? } }
//  Local payload (GPSTrackingService auto-start): { type, visit_id } at the top level.
//

import Foundation
import UserNotifications

/// Where a tapped notification wants the app to go.
struct NotificationRoute: Equatable {
    let visitId: Int?
    let stopId:  Int?
    /// yyyy-MM-dd of the stop, when the sender knows it isn't today.
    let date:    String?
}

extension Notification.Name {
    /// Posted when a schedule-affecting push arrives while the app is in the
    /// foreground — lets the schedule refresh instead of waiting for its poll tick.
    static let mwSchedulePushReceived = Notification.Name("ca.mowology.schedulePushReceived")
}

@MainActor
final class NotificationRouter: NSObject, ObservableObject {

    static let shared = NotificationRouter()

    /// Set on tap; the UI consumes it and resets it to nil.
    @Published var pendingRoute: NotificationRoute?

    private override init() { super.init() }

    /// Must run before launch finishes so a cold-start tap is delivered.
    func install() {
        UNUserNotificationCenter.current().delegate = self
    }

    // MARK: - Parsing

    nonisolated static func route(from userInfo: [AnyHashable: Any]) -> NotificationRoute? {
        // Remote pushes nest custom keys under "data"; local ones are flat.
        let data = (userInfo["data"] as? [AnyHashable: Any]) ?? userInfo

        let visitId = intValue(data["visit_id"])
        let stopId  = intValue(data["stop_id"])
        let isSchedule = (data["screen"] as? String) == "schedule"
            || (data["type"] as? String) == "visit_auto_started"

        guard isSchedule || visitId != nil || stopId != nil else { return nil }
        return NotificationRoute(visitId: visitId, stopId: stopId, date: data["date"] as? String)
    }

    /// JSON numbers arrive as NSNumber, but a PHP sender can just as easily emit "123".
    private nonisolated static func intValue(_ any: Any?) -> Int? {
        if let n = any as? Int { return n }
        if let n = any as? NSNumber { return n.intValue }
        if let s = any as? String { return Int(s) }
        return nil
    }
}

// MARK: - UNUserNotificationCenterDelegate

extension NotificationRouter: UNUserNotificationCenterDelegate {

    /// Foreground delivery — show it. iOS suppresses banners for the frontmost app by default.
    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification
    ) async -> UNNotificationPresentationOptions {
        if Self.route(from: notification.request.content.userInfo) != nil {
            await MainActor.run {
                NotificationCenter.default.post(name: .mwSchedulePushReceived, object: nil)
            }
        }
        return [.banner, .list, .sound]
    }

    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse
    ) async {
        let route = Self.route(from: response.notification.request.content.userInfo)
        await MainActor.run {
            if let route { self.pendingRoute = route }
        }
    }
}
