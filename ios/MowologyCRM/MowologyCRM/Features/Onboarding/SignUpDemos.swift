//
//  SignUpDemos.swift
//  MowologyCRM
//
//  DEBUG only. Launch flags that show each signed-out screen with invented data and a fake transport: no server,
//  no sign-in. `-mwOpeningDemo` (+ `-mwOpeningT <seconds>` to freeze), `-mwWelcomeScreenDemo`, `-mwSignInDemo`,
//  `-mwSignUpDemo [ticked]`, `-mwCodeDemo`, `-mwOnboardDemo 1|2|3|4|5`, `-mwTermsDemo`, `-mwOnboardBarDemo 1|2|3|4|5` (with Kaname's one-tab bar).
//  All the flags end in "Demo", so the app skips its notification permission alert for them (MowologyCRMApp).
//

import SwiftUI

#if DEBUG
/// A fake server: the onboarding map for a chosen step, and "ok" for every step post.
@MainActor
final class DemoOnboardingTransport: OnboardingTransport {
    let current: String
    init(current: String) { self.current = current }

    func send(method: String, path: String, query: String?, body: [String: Any]?, authorised: Bool) async -> OnboardingReply {
        if path.hasSuffix("signup.php") { return OnboardingReply(status: 200, json: ["ok": true, "sent": ["email"], "expires_in": 900]) }
        if path.contains("map.php") && method == "GET" { return OnboardingReply(status: 200, json: map()) }
        if path.contains("step.php") {
            var data: [String: Any] = [:]
            if (body?["do"] as? String) == "search" {
                data = ["matches": [["name": "ACME LANDSCAPING LTD.", "registration_number": "BC1234567", "status": "Active", "record_id": "r1"]], "registry": "ok"]
            }
            return OnboardingReply(status: 200, json: ["ok": true, "next": current, "data": data])
        }
        return OnboardingReply(status: 404, json: nil)
    }

    private func map() -> [String: Any] {
        let order = ["name", "terms", "company", "quickbooks", "books", "accountant", "done"]
        let titles = ["name": "About you", "terms": "Terms", "company": "Company", "quickbooks": "QuickBooks", "books": "Your books", "accountant": "Accountant", "done": "Done"]
        let idx = order.firstIndex(of: current) ?? 0
        let steps: [[String: Any]] = order.enumerated().map { i, key in
            var state = i < idx ? "done" : (i == idx ? "next" : "todo")
            if key == "books" { state = "not_needed" }
            var data: [String: Any] = [:]
            switch key {
            case "name": data = ["first": "Sam", "last": "Lee", "preferred": "", "mobile": "(604) 555-0123", "channel": "text", "quiet": NSNull(), "next_part": "quiet"]
            case "terms": data = ["accepted": false, "version": "2026-11-06", "terms_url": "https://ittai.ca/terms/", "privacy_url": "https://ittai.ca/privacy/"]
            case "company": data = ["entered_name": "Acme Landscaping", "confirmed": false]
            case "quickbooks": data = ["answer": NSNull(), "connected": false]
            case "accountant": data = ["invited_name": NSNull(), "logins": 0]
            case "done": data = ["ready": idx == order.count - 1, "complete": idx == order.count - 1]
            default: break
            }
            if current == "done" {
                state = key == "books" ? "not_needed" : "done"
                if key == "company" { data = ["legal_name": "ACME LANDSCAPING LTD.", "confirmed": true] }
                if key == "accountant" { data["invited_name"] = "Yui Tanaka" }
                if key == "quickbooks" { data = ["answer": "no", "connected": false] }
            }
            return ["key": key, "title": titles[key] ?? key, "state": state, "head": "Penny", "skippable": ["quickbooks", "accountant"].contains(key), "data": data]
        }
        return ["ok": true, "steps": steps, "next": current == "done" ? NSNull() : current, "ready_to_finish": current == "done"]
    }
}

private struct DemoOnboardHost: View {
    let step: String
    @StateObject private var vm: BusinessOnboardingViewModel
    init(step: String) {
        self.step = step
        _vm = StateObject(wrappedValue: BusinessOnboardingViewModel(transport: DemoOnboardingTransport(current: step)))
    }
    var body: some View { BusinessOnboardingView(vm: vm, chiefName: "Kaname", businessName: "Acme Landscaping", onDone: {}) }
}

private struct DemoSignUpHost: View {
    let code: Bool
    let ticked: Bool
    @StateObject private var vm = SignUpViewModel(transport: DemoOnboardingTransport(current: "name"), authSession: AuthSession())
    var body: some View {
        Group {
            if code { SignUpCodeView(vm: vm).onAppear { vm.input.email = "sam@acmelandscaping.example"; vm.sent = ["email"]; vm.setCode("48") } }
            else {
                SignUpFormView(vm: vm, onBack: {}, onSignIn: {})
                    .onAppear {
                        vm.input = SignUpLogic.Input(firstName: "Sam", lastName: "Lee", mobile: "(604) 555-0123", email: "sam@acmelandscaping.example",
                                                     password: "demo-password-1", confirm: "demo-password-1", termsAccepted: ticked)
                    }
            }
        }
    }
}

struct SignUpDemoOverlay: View {
    var body: some View {
        let args = ProcessInfo.processInfo.arguments
        let theme = { (v: AnyView) in v.itAppTheme(enabled: true, field: false) }
        if args.contains("-mwOpeningDemo") || args.contains("-mwWelcomeScreenDemo") {
            theme(AnyView(ItOpeningStage { IttaiWelcomeContent(onSetUp: {}, onSignIn: {}) }))
        } else if args.contains("-mwSignInDemo") {
            theme(AnyView(LoginView(authSession: AuthSession(), onBack: {})))
        } else if args.contains("-mwSignUpDemo") {
            let i = args.firstIndex(of: "-mwSignUpDemo")!
            let big = args.contains("-mwScrollDemo")
            theme(AnyView(DemoSignUpHost(code: false, ticked: i + 1 < args.count && args[i + 1] == "ticked").environment(\.dynamicTypeSize, big ? .accessibility2 : .large)))
        } else if args.contains("-mwCodeDemo") {
            theme(AnyView(DemoSignUpHost(code: true, ticked: true)))
        } else if args.contains("-mwTermsDemo") {
            theme(AnyView(DemoOnboardHost(step: "terms")))
        } else if let i = args.firstIndex(of: "-mwOnboardBarDemo") {
            // Onboarding on Kaname's spine with the one-tab bar (same screen as -mwOnboardDemo; the bar is part of it).
            let n = i + 1 < args.count ? Int(args[i + 1]) ?? 1 : 1
            theme(AnyView(DemoOnboardHost(step: ["name", "company", "quickbooks", "accountant", "done"][max(0, min(4, n - 1))])))
        } else if let i = args.firstIndex(of: "-mwOnboardDemo") {
            let n = i + 1 < args.count ? Int(args[i + 1]) ?? 1 : 1
            theme(AnyView(DemoOnboardHost(step: ["name", "company", "quickbooks", "accountant", "done"][max(0, min(4, n - 1))])))
        }
    }
}
#endif
