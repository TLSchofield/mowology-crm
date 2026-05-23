//
//  ScheduleCache.swift
//  MowologyCRM
//
//  Disk-backed cache for schedule stops, keyed by ISO date string.
//  Stored as JSON files in the system Caches directory — iOS may evict
//  these under storage pressure, so always treat a cache miss as non-fatal.
//
//  Each cached day persists the stops alongside an optional server checksum
//  (used by the prefetch freshness handshake) and a cachedAt timestamp.
//

import Foundation

@MainActor
final class ScheduleCache {

    static let shared = ScheduleCache()

    private let cacheDir: URL

    private init() {
        cacheDir = FileManager.default
            .urls(for: .cachesDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("mw-schedule", isDirectory: true)
        try? FileManager.default.createDirectory(at: cacheDir, withIntermediateDirectories: true)
    }

    // MARK: - Disk format

    /// Wrapper persisted to disk for each cached day. New format as of the
    /// prefetch freshness handshake — `load()` still accepts the legacy bare
    /// `[Stop]` array for files written before this change.
    private struct CachedDay: Codable {
        let stops: [Stop]
        let checksum: String?
        let cachedAt: Date
    }

    // MARK: - Read / Write

    /// Persist stops for a date. Pass the server's freshness checksum when
    /// available so a future prefetch can skip re-fetching unchanged days.
    func save(_ stops: [Stop], forDate dateString: String, checksum: String? = nil) {
        let payload = CachedDay(stops: stops, checksum: checksum, cachedAt: Date())
        guard let data = try? JSONEncoder().encode(payload) else { return }
        try? data.write(to: fileURL(for: dateString))
    }

    func load(forDate dateString: String) -> [Stop]? {
        let url = fileURL(for: dateString)
        guard let data = try? Data(contentsOf: url) else { return nil }

        if let wrapped = try? JSONDecoder().decode(CachedDay.self, from: data) {
            return wrapped.stops
        }
        // Legacy: pre-handshake cache files were a bare [Stop] array.
        if let stops = try? JSONDecoder().decode([Stop].self, from: data) {
            return stops
        }
        return nil
    }

    /// Returns the server checksum previously stored alongside this day's stops,
    /// or `nil` if no checksum was recorded (legacy file or no prior save).
    func checksum(forDate dateString: String) -> String? {
        let url = fileURL(for: dateString)
        guard let data = try? Data(contentsOf: url),
              let wrapped = try? JSONDecoder().decode(CachedDay.self, from: data)
        else { return nil }
        return wrapped.checksum
    }

    // MARK: - Maintenance

    /// Deletes cached files older than `days`. Safe to call from any context.
    nonisolated func evictOlderThan(days: Int = 14) {
        Task.detached(priority: .background) {
            let fm     = FileManager.default
            let dir    = fm.urls(for: .cachesDirectory, in: .userDomainMask)[0]
                .appendingPathComponent("mw-schedule")
            let cutoff = Date().addingTimeInterval(-Double(days * 86_400))
            guard let items = try? fm.contentsOfDirectory(
                at: dir,
                includingPropertiesForKeys: [.creationDateKey],
                options: .skipsHiddenFiles
            ) else { return }
            for url in items {
                if let created = (try? url.resourceValues(forKeys: [.creationDateKey]))?.creationDate,
                   created < cutoff {
                    try? fm.removeItem(at: url)
                }
            }
        }
    }

    // MARK: - Private

    private func fileURL(for dateString: String) -> URL {
        cacheDir.appendingPathComponent("schedule-\(dateString).json")
    }
}
