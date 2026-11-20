import Foundation

var failures = 0
func check(_ got: String?, _ want: String?, _ label: String) {
    if got != want { failures += 1; print("FAIL \(label): got \(got ?? "nil"), want \(want ?? "nil")") }
}

var cal = Calendar(identifier: .gregorian)
cal.timeZone = TimeZone(identifier: "America/Vancouver")!
let f = DateFormatter()
f.calendar = cal; f.timeZone = cal.timeZone; f.locale = Locale(identifier: "en_US_POSIX"); f.dateFormat = "yyyy-MM-dd HH:mm:ss"
let now = f.date(from: "2026-11-05 14:10:00")!   // a Thursday

check(MiaWhen.text("2026-11-05 19:30:00", now: now, calendar: cal), "today at 7:30 pm", "today")
check(MiaWhen.text("2026-11-06 09:00:00", now: now, calendar: cal), "tomorrow at 9 am", "tomorrow, on the hour")
check(MiaWhen.text("2026-11-09 09:30:00", now: now, calendar: cal), "Monday at 9:30 am", "weekday name")
check(MiaWhen.text(nil, now: now, calendar: cal), nil, "missing")
check(MiaWhen.text("not a date", now: now, calendar: cal), nil, "unreadable")
check(MiaWhen.text("2026-11-05 12:05:00", now: now, calendar: cal), "today at 12:05 pm", "noon is pm")

if failures > 0 { print("MiaWhen: \(failures) failed"); exit(1) }
print("MiaWhen: all passed")
