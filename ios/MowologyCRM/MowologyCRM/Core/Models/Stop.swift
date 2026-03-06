//
//  Stop.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation

struct Stop: Codable, Identifiable, Hashable {
    let stopId: Int
    let stopDate: String?
    let stopStatus: String
    let routeOrder: Int
    let estimatedArrival: String?
    let propertyId: Int?
    let propertyAddress: String
    let propertyCity: String
    let propertyName: String?
    let latitude: Double?
    let longitude: Double?
    let contactId: Int?
    let contactName: String?
    let companyName: String?
    let lawnSqft: Int?
    let crewNames: [String]
    let visitCount: Int
    let visits: [Visit]

    var id: Int { stopId }

    enum CodingKeys: String, CodingKey {
        case stopId           = "stop_id"
        case stopDate         = "stop_date"
        case stopStatus       = "stop_status"
        case routeOrder       = "route_order"
        case estimatedArrival = "estimated_arrival"
        case propertyId       = "property_id"
        case propertyAddress  = "property_address"
        case propertyCity     = "property_city"
        case propertyName     = "property_name"
        case latitude
        case longitude
        case contactId        = "contact_id"
        case contactName      = "contact_name"
        case companyName      = "company_name"
        case lawnSqft         = "lawn_sqft"
        case crewNames        = "crew_names"
        case visitCount       = "visit_count"
        case visits
    }

    /// Full single-line address for display.
    var fullAddress: String {
        "\(propertyAddress), \(propertyCity)"
    }

    /// Display name: company if set, otherwise contact name.
    var displayName: String? {
        if let company = companyName, !company.isEmpty { return company }
        return contactName
    }

    /// Returns true when all visits for this stop are completed.
    var isComplete: Bool {
        !visits.isEmpty && visits.allSatisfy { $0.visitStatus.lowercased() == "completed" }
    }

    /// True when at least one visit is in_progress.
    var isInProgress: Bool {
        visits.contains { $0.visitStatus.lowercased() == "in_progress" }
    }
}
