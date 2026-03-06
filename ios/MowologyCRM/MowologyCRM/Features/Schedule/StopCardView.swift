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

    private let mwGreen = Color(red: 0.176, green: 0.525, blue: 0.349)

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {

            // MARK: Top Row — Address + Arrival
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(stop.propertyAddress)
                        .font(.body.bold())
                        .foregroundStyle(.primary)
                        .lineLimit(1)

                    Text(stop.propertyCity)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }

                Spacer()

                if let arrival = stop.estimatedArrival {
                    VStack(alignment: .trailing, spacing: 2) {
                        Text(arrival)
                            .font(.subheadline.monospacedDigit().bold())
                            .foregroundStyle(mwGreen)

                        Text("est. arrival")
                            .font(.caption2)
                            .foregroundStyle(.tertiary)
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
            }

            Divider()

            // MARK: Bottom Row — Client + Crew + Visit Count
            HStack(alignment: .center, spacing: 0) {

                // Client / Contact Name
                if let name = stop.displayName {
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
        if stop.isInProgress   { return Color(red: 0.176, green: 0.525, blue: 0.349) }
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
        companyName: nil,
        lawnSqft: nil,
        crewNames: ["John Doe"],
        visitCount: 1,
        visits: [sampleVisit]
    )

    return StopCardView(stop: sampleStop, isAdmin: true)
        .padding()
        .background(Color(.systemGroupedBackground))
        .previewLayout(.sizeThatFits)
}
