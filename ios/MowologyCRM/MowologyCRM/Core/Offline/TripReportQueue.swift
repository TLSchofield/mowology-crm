//
//  TripReportQueue.swift
//  MowologyCRM
//
//  The driver is personally responsible for their vehicle log, so the app must never be
//  the reason one doesn't exist. The server is tried first; THIS is the backup. When a
//  declaration, pre-trip or post-trip can't reach the server it is written here — with the
//  time it was actually done — so no signal at the yard at 6 a.m. costs the driver nothing:
//  the inspection is kept, and filed under its real time when the phone is next in range.
//
//  Entries are sent strictly in order (a post-trip cannot be filed before its pre-trip) and
//  leave the queue only on a definite answer. The server treats a replay as the same
//  inspection, so re-sending after a dropped response can never create a second trip.
//

import Foundation

struct PendingTripAction: Codable, Identifiable {
    let id: String
    /// Whose log this is. A queued entry is only ever filed under its owner's login —
    /// never by whoever happens to sign in on this phone next.
    let userId: Int
    /// declare | pre_trip | post_trip
    let action: String
    /// JSON-encoded request body (without performed_at — added at send time from `performedAt`).
    let bodyJSON: Data
    /// When the driver actually did it — device clock, epoch ms.
    let performedAt: Int64
    /// Set when the SERVER refused it (not a network failure). Kept and shown; never dropped silently.
    var rejection: String?

    // Enough to rebuild the on-screen state while offline.
    let vehicleId: String?
    let odometerStart: Int?
    let mayDrive: Bool?
}

@MainActor
final class TripReportQueue: ObservableObject {

    static let shared = TripReportQueue()

    @Published private(set) var items: [PendingTripAction] = []

    private let fileURL: URL = {
        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent("mw-trip-report-queue.json")
    }()

    private init() {
        if let data = try? Data(contentsOf: fileURL),
           let saved = try? JSONDecoder().decode([PendingTripAction].self, from: data) {
            items = saved
        }
    }

    func waitingCount(for userId: Int) -> Int { items.filter { $0.userId == userId && $0.rejection == nil }.count }
    func rejected(for userId: Int) -> [PendingTripAction] { items.filter { $0.userId == userId && $0.rejection != nil } }
    func pending(for userId: Int) -> [PendingTripAction] { items.filter { $0.userId == userId && $0.rejection == nil } }

    /// What the queue implies about the trip, overriding the server's last-known status:
    /// a queued pre-trip with no later post-trip = a trip is open; a queued post-trip = closed.
    func impliedOpenTrip(for userId: Int) -> PendingTripAction? {
        var open: PendingTripAction?
        for item in pending(for: userId) {
            if item.action == "pre_trip"  { open = item }
            if item.action == "post_trip" { open = nil }
        }
        return open
    }
    func impliesClosed(for userId: Int) -> Bool {
        pending(for: userId).last(where: { $0.action == "pre_trip" || $0.action == "post_trip" })?.action == "post_trip"
    }

    func enqueue(userId: Int, action: String, body: [String: Any], vehicleId: String? = nil,
                 odometerStart: Int? = nil, mayDrive: Bool? = nil) {
        guard let json = try? JSONSerialization.data(withJSONObject: body) else { return }
        items.append(PendingTripAction(
            id: UUID().uuidString, userId: userId, action: action, bodyJSON: json,
            performedAt: Int64(Date().timeIntervalSince1970 * 1000),
            rejection: nil, vehicleId: vehicleId, odometerStart: odometerStart, mayDrive: mayDrive
        ))
        persist()
    }

    func remove(_ id: String) {
        items.removeAll { $0.id == id }
        persist()
    }

    func markRejected(_ id: String, message: String) {
        guard let i = items.firstIndex(where: { $0.id == id }) else { return }
        items[i].rejection = message
        persist()
    }

    /// The driver has dealt with a refused entry another way (paper log, office correction).
    func discardRejected(for userId: Int) {
        items.removeAll { $0.userId == userId && $0.rejection != nil }
        persist()
    }
    // Deliberately no "remove all on sign-out": an unsent legal log survives a sign-out and
    // is filed the next time ITS OWNER signs in on this phone.

    func requestBody(for item: PendingTripAction) -> [String: Any] {
        var body = (try? JSONSerialization.jsonObject(with: item.bodyJSON)) as? [String: Any] ?? [:]
        body["performed_at"] = item.performedAt
        // The device already made the driver confirm an unusual odometer reading; there is
        // nobody to ask again when this is finally filed.
        if item.action == "post_trip" { body["confirm_odometer"] = true }
        return body
    }

    private func persist() {
        if let data = try? JSONEncoder().encode(items) {
            try? data.write(to: fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
        }
    }
}
