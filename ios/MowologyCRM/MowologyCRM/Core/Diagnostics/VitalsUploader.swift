import Foundation
#if canImport(UIKit)
import UIKit
#endif

/// Sends the diagnostics that matter to /crm/api/app-vitals.php with no export step (plan 2026-11-15).
/// Hooks `AppDiagnostics` (one cheap closure), keeps an offline outbox on disk, flushes on launch, foreground,
/// reconnect and a 60 s timer. Never blocks or throws into the app; release builds only.
@MainActor
final class VitalsUploader {
    static let shared = VitalsUploader()

    private struct Ack: Decodable { let success: Bool? }

    private var outbox = VitalsOutbox()
    private var client: APIClient?
    private var flushing = false
    private var previousRunCrumbs: [String] = []
    private var timer: Timer?
    private var crumbWritePending = false
    private let io = DispatchQueue(label: "ca.mowology.vitals-io", qos: .utility)
    private let directory = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        .appendingPathComponent("Vitals", isDirectory: true)
    private var outboxURL: URL { directory.appendingPathComponent("outbox.json") }
    private var crumbsURL: URL { directory.appendingPathComponent("crumbs.json") }

    /// Call before `CrashLoopBreaker.start()`: it reads the last run's breadcrumbs before this run overwrites them.
    func start() {
        #if DEBUG
        return
        #else
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        previousRunCrumbs = (try? JSONDecoder().decode([String].self, from: Data(contentsOf: crumbsURL))) ?? []
        if let data = try? Data(contentsOf: outboxURL), let saved = try? JSONDecoder().decode(VitalsOutbox.self, from: data) { outbox = saved }
        AppDiagnostics.eventSink = { category, message in
            guard VitalsClassifier.categories.contains(category) else { return }
            Task { @MainActor in VitalsUploader.shared.enqueue(category: category, message: message) }
        }
        AppDiagnostics.crumbSink = { crumbs in
            Task { @MainActor in VitalsUploader.shared.noteCrumbs(crumbs) }
        }
        timer = Timer.scheduledTimer(withTimeInterval: 60, repeats: true) { _ in
            Task { @MainActor in await VitalsUploader.shared.flush() }
        }
        NotificationCenter.default.addObserver(forName: .mwPingQueueOnline, object: nil, queue: .main) { _ in
            Task { @MainActor in await VitalsUploader.shared.flush() }
        }
        #endif
    }

    func configure(apiClient: APIClient) { client = apiClient }

    private func enqueue(category: String, message: String) {
        let clean = AppDiagnostics.redacted(message)
        guard let event = VitalsClassifier.event(category: category, message: clean, at: Date(), id: UUID().uuidString,
                                                 previousRunBreadcrumbs: previousRunCrumbs) else { return }
        outbox.add(event)
        persist()
        if event.severity == "error" { Task { await flush() } }
    }

    /// The last 30 taps, rewritten at most every 3 s so the next launch can say what the owner was doing when the run died.
    private func noteCrumbs(_ crumbs: [String]) {
        guard !crumbWritePending else { return }
        crumbWritePending = true
        DispatchQueue.main.asyncAfter(deadline: .now() + 3) { [weak self] in
            guard let self else { return }
            self.crumbWritePending = false
            let latest = AppDiagnostics.shared.recentBreadcrumbs()
            let url = self.crumbsURL
            self.io.async { if let data = try? JSONEncoder().encode(latest) { try? data.write(to: url, options: .atomic) } }
        }
    }

    private func persist() {
        let snapshot = outbox
        let url = outboxURL
        io.async { if let data = try? JSONEncoder().encode(snapshot) { try? data.write(to: url, options: .atomic) } }
    }

    func flush() async {
        #if !DEBUG
        // Signed out (no token) → nothing to send: the server would answer 401 and the backoff would start.
        guard !flushing, let client, client.isSignedIn else { return }
        let batch = outbox.nextBatch(now: Date())
        guard !batch.isEmpty else { return }
        flushing = true
        defer { flushing = false }
        let ids = batch.map(\.id)
        do {
            guard let events = try? JSONSerialization.jsonObject(with: JSONEncoder().encode(batch)) else { return }
            // A 401 here must not sign the crew out: this is a background report, not the session.
            let _: Ack = try await client.request(.appVitals, body: ["device": Self.device(), "events": events],
                                                  faultsAreTransient: true, signsOutOn401: false)
            outbox.sent(ids)
        } catch APIError.serverError {
            outbox.rejected(ids)           // the server said no to this batch; retrying cannot help
        } catch {
            outbox.failed(now: Date())     // signed out, offline, 5xx: keep them and back off
        }
        persist()
        #endif
    }

    private static func device() -> [String: String] {
        var info = utsname()
        uname(&info)
        let model = withUnsafePointer(to: &info.machine) { $0.withMemoryRebound(to: CChar.self, capacity: 1) { String(cString: $0) } }
        let key = "vitals.installId"
        let install = UserDefaults.standard.string(forKey: key) ?? {
            let id = UUID().uuidString
            UserDefaults.standard.set(id, forKey: key)
            return id
        }()
        return ["platform": "ios", "install_id": install, "model": model,
                "os": "iOS \(ProcessInfo.processInfo.operatingSystemVersionString)",
                "app_version": Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "unknown",
                "build": Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "unknown"]
    }
}
