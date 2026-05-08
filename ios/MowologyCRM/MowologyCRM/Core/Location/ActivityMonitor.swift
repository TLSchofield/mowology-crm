//
//  ActivityMonitor.swift
//  MowologyCRM
//
//  CMMotionActivityManager wrapper that classifies crew motion state and
//  feeds it into LocationManager.currentActivity so the GPS ping loop
//  can adapt its interval accordingly.
//
//  Intervals:
//    stationary → 30s  (at a stop — frequent proof-of-presence pings)
//    walking    → 45s  (moving around a property)
//    driving    → 60s  (between sites — battery saving)
//    unknown    → 45s  (conservative fallback)
//
//  Threading: CMMotionActivityManager delivers on its own OperationQueue.
//  This class dispatches back to @MainActor before mutating shared state.
//

import CoreMotion
import Foundation

// MARK: - ActivityMonitor

@MainActor
final class ActivityMonitor {

    static let shared = ActivityMonitor()

    private let motionManager = CMMotionActivityManager()
    private let motionQueue:   OperationQueue = {
        let q = OperationQueue()
        q.name = "ca.mowology.motion"
        q.maxConcurrentOperationCount = 1
        q.qualityOfService = .utility
        return q
    }()

    private weak var locationManager: LocationManager?

    private init() {}

    // MARK: - Lifecycle

    func start(updating locationManager: LocationManager) {
        guard CMMotionActivityManager.isActivityAvailable() else { return }
        self.locationManager = locationManager

        motionManager.startActivityUpdates(to: motionQueue) { [weak self] activity in
            guard let activity, activity.confidence != .low else { return }
            let mapped = Self.map(activity)
            Task { @MainActor [weak self] in
                self?.locationManager?.updateActivity(mapped)
            }
        }
    }

    func stop() {
        motionManager.stopActivityUpdates()
        locationManager = nil
    }

    // MARK: - Private

    private static func map(_ a: CMMotionActivity) -> ActivityState {
        if a.automotive            { return .automotive }
        if a.walking || a.running  { return .walking    }
        if a.stationary            { return .stationary }
        return .unknown
    }
}
