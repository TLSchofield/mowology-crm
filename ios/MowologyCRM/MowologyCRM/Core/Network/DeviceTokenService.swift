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

    /// The last APNs token this phone registered — sign-out retires it on the server.
    private static let lastTokenKey = "push.lastDeviceToken"

    func register(token: String) async {
        UserDefaults.standard.set(token, forKey: Self.lastTokenKey)
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

    /// Sign-out: tell the server to stop pushing to this phone (Fable review 2026-10-12 — a phone
    /// kept getting the previous user's pushes). Best effort and fire-and-forget: it is sent with
    /// the JWT that is about to be dropped, so it cannot go through APIClient/AuthSession.
    nonisolated static func unregister(jwt: String) {
        guard let token = UserDefaults.standard.string(forKey: lastTokenKey), !token.isEmpty,
              !jwt.isEmpty,
              let url = URL(string: "https://mowology.ca/api/device/token") else { return }
        var request = URLRequest(url: url, timeoutInterval: 10)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(jwt)", forHTTPHeaderField: "Authorization")
        request.httpBody = try? JSONSerialization.data(withJSONObject: [
            "device_token": token, "platform": "ios", "remove": true,
        ])
        URLSession.shared.dataTask(with: request) { _, _, error in
            if let error { print("[DeviceTokenService] unregister failed: \(error)") }
        }.resume()
    }
}
