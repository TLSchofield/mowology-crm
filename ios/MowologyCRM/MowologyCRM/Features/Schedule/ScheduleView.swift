//
//  ScheduleView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct ScheduleView: View {
    /// Phones get the day carousel (1.3.101); iPad keeps the week strip.
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass
    @Environment(\.itAppStyle) private var appStyle

    @EnvironmentObject private var authSession: AuthSession
    @StateObject private var viewModel: ScheduleViewModel
    @Environment(\.scenePhase) private var scenePhase

    @State private var ottoSuggestion: OttoSuggestion?
    @State private var showDatePicker = false
    @State private var pickerDate     = Date()
    /// Which view the day shows: chosen on the Schedule bead (tap = next, long press = pick), remembered.
    @ObservedObject private var views = ScheduleViewStore.shared
    @State private var isOttoExpanded = false

    /// Navigation path — owned here so a tapped notification can open a stop directly.
    @State private var path: [Stop] = []
    /// "Add a job / visit on the spot" sheet.
    @State private var showingFieldJob = false
    /// Client picked from the Add dial: "Add a job" opens straight on its new-client step.
    @State private var fieldJobStartsWithNewClient = false
    @State private var showingAddTask = false
    /// The stop being moved to another date — drives the Move Stop sheet.
    @State private var movingStop: Stop?
    /// A quick action picked on a card, run once by the visit screen it opens (keyed by stop id).
    @State private var pendingActions: [Int: VisitQuickAction] = [:]
    @ObservedObject private var notificationRouter = NotificationRouter.shared

    private let impactLight  = UIImpactFeedbackGenerator(style: .light)
    private let impactMedium = UIImpactFeedbackGenerator(style: .medium)

    /// Kept inside the navigation content so the calendar toolbar owns its safe area.
    private func ottoPrompt(_ suggestion: OttoSuggestion) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 8) {
                HeadFaceView(slug: "otto", name: "Otto", size: 28)
                Text("Otto needs your attention")
                    .font(appStyle.font(.caption, weight: .semibold))
                    .lineLimit(1)
                Spacer(minLength: 4)
                Button(isOttoExpanded ? "Hide" : "Review") {
                    withAnimation(.easeInOut(duration: 0.2)) { isOttoExpanded.toggle() }
                }
                .font(appStyle.font(.caption, weight: .semibold))
                .frame(minHeight: 48)
            }
            if isOttoExpanded {
                Text(suggestion.text)
                    .font(appStyle.font(.subheadline))
                    .fixedSize(horizontal: false, vertical: true)
                Button("Open Otto’s desk") {
                    notificationRouter.pendingHead = HeadRoute(head: "otto", quoteId: nil)
                }
                .font(appStyle.font(.caption, weight: .semibold))
                .frame(minHeight: 48)
            }
        }
        .foregroundStyle(appStyle.text)
        .padding(.horizontal, 12)
        .background(appStyle.surface)
        .overlay(alignment: .bottom) { Rectangle().fill(appStyle.line).frame(height: 1) }
    }

    // MARK: - Init

    init(authSession: AuthSession? = nil) {
        let session = authSession ?? AuthSession()
        let client  = APIClient(authSession: session)
        _viewModel  = StateObject(wrappedValue: ScheduleViewModel(apiClient: client))
    }

    // MARK: - Body

    var body: some View {
        let _ = RenderRate.tick("ScheduleView")
        NavigationStack(path: $path) {
            VStack(spacing: 0) {

                if let suggestion = ottoSuggestion, appStyle.isEnabled, authSession.user?.isAdmin == true {
                    ottoPrompt(suggestion)
                }

                // MARK: Whose jobs + job type (CrewViewScope, 2026-10-14). Above the days since 1.3.100 (Tim,
                // 2026-11-06): the days sit straight on the list, so the centre line meets them with nothing between.
                scopeBar   // always: Today lives here now

                // MARK: Days — phones: one smooth strip, the chosen day on the line (1.3.101); wider: the week.
                if horizontalSizeClass == .compact {
                    DayCarouselView(selectedDate: weekStripBinding, weekDays: viewModel.weekDays,
                                    showsLine: (views.kind == .numbered && viewModel.timelineInput != nil) || views.kind == .time)
                } else {
                WeekStripView(
                    selectedDate: weekStripBinding,
                    weekDays: viewModel.weekDays
                )
                .gesture(
                    DragGesture(minimumDistance: 20)
                        .onEnded { value in
                            let dx = value.translation.width
                            let dy = abs(value.translation.height)
                            guard abs(dx) > dy * 1.5, abs(dx) > 40 else { return }
                            impactMedium.impactOccurred()
                            Task { await viewModel.advanceWeek(by: dx < 0 ? 1 : -1) }
                        }
                )
                }

                // Anything made with no signal and still on the phone (CreateOutbox).
                OutboxStatusLine()

                // MARK: Content
                if views.kind == .time {
                    DayTimeGridView(
                        stops:     viewModel.stops,
                        isLoading: viewModel.isLoading,
                        isToday:   Calendar.current.isDateInToday(viewModel.selectedDate),
                        onRefresh: { await viewModel.refresh() },
                        onSelect:  { pendingActions[$0.id] = nil; path.append($0) }
                    )
                } else if views.kind != .map {
                    DayListView(
                        stops:        viewModel.stops,
                        appointments: viewModel.appointments,
                        isLoading:    viewModel.isLoading,
                        errorMessage: viewModel.errorMessage,
                        isOffline:    viewModel.isOffline,
                        isAdmin:      authSession.user?.isAdmin ?? false,
                        userLocation: viewModel.userLocation,
                        onRefresh:    { await viewModel.refresh() },
                        onMove:       { movingStop = $0 },
                        onSelect:     { pendingActions[$0.id] = nil; path.append($0) },
                        onAction:     { stop, action in pendingActions[stop.id] = action; path.append(stop) },
                        timeline:     viewModel.timelineInput,
                        layout:       views.kind
                    )
                } else {
                    DayMapView(
                        stops:         viewModel.stops,
                        isLoading:     viewModel.isLoading,
                        isAdmin:       authSession.user?.isAdmin ?? false,
                        routes:        viewModel.crewRoutes,
                        liveCrew:      viewModel.crewLive,
                        currentUserId: authSession.user?.id,
                        userLocation:  viewModel.userLocation,
                        onMove:        { movingStop = $0 }
                    )
                }
            }
            // Single navigationDestination covers both list and map modes.
            .navigationDestination(for: Stop.self) { stop in
                VisitDetailView(
                    stop:          stop,
                    isAdmin:       authSession.user?.isAdmin ?? false,
                    authSession:   authSession,
                    initialAction: pendingActions[stop.id],
                    onInitialActionRun: { pendingActions[stop.id] = nil }
                )
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { toolbarContent }
            .background(appStyle.page)
            .itAppScreen()
        }
        .task {
            await viewModel.refresh()
            viewModel.startPolling()
            // Cold start from a notification tap: the route is already waiting.
            await follow(notificationRouter.pendingRoute)
        }
        .task(id: "\(scenePhase)-\(appStyle.isEnabled)") {
            guard scenePhase == .active, authSession.user?.isAdmin == true, appStyle.isEnabled else {
                ottoSuggestion = nil
                return
            }
            ottoSuggestion = nil
            do {
                let response: HeadCardResponse = try await APIClient(authSession: authSession)
                    .request(.teamHeadBrief(head: "otto"))
                try Task.checkCancellation()
                if response.ok {
                    ottoSuggestion = response.otto?.scheduleAttention
                } else {
                    AppDiagnostics.shared.record(category: "schedule.otto", message: response.error ?? "team-mobile brief head=otto returned ok=false without an error field")
                }
            } catch is CancellationError {
                // Leaving Schedule cancels the request; no stale prompt survives the next appearance.
            } catch {
                AppDiagnostics.shared.record(category: "schedule.otto", message: ErrorDetails.describe(error))
            }
        }
        .onChange(of: notificationRouter.pendingRoute) { _, route in
            Task { await follow(route) }
        }
        .onReceive(NotificationCenter.default.publisher(for: .mwSchedulePushReceived)) { _ in
            Task { await viewModel.refreshSilently() }
        }
        .onReceive(NotificationCenter.default.publisher(for: .mwStopMoved)) { note in
            let from = note.userInfo?["from"] as? String ?? ""
            let to   = note.userInfo?["to"]   as? String ?? ""
            Task { await viewModel.stopMoved(from: from, to: to) }
        }
        .onDisappear {
            viewModel.stopPolling()
            ottoSuggestion = nil
        }
        .onChange(of: scenePhase) { _, newPhase in
            if newPhase == .active {
                Task { await viewModel.selectTodayIfDayRolledOver() }
                viewModel.startPolling()
            } else {
                viewModel.stopPolling()
            }
        }
        // Job or Client from the bar's Add dial (MainTabView).
        // A job made offline just reached the server: show it.
        .onReceive(NotificationCenter.default.publisher(for: .mwOutboxSent)) { _ in
            Task { await viewModel.invalidateAndRefresh() }
        }
        .onReceive(NotificationCenter.default.publisher(for: .mwAddFromBar)) { note in
            impactMedium.impactOccurred()
            if note.userInfo?["task"] as? Bool == true { showingAddTask = true; return }
            fieldJobStartsWithNewClient = note.userInfo?["startWithNewClient"] as? Bool ?? false
            showingFieldJob = true
        }
        .sheet(isPresented: $showingAddTask) {
            AddTaskView(authSession: authSession)
        }
        .sheet(isPresented: $showingFieldJob) {
            FieldJobView(authSession: authSession, startWithNewClient: fieldJobStartsWithNewClient) {
                // A visit or job was just added for today — show it.
                Task { await viewModel.invalidateAndRefresh() }
            }
        }
        .sheet(isPresented: $showDatePicker) {
            datePicker
        }
        .sheet(item: $movingStop) { stop in
            MoveStopSheet(stop: stop, apiClient: viewModel.client)
        }
        .fullScreenCover(isPresented: $viewModel.quizRequired) {
            QuizView(authSession: authSession) {
                Task {
                    await viewModel.quizPassed()
                    // A notification tapped while the quiz gate was up waits until now.
                    await follow(notificationRouter.pendingRoute)
                }
            }
        }
    }

    // MARK: - Notification Routing

    /// Opens the stop a tapped notification refers to. Falls back to just showing
    /// the right day when the stop is no longer on it (reassigned, cancelled).
    private func follow(_ route: NotificationRoute?) async {
        guard let route, !viewModel.quizRequired else { return }
        notificationRouter.pendingRoute = nil
        showDatePicker = false
        pendingActions = [:]          // a notification only opens the visit; no card action rides along
        if let stop = await viewModel.resolve(route) {
            path = [stop]
        } else {
            path = []
        }
    }

    // MARK: - Date Picker Sheet

    private var datePicker: some View {
        NavigationStack {
            DatePicker(
                "Jump to date",
                selection: $pickerDate,
                displayedComponents: .date
            )
            .datePickerStyle(.graphical)
            .tint(appStyle.tenant)
            .padding(.horizontal)
            .navigationTitle("Jump to Date")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showDatePicker = false }
                        .foregroundStyle(appStyle.tenant)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Go") {
                        impactLight.impactOccurred()
                        showDatePicker = false
                        Task { await viewModel.selectDate(pickerDate) }
                    }
                    .font(appStyle.font(.subheadline, weight: .semibold))
                    .foregroundStyle(appStyle.tenant)
                }
            }
        }
        .presentationDetents([.medium])
        .presentationDragIndicator(.visible)
    }

    // MARK: - Computed

    /// Mine / All crews (when allowed) and the job type — remembered on the phone.
    private var scopeBar: some View {
        HStack(spacing: 8) {
            // Today moved down from the top bar (Tim, 2026-11-08): it sits with the filters it resets beside.
            Button {
                impactLight.impactOccurred()
                Task { await viewModel.selectDate(.now) }
            } label: {
                Text("Today")
                    .font(appStyle.font(.subheadline, weight: .medium))
                    .foregroundStyle(appStyle.tenant)
                    .frame(minHeight: 44)
            }
            .buttonStyle(.plain)
            // Whose jobs: a checklist like the job types (Tim, 2026-11-08: "same for all crews").
            // Mine, All crews, or any set of people; the menu stays open while ticking.
            if viewModel.canSwitchScope {
                Menu {
                    Toggle("Mine", isOn: Binding(
                        get: { viewModel.scope == "mine" },
                        set: { on in if on { Task { await viewModel.setCrews("", scope: "mine") } } }
                    ))
                    Toggle("All crews", isOn: Binding(
                        get: { viewModel.scope == "all" && viewModel.selectedCrews.isEmpty },
                        set: { on in if on { Task { await viewModel.setCrews("", scope: "all") } } }
                    ))
                    if !viewModel.crews.isEmpty {
                        Divider()
                        ForEach(viewModel.crews) { c in
                            Toggle(c.label, isOn: Binding(
                                get: { viewModel.scope == "all" && viewModel.selectedCrews.contains(c.value) },
                                set: { _ in Task { await viewModel.toggleCrew(c.value) } }
                            ))
                        }
                    }
                } label: {
                    HStack(spacing: 4) {
                        Text(crewFilterLabel).lineLimit(1)
                        Image(systemName: "chevron.down").font(appStyle.font(.caption2))
                    }
                    .font(appStyle.font(.subheadline))
                    .frame(minHeight: 44)
                }
                .menuActionDismissBehavior(.disabled)
            }
            if !viewModel.jobTypes.isEmpty {
                // A checklist: tick as many job types as you like; the menu stays open while you tick.
                Menu {
                    Toggle("All job types", isOn: Binding(
                        get: { viewModel.selectedJobTypes.isEmpty },
                        set: { on in if on { Task { await viewModel.setJobType("") } } }
                    ))
                    Divider()
                    ForEach(viewModel.jobTypes) { t in
                        Toggle(t.label, isOn: Binding(
                            get: { viewModel.selectedJobTypes.contains(t.value) },
                            set: { _ in Task { await viewModel.toggleJobType(t.value) } }
                        ))
                    }
                } label: {
                    HStack(spacing: 4) {
                        Text(jobTypeFilterLabel)
                            .lineLimit(1)
                        Image(systemName: "chevron.down").font(appStyle.font(.caption2))
                    }
                    .font(appStyle.font(.subheadline))
                    .frame(minHeight: 44)
                }
                .menuActionDismissBehavior(.disabled)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 4)
    }

    /// "Mine", "All crews", the one person picked, or "Trevor James +1".
    private var crewFilterLabel: String {
        if viewModel.scope == "mine" { return "Mine" }
        let labels = viewModel.selectedCrews.compactMap { v in viewModel.crews.first(where: { $0.value == v })?.label }
        guard let first = labels.first else { return "All crews" }
        return labels.count == 1 ? first : "\(first) +\(labels.count - 1)"
    }

    /// "All job types", the one picked, or "Lawn Care +2".
    private var jobTypeFilterLabel: String {
        let picked = viewModel.selectedJobTypes
        let labels = picked.compactMap { v in viewModel.jobTypes.first(where: { $0.value == v })?.label }
        guard let first = labels.first else { return "All job types" }
        return labels.count == 1 ? first : "\(first) +\(labels.count - 1)"
    }

    private var weekStripBinding: Binding<Date> {
        Binding(
            get: { viewModel.selectedDate },
            set: { newDate in
                impactLight.impactOccurred()
                Task { await viewModel.selectDate(newDate) }
            }
        )
    }

    // MARK: - Toolbar

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        // Otto owns Schedule (Kaname spec §2). His attention banner is already on this screen, so no line.
        ToolbarItem(placement: .navigationBarLeading) { ScreenHeadFace(slug: "otto") }

        ToolbarItem(placement: .principal) {
            Button {
                pickerDate = viewModel.selectedDate
                showDatePicker = true
            } label: {
                Text(viewModel.selectedDate.formatted(.dateTime.month(.abbreviated)))   // "Nov": "November" wrapped beside the Clock in chip (Tim, 2026-11-06)
                    .font(appStyle.font(.headline, weight: .semibold))
                    .foregroundStyle(appStyle.text)
            }
            .buttonStyle(.plain)
        }

        // Add moved to the bar's Add slot (Tim, 2026-11-05); the top right shows the shift instead.
        ToolbarItem(placement: .navigationBarTrailing) { TimeChip() }

        // The map / list switch moved onto the Schedule bead (Tim, 2026-11-07), which now cycles all four views.

        ToolbarItem(placement: .navigationBarTrailing) {
            Button {
                Task { await viewModel.invalidateAndRefresh() }
            } label: {
                Image(systemName: "arrow.clockwise")
                    .foregroundStyle(appStyle.tenant)
            }
            .disabled(viewModel.isLoading)
        }
    }
}

#Preview {
    ScheduleView()
        .environmentObject(AuthSession())
        .environmentObject(ReadyBriefStore())
}
