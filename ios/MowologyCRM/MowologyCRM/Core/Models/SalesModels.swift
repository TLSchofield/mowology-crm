//
//  SalesModels.swift
//  MowologyCRM
//
//  Sam's desk — GET/POST /api/sales/sales-head-mobile.
//  Mirrors sales-head.php's desk (SalesDeskService::queue/stats/leads/unclaimed,
//  SamFollowupService::draft, SamQuestionService::open). Every field is optional and
//  numbers may arrive as strings, so nothing here fails a decode (BK helpers from
//  BookkeeperModels.swift).
//

import Foundation

// MARK: - Desk

struct SalesStats: Decodable, Equatable {
    var won = 0
    var lost = 0
    var winRate: Int?
    var avgDays: Double?
    var waitingAmount: Double = 0
    var waitingQuotes = 0
    var waitingPeople = 0
    var leadsNew = 0

    private enum CodingKeys: String, CodingKey {
        case won, lost
        case winRate = "win_rate", avgDays = "avg_days", waitingAmount = "waiting_amount"
        case waitingQuotes = "waiting_quotes", waitingPeople = "waiting_people", leadsNew = "leads_new"
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        won = c.bkInt(.won) ?? 0
        lost = c.bkInt(.lost) ?? 0
        winRate = c.bkInt(.winRate)
        avgDays = c.bkDouble(.avgDays)
        waitingAmount = c.bkDouble(.waitingAmount) ?? 0
        waitingQuotes = c.bkInt(.waitingQuotes) ?? 0
        waitingPeople = c.bkInt(.waitingPeople) ?? 0
        leadsNew = c.bkInt(.leadsNew) ?? 0
    }
}

/// One message in a thread — the card's last few (dir/at) or ?mode=thread (direction/sent_at).
struct SalesMessage: Decodable, Identifiable, Equatable {
    let id = UUID()
    let inbound: Bool
    let channel: String
    let subject: String
    let snippet: String
    let at: String?
    /// Someone other than the contact wrote it (joined by quote number / address / company, migration 1314).
    var by: String? = nil

    private enum CodingKeys: String, CodingKey { case dir, direction, channel, subject, snippet, at, sentAt = "sent_at", by, sender }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        inbound = (c.bkString(.dir) ?? c.bkString(.direction)) == "inbound"
        channel = c.bkString(.channel) ?? "email"
        subject = c.bkString(.subject) ?? ""
        snippet = c.bkString(.snippet) ?? ""
        at = c.bkString(.at) ?? c.bkString(.sentAt)
        by = c.bkString(.by) ?? c.bkString(.sender)
    }

    init(inbound: Bool, channel: String = "email", subject: String = "", snippet: String, at: String?) {
        self.inbound = inbound; self.channel = channel; self.subject = subject; self.snippet = snippet; self.at = at
    }

    static func == (a: SalesMessage, b: SalesMessage) -> Bool {
        a.inbound == b.inbound && a.channel == b.channel && a.snippet == b.snippet && a.at == b.at
    }
}

struct SalesQuote: Decodable, Identifiable, Equatable {
    let id: Int
    let number: String
    let title: String
    let service: String
    let address: String
    let amount: Double
    let sentAt: String?
    let validUntil: String?
    let views: Int
    /// "accepted" (green), "declined" (red), "opened" (orange) or "" — QuoteService::colourState().
    let state: String

    private enum CodingKeys: String, CodingKey {
        case id, number, title, service, address, amount, views, state
        case sentAt = "sent_at", validUntil = "valid_until"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        number = c.bkString(.number) ?? ""
        title = c.bkString(.title) ?? ""
        service = c.bkString(.service) ?? ""
        address = c.bkString(.address) ?? ""
        amount = c.bkDouble(.amount) ?? 0
        sentAt = c.bkString(.sentAt)
        validUntil = c.bkString(.validUntil)
        views = c.bkInt(.views) ?? 0
        state = c.bkString(.state) ?? ""
    }
}

/// Sam's suggested follow-up (SamFollowupService::draft()).
struct SalesDraft: Decodable, Equatable {
    var template = "first_nudge"
    var draftedBy = "template"
    var subject = ""
    var body = ""
    var sms = ""

    private enum CodingKeys: String, CodingKey { case template, subject, body, sms, draftedBy = "drafted_by" }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        template = c.bkString(.template) ?? "first_nudge"
        draftedBy = c.bkString(.draftedBy) ?? "template"
        subject = c.bkString(.subject) ?? ""
        body = c.bkString(.body) ?? ""
        sms = c.bkString(.sms) ?? ""
    }
}

/// One waiting customer on the follow-up carousel (SalesDeskService::groupStale()).
struct SalesCard: Decodable, Identifiable, Equatable {
    var id: String { key }

    let key: String
    let kind: String
    let template: String
    let contactId: Int?
    let name: String
    let firstName: String
    let company: String
    let email: String
    let phone: String
    let amount: Double
    let days: Int
    let followups: Int
    let viewed: Bool
    let validUntil: String?
    let lastIn: String?
    /// Set when the last reply came from someone other than the contact (Monica writing about Linda's quote).
    let lastInBy: String?
    let thread: [SalesMessage]
    let quotes: [SalesQuote]
    let draft: SalesDraft
    let smsOK: Bool

    var replied: Bool { kind == "replied" }

    private enum CodingKeys: String, CodingKey {
        case key, kind, template, name, company, email, phone, amount, days, followups, viewed, thread, quotes, draft
        case contactId = "contact_id", firstName = "first_name", validUntil = "valid_until", lastIn = "last_in", lastInBy = "last_in_by", smsOK = "sms_ok"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = c.bkString(.key) ?? ""
        kind = c.bkString(.kind) ?? "stale"
        template = c.bkString(.template) ?? ""
        contactId = c.bkInt(.contactId)
        name = c.bkString(.name) ?? "Customer"
        firstName = c.bkString(.firstName) ?? ""
        company = c.bkString(.company) ?? ""
        email = c.bkString(.email) ?? ""
        phone = c.bkString(.phone) ?? ""
        amount = c.bkDouble(.amount) ?? 0
        days = c.bkInt(.days) ?? 0
        followups = c.bkInt(.followups) ?? 0
        viewed = c.bkBool(.viewed) ?? false
        validUntil = c.bkString(.validUntil)
        lastIn = c.bkString(.lastIn)
        lastInBy = c.bkString(.lastInBy)
        thread = (try? c.decodeIfPresent([SalesMessage].self, forKey: .thread)) ?? []
        quotes = (try? c.decodeIfPresent([SalesQuote].self, forKey: .quotes)) ?? []
        draft = (try? c.decodeIfPresent(SalesDraft.self, forKey: .draft)) ?? SalesDraft()
        smsOK = c.bkBool(.smsOK) ?? false
    }
}

struct SalesLead: Decodable, Identifiable, Equatable {
    let id: Int
    let name: String
    let services: String
    let address: String
    let phone: String
    let tier: String?
    let urgency: String
    let ageDays: Int
    let value: Double
    let hot: Bool
    let next: String
    let url: String

    private enum CodingKeys: String, CodingKey {
        case id, name, services, address, phone, tier, urgency, value, hot, next, url
        case ageDays = "age_days"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        name = c.bkString(.name) ?? "New lead"
        services = c.bkString(.services) ?? ""
        address = c.bkString(.address) ?? ""
        phone = c.bkString(.phone) ?? ""
        tier = c.bkString(.tier)
        urgency = c.bkString(.urgency) ?? ""
        ageDays = c.bkInt(.ageDays) ?? 0
        value = c.bkDouble(.value) ?? 0
        hot = c.bkBool(.hot) ?? false
        next = c.bkString(.next) ?? ""
        url = c.bkString(.url) ?? ""
    }
}

struct SalesQuestion: Decodable, Identifiable, Equatable {
    let id: Int
    let quoteId: Int
    let question: String

    private enum CodingKeys: String, CodingKey { case id, question, quoteId = "quote_id" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        quoteId = c.bkInt(.quoteId) ?? 0
        question = c.bkString(.question) ?? ""
    }
}

/// A customer reply about a quote that nobody answered (UnclaimedReplyService, 'quote' lane).
struct SalesReply: Decodable, Identifiable, Equatable {
    var id: String { key }

    let key: String
    let contactId: Int
    let name: String
    let subject: String
    let quote: String
    let channel: String
    let yes: Bool
    let at: String?
    let url: String

    private enum CodingKeys: String, CodingKey {
        case key, name, subject, quote, channel, yes, at, url
        case contactId = "contact_id"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = c.bkString(.key) ?? ""
        contactId = c.bkInt(.contactId) ?? 0
        name = c.bkString(.name) ?? "A customer"
        subject = c.bkString(.subject) ?? ""
        quote = c.bkString(.quote) ?? ""
        channel = c.bkString(.channel) ?? "email"
        yes = c.bkBool(.yes) ?? false
        at = c.bkString(.at)
        url = c.bkString(.url) ?? ""
    }
}

/// The messages bridge heartbeat (texts from the Mac); nil = not set up.
struct SalesTextBridge: Decodable, Equatable {
    let silent: Bool
    let minutes: Int

    private enum CodingKeys: String, CodingKey { case silent, minutes }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        silent = c.bkBool(.silent) ?? false
        minutes = c.bkInt(.minutes) ?? 0
    }
}

struct SalesDeskResponse: Decodable {
    let ok: Bool
    let error: String?
    let name: String
    let stats: SalesStats
    let queue: [SalesCard]
    let leads: [SalesLead]
    let questions: [SalesQuestion]
    let unclaimed: [SalesReply]
    let texts: SalesTextBridge?

    private enum CodingKeys: String, CodingKey { case ok, error, name, stats, queue, leads, questions, unclaimed, texts }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        error = c.bkString(.error)
        name = c.bkString(.name) ?? ""
        stats = (try? c.decodeIfPresent(SalesStats.self, forKey: .stats)) ?? SalesStats()
        queue = (try? c.decodeIfPresent([SalesCard].self, forKey: .queue)) ?? []
        leads = (try? c.decodeIfPresent([SalesLead].self, forKey: .leads)) ?? []
        questions = (try? c.decodeIfPresent([SalesQuestion].self, forKey: .questions)) ?? []
        unclaimed = (try? c.decodeIfPresent([SalesReply].self, forKey: .unclaimed)) ?? []
        texts = try? c.decodeIfPresent(SalesTextBridge.self, forKey: .texts)
    }
}

struct SalesThreadResponse: Decodable {
    let ok: Bool
    let error: String?
    let messages: [SalesMessage]

    private enum CodingKeys: String, CodingKey { case ok, error, messages }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        error = c.bkString(.error)
        messages = (try? c.decodeIfPresent([SalesMessage].self, forKey: .messages)) ?? []
    }
}

/// send / park / draft_reply / handled / answer. draft_reply adds body (+ subject for a reply).
struct SalesActionResponse: Decodable {
    let ok: Bool
    let message: String?
    let error: String?
    let body: String?
    let subject: String?
    let url: String?

    private enum CodingKeys: String, CodingKey { case ok, message, error, body, subject, url }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        message = c.bkString(.message)
        error = c.bkString(.error)
        body = c.bkString(.body)
        subject = c.bkString(.subject)
        url = c.bkString(.url)
    }
}

// MARK: - SMS rules (CLAUDE.md rule 11 — the same checks as SamFollowupService::smsProblems)

enum SalesSMS {
    static let max = 160

    /// Why a text would be dropped by the carrier gateways (empty = fine).
    static func problems(_ t: String) -> [String] {
        var p: [String] = []
        if t.count > max { p.append("over \(max) characters") }
        let link = #"(?i)https?:|www\.|\b[a-z0-9-]+\.(ca|com|net|org|io|co|info|biz|app|ly|me)\b"#
        if t.range(of: link, options: .regularExpression) != nil { p.append("no links or web addresses") }
        if t.unicodeScalars.contains(where: { !($0.value >= 0x20 && $0.value <= 0x7E) && $0 != "\n" }) {
            p.append("no special characters or emoji")
        }
        if t.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { p.append("empty") }
        return p
    }
}
