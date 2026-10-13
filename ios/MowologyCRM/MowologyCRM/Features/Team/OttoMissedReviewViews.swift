//
//  OttoMissedReviewViews.swift
//  MowologyCRM
//
//  Otto's end-of-day missed-visit review — the iPhone's version of the web's
//  /crm/ops/missed-visits.php (public/crm/js/otto-missed.js). Both call the same server:
//  GET/POST /api/schedule/missed-review → MissedVisitReviewService (JWT, schedule.edit).
//
//  Opened from Otto's card on the Team tab ("3 visits not done — review") and from the push
//  (NotificationRouter.pendingOttoReview). Per visit: the property, the plan, why it was missed,
//  Otto's pick + reason; then Tomorrow / Pick a date / Skip (with a reason) / Cancel (with a
//  reason), and one "Accept all of Otto's picks". Nothing changes until a tap; nothing here
//  messages a client.
//

import SwiftUI

// MARK: - Models

struct OttoMissedResponse: Decodable {
    let ok: Bool
    let error: String?
    let review: OttoMissedReviewInfo?
    let waiting: Int?
    let withPick: Int?
    let items: [OttoMissedItem]?
    let reasons: [OttoMissedReason]?
    let tomorrow: String?
    let today: String?

    enum CodingKeys: String, CodingKey {
        case ok, error, review, waiting, items, reasons, tomorrow, today
        case withPick = "with_pick"
    }
}

struct OttoMissedReviewInfo: Decodable, Equatable {
    let id: Int
    let date: String
    let label: String
}

struct OttoMissedReason: Decodable, Identifiable, Hashable {
    let key: String
    let label: String
    var id: String { key }
}

struct OttoMissedItem: Decodable, Identifiable, Equatable {
    let id: Int
    let visitId: Int
    let property: String
    let plan: String
    let crew: String
    let missedLabel: String
    let why: String
    let pick: String?
    let pickDate: String?
    let pickLabel: String
    let pickReason: String
    let skipAllowed: Bool
    let skipBlock: String?
    let decisionText: String?
    let applyError: String?
    let canDecide: Bool

    enum CodingKeys: String, CodingKey {
        case id, property, plan, crew, why, pick
        case visitId = "visit_id"
        case missedLabel = "missed_label"
        case pickDate = "pick_date"
        case pickLabel = "pick_label"
        case pickReason = "pick_reason"
        case skipAllowed = "skip_allowed"
        case skipBlock = "skip_block"
        case decisionText = "decision_text"
        case applyError = "apply_error"
        case canDecide = "can_decide"
    }
}

struct OttoMissedActionResponse: Decodable {
    let ok: Bool
    let message: String?
    let error: String?
}

// MARK: - Model

@MainActor
final class OttoMissedReviewModel: ObservableObject {
    @Published private(set) var data: OttoMissedResponse?
    @Published private(set) var isLoading = false
    @Published private(set) var busyId: Int?
    @Published var loadError: String?
    /// One outcome line per item (0 = the whole review).
    @Published private(set) var notes: [Int: HeadCardViewModel.OttoNote] = [:]

    private let api: TeamHeadAPI

    init(api: TeamHeadAPI) { self.api = api }

    var waiting: Int { data?.waiting ?? 0 }
    var items: [OttoMissedItem] {
        let all = data?.items ?? []
        return all.filter { $0.canDecide } + all.filter { !$0.canDecide }
    }

    func load() async {
        isLoading = true
        defer { isLoading = false }
        do {
            let r = try await api.ottoMissedLoad()
            if r.ok { data = r; loadError = nil } else { loadError = r.error ?? "Otto's review isn't set up yet." }
        } catch let e as APIError {
            loadError = e.errorDescription
        } catch {
            loadError = error.localizedDescription
        }
    }

    func decide(_ item: OttoMissedItem, choice: String, date: Date? = nil, reason: String? = nil, note: String? = nil) async {
        var body: [String: Any] = ["mode": "decide", "item_id": item.id, "choice": choice]
        if let date { body["date"] = Self.iso.string(from: date) }
        if let reason { body["reason"] = reason }
        if let note, !note.isEmpty { body["note"] = note }
        busyId = item.id
        defer { busyId = nil }
        do {
            let r = try await api.ottoMissedSend(body)
            notes[item.id] = .init(text: r.ok ? (r.message ?? "Done") : (r.error ?? r.message ?? "Could not apply that."), isError: !r.ok)
            if r.ok { await load() }
        } catch {
            notes[item.id] = .init(text: "Network error — nothing was changed.", isError: true)
        }
    }

    func acceptAll() async {
        busyId = 0
        defer { busyId = nil }
        do {
            let r = try await api.ottoMissedSend(["mode": "accept_all"])
            notes[0] = .init(text: r.message ?? (r.ok ? "Done" : "Some could not be applied."), isError: !r.ok)
            await load()
        } catch {
            notes[0] = .init(text: "Network error — nothing was changed.", isError: true)
        }
    }

    static let iso: DateFormatter = {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()
}

// MARK: - Entry on Otto's card

/// "3 visits not done — review" on Otto's card; opens the review sheet. Also opens itself when
/// Otto's missed-visit push is tapped.
struct OttoMissedEntry: View {
    @ObservedObject var model: OttoMissedReviewModel
    @ObservedObject private var router = NotificationRouter.shared
    @State private var showing = false

    var body: some View {
        Group {
            if model.waiting > 0 {
                Button { showing = true } label: {
                    HStack(spacing: 10) {
                        Image(systemName: "calendar.badge.exclamationmark")
                            .font(.title3)
                            .foregroundStyle(Color.MW.orange)
                        VStack(alignment: .leading, spacing: 2) {
                            Text("\(model.waiting) visit\(model.waiting == 1 ? "" : "s") not done")
                                .font(.subheadline.weight(.semibold))
                                .foregroundStyle(.primary)
                            Text("Otto has a pick for each. Tap to decide.")
                                .font(.caption).foregroundStyle(.secondary)
                        }
                        Spacer()
                        Image(systemName: "chevron.right").font(.caption).foregroundStyle(.secondary)
                    }
                    .padding(12)
                    .background(Color.MW.light, in: RoundedRectangle(cornerRadius: 12))
                }
                .buttonStyle(.plain)
            }
        }
        .task { if model.data == nil { await model.load() } }
        .onAppear { consumePush() }
        .onChange(of: router.pendingOttoReview) { _, _ in consumePush() }
        .sheet(isPresented: $showing, onDismiss: { Task { await model.load() } }) {
            OttoMissedReviewSheet(model: model)
        }
    }

    private func consumePush() {
        guard router.pendingOttoReview else { return }
        router.pendingOttoReview = false
        showing = true
        Task { await model.load() }
    }
}

// MARK: - The review screen

struct OttoMissedReviewSheet: View {
    @ObservedObject var model: OttoMissedReviewModel
    @Environment(\.dismiss) private var dismiss
    @State private var dateFor: OttoMissedItem?
    @State private var reasonFor: ReasonTarget?

    struct ReasonTarget: Identifiable { let item: OttoMissedItem; let choice: String; var id: String { "\(choice)-\(item.id)" } }

    var body: some View {
        NavigationStack {
            List {
                header
                if model.items.isEmpty && !model.isLoading {
                    Text("No missed visits in the last week.").foregroundStyle(.secondary)
                }
                ForEach(model.items) { item in
                    row(item)
                }
            }
            .listStyle(.insetGrouped)
            .refreshable { await model.load() }
            .navigationTitle("Missed visits")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } }
            }
            .overlay { if model.isLoading && model.data == nil { ProgressView("Loading…") } }
            .sheet(item: $dateFor) { item in
                OttoMissedDateSheet(item: item, earliest: Self.parse(model.data?.today) ?? Date()) { date in
                    Task { await model.decide(item, choice: "date", date: date) }
                }
            }
            .sheet(item: $reasonFor) { t in
                OttoMissedReasonSheet(target: t, reasons: model.data?.reasons ?? []) { reason, note in
                    Task { await model.decide(t.item, choice: t.choice, reason: reason, note: note) }
                }
            }
        }
    }

    @ViewBuilder private var header: some View {
        Section {
            VStack(alignment: .leading, spacing: 6) {
                HStack(spacing: 10) {
                    AsyncImage(url: URL(string: "https://mowology.ca/crm/img/heads/otto.jpg")) { img in
                        img.resizable().scaledToFill()
                    } placeholder: {
                        Circle().fill(Color.MW.light)
                    }
                    .frame(width: 40, height: 40).clipShape(Circle())
                    VStack(alignment: .leading, spacing: 2) {
                        Text(model.waiting > 0 ? "\(model.waiting) visit\(model.waiting == 1 ? "" : "s") not done" : "Nothing waiting")
                            .font(.headline)
                        if let r = model.data?.review { Text("Review for \(r.label)").font(.caption).foregroundStyle(.secondary) }
                    }
                }
                Text("Undecided visits roll on tonight as before; contract visits stay open for the next review. Nothing is sent to clients.")
                    .font(.caption).foregroundStyle(.secondary)
                if let n = model.notes[0] {
                    Text(n.text).font(.caption).foregroundStyle(n.isError ? .red : Color.MW.green)
                }
                if let k = model.data?.withPick, k > 0 {
                    Button {
                        Task { await model.acceptAll() }
                    } label: {
                        HStack {
                            if model.busyId == 0 { ProgressView().controlSize(.small) }
                            Text("Accept all of Otto's picks (\(k))").frame(maxWidth: .infinity)
                        }
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(Color.MW.green)
                    .disabled(model.busyId != nil)
                }
            }
            .padding(.vertical, 4)
        }
    }

    @ViewBuilder private func row(_ item: OttoMissedItem) -> some View {
        Section {
            VStack(alignment: .leading, spacing: 6) {
                HStack(alignment: .firstTextBaseline) {
                    Text(item.property.isEmpty ? "Property" : item.property).font(.subheadline.weight(.semibold))
                    Spacer()
                    Text(item.missedLabel).font(.caption).foregroundStyle(.secondary)
                }
                Text(item.plan + (item.crew.isEmpty ? "" : " · \(item.crew)")).font(.caption).foregroundStyle(.secondary)
                if !item.why.isEmpty { Text("Why: \(item.why)").font(.caption).foregroundStyle(.secondary) }
                if item.canDecide {
                    VStack(alignment: .leading, spacing: 2) {
                        (Text("Otto: \(item.pickLabel) ").bold() + Text(item.pickReason)).font(.caption)
                        if !item.skipAllowed, let b = item.skipBlock {
                            Text("Never skipped by Otto: \(b)").font(.caption2).foregroundStyle(.secondary)
                        }
                    }
                    .padding(8)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Color.MW.light, in: RoundedRectangle(cornerRadius: 8))
                    buttons(item)
                } else {
                    Text(item.decisionText ?? "Done").font(.caption.weight(.semibold)).foregroundStyle(Color.MW.dark)
                }
                if let n = model.notes[item.id] {
                    Text(n.text).font(.caption).foregroundStyle(n.isError ? .red : Color.MW.green)
                } else if let e = item.applyError, item.canDecide {
                    Text("Last try: \(e)").font(.caption).foregroundStyle(.red)
                }
            }
            .padding(.vertical, 2)
        }
    }

    @ViewBuilder private func buttons(_ item: OttoMissedItem) -> some View {
        let busy = model.busyId != nil
        VStack(alignment: .leading, spacing: 8) {
            if item.pick != nil {
                Button {
                    Task { await model.decide(item, choice: "accept") }
                } label: {
                    HStack {
                        if model.busyId == item.id { ProgressView().controlSize(.small) }
                        Text("Do Otto's pick").frame(maxWidth: .infinity)
                    }
                }
                .buttonStyle(.borderedProminent).tint(Color.MW.green)
            }
            HStack(spacing: 8) {
                Button("Tomorrow") { Task { await model.decide(item, choice: "tomorrow") } }
                Button("Pick a date") { dateFor = item }
                Button("Skip") { reasonFor = ReasonTarget(item: item, choice: "skip") }
                Button("Cancel") { reasonFor = ReasonTarget(item: item, choice: "cancel") }
                    .tint(.red)
            }
            .buttonStyle(.bordered)
            .controlSize(.small)
            .font(.caption)
        }
        .disabled(busy)
    }

    static func parse(_ s: String?) -> Date? {
        guard let s else { return nil }
        return OttoMissedReviewModel.iso.date(from: s)
    }
}

/// Pick a day for the visit (graphical calendar, as Move Stop does).
struct OttoMissedDateSheet: View {
    let item: OttoMissedItem
    let earliest: Date
    let onPick: (Date) -> Void
    @Environment(\.dismiss) private var dismiss
    @State private var date: Date

    init(item: OttoMissedItem, earliest: Date, onPick: @escaping (Date) -> Void) {
        self.item = item
        self.earliest = earliest
        self.onPick = onPick
        let start = OttoMissedReviewSheet.parse(item.pickDate) ?? Calendar.current.date(byAdding: .day, value: 1, to: earliest) ?? earliest
        _date = State(initialValue: max(start, earliest))
    }

    var body: some View {
        NavigationStack {
            VStack(alignment: .leading, spacing: 12) {
                Text(item.property).font(.subheadline.weight(.semibold))
                DatePicker("Move to", selection: $date, in: earliest..., displayedComponents: .date)
                    .datePickerStyle(.graphical)
                    .tint(Color.MW.green)
                Spacer()
            }
            .padding()
            .navigationTitle("Move to…")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Back") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Move") { onPick(date); dismiss() }
                }
            }
        }
        .presentationDetents([.large])
    }
}

/// Skip or cancel: why (weather, client asked, no access, other) + an optional note.
struct OttoMissedReasonSheet: View {
    let target: OttoMissedReviewSheet.ReasonTarget
    let reasons: [OttoMissedReason]
    let onConfirm: (String, String) -> Void
    @Environment(\.dismiss) private var dismiss
    @State private var reason: String?
    @State private var note = ""

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    Text(target.item.property).font(.subheadline.weight(.semibold))
                    if target.choice == "skip", !target.item.skipAllowed, let b = target.item.skipBlock {
                        Text("Otto never would: \(b)").font(.caption).foregroundStyle(.secondary)
                    }
                    if target.choice == "cancel" {
                        Text("It will not be done or billed.").font(.caption).foregroundStyle(.secondary)
                    }
                }
                Section("Why?") {
                    ForEach(reasons) { r in
                        Button {
                            reason = r.key
                        } label: {
                            HStack {
                                Text(r.label).foregroundStyle(.primary)
                                Spacer()
                                if reason == r.key { Image(systemName: "checkmark").foregroundStyle(Color.MW.green) }
                            }
                        }
                    }
                }
                Section("Note (optional)") {
                    TextField("e.g. away until Friday", text: $note)
                }
            }
            .navigationTitle(target.choice == "skip" ? "Skip this visit" : "Cancel this visit")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Back") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button(target.choice == "skip" ? "Skip" : "Cancel visit") {
                        if let reason { onConfirm(reason, note); dismiss() }
                    }
                    .disabled(reason == nil)
                }
            }
        }
        .presentationDetents([.medium, .large])
    }
}
