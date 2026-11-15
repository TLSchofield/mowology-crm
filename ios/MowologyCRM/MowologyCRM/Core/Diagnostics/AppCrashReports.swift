import Foundation
import MetricKit
import CryptoKit

/// Apple supplies crash/hang diagnostics after the event; never attempt file I/O
/// from a POSIX signal handler or claim a forced quit is a crash.
/// The log gets one readable line per diagnostic (`MetricKitSummary`); the raw JSON is kept in
/// Application Support/MetricKit (newest 10) because a payload can be 800 KB and the log file is 1 MB.
final class AppCrashReports: NSObject, MXMetricManagerSubscriber {
    static let shared = AppCrashReports()
    private var started = false
    private let reports = DispatchQueue(label: "ca.mowology.crash-reports", qos: .utility)
    private let importedKey = "diagnostics.metrickit.imported"

    @MainActor func start() {
        guard !started else { return }
        started = true
        MXMetricManager.shared.add(self)
        didReceive(MXMetricManager.shared.pastDiagnosticPayloads)
        didReceive(MXMetricManager.shared.pastPayloads)
        AppDiagnostics.shared.record(category: "lifecycle", message: "App \(Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "unknown") (\(Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "unknown")); \(ProcessInfo.processInfo.operatingSystemVersionString); app launched; MetricKit crash/hang reporting registered. Reports appear when supplied by iOS.")
    }

    func didReceive(_ payloads: [MXDiagnosticPayload]) {
        reports.async { self.importReports(payloads) }
    }

    func didReceive(_ payloads: [MXMetricPayload]) {
        reports.async { self.importMetrics(payloads) }
    }

    /// True the first time a payload is seen (the last 64 are remembered across launches).
    private func firstSighting(_ data: Data) -> String? {
        let digest = SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
        var imported = UserDefaults.standard.stringArray(forKey: importedKey) ?? []
        guard !imported.contains(digest) else { return nil }
        imported.append(digest)
        UserDefaults.standard.set(Array(imported.suffix(64)), forKey: importedKey)
        return digest
    }

    private func importReports(_ payloads: [MXDiagnosticPayload]) {
        for payload in payloads {
            let data = payload.jsonRepresentation()
            guard let digest = firstSighting(data) else { continue }
            let saved = saveRaw(data, name: "diagnostic-\(digest.prefix(12)).json")
            for line in MetricKitSummary.diagnosticLines(data) {
                AppDiagnostics.shared.record(category: "apple.diagnostic", message: line + (saved ? "" : " (raw payload not saved)"))
            }
        }
    }

    private func importMetrics(_ payloads: [MXMetricPayload]) {
        for payload in payloads {
            guard firstSighting(payload.jsonRepresentation()) != nil else { continue }
            let formatter = DateFormatter()
            formatter.dateFormat = "yyyy-MM-dd HH:mm"
            let window = "\(formatter.string(from: payload.timeStampBegin))–\(formatter.string(from: payload.timeStampEnd))"
            AppDiagnostics.shared.record(category: "apple.metrics",
                message: "daily \(window) · " + MetricKitSummary.metricsRow(Self.dailyMetrics(payload)))
        }
    }

    static func dailyMetrics(_ p: MXMetricPayload) -> MetricKitSummary.DailyMetrics {
        func median(_ h: MXHistogram<UnitDuration>?) -> Double? {
            guard let h = h else { return nil }
            var buckets: [(endMs: Double, count: Int)] = []
            let e = h.bucketEnumerator
            while let b = e.nextObject() as? MXHistogramBucket<UnitDuration> {
                buckets.append((b.bucketEnd.converted(to: .milliseconds).value, b.bucketCount))
            }
            return MetricKitSummary.histogramMedian(buckets)
        }
        func seconds(_ m: Measurement<UnitDuration>?) -> Double? { m?.converted(to: .seconds).value }
        func megabytes(_ m: Measurement<UnitInformationStorage>?) -> Double? { m?.converted(to: .megabytes).value }
        var m = MetricKitSummary.DailyMetrics()
        m.launchMedianMs = median(p.applicationLaunchMetrics?.histogrammedTimeToFirstDraw)
        m.resumeMedianMs = median(p.applicationLaunchMetrics?.histogrammedApplicationResumeTime)
        if let hitch = p.animationMetrics?.scrollHitchTimeRatio {
            // Apple reports hitch time per scroll second; the unit symbol says whether it is ms/s or a bare ratio.
            m.hitchMsPerSecond = hitch.unit.symbol.contains("ms") ? hitch.value : hitch.value * 1000
        }
        m.peakMemoryMB = megabytes(p.memoryMetrics?.peakMemoryUsage)
        m.cpuSeconds = seconds(p.cpuMetrics?.cumulativeCPUTime)
        m.gpuSeconds = seconds(p.gpuMetrics?.cumulativeGPUTime)
        m.locationSeconds = seconds(p.locationActivityMetrics?.cumulativeBestAccuracyTime)
        m.foregroundSeconds = seconds(p.applicationTimeMetrics?.cumulativeForegroundTime)
        m.cellularUploadMB = megabytes(p.networkTransferMetrics?.cumulativeCellularUpload)
        m.cellularDownloadMB = megabytes(p.networkTransferMetrics?.cumulativeCellularDownload)
        return m
    }

    private func saveRaw(_ data: Data, name: String) -> Bool {
        let fm = FileManager.default
        do {
            let dir = try fm.url(for: .applicationSupportDirectory, in: .userDomainMask, appropriateFor: nil, create: true)
                .appendingPathComponent("MetricKit", isDirectory: true)
            try fm.createDirectory(at: dir, withIntermediateDirectories: true)
            try data.write(to: dir.appendingPathComponent(name), options: [.atomic, .completeFileProtection])
            let files = try fm.contentsOfDirectory(at: dir, includingPropertiesForKeys: [.contentModificationDateKey])
                .sorted { ((try? $0.resourceValues(forKeys: [.contentModificationDateKey]).contentModificationDate) ?? .distantPast) > ((try? $1.resourceValues(forKeys: [.contentModificationDateKey]).contentModificationDate) ?? .distantPast) }
            for old in files.dropFirst(10) { try fm.removeItem(at: old) }
            return true
        } catch {
            AppDiagnostics.shared.record(category: "apple.diagnostic", message: "save MetricKit payload failed: \(ErrorDetails.describe(error))")
            return false
        }
    }
}
