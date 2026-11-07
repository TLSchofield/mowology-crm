//
//  MainTabView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-06.
//

import SwiftUI
import UIKit

@MainActor
final class ReadyBriefStore: ObservableObject {
    @Published private(set) var response: HeadCardResponse?
    @Published private(set) var loaded = false
    @Published private(set) var failed = false
    @Published private(set) var updatedAt: Date?
    private var refreshTask: Task<Void, Never>?

    func refresh(authSession: AuthSession, force: Bool = false) async {
        if let refreshTask { await refreshTask.value; return }
        guard force || !loaded || failed else { return }
        let task = Task { @MainActor in
            failed = false
            do {
                let value: HeadCardResponse = try await APIClient(authSession: authSession)
                    .request(.teamHeadBriefReady(head: "charlie"))
                if value.ok { response = value; updatedAt = Date() }
                else { failed = true }
            } catch { failed = true }
            loaded = true
        }
        refreshTask = task
        await task.value
        refreshTask = nil
    }
}

struct MainTabView: View {
    @Environment(\.itAppStyle) private var appStyle

    @EnvironmentObject private var authSession: AuthSession
    @ObservedObject private var notificationRouter = NotificationRouter.shared
    /// "Booked another day — doing it now?" (VisitPullForwardService): checks while the app is open.
    @ObservedObject private var pullForward = PullForwardCoordinator.shared
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @StateObject private var readyBriefStore = ReadyBriefStore()
    private let ittaiDesignEnabled = true   // Ittai for everyone from 1.3.56 (Tim, 2026-10-19); the classic look is retired
    @AppStorage("ittai.design.field") private var ittaiFieldEnabled = false

    /// The bar (Tim, 2026-11-05): admins Schedule · Search · Kaname · Receipts · Add — Kaname, the chief of staff,
    /// in the centre; Add at the far right, under the thumb. Crew: Schedule · Search · Receipts · Account · Add.
    /// Kaname's tab carries the profile row and Sign Out for admins. Time left the bar for the chip at the top of
    /// every tab (TimeChip); its screen is still a page of the TabView, so it keeps its state and the driver
    /// question can still open it. Add is not a page: it opens the AddDial over the current screen.
    private enum Tab: Hashable { case schedule, time, kaname, search, receipts, account, add }
    @State private var selectedTab = Tab.schedule
    /// The AddDial is open: the bead sits on Add (showing ×) while the screen underneath stays as it was.
    @State private var addOpen = false
    /// The Schedule bead's view, and its long-press picker (Tim, 2026-11-07).
    @ObservedObject private var scheduleViews = ScheduleViewStore.shared
    @State private var showScheduleViews = false
    /// The bar tab the bead returns to from Time Clock, which has no slot of its own.
    @State private var lastBarTab = Tab.schedule
    @State private var keyboardUp = false
    /// When a push last sent the user somewhere: the driver question doesn't pull them to Time over it.
    @State private var routedAt: Date?

    /// The bar's slots, left to right.
    private var barTabs: [Tab] {
        authSession.user?.isAdmin == true
            ? [.schedule, .search, .kaname, .receipts, .add]
            : [.schedule, .search, .receipts, .account, .add]
    }

    /// The TabView's pages: every slot that is a screen, plus Time Clock.
    private var tabs: [Tab] { barTabs.filter { $0 != .add } + [.time] }

    /// The slot the bead sits on: Add while the dial is open; on Time Clock, the tab it was opened from.
    private var barIndex: Int {
        let shown: Tab = addOpen ? .add : (barTabs.contains(selectedTab) ? selectedTab : lastBarTab)
        return barTabs.firstIndex(of: shown) ?? 0
    }

    private var meniscusItems: [ItMeniscusItem] {
        barTabs.enumerated().map { index, tab in
            switch tab {
            // The Schedule bead shows the day's view; a tap moves to the next (numbered → cards → time → map), a long
            // press picks one (Tim, 2026-11-07). Not while Time Clock is showing: a tap there only goes back.
            case .schedule:
                return selectedTab == .time
                    ? ItMeniscusItem(id: index, title: "Schedule", icon: Image(systemName: "calendar"))
                    : ItMeniscusItem(id: index, title: "Schedule", icon: Image(systemName: "calendar"),
                                     beadIcon: Image(systemName: scheduleViews.kind.icon),
                                     beadLabel: "Show \(scheduleViews.kind.next.title.lowercased()) view",
                                     longPressLabel: "Choose a view")
            case .kaname:   return ItMeniscusItem(id: index, title: "Kaname", icon: Image(uiImage: KanameTabIcon.image), iconScale: 1.35)
            case .search:   return ItMeniscusItem(id: index, title: "Search", icon: Image(systemName: "magnifyingglass"))
            // On Receipts the bead becomes the camera: a second tap snaps (Tim, 2026-11-05). Not while Time Clock
            // is showing: the bead is resting there and a tap only goes back (Codex 2026-11-05).
            case .receipts:
                return selectedTab == .time
                    ? ItMeniscusItem(id: index, title: "Receipts", icon: Image(systemName: "doc.text.image"))
                    : ItMeniscusItem(id: index, title: "Receipts", icon: Image(systemName: "doc.text.image"),
                                     beadIcon: Image(systemName: "camera.fill"), beadLabel: "Snap a receipt")
            case .account:  return ItMeniscusItem(id: index, title: "Account", icon: Image(systemName: "person.fill"))
            // Add is an action, not a page: open, the bead's + spins into × and a tap on it closes the dial.
            case .add:      return ItMeniscusItem(id: index, title: "Add", icon: Image(systemName: "plus"),
                                                  beadTurn: 45, beadLabel: "Close",
                                                  longPressLabel: "Add a job")
            case .time:     return ItMeniscusItem(id: index, title: "Time", icon: Image(systemName: "clock.fill"))
            }
        }
    }

    @ViewBuilder
    private func screen(_ tab: Tab) -> some View {
        switch tab {
        case .schedule: ScheduleView(authSession: authSession)
        case .time:     TimeClockView(authSession: authSession)
        case .kaname:   TeamView(authSession: authSession, readyBriefStore: readyBriefStore)
        case .search:   SearchView(authSession: authSession)
        case .receipts: ReceiptsView(authSession: authSession).environmentObject(authSession)
        case .account:  accountTab
        case .add:      EmptyView()   // never a page: barTabs only
        }
    }

    /// The room the meniscus bar takes at the bottom of every tab screen.
    static let barReserve: CGFloat = ItMeniscusGeometry.rise + ItMeniscusBar.barHeight

    init() {
        // Belt and braces with `.toolbar(.hidden, for: .tabBar)` on each tab: the meniscus bar replaces the
        // system tab bar, so it must never show as a second bar (no simulator on this Mac to prove every screen).
        UITabBar.appearance().isHidden = true
    }

    var body: some View {
        let _ = RenderRate.tick("MainTabView")
        // TabView keeps each tab's screen and navigation alive while another tab shows; its own bar never shows.
        TabView(selection: $selectedTab) {
            ForEach(tabs, id: \.self) { tab in
                screen(tab)
                    .tag(tab)
                    .toolbar(.hidden, for: .tabBar)
                    // Pinned bottom controls inside a tab (Receipts' Snap button, the map's cards, a toast) lift by this.
                    .environment(\.itBarClearance, keyboardUp ? 0 : Self.barReserve)
                    // Where this tab's bead sits: its screen's centre line runs into it (SPEC §13).
                    .environment(\.itTabSlot, ItTabSlot(index: barTabs.firstIndex(of: tab) ?? 0, count: barTabs.count))
                    .environment(\.openTimeClock, { addOpen = false; selectedTab = .time })
                    // The veil blocks touches; this keeps VoiceOver out of the screen behind the Add dial too.
                    .accessibilityHidden(addOpen)
            }
        }
        // Over the screen, under the bar (the bar is the safe-area inset below), so the bead and its × stay live.
        .overlay {
            if addOpen, let slot = barTabs.firstIndex(of: .add) {
                AddDial(slot: slot, slotCount: barTabs.count, onPick: add, onClose: { addOpen = false })
                    .transition(reduceMotion ? .opacity : .opacity.combined(with: .move(edge: .bottom)))
            }
        }
        .animation(reduceMotion ? nil : .spring(response: 0.32, dampingFraction: 0.86), value: addOpen)
        // The ready brief's head states, for each screen's head face and "needs you" line (ScreenHead.swift).
        .environmentObject(readyBriefStore)
        // The bar's inset does not reach scroll views inside the tabs' navigation stacks, so the last rows of a
        // pushed screen sat under the bar (Tim, 2026-11-05: visit screen, "Move to another date"). Content margins
        // pass down to every scroll view, pushed screens included (proved in the simulator, 2026-11-05).
        .contentMargins(.bottom, keyboardUp ? 0 : Self.barReserve, for: .scrollContent)
        // The meniscus bar replaces the system tab bar (Tim, 2026-11-04). It steps aside while the keyboard is up.
        .safeAreaInset(edge: .bottom, spacing: 0) {
            if !keyboardUp {
                ItMeniscusBar(items: meniscusItems, selected: Binding(
                    get: { barIndex },
                    set: { index in
                        guard barTabs.indices.contains(index) else { return }
                        if barTabs[index] == .add { addOpen = true }
                        else { addOpen = false; selectedTab = barTabs[index] }
                    }),
                    onReselect: { index in
                        guard barTabs.indices.contains(index) else { return }
                        let tab = barTabs[index]
                        if tab == .add { addOpen = false }                          // the × on the bead
                        else if selectedTab == .time { selectedTab = tab }          // back from Time Clock
                        else if tab == .receipts {
                            NotificationCenter.default.post(name: .mwSnapReceipt, object: nil)
                        }
                        // Kaname again: back to his main screen from a head's (Tim, 2026-11-07: "no back bar needed if
                        // pressing Kaname on the nav bar returns me to Kaname main screen").
                        else if tab == .kaname {
                            NotificationCenter.default.post(name: .mwKanameHome, object: nil)
                        }
                        else if tab == .schedule {
                            UISelectionFeedbackGenerator().selectionChanged()
                            withAnimation(reduceMotion ? nil : .easeInOut(duration: 0.2)) { scheduleViews.cycle() }
                        }
                    },
                    onLongPress: { index in
                        if barTabs.indices.contains(index), barTabs[index] == .add { add(.job) }
                        if barTabs.indices.contains(index), barTabs[index] == .schedule, selectedTab == .schedule {
                            showScheduleViews = true
                        }
                    })
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillShowNotification)) { _ in keyboardUp = true }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillHideNotification)) { _ in keyboardUp = false }
        .tint(appStyle.tenant)
        .itAppScreen()
        .task {
            await ClockChipStore.shared.refresh(authSession: authSession)
            if authSession.user?.isAdmin == true && ittaiDesignEnabled {
                await readyBriefStore.refresh(authSession: authSession)
            }
        }
        .confirmationDialog("Show the day as", isPresented: $showScheduleViews, titleVisibility: .visible) {
            ForEach(ScheduleViewKind.allCases) { kind in
                Button(kind == scheduleViews.kind ? "\(kind.title) (showing)" : kind.title) { scheduleViews.kind = kind }
            }
        }
        .sheet(item: $pullForward.offer, onDismiss: { pullForward.sheetDismissed() }) { offer in
            PullForwardSheet(coordinator: pullForward, offer: offer)
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active {
                pullForward.appBecameActive(authSession: authSession)
                checkDriverQuestion()
                Task { await ClockChipStore.shared.refresh(authSession: authSession) }
            }
            else if phase == .background { pullForward.appWentInactive() }
        }
        // The vehicle question is keyed off the server's shift, not the Clock In button: an
        // automatic clock-in (arrival, job start) used to skip the pre-trip entirely.
        .onReceive(NotificationCenter.default.publisher(for: .mwShiftMayHaveStarted)) { _ in
            checkDriverQuestion()
        }
        // A sign-in as someone else (admin vs crew) changes the tab list; never leave a tab selected that isn't in it.
        .onChange(of: tabs) { _, now in
            if !now.contains(selectedTab) { selectedTab = .schedule }
            if !barTabs.contains(lastBarTab) { lastBarTab = .schedule }
        }
        .onChange(of: selectedTab) { _, now in
            if barTabs.contains(now) { lastBarTab = now }
        }
        // A push, the driver question or a sign-in change moves the page: the dial never stays open over it.
        .onChange(of: selectedTab) { _, _ in addOpen = false }
        // Every notification route today lands on the schedule; ScheduleView
        // consumes the route itself and opens the stop.
        .onChange(of: notificationRouter.pendingRoute) { _, route in
            if route != nil { routedAt = Date(); selectedTab = .schedule }
        }
        .onAppear {
            pullForward.appBecameActive(authSession: authSession)
            if notificationRouter.pendingRoute != nil || notificationRouter.pendingHead != nil { routedAt = Date() }
            checkDriverQuestion()
            if notificationRouter.pendingRoute != nil { selectedTab = .schedule }
            else if notificationRouter.pendingHead != nil { openTeamForHead() }
        }
        // Department-head pushes ("Sam: Linda just opened QUO-…") land on the Kaname tab;
        // TeamView consumes the route and shows that head's card. Only admins have Kaname.
        .onChange(of: notificationRouter.pendingHead) { _, head in
            if head != nil { routedAt = Date(); openTeamForHead() }
        }
    }

    /// Clocked in on the server but not yet asked "are you driving today?" → open the Time Clock
    /// tab, whose own sheet asks (no new root-level sheet: those blocked other sheets 2026-10-08).
    private func checkDriverQuestion() {
        Task {
            let asked = Date()
            if await DriverTripViewModel.shiftNeedsDriverAnswer(authSession: authSession),
               notificationRouter.pendingRoute == nil, notificationRouter.pendingHead == nil,
               // A push that arrived (or was opened) since a few seconds before asking wins (Codex review 2026-11-04).
               (routedAt ?? .distantPast) < asked.addingTimeInterval(-5) {
                selectedTab = .time
            }
        }
    }

    /// A pick from the AddDial (or a long press on Add): Schedule owns the "Add a job" sheet (FieldJobView), so the
    /// bar switches there and asks it to open — no new root-level sheet (those blocked other sheets, 2026-10-08).
    private func add(_ item: AddDialItem) {
        addOpen = false
        selectedTab = .schedule
        // After the switch lands, so the sheet presents from a Schedule that is on screen.
        DispatchQueue.main.async {
            NotificationCenter.default.post(name: .mwAddFromBar, object: nil,
                                            userInfo: ["startWithNewClient": item == .client])
        }
    }

    private func openTeamForHead() {
        if authSession.user?.isAdmin == true {
            selectedTab = .kaname
        } else {
            notificationRouter.pendingHead = nil
        }
    }

    // MARK: - Placeholder

    private func comingSoonTab(title: String, icon: String) -> some View {
        NavigationStack {
            VStack(spacing: 16) {
                Image(systemName: icon)
                    .font(appStyle.font(size: 48))
                    .foregroundStyle(appStyle.color(.meta, legacy: Color(.systemGray3)))

                Text(title)
                    .font(appStyle.font(.title2, weight: .bold))
                    .foregroundStyle(appStyle.text)

                Text("Coming soon in v2")
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(appStyle.page)
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    // MARK: - Account Tab

    private var accountTab: some View {
        NavigationStack {
            List {
                if let user = authSession.user {
                    Section {
                        AccountProfileRow(user: user)
                            .padding(.vertical, 4)
                            .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
                    }
                }

                Section("Appearance") {
                    ItDesignSettingsRows()
                }
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)

                Section {
                    SignOutButton(authSession: authSession)
                }
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
            }
            .itAppForm()
            .itAppScreen()
            .navigationTitle("Account")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .navigationBarTrailing) { TimeChip() } }
        }
    }
}

@MainActor
private enum KanameTabIcon {
    /// The Ittai hexagon, rendered once; the bar tints it.
    static let image: UIImage = {
        let renderer = ImageRenderer(content: ItSymbol(size: 28).environment(\.it, ItPalette(.light)))
        renderer.scale = UIScreen.main.scale
        return (renderer.uiImage ?? UIImage(systemName: "hexagon")!)
            .withRenderingMode(.alwaysTemplate)
    }()
}

// MARK: - Profile row

/// The signed-in user's name and email — on Account, and on Team for admins.
struct AccountProfileRow: View {
    @Environment(\.itAppStyle) private var appStyle
    let user: User

    var body: some View {
        HStack(spacing: 14) {
            Image(systemName: "person.circle.fill")
                .font(appStyle.font(size: 44))
                .foregroundStyle(appStyle.tenant)

            VStack(alignment: .leading, spacing: 2) {
                Text(user.name)
                    .font(appStyle.font(.headline))
                Text(user.email)
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
            }
        }
    }
}

// MARK: - Sign Out

/// Sign Out — asks first when a shift is still open (see SignOutViewModel).
/// On Account, and on Team for admins.
struct SignOutButton: View {
    @Environment(\.itAppStyle) private var appStyle

    @StateObject private var vm: SignOutViewModel

    init(authSession: AuthSession) {
        _vm = StateObject(wrappedValue: SignOutViewModel(authSession: authSession))
    }

    var body: some View {
        Button(role: .destructive) {
            Task { await vm.begin() }
        } label: {
            HStack {
                Label("Sign Out", systemImage: "rectangle.portrait.and.arrow.right")
                if vm.isWorking {
                    Spacer()
                    ProgressView()
                }
            }
        }
        .disabled(vm.isWorking)
        .confirmationDialog("You're still clocked in",
                            isPresented: $vm.showClockedInPrompt,
                            titleVisibility: .visible) {
            Button("Clock out and sign out") {
                Task { await vm.clockOutAndSignOut() }
            }
            Button("Sign out only — I'm still working", role: .destructive) {
                vm.signOutOnly()
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Signing out doesn't end your shift — your hours keep running until you clock out. Location tracking on this phone stops when you sign out."
                 + (vm.unsentCount > 0 ? " \(vm.unsentCount) action(s) made without signal haven't been sent yet — they'll send next time you sign in with signal." : ""))
        }
        .confirmationDialog("Not sent yet",
                            isPresented: $vm.showUnsentPrompt,
                            titleVisibility: .visible) {
            Button("Sign out anyway", role: .destructive) { vm.signOutOnly() }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("\(vm.unsentCount) clock or job action(s) made without signal haven't reached the office. They stay on this phone and send next time you sign in with signal. Better: stay signed in until you have signal.")
        }
        .alert("Couldn't sign out",
               isPresented: Binding(get: { vm.errorMessage != nil },
                                    set: { if !$0 { vm.errorMessage = nil } })) {
            Button("OK", role: .cancel) {}
        } message: {
            Text(vm.errorMessage ?? "")
        }
    }
}

#Preview {
    MainTabView()
        .environmentObject(AuthSession())
}

/// How far a control pinned to the bottom of a tab screen must sit up to clear the meniscus bar. The bar's own
/// inset does not reach inside the tabs' navigation stacks (Known-Failure-Patterns, 2026-11-05); scroll content
/// gets room from `contentMargins`, pinned controls read this. 0 outside the tabs (sheets set their own).
private struct ItBarClearanceKey: EnvironmentKey { static let defaultValue: CGFloat = 0 }

extension EnvironmentValues {
    var itBarClearance: CGFloat {
        get { self[ItBarClearanceKey.self] }
        set { self[ItBarClearanceKey.self] = newValue }
    }
}

/// The bar slot of the tab a screen belongs to. The Kaname centre line belongs to the active tab (SPEC §13): a screen's
/// line sits on its own bead's axis and runs into the bead at 12 o'clock (Schedule's timeline, Tim 2026-11-06).
struct ItTabSlot: Equatable {
    let index: Int
    let count: Int
    /// The bead's centre across a screen this wide (the bar spans the screen).
    func axis(width: CGFloat) -> CGFloat { ItMeniscusGeometry.slotCentre(index, count: max(count, 1), width: width) }
}

private struct ItTabSlotKey: EnvironmentKey { static let defaultValue: ItTabSlot? = nil }

extension EnvironmentValues {
    var itTabSlot: ItTabSlot? {
        get { self[ItTabSlotKey.self] }
        set { self[ItTabSlotKey.self] = newValue }
    }
}

extension Notification.Name {
    /// The Receipts bead was tapped while Receipts is showing: open the receipt camera (ReceiptsView).
    static let mwSnapReceipt = Notification.Name("ca.mowology.snapReceipt")
    static let mwKanameHome = Notification.Name("ca.mowology.kanameHome")
    /// Job or Client was picked from the bar's Add dial: Schedule opens "Add a job" (userInfo `startWithNewClient`).
    static let mwAddFromBar = Notification.Name("ca.mowology.addFromBar")
}
