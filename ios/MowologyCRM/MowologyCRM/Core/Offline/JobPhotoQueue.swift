//
//  JobPhotoQueue.swift
//  MowologyCRM
//
//  Disk-backed offline queue for before/after job site photos.
//  Images are written to the temp directory; metadata stored in UserDefaults.
//  NWPathMonitor drains the queue automatically on reconnect.
//
//  Mirrors ReceiptQueue.swift in structure — same offline-first pattern.
//

import Foundation
import Network

// MARK: - Photo Type

/// Identifies which proof-of-work slot a job photo occupies.
enum JobPhotoType: String, Codable {
    case before
    case after
}

// MARK: - JobPhotoQueue

@MainActor
final class JobPhotoQueue: ObservableObject {

    static let shared = JobPhotoQueue()

    @Published private(set) var pendingCount: Int = 0

    private let defaults  = UserDefaults.standard
    private let storeKey  = "mw.jobphoto.queue.v1"
    private let monitor   = NWPathMonitor()
    private var drainTask: Task<Void, Never>?

    private init() { updateCount() }

    // MARK: - Pending Item

    struct PendingItem: Codable {
        let id:            String
        let visitId:       Int
        let photoType:     JobPhotoType
        let imageFilename: String
        let queuedAt:      Date
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

    func enqueue(imageData: Data, visitId: Int, photoType: JobPhotoType) {
        let id       = UUID().uuidString
        let filename = "jobphoto-\(id).jpg"
        let url      = FileManager.default.temporaryDirectory.appendingPathComponent(filename)
        try? imageData.write(to: url)
        var current = items
        current.append(PendingItem(
            id:            id,
            visitId:       visitId,
            photoType:     photoType,
            imageFilename: filename,
            queuedAt:      Date()
        ))
        items = current
    }

    // MARK: - Monitor & Drain

    func startMonitoring(uploadHandler: @escaping (Data, Int, JobPhotoType) async throws -> Void) {
        let queue = DispatchQueue(label: "mw.jobphoto.monitor", qos: .utility)
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor [weak self] in
                await self?.drain(uploadHandler: uploadHandler)
            }
        }
        monitor.start(queue: queue)
    }

    func drain(uploadHandler: @escaping (Data, Int, JobPhotoType) async throws -> Void) async {
        guard drainTask == nil else { return }
        drainTask = Task {
            let pending = items
            for item in pending {
                let fileURL = FileManager.default.temporaryDirectory
                    .appendingPathComponent(item.imageFilename)
                guard let data = try? Data(contentsOf: fileURL) else {
                    // File missing — discard the stale entry
                    items = items.filter { $0.id != item.id }
                    continue
                }
                do {
                    try await uploadHandler(data, item.visitId, item.photoType)
                    items = items.filter { $0.id != item.id }
                    try? FileManager.default.removeItem(at: fileURL)
                } catch {
                    break  // Network still down — leave the rest queued
                }
            }
            drainTask = nil
        }
        await drainTask?.value
    }
}
