//
//  VisitWorkModels.swift
//  MowologyCRM
//
//  Proof-of-work data for a visit — GET/POST /api/schedule/visit-work.
//  Every write returns the full refreshed state, so one response type covers all calls.
//

import Foundation

struct VisitWorkState: Decodable {
    let success: Bool
    let visitId: Int?
    /// Locked by the office — read-only for crew.
    let locked: Bool?
    let checklist: [ChecklistItem]?
    let materials: [MaterialItem]?
    let notes: [VisitNote]?
    let message: String?

    enum CodingKeys: String, CodingKey {
        case success
        case visitId = "visit_id"
        case locked, checklist, materials, notes, message
    }
}

struct ChecklistItem: Decodable, Identifiable, Equatable {
    /// Local identity only — never sent or decoded (rows have no server id).
    var id = UUID()
    var item: String
    var checked: Bool
    var note: String

    enum CodingKeys: String, CodingKey { case item, checked, note }

    init(item: String, checked: Bool = false, note: String = "") {
        self.item = item; self.checked = checked; self.note = note
    }

    var payload: [String: Any] { ["item": item, "checked": checked, "note": note] }

    static func == (a: Self, b: Self) -> Bool {
        a.item == b.item && a.checked == b.checked && a.note == b.note
    }
}

struct MaterialItem: Decodable, Identifiable, Equatable {
    /// Local identity only — never sent or decoded (rows have no server id).
    var id = UUID()
    var name: String
    var qty: Double?
    var unit: String
    var ratePerUnit: Double?
    var note: String

    enum CodingKeys: String, CodingKey {
        case name, qty, unit, note
        case ratePerUnit = "rate_per_unit"
    }

    init(name: String, qty: Double?, unit: String, ratePerUnit: Double? = nil, note: String = "") {
        self.name = name; self.qty = qty; self.unit = unit; self.ratePerUnit = ratePerUnit; self.note = note
    }

    var payload: [String: Any] {
        var d: [String: Any] = ["name": name, "unit": unit, "note": note]
        if let qty { d["qty"] = qty }
        // Preserve a rate the office set on the web — the app never edits it.
        if let ratePerUnit { d["rate_per_unit"] = ratePerUnit }
        return d
    }

    var quantityLabel: String {
        guard let qty else { return unit }
        let n = qty == qty.rounded() ? String(Int(qty)) : String(qty)
        return unit.isEmpty ? n : "\(n) \(unit)"
    }

    static func == (a: Self, b: Self) -> Bool {
        a.name == b.name && a.qty == b.qty && a.unit == b.unit && a.ratePerUnit == b.ratePerUnit && a.note == b.note
    }
}

struct VisitNote: Decodable, Identifiable {
    let id: Int
    let noteType: String
    let content: String
    let visibleToCustomer: Bool
    let createdAt: String
    let author: String?

    enum CodingKeys: String, CodingKey {
        case id, content, author
        case noteType          = "note_type"
        case visibleToCustomer = "visible_to_customer"
        case createdAt         = "created_at"
    }
}

/// Note categories the server accepts (visit_notes.note_type).
enum VisitNoteType: String, CaseIterable, Identifiable {
    case general
    case issue
    case customerRequest = "customer_request"
    case followUp        = "follow_up"
    case `internal`

    var id: String { rawValue }

    var label: String {
        switch self {
        case .general:         return "General"
        case .issue:           return "Issue"
        case .customerRequest: return "Client request"
        case .followUp:        return "Follow-up"
        case .internal:        return "Internal"
        }
    }

    static func label(for raw: String) -> String {
        VisitNoteType(rawValue: raw)?.label ?? "General"
    }
}
