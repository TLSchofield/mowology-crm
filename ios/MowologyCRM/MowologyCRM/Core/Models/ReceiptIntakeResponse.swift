//
//  ReceiptIntakeResponse.swift
//  MowologyCRM
//

import Foundation

struct ReceiptIntakeResponse: Decodable {
    let success: Bool
    let mediaId: Int
    let ocrAvailable: Bool
    let ocrSource: String?
    let parsed: ParsedReceipt?
    let suggestions: ReceiptSuggestions?
    let jobSuggestions: [JobSuggestion]?
    let duplicateImage: DuplicateImageInfo?

    enum CodingKeys: String, CodingKey {
        case success
        case mediaId        = "media_id"
        case ocrAvailable   = "ocr_available"
        case ocrSource      = "ocr_source"
        case parsed, suggestions
        case jobSuggestions = "job_suggestions"
        case duplicateImage = "duplicate_image"
    }
}

struct ParsedReceipt: Codable {
    let total: String?
    let gst: String?
    let subtotal: String?
    let pst: String?
    let date: String?
    let vendorHint: String?
    let paymentMethod: String?
    let gstEstimated: Bool?

    enum CodingKeys: String, CodingKey {
        case total, gst, subtotal, pst, date
        case vendorHint    = "vendor_hint"
        case paymentMethod = "payment_method"
        case gstEstimated  = "gst_estimated"
    }

    var totalDouble: Double? { total.flatMap(Double.init) }
    var gstDouble: Double?   { gst.flatMap(Double.init) }
}

struct ReceiptSuggestions: Decodable {
    let vendorId: Int?
    let vendorName: String?
    let vendorConfidence: Int?
    let accountingCategory: String?
    let categoryConfidence: Int?
    let vendorNeedsReview: Bool?

    enum CodingKeys: String, CodingKey {
        case vendorId           = "vendor_id"
        case vendorName         = "vendor_name"
        case vendorConfidence   = "vendor_confidence"
        case accountingCategory = "accounting_category"
        case categoryConfidence = "category_confidence"
        case vendorNeedsReview  = "vendor_needs_review"
    }
}

struct JobSuggestion: Decodable, Identifiable {
    let id: Int
    let planNumber: String?
    let serviceType: String?
    let address: String?

    enum CodingKeys: String, CodingKey {
        case id
        case planNumber  = "plan_number"
        case serviceType = "service_type"
        case address
    }
}

struct DuplicateImageInfo: Decodable {
    let existingMediaId: Int
    enum CodingKeys: String, CodingKey { case existingMediaId = "existing_media_id" }
}
