//
//  CharlieMorningBrief.swift
//  MowologyCRM
//
//  The opening screen (owner, 2026-10-10): Charlie's brain turns in the centre, then glides to
//  the top left of his morning brief, which lands IN the app — a card over the opening screen
//  with the tab bar still there, never a wall in front of it.
//
//  Rules (agreed with the owner):
//    - the full brief plays once a day, on the first open; every other launch gets the short
//      splash and goes straight in;
//    - admins only (the Team tab is where his items lead); everyone else gets the short splash;
//    - it leads with what is NEW since yesterday; standing problems collapse into one line;
//    - never waits on the network for long: today's brief if it arrives in time, else the
//      saved one marked "as of …", else just the splash;
//    - background: warm off-white (default) or the forest green — switchable on the card.
//

import SwiftUI

// MARK: - Saved brief + the once-a-day gate

struct MorningBriefSnapshot: Codable, Equatable {
    struct Line: Codable, Equatable, Identifiable, Hashable {
        let key: String
        let head: String
        let text: String
        let priority: Int
        let isNew: Bool
        var id: String { key }
    }
    var fetchedAt: Date
    var headline: String
    var lines: [Line]
    var ongoing: Int
}

enum MorningBriefStore {
    private static let snapshotKey = "mw.morningBrief.snapshot.v1"
    private static let shownKey = "mw.morningBrief.lastShownDay"
    private static let seenKey = "mw.morningBrief.seenKeys.v1"
    static let maxLines = 3

    static func dayString(_ d: Date = Date()) -> String {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f.string(from: d)
    }

    static var shownToday: Bool { UserDefaults.standard.string(forKey: shownKey) == dayString() }
    static func markShown() { UserDefaults.standard.set(dayString(), forKey: shownKey) }

    static func load() -> MorningBriefSnapshot? {
        guard let d = UserDefaults.standard.data(forKey: snapshotKey) else { return nil }
        return try? JSONDecoder().decode(MorningBriefSnapshot.self, from: d)
    }

    static func save(_ s: MorningBriefSnapshot) {
        if let d = try? JSONEncoder().encode(s) { UserDefaults.standard.set(d, forKey: snapshotKey) }
    }

    /// Keys shown before — an item counts as new when it first appeared since yesterday, or
    /// (for items the server dates loosely) when this phone has never shown it.
    private static var seenKeys: Set<String> {
        Set(UserDefaults.standard.stringArray(forKey: seenKey) ?? [])
    }

    static func rememberSeen(_ keys: [String]) {
        let all = Array(seenKeys.union(keys)).suffix(400)
        UserDefaults.standard.set(Array(all), forKey: seenKey)
    }

    /// New first (by priority), then the rest; at most maxLines shown, the others counted as ongoing.
    static func build(headline: String, items: [HeadItem], now: Date = Date()) -> MorningBriefSnapshot {
        let yesterday = dayString(Calendar.current.date(byAdding: .day, value: -1, to: now) ?? now)
        let seen = seenKeys
        let lines = items.map { it -> MorningBriefSnapshot.Line in
            let first = String((it.firstSeen ?? it.since ?? "").prefix(10))
            let fresh = (!first.isEmpty && first >= yesterday) || (!seen.isEmpty && !seen.contains(it.key))
            return .init(key: it.key, head: it.head, text: it.text, priority: it.priority, isNew: fresh)
        }
        let ordered = lines.filter(\.isNew).sorted { $0.priority < $1.priority }
                    + lines.filter { !$0.isNew }.sorted { $0.priority < $1.priority }
        let shown = Array(ordered.prefix(maxLines))
        return MorningBriefSnapshot(fetchedAt: now, headline: headline, lines: shown, ongoing: max(0, ordered.count - shown.count))
    }
}

// MARK: - Loading (bounded wait)

@MainActor
final class MorningBriefLoader: ObservableObject {
    @Published private(set) var snapshot: MorningBriefSnapshot?
    @Published private(set) var isFresh = false

    /// Today's brief, or the saved one if the network is slow. Also refreshes the brain cache.
    func load(authSession: AuthSession, wait: Double = 2.5) async {
        snapshot = MorningBriefStore.load()
        isFresh = false
        let client = APIClient(authSession: authSession)
        let fetch = Task { () -> HeadCardResponse? in
            try? await client.request(.teamHeadBrief(head: "charlie"))
        }
        let timeout = Task { try? await Task.sleep(nanoseconds: UInt64(wait * 1_000_000_000)); fetch.cancel() }
        if let r = await fetch.value, r.ok {
            timeout.cancel()
            if let brain = r.brain { CharlieBrainCache.save(brain) }
            let s = MorningBriefStore.build(headline: r.headline, items: r.items)
            MorningBriefStore.save(s)
            snapshot = s
            isFresh = true
        }
    }
}

// MARK: - The opening overlay

struct OpeningOverlay: View {
    enum Phase { case splash, brief, done }

    let showBrief: Bool                 // admin + first open today
    @ObservedObject var loader: MorningBriefLoader
    var onFinish: () -> Void

    @AppStorage("mw.opening.lightBackground") private var light = true
    @State private var phase: Phase = .splash
    @State private var revealed = 0
    private let snap = CharlieBrainCache.load() ?? .init(units: 0, tiers: [:])

    private var background: Color { light ? Color.MW.paper : Color.MW.forest }
    private var ink: Color { light ? Color.MW.forest : .white }

    var body: some View {
        GeometryReader { geo in
            let top = geo.safeAreaInsets.top
            let small: CGFloat = 56
            let bigSize = min(geo.size.width * 0.72, 300)
            let center = CGPoint(x: geo.size.width / 2, y: geo.size.height / 2)
            let corner = CGPoint(x: 16 + 16 + small / 2, y: top + 12 + 16 + small / 2)

            ZStack(alignment: .top) {
                if phase == .splash {
                    background.ignoresSafeArea().transition(.opacity)
                }
                if phase == .brief, let s = loader.snapshot {
                    briefCard(s, brainSlot: small)
                        .padding(.horizontal, 16)
                        .padding(.top, top + 12)
                        .transition(.move(edge: .top).combined(with: .opacity))
                }
                if phase != .done {
                    BrainView(units: snap.units, tiers: snap.tiers, label: "Charlie's brain")
                        .frame(width: phase == .splash ? bigSize : small, height: phase == .splash ? bigSize : small)
                        .shadow(color: .black.opacity(light ? 0.18 : 0), radius: phase == .splash ? 18 : 4, y: phase == .splash ? 10 : 2)
                        .position(phase == .splash ? center : corner)
                        .allowsHitTesting(false)
                }
            }
            .ignoresSafeArea()
        }
        .task { await run() }
    }

    private func run() async {
        try? await Task.sleep(nanoseconds: 1_500_000_000)
        if showBrief {
            // Give a slow network a moment more, then use the saved brief (or skip it).
            var waited = 0.0
            while !loader.isFresh && waited < 1.0 {
                try? await Task.sleep(nanoseconds: 200_000_000); waited += 0.2
            }
        }
        if showBrief, let s = loader.snapshot, !s.headline.isEmpty || !s.lines.isEmpty {
            MorningBriefStore.markShown()
            withAnimation(.spring(response: 0.7, dampingFraction: 0.85)) { phase = .brief }
            for i in 1...(s.lines.count + 2) {
                try? await Task.sleep(nanoseconds: 260_000_000)
                withAnimation(.easeOut(duration: 0.35)) { revealed = i }
            }
            MorningBriefStore.rememberSeen(s.lines.map(\.key))
        } else {
            withAnimation(.easeOut(duration: 0.45)) { phase = .done }
            onFinish()
        }
    }

    private func close() {
        withAnimation(.easeInOut(duration: 0.35)) { phase = .done }
        onFinish()
    }

    @ViewBuilder
    private func briefCard(_ s: MorningBriefSnapshot, brainSlot: CGFloat) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(alignment: .center, spacing: 12) {
                Color.clear.frame(width: brainSlot, height: brainSlot)   // the brain lands here
                VStack(alignment: .leading, spacing: 2) {
                    Text("Charlie").font(.headline).foregroundStyle(ink)
                    Text(loader.isFresh ? "Chief of Staff · this morning"
                         : "Chief of Staff · as of \(s.fetchedAt.formatted(date: .abbreviated, time: .shortened))")
                        .font(.caption).foregroundStyle(ink.opacity(0.65))
                }
                Spacer(minLength: 0)
                Button { withAnimation(.easeInOut(duration: 0.3)) { light.toggle() } } label: {
                    Image(systemName: light ? "moon.fill" : "sun.max.fill")
                        .font(.footnote).foregroundStyle(ink.opacity(0.6))
                        .padding(8)
                }
                .accessibilityLabel(light ? "Dark background" : "Light background")
            }

            if !s.headline.isEmpty {
                Text(s.headline)
                    .font(.title3.weight(.semibold))
                    .foregroundStyle(ink)
                    .fixedSize(horizontal: false, vertical: true)
                    .opacity(revealed >= 1 ? 1 : 0)
            }

            ForEach(Array(s.lines.enumerated()), id: \.element.id) { i, line in
                Button {
                    NotificationRouter.shared.pendingHead = HeadRoute(head: line.head.isEmpty ? "charlie" : line.head, quoteId: nil)
                    close()
                } label: {
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        if line.isNew {
                            Text("NEW").font(.caption2.weight(.heavy))
                                .padding(.horizontal, 6).padding(.vertical, 2)
                                .background(Color.MW.green, in: Capsule()).foregroundStyle(.white)
                        }
                        VStack(alignment: .leading, spacing: 1) {
                            if !line.head.isEmpty {
                                Text(line.head.capitalized).font(.caption.weight(.semibold)).foregroundStyle(Color.MW.green)
                            }
                            Text(line.text).font(.subheadline).foregroundStyle(ink)
                                .multilineTextAlignment(.leading)
                                .fixedSize(horizontal: false, vertical: true)
                        }
                        Spacer(minLength: 0)
                        Image(systemName: "chevron.right").font(.caption).foregroundStyle(ink.opacity(0.4))
                    }
                }
                .buttonStyle(.plain)
                .opacity(revealed >= i + 2 ? 1 : 0)
                .offset(y: revealed >= i + 2 ? 0 : 6)
            }

            HStack {
                if s.ongoing > 0 {
                    Text("+ \(s.ongoing) ongoing on the Team tab").font(.caption).foregroundStyle(ink.opacity(0.6))
                }
                Spacer()
                Button("Start the day") { close() }
                    .font(.subheadline.weight(.semibold))
                    .padding(.horizontal, 16).padding(.vertical, 9)
                    .background(Color.MW.green, in: Capsule())
                    .foregroundStyle(.white)
            }
            .opacity(revealed >= s.lines.count + 2 ? 1 : 0)
        }
        .padding(16)
        .background(
            RoundedRectangle(cornerRadius: 20, style: .continuous)
                .fill(background)
                .shadow(color: .black.opacity(0.18), radius: 20, y: 8)
        )
        .gesture(DragGesture(minimumDistance: 20).onEnded { v in if v.translation.height < -30 { close() } })
    }
}
