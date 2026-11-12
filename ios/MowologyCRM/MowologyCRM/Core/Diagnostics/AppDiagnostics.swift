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
                    var parts = ["Ittai diagnostics — \(ISO8601DateFormatter().string(from: Date()))"]
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

/// Lets a repeating event into the log a bounded number of times, and says how many were held back, so a loop that
/// fires 12 times a second leaves a few lines and a count, not 5,000 lines (2026-11-11: cancelled `mode=brains`
/// requests filled the log and made the Diagnostic log screen heavy).
final class LogThrottle: @unchecked Sendable {
    static let shared = LogThrottle()
    private let lock = NSLock()
    private var windows: [String: (start: Date, admitted: Int, held: Int)] = [:]

    /// `admitted`: write this one. `heldBefore`: how many of the previous window's events were not written
    /// (non-zero only on the first admitted event of a new window).
    func admit(_ key: String, limit: Int, interval: TimeInterval, now: Date = Date()) -> (admitted: Bool, heldBefore: Int, firstHeld: Bool) {
        lock.lock(); defer { lock.unlock() }
        // Request labels can carry record ids: drop windows that have expired so the table stays small.
        if windows.count > 200 { windows = windows.filter { now.timeIntervalSince($0.value.start) < interval } }
        var w = windows[key] ?? (now, 0, 0)
        var heldBefore = 0
        if now.timeIntervalSince(w.start) >= interval { heldBefore = w.held; w = (now, 0, 0) }
        let admitted = w.admitted < limit
        if admitted { w.admitted += 1 } else { w.held += 1 }
        windows[key] = w
        return (admitted, heldBefore, !admitted && w.held == 1)
    }
}

extension AppDiagnostics {
    /// A cancelled request is the app cancelling its own work (a screen left, a task restarted), not a failure. It is
    /// still recorded (a loop of them is the clue), but at most 3 per request per minute, with the count held back.
    func recordCancelled(label: String, detail: String) {
        let r = LogThrottle.shared.admit("cancel " + label, limit: 3, interval: 60)
        guard r.admitted else {
            // The count above only reaches the log if another one arrives after the window; say now that some are held.
            if r.firstHeld { record(category: "transport.cancelled", message: "\(label) — more cancellations in the last minute are not logged one by one") }
            return
        }
        let more = r.heldBefore > 0 ? " (\(r.heldBefore) more cancelled in the last minute)" : ""
        record(category: "transport.cancelled", message: "\(label) — \(detail)\(more)")
    }

    /// What the owner just pressed (tab, Add dial), kept in the log so a freeze names its last taps. At most 60 a minute.
    func crumb(_ what: String) {
        let r = LogThrottle.shared.admit("crumb", limit: 60, interval: 60)
        guard r.admitted else { return }
        record(category: "nav", message: what + (r.heldBefore > 0 ? " (\(r.heldBefore) not logged in the last minute)" : ""))
    }
}

/// Tripwire for redraw loops. The app ran the phone at ~98% CPU for minutes at a time (cpu_resource reports from
/// 1.3.64 on, 2026-11-03/04) with no app code on the hot stack, and this Xcode cannot profile the iPhone. A screen
/// that calls `tick` from its body gets one diagnostic-log line, at most once a minute, when it redraws more than
/// 10 times in 2 seconds (it was 40; a 12-a-second loop on Kaname never reached that, 2026-11-11), so the next report names the screen. Call as `let _ = RenderRate.tick("ReceiptsView")`.
@MainActor
enum RenderRate {
    private static var windows: [String: (start: Date, count: Int)] = [:]
    private static var lastReport: [String: Date] = [:]

    static func tick(_ screen: String) {
        let now = Date()
        var w = windows[screen] ?? (now, 0)
        if now.timeIntervalSince(w.start) > 2 { w = (now, 0) }
        w.count += 1
        windows[screen] = w
        guard w.count == 11, now.timeIntervalSince(lastReport[screen] ?? .distantPast) > 60 else { return }
        lastReport[screen] = now
        AppDiagnostics.shared.record(category: "RenderRate",
                                     message: "\(screen) redrew more than 10 times in 2 s — a redraw loop")
    }
}

/// The log as the Diagnostic log screen shows it: one row per entry, newest first, long entries cut.
/// One Apple crash payload is a single line of up to a megabyte; laid out as one text view it hangs or crashes SwiftUI
/// (2026-11-11), so only the start of such a line is drawn. The export file keeps everything.
enum DiagnosticsDisplay {
    struct Entry: Equatable, Identifiable {
        /// The entry's line number in the exported text (stable between refreshes of the same log).
        let id: Int
        let text: String
        /// How many characters of the entry are not drawn (0 when it fits).
        let omittedCharacters: Int
    }

    /// Rows for the newest `maxEntries` non-empty lines of `text`, newest first, each cut to `maxCharacters`.
    static func entries(from text: String, maxEntries: Int = 200, maxCharacters: Int = 600) -> [Entry] {
        var numbered: [(Int, Substring)] = []
        var number = 0
        for line in text.split(separator: "\n", omittingEmptySubsequences: true) {
            number += 1
            numbered.append((number, line))
        }
        return numbered.suffix(max(0, maxEntries)).reversed().map { number, line in
            let shown = line.prefix(max(1, maxCharacters))
            return Entry(id: number, text: String(shown), omittedCharacters: line.count - shown.count)
        }
    }
}
