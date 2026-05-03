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

    @State private var selectedStop: Stop?
    @State private var position: MapCameraPosition = .automatic

    private var mappableStops: [Stop] {
        stops.filter { $0.latitude != nil && $0.longitude != nil }
    }

    // MARK: - Body

    var body: some View {
        ZStack(alignment: .bottom) {
            if !isLoading && stops.isEmpty {
                emptyState
            } else {
                map
            }

            if let stop = selectedStop {
                StopBottomCard(stop: stop) {
                    withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
                        selectedStop = nil
                    }
                }
                .transition(.asymmetric(
                    insertion: .move(edge: .bottom).combined(with: .opacity),
                    removal:   .move(edge: .bottom).combined(with: .opacity)
                ))
                .zIndex(1)
            }
        }
        .onAppear  { fitCamera(to: stops) }
        .onChange(of: stops) { _, new in
            withAnimation { selectedStop = nil }
            fitCamera(to: new)
        }
    }

    // MARK: - Map

    private var map: some View {
        Map(position: $position) {
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
                        isSelected: selectedStop?.id == stop.id,
                        routeOrder: (mappableStops.firstIndex(where: { $0.id == stop.id }) ?? 0) + 1
                    )
                    .onTapGesture {
                        withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
                            selectedStop = selectedStop?.id == stop.id ? nil : stop
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

    private func fitCamera(to stops: [Stop]) {
        let coords = stops.compactMap { stop -> CLLocationCoordinate2D? in
            guard let lat = stop.latitude, let lng = stop.longitude else { return nil }
            return CLLocationCoordinate2D(latitude: lat, longitude: lng)
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

// MARK: - Bottom Card

private struct StopBottomCard: View {

    let stop: Stop
    let onDismiss: () -> Void

    var body: some View {
        NavigationLink(value: stop) {
            VStack(alignment: .leading, spacing: 0) {

                // Drag handle
                Capsule()
                    .fill(Color(.systemGray4))
                    .frame(width: 36, height: 4)
                    .frame(maxWidth: .infinity)
                    .padding(.top, 10)
                    .padding(.bottom, 14)

                HStack(alignment: .top, spacing: 14) {

                    // Status icon
                    ZStack {
                        Circle()
                            .fill(stop.isComplete
                                  ? Color(.systemGray5)
                                  : Color.MW.green.opacity(0.12))
                            .frame(width: 46, height: 46)
                        Image(systemName: stop.isComplete
                              ? "checkmark.circle.fill"
                              : (stop.isInProgress ? "play.circle.fill" : "calendar.circle"))
                            .font(.system(size: 24))
                            .foregroundStyle(stop.isComplete ? Color(.systemGray2) : Color.MW.green)
                    }

                    VStack(alignment: .leading, spacing: 4) {
                        Text(stop.propertyAddress)
                            .font(.headline)
                            .foregroundStyle(.primary)
                            .lineLimit(1)
                        Text(stop.propertyCity)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)

                        // Service badges (up to 2)
                        if !stop.visits.isEmpty {
                            HStack(spacing: 5) {
                                ForEach(stop.visits.prefix(2)) { visit in
                                    Text(visit.serviceTypeLabel)
                                        .font(.caption.weight(.semibold))
                                        .foregroundStyle(Color.MW.green)
                                        .padding(.horizontal, 8)
                                        .padding(.vertical, 3)
                                        .background(Color.MW.green.opacity(0.1))
                                        .clipShape(Capsule())
                                }
                                if stop.visits.count > 2 {
                                    Text("+\(stop.visits.count - 2)")
                                        .font(.caption.weight(.semibold))
                                        .foregroundStyle(.secondary)
                                }
                            }
                            .padding(.top, 2)
                        }

                        // ETA
                        if let eta = stop.estimatedArrival {
                            HStack(spacing: 4) {
                                Image(systemName: "clock")
                                    .font(.caption2)
                                Text(eta)
                                    .font(.caption)
                            }
                            .foregroundStyle(.secondary)
                            .padding(.top, 2)
                        }
                    }

                    Spacer()

                    Image(systemName: "chevron.right")
                        .font(.system(size: 13, weight: .semibold))
                        .foregroundStyle(Color(.systemGray3))
                        .padding(.top, 4)
                }
                .padding(.horizontal, 20)
                .padding(.bottom, 20)
            }
            .background(Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
            .shadow(color: .black.opacity(0.12), radius: 16, x: 0, y: -4)
        }
        .buttonStyle(.plain)
        .padding(.horizontal, 12)
        .padding(.bottom, 8)
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
                    contactId: 1, contactName: "Gary Hudson", companyName: nil,
                    lawnSqft: nil, crewNames: ["Tim SCH"],
                    visitCount: 1,
                    visits: [Visit(visitId: 1, visitNumber: "V-001", serviceType: "lawn_care",
                                   planTitle: nil, planNumber: nil, visitStatus: "scheduled",
                                   estimatedDuration: 60, pricePerVisit: 120, scheduledStart: "09:00")]
                )
            ],
            isLoading: false,
            isAdmin: true
        )
        .navigationDestination(for: Stop.self) { _ in EmptyView() }
    }
}
