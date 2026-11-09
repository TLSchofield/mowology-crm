import Foundation
import CoreGraphics

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() { fatalError(message) }
}

private var cal: Calendar = {
    var c = Calendar(identifier: .gregorian)
    c.timeZone = TimeZone(identifier: "America/Vancouver")!
    return c
}()

private func day(_ y: Int, _ m: Int, _ d: Int, hour: Int = 9) -> Date {
    cal.date(from: DateComponents(year: y, month: m, day: d, hour: hour))!
}

private typealias R = DayCarouselRules
private let today = day(2026, 11, 8)

// Window: today is the anchor while the chosen day fits around it.
expect(R.anchor(for: day(2026, 11, 20), today: today, current: nil, calendar: cal) == cal.startOfDay(for: today), "a near day keeps today's window")
expect(R.anchor(for: day(2026, 9, 9), today: today, current: nil, calendar: cal) == cal.startOfDay(for: today), "60 days back still fits")
expect(R.anchor(for: day(2027, 3, 8), today: today, current: nil, calendar: cal) == cal.startOfDay(for: today), "120 days on still fits")
// A date picked far away moves the window onto it, and stays there while its neighbours are browsed.
let far = day(2027, 6, 1)
let farAnchor = R.anchor(for: far, today: today, current: nil, calendar: cal)
expect(farAnchor == cal.startOfDay(for: far), "a far date becomes the anchor")
expect(R.anchor(for: day(2027, 6, 20), today: today, current: farAnchor, calendar: cal) == farAnchor, "browsing near a far date keeps its window")
expect(R.anchor(for: day(2026, 11, 9), today: today, current: farAnchor, calendar: cal) == cal.startOfDay(for: today), "back near today: today's window again")
expect(R.anchor(for: day(2026, 9, 8), today: today, current: nil, calendar: cal) == cal.startOfDay(for: day(2026, 9, 8)), "61 days back is outside")

// Index and date are each other's inverse, across the November clock change (1 Nov 2026) and March's (14 Mar 2027).
let anchor = cal.startOfDay(for: today)
expect(R.index(of: today, anchor: anchor, calendar: cal) == R.daysBefore, "today sits daysBefore chips in")
for i in [0, 1, 53, 59, 60, 61, 126, 127, R.count - 1] {
    let d = R.date(at: i, anchor: anchor, calendar: cal)
    expect(R.index(of: d, anchor: anchor, calendar: cal) == i, "index \(i) round-trips")
    expect(R.index(of: d.addingTimeInterval(23 * 3600), anchor: anchor, calendar: cal) == i, "late that evening is the same chip (\(i))")
}
expect(R.days(from: day(2026, 10, 31), to: day(2026, 11, 2), calendar: cal) == 2, "the 25-hour day counts as one day")
expect(R.index(of: day(2030, 1, 1), anchor: anchor, calendar: cal) == R.count - 1, "beyond the strip clamps to its last chip")
expect(R.index(of: day(2020, 1, 1), anchor: anchor, calendar: cal) == 0, "before the strip clamps to its first chip")

// Travel: the chip on the line, and resting on a day.
expect(R.pitch == 58, "52 pt chip + 6 pt gap")
expect(R.index(travel: 0) == 0, "no travel: the first day")
expect(R.index(travel: 58 * 60) == 60, "60 pitches: today")
expect(R.index(travel: 58 * 60 + 28) == 60, "under half a pitch on: still today")
expect(R.index(travel: 58 * 60 + 30) == 61, "over half a pitch on: tomorrow")
expect(R.index(travel: -40) == 0, "a bounce past the start stays on the first day")
expect(R.index(travel: 58 * 500) == R.count - 1, "a bounce past the end stays on the last day")
expect(R.isOnDay(travel: 58 * 12), "exactly on a day")
expect(R.isOnDay(travel: 58 * 12 + 0.5), "half a point off is on the day")
expect(!R.isOnDay(travel: 58 * 12 + 3), "3 pt off is between days")

// Landing: on a day AND still for long enough. Nothing loads for a day flown past or a finger resting mid-chip.
expect(R.landed(travel: 58 * 61, stillFor: 0.05) == nil, "still moving: not landed")
expect(R.landed(travel: 58 * 61 + 20, stillFor: 2) == nil, "held between two days: not landed")
expect(R.landed(travel: 58 * 61, stillFor: 0.1) == 61, "on a day and still: landed")
expect(R.landed(travel: 58 * 61 - 0.4, stillFor: 0.3) == 61, "a hair off the snap point still lands")

// Chips: one per day, the server's dots where known, "today" from the phone.
let known = ["2026-11-10": ScheduleDay(date: "2026-11-10", dayName: "Tue", dayNumber: 10, isToday: true,
                                       stopCount: 4, visitCount: 6, hasIncomplete: true)]
let chips = R.chips(anchor: anchor, today: today, known: known, calendar: cal)
expect(chips.count == 181, "181 days")
expect(chips[60].date == "2026-11-08" && chips[60].isToday, "today's chip")
expect(chips[60].dayName == "Sun" && chips[60].dayNumber == 8, "today reads Sun 8")
expect(chips[62].stopCount == 4 && chips[62].hasIncomplete, "a loaded day keeps its dot")
expect(!chips[62].isToday, "the server's stale 'today' never wins over the phone's")
expect(chips.filter(\.isToday).count == 1, "exactly one today")
expect(chips[0].date == "2026-09-09" && chips[180].date == "2027-03-08", "the strip's ends")
expect(Set(chips.map(\.date)).count == 181, "no day twice (clock changes included)")
// Left open overnight: the same window, today moved on.
let tomorrow = R.chips(anchor: anchor, today: day(2026, 11, 9, hour: 0), known: known, calendar: cal)
expect(tomorrow[61].isToday && !tomorrow[60].isToday, "after midnight today moves on a chip")
// A far window has no today in it.
expect(R.chips(anchor: farAnchor, today: today, known: [:], calendar: cal).allSatisfy { !$0.isToday }, "a far window has no today")

// The pill's lens stays on the line whatever the chip's position.
expect(R.lensShift(chipMidX: 100, axis: 39) == -61, "a chip right of the line shifts its lens left")
expect(R.lensShift(chipMidX: 39, axis: 39) == 0, "the chip on the line needs no shift")

print("DayCarouselRules: all checks passed")
