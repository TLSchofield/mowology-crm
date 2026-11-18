//
//  MiaWhen.swift
//  MowologyCRM
//
//  When one of Mia's messages will go, in words: "today at 7:30 pm", "tomorrow at 9:30 am", "Friday at 9 am".
//  Foundation only, so the logic tests compile it on its own (ios/LogicTests/MiaWhenTests.swift).
//

import Foundation

enum MiaWhen {
    /// raw: the server's "yyyy-MM-dd HH:mm:ss" (the business's clock). nil when missing or unreadable.
    static func text(_ raw: String?, now: Date = Date(), calendar: Calendar = .current) -> String? {
        guard let raw, !raw.isEmpty else { return nil }
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.calendar = calendar
        f.timeZone = calendar.timeZone
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        guard let d = f.date(from: raw) else { return nil }
        let t = DateFormatter()
        t.locale = Locale(identifier: "en_US_POSIX")
        t.timeZone = calendar.timeZone
        t.dateFormat = calendar.component(.minute, from: d) == 0 ? "h a" : "h:mm a"
        t.amSymbol = "am"
        t.pmSymbol = "pm"
        let day: String
        if calendar.isDate(d, inSameDayAs: now) {
            day = "today"
        } else if let tomorrow = calendar.date(byAdding: .day, value: 1, to: now), calendar.isDate(d, inSameDayAs: tomorrow) {
            day = "tomorrow"
        } else {
            let w = DateFormatter()
            w.locale = Locale(identifier: "en_US_POSIX")
            w.timeZone = calendar.timeZone
            w.dateFormat = "EEEE"
            day = w.string(from: d)
        }
        return "\(day) at \(t.string(from: d))"
    }
}
