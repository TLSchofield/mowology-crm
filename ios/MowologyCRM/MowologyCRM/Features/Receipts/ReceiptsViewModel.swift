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
    /// Result of a "Label" capture (product bag / nameplate) — read-only on the phone.
    @Published var labelResult: LabelCaptureResponse?

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
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            listError = ErrorDetails.describe(error)
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
        ReceiptQueue.shared.activeUserId = apiClient.currentUserId
        ReceiptQueue.shared.userProvider = { [weak apiClient] in apiClient?.currentUserId }
        ReceiptQueue.shared.startMonitoring(
            upload: { [weak self] data, lat, lng, jobId in
                guard let self else { throw APIError.invalidURL }
                return try await self.replayUpload(data, lat: lat, lng: lng, jobId: jobId)
            },
            save: { [weak self] owed, lat, lng, _ in
                guard let self else { throw APIError.invalidURL }
                try await self.replaySave(owed, lat: lat, lng: lng)
            })
    }

    /// Drain pending receipts whenever the receipts view appears or the app foregrounds.
    /// NWPathMonitor only fires on network status *changes*; this covers the case where
    /// the network was up the whole time but a single upload timed out and was enqueued.
    func drainPendingQueue() async {
        ReceiptQueue.shared.activeUserId = apiClient.currentUserId
        let before = ReceiptQueue.shared.pendingCount
        await ReceiptQueue.shared.drain(
            upload: { [weak self] data, lat, lng, jobId in
                guard let self else { throw APIError.invalidURL }
                return try await self.replayUpload(data, lat: lat, lng: lng, jobId: jobId)
            },
            save: { [weak self] owed, lat, lng, _ in
                guard let self else { throw APIError.invalidURL }
                try await self.replaySave(owed, lat: lat, lng: lng)
            })
        if before > 0 && ReceiptQueue.shared.pendingCount < before {
            await loadExpenses()
        }
    }

    /// Replays one queued receipt, step 1: upload + OCR. What it returns is kept on the queue
    /// item (OwedDraft) so a failed save is retried without uploading the photo again — the
    /// item used to be removed and its photo deleted when the save failed (review round 2).
    private func replayUpload(_ data: Data, lat: Double?, lng: Double?, jobId: Int?) async throws -> ReceiptQueue.OwedDraft {
        let intake = try await apiClient.uploadReceipt(imageData: data, lat: lat, lng: lng, jobId: jobId)
        let p = intake.parsed
        let s = intake.suggestions
        let fmt = DateFormatter(); fmt.dateFormat = "yyyy-MM-dd"
        let total = p?.totalDouble ?? 0
        let gst   = p?.gstDouble ?? 0
        let pst   = p?.pst.flatMap { ReceiptNumber.parse($0) } ?? 0
        return ReceiptQueue.OwedDraft(
            mediaId:       intake.mediaId,
            vendorId:      s?.vendorId,
            vendorName:    s?.vendorName ?? p?.vendorHint ?? "",
            date:          Self.normalizeDate(p?.date) ?? fmt.string(from: .now),
            amount:        p?.subtotal.flatMap { ReceiptNumber.parse($0) } ?? max(0, total - gst - pst),
            gst:           gst,
            pst:           pst,
            total:         total,
            category:      s?.accountingCategory ?? "",
            paymentMethod: p?.paymentMethod ?? "credit_card",
            rawOcrText:    intake.ocrText,
            ocrParsed:     p
        )
    }

    /// Step 2: save the draft expense from what the upload read. The draft is flagged in its
    /// description so it's obvious it needs a human look. Throws so the queue keeps the item
    /// (no signal / 5xx) or sets it aside with the server's reason (4xx).
    private func replaySave(_ o: ReceiptQueue.OwedDraft, lat: Double?, lng: Double?) async throws {
        let draft = ExpenseDraft(
            vendorId:      o.vendorId,
            vendorName:    o.vendorName,
            date:          o.date,
            amount:        o.amount,
            gst:           o.gst,
            pst:           o.pst,
            total:         o.total,
            category:      o.category,
            paymentMethod: o.paymentMethod,
            description:   "Auto-saved from offline queue — please review",
            notes:         "",
            mediaId:       o.mediaId,
            rawOcrText:    o.rawOcrText,
            ocrParsed:     o.ocrParsed,
            job:           nil,
            lat:           lat,
            lng:           lng
        )
        _ = try await postExpense(draft, faultsAreTransient: true)
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

    /// Penny's reasons for a receipt's risk score (admins). Nil on any error — the view
    /// falls back to the bare ring.
    func loadRisk(expenseId: Int) async -> ReceiptRiskResponse? {
        try? await apiClient.request(.bookkeeperRisk(expenseId: expenseId))
    }

    /// Penny's read on this receipt where it differs from it (nil = nothing of hers to show,
    /// or it couldn't be loaded — the receipt works without it).
    func loadPennyRead(expenseId: Int) async -> PennyRead? {
        let r: PennyReadResponse? = try? await apiClient.request(.bookkeeperSuggestion(expenseId: expenseId))
        return r?.ok == true ? r?.read : nil
    }

    /// "Use Penny's version" (useHers) or "Keep mine". The server decides who may do which
    /// (PennyReadService::rights) and whether applying also approves. Refreshes the list on success.
    func decidePennyRead(expenseId: Int, useHers: Bool) async -> BookkeeperActionResponse? {
        isPerformingAction = true
        defer { isPerformingAction = false }
        do {
            let r: BookkeeperActionResponse = try await apiClient.request(
                .bookkeeperAction, body: ["mode": useHers ? "penny_apply" : "penny_keep", "expense_id": expenseId])
            if r.ok {
                await loadExpenses()
            } else {
                actionError = r.message ?? r.error ?? "Bookkeeper action response ok=false; server returned no message/error field."
            }
            return r
        } catch {
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            actionError = ErrorDetails.describe(error)
            return nil
        }
    }

    /// The saved items, or the reason they couldn't be loaded — never a silent empty list
    /// (a failed load used to read "No line items recorded").
    func loadLineItems(expenseId: Int) async -> Result<LineItemsResponse, Error> {
        do {
            let r: LineItemsResponse = try await apiClient.request(.expenseLineItems(expenseId: expenseId))
            return .success(r)
        } catch {
            return .failure(error)
        }
    }

    /// Read the receipt photo again (server OCR, up to ~45 s). Only unlinked lines are replaced.
    func rescanLineItems(expenseId: Int) async -> Result<LineItemRescanResponse, Error> {
        do {
            let r: LineItemRescanResponse = try await apiClient.request(.expenseLineItemAction,
                                                                        body: ["op": "rescan", "expense_id": expenseId],
                                                                        timeout: 75)
            if r.success { return .success(r) }
            return .failure(APIError.serverError(r.error ?? "Receipt rescan response success=false; server returned no error field."))
        } catch {
            return .failure(error)
        }
    }

    private func lineItemMutation(_ body: [String: Any]) async -> LineItemMutationResponse? {
        actionError = nil
        do {
            let r: LineItemMutationResponse = try await apiClient.request(.expenseLineItemAction, body: body)
            if r.success { return r }
            actionError = r.error ?? "Line item update response success=false; server returned no error field."
        } catch let err as APIError {
            actionError = err.errorDescription
        } catch {
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            actionError = ErrorDetails.describe(error)
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
            actionError = r.error ?? r.message ?? "Expense delete response success=false; server returned no error/message field."
        } catch let err as APIError {
            actionError = err.errorDescription
        } catch {
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            actionError = ErrorDetails.describe(error)
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
            // Queue the receipt only when the server didn't decide (review round 2):
            // - .networkError: offline, our 45s cap, 5xx / PHP kill, a truncated answer (UploadFault)
            // - .decodingError: kept as transient for safety
            // A .serverError is the server's answer about this photo (a 4xx) — show it, don't queue
            // it (it would fail the same way forever and block the queue).
            switch err {
            case .networkError, .decodingError:
                if ReceiptQueue.shared.enqueue(imageData: imageData, lat: lat, lng: lng, jobId: nil, userId: apiClient.currentUserId) {
                    if case .networkError(let inner) = err, !(inner is UploadFault) {
                        uploadError = "No connection — receipt queued and will upload automatically."
                    } else {
                        uploadError = "Upload took too long — receipt saved and will retry automatically."
                    }
                } else {
                    uploadError = "Couldn't save the receipt photo on this phone (storage full?) — please try again."
                }
            case .serverError(let msg):
                uploadError = msg
            case .unauthorized, .invalidURL:
                uploadError = err.errorDescription
            }
        } catch {
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            uploadError = ErrorDetails.describe(error)
        }
        isUploading = false
    }

    /// "Label" mode: a product bag or a machine's nameplate. Never an expense and never
    /// queued offline (it isn't money) — a failure just asks to try again.
    func uploadLabel(_ imageData: Data, lat: Double?, lng: Double?) async {
        isUploading = true
        uploadError = nil
        labelResult = nil
        do {
            labelResult = try await apiClient.uploadLabel(imageData: imageData, lat: lat, lng: lng)
        } catch let err as APIError {
            if case .networkError = err {
                uploadError = "No connection — the label photo wasn't sent. Try again when you have signal."
            } else {
                uploadError = ErrorDetails.describe(err)
            }
        } catch {
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            uploadError = ErrorDetails.describe(error)
        }
        isUploading = false
    }

    // MARK: - Save

    /// Saves a reviewed (or auto-drafted) expense. Returns the server response on
    /// success — `sent`/`sendError` matter for a Save & Send — or nil on failure with
    /// `saveError` set. Payload mirrors Android's mobileSaveExpense() field-for-field.
    @discardableResult
    private func expenseBody(_ d: ExpenseDraft) -> [String: Any] {
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
        return body
    }

    /// Posts the save; throws APIError (with faultsAreTransient, 5xx / unreadable answers come
    /// back as .networkError so the offline queue retries them, and a 4xx is a .serverError).
    private func postExpense(_ d: ExpenseDraft, faultsAreTransient: Bool = false) async throws -> ExpenseSaveResponse {
        try await apiClient.request(.expenseSave, body: expenseBody(d), faultsAreTransient: faultsAreTransient)
    }

    func saveExpense(_ d: ExpenseDraft) async -> ExpenseSaveResponse? {
        isSaving   = true
        saveError  = nil
        do {
            let response = try await postExpense(d)
            await loadExpenses()
            isSaving = false
            return response
        } catch let err as APIError {
            saveError = err.errorDescription
        } catch {
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            saveError = ErrorDetails.describe(error)
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
        amount: Double, gst: Double, pst: Double, total: Double,
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
            "pst_amount":          pst,
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
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            saveError = ErrorDetails.describe(error)
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
            actionError = response.error ?? "Receipt bulk action response success=false; server returned no error field."
        } catch let err as APIError {
            actionError = err.errorDescription
        } catch {
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            actionError = ErrorDetails.describe(error)
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
            actionError = response.error ?? "Receipt archive response success=false; server returned no error field."
        } catch let err as APIError {
            actionError = err.errorDescription
            isPerformingAction = false
        } catch {
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            actionError = ErrorDetails.describe(error)
            isPerformingAction = false
        }
        return false
    }

    // MARK: - Offline Action Queue Monitor

    /// Wires up NWPathMonitor so queued approve/reject/send actions drain automatically
    /// on reconnect. Safe to call multiple times — the monitor ignores duplicate starts.
    func startActionQueueMonitor() {
        ReceiptActionQueue.shared.activeUserId = apiClient.currentUserId
        ReceiptActionQueue.shared.userProvider = { [weak apiClient] in apiClient?.currentUserId }
        ReceiptActionQueue.shared.startMonitoring { [weak self] expenseId, action, reason in
            guard let self else { throw APIError.invalidURL }
            return try await self.sendAction(expenseId: expenseId, action: action, reason: reason)
        }
    }

    /// Drain pending actions whenever the receipts view appears or the app foregrounds —
    /// mirrors drainPendingQueue()'s coverage for the case where connectivity was up the
    /// whole time but a single call timed out and was enqueued.
    func drainPendingActionQueue() async {
        ReceiptActionQueue.shared.activeUserId = apiClient.currentUserId
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
            actionError = response.error ?? response.message ?? "Receipt action response success=false; server returned no error/message field."
        } catch let err as APIError {
            // Same policy as uploadImage(): queue only when the server didn't decide, so the tap
            // isn't lost; a 4xx refusal is shown, never queued (it would block the queue).
            switch err {
            case .networkError, .decodingError:
                ReceiptActionQueue.shared.enqueue(expenseId: expenseId, action: action, reason: reason, userId: apiClient.currentUserId)
                if case .networkError(let inner) = err, !(inner is UploadFault) {
                    actionError = "No connection — this will be applied automatically when you reconnect."
                } else {
                    actionError = "That took too long — queued and will retry automatically."
                }
            case .serverError(let msg):
                actionError = msg
            case .unauthorized, .invalidURL:
                actionError = err.errorDescription
            }
        } catch {
            AppDiagnostics.shared.record(category: "ReceiptsViewModel", message: ErrorDetails.describe(error))
            actionError = ErrorDetails.describe(error)
        }
        isPerformingAction = false
        return false
    }

    private func sendAction(expenseId: Int, action: String, reason: String?) async throws -> ReceiptActionResponse {
        var body: [String: Any] = ["action": action, "expense_id": expenseId]
        if let reason { body["rejection_reason"] = reason }
        return try await apiClient.request(.receiptAction, body: body, faultsAreTransient: true)
    }
}
