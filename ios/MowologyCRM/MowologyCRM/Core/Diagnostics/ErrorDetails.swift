import Foundation

/// Preserve the actual failure rather than replacing it with an assumed cause.
enum ErrorDetails {
    static func describe(_ error: Error) -> String {
        if let api = error as? APIError { return api.localizedDescription }
        if let decoding = error as? DecodingError {
            let kind: String
            let context: DecodingError.Context
            switch decoding {
            case .keyNotFound(let key, let value): kind = "Missing key '\(key.stringValue)'"; context = value
            case .typeMismatch(let type, let value): kind = "Expected \(type)"; context = value
            case .valueNotFound(let type, let value): kind = "Missing \(type) value"; context = value
            case .dataCorrupted(let value): kind = "Invalid response data"; context = value
            @unknown default: return String(reflecting: decoding)
            }
            let path = context.codingPath.map { $0.intValue.map(String.init) ?? $0.stringValue }.joined(separator: ".")
            let underlying = context.underlyingError.map { " Underlying: \(describe($0))" } ?? ""
            return AppDiagnostics.redacted("\(kind) at \(path.isEmpty ? "$" : path): \(context.debugDescription)\(underlying)")
        }
        let ns = error as NSError
        return AppDiagnostics.redacted("\(ns.domain) (\(ns.code)): \(ns.localizedDescription)" +
            (ns.localizedFailureReason.map { " Reason: \($0)" } ?? "") +
            (ns.localizedRecoverySuggestion.map { " Recovery: \($0)" } ?? ""))
    }

    static func request(_ request: URLRequest) -> String {
        guard let url = request.url, var parts = URLComponents(url: url, resolvingAgainstBaseURL: false) else {
            return "\(request.httpMethod ?? "GET") [invalid URL]"
        }
        parts.user = nil; parts.password = nil; parts.fragment = nil
        parts.queryItems = parts.queryItems?.filter { ["mode", "action"].contains($0.name.lowercased()) }
        return "\(request.httpMethod ?? "GET") \(parts.string ?? url.path)"
    }

    /// Busy / down answers in plain words.
    static func friendly(status: Int) -> String {
        switch status {
        case 429, 503, 508: return "The server is busy right now — pull down to try again in a moment."
        case 502, 504: return "The server didn't answer in time — try again in a moment."
        case 500...599: return "The server had a problem — try again in a moment."
        default: return "Unexpected answer from the server."
        }
    }

    /// The statuses that mean "busy, try again shortly" — a read can be retried by itself.
    static func isBusy(_ status: Int) -> Bool { [429, 502, 503, 504, 508].contains(status) }

    static func response(_ data: Data, status: Int) -> String {
        if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
            let message = (json["error"] as? String) ?? (json["message"] as? String)
            if let message, !message.isEmpty {
                let code = json["code"].map { " [code: \($0)]" } ?? ""
                return AppDiagnostics.redacted("HTTP \(status)\(code): \(message)")
            }
        }
        let limit = 4096
        let body = String(decoding: data.prefix(limit), as: UTF8.self)
        // A host's HTML error page ("508 Resource Limit Is Reached") is not something to show anyone (2026-10-20).
        let head = body.prefix(200).lowercased()
        if head.contains("<!doctype") || head.contains("<html") {
            return AppDiagnostics.redacted("HTTP \(status): " + friendly(status: status))
        }
        return AppDiagnostics.redacted("HTTP \(status): \(data.isEmpty ? "empty response body" : body)" +
            (data.count > limit ? " [truncated after \(limit) of \(data.count) bytes]" : ""))
    }
}
