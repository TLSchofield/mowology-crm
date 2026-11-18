/// Keeps Ittai's clocked-in Live Activity synchronized with the app's existing time-clock state so the active shift remains visible at the top of the phone while the app is backgrounded, as requested after seeing Jobber.

import ActivityKit
import Combine
import Foundation

@MainActor
final class LiveActivityController {
    static let shared = LiveActivityController()

    private struct Snapshot: Equatable {
        let clockedIn: Bool
        let shiftStart: Date?
        let breakStart: Date?
    }

    private var cancellables = Set<AnyCancellable>()
    private var latestSnapshot: Snapshot?
    private var isReconciling = false
    private var isDirty = false
    private var didRecordDisabled = false

    private init() {}

    func start() {
        guard cancellables.isEmpty else {
            reconcileCurrentState()
            return
        }

        Publishers.CombineLatest3(
            ClockChipStore.shared.$clockedIn,
            ClockChipStore.shared.$shiftStart,
            BreakController.shared.$startedAt
        )
        .receive(on: RunLoop.main)
        .map { clockedIn, shiftStart, breakStart in
            Snapshot(clockedIn: clockedIn, shiftStart: shiftStart, breakStart: breakStart)
        }
        .removeDuplicates()
        .sink { [weak self] snapshot in
            self?.enqueue(snapshot)
        }
        .store(in: &cancellables)

        reconcileCurrentState()
    }

    func reconcile(clockedIn: Bool, shiftStart: Date?, breakStart: Date?) {
        enqueue(Snapshot(clockedIn: clockedIn, shiftStart: shiftStart, breakStart: breakStart))
    }

    func endAll() async {
        for activity in Activity<ClockActivityAttributes>.activities {
            await activity.end(nil, dismissalPolicy: .immediate)
        }
    }

    private func reconcileCurrentState() {
        reconcile(
            clockedIn: ClockChipStore.shared.clockedIn,
            shiftStart: ClockChipStore.shared.shiftStart,
            breakStart: BreakController.shared.startedAt
        )
    }

    private func enqueue(_ snapshot: Snapshot) {
        latestSnapshot = snapshot
        if isReconciling {
            isDirty = true
            return
        }

        isReconciling = true
        Task { [weak self] in
            await self?.runReconcileLoop()
        }
    }

    private func runReconcileLoop() async {
        repeat {
            isDirty = false
            if let snapshot = latestSnapshot {
                await performReconcile(snapshot)
            }
        } while isDirty
        isReconciling = false
    }

    private func performReconcile(_ snapshot: Snapshot) async {
        let activities = Activity<ClockActivityAttributes>.activities
        guard snapshot.clockedIn else {
            for activity in activities {
                await activity.end(nil, dismissalPolicy: .immediate)
            }
            return
        }

        guard let shiftStart = snapshot.shiftStart else { return }

        guard ActivityAuthorizationInfo().areActivitiesEnabled else {
            if !didRecordDisabled {
                didRecordDisabled = true
                AppDiagnostics.shared.record(
                    category: "liveactivity",
                    message: "Live Activities are disabled; clock activity was not changed."
                )
            }
            return
        }

        let state = ClockActivityAttributes.ContentState(
            shiftStart: shiftStart,
            breakStart: snapshot.breakStart
        )
        let content = ActivityContent(
            state: state,
            staleDate: shiftStart.addingTimeInterval(16 * 60 * 60)
        )

        if let retained = activities.first {
            if Self.state(retained.content.state, differsFrom: state) {
                await retained.update(content)
            }
            for duplicate in activities.dropFirst() {
                await duplicate.end(nil, dismissalPolicy: .immediate)
            }
            return
        }

        do {
            _ = try Activity.request(
                attributes: ClockActivityAttributes(businessName: "Ittai"),
                content: content,
                pushType: nil
            )
        } catch {
            AppDiagnostics.shared.record(
                category: "liveactivity",
                message: "Could not start clock activity: \(ErrorDetails.describe(error))"
            )
        }
    }

    private static func state(
        _ current: ClockActivityAttributes.ContentState,
        differsFrom proposed: ClockActivityAttributes.ContentState
    ) -> Bool {
        abs(current.shiftStart.timeIntervalSince(proposed.shiftStart)) > 2
            || current.breakStart != proposed.breakStart
    }
}
