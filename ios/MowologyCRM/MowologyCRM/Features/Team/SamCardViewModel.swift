//
//  SamCardViewModel.swift
//  MowologyCRM
//
//  Sam's sales desk — the app's version of the dashboard's Sam card
//  (public/crm/js/sam-card.js). Same server calls through the JWT endpoint
//  /api/sales/sales-head-mobile: replies waiting (yes first), the follow-up carousel one
//  customer at a time with Sam's editable draft, new leads and Sam's questions.
//  Nothing is sent without Tim's tap; Skip is client-side, as Penny's is.
//

import Foundation

/// The calls Sam's card makes. APIClient in the app; a canned stub in previews/renders.
@MainActor
protocol SalesDeskAPI {
    func loadDesk() async throws -> SalesDeskResponse
    func loadThread(contactId: Int) async throws -> SalesThreadResponse
    func send(_ body: [String: Any]) async throws -> SalesActionResponse
}

@MainActor
struct LiveSalesDeskAPI: SalesDeskAPI {
    let client: APIClient

    func loadDesk() async throws -> SalesDeskResponse {
        try await client.request(.salesDesk)
    }

    func loadThread(contactId: Int) async throws -> SalesThreadResponse {
        try await client.request(.salesThread(contactId: contactId))
    }

    func send(_ body: [String: Any]) async throws -> SalesActionResponse {
        try await client.request(.salesAction, body: body)
    }
}

/// Tim's edits to one follow-up while he types (kept per customer, as the web does).
struct SamEdit: Equatable {
    var channel = "email"
    var subject = ""
    var body = ""
    var sms = ""
    var draftedBy = "template"
    var suggestedBody = ""
    var suggestedSms = ""
}

/// An answer to a waiting reply, once Claude has drafted it.
struct SamReplyDraft: Equatable {
    var subject: String
    var body: String
    var suggestedBody: String
}

@MainActor
final class SamCardViewModel: ObservableObject {

    // MARK: - Desk
    @Published private(set) var name = ""
    @Published private(set) var stats = SalesStats()
    @Published private(set) var queue: [SalesCard] = []
    @Published private(set) var leads: [SalesLead] = []
    @Published private(set) var questions: [SalesQuestion] = []
    @Published private(set) var replies: [SalesReply] = []
    @Published private(set) var texts: SalesTextBridge?
    @Published private(set) var index = 0
    @Published private(set) var isLoading = false
    @Published private(set) var hasLoaded = false
    @Published private(set) var isBusy = false
    @Published var loadError: String?

    /// Outcome lines, one per section, shown under the section that acted.
    @Published var followupMessage: String?
    @Published var followupIsError = false
    @Published var replyMessage: String?
    @Published var replyIsError = false
    @Published var questionMessage: String?

    // MARK: - Editing
    @Published var edits: [String: SamEdit] = [:]
    @Published var replyDrafts: [String: SamReplyDraft] = [:]

    // MARK: - Thread sheet
    @Published var threadTitle = ""
    @Published private(set) var threadMessages: [SalesMessage] = []
    @Published private(set) var threadLoading = false
    @Published var threadError: String?

    private let api: SalesDeskAPI

    init(api: SalesDeskAPI) {
        self.api = api
    }

    var current: SalesCard? { queue.indices.contains(index) ? queue[index] : nil }

    /// Replies with a clear yes first, then newest first (the server's order inside each).
    var sortedReplies: [SalesReply] {
        replies.filter(\.yes) + replies.filter { !$0.yes }
    }

    var queueTotal: Double { queue.reduce(0) { $0 + $1.amount } }

    // MARK: - Load

    func load() async {
        isLoading = true
        loadError = nil
        do {
            let r = try await api.loadDesk()
            if !r.ok {
                loadError = r.error ?? "Sam isn't set up yet."
            } else {
                apply(r)
            }
        } catch let err as APIError {
            loadError = err.errorDescription
        } catch {
            loadError = "Couldn't load Sam's list — pull to try again."
        }
        isLoading = false
        hasLoaded = true
    }

    private func apply(_ r: SalesDeskResponse) {
        name = r.name
        stats = r.stats
        queue = r.queue
        leads = r.leads
        questions = r.questions
        replies = r.unclaimed
        texts = r.texts
        if index >= queue.count { index = 0 }
        let keys = Set(queue.map(\.key))
        edits = edits.filter { keys.contains($0.key) }
    }

    // MARK: - Follow-up carousel

    /// The current card's editable values (Sam's draft until Tim types).
    func edit(for card: SalesCard) -> SamEdit {
        if let e = edits[card.key] { return e }
        return SamEdit(channel: "email", subject: card.draft.subject, body: card.draft.body, sms: card.draft.sms,
                       draftedBy: card.draft.draftedBy, suggestedBody: card.draft.body, suggestedSms: card.draft.sms)
    }

    func update(_ card: SalesCard, _ change: (inout SamEdit) -> Void) {
        var e = edit(for: card)
        change(&e)
        edits[card.key] = e
    }

    func setChannel(_ channel: String) {
        guard let c = current else { return }
        if channel == "sms" && !c.smsOK { return }
        update(c) { $0.channel = channel }
    }

    func smsProblems(for card: SalesCard) -> [String] { SalesSMS.problems(edit(for: card).sms) }

    func move(_ step: Int) {
        guard !queue.isEmpty else { return }
        index = (index + step + queue.count) % queue.count
        followupMessage = nil
    }

    /// Skip: the next customer, nothing recorded (as the web's arrows).
    func skip() { move(1) }

    func sendFollowup() async {
        guard let c = current, !isBusy else { return }
        let e = edit(for: c)
        let sms = e.channel == "sms"
        if sms {
            let p = SalesSMS.problems(e.sms)
            if !p.isEmpty { showFollowup("Text not sent: " + p.joined(separator: " · ") + ".", error: true); return }
        }
        let body: [String: Any] = [
            "mode": "send", "card_key": c.key, "channel": e.channel,
            "subject": sms ? "" : e.subject, "body": sms ? e.sms : e.body,
            "suggested_subject": c.draft.subject, "suggested_body": sms ? e.suggestedSms : e.suggestedBody,
            "template": c.template, "drafted_by": e.draftedBy,
        ]
        guard let r = await perform(body, onError: { self.showFollowup($0, error: true) }) else { return }
        removeCard(c.key, r.message ?? "Sent.")
    }

    /// Not now: Sam leaves them for a week (park skip — recorded, as on the web).
    func notNow() async {
        guard let c = current, !isBusy else { return }
        guard let r = await perform(["mode": "park", "card_key": c.key, "how": "skip", "days": 7],
                                    onError: { self.showFollowup($0, error: true) }) else { return }
        removeCard(c.key, r.message ?? "OK — later.")
    }

    /// The customer wrote back: Claude drafts a reply into the email box (Tim's tap only).
    func draftCardReply() async {
        guard let c = current, c.replied, !isBusy else { return }
        guard let r = await perform(["mode": "draft_reply", "card_key": c.key],
                                    onError: { self.showFollowup($0, error: true) }) else { return }
        guard let text = r.body, !text.isEmpty else { return }
        update(c) {
            $0.channel = "email"; $0.body = text; $0.suggestedBody = text; $0.draftedBy = "claude"
        }
        showFollowup("Here's a draft — read it before you send.", error: false)
    }

    private func removeCard(_ key: String, _ text: String) {
        edits[key] = nil
        if let i = queue.firstIndex(where: { $0.key == key }) {
            queue.remove(at: i)
            if index >= queue.count { index = 0 }
        }
        showFollowup(text, error: false)
    }

    private func showFollowup(_ text: String, error: Bool) {
        followupMessage = text
        followupIsError = error
    }

    // MARK: - Replies waiting

    func draftReply(_ reply: SalesReply) async {
        guard !isBusy else { return }
        guard let r = await perform(["mode": "draft_reply", "reply_key": reply.key],
                                    onError: { self.showReply($0, error: true) }) else { return }
        let text = r.body ?? ""
        replyDrafts[reply.key] = SamReplyDraft(subject: r.subject ?? "Re: your message", body: text, suggestedBody: text)
        showReply("Here's a draft for \(reply.name) — read it before you send.", error: false)
    }

    func sendReply(_ reply: SalesReply) async {
        guard let d = replyDrafts[reply.key], !isBusy else { return }
        guard !d.body.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            showReply("The email is empty.", error: true); return
        }
        let body: [String: Any] = [
            "mode": "send", "reply_key": reply.key, "channel": "email", "subject": d.subject, "body": d.body,
            "suggested_subject": d.subject, "suggested_body": d.suggestedBody, "template": "reply", "drafted_by": "claude",
        ]
        guard let r = await perform(body, onError: { self.showReply($0, error: true) }) else { return }
        removeReply(reply.key, r.message ?? "Sent.")
    }

    func cancelReplyDraft(_ reply: SalesReply) { replyDrafts[reply.key] = nil }

    func handled(_ reply: SalesReply) async {
        guard !isBusy else { return }
        guard let r = await perform(["mode": "handled", "key": reply.key],
                                    onError: { self.showReply($0, error: true) }) else { return }
        removeReply(reply.key, r.message ?? "Marked handled.")
    }

    private func removeReply(_ key: String, _ text: String) {
        replies.removeAll { $0.key == key }
        replyDrafts[key] = nil
        showReply(text, error: false)
    }

    private func showReply(_ text: String, error: Bool) {
        replyMessage = text
        replyIsError = error
    }

    // MARK: - Thread (read-only)

    func openThread(contactId: Int, title: String) async {
        threadTitle = title
        threadMessages = []
        threadError = nil
        threadLoading = true
        defer { threadLoading = false }
        do {
            let r = try await api.loadThread(contactId: contactId)
            if r.ok { threadMessages = r.messages.reversed() }       // oldest first, like a chat
            else { threadError = r.error ?? "Couldn't load the conversation." }
        } catch {
            threadError = "Couldn't load the conversation."
        }
    }

    // MARK: - Questions

    func answer(_ q: SalesQuestion, _ answer: String) async -> URL? {
        guard !isBusy else { return nil }
        guard let r = await perform(["mode": "answer", "question_id": q.id, "answer": answer],
                                    onError: { self.questionMessage = $0 }) else { return nil }
        questions.removeAll { $0.id == q.id }
        questionMessage = r.message
        if answer == "keep" { Task { await self.refreshQuietly() } }
        return r.url.flatMap(Self.webURL)
    }

    // MARK: - Plumbing

    private func perform(_ body: [String: Any], onError: (String) -> Void) async -> SalesActionResponse? {
        isBusy = true
        defer { isBusy = false }
        do {
            let r = try await api.send(body)
            if r.ok { return r }
            onError(r.message ?? r.error ?? "That didn't work — try again.")
        } catch let err as APIError {
            onError(err.errorDescription ?? "That didn't work — try again.")
        } catch {
            onError("Network error — nothing was sent. Try again.")
        }
        return nil
    }

    private func refreshQuietly() async {
        guard let r = try? await api.loadDesk(), r.ok else { return }
        let keep = current?.key
        apply(r)
        index = keep.flatMap { k in queue.firstIndex { $0.key == k } } ?? 0
    }

    // MARK: - Formatting

    /// A CRM path ("/crm/…") as a full URL for Safari.
    static func webURL(_ path: String) -> URL? {
        if path.hasPrefix("http") { return URL(string: path) }
        return URL(string: "https://mowology.ca" + (path.hasPrefix("/") ? path : "/" + path))
    }

    static func money(_ v: Double) -> String {
        let f = NumberFormatter()
        f.numberStyle = .currency
        f.currencyCode = "CAD"
        f.currencySymbol = "$"
        f.maximumFractionDigits = v >= 100 ? 0 : 2
        f.minimumFractionDigits = v >= 100 ? 0 : 2
        return f.string(from: NSNumber(value: v)) ?? "$\(Int(v))"
    }

    private static let stamp: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = .current
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return f
    }()

    static func date(_ s: String?) -> Date? {
        guard let s, !s.isEmpty else { return nil }
        if let d = stamp.date(from: String(s.prefix(19))) { return d }
        return PennyCardViewModel.parseDate(s)
    }

    /// Calendar days, as the web: "today", "yesterday", "3 days ago".
    static func ago(_ s: String?, now: Date = Date()) -> String {
        guard let d = date(s) else { return "" }
        let cal = Calendar.current
        let days = cal.dateComponents([.day], from: cal.startOfDay(for: d), to: cal.startOfDay(for: now)).day ?? 0
        return days <= 0 ? "today" : days == 1 ? "yesterday" : "\(days) days ago"
    }

    static func shortDate(_ s: String?) -> String {
        guard let d = date(s) else { return s ?? "" }
        return d.formatted(.dateTime.month(.abbreviated).day())
    }
}
