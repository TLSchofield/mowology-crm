//
//  ScheduleCache.swift
//  MowologyCRM
//
//  Disk-backed cache for schedule stops, keyed by ISO date string.
//  Stored as JSON files in the system Caches directory — iOS may evict
//  these under storage pressure, so always treat a cache miss as non-fatal.
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

    // MARK: - Read / Write

    func save(_ stops: [Stop], forDate dateString: String) {
        guard let data = try? JSONEncoder().encode(stops) else { return }
        try? data.write(to: fileURL(for: dateString))
    }

    func load(forDate dateString: String) -> [Stop]? {
        let url = fileURL(for: dateString)
        guard let data  = try? Data(contentsOf: url),
              let stops = try? JSONDecoder().decode([Stop].self, from: data)
        else { return nil }
        return stops
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
