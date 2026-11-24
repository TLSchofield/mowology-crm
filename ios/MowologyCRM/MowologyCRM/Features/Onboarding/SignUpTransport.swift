//
//  SignUpTransport.swift
//  MowologyCRM
//
//  The network seam for sign-up and onboarding. One protocol, a live implementation, and (DEBUG) a stub so every
//  screen can be shown with no server. The Bearer token is AuthSession's own; a 401 signs out exactly as APIClient
//  does. APIClient itself is not used here because it replaces a step's {error:"validation", message} with the
//  word "validation"; these screens must show the server's `message` (Android does).
//

import Foundation

struct OnboardingReply {
    var status: Int                 // 0 = no answer (transport failure)
    var json: [String: Any]?
    var ok: Bool { (200..<300).contains(status) }
}

@MainActor
protocol OnboardingTransport: AnyObject {
    /// `authorised` adds the Bearer token. `query` is appended as-is (e.g. "knows=terms").
    func send(method: String, path: String, query: String?, body: [String: Any]?, authorised: Bool) async -> OnboardingReply
}

enum SignUpHostError: Error, Equatable {
    case notAvailable
    case rateLimited
    case network
}

/// Which business a brand-new person's sign-up goes to. The directory only returns unclaimed businesses and gives
/// the same not-available answer when there is no unique match.
protocol SignUpHostResolving {
    func baseURL(forEmail email: String) async -> Result<URL, SignUpHostError>
}
struct SameServerSignUpHost: SignUpHostResolving {
    func baseURL(forEmail email: String) async -> Result<URL, SignUpHostError> { .success(Server.base) }
}

struct DirectorySignUpHost: SignUpHostResolving {
    typealias Fetcher = (URLRequest) async throws -> (Data, Int)

    private let fetch: Fetcher

    init(fetch: @escaping Fetcher = DirectorySignUpHost.liveFetch) {
        self.fetch = fetch
    }

    func baseURL(forEmail email: String) async -> Result<URL, SignUpHostError> {
        guard let lookupURL = URL(string: "https://ittai.ca/api/signup-lookup.php"),
              let body = try? JSONSerialization.data(withJSONObject: ["email": email]) else {
            return .failure(.network)
        }
        var request = URLRequest(url: lookupURL, timeoutInterval: 15)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.httpBody = body

        do {
            let (data, status) = try await fetch(request)
            switch status {
            case 200:
                guard let object = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                      let business = object["business"] as? [String: Any],
                      let appURL = business["app_url"] as? String,
                      let base = Self.validatedBaseURL(appURL) else {
                    AppDiagnostics.shared.record(category: "signup.lookup", message: "HTTP 200 response did not contain a valid business app_url")
                    return .failure(.network)
                }
                await MainActor.run { SignUpHostStore.base = base }
                return .success(base)
            case 404:
                return .failure(.notAvailable)
            case 429:
                return .failure(.rateLimited)
            default:
                AppDiagnostics.shared.record(category: "signup.lookup", message: "Unexpected HTTP status \(status)")
                return .failure(.network)
            }
        } catch {
            AppDiagnostics.shared.record(category: "signup.lookup", message: ErrorDetails.describe(error))
            return .failure(.network)
        }
    }

    static func validatedBaseURL(_ value: String) -> URL? {
        guard !value.isEmpty, let components = URLComponents(string: value),
              components.scheme == "https", let host = components.host?.lowercased(),
              host == "ittai.ca" || (host.hasSuffix(".ittai.ca") && host.count > ".ittai.ca".count),
              components.port == nil, components.user == nil, components.password == nil,
              components.path.isEmpty, components.query == nil, components.fragment == nil else { return nil }
        return components.url
    }

    static func liveFetch(_ request: URLRequest) async throws -> (Data, Int) {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.timeoutIntervalForRequest = 15
        configuration.timeoutIntervalForResource = 15
        configuration.httpCookieStorage = nil
        configuration.httpShouldSetCookies = false
        configuration.urlCredentialStorage = nil
        let (data, response) = try await URLSession(configuration: configuration).data(for: request)
        return (data, (response as? HTTPURLResponse)?.statusCode ?? 0)
    }
}

/// The address the new person signed up at; onboarding calls go there. In memory only: the rest of the app still
/// talks to `Server.base` (the one-app-per-platform step 7 has not landed).
@MainActor
enum SignUpHostStore {
    static var base: URL?
}

@MainActor
final class LiveOnboardingTransport: OnboardingTransport {
    private let authSession: AuthSession
    private let resolver: SignUpHostResolving
    private let session: URLSession
    private var base: URL

    init(authSession: AuthSession, resolver: SignUpHostResolving? = nil) {
        self.authSession = authSession
        self.resolver = resolver ?? SameServerSignUpHost()
        self.base = SignUpHostStore.base ?? Server.base
        let c = URLSessionConfiguration.default
        c.timeoutIntervalForRequest = 30
        self.session = URLSession(configuration: c)
    }

    /// Sign-up only: pick the host from the email before the first call.
    func choose(forEmail email: String) async -> Result<URL, SignUpHostError> {
        let result = await resolver.baseURL(forEmail: email)
        if case .success(let chosen) = result { base = chosen }
        return result
    }
    var chosenBase: URL { base }

    func send(method: String, path: String, query: String?, body: [String: Any]?, authorised: Bool) async -> OnboardingReply {
        var text = base.absoluteString + path
        if let query, !query.isEmpty { text += "?" + query }
        guard let url = URL(string: text) else { return OnboardingReply(status: 0, json: nil) }
        var req = URLRequest(url: url)
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if authorised, let token = authSession.token { req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let body { req.httpBody = try? JSONSerialization.data(withJSONObject: body) }
        do {
            let (data, response) = try await session.data(for: req)
            let status = (response as? HTTPURLResponse)?.statusCode ?? 0
            let json = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
            if authorised && status == 401 { authSession.logout() }
            return OnboardingReply(status: status, json: json)
        } catch {
            AppDiagnostics.shared.record(category: "onboarding", message: "\(method) \(path): \(ErrorDetails.describe(error))")
            return OnboardingReply(status: 0, json: nil)
        }
    }
}

/// Which onboarding step a signed-in person still owes, kept per user so a relaunch resumes. Existing logins have no
/// entry, so their app opens exactly as before.
enum OnboardingProgress {
    private static func key(_ part: String, _ userId: Int) -> String { "ittai.onboarding.\(part).\(userId)" }
    static func begin(userId: Int, next: String, chiefName: String, businessName: String) {
        let d = UserDefaults.standard
        d.set(next, forKey: key("next", userId)); d.set(chiefName, forKey: key("chief", userId)); d.set(businessName, forKey: key("business", userId))
    }
    static func pending(userId: Int) -> Bool { UserDefaults.standard.string(forKey: key("next", userId)) != nil }
    static func chiefName(userId: Int) -> String { UserDefaults.standard.string(forKey: key("chief", userId)) ?? "" }
    static func businessName(userId: Int) -> String { UserDefaults.standard.string(forKey: key("business", userId)) ?? "" }
    static func finish(userId: Int) { UserDefaults.standard.removeObject(forKey: key("next", userId)) }
}
