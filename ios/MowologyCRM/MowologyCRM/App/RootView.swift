//
//  RootView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

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
