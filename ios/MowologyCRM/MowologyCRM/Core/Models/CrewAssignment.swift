//
//  CrewAssignment.swift
//  MowologyCRM
//

import Foundation

/// A team member available for crew assignment — from GET /api/schedule/team-members.
struct TeamMember: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
}

/// Response from GET /api/schedule/team-members.
struct TeamMembersResponse: Decodable {
    let success: Bool
    let members: [TeamMember]
}

/// Response from POST /api/schedule/assign-crew.
struct CrewAssignResponse: Decodable {
    let success:  Bool
    let stopId:   Int
    let crewIds:  [Int]
    let crewNames: [String]

    enum CodingKeys: String, CodingKey {
        case success
        case stopId    = "stop_id"
        case crewIds   = "crew_ids"
        case crewNames = "crew_names"
    }
}
