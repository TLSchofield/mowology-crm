//
//  YuiJobsViewModel.swift
//  MowologyCRM
//
//  Yui's decision carousel on her Team card: one decision at a time, decided on the card. Jobs come from
//  team-mobile `yui_jobs`; every button posts `yui_act` with the request the card carries and the values of the
//  fields it names (the server re-reads the item, so it never trusts a recipient from here). Nothing leaves the
//  list until the server says ok; a refusal stays on the card. A "who is this?" answer can be undone for 6 seconds.
//

import Foundation

/// The calls the carousel makes. APIClient in the app; a canned stub in the demo/previews.
@MainActor
protocol YuiJobsAPI {
    func yuiJobs() async throws -> YuiJobsResponse
    func yuiAct(request: [String: Any], fields: [String: Any], idempotencyKey: String) async throws -> YuiActResponse
}

extension YuiJobsAPI {
    /// Stubs with no Yui desk.
    func yuiJobs() async throws -> YuiJobsResponse { YuiJobsResponse(ok: true) }
    func yuiAct(request: [String: Any], fields: [String: Any], idempotencyKey: String) async throws -> YuiActResponse {
        YuiActResponse(ok: false, error: "Not available here.")
    }
}

@MainActor
final class YuiJobsViewModel: ObservableObject {

    struct Note: Equatable { let text: String; let isError: Bool }

    struct UndoOffer: Equatable {
        let jobKey: String
        let text: String
        let request: [String: Any]
        static func == (a: UndoOffer, b: UndoOffer) -> Bool { a.jobKey == b.jobKey && a.text == b.text }
    }

    @Published private(set) var jobs: [YuiJob] = []
    /// The card in view (the carousel's scroll position).
    @Published var shownId: String?
    @Published private(set) var busyKey: String?
    /// Idempotency keys for card actions still waiting on the server's answer.
    private var pendingKeys: [String: String] = [:]

    /// What to say when an action got no clear answer: it may have happened, so never "nothing was changed".
    static func failure(_ error: Error) -> String {
        if case APIError.serverError(let m) = error, !m.isEmpty { return m }
        if case APIError.unauthorized = error { return "Sign in again to answer Yui's cards." }
        return "Couldn't confirm that with the server. Check before trying again."
    }
    /// One outcome or refusal line per card.
    @Published private(set) var notes: [String: Note] = [:]
    /// What each card's editable fields hold now, by job key then field name.
    @Published private(set) var values: [String: [String: String]] = [:]
    /// Accounts found by "Another account…", by job key.
    @Published private(set) var foundAccounts: [String: [YuiCandidate]] = [:]
    @Published private(set) var undoOffer: UndoOffer?
    @Published private(set) var drafting: String?
    @Published private(set) var searching = false

    /// How long Undo stays on screen after a who answer.
    static let undoSeconds: UInt64 = 6

    private let api: YuiJobsAPI
    private var undoTask: Task<Void, Never>?
    private var removed: [String: (job: YuiJob, index: Int)] = [:]

    init(api: YuiJobsAPI) { self.api = api }

    // MARK: Reading

    var hasJobs: Bool { !jobs.isEmpty }

    /// 1-based position of the card in view.
    var position: Int {
        guard let id = shownId, let i = jobs.firstIndex(where: { $0.id == id }) else { return 1 }
        return i + 1
    }

    func value(_ job: YuiJob, _ name: String) -> String {
        values[job.key]?[name] ?? job.initialValues[name] ?? ""
    }

    func set(_ job: YuiJob, _ name: String, to text: String) {
        var v = values[job.key] ?? job.initialValues
        v[name] = text
        values[job.key] = v
    }

    /// The account choices on a who card: the server's candidates, then any found by search.
    func accounts(_ job: YuiJob) -> [YuiCandidate] {
        var seen = Set<Int>()
        return ((job.who?.candidates ?? []) + (foundAccounts[job.key] ?? [])).filter { seen.insert($0.clientId).inserted }
    }

    /// Send is off while the note is empty (reply cards), and while a statement has nobody to go to.
    func canSend(_ job: YuiJob) -> Bool {
        switch job.kind {
        case .reply: return !value(job, "body").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        case .statement: return !value(job, "recipient").isEmpty
            && !value(job, "body").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        case .who: return true
        }
    }

    func canAddToAccount(_ job: YuiJob) -> Bool {
        Int(value(job, "client_id")) != nil
            && !value(job, "first_name").trimmingCharacters(in: .whitespaces).isEmpty
    }

    // MARK: Loading

    func load() async {
        do {
            let r = try await api.yuiJobs()
            guard r.ok else { return }   // a failed look keeps what is shown; the card never blanks on a blip
            apply(r.jobs)
        } catch {
            AppDiagnostics.shared.record(category: "YuiJobsViewModel", message: ErrorDetails.describe(error))
        }
    }

    private func apply(_ list: [YuiJob]) {
        jobs = list
        let keys = Set(list.map(\.key))
        values = values.filter { keys.contains($0.key) }
        if shownId == nil || !keys.contains(shownId ?? "") { shownId = list.first?.id }
    }

    // MARK: Acting

    func primary(_ job: YuiJob) async { if let a = job.primary { await act(job, a) } }
    func secondary(_ job: YuiJob) async { if let a = job.secondary { await act(job, a) } }

    private func fieldPayload(_ job: YuiJob) -> [String: Any] {
        var out: [String: Any] = [:]
        for name in job.fields {
            let text = value(job, name)
            if name == "client_id", let n = Int(text) { out[name] = n } else { out[name] = text }
        }
        // A statement send must carry the balance the card showed; the server refuses if it has changed.
        if job.kind == .statement, let s = job.statement {
            let digits = s.closing.filter { "0123456789.-".contains($0) }
            if let n = Double(digits) { out["closing"] = n }
        }
        return out
    }

    private func act(_ job: YuiJob, _ action: YuiAction) async {
        guard busyKey == nil else { return }
        busyKey = job.key
        notes[job.key] = nil
        defer { busyKey = nil }
        do {
            // One key per card action until the server answers: a resend after a lost reply is recognised, never done twice.
            let slot = job.key + "|" + (action.request["mode"] as? String ?? "")
            let idem = pendingKeys[slot] ?? UUID().uuidString
            pendingKeys[slot] = idem
            let r = try await api.yuiAct(request: action.request, fields: fieldPayload(job), idempotencyKey: idem)
            pendingKeys[slot] = nil
            guard r.ok else {
                notes[job.key] = Note(text: r.error ?? r.message ?? "Yui couldn't do that. Nothing was changed.", isError: true)
                return
            }
            finish(job, message: r.message, undoId: r.undoId)
            await load()
        } catch {
            notes[job.key] = Note(text: Self.failure(error), isError: true)
        }
    }

    /// The server said ok: the card leaves; a who answer keeps an Undo for a few seconds.
    private func finish(_ job: YuiJob, message: String?, undoId: String?) {
        let i = jobs.firstIndex(where: { $0.id == job.id }) ?? 0
        removed[job.key] = (job, i)
        jobs.removeAll { $0.id == job.id }
        values[job.key] = nil
        notes[job.key] = nil
        if shownId == job.id { shownId = jobs.isEmpty ? nil : jobs[min(i, jobs.count - 1)].id }
        undoTask?.cancel()
        undoOffer = nil
        if job.kind == .who, var req = job.undo?.request {
            if let undoId, !undoId.isEmpty { req["id"] = Int(undoId) ?? undoId }
            let offer = UndoOffer(jobKey: job.key, text: message ?? "Done.", request: req)
            undoOffer = offer
            undoTask = Task { [weak self] in
                try? await Task.sleep(nanoseconds: Self.undoSeconds * 1_000_000_000)
                guard !Task.isCancelled else { return }
                await MainActor.run { if self?.undoOffer == offer { self?.undoOffer = nil } }
            }
        }
    }

    func undo() async {
        guard let offer = undoOffer, busyKey == nil else { return }
        busyKey = offer.jobKey
        defer { busyKey = nil }
        do {
            let r = try await api.yuiAct(request: offer.request, fields: [:], idempotencyKey: UUID().uuidString)
            if r.ok {
                undoTask?.cancel()
                undoOffer = nil
                await load()
                if let back = removed[offer.jobKey]?.job { shownId = back.id }
            } else {
                notes[offer.jobKey] = Note(text: r.error ?? r.message ?? "Couldn't undo that.", isError: true)
                undoOffer = nil
            }
        } catch {
            notes[offer.jobKey] = Note(text: "Couldn't reach the server to undo.", isError: true)
            undoOffer = nil
        }
    }

    // MARK: Drafting and searching

    /// "Draft with Claude": the server's draft replaces the note on the card (nothing is sent).
    func draft(_ job: YuiJob) async {
        guard let req = job.draftRequest, drafting == nil else { return }
        drafting = job.key
        notes[job.key] = nil
        defer { drafting = nil }
        do {
            let r = try await api.yuiAct(request: req, fields: [:], idempotencyKey: UUID().uuidString)
            if r.ok, let body = r.body, !body.isEmpty {
                set(job, "body", to: body)
            } else {
                notes[job.key] = Note(text: r.error ?? r.message ?? "Claude couldn't draft that one. Write it yourself.", isError: true)
            }
        } catch {
            notes[job.key] = Note(text: "Couldn't reach the server to draft.", isError: true)
        }
    }

    /// "Another account…": active accounts by name; the first one found is picked only when the person taps it.
    func searchAccounts(_ job: YuiJob, query: String) async {
        let q = query.trimmingCharacters(in: .whitespaces)
        guard let req = job.searchRequest, !q.isEmpty else { return }
        searching = true
        defer { searching = false }
        do {
            let r = try await api.yuiAct(request: req, fields: ["q": q], idempotencyKey: UUID().uuidString)
            if r.ok { foundAccounts[job.key] = r.accounts }
            else { notes[job.key] = Note(text: r.error ?? "Couldn't look that up.", isError: true) }
        } catch {
            notes[job.key] = Note(text: "Couldn't reach the server to search.", isError: true)
        }
    }
}
