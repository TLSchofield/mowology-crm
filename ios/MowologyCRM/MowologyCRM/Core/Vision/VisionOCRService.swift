//
//  VisionOCRService.swift
//  MowologyCRM
//
//  On-device OCR via Apple's Vision framework (Neural Engine, ~150-250 ms).
//  Used to pre-fill ReceiptReviewView instantly while the server upload runs
//  in the background for vendor matching, category suggestions, and line items.
//

import Vision
import UIKit

// MARK: - VisionPreFill

/// Lightweight struct returned by VisionOCRService.
/// Holds fields that can be pre-populated in the review form before
/// the server-side response (which adds vendor_id, category, line items).
struct VisionPreFill {
    let rawText:       String
    let vendorHint:    String?
    let total:         String?
    let subtotal:      String?
    let gst:           String?
    let date:          String?   // "yyyy-MM-dd" or nil
    let paymentMethod: String?   // API payment method key or nil
}

// MARK: - VisionOCRService

/// Stateless service. Call `scan(_:)` with a UIImage; always returns a result.
enum VisionOCRService {

    /// Runs `VNRecognizeTextRequest` on `image` and returns extracted fields.
    /// Never throws — returns an empty VisionPreFill if Vision finds nothing.
    static func scan(_ image: UIImage) async -> VisionPreFill {
        guard let cgImage = image.cgImage else {
            return VisionPreFill(rawText: "", vendorHint: nil, total: nil,
                                 subtotal: nil, gst: nil, date: nil, paymentMethod: nil)
        }

        return await withCheckedContinuation { continuation in
            let request = VNRecognizeTextRequest { request, _ in
                let observations = (request.results as? [VNRecognizedTextObservation]) ?? []
                let lines        = observations.compactMap { $0.topCandidates(1).first?.string }
                let rawText      = lines.joined(separator: "\n")
                continuation.resume(returning: Self.parse(lines: lines, rawText: rawText))
            }
            request.recognitionLevel    = .accurate
            // Disable language correction — it "fixes" prices (e.g. "36.19" → "36.9")
            request.usesLanguageCorrection = false
            request.recognitionLanguages   = ["en-CA", "en-US", "fr-CA"]

            let handler = VNImageRequestHandler(cgImage: cgImage, options: [:])
            try? handler.perform([request])
        }
    }

    // MARK: - Field Parsing

    /// Internal entry point used by unit tests (bypasses Vision image OCR).
    /// Call `VisionOCRService.parseForTesting(lines:rawText:)` from XCTest.
    internal static func parseForTesting(lines: [String], rawText: String) -> VisionPreFill {
        parse(lines: lines, rawText: rawText)
    }

    private static func parse(lines: [String], rawText: String) -> VisionPreFill {
        VisionPreFill(
            rawText:       rawText,
            vendorHint:    extractVendor(lines),
            total:         extractAmounts(lines).total,
            subtotal:      extractAmounts(lines).subtotal,
            gst:           extractAmounts(lines).gst,
            date:          extractDate(lines),
            paymentMethod: extractPaymentMethod(rawText)
        )
    }

    // MARK: - Vendor

    /// The first "real" text line (≥3 chars, not all-digits, not mostly punctuation)
    /// is usually the store name or chain.
    private static func extractVendor(_ lines: [String]) -> String? {
        for line in lines.prefix(8) {
            let t = line.trimmingCharacters(in: .whitespaces)
            guard t.count >= 3 else { continue }
            // Skip lines that look like addresses or numbers
            if Double(t.replacingOccurrences(of: " ", with: "")) != nil { continue }
            let alphanumericCount = t.unicodeScalars.filter {
                CharacterSet.alphanumerics.contains($0)
            }.count
            if Double(alphanumericCount) / Double(t.count) < 0.4 { continue }
            return t
        }
        return nil
    }

    // MARK: - Amounts

    /// Matches dollar amounts like 36.19, 1,234.56
    private static let amountRegex = try? NSRegularExpression(
        pattern: #"(?<!\d)\d{1,3}(?:,\d{3})*\.\d{2}(?!\d)"#
    )

    private static func amounts(in line: String) -> [Double] {
        guard let re = amountRegex else { return [] }
        let ns = line as NSString
        return re.matches(in: line, range: NSRange(location: 0, length: ns.length))
            .compactMap { Double(ns.substring(with: $0.range).replacingOccurrences(of: ",", with: "")) }
            .filter { $0 > 0 && $0 < 99_999 }
    }

    private static func extractAmounts(_ lines: [String]) -> (total: String?, subtotal: String?, gst: String?) {
        var total:    Double?
        var subtotal: Double?
        var gst:      Double?

        for line in lines {
            let u    = line.uppercased()
            let nums = amounts(in: line)
            guard let first = nums.first else { continue }

            if u.contains("GST") || u.contains("TPS") || u.contains("HST") ||
               u.contains("TVQ") || (u.contains("TAX") && !u.contains("TOTAL")) {
                if gst == nil { gst = first }
            } else if u.contains("SUBTOTAL") || u.contains("SUB TOTAL") || u.contains("SUB-TOTAL") {
                if subtotal == nil { subtotal = first }
            } else if u.contains("TOTAL") || u.contains("AMOUNT DUE") ||
                      u.contains("BALANCE DUE") || u.contains("GRAND TOTAL") {
                // Keep the largest TOTAL match (avoids picking up "SUBTOTAL" line's total)
                if let curr = total { if first > curr { total = first } } else { total = first }
            }
        }

        // Derive missing fields
        if total == nil, let s = subtotal, let g = gst {
            total = round((s + g) * 100) / 100
        }
        // Back-calculate 5% GST when only a total is available
        if let t = total, subtotal == nil, gst == nil {
            let s = round(t / 1.05 * 100) / 100
            let g = round((t - s) * 100) / 100
            subtotal = s
            gst      = g > 0 ? g : nil
        }

        let fmt: (Double?) -> String? = { d in
            guard let d else { return nil }
            return String(format: "%.2f", d)
        }
        return (fmt(total), fmt(subtotal), fmt(gst))
    }

    // MARK: - Date

    private static func extractDate(_ lines: [String]) -> String? {
        typealias Pattern = (regex: String, format: String)
        let patterns: [Pattern] = [
            (#"\d{4}[-/]\d{2}[-/]\d{2}"#,                                                  "yyyy-MM-dd"),
            (#"\d{2}[-/]\d{2}[-/]\d{4}"#,                                                  "MM/dd/yyyy"),
            (#"(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.? \d{1,2},? \d{4}"#, "MMM d, yyyy"),
            (#"\d{1,2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.? \d{4}"#,  "d MMM yyyy"),
        ]

        let combined = lines.joined(separator: " ")
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_CA")

        for (pattern, format) in patterns {
            guard let re = try? NSRegularExpression(pattern: pattern, options: .caseInsensitive),
                  let match = re.firstMatch(in: combined, range: NSRange(combined.startIndex..., in: combined)),
                  let range = Range(match.range, in: combined)
            else { continue }

            let raw = String(combined[range])
            formatter.dateFormat = format
            guard let date = formatter.date(from: raw) else { continue }
            formatter.dateFormat = "yyyy-MM-dd"
            return formatter.string(from: date)
        }
        return nil
    }

    // MARK: - Payment Method

    private static func extractPaymentMethod(_ text: String) -> String? {
        let u = text.uppercased()
        if u.contains("VISA")                                           { return "credit_card" }
        if u.contains("MASTERCARD") || u.contains("MASTER CARD")        { return "credit_card" }
        if u.contains("AMEX") || u.contains("AMERICAN EXPRESS")         { return "credit_card" }
        if u.contains("DEBIT")                                           { return "debit" }
        if u.contains("CASH") || u.contains("ESPÈCES")                  { return "cash" }
        if u.contains("E-TRANSFER") || u.contains("ETRANSFER")          { return "etransfer" }
        return nil
    }
}
