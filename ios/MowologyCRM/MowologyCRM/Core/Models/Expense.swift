//
//  Expense.swift
//  MowologyCRM
//

import Foundation

struct Expense: Decodable, Identifiable {
    let id: Int
    let expenseDate: String
    let vendorName: String?
    let vendorNameRaw: String?
    let description: String?
    let amount: Double?
    let gstAmount: Double?
    let total: Double
    let accountingCategory: String?
    let paymentMethod: String?
    let status: String
    let forwardedToAccounting: Int?
    let receiptMediaId: Int?
    let receiptUrl: String?
    let jobId: Int?
    let anomalyScore: Int?
    let matchConfidence: Int?
    let createdAt: String
    let createdBy: Int?
    let archivedAt: String?

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
        case matchConfidence        = "match_confidence"
        case createdAt              = "created_at"
        case createdBy              = "created_by"
        case archivedAt             = "archived_at"
    }

    var displayVendor: String {
        vendorName ?? vendorNameRaw ?? "Unknown vendor"
    }

    var isForwarded: Bool { (forwardedToAccounting ?? 0) != 0 }

    var isArchived: Bool { archivedAt != nil }

    var statusColor: String {
        switch status {
        case "approved":  return "green"
        case "forwarded": return "blue"
        case "rejected":  return "red"
        default:          return "orange"
        }
    }

    /// OCR extraction trust signal — same thresholds as the Android confidence dot
    /// (mw-mc-conf-dot in expenses_appstack.php) so the two clients agree on what
    /// "high/medium/low confidence" means.
    var confidenceTier: String? {
        guard let c = matchConfidence, c > 0 else { return nil }
        if c >= 70 { return "high" }
        if c >= 40 { return "medium" }
        return "low"
    }

    /// Anomaly risk tier — same thresholds as the desktop/Android anomaly icon
    /// (mw-anomaly-icon: >30 high, >15 medium).
    var anomalyTier: String? {
        guard let s = anomalyScore, s > 15 else { return nil }
        return s > 30 ? "high" : "medium"
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
    /// Set when the save was a "Save & Send": whether the forward to accounting went
    /// through, and why not if it didn't. The expense itself is committed either way.
    let sent: Bool?
    let sendError: String?
    enum CodingKeys: String, CodingKey {
        case success, sent
        case expenseId = "expense_id"
        case sendError = "send_error"
    }
}

/// Everything the review form collects for one expense — the single payload shape
/// for both a user-reviewed save and an auto-drafted offline-queue save.
struct ExpenseDraft {
    var vendorId: Int?
    var vendorName: String
    var date: String                // yyyy-MM-dd
    var amount: Double
    var gst: Double
    var pst: Double
    var total: Double
    var category: String
    var paymentMethod: String
    var description: String
    var notes: String
    var mediaId: Int?
    var rawOcrText: String?
    var ocrParsed: ParsedReceipt?
    /// The review card's (possibly corrected) items — renames, "not an item" flags and
    /// manual additions. Falls back to `ocrParsed.lineItems` when nil.
    var lineItems: [ReceiptLineItem]?
    var lineItemsSource: String?
    var job: JobPick?
    var lat: Double?
    var lng: Double?
    var andSend: Bool = false
}

/// Money typed on the phone, read the same whatever the keyboard's decimal separator:
/// "12,50" and "12.50" are both 12.5 (a French/Spanish keypad gave ",", and Double("12,50") is nil —
/// the receipt saved as $0, review round 2 2026-10-12). Also "1,234.56" / "1.234,56" / "$ 12.50".
enum ReceiptNumber {
    static func parse(_ raw: String?) -> Double? {
        guard var s = raw?.trimmingCharacters(in: .whitespacesAndNewlines), !s.isEmpty else { return nil }
        s = s.replacingOccurrences(of: "$", with: "")
             .replacingOccurrences(of: " ", with: "")
             .replacingOccurrences(of: "\u{00A0}", with: "")
             .replacingOccurrences(of: "\u{202F}", with: "")
        let lastComma = s.lastIndex(of: ","), lastDot = s.lastIndex(of: ".")
        if let c = lastComma, let d = lastDot {
            // Both: the later one is the decimal point, the other groups thousands.
            if c > d { s = s.replacingOccurrences(of: ".", with: "").replacingOccurrences(of: ",", with: ".") }
            else     { s = s.replacingOccurrences(of: ",", with: "") }
        } else if let c = lastComma {
            let decimals = s.distance(from: c, to: s.endIndex) - 1
            let commas = s.filter { $0 == "," }.count
            // One comma with 1–2 digits after it is a decimal comma; otherwise thousands.
            s = (commas == 1 && decimals >= 1 && decimals <= 2)
                ? s.replacingOccurrences(of: ",", with: ".")
                : s.replacingOccurrences(of: ",", with: "")
        }
        return Double(s)
    }

    /// The value as the server wants it ("12.50"), or the input unchanged when it isn't a number.
    static func normalized(_ raw: String) -> String {
        let t = raw.trimmingCharacters(in: .whitespaces)
        guard !t.isEmpty, let d = parse(t) else { return t }
        return String(format: "%.2f", d)
    }
}
