//
//  RouteStore.swift
//  MowologyCRM
//
//  Persists GPS breadcrumbs per visit using SwiftData.
//  These points feed the visit timeline on the CRM dashboard and provide
//  a local audit trail independent of the server-side proof-of-work chain.
//
//  Design notes:
//  - Mirrors the PingQueue SwiftData pattern already established in this app.
//  - record() is fire-and-forget from the GPS ping loop; it must never throw.
//  - purge() is called on clock-out to keep local storage bounded.
//  - No network sync here — the GPS ping loop already uploads fixes live;
//    RouteStore is purely a local breadcrumb trail for diagnostic/replay.
//

import Foundation
import CoreLocation
import SwiftData

// MARK: - RoutePoint (SwiftData model)

@Model
final class RoutePoint {
    var visitId:    Int
    var lat:        Double
    var lng:        Double
    var accuracy:   Double
    var recordedAt: Date

    init(visitId: Int, lat: Double, lng: Double, accuracy: Double) {
        self.visitId    = visitId
        self.lat        = lat
        self.lng        = lng
        self.accuracy   = accuracy
        self.recordedAt = Date()
    }
}

// MARK: - RouteStore

@MainActor
final class RouteStore {

    static let shared = RouteStore()

    private let container: ModelContainer

    private init() {
        do {
            container = try ModelContainer(for: RoutePoint.self)
        } catch {
            // Persistent store failed (schema change, corrupt DB) — fall back
            // to in-memory so the ping loop keeps running without crashing.
            let cfg = ModelConfiguration(isStoredInMemoryOnly: true)
            container = try! ModelContainer(for: RoutePoint.self,
                                            configurations: cfg)
            print("[RouteStore] SwiftData init failed, in-memory fallback: \(error)")
        }
    }

    // MARK: - Record

    /// Append a GPS fix for the given visit.
    /// Silently drops fixes with invalid accuracy (horizontal accuracy < 0).
    func record(visitId: Int, location: CLLocation) {
        guard location.horizontalAccuracy >= 0 else { return }
        let accuracy = location.horizontalAccuracy
        let ctx = ModelContext(container)
        ctx.insert(RoutePoint(
            visitId:  visitId,
            lat:      location.coordinate.latitude,
            lng:      location.coordinate.longitude,
            accuracy: accuracy
        ))
        try? ctx.save()
    }

    // MARK: - Purge

    /// Delete points older than the given interval.
    /// Default is 48 hours — long enough to cover an overnight re-sync,
    /// short enough to keep the local store from growing unbounded.
    func purge(olderThan interval: TimeInterval = 48 * 3600) {
        let cutoff = Date().addingTimeInterval(-interval)
        let ctx = ModelContext(container)
        guard let stale = try? ctx.fetch(
            FetchDescriptor<RoutePoint>(
                predicate: #Predicate { $0.recordedAt < cutoff }
            )
        ) else { return }
        stale.forEach { ctx.delete($0) }
        try? ctx.save()
    }

    // MARK: - Read (for diagnostics / future replay)

    /// Return all points for a visit, ordered by recording time.
    func points(forVisit visitId: Int) -> [RoutePoint] {
        let ctx = ModelContext(container)
        let vid = visitId
        return (try? ctx.fetch(
            FetchDescriptor<RoutePoint>(
                predicate:  #Predicate { $0.visitId == vid },
                sortBy:     [SortDescriptor(\.recordedAt)]
            )
        )) ?? []
    }
}
