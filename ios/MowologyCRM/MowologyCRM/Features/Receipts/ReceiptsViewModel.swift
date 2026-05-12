//
//  ReceiptsViewModel.swift
//  MowologyCRM
//

import Foundation
import Combine

@MainActor
final class ReceiptsViewModel: ObservableObject {

    // MARK: - List state
    @Published var expenses: [Expense] = []
    @Published var isLoadingList = false
    @Published var listError: String?
    @Published var currentPage = 1
    @Published var totalPages  = 1

    // MARK: - Upload state
    /// Only surfaced for *unrecoverable* failures (server 4xx/5xx, decode errors,
    /// or items that have exhausted their retry budget). Transient network
    /// errors are swallowed silently — the pending badge is the only feedback.
    @Published var uploadError:   String?
    @Published var intakeResponse: ReceiptIntakeResponse?

    // MARK: - Save state
    @Published var isSaving     = false
    @Published var saveError:    String?

    private let apiClient: APIClient
    private var queueBootstrapped = false
    private var lastUnrecoverableCount = 0
    private var unrecoverableObserver: AnyCancellable?

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

    // MARK: - Upload (queue-first)

    /// Save the receipt to the durable queue, then attempt an upload in the
    /// background. The caller returns immediately — only the "pending" badge
    /// reflects in-flight uploads. Transient network errors stay queued
    /// silently; the drain loop retries with backoff and only marks an item
    /// `failedUnrecoverable` once the retry budget is spent.
    func uploadImage(_ imageData: Data, lat: Double?, lng: Double?) {
        bootstrapQueueIfNeeded()

        // 1. Durably queue the receipt before doing anything else.
        let queuedId = ReceiptQueue.shared.enqueue(
            imageData: imageData,
            lat: lat,
            lng: lng,
            jobId: nil
        )

        // 2. Kick off the upload in a fire-and-forget task. URLSession's network I/O
        //    runs off the main thread internally, so we don't need Task.detached here —
        //    the caller returns the moment we exit this function.
        Task { [weak self] in
            await self?.performBackgroundUpload(
                imageData: imageData,
                lat: lat,
                lng: lng,
                queuedId: queuedId
            )
        }
    }

    private func performBackgroundUpload(imageData: Data, lat: Double?, lng: Double?, queuedId: String) async {
        do {
            let response = try await apiClient.uploadReceipt(
                imageData: imageData,
                lat: lat,
                lng: lng,
                jobId: nil
            )
            // Success: drop the queued copy and surface OCR results for review.
            ReceiptQueue.shared.remove(id: queuedId)
            intakeResponse = response
        } catch let err as APIError {
            if ReceiptQueue.isTransientNetworkError(err) {
                // Silent — receipt stays queued; ReceiptQueue.drain() retries with backoff.
                return
            }
            // Non-transient (server 4xx/5xx, decode, unauthorized…) — drop from the queue
            // so we don't keep retrying a request the server has already rejected, and
            // surface a single alert.
            ReceiptQueue.shared.remove(id: queuedId)
            uploadError = err.errorDescription
        } catch {
            ReceiptQueue.shared.remove(id: queuedId)
            uploadError = "Upload failed."
        }
    }

    /// Wire ReceiptQueue's drain loop to APIClient.uploadReceipt the first time
    /// the user lands on the Receipts tab. Also start observing unrecoverable
    /// items so the view can show a single alert when the retry budget is spent.
    func bootstrapQueueIfNeeded() {
        guard !queueBootstrapped else { return }
        queueBootstrapped = true

        let client = self.apiClient
        ReceiptQueue.shared.startMonitoring { data, lat, lng, jobId in
            try await client.uploadReceipt(imageData: data, lat: lat, lng: lng, jobId: jobId)
        }

        lastUnrecoverableCount = ReceiptQueue.shared.unrecoverableCount
        unrecoverableObserver = ReceiptQueue.shared.$unrecoverableCount
            .receive(on: RunLoop.main)
            .sink { [weak self] newCount in
                guard let self else { return }
                // Only fire when a NEW item just transitioned to unrecoverable.
                if newCount > self.lastUnrecoverableCount {
                    self.uploadError = "A receipt couldn't upload after multiple attempts. Tap retry from the receipts list, or check your connection."
                }
                self.lastUnrecoverableCount = newCount
            }
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
}
