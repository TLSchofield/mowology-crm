//
//  JobPhotoQueue.swift
//  MowologyCRM
//
//  Disk-backed offline queue for before/after job site photos.
//  Images are written to the Documents directory; metadata stored in UserDefaults.
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

    private init() {
        updateCount()
        ensureQueueDirectory()
    }

    // MARK: - Queue Directory (Documents — survives OS cache purges)

    private var queueDirectory: URL {
        FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("JobPhotoQueue", isDirectory: true)
    }

    private func ensureQueueDirectory() {
        try? FileManager.default.createDirectory(
            at: queueDirectory,
            withIntermediateDirectories: true,
            attributes: [.protectionKey: FileProtectionType.completeUntilFirstUserAuthentication]
        )
    }

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

    /// Saves an image to disk and records the pending upload.
    /// Replaces any existing queued item for the same visit + slot so there is
    /// always at most one pending upload per slot (the most recent capture).
    func enqueue(imageData: Data, visitId: Int, photoType: JobPhotoType) {
        var current = items
        // Drop stale entries for this slot and remove their files.
        for stale in current where stale.visitId == visitId && stale.photoType == photoType {
            let staleURL = queueDirectory.appendingPathComponent(stale.imageFilename)
            try? FileManager.default.removeItem(at: staleURL)
        }
        current.removeAll { $0.visitId == visitId && $0.photoType == photoType }

        let id       = UUID().uuidString
        let filename = "jobphoto-\(id).jpg"
        let fileURL  = queueDirectory.appendingPathComponent(filename)

        do {
            try imageData.write(to: fileURL)
        } catch {
            NSLog("[JobPhotoQueue] enqueue disk write failed for visit \(visitId) (\(photoType.rawValue)): \(error)")
            return  // Don't queue an entry that points to a missing file
        }

        current.append(PendingItem(
            id:            id,
            visitId:       visitId,
            photoType:     photoType,
            imageFilename: filename,
            queuedAt:      Date()
        ))
        items = current
        NSLog("[JobPhotoQueue] queued \(photoType.rawValue) photo for visit \(visitId) — pending: \(current.count)")
    }

    /// Returns true if there is a photo queued for this visit + slot.
    /// Drives the soft "pending sync" indicator in JobPhotoSection.
    func hasQueued(visitId: Int, photoType: JobPhotoType) -> Bool {
        items.contains { $0.visitId == visitId && $0.photoType == photoType }
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

    /// Convenience overload: drain via an APIClient. Stops on the first network
    /// error so the remaining queue is preserved for the next reconnect.
    func drain(using apiClient: APIClient) async {
        await drain { data, visitId, photoType in
            try await apiClient.uploadJobPhoto(imageData: data, visitId: visitId, photoType: photoType)
        }
    }

    func drain(uploadHandler: @escaping (Data, Int, JobPhotoType) async throws -> Void) async {
        guard drainTask == nil else { return }
        let pending = items
        guard !pending.isEmpty else { return }
        NSLog("[JobPhotoQueue] drain starting — \(pending.count) item(s) pending")
        drainTask = Task {
            for item in pending {
                let fileURL = queueDirectory.appendingPathComponent(item.imageFilename)
                guard let data = try? Data(contentsOf: fileURL) else {
                    NSLog("[JobPhotoQueue] drain: file missing for \(item.imageFilename) — discarding stale entry")
                    items = items.filter { $0.id != item.id }
                    continue
                }
                do {
                    try await uploadHandler(data, item.visitId, item.photoType)
                    items = items.filter { $0.id != item.id }
                    try? FileManager.default.removeItem(at: fileURL)
                    NSLog("[JobPhotoQueue] drain: uploaded \(item.photoType.rawValue) for visit \(item.visitId)")
                } catch {
                    NSLog("[JobPhotoQueue] drain: upload failed for visit \(item.visitId) — will retry on next network event: \(error)")
                    break  // Network still down — leave the rest queued
                }
            }
            NSLog("[JobPhotoQueue] drain complete — \(items.count) item(s) remaining")
            drainTask = nil
        }
        await drainTask?.value
    }
}
