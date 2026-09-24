//
//  StopCardView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct StopCardView: View {

    let stop: Stop
    let isAdmin: Bool
    /// Straight-line distance from the device, when known. Shown so the crew
    /// can see why the list is in the order it is (nearest first).
    var distanceMeters: Double? = nil

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {

            // MARK: Top Row — Address + Arrival
            HStack(alignment: .top) {
                // Who, then where: crews know a stop by the building or client, not the street number.
                VStack(alignment: .leading, spacing: 2) {
                    if let headline = stop.headline {
                        Text(headline)
                            .font(.body.bold())
                            .foregroundStyle(.primary)
                            .lineLimit(1)

                        Text(stop.fullAddress)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    } else {
                        Text(stop.propertyAddress)
                            .font(.body.bold())
                            .foregroundStyle(.primary)
                            .lineLimit(1)

                        Text(stop.propertyCity)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }

                Spacer()

                VStack(alignment: .trailing, spacing: 4) {
                    if let arrival = stop.estimatedArrival {
                        VStack(alignment: .trailing, spacing: 2) {
                            Text(arrival)
                                .font(.subheadline.monospacedDigit().bold())
                                .foregroundStyle(Color.MW.green)

                            Text("est. arrival")
                                .font(.caption2)
                                .foregroundStyle(.tertiary)
                        }
                    }

                    if let text = distanceText {
                        Label(text, systemImage: "location.fill")
                            .font(.caption.monospacedDigit())
                            .foregroundStyle(.secondary)
                    }
                }
            }

            // MARK: Service Type Badges
            if !stop.visits.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 6) {
                        ForEach(uniqueServiceBadges(from: stop.visits), id: \.label) { badge in
                            ServiceBadge(label: badge.label, color: badge.color)
                        }
                    }
                }
                // Disable hit testing so the scroll view's gesture recognizer
                // doesn't block the parent List row's NavigationLink tap.
                .allowsHitTesting(false)
            }

            // MARK: Last done
            if let line = stop.historyLine {
                HStack(spacing: 5) {
                    Image(systemName: line.warning ? "exclamationmark.circle.fill" : "clock.arrow.circlepath")
                        .font(.caption2)
                    Text(line.service.map { "\($0): \(line.text)" } ?? line.text)
                        .font(.caption)
                        .lineLimit(1)
                }
                .foregroundStyle(line.warning ? Color.MW.orange : .secondary)
            }

            Divider()

            // MARK: Bottom Row — Client + Crew + Visit Count
            HStack(alignment: .center, spacing: 0) {

                // Client / Contact Name — only when it isn't already the card's headline
                // (a named building still shows who the client is down here).
                if let name = stop.footerClientName {
                    Label(name, systemImage: "person.fill")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }

                Spacer()

                // Crew names (admin only)
                if isAdmin && !stop.crewNames.isEmpty {
                    Label(stop.crewNames.joined(separator: ", "), systemImage: "person.2.fill")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                        .frame(maxWidth: 140, alignment: .trailing)
                }

                // Visit count badge
                HStack(spacing: 3) {
                    Image(systemName: "checkmark.circle")
                        .font(.caption2)
                    Text("\(stop.visitCount) visit\(stop.visitCount == 1 ? "" : "s")")
                        .font(.caption.monospacedDigit())
                }
                .foregroundStyle(.secondary)
                .padding(.leading, 8)
            }
        }
        .padding(16)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 14))
        .shadow(color: .black.opacity(0.06), radius: 8, x: 0, y: 2)
        .overlay(
            // Left accent stripe
            HStack {
                RoundedRectangle(cornerRadius: 3)
                    .fill(accentColor(for: stop))
                    .frame(width: 4)
                Spacer()
            }
            .clipShape(RoundedRectangle(cornerRadius: 14))
        )
    }

    // MARK: - Helpers

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
            return BadgeInfo(label: label, color: visit.statusColor)
        }
    }

    private func accentColor(for stop: Stop) -> Color {
        if stop.isComplete     { return .green }
        if stop.isInProgress   { return Color.MW.green }
        return Color(.systemGray4)
    }
}

// MARK: - ServiceBadge

private struct ServiceBadge: View {
    let label: String
    let color: Color

    var body: some View {
        Text(label)
            .font(.caption2.weight(.semibold))
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
        contactPhone: nil,
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
        .background(Color(.systemGroupedBackground))
        .previewLayout(.sizeThatFits)
}
