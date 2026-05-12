//
//  AuthSession.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation

// MARK: - Keychain Keys

private enum KeychainKey {
    static let jwt  = "mowology_jwt"
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

    // MARK: - Init

    init() {
        // Restore persisted session from Keychain on cold launch.
        restoreFromKeychain()
    }

    // MARK: - Login

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
            throw APIError.serverError(statusCode: nil, message: "Failed to encode request body.")
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
                // Try to extract a server-side error message.
                if let serverMessage = extractErrorMessage(from: data) {
                    throw APIError.serverError(statusCode: httpResponse.statusCode, message: serverMessage)
                }
                throw APIError.serverError(statusCode: httpResponse.statusCode, message: "Server returned status \(httpResponse.statusCode).")
            }
        }

        let authResponse: AuthResponse
        do {
            let decoder = JSONDecoder()
            authResponse = try decoder.decode(AuthResponse.self, from: data)
        } catch {
            throw APIError.decodingError(error)
        }

        // Persist to Keychain.
        KeychainStore.save(key: KeychainKey.jwt, value: authResponse.token)
        if let userData = try? JSONEncoder().encode(authResponse.user),
           let userJSON = String(data: userData, encoding: .utf8) {
            KeychainStore.save(key: KeychainKey.user, value: userJSON)
        }

        // Update published state.
        token           = authResponse.token
        user            = authResponse.user
        isAuthenticated = true
    }

    // MARK: - Logout

    /// Clears the session from memory and removes persisted Keychain entries.
    func logout() {
        KeychainStore.delete(key: KeychainKey.jwt)
        KeychainStore.delete(key: KeychainKey.user)
        token           = nil
        user            = nil
        isAuthenticated = false
    }

    // MARK: - Private

    private func restoreFromKeychain() {
        guard let storedToken = KeychainStore.load(key: KeychainKey.jwt),
              !storedToken.isEmpty,
              let userJSON = KeychainStore.load(key: KeychainKey.user),
              let userData = userJSON.data(using: .utf8),
              let storedUser = try? JSONDecoder().decode(User.self, from: userData)
        else {
            isAuthenticated = false
            return
        }

        token           = storedToken
        user            = storedUser
        isAuthenticated = true
    }

    private func extractErrorMessage(from data: Data) -> String? {
        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let message = json["message"] as? String ?? json["error"] as? String
        else { return nil }
        return message
    }
}
