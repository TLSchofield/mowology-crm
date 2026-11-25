//
//  LabelCaptureResponse.swift
//  MowologyCRM
//
//  Decodes POST /api/expenses/label-upload — a photo of a product bag or a machine's
//  nameplate. The server reads it (same OCR as receipts) but never makes an expense:
//  Penny (product) or Otto (machine) shows a one-tap proposal on the web dashboard.
//  The phone only shows what was read and where it went.
//

import Foundation

struct LabelCaptureResponse: Decodable {
    let success: Bool
    let captureId: Int?
    /// "product" | "machine" | nil (no text could be read)
    let kind: String?
    let title: String?
    let message: String
    let existing: Bool?
    /// A product the phone can add right now (nil: a machine, nothing read, or no permission).
    let proposal: Proposal?

    struct Proposal: Decodable {
        let id: Int
        /// "product_new" | "product_match" (the photo belongs to a product we already have)
        let kind: String
        let name: String
        let unitCost: Double?

        var isNew: Bool { kind == "product_new" }

        enum CodingKeys: String, CodingKey {
            case id, kind, name
            case unitCost = "unit_cost"
        }
    }

    enum CodingKeys: String, CodingKey {
        case success, kind, title, message, existing, proposal
        case captureId = "capture_id"
    }

    /// Who will ask Tim about it.
    var head: String {
        switch kind {
        case "machine": return "Otto"
        case "product": return "Penny"
        default:        return "Nobody yet"
        }
    }
}

/// Decodes POST /api/expenses/label-accept.
struct LabelAcceptResponse: Decodable {
    let success: Bool
    let message: String?
    let error: String?
    let productId: Int?

    enum CodingKeys: String, CodingKey {
        case success, message, error
        case productId = "product_id"
    }
}
