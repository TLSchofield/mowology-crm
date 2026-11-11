//
//  TimeChip.swift
//  MowologyCRM
//
//  Time left the tab bar for a chip at the top right of every tab (Tim, 2026-11-05: "Time could move to very
//  top?"). It shows the shift at a glance — Clock in / 0:42 / On break 0:12 — and a tap opens Time Clock.
//  The chip reads ClockChipStore, which TimeClockViewModel keeps current; breaks come from BreakController.
//

import SwiftUI

/// What the chip shows. TimeClockViewModel writes it whenever it saves the clock state, so the chip and the
/// Time Clock screen never disagree; `refresh` reads the server when Time Clock hasn't been opened yet.
@MainActor
final class ClockChipStore: ObservableObject {
    static let shared = ClockChipStore()

    @Published private(set) var clockedIn = TimeClockViewModel.persistedClockedIn
    /// When the shift started, as far as this phone knows. nil while clocked in = time unknown (offline restart).
    @Published private(set) var shiftStart: Date?

    /// When the Time Clock last wrote the state: a server read that started before it is stale (Codex 2026-11-05).
    private var lastWrite = Date.distantPast

    /// `elapsedSeconds` nil = clocked in but the time isn't known (restored from disk without signal).
    func set(clockedIn: Bool, elapsedSeconds: Int?) {
        lastWrite = Date()
        apply(clockedIn: clockedIn, elapsedSeconds: elapsedSeconds)
    }

    private func apply(clockedIn: Bool, elapsedSeconds: Int?) {
        self.clockedIn = clockedIn
        shiftStart = clockedIn ? elapsedSeconds.map { Date().addingTimeInterval(-Double($0)) } : nil
    }

    /// Read the shift from the server. Silent without signal: the chip keeps the last thing it knew.
    func refresh(authSession: AuthSession) async {
        guard authSession.token != nil else { return }
        let asked = Date()
        do {
            let status: ClockStatusResponse = try await APIClient(authSession: authSession).request(.scheduleClockStatus)
            // A clock in/out made while this was in flight wins over the older answer.
            guard lastWrite < asked else { return }
            apply(clockedIn: status.clockedIn, elapsedSeconds: status.elapsedSeconds ?? 0)
            BreakController.shared.apply(status)
        } catch {
            AppDiagnostics.shared.record(category: "timechip.status", message: ErrorDetails.describe(error))
        }
    }
}

struct TimeChip: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.openTimeClock) private var openTimeClock
    @ObservedObject private var store = ClockChipStore.shared
    @ObservedObject private var breaks = BreakController.shared

    var body: some View {
        Button { openTimeClock() } label: {
            TimelineView(.periodic(from: .now, by: 20)) { context in
                label(now: context.date)
            }
        }
        .buttonStyle(.plain)
        .accessibilityHint("Opens Time Clock")
    }

    @ViewBuilder
    private func label(now: Date) -> some View {
        let content: (icon: String?, text: String, spoken: String) = {
            if !store.clockedIn { return ("play.fill", "Clock in", "Clocked out") }
            if let start = breaks.startedAt {
                let t = Self.hm(now.timeIntervalSince(start))
                return ("pause.fill", "On break \(t)", "On break, \(t)")
            }
            guard let start = store.shiftStart else { return (nil, "Clocked in", "Clocked in") }
            let t = Self.hm(now.timeIntervalSince(start))
            return (nil, t, "Clocked in, \(t) today")
        }()
        HStack(spacing: 7) {
            if let icon = content.icon {
                Image(systemName: icon).font(.system(size: 13, weight: .semibold))
            } else {
                Circle().fill(appStyle.color(.stateWorking, legacy: .green)).frame(width: 9, height: 9)
            }
            Text(content.text)
                .font(appStyle.font(.subheadline, weight: .semibold))
                .monospacedDigit()
                .lineLimit(1)
        }
        .padding(.horizontal, 14)
        .frame(minHeight: 36)
        .foregroundStyle(store.clockedIn ? appStyle.text : appStyle.tenant)
        .background {
            Capsule().fill(store.clockedIn ? (breaks.isOnBreak ? appStyle.sunk : appStyle.color(.tenantSoft, legacy: Color.MW.green.opacity(0.12))) : .clear)
        }
        .overlay {
            if !store.clockedIn { Capsule().stroke(appStyle.tenant, lineWidth: 1.5) }
        }
        // The pill is drawn smaller than the bar; the target stays 48 tall (v1.2 design rules).
        .frame(minHeight: 48)
        .contentShape(Rectangle())
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(content.spoken)
    }

    /// 0:42, 1:05 — hours and minutes, like the Time Clock screen.
    private static func hm(_ seconds: TimeInterval) -> String {
        let m = max(0, Int(seconds) / 60)
        return "\(m / 60):" + String(format: "%02d", m % 60)
    }
}

/// Opens the Time Clock page (MainTabView). A no-op outside the tabs, e.g. in previews.
private struct OpenTimeClockKey: EnvironmentKey { static let defaultValue: () -> Void = {} }

extension EnvironmentValues {
    var openTimeClock: () -> Void {
        get { self[OpenTimeClockKey.self] }
        set { self[OpenTimeClockKey.self] = newValue }
    }
}
