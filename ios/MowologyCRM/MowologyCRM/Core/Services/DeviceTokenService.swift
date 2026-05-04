//
//  DeviceTokenService.swift
//  MowologyCRM
//
//  Registers the APNs device token with the Mowology backend so the server
//  can dispatch push notifications to this device.
//
//  Deduplicates: if the token hasn't changed since the last successful
//  registration, the network call is skipped.
//

import Foundation

@MainActor
final class DeviceTokenService {

    static let shared = DeviceTokenService()

    private let lastTokenKey = "mw.apns.lastRegisteredToken"

    private init() {}

    func register(token: String) async {
        let stored = UserDefaults.standard.string(forKey: lastTokenKey)
        guard token != stored else { return }

        let session = AuthSession()
        guard session.isAuthenticated else { return }
        let client = APIClient(authSession: session)

        do {
            let _: DeviceTokenResponse = try await client.request(
                .deviceTokenRegister,
                body: ["device_token": token, "platform": "ios"]
            )
            UserDefaults.standard.set(token, forKey: lastTokenKey)
        } catch {
            print("[DeviceTokenService] Registration failed: \(error)")
        }
    }
}

// MARK: - Response

private struct DeviceTokenResponse: Decodable {
    let success: Bool
}
