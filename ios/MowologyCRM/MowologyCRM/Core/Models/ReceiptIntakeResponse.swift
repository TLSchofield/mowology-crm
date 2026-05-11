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

    init(from decoder: Decoder) throws {
        let c      = try decoder.container(keyedBy: CodingKeys.self)
        success    = try c.decode(Bool.self, forKey: .success)
        mediaId    = try c.decode(Int.self, forKey: .mediaId)
        filePath   = try c.decodeIfPresent(String.self, forKey: .filePath)
        // Default false — server always sends this field, but guard against older API versions
        ocrAvailable   = try c.decodeIfPresent(Bool.self, forKey: .ocrAvailable) ?? false
        ocrSource      = try c.decodeIfPresent(String.self, forKey: .ocrSource)
        parsed         = try c.decodeIfPresent(ParsedReceipt.self, forKey: .parsed)
        suggestions    = try c.decodeIfPresent(ReceiptSuggestions.self, forKey: .suggestions)
        jobSuggestions = try c.decodeIfPresent([JobSuggestion].self, forKey: .jobSuggestions)
        duplicateImage = try c.decodeIfPresent(DuplicateImageInfo.self, forKey: .duplicateImage)
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

struct ReceiptLineItem: Identifiable, Equatable {
    // UUID assigned at decode time — stable across renders, unique even when two
    // items share the same name and amount (e.g. two identical line items on one receipt).
    let id: String
    let name: String
    let amount: String?
    let quantity: String?
    let unitPrice: String?
}

extension ReceiptLineItem: Codable {
    enum CodingKeys: String, CodingKey {
        case name, amount, quantity
        case unitPrice = "unit_price"
    }

    init(from decoder: Decoder) throws {
        let c  = try decoder.container(keyedBy: CodingKeys.self)
        id        = UUID().uuidString
        name      = try c.decode(String.self, forKey: .name)
        amount    = try c.decodeIfPresent(String.self, forKey: .amount)
        quantity  = try c.decodeIfPresent(String.self, forKey: .quantity)
        unitPrice = try c.decodeIfPresent(String.self, forKey: .unitPrice)
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encode(name,      forKey: .name)
        try c.encodeIfPresent(amount,    forKey: .amount)
        try c.encodeIfPresent(quantity,  forKey: .quantity)
        try c.encodeIfPresent(unitPrice, forKey: .unitPrice)
    }

    // Equality ignores the UUID — two items with identical content are considered equal
    static func == (lhs: ReceiptLineItem, rhs: ReceiptLineItem) -> Bool {
        lhs.name == rhs.name && lhs.amount == rhs.amount &&
        lhs.quantity == rhs.quantity && lhs.unitPrice == rhs.unitPrice
    }
}
