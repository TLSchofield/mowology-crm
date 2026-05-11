//
//  CrewMapModels.swift
//  MowologyCRM
//
//  Decodable models for the /api/team/crew-map JWT endpoint (admin only).
//

import Foundation
import MapKit

// MARK: - Crew member location

struct CrewMapMember: Decodable, Identifiable {
    let userId: Int
    let fullName: String
    let role: String
    let deviceType: String
    let lat: Double
    let lng: Double
    let accuracyMeters: Int?
    let lastUpdate: String
    let secondsAgo: Int
    let isClockedIn: Bool

    var id: Int { userId }

    var coordinate: CLLocationCoordinate2D {
        CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }

    var staleness: StalenessLevel {
        switch secondsAgo {
        case 0..<120:   return .fresh
        case 120..<600: return .stale
        default:        return .old
        }
    }

    var lastSeenText: String {
        switch secondsAgo {
        case 0..<60:    return "just now"
        case 60..<3600: return "\(secondsAgo / 60)m ago"
        default:        return "\(secondsAgo / 3600)h ago"
        }
    }

    enum StalenessLevel { case fresh, stale, old }

    enum CodingKeys: String, CodingKey {
        case userId         = "user_id"
        case fullName       = "full_name"
        case role
        case deviceType     = "device_type"
        case lat, lng
        case accuracyMeters = "accuracy_meters"
        case lastUpdate     = "last_update"
        case secondsAgo     = "seconds_ago"
        case isClockedIn    = "is_clocked_in"
    }
}

// MARK: - Response

struct CrewMapResponse: Decodable {
    let success: Bool
    let crew: [CrewMapMember]
}
