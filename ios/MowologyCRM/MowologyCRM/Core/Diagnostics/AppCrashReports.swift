import Foundation
import MetricKit
import CryptoKit

/// Apple supplies crash/hang diagnostics after the event; never attempt file I/O
/// from a POSIX signal handler or claim a forced quit is a crash.
final class AppCrashReports: NSObject, MXMetricManagerSubscriber {
    static let shared = AppCrashReports()
    private var started = false
    private let reports = DispatchQueue(label: "ca.mowology.crash-reports", qos: .utility)

    @MainActor func start() {
        guard !started else { return }
        started = true
        MXMetricManager.shared.add(self)
        didReceive(MXMetricManager.shared.pastDiagnosticPayloads)
        AppDiagnostics.shared.record(category: "lifecycle", message: "App \(Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "unknown") (\(Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "unknown")); \(ProcessInfo.processInfo.operatingSystemVersionString); app launched; MetricKit crash/hang reporting registered. Reports appear when supplied by iOS.")
    }

    func didReceive(_ payloads: [MXDiagnosticPayload]) {
        reports.async { self.importReports(payloads) }
    }

    private func importReports(_ payloads: [MXDiagnosticPayload]) {
        for payload in payloads {
            let data = payload.jsonRepresentation()
            let key = "diagnostics.metrickit.imported"
            let digest = SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
            var imported = UserDefaults.standard.stringArray(forKey: key) ?? []
            guard !imported.contains(digest) else { continue }
            AppDiagnostics.shared.record(category: "apple.diagnostic", message: String(decoding: data, as: UTF8.self))
            imported.append(digest)
            UserDefaults.standard.set(Array(imported.suffix(64)), forKey: key)
        }
    }
}
