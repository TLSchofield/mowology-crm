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

    enum CodingKeys: String, CodingKey {
        case success, kind, title, message, existing
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
