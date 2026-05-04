//
//  RootView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//
//  PRODUCTION SAFETY — READ BEFORE EDITING
//  ────────────────────────────────────────
//  AppState is the top-level gate controlling what the crew member sees after
//  login. Getting the transitions wrong is bad in both directions:
//    - Gate fires when it shouldn't → crew can't clock in
//    - Gate skips when required     → pre-shift training bypassed
//
//  AppState.quizRequired carries QuizPreshiftStatus as an associated value.
//  This eliminates the double-fetch that would occur if QuizPreshiftGateView
//  re-fetched status on its own .task. The status is fetched once here and
//  forwarded to the gate view via its initialStatus parameter.
//  Do NOT remove the associated value and re-fetch in the gate view — it would
//  add a round-trip and race against any network condition on app launch.
//
//  checkPreshiftRequirement() is intentionally fail-open: if the API call
//  fails for any reason (no network, 500 error, decoding failure), appState
//  becomes .ready and the crew member proceeds. Never block crew from reaching
//  the app due to a transient quiz API failure.
//
//  .onChange(of: authSession.isAuthenticated) re-runs the check on each login.
//  This is required — if a crew member logs out and back in, the check must
//  run again (a different day's pre-shift status may apply).

import SwiftUI

private enum AppState {
    case unauthenticated
    case checkingQuiz
    case quizRequired(QuizPreshiftStatus)
    case ready
}

struct RootView: View {

    @EnvironmentObject private var authSession: AuthSession
    @State private var appState: AppState = .unauthenticated

    #if DEBUG
    @ObservedObject private var devErrors = DevErrorBus.shared
    #endif

    var body: some View {
        Group {
            switch appState {

            case .unauthenticated:
                LoginView(authSession: authSession)

            case .checkingQuiz:
                VStack(spacing: 16) {
                    ProgressView()
                        .scaleEffect(1.2)
                    Text("Loading…")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .background(Color(.systemGroupedBackground))

            case .quizRequired(let preshiftStatus):
                QuizPreshiftGateView(authSession: authSession, preshiftStatus: preshiftStatus) {
                    withAnimation(.easeInOut(duration: 0.3)) {
                        appState = .ready
                    }
                }
                .environmentObject(authSession)

            case .ready:
                MainTabView()
                    .environmentObject(authSession)
            }
        }
        .animation(.easeInOut(duration: 0.3), value: appState == .ready)
        .onChange(of: authSession.isAuthenticated) { isAuthenticated in
            if isAuthenticated {
                Task { await checkPreshiftRequirement() }
            } else {
                appState = .unauthenticated
            }
        }
        .onAppear {
            if authSession.isAuthenticated {
                Task { await checkPreshiftRequirement() }
            }
        }
        #if DEBUG
        .alert("Dev Error", isPresented: Binding(
            get: { devErrors.pendingError != nil },
            set: { if !$0 { devErrors.pendingError = nil } }
        )) {
            Button("OK") { devErrors.pendingError = nil }
        } message: {
            Text(devErrors.pendingError ?? "")
        }
        #endif
    }

    // MARK: - Pre-shift check

    @MainActor
    private func checkPreshiftRequirement() async {
        appState = .checkingQuiz
        do {
            let api    = APIClient(authSession: authSession)
            let status: QuizPreshiftStatus = try await api.request(.quizPreshift)
            if status.required && !status.completedToday {
                appState = .quizRequired(status)
            } else {
                appState = .ready
            }
        } catch {
            // If the check fails (e.g., network down), proceed to app without blocking
            appState = .ready
        }
    }
}

#Preview {
    RootView()
        .environmentObject(AuthSession())
}
