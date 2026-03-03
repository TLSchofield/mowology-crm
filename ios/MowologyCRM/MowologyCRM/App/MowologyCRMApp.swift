//
//  MowologyCRMApp.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

@main
struct MowologyCRMApp: App {

    @StateObject private var authSession = AuthSession()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(authSession)
        }
    }
}
