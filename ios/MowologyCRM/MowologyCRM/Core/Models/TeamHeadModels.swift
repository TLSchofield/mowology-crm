//
//  TeamHeadModels.swift
//  MowologyCRM
//
//  Charlie, Otto, Mia and Yui's cards — GET/POST /api/team/team-mobile.
//  Mirrors TeamCardService (the dashboard Action Board's column for one head), the head's
//  brain, and each head's one extra: Charlie's Ask status, Otto's unpinned properties,
//  Mia's Google post draft. Every field is optional and numbers may arrive as strings, so
//  nothing here fails a decode (BK helpers from BookkeeperModels.swift).
//

import Foundation

// MARK: - Card

struct HeadCardResponse: Decodable {
    let ok: Bool
    let error: String?
    let head: String
    let name: String
    let role: String
    let faceURL: String?
    let headline: String
    let waiting: Int
    let owner: Bool
    let brain: HeadBrainSummary?
    let items: [HeadItem]
    // Charlie
    let ask: HeadAskStatus?
    // Otto
    let unpinned: [UnpinnedProperty]
    /// Otto's suggestions with their buttons + the contract auto-log (jobs.edit; older servers send none).
    let otto: OttoDesk?
    // Mia
    let post: MiaPostDraft?
    let googleMode: String?
    let canApprove: Bool

    private enum CodingKeys: String, CodingKey {
        case ok, error, head, name, role, headline, waiting, owner, brain, items, ask, unpinned, post, otto
        case faceURL = "face_url", googleMode = "google_mode", canApprove = "can_approve"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        error = c.bkString(.error)
        head = c.bkString(.head) ?? ""
        name = c.bkString(.name) ?? ""
        role = c.bkString(.role) ?? ""
        faceURL = c.bkString(.faceURL)
        headline = c.bkString(.headline) ?? ""
        waiting = c.bkInt(.waiting) ?? 0
        owner = c.bkBool(.owner) ?? false
        brain = try? c.decodeIfPresent(HeadBrainSummary.self, forKey: .brain)
        items = (try? c.decodeIfPresent([HeadItem].self, forKey: .items)) ?? []
        ask = try? c.decodeIfPresent(HeadAskStatus.self, forKey: .ask)
        unpinned = (try? c.decodeIfPresent([UnpinnedProperty].self, forKey: .unpinned)) ?? []
        otto = try? c.decodeIfPresent(OttoDesk.self, forKey: .otto)
        post = try? c.decodeIfPresent(MiaPostDraft.self, forKey: .post)
        googleMode = c.bkString(.googleMode)
        canApprove = c.bkBool(.canApprove) ?? false
    }
}

/// One Action Board row (CharlieRankService item, slimmed).
struct HeadItem: Decodable, Identifiable, Equatable {
    let key: String
    let head: String
    let kind: String
    let text: String
    let url: String?
    let priority: Int
    let since: String?
    let firstSeen: String?
    let yes: Bool

    var id: String { key }

    private enum CodingKeys: String, CodingKey {
        case key, head, kind, text, url, priority, since, yes
        case firstSeen = "first_seen"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = c.bkString(.key) ?? UUID().uuidString
        head = c.bkString(.head) ?? ""
        kind = c.bkString(.kind) ?? ""
        text = c.bkString(.text) ?? ""
        url = c.bkString(.url)
        priority = c.bkInt(.priority) ?? 2
        since = c.bkString(.since)
        firstSeen = c.bkString(.firstSeen)
        yes = c.bkBool(.yes) ?? false
    }

    init(key: String, head: String, kind: String, text: String, url: String?, priority: Int = 2,
         since: String? = nil, firstSeen: String? = nil, yes: Bool = false) {
        self.key = key; self.head = head; self.kind = kind; self.text = text; self.url = url
        self.priority = priority; self.since = since; self.firstSeen = firstSeen; self.yes = yes
    }

    /// Mia's Google post item ("mia:gbp-post:12" → 12).
    var gbpPostId: Int? {
        let prefix = "mia:gbp-post:"
        guard key.hasPrefix(prefix) else { return nil }
        return Int(key.dropFirst(prefix.count))
    }
}

/// HeadBrain, compacted (TeamCardService::brainSummary).
struct HeadBrainSummary: Decodable, Equatable {
    struct Part: Decodable, Equatable, Hashable {
        let label: String
        let tier: String?
        private enum CodingKeys: String, CodingKey { case label, tier }
        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            label = c.bkString(.label) ?? ""
            tier = c.bkString(.tier)
        }
        init(label: String, tier: String?) { self.label = label; self.tier = tier }
    }
    struct Tier: Decodable, Equatable, Hashable {
        let slug: String
        let name: String
        let n: Int
        private enum CodingKeys: String, CodingKey { case slug, name, n }
        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            slug = c.bkString(.slug) ?? ""
            name = c.bkString(.name) ?? ""
            n = c.bkInt(.n) ?? 0
        }
        init(slug: String, name: String, n: Int) { self.slug = slug; self.name = name; self.n = n }
    }

    let units: Int
    let shape: Int
    let since: String?
    let parts: [Part]
    let tiers: [Tier]

    private enum CodingKeys: String, CodingKey { case units, shape, since, parts, tiers }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        units = c.bkInt(.units) ?? 0
        shape = c.bkInt(.shape) ?? 1
        since = c.bkString(.since)
        parts = (try? c.decodeIfPresent([Part].self, forKey: .parts)) ?? []
        tiers = (try? c.decodeIfPresent([Tier].self, forKey: .tiers)) ?? []
    }

    init(units: Int, shape: Int, since: String?, parts: [Part], tiers: [Tier]) {
        self.units = units; self.shape = shape; self.since = since; self.parts = parts; self.tiers = tiers
    }
}

struct HeadAskStatus: Decodable, Equatable {
    let ready: Bool
    let cap: Int
    let used: Int
    let left: Int

    private enum CodingKeys: String, CodingKey { case ready, cap, used, left }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ready = c.bkBool(.ready) ?? false
        cap = c.bkInt(.cap) ?? 0
        used = c.bkInt(.used) ?? 0
        left = c.bkInt(.left) ?? 0
    }

    init(ready: Bool, cap: Int, used: Int, left: Int) {
        self.ready = ready; self.cap = cap; self.used = used; self.left = left
    }
}

/// A property with a visit coming and no map pin (PropertyReadinessService::unpinnedUpcoming).
struct UnpinnedProperty: Decodable, Identifiable, Equatable {
    let id: Int
    let address: String
    let city: String
    let nextVisit: String
    let geocodeAddress: String

    private enum CodingKeys: String, CodingKey {
        case id, address, city
        case nextVisit = "next_visit", geocodeAddress = "geocode_address"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        address = c.bkString(.address) ?? ""
        city = c.bkString(.city) ?? ""
        nextVisit = c.bkString(.nextVisit) ?? ""
        geocodeAddress = c.bkString(.geocodeAddress) ?? ""
    }

    init(id: Int, address: String, city: String, nextVisit: String, geocodeAddress: String) {
        self.id = id; self.address = address; self.city = city; self.nextVisit = nextVisit; self.geocodeAddress = geocodeAddress
    }
}

/// This week's Google Business Profile post draft (MiaChannelsService::openPost).
struct MiaPostDraft: Decodable, Equatable {
    let id: Int
    let title: String
    let body: String
    let photoURL: String?
    let ctaType: String?
    let ctaURL: String?

    private enum CodingKeys: String, CodingKey {
        case id, title, body
        case photoURL = "photo_url", ctaType = "cta_type", ctaURL = "cta_url"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        title = c.bkString(.title) ?? ""
        body = c.bkString(.body) ?? ""
        photoURL = c.bkString(.photoURL)
        ctaType = c.bkString(.ctaType)
        ctaURL = c.bkString(.ctaURL)
    }

    init(id: Int, title: String, body: String, photoURL: String? = nil, ctaType: String? = nil, ctaURL: String? = nil) {
        self.id = id; self.title = title; self.body = body; self.photoURL = photoURL; self.ctaType = ctaType; self.ctaURL = ctaURL
    }
}

// MARK: - Actions

struct HeadActionResponse: Decodable {
    let ok: Bool
    let message: String?
    let error: String?
    let answer: String?
    let source: String?
    let left: Int?
    let status: String?

    private enum CodingKeys: String, CodingKey { case ok, message, error, answer, source, left, status }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        message = c.bkString(.message)
        error = c.bkString(.error)
        answer = c.bkString(.answer)
        source = c.bkString(.source)
        left = c.bkInt(.left)
        status = c.bkString(.status)
    }
}

// MARK: - Otto's suggestions (team-mobile brief → otto; OpsDeskService, as otto.php?mode=suggestions)

/// One of Otto's suggestions and what he proposes. `propose` differs per kind, so it stays loose
/// (BKValue) and the view reads the fields its kind needs — as otto-card.js does.
struct OttoSuggestion: Decodable, Identifiable, Equatable {
    let id: Int
    let key: String
    let kind: String
    let priority: Int
    let text: String
    let detail: String
    let url: String?
    let subjectId: Int
    let propose: [String: BKValue]
    /// pin_off only: where the property's pin is now (propose.lat/lng is where the crews work).
    let pinLat: Double?
    let pinLng: Double?

    private enum CodingKeys: String, CodingKey {
        case id, key, kind, priority, text, detail, url, propose, pin
        case subjectId = "subject_id"
    }
    private enum PinKeys: String, CodingKey { case lat, lng }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        key = c.bkString(.key) ?? ""
        kind = c.bkString(.kind) ?? ""
        priority = c.bkInt(.priority) ?? 2
        text = c.bkString(.text) ?? ""
        detail = c.bkString(.detail) ?? ""
        url = c.bkString(.url)
        subjectId = c.bkInt(.subjectId) ?? 0
        propose = (try? c.decodeIfPresent([String: BKValue].self, forKey: .propose)) ?? [:]
        if let p = try? c.nestedContainer(keyedBy: PinKeys.self, forKey: .pin) {
            pinLat = p.bkDouble(.lat)
            pinLng = p.bkDouble(.lng)
        } else {
            pinLat = nil
            pinLng = nil
        }
    }

    func text(_ k: String) -> String? { propose[k]?.text }
    func int(_ k: String) -> Int? { propose[k]?.int }
    func double(_ k: String) -> Double? { propose[k]?.double }
    func bool(_ k: String) -> Bool {
        switch propose[k] {
        case .bool(let b): return b
        case .number(let d): return d != 0
        case .string(let s): return ["1", "true", "yes"].contains(s.lowercased())
        default: return false
        }
    }
    /// propose[k] as a list of objects (plans, invoices).
    func objects(_ k: String) -> [[String: BKValue]] {
        guard case .array(let a) = propose[k] else { return [] }
        return a.compactMap { if case .object(let o) = $0 { return o } else { return nil } }
    }
}

/// A visit Otto logged by himself at a contract site (otto_auto_visits, migration 1267).
struct OttoAutoLogRow: Decodable, Identifiable, Equatable {
    let id: Int
    let day: String
    let kind: String
    let address: String
    let minutes: Int
    let start: String?
    let end: String?
    let movedFrom: String?

    private enum CodingKeys: String, CodingKey { case id, day, kind, address, minutes, start, end, movedFrom = "moved_from" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        day = c.bkString(.day) ?? ""
        kind = c.bkString(.kind) ?? ""
        address = c.bkString(.address) ?? ""
        minutes = c.bkInt(.minutes) ?? 0
        start = c.bkString(.start)
        end = c.bkString(.end)
        movedFrom = c.bkString(.movedFrom)
    }
}

struct OttoAutoLog: Decodable, Equatable {
    let line: String
    let rows: [OttoAutoLogRow]

    private enum CodingKeys: String, CodingKey { case line, rows }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        line = c.bkString(.line) ?? ""
        rows = (try? c.decodeIfPresent([OttoAutoLogRow].self, forKey: .rows)) ?? []
    }
}

struct OttoDesk: Decodable, Equatable {
    let items: [OttoSuggestion]
    let total: Int
    let autolog: OttoAutoLog?
    let reviewURL: String

    private enum CodingKeys: String, CodingKey { case items, total, autolog, reviewURL = "review_url" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        items = (try? c.decodeIfPresent([OttoSuggestion].self, forKey: .items)) ?? []
        total = c.bkInt(.total) ?? 0
        autolog = try? c.decodeIfPresent(OttoAutoLog.self, forKey: .autolog)
        reviewURL = c.bkString(.reviewURL) ?? "/crm/ops/otto-review.php"
    }
}

/// POST team-mobile {mode: otto_decide | otto_undo_auto} → OttoActionService / OttoContractLogService.
struct OttoDecideResponse: Decodable {
    let ok: Bool
    let message: String?
    let error: String?
    let redirect: String?

    private enum CodingKeys: String, CodingKey { case ok, message, error, redirect }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        message = c.bkString(.message)
        error = c.bkString(.error)
        redirect = c.bkString(.redirect)
    }
}
