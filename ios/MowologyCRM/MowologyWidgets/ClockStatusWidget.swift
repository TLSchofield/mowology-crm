//
//  ClockStatusWidget.swift
//  MowologyWidgets
//
//  Home Screen (small) + Lock Screen (accessory) widget showing current
//  clock-in / clock-out status and elapsed shift time.
//
//  Data source: UserDefaults app group (group.ca.mowology.crm).
//  Written by the main app on clock events; read here by the timeline provider.
//

import SwiftUI
import WidgetKit

// MARK: - MW Brand colour

private extension Color {
    static let mwGreen  = Color(red: 0.176, green: 0.525, blue: 0.349)
    static let mwForest = Color(red: 0.051, green: 0.231, blue: 0.180)
}

// MARK: - Shared keys

private enum WidgetKey {
    static let clockedIn     = "mw.widget.clockedIn"
    static let clockInEpoch  = "mw.widget.clockInEpoch"
    static let crewName      = "mw.widget.crewName"
}

// MARK: - Entry

struct ClockStatusEntry: TimelineEntry {
    let date: Date
    let clockedIn: Bool
    let clockInDate: Date?
    let crewName: String
}

// MARK: - Provider

struct ClockStatusProvider: TimelineProvider {

    private var sharedDefaults: UserDefaults {
        UserDefaults(suiteName: "group.ca.mowology.crm") ?? .standard
    }

    func placeholder(in context: Context) -> ClockStatusEntry {
        ClockStatusEntry(date: .now, clockedIn: true,
                         clockInDate: Date().addingTimeInterval(-7200), crewName: "Crew")
    }

    func getSnapshot(in context: Context,
                     completion: @escaping (ClockStatusEntry) -> Void) {
        completion(currentEntry())
    }

    func getTimeline(in context: Context,
                     completion: @escaping (Timeline<ClockStatusEntry>) -> Void) {
        let entry = currentEntry()
        // Refresh every 15 minutes (more frequent updates via app-driven reloadTimelines)
        let nextUpdate = Calendar.current.date(byAdding: .minute, value: 15, to: .now) ?? .now
        completion(Timeline(entries: [entry], policy: .after(nextUpdate)))
    }

    private func currentEntry() -> ClockStatusEntry {
        let defaults    = sharedDefaults
        let clockedIn   = defaults.bool(forKey: WidgetKey.clockedIn)
        let epoch       = defaults.double(forKey: WidgetKey.clockInEpoch)
        let clockInDate = epoch > 0 ? Date(timeIntervalSince1970: epoch) : nil
        let crewName    = defaults.string(forKey: WidgetKey.crewName) ?? "Mowology"
        return ClockStatusEntry(date: .now, clockedIn: clockedIn,
                                clockInDate: clockInDate, crewName: crewName)
    }
}

// MARK: - Views

struct ClockStatusWidgetView: View {
    let entry: ClockStatusEntry
    @Environment(\.widgetFamily) var family

    var body: some View {
        switch family {
        case .accessoryCircular:
            accessoryCircularView
        case .accessoryRectangular:
            accessoryRectangularView
        default:
            smallView
        }
    }

    private var accessoryCircularView: some View {
        ZStack {
            if entry.clockedIn {
                Image(systemName: "clock.fill")
                    .font(.title2)
                    .foregroundStyle(.mwGreen)
            } else {
                Image(systemName: "clock")
                    .font(.title2)
                    .foregroundStyle(.secondary)
            }
        }
    }

    private var accessoryRectangularView: some View {
        HStack(spacing: 6) {
            Image(systemName: entry.clockedIn ? "clock.fill" : "clock")
                .foregroundStyle(entry.clockedIn ? .mwGreen : .secondary)
            VStack(alignment: .leading) {
                Text(entry.clockedIn ? "Clocked In" : "Clocked Out")
                    .font(.caption.weight(.semibold))
                if entry.clockedIn, let start = entry.clockInDate {
                    Text(timerInterval: start...Date.distantFuture, countsDown: false)
                        .font(.caption2.monospacedDigit())
                        .foregroundStyle(.mwGreen)
                }
            }
        }
    }

    private var smallView: some View {
        VStack(spacing: 8) {
            Image(systemName: entry.clockedIn ? "checkmark.circle.fill" : "xmark.circle")
                .font(.system(size: 28))
                .foregroundStyle(entry.clockedIn ? .mwGreen : Color(.systemGray3))

            Text(entry.clockedIn ? "Clocked In" : "Clocked Out")
                .font(.caption.weight(.semibold))
                .foregroundStyle(entry.clockedIn ? .primary : .secondary)

            if entry.clockedIn, let start = entry.clockInDate {
                Text(timerInterval: start...Date.distantFuture, countsDown: false)
                    .font(.system(.body, design: .monospaced).weight(.bold))
                    .foregroundStyle(.mwGreen)
                    .monospacedDigit()
            }
        }
        .containerBackground(.fill.tertiary, for: .widget)
    }
}

// MARK: - Widget

struct ClockStatusWidget: Widget {
    let kind = "ClockStatusWidget"

    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: ClockStatusProvider()) { entry in
            ClockStatusWidgetView(entry: entry)
        }
        .configurationDisplayName("Clock Status")
        .description("Shows your current clock-in status and elapsed shift time.")
        .supportedFamilies([.systemSmall, .accessoryCircular, .accessoryRectangular])
    }
}
