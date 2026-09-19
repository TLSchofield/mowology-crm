//
//  FixStore.swift
//  MowologyCRM
//
//  Every accepted GPS fix goes here FIRST — online or not — and leaves only when the
//  server names its id in `accepted` (or rejects it for good). That is the whole
//  point: the previous pipeline posted fixes directly, queued only on a network
//  error, replayed them without their timestamps, and deleted them when the server
//  answered "success, skipped". A day in a dead zone came back as one dot.
//
//  A JSON file rather than SwiftData: the record is tiny, append-mostly, and a
//  schema change must never be able to strand a shift's worth of fixes.
//

import CoreLocation
import Foundation

struct StoredFix: Codable, Identifiable {
    let id: String              // UUID — makes replay idempotent server-side
    let t: Int64                // DEVICE fix time, epoch ms
    let lat: Double
    let lng: Double
    let acc: Double
    let speed: Double?
    let heading: Double?
    let mock: Bool
    let visitId: Int?
    let tier: String

    var payload: [String: Any] {
        var d: [String: Any] = ["id": id, "t": t, "lat": lat, "lng": lng, "acc": acc, "mock": mock, "tier": tier]
        if let speed   { d["speed"]    = speed }
        if let heading { d["heading"]  = heading }
        if let visitId { d["visit_id"] = visitId }
        return d
    }
}

@MainActor
final class FixStore {

    static let shared = FixStore()

    /// Roughly a 10-hour shift at the enhanced cadence, several times over.
    private let maxFixes = 6_000
    /// Matches the server's replay horizon (TrackingIngestService::MAX_AGE_SECONDS).
    private let maxAge: TimeInterval = 72 * 3600

    private(set) var fixes: [StoredFix] = []
    private var dirty = false
    private var flushTask: Task<Void, Never>?

    private let fileURL: URL = {
        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        var url = dir.appendingPathComponent("mw-fix-store.json")
        // Location history has no business in an iCloud device backup.
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try? url.setResourceValues(values)
        return url
    }()

    private init() {
        if let data = try? Data(contentsOf: fileURL),
           let saved = try? JSONDecoder().decode([StoredFix].self, from: data) {
            fixes = saved
        }
        prune()
    }

    var count: Int { fixes.count }

    func append(_ location: CLLocation, visitId: Int?, tier: TrackingTier) {
        var simulated = false
        if let info = location.sourceInformation { simulated = info.isSimulatedBySoftware }

        fixes.append(StoredFix(
            id:      UUID().uuidString.lowercased(),
            t:       Int64(location.timestamp.timeIntervalSince1970 * 1000),
            lat:     location.coordinate.latitude,
            lng:     location.coordinate.longitude,
            acc:     location.horizontalAccuracy,
            speed:   location.speed >= 0 ? location.speed : nil,
            heading: location.course >= 0 ? location.course : nil,
            mock:    simulated,
            visitId: visitId,
            tier:    tier.rawValue
        ))
        if fixes.count > maxFixes { fixes.removeFirst(fixes.count - maxFixes) }
        scheduleFlush()
    }

    /// Oldest first, so the server sees a trail in order.
    func nextBatch(limit: Int = 150) -> [StoredFix] {
        Array(fixes.prefix(limit))
    }

    func remove(ids: Set<String>) {
        guard !ids.isEmpty else { return }
        fixes.removeAll { ids.contains($0.id) }
        scheduleFlush()
    }

    /// Everything is dropped when the person signs out — it is their data, not the next user's.
    func removeAll() {
        fixes.removeAll()
        flushNow()
    }

    private func prune() {
        let cutoff = Int64((Date().timeIntervalSince1970 - maxAge) * 1000)
        let before = fixes.count
        fixes.removeAll { $0.t < cutoff }
        if fixes.count != before { scheduleFlush() }
    }

    // MARK: - Persistence (coalesced — a fix every few seconds must not mean a write every few seconds)

    private func scheduleFlush() {
        dirty = true
        guard flushTask == nil else { return }
        flushTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 3_000_000_000)
            self?.flushNow()
        }
    }

    func flushNow() {
        flushTask?.cancel()
        flushTask = nil
        guard dirty || fixes.isEmpty else { return }
        dirty = false
        if let data = try? JSONEncoder().encode(fixes) {
            try? data.write(to: fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
        }
    }
}
