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
    @Published var errorMessage: String?

    // MARK: - Dependencies

    private let authSession: AuthSession

    // MARK: - Init

    init(authSession: AuthSession) {
        self.authSession = authSession
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
            // On success, authSession.isAuthenticated flips to true.
            // RootView handles the navigation — no action needed here.
        } catch let apiError as APIError {
            errorMessage = apiError.errorDescription
        } catch {
            errorMessage = "An unexpected error occurred. Please try again."
        }

        isLoading = false
    }

    /// Clears any displayed error when the user resumes typing.
    func clearError() {
        errorMessage = nil
    }

    // MARK: - Validation

    private func isValidEmail(_ value: String) -> Bool {
        // RFC 5322-lite pattern sufficient for UX validation.
        let pattern = #"^[A-Z0-9a-z._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$"#
        return value.range(of: pattern, options: .regularExpression) != nil
    }
}
