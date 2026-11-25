//
//  TripReportModels.swift
//  MowologyCRM
//
//  Commercial vehicle trip inspections — GET/POST /api/schedule/trip-report.
//  Every response carries the full status, so one type covers reads and writes.
//

import Foundation

struct TripVehicle: Decodable, Identifiable, Hashable {
    let id: String
    let label: String
}

struct TripCheckItem: Decodable, Identifiable {
    let field: String
    let label: String
    var id: String { field }
}

struct OpenTrip: Decodable {
    let reportId: Int
    let vehicleId: String
    let preTripAt: String?
    let odometerStart: Int?
    let safeToDrive: Bool

    enum CodingKeys: String, CodingKey {
        case reportId      = "report_id"
        case vehicleId     = "vehicle_id"
        case preTripAt     = "pre_trip_at"
        case odometerStart = "odometer_start"
        case safeToDrive   = "safe_to_drive"
    }
}

struct TripReportStatus: Decodable {
    let success: Bool
    /// none | open | closed
    let tripState: String?
    let openTrip: OpenTrip?
    /// nil = not asked yet this shift — the app should ask.
    let declared: Bool?
    let mustCloseBeforeClockOut: Bool?
    let vehicles: [TripVehicle]?
    let lastOdometer: Int?
    let checks: [TripCheckItem]?

    // Present on a pre-trip save
    let mayDrive: Bool?
    let unchecked: [String]?
    let message: String?

    enum CodingKeys: String, CodingKey {
        case success
        case tripState               = "trip_state"
        case openTrip                = "open_trip"
        case declared
        case mustCloseBeforeClockOut = "must_close_before_clock_out"
        case vehicles
        case lastOdometer            = "last_odometer"
        case checks
        case mayDrive                = "may_drive"
        case unchecked, message
    }
}
