//
//  FieldJobView.swift
//  MowologyCRM
//
//  "Add a job / visit on the spot." GPS first: find the property the crew member is standing
//  at, then offer the shortest path —
//    • it has a plan        → add today's visit to that plan (one tap)
//    • it has no plan       → create a job for it
//    • nothing is nearby    → new client + property (pinned here) + job
//  Mirrors the Android/web overlay (field-job.js); both go through FieldJobService.
//

import SwiftUI
import CoreLocation

// MARK: - ViewModel

@MainActor
final class FieldJobViewModel: ObservableObject {

    enum Phase: Equatable {
        case locating
        case ready
        case failed(String)
    }

    @Published var phase: Phase = .locating
    @Published var nearby: [FieldJobProperty] = []
    @Published var serviceTypes: [String] = ["Lawn Cut", "Snow Removal", "Salt Application", "Other"]
    /// Active staff for the "who is on this job" picker, and the signed-in user (pre-ticked).
    @Published var staff: [TeamMember] = []
    @Published var isSaving = false
    @Published var errorMessage: String?
    @Published var successMessage: String?
    /// No signal when the screen opened: nearby clients couldn't be looked up, but a new client and job can still be
    /// made here and wait on the phone (CreateOutbox).
    @Published var isOffline = false
    /// The last-charged offer for the job being added; nil when there is none or it can't be looked up.
    @Published var lastPrice: FieldJobLastPrice?

    /// Best guess at the street address here — pre-fills the new-client form.
    @Published var addressHere = ""
    @Published var cityHere = ""
    @Published var postalHere = ""

    private(set) var fix: CLLocation?
    private let apiClient: APIClient
    private let locationManager: LocationManager
    let currentUserId: Int?

    /// Close enough that "you are AT this property" is a fair reading of the fix.
    static let atPropertyMetres = 60

    init(authSession: AuthSession,
         locationManager: LocationManager = GPSTrackingService.shared.locationManager) {
        self.apiClient       = APIClient(authSession: authSession)
        self.currentUserId   = authSession.user?.id
        self.locationManager = locationManager
    }

    /// The property the crew member is standing at, if the fix makes that clear.
    var propertyHere: FieldJobProperty? {
        guard let first = nearby.first, first.distanceM <= Self.atPropertyMetres else { return nil }
        return first
    }

    func load() async {
        phase = .locating
        errorMessage = nil
        isOffline = false
        do {
            let location = try await locationManager.currentLocation()
            fix = location
            async let lookup: Void = reverseGeocode(location)
            let response: FieldJobNearbyResponse = try await apiClient.request(
                .fieldJobNearby(lat: location.coordinate.latitude, lng: location.coordinate.longitude)
            )
            nearby = response.results ?? []
            if let types = response.serviceTypes, !types.isEmpty { serviceTypes = types }
            if let list = response.staff { staff = list }
            _ = await lookup
            phase = .ready
        } catch let err as APIError {
            if case .networkError = err, fix != nil {
                // GPS works without signal; only the lookup failed. Carry on with "new client here".
                nearby = []
                isOffline = true
                phase = .ready
                return
            }
            phase = .failed(err.localizedDescription)
        } catch {
            AppDiagnostics.shared.record(category: "FieldJobView", message: ErrorDetails.describe(error))
            phase = .failed(ErrorDetails.describe(error))
        }
    }

    /// For screens reached without the GPS lookup (the Search tab's property page).
    func loadStaffIfNeeded() async {
        guard staff.isEmpty else { return }
        do {
            let response: FieldJobNearbyResponse = try await apiClient.request(.fieldJobNearby(lat: 0, lng: 0))
            if let list = response.staff { staff = list }
            if let types = response.serviceTypes, !types.isEmpty { serviceTypes = types }
        } catch {
            // Non-fatal — the picker falls back to "You" until reopened.
        }
    }

    private func reverseGeocode(_ location: CLLocation) async {
        guard let place = try? await CLGeocoder().reverseGeocodeLocation(location).first else { return }
        let street = [place.subThoroughfare, place.thoroughfare].compactMap { $0 }.joined(separator: " ")
        if !street.isEmpty { addressHere = street }
        cityHere   = place.locality ?? ""
        postalHere = place.postalCode ?? ""
    }

    // MARK: - Actions

    func loadLastPrice(propertyId: Int, serviceType: String) async {
        lastPrice = nil
        guard !serviceType.isEmpty else { return }
        let found: FieldJobLastPrice? = try? await apiClient.request(.fieldJobLastPrice(propertyId: propertyId, serviceType: serviceType))
        guard !Task.isCancelled, found?.found == true else { return }
        lastPrice = found
    }

    func addVisit(to plan: FieldJobPlan) async -> Bool {
        await send(["action": "add_visit", "plan_id": plan.id], title: "Visit added to \(plan.title)") { response in
            "Added to today's schedule" + (response.visitNumber.map { " — \($0)" } ?? "")
        }
    }

    func createJob(propertyId: Int, job: FieldJobDraft) async -> Bool {
        var body = job.payload
        body["action"]      = "create_job"
        body["property_id"] = propertyId
        return await send(body, title: "New \(job.serviceType) job") { response in
            "Job created" + (response.planNumber.map { " — \($0)" } ?? "")
        }
    }

    func createClientJob(client: FieldClientDraft, job: FieldJobDraft) async -> Bool {
        var body = job.payload
        body["action"]               = "create_client_job"
        body["first_name"]           = client.firstName.trimmingCharacters(in: .whitespaces)
        body["last_name"]            = client.lastName.trimmingCharacters(in: .whitespaces)
        body["phone"]                = client.phone.trimmingCharacters(in: .whitespaces)
        body["property_address"]     = client.address.trimmingCharacters(in: .whitespaces)
        body["property_city"]        = client.city.trimmingCharacters(in: .whitespaces)
        body["property_postal_code"] = client.postalCode.trimmingCharacters(in: .whitespaces)
        if let fix {
            body["lat"] = fix.coordinate.latitude
            body["lng"] = fix.coordinate.longitude
        }
        let who = [client.firstName, client.lastName].map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }.joined(separator: " ")
        return await send(body, title: "New client \(who) and job") { response in
            "Client and job created" + (response.planNumber.map { " — \($0)" } ?? "")
        }
    }

    /// A prospect: someone met on site who has no job yet. Saved as a client the office can quote later.
    func createProspect(client: FieldClientDraft) async -> Bool {
        var body: [String: Any] = [:]
        body["action"]               = "create_client"
        body["prospect"]             = 1
        body["first_name"]           = client.firstName.trimmingCharacters(in: .whitespaces)
        body["last_name"]            = client.lastName.trimmingCharacters(in: .whitespaces)
        body["phone"]                = client.phone.trimmingCharacters(in: .whitespaces)
        body["email"]                = client.email.trimmingCharacters(in: .whitespaces)
        body["prospect_notes"]       = client.wants.trimmingCharacters(in: .whitespacesAndNewlines)
        body["property_address"]     = client.address.trimmingCharacters(in: .whitespaces)
        body["property_city"]        = client.city.trimmingCharacters(in: .whitespaces)
        body["property_postal_code"] = client.postalCode.trimmingCharacters(in: .whitespaces)
        if let fix {
            body["lat"] = fix.coordinate.latitude
            body["lng"] = fix.coordinate.longitude
        }
        let who = [client.firstName, client.lastName].map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }.joined(separator: " ")
        return await send(body, title: "New prospect \(who)") { _ in "Prospect saved" }
    }

    /// One id per attempt: a retry after a dropped response must not create a second job.
    private var requestId = UUID().uuidString

    private func send(_ body: [String: Any], title: String, success: (FieldJobActionResponse) -> String) async -> Bool {
        isSaving     = true
        errorMessage = nil
        defer { isSaving = false }

        var body = body
        body["client_request_id"] = requestId

        do {
            let response: FieldJobActionResponse = try await apiClient.request(.fieldJobAction, body: body)
            guard response.success else {
                errorMessage = response.error ?? "Field job response success=false; server returned no error field."
                return false
            }
            requestId      = UUID().uuidString
            successMessage = success(response)
            return true
        } catch let err as APIError {
            if case .networkError = err {
                // No signal (or a busy server): keep it on the phone with the same client_request_id; it goes when
                // the phone is back online, and the server dedupes if this attempt did in fact land.
                if CreateOutbox.shared.enqueue(kind: .fieldJob, userId: apiClient.currentUserId, title: title, body: body) {
                    requestId      = UUID().uuidString
                    successMessage = "No signal — saved on this phone. It's added when you're back online."
                    // A timeout or a busy server fires no reconnect, so try again now rather than at the next open.
                    Task { await CreateOutbox.shared.drain(using: apiClient) }
                    return true
                }
                errorMessage = "No connection, and it couldn't be kept on this phone either. Nothing was saved."
            } else {
                errorMessage = err.localizedDescription
            }
            return false
        } catch {
            AppDiagnostics.shared.record(category: "FieldJobView", message: ErrorDetails.describe(error))
            errorMessage = ErrorDetails.describe(error)
            return false
        }
    }
}

// MARK: - Drafts

struct FieldJobDraft {
    var serviceType = ""
    var title       = ""
    var price       = ""
    var notes       = ""
    var recurring   = false
    var frequency   = "weekly"
    var date        = Date()
    /// Who is on the job. Empty = just me (the server's default).
    var crewIds: [Int] = []

    var isValid: Bool { !serviceType.isEmpty }

    /// The server wants YYYY-MM-DD in the crew's own day, not UTC.
    static let dayFormatter: DateFormatter = {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()

    var payload: [String: Any] {
        var body: [String: Any] = [
            "service_type": serviceType,
            "title":        title.trimmingCharacters(in: .whitespaces),
            "notes":        notes.trimmingCharacters(in: .whitespaces),
            "recurring":    recurring ? 1 : 0,
        ]
        if recurring { body["frequency"] = frequency }
        body["date"] = FieldJobDraft.dayFormatter.string(from: date)
        if !crewIds.isEmpty { body["crew_ids"] = crewIds }
        let cleaned = price.replacingOccurrences(of: "$", with: "").trimmingCharacters(in: .whitespaces)
        if !cleaned.isEmpty { body["price"] = cleaned }
        return body
    }
}

struct FieldClientDraft {
    var firstName  = ""
    var lastName   = ""
    var phone      = ""
    var address    = ""
    var city       = ""
    var postalCode = ""
    /// Prospect only: optional email and what they asked for.
    var email      = ""
    var wants      = ""

    var isValid: Bool {
        !firstName.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty &&
        !address.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    /// A prospect has no job to bring the office back to them, so they need a way to be reached.
    var hasContactMethod: Bool {
        !phone.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ||
        email.contains("@")
    }
}

// MARK: - Root sheet

struct FieldJobView: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    @Environment(\.dismiss) private var dismiss
    @StateObject private var vm: FieldJobViewModel

    /// Called after something was created, so the schedule can reload.
    let onCreated: () -> Void
    /// Opened from Client in the bar's Add dial: go straight to "New client at this location".
    let startWithNewClient: Bool

    init(authSession: AuthSession, startWithNewClient: Bool = false, onCreated: @escaping () -> Void) {
        _vm = StateObject(wrappedValue: FieldJobViewModel(authSession: authSession))
        self.startWithNewClient = startWithNewClient
        self.onCreated = onCreated
    }

    var body: some View {
        NavigationStack {
            Group {
                switch vm.phase {
                case .locating:
                    VStack(spacing: 14) {
                        ProgressView().tint(appStyle.tenant)
                        Text("Finding where you are…")
                            .font(appStyle.font(.subheadline))
                            .foregroundStyle(appStyle.meta)
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)

                case .failed(let message):
                    VStack(spacing: 14) {
                        Image(systemName: "location.slash.fill")
                            .font(appStyle.font(.largeTitle))
                            .foregroundStyle(appStyle.attention)
                        Text(message)
                            .font(appStyle.font(.subheadline))
                            .multilineTextAlignment(.center)
                            .foregroundStyle(appStyle.meta)
                        Button("Try again") { Task { await vm.load() } }
                            .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                            .buttonStyle(.borderedProminent)
                            .tint(appStyle.tenant)
                    }
                    .padding(32)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)

                case .ready:
                    if startWithNewClient {
                        FieldJobNewClientView(vm: vm, finish: finish)
                    } else {
                        nearbyList
                    }
                }
            }
            .background(appStyle.page)
            .itAppForm()
            .itAppScreen()
            .navigationTitle("Add a job")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
            .navigationDestination(for: FieldJobProperty.self) { property in
                FieldJobPropertyView(vm: vm, property: property, finish: finish)
            }
        }
        .task { await vm.load() }
        .alert("Couldn't save",
               isPresented: Binding(get: { vm.errorMessage != nil },
                                    set: { if !$0 { vm.errorMessage = nil } })) {
            Button("OK", role: .cancel) {}
        } message: {
            Text(vm.errorMessage ?? "")
        }
    }

    private func finish() {
        onCreated()
        dismiss()
    }

    // MARK: Nearby list

    private var nearbyList: some View {
        List {
            if let here = vm.propertyHere {
                Section {
                    NavigationLink(value: here) {
                        VStack(alignment: .leading, spacing: 4) {
                            Text("You're at")
                                .font(appStyle.font(.caption, weight: .semibold))
                                .foregroundStyle(appStyle.tenant)
                                .textCase(.uppercase)
                            propertyLabel(here)
                        }
                        .padding(.vertical, 4)
                    }
                } footer: {
                    Text(here.plans.isEmpty
                         ? "No active plan here — you can create a job."
                         : "Add a visit to one of its plans, or create a new job.")
                }
            }

            let others = vm.nearby.filter { $0.id != vm.propertyHere?.id }
            if !others.isEmpty {
                Section(vm.propertyHere == nil ? "Nearby properties" : "Also nearby") {
                    ForEach(others) { property in
                        NavigationLink(value: property) { propertyLabel(property) }
                    }
                }
            }

            if vm.isOffline {
                Section {
                    Text("No signal, so nearby clients can't be looked up. A new client and job made here is saved on this phone and sent when you're back online.")
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(appStyle.meta)
                }
            } else if vm.nearby.isEmpty {
                Section {
                    Text("No client properties within 250 m of you.")
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(appStyle.meta)
                }
            }

            Section {
                NavigationLink {
                    FieldJobNewClientView(vm: vm, finish: finish)
                } label: {
                    Label("New client at this location", systemImage: "person.crop.circle.badge.plus")
                        .foregroundStyle(appStyle.tenant)
                }
            } footer: {
                Text("Creates the client, pins the property where you're standing, and adds the job. The office reviews it afterwards.")
            }
        }
    }

    private func propertyLabel(_ property: FieldJobProperty) -> some View {
        AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout(alignment: .firstTextBaseline))) {
            VStack(alignment: .leading, spacing: 2) {
                Text(property.contactName ?? property.address)
                    .font(appStyle.font(.body, weight: .semibold))
                    .lineLimit(appStyle.isEnabled ? nil : 1)
                Text(property.contactName == nil ? (property.city ?? "") : property.address)
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
                    .lineLimit(appStyle.isEnabled ? nil : 1)
                Text(property.plans.isEmpty
                     ? "No active plan"
                     : property.plans.count == 1 ? property.plans[0].title : "\(property.plans.count) active plans")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
                    .lineLimit(appStyle.isEnabled ? nil : 1)
            }
            Spacer()
            Text(property.distanceText)
                .font(appStyle.font(.caption).monospacedDigit())
                .foregroundStyle(appStyle.meta)
        }
    }
}

// MARK: - One property: add a visit to a plan, or create a job

/// Also pushed from the Search tab's property page ("Create a new job here").
struct FieldJobPropertyView: View {
    @Environment(\.itAppStyle) private var appStyle

    @ObservedObject var vm: FieldJobViewModel
    let property: FieldJobProperty
    let finish: () -> Void

    @State private var draft = FieldJobDraft()
    @State private var confirmingPlan: FieldJobPlan?

    var body: some View {
        Form {
            Section {
                VStack(alignment: .leading, spacing: 2) {
                    if let name = property.contactName {
                        Text(name).font(appStyle.font(.headline))
                    }
                    Text([property.address, property.city].compactMap { $0 }.joined(separator: ", "))
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(appStyle.meta)
                }
            }

            if !property.plans.isEmpty {
                Section {
                    ForEach(property.plans) { plan in
                        Button {
                            confirmingPlan = plan
                        } label: {
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(plan.title)
                                        .font(appStyle.font(.body, weight: .semibold))
                                        .foregroundStyle(appStyle.text)
                                    Text(plan.hasVisitToday
                                         ? "Already has a visit today — add another"
                                         : "Add a visit for today")
                                        .font(appStyle.font(.caption))
                                        .foregroundStyle(plan.hasVisitToday ? appStyle.attention : appStyle.meta)
                                }
                                Spacer()
                                Image(systemName: "plus.circle.fill")
                                    .font(appStyle.font(.title2))
                                    .foregroundStyle(appStyle.tenant)
                            }
                            .padding(.vertical, 4)
                        }
                        .disabled(vm.isSaving)
                    }
                } header: {
                    Text("Add a visit to a plan")
                } footer: {
                    Text("The visit goes on today's schedule, assigned to you.")
                }
            }

            FieldJobFormSections(draft: $draft, serviceTypes: vm.serviceTypes,
                                 header: property.plans.isEmpty ? "Create a job here" : "Or create a new job",
                                 staff: vm.staff, currentUserId: vm.currentUserId,
                                 lastPrice: vm.lastPrice)

            Section {
                Button {
                    Task { if await vm.createJob(propertyId: property.id, job: draft) { finish() } }
                } label: {
                    saveLabel("Create job", saving: vm.isSaving, appStyle: appStyle)
                }
                .disabled(!draft.isValid || vm.isSaving)
                .listRowBackground(draft.isValid ? appStyle.tenant : (appStyle.isEnabled ? appStyle.sunk : Color(.systemGray4)))
            }
        }
        .itAppForm()
        .itAppScreen()
        .task(id: draft.serviceType) { await vm.loadLastPrice(propertyId: property.id, serviceType: draft.serviceType) }
        .onDisappear { vm.lastPrice = nil }
        .navigationTitle("This property")
        .navigationBarTitleDisplayMode(.inline)
        .task { await vm.loadStaffIfNeeded() }
        .confirmationDialog(confirmingPlan.map { "Add a visit to “\($0.title)” for today?" } ?? "",
                            isPresented: Binding(get: { confirmingPlan != nil },
                                                 set: { if !$0 { confirmingPlan = nil } }),
                            titleVisibility: .visible) {
            Button("Add today's visit") {
                guard let plan = confirmingPlan else { return }
                Task { if await vm.addVisit(to: plan) { finish() } }
            }
            Button("Cancel", role: .cancel) {}
        }
    }
}

// MARK: - New client + job

private struct FieldJobNewClientView: View {
    @Environment(\.itAppStyle) private var appStyle

    @ObservedObject var vm: FieldJobViewModel
    let finish: () -> Void

    @State private var client = FieldClientDraft()
    @State private var draft  = FieldJobDraft()
    @State private var prefilled = false
    /// "Prospect only": save the person and place with no job.
    @State private var prospectOnly = false

    private var canSave: Bool { client.isValid && (prospectOnly ? client.hasContactMethod : draft.isValid) }

    var body: some View {
        Form {
            Section {
                Picker("Save as", selection: $prospectOnly) {
                    Text("Client + job").tag(false)
                    Text("Prospect only").tag(true)
                }
                .pickerStyle(.segmented)
            } footer: {
                if prospectOnly {
                    Text("Saves their details and the place, with no job. Add a phone or email so the office can reach them.")
                }
            }

            Section("Client") {
                TextField("First name", text: $client.firstName)
                    .textContentType(.givenName)
                TextField("Last name", text: $client.lastName)
                    .textContentType(.familyName)
                TextField("Phone", text: $client.phone)
                    .keyboardType(.phonePad)
                    .textContentType(.telephoneNumber)
                if prospectOnly {
                    TextField("Email (optional)", text: $client.email)
                        .keyboardType(.emailAddress)
                        .textContentType(.emailAddress)
                        .textInputAutocapitalization(.never)
                    TextField("What they want (optional)", text: $client.wants, axis: .vertical)
                }
            }

            Section {
                TextField("Street address", text: $client.address)
                    .textContentType(.fullStreetAddress)
                TextField("City", text: $client.city)
                TextField("Postal code", text: $client.postalCode)
                    .textInputAutocapitalization(.characters)
            } header: {
                Text("Property")
            } footer: {
                Text("Filled in from where you're standing — check the street number. The map pin is set to your current location.")
            }

            if !prospectOnly {
                FieldJobFormSections(draft: $draft, serviceTypes: vm.serviceTypes, header: "Job",
                                     staff: vm.staff, currentUserId: vm.currentUserId)
            }

            Section {
                Button {
                    Task {
                        let ok = prospectOnly
                            ? await vm.createProspect(client: client)
                            : await vm.createClientJob(client: client, job: draft)
                        if ok { finish() }
                    }
                } label: {
                    saveLabel(prospectOnly ? "Save prospect" : "Create client and job", saving: vm.isSaving, appStyle: appStyle)
                }
                .disabled(!canSave || vm.isSaving)
                .listRowBackground(canSave ? appStyle.tenant : (appStyle.isEnabled ? appStyle.sunk : Color(.systemGray4)))
            }
        }
        .itAppForm()
        .itAppScreen()
        .navigationTitle("New client")
        .navigationBarTitleDisplayMode(.inline)
        .task { await vm.loadStaffIfNeeded() }
        .onAppear {
            guard !prefilled else { return }
            prefilled = true
            client.address    = vm.addressHere
            client.city       = vm.cityHere.isEmpty ? "Vancouver" : vm.cityHere
            client.postalCode = vm.postalHere
        }
    }
}

// MARK: - Shared job form

private struct FieldJobFormSections: View {
    @Environment(\.itAppStyle) private var appStyle

    @Binding var draft: FieldJobDraft
    let serviceTypes: [String]
    let header: String
    let staff: [TeamMember]
    let currentUserId: Int?
    var lastPrice: FieldJobLastPrice? = nil

    private var staffSummary: String {
        let ids = draft.crewIds.isEmpty ? [currentUserId].compactMap { $0 } : draft.crewIds
        let names = ids.map { id in id == currentUserId ? "You" : (staff.first { $0.id == id }?.name ?? "Staff") }
        return names.isEmpty ? "You" : names.joined(separator: ", ")
    }

    private var dateText: String {
        draft.date.formatted(.dateTime.weekday(.abbreviated).day().month(.abbreviated))
    }

    var body: some View {
        Section(header) {
            Picker("Service", selection: $draft.serviceType) {
                Text("Choose…").tag("")
                ForEach(serviceTypes, id: \.self) { Text($0).tag($0) }
            }
            TextField("Title (optional)", text: $draft.title)
            TextField("Price per visit, before GST (optional)", text: $draft.price)
                .keyboardType(.decimalPad)
            if let price = lastPrice?.price {
                Button {
                    draft.price = String(format: "%.2f", price)
                } label: {
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Last charged $" + String(format: "%.2f", price) + (lastPrice?.when.map { ", \($0)" } ?? ""))
                                .font(appStyle.font(.subheadline, weight: .semibold))
                            if let label = lastPrice?.label {
                                Text(label).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta).lineLimit(2)
                            }
                        }
                        Spacer()
                        Text("Use").font(appStyle.font(.subheadline, weight: .semibold)).foregroundStyle(appStyle.tenant)
                    }
                }
                .buttonStyle(.plain)
            }
            TextField("Notes for the office (optional)", text: $draft.notes, axis: .vertical)
                .lineLimit(2...4)
        }

        Section {
            DatePicker(draft.recurring ? "Starts" : "Date", selection: $draft.date, displayedComponents: .date)
            NavigationLink {
                FieldJobStaffPicker(staff: staff, currentUserId: currentUserId, selected: $draft.crewIds)
            } label: {
                HStack {
                    Text("Staff")
                    Spacer()
                    Text(staffSummary)
                        .foregroundStyle(appStyle.meta)
                        .lineLimit(1)
                }
            }
            Toggle("Repeats", isOn: $draft.recurring)
                .tint(appStyle.tenant)
            if draft.recurring {
                Picker("Every", selection: $draft.frequency) {
                    Text("Week").tag("weekly")
                    Text("2 weeks").tag("biweekly")
                    Text("Month").tag("monthly")
                }
                .pickerStyle(.segmented)
            }
        } footer: {
            Text(draft.recurring
                 ? "Starts \(dateText) and repeats on this weekday."
                 : "A one-off job on \(dateText).")
        }
    }
}

// MARK: - Staff picker

private struct FieldJobStaffPicker: View {
    @Environment(\.itAppStyle) private var appStyle

    let staff: [TeamMember]
    let currentUserId: Int?
    @Binding var selected: [Int]

    /// Nothing picked yet means "just me" — show that as ticked.
    private var effective: [Int] { selected.isEmpty ? [currentUserId].compactMap { $0 } : selected }

    var body: some View {
        List {
            Section {
                ForEach(staff) { member in
                    Button {
                        toggle(member.id)
                    } label: {
                        HStack {
                            Text(member.id == currentUserId ? "\(member.name) (you)" : member.name)
                                .foregroundStyle(appStyle.text)
                            Spacer()
                            if effective.contains(member.id) {
                                Image(systemName: "checkmark")
                                    .foregroundStyle(appStyle.tenant)
                            }
                        }
                        .frame(minHeight: appStyle.isEnabled ? 44 : nil)
                    }
                }
            } footer: {
                Text("The job goes on each ticked person's schedule. At least one person stays on it.")
            }
        }
        .itAppForm()
        .itAppScreen()
        .navigationTitle("Staff")
        .navigationBarTitleDisplayMode(.inline)
    }

    private func toggle(_ id: Int) {
        var ids = effective
        if let i = ids.firstIndex(of: id) {
            guard ids.count > 1 else { return }   // never leave a job with nobody on it
            ids.remove(at: i)
        } else {
            ids.append(id)
        }
        selected = ids
    }
}

private func saveLabel(_ title: String, saving: Bool, appStyle: ItAppStyle) -> some View {
    HStack {
        Spacer()
        if saving { ProgressView().tint(appStyle.onTenant) }
        Text(saving ? "Saving…" : title)
            .font(appStyle.font(.headline))
            .foregroundStyle(appStyle.onTenant)
        Spacer()
    }
    .frame(minHeight: appStyle.isEnabled ? 48 : nil)
    .padding(.vertical, 6)
}
