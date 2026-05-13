//
//  ReceiptQueue.swift
//  MowologyCRM
//
//  Disk-backed offline queue for receipt uploads.
//  Images are written to the temp directory; metadata is stored in UserDefaults.
//  Transient network failures keep items queued and bump an attempt counter;
//  after `maxAttempts` an item is marked `failedUnrecoverable` and surfaces
//  to the UI. NWPathMonitor + each new enqueue both drive drains.
//

import Foundation
import Network

@MainActor
final class ReceiptQueue: ObservableObject {

    static let shared = ReceiptQueue()

    /// Items still eligible for retry.
    @Published private(set) var pendingCount: Int = 0
    /// Items that have exhausted their retry budget and need user attention.
    @Published private(set) var unrecoverableCount: Int = 0

    private let defaults   = UserDefaults.standard
    private let storeKey   = "mw.receipt.queue.v1"
    private let monitor    = NWPathMonitor()
    private var uploadTask: Task<Void, Never>?
    private var uploadHandler: ((Data, Double?, Double?, Int?) async throws -> ReceiptIntakeResponse)?

    private let maxAttempts = 5

    private init() { updateCounts() }

    // MARK: - Pending Item

    struct PendingItem: Codable {
        let id: String
        let imageFilename: String
        let lat: Double?
        let lng: Double?
        let jobId: Int?
        let queuedAt: Date
        var attempts: Int
        var failedUnrecoverable: Bool

        init(
            id: String,
            imageFilename: String,
            lat: Double?,
            lng: Double?,
            jobId: Int?,
            queuedAt: Date,
            attempts: Int = 0,
            failedUnrecoverable: Bool = false
        ) {
            self.id = id
            self.imageFilename = imageFilename
            self.lat = lat
            self.lng = lng
            self.jobId = jobId
            self.queuedAt = queuedAt
            self.attempts = attempts
            self.failedUnrecoverable = failedUnrecoverable
        }

        // Backwards-compatible decode for items queued before retry fields existed.
        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            id                  = try c.decode(String.self,   forKey: .id)
            imageFilename       = try c.decode(String.self,   forKey: .imageFilename)
            lat                 = try c.decodeIfPresent(Double.self, forKey: .lat)
            lng                 = try c.decodeIfPresent(Double.self, forKey: .lng)
            jobId               = try c.decodeIfPresent(Int.self,    forKey: .jobId)
            queuedAt            = try c.decode(Date.self,     forKey: .queuedAt)
            attempts            = (try? c.decodeIfPresent(Int.self,  forKey: .attempts)) ?? 0
            failedUnrecoverable = (try? c.decodeIfPresent(Bool.self, forKey: .failedUnrecoverable)) ?? false
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
            updateCounts()
        }
    }

    private func updateCounts() {
        let all = items
        pendingCount       = all.filter { !$0.failedUnrecoverable }.count
        unrecoverableCount = all.filter {  $0.failedUnrecoverable }.count
    }

    // MARK: - Enqueue / Remove

    @discardableResult
    func enqueue(imageData: Data, lat: Double?, lng: Double?, jobId: Int?) -> String {
        let id       = UUID().uuidString
        let filename = "receipt-\(id).jpg"
        let url      = FileManager.default.temporaryDirectory.appendingPathComponent(filename)
        try? imageData.write(to: url)
        var current = items
        current.append(PendingItem(
            id: id,
            imageFilename: filename,
            lat: lat,
            lng: lng,
            jobId: jobId,
            queuedAt: Date()
        ))
        items = current

        // Try to drain right away if a handler is wired up; otherwise NWPathMonitor will pick it up.
        if let handler = uploadHandler {
            Task { @MainActor [weak self] in
                await self?.drain(uploadHandler: handler)
            }
        }
        return id
    }

    func remove(id: String) {
        if let item = items.first(where: { $0.id == id }) {
            let url = FileManager.default.temporaryDirectory.appendingPathComponent(item.imageFilename)
            try? FileManager.default.removeItem(at: url)
        }
        items = items.filter { $0.id != id }
    }

    /// Reset the attempt counter on every unrecoverable item so the user can retry manually.
    func resetUnrecoverable() {
        var updated = items
        for i in updated.indices where updated[i].failedUnrecoverable {
            updated[i].failedUnrecoverable = false
            updated[i].attempts = 0
        }
        items = updated
        if let handler = uploadHandler {
            Task { @MainActor [weak self] in
                await self?.drain(uploadHandler: handler)
            }
        }
    }

    // MARK: - Monitor & Drain

    func startMonitoring(uploadHandler: @escaping (Data, Double?, Double?, Int?) async throws -> ReceiptIntakeResponse) {
        self.uploadHandler = uploadHandler

        let queue = DispatchQueue(label: "mw.receipt.monitor", qos: .utility)
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor [weak self] in
                await self?.drain(uploadHandler: uploadHandler)
            }
        }
        monitor.start(queue: queue)

        // Drain anything already queued at launch.
        Task { @MainActor [weak self] in
            await self?.drain(uploadHandler: uploadHandler)
        }
    }

    func drain(uploadHandler: @escaping (Data, Double?, Double?, Int?) async throws -> ReceiptIntakeResponse) async {
        guard uploadTask == nil else { return }
        uploadTask = Task {
            let pending = items.filter { !$0.failedUnrecoverable }
            for item in pending {
                let fileUrl = FileManager.default.temporaryDirectory.appendingPathComponent(item.imageFilename)
                guard let data = try? Data(contentsOf: fileUrl) else {
                    // Image file vanished — drop the orphaned metadata.
                    items = items.filter { $0.id != item.id }
                    continue
                }
                do {
                    _ = try await uploadHandler(data, item.lat, item.lng, item.jobId)
                    items = items.filter { $0.id != item.id }
                    try? FileManager.default.removeItem(at: fileUrl)
                } catch {
                    // Bump the per-item attempt counter; mark unrecoverable when budget is spent.
                    var updated = items
                    if let idx = updated.firstIndex(where: { $0.id == item.id }) {
                        updated[idx].attempts += 1
                        if updated[idx].attempts >= maxAttempts {
                            updated[idx].failedUnrecoverable = true
                        }
                        items = updated
                    }
                    // Transient failure → stop this drain run, wait for the next NWPathMonitor
                    // satisfied callback or enqueue. Non-transient → keep going so one bad
                    // item doesn't block the rest of the queue.
                    if ReceiptQueue.isTransientNetworkError(error) {
                        break
                    }
                }
            }
            uploadTask = nil
        }
        await uploadTask?.value
    }

    // MARK: - Transient classification

    /// True for URLErrors that mean "try again later" (timeouts, lost connection, DNS, etc.).
    static func isTransientNetworkError(_ error: Error) -> Bool {
        if let apiError = error as? APIError,
           case .networkError(let underlying) = apiError {
            return isTransientURLError(underlying)
        }
        return isTransientURLError(error)
    }

    private static func isTransientURLError(_ error: Error) -> Bool {
        guard let urlError = error as? URLError else { return false }
        switch urlError.code {
        case .timedOut,
             .networkConnectionLost,
             .notConnectedToInternet,
             .cannotConnectToHost,
             .cannotFindHost,
             .dnsLookupFailed,
             .resourceUnavailable,
             .dataNotAllowed,
             .internationalRoamingOff,
             .cancelled:
            return true
        default:
            return false
        }
    }
}
