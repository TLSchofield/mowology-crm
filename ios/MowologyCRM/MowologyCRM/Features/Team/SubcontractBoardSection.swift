//
//  SubcontractBoardSection.swift
//  MowologyCRM
//
//  Otto's Subcontracts carousel — the web card's (public/crm/includes/subcontract-board.php) on the phone: every job
//  given to another company, one at a time, swipe sideways, with the stage it is at and the one button it needs from
//  Tim (Accept the work, Mark paid, Clear it). Tim, 2026-11-10: "these sub contracts are important they should carousel
//  somehow with the stages that they are at."
//

import SwiftUI

struct SubcontractBoardSection: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.openURL) private var openURL
    @ObservedObject var vm: HeadCardViewModel
    @State private var index = 0
    @State private var confirming: (card: SubcontractCard, mode: String)?

    /// The jobs waiting on the owner; the same rule as SubcontractRules::boardOrder.
    nonisolated static func needsYou(_ c: SubcontractCard) -> Bool {
        ["accept", "mark_paid", "dismiss"].contains(c.action ?? "")
    }

    var body: some View {
        let list = vm.subcontracts
        if !list.isEmpty {
            let i = min(index, list.count - 1)
            VStack(alignment: .leading, spacing: 12) {
                HStack {
                    Text("\(i + 1) of \(list.count)").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    Spacer()
                    if list.count > 1 {
                        Button { index = max(0, i - 1) } label: { Image(systemName: "chevron.left").frame(width: 44, height: 44) }
                            .disabled(i == 0).accessibilityLabel("Previous job")
                        Button { index = min(list.count - 1, i + 1) } label: { Image(systemName: "chevron.right").frame(width: 44, height: 44) }
                            .disabled(i >= list.count - 1).accessibilityLabel("Next job")
                    }
                }
                TabView(selection: $index) {
                    ForEach(Array(list.enumerated()), id: \.element.id) { n, card in
                        page(card).tag(n)
                    }
                }
                .tabViewStyle(.page(indexDisplayMode: .never))
                .frame(minHeight: 280)
                // A job that leaves the board (cleared, or paid two weeks ago) must not leave the pager on an empty page.
                .onChange(of: list.count) { _, n in index = min(index, max(0, n - 1)) }
            }
            .confirmationDialog(confirmTitle, isPresented: Binding(get: { confirming != nil }, set: { if !$0 { confirming = nil } }),
                                titleVisibility: .visible) {
                Button(confirmButton) {
                    if let c = confirming { Task { await vm.subcontractAct(c.card, mode: c.mode) } }
                }
                Button("Cancel", role: .cancel) {}
            }
        }
    }

    private func page(_ card: SubcontractCard) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            VStack(alignment: .leading, spacing: 2) {
                Text(card.company).font(appStyle.font(.headline))
                Text([card.title, card.address].filter { !$0.isEmpty }.joined(separator: " · "))
                    .font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
                    .fixedSize(horizontal: false, vertical: true)
            }
            track(card)
            VStack(alignment: .leading, spacing: 2) {
                Text("Now: \(card.at)").font(appStyle.font(.subheadline).weight(.semibold))
                if let last = card.last {
                    Text(last + (Self.when(card.lastAt).map { ", \($0)" } ?? ""))
                        .font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
                }
                if card.action == "penny" {
                    Text("Their bill is waiting in Penny's review.").font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
                }
            }
            HStack(spacing: 12) {
                if let b = Self.button(card.action) {
                    Button { confirming = (card, b.mode) } label: {
                        Text(b.label).font(appStyle.font(.subheadline).weight(.semibold)).frame(maxWidth: .infinity, minHeight: 48)
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(vm.isBusy)
                }
                Button {
                    if let u = HeadCardViewModel.webURL(card.url) { openURL(u) }
                } label: {
                    Text("Open").font(appStyle.font(.subheadline)).frame(minWidth: 72, minHeight: 48)
                }
                .buttonStyle(.bordered)
            }
            Spacer(minLength: 0)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .accessibilityElement(children: .contain)
    }

    /// The stages as a line of dots, the current one larger with its word in bold; scrolls sideways on a small phone.
    private func track(_ card: SubcontractCard) -> some View {
        ScrollViewReader { proxy in
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(alignment: .top, spacing: 0) {
                    ForEach(Array(card.stages.enumerated()), id: \.offset) { n, s in
                        VStack(spacing: 4) {
                            ZStack {
                                if n > 0 {
                                    Rectangle().fill(s.reached ? appStyle.complete : appStyle.meta.opacity(0.3))
                                        .frame(height: 2).offset(x: -30)
                                }
                                Circle()
                                    .fill(s.reached ? appStyle.complete : Color.clear)
                                    .overlay(Circle().stroke(s.reached ? Color.clear : appStyle.meta.opacity(0.5), lineWidth: 2))
                                    .frame(width: s.current ? 26 : 20, height: s.current ? 26 : 20)
                                if s.reached {
                                    Image(systemName: "checkmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white)
                                }
                            }
                            .frame(width: 60, height: 28)
                            Text(s.label)
                                .font(appStyle.font(.caption).weight(s.current ? .bold : .regular))
                                .foregroundStyle(s.current ? Color.primary : appStyle.meta)
                                .multilineTextAlignment(.center)
                                .frame(width: 60)
                        }
                        .id(n)
                        .accessibilityElement(children: .ignore)
                        .accessibilityLabel(s.label + (s.current ? ", now" : (s.reached ? ", done" : ", not yet")))
                    }
                }
            }
            .onAppear {
                if let now = card.stages.firstIndex(where: { $0.current }) { proxy.scrollTo(now, anchor: .center) }
            }
        }
    }

    private var confirmTitle: String {
        guard let c = confirming else { return "" }
        switch c.mode {
        case "sub_accept": return "Accept \(c.card.company)'s work? This completes the visit."
        case "sub_paid": return "Mark \(c.card.company) paid? Only once the money has gone."
        default: return "Clear this job from the board?"
        }
    }

    private var confirmButton: String {
        switch confirming?.mode {
        case "sub_accept": return "Accept the work"
        case "sub_paid": return "Mark paid"
        default: return "Clear it"
        }
    }

    nonisolated static func button(_ action: String?) -> (label: String, mode: String)? {
        switch action {
        case "accept": return ("Accept the work", "sub_accept")
        case "mark_paid": return ("Mark paid", "sub_paid")
        case "dismiss": return ("Clear it", "sub_dismiss")
        default: return nil
        }
    }

    nonisolated static func when(_ at: String?) -> String? {
        guard let at else { return nil }
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_CA")
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        guard let d = f.date(from: at) else { return nil }
        let out = DateFormatter()
        out.locale = Locale(identifier: "en_CA")
        out.dateFormat = "EEE MMM d, h:mm a"
        return out.string(from: d)
    }
}
