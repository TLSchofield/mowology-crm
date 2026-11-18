import Foundation

/// What the phone tells the server (docs/plans/2026-11-15-app-vitals-automatic.md): the wire event, the rules that turn a
/// diagnostics-log line into one, and the offline outbox. Foundation only, so the Mac logic tests can compile it.
struct VitalsEvent: Codable, Equatable {
    struct SignatureParts: Codable, Equatable {
        var cause: String
        var frame: String
        var binaryUUID: String?
        enum CodingKeys: String, CodingKey { case cause, frame, binaryUUID = "binary_uuid" }
    }
    var id: String
    var at: String                     // ISO 8601 UTC
    var kind: String
    var severity: String               // info | warn | error
    var message: String
    var signatureParts: SignatureParts?
    var breadcrumbs: [String]?
    enum CodingKeys: String, CodingKey { case id, at, kind, severity, message, signatureParts = "signature_parts", breadcrumbs }
}

enum VitalsClassifier {
    /// Log categories worth a look; the uploader checks this before it redacts or classifies anything.
    static let categories: Set<String> = ["apple.diagnostic", "apple.metrics", "HangWatchdog", "CrashLoopBreaker", "vitals.unclean_exit"]
    static let maxMessage = 600

    private static let diagnosticKinds: [(prefix: String, kind: String, severity: String)] = [
        ("CRASH:", "crash", "error"), ("CPU EXCEPTION:", "cpu_exception", "warn"), ("HANG:", "hang", "warn"),
        ("SLOW LAUNCH:", "slow_launch", "warn"), ("DISK WRITES:", "disk_writes", "warn")]

    /// nil for log lines that are not the server's business (and for MetricKit lines that carry no diagnostic).
    static func event(category: String, message: String, at: Date, id: String, previousRunBreadcrumbs: [String] = []) -> VitalsEvent? {
        let stamp = isoFormatter.string(from: at)
        func make(_ kind: String, _ severity: String, _ text: String, cause: String = "", frame: String = "", uuid: String? = nil,
                  crumbs: [String]? = nil) -> VitalsEvent {
            VitalsEvent(id: id, at: stamp, kind: kind, severity: severity, message: String(text.prefix(maxMessage)),
                        signatureParts: (cause.isEmpty && frame.isEmpty) ? nil : .init(cause: cause, frame: frame, binaryUUID: uuid),
                        breadcrumbs: (crumbs?.isEmpty ?? true) ? nil : crumbs)
        }
        switch category {
        case "apple.diagnostic":
            guard let hit = diagnosticKinds.first(where: { message.hasPrefix($0.prefix) }) else { return nil }
            let frame = appFrame(in: message)
            // A crash's cause is the same everywhere ("scene-update watchdog …"); for the rest the top frame names the issue.
            let cause = hit.kind == "crash" ? segment(message, 1) : ""
            return make(hit.kind, hit.severity, message, cause: cause, frame: frame?.name ?? "", uuid: frame?.uuid)
        case "apple.metrics":
            return make("daily_metrics", "info", message)
        case "HangWatchdog":
            if message.hasPrefix("Main thread not responding") {
                let trail = trailOf(message)
                return make("watchdog", "error", message, cause: "main thread stall", frame: trail.last ?? "", crumbs: trail)
            }
            if message.hasPrefix("Main thread stalled") { return make("stall_end", "info", message) }
            return nil
        case "CrashLoopBreaker":
            return make("unclean_exit", "error", message, cause: "crash loop breaker tripped")
        case "vitals.unclean_exit":
            return make("unclean_exit", "warn", message, cause: "ended while in the foreground",
                        frame: previousRunBreadcrumbs.last ?? "", crumbs: previousRunBreadcrumbs)
        default:
            return nil
        }
    }

    /// First `MowologyCRM+0x…[uuid8]` in a summary line.
    static func appFrame(in line: String) -> (name: String, uuid: String)? {
        guard let regex = try? NSRegularExpression(pattern: "(MowologyCRM\\+0x[0-9a-fA-F]+)\\[([0-9A-Fa-f]+)\\]"),
              let m = regex.firstMatch(in: line, range: NSRange(line.startIndex..., in: line)),
              let a = Range(m.range(at: 1), in: line), let b = Range(m.range(at: 2), in: line) else { return nil }
        return (String(line[a]), String(line[b]))
    }

    private static func segment(_ line: String, _ index: Int) -> String {
        let parts = line.components(separatedBy: " · ")
        return index < parts.count ? parts[index].trimmingCharacters(in: .whitespaces) : ""
    }

    /// "…Last navigation: a | b | c" → ["a","b","c"]; the watchdog's "(no navigation breadcrumbs)" → [].
    static func trailOf(_ message: String) -> [String] {
        guard let r = message.range(of: "Last navigation: ") else { return [] }
        let trail = String(message[r.upperBound...])
        if trail.hasPrefix("(no navigation") { return [] }
        return trail.components(separatedBy: " | ").map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }
    }

    static let isoFormatter: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f
    }()
}

/// Events waiting for the server. Survives relaunch (Codable); a flood is bounded; failures back off.
struct VitalsOutbox: Codable, Equatable {
    static let maxEvents = 500
    static let batchEvents = 50
    static let batchBytes = 60_000        // the server refuses 64 KB

    private(set) var events: [VitalsEvent] = []
    private(set) var failures = 0
    private(set) var retryAfter: Date?

    mutating func add(_ event: VitalsEvent) {
        guard !events.contains(where: { $0.id == event.id }) else { return }
        events.append(event)
        if events.count > Self.maxEvents { events.removeFirst(events.count - Self.maxEvents) }
    }

    /// The next batch, oldest first, within the event and byte limits; empty while backing off.
    func nextBatch(now: Date) -> [VitalsEvent] {
        if let retryAfter, now < retryAfter { return [] }
        var batch: [VitalsEvent] = []
        var bytes = 0
        let encoder = JSONEncoder()
        for event in events {
            let size = (try? encoder.encode(event).count) ?? Self.batchBytes
            if !batch.isEmpty && (batch.count >= Self.batchEvents || bytes + size > Self.batchBytes) { break }
            batch.append(event)
            bytes += size
        }
        return batch
    }

    mutating func sent(_ ids: [String]) {
        let gone = Set(ids)
        events.removeAll { gone.contains($0.id) }
        failures = 0
        retryAfter = nil
    }

    /// Network or server trouble: keep the events, wait 30 s, 60 s, 2 min … at most an hour.
    mutating func failed(now: Date) {
        failures += 1
        retryAfter = now.addingTimeInterval(min(3600, 30 * pow(2, Double(min(failures - 1, 7)))))
    }

    /// The server answered "no" to this batch itself (bad request, too big): retrying cannot help, so drop it.
    mutating func rejected(_ ids: [String]) { sent(ids) }
}
