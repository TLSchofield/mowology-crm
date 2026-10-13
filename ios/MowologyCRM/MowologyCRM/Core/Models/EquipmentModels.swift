//
//  EquipmentModels.swift
//  MowologyCRM
//
//  Otto → Add equipment (JWT /api/team/equipment-mobile). The server reads the model/serial plate
//  (EquipmentService::readPlate — same reader as the web page), the phone shows a prefilled form,
//  and Save adds the machine (EquipmentService::addFromPlate), linking its model to the shared
//  catalogue. The web page /crm/ops/equipment.php does the same through dispatch.php.
//

import Foundation

struct EquipmentChoice: Decodable, Hashable, Identifiable {
    let value: String
    let label: String
    var id: String { value }
}

struct EquipmentPerson: Decodable, Hashable, Identifiable {
    let id: Int
    let name: String
}

/// GET ?mode=options
struct EquipmentOptions: Decodable {
    let ok: Bool
    let classes: [EquipmentChoice]
    let power: [EquipmentChoice]
    let people: [EquipmentPerson]
    let trucks: [EquipmentPerson]
}

struct EquipmentSuggested: Decodable {
    let name: String?
    let make: String?
    let model: String?
    let serialNo: String?
    let equipmentClass: String?
    let powerSource: String?
    let modelName: String?

    enum CodingKeys: String, CodingKey {
        case name, make, model
        case serialNo = "serial_no"
        case equipmentClass = "equipment_class"
        case powerSource = "power_source"
        case modelName = "model_name"
    }
}

struct EquipmentExisting: Decodable {
    let id: Int
    let name: String
}

/// What Otto already knows about the model (shared catalogue).
struct EquipmentKnownModel: Decodable {
    let name: String
    let intervals: Int
    let units: Int
}

struct EquipmentReceiptCandidate: Decodable, Identifiable, Hashable {
    let id: Int
    let date: String
    let total: Double?
    let vendor: String
    let why: String
}

/// POST multipart mode=plate
struct PlateReadResponse: Decodable {
    let ok: Bool
    let message: String?
    let suggested: EquipmentSuggested?
    let existing: EquipmentExisting?
    let knownModel: EquipmentKnownModel?
    let receipts: [EquipmentReceiptCandidate]?
    let plateMediaId: Int?
    let photoMediaId: Int?
    let labelCaptureId: Int?

    enum CodingKeys: String, CodingKey {
        case ok, message, suggested, existing, receipts
        case knownModel = "known_model"
        case plateMediaId = "plate_media_id"
        case photoMediaId = "photo_media_id"
        case labelCaptureId = "label_capture_id"
    }
}

struct EquipmentReceiptsResponse: Decodable {
    let ok: Bool
    let receipts: [EquipmentReceiptCandidate]
}

/// POST JSON mode=save
struct EquipmentSaveResponse: Decodable {
    let ok: Bool
    let message: String
    let id: Int?
    let sharedIntervals: Int?
    let url: String?

    enum CodingKeys: String, CodingKey {
        case ok, message, id, url
        case sharedIntervals = "shared_intervals"
    }
}
