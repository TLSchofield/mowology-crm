//
//  InvoiceModels.swift
//  MowologyCRM
//
//  Models for the iOS invoice compose flow (Complete & Invoice).
//  Separate from JobsQuotesInvoicesModels.swift which handles the read-only list.
//

import Foundation

// MARK: - Prefill envelope

struct InvoicePrefillResponse: Decodable {
    let success: Bool
    let data: InvoicePrefill?
    // Error cases
    let existingInvoiceId: Int?
    let message: String?

    enum CodingKeys: String, CodingKey {
        case success
        case data
        case existingInvoiceId = "existing_invoice_id"
        case message
    }
}

// MARK: - Prefill (drives the compose sheet; Identifiable so sheet(item:) works)

struct InvoicePrefill: Decodable, Identifiable {
    var id: Int { visitId }

    let visitId: Int
    let visitNumber: String?
    let planId: Int?
    let propertyId: Int?
    let companyId: Int?
    let contactId: Int?
    let clientDisplayName: String
    let serviceAddress: String
    let serviceCity: String
    let serviceProvince: String
    let servicePostalCode: String
    let lineItems: [PrefillLineItem]
    let extrasLineItem: PrefillLineItem?
    let subtotal: Double
    let taxRate: Double
    let taxAmount: Double
    let total: Double
    let issueDate: String
    let defaultDueDate: String
    let recipients: [PrefillRecipient]
    let crewNotes: String?
    let gstNumber: String?

    enum CodingKeys: String, CodingKey {
        case visitId           = "visit_id"
        case visitNumber       = "visit_number"
        case planId            = "plan_id"
        case propertyId        = "property_id"
        case companyId         = "company_id"
        case contactId         = "contact_id"
        case clientDisplayName = "client_display_name"
        case serviceAddress    = "service_address"
        case serviceCity       = "service_city"
        case serviceProvince   = "service_province"
        case servicePostalCode = "service_postal_code"
        case lineItems         = "line_items"
        case extrasLineItem    = "extras_line_item"
        case subtotal
        case taxRate           = "tax_rate"
        case taxAmount         = "tax_amount"
        case total
        case issueDate         = "issue_date"
        case defaultDueDate    = "default_due_date"
        case recipients
        case crewNotes         = "crew_notes"
        case gstNumber         = "gst_number"
    }
}

// MARK: - Editable line item (client-side UUID for List identity)

struct PrefillLineItem: Codable, Identifiable {
    var id: UUID
    var description: String
    var quantity: Double
    var unitPrice: Double
    var lineTotal: Double
    var sortOrder: Int
    var isExtras: Bool

    enum CodingKeys: String, CodingKey {
        case description
        case quantity
        case unitPrice  = "unit_price"
        case lineTotal  = "line_total"
        case sortOrder  = "sort_order"
        case isExtras   = "is_extras"
    }

    init(id: UUID = UUID(), description: String, quantity: Double,
         unitPrice: Double, lineTotal: Double, sortOrder: Int, isExtras: Bool = false) {
        self.id          = id
        self.description = description
        self.quantity    = quantity
        self.unitPrice   = unitPrice
        self.lineTotal   = lineTotal
        self.sortOrder   = sortOrder
        self.isExtras    = isExtras
    }

    init(from decoder: Decoder) throws {
        let c       = try decoder.container(keyedBy: CodingKeys.self)
        id          = UUID()
        description = try c.decode(String.self,  forKey: .description)
        quantity    = try c.decode(Double.self,  forKey: .quantity)
        unitPrice   = try c.decode(Double.self,  forKey: .unitPrice)
        lineTotal   = try c.decode(Double.self,  forKey: .lineTotal)
        sortOrder   = try c.decode(Int.self,     forKey: .sortOrder)
        isExtras    = (try? c.decode(Bool.self,  forKey: .isExtras)) ?? false
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encode(description, forKey: .description)
        try c.encode(quantity,    forKey: .quantity)
        try c.encode(unitPrice,   forKey: .unitPrice)
        try c.encode(lineTotal,   forKey: .lineTotal)
        try c.encode(sortOrder,   forKey: .sortOrder)
        try c.encode(isExtras,    forKey: .isExtras)
    }

    mutating func recompute() {
        lineTotal = (quantity * unitPrice * 100).rounded() / 100
    }
}

// MARK: - Recipient

struct PrefillRecipient: Decodable, Identifiable {
    let id: Int
    let name: String
    let emailAddress: String
    let role: String
    let receivesSms: Bool
    let phone: String?

    enum CodingKeys: String, CodingKey {
        case id           = "contact_id"
        case name         = "contact_name"
        case emailAddress = "email_address"
        case role         = "contact_role"
        case receivesSms  = "receive_sms"
        case phone
    }
}

// MARK: - Create+Send response

struct InvoiceCreateSendResponse: Decodable {
    let success: Bool
    let invoiceId: Int?
    let invoiceNumber: String?
    let total: Double?
    let status: String?
    let paymentPortalUrl: String?
    let sentTo: [String]?
    let smsSent: Bool?
    // error cases
    let existingInvoiceId: Int?
    let message: String?

    enum CodingKeys: String, CodingKey {
        case success
        case invoiceId        = "invoice_id"
        case invoiceNumber    = "invoice_number"
        case total
        case status
        case paymentPortalUrl = "payment_portal_url"
        case sentTo           = "sent_to"
        case smsSent          = "sms_sent"
        case existingInvoiceId = "existing_invoice_id"
        case message
    }
}
