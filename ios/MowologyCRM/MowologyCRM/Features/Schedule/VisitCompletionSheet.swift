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
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

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
            .itAppScreen()
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
                if vm.snowIsRoute {
                    snowChoiceSection
                }
                extrasSection
                noteSection
                if visit.isPerVisitBillable && (!vm.unbilledItems.isEmpty || !vm.unbilledHints.isEmpty) {
                    UnbilledWorkSection(vm: vm)
                }
                actionButtons
                    // A snow & salt route stop can't be completed until the crew say what was done.
                    .disabled(vm.snowIsRoute && vm.snowChoice == nil)
                    .opacity(vm.snowIsRoute && vm.snowChoice == nil ? 0.45 : 1)
            }
            .padding(16)
        }
        .background(appStyle.page)
        .task {
            // Snow & salt route stop? Then the crew record what was done before completing.
            await vm.loadSnowRoute(visitId: visit.visitId, serviceType: visit.serviceType)
            // Missed work at the same address (e.g. a cut timed on another day, then skipped).
            if visit.isPerVisitBillable { await vm.loadUnbilled(visitId: visit.visitId) }
        }
    }

    private var extrasSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            sectionHeader("Timed Extra Work", icon: "timer")

            VStack(spacing: 16) {
                // Live timer + start/stop
                AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(alignment: .leading, spacing: 12)) : AnyLayout(HStackLayout())) {
                    Text(vm.timerDisplay)
                        .font(appStyle.font(size: 34, weight: .bold, design: .rounded).monospacedDigit())
                        .foregroundStyle(appStyle.text)
                    Spacer()
                    Button {
                        vm.toggleTimer()
                    } label: {
                        Label(vm.isTimerRunning ? "Stop" : "Start",
                              systemImage: vm.isTimerRunning ? "stop.fill" : "play.fill")
                            .font(appStyle.font(.subheadline, weight: .bold))
                            .padding(.horizontal, 16)
                            .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                            .padding(.vertical, 9)
                            .background(vm.isTimerRunning ? appStyle.attention : appStyle.tenant)
                            .foregroundStyle(appStyle.onTenant)
                            .clipShape(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999))
                    }
                }

                // Quick-add blocks
                AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(spacing: 8)) : AnyLayout(HStackLayout(spacing: 8))) {
                    ForEach([5, 10, 15, 30], id: \.self) { mins in
                        Button {
                            vm.addMinutes(mins)
                        } label: {
                            Text("+\(mins)")
                                .font(appStyle.font(.subheadline, weight: .bold))
                                .frame(maxWidth: .infinity)
                                .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                                .padding(.vertical, 10)
                                .background(appStyle.tenant.opacity(0.12))
                                .foregroundStyle(appStyle.tenant)
                                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
                        }
                    }
                }

                Divider()

                // Running total + live dollar preview
                AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(alignment: .leading, spacing: 12)) : AnyLayout(HStackLayout())) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("\(vm.totalMinutes) min")
                            .font(appStyle.font(.headline))
                        if vm.hasExtras {
                            Text("\(vm.billableBlocks) × 5-min block\(vm.billableBlocks == 1 ? "" : "s")")
                                .font(appStyle.font(.caption))
                                .foregroundStyle(appStyle.meta)
                        }
                    }
                    Spacer()
                    Text(currency(vm.extrasAmount))
                        .font(appStyle.font(.title3, weight: .bold))
                        .foregroundStyle(vm.hasExtras ? appStyle.tenant : appStyle.meta)
                }

                if vm.hasExtras {
                    Button("Reset extras", role: .destructive) { vm.resetExtras() }
                        .font(appStyle.font(.caption))
                        .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                }
            }
            .padding(16)
            .background(appStyle.surface)
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        }
    }

    private var snowChoiceSection: some View {
        VStack(alignment: .leading, spacing: 10) {
            sectionHeader("What was done here?", icon: "snowflake")
            LazyVGrid(columns: Array(repeating: GridItem(.flexible()), count: appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? 1 : 2), spacing: 8) {
                ForEach(vm.snowChoices, id: \.self) { option in
                    Button {
                        Task { _ = await vm.recordSnowChoice(visitId: visit.visitId, choice: option.value) }
                    } label: {
                        Text(option.label)
                            .font(appStyle.font(.subheadline, weight: .bold))
                            .multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity, minHeight: appStyle.isEnabled ? 48 : 44)
                            .padding(.vertical, 4)
                            .background(vm.snowChoice == option.value ? appStyle.tenant : appStyle.tenant.opacity(0.12))
                            .foregroundStyle(vm.snowChoice == option.value ? appStyle.onTenant : appStyle.tenant)
                            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
                    }
                }
            }
            if let err = vm.snowError {
                Text(err).font(appStyle.font(.footnote)).foregroundStyle(appStyle.attention)
            } else if vm.snowChoice == nil {
                Text("Pick one to complete the stop. The invoice bills only what you pick.")
                    .font(appStyle.font(.footnote))
                    .foregroundStyle(appStyle.meta)
            }
        }
    }

    private var noteSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            sectionHeader("Note to Client (optional)", icon: "text.bubble")
            TextField("e.g. Trimmed hedge by gate as requested",
                      text: $vm.note, axis: .vertical)
                .lineLimit(2...4)
                .padding(12)
                .background(appStyle.surface)
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
        }
    }

    private var actionButtons: some View {
        VStack(spacing: 10) {
            if vm.snowIsRoute && vm.snowChoice == "none" {
                // Checked, nothing needed: the stop is completed and nothing is billed.
                Button {
                    complete(withInvoice: false)
                } label: {
                    Label("Complete — Nothing Needed", systemImage: "checkmark.circle.fill")
                        .font(appStyle.font(.subheadline, weight: .bold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                        .background(appStyle.tenant)
                        .foregroundStyle(appStyle.onTenant)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999))
                }
            } else if visit.isPerVisitBillable {
                Button {
                    complete(withInvoice: true)
                } label: {
                    Label(vm.tickedUnbilled.isEmpty ? "Complete & Invoice"
                                                    : "Complete & Invoice (+\(vm.tickedUnbilled.count))",
                          systemImage: "paperplane.fill")
                        .font(appStyle.font(.subheadline, weight: .bold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                        .background(appStyle.tenant)
                        .foregroundStyle(appStyle.onTenant)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999))
                }

                Button {
                    complete(withInvoice: false)
                } label: {
                    Text(vm.hasExtras ? "Complete — Save Extras, No Invoice" : "Complete — No Invoice")
                        .font(appStyle.font(.subheadline, weight: .bold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                        .background(appStyle.tenant.opacity(0.12))
                        .foregroundStyle(appStyle.tenant)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999))
                }
            } else {
                // Contract / monthly-flat work: the contract bills it, so a visit never raises
                // an invoice. Extras are still saved against the visit for the office to bill.
                Button {
                    complete(withInvoice: false)
                } label: {
                    Label(vm.hasExtras ? "Complete & Save Extras" : "Complete Visit", systemImage: "checkmark.circle.fill")
                        .font(appStyle.font(.subheadline, weight: .bold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                        .background(appStyle.tenant)
                        .foregroundStyle(appStyle.onTenant)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999))
                }
                Label(visit.isContractBilled ? "Billed under the contract — no invoice from this visit."
                                             : "Billed monthly — no invoice from this visit.",
                      systemImage: "doc.text")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
                    .frame(maxWidth: .infinity)
            }
        }
        .padding(.top, 4)
    }

    // MARK: - Working

    private var workingView: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text("Completing visit…")
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.meta)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(appStyle.page)
    }

    // MARK: - Result

    private func resultView(success: Bool) -> some View {
        ScrollView {
            VStack(spacing: 16) {
                Image(systemName: success ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
                    .font(appStyle.font(size: 52))
                    .foregroundStyle(success ? appStyle.tenant : appStyle.attention)

                Text(vm.statusMessage)
                    .font(appStyle.font(.headline))
                    .multilineTextAlignment(.center)

                if success, !vm.sentTo.isEmpty {
                    VStack(spacing: 2) {
                        Text("Emailed to")
                            .font(appStyle.font(.caption))
                            .foregroundStyle(appStyle.meta)
                        ForEach(vm.sentTo, id: \.self) { addr in
                            Text(addr).font(appStyle.font(.subheadline))
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
                        .font(appStyle.font(.subheadline, weight: .bold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                        .background(appStyle.tenant)
                        .foregroundStyle(appStyle.onTenant)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999))
                }
                .padding(.top, 8)
            }
            .padding(24)
        }
        .background(appStyle.page)
    }

    private func invoiceSummary(_ inv: InvoiceCreateResult) -> some View {
        VStack(spacing: 8) {
            ForEach(inv.lineItems) { item in
                AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(alignment: .leading, spacing: 12)) : AnyLayout(HStackLayout())) {
                    Text(item.description)
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(item.isExtras ? appStyle.tenant : appStyle.text)
                    Spacer()
                    Text(currency(item.amount)).font(appStyle.font(.subheadline).monospacedDigit())
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
        .background(appStyle.surface)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
    }

    private func summaryRow(_ label: String, _ value: String, bold: Bool = false) -> some View {
        HStack {
            Text(label).font(appStyle.font(.subheadline, weight: bold ? .bold : .regular))
            Spacer()
            Text(value).font(appStyle.font(.subheadline, weight: bold ? .bold : .regular).monospacedDigit())
        }
    }

    // MARK: - Orchestration

    private func complete(withInvoice: Bool) {
        Task {
            vm.phase = .working
            let ok = await detailVM.completeJob(
                visitId: visit.visitId,
                extrasMinutes: vm.totalMinutes,
                extrasNote: vm.note,
                snowChoice: vm.snowIsRoute ? vm.snowChoice : nil
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
            .font(appStyle.font(.footnote, weight: .semibold))
            .foregroundStyle(appStyle.meta)
            .textCase(.uppercase)
            .padding(.leading, 4)
    }

    private func currency(_ amount: Double) -> String {
        String(format: "$%.2f", amount)
    }
}
