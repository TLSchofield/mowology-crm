/// Defines the shared ActivityKit model for keeping a clocked-in shift visible while Ittai is backgrounded, matching the persistent at-a-glance behavior requested after seeing Jobber.

import ActivityKit
import Foundation

struct ClockActivityAttributes: ActivityAttributes {
    struct ContentState: Codable, Hashable {
        var shiftStart: Date
        var breakStart: Date?

        var isOnBreak: Bool { breakStart != nil }

        static func accessibilityDuration(from start: Date, now: Date) -> String {
            let duration = max(0, now.timeIntervalSince(start))
            guard duration >= 60 else { return "Less than a minute" }

            let formatter = DateComponentsFormatter()
            formatter.allowedUnits = [.hour, .minute]
            formatter.unitsStyle = .full
            formatter.maximumUnitCount = 2
            return formatter.string(from: duration) ?? "Less than a minute"
        }
    }

    var businessName: String
}
