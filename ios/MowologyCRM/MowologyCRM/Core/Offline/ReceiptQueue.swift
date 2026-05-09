//
//  ReceiptQueue.swift
//  MowologyCRM
//
//  Disk-backed offline queue for receipt uploads.
//  Images are written to the temp directory; metadata is stored in UserDefaults.
//  Each item carries a UUID that doubles as the server-side `Idempotency-Key`,
//  so a retry can never produce a duplicate expense.
//
//  NWPathMonitor drains the queue automatically on reconnect.
//

import Foundation
import Network

@MainActor
final class ReceiptQueue: ObservableObject {

    static let shared = ReceiptQueue()

    @Published private(set) var pendingCount: Int = 0
    @Published private(set) var failedCount: Int = 0
    /// Last unrecoverable failure message, surfaced to the user via the Receipts UI.
    @Published var lastFailureMessage: String?

    private let defaults   = UserDefaults.standard
    private let storeKey   = "mw.receipt.queue.v1"
    private let monitor    = NWPathMonitor()
    private var uploadTask: Task<Void, Never>?

    /// Upper bound on the exponential backoff (1 hour).
    private let maxBackoffSeconds: TimeInterval = 3600
    /// Number of attempts before an item is marked unrecoverable.
    private let maxAttempts = 5
    /// Temp-file retention window for the launch-time cleanup pass.
    private let tempFileTTL: TimeInterval = 7 * 24 * 60 * 60

    private init() { refreshCounts() }

    // MARK: - Pending Item

    struct PendingItem: Codable {
        /// UUID — also sent as the `Idempotency-Key` header on every retry.
        let id: String
        let imageFilename: String
        let lat: Double?
        let lng: Double?
        let jobId: Int?
        let queuedAt: Date
        var attempts: Int
        var nextAttemptAt: Date
        var failedUnrecoverable: Bool

        init(
            id: String,
            imageFilename: String,
            lat: Double?,
            lng: Double?,
            jobId: Int?,
            queuedAt: Date,
            attempts: Int = 0,
            nextAttemptAt: Date = .distantPast,
            failedUnrecoverable: Bool = false
        ) {
            self.id                  = id
            self.imageFilename       = imageFilename
            self.lat                 = lat
            self.lng                 = lng
            self.jobId               = jobId
            self.queuedAt            = queuedAt
            self.attempts            = attempts
            self.nextAttemptAt       = nextAttemptAt
            self.failedUnrecoverable = failedUnrecoverable
        }

        // Custom decoder so queue items written by older app versions —
        // which lack the retry/backoff fields — still load with defaults.
        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            self.id                  = try c.decode(String.self, forKey: .id)
            self.imageFilename       = try c.decode(String.self, forKey: .imageFilename)
            self.lat                 = try c.decodeIfPresent(Double.self, forKey: .lat)
            self.lng                 = try c.decodeIfPresent(Double.self, forKey: .lng)
            self.jobId               = try c.decodeIfPresent(Int.self, forKey: .jobId)
            self.queuedAt            = try c.decode(Date.self, forKey: .queuedAt)
            self.attempts            = try c.decodeIfPresent(Int.self,  forKey: .attempts) ?? 0
            self.nextAttemptAt       = try c.decodeIfPresent(Date.self, forKey: .nextAttemptAt) ?? .distantPast
            self.failedUnrecoverable = try c.decodeIfPresent(Bool.self, forKey: .failedUnrecoverable) ?? false
        }
    }

    private var items: [PendingItem] {
        get {
            guard let data = defaults.data(forKey: storeKey),
                  let decoded = try? JSONDecoder().decode([PendingItem].self, from: data)
            else { return [] }
            return decoded
        }
        set {
            defaults.set(try? JSONEncoder().encode(newValue), forKey: storeKey)
            refreshCounts()
        }
    }

    private func refreshCounts() {
        let all = items
        pendingCount = all.filter { !$0.failedUnrecoverable }.count
        failedCount  = all.filter {  $0.failedUnrecoverable }.count
    }

    // MARK: - Enqueue

    /// Enqueue a receipt for upload. Returns the idempotency key that will be
    /// reused on every retry — callers may pass a pre-existing UUID via
    /// `idempotencyKey:` so a first-attempt upload that timed out (response
    /// never arrived) can be retried without producing a duplicate expense.
    @discardableResult
    func enqueue(
        imageData: Data,
        lat: Double?,
        lng: Double?,
        jobId: Int?,
        idempotencyKey: String? = nil
    ) -> String {
        let id       = idempotencyKey ?? UUID().uuidString
        let filename = "receipt-\(id).jpg"
        let url      = FileManager.default.temporaryDirectory.appendingPathComponent(filename)
        try? imageData.write(to: url)
        var current = items
        current.append(PendingItem(
            id:            id,
            imageFilename: filename,
            lat:           lat,
            lng:           lng,
            jobId:         jobId,
            queuedAt:      Date()
        ))
        items = current
        return id
    }

    // MARK: - Failed item management

    /// Remove an item the user has chosen to discard from the failed list.
    func discard(itemId: String) {
        var current = items
        if let item = current.first(where: { $0.id == itemId }) {
            let url = FileManager.default.temporaryDirectory.appendingPathComponent(item.imageFilename)
            try? FileManager.default.removeItem(at: url)
        }
        current.removeAll { $0.id == itemId }
        items = current
    }

    /// Reset an item's retry state so the next drain pass attempts it again.
    func retry(itemId: String) {
        var current = items
        guard let idx = current.firstIndex(where: { $0.id == itemId }) else { return }
        current[idx].attempts            = 0
        current[idx].nextAttemptAt       = .distantPast
        current[idx].failedUnrecoverable = false
        items = current
    }

    // MARK: - Monitor & Drain

    typealias UploadHandler = (
        _ data: Data,
        _ lat: Double?,
        _ lng: Double?,
        _ jobId: Int?,
        _ idempotencyKey: String
    ) async throws -> ReceiptIntakeResponse

    func startMonitoring(uploadHandler: @escaping UploadHandler) {
        let queue = DispatchQueue(label: "mw.receipt.monitor", qos: .utility)
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor [weak self] in
                await self?.drain(uploadHandler: uploadHandler)
            }
        }
        monitor.start(queue: queue)
    }

    func drain(uploadHandler: @escaping UploadHandler) async {
        guard uploadTask == nil else { return }
        uploadTask = Task {
            let now = Date()
            // Skip unrecoverable items and items whose backoff window hasn't elapsed.
            let eligible = items.filter { !$0.failedUnrecoverable && now >= $0.nextAttemptAt }
            for item in eligible {
                let url = FileManager.default.temporaryDirectory.appendingPathComponent(item.imageFilename)
                guard let data = try? Data(contentsOf: url) else {
                    // Orphaned metadata — temp file is gone. Drop it.
                    var current = items
                    current.removeAll { $0.id == item.id }
                    items = current
                    continue
                }
                do {
                    _ = try await uploadHandler(data, item.lat, item.lng, item.jobId, item.id)
                    var current = items
                    current.removeAll { $0.id == item.id }
                    items = current
                    try? FileManager.default.removeItem(at: url)
                } catch {
                    // CRITICAL: was `break` — abandoning the rest of the batch on a
                    // single failure. Now we record the failure on this item only
                    // and keep draining so other items get their chance.
                    recordFailure(itemId: item.id)
                    continue
                }
            }
            uploadTask = nil
        }
        await uploadTask?.value
    }

    private func recordFailure(itemId: String) {
        var current = items
        guard let idx = current.firstIndex(where: { $0.id == itemId }) else { return }
        current[idx].attempts += 1
        if current[idx].attempts >= maxAttempts {
            current[idx].failedUnrecoverable = true
            lastFailureMessage = "A receipt could not be uploaded after \(maxAttempts) attempts. Open Receipts to retry or remove it."
        } else {
            // 2s, 4s, 8s, 16s — capped at 1 hour.
            let backoff = min(pow(2.0, Double(current[idx].attempts)), maxBackoffSeconds)
            current[idx].nextAttemptAt = Date().addingTimeInterval(backoff)
        }
        items = current
    }

    // MARK: - Temp-file cleanup

    /// Delete `receipt-*.jpg` files in the temp directory older than 7 days.
    /// Scoped to our prefix so we don't stomp on other temp consumers.
    /// Call from app launch.
    nonisolated static func cleanupOldTempFiles() {
        let fm      = FileManager.default
        let tempDir = fm.temporaryDirectory
        let cutoff  = Date().addingTimeInterval(-7 * 24 * 60 * 60)
        guard let urls = try? fm.contentsOfDirectory(
            at: tempDir,
            includingPropertiesForKeys: [.contentModificationDateKey],
            options: [.skipsHiddenFiles]
        ) else { return }
        for url in urls {
            guard url.lastPathComponent.hasPrefix("receipt-") else { continue }
            guard let attrs = try? url.resourceValues(forKeys: [.contentModificationDateKey]),
                  let mod   = attrs.contentModificationDate,
                  mod < cutoff else { continue }
            try? fm.removeItem(at: url)
        }
    }
}
