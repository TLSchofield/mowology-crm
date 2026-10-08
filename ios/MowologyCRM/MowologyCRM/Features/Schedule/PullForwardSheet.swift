//
//  PullForwardSheet.swift
//  MowologyCRM
//
//  "You're at Oakridge Gardens. Lawn Cut is booked Wed Oct 7. Doing it now?"
//  The iOS side of VisitPullForwardService (POST /api/jobs/visit-pull-forward) — the web's
//  mw-pull-forward.js. Auto-arrival (the server's proximity start) only knows TODAY's visits, so
//  arriving at a property whose visit is on another day did nothing and the work got timed against
//  visits still dated later in the week. This asks instead of guessing:
//
//    Start — moves it to today     POST ?mode=accept, then the existing JWT timer path
//                                  (POST /api/schedule/timer {action: start}).
//    A different job here ›        the property's other plans' nearest visits (+ plans with nothing
//                                  booked → add today's visit).
//    Extra work (new one-off)      POST /api/schedule/field-job {action: add_visit}, then start.
//    Not now                       quiet for this property for the rest of the day (this phone).
//
//  When it checks: when the app comes to the foreground (on_open — opening the app on site is
//  deliberate, no dwell) and every 3 minutes while it stays in the foreground (the server then
//  requires a dwell: two fixes inside the property). Server first: a call that fails says so and
//  nothing is half-done on the phone.
//

import SwiftUI
import Combine
import CoreLocation

extension Notification.Name {
    /// A visit was moved to today / added and started from the pull-forward sheet — the schedule refreshes.
    static let mwScheduleChanged = Notification.Name("ca.mowology.scheduleChanged")
}

// MARK: - Models (VisitPullForwardService::offerAt)

struct PullForwardVisit: Decodable, Identifiable, Equatable {
    let visitId: Int
    let planId: Int
    let planNumber: String
    let title: String
    let serviceType: String
    let scheduledDate: String
    let whenLabel: String
    let overdue: Bool

    var id: Int { visitId }
    var what: String { !serviceType.isEmpty ? serviceType : (!title.isEmpty ? title : "The visit") }

    private enum CodingKeys: String, CodingKey {
        case title, overdue
        case visitId = "visit_id", planId = "plan_id", planNumber = "plan_number", serviceType = "service_type"
        case scheduledDate = "scheduled_date", whenLabel = "when_label"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        visitId = c.bkInt(.visitId) ?? 0
        planId = c.bkInt(.planId) ?? 0
        planNumber = c.bkString(.planNumber) ?? ""
        title = c.bkString(.title) ?? ""
        serviceType = c.bkString(.serviceType) ?? ""
        scheduledDate = c.bkString(.scheduledDate) ?? ""
        whenLabel = c.bkString(.whenLabel) ?? ""
        overdue = c.bkBool(.overdue) ?? false
    }
}

struct PullForwardPlan: Decodable, Identifiable, Equatable {
    let planId: Int
    let title: String
    let serviceType: String

    var id: Int { planId }
    var what: String { !serviceType.isEmpty ? serviceType : title }

    private enum CodingKeys: String, CodingKey { case title, planId = "plan_id", serviceType = "service_type" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        planId = c.bkInt(.planId) ?? 0
        title = c.bkString(.title) ?? ""
        serviceType = c.bkString(.serviceType) ?? ""
    }
}

struct PullForwardOffer: Decodable, Identifiable, Equatable {
    let propertyId: Int
    let siteName: String
    let address: String
    let primary: PullForwardVisit?
    let others: [PullForwardVisit]
    let plansWithoutVisit: [PullForwardPlan]

    var id: Int { propertyId }
    var place: String { !siteName.isEmpty ? siteName : address }

    private enum CodingKeys: String, CodingKey {
        case address, primary, others
        case propertyId = "property_id", siteName = "site_name", plansWithoutVisit = "plans_without_visit"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        propertyId = c.bkInt(.propertyId) ?? 0
        siteName = c.bkString(.siteName) ?? ""
        address = c.bkString(.address) ?? ""
        primary = try? c.decodeIfPresent(PullForwardVisit.self, forKey: .primary)
        others = (try? c.decodeIfPresent([PullForwardVisit].self, forKey: .others)) ?? []
        plansWithoutVisit = (try? c.decodeIfPresent([PullForwardPlan].self, forKey: .plansWithoutVisit)) ?? []
    }
}

struct PullForwardOfferResponse: Decodable {
    let success: Bool
    let offer: PullForwardOffer?
    let reason: String?

    private enum CodingKeys: String, CodingKey { case success, offer, reason }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success = c.bkBool(.success) ?? false
        offer = try? c.decodeIfPresent(PullForwardOffer.self, forKey: .offer)
        reason = c.bkString(.reason)
    }
}

struct PullForwardResult: Decodable {
    let success: Bool
    let error: String?
    let visitId: Int?

    private enum CodingKeys: String, CodingKey { case success, error, visitId = "visit_id" }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success = c.bkBool(.success) ?? false
        error = c.bkString(.error)
        visitId = c.bkInt(.visitId)
    }
}

// MARK: - Coordinator

@MainActor
final class PullForwardCoordinator: ObservableObject {

    static let shared = PullForwardCoordinator()

    enum Phase: Equatable {
        case ask, different
        case working(String)
        case done(String)
        case failed(String)
    }

    /// The offer on screen (drives the sheet).
    @Published var offer: PullForwardOffer?
    @Published private(set) var phase: Phase = .ask

    static let checkEvery: TimeInterval = 180
    private static let firstCheckDelay: UInt64 = 4_000_000_000   // let a proximity auto-start land first

    private var client: APIClient?
    private var loop: Task<Void, Never>?
    private var checking = false
    private var lastFix: CLLocation?
    private var autoStartObserver: AnyCancellable?
    /// The property whose offer is on screen — a swipe-down counts as "Not now" for it.
    private var shownPropertyId: Int?

    private var location: LocationManager { GPSTrackingService.shared.locationManager }

    private init() {}

    // MARK: Lifecycle (MainTabView: foreground on, background off)

    func appBecameActive(authSession: AuthSession) {
        guard authSession.token != nil else { return }
        client = APIClient(authSession: authSession)
        if autoStartObserver == nil {
            // Auto-arrival started something: this sheet is no longer the question.
            autoStartObserver = GPSTrackingService.shared.$autoStartedPayload
                .compactMap { $0 }
                .receive(on: DispatchQueue.main)
                .sink { [weak self] _ in
                    guard let self, self.offer != nil, self.isIdle else { return }
                    self.offer = nil
                }
        }
        loop?.cancel()
        loop = Task { [weak self] in
            try? await Task.sleep(nanoseconds: Self.firstCheckDelay)
            await self?.check(onOpen: true)
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: UInt64(Self.checkEvery * 1_000_000_000))
                guard !Task.isCancelled else { break }
                await self?.check(onOpen: false)
            }
        }
    }

    func appWentInactive() {
        loop?.cancel()
        loop = nil
    }

    private var isIdle: Bool {
        switch phase {
        case .working: return false
        default: return true
        }
    }

    // MARK: Checking

    func check(onOpen: Bool) async {
        guard let client, offer == nil, !checking, location.canUseLocation else { return }
        checking = true
        defer { checking = false }
        guard let fix = try? await location.currentLocation() else { return }
        lastFix = fix
        let body: [String: Any] = [
            "lat": fix.coordinate.latitude, "lng": fix.coordinate.longitude,
            "accuracy": max(0, fix.horizontalAccuracy), "on_open": onOpen,
        ]
        guard let r: PullForwardOfferResponse = try? await client.request(.visitPullForward(mode: "offer"), body: body),
              r.success, let o = r.offer, o.primary != nil, !Self.isDismissed(o.propertyId) else { return }
        phase = .ask
        shownPropertyId = o.propertyId
        offer = o
    }

    /// The sheet went away (button or swipe). A swipe while still asking = "Not now" (as a tap
    /// outside the web sheet); after Start / Extra work there is nothing to remember.
    func sheetDismissed() {
        if let pid = shownPropertyId, phase == .ask || phase == .different { Self.dismiss(pid) }
        shownPropertyId = nil
        offer = nil
        phase = .ask
    }

    // MARK: Actions

    func notNow() {
        if let o = offer { Self.dismiss(o.propertyId) }
        close()
    }

    func close() {
        offer = nil
    }

    func showDifferent() { phase = .different }
    func back() { phase = .ask }

    /// Start: move the visit to today, then start its timer through the existing JWT path.
    func start(visitId: Int) async {
        guard let client, let o = offer, visitId > 0 else { return }
        phase = .working("Moving it to today…")
        do {
            let moved: PullForwardResult = try await client.request(
                .visitPullForward(mode: "accept"),
                body: ["visit_id": visitId, "property_id": o.propertyId, "request_key": UUID().uuidString])
            guard moved.success else {
                phase = .failed(moved.error ?? "Could not move the visit.")
                return
            }
        } catch {
            phase = .failed(Self.message(error, fallback: "No connection — nothing was moved. Try again in a moment."))
            return
        }
        NotificationCenter.default.post(name: .mwScheduleChanged, object: nil)
        await startTimer(visitId: visitId, done: "Timer running — it's on today's schedule")
    }

    /// Extra work: a new one-off visit on that plan today (field-job add_visit), then start it.
    func extraWork(planId: Int) async {
        guard let client, planId > 0 else { return }
        phase = .working("Adding a one-off visit…")
        let added: PullForwardResult
        do {
            added = try await client.request(.fieldJobAction,
                                             body: ["action": "add_visit", "plan_id": planId, "client_request_id": UUID().uuidString])
        } catch {
            phase = .failed(Self.message(error, fallback: "No connection — nothing was added. Try again in a moment."))
            return
        }
        guard added.success else {
            phase = .failed(added.error ?? "Could not add the visit.")
            return
        }
        NotificationCenter.default.post(name: .mwScheduleChanged, object: nil)
        guard let vid = added.visitId, vid > 0 else {
            phase = .done("Visit added — start it from the schedule")
            return
        }
        await startTimer(visitId: vid, done: "Extra visit added — timer running")
    }

    private func startTimer(visitId: Int, done: String) async {
        guard let client else { return }
        phase = .working("Starting the timer…")
        var body: [String: Any] = ["action": "start", "visit_id": visitId]
        if let fix = lastFix {
            body["lat"] = fix.coordinate.latitude
            body["lng"] = fix.coordinate.longitude
        }
        do {
            let r: TimerStartResponse = try await client.request(.scheduleTimer, body: body,
                                                                 extraHeaders: ["Idempotency-Key": UUID().uuidString])
            guard r.success else {
                phase = .failed(r.message ?? "The visit is on today's schedule, but the timer didn't start. Start it from the schedule.")
                return
            }
            GPSTrackingService.shared.setActiveVisit(visitId)
            ArrivalMonitor.shared.jobStarted()
            NotificationCenter.default.post(name: .mwScheduleChanged, object: nil)
            phase = .done(done)
        } catch {
            phase = .failed("The visit is on today's schedule, but the timer didn't start — start it from the schedule.")
        }
    }

    // MARK: Helpers

    private static func dismissKey(_ propertyId: Int) -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return "mw.pf.dismissed.\(f.string(from: Date())).\(propertyId)"
    }

    private static func isDismissed(_ propertyId: Int) -> Bool {
        UserDefaults.standard.bool(forKey: dismissKey(propertyId))
    }

    private static func dismiss(_ propertyId: Int) {
        UserDefaults.standard.set(true, forKey: dismissKey(propertyId))
    }

    private static func message(_ error: Error, fallback: String) -> String {
        if let e = error as? APIError, case .serverError(let m) = e { return m }
        return fallback
    }
}

// MARK: - Sheet

struct PullForwardSheet: View {
    @ObservedObject var coordinator: PullForwardCoordinator
    let offer: PullForwardOffer

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            Label("You're at \(offer.place)", systemImage: "mappin.circle.fill")
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(Color.MW.green)
            switch coordinator.phase {
            case .ask:              ask
            case .different:        different
            case .working(let m):   working(m)
            case .done(let m):      result(m, ok: true)
            case .failed(let m):    result(m, ok: false)
            }
            Spacer(minLength: 0)
        }
        .padding(20)
        .presentationDetents([.medium, .large])
        .presentationDragIndicator(.visible)
        .interactiveDismissDisabled(isWorking)
    }

    private var isWorking: Bool {
        if case .working = coordinator.phase { return true }
        return false
    }

    @ViewBuilder
    private var ask: some View {
        if let v = offer.primary {
            Text("\(v.what) for \(offer.place) \(v.whenLabel).")
                .font(.title3.bold())
                .fixedSize(horizontal: false, vertical: true)
            HStack(spacing: 8) {
                Text("Doing it now?").font(.headline)
                Spacer()
                Text(v.overdue ? "Overdue" : (v.title.isEmpty ? v.planNumber : v.title))
                    .font(.caption.weight(.semibold))
                    .padding(.horizontal, 8).padding(.vertical, 3)
                    .background((v.overdue ? Color.orange : Color.MW.green).opacity(0.15), in: Capsule())
                    .foregroundStyle(v.overdue ? Color.orange : Color.MW.green)
            }
            VStack(spacing: 10) {
                Button {
                    Task { await coordinator.start(visitId: v.visitId) }
                } label: {
                    VStack(spacing: 2) {
                        Text("Start").font(.headline)
                        Text("moves it to today").font(.caption)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 6)
                }
                .buttonStyle(.borderedProminent)
                .tint(Color.MW.green)

                if !offer.others.isEmpty || !offer.plansWithoutVisit.isEmpty {
                    Button {
                        coordinator.showDifferent()
                    } label: {
                        HStack { Text("A different job here"); Spacer(); Image(systemName: "chevron.right") }
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.bordered)
                }
                Button {
                    Task { await coordinator.extraWork(planId: v.planId) }
                } label: {
                    Text("Extra work (new one-off)").frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)

                Button("Not now") { coordinator.notNow() }
                    .buttonStyle(.borderless)
                    .tint(.secondary)
            }
        }
    }

    private var different: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("Which job are you doing?").font(.title3.bold())
            ScrollView {
                VStack(spacing: 10) {
                    ForEach(offer.others) { v in
                        row(title: v.what, sub: v.whenLabel + (v.overdue ? " · overdue" : "")) {
                            Button("Start") { Task { await coordinator.start(visitId: v.visitId) } }
                                .buttonStyle(.borderedProminent)
                                .tint(Color.MW.green)
                        }
                    }
                    ForEach(offer.plansWithoutVisit) { p in
                        row(title: p.what, sub: "Nothing booked this week") {
                            Button("Add today") { Task { await coordinator.extraWork(planId: p.planId) } }
                                .buttonStyle(.bordered)
                        }
                    }
                }
            }
            Button("‹ Back") { coordinator.back() }
                .buttonStyle(.borderless)
        }
    }

    private func row<B: View>(title: String, sub: String, @ViewBuilder button: () -> B) -> some View {
        HStack(spacing: 10) {
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.subheadline.weight(.semibold))
                Text(sub).font(.caption).foregroundStyle(.secondary)
            }
            Spacer(minLength: 4)
            button().controlSize(.small)
        }
        .padding(10)
        .background(Color.MW.light, in: RoundedRectangle(cornerRadius: 10))
    }

    private func working(_ m: String) -> some View {
        HStack(spacing: 10) {
            ProgressView()
            Text(m).font(.subheadline)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 30)
    }

    private func result(_ m: String, ok: Bool) -> some View {
        VStack(spacing: 14) {
            Image(systemName: ok ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
                .font(.system(size: 40))
                .foregroundStyle(ok ? Color.MW.green : .orange)
            Text(m)
                .font(.headline)
                .multilineTextAlignment(.center)
                .fixedSize(horizontal: false, vertical: true)
            HStack(spacing: 10) {
                if !ok {
                    Button("‹ Back") { coordinator.back() }.buttonStyle(.bordered)
                }
                Button(ok ? "Done" : "Close") { coordinator.close() }
                    .buttonStyle(.borderedProminent)
                    .tint(Color.MW.green)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 10)
    }
}
