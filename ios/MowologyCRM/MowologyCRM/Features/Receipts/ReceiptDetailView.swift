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
    @Environment(\.itAppStyle) private var appStyle

    @ObservedObject var viewModel: ReceiptsViewModel
    let expense: Expense
    /// Admins see Penny's explanation of the risk score; crew see the bare ring (above 15 only).
    var isAdmin: Bool = false
    @Environment(\.dismiss) private var dismiss

    // Penny's reasons for the risk score (bookkeeper-mobile ?mode=risk), loaded lazily.
    @State private var risk: ReceiptRiskResponse?
    @State private var riskFailed = false

    // Penny's read (bookkeeper-mobile ?mode=suggestion): her pending suggestion where it differs
    // from the receipt — the reader may have missed items she read (Chevron #424, 2026-10-13).
    @State private var pennyRead: PennyRead?
    @State private var pennyNote: String?

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
    @State private var lineItemsError: String?
    @State private var rescanning = false
    @State private var rescanNote: String?
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
                if let read = pennyRead, read.differs || read.appliedDraft {
                    pennyReadSection(read)
                }
                detailsSection
                lineItemsSection

                if expense.isArchived {
                    Section {
                        Label("Archived — original photo removed from the server; a thumbnail is shown here. The full-size image was emailed before removal.", systemImage: "archivebox")
                            .font(appStyle.font(.caption))
                            .foregroundStyle(appStyle.meta)
                    }
                    .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
                }
            }
            // Pull to refresh: a suggestion Penny made after the screen opened shows up here.
            .refreshable { await refreshAll() }
            .navigationTitle("Receipt")
            .navigationBarTitleDisplayMode(.inline)
            .itAppForm()
            .itAppScreen()
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                        .foregroundStyle(appStyle.tenant)
                }
                if isEditable {
                    ToolbarItem(placement: .primaryAction) {
                        Button("Edit") { showEditSheet = true }
                            .foregroundStyle(appStyle.tenant)
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
        .task(id: expense.id) { await loadRisk() }
        .task(id: expense.id) { pennyRead = await viewModel.loadPennyRead(expenseId: expense.id) }
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
                let amt = ReceiptNumber.parse(addItemAmount) ?? 0
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

    // MARK: - Penny's read

    private func refreshAll() async {
        await loadLineItems()
        pennyRead = await viewModel.loadPennyRead(expenseId: expense.id)
        if isAdmin, risk == nil { riskFailed = false; await loadRisk() }
    }

    private func pennyReadSection(_ read: PennyRead) -> some View {
        Section {
            if !read.differs {
                Label("Using Penny's version — waiting for the office to approve.", systemImage: "checkmark.circle")
                    .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            } else {
                ForEach(read.fields) { f in
                    VStack(alignment: .leading, spacing: 3) {
                        HStack(alignment: .firstTextBaseline) {
                            Text(f.label).font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.meta)
                            Spacer()
                            Text(f.penny ?? "—").font(appStyle.font(.subheadline, weight: .semibold)).foregroundStyle(appStyle.tenant)
                                .multilineTextAlignment(.trailing)
                        }
                        Text("Receipt says: \(f.receipt ?? "—")").font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                        if !f.reason.isEmpty {
                            Text(f.reason + (f.confidence == "low" ? " (she's unsure)" : ""))
                                .font(appStyle.font(.caption)).foregroundStyle(f.confidence == "low" ? appStyle.attention : appStyle.meta)
                        }
                    }
                }
                if let items = read.items {
                    VStack(alignment: .leading, spacing: 4) {
                        HStack {
                            Text("Penny's items").font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.meta)
                            Spacer()
                            Text(String(format: "$%.2f", items.pennySum)).font(appStyle.font(.caption, weight: .semibold))
                        }
                        ForEach(Array(items.penny.enumerated()), id: \.offset) { _, i in
                            HStack(alignment: .firstTextBaseline) {
                                Text(i.name).font(appStyle.font(.subheadline))
                                Spacer()
                                Text(String(format: "$%.2f", i.amount)).font(appStyle.font(.subheadline))
                            }
                        }
                        Text(items.receipt.isEmpty
                             ? "The receipt has no items."
                             : "The receipt has \(items.receipt.count) item\(items.receipt.count == 1 ? "" : "s") for " + String(format: "$%.2f", items.receiptSum) + ": "
                               + items.receipt.map { $0.name }.joined(separator: ", "))
                            .font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                    }
                }
                if !read.notes.isEmpty {
                    Text(read.notes).font(appStyle.font(.caption)).italic().foregroundStyle(appStyle.meta)
                }
                if read.canApply || read.canKeep {
                    HStack(spacing: 10) {
                        if read.canApply {
                            Button { Task { await decidePenny(useHers: true) } } label: {
                                Text("Use Penny's version").font(appStyle.font(.subheadline, weight: .semibold))
                                    .frame(maxWidth: .infinity, minHeight: appStyle.isEnabled ? 48 : 40)
                                    .background(appStyle.tenant).foregroundStyle(appStyle.onTenant)
                                    .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
                            }
                            .buttonStyle(.borderless)
                        }
                        if read.canKeep {
                            Button { Task { await decidePenny(useHers: false) } } label: {
                                Text("Keep mine").font(appStyle.font(.subheadline, weight: .semibold))
                                    .frame(maxWidth: .infinity, minHeight: appStyle.isEnabled ? 48 : 40)
                                    .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)).stroke(appStyle.meta.opacity(0.4)))
                                    .foregroundStyle(appStyle.text)
                            }
                            .buttonStyle(.borderless)
                        }
                    }
                    .disabled(viewModel.isPerformingAction)
                    if read.canApply {
                        Text(read.applyNote).font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                    }
                }
            }
            if let note = pennyNote {
                Text(note).font(appStyle.font(.caption)).foregroundStyle(appStyle.tenant)
            }
        } header: {
            Label("Penny's read", systemImage: "sparkles")
        } footer: {
            if read.differs {
                Text("Penny read this receipt differently. Using her version replaces the items and teaches the receipt reader this store's layout.")
            }
        }
        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
    }

    private func decidePenny(useHers: Bool) async {
        guard let r = await viewModel.decidePennyRead(expenseId: expense.id, useHers: useHers), r.ok else { return }
        if r.approved == true {
            dismiss()
            return
        }
        pennyNote = r.message
        await loadLineItems()
        pennyRead = await viewModel.loadPennyRead(expenseId: expense.id)
    }

    // MARK: - Line items

    private func loadLineItems() async {
        switch await viewModel.loadLineItems(expenseId: expense.id) {
        case .success(let r):
            lineItems = r.lineItems
            lineItemsSource = r.lineItemsSource
            lineItemsError = nil
        case .failure(let e):
            lineItemsError = (e as? APIError)?.errorDescription ?? e.localizedDescription
        }
        lineItemsLoaded = true
    }

    private func rescan() async {
        rescanning = true
        rescanNote = nil
        defer { rescanning = false }
        switch await viewModel.rescanLineItems(expenseId: expense.id) {
        case .success(let r):
            if let items = r.lineItems { lineItems = items }
            lineItemsSource = r.lineItemsSource
            lineItemsError = nil
            rescanNote = r.message ?? (lineItems.isEmpty ? "No item lines found on the photo." : "Read again — check the items below.")
        case .failure(let e):
            rescanNote = (e as? APIError)?.errorDescription ?? e.localizedDescription
        }
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
            } else if let err = lineItemsError {
                VStack(alignment: .leading, spacing: 6) {
                    Label("Couldn't load the items: \(err)", systemImage: "exclamationmark.triangle")
                        .font(appStyle.font(.caption)).foregroundStyle(appStyle.attention)
                    Button("Try again") { Task { lineItemsLoaded = false; await loadLineItems() } }
                        .font(appStyle.font(.caption, weight: .semibold)).buttonStyle(.borderless)
                }
            } else if lineItems.isEmpty {
                Text("No line items recorded").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            }
            if lineItemsSource == "llm" {
                Label("AI extracted — please verify each line", systemImage: "sparkles")
                    .font(appStyle.font(.caption2)).foregroundStyle(appStyle.progress)
            }
            ForEach(lineItems) { item in
                HStack(spacing: 6) {
                    VStack(alignment: .leading, spacing: 1) {
                        Text(item.name).font(appStyle.font(.subheadline))
                        HStack(spacing: 6) {
                            if let sku = item.skuRaw, !sku.isEmpty {
                                Text(sku).font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                            }
                            if let pn = item.productName {
                                Label(pn, systemImage: "link").font(appStyle.font(.caption2)).foregroundStyle(appStyle.tenant)
                            } else if isEditable && !item.isAdjustment {
                                Button("Link product") { linkItem = item }
                                    .font(appStyle.font(.caption2))
                                    .buttonStyle(.borderless)
                                    .foregroundStyle(appStyle.tenant)
                            }
                        }
                    }
                    if item.quantity > 1 {
                        Text("×\(item.quantity, specifier: "%g")").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    }
                    Spacer()
                    Text(String(format: "$%.2f", item.lineTotal))
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(item.lineTotal < 0 ? appStyle.blocked : appStyle.text)
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
                            .tint(appStyle.attention)
                        }
                    }
                }
            }
            if isEditable && lineItemsLoaded {
                Button {
                    showAddItem = true
                } label: {
                    Label("Add missed item", systemImage: "plus.circle")
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(appStyle.tenant)
                }
                if receiptURL != nil {
                    Button {
                        Task { await rescan() }
                    } label: {
                        HStack(spacing: 8) {
                            if rescanning { ProgressView().controlSize(.small) }
                            Label(rescanning ? "Reading the photo…" : (lineItems.isEmpty ? "Read the items from the photo" : "Rescan the photo"),
                                  systemImage: "doc.text.viewfinder")
                                .font(appStyle.font(.subheadline))
                                .foregroundStyle(appStyle.tenant)
                        }
                    }
                    .disabled(rescanning)
                    if let note = rescanNote {
                        Text(note).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    }
                }
            }
        } header: {
            Text("Line items")
        } footer: {
            if isEditable {
                Text("Tap to rename, swipe left to remove or unlink. Linking an item to a CRM product teaches the vendor catalog and SKU memory for next time.")
            }
        }
        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
    }

    private var receiptURL: URL? {
        guard let s = expense.receiptUrl else { return nil }
        return URL(string: s)
    }

    /// Editable in any status except sent (forwarded to accounting) — matches the server guard.
    private var isEditable: Bool {
        expense.status != "forwarded" && !expense.isForwarded
    }

    /// Draft/pending expenses can be approved or rejected from the phone. Sending to
    /// accounting is desktop-only, so approved/forwarded/rejected have no action here.
    private func canActOn(_ expense: Expense) -> Bool {
        ["draft", "pending_approval"].contains(expense.status)
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
                            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
                            .overlay(alignment: .bottomTrailing) {
                                Image(systemName: "arrow.up.left.and.arrow.down.right")
                                    .font(appStyle.font(.caption, weight: .bold))
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
                    RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                        .fill(appStyle.sunk)
                        .frame(height: 120)
                        .overlay { Image(systemName: "doc.text.image").font(appStyle.font(.largeTitle)).foregroundStyle(appStyle.meta) }
                }
                Spacer()
            }
        }
        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
    }

    private var statusSection: some View {
        Section {
            HStack {
                Text("Status")
                Spacer()
                statusBadge
            }
            if let score = expense.anomalyScore, score > 0 {
                if isAdmin, let risk, !risk.summary.isEmpty {
                    PennyRiskRow(risk: risk, score: score, status: expense.status)
                } else if isAdmin || score > 15 {
                    // Admins: shown while Penny's reasons load, and if they can't be loaded.
                    RiskRingView(score: score, status: expense.status)
                }
            }
        }
        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
    }

    private func loadRisk() async {
        guard isAdmin, let score = expense.anomalyScore, score > 0, risk == nil, !riskFailed else { return }
        if let r = await viewModel.loadRisk(expenseId: expense.id), r.ok {
            risk = r
        } else {
            riskFailed = true
        }
    }

    private var statusBadge: some View {
        let (label, color): (String, Color) = switch expense.status {
        case "forwarded":        ("Sent", .blue)
        case "approved":         ("Approved", appStyle.tenant)
        case "rejected":         ("Rejected", .red)
        case "pending_approval": ("Pending Approval", appStyle.attention)
        default:                 ("Draft", appStyle.attention)
        }
        return Text(label)
            .font(appStyle.font(.caption, weight: .semibold))
            .padding(.horizontal, 8).padding(.vertical, 3)
            .background(appStyle.statusColor(expense.status, legacy: color).opacity(0.12))
            .foregroundStyle(appStyle.statusColor(expense.status, legacy: color))
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
        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
    }

    private func detailRow(_ label: String, _ value: String) -> some View {
        HStack {
            Text(label).foregroundStyle(appStyle.meta)
            Spacer()
            Text(value).multilineTextAlignment(.trailing)
        }
    }

    // MARK: - Actions

    // Mobile is capture-only: sending to accounting happens on the desktop after the
    // admin has corrected the expense (those corrections are the learning signal), so
    // there is deliberately no "Send to Accounting" here — only review-queue triage.
    private var actionBar: some View {
        HStack(spacing: 10) {
            if viewModel.isPerformingAction {
                ProgressView().frame(maxWidth: .infinity)
            } else {
                actionButton(title: "Reject", systemImage: "xmark", color: appStyle.blocked) {
                    rejectReason = ""
                    showRejectSheet = true
                }
                actionButton(title: "Approve", systemImage: "checkmark", color: appStyle.tenant) {
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
                .font(appStyle.font(.subheadline, weight: .semibold))
                .frame(maxWidth: .infinity, minHeight: appStyle.isEnabled ? 48 : 44)
                .background(color)
                .foregroundStyle(appStyle.isEnabled && color == appStyle.tenant ? appStyle.onTenant : .white)
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
        }
    }

    private var rejectSheet: some View {
        NavigationStack {
            Form {
                Section("Reason for rejection") {
                    TextField("Explain why this expense is being rejected…", text: $rejectReason, axis: .vertical)
                        .lineLimit(3...6)
                }
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
            }
            .navigationTitle("Reject Receipt")
            .navigationBarTitleDisplayMode(.inline)
            .itAppForm()
            .itAppScreen()
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showRejectSheet = false }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Reject") {
                        Task { await performReject() }
                    }
                    .foregroundStyle(appStyle.blocked)
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

}

// MARK: - Risk ring

/// Anomaly risk shown as an activity-ring gauge instead of a bare number — a lone integer
/// ("20") gives the approver no scale or reference. The ring fills by score, colored by tier,
/// with a plain-language label + hint so the Approve/Reject call is obvious at a glance.
///
/// Bands match the rest of the system (AnomalyDetector.php, web/Android anomaly icon,
/// Expense.anomalyTier): >30 high, 16–30 medium, 1–15 low.
private struct RiskRingView: View {
    @Environment(\.itAppStyle) private var appStyle
    let score: Int
    var status: String = ""

    /// Already decided — the hint no longer asks for a review.
    private var isDone: Bool { ["approved", "forwarded", "sent"].contains(status) }

    /// Score is the max of triggered anomaly rules (top rule = 35), so realistic values are
    /// ~0–35. Cap the ring at 40 rather than 100 so a real score reads as a meaningful fill
    /// instead of a near-empty ring that looks "safe".
    private var fraction: Double { min(Double(score) / 40.0, 1.0) }

    private var tier: (label: String, hint: String, color: Color) {
        switch score {
        case 31...:   return ("High risk",   "Verify before approving", appStyle.blocked)
        case 16...30: return ("Medium risk", "Review before approving", appStyle.attention)
        default:      return ("Low risk",    "Looks routine",           appStyle.tenant)
        }
    }

    var body: some View {
        HStack(spacing: 14) {
            ZStack {
                Circle()
                    .stroke(appStyle.sunk, lineWidth: 6)
                Circle()
                    .trim(from: 0, to: fraction)
                    .stroke(tier.color, style: StrokeStyle(lineWidth: 6, lineCap: .round))
                    .rotationEffect(.degrees(-90))
                Text("\(score)")
                    .font(appStyle.font(.callout, weight: .semibold))
                    .foregroundStyle(appStyle.text)
            }
            .frame(width: 54, height: 54)

            VStack(alignment: .leading, spacing: 2) {
                Text(tier.label)
                    .font(appStyle.font(.subheadline, weight: .semibold))
                    .foregroundStyle(tier.color)
                Text(isDone ? "Checked" : tier.hint)
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
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
    @Environment(\.itAppStyle) private var appStyle
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
                    Text(lineItem.name).font(appStyle.font(.subheadline, weight: .semibold))
                    if let sku = lineItem.skuRaw, !sku.isEmpty {
                        Text("SKU \(sku)").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    }
                } header: { Text("Receipt line") }
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
                Section {
                    ForEach(results) { p in
                        Button {
                            onPick(p.id)
                            dismiss()
                        } label: {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(p.name).foregroundStyle(appStyle.text)
                                if let sku = p.sku, !sku.isEmpty {
                                    Text(sku).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                                }
                            }
                        }
                    }
                    if results.isEmpty && query.count >= 2 {
                        Text("No products match").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    }
                } header: { Text("CRM product") }
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
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
            .itAppForm()
            .itAppScreen()
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }.foregroundStyle(appStyle.tenant)
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
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var usesAccessibleAmounts: Bool { appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize }
    @Environment(\.itAppStyle) private var appStyle

    @ObservedObject var viewModel: ReceiptsViewModel
    let expense: Expense
    @Binding var isPresented: Bool
    let onSaved: () -> Void

    @State private var vendorName:     String
    @State private var expenseDate:    Date
    @State private var amount:         String
    @State private var gst:            String
    @State private var pst:            String
    @State private var total:          String
    @State private var category:       String
    @State private var paymentMethod:  String
    @State private var descriptionText: String

    private let categories = [
        "Materials", "Fuel", "Equipment", "Subcontractor", "Disposal",
        "Safety", "Tools", "Office", "Meals", "Other",
    ]
    private let paymentMethods = ["credit_card", "debit", "cash", "etransfer", "company_card", "owner_paid"]

    init(viewModel: ReceiptsViewModel, expense: Expense, isPresented: Binding<Bool>, onSaved: @escaping () -> Void) {
        self.viewModel    = viewModel
        self.expense      = expense
        self._isPresented = isPresented
        self.onSaved      = onSaved
        _vendorName      = State(initialValue: expense.vendorNameRaw ?? expense.vendorName ?? "")
        _expenseDate     = State(initialValue: Self.parseDate(expense.expenseDate))
        _amount          = State(initialValue: expense.amount.map { String(format: "%.2f", $0) } ?? "")
        _gst             = State(initialValue: expense.gstAmount.map { String(format: "%.2f", $0) } ?? "")
        _pst             = State(initialValue: expense.pstAmount.map { String(format: "%.2f", $0) } ?? "")
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
                        .tint(appStyle.tenant)
                }
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
                Section("Amounts") {
                    amountRow("Subtotal", $amount)
                    amountRow("GST", $gst)
                    amountRow("PST", $pst)
                    amountRow("Total", $total, bold: true)
                }
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
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
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
                Section("Description") {
                    TextField("Optional note", text: $descriptionText, axis: .vertical)
                        .lineLimit(2...4)
                }
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
                if let err = viewModel.saveError {
                    Section { Text(err).foregroundStyle(appStyle.blocked).font(appStyle.font(.subheadline)) }
                    .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
                }
            }
            .navigationTitle("Edit Receipt")
            .navigationBarTitleDisplayMode(.inline)
            .itAppForm()
            .itAppScreen()
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { isPresented = false }
                        .foregroundStyle(appStyle.tenant)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button {
                        Task { await save() }
                    } label: {
                        if viewModel.isSaving {
                            ProgressView().tint(appStyle.tenant)
                        } else {
                            Text("Save").font(appStyle.font(.subheadline, weight: .semibold))
                                .foregroundStyle(appStyle.tenant)
                        }
                    }
                    .disabled(viewModel.isSaving || total.isEmpty)
                }
            }
        }
        .presentationDragIndicator(.visible)
    }

    private func amountRow(_ label: String, _ value: Binding<String>, bold: Bool = false) -> some View {
        (usesAccessibleAmounts ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout())) {
            Text(label)
                .font(bold ? appStyle.font(.subheadline, weight: .semibold) : appStyle.font(.body))
                .foregroundStyle(bold ? .primary : .secondary)
            if !usesAccessibleAmounts { Spacer() }
            TextField("0.00", text: value)
                .keyboardType(.decimalPad)
                .multilineTextAlignment(.trailing)
                .font(bold ? appStyle.font(.subheadline, weight: .semibold) : appStyle.font(.body))
                .frame(width: usesAccessibleAmounts ? nil : 100)
        }
    }

    private func paymentLabel(_ v: String) -> String {
        switch v {
        case "credit_card":  return "Credit Card"
        case "company_card": return "Company Card"
        case "etransfer":    return "e-Transfer"
        case "owner_paid":   return "Paid personally (owner)"
        default:             return v.capitalized
        }
    }

    private func save() async {
        let ok = await viewModel.updateExpense(
            expenseId:     expense.id,
            vendorName:    vendorName.trimmingCharacters(in: .whitespacesAndNewlines),
            date:          Self.isoFormatter.string(from: expenseDate),
            amount:        ReceiptNumber.parse(amount) ?? 0,
            gst:           ReceiptNumber.parse(gst)    ?? 0,
            pst:           ReceiptNumber.parse(pst)    ?? 0,
            total:         ReceiptNumber.parse(total)  ?? 0,
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
struct ZoomableReceiptView: View {
    @Environment(\.itAppStyle) private var appStyle
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
                        Image(systemName: "exclamationmark.triangle").font(appStyle.font(.largeTitle))
                        Text("Couldn't load image").font(appStyle.font(.footnote))
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
                            .font(appStyle.font(size: 30))
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
