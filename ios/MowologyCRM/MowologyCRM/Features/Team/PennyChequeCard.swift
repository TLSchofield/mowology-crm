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

    static let ring: CGFloat = 56

    /// In a square box where the centre line crosses the top edge of the panel being matched (Tim, 2026-11-07, his drawing),
    /// as the ledger sits on its bottom edge: gold there says Penny has mastered this one.
    var body: some View {
        let r = Self.ring, t: CGFloat = 34
        // A square box, not a ring (Tim, 2026-11-07): the circles on the line are ticks and section heads; this is the one
        // judgement on the card, so it gets its own shape.
        let box = RoundedRectangle(cornerRadius: appStyle.cornerRadius(6))
        ZStack {
            // Page-filled to its own outline, so the centre line and the panel's edge run right up to the box (Tim,
            // 2026-11-07: a wider square fill hid 3 pt of line each side).
            box.fill(appStyle.page).frame(width: r, height: r)
            box.stroke(appStyle.line, lineWidth: 1).frame(width: r, height: r)
            Group {
                switch level {
                case "high":   ItKnowledgeMark(stage: .mastered, size: t)
                case "medium": ItKnowledgeMark(stage: .understood, size: t)
                default:
                    ItKnowledgeTriangleShape(inset: ItKnowledgeGeometry.triangleInset)
                        .stroke(it.color(.knowledgeDiscovered), style: StrokeStyle(lineWidth: 1.6, lineCap: .round, dash: [3.5, 3]))
                        .frame(width: t, height: t * sqrt(3) / 2)
                }
            }
            .offset(y: -2)                              // the triangle's weight sits low; lift it (not the box) to look centred
        }
        .accessibilityElement()
        .accessibilityLabel(level == "high" ? "Mastered: high confidence" : level.capitalized + " confidence")
    }

    /// Seat it on a panel's top edge: `panel.modifier(...)` adds room inside for its lower half and above for its upper.
    struct Seat: ViewModifier {
        let level: String?
        func body(content: Content) -> some View {
            if let level, ["high", "medium", "low"].contains(level) {
                content
                    .overlay(alignment: .top) { PaymentConfidenceTriangle(level: level).offset(y: -31) }
                    .padding(.top, 31)
            } else {
                content
            }
        }
    }
}

/// How far the money has come, as Tim drew it (2026-11-07): a plain triangle on the line, read as a fraction — the
/// joined count above its left side, the whole (3: invoice, payment, bank line) inside, what is joined to the right.
/// Only its left side and base are drawn.
/// "If the vertical line wasn't there it would look like a fraction — it's inferred, 2 of three."
struct PaymentFraction: View {
    @Environment(\.itAppStyle) private var appStyle
    let joined: Int
    var whole = 3
    let label: String
    /// Read out instead of Penny's sentence, which this replaces on screen.
    var spoken: String? = nil

    static let width: CGFloat = 48

    var body: some View {
        let w = Self.width, h = w * sqrt(3) / 2
        HStack(spacing: 8) {
            // The line's shade and weight throughout (Tim, 2026-11-07: bolder, it stole the focus from the card).
            Text("\(joined)")
                .font(appStyle.font(.title2, weight: .regular)).foregroundStyle(appStyle.meta)
                .frame(maxWidth: .infinity, alignment: .trailing)
                .padding(.trailing, -w * 0.34)            // tucked against the left side, up toward the apex, as near as the label
                .offset(y: -h * 0.22)
            ZStack {
                ItKnowledgeTriangleShape().fill(appStyle.page)
                FractionStroke().stroke(appStyle.line, style: StrokeStyle(lineWidth: 1, lineCap: .round, lineJoin: .round))
                Text("\(whole)")
                    .font(appStyle.font(.title2, weight: .regular)).foregroundStyle(appStyle.meta)
                    .minimumScaleFactor(0.6)
                    .offset(y: h * 0.16)
            }
            .frame(width: w, height: h)
            // Quiet like the numbers: what it names is already decided (Tim, 2026-11-07). `meta`, not the line colour itself,
            // which is too pale to read as text.
            Text(label.uppercased())
                .font(appStyle.font(.caption, weight: .medium)).foregroundStyle(appStyle.meta)
                .fixedSize(horizontal: false, vertical: true)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .dynamicTypeSize(...DynamicTypeSize.xxLarge)
        .accessibilityElement()
        .accessibilityLabel([spoken, "\(joined) of \(whole) joined: \(label)"].compactMap { $0 }.joined(separator: ". "))
    }
}

/// The fraction triangle's visible strokes: the left side (the fraction's bar) and the base from its corner to the centre
/// line, where the line carries on down; no right side (Tim, 2026-11-07: "the right line of the triangle shouldn't be
/// seen"; "base of the triangle was meant to meet the vertical axis").
private struct FractionStroke: Shape {
    func path(in rect: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: rect.midX, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.minX, y: rect.maxY))
        p.addLine(to: CGPoint(x: rect.midX, y: rect.maxY))
        return p
    }
}

/// A deposit the payments matcher proposes, on the centre line — the money's path from the bank to the books (Tim,
/// 2026-11-07): the number row, the bank icon, Penny's sentence, the confidence triangle, the deposit and its invoice(s)
/// side by side, Link / Record and Not now, and the ledger at the bottom, which fills once it is in the books.
struct PennyPaymentCard: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var vm: PennyCardViewModel
    let payment: BKPaymentMatch
    /// Set when this card was linked / recorded this sitting: the two are one now — one filled block, the ledger unlinks.
    var done: PennyCardViewModel.PaymentDone? = nil
    var extendsLine = true
    /// Drawn as the carousel's neighbour: a picture only — no sheet, no debug hooks.
    var isPeek = false
    @State private var confirmUnlink: PennyCardViewModel.PaymentDone?

    var body: some View {
        let count = vm.paySession.count
        VStack(alignment: .leading, spacing: 10) {
            // The bank icon heads the section, above the numbers (Tim, 2026-11-07), as the copies icon heads duplicates.
            PennyCardView.sectionIcon("building.columns.fill", style: appStyle)
                .frame(maxWidth: .infinity)
                .accessibilityElement()
                .accessibilityLabel("Likely payment, from the bank")
                .modifier(PaymentCarouselActions(vm: vm))
            if count > 1 {
                KanameNumberRow(count: count, current: vm.payPos,
                                isDone: { i in vm.paySession.indices.contains(i) && vm.paymentOutcome(vm.paySession[i]) != nil },
                                noun: "Payment", onTap: { i in vm.showPayment(at: i) })
            }
            messageLine
            if let done { doneBody(done) } else { openBody }
        }
        .background(alignment: .top) {
            Rectangle().fill(appStyle.line)
                .frame(width: 1).frame(maxHeight: .infinity)
                .padding(.top, 31)                              // from the bank icon's centre
                .padding(.bottom, extendsLine ? -400 : -32)
                .frame(maxWidth: .infinity)
                .accessibilityHidden(true)
        }
    }

    @ViewBuilder private var openBody: some View {
        // Penny's sentence is now the triangle (Tim, 2026-11-07, his sketch): how sure (its colour), how much of the
        // money's path is already joined (2 of 3 when the payment is on the invoice; 1 of 3 when only the invoice is),
        // and what that is, beside it. The name and its source are in the table below.
        PaymentFraction(joined: joinedCount, label: joinedLabel,
                        spoken: ([payment.verdict] + payment.evidence).joined(separator: ". "))

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
        }
        .padding(8)
        .padding(.top, ["high", "medium", "low"].contains(payment.confidence) ? 30 : 0)   // the ring's lower half
        .padding(.bottom, 30)                 // the ledger's upper half sits inside the panel
        .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.line, lineWidth: 1).allowsHitTesting(false))
        // The ledger sits where the centre line crosses the panel's bottom edge (Tim, 2026-11-07), as the business icon sits
        // on its card's top edge: its page-colour disc covers the frame there; the space below makes room for its lower half.
        .overlay(alignment: .bottom) { PennyCardView.ledgerIcon(filled: false, style: appStyle).offset(y: 31) }
        .padding(.bottom, 31)
        // How sure, in its ring where the line meets the panel's top edge (inside: room for its lower half).
        .modifier(PaymentConfidenceTriangle.Seat(level: payment.confidence))
    }

    private var linking: Bool { payment.button?.title == "Link" }
    /// Invoice, payment, bank line: the payment already on the invoice makes 2; an invoice alone makes 1.
    private var joinedCount: Int { linking ? 2 : 1 }
    private var joinedLabel: String {
        linking ? payment.targetLine + " + payment" : payment.targetLine
    }

    /// Linked / recorded: the deposit and its invoice(s) are one now — one filled block, the ledger filled: counted once, in
    /// the books (Tim, 2026-11-07). No Next or Undo: the carousel is swiped, and the ledger itself unlinks (with a confirm).
    /// The panel is page-filled, so the centre line stops at its top edge and comes back at the ledger.
    private func doneBody(_ d: PennyCardViewModel.PaymentDone) -> some View {
        let p = d.card
        let canUnlink = d.logId > 0 && !vm.isBusy
        return linkedBlock(p)
            .padding(8)
            .padding(.bottom, 30)                 // the ledger's upper half sits inside the panel
            .background(appStyle.page, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.line, lineWidth: 1).allowsHitTesting(false))
            .overlay(alignment: .bottom) {
                Group {
                    if d.logId > 0 {
                        Button { confirmUnlink = d } label: {
                            PennyCardView.ledgerIcon(filled: true, style: appStyle, tappable: true)
                        }
                        .buttonStyle(.plain)
                        .contentShape(Circle())
                        .disabled(!canUnlink)
                        .accessibilityElement()
                        .accessibilityLabel("In the books")
                        .accessibilityHint("Double-tap to unlink.")
                        .accessibilityAddTraits(.isButton)
                    } else {
                        PennyCardView.ledgerIcon(filled: true, style: appStyle)
                    }
                }
                .offset(y: 31)
            }
            .padding(.bottom, 31)
            .sheet(item: $confirmUnlink) { d in
                PaymentUnlinkSheet(done: d) { Task { await vm.undoPayment(d) } }
            }
            #if DEBUG
            .task {
                // `-mwPayUnlink` with `-mwDupeDemo paymentdone|paymentmany`: open the unlink sheet, for screenshots.
                if !isPeek, d.logId > 0, ProcessInfo.processInfo.arguments.contains("-mwPayUnlink") {
                    try? await Task.sleep(nanoseconds: 600_000_000)
                    confirmUnlink = d
                }
            }
            #endif
    }

    /// Over this many invoices the block shows the count and the first three; the unlink sheet lists them all.
    static let longList = 10

    /// The one filled block: link mark, the invoice number(s), the payer, the amount and how it came. Not a button.
    private func linkedBlock(_ p: BKPaymentMatch) -> some View {
        let indigo = appStyle.isEnabled ? appStyle.palette.color(.brand) : Color.indigo
        let many = p.targets.count > Self.longList
        let shown = many ? Array(p.targets.prefix(3)) : p.targets
        let amount = "\(PennyChequeCard.money(p.amount)) \(p.label.lowercased())"
        return VStack(spacing: 6) {
            Image(systemName: "link").font(appStyle.font(size: 30, weight: .semibold)).padding(.bottom, 4)
            if many {
                Text("\(p.targets.count) invoices").font(appStyle.font(.title3, weight: .bold))
            }
            ForEach(shown, id: \.self) { t in
                Text(t.number).font(appStyle.font(p.targets.count > 1 ? .headline : .title2, weight: .bold))
            }
            if many { Text("and \(p.targets.count - 3) more").font(appStyle.font(.subheadline)).opacity(0.85) }
            if let payer = p.targets.first?.payer, !payer.isEmpty {
                Text(payer).font(appStyle.font(.subheadline)).opacity(0.85).padding(.top, 2)
            }
            Text(amount).font(appStyle.font(.subheadline, weight: .semibold))
        }
        // The page colour, not white: indigo turns pale in dark mode, where white text on it would not read.
        .foregroundStyle(appStyle.page)
        .multilineTextAlignment(.center)
        .fixedSize(horizontal: false, vertical: true)
        .padding(.vertical, 18).padding(.horizontal, 12)
        .frame(maxWidth: .infinity)
        .background(indigo, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(
            (p.button?.title == "Link" ? "Linked to the bank: " : "Paid: ")
            + (many ? "\(p.targets.count) invoices, starting \(shown.map(\.number).joined(separator: ", "))"
                    : p.targets.map(\.number).joined(separator: ", "))
            + (p.targets.first.map { $0.payer.isEmpty ? "" : ", \($0.payer)" } ?? "") + ", \(amount)")
        .modifier(PaymentCarouselActions(vm: vm))
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

/// The payments carousel is swiped (Tim, 2026-11-07: no Next button); VoiceOver gets the same moves as actions.
struct PaymentCarouselActions: ViewModifier {
    @ObservedObject var vm: PennyCardViewModel
    func body(content: Content) -> some View {
        content
            .accessibilityAction(named: "Next payment") {
                withAnimation(.easeOut(duration: 0.22)) {
                    if vm.payPos + 1 < vm.paySession.count || vm.canSwipePastLastPayment { vm.swipeToNextPayment() }
                }
            }
            .accessibilityAction(named: "Previous payment") {
                withAnimation(.easeOut(duration: 0.22)) { vm.showPayment(at: vm.payPos - 1) }
            }
    }
}

/// Tapping the filled ledger: take the payment out of the books again (the matcher's undo), after a plain confirm.
struct PaymentUnlinkSheet: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dismiss) private var dismiss
    let done: PennyCardViewModel.PaymentDone
    let unlink: () -> Void

    var body: some View {
        let p = done.card
        let n = p.targets.count
        let from = n == 1 ? (p.targets.first?.number ?? "its invoice") : "\(n) invoices"
        VStack(spacing: 16) {
            Text("Take \(PennyChequeCard.money(p.amount)) out of the books?")
                .font(appStyle.font(.title3, weight: .bold)).multilineTextAlignment(.center)
            Text("It unlinks from \(from) and goes back to waiting.")
                .font(appStyle.font(.body)).foregroundStyle(appStyle.meta).multilineTextAlignment(.center)
            if n > PennyPaymentCard.longList {
                ScrollView {
                    VStack(alignment: .leading, spacing: 6) {
                        ForEach(p.targets, id: \.self) { t in
                            HStack {
                                Text(t.number).font(appStyle.font(.subheadline, weight: .semibold))
                                Spacer()
                                Text(PennyChequeCard.money(t.amount)).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                            }
                            .accessibilityElement(children: .combine)
                        }
                    }
                    .padding(.horizontal, 4)
                }
                .frame(maxHeight: 280)
            }
            VStack(spacing: 10) {
                Button(role: .destructive) { dismiss(); unlink() } label: {
                    Text("Unlink").font(appStyle.font(.headline)).frame(maxWidth: .infinity, minHeight: 50)
                        .foregroundStyle(appStyle.page).background(appStyle.blocked, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
                }
                Button { dismiss() } label: {
                    Text("Keep").font(appStyle.font(.headline)).frame(maxWidth: .infinity, minHeight: 50)
                        .foregroundStyle(appStyle.tenant)
                        .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)).strokeBorder(appStyle.tenant, lineWidth: 2))
                }
            }
            .buttonStyle(.plain)
        }
        .padding(20)
        .padding(.top, 8)
        .frame(maxHeight: .infinity, alignment: .top)
        .presentationDetents([.medium, .large])
        .presentationDragIndicator(.visible)
    }
}

/// The payment cards as a carousel, swipe only (Tim, 2026-11-07): swipe left for the next card, whose left edge peeks in
/// the right-hand margin; the current card and the centre line stay where they are. The neighbours are pictures only —
/// not tappable, hidden from VoiceOver (which moves with the cards' "Next payment" action instead).
struct PennyPaymentCarousel: View {
    @ObservedObject var vm: PennyCardViewModel
    let extendsLine: Bool
    @State private var width: CGFloat = 0
    /// The finger's offset; a GestureState so a drag the scroll view takes over (onEnded never runs) springs back by itself.
    @GestureState(resetTransaction: Transaction(animation: .spring(duration: 0.3))) private var dragX: CGFloat = 0
    /// The committed slide to the neighbour, after the finger lifts.
    @State private var slideX: CGFloat = 0

    /// Between a card and the next: the screen's 16 pt margin less this is how much of the next card shows.
    static let gap: CGFloat = 4

    var body: some View {
        if let payment = vm.currentPayment {
            PennyPaymentCard(vm: vm, payment: payment, done: vm.currentPaymentDone, extendsLine: extendsLine)
                .background(GeometryReader { g in
                    Color.clear.onAppear { width = g.size.width }.onChange(of: g.size.width) { _, w in width = w }
                })
                .overlay(alignment: .topLeading) { neighbours }
                .offset(x: dragX + slideX)
                .id(payment.id)
                .transition(.asymmetric(insertion: .move(edge: .trailing).combined(with: .opacity), removal: .opacity))
                .gesture(swipe)
        }
    }

    private func card(at i: Int) -> PennyPaymentCard? {
        guard vm.paySession.indices.contains(i), let p = vm.paySnapshots[vm.paySession[i]] else { return nil }
        var done: PennyCardViewModel.PaymentDone?
        if case .booked(let d)? = vm.payOutcomes[p.txId] { done = d }
        return PennyPaymentCard(vm: vm, payment: p, done: done, extendsLine: false, isPeek: true)
    }

    /// Cut to the current card's height, so a taller neighbour never reaches past it into the margin below.
    private var neighbours: some View {
        GeometryReader { g in
            ZStack(alignment: .topLeading) {
                if let next = card(at: vm.payPos + 1) {
                    next.frame(width: g.size.width, height: g.size.height, alignment: .top).clipped()
                        .offset(x: g.size.width + Self.gap)
                }
                if dragX + slideX > 0, let prev = card(at: vm.payPos - 1) {
                    prev.frame(width: g.size.width, height: g.size.height, alignment: .top).clipped()
                        .offset(x: -(g.size.width + Self.gap))
                }
            }
        }
        .allowsHitTesting(false)
        .accessibilityHidden(true)
    }

    private var swipe: some Gesture {
        DragGesture(minimumDistance: 20)
            .updating($dragX) { g, state, _ in
                guard abs(g.translation.width) > abs(g.translation.height) else { return }
                let t = g.translation.width
                state = blocked(t) ? t / 4 : t               // a little give at either end
            }
            .onEnded { g in
                let t = g.translation.width
                let far = abs(t) > max(60, width * 0.25) || abs(g.predictedEndTranslation.width) > width * 0.5
                guard far, abs(t) > abs(g.translation.height), !blocked(t), width > 0 else { return }   // springs back
                let from = vm.payPos
                if t < 0 && !vm.paySession.indices.contains(from + 1) {
                    // Past the last card: on to the first unanswered one, or receipts (the old Next button's job).
                    withAnimation(.easeOut(duration: 0.22)) { vm.swipeToNextPayment() }
                    return
                }
                let target = t < 0 ? from + 1 : from - 1        // swipe left for the next card
                var start = Transaction()
                start.disablesAnimations = true
                withTransaction(start) { slideX = t }        // carry on from where the finger let go
                withAnimation(.easeOut(duration: 0.2)) {
                    slideX = t < 0 ? -(width + Self.gap) : width + Self.gap
                } completion: {
                    // The neighbour now sits exactly where the card was: swap them with no animation. Only if nothing
                    // (a reload, an undo) moved the carousel meanwhile.
                    var tr = Transaction()
                    tr.disablesAnimations = true
                    withTransaction(tr) {
                        if vm.payPos == from { vm.showPayment(at: target) }
                        slideX = 0
                    }
                }
            }
    }

    /// No card that way: the first card going back, or the last going on while it still waits for an answer.
    private func blocked(_ t: CGFloat) -> Bool {
        t > 0 ? vm.payPos == 0 : (vm.payPos + 1 >= vm.paySession.count && !vm.canSwipePastLastPayment)
    }
}
