//
//  MoveStopSheet.swift
//  MowologyCRM
//
//  Move a stop to another date — the phone's answer to the desktop schedule's
//  drag-and-drop. Two taps: pick a day, confirm. Each day shows how many stops it
//  already carries, so the office can see where there is room before moving.
//

import SwiftUI

// MARK: - API Response

/// Response from POST /api/schedule/reschedule-stop.
/// A soft capacity warning comes back as `success: false, warning: true` with a
/// message; resending with `force` moves the stop anyway.
struct RescheduleStopResponse: Decodable {
    let success: Bool
    let warning: Bool?
    let message: String?
    let stopId:  Int?
    let oldDate: String?
    let newDate: String?
    let merged:  Bool?

    enum CodingKeys: String, CodingKey {
        case success, warning, message, merged
        case stopId  = "stop_id"
        case oldDate = "old_date"
        case newDate = "new_date"
    }
}

private struct MoveSheetWeekResponse: Decodable {
    let days: [ScheduleDay]
}

extension Notification.Name {
    /// Posted after a stop moves. `userInfo`: "from" and "to" ISO date strings.
    static let mwStopMoved = Notification.Name("ca.mowology.stopMoved")
}

extension Stop {
    /// A stop can move only while it still has work to do and nothing is running.
    var canBeMoved: Bool { !isResolved && !isInProgress }
}

// MARK: - Sheet

struct MoveStopSheet: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    let stop: Stop
    let apiClient: APIClient
    /// Called with the new ISO date once the server confirms the move.
    var onMoved: (String) -> Void = { _ in }

    @Environment(\.dismiss) private var dismiss

    @State private var selectedISO: String?
    @State private var stopCounts: [String: Int] = [:]
    @State private var isShowingCalendar = false
    @State private var calendarDate = Date()
    @State private var isMoving = false
    @State private var errorMessage: String?
    @State private var capacityWarning: String?

    private let impact = UIImpactFeedbackGenerator(style: .medium)
    private let notify = UINotificationFeedbackGenerator()

    private static let dayCount = 14

    private static let calendar: Calendar = {
        var cal = Calendar(identifier: .gregorian)
        cal.firstWeekday = 2
        cal.locale = Locale(identifier: "en_CA")
        return cal
    }()

    private static let isoFormatter: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_CA")
        f.calendar   = calendar
        return f
    }()

    // MARK: - Body

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    header

                    if let errorMessage {
                        banner(errorMessage, icon: "exclamationmark.triangle.fill")
                    }
                    if let capacityWarning {
                        banner(capacityWarning, icon: "clock.badge.exclamationmark.fill")
                    }

                    dayGrid

                    Button {
                        isShowingCalendar.toggle()
                    } label: {
                        Label(isShowingCalendar ? "Hide calendar" : "Further out…",
                              systemImage: "calendar")
                            .font(appStyle.font(.subheadline, weight: .semibold))
                            .foregroundStyle(appStyle.tenant)
                            .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                    }

                    if isShowingCalendar {
                        DatePicker("Date",
                                   selection: $calendarDate,
                                   in: Date()...,
                                   displayedComponents: .date)
                            .datePickerStyle(.graphical)
                            .tint(appStyle.tenant)
                            .onChange(of: calendarDate) { _, picked in
                                select(Self.isoFormatter.string(from: picked))
                            }
                    }
                }
                .padding(16)
            }
            .safeAreaInset(edge: .bottom) { confirmBar }
            .background(appStyle.page)
            .itAppScreen()
            .navigationTitle("Move Stop")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }.foregroundStyle(appStyle.tenant)
                }
            }
            .task { await loadStopCounts() }
        }
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
    }

    // MARK: - Header

    private var header: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(stop.headline ?? stop.propertyAddress)
                .font(appStyle.font(.headline))
            Text(stop.fullAddress)
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.meta)
            if let from = stop.stopDate {
                Text("Currently \(Self.longLabel(from))")
                    .font(appStyle.font(.footnote))
                    .foregroundStyle(appStyle.meta)
            }
        }
    }

    // MARK: - Day Grid

    /// The next two weeks, starting tomorrow (or today when the stop is in the past/future).
    private var upcomingDays: [String] {
        let today = Self.calendar.startOfDay(for: Date())
        return (0..<Self.dayCount + 1).compactMap { offset in
            guard let day = Self.calendar.date(byAdding: .day, value: offset, to: today) else { return nil }
            let iso = Self.isoFormatter.string(from: day)
            return iso == stop.stopDate ? nil : iso
        }
        .prefix(Self.dayCount)
        .map { $0 }
    }

    private var dayGrid: some View {
        LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 8), count: appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? 2 : 4), spacing: 8) {
            ForEach(upcomingDays, id: \.self) { iso in
                dayChip(iso)
            }
        }
    }

    private func dayChip(_ iso: String) -> some View {
        let isSelected = selectedISO == iso
        let count      = stopCounts[iso]
        return Button {
            select(iso)
        } label: {
            VStack(spacing: 2) {
                Text(Self.weekdayLabel(iso))
                    .font(appStyle.font(.caption2, weight: .semibold))
                    .foregroundStyle(isSelected ? appStyle.onTenant.opacity(0.85) : appStyle.meta)
                Text(Self.dayNumber(iso))
                    .font(appStyle.font(.title3, weight: .bold))
                    .foregroundStyle(isSelected ? appStyle.onTenant : appStyle.text)
                Text(count.map { $0 == 0 ? "free" : "\($0) stop\($0 == 1 ? "" : "s")" } ?? " ")
                    .font(appStyle.font(.caption2))
                    .foregroundStyle(isSelected ? appStyle.onTenant.opacity(0.85) : appStyle.meta)
            }
            .frame(maxWidth: .infinity, minHeight: 72)
            .background(isSelected ? appStyle.tenant : appStyle.surface)
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        }
        .buttonStyle(.plain)
        .accessibilityLabel("\(Self.longLabel(iso)), \(count ?? 0) stops")
    }

    // MARK: - Confirm Bar

    private var confirmBar: some View {
        Button {
            Task { await move() }
        } label: {
            HStack {
                if isMoving { ProgressView().tint(appStyle.onTenant) }
                Text(confirmTitle)
                    .font(appStyle.font(.headline))
            }
            .frame(maxWidth: .infinity, minHeight: 52)
            .foregroundStyle(appStyle.onTenant)
            .background(selectedISO == nil ? (appStyle.isEnabled ? appStyle.sunk : Color(.systemGray3)) : appStyle.tenant)
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
        }
        .disabled(selectedISO == nil || isMoving)
        .padding(.horizontal, 16)
        .padding(.vertical, 10)
        .background(.bar)
    }

    private var confirmTitle: String {
        guard let selectedISO else { return "Pick a day" }
        let label = Self.longLabel(selectedISO)
        return capacityWarning == nil ? "Move to \(label)" : "Move anyway to \(label)"
    }

    private func banner(_ message: String, icon: String) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon)
            Text(message).fixedSize(horizontal: false, vertical: true)
            Spacer()
        }
        .font(appStyle.font(.subheadline))
        .foregroundStyle(appStyle.isEnabled ? appStyle.attention : .orange)
        .padding(12)
        .background((appStyle.isEnabled ? appStyle.attention : Color.orange).opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
    }

    // MARK: - Actions

    private func select(_ iso: String) {
        guard iso != stop.stopDate else { return }
        impact.impactOccurred()
        selectedISO     = iso
        capacityWarning = nil
        errorMessage    = nil
    }

    /// Stop counts for the weeks the grid spans. Decorative — failures are silent.
    @MainActor
    private func loadStopCounts() async {
        let today = Self.calendar.startOfDay(for: Date())
        var mondays: [String] = []
        for offset in stride(from: 0, through: Self.dayCount + 7, by: 7) {
            guard let day = Self.calendar.date(byAdding: .day, value: offset, to: today),
                  let monday = Self.calendar.dateInterval(of: .weekOfYear, for: day)?.start else { continue }
            mondays.append(Self.isoFormatter.string(from: monday))
        }
        for monday in Set(mondays) {
            guard let response: MoveSheetWeekResponse =
                    try? await apiClient.request(.scheduleWeek(start: monday)) else { continue }
            for day in response.days {
                stopCounts[day.date] = day.stopCount
            }
        }
    }

    @MainActor
    private func move() async {
        guard let target = selectedISO, !isMoving else { return }
        isMoving     = true
        errorMessage = nil
        defer { isMoving = false }

        do {
            let response: RescheduleStopResponse = try await apiClient.request(
                .rescheduleStop,
                body: [
                    "stop_id":  stop.stopId,
                    "new_date": target,
                    "force":    capacityWarning != nil
                ]
            )
            if response.success {
                notify.notificationOccurred(.success)
                NotificationCenter.default.post(
                    name: .mwStopMoved,
                    object: nil,
                    userInfo: ["from": stop.stopDate ?? "", "to": target]
                )
                onMoved(target)
                dismiss()
            } else if response.warning == true {
                notify.notificationOccurred(.warning)
                capacityWarning = response.message ?? "Move stop response warning=true; server returned no message field."
            } else {
                errorMessage = response.message ?? "Move stop response success=false; server returned no message field."
            }
        } catch {
            notify.notificationOccurred(.error)
            errorMessage = (error as? APIError)?.localizedDescription ?? error.localizedDescription
        }
    }

    // MARK: - Formatting

    private static func date(_ iso: String) -> Date? { isoFormatter.date(from: iso) }

    private static func weekdayLabel(_ iso: String) -> String {
        guard let d = date(iso) else { return "" }
        if calendar.isDateInToday(d)    { return "TODAY" }
        if calendar.isDateInTomorrow(d) { return "TMRW" }
        return d.formatted(.dateTime.weekday(.abbreviated)).uppercased()
    }

    private static func dayNumber(_ iso: String) -> String {
        guard let d = date(iso) else { return "" }
        return d.formatted(.dateTime.day())
    }

    private static func longLabel(_ iso: String) -> String {
        guard let d = date(iso) else { return iso }
        return d.formatted(.dateTime.weekday(.abbreviated).month(.abbreviated).day())
    }
}
