//
//  JobPhotoQueue.swift
//  MowologyCRM
//
//  Disk-backed offline queue for job before/after photo uploads.
//  Image bytes go to QueueStorage (durable, non-purgeable); metadata is
//  stored in UserDefaults.
//  Drained by AppPhotoQueueDrainService (reconnect + foreground); screens join that run.
//
//  Rules (2026-10-12):
//  - one drain at a time: a second caller waits for the run in progress (two runs over the
//    same snapshot uploaded every photo twice → duplicate before/after rows);
//  - a photo leaves the phone only when the server stored it ({"success": true}) or refused
//    it with a 4xx. No signal, an expired login, a 5xx or an unreadable answer (PHP fatal =
//    truncated HTTP 200) keep it; a server fault backs that one photo off and moves on;
//  - each photo carries who took it; only that person's login sends it (nil = older build).
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
        let photoType: String    // JobPhotoType.rawValue — before | after | additional
        let queuedAt: Date
        /// Who took it (nil = queued by a build before 2026-10-12).
        var userId: Int? = nil
        /// Server faults so far, and when this photo may be tried again.
        var attempts: Int? = nil
        var nextTryAt: Date? = nil
    }

    private var running: Task<Void, Never>?

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
    /// Before/after are single slots: a retake replaces the queued capture. Extra photos
    /// are a list — every one is kept (a salting run queues four or more with no signal).
    func enqueue(imageData: Data, visitId: Int, photoType: JobPhotoType, userId: Int?) {
        var current = items
        if photoType.isSingleSlot {
            for stale in current where stale.visitId == visitId && stale.photoType == photoType.rawValue
                && stale.userId == userId {
                QueueStorage.remove(stale.imageFilename)
            }
            current.removeAll { $0.visitId == visitId && $0.photoType == photoType.rawValue && $0.userId == userId }
        }

        // Write the new image file. No file, no queue entry — an entry without bytes is a lie.
        let id       = UUID().uuidString
        let filename = "jobphoto-\(id).jpg"
        guard QueueStorage.write(imageData, filename: filename) else {
            print("[JobPhotoQueue] Could not save visit \(visitId) \(photoType.rawValue) to disk")
            return
        }

        current.append(PendingItem(
            id:            id,
            imageFilename: filename,
            visitId:       visitId,
            photoType:     photoType.rawValue,
            queuedAt:      Date(),
            userId:        userId
        ))
        items = current
    }

    /// Returns true if there is a photo queued for this visit + slot.
    func hasQueued(visitId: Int, photoType: JobPhotoType) -> Bool {
        items.contains { $0.visitId == visitId && $0.photoType == photoType.rawValue }
    }

    /// How many photos of this type are still waiting for signal.
    func queuedCount(visitId: Int, photoType: JobPhotoType) -> Int {
        items.filter { $0.visitId == visitId && $0.photoType == photoType.rawValue }.count
    }

    // MARK: - Drain

    /// Uploads the signed-in person's queued photos. One run at a time: a caller arriving
    /// mid-run waits for that run instead of starting a second over the same snapshot.
    func drain(using apiClient: APIClient) async {
        if let running { return await running.value }
        let task = Task { await self.drainOnce(using: apiClient) }
        running = task
        await task.value
        running = nil
    }

    private func drainOnce(using apiClient: APIClient) async {
        guard let me = apiClient.currentUserId else { return }
        let now = Date()
        let pending = items.filter { ($0.userId == nil || $0.userId == me) && ($0.nextTryAt ?? .distantPast) <= now }

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
                try await apiClient.uploadJobPhoto(imageData: data, visitId: item.visitId, photoType: slot, queuedAt: item.queuedAt)
                items = items.filter { $0.id != item.id }
                QueueStorage.remove(item.imageFilename)
            } catch where UploadFault.matches(error) {
                // The server answered but did not decide (5xx, truncated 200). Keep the photo,
                // back this one off (1, 2, 4 … 60 min) and carry on with the rest.
                let tries = (item.attempts ?? 0) + 1
                let wait  = min(60.0 * pow(2.0, Double(tries - 1)), 3600)
                print("[JobPhotoQueue] visit \(item.visitId) \(item.photoType) server fault (try \(tries)): \(error)")
                items = items.map {
                    guard $0.id == item.id else { return $0 }
                    var c = $0; c.attempts = tries; c.nextTryAt = Date().addingTimeInterval(wait); return c
                }
            } catch APIError.serverError(let message) {
                // An explicit 4xx refusal (visit deleted, not your visit) — retrying can't help.
                print("[JobPhotoQueue] visit \(item.visitId) \(item.photoType) refused: \(message) — discarded")
                items = items.filter { $0.id != item.id }
                QueueStorage.remove(item.imageFilename)
            } catch {
                // No signal, or the login expired: keep everything and stop.
                return
            }
        }
    }
}
