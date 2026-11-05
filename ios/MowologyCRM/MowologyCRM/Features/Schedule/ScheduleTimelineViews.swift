//
//  ScheduleTimelineViews.swift
//  MowologyCRM
//
//  The schedule day as a timeline (Tim, 2026-11-05): a rail of numbered stops, the time window on one
//  line at the far right, done stops dimmed to one line, the next stop with its note and Navigate, and
//  the visit you are on expanded with its timer, notes, quick add and Finish visit. What each stop IS
//  comes from ScheduleTimelineRules; this file only draws it. Shown by DayListView behind
//  ScheduleTimelineSwitch (admins first).
//

import SwiftUI
import MapKit

/// Everything the timeline needs from ScheduleViewModel besides the stops.
struct ScheduleTimelineInput: Equatable {
    var context: TimelineContext
    /// When my running job timer started (fetch time minus the server's elapsed seconds); ticks locally.
    var timerStartedAt: Date?
    /// Visit id → when the server stopped my timer because the phone left the site.
    var leftSiteAt: [Int: Date] = [:]
}

struct TimelineStopRow: View {
    @Environment(\.itAppStyle) private var style
    @Environment(\.openURL) private var openURL
    @AppStorage("ittai.design.field") private var field = false

    let stop: Stop
    let number: Int
    let state: TimelineStopState
    /// The visit I am on (or just left) takes over as the focus card — only in "Mine".
    let expanded: Bool
    let isFirst: Bool
    let isLast: Bool
    /// The rail segment leading into the next stop is drawn in the tenant colour.
    let tenantAbove: Bool
    let tenantBelow: Bool
    /// Navigate shows on the next stop only when I am not on a job.
    let showNavigate: Bool
    let input: ScheduleTimelineInput
    let onSelect: () -> Void
    let onAction: (VisitQuickAction) -> Void

    private let railWidth: CGFloat = 1.5
    private let markerSize: CGFloat = 28

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            rail
            VStack(alignment: .leading, spacing: 0) {
                header
                expandedBody
            }
            .padding(.top, 3)
            .padding(.bottom, state.isCollapsed ? 12 : 18)
        }
    }

    // MARK: Rail and marker

    private var rail: some View {
        VStack(spacing: 0) {
            Rectangle()
                .fill(isFirst ? Color.clear : (tenantAbove ? style.tenant : style.line))
                .frame(width: railWidth, height: 4)
            marker
            Rectangle()
                .fill(isLast ? Color.clear : (tenantBelow ? style.tenant : style.line))
                .frame(width: railWidth)
                .frame(maxHeight: .infinity)
        }
        .frame(width: markerSize)
        .accessibilityHidden(true)
    }

    @ViewBuilder
    private var marker: some View {
        ZStack {
            Circle().fill(markerFill)
            markerRing
            markerLabel
        }
        .frame(width: markerSize, height: markerSize)
    }

    private var markerFill: Color {
        state == .next ? style.tenant : style.surface
    }

    @ViewBuilder
    private var markerRing: some View {
        switch state {
        case .savedNotSent:
            Circle().stroke(style.saved, style: StrokeStyle(lineWidth: 1.5, dash: [3, 3]))
        case .done, .closed:
            Circle().stroke(style.meta, lineWidth: 1.5)
        case .onSite, .leftSite, .inProgress:
            Circle().stroke(style.progress, lineWidth: 2)
        case .next:
            Circle().stroke(style.tenant, lineWidth: 1.5)
        case .attention:
            Circle().stroke(style.attention, lineWidth: 1.5)
        case .upcoming:
            Circle().stroke(style.line, lineWidth: 1.5)
        }
    }

    @ViewBuilder
    private var markerLabel: some View {
        switch state {
        case .done:
            Image(systemName: "checkmark").font(.system(size: 12, weight: .semibold)).foregroundStyle(style.meta)
        case .closed:
            Image(systemName: "minus").font(.system(size: 12, weight: .semibold)).foregroundStyle(style.meta)
        default:
            Text("\(number)")
                .font(style.font(size: 13, weight: .semibold))
                .monospacedDigit()
                .foregroundStyle(numberColor)
        }
    }

    private var numberColor: Color {
        switch state {
        case .next: return style.onTenant
        case .onSite, .leftSite, .inProgress: return style.progress
        case .attention: return style.attention
        case .savedNotSent: return style.saved
        default: return style.text
        }
    }

    // MARK: Header (the row's tap target: opens the visit)

    private var header: some View {
        Button(action: onSelect) {
            VStack(alignment: .leading, spacing: 4) {
                HStack(alignment: .firstTextBaseline, spacing: 8) {
                    Text(name)
                        .font(style.font(size: nameSize, weight: isDimmed ? .medium : .semibold))
                        .foregroundStyle(isDimmed ? style.meta : style.text)
                        .fixedSize(horizontal: false, vertical: true)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    timeView
                }
                statusLine
                if !state.isCollapsed && !(state == .next && expandedNextIsOneLine) {
                    Text(detailLine)
                        .font(style.font(size: field ? 16 : 14))
                        .foregroundStyle(style.meta)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            .frame(minHeight: state.isCollapsed ? 32 : 40, alignment: .top)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(accessibilityText)
        .accessibilityHint("Opens the visit")
    }

    /// While I am on a job, the next stop is a single line (Tim's on-site design).
    private var expandedNextIsOneLine: Bool { state == .next && !showNavigate }

    private var isDimmed: Bool {
        switch state {
        case .done, .closed: return true
        default: return false
        }
    }

    private var nameSize: CGFloat {
        if state.isCollapsed { return field ? 17 : 15 }
        return field ? 19 : 17
    }

    private var timeSize: CGFloat { field ? 17 : 15 }

    @ViewBuilder
    private var timeView: some View {
        if state == .onSite, let started = input.timerStartedAt {
            TimelineView(.periodic(from: .now, by: 15)) { context in
                let elapsed = Int(context.date.timeIntervalSince(started))
                HStack(alignment: .firstTextBaseline, spacing: 4) {
                    Text(ScheduleTimelineRules.timerText(elapsedSeconds: elapsed))
                        .font(style.font(size: timeSize, weight: .semibold))
                        .foregroundStyle(style.progress)
                    if let booked = ScheduleTimelineRules.bookedText(activeBookedMinutes) {
                        Text(booked)
                            .font(style.font(size: timeSize))
                            .foregroundStyle(style.meta)
                    }
                }
                .monospacedDigit()
                .fixedSize()
            }
        } else if let window = ScheduleTimelineRules.windowText(stop) {
            Text(window)
                .font(style.font(size: timeSize, weight: isDimmed ? .medium : .semibold))
                .foregroundStyle(isDimmed ? style.meta : style.text)
                .monospacedDigit()
                .fixedSize()
        }
    }

    /// Booked minutes of the visit my timer is on (the stop's total when that visit is not found).
    private var activeBookedMinutes: Int? {
        if let id = input.context.activeTimerVisitId,
           let visit = stop.visits.first(where: { $0.visitId == id }),
           let minutes = visit.estimatedDuration, minutes > 0 { return minutes }
        return ScheduleTimelineRules.bookedMinutes(stop)
    }

    @ViewBuilder
    private var statusLine: some View {
        switch state {
        case .savedNotSent:
            statusLabel("Saved, not sent yet", icon: "icloud", color: style.saved)
        case .closed(let word):
            statusLabel(word, icon: nil, color: style.meta)
        case .inProgress:
            // Someone else's job (or mine in All crews without a timer here): the word is needed.
            statusLabel("In progress", icon: "clock", color: style.progress)
        case .attention(let reason):
            statusLabel("Needs attention", icon: "exclamationmark.triangle", color: style.attention)
            reasonText(reason)
        case .next:
            if let history = stop.historyLine, history.warning {
                statusLabel("Needs attention", icon: "exclamationmark.triangle", color: style.attention)
                reasonText(history.text)
            }
        default:
            EmptyView()
        }
    }

    private func statusLabel(_ text: String, icon: String?, color: Color) -> some View {
        HStack(spacing: 5) {
            if let icon { Image(systemName: icon).font(.system(size: field ? 14 : 12, weight: .semibold)) }
            Text(text).font(style.font(size: field ? 15 : 13, weight: .semibold))
        }
        .foregroundStyle(color)
    }

    private func reasonText(_ text: String) -> some View {
        Text(text)
            .font(style.font(size: field ? 15 : 13))
            .foregroundStyle(style.meta)
            .fixedSize(horizontal: false, vertical: true)
    }

    // MARK: Expanded parts

    @ViewBuilder
    private var expandedBody: some View {
        if expanded && state == .onSite {
            onSiteCard
        } else if expanded && state == .leftSite {
            leftSiteCard
        } else if state == .next && showNavigate {
            if let notes = crewNotes {
                noteBlock(title: "Before you arrive", text: notes).padding(.top, 10)
            }
            filledButton("Navigate", icon: "location.fill", label: "Navigate to \(name)") { navigate() }
                .padding(.top, 10)
        }
    }

    private var onSiteCard: some View {
        VStack(alignment: .leading, spacing: 10) {
            if let notes = crewNotes { noteBlock(title: "Job notes", text: notes) }
            quickAdd
            filledButton("Finish visit", icon: nil, label: "Finish visit at \(name)") { run(.finish) }
        }
        .padding(12)
        .background(style.surface)
        .overlay(RoundedRectangle(cornerRadius: 10).stroke(style.line, lineWidth: 1))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .padding(.top, 10)
    }

    private var leftSiteCard: some View {
        VStack(alignment: .leading, spacing: 10) {
            leftSiteNotice
            if let notes = crewNotes { noteBlock(title: "Job notes", text: notes) }
            quickAdd
            Button { run(.start) } label: {
                Text("Still working here")
                    .font(style.font(size: field ? 17 : 15, weight: .semibold))
                    .foregroundStyle(style.text)
                    .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityHint("Starts the job timer again")
            filledButton("Finish visit", icon: nil, label: "Finish visit at \(name)") { run(.finish) }
        }
        .padding(12)
        .background(style.surface)
        .overlay(RoundedRectangle(cornerRadius: 10).stroke(style.line, lineWidth: 1))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .padding(.top, 10)
    }

    /// Server-stopped timer: say when, and what to do. On the surface colour (AAA), amber edge.
    private var leftSiteNotice: some View {
        let ago = stop.visits.compactMap { input.leftSiteAt[$0.visitId] }.max()
            .map { ScheduleTimelineRules.agoText(since: $0, now: Date()) }
        return HStack(alignment: .top, spacing: 8) {
            Image(systemName: "exclamationmark.triangle")
                .font(.system(size: field ? 16 : 14, weight: .semibold))
            VStack(alignment: .leading, spacing: 3) {
                Text("Timer stopped when you left \(name)" + (ago.map { " \($0)" } ?? ""))
                    .font(style.font(size: field ? 16 : 14, weight: .semibold))
                Text("Finish the visit, or start the timer again if you are still working.")
                    .font(style.font(size: field ? 15 : 13))
            }
            .fixedSize(horizontal: false, vertical: true)
        }
        .foregroundStyle(style.attention)
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(style.surface)
        .overlay(alignment: .leading) { Rectangle().fill(style.attention).frame(width: 3) }
        .overlay(RoundedRectangle(cornerRadius: 6).stroke(style.line, lineWidth: 1))
        .clipShape(RoundedRectangle(cornerRadius: 6))
    }

    private var quickAdd: some View {
        let columns = [GridItem(.flexible(), spacing: 8), GridItem(.flexible(), spacing: 8), GridItem(.flexible(), spacing: 8)]
        return LazyVGrid(columns: columns, spacing: 8) {
            quickButton("Note", icon: "text.bubble") { run(.notes) }
            quickButton("Photo", icon: "camera") { run(.photos) }
            quickButton("Materials", icon: "shippingbox") { run(.notes) }
        }
    }

    private func quickButton(_ title: String, icon: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            // Icon above the word: three buttons fit a phone width without cutting "Materials" short.
            VStack(spacing: 3) {
                Image(systemName: icon).font(.system(size: field ? 16 : 14, weight: .semibold))
                Text(title).font(style.font(size: field ? 15 : 13, weight: .semibold)).lineLimit(1)
            }
            .foregroundStyle(style.text)
            .padding(.vertical, 6)
            .frame(maxWidth: .infinity, minHeight: field ? 56 : ItSize.targetPhone)
            .background(style.surface)
            .overlay(RoundedRectangle(cornerRadius: 8).stroke(style.line, lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel("Add \(title.lowercased()) for \(name)")
    }

    private func filledButton(_ title: String, icon: String?, label: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 8) {
                if let icon { Image(systemName: icon).font(.system(size: 15, weight: .semibold)) }
                Text(title).font(style.font(size: field ? 18 : 17, weight: .semibold)).lineLimit(1)
            }
            .foregroundStyle(style.onTenant)
            .frame(maxWidth: .infinity, minHeight: 56)
            .background(style.tenant)
            .clipShape(RoundedRectangle(cornerRadius: 10))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }

    private func noteBlock(title: String, text: String) -> some View {
        HStack(alignment: .top, spacing: 8) {
            Image(systemName: "text.bubble")
                .font(.system(size: field ? 15 : 13))
                .foregroundStyle(style.text)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(style.font(size: field ? 16 : 14, weight: .semibold))
                Text(text).font(style.font(size: field ? 16 : 14)).fixedSize(horizontal: false, vertical: true)
            }
            .foregroundStyle(style.text)
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(style.sunk)
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }

    // MARK: Data helpers

    private var name: String {
        stop.headline.map(ScheduleTimelineRules.displayName) ?? ScheduleTimelineRules.street(stop.propertyAddress)
    }

    /// Address (city only when it is not the usual one is left to the visit screen) · service types.
    private var detailLine: String {
        var seen = Set<String>()
        let services = stop.visits.map(\.serviceTypeLabel).filter { seen.insert($0).inserted }
        let parts = [stop.headline == nil ? nil : ScheduleTimelineRules.street(stop.propertyAddress)] + services.map { Optional($0) }
        return parts.compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " \u{00B7} ")
    }

    /// Today's note first, then the property's standing notes (gate codes, dogs).
    private var crewNotes: String? {
        let parts = [stop.stopNotes, stop.propertyNotes]
            .compactMap { $0?.trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { !$0.isEmpty }
        return parts.isEmpty ? nil : parts.joined(separator: "\n")
    }

    private var accessibilityText: String {
        var parts = [name]
        switch state {
        case .done: parts.append("done")
        case .closed(let word): parts.append(word.lowercased())
        case .savedNotSent: parts.append("saved, not sent yet")
        case .onSite: parts.append("in progress")
        case .leftSite: parts.append("timer stopped when you left")
        case .inProgress: parts.append("in progress")
        case .next: parts.append("next stop")
        case .attention(let reason): parts.append("needs attention, \(reason)")
        case .upcoming: break
        }
        if let window = ScheduleTimelineRules.windowText(stop) {
            parts.append(window.replacingOccurrences(of: "\u{2013}", with: " to "))
        }
        if !state.isCollapsed { parts.append(detailLine) }
        return parts.joined(separator: ", ")
    }

    /// Quick actions run on the visit screen (one place for the permission and clock-in checks).
    /// A stop with several visits opens the visit screen instead, where the visit is picked.
    private func run(_ action: VisitQuickAction) {
        if stop.visits.count == 1 { onAction(action) } else { onSelect() }
    }

    private func navigate() {
        if let lat = stop.latitude, let lon = stop.longitude {
            let item = MKMapItem(placemark: MKPlacemark(
                coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lon),
                addressDictionary: ["Street": stop.propertyAddress, "City": stop.propertyCity]))
            item.name = name
            item.openInMaps(launchOptions: [MKLaunchOptionsDirectionsModeKey: MKLaunchOptionsDirectionsModeDriving])
        } else if let q = "\(stop.propertyAddress), \(stop.propertyCity)".addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed),
                  let url = URL(string: "maps://?daddr=\(q)") {
            openURL(url)
        }
    }
}

// MARK: - Debug-only demo (synthetic data; never in a TestFlight/Release build)

/// Launch a Debug build with `-mwTimelineDemo day|onsite|left` (and `-mwTimelineField` for field mode)
/// to see the timeline with the design's synthetic day, without signing in or any client data.
struct ScheduleTimelineDemoOverlay: View {
    var body: some View {
        #if DEBUG
        let args = ProcessInfo.processInfo.arguments
        if args.contains("-mwBeadDemo") {
            MeniscusBeadDemo().itAppTheme(enabled: true, field: false)
        } else if let i = args.firstIndex(of: "-mwTimelineDemo") {
            let mode = i + 1 < args.count ? args[i + 1] : "day"
            let field = args.contains("-mwTimelineField")
            ScheduleTimelineDemo(mode: mode)
                .itAppTheme(enabled: true, field: field)
                .onAppear { UserDefaults.standard.set(field, forKey: "ittai.design.field") }  // the rows read it
        }
        #endif
    }
}

#if DEBUG
private struct ScheduleTimelineDemo: View {
    @Environment(\.itAppStyle) private var style
    let mode: String

    private static func visit(_ id: Int, _ service: String, _ status: String, _ start: String, _ minutes: Int, warn: String? = nil) -> Visit {
        Visit(visitId: id, serviceType: service, visitStatus: status, estimatedDuration: minutes, scheduledStart: start,
              history: warn.map { ServiceHistory(days: [], today: "2026-11-05", winter: false, lastCompletedAt: nil,
                                                 applications24h: 0, summary: $0) })
    }

    private static func stop(_ id: Int, _ name: String, _ address: String, _ visit: Visit, notes: String? = nil) -> Stop {
        Stop(stopId: id, stopDate: "2026-11-05", stopStatus: "scheduled", routeOrder: id, estimatedArrival: nil,
             propertyId: nil, propertyAddress: address, propertyCity: "Vancouver", propertyName: name,
             latitude: nil, longitude: nil, contactId: nil, contactName: nil, contactPhone: nil, contactEmail: nil,
             onsiteContact: nil, companyName: nil, lawnSqft: nil, crewIds: [7], crewNames: ["Jordan L."],
             visitCount: 1, visits: [visit], propertyNotes: nil, stopNotes: notes)
    }

    private var stops: [Stop] {
        let onSite = mode != "day"
        return [
            Self.stop(1, "Cedar Court", "123 Example Street", Self.visit(10, "lawn_care", "completed", "08:00", 60)),
            Self.stop(2, "Hillside Residence", "45 Sample Avenue", Self.visit(20, "maintenance", "in_progress", "09:15", 75)),
            Self.stop(3, "Maple Row Strata", "8 Placeholder Road", Self.visit(30, "pruning", "in_progress", "10:45", 45),
                      notes: onSite ? "Shape the front hedges. Bag all clippings. Client away today." : nil),
            Self.stop(4, "Harbour View Lodge", "200 Demo Boulevard", Self.visit(40, "cleanup", "scheduled", "12:30", 60),
                      notes: "Gate code at side door"),
            Self.stop(5, "Birch Lane Property", "17 Test Crescent", Self.visit(50, "lawn_care", "scheduled", "13:45", 45)),
            Self.stop(6, "Ridge Park Homes", "9 Mock Way", Self.visit(60, "maintenance", "scheduled", "14:45", 45,
                                                                     warn: "Skipped last time \u{00B7} last done 28 days ago")),
        ]
    }

    private var input: ScheduleTimelineInput {
        switch mode {
        case "onsite":
            return ScheduleTimelineInput(context: TimelineContext(myUserId: 7, mineScope: true, activeTimerVisitId: 30,
                                                                  pendingFinishVisitIds: [20]),
                                         timerStartedAt: Date().addingTimeInterval(-42 * 60))
        case "left":
            return ScheduleTimelineInput(context: TimelineContext(myUserId: 7, mineScope: true, activeTimerVisitId: nil,
                                                                  pendingFinishVisitIds: [20], leftSiteVisitIds: [30]),
                                         leftSiteAt: [30: Date().addingTimeInterval(-20 * 60)])
        default:   // the plain day: Maple Row is another crew's job in progress
            return ScheduleTimelineInput(context: TimelineContext(myUserId: 7, mineScope: false, activeTimerVisitId: nil,
                                                                  pendingFinishVisitIds: [20]))
        }
    }

    var body: some View {
        DayListView(stops: stops, isLoading: false, errorMessage: nil, isOffline: false, isAdmin: true,
                    onRefresh: {}, onSelect: { _ in }, onAction: { _, _ in }, timeline: input)
            .background(style.page)
    }
}
#endif

#if DEBUG
/// `-mwBeadDemo`: the tab bar with Receipts selected, to see the camera in the bead.
private struct MeniscusBeadDemo: View {
    @Environment(\.itAppStyle) private var style
    @State private var selected = 4
    var body: some View {
        VStack(spacing: 0) {
            style.page
            ItMeniscusBar(items: [
                ItMeniscusItem(id: 0, title: "Schedule", icon: Image(systemName: "calendar")),
                ItMeniscusItem(id: 1, title: "Time", icon: Image(systemName: "clock.fill")),
                ItMeniscusItem(id: 2, title: "Kaname", icon: Image(systemName: "hexagon")),
                ItMeniscusItem(id: 3, title: "Search", icon: Image(systemName: "magnifyingglass")),
                ItMeniscusItem(id: 4, title: "Receipts", icon: Image(systemName: "doc.text.image"),
                               beadIcon: Image(systemName: "camera.fill"), beadLabel: "Snap a receipt"),
            ], selected: $selected)
        }
    }
}
#endif
