//
//  TeamView.swift
//  MowologyCRM
//
//  The admin's "Team" tab (replaces Account for admins): the department heads across
//  the top — Penny is working, the rest are coming — Penny's receipt card under them,
//  and the profile row and Sign Out (moved here from Account) at the bottom.
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
        TeamHead(slug: "sam",     name: "Sam",     role: "Sales",          isLive: false),
        TeamHead(slug: "otto",    name: "Otto",    role: "Operations",     isLive: false),
        TeamHead(slug: "mia",     name: "Mia",     role: "Marketing",      isLive: false),
        TeamHead(slug: "yui",     name: "Yui",     role: "Communications", isLive: false),
        TeamHead(slug: "charlie", name: "Charlie", role: "Chief of Staff", isLive: false),
    ]
}

struct TeamView: View {

    @ObservedObject var authSession: AuthSession
    @StateObject private var penny: PennyCardViewModel
    /// Shows the profile row + Sign Out; off only for the static render (no AuthSession needed there).
    private let showsAccount: Bool

    init(authSession: AuthSession, api: BookkeeperDeskAPI? = nil, showsAccount: Bool = true) {
        self.authSession = authSession
        self.showsAccount = showsAccount
        let deskAPI = api ?? LiveBookkeeperDeskAPI(client: APIClient(authSession: authSession))
        _penny = StateObject(wrappedValue: PennyCardViewModel(api: deskAPI))
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    headsRow

                    VStack(alignment: .leading, spacing: 12) {
                        HStack(spacing: 8) {
                            Text("Penny's receipts").font(.title3.bold())
                            Spacer()
                            if penny.isLoading && penny.hasLoaded { ProgressView() }
                        }
                        PennyCardView(vm: penny)
                    }
                    .padding(16)
                    .background(Color(.systemBackground), in: RoundedRectangle(cornerRadius: 16))

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
            .refreshable { await penny.load() }
            .navigationTitle("Team")
            .navigationBarTitleDisplayMode(.inline)
            .task {
                if !penny.hasLoaded { await penny.load() }
            }
        }
    }

    // MARK: - Faces

    private var headsRow: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(alignment: .top, spacing: 14) {
                ForEach(TeamHead.all) { head in
                    HeadFace(head: head)
                }
            }
            .padding(.horizontal, 2)
        }
    }
}

private struct HeadFace: View {
    let head: TeamHead

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
            .overlay(Circle().stroke(head.isLive ? Color.MW.lime : Color.clear, lineWidth: 3))
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
    }
}
