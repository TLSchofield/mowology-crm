//
//  MainTabView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-06.
//

import SwiftUI

struct MainTabView: View {

    @EnvironmentObject private var authSession: AuthSession

    private var isAdmin: Bool { authSession.user?.isAdmin ?? false }

    var body: some View {
        TabView {
            ScheduleView(authSession: authSession)
                .tabItem {
                    Label("Schedule", systemImage: "calendar")
                }

            TimeClockView(authSession: authSession)
                .tabItem {
                    Label("Time Clock", systemImage: "clock.fill")
                }

            JobsListView(authSession: authSession)
                .tabItem {
                    Label("Jobs", systemImage: "briefcase.fill")
                }

            ReceiptsView(authSession: authSession)
                .environmentObject(authSession)
                .tabItem {
                    Label("Receipts", systemImage: "doc.text.image")
                }

            accountTab
                .tabItem {
                    Label("Account", systemImage: "person.fill")
                }
        }
        .tint(Color.MW.green)
        .onAppear {
            // Ask for location permission on first authenticated launch so crews
            // see the system prompt in context — not buried inside clock-in.
            GPSTrackingService.shared.requestPermissionIfNeeded()
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
                                Text(user.role.capitalized)
                                    .font(.caption2)
                                    .foregroundStyle(Color.MW.green)
                            }
                        }
                        .padding(.vertical, 4)
                    }
                }

                if isAdmin {
                    Section("Admin") {
                        NavigationLink {
                            QuotesView(authSession: authSession)
                        } label: {
                            Label("Quotes", systemImage: "doc.text")
                        }

                        NavigationLink {
                            InvoicesView(authSession: authSession)
                        } label: {
                            Label("Invoices", systemImage: "doc.plaintext")
                        }
                    }
                }

                Section {
                    Button(role: .destructive) {
                        authSession.logout()
                    } label: {
                        Label("Sign Out", systemImage: "rectangle.portrait.and.arrow.right")
                    }
                }
            }
            .navigationTitle("Account")
            .navigationBarTitleDisplayMode(.inline)
        }
    }
}

#Preview {
    MainTabView()
        .environmentObject(AuthSession())
}
