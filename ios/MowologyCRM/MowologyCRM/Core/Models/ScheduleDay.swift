//
//  ScheduleDay.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation

struct ScheduleDay: Codable, Identifiable {
    let date: String
    let dayName: String
    let dayNumber: Int
    let isToday: Bool
    let stopCount: Int
    let visitCount: Int
    let hasIncomplete: Bool

    /// Identifiable conformance — dates are unique within a week response.
    var id: String { date }

    enum CodingKeys: String, CodingKey {
        case date
        case dayName       = "day_name"
        case dayNumber     = "day_number"
        case isToday       = "is_today"
        case stopCount     = "stop_count"
        case visitCount    = "visit_count"
        case hasIncomplete = "has_incomplete"
    }
}
