//
//  ReceiptReviewView.swift
//  MowologyCRM
//
//  Review and confirm OCR-parsed receipt fields before saving. Feature-for-feature
//  parity with the Android/WebView review card (public/crm/expenses_appstack.php,
//  #mobileReviewPanel): captured-image preview, vendor autocomplete that unlinks the
//  vendor_id when retyped, GST/PST with live subtotal↔total arithmetic, GPS job
//  suggestion pills + job search, duplicate warnings (exact-image and amount/date),
//  server-driven category list, Save vs Save & Send, and a "Snap Another / Done"
//  loop after a successful save.
//

import SwiftUI

struct ReceiptReviewView: View {

    /// What the user chose after a successful save.
    enum SaveFollowUp { case snapAnother, done }

    @ObservedObject var viewModel: ReceiptsViewModel
    let intake: ReceiptIntakeResponse
    let imageData: Data?
    let lat: Double?
    let lng: Double?
    @Binding var isPresented: Bool
    var onSaved: (SaveFollowUp) -> Void

    // Form state — pre-filled from OCR
    @State private var vendorName:     String
    @State private var vendorId:       Int?
    @State private var expenseDate:    Date
    @State private var amount:         String
    @State private var gst:            String
    @State private var pst:            String
    @State private var total:          String
    @State private var category:       String
    @State private var paymentMethod:  String
    @State private var description:    String = ""
    @State private var selectedJob:    JobPick?

    // Lookups
    @State private var vendorResults:  [VendorSearchResult] = []
    @State private var vendorSearchTask: Task<Void, Never>?
    @State private var suppressVendorSearch = false
    @State private var jobSearch:      String = ""
    @State private var jobResults:     [JobSearchResult] = []
    @State private var jobSearchTask:  Task<Void, Never>?
    @State private var duplicates:     [DuplicateExpense] = []
    @State private var duplicatesAcknowledged = false
    @State private var showDuplicateConfirm = false
    @State private var pendingAndSend = false

    // Post-save
    @State private var showSavedDialog = false
    @State private var savedMessage = ""
    @State private var showImageFull = false

    private enum Field: Hashable { case vendor, amount, gst, pst, total, jobSearch, description }
    @FocusState private var focus: Field?

    private static let fallbackCategories = [
        "Materials", "Fuel", "Tools/Equipment", "Repairs/Maintenance",
        "Disposal/Dump", "Subcontractors", "Marketing", "Office/Admin",
        "Overhead", "Licenses/Permits", "Meals", "Vehicle", "Other",
    ]
    private static let fallbackPayments = ["cash", "credit_card", "debit", "company_card", "etransfer", "cheque"]

    init(viewModel: ReceiptsViewModel,
         intake: ReceiptIntakeResponse,
         imageData: Data?,
         lat: Double?,
         lng: Double?,
         isPresented: Binding<Bool>,
         onSaved: @escaping (SaveFollowUp) -> Void) {
        self.viewModel    = viewModel
        self.intake       = intake
        self.imageData    = imageData
        self.lat          = lat
        self.lng          = lng
        self._isPresented = isPresented
        self.onSaved      = onSaved

        let p = intake.parsed
        let s = intake.suggestions
        let totalD = p?.totalDouble ?? 0
        let gstD   = p?.gstDouble ?? 0
        let pstD   = p?.pst.flatMap(Double.init) ?? 0
        // Same derivation as Android: use the parsed subtotal, else total − taxes.
        let subtotalD = p?.subtotal.flatMap(Double.init) ?? (totalD > 0 ? totalD - gstD - pstD : 0)

        _vendorName    = State(initialValue: s?.vendorName ?? p?.vendorHint ?? "")
        _vendorId      = State(initialValue: s?.vendorId)
        _expenseDate   = State(initialValue: Self.parseDate(p?.date))
        _amount        = State(initialValue: subtotalD > 0 ? Self.money(subtotalD) : "")
        _gst           = State(initialValue: gstD > 0 ? Self.money(gstD) : "")
        _pst           = State(initialValue: pstD > 0 ? Self.money(pstD) : "")
        _total         = State(initialValue: totalD > 0 ? Self.money(totalD) : "")
        _category      = State(initialValue: s?.accountingCategory ?? "")
        _paymentMethod = State(initialValue: p?.paymentMethod ?? "credit_card")
    }

    private var categories: [String] {
        var list = viewModel.accountingCategories.isEmpty ? Self.fallbackCategories : viewModel.accountingCategories
        if !category.isEmpty && !list.contains(category) { list.insert(category, at: 0) }
        return list
    }

    private var paymentMethods: [String] {
        viewModel.paymentMethods.isEmpty ? Self.fallbackPayments : viewModel.paymentMethods
    }

    private var totalValue: Double { Double(total) ?? 0 }
    private var canSave: Bool { totalValue > 0 && !viewModel.isSaving }

    var body: some View {
        NavigationStack {
            Form {
                receiptImageSection
                duplicateSection
                totalSection
                vendorSection
                jobSection
                categorySection
                lineItemsSection
                descriptionSection
                saveAndSendSection

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
                        Task { await attemptSave(andSend: false) }
                    } label: {
                        if viewModel.isSaving {
                            ProgressView().tint(Color.MW.green)
                        } else {
                            Text("Save").font(.subheadline.weight(.semibold))
                                .foregroundStyle(canSave ? Color.MW.green : .secondary)
                        }
                    }
                    .disabled(!canSave)
                }
            }
        }
        .presentationDragIndicator(.visible)
        .interactiveDismissDisabled(viewModel.isSaving)
        .task {
            await viewModel.loadCategories()
            await runDuplicateCheck()
        }
        .onChange(of: amount) { _, _ in if [.amount, .gst, .pst].contains(focus) { recalcTotal() } }
        .onChange(of: gst)    { _, _ in if [.amount, .gst, .pst].contains(focus) { recalcTotal() } }
        .onChange(of: pst)    { _, _ in if [.amount, .gst, .pst].contains(focus) { recalcTotal() } }
        .onChange(of: total)  { _, _ in if focus == .total { recalcSubtotal() } }
        .onChange(of: vendorName) { _, new in handleVendorTyped(new) }
        .onChange(of: jobSearch)  { _, new in handleJobTyped(new) }
        .fullScreenCover(isPresented: $showImageFull) {
            if let data = imageData, let ui = UIImage(data: data) {
                ZoomableCapturedImageView(image: ui)
            }
        }
        // Save-time duplicate confirm — desktop web uses confirm(); Android toasts and
        // allows. This is the explicit version: one tap to proceed, one to go back.
        .alert("Possible duplicate", isPresented: $showDuplicateConfirm) {
            Button("Save anyway") {
                duplicatesAcknowledged = true
                Task { await performSave(andSend: pendingAndSend) }
            }
            Button("Go back", role: .cancel) {}
        } message: {
            Text(duplicateSummary)
        }
        // "Snap Another / Done" — the batch loop Android shows after each save.
        .confirmationDialog(savedMessage, isPresented: $showSavedDialog, titleVisibility: .visible) {
            Button("Snap Another") { finish(.snapAnother) }
            Button("Done") { finish(.done) }
        }
    }

    // MARK: - Sections

    private var receiptImageSection: some View {
        Section {
            HStack {
                Spacer()
                if let data = imageData, let ui = UIImage(data: data) {
                    Image(uiImage: ui)
                        .resizable().scaledToFit()
                        .frame(maxHeight: 180)
                        .clipShape(RoundedRectangle(cornerRadius: 10))
                        .overlay(alignment: .bottomTrailing) {
                            Image(systemName: "arrow.up.left.and.arrow.down.right")
                                .font(.caption.weight(.bold))
                                .foregroundStyle(.white)
                                .padding(6)
                                .background(.black.opacity(0.55), in: Circle())
                                .padding(8)
                        }
                        .contentShape(Rectangle())
                        .onTapGesture { showImageFull = true }
                } else {
                    RoundedRectangle(cornerRadius: 10)
                        .fill(Color(.systemGray5))
                        .frame(height: 120)
                        .overlay { Image(systemName: "doc.text.image").font(.largeTitle).foregroundStyle(.secondary) }
                }
                Spacer()
            }
            HStack(spacing: 8) {
                ocrBadge
                if intake.gstValidation?.valid == false {
                    Label(intake.gstValidation?.message ?? "Tax math doesn't add up — check the amounts", systemImage: "exclamationmark.triangle.fill")
                        .font(.caption2)
                        .foregroundStyle(Color.MW.orange)
                }
            }
        }
    }

    @ViewBuilder
    private var ocrBadge: some View {
        if intake.ocrAvailable && !(intake.ocrText ?? "").isEmpty {
            Label("AI detected\(intake.ocrSource == "vision" ? " (AI)" : intake.ocrSource == "tesseract" ? " (local)" : "")", systemImage: "text.viewfinder")
                .font(.caption2).foregroundStyle(Color.MW.green)
        } else if intake.ocrAvailable {
            Label("No text found", systemImage: "questionmark.circle")
                .font(.caption2).foregroundStyle(Color.MW.orange)
        } else {
            Label("Manual entry", systemImage: "pencil")
                .font(.caption2).foregroundStyle(.secondary)
        }
    }

    @ViewBuilder
    private var duplicateSection: some View {
        if intake.duplicateImage != nil {
            Section {
                Label {
                    Text("This exact receipt image was already uploaded. You may be about to create a duplicate expense — please verify before saving.")
                        .font(.caption)
                } icon: {
                    Image(systemName: "doc.on.doc.fill").foregroundStyle(Color.MW.orange)
                }
            }
        } else if !duplicates.isEmpty {
            Section("Possible duplicate\(duplicates.count == 1 ? "" : "s")") {
                ForEach(duplicates) { d in
                    HStack {
                        Image(systemName: "doc.on.doc").foregroundStyle(Color.MW.orange)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(d.displayVendor).font(.subheadline.weight(.semibold))
                            Text("$\(d.total, specifier: "%.2f") on \(d.expenseDate) · \(d.status.replacingOccurrences(of: "_", with: " "))")
                                .font(.caption).foregroundStyle(.secondary)
                        }
                    }
                }
            }
        }
    }

    private var totalSection: some View {
        Section {
            HStack {
                Text("Total").font(.title3.weight(.semibold))
                Spacer()
                Text("$").foregroundStyle(.secondary)
                TextField("0.00", text: $total)
                    .keyboardType(.decimalPad)
                    .multilineTextAlignment(.trailing)
                    .font(.title3.weight(.semibold))
                    .frame(width: 110)
                    .focused($focus, equals: .total)
                if let c = intake.parsed?.total, !c.isEmpty { confidenceDot(70) }
            }
            amountRow(label: "Subtotal", value: $amount, field: .amount)
            amountRow(label: "GST",      value: $gst,    field: .gst)
            amountRow(label: "PST",      value: $pst,    field: .pst)
        } footer: {
            Text("Editing subtotal or tax recalculates the total; editing the total recalculates the subtotal.")
        }
    }

    private var vendorSection: some View {
        Section("Vendor") {
            HStack {
                TextField("Vendor name", text: $vendorName)
                    .focused($focus, equals: .vendor)
                    .autocorrectionDisabled()
                if vendorId != nil {
                    Image(systemName: "link").font(.caption).foregroundStyle(Color.MW.green)
                } else if let conf = intake.suggestions?.vendorConfidence, conf > 0, !vendorName.isEmpty {
                    confidenceDot(conf)
                }
            }
            if focus == .vendor && !vendorResults.isEmpty {
                ForEach(vendorResults) { v in
                    Button {
                        pickVendor(v)
                    } label: {
                        HStack {
                            Text(v.name).foregroundStyle(.primary)
                            Spacer()
                            if let cat = v.defaultAccountingCategory {
                                Text(cat).font(.caption).foregroundStyle(.secondary)
                            }
                        }
                    }
                }
            }
            DatePicker("Date", selection: $expenseDate, displayedComponents: .date)
                .tint(Color.MW.green)
        }
    }

    private var jobSection: some View {
        Section("Job") {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    jobPill(title: "No Job", subtitle: nil, selected: selectedJob == nil) { selectedJob = nil }
                    ForEach(intake.jobSuggestions ?? []) { s in
                        let pick = JobPick(suggestion: s)
                        jobPill(title: pick.title, subtitle: pick.subtitle, selected: selectedJob == pick) { selectedJob = pick }
                    }
                    if let j = selectedJob, !(intake.jobSuggestions ?? []).contains(where: { $0.planId == j.id }) {
                        jobPill(title: j.title, subtitle: j.subtitle, selected: true) {}
                    }
                }
                .padding(.vertical, 2)
            }
            HStack {
                Image(systemName: "magnifyingglass").foregroundStyle(.secondary)
                TextField("Search jobs by client, address or job #", text: $jobSearch)
                    .focused($focus, equals: .jobSearch)
                    .autocorrectionDisabled()
            }
            if focus == .jobSearch && !jobResults.isEmpty {
                ForEach(jobResults) { j in
                    Button {
                        selectedJob = JobPick(search: j)
                        jobSearch = ""
                        jobResults = []
                        focus = nil
                    } label: {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(JobPick(search: j).title).foregroundStyle(.primary)
                            if let a = j.address { Text(a).font(.caption).foregroundStyle(.secondary) }
                        }
                    }
                }
            }
        }
    }

    private func jobPill(title: String, subtitle: String?, selected: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 1) {
                Text(title).font(.caption.weight(.semibold)).lineLimit(1)
                if let subtitle, !subtitle.isEmpty {
                    Text(subtitle).font(.caption2).lineLimit(1).opacity(0.8)
                }
            }
            .padding(.horizontal, 10).padding(.vertical, 6)
            .background(selected ? Color.MW.green : Color(.systemGray5))
            .foregroundStyle(selected ? .white : .primary)
            .clipShape(Capsule())
        }
        .buttonStyle(.plain)
    }

    private var categorySection: some View {
        Section("Category") {
            Picker("Category", selection: $category) {
                Text("— Select —").tag("")
                ForEach(categories, id: \.self) { Text($0).tag($0) }
            }
            Picker("Payment", selection: $paymentMethod) {
                ForEach(paymentMethods, id: \.self) { Text(paymentLabel($0)).tag($0) }
            }
        }
    }

    /// Detected line items — read-only, collapsed by default, same behaviour as
    /// Android's mobile review card ("N items detected"). Neither platform lets the
    /// user edit individual lines here; they're sent through to the save as-is.
    @ViewBuilder
    private var lineItemsSection: some View {
        if let items = intake.parsed?.lineItems, !items.isEmpty {
            Section {
                DisclosureGroup("\(items.count) item\(items.count == 1 ? "" : "s") detected") {
                    ForEach(Array(items.enumerated()), id: \.offset) { _, item in
                        HStack {
                            Text(item.name ?? "Unknown Item")
                                .font(.subheadline)
                            if let qty = item.quantity, qty > 1 {
                                Text("×\(qty, specifier: "%g")")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            Spacer()
                            Text(item.amount.map { "$\($0)" } ?? "—")
                                .font(.subheadline)
                                .foregroundStyle((item.amountDouble ?? 0) < 0 ? .red : .primary)
                        }
                    }
                }
            }
        }
    }

    private var descriptionSection: some View {
        Section("Description (optional)") {
            TextField("What was this for?", text: $description, axis: .vertical)
                .lineLimit(2, reservesSpace: true)
                .focused($focus, equals: .description)
        }
    }

    /// Save & Send — one tap to save and forward to accounting, same as Android's
    /// second button. Not permission-gated client-side (the JWT carries no RBAC
    /// detail); the server saves regardless and reports a send failure separately.
    private var saveAndSendSection: some View {
        Section {
            Button {
                Task { await attemptSave(andSend: true) }
            } label: {
                HStack {
                    Spacer()
                    Label("Save & Send to Accounting", systemImage: "paperplane.fill")
                        .font(.subheadline.weight(.semibold))
                    Spacer()
                }
                .frame(minHeight: 36)
            }
            .disabled(!canSave)
            .foregroundStyle(canSave ? Color.MW.green : .secondary)
        } footer: {
            Text("Saves the expense and emails the receipt to the accounting inbox in one step.")
        }
    }

    private func amountRow(label: String, value: Binding<String>, field: Field) -> some View {
        HStack {
            Text(label).foregroundStyle(.secondary)
            Spacer()
            Text("$").foregroundStyle(.secondary)
            TextField("0.00", text: value)
                .keyboardType(.decimalPad)
                .multilineTextAlignment(.trailing)
                .frame(width: 90)
                .focused($focus, equals: field)
        }
    }

    private func confidenceDot(_ confidence: Int) -> some View {
        let color: Color = confidence >= 70 ? Color.MW.green : confidence >= 40 ? Color.MW.orange : .red
        return Circle().fill(color).frame(width: 8, height: 8)
    }

    // MARK: - Arithmetic (mirrors recalcMobileTotal / recalcMobileSubtotal)

    private func recalcTotal() {
        let sub = Double(amount) ?? 0, g = Double(gst) ?? 0, p = Double(pst) ?? 0
        let t = sub + g + p
        let formatted = t > 0 ? Self.money(t) : ""
        if formatted != total { total = formatted }
    }

    private func recalcSubtotal() {
        let t = Double(total) ?? 0, g = Double(gst) ?? 0, p = Double(pst) ?? 0
        let sub = t - g - p
        let formatted = sub > 0 ? Self.money(sub) : ""
        if formatted != amount { amount = formatted }
    }

    // MARK: - Vendor / job lookups

    private func handleVendorTyped(_ text: String) {
        if suppressVendorSearch { suppressVendorSearch = false; return }
        guard focus == .vendor else { return }
        // Retyping the vendor unlinks the suggested vendor_id — same as Android's
        // input handler — so the typed name is what gets saved and displayed.
        vendorId = nil
        vendorSearchTask?.cancel()
        let q = text.trimmingCharacters(in: .whitespaces)
        guard q.count >= 2 else { vendorResults = []; return }
        vendorSearchTask = Task {
            try? await Task.sleep(nanoseconds: 250_000_000)
            guard !Task.isCancelled else { return }
            let results = await viewModel.searchVendors(q)
            guard !Task.isCancelled else { return }
            vendorResults = results
        }
    }

    private func pickVendor(_ v: VendorSearchResult) {
        suppressVendorSearch = true
        vendorName = v.name
        vendorId = v.id
        if category.isEmpty, let cat = v.defaultAccountingCategory { category = cat }
        vendorResults = []
        focus = nil
    }

    private func handleJobTyped(_ text: String) {
        jobSearchTask?.cancel()
        let q = text.trimmingCharacters(in: .whitespaces)
        guard q.count >= 2 else { jobResults = []; return }
        jobSearchTask = Task {
            try? await Task.sleep(nanoseconds: 250_000_000)
            guard !Task.isCancelled else { return }
            let results = await viewModel.searchJobs(q)
            guard !Task.isCancelled else { return }
            jobResults = results
        }
    }

    // MARK: - Duplicates

    private func runDuplicateCheck() async {
        guard intake.duplicateImage == nil, totalValue > 0 else { return }
        duplicates = await viewModel.checkDuplicates(
            total: totalValue,
            date: Self.iso(expenseDate),
            vendorName: vendorName.isEmpty ? nil : vendorName,
            vendorId: vendorId
        )
    }

    private var duplicateSummary: String {
        if intake.duplicateImage != nil { return "This exact receipt image was already uploaded." }
        guard let d = duplicates.first else { return "" }
        let more = duplicates.count > 1 ? " (+\(duplicates.count - 1) more)" : ""
        return "\(d.displayVendor) — $\(String(format: "%.2f", d.total)) on \(d.expenseDate)\(more) already exists."
    }

    // MARK: - Save

    private func attemptSave(andSend: Bool) async {
        focus = nil
        if !duplicatesAcknowledged {
            await runDuplicateCheck()
            if intake.duplicateImage != nil || !duplicates.isEmpty {
                pendingAndSend = andSend
                showDuplicateConfirm = true
                return
            }
        }
        await performSave(andSend: andSend)
    }

    private func performSave(andSend: Bool) async {
        let draft = ExpenseDraft(
            vendorId:      vendorId,
            vendorName:    vendorName.trimmingCharacters(in: .whitespaces),
            date:          Self.iso(expenseDate),
            amount:        Double(amount) ?? 0,
            gst:           Double(gst) ?? 0,
            pst:           Double(pst) ?? 0,
            total:         totalValue,
            category:      category,
            paymentMethod: paymentMethod,
            description:   description.trimmingCharacters(in: .whitespacesAndNewlines),
            notes:         "",
            mediaId:       intake.mediaId,
            rawOcrText:    intake.ocrText,
            ocrParsed:     intake.parsed,
            job:           selectedJob,
            lat:           lat,
            lng:           lng,
            andSend:       andSend
        )
        guard let response = await viewModel.saveExpense(draft) else { return }

        let haptic = UINotificationFeedbackGenerator()
        if andSend {
            if response.sent == true {
                haptic.notificationOccurred(.success)
                savedMessage = "Saved & sent to accounting"
            } else {
                haptic.notificationOccurred(.warning)
                savedMessage = "Saved, but send failed: \(response.sendError ?? "unknown error")"
            }
        } else {
            haptic.notificationOccurred(.success)
            savedMessage = "Expense saved"
        }
        showSavedDialog = true
    }

    private func finish(_ followUp: SaveFollowUp) {
        isPresented = false
        onSaved(followUp)
    }

    // MARK: - Helpers

    private static func money(_ v: Double) -> String { String(format: "%.2f", v) }

    private static func iso(_ d: Date) -> String {
        let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; return f.string(from: d)
    }

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
        case "cheque":        return "Cheque"
        default:              return method.capitalized
        }
    }
}

// MARK: - Full-screen captured image

/// Pinch/pan viewer for the just-captured photo (a local UIImage, not a URL —
/// ReceiptDetailView's ZoomableReceiptView handles the server-hosted case).
private struct ZoomableCapturedImageView: View {
    let image: UIImage
    @Environment(\.dismiss) private var dismiss
    @State private var scale: CGFloat = 1
    @State private var lastScale: CGFloat = 1

    var body: some View {
        ZStack(alignment: .topTrailing) {
            Color.black.ignoresSafeArea()
            ScrollView([.horizontal, .vertical], showsIndicators: false) {
                Image(uiImage: image)
                    .resizable()
                    .scaledToFit()
                    .scaleEffect(scale)
                    .frame(width: UIScreen.main.bounds.width * scale,
                           height: UIScreen.main.bounds.height * scale)
            }
            .gesture(
                MagnificationGesture()
                    .onChanged { v in scale = max(1, min(5, lastScale * v)) }
                    .onEnded { _ in lastScale = scale }
            )
            .onTapGesture(count: 2) {
                withAnimation { scale = scale > 1 ? 1 : 2.5; lastScale = scale }
            }
            Button { dismiss() } label: {
                Image(systemName: "xmark.circle.fill")
                    .font(.title)
                    .foregroundStyle(.white.opacity(0.9))
                    .padding()
            }
        }
    }
}
