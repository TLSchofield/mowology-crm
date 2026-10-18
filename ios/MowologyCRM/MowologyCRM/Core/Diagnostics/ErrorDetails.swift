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
        return AppDiagnostics.redacted("HTTP \(status): \(data.isEmpty ? "empty response body" : body)" +
            (data.count > limit ? " [truncated after \(limit) of \(data.count) bytes]" : ""))
    }
}
