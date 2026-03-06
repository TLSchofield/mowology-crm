//
//  APIClient.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation

// MARK: - Decodable Envelope

/// Top-level wrapper the Mowology API wraps responses in.
private struct APIEnvelope<T: Decodable>: Decodable {
    let success: Bool
    let data: T?
    let message: String?

    // The API may place the payload at various top-level keys, so we fall back
    // to decoding the entire response as T when the envelope shape is absent.
}

// MARK: - APIClient

/// Central HTTP client for the Mowology CRM app.
///
/// - Injects `Authorization: Bearer <token>` for authenticated endpoints.
/// - Automatically calls `authSession.logout()` on a 401 response so
///   RootView transitions back to LoginView without any extra wiring.
/// - Generic `request<T>` method decodes the JSON response into any
///   `Decodable` type the caller specifies.
@MainActor
final class APIClient: ObservableObject {

    // Weak reference avoids a retain cycle (AuthSession -> APIClient -> AuthSession).
    private weak var authSession: AuthSession?

    private let session: URLSession
    private let decoder: JSONDecoder

    // MARK: - Init

    init(authSession: AuthSession) {
        self.authSession = authSession

        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest  = 30
        config.timeoutIntervalForResource = 60
        self.session = URLSession(configuration: config)

        self.decoder = JSONDecoder()
        // Models define explicit CodingKeys for snake_case mapping —
        // do NOT also set .convertFromSnakeCase (they conflict).
    }

    // MARK: - Generic Request

    /// Performs an HTTP request and decodes the response body into `T`.
    ///
    /// - Parameters:
    ///   - endpoint: The `APIEndpoint` case describing the URL and auth requirement.
    ///   - method:   HTTP method override (defaults to the endpoint's natural method).
    ///   - body:     Optional JSON dictionary to serialise into the request body.
    /// - Returns:    A decoded instance of `T`.
    /// - Throws:     `APIError` for all failure cases.
    func request<T: Decodable>(
        _ endpoint: APIEndpoint,
        method: String? = nil,
        body: [String: Any]? = nil
    ) async throws -> T {
        guard let url = endpoint.url else {
            throw APIError.invalidURL
        }

        var urlRequest = URLRequest(url: url)
        urlRequest.httpMethod = method ?? endpoint.httpMethod
        urlRequest.setValue("application/json", forHTTPHeaderField: "Content-Type")
        urlRequest.setValue("application/json", forHTTPHeaderField: "Accept")

        // Inject Bearer token for authenticated endpoints.
        if endpoint.requiresAuth {
            if let token = authSession?.token {
                urlRequest.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
            }
        }

        // Serialise the body dictionary, if provided.
        if let body = body {
            guard let bodyData = try? JSONSerialization.data(withJSONObject: body) else {
                throw APIError.serverError("Failed to encode request body.")
            }
            urlRequest.httpBody = bodyData
        }

        // Execute the network request.
        let data: Data
        let response: URLResponse

        do {
            (data, response) = try await session.data(for: urlRequest)
        } catch {
            throw APIError.networkError(error)
        }

        // Inspect the HTTP status code.
        if let httpResponse = response as? HTTPURLResponse {
            if httpResponse.statusCode == 401 {
                // Token is invalid or expired — sign out immediately.
                authSession?.logout()
                throw APIError.unauthorized
            }

            if !(200..<300).contains(httpResponse.statusCode) {
                let message = extractErrorMessage(from: data)
                    ?? "Server returned status \(httpResponse.statusCode)."
                throw APIError.serverError(message)
            }
        }

        // Decode the response. We attempt a direct decode first; if that fails
        // we try the standard {"success": true, ...} envelope the API uses on
        // list endpoints.
        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            throw APIError.decodingError(error)
        }
    }

    // MARK: - Private Helpers

    private func extractErrorMessage(from data: Data) -> String? {
        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            return nil
        }
        return json["message"] as? String
            ?? json["error"] as? String
    }
}
