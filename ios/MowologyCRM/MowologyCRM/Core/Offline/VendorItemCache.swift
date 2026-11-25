//
//  VendorItemCache.swift
//  MowologyCRM
//
//  A supplier's learned item names, kept on the phone for receipt correction in the field.
//

import Foundation

enum VendorItemSource: String, Codable {
    case usual
    case catalogue
}

struct VendorItemSuggestion: Codable, Equatable, Identifiable {
    let name: String
    let source: VendorItemSource
    let useCount: Int
    let confirmed: Bool
    let lastDate: String?
    let suggestedTotal: Double?
    let lastQuantity: Double?

    var id: String { "\(source.rawValue):\(name.folding(options: [.caseInsensitive, .diacriticInsensitive], locale: .current))" }
}

/// Deterministic cache and picker rules. Kept free of disk, network and actor state for Mac-side tests.
enum VendorItemRules {
    static let rowLimit = 60
    static let maxAge: TimeInterval = 24 * 60 * 60

    static func isExpired(savedAt: Date, now: Date) -> Bool {
        savedAt > now || now.timeIntervalSince(savedAt) >= maxAge
    }

    /// The server has already rejected OCR junk. The phone only merges duplicates and preserves
    /// the server's useful ordering: most-used learned lines first, then the catalogue.
    static func merge(usual: [VendorItemSuggestion], catalogue: [VendorItemSuggestion]) -> [VendorItemSuggestion] {
        let rankedUsual = usual.sorted {
            if $0.useCount != $1.useCount { return $0.useCount > $1.useCount }
            if $0.confirmed != $1.confirmed { return $0.confirmed && !$1.confirmed }
            return ($0.lastDate ?? "") > ($1.lastDate ?? "")
        }
        var names = Set<String>()
        var result: [VendorItemSuggestion] = []
        for item in rankedUsual + catalogue {
            let key = normalized(item.name)
            guard !key.isEmpty, names.insert(key).inserted else { continue }
            result.append(item)
            if result.count == rowLimit { break }
        }
        return result
    }

    static func filter(_ items: [VendorItemSuggestion], query: String) -> [VendorItemSuggestion] {
        let needle = normalized(query)
        guard !needle.isEmpty else { return items }
        return items.enumerated()
            .filter { normalized($0.element.name).contains(needle) }
            .sorted {
                let a = normalized($0.element.name).hasPrefix(needle)
                let b = normalized($1.element.name).hasPrefix(needle)
                return a == b ? $0.offset < $1.offset : a
            }
            .map(\.element)
    }

    /// An existing OCR value is editable text, not a search the user asked for. Keep the
    /// supplier's full list visible until the user changes that text.
    static func pickerQuery(name: String, hasEditedName: Bool) -> String {
        hasEditedName ? name : ""
    }

    static func recentVendorIDs(
        receipts: [(vendorId: Int?, expenseDate: String)],
        now: Date,
        calendar: Calendar = Calendar(identifier: .gregorian)
    ) -> Set<Int> {
        guard let cutoff = calendar.date(byAdding: .day, value: -90, to: now) else { return [] }
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.calendar = calendar
        formatter.dateFormat = "yyyy-MM-dd"
        return Set(receipts.compactMap { receipt in
            guard let vendorId = receipt.vendorId,
                  let date = formatter.date(from: receipt.expenseDate),
                  date >= cutoff else { return nil }
            return vendorId
        })
    }

    private static func normalized(_ text: String) -> String {
        text.trimmingCharacters(in: .whitespacesAndNewlines)
            .folding(options: [.caseInsensitive, .diacriticInsensitive], locale: .current)
    }
}

#if !VENDOR_ITEM_RULES_ONLY
private struct VendorItemResponse: Decodable {
    let success: Bool
    let usual: [VendorItemWire]
    let catalogue: [VendorItemWire]
}

/// Fields returned by ExpenseLookupService::vendorItemSuggestions. Values from PDO may be strings.
private struct VendorItemWire: Decodable {
    let name: String
    let n: Int?
    let confirmed: Bool?
    let lastDate: String?
    let lastTotal: Double?
    let lastQty: Double?
    let price: Double?

    private enum CodingKeys: String, CodingKey {
        case name, n, confirmed, price
        case lastDate = "last_date"
        case lastTotal = "last_total"
        case lastQty = "last_qty"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        n = Self.int(c, .n)
        confirmed = Self.bool(c, .confirmed)
        lastDate = try? c.decodeIfPresent(String.self, forKey: .lastDate)
        lastTotal = Self.double(c, .lastTotal)
        lastQty = Self.double(c, .lastQty)
        price = Self.double(c, .price)
    }

    private static func double(_ c: KeyedDecodingContainer<CodingKeys>, _ key: CodingKeys) -> Double? {
        if let value = try? c.decodeIfPresent(Double.self, forKey: key) { return value }
        if let value = try? c.decodeIfPresent(String.self, forKey: key) { return Double(value) }
        return nil
    }

    private static func int(_ c: KeyedDecodingContainer<CodingKeys>, _ key: CodingKeys) -> Int? {
        if let value = try? c.decodeIfPresent(Int.self, forKey: key) { return value }
        if let value = try? c.decodeIfPresent(String.self, forKey: key) { return Int(value) }
        return nil
    }

    private static func bool(_ c: KeyedDecodingContainer<CodingKeys>, _ key: CodingKeys) -> Bool? {
        if let value = try? c.decodeIfPresent(Bool.self, forKey: key) { return value }
        if let value = int(c, key) { return value != 0 }
        return nil
    }

    func suggestion(source: VendorItemSource) -> VendorItemSuggestion {
        VendorItemSuggestion(name: name, source: source, useCount: n ?? 0, confirmed: confirmed ?? false,
                             lastDate: lastDate, suggestedTotal: lastTotal ?? price, lastQuantity: lastQty)
    }
}

@MainActor
final class VendorItemCache: ObservableObject {
    static let shared = VendorItemCache()

    private struct Entry: Codable {
        let host: String
        let vendorId: Int
        let savedAt: Date
        let items: [VendorItemSuggestion]
    }

    private struct Saved: Codable { var entries: [Entry] }

    @Published private(set) var revision = 0
    private var entries: [String: Entry] = [:]
    private var loaded = false
    private static var fileURL: URL { QueueStorage.directory.appendingPathComponent("vendor-items.json") }

    private init() {}

    func items(vendorId: Int, host: String = Server.base.host ?? "") -> [VendorItemSuggestion] {
        loadIfNeeded()
        return entries[key(host: host, vendorId: vendorId)]?.items ?? []
    }

    /// Returns the phone's copy immediately and refreshes it only when absent or a day old.
    func load(vendorId: Int, using apiClient: APIClient, now: Date = Date()) async -> [VendorItemSuggestion] {
        loadIfNeeded()
        let host = Server.base.host ?? ""
        let cacheKey = key(host: host, vendorId: vendorId)
        if let entry = entries[cacheKey], !VendorItemRules.isExpired(savedAt: entry.savedAt, now: now) {
            return entry.items
        }
        do {
            let response: VendorItemResponse = try await apiClient.request(.vendorItems(vendorId: vendorId))
            guard response.success else { return entries[cacheKey]?.items ?? [] }
            let merged = VendorItemRules.merge(
                usual: response.usual.map { $0.suggestion(source: .usual) },
                catalogue: response.catalogue.map { $0.suggestion(source: .catalogue) }
            )
            entries[cacheKey] = Entry(host: host, vendorId: vendorId, savedAt: now, items: merged)
            persist()
            revision &+= 1
            return merged
        } catch {
            AppDiagnostics.shared.record(category: "VendorItemCache", message: ErrorDetails.describe(error))
            return entries[cacheKey]?.items ?? []
        }
    }

    func clear() {
        entries = [:]
        loaded = true
        revision &+= 1
        try? FileManager.default.removeItem(at: Self.fileURL)
    }

    private func key(host: String, vendorId: Int) -> String { "\(host.lowercased())|\(vendorId)" }

    private func loadIfNeeded() {
        guard !loaded else { return }
        loaded = true
        guard let data = try? Data(contentsOf: Self.fileURL),
              let saved = try? JSONDecoder().decode(Saved.self, from: data) else { return }
        entries = Dictionary(uniqueKeysWithValues: saved.entries.map { (key(host: $0.host, vendorId: $0.vendorId), $0) })
    }

    private func persist() {
        guard let data = try? JSONEncoder().encode(Saved(entries: Array(entries.values))) else { return }
        try? data.write(to: Self.fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
    }
}
#endif
