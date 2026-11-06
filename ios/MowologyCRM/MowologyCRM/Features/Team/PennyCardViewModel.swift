//
//  PennyCardViewModel.swift
//  MowologyCRM
//
//  Penny's receipt desk, one receipt at a time — the app's version of the dashboard's
//  bookkeeper card (public/crm/js/bookkeeper-card.js). Same server calls through the
//  JWT endpoint /api/expenses/bookkeeper-mobile: duplicates are settled first, then
//  each prepared receipt is approved (with any edits), saved as a draft, rejected or skipped.
//

import Foundation

/// The two calls Penny's card makes. APIClient in the app; a canned stub in previews/renders.
@MainActor
protocol BookkeeperDeskAPI {
    func loadQueue() async throws -> BookkeeperQueueResponse
    func send(_ body: [String: Any]) async throws -> BookkeeperActionResponse
}

@MainActor
struct LiveBookkeeperDeskAPI: BookkeeperDeskAPI {
    let client: APIClient

    func loadQueue() async throws -> BookkeeperQueueResponse {
        try await client.request(.bookkeeperQueue)
    }

    func send(_ body: [String: Any]) async throws -> BookkeeperActionResponse {
        try await client.request(.bookkeeperAction, body: body)
    }
}

@MainActor
final class PennyCardViewModel: ObservableObject {

    // MARK: - Desk state
    @Published private(set) var queue: [BookkeeperItem] = []
    @Published private(set) var dupes: [BKDupeGroup] = []
    /// Likely cheques for open invoices, shown before the duplicates (Tim, 2026-11-06). "Not now" hides one until the
    /// next open of the card; "Or …" picks another invoice for it (index into [best] + others).
    @Published private(set) var cheques: [BKChequeMatch] = []
    @Published private(set) var chequeSkipped: Set<Int> = []
    @Published private(set) var chequePick: [Int: Int] = [:]
    // MARK: Duplicates carousel (Tim, 2026-11-06: "I should be able to swipe back to 1 and view the merged bill and
    // unmerge it if I want"). The carousel walks every group seen since the card opened. An open group is on the
    // server's list; an answered one keeps its last snapshot and what was decided, so it can be viewed and reversed.

    enum DupeOutcome: Equatable {
        case merged(keep: Int, copies: [Int])
        case different(pairs: [[Int]])
        case elsewhere                      // gone from the list without an answer here (web card, another phone)
    }

    @Published private(set) var dupeSession: [String] = []
    @Published private(set) var dupeSnapshots: [String: BKDupeGroup] = [:]
    @Published private(set) var dupeOutcomes: [String: DupeOutcome] = [:]
    @Published private(set) var dupePos = 0
    @Published private(set) var dupeSwipeForward = true
    /// "On to the receipts" after the last group: the carousel steps aside until a new duplicate turns up.
    @Published private(set) var dupesFinished = false

    var dupeOpenIds: Set<String> { Set(dupes.map(\.id)) }
    var currentDupeId: String? { dupeSession.indices.contains(dupePos) ? dupeSession[dupePos] : nil }
    var currentDupe: BKDupeGroup? {
        guard let id = currentDupeId else { return nil }
        return dupes.first { $0.id == id } ?? dupeSnapshots[id]
    }
    /// nil while the group is open; else what was decided.
    func dupeOutcome(_ id: String) -> DupeOutcome? {
        dupeOpenIds.contains(id) ? nil : (dupeOutcomes[id] ?? .elsewhere)
    }
    var showsDupes: Bool { !dupeSession.isEmpty && !dupesFinished }
    var dupesAllAnswered: Bool { dupes.isEmpty && !dupeSession.isEmpty }

    private func trackDupes() {
        for g in dupes {
            dupeSnapshots[g.id] = g
            dupeOutcomes[g.id] = nil                    // back on the list (Unmerge / Undo): open again
            if !dupeSession.contains(g.id) { dupeSession.append(g.id); dupesFinished = false }
        }
        if dupePos >= dupeSession.count { dupePos = max(0, dupeSession.count - 1) }
    }

    func nextDupe() { move(to: dupePos + 1) }
    func previousDupe() { move(to: dupePos - 1) }
    func showDupe(id: String) { if let i = dupeSession.firstIndex(of: id) { move(to: i) } }
    private func move(to i: Int) {
        guard dupeSession.indices.contains(i), i != dupePos else { return }
        dupeSwipeForward = i > dupePos
        dupePos = i
    }
    /// After an answer: the next open group after this one (wrapping), else stay on the answered card.
    private func advanceToOpen() {
        let n = dupeSession.count
        guard n > 0 else { return }
        for step in 1...n {
            let i = (dupePos + step) % n
            if dupeOpenIds.contains(dupeSession[i]) { move(to: i); return }
        }
    }
    func finishDupes() { dupesFinished = true }

    /// "Keep the other instead": the receipt chosen to stay, per group id (else Penny's pick).
    @Published private(set) var keepChoice: [String: Int] = [:]
    @Published private(set) var categories: [String] = []
    @Published private(set) var tagOptions: [BKTagOption] = BKTagOption.defaults
    @Published private(set) var index = 0
    @Published private(set) var isLoading = false
    @Published private(set) var hasLoaded = false
    @Published private(set) var isBusy = false
    @Published var loadError: String?
    /// The last action's outcome or error, shown inline under the buttons.
    @Published var message: String?
    @Published var messageIsError = false

    // MARK: - Customer billing mail routed to Penny (tasks for Tim — she never answers them)
    @Published private(set) var messages: [PennyMessage] = []
    /// Read-only web-card lines: look-back, deposits matched to invoices, missing receipts.
    @Published private(set) var lines: PennyLines?
    @Published var mailNote: String?
    @Published var mailNoteIsError = false

    // MARK: - Form (the current receipt's editable values)
    @Published var vendor = ""
    @Published var date = Date()
    @Published var category = ""
    @Published var assetTag = "none"
    @Published var subtotal = ""
    @Published var gst = ""
    @Published var pst = ""
    @Published var total = ""
    /// v1 shows the job read-only; its value is sent back unchanged.
    @Published private(set) var jobId: Int?

    private let api: BookkeeperDeskAPI

    init(api: BookkeeperDeskAPI) {
        self.api = api
    }

    var current: BookkeeperItem? { queue.indices.contains(index) ? queue[index] : nil }

    // MARK: - Load

    private var loading: Task<Void, Never>?

    /// One load at a time, in its own task. A second caller waits for the one in flight (opening a head from the
    /// top bar asked twice, and the answers could overwrite each other), and a screen that goes away mid-load no
    /// longer cancels it ("NSURLErrorDomain (-999): cancelled" on Tim's iPhone, 2026-11-04).
    func load() async {
        if let loading { await loading.value; return }
        let task = Task { await runLoad() }
        loading = task
        await task.value
        loading = nil
    }

    private func runLoad() async {
        isLoading = true
        loadError = nil
        do {
            let r = try await api.loadQueue()
            if !r.ok {
                loadError = r.error ?? "Penny queue response ok=false; server returned no error field."
            } else {
                apply(r)
            }
        } catch let err as APIError {
            loadError = err.errorDescription
        } catch {
            AppDiagnostics.shared.record(category: "PennyCardViewModel", message: ErrorDetails.describe(error))
            loadError = ErrorDetails.describe(error)
        }
        isLoading = false
        hasLoaded = true
    }

    private func apply(_ r: BookkeeperQueueResponse) {
        messages = r.messages
        lines = r.lines
        queue = r.queue
        cheques = r.cheques
        dupes = r.dupes
        trackDupes()
        categories = r.categories
        tagOptions = r.assetTags.isEmpty ? BKTagOption.defaults : r.assetTags
        index = 0
        fillForm()
    }

    /// Opens the form on the current receipt: your saved draft if any, else Penny's read.
    private func fillForm() {
        guard let it = current else { return }
        let draftVendor = it.savedDraft?["vendor"]?.text
        vendor = draftVendor ?? it.suggested("vendor") ?? it.vendor ?? ""
        date = Self.parseDate(it.date) ?? Date()
        category = it.current("accounting_category") ?? ""
        let tag = it.current("asset_tag") ?? "none"
        assetTag = tag.isEmpty ? "none" : tag
        subtotal = Self.money(it.current("subtotal"))
        gst = Self.money(it.current("gst"))
        pst = Self.money(it.current("pst"))
        total = Self.money(it.current("total"))
        if let d = it.savedDraft, let j = d["job"] { jobId = j.int } else { jobId = it.suggestion["job"]?.value.int }
    }

    // MARK: - Field helpers for the view

    func confidence(_ field: String) -> String? { current?.suggestion[field]?.confidence }
    func reason(_ field: String) -> String? { current?.suggestion[field]?.reason }
    var notes: String? { current?.suggested("notes").flatMap { $0.isEmpty ? nil : $0 } }

    var jobLabel: String {
        guard let id = jobId, id > 0 else { return "None" }
        if id == current?.suggestion["job"]?.value.int, let t = current?.jobTitle, !t.isEmpty { return t }
        return "Job #\(id)"
    }

    /// Categories for the picker; a stored value outside the list is still shown.
    var categoryChoices: [String] {
        var c = categories
        if !category.isEmpty && !c.contains(category) { c.insert(category, at: 0) }
        return c
    }

    // MARK: - Actions

    func approve() async { await decide(saveDraft: false) }
    func saveDraft() async { await decide(saveDraft: true) }

    func skip() {
        guard !queue.isEmpty else { return }
        index = (index + 1) % queue.count
        message = nil
        fillForm()
    }

    func reject(reason: String) async {
        guard let it = current, !isBusy else { return }
        let why = reason.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !why.isEmpty else {
            show("Say why it's rejected — the person who sent it in sees the reason.", error: true)
            return
        }
        guard let r = await perform(["mode": "reject", "suggestion_id": it.suggestionId, "reason": why]) else { return }
        removeCurrent(r.message ?? "Rejected")
    }

    func notDuplicates(_ group: BKDupeGroup) async {
        guard !isBusy else { return }
        guard let r = await perform(["mode": "not_dupe", "pairs": group.pairs]) else { return }
        await load()
        show(r.message ?? "Got it — they go on for approval.", error: false)
    }

    // MARK: - Duplicates: Merge / Different / Undo (plan 2026-11-05)

    /// The receipt that stays: your "Keep the other instead" choice, else Penny's (server keep_id),
    /// else the same rule on an older server — one in the books, else the first sent in.
    func keepId(for group: BKDupeGroup) -> Int {
        if let c = keepChoice[group.id], group.members.contains(where: { $0.id == c }) { return c }
        return Self.defaultKeep(group)
    }

    nonisolated static func defaultKeep(_ group: BKDupeGroup) -> Int {
        if let k = group.keepId, group.members.contains(where: { $0.id == k }) { return k }
        let sorted = group.members.sorted { $0.id < $1.id }
        return (sorted.first { !$0.isWaiting } ?? sorted.first)?.id ?? 0
    }

    /// Why that one stays, in words: Penny's reason for her pick, "your pick" for a swap.
    func keepLine(for group: BKDupeGroup) -> String {
        let k = keepId(for: group)
        if keepChoice[group.id] == k, k != group.keepId { return "Keeping #\(k) (your pick)" }
        if let r = group.keepReason, k == group.keepId { return "Keeping #\(k) (\(r))" }
        return "Keeping #\(k)"
    }

    /// The receipts Merge sets aside: every other member still waiting.
    func copyIds(for group: BKDupeGroup) -> [Int] { Self.copiesToRemove(keeping: keepId(for: group), in: group) }

    /// Receipts that may be kept instead. Never when one is already in the books (it always stays).
    func swapOptions(for group: BKDupeGroup) -> [Int] { Self.swapOptions(keeping: keepId(for: group), in: group) }

    nonisolated static func swapOptions(keeping keepId: Int, in group: BKDupeGroup) -> [Int] {
        if group.members.contains(where: { !$0.isWaiting }) { return [] }
        return group.members.filter { $0.id != keepId }.map(\.id)
    }

    func chooseKeep(_ id: Int, in group: BKDupeGroup) {
        guard Self.swapOptions(keeping: keepId(for: group), in: group).contains(id) else { return }
        keepChoice[group.id] = id
    }

    // MARK: - Likely cheques (suggest only — Record is the person's tap)

    var openCheques: [BKChequeMatch] { cheques.filter { !chequeSkipped.contains($0.txId) } }
    var currentCheque: BKChequeMatch? { openCheques.first }

    /// The invoice the cheque goes on: Penny's pick, or one the person chose from "Or …".
    struct ChequeTarget: Equatable { let invoiceId: Int; let number: String; let balanceDue: Double; let apply: Double }
    func chequeTargets(_ c: BKChequeMatch) -> [ChequeTarget] {
        [ChequeTarget(invoiceId: c.invoice.id, number: c.invoice.number, balanceDue: c.invoice.balanceDue, apply: c.apply)]
            + c.others.map { ChequeTarget(invoiceId: $0.invoiceId, number: $0.invoiceNumber, balanceDue: $0.balanceDue, apply: $0.apply) }
    }
    func chequeTarget(_ c: BKChequeMatch) -> ChequeTarget {
        let all = chequeTargets(c)
        return all[min(chequePick[c.txId] ?? 0, all.count - 1)]
    }
    func pickCheque(_ c: BKChequeMatch, index: Int) { chequePick[c.txId] = index }
    func skipCheque(_ c: BKChequeMatch) { chequeSkipped.insert(c.txId) }

    func recordCheque(_ c: BKChequeMatch) async {
        guard !isBusy else { return }
        let t = chequeTarget(c)
        guard let r = await perform(["mode": "cheque_record", "transaction_id": c.txId, "invoice_id": t.invoiceId, "amount": t.apply]) else { return }
        chequePick[c.txId] = nil
        await load()
        show(r.message ?? "Recorded on \(t.number).", error: false)
    }

    func merge(_ group: BKDupeGroup) async {
        guard !isBusy else { return }
        let keep = keepId(for: group)
        let copies = copyIds(for: group)
        guard keep > 0, !copies.isEmpty else { return }
        guard let r = await perform(["mode": "merge", "keep_id": keep, "copy_ids": copies]) else { return }
        keepChoice[group.id] = nil
        dupeOutcomes[group.id] = .merged(keep: keep, copies: copies)
        await load()
        show(r.undo == false ? (r.message ?? "Merged into #\(keep).") : "Merged into #\(keep). Swipe back to unmerge.", error: false)
        advanceToOpen()
    }

    /// "Different": two purchases — remembered, both go on for approval.
    func different(_ group: BKDupeGroup) async {
        guard !isBusy else { return }
        guard let r = await perform(["mode": "not_dupe", "pairs": group.pairs]) else { return }
        keepChoice[group.id] = nil
        dupeOutcomes[group.id] = .different(pairs: group.pairs)
        await load()
        show(r.message ?? "Got it — two purchases.", error: false)
        advanceToOpen()
    }

    /// Reverse an answered group from its card: Unmerge (each copy back, the pair asked again, Penny's lesson taken
    /// back) or undo "Different". The group comes back open on this card.
    func reopen(_ id: String) async {
        guard !isBusy, let outcome = dupeOutcome(id) else { return }
        var last: BookkeeperActionResponse?
        switch outcome {
        case .merged(_, let copies):
            for c in copies {
                guard let r = await perform(["mode": "unmerge", "copy_id": c, "kind": "undo"]) else { await load(); return }
                last = r
            }
        case .different(let pairs):
            guard let r = await perform(["mode": "undismiss", "pairs": pairs]) else { return }
            last = r
        case .elsewhere:
            return
        }
        await load()
        show(last?.message ?? "Back to decide again.", error: false)
    }

    /// "Keep this one": every other waiting copy in the group is set aside (rejected
    /// "Duplicate of receipt #N" on the server, kept on record); anything the kept one is
    /// missing — job, category, notes, line items, photo — is taken from them first.
    func keep(_ member: BKDupeMember, in group: BKDupeGroup) async {
        guard !isBusy else { return }
        let removeIds = Self.copiesToRemove(keeping: member.id, in: group)
        guard !removeIds.isEmpty else { return }
        guard let r = await perform(["mode": "remove_dupes", "keep_id": member.id, "remove_ids": removeIds]) else { return }
        await load()
        show(r.message ?? "Kept #\(member.id) — the copies are set aside.", error: false)
    }

    /// The copies "Keep this one" removes: every other member still waiting (an approved
    /// or sent one is never removed).
    nonisolated static func copiesToRemove(keeping keepId: Int, in group: BKDupeGroup) -> [Int] {
        group.members.filter { $0.id != keepId && $0.isWaiting }.map(\.id)
    }

    /// Where "Keep this one" is offered. When a member is already approved/sent it stays
    /// regardless, so only it can be the one kept — keeping a waiting one would leave the pair.
    nonisolated static func canKeep(_ member: BKDupeMember, in group: BKDupeGroup) -> Bool {
        let settled = group.members.contains { !$0.isWaiting }
        return (settled ? !member.isWaiting : true) && !copiesToRemove(keeping: member.id, in: group).isEmpty
    }

    /// The values sent with decide — the same keys the web card's form sends.
    func overrides() -> [String: Any] {
        guard let it = current else { return [:] }
        var o: [String: Any] = [
            "vendor": vendor.trimmingCharacters(in: .whitespacesAndNewlines),
            "accounting_category": category,
            "asset_tag": assetTag,
            "job": jobId.map { String($0) } ?? "",
            // "12,50" from a comma keypad is sent as "12.50" (review round 2).
            "subtotal": ReceiptNumber.normalized(subtotal),
            "gst": ReceiptNumber.normalized(gst),
            "pst": ReceiptNumber.normalized(pst),
            "total": ReceiptNumber.normalized(total),
        ]
        // Keep the vendor record when the name is the one it's already filed under (as the web does).
        if let draftId = it.savedDraft?["vendor_id"]?.int, draftId > 0 {
            o["vendor_id"] = draftId
        } else if vendor == it.vendor, let vid = it.vendorId {
            o["vendor_id"] = vid
        }
        let picked = Self.dayFormatter.string(from: date)
        if picked != String((it.date ?? "").prefix(10)) {
            o["expense_date"] = picked
        }
        return o
    }

    private func decide(saveDraft: Bool) async {
        guard let it = current, !isBusy else { return }
        let values = overrides()
        guard let r = await perform(["mode": "decide", "suggestion_id": it.suggestionId,
                                     "overrides": values, "save_draft": saveDraft]) else { return }
        // The current receipt may have moved while the request was out; find it again.
        guard let i = queue.firstIndex(where: { $0.suggestionId == it.suggestionId }) else { return }
        let draft = values.reduce(into: [String: BKValue]()) { acc, kv in
            if let s = kv.value as? String { acc[kv.key] = .string(s) }
            else if let n = kv.value as? Int { acc[kv.key] = .number(Double(n)) }
        }
        if r.blocked == true {
            // Stays put, so the reason is read on this receipt.
            queue[i].savedDraft = draft
            show(r.message ?? "Approval response blocked=true; server returned no message field. Your edits are saved.", error: true)
        } else if r.savedDraft == true {
            // Come back to it last.
            var item = queue.remove(at: i)
            item.savedDraft = draft
            queue.append(item)
            index = i < queue.count - 1 ? i : 0
            fillForm()
            show(r.message ?? "Saved as a draft", error: false)
        } else {
            index = i
            removeCurrent(r.message ?? "Approved")
        }
    }

    // MARK: - Messages

    /// "Move to…": not Penny's after all. The sender + topic is learned.
    func moveMessage(_ m: PennyMessage, to head: String) async {
        await mailAction(m, ["mode": "move", "key": m.key, "to": head], done: "Given to \(MoveToMenu.name(head)).")
    }

    /// Tim dealt with it (filled in the form and sent it himself): off the list.
    func messageDone(_ m: PennyMessage) async {
        await mailAction(m, ["mode": "message_done", "key": m.key], done: "Done — off the list.")
    }

    private func mailAction(_ m: PennyMessage, _ body: [String: Any], done: String) async {
        guard !isBusy else { return }
        isBusy = true
        defer { isBusy = false }
        do {
            let r = try await api.send(body)
            if r.ok {
                messages.removeAll { $0.key == m.key }
                mailNote = r.message ?? done
                mailNoteIsError = false
            } else {
                mailNote = r.message ?? r.error ?? "Penny action response ok=false; server returned no message/error field."
                mailNoteIsError = true
            }
        } catch {
            AppDiagnostics.shared.record(category: "PennyCardViewModel", message: ErrorDetails.describe(error))
            mailNote = ErrorDetails.describe(error)
            mailNoteIsError = true
        }
    }

    /// Sends one action; returns the response when it worked, else shows why and returns nil.
    private func perform(_ body: [String: Any]) async -> BookkeeperActionResponse? {
        isBusy = true
        message = nil
        defer { isBusy = false }
        do {
            let r = try await api.send(body)
            if r.ok { return r }
            show(r.message ?? r.error ?? "Penny save response ok=false; server returned no message/error field.", error: true)
        } catch let err as APIError {
            show(ErrorDetails.describe(err), error: true)
        } catch {
            AppDiagnostics.shared.record(category: "PennyCardViewModel", message: ErrorDetails.describe(error))
            show(ErrorDetails.describe(error), error: true)
        }
        return nil
    }

    private func removeCurrent(_ text: String) {
        guard queue.indices.contains(index) else { return }
        queue.remove(at: index)
        if index >= queue.count { index = 0 }
        fillForm()
        show(text, error: false)
        if queue.count < 3 && dupes.isEmpty {
            // Top up quietly; Penny may have prepared more in the meantime.
            Task { await refreshQuietly() }
        }
    }

    private func refreshQuietly() async {
        guard let r = try? await api.loadQueue(), r.ok else { return }
        let keepId = current?.suggestionId
        let saved = message
        let savedIsError = messageIsError
        queue = r.queue
        cheques = r.cheques
        dupes = r.dupes
        trackDupes()
        if !r.categories.isEmpty { categories = r.categories }
        index = keepId.flatMap { id in queue.firstIndex { $0.suggestionId == id } } ?? 0
        fillForm()
        message = saved
        messageIsError = savedIsError
    }

    private func show(_ text: String, error: Bool) {
        message = text
        messageIsError = error
    }

    // MARK: - Formatting

    static let dayFormatter: DateFormatter = {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = .current
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()

    static func parseDate(_ s: String?) -> Date? {
        guard let s, s.count >= 10 else { return nil }
        return dayFormatter.date(from: String(s.prefix(10)))
    }

    /// "45.5" → "45.50"; empty or unreadable stays as it is.
    static func money(_ s: String?) -> String {
        guard let s, let d = ReceiptNumber.parse(s) else { return s ?? "" }
        return String(format: "%.2f", d)
    }

    /// The receipt date can't be in the future or more than 7 years back (validDate on the server).
    static var dateRange: ClosedRange<Date> {
        let now = Date()
        let start = Calendar.current.date(byAdding: .year, value: -7, to: now) ?? now
        return start...now
    }
}
