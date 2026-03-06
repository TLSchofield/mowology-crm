//
//  RootView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct RootView: View {

    @EnvironmentObject private var authSession: AuthSession

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
    }
}

#Preview {
    RootView()
        .environmentObject(AuthSession())
}
