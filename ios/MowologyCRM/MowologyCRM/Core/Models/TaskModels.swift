//
//  TaskModels.swift
//  MowologyCRM
//
//  GET/POST /api/tasks/tasks-mobile (Add → Task, 2026-11-08).
//

import Foundation

struct TaskCreateResponse: Decodable {
    let success: Bool
    let error: String?
}

struct TaskPerson: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
}

/// One client at one property, as kept on the phone for picking with no signal.
struct DirectoryClient: Codable, Identifiable, Hashable {
    let contactId: Int
    let name: String
    let propertyId: Int
    let address: String

    var id: Int { propertyId }

    private enum CodingKeys: String, CodingKey {
        case name, address
        case contactId = "contact_id", propertyId = "property_id"
    }
}

struct TaskDirectoryResponse: Decodable {
    let success: Bool
    let people: [TaskPerson]?
    let clients: [DirectoryClient]?
}
