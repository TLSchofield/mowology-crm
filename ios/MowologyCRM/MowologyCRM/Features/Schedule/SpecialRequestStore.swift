//
//  SpecialRequestStore.swift
//  MowologyCRM
//
//  A client's special request on a visit ("run the mower over the front lawns … and rake the
//  leaves in the NW corner"), attached by the office from Yui's / Otto's card.
//  Loaded per visit screen from GET /api/schedule/special-requests?mode=visits&ids=…; empty
//  (enabled:false) while ops_settings.special_requests_enabled is off, so nothing shows.
//
//  The crew must tap "Got it" before Start / any photo on that visit — VisitDetailView asks
//  firstUnread(for:) synchronously at the top of those buttons and shows the request instead.
//  The server enforces the same rule (409), so this is the friendly half of the gate.
//

import Foundation

struct SpecialRequestAck: Decodable, Hashable {
    let userId: Int
    let name: String

    enum CodingKeys: String, CodingKey {
        case userId = "user_id"
        case name
    }
}

struct SpecialRequestOutcome: Decodable, Hashable {
    let status: String
    let reason: String?
    let extraDescription: String?
    let extraMinutes: Int?
    let byName: String?

    enum CodingKeys: String, CodingKey {
        case status, reason
        case extraDescription = "extra_description"
        case extraMinutes     = "extra_minutes"
        case byName           = "by_name"
    }
}

struct SpecialRequest: Decodable, Identifiable, Hashable {
    let requestVisitId: Int
    let requestId: Int
    let visitId: Int
    let head: String
    let headName: String
    let headRole: String
    let fromName: String?
    let companyName: String?
    let clientWords: String
    let included: [String]
    let extra: [String]
    let summary: String
    let address: String
    let status: String
    var ackedByMe: Bool
    let acks: [SpecialRequestAck]
    let outcome: SpecialRequestOutcome?

    var id: Int { requestVisitId }
    var isOpen: Bool { status == "attached" }
    var needsMyAck: Bool { isOpen && !ackedByMe }

    var fromLine: String {
        let who = [fromName, companyName].compactMap { ($0?.isEmpty ?? true) ? nil : $0 }.joined(separator: " · ")
        return who.isEmpty ? "From the office" : "From \(who)"
    }

    /// The head who raised it, for HeadFaceView (yui | otto).
    var headSlug: String { head == "yui" ? "yui" : "otto" }
    var headLine: String { "\(headName) · \(headRole)" }

    enum CodingKeys: String, CodingKey {
        case requestVisitId = "request_visit_id"
        case requestId      = "request_id"
        case visitId        = "visit_id"
        case head
        case headName       = "head_name"
        case headRole       = "head_role"
        case fromName       = "from_name"
        case companyName    = "company_name"
        case clientWords    = "client_words"
        case included, extra, summary, address, status
        case ackedByMe      = "acked_by_me"
        case acks, outcome
    }
}

struct SpecialRequestsResponse: Decodable {
    let success: Bool
    let enabled: Bool
    let requests: [String: [SpecialRequest]]
}

struct SpecialRequestActionResponse: Decodable {
    let success: Bool
    let request: SpecialRequest?
}

@MainActor
final class SpecialRequestStore: ObservableObject {

    @Published private(set) var byVisit: [Int: [SpecialRequest]] = [:]
    @Published var errorMessage: String?
    @Published private(set) var savingId: Int?

    private let visitIds: [Int]
    private let apiClient: APIClient

    init(visitIds: [Int], apiClient: APIClient) {
        self.visitIds = visitIds
        self.apiClient = apiClient
    }

    /// Silent on failure: offline just means nothing to show (the server still guards Start).
    func load() async {
        guard !visitIds.isEmpty else { return }
        await SpecialRequestOutbox.shared.drain(using: apiClient)
        guard let r: SpecialRequestsResponse = try? await apiClient.request(.scheduleSpecialRequests(visitIds: visitIds)) else { return }
        var map: [Int: [SpecialRequest]] = [:]
        if r.enabled {
            for (key, list) in r.requests {
                if let vid = Int(key) { map[vid] = list }
            }
        }
        // A "Got it" tapped with no signal is still read — keep it read after a reload.
        let me = apiClient.currentUserId
        for vid in map.keys {
            map[vid] = map[vid]?.map { r in
                var c = r
                if SpecialRequestOutbox.shared.hasPendingAck(requestVisitId: r.requestVisitId, userId: me) { c.ackedByMe = true }
                return c
            }
        }
        byVisit = map
        // Keep the heads' faces on disk now, so the read-it-first screen shows them offline.
        for slug in Set(map.values.flatMap { $0 }.map(\.headSlug)) {
            _ = await HeadFaceCache.shared.load(slug)
        }
    }

    func requests(for visitId: Int) -> [SpecialRequest] { byVisit[visitId] ?? [] }

    /// The request this person still has to read before Start / photos on this visit.
    func firstUnread(for visitId: Int) -> SpecialRequest? {
        requests(for: visitId).first { $0.needsMyAck }
    }

    /// "Got it". With no signal it is remembered on this screen so the crew are never trapped
    /// (a start made offline is queued and accepted by the server).
    func ack(_ request: SpecialRequest) async {
        savingId = request.id
        defer { savingId = nil }
        do {
            let r: SpecialRequestActionResponse = try await apiClient.request(
                .scheduleSpecialRequestAction,
                body: ["mode": "ack", "request_visit_id": request.requestVisitId]
            )
            if let updated = r.request { replace(updated) } else { markAcked(request) }
        } catch {
            markAcked(request)
            if case APIError.networkError = error {
                // Kept on the phone and sent when signal returns, so the office sees it was read.
                SpecialRequestOutbox.shared.enqueue(
                    userId: apiClient.currentUserId,
                    body: ["mode": "ack", "request_visit_id": request.requestVisitId]
                )
                errorMessage = "No signal — marked as read; it will reach the office when you're back in range."
            }
        }
    }

    /// done / not_done (reason) / extra_done (what + minutes → the visit's extras).
    func answer(_ request: SpecialRequest, outcome: String, reason: String = "",
                extraDescription: String = "", extraMinutes: Int = 0) async -> Bool {
        savingId = request.id
        defer { savingId = nil }
        do {
            let r: SpecialRequestActionResponse = try await apiClient.request(
                .scheduleSpecialRequestAction,
                body: [
                    "mode": "outcome", "request_visit_id": request.requestVisitId, "outcome": outcome,
                    "reason": reason, "extra_description": extraDescription, "extra_minutes": extraMinutes,
                ]
            )
            if let updated = r.request { replace(updated) }
            errorMessage = nil
            return true
        } catch APIError.networkError {
            // No signal: the answer is kept on this phone and sent later, never lost.
            SpecialRequestOutbox.shared.enqueue(
                userId: apiClient.currentUserId,
                body: [
                    "mode": "outcome", "request_visit_id": request.requestVisitId, "outcome": outcome,
                    "reason": reason, "extra_description": extraDescription, "extra_minutes": extraMinutes,
                ]
            )
            errorMessage = "No signal — answer saved on this phone; it will be sent when you're back in range."
            return true
        } catch {
            errorMessage = (error as? APIError)?.localizedDescription ?? error.localizedDescription
            return false
        }
    }

    private func markAcked(_ request: SpecialRequest) {
        var copy = request
        copy.ackedByMe = true
        replace(copy)
    }

    private func replace(_ request: SpecialRequest) {
        var list = byVisit[request.visitId] ?? []
        if let i = list.firstIndex(where: { $0.id == request.id }) { list[i] = request } else { list.append(request) }
        byVisit[request.visitId] = list
    }
}

// MARK: - Offline outbox

/// "Got it" taps and answers made with no signal (2026-10-12: they were held only on screen and
/// lost). Kept on disk per person, sent oldest-first by the photo drain on reconnect/foreground
/// and before each load. A 4xx refusal (request gone, switched off) drops the entry.
@MainActor
final class SpecialRequestOutbox {

    static let shared = SpecialRequestOutbox()

    struct Entry: Codable {
        let id: String
        let userId: Int?
        let bodyJSON: Data
        let requestVisitId: Int
        let isAck: Bool
    }

    private let defaults = UserDefaults.standard
    private let storeKey = "mw.specialrequest.outbox.v1"
    private var running: Task<Void, Never>?

    private var entries: [Entry] {
        get {
            guard let data = defaults.data(forKey: storeKey),
                  let decoded = try? JSONDecoder().decode([Entry].self, from: data) else { return [] }
            return decoded
        }
        set { defaults.set(try? JSONEncoder().encode(newValue), forKey: storeKey) }
    }

    var hasPending: Bool { !entries.isEmpty }

    func enqueue(userId: Int?, body: [String: Any]) {
        guard let json = try? JSONSerialization.data(withJSONObject: body) else { return }
        var list = entries
        list.append(Entry(id: UUID().uuidString, userId: userId, bodyJSON: json,
                          requestVisitId: body["request_visit_id"] as? Int ?? 0,
                          isAck: (body["mode"] as? String) == "ack"))
        entries = list
    }

    func hasPendingAck(requestVisitId: Int, userId: Int?) -> Bool {
        entries.contains { $0.isAck && $0.requestVisitId == requestVisitId && $0.userId == userId }
    }

    /// One run at a time; only the signed-in person's entries.
    func drain(using apiClient: APIClient) async {
        if let running { return await running.value }
        let task = Task { await self.drainOnce(using: apiClient) }
        running = task
        await task.value
        running = nil
    }

    private func drainOnce(using apiClient: APIClient) async {
        guard let me = apiClient.currentUserId else { return }
        for entry in entries where entry.userId == me {
            guard let body = (try? JSONSerialization.jsonObject(with: entry.bodyJSON)) as? [String: Any] else {
                entries = entries.filter { $0.id != entry.id }
                continue
            }
            do {
                let _: SpecialRequestActionResponse = try await apiClient.request(.scheduleSpecialRequestAction, body: body)
                entries = entries.filter { $0.id != entry.id }
            } catch APIError.serverError(let message) {
                print("[SpecialRequestOutbox] refused \(entry.requestVisitId): \(message) — dropped")
                entries = entries.filter { $0.id != entry.id }
            } catch {
                return   // no signal / login expired / unreadable: keep, in order
            }
        }
    }
}
