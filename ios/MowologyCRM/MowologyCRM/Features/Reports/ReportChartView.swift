//
//  ReportChartView.swift
//  MowologyCRM
//
//  Swift Charts renderers for each report type.
//  Requires iOS 16+.
//

import SwiftUI
import Charts

// MARK: - Router

struct ReportChartView: View {
    let type: ReportType
    let data: ReportResponse

    var body: some View {
        switch type {
        case .revenueByMonth:   RevenueByMonthChart(data: data)
        case .revenueByService: RevenueByServiceChart(data: data)
        case .quoteFunnel:      QuoteFunnelChart(data: data)
        case .crewProfit:       CrewProfitChart(data: data)
        case .overdueInvoices:  OverdueInvoiceList(data: data)
        }
    }
}

// MARK: - Revenue by Month (grouped bar)

private struct RevenueByMonthChart: View {
    let data: ReportResponse

    private struct BarPoint: Identifiable {
        let id = UUID()
        let month: String
        let series: String
        let value: Double
    }

    private var points: [BarPoint] {
        var out: [BarPoint] = []
        for series in data.series {
            for (i, label) in data.labels.enumerated() {
                let val = i < series.data.count ? series.data[i] : 0
                out.append(BarPoint(month: label, series: series.label, value: val))
            }
        }
        return out
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            summaryRow

            Chart(points) { p in
                BarMark(
                    x: .value("Month", p.month),
                    y: .value("Amount", p.value)
                )
                .foregroundStyle(by: .value("Series", p.series))
            }
            .chartForegroundStyleScale([
                "Invoiced":  Color.MW.green,
                "Collected": Color.MW.lime,
            ])
            .frame(height: 220)
            .chartYAxis {
                AxisMarks(format: .currency(code: "CAD").precision(.fractionLength(0)))
            }
        }
    }

    private var summaryRow: some View {
        HStack(spacing: 20) {
            summaryBadge(label: "Invoiced",     value: data.summary?["total_invoiced"]    ?? 0)
            summaryBadge(label: "Collected",    value: data.summary?["total_collected"]   ?? 0)
            summaryBadge(label: "Outstanding",  value: data.summary?["total_outstanding"] ?? 0, accent: .orange)
        }
    }
}

// MARK: - Revenue by Service (donut)

private struct RevenueByServiceChart: View {
    let data: ReportResponse

    private struct Slice: Identifiable {
        let id = UUID()
        let label: String
        let value: Double
    }

    private var slices: [Slice] {
        guard let series = data.series.first else { return [] }
        return zip(data.labels, series.data).map { Slice(label: $0, value: $1) }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Chart(slices) { s in
                SectorMark(
                    angle: .value("Revenue", s.value),
                    innerRadius: .ratio(0.55),
                    angularInset: 2
                )
                .foregroundStyle(by: .value("Service", s.label))
                .cornerRadius(4)
            }
            .frame(height: 240)

            LegendGrid(labels: data.labels)
        }
    }
}

// MARK: - Quote Funnel (horizontal bar)

private struct QuoteFunnelChart: View {
    let data: ReportResponse

    private struct FunnelBar: Identifiable {
        let id = UUID()
        let stage: String
        let count: Double
    }

    private var bars: [FunnelBar] {
        guard let series = data.series.first else { return [] }
        return zip(data.labels, series.data).map { FunnelBar(stage: $0, count: $1) }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            if let conv = data.summary?["conversion_rate"] {
                Text(String(format: "Conversion rate: %.1f%%", conv))
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            Chart(bars) { b in
                BarMark(
                    x: .value("Count", b.count),
                    y: .value("Stage", b.stage)
                )
                .foregroundStyle(Color.MW.green)
                .annotation(position: .trailing) {
                    Text("\(Int(b.count))")
                        .font(.caption.monospacedDigit())
                        .foregroundStyle(.secondary)
                }
            }
            .frame(height: 200)
            .chartXAxis(.hidden)
        }
    }
}

// MARK: - Crew Profitability (grouped bar)

private struct CrewProfitChart: View {
    let data: ReportResponse

    private struct Bar: Identifiable {
        let id = UUID()
        let crew: String
        let series: String
        let value: Double
    }

    private var bars: [Bar] {
        var out: [Bar] = []
        for series in data.series {
            for (i, label) in data.labels.enumerated() {
                let val = i < series.data.count ? series.data[i] : 0
                out.append(Bar(crew: label, series: series.label, value: val))
            }
        }
        return out
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Chart(bars) { b in
                BarMark(
                    x: .value("Crew", b.crew),
                    y: .value("Amount", b.value)
                )
                .foregroundStyle(by: .value("Series", b.series))
            }
            .chartForegroundStyleScale([
                "Revenue": Color.MW.green,
                "Margin":  Color.MW.lime,
            ])
            .frame(height: 220)
            .chartYAxis {
                AxisMarks(format: .currency(code: "CAD").precision(.fractionLength(0)))
            }
        }
    }
}

// MARK: - Overdue Invoices (list)

private struct OverdueInvoiceList: View {
    let data: ReportResponse

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            if let summary = data.summary {
                HStack(spacing: 20) {
                    summaryBadge(
                        label: "Total Overdue",
                        value: summary["total_overdue"] ?? 0,
                        accent: .red
                    )
                    VStack(alignment: .leading) {
                        Text("\(Int(summary["count"] ?? 0))")
                            .font(.title2.bold())
                        Text("invoices")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }

            if let rows = data.rows, !rows.isEmpty {
                ForEach(rows) { row in
                    OverdueRow(row: row)
                }
            } else {
                Text("No overdue invoices.")
                    .foregroundStyle(.secondary)
                    .padding(.top)
            }
        }
    }
}

private struct OverdueRow: View {
    let row: OverdueInvoiceRow

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(row.clientName)
                    .font(.subheadline.weight(.semibold))
                Spacer()
                Text("$\(row.balanceDue, specifier: "%.2f")")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.red)
            }
            HStack {
                Text(row.invoiceNumber)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Spacer()
                Text("\(row.daysOverdue)d overdue")
                    .font(.caption)
                    .foregroundStyle(.orange)
            }
        }
        .padding(.vertical, 8)
        Divider()
    }
}

// MARK: - Shared Helpers

private func summaryBadge(label: String, value: Double, accent: Color = Color.MW.green) -> some View {
    VStack(alignment: .leading, spacing: 2) {
        Text(value, format: .currency(code: "CAD").precision(.fractionLength(0)))
            .font(.title3.bold())
            .foregroundStyle(accent)
        Text(label)
            .font(.caption)
            .foregroundStyle(.secondary)
    }
}

private struct LegendGrid: View {
    let labels: [String]
    private let colours: [Color] = [
        .MW.green, .MW.lime, .orange, .blue, .purple,
        .pink, .teal, .yellow, .indigo, .red,
    ]

    var body: some View {
        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 6) {
            ForEach(Array(labels.enumerated()), id: \.offset) { idx, label in
                HStack(spacing: 6) {
                    Circle()
                        .fill(colours[idx % colours.count])
                        .frame(width: 10, height: 10)
                    Text(label)
                        .font(.caption)
                        .lineLimit(1)
                    Spacer()
                }
            }
        }
    }
}

