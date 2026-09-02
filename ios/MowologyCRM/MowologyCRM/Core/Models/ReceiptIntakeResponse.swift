//
//  ReceiptIntakeResponse.swift
//  MowologyCRM
//
//  Decodes POST /api/expenses/receipt-upload, plus the small lookup models the
//  review form needs from GET /api/expenses/expense-lookup (vendors, jobs,
//  categories, duplicates). Kept in one file so the review form's data contract
//  with the server lives in one place.
//

import Foundation

struct ReceiptIntakeResponse: Decodable {
    let success: Bool
    let mediaId: Int
    let ocrAvailable: Bool
    let ocrSource: String?
    /// Raw OCR text — sent back on save as `raw_ocr_json` so the server's
    /// self-learning parser can record corrections. Without it every iOS save was
    /// invisible to the learning loop.
    let ocrText: String?
    let parsed: ParsedReceipt?
    let suggestions: ReceiptSuggestions?
    let jobSuggestions: [JobSuggestion]?
    let duplicateImage: DuplicateImageInfo?
    let gstValidation: GstValidation?

    enum CodingKeys: String, CodingKey {
        case success
        case mediaId        = "media_id"
        case ocrAvailable   = "ocr_available"
        case ocrSource      = "ocr_source"
        case ocrText        = "ocr_text"
        case parsed, suggestions
        case jobSuggestions = "job_suggestions"
        case duplicateImage = "duplicate_image"
        case gstValidation  = "gst_validation"
    }
}

struct GstValidation: Decodable {
    let valid: Bool?
    let message: String?
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

/// An OCR-detected line item. Read-only on the phone — mirrors Android's mobile review
/// card, which shows detected items ("N items detected") but doesn't let the user edit
/// individual lines either; both platforms just send the OCR result through unmodified.
struct ReceiptLineItem: Codable {
    let name: String?
    let amount: String?
    let quantity: Double?
    let unitPrice: Double?
    let skuRaw: String?

    enum CodingKeys: String, CodingKey {
        case name, amount, quantity
        case unitPrice = "unit_price"
        case skuRaw    = "sku_raw"
    }

    var amountDouble: Double? { amount.flatMap(Double.init) }
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

/// A GPS/schedule-derived job suggestion from ReceiptSmartMatch::suggestJobFromSchedule().
/// The server keys these by `plan_id` (not `id`) — the previous model required an `id`
/// key, so every upload made near a scheduled stop failed to decode, was treated as an
/// upload failure, and got queued for an offline retry that never opened the review form.
struct JobSuggestion: Decodable, Identifiable {
    let planId: Int
    let propertyId: Int?
    let contactId: Int?
    let contactName: String?
    let serviceType: String?
    let propertyAddress: String?
    let score: Int?

    var id: Int { planId }

    enum CodingKeys: String, CodingKey {
        case planId          = "plan_id"
        case propertyId      = "property_id"
        case contactId       = "contact_id"
        case contactName     = "contact_name"
        case serviceType     = "service_type"
        case propertyAddress = "property_address"
        case score
    }
}

struct DuplicateImageInfo: Decodable {
    let existingMediaId: Int
    enum CodingKeys: String, CodingKey { case existingMediaId = "existing_media_id" }
}

// MARK: - Lookup models (GET /api/expenses/expense-lookup)

struct VendorSearchResult: Decodable, Identifiable {
    let id: Int
    let name: String
    let aliases: String?
    let defaultAccountingCategory: String?
    let defaultGbpCategory: String?

    enum CodingKeys: String, CodingKey {
        case id, name, aliases
        case defaultAccountingCategory = "default_accounting_category"
        case defaultGbpCategory        = "default_gbp_category"
    }
}

struct VendorSearchResponse: Decodable {
    let success: Bool
    let vendors: [VendorSearchResult]
}

struct JobSearchResult: Decodable, Identifiable {
    let id: Int
    let planNumber: String?
    let serviceType: String?
    let status: String?
    let propertyId: Int?
    let contactId: Int?
    let address: String?
    let contactName: String?

    enum CodingKeys: String, CodingKey {
        case id, status, address
        case planNumber  = "plan_number"
        case serviceType = "service_type"
        case propertyId  = "property_id"
        case contactId   = "contact_id"
        case contactName = "contact_name"
    }
}

struct JobSearchResponse: Decodable {
    let success: Bool
    let jobs: [JobSearchResult]
}

struct ExpenseCategoriesResponse: Decodable {
    let success: Bool
    let accountingCategories: [String]
    let paymentMethods: [String]

    enum CodingKeys: String, CodingKey {
        case success
        case accountingCategories = "accounting_categories"
        case paymentMethods       = "payment_methods"
    }
}

/// A likely-duplicate expense (same total within ±3 days, same vendor). Rows come
/// straight from PDO so `total` may arrive as a string ("12.50") or a number.
struct DuplicateExpense: Decodable, Identifiable {
    let id: Int
    let expenseDate: String
    let total: Double
    let status: String
    let vendorNameRaw: String?
    let vendorName: String?

    enum CodingKeys: String, CodingKey {
        case id, total, status
        case expenseDate   = "expense_date"
        case vendorNameRaw = "vendor_name_raw"
        case vendorName    = "vendor_name"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id            = try c.decode(Int.self, forKey: .id)
        expenseDate   = try c.decode(String.self, forKey: .expenseDate)
        status        = (try? c.decode(String.self, forKey: .status)) ?? "draft"
        vendorNameRaw = try? c.decode(String.self, forKey: .vendorNameRaw)
        vendorName    = try? c.decode(String.self, forKey: .vendorName)
        if let d = try? c.decode(Double.self, forKey: .total) {
            total = d
        } else if let s = try? c.decode(String.self, forKey: .total), let d = Double(s) {
            total = d
        } else {
            total = 0
        }
    }

    var displayVendor: String { vendorName ?? vendorNameRaw ?? "Unknown vendor" }
}

struct DuplicateCheckResponse: Decodable {
    let success: Bool
    let hasDuplicates: Bool
    let duplicates: [DuplicateExpense]

    enum CodingKeys: String, CodingKey {
        case success, duplicates
        case hasDuplicates = "has_duplicates"
    }
}

/// A job chosen for an expense — from a GPS suggestion pill or a text search. Carries
/// all three identifiers the server persists (job/plan, property, contact), same as
/// the Android review card's hidden fields.
struct JobPick: Identifiable, Equatable {
    let id: Int             // job_plans.id
    let propertyId: Int?
    let contactId: Int?
    let title: String
    let subtitle: String?

    init(suggestion s: JobSuggestion) {
        id         = s.planId
        propertyId = s.propertyId
        contactId  = s.contactId
        let name   = (s.contactName?.isEmpty == false) ? s.contactName! : "Job"
        title      = s.serviceType.map { "\(name) — \($0)" } ?? name
        subtitle   = s.propertyAddress
    }

    init(search j: JobSearchResult) {
        id         = j.id
        propertyId = j.propertyId
        contactId  = j.contactId
        let name   = (j.contactName?.isEmpty == false) ? j.contactName! : (j.planNumber ?? "Job")
        title      = j.serviceType.map { "\(name) — \($0)" } ?? name
        subtitle   = j.address
    }
}
