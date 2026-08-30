//
//  JobPhotoQueue.swift
//  MowologyCRM
//
//  Disk-backed offline queue for job before/after photo uploads.
//  Image bytes go to QueueStorage (durable, non-purgeable); metadata is
//  stored in UserDefaults.
//  Call drain(using:) when connectivity is restored (TimeClockViewModel and
//  JobPhotoViewModel both observe mwPingQueueOnline to do this automatically).
//

import Foundation

@MainActor
final class JobPhotoQueue: ObservableObject {

    static let shared = JobPhotoQueue()

    @Published private(set) var pendingCount: Int = 0

    private let defaults = UserDefaults.standard
    private let storeKey = "mw.jobphoto.queue.v1"

    private init() { refreshCount() }

    // MARK: - Pending Item

    struct PendingItem: Codable {
        let id: String           // idempotency / file key
        let imageFilename: String
        let visitId: Int
        let photoType: String    // JobPhotoType.rawValue — "before" or "after"
        let queuedAt: Date
    }

    // MARK: - Persistence

    private var items: [PendingItem] {
        get {
            guard let data    = defaults.data(forKey: storeKey),
                  let decoded = try? JSONDecoder().decode([PendingItem].self, from: data)
            else { return [] }
            return decoded
        }
        set {
            defaults.set(try? JSONEncoder().encode(newValue), forKey: storeKey)
            refreshCount()
        }
    }

    private func refreshCount() { pendingCount = items.count }

    // MARK: - Enqueue

    /// Saves an image to disk and records the pending upload.
    /// Replaces any existing queued item for the same visit + slot so there is
    /// always at most one pending upload per slot (the most recent capture).
    func enqueue(imageData: Data, visitId: Int, photoType: JobPhotoType) {
        // Remove any stale entry for this slot and clean up its file.
        var current = items
        for stale in current where stale.visitId == visitId && stale.photoType == photoType.rawValue {
            QueueStorage.remove(stale.imageFilename)
        }
        current.removeAll { $0.visitId == visitId && $0.photoType == photoType.rawValue }

        // Write the new image file.
        let id       = UUID().uuidString
        let filename = "jobphoto-\(id).jpg"
        QueueStorage.write(imageData, filename: filename)

        current.append(PendingItem(
            id:            id,
            imageFilename: filename,
            visitId:       visitId,
            photoType:     photoType.rawValue,
            queuedAt:      Date()
        ))
        items = current
    }

    /// Returns true if there is a photo queued for this visit + slot.
    func hasQueued(visitId: Int, photoType: JobPhotoType) -> Bool {
        items.contains { $0.visitId == visitId && $0.photoType == photoType.rawValue }
    }

    // MARK: - Drain

    /// Attempts to upload all queued photos using the provided APIClient.
    /// Stops on the first network error to preserve order; non-network server
    /// errors (e.g. duplicate upload) are discarded so they don't block the queue.
    func drain(using apiClient: APIClient) async {
        let pending = items
        for item in pending {
            guard let data = QueueStorage.read(item.imageFilename),
                  let slot = JobPhotoType(rawValue: item.photoType)
            else {
                // File missing or type unknown — discard. A missing file here means
                // the payload was purged or never written; log it, because it is a
                // silent loss of photo proof and the only signal we get.
                print("[JobPhotoQueue] Discarding visit \(item.visitId) \(item.photoType) — payload unreadable")
                items = items.filter { $0.id != item.id }
                continue
            }

            do {
                try await apiClient.uploadJobPhoto(imageData: data, visitId: item.visitId, photoType: slot)
                items = items.filter { $0.id != item.id }
                QueueStorage.remove(item.imageFilename)
            } catch let err as APIError {
                if case .networkError = err {
                    // Still offline — preserve queue, stop draining.
                    return
                }
                // Server rejected (duplicate, visit closed, etc.) — discard and continue.
                items = items.filter { $0.id != item.id }
                QueueStorage.remove(item.imageFilename)
            } catch {
                return
            }
        }
    }
}
