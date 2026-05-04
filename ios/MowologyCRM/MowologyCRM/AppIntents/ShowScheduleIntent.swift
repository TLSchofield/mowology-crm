//
//  ShowScheduleIntent.swift
//  MowologyCRM
//
//  App Intent: "Show my schedule" — opens app to the Schedule tab.
//  Also exposed as a Spotlight shortcut so crew can find it by name.
//

import AppIntents

struct ShowScheduleIntent: AppIntent {

    static var title: LocalizedStringResource = "Show Today's Schedule"
    static var description = IntentDescription(
        "Open Mowology and show today's job stops.",
        categoryName: "Schedule"
    )
    static var openAppWhenRun = true

    // Siri donation: after the user opens the app most mornings, iOS learns
    // to suggest this shortcut on the Lock Screen around that time.
    static var isDiscoverable = true

    func perform() async throws -> some IntentResult {
        // openAppWhenRun = true handles the navigation; no additional work needed.
        return .result()
    }
}

// MARK: - AppShortcutsProvider

// Makes "Hey Siri, show my schedule" work without user setup.
struct MowologyShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(
            intent: ShowScheduleIntent(),
            phrases: [
                "Show my schedule in \(.applicationName)",
                "Open \(.applicationName) schedule",
                "What are my jobs today in \(.applicationName)"
            ],
            shortTitle: "Today's Schedule",
            systemImageName: "calendar"
        )
        AppShortcut(
            intent: ClockInIntent(),
            phrases: [
                "Clock in to \(.applicationName)",
                "Start my shift in \(.applicationName)"
            ],
            shortTitle: "Clock In",
            systemImageName: "clock.badge.checkmark"
        )
        AppShortcut(
            intent: ClockOutIntent(),
            phrases: [
                "Clock out of \(.applicationName)",
                "End my shift in \(.applicationName)"
            ],
            shortTitle: "Clock Out",
            systemImageName: "clock.badge.xmark"
        )
    }
}
