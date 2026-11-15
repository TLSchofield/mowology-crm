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

struct DirectoryChoiceRequired: Error {
    let businesses: [DirectoryBusiness]
}

typealias AuthFetch = @Sendable (URLRequest) async throws -> (Data, URLResponse)

// MARK: - AuthSession

/// Holds the current authentication state for the app lifetime.
/// Published properties drive the RootView transition between login and schedule.
@MainActor
final class AuthSession: ObservableObject {

    @Published private(set) var isAuthenticated: Bool = false
    @Published private(set) var user: User?
    @Published private(set) var token: String?
    @Published private(set) var businessChoices: [DirectoryBusiness] = []

    private let fetch: AuthFetch

    /// One session for the whole process. iOS can relaunch the app in the background
    /// (significant-change / geofence wake) with no UI, and tracking must be able to
    /// resume with the same credentials the views use.
    static let shared = AuthSession()

    // MARK: - Init

    init(fetch: @escaping AuthFetch = { request in try await URLSession.shared.data(for: request) }) {
        self.fetch = fetch
        // Restore persisted session from Keychain on cold launch.
        restoreFromKeychain()
    }

    // MARK: - Login

    /// Authenticates against the Mowology API token endpoint.
    /// On success the JWT and user record are persisted to Keychain and the
    /// published state is updated so RootView switches to ScheduleView.
    func login(email: String, password: String, business: String? = nil) async throws {
        businessChoices = []
        let body = loginBody(email: email, password: password, business: business)
        if let directoryURL = URL(string: "https://ittai.ca/api/sign-in.php") {
            var directoryRequest = request(url: directoryURL, body: body)
            directoryRequest.timeoutInterval = 15
            directoryRequest.httpShouldHandleCookies = false
            do {
                let (data, response) = try await fetch(directoryRequest)
                let status = (response as? HTTPURLResponse)?.statusCode
                switch DirectorySignInMapper.map(status: status, data: data) {
                case .authenticated(let selected, let token, let userJSON, _):
                    guard let user = try? JSONDecoder().decode(User.self, from: userJSON) else { break }
                    let oldHost = Server.base
                    if TenantDataDecision.shouldClear(storedHost: oldHost, newHost: selected.appURL) {
                        do {
                            try TenantDataReset.clear(forNewHost: selected.appURL, previousHost: oldHost)
                        } catch {
                            throw APIError.serverError(
                                "This phone could not safely store work from the previous business: \(ErrorDetails.describe(error))"
                            )
                        }
                    }
                    guard Server.use(selected.appURL) else { throw APIError.invalidURL }
                    adopt(token: token, user: user)
                    return
                case .choose(let businesses):
                    businessChoices = businesses
                    throw DirectoryChoiceRequired(businesses: businesses)
                case .unauthorized: throw APIError.unauthorized
                case .rateLimited: throw APIError.serverError("Too many tries. Wait a moment, then try again.")
                case .invalidResponse, .fallback: break
                }
            } catch is DirectoryChoiceRequired { throw DirectoryChoiceRequired(businesses: businessChoices) }
            catch let error as APIError { throw error }
            catch { /* Directory transport failure deliberately falls back to the current business. */ }
        }
        try await loginDirect(body: body)
    }

    func dismissBusinessChoices() {
        businessChoices = []
    }

    private func loginDirect(body: [String: String]) async throws {
        guard let url = Server.url("/api/auth/token.php") else {
            throw APIError.invalidURL
        }
        let request = request(url: url, body: body)

        let data: Data
        let response: URLResponse

        do {
            (data, response) = try await fetch(request)
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

        adopt(token: authResponse.token, user: authResponse.user)
    }

    private func loginBody(email: String, password: String, business: String?) -> [String: String] {
        var body = ["email": email, "password": password, "audience": "mobile"]
        if let business, !business.isEmpty { body["business"] = business }
        return body
    }

    private func request(url: URL, body: [String: String]) -> URLRequest {
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try? JSONSerialization.data(withJSONObject: body)
        return request
    }

    /// A token handed back by another auth endpoint (the phone sign-up's signup-confirm.php) goes through the very
    /// same persistence and published state as `login`: one auth path, not two.
    func acceptSignUp(token: String, user: User) {
        adopt(token: token, user: user)
    }

    private func adopt(token newToken: String, user newUser: User) {
        // Persist to Keychain.
        KeychainStore.save(key: KeychainKey.jwt, value: newToken)
        if let userData = try? JSONEncoder().encode(newUser),
           let userJSON = String(data: userData, encoding: .utf8) {
            KeychainStore.save(key: KeychainKey.user, value: userJSON)
        }

        // Update published state.
        token           = newToken
        user            = newUser
        isAuthenticated = true
        FixStore.shared.claim(for: newUser.id)   // someone else's unsent GPS is dropped first
    }

    // MARK: - Logout

    /// Clears the session from memory and removes persisted Keychain entries.
    func logout() {
        // Signing out — or a 401 mid-shift — must end location capture. CoreLocation
        // kept running after logout, recording someone who was no longer signed in.
        GPSTrackingService.shared.stop(reason: .signedOut)
        // This phone stops getting this user's pushes (the server switches its token off).
        if let jwt = token { DeviceTokenService.unregister(jwt: jwt) }
        KeychainStore.delete(key: KeychainKey.jwt)
        KeychainStore.delete(key: KeychainKey.user)
        OfflineDirectory.shared.clear()
        token           = nil
        user            = nil
        isAuthenticated = false
        businessChoices = []
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
        FixStore.shared.claim(for: storedUser.id)
    }

    private func extractErrorMessage(from data: Data) -> String? {
        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let message = json["message"] as? String ?? json["error"] as? String
        else { return nil }
        return message
    }
}

/// Removes data that belongs to the previous server before routing can change. Pending captured
/// payloads are moved intact to a folder no queue reads; cache data is deleted.
@MainActor
enum TenantDataReset {
    static func clear(forNewHost newHost: URL, previousHost: URL) throws {
        GPSTrackingService.shared.stop(reason: .signedOut)
        let files = FileManager.default
        let support = files.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        let stamp = ISO8601DateFormatter().string(from: Date()).replacingOccurrences(of: ":", with: "-")
        let quarantine = support.appendingPathComponent("Quarantined Tenant Data", isDirectory: true)
            .appendingPathComponent("\(previousHost.host ?? "unknown")-\(stamp)", isDirectory: true)
        let queue = support.appendingPathComponent("UploadQueue", isDirectory: true)
        let defaults = UserDefaults.standard
        let exactKeys = [
            "mw.clock.queue.v1", "mw.jobphoto.queue.v1", "mw.recommendation.queue.v1",
            "mw.receipt.queue.v1", "mw.receipt.queue.problem.v1", "mw.receipt.action.queue.v1",
            "mw.fixstore.owner", GPSTrackingService.kShiftActive,
            GPSTrackingService.kActiveVisit, GPSTrackingService.kOnBreak
        ]
        let prefixedKeys = defaults.dictionaryRepresentation().keys.filter {
            $0.hasPrefix("mw.transition.") || $0.hasPrefix("mw.stop.") || $0.hasPrefix("ittai.knowledge.") ||
            $0.hasPrefix("ittai.onboarding.")
        }
        let metadataKeys = Set(exactKeys + prefixedKeys)
        let metadata = defaults.dictionaryRepresentation().filter { metadataKeys.contains($0.key) }
        let sources = [queue, support.appendingPathComponent("mw-trip-report-queue.json"),
                       support.appendingPathComponent("mw-fix-store.json")]
        do {
            try TenantDataQuarantine.commit(sources: sources, metadata: metadata, to: quarantine, files: files)
        } catch {
            AppDiagnostics.shared.record(category: "tenant", message: "Could not quarantine pending uploads: \(ErrorDetails.describe(error))")
            throw error
        }
        metadataKeys.forEach(defaults.removeObject(forKey:))
        AppDiagnostics.shared.record(category: "tenant", message: "Pending work quarantined before changing business.")
        for url in [
            files.urls(for: .cachesDirectory, in: .userDomainMask)[0].appendingPathComponent("mw-schedule"),
            support.appendingPathComponent("mw-trip-report-queue.json"),
            support.appendingPathComponent("mw-fix-store.json")
        ] { try? files.removeItem(at: url) }
        CreateOutbox.shared.clearAfterTenantQuarantine()
        TripReportQueue.shared.clearAfterTenantQuarantine()
        FixStore.shared.removeAll()
        TransitionQueue().clearForTenantSwitch()
        OfflineDirectory.shared.clear()
        VendorItemCache.shared.clear()
        AppDiagnostics.shared.record(category: "tenant", message: "Local business data cleared before changing to \(newHost.host ?? "new business").")
    }
}
