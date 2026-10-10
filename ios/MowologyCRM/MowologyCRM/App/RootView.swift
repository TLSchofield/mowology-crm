import SwiftUI

struct RootView: View {

    @EnvironmentObject private var authSession: AuthSession
    @State private var updateResult: VersionCheckResult? = nil
    /// Charlie's brain on the opening screen, then (admins, first open of the day) his morning
    /// brief landing in the app — OpeningOverlay / CharlieMorningBrief.swift.
    @State private var showSplash = true
    @StateObject private var briefLoader = MorningBriefLoader()
    /// An admin who hasn't had today's brief yet (read when the opening screen starts).
    private var showBrief: Bool {
        authSession.isAuthenticated && authSession.user?.isAdmin == true && !MorningBriefStore.shownToday
    }

    var body: some View {
        ZStack {
        Group {
            if let result = updateResult, result.mustUpdate {
                AppUpdateView(result: result)
            } else if authSession.isAuthenticated {
                MainTabView()
                    .environmentObject(authSession)
            } else {
                LoginView(authSession: authSession)
            }
        }
        .animation(.easeInOut(duration: 0.3), value: authSession.isAuthenticated)

            if showSplash {
                OpeningOverlay(showBrief: showBrief, loader: briefLoader) {
                    showSplash = false
                }
                .zIndex(1)
            }
        }
        .task {
            let result = await VersionCheckService.shared.check()
            if result.mustUpdate {
                updateResult = result
            }
        }
        .task(id: authSession.isAuthenticated) {
            // Charlie's brief (and the brain for next launch). Bounded wait inside the loader.
            guard authSession.isAuthenticated else { return }
            await briefLoader.load(authSession: authSession)
        }
    }
}

#Preview {
    RootView()
        .environmentObject(AuthSession())
}
