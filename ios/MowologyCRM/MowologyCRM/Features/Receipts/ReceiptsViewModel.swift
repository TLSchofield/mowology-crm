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

    // MARK: - Upload

    func uploadImage(_ imageData: Data, lat: Double?, lng: Double?) async {
        isUploading   = true
        uploadError   = nil
        intakeResponse = nil
        do {
            intakeResponse = try await apiClient.uploadReceipt(imageData: imageData, lat: lat, lng: lng, jobId: nil)
        } catch let err as APIError {
            if case .networkError = err {
                ReceiptQueue.shared.enqueue(imageData: imageData, lat: lat, lng: lng, jobId: nil)
                uploadError = "No connection — receipt queued and will upload automatically."
            } else {
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
}
