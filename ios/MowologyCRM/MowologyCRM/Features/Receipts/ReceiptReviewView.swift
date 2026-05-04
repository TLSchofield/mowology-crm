//
//  ReceiptReviewView.swift
//  MowologyCRM
//
//  Review and confirm OCR-parsed receipt fields before saving.
//
//  Two-phase UX:
//    1. Opens immediately with Vision pre-fill (~300 ms after photo)
//    2. When server upload completes, merges server enrichments (vendor_id,
//       category, line items) into any fields the user hasn't edited yet.
//

import SwiftUI
import UIKit

struct ReceiptReviewView: View {

    @ObservedObject var viewModel: ReceiptsViewModel
    @Binding var isPresented: Bool

    // Editable fields — initialised from visionPreFill, upgraded by server response
    @State private var vendorName:    String
    @State private var expenseDate:   Date
    @State private var amount:        String
    @State private var gst:           String
    @State private var total:         String
    @State private var category:      String
    @State private var paymentMethod: String
    @State private var notes:         String

    // Track which fields the user has manually edited so server merge skips them
    @State private var editedFields: Set<String> = []
    @State private var serverMerged  = false

    // Receipt image (loaded via bearer-token auth once mediaId is available)
    @State private var receiptImage:   UIImage? = nil
    @State private var isLoadingImage: Bool     = false

    // Focus management
    @FocusState private var focusedField: FormField?
    private enum FormField: Hashable { case vendor, amount, gst, total, notes }

    // MARK: - Init

    init(viewModel: ReceiptsViewModel, isPresented: Binding<Bool>) {
        self.viewModel    = viewModel
        self._isPresented = isPresented

        // Phase 1: Vision pre-fill (fast, on-device)
        let v = viewModel.visionPreFill
        // Phase 2: Server data (may already be available if upload was very fast)
        let p = viewModel.intakeResponse?.parsed
        let s = viewModel.intakeResponse?.suggestions

        _vendorName    = State(initialValue: s?.vendorName    ?? v?.vendorHint    ?? p?.vendorHint    ?? "")
        _expenseDate   = State(initialValue: Self.parseDate(p?.date ?? v?.date))
        _amount        = State(initialValue: p?.subtotal       ?? v?.subtotal       ?? "")
        _gst           = State(initialValue: p?.gst            ?? v?.gst            ?? "")
        _total         = State(initialValue: p?.total          ?? v?.total          ?? "")
        _category      = State(initialValue: s?.accountingCategory ?? "")
        _paymentMethod = State(initialValue: p?.paymentMethod  ?? v?.paymentMethod  ?? "credit_card")
        _notes         = State(initialValue: "")
        // If server data was already present when the sheet opened, the fields above
        // are already initialised with it — mark merged so .onChange never re-applies
        // and risks overwriting anything the user edits immediately after open.
        _serverMerged  = State(initialValue: viewModel.intakeResponse != nil)
    }

    // MARK: - Body

    var body: some View {
        NavigationStack {
            Form {
                // Inline upload-progress banner (replaces old full-screen spinner)
                if viewModel.isUploading {
                    Section {
                        HStack(spacing: 10) {
                            ProgressView().scaleEffect(0.8).tint(Color.MW.green)
                            Text("Syncing with server…")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                        }
                        .padding(.vertical, 2)
                    }
                }

                receiptImageSection
                vendorSection
                lineItemsSection
                amountsSection
                categorySection
                notesSection

                if let err = viewModel.saveError {
                    Section {
                        ErrorBannerView(message: err)
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
                        if viewModel.isSaving {
                            ProgressView().tint(Color.MW.green)
                        } else {
                            Text(viewModel.isUploading ? "Uploading…" : "Save")
                                .font(.subheadline.weight(.semibold))
                                .foregroundStyle(
                                    (viewModel.isSaving || viewModel.isUploading || total.isEmpty)
                                    ? Color.MW.green.opacity(0.4) : Color.MW.green
                                )
                        }
                    }
                    .disabled(viewModel.isSaving || viewModel.isUploading || total.isEmpty)
                }
            }
        }
        // Load metadata (categories / payment methods) when sheet appears
        .task { await viewModel.loadMeta() }
        // When server response arrives, merge enrichments into unedited fields
        .onChange(of: viewModel.intakeResponse) { _, intake in
            guard let intake, !serverMerged else { return }
            mergeServerData(intake)
            serverMerged = true
        }
        .presentationDragIndicator(.visible)
    }

    // MARK: - Receipt Image Section

    private var receiptImageSection: some View {
        Section {
            HStack {
                Spacer()
                if let img = receiptImage {
                    // Server-fetched (full resolution, post-EXIF-strip)
                    Image(uiImage: img)
                        .resizable()
                        .scaledToFit()
                        .frame(maxHeight: 220)
                        .clipShape(RoundedRectangle(cornerRadius: 10))
                } else if let local = viewModel.capturedImage {
                    // Local capture — shown immediately while upload is in progress
                    Image(uiImage: local)
                        .resizable()
                        .scaledToFit()
                        .frame(maxHeight: 220)
                        .clipShape(RoundedRectangle(cornerRadius: 10))
                        .overlay(alignment: .bottomTrailing) {
                            if viewModel.isUploading {
                                ProgressView()
                                    .tint(.white)
                                    .padding(6)
                                    .background(.black.opacity(0.35))
                                    .clipShape(RoundedRectangle(cornerRadius: 6))
                                    .padding(8)
                            }
                        }
                } else if isLoadingImage {
                    RoundedRectangle(cornerRadius: 10)
                        .fill(Color(.systemGray5))
                        .frame(height: 120)
                        .overlay { ProgressView().tint(Color.MW.green) }
                } else {
                    receiptImagePlaceholder
                }
                Spacer()
            }
        }
        // Re-fires when a valid mediaId arrives (upload complete, server stored the image)
        .task(id: viewModel.intakeResponse?.mediaId) {
            guard let mediaId = viewModel.intakeResponse?.mediaId, mediaId > 0 else { return }
            isLoadingImage = true
            if let data = try? await viewModel.fetchReceiptImage(mediaId: mediaId),
               let img  = UIImage(data: data) {
                receiptImage = img
            }
            isLoadingImage = false
        }
    }

    private var receiptImagePlaceholder: some View {
        RoundedRectangle(cornerRadius: 10)
            .fill(Color(.systemGray5))
            .frame(height: 120)
            .overlay {
                VStack(spacing: 6) {
                    Image(systemName: "doc.text.image")
                        .font(.largeTitle)
                        .foregroundStyle(.secondary)
                    // Show OCR source badge once we know it
                    let ocrSource = viewModel.intakeResponse?.ocrSource
                        ?? (viewModel.visionPreFill != nil ? "ios_vision" : nil)
                    if let src = ocrSource {
                        Label("OCR: \(src.uppercased().replacingOccurrences(of: "_", with: " "))",
                              systemImage: "text.viewfinder")
                        .font(.caption2)
                        .foregroundStyle(Color.MW.green)
                    }
                }
            }
    }

    // MARK: - Vendor Section

    private var vendorSection: some View {
        Section("Vendor") {
            HStack {
                TextField("Vendor name", text: $vendorName)
                    .focused($focusedField, equals: .vendor)
                    .onChange(of: vendorName) { _, _ in editedFields.insert("vendorName") }
                if let conf = viewModel.intakeResponse?.suggestions?.vendorConfidence, conf > 0 {
                    confidenceDot(conf)
                } else if viewModel.visionPreFill?.vendorHint != nil && !vendorName.isEmpty {
                    // Vision-sourced — show a muted dot until server confirms
                    Circle().fill(Color.MW.green.opacity(0.35)).frame(width: 8, height: 8)
                }
            }
            DatePicker("Date", selection: $expenseDate, displayedComponents: .date)
                .tint(Color.MW.green)
                .onChange(of: expenseDate) { _, _ in editedFields.insert("expenseDate") }
        }
    }

    // MARK: - Amounts Section

    private var amountsSection: some View {
        Section("Amounts") {
            amountRow(label: "Subtotal", value: $amount, field: .amount)
            amountRow(label: "GST (5%)", value: $gst,    field: .gst)
            Divider()
            HStack {
                Text("Total").font(.subheadline.weight(.semibold))
                Spacer()
                TextField("0.00", text: $total)
                    .keyboardType(.decimalPad)
                    .multilineTextAlignment(.trailing)
                    .font(.subheadline.weight(.semibold))
                    .frame(width: 90)
                    .focused($focusedField, equals: .total)
                    .onChange(of: total) { _, _ in editedFields.insert("total") }
            }
        }
    }

    private func amountRow(label: String, value: Binding<String>, field: FormField) -> some View {
        HStack {
            Text(label).foregroundStyle(.secondary)
            Spacer()
            TextField("0.00", text: value)
                .keyboardType(.decimalPad)
                .multilineTextAlignment(.trailing)
                .frame(width: 90)
                .focused($focusedField, equals: field)
                .onChange(of: value.wrappedValue) { _, _ in editedFields.insert(label) }
        }
    }

    // MARK: - Line Items Section

    @ViewBuilder
    private var lineItemsSection: some View {
        if let items = viewModel.intakeResponse?.parsed?.lineItems, !items.isEmpty {
            Section("Line Items") {
                ForEach(items) { item in
                    HStack {
                        Text(item.name).font(.subheadline).lineLimit(2)
                        Spacer()
                        if let amt = item.amount {
                            Text("$\(amt)").font(.subheadline).foregroundStyle(.secondary).monospacedDigit()
                        }
                    }
                }
            }
        }
    }

    // MARK: - Category Section

    private var categorySection: some View {
        Section("Category") {
            Picker("Category", selection: $category) {
                Text("— Select —").tag("")
                ForEach(viewModel.categories, id: \.self) { Text($0).tag($0) }
            }
            .onChange(of: category) { _, _ in editedFields.insert("category") }

            Picker("Payment", selection: $paymentMethod) {
                ForEach(viewModel.paymentMethods, id: \.self) {
                    Text(paymentLabel($0)).tag($0)
                }
            }
            .onChange(of: paymentMethod) { _, _ in editedFields.insert("paymentMethod") }
        }
    }

    // MARK: - Notes Section

    private var notesSection: some View {
        Section("Notes (optional)") {
            TextField("Any additional details…", text: $notes, axis: .vertical)
                .lineLimit(3, reservesSpace: true)
                .focused($focusedField, equals: .notes)
        }
    }

    // MARK: - Confidence Dot

    private func confidenceDot(_ confidence: Int) -> some View {
        let color: Color = confidence >= 80 ? Color.MW.green
                         : confidence >= 50 ? Color.MW.orange : .red
        return Circle().fill(color).frame(width: 8, height: 8)
    }

    // MARK: - Server Merge

    /// Merges server-enriched data into fields the user hasn't manually edited.
    private func mergeServerData(_ intake: ReceiptIntakeResponse) {
        let p = intake.parsed
        let s = intake.suggestions
        let v = viewModel.visionPreFill

        if !editedFields.contains("vendorName"), let name = s?.vendorName {
            vendorName = name
        }
        if !editedFields.contains("expenseDate"), let dateStr = p?.date {
            expenseDate = Self.parseDate(dateStr)
        }
        if !editedFields.contains("Subtotal"), let amt = p?.subtotal {
            amount = amt
        }
        if !editedFields.contains("GST (5%)"), let g = p?.gst {
            gst = g
        }
        if !editedFields.contains("total"), let t = p?.total {
            total = t
        }
        if !editedFields.contains("category"), let cat = s?.accountingCategory, !cat.isEmpty {
            category = cat
        }
        if !editedFields.contains("paymentMethod"), let pm = p?.paymentMethod {
            paymentMethod = pm
        }
        // Vision was a guess at vendor — if server confirmed something different, always update
        if let name = s?.vendorName,
           vendorName == (v?.vendorHint ?? "") || vendorName.isEmpty {
            vendorName = name
        }
    }

    // MARK: - Save

    private func save() async {
        let fmt = DateFormatter(); fmt.dateFormat = "yyyy-MM-dd"
        let saved = await viewModel.saveExpense(
            vendorId:      viewModel.intakeResponse?.suggestions?.vendorId,
            vendorName:    vendorName,
            date:          fmt.string(from: expenseDate),
            amount:        Double(amount) ?? 0,
            gst:           Double(gst)    ?? 0,
            total:         Double(total)  ?? 0,
            category:      category,
            paymentMethod: paymentMethod,
            notes:         notes,
            mediaId:       viewModel.intakeResponse?.mediaId,
            ocrParsed:     viewModel.intakeResponse?.parsed,
            lat:           nil, lng: nil
        )
        if saved { isPresented = false }
    }

    // MARK: - Helpers

    private static func parseDate(_ string: String?) -> Date {
        guard let string else { return .now }
        let fmts = ["yyyy-MM-dd", "MM/dd/yyyy", "dd/MM/yyyy", "MMM d, yyyy"]
        let f = DateFormatter(); f.locale = Locale(identifier: "en_CA")
        for fmt in fmts { f.dateFormat = fmt; if let d = f.date(from: string) { return d } }
        return .now
    }

    private func paymentLabel(_ method: String) -> String {
        switch method {
        case "credit_card":  return "Credit Card"
        case "debit":        return "Debit Card"
        case "cash":         return "Cash"
        case "etransfer":    return "e-Transfer"
        case "company_card": return "Company Card"
        default:             return method.capitalized
        }
    }
}

#Preview {
    let session = AuthSession()
    let client  = APIClient(authSession: session)
    let vm      = ReceiptsViewModel(apiClient: client)
    vm.visionPreFill = VisionPreFill(
        rawText:       "CITY OF VANCOUVER\nTotal: 36.19\nGST: 1.72\n2025-05-28",
        vendorHint:    "City of Vancouver",
        total:         "36.19",
        subtotal:      "34.47",
        gst:           "1.72",
        date:          "2025-05-28",
        paymentMethod: "credit_card"
    )
    return ReceiptReviewView(viewModel: vm, isPresented: .constant(true))
}
