//
//  DayRouteView.swift
//  MowologyCRM
//
//  Shows today's stops as map pins connected by MKDirections-computed driving
//  polylines. A summary bar at the bottom shows total distance + drive time.
//  "Start in Maps" launches Apple Maps with all waypoints queued.
//
//  Routing rules:
//  - MKDirections requests run sequentially (1 at a time) with a 300ms gap
//    between requests to stay within Apple's undocumented rate limit.
//  - Results are cached in-memory for the view lifetime; revisiting the view
//    never re-requests routes.
//  - Stops without coordinates are omitted from routing but shown as pins.
//  - If all MKDirections calls fail (no network), the view degrades gracefully
//    to pin-only mode with an info label.
//

import SwiftUI
import MapKit

// MARK: - RouteSegment

private struct RouteSegment: Identifiable {
    let id = UUID()
    let polyline: MKPolyline
    let travelTimeSeconds: TimeInterval
    let distanceMeters: CLLocationDistance
}

// MARK: - DayRouteView

struct DayRouteView: View {

    let stops: [Stop]

    @State private var segments:           [RouteSegment] = []
    @State private var isComputingRoutes:  Bool           = false
    @State private var routingFailed:      Bool           = false
    @State private var mapPosition:        MapCameraPosition = .automatic

    // MARK: - Computed

    private var orderedStops: [Stop] {
        stops.sorted { $0.routeOrder < $1.routeOrder }
    }

    private var routeableStops: [Stop] {
        orderedStops.filter { $0.latitude != nil && $0.longitude != nil }
    }

    private var totalDriveMinutes: Int {
        Int(segments.reduce(0) { $0 + $1.travelTimeSeconds } / 60)
    }

    private var totalKilometres: Double {
        segments.reduce(0) { $0 + $1.distanceMeters } / 1000
    }

    // MARK: - Body

    var body: some View {
        ZStack(alignment: .bottom) {
            mapLayer
            summaryBar
        }
        .navigationTitle("Route — \(orderedStops.count) stops")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                startInMapsButton
            }
        }
        .task { await computeRoutes() }
    }

    // MARK: - Map

    private var mapLayer: some View {
        Map(position: $mapPosition) {
            // Stop annotations
            ForEach(Array(orderedStops.enumerated()), id: \.element.id) { index, stop in
                if let lat = stop.latitude, let lon = stop.longitude {
                    Annotation(
                        "\(index + 1)",
                        coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lon)
                    ) {
                        stopPin(order: index + 1, stop: stop)
                    }
                }
            }

            // Route polylines
            ForEach(segments) { segment in
                MapPolyline(segment.polyline)
                    .stroke(Color.MW.green, lineWidth: 3)
            }
        }
        .mapStyle(.standard(elevation: .flat))
        .ignoresSafeArea(edges: .top)
        .overlay(alignment: .top) {
            if isComputingRoutes {
                routingProgressBanner
            } else if routingFailed && segments.isEmpty {
                routingFailedBanner
            }
        }
    }

    private func stopPin(order: Int, stop: Stop) -> some View {
        ZStack {
            Circle()
                .fill(stop.isComplete ? Color.green : Color.MW.green)
                .frame(width: 28, height: 28)
            Text("\(order)")
                .font(.caption.bold())
                .foregroundStyle(.white)
        }
        .shadow(color: .black.opacity(0.2), radius: 3, y: 1)
    }

    // MARK: - Summary bar

    private var summaryBar: some View {
        VStack(spacing: 0) {
            if !segments.isEmpty {
                HStack(spacing: 0) {
                    summaryCell(
                        value: "\(orderedStops.count)",
                        label: "stops"
                    )
                    Divider().frame(height: 36)
                    summaryCell(
                        value: String(format: "%.1f km", totalKilometres),
                        label: "total"
                    )
                    Divider().frame(height: 36)
                    summaryCell(
                        value: formattedDriveTime,
                        label: "drive"
                    )
                }
                .padding(.vertical, 10)
                .background(.ultraThinMaterial)
            }
        }
    }

    private func summaryCell(value: String, label: String) -> some View {
        VStack(spacing: 2) {
            Text(value)
                .font(.subheadline.weight(.semibold).monospacedDigit())
            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
    }

    private var formattedDriveTime: String {
        let h = totalDriveMinutes / 60
        let m = totalDriveMinutes % 60
        return h > 0 ? "\(h)h \(m)m" : "\(m)m"
    }

    // MARK: - Progress banners

    private var routingProgressBanner: some View {
        HStack(spacing: 8) {
            ProgressView().tint(Color.MW.green)
            Text("Computing routes…")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 8)
        .background(.ultraThinMaterial)
        .clipShape(Capsule())
        .padding(.top, 8)
    }

    private var routingFailedBanner: some View {
        Label("Routes unavailable offline — pins only", systemImage: "wifi.slash")
            .font(.caption)
            .foregroundStyle(.secondary)
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .background(.ultraThinMaterial)
            .clipShape(Capsule())
            .padding(.top, 8)
    }

    // MARK: - "Start in Apple Maps" button

    private var startInMapsButton: some View {
        Button {
            openInAppleMaps()
        } label: {
            Label("Maps", systemImage: "arrow.triangle.turn.up.right.circle.fill")
                .font(.subheadline.weight(.medium))
                .foregroundStyle(Color.MW.green)
        }
        .disabled(routeableStops.isEmpty)
    }

    private func openInAppleMaps() {
        let items = routeableStops.map { stop -> MKMapItem in
            let coord     = CLLocationCoordinate2D(latitude: stop.latitude!, longitude: stop.longitude!)
            let placemark = MKPlacemark(coordinate: coord)
            let item      = MKMapItem(placemark: placemark)
            item.name     = stop.displayName ?? stop.propertyAddress
            return item
        }
        guard !items.isEmpty else { return }
        MKMapItem.openMaps(with: items, launchOptions: [
            MKLaunchOptionsDirectionsModeKey: MKLaunchOptionsDirectionsModeDriving
        ])
    }

    // MARK: - Route computation

    private func computeRoutes() async {
        guard segments.isEmpty, routeableStops.count >= 2 else { return }
        isComputingRoutes = true
        routingFailed     = false

        var computed: [RouteSegment] = []

        for i in 0..<(routeableStops.count - 1) {
            let from = routeableStops[i]
            let to   = routeableStops[i + 1]

            let request = MKDirections.Request()
            request.source = MKMapItem(placemark: MKPlacemark(
                coordinate: CLLocationCoordinate2D(latitude: from.latitude!, longitude: from.longitude!)
            ))
            request.destination = MKMapItem(placemark: MKPlacemark(
                coordinate: CLLocationCoordinate2D(latitude: to.latitude!, longitude: to.longitude!)
            ))
            request.transportType = .automobile

            let directions = MKDirections(request: request)
            do {
                let response = try await directions.calculate()
                if let route = response.routes.first {
                    computed.append(RouteSegment(
                        polyline:            route.polyline,
                        travelTimeSeconds:   route.expectedTravelTime,
                        distanceMeters:      route.distance
                    ))
                }
            } catch {
                routingFailed = true
            }

            // 300ms gap between MKDirections requests — Apple's rate limit is undocumented
            // but parallel/rapid requests return errors. Sequential + small gap is reliable.
            if i < routeableStops.count - 2 {
                try? await Task.sleep(for: .milliseconds(300))
            }
        }

        segments          = computed
        isComputingRoutes = false
        mapPosition       = .automatic
    }
}
