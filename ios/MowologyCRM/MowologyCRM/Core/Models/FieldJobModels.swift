//
//  FieldJobModels.swift
//  MowologyCRM
//
//  /api/schedule/field-job — "add a job / visit on the spot".
//

import Foundation

struct FieldJobPlan: Decodable, Identifiable, Hashable {
    let id: Int
    let title: String
    let serviceType: String?
    let hasVisitToday: Bool

    enum CodingKeys: String, CodingKey {
        case id, title
        case serviceType   = "service_type"
        case hasVisitToday = "has_visit_today"
    }
}

struct FieldJobProperty: Decodable, Identifiable, Hashable {
    let id: Int
    let address: String
    let city: String?
    let distanceM: Int
    let contactName: String?
    let plans: [FieldJobPlan]

    enum CodingKeys: String, CodingKey {
        case id, address, city, plans
        case distanceM   = "distance_m"
        case contactName = "contact_name"
    }

    var distanceText: String {
        distanceM < 1000 ? "\(distanceM) m" : String(format: "%.1f km", Double(distanceM) / 1000)
    }
}

struct FieldJobNearbyResponse: Decodable {
    let success: Bool
    let results: [FieldJobProperty]?
    let serviceTypes: [String]?
    let frequencies: [String]?

    enum CodingKeys: String, CodingKey {
        case success, results, frequencies
        case serviceTypes = "service_types"
    }
}

struct FieldJobActionResponse: Decodable {
    let success: Bool
    let error: String?
    let visitNumber: String?
    let planNumber: String?

    enum CodingKeys: String, CodingKey {
        case success, error
        case visitNumber = "visit_number"
        case planNumber  = "plan_number"
    }
}
