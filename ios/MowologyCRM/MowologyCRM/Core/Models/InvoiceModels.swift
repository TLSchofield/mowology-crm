//
//  InvoiceModels.swift
//  MowologyCRM
//
//  Decodable responses for the mobile "invoice a completed visit" flow
//  (POST /api/schedule/invoice → preview / create / send).
//

import Foundation

/// One line on a created invoice (base service line, or a timed-extras line).
struct InvoiceLineItem: Decodable, Identifiable {
    let description: String
    let amount: Double
    let isExtras: Bool

    /// Identifiable for ForEach — descriptions are unique enough within one invoice.
    var id: String { description }

    enum CodingKeys: String, CodingKey {
        case description
        case amount
        case isExtras = "is_extras"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        description = (try? c.decode(String.self, forKey: .description)) ?? ""
        amount      = (try? c.decode(Double.self, forKey: .amount)) ?? 0
        isExtras    = (try? c.decode(Bool.self, forKey: .isExtras)) ?? false
    }
}

/// Response from `action: "create"` — the draft invoice with totals.
struct InvoiceCreateResult: Decodable {
    let success: Bool
    let error: String?
    let invoiceId: Int?
    let invoiceNumber: String?
    let contactName: String?
    let contactEmail: String?
    let lineItems: [InvoiceLineItem]
    let subtotal: Double?
    let taxRate: Double?
    let taxAmount: Double?
    let total: Double?

    enum CodingKeys: String, CodingKey {
        case success, error, total, subtotal
        case invoiceId     = "invoice_id"
        case invoiceNumber = "invoice_number"
        case contactName   = "contact_name"
        case contactEmail  = "contact_email"
        case lineItems     = "line_items"
        case taxRate       = "tax_rate"
        case taxAmount     = "tax_amount"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success       = (try? c.decode(Bool.self, forKey: .success)) ?? false
        error         = try? c.decode(String.self, forKey: .error)
        invoiceId     = try? c.decode(Int.self, forKey: .invoiceId)
        invoiceNumber = try? c.decode(String.self, forKey: .invoiceNumber)
        contactName   = try? c.decode(String.self, forKey: .contactName)
        contactEmail  = try? c.decode(String.self, forKey: .contactEmail)
        lineItems     = (try? c.decode([InvoiceLineItem].self, forKey: .lineItems)) ?? []
        subtotal      = try? c.decode(Double.self, forKey: .subtotal)
        taxRate       = try? c.decode(Double.self, forKey: .taxRate)
        taxAmount     = try? c.decode(Double.self, forKey: .taxAmount)
        total         = try? c.decode(Double.self, forKey: .total)
    }
}

/// Response from `action: "send"` — confirmation + recipients.
struct InvoiceSendResult: Decodable {
    let success: Bool
    let error: String?
    let invoiceNumber: String?
    let sentTo: [String]

    enum CodingKeys: String, CodingKey {
        case success, error
        case invoiceNumber = "invoice_number"
        case sentTo        = "sent_to"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success       = (try? c.decode(Bool.self, forKey: .success)) ?? false
        error         = try? c.decode(String.self, forKey: .error)
        invoiceNumber = try? c.decode(String.self, forKey: .invoiceNumber)
        sentTo        = (try? c.decode([String].self, forKey: .sentTo)) ?? []
    }
}
