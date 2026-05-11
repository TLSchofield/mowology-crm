//
//  ReceiptQueue.swift
//  MowologyCRM
//
//  Disk-backed offline queue for receipt uploads.
//  Images are persisted to Application Support (survives OS temp-dir purges).
//  NWPathMonitor drains the queue automatically on reconnect.
//

import Foundation
import Network

@MainActor
final class ReceiptQueue: ObservableObject {

    static let shared = ReceiptQueue()

    @Published private(set) var pendingCount: Int = 0

    private let defaults   = UserDefaults.standard
    private let storeKey   = "mw.receipt.queue.v1"
    private let monitor    = NWPathMonitor()
    private var uploadTask: Task<Void, Never>?

    // Application Support survives the OS temp-dir purge (which wipes the
    // temp directory when the device runs low on storage — a common field condition).
    private static var storageDirectory: URL {
        let support = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        let dir = support.appendingPathComponent("MowologyReceipts", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir
    }

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
        let url      = Self.storageDirectory.appendingPathComponent(filename)
        do {
            // Use .atomic write to prevent partial files surviving a crash
            try imageData.write(to: url, options: .atomic)
        } catch {
            // Write failed — do NOT add orphaned metadata to the queue.
            // The upload will be retried immediately when connectivity returns;
            // losing the offline copy is better than a zombie queue entry.
            return
        }
        var current = items
        current.append(PendingItem(id: id, imageFilename: filename, lat: lat,
                                   lng: lng, jobId: jobId, queuedAt: Date()))
        items = current
    }

    // MARK: - Monitor & Drain

    func startMonitoring(uploadHandler: @escaping (Data, Double?, Double?, Int?) async throws -> ReceiptIntakeResponse) {
        let queue = DispatchQueue(label: "mw.receipt.monitor", qos: .utility)
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor [weak self] in
                await self?.drain(uploadHandler: uploadHandler)
            }
        }
        monitor.start(queue: queue)
    }

    func drain(uploadHandler: @escaping (Data, Double?, Double?, Int?) async throws -> ReceiptIntakeResponse) async {
        guard uploadTask == nil else { return }
        uploadTask = Task {
            let pending = items
            for item in pending {
                let url = Self.storageDirectory.appendingPathComponent(item.imageFilename)
                guard let data = try? Data(contentsOf: url) else {
                    // File missing (OS-purged old temp path, or never written) — drop orphaned entry
                    items = items.filter { $0.id != item.id }
                    continue
                }
                do {
                    _ = try await uploadHandler(data, item.lat, item.lng, item.jobId)
                    items = items.filter { $0.id != item.id }
                    try? FileManager.default.removeItem(at: url)
                } catch {
                    // Upload failed — leave this item in the queue for the next drain.
                    // Continue to attempt remaining items; don't abort the whole batch
                    // because one item's network error doesn't guarantee all will fail.
                    continue
                }
            }
            uploadTask = nil
        }
        await uploadTask?.value
    }
}
