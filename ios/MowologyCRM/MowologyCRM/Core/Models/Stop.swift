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
    let contactPhone: String?
    let companyName: String?
    let lawnSqft: Int?
    let crewNames: [String]
    let visitCount: Int
    let visits: [Visit]
    /// Permanent site instructions: gate code, dog, parking, access pin, etc.
    let propertyNotes: String?
    /// Today-specific instructions added when this stop was scheduled.
    let stopNotes: String?

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
        case contactPhone     = "contact_phone"
        case companyName      = "company_name"
        case lawnSqft         = "lawn_sqft"
        case crewNames        = "crew_names"
        case visitCount       = "visit_count"
        case visits
        case propertyNotes    = "property_notes"
        case stopNotes        = "stop_notes"
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

    /// What the crew call this stop: the building name if it has one, else the company, else the
    /// client. nil when there is nothing better than the street address.
    var headline: String? {
        let address = propertyAddress.trimmingCharacters(in: .whitespaces).lowercased()
        for candidate in [propertyName, companyName, contactName] {
            guard let name = candidate?.trimmingCharacters(in: .whitespaces), !name.isEmpty,
                  name.lowercased() != address else { continue }
            return name
        }
        return nil
    }

    /// The client label for the card footer — nil when it would only repeat the headline.
    /// "Repeat" includes near-misses: a building called "Spirit Halloween Property" owned by
    /// the company "Spirit Halloween" is the same name to anyone reading the card.
    var footerClientName: String? {
        guard let client = displayName?.trimmingCharacters(in: .whitespaces), !client.isEmpty else { return nil }
        guard let head = headline?.lowercased() else { return client }
        let c = client.lowercased()
        return (head == c || head.contains(c) || c.contains(head)) ? nil : client
    }

    /// Returns true when all visits for this stop are completed.
    var isComplete: Bool {
        !visits.isEmpty && visits.allSatisfy { $0.visitStatus.lowercased() == "completed" }
    }

    /// True when nothing is left to do here — every visit is completed, skipped
    /// or cancelled. Unlike `isComplete` (which drives the green "done" visuals),
    /// this only decides whether the stop still counts as upcoming work.
    var isResolved: Bool {
        !visits.isEmpty && visits.allSatisfy {
            ["completed", "skipped", "cancelled"].contains($0.visitStatus.lowercased())
        }
    }

    /// True when at least one visit is in_progress.
    var isInProgress: Bool {
        visits.contains { $0.visitStatus.lowercased() == "in_progress" }
    }
}
