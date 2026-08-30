//
//  RecommendationQueue.swift
//  MowologyCRM
//
//  Crew are regularly out of signal on site. Without this a recommendation —
//  photos, service, note — would vanish the moment the request failed, and the
//  sale with it. Photo bytes go to QueueStorage (durable, non-purgeable);
//  metadata lives in UserDefaults. Drained on mwPingQueueOnline, same as JobPhotoQueue.
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

    /// Persist a recommendation that could not be sent. Unlike JobPhotoQueue
    /// these are additive — a crew member may legitimately recommend two
    /// different services on the same visit.
    func enqueue(visitId: Int, productId: Int, note: String, images: [Data]) {
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
            queuedAt: Date()
        ))
        items = current
    }

    // MARK: - Drain

    /// Retry every queued recommendation. Stops at the first network error so the
    /// queue survives to try again; anything the server actively rejected is
    /// discarded rather than retried forever.
    func drain(using apiClient: APIClient) async {
        var remaining = items

        while let item = remaining.first {
            do {
                var mediaIds: [Int] = []

                for filename in item.imageFilenames {
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
                        photoTypeRaw: "issue"
                    )
                    if let mediaId = response["media_id"] as? Int {
                        mediaIds.append(mediaId)
                    }
                }

                let _: RecommendationCreateResponse = try await apiClient.request(
                    .recommendationCreate,
                    body: [
                        "action":     "create",
                        "visit_id":   item.visitId,
                        "product_id": item.productId,
                        "note":       item.note,
                        "media_ids":  mediaIds
                    ]
                )

                cleanUpFiles(for: item)
                remaining.removeFirst()
                items = remaining

            } catch APIError.networkError {
                // Still offline — keep everything and try again on the next ping.
                return
            } catch {
                // Server rejected it (duplicate, deleted product, revoked access).
                // Retrying would never succeed, so drop it.
                cleanUpFiles(for: item)
                remaining.removeFirst()
                items = remaining
            }
        }
    }

    private func cleanUpFiles(for item: PendingItem) {
        for filename in item.imageFilenames {
            QueueStorage.remove(filename)
        }
    }
}
