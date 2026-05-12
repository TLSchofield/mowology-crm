//
//  Visit.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct Visit: Codable, Identifiable, Hashable {
    let visitId: Int
    let visitNumber: String?
    let serviceType: String
    let planTitle: String?
    let planNumber: String?
    let visitStatus: String
    let estimatedDuration: Int?
    let pricePerVisit: Double?
    let scheduledStart: String?
    /// Crew endorsed this visit — drives review request gate + BA marketing.
    let isFlagged: Bool
    /// Client has already left a Google review — shown as "Review received" reward.
    let contactHasReviewed: Bool

    var id: Int { visitId }

    enum CodingKeys: String, CodingKey {
        case visitId              = "visit_id"
        case visitNumber          = "visit_number"
        case serviceType          = "service_type"
        case planTitle            = "plan_title"
        case planNumber           = "plan_number"
        case visitStatus          = "visit_status"
        case estimatedDuration    = "estimated_duration"
        case pricePerVisit        = "price_per_visit"
        case scheduledStart       = "scheduled_start"
        case isFlagged            = "is_flagged"
        case contactHasReviewed   = "contact_has_reviewed"
    }

    // Direct init for previews and unit tests (not used in production decoding).
    init(visitId: Int, visitNumber: String? = nil, serviceType: String = "",
         planTitle: String? = nil, planNumber: String? = nil,
         visitStatus: String = "scheduled", estimatedDuration: Int? = nil,
         pricePerVisit: Double? = nil, scheduledStart: String? = nil,
         isFlagged: Bool = false, contactHasReviewed: Bool = false) {
        self.visitId            = visitId
        self.visitNumber        = visitNumber
        self.serviceType        = serviceType
        self.planTitle          = planTitle
        self.planNumber         = planNumber
        self.visitStatus        = visitStatus
        self.estimatedDuration  = estimatedDuration
        self.pricePerVisit      = pricePerVisit
        self.scheduledStart     = scheduledStart
        self.isFlagged          = isFlagged
        self.contactHasReviewed = contactHasReviewed
    }

    // Graceful decode: older schedule API responses that lack these fields default to false.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        visitId            = try  c.decode(Int.self,     forKey: .visitId)
        visitNumber        = try? c.decode(String.self,  forKey: .visitNumber)
        serviceType        = (try? c.decode(String.self, forKey: .serviceType)) ?? ""
        planTitle          = try? c.decode(String.self,  forKey: .planTitle)
        planNumber         = try? c.decode(String.self,  forKey: .planNumber)
        visitStatus        = (try? c.decode(String.self, forKey: .visitStatus)) ?? "scheduled"
        estimatedDuration  = try? c.decode(Int.self,     forKey: .estimatedDuration)
        pricePerVisit      = try? c.decode(Double.self,  forKey: .pricePerVisit)
        scheduledStart     = try? c.decode(String.self,  forKey: .scheduledStart)
        isFlagged          = (try? c.decode(Bool.self,   forKey: .isFlagged))          ?? false
        contactHasReviewed = (try? c.decode(Bool.self,   forKey: .contactHasReviewed)) ?? false
    }

    // MARK: - Computed UI Properties

    /// Maps API status values to SwiftUI Colors for badge rendering.
    var statusColor: Color {
        switch visitStatus.lowercased() {
        case "completed":           return .green
        case "in_progress":         return Color.MW.green
        case "scheduled":           return .blue
        case "cancelled":           return .red
        case "skipped":             return .orange
        default:                    return .gray
        }
    }

    /// Human-readable label derived from the snake_case service_type key.
    var serviceTypeLabel: String {
        switch serviceType.lowercased() {
        case "lawn_care":           return "Lawn Care"
        case "hedge_trimming":      return "Hedge Trimming"
        case "snow_removal":        return "Snow Removal"
        case "fertilization":       return "Fertilization"
        case "aeration":            return "Aeration"
        case "leaf_removal":        return "Leaf Removal"
        case "irrigation":          return "Irrigation"
        case "landscape_design":    return "Landscape Design"
        case "cleanup":             return "Cleanup"
        case "mulching":            return "Mulching"
        case "weeding":             return "Weeding"
        case "tree_service":        return "Tree Service"
        case "gutter_cleaning":     return "Gutter Cleaning"
        case "pressure_washing":    return "Pressure Washing"
        default:
            // Fallback: capitalize words and replace underscores with spaces.
            return serviceType
                .replacingOccurrences(of: "_", with: " ")
                .capitalized
        }
    }

    /// Returns a copy of this Visit with `visitStatus` replaced.
    /// Used by ScheduleViewModel to overlay optimistic statuses on cached data.
    func withStatus(_ newStatus: String) -> Visit {
        Visit(
            visitId:            visitId,
            visitNumber:        visitNumber,
            serviceType:        serviceType,
            planTitle:          planTitle,
            planNumber:         planNumber,
            visitStatus:        newStatus,
            estimatedDuration:  estimatedDuration,
            pricePerVisit:      pricePerVisit,
            scheduledStart:     scheduledStart,
            isFlagged:          isFlagged,
            contactHasReviewed: contactHasReviewed
        )
    }

    /// Human-readable visit status label.
    var statusLabel: String {
        switch visitStatus.lowercased() {
        case "in_progress":   return "In Progress"
        case "completed":     return "Completed"
        case "scheduled":     return "Scheduled"
        case "cancelled":     return "Cancelled"
        case "skipped":       return "Skipped"
        default:
            return visitStatus
                .replacingOccurrences(of: "_", with: " ")
                .capitalized
        }
    }
}
