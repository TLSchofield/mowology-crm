//
//  ReceiptQueue.swift
//  MowologyCRM
//
//  Disk-backed offline queue for receipt uploads.
//  Images live in QueueStorage (Application Support — not purgeable; the legacy temporary-
//  directory location is still read so items queued by an older build drain); metadata is
//  stored in UserDefaults. NWPathMonitor drains the queue automatically on reconnect.
//
//  Review round 2 (2026-10-12):
//   - the JPEG used to sit in temporaryDirectory, written with try?, and the entry was deleted
//     when iOS had purged the file — a receipt lost without a word. Now a failed write is
//     never queued (enqueue returns false) and the bytes are in Application Support.
//   - a 4xx answer (the server refusing this photo) is permanent: the item is set aside with
//     the server's reason and the drain goes on to the next one, instead of every later
//     receipt waiting behind it forever. No signal / 5xx stops the drain to retry later.
//   - the photo uploads, the expense save fails → the item stays, with what the upload
//     returned (OwedDraft), and only the save is retried — the photo isn't re-uploaded and
//     isn't left orphaned.
//   - 2026-11-08 (capture review): the photo is written at the shutter and held while the screen uses it, so a killed
//     app can't lose it; a refusal keeps the photo for Retry / Discard; one failing receipt no longer blocks the rest.
//   - each item carries the user who captured it (nil = queued by a build before 1.3.28);
//     only the signed-in person's receipts drain, like ClockQueue.
//

import Foundation
import Network

@MainActor
final class ReceiptQueue: ObservableObject {

    static let shared = ReceiptQueue()

    /// Pending receipts for the person signed in (set by the receipts screen).
    @Published private(set) var pendingCount: Int = 0
    /// What the person can see and act on: their waiting receipts, oldest first, minus the one being sent this moment.
    @Published private(set) var waiting: [PendingItem] = []
    /// The last receipt the server refused, in plain words — shown on the receipts screen.
    @Published private(set) var lastProblem: String?

    /// Who is signed in. Only their receipts (and untagged legacy ones) are counted and drained.
    var activeUserId: Int? { didSet { updateCount() } }
    /// Reads who is signed in at drain time, so a reconnect after a sign-out / sign-in never
    /// sends the previous person's items under the new login.
    var userProvider: (() -> Int?)?

    private let defaults       = UserDefaults.standard
    private let storeKey       = "mw.receipt.queue.v1"
    private let problemKey     = "mw.receipt.queue.problem.v1"
    /// Receipts the receipts screen is working on right now: the drain leaves them alone. In memory on purpose (as Android's
    /// ReceiptHold): after a restart nothing is holding them, so what the app was killed over sends by itself.
    private var held: Set<String> = []
    /// Being sent right now: not on the waiting list, and Discard can't reach it mid-send.
    private var inFlight: Set<String> = []
    private let monitor        = NWPathMonitor()
    private var monitorStarted = false
    private var uploadTask: Task<Void, Never>?

    private init() {
        lastProblem = defaults.string(forKey: problemKey)
        updateCount()
    }

    // MARK: - Pending Item

    /// What the upload returned, kept so a failed expense save can be retried without
    /// uploading the photo again.
    struct OwedDraft: Codable {
        var mediaId: Int
        var vendorId: Int?
        var vendorName: String
        var date: String
        var amount: Double
        var gst: Double
        var pst: Double
        var total: Double
        var category: String
        var paymentMethod: String
        var rawOcrText: String?
        var ocrParsed: ParsedReceipt?
        // The decisive schedule job, when the server picked one (older stored items have none).
        var jobId: Int?
        var jobPropertyId: Int?
        var jobContactId: Int?
        var jobTitle: String?
        var jobSubtitle: String?
    }

    struct PendingItem: Codable {
        let id: String
        let imageFilename: String
        var lat: Double?
        var lng: Double?
        let jobId: Int?
        let queuedAt: Date
        var userId: Int?          // nil = queued before 1.3.28
        var owed: OwedDraft?      // photo uploaded; the expense save is still owed
        var refused: String?      // the server's reason: stays here, with its photo, for Retry / Discard
        var attempts: Int?        // failed sends in a row (nil before 1.3.130)
        var nextTry: Date?        // not before this
    }

    private var items: [PendingItem] {
        get {
            guard let data = defaults.data(forKey: storeKey),
                  let decoded = try? JSONDecoder().decode([PendingItem].self, from: data)
            else { return [] }
            return decoded
        }
        set {
            defaults.set(try? JSONEncoder().encode(newValue), forKey: storeKey)
            updateCount()
        }
    }

    private func mine(_ item: PendingItem) -> Bool {
        item.userId == nil || item.userId == activeUserId
    }

    private func updateCount() {
        let visible = items.filter { self.mine($0) && !held.contains($0.id) }
        pendingCount = visible.count
        waiting = visible.filter { !inFlight.contains($0.id) }
    }

    func clearProblem() {
        lastProblem = nil
        defaults.removeObject(forKey: problemKey)
    }

    private func setProblem(_ text: String) {
        lastProblem = text
        defaults.set(text, forKey: problemKey)
    }

    // MARK: - Enqueue

    /// Queues a receipt photo. Returns false when the photo could not be written to the phone —
    /// nothing is queued then, and the caller must tell the person.
    @discardableResult
    func enqueue(imageData: Data, lat: Double?, lng: Double?, jobId: Int?, userId: Int?) -> Bool {
        guard !imageData.isEmpty else { return false }
        let id       = UUID().uuidString
        let filename = "receipt-\(id).jpg"
        guard QueueStorage.write(imageData, filename: filename) else { return false }
        var current = items
        current.append(PendingItem(id: id, imageFilename: filename, lat: lat, lng: lng, jobId: jobId,
                                   queuedAt: Date(), userId: userId, owed: nil))
        items = current
        return true
    }

    // MARK: - The receipt on screen

    /// Writes the photo to the phone straight after the shot and holds it for the screen. Nil when it could not be written.
    func reserve(imageData: Data, userId: Int?) -> String? {
        guard !imageData.isEmpty else { return nil }
        let id = UUID().uuidString
        let filename = "receipt-\(id).jpg"
        guard QueueStorage.write(imageData, filename: filename) else { return nil }
        held.insert(id)
        var current = items
        current.append(PendingItem(id: id, imageFilename: filename, lat: nil, lng: nil, jobId: nil,
                                   queuedAt: Date(), userId: userId, owed: nil))
        items = current
        return id
    }

    func setFix(_ id: String, lat: Double?, lng: Double?) {
        items = items.map { $0.id == id ? { var c = $0; c.lat = lat; c.lng = lng; return c }($0) : $0 }
    }

    /// The upload worked: only the expense save is owed from here on, whatever happens to the app.
    func setOwed(_ id: String, owed: OwedDraft) {
        items = items.map { $0.id == id ? { var c = $0; c.owed = owed; return c }($0) : $0 }
    }

    /// The screen is done with it and it still has to go: the drain takes it from here.
    func release(_ id: String) {
        held.remove(id)
        updateCount()
    }

    /// When the shot was taken: the date a receipt with no readable date falls back to.
    func takenAt(_ id: String?) -> Date? {
        guard let id else { return nil }
        return items.first(where: { $0.id == id })?.queuedAt
    }

    /// Saved, or the person threw it away. Never one the drain is sending this moment.
    func finish(_ id: String) {
        guard !inFlight.contains(id) else { return }
        held.remove(id)
        guard let item = items.first(where: { $0.id == id }) else { updateCount(); return }
        remove(item)
    }

    /// The server said no to this one: kept, with its photo and the reason.
    func refuse(_ id: String, reason: String) {
        held.remove(id)
        items = items.map { $0.id == id ? { var c = $0; c.refused = reason; return c }($0) : $0 }
    }

    /// Retry from the waiting list.
    func retry(_ id: String) {
        items = items.map { $0.id == id ? { var c = $0; c.refused = nil; c.attempts = 0; c.nextTry = nil; return c }($0) : $0 }
    }

    // MARK: - Errors

    /// The server's own refusal (a 4xx it decided on) — retrying won't change it. nil = try again later.
    static func permanentReason(_ error: Error) -> String? {
        if case APIError.serverError(let msg)? = error as? APIError { return msg }
        return nil
    }

    /// No signal at all: the rest would fail the same way, so the drain stops. Any other failure is about this receipt.
    static func isOffline(_ error: Error) -> Bool {
        if case APIError.unauthorized? = error as? APIError { return true }   // signed out: nothing can send
        guard case APIError.networkError(let inner)? = error as? APIError, let url = inner as? URLError else { return false }
        return [.notConnectedToInternet, .networkConnectionLost, .cannotFindHost, .cannotConnectToHost, .dnsLookupFailed,
                .dataNotAllowed, .internationalRoamingOff, .callIsActive].contains(url.code)
    }

    /// Minutes before trying this receipt again, after this many failures in a row. Pure.
    nonisolated static func backoffMinutes(attempts: Int) -> Int {
        [1, 5, 15, 60][min(max(attempts, 1), 4) - 1]
    }

    // MARK: - Monitor & Drain

    /// The last Date is when the receipt was taken (queuedAt): the fallback when the scan finds no date.
    typealias Upload = (Data, Double?, Double?, Int?, Date) async throws -> OwedDraft
    typealias Save   = (OwedDraft, Double?, Double?, Int?) async throws -> Void

    func startMonitoring(upload: @escaping Upload, save: @escaping Save) {
        // Re-set the handler so the latest apiClient closure is always used,
        // but only start the monitor once (NWPathMonitor asserts on double-start).
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor [weak self] in
                await self?.drain(upload: upload, save: save)
            }
        }
        guard !monitorStarted else { return }
        monitorStarted = true
        let queue = DispatchQueue(label: "mw.receipt.monitor", qos: .utility)
        monitor.start(queue: queue)
    }

    func drain(upload: @escaping Upload, save: @escaping Save) async {
        guard uploadTask == nil else { return }
        if let userProvider { activeUserId = userProvider() }
        guard activeUserId != nil else { return }   // nobody signed in: nothing drains
        uploadTask = Task {
            let now = Date()
            let pending = items.filter { self.mine($0) && $0.refused == nil && ($0.nextTry ?? now) <= now }
            for item in pending where !held.contains(item.id) {
                guard items.contains(where: { $0.id == item.id }) else { continue }   // discarded while waiting its turn
                inFlight.insert(item.id)
                updateCount()
                defer { inFlight.remove(item.id); updateCount() }
                var owed = item.owed
                do {
                    if owed == nil {
                        guard let data = QueueStorage.read(item.imageFilename) else {
                            // The photo is gone from the phone: say so instead of dropping it silently.
                            setProblem("A receipt photo queued \(Self.when(item.queuedAt)) could no longer be found on this phone — please snap it again.")
                            remove(item)
                            continue
                        }
                        owed = try await upload(data, item.lat, item.lng, item.jobId, item.queuedAt)
                        items = items.map { $0.id == item.id ? { var c = $0; c.owed = owed; return c }($0) : $0 }
                    }
                    if let owed {
                        try await save(owed, item.lat, item.lng, item.jobId)
                    }
                    remove(item)
                } catch {
                    if let reason = Self.permanentReason(error) {
                        // The server refused this one: it stays, with its photo, and the rest go on.
                        let what = owed == nil ? "wasn't accepted" : "uploaded but couldn't be saved"
                        setProblem("A receipt queued \(Self.when(item.queuedAt)) \(what): \(reason). It is still on this phone.")
                        refuse(item.id, reason: reason)
                        continue
                    }
                    if Self.isOffline(error) { break }   // no signal or signed out: keep it and the rest for next time
                    // A fault with this one (server error, timeout): try it later, and let the others past.
                    let tries = (item.attempts ?? 0) + 1
                    let later = Date().addingTimeInterval(Double(Self.backoffMinutes(attempts: tries)) * 60)
                    items = items.map { $0.id == item.id ? { var c = $0; c.attempts = tries; c.nextTry = later; return c }($0) : $0 }
                }
            }
            uploadTask = nil
        }
        await uploadTask?.value
    }

    private func remove(_ item: PendingItem) {
        items = items.filter { $0.id != item.id }
        QueueStorage.remove(item.imageFilename)
    }

    private static func when(_ d: Date) -> String {
        let f = DateFormatter()
        f.dateFormat = "MMM d, h:mm a"
        return "on " + f.string(from: d)
    }
}
