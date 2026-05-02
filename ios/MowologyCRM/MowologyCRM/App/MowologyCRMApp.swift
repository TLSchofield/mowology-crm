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

    private let kJobTimerActive = "mw.jobTimerActive"
    private let kLastPingAt     = "mw.lastPingAt"
    private let bgTaskId        = "ca.mowology.gps-refresh"

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

    /// Schedule the next GPS-staleness check for 8 minutes from now.
    /// Only schedules when a job timer is active.
    private func scheduleGPSRefreshIfNeeded() {
        guard UserDefaults.standard.bool(forKey: kJobTimerActive) else { return }
        let request = BGAppRefreshTaskRequest(identifier: bgTaskId)
        request.earliestBeginDate = Date(timeIntervalSinceNow: 8 * 60)
        try? BGTaskScheduler.shared.submit(request)
    }

    @MainActor
    private func handleGPSRefresh(_ task: BGAppRefreshTask) async {
        // Re-schedule immediately so the chain continues on next background entry.
        scheduleGPSRefreshIfNeeded()

        let jobActive = UserDefaults.standard.bool(forKey: kJobTimerActive)
        let lastPing  = UserDefaults.standard.double(forKey: kLastPingAt)
        let elapsed   = lastPing > 0 ? Date().timeIntervalSince1970 - lastPing : 0

        // If a job is active and we haven't pinged in >8 minutes, nudge the crew.
        // interruptionLevel .timeSensitive wakes the app briefly without sounding
        // an alert — crew phone is not disturbed, but the app gets a reconnect chance.
        if jobActive && elapsed > 8 * 60 {
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
        }

        task.setTaskCompleted(success: true)
    }

    // MARK: - Notification Permission

    private func requestNotificationPermission() {
        UNUserNotificationCenter.current().requestAuthorization(
            options: [.alert, .sound, .badge]
        ) { _, _ in }
    }
}
