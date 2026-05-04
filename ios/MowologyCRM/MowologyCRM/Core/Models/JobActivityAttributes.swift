//
//  JobActivityAttributes.swift
//  MowologyCRM
//
//  ActivityKit data model shared by the main app (Activity lifecycle) and
//  the MowologyWidgets extension (Lock Screen + Dynamic Island rendering).
//
//  Kept in a separate file so project.yml can compile it into both targets.
//

import ActivityKit
import Foundation

// MARK: - MowJobActivity

struct MowJobActivity: ActivityAttributes {

    // MARK: - Static (set once at Activity.request time)

    /// Exact moment the crew member clocked in. Used as the timer origin.
    let clockInDate: Date

    /// Display name of the logged-in crew member.
    let crewName: String

    // MARK: - ContentState (mutable — updated as shift progresses)

    struct ContentState: Codable, Hashable {
        /// Title of the currently-active job timer, nil when between jobs.
        var jobTitle: String?

        /// Property address of the current job, nil when between jobs.
        var address: String?

        /// True while a job timer is running; false during shift gaps.
        var isOnJob: Bool
    }
}
