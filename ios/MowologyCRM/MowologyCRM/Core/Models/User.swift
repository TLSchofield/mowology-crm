//
//  User.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import Foundation

struct User: Codable, Identifiable {
    let id: Int
    let email: String
    let name: String
    let role: String

    /// Returns true for admin or manager roles — both see all crew stops.
    var isAdmin: Bool {
        role == "admin" || role == "manager"
    }
}
