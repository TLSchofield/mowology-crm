//
//  LocalOCR.swift
//  MowologyCRM
//
//  On-device receipt text parser. Extracts vendor, total, GST, and date from
//  VisionKit-recognised lines with no network round-trip.
//

import Foundation

// MARK: - LocalReceiptParse

struct LocalReceiptParse {
    var total:      Double?
    var totalRaw:   String?
    var gst:        Double?
    var gstRaw:     String?
    var date:       String?
    var vendorHint: String?
}

// MARK: - LocalOCR

enum LocalOCR {

    /// Parse an ordered array of text lines from VisionKit / DataScannerViewController.
    static func parseLines(_ lines: [String]) -> LocalReceiptParse {
        guard !lines.isEmpty else { return LocalReceiptParse() }

        let amountPattern = /\$?\s*(\d{1,4}[.,]\d{2})\b/

        var total:      Double?
        var totalRaw:   String?
        var gst:        Double?
        var gstRaw:     String?
        var date:       String?
        var vendorHint: String?

        for (i, line) in lines.enumerated() {
            let lower   = line.lowercased()
            let trimmed = line.trimmingCharacters(in: .whitespacesAndNewlines)

            // Vendor: first short, text-dominant line in the top 5
            if vendorHint == nil, i < 5, trimmed.count >= 3, trimmed.count <= 40 {
                let digitRatio = Double(trimmed.filter(\.isNumber).count) / Double(trimmed.count)
                if digitRatio < 0.4 { vendorHint = trimmed }
            }

            // Date — ISO, slash, or abbreviated-month formats
            if date == nil {
                if let m = line.firstMatch(of: /\b(\d{4}-\d{2}-\d{2})\b/)      { date = String(m.output.1) }
                else if let m = line.firstMatch(of: /\b(\d{2}\/\d{2}\/\d{4})\b/) { date = String(m.output.1) }
                else if let m = line.firstMatch(of: /\b(\d{1,2}-[A-Za-z]{3}-\d{4})\b/) { date = String(m.output.1) }
            }

            // GST / HST / Tax (first match wins)
            if gst == nil, lower.contains("gst") || lower.contains("hst") || lower.contains("tax") {
                if let m = line.firstMatch(of: amountPattern) {
                    gst    = Double(String(m.output.1).replacingOccurrences(of: ",", with: "."))
                    gstRaw = String(m.output.1)
                } else if i + 1 < lines.count, let m = lines[i + 1].firstMatch(of: amountPattern) {
                    gst    = Double(String(m.output.1).replacingOccurrences(of: ",", with: "."))
                    gstRaw = String(m.output.1)
                }
            }

            // Total — last "total" line wins (grand total > subtotal guard)
            if lower.contains("total"), !lower.contains("subtotal"), !lower.contains("sub total") {
                if let m = line.firstMatch(of: amountPattern) {
                    total    = Double(String(m.output.1).replacingOccurrences(of: ",", with: "."))
                    totalRaw = String(m.output.1)
                } else if i + 1 < lines.count, let m = lines[i + 1].firstMatch(of: amountPattern) {
                    total    = Double(String(m.output.1).replacingOccurrences(of: ",", with: "."))
                    totalRaw = String(m.output.1)
                }
            }
        }

        return LocalReceiptParse(
            total: total, totalRaw: totalRaw,
            gst: gst, gstRaw: gstRaw,
            date: date, vendorHint: vendorHint
        )
    }
}
