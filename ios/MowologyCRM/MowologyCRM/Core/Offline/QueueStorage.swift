//
//  QueueStorage.swift
//  MowologyCRM
//
//  Durable on-disk storage for queued upload payloads (job photos, crew
//  recommendations).
//
//  Why this exists:
//    Both queues previously wrote their JPEG bytes to
//    FileManager.temporaryDirectory. iOS reclaims that directory under storage
//    pressure WITHOUT notifying the app, so a photo captured in a dead-signal
//    area could have its bytes deleted while the queue entry survived in
//    UserDefaults. The drain would then find no file and either discard the item
//    or post it with no evidence attached — a silent loss of revenue-critical
//    photo proof, which is exactly the failure mode the queue exists to prevent.
//
//    Application Support is not purgeable, so the bytes survive until we delete
//    them ourselves.
//
//  Deliberately NOT excluded from iCloud backup: Apple's guidance is to exclude
//  regenerable/cache-like data, and a photo of a site that the crew has already
//  driven away from cannot be regenerated. These files are small and short-lived.
//

import Foundation

enum QueueStorage {

    /// Durable directory for pending upload payloads. Created on first use.
    static var directory: URL {
        let base = (try? FileManager.default.url(
            for: .applicationSupportDirectory,
            in: .userDomainMask,
            appropriateFor: nil,
            create: true
        )) ?? FileManager.default.temporaryDirectory

        let dir = base.appendingPathComponent("UploadQueue", isDirectory: true)

        if !FileManager.default.fileExists(atPath: dir.path) {
            try? FileManager.default.createDirectory(
                at: dir, withIntermediateDirectories: true
            )
        }
        return dir
    }

    /// Where a newly written payload lives.
    static func url(for filename: String) -> URL {
        directory.appendingPathComponent(filename)
    }

    /// Legacy location, for items queued before this change shipped.
    private static func legacyURL(for filename: String) -> URL {
        FileManager.default.temporaryDirectory.appendingPathComponent(filename)
    }

    /// Persist payload bytes. Returns false if the write failed, so callers can
    /// avoid recording a queue entry whose file does not exist.
    @discardableResult
    static func write(_ data: Data, filename: String) -> Bool {
        do {
            try data.write(to: url(for: filename), options: .atomic)
            return true
        } catch {
            return false
        }
    }

    /// Read payload bytes, falling back to the old temporary-directory location so
    /// anything queued by a previous build still drains instead of being dropped.
    static func read(_ filename: String) -> Data? {
        if let data = try? Data(contentsOf: url(for: filename)) {
            return data
        }
        return try? Data(contentsOf: legacyURL(for: filename))
    }

    /// Delete a payload from both locations.
    static func remove(_ filename: String) {
        try? FileManager.default.removeItem(at: url(for: filename))
        try? FileManager.default.removeItem(at: legacyURL(for: filename))
    }
}
