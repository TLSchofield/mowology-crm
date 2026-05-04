//
//  QuotesView.swift
//  MowologyCRM
//

import SwiftUI

struct QuotesView: View {

    @StateObject private var viewModel: QuotesViewModel

    private let filters: [(label: String, value: String)] = [
        ("All",            "all"),
        ("Pending Review", "pending_review"),
        ("Sent",           "sent"),
        ("Approved",       "approved"),
        ("Rejected",       "rejected"),
    ]

    init(authSession: AuthSession) {
        let client = APIClient(authSession: authSession)
        _viewModel = StateObject(wrappedValue: QuotesViewModel(apiClient: client))
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                filterBar
                Divider()
                content
            }
            .navigationTitle("Quotes")
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
        if viewModel.isLoading && viewModel.quotes.isEmpty {
            ProgressView("Loading…")
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if let err = viewModel.errorMessage, viewModel.quotes.isEmpty {
            emptyState(icon: "exclamationmark.triangle", message: err)
        } else if viewModel.quotes.isEmpty {
            emptyState(icon: "doc.text", message: "No quotes found")
        } else {
            List {
                ForEach(viewModel.quotes) { quote in
                    QuoteRow(quote: quote)
                        .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))
                }
                if viewModel.hasMore {
                    HStack { Spacer(); ProgressView(); Spacer() }
                        .listRowBackground(Color.clear)
                        .onAppear { Task { await viewModel.load(reset: false) } }
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

// MARK: - QuoteRow

private struct QuoteRow: View {
    let quote: QuoteItem

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(quote.quoteNumber ?? "Quote")
                        .font(.headline.monospacedDigit())
                    Text(quote.clientName)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                Spacer()
                StatusBadge(label: quote.statusLabel, color: quote.statusColor)
            }

            HStack(spacing: 12) {
                if let amount = quote.totalAmount {
                    Label(String(format: "$%.2f", amount), systemImage: "dollarsign.circle")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                if let created = quote.createdAt {
                    Label(formattedDate(created), systemImage: "calendar")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                if let valid = quote.validUntil {
                    Label("Valid to \(formattedDate(valid))", systemImage: "clock")
                        .font(.caption)
                        .foregroundStyle(.secondary)
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
    QuotesView(authSession: AuthSession())
}
