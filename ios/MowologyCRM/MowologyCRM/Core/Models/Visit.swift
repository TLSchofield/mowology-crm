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
