//
//  SignedOutFlow.swift
//  MowologyCRM
//
//  Everything a signed-out phone shows: the opening that ends on the wordmark and becomes the Welcome screen,
//  Create your login, Check your email, and the existing sign-in. Existing logins are unchanged: Welcome's
//  "I already have an account" leads to the same LoginView and LoginViewModel as before.
//

import SwiftUI

struct SignedOutFlow: View {
    enum Screen { case welcome, signUp, signIn }
    @State private var screen: Screen
    @StateObject private var signUp: SignUpViewModel
    private let authSession: AuthSession

    init(authSession: AuthSession, start: Screen = .welcome, transport: OnboardingTransport? = nil) {
        self.authSession = authSession
        _screen = State(initialValue: start)
        let live = LiveOnboardingTransport(authSession: authSession)
        let t: OnboardingTransport = transport ?? live
        _signUp = StateObject(wrappedValue: SignUpViewModel(transport: t, authSession: authSession, chooseHost: { email in
            guard transport == nil else { return }
            await live.choose(forEmail: email)
            SignUpHostStore.base = live.chosenBase
        }))
    }

    var body: some View {
        Group {
            switch screen {
            case .welcome:
                ItOpeningStage { IttaiWelcomeContent(onSetUp: { screen = .signUp }, onSignIn: { screen = .signIn }) }
            case .signUp:
                if signUp.stage == .form {
                    SignUpFormView(vm: signUp, onBack: { screen = .welcome }, onSignIn: { screen = .signIn })
                } else {
                    SignUpCodeView(vm: signUp)
                }
            case .signIn:
                LoginView(authSession: authSession, onBack: { screen = .welcome })
            }
        }
        .itAppTheme(enabled: true, field: false)
        .animation(.easeInOut(duration: 0.2), value: screen)
    }
}

/// Signed in, onboarding still owed: the five steps against the business the person just signed up at.
struct BusinessOnboardingHost: View {
    @StateObject private var vm: BusinessOnboardingViewModel
    private let userId: Int
    private let onDone: () -> Void

    init(authSession: AuthSession, userId: Int, onDone: @escaping () -> Void) {
        self.userId = userId; self.onDone = onDone
        _vm = StateObject(wrappedValue: BusinessOnboardingViewModel(transport: LiveOnboardingTransport(authSession: authSession)))
    }
    var body: some View {
        BusinessOnboardingView(vm: vm, chiefName: OnboardingProgress.chiefName(userId: userId),
                               businessName: OnboardingProgress.businessName(userId: userId), onDone: onDone)
    }
}
