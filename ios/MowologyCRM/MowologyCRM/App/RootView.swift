import SwiftUI

struct RootView: View {

    @EnvironmentObject private var authSession: AuthSession
    private let ittaiDesignEnabled = true   // Ittai for everyone from 1.3.56 (Tim, 2026-10-19); the classic look is retired
    @AppStorage("ittai.design.field") private var ittaiFieldEnabled = false
    @State private var updateResult: VersionCheckResult? = nil
    /// Charlie's brain on the opening screen, then (admins, first open of the day) his morning
    /// brief landing in the app — OpeningOverlay / CharlieMorningBrief.swift.
    @State private var showSplash = AuthSession.shared.isAuthenticated   // signed-out launches open on the Ittai wordmark instead (SignedOutFlow)
    @State private var onboardingTick = 0
    @StateObject private var briefLoader = MorningBriefLoader()
    /// An admin who hasn't had today's brief yet. nil = signed in but the user isn't loaded yet.
    private var showBrief: Bool? {
        guard authSession.isAuthenticated else { return false }
        guard let user = authSession.user else { return nil }
        return user.isAdmin && !MorningBriefStore.shownToday
    }

    /// A person who signed up on this phone owes the onboarding until they tap "Open my day". Existing logins have none.
    private var onboardingPending: Bool {
        _ = onboardingTick
        guard authSession.isAuthenticated, let user = authSession.user else { return false }
        return OnboardingProgress.pending(userId: user.id)
    }

    /// The one-time welcome for this person in this business. The iPhone has no per-business module flags yet (one server,
    /// Server.base): the Add dial offers a job to every role and the Receipts tab is always there, so both are on.
    private func welcomeCard() -> KanameWelcomeCard? {
        guard authSession.isAuthenticated, let user = authSession.user else { return nil }
        let host = Server.base.host ?? Server.base.absoluteString
        let entry = KanameWelcome.entry(canAddJobs: true, receiptsOn: true)
        guard KanameWelcome.shouldShowWelcome(seen: KanameWelcome.isSeen(host: host, userId: user.id), entry: entry),
              let entry else { return nil }
        return KanameWelcomeCard(entry: entry, firstName: KanameWelcome.firstName(fromFullName: user.name), host: host, userId: user.id)
    }

    var body: some View {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("-mwSmokeTour") {
            MainTabView()
                .environmentObject(authSession)
                .itAppTheme(enabled: true, field: false)
        } else if ProcessInfo.processInfo.arguments.contains("-mwContactsDemo") {
            PhoneContactsDemoHost()
                .itAppTheme(enabled: true, field: false)
        } else {
            authenticatedContent
        }
        #else
        authenticatedContent
        #endif
    }

    private var authenticatedContent: some View {
        ZStack {
        Group {
            if let result = updateResult, result.mustUpdate {
                AppUpdateView(result: result)
            } else if authSession.isAuthenticated {
                if onboardingPending, let user = authSession.user {
                    BusinessOnboardingHost(authSession: authSession, userId: user.id) {
                        UserDefaults.standard.set(OnboardingBarLogic.Landing.receipts.rawValue, forKey: OnboardingBarLogic.landingKey)   // main app opens on Receipts
                        OnboardingProgress.finish(userId: user.id)
                        onboardingTick += 1
                        showSplash = true   // then the app's normal opening (Kaname's welcome)
                    }
                    .itAppTheme(enabled: ittaiDesignEnabled, field: false)
                } else {
                    MainTabView()
                        .environmentObject(authSession)
                        .itAppTheme(enabled: ittaiDesignEnabled, field: ittaiFieldEnabled)
                }
            } else {
                SignedOutFlow(authSession: authSession)
            }
        }
        .animation(.easeInOut(duration: 0.3), value: authSession.isAuthenticated)

            if showSplash {
                OpeningOverlay(wantsBrief: { showBrief }, loader: briefLoader,
                               welcome: { welcomeCard() },
                               onWelcomeAction: { entry in
                                   NotificationCenter.default.post(name: .mwKanameWelcomeAction, object: nil,
                                                                   userInfo: ["entry": entry.rawValue])
                               }) {
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
