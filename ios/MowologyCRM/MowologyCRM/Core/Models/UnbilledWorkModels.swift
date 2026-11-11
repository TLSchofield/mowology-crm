//
//  UnbilledWorkModels.swift
//  MowologyCRM
//
//  "Also unbilled at this address" — other unbilled work at the property of the visit being
//  invoiced (POST /api/schedule/invoice, action "unbilled" → UnbilledWorkFinder on the server).
//
//  kind:
//    completed      completed visit nobody invoiced           (pre-ticked when nothing looks off)
//    possibly_done  skipped/scheduled visit with a job timer or Otto evidence that day
//                   (never pre-ticked; only admin/manager may tick — canMarkDone)
//    zero_price     completed visit priced $0                 (needs a price before it can be ticked)
//

import Foundation

struct UnbilledWorkItem: Decodable, Identifiable, Equatable {
    let visitId: Int
    let kind: String
    let badge: String
    let description: String
    let serviceDate: String
    let serviceDayLabel: String
    let amount: Double
    let suggestedAmount: Double?
    let needsPrice: Bool
    let evidence: [String]
    let warnings: [String]
    let preselect: Bool

    var id: Int { visitId }
    var isPossiblyDone: Bool { kind == "possibly_done" }

    enum CodingKeys: String, CodingKey {
        case kind, badge, description, amount, evidence, warnings, preselect
        case visitId         = "visit_id"
        case serviceDate     = "service_date"
        case serviceDayLabel = "service_day_label"
        case suggestedAmount = "suggested_amount"
        case needsPrice      = "needs_price"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        visitId         = (try? c.decode(Int.self, forKey: .visitId)) ?? 0
        kind            = (try? c.decode(String.self, forKey: .kind)) ?? "completed"
        badge           = (try? c.decode(String.self, forKey: .badge)) ?? ""
        description     = (try? c.decode(String.self, forKey: .description)) ?? ""
        serviceDate     = (try? c.decode(String.self, forKey: .serviceDate)) ?? ""
        serviceDayLabel = (try? c.decode(String.self, forKey: .serviceDayLabel)) ?? ""
        amount          = (try? c.decode(Double.self, forKey: .amount)) ?? 0
        suggestedAmount = try? c.decode(Double.self, forKey: .suggestedAmount)
        needsPrice      = (try? c.decode(Bool.self, forKey: .needsPrice)) ?? false
        evidence        = (try? c.decode([String].self, forKey: .evidence)) ?? []
        warnings        = (try? c.decode([String].self, forKey: .warnings)) ?? []
        preselect       = (try? c.decode(Bool.self, forKey: .preselect)) ?? false
    }
}

struct UnbilledWorkHint: Decodable, Identifiable {
    let suggestionId: Int
    let text: String
    var id: Int { suggestionId }

    enum CodingKeys: String, CodingKey {
        case text
        case suggestionId = "suggestion_id"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        suggestionId = (try? c.decode(Int.self, forKey: .suggestionId)) ?? 0
        text         = (try? c.decode(String.self, forKey: .text)) ?? ""
    }
}

/// Response from `action: "unbilled"`.
struct UnbilledWorkResult: Decodable {
    let success: Bool
    let error: String?
    let items: [UnbilledWorkItem]
    let hints: [UnbilledWorkHint]
    let canMarkDone: Bool

    enum CodingKeys: String, CodingKey {
        case success, error, items, hints
        case canMarkDone = "can_mark_done"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success     = (try? c.decode(Bool.self, forKey: .success)) ?? false
        error       = try? c.decode(String.self, forKey: .error)
        items       = (try? c.decode([UnbilledWorkItem].self, forKey: .items)) ?? []
        hints       = (try? c.decode([UnbilledWorkHint].self, forKey: .hints)) ?? []
        canMarkDone = (try? c.decode(Bool.self, forKey: .canMarkDone)) ?? false
    }
}
