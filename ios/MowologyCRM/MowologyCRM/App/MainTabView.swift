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
    @StateObject private var readyBriefStore = ReadyBriefStore()
    private let ittaiDesignEnabled = true   // Ittai for everyone from 1.3.56 (Tim, 2026-10-19); the classic look is retired
    @AppStorage("ittai.design.field") private var ittaiFieldEnabled = false

    /// Order: home first, the twice-a-day clock next to it, Search in the centre where either
    /// thumb reaches it, then the occasional Receipts, and Account last by convention.
    /// For admins the last tab is Team (it carries Account's profile row and Sign Out).
    private enum Tab: Hashable { case schedule, timeClock, search, receipts, account }
    @State private var selectedTab = Tab.schedule
    @State private var keyboardUp = false
    private static let order: [Tab] = [.schedule, .timeClock, .search, .receipts, .account]

    /// The meniscus bar's tabs, in `order`; the last is AI Office for admins, Account for everyone else.
    private var meniscusItems: [ItMeniscusItem] {
        let admin = authSession.user?.isAdmin == true
        return [
            ItMeniscusItem(id: 0, title: "Schedule", icon: Image(systemName: "calendar")),
            ItMeniscusItem(id: 1, title: "Time Clock", icon: Image(systemName: "clock.fill")),
            ItMeniscusItem(id: 2, title: "Search", icon: Image(systemName: "magnifyingglass")),
            ItMeniscusItem(id: 3, title: "Receipts", icon: Image(systemName: "doc.text.image")),
            ItMeniscusItem(id: 4, title: admin ? "AI Office" : "Account",
                           icon: admin ? Image(uiImage: AI_OfficeTabIcon.image) : Image(systemName: "person.fill"),
                           iconScale: admin ? 1.35 : 1),
        ]
    }

    init() {
        // Belt and braces with `.toolbar(.hidden, for: .tabBar)` on each tab: the meniscus bar replaces the
        // system tab bar, so it must never show as a second bar (no simulator on this Mac to prove every screen).
        UITabBar.appearance().isHidden = true
    }

    var body: some View {
        let _ = RenderRate.tick("MainTabView")
        TabView(selection: $selectedTab) {
            ScheduleView(authSession: authSession)
                .tabItem {
                    Label("Schedule", systemImage: "calendar")
                }
                .tag(Tab.schedule)
                .toolbar(.hidden, for: .tabBar)

            TimeClockView(authSession: authSession)
                .tabItem {
                    Label("Time Clock", systemImage: "clock.fill")
                }
                .tag(Tab.timeClock)
                .toolbar(.hidden, for: .tabBar)

            SearchView(authSession: authSession)
                .tabItem {
                    Label("Search", systemImage: "magnifyingglass")
                }
                .tag(Tab.search)
                .toolbar(.hidden, for: .tabBar)

            ReceiptsView(authSession: authSession)
                .environmentObject(authSession)
                .tabItem {
                    Label("Receipts", systemImage: "doc.text.image")
                }
                .tag(Tab.receipts)
                .toolbar(.hidden, for: .tabBar)

            // Admins: Team (department heads + Penny's receipt card, with the profile
            // row and Sign Out moved in). Everyone else: the Account tab, unchanged.
            if authSession.user?.isAdmin == true {
                TeamView(authSession: authSession, readyBriefStore: readyBriefStore)
                    .tabItem {
                        Label("AI Office", uiImage: AI_OfficeTabIcon.image)
                    }
                    .tag(Tab.account)
                    .toolbar(.hidden, for: .tabBar)
            } else {
                accountTab
                    .tabItem {
                        Label("Account", systemImage: "person.fill")
                    }
                    .tag(Tab.account)
                    .toolbar(.hidden, for: .tabBar)
            }
        }
        // The meniscus bar replaces the system tab bar (Tim, 2026-11-04). It steps aside while the keyboard is up.
        .safeAreaInset(edge: .bottom, spacing: 0) {
            if !keyboardUp {
                ItMeniscusBar(items: meniscusItems, selected: Binding(
                    get: { Self.order.firstIndex(of: selectedTab) ?? 0 },
                    set: { selectedTab = Self.order[$0] }))
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillShowNotification)) { _ in keyboardUp = true }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillHideNotification)) { _ in keyboardUp = false }
        .tint(appStyle.tenant)
        .itAppScreen()
        // The AI Office bar: each tab but Schedule shows it under its own title (itOfficeBarSlot), as on Android.
        .environment(\.itOfficeBarShown, authSession.user?.isAdmin == true && ittaiDesignEnabled)
        .environment(\.itOfficeTabShowing, selectedTab == .account)
        .environmentObject(readyBriefStore)
        .task {
            if authSession.user?.isAdmin == true && ittaiDesignEnabled {
                await readyBriefStore.refresh(authSession: authSession)
            }
        }
        .sheet(item: $pullForward.offer, onDismiss: { pullForward.sheetDismissed() }) { offer in
            PullForwardSheet(coordinator: pullForward, offer: offer)
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active {
                pullForward.appBecameActive(authSession: authSession)
                checkDriverQuestion()
            }
            else if phase == .background { pullForward.appWentInactive() }
        }
        // The vehicle question is keyed off the server's shift, not the Clock In button: an
        // automatic clock-in (arrival, job start) used to skip the pre-trip entirely.
        .onReceive(NotificationCenter.default.publisher(for: .mwShiftMayHaveStarted)) { _ in
            checkDriverQuestion()
        }
        // Every notification route today lands on the schedule; ScheduleView
        // consumes the route itself and opens the stop.
        .onChange(of: notificationRouter.pendingRoute) { _, route in
            if route != nil { selectedTab = .schedule }
        }
        .onAppear {
            pullForward.appBecameActive(authSession: authSession)
            checkDriverQuestion()
            if notificationRouter.pendingRoute != nil { selectedTab = .schedule }
            else if notificationRouter.pendingHead != nil { openTeamForHead() }
        }
        // Department-head pushes ("Sam: Linda just opened QUO-…") land on the Team tab;
        // TeamView consumes the route and shows that head's card. Admins only have Team.
        .onChange(of: notificationRouter.pendingHead) { _, head in
            if head != nil { openTeamForHead() }
        }
    }

    /// Clocked in on the server but not yet asked "are you driving today?" → open the Time Clock
    /// tab, whose own sheet asks (no new root-level sheet: those blocked other sheets 2026-10-08).
    private func checkDriverQuestion() {
        Task {
            if await DriverTripViewModel.shiftNeedsDriverAnswer(authSession: authSession),
               notificationRouter.pendingRoute == nil {
                selectedTab = .timeClock
            }
        }
    }

    private func openTeamForHead() {
        if authSession.user?.isAdmin == true {
            selectedTab = .account
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
        }
    }
}

@MainActor
private enum AI_OfficeTabIcon {
    /// Render once: the tab bar then applies its normal selected/unselected tint.
    static let image: UIImage = {
        let renderer = ImageRenderer(content: ItSymbol(size: 28).environment(\.it, ItPalette(.light)))
        renderer.scale = UIScreen.main.scale
        return (renderer.uiImage ?? UIImage(systemName: "person.3.fill")!)
            .withRenderingMode(.alwaysTemplate)
    }()
}

private extension Label where Title == Text, Icon == Image {
    init(_ title: String, uiImage: UIImage) {
        self.init { Text(title) } icon: { Image(uiImage: uiImage) }
    }
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
