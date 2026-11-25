//
//  DeviceTokenResponse.swift
//  MowologyCRM
//

import Foundation

/// Response shape for POST /api/device/token.
struct DeviceTokenResponse: Decodable {
    let success: Bool
}
