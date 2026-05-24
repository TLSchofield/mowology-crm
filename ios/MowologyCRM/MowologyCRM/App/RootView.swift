import SwiftUI

struct RootView: View {

    @EnvironmentObject private var authSession: AuthSession
    @State private var updateResult: VersionCheckResult? = nil

    var body: some View {
        Group {
            if let result = updateResult, result.mustUpdate {
                AppUpdateView(result: result)
            } else if authSession.isAuthenticated {
                MainTabView()
                    .environmentObject(authSession)
                    .gpsTrackingBanner()
            } else {
                LoginView(authSession: authSession)
            }
        }
        .animation(.easeInOut(duration: 0.3), value: authSession.isAuthenticated)
        .task {
            let result = await VersionCheckService.shared.check()
            if result.mustUpdate {
                updateResult = result
            }
        }
    }
}

#Preview {
    RootView()
        .environmentObject(AuthSession())
}
