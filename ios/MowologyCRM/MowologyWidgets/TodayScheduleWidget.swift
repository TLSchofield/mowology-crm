//
//  TodayScheduleWidget.swift
//  MowologyWidgets
//
//  Medium Home Screen widget showing today's stop count and the next job address.
//
//  Data source: UserDefaults app group (group.ca.mowology.crm).
//  Written by the main app on schedule refresh and BGAppRefreshTask.
//

import SwiftUI
import WidgetKit

// MARK: - MW Brand colour

private extension Color {
    static let mwGreen  = Color(red: 0.176, green: 0.525, blue: 0.349)
    static let mwForest = Color(red: 0.051, green: 0.231, blue: 0.180)
}

// MARK: - Entry

struct TodayScheduleEntry: TimelineEntry {
    let date: Date
    let stopCount: Int
    let nextAddress: String
    let nextTime: String
}

// MARK: - Provider

struct TodayScheduleProvider: TimelineProvider {

    private var sharedDefaults: UserDefaults {
        UserDefaults(suiteName: "group.ca.mowology.crm") ?? .standard
    }

    func placeholder(in context: Context) -> TodayScheduleEntry {
        TodayScheduleEntry(date: .now, stopCount: 5, nextAddress: "123 Maple Ave", nextTime: "9:00 AM")
    }

    func getSnapshot(in context: Context,
                     completion: @escaping (TodayScheduleEntry) -> Void) {
        completion(currentEntry())
    }

    func getTimeline(in context: Context,
                     completion: @escaping (Timeline<TodayScheduleEntry>) -> Void) {
        let entry = currentEntry()
        let nextUpdate = Calendar.current.date(byAdding: .minute, value: 15, to: .now) ?? .now
        completion(Timeline(entries: [entry], policy: .after(nextUpdate)))
    }

    private func currentEntry() -> TodayScheduleEntry {
        let defaults    = sharedDefaults
        let stopCount   = defaults.integer(forKey: "mw.widget.stopCount")
        let nextAddress = defaults.string(forKey: "mw.widget.nextAddress") ?? "No stops today"
        let nextTime    = defaults.string(forKey: "mw.widget.nextTime") ?? ""
        return TodayScheduleEntry(date: .now, stopCount: stopCount,
                                  nextAddress: nextAddress, nextTime: nextTime)
    }
}

// MARK: - View

struct TodayScheduleWidgetView: View {
    let entry: TodayScheduleEntry

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Image(systemName: "calendar")
                    .foregroundStyle(.mwGreen)
                Text("Today")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(.secondary)
                Spacer()
                Text("\(entry.stopCount) stops")
                    .font(.caption.weight(.semibold))
                    .padding(.horizontal, 6).padding(.vertical, 2)
                    .background(.mwGreen.opacity(0.15))
                    .foregroundStyle(.mwGreen)
                    .clipShape(Capsule())
            }

            Divider()

            VStack(alignment: .leading, spacing: 4) {
                Label("Next Stop", systemImage: "mappin.circle.fill")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                Text(entry.nextAddress)
                    .font(.subheadline.weight(.medium))
                    .lineLimit(2)
                if !entry.nextTime.isEmpty {
                    Text(entry.nextTime)
                        .font(.caption)
                        .foregroundStyle(.mwGreen)
                }
            }
        }
        .padding(12)
        .containerBackground(.fill.tertiary, for: .widget)
    }
}

// MARK: - Widget

struct TodayScheduleWidget: Widget {
    let kind = "TodayScheduleWidget"

    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: TodayScheduleProvider()) { entry in
            TodayScheduleWidgetView(entry: entry)
        }
        .configurationDisplayName("Today's Schedule")
        .description("Shows your stop count and next job address for today.")
        .supportedFamilies([.systemMedium])
    }
}
