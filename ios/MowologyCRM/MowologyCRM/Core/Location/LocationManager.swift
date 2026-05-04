//
//  LocationManager.swift
//  MowologyCRM
//
//  Wraps CLLocationManager for background GPS tracking during a crew shift.
//

import Foundation
import CoreLocation

// MARK: - Tracking Activity

enum TrackingActivity: String {
    case driving    = "driving"
    case walking    = "walking"
    case stationary = "stationary"
    case unknown    = "unknown"
}

// MARK: - LocationManager

final class LocationManager: NSObject {

    private let manager = CLLocationManager()

    private(set) var lastLocation: CLLocation?

    // Set externally by ActivityMonitor when CoreMotion data is available.
    var currentActivity: TrackingActivity = .unknown

    private(set) var sessionDistanceMeters: Double = 0

    override init() {
        super.init()
        manager.delegate                          = self
        manager.desiredAccuracy                   = kCLLocationAccuracyBest
        manager.distanceFilter                    = 10
        manager.pausesLocationUpdatesAutomatically = false
        manager.activityType                      = .otherNavigation
    }

    // MARK: - Permissions

    func requestAlwaysPermission() {
        switch manager.authorizationStatus {
        case .notDetermined, .authorizedWhenInUse:
            manager.requestAlwaysAuthorization()
        default:
            break
        }
    }

    // MARK: - Tracking

    func startBackgroundTracking() {
        manager.allowsBackgroundLocationUpdates   = true
        manager.showsBackgroundLocationIndicator  = true
        manager.startUpdatingLocation()
    }

    func stopBackgroundTracking() {
        manager.allowsBackgroundLocationUpdates   = false
        manager.stopUpdatingLocation()
    }

    // MARK: - Session Metrics

    func resetSessionMetrics() {
        sessionDistanceMeters = 0
    }
}

// MARK: - CLLocationManagerDelegate

extension LocationManager: CLLocationManagerDelegate {

    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let loc = locations.last else { return }
        if let prev = lastLocation {
            sessionDistanceMeters += loc.distance(from: prev)
        }
        lastLocation = loc
    }

    func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        print("[LocationManager] \(error.localizedDescription)")
    }

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        if manager.authorizationStatus == .authorizedAlways,
           manager.allowsBackgroundLocationUpdates {
            manager.startUpdatingLocation()
        }
    }
}
