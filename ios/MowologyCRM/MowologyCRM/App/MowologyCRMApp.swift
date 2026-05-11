//
//  MowologyCRMApp.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI
import UserNotifications
import BackgroundTasks

// MARK: - Background task identifier

private let kBGPingTaskId = "ca.mowology.crm-field.ping"

@main
struct MowologyCRMApp: App {

    @UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate
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
                .task {
                    await requestNotificationPermission()
                    // Start NWPathMonitor so the receipt upload queue drains
                    // automatically when connectivity is restored. Without this
                    // call the monitor is never started and the queue only drains
                    // on the next manual upload attempt.
                    let client = APIClient(authSession: authSession)
                    ReceiptQueue.shared.startMonitoring { data, lat, lng, jobId in
                        try await client.uploadReceipt(
                            imageData: data,
                            lat:       lat,
                            lng:       lng,
                            jobId:     jobId
                        )
                    }
                }
                .onReceive(
                    NotificationCenter.default.publisher(
                        for: UIApplication.willResignActiveNotification
                    )
                ) { _ in
                    schedulePingRefresh()
                }
        }
    }

    private func requestNotificationPermission() async {
        let center = UNUserNotificationCenter.current()
        let granted = (try? await center.requestAuthorization(options: [.alert, .sound, .badge])) ?? false
        if granted {
            await MainActor.run {
                UIApplication.shared.registerForRemoteNotifications()
            }
        }
    }

    // MARK: - Background Task Registration

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

    private func handlePingTask(_ task: BGAppRefreshTask) {
        schedulePingRefresh()

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

    private func schedulePingRefresh() {
        guard UserDefaults.standard.bool(forKey: GPSTrackingService.kShiftActive) else {
            return
        }

        let request = BGAppRefreshTaskRequest(identifier: kBGPingTaskId)
        request.earliestBeginDate = Date(timeIntervalSinceNow: 60 * 5)

        try? BGTaskScheduler.shared.submit(request)
    }
}
