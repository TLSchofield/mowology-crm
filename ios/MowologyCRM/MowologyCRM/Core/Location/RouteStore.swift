//
//  RouteStore.swift
//  MowologyCRM
//
//  Stores one GPS fix per minute per active visit for route-replay in the
//  dispatcher map. Retains points for 7 days then auto-purges.
//

import Foundation
import SwiftData
import CoreLocation

// MARK: - RoutePoint

@Model
final class RoutePoint {
    var visitId:   Int
    var lat:       Double
    var lng:       Double
    var accuracy:  Double
    var heading:   Double   // degrees clockwise from north; -1 when unavailable
    var speed:     Double   // m/s; -1 when unavailable
    var timestamp: Date

    init(visitId: Int, location: CLLocation) {
        self.visitId   = visitId
        self.lat       = location.coordinate.latitude
        self.lng       = location.coordinate.longitude
        self.accuracy  = max(0, location.horizontalAccuracy)
        self.heading   = location.course  >= 0 ? location.course  : -1
        self.speed     = location.speed   >= 0 ? location.speed   : -1
        self.timestamp = location.timestamp
    }
}

// MARK: - RouteStore

/// Thread-safe (MainActor) route-point store.
/// One point per minute per visit, 7-day retention, max ~600 points/shift.
@MainActor
final class RouteStore {

    static let shared = RouteStore()

    private let container: ModelContainer
    // Last stored timestamp per visitId — prevents double-storage within the same minute.
    private var lastRecordTime: [Int: Date] = [:]
    private let minInterval: TimeInterval   = 60   // seconds between stored points

    private init() {
        container = try! ModelContainer(for: RoutePoint.self)
        purgeExpired()
    }

    // MARK: - Record

    /// Store a fix for this visit if ≥60 s have elapsed since the last stored point.
    /// Call from the ping loop inside `VisitDetailViewModel`.
    func record(visitId: Int, location: CLLocation) {
        let now = Date()
        if let last = lastRecordTime[visitId],
           now.timeIntervalSince(last) < minInterval { return }
        lastRecordTime[visitId] = now

        let ctx = ModelContext(container)
        ctx.insert(RoutePoint(visitId: visitId, location: location))
        try? ctx.save()
    }

    // MARK: - Query

    /// Returns all stored route points for a visit, oldest first.
    func points(for visitId: Int) -> [RoutePoint] {
        let ctx  = ModelContext(container)
        let desc = FetchDescriptor<RoutePoint>(
            predicate: #Predicate { $0.visitId == visitId },
            sortBy:    [SortDescriptor(\.timestamp)]
        )
        return (try? ctx.fetch(desc)) ?? []
    }

    /// Returns the most-recent point for a visit, or nil.
    func lastPoint(for visitId: Int) -> RoutePoint? {
        points(for: visitId).last
    }

    // MARK: - Cleanup

    /// Delete points older than `days` days. Called once on init.
    private func purgeExpired(days: Int = 7) {
        let ctx    = ModelContext(container)
        let cutoff = Date().addingTimeInterval(-Double(days) * 24 * 3600)
        let all    = (try? ctx.fetch(FetchDescriptor<RoutePoint>())) ?? []
        for pt in all where pt.timestamp < cutoff { ctx.delete(pt) }
        try? ctx.save()
    }
}
