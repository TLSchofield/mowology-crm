//
//  Expense.swift
//  MowologyCRM
//

import Foundation

struct Expense: Decodable, Identifiable {
    let id: Int
    /// Optional: schema allows NULL on legacy rows; defensively decoded.
    let expenseDate: String?
    let vendorName: String?
    let vendorNameRaw: String?
    let description: String?
    let amount: Double?
    let gstAmount: Double?
    /// Decodes from the server's `total` field. Defaults to 0 if missing/null
    /// to keep the whole list-decode resilient against a single bad row.
    let total: Double
    let accountingCategory: String?
    let paymentMethod: String?
    /// Optional: schema default is 'draft' but be defensive against legacy NULLs.
    let status: String?
    let forwardedToAccounting: Int?
    let receiptMediaId: Int?
    let receiptUrl: String?
    let jobId: Int?
    let anomalyScore: Int?
    /// Optional: created_at has a DB default but be defensive against legacy NULLs.
    let createdAt: String?

    enum CodingKeys: String, CodingKey {
        case id, description, status
        case expenseDate            = "expense_date"
        case vendorName             = "vendor_name"
        case vendorNameRaw          = "vendor_name_raw"
        case amount
        case gstAmount              = "gst_amount"
        case total
        case accountingCategory     = "accounting_category"
        case paymentMethod          = "payment_method"
        case forwardedToAccounting  = "forwarded_to_accounting"
        case receiptMediaId         = "receipt_media_id"
        case receiptUrl             = "receipt_url"
        case jobId                  = "job_id"
        case anomalyScore           = "anomaly_score"
        case createdAt              = "created_at"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id                    = try c.decode(Int.self,    forKey: .id)
        expenseDate           = try c.decodeIfPresent(String.self, forKey: .expenseDate)
        vendorName            = try c.decodeIfPresent(String.self, forKey: .vendorName)
        vendorNameRaw         = try c.decodeIfPresent(String.self, forKey: .vendorNameRaw)
        description           = try c.decodeIfPresent(String.self, forKey: .description)
        amount                = try c.decodeIfPresent(Double.self, forKey: .amount)
        gstAmount             = try c.decodeIfPresent(Double.self, forKey: .gstAmount)
        total                 = (try? c.decodeIfPresent(Double.self, forKey: .total)) ?? 0
        accountingCategory    = try c.decodeIfPresent(String.self, forKey: .accountingCategory)
        paymentMethod         = try c.decodeIfPresent(String.self, forKey: .paymentMethod)
        status                = try c.decodeIfPresent(String.self, forKey: .status)
        forwardedToAccounting = try c.decodeIfPresent(Int.self,    forKey: .forwardedToAccounting)
        receiptMediaId        = try c.decodeIfPresent(Int.self,    forKey: .receiptMediaId)
        receiptUrl            = try c.decodeIfPresent(String.self, forKey: .receiptUrl)
        jobId                 = try c.decodeIfPresent(Int.self,    forKey: .jobId)
        anomalyScore          = try c.decodeIfPresent(Int.self,    forKey: .anomalyScore)
        createdAt             = try c.decodeIfPresent(String.self, forKey: .createdAt)
    }

    var displayVendor: String {
        vendorName ?? vendorNameRaw ?? "Unknown vendor"
    }

    var isForwarded: Bool { (forwardedToAccounting ?? 0) != 0 }

    var statusColor: String {
        switch status ?? "draft" {
        case "approved":  return "green"
        case "forwarded": return "blue"
        case "rejected":  return "red"
        default:          return "orange"
        }
    }
}

struct ExpenseListResponse: Decodable {
    let success: Bool
    let expenses: [Expense]
    let total: Int
    let page: Int
    let pages: Int
}

struct ExpenseSaveResponse: Decodable {
    let success: Bool
    let expenseId: Int
    enum CodingKeys: String, CodingKey { case success; case expenseId = "expense_id" }
}
