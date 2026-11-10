//
//  YuiJobsModels.swift
//  MowologyCRM
//
//  Yui's decision cards — GET /api/team/team-mobile?mode=yui_jobs and POST {mode: "yui_act", request, fields}
//  (YuiJobAdapter on the server). One job = one decision: a statement to send, an unknown sender to place on an
//  account, or a client reply to answer. Every field is optional and numbers may arrive as strings (BK helpers),
//  so nothing fails a decode; a job of a kind this build does not know is dropped.
//

import Foundation

/// A button on a card: its words and the request the server wants posted back, untouched.
struct YuiAction {
    let label: String
    let sub: String
    let request: [String: Any]

    fileprivate init?(_ v: BKValue?, defaultLabel: String = "") {
        guard case .object(let o)? = v else { return nil }
        label = o["label"]?.text ?? defaultLabel
        sub = o["sub"]?.text ?? ""
        if case .object(let r)? = o["request"] { request = r.mapValues(\.anyValue) } else { request = [:] }
    }
}

struct YuiLeft {
    let name: String
    let subject: String
    let text: String
    let at: String
    let addr: String?
    /// "3 earlier from them", from a count or the server's own words.
    let more: String?

    fileprivate init(_ v: BKValue?) {
        let o: [String: BKValue]
        if case .object(let d)? = v { o = d } else { o = [:] }
        name = o["name"]?.text ?? ""
        subject = o["subject"]?.text ?? ""
        text = o["text"]?.text ?? ""
        at = o["at"]?.text ?? ""
        addr = o["addr"]?.text.flatMap { $0.isEmpty ? nil : $0 }
        if let n = o["more"]?.int, n > 0 { more = "\(n) earlier from them" }
        else if let s = o["more"]?.text, !s.isEmpty, Int(s) == nil { more = s }
        else { more = nil }
    }
}

struct YuiRecipient: Identifiable, Hashable {
    let email: String
    let name: String
    let onFile: Bool
    var id: String { email }
}

struct YuiCandidate: Identifiable, Hashable {
    let clientId: Int
    let name: String
    var id: Int { clientId }

    init?(_ v: BKValue) {
        guard case .object(let o) = v, let id = o["client_id"]?.int ?? o["id"]?.int else { return nil }
        clientId = id
        name = o["name"]?.text ?? "Account \(id)"
    }
}

struct YuiStatementRight {
    let account: String
    let label: String
    let closing: String
    let recipients: [YuiRecipient]
    let recipient: String
    let subject: String
    let body: String
}

struct YuiWhoRight {
    let firstName: String
    let lastName: String
    let candidates: [YuiCandidate]
    let clientId: Int?
}

struct YuiReplyRight {
    let toName: String
    let toEmail: String
    let subject: String
    let body: String
    let canDraft: Bool
}

struct YuiJob: Identifiable {
    enum Kind: String { case statement, who, reply }

    let kind: Kind
    let key: String
    let title: String
    let why: String
    let index: Int
    let total: Int
    let left: YuiLeft
    let statement: YuiStatementRight?
    let who: YuiWhoRight?
    let reply: YuiReplyRight?
    let fields: [String]
    let primary: YuiAction?
    let secondary: YuiAction?
    let undo: YuiAction?
    /// who: the request that lists other accounts by name.
    let searchRequest: [String: Any]?
    /// reply: the request that has Claude draft the note.
    let draftRequest: [String: Any]?

    var id: String { key }

    /// What the card starts out holding in each editable field, by the field name the server lists.
    var initialValues: [String: String] {
        var v: [String: String] = [:]
        if let s = statement {
            v["recipient"] = s.recipient; v["subject"] = s.subject; v["body"] = s.body
            v["closing"] = s.closing; v["account"] = s.account
        }
        if let w = who {
            v["first_name"] = w.firstName; v["last_name"] = w.lastName
            v["client_id"] = w.clientId.map(String.init) ?? ""
        }
        if let r = reply { v["subject"] = r.subject; v["body"] = r.body; v["to"] = r.toEmail }
        return v
    }

    fileprivate init?(_ v: BKValue) {
        guard case .object(let o) = v, let k = Kind(rawValue: o["kind"]?.text ?? "") else { return nil }
        kind = k
        key = o["key"]?.text ?? ""
        title = o["title"]?.text ?? ""
        why = o["why"]?.text ?? ""
        index = o["index"]?.int ?? 1
        total = o["total"]?.int ?? 1
        left = YuiLeft(o["left"])
        var r: [String: BKValue] = [:]
        if case .object(let d)? = o["right"] { r = d }
        switch k {
        case .statement:
            var recips: [YuiRecipient] = []
            if case .array(let a)? = r["recipients"] {
                for item in a { if case .object(let x) = item, let e = x["email"]?.text, !e.isEmpty {
                    recips.append(YuiRecipient(email: e, name: x["name"]?.text ?? "", onFile: BKValue.truthy(x["on_file"])))
                } }
            }
            statement = YuiStatementRight(account: r["account"]?.text ?? "", label: r["label"]?.text ?? "",
                                          closing: r["closing"]?.text ?? "", recipients: recips,
                                          recipient: r["recipient"]?.text ?? "", subject: r["subject"]?.text ?? "",
                                          body: r["body"]?.text ?? "")
            who = nil; reply = nil
        case .who:
            var cands: [YuiCandidate] = []
            if case .array(let a)? = r["candidates"] { cands = a.compactMap(YuiCandidate.init) }
            who = YuiWhoRight(firstName: r["first_name"]?.text ?? "", lastName: r["last_name"]?.text ?? "",
                              candidates: cands, clientId: r["client_id"]?.int)
            statement = nil; reply = nil
        case .reply:
            var to: [String: BKValue] = [:]
            if case .object(let d)? = r["to"] { to = d }
            reply = YuiReplyRight(toName: to["name"]?.text ?? "", toEmail: to["email"]?.text ?? "",
                                  subject: r["subject"]?.text ?? "", body: r["body"]?.text ?? "",
                                  canDraft: BKValue.truthy(r["can_draft"]))
            statement = nil; who = nil
        }
        var names: [String] = []
        if case .array(let a)? = o["fields"] { names = a.compactMap(\.text) }
        fields = names
        primary = YuiAction(o["primary"])
        secondary = YuiAction(o["secondary"])
        undo = YuiAction(o["undo"])
        if case .object(let s)? = o["search"] { searchRequest = s.mapValues(\.anyValue) } else { searchRequest = nil }
        if case .object(let d)? = o["draft"] { draftRequest = d.mapValues(\.anyValue) } else { draftRequest = nil }
    }
}

struct YuiJobsResponse: Decodable {
    let ok: Bool
    let error: String?
    let jobs: [YuiJob]

    private enum CodingKeys: String, CodingKey { case ok, error, jobs }

    init(ok: Bool, error: String? = nil, jobs: [YuiJob] = []) { self.ok = ok; self.error = error; self.jobs = jobs }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        error = c.bkString(.error)
        if case .array(let a)? = try? c.decodeIfPresent(BKValue.self, forKey: .jobs) {
            jobs = a.compactMap(YuiJob.init)
        } else { jobs = [] }
    }
}

struct YuiActResponse: Decodable {
    let ok: Bool
    let message: String?
    let error: String?
    let undoId: String?
    let accounts: [YuiCandidate]
    let body: String?

    private enum CodingKeys: String, CodingKey { case ok, message, error, undoId = "undo_id", accounts, body }

    init(ok: Bool, message: String? = nil, error: String? = nil, undoId: String? = nil,
         accounts: [YuiCandidate] = [], body: String? = nil) {
        self.ok = ok; self.message = message; self.error = error; self.undoId = undoId
        self.accounts = accounts; self.body = body
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        message = c.bkString(.message)
        error = c.bkString(.error)
        undoId = c.bkString(.undoId)
        body = c.bkString(.body)
        if case .array(let a)? = try? c.decodeIfPresent(BKValue.self, forKey: .accounts) {
            accounts = a.compactMap(YuiCandidate.init)
        } else { accounts = [] }
    }
}

extension BKValue {
    /// Back to something JSONSerialization can send.
    fileprivate var anyValue: Any {
        switch self {
        case .number(let d): return d == d.rounded() && abs(d) < 1e12 ? Int(d) as Any : d
        case .string(let s): return s
        case .bool(let b): return b
        case .array(let a): return a.map(\.anyValue)
        case .object(let o): return o.mapValues(\.anyValue)
        case .null: return NSNull()
        }
    }

    fileprivate static func truthy(_ v: BKValue?) -> Bool {
        switch v {
        case .bool(let b)?: return b
        case .number(let d)?: return d != 0
        case .string(let s)?: return ["1", "true", "yes"].contains(s.lowercased())
        default: return false
        }
    }
}
