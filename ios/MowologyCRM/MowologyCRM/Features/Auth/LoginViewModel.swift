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

    @Published var email:        String  = ""
    @Published var password:     String  = ""
    @Published var isLoading:    Bool    = false
    @Published var errorMessage: String? = nil

    // MARK: - Biometric

    /// Whether Face ID / Touch ID is available AND credentials are stored.
    var canUseBiometrics: Bool {
        var nsError: NSError?
        let context = LAContext()
        return context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics,
                                         error: &nsError)
            && authSession.hasBiometricCredentials
    }

    /// System image name for the current biometry type.
    var biometricSystemImage: String {
        let context = LAContext()
        var nsError: NSError?
        guard context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics,
                                        error: &nsError) else { return "faceid" }
        switch context.biometryType {
        case .faceID:  return "faceid"
        case .touchID: return "touchid"
        default:       return "lock.circle"
        }
    }

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

    /// Authenticates using Face ID / Touch ID, then re-authenticates against the server
    /// using Keychain-stored credentials.  Only callable when `canUseBiometrics` is true.
    func biometricLogin() async {
        let context = LAContext()
        var nsError: NSError?
        guard context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics,
                                        error: &nsError) else {
            errorMessage = "Biometric authentication is not available on this device."
            return
        }
        guard let (storedEmail, storedPassword) = authSession.biometricCredentials else {
            errorMessage = "No saved credentials. Please sign in with your password first."
            return
        }

        isLoading    = true
        errorMessage = nil

        do {
            // Prompt the user with Face ID / Touch ID.
            // On iOS 16+ evaluatePolicy is async and throws on failure/cancellation.
            _ = try await context.evaluatePolicy(
                .deviceOwnerAuthenticationWithBiometrics,
                localizedReason: "Sign in to Mowology"
            )
            // Biometric passed — authenticate with server using stored credentials.
            try await authSession.login(email: storedEmail, password: storedPassword)
        } catch let apiError as APIError {
            errorMessage = apiError.errorDescription
        } catch {
            // LAError — user cancelled, failed too many times, etc.
            errorMessage = nil  // silently cancel — don't show "authentication failed" for a cancel
        }

        isLoading = false
    }

    /// Clears any displayed error when the user resumes typing.
    func clearError() {
        errorMessage = nil
    }

}
