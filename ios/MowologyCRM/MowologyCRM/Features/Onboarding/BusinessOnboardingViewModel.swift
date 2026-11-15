//
//  BusinessOnboardingViewModel.swift
//  MowologyCRM
//
//  Drives the five onboarding steps against /api/onboarding/map.php and step.php (ported from Android
//  OnboardingViewModel.kt). Declares `knows=terms` so the server includes the Terms step.
//

import Foundation

@MainActor
final class BusinessOnboardingViewModel: ObservableObject {
    struct Reply { let key: String; let action: String; let next: String?; let message: String?; let data: [String: Any]? }

    @Published private(set) var map: BusinessOnboardingLogic.MapState?
    @Published private(set) var loading = false
    @Published private(set) var error: String?
    @Published var stepError: String?
    @Published private(set) var reply: Reply?
    @Published private(set) var quickBooksURL: URL?
    @Published var current: String?

    private let transport: OnboardingTransport
    private var initialised = false

    init(transport: OnboardingTransport) { self.transport = transport }

    private func message(_ r: OnboardingReply) -> String {
        if r.status == 404 { return "Setup is not available on this server yet." }
        if r.status == 0 { return SignUpLogic.network }
        if let m = r.json?["message"] as? String, !m.isEmpty { return m }
        return "Setup could not be loaded."
    }

    func load(preserveReply: Bool = false) async {
        if loading { return }
        loading = true; error = nil
        if !preserveReply { reply = nil }
        let r = await transport.send(method: "GET", path: BusinessOnboardingLogic.mapPath,
                                     query: "knows=\(BusinessOnboardingLogic.knownSteps)", body: nil, authorised: true)
        loading = false
        guard r.ok else { error = message(r); return }
        guard let m = BusinessOnboardingLogic.parse(r.json) else { error = "Setup could not be loaded."; return }
        map = m
        if !initialised { initialised = true; current = BusinessOnboardingLogic.firstScreen(map: m, requested: nil) }
    }

    func act(_ key: String, _ action: String, fields: [String: Any] = [:]) async {
        if loading { return }
        loading = true; stepError = nil
        let r = await transport.send(method: "POST", path: BusinessOnboardingLogic.stepPath, query: nil,
                                     body: BusinessOnboardingLogic.stepBody(key: key, action: action, fields: fields), authorised: true)
        loading = false
        guard r.ok, (r.json?["ok"] as? Bool) == true else { stepError = message(r); return }
        let next = (r.json?["next"] as? String).flatMap { $0.isEmpty || $0 == "null" ? nil : $0 }
        let rep = Reply(key: key, action: action, next: next, message: (r.json?["message"] as? String).flatMap { $0.isEmpty ? nil : $0 },
                        data: r.json?["data"] as? [String: Any])
        reply = rep
        if BusinessOnboardingLogic.holdsReply(key: key, action: action) { current = key } else { current = next; reply = nil }
        await load(preserveReply: true)
    }

    func skip(_ key: String) async {
        guard BusinessOnboardingLogic.skippable(map?.steps.first { $0.key == key } ?? .init(key: key, title: "", state: .todo, head: nil, skippable: false, data: [:])) else { return }
        if loading { return }
        loading = true; stepError = nil
        let r = await transport.send(method: "POST", path: BusinessOnboardingLogic.mapPath, query: nil,
                                     body: BusinessOnboardingLogic.mapBody(key: key, action: "skip"), authorised: true)
        loading = false
        guard r.ok else { stepError = message(r); return }
        if let m = BusinessOnboardingLogic.parse(r.json) { map = m; current = m.next; reply = nil } else { await load() }
    }

    func startQuickBooks() async {
        if loading { return }
        loading = true; stepError = nil; quickBooksURL = nil
        let r = await transport.send(method: "GET", path: BusinessOnboardingLogic.quickBooksPath, query: nil, body: nil, authorised: true)
        loading = false
        if r.ok, let s = r.json?["url"] as? String, let u = URL(string: s), u.scheme == "https" { quickBooksURL = u }
        else { stepError = message(r) }
    }
    func consumeQuickBooksURL() { quickBooksURL = nil }

    /// "Continue" on a held reply (company confirmed, invitation shown).
    func continueFrom(next: String?) { reply = nil; current = next ?? map?.next }
}
