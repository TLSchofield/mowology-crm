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

    /// Owner, 2026-10-11: the brief plays on EVERY open until he says to go back to once a day.
    /// Set to true to restore the once-a-day rule.
    static let oncePerDay = false
    static var shownToday: Bool { oncePerDay && UserDefaults.standard.string(forKey: shownKey) == dayString() }
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

    @Published private(set) var finished = false

    /// Today's brief, with the saved one meanwhile. Never cancelled: a live build reads every head's
    /// desk (~7 s, measured 2026-10-10) — the old 2.5 s cut-off meant the brief never arrived.
    /// The server now keeps it ready (?ready=1, HeadBriefReadyService), so it is normally instant.
    /// The overlay decides how long to wait; this just publishes whatever arrives.
    func load(authSession: AuthSession) async {
        snapshot = MorningBriefStore.load()
        isFresh = false
        finished = false
        defer { finished = true }
        let client = APIClient(authSession: authSession)
        // The ready copy (served at once); an older server ignores ready=1 and builds live.
        let r: HeadCardResponse? = try? await client.request(.teamHeadBriefReady(head: "charlie"))
        if let r, r.ok {
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
    /// waiting = the splash has gone and the app is usable; Charlie's small brain sits top left
    /// with the dots until his brief arrives, then the card lands.
    enum Phase { case splash, waiting, brief, done }

    /// Asked when the spin ends (not at launch: the login may still be loading then).
    let wantsBrief: () -> Bool?         // admin + first open today; nil = login still loading
    @ObservedObject var loader: MorningBriefLoader
    var onFinish: () -> Void

    @AppStorage("mw.opening.lightBackground") private var light = true
    @State private var phase: Phase = .splash
    @State private var revealed = 0
    /// The intro (owner, 2026-10-11): the brain grows in, then ITTAI, then the strapline; then the
    /// brain moves to the top left and the brief lands beside it.
    @State private var intro = 0
    /// Once the brain has landed top left it flips to Charlie's face (as on the Team tab); a tap flips it back.
    @State private var showFace = false
    static let productName = "ITTAI"
    static let strapline = "One business, working as one."
    private let snap = CharlieBrainCache.load() ?? .init(units: 0, tiers: [:])

    private var background: Color { light ? Color.MW.paper : Color.MW.forest }
    private var ink: Color { light ? Color.MW.forest : .white }

    var body: some View {
        GeometryReader { geo in
            let top = geo.safeAreaInsets.top
            let small: CGFloat = 56
            let bigSize = min(geo.size.width * 0.72, 300)
            // The brain sits a little above the middle so the name and strapline fit beneath it.
            let center = CGPoint(x: geo.size.width / 2, y: geo.size.height / 2 - 48)
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
                if phase == .splash || phase == .waiting {
                    let big = phase == .splash
                    OrbitDots(color: light ? Color.MW.green : Color.MW.lime, radius: (big ? bigSize : small) * 0.62)
                        .frame(width: (big ? bigSize : small) * 1.4, height: (big ? bigSize : small) * 1.4)
                        .position(big ? center : corner)
                        .allowsHitTesting(false)
                        .transition(.opacity)
                }
                if phase == .splash {
                    VStack(spacing: 10) {
                        Text(Self.productName)
                            .font(.system(size: 34, weight: .semibold))
                            .tracking(12)
                            .foregroundStyle(ink)
                            .opacity(intro >= 2 ? 1 : 0)
                            .offset(y: intro >= 2 ? 0 : 8)
                        Text(Self.strapline)
                            .font(.system(size: 15, weight: .regular))
                            .foregroundStyle(ink.opacity(0.7))
                            .opacity(intro >= 3 ? 1 : 0)
                    }
                    .multilineTextAlignment(.center)
                    .position(x: center.x, y: center.y + bigSize / 2 + 70)
                    .accessibilityElement(children: .combine)
                    .allowsHitTesting(false)
                    .transition(.opacity)
                }
                if phase != .done {
                    let side = phase == .splash ? bigSize : small
                    ZStack {
                        BrainView(units: snap.units, tiers: snap.tiers, label: "Charlie's brain", onLight: light)
                            .opacity(showFace ? 0 : 1)
                        // The back: his face, drawn mirrored so it reads the right way round once flipped.
                        HeadFaceView(slug: "charlie", name: "Charlie", size: side, ring: Color.MW.lime)
                            .rotation3DEffect(.degrees(180), axis: (x: 0, y: 1, z: 0))
                            .opacity(showFace ? 1 : 0)
                    }
                    .frame(width: side, height: side)
                    .rotation3DEffect(.degrees(showFace ? 180 : 0), axis: (x: 0, y: 1, z: 0))
                    // The tap belongs to the face's own square, set before .position — after it the
                    // view fills the screen and could swallow taps meant for the brief.
                    .contentShape(Circle())
                    .onTapGesture {
                        guard phase == .brief else { return }
                        withAnimation(.easeInOut(duration: 0.45)) { showFace.toggle() }
                    }
                    .allowsHitTesting(phase == .brief)
                    .accessibilityLabel(showFace ? "Charlie" : "Charlie's brain")
                    .shadow(color: .black.opacity(light ? 0.18 : 0), radius: phase == .splash ? 18 : 4, y: phase == .splash ? 10 : 2)
                    .position(phase == .splash ? center : corner)
                    .scaleEffect(phase == .splash && intro < 1 ? 0.82 : 1)
                    .opacity(phase == .splash && intro < 1 ? 0 : 1)
                }
            }
            .ignoresSafeArea()
        }
        .task { await run() }
    }

    private func run() async {
        // The intro: brain (0 s), ITTAI (0.45 s), strapline (0.9 s), held so it can be read — ~2.8 s in all
        // (owner, 2026-10-11: a 5-word line needs ~1.5–2 s on screen to land; past ~3 s it feels like waiting).
        withAnimation(.spring(response: 0.6, dampingFraction: 0.8)) { intro = 1 }
        try? await Task.sleep(nanoseconds: 450_000_000)
        withAnimation(.easeOut(duration: 0.5)) { intro = 2 }
        try? await Task.sleep(nanoseconds: 450_000_000)
        withAnimation(.easeOut(duration: 0.5)) { intro = 3 }
        try? await Task.sleep(nanoseconds: 1_900_000_000)
        // The login and today's brief get up to 2 s more (the dots keep circling).
        // Everyone else goes straight in — only an admin (or a login not loaded yet) waits.
        var waited = 0.0
        while waited < 2.0 {
            let want = wantsBrief()
            if want == false || (want == true && loader.isFresh) { break }
            try? await Task.sleep(nanoseconds: 200_000_000); waited += 0.2
        }
        let showBrief = wantsBrief() == true
        if showBrief && !loader.isFresh {
            // Not here yet: open the app and keep Charlie thinking in the corner (up to 20 s more).
            // A saved brief is the fallback only when today's never comes.
            withAnimation(.spring(response: 0.7, dampingFraction: 0.85)) { phase = .waiting }
            var more = 0.0
            while more < 20 && !loader.isFresh && !loader.finished {
                try? await Task.sleep(nanoseconds: 250_000_000); more += 0.25
            }
        }
        if showBrief, let s = loader.snapshot, !s.headline.isEmpty || !s.lines.isEmpty {
            MorningBriefStore.markShown()
            withAnimation(.spring(response: 0.7, dampingFraction: 0.85)) { phase = .brief }
            // Landed: the brain turns over to show who is talking.
            Task { @MainActor in
                try? await Task.sleep(nanoseconds: 800_000_000)
                withAnimation(.easeInOut(duration: 0.5)) { showFace = true }
            }
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

// MARK: - Three dots circling while the brief loads

struct OrbitDots: View {
    let color: Color
    let radius: CGFloat

    var body: some View {
        TimelineView(.animation) { tl in
            Canvas { g, size in
                let t = tl.date.timeIntervalSinceReferenceDate
                let c = CGPoint(x: size.width / 2, y: size.height / 2)
                for i in 0..<3 {
                    // Three dots a third of a turn apart, on a slightly tilted ellipse, gently breathing.
                    let a = t * 1.6 + Double(i) * 2 * .pi / 3
                    let x = c.x + CGFloat(cos(a)) * radius
                    let y = c.y + CGFloat(sin(a)) * radius * 0.42
                    let front = sin(a) > 0
                    let r: CGFloat = (front ? 5 : 3.5) + CGFloat(0.8 * sin(t * 3 + Double(i)))
                    g.fill(Path(ellipseIn: CGRect(x: x - r, y: y - r, width: 2 * r, height: 2 * r)),
                           with: .color(color.opacity(front ? 0.95 : 0.45)))
                }
            }
        }
        .accessibilityHidden(true)
    }
}
