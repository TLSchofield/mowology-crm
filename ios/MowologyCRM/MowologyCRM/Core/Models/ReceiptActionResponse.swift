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
