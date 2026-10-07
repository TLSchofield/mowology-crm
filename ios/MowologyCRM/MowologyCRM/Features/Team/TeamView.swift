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
        TeamHead(slug: "yui",     name: "Yui",     role: "Communications", isLive: true),
        TeamHead(slug: "charlie", name: "Charlie", role: "Chief of Staff", isLive: true),
    ]
}

struct TeamView: View {

    @ObservedObject var authSession: AuthSession
    @StateObject private var penny: PennyCardViewModel
    @StateObject private var sam: SamCardViewModel
    @StateObject private var otto: HeadCardViewModel
    @StateObject private var mia: HeadCardViewModel
    @StateObject private var yui: HeadCardViewModel
    @StateObject private var charlie: HeadCardViewModel
    /// Whose card is showing under the faces. Penny is the default.
    @State private var selected: String
    /// Shows the profile row + Sign Out; off only for the static render (no AuthSession needed there).
    private let showsAccount: Bool
    @ObservedObject private var notificationRouter = NotificationRouter.shared
    /// Bumped when a push asks for a card, so the ScrollViewReader scrolls to it.
    @State private var scrollRequest = 0
    private static let cardAnchor = "mw-team-head-card"

    init(authSession: AuthSession, api: BookkeeperDeskAPI? = nil, salesAPI: SalesDeskAPI? = nil,
         teamAPI: TeamHeadAPI? = nil, geocoder: AddressGeocoder = AppleAddressGeocoder(),
         showsAccount: Bool = true, selected: String = "penny") {
        self.authSession = authSession
        self.showsAccount = showsAccount
        let client = APIClient(authSession: authSession)
        let deskAPI = api ?? LiveBookkeeperDeskAPI(client: client)
        _penny = StateObject(wrappedValue: PennyCardViewModel(api: deskAPI))
        _sam = StateObject(wrappedValue: SamCardViewModel(api: salesAPI ?? LiveSalesDeskAPI(client: client)))
        let heads = teamAPI ?? LiveTeamHeadAPI(client: client)
        _otto = StateObject(wrappedValue: HeadCardViewModel(head: "otto", api: heads, geocoder: geocoder))
        _mia = StateObject(wrappedValue: HeadCardViewModel(head: "mia", api: heads, geocoder: geocoder))
        _yui = StateObject(wrappedValue: HeadCardViewModel(head: "yui", api: heads, geocoder: geocoder))
        _charlie = StateObject(wrappedValue: HeadCardViewModel(head: "charlie", api: heads, geocoder: geocoder))
        _selected = State(initialValue: selected)
    }

    var body: some View {
        NavigationStack {
            ScrollViewReader { proxy in
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    headsRow

                    VStack(alignment: .leading, spacing: 12) {
                        if let head = headVM {
                            HStack(spacing: 8) {
                                Text(headTitle).font(.title3.bold())
                                Spacer()
                                if head.isLoading && head.hasLoaded { ProgressView() }
                            }
                            HeadCardView(vm: head)
                        } else if selected == "sam" {
                            HStack(spacing: 8) {
                                Text("Sam's sales desk").font(.title3.bold())
                                Spacer()
                                if sam.isLoading && sam.hasLoaded { ProgressView() }
                            }
                            SamCardView(vm: sam)
                        } else {
                            HStack(spacing: 8) {
                                Text("Penny's receipts").font(.title3.bold())
                                Spacer()
                                if penny.isLoading && penny.hasLoaded { ProgressView() }
                            }
                            PennyCardView(vm: penny)
                        }
                    }
                    .padding(16)
                    .background(Color(.systemBackground), in: RoundedRectangle(cornerRadius: 16))
                    .id(Self.cardAnchor)

                    if showsAccount {
                        VStack(alignment: .leading, spacing: 0) {
                            if let user = authSession.user {
                                AccountProfileRow(user: user)
                                    .padding(.horizontal, 16)
                                    .padding(.vertical, 10)
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
            .background(Color(.systemGroupedBackground))
            .scrollDismissesKeyboard(.interactively)
            .refreshable { await reload() }
            .navigationTitle("Team")
            .navigationBarTitleDisplayMode(.inline)
            .task(id: selected) {
                if let head = headVM {
                    if !head.hasLoaded { await head.load() }
                } else if selected == "sam" {
                    if !sam.hasLoaded { await sam.load() }
                } else if !penny.hasLoaded {
                    await penny.load()
                }
            }
            .onAppear { consumeHeadRoute() }
            .onChange(of: notificationRouter.pendingHead) { _, _ in consumeHeadRoute() }
            .onChange(of: scrollRequest) { _, _ in
                withAnimation { proxy.scrollTo(Self.cardAnchor, anchor: .top) }
            }
            // A head push while the app is open: refresh that card if it's the one showing.
            .onReceive(NotificationCenter.default.publisher(for: .mwHeadPushReceived)) { note in
                guard let slug = note.object as? String, slug == selected else { return }
                Task { await reload() }
            }
            }
        }
    }

    /// Follow a tapped department-head push: show that head, reload, scroll the card into view.
    private func consumeHeadRoute() {
        guard let route = notificationRouter.pendingHead else { return }
        notificationRouter.pendingHead = nil
        guard TeamHead.all.contains(where: { $0.slug == route.head && $0.isLive }) else { return }
        selected = route.head
        scrollRequest += 1
        Task { await reload() }
    }

    private func reload() async {
        if let head = headVM { await head.load() } else if selected == "sam" { await sam.load() } else { await penny.load() }
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

    private var headTitle: String {
        guard let h = TeamHead.all.first(where: { $0.slug == selected }) else { return "" }
        return "\(h.name) · \(h.role)"
    }

    // MARK: - Faces

    private var headsRow: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(alignment: .top, spacing: 14) {
                ForEach(TeamHead.all) { head in
                    Button {
                        if head.isLive { selected = head.slug }
                    } label: {
                        HeadFace(head: head, isSelected: head.slug == selected)
                    }
                    .buttonStyle(.plain)
                    .disabled(!head.isLive)
                }
            }
            .padding(.horizontal, 2)
        }
    }
}

private struct HeadFace: View {
    let head: TeamHead
    var isSelected = false

    var body: some View {
        VStack(spacing: 4) {
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
            .overlay(Circle().stroke(isSelected ? Color.MW.lime : (head.isLive ? Color.MW.lime.opacity(0.35) : Color.clear),
                                     lineWidth: isSelected ? 4 : 2))
            .grayscale(head.isLive ? 0 : 1)
            .opacity(head.isLive ? 1 : 0.45)

            Text(head.name)
                .font(.caption.weight(head.isLive ? .bold : .regular))
                .foregroundStyle(head.isLive ? .primary : .secondary)
            Text(head.isLive ? head.role : "Coming soon")
                .font(.system(size: 10))
                .foregroundStyle(head.isLive ? Color.MW.green : .secondary)
        }
        .frame(width: 76)
        .accessibilityElement(children: .combine)
        .accessibilityLabel(head.isLive ? "\(head.name), \(head.role)" : "\(head.name), \(head.role), coming soon")
        .accessibilityAddTraits(isSelected ? .isSelected : [])
    }
}
