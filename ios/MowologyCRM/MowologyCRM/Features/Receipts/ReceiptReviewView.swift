//
//  ReceiptReviewView.swift
//  MowologyCRM
//
//  Review and confirm receipt fields before saving.
//
//  Opens immediately with localParse pre-fill (DataScannerViewController result).
//  While the server upload runs in the background, the Save button shows "Uploading…"
//  and is disabled. Once intakeResponse arrives, Save becomes active.
//

import SwiftUI
import UIKit

struct ReceiptReviewView: View {

    @ObservedObject var viewModel: ReceiptsViewModel

    /// Non-nil once the server upload completes. May be nil if opened before upload finishes.
    let intake: ReceiptIntakeResponse?

    /// Instant local OCR pre-fill from VisionKit. Used when intake is not yet available.
    let localParse: LocalReceiptParse?

    @Binding var isPresented: Bool

    // Form state — pre-filled from intake or localParse
    @State private var vendorName:    String
    @State private var expenseDate:   Date
    @State private var amount:        String
    @State private var gst:           String
    @State private var total:         String
    @State private var category:      String
    @State private var paymentMethod: String
    @State private var notes:         String

    @State private var receiptImage:   UIImage? = nil
    @State private var isLoadingImage: Bool     = false

    // MARK: - Init

    init(viewModel:   ReceiptsViewModel,
         intake:      ReceiptIntakeResponse?,
         localParse:  LocalReceiptParse?,
         isPresented: Binding<Bool>) {
        self.viewModel    = viewModel
        self.intake       = intake
        self.localParse   = localParse
        self._isPresented = isPresented

        // Prefer server suggestions, fall back to local parse
        let p = intake?.parsed
        let s = intake?.suggestions
        let lp = localParse

        _vendorName    = State(initialValue: s?.vendorName    ?? p?.vendorHint ?? lp?.vendorHint ?? "")
        _expenseDate   = State(initialValue: Self.parseDate(p?.date ?? lp?.date))
        _amount        = State(initialValue: p?.subtotal ?? "")
        _gst           = State(initialValue: p?.gst      ?? Self.formatAmount(lp?.gst))
        _total         = State(initialValue: p?.total    ?? Self.formatAmount(lp?.total))
        _category      = State(initialValue: s?.accountingCategory ?? "")
        _paymentMethod = State(initialValue: p?.paymentMethod ?? "credit_card")
        _notes         = State(initialValue: "")
    }

    // MARK: - Body

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
                if let err = viewModel.uploadError {
                    Section {
                        Label(err, systemImage: "exclamationmark.triangle")
                            .font(.subheadline)
                            .foregroundStyle(Color.MW.orange)
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
                    Button { Task { await save() } } label: {
                        Group {
                            if viewModel.isSaving {
                                ProgressView().tint(Color.MW.green)
                            } else if viewModel.isUploading {
                                Label("Uploading…", systemImage: "arrow.up.circle")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                            } else {
                                Text("Save").font(.subheadline.weight(.semibold))
                                    .foregroundStyle(Color.MW.green)
                            }
                        }
                    }
                    .disabled(viewModel.isSaving || viewModel.isUploading || total.isEmpty)
                }
            }
        }
        .presentationDragIndicator(.visible)
        .task { await viewModel.loadMeta() }
        // When server intake arrives after local pre-fill, update any empty fields
        .onChange(of: viewModel.intakeResponse) { _, newIntake in
            guard let newIntake else { return }
            updateFromIntake(newIntake)
            loadReceiptImage(mediaId: newIntake.mediaId)
        }
        .task {
            // Load image if intake already available (e.g., upload beat the sheet opening)
            if let mediaId = intake?.mediaId {
                loadReceiptImage(mediaId: mediaId)
            }
        }
    }

    // MARK: - Sections

    private var receiptImageSection: some View {
        Section {
            HStack {
                Spacer()
                if let uiImage = receiptImage {
                    Image(uiImage: uiImage)
                        .resizable()
                        .scaledToFit()
                        .frame(maxHeight: 220)
                        .clipShape(RoundedRectangle(cornerRadius: 10))
                } else if isLoadingImage || viewModel.isUploading {
                    RoundedRectangle(cornerRadius: 10)
                        .fill(Color(.systemGray5))
                        .frame(height: 120)
                        .overlay {
                            VStack(spacing: 6) {
                                ProgressView().tint(Color.MW.green)
                                Text(viewModel.isUploading ? "Uploading…" : "Loading image…")
                                    .font(.caption).foregroundStyle(.secondary)
                            }
                        }
                } else {
                    receiptImagePlaceholder
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
                    if let intake, intake.ocrAvailable {
                        Label("OCR: \(intake.ocrSource?.uppercased() ?? "–")", systemImage: "text.viewfinder")
                            .font(.caption2).foregroundStyle(Color.MW.green)
                    } else if localParse?.total != nil {
                        Label("Local OCR", systemImage: "text.viewfinder")
                            .font(.caption2).foregroundStyle(Color.MW.green)
                    }
                }
            }
    }

    private var vendorSection: some View {
        Section("Vendor") {
            HStack {
                TextField("Vendor name", text: $vendorName)
                if let conf = intake?.suggestions?.vendorConfidence, conf > 0 {
                    confidenceDot(conf)
                }
            }
            DatePicker("Date", selection: $expenseDate, displayedComponents: .date)
        }
    }

    private var lineItemsSection: some View {
        Group {
            if let items = intake?.parsed?.lineItems, !items.isEmpty {
                Section("Line Items") {
                    ForEach(items) { item in
                        HStack {
                            Text(item.name).font(.subheadline)
                            Spacer()
                            if let amt = item.amount {
                                Text(amt).font(.subheadline.monospacedDigit())
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                }
            }
        }
    }

    private var amountsSection: some View {
        Section("Amounts") {
            HStack {
                Text("Subtotal").foregroundStyle(.secondary)
                Spacer()
                TextField("0.00", text: $amount)
                    .keyboardType(.decimalPad)
                    .multilineTextAlignment(.trailing)
                    .frame(maxWidth: 100)
            }
            HStack {
                Text("GST / HST").foregroundStyle(.secondary)
                Spacer()
                TextField("0.00", text: $gst)
                    .keyboardType(.decimalPad)
                    .multilineTextAlignment(.trailing)
                    .frame(maxWidth: 100)
            }
            HStack {
                Text("Total").foregroundStyle(.primary).fontWeight(.medium)
                Spacer()
                TextField("0.00", text: $total)
                    .keyboardType(.decimalPad)
                    .multilineTextAlignment(.trailing)
                    .frame(maxWidth: 100)
                    .fontWeight(.medium)
            }
        }
    }

    private var categorySection: some View {
        Section("Category & Payment") {
            if viewModel.categories.isEmpty {
                TextField("Category", text: $category)
            } else {
                Picker("Category", selection: $category) {
                    Text("Select…").tag("")
                    ForEach(viewModel.categories, id: \.self) { cat in
                        Text(cat.replacingOccurrences(of: "_", with: " ").capitalized)
                            .tag(cat)
                    }
                }
            }
            Picker("Payment", selection: $paymentMethod) {
                ForEach(viewModel.paymentMethods, id: \.self) { method in
                    Text(method.replacingOccurrences(of: "_", with: " ").capitalized)
                        .tag(method)
                }
            }
        }
    }

    private var notesSection: some View {
        Section("Notes") {
            TextField("Optional notes", text: $notes, axis: .vertical)
                .lineLimit(2...4)
        }
    }

    // MARK: - Confidence indicator

    private func confidenceDot(_ confidence: Int) -> some View {
        Circle()
            .fill(confidence >= 80 ? Color.green : confidence >= 50 ? Color.orange : Color.red)
            .frame(width: 8, height: 8)
    }

    // MARK: - Image loading

    private func loadReceiptImage(mediaId: Int) {
        guard receiptImage == nil, !isLoadingImage else { return }
        isLoadingImage = true
        Task {
            if let data = try? await viewModel.fetchReceiptImage(mediaId: mediaId),
               let img  = UIImage(data: data) {
                receiptImage = img
            }
            isLoadingImage = false
        }
    }

    // MARK: - Update from server intake

    private func updateFromIntake(_ newIntake: ReceiptIntakeResponse) {
        let p = newIntake.parsed
        let s = newIntake.suggestions

        if vendorName.isEmpty    { vendorName    = s?.vendorName ?? p?.vendorHint ?? vendorName }
        if gst.isEmpty,   let v = p?.gst   { gst   = v }
        if total.isEmpty, let v = p?.total { total = v }
        if amount.isEmpty, let v = p?.subtotal { amount = v }
        if category.isEmpty, let v = s?.accountingCategory { category = v }
        if paymentMethod == "credit_card", let v = p?.paymentMethod { paymentMethod = v }

        let serverDate = Self.parseDate(p?.date)
        if expenseDate == Self.parseDate(localParse?.date) {
            expenseDate = serverDate
        }
    }

    // MARK: - Save

    private func save() async {
        let totalDouble  = Double(total.replacingOccurrences(of: ",", with: ".")) ?? 0
        let gstDouble    = Double(gst.replacingOccurrences(of: ",", with: "."))   ?? 0
        let amountDouble = Double(amount.replacingOccurrences(of: ",", with: ".")) ?? (totalDouble - gstDouble)

        let dateStr = Self.isoDate(from: expenseDate)

        let mediaId  = viewModel.intakeResponse?.mediaId ?? intake?.mediaId
        let ocrParsed = viewModel.intakeResponse?.parsed ?? intake?.parsed

        let success = await viewModel.saveExpense(
            vendorId:      viewModel.intakeResponse?.suggestions?.vendorId ?? intake?.suggestions?.vendorId,
            vendorName:    vendorName,
            date:          dateStr,
            amount:        amountDouble,
            gst:           gstDouble,
            total:         totalDouble,
            category:      category,
            paymentMethod: paymentMethod,
            notes:         notes,
            mediaId:       mediaId,
            ocrParsed:     ocrParsed,
            lat:           nil,
            lng:           nil
        )

        if success { isPresented = false }
    }

    // MARK: - Date helpers

    private static func parseDate(_ string: String?) -> Date {
        guard let string, !string.isEmpty else { return .now }
        let fmts = ["yyyy-MM-dd", "MM/dd/yyyy", "dd-MMM-yyyy"]
        let fmt = DateFormatter()
        fmt.locale = Locale(identifier: "en_US_POSIX")
        for f in fmts {
            fmt.dateFormat = f
            if let d = fmt.date(from: string) { return d }
        }
        return .now
    }

    private static func isoDate(from date: Date) -> String {
        let fmt = DateFormatter()
        fmt.locale     = Locale(identifier: "en_US_POSIX")
        fmt.dateFormat = "yyyy-MM-dd"
        return fmt.string(from: date)
    }

    private static func formatAmount(_ value: Double?) -> String {
        guard let v = value else { return "" }
        return String(format: "%.2f", v)
    }
}
