//
//  SearchView.swift
//  MowologyCRM
//
//  The Search tab — one box over clients, companies, buildings, addresses, phone numbers and
//  plan / visit numbers. Built for someone standing in a driveway:
//    • before typing it already shows what's Near you and what you opened Recently
//    • results are ranked today's-schedule first, then nearest — not alphabetically
//    • every result is a property you can ACT on: navigate, call, open today's visit, add a visit
//

import SwiftUI
import CoreLocation
import MapKit

// MARK: - ViewModel

@MainActor
final class SearchViewModel: ObservableObject {

    @Published var query = ""
    @Published private(set) var results: [SearchProperty] = []
    @Published private(set) var nearby: [SearchProperty] = []
    @Published private(set) var recents: [SearchProperty] = []
    @Published private(set) var isSearching = false
    @Published private(set) var searchFailed = false
    @Published private(set) var searchErrorDetails: String?
    @Published private(set) var hasSearched = false

    private(set) var fix: CLLocation?
    let apiClient: APIClient
    private let locationManager: LocationManager
    private var searchTask: Task<Void, Never>?

    private static let recentsKey = "mw.search.recents.v1"
    private static let maxRecents = 8

    init(authSession: AuthSession,
         locationManager: LocationManager = GPSTrackingService.shared.locationManager) {
        self.apiClient       = APIClient(authSession: authSession)
        self.locationManager = locationManager
        loadRecents()
    }

    var trimmedQuery: String { query.trimmingCharacters(in: .whitespacesAndNewlines) }

    // MARK: Empty state

    /// Fix + "Near you". Cheap to call on every appearance; silent when location isn't available.
    func refreshNearby() async {
        if let location = try? await locationManager.currentLocation() {
            fix = location
        }
        guard let fix,
              let response: SearchResponse = try? await apiClient.request(
                .fieldSearchNearby(lat: fix.coordinate.latitude, lng: fix.coordinate.longitude)
              ) else { return }
        nearby = response.results ?? []
    }

    // MARK: Search (debounced — typing "balaclava" is one request, not nine)

    func queryChanged() {
        searchTask?.cancel()
        let q = trimmedQuery
        guard q.count >= 2 else {
            results = []; hasSearched = false; searchFailed = false; isSearching = false
            return
        }
        isSearching = true
        searchTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 300_000_000)
            guard !Task.isCancelled else { return }
            await self?.run(q)
        }
    }

    private func run(_ q: String) async {
        do {
            let response: SearchResponse = try await apiClient.request(
                .fieldSearch(query: q, lat: fix?.coordinate.latitude, lng: fix?.coordinate.longitude)
            )
            guard !Task.isCancelled, q == trimmedQuery else { return }
            results      = response.results ?? []
            searchFailed = false
        } catch {
            guard !Task.isCancelled, q == trimmedQuery else { return }
            // Say so — an empty list would read as "no such client".
            results      = []
            searchFailed = true
            searchErrorDetails = ErrorDetails.describe(error)
            AppDiagnostics.shared.record(category: "Search", message: ErrorDetails.describe(error))
        }
        hasSearched = true
        isSearching = false
    }

    // MARK: Recents (on this phone only)

    func remember(_ property: SearchProperty) {
        var list = recents.filter { $0.id != property.id }
        list.insert(property, at: 0)
        recents = Array(list.prefix(Self.maxRecents))
        if let data = try? JSONEncoder().encode(recents) {
            UserDefaults.standard.set(data, forKey: Self.recentsKey)
        }
    }

    func clearRecents() {
        recents = []
        UserDefaults.standard.removeObject(forKey: Self.recentsKey)
    }

    private func loadRecents() {
        guard let data = UserDefaults.standard.data(forKey: Self.recentsKey),
              let saved = try? JSONDecoder().decode([SearchProperty].self, from: data) else { return }
        recents = saved
    }
}

// MARK: - Search tab

struct SearchView: View {

    @Environment(\.itAppStyle) private var appStyle

    @StateObject private var vm: SearchViewModel
    /// The owner's half of the box (OwnerSearchViews.swift): answers, Ask Kaname, every group. Unused for crew.
    @StateObject private var owner: OwnerSearchModel
    @StateObject private var voice = VoiceSearch()
    /// The "Linda ×" chip: at most one client at a time.
    @State private var scopes: [OwnerSearchScope] = []
    private let authSession: AuthSession

    init(authSession: AuthSession) {
        self.authSession = authSession
        _vm = StateObject(wrappedValue: SearchViewModel(authSession: authSession))
        _owner = StateObject(wrappedValue: OwnerSearchModel(authSession: authSession))
    }

    /// Admin/manager search everything; crew keep the fast property-only search (no prices, no money).
    private var isOwner: Bool { authSession.user?.isAdmin == true }
    private var scope: OwnerSearchScope? { isOwner ? scopes.first : nil }
    /// With a chip the box may be empty: "Linda ×" alone lists everything about Linda.
    private var showsResults: Bool { vm.trimmedQuery.count >= 2 || scope != nil }

    var body: some View {
        let _ = RenderRate.tick("SearchView")
        NavigationStack {
            List {
                if let e = voice.error {
                    Section {
                        Label(e, systemImage: "mic.slash")
                            .font(appStyle.font(.caption))
                            .foregroundStyle(appStyle.attention)
                    }
                }
                if showsResults {
                    if isOwner { ownerResults } else { resultsSection }
                } else {
                    emptyState
                }
            }
            .listStyle(.insetGrouped)
            .itAppForm()
            .itAppScreen()
            .foregroundStyle(appStyle.text)
            .navigationTitle("Search")
            // Inline like every other tab: a large title sat under the heads bar (MainTabView's top inset) and was
            // clipped (Tim's screenshot, 2026-10-19).
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) { micButton }
                ToolbarItem(placement: .navigationBarTrailing) { TimeChip() }
            }
            .searchable(text: $vm.query,
                        tokens: $scopes,
                        placement: .navigationBarDrawer(displayMode: .always),
                        prompt: scope != nil ? "Anything about them — “unpaid”, “quotes”…"
                            : (isOwner ? "Search or ask — “has Linda's quote been sent?”" : "Client, address, building, phone…")) { token in
                Label(token.name, systemImage: token.kind == "company" ? "building.2" : "person.fill")
            }
            .autocorrectionDisabled()
            .textInputAutocapitalization(.never)
            .onChange(of: vm.query) { _, _ in
                vm.queryChanged()
                if isOwner { owner.queryChanged(vm.trimmedQuery, scope: scope) }
            }
            .onChange(of: scopes) { _, new in
                if new.count > 1 { scopes = [new[new.count - 1]] }
                if isOwner { owner.queryChanged(vm.trimmedQuery, scope: scopes.first) }
            }
            .onSubmit(of: .search) {
                if isOwner && OwnerSearchModel.looksLikeQuestion(vm.trimmedQuery) {
                    Task { await owner.ask(askText) }
                }
            }
            .onDisappear { voice.stop() }
            .navigationDestination(for: SearchProperty.self) { property in
                PropertyDetailView(summary: property, searchVM: vm, authSession: authSession)
            }
            .navigationDestination(for: OwnerContactRoute.self) { route in
                OwnerContactView(route: route, apiClient: owner.apiClient, searchVM: vm, authSession: authSession)
            }
            .task { await vm.refreshNearby() }
            .refreshable { await vm.refreshNearby() }
        }
    }

    // MARK: Results

    @ViewBuilder
    private var resultsSection: some View {
        if vm.isSearching && vm.results.isEmpty {
            Section {
                HStack(spacing: 10) {
                    ProgressView()
                    Text("Searching…").foregroundStyle(appStyle.meta)
                }
            }
        } else if vm.searchFailed {
            Section {
                Label(vm.searchErrorDetails ?? "Search failed without error details.", systemImage: "wifi.exclamationmark")
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.attention)
            }
        } else if vm.hasSearched && vm.results.isEmpty {
            Section {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Nothing found for “\(vm.trimmedQuery)”")
                        .font(appStyle.font(.subheadline, weight: .semibold))
                    Text("Try part of the street name, the client's surname, or a phone number.")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                }
                .padding(.vertical, 4)
            }
        } else {
            Section {
                ForEach(vm.results) { property in
                    NavigationLink(value: property) { SearchResultRow(property: property) }
                        .listRowBackground(appStyle.surface)
                }
            } footer: {
                if !vm.results.isEmpty {
                    Text("Today's stops first, then nearest to you.")
                }
            }
        }
    }

    /// Big mic: speech fills the box; the answers and groups follow as if typed.
    private var micButton: some View {
        Button {
            voice.toggle { text in vm.query = text }
        } label: {
            Image(systemName: voice.isListening ? "mic.fill" : "mic")
                .font(appStyle.font(.title3, weight: .semibold))
                .symbolEffect(.pulse, isActive: voice.isListening)
                .frame(minWidth: 48, minHeight: 44)
        }
        .tint(voice.isListening ? appStyle.attention : appStyle.tenant)
        .accessibilityLabel(voice.isListening ? "Stop listening" : "Search by voice")
    }

    /// What Ask Kaname is asked: the chip's name joins the words ("Linda Tam: unpaid").
    private var askText: String {
        guard let scope else { return vm.trimmedQuery }
        return vm.trimmedQuery.isEmpty ? scope.name : "\(scope.name): \(vm.trimmedQuery)"
    }

    // MARK: Owner results — answer, Ask Kaname, properties (field search), then every other group

    @ViewBuilder
    private var ownerResults: some View {
        if let answer = owner.answer {
            Section { QuickAnswerCard(answer: answer).listRowBackground(appStyle.surface) }
        }

        if vm.trimmedQuery.count >= 3 {
            Section { AskKanameRow(model: owner, query: askText).listRowBackground(appStyle.surface) }
        }

        if scope == nil && !vm.results.isEmpty {
            Section("Properties") {
                ForEach(vm.results) { property in
                    NavigationLink(value: property) { SearchResultRow(property: property) }
                        .simultaneousGesture(TapGesture().onEnded {
                            owner.pick(query: vm.trimmedQuery, kind: "property", id: property.id)
                        })
                        .listRowBackground(appStyle.surface)
                }
            }
        }

        ForEach(owner.groups) { group in
            Section {
                ForEach(group.items) { item in
                    OwnerSearchItemLink(item: item, onPick: { picked in
                        owner.pick(query: vm.trimmedQuery, kind: picked.kind, id: picked.entityId)
                    }, onNarrow: { chosen in
                        scopes = [chosen]
                        vm.query = ""
                    })
                }
            } header: {
                Text(group.title)
            } footer: {
                if group.more { Text("More found — add a word to narrow it down.") }
            }
        }

        if let missed = owner.missedQuery, missed != vm.trimmedQuery || owner.hasResults || !vm.results.isEmpty {
            Section {
                Label("Pick the right one and “\(missed)” will find it next time.", systemImage: "star")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
            }
        }

        if (vm.isSearching || owner.isSearching) && vm.results.isEmpty && !owner.hasResults {
            Section {
                HStack(spacing: 10) {
                    ProgressView()
                    Text("Searching…").foregroundStyle(appStyle.meta)
                }
            }
        } else if let failed = owner.failed ?? (vm.searchFailed ? vm.searchErrorDetails ?? "Property search failed without error details." : nil) {
            Section {
                Label(failed, systemImage: "wifi.exclamationmark")
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.attention)
            }
        } else if (vm.hasSearched || scope != nil) && !vm.isSearching && !owner.isSearching && (scope != nil || vm.results.isEmpty) && !owner.hasResults {
            Section {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Nothing found for “\(vm.trimmedQuery)”")
                        .font(appStyle.font(.subheadline, weight: .semibold))
                    Text(scope != nil ? "Nothing about \(scope?.name ?? "them") matches that. Remove the word, or the chip." :
                         "Try another word — what you pick next is remembered for “\(vm.trimmedQuery)”. Or ask Kaname above.")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                }
                .padding(.vertical, 4)
            }
        }
    }

    // MARK: Empty state — useful before a single letter is typed

    @ViewBuilder
    private var emptyState: some View {
        if !vm.nearby.isEmpty {
            Section("Near you") {
                ForEach(vm.nearby) { property in
                    NavigationLink(value: property) { SearchResultRow(property: property) }
                        .listRowBackground(appStyle.surface)
                }
            }
        }

        if !vm.recents.isEmpty {
            Section {
                ForEach(vm.recents) { property in
                    NavigationLink(value: property) { SearchResultRow(property: property, showDistance: false) }
                        .listRowBackground(appStyle.surface)
                }
            } header: {
                HStack {
                    Text("Recent")
                    Spacer()
                    Button("Clear") { vm.clearRecents() }
                        .font(appStyle.font(.caption))
                        .textCase(nil)
                        .frame(minWidth: 48, minHeight: 48)
                }
            }
        }

        if vm.nearby.isEmpty && vm.recents.isEmpty {
            Section {
                VStack(spacing: 10) {
                    Image(systemName: "magnifyingglass")
                        .font(appStyle.font(.largeTitle))
                        .foregroundStyle(appStyle.isEnabled ? appStyle.meta : Color(.systemGray3))
                    Text("Find a client, address, building or phone number")
                        .font(appStyle.font(.subheadline))
                        .multilineTextAlignment(.center)
                        .foregroundStyle(appStyle.meta)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 28)
                .listRowBackground(Color.clear)
            }
        }
    }
}

// MARK: - Result row

struct SearchResultRow: View {

    @Environment(\.itAppStyle) private var appStyle

    let property: SearchProperty
    var showDistance = true

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 6) {
                    Text(property.label)
                        .font(appStyle.font(.body, weight: .semibold))
                        .fixedSize(horizontal: false, vertical: true)
                    if property.onToday {
                        Text("TODAY")
                            .font(appStyle.font(size: 9, weight: .bold))
                            .padding(.horizontal, 5)
                            .padding(.vertical, 2)
                            .background(appStyle.tenant)
                            .foregroundStyle(appStyle.onTenant)
                            .clipShape(Capsule())
                    }
                }
                if !property.subtitle.isEmpty {
                    Text(property.subtitle)
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(appStyle.meta)
                        .fixedSize(horizontal: false, vertical: true)
                }
                if let client = property.clientLine {
                    Label(client, systemImage: "person.fill")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                        .fixedSize(horizontal: false, vertical: true)
                }
                if let lastDone = property.lastDone {
                    Label(lastDone, systemImage: "clock.arrow.circlepath")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                        .fixedSize(horizontal: false, vertical: true)
                } else if property.plans.isEmpty {
                    Text("No active plan")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                }
            }
            Spacer(minLength: 4)
            if showDistance, let distance = property.distanceText {
                Text(distance)
                    .font(appStyle.font(.caption).monospacedDigit())
                    .foregroundStyle(appStyle.meta)
            }
        }
        .padding(.vertical, 2)
    }
}

// MARK: - Property page

struct PropertyDetailView: View {

    @Environment(\.itAppStyle) private var appStyle
    /// Room for the meniscus bar under pinned bottom controls (Tim, 2026-11-05).
    @Environment(\.itBarClearance) private var barClearance

    /// What the result row already knew — shown instantly while the full page loads.
    let summary: SearchProperty
    @ObservedObject var searchVM: SearchViewModel
    let authSession: AuthSession

    @Environment(\.openURL) private var openURL
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @State private var detail: SearchProperty?
    @State private var loadFailed = false
    @State private var loadErrorDetails: String?
    @State private var confirmingPlan: SearchPlan?
    @State private var creatingJob = false
    @State private var toast: String?
    @StateObject private var fieldJob: FieldJobViewModel

    init(summary: SearchProperty, searchVM: SearchViewModel, authSession: AuthSession) {
        self.summary     = summary
        self.searchVM    = searchVM
        self.authSession = authSession
        _fieldJob = StateObject(wrappedValue: FieldJobViewModel(authSession: authSession))
    }

    private var property: SearchProperty { detail ?? summary }

    var body: some View {
        List {
            headerSection
                .listRowBackground(appStyle.surface)
            actionsSection

            if let notes = property.notes {
                Section("Site notes") {
                    Text(notes).font(appStyle.font(.subheadline))
                        .listRowBackground(appStyle.surface)
                }
            }

            plansSection
                .listRowBackground(appStyle.surface)

            if let upcoming = property.upcoming, !upcoming.isEmpty {
                Section("Coming up") {
                    ForEach(upcoming) { visit in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(visit.planTitle).font(appStyle.font(.subheadline, weight: .medium))
                                Text(visit.visitNumber).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                            }
                            Spacer()
                            Text(Self.friendlyDate(visit.scheduledDate))
                                .font(appStyle.font(.caption).monospacedDigit())
                                .foregroundStyle(appStyle.meta)
                        }
                        .listRowBackground(appStyle.surface)
                    }
                }
            }

            if loadFailed {
                Section {
                    Label(loadErrorDetails ?? "Property details failed without error details.", systemImage: "wifi.exclamationmark")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.attention)
                }
            }
        }
        .listStyle(.insetGrouped)
        .itAppForm()
        .itAppScreen()
        .foregroundStyle(appStyle.text)
        .navigationTitle(property.label)
        .navigationBarTitleDisplayMode(.inline)
        .task {
            searchVM.remember(summary)
            await load()
        }
        .confirmationDialog(confirmingPlan.map { "Add a visit to “\($0.title)” for today?" } ?? "",
                            isPresented: Binding(get: { confirmingPlan != nil },
                                                 set: { if !$0 { confirmingPlan = nil } }),
                            titleVisibility: .visible) {
            Button("Add today's visit") {
                guard let plan = confirmingPlan else { return }
                Task {
                    let added = await fieldJob.addVisit(to: FieldJobPlan(id: plan.id, title: plan.title,
                                                                         serviceType: plan.serviceType,
                                                                         hasVisitToday: plan.hasVisitToday))
                    if added {
                        toast = fieldJob.successMessage ?? "Added to today's schedule"
                        await load()
                    }
                }
            }
            Button("Cancel", role: .cancel) {}
        }
        .sheet(isPresented: $creatingJob) {
            NavigationStack {
                FieldJobPropertyView(vm: fieldJob, property: property.asFieldJobProperty) {
                    creatingJob = false
                    toast = fieldJob.successMessage ?? "Job created"
                    Task { await load() }
                }
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Cancel") { creatingJob = false }
                    }
                }
            }
        }
        .alert("Couldn't save",
               isPresented: Binding(get: { fieldJob.errorMessage != nil },
                                    set: { if !$0 { fieldJob.errorMessage = nil } })) {
            Button("OK", role: .cancel) {}
        } message: {
            Text(fieldJob.errorMessage ?? "")
        }
        .overlay(alignment: .bottom) {
            if let toast {
                Text(toast)
                    .font(appStyle.font(.subheadline, weight: .semibold))
                    .padding(.horizontal, 16)
                    .padding(.vertical, 10)
                    .background(appStyle.tenant)
                    .foregroundStyle(appStyle.onTenant)
                    .clipShape(Capsule())
                    .padding(.bottom, 18 + barClearance)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .task {
                        try? await Task.sleep(nanoseconds: 2_500_000_000)
                        withAnimation { self.toast = nil }
                    }
            }
        }
        .animation(.easeInOut(duration: 0.2), value: toast)
    }

    // MARK: Sections

    private var headerSection: some View {
        Section {
            VStack(alignment: .leading, spacing: 4) {
                Text(property.label).font(appStyle.font(.title3, weight: .bold))
                Text(property.fullAddress)
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
                if let client = property.clientLine {
                    Label(client, systemImage: "person.fill")
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(appStyle.meta)
                }
                HStack(spacing: 10) {
                    if let distance = property.distanceText {
                        Label(distance, systemImage: "location.fill")
                    }
                    if let sqft = property.lawnSqft {
                        Label("\(sqft.formatted()) sq ft lawn", systemImage: "square.dashed")
                    }
                }
                .font(appStyle.font(.caption))
                .foregroundStyle(appStyle.meta)
            }
            .padding(.vertical, 4)
        }
    }

    /// Big tiles, like Start / Skip on the visit screen — these get pressed with gloves on.
    private var actionsSection: some View {
        Section {
            actionLayout {
                actionTile("Navigate", icon: "arrow.triangle.turn.up.right.diamond.fill", enabled: true) {
                    openInMaps()
                }
                actionTile("Call", icon: "phone.fill", enabled: property.dialURL != nil) {
                    if let url = property.dialURL { openURL(url) }
                }
                actionTile(property.todayVisitId != nil ? "Today's visit" : "Not today",
                           icon: "calendar", enabled: property.todayVisitId != nil) {
                    guard let visitId = property.todayVisitId else { return }
                    // The Schedule tab owns the visit screen; route there like a notification tap.
                    NotificationRouter.shared.pendingRoute = NotificationRoute(visitId: visitId, stopId: nil, date: nil)
                }
            }
            .listRowInsets(EdgeInsets(top: 10, leading: 12, bottom: 10, trailing: 12))
            .listRowBackground(Color.clear)
        }
    }

    private var actionLayout: AnyLayout {
        dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(spacing: 10))
            : AnyLayout(HStackLayout(spacing: 10))
    }

    private func actionTile(_ title: String, icon: String, enabled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 6) {
                Image(systemName: icon).font(appStyle.font(.title2))
                Text(title).font(appStyle.font(.caption, weight: .semibold)).fixedSize(horizontal: false, vertical: true)
            }
            .frame(maxWidth: .infinity)
            .frame(minHeight: 78)
            .padding(.vertical, 8)
            .foregroundStyle(enabled ? appStyle.tenant : (appStyle.isEnabled ? appStyle.meta : Color(.systemGray3)))
            .background(appStyle.isEnabled ? appStyle.surface : (enabled ? appStyle.tenant.opacity(0.10) : Color(.systemGray6)))
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }

    private var planLayout: AnyLayout {
        dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8))
            : AnyLayout(HStackLayout(alignment: .firstTextBaseline))
    }

    @ViewBuilder
    private var plansSection: some View {
        Section {
            if property.plans.isEmpty {
                Text("No active plan at this property.")
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
            }
            ForEach(property.plans) { plan in
                VStack(alignment: .leading, spacing: 8) {
                    planLayout {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(plan.title).font(appStyle.font(.body, weight: .semibold))
                            if plan.history == nil, let summary = plan.summary {
                                Text(summary).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                            }
                        }
                        if !dynamicTypeSize.isAccessibilitySize { Spacer() }
                        Button {
                            confirmingPlan = plan
                        } label: {
                            Label(plan.hasVisitToday ? "Add another" : "Add visit", systemImage: "plus.circle.fill")
                                .font(appStyle.font(.caption, weight: .semibold))
                                .frame(minHeight: 48)
                        }
                        .buttonStyle(.bordered)
                        .tint(appStyle.tenant)
                        .disabled(fieldJob.isSaving)
                    }
                    if let history = plan.history, !history.weeks.isEmpty {
                        ServiceHistoryGrid(history: history)
                    }
                }
                .padding(.vertical, 4)
            }

            Button {
                creatingJob = true
            } label: {
                Label("Create a new job here", systemImage: "plus.square.on.square")
                    .font(appStyle.font(.body))
                    .frame(minHeight: 48)
                    .foregroundStyle(appStyle.tenant)
            }
        } header: {
            Text("Plans")
        }
    }

    // MARK: Helpers

    private func load() async {
        do {
            let response: SearchPropertyResponse = try await searchVM.apiClient.request(
                .fieldSearchProperty(id: summary.id,
                                     lat: searchVM.fix?.coordinate.latitude,
                                     lng: searchVM.fix?.coordinate.longitude)
            )
            if let loaded = response.property {
                detail     = loaded
                loadFailed = false
            }
        } catch {
            loadErrorDetails = ErrorDetails.describe(error)
            AppDiagnostics.shared.record(category: "PropertyDetail", message: ErrorDetails.describe(error))
            loadFailed = detail == nil
        }
    }

    private func openInMaps() {
        if let coordinate = property.coordinate {
            let item = MKMapItem(placemark: MKPlacemark(coordinate: coordinate))
            item.name = property.label
            item.openInMaps(launchOptions: [MKLaunchOptionsDirectionsModeKey: MKLaunchOptionsDirectionsModeDriving])
        } else if let encoded = property.fullAddress.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed),
                  let url = URL(string: "maps://?q=\(encoded)") {
            openURL(url)
        }
    }

    private static func friendlyDate(_ iso: String) -> String {
        let parser = DateFormatter()
        parser.dateFormat = "yyyy-MM-dd"
        parser.locale = Locale(identifier: "en_US_POSIX")
        guard let date = parser.date(from: iso) else { return iso }
        if Calendar.current.isDateInToday(date)    { return "Today" }
        if Calendar.current.isDateInTomorrow(date) { return "Tomorrow" }
        let out = DateFormatter()
        out.dateFormat = "EEE MMM d"
        return out.string(from: date)
    }
}
