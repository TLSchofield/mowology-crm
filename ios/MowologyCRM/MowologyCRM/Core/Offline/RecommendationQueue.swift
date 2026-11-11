//
//  RecommendationQueue.swift
//  MowologyCRM
//
//  Crew are regularly out of signal on site. Without this a recommendation —
//  photos, service, note — would vanish the moment the request failed, and the
//  sale with it. Photo bytes go to QueueStorage (durable, non-purgeable);
//  metadata lives in UserDefaults. Drained on mwPingQueueOnline, same as JobPhotoQueue.
//
//  Rules (2026-10-12): one drain at a time; an item leaves only when the server created the
//  recommendation or refused it with a 4xx (no signal, expired login, 5xx or an unreadable
//  answer keep it, a fault backs that item off); each uploaded photo's media id is saved on
//  the item so a retry never uploads it again; only the person who queued it sends it.
//

import Foundation

@MainActor
final class RecommendationQueue: ObservableObject {

    static let shared = RecommendationQueue()

    @Published private(set) var pendingCount: Int = 0

    private let defaults = UserDefaults.standard
    private let storeKey = "mw.recommendation.queue.v1"

    private init() { refreshCount() }

    // MARK: - Pending Item

    struct PendingItem: Codable {
        let id: String
        let visitId: Int
        let productId: Int
        let note: String
        /// Filenames in the temp directory — uploaded on drain to obtain media_ids.
        let imageFilenames: [String]
        let queuedAt: Date
        /// "ask" or "quote". Optional so items queued by older builds (no key) still
        /// decode — they replay as "quote", which is what they were. An ask replays as a
        /// draft only: the server never sends one without a manager reading it.
        var intent: String? = nil
        /// Who queued it (nil = older build) — never sent under someone else's login.
        var userId: Int? = nil
        /// filename → media id for photos already stored on the server.
        var uploaded: [String: Int]? = nil
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

    /// Persist a recommendation that could not be sent. Unlike JobPhotoQueue
    /// these are additive — a crew member may legitimately recommend two
    /// different services on the same visit.
    func enqueue(visitId: Int, productId: Int, note: String, images: [Data], intent: String = "quote", userId: Int?) {
        var filenames: [String] = []

        for data in images {
            let name = "mw-reco-\(UUID().uuidString).jpg"
            if QueueStorage.write(data, filename: name) {
                filenames.append(name)
            }
        }

        var current = items
        current.append(PendingItem(
            id: UUID().uuidString,
            visitId: visitId,
            productId: productId,
            note: note,
            imageFilenames: filenames,
            queuedAt: Date(),
            intent: intent,
            userId: userId
        ))
        items = current
    }

    // MARK: - Drain

    /// Retry the signed-in person's queued recommendations. One run at a time: a second caller
    /// waits for the run in progress.
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
            var uploaded = item.uploaded ?? [:]
            do {
                for filename in item.imageFilenames where uploaded[filename] == nil {
                    guard let data = QueueStorage.read(filename) else {
                        // Payload purged or never written — the photo is gone. Log
                        // rather than silently sending a recommendation with no
                        // evidence attached.
                        print("[RecommendationQueue] Missing payload \(filename) for visit \(item.visitId)")
                        continue
                    }

                    let response = try await apiClient.uploadVisitPhoto(
                        imageData: data,
                        visitId: item.visitId,
                        photoTypeRaw: "issue",
                        queuedAt: item.queuedAt
                    )
                    if let mediaId = response["media_id"] as? Int {
                        uploaded[filename] = mediaId
                        // Saved now, so losing signal before the create never re-uploads it.
                        update(item.id) { $0.uploaded = uploaded }
                    }
                }

                let mediaIds = item.imageFilenames.compactMap { uploaded[$0] }
                let _: RecommendationCreateResponse = try await apiClient.request(
                    .recommendationCreate,
                    body: [
                        "action":     "create",
                        "intent":     item.intent ?? "quote",
                        "visit_id":   item.visitId,
                        "product_id": item.productId,
                        "note":       item.note,
                        "media_ids":  mediaIds
                    ],
                    faultsAreTransient: true
                )

                finish(item)

            } catch where UploadFault.matches(error) {
                // Server answered but did not decide — keep it, back off this item, carry on.
                let tries = (item.attempts ?? 0) + 1
                let wait  = min(60.0 * pow(2.0, Double(tries - 1)), 3600)
                print("[RecommendationQueue] visit \(item.visitId) server fault (try \(tries)): \(error)")
                update(item.id) { $0.attempts = tries; $0.nextTryAt = Date().addingTimeInterval(wait) }
            } catch APIError.serverError(let message) {
                // Explicit 4xx refusal (deleted product, revoked access) — retrying can't help.
                print("[RecommendationQueue] visit \(item.visitId) refused: \(message) — discarded")
                finish(item)
            } catch {
                // No signal or the login expired — keep everything and try again later.
                return
            }
        }
    }

    private func update(_ id: String, _ change: (inout PendingItem) -> Void) {
        items = items.map { guard $0.id == id else { return $0 }; var c = $0; change(&c); return c }
    }

    private func finish(_ item: PendingItem) {
        cleanUpFiles(for: item)
        items = items.filter { $0.id != item.id }
    }

    private func cleanUpFiles(for item: PendingItem) {
        for filename in item.imageFilenames {
            QueueStorage.remove(filename)
        }
    }
}
