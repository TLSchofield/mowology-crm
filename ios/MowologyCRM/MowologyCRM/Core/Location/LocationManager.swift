//
//  LocationManager.swift
//  MowologyCRM
//

import CoreLocation
import CoreMotion
import Foundation

// MARK: - ActivityState

/// Motion state derived from CMMotionActivityManager.
/// Controls ping interval, CLLocationAccuracy tier, and distanceFilter.
enum ActivityState: String {
    case unknown    = "UNKNOWN"
    case stationary = "STILL"
    case walking    = "WALKING"
    case running    = "RUNNING"
    case automotive = "IN_VEHICLE"

    /// Seconds between GPS pings to the server.
    var pingInterval: TimeInterval {
        switch self {
        case .running:    return 20
        case .walking:    return 30
        case .automotive: return 45   // driving to next site
        case .unknown:    return 60
        case .stationary: return 120  // lunch / break — save battery
        }
    }

    /// CLLocation accuracy tier appropriate for this motion state.
    var desiredAccuracy: CLLocationAccuracy {
        switch self {
        case .running, .walking: return kCLLocationAccuracyBestForNavigation
        case .automotive:        return kCLLocationAccuracyBest
        case .stationary:        return kCLLocationAccuracyHundredMeters
        case .unknown:           return kCLLocationAccuracyBest
        }
    }

    /// Minimum movement (metres) between CLLocation callbacks.
    var distanceFilter: CLLocationDistance {
        switch self {
        case .running, .walking: return 10
        case .automotive:        return 25
        case .stationary:        return 100
        case .unknown:           return 15
        }
    }
}

// MARK: - ActivityState + CMMotionActivity

extension ActivityState {
    static func from(_ a: CMMotionActivity) -> ActivityState {
        if a.automotive           { return .automotive }
        if a.running              { return .running    }
        if a.walking || a.cycling { return .walking    }
        if a.stationary           { return .stationary }
        return .unknown
    }
}

// MARK: - LocationError

enum LocationError: LocalizedError {
    case permissionDenied
    case permissionRestricted
    case locationUnavailable

    var errorDescription: String? {
        switch self {
        case .permissionDenied:     return "Location access denied. Enable in Settings → Privacy → Location."
        case .permissionRestricted: return "Location access is restricted on this device."
        case .locationUnavailable:  return "Could not get current location."
        }
    }
}

// MARK: - LocationManager

/// Manages CoreLocation and CoreMotion for adaptive, battery-efficient crew tracking.
///
/// Key behaviours:
/// - Requests "when in use" on first schedule view, upgrades to "always" on job start.
/// - Uses CMMotionActivityManager to detect walking/running/stationary/automotive and
///   adjusts desiredAccuracy + distanceFilter accordingly (saving 60–70% battery vs
///   fixed kCLLocationAccuracyBest with no filter).
/// - Filters out fixes with horizontalAccuracy > 50 m unless the device is clearly
///   moving (speed ≥ 0.5 m/s), and rejects physically-impossible teleports.
/// - Tracks worst-fix accuracy per job session for the dispatcher accuracy badge.
@MainActor
final class LocationManager: NSObject, ObservableObject {

    // MARK: - Published State

    @Published private(set) var authorizationStatus: CLAuthorizationStatus
    @Published private(set) var lastLocation: CLLocation?
    @Published private(set) var currentActivity: ActivityState = .unknown
    @Published private(set) var accuracyBadge: AccuracyBadge  = .high

    /// Called on the main actor each time a fix is accepted for continuous
    /// tracking. GPSTrackingService uses this to POST pings driven by location
    /// updates — which fire even when the app is backgrounded — instead of a
    /// foreground-only timer.
    var onAcceptedFix: ((CLLocation) -> Void)?

    // MARK: - Private

    private let clManager       = CLLocationManager()
    private let motionMgr       = CMMotionActivityManager()
    private var motionActive    = false

    private var pendingContinuation: CheckedContinuation<CLLocation, Error>?

    /// Set to true when startBackgroundTracking() is called before "Always" is granted.
    /// locationManagerDidChangeAuthorization will start tracking as soon as it arrives.
    private var backgroundTrackingRequested = false

    // Fix-quality state (reset per job session)
    private var lastAcceptedFix:     CLLocation?
    private var sessionWorstAccuracy: Double = 0

    // Fix-quality thresholds
    private let ACCURACY_HARD_LIMIT: Double    = 200   // always reject
    private let ACCURACY_SOFT_LIMIT: Double    = 50    // reject if stationary
    private let SPEED_FOR_SOFT_PASS: Double    = 0.5   // m/s — accept >50 m if moving
    private let TELEPORT_SPEED_LIMIT: Double   = 60    // m/s (~216 km/h)

    // MARK: - Init

    override init() {
        authorizationStatus = CLLocationManager().authorizationStatus
        super.init()
        clManager.delegate = self
        applySettings(for: .unknown)
    }

    // MARK: - Permission

    func requestWhenInUsePermission() {
        guard clManager.authorizationStatus == .notDetermined else { return }
        clManager.requestWhenInUseAuthorization()
    }

    func requestAlwaysPermission() {
        let status = clManager.authorizationStatus
        // Handle notDetermined (ask for Always directly) or upgrade from WhenInUse.
        // If denied/restricted there is nothing we can do — user must go to Settings.
        guard status == .notDetermined || status == .authorizedWhenInUse else { return }
        clManager.requestAlwaysAuthorization()
    }

    var canUseLocation: Bool {
        switch authorizationStatus {
        case .authorizedAlways, .authorizedWhenInUse: return true
        default: return false
        }
    }

    // MARK: - One-Shot Fix

    /// Returns a current location, reusing a cached fix ≤30 s old.
    func currentLocation() async throws -> CLLocation {
        switch clManager.authorizationStatus {
        case .denied:     throw LocationError.permissionDenied
        case .restricted: throw LocationError.permissionRestricted
        default: break
        }
        if let cached = lastLocation, cached.timestamp.timeIntervalSinceNow > -30 {
            return cached
        }
        return try await withCheckedThrowingContinuation { continuation in
            pendingContinuation?.resume(throwing: LocationError.locationUnavailable)
            pendingContinuation = continuation
            clManager.requestLocation()
        }
    }

    // MARK: - Background Continuous Tracking

    func startBackgroundTracking() {
        if authorizationStatus == .authorizedAlways {
            activateBackgroundTracking()
        } else {
            // Defer: activate as soon as "Always" permission arrives.
            backgroundTrackingRequested = true
        }
    }

    private func activateBackgroundTracking() {
        backgroundTrackingRequested             = false
        clManager.allowsBackgroundLocationUpdates    = true
        clManager.pausesLocationUpdatesAutomatically = false  // managed via activity
        clManager.startUpdatingLocation()
        startMotionTracking()
    }

    func stopBackgroundTracking() {
        backgroundTrackingRequested = false
        clManager.stopUpdatingLocation()
        if clManager.authorizationStatus == .authorizedAlways {
            clManager.allowsBackgroundLocationUpdates = false
        }
        stopMotionTracking()
    }

    // MARK: - Session Metrics Reset (call on job start)

    func resetSessionMetrics() {
        sessionWorstAccuracy = 0
        lastAcceptedFix      = nil
        accuracyBadge        = .high
    }

    // MARK: - CoreMotion

    private func startMotionTracking() {
        guard !motionActive, CMMotionActivityManager.isActivityAvailable() else { return }
        motionActive = true
        motionMgr.startActivityUpdates(to: .main) { [weak self] activity in
            guard let self, let activity else { return }
            Task { @MainActor in
                let detected = ActivityState.from(activity)
                guard detected != self.currentActivity else { return }
                self.currentActivity = detected
                self.applySettings(for: detected)
            }
        }
    }

    private func stopMotionTracking() {
        guard motionActive else { return }
        motionActive    = false
        currentActivity = .unknown
        motionMgr.stopActivityUpdates()
    }

    /// Called by ActivityMonitor when external motion classification is available.
    func updateActivity(_ state: ActivityState) {
        guard state != currentActivity else { return }
        currentActivity = state
        applySettings(for: state)
    }

    // MARK: - Adaptive CLLocationManager Settings

    private func applySettings(for activity: ActivityState) {
        clManager.desiredAccuracy = activity.desiredAccuracy
        clManager.distanceFilter  = activity.distanceFilter
    }

    // MARK: - Fix Quality Filter

    /// Returns true if this fix should be forwarded to the server and stored.
    /// Filters:
    ///   Hard reject  — horizontalAccuracy > 200 m (indoor/tunnel noise)
    ///   Soft reject  — horizontalAccuracy > 50 m AND device not moving
    ///   Teleport     — apparent speed > 60 m/s vs previous accepted fix
    private func shouldAccept(_ fix: CLLocation) -> Bool {
        if fix.horizontalAccuracy > ACCURACY_HARD_LIMIT { return false }

        let speed = fix.speed >= 0 ? fix.speed : 0
        if fix.horizontalAccuracy > ACCURACY_SOFT_LIMIT && speed < SPEED_FOR_SOFT_PASS {
            return false
        }

        if let prev = lastAcceptedFix {
            let distance = fix.distance(from: prev)
            let elapsed  = fix.timestamp.timeIntervalSince(prev.timestamp)
            if elapsed > 0 && (distance / elapsed) > TELEPORT_SPEED_LIMIT {
                return false  // teleport — GPS multi-path artifact
            }
        }

        return true
    }

    // MARK: - Accuracy Badge Update

    private func refreshBadge(accuracy: Double) {
        if accuracy > sessionWorstAccuracy { sessionWorstAccuracy = accuracy }
        let badge: AccuracyBadge
        if sessionWorstAccuracy <= 25 { badge = .high   }
        else if sessionWorstAccuracy <= 50 { badge = .normal }
        else { badge = .verify }
        if badge != accuracyBadge { accuracyBadge = badge }
    }
}

// MARK: - CLLocationManagerDelegate

extension LocationManager: CLLocationManagerDelegate {

    nonisolated func locationManager(
        _ manager: CLLocationManager,
        didUpdateLocations locations: [CLLocation]
    ) {
        guard let fix = locations.last else { return }
        Task { @MainActor in
            // Always resolve one-shot continuations with the raw fix
            // (user-initiated — give them something even if quality is low)
            if let cont = self.pendingContinuation {
                self.pendingContinuation = nil
                cont.resume(returning: fix)
            }

            // Apply quality filter for continuous tracking
            if self.shouldAccept(fix) {
                self.lastAcceptedFix = fix
                self.lastLocation    = fix
                self.refreshBadge(accuracy: fix.horizontalAccuracy)
                // Drive a ping off the location update so tracking keeps posting
                // while the app is backgrounded (the timer loop is suspended then).
                self.onAcceptedFix?(fix)
            }
        }
    }

    nonisolated func locationManager(
        _ manager: CLLocationManager,
        didFailWithError error: Error
    ) {
        Task { @MainActor in
            if let cont = self.pendingContinuation {
                self.pendingContinuation = nil
                cont.resume(throwing: LocationError.locationUnavailable)
            }
        }
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        Task { @MainActor in
            self.authorizationStatus = manager.authorizationStatus
            // If "Always" permission just arrived and tracking was deferred, start now.
            if manager.authorizationStatus == .authorizedAlways,
               self.backgroundTrackingRequested {
                self.activateBackgroundTracking()
            }
        }
    }
}
