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
    }

    struct PendingItem: Codable {
        let id: String
        let imageFilename: String
        let lat: Double?
        let lng: Double?
        let jobId: Int?
        let queuedAt: Date
        var userId: Int?          // nil = queued before 1.3.28
        var owed: OwedDraft?      // photo uploaded; the expense save is still owed
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

    private func updateCount() { pendingCount = items.filter { self.mine($0) }.count }

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

    // MARK: - Errors

    /// The server's own refusal (a 4xx it decided on) — retrying won't change it. nil = try again later.
    static func permanentReason(_ error: Error) -> String? {
        if case APIError.serverError(let msg)? = error as? APIError { return msg }
        return nil
    }

    // MARK: - Monitor & Drain

    typealias Upload = (Data, Double?, Double?, Int?) async throws -> OwedDraft
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
            let pending = items.filter { self.mine($0) }
            for item in pending {
                var owed = item.owed
                do {
                    if owed == nil {
                        guard let data = QueueStorage.read(item.imageFilename) else {
                            // The photo is gone from the phone: say so instead of dropping it silently.
                            setProblem("A receipt photo queued \(Self.when(item.queuedAt)) could no longer be found on this phone — please snap it again.")
                            remove(item)
                            continue
                        }
                        owed = try await upload(data, item.lat, item.lng, item.jobId)
                        items = items.map { $0.id == item.id ? { var c = $0; c.owed = owed; return c }($0) : $0 }
                    }
                    if let owed {
                        try await save(owed, item.lat, item.lng, item.jobId)
                    }
                    remove(item)
                } catch {
                    if let reason = Self.permanentReason(error) {
                        // The server refused this one: set it aside with its reason, go on with the rest.
                        let what = owed == nil ? "wasn't accepted" : "uploaded but couldn't be saved"
                        setProblem("A receipt queued \(Self.when(item.queuedAt)) \(what): \(reason)")
                        remove(item)
                        continue
                    }
                    break   // no signal, server busy, or signed out: keep it and the rest for next time
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
