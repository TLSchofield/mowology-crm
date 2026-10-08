//
//  HeadCardViewModel.swift
//  MowologyCRM
//
//  One department head's card (Charlie, Otto, Mia, Yui) — the phone's version of that
//  head's column on the dashboard Action Board: the same top 3, ranked by Charlie, through
//  the JWT endpoint /api/team/team-mobile. Plus the head's single action:
//    Charlie  Ask box: Tim types a question, Charlie answers from CRM data (daily-capped).
//    Otto     Geocode a property with a visit coming and no map pin (Apple's geocoder on the
//             phone, the pin saved with the web Geocode button's write).
//    Mia      This week's Google post: Post to Google (live mode only), I posted it, Skip —
//             the web card's own post_decide.
//    Yui      Open the client — her replies stay drafts; nothing is sent from the phone.
//  "Open" and "Not now" teach Charlie, exactly as the Action Board's buttons do.
//

import Foundation
import CoreLocation

/// The calls a head card makes. APIClient in the app; a canned stub in previews/renders.
@MainActor
protocol TeamHeadAPI {
    func loadCard(head: String) async throws -> HeadCardResponse
    func send(_ body: [String: Any]) async throws -> HeadActionResponse
    /// Otto's suggestion buttons / auto-log Undo (team-mobile otto_decide | otto_undo_auto).
    func ottoSend(_ body: [String: Any]) async throws -> OttoDecideResponse
}

@MainActor
struct LiveTeamHeadAPI: TeamHeadAPI {
    let client: APIClient

    func loadCard(head: String) async throws -> HeadCardResponse {
        try await client.request(.teamHeadBrief(head: head))
    }

    func send(_ body: [String: Any]) async throws -> HeadActionResponse {
        try await client.request(.teamHeadAction, body: body)
    }

    func ottoSend(_ body: [String: Any]) async throws -> OttoDecideResponse {
        try await client.request(.teamHeadAction, body: body)
    }
}

/// Turns an address into a pin. CLGeocoder in the app; a stub in previews/renders.
protocol AddressGeocoder {
    func coordinate(for address: String) async throws -> CLLocationCoordinate2D
}

struct AppleAddressGeocoder: AddressGeocoder {
    func coordinate(for address: String) async throws -> CLLocationCoordinate2D {
        let places = try await CLGeocoder().geocodeAddressString(address)
        guard let loc = places.first?.location else {
            throw HeadCardViewModel.GeocodeError.notFound
        }
        return loc.coordinate
    }
}

@MainActor
final class HeadCardViewModel: ObservableObject {

    enum GeocodeError: Error { case notFound }

    let head: String

    @Published private(set) var card: HeadCardResponse?
    @Published private(set) var items: [HeadItem] = []
    @Published private(set) var unpinned: [UnpinnedProperty] = []
    @Published private(set) var post: MiaPostDraft?
    @Published private(set) var ask: HeadAskStatus?
    @Published private(set) var isLoading = false
    @Published private(set) var hasLoaded = false
    @Published private(set) var isBusy = false
    @Published var loadError: String?

    /// One outcome line under the section that acted.
    @Published var message: String?
    @Published var messageIsError = false

    // Charlie's Ask box
    @Published var question = ""
    @Published private(set) var answer: String?
    @Published private(set) var askedQuestion: String?
    @Published private(set) var isAsking = false

    // Otto: which property is being looked up, and the ones pinned from here
    @Published private(set) var geocoding: Int?
    @Published private(set) var pinned: [Int: String] = [:]

    // Otto: his suggestions (the web card's list) and the contract visits he logged by himself
    @Published private(set) var ottoItems: [OttoSuggestion] = []
    @Published private(set) var ottoTotal = 0
    @Published private(set) var ottoReviewURL = "/crm/ops/otto-review.php"
    @Published private(set) var autolog: OttoAutoLog?
    /// Auto-log rows undone from this phone (their outcome line stays).
    @Published private(set) var undoneAuto: Set<Int> = []
    /// The suggestion (or auto-log row, negated id) being sent.
    @Published private(set) var ottoBusy: Int?
    /// One outcome line per suggestion / auto-log row (negated id), as the web card's .mw-otto-msg.
    @Published private(set) var ottoNotes: [Int: OttoNote] = [:]

    struct OttoNote: Equatable { let text: String; let isError: Bool }

    private let api: TeamHeadAPI
    private let geocoder: AddressGeocoder

    init(head: String, api: TeamHeadAPI, geocoder: AddressGeocoder = AppleAddressGeocoder()) {
        self.head = head
        self.api = api
        self.geocoder = geocoder
    }

    // MARK: - Load

    func load() async {
        isLoading = true
        loadError = nil
        do {
            let r = try await api.loadCard(head: head)
            if !r.ok {
                loadError = r.error ?? "\(head.capitalized) isn't set up yet."
            } else {
                apply(r)
            }
        } catch let err as APIError {
            loadError = err.errorDescription
        } catch {
            loadError = "Couldn't load the list — pull to try again."
        }
        isLoading = false
        hasLoaded = true
    }

    private func apply(_ r: HeadCardResponse) {
        card = r
        items = r.items
        unpinned = r.unpinned.filter { pinned[$0.id] == nil }
        post = r.post
        ask = r.ask
        ottoItems = r.otto?.items ?? []
        ottoTotal = r.otto?.total ?? 0
        ottoReviewURL = r.otto?.reviewURL ?? "/crm/ops/otto-review.php"
        autolog = r.otto?.autolog
        undoneAuto = []
        ottoNotes = [:]
    }

    var canPublishToGoogle: Bool { card?.googleMode == "live" && (card?.canApprove ?? false) }

    // MARK: - Items (as the Action Board's buttons)

    /// Open: Charlie counts it as "you went to it" (owner only), then the link opens.
    /// A failed save never blocks the link — as the board.
    func open(_ item: HeadItem) async -> URL? {
        if card?.owner == true {
            _ = try? await api.send(["mode": "act", "key": item.key, "what": "open"])
        }
        return item.url.flatMap(Self.webURL)
    }

    /// Not now: back tomorrow (Charlie's snooze), and the row leaves.
    func notNow(_ item: HeadItem) async {
        guard !isBusy else { return }
        guard let r = await perform(["mode": "act", "key": item.key, "what": "snooze"]) else { return }
        items.removeAll { $0.key == item.key }
        show(r.message ?? "OK — I'll bring it back tomorrow.", error: false)
    }

    /// "Move to…" on a customer message: it goes to that head, and the sender + topic is learned
    /// so the next one like it goes there too (POST team-mobile {mode: move}).
    func move(_ item: HeadItem, to head: String) async {
        guard !isBusy else { return }
        guard let r = await perform(["mode": "move", "key": item.key, "to": head]) else { return }
        items.removeAll { $0.key == item.key }
        show(r.message ?? "Moved to \(MoveToMenu.name(head)).", error: false)
    }

    // MARK: - Charlie: Ask

    var askLeftText: String? {
        guard let a = ask else { return nil }
        if !a.ready { return a.cap == 0 ? "Ask needs a server update first." : "Ask isn't switched on yet." }
        return "\(a.left) ask\(a.left == 1 ? "" : "s") left today"
    }

    var canAsk: Bool {
        guard let a = ask, a.ready, a.left > 0, !isAsking else { return false }
        return question.trimmingCharacters(in: .whitespacesAndNewlines).count >= 3
    }

    func submitQuestion() async {
        let q = question.trimmingCharacters(in: .whitespacesAndNewlines)
        guard canAsk else { return }
        isAsking = true
        defer { isAsking = false }
        askedQuestion = q
        answer = nil
        do {
            let r = try await api.send(["mode": "ask", "question": q])
            if let left = r.left, let a = ask {
                ask = HeadAskStatus(ready: a.ready, cap: a.cap, used: max(0, a.cap - left), left: left)
            }
            if r.ok, let text = r.answer, !text.isEmpty {
                answer = text
                question = ""
                message = nil
            } else {
                show(r.message ?? r.error ?? "Charlie couldn't answer that — try again.", error: true)
            }
        } catch let err as APIError {
            show(err.errorDescription ?? "Charlie couldn't answer that — try again.", error: true)
        } catch {
            show("Network error — try again.", error: true)
        }
    }

    // MARK: - Otto: Geocode

    func geocode(_ p: UnpinnedProperty) async {
        guard geocoding == nil, !isBusy else { return }
        let address = p.geocodeAddress.isEmpty ? [p.address, p.city, "Canada"].filter { !$0.isEmpty }.joined(separator: ", ") : p.geocodeAddress
        geocoding = p.id
        defer { geocoding = nil }
        let coord: CLLocationCoordinate2D
        do {
            coord = try await geocoder.coordinate(for: address)
        } catch {
            show("I couldn't find \(p.address) on the map. Check the address in the CRM.", error: true)
            return
        }
        guard let r = await perform(["mode": "geocode", "property_id": p.id, "lat": coord.latitude, "lng": coord.longitude]) else { return }
        _ = r
        pinned[p.id] = p.address
        unpinned.removeAll { $0.id == p.id }
        show("Pinned \(p.address) — I can route there now.", error: false)
    }

    // MARK: - Otto: suggestions

    /// One of Otto's buttons: POST team-mobile {mode: otto_decide, suggestion_id, choice, ...}, exactly
    /// the web card's decide(). On success the row leaves; a returned redirect (the new invoice) opens.
    func ottoDecide(_ s: OttoSuggestion, _ body: [String: Any]) async -> URL? {
        guard ottoBusy == nil else { return nil }
        ottoBusy = s.id
        defer { ottoBusy = nil }
        var b = body
        b["mode"] = "otto_decide"
        b["suggestion_id"] = s.id
        do {
            let r = try await api.ottoSend(b)
            if r.ok {
                ottoItems.removeAll { $0.id == s.id }
                ottoTotal = max(0, ottoTotal - 1)
                show(r.message ?? "Done.", error: false)
                return r.redirect.flatMap(Self.webURL)
            }
            ottoNotes[s.id] = OttoNote(text: r.message ?? r.error ?? "That didn't work.", isError: true)
        } catch let err as APIError {
            ottoNotes[s.id] = OttoNote(text: err.errorDescription ?? "That didn't work.", isError: true)
        } catch {
            ottoNotes[s.id] = OttoNote(text: "No connection. Nothing was changed, try again.", isError: true)
        }
        return nil
    }

    /// A local problem with the inputs (no times, no minutes), shown under that suggestion.
    func ottoSay(_ s: OttoSuggestion, _ text: String) {
        ottoNotes[s.id] = OttoNote(text: text, isError: true)
    }

    /// Undo a contract visit Otto logged by himself: the visit is cancelled and he asks instead.
    func undoAuto(_ row: OttoAutoLogRow) async {
        guard ottoBusy == nil else { return }
        ottoBusy = -row.id
        defer { ottoBusy = nil }
        do {
            let r = try await api.ottoSend(["mode": "otto_undo_auto", "id": row.id])
            ottoNotes[-row.id] = OttoNote(text: r.message ?? r.error ?? (r.ok ? "Undone." : "That didn't work."), isError: !r.ok)
            if r.ok { undoneAuto.insert(row.id) }
        } catch {
            ottoNotes[-row.id] = OttoNote(text: "No connection. Nothing was changed.", isError: true)
        }
    }

    // MARK: - Mia: this week's Google post

    /// what: publish (live mode only) | copied (Tim posted it himself) | dismiss (skip this week).
    func decidePost(_ what: String) async {
        guard let p = post, !isBusy else { return }
        guard let r = await perform(["mode": "gbp_post", "id": p.id, "what": what]) else { return }
        _ = r
        post = nil
        items.removeAll { $0.gbpPostId == p.id }
        switch what {
        case "publish": show("Posted to Google.", error: false)
        case "copied":  show("Noted — posted by you.", error: false)
        default:        show("Skipped this week's post.", error: false)
        }
    }

    // MARK: - Plumbing

    private func perform(_ body: [String: Any]) async -> HeadActionResponse? {
        isBusy = true
        defer { isBusy = false }
        do {
            let r = try await api.send(body)
            if r.ok { return r }
            show(r.message ?? r.error ?? "That didn't work — try again.", error: true)
        } catch let err as APIError {
            show(err.errorDescription ?? "That didn't work — try again.", error: true)
        } catch {
            show("Network error — nothing was changed. Try again.", error: true)
        }
        return nil
    }

    private func show(_ text: String, error: Bool) {
        message = text
        messageIsError = error
    }

    // MARK: - Formatting

    /// A CRM path ("/crm/…") as a full URL for Safari.
    static func webURL(_ path: String) -> URL? {
        if path.hasPrefix("http") { return URL(string: path) }
        return URL(string: "https://mowology.ca" + (path.hasPrefix("/") ? path : "/" + path))
    }

    /// As the board's waited(): "today", "3 days", "2 weeks", "4 months".
    static func waited(_ item: HeadItem, now: Date = Date()) -> String {
        guard let s = item.since ?? item.firstSeen, s.count >= 10 else { return "" }
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        guard let then = f.date(from: String(s.prefix(10))) else { return "" }
        let cal = Calendar.current
        let days = max(0, cal.dateComponents([.day], from: cal.startOfDay(for: then), to: cal.startOfDay(for: now)).day ?? 0)
        if days == 0 { return "today" }
        if days < 14 { return "\(days) day\(days == 1 ? "" : "s")" }
        if days < 60 { return "\(days / 7) weeks" }
        return "\(days / 30) months"
    }

    /// "Oct 9" from "2026-10-09".
    static func shortDate(_ s: String) -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        guard let d = f.date(from: String(s.prefix(10))) else { return s }
        return d.formatted(.dateTime.month(.abbreviated).day())
    }
}
