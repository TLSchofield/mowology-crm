//
//  EquipmentAddView.swift
//  MowologyCRM
//
//  Otto → Add equipment on the iPhone. Snap the model/serial plate (and, if you like, the machine),
//  the server reads it, and a prefilled form comes back: name, type, power, make, model, serial,
//  bought, cost, who uses it, which truck, and the purchase receipt if one matches. Save adds the
//  machine; its MODEL joins the shared catalogue so every unit of it shares specs, parts and the
//  manual's intervals. Same server code as the web page (/crm/ops/equipment.php).
//
//  2026-10-14: the same form edits a machine already added (Otto → Equipment list → tap one): fix the
//  serial, model or type, or retake the plate and let it fill in what it reads. Only the fields on this
//  form change (EquipmentService::updateFromPhone keeps the rest).
//

import SwiftUI
import PhotosUI
import UIKit

// MARK: - API

protocol EquipmentAPI {
    func options() async throws -> EquipmentOptions
    func readPlate(plate: Data, photo: Data?) async throws -> PlateReadResponse
    func receipts(_ body: [String: Any]) async throws -> EquipmentReceiptsResponse
    func save(_ body: [String: Any]) async throws -> EquipmentSaveResponse
    func list() async throws -> EquipmentListResponse
    func update(_ body: [String: Any]) async throws -> EquipmentSaveResponse
}

@MainActor
struct LiveEquipmentAPI: EquipmentAPI {
    let client: APIClient

    func options() async throws -> EquipmentOptions {
        try await client.request(.equipmentOptions)
    }
    func readPlate(plate: Data, photo: Data?) async throws -> PlateReadResponse {
        try await client.uploadPlate(plateData: plate, photoData: photo)
    }
    func receipts(_ body: [String: Any]) async throws -> EquipmentReceiptsResponse {
        try await client.request(.equipmentAction, body: body.merging(["mode": "receipts"]) { _, new in new })
    }
    func save(_ body: [String: Any]) async throws -> EquipmentSaveResponse {
        try await client.request(.equipmentAction, body: body.merging(["mode": "save"]) { _, new in new })
    }
    func list() async throws -> EquipmentListResponse {
        try await client.request(.equipmentList)
    }
    func update(_ body: [String: Any]) async throws -> EquipmentSaveResponse {
        try await client.request(.equipmentAction, body: body.merging(["mode": "update"]) { _, new in new })
    }
}

// MARK: - View model

@MainActor
final class EquipmentAddViewModel: ObservableObject {
    enum Step { case snap, confirm, done }

    let api: EquipmentAPI
    @Published var step: Step = .snap
    @Published var plateImage: UIImage?
    @Published var photoImage: UIImage?
    @Published var options: EquipmentOptions?
    @Published var busy = false
    @Published var message: String?
    @Published var messageIsError = false
    @Published var knownModel: EquipmentKnownModel?
    @Published var receipts: [EquipmentReceiptCandidate] = []

    // The form
    @Published var name = ""
    @Published var equipmentClass = "mower"
    @Published var powerSource = "gas"
    @Published var make = ""
    @Published var model = ""
    @Published var serial = ""
    @Published var hasPurchaseDate = true
    @Published var purchaseDate = Date()
    @Published var cost = ""
    @Published var assignedUserId: Int?
    @Published var truckId: Int?
    @Published var expenseId: Int?
    var modelName = ""
    var plateMediaId: Int?
    var photoMediaId: Int?
    var labelCaptureId: Int?

    /// Set when the form edits a machine already in the register (nil = adding a new one).
    let editingId: Int?

    init(api: EquipmentAPI) { self.api = api; self.editingId = nil }

    /// Edit a machine already added: the form opens filled in, at the confirm step.
    init(api: EquipmentAPI, item: EquipmentItem) {
        self.api = api
        self.editingId = item.id
        name = item.name
        equipmentClass = item.equipmentClass
        powerSource = item.powerSource ?? "gas"
        make = item.make ?? ""
        model = item.model ?? ""
        serial = item.serialNo ?? ""
        if let d = item.purchaseDate, let date = Self.ymd.date(from: d) { purchaseDate = date; hasPurchaseDate = true } else { hasPurchaseDate = false }
        cost = item.costManual.map { String(format: "%.2f", $0) } ?? ""
        assignedUserId = item.assignedUserId
        truckId = item.truckId
        expenseId = item.expenseId
        step = .confirm
    }

    func loadOptions() async {
        if options != nil { return }
        options = try? await api.options()
    }

    func readPlate() async {
        guard let plate = plateImage else { return }
        busy = true
        message = "Reading the plate…"
        messageIsError = false
        defer { busy = false }
        let plateData = await Task.detached(priority: .userInitiated) { ReceiptsView.resizeAndCompress(plate) }.value
        var photoData: Data?
        if let p = photoImage { photoData = await Task.detached(priority: .userInitiated) { ReceiptsView.resizeAndCompress(p) }.value }
        do {
            let r = try await api.readPlate(plate: plateData, photo: photoData)
            if let s = r.suggested {
                // Editing: keep what's there unless the plate read something (it may read only the serial).
                let keep = editingId != nil
                func pick(_ read: String?, _ current: String) -> String {
                    let v = (read ?? "").trimmingCharacters(in: .whitespaces)
                    return v.isEmpty && keep ? current : v
                }
                if !keep || name.trimmingCharacters(in: .whitespaces).isEmpty { name = pick(s.name, name) }
                make = pick(s.make, make)
                model = pick(s.model, model)
                serial = pick(s.serialNo, serial)
                if let c = s.equipmentClass, !c.isEmpty, !(keep && c == "other") { equipmentClass = c }
                if let p = s.powerSource, !p.isEmpty { powerSource = p }
                modelName = s.modelName ?? ""
            }
            plateMediaId = r.plateMediaId
            photoMediaId = r.photoMediaId
            labelCaptureId = r.labelCaptureId
            knownModel = r.knownModel
            receipts = r.receipts ?? []
            message = r.message
            messageIsError = r.existing != nil
            step = .confirm
        } catch {
            message = (error as? APIError)?.errorDescription ?? error.localizedDescription
            messageIsError = true
        }
    }

    static let ymd: DateFormatter = {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()

    func findReceipts() async {
        var body: [String: Any] = ["make": make, "model": model]
        if hasPurchaseDate { body["date"] = Self.ymd.string(from: purchaseDate) }
        if let c = Double(cost.replacingOccurrences(of: ",", with: "")) { body["cost"] = c }
        receipts = (try? await api.receipts(body).receipts) ?? []
        if receipts.isEmpty { message = "No matching receipts. Type the receipt # on the web page later if you find it."; messageIsError = false }
    }

    func save() async -> Bool {
        let trimmed = name.trimmingCharacters(in: .whitespaces)
        guard !trimmed.isEmpty else { message = "Give it a name."; messageIsError = true; return false }
        busy = true
        defer { busy = false }
        var body: [String: Any] = [
            "name": trimmed, "equipment_class": equipmentClass, "power_source": powerSource,
            "make": make, "model": model, "serial_no": serial, "model_name": modelName,
        ]
        if hasPurchaseDate { body["purchase_date"] = Self.ymd.string(from: purchaseDate) }
        if let c = Double(cost.replacingOccurrences(of: ",", with: "")) { body["cost"] = c }
        if let v = assignedUserId { body["assigned_user_id"] = v }
        if let v = truckId { body["truck_id"] = v }
        if let v = expenseId { body["expense_id"] = v }
        if let v = plateMediaId { body["plate_media_id"] = v }
        if let v = photoMediaId { body["photo_media_id"] = v }
        if let v = labelCaptureId { body["label_capture_id"] = v }
        do {
            if let id = editingId { body["id"] = id }
            let r = try await (editingId == nil ? api.save(body) : api.update(body))
            message = r.message
            messageIsError = !r.ok
            if r.ok { step = .done }
            return r.ok
        } catch {
            message = (error as? APIError)?.errorDescription ?? error.localizedDescription
            messageIsError = true
            return false
        }
    }
}

// MARK: - Entry button (Otto's card on the Team tab)

struct OttoAddEquipmentButton: View {
    let api: EquipmentAPI
    @State private var showing = false
    @State private var showingList = false

    var body: some View {
        HStack(spacing: 8) {
            Button {
                showing = true
            } label: {
                Label("Add — snap the plate", systemImage: "camera.viewfinder")
                    .frame(maxWidth: .infinity)
            }
            Button {
                showingList = true
            } label: {
                Label("Equipment", systemImage: "list.bullet")
                    .frame(maxWidth: .infinity)
            }
        }
        .buttonStyle(.bordered)
        .tint(Color.MW.green)
        .sheet(isPresented: $showing) {
            EquipmentAddView(vm: EquipmentAddViewModel(api: api))
        }
        .sheet(isPresented: $showingList) {
            EquipmentListView(api: api)
        }
    }
}

// MARK: - The register (open a machine to edit it)

struct EquipmentListView: View {
    let api: EquipmentAPI
    @Environment(\.dismiss) private var dismiss
    @State private var items: [EquipmentItem] = []
    @State private var loading = true
    @State private var error: String?
    @State private var search = ""
    @State private var editing: EquipmentItem?

    private var shown: [EquipmentItem] {
        let q = search.trimmingCharacters(in: .whitespaces).lowercased()
        guard !q.isEmpty else { return items }
        return items.filter { i in
            [i.name, i.make ?? "", i.model ?? "", i.serialNo ?? "", i.assignedName ?? ""].joined(separator: " ").lowercased().contains(q)
        }
    }

    var body: some View {
        NavigationStack {
            List {
                if let error { Text(error).font(.footnote).foregroundStyle(.red) }
                if loading && items.isEmpty { ProgressView() }
                if !loading && items.isEmpty && error == nil {
                    Text("No equipment yet — add one by snapping its plate.").foregroundStyle(.secondary)
                }
                ForEach(shown) { i in
                    Button { editing = i } label: {
                        VStack(alignment: .leading, spacing: 3) {
                            Text(i.name).font(.subheadline.weight(.semibold))
                            Text(Self.subtitle(i)).font(.caption).foregroundStyle(i.serialNo?.isEmpty ?? true ? .orange : .secondary)
                        }
                    }
                    .foregroundStyle(.primary)
                }
            }
            .searchable(text: $search, prompt: "Name, model, serial, who uses it")
            .navigationTitle("Equipment")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Close") { dismiss() } } }
            .task { await load() }
            .refreshable { await load() }
            .sheet(item: $editing, onDismiss: { Task { await load() } }) { item in
                EquipmentAddView(vm: EquipmentAddViewModel(api: api, item: item))
            }
        }
    }

    /// "Shindaiwa EB770RT · serial P5211… · Nigel" — "no serial yet" stands out so it gets filled in.
    static func subtitle(_ i: EquipmentItem) -> String {
        var parts: [String] = []
        let mm = [i.make ?? "", i.model ?? ""].filter { !$0.isEmpty }.joined(separator: " ")
        if !mm.isEmpty { parts.append(mm) }
        parts.append((i.serialNo ?? "").isEmpty ? "no serial yet" : "serial \(i.serialNo!)")
        if let who = i.assignedName, !who.isEmpty { parts.append(who) }
        return parts.joined(separator: " · ")
    }

    private func load() async {
        loading = true
        defer { loading = false }
        do {
            items = try await api.list().items
            error = nil
        } catch {
            self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

// MARK: - The sheet

struct EquipmentAddView: View {
    @StateObject var vm: EquipmentAddViewModel
    @Environment(\.dismiss) private var dismiss
    @State private var cameraFor: CameraTarget?
    @State private var plateItem: PhotosPickerItem?
    @State private var photoItem: PhotosPickerItem?

    enum CameraTarget: Identifiable { case plate, machine; var id: Int { self == .plate ? 0 : 1 } }

    init(vm: EquipmentAddViewModel) { _vm = StateObject(wrappedValue: vm) }

    var body: some View {
        NavigationStack {
            Form {
                switch vm.step {
                case .snap:    snapSection
                case .confirm: confirmSections
                case .done:    doneSection
                }
                if let m = vm.message, !m.isEmpty {
                    Section {
                        Text(m).font(.footnote).foregroundStyle(vm.messageIsError ? .red : Color.MW.green)
                    }
                }
            }
            .navigationTitle(vm.editingId == nil ? "Add equipment" : "Edit equipment")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button(vm.step == .done ? "Close" : "Cancel") { dismiss() } }
                if vm.step == .confirm {
                    ToolbarItem(placement: .confirmationAction) {
                        Button("Save") { Task { _ = await vm.save() } }.disabled(vm.busy)
                    }
                }
            }
            .task { await vm.loadOptions() }
            .fullScreenCover(item: $cameraFor) { target in
                CameraPicker(
                    onCapture: { image in
                        if target == .plate { vm.plateImage = image } else { vm.photoImage = image }
                        cameraFor = nil
                    },
                    onCancel: { cameraFor = nil }
                )
                .ignoresSafeArea()
            }
            .onChange(of: plateItem) { _, item in Task { vm.plateImage = await Self.load(item) ?? vm.plateImage } }
            .onChange(of: photoItem) { _, item in Task { vm.photoImage = await Self.load(item) ?? vm.photoImage } }
        }
    }

    private static func load(_ item: PhotosPickerItem?) async -> UIImage? {
        guard let item, let data = try? await item.loadTransferable(type: Data.self) else { return nil }
        return UIImage(data: data)
    }

    // MARK: Snap

    @ViewBuilder
    private var snapSection: some View {
        Section {
            photoRow(title: "Model / serial plate", image: vm.plateImage, target: .plate, item: $plateItem)
            photoRow(title: "The machine (optional)", image: vm.photoImage, target: .machine, item: $photoItem)
        } footer: {
            Text("Get close and square to the plate, in good light. I read the make, model and serial; you check them.")
        }
        Section {
            Button {
                Task { await vm.readPlate() }
            } label: {
                HStack { if vm.busy { ProgressView() }; Text(vm.busy ? "Reading…" : "Read the plate") }
            }
            .disabled(vm.plateImage == nil || vm.busy)
            Button(vm.editingId == nil ? "Type it in instead" : "Back to the form") { vm.step = .confirm }
                .disabled(vm.busy)
        }
    }

    private func photoRow(title: String, image: UIImage?, target: CameraTarget, item: Binding<PhotosPickerItem?>) -> some View {
        HStack(spacing: 12) {
            if let image {
                Image(uiImage: image).resizable().scaledToFill().frame(width: 56, height: 56).clipShape(RoundedRectangle(cornerRadius: 8))
            } else {
                RoundedRectangle(cornerRadius: 8).fill(Color(.secondarySystemBackground)).frame(width: 56, height: 56)
                    .overlay(Image(systemName: target == .plate ? "barcode.viewfinder" : "photo").foregroundStyle(.secondary))
            }
            VStack(alignment: .leading, spacing: 6) {
                Text(title).font(.subheadline.weight(.semibold))
                HStack(spacing: 10) {
                    Button("Camera") { cameraFor = target }.buttonStyle(.bordered).controlSize(.small)
                    PhotosPicker("Library", selection: item, matching: .images).buttonStyle(.bordered).controlSize(.small)
                }
            }
        }
        .padding(.vertical, 4)
    }

    // MARK: Confirm

    @ViewBuilder
    private var confirmSections: some View {
        if let k = vm.knownModel {
            Section {
                Text("I know this model: \(k.name)" + (k.intervals > 0 ? " — the manual's \(k.intervals) service interval(s) are on file." : "."))
                    .font(.footnote)
            }
        }
        if vm.editingId != nil {
            Section {
                Button {
                    vm.step = .snap
                    vm.message = nil
                } label: {
                    Label("Retake the plate — fill in model and serial", systemImage: "camera.viewfinder")
                }
            }
        }
        Section("Machine") {
            TextField("Name / nickname", text: $vm.name)
            Picker("Type", selection: $vm.equipmentClass) {
                ForEach(vm.options?.classes ?? Self.fallbackClasses) { Text($0.label).tag($0.value) }
            }
            Picker("Power", selection: $vm.powerSource) {
                ForEach(vm.options?.power ?? Self.fallbackPower) { Text($0.label).tag($0.value) }
            }
            TextField("Make", text: $vm.make).textInputAutocapitalization(.words)
            TextField("Model", text: $vm.model).textInputAutocapitalization(.characters).autocorrectionDisabled()
            TextField("Serial", text: $vm.serial).textInputAutocapitalization(.characters).autocorrectionDisabled()
        }
        Section("Purchase") {
            Toggle("I know when it was bought", isOn: $vm.hasPurchaseDate)
            if vm.hasPurchaseDate { DatePicker("Bought", selection: $vm.purchaseDate, displayedComponents: .date) }
            TextField("Cost before tax", text: $vm.cost).keyboardType(.decimalPad)
            Button("Find the receipt") { Task { await vm.findReceipts() } }
            ForEach(vm.receipts) { r in
                Button {
                    vm.expenseId = vm.expenseId == r.id ? nil : r.id
                } label: {
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text("#\(r.id) · \(r.date) · \(r.vendor.isEmpty ? "receipt" : r.vendor)").font(.subheadline)
                            Text((r.total.map { String(format: "$%.2f", $0) } ?? "") + " — " + r.why).font(.caption).foregroundStyle(.secondary)
                        }
                        Spacer()
                        if vm.expenseId == r.id { Image(systemName: "checkmark.circle.fill").foregroundStyle(Color.MW.green) }
                    }
                }
                .foregroundStyle(.primary)
            }
        }
        Section("Who and where") {
            Picker("Used by", selection: $vm.assignedUserId) {
                Text("—").tag(Int?.none)
                ForEach(vm.options?.people ?? []) { Text($0.name).tag(Int?.some($0.id)) }
            }
            Picker("Truck / trailer", selection: $vm.truckId) {
                Text("—").tag(Int?.none)
                ForEach(vm.options?.trucks ?? []) { Text($0.name).tag(Int?.some($0.id)) }
            }
        }
        Section {
            Button {
                Task { _ = await vm.save() }
            } label: {
                HStack { if vm.busy { ProgressView() }; Text(vm.editingId == nil ? "Add to equipment" : "Save changes") }
            }
            .disabled(vm.busy)
        }
    }

    private var doneSection: some View {
        Section {
            Label(vm.editingId == nil ? "Added. Service intervals, parts and the cost this year are on the machine's page on the web."
                                      : "Saved.", systemImage: "checkmark.seal.fill")
                .foregroundStyle(Color.MW.green)
            if vm.editingId == nil { Button("Add another") {
                vm.step = .snap
                vm.plateImage = nil; vm.photoImage = nil; vm.message = nil
                vm.name = ""; vm.make = ""; vm.model = ""; vm.serial = ""; vm.cost = ""; vm.expenseId = nil; vm.receipts = []
                vm.plateMediaId = nil; vm.photoMediaId = nil; vm.labelCaptureId = nil; vm.knownModel = nil
            } }
        }
    }

    private static let fallbackClasses = [
        EquipmentChoice(value: "mower", label: "Mower"), EquipmentChoice(value: "trimmer", label: "Trimmer"),
        EquipmentChoice(value: "blower", label: "Blower"), EquipmentChoice(value: "hedge_trimmer", label: "Hedge trimmer"),
        EquipmentChoice(value: "saw", label: "Saw"), EquipmentChoice(value: "aerator", label: "Aerator"),
        EquipmentChoice(value: "battery_pack", label: "Battery pack"), EquipmentChoice(value: "truck", label: "Truck"),
        EquipmentChoice(value: "trailer", label: "Trailer"), EquipmentChoice(value: "other", label: "Other"),
    ]
    private static let fallbackPower = [
        EquipmentChoice(value: "gas", label: "Gas"), EquipmentChoice(value: "battery", label: "Battery"),
        EquipmentChoice(value: "electric", label: "Electric"), EquipmentChoice(value: "diesel", label: "Diesel"),
    ]
}
