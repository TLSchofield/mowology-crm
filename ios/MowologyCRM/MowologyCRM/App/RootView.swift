//
//  RootView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct RootView: View {

    @EnvironmentObject private var authSession: AuthSession
    #if DEBUG
    @ObservedObject private var devErrors = DevErrorBus.shared
    #endif

    var body: some View {
        Group {
            if authSession.isAuthenticated {
                MainTabView()
                    .environmentObject(authSession)
            } else {
                LoginView(authSession: authSession)
            }
        }
        .animation(.easeInOut(duration: 0.3), value: authSession.isAuthenticated)
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
}

#Preview {
    RootView()
        .environmentObject(AuthSession())
}
