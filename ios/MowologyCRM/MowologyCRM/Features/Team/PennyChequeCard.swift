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

// MARK: - Likely payment (the payments matcher, 1.3.108; the money's path, 1.3.110)

/// How sure the matcher is, as the knowledge brain's triangle on the centre line instead of words (Tim, 2026-11-07:
/// "Its either broken, solid blue or gold. Instead of text"): low = broken outline (discovered), medium = solid blue
/// (understood), high = gold with the mastered keyline. EXCEPTION, 2026-11-07, Tim: the knowledge colours are otherwise
/// "knowledge brain only; never an operational status colour" (tokens.json) — this triangle is the one other use.
struct PaymentConfidenceTriangle: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.it) private var it
    let level: String

    var body: some View {
        ZStack {
            Circle().fill(appStyle.page).frame(width: 31, height: 31)
            Circle().stroke(appStyle.line, lineWidth: 1).frame(width: 29, height: 29)
            switch level {
            case "high":   ItKnowledgeMark(stage: .mastered, size: 17)
            case "medium": ItKnowledgeMark(stage: .understood, size: 17)
            default:
                ItKnowledgeTriangleShape(inset: ItKnowledgeGeometry.triangleInset)
                    .stroke(it.color(.knowledgeDiscovered), style: StrokeStyle(lineWidth: 1.4, lineCap: .round, dash: [2.6, 2.2]))
                    .frame(width: 17, height: 17 * sqrt(3) / 2)
            }
        }
        .frame(maxWidth: .infinity)
        .accessibilityElement()
        .accessibilityLabel(level.capitalized + " confidence")
    }
}

/// A deposit the payments matcher proposes, on the centre line — the money's path from the bank to the books (Tim,
/// 2026-11-07): the number row, the bank icon, Penny's sentence, the confidence triangle, the deposit and its invoice(s)
/// side by side, Link / Record and Not now, and the ledger at the bottom, which fills once it is in the books.
struct PennyPaymentCard: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var vm: PennyCardViewModel
    let payment: BKPaymentMatch
    /// Set when this card was linked / recorded this sitting: the two are one now — shown centred, with Next and Undo.
    var done: PennyCardViewModel.PaymentDone? = nil
    var extendsLine = true

    var body: some View {
        let count = vm.paySession.count
        VStack(alignment: .leading, spacing: 10) {
            if count > 1 {
                KanameNumberRow(count: count, current: vm.payPos,
                                isDone: { i in vm.paySession.indices.contains(i) && vm.paymentOutcome(vm.paySession[i]) != nil },
                                noun: "Payment", onTap: { i in vm.showPayment(at: i) })
            }
            PennyCardView.sectionIcon("building.columns.fill", style: appStyle)
                .frame(maxWidth: .infinity)
                .accessibilityElement()
                .accessibilityLabel("Likely payment, from the bank")
            messageLine
            if let done { doneBody(done) } else { openBody }
        }
        .background(alignment: .top) {
            Rectangle().fill(appStyle.line)
                .frame(width: 1).frame(maxHeight: .infinity)
                .padding(.top, count > 1 ? 26 : 31)            // from the current number, else the bank icon
                .padding(.bottom, extendsLine ? -400 : -32)
                .frame(maxWidth: .infinity)
                .accessibilityHidden(true)
        }
    }

    @ViewBuilder private var openBody: some View {
        VStack(spacing: 4) {
            Text(payment.verdict).font(appStyle.font(.title3, weight: .semibold)).fixedSize(horizontal: false, vertical: true)
            if !payment.evidence.isEmpty {
                Text(payment.evidence.joined(separator: " · "))
                    .font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta).fixedSize(horizontal: false, vertical: true)
            }
        }
        .multilineTextAlignment(.center)
        .padding(.horizontal, 6)
        .background(appStyle.page)
        .frame(maxWidth: .infinity)
        .accessibilityElement(children: .combine)

        if ["high", "medium", "low"].contains(payment.confidence) {
            PaymentConfidenceTriangle(level: payment.confidence)
        }

        VStack(alignment: .leading, spacing: 10) {
            factsTable
            // The memo is the deposit's (its column); the invoices it pays are theirs (the other column).
            HStack(alignment: .top, spacing: PennyCardView.columnGap) {
                Text("Bank memo: \(payment.memo)").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    .multilineTextAlignment(.trailing).fixedSize(horizontal: false, vertical: true)
                    .frame(maxWidth: .infinity, alignment: .trailing)
                VStack(alignment: .leading, spacing: 2) {
                    if payment.targets.count > 1 {
                        ForEach(payment.targets, id: \.self) { t in
                            Text("\(t.number) · \(PennyChequeCard.money(t.amount))").font(appStyle.font(.caption)).foregroundStyle(appStyle.text)
                        }
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            ForEach(payment.flags, id: \.self) { f in
                Label(f, systemImage: "exclamationmark.triangle").font(appStyle.font(.caption)).foregroundStyle(appStyle.attention)
                    .padding(.horizontal, 6).background(appStyle.page).frame(maxWidth: .infinity)
            }
            let linking = payment.button?.title == "Link"
            HStack(spacing: PennyCardView.columnGap) {
                chequeSquareButton(style: appStyle, busy: vm.isBusy, icon: linking ? "link" : "checkmark",
                                   title: payment.button?.title ?? "Record",
                                   detail: payment.button?.detail ?? "\(PennyChequeCard.money(payment.amount)) on \(payment.targetLine)", filled: true) {
                    Task { await vm.recordPayment(payment) }
                }
                .accessibilityHint(linking
                    ? "The payment is already on \(payment.targetLine). This ties the bank line to it, so it counts once. You can undo it."
                    : "Records the deposit on \(payment.targetLine). It then counts once, on the invoice. You can undo it.")
                chequeSquareButton(style: appStyle, busy: false, icon: "arrow.right", title: "Not now", detail: "leave it in the bank", filled: false) {
                    withAnimation(.easeOut(duration: 0.22)) { vm.skipPayment(payment) }
                }
            }
            .disabled(vm.isBusy)
            .frame(maxWidth: .infinity)
            .padding(.top, 2)
            // The money's path ends here: in the books (outline until it is linked or recorded).
            PennyCardView.ledgerIcon(filled: false, style: appStyle).frame(maxWidth: .infinity)
        }
        .padding(8)
        .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.line, lineWidth: 1).allowsHitTesting(false))
    }

    /// Linked / recorded: the deposit and its invoice(s) are one now — centred, one way on (Next), Undo underneath, the
    /// ledger filled: counted once, in the books.
    private func doneBody(_ d: PennyCardViewModel.PaymentDone) -> some View {
        let p = d.card
        let next = !vm.openPayments.isEmpty ? "next payment" : (vm.dupes.isEmpty ? "on to receipts" : "on to duplicates")
        return VStack(spacing: 10) {
            VStack(spacing: 4) {
                Label(p.button?.title == "Link" ? "Linked to the bank" : "Paid", systemImage: "checkmark.circle.fill")
                    .font(appStyle.font(.subheadline, weight: .semibold)).foregroundStyle(appStyle.tenant)
                ForEach(p.targets, id: \.self) { t in Text(t.number).font(appStyle.font(p.targets.count > 1 ? .headline : .title2, weight: .bold)) }
                if let payer = p.targets.first?.payer, !payer.isEmpty {
                    Text(payer).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                }
                Text("\(PennyChequeCard.money(p.amount)) \(p.label.lowercased())").font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
            }
            .multilineTextAlignment(.center)
            .padding(.vertical, 10).padding(.horizontal, 12)
            .background(appStyle.page)
            .frame(maxWidth: .infinity)
            .accessibilityElement(children: .combine)

            chequeSquareButton(style: appStyle, busy: vm.isBusy, icon: "arrow.right", title: "Next", detail: next, filled: true) {
                withAnimation(.easeOut(duration: 0.22)) { vm.advancePayment() }
            }
            .frame(width: PennyCardView.singleColumn)
            .frame(maxWidth: .infinity)
            if d.logId > 0 {
                Button("Undo") { Task { await vm.undoPayment(d) } }
                    .font(appStyle.font(.footnote, weight: .semibold))
                    .foregroundStyle(appStyle.tenant)
                    .padding(.horizontal, 8).background(appStyle.page)
                    .frame(maxWidth: .infinity, minHeight: 44)
                    .disabled(vm.isBusy)
                    .accessibilityHint("Takes the payment off the invoice again; the deposit goes back to waiting.")
            }
            PennyCardView.ledgerIcon(filled: true, style: appStyle).frame(maxWidth: .infinity)
        }
        .padding(8)
        .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.line, lineWidth: 1).allowsHitTesting(false))
    }

    @ViewBuilder private var messageLine: some View {
        if let m = vm.message {
            Text(m).font(appStyle.font(.footnote)).foregroundStyle(vm.messageIsError ? appStyle.blocked : appStyle.tenant)
                .multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 6).background(appStyle.page).frame(maxWidth: .infinity)
        }
    }

    private var factsTable: some View {
        let gap = PennyCardView.columnGap
        let indigo = appStyle.isEnabled ? appStyle.palette.color(.brand) : Color.indigo
        return VStack(spacing: 0) {
            HStack(spacing: 0) {
                Text(payment.label).font(appStyle.font(.caption, weight: .bold)).foregroundStyle(appStyle.meta)
                    .frame(maxWidth: .infinity, alignment: .trailing)
                Color.clear.frame(width: gap, height: 1)
                Text(payment.targetLine).font(appStyle.font(.caption, weight: .bold)).foregroundStyle(appStyle.tenant)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(.bottom, 4)
            ForEach(payment.facts, id: \.self) { f in
                HStack(alignment: .center, spacing: 0) {
                    Text(f.deposit).font(appStyle.font(.footnote, weight: f.state == "differs" ? .semibold : .regular))
                        .foregroundStyle(f.state == "missing" ? appStyle.meta : appStyle.text)
                        .multilineTextAlignment(.trailing).lineLimit(2).minimumScaleFactor(0.8)
                        .frame(maxWidth: .infinity, alignment: .trailing)
                    Group {
                        if f.state == "same" {
                            ZStack {
                                Circle().fill(appStyle.page).frame(width: 27, height: 27)
                                Circle().stroke(appStyle.line, lineWidth: 1).frame(width: 25, height: 25)
                                Image(systemName: "checkmark.circle.fill").font(appStyle.font(size: 16, weight: .semibold)).foregroundStyle(indigo)
                            }
                            .accessibilityHidden(true)
                        } else { Color.clear }
                    }
                    .frame(width: gap)
                    Text(f.invoice).font(appStyle.font(.footnote, weight: f.state == "differs" ? .semibold : .regular))
                        .foregroundStyle(appStyle.text).lineLimit(2).minimumScaleFactor(0.8)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                .padding(.vertical, 3)
                .accessibilityElement(children: .combine)
                .accessibilityLabel(f.state == "same" ? "\(f.label) matches: \(f.deposit), \(f.invoice)" : "\(f.label): deposit \(f.deposit), invoice \(f.invoice)")
                Divider().overlay(appStyle.line)
            }
        }
    }
}
