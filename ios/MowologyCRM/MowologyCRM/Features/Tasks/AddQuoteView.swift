//
//  AddQuoteView.swift
//  MowologyCRM
//
//  Add → Quote (Tim, 2026-11-08): client, title, lines from the price list, total with GST. Works with no signal. It
//  always makes a draft: it gets its number from the server and waits in Sam's "Quotes to send", so nothing reaches a
//  customer without a tap while online.
//

import SwiftUI

private struct QuoteLine: Identifiable {
    let id = UUID()
    var productId: Int?
    var description: String
    var unit: String
    var quantity: String = "1"
    var price: String

    var quantityValue: Double? { QuoteMath.number(quantity) }
    var priceValue: Double? { QuoteMath.number(price) }
    var isValid: Bool {
        !description.trimmingCharacters(in: .whitespaces).isEmpty && (quantityValue ?? 0) > 0 && (priceValue ?? -1) >= 0
    }
}

struct AddQuoteView: View {
    let authSession: AuthSession

    @ObservedObject private var directory = OfflineDirectory.shared
    @ObservedObject private var outbox = CreateOutbox.shared
    @Environment(\.dismiss) private var dismiss
    @Environment(\.itAppStyle) private var appStyle

    @State private var client: DirectoryClient?
    @State private var title = ""
    @State private var lines: [QuoteLine] = []
    @State private var note = ""
    @State private var saving = false
    @State private var errorMessage: String?
    @State private var doneMessage: String?
    /// Made once per quote, so a second tap or a resend can't make a second one.
    @State private var requestId = UUID().uuidString

    private var canSave: Bool {
        client != nil && !title.trimmingCharacters(in: .whitespaces).isEmpty && !lines.isEmpty
            && lines.allSatisfy(\.isValid) && !saving
    }

    private var totals: (subtotal: Double, gst: Double, total: Double) {
        QuoteMath.totals(lines: lines.map { (quantity: $0.quantityValue ?? 0, price: $0.priceValue ?? 0) },
                         gstPercent: directory.gstPercent)
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("Client") {
                    NavigationLink {
                        ClientPicker(selection: $client)
                    } label: {
                        LabeledContent("Client") {
                            Text(client?.name ?? "Choose").foregroundStyle(client == nil ? appStyle.meta : appStyle.text)
                        }
                    }
                    if let client { Text(client.address).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta) }
                    if directory.clients.isEmpty {
                        Text("The client list hasn't reached this phone yet. Open this once with signal.")
                            .font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
                    }
                }
                Section {
                    TextField("Title, e.g. Fall cleanup", text: $title)
                }
                Section("Lines") {
                    ForEach($lines) { $line in
                        VStack(alignment: .leading, spacing: 8) {
                            TextField("Description", text: $line.description, axis: .vertical)
                            HStack(spacing: 12) {
                                TextField("Qty", text: $line.quantity)
                                    .keyboardType(.decimalPad)
                                    .frame(maxWidth: 70)
                                    .accessibilityLabel("Quantity")
                                if !line.unit.isEmpty { Text(line.unit).foregroundStyle(appStyle.meta) }
                                Text("×").foregroundStyle(appStyle.meta).accessibilityHidden(true)
                                TextField("Price", text: $line.price)
                                    .keyboardType(.decimalPad)
                                    .accessibilityLabel("Price")
                                Spacer(minLength: 0)
                                Text(Self.money(QuoteMath.lineTotal(quantity: line.quantityValue ?? 0, price: line.priceValue ?? 0)))
                                    .monospacedDigit()
                            }
                            .font(appStyle.font(.subheadline))
                        }
                        .padding(.vertical, 2)
                    }
                    .onDelete { lines.remove(atOffsets: $0) }
                    NavigationLink {
                        ProductPicker { p in
                            lines.append(QuoteLine(productId: p.id, description: p.name, unit: p.unit, price: Self.plain(p.price)))
                        }
                    } label: {
                        Label("Add from price list", systemImage: "list.bullet").foregroundStyle(appStyle.tenant)
                    }
                    Button {
                        lines.append(QuoteLine(productId: nil, description: "", unit: "", price: ""))
                    } label: {
                        Label("Add a custom line", systemImage: "plus").foregroundStyle(appStyle.tenant)
                    }
                }
                if !lines.isEmpty {
                    Section {
                        LabeledContent("Subtotal", value: Self.money(totals.subtotal))
                        if directory.gstPercent > 0 {
                            LabeledContent("GST \(Self.plain(directory.gstPercent))%", value: Self.money(totals.gst))
                        }
                        LabeledContent("Total") {
                            Text(Self.money(totals.total)).fontWeight(.semibold)
                        }
                    }
                    .monospacedDigit()
                }
                Section("Note for the customer (optional)") {
                    TextField("Note", text: $note, axis: .vertical)
                }
            }
            .itAppForm()
            .itAppScreen()
            .navigationTitle("Add a quote")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button(saving ? "Saving…" : "Save draft") { Task { await save() } }.disabled(!canSave)
                }
            }
            .task { await directory.load(using: APIClient(authSession: authSession), prices: true) }
            .alert("Couldn't save", isPresented: Binding(get: { errorMessage != nil }, set: { if !$0 { errorMessage = nil } })) {
                Button("OK", role: .cancel) {}
            } message: { Text(errorMessage ?? "") }
            .alert("Draft saved", isPresented: Binding(get: { doneMessage != nil }, set: { if !$0 { doneMessage = nil } })) {
                Button("OK") { dismiss() }
            } message: { Text(doneMessage ?? "") }
        }
    }

    private func save() async {
        guard let client else { return }
        saving = true
        defer { saving = false }
        let label = title.trimmingCharacters(in: .whitespaces)
        let body: [String: Any] = [
            "property_id": client.propertyId,   // the server's recipient rules pick who it goes to
            "title": label,
            "notes": note.trimmingCharacters(in: .whitespaces),
            "client_request_id": requestId,
            "line_items": lines.map { line -> [String: Any] in
                var item: [String: Any] = ["description": line.description.trimmingCharacters(in: .whitespaces),
                                           "quantity": line.quantityValue ?? 0, "unit_price": line.priceValue ?? 0]
                if let id = line.productId { item["product_id"] = id }
                if !line.unit.isEmpty { item["unit_type"] = line.unit }
                return item
            },
        ]
        let api = APIClient(authSession: authSession)
        switch await outbox.submit(kind: .quote, title: "Quote: \(label) for \(client.name)", body: body, using: api) {
        case .sent:
            doneMessage = "It's in Sam's Quotes to send. Nothing has gone to the customer."
        case .waiting:
            doneMessage = "No signal right now, so it's saved on this phone. It gets its number and appears in Sam's Quotes to send when you're back online."
        case .refused(let reason):
            requestId = UUID().uuidString
            errorMessage = reason
        case .notKept:
            errorMessage = "It couldn't be kept on this phone. Nothing was saved."
        }
    }

    private static func money(_ x: Double) -> String {
        x.formatted(.currency(code: "CAD"))
    }

    /// 40 → "40", 40.5 → "40.50".
    private static func plain(_ x: Double) -> String {
        x.rounded() == x ? String(Int(x)) : String(format: "%.2f", x)
    }
}

private struct ProductPicker: View {
    let onPick: (QuoteProduct) -> Void
    @ObservedObject private var directory = OfflineDirectory.shared
    @Environment(\.dismiss) private var dismiss
    @Environment(\.itAppStyle) private var appStyle
    @State private var query = ""

    private var shown: [QuoteProduct] {
        let q = query.trimmingCharacters(in: .whitespaces)
        return q.isEmpty ? directory.products : directory.products.filter { $0.name.localizedCaseInsensitiveContains(q) }
    }

    var body: some View {
        List {
            if directory.products.isEmpty {
                Text("The price list hasn't reached this phone yet. Open this once with signal, or add a custom line.")
                    .font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
            }
            ForEach(shown) { p in
                Button {
                    onPick(p)
                    dismiss()
                } label: {
                    HStack {
                        Text(p.name).foregroundStyle(appStyle.text)
                        Spacer(minLength: 8)
                        Text(p.price.formatted(.currency(code: "CAD")) + (p.unit.isEmpty ? "" : " / \(p.unit)"))
                            .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    }
                }
            }
        }
        .searchable(text: $query, placement: .navigationBarDrawer(displayMode: .always), prompt: "Search the price list")
        .navigationTitle("Price list")
        .navigationBarTitleDisplayMode(.inline)
    }
}
