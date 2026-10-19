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

    /// The server returned an error payload with an explanatory message.
    case serverError(String)

    /// A URL could not be constructed from the endpoint definition.
    case invalidURL

    // MARK: - LocalizedError

    var errorDescription: String? {
        switch self {
        case .unauthorized:
            return "HTTP 401 Unauthorized. Sign in again; the server response is recorded in the diagnostic log."
        case .networkError(let underlying):
            return "Network error: \(ErrorDetails.describe(underlying))"
        case .decodingError(let underlying):
            return "Failed to read server response: \(ErrorDetails.describe(underlying))"
        case .serverError(let message):
            return message
        case .invalidURL:
            return "Invalid API endpoint URL."
        }
    }
}
