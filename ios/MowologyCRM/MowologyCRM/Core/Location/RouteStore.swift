//
//  RouteStore.swift
//  MowologyCRM
//
//  In-memory GPS waypoints per visit. Server pings are the persistent record;
//  this store exists for in-session map rendering only.
//

import Foundation
import CoreLocation

struct RoutePoint {
    let visitId: Int
    let location: CLLocation
    let timestamp: Date
}

@MainActor
final class RouteStore {

    static let shared = RouteStore()
    private var points: [Int: [RoutePoint]] = [:]
    private init() {}

    func record(visitId: Int, location: CLLocation) {
        var existing = points[visitId] ?? []
        existing.append(RoutePoint(visitId: visitId, location: location, timestamp: .now))
        points[visitId] = existing
    }

    func points(for visitId: Int) -> [RoutePoint] { points[visitId] ?? [] }

    func clearAll() { points.removeAll() }
}
