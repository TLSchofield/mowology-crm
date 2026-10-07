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

    func load() async {
        isLoading = true
        loadError = nil
        do {
            let r = try await api.loadQueue()
            if !r.ok {
                loadError = r.error ?? "Couldn't load Penny's receipts."
            } else {
                apply(r)
            }
        } catch let err as APIError {
            loadError = err.errorDescription
        } catch {
            loadError = "Couldn't load Penny's receipts."
        }
        isLoading = false
        hasLoaded = true
    }

    private func apply(_ r: BookkeeperQueueResponse) {
        queue = r.queue
        dupes = r.dupes
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
            "subtotal": subtotal.trimmingCharacters(in: .whitespaces),
            "gst": gst.trimmingCharacters(in: .whitespaces),
            "pst": pst.trimmingCharacters(in: .whitespaces),
            "total": total.trimmingCharacters(in: .whitespaces),
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
            show(r.message ?? "Not approved — your edits are saved", error: true)
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

    /// Sends one action; returns the response when it worked, else shows why and returns nil.
    private func perform(_ body: [String: Any]) async -> BookkeeperActionResponse? {
        isBusy = true
        message = nil
        defer { isBusy = false }
        do {
            let r = try await api.send(body)
            if r.ok { return r }
            show(r.message ?? r.error ?? "Couldn't save — try again.", error: true)
        } catch let err as APIError {
            show(err.errorDescription ?? "Couldn't save — try again.", error: true)
        } catch {
            show("Network error — try again.", error: true)
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
        dupes = r.dupes
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
        guard let s, let d = Double(s) else { return s ?? "" }
        return String(format: "%.2f", d)
    }

    /// The receipt date can't be in the future or more than 7 years back (validDate on the server).
    static var dateRange: ClosedRange<Date> {
        let now = Date()
        let start = Calendar.current.date(byAdding: .year, value: -7, to: now) ?? now
        return start...now
    }
}
