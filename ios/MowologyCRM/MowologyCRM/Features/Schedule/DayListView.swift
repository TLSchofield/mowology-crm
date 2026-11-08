//
//  DayListView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI
import CoreLocation

struct DayListView: View {
    @Environment(\.itAppStyle) private var appStyle

    let stops: [Stop]
    /// Quote visits for the day (their own cards, above the stops).
    var appointments: [Appointment] = []
    let isLoading: Bool
    let errorMessage: String?
    let isOffline: Bool
    let isAdmin: Bool
    /// Device position the list is sorted from; nil until a fix arrives.
    var userLocation: CLLocation? = nil
    let onRefresh: () async -> Void
    /// Admin only: asks the parent to open the Move Stop sheet for this stop.
    var onMove: ((Stop) -> Void)? = nil
    var onSelect: ((Stop) -> Void)? = nil
    /// A quick action picked on a card (touch-and-hold menu or the ⋯ button). The visit screen runs it.
    var onAction: ((Stop, VisitQuickAction) -> Void)? = nil

    /// The timeline layout's extra state (timer, offline finishes, left-site). nil = always the card list.
    var timeline: ScheduleTimelineInput? = nil
    /// The view chosen on the Schedule bead: .numbered = the timeline (when its input is there), .cards = the card list.
    /// nil = the old per-role timeline switch.
    var layout: ScheduleViewKind? = nil

    /// Tracks whether the one-time initial scroll has already fired.
    @State private var hasAutoScrolled = false
    /// Timeline auto-scroll: the stop it last scrolled to (see timelineList).
    @State private var lastAutoScrollTarget: Int?
    /// The timeline's rail sits on the Schedule bead's axis and runs into it (SPEC §13, Tim 2026-11-06).
    @Environment(\.itTabSlot) private var tabSlot
    @State private var listWidth: CGFloat = 0

    /// 0 = default for the role (admins on), 1 = on, 2 = off (ScheduleTimelineSwitch).
    @AppStorage(ScheduleTimelineSwitch.defaultsKey) private var timelineSetting = 0

    private var useTimeline: Bool {
        guard timeline != nil else { return false }
        if let layout { return layout == .numbered }
        return timelineSetting == 1 || (timelineSetting == 0 && isAdmin)
    }

    /// The stop ID to scroll to on first load.
    /// Priority: in-progress → first non-complete → nil (stay at top).
    private var autoScrollTargetId: Int? {
        if let active = stops.first(where: { $0.isInProgress }) { return active.id }
        if let next   = stops.first(where: { !$0.isResolved })  { return next.id   }
        return nil
    }

    var body: some View {
        Group {
            if isLoading && stops.isEmpty {
                loadingSkeleton
            } else if let message = errorMessage, stops.isEmpty {
                errorBanner(message: message)
            } else if stops.isEmpty && appointments.isEmpty {
                emptyState
            } else if useTimeline, let timeline {
                timelineList(timeline)
            } else {
                stopList
            }
        }
    }

    // MARK: - Timeline (Tim, 2026-11-05; behind ScheduleTimelineSwitch)

    /// The rail's x: the Schedule bead's centre (16 pt row inset + half a marker until the width is known).
    private var railAxis: CGFloat {
        guard let tabSlot, listWidth > 0 else { return 16 + TimelineStopRow.markerSize / 2 }
        return max(TimelineStopRow.markerSize / 2 + 4, tabSlot.axis(width: listWidth))
    }
    private var railLeading: CGFloat { railAxis - TimelineStopRow.markerSize / 2 }

    @ViewBuilder private func railTail(show: Bool) -> some View {
        if show {
            Rectangle().fill(appStyle.line)
                .frame(width: TimelineStopRow.railWidth)
                .frame(maxHeight: .infinity)
                .offset(x: railAxis - TimelineStopRow.railWidth / 2)
                .accessibilityHidden(true)
        }
    }

    private var widthReader: some View {
        GeometryReader { g in
            Color.clear
                .onAppear { listWidth = g.size.width }
                .onChange(of: g.size.width) { _, w in listWidth = w }
        }
    }

    private func timelineList(_ input: ScheduleTimelineInput) -> some View {
        let ordered = ScheduleTimelineRules.ordered(stops)
        let states = ScheduleTimelineRules.states(for: ordered, context: input.context)
        let state: (Stop) -> TimelineStopState = { states[$0.id] ?? .upcoming }
        let focusId = ordered.first { ScheduleTimelineRules.expandsOnSite(state($0), context: input.context) }?.id
        let onAJob = ordered.contains { state($0) == .onSite }
        let nextIndex = ordered.firstIndex { state($0) == .next }
        return ScrollViewReader { proxy in
            List {
                if isOffline {
                    Section {
                        offlineBanner
                            .listRowBackground(Color.clear)
                            .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 0, trailing: 0))
                    }
                }
                if let message = errorMessage {
                    Section {
                        errorBanner(message: message)
                            .listRowBackground(Color.clear)
                            .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 0, trailing: 0))
                    }
                }
                ForEach(appointments) { appt in
                    AppointmentCardView(appointment: appt)
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                        .listRowInsets(EdgeInsets(top: 4, leading: 16, bottom: 4, trailing: 16))
                }
                ForEach(Array(ordered.enumerated()), id: \.element.id) { index, stop in
                    let actions = VisitQuickAction.available(for: stop, isAdmin: isAdmin)
                    TimelineStopRow(
                        stop: stop,
                        number: index + 1,
                        state: state(stop),
                        expanded: stop.id == focusId,
                        isFirst: index == 0,
                        isLast: index == ordered.count - 1,
                        tenantAbove: nextIndex.map { $0 == index && index > 0 } ?? false,
                        tenantBelow: nextIndex.map { $0 == index + 1 } ?? false,
                        // One filled button per screen: no Navigate while a job (running or left) waits on me.
                        showNavigate: !onAJob && focusId == nil,
                        input: input,
                        onSelect: { onSelect?(stop) },
                        onAction: { action in onAction?(stop, action) }
                    )
                    .listRowBackground(appStyle.page)
                    .listRowSeparator(.hidden)
                    .listRowInsets(EdgeInsets(top: 0, leading: railLeading, bottom: 0, trailing: 16))
                    .id(stop.id)
                    .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                        if isAdmin, stop.canBeMoved, let onMove {
                            Button { onMove(stop) } label: { Label("Move", systemImage: "calendar.badge.clock") }
                                .tint(appStyle.tenant)
                        }
                    }
                    .contextMenu {
                        if !actions.isEmpty, onAction != nil {
                            quickActionButtons(actions, for: stop)
                        } else if isAdmin, stop.canBeMoved, let onMove {
                            Button { onMove(stop) } label: { Label("Move to another date", systemImage: "calendar.badge.clock") }
                        }
                    }
                }
            }
            .listStyle(.plain)
            .scrollContentBackground(.hidden)
            // Below the last stop the rail carries on down to the Schedule bead's 12 o'clock. Behind the rows: each
            // stop row is page-filled and draws its own rail, so this shows only in the empty space under them.
            .background(alignment: .topLeading) { railTail(show: !ordered.isEmpty) }
            .background(appStyle.page)
            .background(widthReader)
            .refreshable { await onRefresh() }
            // The job I am on, else my next stop, at the top. Runs when the list appears with its stops
            // (onChange alone never fired then), and once more if the on-site job becomes known later
            // (the timer arrives after the stops). Never again after that, so it does not fight the user.
            .task(id: focusId ?? nextIndex.map { ordered[$0].id }) {
                guard let target = focusId ?? nextIndex.map({ ordered[$0].id }),
                      target != lastAutoScrollTarget,
                      lastAutoScrollTarget == nil || target == focusId else { return }
                lastAutoScrollTarget = target
                try? await Task.sleep(nanoseconds: 150_000_000)
                withAnimation(.easeInOut(duration: 0.35)) { proxy.scrollTo(target, anchor: .top) }
            }
        }
    }

    // MARK: - Stop List

    private var stopList: some View {
        ScrollViewReader { proxy in
            List {
                // Offline banner — shown above the cached stop list.
                if isOffline {
                    Section {
                        offlineBanner
                            .listRowBackground(Color.clear)
                            .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 0, trailing: 0))
                    }
                }

                // Inline error banner above the list when we have stale data + an error.
                if let message = errorMessage {
                    Section {
                        errorBanner(message: message)
                            .listRowBackground(Color.clear)
                            .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 0, trailing: 0))
                    }
                }

                ForEach(appointments) { appt in
                    AppointmentCardView(appointment: appt)
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                        .listRowInsets(EdgeInsets(top: 4, leading: 16, bottom: 4, trailing: 16))
                }

                ForEach(stops) { stop in
                    let actions = VisitQuickAction.available(for: stop, isAdmin: isAdmin)
                    HStack(alignment: .center, spacing: 4) {
                        Button {
                            onSelect?(stop)
                        } label: {
                            StopCardView(stop: stop,
                                         isAdmin: isAdmin,
                                         distanceMeters: distance(to: stop))
                                .padding(.vertical, appStyle.isEnabled ? 0 : 4)
                        }
                        .buttonStyle(.plain)
                        .accessibilityHint(actions.isEmpty ? "Opens visit details" : "Opens visit details. Touch and hold for actions.")

                        // The visible way to the same actions as touch-and-hold: nothing is gesture-only.
                        if !actions.isEmpty, onAction != nil {
                            Menu {
                                quickActionButtons(actions, for: stop)
                            } label: {
                                Image(systemName: "ellipsis")
                                    .font(appStyle.font(.body, weight: .semibold))
                                    .foregroundStyle(appStyle.text)
                                    .frame(width: 44, height: 48)
                                    .contentShape(Rectangle())
                            }
                            .accessibilityLabel("Actions for \(stop.headline ?? stop.propertyAddress)")
                        }
                    }
                    .listRowBackground(appStyle.isEnabled ? appStyle.surface : Color.clear)
                    .listRowSeparator(appStyle.isEnabled ? .visible : .hidden)
                    .listRowSeparatorTint(appStyle.line)
                    .listRowInsets(EdgeInsets(top: appStyle.isEnabled ? 0 : 4, leading: 16, bottom: appStyle.isEnabled ? 0 : 4, trailing: 16))
                    .id(stop.id)
                    .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                        if isAdmin, stop.canBeMoved, let onMove {
                            Button {
                                onMove(stop)
                            } label: {
                                Label("Move", systemImage: "calendar.badge.clock")
                            }
                            .tint(appStyle.tenant)
                        }
                    }
                    .contextMenu {
                        // Touch and hold, slide to an action, let go (iOS context menus pick on release).
                        if !actions.isEmpty, onAction != nil {
                            quickActionButtons(actions, for: stop)
                        } else if isAdmin, stop.canBeMoved, let onMove {
                            Button {
                                onMove(stop)
                            } label: {
                                Label("Move to another date", systemImage: "calendar.badge.clock")
                            }
                        }
                    }
                }
            }
            .listStyle(.plain)
            .scrollContentBackground(.hidden)
            .background(appStyle.page)
            .refreshable { await onRefresh() }
            .onChange(of: stops) { _, newStops in
                guard !hasAutoScrolled, !newStops.isEmpty else { return }
                hasAutoScrolled = true
                guard let targetId = autoScrollTargetId else { return }
                // Brief delay lets the List finish its layout pass before scrolling.
                DispatchQueue.main.asyncAfter(deadline: .now() + 0.15) {
                    withAnimation(.easeInOut(duration: 0.35)) {
                        proxy.scrollTo(targetId, anchor: .top)
                    }
                }
            }
        }
    }

    /// One list of buttons for both the touch-and-hold menu and the ⋯ menu, so they never differ.
    @ViewBuilder
    private func quickActionButtons(_ actions: [VisitQuickAction], for stop: Stop) -> some View {
        ForEach(actions, id: \.self) { action in
            Button(role: action == .skip ? .destructive : nil) {
                if action == .move, let onMove {
                    onMove(stop)              // the schedule's own Move sheet, as the swipe uses
                } else {
                    onAction?(stop, action)
                }
            } label: {
                Label(action.title, systemImage: action.systemImage)
            }
        }
    }

    private func distance(to stop: Stop) -> CLLocationDistance? {
        guard let userLocation, let lat = stop.latitude, let lng = stop.longitude else { return nil }
        return userLocation.distance(from: CLLocation(latitude: lat, longitude: lng))
    }

    // MARK: - Loading Skeleton

    private var loadingSkeleton: some View {
        VStack(spacing: 12) {
            ForEach(0..<4, id: \.self) { _ in
                SkeletonCard()
                    .padding(.horizontal, 16)
            }
            Spacer()
        }
        .padding(.top, 8)
        // Four cards are taller than the space under the week strip: an overflowing stack stays CENTRED and pushed the
        // strip up under the navigation bar while loading (Tim's screenshot, 2026-10-20). Pinned to the top, the rest trimmed.
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .clipped()
        .background(appStyle.page)
    }

    // MARK: - Empty State

    private var emptyState: some View {
        VStack(spacing: 16) {
            Spacer()

            Image(systemName: "calendar.badge.clock")
                .font(appStyle.font(size: 52))
                .foregroundStyle(appStyle.color(.meta, legacy: Color(.systemGray3)))

            Text("No Stops Scheduled")
                .font(appStyle.font(.title3, weight: .bold))
                .foregroundStyle(appStyle.text)

            Text("There are no stops on this day.")
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.meta)
                .multilineTextAlignment(.center)

            Spacer()
        }
        .frame(maxWidth: .infinity)
        .padding(32)
        .background(appStyle.page)
        // Allow pull-to-refresh on empty state.
        .refreshable { await onRefresh() }
    }

    // MARK: - Offline Banner

    private var offlineBanner: some View {
        HStack(spacing: 10) {
            Image(systemName: "wifi.slash")
                .foregroundStyle(appStyle.meta)
                .font(appStyle.font(.subheadline))

            Text("No signal — showing last synced schedule")
                .font(appStyle.font(.caption))
                .foregroundStyle(appStyle.meta)

            Spacer()
        }
        .padding(10)
        .background(appStyle.sunk)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
        .padding(.horizontal, 16)
        .padding(.vertical, 4)
    }

    // MARK: - Error Banner

    private func errorBanner(message: String) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "wifi.exclamationmark")
                .foregroundStyle(appStyle.color(.statusAttention, legacy: .orange))
                .font(appStyle.font(.subheadline))

            Text(message)
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.color(.statusAttention, legacy: .orange))
                .fixedSize(horizontal: false, vertical: true)

            Spacer()
        }
        .padding(12)
        .background(appStyle.color(.statusAttention, legacy: Color.orange).opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
        .overlay(
            RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                .stroke(appStyle.color(.statusAttention, legacy: Color.orange).opacity(0.25), lineWidth: 1)
        )
        .padding(.horizontal, 16)
        .padding(.vertical, 8)
    }
}

// MARK: - Skeleton Card

private struct SkeletonCard: View {
    @Environment(\.itAppStyle) private var appStyle

    @State private var isAnimating = false

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                VStack(alignment: .leading, spacing: 6) {
                    skeletonBar(width: 180, height: 16)
                    skeletonBar(width: 100, height: 12)
                }
                Spacer()
                skeletonBar(width: 50, height: 30)
            }

            HStack(spacing: 6) {
                skeletonBar(width: 80, height: 22)
                skeletonBar(width: 70, height: 22)
            }

            Divider()

            HStack {
                skeletonBar(width: 120, height: 12)
                Spacer()
                skeletonBar(width: 60, height: 12)
            }
        }
        .padding(16)
        .background(appStyle.surface)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
        .shadow(color: appStyle.shadow(0.04), radius: 6, x: 0, y: 2)
        .onAppear { isAnimating = true }
    }

    private func skeletonBar(width: CGFloat, height: CGFloat) -> some View {
        RoundedRectangle(cornerRadius: height / 2)
            .fill(appStyle.color(.line, legacy: Color(.systemGray5)))
            .frame(width: width, height: height)
            .opacity(isAnimating ? 0.5 : 1.0)
            .animation(
                .easeInOut(duration: 0.9).repeatForever(autoreverses: true),
                value: isAnimating
            )
    }
}

// MARK: - Quick actions on a visit card (Tim, 2026-10-20: "slide could be more useful for changes/additions")

/// What touch-and-hold (slide to an action, let go) and the ⋯ button offer on a visit card.
/// Every action except Move opens the visit screen and runs there, so the special-request gate,
/// clock-in and permission checks stay in one place (VisitDetailView.runInitialAction).
/// Kept in this file: the Xcode project is not regenerated on Xcode Cloud, so a new file would not build.
enum VisitQuickAction: String, Hashable {
    case start, finish, photos, notes, move, skip

    var title: String {
        switch self {
        case .start:  return "Start job"
        case .finish: return "Finish and add extras"
        case .photos: return "Photos"
        case .notes:  return "Checklist, materials & notes"
        case .move:   return "Move to another date"
        case .skip:   return "Skip visit"
        }
    }

    var systemImage: String {
        switch self {
        case .start:  return "play.fill"
        case .finish: return "checkmark.circle"
        case .photos: return "camera"
        case .notes:  return "checklist"
        case .move:   return "calendar.badge.clock"
        case .skip:   return "forward.end"
        }
    }

    /// Only what the visit screen itself would allow now. A stop with several visits gets Move only
    /// (which visit to start or finish is picked on the visit screen).
    static func available(for stop: Stop, isAdmin: Bool) -> [VisitQuickAction] {
        let canMove = isAdmin && stop.canBeMoved
        guard stop.visits.count == 1, let visit = stop.visits.first else {
            return canMove ? [.move] : []
        }
        switch visit.visitStatus.lowercased() {
        case "scheduled":
            return [.start, .notes] + (canMove ? [.move] : []) + [.skip]
        case "in_progress":
            return [.finish, .photos, .notes]
        case "completed":
            return [.notes]
        default:
            return canMove ? [.move] : []
        }
    }
}


// MARK: - Time view (Tim, 2026-11-07: the Schedule bead switches map / cards / time / numbered)

/// The day as an hour grid: each stop a block at its booked time, as long as its booked length (at least 30 min),
/// side by side where two overlap; a line for now on today. Stops with no time are listed above the grid. Tap a block
/// to open the stop, as on the cards.
struct DayTimeGridView: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.itTabSlot) private var tabSlot
    let stops: [Stop]
    let isLoading: Bool
    let isToday: Bool
    let onRefresh: () async -> Void
    var onSelect: ((Stop) -> Void)? = nil

    static let hourHeight: CGFloat = 64
    /// The hours sit in pills like the Clock in pill, threaded on the time axis (Tim, 2026-11-07): the axis is the
    /// Schedule bead's, so it runs up to meet the chosen day in the strip and down to the bead, as on the numbered view.
    static let pillWidth: CGFloat = 48
    static let pillHeight: CGFloat = 28
    @State private var width: CGFloat = 0
    private var axis: CGFloat {
        guard let tabSlot, width > 0 else { return 16 + Self.pillWidth / 2 }
        return max(Self.pillWidth / 2 + 4, tabSlot.axis(width: width))
    }
    /// Where the blocks begin: just past the pills.
    private var labelWidth: CGFloat { axis + Self.pillWidth / 2 + 8 }

    private struct Block: Identifiable {
        let stop: Stop
        let start: Int
        let length: Int
        var column = 0
        var columns = 1
        var id: Int { stop.id }
    }

    /// Blocks with overlap columns: greedy, each block in the first column free at its start; a cluster of
    /// overlapping blocks shares one column count.
    private var layout: (blocks: [Block], untimed: [Stop], firstHour: Int, lastHour: Int) {
        var blocks: [Block] = []
        var untimed: [Stop] = []
        for stop in stops {
            if let start = ScheduleTimelineRules.startMinutes(stop) {
                blocks.append(Block(stop: stop, start: start, length: max(30, ScheduleTimelineRules.bookedMinutes(stop) ?? 30)))
            } else {
                untimed.append(stop)
            }
        }
        blocks.sort { ($0.start, $0.stop.routeOrder) < ($1.start, $1.stop.routeOrder) }
        var columnEnds: [Int] = []
        var cluster: [Int] = []
        var clusterEnd = -1
        func closeCluster() {
            let n = cluster.map { blocks[$0].column }.max().map { $0 + 1 } ?? 1
            for i in cluster { blocks[i].columns = n }
            cluster = []
            columnEnds = []
        }
        for i in blocks.indices {
            if blocks[i].start >= clusterEnd { closeCluster() }
            if let free = columnEnds.firstIndex(where: { $0 <= blocks[i].start }) {
                blocks[i].column = free
                columnEnds[free] = blocks[i].start + blocks[i].length
            } else {
                blocks[i].column = columnEnds.count
                columnEnds.append(blocks[i].start + blocks[i].length)
            }
            cluster.append(i)
            clusterEnd = max(clusterEnd, blocks[i].start + blocks[i].length)
        }
        closeCluster()
        let first = min(7, (blocks.map(\.start).min() ?? 7 * 60) / 60)
        let last = max(17, ((blocks.map { $0.start + $0.length }.max() ?? 17 * 60) + 59) / 60)
        return (blocks, untimed, first, min(24, last))
    }

    var body: some View {
        let l = layout
        ScrollViewReader { proxy in
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    if stops.isEmpty && !isLoading {
                        Text("No stops this day.")
                            .font(appStyle.font(.subheadline))
                            .foregroundStyle(appStyle.meta)
                            .frame(maxWidth: .infinity)
                            .padding(.leading, labelWidth)          // off the axis
                            .padding(.top, 40)
                    }
                    if !l.untimed.isEmpty {
                        VStack(alignment: .leading, spacing: 6) {
                            Text("No time set").font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.meta)
                            ForEach(l.untimed) { stop in blockButton(stop, window: nil).frame(height: 44) }
                        }
                        .padding(.leading, labelWidth).padding(.trailing, 16)
                    }
                    grid(l).id("grid")
                }
                .padding(.vertical, 12)
            }
            .refreshable { await onRefresh() }
            // The axis: from the top of the view (the chosen day's tick in the strip above) to the bottom (the Schedule
            // bead's 12 o'clock). Behind everything; the page-filled pills sit on it.
            .background(alignment: .topLeading) {
                Rectangle().fill(appStyle.line)
                    .frame(width: 1.5).frame(maxHeight: .infinity)
                    .offset(x: axis - 0.75)
                    .accessibilityHidden(true)
            }
            .background(appStyle.page)
            .background(GeometryReader { g in
                Color.clear
                    .onAppear { width = g.size.width }
                    .onChange(of: g.size.width) { _, w in width = w }
            })
            .onAppear {
                // Open on the first stop's hour (or now, on today).
                let target = isToday ? Calendar.current.component(.hour, from: Date()) : (l.blocks.first?.start ?? l.firstHour * 60) / 60
                proxy.scrollTo("hour-\(max(l.firstHour, target - 1))", anchor: .top)
            }
        }
    }

    private func grid(_ l: (blocks: [Block], untimed: [Stop], firstHour: Int, lastHour: Int)) -> some View {
        let hours = Array(l.firstHour...max(l.firstHour, l.lastHour))
        let height = CGFloat(hours.count - 1) * Self.hourHeight
        return GeometryReader { geo in
            let labelWidth = self.labelWidth
            let laneWidth = geo.size.width - labelWidth - 16
            ZStack(alignment: .topLeading) {
                ForEach(hours, id: \.self) { h in
                    HStack(alignment: .center, spacing: 8) {
                        Text(ScheduleTimelineRules.clock(h * 60).replacingOccurrences(of: ":00", with: ""))
                            .font(appStyle.font(.subheadline, weight: .semibold))
                            .monospacedDigit()
                            .lineLimit(1).minimumScaleFactor(0.7)
                            .foregroundStyle(appStyle.tenant)
                            .frame(width: Self.pillWidth, height: Self.pillHeight)
                            .background(Capsule().fill(appStyle.page))
                            .overlay(Capsule().stroke(appStyle.tenant, lineWidth: 1.5))
                        Rectangle().fill(appStyle.line).frame(height: 1)
                    }
                    .frame(height: Self.pillHeight)
                    .padding(.leading, axis - Self.pillWidth / 2)
                    .offset(y: CGFloat(h - l.firstHour) * Self.hourHeight - Self.pillHeight / 2)
                    .id("hour-\(h)")
                }
                ForEach(l.blocks) { b in
                    let colWidth = laneWidth / CGFloat(b.columns)
                    blockButton(b.stop, window: ScheduleTimelineRules.windowText(b.stop))
                        .frame(width: colWidth - 4, height: max(28, CGFloat(b.length) / 60 * Self.hourHeight - 3))
                        .offset(x: labelWidth + CGFloat(b.column) * colWidth,
                                y: CGFloat(b.start - l.firstHour * 60) / 60 * Self.hourHeight + 1)
                }
                if isToday {
                    let now = Calendar.current.dateComponents([.hour, .minute], from: Date())
                    let minutes = (now.hour ?? 0) * 60 + (now.minute ?? 0)
                    if minutes >= l.firstHour * 60, minutes <= l.lastHour * 60 {
                        Rectangle().fill(appStyle.blocked)
                            .frame(width: laneWidth + 4, height: 1.5)
                            .offset(x: labelWidth - 4, y: CGFloat(minutes - l.firstHour * 60) / 60 * Self.hourHeight)
                            .accessibilityLabel("Now")
                    }
                }
            }
        }
        .frame(height: height + Self.pillHeight)
        .padding(.top, Self.pillHeight / 2)                // the first pill's top half
        .padding(.leading, 0)
        .padding(.trailing, 16)
    }

    private func blockButton(_ stop: Stop, window: String?) -> some View {
        let done = stop.isResolved
        let active = stop.isInProgress
        let name = stop.propertyName ?? stop.contactName ?? stop.propertyAddress
        return Button { onSelect?(stop) } label: {
            VStack(alignment: .leading, spacing: 1) {
                Text(name)
                    .font(appStyle.font(.footnote, weight: .semibold))
                    .lineLimit(1)
                if let window {
                    Text(window).font(appStyle.font(.caption2)).lineLimit(1)
                }
            }
            .foregroundStyle(active ? appStyle.onTenant : (done ? appStyle.meta : appStyle.text))
            .padding(.horizontal, 8).padding(.vertical, 4)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
            .background(active ? appStyle.tenant : appStyle.surface, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
            .overlay(alignment: .leading) {
                Rectangle().fill(done ? appStyle.line : appStyle.tenant).frame(width: 3)
                    .clipShape(RoundedRectangle(cornerRadius: 2))
            }
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)).stroke(appStyle.line, lineWidth: 1))
            .opacity(done ? 0.7 : 1)
        }
        .buttonStyle(.plain)
        .accessibilityLabel("\(name)\(window.map { ", \($0)" } ?? ""), \(done ? "done" : active ? "in progress" : "to do")")
    }
}
