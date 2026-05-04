//
//  MowologyCRMApp.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI
import BackgroundTasks

// MARK: - Background task identifier

private let kBGPingTaskId = "ca.mowology.crm-field.ping"

@main
struct MowologyCRMApp: App {

    @StateObject private var authSession = AuthSession()

    // MARK: - Init

    init() {
        registerBackgroundTasks()
    }

    // MARK: - Scene

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(authSession)
                .onReceive(
                    NotificationCenter.default.publisher(
                        for: UIApplication.willResignActiveNotification
                    )
                ) { _ in
                    // App is moving to background — schedule the next GPS ping.
                    schedulePingRefresh()
                }
        }
    }

    // MARK: - Background Task Registration

    /// Register the GPS ping task identifier.
    ///
    /// Must be called before the app finishes launching (called from init).
    /// The identifier must also be listed under BGTaskSchedulerPermittedIdentifiers
    /// in Info.plist (added to project.yml).
    ///
    /// How it works:
    ///   1. On foreground → background, schedulePingRefresh() asks iOS to
    ///      wake the app within ~15 minutes.
    ///   2. iOS calls the handler below; we send one GPS ping via GPSTrackingService.
    ///   3. The handler reschedules itself so pings continue while the shift is active.
    private func registerBackgroundTasks() {
        BGTaskScheduler.shared.register(
            forTaskWithIdentifier: kBGPingTaskId,
            using: nil
        ) { task in
            guard let refreshTask = task as? BGAppRefreshTask else {
                task.setTaskCompleted(success: false)
                return
            }
            handlePingTask(refreshTask)
        }
    }

    /// Execute a single GPS ping and reschedule.
    private func handlePingTask(_ task: BGAppRefreshTask) {
        // Reschedule immediately so the chain continues even if this run is cut short.
        schedulePingRefresh()

        // Only ping while a shift is active.
        guard UserDefaults.standard.bool(forKey: GPSTrackingService.kShiftActive) else {
            task.setTaskCompleted(success: true)
            return
        }

        let pingTask = Task { @MainActor in
            await GPSTrackingService.shared.sendPing()
            task.setTaskCompleted(success: true)
        }

        task.expirationHandler = {
            pingTask.cancel()
            task.setTaskCompleted(success: false)
        }
    }

    /// Ask iOS to wake the app for a GPS ping within the system-throttled window.
    /// iOS honours this request when conditions allow (typically ≥ 15 min intervals).
    private func schedulePingRefresh() {
        guard UserDefaults.standard.bool(forKey: GPSTrackingService.kShiftActive) else {
            return   // don't schedule when no shift is active
        }

        let request = BGAppRefreshTaskRequest(identifier: kBGPingTaskId)
        request.earliestBeginDate = Date(timeIntervalSinceNow: 60 * 5)  // 5 min minimum

        try? BGTaskScheduler.shared.submit(request)
    }
}
