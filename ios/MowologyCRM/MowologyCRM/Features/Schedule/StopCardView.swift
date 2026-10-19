//
//  StopCardView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct StopCardView: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    let stop: Stop
    let isAdmin: Bool
    /// Straight-line distance from the device, when known. Shown so the crew
    /// can see why the list is in the order it is (nearest first).
    var distanceMeters: Double? = nil

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {

            // MARK: Top Row — Address + Arrival
            headerLayout {
                // Who, then where: crews know a stop by the building or client, not the street number.
                VStack(alignment: .leading, spacing: 2) {
                    if let headline = stop.headline {
                        Text(headline)
                            .font(appStyle.font(.body, weight: .bold))
                            .foregroundStyle(appStyle.text)
                            .lineLimit(appStyle.isEnabled ? nil : 1)
                            .fixedSize(horizontal: false, vertical: appStyle.isEnabled)

                        Text(stop.fullAddress)
                            .font(appStyle.font(.subheadline))
                            .foregroundStyle(appStyle.meta)
                            .lineLimit(appStyle.isEnabled ? nil : 1)
                            .fixedSize(horizontal: false, vertical: appStyle.isEnabled)
                    } else {
                        Text(stop.propertyAddress)
                            .font(appStyle.font(.body, weight: .bold))
                            .foregroundStyle(appStyle.text)
                            .lineLimit(appStyle.isEnabled ? nil : 1)
                            .fixedSize(horizontal: false, vertical: appStyle.isEnabled)

                        Text(stop.propertyCity)
                            .font(appStyle.font(.subheadline))
                            .foregroundStyle(appStyle.meta)
                            .lineLimit(appStyle.isEnabled ? nil : 1)
                            .fixedSize(horizontal: false, vertical: appStyle.isEnabled)
                    }
                }

                if !dynamicTypeSize.isAccessibilitySize { Spacer() }

                VStack(alignment: .trailing, spacing: 4) {
                    if let arrival = stop.estimatedArrival {
                        VStack(alignment: .trailing, spacing: 2) {
                            Text(arrival)
                                .font(appStyle.font(.subheadline, weight: .bold).monospacedDigit())
                                .foregroundStyle(appStyle.tenant)

                            Text("est. arrival")
                                .font(appStyle.font(.caption2))
                                .foregroundStyle(appStyle.meta)
                        }
                    }

                    if let text = distanceText {
                        Label(text, systemImage: "location.fill")
                            .font(appStyle.font(.caption).monospacedDigit())
                            .foregroundStyle(appStyle.meta)
                    }
                }
            }

            // MARK: Service Type Badges
            if !stop.visits.isEmpty {
                if appStyle.isEnabled {
                    ViewThatFits(in: .horizontal) {
                        HStack(spacing: 6) { serviceBadges }
                        VStack(alignment: .leading, spacing: 6) { serviceBadges }
                    }
                } else {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 6) { serviceBadges }
                    }
                    // Preserve the legacy row's navigation gesture.
                    .allowsHitTesting(false)
                }
            }

            // MARK: Last done
            if let line = stop.historyLine {
                HStack(spacing: 5) {
                    Image(systemName: line.warning ? "exclamationmark.circle.fill" : "clock.arrow.circlepath")
                        .font(appStyle.font(.caption2))
                    Text(line.service.map { "\($0): \(line.text)" } ?? line.text)
                        .font(appStyle.font(.caption))
                        .lineLimit(appStyle.isEnabled ? nil : 1)
                            .fixedSize(horizontal: false, vertical: appStyle.isEnabled)
                }
                .foregroundStyle(line.warning ? appStyle.attention : appStyle.meta)
            }

            if appStyle.isEnabled {
                Label(statusPresentation.label, systemImage: statusPresentation.icon)
                    .font(appStyle.font(.caption, weight: .medium))
                    .foregroundStyle(statusPresentation.color)
            }

            Divider().overlay(appStyle.isEnabled ? appStyle.line : Color.clear)

            // MARK: Bottom Row — Client + Crew + Visit Count
            footerLayout {

                // Client / Contact Name — only when it isn't already the card's headline
                // (a named building still shows who the client is down here).
                if let name = stop.footerClientName {
                    Label(name, systemImage: "person.fill")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                        .lineLimit(appStyle.isEnabled ? nil : 1)
                            .fixedSize(horizontal: false, vertical: appStyle.isEnabled)
                }

                if !dynamicTypeSize.isAccessibilitySize { Spacer() }

                // Crew names (admin only)
                if isAdmin && !stop.crewNames.isEmpty {
                    Label(stop.crewNames.joined(separator: ", "), systemImage: "person.2.fill")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                        .lineLimit(appStyle.isEnabled ? nil : 1)
                            .fixedSize(horizontal: false, vertical: appStyle.isEnabled)
                        .frame(maxWidth: dynamicTypeSize.isAccessibilitySize ? nil : 140, alignment: .trailing)
                }

                // Visit count badge
                HStack(spacing: 3) {
                    Image(systemName: "checkmark.circle")
                        .font(appStyle.font(.caption2))
                    Text("\(stop.visitCount) visit\(stop.visitCount == 1 ? "" : "s")")
                        .font(appStyle.font(.caption).monospacedDigit())
                }
                .foregroundStyle(appStyle.meta)
                .padding(.leading, 8)
            }
        }
        .padding(16)
        .background(appStyle.surface)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.isEnabled ? 0 : appStyle.cornerRadius(14)))
        .shadow(color: appStyle.shadow(0.06), radius: 8, x: 0, y: 2)
        .overlay(alignment: .leading) {
            if !appStyle.isEnabled {
                HStack {
                    RoundedRectangle(cornerRadius: appStyle.cornerRadius(3))
                        .fill(accentColor(for: stop))
                        .frame(width: 4)
                    Spacer()
                }
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
            }
        }
        .overlay(alignment: .bottom) {
            if appStyle.isEnabled {
                Rectangle().fill(appStyle.line).frame(height: 1)
            }
        }
    }

    // MARK: - Helpers

    private var serviceBadges: some View {
        ForEach(uniqueServiceBadges(from: stop.visits), id: \.label) { badge in
            ServiceBadge(label: badge.label, color: badge.color)
        }
    }

    private var headerLayout: AnyLayout {
        dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8))
            : AnyLayout(HStackLayout(alignment: .top))
    }

    private var footerLayout: AnyLayout {
        dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8))
            : AnyLayout(HStackLayout(alignment: .center, spacing: 0))
    }

    private var statusPresentation: (label: String, icon: String, color: Color) {
        if stop.isComplete { return ("Complete", "checkmark.circle", appStyle.complete) }
        if stop.isInProgress { return ("In progress", "clock", appStyle.progress) }
        if stop.isResolved { return ("Resolved", "checkmark.circle", appStyle.meta) }
        return ("Scheduled", "calendar", appStyle.meta)
    }

    private var distanceText: String? {
        guard let m = distanceMeters else { return nil }
        if m < 950 { return "\(Int((m / 50).rounded() * 50)) m" }
        return String(format: m < 10_000 ? "%.1f km" : "%.0f km", m / 1000)
    }

    private struct BadgeInfo {
        let label: String
        let color: Color
    }

    private func uniqueServiceBadges(from visits: [Visit]) -> [BadgeInfo] {
        var seen = Set<String>()
        return visits.compactMap { visit in
            let label = visit.serviceTypeLabel
            guard !seen.contains(label) else { return nil }
            seen.insert(label)
            return BadgeInfo(label: label, color: appStyle.statusColor(visit.visitStatus, legacy: visit.statusColor))
        }
    }

    private func accentColor(for stop: Stop) -> Color {
        if stop.isComplete     { return .green }
        if stop.isInProgress   { return appStyle.tenant }
        #if canImport(UIKit)
        return appStyle.color(.line, legacy: Color(.systemGray4))
        #else
        return appStyle.color(.line, legacy: Color.secondary.opacity(0.3))
        #endif
    }
}

// MARK: - ServiceBadge

private struct ServiceBadge: View {
    @Environment(\.itAppStyle) private var appStyle
    let label: String
    let color: Color

    var body: some View {
        Text(label)
            .font(appStyle.font(.caption2, weight: .semibold))
            .foregroundStyle(color)
            .padding(.horizontal, 8)
            .padding(.vertical, 4)
            .background(color.opacity(0.12))
            .clipShape(Capsule())
    }
}

#Preview {
    let sampleVisit = Visit(
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

    let sampleStop = Stop(
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
        contactPhone: nil, contactEmail: nil, onsiteContact: nil,
        companyName: nil,
        lawnSqft: nil,
        crewIds: [1],
        crewNames: ["John Doe"],
        visitCount: 1,
        visits: [sampleVisit],
        propertyNotes: nil,
        stopNotes: nil
    )

    return StopCardView(stop: sampleStop, isAdmin: true)
        .padding()
        .background(ItAppStyle().page)
        .previewLayout(.sizeThatFits)
}
