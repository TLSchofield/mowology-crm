//
//  ReceiptDetailView.swift
//  MowologyCRM
//
//  Detail/action screen for an already-saved receipt — the piece that was missing on
//  iOS: capture and draft-save already worked, but there was no way to approve, reject,
//  or forward a receipt to accounting without switching to the web app. Buttons here are
//  not role-gated client-side (there's no cheap way to know the JWT user's exact RBAC
//  permissions without a dedicated lookup) — the server enforces expenses.approve /
//  expenses.send via jwtUserHasPermission() and returns a clear error if denied.
//

import SwiftUI

struct ReceiptDetailView: View {

    @ObservedObject var viewModel: ReceiptsViewModel
    let expense: Expense
    @Environment(\.dismiss) private var dismiss

    @State private var showRejectSheet = false
    @State private var rejectReason = ""
    @State private var showErrorAlert = false
    @State private var showFullImage = false
    @State private var showEditSheet = false

    // Line items — editable post-save (rename / delete / link to a CRM product / add).
    // Each action goes through the shared ExpenseLineItemService, so a correction made
    // here teaches the parser exactly like one made on the desktop edit modal.
    @State private var lineItems: [StoredLineItem] = []
    @State private var lineItemsSource: String?
    @State private var lineItemsLoaded = false
    @State private var renameItem: StoredLineItem?
    @State private var renameText = ""
    @State private var linkItem: StoredLineItem?
    @State private var showAddItem = false
    @State private var addItemName = ""
    @State private var addItemAmount = ""

    var body: some View {
        NavigationStack {
            Form {
                imageSection
                statusSection
                detailsSection
                lineItemsSection

                if expense.isArchived {
                    Section {
                        Label("Archived — original photo removed from the server; a thumbnail is shown here. The full-size image was emailed before removal.", systemImage: "archivebox")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }
            .navigationTitle("Receipt")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                        .foregroundStyle(Color.MW.green)
                }
                if isEditable {
                    ToolbarItem(placement: .primaryAction) {
                        Button("Edit") { showEditSheet = true }
                            .foregroundStyle(Color.MW.green)
                    }
                }
            }
            .safeAreaInset(edge: .bottom) {
                if canActOn(expense) {
                    actionBar
                }
            }
        }
        .presentationDragIndicator(.visible)
        .sheet(isPresented: $showRejectSheet) {
            rejectSheet
        }
        .onChange(of: viewModel.actionError) { _, err in
            if err != nil { showErrorAlert = true }
        }
        .alert("Action Failed", isPresented: $showErrorAlert) {
            Button("OK") { viewModel.actionError = nil }
        } message: {
            Text(viewModel.actionError ?? "")
        }
        .fullScreenCover(isPresented: $showFullImage) {
            if let url = receiptURL {
                ZoomableReceiptView(url: url)
            }
        }
        .sheet(isPresented: $showEditSheet) {
            EditExpenseView(viewModel: viewModel, expense: expense, isPresented: $showEditSheet) {
                dismiss()   // pop back to the (now refreshed) list so edits are reflected
            }
        }
        .sheet(item: $linkItem) { item in
            ProductLinkSheet(viewModel: viewModel, lineItem: item) { productId in
                Task {
                    if let updated = await viewModel.linkLineItem(id: item.id, productId: productId) {
                        replaceLineItem(updated)
                    }
                }
            }
        }
        .task { await loadLineItems() }
        .alert("Rename item", isPresented: Binding(get: { renameItem != nil }, set: { if !$0 { renameItem = nil } })) {
            TextField("Item name", text: $renameText)
            Button("Save") {
                if let item = renameItem {
                    let v = renameText.trimmingCharacters(in: .whitespaces)
                    if !v.isEmpty {
                        Task {
                            if let updated = await viewModel.updateLineItem(id: item.id, name: v, quantity: nil, unitPrice: nil, lineTotal: nil) {
                                replaceLineItem(updated)
                            }
                        }
                    }
                }
                renameItem = nil
            }
            Button("Cancel", role: .cancel) { renameItem = nil }
        } message: {
            Text("Corrections teach the parser what this line really says for this vendor.")
        }
        .alert("Add missed item", isPresented: $showAddItem) {
            TextField("Item name (as printed)", text: $addItemName)
            TextField("Line total ($)", text: $addItemAmount)
                .keyboardType(.decimalPad)
            Button("Add") {
                let n = addItemName.trimmingCharacters(in: .whitespaces)
                let amt = Double(addItemAmount) ?? 0
                addItemName = ""; addItemAmount = ""
                guard !n.isEmpty else { return }
                Task {
                    if let added = await viewModel.addLineItem(expenseId: expense.id, name: n, lineTotal: amt, productId: nil) {
                        lineItems.append(added)
                    }
                }
            }
            Button("Cancel", role: .cancel) { addItemName = ""; addItemAmount = "" }
        }
    }

    // MARK: - Line items

    private func loadLineItems() async {
        if let r = await viewModel.loadLineItems(expenseId: expense.id) {
            lineItems = r.lineItems
            lineItemsSource = r.lineItemsSource
        }
        lineItemsLoaded = true
    }

    private func replaceLineItem(_ updated: StoredLineItem) {
        if let i = lineItems.firstIndex(where: { $0.id == updated.id }) {
            lineItems[i] = updated
        }
    }

    private var lineItemsSection: some View {
        Section {
            if !lineItemsLoaded {
                HStack { Spacer(); ProgressView(); Spacer() }
            } else if lineItems.isEmpty {
                Text("No line items recorded").font(.caption).foregroundStyle(.secondary)
            }
            if lineItemsSource == "llm" {
                Label("AI extracted — please verify each line", systemImage: "sparkles")
                    .font(.caption2).foregroundStyle(.blue)
            }
            ForEach(lineItems) { item in
                HStack(spacing: 6) {
                    VStack(alignment: .leading, spacing: 1) {
                        Text(item.name).font(.subheadline)
                        HStack(spacing: 6) {
                            if let sku = item.skuRaw, !sku.isEmpty {
                                Text(sku).font(.caption2).foregroundStyle(.secondary)
                            }
                            if let pn = item.productName {
                                Label(pn, systemImage: "link").font(.caption2).foregroundStyle(Color.MW.green)
                            } else if isEditable && !item.isAdjustment {
                                Button("Link product") { linkItem = item }
                                    .font(.caption2)
                                    .buttonStyle(.borderless)
                                    .foregroundStyle(Color.MW.green)
                            }
                        }
                    }
                    if item.quantity > 1 {
                        Text("×\(item.quantity, specifier: "%g")").font(.caption).foregroundStyle(.secondary)
                    }
                    Spacer()
                    Text(String(format: "$%.2f", item.lineTotal))
                        .font(.subheadline)
                        .foregroundStyle(item.lineTotal < 0 ? .red : .primary)
                }
                .contentShape(Rectangle())
                .onTapGesture {
                    guard isEditable else { return }
                    renameText = item.name
                    renameItem = item
                }
                .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                    if isEditable {
                        Button(role: .destructive) {
                            Task {
                                if await viewModel.deleteLineItem(id: item.id) {
                                    lineItems.removeAll { $0.id == item.id }
                                }
                            }
                        } label: { Label("Not an item", systemImage: "xmark") }
                        if item.productId != nil {
                            Button {
                                Task {
                                    if let updated = await viewModel.linkLineItem(id: item.id, productId: nil) {
                                        replaceLineItem(updated)
                                    }
                                }
                            } label: { Label("Unlink", systemImage: "link.badge.minus") }
                            .tint(Color.MW.orange)
                        }
                    }
                }
            }
            if isEditable && lineItemsLoaded {
                Button {
                    showAddItem = true
                } label: {
                    Label("Add missed item", systemImage: "plus.circle")
                        .font(.subheadline)
                        .foregroundStyle(Color.MW.green)
                }
            }
        } header: {
            Text("Line items")
        } footer: {
            if isEditable {
                Text("Tap to rename, swipe left to remove or unlink. Linking an item to a CRM product teaches the vendor catalog and SKU memory for next time.")
            }
        }
    }

    private var receiptURL: URL? {
        guard let s = expense.receiptUrl else { return nil }
        return URL(string: s)
    }

    /// Editable in any status except sent (forwarded to accounting) — matches the server guard.
    private var isEditable: Bool {
        expense.status != "forwarded" && !expense.isForwarded
    }

    /// Draft/pending expenses can be approved or rejected; approved ones can be sent.
    /// Already-forwarded or already-rejected expenses have no further action here.
    private func canActOn(_ expense: Expense) -> Bool {
        ["draft", "pending_approval", "approved"].contains(expense.status)
    }

    // MARK: - Sections

    private var imageSection: some View {
        Section {
            HStack {
                Spacer()
                if let url = receiptURL {
                    AsyncImage(url: url) { img in
                        img.resizable().scaledToFit()
                            .frame(maxHeight: 220)
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
                            .onTapGesture { showFullImage = true }
                    } placeholder: {
                        ProgressView().frame(height: 160)
                    }
                } else {
                    RoundedRectangle(cornerRadius: 10)
                        .fill(Color(.systemGray5))
                        .frame(height: 120)
                        .overlay { Image(systemName: "doc.text.image").font(.largeTitle).foregroundStyle(.secondary) }
                }
                Spacer()
            }
        }
    }

    private var statusSection: some View {
        Section {
            HStack {
                Text("Status")
                Spacer()
                statusBadge
            }
            if let score = expense.anomalyScore, score > 0 {
                RiskRingView(score: score)
            }
        }
    }

    private var statusBadge: some View {
        let (label, color): (String, Color) = switch expense.status {
        case "forwarded":        ("Sent", .blue)
        case "approved":         ("Approved", Color.MW.green)
        case "rejected":         ("Rejected", .red)
        case "pending_approval": ("Pending Approval", Color.MW.orange)
        default:                 ("Draft", Color.MW.orange)
        }
        return Text(label)
            .font(.caption.weight(.semibold))
            .padding(.horizontal, 8).padding(.vertical, 3)
            .background(color.opacity(0.12))
            .foregroundStyle(color)
            .clipShape(Capsule())
    }

    private var detailsSection: some View {
        Section("Details") {
            detailRow("Vendor", expense.displayVendor)
            detailRow("Date", expense.expenseDate)
            detailRow("Total", String(format: "$%.2f", expense.total))
            if let category = expense.accountingCategory, !category.isEmpty {
                detailRow("Category", category)
            }
            if let payment = expense.paymentMethod, !payment.isEmpty {
                detailRow("Payment", payment)
            }
            if let description = expense.description, !description.isEmpty {
                detailRow("Description", description)
            }
        }
    }

    private func detailRow(_ label: String, _ value: String) -> some View {
        HStack {
            Text(label).foregroundStyle(.secondary)
            Spacer()
            Text(value).multilineTextAlignment(.trailing)
        }
    }

    // MARK: - Actions

    private var actionBar: some View {
        HStack(spacing: 10) {
            if viewModel.isPerformingAction {
                ProgressView().frame(maxWidth: .infinity)
            } else if expense.status == "approved" {
                actionButton(title: "Send to Accounting", systemImage: "paperplane.fill", color: Color.MW.green) {
                    Task { await performSend() }
                }
            } else {
                actionButton(title: "Reject", systemImage: "xmark", color: .red) {
                    rejectReason = ""
                    showRejectSheet = true
                }
                actionButton(title: "Approve", systemImage: "checkmark", color: Color.MW.green) {
                    Task { await performApprove() }
                }
            }
        }
        .padding(.horizontal)
        .padding(.vertical, 10)
        .background(.bar)
    }

    private func actionButton(title: String, systemImage: String, color: Color, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Label(title, systemImage: systemImage)
                .font(.subheadline.weight(.semibold))
                .frame(maxWidth: .infinity, minHeight: 44)
                .background(color)
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 10))
        }
    }

    private var rejectSheet: some View {
        NavigationStack {
            Form {
                Section("Reason for rejection") {
                    TextField("Explain why this expense is being rejected…", text: $rejectReason, axis: .vertical)
                        .lineLimit(3...6)
                }
            }
            .navigationTitle("Reject Receipt")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showRejectSheet = false }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Reject") {
                        Task { await performReject() }
                    }
                    .foregroundStyle(.red)
                    .disabled(rejectReason.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
        }
        .presentationDetents([.medium])
    }

    private func performApprove() async {
        if await viewModel.approve(expenseId: expense.id) { dismiss() }
    }

    private func performReject() async {
        let reason = rejectReason.trimmingCharacters(in: .whitespacesAndNewlines)
        if await viewModel.reject(expenseId: expense.id, reason: reason) {
            showRejectSheet = false
            dismiss()
        }
    }

    private func performSend() async {
        if await viewModel.send(expenseId: expense.id) { dismiss() }
    }
}

// MARK: - Risk ring

/// Anomaly risk shown as an activity-ring gauge instead of a bare number — a lone integer
/// ("20") gives the approver no scale or reference. The ring fills by score, colored by tier,
/// with a plain-language label + hint so the Approve/Reject call is obvious at a glance.
///
/// Bands match the rest of the system (AnomalyDetector.php, web/Android anomaly icon,
/// Expense.anomalyTier): >30 high, 16–30 medium, 1–15 low.
private struct RiskRingView: View {
    let score: Int

    /// Score is the max of triggered anomaly rules (top rule = 35), so realistic values are
    /// ~0–35. Cap the ring at 40 rather than 100 so a real score reads as a meaningful fill
    /// instead of a near-empty ring that looks "safe".
    private var fraction: Double { min(Double(score) / 40.0, 1.0) }

    private var tier: (label: String, hint: String, color: Color) {
        switch score {
        case 31...:   return ("High risk",   "Verify before approving", .red)
        case 16...30: return ("Medium risk", "Review before approving", Color.MW.orange)
        default:      return ("Low risk",    "Looks routine",           Color.MW.green)
        }
    }

    var body: some View {
        HStack(spacing: 14) {
            ZStack {
                Circle()
                    .stroke(Color(.systemGray5), lineWidth: 6)
                Circle()
                    .trim(from: 0, to: fraction)
                    .stroke(tier.color, style: StrokeStyle(lineWidth: 6, lineCap: .round))
                    .rotationEffect(.degrees(-90))
                Text("\(score)")
                    .font(.callout.weight(.semibold))
                    .foregroundStyle(.primary)
            }
            .frame(width: 54, height: 54)

            VStack(alignment: .leading, spacing: 2) {
                Text(tier.label)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(tier.color)
                Text(tier.hint)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 4)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Risk score \(score), \(tier.label)")
    }
}

// MARK: - Product link sheet

/// Search CRM products and link a receipt line item to one — the mobile mirror of the
/// desktop "Link" popover. Server-side this trains the vendor catalog alias and the
/// SKU → product memory, so the next receipt from this vendor auto-links.
private struct ProductLinkSheet: View {
    @ObservedObject var viewModel: ReceiptsViewModel
    let lineItem: StoredLineItem
    let onPick: (Int) -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var query = ""
    @State private var results: [ProductSearchResult] = []
    @State private var searchTask: Task<Void, Never>?

    var body: some View {
        NavigationStack {
            List {
                Section {
                    Text(lineItem.name).font(.subheadline.weight(.semibold))
                    if let sku = lineItem.skuRaw, !sku.isEmpty {
                        Text("SKU \(sku)").font(.caption).foregroundStyle(.secondary)
                    }
                } header: { Text("Receipt line") }
                Section {
                    ForEach(results) { p in
                        Button {
                            onPick(p.id)
                            dismiss()
                        } label: {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(p.name).foregroundStyle(.primary)
                                if let sku = p.sku, !sku.isEmpty {
                                    Text(sku).font(.caption).foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                    if results.isEmpty && query.count >= 2 {
                        Text("No products match").font(.caption).foregroundStyle(.secondary)
                    }
                } header: { Text("CRM product") }
            }
            .searchable(text: $query, placement: .navigationBarDrawer(displayMode: .always), prompt: "Search products by name or SKU")
            .onChange(of: query) { _, q in
                searchTask?.cancel()
                let trimmed = q.trimmingCharacters(in: .whitespaces)
                guard trimmed.count >= 2 else { results = []; return }
                searchTask = Task {
                    try? await Task.sleep(nanoseconds: 250_000_000)
                    guard !Task.isCancelled else { return }
                    let r = await viewModel.searchProducts(trimmed)
                    guard !Task.isCancelled else { return }
                    results = r
                }
            }
            .navigationTitle("Link Product")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }.foregroundStyle(Color.MW.green)
                }
            }
            .task {
                // Seed with the line's own name so the likely match is one tap away
                let seed = lineItem.name.trimmingCharacters(in: .whitespaces)
                if seed.count >= 2 { results = await viewModel.searchProducts(seed) }
            }
        }
        .presentationDragIndicator(.visible)
    }
}

// MARK: - Edit expense

/// Edit an existing receipt's fields from the phone — the piece needed to actually run
/// the business on mobile: fixing OCR mistakes (wrong dates/vendors/totals) without the
/// web app. Mirrors the OCR-review form; saves via ExpenseService::update() server-side,
/// which is ownership- and status-guarded (blocked once sent to accounting).
private struct EditExpenseView: View {

    @ObservedObject var viewModel: ReceiptsViewModel
    let expense: Expense
    @Binding var isPresented: Bool
    let onSaved: () -> Void

    @State private var vendorName:     String
    @State private var expenseDate:    Date
    @State private var amount:         String
    @State private var gst:            String
    @State private var total:          String
    @State private var category:       String
    @State private var paymentMethod:  String
    @State private var descriptionText: String

    private let categories = [
        "Materials", "Fuel", "Equipment", "Subcontractor", "Disposal",
        "Safety", "Tools", "Office", "Meals", "Other",
    ]
    private let paymentMethods = ["credit_card", "debit", "cash", "etransfer", "company_card"]

    init(viewModel: ReceiptsViewModel, expense: Expense, isPresented: Binding<Bool>, onSaved: @escaping () -> Void) {
        self.viewModel    = viewModel
        self.expense      = expense
        self._isPresented = isPresented
        self.onSaved      = onSaved
        _vendorName      = State(initialValue: expense.vendorNameRaw ?? expense.vendorName ?? "")
        _expenseDate     = State(initialValue: Self.parseDate(expense.expenseDate))
        _amount          = State(initialValue: expense.amount.map { String(format: "%.2f", $0) } ?? "")
        _gst             = State(initialValue: expense.gstAmount.map { String(format: "%.2f", $0) } ?? "")
        _total           = State(initialValue: String(format: "%.2f", expense.total))
        _category        = State(initialValue: expense.accountingCategory ?? "")
        _paymentMethod   = State(initialValue: expense.paymentMethod ?? "credit_card")
        _descriptionText = State(initialValue: expense.description ?? "")
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("Vendor") {
                    TextField("Vendor name", text: $vendorName)
                    DatePicker("Date", selection: $expenseDate, displayedComponents: .date)
                        .tint(Color.MW.green)
                }
                Section("Amounts") {
                    amountRow("Subtotal", $amount)
                    amountRow("GST", $gst)
                    Divider()
                    amountRow("Total", $total, bold: true)
                }
                Section("Category") {
                    Picker("Category", selection: $category) {
                        Text("—").tag("")
                        ForEach(categories, id: \.self) { Text($0).tag($0) }
                        if !category.isEmpty && !categories.contains(category) {
                            Text(category).tag(category)   // preserve a non-standard existing value
                        }
                    }
                    Picker("Payment", selection: $paymentMethod) {
                        ForEach(paymentMethods, id: \.self) { Text(paymentLabel($0)).tag($0) }
                    }
                }
                Section("Description") {
                    TextField("Optional note", text: $descriptionText, axis: .vertical)
                        .lineLimit(2...4)
                }
                if let err = viewModel.saveError {
                    Section { Text(err).foregroundStyle(.red).font(.subheadline) }
                }
            }
            .navigationTitle("Edit Receipt")
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
    }

    private func amountRow(_ label: String, _ value: Binding<String>, bold: Bool = false) -> some View {
        HStack {
            Text(label)
                .font(bold ? .subheadline.weight(.semibold) : .body)
                .foregroundStyle(bold ? .primary : .secondary)
            Spacer()
            TextField("0.00", text: value)
                .keyboardType(.decimalPad)
                .multilineTextAlignment(.trailing)
                .font(bold ? .subheadline.weight(.semibold) : .body)
                .frame(width: 100)
        }
    }

    private func paymentLabel(_ v: String) -> String {
        switch v {
        case "credit_card":  return "Credit Card"
        case "company_card": return "Company Card"
        case "etransfer":    return "e-Transfer"
        default:             return v.capitalized
        }
    }

    private func save() async {
        let ok = await viewModel.updateExpense(
            expenseId:     expense.id,
            vendorName:    vendorName.trimmingCharacters(in: .whitespacesAndNewlines),
            date:          Self.isoFormatter.string(from: expenseDate),
            amount:        Double(amount) ?? 0,
            gst:           Double(gst)    ?? 0,
            total:         Double(total)  ?? 0,
            category:      category,
            paymentMethod: paymentMethod,
            description:   descriptionText
        )
        if ok {
            isPresented = false
            onSaved()
        }
    }

    private static let isoFormatter: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale = Locale(identifier: "en_US_POSIX")
        return f
    }()

    private static func parseDate(_ s: String) -> Date {
        Self.isoFormatter.date(from: String(s.prefix(10))) ?? Date()
    }
}

// MARK: - Full-screen zoomable receipt

/// Tap a receipt thumbnail to inspect it full-screen. Supports pinch-to-zoom,
/// double-tap to toggle zoom, and drag-to-pan while zoomed — for reading faint
/// handwritten totals/GST that are illegible at thumbnail size.
private struct ZoomableReceiptView: View {
    let url: URL
    @Environment(\.dismiss) private var dismiss

    @State private var scale: CGFloat = 1
    @GestureState private var pinch: CGFloat = 1
    @State private var offset: CGSize = .zero
    @GestureState private var drag: CGSize = .zero

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()

            AsyncImage(url: url) { phase in
                if let img = phase.image {
                    img.resizable().scaledToFit()
                        .scaleEffect(max(1, scale * pinch))
                        .offset(x: offset.width + drag.width, y: offset.height + drag.height)
                        .gesture(
                            MagnificationGesture()
                                .updating($pinch) { value, state, _ in state = value }
                                .onEnded { value in
                                    scale = min(max(scale * value, 1), 5)
                                    if scale == 1 { offset = .zero }
                                }
                        )
                        .simultaneousGesture(
                            DragGesture()
                                .updating($drag) { value, state, _ in
                                    if scale > 1 { state = value.translation }
                                }
                                .onEnded { value in
                                    guard scale > 1 else { return }
                                    offset.width += value.translation.width
                                    offset.height += value.translation.height
                                }
                        )
                        .onTapGesture(count: 2) {
                            withAnimation(.spring(response: 0.3)) {
                                if scale > 1 { scale = 1; offset = .zero } else { scale = 2.5 }
                            }
                        }
                } else if phase.error != nil {
                    VStack(spacing: 8) {
                        Image(systemName: "exclamationmark.triangle").font(.largeTitle)
                        Text("Couldn't load image").font(.footnote)
                    }
                    .foregroundStyle(.white.opacity(0.8))
                } else {
                    ProgressView().tint(.white)
                }
            }

            VStack {
                HStack {
                    Spacer()
                    Button { dismiss() } label: {
                        Image(systemName: "xmark.circle.fill")
                            .font(.system(size: 30))
                            .symbolRenderingMode(.palette)
                            .foregroundStyle(.white, .black.opacity(0.4))
                            .padding()
                    }
                }
                Spacer()
            }
        }
    }
}

// MARK: - Preview

#Preview("Risk ring — tiers") {
    Form {
        Section("Risk tiers") {
            RiskRingView(score: 10)   // Low   → green
            RiskRingView(score: 15)   // Low   → green (band edge)
            RiskRingView(score: 16)   // Med   → orange (band edge)
            RiskRingView(score: 20)   // Med   → orange (the example receipt)
            RiskRingView(score: 30)   // Med   → orange (band edge)
            RiskRingView(score: 31)   // High  → red (band edge)
            RiskRingView(score: 35)   // High  → red
        }
    }
}
