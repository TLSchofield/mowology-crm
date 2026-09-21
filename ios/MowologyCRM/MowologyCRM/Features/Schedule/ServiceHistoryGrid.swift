//
//  ServiceHistoryGrid.swift
//  MowologyCRM
//
//  Two rows of seven: last week over this week, Monday → Sunday, the same columns as the
//  schedule's week strip. Green = done, orange = skipped, outline = scheduled, ring = today.
//

import SwiftUI

struct ServiceHistoryGrid: View {

    let history: ServiceHistory

    private let weekdays = ["M", "T", "W", "T", "F", "S", "S"]

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 6) {
                Image(systemName: "calendar.badge.clock")
                    .font(.caption)
                    .foregroundStyle(history.summaryIsWarning ? Color.MW.orange : .secondary)
                Text(history.summary ?? "Service history")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(history.summaryIsWarning ? Color.MW.orange : .secondary)
            }

            VStack(spacing: 5) {
                HStack(spacing: 5) {
                    Text("").frame(width: 34)
                    ForEach(Array(weekdays.enumerated()), id: \.offset) { _, letter in
                        Text(letter)
                            .font(.caption2.weight(.medium))
                            .foregroundStyle(.tertiary)
                            .frame(maxWidth: .infinity)
                    }
                }
                ForEach(Array(history.weeks.enumerated()), id: \.offset) { index, week in
                    HStack(spacing: 5) {
                        Text(index == 0 ? "Last" : "This")
                            .font(.caption2)
                            .foregroundStyle(.tertiary)
                            .frame(width: 34, alignment: .leading)
                        ForEach(week) { day in
                            cell(day)
                        }
                    }
                }
            }

            HStack(spacing: 12) {
                legend(color: Color.MW.green, filled: true, label: "Done")
                legend(color: Color.MW.orange, filled: true, label: "Skipped")
                legend(color: Color(.systemGray3), filled: false, label: "Scheduled")
            }
        }
        .padding(10)
        .background(Color(.systemGray6).opacity(0.6))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(history.summary ?? "Service history")
    }

    private func cell(_ day: ServiceHistoryDay) -> some View {
        let isToday = day.date == history.today
        let fill: Color
        let text: Color
        switch day.state {
        case "completed":   fill = Color.MW.green;               text = .white
        case "in_progress": fill = Color.MW.green.opacity(0.35); text = .primary
        case "skipped":     fill = Color.MW.orange;              text = .white
        default:            fill = .clear;                       text = Color(.tertiaryLabel)
        }

        return ZStack(alignment: .topTrailing) {
            RoundedRectangle(cornerRadius: 6)
                .fill(fill)
                .overlay(
                    RoundedRectangle(cornerRadius: 6)
                        .stroke(day.state == "scheduled" ? Color(.systemGray3) : .clear,
                                style: StrokeStyle(lineWidth: 1, dash: [3, 2]))
                )
                .overlay(
                    RoundedRectangle(cornerRadius: 6)
                        .stroke(isToday ? Color.primary : .clear, lineWidth: 1.5)
                )
            Text(day.dayOfMonth)
                .font(.caption2.monospacedDigit().weight(isToday ? .bold : .regular))
                .foregroundStyle(day.state == "scheduled" ? Color(.secondaryLabel) : text)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
            // A storm day: the same site done more than once.
            if day.count > 1 {
                Text("\(day.count)")
                    .font(.system(size: 8, weight: .bold))
                    .foregroundStyle(Color.MW.green)
                    .padding(2)
                    .background(Circle().fill(.white))
                    .offset(x: 3, y: -3)
            }
        }
        .frame(maxWidth: .infinity)
        .frame(height: 26)
    }

    private func legend(color: Color, filled: Bool, label: String) -> some View {
        HStack(spacing: 4) {
            RoundedRectangle(cornerRadius: 3)
                .fill(filled ? color : .clear)
                .overlay(RoundedRectangle(cornerRadius: 3)
                    .stroke(filled ? .clear : color, style: StrokeStyle(lineWidth: 1, dash: [2, 2])))
                .frame(width: 10, height: 10)
            Text(label)
                .font(.caption2)
                .foregroundStyle(.tertiary)
        }
    }
}
