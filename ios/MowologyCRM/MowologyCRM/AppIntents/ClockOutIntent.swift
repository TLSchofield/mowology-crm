//
//  ClockOutIntent.swift
//  MowologyCRM
//
//  App Intent for Siri / Shortcuts: "Clock out of Mowology".
//

import AppIntents
import Foundation

struct ClockOutIntent: AppIntent {

    static var title: LocalizedStringResource = "Clock Out"
    static var description = IntentDescription(
        "Clock out to end your Mowology shift.",
        categoryName: "Time Clock"
    )
    static var openAppWhenRun = false

    func perform() async throws -> some IntentResult & ProvidesDialog {
        let session = await MainActor.run { AuthSession() }
        guard session.isAuthenticated else {
            return .result(dialog: "You're not logged in to Mowology.")
        }
        let client = APIClient(authSession: session)

        struct ClockResp: Decodable {
            let success: Bool
            let clockedIn: Bool
            let totalMinutes: Int?
            let message: String?
            enum CodingKeys: String, CodingKey {
                case success; case clockedIn = "clocked_in"
                case totalMinutes = "total_minutes"; case message
            }
        }

        let body: [String: Any] = ["action": "clock_out"]
        guard let response: ClockResp = try? await client.request(.scheduleClock, body: body),
              response.success else {
            return .result(dialog: "Couldn't clock out. Open the app to try again.")
        }

        if let minutes = response.totalMinutes, minutes > 0 {
            let h = minutes / 60
            let m = minutes % 60
            let duration = h > 0 ? "\(h) hour\(h == 1 ? "" : "s") \(m) minute\(m == 1 ? "" : "s")" : "\(m) minute\(m == 1 ? "" : "s")"
            return .result(dialog: "Clocked out. Shift was \(duration). Great work!")
        }
        return .result(dialog: "Clocked out.")
    }
}
