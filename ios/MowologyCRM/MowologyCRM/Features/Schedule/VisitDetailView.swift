//
//  VisitDetailView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI
import MapKit

struct VisitDetailView: View {

    let stop:        Stop
    let isAdmin:     Bool

    @StateObject private var timerVM: VisitDetailViewModel
    private let authSession: AuthSession

    // MARK: - Init

    init(stop: Stop, isAdmin: Bool, authSession: AuthSession) {
        self.stop        = stop
        self.isAdmin     = isAdmin
        self.authSession = authSession
        _timerVM         = StateObject(
            wrappedValue: VisitDetailViewModel(authSession: authSession)
        )
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {

                // Timer error banner — shown above content so crew sees it immediately.
                if let err = timerVM.errorMessage {
                    ErrorBannerView(message: err) {
                        timerVM.errorMessage = nil
                    }
                    .padding(.horizontal, 16)
                }

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
        .task {
            let visitIds = stop.visits.map { $0.visitId }
            await timerVM.syncState(visitIds: visitIds)
        }
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
            .disabled(true)

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
        let isThisTimerActive = timerVM.activeVisitId == visit.visitId
        let canStart = !timerVM.isTimerRunning
            && ["scheduled", "in_progress"].contains(visit.visitStatus.lowercased())
        let canStop  = isThisTimerActive

        return VStack(alignment: .leading, spacing: 10) {

            // Title Row
            HStack {
                Text(visit.planTitle ?? visit.serviceTypeLabel)
                    .font(.subheadline.bold())
                    .foregroundStyle(.primary)

                Spacer()

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
                if let duration = visit.estimatedDuration {
                    Label("\(duration) min", systemImage: "clock")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                if let price = visit.pricePerVisit {
                    Label(String(format: "$%.2f", price), systemImage: "dollarsign.circle")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                if let start = visit.scheduledStart {
                    Label(start, systemImage: "clock.arrow.circlepath")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            // MARK: Timer Controls
            // Only shown for actionable visits (scheduled / in_progress).
            // When this timer is running, show elapsed time + Stop button.
            // When no timer is running, show Start button.
            if canStart || canStop {
                Divider()

                HStack {
                    if isThisTimerActive {
                        // Elapsed time chip
                        Label(timerVM.elapsedFormatted, systemImage: "timer")
                            .font(.callout.monospacedDigit().bold())
                            .foregroundStyle(Color.MW.green)
                    }

                    Spacer()

                    if canStop {
                        Button {
                            Task { await timerVM.stopTimer(visitId: visit.visitId) }
                        } label: {
                            Label("Stop Job", systemImage: "stop.circle.fill")
                                .font(.subheadline.bold())
                                .padding(.horizontal, 14)
                                .padding(.vertical, 8)
                                .background(Color.red.opacity(0.12))
                                .foregroundStyle(.red)
                                .clipShape(Capsule())
                        }
                        .disabled(timerVM.isLoading)

                    } else if canStart {
                        Button {
                            Task { await timerVM.startTimer(visitId: visit.visitId) }
                        } label: {
                            Label("Start Job", systemImage: "play.circle.fill")
                                .font(.subheadline.bold())
                                .padding(.horizontal, 14)
                                .padding(.vertical, 8)
                                .background(Color.MW.green.opacity(0.12))
                                .foregroundStyle(Color.MW.green)
                                .clipShape(Capsule())
                        }
                        .disabled(timerVM.isLoading)
                    }
                }
            }

            // MARK: Before/After Photo Proof
            // Shown for in_progress visits, and for scheduled visits so crew can
            // capture a "before" photo before pressing Start. The "after" slot
            // is locked until the job timer is running.
            if ["scheduled", "in_progress"].contains(visit.visitStatus.lowercased()) {
                Divider()
                JobPhotoSection(
                    visitId:     visit.visitId,
                    isActive:    isThisTimerActive,
                    authSession: authSession
                )
            }
        }
        .padding(14)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(
                    isThisTimerActive
                        ? Color.MW.green.opacity(0.5)
                        : visit.statusColor.opacity(0.2),
                    lineWidth: isThisTimerActive ? 2 : 1
                )
        )
    }

    // MARK: - Crew Section

    private var crewSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            sectionHeader("Crew", icon: "person.2.fill")

            VStack(spacing: 0) {
                // Use rich CrewMember data when available (admin role), otherwise
                // fall back to the name-only crew_names array.
                if let members = stop.crewMembers, !members.isEmpty {
                    ForEach(Array(members.enumerated()), id: \.element.id) { index, member in
                        crewMemberRow(member: member)
                        if index < members.count - 1 {
                            Divider().padding(.leading, 52)
                        }
                    }
                } else {
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
            }
            .background(Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }

    private func crewMemberRow(member: CrewMember) -> some View {
        HStack(spacing: 12) {
            Image(systemName: "person.circle.fill")
                .foregroundStyle(Color.MW.green)
                .font(.title3)

            VStack(alignment: .leading, spacing: 2) {
                Text(member.name)
                    .font(.body)
                    .foregroundStyle(.primary)
                if let phone = member.phone {
                    Text(phone)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            Spacer()

            // Tap-to-call and tap-to-message buttons (admin view)
            if let callURL = member.callURL {
                Link(destination: callURL) {
                    Image(systemName: "phone.fill")
                        .font(.subheadline)
                        .foregroundStyle(Color.MW.green)
                        .padding(8)
                        .background(Color.MW.green.opacity(0.10))
                        .clipShape(Circle())
                }

                if let smsURL = URL(string: "sms:\(member.phone?.components(separatedBy: CharacterSet.decimalDigits.inverted).joined() ?? "")") {
                    Link(destination: smsURL) {
                        Image(systemName: "message.fill")
                            .font(.subheadline)
                            .foregroundStyle(Color.MW.green)
                            .padding(8)
                            .background(Color.MW.green.opacity(0.10))
                            .clipShape(Circle())
                    }
                }
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
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
        crewMembers: nil,
        visitCount: 1,
        visits: [visit]
    )

    return NavigationStack {
        VisitDetailView(stop: stop, isAdmin: true, authSession: AuthSession())
    }
}
