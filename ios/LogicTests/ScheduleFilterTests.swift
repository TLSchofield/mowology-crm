import Foundation

private var failures = 0

private func check(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() {
        failures += 1
        print("FAIL: \(message)")
    }
}

private func date(_ year: Int, _ month: Int, _ day: Int, calendar: Calendar) -> Date {
    calendar.date(from: DateComponents(year: year, month: month, day: day)) ?? .distantPast
}

var calendar = Calendar(identifier: .gregorian)
calendar.firstWeekday = 2
calendar.locale = Locale(identifier: "en_US_POSIX")
calendar.timeZone = TimeZone(secondsFromGMT: 0) ?? calendar.timeZone

let formatter = DateFormatter()
formatter.dateFormat = "MMM d"
formatter.locale = Locale(identifier: "en_US_POSIX")
formatter.calendar = calendar
formatter.timeZone = calendar.timeZone

check(
    ScheduleFilterRules.selectedValues(from: " alpha, , beta ,\t, gamma ", liveValues: [])
        == ["alpha", "beta", "gamma"],
    "filter parsing trims whitespace and removes empty values"
)
check(
    ScheduleFilterRules.selectedValues(from: "alpha,beta,gamma", liveValues: ["gamma", "alpha"])
        == ["alpha", "gamma"],
    "known live values filter remembered selections without reordering"
)
check(
    ScheduleFilterRules.selectedValues(from: "retired,current", liveValues: [])
        == ["retired", "current"],
    "an empty live list keeps every remembered selection"
)

check(
    ScheduleFilterRules.toggledValues(["beta"], value: "alpha", order: ["alpha", "beta"])
        == ["alpha", "beta"],
    "toggle adds a missing value and sorts by server order"
)
check(
    ScheduleFilterRules.toggledValues(["alpha", "beta"], value: "alpha", order: ["alpha", "beta"])
        == ["beta"],
    "toggle removes an existing value"
)
check(
    ScheduleFilterRules.toggledValues(["second", "first"], value: "third", order: ["first", "second", "third"])
        == ["first", "second", "third"],
    "server order determines the sorted pick"
)
check(
    ScheduleFilterRules.toggledValues(["zulu", "beta"], value: "alpha", order: ["beta"])
        == ["beta", "alpha", "zulu"],
    "unknown values sort last and alphabetically"
)
check(
    ScheduleFilterRules.toggledValues(
        ["remembered"],
        value: "new",
        order: ["remembered", "new"],
        startingFromEmpty: true
    ) == ["new"],
    "crew toggle outside all scope starts from an empty pick"
)

check(
    ScheduleFilterRules.crewsParam(scope: "all", selectedCrews: ["7", "3"]) == "7,3",
    "all scope sends selected crews"
)
check(
    ScheduleFilterRules.crewsParam(scope: "mine", selectedCrews: ["7", "3"]).isEmpty,
    "mine scope sends no crews"
)

check(
    ScheduleFilterRules.diskKey(dateString: "2026-06-08", scope: "all", jobType: "", crewsParam: "")
        == "2026-06-08_all_any",
    "disk key represents no filter"
)
check(
    ScheduleFilterRules.diskKey(dateString: "2026-06-08", scope: "all", jobType: "mow", crewsParam: "")
        == "2026-06-08_all_mow",
    "disk key includes a job type"
)
check(
    ScheduleFilterRules.diskKey(dateString: "2026-06-08", scope: "all", jobType: "", crewsParam: "7,3")
        == "2026-06-08_all_any_c7,3",
    "disk key includes crews"
)
check(
    ScheduleFilterRules.diskKey(dateString: "2026-06-08", scope: "all", jobType: "mow", crewsParam: "7,3")
        == "2026-06-08_all_mow_c7,3",
    "disk key includes job type and crews"
)

check(
    ScheduleFilterRules.weekRangeLabel(
        selectedDate: date(2026, 6, 10, calendar: calendar),
        calendar: calendar,
        displayFormatter: formatter
    ) == "Jun 8–14, 2026",
    "same-month week label"
)
check(
    ScheduleFilterRules.weekRangeLabel(
        selectedDate: date(2026, 6, 1, calendar: calendar),
        calendar: calendar,
        displayFormatter: formatter
    ) == "Jun 1–7, 2026",
    "same-month week beginning on Monday"
)
check(
    ScheduleFilterRules.weekRangeLabel(
        selectedDate: date(2026, 6, 30, calendar: calendar),
        calendar: calendar,
        displayFormatter: formatter
    ) == "Jun 29 – Jul 5, 2026",
    "cross-month week label"
)
check(
    ScheduleFilterRules.weekRangeLabel(
        selectedDate: date(2025, 12, 31, calendar: calendar),
        calendar: calendar,
        displayFormatter: formatter
    ) == "Dec 29 – Jan 4, 2026",
    "year-boundary week label uses the ending year"
)

check(
    ScheduleFilterRules.mondayOf(
        week: date(2026, 6, 14, calendar: calendar),
        calendar: calendar
    ) == date(2026, 6, 8, calendar: calendar),
    "Sunday resolves to the preceding Monday"
)
check(
    ScheduleFilterRules.mondayOf(
        week: date(2026, 6, 8, calendar: calendar),
        calendar: calendar
    ) == date(2026, 6, 8, calendar: calendar),
    "Monday resolves to itself"
)

if failures == 0 {
    print("ScheduleFilterRules checks passed")
} else {
    exit(1)
}
