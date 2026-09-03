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
    /// Line-item quality signal from the server: 'match' | 'mismatch' | 'none'
    let lineItemsQuality: String?
    let itemsSum: String?
    /// Provenance: 'ocr' | 'vision' | 'llm'
    let lineItemsSource: String?
    let escalationReason: String?

    enum CodingKeys: String, CodingKey {
        case total, gst, subtotal, pst, date
        case vendorHint        = "vendor_hint"
        case paymentMethod     = "payment_method"
        case gstEstimated      = "gst_estimated"
        case lineItems         = "line_items"
        case lineItemsQuality  = "line_items_quality"
        case itemsSum          = "items_sum"
        case lineItemsSource   = "line_items_source"
        case escalationReason  = "escalation_reason"
    }

    var totalDouble: Double? { total.flatMap(Double.init) }
    var gstDouble: Double?   { gst.flatMap(Double.init) }
}

/// An OCR-detected line item on the review card. Editable pre-save on both platforms:
/// `name` may be corrected, `removed` marks "not an item", `manual` marks a row the user
/// typed because OCR missed it. `ocrName` is what the parser produced — echoed back on save
/// so the server records the correction as a per-vendor lesson.
struct ReceiptLineItem: Codable, Identifiable {
    var name: String?
    var amount: String?
    var quantity: Double?
    var unitPrice: Double?
    var skuRaw: String?
    var productId: Int?
    var ocrName: String?
    var nameSource: String?
    var productSource: String?
    var isAdjustment: Bool?

    // Client-only state (not decoded)
    var removed: Bool = false
    var manual: Bool = false
    let localId: UUID = UUID()

    var id: UUID { localId }

    enum CodingKeys: String, CodingKey {
        case name, amount, quantity
        case unitPrice     = "unit_price"
        case skuRaw        = "sku_raw"
        case productId     = "product_id"
        case ocrName       = "ocr_name"
        case nameSource    = "name_source"
        case productSource = "product_source"
        case isAdjustment  = "is_adjustment"
    }

    init(name: String, amount: Double, manual: Bool = false) {
        self.name = name
        self.amount = String(format: "%.2f", amount)
        self.quantity = 1
        self.unitPrice = nil
        self.skuRaw = nil
        self.productId = nil
        self.ocrName = manual ? "" : name
        self.manual = manual
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        name          = try? c.decode(String.self, forKey: .name)
        amount        = try? c.decode(String.self, forKey: .amount)
        if amount == nil, let d = try? c.decode(Double.self, forKey: .amount) { amount = String(format: "%.2f", d) }
        quantity      = Self.lossyDouble(c, .quantity)
        unitPrice     = Self.lossyDouble(c, .unitPrice)
        skuRaw        = try? c.decode(String.self, forKey: .skuRaw)
        productId     = try? c.decode(Int.self, forKey: .productId)
        ocrName       = try? c.decode(String.self, forKey: .ocrName)
        nameSource    = try? c.decode(String.self, forKey: .nameSource)
        productSource = try? c.decode(String.self, forKey: .productSource)
        if let b = try? c.decode(Bool.self, forKey: .isAdjustment) { isAdjustment = b }
        else if let i = try? c.decode(Int.self, forKey: .isAdjustment) { isAdjustment = i != 0 }
        if ocrName == nil || ocrName?.isEmpty == true { ocrName = name }
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encodeIfPresent(name, forKey: .name)
        try c.encodeIfPresent(amount, forKey: .amount)
        try c.encodeIfPresent(quantity, forKey: .quantity)
        try c.encodeIfPresent(unitPrice, forKey: .unitPrice)
        try c.encodeIfPresent(skuRaw, forKey: .skuRaw)
        try c.encodeIfPresent(productId, forKey: .productId)
        try c.encodeIfPresent(ocrName, forKey: .ocrName)
    }

    static func lossyDouble(_ c: KeyedDecodingContainer<CodingKeys>, _ key: CodingKeys) -> Double? {
        if let d = try? c.decode(Double.self, forKey: key) { return d }
        if let s = try? c.decode(String.self, forKey: key) { return Double(s) }
        return nil
    }

    var amountDouble: Double? { amount.flatMap(Double.init) }
}

// MARK: - Stored line items (GET/POST /api/expenses/expense-line-items)

/// A persisted expense_line_items row (decimals arrive as strings from PDO).
struct StoredLineItem: Decodable, Identifiable {
    let id: Int
    let name: String
    let ocrName: String?
    let quantity: Double
    let unitPrice: Double?
    let lineTotal: Double
    let skuRaw: String?
    let productId: Int?
    let productName: String?
    let productSku: String?
    let isAdjustment: Bool

    enum CodingKeys: String, CodingKey {
        case id, name, quantity
        case ocrName      = "ocr_name"
        case unitPrice    = "unit_price"
        case lineTotal    = "line_total"
        case skuRaw       = "sku_raw"
        case productId    = "product_id"
        case productName  = "product_name"
        case productSku   = "product_sku"
        case isAdjustment = "is_adjustment"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        func num(_ k: CodingKeys) -> Double? {
            if let d = try? c.decode(Double.self, forKey: k) { return d }
            if let s = try? c.decode(String.self, forKey: k) { return Double(s) }
            return nil
        }
        func int(_ k: CodingKeys) -> Int? {
            if let i = try? c.decode(Int.self, forKey: k) { return i }
            if let s = try? c.decode(String.self, forKey: k) { return Int(s) }
            return nil
        }
        id          = int(.id) ?? 0
        name        = (try? c.decode(String.self, forKey: .name)) ?? ""
        ocrName     = try? c.decode(String.self, forKey: .ocrName)
        quantity    = num(.quantity) ?? 1
        unitPrice   = num(.unitPrice)
        lineTotal   = num(.lineTotal) ?? 0
        skuRaw      = try? c.decode(String.self, forKey: .skuRaw)
        productId   = int(.productId)
        productName = try? c.decode(String.self, forKey: .productName)
        productSku  = try? c.decode(String.self, forKey: .productSku)
        isAdjustment = (int(.isAdjustment) ?? 0) != 0
    }
}

struct LineItemsResponse: Decodable {
    let success: Bool
    let lineItems: [StoredLineItem]
    let lineItemsSource: String?
    enum CodingKeys: String, CodingKey {
        case success
        case lineItems       = "line_items"
        case lineItemsSource = "line_items_source"
    }
}

struct LineItemMutationResponse: Decodable {
    let success: Bool
    let lineItem: StoredLineItem?
    let error: String?
    enum CodingKeys: String, CodingKey {
        case success, error
        case lineItem = "line_item"
    }
}

struct ProductSearchResult: Decodable, Identifiable {
    let id: Int
    let name: String
    let sku: String?
}

struct ProductSearchResponse: Decodable {
    let success: Bool
    let products: [ProductSearchResult]
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
