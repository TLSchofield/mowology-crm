//
//  WeekStripView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct WeekStripView: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    @Binding var selectedDate: Date
    let weekDays: [ScheduleDay]
    @Namespace private var chipNamespace

    var body: some View {
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                ScrollView(.horizontal, showsIndicators: false) { dayChips }
            } else {
                dayChips
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.horizontal, appStyle.isEnabled ? 8 : 12)
        .padding(.vertical, 12)
        .background(appStyle.surface)
    }

    private var dayChips: some View {
        HStack(spacing: appStyle.isEnabled ? 2 : 4) {
            ForEach(weekDays) { day in
                DayChip(day: day, isSelected: isSelected(day), ns: chipNamespace) {
                    selectDay(day)
                }
            }
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - Helpers

    private func isSelected(_ day: ScheduleDay) -> Bool {
        isoDateString(from: selectedDate) == day.date
    }

    private func selectDay(_ day: ScheduleDay) {
        guard let date = parseISODate(day.date) else { return }
        withAnimation(.spring(response: 0.3, dampingFraction: 0.75)) {
            selectedDate = date
        }
    }

    private func isoDateString(from date: Date) -> String {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_CA")
        return f.string(from: date)
    }

    private func parseISODate(_ string: String) -> Date? {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_CA")
        return f.date(from: string)
    }
}

// MARK: - DayChip

private struct DayChip: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    let day: ScheduleDay
    let isSelected: Bool
    let ns: Namespace.ID
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 4) {

                // Day name (e.g. "Mon")
                Text(day.dayName.prefix(3).uppercased())
                    .font(appStyle.font(size: appStyle.isEnabled ? 12 : 10, weight: .semibold))
                    .foregroundStyle(isSelected ? appStyle.onTenant : appStyle.meta)

                // Day number (e.g. "3")
                Text("\(day.dayNumber)")
                    .font(appStyle.font(size: 20, weight: .bold, design: .rounded))
                    .foregroundStyle(isSelected ? appStyle.onTenant : (day.isToday ? appStyle.tenant : appStyle.text))

                // Activity dot
                Circle()
                    .fill(
                        day.stopCount > 0
                            ? ((isSelected && appStyle.isEnabled) ? appStyle.onTenant : appStyle.meta)
                            : Color.clear
                    )
                    .frame(width: 6, height: 6)
            }
            .frame(minWidth: dynamicTypeSize.isAccessibilitySize ? 72 : nil, maxWidth: .infinity, minHeight: 72)
            .background {
                ZStack {
                    // Base layer — fades out when selected
                    RoundedRectangle(cornerRadius: appStyle.cornerRadius(14))
                        .fill(day.isToday ? appStyle.tenant.opacity(0.08) : appStyle.sunk)
                        .opacity(isSelected ? 0 : 1)

                    // Selected pill — matchedGeometryEffect makes it slide
                    // between chips rather than cross-fade in place.
                    if isSelected {
                        RoundedRectangle(cornerRadius: appStyle.cornerRadius(14))
                            .fill(appStyle.tenant)
                            .matchedGeometryEffect(id: "chip", in: ns)
                    }
                }
            }
            .overlay {
                RoundedRectangle(cornerRadius: appStyle.cornerRadius(14))
                    .stroke(
                        day.isToday && !isSelected ? appStyle.tenant.opacity(0.4) : Color.clear,
                        lineWidth: 1.5
                    )
            }
        }
        .buttonStyle(.plain)
        .accessibilityLabel("\(day.dayName), \(day.dayNumber), \(day.stopCount) stops")
        .accessibilityValue(isSelected ? "Selected" : (day.hasIncomplete ? "Work remaining" : ""))
    }
}

#Preview {
    WeekStripView(
        selectedDate: .constant(.now),
        weekDays: [
            ScheduleDay(date: "2026-03-02", dayName: "Sun", dayNumber: 2,  isToday: false, stopCount: 0, visitCount: 0, hasIncomplete: false),
            ScheduleDay(date: "2026-03-03", dayName: "Mon", dayNumber: 3,  isToday: true,  stopCount: 3, visitCount: 5, hasIncomplete: true),
            ScheduleDay(date: "2026-03-04", dayName: "Tue", dayNumber: 4,  isToday: false, stopCount: 2, visitCount: 3, hasIncomplete: false),
            ScheduleDay(date: "2026-03-05", dayName: "Wed", dayNumber: 5,  isToday: false, stopCount: 0, visitCount: 0, hasIncomplete: false),
            ScheduleDay(date: "2026-03-06", dayName: "Thu", dayNumber: 6,  isToday: false, stopCount: 4, visitCount: 6, hasIncomplete: true),
            ScheduleDay(date: "2026-03-07", dayName: "Fri", dayNumber: 7,  isToday: false, stopCount: 1, visitCount: 1, hasIncomplete: false),
            ScheduleDay(date: "2026-03-08", dayName: "Sat", dayNumber: 8,  isToday: false, stopCount: 0, visitCount: 0, hasIncomplete: false)
        ]
    )
    .previewLayout(.sizeThatFits)
}
