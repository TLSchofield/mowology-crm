//
//  BiometricAuth.swift
//  MowologyCRM
//

import Foundation
import LocalAuthentication

@MainActor
enum BiometricAuth {

    enum BiometryKind {
        case none
        case touchID
        case faceID
        case opticID

        var displayName: String {
            switch self {
            case .faceID:  return "Face ID"
            case .touchID: return "Touch ID"
            case .opticID: return "Optic ID"
            case .none:    return "Biometrics"
            }
        }

        var symbolName: String {
            switch self {
            case .faceID:  return "faceid"
            case .touchID: return "touchid"
            case .opticID: return "opticid"
            case .none:    return "lock.shield"
            }
        }
    }

    enum BiometricError: LocalizedError {
        case notAvailable
        case userCancelled
        case authenticationFailed
        case biometryLockout
        case other(Error)

        var errorDescription: String? {
            switch self {
            case .notAvailable:
                return "Biometrics are not set up on this device."
            case .userCancelled:
                return "Sign-in cancelled."
            case .authenticationFailed:
                return "Biometric authentication failed. Please try again or sign in with your password."
            case .biometryLockout:
                return "Biometrics are locked. Unlock your device with your passcode and try again."
            case .other(let err):
                return err.localizedDescription
            }
        }
    }

    /// The kind of biometry available on the current device, or `.none`
    /// if biometrics are not enrolled / not supported.
    static var availableKind: BiometryKind {
        let context = LAContext()
        var error: NSError?
        guard context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &error) else {
            return .none
        }
        switch context.biometryType {
        case .faceID:     return .faceID
        case .touchID:    return .touchID
        case .opticID:    return .opticID
        case .none:       return .none
        @unknown default: return .none
        }
    }

    static var isAvailable: Bool {
        availableKind != .none
    }

    /// Prompts the user for biometric authentication. On success, returns an
    /// authenticated `LAContext` that can be passed to Keychain reads via
    /// `kSecUseAuthenticationContext` so the user is not prompted twice.
    static func authenticate(reason: String) async throws -> LAContext {
        let context = LAContext()
        // Hide the password fallback button — we have our own email/password screen.
        context.localizedFallbackTitle = ""

        var policyError: NSError?
        guard context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &policyError) else {
            throw BiometricError.notAvailable
        }

        return try await withCheckedThrowingContinuation { continuation in
            context.evaluatePolicy(
                .deviceOwnerAuthenticationWithBiometrics,
                localizedReason: reason
            ) { success, evalError in
                if success {
                    continuation.resume(returning: context)
                    return
                }

                guard let laError = evalError as? LAError else {
                    continuation.resume(throwing: BiometricError.other(evalError ?? BiometricError.authenticationFailed))
                    return
                }

                switch laError.code {
                case .userCancel, .systemCancel, .appCancel:
                    continuation.resume(throwing: BiometricError.userCancelled)
                case .biometryLockout:
                    continuation.resume(throwing: BiometricError.biometryLockout)
                case .authenticationFailed:
                    continuation.resume(throwing: BiometricError.authenticationFailed)
                default:
                    continuation.resume(throwing: BiometricError.other(laError))
                }
            }
        }
    }
}
