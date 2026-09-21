//
//  MainTabView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-06.
//

import SwiftUI

struct MainTabView: View {

    @EnvironmentObject private var authSession: AuthSession
    @ObservedObject private var notificationRouter = NotificationRouter.shared

    private enum Tab: Hashable { case schedule, timeClock, receipts, account }
    @State private var selectedTab = Tab.schedule

    var body: some View {
        TabView(selection: $selectedTab) {
            ScheduleView(authSession: authSession)
                .tabItem {
                    Label("Schedule", systemImage: "calendar")
                }
                .tag(Tab.schedule)

            TimeClockView(authSession: authSession)
                .tabItem {
                    Label("Time Clock", systemImage: "clock.fill")
                }
                .tag(Tab.timeClock)

            ReceiptsView(authSession: authSession)
                .environmentObject(authSession)
                .tabItem {
                    Label("Receipts", systemImage: "doc.text.image")
                }
                .tag(Tab.receipts)

            // Account / sign out
            accountTab
                .tabItem {
                    Label("Account", systemImage: "person.fill")
                }
                .tag(Tab.account)
        }
        .tint(Color.MW.green)
        // Every notification route today lands on the schedule; ScheduleView
        // consumes the route itself and opens the stop.
        .onChange(of: notificationRouter.pendingRoute) { _, route in
            if route != nil { selectedTab = .schedule }
        }
        .onAppear {
            if notificationRouter.pendingRoute != nil { selectedTab = .schedule }
        }
    }

    // MARK: - Placeholder

    private func comingSoonTab(title: String, icon: String) -> some View {
        NavigationStack {
            VStack(spacing: 16) {
                Image(systemName: icon)
                    .font(.system(size: 48))
                    .foregroundStyle(Color(.systemGray3))

                Text(title)
                    .font(.title2.bold())
                    .foregroundStyle(.primary)

                Text("Coming soon in v2")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color(.systemGroupedBackground))
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
                        HStack(spacing: 14) {
                            Image(systemName: "person.circle.fill")
                                .font(.system(size: 44))
                                .foregroundStyle(Color.MW.green)

                            VStack(alignment: .leading, spacing: 2) {
                                Text(user.name)
                                    .font(.headline)
                                Text(user.email)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .padding(.vertical, 4)
                    }
                }

                Section {
                    SignOutButton(authSession: authSession)
                }
            }
            .navigationTitle("Account")
            .navigationBarTitleDisplayMode(.inline)
        }
    }
}

// MARK: - Sign Out

/// Sign Out — asks first when a shift is still open (see SignOutViewModel).
private struct SignOutButton: View {

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
            Text("Signing out doesn't end your shift — your hours keep running until you clock out. Location tracking on this phone stops when you sign out.")
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
