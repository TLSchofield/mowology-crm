//
//  ClockInIntent.swift
//  MowologyCRM
//
//  App Intent for Siri / Shortcuts: "Clock in to Mowology".
//  Usable by gloved/driving crew without unlocking the phone.
//
//  Runs out-of-process — constructs its own auth from Keychain.
//  Does NOT touch GPSTrackingService (no location during Intent).
//

import AppIntents
import Foundation

struct ClockInIntent: AppIntent {

    static var title: LocalizedStringResource = "Clock In"
    static var description = IntentDescription(
        "Clock in to start your Mowology shift.",
        categoryName: "Time Clock"
    )
    static var openAppWhenRun = false

    @MainActor
    func perform() async throws -> some IntentResult & ProvidesDialog {
        let session = AuthSession()
        guard session.isAuthenticated else {
            return .result(dialog: "You're not logged in to Mowology.")
        }
        let client = APIClient(authSession: session)

        struct ClockResp: Decodable {
            let success: Bool
            let clockedIn: Bool
            let message: String?
            enum CodingKeys: String, CodingKey {
                case success; case clockedIn = "clocked_in"; case message
            }
        }

        let body: [String: Any] = ["action": "clock_in"]
        guard let response: ClockResp = try? await client.request(.scheduleClock, body: body, retryable: true),
              response.success else {
            return .result(dialog: "Couldn't clock in. Open the app to try again.")
        }

        if response.clockedIn {
            return .result(dialog: "Clocked in. Have a great shift!")
        } else {
            return .result(dialog: "Already clocked in.")
        }
    }
}
