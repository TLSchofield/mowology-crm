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
            return try await self.replayQueuedReceipt(data, lat: lat, lng: lng, jobId: jobId)
        }
    }

    /// Drain pending receipts whenever the receipts view appears or the app foregrounds.
    /// NWPathMonitor only fires on network status *changes*; this covers the case where
    /// the network was up the whole time but a single upload timed out and was enqueued.
    func drainPendingQueue() async {
        let before = ReceiptQueue.shared.pendingCount
        await ReceiptQueue.shared.drain { [weak self] data, lat, lng, jobId in
            guard let self else { throw APIError.invalidURL }
            return try await self.replayQueuedReceipt(data, lat: lat, lng: lng, jobId: jobId)
        }
        if before > 0 && ReceiptQueue.shared.pendingCount < before {
            await loadExpenses()
        }
    }

    /// Replays one queued receipt: upload + OCR, then immediately auto-save a draft
    /// expense from whatever OCR found. Previously the drain discarded the intake
    /// result, so a receipt queued offline became an orphaned image with no expense
    /// row — nothing ever appeared in the list and the crew member had no idea. The
    /// draft is flagged in its description so it's obvious it needs a human look.
    private func replayQueuedReceipt(_ data: Data, lat: Double?, lng: Double?, jobId: Int?) async throws -> ReceiptIntakeResponse {
        let intake = try await apiClient.uploadReceipt(imageData: data, lat: lat, lng: lng, jobId: jobId)
        let p = intake.parsed
        let s = intake.suggestions
        let fmt = DateFormatter(); fmt.dateFormat = "yyyy-MM-dd"
        let total = p?.totalDouble ?? 0
        let gst   = p?.gstDouble ?? 0
        let pst   = p?.pst.flatMap(Double.init) ?? 0
        let draft = ExpenseDraft(
            vendorId:      s?.vendorId,
            vendorName:    s?.vendorName ?? p?.vendorHint ?? "",
            date:          Self.normalizeDate(p?.date) ?? fmt.string(from: .now),
            amount:        p?.subtotal.flatMap(Double.init) ?? max(0, total - gst - pst),
            gst:           gst,
            pst:           pst,
            total:         total,
            category:      s?.accountingCategory ?? "",
            paymentMethod: p?.paymentMethod ?? "credit_card",
            description:   "Auto-saved from offline queue — please review",
            notes:         "",
            mediaId:       intake.mediaId,
            rawOcrText:    intake.ocrText,
            ocrParsed:     p,
            job:           nil,
            lat:           lat,
            lng:           lng
        )
        // A failed auto-save must not re-queue the image (that would upload a second
        // media row every retry); the media row + SHA-256 duplicate guard remain, and
        // the error is surfaced on the list instead.
        if await saveExpense(draft) == nil {
            listError = "A queued receipt uploaded but could not be saved as a draft: \(saveError ?? "unknown error")"
        }
        return intake
    }

    private static func normalizeDate(_ s: String?) -> String? {
        guard let s, !s.isEmpty else { return nil }
        let out = DateFormatter(); out.dateFormat = "yyyy-MM-dd"
        let f = DateFormatter()
        for fmt in ["yyyy-MM-dd", "MM/dd/yyyy", "dd/MM/yyyy"] {
            f.dateFormat = fmt
            if let d = f.date(from: s) { return out.string(from: d) }
        }
        return nil
    }

    // MARK: - Review-form lookups (vendors / jobs / categories / duplicates)

    @Published var accountingCategories: [String] = []
    @Published var paymentMethods: [String] = []

    /// Loads the canonical category + payment-method lists (same source the Android
    /// review card reads). Falls back to whatever is already loaded on failure.
    func loadCategories() async {
        guard accountingCategories.isEmpty else { return }
        let q = [URLQueryItem(name: "type", value: "categories")]
        if let r: ExpenseCategoriesResponse = try? await apiClient.request(.expenseLookup(query: q)), r.success {
            accountingCategories = r.accountingCategories
            paymentMethods      = r.paymentMethods
        }
    }

    func searchVendors(_ query: String) async -> [VendorSearchResult] {
        let q = [URLQueryItem(name: "type", value: "vendors"), URLQueryItem(name: "q", value: query)]
        let r: VendorSearchResponse? = try? await apiClient.request(.expenseLookup(query: q))
        return r?.vendors ?? []
    }

    func searchJobs(_ query: String) async -> [JobSearchResult] {
        let q = [URLQueryItem(name: "type", value: "jobs"), URLQueryItem(name: "q", value: query)]
        let r: JobSearchResponse? = try? await apiClient.request(.expenseLookup(query: q))
        return r?.jobs ?? []
    }

    /// Same amount/date/vendor duplicate check the Android review card runs before save.
    func checkDuplicates(total: Double, date: String, vendorName: String?, vendorId: Int?) async -> [DuplicateExpense] {
        guard total > 0 else { return [] }
        var q = [
            URLQueryItem(name: "type", value: "duplicates"),
            URLQueryItem(name: "total", value: String(format: "%.2f", total)),
            URLQueryItem(name: "expense_date", value: date),
        ]
        if let vendorName, vendorName.count >= 2 { q.append(URLQueryItem(name: "vendor_name", value: vendorName)) }
        if let vendorId { q.append(URLQueryItem(name: "vendor_id", value: "\(vendorId)")) }
        let r: DuplicateCheckResponse? = try? await apiClient.request(.expenseLookup(query: q))
        return r?.duplicates ?? []
    }

    // MARK: - Saved line items (edit / add / delete / link — each teaches the parser)

    func loadLineItems(expenseId: Int) async -> LineItemsResponse? {
        try? await apiClient.request(.expenseLineItems(expenseId: expenseId))
    }

    private func lineItemMutation(_ body: [String: Any]) async -> LineItemMutationResponse? {
        actionError = nil
        do {
            let r: LineItemMutationResponse = try await apiClient.request(.expenseLineItemAction, body: body)
            if r.success { return r }
            actionError = r.error ?? "Line item update failed."
        } catch let err as APIError {
            actionError = err.errorDescription
        } catch {
            actionError = "Line item update failed."
        }
        return nil
    }

    func updateLineItem(id: Int, name: String, quantity: Double?, unitPrice: Double?, lineTotal: Double?) async -> StoredLineItem? {
        var body: [String: Any] = ["op": "update", "line_item_id": id, "name": name]
        if let quantity  { body["quantity"]   = quantity }
        if let unitPrice { body["unit_price"] = unitPrice }
        if let lineTotal { body["line_total"] = lineTotal }
        return await lineItemMutation(body)?.lineItem
    }

    func addLineItem(expenseId: Int, name: String, lineTotal: Double, productId: Int?) async -> StoredLineItem? {
        var body: [String: Any] = ["op": "add", "expense_id": expenseId, "name": name, "quantity": 1, "line_total": lineTotal]
        if let productId { body["product_id"] = productId }
        return await lineItemMutation(body)?.lineItem
    }

    func deleteLineItem(id: Int) async -> Bool {
        await lineItemMutation(["op": "delete", "line_item_id": id]) != nil
    }

    func linkLineItem(id: Int, productId: Int?) async -> StoredLineItem? {
        var body: [String: Any] = ["op": "link", "line_item_id": id]
        body["product_id"] = productId ?? NSNull()
        return await lineItemMutation(body)?.lineItem
    }

    func searchProducts(_ query: String) async -> [ProductSearchResult] {
        let q = [URLQueryItem(name: "type", value: "products"), URLQueryItem(name: "q", value: query)]
        let r: ProductSearchResponse? = try? await apiClient.request(.expenseLookup(query: q))
        return r?.products ?? []
    }

    // MARK: - Delete

    /// Delete an own draft (admins any). Server refuses anything already forwarded.
    func deleteExpense(id: Int) async -> Bool {
        isPerformingAction = true
        actionError = nil
        defer { isPerformingAction = false }
        do {
            let r: ReceiptActionResponse = try await apiClient.request(.expenseDelete, body: ["id": id])
            if r.success {
                expenses.removeAll { $0.id == id }
                return true
            }
            actionError = r.error ?? r.message ?? "Delete failed."
        } catch let err as APIError {
            actionError = err.errorDescription
        } catch {
            actionError = "Delete failed."
        }
        return false
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

    /// Saves a reviewed (or auto-drafted) expense. Returns the server response on
    /// success — `sent`/`sendError` matter for a Save & Send — or nil on failure with
    /// `saveError` set. Payload mirrors Android's mobileSaveExpense() field-for-field.
    @discardableResult
    func saveExpense(_ d: ExpenseDraft) async -> ExpenseSaveResponse? {
        isSaving   = true
        saveError  = nil

        var body: [String: Any] = [
            "expense_date":         d.date,
            "vendor_name_raw":      d.vendorName,
            "amount":               d.amount,
            "gst_amount":           d.gst,
            "pst_amount":           d.pst,
            "total":                d.total,
            "accounting_category":  d.category,
            "payment_method":       d.paymentMethod,
            "description":          d.description,
            "notes":                d.notes,
            // Mobile is capture-only: the receipt is submitted for desktop review, where
            // the admin corrects it (the parser's learning signal) and sends to accounting.
            "status":               "pending_approval",
            "and_send":             false,
        ]
        if let vendorId = d.vendorId { body["vendor_id"]        = vendorId }
        if let mediaId  = d.mediaId  { body["receipt_media_id"] = mediaId  }
        if let lat      = d.lat      { body["receipt_lat"]      = lat      }
        if let lng      = d.lng      { body["receipt_lng"]      = lng      }
        if let job      = d.job {
            body["job_id"] = job.id
            if let p = job.propertyId { body["property_id"] = p }
            if let c = job.contactId  { body["contact_id"]  = c }
        }
        // Raw OCR text is what makes the server's self-learning parser record
        // corrections (it requires raw_ocr_json AND ocr_parsed) — and what lets a later
        // edit re-parse. Android always sent it; iOS never did.
        if let raw = d.rawOcrText, !raw.isEmpty { body["raw_ocr_json"] = raw }
        if let p = d.ocrParsed {
            // Store OCR parsed fields so the backend can record corrections for learning
            if let data = try? JSONEncoder().encode(p),
               let str  = String(data: data, encoding: .utf8) {
                body["ocr_parsed"] = str
            }
        }
        // Line items as reviewed on the card: renames, "not an item" flags and manual
        // additions all travel with ocr_name so the server records per-vendor lessons —
        // same payload shape as the Android review card.
        let items = d.lineItems ?? d.ocrParsed?.lineItems ?? []
        if !items.isEmpty {
            body["line_items"] = items.map { item -> [String: Any] in
                var dict: [String: Any] = [
                    "name":       item.name ?? "Unknown Item",
                    "ocr_name":   item.ocrName ?? "",
                    "removed":    item.removed,
                    "manual":     item.manual,
                    "quantity":   item.quantity ?? 1,
                    "line_total": item.amountDouble ?? 0,
                ]
                if let unitPrice = item.unitPrice { dict["unit_price"] = unitPrice }
                if let sku = item.skuRaw { dict["sku_raw"] = sku }
                if let pid = item.productId { dict["product_id"] = pid }
                return dict
            }
        }
        if let src = d.lineItemsSource ?? d.ocrParsed?.lineItemsSource { body["line_items_source"] = src }

        do {
            let response: ExpenseSaveResponse = try await apiClient.request(.expenseSave, body: body)
            await loadExpenses()
            isSaving = false
            return response
        } catch let err as APIError {
            saveError = err.errorDescription
        } catch {
            saveError = "Failed to save expense."
        }
        isSaving = false
        return nil
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
