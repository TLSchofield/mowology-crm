//
//  ServiceHistory.swift
//  MowologyCRM
//
//  "When was this last done?" — sent with every visit by /api/schedule/day
//  (ServiceHistoryService). 14 cells: Monday of last week → Sunday of this week.
//

import Foundation

struct ServiceHistoryDay: Codable, Hashable, Identifiable {
    let date: String          // yyyy-MM-dd
    /// completed | in_progress | skipped | scheduled | none
    let state: String
    /// Completions that day — more than one on a storm day for salt / snow.
    let count: Int

    var id: String { date }

    var dayOfMonth: String {
        guard date.count >= 10 else { return "" }
        let d = date.suffix(2)
        return d.hasPrefix("0") ? String(d.suffix(1)) : String(d)
    }
}

struct ServiceHistory: Codable, Hashable {
    let days: [ServiceHistoryDay]
    let today: String
    let winter: Bool
    let lastCompletedAt: String?
    let applications24h: Int
    let summary: String?

    enum CodingKeys: String, CodingKey {
        case days, today, winter, summary
        case lastCompletedAt = "last_completed_at"
        case applications24h = "applications_24h"
    }

    /// Last week, this week — each Monday → Sunday.
    var weeks: [[ServiceHistoryDay]] {
        guard days.count == 14 else { return [] }
        return [Array(days[0..<7]), Array(days[7..<14])]
    }

    /// True when the most recent thing that happened was a skip — worth flagging on the card.
    var summaryIsWarning: Bool { summary?.hasPrefix("Skipped") == true }
}
