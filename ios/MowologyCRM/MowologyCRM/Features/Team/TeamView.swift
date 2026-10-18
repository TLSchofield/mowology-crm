//
//  TeamView.swift
//  MowologyCRM
//
//  The admin's "Team" tab (replaces Account for admins): the department heads across
//  the top. Tapping a face switches the card under the row — Penny's receipts (default),
//  Sam's sales desk, and one shared HeadCardView for Otto, Mia, Yui and Charlie (their
//  column of the dashboard Action Board, their brain and their one action). The profile
//  row and Sign Out (moved here from Account) sit at the bottom.
//
//  Tapping a department-head push (NotificationRouter.pendingHead — e.g. "Sam: Linda just
//  opened QUO-…") switches to that head, reloads the card and scrolls it into view.
//

import SwiftUI

/// One department head on the faces row. Faces are public images on the web server.
struct TeamHead: Identifiable, Hashable {
    let slug: String
    let name: String
    let role: String
    let isLive: Bool

    var id: String { slug }
    var faceURL: URL? { URL(string: "https://mowology.ca/crm/img/heads/\(slug).jpg") }

    static let all: [TeamHead] = [
        TeamHead(slug: "penny",   name: "Penny",   role: "Bookkeeper",     isLive: true),
        TeamHead(slug: "sam",     name: "Sam",     role: "Sales",          isLive: true),
        TeamHead(slug: "otto",    name: "Otto",    role: "Operations",     isLive: true),
        TeamHead(slug: "mia",     name: "Mia",     role: "Marketing",      isLive: true),
        TeamHead(slug: "yui",     name: "Yui",     role: "Comms", isLive: true),
        TeamHead(slug: "charlie", name: "Charlie", role: "Chief of Staff", isLive: true),
    ]
}

/// Only the brief fields the office can safely show offline; no client data beyond the existing brief.
private struct ReadyBriefSnapshot: Codable {
    struct Item: Codable {
        let key, head, kind, text: String
        let url, route: String?
        var item: HeadItem { HeadItem(key: key, head: head, kind: kind, text: text, url: url, route: route) }
    }
    let headline: String
    let owner: Bool
    let items: [Item]
    let savedAt: Date
    private static let key = "ittai.readyBrief.v1"

    init(response: HeadCardResponse, savedAt: Date) {
        headline = response.headline; owner = response.owner; self.savedAt = savedAt
        items = response.items.map { Item(key: $0.key, head: $0.head, kind: $0.kind, text: $0.text, url: $0.url, route: $0.route) }
    }
    static func load() -> Self? {
        UserDefaults.standard.data(forKey: key).flatMap { try? JSONDecoder().decode(Self.self, from: $0) }
    }
    func save() {
        if let data = try? JSONEncoder().encode(self) { UserDefaults.standard.set(data, forKey: Self.key) }
    }
}

struct TeamView: View {

    private static let timeFormatter: DateFormatter = {
        let f = DateFormatter(); f.dateStyle = .none; f.timeStyle = .short; return f
    }()

    @ObservedObject var authSession: AuthSession
    @ObservedObject var readyBriefStore: ReadyBriefStore
    @StateObject private var penny: PennyCardViewModel
    /// Penny's missing-receipt chaser — everyone's open items + Reassign (penny-chase-mobile).
    @StateObject private var chase: PennyChaseViewModel
    @StateObject private var businessIdentity: BusinessIdentityViewModel
    @StateObject private var sam: SamCardViewModel
    @StateObject private var otto: HeadCardViewModel
    @StateObject private var mia: HeadCardViewModel
    @StateObject private var yui: HeadCardViewModel
    @StateObject private var charlie: HeadCardViewModel
    /// Whose card is showing under the faces. Penny is the default.
    @State private var selected: String
    @State private var path: [String] = []
    /// Shows the profile row + Sign Out; off only for the static render (no AuthSession needed there).
    private let showsAccount: Bool
    @ObservedObject private var notificationRouter = NotificationRouter.shared
    /// Bumped when a push asks for a card, so the ScrollViewReader scrolls to it.
    @State private var scrollRequest = 0
    private static let cardAnchor = "mw-team-head-card"
    /// Every head's brain (team-mobile ?mode=brains): tapping a face flips it to show theirs.
    @State private var brains: [String: HeadBrainSummary] = [:]
    @State private var flipped: String?
    /// Charlie's server-cached brief supplies every Ittai row until that head's own card has loaded.
    @State private var readyBrief: HeadCardResponse?
    @State private var readyBriefLoaded = false
    @State private var readyBriefFailed = false
    @State private var dismissedBriefKeys: Set<String> = []
    @State private var readyBriefTime: Date?
    @State private var cachedBrief: ReadyBriefSnapshot?
    @ObservedObject private var connectivity = ConnectivityMonitor.shared
    @Environment(\.openURL) private var openURL
    @AppStorage("ittai.design.enabled") private var ittaiDesignEnabled = false
    @AppStorage("ittai.design.field") private var ittaiFieldEnabled = false

    init(authSession: AuthSession, readyBriefStore: ReadyBriefStore, api: BookkeeperDeskAPI? = nil, salesAPI: SalesDeskAPI? = nil,
         teamAPI: TeamHeadAPI? = nil, chaseAPI: PennyChaseAPI? = nil, geocoder: AddressGeocoder = AppleAddressGeocoder(),
         showsAccount: Bool = true, selected: String = "penny") {
        self.authSession = authSession
        self.readyBriefStore = readyBriefStore
        self.showsAccount = showsAccount
        let client = APIClient(authSession: authSession)
        let deskAPI = api ?? LiveBookkeeperDeskAPI(client: client)
        _penny = StateObject(wrappedValue: PennyCardViewModel(api: deskAPI))
        _chase = StateObject(wrappedValue: PennyChaseViewModel(api: chaseAPI ?? LivePennyChaseAPI(client: client)))
        _businessIdentity = StateObject(wrappedValue: BusinessIdentityViewModel(api: LiveBusinessIdentityAPI(client: client)))
        _sam = StateObject(wrappedValue: SamCardViewModel(api: salesAPI ?? LiveSalesDeskAPI(client: client)))
        let heads = teamAPI ?? LiveTeamHeadAPI(client: client)
        _otto = StateObject(wrappedValue: HeadCardViewModel(head: "otto", api: heads, geocoder: geocoder))
        _mia = StateObject(wrappedValue: HeadCardViewModel(head: "mia", api: heads, geocoder: geocoder))
        _yui = StateObject(wrappedValue: HeadCardViewModel(head: "yui", api: heads, geocoder: geocoder))
        _charlie = StateObject(wrappedValue: HeadCardViewModel(head: "charlie", api: heads, geocoder: geocoder))
        _selected = State(initialValue: selected)
        _cachedBrief = State(initialValue: ReadyBriefSnapshot.load())
    }

    var body: some View {
        NavigationStack(path: $path) {
            ScrollViewReader { proxy in
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    if ittaiDesignEnabled {
                        ittaiOffice
                            .ittaiTheme(field: ittaiFieldEnabled)
                            .id(Self.cardAnchor)
                    } else {
                        headsRow

                        VStack(alignment: .leading, spacing: 12) {
                            cardContent(for: selected, showsTitle: true)
                        }
                        .padding(16)
                        .background(Color(.systemBackground), in: RoundedRectangle(cornerRadius: 16))
                        .id(Self.cardAnchor)
                    }

                    if showsAccount {
                        VStack(alignment: .leading, spacing: 0) {
                            if let user = authSession.user {
                                AccountProfileRow(user: user)
                                    .padding(.horizontal, 16)
                                    .padding(.vertical, 10)
                                Divider().padding(.leading, 16)
                            }
                            ItDesignSettingsRows()
                                .padding(.horizontal, 16)
                                .padding(.vertical, 12)
                            Divider().padding(.leading, 16)
                            if authSession.user?.isAdmin == true {
                                NavigationLink {
                                    DiagnosticsView().environmentObject(authSession)
                                } label: {
                                    Label("Diagnostic log", systemImage: "doc.text.magnifyingglass")
                                        .frame(minHeight: 48)
                                }
                                .padding(.horizontal, 16)
                                Divider().padding(.leading, 16)
                            }
                            SignOutButton(authSession: authSession)
                                .padding(.horizontal, 16)
                                .padding(.vertical, 14)
                        }
                        .background(Color(.systemBackground), in: RoundedRectangle(cornerRadius: 16))
                    }
                }
                .padding(16)
            }
            .background {
                if ittaiDesignEnabled {
                    ItPageBackground().ittaiTheme(field: ittaiFieldEnabled)
                } else {
                    Color(.systemGroupedBackground)
                }
            }
            .scrollDismissesKeyboard(.interactively)
            .refreshable { await reload() }
            .navigationTitle("AI Office")
            .navigationBarTitleDisplayMode(.inline)
            .task { await loadBrains() }
            .task(id: ittaiDesignEnabled) {
                if ittaiDesignEnabled { await loadReadyBrief() }
            }
            .task(id: selected) {
                if let head = headVM {
                    if !head.hasLoaded && !head.isLoading { await head.load() }
                } else if selected == "sam" {
                    if !sam.hasLoaded && !sam.isLoading { await sam.load() }
                } else {
                    if !penny.hasLoaded && !penny.isLoading { await penny.load() }
                    if !chase.adminLoaded { await chase.loadAdmin() }
                }
            }
            .onAppear { consumeHeadRoute() }
            .onChange(of: notificationRouter.pendingHead) { _, _ in consumeHeadRoute() }
            .onChange(of: scrollRequest) { _, _ in
                if ittaiDesignEnabled {
                    let workspaceAnchor = "it-workspace-\(selected)"
                    DispatchQueue.main.async {
                        withAnimation { proxy.scrollTo(workspaceAnchor, anchor: .top) }
                    }
                } else {
                    withAnimation { proxy.scrollTo(Self.cardAnchor, anchor: .top) }
                }
            }
            // A head push while the app is open: refresh that card if it's the one showing.
            .onReceive(NotificationCenter.default.publisher(for: .mwHeadPushReceived)) { note in
                guard let slug = note.object as? String, slug == selected else { return }
                Task { await reload() }
            }
            .navigationDestination(for: String.self) { slug in
                ItWorkspaceView(
                    authSession: authSession,
                    businessIdentity: businessIdentity,
                    member: officeMemberForWorkspace(slug),
                    archetype: archetype(slug),
                    intro: intro(slug),
                    item: workspaceItem(slug),
                    recommendation: workspaceItem(slug).map { recommendation(for: $0, head: slug) },
                    brain: brain(for: slug),
                    loadState: workspaceLoadState(slug),
                    onRefresh: { await reload(slug: slug) }
                ) {
                    cardContent(for: slug, showsTitle: false)
                }
                .ittaiTheme(field: ittaiFieldEnabled)
                .background(ItPageBackground().ittaiTheme(field: ittaiFieldEnabled))
                .task { await loadHeadIfNeeded(slug) }
                .navigationTitle(TeamHead.all.first { $0.slug == slug }?.name ?? slug.capitalized)
                .navigationBarTitleDisplayMode(.inline)
            }
            }
        }
    }

    @ViewBuilder
    private func cardContent(for slug: String, showsTitle: Bool) -> some View {
        if let head = headVM(for: slug) {
            if showsTitle {
                HStack(spacing: 8) {
                    Text(headTitle(for: slug)).font(.title3.bold())
                    Spacer()
                    if head.isLoading && head.hasLoaded { ProgressView() }
                }
            }
            HeadCardView(vm: head, onBrainTap: { flip(slug) }, showsBrain: !ittaiDesignEnabled)
            if slug == "otto" && showsAccount {
                // Otto → Add equipment: snap the plate (EquipmentAddView, /api/team/equipment-mobile).
                OttoAddEquipmentButton(api: LiveEquipmentAPI(client: APIClient(authSession: authSession)))
            }
        } else if slug == "sam" {
            if showsTitle {
                HStack(spacing: 8) {
                    Text("Sam's sales desk").font(.title3.bold())
                    Spacer()
                    if sam.isLoading && sam.hasLoaded { ProgressView() }
                }
            }
            SamCardView(vm: sam)
        } else {
            if showsTitle {
                HStack(spacing: 8) {
                    Text("Penny's receipts").font(.title3.bold())
                    Spacer()
                    if penny.isLoading && penny.hasLoaded { ProgressView() }
                }
            }
            PennyLinesView(lines: penny.lines, hideMissing: !chase.adminItems.isEmpty)
            PennyChaseAdminSection(vm: chase)
            PennyCardView(vm: penny)
        }
    }

    private var ittaiOffice: some View {
        ItAIOfficeView(
            brief: liveReadyBrief.flatMap { $0.headline.isEmpty ? nil : $0.headline } ?? cachedBrief?.headline,
            briefTime: liveReadyBrief != nil
                ? readyBriefTime.map(Self.timeFormatter.string)
                : cachedBrief.map { cachedBriefLabel($0.savedAt) },
            charlie: officeMember("charlie", name: "Charlie", department: "Chief of Staff", vm: charlie),
            sides: [
                officeMember("yui", name: "Yui", department: "Communications", vm: yui),
                officeMember("otto", name: "Otto", department: "Operations", vm: otto),
                officeMember("mia", name: "Mia", department: "Marketing", vm: mia),
                penny.hasLoaded
                    ? ItOfficeMember(id: "penny", name: "Penny", department: "Bookkeeper", state: reportedState("penny"),
                                     stateReason: readyBrief?.heads?["penny"]?.reason,
                                     topItem: penny.queue.first.map { "\($0.vendor ?? "Receipt") to review" }, count: penny.queue.count,
                                     loaded: true, loadFailed: penny.loadError != nil)
                    : briefMember("penny", name: "Penny", department: "Bookkeeper"),
                sam.hasLoaded
                    ? ItOfficeMember(id: "sam", name: "Sam", department: "Sales", state: reportedState("sam"),
                                     stateReason: readyBrief?.heads?["sam"]?.reason,
                                     topItem: sam.queue.first.map { "\($0.name) · " + currency($0.amount) }, count: sam.queue.count,
                                     loaded: true, loadFailed: sam.loadError != nil)
                    : briefMember("sam", name: "Sam", department: "Sales"),
                ItOfficeMember(id: "ada", name: "Ada", department: "Technology", state: nil, available: false),
            ],
            selected: Binding(
                get: { selected },
                set: { value in
                    guard let value, value != "ada" else { return }
                    selected = value
                }
            ),
            briefLoadState: briefLoadState,
            priorityText: briefTopItem?.text,
            recommendation: briefTopItem.map { recommendation(for: $0, head: "charlie") },
            actionLabel: briefTopItem.map { openLabel(for: $0, head: "charlie") },
            canSnooze: (liveReadyBrief?.owner ?? cachedBrief?.owner) == true && briefTopItem != nil,
            actionsBusy: charlie.isBusy,
            actionsDisabled: liveReadyBrief == nil,
            onAction: openBriefPriority,
            onSnooze: snoozeBriefPriority,
            portraitURL: { slug in TeamHead.all.first { $0.slug == slug }?.faceURL },
            onOpen: openWorkspace,
            workspace: { _ in EmptyView() }
        )
    }

    private func officeMember(_ id: String, name: String, department: String,
                              vm: HeadCardViewModel) -> ItOfficeMember {
        guard vm.hasLoaded else { return briefMember(id, name: name, department: department) }
        let reported = readyBrief?.heads?[id]
        return ItOfficeMember(id: id, name: name, department: department,
                              state: reported?.state.flatMap(ItEmployeeState.init(rawValue:)),
                              stateReason: reported?.reason,
                              topItem: vm.items.first?.text, count: vm.card?.waiting,
                              loaded: true, loadFailed: vm.loadError != nil)
    }

    private func reportedState(_ id: String) -> ItEmployeeState? {
        readyBrief?.heads?[id]?.state.flatMap(ItEmployeeState.init(rawValue:))
    }

    private func briefMember(_ id: String, name: String, department: String) -> ItOfficeMember {
        let items = (liveReadyBrief?.items ?? cachedBrief?.items.map(\.item) ?? []).filter { ($0.route ?? $0.head) == id }
        let isCharlie = id == "charlie"
        let reported = liveReadyBrief?.heads?[id]
        return ItOfficeMember(id: id, name: name, department: department,
                              state: reported?.state.flatMap(ItEmployeeState.init(rawValue:)),
                              stateReason: reported?.reason,
                              topItem: isCharlie ? (liveReadyBrief?.items.first?.text ?? cachedBrief?.items.first?.text) : items.first?.text,
                              count: reported?.needsYou ?? (isCharlie ? (liveReadyBrief?.items.count ?? cachedBrief?.items.count) : items.count),
                              loaded: readyBriefLoaded || cachedBrief != nil, loadFailed: readyBriefFailed,
                              loadState: readyBriefFailed
                                ? (!connectivity.isOnline && cachedBrief != nil ? .offline(Self.timeFormatter.string(from: cachedBrief!.savedAt)) : .error)
                                : (!readyBriefLoaded && cachedBrief == nil ? .loading : nil))
    }

    private var briefTopItem: HeadItem? {
        (liveReadyBrief?.items ?? cachedBrief?.items.map(\.item) ?? []).first { !dismissedBriefKeys.contains($0.key) }
    }

    private var liveReadyBrief: HeadCardResponse? {
        readyBriefLoaded && !readyBriefFailed ? readyBrief : nil
    }

    private func cachedBriefLabel(_ date: Date) -> String {
        let time = Self.timeFormatter.string(from: date)
        if !connectivity.isOnline { return "Saved at \(time) · offline" }
        return readyBriefLoaded ? "Saved at \(time)" : "Saved at \(time) · updating…"
    }

    private var briefLoadState: ItLoadState? {
        if !readyBriefLoaded && cachedBrief == nil { return .loading }
        guard readyBriefFailed else { return nil }
        if !connectivity.isOnline, let cachedBrief { return .offline(Self.timeFormatter.string(from: cachedBrief.savedAt)) }
        return .error
    }

    private func openWorkspace(_ slug: String) {
        guard slug != "ada", !path.contains(slug) else { return }
        selected = slug
        path.append(slug)
    }

    private func openBriefPriority() {
        guard liveReadyBrief != nil, let item = briefTopItem, !charlie.isBusy else { return }
        Task {
            if let url = await charlie.open(item, owner: readyBrief?.owner == true) { openURL(url) }
        }
    }

    private func snoozeBriefPriority() {
        guard let item = briefTopItem, liveReadyBrief?.owner == true, !charlie.isBusy else { return }
        Task {
            if await charlie.notNowResult(item) { dismissedBriefKeys.insert(item.key) }
        }
    }

    private func openLabel(for item: HeadItem, head: String) -> String {
        if item.kind == "campaign_reply" || item.kind.hasSuffix(":campaign_reply") { return "Start the quote" }
        if head == "yui" { return "Open client" }
        return "Open in CRM"
    }

    private func recommendation(for item: HeadItem, head: String) -> String {
        switch openLabel(for: item, head: head) {
        case "Start the quote": return "Start the quote and check the details before you send anything."
        case "Open client": return "Open the client in the CRM and decide what to do."
        default:
            let noun = item.url?.lowercased().contains("invoice") == true ? "invoice" :
                (item.url?.lowercased().contains("quote") == true ? "quote" : "item")
            return "Open the \(noun) in the CRM and decide what to do."
        }
    }

    private func workspaceItem(_ slug: String) -> HeadItem? {
        if let vm = headVM(for: slug), vm.hasLoaded { return vm.items.first }
        return readyBrief?.items.first { ($0.route ?? $0.head) == slug }
    }

    private func brain(for slug: String) -> HeadBrainSummary? {
        headVM(for: slug)?.card?.brain ?? brains[slug]
    }

    private func headVM(for slug: String) -> HeadCardViewModel? {
        switch slug { case "otto": return otto; case "mia": return mia; case "yui": return yui; case "charlie": return charlie; default: return nil }
    }

    private func loadHeadIfNeeded(_ slug: String) async {
        if let vm = headVM(for: slug) {
            if !vm.hasLoaded && !vm.isLoading { await vm.load() }
        } else if slug == "sam" {
            if !sam.hasLoaded && !sam.isLoading { await sam.load() }
        } else if slug == "penny" {
            if !penny.hasLoaded && !penny.isLoading { await penny.load() }
            if !chase.adminLoaded { await chase.loadAdmin() }
        }
    }

    private func workspaceLoadState(_ slug: String) -> ItLoadState? {
        if slug == "ada" { return .unavailable }
        if let vm = headVM(for: slug) {
            if !vm.hasLoaded || (vm.isLoading && vm.card == nil) { return .loading }
            if vm.loadError != nil && vm.card == nil { return offlineOrError }
        } else if slug == "sam" {
            if !sam.hasLoaded { return sam.loadError == nil ? .loading : offlineOrError }
        } else if slug == "penny" {
            if !penny.hasLoaded { return penny.loadError == nil ? .loading : offlineOrError }
        }
        return nil
    }

    private var offlineOrError: ItLoadState {
        if !connectivity.isOnline, let cachedBrief { return .offline(Self.timeFormatter.string(from: cachedBrief.savedAt)) }
        return .error
    }

    private func officeMemberForWorkspace(_ slug: String) -> ItOfficeMember {
        let h = TeamHead.all.first { $0.slug == slug }
        if let vm = headVM(for: slug) {
            return officeMember(slug, name: h?.name ?? slug.capitalized, department: h?.role ?? "", vm: vm)
        }
        let reported = readyBrief?.heads?[slug]
        let loaded = slug == "sam" ? sam.hasLoaded : penny.hasLoaded
        return ItOfficeMember(id: slug, name: h?.name ?? slug.capitalized, department: h?.role ?? "",
                              state: reported?.state.flatMap(ItEmployeeState.init(rawValue:)),
                              stateReason: reported?.reason, topItem: workspaceItem(slug)?.text,
                              loaded: loaded, loadFailed: workspaceLoadState(slug) == .error)
    }

    private func archetype(_ slug: String) -> String {
        ["charlie": "Kaname · 要 — the linchpin", "yui": "Musubi · 結び — the connector",
         "sam": "Kaitaku · 開拓 — the pathfinder", "penny": "Kanjō · 勘定 — the steward",
         "otto": "Dandori · 段取り — the orchestrator", "mia": "Hasshin · 発信 — the voice",
         "ada": "Kiban · 基盤 — the foundation"][slug] ?? ""
    }

    private func intro(_ slug: String) -> String {
        ["charlie": "I keep the office aligned and bring you the decision that matters most. I'll explain each step before you act.",
         "yui": "I look after customer messages. When a reply needs you, I'll show you what happened and where it will open.",
         "sam": "I look after your sales work. When an opportunity needs you, I'll walk you through the next step.",
         "penny": "I look after your receipts and your books. When something needs you, I'll walk you through it one step at a time.",
         "otto": "I look after the day's operations. When a job needs your decision, I'll explain what will happen next.",
         "mia": "I look after how the business speaks to customers. When something needs you, I'll help you review it carefully.",
         "ada": "I look after the systems underneath the office. I'll explain changes before they affect your work."][slug] ?? ""
    }

    private func currency(_ amount: Double) -> String {
        amount.formatted(.currency(code: "CAD").precision(.fractionLength(amount.rounded() == amount ? 0 : 2)))
    }

    private func loadReadyBrief() async {
        guard showsAccount else { return }
        readyBriefLoaded = false
        readyBriefFailed = false
        await readyBriefStore.refresh(authSession: authSession)
        if let response = readyBriefStore.response {
                readyBrief = response
                readyBriefTime = readyBriefStore.updatedAt
                let snapshot = ReadyBriefSnapshot(response: response, savedAt: readyBriefStore.updatedAt ?? Date())
                cachedBrief = snapshot
                snapshot.save()
        }
        readyBriefFailed = readyBriefStore.failed
        readyBriefLoaded = true
    }

    /// Tap a face: it turns over to show that head's brain for a few seconds, then back.
    private func flip(_ slug: String) {
        withAnimation(.easeInOut(duration: 0.5)) { flipped = slug }
        Task {
            if brains.isEmpty { await loadBrains() }
            try? await Task.sleep(nanoseconds: 3_200_000_000)
            if flipped == slug { withAnimation(.easeInOut(duration: 0.5)) { flipped = nil } }
        }
    }

    private func loadBrains() async {
        guard showsAccount else { return }   // the static render has no session
        if let r: TeamBrainsResponse = try? await APIClient(authSession: authSession).request(.teamBrains), r.ok {
            brains = r.brains
        }
    }

    /// Follow a tapped department-head push: show that head, reload, scroll the card into view.
    private func consumeHeadRoute() {
        guard let route = notificationRouter.pendingHead else { return }
        notificationRouter.pendingHead = nil
        guard TeamHead.all.contains(where: { $0.slug == route.head && $0.isLive }) else { return }
        selected = route.head
        if ittaiDesignEnabled { path = [route.head] }
        scrollRequest += 1
        Task { await reload() }
    }

    private func reload() async {
        if ittaiDesignEnabled {
            await readyBriefStore.refresh(authSession: authSession, force: true)
            await loadReadyBrief()
            await reloadSelected()
            return
        }
        await reloadSelected()
    }

    private func reloadSelected() async {
        await reload(slug: selected)
    }

    private func reload(slug: String) async {
        if let head = headVM(for: slug) { await head.load() } else if slug == "sam" { await sam.load() } else {
            await penny.load()
            await chase.loadAdmin()
        }
    }

    /// The shared head card's model for the selected face (nil for Penny and Sam).
    private var headVM: HeadCardViewModel? {
        switch selected {
        case "otto":    return otto
        case "mia":     return mia
        case "yui":     return yui
        case "charlie": return charlie
        default:        return nil
        }
    }

    private func headTitle(for slug: String) -> String {
        guard let h = TeamHead.all.first(where: { $0.slug == slug }) else { return "" }
        return "\(h.name) · \(h.role)"
    }

    // MARK: - Faces

    private var headsRow: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(alignment: .top, spacing: 14) {
                ForEach(TeamHead.all) { head in
                    Button {
                        if head.isLive { selected = head.slug; flip(head.slug) }
                    } label: {
                        HeadFace(head: head, isSelected: head.slug == selected,
                                 brain: brains[head.slug], flipped: flipped == head.slug)
                    }
                    .buttonStyle(.plain)
                    .disabled(!head.isLive)
                }
            }
            .padding(.horizontal, 2)
            .padding(.vertical, 2)
        }
    }
}

private struct ItPageBackground: View {
    @Environment(\.it) private var it

    var body: some View {
        it.page
    }
}

private struct ItWorkspaceView<Content: View>: View {
    let authSession: AuthSession
    @ObservedObject var businessIdentity: BusinessIdentityViewModel
    let member: ItOfficeMember
    let archetype: String
    let intro: String
    let item: HeadItem?
    let recommendation: String?
    let brain: HeadBrainSummary?
    let loadState: ItLoadState?
    let onRefresh: () async -> Void
    @ViewBuilder let content: Content
    @Environment(\.it) private var it
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @State private var knowledgeReloadID = 0

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: ItSpace.s6) {
                header
                if let loadState {
                    ItLoadStateLine(state: loadState).padding(.vertical, ItSpace.s5)
                } else {
                    guide
                    if member.id == "penny" { PennyKnowledgeLiveView(api: LiveKnowledgeAPI(client: APIClient(authSession: authSession)))
                            .id(knowledgeReloadID) }
                    else { learning }
                    content
                    onboardingSection
                }
            }
            .padding(16)
        }
        .refreshable {
            await onRefresh()
            if member.id == "penny" {
                knowledgeReloadID += 1
                await businessIdentity.load()
            }
        }
        .task { if member.id == "penny" { await businessIdentity.load() } }
    }

    @ViewBuilder private var onboardingSection: some View {
        if member.id == "penny" {
        VStack(alignment: .leading, spacing: ItSpace.s3) {
            Text("Getting to know your business").itText(.heading18).foregroundStyle(it.text)
            businessIdentitySection
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    @ViewBuilder private var businessIdentitySection: some View {
        let stage = businessIdentity.current?.knowledge.stage
        if businessIdentity.current == nil {
            if businessIdentity.isBusy {
                ProgressView("Loading business details")
            } else {
                Text(businessIdentity.error ?? "No business-identity response has been loaded.")
                    .itText(.body14).foregroundStyle(it.meta)
                Button("Try again") { Task { await businessIdentity.load() } }
                    .buttonStyle(.it(.ghost))
            }
        } else if businessIdentity.isEditing {
            onboarding
        } else if case .confirmed = businessIdentity.state {
            onboarding
        } else if businessIdentity.current?.identity == nil || stage == .recognized || businessIdentity.current == nil {
            onboarding
        } else if let identity = businessIdentity.current?.identity {
            ItBusinessIdentityRow(identity: identity,
                                  canEdit: businessIdentity.current?.canEdit == true,
                                  onChange: businessIdentity.restart)
        }
    }

    private var onboarding: some View {
        VStack(alignment: .leading, spacing: ItSpace.s2) {
            ItPennyOnboarding(state: businessIdentity.state,
                              stage: businessIdentity.current?.knowledge.stage,
                              attribution: businessIdentity.attribution,
                              isBusy: businessIdentity.isBusy,
                              canEdit: businessIdentity.current?.canEdit == true,
                              initialName: businessIdentity.enteredName,
                              showsOtherMatches: businessIdentity.hasOtherMatches,
                              error: businessIdentity.error,
                              contactsState: businessIdentity.contactsState,
                              onStart: { value in Task { await businessIdentity.start(value) } },
                              onRetry: { Task { await businessIdentity.searchAgain() } },
                              onShowOtherMatches: businessIdentity.showOtherMatches,
                              onSelect: businessIdentity.select,
                              onConfirm: { match in Task { await businessIdentity.confirm(match) } },
                              onManual: { legal, operating, structure, jurisdiction in
                                  Task { await businessIdentity.manual(legalName: legal, operatingName: operating,
                                                                       legalStructure: structure, jurisdiction: jurisdiction) }
                              })
        }
    }

    private var header: some View {
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: ItSpace.s3) {
                    workspacePortrait
                    workspaceIdentity
                }
            } else {
                HStack(spacing: ItSpace.s3) {
                    workspacePortrait
                    workspaceIdentity
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .foregroundStyle(it.text)
        .accessibilityElement(children: .combine)
    }

    private var workspacePortrait: some View {
        ItPortrait(name: member.name,
                   url: TeamHead.all.first { $0.slug == member.id }?.faceURL,
                   state: member.state, size: 56)
    }

    private var workspaceIdentity: some View {
        VStack(alignment: .leading, spacing: 2) {
            HStack(spacing: 6) {
                Text(member.name).itText(.title22, weight: 600)
                    .fixedSize(horizontal: false, vertical: true)
                ItAITag()
            }
            Text(member.department).itText(.meta13).foregroundStyle(it.meta)
                .fixedSize(horizontal: false, vertical: true)
            Text(archetype).itText(.meta13).foregroundStyle(it.meta)
                .fixedSize(horizontal: false, vertical: true)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private var guide: some View {
        VStack(alignment: .leading, spacing: ItSpace.s4) {
            Text(intro).itText(.body16).foregroundStyle(it.text)
            if let item, let recommendation {
                guideStep(1, "What happened", item.text)
                guideStep(2, "What I recommend", recommendation)
                let label = actionLabel(item)
                guideStep(3, "When you tap \(label)", actionExplanation(label))
                guideStep(4, "Not ready?", "Not now brings it back tomorrow at 8.")
            } else {
                Text("Nothing needs you right now.").itText(.body16).foregroundStyle(it.meta)
            }
        }
        .padding(ItSpace.s4).background(it.surface, in: RoundedRectangle(cornerRadius: ItRadius.lg))
    }

    private func guideStep(_ n: Int, _ title: String, _ words: String) -> some View {
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: ItSpace.s2) {
                    guideNumber(n)
                    guideWords(title, words)
                }
            } else {
                HStack(alignment: .top, spacing: ItSpace.s3) {
                    guideNumber(n)
                    guideWords(title, words)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .foregroundStyle(it.text)
    }

    private func guideNumber(_ n: Int) -> some View {
        Text("\(n)").itText(.body14, weight: 600).frame(minWidth: 24, minHeight: 24)
            .background(it.sunk, in: Circle())
    }

    private func guideWords(_ title: String, _ words: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title).itText(.body14, weight: 600)
                .fixedSize(horizontal: false, vertical: true)
            Text(words).itText(.body14).foregroundStyle(it.meta)
                .fixedSize(horizontal: false, vertical: true)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    @ViewBuilder private var learning: some View {
        if let brain {
            let strongest = brain.parts.max { lhs, rhs in
                (brain.tiers.first { $0.slug == lhs.tier }?.n ?? 0) < (brain.tiers.first { $0.slug == rhs.tier }?.n ?? 0)
            }?.label.lowercased() ?? "the work you do most"
            DisclosureGroup {
                VStack(alignment: .leading, spacing: ItSpace.s3) {
                    BrainView(units: brain.units,
                              tiers: Dictionary(brain.tiers.map { ($0.slug, $0.n) }, uniquingKeysWith: +),
                              label: "\(member.name)'s brain", onLight: it.mode != .dark)
                        .frame(height: 150)
                    Text(brain.units == 0 ? "Nothing learned yet" : "Shape \(brain.shape)").itText(.caption12).foregroundStyle(it.meta)
                    ForEach(brain.tiers, id: \.self) { tier in
                        Text("\(tier.name): \(tier.n)").itText(.body14).foregroundStyle(it.meta)
                    }
                }.padding(.top, ItSpace.s3)
            } label: {
                Text("\(member.name) has learned \(brain.units) things about how you work — mostly \(strongest).  See details")
                    .itText(.body14).foregroundStyle(it.text)
            }
        }
    }

    private func actionLabel(_ item: HeadItem) -> String {
        if item.kind == "campaign_reply" || item.kind.hasSuffix(":campaign_reply") { return "Start the quote" }
        if member.id == "yui" { return "Open client" }
        return "Open in CRM"
    }

    private func actionExplanation(_ label: String) -> String {
        switch label {
        case "Start the quote": return "It opens a quote in the CRM. Nothing is sent to the customer."
        case "Open client": return "It opens the client's record in the CRM. Nothing is sent to the customer."
        default:
            let noun = item?.url?.lowercased().contains("invoice") == true ? "invoice" : "item"
            return "It opens the \(noun) in the CRM. Nothing is sent to the customer."
        }
    }
}

private struct PennyKnowledgeLiveView: View {
    let api: KnowledgeAPI
    @ObservedObject private var connectivity = ConnectivityMonitor.shared
    @State private var response: KnowledgeResponse?
    @State private var loading = true
    @State private var failed = false

    var body: some View {
        Group {
            if let response {
                if response.items.isEmpty {
                    Text("Penny hasn't met any vendors yet.").itText(.body14)
                } else {
                    ItKnowledgeBrain(response: response) { key in
                        try await api.items(key: key).items
                    }
                }
            } else if loading {
                ItLoadStateLine(state: .loading)
            } else if !connectivity.isOnline {
                Text("You're offline. Penny's knowledge is not saved on this phone.").itText(.body14)
            } else if failed {
                ItLoadStateLine(state: .error)
            }
        }
        .task {
            guard response == nil else { return }
            loading = true
            failed = false
            do { response = try await api.knowledge() }
            catch { failed = true }
            loading = false
        }
    }
}

private struct HeadFace: View {
    let head: TeamHead
    var isSelected = false
    var brain: HeadBrainSummary? = nil
    var flipped = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        VStack(spacing: 4) {
            ZStack {
                AsyncImage(url: head.faceURL) { phase in
                    if let img = phase.image {
                        img.resizable().scaledToFill()
                    } else {
                        Text(String(head.name.prefix(1)))
                            .font(.title2.bold())
                            .foregroundStyle(.white)
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                            .background(Color.MW.dark)
                    }
                }
                .frame(width: 62, height: 62)
                .clipShape(Circle())
                // strokeBorder keeps the ring inside the face: a centred stroke poked 2 pt past the
                // frame and the scroll view clipped its top (Penny, 2026-10-10).
                .overlay(Circle().strokeBorder(isSelected ? Color.MW.lime : (head.isLive ? Color.MW.lime.opacity(0.35) : Color.clear),
                                         lineWidth: isSelected ? 4 : 2))
                    .opacity(flipped ? 0 : 1)
                // The back of the face: their brain (nothing learned yet = the seed triangle).
                ZStack {
                    Circle().fill(Color.MW.light)
                    if flipped {
                        BrainView(units: brain?.units ?? 0,
                                  tiers: Dictionary((brain?.tiers ?? []).map { ($0.slug, $0.n) }, uniquingKeysWith: +),
                                  label: "\(head.name)'s brain", onLight: true)
                            .padding(4)
                    }
                }
                .frame(width: 62, height: 62)
                .clipShape(Circle())
                .overlay(Circle().strokeBorder(Color.MW.lime, lineWidth: 4))
                .rotation3DEffect(.degrees(reduceMotion ? 0 : 180), axis: (x: 0, y: 1, z: 0))
                .opacity(flipped ? 1 : 0)
            }
            .rotation3DEffect(.degrees(flipped && !reduceMotion ? 180 : 0), axis: (x: 0, y: 1, z: 0))
            .grayscale(head.isLive ? 0 : 1)
            .opacity(head.isLive ? 1 : 0.45)

            Text(head.name)
                .font(.caption.weight(head.isLive ? .bold : .regular))
                .foregroundStyle(head.isLive ? .primary : .secondary)
            Text(head.isLive ? head.role : "Coming soon")
                .font(.system(size: 10))
                .lineLimit(1)
                .minimumScaleFactor(0.8)
                .foregroundStyle(head.isLive ? Color.MW.green : .secondary)
        }
        .frame(width: 76)
        .accessibilityElement(children: .combine)
        .accessibilityLabel(head.isLive ? "\(head.name), \(head.role)" : "\(head.name), \(head.role), coming soon")
        .accessibilityAddTraits(isSelected ? .isSelected : [])
    }
}
