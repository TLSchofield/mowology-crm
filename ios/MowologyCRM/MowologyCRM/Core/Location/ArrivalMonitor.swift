//
//  ArrivalMonitor.swift
//  MowologyCRM
//
//  Detects when a crew member arrives at a job site by comparing incoming
//  GPS fixes against a set of monitored visit coordinates.
//
//  Feed fixes via observe(fix:) from the GPS ping loop.
//  When a fix falls within a site's radius, posts .mwArrivalDetected so
//  VisitDetailViewModel can surface an "auto-start timer" prompt.
//
//  Design notes:
//  - Pure distance math — no CLCircularRegion / geofence API.
//    Geofence region monitoring requires significant-location authorization
//    and adds OS-managed state that's complex to debug in the field.
//    A distance check per ping is cheaper and more predictable.
//  - notifiedVisitIds prevents repeated notifications for the same visit
//    within a single tracking session. Cleared on configure() (i.e., each
//    clock-in) so arrivals are re-detectable on a new shift.
//  - Radius default is 150 m, matching the Capacitor bridge (geofence.php).
//

import Foundation
import CoreLocation

// MARK: - MonitoredSite

struct MonitoredSite {
    let visitId:      Int
    let coordinate:   CLLocationCoordinate2D
    let radiusMeters: Double

    init(visitId: Int, coordinate: CLLocationCoordinate2D, radiusMeters: Double = 150) {
        self.visitId      = visitId
        self.coordinate   = coordinate
        self.radiusMeters = radiusMeters
    }
}

// MARK: - ArrivalMonitor

@MainActor
final class ArrivalMonitor {

    static let shared = ArrivalMonitor()

    // UNNotification identifiers used by AppDelegate
    static let actionClockIn     = "ca.mowology.action.clockIn"
    static let categoryArrival   = "ca.mowology.category.arrival"

    // MARK: - Private

    private var sites:            [MonitoredSite] = []
    private var notifiedVisitIds: Set<Int>        = []

    private init() {}

    // MARK: - Configuration

    /// Register the job sites to watch for the current shift.
    /// Typically called after the day's schedule loads (ScheduleViewModel).
    /// Replaces any previously registered sites and resets arrival state.
    func configure(sites: [MonitoredSite]) {
        self.sites           = sites
        notifiedVisitIds     = []
    }

    // MARK: - Observation

    /// Feed an incoming GPS fix. O(n) scan over registered sites.
    /// For typical day schedules (≤ 15 stops) this is negligible on-CPU.
    func observe(fix: CLLocation) {
        for site in sites {
            guard !notifiedVisitIds.contains(site.visitId) else { continue }

            let siteLocation = CLLocation(
                latitude:  site.coordinate.latitude,
                longitude: site.coordinate.longitude
            )

            if fix.distance(from: siteLocation) <= site.radiusMeters {
                notifiedVisitIds.insert(site.visitId)
                NotificationCenter.default.post(
                    name:     .mwArrivalDetected,
                    object:   nil,
                    userInfo: ["visitId": site.visitId]
                )
            }
        }
    }

    // MARK: - Reset

    /// Clear all sites and arrival state. Called on clock-out.
    func reset() {
        sites            = []
        notifiedVisitIds = []
    }
}

// MARK: - Notification Name

extension Notification.Name {
    /// Posted on the main thread when the crew arrives at a monitored job site.
    /// UserInfo key `"visitId"` contains the `Int` visit ID.
    static let mwArrivalDetected = Notification.Name("ca.mowology.arrivalDetected")
}
