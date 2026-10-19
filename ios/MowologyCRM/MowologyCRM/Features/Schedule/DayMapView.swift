//
//  DayMapView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-05-03.
//

import SwiftUI
import MapKit

struct DayMapView: View {

    let stops: [Stop]
    let isLoading: Bool
    let isAdmin: Bool
    var routes: [CrewRoute] = []
    var liveCrew: [CrewLiveLocation] = []
    var currentUserId: Int? = nil
    /// Device position, for the distance shown on each carousel card.
    var userLocation: CLLocation? = nil
    /// Admin only: asks the parent to open the Move Stop sheet for this stop.
    var onMove: ((Stop) -> Void)? = nil

    /// The stop in focus — the carousel card on screen and the enlarged pin.
    /// nil means "overview": every stop fitted on the map, carousel at its first card.
    @State private var selectedId: Int?
    @State private var position: MapCameraPosition = .automatic
    @State private var isCarouselCollapsed = false

    private let selectionFeedback = UISelectionFeedbackGenerator()

    private var mappableStops: [Stop] {
        stops.filter { $0.latitude != nil && $0.longitude != nil }
    }

    /// Stable colour per crew member so trail polylines and live pins match.
    /// The current user always gets MW.green so their own trail reads as "yours".
    private static let trailPalette: [Color] = [
        .blue, .orange, .purple, .red, .pink, .teal, .indigo, .brown
    ]

    private func color(forUserId userId: Int) -> Color {
        if let me = currentUserId, userId == me { return Color.MW.green }
        return Self.trailPalette[abs(userId) % Self.trailPalette.count]
    }

    // MARK: - Body

    var body: some View {
        ZStack(alignment: .bottom) {
            if !isLoading && stops.isEmpty {
                emptyState
            } else {
                map
            }

            if !mappableStops.isEmpty {
                carousel
                    .zIndex(1)
            }
        }
        .onAppear  { fitCameraToAll() }
        .onChange(of: stops.map(\.id)) { _, ids in
            // A poll refresh keeps the same stops — hold the crew's place. Only a
            // different day (or a stop leaving it) returns to the overview.
            if let selectedId, !ids.contains(selectedId) {
                self.selectedId = nil
                fitCameraToAll()
            } else if selectedId == nil {
                fitCameraToAll()
            }
        }
        .onChange(of: selectedId) { _, id in
            guard let id, let stop = mappableStops.first(where: { $0.id == id }) else { return }
            selectionFeedback.selectionChanged()
            focusCamera(on: stop)
        }
        // Trails and live pins refresh every poll; only refit while in overview.
        .onChange(of: routes)   { _, _ in if selectedId == nil { fitCameraToAll() } }
        .onChange(of: liveCrew) { _, _ in if selectedId == nil { fitCameraToAll() } }
    }

    // MARK: - Carousel

    /// Swipe the cards and the map follows; tap a pin and the cards follow.
    private var carousel: some View {
        VStack(spacing: 8) {
            HStack(spacing: 8) {
                Button {
                    withAnimation(.spring(response: 0.3, dampingFraction: 0.85)) {
                        isCarouselCollapsed.toggle()
                    }
                } label: {
                    Image(systemName: isCarouselCollapsed ? "chevron.up" : "chevron.down")
                        .font(.footnote.weight(.bold))
                        .foregroundStyle(Color.MW.green)
                        .frame(width: 56, height: 30)
                        .background(.regularMaterial, in: Capsule())
                }
                .accessibilityLabel(isCarouselCollapsed ? "Show stops" : "Hide stops")

                if selectedId != nil {
                    Button {
                        selectedId = nil
                        fitCameraToAll()
                    } label: {
                        Label("All stops", systemImage: "arrow.up.left.and.arrow.down.right")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(Color.MW.green)
                            .padding(.horizontal, 12)
                            .frame(height: 30)
                            .background(.regularMaterial, in: Capsule())
                    }
                    .transition(.opacity)
                }
            }

            if !isCarouselCollapsed {
                ScrollView(.horizontal, showsIndicators: false) {
                    LazyHStack(spacing: 10) {
                        ForEach(Array(mappableStops.enumerated()), id: \.element.id) { index, stop in
                            StopCarouselCard(
                                stop: stop,
                                routeOrder: index + 1,
                                total: mappableStops.count,
                                distanceMeters: distance(to: stop),
                                canMove: isAdmin && stop.canBeMoved && onMove != nil,
                                onMove: { onMove?(stop) }
                            )
                            .containerRelativeFrame(.horizontal)
                            .id(stop.id)
                        }
                    }
                    .scrollTargetLayout()
                }
                .scrollTargetBehavior(.viewAligned)
                .scrollPosition(id: $selectedId)
                .contentMargins(.horizontal, 28, for: .scrollContent)
                .frame(height: StopCarouselCard.height)
                .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .padding(.bottom, 10)
        .animation(.easeInOut(duration: 0.2), value: selectedId == nil)
    }

    private func distance(to stop: Stop) -> CLLocationDistance? {
        guard let userLocation, let lat = stop.latitude, let lng = stop.longitude else { return nil }
        return userLocation.distance(from: CLLocation(latitude: lat, longitude: lng))
    }

    // MARK: - Map

    private var map: some View {
        Map(position: $position) {

            // Travel trails — drawn first so stop pins render above them.
            ForEach(routes) { route in
                let coords = route.coordinates
                if coords.count >= 2 {
                    MapPolyline(coordinates: coords)
                        .stroke(
                            color(forUserId: route.userId),
                            style: StrokeStyle(
                                lineWidth: 4,
                                lineCap: .round,
                                lineJoin: .round
                            )
                        )
                }
            }

            // Live crew positions (latest fix per crew, last 24 h).
            // Skip the current user's pin if SwiftUI's UserAnnotation will already
            // show a blue dot for them — avoids two overlapping markers.
            ForEach(liveCrew) { crew in
                if crew.userId != currentUserId {
                    Annotation(
                        crew.fullName,
                        coordinate: crew.coordinate,
                        anchor: .center
                    ) {
                        CrewPin(crew: crew, color: color(forUserId: crew.userId))
                    }
                }
            }

            // Scheduled stops.
            ForEach(mappableStops) { stop in
                Annotation(
                    stop.fullAddress,
                    coordinate: CLLocationCoordinate2D(
                        latitude:  stop.latitude!,
                        longitude: stop.longitude!
                    ),
                    anchor: .bottom
                ) {
                    StopPin(
                        stop: stop,
                        isSelected: selectedId == stop.id,
                        routeOrder: (mappableStops.firstIndex(where: { $0.id == stop.id }) ?? 0) + 1
                    )
                    .onTapGesture {
                        withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
                            isCarouselCollapsed = false
                            selectedId = stop.id
                        }
                    }
                }
            }

            UserAnnotation()
        }
        .mapStyle(.standard(elevation: .flat))
        .mapControls {
            MapUserLocationButton()
            MapCompass()
        }
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Empty State

    private var emptyState: some View {
        VStack(spacing: 16) {
            Spacer()
            Image(systemName: "map")
                .font(.system(size: 52))
                .foregroundStyle(Color(.systemGray3))
            Text("No Stops Scheduled")
                .font(.title3.bold())
            Text("There are no stops on this day.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Spacer()
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Camera

    /// Fit the camera around stops, trail polylines, and live crew positions
    /// so all relevant geometry is visible at once.
    private func fitCameraToAll() {
        var coords: [CLLocationCoordinate2D] = []

        for stop in stops {
            if let lat = stop.latitude, let lng = stop.longitude {
                coords.append(CLLocationCoordinate2D(latitude: lat, longitude: lng))
            }
        }
        for route in routes {
            coords.append(contentsOf: route.coordinates)
        }
        for crew in liveCrew {
            coords.append(crew.coordinate)
        }

        guard !coords.isEmpty else { return }

        let lats = coords.map(\.latitude)
        let lngs = coords.map(\.longitude)
        let center = CLLocationCoordinate2D(
            latitude:  ((lats.min()! + lats.max()!) / 2),
            longitude: ((lngs.min()! + lngs.max()!) / 2)
        )
        let span = MKCoordinateSpan(
            latitudeDelta:  max((lats.max()! - lats.min()!) * 1.6, 0.012),
            longitudeDelta: max((lngs.max()! - lngs.min()!) * 1.6, 0.012)
        )
        withAnimation(.easeInOut(duration: 0.5)) {
            position = .region(MKCoordinateRegion(center: center, span: span))
        }
    }

    /// Street-level view of one stop. The centre sits a little south of the pin
    /// so the pin lands in the clear area above the carousel, not behind it.
    private func focusCamera(on stop: Stop) {
        guard let lat = stop.latitude, let lng = stop.longitude else { return }
        let span = MKCoordinateSpan(latitudeDelta: 0.012, longitudeDelta: 0.012)
        let center = CLLocationCoordinate2D(
            latitude:  lat - (isCarouselCollapsed ? 0 : span.latitudeDelta * 0.18),
            longitude: lng
        )
        withAnimation(.easeInOut(duration: 0.45)) {
            position = .region(MKCoordinateRegion(center: center, span: span))
        }
    }
}

// MARK: - Crew Pin

private struct CrewPin: View {

    let crew: CrewLiveLocation
    let color: Color

    private var iconName: String {
        crew.isTruck ? "truck.box.fill" : "person.fill"
    }

    private var initials: String {
        let parts = crew.fullName
            .split(separator: " ")
            .prefix(2)
            .map { String($0.first ?? Character("?")) }
        return parts.joined().uppercased()
    }

    /// Stale (>15 min) live fixes get desaturated so admins can spot drift.
    private var isStale: Bool { crew.secondsAgo > 900 }

    var body: some View {
        ZStack {
            Circle()
                .fill(isStale ? color.opacity(0.45) : color)
                .frame(width: 30, height: 30)
                .overlay(Circle().stroke(.white, lineWidth: 2))
                .shadow(color: .black.opacity(0.22), radius: 3, y: 2)

            if crew.isTruck {
                Image(systemName: iconName)
                    .font(.system(size: 13, weight: .bold))
                    .foregroundStyle(.white)
            } else {
                Text(initials)
                    .font(.system(size: 11, weight: .bold, design: .rounded))
                    .foregroundStyle(.white)
            }
        }
    }
}

// MARK: - Stop Pin

private struct StopPin: View {

    let stop: Stop
    let isSelected: Bool
    let routeOrder: Int

    private var pinColor: Color {
        if stop.isComplete   { return Color(.systemGray) }
        if stop.isInProgress { return Color.MW.green }
        return Color.MW.green
    }

    private var selectedColor: Color { Color.MW.dark }

    private var iconName: String {
        if stop.isComplete   { return "checkmark" }
        if stop.isInProgress { return "play.fill" }
        return "\(routeOrder).circle.fill"
    }

    var body: some View {
        VStack(spacing: 0) {
            ZStack {
                Circle()
                    .fill(isSelected ? selectedColor : pinColor)
                    .frame(width: 34, height: 34)
                    .overlay(
                        Circle().stroke(.white, lineWidth: isSelected ? 3 : 2)
                    )
                    .shadow(color: .black.opacity(0.22), radius: 3, y: 2)

                if stop.isComplete {
                    Image(systemName: "checkmark")
                        .font(.system(size: 13, weight: .bold))
                        .foregroundStyle(.white)
                } else if stop.isInProgress {
                    Image(systemName: "play.fill")
                        .font(.system(size: 10, weight: .bold))
                        .foregroundStyle(.white)
                } else {
                    Text("\(routeOrder)")
                        .font(.system(size: 13, weight: .bold, design: .rounded))
                        .foregroundStyle(.white)
                }
            }

            // Teardrop tail
            PinTail()
                .fill(isSelected ? selectedColor : pinColor)
                .frame(width: 10, height: 7)
        }
        .scaleEffect(isSelected ? 1.25 : 1.0)
        .animation(.spring(response: 0.25, dampingFraction: 0.7), value: isSelected)
    }
}

private struct PinTail: Shape {
    func path(in rect: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: rect.midX, y: rect.maxY))
        p.addLine(to: CGPoint(x: rect.minX, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.minY))
        p.closeSubpath()
        return p
    }
}

// MARK: - Carousel Card

/// One stop in the map carousel. The whole card opens the stop; Directions and
/// Move are reachable without leaving the map.
private struct StopCarouselCard: View {

    static let height: CGFloat = 148

    let stop: Stop
    let routeOrder: Int
    let total: Int
    let distanceMeters: Double?
    let canMove: Bool
    let onMove: () -> Void

    private var statusColor: Color {
        stop.isComplete ? Color(.systemGray) : Color.MW.green
    }

    private var distanceText: String? {
        guard let m = distanceMeters else { return nil }
        return m < 1000 ? "\(Int((m / 10).rounded()) * 10) m" : String(format: "%.1f km", m / 1000)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            NavigationLink(value: stop) {
                VStack(alignment: .leading, spacing: 6) {
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        Text(stop.estimatedArrival ?? "Anytime")
                            .font(.subheadline.monospacedDigit().bold())
                            .foregroundStyle(statusColor)

                        if stop.isComplete {
                            Label("Done", systemImage: "checkmark.circle.fill")
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(.secondary)
                        } else if stop.isInProgress {
                            Label("In progress", systemImage: "play.circle.fill")
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(Color.MW.green)
                        }

                        Spacer()

                        if let distanceText {
                            Label(distanceText, systemImage: "location.fill")
                                .font(.caption.monospacedDigit())
                                .foregroundStyle(.secondary)
                        }
                        Text("\(routeOrder)/\(total)")
                            .font(.caption.monospacedDigit().weight(.semibold))
                            .foregroundStyle(.secondary)
                    }

                    Text(stop.headline ?? stop.propertyAddress)
                        .font(.body.bold())
                        .foregroundStyle(.primary)
                        .lineLimit(1)

                    Text(stop.headline == nil ? stop.propertyCity : stop.fullAddress)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)

                    if let line = stop.historyLine {
                        Label(line.service.map { "\($0): \(line.text)" } ?? line.text,
                              systemImage: line.warning ? "exclamationmark.circle.fill" : "clock.arrow.circlepath")
                            .font(.caption)
                            .lineLimit(1)
                            .foregroundStyle(line.warning ? Color.MW.orange : .secondary)
                    } else if let first = stop.visits.first {
                        Text(first.serviceTypeLabel + (stop.visits.count > 1 ? " +\(stop.visits.count - 1)" : ""))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)

            Spacer(minLength: 0)

            HStack(spacing: 8) {
                Button(action: openDirections) {
                    Label("Directions", systemImage: "arrow.triangle.turn.up.right.diamond.fill")
                        .font(.caption.weight(.semibold))
                        .frame(maxWidth: .infinity, minHeight: 34)
                        .foregroundStyle(.white)
                        .background(Color.MW.green, in: RoundedRectangle(cornerRadius: 9))
                }

                if canMove {
                    Button(action: onMove) {
                        Label("Move", systemImage: "calendar.badge.clock")
                            .font(.caption.weight(.semibold))
                            .frame(maxWidth: .infinity, minHeight: 34)
                            .foregroundStyle(Color.MW.green)
                            .background(Color.MW.green.opacity(0.10), in: RoundedRectangle(cornerRadius: 9))
                    }
                }
            }
            .buttonStyle(.plain)
        }
        .padding(14)
        .frame(height: Self.height)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
        .shadow(color: .black.opacity(0.14), radius: 12, x: 0, y: 3)
    }

    private func openDirections() {
        guard let lat = stop.latitude, let lng = stop.longitude else { return }
        let item = MKMapItem(placemark: MKPlacemark(
            coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lng)
        ))
        item.name = stop.headline ?? stop.fullAddress
        item.openInMaps(launchOptions: [MKLaunchOptionsDirectionsModeKey: MKLaunchOptionsDirectionsModeDriving])
    }
}

#Preview {
    NavigationStack {
        DayMapView(
            stops: [
                Stop(
                    stopId: 1, stopDate: "2026-05-03", stopStatus: "scheduled",
                    routeOrder: 1, estimatedArrival: "09:00",
                    propertyId: 1, propertyAddress: "3304 West 12th Avenue",
                    propertyCity: "Vancouver", propertyName: nil,
                    latitude: 49.2604, longitude: -123.1625,
                    contactId: 1, contactName: "Gary Hudson", contactPhone: nil, contactEmail: nil, onsiteContact: nil,
                    companyName: nil,
                    lawnSqft: nil, crewIds: [1], crewNames: ["Tim SCH"],
                    visitCount: 1,
                    visits: [Visit(visitId: 1, visitNumber: "V-001", serviceType: "lawn_care",
                                   planTitle: nil, planNumber: nil, visitStatus: "scheduled",
                                   estimatedDuration: 60, pricePerVisit: 120, scheduledStart: "09:00")],
                    propertyNotes: nil, stopNotes: nil
                )
            ],
            isLoading: false,
            isAdmin: true
        )
        .navigationDestination(for: Stop.self) { _ in EmptyView() }
    }
}
