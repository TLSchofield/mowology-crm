//
//  VisitDetailView.swift
//  MowologyCRM
//

import SwiftUI
import MapKit

struct VisitDetailView: View {

    let stop: Stop
    let isAdmin: Bool
    private let authSession: AuthSession

    @StateObject private var viewModel: VisitDetailViewModel


    // MARK: - Init

    init(stop: Stop, isAdmin: Bool, authSession: AuthSession,
         onStatusChange: ((Int, String) -> Void)? = nil) {
        self.stop        = stop
        self.isAdmin     = isAdmin
        self.authSession = authSession
        let client       = APIClient(authSession: authSession)
        _viewModel       = StateObject(wrappedValue: VisitDetailViewModel(
            stop:           stop,
            apiClient:      client,
            onStatusChange: onStatusChange
        ))
    }

    // MARK: - Body

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {

                // Auto clock-in notice
                if let notice = viewModel.autoClockInNotice {
                    noticeBanner(notice) { viewModel.autoClockInNotice = nil }
                }

                // Error banner
                if let error = viewModel.errorMessage {
                    errorBanner(error)
                }

                propertySection
                mapSection

                visitsSection

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

    // MARK: - Notice Banner (green — informational)

    private func noticeBanner(_ message: String, onDismiss: @escaping () -> Void) -> some View {
        HStack(spacing: 10) {
            Image(systemName: "clock.badge.checkmark.fill")
                .foregroundStyle(Color.MW.green)
            Text(message)
                .font(.subheadline)
                .foregroundStyle(Color.MW.green)
            Spacer()
            Button { onDismiss() } label: {
                Image(systemName: "xmark")
                    .font(.caption.bold())
                    .foregroundStyle(Color.MW.green)
            }
        }
        .padding(12)
        .background(Color.MW.green.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(RoundedRectangle(cornerRadius: 10).stroke(Color.MW.green.opacity(0.25), lineWidth: 1))
    }

    // MARK: - Error Banner

    private func errorBanner(_ message: String) -> some View {
        HStack(spacing: 10) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundStyle(Color.MW.orange)
            Text(message)
                .font(.subheadline)
                .foregroundStyle(Color.MW.orange)
            Spacer()
            Button { viewModel.errorMessage = nil } label: {
                Image(systemName: "xmark")
                    .font(.caption.bold())
                    .foregroundStyle(Color.MW.orange)
            }
        }
        .padding(12)
        .background(Color.MW.orange.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(RoundedRectangle(cornerRadius: 10).stroke(Color.MW.orange.opacity(0.25), lineWidth: 1))
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
                mapView(coordinate: coordinate)
            } else {
                noLocationPlaceholder
            }
        }
    }

    private func mapView(coordinate: CLLocationCoordinate2D) -> some View {
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

            Button { openInMaps(coordinate: coordinate) } label: {
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
        let currentStatus = viewModel.status(for: visit)
        let statusColor   = statusColorFor(currentStatus)
        let isActive      = viewModel.activeTimerVisitId == visit.visitId

        return VStack(alignment: .leading, spacing: 10) {

            // Title + badge row
            HStack {
                Text(visit.planTitle ?? visit.serviceTypeLabel)
                    .font(.subheadline.bold())

                Spacer()

                Text(statusLabelFor(currentStatus))
                    .font(.caption.bold())
                    .padding(.horizontal, 8)
                    .padding(.vertical, 3)
                    .background(statusColor.opacity(0.15))
                    .foregroundStyle(statusColor)
                    .clipShape(Capsule())
            }

            // Service type
            Label(visit.serviceTypeLabel, systemImage: "tag")
                .font(.caption)
                .foregroundStyle(.secondary)

            // Meta row
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

            // Live elapsed timer
            if isActive {
                HStack(spacing: 6) {
                    Image(systemName: "timer")
                        .foregroundStyle(Color.MW.green)
                        .font(.caption)
                    Text(viewModel.elapsedFormatted)
                        .font(.caption.monospacedDigit().bold())
                        .foregroundStyle(Color.MW.green)
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 5)
                .background(Color.MW.green.opacity(0.08))
                .clipShape(Capsule())
            }

            // Pending-sync chip — shown when the local status is ahead of the server.
            if viewModel.isPendingSync(visit) {
                HStack(spacing: 6) {
                    Image(systemName: "arrow.triangle.2.circlepath")
                        .font(.caption)
                        .foregroundStyle(Color.MW.orange)
                    Text("Saved · pending sync")
                        .font(.caption.weight(.medium))
                        .foregroundStyle(Color.MW.orange)
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 5)
                .background(Color.MW.orange.opacity(0.10))
                .clipShape(Capsule())
            }

            // MARK: Before/After Photo Proof + Heart Endorsement
            if ["scheduled", "in_progress"].contains(currentStatus.lowercased()) {
                let isVisitFlagged  = viewModel.isFlagged(for: visit)
                let isFlagLoading   = viewModel.flagLoadingIds.contains(visit.visitId)

                Divider()
                JobPhotoSection(
                    visitId:       visit.visitId,
                    isActive:      isActive || currentStatus.lowercased() == "in_progress",
                    authSession:   authSession,
                    isFlagged:     isVisitFlagged,
                    isFlagLoading: isFlagLoading,
                    onFlagToggle:  { await viewModel.toggleFlag(visit) }
                )

                // Review earned strip — visible once the client has actually reviewed.
                if isVisitFlagged && visit.contactHasReviewed {
                    HStack(spacing: 0) {
                        Image(systemName: "heart.fill")
                            .foregroundStyle(Color.MW.orange)
                            .font(.caption)
                            .padding(.trailing, 6)
                        Text("You endorsed this visit · ")
                            .font(.caption)
                            .foregroundStyle(Color.MW.dark)
                        Text("Review received ★★★★★")
                            .font(.caption.bold())
                            .foregroundStyle(Color.MW.dark)
                        Spacer(minLength: 0)
                    }
                    .padding(.horizontal, 10)
                    .padding(.vertical, 7)
                    .background(Color.MW.green.opacity(0.08))
                    .overlay(
                        RoundedRectangle(cornerRadius: 8)
                            .stroke(Color.MW.green.opacity(0.25), lineWidth: 1)
                    )
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                }
            }

            // MARK: Start / Complete buttons — drive the live state machine.
            actionButtons(for: visit, currentStatus: currentStatus)
        }
        .padding(14)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(statusColor.opacity(0.2), lineWidth: 1)
        )
    }

    @ViewBuilder
    private func actionButtons(for visit: Visit, currentStatus: String) -> some View {
        let isThisLoading = viewModel.isLoading

        switch currentStatus.lowercased() {
        case "scheduled":
            Button {
                Task { await viewModel.startJob(visitId: visit.visitId) }
            } label: {
                Label("Start Job", systemImage: "play.fill")
                    .font(.subheadline.bold())
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 10)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 8))
            }
            .disabled(isThisLoading)

        case "in_progress":
            Button {
                Task { await viewModel.completeJob(visitId: visit.visitId) }
            } label: {
                Label("Mark Complete", systemImage: "checkmark.circle.fill")
                    .font(.subheadline.bold())
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 10)
                    .background(Color.green)
                    .foregroundStyle(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 8))
            }
            .disabled(isThisLoading)

        default:
            EmptyView()
        }
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

    // MARK: - Shared Sub-views

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
            Spacer()
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
    }

    // MARK: - Status Helpers

    private func statusColorFor(_ status: String) -> Color {
        switch status.lowercased() {
        case "completed":   return .green
        case "in_progress": return Color.MW.green
        case "scheduled":   return .blue
        case "cancelled":   return .red
        case "skipped":     return .orange
        default:            return .gray
        }
    }

    private func statusLabelFor(_ status: String) -> String {
        switch status.lowercased() {
        case "in_progress": return "In Progress"
        case "completed":   return "Completed"
        case "scheduled":   return "Scheduled"
        case "cancelled":   return "Cancelled"
        case "skipped":     return "Skipped"
        default:
            return status.replacingOccurrences(of: "_", with: " ").capitalized
        }
    }

    // MARK: - Actions

    private func openInMaps(coordinate: CLLocationCoordinate2D) {
        let mapItem = MKMapItem(
            placemark: MKPlacemark(
                coordinate: coordinate,
                addressDictionary: ["Street": stop.propertyAddress, "City": stop.propertyCity]
            )
        )
        mapItem.name = stop.propertyAddress
        mapItem.openInMaps(launchOptions: [
            MKLaunchOptionsDirectionsModeKey: MKLaunchOptionsDirectionsModeDriving
        ])
    }
}
