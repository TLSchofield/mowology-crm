//
//  DayCarouselView.swift
//  MowologyCRM
//
//  The phone's day carousel (1.3.101), rebuilt 2026-11-08 so nothing changes while it moves.
//

import SwiftUI
import QuartzCore

/// The days as one smooth strip, like a wheel (Tim, 2026-11-06: "the day carousel is jerky not smooth like a wheel").
/// The day sitting on the Schedule bead's axis IS the day shown: a swipe glides and snaps a day onto the line (a
/// selection tick per day, like a picker), a tap slides that day onto it. The timeline's line then runs straight from
/// that day, through the stops, into the Schedule bead (SPEC §13 — the line belongs to the active tab). No week walls.
///
/// The pill never moves: it is drawn once on the line and the days pass under it, each showing its on-pill colours
/// only where it is under the pill. The day is handed on (`onLand`) only once the strip has come to rest on it, so
/// the list below never loads or redraws mid-glide. Arithmetic: `DayCarouselRules`.
struct DayCarouselView: View, Equatable {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.itTabSlot) private var tabSlot
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    /// The day the screen shows. Changed from outside (Today, the date picker, a notification): the strip glides to it.
    let selectedDate: Date
    /// The loaded week's summaries; their dots are kept as weeks load, so days already seen keep theirs.
    let weekDays: [ScheduleDay]
    /// Whose jobs and which job types the dots count. When it changes, dots kept for other weeks are forgotten.
    let dotsKey: String
    /// Draw the stub of line from the chosen day down to the list (the timeline is on).
    let showsLine: Bool
    /// The strip came to rest on another day.
    let onLand: (Date) -> Void
    /// A day reached the line while the strip moves (the month in the top bar follows it).
    var onLine: ((Date) -> Void)? = nil

    /// What the strip draws; the closures never make two strips differ, so the Schedule screen's other updates
    /// (location, loading, crew positions) leave it alone.
    static func == (a: DayCarouselView, b: DayCarouselView) -> Bool {
        a.selectedDate == b.selectedDate && a.weekDays == b.weekDays && a.dotsKey == b.dotsKey && a.showsLine == b.showsLine
    }

    private typealias Rules = DayCarouselRules
    static let chipWidth = DayCarouselRules.chipWidth
    /// One exact height for the strip (Tim's iPhone, 1.3.105: the scroll area measured taller than its chips and left a
    /// blank band, so the line never met the day). Text in the strip is capped at extra-large so a chip never outgrows it.
    static let chipHeight: CGFloat = 80
    fileprivate static let space = "dayCarousel"
    /// The glide to a day chosen from outside. `-mwCarouselSlow` (Debug) stretches it so a screenshot can catch it.
    private static let glide: Double = {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("-mwCarouselSlow") { return 6 }
        #endif
        return 0.35
    }()

    @State private var centred: Int?
    @State private var anchor = Calendar.current.startOfDay(for: .now)
    @State private var builtFor = Calendar.current.startOfDay(for: .now)
    @State private var known: [String: ScheduleDay] = [:]
    /// The 181 chips, worked out once and again only when a week's dots arrive, the window moves or the day rolls over.
    @State private var chips: [ScheduleDay] = []
    @State private var width: CGFloat = 0
    /// Where the strip is and when it last moved. Not view state on purpose: a scroll writes here every frame.
    @State private var rest = DayCarouselRest()
    @State private var landing = 0
    /// False from the moment the strip is put somewhere without a glide until it reports from there. A strip that
    /// has just been made sits on its first day for an instant; that is not a landing (it loaded 60 days ago at launch).
    @State private var placed = false
    private let tick = UISelectionFeedbackGenerator()

    var body: some View {
        let axis = tabSlot?.axis(width: width) ?? width / 10
        let lead = max(0, axis - Self.chipWidth / 2)
        VStack(spacing: 0) {
            ZStack(alignment: .topLeading) {
                // The pill: fixed on the line. The days scroll over it (Tim, 2026-11-07: "the calendar" is not smooth —
                // the pill used to hop from chip to chip as each day crossed the line).
                RoundedRectangle(cornerRadius: appStyle.cornerRadius(14))
                    .fill(appStyle.tenant)
                    .frame(width: Self.chipWidth, height: Self.chipHeight)
                    .offset(x: lead)
                    .accessibilityHidden(true)
                // Made once the width is known, so it opens with the chosen day already on the line.
                if width > 0, !chips.isEmpty { strip(lead: lead) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .frame(height: Self.chipHeight)
            .padding(.top, 10)

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
            let a = Rules.anchor(for: selectedDate, today: .now, current: nil, calendar: .current)
            anchor = a
            for d in weekDays { known[d.date] = d }
            rebuild(around: a, known: Dictionary(weekDays.map { ($0.date, $0) }, uniquingKeysWith: { $1 }))
            centred = Rules.index(of: selectedDate, anchor: a, calendar: .current)
            tick.prepare()
            rest.onLand = { i in
                rest.landed = i
                landing += 1
            }
        }
        .onChange(of: weekDays) { _, days in
            var all = known
            for d in days { all[d.date] = d }
            guard all != known else { return }
            known = all
            rebuild(around: anchor, known: all)
        }
        .onChange(of: dotsKey) { _, _ in
            // Dots kept from other weeks counted the old filter's jobs. The week on screen is about to be replaced.
            let onScreen = Set(weekDays.map(\.date))
            let kept = known.filter { onScreen.contains($0.key) }
            known = kept
            rebuild(around: anchor, known: kept)
        }
        // Today / the date picker / a notification changed the day: glide to it, or move the window when it is far away.
        .onChange(of: selectedDate) { _, d in show(d, animated: true) }
        // Left open overnight: "today" moves on a chip.
        .onReceive(NotificationCenter.default.publisher(for: .NSCalendarDayChanged).receive(on: DispatchQueue.main)) { _ in
            dayRolledOver()
        }
        .onChange(of: scenePhase) { _, phase in if phase == .active { dayRolledOver() } }
        // A rotation, split view or a different tab bar moved the line: put the chosen day back on it.
        .onChange(of: lead) { old, _ in
            guard old > 0 else { return }
            place(index(of: selectedDate))
        }
        // A day reached the line: tick, like a picker. Nothing loads here.
        .onChange(of: centred) { old, i in
            guard let i else { return }
            if old != nil { tick.selectionChanged() }
            onLine?(Rules.date(at: i, anchor: anchor, calendar: .current))
        }
        // The strip came to rest on a day: now it is the day shown.
        .onChange(of: landing) { _, _ in
            guard let i = rest.landed else { return }
            if !placed {
                placed = true
                if i != centred { return }   // the report from before the strip was put on its day
            }
            let date = Rules.date(at: i, anchor: anchor, calendar: .current)
            if !Calendar.current.isDate(date, inSameDayAs: selectedDate) { onLand(date) }
        }
    }

    private func strip(lead: CGFloat) -> some View {
        // Every day between where the strip last rested and the day now on the line can pass under the pill, so
        // each of them carries its on-pill colours (two either side to spare); the rest of the 181 do not.
        let now = centred ?? rest.landed ?? 0
        let from = rest.landed ?? now
        let reach = (min(now, from) - 2)...(max(now, from) + 2)
        return ScrollView(.horizontal, showsIndicators: false) {
            // Not lazy: on Tim's iPhone (1.3.101) the strip jumped to the chosen day before a lazy stack had made
            // that chip, and showed blank space. 181 small chips are cheap.
            HStack(spacing: Rules.spacing) {
                ForEach(0..<Rules.count, id: \.self) { i in
                    // Equatable: a day crossing the line redraws only the chip entering the pill's reach.
                    DayCarouselChip(day: chips.indices.contains(i) ? chips[i] : chips[0],
                                    underPill: reach.contains(i),
                                    isSelected: i == centred,
                                    pillMidX: lead + Self.chipWidth / 2) {
                        if reduceMotion { centred = i } else { withAnimation(.snappy(duration: 0.35)) { centred = i } }
                    }
                    .equatable()
                    .frame(width: Self.chipWidth, height: Self.chipHeight)
                    .id(i)
                }
            }
            .scrollTargetLayout()
            // How far the strip has travelled, read every frame into `rest` (no view state changes while it moves).
            .background(GeometryReader { g in
                let x = g.frame(in: .named(Self.space)).minX
                Color.clear
                    .onAppear { rest.moved(to: lead - x) }
                    .onChange(of: x) { _, x in rest.moved(to: lead - x) }
            })
        }
        .coordinateSpace(name: Self.space)
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
        // A new scroll view does not take up the position it is given; it has to be told again once it exists.
        .onAppear { place(index(of: selectedDate)) }
    }

    // MARK: - Helpers

    private func index(of date: Date) -> Int { Rules.index(of: date, anchor: anchor, calendar: .current) }

    /// Puts a day on the line: a glide inside the window, a silent move of the window when the day is outside it.
    private func show(_ date: Date, animated: Bool) {
        let wanted = Rules.anchor(for: date, today: .now, current: anchor, calendar: .current)
        if wanted != anchor {
            move(to: wanted, showing: date)
            return
        }
        let i = index(of: date)
        guard i != centred else { return }
        if animated, !reduceMotion { withAnimation(.snappy(duration: Self.glide)) { centred = i } } else { centred = i }
    }

    private func dayRolledOver() {
        let today = Calendar.current.startOfDay(for: .now)
        guard today != builtFor else { return }
        move(to: Rules.anchor(for: selectedDate, today: today, current: anchor, calendar: .current), showing: selectedDate)
    }

    /// Rebuilds the strip around another anchor day with the given day on the line, without a glide.
    private func move(to wanted: Date, showing date: Date) {
        anchor = wanted
        rebuild(around: wanted, known: known)
        place(Rules.index(of: date, anchor: wanted, calendar: .current))
    }

    /// Puts a chip on the line without a glide. Cleared first, then set on the next turn of the run loop: setting
    /// the same value, or setting it before the scroll view has laid out, moves nothing.
    private func place(_ i: Int) {
        placed = false
        var t = Transaction(); t.disablesAnimations = true
        withTransaction(t) { centred = nil }
        DispatchQueue.main.async { withTransaction(t) { centred = i } }
    }

    private func rebuild(around anchor: Date, known: [String: ScheduleDay]) {
        let today = Calendar.current.startOfDay(for: .now)
        builtFor = today
        chips = Rules.chips(anchor: anchor, today: today, known: known, calendar: .current)
    }
}

// MARK: - Rest

/// Watches the strip's travel and reports the day it comes to rest on (`DayCarouselRules.landed`). iOS 17 has no
/// scroll-phase callback, so "landed" is: on a day, and not moved for a tenth of a second. Main thread only.
final class DayCarouselRest {
    var onLand: ((Int) -> Void)?
    /// The last day reported.
    var landed: Int?

    private var travel: CGFloat = 0
    private var lastMove: CFTimeInterval = 0
    private var watching = false

    func moved(to travel: CGFloat) {
        self.travel = travel
        lastMove = CACurrentMediaTime()
        guard !watching else { return }
        watching = true
        check(after: DayCarouselRules.restSeconds)
    }

    private func check(after seconds: Double) {
        DispatchQueue.main.asyncAfter(deadline: .now() + seconds) { [weak self] in
            guard let self else { return }
            let still = CACurrentMediaTime() - self.lastMove
            if still < DayCarouselRules.restSeconds {
                self.check(after: DayCarouselRules.restSeconds - still + 0.005)
                return
            }
            self.watching = false
            let day = DayCarouselRules.landed(travel: self.travel, stillFor: still)
            #if DEBUG
            if ProcessInfo.processInfo.arguments.contains("-mwCarouselProbe") {
                print("DayCarousel rest: travel \(self.travel) still \(still) landed \(day.map(String.init) ?? "no")")
            }
            #endif
            if let day { self.onLand?(day) }
        }
    }
}

// MARK: - Chip

private struct DayCarouselChip: View, Equatable {
    @Environment(\.itAppStyle) private var appStyle

    let day: ScheduleDay
    /// Near enough the line to need its on-pill colours.
    let underPill: Bool
    let isSelected: Bool
    /// The pill's centre across the strip.
    let pillMidX: CGFloat
    let action: () -> Void

    /// What the chip draws; the action only carries its own index, so it never makes two chips differ.
    static func == (a: DayCarouselChip, b: DayCarouselChip) -> Bool {
        a.day == b.day && a.underPill == b.underPill && a.isSelected == b.isSelected && a.pillMidX == b.pillMidX
    }

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: appStyle.cornerRadius(14))
        let pillMidX = pillMidX
        Button(action: action) {
            label(onPill: false)
                .background { shape.fill(day.isToday ? appStyle.tenant.opacity(0.08) : appStyle.sunk) }
                // Inside the chip's edge, so the pill covers today's outline completely when today is on the line.
                .overlay { shape.strokeBorder(day.isToday ? appStyle.tenant.opacity(0.4) : Color.clear, lineWidth: 1.5) }
                .overlay {
                    if underPill {
                        // The same day in its on-pill colours, seen only through a pill-shaped window that stays on
                        // the line while the chip moves: the colours change exactly at the pill's edge.
                        label(onPill: true)
                            .background(appStyle.tenant)
                            // There at once: inside a glide's animation it would fade in late, over the pill.
                            .transition(.identity)
                            .mask {
                                shape.visualEffect { content, proxy in
                                    content.offset(x: DayCarouselRules.lensShift(
                                        chipMidX: proxy.frame(in: .named(DayCarouselView.space)).midX, axis: pillMidX))
                                }
                            }
                    }
                }
        }
        .buttonStyle(.plain)
        // A day whose week has not loaded has no count yet, so only a real count is read out.
        .accessibilityLabel(day.stopCount > 0 ? "\(day.dayName), \(day.dayNumber), \(day.stopCount) stops" : "\(day.dayName), \(day.dayNumber)")
        .accessibilityValue(isSelected ? "Selected" : (day.hasIncomplete ? "Work remaining" : ""))
        .accessibilityAddTraits(isSelected ? .isSelected : [])
    }

    private func label(onPill: Bool) -> some View {
        VStack(spacing: 4) {
            // Day name (e.g. "MON")
            Text(day.dayName.prefix(3).uppercased())
                .font(appStyle.font(size: appStyle.isEnabled ? 12 : 10, weight: .semibold))
                .foregroundStyle(onPill ? appStyle.onTenant : appStyle.meta)

            // Day number (e.g. "3")
            Text("\(day.dayNumber)")
                .font(appStyle.font(size: 20, weight: .bold, design: .rounded))
                .foregroundStyle(onPill ? appStyle.onTenant : (day.isToday ? appStyle.tenant : appStyle.text))

            // Activity dot
            Circle()
                .fill(day.stopCount > 0 ? ((onPill && appStyle.isEnabled) ? appStyle.onTenant : appStyle.meta) : Color.clear)
                .frame(width: 6, height: 6)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}
