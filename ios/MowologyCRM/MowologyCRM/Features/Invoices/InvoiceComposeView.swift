//
//  InvoiceComposeView.swift
//  MowologyCRM
//
//  Invoice compose sheet — presented from VisitWorkView when admin taps
//  "Complete & Invoice". Stays open across all states; the success confirmation
//  replaces the editing form in-place (no navigation push).
//
//  UX goal: admin reviews pre-populated invoice, adjusts if needed, taps
//  "Send Invoice" — done in 2 gestures without leaving the job context.
//

import SwiftUI

struct InvoiceComposeView: View {

    let visitId: Int
    @StateObject private var vm: InvoiceComposeViewModel
    @Environment(\.dismiss) private var dismiss

    init(visitId: Int, prefill: InvoicePrefill, apiClient: APIClient) {
        self.visitId = visitId
        _vm = StateObject(wrappedValue: InvoiceComposeViewModel(prefill: prefill, apiClient: apiClient))
    }

    // MARK: - Body

    var body: some View {
        NavigationStack {
            ZStack {
                switch vm.state {
                case .editing(let prefill):
                    editingContent(prefill: prefill)

                case .sending:
                    sendingView

                case .success(let number, let total, let sentTo):
                    successView(invoiceNumber: number, total: total, sentTo: sentTo)

                case .error(let message):
                    // Error banner at top, editing content still visible
                    VStack(spacing: 0) {
                        ErrorBannerView(message: message) {
                            vm.state = .editing(vm.prefill)
                        }
                        .padding(.horizontal, 16)
                        .padding(.top, 12)
                        editingContent(prefill: vm.prefill)
                    }
                }
            }
            .navigationTitle("New Invoice")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    if case .success = vm.state {
                        Button("Done") { dismiss() }
                    } else if !vm.isSending {
                        Button("Cancel") { dismiss() }
                    }
                }
            }
        }
    }

    // MARK: - Editing content

    @ViewBuilder
    private func editingContent(prefill: InvoicePrefill) -> some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {

                // ── Client + address ─────────────────────────────────────────
                clientCard(prefill: prefill)

                // ── Line items ───────────────────────────────────────────────
                lineItemsCard

                // ── Nominal request charge ───────────────────────────────────
                nominalRequestCard

                // ── Totals ───────────────────────────────────────────────────
                totalsCard(prefill: prefill)

                // ── Due date ─────────────────────────────────────────────────
                dueDateCard

                // ── Notes ────────────────────────────────────────────────────
                notesCard

                // ── Recipients ───────────────────────────────────────────────
                recipientsCard(prefill: prefill)

                // ── Send button ──────────────────────────────────────────────
                sendButton(prefill: prefill)
            }
            .padding(16)
        }
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Client card

    private func clientCard(prefill: InvoicePrefill) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Label("Bill To", systemImage: "person.fill")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)
                .textCase(.uppercase)
                .tracking(0.5)

            Text(prefill.clientDisplayName)
                .font(.body.weight(.semibold))
                .foregroundStyle(Color.MW.dark)

            if !prefill.serviceAddress.isEmpty {
                Text([prefill.serviceAddress, prefill.serviceCity, prefill.serviceProvince]
                    .filter { !$0.isEmpty }.joined(separator: ", "))
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(16)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Line items card

    private var lineItemsCard: some View {
        VStack(alignment: .leading, spacing: 12) {
            Label("Services", systemImage: "list.bullet")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)
                .textCase(.uppercase)
                .tracking(0.5)

            ForEach($vm.editableLineItems) { $item in
                LineItemRow(item: $item, vm: vm)
                Divider()
            }
            .onDelete { offsets in
                let ids = offsets.map { vm.editableLineItems[$0].id }
                ids.forEach { vm.removeLineItem(id: $0) }
            }

            Button {
                vm.addLineItem()
            } label: {
                Label("Add line item", systemImage: "plus.circle")
                    .font(.subheadline)
                    .foregroundStyle(Color.MW.green)
            }
        }
        .padding(16)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Nominal request card

    private var nominalRequestCard: some View {
        Toggle(isOn: $vm.nominalRequestEnabled) {
            HStack(spacing: 10) {
                Image(systemName: "plus.circle.fill")
                    .foregroundStyle(vm.nominalRequestEnabled ? Color.MW.green : .secondary)
                VStack(alignment: .leading, spacing: 2) {
                    Text("Nominal client request")
                        .font(.subheadline.weight(.medium))
                        .foregroundStyle(Color.MW.dark)
                    Text("Add $10.00 for small on-site requests")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .toggleStyle(.switch)
        .tint(Color.MW.green)
        .padding(16)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Totals card

    private func totalsCard(prefill: InvoicePrefill) -> some View {
        VStack(spacing: 8) {
            TotalRow(label: "Subtotal",
                     value: formatCurrency(vm.subtotal))
            TotalRow(label: "GST (\(Int((prefill.taxRate * 100).rounded()))%)",
                     value: formatCurrency(vm.taxAmount))
            Divider()
            TotalRow(label: "Total",
                     value: formatCurrency(vm.total),
                     isTotal: true)
        }
        .padding(16)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Due date card

    private var dueDateCard: some View {
        HStack {
            Label("Due Date", systemImage: "calendar")
                .font(.subheadline)
                .foregroundStyle(Color.MW.dark)
            Spacer()
            DatePicker("", selection: $vm.dueDate, displayedComponents: .date)
                .labelsHidden()
        }
        .padding(16)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Notes card

    private var notesCard: some View {
        VStack(alignment: .leading, spacing: 8) {
            Label("Notes (optional)", systemImage: "note.text")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)
                .textCase(.uppercase)
                .tracking(0.5)

            TextField("Crew notes, payment instructions…", text: $vm.notes, axis: .vertical)
                .font(.subheadline)
                .lineLimit(3...6)
        }
        .padding(16)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Recipients card

    private func recipientsCard(prefill: InvoicePrefill) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Label("Send To", systemImage: "envelope.fill")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)
                .textCase(.uppercase)
                .tracking(0.5)

            if prefill.recipients.isEmpty {
                Text("No email on file — invoice will be saved as draft.")
                    .font(.subheadline)
                    .foregroundStyle(.orange)
            } else {
                ForEach(prefill.recipients) { r in
                    HStack(spacing: 8) {
                        Image(systemName: "person.circle.fill")
                            .foregroundStyle(Color.MW.green)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(r.name)
                                .font(.subheadline.weight(.medium))
                            Text(r.emailAddress)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        Spacer()
                        if r.receivesSms {
                            Label("SMS", systemImage: "message.fill")
                                .font(.caption2.weight(.semibold))
                                .padding(.horizontal, 7)
                                .padding(.vertical, 3)
                                .background(Color.MW.green.opacity(0.12))
                                .foregroundStyle(Color.MW.green)
                                .clipShape(Capsule())
                        }
                    }
                }
            }
        }
        .padding(16)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Send button

    private func sendButton(prefill: InvoicePrefill) -> some View {
        let canSend = vm.hasValidLineItems
        let label = prefill.recipients.isEmpty ? "Save as Draft" : "Send Invoice"
        let icon  = prefill.recipients.isEmpty ? "tray.and.arrow.down.fill" : "paperplane.fill"

        return Button {
            Task { await vm.sendInvoice(visitId: visitId) }
        } label: {
            Label(label, systemImage: icon)
                .font(.headline)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 16)
                .background(canSend ? Color.MW.green : Color.gray.opacity(0.3))
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 12))
        }
        .disabled(!canSend || vm.isSending)
        .padding(.bottom, 8)
    }

    // MARK: - Sending overlay

    private var sendingView: some View {
        VStack(spacing: 20) {
            ProgressView()
                .scaleEffect(1.4)
            Text("Sending invoice…")
                .font(.headline)
                .foregroundStyle(Color.MW.dark)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Success view

    private func successView(invoiceNumber: String, total: Double, sentTo: [String]) -> some View {
        VStack(spacing: 28) {
            Spacer()

            Image(systemName: "checkmark.circle.fill")
                .font(.system(size: 72))
                .foregroundStyle(Color.MW.green)

            VStack(spacing: 8) {
                Text("Invoice Sent!")
                    .font(.title2.weight(.bold))
                    .foregroundStyle(Color.MW.dark)

                Text(invoiceNumber)
                    .font(.title3.weight(.semibold))
                    .foregroundStyle(Color.MW.green)
                    .monospaced()

                Text(formatCurrency(total))
                    .font(.headline)
                    .foregroundStyle(.secondary)
            }

            if !sentTo.isEmpty {
                VStack(spacing: 4) {
                    Text("Sent to")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    ForEach(sentTo, id: \.self) { email in
                        Text(email)
                            .font(.subheadline)
                            .foregroundStyle(Color.MW.dark)
                    }
                }
                .padding(14)
                .background(Color.MW.green.opacity(0.08))
                .clipShape(RoundedRectangle(cornerRadius: 10))
            }

            Text("Customer will receive a payment link via email.")
                .font(.caption)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)

            Spacer()

            Button("Done") { dismiss() }
                .font(.headline)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 16)
                .background(Color.MW.green)
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .padding(.horizontal, 16)
                .padding(.bottom, 16)
        }
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Currency formatter

    private func formatCurrency(_ value: Double) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.currencyCode = "CAD"
        formatter.maximumFractionDigits = 2
        return formatter.string(from: NSNumber(value: value)) ?? "$\(String(format: "%.2f", value))"
    }
}

// MARK: - Line item row

private struct LineItemRow: View {
    @Binding var item: PrefillLineItem
    let vm: InvoiceComposeViewModel

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            TextField("Description", text: $item.description)
                .font(.subheadline)
                .onChange(of: item.description) { _, new in
                    vm.lineItemDescriptionChanged(id: item.id, description: new)
                }

            HStack(spacing: 16) {
                // Quantity stepper
                HStack(spacing: 8) {
                    Text("Qty")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    Button {
                        vm.lineItemQuantityChanged(id: item.id, quantity: max(0, item.quantity - 1))
                    } label: {
                        Image(systemName: "minus.circle")
                            .foregroundStyle(Color.MW.green)
                    }
                    Text(item.quantity == item.quantity.rounded()
                         ? String(Int(item.quantity))
                         : String(format: "%.1f", item.quantity))
                        .font(.subheadline.monospacedDigit())
                        .frame(minWidth: 24, alignment: .center)
                    Button {
                        vm.lineItemQuantityChanged(id: item.id, quantity: item.quantity + 1)
                    } label: {
                        Image(systemName: "plus.circle")
                            .foregroundStyle(Color.MW.green)
                    }
                }

                Spacer()

                // Unit price
                HStack(spacing: 4) {
                    Text("$")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    TextField("0.00", value: $item.unitPrice,
                              format: .number.precision(.fractionLength(2)))
                        .keyboardType(.decimalPad)
                        .font(.subheadline.monospacedDigit())
                        .frame(width: 70)
                        .multilineTextAlignment(.trailing)
                        .onChange(of: item.unitPrice) { _, new in
                            vm.lineItemPriceChanged(id: item.id, unitPrice: new)
                        }
                }

                // Line total (read-only)
                Text("$\(String(format: "%.2f", item.lineTotal))")
                    .font(.subheadline.monospacedDigit().weight(.medium))
                    .foregroundStyle(Color.MW.dark)
                    .frame(width: 70, alignment: .trailing)
            }
        }
        .padding(.vertical, 4)
    }
}

// MARK: - Total row

private struct TotalRow: View {
    let label: String
    let value: String
    var isTotal: Bool = false

    var body: some View {
        HStack {
            Text(label)
                .font(isTotal ? .subheadline.weight(.semibold) : .subheadline)
                .foregroundStyle(isTotal ? Color.MW.dark : .secondary)
            Spacer()
            Text(value)
                .font(isTotal ? .headline : .subheadline)
                .foregroundStyle(isTotal ? Color.MW.dark : .secondary)
                .monospacedDigit()
        }
    }
}

