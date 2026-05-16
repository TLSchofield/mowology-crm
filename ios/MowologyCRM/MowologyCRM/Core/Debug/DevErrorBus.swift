//
//  DevErrorBus.swift
//  MowologyCRM
//
//  Development-only global error surface. All APIClient errors are forwarded
//  here so RootView can show them regardless of which tab or sheet is active.
//  Wrapped in #if DEBUG — zero overhead in Release builds.
//

#if DEBUG
import Foundation

@MainActor
final class DevErrorBus: ObservableObject {

    static let shared = DevErrorBus()
    private init() {}

    @Published var pendingError: String?

    func post(_ error: Error, url: URL? = nil) {
        // Cancelled requests are normal — a view dismissed, a request was
        // superseded, or the user dismissed a Face ID prompt. They must never
        // surface as a "Dev Error".
        if Self.isCancellation(error) { return }

        if let url {
            // Trim baseURLString prefix when possible so the alert stays readable.
            let path = url.path.isEmpty ? url.absoluteString : url.path
            pendingError = "\(path)\n\n\(error.localizedDescription)"
        } else {
            pendingError = error.localizedDescription
        }
    }

    /// True when the error represents a benign request cancellation
    /// (`URLError.cancelled`, -999), including when wrapped in
    /// `APIError.networkError`.
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
}
#endif
