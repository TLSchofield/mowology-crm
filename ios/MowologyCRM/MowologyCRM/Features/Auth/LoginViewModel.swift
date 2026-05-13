//
//  LoginViewModel.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation
import LocalAuthentication

@MainActor
final class LoginViewModel: ObservableObject {

    // MARK: - Published State

    @Published var email: String = ""
    @Published var password: String = ""
    @Published var isLoading: Bool = false
    @Published var isBiometricLoading: Bool = false
    @Published var errorMessage: String?

    // MARK: - Dependencies

    let authSession: AuthSession

    // MARK: - Init

    init(authSession: AuthSession) {
        self.authSession = authSession
    }

    // MARK: - Computed

    /// `true` when a saved biometric session exists AND the device has
    /// biometrics enrolled. Drives visibility of the "Sign in with Face ID" button.
    var canUseBiometric: Bool {
        authSession.hasBiometricSession && BiometricAuth.isAvailable
    }

    var biometryKind: BiometricAuth.BiometryKind {
        BiometricAuth.availableKind
    }

    var biometricButtonTitle: String {
        "Sign in with \(biometryKind.displayName)"
    }

    var savedUserGreeting: String? {
        guard let user = authSession.user else { return nil }
        let trimmed = user.name.trimmingCharacters(in: .whitespaces)
        guard !trimmed.isEmpty else { return nil }
        let firstName = trimmed.split(separator: " ").first.map(String.init) ?? trimmed
        return "Welcome back, \(firstName)."
    }

    // MARK: - Actions

    /// Validates input and calls the AuthSession login method.
    /// On success, `authSession.isAuthenticated` becomes true and RootView
    /// transitions automatically. On failure, `errorMessage` is set.
    /// The identifier may be an email address or a username (drivers sign in
    /// with short usernames like "nigel"); the backend resolves either.
    func login() async {
        let trimmedIdentifier = email.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedPassword   = password.trimmingCharacters(in: .whitespacesAndNewlines)

        guard !trimmedIdentifier.isEmpty else {
            errorMessage = "Please enter your email or username."
            return
        }

        guard !trimmedPassword.isEmpty else {
            errorMessage = "Please enter your password."
            return
        }

        isLoading    = true
        errorMessage = nil

        do {
            try await authSession.login(email: trimmedIdentifier, password: trimmedPassword)
        } catch let apiError as APIError {
            errorMessage = apiError.errorDescription
        } catch {
            errorMessage = "An unexpected error occurred. Please try again."
        }

        isLoading = false
    }

    /// Triggers the biometric prompt and unlocks the saved JWT on success.
    ///
    /// This is also auto-fired by `LoginView`'s `.task` modifier on appear, so any
    /// flavor of "cancelled" — user-dismissed Face ID, system-cancelled LAContext
    /// evaluation, Task cancellation when the view tears down, or a `URLError.cancelled`
    /// from a network step — must NEVER surface as an error. The user didn't ask for
    /// the prompt; they only get an error banner when they explicitly tap Sign In.
    func loginWithBiometric() async {
        isBiometricLoading = true
        errorMessage       = nil

        do {
            try await authSession.loginWithBiometric()
        } catch {
            if LoginViewModel.isSilentCancellation(error) {
                // No-op — leave the form clean so the user can still tap Sign In.
            } else if let bioError = error as? BiometricAuth.BiometricError {
                errorMessage = bioError.errorDescription
            } else if let apiError = error as? APIError {
                errorMessage = apiError.errorDescription
            } else {
                errorMessage = error.localizedDescription
            }
        }

        isBiometricLoading = false
    }

    /// True when the error represents the user / system / Task / network cancelling
    /// the biometric flow. Centralised so the auto-prompt and any future biometric
    /// retry paths share the same classifier.
    static func isSilentCancellation(_ error: Error) -> Bool {
        // BiometricAuth's own cancel case.
        if let bio = error as? BiometricAuth.BiometricError {
            if case .userCancelled = bio { return true }
            // BiometricError.other(LAError) may wrap a raw LAError cancel code.
            if case .other(let inner) = bio, isLACancellation(inner) { return true }
        }

        // Raw LAError surfacing past BiometricAuth.authenticate (defensive).
        if isLACancellation(error) { return true }

        // URLError.cancelled (-999) — raw, or wrapped in APIError.networkError.
        if let urlError = error as? URLError, urlError.code == .cancelled { return true }
        if let apiError = error as? APIError, apiError.isCancelled { return true }

        // Swift concurrency Task cancellation.
        if error is CancellationError { return true }

        return false
    }

    private static func isLACancellation(_ error: Error) -> Bool {
        guard let la = error as? LAError else { return false }
        switch la.code {
        case .userCancel, .systemCancel, .appCancel:
            return true
        default:
            return false
        }
    }

    /// Clears any displayed error when the user resumes typing.
    func clearError() {
        errorMessage = nil
    }
}
