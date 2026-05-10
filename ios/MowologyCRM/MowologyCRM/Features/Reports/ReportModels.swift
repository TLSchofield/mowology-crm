//
//  ReportModels.swift
//  MowologyCRM
//

import Foundation

// MARK: - Report Types

enum ReportType: String, CaseIterable, Identifiable {
    case revenueByMonth   = "revenue-by-month"
    case revenueByService = "revenue-by-service"
    case quoteFunnel      = "quote-funnel"
    case crewProfit       = "crew-profitability"
    case overdueInvoices  = "overdue-invoices"

    var id: String { rawValue }

    var displayName: String {
        switch self {
        case .revenueByMonth:   return "Revenue by Month"
        case .revenueByService: return "By Service"
        case .quoteFunnel:      return "Quote Funnel"
        case .crewProfit:       return "Crew Profit"
        case .overdueInvoices:  return "Overdue"
        }
    }

    var systemImage: String {
        switch self {
        case .revenueByMonth:   return "chart.bar.fill"
        case .revenueByService: return "chart.pie.fill"
        case .quoteFunnel:      return "arrow.down.right.and.arrow.up.left"
        case .crewProfit:       return "person.2.fill"
        case .overdueInvoices:  return "exclamationmark.triangle.fill"
        }
    }
}

// MARK: - API Response

/// Generic chart series (label + numeric data points).
struct ReportSeries: Decodable {
    let label: String
    let data: [Double]
}

/// Summary key-value pairs returned by the API.
typealias ReportSummary = [String: Double]

/// Overdue invoice row (used only for the overdue-invoices report).
struct OverdueInvoiceRow: Decodable, Identifiable {
    let id: Int
    let invoiceNumber: String
    let clientName: String
    let dueDate: String
    let daysOverdue: Int
    let balanceDue: Double
    let email: String?
    let phone: String?

    enum CodingKeys: String, CodingKey {
        case id
        case invoiceNumber = "invoice_number"
        case clientName    = "client_name"
        case dueDate       = "due_date"
        case daysOverdue   = "days_overdue"
        case balanceDue    = "balance_due"
        case email
        case phone
    }
}

/// Top-level API response for all report types.
struct ReportResponse: Decodable {
    let success: Bool
    let labels: [String]
    let series: [ReportSeries]
    let summary: [String: Double]?
    let rows: [OverdueInvoiceRow]?

    enum CodingKeys: String, CodingKey {
        case success
        case labels
        case series
        case summary
        case rows
    }

    // summary values arrive as Double or String in JSON — use a custom decoder
    init(from decoder: Decoder) throws {
        let c       = try decoder.container(keyedBy: CodingKeys.self)
        success     = try c.decode(Bool.self,          forKey: .success)
        labels      = try c.decode([String].self,      forKey: .labels)
        series      = try c.decode([ReportSeries].self, forKey: .series)
        rows        = try c.decodeIfPresent([OverdueInvoiceRow].self, forKey: .rows)

        // summary values may be Double or Int in the JSON
        if let rawSummary = try c.decodeIfPresent([String: AnyCodable].self, forKey: .summary) {
            var out: [String: Double] = [:]
            for (key, val) in rawSummary {
                switch val.value {
                case let d as Double: out[key] = d
                case let i as Int:    out[key] = Double(i)
                default: break
                }
            }
            summary = out
        } else {
            summary = nil
        }
    }
}

// MARK: - AnyCodable helper (local, minimal)

struct AnyCodable: Decodable {
    let value: Any

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if let i = try? c.decode(Int.self)    { value = i; return }
        if let d = try? c.decode(Double.self) { value = d; return }
        if let s = try? c.decode(String.self) { value = s; return }
        if let b = try? c.decode(Bool.self)   { value = b; return }
        value = NSNull()
    }
}
