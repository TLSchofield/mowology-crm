//
//  PennyChequeCard.swift
//  MowologyCRM
//
//  "Likely cheque" on Penny's card (Tim, 2026-11-06: first, before the duplicates, same design language).
//  A cheque deposit in the bank that probably pays an open invoice: Penny's verdict, the cheque and the invoice side
//  by side on one centre line with a ringed tick where they agree, then two square buttons — Record / Not now.
//  Suggest only: Record is the person's tap (cheque_record → BankInvoiceMatchService::record()).
//

import SwiftUI

struct PennyChequeCard: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var vm: PennyCardViewModel
    let cheque: BKChequeMatch
    /// Run the line on past the card toward the Kaname button. Off when another panel follows (the duplicates): the line
    /// then stops at that panel's frame — 400 pt on, it cut through a three-receipt comparison (Tim, 2026-11-06).
    var extendsLine = true

    var body: some View {
        let target = vm.chequeTarget(cheque)
        let targets = vm.chequeTargets(cheque)
        let picked = (vm.chequePick[cheque.txId] ?? 0) > 0
        let count = vm.openCheques.count
        // The section starts with its icon on the axis (a bank, Tim 2026-11-06: "similar to the copies icon"); the axis
        // runs from it down through the panel. Words that span the width sit on page colour so the line breaks round them.
        VStack(alignment: .leading, spacing: 10) {
            VStack(spacing: 6) {
                PennyCardView.sectionIcon("building.columns.fill", style: appStyle)
                if count > 1 {
                    Text("1 of \(count)").font(appStyle.font(.footnote, weight: .semibold)).foregroundStyle(appStyle.meta)
                        .padding(.horizontal, 6).background(appStyle.page)
                }
            }
            .frame(maxWidth: .infinity)
            .accessibilityElement(children: .combine)
            .accessibilityLabel(count > 1 ? "Likely cheque, 1 of \(count)" : "Likely cheque")
            messageLine.frame(maxWidth: .infinity)

            VStack(alignment: .center, spacing: 4) {
                Text(picked ? "This \(Self.money(cheque.amount)) cheque\(cheque.payer.map { " from \($0)" } ?? "") pays \(target.number)?"
                            : cheque.verdict)
                    .font(appStyle.font(.title3, weight: .semibold))
                    .fixedSize(horizontal: false, vertical: true)
                Text(cheque.confidenceLine).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                    .fixedSize(horizontal: false, vertical: true)
            }
            .multilineTextAlignment(.center)
            .padding(.horizontal, 6)
            .background(appStyle.page)
            .frame(maxWidth: .infinity)
            .accessibilityElement(children: .combine)

            VStack(alignment: .leading, spacing: 10) {
                factsTable
                // The memo is the cheque's, so it sits in the cheque's column; another invoice to pick sits in the
                // invoice's. Nothing crosses the axis (Tim, 2026-11-06: "bank memo text should not cross the vertical axis").
                HStack(alignment: .top, spacing: PennyCardView.columnGap) {
                    Text("Bank memo: \(cheque.memo)").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                        .multilineTextAlignment(.trailing)
                        .fixedSize(horizontal: false, vertical: true)
                        .frame(maxWidth: .infinity, alignment: .trailing)
                    VStack(alignment: .leading, spacing: 6) {
                        ForEach(Array(targets.enumerated()), id: \.offset) { i, t in
                            if t != target {
                                Button("Or \(t.number) (\(Self.money(t.balanceDue)))") { vm.pickCheque(cheque, index: i) }
                                    .font(appStyle.font(.footnote))
                                    .foregroundStyle(appStyle.tenant)
                                    .multilineTextAlignment(.leading)
                                    .disabled(vm.isBusy)
                            }
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }

                HStack(spacing: PennyCardView.columnGap) {
                    squareButton(icon: "checkmark", title: "Record", detail: "\(Self.money(target.apply)) on \(target.number)", filled: true) {
                        Task { await vm.recordCheque(cheque) }
                    }
                    .accessibilityHint("Records the cheque as payment of \(target.number). The deposit then counts once, on the invoice.")
                    squareButton(icon: "arrow.right", title: "Not now", detail: "leave it in the bank", filled: false) {
                        withAnimation(.easeOut(duration: 0.22)) { vm.skipCheque(cheque) }
                    }
                    .accessibilityHint("Hides this cheque until the card opens again.")
                }
                .disabled(vm.isBusy)
                .frame(maxWidth: .infinity)
                .padding(.top, 2)
            }
            .padding(8)
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.line, lineWidth: 1).allowsHitTesting(false))
        }
        .background(alignment: .top) {
            Rectangle().fill(appStyle.line)
                .frame(width: 1)
                .frame(maxHeight: .infinity)
                .padding(.top, 31)                                 // from the icon's centre
                .padding(.bottom, extendsLine ? -400 : -32)        // toward Kaname, or down to the duplicates' icon
                .frame(maxWidth: .infinity)
                .accessibilityHidden(true)
        }
    }

    /// The cheque (left) and the invoice (right), a ringed tick between them where they agree.
    private var factsTable: some View {
        let gap = PennyCardView.columnGap
        let indigo = appStyle.isEnabled ? appStyle.palette.color(.brand) : Color.indigo
        return VStack(spacing: 0) {
            HStack(spacing: 0) {
                Text(cheque.label).font(appStyle.font(.caption, weight: .bold)).foregroundStyle(appStyle.meta)
                    .frame(maxWidth: .infinity, alignment: .trailing)
                Color.clear.frame(width: gap, height: 1)
                Text(cheque.invoice.number).font(appStyle.font(.caption, weight: .bold)).foregroundStyle(appStyle.tenant)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(.bottom, 4)
            ForEach(cheque.facts, id: \.self) { f in
                let match = f.state == "same"
                HStack(alignment: .center, spacing: 0) {
                    cell(f.cheque, state: f.state).frame(maxWidth: .infinity, alignment: .trailing)
                    Group {
                        if match {
                            ZStack {
                                Circle().fill(appStyle.page).frame(width: 27, height: 27)
                                Circle().stroke(appStyle.line, lineWidth: 1).frame(width: 25, height: 25)
                                Image(systemName: "checkmark.circle.fill")
                                    .font(appStyle.font(size: 16, weight: .semibold))
                                    .foregroundStyle(indigo)
                            }
                            .accessibilityHidden(true)
                        } else { Color.clear }
                    }
                    .frame(width: gap)
                    cell(f.invoice, state: f.state).frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(.vertical, 3)
                .accessibilityElement(children: .combine)
                .accessibilityLabel(match ? "\(f.label) matches: \(f.cheque), \(f.invoice)" : "\(f.label): cheque \(f.cheque), invoice \(f.invoice)")
                Divider().overlay(appStyle.line)
            }
        }
    }

    private func cell(_ text: String, state: String) -> some View {
        Text(text)
            .font(appStyle.font(.footnote, weight: state == "differs" ? .semibold : .regular))
            .foregroundStyle(state == "missing" ? appStyle.meta : appStyle.text)
            .lineLimit(2)
            .minimumScaleFactor(0.8)
            .padding(.horizontal, state == "differs" ? 4 : 0).padding(.vertical, 1)
            .background(state == "differs" ? appStyle.attention.opacity(0.18) : Color.clear, in: RoundedRectangle(cornerRadius: 4))
    }

    @ViewBuilder private var messageLine: some View {
        if let m = vm.message {
            Text(m)
                .font(appStyle.font(.footnote))
                .foregroundStyle(vm.messageIsError ? appStyle.blocked : appStyle.tenant)
                .multilineTextAlignment(.center)
                .fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 6)
                .background(appStyle.page)
        }
    }

    /// The duplicates' square button (same size whatever the text).
    private func squareButton(icon: String, title: String, detail: String, filled: Bool, action: @escaping () -> Void) -> some View {
        chequeSquareButton(style: appStyle, busy: vm.isBusy, icon: icon, title: title, detail: detail, filled: filled, action: action)
    }

    static func money(_ v: Double) -> String {
        let f = NumberFormatter()
        f.numberStyle = .currency
        f.currencyCode = "CAD"
        f.currencySymbol = "$"
        return f.string(from: NSNumber(value: v)) ?? String(format: "$%.2f", v)
    }
}

/// A recorded cheque: the cheque and the invoice are one now (Tim, 2026-11-06: "when a cheque is attached to an invoice
/// the two become one. unity"). The bank icon on the axis, the paid invoice alone in the middle with the cheque inside
/// it, and one way on — centred, like a merged duplicate.
struct PennyChequeDoneCard: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var vm: PennyCardViewModel
    let done: PennyCardViewModel.ChequeDone
    var extendsLine = true

    var body: some View {
        let nextLabel = !vm.openCheques.isEmpty ? "next cheque" : (vm.showsDupes ? "on to duplicates" : "all done")
        VStack(spacing: 10) {
            PennyCardView.sectionIcon("building.columns.fill", style: appStyle)
                .frame(maxWidth: .infinity)
            VStack(spacing: 10) {
                VStack(spacing: 4) {
                    Label("Paid", systemImage: "checkmark.circle.fill")
                        .font(appStyle.font(.subheadline, weight: .semibold))
                        .foregroundStyle(appStyle.tenant)
                    Text(done.target.number).font(appStyle.font(.title2, weight: .bold))
                    if !done.cheque.invoice.payer.isEmpty {
                        Text(done.cheque.invoice.payer).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                    }
                    Text("\(PennyChequeCard.money(done.target.apply)) cheque · banked \(done.banked)")
                        .font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
                }
                .multilineTextAlignment(.center)
                .padding(.vertical, 10).padding(.horizontal, 12)
                .background(appStyle.page)
                .frame(maxWidth: .infinity)
                .accessibilityElement(children: .combine)

                chequeSquareButton(style: appStyle, busy: vm.isBusy, icon: "arrow.right", title: "Next", detail: nextLabel, filled: true) {
                    withAnimation(.easeOut(duration: 0.22)) { vm.dismissChequeDone() }
                }
                    .frame(width: PennyCardView.singleColumn)
                    .frame(maxWidth: .infinity)
            }
            .padding(8)
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.line, lineWidth: 1).allowsHitTesting(false))
        }
        .background(alignment: .top) {
            Rectangle().fill(appStyle.line)
                .frame(width: 1)
                .frame(maxHeight: .infinity)
                .padding(.top, 31)
                .padding(.bottom, extendsLine ? -400 : -32)
                .frame(maxWidth: .infinity)
                .accessibilityHidden(true)
        }
    }
}

/// Penny's square button, as on the duplicates: same size whatever the text, outlined ones page-filled so the axis passes
/// behind them.
fileprivate func chequeSquareButton(style: ItAppStyle, busy: Bool, icon: String, title: String, detail: String, filled: Bool,
                                    action: @escaping () -> Void) -> some View {
    let shape = RoundedRectangle(cornerRadius: style.cornerRadius(16))
    return Color.clear
        .frame(maxWidth: .infinity)
        .aspectRatio(1, contentMode: .fit)
        .overlay {
            Button(action: action) {
                VStack(spacing: 8) {
                    if busy && filled { ProgressView().tint(style.onTenant).frame(height: 42) }
                    else { Image(systemName: icon).font(style.font(size: 34, weight: .semibold)).frame(height: 42) }
                    Text(title).font(style.font(.title3, weight: .bold))
                    Text(detail).font(style.font(.footnote)).opacity(0.85)
                }
                .lineLimit(1)
                .minimumScaleFactor(0.6)
                .padding(10)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .foregroundStyle(filled ? style.onTenant : style.tenant)
                .background(filled ? style.tenant : style.page, in: shape)
                .overlay(shape.strokeBorder(style.tenant, lineWidth: filled ? 0 : 2))
                .contentShape(shape)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("\(title), \(detail)")
        }
}
