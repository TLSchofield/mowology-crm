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

    @State private var showDatePicker = false
    @State private var pickerDate     = Date()
    /// Which view the day shows: chosen on the Schedule bead (tap = next, long press = pick), remembered.
    @ObservedObject private var views = ScheduleViewStore.shared
    /// The month of the day on the carousel's line while it differs from the loaded day's.
    @State private var lineMonth: Date?
    /// The day's content fades in when another day lands (2026-11-08).
    @State private var dayOpacity = 1.0
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    /// Navigation path — owned here so a tapped notification can open a stop directly.
    @State private var path: [Stop] = []
    /// "Add a job / visit on the spot" sheet.
    @State private var showingFieldJob = false
    /// Client picked from the Add dial: "Add a job" opens straight on its new-client step.
    @State private var fieldJobStartsWithNewClient = false
    @State private var addForm: AddForm?
    /// The stop being moved to another date — drives the Move Stop sheet.
    @State private var movingStop: Stop?
    /// A quick action picked on a card, run once by the visit screen it opens (keyed by stop id).
    @State private var pendingActions: [Int: VisitQuickAction] = [:]
    @ObservedObject private var notificationRouter = NotificationRouter.shared

    private let impactLight  = UIImpactFeedbackGenerator(style: .light)
    private let impactMedium = UIImpactFeedbackGenerator(style: .medium)

    // MARK: - Init

    init(authSession: AuthSession? = nil) {
        let session = authSession ?? AuthSession()
        let client  = APIClient(authSession: session)
        _viewModel  = StateObject(wrappedValue: ScheduleViewModel(apiClient: client))
    }

    // MARK: - Body

    var body: some View {
        let _ = RenderRate.tick("ScheduleView")
        let _ = Self._printChanges()   // read by ChangeCapture; the redraw alarm names the property that changed
        NavigationStack(path: $path) {
            VStack(spacing: 0) {

                // MARK: Whose jobs + job type (CrewViewScope, 2026-10-14). Above the days since 1.3.100 (Tim,
                // 2026-11-06): the days sit straight on the list, so the centre line meets them with nothing between.
                activeFilterChips   // only when something is filtered, so a partial day is never hidden

                // MARK: Days — phones: one smooth strip, the chosen day on the line (1.3.101); wider: the week.
                if horizontalSizeClass == .compact {
                    DayCarouselView(selectedDate: viewModel.selectedDate, weekDays: viewModel.weekDays,
                                    dotsKey: viewModel.dotsKey,
                                    showsLine: (views.kind == .numbered && viewModel.timelineInput != nil) || views.kind == .time,
                                    onLand: { weekStripBinding.wrappedValue = $0 },
                                    onLine: { day in
                                        // "Nov" follows the day on the line, not only the day loaded.
                                        let shown = lineMonth ?? viewModel.selectedDate
                                        if !Calendar.current.isDate(day, equalTo: shown, toGranularity: .month) { lineMonth = day }
                                    })
                        .equatable()
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
                Group {
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
                .opacity(dayOpacity)
            }
            // Another day landed: its content (already swapped in with the date) fades in.
            .onChange(of: viewModel.selectedDate) { old, new in
                lineMonth = nil
                guard !reduceMotion, !Calendar.current.isDate(old, inSameDayAs: new) else { return }
                var still = Transaction(); still.disablesAnimations = true
                withTransaction(still) { dayOpacity = 0 }
                DispatchQueue.main.async { withAnimation(.easeOut(duration: 0.22)) { dayOpacity = 1 } }
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
                // The head line leads the screen, as on Schedule (Tim, 2026-11-17): the system bar is hidden and
                // the back button and address sit in their own row under it.
                .toolbar(.hidden, for: .navigationBar)
                .safeAreaInset(edge: .top, spacing: 0) {
                    VStack(spacing: 0) {
                        ScreenHeader(slug: "otto")
                        VisitBackBar(title: stop.propertyAddress)
                    }
                }
                .background(PopGestureEnabler())
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar(.hidden, for: .navigationBar)
            .safeAreaInset(edge: .top, spacing: 0) {
                ScreenHeader(
                    slug: "otto",
                    isRefreshing: viewModel.isLoading,
                    showsRefreshButton: false,   // pull-to-refresh covers it (2026-11-18, one-row header)
                    onRefresh: { Task { await viewModel.invalidateAndRefresh() } },
                    title: { headerTitle },
                    secondaryActions: { filterMenu }
                )
            }
            .background(appStyle.page)
            .itAppScreen()
        }
        .task {
            await viewModel.refresh()
            viewModel.startPolling()
            // Cold start from a notification tap: the route is already waiting.
            await follow(notificationRouter.pendingRoute)
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
            switch note.userInfo?["item"] as? String {
            case "Task":    addForm = .task;    return
            case "Quote":   addForm = .quote;   return
            case "Invoice": addForm = .invoice; return
            default: break
            }
            fieldJobStartsWithNewClient = note.userInfo?["startWithNewClient"] as? Bool ?? false
            showingFieldJob = true
        }
        .sheet(item: $addForm) { form in
            switch form {
            case .task:    AddTaskView(authSession: authSession)
            case .quote:   AddQuoteView(authSession: authSession)
            case .invoice: AddInvoiceView(authSession: authSession)
            }
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

    /// "Nov 18 ▾" opens the date jump; "Today" appears beside it only when another day is showing.
    private var headerTitle: some View {
        HStack(spacing: 8) {
            Button {
                pickerDate = viewModel.selectedDate
                showDatePicker = true
            } label: {
                HStack(spacing: 4) {
                    Text(lineMonth.map { $0.formatted(.dateTime.month(.abbreviated)) }
                         ?? viewModel.selectedDate.formatted(.dateTime.month(.abbreviated).day()))
                        .font(appStyle.font(.headline, weight: .semibold))
                        .foregroundStyle(appStyle.text)
                    Image(systemName: "chevron.down").font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                }
                .frame(minHeight: 44)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Jump to date")
            if !Calendar.current.isDateInToday(viewModel.selectedDate) {
                Button {
                    impactLight.impactOccurred()
                    Task { await viewModel.selectDate(.now) }
                } label: {
                    Text("Today")
                        .font(appStyle.font(.subheadline, weight: .semibold))
                        .foregroundStyle(appStyle.tenant)
                        .frame(minHeight: 44)
                }
                .buttonStyle(.plain)
            }
        }
    }

    /// Crew people and job types in one checklist menu; it stays open while ticking. The icon carries a count.
    @ViewBuilder
    private var filterMenu: some View {
        if viewModel.canSwitchScope || !viewModel.jobTypes.isEmpty {
            Menu {
                if viewModel.canSwitchScope {
                    Section("Whose jobs") {
                        Toggle("Mine", isOn: Binding(
                            get: { viewModel.scope == "mine" },
                            set: { on in if on { Task { await viewModel.setCrews("", scope: "mine") } } }
                        ))
                        Toggle("All crews", isOn: Binding(
                            get: { viewModel.scope == "all" && viewModel.selectedCrews.isEmpty },
                            set: { on in if on { Task { await viewModel.setCrews("", scope: "all") } } }
                        ))
                        ForEach(viewModel.crews) { c in
                            Toggle(c.label, isOn: Binding(
                                get: { viewModel.scope == "all" && viewModel.selectedCrews.contains(c.value) },
                                set: { _ in Task { await viewModel.toggleCrew(c.value) } }
                            ))
                        }
                    }
                }
                if !viewModel.jobTypes.isEmpty {
                    Section("Job types") {
                        Toggle("All job types", isOn: Binding(
                            get: { viewModel.selectedJobTypes.isEmpty },
                            set: { on in if on { Task { await viewModel.setJobType("") } } }
                        ))
                        ForEach(viewModel.jobTypes) { t in
                            Toggle(t.label, isOn: Binding(
                                get: { viewModel.selectedJobTypes.contains(t.value) },
                                set: { _ in Task { await viewModel.toggleJobType(t.value) } }
                            ))
                        }
                    }
                }
            } label: {
                Image(systemName: activeFilterCount > 0 ? "line.3.horizontal.decrease.circle.fill" : "line.3.horizontal.decrease.circle")
                    .font(.system(size: 22))
                    .foregroundStyle(appStyle.tenant)
                    .overlay(alignment: .topTrailing) {
                        if activeFilterCount > 0 {
                            Text("\(activeFilterCount)")
                                .font(appStyle.font(.caption2, weight: .bold))
                                .foregroundStyle(appStyle.onTenant)
                                .padding(.horizontal, 5)
                                .background(Capsule().fill(appStyle.tenant))
                                .offset(x: 8, y: -6)
                        }
                    }
                    .frame(minWidth: 48, minHeight: 48)
                    .contentShape(Rectangle())
            }
            .menuActionDismissBehavior(.disabled)
            .accessibilityLabel(activeFilterCount > 0 ? "Filters, \(activeFilterCount) on" : "Filters")
            .accessibilityHint("Choose crews and job types")
        }
    }

    private var crewFilterOn: Bool { viewModel.canSwitchScope && (viewModel.scope == "all" || !viewModel.selectedCrews.isEmpty) }
    private var activeFilterCount: Int { (crewFilterOn ? 1 : 0) + (viewModel.selectedJobTypes.isEmpty ? 0 : 1) }

    /// Removable chips for what is filtered; nothing at all when the day is unfiltered.
    @ViewBuilder
    private var activeFilterChips: some View {
        if activeFilterCount > 0 {
            HStack(spacing: 8) {
                if crewFilterOn {
                    filterChip(crewFilterLabel) { Task { await viewModel.setCrews("", scope: "mine") } }
                }
                if !viewModel.selectedJobTypes.isEmpty {
                    filterChip(jobTypeFilterLabel) { Task { await viewModel.setJobType("") } }
                }
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 2)
        }
    }

    private func filterChip(_ text: String, clear: @escaping () -> Void) -> some View {
        Button(action: clear) {
            HStack(spacing: 6) {
                Text(text).lineLimit(1)
                Image(systemName: "xmark").font(appStyle.font(.caption2, weight: .bold))
            }
            .font(appStyle.font(.subheadline, weight: .medium))
            .foregroundStyle(appStyle.text)
            .padding(.horizontal, 12)
            .frame(minHeight: 36)
            .background(Capsule().fill(appStyle.sunk))
            .frame(minHeight: 48)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel("Filter \(text). Tap to clear.")
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

}

#Preview {
    ScheduleView()
        .environmentObject(AuthSession())
        .environmentObject(ReadyBriefStore())
}

/// The Add menu's forms that open over the schedule.
private enum AddForm: String, Identifiable {
    case task, quote, invoice
    var id: String { rawValue }
}


/// Back button + address for the visit screen, drawn under the head line now the system bar is hidden.
private struct VisitBackBar: View {
    let title: String
    @Environment(\.dismiss) private var dismiss
    @Environment(\.itAppStyle) private var appStyle

    var body: some View {
        HStack(spacing: 8) {
            Button { dismiss() } label: {
                Image(systemName: "chevron.left")
                    .font(.system(size: 17, weight: .semibold))
                    .frame(width: 44, height: 44)
                    .contentShape(Rectangle())
            }
            .accessibilityLabel("Back")
            Text(title)
                .font(appStyle.font(.headline, weight: .semibold))
                .fixedSize(horizontal: false, vertical: true)
                .accessibilityAddTraits(.isHeader)
            Spacer(minLength: 0)
        }
        .foregroundStyle(appStyle.text)
        .padding(.horizontal, 8)
        .padding(.vertical, 2)
        .background(appStyle.page)
    }
}

/// Keeps the swipe-from-edge back gesture working while the navigation bar is hidden.
private struct PopGestureEnabler: UIViewControllerRepresentable {
    func makeUIViewController(context: Context) -> UIViewController { Holder() }
    func updateUIViewController(_ vc: UIViewController, context: Context) {}
    private final class Holder: UIViewController {
        override func viewDidAppear(_ animated: Bool) {
            super.viewDidAppear(animated)
            navigationController?.interactivePopGestureRecognizer?.isEnabled = true
            navigationController?.interactivePopGestureRecognizer?.delegate = nil
        }
    }
}
