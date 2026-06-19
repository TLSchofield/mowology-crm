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
    @Environment(\.openURL) private var openURL

    /// The visit being completed via the completion sheet (extras + invoice).
    @State private var completingVisit: Visit?


    // MARK: - Init

    init(stop: Stop, isAdmin: Bool, authSession: AuthSession) {
        self.stop        = stop
        self.isAdmin     = isAdmin
        self.authSession = authSession
        let client       = APIClient(authSession: authSession)
        _viewModel       = StateObject(wrappedValue: VisitDetailViewModel(stop: stop, apiClient: client))
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

                compactPropertySection
                accessNotesSection
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
        .task { await viewModel.checkClockStatus() }
        .sheet(item: $completingVisit) { visit in
            VisitCompletionSheet(visit: visit, detailVM: viewModel, authSession: authSession)
        }
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

    // MARK: - Compact Property Header

    private var compactPropertySection: some View {
        VStack(alignment: .leading, spacing: 8) {
            sectionHeader("Property", icon: "house.fill")

            VStack(spacing: 0) {

                // Row 1: map-pin icon (tappable) + address/city + arrival + Maps pill
                HStack(spacing: 12) {
                    Button { openInMaps() } label: {
                        Image(systemName: "mappin.circle.fill")
                            .font(.title2)
                            .foregroundStyle(Color.MW.green)
                    }
                    .buttonStyle(.plain)

                    VStack(alignment: .leading, spacing: 1) {
                        Text(stop.propertyAddress)
                            .font(.subheadline.bold())
                            .foregroundStyle(.primary)
                            .lineLimit(1)
                        Text(stop.propertyCity)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }

                    Spacer()

                    if let arrival = stop.estimatedArrival {
                        Text(arrival)
                            .font(.subheadline.monospacedDigit().bold())
                            .foregroundStyle(Color.MW.green)
                    }

                    Button { openInMaps() } label: {
                        HStack(spacing: 4) {
                            Image(systemName: "arrow.triangle.turn.up.right.circle.fill")
                            Text("Maps")
                        }
                        .font(.caption.bold())
                        .padding(.horizontal, 10)
                        .padding(.vertical, 5)
                        .background(Color.MW.green.opacity(0.1))
                        .foregroundStyle(Color.MW.green)
                        .clipShape(Capsule())
                    }
                    .buttonStyle(.plain)
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 12)

                // Row 2: contact name + optional Call pill
                let displayName = stop.contactName ?? stop.companyName
                if let name = displayName {
                    Divider().padding(.leading, 52)

                    HStack(spacing: 12) {
                        Image(systemName: "person.circle.fill")
                            .font(.title2)
                            .foregroundStyle(Color(.systemGray3))

                        Text(name)
                            .font(.subheadline.bold())
                            .foregroundStyle(.primary)
                            .lineLimit(1)

                        Spacer()

                        if let phone = stop.contactPhone, !phone.isEmpty {
                            let digits = phone.filter { $0.isNumber || $0 == "+" }
                            Button {
                                if let url = URL(string: "tel:\(digits)") { openURL(url) }
                            } label: {
                                HStack(spacing: 4) {
                                    Image(systemName: "phone.fill")
                                    Text("Call")
                                }
                                .font(.caption.bold())
                                .padding(.horizontal, 10)
                                .padding(.vertical, 5)
                                .background(Color.MW.green.opacity(0.1))
                                .foregroundStyle(Color.MW.green)
                                .clipShape(Capsule())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(.horizontal, 16)
                    .padding(.vertical, 12)
                }
            }
            .background(Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }

    // MARK: - Access Notes Section

    @ViewBuilder
    private var accessNotesSection: some View {
        let hasPropertyNotes = !(stop.propertyNotes ?? "").isEmpty
        let hasStopNotes     = !(stop.stopNotes ?? "").isEmpty

        if hasPropertyNotes || hasStopNotes {
            VStack(alignment: .leading, spacing: 8) {
                sectionHeader("Access Info", icon: "key.fill")

                VStack(spacing: 8) {
                    if let notes = stop.propertyNotes, !notes.isEmpty {
                        noteStrip(
                            icon:        "exclamationmark.triangle.fill",
                            label:       "SITE NOTES",
                            text:        notes,
                            tint:        Color.MW.orange
                        )
                    }

                    if let notes = stop.stopNotes, !notes.isEmpty {
                        noteStrip(
                            icon:        "note.text",
                            label:       "TODAY",
                            text:        notes,
                            tint:        Color.blue
                        )
                    }
                }
            }
        }
    }

    private func noteStrip(icon: String, label: String, text: String, tint: Color) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon)
                .foregroundStyle(tint)
                .font(.subheadline)
                .padding(.top, 1)

            VStack(alignment: .leading, spacing: 3) {
                Text(label)
                    .font(.caption2.weight(.semibold))
                    .foregroundStyle(tint)
                    .tracking(0.5)
                Text(text)
                    .font(.subheadline)
                    .foregroundStyle(Color.primary)
                    .fixedSize(horizontal: false, vertical: true)
            }

            Spacer(minLength: 0)
        }
        .padding(12)
        .background(tint.opacity(0.07))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(RoundedRectangle(cornerRadius: 10).stroke(tint.opacity(0.22), lineWidth: 1))
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

            // MARK: Action CTA — Start Job / Mark Complete
            Divider()
            actionButtons(for: visit, currentStatus: currentStatus)

            // MARK: Before/After Photo Proof + Heart Endorsement
            if ["scheduled", "in_progress"].contains(visit.visitStatus.lowercased()) {
                let isVisitFlagged  = viewModel.isFlagged(for: visit)
                let isFlagLoading   = viewModel.flagLoadingIds.contains(visit.visitId)

                Divider()
                JobPhotoSection(
                    visitId:       visit.visitId,
                    isActive:      isActive,
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
        }
        .padding(14)
        .background(
            currentStatus.lowercased() == "in_progress"
                ? Color.MW.green.opacity(0.03)
                : Color(.systemBackground)
        )
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(statusColor.opacity(0.2), lineWidth: 1)
        )
        .overlay(alignment: .leading) {
            if currentStatus.lowercased() == "in_progress" {
                RoundedRectangle(cornerRadius: 2)
                    .frame(width: 4)
                    .foregroundStyle(Color.MW.green)
                    .padding(.vertical, 4)
            }
        }
    }

    @ViewBuilder
    private func actionButtons(for visit: Visit, currentStatus: String) -> some View {
        let isThisLoading = viewModel.isLoading

        switch currentStatus.lowercased() {
        case "scheduled":
            VStack(spacing: 8) {
                Button {
                    Task { await viewModel.startJob(visitId: visit.visitId) }
                } label: {
                    Group {
                        if isThisLoading {
                            HStack(spacing: 8) {
                                ProgressView().tint(.white)
                                Text("Starting…").font(.subheadline.bold())
                            }
                        } else {
                            Label("Start Job", systemImage: "play.fill")
                                .font(.subheadline.bold())
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(Capsule())
                }
                .disabled(isThisLoading)

                // Clock warning — shown only when we know the clock is off.
                if viewModel.isClockedIn == false {
                    HStack(spacing: 6) {
                        Image(systemName: "clock.badge.exclamationmark.fill")
                            .font(.caption)
                            .foregroundStyle(Color.MW.orange)
                        Text("Not clocked in — starting this job will clock you in automatically.")
                            .font(.caption)
                            .foregroundStyle(Color.MW.orange)
                        Spacer(minLength: 0)
                    }
                    .padding(.horizontal, 10)
                    .padding(.vertical, 7)
                    .background(Color.MW.orange.opacity(0.08))
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                    .overlay(RoundedRectangle(cornerRadius: 8).stroke(Color.MW.orange.opacity(0.2), lineWidth: 1))
                }
            }

        case "in_progress":
            Button {
                completingVisit = visit
            } label: {
                Label("Mark Complete", systemImage: "checkmark.circle.fill")
                    .font(.subheadline.bold())
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(Capsule())
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

    private func openInMaps() {
        if let lat = stop.latitude, let lon = stop.longitude {
            let mapItem = MKMapItem(
                placemark: MKPlacemark(
                    coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lon),
                    addressDictionary: ["Street": stop.propertyAddress, "City": stop.propertyCity]
                )
            )
            mapItem.name = stop.propertyAddress
            mapItem.openInMaps(launchOptions: [
                MKLaunchOptionsDirectionsModeKey: MKLaunchOptionsDirectionsModeDriving
            ])
        } else {
            // No coordinates — fall back to address search
            let query = "\(stop.propertyAddress), \(stop.propertyCity)"
            if let encoded = query.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed),
               let url = URL(string: "maps://?q=\(encoded)") {
                openURL(url)
            }
        }
    }
}
