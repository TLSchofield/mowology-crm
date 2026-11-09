//
//  DayCarouselRules.swift
//  MowologyCRM
//
//  The day carousel's arithmetic, kept apart from the view so it is checked on the Mac
//  (`bash ios/LogicTests/run.sh`): which days the strip holds, which one is on the line, when it has landed.
//

import Foundation
import CoreGraphics

enum DayCarouselRules {
    static let chipWidth: CGFloat = 52
    static let spacing: CGFloat = 6
    /// One day's travel along the strip.
    static let pitch: CGFloat = chipWidth + spacing

    /// Days the strip holds before and after its anchor day.
    static let daysBefore = 60
    static let daysAfter = 120
    static let count = daysBefore + daysAfter + 1

    /// How long the strip must sit still on a day before that day counts as landed.
    static let restSeconds: Double = 0.1
    /// How near a snap point counts as on it (the snap ends within a fraction of a point).
    static let restTolerance: CGFloat = 0.75

    // MARK: Window

    /// The anchor day the strip is built around: today while the chosen day fits around it, else the anchor it
    /// already has while the day still fits, else the chosen day itself (a date picked far away in the date picker).
    static func anchor(for date: Date, today: Date, current: Date?, calendar: Calendar) -> Date {
        let today = calendar.startOfDay(for: today)
        if holds(date, anchor: today, calendar: calendar) { return today }
        if let current, holds(date, anchor: current, calendar: calendar) { return calendar.startOfDay(for: current) }
        return calendar.startOfDay(for: date)
    }

    static func holds(_ date: Date, anchor: Date, calendar: Calendar) -> Bool {
        let d = days(from: anchor, to: date, calendar: calendar)
        return d >= -daysBefore && d <= daysAfter
    }

    /// Whole calendar days between two dates (a clock change never makes a day 23 or 25 hours "long").
    static func days(from a: Date, to b: Date, calendar: Calendar) -> Int {
        calendar.dateComponents([.day], from: calendar.startOfDay(for: a), to: calendar.startOfDay(for: b)).day ?? 0
    }

    /// The chip index of a date, kept inside the strip.
    static func index(of date: Date, anchor: Date, calendar: Calendar) -> Int {
        min(max(days(from: anchor, to: date, calendar: calendar) + daysBefore, 0), count - 1)
    }

    static func date(at index: Int, anchor: Date, calendar: Calendar) -> Date {
        let start = calendar.startOfDay(for: anchor)
        return calendar.date(byAdding: .day, value: index - daysBefore, to: start) ?? start
    }

    // MARK: Travel

    /// The chip on the line after the strip has travelled this far from its first day.
    static func index(travel: CGFloat) -> Int {
        min(max(Int((travel / pitch).rounded()), 0), count - 1)
    }

    /// The strip sits on a day (not between two).
    static func isOnDay(travel: CGFloat) -> Bool {
        abs(travel - (travel / pitch).rounded() * pitch) <= restTolerance
    }

    /// The landed day, or nil while the strip is still moving or resting between two days.
    static func landed(travel: CGFloat, stillFor seconds: Double) -> Int? {
        guard seconds >= restSeconds, isOnDay(travel: travel) else { return nil }
        return index(travel: travel)
    }

    // MARK: Chips

    /// The strip's days: the server's summary where a week has loaded, else the plain day (no dot yet).
    static func chips(anchor: Date, today: Date, known: [String: ScheduleDay], calendar: Calendar) -> [ScheduleDay] {
        let iso = DateFormatter()
        iso.calendar = calendar; iso.timeZone = calendar.timeZone
        iso.locale = Locale(identifier: "en_CA"); iso.dateFormat = "yyyy-MM-dd"
        let weekday = DateFormatter()
        weekday.calendar = calendar; weekday.timeZone = calendar.timeZone
        weekday.locale = Locale(identifier: "en_CA"); weekday.dateFormat = "EEE"
        return (0..<count).map { i in
            let day = date(at: i, anchor: anchor, calendar: calendar)
            let key = iso.string(from: day)
            let isToday = calendar.isDate(day, inSameDayAs: today)
            if let k = known[key] {
                // "Today" is the phone's, so a strip left open overnight moves it on.
                return ScheduleDay(date: k.date, dayName: k.dayName, dayNumber: k.dayNumber, isToday: isToday,
                                   stopCount: k.stopCount, visitCount: k.visitCount, hasIncomplete: k.hasIncomplete)
            }
            return ScheduleDay(date: key, dayName: weekday.string(from: day),
                               dayNumber: calendar.component(.day, from: day), isToday: isToday,
                               stopCount: 0, visitCount: 0, hasIncomplete: false)
        }
    }

    /// The pill's lens: how far a chip's own copy of the pill slides so it stays on the line.
    /// A chip a full pitch away shows none of it.
    static func lensShift(chipMidX: CGFloat, axis: CGFloat) -> CGFloat { axis - chipMidX }
}
