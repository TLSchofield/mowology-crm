//
//  ArrivalMonitor.swift
//  MowologyCRM
//
//  CLCircularRegion geofences around today's stops.
//  When the crew enters a region a local notification fires — "You've arrived at [Client]".
//  The notification's "Clock In" action triggers the clock-in flow without opening the app.
//
//  iOS allows a maximum of 20 simultaneous monitored regions; this class always loads
//  only the current day's stops, which fits within that limit.
//

import Foundation
import CoreLocation
import UserNotifications

// MARK: - ArrivalMonitor

@MainActor
final class ArrivalMonitor: NSObject {

    static let shared = ArrivalMonitor()

    // MARK: - Constants

    static let geofenceRadius: CLLocationDistance = 100   // metres
    static let regionPrefix = "mw.stop."

    // Notification action identifiers (must match AppDelegate category setup)
    static let actionClockIn   = "ARRIVAL_CLOCK_IN"
    static let categoryArrival = "ARRIVAL"

    // MARK: - Private

    private let locationManager = CLLocationManager()
    private var registeredStops: [String: (stopId: Int, displayName: String)] = [:]

    private override init() {
        super.init()
        locationManager.delegate = self
    }

    // MARK: - Public API

    /// Register geofences for the provided stops. Clears any previously-registered regions.
    func loadStops(_ stops: [Stop]) {
        // Remove all previously monitored Mowology regions
        for region in locationManager.monitoredRegions
            where region.identifier.hasPrefix(Self.regionPrefix) {
            locationManager.stopMonitoring(for: region)
        }
        registeredStops.removeAll()

        guard locationManager.authorizationStatus == .authorizedAlways else { return }

        for stop in stops {
            guard let lat = stop.latitude, let lng = stop.longitude else { continue }
            let id     = "\(Self.regionPrefix)\(stop.stopId)"
            let name   = stop.displayName ?? stop.propertyAddress
            let region = CLCircularRegion(
                center: CLLocationCoordinate2D(latitude: lat, longitude: lng),
                radius: Self.geofenceRadius,
                identifier: id
            )
            region.notifyOnEntry = true
            region.notifyOnExit  = false
            locationManager.startMonitoring(for: region)
            registeredStops[id] = (stopId: stop.stopId, displayName: name)
        }
    }

    /// Manual proximity check called on each GPS ping while a visit is active.
    /// Fires a notification if within the radius but iOS region monitoring hasn't triggered yet.
    func observe(fix location: CLLocation) {
        guard locationManager.authorizationStatus == .authorizedAlways else { return }
        // Region monitoring handles entry events; observe() is a belt-and-suspenders check
        // for edge cases where the app is running but the region callback didn't fire.
        for (id, info) in registeredStops {
            guard let region = locationManager.monitoredRegions
                .compactMap({ $0 as? CLCircularRegion })
                .first(where: { $0.identifier == id }) else { continue }

            if region.contains(location.coordinate) {
                postArrivalNotification(stopId: info.stopId, displayName: info.displayName, regionId: id)
            }
        }
    }

    // MARK: - Notification

    func postArrivalNotification(stopId: Int, displayName: String, regionId: String) {
        // Avoid re-posting if recently delivered (within 5 minutes)
        let lastKey = "mw.arrival.last.\(regionId)"
        let lastFired = UserDefaults.standard.double(forKey: lastKey)
        let now = Date().timeIntervalSince1970
        guard now - lastFired > 300 else { return }
        UserDefaults.standard.set(now, forKey: lastKey)

        let content = UNMutableNotificationContent()
        content.title    = "You've arrived"
        content.body     = "At \(displayName). Ready to start the job?"
        content.sound    = .default
        content.categoryIdentifier = Self.categoryArrival
        content.userInfo = ["stop_id": stopId]

        let request = UNNotificationRequest(
            identifier: "arrival.\(stopId)",
            content: content,
            trigger: nil
        )
        UNUserNotificationCenter.current().add(request, withCompletionHandler: nil)
    }
}

// MARK: - CLLocationManagerDelegate

extension ArrivalMonitor: CLLocationManagerDelegate {

    func locationManager(_ manager: CLLocationManager,
                         didEnterRegion region: CLRegion) {
        guard region.identifier.hasPrefix(Self.regionPrefix),
              let info = registeredStops[region.identifier] else { return }
        postArrivalNotification(
            stopId:      info.stopId,
            displayName: info.displayName,
            regionId:    region.identifier
        )
    }

    func locationManager(_ manager: CLLocationManager,
                         monitoringDidFailFor region: CLRegion?,
                         withError error: Error) {
        print("[ArrivalMonitor] Monitoring failed: \(error.localizedDescription)")
    }
}
