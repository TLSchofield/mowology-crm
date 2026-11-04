//
//  ServiceHistoryGrid.swift
//  MowologyCRM
//
//  Two rows of seven: last week over this week, Monday → Sunday, the same columns as the
//  schedule's week strip. Green = done, orange = skipped, outline = scheduled, ring = today.
//

import SwiftUI

struct ServiceHistoryGrid: View {
    @Environment(\.itAppStyle) private var appStyle

    @ScaledMetric(relativeTo: .caption2) private var cellHeight = 26.0
    let history: ServiceHistory

    private let weekdays = ["M", "T", "W", "T", "F", "S", "S"]

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 6) {
                Image(systemName: "calendar.badge.clock")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(history.summaryIsWarning ? appStyle.attention : appStyle.meta)
                Text(history.summary ?? "Service history")
                    .font(appStyle.font(.caption, weight: .semibold))
                    .foregroundStyle(history.summaryIsWarning ? appStyle.attention : appStyle.meta)
            }

            VStack(spacing: 5) {
                HStack(spacing: 5) {
                    Text("").frame(width: 34)
                    ForEach(Array(weekdays.enumerated()), id: \.offset) { _, letter in
                        Text(letter)
                            .font(appStyle.font(.caption2, weight: .medium))
                            .foregroundStyle(appStyle.isEnabled ? appStyle.meta : Color(.tertiaryLabel))
                            .frame(maxWidth: .infinity)
                    }
                }
                ForEach(Array(history.weeks.enumerated()), id: \.offset) { index, week in
                    HStack(spacing: 5) {
                        Text(index == 0 ? "Last" : "This")
                            .font(appStyle.font(.caption2))
                            .foregroundStyle(appStyle.isEnabled ? appStyle.meta : Color(.tertiaryLabel))
                            .frame(width: 34, alignment: .leading)
                        ForEach(week) { day in
                            cell(day)
                        }
                    }
                }
            }

            HStack(spacing: 12) {
                legend(color: appStyle.tenant, filled: true, label: "Done")
                legend(color: appStyle.attention, filled: true, label: "Skipped")
                legend(color: (appStyle.isEnabled ? appStyle.meta : Color(.systemGray3)), filled: false, label: "Scheduled")
            }
        }
        .padding(10)
        .background(appStyle.sunk.opacity(0.6))
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(history.summary ?? "Service history")
    }

    private func cell(_ day: ServiceHistoryDay) -> some View {
        let isToday = day.date == history.today
        let fill: Color
        let text: Color
        switch day.state {
        case "completed":   fill = appStyle.tenant;               text = appStyle.onTenant
        case "in_progress": fill = appStyle.tenant.opacity(0.35); text = appStyle.text
        case "skipped":     fill = appStyle.attention;              text = appStyle.onTenant
        default:            fill = .clear;                       text = (appStyle.isEnabled ? appStyle.meta : Color(.tertiaryLabel))
        }

        return ZStack(alignment: .topTrailing) {
            RoundedRectangle(cornerRadius: appStyle.cornerRadius(6))
                .fill(fill)
                .overlay(
                    RoundedRectangle(cornerRadius: appStyle.cornerRadius(6))
                        .stroke(day.state == "scheduled" ? (appStyle.isEnabled ? appStyle.meta : Color(.systemGray3)) : .clear,
                                style: StrokeStyle(lineWidth: 1, dash: [3, 2]))
                )
                .overlay(
                    RoundedRectangle(cornerRadius: appStyle.cornerRadius(6))
                        .stroke(isToday ? appStyle.text : .clear, lineWidth: 1.5)
                )
            Text(day.dayOfMonth)
                .font(appStyle.font(.caption2, weight: isToday ? .bold : .regular).monospacedDigit())
                .foregroundStyle(day.state == "scheduled" ? (appStyle.isEnabled ? appStyle.meta : Color(.secondaryLabel)) : text)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            // A storm day: the same site done more than once.
            if day.count > 1 {
                Text("\(day.count)")
                    .font(appStyle.font(size: 8, weight: .bold))
                    .foregroundStyle(appStyle.tenant)
                    .padding(2)
                    .background(Circle().fill(appStyle.surface))
                    .offset(x: 3, y: -3)
            }
        }
        .frame(maxWidth: .infinity)
        .frame(height: cellHeight)
    }

    private func legend(color: Color, filled: Bool, label: String) -> some View {
        HStack(spacing: 4) {
            RoundedRectangle(cornerRadius: appStyle.cornerRadius(3))
                .fill(filled ? color : .clear)
                .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(3))
                    .stroke(filled ? .clear : color, style: StrokeStyle(lineWidth: 1, dash: [2, 2])))
                .frame(width: 10, height: 10)
            Text(label)
                .font(appStyle.font(.caption2))
                .foregroundStyle(appStyle.isEnabled ? appStyle.meta : Color(.tertiaryLabel))
        }
    }
}
