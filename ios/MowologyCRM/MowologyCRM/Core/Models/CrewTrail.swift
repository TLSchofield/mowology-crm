//
//  CrewTrail.swift
//  MowologyCRM
//
//  Decodable models for GET /api/schedule/crew-trails — GPS polylines + live
//  positions for crew members. Crew see only their own data; admins see all.
//

import Foundation
import CoreLocation

// MARK: - Top-level response

struct CrewTrailsResponse: Decodable {
    let success: Bool
    let date: String
    let isAdmin: Bool
    let currentUserId: Int
    let routes: [CrewRoute]
    let live: [CrewLiveLocation]

    enum CodingKeys: String, CodingKey {
        case success
        case date
        case isAdmin       = "is_admin"
        case currentUserId = "current_user_id"
        case routes
        case live
    }
}

// MARK: - Trail polyline (one per crew member)

struct CrewRoute: Decodable, Identifiable, Hashable {
    let userId: Int
    let fullName: String
    let deviceType: String
    let points: [CrewTrailPoint]

    var id: Int { userId }

    enum CodingKeys: String, CodingKey {
        case userId     = "user_id"
        case fullName   = "full_name"
        case deviceType = "device_type"
        case points
    }

    /// Materialise the trail as `CLLocationCoordinate2D`s for `MapPolyline`.
    var coordinates: [CLLocationCoordinate2D] {
        points.map { CLLocationCoordinate2D(latitude: $0.lat, longitude: $0.lng) }
    }
}

struct CrewTrailPoint: Decodable, Hashable {
    let lat: Double
    let lng: Double
    let accuracy: Int?
    let time: String
}

// MARK: - Live position (latest fix per crew member, last 24h)

struct CrewLiveLocation: Decodable, Identifiable, Hashable {
    let userId: Int
    let fullName: String
    let deviceType: String
    let lat: Double
    let lng: Double
    let accuracyMeters: Int?
    let secondsAgo: Int
    let isClockedIn: Int

    var id: Int { userId }

    enum CodingKeys: String, CodingKey {
        case userId         = "user_id"
        case fullName       = "full_name"
        case deviceType     = "device_type"
        case lat
        case lng
        case accuracyMeters = "accuracy_meters"
        case secondsAgo     = "seconds_ago"
        case isClockedIn    = "is_clocked_in"
    }

    var coordinate: CLLocationCoordinate2D {
        CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }

    var isTruck: Bool { deviceType == "truck" }

    var clockedIn: Bool { isClockedIn == 1 }
}
