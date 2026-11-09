//
//  AddInvoiceView.swift
//  MowologyCRM
//
//  Add → Invoice (Tim, 2026-11-08): finished visits waiting on an invoice, from the server's own definition (contracts
//  and pre-sold work never appear). A tap makes a draft, with signal or without (CreateOutbox). Sending is a separate tap
//  and needs signal: the email goes through InvoiceFromVisitService::send with its PDF, as everywhere else.
//

import SwiftUI

struct AddInvoiceView: View {
    let authSession: AuthSession

    @ObservedObject private var directory = OfflineDirectory.shared
    @ObservedObject private var outbox = CreateOutbox.shared
    @Environment(\.dismiss) private var dismiss
    @Environment(\.itAppStyle) private var appStyle

    @State private var confirmVisit: UnbilledVisit?
    @State private var confirmSend: DraftInvoice?
    @State private var busy = false
    @State private var errorMessage: String?
    @State private var note: String?
    /// One id per visit: a second tap or a resend can't make a second invoice.
    @State private var requestIds: [Int: String] = [:]

    private var waiting: [UnbilledVisit] {
        let queued = outbox.queuedInvoiceVisitIds(me: authSession.user?.id)
        return directory.unbilled.filter { !queued.contains($0.visitId) }
    }

    var body: some View {
        NavigationStack {
            List {
                if let note {
                    Section { Text(note).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.tenant) }
                }
                if !directory.drafts.isEmpty {
                    Section("Drafts ready to send") {
                        ForEach(directory.drafts) { d in
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(d.client.isEmpty ? d.number : d.client)
                                        .font(appStyle.font(.subheadline, weight: .semibold))
                                    Text("\(d.number) · \(Self.money(d.total))")
                                        .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                                }
                                Spacer(minLength: 8)
                                Button("Send") { confirmSend = d }
                                    .buttonStyle(.borderedProminent)
                                    .tint(appStyle.tenant)
                                    .disabled(busy)
                            }
                        }
                    }
                }
                Section("Finished, not invoiced") {
                    if waiting.isEmpty {
                        Text(directory.savedAt == nil
                             ? "This list hasn't reached the phone yet. Open this once with signal."
                             : "Nothing is waiting on an invoice.")
                            .font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                    }
                    ForEach(waiting) { v in
                        Button { confirmVisit = v } label: {
                            HStack(alignment: .top) {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(v.client.isEmpty ? v.title : v.client)
                                        .font(appStyle.font(.subheadline, weight: .semibold))
                                        .foregroundStyle(appStyle.text)
                                    Text("\(v.title) · \(v.date)")
                                        .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                                    if !v.address.isEmpty {
                                        Text(v.address).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                                    }
                                }
                                Spacer(minLength: 8)
                                Text(Self.money(v.amount)).monospacedDigit().foregroundStyle(appStyle.text)
                            }
                        }
                        .disabled(busy)
                    }
                }
            }
            .navigationTitle("Add an invoice")
            .navigationBarTitleDisplayMode(.inline)
            .itAppScreen()
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Close") { dismiss() } } }
            .task { await reload() }
            .refreshable { await reload() }
            .confirmationDialog("Make a draft invoice?", isPresented: Binding(get: { confirmVisit != nil }, set: { if !$0 { confirmVisit = nil } }),
                                titleVisibility: .visible, presenting: confirmVisit) { v in
                Button("Make draft for \(v.client.isEmpty ? v.title : v.client)") { Task { await makeDraft(v) } }
                Button("Cancel", role: .cancel) {}
            } message: { v in
                Text("\(v.title), \(v.date), about \(Self.money(v.amount)) before tax. Nothing goes to the customer yet.")
            }
            .confirmationDialog("Send this invoice?", isPresented: Binding(get: { confirmSend != nil }, set: { if !$0 { confirmSend = nil } }),
                                titleVisibility: .visible, presenting: confirmSend) { d in
                Button("Email \(d.number) to \(d.client.isEmpty ? "the client" : d.client)") { Task { await send(d) } }
                Button("Cancel", role: .cancel) {}
            } message: { d in
                Text("\(Self.money(d.total)) with its PDF.")
            }
            .alert("Couldn't do that", isPresented: Binding(get: { errorMessage != nil }, set: { if !$0 { errorMessage = nil } })) {
                Button("OK", role: .cancel) {}
            } message: { Text(errorMessage ?? "") }
        }
    }

    private func reload() async {
        await directory.load(using: APIClient(authSession: authSession), invoices: true)
    }

    private func makeDraft(_ v: UnbilledVisit) async {
        busy = true
        defer { busy = false }
        let id = requestIds[v.visitId] ?? UUID().uuidString
        requestIds[v.visitId] = id
        let body: [String: Any] = ["action": "create", "visit_id": v.visitId, "client_request_id": id]
        let api = APIClient(authSession: authSession)
        switch await outbox.submit(kind: .invoice, title: "Invoice: \(v.client.isEmpty ? v.title : v.client), \(v.date)", body: body, using: api) {
        case .sent:
            note = "Draft made for \(v.client.isEmpty ? v.title : v.client). Send it from the list above."
            await reload()
        case .waiting:
            note = "No signal, so the draft is saved on this phone. It appears here when you're back online."
        case .refused(let reason):
            requestIds[v.visitId] = nil
            errorMessage = reason
        case .notKept:
            errorMessage = "It couldn't be kept on this phone. Nothing was saved."
        }
    }

    private func send(_ d: DraftInvoice) async {
        busy = true
        defer { busy = false }
        do {
            let r: InvoiceActionResponse = try await APIClient(authSession: authSession)
                .request(.invoicesMobileAction, body: ["action": "send", "invoice_id": d.invoiceId])
            if r.success {
                note = "\(d.number) sent to \(d.client.isEmpty ? "the client" : d.client)."
                await reload()
            } else {
                errorMessage = r.error ?? "Invoice send response success=false; server returned no error field."
            }
        } catch APIError.networkError {
            errorMessage = "Sending needs signal. The draft is safe; send it when you're back online."
        } catch {
            errorMessage = ErrorDetails.describe(error)
        }
    }

    private static func money(_ x: Double) -> String {
        x.formatted(.currency(code: "CAD"))
    }
}
