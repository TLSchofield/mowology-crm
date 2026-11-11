//
//  ReceiptActionResponse.swift
//  MowologyCRM
//

import Foundation

/// Response shape for POST /api/expenses/receipt-actions (approve/reject/send).
struct ReceiptActionResponse: Decodable {
    let success: Bool
    let message: String?
    let error: String?
}

/// One failed item within a batch_approve/batch_reject response.
struct BatchActionFailure: Decodable {
    let id: Int
    let error: String
}

/// Response shape for POST /api/expenses/receipt-actions {action: "batch_approve"|"batch_reject"}.
/// `approved`/`rejected` — whichever the action was — carries the succeeded ids.
struct BatchActionResponse: Decodable {
    let success: Bool
    let approved: [Int]?
    let rejected: [Int]?
    let failed: [BatchActionFailure]?
    let error: String?
}

/// Response shape for POST /api/expenses/receipt-actions {action: "archive_export"}.
struct ArchiveExportResponse: Decodable {
    let success: Bool
    let error: String?
}
