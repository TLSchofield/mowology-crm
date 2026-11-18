/// Presents the active Ittai shift on the Lock Screen and Dynamic Island so clocked-in status stays visible while the app is backgrounded, matching the useful behavior observed in Jobber.

import ActivityKit
import SwiftUI
import WidgetKit

struct ClockLiveActivity: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: ClockActivityAttributes.self) { context in
            LockScreenClockView(context: context)
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    AccessibleActivityView(state: context.state) {
                        StatusLabel(state: context.state, scheme: .dark)
                    }
                }
                DynamicIslandExpandedRegion(.trailing) {
                    AccessibleActivityView(state: context.state) {
                        ShiftTimer(state: context.state, font: .title.weight(.semibold))
                            .foregroundStyle(LiveActivityTokens.color(.text, scheme: .dark))
                    }
                }
                DynamicIslandExpandedRegion(.bottom) {
                    AccessibleActivityView(state: context.state) {
                        Text(context.attributes.businessName)
                            .font(.footnote)
                            .foregroundStyle(LiveActivityTokens.color(.meta, scheme: .dark))
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                }
            } compactLeading: {
                AccessibleActivityView(state: context.state) {
                    StatusDot(state: context.state, scheme: .dark)
                }
            } compactTrailing: {
                AccessibleActivityView(state: context.state) {
                    ShiftTimer(state: context.state, font: .footnote.weight(.semibold))
                        .foregroundStyle(LiveActivityTokens.color(.text, scheme: .dark))
                        .frame(maxWidth: 52)
                        .minimumScaleFactor(0.7)
                }
            } minimal: {
                AccessibleActivityView(state: context.state) {
                    StatusDot(state: context.state, scheme: .dark)
                }
            }
            .keylineTint(LiveActivityTokens.color(.brand, scheme: .dark))
        }
    }
}

private struct LockScreenClockView: View {
    @Environment(\.colorScheme) private var colorScheme
    let context: ActivityViewContext<ClockActivityAttributes>

    var body: some View {
        ViewThatFits {
            HStack(spacing: 12) {
                identity
                Spacer(minLength: 8)
                timer
            }
            VStack(alignment: .leading, spacing: 10) {
                identity
                timer
            }
        }
        .padding()
        .activityBackgroundTint(LiveActivityTokens.color(.surface, scheme: colorScheme))
        .activitySystemActionForegroundColor(LiveActivityTokens.color(.brand, scheme: colorScheme))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(clockAccessibilityLabel(for: context.state))
        .accessibilityAddTraits(.updatesFrequently)
    }

    private var identity: some View {
        HStack(spacing: 10) {
            BrandMark(scheme: colorScheme)
            VStack(alignment: .leading, spacing: 2) {
                StatusLabel(state: context.state, scheme: colorScheme)
                Text(context.attributes.businessName)
                    .font(.footnote)
                    .foregroundStyle(LiveActivityTokens.color(.meta, scheme: colorScheme))
            }
        }
    }

    private var timer: some View {
        ShiftTimer(state: context.state, font: .title.weight(.semibold))
            .foregroundStyle(LiveActivityTokens.color(.text, scheme: colorScheme))
    }
}

private struct BrandMark: View {
    let scheme: ColorScheme

    var body: some View {
        RoundedRectangle(cornerRadius: 5)
            .fill(LiveActivityTokens.color(.brand, scheme: scheme))
            .frame(width: 22, height: 22)
            .overlay {
                Image(systemName: "timer")
                    .font(.system(size: 12, weight: .semibold))
                    .foregroundStyle(LiveActivityTokens.color(.surface, scheme: .light))
            }
            .accessibilityHidden(true)
    }
}

private struct StatusLabel: View {
    let state: ClockActivityAttributes.ContentState
    let scheme: ColorScheme

    var body: some View {
        HStack(spacing: 7) {
            StatusDot(state: state, scheme: scheme)
            Text(state.isOnBreak ? "On break" : "Clocked in")
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(LiveActivityTokens.color(.text, scheme: scheme))
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(state.isOnBreak ? "On break" : "Clocked in")
    }
}

private struct StatusDot: View {
    let state: ClockActivityAttributes.ContentState
    let scheme: ColorScheme

    var body: some View {
        Circle()
            .fill(LiveActivityTokens.color(state.isOnBreak ? .stateIdle : .stateWorking, scheme: scheme))
            .frame(width: 10, height: 10)
            .accessibilityHidden(true)
    }
}

private struct ShiftTimer: View {
    let state: ClockActivityAttributes.ContentState
    let font: Font

    var body: some View {
        Group {
            if let breakStart = state.breakStart {
                Text(
                    timerInterval: state.shiftStart...breakStart,
                    pauseTime: breakStart,
                    countsDown: false,
                    showsHours: true
                )
            } else {
                Text(
                    timerInterval: state.shiftStart...Date.distantFuture,
                    pauseTime: nil,
                    countsDown: false,
                    showsHours: true
                )
            }
        }
        .font(font)
        .monospacedDigit()
        .lineLimit(1)
        .minimumScaleFactor(0.7)
    }
}

private struct AccessibleActivityView<Content: View>: View {
    let state: ClockActivityAttributes.ContentState
    let content: Content

    init(
        state: ClockActivityAttributes.ContentState,
        @ViewBuilder content: () -> Content
    ) {
        self.state = state
        self.content = content()
    }

    var body: some View {
        content
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(clockAccessibilityLabel(for: state))
            .accessibilityAddTraits(.updatesFrequently)
    }
}

private func clockAccessibilityLabel(for state: ClockActivityAttributes.ContentState) -> String {
    if let breakStart = state.breakStart {
        let duration = ClockActivityAttributes.ContentState.accessibilityDuration(
            from: state.shiftStart,
            now: breakStart
        )
        return "On break, shift time \(duration)"
    }

    let duration = ClockActivityAttributes.ContentState.accessibilityDuration(
        from: state.shiftStart,
        now: Date()
    )
    return "Clocked in, \(duration)"
}
