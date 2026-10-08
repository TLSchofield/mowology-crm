//
//  BookkeeperModels.swift
//  MowologyCRM
//
//  Penny's desk — GET/POST /api/expenses/bookkeeper-mobile.
//  Mirrors the web card's queue (BookkeeperDeskService::queue) and duplicate groups
//  (DuplicateReceiptService::groups). Every field is optional and numbers may arrive
//  as strings (PDO returns DECIMAL columns as strings), so nothing here fails a decode.
//

import Foundation

// MARK: - Loose JSON value

/// Any JSON value. Penny's suggestion values are strings, numbers, null, or (line items) arrays.
enum BKValue: Decodable, Equatable {
    case string(String)
    case number(Double)
    case bool(Bool)
    case array([BKValue])
    case object([String: BKValue])
    case null

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if c.decodeNil() { self = .null }
        else if let d = try? c.decode(Double.self) { self = .number(d) }
        else if let b = try? c.decode(Bool.self) { self = .bool(b) }
        else if let s = try? c.decode(String.self) { self = .string(s) }
        else if let a = try? c.decode([BKValue].self) { self = .array(a) }
        else if let o = try? c.decode([String: BKValue].self) { self = .object(o) }
        else { self = .null }
    }

    /// Text form, as the web card would put it in an input. Whole numbers lose the ".0".
    var text: String? {
        switch self {
        case .string(let s): return s
        case .number(let d):
            return d == d.rounded() && abs(d) < 1e12 ? String(Int(d)) : String(d)
        case .bool(let b): return b ? "true" : "false"
        default: return nil
        }
    }

    var double: Double? {
        switch self {
        case .number(let d): return d
        case .string(let s): return Double(s.trimmingCharacters(in: .whitespaces))
        default: return nil
        }
    }

    var int: Int? {
        switch self {
        case .number(let d): return Int(d)
        case .string(let s): return Int(s.trimmingCharacters(in: .whitespaces)) ?? Double(s).map { Int($0) }
        default: return nil
        }
    }
}

// MARK: - Tolerant decode helpers

extension KeyedDecodingContainer {
    func bkInt(_ key: Key) -> Int? {
        (try? decodeIfPresent(BKValue.self, forKey: key))?.int
    }
    func bkDouble(_ key: Key) -> Double? {
        (try? decodeIfPresent(BKValue.self, forKey: key))?.double
    }
    func bkString(_ key: Key) -> String? {
        (try? decodeIfPresent(BKValue.self, forKey: key))?.text
    }
    func bkBool(_ key: Key) -> Bool? {
        guard let v = try? decodeIfPresent(BKValue.self, forKey: key) else { return nil }
        switch v {
        case .bool(let b): return b
        case .number(let d): return d != 0
        case .string(let s): return ["1", "true", "yes"].contains(s.lowercased())
        default: return nil
        }
    }
}

// MARK: - Queue

/// One of Penny's field reads: her value, why, and how sure she is.
struct BKSuggestedField: Decodable, Equatable {
    let value: BKValue
    let reason: String?
    let confidence: String?

    private enum CodingKeys: String, CodingKey { case value, reason, confidence }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        value = (try? c.decodeIfPresent(BKValue.self, forKey: .value)) ?? .null
        reason = c.bkString(.reason)
        confidence = c.bkString(.confidence)
    }

    init(value: BKValue, reason: String?, confidence: String?) {
        self.value = value; self.reason = reason; self.confidence = confidence
    }
}

/// A hard check on the read ("Total = subtotal + GST + PST", "Category: Fuel").
struct BKCheck: Decodable, Identifiable, Equatable {
    let id: UUID
    let ok: Bool
    let message: String

    private enum CodingKeys: String, CodingKey { case ok, message }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = UUID()
        ok = c.bkBool(.ok) ?? false
        message = c.bkString(.message) ?? ""
    }

    init(ok: Bool, message: String) { id = UUID(); self.ok = ok; self.message = message }

    static func == (a: BKCheck, b: BKCheck) -> Bool { a.ok == b.ok && a.message == b.message }
}

struct BookkeeperItem: Decodable, Identifiable, Equatable {
    var id: Int { suggestionId }

    let suggestionId: Int
    let expenseId: Int
    let status: String
    let vendor: String?
    let vendorId: Int?
    let date: String?
    let submittedBy: String?
    let imageURL: String?
    /// field name → Penny's read (vendor, accounting_category, asset_tag, job, subtotal, gst, pst, total, line_items, notes)
    let suggestion: [String: BKSuggestedField]
    let jobTitle: String?
    let checks: [BKCheck]
    /// The owner's saved-but-not-approved values, if any — the form reopens with them.
    var savedDraft: [String: BKValue]?

    private enum CodingKeys: String, CodingKey {
        case suggestionId = "suggestion_id", expenseId = "expense_id", status, vendor
        case vendorId = "vendor_id", date, submittedBy = "submitted_by", imageURL = "image_url"
        case suggestion, jobTitle = "job_title", checks, savedDraft = "saved_draft"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        suggestionId = c.bkInt(.suggestionId) ?? 0
        expenseId = c.bkInt(.expenseId) ?? 0
        status = c.bkString(.status) ?? "draft"
        vendor = c.bkString(.vendor)
        vendorId = c.bkInt(.vendorId)
        date = c.bkString(.date)
        submittedBy = c.bkString(.submittedBy)
        imageURL = c.bkString(.imageURL)
        // A field that isn't {value, reason, confidence} (e.g. an odd shape) is skipped, not fatal.
        var fields: [String: BKSuggestedField] = [:]
        if let raw = try? c.decodeIfPresent([String: BKValue].self, forKey: .suggestion) {
            for (k, v) in raw {
                guard case .object(let o) = v else { continue }
                fields[k] = BKSuggestedField(value: o["value"] ?? .null,
                                             reason: o["reason"]?.text,
                                             confidence: o["confidence"]?.text)
            }
        }
        suggestion = fields
        jobTitle = c.bkString(.jobTitle)
        checks = (try? c.decodeIfPresent([BKCheck].self, forKey: .checks)) ?? []
        if let d = try? c.decodeIfPresent([String: BKValue].self, forKey: .savedDraft) {
            savedDraft = d
        } else {
            savedDraft = nil
        }
    }

    init(suggestionId: Int, expenseId: Int, status: String, vendor: String?, vendorId: Int?, date: String?,
         submittedBy: String?, imageURL: String?, suggestion: [String: BKSuggestedField], jobTitle: String?,
         checks: [BKCheck], savedDraft: [String: BKValue]?) {
        self.suggestionId = suggestionId; self.expenseId = expenseId; self.status = status
        self.vendor = vendor; self.vendorId = vendorId; self.date = date; self.submittedBy = submittedBy
        self.imageURL = imageURL; self.suggestion = suggestion; self.jobTitle = jobTitle
        self.checks = checks; self.savedDraft = savedDraft
    }

    /// Penny's value for a field, as text.
    func suggested(_ field: String) -> String? { suggestion[field]?.value.text }

    /// What the form opens with: the saved draft's value if there is one, else Penny's.
    func current(_ field: String) -> String? {
        if let d = savedDraft, let v = d[field] { return v.text }
        return suggested(field)
    }
}

// MARK: - Duplicates

struct BKDupeMember: Decodable, Identifiable, Equatable {
    let id: Int
    let expenseDate: String?
    let total: Double?
    let status: String?
    let vendorName: String?
    let vendorNameRaw: String?
    let receiptPath: String?
    let submittedBy: String?

    private enum CodingKeys: String, CodingKey {
        case id, expenseDate = "expense_date", total, status, vendorName = "vendor_name"
        case vendorNameRaw = "vendor_name_raw", receiptPath = "receipt_path", submittedBy = "submitted_by"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        expenseDate = c.bkString(.expenseDate)
        total = c.bkDouble(.total)
        status = c.bkString(.status)
        vendorName = c.bkString(.vendorName)
        vendorNameRaw = c.bkString(.vendorNameRaw)
        receiptPath = c.bkString(.receiptPath)
        submittedBy = c.bkString(.submittedBy)
    }

    init(id: Int, expenseDate: String?, total: Double?, status: String?, vendorName: String?,
         receiptPath: String?, submittedBy: String?) {
        self.id = id; self.expenseDate = expenseDate; self.total = total; self.status = status
        self.vendorName = vendorName; self.vendorNameRaw = nil; self.receiptPath = receiptPath
        self.submittedBy = submittedBy
    }

    var displayVendor: String { vendorName ?? vendorNameRaw ?? "Unknown vendor" }
    var isWaiting: Bool { status == "draft" || status == "pending_approval" }
}

struct BKDupeGroup: Decodable, Identifiable, Equatable {
    var id: String { members.map { String($0.id) }.joined(separator: "-") }
    let pairs: [[Int]]
    let members: [BKDupeMember]

    private enum CodingKeys: String, CodingKey { case pairs, members }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        let raw = (try? c.decodeIfPresent([[BKValue]].self, forKey: .pairs)) ?? []
        pairs = raw.map { $0.compactMap { $0.int } }.filter { $0.count == 2 }
        members = (try? c.decodeIfPresent([BKDupeMember].self, forKey: .members)) ?? []
    }

    init(pairs: [[Int]], members: [BKDupeMember]) { self.pairs = pairs; self.members = members }
}

// MARK: - Responses

struct BKTagOption: Decodable, Hashable {
    let value: String
    let label: String
}

struct BookkeeperQueueResponse: Decodable {
    let ok: Bool
    let dupes: [BKDupeGroup]
    let queue: [BookkeeperItem]
    let categories: [String]
    let assetTags: [BKTagOption]
    /// Customer billing mail routed to Penny (admins) — older servers send none.
    let messages: [PennyMessage]
    /// Penny's read-only web-card lines (look-back, payments ↔ invoices, missing receipts) — older servers send none.
    let lines: PennyLines?
    let error: String?

    private enum CodingKeys: String, CodingKey { case ok, dupes, queue, categories, assetTags = "asset_tags", messages, lines, error }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        dupes = (try? c.decodeIfPresent([BKDupeGroup].self, forKey: .dupes)) ?? []
        queue = (try? c.decodeIfPresent([BookkeeperItem].self, forKey: .queue)) ?? []
        categories = (try? c.decodeIfPresent([String].self, forKey: .categories)) ?? []
        assetTags = (try? c.decodeIfPresent([BKTagOption].self, forKey: .assetTags)) ?? BKTagOption.defaults
        messages = (try? c.decodeIfPresent([PennyMessage].self, forKey: .messages)) ?? []
        lines = try? c.decodeIfPresent(PennyLines.self, forKey: .lines)
        error = c.bkString(.error)
    }
}

// MARK: - Penny's read-only lines (bookkeeper-mobile queue → lines; added 2026-10-08)

/// The look-back review, payments matched to invoices, and the missing-receipt chaser — counts only;
/// each one is approved on the web page it links to.
struct PennyLines: Decodable, Equatable {
    struct Lookback: Decodable, Equatable {
        let count: Int
        let amount: Double
        let gst: Double
        let text: String
        let url: String
        private enum CodingKeys: String, CodingKey { case count, amount, gst, text, url }
        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            count = c.bkInt(.count) ?? 0
            amount = c.bkDouble(.amount) ?? 0
            gst = c.bkDouble(.gst) ?? 0
            text = c.bkString(.text) ?? ""
            url = c.bkString(.url) ?? "/crm/accounting/lookback.php"
        }
    }
    struct Payments: Decodable, Equatable {
        let waiting: Int
        let waitingTotal: Double
        let high: Int
        let url: String
        private enum CodingKeys: String, CodingKey { case waiting, high, url, waitingTotal = "waiting_total" }
        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            waiting = c.bkInt(.waiting) ?? 0
            waitingTotal = c.bkDouble(.waitingTotal) ?? 0
            high = c.bkInt(.high) ?? 0
            url = c.bkString(.url) ?? "/crm/accounting/payment-match.php"
        }
    }
    struct Missing: Decodable, Equatable {
        let open: Int
        let openAmount: Double
        let noReceipt: Int
        let noReceiptAmount: Double
        private enum CodingKeys: String, CodingKey {
            case open, openAmount = "open_amount", noReceipt = "no_receipt", noReceiptAmount = "no_receipt_amount"
        }
        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            open = c.bkInt(.open) ?? 0
            openAmount = c.bkDouble(.openAmount) ?? 0
            noReceipt = c.bkInt(.noReceipt) ?? 0
            noReceiptAmount = c.bkDouble(.noReceiptAmount) ?? 0
        }
    }

    let lookback: Lookback?
    let payments: Payments?
    let missing: Missing?

    private enum CodingKeys: String, CodingKey { case lookback, payments, missing }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        lookback = try? c.decodeIfPresent(Lookback.self, forKey: .lookback)
        payments = try? c.decodeIfPresent(Payments.self, forKey: .payments)
        missing = try? c.decodeIfPresent(Missing.self, forKey: .missing)
    }
}

// MARK: - Penny's messages (customer billing mail routed to her — InboundRouteService::forApp)

/// "Vancouver Management wants your direct-deposit details — the form is attached; fill it in and
/// email vidhya@vml.ca." A reminder for Tim: Penny never fills in or sends banking details.
struct PennyMessage: Decodable, Identifiable, Equatable {
    let key: String
    let text: String
    let subject: String
    let at: String
    let emailTo: String?
    let note: String?
    let contactURL: String?
    let attachments: [PennyAttachment]

    var id: String { key }

    private enum CodingKeys: String, CodingKey {
        case key, text, subject, at, note, attachments
        case emailTo = "email_to"
        case contactURL = "contact_url"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = c.bkString(.key) ?? UUID().uuidString
        text = c.bkString(.text) ?? ""
        subject = c.bkString(.subject) ?? ""
        at = c.bkString(.at) ?? ""
        emailTo = c.bkString(.emailTo)
        note = c.bkString(.note)
        contactURL = c.bkString(.contactURL)
        attachments = (try? c.decodeIfPresent([PennyAttachment].self, forKey: .attachments)) ?? []
    }
}

/// A kept attachment with a short-lived signed link (opens in Safari / Quick Look).
struct PennyAttachment: Decodable, Identifiable, Equatable {
    let id: Int
    let filename: String
    let url: String

    private enum CodingKeys: String, CodingKey { case id, filename, url }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        filename = c.bkString(.filename) ?? "attachment.pdf"
        url = c.bkString(.url) ?? ""
    }
}

extension BKTagOption {
    /// The web card's "For" choices, used if the server sends none.
    static let defaults: [BKTagOption] = [
        BKTagOption(value: "none", label: "None"),
        BKTagOption(value: "truck", label: "Truck"),
        BKTagOption(value: "equipment", label: "Equipment"),
        BKTagOption(value: "stock", label: "Shop stock"),
    ]
}

/// decide / reject / not_dupe → {ok, message, approved?, saved_draft?, blocked?}
struct BookkeeperActionResponse: Decodable {
    let ok: Bool
    let message: String?
    let approved: Bool?
    let savedDraft: Bool?
    let blocked: Bool?
    let error: String?

    private enum CodingKeys: String, CodingKey {
        case ok, message, approved, savedDraft = "saved_draft", blocked, error
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        message = c.bkString(.message)
        approved = c.bkBool(.approved)
        savedDraft = c.bkBool(.savedDraft)
        blocked = c.bkBool(.blocked)
        error = c.bkString(.error)
    }
}

// MARK: - Penny's risk explanation (GET /api/expenses/bookkeeper-mobile?mode=risk)

struct ReceiptRiskFlag: Decodable, Identifiable, Equatable {
    var id: String { code }
    let code: String
    let detail: String?
    let penny: String

    private enum CodingKeys: String, CodingKey { case code, detail, penny }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        code = c.bkString(.code) ?? ""
        detail = c.bkString(.detail)
        penny = c.bkString(.penny) ?? (c.bkString(.detail) ?? "")
    }
}

struct ReceiptRiskResponse: Decodable, Equatable {
    let ok: Bool
    let score: Int
    let tier: String
    let summary: String
    let flags: [ReceiptRiskFlag]

    private enum CodingKeys: String, CodingKey { case ok, score, tier, summary, flags }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        score = c.bkInt(.score) ?? 0
        tier = c.bkString(.tier) ?? "none"
        summary = c.bkString(.summary) ?? ""
        flags = (try? c.decodeIfPresent([ReceiptRiskFlag].self, forKey: .flags)) ?? []
    }
}
