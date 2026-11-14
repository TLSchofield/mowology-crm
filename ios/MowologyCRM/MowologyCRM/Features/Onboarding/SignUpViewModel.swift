//
//  SignUpViewModel.swift
//  MowologyCRM
//
//  Create your login -> Check your email -> signed in. Behaviour ported from Android SignUpViewModel.kt.
//

import Foundation

@MainActor
final class SignUpViewModel: ObservableObject {
    enum Stage { case form, code }
    @Published var input = SignUpLogic.Input()
    @Published var errors: [String: String] = [:]
    @Published var loading = false
    @Published var error: String?
    @Published var stage: Stage = .form
    @Published var sent: [String] = []
    @Published var code = ""
    @Published var codeError: String?
    @Published var codeExpired = false
    @Published var notice: String?
    @Published var passwordHelp: String?
    @Published var showPasswords = false
    @Published var unavailable = false

    private let transport: OnboardingTransport
    private let authSession: AuthSession
    private let chooseHost: (String) async -> Void

    init(transport: OnboardingTransport, authSession: AuthSession, chooseHost: @escaping (String) async -> Void = { _ in }) {
        self.transport = transport; self.authSession = authSession; self.chooseHost = chooseHost
    }

    func edited() { errors = [:]; error = nil }
    func passwordEdited() { passwordHelp = nil }

    func generatePassword() {
        let p = PasswordGen.generate()
        input.password = p; input.confirm = p
        errors = [:]; error = nil; showPasswords = true
        passwordHelp = "Your phone will offer to save it — no need to write it down."
    }
    func setCode(_ value: String) { code = SignUpLogic.digitsOnly(value); codeError = nil; notice = nil }
    func changeEmail() { stage = .form; code = ""; codeError = nil; codeExpired = false; notice = nil }

    func submit() async { await send(resend: false) }
    func resend() async { await send(resend: true) }

    private func send(resend: Bool) async {
        let v = SignUpLogic.validate(input)
        if !v.isEmpty { errors = v; return }
        if loading { return }
        loading = true; error = nil; notice = nil
        await chooseHost(input.email)
        let res = await transport.send(method: "POST", path: "/api/auth/signup.php", query: nil, body: SignUpLogic.body(input), authorised: false)
        let fields = SignUpLogic.fieldErrors(res.json)
        if res.status == 404 { unavailable = true; loading = false; error = SignUpLogic.serverMessage(404) }
        else if !res.ok || !fields.isEmpty {
            loading = false; errors = fields; error = fields.isEmpty ? SignUpLogic.serverMessage(res.status) : nil
        } else {
            loading = false; stage = .code; sent = SignUpLogic.sent(res.json); codeError = nil; codeExpired = false
            notice = resend ? "Code sent again." : nil
        }
    }

    func confirm() async {
        guard SignUpLogic.codeValid(code) else { codeError = "Enter the 6-digit code."; return }
        if loading { return }
        loading = true; error = nil; codeError = nil; notice = nil
        let res = await transport.send(method: "POST", path: "/api/auth/signup-confirm.php", query: nil,
                                       body: SignUpLogic.confirmBody(input, code: code), authorised: false)
        if res.status == 404 { unavailable = true; loading = false; error = SignUpLogic.serverMessage(404); return }
        let ce = SignUpLogic.codeError(res.json)
        if res.status != 201 || ce != nil {
            loading = false; errors = SignUpLogic.fieldErrors(res.json); codeError = ce
            codeExpired = SignUpLogic.codeExpired(res.json)
            error = ce == nil ? SignUpLogic.serverMessage(res.status) : nil
            return
        }
        guard let json = res.json, let token = json["token"] as? String, !token.isEmpty,
              let userObj = json["user"] as? [String: Any],
              let data = try? JSONSerialization.data(withJSONObject: userObj),
              let user = try? JSONDecoder().decode(User.self, from: data) else {
            loading = false
            error = "Your login was created, but sign-in did not finish. Go back and sign in."
            return
        }
        // Onboarding is owed from the next step the server named; recorded BEFORE the session flips so RootView
        // never shows the day first.
        if let next = SignUpLogic.nextStep(json) {
            OnboardingProgress.begin(userId: user.id, next: next, chiefName: (userObj["chief_name"] as? String) ?? "",
                                     businessName: (userObj["business_name"] as? String) ?? "")
        }
        code = ""
        input.password = ""; input.confirm = ""
        loading = false
        authSession.acceptSignUp(token: token, user: user)
    }
}
