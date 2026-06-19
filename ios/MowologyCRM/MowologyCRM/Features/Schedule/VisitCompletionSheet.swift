//
//  VisitCompletionSheet.swift
//  MowologyCRM
//
//  Shown when crew tap "Mark Complete" on an in-progress visit. Lets them log
//  timed extra work (live timer + quick-add blocks + a note to the client) and
//  either complete the visit only, or complete it and email an invoice.
//
//  Mirrors the web "Add-On Services" modal (visit-work.php) used by the Android
//  crew app, which is a WebView over the same PHP backend.
//

import SwiftUI

struct VisitCompletionSheet: View {

    let visit: Visit
    @ObservedObject var detailVM: VisitDetailViewModel
    @StateObject private var vm: VisitCompletionViewModel
    @Environment(\.dismiss) private var dismiss

    init(visit: Visit, detailVM: VisitDetailViewModel, authSession: AuthSession) {
        self.visit = visit
        self.detailVM = detailVM
        _vm = StateObject(wrappedValue: VisitCompletionViewModel(authSession: authSession))
    }

    var body: some View {
        NavigationStack {
            Group {
                switch vm.phase {
                case .input:   inputForm
                case .working: workingView
                case .success: resultView(success: true)
                case .failed:  resultView(success: false)
                }
            }
            .navigationTitle("Complete Visit")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if vm.phase == .input {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Cancel") { dismiss() }
                    }
                }
            }
        }
        .interactiveDismissDisabled(vm.phase == .working)
    }

    // MARK: - Input form

    private var inputForm: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                extrasSection
                noteSection
                actionButtons
            }
            .padding(16)
        }
        .background(Color(.systemGroupedBackground))
    }

    private var extrasSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            sectionHeader("Timed Extra Work", icon: "timer")

            VStack(spacing: 16) {
                // Live timer + start/stop
                HStack {
                    Text(vm.timerDisplay)
                        .font(.system(size: 34, weight: .bold, design: .rounded).monospacedDigit())
                        .foregroundStyle(.primary)
                    Spacer()
                    Button {
                        vm.toggleTimer()
                    } label: {
                        Label(vm.isTimerRunning ? "Stop" : "Start",
                              systemImage: vm.isTimerRunning ? "stop.fill" : "play.fill")
                            .font(.subheadline.bold())
                            .padding(.horizontal, 16)
                            .padding(.vertical, 9)
                            .background(vm.isTimerRunning ? Color.MW.orange : Color.MW.green)
                            .foregroundStyle(.white)
                            .clipShape(Capsule())
                    }
                }

                // Quick-add blocks
                HStack(spacing: 8) {
                    ForEach([5, 10, 15, 30], id: \.self) { mins in
                        Button {
                            vm.addMinutes(mins)
                        } label: {
                            Text("+\(mins)")
                                .font(.subheadline.bold())
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, 10)
                                .background(Color.MW.green.opacity(0.12))
                                .foregroundStyle(Color.MW.green)
                                .clipShape(RoundedRectangle(cornerRadius: 8))
                        }
                    }
                }

                Divider()

                // Running total + live dollar preview
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("\(vm.totalMinutes) min")
                            .font(.headline)
                        if vm.hasExtras {
                            Text("\(vm.billableBlocks) × 5-min block\(vm.billableBlocks == 1 ? "" : "s")")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                    Spacer()
                    Text(currency(vm.extrasAmount))
                        .font(.title3.bold())
                        .foregroundStyle(vm.hasExtras ? Color.MW.green : .secondary)
                }

                if vm.hasExtras {
                    Button("Reset extras", role: .destructive) { vm.resetExtras() }
                        .font(.caption)
                }
            }
            .padding(16)
            .background(Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }

    private var noteSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            sectionHeader("Note to Client (optional)", icon: "text.bubble")
            TextField("e.g. Trimmed hedge by gate as requested",
                      text: $vm.note, axis: .vertical)
                .lineLimit(2...4)
                .padding(12)
                .background(Color(.systemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 10))
        }
    }

    private var actionButtons: some View {
        VStack(spacing: 10) {
            Button {
                complete(withInvoice: true)
            } label: {
                Label("Complete & Invoice", systemImage: "paperplane.fill")
                    .font(.subheadline.bold())
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 14)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(Capsule())
            }

            Button {
                complete(withInvoice: false)
            } label: {
                Text(vm.hasExtras ? "Complete — Save Extras, No Invoice" : "Complete — No Invoice")
                    .font(.subheadline.bold())
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 14)
                    .background(Color.MW.green.opacity(0.12))
                    .foregroundStyle(Color.MW.green)
                    .clipShape(Capsule())
            }
        }
        .padding(.top, 4)
    }

    // MARK: - Working

    private var workingView: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text("Completing visit…")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Result

    private func resultView(success: Bool) -> some View {
        ScrollView {
            VStack(spacing: 16) {
                Image(systemName: success ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
                    .font(.system(size: 52))
                    .foregroundStyle(success ? Color.MW.green : Color.MW.orange)

                Text(vm.statusMessage)
                    .font(.headline)
                    .multilineTextAlignment(.center)

                if success, !vm.sentTo.isEmpty {
                    VStack(spacing: 2) {
                        Text("Emailed to")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        ForEach(vm.sentTo, id: \.self) { addr in
                            Text(addr).font(.subheadline)
                        }
                    }
                }

                if success, let inv = vm.invoice {
                    invoiceSummary(inv)
                }

                Button {
                    dismiss()
                } label: {
                    Text("Done")
                        .font(.subheadline.bold())
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                        .background(Color.MW.green)
                        .foregroundStyle(.white)
                        .clipShape(Capsule())
                }
                .padding(.top, 8)
            }
            .padding(24)
        }
        .background(Color(.systemGroupedBackground))
    }

    private func invoiceSummary(_ inv: InvoiceCreateResult) -> some View {
        VStack(spacing: 8) {
            ForEach(inv.lineItems) { item in
                HStack {
                    Text(item.description)
                        .font(.subheadline)
                        .foregroundStyle(item.isExtras ? Color.MW.green : .primary)
                    Spacer()
                    Text(currency(item.amount)).font(.subheadline.monospacedDigit())
                }
            }
            Divider()
            if let tax = inv.taxAmount {
                summaryRow("GST", currency(tax))
            }
            if let total = inv.total {
                summaryRow("Total", currency(total), bold: true)
            }
        }
        .padding(16)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private func summaryRow(_ label: String, _ value: String, bold: Bool = false) -> some View {
        HStack {
            Text(label).font(bold ? .subheadline.bold() : .subheadline)
            Spacer()
            Text(value).font((bold ? Font.subheadline.bold() : Font.subheadline).monospacedDigit())
        }
    }

    // MARK: - Orchestration

    private func complete(withInvoice: Bool) {
        Task {
            vm.phase = .working
            let ok = await detailVM.completeJob(
                visitId: visit.visitId,
                extrasMinutes: vm.totalMinutes,
                extrasNote: vm.note
            )

            guard ok else {
                vm.phase = .failed
                vm.statusMessage = detailVM.errorMessage
                    ?? "Could not complete the visit. It will sync when you're back online."
                return
            }

            if withInvoice {
                _ = await vm.createAndSendInvoice(visitId: visit.visitId)
            } else {
                vm.phase = .success
                vm.statusMessage = vm.hasExtras
                    ? "Visit completed. \(vm.totalMinutes) min of extra work saved."
                    : "Visit completed."
            }
        }
    }

    // MARK: - Helpers

    private func sectionHeader(_ title: String, icon: String) -> some View {
        Label(title, systemImage: icon)
            .font(.footnote.weight(.semibold))
            .foregroundStyle(.secondary)
            .textCase(.uppercase)
            .padding(.leading, 4)
    }

    private func currency(_ amount: Double) -> String {
        String(format: "$%.2f", amount)
    }
}
