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
//  Department-head pushes (QuoteViewNotifier — "Sam: Linda just opened QUO-…"):
//  { aps: {...}, data: { open: "team", head: "sam", quote_id? } } → the Team tab, that head's card.
//  Penny's unclaimed e-Transfer push adds data.url (etransfer.interac.ca only) → opened in Safari.
//

import Foundation
import UIKit
import UserNotifications

/// Where a tapped notification wants the app to go.
struct NotificationRoute: Equatable {
    let visitId: Int?
    let stopId:  Int?
    /// yyyy-MM-dd of the stop, when the sender knows it isn't today.
    let date:    String?
}

/// A push that wants a department head's card on the Team tab.
struct HeadRoute: Equatable {
    /// Head slug as on TeamHead.all ("sam", "penny", …).
    let head: String
    let quoteId: Int?
}

extension Notification.Name {
    /// Posted when a department-head push arrives while the app is in the foreground —
    /// the Team tab reloads that head's card. `object` is the head slug.
    static let mwHeadPushReceived = Notification.Name("ca.mowology.headPushReceived")

    /// Posted when a schedule-affecting push arrives while the app is in the
    /// foreground — lets the schedule refresh instead of waiting for its poll tick.
    static let mwSchedulePushReceived = Notification.Name("ca.mowology.schedulePushReceived")
}

@MainActor
final class NotificationRouter: NSObject, ObservableObject {

    static let shared = NotificationRouter()

    /// Set on tap; the UI consumes it and resets it to nil.
    @Published var pendingRoute: NotificationRoute?

    /// Set on tapping a department-head push; TeamView consumes it and resets it to nil.
    @Published var pendingHead: HeadRoute?

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

    nonisolated static func headRoute(from userInfo: [AnyHashable: Any]) -> HeadRoute? {
        let data = (userInfo["data"] as? [AnyHashable: Any]) ?? userInfo
        guard (data["open"] as? String) == "team",
              let head = data["head"] as? String,
              TeamHead.all.contains(where: { $0.slug == head }) else { return nil }
        return HeadRoute(head: head, quoteId: intValue(data["quote_id"]))
    }

    /// Penny's "deposit this e-Transfer" push carries the Interac deposit link. Only an
    /// https link on etransfer.interac.ca is ever opened — anything else is ignored.
    nonisolated static func depositURL(from userInfo: [AnyHashable: Any]) -> URL? {
        let data = (userInfo["data"] as? [AnyHashable: Any]) ?? userInfo
        guard let raw = data["url"] as? String,
              let url = URL(string: raw),
              url.scheme == "https",
              url.host?.lowercased() == "etransfer.interac.ca" else { return nil }
        return url
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
        let userInfo = notification.request.content.userInfo
        if Self.route(from: userInfo) != nil {
            await MainActor.run {
                NotificationCenter.default.post(name: .mwSchedulePushReceived, object: nil)
            }
        } else if let head = Self.headRoute(from: userInfo) {
            await MainActor.run {
                NotificationCenter.default.post(name: .mwHeadPushReceived, object: head.head)
            }
        }
        return [.banner, .list, .sound]
    }

    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse
    ) async {
        let userInfo = response.notification.request.content.userInfo
        if let url = Self.depositURL(from: userInfo) {
            // Open the Interac deposit page in Safari; Tim chooses his bank there.
            await MainActor.run { UIApplication.shared.open(url) }
            return
        }
        let route = Self.route(from: userInfo)
        let head = route == nil ? Self.headRoute(from: userInfo) : nil
        await MainActor.run {
            if let route { self.pendingRoute = route }
            if let head { self.pendingHead = head }
        }
    }
}
