//
//  ExpenseSaveQueue.swift
//  MowologyCRM
//
//  Disk-backed offline queue for expense metadata saves.
//  When the server is unreachable, expense form data is persisted to
//  UserDefaults and replayed automatically when connectivity returns.
//  NWPathMonitor drains the queue on reconnect — same pattern as ReceiptQueue.
//

import Foundation
import Network

@MainActor
final class ExpenseSaveQueue: ObservableObject {

    static let shared = ExpenseSaveQueue()

    @Published private(set) var pendingCount: Int = 0

    private let defaults  = UserDefaults.standard
    private let storeKey  = "mw.expense.save.queue.v1"
    private let monitor   = NWPathMonitor()
    private var drainTask: Task<Void, Never>?

    // MARK: - Pending Item

    struct PendingExpense: Codable {
        let id:                 String
        let expenseDate:        String
        let vendorId:           Int?
        let vendorNameRaw:      String
        let amount:             Double
        let gstAmount:          Double
        let pstAmount:          Double
        let hstAmount:          Double
        let total:              Double
        let taxModel:           String
        let accountingCategory: String
        let paymentMethod:      String
        let notes:              String
        let receiptMediaId:     Int?
        let receiptLat:         Double?
        let receiptLng:         Double?
        let ocrParsed:          String?
        let queuedAt:           Date
    }

    private var items: [PendingExpense] {
        get {
            guard let data    = defaults.data(forKey: storeKey),
                  let decoded = try? JSONDecoder().decode([PendingExpense].self, from: data)
            else { return [] }
            return decoded
        }
        set {
            defaults.set(try? JSONEncoder().encode(newValue), forKey: storeKey)
            pendingCount = newValue.count
        }
    }

    private init() { pendingCount = items.count }

    // MARK: - Enqueue

    func enqueue(
        expenseDate:   String,
        vendorId:      Int?,
        vendorName:    String,
        amount:        Double,
        gst:           Double,
        pst:           Double,
        hst:           Double,
        total:         Double,
        taxModel:      String,
        category:      String,
        paymentMethod: String,
        notes:         String,
        mediaId:       Int?,
        lat:           Double?,
        lng:           Double?,
        ocrParsed:     String?
    ) {
        var current = items
        current.append(PendingExpense(
            id:                 UUID().uuidString,
            expenseDate:        expenseDate,
            vendorId:           vendorId,
            vendorNameRaw:      vendorName,
            amount:             amount,
            gstAmount:          gst,
            pstAmount:          pst,
            hstAmount:          hst,
            total:              total,
            taxModel:           taxModel,
            accountingCategory: category,
            paymentMethod:      paymentMethod,
            notes:              notes,
            receiptMediaId:     mediaId,
            receiptLat:         lat,
            receiptLng:         lng,
            ocrParsed:          ocrParsed,
            queuedAt:           Date()
        ))
        items = current
    }

    // MARK: - Monitor & Drain

    func startMonitoring(saveHandler: @escaping ([String: Any]) async throws -> Void) {
        let queue = DispatchQueue(label: "ca.mowology.expense-save-monitor", qos: .utility)
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor [weak self] in
                await self?.drain(saveHandler: saveHandler)
            }
        }
        monitor.start(queue: queue)
    }

    func drain(saveHandler: @escaping ([String: Any]) async throws -> Void) async {
        guard drainTask == nil else { return }
        drainTask = Task {
            var current = items
            for item in current {
                var body: [String: Any] = [
                    "expense_date":        item.expenseDate,
                    "vendor_name_raw":     item.vendorNameRaw,
                    "amount":              item.amount,
                    "gst_amount":          item.gstAmount,
                    "pst_amount":          item.pstAmount,
                    "hst_amount":          item.hstAmount,
                    "total":               item.total,
                    "tax_model":           item.taxModel,
                    "accounting_category": item.accountingCategory,
                    "payment_method":      item.paymentMethod,
                    "notes":               item.notes,
                    "status":              "draft",
                ]
                if let v   = item.vendorId       { body["vendor_id"]        = v   }
                if let m   = item.receiptMediaId { body["receipt_media_id"] = m   }
                if let lat = item.receiptLat     { body["receipt_lat"]      = lat }
                if let lng = item.receiptLng     { body["receipt_lng"]      = lng }
                if let p   = item.ocrParsed      { body["ocr_parsed"]       = p   }

                do {
                    try await saveHandler(body)
                    current.removeAll { $0.id == item.id }
                    items = current
                } catch {
                    // Leave in queue and retry on next connectivity event.
                    // Break to preserve insertion order (avoids saving expense B before A).
                    break
                }
            }
            drainTask = nil
        }
        await drainTask?.value
    }
}
