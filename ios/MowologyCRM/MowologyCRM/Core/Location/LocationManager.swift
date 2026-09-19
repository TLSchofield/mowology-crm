//
//  LocationManager.swift
//  MowologyCRM
//

import CoreLocation
import CoreMotion
import Foundation

// MARK: - ActivityState

/// Motion state derived from CMMotionActivityManager. Only shapes the BASELINE tier
/// (between jobs). On a job site the tier is `enhanced` and motion is ignored — a
/// crew standing still on a client's property is exactly when the record matters.
enum ActivityState: String {
    case unknown    = "UNKNOWN"
    case stationary = "STILL"
    case walking    = "WALKING"
    case running    = "RUNNING"
    case automotive = "IN_VEHICLE"
}

extension ActivityState {
    static func from(_ a: CMMotionActivity) -> ActivityState {
        if a.automotive { return .automotive }
        if a.running    { return .running    }
        if a.walking    { return .walking    }
        if a.cycling    { return .walking    }
        if a.stationary { return .stationary }
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

// MARK: - TrackingProblem

/// Something that stops tracking from working as the crew and the office expect.
/// Surfaced in the UI and reported to the server — tracking must never fail silently.
enum TrackingProblem: String {
    case denied           // location access off for this app
    case whenInUseOnly    // works while open; iOS will not relaunch us after a kill
    case reducedAccuracy  // "Precise Location" off — every fix is kilometres wide

    var message: String {
        switch self {
        case .denied:          return "Location access is off. Tracking can't run."
        case .whenInUseOnly:   return "Location is set to \"While Using\". Set it to \"Always\" so tracking survives the app closing."
        case .reducedAccuracy: return "Precise Location is off, so job sites can't be detected."
        }
    }
}

// MARK: - LocationManager

/// CoreLocation + CoreMotion for shift tracking.
///
/// Two tiers, chosen by WHERE the crew is, not how they are moving:
///   baseline — between jobs: ~10–100 m accuracy, 50 m filter, motion-adaptive
///   enhanced — on a client property or a running job: best accuracy, 8 m filter,
///              never backs off when stationary
///
/// Survives termination: significant-change monitoring and region monitoring both
/// make iOS relaunch the app in the background (see AppDelegate), which a plain
/// `startUpdatingLocation` never does.
@MainActor
final class LocationManager: NSObject, ObservableObject {

    // MARK: - Published State

    @Published private(set) var authorizationStatus: CLAuthorizationStatus
    @Published private(set) var isPrecise: Bool = true
    @Published private(set) var lastLocation: CLLocation?
    @Published private(set) var currentActivity: ActivityState = .unknown
    @Published private(set) var accuracyBadge: AccuracyBadge  = .high
    @Published private(set) var tier: TrackingTier = .off

    /// Called on the main actor for every fix accepted for continuous tracking.
    var onAcceptedFix: ((CLLocation) -> Void)?
    /// (visitId, entered) — an OS geofence around one of today's own stops fired.
    var onRegionEvent: ((Int, Bool) -> Void)?
    /// Authorization or precision changed while tracking.
    var onAuthorizationChanged: (() -> Void)?

    // MARK: - Private

    private let clManager    = CLLocationManager()
    private let motionMgr    = CMMotionActivityManager()
    private var motionActive = false
    private var isUpdating   = false

    /// Holds the process's background location entitlement open for the shift (iOS 17).
    private var backgroundSession: CLBackgroundActivitySession?

    private var waiters: [UUID: CheckedContinuation<CLLocation, Error>] = [:]

    /// True when startBackgroundTracking() ran before any usable authorization existed.
    private var backgroundTrackingRequested = false

    private var lastAcceptedFix:      CLLocation?
    private var sessionWorstAccuracy: Double = 0

    private let ACCURACY_HARD_LIMIT: Double  = 200   // always reject
    private let ACCURACY_SOFT_LIMIT: Double  = 50    // reject if stationary
    private let SPEED_FOR_SOFT_PASS: Double  = 0.5   // m/s — accept >50 m if moving
    private let TELEPORT_SPEED_LIMIT: Double = 60    // m/s (~216 km/h)
    private let MAX_FIX_AGE: TimeInterval    = 15    // CoreLocation replays a cached fix on start
    private let ONE_SHOT_TIMEOUT: TimeInterval = 10

    static let regionPrefix = "mw.visit."
    /// iOS allows 20 monitored regions per app; leave headroom.
    static let maxRegions   = 18

    // MARK: - Init

    override init() {
        authorizationStatus = clManager.authorizationStatus
        super.init()
        clManager.delegate     = self
        clManager.activityType = .otherNavigation
        isPrecise = clManager.accuracyAuthorization == .fullAccuracy
        applySettings()
    }

    // MARK: - Permission

    func requestWhenInUsePermission() {
        guard clManager.authorizationStatus == .notDetermined else { return }
        clManager.requestWhenInUseAuthorization()
    }

    func requestAlwaysPermission() {
        let status = clManager.authorizationStatus
        guard status == .notDetermined || status == .authorizedWhenInUse else { return }
        clManager.requestAlwaysAuthorization()
    }

    var canUseLocation: Bool {
        switch authorizationStatus {
        case .authorizedAlways, .authorizedWhenInUse: return true
        default: return false
        }
    }

    /// The most serious thing wrong with tracking right now, if anything.
    var problem: TrackingProblem? {
        switch authorizationStatus {
        case .denied, .restricted:  return .denied
        case .authorizedWhenInUse:  return isPrecise ? .whenInUseOnly : .reducedAccuracy
        case .authorizedAlways:     return isPrecise ? nil : .reducedAccuracy
        default:                    return nil
        }
    }

    var permissionLabel: String {
        switch authorizationStatus {
        case .authorizedAlways:    return "always"
        case .authorizedWhenInUse: return "when_in_use"
        case .denied, .restricted: return "denied"
        default:                   return "unknown"
        }
    }

    // MARK: - One-Shot Fix

    /// Returns a current location, reusing a cached fix ≤30 s old. Any number of
    /// callers may wait at once (schedule sort, clock punch and job start overlap),
    /// and every one of them is released within ONE_SHOT_TIMEOUT.
    func currentLocation() async throws -> CLLocation {
        switch clManager.authorizationStatus {
        case .denied:     throw LocationError.permissionDenied
        case .restricted: throw LocationError.permissionRestricted
        default: break
        }
        if let cached = lastLocation, cached.timestamp.timeIntervalSinceNow > -30 {
            return cached
        }
        let id = UUID()
        return try await withCheckedThrowingContinuation { continuation in
            addWaiter(id, continuation)
        }
    }

    private func addWaiter(_ id: UUID, _ continuation: CheckedContinuation<CLLocation, Error>) {
        waiters.updateValue(continuation, forKey: id)
        clManager.requestLocation()
        Task { [weak self] in
            try? await Task.sleep(nanoseconds: 10_000_000_000)   // ONE_SHOT_TIMEOUT
            self?.timeOutWaiter(id)
        }
    }

    private func timeOutWaiter(_ id: UUID) {
        waiters.removeValue(forKey: id)?.resume(throwing: LocationError.locationUnavailable)
    }

    /// Ask for one fresh fix without waiting on it — used to break a stationary
    /// silence on a job site, where the distance filter would otherwise say nothing.
    func nudge() {
        guard canUseLocation else { return }
        clManager.requestLocation()
    }

    private func resolveWaiters(with result: Result<CLLocation, Error>) {
        let pending = waiters
        waiters.removeAll()
        pending.values.forEach { $0.resume(with: result) }
    }

    // MARK: - Continuous Tracking

    func startBackgroundTracking() {
        if canUseLocation {
            activateBackgroundTracking()
        } else {
            backgroundTrackingRequested = true   // start the moment permission arrives
        }
    }

    private func activateBackgroundTracking() {
        backgroundTrackingRequested = false
        if tier == .off { tier = .baseline }

        clManager.allowsBackgroundLocationUpdates    = true
        clManager.pausesLocationUpdatesAutomatically = false
        // With "While Using", updates started in the foreground DO continue in the
        // background — as long as the blue indicator is shown. Previously nothing
        // started at all until "Always" was granted, with no warning.
        clManager.showsBackgroundLocationIndicator = authorizationStatus != .authorizedAlways

        if backgroundSession == nil { backgroundSession = CLBackgroundActivitySession() }
        if !isUpdating {
            clManager.startUpdatingLocation()
            isUpdating = true
        }
        // The relaunch path: iOS restarts a terminated app for these. Needs "Always".
        if authorizationStatus == .authorizedAlways {
            clManager.startMonitoringSignificantLocationChanges()
        }
        applySettings()
        startMotionTracking()
    }

    func stopBackgroundTracking() {
        backgroundTrackingRequested = false
        tier = .off
        if isUpdating {
            clManager.stopUpdatingLocation()
            isUpdating = false
        }
        clManager.stopMonitoringSignificantLocationChanges()
        clearRegions()
        backgroundSession?.invalidate()
        backgroundSession = nil
        clManager.allowsBackgroundLocationUpdates = false
        stopMotionTracking()
    }

    func setTier(_ newTier: TrackingTier) {
        guard newTier != tier, newTier != .off, isUpdating else { return }
        tier = newTier
        applySettings()
        if newTier == .enhanced { nudge() }
    }

    // MARK: - Geofences

    /// Monitor the nearest of today's own stops. Entering one wakes (or relaunches)
    /// the app and promotes tracking to `enhanced` before the first on-site ping.
    func monitor(_ fences: [TrackingGeofence]) {
        guard authorizationStatus == .authorizedAlways,
              CLLocationManager.isMonitoringAvailable(for: CLCircularRegion.self) else { return }

        let origin  = lastLocation
        let nearest = fences.sorted { a, b in
            guard let origin else { return a.visitId < b.visitId }
            return origin.distance(from: CLLocation(latitude: a.lat, longitude: a.lng))
                 < origin.distance(from: CLLocation(latitude: b.lat, longitude: b.lng))
        }.prefix(Self.maxRegions)

        let wanted = Set(nearest.map { Self.regionPrefix + String($0.visitId) })
        for region in clManager.monitoredRegions where region.identifier.hasPrefix(Self.regionPrefix) {
            if !wanted.contains(region.identifier) { clManager.stopMonitoring(for: region) }
        }
        let existing = Set(clManager.monitoredRegions.map(\.identifier))
        for fence in nearest where !existing.contains(Self.regionPrefix + String(fence.visitId)) {
            let radius = min(Double(fence.radiusM), clManager.maximumRegionMonitoringDistance)
            let region = CLCircularRegion(
                center: CLLocationCoordinate2D(latitude: fence.lat, longitude: fence.lng),
                radius: max(radius, 60),
                identifier: Self.regionPrefix + String(fence.visitId)
            )
            region.notifyOnEntry = true
            region.notifyOnExit  = true
            clManager.startMonitoring(for: region)
            clManager.requestState(for: region)   // already inside? say so now
        }
    }

    private func clearRegions() {
        for region in clManager.monitoredRegions where region.identifier.hasPrefix(Self.regionPrefix) {
            clManager.stopMonitoring(for: region)
        }
    }

    /// nonisolated: called straight from CoreLocation's delegate callbacks.
    nonisolated private static func visitId(from region: CLRegion) -> Int? {
        let prefix = "mw.visit."
        guard region.identifier.hasPrefix(prefix) else { return nil }
        return Int(region.identifier.dropFirst(prefix.count))
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
            // Low-confidence readings flap between states and thrash the GPS settings.
            guard let self, let activity, activity.confidence != .low else { return }
            Task { @MainActor in
                let detected = ActivityState.from(activity)
                guard detected != self.currentActivity else { return }
                self.currentActivity = detected
                self.applySettings()
            }
        }
    }

    private func stopMotionTracking() {
        guard motionActive else { return }
        motionActive    = false
        currentActivity = .unknown
        motionMgr.stopActivityUpdates()
    }

    func updateActivity(_ state: ActivityState) {
        guard state != currentActivity else { return }
        currentActivity = state
        applySettings()
    }

    // MARK: - Tier Settings

    private func applySettings() {
        switch tier {
        case .enhanced:
            clManager.desiredAccuracy = kCLLocationAccuracyBest
            clManager.distanceFilter  = 8
        case .baseline, .off:
            switch currentActivity {
            case .stationary:
                clManager.desiredAccuracy = kCLLocationAccuracyHundredMeters
                clManager.distanceFilter  = 100
            case .automotive:
                // Approaching a site at speed: tight enough that a 150 m fence isn't skipped.
                clManager.desiredAccuracy = kCLLocationAccuracyNearestTenMeters
                clManager.distanceFilter  = 40
            default:
                clManager.desiredAccuracy = kCLLocationAccuracyNearestTenMeters
                clManager.distanceFilter  = 50
            }
        }
    }

    // MARK: - Fix Quality Filter

    /// Invalid (negative accuracy), stale, wildly inaccurate, stationary-and-vague,
    /// or physically impossible fixes never reach the record.
    private func shouldAccept(_ fix: CLLocation) -> Bool {
        if fix.horizontalAccuracy < 0 { return false }
        if fix.horizontalAccuracy > ACCURACY_HARD_LIMIT { return false }
        if fix.timestamp.timeIntervalSinceNow < -MAX_FIX_AGE { return false }

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
            // One-shot callers are user-initiated — give them any VALID fix, even a rough one.
            if fix.horizontalAccuracy >= 0 {
                self.resolveWaiters(with: .success(fix))
            }
            if self.shouldAccept(fix) {
                self.lastAcceptedFix = fix
                self.lastLocation    = fix
                self.refreshBadge(accuracy: fix.horizontalAccuracy)
                self.onAcceptedFix?(fix)
            }
        }
    }

    nonisolated func locationManager(
        _ manager: CLLocationManager,
        didFailWithError error: Error
    ) {
        // kCLErrorLocationUnknown is transient: CoreLocation keeps trying and a later
        // didUpdateLocations still resolves the request. Failing here made cold-start
        // requestLocation() calls fail almost every time indoors.
        if let clErr = error as? CLError, clErr.code == .locationUnknown { return }
        Task { @MainActor in
            self.resolveWaiters(with: .failure(LocationError.locationUnavailable))
        }
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let status  = manager.authorizationStatus
        let precise = manager.accuracyAuthorization == .fullAccuracy
        Task { @MainActor in
            self.authorizationStatus = status
            self.isPrecise           = precise
            if self.canUseLocation, self.backgroundTrackingRequested || self.isUpdating {
                // Newly granted, or upgraded While-Using → Always mid-shift: re-arm so
                // significant-change + the indicator flag match the new authorization.
                self.activateBackgroundTracking()
            }
            self.onAuthorizationChanged?()
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didEnterRegion region: CLRegion) {
        guard let visitId = Self.visitId(from: region) else { return }
        Task { @MainActor in self.onRegionEvent?(visitId, true) }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didExitRegion region: CLRegion) {
        guard let visitId = Self.visitId(from: region) else { return }
        Task { @MainActor in self.onRegionEvent?(visitId, false) }
    }

    nonisolated func locationManager(
        _ manager: CLLocationManager,
        didDetermineState state: CLRegionState,
        for region: CLRegion
    ) {
        guard state == .inside, let visitId = Self.visitId(from: region) else { return }
        Task { @MainActor in self.onRegionEvent?(visitId, true) }
    }
}
