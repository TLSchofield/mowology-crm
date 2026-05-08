//
//  AuthSession.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation

// MARK: - Keychain Keys

private enum KeychainKey {
    /// Plain-text JWT for devices without biometrics. Restored automatically on launch.
    static let jwtPlain = "mowology_jwt"
    /// Biometric-protected JWT. Requires Face ID / Touch ID to read.
    static let jwtBiometric = "mowology_jwt_bio"
    /// User profile JSON. Always non-biometric so we can show "Welcome back, name".
    static let user = "mowology_user"
}

// MARK: - Auth Response

private struct AuthResponse: Decodable {
    let token: String
    let user: User
    let expiresIn: Int

    enum CodingKeys: String, CodingKey {
        case token
        case user
        case expiresIn = "expires_in"
    }
}

// MARK: - AuthSession

/// Holds the current authentication state for the app lifetime.
/// Published properties drive the RootView transition between login and schedule.
@MainActor
final class AuthSession: ObservableObject {

    @Published private(set) var isAuthenticated: Bool = false
    @Published private(set) var user: User?
    @Published private(set) var token: String?

    /// `true` when a biometric-protected session is on disk and the device
    /// has biometrics enrolled. Drives the "Sign in with Face ID" button.
    @Published private(set) var hasBiometricSession: Bool = false

    // MARK: - Init

    init() {
        // Restore persisted session from Keychain on cold launch.
        restoreFromKeychain()
    }

    // MARK: - Login (email + password)

    /// Authenticates against the Mowology API token endpoint.
    /// On success the JWT and user record are persisted to Keychain and the
    /// published state is updated so RootView switches to ScheduleView.
    func login(email: String, password: String) async throws {
        guard let url = URL(string: "https://mowology.ca/api/auth/token.php") else {
            throw APIError.invalidURL
        }

        let body: [String: String] = [
            "email":    email,
            "password": password,
            "audience": "mobile"
        ]

        guard let bodyData = try? JSONSerialization.data(withJSONObject: body) else {
            throw APIError.serverError("Failed to encode request body.")
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = bodyData

        let data: Data
        let response: URLResponse

        do {
            (data, response) = try await URLSession.shared.data(for: request)
        } catch {
            throw APIError.networkError(error)
        }

        if let httpResponse = response as? HTTPURLResponse {
            if httpResponse.statusCode == 401 {
                throw APIError.unauthorized
            }
            if !(200..<300).contains(httpResponse.statusCode) {
                if let serverMessage = extractErrorMessage(from: data) {
                    throw APIError.serverError(serverMessage)
                }
                throw APIError.serverError("Server returned status \(httpResponse.statusCode).")
            }
        }

        let authResponse: AuthResponse
        do {
            let decoder = JSONDecoder()
            authResponse = try decoder.decode(AuthResponse.self, from: data)
        } catch {
            throw APIError.decodingError(error)
        }

        persistSession(token: authResponse.token, user: authResponse.user)

        // Update published state.
        token              = authResponse.token
        user               = authResponse.user
        isAuthenticated    = true
        hasBiometricSession = BiometricAuth.isAvailable
    }

    // MARK: - Login (biometric)

    /// Prompts the user for biometric authentication, then loads the saved JWT
    /// from the biometric-protected Keychain entry. Throws if no biometric
    /// session exists, biometrics are unavailable, or the user cancels.
    func loginWithBiometric() async throws {
        guard BiometricAuth.isAvailable else {
            throw BiometricAuth.BiometricError.notAvailable
        }

        let reason = "Sign in to Mowology"
        let context = try await BiometricAuth.authenticate(reason: reason)

        guard let jwt = KeychainStore.loadBiometric(key: KeychainKey.jwtBiometric, context: context),
              !jwt.isEmpty else {
            // The bio item was missing or unreadable — clear the stale flag and bail.
            hasBiometricSession = false
            throw BiometricAuth.BiometricError.authenticationFailed
        }

        let savedUser = loadUserFromKeychain()

        token            = jwt
        user             = savedUser
        isAuthenticated  = true
    }

    // MARK: - Logout

    /// Clears the session from memory and removes persisted Keychain entries.
    func logout() {
        KeychainStore.delete(key: KeychainKey.jwtPlain)
        KeychainStore.delete(key: KeychainKey.jwtBiometric)
        KeychainStore.delete(key: KeychainKey.user)
        token              = nil
        user               = nil
        isAuthenticated    = false
        hasBiometricSession = false
    }

    // MARK: - Private

    /// Writes the session to Keychain. The JWT is stored biometric-protected
    /// when the device supports biometrics, otherwise plain.
    private func persistSession(token: String, user: User) {
        if BiometricAuth.isAvailable {
            KeychainStore.saveBiometric(key: KeychainKey.jwtBiometric, value: token)
            KeychainStore.delete(key: KeychainKey.jwtPlain)
        } else {
            KeychainStore.save(key: KeychainKey.jwtPlain, value: token)
            KeychainStore.delete(key: KeychainKey.jwtBiometric)
        }

        if let userData = try? JSONEncoder().encode(user),
           let userJSON = String(data: userData, encoding: .utf8) {
            KeychainStore.save(key: KeychainKey.user, value: userJSON)
        }
    }

    private func restoreFromKeychain() {
        // Path 1: device without biometrics (or legacy install) — auto-restore.
        if let plainToken = KeychainStore.load(key: KeychainKey.jwtPlain),
           !plainToken.isEmpty,
           let storedUser = loadUserFromKeychain() {
            token            = plainToken
            user             = storedUser
            isAuthenticated  = true
            return
        }

        // Path 2: biometric session present. Don't unlock yet — the LoginView
        // shows a Face ID button so the user explicitly authenticates.
        if BiometricAuth.isAvailable,
           let storedUser = loadUserFromKeychain() {
            user                = storedUser
            hasBiometricSession = true
            return
        }

        // Path 3: nothing saved — fresh login required.
        isAuthenticated = false
    }

    private func loadUserFromKeychain() -> User? {
        guard let userJSON = KeychainStore.load(key: KeychainKey.user),
              let userData = userJSON.data(using: .utf8),
              let storedUser = try? JSONDecoder().decode(User.self, from: userData)
        else { return nil }
        return storedUser
    }

    private func extractErrorMessage(from data: Data) -> String? {
        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let message = json["message"] as? String ?? json["error"] as? String
        else { return nil }
        return message
    }
}
