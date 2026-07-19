//
//  ReceiptsViewModel.swift
//  MowologyCRM
//

import Foundation

@MainActor
final class ReceiptsViewModel: ObservableObject {

    // MARK: - List state
    @Published var expenses: [Expense] = []
    @Published var isLoadingList = false
    @Published var listError: String?
    @Published var currentPage = 1
    @Published var totalPages  = 1

    // MARK: - Upload state
    @Published var isUploading   = false
    @Published var uploadError:   String?
    @Published var intakeResponse: ReceiptIntakeResponse?

    // MARK: - Save state
    @Published var isSaving     = false
    @Published var saveError:    String?

    // MARK: - Action state (approve / reject / send)
    @Published var isPerformingAction = false
    @Published var actionError: String?

    private let apiClient: APIClient

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    // MARK: - Load

    func loadExpenses(page: Int = 1) async {
        isLoadingList = true
        listError     = nil
        do {
            let response: ExpenseListResponse = try await apiClient.request(.expenseList(page: page))
            if page == 1 { expenses = response.expenses }
            else         { expenses += response.expenses }
            currentPage = response.page
            totalPages  = response.pages
        } catch let err as APIError {
            listError = err.errorDescription
        } catch {
            listError = "Failed to load expenses."
        }
        isLoadingList = false
    }

    func loadNextPage() async {
        guard currentPage < totalPages, !isLoadingList else { return }
        await loadExpenses(page: currentPage + 1)
    }

    // MARK: - Offline Queue Monitor

    /// Wires up NWPathMonitor so queued receipts drain automatically on reconnect.
    /// Safe to call multiple times — the monitor ignores duplicate starts.
    func startReceiptQueueMonitor() {
        ReceiptQueue.shared.startMonitoring { [weak self] data, lat, lng, jobId in
            guard let self else { throw APIError.invalidURL }
            return try await self.apiClient.uploadReceipt(imageData: data, lat: lat, lng: lng, jobId: jobId)
        }
    }

    /// Drain pending receipts whenever the receipts view appears or the app foregrounds.
    /// NWPathMonitor only fires on network status *changes*; this covers the case where
    /// the network was up the whole time but a single upload timed out and was enqueued.
    func drainPendingQueue() async {
        await ReceiptQueue.shared.drain { [weak self] data, lat, lng, jobId in
            guard let self else { throw APIError.invalidURL }
            return try await self.apiClient.uploadReceipt(imageData: data, lat: lat, lng: lng, jobId: jobId)
        }
    }

    // MARK: - Upload

    func uploadImage(_ imageData: Data, lat: Double?, lng: Double?) async {
        isUploading   = true
        uploadError   = nil
        intakeResponse = nil
        do {
            intakeResponse = try await apiClient.uploadReceipt(imageData: imageData, lat: lat, lng: lng, jobId: nil)
        } catch let err as APIError {
            // Queue the receipt on any transient/server-side failure so it isn't lost:
            // - .networkError covers offline + URLError.timedOut (our 45s client cap)
            // - .serverError covers 5xx / 504 / hosting PHP kill — same OCR-too-slow story
            // - .decodingError covers a truncated/garbled response from a killed PHP process
            // Only .unauthorized (needs re-login) and .invalidURL (programmer error) skip the queue.
            switch err {
            case .networkError, .serverError, .decodingError:
                ReceiptQueue.shared.enqueue(imageData: imageData, lat: lat, lng: lng, jobId: nil)
                if case .networkError = err {
                    uploadError = "No connection — receipt queued and will upload automatically."
                } else {
                    uploadError = "Upload took too long — receipt saved and will retry automatically."
                }
            case .unauthorized, .invalidURL:
                uploadError = err.errorDescription
            }
        } catch {
            uploadError = "Upload failed."
        }
        isUploading = false
    }

    // MARK: - Save

    func saveExpense(
        vendorId: Int?, vendorName: String, date: String,
        amount: Double, gst: Double, total: Double,
        category: String, paymentMethod: String,
        notes: String, mediaId: Int?,
        ocrParsed: ParsedReceipt?, lat: Double?, lng: Double?
    ) async -> Bool {
        isSaving   = true
        saveError  = nil

        var body: [String: Any] = [
            "expense_date":         date,
            "vendor_name_raw":      vendorName,
            "amount":               amount,
            "gst_amount":           gst,
            "total":                total,
            "accounting_category":  category,
            "payment_method":       paymentMethod,
            "notes":                notes,
            "status":               "draft",
        ]
        if let vendorId  { body["vendor_id"]        = vendorId  }
        if let mediaId   { body["receipt_media_id"]  = mediaId   }
        if let lat       { body["receipt_lat"]        = lat       }
        if let lng       { body["receipt_lng"]        = lng       }
        if let p = ocrParsed {
            // Store OCR parsed fields so the backend can record corrections for learning
            if let data = try? JSONEncoder().encode(p),
               let str  = String(data: data, encoding: .utf8) {
                body["ocr_parsed"] = str
            }
        }

        do {
            let _: ExpenseSaveResponse = try await apiClient.request(.expenseSave, body: body)
            await loadExpenses()
            isSaving = false
            return true
        } catch let err as APIError {
            saveError = err.errorDescription
        } catch {
            saveError = "Failed to save expense."
        }
        isSaving = false
        return false
    }

    // MARK: - Edit

    /// Edit an existing expense's user-correctable fields (fix OCR mistakes from the phone).
    /// Server-side: ownership- and status-guarded, and rejected if already sent to accounting.
    func updateExpense(
        expenseId: Int,
        vendorName: String, date: String,
        amount: Double, gst: Double, total: Double,
        category: String, paymentMethod: String, description: String
    ) async -> Bool {
        isSaving  = true
        saveError = nil
        let body: [String: Any] = [
            "id":                  expenseId,
            "expense_date":        date,
            "vendor_name_raw":     vendorName,
            "amount":              amount,
            "gst_amount":          gst,
            "total":               total,
            "accounting_category": category,
            "payment_method":      paymentMethod,
            "description":         description,
        ]
        do {
            let _: ExpenseSaveResponse = try await apiClient.request(.expenseUpdate, body: body)
            await loadExpenses()
            isSaving = false
            return true
        } catch let err as APIError {
            saveError = err.errorDescription
        } catch {
            saveError = "Failed to update expense."
        }
        isSaving = false
        return false
    }

    // MARK: - Actions (approve / reject / send)

    /// Approve a receipt already saved as an expense. Fails server-side with
    /// "Cannot approve your own expense" if the current user created it.
    func approve(expenseId: Int) async -> Bool {
        await performAction(expenseId: expenseId, action: "approve", reason: nil)
    }

    /// Reject a receipt with a required reason, visible to the creator via the web Review Queue.
    func reject(expenseId: Int, reason: String) async -> Bool {
        await performAction(expenseId: expenseId, action: "reject", reason: reason)
    }

    /// Forward an approved receipt's image to the configured accounting inbox.
    /// Idempotent server-side — a second call on an already-forwarded receipt fails cleanly.
    func send(expenseId: Int) async -> Bool {
        await performAction(expenseId: expenseId, action: "send", reason: nil)
    }

    // MARK: - Bulk actions (multi-select)

    /// Approve multiple receipts in one call. A partial failure (e.g. one self-approval
    /// attempt in a mixed batch) still returns true — the surviving items are approved
    /// and actionError surfaces a summary of what failed, mirroring the Android bulk bar.
    func batchApprove(expenseIds: [Int]) async -> Bool {
        await performBatchAction(expenseIds: expenseIds, action: "batch_approve", reason: nil)
    }

    /// Reject multiple receipts with the same reason in one call.
    func batchReject(expenseIds: [Int], reason: String) async -> Bool {
        await performBatchAction(expenseIds: expenseIds, action: "batch_reject", reason: reason)
    }

    private func performBatchAction(expenseIds: [Int], action: String, reason: String?) async -> Bool {
        isPerformingAction = true
        actionError = nil
        var body: [String: Any] = ["action": action, "expense_ids": expenseIds]
        if let reason { body["rejection_reason"] = reason }
        do {
            let response: BatchActionResponse = try await apiClient.request(.receiptAction, body: body)
            if response.success {
                await loadExpenses()
                isPerformingAction = false
                if let failed = response.failed, !failed.isEmpty {
                    let succeededCount = (response.approved ?? response.rejected ?? []).count
                    actionError = "\(succeededCount) succeeded, \(failed.count) failed: \(failed[0].error)"
                }
                return true
            }
            actionError = response.error ?? "Bulk action failed."
        } catch let err as APIError {
            actionError = err.errorDescription
        } catch {
            actionError = "Bulk action failed."
        }
        isPerformingAction = false
        return false
    }

    // MARK: - Archive & export

    /// Manually triggers ReceiptArchiveService — the same run the desktop admin has via
    /// receipt-export.php, now reachable from the phone. Permission-gated server-side
    /// (expenses.send) given the CRA-retention stakes of removing originals from disk.
    func archiveExport() async -> Bool {
        isPerformingAction = true
        actionError = nil
        do {
            let response: ArchiveExportResponse = try await apiClient.request(.receiptAction, body: ["action": "archive_export"])
            isPerformingAction = false
            if response.success {
                await loadExpenses()
                return true
            }
            actionError = response.error ?? "Archive failed."
        } catch let err as APIError {
            actionError = err.errorDescription
            isPerformingAction = false
        } catch {
            actionError = "Archive failed."
            isPerformingAction = false
        }
        return false
    }

    // MARK: - Offline Action Queue Monitor

    /// Wires up NWPathMonitor so queued approve/reject/send actions drain automatically
    /// on reconnect. Safe to call multiple times — the monitor ignores duplicate starts.
    func startActionQueueMonitor() {
        ReceiptActionQueue.shared.startMonitoring { [weak self] expenseId, action, reason in
            guard let self else { throw APIError.invalidURL }
            return try await self.sendAction(expenseId: expenseId, action: action, reason: reason)
        }
    }

    /// Drain pending actions whenever the receipts view appears or the app foregrounds —
    /// mirrors drainPendingQueue()'s coverage for the case where connectivity was up the
    /// whole time but a single call timed out and was enqueued.
    func drainPendingActionQueue() async {
        await ReceiptActionQueue.shared.drain { [weak self] expenseId, action, reason in
            guard let self else { throw APIError.invalidURL }
            return try await self.sendAction(expenseId: expenseId, action: action, reason: reason)
        }
        await loadExpenses()
    }

    private func performAction(expenseId: Int, action: String, reason: String?) async -> Bool {
        isPerformingAction = true
        actionError = nil
        do {
            let response = try await sendAction(expenseId: expenseId, action: action, reason: reason)
            if response.success {
                await loadExpenses()
                isPerformingAction = false
                return true
            }
            actionError = response.error ?? response.message ?? "Action failed."
        } catch let err as APIError {
            // Same policy as uploadImage(): queue on transient/server-side failure so
            // the tap isn't lost, surface a clear message, don't queue on programmer/auth errors.
            switch err {
            case .networkError, .serverError, .decodingError:
                ReceiptActionQueue.shared.enqueue(expenseId: expenseId, action: action, reason: reason)
                if case .networkError = err {
                    actionError = "No connection — this will be applied automatically when you reconnect."
                } else {
                    actionError = "That took too long — queued and will retry automatically."
                }
            case .unauthorized, .invalidURL:
                actionError = err.errorDescription
            }
        } catch {
            actionError = "Action failed."
        }
        isPerformingAction = false
        return false
    }

    private func sendAction(expenseId: Int, action: String, reason: String?) async throws -> ReceiptActionResponse {
        var body: [String: Any] = ["action": action, "expense_id": expenseId]
        if let reason { body["rejection_reason"] = reason }
        return try await apiClient.request(.receiptAction, body: body)
    }
}
