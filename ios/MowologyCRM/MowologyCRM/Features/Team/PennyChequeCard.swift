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
        VStack(alignment: .leading, spacing: 10) {
            Text(count > 1 ? "Likely cheque · 1 of \(count)" : "Likely cheque").font(appStyle.font(.headline))
            messageLine

            VStack(alignment: .leading, spacing: 4) {
                Text(picked ? "This \(Self.money(cheque.amount)) cheque\(cheque.payer.map { " from \($0)" } ?? "") pays \(target.number)?"
                            : cheque.verdict)
                    .font(appStyle.font(.title3, weight: .semibold))
                    .fixedSize(horizontal: false, vertical: true)
                Text(cheque.confidenceLine).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                    .fixedSize(horizontal: false, vertical: true)
            }
            .accessibilityElement(children: .combine)

            VStack(alignment: .leading, spacing: 10) {
                factsTable
                // The centre line stops at the facts and picks up again between the buttons: text never sits on it
                // (Tim's 1.3.97 screenshot: the line ran through the memo and "Or INV-…").
                Text("Bank memo: \(cheque.memo)").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    .fixedSize(horizontal: false, vertical: true)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(appStyle.page)

                // Another open invoice of the same amount or payer: pick it instead (small, away from the buttons).
                if targets.count > 1 {
                    VStack(alignment: .leading, spacing: 6) {
                        ForEach(Array(targets.enumerated()), id: \.offset) { i, t in
                            if t != target {
                                Button("Or \(t.number) (\(Self.money(t.balanceDue)))") { vm.pickCheque(cheque, index: i) }
                                    .font(appStyle.font(.footnote))
                                    .foregroundStyle(appStyle.tenant)
                                    .disabled(vm.isBusy)
                            }
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(appStyle.page)
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
            .background(alignment: .top) {
                Rectangle().fill(appStyle.line)
                    .frame(width: 1)
                    .frame(maxHeight: .infinity)
                    .padding(.bottom, extendsLine ? -400 : -32)   // toward Kaname, or just down to the duplicates (14 spacing + 18 padding)
                    .frame(maxWidth: .infinity)
                    .accessibilityHidden(true)
            }
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
                .fixedSize(horizontal: false, vertical: true)
        }
    }

    /// The duplicates' square button (same size whatever the text).
    private func squareButton(icon: String, title: String, detail: String, filled: Bool, action: @escaping () -> Void) -> some View {
        let shape = RoundedRectangle(cornerRadius: appStyle.cornerRadius(16))
        return Color.clear
            .frame(maxWidth: .infinity)
            .aspectRatio(1, contentMode: .fit)
            .overlay {
                Button(action: action) {
                    VStack(spacing: 8) {
                        if vm.isBusy && filled { ProgressView().tint(appStyle.onTenant).frame(height: 42) }
                        else { Image(systemName: icon).font(appStyle.font(size: 34, weight: .semibold)).frame(height: 42) }
                        Text(title).font(appStyle.font(.title3, weight: .bold))
                        Text(detail).font(appStyle.font(.footnote)).opacity(0.85)
                    }
                    .lineLimit(1)
                    .minimumScaleFactor(0.6)
                    .padding(10)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .foregroundStyle(filled ? appStyle.onTenant : appStyle.tenant)
                    .background(filled ? appStyle.tenant : Color.clear, in: shape)
                    .overlay(shape.strokeBorder(appStyle.tenant, lineWidth: filled ? 0 : 2))
                    .contentShape(shape)
                }
                .buttonStyle(.plain)
                .accessibilityLabel("\(title), \(detail)")
            }
    }

    static func money(_ v: Double) -> String {
        let f = NumberFormatter()
        f.numberStyle = .currency
        f.currencyCode = "CAD"
        f.currencySymbol = "$"
        return f.string(from: NSNumber(value: v)) ?? String(format: "$%.2f", v)
    }
}
