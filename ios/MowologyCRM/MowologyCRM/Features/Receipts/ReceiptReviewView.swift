//
//  ReceiptReviewView.swift
//  MowologyCRM
//
//  Review and confirm OCR-parsed receipt fields before saving.
//

import SwiftUI

struct ReceiptReviewView: View {

    @ObservedObject var viewModel: ReceiptsViewModel
    let intake: ReceiptIntakeResponse
    @Binding var isPresented: Bool

    // Pre-fill from OCR
    @State private var vendorName:     String
    @State private var expenseDate:    Date
    @State private var amount:         String
    @State private var gst:            String
    @State private var total:          String
    @State private var category:       String
    @State private var paymentMethod:  String
    @State private var notes:          String

    // Categories and payment methods come from viewModel.categories (fetched from /api/expenses/expense-meta).

    init(viewModel: ReceiptsViewModel, intake: ReceiptIntakeResponse, isPresented: Binding<Bool>) {
        self.viewModel   = viewModel
        self.intake      = intake
        self._isPresented = isPresented

        let p = intake.parsed
        let s = intake.suggestions
        _vendorName    = State(initialValue: s?.vendorName ?? p?.vendorHint ?? "")
        _expenseDate   = State(initialValue: Self.parseDate(p?.date))
        _amount        = State(initialValue: p?.subtotal ?? p?.total ?? "")
        _gst           = State(initialValue: p?.gst ?? "")
        _total         = State(initialValue: p?.total ?? "")
        _category      = State(initialValue: s?.accountingCategory ?? "")
        _paymentMethod = State(initialValue: p?.paymentMethod ?? "credit_card")
        _notes         = State(initialValue: "")
    }

    var body: some View {
        NavigationStack {
            Form {
                receiptImageSection
                vendorSection
                lineItemsSection
                amountsSection
                categorySection
                notesSection

                if let err = viewModel.saveError {
                    Section {
                        Text(err).foregroundStyle(.red).font(.subheadline)
                    }
                }
            }
            .navigationTitle("Review Receipt")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { isPresented = false }
                        .foregroundStyle(Color.MW.green)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button {
                        Task { await save() }
                    } label: {
                        if viewModel.isSaving {
                            ProgressView().tint(Color.MW.green)
                        } else {
                            Text("Save").font(.subheadline.weight(.semibold))
                                .foregroundStyle(Color.MW.green)
                        }
                    }
                    .disabled(viewModel.isSaving || total.isEmpty)
                }
            }
        }
        .presentationDragIndicator(.visible)
        .task { await viewModel.loadMeta() }
    }

    // MARK: - Sections

    private var receiptImageSection: some View {
        Section {
            HStack {
                Spacer()
                AsyncImage(url: intake.receiptImageURL) { phase in
                    switch phase {
                    case .success(let img):
                        img.resizable().scaledToFit()
                            .frame(maxHeight: 220)
                            .clipShape(RoundedRectangle(cornerRadius: 10))
                    case .failure:
                        receiptImagePlaceholder
                    default:
                        receiptImagePlaceholder
                    }
                }
                Spacer()
            }
        }
    }

    private var receiptImagePlaceholder: some View {
        RoundedRectangle(cornerRadius: 10)
            .fill(Color(.systemGray5))
            .frame(height: 120)
            .overlay {
                VStack(spacing: 6) {
                    Image(systemName: "doc.text.image").font(.largeTitle).foregroundStyle(.secondary)
                    if intake.ocrAvailable {
                        Label("OCR: \(intake.ocrSource?.uppercased() ?? "–")", systemImage: "text.viewfinder")
                            .font(.caption2).foregroundStyle(Color.MW.green)
                    }
                }
            }
    }

    private var vendorSection: some View {
        Section("Vendor") {
            HStack {
                TextField("Vendor name", text: $vendorName)
                if let conf = intake.suggestions?.vendorConfidence, conf > 0 {
                    confidenceDot(conf)
                }
            }
            DatePicker("Date", selection: $expenseDate, displayedComponents: .date)
                .tint(Color.MW.green)
        }
    }

    private var amountsSection: some View {
        Section("Amounts") {
            amountRow(label: "Subtotal", value: $amount)
            amountRow(label: "GST (5%)", value: $gst)
            Divider()
            HStack {
                Text("Total").font(.subheadline.weight(.semibold))
                Spacer()
                TextField("0.00", text: $total)
                    .keyboardType(.decimalPad)
                    .multilineTextAlignment(.trailing)
                    .font(.subheadline.weight(.semibold))
                    .frame(width: 90)
            }
        }
    }

    private func amountRow(label: String, value: Binding<String>) -> some View {
        HStack {
            Text(label).foregroundStyle(.secondary)
            Spacer()
            TextField("0.00", text: value)
                .keyboardType(.decimalPad)
                .multilineTextAlignment(.trailing)
                .frame(width: 90)
        }
    }

    @ViewBuilder
    private var lineItemsSection: some View {
        if let items = intake.parsed?.lineItems, !items.isEmpty {
            Section("Line Items") {
                ForEach(items) { item in
                    HStack {
                        Text(item.name)
                            .font(.subheadline)
                            .lineLimit(2)
                        Spacer()
                        if let amt = item.amount {
                            Text("$\(amt)")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                                .monospacedDigit()
                        }
                    }
                }
            }
        }
    }

    private var categorySection: some View {
        Section("Category") {
            Picker("Category", selection: $category) {
                Text("— Select —").tag("")
                ForEach(viewModel.categories, id: \.self) { Text($0).tag($0) }
            }
            Picker("Payment", selection: $paymentMethod) {
                ForEach(viewModel.paymentMethods, id: \.self) { Text(paymentLabel($0)).tag($0) }
            }
        }
    }

    private var notesSection: some View {
        Section("Notes (optional)") {
            TextField("Any additional details…", text: $notes, axis: .vertical)
                .lineLimit(3, reservesSpace: true)
        }
    }

    // MARK: - Confidence dot

    private func confidenceDot(_ confidence: Int) -> some View {
        let color: Color = confidence >= 80 ? Color.MW.green : confidence >= 50 ? Color.MW.orange : .red
        return Circle().fill(color).frame(width: 8, height: 8)
    }

    // MARK: - Save

    private func save() async {
        let fmt = DateFormatter(); fmt.dateFormat = "yyyy-MM-dd"
        let saved = await viewModel.saveExpense(
            vendorId:      intake.suggestions?.vendorId,
            vendorName:    vendorName,
            date:          fmt.string(from: expenseDate),
            amount:        Double(amount) ?? 0,
            gst:           Double(gst)    ?? 0,
            total:         Double(total)  ?? 0,
            category:      category,
            paymentMethod: paymentMethod,
            notes:         notes,
            mediaId:       intake.mediaId,
            ocrParsed:     intake.parsed,
            lat:           nil, lng: nil
        )
        if saved { isPresented = false }
    }

    // MARK: - Helpers

    private static func parseDate(_ string: String?) -> Date {
        guard let string else { return .now }
        let fmts = ["yyyy-MM-dd", "MM/dd/yyyy", "dd/MM/yyyy"]
        let f = DateFormatter()
        for fmt in fmts { f.dateFormat = fmt; if let d = f.date(from: string) { return d } }
        return .now
    }

    private func paymentLabel(_ method: String) -> String {
        switch method {
        case "credit_card":   return "Credit Card"
        case "debit":         return "Debit Card"
        case "cash":          return "Cash"
        case "etransfer":     return "e-Transfer"
        case "company_card":  return "Company Card"
        default:              return method.capitalized
        }
    }
}
