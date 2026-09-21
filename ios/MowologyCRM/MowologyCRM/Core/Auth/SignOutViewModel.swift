//
//  SignOutViewModel.swift
//  MowologyCRM
//
//  Crew treat "Sign Out" as "done for the day" — but signing out never clocked anyone out,
//  so shifts ran on to the 12 h auto clock-out and recorded hours were wrong. If you are
//  still clocked in, you are asked first (mirrors /crm/logout_secure.php on Android/web).
//

import Foundation

@MainActor
final class SignOutViewModel: ObservableObject {

    @Published var showClockedInPrompt = false
    @Published var isWorking           = false
    @Published var errorMessage: String?

    private let authSession: AuthSession
    private let apiClient:   APIClient

    init(authSession: AuthSession) {
        self.authSession = authSession
        self.apiClient   = APIClient(authSession: authSession)
    }

    /// Sign Out tapped. Signs out straight away unless a shift is open.
    func begin() async {
        isWorking    = true
        errorMessage = nil
        defer { isWorking = false }

        let clockedIn: Bool
        if let status: ClockStatusResponse = try? await apiClient.request(.scheduleClockStatus) {
            clockedIn = status.clockedIn
        } else {
            // No signal — go by what this phone last knew.
            clockedIn = TimeClockViewModel.persistedClockedIn
        }

        // A 401 during the check already signed us out.
        guard authSession.isAuthenticated else { return }

        if clockedIn {
            showClockedInPrompt = true
        } else {
            authSession.logout()
        }
    }

    /// "Clock out and sign out". An open vehicle trip holds the clock-out, same as the Time
    /// Clock tab: the commercial log needs its end odometer and hours before the shift closes.
    func clockOutAndSignOut() async {
        isWorking    = true
        errorMessage = nil
        defer { isWorking = false }

        let driver = DriverTripViewModel(authSession: authSession)
        await driver.refresh()
        if driver.hasOpenTrip {
            errorMessage = "You have an open vehicle trip. Clock out from the Time Clock tab to do the post-trip check first, then sign out."
            return
        }

        do {
            let _: ClockActionResponse = try await apiClient.request(
                .scheduleClock,
                body: ["action": "clock_out", "notes": "Clocked out at sign-out"]
            )
            TimeClockViewModel.clearPersistedClockState()
            authSession.logout()
        } catch let err as APIError {
            if case .networkError = err {
                errorMessage = "No connection — you're still clocked in. Try again when you have signal."
            } else {
                errorMessage = err.localizedDescription
            }
        } catch {
            errorMessage = "Couldn't clock you out. Try again."
        }
    }

    /// "Sign out only" — the shift stays open on the server.
    func signOutOnly() {
        authSession.logout()
    }
}
