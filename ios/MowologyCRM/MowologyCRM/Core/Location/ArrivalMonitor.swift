//
//  ArrivalMonitor.swift
//  MowologyCRM
//
//  Tracks the three dispatcher-trust metrics for a job visit:
//    1. Arrival Confidence — minutes the crew spent within 30 m before tapping Start
//    2. Dwell Time — minutes from Start to Complete
//    3. Accuracy Badge — worst GPS fix seen during the visit
//

import Foundation
import CoreLocation

// MARK: - AccuracyBadge

enum AccuracyBadge: String {
    case high   = "High Accuracy"   // all fixes ≤25 m
    case normal = "Normal"          // worst fix ≤50 m
    case verify = "Verify"          // any fix >75 m — dispatcher should cross-check
}

// MARK: - ArrivalMetrics

struct ArrivalMetrics {
    let arrivalTime:   Date?    // first fix within 30 m of job site
    let jobStartTime:  Date?    // when startJob() API succeeded
    let jobEndTime:    Date?    // when completeJob() API succeeded
    let worstAccuracy: Double   // meters

    var badge: AccuracyBadge {
        if worstAccuracy <= 25 { return .high   }
        if worstAccuracy <= 50 { return .normal }
        return .verify
    }

    /// Minutes crew spent within 30 m before tapping Start.
    /// < 3 min = high confidence; > 8 min = possible GPS drift.
    var arrivalConfidenceMinutes: Int? {
        guard let arr = arrivalTime, let start = jobStartTime else { return nil }
        return max(0, Int(start.timeIntervalSince(arr) / 60))
    }

    var dwellMinutes: Int? {
        guard let start = jobStartTime, let end = jobEndTime else { return nil }
        return max(0, Int(end.timeIntervalSince(start) / 60))
    }

    /// Flat dictionary ready to merge into the completeJob() POST body.
    var serverPayload: [String: Any] {
        var d: [String: Any] = ["accuracy_badge": badge.rawValue]
        if let v = arrivalConfidenceMinutes { d["arrival_confidence_min"] = v }
        if let v = dwellMinutes             { d["dwell_minutes"]          = v }
        d["worst_fix_accuracy"] = Int(worstAccuracy)
        return d
    }
}

// MARK: - ArrivalMonitor

/// Singleton that tracks one visit at a time. Reset for each new visit via
/// `configure(site:)`.
///
/// Call sequence:
///   configure(site:)         ← set job-site coordinate
///   observe(fix:)            ← called on every accepted GPS fix
///   jobStarted()             ← called immediately after startJob() API success
///   jobCompleted()           ← called immediately after completeJob() API success
///   metrics                  ← read the final ArrivalMetrics
@MainActor
final class ArrivalMonitor {

    static let shared = ArrivalMonitor()

    // MARK: - Notification constants

    static let actionClockIn    = "ca.mowology.crm.arrival.clockin"
    static let categoryArrival  = "ca.mowology.crm.arrival"

    private init() {}

    // MARK: - Published Metrics

    private(set) var metrics: ArrivalMetrics?

    // MARK: - Private State

    private var siteCoordinate: CLLocationCoordinate2D?
    private var arrivalTime:    Date?
    private var jobStartTime:   Date?
    private var worstAccuracy:  Double = 0

    private let ARRIVAL_RADIUS_M: Double = 30

    // MARK: - Configure

    /// Call before `startJob()` with the job-site lat/lng from `Stop`.
    /// Resets all state for the new visit.
    func configure(site: CLLocationCoordinate2D) {
        siteCoordinate = site
        arrivalTime    = nil
        jobStartTime   = nil
        worstAccuracy  = 0
        metrics        = nil
    }

    // MARK: - Observe

    /// Called on every accepted GPS fix. Tracks worst accuracy and
    /// records the first fix within 30 m of the site as arrival time.
    func observe(fix: CLLocation) {
        if fix.horizontalAccuracy > worstAccuracy {
            worstAccuracy = fix.horizontalAccuracy
        }

        guard let coord = siteCoordinate, arrivalTime == nil else { return }
        let site = CLLocation(latitude: coord.latitude, longitude: coord.longitude)
        if fix.distance(from: site) <= ARRIVAL_RADIUS_M {
            arrivalTime = fix.timestamp
        }
    }

    // MARK: - Job Lifecycle

    func jobStarted() {
        jobStartTime = Date()
        metrics = buildMetrics()
    }

    func jobCompleted() {
        metrics = buildMetrics(endTime: Date())
    }

    // MARK: - Private

    private func buildMetrics(endTime: Date? = nil) -> ArrivalMetrics {
        ArrivalMetrics(
            arrivalTime:   arrivalTime,
            jobStartTime:  jobStartTime,
            jobEndTime:    endTime,
            worstAccuracy: worstAccuracy
        )
    }
}
