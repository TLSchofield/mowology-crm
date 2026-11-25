//
//  TimesheetViewModel.swift
//  MowologyCRM
//

import Foundation

@MainActor
final class TimesheetViewModel: ObservableObject {

    @Published private(set) var week: TimesheetWeekResponse?
    @Published private(set) var isLoading = false
    @Published var errorMessage: String?

    /// Monday of the week on screen.
    @Published private(set) var weekStart: Date

    private let apiClient: APIClient

    private static let calendar: Calendar = {
        var cal = Calendar(identifier: .gregorian)
        cal.firstWeekday = 2
        return cal
    }()

    private static let isoDay: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_US_POSIX")
        return f
    }()

    init(authSession: AuthSession) {
        apiClient = APIClient(authSession: authSession)
        weekStart = Self.monday(of: .now)
    }

    // MARK: - Loading

    func load() async {
        isLoading    = true
        errorMessage = nil
        do {
            let response: TimesheetWeekResponse = try await apiClient.request(
                .scheduleTimesheetWeek(start: Self.isoDay.string(from: weekStart))
            )
            week = response
        } catch {
            errorMessage = (error as? APIError)?.localizedDescription ?? error.localizedDescription
        }
        isLoading = false
    }

    func shiftWeek(by weeks: Int) async {
        guard let moved = Self.calendar.date(byAdding: .weekOfYear, value: weeks, to: weekStart),
              moved <= Self.monday(of: .now) else { return }
        weekStart = moved
        week      = nil
        await load()
    }

    var isCurrentWeek: Bool { weekStart == Self.monday(of: .now) }

    // MARK: - Formatting

    var weekLabel: String {
        let end = Self.calendar.date(byAdding: .day, value: 6, to: weekStart) ?? weekStart
        let f = DateFormatter()
        f.locale     = Locale(identifier: "en_CA")
        f.dateFormat = "MMM d"
        return "\(f.string(from: weekStart)) – \(f.string(from: end))"
    }

    /// "7h 45m" — hours are what payroll is read in; seconds are noise here.
    static func duration(_ seconds: Int) -> String {
        let h = seconds / 3600
        let m = (seconds % 3600) / 60
        if h == 0 { return "\(m)m" }
        return m == 0 ? "\(h)h" : "\(h)h \(m)m"
    }

    static func dayLabel(_ isoDate: String) -> String {
        guard let date = isoDay.date(from: isoDate) else { return isoDate }
        let f = DateFormatter()
        f.locale     = Locale(identifier: "en_CA")
        f.dateFormat = "EEEE, MMM d"
        return f.string(from: date)
    }

    static func time(_ raw: String?) -> String {
        guard let raw else { return "now" }
        let parser = DateFormatter()
        parser.dateFormat = "yyyy-MM-dd HH:mm:ss"
        parser.locale     = Locale(identifier: "en_US_POSIX")
        guard let date = parser.date(from: raw) else { return raw }
        let f = DateFormatter()
        f.locale     = Locale(identifier: "en_CA")
        f.dateFormat = "h:mm a"
        return f.string(from: date)
    }

    private static func monday(of date: Date) -> Date {
        let comps = calendar.dateComponents([.yearForWeekOfYear, .weekOfYear], from: date)
        return calendar.date(from: comps) ?? date
    }
}
