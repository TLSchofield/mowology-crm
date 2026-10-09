import SwiftUI

struct RootView: View {

    @EnvironmentObject private var authSession: AuthSession
    @State private var updateResult: VersionCheckResult? = nil
    /// Charlie's brain on the opening screen (CharlieBrainSplash), briefly, then fades.
    @State private var showSplash = true

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
                CharlieBrainSplash()
                    .transition(.opacity)
                    .zIndex(1)
            }
        }
        .task {
            let result = await VersionCheckService.shared.check()
            if result.mustUpdate {
                updateResult = result
            }
        }
        .task {
            try? await Task.sleep(nanoseconds: 1_800_000_000)
            withAnimation(.easeOut(duration: 0.45)) { showSplash = false }
        }
        .task(id: authSession.isAuthenticated) {
            // Keep the opening screen's brain current for next launch.
            guard authSession.isAuthenticated else { return }
            let client = APIClient(authSession: authSession)
            if let r: HeadCardResponse = try? await client.request(.teamHeadBrief(head: "charlie")),
               r.ok, let brain = r.brain {
                CharlieBrainCache.save(brain)
            }
        }
    }
}

#Preview {
    RootView()
        .environmentObject(AuthSession())
}
