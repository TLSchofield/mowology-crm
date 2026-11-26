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

    /// Quote visits for the day (2026-10-15) — their own file beside the stops, same key.
    func saveAppointments(_ appointments: [Appointment], forDate dateString: String) {
        guard let data = try? JSONEncoder().encode(appointments) else { return }
        try? data.write(to: cacheDir.appendingPathComponent("appointments-\(dateString).json"))
    }

    func loadAppointments(forDate dateString: String) -> [Appointment]? {
        guard let data = try? Data(contentsOf: cacheDir.appendingPathComponent("appointments-\(dateString).json")) else { return nil }
        return try? JSONDecoder().decode([Appointment].self, from: data)
    }

    // MARK: - Owner (shared phones)

    /// Everything cached here belongs to whoever signed in last. Sign-out empties it, and signing in as a
    /// different person empties it too (a crash or a 401 can skip sign-out), so on a shared phone nobody sees
    /// the previous person's offline schedule.
    func clear() {
        let fm = FileManager.default
        if let items = try? fm.contentsOfDirectory(at: cacheDir, includingPropertiesForKeys: nil) {
            for url in items { try? fm.removeItem(at: url) }
        }
        UserDefaults.standard.removeObject(forKey: Self.ownerKey)
    }

    func claim(for userId: Int) {
        let defaults = UserDefaults.standard
        if ScheduleCacheOwner.shouldClear(recordedOwner: defaults.object(forKey: Self.ownerKey) as? Int, signingIn: userId) { clear() }
        defaults.set(userId, forKey: Self.ownerKey)
    }

    private static let ownerKey = "mw.scheduleCache.ownerId"

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
