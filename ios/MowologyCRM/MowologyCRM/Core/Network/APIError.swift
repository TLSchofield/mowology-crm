//
//  APIError.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation

/// Typed errors surfaced by APIClient throughout the app.
enum APIError: LocalizedError {

    /// The server returned 401 — token expired or invalid credentials.
    case unauthorized

    /// A transport-layer failure (no internet, timeout, DNS, etc.).
    case networkError(Error)

    /// The response body could not be decoded into the expected model type.
    case decodingError(Error)

    /// The server returned an error payload. `statusCode` is the HTTP status
    /// when the error originates from a non-2xx HTTP response, and nil for
    /// errors synthesised on the client side (e.g. body encoding failures).
    /// 4xx generally means the request was rejected for a permanent reason
    /// and should not be retried; 5xx is usually transient.
    case serverError(statusCode: Int?, message: String)

    /// A URL could not be constructed from the endpoint definition.
    case invalidURL

    // MARK: - LocalizedError

    var errorDescription: String? {
        switch self {
        case .unauthorized:
            return "Your session has expired. Please sign in again."
        case .networkError(let underlying):
            return "Network error: \(underlying.localizedDescription)"
        case .decodingError(let underlying):
            return "Failed to read server response: \(underlying.localizedDescription)"
        case .serverError(_, let message):
            return message
        case .invalidURL:
            return "Invalid API endpoint URL."
        }
    }

    // MARK: - Classification helpers

    /// True for transient failures the caller may safely retry with the same
    /// idempotency key: network/transport errors and 5xx server responses.
    var isTransient: Bool {
        switch self {
        case .networkError:
            return true
        case .serverError(let code, _):
            // No code (e.g. client-side encode failure) → don't retry.
            // 5xx → transient.
            return (code ?? 0) >= 500
        default:
            return false
        }
    }

    /// True when the server explicitly rejected the request with a 4xx that
    /// we should treat as authoritative ("this won't succeed on retry").
    /// 401 is excluded because APIClient already triggers logout on its own.
    var isPermanentClientReject: Bool {
        if case .serverError(let code, _) = self,
           let code, (400..<500).contains(code), code != 401 {
            return true
        }
        return false
    }
}
