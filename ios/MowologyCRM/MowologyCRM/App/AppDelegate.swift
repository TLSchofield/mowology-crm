//
//  AppDelegate.swift
//  MowologyCRM
//
//  Handles three system-level responsibilities:
//    1. BackgroundTasks — register + handle BGAppRefreshTask (schedule) and
//       BGProcessingTask (ping drain) so the app stays fresh when backgrounded.
//    2. Push Notifications — receive APNs device token and forward it to the
//       backend; handle background content-available pushes.
//    3. UNUserNotificationCenter delegate — deliver foreground notifications
//       as banners; route "Clock In" action taps to TimeClockViewModel.
//

import UIKit
import BackgroundTasks
import UserNotifications
import WidgetKit

final class AppDelegate: NSObject, UIApplicationDelegate {

    // MARK: - Constants

    static let bgScheduleRefreshId = "ca.mowology.crm.schedule-refresh"
    static let bgPingDrainId       = "ca.mowology.crm.ping-drain"
    static let appGroupId          = "group.ca.mowology.crm"

    // MARK: - App launch

    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        // BGTask identifiers MUST be registered before the app finishes launching.
        BGTaskScheduler.shared.register(
            forTaskWithIdentifier: AppDelegate.bgScheduleRefreshId, using: nil
        ) { [weak self] task in
            self?.handleScheduleRefresh(task: task as! BGAppRefreshTask)
        }
        BGTaskScheduler.shared.register(
            forTaskWithIdentifier: AppDelegate.bgPingDrainId, using: nil
        ) { [weak self] task in
            self?.handlePingDrain(task: task as! BGProcessingTask)
        }

        UNUserNotificationCenter.current().delegate = self
        setupNotificationCategories()
        return true
    }

    func applicationDidEnterBackground(_ application: UIApplication) {
        scheduleBackgroundRefresh()
    }

    // MARK: - BGTask scheduling

    private func scheduleBackgroundRefresh() {
        let request = BGAppRefreshTaskRequest(identifier: AppDelegate.bgScheduleRefreshId)
        request.earliestBeginDate = Date(timeIntervalSinceNow: 15 * 60)
        try? BGTaskScheduler.shared.submit(request)
    }

    func schedulePingDrain() {
        let request = BGProcessingTaskRequest(identifier: AppDelegate.bgPingDrainId)
        request.requiresNetworkConnectivity = true
        request.requiresExternalPower = false
        try? BGTaskScheduler.shared.submit(request)
    }

    // MARK: - BGTask: schedule refresh (30 s budget)

    private func handleScheduleRefresh(task: BGAppRefreshTask) {
        scheduleBackgroundRefresh()

        let refreshTask = Task { @MainActor in
            let session = AuthSession()
            guard session.isAuthenticated else {
                task.setTaskCompleted(success: false)
                return
            }
            let client = APIClient(authSession: session)
            let today  = isoToday()

            struct DayResp: Decodable {
                let success: Bool
                let stopCount: Int?
                let stops: [BgStop]?
                enum CodingKeys: String, CodingKey {
                    case success; case stopCount = "stop_count"; case stops
                }
            }
            struct BgStop: Decodable {
                let propertyAddress: String?
                let startTime: String?
                enum CodingKeys: String, CodingKey {
                    case propertyAddress = "property_address"
                    case startTime = "start_time"
                }
            }

            if let response: DayResp = try? await client.request(.scheduleDay(date: today)) {
                let defaults = UserDefaults(suiteName: AppDelegate.appGroupId) ?? .standard
                defaults.set(response.stopCount ?? 0, forKey: "mw.widget.stopCount")
                if let first = response.stops?.first {
                    defaults.set(first.propertyAddress ?? "", forKey: "mw.widget.nextAddress")
                    defaults.set(first.startTime ?? "",       forKey: "mw.widget.nextTime")
                }
                WidgetCenter.shared.reloadAllTimelines()
            }
            task.setTaskCompleted(success: true)
        }

        task.expirationHandler = { refreshTask.cancel() }
    }

    // MARK: - BGTask: ping drain (runs when network is available)

    private func handlePingDrain(task: BGProcessingTask) {
        let drainTask = Task { @MainActor in
            let session = AuthSession()
            guard session.isAuthenticated else {
                task.setTaskCompleted(success: false)
                return
            }
            let client = APIClient(authSession: session)
            await PingQueue.shared.drain(using: client)
            task.setTaskCompleted(success: true)
        }

        task.expirationHandler = { drainTask.cancel() }
    }

    // MARK: - Push Notifications

    func application(
        _ application: UIApplication,
        didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
    ) {
        let tokenString = deviceToken.map { String(format: "%02x", $0) }.joined()
        Task { @MainActor in
            await DeviceTokenService.shared.register(token: tokenString)
        }
    }

    func application(
        _ application: UIApplication,
        didFailToRegisterForRemoteNotificationsWithError error: Error
    ) {
        print("[AppDelegate] Push registration failed: \(error.localizedDescription)")
    }

    // content-available:1 silent push — drain queued pings immediately
    func application(
        _ application: UIApplication,
        didReceiveRemoteNotification userInfo: [AnyHashable: Any],
        fetchCompletionHandler completionHandler: @escaping (UIBackgroundFetchResult) -> Void
    ) {
        Task { @MainActor in
            let session = AuthSession()
            guard session.isAuthenticated else { completionHandler(.noData); return }
            let client = APIClient(authSession: session)
            await PingQueue.shared.drain(using: client)
            completionHandler(.newData)
        }
    }

    // MARK: - Notification categories

    private func setupNotificationCategories() {
        let clockInAction = UNNotificationAction(
            identifier: ArrivalMonitor.actionClockIn,
            title: "Clock In",
            options: .foreground
        )
        let arrivalCategory = UNNotificationCategory(
            identifier: ArrivalMonitor.categoryArrival,
            actions: [clockInAction],
            intentIdentifiers: []
        )
        UNUserNotificationCenter.current().setNotificationCategories([arrivalCategory])
    }

    // MARK: - Helpers

    private func isoToday() -> String {
        let fmt = DateFormatter()
        fmt.locale     = Locale(identifier: "en_US_POSIX")
        fmt.dateFormat = "yyyy-MM-dd"
        return fmt.string(from: Date())
    }
}

// MARK: - UNUserNotificationCenterDelegate

extension AppDelegate: UNUserNotificationCenterDelegate {

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        completionHandler([.banner, .sound])
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        if response.actionIdentifier == ArrivalMonitor.actionClockIn,
           let stopId = response.notification.request.content.userInfo["stop_id"] as? Int {
            NotificationCenter.default.post(
                name: .mwArrivalClockIn,
                object: nil,
                userInfo: ["stop_id": stopId]
            )
        }
        completionHandler()
    }
}

// MARK: - Notification.Name

extension Notification.Name {
    /// Posted when the user taps "Clock In" on an arrival notification.
    /// userInfo key "stop_id": Int
    static let mwArrivalClockIn = Notification.Name("ca.mowology.arrivalClockIn")
}
