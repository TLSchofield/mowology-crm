//
//  ReceiptQueue.swift
//  MowologyCRM
//
//  Disk-backed offline queue for receipt uploads.
//  Images are written to the temp directory; metadata is stored in UserDefaults.
//  NWPathMonitor drains the queue automatically on reconnect.
//

import Foundation
import Network

@MainActor
final class ReceiptQueue: ObservableObject {

    static let shared = ReceiptQueue()

    @Published private(set) var pendingCount: Int = 0

    private let defaults       = UserDefaults.standard
    private let storeKey       = "mw.receipt.queue.v1"
    private let monitor        = NWPathMonitor()
    private var monitorStarted = false
    private var uploadTask: Task<Void, Never>?

    private init() { updateCount() }

    // MARK: - Pending Item

    struct PendingItem: Codable {
        let id: String
        let imageFilename: String
        let lat: Double?
        let lng: Double?
        let jobId: Int?
        let queuedAt: Date
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
            updateCount()
        }
    }

    private func updateCount() { pendingCount = items.count }

    // MARK: - Enqueue

    func enqueue(imageData: Data, lat: Double?, lng: Double?, jobId: Int?) {
        let id       = UUID().uuidString
        let filename = "receipt-\(id).jpg"
        let url      = FileManager.default.temporaryDirectory.appendingPathComponent(filename)
        try? imageData.write(to: url)
        var current = items
        current.append(PendingItem(id: id, imageFilename: filename, lat: lat, lng: lng, jobId: jobId, queuedAt: Date()))
        items = current
    }

    // MARK: - Monitor & Drain

    func startMonitoring(uploadHandler: @escaping (Data, Double?, Double?, Int?) async throws -> ReceiptIntakeResponse) {
        // Re-set the handler so the latest apiClient closure is always used,
        // but only start the monitor once (NWPathMonitor asserts on double-start).
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor [weak self] in
                await self?.drain(uploadHandler: uploadHandler)
            }
        }
        guard !monitorStarted else { return }
        monitorStarted = true
        let queue = DispatchQueue(label: "mw.receipt.monitor", qos: .utility)
        monitor.start(queue: queue)
    }

    func drain(uploadHandler: @escaping (Data, Double?, Double?, Int?) async throws -> ReceiptIntakeResponse) async {
        guard uploadTask == nil else { return }
        uploadTask = Task {
            let pending = items
            for item in pending {
                let url = FileManager.default.temporaryDirectory.appendingPathComponent(item.imageFilename)
                guard let data = try? Data(contentsOf: url) else {
                    items = items.filter { $0.id != item.id }
                    continue
                }
                do {
                    _ = try await uploadHandler(data, item.lat, item.lng, item.jobId)
                    items = items.filter { $0.id != item.id }
                    try? FileManager.default.removeItem(at: url)
                } catch {
                    break
                }
            }
            uploadTask = nil
        }
        await uploadTask?.value
    }
}
