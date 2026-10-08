//
//  PennyChaseViews.swift
//  MowologyCRM
//
//  Penny's missing-receipt chaser — GET/POST /api/expenses/penny-chase-mobile (MissingReceiptService,
//  migration 1245), the phone's version of the web crew card (crew-team.js) and Tim's strip.
//
//    Crew (Receipts tab): "Penny needs N receipts" — each card charge of yours with no receipt:
//      Snap it          → the receipt camera; Penny matches the saved receipt to the charge
//                         (same amount ±2 %, ±3 days — the server's ExpenseGate hook)
//      It's already in  → your receipts from around then → that one answers the charge
//      No receipt       → lost / the vendor didn't give one — Penny stops asking, Tim is told
//    Admin (Team tab, Penny): everyone's open items, who Penny is asking, and Reassign.
//    Also on the Team tab: Penny's read-only lines — look-back proposals, deposits matched to
//    invoices, missing receipts — each opening its web page (approvals stay on the web).
//
//  Every field is optional and numbers may arrive as strings (BK helpers), so nothing fails a decode.
//

import SwiftUI

// MARK: - Models

struct PennyChaseItem: Decodable, Identifiable, Equatable {
    let id: Int
    let ask: String
    let amount: Double
    let date: String
    let time: String?
    let vendor: String
    let basisNote: String
    let who: String
    let userId: Int
    let nudges: Int

    private enum CodingKeys: String, CodingKey {
        case id, ask, amount, date, time, vendor, who, nudges
        case basisNote = "basis_note", userId = "user_id"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        ask = c.bkString(.ask) ?? ""
        amount = c.bkDouble(.amount) ?? 0
        date = c.bkString(.date) ?? ""
        time = c.bkString(.time)
        vendor = c.bkString(.vendor) ?? ""
        basisNote = c.bkString(.basisNote) ?? ""
        who = c.bkString(.who) ?? ""
        userId = c.bkInt(.userId) ?? 0
        nudges = c.bkInt(.nudges) ?? 0
    }
}

struct PennyChaseMineResponse: Decodable {
    let ok: Bool
    let ready: Bool
    let count: Int
    let total: Double
    let items: [PennyChaseItem]
    let error: String?

    private enum CodingKeys: String, CodingKey { case ok, ready, count, total, items, error }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        ready = c.bkBool(.ready) ?? false
        count = c.bkInt(.count) ?? 0
        total = c.bkDouble(.total) ?? 0
        items = (try? c.decodeIfPresent([PennyChaseItem].self, forKey: .items)) ?? []
        error = c.bkString(.error)
    }
}

struct PennyChaseReceipt: Decodable, Identifiable, Equatable {
    let id: Int
    let date: String
    let vendor: String
    let total: Double
    let sameAmount: Bool

    private enum CodingKeys: String, CodingKey { case id, date, vendor, total, sameAmount = "same_amount" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        date = c.bkString(.date) ?? ""
        vendor = c.bkString(.vendor) ?? "Receipt"
        total = c.bkDouble(.total) ?? 0
        sameAmount = c.bkBool(.sameAmount) ?? false
    }
}

struct PennyChaseRecentResponse: Decodable {
    let ok: Bool
    let receipts: [PennyChaseReceipt]

    private enum CodingKeys: String, CodingKey { case ok, receipts }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        receipts = (try? c.decodeIfPresent([PennyChaseReceipt].self, forKey: .receipts)) ?? []
    }
}

struct PennyChasePerson: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String

    private enum CodingKeys: String, CodingKey { case id, name = "full_name" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.bkInt(.id) ?? 0
        name = c.bkString(.name) ?? ""
    }
}

struct PennyChaseAdminResponse: Decodable {
    let ok: Bool
    let ready: Bool
    let summary: String
    let items: [PennyChaseItem]
    let people: [PennyChasePerson]
    let error: String?

    private enum CodingKeys: String, CodingKey { case ok, ready, summary, items, people, error }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        ready = c.bkBool(.ready) ?? true
        summary = c.bkString(.summary) ?? ""
        items = (try? c.decodeIfPresent([PennyChaseItem].self, forKey: .items)) ?? []
        people = (try? c.decodeIfPresent([PennyChasePerson].self, forKey: .people)) ?? []
        error = c.bkString(.error)
    }
}

struct PennyChaseActionResponse: Decodable {
    let ok: Bool
    let message: String?
    let error: String?
    let status: String?

    private enum CodingKeys: String, CodingKey { case ok, message, error, status }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.bkBool(.ok) ?? false
        message = c.bkString(.message)
        error = c.bkString(.error)
        status = c.bkString(.status)
    }
}

// MARK: - API

@MainActor
protocol PennyChaseAPI {
    func mine() async throws -> PennyChaseMineResponse
    func recent(itemId: Int) async throws -> PennyChaseRecentResponse
    func admin() async throws -> PennyChaseAdminResponse
    func send(_ body: [String: Any]) async throws -> PennyChaseActionResponse
}

@MainActor
struct LivePennyChaseAPI: PennyChaseAPI {
    let client: APIClient

    func mine() async throws -> PennyChaseMineResponse {
        try await client.request(.pennyChase(query: [URLQueryItem(name: "mode", value: "mine")]))
    }
    func recent(itemId: Int) async throws -> PennyChaseRecentResponse {
        try await client.request(.pennyChase(query: [URLQueryItem(name: "mode", value: "recent"),
                                                     URLQueryItem(name: "id", value: "\(itemId)")]))
    }
    func admin() async throws -> PennyChaseAdminResponse {
        try await client.request(.pennyChase(query: [URLQueryItem(name: "mode", value: "admin")]))
    }
    func send(_ body: [String: Any]) async throws -> PennyChaseActionResponse {
        try await client.request(.pennyChaseAction, body: body)
    }
}

// MARK: - View model

@MainActor
final class PennyChaseViewModel: ObservableObject {

    /// The REASONS MissingReceiptService accepts, in the web sheet's order.
    static let reasons: [(code: String, label: String)] = [("lost", "Lost it"), ("not_available", "Vendor didn't give one")]

    // Crew: my open items
    @Published private(set) var items: [PennyChaseItem] = []
    @Published private(set) var total: Double = 0
    // Admin: everyone's
    @Published private(set) var adminItems: [PennyChaseItem] = []
    @Published private(set) var adminSummary = ""
    @Published private(set) var people: [PennyChasePerson] = []
    @Published private(set) var adminLoaded = false

    // "It's already in"
    @Published private(set) var recent: [PennyChaseReceipt] = []
    @Published private(set) var recentLoading = false

    @Published private(set) var busy: Int?
    @Published var message: String?
    @Published var messageIsError = false

    private let api: PennyChaseAPI

    init(api: PennyChaseAPI) {
        self.api = api
    }

    convenience init(client: APIClient) {
        self.init(api: LivePennyChaseAPI(client: client))
    }

    /// Crew card. Quiet on any failure — an older server (no endpoint) just shows nothing.
    func loadMine() async {
        guard let r = try? await api.mine(), r.ok, r.ready else { return }
        items = r.items
        total = r.total
    }

    func loadAdmin() async {
        guard let r = try? await api.admin(), r.ok, r.ready else { adminLoaded = true; return }
        adminItems = r.items
        adminSummary = r.summary
        people = r.people
        adminLoaded = true
    }

    func loadRecent(for item: PennyChaseItem) async {
        recent = []
        recentLoading = true
        defer { recentLoading = false }
        recent = (try? await api.recent(itemId: item.id))?.receipts ?? []
    }

    func attach(_ item: PennyChaseItem, receipt: PennyChaseReceipt) async {
        await act(item, ["mode": "attach", "id": item.id, "expense_id": receipt.id])
    }

    func noReceipt(_ item: PennyChaseItem, reason: String, note: String) async {
        await act(item, ["mode": "no_receipt", "id": item.id, "reason": reason, "note": note])
    }

    func reassign(_ item: PennyChaseItem, to person: PennyChasePerson) async {
        guard busy == nil else { return }
        busy = item.id
        defer { busy = nil }
        do {
            let r = try await api.send(["mode": "reassign", "id": item.id, "user_id": person.id])
            show(r.ok ? (r.message ?? "Penny will ask \(person.name).") : (r.message ?? r.error ?? "That didn't work."), error: !r.ok)
            if r.ok { await loadAdmin() }
        } catch {
            show("No connection — nothing was changed.", error: true)
        }
    }

    private func act(_ item: PennyChaseItem, _ body: [String: Any]) async {
        guard busy == nil else { return }
        busy = item.id
        defer { busy = nil }
        do {
            let r = try await api.send(body)
            if r.ok {
                items.removeAll { $0.id == item.id }
                adminItems.removeAll { $0.id == item.id }
                total = items.reduce(0) { $0 + $1.amount }
                show(r.message ?? "Thanks!", error: false)
            } else {
                show(r.message ?? r.error ?? "That didn't work — try again.", error: true)
            }
        } catch {
            show("No connection — nothing was changed. Try again when you have signal.", error: true)
        }
    }

    private func show(_ text: String, error: Bool) {
        message = text
        messageIsError = error
    }

    static func money(_ v: Double) -> String {
        v.formatted(.currency(code: "CAD").precision(.fractionLength(2)))
    }

    static func shortDate(_ s: String) -> String { HeadCardViewModel.shortDate(s) }
}

// MARK: - Crew card (Receipts tab)

/// "Penny needs 2 receipts ($84.10)". Hidden when nothing is missing.
struct PennyCrewChaseCard: View {
    @ObservedObject var vm: PennyChaseViewModel
    /// Opens the receipt camera (the Receipts tab's own capture).
    let onSnap: @MainActor () -> Void

    @State private var alreadyFor: PennyChaseItem?
    @State private var noReceiptFor: PennyChaseItem?

    var body: some View {
        if !vm.items.isEmpty || vm.message != nil {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 10) {
                    AsyncImage(url: URL(string: "https://mowology.ca/crm/img/heads/penny.jpg")) { phase in
                        if let img = phase.image { img.resizable().scaledToFill() } else { Color.MW.light }
                    }
                    .frame(width: 36, height: 36)
                    .clipShape(Circle())
                    VStack(alignment: .leading, spacing: 1) {
                        Text(vm.items.isEmpty ? "Penny" : "Penny needs \(vm.items.count) receipt\(vm.items.count == 1 ? "" : "s")")
                            .font(.subheadline.weight(.semibold))
                        if !vm.items.isEmpty {
                            Text("Card charges with no receipt yet · \(PennyChaseViewModel.money(vm.total))")
                                .font(.caption).foregroundStyle(.secondary)
                        }
                    }
                }
                ForEach(vm.items) { item in
                    VStack(alignment: .leading, spacing: 6) {
                        Text(item.ask.isEmpty ? "\(item.vendor) · \(PennyChaseViewModel.money(item.amount))" : item.ask)
                            .font(.subheadline)
                            .fixedSize(horizontal: false, vertical: true)
                        HStack(spacing: 8) {
                            Button("Snap it") { onSnap() }
                                .buttonStyle(.borderedProminent)
                                .tint(Color.MW.green)
                            Button("It's already in") {
                                alreadyFor = item
                                Task { await vm.loadRecent(for: item) }
                            }
                            .buttonStyle(.bordered)
                            Button("No receipt") { noReceiptFor = item }
                                .buttonStyle(.borderless)
                                .tint(.secondary)
                        }
                        .font(.caption.weight(.semibold))
                        .controlSize(.small)
                        .disabled(vm.busy != nil)
                    }
                    if item.id != vm.items.last?.id { Divider() }
                }
                if let m = vm.message {
                    Text(m).font(.footnote).foregroundStyle(vm.messageIsError ? .red : Color.MW.green)
                }
            }
            .padding(.vertical, 4)
            .sheet(item: $alreadyFor) { item in
                PennyAlreadyInSheet(vm: vm, item: item) { alreadyFor = nil }
            }
            .confirmationDialog("No receipt for this one?", isPresented: Binding(
                get: { noReceiptFor != nil }, set: { if !$0 { noReceiptFor = nil } }
            ), titleVisibility: .visible, presenting: noReceiptFor) { item in
                ForEach(PennyChaseViewModel.reasons, id: \.code) { r in
                    Button(r.label) { Task { await vm.noReceipt(item, reason: r.code, note: "") } }
                }
                Button("Cancel", role: .cancel) {}
            } message: { _ in
                Text("Penny stops asking and lets Tim know — there's no GST claim without a receipt.")
            }
        }
    }
}

/// "It's already in": your receipts from around the charge date, same amount first.
struct PennyAlreadyInSheet: View {
    @ObservedObject var vm: PennyChaseViewModel
    let item: PennyChaseItem
    let done: () -> Void

    var body: some View {
        NavigationStack {
            List {
                Section {
                    Text(item.ask.isEmpty ? "\(item.vendor) · \(PennyChaseViewModel.money(item.amount))" : item.ask)
                        .font(.subheadline)
                }
                Section("Which receipt is it?") {
                    if vm.recentLoading {
                        HStack { Spacer(); ProgressView(); Spacer() }
                    } else if vm.recent.isEmpty {
                        Text("I can't see a receipt of yours from around then. If you have the paper one, snap it — or tell me it's gone.")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    ForEach(vm.recent) { rc in
                        Button {
                            Task {
                                await vm.attach(item, receipt: rc)
                                done()
                            }
                        } label: {
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(rc.vendor).font(.subheadline.weight(.semibold))
                                    Text(PennyChaseViewModel.shortDate(rc.date)).font(.caption).foregroundStyle(.secondary)
                                }
                                Spacer()
                                Text(PennyChaseViewModel.money(rc.total))
                                    .font(.subheadline.monospacedDigit())
                                    .foregroundStyle(rc.sameAmount ? Color.MW.green : .primary)
                                if rc.sameAmount {
                                    Image(systemName: "checkmark.circle.fill").foregroundStyle(Color.MW.green)
                                }
                            }
                        }
                        .disabled(vm.busy != nil)
                    }
                }
            }
            .navigationTitle("It's already in")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { done() } }
            }
        }
        .presentationDetents([.medium, .large])
    }
}

// MARK: - Admin (Team tab, Penny)

/// Everyone's missing receipts: who Penny is asking, and Reassign. Hidden when there are none.
struct PennyChaseAdminSection: View {
    @ObservedObject var vm: PennyChaseViewModel
    @Environment(\.openURL) private var openURL
    @State private var expanded = false

    var body: some View {
        if !vm.adminItems.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                Button {
                    withAnimation { expanded.toggle() }
                } label: {
                    HStack {
                        Label(vm.adminSummary.isEmpty ? "\(vm.adminItems.count) receipts missing" : vm.adminSummary,
                              systemImage: "doc.badge.clock")
                            .font(.subheadline.weight(.semibold))
                            .multilineTextAlignment(.leading)
                        Spacer(minLength: 4)
                        Image(systemName: expanded ? "chevron.up" : "chevron.down").font(.caption)
                    }
                }
                .buttonStyle(.plain)
                if expanded {
                    ForEach(vm.adminItems.prefix(15)) { item in
                        HStack(alignment: .top, spacing: 8) {
                            VStack(alignment: .leading, spacing: 2) {
                                Text("\(item.vendor) · \(PennyChaseViewModel.money(item.amount))")
                                    .font(.subheadline)
                                Text([PennyChaseViewModel.shortDate(item.date),
                                      item.who.isEmpty ? "nobody yet" : "asking \(item.who)",
                                      item.nudges > 0 ? "\(item.nudges) nudge\(item.nudges == 1 ? "" : "s")" : ""]
                                        .filter { !$0.isEmpty }.joined(separator: " · "))
                                    .font(.caption).foregroundStyle(.secondary)
                            }
                            Spacer(minLength: 4)
                            Menu {
                                ForEach(vm.people.filter { $0.id != item.userId }) { p in
                                    Button(p.name) { Task { await vm.reassign(item, to: p) } }
                                }
                            } label: {
                                if vm.busy == item.id { ProgressView().controlSize(.small) } else { Text("Reassign") }
                            }
                            .font(.caption)
                            .disabled(vm.busy != nil || vm.people.isEmpty)
                        }
                    }
                    Button("Rules, cards and everything else on the web →") {
                        if let u = HeadCardViewModel.webURL("/crm/dashboard_appstack.php#mw-penny") { openURL(u) }
                    }
                    .font(.caption)
                }
                if let m = vm.message {
                    Text(m).font(.footnote).foregroundStyle(vm.messageIsError ? .red : Color.MW.green)
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Color.MW.light, in: RoundedRectangle(cornerRadius: 12))
        }
    }
}

/// Penny's read-only lines from the web card: look-back, payments ↔ invoices, missing receipts.
struct PennyLinesView: View {
    let lines: PennyLines?
    /// The missing-receipt line is shown by PennyChaseAdminSection when it has the list.
    var hideMissing = false
    @Environment(\.openURL) private var openURL

    var body: some View {
        if let l = lines, l.lookback != nil || l.payments != nil || (!hideMissing && (l.missing?.open ?? 0) > 0) {
            VStack(alignment: .leading, spacing: 6) {
                if let p = l.payments, p.waiting > 0 {
                    row("creditcard", "\(p.waiting) deposit\(p.waiting == 1 ? "" : "s") matched to invoices waiting (\(PennyChaseViewModel.money(p.waitingTotal)))"
                        + (p.high > 0 ? " · \(p.high) high-confidence" : ""), p.url)
                }
                if let lb = l.lookback, lb.count > 0 {
                    row("magnifyingglass", lb.text, lb.url)
                }
                if !hideMissing, let m = l.missing, m.open > 0 {
                    row("doc.badge.clock", "\(m.open) receipt\(m.open == 1 ? "" : "s") missing (\(PennyChaseViewModel.money(m.openAmount))) — Penny is asking the crew",
                        "/crm/dashboard_appstack.php#mw-penny")
                }
            }
        }
    }

    @MainActor
    private func row(_ icon: String, _ text: String, _ path: String) -> some View {
        Button {
            if let u = HeadCardViewModel.webURL(path) { openURL(u) }
        } label: {
            HStack(alignment: .top, spacing: 8) {
                Image(systemName: icon).foregroundStyle(Color.MW.green).frame(width: 18)
                Text(text).font(.caption).multilineTextAlignment(.leading).foregroundStyle(.primary)
                Spacer(minLength: 4)
                Image(systemName: "arrow.up.right.square").font(.caption).foregroundStyle(.secondary)
            }
        }
        .buttonStyle(.plain)
    }
}
