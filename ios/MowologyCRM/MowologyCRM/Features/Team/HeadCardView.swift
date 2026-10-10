//
//  HeadCardView.swift
//  MowologyCRM
//
//  One card for Charlie, Otto, Mia or Yui on the Team tab, top to bottom: the head's line
//  and brain, Charlie's Ask box (Charlie only), the top 3 from the dashboard Action Board
//  with Open / Not now, then the head's single action — Otto's Geocode, Mia's Google post.
//  Yui's items open the client; her replies stay drafts.
//

import SwiftUI
import UIKit

struct HeadCardView: View {

    @ObservedObject var vm: HeadCardViewModel
    @Environment(\.openURL) private var openURL
    @FocusState private var askFocused: Bool
    @State private var confirmPublish = false

    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            if vm.isLoading && !vm.hasLoaded {
                HStack { Spacer(); ProgressView("Loading…"); Spacer() }
                    .padding(.vertical, 40)
            } else if let err = vm.loadError, vm.card == nil {
                errorState(err)
            } else if let card = vm.card {
                header(card)
                if vm.head == "charlie" { askSection }
                note
                itemsSection(card)
                if vm.head == "otto" {
                    OttoDeskSection(vm: vm)
                    unpinnedSection
                }
                if vm.head == "mia" { postSection }
            }
        }
    }

    // MARK: - States

    private func errorState(_ err: String) -> some View {
        VStack(spacing: 10) {
            Label(err, systemImage: "exclamationmark.triangle.fill")
                .font(.subheadline)
                .foregroundStyle(.red)
                .multilineTextAlignment(.center)
            Button("Try again") { Task { await vm.load() } }
                .buttonStyle(.bordered)
                .tint(Color.MW.green)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 24)
    }

    private func sectionHead(_ title: String, _ sub: String? = nil) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title).font(.headline)
            if let sub { Text(sub).font(.caption).foregroundStyle(.secondary) }
        }
    }

    @ViewBuilder
    private var note: some View {
        if let text = vm.message, !text.isEmpty {
            Text(text)
                .font(.footnote)
                .foregroundStyle(vm.messageIsError ? .red : Color.MW.green)
                .fixedSize(horizontal: false, vertical: true)
        }
    }

    // MARK: - Header + brain

    private func header(_ card: HeadCardResponse) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            if !card.headline.isEmpty {
                Text(card.headline)
                    .font(.subheadline)
                    .fixedSize(horizontal: false, vertical: true)
            }
            if let b = card.brain { brainRow(b, name: card.name) }
        }
    }

    /// The head's own turning brain sits beside the summary (owner, 2026-10-10: on brand, and
    /// smaller than a full-width graphic) — the same shape the splash screen and the web draw.
    private func brainRow(_ b: HeadBrainSummary, name: String) -> some View {
        HStack(alignment: .top, spacing: 10) {
            brainText(b, name: name)
            BrainView(units: b.units, tiers: Dictionary(b.tiers.map { ($0.slug, $0.n) }, uniquingKeysWith: +),
                      label: "\(name)'s brain")
                .frame(width: 84, height: 84)
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.MW.light, in: RoundedRectangle(cornerRadius: 10))
    }

    private func brainText(_ b: HeadBrainSummary, name: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 8) {
                Text(b.units == 0 ? "\(name)'s brain: just starting" : "\(name)'s brain: \(b.units) thing\(b.units == 1 ? "" : "s") learned")
                    .font(.caption.weight(.semibold))
                Spacer(minLength: 0)
                Text("shape \(b.shape)").font(.caption2).foregroundStyle(.secondary)
            }
            if !b.tiers.isEmpty {
                HStack(spacing: 6) {
                    ForEach(b.tiers, id: \.self) { t in
                        Text("\(t.n) \(t.name)")
                            .font(.system(size: 10, weight: .semibold))
                            .padding(.horizontal, 6).padding(.vertical, 2)
                            .background(Self.tierColor(t.slug).opacity(0.18), in: Capsule())
                            .foregroundStyle(Self.tierColor(t.slug))
                    }
                }
            }
            ForEach(b.parts, id: \.self) { p in
                Text("· " + p.label + (p.tier.map { " (\($0))" } ?? ""))
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    /// HeadBrain::TIERS, as head-brain.js colours them (approximately).
    static func tierColor(_ slug: String) -> Color {
        switch slug {
        case "platinum": return Color(red: 0.35, green: 0.55, blue: 0.75)
        case "white":    return Color(red: 0.45, green: 0.45, blue: 0.5)
        case "gold":     return Color(red: 0.75, green: 0.55, blue: 0.05)
        case "silver":   return Color(red: 0.45, green: 0.5, blue: 0.55)
        case "bronze":   return Color(red: 0.65, green: 0.4, blue: 0.2)
        default:         return Color.primary
        }
    }

    // MARK: - Charlie's Ask box

    private var askSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            sectionHead("Ask Charlie", "about a customer, a quote, an invoice or a visit")
            HStack(alignment: .bottom, spacing: 8) {
                TextField("Has Linda's quote been sent?", text: $vm.question, axis: .vertical)
                    .lineLimit(1...4)
                    .textFieldStyle(.roundedBorder)
                    .focused($askFocused)
                    .submitLabel(.send)
                    .onSubmit { Task { await vm.submitQuestion() } }
                    .disabled(vm.ask?.ready != true)
                Button {
                    askFocused = false
                    Task { await vm.submitQuestion() }
                } label: {
                    if vm.isAsking { ProgressView() } else { Image(systemName: "arrow.up.circle.fill").font(.title2) }
                }
                .disabled(!vm.canAsk)
                .tint(Color.MW.green)
                .accessibilityLabel("Ask Charlie")
            }
            if let left = vm.askLeftText {
                Text(left).font(.caption2).foregroundStyle(.secondary)
            }
            if let a = vm.answer {
                VStack(alignment: .leading, spacing: 4) {
                    if let q = vm.askedQuestion {
                        Text(q).font(.caption.weight(.semibold)).foregroundStyle(.secondary)
                    }
                    Text(a)
                        .font(.subheadline)
                        .textSelection(.enabled)
                        .fixedSize(horizontal: false, vertical: true)
                }
                .padding(10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color.MW.light, in: RoundedRectangle(cornerRadius: 10))
            }
        }
    }

    // MARK: - Items

    private func itemsSection(_ card: HeadCardResponse) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            let more = max(0, card.waiting - vm.items.count)
            sectionHead("Needs you now", more > 0 ? "+\(more) more on the dashboard" : nil)
            if vm.items.isEmpty {
                Text("Nothing from \(card.name) needs you right now.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            ForEach(vm.items) { it in
                itemRow(it, first: it.id == vm.items.first?.id)
                if it.id != vm.items.last?.id { Divider() }
            }
        }
    }

    private func itemRow(_ it: HeadItem, first: Bool) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .top, spacing: 6) {
                if it.yes {
                    Text("YES")
                        .font(.caption2.weight(.heavy))
                        .padding(.horizontal, 7).padding(.vertical, 2)
                        .background(Color.MW.green, in: Capsule())
                        .foregroundStyle(.white)
                }
                Text(it.text)
                    .font(first ? .subheadline.weight(.semibold) : .subheadline)
                    .fixedSize(horizontal: false, vertical: true)
            }
            HStack(spacing: 10) {
                let w = HeadCardViewModel.waited(it)
                if !w.isEmpty { Text(w).font(.caption).foregroundStyle(.secondary) }
                Spacer(minLength: 0)
                if MoveToMenu.isMovable(it.key) {
                    MoveToMenu(current: vm.head, disabled: vm.isBusy) { to in Task { await vm.move(it, to: to) } }
                }
                if vm.card?.owner == true {
                    Button("Not now") { Task { await vm.notNow(it) } }
                        .font(.caption)
                        .buttonStyle(.borderless)
                        .tint(.secondary)
                        .disabled(vm.isBusy)
                }
                if it.url != nil {
                    Button(openLabel(it)) {
                        Task { if let u = await vm.open(it) { openURL(u) } }
                    }
                    .font(.caption.weight(.semibold))
                    .buttonStyle(.borderedProminent)
                    .controlSize(.small)
                    .tint(Color.MW.green)
                }
            }
        }
    }

    /// As action-board.js label(), plus Yui's "Open client".
    private func openLabel(_ it: HeadItem) -> String {
        if it.kind == "campaign_reply" || it.kind.hasSuffix(":campaign_reply") { return "Start the quote" }
        if vm.head == "yui" { return "Open client" }
        return "Open in CRM"
    }

    // MARK: - Otto: properties he can't route to

    @ViewBuilder
    private var unpinnedSection: some View {
        if !vm.unpinned.isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                sectionHead("Can't route here yet", "a visit is coming and there's no map pin")
                ForEach(vm.unpinned) { p in
                    HStack(spacing: 10) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(p.address).font(.subheadline.weight(.semibold))
                            Text([p.city, "visit " + HeadCardViewModel.shortDate(p.nextVisit)].filter { !$0.isEmpty }.joined(separator: " · "))
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        Spacer(minLength: 0)
                        Button {
                            Task { await vm.geocode(p) }
                        } label: {
                            if vm.geocoding == p.id { ProgressView().controlSize(.small) } else { Text("Geocode") }
                        }
                        .buttonStyle(.bordered)
                        .controlSize(.small)
                        .tint(Color.MW.green)
                        .disabled(vm.geocoding != nil || vm.isBusy)
                    }
                    if p.id != vm.unpinned.last?.id { Divider() }
                }
            }
        }
    }

    // MARK: - Mia: this week's Google post

    @ViewBuilder
    private var postSection: some View {
        if let p = vm.post {
            VStack(alignment: .leading, spacing: 10) {
                sectionHead("This week's Google post", p.title.isEmpty ? nil : p.title)
                if let photo = p.photoURL, let url = HeadCardViewModel.webURL(photo) {
                    AsyncImage(url: url) { phase in
                        if let img = phase.image { img.resizable().scaledToFill() } else { Color.MW.light }
                    }
                    .frame(height: 160)
                    .frame(maxWidth: .infinity)
                    .clipShape(RoundedRectangle(cornerRadius: 10))
                }
                Text(p.body)
                    .font(.subheadline)
                    .textSelection(.enabled)
                    .fixedSize(horizontal: false, vertical: true)
                    .padding(10)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Color.MW.light, in: RoundedRectangle(cornerRadius: 10))
                if vm.card?.canApprove == true {
                    HStack(spacing: 8) {
                        if vm.canPublishToGoogle {
                            Button("Post to Google") { confirmPublish = true }
                                .buttonStyle(.borderedProminent)
                                .tint(Color.MW.green)
                        } else {
                            Button("Copy") { UIPasteboard.general.string = p.body }
                                .buttonStyle(.bordered)
                            Button("I posted it") { Task { await vm.decidePost("copied") } }
                                .buttonStyle(.bordered)
                                .tint(Color.MW.green)
                        }
                        Spacer(minLength: 0)
                        Button("Skip this week") { Task { await vm.decidePost("dismiss") } }
                            .buttonStyle(.borderless)
                            .tint(.secondary)
                    }
                    .font(.subheadline)
                    .disabled(vm.isBusy)
                    if !vm.canPublishToGoogle {
                        Text("Google isn't connected for posting — copy it and paste it into Google.")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                }
            }
            .confirmationDialog("Post this publicly on Google?", isPresented: $confirmPublish, titleVisibility: .visible) {
                Button("Post to Google") { Task { await vm.decidePost("publish") } }
                Button("Cancel", role: .cancel) {}
            }
        }
    }
}

// MARK: - Move to…

/// "Move to…" on a customer message — Penny, Sam, Otto, Mia or Yui (the web Action Board's
/// select). The server re-routes it and learns sender + topic, so the next one goes there.
/// Shared by the head cards, Sam's card and Penny's card (kept in this file so the Xcode
/// project needs no new entry).
struct MoveToMenu: View {
    let current: String
    var disabled = false
    let onMove: (String) -> Void

    static let heads: [(slug: String, name: String, role: String)] = [
        ("penny", "Penny", "Bookkeeper"),
        ("sam", "Sam", "Sales"),
        ("otto", "Otto", "Operations"),
        ("mia", "Mia", "Marketing"),
        ("yui", "Yui", "Comms"),
    ]

    static func name(_ slug: String) -> String {
        heads.first { $0.slug == slug }?.name ?? slug.capitalized
    }

    /// A reply in any head's lane, or Sam's "they replied" card (as action-board.js movable()).
    static func isMovable(_ key: String) -> Bool {
        key.range(of: #"^(sam|yui|penny|otto|mia):reply:\d+:[0-9a-f]{12}$"#, options: .regularExpression) != nil
            || key.range(of: #"^sam:contact:c\d+$"#, options: .regularExpression) != nil
    }

    var body: some View {
        Menu {
            ForEach(Self.heads.filter { $0.slug != current }, id: \.slug) { h in
                Button("\(h.name) · \(h.role)") { onMove(h.slug) }
            }
        } label: {
            Label("Move to…", systemImage: "arrowshape.turn.up.right")
                .labelStyle(.titleAndIcon)
        }
        .font(.caption)
        .disabled(disabled)
    }
}
