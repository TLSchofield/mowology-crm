//
//  ServiceHistoryGrid.swift
//  MowologyCRM
//
//  Collapsed to one summary line (tap to open): two rows of seven, last week over this week, Monday → Sunday, the same columns as the
//  schedule's week strip. Green = done, orange = skipped, outline = scheduled, ring = today.
//

import SwiftUI

struct ServiceHistoryGrid: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    @ScaledMetric(relativeTo: .caption2) private var cellHeight = 26.0
    let history: ServiceHistory
    /// The caller can own the open/closed choice (the visit screen keeps it across reloads); without one
    /// the grid keeps its own. Collapsed by default either way.
    private let external: Binding<Bool>?
    @State private var local = false

    init(history: ServiceHistory, isExpanded: Binding<Bool>? = nil) {
        self.history = history
        self.external = isExpanded
    }

    private var expanded: Bool { external?.wrappedValue ?? local }
    private func setExpanded(_ v: Bool) {
        if let external { external.wrappedValue = v } else { local = v }
    }

    private let weekdays = ["M", "T", "W", "T", "F", "S", "S"]

    /// "Skipped Fri — last done 40 days ago" → "Skipped Fri · Last done 40 days ago".
    private var summaryText: String {
        guard let raw = history.summary, !raw.isEmpty else { return "Service history" }
        // The server joins the parts with a dash or a comma; show them as "Part \u{00B7} Part", each starting with a capital.
        var text = raw.replacingOccurrences(of: " \u{2014} ", with: " \u{00B7} ")
            .replacingOccurrences(of: ", last", with: " \u{00B7} last")
        let parts = text.components(separatedBy: " \u{00B7} ").map { $0.prefix(1).uppercased() + $0.dropFirst() }
        text = parts.joined(separator: " \u{00B7} ")
        return text
    }

    private var tint: Color { history.summaryIsWarning ? appStyle.attention : appStyle.meta }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Button {
                if reduceMotion { setExpanded(!expanded) }
                else { withAnimation(.easeInOut(duration: 0.2)) { setExpanded(!expanded) } }
            } label: {
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    // Icon and words, not colour alone, say there is a skip.
                    Image(systemName: history.summaryIsWarning ? "exclamationmark.triangle.fill" : "calendar.badge.clock")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(tint)
                        .accessibilityHidden(true)
                    Text(summaryText)
                        .font(appStyle.font(.caption, weight: .semibold))
                        .foregroundStyle(tint)
                        .multilineTextAlignment(.leading)
                        .fixedSize(horizontal: false, vertical: true)
                    Spacer(minLength: 4)
                    Image(systemName: "chevron.down")
                        .font(appStyle.font(.caption2, weight: .semibold))
                        .foregroundStyle(appStyle.meta)
                        .rotationEffect(.degrees(expanded ? 180 : 0))
                        .accessibilityHidden(true)
                }
                .frame(minHeight: 32)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(summaryText)
            .accessibilityValue(expanded ? "Expanded" : "Collapsed")
            .accessibilityHint(expanded ? "Hides the two-week calendar" : "Shows the two-week calendar")
            .accessibilityAddTraits(.isButton)

            if expanded {
                calendar
                    .transition(reduceMotion ? .identity : .opacity)
            }
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
        .background(appStyle.sunk.opacity(0.6))
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
    }

    /// Weekday letters once, then each week under its own label so the columns stay aligned.
    private var calendar: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 5) {
                ForEach(Array(weekdays.enumerated()), id: \.offset) { _, letter in
                    Text(letter)
                        .font(appStyle.font(.caption2, weight: .medium))
                        .foregroundStyle(appStyle.isEnabled ? appStyle.meta : Color(.tertiaryLabel))
                        .frame(maxWidth: .infinity)
                }
            }
            .accessibilityHidden(true)
            ForEach(Array(history.weeks.enumerated()), id: \.offset) { index, week in
                VStack(alignment: .leading, spacing: 3) {
                    Text(index == 0 ? "Last week" : "This week")
                        .font(appStyle.font(.caption2, weight: .medium))
                        .foregroundStyle(appStyle.isEnabled ? appStyle.meta : Color(.tertiaryLabel))
                        .lineLimit(1)
                    HStack(spacing: 5) {
                        ForEach(week) { day in cell(day) }
                    }
                }
            }
            ViewThatFits(in: .horizontal) {
                HStack(spacing: 12) { legends }
                VStack(alignment: .leading, spacing: 4) { legends }
            }
            .padding(.bottom, 6)
        }
    }

    @ViewBuilder private var legends: some View {
        legend(color: appStyle.tenant, filled: true, label: "Done")
        legend(color: appStyle.attention, filled: true, label: "Skipped")
        legend(color: (appStyle.isEnabled ? appStyle.meta : Color(.systemGray3)), filled: false, label: "Scheduled")
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
