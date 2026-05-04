//
//  VisitDetailView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI
import MapKit
import Contacts
import ContactsUI

struct VisitDetailView: View {

    let stop: Stop
    let isAdmin: Bool

    @EnvironmentObject private var authSession: AuthSession
    @State private var showContactSave = false
    @State private var contactSaveToast: String?

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
        .sheet(isPresented: $showContactSave) {
            if let contact = buildContact() {
                ContactSaveSheet(contact: contact) { saved in
                    contactSaveToast = saved ? "Saved to Contacts" : nil
                }
            }
        }
        .overlay(alignment: .bottom) {
            if let toast = contactSaveToast {
                Text(toast)
                    .font(.subheadline.bold())
                    .padding(.horizontal, 16)
                    .padding(.vertical, 10)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(Capsule())
                    .padding(.bottom, 24)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .onAppear {
                        DispatchQueue.main.asyncAfter(deadline: .now() + 2) {
                            withAnimation { contactSaveToast = nil }
                        }
                    }
            }
        }
        .animation(.easeInOut(duration: 0.25), value: contactSaveToast)
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
                    HStack {
                        detailRow(label: "Contact", value: contact)
                        Button {
                            showContactSave = true
                        } label: {
                            Image(systemName: "person.badge.plus")
                                .foregroundStyle(Color.MW.green)
                                .padding(.trailing, 16)
                        }
                        .accessibilityLabel("Save \(contact) to Contacts")
                    }
                }

                if let company = stop.companyName, !company.isEmpty {
                    Divider().padding(.leading, 16)
                    detailRow(label: "Company", value: company)
                }

                if let arrival = stop.estimatedArrival {
                    Divider().padding(.leading, 16)
                    detailRow(label: "Est. Arrival", value: arrival)
                }

                // Coordinates intentionally omitted — takes up space, not useful to crew.
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
                        .foregroundStyle(Color.MW.green)
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
                    .foregroundStyle(Color.MW.green)
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

            // "Start Job" / "Continue Job" — only for actionable visit states
            let status = visit.visitStatus.lowercased()
            if status == "scheduled" || status == "in_progress" {
                NavigationLink {
                    VisitWorkView(stop: stop, visit: visit, authSession: authSession)
                } label: {
                    Label(
                        status == "in_progress" ? "Continue Job" : "Start Job",
                        systemImage: status == "in_progress" ? "timer" : "play.fill"
                    )
                    .font(.subheadline.weight(.semibold))
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 10)
                    .background(status == "in_progress" ? Color.MW.green.opacity(0.15) : Color.MW.green)
                    .foregroundStyle(status == "in_progress" ? Color.MW.green : .white)
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                }
                .padding(.top, 4)
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
                            .foregroundStyle(Color.MW.green)
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

    // MARK: - Contact helpers

    private func buildContact() -> CNMutableContact? {
        guard let name = stop.contactName else { return nil }
        let contact = CNMutableContact()

        // Name — split on first space; if no space treat whole string as given name.
        let parts = name.split(separator: " ", maxSplits: 1)
        contact.givenName  = parts.first.map(String.init) ?? name
        contact.familyName = parts.count > 1 ? String(parts[1]) : ""

        if let company = stop.companyName, !company.isEmpty {
            contact.organizationName = company
        }

        let addr               = CNMutablePostalAddress()
        addr.street            = stop.propertyAddress
        addr.city              = stop.propertyCity
        addr.country           = "Canada"
        contact.postalAddresses = [CNLabeledValue(label: CNLabelHome, value: addr)]

        contact.note = "Mowology client — property ID \(stop.propertyId ?? 0)"

        return contact
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

// MARK: - ContactSaveSheet

private struct ContactSaveSheet: UIViewControllerRepresentable {

    let contact: CNMutableContact
    let onDismiss: (_ saved: Bool) -> Void

    func makeCoordinator() -> Coordinator { Coordinator(onDismiss: onDismiss) }

    func makeUIViewController(context: Context) -> UINavigationController {
        let vc = CNContactViewController(forUnknownContact: contact)
        vc.allowsEditing   = true
        vc.allowsActions   = false
        vc.delegate        = context.coordinator
        let nav = UINavigationController(rootViewController: vc)
        return nav
    }

    func updateUIViewController(_ uiViewController: UINavigationController, context: Context) {}

    final class Coordinator: NSObject, CNContactViewControllerDelegate {
        let onDismiss: (_ saved: Bool) -> Void
        private var didSave = false

        init(onDismiss: @escaping (_ saved: Bool) -> Void) { self.onDismiss = onDismiss }

        func contactViewController(_ vc: CNContactViewController,
                                   didCompleteWith contact: CNContact?) {
            didSave = contact != nil
            vc.dismiss(animated: true) { [weak self] in
                self?.onDismiss(self?.didSave ?? false)
            }
        }
    }
}

#Preview {
    let visit = Visit(
        visitId: 1001,
        visitNumber: "PLN-2026-0001-V001",
        serviceType: "lawn_care",
        planTitle: "Weekly Lawn Mowing",
        planNumber: "PLN-2026-0001",
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
        propertyId: 1,
        propertyAddress: "123 Oak Street",
        propertyCity: "Vancouver",
        propertyName: "Bob Jones Property",
        latitude: 49.2827,
        longitude: -123.1207,
        contactId: 1,
        contactName: "Bob Jones",
        companyName: nil,
        lawnSqft: nil,
        crewNames: ["John Doe", "Jane Smith"],
        visitCount: 1,
        visits: [visit]
    )

    return NavigationStack {
        VisitDetailView(stop: stop, isAdmin: true)
    }
}
