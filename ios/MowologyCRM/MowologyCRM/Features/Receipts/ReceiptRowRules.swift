//
//  ReceiptRowRules.swift
//  MowologyCRM
//

import Foundation

enum ReceiptRowRules {
    /// A server-provided `needs review` flag is the real fix; this is a narrow
    /// fallback for OCR text that cannot plausibly identify a merchant.
    static func isMerchantDoubtful(_ storedName: String?) -> Bool {
        guard let storedName else { return true }
        let trimmed = storedName.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return true }

        let words = trimmed
            .lowercased()
            .components(separatedBy: CharacterSet.alphanumerics.inverted)
            .filter { !$0.isEmpty }

        if words == ["receipt"] || words == ["thank", "you"] || words == ["unknown", "vendor"] {
            return true
        }
        if words.count == 4,
           words[0] == "page", Int(words[1]) != nil,
           words[2] == "of", Int(words[3]) != nil {
            return true
        }

        return !trimmed.unicodeScalars.contains(where: CharacterSet.letters.contains)
    }
}
