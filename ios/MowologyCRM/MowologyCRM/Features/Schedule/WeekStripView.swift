//
//  WeekStripView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct WeekStripView: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    @Binding var selectedDate: Date
    let weekDays: [ScheduleDay]
    @Namespace private var chipNamespace

    var body: some View {
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                ScrollView(.horizontal, showsIndicators: false) { dayChips }
            } else {
                dayChips
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.horizontal, appStyle.isEnabled ? 8 : 12)
        .padding(.vertical, 12)
        .background(appStyle.surface)
    }

    private var dayChips: some View {
        HStack(spacing: appStyle.isEnabled ? 2 : 4) {
            ForEach(weekDays) { day in
                DayChip(day: day, isSelected: isSelected(day), ns: chipNamespace) {
                    selectDay(day)
                }
            }
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - Helpers

    private func isSelected(_ day: ScheduleDay) -> Bool {
        isoDateString(from: selectedDate) == day.date
    }

    private func selectDay(_ day: ScheduleDay) {
        guard let date = parseISODate(day.date) else { return }
        withAnimation(.spring(response: 0.3, dampingFraction: 0.75)) {
            selectedDate = date
        }
    }

    private func isoDateString(from date: Date) -> String {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_CA")
        return f.string(from: date)
    }

    private func parseISODate(_ string: String) -> Date? {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_CA")
        return f.date(from: string)
    }
}

// MARK: - DayCarouselView (phones, 1.3.101)

/// The days as one smooth strip, like a wheel (Tim, 2026-11-06: "the day carousel is jerky not smooth like a wheel").
/// The day sitting on the Schedule bead's axis IS the day shown: a swipe glides and snaps a day onto the line (a
/// selection tick per day, like a picker), a tap slides that day onto it. The timeline's line then runs straight from
/// that day, through the stops, into the Schedule bead (SPEC §13 — the line belongs to the active tab). No week walls.
struct DayCarouselView: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.itTabSlot) private var tabSlot

    @Binding var selectedDate: Date
    /// The loaded week's summaries; their dots are kept as weeks load, so days already seen keep theirs.
    let weekDays: [ScheduleDay]
    /// Draw the stub of line from the chosen day down to the list (the timeline is on).
    let showsLine: Bool

    static let chipWidth: CGFloat = 52
    /// One exact height for the strip (Tim's iPhone, 1.3.105: the scroll area measured taller than its chips and left a
    /// blank band, so the line never met the day). Text in the strip is capped at extra-large so a chip never outgrows it.
    static let chipHeight: CGFloat = 80
    static let spacing: CGFloat = 6
    private static let span = -60...120           // days around today the strip reaches
    private static let offsets = Array(span)

    @State private var centred: Int?
    @State private var known: [String: ScheduleDay] = [:]
    /// The 181 chips, worked out once and again only when a week's dots arrive (Tim, 2026-11-07: "the calendar" is not
    /// smooth). Before, every day that reached the centre during a swipe rebuilt all 181, each formatting its date.
    @State private var chips: [ScheduleDay] = []
    @State private var commit: Task<Void, Never>?
    private let tick = UISelectionFeedbackGenerator()
    private let base = Calendar.current.startOfDay(for: .now)

    @State private var width: CGFloat = 0

    var body: some View {
        // Natural height (Tim's iPhone, 1.3.104: at his larger text size the chips outgrew a fixed 96 pt strip and spilled
        // up over the Mine / All crews bar). The width is read in the background for the axis.
        let axis = tabSlot?.axis(width: width) ?? width / 10
        let lead = max(0, axis - Self.chipWidth / 2)
        VStack(spacing: 0) {
            ScrollView(.horizontal, showsIndicators: false) {
                    // Not lazy: on Tim's iPhone (1.3.101) the strip jumped to the chosen day before a lazy stack had made
                    // that chip, and showed blank space. 181 small chips are cheap.
                    HStack(spacing: Self.spacing) {
                        ForEach(Self.offsets, id: \.self) { offset in
                            let day = chip(offset)
                            // Equatable: a swipe redraws only the chip leaving the centre and the one arriving.
                            DayChip(day: day, isSelected: offset == centred, ns: ns) {
                                withAnimation(.snappy(duration: 0.35)) { centred = offset }
                            }
                            .equatable()
                            .frame(width: Self.chipWidth, height: Self.chipHeight)
                            .id(offset)
                        }
                    }
                    .scrollTargetLayout()
                }
                // MainTabView gives every scroll view the tab bar's bottom margin; inherited here it made the strip
                // scroll vertically and centring a day pushed the chips up over the Mine bar (Tim's iPhone, build 416).
                .contentMargins(.vertical, 0, for: .scrollContent)
                .contentMargins(.leading, lead, for: .scrollContent)
                .contentMargins(.trailing, max(0, width - lead - Self.chipWidth), for: .scrollContent)
                .scrollBounceBehavior(.basedOnSize, axes: .vertical)
                .scrollTargetBehavior(.viewAligned)
                // Positioned only through its own scroll position: a ScrollViewReader.scrollTo also moved the scroll
                // containers around it and slid the whole schedule sideways (build 416).
                .scrollPosition(id: $centred, anchor: .topLeading)
                .frame(height: Self.chipHeight)
                .padding(.top, 10)
                // Once the width is known the margins change: put the chosen day back on the line.
                .onChange(of: width) { _, _ in
                    var t = Transaction(); t.disablesAnimations = true
                    withTransaction(t) { centred = nil }
                    DispatchQueue.main.async { centred = offset(of: selectedDate) }
                }

            // The line leaves the chosen day and runs down to the first stop.
            Rectangle()
                .fill(showsLine ? appStyle.line : Color.clear)
                .frame(width: 1.5, height: 10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .offset(x: axis - 0.75)
                .accessibilityHidden(true)
        }
        .dynamicTypeSize(...DynamicTypeSize.xLarge)
        .background(GeometryReader { g in
            Color.clear
                .onAppear { width = g.size.width }
                .onChange(of: g.size.width) { _, w in width = w }
        })
        .background(appStyle.page)
        .onAppear {
            remember(weekDays)
            centred = offset(of: selectedDate)
            tick.prepare()
        }
        .onChange(of: weekDays.map(\.id)) { _, _ in remember(weekDays) }
        // Today / another screen changed the day: glide to it.
        .onChange(of: selectedDate) { _, d in
            let o = offset(of: d)
            if o != centred { withAnimation(.snappy(duration: 0.35)) { centred = o } }
        }
        // A day reached the line: tick now, load once the strip settles (no request per day flown past).
        .onChange(of: centred) { _, o in
            guard let o else { return }
            tick.selectionChanged()
            commit?.cancel()
            commit = Task { @MainActor in
                try? await Task.sleep(nanoseconds: 280_000_000)
                guard !Task.isCancelled, o == centred else { return }
                let date = Calendar.current.date(byAdding: .day, value: o, to: base) ?? base
                if offset(of: selectedDate) != o { selectedDate = date }
            }
        }
    }

    @Namespace private var ns

    private func offset(of date: Date) -> Int {
        let d = Calendar.current.dateComponents([.day], from: base, to: Calendar.current.startOfDay(for: date)).day ?? 0
        return min(max(d, Self.span.lowerBound), Self.span.upperBound)
    }

    private func remember(_ days: [ScheduleDay]) {
        var changed = chips.isEmpty
        for d in days where known[d.date].map({ !DayChip.same($0, d) }) ?? true {
            known[d.date] = d
            changed = true
        }
        if changed { chips = Self.offsets.map { summary($0) } }
    }

    private func chip(_ offset: Int) -> ScheduleDay {
        let i = offset - Self.span.lowerBound
        return chips.indices.contains(i) ? chips[i] : summary(offset)
    }

    private static let iso: DateFormatter = {
        let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; f.locale = Locale(identifier: "en_CA"); return f
    }()
    private static let weekday: DateFormatter = {
        let f = DateFormatter(); f.dateFormat = "EEE"; f.locale = Locale(identifier: "en_CA"); return f
    }()

    /// The server's summary for that day when its week has loaded, else the plain day (no dot yet).
    private func summary(_ offset: Int) -> ScheduleDay {
        let date = Calendar.current.date(byAdding: .day, value: offset, to: base) ?? base
        let key = Self.iso.string(from: date)
        if let k = known[key] { return k }
        return ScheduleDay(date: key, dayName: Self.weekday.string(from: date),
                           dayNumber: Calendar.current.component(.day, from: date), isToday: offset == 0,
                           stopCount: 0, visitCount: 0, hasIncomplete: false)
    }
}

// MARK: - DayChip

private struct DayChip: View, Equatable {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    let day: ScheduleDay
    let isSelected: Bool
    let ns: Namespace.ID
    let action: () -> Void

    /// What the chip draws; the action only carries its own offset, so it never makes two chips differ.
    static func == (a: DayChip, b: DayChip) -> Bool { a.isSelected == b.isSelected && same(a.day, b.day) }

    static func same(_ a: ScheduleDay, _ b: ScheduleDay) -> Bool {
        a.date == b.date && a.dayName == b.dayName && a.dayNumber == b.dayNumber && a.isToday == b.isToday
            && a.stopCount == b.stopCount && a.visitCount == b.visitCount && a.hasIncomplete == b.hasIncomplete
    }

    var body: some View {
        Button(action: action) {
            VStack(spacing: 4) {

                // Day name (e.g. "Mon")
                Text(day.dayName.prefix(3).uppercased())
                    .font(appStyle.font(size: appStyle.isEnabled ? 12 : 10, weight: .semibold))
                    .foregroundStyle(isSelected ? appStyle.onTenant : appStyle.meta)

                // Day number (e.g. "3")
                Text("\(day.dayNumber)")
                    .font(appStyle.font(size: 20, weight: .bold, design: .rounded))
                    .foregroundStyle(isSelected ? appStyle.onTenant : (day.isToday ? appStyle.tenant : appStyle.text))

                // Activity dot
                Circle()
                    .fill(
                        day.stopCount > 0
                            ? ((isSelected && appStyle.isEnabled) ? appStyle.onTenant : appStyle.meta)
                            : Color.clear
                    )
                    .frame(width: 6, height: 6)
            }
            .frame(minWidth: dynamicTypeSize.isAccessibilitySize ? 72 : nil, maxWidth: .infinity, minHeight: 72)
            .background {
                ZStack {
                    // Base layer — fades out when selected
                    RoundedRectangle(cornerRadius: appStyle.cornerRadius(14))
                        .fill(day.isToday ? appStyle.tenant.opacity(0.08) : appStyle.sunk)
                        .opacity(isSelected ? 0 : 1)

                    // Selected pill — matchedGeometryEffect makes it slide
                    // between chips rather than cross-fade in place.
                    if isSelected {
                        RoundedRectangle(cornerRadius: appStyle.cornerRadius(14))
                            .fill(appStyle.tenant)
                            .matchedGeometryEffect(id: "chip", in: ns)
                    }
                }
            }
            .overlay {
                RoundedRectangle(cornerRadius: appStyle.cornerRadius(14))
                    .stroke(
                        day.isToday && !isSelected ? appStyle.tenant.opacity(0.4) : Color.clear,
                        lineWidth: 1.5
                    )
            }
        }
        .buttonStyle(.plain)
        .accessibilityLabel("\(day.dayName), \(day.dayNumber), \(day.stopCount) stops")
        .accessibilityValue(isSelected ? "Selected" : (day.hasIncomplete ? "Work remaining" : ""))
    }
}

#Preview {
    WeekStripView(
        selectedDate: .constant(.now),
        weekDays: [
            ScheduleDay(date: "2026-03-02", dayName: "Sun", dayNumber: 2,  isToday: false, stopCount: 0, visitCount: 0, hasIncomplete: false),
            ScheduleDay(date: "2026-03-03", dayName: "Mon", dayNumber: 3,  isToday: true,  stopCount: 3, visitCount: 5, hasIncomplete: true),
            ScheduleDay(date: "2026-03-04", dayName: "Tue", dayNumber: 4,  isToday: false, stopCount: 2, visitCount: 3, hasIncomplete: false),
            ScheduleDay(date: "2026-03-05", dayName: "Wed", dayNumber: 5,  isToday: false, stopCount: 0, visitCount: 0, hasIncomplete: false),
            ScheduleDay(date: "2026-03-06", dayName: "Thu", dayNumber: 6,  isToday: false, stopCount: 4, visitCount: 6, hasIncomplete: true),
            ScheduleDay(date: "2026-03-07", dayName: "Fri", dayNumber: 7,  isToday: false, stopCount: 1, visitCount: 1, hasIncomplete: false),
            ScheduleDay(date: "2026-03-08", dayName: "Sat", dayNumber: 8,  isToday: false, stopCount: 0, visitCount: 0, hasIncomplete: false)
        ]
    )
    .previewLayout(.sizeThatFits)
}
