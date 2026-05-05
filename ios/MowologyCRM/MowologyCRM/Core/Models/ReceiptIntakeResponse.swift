//
//  ReceiptIntakeResponse.swift
//  MowologyCRM
//

import Foundation

struct ReceiptIntakeResponse: Decodable, Equatable {
    let success: Bool
    let mediaId: Int
    let filePath: String?
    let ocrAvailable: Bool
    let ocrSource: String?
    let parsed: ParsedReceipt?
    let suggestions: ReceiptSuggestions?
    let jobSuggestions: [JobSuggestion]?
    let duplicateImage: DuplicateImageInfo?

    enum CodingKeys: String, CodingKey {
        case success
        case mediaId        = "media_id"
        case filePath       = "file_path"
        case ocrAvailable   = "ocr_available"
        case ocrSource      = "ocr_source"
        case parsed, suggestions
        case jobSuggestions = "job_suggestions"
        case duplicateImage = "duplicate_image"
    }

    var receiptImageURL: URL? {
        guard let path = filePath, !path.isEmpty else { return nil }
        return URL(string: "https://mowology.ca\(path)")
    }
}

struct ParsedReceipt: Codable, Equatable {
    let total: String?
    let gst: String?
    let subtotal: String?
    let pst: String?
    let date: String?
    let vendorHint: String?
    let paymentMethod: String?
    let gstEstimated: Bool?
    let lineItems: [ReceiptLineItem]?

    enum CodingKeys: String, CodingKey {
        case total, gst, subtotal, pst, date
        case vendorHint    = "vendor_hint"
        case paymentMethod = "payment_method"
        case gstEstimated  = "gst_estimated"
        case lineItems     = "line_items"
    }

    var totalDouble: Double? { total.flatMap(Double.init) }
    var gstDouble: Double?   { gst.flatMap(Double.init) }
}

struct ReceiptSuggestions: Decodable, Equatable {
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

struct JobSuggestion: Decodable, Identifiable, Equatable {
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

struct DuplicateImageInfo: Decodable, Equatable {
    let existingMediaId: Int
    enum CodingKeys: String, CodingKey { case existingMediaId = "existing_media_id" }
}

struct ExpenseMetaResponse: Decodable {
    let success: Bool
    let accountingCategories: [String]
    let paymentMethods: [String]

    enum CodingKeys: String, CodingKey {
        case success
        case accountingCategories = "accounting_categories"
        case paymentMethods       = "payment_methods"
    }
}

struct ReceiptLineItem: Codable, Identifiable, Equatable {
    var id: String { name + (amount ?? "") }
    let name: String
    let amount: String?
    let quantity: String?
    let unitPrice: String?

    enum CodingKeys: String, CodingKey {
        case name, amount, quantity
        case unitPrice = "unit_price"
    }
}
