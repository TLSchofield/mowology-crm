//
//  OttoSuggestionViews.swift
//  MowologyCRM
//
//  Otto's suggestions on the Team tab — the web card's list (public/crm/js/otto-card.js) with
//  the same buttons, sent through POST team-mobile {mode: otto_decide} → OttoActionService.
//  Nothing changes until Tim taps, and nothing here messages a crew member.
//
//    unscheduled / extra_work   times + which plan (or a one-off) → Add the visit · Add + invoice ·
//                               Already billed (pick the invoice) · Not work / It was all the lawn cut
//    visit_date                 "done on another day" → Move it
//    duration                   the plan's length → Update the plan · Keep
//    pin_off                    map: the pin vs where the crews work → Move the pin there · The pin is right
//    default_border / no_pin / border_overlap   open the web zone editor / map · or leave it
//    the rest (weather, timers, bylaws, kit, training, quiet phones) — their one-tap answers;
//    anything needing the web's pickers (a weather slot, a bylaw start time) opens the CRM.
//  Plus the contract visits Otto logged by himself (otto_auto_visits) with Undo.
//

import SwiftUI
import MapKit

@MainActor
struct OttoDeskSection: View {
    @ObservedObject var vm: HeadCardViewModel
    @Environment(\.openURL) private var openURL

    var body: some View {
        if !vm.ottoItems.isEmpty || vm.autolog != nil {
            VStack(alignment: .leading, spacing: 12) {
                if let log = vm.autolog, !log.line.isEmpty { autologSection(log) }
                if !vm.ottoItems.isEmpty {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Otto suggests").font(.headline)
                        Text(vm.ottoTotal > vm.ottoItems.count
                             ? "\(vm.ottoItems.count) of \(vm.ottoTotal) — the rest on the review page"
                             : "Nothing changes until you tap")
                            .font(.caption).foregroundStyle(.secondary)
                    }
                    ForEach(vm.ottoItems) { s in
                        OttoSuggestionRow(vm: vm, s: s)
                        if s.id != vm.ottoItems.last?.id { Divider() }
                    }
                }
                Button("Review work, lengths and borders on the web →") {
                    if let u = HeadCardViewModel.webURL(vm.ottoReviewURL) { openURL(u) }
                }
                .font(.caption)
            }
        }
    }

    // MARK: Contract visits Otto logged by himself

    private func autologSection(_ log: OttoAutoLog) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Label(log.line, systemImage: "checkmark.seal")
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(Color.MW.dark)
            ForEach(log.rows) { row in
                HStack(alignment: .top, spacing: 8) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(row.address.isEmpty ? "Property" : row.address).font(.caption.weight(.semibold))
                        Text(Self.rowLine(row)).font(.caption2).foregroundStyle(.secondary)
                        if let n = vm.ottoNotes[-row.id] {
                            Text(n.text).font(.caption2).foregroundStyle(n.isError ? .red : Color.MW.green)
                        }
                    }
                    Spacer(minLength: 4)
                    if !vm.undoneAuto.contains(row.id) {
                        Button {
                            Task { await vm.undoAuto(row) }
                        } label: {
                            if vm.ottoBusy == -row.id { ProgressView().controlSize(.small) } else { Text("Undo") }
                        }
                        .font(.caption)
                        .buttonStyle(.bordered)
                        .controlSize(.small)
                        .disabled(vm.ottoBusy != nil)
                    }
                }
            }
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.MW.light, in: RoundedRectangle(cornerRadius: 10))
    }

    private static func rowLine(_ r: OttoAutoLogRow) -> String {
        let day = HeadCardViewModel.shortDate(r.day)
        if r.kind == "move" {
            return "\(day) · moved from \(r.movedFrom.map(HeadCardViewModel.shortDate) ?? "another day")"
        }
        var parts = [day]
        if let s = r.start, let e = r.end { parts.append("\(s)–\(e)") }
        if r.minutes > 0 { parts.append("\(r.minutes) min") }
        if r.kind == "extra" { parts.append("extra") }
        return parts.joined(separator: " · ")
    }
}

// MARK: - One suggestion

@MainActor
struct OttoSuggestionRow: View {
    @ObservedObject var vm: HeadCardViewModel
    let s: OttoSuggestion
    @Environment(\.openURL) private var openURL

    // Inputs (only the kinds that need them read these)
    @State private var minutes = ""
    @State private var start = Date()
    @State private var end = Date()
    @State private var planChoice = "oneoff"
    @State private var service = OttoSuggestionRow.oneOffServices[0]
    @State private var title = ""
    @State private var didSeed = false

    /// The web card's ONE_OFF_SERVICES.
    static let oneOffServices = ["Hedge Trimming", "Cleanup", "Garden Care", "Lawn Cut", "Pruning", "Other"]

    private var busy: Bool { vm.ottoBusy != nil }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(s.text)
                .font(s.priority <= 1 ? .subheadline.weight(.semibold) : .subheadline)
                .fixedSize(horizontal: false, vertical: true)
            if !s.detail.isEmpty {
                Text(s.detail).font(.caption).foregroundStyle(.secondary)
                    .fixedSize(horizontal: false, vertical: true)
            }
            controls
            if let n = vm.ottoNotes[s.id] {
                Text(n.text).font(.caption).foregroundStyle(n.isError ? .red : Color.MW.green)
            }
            if vm.ottoBusy == s.id { ProgressView().controlSize(.small) }
        }
        .onAppear(perform: seed)
    }

    // MARK: Controls per kind (otto-card.js controls())

    @ViewBuilder
    private var controls: some View {
        switch s.kind {
        case "unscheduled", "extra_work":
            unscheduled
        case "visit_date":
            buttons([("Move it to \(s.text("to_label") ?? s.text("to") ?? "that day")", "move", true), ("Leave it", "dismiss", false)])
        case "duration":
            HStack(spacing: 8) {
                minutesField(label: "Plan length")
                Button("Update the plan") { applyMinutes() }.buttonStyle(.borderedProminent).tint(Color.MW.green)
                Button("Keep \(s.int("planned").map { "\($0) min" } ?? "it")") { decide(["choice": "keep"]) }.buttonStyle(.bordered)
            }
            .font(.caption.weight(.semibold)).controlSize(.small).disabled(busy)
        case "pin_off":
            pinMap
            buttons([("Move the pin there", "move_pin", true), ("The pin is right", "dismiss", false)])
        case "default_border":
            linkAnd("Draw it", [("It's fine as it is", "keep")])
        case "no_pin":
            linkAnd("Find it on the map", [("Not now", "dismiss")])
        case "border_overlap":
            linkAnd("Redraw", [("They share a lot — fine", "dismiss")])
        case "weather":
            linkAnd("Move it…", [("Keep it", "keep")], linkFirst: s.text("lean") == "move")
        case "clock_out":
            buttons([("Use \(Self.clock(s.text("clock_out")))", "apply_clock", true), ("Leave it", "dismiss", false)])
        case "job_timer", "no_time":
            HStack(spacing: 8) {
                minutesField(label: nil)
                Button(s.kind == "job_timer" ? "Fix the timer" : "Save the time") { applyMinutes() }
                    .buttonStyle(.borderedProminent).tint(Color.MW.green)
                Button("Leave it") { decide(["choice": "dismiss"]) }.buttonStyle(.bordered)
            }
            .font(.caption.weight(.semibold)).controlSize(.small).disabled(busy)
        case "bylaw":
            if s.bool("blower") {
                buttons([("Rake/vac instead — tell the crew", "note", s.text("problem") == "ban"), ("Leave it", "dismiss", false)])
            }
            if s.text("problem") != "ban" { linkAnd("New start time…", []) }
        case "west_end":
            buttons([("Add a crew note", "note", true), ("Leave it", "dismiss", false)])
        case "truck_range":
            buttons([("I'll rework the day", "ack", true), ("It's fine", "dismiss", false)])
        case "maintenance":
            buttons([("Make a task", "task", true), ("Already done", "done", false), ("Leave it", "dismiss", false)])
        case "pack_fading":
            buttons([("Retire the pack", "retire", true), ("Keep using it", "dismiss", false)])
        case "training_gap":
            buttons([("Make me a task", "task", true)] + (s.bool("shadowed") ? [("Fine, they're shadowing", "shadow", false)] : []) + [("Not now", "dismiss", false)])
        case "training_quality", "safety_refresher":
            buttons([("Make me a task", "task", true), ("Not now", "dismiss", false)])
        case "training_topic":
            buttons([("Add to the crew meeting", "task", true), ("Not now", "dismiss", false)])
        case "silent":
            buttons([("It's a problem — I'll call", "real", true), ("It's fine", "fine", false)])
        default:
            linkAnd("Open", [("Leave it", "dismiss")])
        }
    }

    private func buttons(_ list: [(String, String, Bool)]) -> some View {
        FlowButtons {
            ForEach(Array(list.enumerated()), id: \.offset) { _, b in
                if b.2 {
                    Button(b.0) { choose(b.1) }.buttonStyle(.borderedProminent).tint(Color.MW.green)
                } else {
                    Button(b.0) { choose(b.1) }.buttonStyle(.bordered)
                }
            }
        }
        .font(.caption.weight(.semibold))
        .controlSize(.small)
        .disabled(busy)
    }

    /// A web link (the zone editor, the map, the weather slot picker) plus one-tap answers.
    private func linkAnd(_ link: String, _ list: [(String, String)], linkFirst: Bool = true) -> some View {
        FlowButtons {
            if let path = s.url, let u = HeadCardViewModel.webURL(path) {
                if linkFirst {
                    Button(link) { openURL(u) }.buttonStyle(.borderedProminent).tint(Color.MW.green)
                } else {
                    Button(link) { openURL(u) }.buttonStyle(.bordered)
                }
            }
            ForEach(Array(list.enumerated()), id: \.offset) { i, b in
                if !linkFirst && i == 0 {
                    Button(b.0) { choose(b.1) }.buttonStyle(.borderedProminent).tint(Color.MW.green)
                } else {
                    Button(b.0) { choose(b.1) }.buttonStyle(.bordered)
                }
            }
        }
        .font(.caption.weight(.semibold))
        .controlSize(.small)
        .disabled(busy)
    }

    private func minutesField(label: String?) -> some View {
        HStack(spacing: 4) {
            if let label { Text(label).font(.caption2).foregroundStyle(.secondary) }
            TextField("min", text: $minutes)
                .keyboardType(.numberPad)
                .textFieldStyle(.roundedBorder)
                .frame(width: 56)
            Text("min").font(.caption2).foregroundStyle(.secondary)
        }
    }

    // MARK: Work with nothing scheduled / extra work

    private var plans: [[String: BKValue]] { s.objects("plans") }
    private var invoices: [[String: BKValue]] { s.objects("invoices") }

    private var unscheduled: some View {
        let extra = s.bool("extra")
        return VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 10) {
                DatePicker("From", selection: $start, displayedComponents: .hourAndMinute)
                DatePicker("to", selection: $end, displayedComponents: .hourAndMinute)
            }
            .font(.caption)
            Picker("On", selection: $planChoice) {
                ForEach(Array(plans.enumerated()), id: \.offset) { _, pl in
                    let id = pl["id"]?.int ?? 0
                    let name = [pl["number"]?.text, pl["title"]?.text ?? pl["service_type"]?.text].compactMap { $0 }.joined(separator: " · ")
                    Text(name + (Self.isRecurring(pl) ? " (recurring)" : ""))
                        .tag(String(id))
                }
                Text("A one-off job").tag("oneoff")
            }
            .pickerStyle(.menu)
            .font(.caption)
            if planChoice == "oneoff" {
                Picker("Service", selection: $service) {
                    ForEach(Self.oneOffServices, id: \.self) { Text($0).tag($0) }
                }
                .pickerStyle(.menu)
                .font(.caption)
                TextField("What was done (e.g. yew hedge reduction)", text: $title)
                    .textFieldStyle(.roundedBorder)
                    .font(.caption)
            }
            FlowButtons {
                Button(extra ? "Add the extra work as a visit" : "Add the visit") { addVisit(then: "none") }
                    .buttonStyle(.borderedProminent).tint(Color.MW.green)
                Button("Add + create invoice") { addVisit(then: "invoice") }
                    .buttonStyle(.bordered)
                if !invoices.isEmpty {
                    Menu("Already billed…") {
                        ForEach(Array(invoices.enumerated()), id: \.offset) { _, inv in
                            Button([inv["number"]?.text, inv["date"]?.text.map { HeadCardViewModel.shortDate($0) }].compactMap { $0 }.joined(separator: " · ")) {
                                addVisit(then: "link", invoiceId: inv["id"]?.int)
                            }
                        }
                    }
                    .buttonStyle(.bordered)
                }
                if extra {
                    Button("It was all the \(s.text("scheduled_label") ?? "visit")") { decide(["choice": "all_scheduled"]) }
                        .buttonStyle(.bordered)
                } else {
                    Button("Not work") { decide(["choice": "not_work"]) }
                        .buttonStyle(.bordered)
                }
            }
            .font(.caption.weight(.semibold))
            .controlSize(.small)
        }
        .disabled(busy)
    }

    private func addVisit(then: String, invoiceId: Int? = nil) {
        guard end > start else {
            vm.ottoSay(s, "Set when they started and finished.")
            return
        }
        var b: [String: Any] = ["start": Self.hm(start), "end": Self.hm(end), "then": then]
        if planChoice == "oneoff" {
            b["choice"] = "oneoff"
            b["service_type"] = service
            b["title"] = title
        } else {
            b["choice"] = "add_visit"
            b["plan_id"] = Int(planChoice) ?? 0
        }
        if let invoiceId { b["invoice_id"] = invoiceId }
        decide(b)
    }

    // MARK: "pin X m off": the pin vs where the crews work

    @ViewBuilder
    private var pinMap: some View {
        if let wLat = s.double("lat"), let wLng = s.double("lng") {
            let work = CLLocationCoordinate2D(latitude: wLat, longitude: wLng)
            let pin = s.pinLat.flatMap { lat in s.pinLng.map { CLLocationCoordinate2D(latitude: lat, longitude: $0) } }
            Map(initialPosition: .region(Self.region(work, pin))) {
                if let pin {
                    Marker("Pin now", systemImage: "mappin", coordinate: pin).tint(.red)
                }
                Marker("Where crews work", systemImage: "figure.walk", coordinate: work).tint(Color.MW.green)
            }
            .frame(height: 170)
            .clipShape(RoundedRectangle(cornerRadius: 10))
            .allowsHitTesting(true)
        }
    }

    private static func region(_ a: CLLocationCoordinate2D, _ b: CLLocationCoordinate2D?) -> MKCoordinateRegion {
        guard let b else {
            return MKCoordinateRegion(center: a, latitudinalMeters: 250, longitudinalMeters: 250)
        }
        let center = CLLocationCoordinate2D(latitude: (a.latitude + b.latitude) / 2, longitude: (a.longitude + b.longitude) / 2)
        let span = MKCoordinateSpan(latitudeDelta: max(0.0015, abs(a.latitude - b.latitude) * 2.4),
                                    longitudeDelta: max(0.0015, abs(a.longitude - b.longitude) * 2.4))
        return MKCoordinateRegion(center: center, span: span)
    }

    // MARK: Sending

    private func choose(_ choice: String) {
        switch choice {
        case "apply_clock":
            guard let co = s.text("clock_out"), !co.isEmpty else {
                vm.ottoSay(s, "Set the clock-out on the web.")
                return
            }
            decide(["choice": "apply", "clock_out": co.replacingOccurrences(of: "T", with: " ")])
        default:
            decide(["choice": choice])
        }
    }

    private func applyMinutes() {
        guard let m = Int(minutes.trimmingCharacters(in: .whitespaces)), m > 0 else {
            vm.ottoSay(s, "Give the minutes first.")
            return
        }
        decide(["choice": "apply", "minutes": m])
    }

    private func decide(_ body: [String: Any]) {
        Task {
            if let u = await vm.ottoDecide(s, body) { openURL(u) }
        }
    }

    private func seed() {
        guard !didSeed else { return }
        didSeed = true
        if let m = s.int("minutes") { minutes = String(m) }
        if let t = s.text("start"), let d = Self.date(hm: t) { start = d }
        if let t = s.text("end"), let d = Self.date(hm: t) { end = d }
        let extra = s.bool("extra")
        if !extra, let first = plans.first?["id"]?.int { planChoice = String(first) } else { planChoice = "oneoff" }
    }

    // MARK: Formatting

    private static func isRecurring(_ pl: [String: BKValue]) -> Bool {
        pl["recurring"] == .bool(true) || pl["recurring"]?.int == 1
    }

    private static func date(hm: String) -> Date? {
        let parts = hm.prefix(5).split(separator: ":").compactMap { Int($0) }
        guard parts.count == 2 else { return nil }
        return Calendar.current.date(bySettingHour: parts[0], minute: parts[1], second: 0, of: Date())
    }

    private static func hm(_ d: Date) -> String {
        let c = Calendar.current.dateComponents([.hour, .minute], from: d)
        return String(format: "%02d:%02d", c.hour ?? 0, c.minute ?? 0)
    }

    /// "2026-10-07T16:30" / "2026-10-07 16:30" → "4:30 pm".
    private static func clock(_ s: String?) -> String {
        guard let s, s.count >= 16 else { return "Otto's time" }
        let t = String(s.dropFirst(11).prefix(5))
        guard let d = date(hm: t) else { return t }
        return d.formatted(date: .omitted, time: .shortened)
    }
}

/// Buttons that wrap onto the next line on a narrow phone.
struct FlowButtons<Content: View>: View {
    @ViewBuilder let content: () -> Content

    var body: some View {
        ViewThatFits(in: .horizontal) {
            HStack(spacing: 8) { content() }
            VStack(alignment: .leading, spacing: 6) { content() }
        }
    }
}
