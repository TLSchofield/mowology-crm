// Bounded, redacted diagnostics. Callers supply event summaries, never request headers or bodies.
import Foundation

struct DiagnosticsSnapshot: Sendable {
    let text: String
    let url: URL
}

final class AppDiagnostics: @unchecked Sendable {
    static let shared = AppDiagnostics(directory: FileManager.default.urls(for: .applicationSupportDirectory,
                                                                          in: .userDomainMask)[0]
        .appendingPathComponent("Diagnostics", isDirectory: true))

    private let directory: URL
    private let maxBytes: Int
    private let queue = DispatchQueue(label: "ca.mowology.diagnostics", qos: .utility)
    // All mutable state and disk access are confined to queue.
    private var lastFailure: NSError?
    private let files = FileManager.default

    init(directory: URL, maxBytes: Int = 1_048_576) {
        self.directory = directory
        self.maxBytes = max(256, maxBytes)
    }

    func record(category: String, message: String) {
        queue.async { [self] in
            do {
                try prepareDirectory()
                let timestamp = ISO8601DateFormatter().string(from: Date())
                let raw = "\(timestamp) [\(Self.redacted(category))] \(Self.redacted(message))"
                    .replacingOccurrences(of: "\n", with: " ")
                    .replacingOccurrences(of: "\r", with: " ")
                let originalBytes = raw.utf8.count
                let line: String
                if originalBytes + 1 > maxBytes {
                    let marker = " [truncated: original \(originalBytes) bytes]"
                    // Leave room for UTF-8 boundary replacement and the final newline.
                    let prefix = String(decoding: raw.utf8.prefix(maxBytes - marker.utf8.count - 4), as: UTF8.self)
                    line = prefix + marker + "\n"
                } else { line = raw + "\n" }
                let data = Data(line.utf8)
                let active = logURL(0)
                let existing: Int
                if files.fileExists(atPath: active.path) {
                    existing = try files.attributesOfItem(atPath: active.path)[.size] as? Int ?? 0
                } else { existing = 0 }
                if existing + data.count > maxBytes { try rotate() }
                if !files.fileExists(atPath: active.path) {
                    try Data().write(to: active, options: .atomic)
                    try protect(active)
                }
                let handle = try FileHandle(forWritingTo: active)
                do {
                    try handle.seekToEnd()
                    try handle.write(contentsOf: data)
                    try handle.close()
                } catch {
                    do { try handle.close() }
                    catch { report(error, operation: "close diagnostic log") }
                    throw error
                }
            } catch { report(error, operation: "append diagnostic event") }
        }
    }

    func snapshot() async throws -> DiagnosticsSnapshot {
        try await withCheckedThrowingContinuation { continuation in
            queue.async { [self] in
                do {
                    try prepareDirectory()
                    var parts = ["Mowology diagnostics — \(ISO8601DateFormatter().string(from: Date()))"]
                    if let lastFailure {
                        parts.append("Previous log-storage failure: \(lastFailure.localizedDescription)")
                    }
                    for index in [2, 1, 0] {
                        let url = logURL(index)
                        if files.fileExists(atPath: url.path) {
                            let text = try String(contentsOf: url, encoding: .utf8)
                            parts.append("--- \(url.lastPathComponent) ---\n" + Self.redacted(text))
                        }
                    }
                    let text = parts.joined(separator: "\n\n")
                    let export = directory.appendingPathComponent("diagnostics-export.txt")
                    try Data(text.utf8).write(to: export, options: .atomic)
                    try protect(export)
                    continuation.resume(returning: DiagnosticsSnapshot(text: text, url: export))
                } catch {
                    let previous = lastFailure
                    report(error, operation: "export diagnostic log")
                    continuation.resume(throwing: previous ?? lastFailure ?? error as NSError)
                }
            }
        }
    }

    private func logURL(_ index: Int) -> URL {
        directory.appendingPathComponent(index == 0 ? "diagnostics.log" : "diagnostics.\(index).log")
    }

    private func prepareDirectory() throws {
        try files.createDirectory(at: directory, withIntermediateDirectories: true)
        try protect(directory)
    }

    private func protect(_ url: URL) throws {
        var url = url
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try url.setResourceValues(values)
        #if os(iOS)
        try files.setAttributes([.protectionKey: FileProtectionType.completeUntilFirstUserAuthentication],
                                ofItemAtPath: url.path)
        #endif
    }

    private func rotate() throws {
        let oldest = logURL(2)
        if files.fileExists(atPath: oldest.path) { try files.removeItem(at: oldest) }
        for index in [1, 0] {
            let source = logURL(index)
            if files.fileExists(atPath: source.path) { try files.moveItem(at: source, to: logURL(index + 1)) }
        }
    }

    private func report(_ error: Error, operation: String) {
        let source = error as NSError
        let description = Self.redacted("Could not \(operation): \(source.domain) (\(source.code)): \(source.localizedDescription)")
        // Preserve the first failed operation across refresh attempts; still print
        // every subsequent failure, and allow snapshot recovery when storage returns.
        if lastFailure == nil {
            lastFailure = NSError(domain: "ca.mowology.diagnostics", code: source.code,
                                  userInfo: [NSLocalizedDescriptionKey: description])
        }
        NSLog("%@", description)
    }

    static func redacted(_ text: String) -> String {
        var result = text
        func replace(_ pattern: String, _ template: String) {
            // Patterns are constants; a programming error must not result in unsafe logging.
            guard let regex = try? NSRegularExpression(pattern: pattern) else {
                result = "[Diagnostic redaction unavailable]"
                return
            }
            result = regex.stringByReplacingMatches(in: result, range: NSRange(result.startIndex..., in: result),
                                                     withTemplate: template)
        }
        // Remove URL credentials, fragments and all query values except known routing selectors.
        if let regex = try? NSRegularExpression(pattern: #"(?i)https?://[^\s<>"']+|/[A-Za-z0-9_.~%/-]+\?[^\s<>"']+"#) {
            for match in regex.matches(in: result, range: NSRange(result.startIndex..., in: result)).reversed() {
                guard let range = Range(match.range, in: result) else { continue }
                guard var parts = URLComponents(string: String(result[range])) else {
                    result.replaceSubrange(range, with: "[Redacted URL]")
                    continue
                }
                parts.user = nil; parts.password = nil; parts.fragment = nil
                parts.queryItems = parts.queryItems?.filter {
                    ["action", "mode"].contains($0.name.lowercased()) &&
                    ($0.value ?? "").range(of: #"^[A-Za-z0-9_-]{0,64}$"#, options: .regularExpression) != nil
                }
                if parts.queryItems?.isEmpty == true { parts.queryItems = nil }
                result.replaceSubrange(range, with: parts.string ?? "[Redacted URL]")
            }
        } else { return "[Diagnostic redaction unavailable]" }
        replace(#"(?im)\b(?:cookie|set-cookie)\s*:\s*[^\r\n]*"#, "Cookie: [REDACTED]")
        replace(#"(?i)\bAuthorization\s*[:=]\s*(?:Basic|Bearer)\s+[^\s,;"']+"#, "Authorization: [REDACTED]")
        replace(#"(?i)\bBearer\s+[^\s,;"']+"#, "Bearer [REDACTED]")
        replace(#"\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b"#, "[REDACTED JWT]")
        let key = #"(?:token|(?:access|refresh|id|auth)[_-]?token|password|(?:client[_-]?)?secret|(?:api|auth)[_-]?key|authorization|cookie|set[_-]?cookie|csrf(?:[_-]?token)?|session(?:[_-]?(?:id|token|cookie))?|PHPSESSID)"#
        replace(#"(?i)(""# + key + #""\s*:\s*)(?:"(?:\\.|[^"\\])*"|[^,\s}\]]+)"#, "$1\"[REDACTED]\"")
        replace(#"(?i)\b("# + key + #")\s*[=:]\s*[^\s,;&]+"#, "$1=[REDACTED]")
        return result
    }
}
