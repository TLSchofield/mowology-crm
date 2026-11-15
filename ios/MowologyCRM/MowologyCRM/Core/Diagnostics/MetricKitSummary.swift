import Foundation

/// Turns MetricKit payload JSON into one readable log line each. Foundation only, so the
/// Mac logic tests can compile it. The frames Apple sends are unsymbolicated (binary name +
/// offset), so the summary names the offsets: `atos -o <MowologyCRM dSYM> -l <load address> <offset>`.
enum MetricKitSummary {
    /// Daily `MXMetricPayload` numbers, already converted to plain units by the caller.
    struct DailyMetrics: Equatable {
        var launchMedianMs: Double?
        var resumeMedianMs: Double?
        var hitchMsPerSecond: Double?
        var peakMemoryMB: Double?
        var cpuSeconds: Double?
        var gpuSeconds: Double?
        var locationSeconds: Double?
        var foregroundSeconds: Double?
        var cellularUploadMB: Double?
        var cellularDownloadMB: Double?
    }

    static let appBinary = "MowologyCRM"

    // MARK: Diagnostics (crash, CPU exception, hang, launch, disk write)

    /// One line per diagnostic inside the payload; a payload that cannot be parsed still gives one line.
    static func diagnosticLines(_ data: Data) -> [String] {
        guard let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            return ["unreadable payload (\(data.count) bytes, not valid JSON)"]
        }
        let window = (root["timeStampBegin"] as? String).map { " window \($0)" + ((root["timeStampEnd"] as? String).map { "–\($0)" } ?? "") } ?? ""
        var lines: [String] = []
        let kinds: [(key: String, title: String)] = [
            ("crashDiagnostics", "CRASH"), ("cpuExceptionDiagnostics", "CPU EXCEPTION"),
            ("hangDiagnostics", "HANG"), ("appLaunchDiagnostics", "SLOW LAUNCH"),
            ("diskWriteExceptionDiagnostics", "DISK WRITES")]
        for kind in kinds {
            for item in root[kind.key] as? [[String: Any]] ?? [] {
                lines.append(kind.title + ": " + describe(item, kind: kind.key) + window)
            }
        }
        if lines.isEmpty {
            lines.append("no diagnostics inside payload (keys: \(root.keys.sorted().joined(separator: ",")))" + window)
        }
        return lines
    }

    private static func describe(_ item: [String: Any], kind: String) -> String {
        let meta = item["diagnosticMetaData"] as? [String: Any] ?? [:]
        func text(_ key: String) -> String? {
            if let s = meta[key] as? String { return s }
            if let n = meta[key] as? NSNumber { return n.stringValue }
            return nil
        }
        var parts: [String] = []
        parts.append("v\(text("appVersion") ?? "?")(\(text("appBuildVersion") ?? "?"))"
            + " \(text("deviceType") ?? "?") \(text("osVersion") ?? "?")")
        switch kind {
        case "cpuExceptionDiagnostics":
            parts.append("cpu \(text("totalCPUTime") ?? "?") of \(text("totalSampledTime") ?? "?") sampled")
        case "hangDiagnostics":
            parts.append("hang \(text("hangDuration") ?? "?")")
        case "appLaunchDiagnostics":
            parts.append("launch \(text("launchDuration") ?? "?")")
        case "diskWriteExceptionDiagnostics":
            parts.append("writes \(text("writesCaused") ?? text("totalWritesCaused") ?? "?")")
        default:
            parts.append(crashCause(meta: meta))
        }
        if text("lowPowerModeEnabled") == "1" { parts.append("low power mode on") }
        if let tree = item["callStackTree"] as? [String: Any] {
            parts.append(frameSummary(tree, crashedOnly: kind == "crashDiagnostics"))
        }
        return parts.joined(separator: " · ")
    }

    /// "scene-update watchdog (0x8BADF00D, 10.00 s wall clock)", or signal/exception when it is not a watchdog.
    static func crashCause(meta: [String: Any]) -> String {
        var cause: [String] = []
        if let reason = meta["terminationReason"] as? String, !reason.isEmpty {
            let code = firstMatch(in: reason, pattern: "code:(0x[0-9A-Fa-f]+)")
            let explanation = firstMatch(in: reason, pattern: "explanation:([^\\n]*)")
            let event = firstMatch(in: reason, pattern: "WatchdogEvent: ([^\\n]*)")
            var s = ""
            if let event = event { s += "\(event) watchdog" } else if let explanation = explanation { s += explanation }
            let seconds = firstMatch(in: reason, pattern: "allowance of ([0-9.]+) seconds")
            let detail = [code, seconds.map { "\($0) s allowance" }].compactMap { $0 }.joined(separator: ", ")
            if !detail.isEmpty { s += " (\(detail))" }
            if s.isEmpty { s = String(reason.prefix(120)) }
            cause.append(s)
        }
        if let signal = meta["signal"] as? NSNumber { cause.append("signal \(signal)") }
        if let type = meta["exceptionType"] as? NSNumber { cause.append("exception type \(type)") }
        return cause.isEmpty ? "cause not given" : cause.joined(separator: ", ")
    }

    private static func firstMatch(in text: String, pattern: String) -> String? {
        guard let regex = try? NSRegularExpression(pattern: pattern),
              let m = regex.firstMatch(in: text, range: NSRange(text.startIndex..., in: text)),
              m.numberOfRanges > 1, let r = Range(m.range(at: 1), in: text) else { return nil }
        return String(text[r]).trimmingCharacters(in: .whitespaces)
    }

    // MARK: Call stacks

    /// Where the samples sit: share by binary (leaf time), then the app frames that called out most.
    /// `crashedOnly` limits a crash to the thread Apple attributes it to.
    static func frameSummary(_ tree: [String: Any], crashedOnly: Bool) -> String {
        var stacks = tree["callStacks"] as? [[String: Any]] ?? []
        if crashedOnly {
            let attributed = stacks.filter { ($0["threadAttributed"] as? Bool) == true }
            if !attributed.isEmpty { stacks = attributed }
        }
        var selfByBinary: [String: Int] = [:]
        var byAppFrame: [String: Int] = [:]
        var total = 0
        // Iterative walk: payload trees can be thousands of frames deep.
        for stack in stacks {
            var work: [([String: Any], String?)] = (stack["callStackRootFrames"] as? [[String: Any]] ?? []).map { ($0, nil) }
            for (frame, _) in work { total += (frame["sampleCount"] as? Int) ?? 0 }
            while let (frame, lastApp) = work.popLast() {
                let name = frame["binaryName"] as? String ?? "?"
                let count = (frame["sampleCount"] as? Int) ?? 0
                let children = frame["subFrames"] as? [[String: Any]] ?? []
                var app = lastApp
                if name == appBinary {
                    let uuid = String((frame["binaryUUID"] as? String ?? "?").prefix(8))
                    let offset = (frame["offsetIntoBinaryTextSegment"] as? Int).map { "0x" + String($0, radix: 16) } ?? "?"
                    app = "\(name)+\(offset)[\(uuid)]"
                }
                let own = count - children.reduce(0) { $0 + ((($1["sampleCount"]) as? Int) ?? 0) }
                if own > 0 {
                    selfByBinary[name, default: 0] += own
                    if let app = app { byAppFrame[app, default: 0] += own }
                }
                for child in children { work.append((child, app)) }
            }
        }
        guard total > 0 else { return "no call-stack samples" }
        let shares = selfByBinary.sorted { $0.value != $1.value ? $0.value > $1.value : $0.key < $1.key }.prefix(4)
            .map { "\($0.key) \(Int((Double($0.value) / Double(total) * 100).rounded()))%" }.joined(separator: ", ")
        var text = "samples \(total): \(shares)"
        let tops = byAppFrame.sorted { $0.value != $1.value ? $0.value > $1.value : $0.key < $1.key }.prefix(3)
            .map { "\($0.key) ×\($0.value)" }
        if tops.isEmpty {
            text += " · no \(appBinary) frames"
        } else {
            text += " · top app frames (last \(appBinary) frame before leaf): " + tops.joined(separator: ", ")
        }
        return text
    }

    // MARK: Daily metrics

    static func metricsRow(_ m: DailyMetrics) -> String {
        func num(_ v: Double?, _ unit: String, digits: Int = 0) -> String {
            guard let v = v else { return "n/a" }
            return String(format: "%.\(digits)f", v) + unit
        }
        var parts = [
            "launch \(num(m.launchMedianMs, " ms")) median",
            "resume \(num(m.resumeMedianMs, " ms"))",
            "hitches \(num(m.hitchMsPerSecond, " ms/s", digits: 1))",
            "peak memory \(num(m.peakMemoryMB, " MB"))",
            "cpu \(num(m.cpuSeconds, " s"))",
            "gpu \(num(m.gpuSeconds, " s", digits: 1))",
            "location \(num(m.locationSeconds, " s"))",
            "foreground \(num(m.foregroundSeconds, " s"))"
        ]
        if m.cellularUploadMB != nil || m.cellularDownloadMB != nil {
            parts.append("cellular up \(num(m.cellularUploadMB, " MB", digits: 1)) down \(num(m.cellularDownloadMB, " MB", digits: 1))")
        }
        if let cpu = m.cpuSeconds, let fg = m.foregroundSeconds, fg > 0 {
            parts.append("cpu per foreground hour \(String(format: "%.0f", cpu / fg * 3600)) s")
        }
        return parts.joined(separator: " · ")
    }

    /// Median of a MetricKit histogram's buckets as `(bucketEndMs, count)` pairs; nil when empty.
    static func histogramMedian(_ buckets: [(endMs: Double, count: Int)]) -> Double? {
        let sorted = buckets.sorted { $0.endMs < $1.endMs }
        let total = sorted.reduce(0) { $0 + $1.count }
        guard total > 0 else { return nil }
        var seen = 0
        for b in sorted {
            seen += b.count
            if seen * 2 >= total { return b.endMs }
        }
        return sorted.last?.endMs
    }
}
