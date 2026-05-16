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
        } catch let apiError as APIError where Self.isCancellation(apiError) {
            errorMessage = nil  // request superseded/dismissed — not a real error
        } catch let apiError as APIError {
            errorMessage = apiError.errorDescription
        } catch let urlError as URLError where urlError.code == .cancelled {
            errorMessage = nil
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
        } catch let laError as LAError {
            switch laError.code {
            case .userCancel, .systemCancel, .appCancel, .userFallback:
                // User dismissed Face ID / Touch ID — this is not an error.
                errorMessage = nil
            default:
                errorMessage = "Biometric sign-in failed. Please use your password."
            }
        } catch let apiError as APIError where Self.isCancellation(apiError) {
            errorMessage = nil  // request superseded/dismissed — not a real error
        } catch let apiError as APIError {
            errorMessage = apiError.errorDescription
        } catch let urlError as URLError where urlError.code == .cancelled {
            errorMessage = nil
        } catch {
            errorMessage = "An unexpected error occurred. Please try again."
        }

        isLoading = false
    }

    /// True when the error is a benign request cancellation (`URLError.cancelled`,
    /// -999), including when wrapped in `APIError.networkError`.
    private static func isCancellation(_ error: Error) -> Bool {
        if let urlError = error as? URLError, urlError.code == .cancelled {
            return true
        }
        if let apiError = error as? APIError,
           case .networkError(let underlying) = apiError,
           let urlError = underlying as? URLError,
           urlError.code == .cancelled {
            return true
        }
        return false
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
