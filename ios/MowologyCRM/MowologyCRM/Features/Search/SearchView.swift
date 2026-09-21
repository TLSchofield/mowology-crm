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

    @StateObject private var vm: SearchViewModel
    private let authSession: AuthSession

    init(authSession: AuthSession) {
        self.authSession = authSession
        _vm = StateObject(wrappedValue: SearchViewModel(authSession: authSession))
    }

    var body: some View {
        NavigationStack {
            List {
                if vm.trimmedQuery.count >= 2 {
                    resultsSection
                } else {
                    emptyState
                }
            }
            .listStyle(.insetGrouped)
            .navigationTitle("Search")
            .searchable(text: $vm.query,
                        placement: .navigationBarDrawer(displayMode: .always),
                        prompt: "Client, address, building, phone…")
            .autocorrectionDisabled()
            .textInputAutocapitalization(.never)
            .onChange(of: vm.query) { _, _ in vm.queryChanged() }
            .navigationDestination(for: SearchProperty.self) { property in
                PropertyDetailView(summary: property, searchVM: vm, authSession: authSession)
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
                    Text("Searching…").foregroundStyle(.secondary)
                }
            }
        } else if vm.searchFailed {
            Section {
                Label("Couldn't search — check your signal and try again.", systemImage: "wifi.exclamationmark")
                    .font(.subheadline)
                    .foregroundStyle(Color.MW.orange)
            }
        } else if vm.hasSearched && vm.results.isEmpty {
            Section {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Nothing found for “\(vm.trimmedQuery)”")
                        .font(.subheadline.weight(.semibold))
                    Text("Try part of the street name, the client's surname, or a phone number.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                .padding(.vertical, 4)
            }
        } else {
            Section {
                ForEach(vm.results) { property in
                    NavigationLink(value: property) { SearchResultRow(property: property) }
                }
            } footer: {
                if !vm.results.isEmpty {
                    Text("Today's stops first, then nearest to you.")
                }
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
                }
            }
        }

        if !vm.recents.isEmpty {
            Section {
                ForEach(vm.recents) { property in
                    NavigationLink(value: property) { SearchResultRow(property: property, showDistance: false) }
                }
            } header: {
                HStack {
                    Text("Recent")
                    Spacer()
                    Button("Clear") { vm.clearRecents() }
                        .font(.caption)
                        .textCase(nil)
                }
            }
        }

        if vm.nearby.isEmpty && vm.recents.isEmpty {
            Section {
                VStack(spacing: 10) {
                    Image(systemName: "magnifyingglass")
                        .font(.largeTitle)
                        .foregroundStyle(Color(.systemGray3))
                    Text("Find a client, address, building or phone number")
                        .font(.subheadline)
                        .multilineTextAlignment(.center)
                        .foregroundStyle(.secondary)
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

    let property: SearchProperty
    var showDistance = true

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 6) {
                    Text(property.label)
                        .font(.body.weight(.semibold))
                        .lineLimit(1)
                    if property.onToday {
                        Text("TODAY")
                            .font(.system(size: 9, weight: .bold))
                            .padding(.horizontal, 5)
                            .padding(.vertical, 2)
                            .background(Color.MW.green)
                            .foregroundStyle(.white)
                            .clipShape(Capsule())
                    }
                }
                if !property.subtitle.isEmpty {
                    Text(property.subtitle)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
                if let client = property.clientLine {
                    Label(client, systemImage: "person.fill")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
                if let lastDone = property.lastDone {
                    Label(lastDone, systemImage: "clock.arrow.circlepath")
                        .font(.caption)
                        .foregroundStyle(.tertiary)
                        .lineLimit(1)
                } else if property.plans.isEmpty {
                    Text("No active plan")
                        .font(.caption)
                        .foregroundStyle(.tertiary)
                }
            }
            Spacer(minLength: 4)
            if showDistance, let distance = property.distanceText {
                Text(distance)
                    .font(.caption.monospacedDigit())
                    .foregroundStyle(.secondary)
            }
        }
        .padding(.vertical, 2)
    }
}

// MARK: - Property page

struct PropertyDetailView: View {

    /// What the result row already knew — shown instantly while the full page loads.
    let summary: SearchProperty
    @ObservedObject var searchVM: SearchViewModel
    let authSession: AuthSession

    @Environment(\.openURL) private var openURL
    @State private var detail: SearchProperty?
    @State private var loadFailed = false
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
            actionsSection

            if let notes = property.notes {
                Section("Site notes") {
                    Text(notes).font(.subheadline)
                }
            }

            plansSection

            if let upcoming = property.upcoming, !upcoming.isEmpty {
                Section("Coming up") {
                    ForEach(upcoming) { visit in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(visit.planTitle).font(.subheadline.weight(.medium))
                                Text(visit.visitNumber).font(.caption).foregroundStyle(.tertiary)
                            }
                            Spacer()
                            Text(Self.friendlyDate(visit.scheduledDate))
                                .font(.caption.monospacedDigit())
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }

            if loadFailed {
                Section {
                    Label("Couldn't load the full details — showing what search returned.", systemImage: "wifi.exclamationmark")
                        .font(.caption)
                        .foregroundStyle(Color.MW.orange)
                }
            }
        }
        .listStyle(.insetGrouped)
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
                    .font(.subheadline.weight(.semibold))
                    .padding(.horizontal, 16)
                    .padding(.vertical, 10)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(Capsule())
                    .padding(.bottom, 18)
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
                Text(property.label).font(.title3.bold())
                Text(property.fullAddress)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                if let client = property.clientLine {
                    Label(client, systemImage: "person.fill")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                HStack(spacing: 10) {
                    if let distance = property.distanceText {
                        Label(distance, systemImage: "location.fill")
                    }
                    if let sqft = property.lawnSqft {
                        Label("\(sqft.formatted()) sq ft lawn", systemImage: "square.dashed")
                    }
                }
                .font(.caption)
                .foregroundStyle(.tertiary)
            }
            .padding(.vertical, 4)
        }
    }

    /// Big tiles, like Start / Skip on the visit screen — these get pressed with gloves on.
    private var actionsSection: some View {
        Section {
            HStack(spacing: 10) {
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

    private func actionTile(_ title: String, icon: String, enabled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 6) {
                Image(systemName: icon).font(.title2)
                Text(title).font(.caption.weight(.semibold)).lineLimit(1).minimumScaleFactor(0.8)
            }
            .frame(maxWidth: .infinity)
            .frame(height: 78)
            .foregroundStyle(enabled ? Color.MW.green : Color(.systemGray3))
            .background(enabled ? Color.MW.green.opacity(0.10) : Color(.systemGray6))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }

    @ViewBuilder
    private var plansSection: some View {
        Section {
            if property.plans.isEmpty {
                Text("No active plan at this property.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            ForEach(property.plans) { plan in
                VStack(alignment: .leading, spacing: 8) {
                    HStack(alignment: .firstTextBaseline) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(plan.title).font(.body.weight(.semibold))
                            if plan.history == nil, let summary = plan.summary {
                                Text(summary).font(.caption).foregroundStyle(.secondary)
                            }
                        }
                        Spacer()
                        Button {
                            confirmingPlan = plan
                        } label: {
                            Label(plan.hasVisitToday ? "Add another" : "Add visit", systemImage: "plus.circle.fill")
                                .font(.caption.weight(.semibold))
                        }
                        .buttonStyle(.bordered)
                        .tint(Color.MW.green)
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
                    .foregroundStyle(Color.MW.green)
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
