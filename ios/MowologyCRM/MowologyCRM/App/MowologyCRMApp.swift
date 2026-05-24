//
//  MowologyCRMApp.swift
//  MowologyCRM
//

import SwiftUI
import BackgroundTasks
import UserNotifications

@main
struct MowologyCRMApp: App {

    @StateObject private var authSession = AuthSession()
    @Environment(\.scenePhase) private var scenePhase

    private let bgTaskId = "ca.mowology.gps-refresh"

    /// Threshold (s) since last successful ping before the BGTask handler
    /// considers tracking stalled. Raised from 8→15 min after introducing
    /// delegate-driven pings + stationary heartbeat — gaps under 15 min are
    /// now within the normal between-visits cadence (5 min × some retries).
    private static let kNagThresholdSeconds: TimeInterval = 15 * 60

    /// Minimum interval (s) between consecutive "GPS paused" notifications.
    /// Prevents crews from being interrupted every BGTask refresh once a
    /// real stall has occurred. They get one nag per hour, max.
    private static let kNagCooldownSeconds: TimeInterval  = 60 * 60

    /// UserDefaults key for the dedup timestamp.
    private static let kLastNagAt = "mw.lastGpsNagAt"

    // MARK: - Init

    init() {
        registerBackgroundTask()
        requestNotificationPermission()
    }

    // MARK: - Scene

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(authSession)
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .background {
                scheduleGPSRefreshIfNeeded()
            }
        }
    }

    // MARK: - BGTaskScheduler

    private func registerBackgroundTask() {
        BGTaskScheduler.shared.register(
            forTaskWithIdentifier: bgTaskId,
            using: nil
        ) { task in
            guard let refreshTask = task as? BGAppRefreshTask else {
                task.setTaskCompleted(success: false)
                return
            }
            Task { @MainActor in
                await handleGPSRefresh(refreshTask)
            }
        }
    }

    /// Schedule the next GPS-staleness check for ~8 minutes from now.
    /// iOS decides when to actually grant the task — could be 8 min, could
    /// be 30+ min depending on system pressure. This is the "long pole"
    /// safety net; the in-app delegate-driven and heartbeat paths handle
    /// the normal cadence. Only schedules when a clock-in is active.
    private func scheduleGPSRefreshIfNeeded() {
        guard UserDefaults.standard.bool(forKey: GPSTrackingService.kShiftActive) else { return }
        let request = BGAppRefreshTaskRequest(identifier: bgTaskId)
        request.earliestBeginDate = Date(timeIntervalSinceNow: 8 * 60)
        try? BGTaskScheduler.shared.submit(request)
    }

    @MainActor
    private func handleGPSRefresh(_ task: BGAppRefreshTask) async {
        // Re-schedule immediately so the chain continues on next background entry.
        scheduleGPSRefreshIfNeeded()

        let jobActive = UserDefaults.standard.bool(forKey: GPSTrackingService.kShiftActive)
        let lastPing  = UserDefaults.standard.double(forKey: GPSTrackingService.kLastPingAt)
        let elapsed   = lastPing > 0 ? Date().timeIntervalSince1970 - lastPing : 0

        // Stall nag — only fires if BOTH a clock-in is active AND we genuinely
        // missed pings for longer than the 5-min between-visits floor allows
        // for, AND we haven't already nagged the crew in the last hour.
        //
        // The 15-min threshold means: missed three back-to-back 5-min pings,
        // which by then is a real signal something is wrong (auth revoked,
        // airplane mode, deep iOS sleep) — not noise from normal cadence.
        if jobActive && elapsed > Self.kNagThresholdSeconds {
            let lastNag    = UserDefaults.standard.double(forKey: Self.kLastNagAt)
            let nagElapsed = lastNag > 0
                ? Date().timeIntervalSince1970 - lastNag
                : .greatestFiniteMagnitude

            if nagElapsed > Self.kNagCooldownSeconds {
                let content = UNMutableNotificationContent()
                content.title             = "Mowology GPS"
                content.body              = "GPS tracking paused. Open the app to resume."
                content.sound             = nil
                content.interruptionLevel = .timeSensitive

                let trigger = UNTimeIntervalNotificationTrigger(timeInterval: 1, repeats: false)
                let request = UNNotificationRequest(
                    identifier: "mw.gps-nudge",
                    content:    content,
                    trigger:    trigger
                )
                try? await UNUserNotificationCenter.current().add(request)
                UserDefaults.standard.set(Date().timeIntervalSince1970, forKey: Self.kLastNagAt)
            }
        }

        // Attempt a single fresh ping so the server has a recent location
        // even when the foreground ping loop was suspended.
        if jobActive { await GPSTrackingService.shared.sendPing() }

        task.setTaskCompleted(success: true)
    }

    // MARK: - Notification Permission

    private func requestNotificationPermission() {
        UNUserNotificationCenter.current().requestAuthorization(
            options: [.alert, .sound, .badge]
        ) { _, _ in }
    }
}
