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
    let staff: [TeamMember]?

    enum CodingKeys: String, CodingKey {
        case success, results, frequencies, staff
        case serviceTypes = "service_types"
    }
}

/// What this property was last charged for this kind of job, before GST.
struct FieldJobLastPrice: Decodable, Equatable {
    let found: Bool
    let price: Double?
    let label: String?
    let when: String?
}

struct FieldJobActionResponse: Decodable {
    let success: Bool
    let error: String?
    let visitNumber: String?
    let planNumber: String?
    /// The property the server made or found (create_client / create_client_job) — Otto draws its border next.
    let propertyId: Int?

    enum CodingKeys: String, CodingKey {
        case success, error
        case propertyId  = "property_id"
        case visitNumber = "visit_number"
        case planNumber  = "plan_number"
    }
}


/// Otto's answer when asked to draw (or approve / remove) a property's arrival border.
struct OttoBorderResponse: Decodable {
    let ok: Bool
    let message: String?
    let areaSqm: Int?
    /// [[lat, lng], …], open ring.
    let ring: [[Double]]?

    enum CodingKeys: String, CodingKey {
        case ok, message, ring
        case areaSqm = "area_sqm"
    }
}
