//
//  OwnerSearchViews.swift
//  MowologyCRM
//
//  The owner's half of the one search box (Tim, 2026-11-06: "an independent owner needs to work off their phone").
//  Crew keep the property search in SearchView; an owner (admin/manager) also gets, as they type:
//    • a direct answer when the box reads as a question about one client ("has lindas quote been sent") — plain SQL on
//      the server (ClientFactAnswerer), with the buttons that act on it: Open / Call / Text / Email
//    • an "Ask Kaname" row — the AI only runs when that row is tapped (or Return on a question)
//    • every group they may see: people, quotes, invoices, jobs, receipts, suppliers, emails & texts, team
//  What they pick is sent back (mode=pick) so it ranks first next time for the same start of a query.
//

import SwiftUI

// MARK: - Models

/// One button under an answer. Built by the server; the phone only turns it into a link.
struct QuickAction: Decodable, Hashable, Identifiable {
    let kind: String          // open | call | text | email
    let label: String
    let url: String?
    let phone: String?
    let email: String?

    var id: String { kind + label + (url ?? phone ?? email ?? "") }

    private enum CodingKeys: String, CodingKey { case kind, label, url, phone, email }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        kind  = c.bkString(.kind) ?? "open"
        label = c.bkString(.label) ?? "Open"
        url   = c.bkString(.url)
        phone = c.bkString(.phone)
        email = c.bkString(.email)
    }

    init(kind: String, label: String, url: String? = nil, phone: String? = nil, email: String? = nil) {
        self.kind = kind; self.label = label; self.url = url; self.phone = phone; self.email = email
    }

    var icon: String {
        switch kind {
        case "call":  return "phone.fill"
        case "text":  return "message.fill"
        case "email": return "envelope.fill"
        default:      return "arrow.up.right.square"
        }
    }

    var link: URL? {
        switch kind {
        case "call", "text":
            let digits = (phone ?? "").filter { $0.isNumber || $0 == "+" }
            guard digits.count >= 7 else { return nil }
            return URL(string: (kind == "call" ? "tel:" : "sms:") + digits)
        case "email":
            guard let email, email.contains("@"),
                  let encoded = email.addingPercentEncoding(withAllowedCharacters: .urlUserAllowed) else { return nil }
            return URL(string: "mailto:" + encoded)
        default:
            return url.flatMap(HeadCardViewModel.webURL)
        }
    }
}

struct QuickAnswer: Decodable {
    let answer: String
    let intent: String?
    let actions: [QuickAction]

    private enum CodingKeys: String, CodingKey { case answer, intent, actions }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        answer  = c.bkString(.answer) ?? ""
        intent  = c.bkString(.intent)
        actions = (try? c.decodeIfPresent([QuickAction].self, forKey: .actions)) ?? []
    }
}

struct OwnerSearchItem: Decodable, Hashable, Identifiable {
    let kind: String
    let entityId: Int
    let label: String
    let sublabel: String
    let url: String?
    let phone: String?
    let email: String?
    let contactId: Int?
    let propertyId: Int?

    var id: String { "\(kind)-\(entityId)" }

    private enum CodingKeys: String, CodingKey {
        case kind, id, label, sublabel, url, phone, email
        case contactId  = "contact_id"
        case propertyId = "property_id"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        kind       = c.bkString(.kind) ?? ""
        entityId   = c.bkInt(.id) ?? 0
        label      = c.bkString(.label) ?? ""
        sublabel   = c.bkString(.sublabel) ?? ""
        url        = c.bkString(.url)
        phone      = c.bkString(.phone)
        email      = c.bkString(.email)
        contactId  = c.bkInt(.contactId)
        propertyId = c.bkInt(.propertyId)
    }

    var icon: String {
        switch kind {
        case "contact":  return "person.fill"
        case "company":  return "building.2.fill"
        case "property": return "mappin.and.ellipse"
        case "quote":    return "doc.text"
        case "invoice":  return "creditcard"
        case "job", "contract": return "calendar"
        case "receipt":  return "doc.plaintext"
        case "vendor":   return "shippingbox"
        case "message":  return "envelope"
        case "team":     return "person.2.fill"
        default:         return "magnifyingglass"
        }
    }

    /// Opens the client's page on the phone; everything else opens its full page.
    var contactPageId: Int? {
        if kind == "contact" { return entityId }
        if kind == "message" { return contactId }
        return nil
    }
}

struct OwnerSearchGroup: Decodable, Identifiable {
    let key: String
    let title: String
    let more: Bool
    let items: [OwnerSearchItem]

    var id: String { key }

    private enum CodingKeys: String, CodingKey { case key, title, more, items }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key   = c.bkString(.key) ?? ""
        title = c.bkString(.title) ?? ""
        more  = c.bkBool(.more) ?? false
        items = (try? c.decodeIfPresent([OwnerSearchItem].self, forKey: .items)) ?? []
    }
}

struct OwnerSearchResponse: Decodable {
    let groups: [OwnerSearchGroup]
    let answer: QuickAnswer?

    private enum CodingKeys: String, CodingKey { case groups, answer }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        groups = (try? c.decodeIfPresent([OwnerSearchGroup].self, forKey: .groups)) ?? []
        answer = try? c.decodeIfPresent(QuickAnswer.self, forKey: .answer)
    }
}

struct OwnerContact: Decodable {
    let id: Int
    let name: String
    let company: String?
    let phone: String?
    let email: String?

    private enum CodingKeys: String, CodingKey { case id, name, company, phone, email }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id      = c.bkInt(.id) ?? 0
        name    = c.bkString(.name) ?? ""
        company = c.bkString(.company)
        phone   = c.bkString(.phone)
        email   = c.bkString(.email)
    }

    var actions: [QuickAction] {
        var out: [QuickAction] = []
        if let phone, !phone.isEmpty {
            out.append(QuickAction(kind: "call", label: "Call", phone: phone))
            out.append(QuickAction(kind: "text", label: "Text", phone: phone))
        }
        if let email, !email.isEmpty { out.append(QuickAction(kind: "email", label: "Email", email: email)) }
        return out
    }
}

struct OwnerContactResponse: Decodable {
    let contact: OwnerContact?
    let properties: [OwnerSearchItem]
    let quotes: [OwnerSearchItem]
    let invoices: [OwnerSearchItem]
    let messages: [OwnerSearchItem]

    private enum CodingKeys: String, CodingKey { case contact, properties, quotes, invoices, messages }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        contact    = try? c.decodeIfPresent(OwnerContact.self, forKey: .contact)
        properties = (try? c.decodeIfPresent([OwnerSearchItem].self, forKey: .properties)) ?? []
        quotes     = (try? c.decodeIfPresent([OwnerSearchItem].self, forKey: .quotes)) ?? []
        invoices   = (try? c.decodeIfPresent([OwnerSearchItem].self, forKey: .invoices)) ?? []
        messages   = (try? c.decodeIfPresent([OwnerSearchItem].self, forKey: .messages)) ?? []
    }
}

/// Navigation value for the client page.
struct OwnerContactRoute: Hashable { let id: Int; let name: String }

// MARK: - ViewModel

@MainActor
final class OwnerSearchModel: ObservableObject {

    @Published private(set) var groups: [OwnerSearchGroup] = []
    @Published private(set) var answer: QuickAnswer?
    @Published private(set) var failed: String?
    @Published private(set) var isSearching = false

    // Ask Kaname (the AI — only on a tap)
    @Published private(set) var askedQuestion: String?
    @Published private(set) var askAnswer: String?
    @Published private(set) var askActions: [QuickAction] = []
    @Published private(set) var askError: String?
    @Published private(set) var isAsking = false
    @Published private(set) var askLeft: Int?

    let apiClient: APIClient
    private var task: Task<Void, Never>?
    private var lastQuery = ""

    init(authSession: AuthSession) {
        apiClient = APIClient(authSession: authSession)
    }

    /// Same debounce as the property search, so both requests leave together.
    func queryChanged(_ q: String) {
        task?.cancel()
        lastQuery = q
        guard q.count >= 2 else {
            groups = []; answer = nil; failed = nil; isSearching = false
            return
        }
        isSearching = true
        task = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 300_000_000)
            guard !Task.isCancelled else { return }
            await self?.run(q)
        }
    }

    private func run(_ q: String) async {
        do {
            let r: OwnerSearchResponse = try await apiClient.request(.ownerSearch(query: q))
            guard !Task.isCancelled, q == lastQuery else { return }
            // Properties come from the field search (today's stops first, nearest, the property page).
            groups = r.groups.filter { $0.key != "properties" }
            answer = r.answer
            failed = nil
        } catch {
            guard !Task.isCancelled, q == lastQuery else { return }
            groups = []; answer = nil
            failed = ErrorDetails.describe(error)
            AppDiagnostics.shared.record(category: "OwnerSearch", message: ErrorDetails.describe(error))
        }
        isSearching = false
    }

    /// Learn the pick — fire and forget; a failure only costs the ranking.
    func pick(query: String, kind: String, id: Int) {
        let q = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard q.count >= 2, id > 0 else { return }
        let client = apiClient
        Task {
            let _: HeadActionResponse? = try? await client.request(.ownerSearchPick, body: ["q": q, "kind": kind, "id": id])
        }
    }

    func ask(_ question: String) async {
        let q = question.trimmingCharacters(in: .whitespacesAndNewlines)
        guard q.count >= 3, !isAsking else { return }
        isAsking = true
        defer { isAsking = false }
        askedQuestion = q
        askAnswer = nil; askActions = []; askError = nil
        do {
            let r: HeadActionResponse = try await apiClient.request(.teamHeadAction, body: ["mode": "ask", "question": q])
            if let left = r.left { askLeft = left }
            if r.ok, let text = r.answer, !text.isEmpty {
                askAnswer = text
                askActions = r.actions
            } else {
                askError = r.message ?? r.error ?? "Kaname answered ok=false without a message."
            }
        } catch {
            askError = ErrorDetails.describe(error)
            AppDiagnostics.shared.record(category: "OwnerSearchAsk", message: ErrorDetails.describe(error))
        }
    }

    var hasResults: Bool { answer != nil || groups.contains { !$0.items.isEmpty } }

    /// Return on the keyboard asks Kaname only when the box reads as a question.
    static func looksLikeQuestion(_ q: String) -> Bool {
        let t = q.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if t.hasSuffix("?") { return true }
        let first = t.split(separator: " ").first.map(String.init) ?? ""
        let starters: Set<String> = ["has", "have", "did", "does", "do", "is", "was", "were", "are", "when", "what", "whats",
                                     "who", "how", "where", "why", "which", "can", "will", "should", "any"]
        return t.split(separator: " ").count >= 3 && starters.contains(first)
    }
}

// MARK: - Pieces

/// Open / Call / Text / Email — big enough for a thumb in the truck.
struct QuickActionButtons: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.openURL) private var openURL
    let actions: [QuickAction]

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(actions) { action in
                    Button {
                        if let url = action.link { openURL(url) }
                    } label: {
                        Label(action.label, systemImage: action.icon)
                            .font(appStyle.font(.subheadline, weight: .semibold))
                            .frame(minHeight: 44)
                            .padding(.horizontal, 6)
                    }
                    .buttonStyle(.bordered)
                    .tint(appStyle.tenant)
                    .disabled(action.link == nil)
                }
            }
        }
    }
}

/// The direct answer, shown while typing — no AI, no tap needed.
struct QuickAnswerCard: View {
    @Environment(\.itAppStyle) private var appStyle
    let answer: QuickAnswer

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Label("Answer", systemImage: "checkmark.seal.fill")
                .font(appStyle.font(.caption, weight: .semibold))
                .foregroundStyle(appStyle.tenant)
            Text(answer.answer)
                .font(appStyle.font(.subheadline, weight: .medium))
                .textSelection(.enabled)
                .fixedSize(horizontal: false, vertical: true)
            if !answer.actions.isEmpty { QuickActionButtons(actions: answer.actions) }
        }
        .padding(.vertical, 6)
    }
}

/// "Ask Kaname: …" — the only place the AI runs, and only on a tap.
struct AskKanameRow: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var model: OwnerSearchModel
    let query: String

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Button {
                Task { await model.ask(query) }
            } label: {
                HStack(spacing: 10) {
                    Image(systemName: "sparkles").font(appStyle.font(.body))
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Ask Kaname").font(appStyle.font(.subheadline, weight: .semibold))
                        Text("“\(query)”")
                            .font(appStyle.font(.caption))
                            .foregroundStyle(appStyle.meta)
                            .lineLimit(2)
                    }
                    Spacer(minLength: 4)
                    if model.isAsking { ProgressView() }
                    else { Image(systemName: "arrow.up.circle.fill").font(appStyle.font(.title3)) }
                }
                .frame(minHeight: 44)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .foregroundStyle(appStyle.tenant)
            .disabled(model.isAsking || query.count < 3)

            if let asked = model.askedQuestion, asked == query {
                if let a = model.askAnswer {
                    Text(a)
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(appStyle.text)
                        .textSelection(.enabled)
                        .fixedSize(horizontal: false, vertical: true)
                    if !model.askActions.isEmpty { QuickActionButtons(actions: model.askActions) }
                } else if let e = model.askError {
                    Text(e).font(appStyle.font(.caption)).foregroundStyle(appStyle.attention)
                }
            }
            if let left = model.askLeft {
                Text("\(left) ask\(left == 1 ? "" : "s") left today")
                    .font(appStyle.font(.caption2))
                    .foregroundStyle(appStyle.meta)
            }
        }
        .padding(.vertical, 2)
    }
}

struct OwnerSearchRow: View {
    @Environment(\.itAppStyle) private var appStyle
    let item: OwnerSearchItem

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: item.icon)
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.meta)
                .frame(width: 22)
            VStack(alignment: .leading, spacing: 3) {
                Text(item.label)
                    .font(appStyle.font(.body, weight: .semibold))
                    .fixedSize(horizontal: false, vertical: true)
                if !item.sublabel.isEmpty {
                    Text(item.sublabel)
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            Spacer(minLength: 4)
            if item.contactPageId == nil {
                Image(systemName: "arrow.up.right")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
            }
        }
        .padding(.vertical, 2)
        .contentShape(Rectangle())
    }
}

/// One tappable result: the client page on the phone, or the full page in the browser.
struct OwnerSearchItemLink: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.openURL) private var openURL
    let item: OwnerSearchItem
    var onPick: (OwnerSearchItem) -> Void = { _ in }

    var body: some View {
        if let cid = item.contactPageId {
            NavigationLink(value: OwnerContactRoute(id: cid, name: item.kind == "contact" ? item.label : "")) {
                OwnerSearchRow(item: item)
            }
            .simultaneousGesture(TapGesture().onEnded { onPick(item) })
            .listRowBackground(appStyle.surface)
        } else {
            Button {
                onPick(item)
                if let url = item.url.flatMap(HeadCardViewModel.webURL) { openURL(url) }
            } label: {
                OwnerSearchRow(item: item)
            }
            .buttonStyle(.plain)
            .disabled(item.url == nil)
            .listRowBackground(appStyle.surface)
        }
    }
}

// MARK: - Client page

struct OwnerContactView: View {
    @Environment(\.itAppStyle) private var appStyle
    let route: OwnerContactRoute
    let apiClient: APIClient
    @ObservedObject var searchVM: SearchViewModel
    let authSession: AuthSession

    @State private var data: OwnerContactResponse?
    @State private var failed: String?

    var body: some View {
        List {
            if let c = data?.contact {
                Section {
                    VStack(alignment: .leading, spacing: 6) {
                        Text(c.name).font(appStyle.font(.title3, weight: .bold))
                        if let company = c.company, !company.isEmpty {
                            Label(company, systemImage: "building.2").font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                        }
                        if let phone = c.phone, !phone.isEmpty {
                            Text(phone).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                        }
                        if let email = c.email, !email.isEmpty {
                            Text(email).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                        }
                        if !c.actions.isEmpty { QuickActionButtons(actions: c.actions).padding(.top, 4) }
                    }
                    .padding(.vertical, 4)
                    .listRowBackground(appStyle.surface)
                }
            } else if failed == nil {
                Section { HStack(spacing: 10) { ProgressView(); Text("Loading…").foregroundStyle(appStyle.meta) } }
            }

            if let d = data {
                if !d.properties.isEmpty {
                    Section("Properties") {
                        ForEach(d.properties) { p in
                            NavigationLink(value: Self.summary(for: p)) { OwnerSearchRow(item: p) }
                                .listRowBackground(appStyle.surface)
                        }
                    }
                }
                itemSection("Open quotes", d.quotes)
                itemSection("Unpaid invoices", d.invoices)
                itemSection("Recent emails & texts", d.messages)
                if d.properties.isEmpty && d.quotes.isEmpty && d.invoices.isEmpty && d.messages.isEmpty {
                    Section { Text("No properties, open quotes, unpaid invoices or messages on record.")
                        .font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta) }
                }
            }

            if let failed {
                Section {
                    Label(failed, systemImage: "wifi.exclamationmark")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.attention)
                }
            }
        }
        .listStyle(.insetGrouped)
        .itAppForm()
        .itAppScreen()
        .foregroundStyle(appStyle.text)
        .navigationTitle(data?.contact?.name ?? route.name)
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        .refreshable { await load() }
    }

    @ViewBuilder
    private func itemSection(_ title: String, _ items: [OwnerSearchItem]) -> some View {
        if !items.isEmpty {
            Section(title) {
                ForEach(items) { item in OwnerSearchItemLink(item: item) }
            }
        }
    }

    private func load() async {
        do {
            let r: OwnerContactResponse = try await apiClient.request(.ownerSearchContact(id: route.id))
            data = r
            failed = r.contact == nil ? "That client is no longer available." : nil
        } catch {
            failed = ErrorDetails.describe(error)
            AppDiagnostics.shared.record(category: "OwnerContact", message: ErrorDetails.describe(error))
        }
    }

    /// Enough of a property card to open the property page, which loads the rest.
    static func summary(for p: OwnerSearchItem) -> SearchProperty {
        SearchProperty(id: p.propertyId ?? p.entityId, label: p.label, propertyName: nil, companyName: nil, contactName: nil,
                       phone: nil, address: p.label, city: p.sublabel.isEmpty ? nil : p.sublabel, latitude: nil, longitude: nil,
                       distanceM: nil, onToday: false, todayVisitId: nil, plans: [], lastDone: nil, notes: nil, lawnSqft: nil,
                       upcoming: nil)
    }
}
