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

    /// Who is signed in — the offline queues send only this person's queued actions.
    var currentUserId: Int? { authSession?.user?.id }

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
        body: [String: Any]? = nil,
        extraHeaders: [String: String] = [:],
        timeout: TimeInterval? = nil,
        faultsAreTransient: Bool = false
    ) async throws -> T {
        guard let url = endpoint.url else {
            throw APIError.invalidURL
        }

        var urlRequest = URLRequest(url: url)
        // A longer ceiling for slow server work (a receipt rescan runs OCR: up to ~45 s).
        if let timeout { urlRequest.timeoutInterval = timeout }
        urlRequest.httpMethod = method ?? endpoint.httpMethod
        urlRequest.setValue("application/json", forHTTPHeaderField: "Content-Type")
        urlRequest.setValue("application/json", forHTTPHeaderField: "Accept")

        // Inject caller-supplied extra headers (e.g. Idempotency-Key).
        for (key, value) in extraHeaders {
            urlRequest.setValue(value, forHTTPHeaderField: key)
        }

        // Inject Bearer token for authenticated endpoints.
        if endpoint.requiresAuth {
            if let token = authSession?.token {
                urlRequest.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
            }
        }

        // Serialise the body dictionary, if provided.
        if let body = body {
            do { urlRequest.httpBody = try JSONSerialization.data(withJSONObject: body) }
            catch {
                let detail = "\(ErrorDetails.request(urlRequest)): request encoding failed: \(ErrorDetails.describe(error))"
                AppDiagnostics.shared.record(category: "request.encode", message: detail)
                throw APIError.serverError(detail)
            }
        }

        // Execute the network request.
        let data: Data
        let response: URLResponse

        do {
            (data, response) = try await executeRetryingBusy(urlRequest)
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
                let message = ErrorDetails.response(data, status: httpResponse.statusCode)
                if faultsAreTransient, UploadFault.isTransient(httpResponse.statusCode) {
                    throw APIError.networkError(UploadFault(status: httpResponse.statusCode, detail: message))
                }
                throw APIError.serverError(message)
            }
        }

        // Decode the response. We attempt a direct decode first; if that fails
        // we try the standard {"success": true, ...} envelope the API uses on
        // list endpoints.
        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            logDecode(error, data: data, response: response, request: urlRequest)
            if faultsAreTransient {
                // A PHP fatal truncates the body with HTTP 200 — not an answer, try again later.
                throw APIError.networkError(UploadFault(status: 200, detail: ErrorDetails.describe(error)))
            }
            throw APIError.decodingError(error)
        }
    }


    // MARK: - Multipart Upload

    /// Uploads a receipt image as multipart/form-data and returns OCR suggestions.
       func uploadReceipt(imageData: Data, lat: Double?, lng: Double?, jobId: Int?) async throws -> ReceiptIntakeResponse {
        guard let url = APIEndpoint.receiptUpload.url else { throw APIError.invalidURL }

        let boundary = "MwBoundary-\(UUID().uuidString.replacingOccurrences(of: "-", with: ""))"
        var request  = URLRequest(url: url)
        request.httpMethod = "POST"
        // 45s per-request ceiling so the upload spinner can't sit forever when the
        // server's OCR pipeline (Tesseract 15s + Vision 30s) outlives the hosting
        // PHP cap. On timeout, URLError.timedOut surfaces as APIError.networkError
        // and ReceiptsViewModel enqueues to ReceiptQueue for automatic retry.
        request.timeoutInterval = 45
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        if let token = authSession?.token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        // The phone reads the text too; the server compares it with its own read (and may use it once proven).
        let device = await DeviceTextScanner.read(imageData)

        var body = Data()
        body.appendField(name: "receipt_photo", filename: "receipt.jpg", mimeType: "image/jpeg", data: imageData, boundary: boundary)
        if let device = device {
            body.appendField(name: "client_ocr_text", value: device.text, boundary: boundary)
            body.appendField(name: "client_ocr_engine", value: device.engine, boundary: boundary)
            body.appendField(name: "client_ocr_version", value: device.version, boundary: boundary)
        }
        if let lat = lat { body.appendField(name: "lat", value: "\(lat)", boundary: boundary) }
        if let lng = lng { body.appendField(name: "lng", value: "\(lng)", boundary: boundary) }
        if let jobId = jobId { body.appendField(name: "job_id", value: "\(jobId)", boundary: boundary) }
        body.append("--\(boundary)--\r\n".data(using: .utf8)!)
        request.httpBody = body

        let data: Data
        let response: URLResponse
        do { (data, response) = try await execute(request) }
        catch { throw APIError.networkError(error) }

        if let http = response as? HTTPURLResponse {
            if http.statusCode == 401 { authSession?.logout(); throw APIError.unauthorized }
            if !(200..<300).contains(http.statusCode) {
                let msg = ErrorDetails.response(data, status: http.statusCode)
                // 5xx / timeout / rate limit: the server didn't decide — retry later (queued).
                // Any other 4xx is the server's answer about this photo — retrying won't help.
                if UploadFault.isTransient(http.statusCode) {
                    throw APIError.networkError(UploadFault(status: http.statusCode, detail: msg))
                }
                throw APIError.serverError(msg)
            }
        }

        do { return try decoder.decode(ReceiptIntakeResponse.self, from: data) }
        catch {
            logDecode(error, data: data, response: response, request: request)
            // A PHP fatal truncates the body with HTTP 200 — not an answer, try again later.
            throw APIError.networkError(UploadFault(status: 200, detail: ErrorDetails.describe(error)))
        }
    }

    // MARK: - Label Upload

    /// Uploads a photo of a product label / machine nameplate. Never creates an expense —
    /// the server turns it into a proposal on Penny's or Otto's web card.
    func uploadLabel(imageData: Data, lat: Double?, lng: Double?) async throws -> LabelCaptureResponse {
        guard let url = APIEndpoint.labelUpload.url else { throw APIError.invalidURL }

        let boundary = "MwBoundary-\(UUID().uuidString.replacingOccurrences(of: "-", with: ""))"
        var request  = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 45
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        if let token = authSession?.token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        var body = Data()
        body.appendField(name: "label_photo", filename: "label.jpg", mimeType: "image/jpeg", data: imageData, boundary: boundary)
        if let lat = lat { body.appendField(name: "lat", value: "\(lat)", boundary: boundary) }
        if let lng = lng { body.appendField(name: "lng", value: "\(lng)", boundary: boundary) }
        body.append("--\(boundary)--\r\n".data(using: .utf8)!)
        request.httpBody = body

        let data: Data
        let response: URLResponse
        do { (data, response) = try await execute(request) }
        catch { throw APIError.networkError(error) }

        if let http = response as? HTTPURLResponse {
            if http.statusCode == 401 { authSession?.logout(); throw APIError.unauthorized }
            if !(200..<300).contains(http.statusCode) {
                throw APIError.serverError(ErrorDetails.response(data, status: http.statusCode))
            }
        }
        do { return try decoder.decode(LabelCaptureResponse.self, from: data) }
        catch { logDecode(error, data: data, response: response, request: request)
            throw APIError.decodingError(error) }
    }

    // MARK: - Equipment plate (Otto → Add equipment)

    /// Uploads a photo of a machine's model/serial plate (+ optionally the machine) to
    /// /api/team/equipment-mobile mode=plate. Nothing is added yet: the server reads the plate and
    /// returns a prefilled form; the app saves it with `.equipmentAction` mode=save.
    func uploadPlate(plateData: Data, photoData: Data?) async throws -> PlateReadResponse {
        guard let url = APIEndpoint.equipmentAction.url else { throw APIError.invalidURL }

        let boundary = "MwBoundary-\(UUID().uuidString.replacingOccurrences(of: "-", with: ""))"
        var request  = URLRequest(url: url)
        request.httpMethod = "POST"
        request.timeoutInterval = 60
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        if let token = authSession?.token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        var body = Data()
        body.appendField(name: "mode", value: "plate", boundary: boundary)
        body.appendField(name: "plate_photo", filename: "plate.jpg", mimeType: "image/jpeg", data: plateData, boundary: boundary)
        if let photoData {
            body.appendField(name: "machine_photo", filename: "machine.jpg", mimeType: "image/jpeg", data: photoData, boundary: boundary)
        }
        body.append("--\(boundary)--\r\n".data(using: .utf8)!)
        request.httpBody = body

        let data: Data
        let response: URLResponse
        do { (data, response) = try await execute(request) }
        catch { throw APIError.networkError(error) }

        if let http = response as? HTTPURLResponse {
            if http.statusCode == 401 { authSession?.logout(); throw APIError.unauthorized }
            if !(200..<300).contains(http.statusCode) {
                throw APIError.serverError(ErrorDetails.response(data, status: http.statusCode))
            }
        }
        do { return try decoder.decode(PlateReadResponse.self, from: data) }
        catch { logDecode(error, data: data, response: response, request: request)
            throw APIError.decodingError(error) }
    }

    // MARK: - Job Photo Upload

    /// Uploads a single before/after job photo for a visit.
    @discardableResult
    func uploadJobPhoto(imageData: Data, visitId: Int, photoType: JobPhotoType, queuedAt: Date? = nil) async throws -> [String: Any] {
        try await uploadVisitPhoto(imageData: imageData, visitId: visitId, photoTypeRaw: photoType.rawValue, queuedAt: queuedAt)
    }

    /// Upload a visit photo under any of the server's photo_type values.
    ///
    /// Recommendation photos go up as "issue" — deliberately not before/after,
    /// which the server treats as implied visit start/completion timestamps.
    /// The returned dictionary carries `media_id`, which is what links a photo to
    /// a recommendation.
    @discardableResult
    /// `queuedAt` = when a queued photo was taken; the server's special-request gate accepts a
    /// queued photo (logged) instead of refusing it forever. Nil for a live upload.
    func uploadVisitPhoto(imageData: Data, visitId: Int, photoTypeRaw: String, queuedAt: Date? = nil) async throws -> [String: Any] {
        guard let url = APIEndpoint.scheduleJobPhoto.url else {
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
        body.appendField(name: "photo", filename: "photo.jpg", mimeType: "image/jpeg",
                         data: imageData, boundary: boundary)
        body.appendField(name: "visit_id",   value: "\(visitId)",       boundary: boundary)
        body.appendField(name: "photo_type", value: photoTypeRaw,         boundary: boundary)
        if let queuedAt {
            body.appendField(name: "queued_at", value: "\(Int(queuedAt.timeIntervalSince1970 * 1000))", boundary: boundary)
        }
        body.append("--\(boundary)--\r\n".data(using: .utf8)!)
        request.httpBody = body

        let data: Data
        let response: URLResponse
        do { (data, response) = try await execute(request) }
        catch { throw APIError.networkError(error) }

        if let http = response as? HTTPURLResponse {
            if http.statusCode == 401 { authSession?.logout(); throw APIError.unauthorized }
            if !(200..<300).contains(http.statusCode) {
                let msg = ErrorDetails.response(data, status: http.statusCode)
                // 5xx / timeout / rate limit: the server did not say no — keep the photo and retry.
                if UploadFault.isTransient(http.statusCode) {
                    throw APIError.networkError(UploadFault(status: http.statusCode, detail: msg))
                }
                // Any other 4xx is the server refusing this photo (visit gone, not your visit).
                throw APIError.serverError(msg)
            }
        }

        // A PHP fatal on production is a truncated body with HTTP 200. Only a readable
        // {"success": true} counts as stored; anything else is retried, never counted uploaded.
        let json: [String: Any]
        do {
            let value = try JSONSerialization.jsonObject(with: data)
            guard let object = value as? [String: Any] else {
                throw NSError(domain: "ca.mowology.response", code: 200,
                              userInfo: [NSLocalizedDescriptionKey: "Visit photo response expected a JSON object; received \(type(of: value))."])
            }
            json = object
        } catch {
            logDecode(error, data: data, response: response, request: request)
            throw APIError.networkError(UploadFault(status: 200, detail: ErrorDetails.describe(error)))
        }
        if let ok = json["success"] as? Bool, ok == false {
            throw APIError.serverError(ErrorDetails.response(data, status: 200))
        }
        guard json["success"] as? Bool == true else {
            throw APIError.networkError(UploadFault(status: 200, detail: "Visit photo response did not contain success=true; received keys: \(json.keys.sorted().joined(separator: ", "))."))
        }
        return json
    }

    // MARK: - Private Helpers

    /// One instrumented transport for generic requests and every multipart upload.
    /// Log failures only; never headers, request bodies, photos, or GPS coordinates.
    /// A READ (GET) that the server turns away as busy (HTTP 508 resource limit, 503, 429, 502/504) is tried again by
    /// itself — after the server's Retry-After, else ~1.5 s then ~4 s — before anyone sees an error (2026-10-20: the
    /// morning burst of crew phones hit the host's limit and the schedule showed the raw 508 page). Writes are never
    /// repeated here: their callers own retrying (queues with idempotency keys).
    private func executeRetryingBusy(_ request: URLRequest) async throws -> (Data, URLResponse) {
        let isRead = (request.httpMethod ?? "GET").uppercased() == "GET"
        var result = try await execute(request)
        guard isRead else { return result }
        for delay in [1.5, 4.0] {
            guard let http = result.1 as? HTTPURLResponse, ErrorDetails.isBusy(http.statusCode) else { return result }
            let asked = (http.value(forHTTPHeaderField: "Retry-After")).flatMap(Double.init) ?? delay
            let wait = min(max(asked, 0.5), 8.0) + Double.random(in: 0...0.4)
            try await Task.sleep(nanoseconds: UInt64(wait * 1_000_000_000))
            result = try await execute(request)
        }
        return result
    }

    private func execute(_ request: URLRequest) async throws -> (Data, URLResponse) {
        let label = ErrorDetails.request(request)
        do {
            let result = try await session.data(for: request)
            if let http = result.1 as? HTTPURLResponse {
                let json = (try? JSONSerialization.jsonObject(with: result.0)) as? [String: Any]
                let rejected = (json?["ok"] as? Bool == false) || (json?["success"] as? Bool == false)
                if !(200..<300).contains(http.statusCode) || rejected {
                    AppDiagnostics.shared.record(category: "http.error", message: "\(label) — \(ErrorDetails.response(result.0, status: http.statusCode))")
                }
            }
            return result
        } catch {
            AppDiagnostics.shared.record(category: "transport.error", message: "\(label) — \(ErrorDetails.describe(error))")
            throw error
        }
    }

    private func logDecode(_ error: Error, data: Data, response: URLResponse, request: URLRequest) {
        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        let detail = "\(ErrorDetails.request(request)) — content-type=\(response.mimeType ?? "absent"), bytes=\(data.count); \(ErrorDetails.describe(error)); \(ErrorDetails.response(data, status: status))"
        AppDiagnostics.shared.record(category: "response.decode", message: detail)
    }

}

// MARK: - Upload fault

/// The server answered but did not decide: 5xx, timeout, rate limit, or a body it could not
/// finish (PHP fatal → truncated HTTP 200). Thrown wrapped in `APIError.networkError`, so every
/// caller that queues on a network error keeps the item; queues check for this type to back off
/// that one item instead of treating the phone as offline.
struct UploadFault: LocalizedError {
    let status: Int
    let detail: String

    var errorDescription: String? { "Server problem (\(status)): \(detail)" }

    static func isTransient(_ status: Int) -> Bool {
        status >= 500 || status == 408 || status == 425 || status == 429
    }

    /// True when `error` is a server fault rather than no signal.
    static func matches(_ error: Error) -> Bool {
        if case APIError.networkError(let inner)? = error as? APIError { return inner is UploadFault }
        return false
    }
}

// MARK: - Data multipart helpers

private extension Data {
    mutating func appendField(name: String, filename: String, mimeType: String, data: Data, boundary: String) {
        append("--\(boundary)\r\n".data(using: .utf8)!)
        append("Content-Disposition: form-data; name=\"\(name)\"; filename=\"\(filename)\"\r\n".data(using: .utf8)!)
        append("Content-Type: \(mimeType)\r\n\r\n".data(using: .utf8)!)
        append(data)
        append("\r\n".data(using: .utf8)!)
    }

    mutating func appendField(name: String, value: String, boundary: String) {
        append("--\(boundary)\r\n".data(using: .utf8)!)
        append("Content-Disposition: form-data; name=\"\(name)\"\r\n\r\n".data(using: .utf8)!)
        append(value.data(using: .utf8)!)
        append("\r\n".data(using: .utf8)!)
    }
}
