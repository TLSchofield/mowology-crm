//
//  JobActivityWidget.swift
//  MowologyWidgets
//
//  Lock Screen Live Activity + Dynamic Island for an active Mowology shift.
//
//  The timer counts up automatically using Text(timerInterval:) — no app
//  updates needed just for the clock display. Activity.update() is only
//  called when job title / address / isOnJob state changes.
//

import ActivityKit
import SwiftUI
import WidgetKit

// MARK: - MW Brand colour (widget extension can't import MWTheme)

private extension Color {
    static let mwGreen  = Color(red: 0.176, green: 0.525, blue: 0.349) // #2D8659
    static let mwForest = Color(red: 0.051, green: 0.231, blue: 0.180) // #0D3B2E
}

// MARK: - JobActivityWidget

struct JobActivityWidget: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: MowJobActivity.self) { context in
            LockScreenLiveActivityView(context: context)
                .activityBackgroundTint(.mwForest)
                .activitySystemActionForegroundColor(.white)
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    HStack(spacing: 6) {
                        Image(systemName: "lawnmower.fill")
                            .foregroundStyle(.mwGreen)
                            .font(.title3)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(context.state.isOnJob ? (context.state.jobTitle ?? "Active Job") : "Shift Active")
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(.white)
                            if let address = context.state.address {
                                Text(address)
                                    .font(.caption2)
                                    .foregroundStyle(.white.opacity(0.7))
                                    .lineLimit(1)
                            }
                        }
                    }
                    .padding(.leading, 4)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    VStack(alignment: .trailing, spacing: 2) {
                        Text("Elapsed")
                            .font(.caption2)
                            .foregroundStyle(.white.opacity(0.6))
                        Text(
                            timerInterval: context.attributes.clockInDate...Date.distantFuture,
                            countsDown: false
                        )
                        .font(.system(.body, design: .monospaced).weight(.semibold))
                        .foregroundStyle(.mwGreen)
                        .monospacedDigit()
                    }
                    .padding(.trailing, 4)
                }
                DynamicIslandExpandedRegion(.bottom) {
                    HStack {
                        Label(context.attributes.crewName, systemImage: "person.fill")
                            .font(.caption2)
                            .foregroundStyle(.white.opacity(0.7))
                        Spacer()
                        if context.state.isOnJob {
                            Label("On Job", systemImage: "checkmark.circle.fill")
                                .font(.caption2.weight(.semibold))
                                .foregroundStyle(.mwGreen)
                        }
                    }
                    .padding(.horizontal, 4)
                    .padding(.bottom, 4)
                }
            } compactLeading: {
                Image(systemName: "lawnmower.fill")
                    .foregroundStyle(.mwGreen)
                    .font(.caption)
            } compactTrailing: {
                Text(
                    timerInterval: context.attributes.clockInDate...Date.distantFuture,
                    countsDown: false
                )
                .font(.caption.monospacedDigit())
                .foregroundStyle(.mwGreen)
                .frame(maxWidth: 52)
            } minimal: {
                Image(systemName: context.state.isOnJob ? "lawnmower.fill" : "clock.fill")
                    .foregroundStyle(.mwGreen)
                    .font(.caption2)
            }
        }
    }
}

// MARK: - Lock Screen view

private struct LockScreenLiveActivityView: View {
    let context: ActivityViewContext<MowJobActivity>

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: "lawnmower.fill")
                .font(.title2)
                .foregroundStyle(.mwGreen)

            VStack(alignment: .leading, spacing: 3) {
                Text(context.state.isOnJob
                     ? (context.state.jobTitle ?? "Active Job")
                     : "Shift Active — \(context.attributes.crewName)")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.white)
                    .lineLimit(1)

                if let address = context.state.address {
                    Text(address)
                        .font(.caption)
                        .foregroundStyle(.white.opacity(0.7))
                        .lineLimit(1)
                }
            }

            Spacer()

            VStack(alignment: .trailing, spacing: 3) {
                Text(
                    timerInterval: context.attributes.clockInDate...Date.distantFuture,
                    countsDown: false
                )
                .font(.system(.title3, design: .monospaced).weight(.bold))
                .foregroundStyle(.mwGreen)
                .monospacedDigit()

                Text("elapsed")
                    .font(.caption2)
                    .foregroundStyle(.white.opacity(0.5))
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
    }
}
