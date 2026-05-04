//
//  JobsListView.swift
//  MowologyCRM
//

import SwiftUI

struct JobsListView: View {

    @StateObject private var viewModel: JobsListViewModel

    private let filters: [(label: String, value: String)] = [
        ("All",         "all"),
        ("Scheduled",   "scheduled"),
        ("In Progress", "in_progress"),
        ("Completed",   "completed"),
        ("Cancelled",   "cancelled"),
    ]

    init(authSession: AuthSession) {
        let client = APIClient(authSession: authSession)
        _viewModel = StateObject(wrappedValue: JobsListViewModel(apiClient: client))
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                filterBar
                Divider()
                content
            }
            .navigationTitle("Job History")
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
        if viewModel.isLoading && viewModel.visits.isEmpty {
            ProgressView("Loading…")
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if let err = viewModel.errorMessage, viewModel.visits.isEmpty {
            emptyState(icon: "exclamationmark.triangle", message: err)
        } else if viewModel.visits.isEmpty {
            emptyState(icon: "calendar.badge.clock", message: "No jobs found")
        } else {
            List {
                ForEach(viewModel.visits) { visit in
                    JobRow(visit: visit)
                        .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))
                }
                if viewModel.hasMore {
                    HStack { Spacer(); ProgressView(); Spacer() }
                        .listRowBackground(Color.clear)
                        .onAppear { Task { await viewModel.loadMore() } }
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

// MARK: - JobRow

private struct JobRow: View {
    let visit: JobItem

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(visit.planTitle ?? "Job Visit")
                        .font(.headline)
                        .lineLimit(1)
                    Text(addressLine)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
                Spacer()
                StatusBadge(label: visit.statusLabel, color: visit.statusColor)
            }

            HStack(spacing: 12) {
                if let date = visit.scheduledDate {
                    Label(formattedDate(date), systemImage: "calendar")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                if let price = visit.pricePerVisit {
                    Label(String(format: "$%.2f", price), systemImage: "dollarsign.circle")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .padding(.vertical, 2)
    }

    private var addressLine: String {
        let addr = visit.propertyAddress
        let city = visit.propertyCity
        if addr.isEmpty && city.isEmpty { return "No address" }
        if city.isEmpty { return addr }
        if addr.isEmpty { return city }
        return "\(addr), \(city)"
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

// MARK: - Shared sub-components

struct FilterChip: View {
    let label: String
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(label)
                .font(.subheadline.weight(isSelected ? .semibold : .regular))
                .padding(.horizontal, 14)
                .padding(.vertical, 7)
                .background(isSelected ? Color.MW.green : Color(.secondarySystemGroupedBackground))
                .foregroundStyle(isSelected ? .white : .primary)
                .clipShape(Capsule())
        }
        .buttonStyle(.plain)
    }
}

struct StatusBadge: View {
    let label: String
    let color: String

    var body: some View {
        Text(label)
            .font(.caption2.weight(.semibold))
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .background(resolvedColor.opacity(0.15))
            .foregroundStyle(resolvedColor)
            .clipShape(Capsule())
    }

    private var resolvedColor: Color {
        switch color {
        case "green":  return Color.MW.green
        case "orange": return .orange
        case "blue":   return .blue
        case "red":    return .red
        default:       return .gray
        }
    }
}

#Preview {
    JobsListView(authSession: AuthSession())
}
