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
    let pst:           String?   // Provincial Sales Tax (BC PST 7%, QC TVQ, etc.)
    let date:          String?   // "yyyy-MM-dd" or nil
    let paymentMethod: String?   // API payment method key or nil
}

// MARK: - VisionOCRService

/// Stateless service. Call `scan(_:)` with a UIImage; always returns a result.
enum VisionOCRService {

    /// Runs `VNRecognizeTextRequest` on `image` and returns extracted fields.
    /// Never throws — returns an empty VisionPreFill if Vision finds nothing.
    static func scan(_ image: UIImage) async -> VisionPreFill {
        // Normalize orientation before extracting cgImage — UIImage orientation
        // metadata is not preserved when crossing the UIImage→CGImage boundary,
        // so portrait photos would be scanned rotated without this step.
        let oriented = normalizeOrientation(image)
        guard let cgImage = oriented.cgImage else {
            return emptyPreFill
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
            do {
                try handler.perform([request])
                // Continuation was resumed inside the request completion block above.
            } catch {
                // perform() threw before invoking the completion — resume so the caller never hangs.
                continuation.resume(returning: Self.emptyPreFill)
            }
        }
    }

    private static let emptyPreFill = VisionPreFill(
        rawText: "", vendorHint: nil, total: nil,
        subtotal: nil, gst: nil, pst: nil, date: nil, paymentMethod: nil
    )

    /// Returns a copy of `image` drawn upright (imageOrientation == .up).
    /// Portrait photos from UIImagePickerController carry a 90° rotation tag
    /// that CGImage-based APIs (including Vision) do not honour.
    private static func normalizeOrientation(_ image: UIImage) -> UIImage {
        guard image.imageOrientation != .up else { return image }
        let renderer = UIGraphicsImageRenderer(size: image.size)
        return renderer.image { _ in image.draw(at: .zero) }
    }

    // MARK: - Field Parsing

    /// Internal entry point used by unit tests (bypasses Vision image OCR).
    /// Call `VisionOCRService.parseForTesting(lines:rawText:)` from XCTest.
    internal static func parseForTesting(lines: [String], rawText: String) -> VisionPreFill {
        parse(lines: lines, rawText: rawText)
    }

    private static func parse(lines: [String], rawText: String) -> VisionPreFill {
        let amounts = extractAmounts(lines)
        return VisionPreFill(
            rawText:       rawText,
            vendorHint:    extractVendor(lines),
            total:         amounts.total,
            subtotal:      amounts.subtotal,
            gst:           amounts.gst,
            pst:           amounts.pst,
            date:          extractDate(lines),
            paymentMethod: extractPaymentMethod(rawText)
        )
    }

    // MARK: - Vendor

    // Common short words that appear on receipts but are NOT vendor names.
    // Includes French terms (SANS = "without", MERCI = "thank you") common on BC/QC receipts.
    private static let vendorBlocklist: Set<String> = [
        "SANS", "MERCI", "THANK", "THANKS",
        "VISA", "CASH", "DEBIT", "INTERAC", "CREDIT",
        "TOTAL", "TAXES", "RECEIPT", "INVOICE",
        "DATE", "TIME", "REF", "AUTH", "APPROVED",
    ]

    /// The first "real" text line (≥5 chars, not all-digits, not mostly punctuation,
    /// not a known non-vendor keyword) is usually the store name or chain.
    private static func extractVendor(_ lines: [String]) -> String? {
        for line in lines.prefix(8) {
            let t = line.trimmingCharacters(in: .whitespaces)
            guard t.count >= 5 else { continue }
            if vendorBlocklist.contains(t.uppercased()) { continue }
            // Skip lines that look like numbers or phone numbers
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

    private static func extractAmounts(_ lines: [String]) -> (total: String?, subtotal: String?, gst: String?, pst: String?) {
        var total:    Double?
        var subtotal: Double?
        var gst:      Double?
        var pst:      Double?

        for line in lines {
            let u    = line.uppercased()
            let nums = amounts(in: line)
            guard let first = nums.first else { continue }

            // PST / TVQ — provincial tax (must check before generic TAX to avoid misclassification)
            if u.contains("PST") || (u.contains("TVQ") && !u.contains("GST")) {
                if pst == nil { pst = first }
            // GST / TPS / HST — federal tax only (no generic TAX fallthrough here)
            } else if u.contains("GST") || u.contains("TPS") || u.contains("HST") {
                if gst == nil { gst = first }
            // Generic "TAX" line — conservative fallback only when neither GST nor PST found yet
            } else if u.contains("TAX") && !u.contains("TOTAL") && gst == nil && pst == nil {
                gst = first
            } else if u.contains("SUBTOTAL") || u.contains("SUB TOTAL") || u.contains("SUB-TOTAL") {
                if subtotal == nil { subtotal = first }
            } else if u.contains("TOTAL") || u.contains("AMOUNT DUE") ||
                      u.contains("BALANCE DUE") || u.contains("GRAND TOTAL") {
                // Keep the largest TOTAL match (avoids picking up "SUBTOTAL" line's total)
                if let curr = total { if first > curr { total = first } } else { total = first }
            }
        }

        // Derive missing total (subtotal + gst + pst)
        if total == nil, let s = subtotal {
            let taxSum = (gst ?? 0) + (pst ?? 0)
            total = round((s + taxSum) * 100) / 100
        }
        // Back-calculate 5% GST when only a total is available and no taxes were found
        if let t = total, subtotal == nil, gst == nil, pst == nil {
            let s = round(t / 1.05 * 100) / 100
            let g = round((t - s) * 100) / 100
            subtotal = s
            gst      = g > 0 ? g : nil
        }

        let fmt: (Double?) -> String? = { d in
            guard let d else { return nil }
            return String(format: "%.2f", d)
        }
        return (fmt(total), fmt(subtotal), fmt(gst), fmt(pst))
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
