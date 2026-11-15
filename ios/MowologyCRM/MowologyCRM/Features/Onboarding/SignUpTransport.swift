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

/// Which business a brand-new person's sign-up goes to. The sign-up email is the owner email the business was
/// provisioned with, but there is NO email -> business lookup for someone who has no password yet
/// (ittai.ca/api/sign-in.php needs one). Until the owner decides how to find it (see SIGNUP_BUILD_NOTES.md), the
/// phone uses the one server it already knows, `Server.base`. Replace this one type when the lookup exists.
protocol SignUpHostResolving {
    func baseURL(forEmail email: String) async -> URL
}
struct SameServerSignUpHost: SignUpHostResolving {
    func baseURL(forEmail email: String) async -> URL { Server.base }
}

/// The address the new person signed up at; onboarding calls go there. In memory only: the rest of the app still
/// talks to `Server.base` (the one-app-per-platform step 7 has not landed).
enum SignUpHostStore {
    static var base: URL?
}

@MainActor
final class LiveOnboardingTransport: OnboardingTransport {
    private let authSession: AuthSession
    private let resolver: SignUpHostResolving
    private let session: URLSession
    private var base: URL

    init(authSession: AuthSession, resolver: SignUpHostResolving = SameServerSignUpHost()) {
        self.authSession = authSession
        self.resolver = resolver
        self.base = SignUpHostStore.base ?? Server.base
        let c = URLSessionConfiguration.default
        c.timeoutIntervalForRequest = 30
        self.session = URLSession(configuration: c)
    }

    /// Sign-up only: pick the host from the email before the first call.
    func choose(forEmail email: String) async { base = await resolver.baseURL(forEmail: email) }
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
