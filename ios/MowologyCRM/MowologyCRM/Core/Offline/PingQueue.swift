//
//  PingQueue.swift
//  MowologyCRM
//

import Foundation
import SwiftData
import Network

// MARK: - PendingPing (SwiftData model)

/// A GPS ping that could not be delivered while offline.
/// Stored locally and flushed when connectivity returns.
@Model
final class PendingPing {
    var lat: Double
    var lng: Double
    var accuracy: Double
    var visitId: Int?
    var queuedAt: Date

    init(lat: Double, lng: Double, accuracy: Double, visitId: Int?) {
        self.lat       = lat
        self.lng       = lng
        self.accuracy  = accuracy
        self.visitId   = visitId
        self.queuedAt  = Date()
    }
}

// MARK: - PingQueue

/// Buffers GPS pings when the device is offline and drains them in
/// chronological order when connectivity returns.
///
/// - Pings older than 8 hours are discarded on drain (stale location
///   data has no operational value and would confuse the dispatch map).
/// - `NWPathMonitor` fires a Notification when connectivity is restored
///   so any active `VisitDetailViewModel` can drain immediately.
@MainActor
final class PingQueue: ObservableObject {

    static let shared = PingQueue()

    @Published private(set) var pendingCount: Int = 0
    @Published private(set) var isOnline: Bool = true

    private let container: ModelContainer
    private let monitor    = NWPathMonitor()
    private let monitorQ   = DispatchQueue(label: "ca.mowology.ping-monitor", qos: .utility)

    private init() {
        do {
            container = try ModelContainer(for: PendingPing.self)
        } catch {
            // Persistent store failed (schema migration or corrupt DB) — fall
            // back to in-memory so the app keeps running. Pings will be lost
            // on next launch but the app won't crash.
            let config = ModelConfiguration(isStoredInMemoryOnly: true)
            container = try! ModelContainer(for: PendingPing.self, configurations: config)
            print("[PingQueue] SwiftData init failed, using in-memory fallback: \(error)")
        }
        refreshCount()
        startMonitor()
    }

    // MARK: - Network Monitor

    private func startMonitor() {
        monitor.pathUpdateHandler = { [weak self] path in
            Task { @MainActor [weak self] in
                guard let self else { return }
                let wasOffline  = !self.isOnline
                self.isOnline   = path.status == .satisfied
                if wasOffline && self.isOnline {
                    NotificationCenter.default.post(
                        name: .mwPingQueueOnline, object: nil
                    )
                }
            }
        }
        monitor.start(queue: monitorQ)
    }

    // MARK: - Store

    /// Persist a ping that failed to send (called on network error).
    func store(lat: Double, lng: Double, accuracy: Double, visitId: Int?) {
        let ctx = ModelContext(container)
        ctx.insert(PendingPing(lat: lat, lng: lng, accuracy: accuracy, visitId: visitId))
        try? ctx.save()
        refreshCount()
    }

    // MARK: - Drain

    /// Send all queued pings oldest-first. Stops on the first failure
    /// to preserve ordering. Expired pings (> 8 h old) are deleted silently.
    func drain(using apiClient: APIClient) async {
        let ctx = ModelContext(container)
        guard let pings = try? ctx.fetch(
            FetchDescriptor<PendingPing>(sortBy: [SortDescriptor(\.queuedAt)])
        ), !pings.isEmpty else { return }

        let expiry = Date().addingTimeInterval(-8 * 3600)

        for ping in pings {
            if ping.queuedAt < expiry {
                ctx.delete(ping)
                continue
            }

            var body: [String: Any] = [
                "lat": ping.lat,
                "lng": ping.lng,
                "accuracy": ping.accuracy,
            ]
            if let vid = ping.visitId { body["visit_id"] = vid }

            struct Resp: Decodable { let success: Bool }
            if let resp = try? await apiClient.request(.scheduleLocation, body: body) as Resp,
               resp.success {
                ctx.delete(ping)
            } else {
                break   // preserve order — retry next time
            }
        }

        try? ctx.save()
        refreshCount()
    }

    // MARK: - Private

    private func refreshCount() {
        let ctx   = ModelContext(container)
        pendingCount = (try? ctx.fetchCount(FetchDescriptor<PendingPing>())) ?? 0
    }
}

// MARK: - Notification

extension Notification.Name {
    /// Posted by PingQueue on the main thread when connectivity is restored.
    static let mwPingQueueOnline = Notification.Name("ca.mowology.pingQueueOnline")
}
