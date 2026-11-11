//
//  TimesheetModels.swift
//  MowologyCRM
//
//  Response shape for GET /api/schedule/clock?mode=week — the crew member's own
//  week of clock punches and job time. Timestamps are server-local
//  "yyyy-MM-dd HH:mm:ss" strings, like every other clock payload.
//

import Foundation

struct TimesheetWeekResponse: Decodable {
    let success: Bool
    let weekStart: String
    let weekEnd: String
    /// Clocked time across the week, including a still-running shift.
    let totalSeconds: Int
    /// Time spent on job timers — always ≤ clocked time; the gap is travel/yard time.
    let jobTotalSeconds: Int
    let days: [TimesheetDay]
    /// Breaks (2026-10-13): counted once (a tapped break wins over GPS). Nil from an older server.
    let breakSeconds: Int?
    /// Clocked less unpaid breaks — equal to totalSeconds when breaks are paid (Mowology).
    let paidSeconds: Int?
    let breaksPaid: Bool?

    enum CodingKeys: String, CodingKey {
        case success
        case weekStart       = "week_start"
        case weekEnd         = "week_end"
        case totalSeconds    = "total_seconds"
        case jobTotalSeconds = "job_total_seconds"
        case days
        case breakSeconds    = "break_seconds"
        case paidSeconds     = "paid_seconds"
        case breaksPaid      = "breaks_paid"
    }
}

struct TimesheetDay: Decodable, Identifiable {
    let date: String
    let totalSeconds: Int
    let jobSeconds: Int
    let entries: [TimesheetClockEntry]
    let jobs: [TimesheetJobEntry]

    var id: String { date }
    var isEmpty: Bool { entries.isEmpty && jobs.isEmpty }

    enum CodingKeys: String, CodingKey {
        case date
        case totalSeconds = "total_seconds"
        case jobSeconds   = "job_seconds"
        case entries, jobs
    }
}

struct TimesheetClockEntry: Decodable, Identifiable {
    let id: Int
    let clockIn: String
    let clockOut: String?
    let durationSeconds: Int
    let isOpen: Bool
    /// True when the office corrected this punch after the fact.
    let edited: Bool
    let notes: String?
    let breaks: [TimesheetBreak]?
    let breakSeconds: Int?

    enum CodingKeys: String, CodingKey {
        case id
        case clockIn         = "clock_in"
        case clockOut        = "clock_out"
        case durationSeconds = "duration_seconds"
        case isOpen          = "is_open"
        case edited, notes, breaks
        case breakSeconds    = "break_seconds"
    }
}

/// One break in a shift: tapped ("manual") or found by the phone's GPS ("gps").
struct TimesheetBreak: Decodable, Identifiable {
    let start: String
    let end: String?
    let minutes: Int
    let source: String

    var id: String { start + source }
    var tapped: Bool { source == "manual" }
}

struct TimesheetJobEntry: Decodable, Identifiable {
    let id: Int
    let visitId: Int
    let jobTitle: String?
    let propertyAddress: String?
    let startTime: String
    let endTime: String?
    let durationSeconds: Int

    enum CodingKeys: String, CodingKey {
        case id
        case visitId         = "visit_id"
        case jobTitle        = "job_title"
        case propertyAddress = "property_address"
        case startTime       = "start_time"
        case endTime         = "end_time"
        case durationSeconds = "duration_seconds"
    }
}
