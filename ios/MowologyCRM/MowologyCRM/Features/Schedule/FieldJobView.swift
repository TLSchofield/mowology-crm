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
    @Published var isSaving = false
    @Published var errorMessage: String?
    @Published var successMessage: String?

    /// Best guess at the street address here — pre-fills the new-client form.
    @Published var addressHere = ""
    @Published var cityHere = ""
    @Published var postalHere = ""

    private(set) var fix: CLLocation?
    private let apiClient: APIClient
    private let locationManager: LocationManager

    /// Close enough that "you are AT this property" is a fair reading of the fix.
    static let atPropertyMetres = 60

    init(authSession: AuthSession,
         locationManager: LocationManager = GPSTrackingService.shared.locationManager) {
        self.apiClient       = APIClient(authSession: authSession)
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
        do {
            let location = try await locationManager.currentLocation()
            fix = location
            async let lookup: Void = reverseGeocode(location)
            let response: FieldJobNearbyResponse = try await apiClient.request(
                .fieldJobNearby(lat: location.coordinate.latitude, lng: location.coordinate.longitude)
            )
            nearby = response.results ?? []
            if let types = response.serviceTypes, !types.isEmpty { serviceTypes = types }
            _ = await lookup
            phase = .ready
        } catch let err as APIError {
            phase = .failed(err.localizedDescription)
        } catch {
            phase = .failed("Couldn't get your location. Check that location access is on, then try again.")
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

    func addVisit(to plan: FieldJobPlan) async -> Bool {
        await send(["action": "add_visit", "plan_id": plan.id]) { response in
            "Added to today's schedule" + (response.visitNumber.map { " — \($0)" } ?? "")
        }
    }

    func createJob(propertyId: Int, job: FieldJobDraft) async -> Bool {
        var body = job.payload
        body["action"]      = "create_job"
        body["property_id"] = propertyId
        return await send(body) { response in
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
        return await send(body) { response in
            "Client and job created" + (response.planNumber.map { " — \($0)" } ?? "")
        }
    }

    /// One id per attempt: a retry after a dropped response must not create a second job.
    private var requestId = UUID().uuidString

    private func send(_ body: [String: Any], success: (FieldJobActionResponse) -> String) async -> Bool {
        isSaving     = true
        errorMessage = nil
        defer { isSaving = false }

        var body = body
        body["client_request_id"] = requestId

        do {
            let response: FieldJobActionResponse = try await apiClient.request(.fieldJobAction, body: body)
            guard response.success else {
                errorMessage = response.error ?? "Couldn't save. Try again."
                return false
            }
            requestId      = UUID().uuidString
            successMessage = success(response)
            return true
        } catch let err as APIError {
            if case .networkError = err {
                errorMessage = "No connection — nothing was saved. Try again when you have signal."
            } else {
                errorMessage = err.localizedDescription
            }
            return false
        } catch {
            errorMessage = "Couldn't save. Try again."
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

    var isValid: Bool { !serviceType.isEmpty }

    var payload: [String: Any] {
        var body: [String: Any] = [
            "service_type": serviceType,
            "title":        title.trimmingCharacters(in: .whitespaces),
            "notes":        notes.trimmingCharacters(in: .whitespaces),
            "recurring":    recurring ? 1 : 0,
        ]
        if recurring { body["frequency"] = frequency }
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

    var isValid: Bool {
        !firstName.trimmingCharacters(in: .whitespaces).isEmpty &&
        !address.trimmingCharacters(in: .whitespaces).isEmpty
    }
}

// MARK: - Root sheet

struct FieldJobView: View {

    @Environment(\.dismiss) private var dismiss
    @StateObject private var vm: FieldJobViewModel

    /// Called after something was created, so the schedule can reload.
    let onCreated: () -> Void

    init(authSession: AuthSession, onCreated: @escaping () -> Void) {
        _vm = StateObject(wrappedValue: FieldJobViewModel(authSession: authSession))
        self.onCreated = onCreated
    }

    var body: some View {
        NavigationStack {
            Group {
                switch vm.phase {
                case .locating:
                    VStack(spacing: 14) {
                        ProgressView().tint(Color.MW.green)
                        Text("Finding where you are…")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)

                case .failed(let message):
                    VStack(spacing: 14) {
                        Image(systemName: "location.slash.fill")
                            .font(.largeTitle)
                            .foregroundStyle(Color.MW.orange)
                        Text(message)
                            .font(.subheadline)
                            .multilineTextAlignment(.center)
                            .foregroundStyle(.secondary)
                        Button("Try again") { Task { await vm.load() } }
                            .buttonStyle(.borderedProminent)
                            .tint(Color.MW.green)
                    }
                    .padding(32)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)

                case .ready:
                    nearbyList
                }
            }
            .background(Color(.systemGroupedBackground))
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
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(Color.MW.green)
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

            if vm.nearby.isEmpty {
                Section {
                    Text("No client properties within 250 m of you.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
            }

            Section {
                NavigationLink {
                    FieldJobNewClientView(vm: vm, finish: finish)
                } label: {
                    Label("New client at this location", systemImage: "person.crop.circle.badge.plus")
                        .foregroundStyle(Color.MW.green)
                }
            } footer: {
                Text("Creates the client, pins the property where you're standing, and adds the job. The office reviews it afterwards.")
            }
        }
    }

    private func propertyLabel(_ property: FieldJobProperty) -> some View {
        HStack(alignment: .firstTextBaseline) {
            VStack(alignment: .leading, spacing: 2) {
                Text(property.contactName ?? property.address)
                    .font(.body.weight(.semibold))
                    .lineLimit(1)
                Text(property.contactName == nil ? (property.city ?? "") : property.address)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
                Text(property.plans.isEmpty
                     ? "No active plan"
                     : property.plans.count == 1 ? property.plans[0].title : "\(property.plans.count) active plans")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
                    .lineLimit(1)
            }
            Spacer()
            Text(property.distanceText)
                .font(.caption.monospacedDigit())
                .foregroundStyle(.secondary)
        }
    }
}

// MARK: - One property: add a visit to a plan, or create a job

private struct FieldJobPropertyView: View {

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
                        Text(name).font(.headline)
                    }
                    Text([property.address, property.city].compactMap { $0 }.joined(separator: ", "))
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
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
                                        .font(.body.weight(.semibold))
                                        .foregroundStyle(.primary)
                                    Text(plan.hasVisitToday
                                         ? "Already has a visit today — add another"
                                         : "Add a visit for today")
                                        .font(.caption)
                                        .foregroundStyle(plan.hasVisitToday ? Color.MW.orange : .secondary)
                                }
                                Spacer()
                                Image(systemName: "plus.circle.fill")
                                    .font(.title2)
                                    .foregroundStyle(Color.MW.green)
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
                                 header: property.plans.isEmpty ? "Create a job here" : "Or create a new job")

            Section {
                Button {
                    Task { if await vm.createJob(propertyId: property.id, job: draft) { finish() } }
                } label: {
                    saveLabel("Create job", saving: vm.isSaving)
                }
                .disabled(!draft.isValid || vm.isSaving)
                .listRowBackground(draft.isValid ? Color.MW.green : Color(.systemGray4))
            }
        }
        .navigationTitle("This property")
        .navigationBarTitleDisplayMode(.inline)
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

    @ObservedObject var vm: FieldJobViewModel
    let finish: () -> Void

    @State private var client = FieldClientDraft()
    @State private var draft  = FieldJobDraft()
    @State private var prefilled = false

    var body: some View {
        Form {
            Section("Client") {
                TextField("First name", text: $client.firstName)
                    .textContentType(.givenName)
                TextField("Last name", text: $client.lastName)
                    .textContentType(.familyName)
                TextField("Phone", text: $client.phone)
                    .keyboardType(.phonePad)
                    .textContentType(.telephoneNumber)
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

            FieldJobFormSections(draft: $draft, serviceTypes: vm.serviceTypes, header: "Job")

            Section {
                Button {
                    Task { if await vm.createClientJob(client: client, job: draft) { finish() } }
                } label: {
                    saveLabel("Create client and job", saving: vm.isSaving)
                }
                .disabled(!(client.isValid && draft.isValid) || vm.isSaving)
                .listRowBackground(client.isValid && draft.isValid ? Color.MW.green : Color(.systemGray4))
            }
        }
        .navigationTitle("New client")
        .navigationBarTitleDisplayMode(.inline)
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

    @Binding var draft: FieldJobDraft
    let serviceTypes: [String]
    let header: String

    var body: some View {
        Section(header) {
            Picker("Service", selection: $draft.serviceType) {
                Text("Choose…").tag("")
                ForEach(serviceTypes, id: \.self) { Text($0).tag($0) }
            }
            TextField("Title (optional)", text: $draft.title)
            TextField("Price per visit, before GST (optional)", text: $draft.price)
                .keyboardType(.decimalPad)
            TextField("Notes for the office (optional)", text: $draft.notes, axis: .vertical)
                .lineLimit(2...4)
        }

        Section {
            Toggle("Repeats", isOn: $draft.recurring)
                .tint(Color.MW.green)
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
                 ? "Starts today and repeats on this weekday. Assigned to you."
                 : "A one-off job for today, assigned to you.")
        }
    }
}

private func saveLabel(_ title: String, saving: Bool) -> some View {
    HStack {
        Spacer()
        if saving { ProgressView().tint(.white) }
        Text(saving ? "Saving…" : title)
            .font(.headline)
            .foregroundStyle(.white)
        Spacer()
    }
    .padding(.vertical, 6)
}
