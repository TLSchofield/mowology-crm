//
//  Visit.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct Visit: Codable, Identifiable, Hashable {
    let visitId:           Int
    let visitNumber:       String?
    let serviceType:       String
    let planTitle:         String?
    let planNumber:        String?
    let visitStatus:       String
    let estimatedDuration: Int?
    let pricePerVisit:     Double?
    let scheduledStart:    String?
    /// Non-nil when `job_visits.invoice_id` is set — visit has been invoiced.
    let invoiceId:         Int?  = nil
    /// Mirrors `job_visits.is_invoiced`; defaults to `false` when absent from JSON.
    let isInvoiced:        Bool  = false

    var id: Int { visitId }

    enum CodingKeys: String, CodingKey {
        case visitId           = "visit_id"
        case visitNumber       = "visit_number"
        case serviceType       = "service_type"
        case planTitle         = "plan_title"
        case planNumber        = "plan_number"
        case visitStatus       = "visit_status"
        case estimatedDuration = "estimated_duration"
        case pricePerVisit     = "price_per_visit"
        case scheduledStart    = "scheduled_start"
        case invoiceId         = "invoice_id"
        case isInvoiced        = "is_invoiced"
    }


    // MARK: - Computed: invoice state

    /// True when this visit has not yet been invoiced.
    var needsInvoice: Bool { invoiceId == nil && !isInvoiced }

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

// MARK: - Custom Decodable
// Placed in an extension so the synthesised memberwise initialiser is preserved
// (previews and tests can still construct Visit values directly).
// `is_invoiced` and `invoice_id` default to false/nil when absent from JSON so
// older API responses don't break.
extension Visit {
    init(from decoder: Decoder) throws {
        let c              = try decoder.container(keyedBy: CodingKeys.self)
        visitId            = try c.decode(Int.self,             forKey: .visitId)
        visitNumber        = try c.decodeIfPresent(String.self, forKey: .visitNumber)
        serviceType        = try c.decode(String.self,          forKey: .serviceType)
        planTitle          = try c.decodeIfPresent(String.self, forKey: .planTitle)
        planNumber         = try c.decodeIfPresent(String.self, forKey: .planNumber)
        visitStatus        = try c.decode(String.self,          forKey: .visitStatus)
        estimatedDuration  = try c.decodeIfPresent(Int.self,    forKey: .estimatedDuration)
        pricePerVisit      = try c.decodeIfPresent(Double.self, forKey: .pricePerVisit)
        scheduledStart     = try c.decodeIfPresent(String.self, forKey: .scheduledStart)
        invoiceId          = try c.decodeIfPresent(Int.self,    forKey: .invoiceId)
        isInvoiced         = try c.decodeIfPresent(Bool.self,   forKey: .isInvoiced) ?? false
    }
}
