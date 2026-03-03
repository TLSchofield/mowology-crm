//
//  WeekStripView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct WeekStripView: View {

    @Binding var selectedDate: Date
    let weekDays: [ScheduleDay]

    // Mowology brand colors.
    private let mwGreen  = Color(red: 0.176, green: 0.525, blue: 0.349)
    private let mwOrange = Color(red: 0.910, green: 0.365, blue: 0.016)

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(weekDays) { day in
                    DayChip(
                        day: day,
                        isSelected: isSelected(day),
                        mwGreen: mwGreen,
                        mwOrange: mwOrange
                    ) {
                        selectDay(day)
                    }
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
        }
        .background(Color(.systemBackground))
    }

    // MARK: - Helpers

    private func isSelected(_ day: ScheduleDay) -> Bool {
        isoDateString(from: selectedDate) == day.date
    }

    private func selectDay(_ day: ScheduleDay) {
        guard let date = parseISODate(day.date) else { return }
        withAnimation(.easeInOut(duration: 0.2)) {
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

    let day: ScheduleDay
    let isSelected: Bool
    let mwGreen: Color
    let mwOrange: Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 4) {

                // Day name (e.g. "Mon")
                Text(day.dayName.prefix(3).uppercased())
                    .font(.system(size: 10, weight: .semibold))
                    .foregroundStyle(isSelected ? .white : .secondary)

                // Day number (e.g. "3")
                Text("\(day.dayNumber)")
                    .font(.system(size: 20, weight: .bold, design: .rounded))
                    .foregroundStyle(isSelected ? .white : (day.isToday ? mwGreen : .primary))

                // Activity dot
                if day.stopCount > 0 {
                    Circle()
                        .fill(day.hasIncomplete ? mwOrange : mwGreen)
                        .frame(width: 6, height: 6)
                } else {
                    // Invisible placeholder to keep chip heights uniform.
                    Circle()
                        .fill(Color.clear)
                        .frame(width: 6, height: 6)
                }
            }
            .frame(width: 48, height: 72)
            .background(
                RoundedRectangle(cornerRadius: 14)
                    .fill(isSelected ? mwGreen : (day.isToday ? mwGreen.opacity(0.08) : Color(.systemGray6)))
            )
            .overlay(
                RoundedRectangle(cornerRadius: 14)
                    .stroke(
                        day.isToday && !isSelected ? mwGreen.opacity(0.4) : Color.clear,
                        lineWidth: 1.5
                    )
            )
        }
        .buttonStyle(.plain)
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
