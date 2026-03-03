//
//  VisitDetailView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI
import MapKit

struct VisitDetailView: View {

    let stop: Stop
    let isAdmin: Bool

    private let mwGreen = Color(red: 0.176, green: 0.525, blue: 0.349)

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {

                // MARK: Property Section
                propertySection

                // MARK: Map
                mapSection

                // MARK: Visits Section
                visitsSection

                // MARK: Crew Section (admin only)
                if isAdmin && !stop.crewNames.isEmpty {
                    crewSection
                }

                Spacer(minLength: 24)
            }
            .padding(.horizontal, 16)
            .padding(.top, 16)
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle(stop.propertyAddress)
        .navigationBarTitleDisplayMode(.inline)
    }

    // MARK: - Property Section

    private var propertySection: some View {
        VStack(alignment: .leading, spacing: 0) {
            sectionHeader("Property", icon: "house.fill")

            VStack(spacing: 0) {
                detailRow(label: "Address", value: stop.propertyAddress)
                Divider().padding(.leading, 16)
                detailRow(label: "City", value: stop.propertyCity)

                if let contact = stop.contactName {
                    Divider().padding(.leading, 16)
                    detailRow(label: "Contact", value: contact)
                }

                if let company = stop.companyName, !company.isEmpty {
                    Divider().padding(.leading, 16)
                    detailRow(label: "Company", value: company)
                }

                if let arrival = stop.estimatedArrival {
                    Divider().padding(.leading, 16)
                    detailRow(label: "Est. Arrival", value: arrival)
                }

                if let lat = stop.latitude, let lon = stop.longitude {
                    Divider().padding(.leading, 16)
                    detailRow(label: "Coordinates", value: String(format: "%.4f, %.4f", lat, lon))
                }
            }
            .background(Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }

    // MARK: - Map Section

    private var mapSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            sectionHeader("Location", icon: "map.fill")

            if let lat = stop.latitude, let lon = stop.longitude {
                let coordinate = CLLocationCoordinate2D(latitude: lat, longitude: lon)
                mapPlaceholder(coordinate: coordinate)
            } else {
                noLocationPlaceholder
            }
        }
    }

    private func mapPlaceholder(coordinate: CLLocationCoordinate2D) -> some View {
        ZStack(alignment: .bottomTrailing) {
            // Native MapKit map view.
            Map(initialPosition: .region(
                MKCoordinateRegion(
                    center: coordinate,
                    span: MKCoordinateSpan(latitudeDelta: 0.005, longitudeDelta: 0.005)
                )
            )) {
                Annotation(stop.propertyAddress, coordinate: coordinate) {
                    Image(systemName: "mappin.circle.fill")
                        .font(.title)
                        .foregroundStyle(mwGreen)
                }
            }
            .frame(height: 200)
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .disabled(true)  // Disable interaction — tapping opens Apple Maps below.

            // "Open in Maps" button overlaid on the map.
            Button {
                openInMaps(coordinate: coordinate)
            } label: {
                Label("Open in Maps", systemImage: "arrow.triangle.turn.up.right.circle.fill")
                    .font(.caption.bold())
                    .padding(.horizontal, 10)
                    .padding(.vertical, 6)
                    .background(.ultraThinMaterial)
                    .clipShape(Capsule())
                    .foregroundStyle(mwGreen)
            }
            .padding(10)
        }
    }

    private var noLocationPlaceholder: some View {
        HStack {
            Spacer()
            VStack(spacing: 8) {
                Image(systemName: "mappin.slash")
                    .font(.title2)
                    .foregroundStyle(Color(.systemGray3))

                Text("No coordinates available")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
            Spacer()
        }
        .frame(height: 100)
        .background(Color(.systemGray6))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Visits Section

    private var visitsSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            sectionHeader("Visits (\(stop.visits.count))", icon: "checklist")

            VStack(spacing: 10) {
                ForEach(stop.visits) { visit in
                    visitCard(visit)
                }
            }
        }
    }

    private func visitCard(_ visit: Visit) -> some View {
        VStack(alignment: .leading, spacing: 10) {

            // Title Row
            HStack {
                Text(visit.planTitle ?? visit.serviceTypeLabel)
                    .font(.subheadline.bold())
                    .foregroundStyle(.primary)

                Spacer()

                // Status Badge
                Text(visit.statusLabel)
                    .font(.caption.bold())
                    .padding(.horizontal, 8)
                    .padding(.vertical, 3)
                    .background(visit.statusColor.opacity(0.15))
                    .foregroundStyle(visit.statusColor)
                    .clipShape(Capsule())
            }

            // Service Type
            Label(visit.serviceTypeLabel, systemImage: "tag")
                .font(.caption)
                .foregroundStyle(.secondary)

            HStack(spacing: 16) {
                // Duration
                if let duration = visit.estimatedDuration {
                    Label("\(duration) min", systemImage: "clock")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                // Price
                if let price = visit.pricePerVisit {
                    Label(String(format: "$%.2f", price), systemImage: "dollarsign.circle")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                // Scheduled Start
                if let start = visit.scheduledStart {
                    Label(start, systemImage: "clock.arrow.circlepath")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .padding(14)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(visit.statusColor.opacity(0.2), lineWidth: 1)
        )
    }

    // MARK: - Crew Section

    private var crewSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            sectionHeader("Crew", icon: "person.2.fill")

            VStack(spacing: 0) {
                ForEach(Array(stop.crewNames.enumerated()), id: \.offset) { index, name in
                    HStack {
                        Image(systemName: "person.circle.fill")
                            .foregroundStyle(mwGreen)
                            .font(.title3)

                        Text(name)
                            .font(.body)
                            .foregroundStyle(.primary)

                        Spacer()
                    }
                    .padding(.horizontal, 16)
                    .padding(.vertical, 12)

                    if index < stop.crewNames.count - 1 {
                        Divider().padding(.leading, 52)
                    }
                }
            }
            .background(Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }

    // MARK: - Sub-views

    private func sectionHeader(_ title: String, icon: String) -> some View {
        Label(title, systemImage: icon)
            .font(.footnote.weight(.semibold))
            .foregroundStyle(.secondary)
            .textCase(.uppercase)
            .padding(.leading, 4)
    }

    private func detailRow(label: String, value: String) -> some View {
        HStack {
            Text(label)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .frame(width: 110, alignment: .leading)

            Text(value)
                .font(.subheadline)
                .foregroundStyle(.primary)

            Spacer()
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
    }

    // MARK: - Actions

    private func openInMaps(coordinate: CLLocationCoordinate2D) {
        let mapItem = MKMapItem(
            placemark: MKPlacemark(
                coordinate: coordinate,
                addressDictionary: [
                    "Street": stop.propertyAddress,
                    "City":   stop.propertyCity
                ]
            )
        )
        mapItem.name = stop.propertyAddress
        mapItem.openInMaps(launchOptions: [
            MKLaunchOptionsDirectionsModeKey: MKLaunchOptionsDirectionsModeDriving
        ])
    }
}

#Preview {
    let visit = Visit(
        visitId: 1001,
        visitNumber: 1,
        serviceType: "lawn_care",
        planTitle: "Weekly Lawn Mowing",
        visitStatus: "scheduled",
        estimatedDuration: 45,
        pricePerVisit: 75.00,
        scheduledStart: "09:00"
    )

    let stop = Stop(
        stopId: 42,
        stopDate: "2026-03-03",
        stopStatus: "scheduled",
        routeOrder: 1,
        estimatedArrival: "09:00",
        propertyAddress: "123 Oak Street",
        propertyCity: "Vancouver",
        latitude: 49.2827,
        longitude: -123.1207,
        contactName: "Bob Jones",
        companyName: nil,
        crewNames: ["John Doe", "Jane Smith"],
        visitCount: 1,
        visits: [visit]
    )

    return NavigationStack {
        VisitDetailView(stop: stop, isAdmin: true)
    }
}
