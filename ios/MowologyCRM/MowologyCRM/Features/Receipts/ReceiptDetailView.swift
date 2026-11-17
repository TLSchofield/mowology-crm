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
    /// Add `-mwReceiptSheetMismatch` for a cents mismatch and one newly added item.
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
    @State private var mergedCopies: [BKMergedCopy] = []
    @State private var splitting: Int?
    @State private var mergedNote: String?
    @State private var mergedPhoto: URL?

    // The grid edits one complete expense snapshot. A generation counter debounces saves
    // without keeping an unstructured Task alive beyond this view.
    @State private var draft: ReceiptSaveRecord?
    @State private var editedFields = Set<String>()
    @State private var readerChoices = Set<String>()
    @State private var pinnedPairs = Set<String>()
    @State private var showsAgreedRows = false
    @State private var categoryFocusTick = 0
    @State private var recordWasEdited = false
    @State private var saveGeneration = 0
    @State private var itemSaveGenerations: [Int: Int] = [:]
    @State private var itemWasEdited = false
    @State private var alreadySent = false
    @State private var gridItems: [ReceiptGridItemDraft] = []

    var body: some View {
        NavigationStack {
            ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(spacing: ItSpace.s4) {
                    if let receiptImage {
                        pinnedReceipt(receiptImage)
                            .padding(.horizontal, -ItSpace.s4)
                            .overlay(alignment: .topLeading) { statusBadge.padding(ItSpace.s2) }
                    } else {
                        statusBadge.frame(maxWidth: .infinity, alignment: .leading)
                    }
                    receiptGrid
                    DisclosureGroup("More", isExpanded: $showsMoreDetails) {
                        VStack(alignment: .leading, spacing: ItSpace.s4) {
                            Button("Edit all fields") { showEditSheet = true }.disabled(!isEditable)
                            Button("Reject receipt", role: .destructive) { rejectReason = ""; showRejectSheet = true }
                                .disabled(!canActOn(expense) || viewModel.isPerformingAction)
                            detailsContent
                            if hasVisibleRisk { riskContent }
                            if isAdmin && !mergedCopies.isEmpty { mergedContent }
                            lineItemsContent
                        }
                        .padding(.top, ItSpace.s3)
                    }
                    .tint(appStyle.palette.color(.brand))
                }
                .padding(.horizontal, ItSpace.s4)
            }
            // Pull to refresh: a suggestion Penny made after the screen opened shows up here.
            .refreshable { await refreshAll() }
            .onChange(of: categoryFocusTick) { _, _ in
                withAnimation { proxy.scrollTo("categoryRow", anchor: .top) }
            }
            .navigationTitle("Receipt")
            .navigationBarTitleDisplayMode(.inline)
            .itAppScreen()
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                        .foregroundStyle(appStyle.palette.color(.brand))
                }
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
        .task { await viewModel.loadCategories() }
        .task(id: expense.id) { await loadRisk() }
        .task(id: expense.id) { if isAdmin { mergedCopies = await viewModel.loadMerged(expenseId: expense.id) } }
        .fullScreenCover(item: Binding(get: { mergedPhoto.map { IdentURL(url: $0) } }, set: { mergedPhoto = $0?.url })) { t in
            ZoomableReceiptView(url: t.url)
        }
        #if DEBUG
        .onAppear {
            if let r = seededRead { pennyRead = r; prepareDraft(read: r); applyDebugMismatchSeed() }
            if let i = seededImage { receiptImage = i }
        }
        .task(id: expense.id) { if seededRead == nil { pennyRead = await viewModel.loadPennyRead(expenseId: expense.id); prepareDraft(read: pennyRead) } }
        #else
        .task(id: expense.id) { pennyRead = await viewModel.loadPennyRead(expenseId: expense.id); prepareDraft(read: pennyRead) }
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
    }

    // MARK: - Penny's read

    // Design round 4 (2026-11-17, Tim): only Penny's side is editable. Rows where Reader and Penny disagree (or that were just
    // fixed) sit on the line as a pair; everything that agrees is a Penny-only list. Later is the way out, no Reject here.
    private static let textKeys = ["vendor", "date"]
    private static let amountKeys = ["subtotal", "gst", "pst", "total"]

    private func isPair(_ key: String) -> Bool {
        if pinnedPairs.contains(key) || readerChoices.contains(key) { return true }
        guard let reader = readerValue(key) else { return false }
        return !valuesAgree(key: key, reader: reader, penny: pennyText(key))
    }

    private func itemIsPair(_ index: Int) -> Bool {
        gridItems[index].differs || gridItems[index].readerChosen || gridItems[index].dirty
    }

    private var pairedItems: [Int] { gridItems.indices.filter(itemIsPair) }
    private var comparedCount: Int { Self.textKeys.count + Self.amountKeys.count + gridItems.count }
    private var pairedCount: Int {
        (Self.textKeys + Self.amountKeys).filter(isPair).count + pairedItems.count
    }
    private var totalMismatch: Bool {
        guard let draft else { return false }
        return !ReceiptCompareRules.totalsAddUp(subtotal: draft.subtotal, gst: draft.gst, pst: draft.pst, total: draft.total)
    }

    private var receiptGrid: some View {
        VStack(spacing: 0) {
            if alreadySent {
                Text("Already sent to accounting")
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, ItSpace.s3)
            }
            if draft != nil {
                if pairedCount > 0 {
                    pairedSection
                    let agreed = comparedCount - pairedCount
                    if agreed > 0 { agreedDisclosure(count: agreed) }
                    categoryRow
                } else {
                    if pennyRead != nil { agreeLine }
                    pennyOnlyRows(keys: Self.textKeys + Self.amountKeys, items: Array(gridItems.indices), includeCategory: true)
                }
                addItemRow
                actionRow
            }
        }
        .disabled(alreadySent)
    }

    // MARK: Pairs on the line

    private var pairedSection: some View {
        VStack(spacing: 0) {
            gridHeadings
            ForEach(Self.textKeys.filter(isPair), id: \.self) { pairField($0) }
            ForEach(pairedItems, id: \.self) { pairItem($0) }
            ForEach(Self.amountKeys.filter(isPair), id: \.self) { pairField($0) }
        }
        .overlay { SpineLine(large: false).allowsHitTesting(false) }
    }

    private var gridHeadings: some View {
        HStack(spacing: SpineMetrics.slot) {
            Text("Reader").frame(maxWidth: .infinity, alignment: .trailing)
            Text("Penny").frame(maxWidth: .infinity, alignment: .leading)
        }
        .font(appStyle.font(.caption, weight: .semibold))
        .foregroundStyle(appStyle.meta)
        .padding(.vertical, ItSpace.s2)
    }

    /// The label sits on page colour so the line breaks round it (words never sit on the line); values hug the line;
    /// agreement is a ringed tick on the line.
    private func pairRow<Left: View, Right: View>(label: String, agreed: Bool,
                                                   @ViewBuilder left: () -> Left,
                                                   @ViewBuilder right: () -> Right) -> some View {
        VStack(spacing: ItSpace.s1) {
            Text(label)
                .font(appStyle.font(.caption, weight: .semibold))
                .foregroundStyle(appStyle.meta)
                .padding(.horizontal, ItSpace.s2)
                .background(appStyle.page)
                .accessibilityHidden(true)
            HStack(alignment: .top, spacing: 0) {
                left().frame(maxWidth: .infinity, alignment: .trailing).padding(.trailing, ItSpace.s1)
                ZStack {
                    if agreed {
                        Image(systemName: "checkmark.circle.fill")
                            .font(appStyle.font(.title3))
                            .foregroundStyle(pennyBrand)
                            .background(appStyle.page, in: Circle())
                            .accessibilityLabel("Agrees")
                    }
                }
                .frame(width: SpineMetrics.slot, height: ItSize.targetPhone)
                right().frame(maxWidth: .infinity, alignment: .leading).padding(.leading, ItSpace.s1)
            }
        }
        .font(appStyle.font(.subheadline))
        .padding(.vertical, ItSpace.s3)
    }

    private func pairField(_ key: String) -> some View {
        let isAmount = Self.amountKeys.contains(key)
        let reader = readerValue(key)
        let agreed = valuesAgree(key: key, reader: reader, penny: pennyText(key))
        let mismatch = key == "total" && totalMismatch
        return pairRow(label: label(for: key), agreed: agreed) {
            readerButton(isAmount ? reader.map(currencyText) : reader, key: key, agreed: agreed)
        } right: {
            VStack(alignment: .leading, spacing: ItSpace.s1) {
                if isAmount {
                    TextField("0.00", text: amountTextBinding(key))
                        .keyboardType(.decimalPad)
                } else {
                    TextField("-", text: textBinding(key), axis: .vertical)
                }
                if !agreed {
                    Label("Different", systemImage: "exclamationmark.triangle.fill")
                        .font(appStyle.font(.caption))
                }
                if mismatch {
                    Label("Does not add up", systemImage: "exclamationmark.triangle.fill")
                        .font(appStyle.font(.caption))
                }
            }
            .multilineTextAlignment(.leading)
            .foregroundStyle(agreed && !mismatch ? appStyle.text : appStyle.attention)
            .frame(minHeight: ItSize.targetPhone, alignment: .top)
            .padding(ItSpace.s1)
            .background(agreed && !mismatch ? Color.clear : appStyle.attention.opacity(0.08))
            .disabled(!canEditGrid)
            .accessibilityElement(children: .contain)
            .accessibilityLabel("Penny \(label(for: key))")
        }
    }

    private func pairItem(_ index: Int) -> some View {
        let agreed = !gridItems[index].differs
        return pairRow(label: "Item", agreed: agreed) {
            Button {
                guard canEditGrid, gridItems[index].readerName != nil else { return }
                gridItems[index].name = gridItems[index].readerName ?? ""
                gridItems[index].amount = gridItems[index].readerAmount ?? 0
                gridItems[index].readerChosen = true
                gridItems[index].dirty = true
                itemWasEdited = true
                Task { await saveGridItem(index) }
            } label: {
                VStack(alignment: .trailing, spacing: ItSpace.s1) {
                    itemText(name: gridItems[index].readerName, amount: gridItems[index].readerAmount)
                    if !agreed, gridItems[index].readerName != nil {
                        Text("Use Reader's").font(appStyle.font(.caption)).underline().foregroundStyle(pennyBrand)
                    }
                }
                .frame(minHeight: ItSize.targetPhone, alignment: .top)
                .foregroundStyle(agreed ? appStyle.meta : appStyle.text)
            }
            .buttonStyle(.plain)
            .disabled(!canEditGrid || agreed)
        } right: {
            VStack(alignment: .leading, spacing: ItSpace.s1) {
                itemEditor(index)
                if !agreed {
                    Label("Different", systemImage: "exclamationmark.triangle.fill")
                        .font(appStyle.font(.caption)).foregroundStyle(appStyle.attention)
                }
            }
            .padding(ItSpace.s1)
            .background(agreed ? Color.clear : appStyle.attention.opacity(0.08))
        }
    }

    private func readerButton(_ value: String?, key: String, agreed: Bool) -> some View {
        Button {
            guard canEditGrid, let value, !agreed else { return }
            chooseReader(value, field: key)
        } label: {
            VStack(alignment: .trailing, spacing: ItSpace.s1) {
                Text(value ?? "-").fixedSize(horizontal: false, vertical: true)
                if !agreed, value != nil {
                    Text("Use Reader's").font(appStyle.font(.caption)).underline().foregroundStyle(pennyBrand)
                }
            }
            .frame(minHeight: ItSize.targetPhone, alignment: .top)
            .foregroundStyle(agreed ? appStyle.meta : appStyle.text)
        }
        .buttonStyle(.plain)
        .disabled(!canEditGrid || agreed || value == nil)
        .accessibilityLabel(agreed ? "Reader \(label(for: key)) \(value ?? "")" : "Use Reader's \(label(for: key)) \(value ?? "")")
    }

    // MARK: Penny-only rows

    private var agreeLine: some View {
        HStack(spacing: ItSpace.s2) {
            Image(systemName: "checkmark.circle").foregroundStyle(pennyBrand)
            Text("Reader agrees on all \(comparedCount) fields")
        }
        .font(appStyle.font(.subheadline))
        .foregroundStyle(appStyle.meta)
        .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone, alignment: .leading)
    }

    private func agreedDisclosure(count: Int) -> some View {
        VStack(spacing: 0) {
            Button {
                withAnimation(.easeInOut(duration: 0.2)) { showsAgreedRows.toggle() }
            } label: {
                HStack(spacing: ItSpace.s1) {
                    Text("\(count) \(count == 1 ? "field agrees" : "fields agree")")
                    Image(systemName: showsAgreedRows ? "chevron.up" : "chevron.down").font(appStyle.font(.caption))
                }
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.text)
                .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone)
                .background(appStyle.page)
            }
            .buttonStyle(.plain)
            .accessibilityHint(showsAgreedRows ? "Hides them" : "Shows them")
            if showsAgreedRows {
                pennyOnlyRows(keys: (Self.textKeys + Self.amountKeys).filter { !isPair($0) },
                              items: gridItems.indices.filter { !itemIsPair($0) }, includeCategory: false)
            }
        }
    }

    @ViewBuilder
    private func pennyOnlyRows(keys: [String], items: [Int], includeCategory: Bool) -> some View {
        if keys.contains("vendor") { soloField("vendor") }
        if keys.contains("date") { soloField("date") }
        if includeCategory { categoryRow }
        ForEach(items, id: \.self) { soloItem($0) }
        ForEach(Self.amountKeys.filter(keys.contains), id: \.self) { soloField($0) }
    }

    private func soloField(_ key: String) -> some View {
        let mismatch = key == "total" && totalMismatch
        return VStack(alignment: .leading, spacing: ItSpace.s1) {
            Text(label(for: key)).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            Group {
                if Self.amountKeys.contains(key) {
                    TextField("0.00", text: amountTextBinding(key)).keyboardType(.decimalPad)
                } else {
                    TextField("-", text: textBinding(key), axis: .vertical)
                }
            }
            .font(appStyle.font(.body, weight: .medium))
            .foregroundStyle(mismatch ? appStyle.attention : appStyle.text)
            .frame(minHeight: ItSize.targetPhone - ItSpace.s4, alignment: .leading)
            .disabled(!canEditGrid)
            .accessibilityLabel("Penny \(label(for: key))")
            if mismatch {
                Label("Does not add up", systemImage: "exclamationmark.triangle.fill")
                    .font(appStyle.font(.caption)).foregroundStyle(appStyle.attention)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.vertical, ItSpace.s2)
        .overlay(alignment: .bottom) { Rectangle().fill(appStyle.line).frame(height: SpineMetrics.lineWidth) }
    }

    private func soloItem(_ index: Int) -> some View {
        VStack(alignment: .leading, spacing: ItSpace.s1) {
            Text("Item").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            itemEditor(index)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.vertical, ItSpace.s2)
        .overlay(alignment: .bottom) { Rectangle().fill(appStyle.line).frame(height: SpineMetrics.lineWidth) }
    }

    private func itemEditor(_ index: Int) -> some View {
        VStack(alignment: .leading, spacing: ItSpace.s1) {
            TextField("Item name", text: $gridItems[index].name, axis: .vertical)
                .onSubmit { Task { await saveGridItem(index) } }
                .onChange(of: gridItems[index].name) { _, _ in scheduleItemSave(index) }
                .disabled(!canEditGrid)
            TextField("0.00", value: $gridItems[index].amount, format: .number.precision(.fractionLength(2)))
                .keyboardType(.decimalPad)
                .onSubmit { Task { await saveGridItem(index) } }
                .onChange(of: gridItems[index].amount) { _, _ in scheduleItemSave(index) }
                .disabled(!canEditGrid)
        }
        .frame(minHeight: ItSize.targetPhone, alignment: .topLeading)
    }

    private func itemText(name: String?, amount: Double?) -> some View {
        VStack(alignment: .trailing, spacing: ItSpace.s1) {
            Text(amount.map { String(format: "$%.2f", $0) } ?? "-")
            if let name, !name.isEmpty { Text(name).fixedSize(horizontal: false, vertical: true) }
        }
    }

    private var categoryRow: some View {
        var options = viewModel.accountingCategories
        if let current = draft?.category, !current.isEmpty, !options.contains(current) { options.insert(current, at: 0) }
        return ReceiptCategoryCombo(text: binding(\.category, field: "accounting_category"), options: options,
                                    enabled: canEditGrid) { categoryFocusTick += 1 }
            .id("categoryRow")
    }

    private var addItemRow: some View {
        Button {
            gridItems.append(ReceiptGridItemDraft(readerName: nil, readerAmount: nil, name: "", amount: 0, storedID: nil))
        } label: {
            Label("Add item", systemImage: "plus.circle")
                .font(appStyle.font(.subheadline))
                .foregroundStyle(pennyBrand)
                .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone, alignment: .leading)
        }
        .buttonStyle(.plain)
        .disabled(!canEditGrid)
    }

    private func label(for key: String) -> String {
        ["vendor": "Vendor", "date": "Date", "subtotal": "Sub total", "gst": "GST", "pst": "PST", "total": "Total"][key] ?? key
    }

    private func textBinding(_ key: String) -> Binding<String> {
        key == "vendor" ? binding(\.vendor, field: "vendor") : binding(\.date, field: "date")
    }

    private func amountTextBinding(_ key: String) -> Binding<String> {
        let amount: Binding<Double>
        switch key {
        case "subtotal": amount = binding(\.subtotal, field: key)
        case "gst": amount = binding(\.gst, field: key)
        case "pst": amount = binding(\.pst, field: key)
        default: amount = binding(\.total, field: key)
        }
        return Binding(get: { String(format: "%.2f", amount.wrappedValue) },
                       set: { amount.wrappedValue = ReceiptNumber.parse($0) ?? 0 })
    }

    // MARK: Two square buttons

    /// Approve (filled) and Later (outlined), indigo, half the panel each with one gap between (Tim, 2026-11-17).
    /// Later closes the sheet and leaves the receipt a draft; anything already typed is kept as that draft.
    private var actionRow: some View {
        let radius = appStyle.cornerRadius(ItRadius.md)
        return VStack(spacing: ItSpace.s2) {
            HStack(spacing: SpineMetrics.slot) {
                Button {
                    Task {
                        saveGeneration += 1
                        for index in gridItems.indices where gridItems[index].dirty { itemSaveGenerations[index, default: 0] += 1; await saveGridItem(index) }
                        if !editedFields.isEmpty { await saveDraft() }
                        dismiss()
                    }
                } label: {
                    Text("Later")
                        .font(appStyle.font(.subheadline, weight: .semibold))
                        .foregroundStyle(pennyBrand)
                        .frame(maxWidth: .infinity, minHeight: ItSize.targetCrewAction)
                        .background(appStyle.page, in: RoundedRectangle(cornerRadius: radius))
                        .overlay(RoundedRectangle(cornerRadius: radius).stroke(pennyBrand, lineWidth: 1.5))
                }
                .buttonStyle(.plain)
                Button { Task { await approveGrid() } } label: {
                    Text(viewModel.isPerformingAction ? "Approving…" : "Approve")
                        .font(appStyle.font(.subheadline, weight: .semibold))
                        .foregroundStyle(appStyle.surface)
                        .frame(maxWidth: .infinity, minHeight: ItSize.targetCrewAction)
                        .background(pennyBrand, in: RoundedRectangle(cornerRadius: radius))
                }
                .buttonStyle(.plain)
                .disabled(!canActOn(expense) || viewModel.isPerformingAction)
            }
            Text("Later keeps this as a draft")
                .font(appStyle.font(.caption))
                .foregroundStyle(appStyle.meta)
        }
        .padding(.vertical, ItSpace.s3)
    }

    private func prepareDraft(read: PennyRead?) {
        guard draft == nil else { return }
        let base = ReceiptSaveRecord(
            vendor: expense.displayVendor,
            date: expense.expenseDate,
            category: expense.accountingCategory ?? "",
            subtotal: expense.amount ?? 0,
            gst: expense.gstAmount ?? 0,
            pst: expense.pstAmount ?? 0,
            total: expense.total,
            paymentMethod: expense.paymentMethod ?? "credit_card",
            description: expense.description ?? ""
        )
        let proposed = (read?.fields ?? []).reduce(into: [String: String]()) { values, field in
            if let value = field.penny { values[field.field] = value }
        }
        draft = ReceiptCompareRules.applying(proposed, to: base)
        editedFields = ReceiptCompareRules.pendingPennyFieldKeys(read?.fields ?? [])
        if let items = read?.items {
            gridItems = ReceiptCompareRules.items(items).map {
                ReceiptGridItemDraft(readerName: $0.currentName, readerAmount: $0.currentAmount,
                                     name: $0.proposedName ?? "", amount: $0.proposedAmount ?? 0,
                                     storedID: lineItems.indices.contains($0.index) ? lineItems[$0.index].id : nil,
                                     dirty: ReceiptCompareRules.pennyItemNeedsSave($0))
            }
        } else {
            gridItems = lineItems.map {
                ReceiptGridItemDraft(readerName: $0.name, readerAmount: $0.lineTotal,
                                     name: $0.name, amount: $0.lineTotal, storedID: $0.id)
            }
        }
    }

    #if DEBUG
    private func applyDebugMismatchSeed() {
        guard ProcessInfo.processInfo.arguments.contains("-mwReceiptSheetMismatch"), var value = draft else { return }
        value.total += 0.01
        draft = value
        gridItems.append(ReceiptGridItemDraft(readerName: nil, readerAmount: nil,
                                              name: "Added item", amount: 12.34, storedID: nil))
    }
    #endif

    private func readerValue(_ key: String) -> String? {
        if let value = pennyRead?.fields.first(where: { $0.field == key })?.receipt { return value }
        switch key {
        case "vendor": return expense.displayVendor
        case "date": return expense.expenseDate
        case "accounting_category": return expense.accountingCategory
        case "subtotal": return expense.amount.map { String(format: "%.2f", $0) }
        case "gst": return expense.gstAmount.map { String(format: "%.2f", $0) }
        case "pst": return expense.pstAmount.map { String(format: "%.2f", $0) }
        case "total": return String(format: "%.2f", expense.total)
        default: return nil
        }
    }

    private func pennyText(_ key: String) -> String? {
        guard let draft else { return nil }
        switch key {
        case "vendor": return draft.vendor
        case "date": return draft.date
        case "accounting_category": return draft.category
        case "subtotal": return String(format: "%.2f", draft.subtotal)
        case "gst": return String(format: "%.2f", draft.gst)
        case "pst": return String(format: "%.2f", draft.pst)
        case "total": return String(format: "%.2f", draft.total)
        default: return nil
        }
    }

    private func binding<Value>(_ keyPath: WritableKeyPath<ReceiptSaveRecord, Value>, field: String) -> Binding<Value> {
        Binding(
            get: { draft?[keyPath: keyPath] ?? expenseFallback(keyPath) },
            set: { newValue in
                guard canEditGrid, var value = draft else { return }
                value[keyPath: keyPath] = newValue
                draft = value
                editedFields.insert(field)
                pinnedPairs.insert(field)
                readerChoices.remove(field)
                recordWasEdited = true
                scheduleSave()
            }
        )
    }

    private func expenseFallback<Value>(_ keyPath: WritableKeyPath<ReceiptSaveRecord, Value>) -> Value {
        // `prepareDraft` runs before the grid is rendered; this branch only satisfies Binding's
        // non-optional contract during teardown.
        let record = ReceiptSaveRecord(vendor: expense.displayVendor, date: expense.expenseDate,
                                       category: expense.accountingCategory ?? "", subtotal: expense.amount ?? 0,
                                       gst: expense.gstAmount ?? 0, pst: expense.pstAmount ?? 0, total: expense.total,
                                       paymentMethod: expense.paymentMethod ?? "credit_card", description: expense.description ?? "")
        return record[keyPath: keyPath]
    }

    private func chooseReader(_ value: String, field: String) {
        guard canEditGrid, let draft else { return }
        self.draft = ReceiptCompareRules.choosingReader(value, in: draft, field: field)
        editedFields.insert(field)
        readerChoices.insert(field)
        recordWasEdited = true
        scheduleSave()
    }

    private func scheduleSave() {
        saveGeneration += 1
        let generation = saveGeneration
        Task {
            try? await Task.sleep(nanoseconds: 1_000_000_000)
            guard generation == saveGeneration else { return }
            await saveDraft()
        }
    }

    private func saveDraft() async {
        guard let record = draft, !editedFields.isEmpty, !alreadySent else { return }
        let saved = await viewModel.updateExpense(expenseId: expense.id, vendorName: record.vendor, date: record.date,
                                                  amount: record.subtotal, gst: record.gst, pst: record.pst, total: record.total,
                                                  category: record.category, paymentMethod: record.paymentMethod,
                                                  description: record.description)
        if saved {
            if draft == record {
                editedFields.removeAll()
            } else {
                scheduleSave()
            }
        } else if isAlreadySentError(viewModel.saveError) {
            alreadySent = true
        }
    }

    private func saveGridItem(_ index: Int) async {
        guard gridItems.indices.contains(index), canEditGrid else { return }
        let item = gridItems[index]
        var saved = false
        if item.name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, let id = item.storedID {
            saved = await viewModel.deleteLineItem(id: id)
            if saved, gridItems.indices.contains(index) { gridItems[index].storedID = nil }
        } else if let id = item.storedID {
            if let updated = await viewModel.updateLineItem(id: id, name: item.name, quantity: nil,
                                                            unitPrice: nil, lineTotal: item.amount) {
                if gridItems.indices.contains(index) { gridItems[index].storedID = updated.id }
                saved = true
            }
        } else if !item.name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty,
                  let added = await viewModel.addLineItem(expenseId: expense.id, name: item.name,
                                                          lineTotal: item.amount, productId: nil) {
            if gridItems.indices.contains(index) { gridItems[index].storedID = added.id }
            lineItems.append(added)
            saved = true
        }
        if saved, gridItems.indices.contains(index), gridItems[index].name == item.name,
           gridItems[index].amount == item.amount {
            gridItems[index].dirty = false
        }
        if isAlreadySentError(viewModel.actionError) { alreadySent = true }
    }

    private func scheduleItemSave(_ index: Int) {
        guard gridItems.indices.contains(index), canEditGrid else { return }
        gridItems[index].dirty = true
        itemWasEdited = true
        let generation = (itemSaveGenerations[index] ?? 0) + 1
        itemSaveGenerations[index] = generation
        Task {
            try? await Task.sleep(nanoseconds: 1_000_000_000)
            guard generation == itemSaveGenerations[index] else { return }
            await saveGridItem(index)
        }
    }

    private func approveGrid() async {
        saveGeneration += 1
        for index in gridItems.indices {
            itemSaveGenerations[index, default: 0] += 1
        }
        if !recordWasEdited, !itemWasEdited, let read = pennyRead, read.canApply {
            await decidePenny(useHers: true)
            return
        }
        for index in gridItems.indices where gridItems[index].dirty { await saveGridItem(index) }
        guard !alreadySent, !gridItems.contains(where: \.dirty) else { return }
        if !editedFields.isEmpty {
            await saveDraft()
            guard !alreadySent, editedFields.isEmpty else { return }
        }
        await performApprove()
    }

    private func rightColor(key: String, reader: String?, penny: String?) -> Color {
        valuesAgree(key: key, reader: reader, penny: penny) ? appStyle.text : appStyle.attention
    }

    private func valuesAgree(key: String, reader: String?, penny: String?) -> Bool {
        if ["subtotal", "gst", "pst", "total"].contains(key) {
            return ReceiptNumber.parse(reader ?? "") == ReceiptNumber.parse(penny ?? "")
        }
        return ReceiptCompareRules.chosenSide(reader: reader, penny: penny) == .reader
    }

    private func isAlreadySentError(_ message: String?) -> Bool {
        guard let message = message?.lowercased() else { return false }
        return message.contains("accounting") || message.contains("forwarded") || message.contains("already sent")
    }

    private func currencyText(_ value: String) -> String {
        ReceiptNumber.parse(value).map { String(format: "$%.2f", $0) } ?? value
    }

    private func refreshAll() async {
        await loadLineItems()
        pennyRead = await viewModel.loadPennyRead(expenseId: expense.id)
        if editedFields.isEmpty { draft = nil; prepareDraft(read: pennyRead) }
        if isAdmin, risk == nil { riskFailed = false; await loadRisk() }
    }

    private func comparisonCard(_ read: PennyRead) -> some View {
        let fieldRows = ReceiptCompareRules.fields(read.fields)
        let itemRows = read.items.map(ReceiptCompareRules.items) ?? []

        return VStack(spacing: 0) {
            comparisonHeadings
            ForEach(fieldRows) { comparisonField($0) }
            if let items = read.items { comparisonItems(itemRows, items: items) }
        }
    }

    private func decisionContent(_ read: PennyRead) -> some View {
        let fieldRows = ReceiptCompareRules.fields(read.fields)
        let itemRows = read.items.map(ReceiptCompareRules.items) ?? []
        let selection = ReceiptCompareRules.selection(differs: read.differs, fieldRows: fieldRows, itemRows: itemRows)
        return VStack(spacing: ItSpace.s3) {
            versionActions(read, selection: selection)
            if canActOn(expense) { approvalActions }
        }
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
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(spacing: ItSpace.s3) { versionButtons(read, selection: selection) }
            } else {
                HStack(spacing: ItSpace.s3) { versionButtons(read, selection: selection) }
            }
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
            if gridItems.isEmpty, pennyRead?.items == nil {
                gridItems = r.lineItems.map {
                    ReceiptGridItemDraft(readerName: $0.name, readerAmount: $0.lineTotal,
                                         name: $0.name, amount: $0.lineTotal, storedID: $0.id)
                }
            } else {
                for index in gridItems.indices where r.lineItems.indices.contains(index) && gridItems[index].storedID == nil {
                    gridItems[index].storedID = r.lineItems[index].id
                }
            }
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

    private var lineItemsContent: some View {
        VStack(alignment: .leading, spacing: ItSpace.s3) {
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
                    if isEditable {
                        Menu {
                            Button("Rename", systemImage: "pencil") {
                                renameText = item.name
                                renameItem = item
                            }
                            if !item.isAdjustment {
                                Button(item.productId == nil ? "Link product" : "Change product", systemImage: "link") {
                                    linkItem = item
                                }
                            }
                            if item.productId != nil {
                                Button("Unlink product", systemImage: "link.badge.minus") {
                                    Task {
                                        if let updated = await viewModel.linkLineItem(id: item.id, productId: nil) {
                                            replaceLineItem(updated)
                                        }
                                    }
                                }
                            }
                            Button("Not an item", systemImage: "xmark", role: .destructive) {
                                Task {
                                    if await viewModel.deleteLineItem(id: item.id) {
                                        lineItems.removeAll { $0.id == item.id }
                                    }
                                }
                            }
                        } label: {
                            Image(systemName: "ellipsis.circle")
                                .frame(width: ItSize.targetPhone, height: ItSize.targetPhone)
                        }
                        .accessibilityLabel("Actions for \(item.name)")
                    }
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
            if isEditable {
                Divider()
                Text("Tap to rename, or use the item actions to remove or unlink. Linking an item to a CRM product teaches the vendor catalog and SKU memory for next time.")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
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

    private var canEditGrid: Bool {
        isEditable && canActOn(expense) && !alreadySent && !viewModel.isPerformingAction
    }

    /// Draft/pending expenses can be approved or rejected from the phone. Sending to
    /// accounting is desktop-only, so approved/forwarded/rejected have no action here.
    private func canActOn(_ expense: Expense) -> Bool {
        ["draft", "pending_approval"].contains(expense.status)
    }

    // MARK: - Also on this purchase (merged duplicates, expense_merges)

    /// Copies merged into this receipt as duplicates. Split off brings one back as its own receipt
    /// (a wrong merge can be undone at any time — plan 2026-11-05).
    private var mergedContent: some View {
        VStack(alignment: .leading, spacing: ItSpace.s3) {
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
            Divider()
            Text("Copies merged into this receipt as duplicates. They're kept on record, not deleted.")
                .font(appStyle.font(.caption))
                .foregroundStyle(appStyle.meta)
        }
    }

    // MARK: - Sections

    private func pinnedReceipt(_ image: UIImage) -> some View {
        ReceiptZoomView(image: image)
            .accessibilityLabel("Receipt photo. Pinch to zoom, drag to move.")
            .frame(maxWidth: .infinity)
            .frame(height: UIScreen.main.bounds.height * (dynamicTypeSize.isAccessibilitySize ? 0.28 : 0.42))
            .background(appStyle.sunk)
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(ItRadius.sm)))   // the spine card is the only border
    }

    @ViewBuilder private var receiptNodeContent: some View {
        if let receiptImage { pinnedReceipt(receiptImage) }
    }

    private var receiptSpineSlug: String { "receipt-\(expense.id)" }

    private var statusLabel: String {
        switch expense.status {
        case "forwarded": "Sent"
        case "approved": "Approved"
        case "rejected": "Rejected"
        case "pending_approval": "Pending approval"
        default: "Draft"
        }
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

    @ViewBuilder private var detailsContent: some View {
        detailRow("Vendor", expense.displayVendor)
        detailRow("Date", expense.expenseDate)
        detailRow("Total", String(format: "$%.2f", expense.total))
        if let category = expense.accountingCategory, !category.isEmpty { detailRow("Category", category) }
        if let payment = expense.paymentMethod, !payment.isEmpty { detailRow("Payment", payment) }
        if let description = expense.description, !description.isEmpty { detailRow("Description", description) }
        if expense.isArchived {
            Divider()
            Label("Archived — original photo removed from the server; a thumbnail is shown here. The full-size image was emailed before removal.", systemImage: "archivebox")
                .font(appStyle.font(.caption))
                .foregroundStyle(appStyle.meta)
        }
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
    private var approvalActions: some View {
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(spacing: ItSpace.s3) { approvalButtons }
            } else {
                HStack(spacing: ItSpace.s3) { approvalButtons }
            }
        }
    }

    @ViewBuilder private var approvalButtons: some View {
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

    private func actionButton(title: String, systemImage: String, color: Color, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Label(title, systemImage: systemImage)
                .font(appStyle.font(.subheadline, weight: .semibold))
                .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone)
                .background(color)
                .foregroundStyle(appStyle.isEnabled && color == appStyle.tenant ? appStyle.onTenant : .white)
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(ItRadius.md)))
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

private struct ReceiptGridItemDraft: Equatable {
    let readerName: String?
    let readerAmount: Double?
    var name: String
    var amount: Double
    var storedID: Int?
    var readerChosen = false
    var dirty = false

    var differs: Bool {
        readerName?.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            != name.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            || readerAmount != amount
    }
}

/// Category as a typeable search (Tim, 2026-11-17): tap, type, pick. The saved category changes only when a row is picked
/// (or Return is pressed), so a half-typed word is never autosaved as the category. Penny's current value is the placeholder.
private struct ReceiptCategoryCombo: View {
    @Binding var text: String
    let options: [String]
    let enabled: Bool
    let onFocus: () -> Void

    @Environment(\.itAppStyle) private var appStyle
    @FocusState private var focused: Bool
    @State private var query = ""

    private var pennyBrand: Color { appStyle.palette.color(.brand) }

    private var matches: [String] {
        let q = query.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let list = q.isEmpty ? options : options.filter { $0.lowercased().contains(q) }
        return Array(list.prefix(8))
    }

    var body: some View {
        VStack(alignment: .leading, spacing: ItSpace.s1) {
            Text("Category").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            HStack(spacing: ItSpace.s2) {
                TextField(text.isEmpty ? "Search categories" : text, text: $query)
                    .focused($focused)
                    .submitLabel(.done)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                    .font(appStyle.font(.body, weight: .medium))
                    .foregroundStyle(appStyle.text)
                    .onSubmit { commitTyped() }
                    .accessibilityLabel("Penny category")
                Image(systemName: focused ? "chevron.up" : "chevron.down")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
                    .accessibilityHidden(true)
            }
            .padding(.horizontal, ItSpace.s3)
            .frame(minHeight: ItSize.targetPhone)
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(ItRadius.md))
                .stroke(focused ? pennyBrand : appStyle.line, lineWidth: focused ? 2 : 1))
            .disabled(!enabled)
            if focused {
                VStack(spacing: 0) {
                    ForEach(matches, id: \.self) { option in
                        Button { pick(option) } label: {
                            HStack {
                                Text(option).font(appStyle.font(.subheadline))
                                    .foregroundStyle(appStyle.text)
                                Spacer(minLength: 0)
                                if option == text {
                                    Image(systemName: "checkmark").foregroundStyle(pennyBrand)
                                }
                            }
                            .padding(.horizontal, ItSpace.s3)
                            .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone, alignment: .leading)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityAddTraits(option == text ? .isSelected : [])
                        Rectangle().fill(appStyle.line).frame(height: SpineMetrics.lineWidth)
                    }
                    let typed = query.trimmingCharacters(in: .whitespacesAndNewlines)
                    if !typed.isEmpty, !options.contains(where: { $0.caseInsensitiveCompare(typed) == .orderedSame }) {
                        Button { pick(typed) } label: {
                            Text("Use “\(typed)”")
                                .font(appStyle.font(.subheadline))
                                .foregroundStyle(pennyBrand)
                                .padding(.horizontal, ItSpace.s3)
                                .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone, alignment: .leading)
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
        .padding(.vertical, ItSpace.s2)
        .onAppear { query = text }
        .onChange(of: text) { _, new in if !focused { query = new } }
        .onChange(of: focused) { _, isFocused in
            if isFocused { query = ""; onFocus() } else { query = text }
        }
    }

    private func pick(_ value: String) {
        text = value
        query = value
        focused = false
    }

    private func commitTyped() {
        if let first = matches.first { pick(first) }
        else {
            let typed = query.trimmingCharacters(in: .whitespacesAndNewlines)
            if typed.isEmpty { focused = false } else { pick(typed) }
        }
    }
}
