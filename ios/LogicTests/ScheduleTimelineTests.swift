import Foundation

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() { fatalError(message) }
}

private func stop(_ id: Int, start: String?, minutes: Int? = 60, status: String = "scheduled",
                  crew: [Int] = [7], route: Int = 0, summary: String? = nil, arrival: String? = nil) -> Stop {
    let history = summary.map { ServiceHistory(days: [], today: "2026-11-05", winter: false,
                                               lastCompletedAt: nil, applications24h: 0, summary: $0) }
    let visit = Visit(visitId: id * 10, serviceType: "lawn", visitStatus: status,
                      estimatedDuration: minutes, scheduledStart: start, history: history)
    return Stop(stopId: id, stopDate: "2026-11-05", stopStatus: "scheduled", routeOrder: route,
                estimatedArrival: arrival, propertyId: nil, propertyAddress: "\(id) Example Street",
                propertyCity: "Vancouver", propertyName: "Place \(id)", latitude: nil, longitude: nil,
                contactId: nil, contactName: nil, contactPhone: nil, contactEmail: nil, onsiteContact: nil,
                companyName: nil, lawnSqft: nil, crewIds: crew, crewNames: [], visitCount: 1,
                visits: [visit], propertyNotes: nil, stopNotes: nil)
}

// Time window: one line, 12-hour, en dash; start only when the length is unknown.
expect(ScheduleTimelineRules.windowText(stop(1, start: "08:00", minutes: 60)) == "8:00\u{2013}9:00", "8:00–9:00")
expect(ScheduleTimelineRules.windowText(stop(1, start: "12:30", minutes: 60)) == "12:30\u{2013}1:30", "noon wraps to 1:30")
expect(ScheduleTimelineRules.windowText(stop(1, start: "09:15", minutes: nil)) == "9:15", "no length: start only")
expect(ScheduleTimelineRules.windowText(stop(1, start: nil, minutes: 30, arrival: "14:45")) == "2:45\u{2013}3:15", "falls back to the stop's arrival")
expect(ScheduleTimelineRules.windowText(stop(1, start: nil, minutes: 30)) == nil, "no time at all: nil")
expect(ScheduleTimelineRules.windowText(stop(1, start: "00:30", minutes: 30)) == "12:30\u{2013}1:00", "just after midnight reads 12:30")

// Timer and booked length.
expect(ScheduleTimelineRules.timerText(elapsedSeconds: 42 * 60 + 59) == "0:42", "42 min 59 s reads 0:42")
expect(ScheduleTimelineRules.timerText(elapsedSeconds: 75 * 60) == "1:15", "75 min reads 1:15")
expect(ScheduleTimelineRules.timerText(elapsedSeconds: -5) == "0:00", "negative clamps to 0:00")
expect(ScheduleTimelineRules.bookedText(45) == "of 45 min", "booked minutes")
expect(ScheduleTimelineRules.bookedText(90) == "of 1 h 30 min", "booked hours and minutes")
expect(ScheduleTimelineRules.bookedText(120) == "of 2 h", "booked whole hours")
expect(ScheduleTimelineRules.bookedText(nil) == nil, "no booked length")

// Order: by time, untimed after, ties by route order.
let a = stop(1, start: "13:00"), b = stop(2, start: "08:00"), c = stop(3, start: nil, route: 1), d = stop(4, start: nil, route: 0)
expect(ScheduleTimelineRules.ordered([a, b, c, d]).map(\.id) == [2, 1, 4, 3], "time order, then route order")

// States.
let done = stop(1, start: "08:00", status: "completed")
let saved = stop(2, start: "09:15", status: "in_progress")
let onsite = stop(3, start: "10:45", status: "in_progress")
let next = stop(4, start: "12:30")
let later = stop(5, start: "13:45")
let warn = stop(6, start: "14:45", summary: "Skipped Fri \u{00B7} last done 28 days ago")
let day = [done, saved, onsite, next, later, warn]
var ctx = TimelineContext(myUserId: 7, mineScope: true, activeTimerVisitId: 30, pendingFinishVisitIds: [20])
var s = ScheduleTimelineRules.states(for: day, context: ctx)
expect(s[1] == .done, "completed is done")
expect(s[2] == .savedNotSent, "queued finish beats in-progress: saved, not sent")
expect(s[3] == .onSite, "my running timer: on site")
expect(s[4] == .next, "first open stop after: next")
expect(s[5] == .upcoming, "only one next")
expect(s[6] == .attention("Skipped Fri \u{00B7} last done 28 days ago"), "skipped last time: attention")
expect(ScheduleTimelineRules.expandsOnSite(.onSite, context: ctx), "on site expands in Mine")
ctx.mineScope = false
expect(!ScheduleTimelineRules.expandsOnSite(.onSite, context: ctx), "never expands in All crews")

// All crews: next is only a stop I am on; someone else's job in progress is plain in progress.
ctx = TimelineContext(myUserId: 7, mineScope: false, activeTimerVisitId: nil)
let others = stop(10, start: "08:00", crew: [9]), mine = stop(11, start: "09:00", crew: [7]), busy = stop(12, start: "07:00", status: "in_progress", crew: [9])
s = ScheduleTimelineRules.states(for: [busy, others, mine], context: ctx)
expect(s[12] == .inProgress, "someone else's job: in progress")
expect(s[10] == .upcoming, "another crew's stop is never my next")
expect(s[11] == .next, "my first open stop is next")

// Left site: the server stopped my timer; not finished. A running timer wins over the memory.
ctx = TimelineContext(myUserId: 7, mineScope: true, activeTimerVisitId: nil, leftSiteVisitIds: [30])
expect(ScheduleTimelineRules.baseState(onsite, context: ctx) == .leftSite, "left site")
ctx.activeTimerVisitId = 30
expect(ScheduleTimelineRules.baseState(onsite, context: ctx) == .onSite, "timer restarted: on site again")
expect(ScheduleTimelineRules.baseState(stop(3, start: "10:45", status: "completed"), context: ctx) == .done, "finished: done, nudge gone")
expect(ScheduleTimelineRules.baseState(stop(3, start: "10:45", status: "skipped"), context: ctx) == .closed("Skipped"), "skipped closes")

// Mixed closed labels; "mine" comes from the crew list, not the filter.
func multi(_ id: Int, _ statuses: [String]) -> Stop {
    let base = stop(id, start: "08:00")
    let visits = statuses.enumerated().map { Visit(visitId: id * 10 + $0.offset, serviceType: "lawn", visitStatus: $0.element) }
    return Stop(stopId: id, stopDate: nil, stopStatus: "scheduled", routeOrder: 0, estimatedArrival: nil, propertyId: nil,
                propertyAddress: base.propertyAddress, propertyCity: "Vancouver", propertyName: nil, latitude: nil, longitude: nil,
                contactId: nil, contactName: nil, contactPhone: nil, contactEmail: nil, onsiteContact: nil, companyName: nil,
                lawnSqft: nil, crewIds: [7], crewNames: [], visitCount: visits.count, visits: visits, propertyNotes: nil, stopNotes: nil)
}
let plain = TimelineContext(myUserId: 7, mineScope: false)
expect(ScheduleTimelineRules.baseState(multi(20, ["completed", "skipped"]), context: plain) == .closed("Skipped"), "done + skipped: Skipped")
expect(ScheduleTimelineRules.baseState(multi(21, ["completed", "cancelled"]), context: plain) == .closed("Cancelled"), "done + cancelled: Cancelled")
expect(ScheduleTimelineRules.baseState(multi(22, ["skipped", "cancelled"]), context: plain) == .closed("Closed"), "mix: Closed")
expect(!ScheduleTimelineRules.isMine(stop(23, start: "08:00", crew: [9]), context: TimelineContext(myUserId: 7, mineScope: true)), "Mine filter is not proof of assignment")
expect(ScheduleTimelineRules.isMine(stop(24, start: "08:00", crew: []), context: TimelineContext(myUserId: 7, mineScope: true)), "no crew list: the filter decides")

// Attention stop can be next (it keeps its reason in the view).
ctx = TimelineContext(myUserId: 7, mineScope: true)
expect(ScheduleTimelineRules.states(for: [warn], context: ctx)[6] == .next, "attention stop can be next")

// Left-site memory: survives, forgets after 16 h and on request.
let suite = "ScheduleTimelineTests-\(UUID().uuidString)"
let defaults = UserDefaults(suiteName: suite)!
defer { defaults.removePersistentDomain(forName: suite) }
let memory = LeftSiteMemory(defaults: defaults)
let t0 = Date(timeIntervalSince1970: 1_800_000_000)
memory.record(visitId: 30, at: t0)
expect(memory.current(now: t0.addingTimeInterval(600))[30] == t0, "remembered")
expect(memory.current(now: t0.addingTimeInterval(17 * 3600))[30] == nil, "forgotten after 16 h")
memory.record(visitId: 31, at: t0)
memory.forget(visitId: 31)
expect(memory.current(now: t0)[31] == nil, "forget removes it")
expect(ScheduleTimelineRules.agoText(since: t0, now: t0.addingTimeInterval(20 * 60)) == "20 min ago", "ago minutes")
expect(ScheduleTimelineRules.agoText(since: t0, now: t0.addingTimeInterval(65 * 60)) == "1 h 5 min ago", "ago hours")
expect(ScheduleTimelineRules.agoText(since: t0, now: t0) == "just now", "ago now")

// The switch: admins on by default, crew off, explicit choice wins.
defaults.removeObject(forKey: ScheduleTimelineSwitch.defaultsKey)
expect(ScheduleTimelineSwitch.isOn(isAdmin: true, defaults: defaults), "admin default on")
expect(!ScheduleTimelineSwitch.isOn(isAdmin: false, defaults: defaults), "crew default off")
defaults.set(1, forKey: ScheduleTimelineSwitch.defaultsKey)
expect(ScheduleTimelineSwitch.isOn(isAdmin: false, defaults: defaults), "explicit on")
defaults.set(2, forKey: ScheduleTimelineSwitch.defaultsKey)
expect(!ScheduleTimelineSwitch.isOn(isAdmin: true, defaults: defaults), "explicit off")

// Names and streets (Tim, 2026-11-05).
expect(ScheduleTimelineRules.displayName("Rosie Perera Property") == "Rosie Perera", "drop Property")
expect(ScheduleTimelineRules.displayName("Heather Franklyn property") == "Heather Franklyn", "drop lowercase property")
expect(ScheduleTimelineRules.displayName("SHANNON STATION COMMERCIAL + RESIDENTIAL") == "Shannon Station Commercial + Residential", "all caps reads normally")
expect(ScheduleTimelineRules.displayName("Property") == "Property", "never empty")
expect(ScheduleTimelineRules.displayName("Maple Row Strata") == "Maple Row Strata", "other names unchanged")
expect(ScheduleTimelineRules.street("3871 west 3rd avenue, Vancouver, Bc V6R 1M4, Canada") == "3871 west 3rd avenue", "street only")
expect(ScheduleTimelineRules.street("4377 West 11th Avenue") == "4377 West 11th Avenue", "no comma unchanged")

print("Schedule timeline tests passed")
