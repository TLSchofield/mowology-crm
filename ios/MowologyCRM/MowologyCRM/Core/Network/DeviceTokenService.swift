//
//  DeviceTokenService.swift
//  MowologyCRM
//
//  Registers the device's APNs push token with the backend. Called from
//  AppDelegate.didRegisterForRemoteNotificationsWithDeviceToken and re-called
//  on every foreground transition (see MowologyCRMApp) — registration is
//  idempotent server-side (upsert on user_id+device_token), so a redundant
//  call on an already-registered token is harmless and doubles as retry
//  coverage for the case where the initial registration attempt had no
//  connectivity when the token first arrived.
//

import Foundation

@MainActor
final class DeviceTokenService {

    static let shared = DeviceTokenService()

    private init() {}

    func register(token: String) async {
        let session = AuthSession()
        guard session.isAuthenticated else { return }

        let client = APIClient(authSession: session)
        do {
            let _: DeviceTokenResponse = try await client.request(
                .deviceTokenRegister,
                body: ["device_token": token, "platform": "ios"]
            )
        } catch {
            print("[DeviceTokenService] register failed: \(error)")
        }
    }
}
