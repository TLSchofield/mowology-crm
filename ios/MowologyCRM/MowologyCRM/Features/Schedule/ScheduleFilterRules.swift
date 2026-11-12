//
//  ScheduleFilterRules.swift
//  MowologyCRM
//

import Foundation

enum ScheduleFilterRules {
    static func selectedValues(from filter: String, liveValues: [String]) -> [String] {
        let selected = filter
            .split(separator: ",")
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
        guard !liveValues.isEmpty else { return selected }
        let live = Set(liveValues)
        return selected.filter { live.contains($0) }
    }

    static func toggledValues(
        _ selected: [String],
        value: String,
        order: [String],
        startingFromEmpty: Bool = false
    ) -> [String] {
        var result = startingFromEmpty ? [] : selected
        if let index = result.firstIndex(of: value) {
            result.remove(at: index)
        } else {
            result.append(value)
        }
        result.sort { lhs, rhs in
            let lhsIndex = order.firstIndex(of: lhs) ?? .max
            let rhsIndex = order.firstIndex(of: rhs) ?? .max
            return lhsIndex != rhsIndex ? lhsIndex < rhsIndex : lhs < rhs
        }
        return result
    }

    static func crewsParam(scope: String, selectedCrews: [String]) -> String {
        scope == "all" ? selectedCrews.joined(separator: ",") : ""
    }

    static func diskKey(dateString: String, scope: String, jobType: String, crewsParam: String) -> String {
        "\(dateString)_\(scope)_\(jobType.isEmpty ? "any" : jobType)"
            + (crewsParam.isEmpty ? "" : "_c\(crewsParam)")
    }

    static func weekRangeLabel(
        selectedDate: Date,
        calendar: Calendar,
        displayFormatter: DateFormatter
    ) -> String {
        let monday = mondayOf(week: selectedDate, calendar: calendar)
        let sunday = calendar.date(byAdding: .day, value: 6, to: monday) ?? monday
        let year = calendar.component(.year, from: sunday)
        let startMonth = calendar.component(.month, from: monday)
        let endMonth = calendar.component(.month, from: sunday)
        if startMonth == endMonth {
            let endDay = calendar.component(.day, from: sunday)
            return "\(displayFormatter.string(from: monday))–\(endDay), \(year)"
        }
        return "\(displayFormatter.string(from: monday)) – \(displayFormatter.string(from: sunday)), \(year)"
    }

    static func mondayOf(week date: Date, calendar: Calendar) -> Date {
        let components = calendar.dateComponents([.yearForWeekOfYear, .weekOfYear], from: date)
        return calendar.date(from: components) ?? date
    }
}
