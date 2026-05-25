//
//  LocationManager.swift
//  MowologyCRM
//
//  CoreLocation wrapper for always-on GPS tracking during crew shifts.
//  Provides: permission request, background tracking control, last fix
//  access, and a speed-derived activity classification that feeds the
//  /api/schedule/location ping body.
//

import CoreLocation
import Foundation

// MARK: - ActivityType

/// Coarse activity state derived from GPS speed.
/// Raw values match the server-side `activity` field the PHP API expects.
/// Mirrors the Android Google Activity Recognition type names so the
/// dashboard can render a consistent activity legend across platforms.
enum ActivityType: String {
    case driving    = "IN_VEHICLE"
    case walking    = "ON_FOOT"
    case stationary = "STILL"
    case unknown    = "UNKNOWN"
}

// MARK: - LocationError

enum LocationError: Error {
    case permissionDenied
    case locationUnavailable
}

// MARK: - LocationManager

/// Thin, non-singleton wrapper around `CLLocationManager`.
///
/// **Threading:** must be created and accessed on the main thread.
/// `GPSTrackingService` is `@MainActor`; this class lives inside it.
///
/// **Permissions:** calls `requestAlwaysAuthorization()` on demand.
/// The caller (`GPSTrackingService.start`) is responsible for timing.
/// Always-on authorization is required for background delivery — the
/// `UIBackgroundModes: location` key must also be set in Info.plist.
final class LocationManager: NSObject {

    // MARK: - State

    private(set) var lastLocation: CLLocation?         = nil
    private(set) var currentActivity: ActivityType     = .unknown

    /// Called on every valid, filtered CLLocation update.
    /// GPSTrackingService sets this to drive its delegate-based ping trigger.
    var onLocationFix: ((CLLocation) -> Void)?

    // MARK: - Private

    private let manager = CLLocationManager()
    private var pendingLocationContinuation: CheckedContinuation<CLLocation, Error>?

    // MARK: - Init

    override init() {
        super.init()
        manager.delegate                            = self
        manager.desiredAccuracy                     = kCLLocationAccuracyBestForNavigation
        manager.distanceFilter                      = 10      // meters — suppress micro-jitter
        manager.pausesLocationUpdatesAutomatically  = false   // field app must not pause
        manager.activityType                        = .other  // no road-snapping; crew is on foot
    }

    // MARK: - Permission

    func requestAlwaysPermission() {
        manager.requestAlwaysAuthorization()
    }

    // MARK: - Tracking Lifecycle

    /// Enable background location delivery and begin streaming fixes.
    /// Must be called after the user has granted Always authorization;
    /// calling before authorization is granted has no effect.
    ///
    /// Registers BOTH continuous location updates AND Significant Location
    /// Changes (SLC). Continuous updates give us the fine-grained 10m fixes we
    /// need for accurate visit trails when the app is active. SLC is the
    /// background-survival path: when iOS suspends the app during a stationary
    /// period, SLC will wake the suspended process on the next ~500m of
    /// movement and deliver a fix via the delegate — restarting the ping
    /// stream automatically. Without SLC, a suspended app stays asleep
    /// indefinitely because continuous updates require a running process.
    func startBackgroundTracking() {
        manager.allowsBackgroundLocationUpdates  = true
        manager.showsBackgroundLocationIndicator = true
        manager.startUpdatingLocation()
        manager.startMonitoringSignificantLocationChanges()
    }

    /// Stop the location stream and disable background delivery.
    func stopBackgroundTracking() {
        manager.stopMonitoringSignificantLocationChanges()
        manager.stopUpdatingLocation()
        manager.allowsBackgroundLocationUpdates  = false
        manager.showsBackgroundLocationIndicator = false
    }

    /// Hook for resetting per-visit accumulators (distance, duration) when
    /// a new job timer starts. Currently a no-op; extend when those metrics
    /// are added to the visit timeline API.
    func resetSessionMetrics() {}

    // MARK: - One-shot Location Fix

    /// Returns the most recent fix if it is younger than 30 s; otherwise
    /// requests a single fresh fix and awaits it. Throws if permission is
    /// denied or the fix request fails.
    func currentLocation() async throws -> CLLocation {
        switch manager.authorizationStatus {
        case .denied, .restricted:
            throw LocationError.permissionDenied
        default:
            break
        }
        if let cached = lastLocation, cached.timestamp.timeIntervalSinceNow > -30 {
            return cached
        }
        return try await withCheckedThrowingContinuation { continuation in
            pendingLocationContinuation?.resume(throwing: LocationError.locationUnavailable)
            pendingLocationContinuation = continuation
            manager.requestLocation()
        }
    }

    // MARK: - Private Helpers

    /// Classify the activity from GPS speed (m/s).
    /// Speed is -1 when unknown (no valid fix yet).
    private func deriveActivity(from location: CLLocation) -> ActivityType {
        let speed = location.speed
        guard speed >= 0 else { return .unknown }
        if speed < 0.5  { return .stationary }   // < 1.8 km/h  — standing still
        if speed < 4.0  { return .walking    }   // < 14.4 km/h — walking/jogging
        return .driving                           // ≥ 14.4 km/h — in a vehicle
    }

    /// Called by ActivityMonitor to update the motion-classified activity.
    func updateActivity(_ activity: ActivityType) {
        currentActivity = activity
    }
}

// MARK: - CLLocationManagerDelegate

extension LocationManager: CLLocationManagerDelegate {

    func locationManager(_ manager: CLLocationManager,
                         didUpdateLocations locations: [CLLocation]) {
        guard let loc = locations.last else { return }
        // Discard fixes worse than 200 m — matches the Capacitor bridge gate.
        guard loc.horizontalAccuracy >= 0,
              loc.horizontalAccuracy <= 200 else { return }
        lastLocation    = loc
        currentActivity = deriveActivity(from: loc)
        onLocationFix?(loc)
        if let cont = pendingLocationContinuation {
            pendingLocationContinuation = nil
            cont.resume(returning: loc)
        }
    }

    func locationManager(_ manager: CLLocationManager,
                         didFailWithError error: Error) {
        // Non-fatal for continuous tracking: last known location stays valid.
        print("[LocationManager] fix error: \(error.localizedDescription)")
        if let cont = pendingLocationContinuation {
            pendingLocationContinuation = nil
            cont.resume(throwing: error)
        }
    }

    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        switch manager.authorizationStatus {
        case .authorizedAlways:
            break   // ideal — background delivery is available
        case .authorizedWhenInUse:
            print("[LocationManager] WhenInUse only — background GPS disabled.")
        case .denied, .restricted:
            print("[LocationManager] Location denied — GPS tracking unavailable.")
        case .notDetermined:
            break
        @unknown default:
            break
        }
    }
}
