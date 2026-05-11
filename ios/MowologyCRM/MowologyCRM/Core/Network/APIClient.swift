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

    private weak var authSession: AuthSession?

    private let session: URLSession
    private let decoder: JSONDecoder

    // Transient network failures we silently retry on idempotent requests.
    // -1005 (NSURLErrorNetworkConnectionLost) commonly fires when shared cPanel
    // hosting recycles a keep-alive socket mid-request — the server typically
    // processed the call but the client never read the response.
    private static let retryableNetworkErrorCodes: Set<Int> = [
        NSURLErrorNetworkConnectionLost,  // -1005
        NSURLErrorTimedOut,               // -1001
    ]
    private static let maxRetryAttempts = 3

    // MARK: - Init

    init(authSession: AuthSession) {
        self.authSession = authSession

        let config = URLSessionConfiguration.default
        // 60/120s — server queries (getCalendarStops, receipt GCV) can exceed
        // the old 30s limit on slow connections or cold PHP starts.
        config.timeoutIntervalForRequest  = 60
        config.timeoutIntervalForResource = 120
        self.session = URLSession(configuration: config)

        self.decoder = JSONDecoder()
    }

    // MARK: - Generic Request

    /// Performs an HTTP request and decodes the response body into `T`.
    ///
    /// - Parameter retryable: pass `true` for POST endpoints whose handlers are
    ///   safe to call twice (idempotent). GET requests retry automatically.
    func request<T: Decodable>(
        _ endpoint: APIEndpoint,
        method: String? = nil,
        body: [String: Any]? = nil,
        extraHeaders: [String: String] = [:],
        retryable: Bool = false
    ) async throws -> T {
        guard let url = endpoint.url else {
            throw APIError.invalidURL
        }

        var urlRequest = URLRequest(url: url)
        urlRequest.httpMethod = method ?? endpoint.httpMethod
        urlRequest.setValue("application/json", forHTTPHeaderField: "Content-Type")
        urlRequest.setValue("application/json", forHTTPHeaderField: "Accept")

        for (key, value) in extraHeaders {
            urlRequest.setValue(value, forHTTPHeaderField: key)
        }

        if endpoint.requiresAuth {
            if let token = authSession?.token {
                urlRequest.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
            }
        }

        if let body = body {
            guard let bodyData = try? JSONSerialization.data(withJSONObject: body) else {
                throw APIError.serverError("Failed to encode request body.")
            }
            urlRequest.httpBody = bodyData
        }

        let canRetry = retryable || (urlRequest.httpMethod ?? "GET") == "GET"

        let data: Data
        let response: URLResponse

        do {
            (data, response) = try await performDataTask(
                urlRequest,
                retryOnTransientError: canRetry
            )
        } catch {
            let err = APIError.networkError(error)
            #if DEBUG
            DevErrorBus.shared.post(err)
            #endif
            throw err
        }

        if let httpResponse = response as? HTTPURLResponse {
            if httpResponse.statusCode == 401 {
                authSession?.logout()
                throw APIError.unauthorized
            }

            if !(200..<300).contains(httpResponse.statusCode) {
                let message = extractErrorMessage(from: data)
                    ?? "Server returned status \(httpResponse.statusCode)."
                let err = APIError.serverError(message)
                #if DEBUG
                DevErrorBus.shared.post(err)
                #endif
                throw err
            }
        }

        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            let err = APIError.decodingError(error)
            #if DEBUG
            DevErrorBus.shared.post(err)
            #endif
            throw err
        }
    }

    // MARK: - Multipart Upload

    /// Uploads a receipt image as multipart/form-data and returns OCR suggestions.
    /// `visionText` is the raw text extracted by on-device Vision; when supplied the server
    /// skips Tesseract and uses this text directly, improving parse quality and speed.
    func uploadReceipt(imageData: Data, visionText: String? = nil, lat: Double?, lng: Double?, jobId: Int?) async throws -> ReceiptIntakeResponse {
        guard let url = APIEndpoint.receiptUpload.url else { throw APIError.invalidURL }

        let boundary = "MwBoundary-\(UUID().uuidString.replacingOccurrences(of: "-", with: ""))"
        var request  = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        if let token = authSession?.token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        var body = Data()
        body.appendField(name: "receipt_photo", filename: "receipt.jpg", mimeType: "image/jpeg", data: imageData, boundary: boundary)
        if let visionText, !visionText.isEmpty {
            body.appendField(name: "vision_text", value: visionText, boundary: boundary)
        }
        if let lat   { body.appendField(name: "lat",    value: "\(lat)",    boundary: boundary) }
        if let lng   { body.appendField(name: "lng",    value: "\(lng)",    boundary: boundary) }
        if let jobId { body.appendField(name: "job_id", value: "\(jobId)", boundary: boundary) }
        body.append("--\(boundary)--\r\n".data(using: .utf8) ?? Data())
        request.httpBody = body

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await performDataTask(request, retryOnTransientError: false)
        } catch {
            let err = APIError.networkError(error)
            #if DEBUG
            DevErrorBus.shared.post(err)
            #endif
            throw err
        }

        if let http = response as? HTTPURLResponse {
            if http.statusCode == 401 { authSession?.logout(); throw APIError.unauthorized }
            if !(200..<300).contains(http.statusCode) {
                let msg = extractErrorMessage(from: data) ?? "Upload failed (\(http.statusCode))"
                let err = APIError.serverError(msg)
                #if DEBUG
                DevErrorBus.shared.post(err)
                #endif
                throw err
            }
        }

        do {
            return try decoder.decode(ReceiptIntakeResponse.self, from: data)
        } catch {
            let err = APIError.decodingError(error)
            #if DEBUG
            DevErrorBus.shared.post(err)
            #endif
            throw err
        }
    }

    // MARK: - Job Photo Upload

    /// Uploads a before/after job site photo as multipart/form-data.
    /// Returns Void on success; throws on HTTP or network error.
    /// Callers should enqueue to `JobPhotoQueue` on failure for offline retry.
    func uploadJobPhoto(imageData: Data, visitId: Int, photoType: JobPhotoType) async throws {
        guard let url = APIEndpoint.jobPhoto(visitId: visitId).url else {
            throw APIError.invalidURL
        }

        let boundary = "MwBoundary-\(UUID().uuidString.replacingOccurrences(of: "-", with: ""))"
        var request  = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        if let token = authSession?.token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        var body = Data()
        body.appendField(name: "job_photo", filename: "photo.jpg",
                         mimeType: "image/jpeg", data: imageData, boundary: boundary)
        body.appendField(name: "visit_id",   value: "\(visitId)",         boundary: boundary)
        body.appendField(name: "photo_type", value: photoType.rawValue,   boundary: boundary)
        body.append("--\(boundary)--\r\n".data(using: .utf8) ?? Data())
        request.httpBody = body

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await performDataTask(request, retryOnTransientError: false)
        } catch {
            let err = APIError.networkError(error)
            #if DEBUG
            DevErrorBus.shared.post(err)
            #endif
            throw err
        }

        if let http = response as? HTTPURLResponse {
            if http.statusCode == 401 { authSession?.logout(); throw APIError.unauthorized }
            if !(200..<300).contains(http.statusCode) {
                let msg = extractErrorMessage(from: data) ?? "Job photo upload failed (\(http.statusCode))"
                let err = APIError.serverError(msg)
                #if DEBUG
                DevErrorBus.shared.post(err)
                #endif
                throw err
            }
        }

        // Verify server acknowledged success
        if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
           let success = json["success"] as? Bool, !success {
            let msg = (json["error"] as? String) ?? "Server rejected job photo."
            throw APIError.serverError(msg)
        }
    }

    // MARK: - Authenticated Image Fetch

    /// Fetches raw receipt image bytes using the bearer token.
    /// Use instead of `AsyncImage(url:)` because `/uploads/receipts/` is
    /// protected by `Deny from all` and requires the Authorization header.
    func fetchReceiptImageData(mediaId: Int) async throws -> Data {
        guard let url = APIEndpoint.receiptImage(mediaId: mediaId).url else {
            throw APIError.invalidURL
        }
        var urlRequest = URLRequest(url: url)
        urlRequest.httpMethod = "GET"
        if let token = authSession?.token {
            urlRequest.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await performDataTask(urlRequest, retryOnTransientError: true)
        } catch {
            let err = APIError.networkError(error)
            #if DEBUG
            DevErrorBus.shared.post(err)
            #endif
            throw err
        }

        if let http = response as? HTTPURLResponse {
            if http.statusCode == 401 { authSession?.logout(); throw APIError.unauthorized }
            if !(200..<300).contains(http.statusCode) {
                let err = APIError.serverError("Image unavailable (\(http.statusCode))")
                #if DEBUG
                DevErrorBus.shared.post(err)
                #endif
                throw err
            }
        }
        return data
    }

    // MARK: - Private Helpers

    /// Executes a data task with optional retry on transient network failures.
    /// Retries on -1005 (NSURLErrorNetworkConnectionLost) and -1001 (timeout)
    /// up to `maxRetryAttempts` times with exponential backoff (1s, 2s, 4s).
    /// Re-throws the underlying URLError on the final failure.
    private func performDataTask(
        _ urlRequest: URLRequest,
        retryOnTransientError: Bool
    ) async throws -> (Data, URLResponse) {
        let maxAttempts = retryOnTransientError ? Self.maxRetryAttempts + 1 : 1
        var attempt = 0
        while true {
            attempt += 1
            do {
                return try await session.data(for: urlRequest)
            } catch {
                let nsError = error as NSError
                let isTransient = nsError.domain == NSURLErrorDomain
                    && Self.retryableNetworkErrorCodes.contains(nsError.code)
                if isTransient && attempt < maxAttempts {
                    let delaySec = pow(2.0, Double(attempt - 1))  // 1, 2, 4
                    #if DEBUG
                    let path = urlRequest.url?.lastPathComponent ?? "?"
                    print("⚠️ APIClient retry \(attempt)/\(maxAttempts - 1) for \(path) — code \(nsError.code), waiting \(delaySec)s")
                    #endif
                    try? await Task.sleep(nanoseconds: UInt64(delaySec * 1_000_000_000))
                    continue
                }
                throw error
            }
        }
    }

    private func extractErrorMessage(from data: Data) -> String? {
        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            return nil
        }
        return json["message"] as? String
            ?? json["error"] as? String
    }
}

// MARK: - Data multipart helpers

private extension Data {
    mutating func appendField(name: String, filename: String, mimeType: String, data: Data, boundary: String) {
        append("--\(boundary)\r\n".data(using: .utf8) ?? Data())
        append("Content-Disposition: form-data; name=\"\(name)\"; filename=\"\(filename)\"\r\n".data(using: .utf8) ?? Data())
        append("Content-Type: \(mimeType)\r\n\r\n".data(using: .utf8) ?? Data())
        append(data)
        append("\r\n".data(using: .utf8) ?? Data())
    }

    mutating func appendField(name: String, value: String, boundary: String) {
        append("--\(boundary)\r\n".data(using: .utf8) ?? Data())
        append("Content-Disposition: form-data; name=\"\(name)\"\r\n\r\n".data(using: .utf8) ?? Data())
        append(value.data(using: .utf8) ?? Data())
        append("\r\n".data(using: .utf8) ?? Data())
    }
}
