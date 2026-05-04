//
//  InvoicesView.swift
//  MowologyCRM
//

import SwiftUI

struct InvoicesView: View {

    @StateObject private var viewModel: InvoicesViewModel

    private let filters: [(label: String, value: String)] = [
        ("All",       "all"),
        ("Draft",     "draft"),
        ("Sent",      "sent"),
        ("Paid",      "paid"),
        ("Overdue",   "overdue"),
    ]

    init(authSession: AuthSession) {
        let client = APIClient(authSession: authSession)
        _viewModel = StateObject(wrappedValue: InvoicesViewModel(apiClient: client))
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                filterBar
                Divider()
                content
            }
            .navigationTitle("Invoices")
            .navigationBarTitleDisplayMode(.inline)
        }
        .task { await viewModel.load() }
    }

    // MARK: - Filter bar

    private var filterBar: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(filters, id: \.value) { f in
                    FilterChip(
                        label: f.label,
                        isSelected: viewModel.statusFilter == f.value
                    ) {
                        Task { await viewModel.applyFilter(f.value) }
                    }
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 10)
        }
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Content

    @ViewBuilder
    private var content: some View {
        if viewModel.isLoading && viewModel.invoices.isEmpty {
            ProgressView("Loading…")
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if let err = viewModel.errorMessage, viewModel.invoices.isEmpty {
            emptyState(icon: "exclamationmark.triangle", message: err)
        } else if viewModel.invoices.isEmpty {
            emptyState(icon: "doc.plaintext", message: "No invoices found")
        } else {
            List {
                if viewModel.statusFilter == "all" && viewModel.totalOutstanding > 0 {
                    Section {
                        HStack {
                            Text("Outstanding")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                            Spacer()
                            Text(String(format: "$%.2f", viewModel.totalOutstanding))
                                .font(.headline)
                                .foregroundStyle(Color.MW.green)
                        }
                    }
                }

                Section {
                    ForEach(viewModel.invoices) { invoice in
                        InvoiceRow(invoice: invoice)
                            .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))
                    }
                    if viewModel.hasMore {
                        HStack { Spacer(); ProgressView(); Spacer() }
                            .listRowBackground(Color.clear)
                            .onAppear { Task { await viewModel.load(reset: false) } }
                    }
                }
            }
            .listStyle(.insetGrouped)
            .refreshable { await viewModel.load(reset: true) }
        }
    }

    private func emptyState(icon: String, message: String) -> some View {
        VStack(spacing: 16) {
            Image(systemName: icon)
                .font(.system(size: 44))
                .foregroundStyle(Color(.systemGray3))
            Text(message)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

// MARK: - InvoiceRow

private struct InvoiceRow: View {
    let invoice: InvoiceItem

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(invoice.invoiceNumber ?? "Invoice")
                        .font(.headline.monospacedDigit())
                    Text(invoice.clientName)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 4) {
                    StatusBadge(label: invoice.statusLabel, color: invoice.statusColor)
                    if invoice.isOverdue && invoice.status != "overdue" {
                        StatusBadge(label: "Overdue", color: "red")
                    }
                }
            }

            HStack(spacing: 12) {
                Label(String(format: "$%.2f", invoice.totalAmount), systemImage: "dollarsign.circle")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                if invoice.balanceOwing > 0 && invoice.status != "paid" {
                    Label(String(format: "Owing $%.2f", invoice.balanceOwing), systemImage: "exclamationmark.circle")
                        .font(.caption)
                        .foregroundStyle(invoice.isOverdue ? .red : .orange)
                }

                if let due = invoice.dueDate {
                    Label("Due \(formattedDate(due))", systemImage: "calendar")
                        .font(.caption)
                        .foregroundStyle(invoice.isOverdue ? .red : .secondary)
                }
            }
        }
        .padding(.vertical, 2)
    }

    private func formattedDate(_ raw: String) -> String {
        let src = DateFormatter()
        src.dateFormat = "yyyy-MM-dd"
        let dst = DateFormatter()
        dst.dateStyle = .medium
        dst.timeStyle = .none
        guard let d = src.date(from: raw) else { return raw }
        return dst.string(from: d)
    }
}

#Preview {
    InvoicesView(authSession: AuthSession())
}
