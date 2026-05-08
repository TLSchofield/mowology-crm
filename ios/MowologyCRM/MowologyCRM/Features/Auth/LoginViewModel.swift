//
//  LoginViewModel.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation

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
    func login() async {
        let trimmedEmail    = email.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedPassword = password.trimmingCharacters(in: .whitespacesAndNewlines)

        guard !trimmedEmail.isEmpty else {
            errorMessage = "Please enter your email address."
            return
        }

        guard isValidEmail(trimmedEmail) else {
            errorMessage = "Please enter a valid email address."
            return
        }

        guard !trimmedPassword.isEmpty else {
            errorMessage = "Please enter your password."
            return
        }

        isLoading    = true
        errorMessage = nil

        do {
            try await authSession.login(email: trimmedEmail, password: trimmedPassword)
        } catch let apiError as APIError {
            errorMessage = apiError.errorDescription
        } catch {
            errorMessage = "An unexpected error occurred. Please try again."
        }

        isLoading = false
    }

    /// Triggers the biometric prompt and unlocks the saved JWT on success.
    func loginWithBiometric() async {
        isBiometricLoading = true
        errorMessage       = nil

        do {
            try await authSession.loginWithBiometric()
        } catch let bioError as BiometricAuth.BiometricError {
            // Suppress the cancel message — that's user intent, not an error.
            if case .userCancelled = bioError {
                errorMessage = nil
            } else {
                errorMessage = bioError.errorDescription
            }
        } catch {
            errorMessage = error.localizedDescription
        }

        isBiometricLoading = false
    }

    /// Clears any displayed error when the user resumes typing.
    func clearError() {
        errorMessage = nil
    }

    // MARK: - Validation

    private func isValidEmail(_ value: String) -> Bool {
        let pattern = #"^[A-Z0-9a-z._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$"#
        return value.range(of: pattern, options: .regularExpression) != nil
    }
}
