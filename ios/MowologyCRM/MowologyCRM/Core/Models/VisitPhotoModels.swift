//
//  VisitPhotoModels.swift
//  MowologyCRM
//
//  GET /api/schedule/visit-photos — the proof photos already on the server for a visit.
//  `photoType` is before | after | additional (the server folds legacy extras into additional).
//

import Foundation

struct VisitPhoto: Decodable, Identifiable {
    let id: Int
    let photoType: String
    let photoUrl: String
    let thumbUrl: String?
    /// Who took it — in-house record, never shown to clients.
    let takenBy: String?

    enum CodingKeys: String, CodingKey {
        case id
        case photoType = "photo_type"
        case photoUrl  = "photo_url"
        case thumbUrl  = "thumb_url"
        case takenBy   = "taken_by"
    }

    /// Paths come back web-root relative.
    var thumbnailURL: URL? {
        let path = (thumbUrl?.isEmpty == false ? thumbUrl : nil) ?? photoUrl
        if path.hasPrefix("http") { return URL(string: path) }
        return URL(string: "https://mowology.ca" + path)
    }
}

struct VisitPhotosResponse: Decodable {
    let success: Bool
    let photos: [VisitPhoto]?
}
