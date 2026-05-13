//
//  TimeClockModels.swift
//  MowologyCRM
//

import Foundation

// MARK: - Clock Status

struct ClockStatusResponse: Decodable {
    let success: Bool
    let clockedIn: Bool
    let entryId: Int?
    let clockIn: String?
    let elapsedSeconds: Int?
    let activeJob: ActiveJobTimer?
    let message: String?

    enum CodingKeys: String, CodingKey {
        case success
        case clockedIn       = "clocked_in"
        case entryId         = "entry_id"
        case clockIn         = "clock_in"
        case elapsedSeconds  = "elapsed_seconds"
        case activeJob       = "active_job"
        case message
    }
}

struct ClockActionResponse: Decodable {
    let success: Bool
    let clockedIn: Bool
    let entryId: Int?
    let clockIn: String?
    let clockOut: String?
    let elapsedSeconds: Int?
    let totalMinutes: Int?
    let message: String?

    enum CodingKeys: String, CodingKey {
        case success
        case clockedIn      = "clocked_in"
        case entryId        = "entry_id"
        case clockIn        = "clock_in"
        case clockOut       = "clock_out"
        case elapsedSeconds = "elapsed_seconds"
        case totalMinutes   = "total_minutes"
        case message
    }
}

// MARK: - Job Timer

struct ActiveJobTimer: Decodable {
    let id: Int
    let visitId: Int
    let jobTitle: String?
    let propertyAddress: String?
    let startTime: String
    let elapsedSeconds: Int

    enum CodingKeys: String, CodingKey {
        case id
        case visitId         = "visit_id"
        case jobTitle        = "job_title"
        case propertyAddress = "property_address"
        case startTime       = "start_time"
        case elapsedSeconds  = "elapsed_seconds"
    }
}

struct ActiveTimerResponse: Decodable {
    let success: Bool
    let activeTimer: ActiveJobTimer?

    enum CodingKeys: String, CodingKey {
        case success
        case activeTimer = "active_timer"
    }
}

struct TimerStartResponse: Decodable {
    let success: Bool
    let entryId: Int?
    let visitId: Int?
    let startTime: String?
    let autoClockIn: Bool?
    let message: String?

    enum CodingKeys: String, CodingKey {
        case success
        case entryId      = "entry_id"
        case visitId      = "visit_id"
        case startTime    = "start_time"
        case autoClockIn  = "auto_clock_in"
        case message
    }
}

struct TimerStopResponse: Decodable {
    let success: Bool
    let visitId: Int?
    let durationMinutes: Int?
    let visitCompleted: Bool?
    let message: String?

    enum CodingKeys: String, CodingKey {
        case success
        case visitId         = "visit_id"
        case durationMinutes = "duration_minutes"
        case visitCompleted  = "visit_completed"
        case message
    }
}

// MARK: - Visit Flag Response

struct VisitFlagResponse: Decodable {
    let success:   Bool
    let isFlagged: Bool

    enum CodingKeys: String, CodingKey {
        case success
        case isFlagged = "is_flagged"
    }
}
