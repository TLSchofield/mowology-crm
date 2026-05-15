//
//  InvoiceComposeViewModel.swift
//  MowologyCRM
//
//  State machine for the "Complete & Invoice" compose sheet.
//  Drives InvoiceComposeView: loading prefill → editing → sending → success/error.
//

import Foundation

@MainActor
final class InvoiceComposeViewModel: ObservableObject {

    // MARK: - State machine

    enum ComposeState {
        case editing(InvoicePrefill)
        case sending
        case success(invoiceNumber: String, total: Double, sentTo: [String])
        case error(String)
    }

    // MARK: - Published

    @Published var state: ComposeState
    @Published var editableLineItems: [PrefillLineItem]
    @Published var dueDate: Date
    @Published var notes: String

    // Editable totals (recomputed from editableLineItems)
    @Published var subtotal: Double
    @Published var taxAmount: Double
    @Published var total: Double

    // MARK: - Internal (accessible to InvoiceComposeView)

    let prefill: InvoicePrefill
    let apiClient: APIClient

    // MARK: - Init

    init(prefill: InvoicePrefill, apiClient: APIClient) {
        self.prefill    = prefill
        self.apiClient  = apiClient

        // Combine base line items + extras into a single editable list
        var items = prefill.lineItems
        if let extras = prefill.extrasLineItem {
            items.append(extras)
        }
        self.editableLineItems = items

        // Parse due date from ISO string, fallback to +30 days
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd"
        self.dueDate = formatter.date(from: prefill.defaultDueDate)
            ?? Date(timeIntervalSinceNow: 30 * 86400)

        self.notes    = prefill.crewNotes ?? ""
        self.subtotal = prefill.subtotal
        self.taxAmount = prefill.taxAmount
        self.total     = prefill.total

        self.state = .editing(prefill)
    }

    // MARK: - Extra charge quick-entry (free-form description + any amount)

    @Published var extraChargeDescription: String = ""
    @Published var extraChargeAmount: String = ""

    var extraChargeAmountDouble: Double? {
        let cleaned = extraChargeAmount.replacingOccurrences(of: ",", with: ".")
        guard let v = Double(cleaned), v > 0 else { return nil }
        return (v * 100).rounded() / 100
    }

    func addExtraCharge() {
        guard let amount = extraChargeAmountDouble else { return }
        let desc = extraChargeDescription.trimmingCharacters(in: .whitespaces)
        let nextOrder = (editableLineItems.map(\.sortOrder).max() ?? 0) + 1
        let item = PrefillLineItem(
            description: desc.isEmpty ? "Extra charge" : desc,
            quantity: 1.0,
            unitPrice: amount,
            lineTotal: amount,
            sortOrder: nextOrder,
            isExtras: true
        )
        editableLineItems.append(item)
        extraChargeDescription = ""
        extraChargeAmount = ""
        recomputeTotals()
    }

    // MARK: - Line item editing

    func addLineItem() {
        let nextOrder = (editableLineItems.map(\.sortOrder).max() ?? 0) + 1
        let blank = PrefillLineItem(
            description: "",
            quantity: 1.0,
            unitPrice: 0.0,
            lineTotal: 0.0,
            sortOrder: nextOrder
        )
        editableLineItems.append(blank)
    }

    func removeLineItem(id: UUID) {
        editableLineItems.removeAll { $0.id == id }
        recomputeTotals()
    }

    func lineItemQuantityChanged(id: UUID, quantity: Double) {
        guard let idx = editableLineItems.firstIndex(where: { $0.id == id }) else { return }
        editableLineItems[idx].quantity = max(0, quantity)
        editableLineItems[idx].recompute()
        recomputeTotals()
    }

    func lineItemPriceChanged(id: UUID, unitPrice: Double) {
        guard let idx = editableLineItems.firstIndex(where: { $0.id == id }) else { return }
        editableLineItems[idx].unitPrice = max(0, unitPrice)
        editableLineItems[idx].recompute()
        recomputeTotals()
    }

    func lineItemDescriptionChanged(id: UUID, description: String) {
        guard let idx = editableLineItems.firstIndex(where: { $0.id == id }) else { return }
        editableLineItems[idx].description = description
    }

    func recomputeTotals() {
        let sub = editableLineItems.reduce(0.0) { $0 + $1.lineTotal }
        subtotal  = (sub * 100).rounded() / 100
        taxAmount = (sub * prefill.taxRate * 100).rounded() / 100
        total     = (subtotal + taxAmount * 100).rounded() / 100
    }

    // MARK: - Send

    func sendInvoice(visitId: Int) async {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd"
        let dueDateString = formatter.string(from: dueDate)

        // Encode line items as [String: Any] for the JSON body
        let lineItemsPayload: [[String: Any]] = editableLineItems.map { item in
            [
                "description": item.description,
                "quantity":    item.quantity,
                "unit_price":  item.unitPrice,
                "line_total":  item.lineTotal,
                "sort_order":  item.sortOrder,
            ]
        }

        let recipientIds = prefill.recipients.map(\.id)

        let body: [String: Any] = [
            "visit_id":              visitId,
            "plan_id":               prefill.planId as Any,
            "company_id":            prefill.companyId as Any,
            "contact_id":            prefill.contactId as Any,
            "property_id":           prefill.propertyId as Any,
            "due_date":              dueDateString,
            "notes":                 notes,
            "line_items":            lineItemsPayload,
            "recipient_contact_ids": recipientIds,
            "send_immediately":      true,
        ]

        state = .sending

        do {
            let response: InvoiceCreateSendResponse = try await apiClient.request(
                .scheduleInvoiceCreateSend,
                body: body
            )

            if response.success, let number = response.invoiceNumber, let tot = response.total {
                let sent = response.sentTo ?? []
                state = .success(invoiceNumber: number, total: tot, sentTo: sent)
            } else if let existingId = response.existingInvoiceId {
                // Race condition: already invoiced between prefill fetch and create
                state = .success(
                    invoiceNumber: "Already invoiced (#\(existingId))",
                    total: total,
                    sentTo: []
                )
            } else {
                state = .error(response.message ?? "Invoice could not be created. Please try again.")
            }
        } catch {
            state = .error(friendlyError(error))
        }
    }

    // MARK: - Helpers

    var isSending: Bool {
        if case .sending = state { return true }
        return false
    }

    var hasValidLineItems: Bool {
        !editableLineItems.isEmpty && editableLineItems.contains { $0.lineTotal > 0 }
    }

    var hasRecipients: Bool {
        !prefill.recipients.isEmpty
    }

    private func friendlyError(_ error: Error) -> String {
        if let apiError = error as? APIError { return apiError.localizedDescription }
        return error.localizedDescription
    }
}
