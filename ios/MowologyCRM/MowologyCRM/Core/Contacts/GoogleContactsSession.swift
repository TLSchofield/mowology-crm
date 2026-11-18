import AuthenticationServices
import UIKit

@MainActor
protocol GoogleContactsSigningIn {
    func authenticate(url: URL) async throws -> URL
}

enum GoogleContactsSessionError: LocalizedError, Equatable {
    case cancelled
    case missingCallbackURL
    case couldNotStart

    var errorDescription: String? {
        switch self {
        case .cancelled: return "Google contact access was cancelled."
        case .missingCallbackURL: return "Google contact access finished without a callback URL."
        case .couldNotStart: return "Google contact access could not open the sign-in page."
        }
    }
}

@MainActor
final class LiveGoogleContactsSession: NSObject, GoogleContactsSigningIn, ASWebAuthenticationPresentationContextProviding {
    private var session: ASWebAuthenticationSession?
    private let fallbackWindow = UIWindow(frame: .zero)

    func authenticate(url: URL) async throws -> URL {
        try await withCheckedThrowingContinuation { continuation in
            let session = ASWebAuthenticationSession(url: url, callbackURLScheme: "ittai-google") { [weak self] callbackURL, error in
                Task { @MainActor in
                    self?.session = nil
                    if let authenticationError = error as? ASWebAuthenticationSessionError,
                       authenticationError.code == .canceledLogin {
                        continuation.resume(throwing: GoogleContactsSessionError.cancelled)
                    } else if let error {
                        continuation.resume(throwing: error)
                    } else if let callbackURL {
                        continuation.resume(returning: callbackURL)
                    } else {
                        continuation.resume(throwing: GoogleContactsSessionError.missingCallbackURL)
                    }
                }
            }
            session.presentationContextProvider = self
            session.prefersEphemeralWebBrowserSession = true
            self.session = session
            guard session.start() else {
                self.session = nil
                continuation.resume(throwing: GoogleContactsSessionError.couldNotStart)
                return
            }
        }
    }

    nonisolated func presentationAnchor(for session: ASWebAuthenticationSession) -> ASPresentationAnchor {
        MainActor.assumeIsolated {
            let scenes = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }
            return scenes.flatMap(\.windows).first(where: \.isKeyWindow)
                ?? scenes.flatMap(\.windows).first(where: { !$0.isHidden })
                ?? fallbackWindow
        }
    }
}
