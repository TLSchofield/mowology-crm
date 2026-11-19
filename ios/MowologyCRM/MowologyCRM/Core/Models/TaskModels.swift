//
//  TaskModels.swift
//  MowologyCRM
//
//  GET/POST /api/tasks/tasks-mobile (Add → Task, 2026-11-08).
//

import Foundation

struct TaskCreateResponse: Decodable {
    let success: Bool
    let error: String?
}

struct TaskPerson: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
}

/// One client at one property, as kept on the phone for picking with no signal.
struct DirectoryClient: Codable, Identifiable, Hashable {
    let contactId: Int
    let name: String
    let propertyId: Int
    let address: String

    var id: Int { propertyId }

    private enum CodingKeys: String, CodingKey {
        case name, address
        case contactId = "contact_id", propertyId = "property_id"
    }
}

struct TaskDirectoryResponse: Decodable {
    let success: Bool
    let people: [TaskPerson]?
    let clients: [DirectoryClient]?
}

// MARK: - Add → Quote (GET/POST /api/quotes/quote-mobile)

struct QuoteProduct: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
    let unit: String
    let price: Double
}

struct QuotePriceListResponse: Decodable {
    let success: Bool
    let gstPercent: Double?
    let products: [QuoteProduct]?

    private enum CodingKeys: String, CodingKey {
        case success, products
        case gstPercent = "gst_percent"
    }
}

struct QuoteCreateResponse: Decodable {
    let success: Bool
    let error: String?
}

// MARK: - Add → Invoice (GET/POST /api/invoices/invoices-mobile)

struct UnbilledVisit: Codable, Identifiable, Hashable {
    let visitId: Int
    let date: String
    let title: String
    let amount: Double
    let client: String
    let address: String

    var id: Int { visitId }

    private enum CodingKeys: String, CodingKey {
        case date, title, amount, client, address
        case visitId = "visit_id"
    }
}

struct DraftInvoice: Codable, Identifiable, Hashable {
    let invoiceId: Int
    let number: String
    let client: String
    let total: Double

    var id: Int { invoiceId }

    private enum CodingKeys: String, CodingKey {
        case number, client, total
        case invoiceId = "invoice_id"
    }
}

struct InvoicesMobileResponse: Decodable {
    let success: Bool
    let unbilled: [UnbilledVisit]?
    let drafts: [DraftInvoice]?
}

struct InvoiceActionResponse: Decodable {
    let success: Bool
    let error: String?
}
