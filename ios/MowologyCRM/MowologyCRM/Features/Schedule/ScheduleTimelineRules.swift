//
//  ScheduleTimelineRules.swift
//  MowologyCRM
//
//  The crew schedule as a timeline (Tim, 2026-11-05, design: design-studio/sandbox/ittai-schedule-cards/chosen-a-timeline).
//  Pure rules only (Foundation + the Stop/Visit models), so ios/LogicTests checks them on the Mac:
//  which state each stop is in, its one-line time window, the running-timer text, the next stop,
//  and the "you left the site" memory. The views in ScheduleTimelineViews.swift only draw what this decides.
//

import Foundation

/// What a stop shows on the timeline. One state per stop; the order of the checks in
/// `ScheduleTimelineRules.state` is the order of precedence.
enum TimelineStopState: Equatable {
    /// Finished on this phone, not yet confirmed by the server (offline queue). Never shown as done.
    case savedNotSent
    /// Every visit completed and confirmed: one dimmed line, the check is in the marker.
    case done
    /// Skipped or cancelled: one dimmed line with the word.
    case closed(String)
    /// The signed-in person's own running timer is on this stop. Expanded focus card in "Mine".
    case onSite
    /// The server stopped my timer when the phone left the site; the visit is not finished.
    case leftSite
    /// Someone is working here (not my timer): one row with the in-progress word.
    case inProgress
    /// The signed-in person's next stop: note + Navigate (Navigate hidden while on site elsewhere).
    case next
    /// Not done last time ("Skipped Fri, last done 28 days ago").
    case attention(String)
    case upcoming

    var isCollapsed: Bool {
        switch self {
        case .savedNotSent, .done, .closed: return true
        default: return false
        }
    }
}

/// Everything the rules need besides the stops. Built by ScheduleViewModel.
struct TimelineContext: Equatable {
    var myUserId: Int?
    /// The "Mine" filter is on: every stop listed is this person's, and only here does a job expand to the focus card.
    var mineScope: Bool
    /// The visit the signed-in person's job timer is running on (server: /api/schedule/timer?mode=active).
    var activeTimerVisitId: Int?
    /// Visits whose finish ("stop") is still in the offline queue.
    var pendingFinishVisitIds: Set<Int> = []
    /// Visits the server auto-stopped when the phone left the site (today, not yet finished).
    var leftSiteVisitIds: Set<Int> = []
}

enum ScheduleTimelineRules {

    // MARK: Order

    /// Timeline order is the day's time order, not the distance order the card list uses.
    /// Stops without a time keep the server's route order, after the timed ones.
    static func ordered(_ stops: [Stop]) -> [Stop] {
        stops.enumerated().sorted { a, b in
            let ta = startMinutes(a.element), tb = startMinutes(b.element)
            switch (ta, tb) {
            case let (x?, y?) where x != y: return x < y
            case (.some, nil): return true
            case (nil, .some): return false
            default:
                if a.element.routeOrder != b.element.routeOrder { return a.element.routeOrder < b.element.routeOrder }
                return a.offset < b.offset
            }
        }.map(\.element)
    }

    // MARK: States

    /// One state per stop, keyed by stop id. Pass the stops in timeline order.
    static func states(for stops: [Stop], context: TimelineContext) -> [Int: TimelineStopState] {
        var result: [Int: TimelineStopState] = [:]
        var nextPicked = false
        for stop in stops {
            var state = baseState(stop, context: context)
            if state == .upcoming || isAttention(state), !nextPicked, isMine(stop, context: context) {
                state = .next
                nextPicked = true
            }
            result[stop.id] = state
        }
        return result
    }

    private static func isAttention(_ state: TimelineStopState) -> Bool {
        if case .attention = state { return true }
        return false
    }

    static func baseState(_ stop: Stop, context: TimelineContext) -> TimelineStopState {
        let visitIds = Set(stop.visits.map(\.visitId))
        if !visitIds.isDisjoint(with: context.pendingFinishVisitIds) { return .savedNotSent }
        if stop.isComplete { return .done }
        if stop.isResolved {
            // Label from every visit that was not completed: all skipped, all cancelled, or a mix.
            let open = Set(stop.visits.map { $0.visitStatus.lowercased() }.filter { $0 != "completed" })
            if open == ["skipped"] { return .closed("Skipped") }
            if open == ["cancelled"] { return .closed("Cancelled") }
            return .closed("Closed")
        }
        if let active = context.activeTimerVisitId, visitIds.contains(active) { return .onSite }
        if !visitIds.isDisjoint(with: context.leftSiteVisitIds) { return .leftSite }
        if stop.isInProgress { return .inProgress }
        if let history = stop.historyLine, history.warning { return .attention(history.text) }
        return .upcoming
    }

    /// Mine = I am on the stop's crew. The "Mine" filter is only the fallback when the crew list or my id
    /// is missing (a cached or older response), never proof of assignment on its own.
    static func isMine(_ stop: Stop, context: TimelineContext) -> Bool {
        if let me = context.myUserId, !stop.crewIds.isEmpty { return stop.crewIds.contains(me) }
        return context.mineScope
    }

    /// The on-site card only takes over in "Mine" (Tim, 2026-11-05: never in All crews).
    static func expandsOnSite(_ state: TimelineStopState, context: TimelineContext) -> Bool {
        (state == .onSite || state == .leftSite) && context.mineScope
    }

    // MARK: Time

    /// Start of the stop in minutes after midnight: the earliest visit start, else the stop's arrival.
    static func startMinutes(_ stop: Stop) -> Int? {
        let starts = stop.visits.compactMap { minutes(fromHHMM: $0.scheduledStart) }
        return starts.min() ?? minutes(fromHHMM: stop.estimatedArrival)
    }

    /// Booked length: the visits' estimated minutes added up (nil when none is known).
    static func bookedMinutes(_ stop: Stop) -> Int? {
        let sum = stop.visits.compactMap(\.estimatedDuration).filter { $0 > 0 }.reduce(0, +)
        return sum > 0 ? sum : nil
    }

    /// "8:00–9:00" on one line; "8:00" when the length is unknown; nil when there is no time at all.
    static func windowText(_ stop: Stop) -> String? {
        guard let start = startMinutes(stop) else { return nil }
        guard let length = bookedMinutes(stop) else { return clock(start) }
        return clock(start) + "\u{2013}" + clock(start + length)
    }

    /// "0:42" for a running timer (hours:minutes).
    static func timerText(elapsedSeconds: Int) -> String {
        let minutes = max(0, elapsedSeconds) / 60
        return "\(minutes / 60):" + String(format: "%02d", minutes % 60)
    }

    /// "of 45 min", or "of 1 h 30 min".
    static func bookedText(_ minutes: Int?) -> String? {
        guard let minutes, minutes > 0 else { return nil }
        if minutes < 60 { return "of \(minutes) min" }
        let h = minutes / 60, m = minutes % 60
        return m == 0 ? "of \(h) h" : "of \(h) h \(m) min"
    }

    /// 12-hour clock without am/pm, as the schedule reads on a crew day: 8:00, 12:30, 1:45.
    static func clock(_ minutesAfterMidnight: Int) -> String {
        let total = ((minutesAfterMidnight % 1440) + 1440) % 1440
        var hour = total / 60 % 12
        if hour == 0 { hour = 12 }
        return "\(hour):" + String(format: "%02d", total % 60)
    }

    static func minutes(fromHHMM raw: String?) -> Int? {
        guard let raw, raw.count >= 4 else { return nil }
        let parts = raw.split(separator: ":")
        guard parts.count >= 2, let h = Int(parts[0]), let m = Int(parts[1]),
              (0..<24).contains(h), (0..<60).contains(m) else { return nil }
        return h * 60 + m
    }

    // MARK: Names (Tim, 2026-11-05: "the word 'property' does not need to be shown")

    /// "Rosie Perera Property" → "Rosie Perera"; "SHANNON STATION" → "Shannon Station" (DisplayNameFormatter).
    static func displayName(_ raw: String) -> String {
        var name = DisplayNameFormatter.string(raw.trimmingCharacters(in: .whitespaces))
        for suffix in [" property", " Property", " PROPERTY"] where name.hasSuffix(suffix) {
            let cut = String(name.dropLast(suffix.count)).trimmingCharacters(in: .whitespaces)
            if !cut.isEmpty { name = cut }
        }
        return name
    }

    /// The street only: "3871 west 3rd avenue, Vancouver, Bc V6R 1M4, Canada" → "3871 west 3rd avenue".
    static func street(_ address: String) -> String {
        let first = address.split(separator: ",", maxSplits: 1).first.map(String.init) ?? address
        let trimmed = first.trimmingCharacters(in: .whitespaces)
        return trimmed.isEmpty ? address : trimmed
    }

    /// "20 min ago", "1 h 5 min ago", "just now".
    static func agoText(since: Date, now: Date) -> String {
        let minutes = max(0, Int(now.timeIntervalSince(since) / 60))
        if minutes < 1 { return "just now" }
        if minutes < 60 { return "\(minutes) min ago" }
        let h = minutes / 60, m = minutes % 60
        return m == 0 ? "\(h) h ago" : "\(h) h \(m) min ago"
    }
}

/// The server's "you left the site, timer stopped" event arrives once, inside a GPS upload reply,
/// and GPSTrackingService clears it 0.1 s later. The timeline keeps it here (per visit, for the day)
/// so the nudge survives scrolling and an app restart, until the visit is finished or the timer restarts.
struct LeftSiteMemory {
    static let defaultsKey = "mw.schedule.leftSite"
    /// Older than this and the event is forgotten (a stop left yesterday is not today's nudge).
    static let keepFor: TimeInterval = 16 * 3600

    let defaults: UserDefaults

    init(defaults: UserDefaults = .standard) { self.defaults = defaults }

    private func load() -> [String: Double] {
        defaults.dictionary(forKey: Self.defaultsKey) as? [String: Double] ?? [:]
    }

    func record(visitId: Int, at date: Date) {
        var all = load()
        all[String(visitId)] = date.timeIntervalSince1970
        defaults.set(all, forKey: Self.defaultsKey)
    }

    func forget(visitId: Int) {
        var all = load()
        all[String(visitId)] = nil
        defaults.set(all, forKey: Self.defaultsKey)
    }

    /// Visit id → when the phone left, pruned of anything older than `keepFor`.
    func current(now: Date) -> [Int: Date] {
        var kept: [String: Double] = [:]
        var result: [Int: Date] = [:]
        for (key, stamp) in load() where now.timeIntervalSince1970 - stamp < Self.keepFor {
            kept[key] = stamp
            if let id = Int(key) { result[id] = Date(timeIntervalSince1970: stamp) }
        }
        defaults.set(kept, forKey: Self.defaultsKey)
        return result
    }
}

/// The new layout is behind a switch: on for admins by default, off for crew until Tim says so.
/// 0 = default for the role, 1 = on, 2 = off.
enum ScheduleTimelineSwitch {
    static let defaultsKey = "mw.schedule.timeline"

    static func isOn(isAdmin: Bool, defaults: UserDefaults = .standard) -> Bool {
        switch defaults.integer(forKey: defaultsKey) {
        case 1: return true
        case 2: return false
        default: return isAdmin
        }
    }
}
