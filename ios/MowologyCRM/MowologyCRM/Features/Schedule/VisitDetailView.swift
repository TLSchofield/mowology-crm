//
//  VisitDetailView.swift
//  MowologyCRM
//
//  Elite field-worker-optimised visit detail. Designed for crews working
//  outdoors with gloves and bright sun: high contrast, large tap targets,
//  timer-dominant when active, photo proof state always visible at a glance.
//
//  Layout sections (top → bottom):
//    1. compactHeader     — state pill + stop counter, address, contact · service,
//                           Directions chip
//    2. heroTimerBand     — present only when this stop has an active timer
//    3. visitsBlock       — per-visit card: status, photo proof cells, Start/
//                           Complete CTA
//    4. infoChipsRow      — Lawn Size · Total Duration · Visit Total tiles
//    5. notesBlock        — gate codes, hazards (renders only if Stop.notes set)
//    6. crewAvatarsRow    — initials avatars in MW.green
//
//  The crew never sees an embedded MapKit preview — the Directions chip
//  launches Apple Maps directly, which is what they actually use.
//
//  Photo cells are visual stubs in this revision. The PoW capture system
//  (JobPhotoSection / JobPhotoQueue / /api/schedule/job-photo) lives on
//  `main` and will be ported in a follow-up PR; the cells slot in cleanly
//  once that lands.
//

import SwiftUI
import MapKit

struct VisitDetailView: View {

    let stop:        Stop
    let isAdmin:     Bool
    let totalStops:  Int?

    @StateObject private var viewModel: VisitDetailViewModel

    // MARK: - Init

    init(stop: Stop, isAdmin: Bool, authSession: AuthSession, totalStops: Int? = nil) {
        self.stop       = stop
        self.isAdmin    = isAdmin
        self.totalStops = totalStops
        let client      = APIClient(authSession: authSession)
        _viewModel      = StateObject(
            wrappedValue: VisitDetailViewModel(stop: stop, apiClient: client)
        )
    }

    // MARK: - Body

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {

                if let notice = viewModel.autoClockInNotice {
                    noticeBanner(notice) { viewModel.autoClockInNotice = nil }
                }

                if let error = viewModel.errorMessage {
                    errorBanner(error)
                }

                compactHeader

                if let activeId = viewModel.activeTimerVisitId,
                   stop.visits.contains(where: { $0.visitId == activeId }) {
                    heroTimerBand(activeVisitId: activeId)
                }

                visitsBlock

                infoChipsRow

                if let notes = stopNotes {
                    notesBlock(notes)
                }

                if !stop.crewNames.isEmpty {
                    crewAvatarsRow
                }

                Spacer(minLength: 24)
            }
            .padding(.horizontal, 16)
            .padding(.top, 12)
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle(stop.propertyAddress)
        .navigationBarTitleDisplayMode(.inline)
    }

    // MARK: - 1. Compact Header

    private var compactHeader: some View {
        VStack(alignment: .leading, spacing: 10) {

            // Top row: state pill + (stop counter | est. arrival)
            HStack(alignment: .center, spacing: 8) {
                statePill
                Spacer()
                if let counter = stopCounterLabel {
                    Text(counter)
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(.secondary)
                        .textCase(.uppercase)
                        .tracking(0.4)
                }
                if let arrival = stop.estimatedArrival {
                    Text("Est. \(arrival)")
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(.secondary)
                        .textCase(.uppercase)
                        .tracking(0.4)
                }
            }

            // Title — propertyAddress
            Text(stop.propertyAddress)
                .font(.title3.weight(.bold))
                .foregroundStyle(.primary)
                .lineLimit(2)

            // Subtitle — contact · primary service
            if let subtitle = headerSubtitle {
                Text(subtitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }

            // Action chips — Directions (when coords)
            if let lat = stop.latitude, let lon = stop.longitude {
                let coord = CLLocationCoordinate2D(latitude: lat, longitude: lon)
                HStack(spacing: 8) {
                    Button {
                        openInMaps(coordinate: coord)
                    } label: {
                        actionChip(icon: "arrow.triangle.turn.up.right.circle.fill",
                                   label: "Directions")
                    }
                    .buttonStyle(.plain)
                    Spacer()
                }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 14))
        .overlay(
            RoundedRectangle(cornerRadius: 14)
                .stroke(headerBorderColor, lineWidth: 1)
        )
    }

    private var statePill: some View {
        HStack(spacing: 6) {
            Circle()
                .fill(stateAccent)
                .frame(width: 6, height: 6)
            Text(stateLabel)
                .font(.caption2.weight(.bold))
                .foregroundStyle(stateAccent)
                .tracking(0.6)
                .textCase(.uppercase)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
        .background(stateAccent.opacity(0.12))
        .clipShape(Capsule())
        .overlay(
            Capsule().stroke(stateAccent.opacity(0.3), lineWidth: 1)
        )
    }

    private func actionChip(icon: String, label: String) -> some View {
        Label(label, systemImage: icon)
            .font(.caption.weight(.semibold))
            .foregroundStyle(Color.MW.green)
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(Color.MW.green.opacity(0.10))
            .clipShape(Capsule())
            .overlay(
                Capsule().stroke(Color.MW.green.opacity(0.25), lineWidth: 1)
            )
    }

    // MARK: - 2. Hero Timer Band

    private func heroTimerBand(activeVisitId: Int) -> some View {
        HStack(alignment: .center, spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text("ELAPSED")
                    .font(.caption2.weight(.semibold))
                    .foregroundStyle(.white.opacity(0.6))
                    .tracking(0.6)
                Text(viewModel.elapsedFormatted)
                    .font(.system(size: 38, weight: .light, design: .rounded))
                    .monospacedDigit()
                    .foregroundStyle(Color.MW.lime)
                    .lineLimit(1)
            }

            Spacer()

            Button {
                Task { await viewModel.completeJob(visitId: activeVisitId) }
            } label: {
                Label("Complete", systemImage: "checkmark.circle.fill")
                    .font(.subheadline.weight(.bold))
                    .padding(.horizontal, 16)
                    .padding(.vertical, 10)
                    .foregroundStyle(.white)
                    .background(Color.MW.green)
                    .clipShape(Capsule())
            }
            .disabled(viewModel.isLoading)
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 14)
        .background(
            LinearGradient(
                colors: [Color.MW.forest, Color.MW.dark],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
        )
        .clipShape(RoundedRectangle(cornerRadius: 14))
    }

    // MARK: - 3. Visits Block

    private var visitsBlock: some View {
        VStack(spacing: 10) {
            ForEach(stop.visits) { visit in
                visitCard(visit)
            }
        }
    }

    private func visitCard(_ visit: Visit) -> some View {
        let currentStatus = viewModel.status(for: visit)
        let statusColor   = statusColorFor(currentStatus)
        let isThisActive  = viewModel.activeTimerVisitId == visit.visitId
        let normalised    = currentStatus.lowercased()
        let isActionable  = normalised == "scheduled" || normalised == "in_progress"

        return VStack(alignment: .leading, spacing: 12) {

            // Top row — title + status badge
            HStack(spacing: 8) {
                Text(visit.planTitle ?? visit.serviceTypeLabel)
                    .font(.subheadline.weight(.bold))
                    .foregroundStyle(.primary)
                    .lineLimit(1)

                Spacer()

                Text(statusLabelFor(currentStatus))
                    .font(.caption2.weight(.bold))
                    .padding(.horizontal, 8)
                    .padding(.vertical, 3)
                    .background(statusColor.opacity(0.15))
                    .foregroundStyle(statusColor)
                    .clipShape(Capsule())
            }

            // Inline meta — duration · price · scheduled start
            HStack(spacing: 14) {
                if let duration = visit.estimatedDuration {
                    Label("\(duration) min", systemImage: "clock")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                if let price = visit.pricePerVisit {
                    Label(String(format: "$%.0f", price), systemImage: "dollarsign.circle")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                if let start = visit.scheduledStart {
                    Label(start, systemImage: "calendar")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            // Photo proof cells — visual placeholders.
            // PoW capture lives in JobPhotoSection on `main`; once that ports
            // to feature/language, replace these tiles with `JobPhotoSection(
            //   visitId: visit.visitId, isActive: isThisActive,
            //   authSession: authSession )`.
            if isActionable {
                photoProofRow(isActive: isThisActive)
            }

            // Start CTA — only when this visit is scheduled and no timer is
            // running anywhere on this stop. Active state is handled by the
            // hero timer band above.
            if normalised == "scheduled" && viewModel.activeTimerVisitId == nil {
                Button {
                    Task { await viewModel.startJob(visitId: visit.visitId) }
                } label: {
                    Label("Start Job", systemImage: "play.fill")
                        .font(.headline.weight(.bold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                        .foregroundStyle(.white)
                        .background(Color.MW.green)
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }
                .disabled(viewModel.isLoading)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 14))
        .overlay(
            RoundedRectangle(cornerRadius: 14)
                .stroke(
                    isThisActive
                        ? Color.MW.green.opacity(0.55)
                        : statusColor.opacity(0.18),
                    lineWidth: isThisActive ? 2 : 1
                )
        )
    }

    private func photoProofRow(isActive: Bool) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("PHOTO PROOF")
                .font(.caption2.weight(.bold))
                .foregroundStyle(.secondary)
                .tracking(0.5)

            HStack(spacing: 10) {
                photoCell(label: "Before", icon: "camera.badge.plus", enabled: true)
                photoCell(label: "After",  icon: isActive ? "camera.badge.plus" : "lock.fill",
                          enabled: isActive)
            }
        }
    }

    private func photoCell(label: String, icon: String, enabled: Bool) -> some View {
        VStack(spacing: 6) {
            ZStack {
                RoundedRectangle(cornerRadius: 10)
                    .fill(enabled ? Color.MW.green.opacity(0.08) : Color(.systemGray6))
                RoundedRectangle(cornerRadius: 10)
                    .strokeBorder(
                        enabled ? Color.MW.green.opacity(0.35) : Color(.systemGray4),
                        style: StrokeStyle(lineWidth: 1.5, dash: enabled ? [] : [5, 4])
                    )
                VStack(spacing: 4) {
                    Image(systemName: icon)
                        .font(.title3)
                        .foregroundStyle(enabled ? Color.MW.green : Color(.systemGray3))
                    if !enabled {
                        Text("Start job first")
                            .font(.caption2)
                            .foregroundStyle(.tertiary)
                    }
                }
            }
            .frame(height: 86)

            Text(label)
                .font(.caption2.weight(.semibold))
                .foregroundStyle(enabled ? Color.MW.green : .secondary)
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - 4. Info Chips Row

    private var infoChipsRow: some View {
        HStack(spacing: 8) {
            if let sqft = stop.lawnSqft {
                infoChip(value: Self.numberFormatter.string(from: NSNumber(value: sqft)) ?? "\(sqft)",
                         unit: "sq ft",
                         label: "Lawn Size")
            }
            if let total = totalEstDuration {
                infoChip(value: "\(total)",
                         unit: "min",
                         label: "Est. Duration")
            }
            if let totalPrice = totalPrice {
                infoChip(value: String(format: "$%.0f", totalPrice),
                         unit: nil,
                         label: "Visit Total")
            }
        }
        .frame(maxWidth: .infinity)
    }

    private func infoChip(value: String, unit: String?, label: String) -> some View {
        VStack(spacing: 2) {
            HStack(alignment: .firstTextBaseline, spacing: 3) {
                Text(value)
                    .font(.headline.weight(.bold))
                    .foregroundStyle(Color.MW.forest)
                if let unit {
                    Text(unit)
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(.secondary)
                }
            }
            Text(label.uppercased())
                .font(.system(size: 9, weight: .semibold))
                .foregroundStyle(.tertiary)
                .tracking(0.5)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 10)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(Color.MW.green.opacity(0.18), lineWidth: 1)
        )
    }

    // MARK: - 5. Notes Block

    private func notesBlock(_ text: String) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "exclamationmark.bubble.fill")
                .font(.subheadline)
                .foregroundStyle(Color.MW.orange)
            VStack(alignment: .leading, spacing: 4) {
                Text("NOTES")
                    .font(.caption2.weight(.bold))
                    .foregroundStyle(.secondary)
                    .tracking(0.5)
                Text(text)
                    .font(.subheadline)
                    .foregroundStyle(.primary)
                    .fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 0)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.MW.orange.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(Color.MW.orange.opacity(0.25), lineWidth: 1)
        )
    }

    // MARK: - 6. Crew Avatars Row

    private var crewAvatarsRow: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("CREW")
                .font(.caption2.weight(.bold))
                .foregroundStyle(.secondary)
                .tracking(0.5)

            HStack(spacing: 8) {
                ForEach(Array(stop.crewNames.prefix(5).enumerated()), id: \.offset) { _, name in
                    CrewInitialsAvatar(name: name)
                }
                if stop.crewNames.count > 5 {
                    overflowAvatar(count: stop.crewNames.count - 5)
                }
                Spacer()
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(Color.MW.green.opacity(0.18), lineWidth: 1)
        )
    }

    private func overflowAvatar(count: Int) -> some View {
        Text("+\(count)")
            .font(.caption.weight(.bold))
            .foregroundStyle(.secondary)
            .frame(width: 36, height: 36)
            .background(Color(.systemGray5))
            .clipShape(Circle())
    }

    // MARK: - Banners (preserved from prior implementation)

    private func noticeBanner(_ message: String, onDismiss: @escaping () -> Void) -> some View {
        HStack(spacing: 10) {
            Image(systemName: "clock.badge.checkmark.fill")
                .foregroundStyle(Color.MW.green)
            Text(message)
                .font(.subheadline)
                .foregroundStyle(Color.MW.green)
            Spacer()
            Button(action: onDismiss) {
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

    // MARK: - Computed

    private var stateIsActive: Bool {
        guard let active = viewModel.activeTimerVisitId else { return false }
        return stop.visits.contains(where: { $0.visitId == active })
    }

    private var stateLabel: String {
        if stateIsActive { return "On Job" }
        if stop.isComplete { return "Done" }
        return "Scheduled"
    }

    private var stateAccent: Color {
        if stateIsActive { return Color.MW.green }
        if stop.isComplete { return .gray }
        return .blue
    }

    private var headerBorderColor: Color {
        stateIsActive ? Color.MW.green.opacity(0.4) : Color(.systemGray5)
    }

    private var stopCounterLabel: String? {
        guard let total = totalStops, total > 0 else { return nil }
        return "Stop \(stop.routeOrder) of \(total)"
    }

    private var headerSubtitle: String? {
        let parts = [stop.contactName, primaryServiceLabel].compactMap { $0?.isEmpty == false ? $0 : nil }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }

    private var primaryServiceLabel: String? {
        if let active = stop.visits.first(where: { $0.visitId == viewModel.activeTimerVisitId }) {
            return active.serviceTypeLabel
        }
        return stop.visits.first?.serviceTypeLabel
    }

    private var totalEstDuration: Int? {
        let durations = stop.visits.compactMap { $0.estimatedDuration }
        guard !durations.isEmpty else { return nil }
        return durations.reduce(0, +)
    }

    private var totalPrice: Double? {
        let prices = stop.visits.compactMap { $0.pricePerVisit }
        guard !prices.isEmpty else { return nil }
        return prices.reduce(0, +)
    }

    /// Stop-level notes (gate codes, hazards). Returns nil until the API
    /// surfaces a `notes` field on the Stop model — the UI hides the block
    /// until the backend ships it (deferred to a follow-up).
    private var stopNotes: String? {
        nil
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

    // MARK: - Helpers

    private static let numberFormatter: NumberFormatter = {
        let nf = NumberFormatter()
        nf.numberStyle = .decimal
        nf.maximumFractionDigits = 0
        return nf
    }()

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

// MARK: - Crew Initials Avatar

fileprivate struct CrewInitialsAvatar: View {

    let name: String

    private var initials: String {
        let parts = name
            .split(separator: " ", omittingEmptySubsequences: true)
            .prefix(2)
            .compactMap { $0.first.map(String.init) }
        let result = parts.joined().uppercased()
        return result.isEmpty ? "?" : result
    }

    var body: some View {
        Text(initials)
            .font(.caption.weight(.bold))
            .foregroundStyle(.white)
            .frame(width: 36, height: 36)
            .background(
                LinearGradient(
                    colors: [Color.MW.green, Color.MW.dark],
                    startPoint: .topLeading,
                    endPoint: .bottomTrailing
                )
            )
            .clipShape(Circle())
            .overlay(
                Circle().stroke(.white.opacity(0.6), lineWidth: 1)
            )
            .shadow(color: .black.opacity(0.08), radius: 1, y: 1)
    }
}

// MARK: - Previews

#Preview("Scheduled") {
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
        routeOrder: 2,
        estimatedArrival: "08:30",
        propertyId: 1,
        propertyAddress: "123 Oak Street",
        propertyCity: "Vancouver",
        propertyName: "Bob Jones Property",
        latitude: 49.2827,
        longitude: -123.1207,
        contactId: 1,
        contactName: "Bob Jones",
        companyName: nil,
        lawnSqft: 4200,
        crewNames: ["John Doe", "Jane Smith", "Tim Schofield"],
        visitCount: 1,
        visits: [visit]
    )

    return NavigationStack {
        VisitDetailView(stop: stop, isAdmin: true, authSession: AuthSession(), totalStops: 5)
    }
}

#Preview("In Progress") {
    let visit = Visit(
        visitId: 1001,
        visitNumber: "PLN-2026-0001-V001",
        serviceType: "lawn_care",
        planTitle: "Weekly Lawn Mowing",
        planNumber: "PLN-2026-0001",
        visitStatus: "in_progress",
        estimatedDuration: 45,
        pricePerVisit: 75.00,
        scheduledStart: "09:00"
    )

    let stop = Stop(
        stopId: 42,
        stopDate: "2026-03-03",
        stopStatus: "in_progress",
        routeOrder: 2,
        estimatedArrival: "08:30",
        propertyId: 1,
        propertyAddress: "6015 Tisdall Street",
        propertyCity: "Vancouver",
        propertyName: nil,
        latitude: 49.2360,
        longitude: -123.1280,
        contactId: 1,
        contactName: "Ron Harvie",
        companyName: nil,
        lawnSqft: 4200,
        crewNames: ["Himal Bam", "Tim Schofield", "Might-E Truck", "Nigel Casey"],
        visitCount: 1,
        visits: [visit]
    )

    return NavigationStack {
        VisitDetailView(stop: stop, isAdmin: true, authSession: AuthSession(), totalStops: 5)
    }
}
