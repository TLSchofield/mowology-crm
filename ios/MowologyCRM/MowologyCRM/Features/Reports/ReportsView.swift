//
//  ReportsView.swift
//  MowologyCRM
//
//  Admin-only reports screen. Accessible from the Account tab.
//  Displays a picker of report types and renders the appropriate Swift Chart.
//

import SwiftUI

struct ReportsView: View {

    @StateObject private var viewModel: ReportsViewModel

    init(authSession: AuthSession) {
        _viewModel = StateObject(wrappedValue: ReportsViewModel(authSession: authSession))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                // Report type picker
                reportPicker

                // Date range (not shown for overdue-invoices — it ignores dates)
                if viewModel.selectedReport != .overdueInvoices {
                    dateRangeSection
                }

                // Chart or list
                if viewModel.isLoading {
                    loadingView
                } else if let data = viewModel.reportData, data.success {
                    chartSection(data: data)
                } else if let err = viewModel.errorMessage {
                    errorView(err)
                }
            }
            .padding()
        }
        .navigationTitle("Reports")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Button {
                    Task { await viewModel.load() }
                } label: {
                    Image(systemName: "arrow.clockwise")
                }
                .disabled(viewModel.isLoading)
            }
        }
        .task { await viewModel.load() }
        .onChange(of: viewModel.selectedReport) { _, _ in
            Task { await viewModel.load() }
        }
    }

    // MARK: - Report Picker

    private var reportPicker: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 10) {
                ForEach(ReportType.allCases) { type in
                    Button {
                        viewModel.selectedReport = type
                    } label: {
                        Label(type.displayName, systemImage: type.systemImage)
                            .font(.subheadline.weight(.medium))
                            .padding(.horizontal, 14)
                            .padding(.vertical, 8)
                            .background(
                                viewModel.selectedReport == type
                                    ? Color.MW.green
                                    : Color(.secondarySystemGroupedBackground)
                            )
                            .foregroundStyle(
                                viewModel.selectedReport == type ? .white : Color.primary
                            )
                            .clipShape(Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    // MARK: - Date Range

    private var dateRangeSection: some View {
        HStack(spacing: 16) {
            VStack(alignment: .leading, spacing: 2) {
                Text("From")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                DatePicker("", selection: $viewModel.startDate, displayedComponents: .date)
                    .labelsHidden()
            }
            VStack(alignment: .leading, spacing: 2) {
                Text("To")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                DatePicker("", selection: $viewModel.endDate, displayedComponents: .date)
                    .labelsHidden()
            }
            Spacer()
            Button("Load") {
                Task { await viewModel.load() }
            }
            .buttonStyle(.borderedProminent)
            .tint(Color.MW.green)
            .disabled(viewModel.isLoading)
        }
        .padding()
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Chart Section

    private func chartSection(data: ReportResponse) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(viewModel.selectedReport.displayName)
                .font(.headline)

            ReportChartView(type: viewModel.selectedReport, data: data)
                .padding()
                .background(Color(.secondarySystemGroupedBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }

    // MARK: - Loading / Error

    private var loadingView: some View {
        HStack {
            Spacer()
            VStack(spacing: 12) {
                ProgressView()
                Text("Loading \(viewModel.selectedReport.displayName)…")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            Spacer()
        }
        .padding(.vertical, 40)
    }

    private func errorView(_ message: String) -> some View {
        VStack(spacing: 12) {
            Image(systemName: "exclamationmark.triangle")
                .font(.largeTitle)
                .foregroundStyle(.orange)
            Text(message)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Button("Retry") {
                Task { await viewModel.load() }
            }
            .buttonStyle(.bordered)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 40)
    }
}
