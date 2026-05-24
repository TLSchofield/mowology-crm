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
///
/// `fixTimestamp` is the CLLocation capture time — distinct from `queuedAt`
/// (when we tried to send) and from the server-side receive time. Sending
/// `fixTimestamp` to the server lets drained queues reconstruct accurate
/// chronological trails instead of all collapsing to "now" on drain.
@Model
final class PendingPing {
    var lat: Double
    var lng: Double
    var accuracy: Double
    var visitId: Int?
    var queuedAt: Date
    /// When CoreLocation actually captured this fix (vs when we queued it).
    /// Nullable for backwards compatibility with pings queued before this
    /// field existed — drain falls back to `queuedAt` in that case.
    var fixTimestamp: Date?
    /// Optional CLLocation metrics (nil when CLLocation reported invalid).
    var speed:    Double?
    var course:   Double?
    var altitude: Double?

    init(lat: Double,
         lng: Double,
         accuracy: Double,
         visitId: Int?,
         fixTimestamp: Date?  = nil,
         speed:    Double?    = nil,
         course:   Double?    = nil,
         altitude: Double?    = nil)
    {
        self.lat          = lat
        self.lng          = lng
        self.accuracy     = accuracy
        self.visitId      = visitId
        self.queuedAt     = Date()
        self.fixTimestamp = fixTimestamp
        self.speed        = speed
        self.course       = course
        self.altitude     = altitude
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

    /// In-flight guard. `mwPingQueueOnline` can fire multiple times on
    /// flaky networks; without this, concurrent `drain()` calls could
    /// race on the ModelContext and double-post the same rows.
    /// MainActor isolation makes the read+set atomic.
    private var draining = false

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
    /// `fixTimestamp` and the optional metrics are forwarded to the server
    /// on drain so the trail reflects when the fix was captured, not when
    /// it was eventually delivered.
    func store(lat: Double,
               lng: Double,
               accuracy: Double,
               visitId: Int?,
               fixTimestamp: Date? = nil,
               speed:    Double?  = nil,
               course:   Double?  = nil,
               altitude: Double?  = nil)
    {
        let ctx = ModelContext(container)
        ctx.insert(PendingPing(
            lat: lat, lng: lng, accuracy: accuracy, visitId: visitId,
            fixTimestamp: fixTimestamp,
            speed: speed, course: course, altitude: altitude
        ))
        try? ctx.save()
        refreshCount()
    }

    // MARK: - Drain

    /// Send all queued pings oldest-first. Stops on the first failure
    /// to preserve ordering. Expired pings (> 8 h old) are deleted silently.
    ///
    /// Guarded by `draining` so concurrent invocations (e.g. multiple
    /// `mwPingQueueOnline` notifications on a flaky network) collapse
    /// to a single in-flight pass.
    func drain(using apiClient: APIClient) async {
        guard !draining else { return }
        draining = true
        defer { draining = false }

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

            // Send the original capture time so the server can timestamp
            // this row with when the fix actually happened, not when we
            // managed to drain it. Falls back to queuedAt for pings
            // queued before fixTimestamp existed (legacy on-disk rows).
            let captureTs = (ping.fixTimestamp ?? ping.queuedAt).timeIntervalSince1970

            var body: [String: Any] = [
                "lat":              ping.lat,
                "lng":              ping.lng,
                "accuracy":         ping.accuracy,
                "client_timestamp": captureTs,
            ]
            if let vid = ping.visitId  { body["visit_id"] = vid }
            if let v   = ping.speed    { body["speed"]    = v }
            if let v   = ping.course   { body["course"]   = v }
            if let v   = ping.altitude { body["altitude"] = v }

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
