//
//  FieldSearchModels.swift
//  MowologyCRM
//
//  /api/schedule/search — every hit is a PROPERTY, because that is what a crew member acts on.
//

import Foundation
import CoreLocation

struct SearchPlan: Codable, Identifiable, Hashable {
    let id: Int
    let title: String
    let serviceType: String?
    let hasVisitToday: Bool
    let summary: String?
    /// Only on the property page.
    let history: ServiceHistory?

    enum CodingKeys: String, CodingKey {
        case id, title, summary, history
        case serviceType   = "service_type"
        case hasVisitToday = "has_visit_today"
    }
}

struct SearchUpcomingVisit: Codable, Identifiable, Hashable {
    let visitId: Int
    let visitNumber: String
    let scheduledDate: String
    let status: String
    let planTitle: String

    var id: Int { visitId }

    enum CodingKeys: String, CodingKey {
        case status
        case visitId       = "visit_id"
        case visitNumber   = "visit_number"
        case scheduledDate = "scheduled_date"
        case planTitle     = "plan_title"
    }
}

struct SearchProperty: Codable, Identifiable, Hashable {
    let id: Int
    let label: String
    let propertyName: String?
    let companyName: String?
    let contactName: String?
    let phone: String?
    let address: String
    let city: String?
    let latitude: Double?
    let longitude: Double?
    let distanceM: Int?
    let onToday: Bool
    let todayVisitId: Int?
    let plans: [SearchPlan]
    let lastDone: String?
    // Property page only.
    let notes: String?
    let lawnSqft: Int?
    let upcoming: [SearchUpcomingVisit]?

    enum CodingKeys: String, CodingKey {
        case id, label, phone, address, city, latitude, longitude, plans, notes, upcoming
        case propertyName = "property_name"
        case companyName  = "company_name"
        case contactName  = "contact_name"
        case distanceM    = "distance_m"
        case onToday      = "on_today"
        case todayVisitId = "today_visit_id"
        case lastDone     = "last_done"
        case lawnSqft     = "lawn_sqft"
    }

    var fullAddress: String { [address, city].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: ", ") }

    /// The line under the headline: the address, unless the address IS the headline.
    var subtitle: String { label == address ? (city ?? "") : fullAddress }

    /// Client, when the headline is a building or company rather than the person.
    var clientLine: String? {
        guard let contactName, !contactName.isEmpty, contactName != label else { return nil }
        return contactName
    }

    var distanceText: String? {
        guard let distanceM else { return nil }
        return distanceM < 1000 ? "\(distanceM) m" : String(format: "%.1f km", Double(distanceM) / 1000)
    }

    var coordinate: CLLocationCoordinate2D? {
        guard let latitude, let longitude else { return nil }
        return CLLocationCoordinate2D(latitude: latitude, longitude: longitude)
    }

    var dialURL: URL? {
        guard let phone else { return nil }
        let digits = phone.filter { $0.isNumber || $0 == "+" }
        return digits.count >= 7 ? URL(string: "tel:\(digits)") : nil
    }

    /// For handing to the quick-add flow ("create a job here").
    var asFieldJobProperty: FieldJobProperty {
        FieldJobProperty(
            id: id, address: address, city: city, distanceM: distanceM ?? 0, contactName: contactName ?? label,
            plans: plans.map { FieldJobPlan(id: $0.id, title: $0.title, serviceType: $0.serviceType, hasVisitToday: $0.hasVisitToday) }
        )
    }
}

struct SearchResponse: Decodable {
    let success: Bool
    let results: [SearchProperty]?
}

struct SearchPropertyResponse: Decodable {
    let success: Bool
    let property: SearchProperty?
}
