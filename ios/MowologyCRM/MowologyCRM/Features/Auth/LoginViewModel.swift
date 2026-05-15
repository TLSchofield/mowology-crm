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
}
