//
//  MainTabView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-06.
//
//  PRODUCTION SAFETY — READ BEFORE EDITING
//  ────────────────────────────────────────
//  Tab order is intentional and crew-facing: Schedule → Time Clock →
//  Receipts → Quiz → Account. Time Clock is tab 2 so it's one tap from the
//  default (Schedule). Do not reorder tabs without checking with the crew —
//  muscle memory matters on a job site.
//
//  Quiz tab (brain.head.profile icon) is shown unconditionally. The pre-shift
//  gate in RootView.swift handles enforcement before the tabs appear. The Quiz
//  tab itself does not re-check the gate — it goes straight to QuizHubView.
//  If you add per-tab pre-shift enforcement here, you will double-gate crew.
//
//  All tabs except accountTab pass authSession explicitly to their views.
//  accountTab is inline because it has no separate view file — keep it inline
//  or extract it to a separate file, but do not change the logout mechanism
//  without updating AuthSession.logout().

import SwiftUI

struct MainTabView: View {

    @EnvironmentObject private var authSession: AuthSession

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

            ReceiptsView(authSession: authSession)
                .environmentObject(authSession)
                .tabItem {
                    Label("Receipts", systemImage: "doc.text.image")
                }

            QuizHubView(authSession: authSession)
                .environmentObject(authSession)
                .tabItem {
                    Label("Quiz", systemImage: "brain.head.profile")
                }

            accountTab
                .tabItem {
                    Label("Account", systemImage: "person.fill")
                }
        }
        .tint(Color.MW.green)
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
