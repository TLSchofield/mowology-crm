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
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    @ObservedObject var viewModel: ReceiptsViewModel
    let expense: Expense
    /// Admins see Penny's explanation of the risk score; crew see the bare ring (above 15 only).
    var isAdmin: Bool = false
    #if DEBUG
    /// `-mwReceiptSheetDemo`: invented Penny read and photo, so the layout can be seen without sign-in or client data.
    var seededRead: PennyRead? = nil
    var seededImage: UIImage? = nil
    #endif
    @Environment(\.dismiss) private var dismiss

    // Penny's reasons for the risk score (bookkeeper-mobile ?mode=risk), loaded lazily.
    @State private var risk: ReceiptRiskResponse?
    @State private var riskFailed = false

    // Penny's read (bookkeeper-mobile ?mode=suggestion): her pending suggestion where it differs
    // from the receipt — the reader may have missed items she read (Chevron #424, 2026-10-13).
    @State private var pennyRead: PennyRead?
    @State private var receiptImage: UIImage?
    @State private var showsMoreDetails = false

    @State private var showRejectSheet = false
    @State private var rejectReason = ""
    @State private var showErrorAlert = false
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
    @State private var mergedCopies: [BKMergedCopy] = []
    @State private var splitting: Int?
    @State private var mergedNote: String?
    @State private var mergedPhoto: URL?

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                if let receiptImage {
                    pinnedReceipt(receiptImage)
                }
                Form {
                    if let read = pennyRead {
                        pennyReadSection(read)
                        if hasVisibleRisk { riskSection }
                    } else {
                        statusSection
                        detailsSection
                    }
                    if isAdmin && !mergedCopies.isEmpty { mergedSection }
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
        .task(id: expense.id) { if isAdmin { mergedCopies = await viewModel.loadMerged(expenseId: expense.id) } }
        .fullScreenCover(item: Binding(get: { mergedPhoto.map { IdentURL(url: $0) } }, set: { mergedPhoto = $0?.url })) { t in
            ZoomableReceiptView(url: t.url)
        }
        #if DEBUG
        .onAppear { if let r = seededRead { pennyRead = r }; if let i = seededImage { receiptImage = i } }
        .task(id: expense.id) { if seededRead == nil { pennyRead = await viewModel.loadPennyRead(expenseId: expense.id) } }
        #else
        .task(id: expense.id) { pennyRead = await viewModel.loadPennyRead(expenseId: expense.id) }
        #endif
        .task(id: receiptURL) { await loadReceiptImage() }
        .sheet(item: $renameItem) { item in
            VendorItemPickerSheet(title: "Rename item", vendorId: expense.vendorId, viewModel: viewModel,
                                  name: $renameText, amount: .constant(""), showsAmount: false) {
                let v = renameText.trimmingCharacters(in: .whitespaces)
                if !v.isEmpty {
                    Task {
                        if let updated = await viewModel.updateLineItem(id: item.id, name: v, quantity: nil, unitPrice: nil, lineTotal: nil) {
                            replaceLineItem(updated)
                        }
                    }
                }
                renameItem = nil
            } onCancel: {
                renameItem = nil
            }
        }
        .sheet(isPresented: $showAddItem) {
            VendorItemPickerSheet(title: "Add missed item", vendorId: expense.vendorId, viewModel: viewModel,
                                  name: $addItemName, amount: $addItemAmount, showsAmount: true) {
                let n = addItemName.trimmingCharacters(in: .whitespaces)
                let amt = ReceiptNumber.parse(addItemAmount) ?? 0
                addItemName = ""; addItemAmount = ""
                guard !n.isEmpty else { return }
                Task {
                    if let added = await viewModel.addLineItem(expenseId: expense.id, name: n, lineTotal: amt, productId: nil) {
                        lineItems.append(added)
                    }
                }
                showAddItem = false
            } onCancel: {
                addItemName = ""; addItemAmount = ""; showAddItem = false
            }
        }
    }

    // MARK: - Penny's read

    private func refreshAll() async {
        await loadLineItems()
        pennyRead = await viewModel.loadPennyRead(expenseId: expense.id)
        if isAdmin, risk == nil { riskFailed = false; await loadRisk() }
    }

    private func pennyReadSection(_ read: PennyRead) -> some View {
        let fieldRows = ReceiptCompareRules.fields(read.fields, status: expense.status)
        let itemRows = read.items.map(ReceiptCompareRules.items) ?? []
        let selection = ReceiptCompareRules.selection(differs: read.differs, fieldRows: fieldRows, itemRows: itemRows)

        return Section {
            VStack(spacing: 0) {
                comparisonHeadings
                ForEach(fieldRows) { comparisonField($0) }
                if let items = read.items { comparisonItems(itemRows, items: items) }
            }
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(ItRadius.md)))
            .overlay { RoundedRectangle(cornerRadius: appStyle.cornerRadius(ItRadius.md)).stroke(appStyle.meta.opacity(0.25)) }

            versionActions(read, selection: selection)
            if hasMoreDetails {
                DisclosureGroup("More details", isExpanded: $showsMoreDetails) {
                    moreDetails
                }
            }
        }
        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
    }

    private var comparisonHeadings: some View {
        HStack(spacing: 0) {
            Text("Read").frame(maxWidth: .infinity, alignment: .leading)
            Divider()
            Text("Penny").frame(maxWidth: .infinity, alignment: .leading)
        }
        .font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.meta)
        .padding(ItSpace.s3).background(appStyle.sunk)
        .accessibilityHidden(dynamicTypeSize.isAccessibilitySize)
    }

    private func comparisonField(_ row: ReceiptCompareRow) -> some View {
        VStack(alignment: .leading, spacing: ItSpace.s2) {
            Text(row.label).font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.meta)
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: ItSpace.s2) {
                    stackedValue("Read", row.current, fallback: "Missing", state: row.state, proposed: false)
                    stackedValue("Penny", row.proposed, fallback: "Missing", state: row.state, proposed: true)
                }
            } else {
                ViewThatFits(in: .horizontal) {
                HStack(alignment: .top, spacing: 0) {
                    compareValue(row.current, fallback: "Missing", state: row.state, proposed: false)
                    Divider()
                    compareValue(row.proposed, fallback: "Missing", state: row.state, proposed: true)
                }
                VStack(alignment: .leading, spacing: ItSpace.s2) {
                    stackedValue("Read", row.current, fallback: "Missing", state: row.state, proposed: false)
                    stackedValue("Penny", row.proposed, fallback: "Missing", state: row.state, proposed: true)
                }
                }
            }
        }
        .padding(ItSpace.s3).overlay(alignment: .bottom) { Divider() }
    }

    private func compareValue(_ value: String?, fallback: String, state: ReceiptCompareValueState, proposed: Bool) -> some View {
        Text(value ?? fallback)
            .font(appStyle.font(.subheadline, weight: proposed && state != .unchanged ? .semibold : .regular))
            .foregroundStyle(value == nil ? appStyle.meta : appStyle.text)
            .fixedSize(horizontal: false, vertical: true)
            .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, ItSpace.s2)
            .background(compareHighlight(state, proposed: proposed))
    }

    private func stackedValue(_ heading: String, _ value: String?, fallback: String, state: ReceiptCompareValueState, proposed: Bool) -> some View {
        VStack(alignment: .leading, spacing: ItSpace.s1) {
            Text(heading).font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
            compareValue(value, fallback: fallback, state: state, proposed: proposed)
        }
    }

    private func compareHighlight(_ state: ReceiptCompareValueState, proposed: Bool) -> Color {
        guard state != .unchanged else { return .clear }
        return proposed ? pennyBrand.opacity(0.08) : .clear
    }

    private func comparisonItems(_ rows: [ReceiptCompareItemRow], items: PennyReadItems) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            Text("Items").font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.meta).padding(ItSpace.s3)
            ForEach(rows) { row in
                Group {
                    if dynamicTypeSize.isAccessibilitySize {
                        stackedItem(row)
                    } else {
                        ViewThatFits(in: .horizontal) {
                            HStack(alignment: .top, spacing: 0) {
                                itemValue(row.currentName, row.currentAmount, fallback: "Missing", state: row.state, proposed: false)
                                Divider()
                                itemValue(row.proposedName, row.proposedAmount, fallback: "Missing", state: row.state, proposed: true)
                            }
                            stackedItem(row)
                        }
                    }
                }
                .padding(ItSpace.s3).overlay(alignment: .bottom) { Divider() }
            }
            HStack(spacing: 0) {
                itemValue("Sum", items.receiptSum, fallback: "Missing", state: items.receiptSum == items.pennySum ? .unchanged : .changed, proposed: false)
                Divider()
                itemValue("Sum", items.pennySum, fallback: "Missing", state: items.receiptSum == items.pennySum ? .unchanged : .changed, proposed: true)
            }
            .padding(ItSpace.s3)
        }
    }

    private func stackedItem(_ row: ReceiptCompareItemRow) -> some View {
        VStack(alignment: .leading, spacing: ItSpace.s2) {
            Text("Read").font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
            itemValue(row.currentName, row.currentAmount, fallback: "Missing", state: row.state, proposed: false)
            Text("Penny").font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
            itemValue(row.proposedName, row.proposedAmount, fallback: "Missing", state: row.state, proposed: true)
        }
    }

    private func itemValue(_ name: String?, _ amount: Double?, fallback: String, state: ReceiptCompareValueState, proposed: Bool) -> some View {
        HStack(alignment: .firstTextBaseline) {
            Text(name ?? fallback).fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: ItSpace.s2)
            if let amount { Text(String(format: "$%.2f", amount)).lineLimit(1).minimumScaleFactor(0.8) }
        }
        .font(appStyle.font(.subheadline, weight: proposed && state != .unchanged ? .semibold : .regular))
        .foregroundStyle(name == nil ? appStyle.meta : appStyle.text)
        .frame(maxWidth: .infinity, alignment: .leading).background(compareHighlight(state, proposed: proposed))
    }

    private func versionActions(_ read: PennyRead, selection: ReceiptCompareSelection) -> some View {
        HStack(spacing: ItSpace.s3) {
            versionButtons(read, selection: selection)
        }
        .disabled(viewModel.isPerformingAction)
    }

    @ViewBuilder private func versionButtons(_ read: PennyRead, selection: ReceiptCompareSelection) -> some View {
        if read.canKeep || selection == .current {
            versionButton("Keep", accessibilityLabel: "Keep the current version", selected: selection == .current, filled: false) {
                Task { await decidePenny(useHers: false) }
            }
            .disabled(!read.canKeep)
        }
        if read.canApply || selection == .penny {
            versionButton("Use Penny's", accessibilityLabel: "Use Penny's changes", selected: selection == .penny, filled: true) {
                Task { await decidePenny(useHers: true) }
            }
            .disabled(!read.canApply)
        }
    }

    private func versionButton(_ title: String, accessibilityLabel: String, selected: Bool, filled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: ItSpace.s2) {
                Text(title).font(appStyle.font(.subheadline, weight: .semibold))
                if selected { Image(systemName: "checkmark").font(appStyle.font(.caption, weight: .bold)) }
            }
            .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone)
            .background(filled ? pennyBrand : Color.clear)
            .foregroundStyle(filled ? appStyle.surface : appStyle.text)
            .overlay { RoundedRectangle(cornerRadius: appStyle.cornerRadius(ItRadius.md)).stroke(appStyle.meta.opacity(filled ? 0 : 0.4)) }
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(ItRadius.md)))
        }
        .buttonStyle(.borderless)
        .accessibilityLabel(accessibilityLabel)
        .accessibilityValue(selected ? "Selected" : "Not selected")
    }

    private var pennyBrand: Color { appStyle.palette.color(.brand) }

    private func decidePenny(useHers: Bool) async {
        guard let r = await viewModel.decidePennyRead(expenseId: expense.id, useHers: useHers), r.ok else { return }
        if r.approved == true {
            dismiss()
            return
        }
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

    // MARK: - Also on this purchase (merged duplicates, expense_merges)

    /// Copies merged into this receipt as duplicates. Split off brings one back as its own receipt
    /// (a wrong merge can be undone at any time — plan 2026-11-05).
    private var mergedSection: some View {
        Section {
            ForEach(mergedCopies) { c in
                HStack(spacing: 12) {
                    if let s = c.receiptPath, let url = URL(string: s) {
                        Button { mergedPhoto = url } label: {
                            AsyncImage(url: url) { img in img.resizable().scaledToFill() } placeholder: { ProgressView() }
                                .frame(width: 48, height: 60)
                                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(6)))
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel("Photo of receipt #\(c.copyId)")
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        Text("#\(c.copyId)").font(appStyle.font(.subheadline, weight: .semibold))
                        Text([c.expenseDate, c.total.map { String(format: "$%.2f", $0) }, c.mergedBy.map { "merged by \($0)" }]
                                .compactMap { $0 }.joined(separator: " · "))
                            .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    }
                    Spacer()
                    Button {
                        Task {
                            splitting = c.copyId
                            if let r = await viewModel.splitOff(copyId: c.copyId) {
                                mergedNote = r.message ?? r.error
                                if r.ok { mergedCopies.removeAll { $0.copyId == c.copyId } }
                            }
                            splitting = nil
                        }
                    } label: {
                        if splitting == c.copyId { ProgressView() } else { Text("Split off") }
                    }
                    .buttonStyle(.bordered)
                    .tint(appStyle.tenant)
                    .disabled(splitting != nil)
                    .accessibilityHint("Makes #\(c.copyId) its own receipt again. It goes back for approval.")
                }
            }
            if let n = mergedNote {
                Text(n).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            }
        } header: {
            Text("Also on this purchase")
        } footer: {
            Text("Copies merged into this receipt as duplicates. They're kept on record, not deleted.")
        }
        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
    }

    // MARK: - Sections

    private func pinnedReceipt(_ image: UIImage) -> some View {
        ReceiptZoomView(image: image)
            .accessibilityLabel("Receipt photo. Pinch to zoom, drag to move.")
            .frame(maxWidth: .infinity)
            .frame(height: UIScreen.main.bounds.height * (dynamicTypeSize.isAccessibilitySize ? 0.28 : 0.42))
            .background(appStyle.sunk)
            .overlay(alignment: .bottom) { Divider() }
    }

    private func loadReceiptImage() async {
        #if DEBUG
        if seededImage != nil { return }
        #endif
        receiptImage = nil
        guard let receiptURL else { return }
        do {
            let (data, response) = try await URLSession.shared.data(from: receiptURL)
            guard !(response is HTTPURLResponse) || (200..<300).contains((response as? HTTPURLResponse)?.statusCode ?? 0),
                  let image = UIImage(data: data) else { return }
            receiptImage = image
        } catch {
            // The photo is optional in this sheet; a failed load deliberately leaves no placeholder behind.
        }
    }

    private var hasMoreDetails: Bool {
        [expense.accountingCategory, expense.paymentMethod, expense.description]
            .contains { !($0?.isEmpty ?? true) }
    }

    @ViewBuilder private var moreDetails: some View {
        if let category = expense.accountingCategory, !category.isEmpty { detailRow("Category", category) }
        if let payment = expense.paymentMethod, !payment.isEmpty { detailRow("Payment", payment) }
        if let description = expense.description, !description.isEmpty { detailRow("Description", description) }
    }

    private var statusSection: some View {
        Section {
            HStack {
                Text("Status")
                Spacer()
                statusBadge
            }
            riskContent
        }
        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
    }

    private var riskSection: some View {
        Section {
            riskContent
        }
        .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
    }

    private var hasVisibleRisk: Bool {
        guard let score = expense.anomalyScore else { return false }
        return isAdmin ? score > 0 : score > 15
    }

    @ViewBuilder private var riskContent: some View {
            if let score = expense.anomalyScore, score > 0 {
                if isAdmin, let risk, !risk.summary.isEmpty {
                    PennyRiskRow(risk: risk, score: score, status: expense.status)
                } else if isAdmin || score > 15 {
                    // Admins: shown while Penny's reasons load, and if they can't be loaded.
                    RiskRingView(score: score, status: expense.status)
                }
            }
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
        case "forwarded":        ("Sent", appStyle.progress)
        case "approved":         ("Approved", appStyle.tenant)
        case "rejected":         ("Rejected", appStyle.blocked)
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
                actionButton(title: "Approve receipt", systemImage: "checkmark", color: appStyle.tenant) {
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
                .foregroundStyle(bold ? appStyle.text : appStyle.meta)
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

/// Tap a receipt thumbnail to inspect it full-screen: opens at the receipt's width, pinch / drag / double-tap
/// (ReceiptZoomView) — for reading faint handwritten totals/GST that are illegible at thumbnail size.
struct ZoomableReceiptView: View {
    @Environment(\.itAppStyle) private var appStyle
    let url: URL
    @Environment(\.dismiss) private var dismiss
    @State private var image: UIImage?
    @State private var failed: String?

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()

            if let image {
                ReceiptZoomView(image: image).ignoresSafeArea(edges: .bottom)
            } else if let failed {
                VStack(spacing: 8) {
                    Image(systemName: "exclamationmark.triangle").font(appStyle.font(.largeTitle))
                    Text("Couldn't load image").font(appStyle.font(.footnote))
                    Text(failed).font(appStyle.font(.caption2)).multilineTextAlignment(.center)
                }
                .foregroundStyle(.white.opacity(0.8))
                .padding()
            } else {
                ProgressView().tint(.white)
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
                    .accessibilityLabel("Close")
                }
                Spacer()
            }
        }
        .task(id: url) { await load() }
    }

    /// The same signed link AsyncImage loaded; the precise failure is kept (ErrorDetails), never a generic label.
    private func load() async {
        do {
            let (data, response) = try await URLSession.shared.data(from: url)
            if let http = response as? HTTPURLResponse, !(200..<300).contains(http.statusCode) {
                failed = "HTTP \(http.statusCode)"
                return
            }
            guard let ui = UIImage(data: data) else { failed = "The file is not an image (\(data.count) bytes)"; return }
            image = ui
        } catch {
            failed = ErrorDetails.describe(error)
            AppDiagnostics.shared.record(category: "ReceiptImage", message: failed ?? "")
        }
    }
}

/// Shared by pre-save review and saved-receipt detail. Suggestions are always filtered on-device,
/// so an expired copy remains useful while a refresh is unavailable.
struct VendorItemPickerSheet: View {
    @Environment(\.itAppStyle) private var appStyle
    let title: String
    let vendorId: Int?
    @ObservedObject var viewModel: ReceiptsViewModel
    @Binding var name: String
    @Binding var amount: String
    let showsAmount: Bool
    let onSubmit: () -> Void
    let onCancel: () -> Void

    @State private var suggestions: [VendorItemSuggestion] = []
    @State private var hasEditedName = false
    @FocusState private var nameFocused: Bool

    private var trimmedName: String { name.trimmingCharacters(in: .whitespacesAndNewlines) }
    private var matches: [VendorItemSuggestion] {
        VendorItemRules.filter(
            suggestions,
            query: VendorItemRules.pickerQuery(name: name, hasEditedName: hasEditedName)
        )
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("Item name (as printed)", text: $name)
                        .focused($nameFocused)
                        .textInputAutocapitalization(.words)
                        .onChange(of: name) { _, _ in hasEditedName = true }
                    if showsAmount {
                        TextField("Line total ($)", text: $amount)
                            .keyboardType(.decimalPad)
                    }
                }
                .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)

                if vendorId != nil && !matches.isEmpty {
                    Section("Supplier items") {
                        ForEach(matches) { item in
                            Button {
                                name = item.name
                                if showsAmount, let total = item.suggestedTotal {
                                    amount = String(format: "%.2f", total)
                                }
                            } label: {
                                HStack {
                                    Text(item.name)
                                        .foregroundStyle(appStyle.text)
                                        .multilineTextAlignment(.leading)
                                    Spacer()
                                    if let total = item.suggestedTotal {
                                        Text(total, format: .currency(code: "CAD"))
                                            .foregroundStyle(appStyle.meta)
                                    }
                                }
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                            .accessibilityLabel(item.suggestedTotal.map {
                                "\(item.name), \($0.formatted(.currency(code: "CAD")))"
                            } ?? item.name)
                        }
                    }
                    .listRowBackground(appStyle.isEnabled ? appStyle.surface : nil)
                }
            }
            .itAppForm()
            .itAppScreen()
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel", action: onCancel)
                        .foregroundStyle(appStyle.tenant)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(submitLabel, action: onSubmit)
                        .foregroundStyle(appStyle.tenant)
                        .disabled(trimmedName.isEmpty)
                }
            }
            .task(id: vendorId) {
                guard let vendorId else { nameFocused = true; return }
                suggestions = VendorItemCache.shared.items(vendorId: vendorId)
                suggestions = await viewModel.vendorItems(vendorId: vendorId)
                nameFocused = true
            }
        }
        .presentationDragIndicator(.visible)
    }

    private var submitLabel: String {
        if title == "Rename item" { return "Save" }
        return trimmedName.isEmpty ? "Add" : "Add ‘\(trimmedName)’"
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

#if DEBUG
/// Synthetic comparison states for checking long receipts, missing values, no differences,
/// accessibility text, and selection changes without a server or client data.
private struct ReceiptCompareDebugDemo: View {
    @Environment(\.itAppStyle) private var appStyle
    @State private var caseName = "Many items"
    @State private var usesLargeText = false
    @State private var selection = ReceiptCompareSelection.current

    private var rows: [ReceiptCompareRow] {
        switch caseName {
        case "Missing values":
            return [
                ReceiptCompareRow(id: "merchant", label: "Merchant", current: nil, proposed: "Garden Supply"),
                ReceiptCompareRow(id: "tax", label: "Tax", current: "$4.20", proposed: nil),
            ]
        case "No differences":
            return [ReceiptCompareRow(id: "total", label: "Total", current: "$42.00", proposed: "$42.00")]
        default:
            return (1...18).map {
                ReceiptCompareRow(id: "item-\($0)", label: "Item \($0)", current: "$\($0).00", proposed: "$\($0 + 1).00")
            }
        }
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                LazyVStack(alignment: .leading, spacing: ItSpace.s3) {
                    Picker("Case", selection: $caseName) {
                        ForEach(["Many items", "Missing values", "No differences"], id: \.self) { Text($0) }
                    }
                    .pickerStyle(.segmented)
                    Toggle("Accessibility text", isOn: $usesLargeText)
                    HStack {
                        Text("Read").frame(maxWidth: .infinity, alignment: .leading)
                        Divider()
                        Text("Penny").frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .font(appStyle.font(.caption, weight: .semibold))
                    .foregroundStyle(appStyle.meta)
                    ForEach(rows) { row in
                        VStack(alignment: .leading, spacing: ItSpace.s2) {
                            Text(row.label).font(appStyle.font(.caption, weight: .semibold))
                            ViewThatFits(in: .horizontal) {
                                HStack(spacing: 0) {
                                    Text(row.current ?? "Missing").frame(maxWidth: .infinity, alignment: .leading)
                                    Divider()
                                    Text(row.proposed ?? "Missing")
                                        .frame(maxWidth: .infinity, alignment: .leading)
                                        .background(row.state == .unchanged ? Color.clear : appStyle.palette.color(.brand).opacity(0.08))
                                }
                                VStack(alignment: .leading, spacing: ItSpace.s2) {
                                    Text("Read").font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                                    Text(row.current ?? "Missing")
                                    Text("Penny").font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                                    Text(row.proposed ?? "Missing")
                                        .background(row.state == .unchanged ? Color.clear : appStyle.palette.color(.brand).opacity(0.08))
                                }
                            }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(ItSpace.s3).background(appStyle.surface)
                    }
                    HStack(spacing: ItSpace.s3) {
                        demoButton("Keep", selected: selection == .current) { selection = .current }
                        demoButton("Use Penny's", selected: selection == .penny) { selection = .penny }
                    }
                }
                .padding(ItSpace.s3)
            }
            .environment(\.dynamicTypeSize, usesLargeText ? .accessibility3 : .large)
            .navigationTitle("Receipt compare demo")
        }
    }

    private func demoButton(_ title: String, selected: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: ItSpace.s2) {
                Text(title)
                if selected { Image(systemName: "checkmark") }
            }
            .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone)
        }
        .buttonStyle(.bordered)
        .tint(appStyle.palette.color(.brand))
    }
}

#Preview("Receipt compare — synthetic") {
    ReceiptCompareDebugDemo()
}
#endif

/// A URL that can drive .fullScreenCover(item:).
private struct IdentURL: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}
