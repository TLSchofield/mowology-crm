//
//  JobsQuotesInvoicesModels.swift
//  MowologyCRM
//

import Foundation

// MARK: - Jobs

struct JobsListResponse: Decodable {
    let success: Bool
    let total: Int
    let limit: Int
    let offset: Int
    let visits: [JobItem]
}

struct JobItem: Decodable, Identifiable {
    let visitId: Int
    let visitNumber: String?
    let planTitle: String?
    let serviceType: String?
    let visitStatus: String
    let propertyAddress: String
    let propertyCity: String
    let scheduledDate: String?
    let scheduledStart: String?
    let estimatedDuration: Int?
    let pricePerVisit: Double?
    let stopId: Int?

    var id: Int { visitId }

    enum CodingKeys: String, CodingKey {
        case visitId          = "visit_id"
        case visitNumber      = "visit_number"
        case planTitle        = "plan_title"
        case serviceType      = "service_type"
        case visitStatus      = "visit_status"
        case propertyAddress  = "property_address"
        case propertyCity     = "property_city"
        case scheduledDate    = "scheduled_date"
        case scheduledStart   = "scheduled_start"
        case estimatedDuration = "estimated_duration"
        case pricePerVisit    = "price_per_visit"
        case stopId           = "stop_id"
    }

    var statusColor: String {
        switch visitStatus.lowercased() {
        case "completed":   return "green"
        case "in_progress": return "orange"
        case "scheduled":   return "blue"
        case "cancelled":   return "red"
        case "skipped":     return "gray"
        default:            return "gray"
        }
    }

    var statusLabel: String {
        switch visitStatus.lowercased() {
        case "completed":   return "Completed"
        case "in_progress": return "In Progress"
        case "scheduled":   return "Scheduled"
        case "cancelled":   return "Cancelled"
        case "skipped":     return "Skipped"
        default:            return visitStatus.capitalized
        }
    }
}

// MARK: - Quotes

struct QuotesListResponse: Decodable {
    let success: Bool
    let total: Int
    let limit: Int
    let offset: Int
    let quotes: [QuoteItem]
}

struct QuoteItem: Decodable, Identifiable {
    let quoteId: Int
    let quoteNumber: String?
    let clientName: String
    let status: String
    let totalAmount: Double?
    let validUntil: String?
    let createdAt: String?

    var id: Int { quoteId }

    enum CodingKeys: String, CodingKey {
        case quoteId     = "quote_id"
        case quoteNumber = "quote_number"
        case clientName  = "client_name"
        case status
        case totalAmount = "total_amount"
        case validUntil  = "valid_until"
        case createdAt   = "created_at"
    }

    var statusLabel: String {
        switch status.lowercased() {
        case "pending_review": return "Pending Review"
        case "draft":          return "Draft"
        case "sent":           return "Sent"
        case "approved":       return "Approved"
        case "rejected":       return "Rejected"
        case "expired":        return "Expired"
        default:               return status.capitalized
        }
    }

    var statusColor: String {
        switch status.lowercased() {
        case "approved":       return "green"
        case "sent":           return "blue"
        case "pending_review": return "orange"
        case "draft":          return "gray"
        case "rejected",
             "expired":        return "red"
        default:               return "gray"
        }
    }
}

// MARK: - Invoices

struct InvoicesListResponse: Decodable {
    let success: Bool
    let total: Int
    let limit: Int
    let offset: Int
    let invoices: [InvoiceItem]
}

struct InvoiceItem: Decodable, Identifiable {
    let invoiceId: Int
    let invoiceNumber: String?
    let clientName: String
    let status: String
    let totalAmount: Double
    let amountPaid: Double
    let balanceOwing: Double
    let dueDate: String?
    let createdAt: String?

    var id: Int { invoiceId }

    enum CodingKeys: String, CodingKey {
        case invoiceId     = "invoice_id"
        case invoiceNumber = "invoice_number"
        case clientName    = "client_name"
        case status
        case totalAmount   = "total_amount"
        case amountPaid    = "amount_paid"
        case balanceOwing  = "balance_owing"
        case dueDate       = "due_date"
        case createdAt     = "created_at"
    }

    var isOverdue: Bool {
        guard status.lowercased() != "paid",
              let due = dueDate,
              let date = ISO8601DateFormatter().date(from: due + "T00:00:00Z")
        else { return false }
        return date < Date()
    }

    var statusLabel: String {
        switch status.lowercased() {
        case "draft":     return "Draft"
        case "sent":      return "Sent"
        case "paid":      return "Paid"
        case "overdue":   return "Overdue"
        case "cancelled": return "Cancelled"
        default:          return status.capitalized
        }
    }

    var statusColor: String {
        switch status.lowercased() {
        case "paid":      return "green"
        case "sent":      return "blue"
        case "draft":     return "gray"
        case "overdue":   return "red"
        case "cancelled": return "gray"
        default:          return "gray"
        }
    }
}
