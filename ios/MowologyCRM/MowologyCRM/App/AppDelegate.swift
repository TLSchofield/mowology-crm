//
//  AppDelegate.swift
//  MowologyCRM
//
//  Minimal UIApplicationDelegate — SwiftUI's App protocol has no hook for
//  UIApplication's push-registration callbacks, so this exists solely to
//  receive the APNs device token and forward it to the backend. Wired into
//  MowologyCRMApp via @UIApplicationDelegateAdaptor.
//

import UIKit

final class AppDelegate: NSObject, UIApplicationDelegate {

    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        // The notification delegate has to be in place before launch finishes,
        // or the tap that cold-started the app is never delivered.
        NotificationRouter.shared.install()
        return true
    }

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
}
