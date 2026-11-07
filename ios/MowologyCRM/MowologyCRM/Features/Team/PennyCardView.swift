//
//  PennyCardView.swift
//  MowologyCRM
//
//  Penny's receipt card for the admin, one receipt at a time: the photo, her read of
//  every field (with how sure she is and why), and Approve · Save draft · Reject · Skip.
//  Likely cheques come first (PennyChequeCard), then possible duplicates, as on the web card — never offered for approval.
//

import SwiftUI
import UIKit

struct PennyCardView: View {
    @Environment(\.itAppStyle) private var appStyle

    @ObservedObject var vm: PennyCardViewModel

    @State private var zoomTarget: ZoomTarget?
    @State private var showReject = false
    @State private var rejectReason = ""
    @State private var pagerTarget: PagerTarget?

    @Environment(\.openURL) private var openURL

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            mailSection
            if vm.isLoading && !vm.hasLoaded {
                HStack { Spacer(); ProgressView("Loading Penny's receipts…"); Spacer() }
                    .padding(.vertical, 40)
            } else if let err = vm.loadError, vm.queue.isEmpty && vm.dupes.isEmpty && vm.cheques.isEmpty {
                errorState(err)
            } else if let cheque = vm.currentCheque {
                // Likely cheques first (Tim, 2026-11-06), with the duplicates still under them on the same line
                // ("duplicates have vanished" — one-at-a-time hid them until every cheque was answered); receipts after.
                PennyChequeCard(vm: vm, cheque: cheque)
                    .id(cheque.id)
                    .transition(.asymmetric(insertion: .move(edge: .leading).combined(with: .opacity), removal: .opacity))
                if vm.showsDupes, let group = vm.currentDupe {
                    dupeCard(group)
                        .id(group.id)
                        .padding(.top, 18)
                }
            } else if vm.showsDupes, let group = vm.currentDupe {
                dupeCard(group)
                    .id(group.id)
                    .transition(.asymmetric(insertion: .move(edge: vm.dupeSwipeForward ? .leading : .trailing).combined(with: .opacity),
                                            removal: .opacity))
                    // A carousel: swipe sideways to the next / previous group (the photos open full screen on a tap).
                    .gesture(DragGesture(minimumDistance: 30).onEnded { g in
                        guard abs(g.translation.width) > abs(g.translation.height) * 1.5, abs(g.translation.width) > 60 else { return }
                        // Right to left, as the numbers read: drag right to bring the next group in from the left.
                        withAnimation(.easeOut(duration: 0.22)) { g.translation.width > 0 ? vm.nextDupe() : vm.previousDupe() }
                    })
            } else if let item = vm.current {
                receiptCard(item)
            } else if vm.hasLoaded {
                emptyState
            }
        }
        .fullScreenCover(item: $zoomTarget) { target in
            ZoomableReceiptView(url: target.url)
        }
        .alert("Reject this receipt?", isPresented: $showReject) {
            TextField("Reason", text: $rejectReason)
            Button("Reject", role: .destructive) {
                let why = rejectReason
                Task { await vm.reject(reason: why) }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("It leaves Penny's desk and won't be booked. The person who sent it in sees the reason.")
        }
        .fullScreenCover(item: $pagerTarget) { t in
            DupePhotoPager(urls: t.urls, labels: t.labels, reads: t.reads, start: t.start)
        }
    }

    // MARK: - States

    private var emptyState: some View {
        VStack(spacing: 10) {
            Image(systemName: "checkmark.seal.fill")
                .font(appStyle.font(size: 40))
                .foregroundStyle(appStyle.tenant)
            Text("Nothing to review. Penny is working through the backlog.")
                .font(appStyle.font(.subheadline))
                .multilineTextAlignment(.center)
                .foregroundStyle(appStyle.meta)
            messageLine
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 32)
    }

    private func errorState(_ err: String) -> some View {
        VStack(spacing: 10) {
            Label(err, systemImage: "exclamationmark.triangle.fill")
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.blocked)
                .multilineTextAlignment(.center)
            Button("Try again") { Task { await vm.load() } }
                .buttonStyle(.bordered)
                .tint(appStyle.tenant)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 24)
    }

    // MARK: - Receipt

    private func receiptCard(_ item: BookkeeperItem) -> some View {
        VStack(alignment: .leading, spacing: 14) {
            header(item)
            photo(item.imageURL)

            if let who = item.submittedBy, !who.isEmpty {
                Text("from \(who)")
                    .font(appStyle.font(.footnote))
                    .foregroundStyle(appStyle.meta)
            }

            field("Vendor", key: "vendor") {
                TextField("Who sold it?", text: $vm.vendor)
                    .textFieldStyle(.roundedBorder)
                    .textInputAutocapitalization(.words)
            }
            field("Receipt date", key: nil) {
                DatePicker("Receipt date", selection: $vm.date, in: PennyCardViewModel.dateRange, displayedComponents: .date)
                    .labelsHidden()
            }
            field("Category", key: "accounting_category") {
                Picker("Category", selection: $vm.category) {
                    if vm.category.isEmpty { Text("Choose…").tag("") }
                    ForEach(vm.categoryChoices, id: \.self) { Text($0).tag($0) }
                }
                .pickerStyle(.menu)
                .tint(appStyle.tenant)
            }
            field("For", key: "asset_tag") {
                Picker("For", selection: $vm.assetTag) {
                    ForEach(vm.tagOptions, id: \.value) { Text($0.label).tag($0.value) }
                }
                .pickerStyle(.segmented)
            }
            field("Job", key: "job") {
                Text(vm.jobLabel)
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(vm.jobLabel == "None" ? appStyle.meta : appStyle.text)
            }

            VStack(alignment: .leading, spacing: 8) {
                HStack(alignment: .top, spacing: 10) {
                    amount("Subtotal", key: "subtotal", text: $vm.subtotal)
                    amount("Total", key: "total", text: $vm.total)
                }
                HStack(alignment: .top, spacing: 10) {
                    amount("GST", key: "gst", text: $vm.gst)
                    amount("PST", key: "pst", text: $vm.pst)
                }
            }

            if !item.checks.isEmpty {
                VStack(alignment: .leading, spacing: 4) {
                    ForEach(item.checks) { c in
                        Label(c.message, systemImage: c.ok ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
                            .font(appStyle.font(.caption))
                            .foregroundStyle(c.ok ? appStyle.tenant : appStyle.attention)
                    }
                }
                .padding(10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(appStyle.sunk, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
            }

            if let notes = vm.notes {
                Label(notes, systemImage: "note.text")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
            }

            actions
            messageLine
        }
    }

    private func header(_ item: BookkeeperItem) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: 8) {
            Text("Receipt \(vm.index + 1) of \(vm.queue.count)")
                .font(appStyle.font(.headline))
            badge(item.status == "pending_approval" ? "Submitted" : "Draft",
                  color: item.status == "pending_approval" ? appStyle.tenant : appStyle.meta)
            if item.savedDraft != nil {
                badge("Saved draft", color: appStyle.attention)
            }
            Spacer()
        }
    }

    @ViewBuilder
    private func photo(_ urlString: String?) -> some View {
        if let s = urlString, let url = URL(string: s) {
            Button { zoomTarget = ZoomTarget(url: url) } label: {
                AsyncImage(url: url) { phase in
                    if let img = phase.image {
                        img.resizable().scaledToFit()
                    } else if phase.error != nil {
                        Label("Couldn't load the photo", systemImage: "photo")
                            .font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
                    } else {
                        ProgressView()
                    }
                }
                .frame(maxWidth: .infinity, minHeight: 160, maxHeight: 260)
                .background(appStyle.sunk, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
                .overlay(alignment: .bottomTrailing) {
                    Image(systemName: "arrow.up.left.and.arrow.down.right")
                        .font(appStyle.font(.caption, weight: .bold))
                        .padding(6)
                        .background(.ultraThinMaterial, in: Circle())
                        .padding(8)
                }
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Receipt photo — tap to zoom")
        } else {
            Label("No photo", systemImage: "photo")
                .font(appStyle.font(.footnote))
                .foregroundStyle(appStyle.meta)
                .frame(maxWidth: .infinity, minHeight: 80)
                .background(appStyle.sunk, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        }
    }

    /// A field with Penny's confidence chip beside the label and her reason under it.
    private func field<Content: View>(_ label: String, key: String?, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 5) {
            HStack(spacing: 6) {
                Text(label).font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.meta)
                if let key, let c = vm.confidence(key) { ConfidenceChip(level: c) }
            }
            content()
            if let key, let why = vm.reason(key), !why.isEmpty {
                Text(why).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            }
        }
    }

    private func amount(_ label: String, key: String, text: Binding<String>) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 4) {
                Text(label).font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.meta)
                if let c = vm.confidence(key) { ConfidenceChip(level: c) }
            }
            TextField("0.00", text: text)
                .keyboardType(.decimalPad)
                .textFieldStyle(.roundedBorder)
                .monospacedDigit()
            if let why = vm.reason(key), !why.isEmpty {
                Text(why).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta).lineLimit(3)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private var actions: some View {
        VStack(spacing: 10) {
            Button {
                Task { await vm.approve() }
            } label: {
                HStack {
                    if vm.isBusy { ProgressView().tint(appStyle.onTenant) }
                    Text("Approve").bold()
                }
                .frame(maxWidth: .infinity)
            }
            .buttonStyle(.borderedProminent)
            .tint(appStyle.tenant)
            .controlSize(.large)

            HStack(spacing: 10) {
                Button { Task { await vm.saveDraft() } } label: {
                    Text("Save draft").frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .tint(appStyle.tenant)

                Button(role: .destructive) {
                    rejectReason = ""
                    showReject = true
                } label: {
                    Text("Reject").frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)

                Button { vm.skip() } label: {
                    Text("Skip").frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .tint(appStyle.meta)
                .disabled(vm.queue.count < 2)
            }
        }
        .disabled(vm.isBusy)
    }

    @ViewBuilder
    private var messageLine: some View {
        if let m = vm.message {
            Text(m)
                .font(appStyle.font(.footnote))
                .foregroundStyle(vm.messageIsError ? appStyle.blocked : appStyle.tenant)
                .fixedSize(horizontal: false, vertical: true)
        }
    }

    // MARK: - Customer billing mail (routed to Penny — reminders for Tim)

    @ViewBuilder
    private var mailSection: some View {
        if !vm.messages.isEmpty || vm.mailNote != nil {
            VStack(alignment: .leading, spacing: 10) {
                Text("From customers")
                    .font(appStyle.font(.subheadline, weight: .semibold))
                ForEach(vm.messages) { m in
                    mailRow(m)
                    if m.id != vm.messages.last?.id { Divider() }
                }
                if let n = vm.mailNote {
                    Text(n)
                        .font(appStyle.font(.footnote))
                        .foregroundStyle(vm.mailNoteIsError ? appStyle.blocked : appStyle.tenant)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            .padding(12)
            .background(appStyle.tenant.opacity(0.06), in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        }
    }

    private func mailRow(_ m: PennyMessage) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(m.text)
                .font(appStyle.font(.subheadline, weight: .semibold))
                .fixedSize(horizontal: false, vertical: true)
            if let note = m.note {
                Text(note)
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
                    .fixedSize(horizontal: false, vertical: true)
            }
            ForEach(m.attachments) { a in
                Button {
                    if let u = URL(string: a.url) { openURL(u) }
                } label: {
                    Label(a.filename, systemImage: "doc.richtext")
                        .font(appStyle.font(.caption))
                        .lineLimit(1)
                }
                .buttonStyle(.bordered)
                .tint(appStyle.tenant)
            }
            HStack(spacing: 8) {
                if let to = m.emailTo {
                    Button {
                        UIPasteboard.general.string = to
                        vm.mailNote = "Copied \(to)."
                        vm.mailNoteIsError = false
                    } label: { Label("Copy \(to)", systemImage: "doc.on.doc") }
                        .lineLimit(1)
                }
                Spacer(minLength: 0)
                MoveToMenu(current: "penny", disabled: vm.isBusy) { to in Task { await vm.moveMessage(m, to: to) } }
                Button("Done") { Task { await vm.messageDone(m) } }
                    .buttonStyle(.bordered).tint(appStyle.meta)
                    .disabled(vm.isBusy)
            }
            .font(appStyle.font(.caption))
        }
    }

    private func badge(_ text: String, color: Color) -> some View {
        Text(text)
            .font(appStyle.font(.caption, weight: .semibold))
            .padding(.horizontal, 7).padding(.vertical, 3)
            .background(color.opacity(0.15), in: Capsule())
            .foregroundStyle(color)
    }

    // MARK: - Duplicates (plan 2026-11-05: Penny's verdict and the printed facts first, two big square buttons)

    private func dupeCard(_ group: BKDupeGroup) -> some View {
        let keep = vm.keepId(for: group)
        let copies = vm.copyIds(for: group)
        let swaps = vm.swapOptions(for: group)
        let members = group.members.sorted { $0.id < $1.id }
        let outcome = vm.dupeOutcome(group.id)
        // Fits one phone screen (Tim, 2026-11-06): matching facts fold into one ticked line, the table keeps only
        // what differs or wasn't read, status and sender sit under the photos, the squares are capped.
        let compared = group.facts.filter { $0.state != "info" }
        // The table carries the facts; the headline only says what they add up to. Status and sender sit under the photos.
        let rows = compared   // every compared fact, auth number included (Tim, 2026-11-06: "auth should be in the table")
        let info = Dictionary(uniqueKeysWithValues: group.facts.filter { $0.state == "info" }.map { ($0.label, $0.values) })
        return VStack(alignment: .leading, spacing: 10) {
            if vm.dupeSession.count > 1 {
                dupePager
            } else {
                Text(members.count > 2 ? "Possible duplicates" : "Possible duplicate").font(appStyle.font(.headline))
            }
            // Undo sits up here, under the numbers: at the bottom the tab bar's Kaname button covered it.
            undoOrMessage

            // 1. Penny's verdict, large, and how sure she is with this vendor.
            VStack(alignment: .leading, spacing: 4) {
                Text(Self.headline(group))
                    .font(appStyle.font(.title3, weight: .semibold))
                    .fixedSize(horizontal: false, vertical: true)
                if let c = group.confidence, !c.isEmpty {
                    Text(c).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            .accessibilityElement(children: .combine)

            // The rest hangs on one thin centre line (Tim, 2026-11-06): down the table, between the photos and between
            // the two buttons, on toward the Kaname button below. Two receipts only; three get the table's own lines.
            VStack(alignment: .leading, spacing: 10) {
                // 2. The facts side by side (Tim: "fast to read"), minus what the verdict already says.
                if !rows.isEmpty { factsTable(rows, members: members, keep: keep) }

                // 3. Photos in the buttons' columns, same width (Tim, 2026-11-06); tap for full screen with Penny's read.
                HStack(alignment: .top, spacing: members.count > 2 ? 10 : 16) {
                    ForEach(Array(members.enumerated()), id: \.element.id) { i, m in
                        dupeThumb(m, index: i, members: members, keep: keep,
                                  status: Self.statusAfter(outcome, member: m.id) ?? info["Status"]?[safe: i] ?? nil,
                              sender: info["Sent in by"]?[safe: i] ?? nil,
                                  reads: members.indices.map { Self.pennyRead(compared, at: $0) })
                    }
                }
                .frame(maxWidth: 2 * 150 + 16)
                .frame(maxWidth: .infinity)

                // 4. The swap, small and away from the buttons. (Which one is kept is on its photo and on Merge.)
                VStack(alignment: .leading, spacing: 6) {
                    ForEach(outcome == nil ? swaps : [], id: \.self) { id in
                        Button(swaps.count == 1 ? "Keep the other instead" : "Keep #\(id) instead") { vm.chooseKeep(id, in: group) }
                            .font(appStyle.font(.footnote))
                            .foregroundStyle(appStyle.tenant)
                            .disabled(vm.isBusy)
                    }
                }

                // 5. Open: Merge / Different. Answered: what was decided, and the way back (Unmerge / Undo).
                if let outcome {
                    answeredButtons(outcome, group: group)
                } else {
                    // 5. Two square buttons, side by side, nothing else near them (capped so the card fits one screen).
                    HStack(spacing: 16) {
                        squareButton(icon: "arrow.triangle.merge", title: "Merge",
                                     detail: copies.count > 1 ? "all into #\(keep)" : "into #\(keep)", filled: true) {
                            Task { await vm.merge(group) }
                        }
                        .disabled(copies.isEmpty)
                        .accessibilityHint("Sets #\(copies.map(String.init).joined(separator: ", #")) aside as a copy of #\(keep). You can undo it.")
                        squareButton(icon: "doc.on.doc", title: "Different", detail: members.count > 2 ? "keep them all" : "keep both", filled: false) {
                            Task { await vm.different(group) }
                        }
                        .accessibilityHint("They are separate purchases. Both go on for approval.")
                    }
                    .disabled(vm.isBusy)
                    .frame(maxWidth: 2 * 150 + 16)
                    .frame(maxWidth: .infinity)
                    .padding(.top, 2)
                }

            }
            .padding(8)
            // A thin frame of the same weight around the side-by-side panel (Tim: "surround with a similar weighted line").
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.line, lineWidth: 1).allowsHitTesting(false))
            .background(alignment: .top) {
                if members.count == 2 {
                    Rectangle().fill(appStyle.line)
                        .frame(width: 1)
                        .frame(maxHeight: .infinity)
                        .padding(.bottom, -400)          // runs on past the card toward the Kaname button
                        .frame(maxWidth: .infinity)
                        .accessibilityHidden(true)
                }
            }
        }
    }

    /// Progress through the duplicates, as numbered circles on a horizontal line (Tim, 2026-11-06: "this is Kaname at work"),
    /// each in a Kaname-style ring: answered = filled indigo with a tick, current = indigo ring and number, the rest open.
    /// Tap an open one to go to it.
    private var dupePager: some View {
        let indigo = appStyle.isEnabled ? appStyle.palette.color(.brand) : Color.indigo
        let done = Set(vm.dupeSession.filter { vm.dupeOutcome($0) != nil })
        let current = vm.currentDupe?.id
        let pos = vm.dupePos
        let step: CGFloat = 44
        // Read right to left (Tim, 2026-11-06: "the Japanese reads design easter egg"): the current number sits enlarged
        // on the vertical Kaname line at the centre, the ones still to do wait on its left, answered ones go off to the
        // right. Each step slides the row right, so the next number arrives at the centre.
        return GeometryReader { geo in
            let mid = geo.size.width / 2
            ZStack {
                Rectangle().fill(appStyle.line).frame(height: 1).accessibilityHidden(true)
                ForEach(Array(vm.dupeSession.enumerated()), id: \.element) { i, id in
                    let isDone = done.contains(id)
                    let on = id == current
                    let x = mid - CGFloat(i - pos) * step
                    Button {
                        withAnimation(.spring(response: 0.38, dampingFraction: 0.82)) { vm.showDupe(id: id) }
                    } label: {
                        ZStack {
                            Circle().fill(appStyle.page).frame(width: 30, height: 30)
                            Circle().stroke(on ? indigo : appStyle.line, lineWidth: on ? 1.5 : 1).frame(width: 28, height: 28)
                            Circle().fill(isDone ? indigo : appStyle.page).frame(width: 22, height: 22)
                            if isDone {
                                Image(systemName: "checkmark").font(appStyle.font(size: 11, weight: .heavy)).foregroundStyle(Color.white)
                            } else {
                                Text("\(i + 1)")
                                    .font(appStyle.font(.footnote, weight: .bold))
                                    .foregroundStyle(on ? indigo : appStyle.meta)
                            }
                        }
                        .scaleEffect(on ? 1.4 : 1)
                        .frame(width: step, height: 52)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)                    // answered ones stay tappable: go back, Unmerge / Undo
                    .position(x: x, y: 26)
                    .opacity(x < -step || x > geo.size.width + step ? 0 : 1)
                    .accessibilityLabel("Group \(i + 1) of \(vm.dupeSession.count)" + (isDone ? ", done" : on ? ", showing" : ""))
                }
            }
            .frame(width: geo.size.width, height: 52)
            .clipped()
        }
        .frame(height: 52)
        .animation(.spring(response: 0.38, dampingFraction: 0.82), value: pos)
    }

    /// An answered group: a status square beside the way back, in the same two columns as Merge / Different.
    @ViewBuilder private func answeredButtons(_ outcome: PennyCardViewModel.DupeOutcome, group: BKDupeGroup) -> some View {
        VStack(spacing: 10) {
            HStack(spacing: 16) {
                switch outcome {
                case .merged(let keep, let copies):
                    statusSquare(icon: "arrow.triangle.merge", title: "Merged",
                                 detail: "#" + copies.map(String.init).joined(separator: ", #") + " into #\(keep)")
                    squareButton(icon: "arrow.uturn.backward", title: "Unmerge", detail: "decide again", filled: false) {
                        Task { await vm.reopen(group.id) }
                    }
                    .accessibilityHint("Brings the copy back to waiting and asks about this pair again.")
                case .different:
                    statusSquare(icon: "doc.on.doc", title: "Different", detail: "both kept")
                    squareButton(icon: "arrow.uturn.backward", title: "Undo", detail: "decide again", filled: false) {
                        Task { await vm.reopen(group.id) }
                    }
                case .elsewhere:
                    statusSquare(icon: "checkmark", title: "Settled", detail: "on another screen")
                    Color.clear.frame(maxWidth: .infinity).aspectRatio(1, contentMode: .fit)
                }
            }
            .disabled(vm.isBusy)
            .frame(maxWidth: 2 * 150 + 16)
            .frame(maxWidth: .infinity)
            if vm.dupesAllAnswered {
                Button("All sorted. On to the receipts") { withAnimation { vm.finishDupes() } }
                    .font(appStyle.font(.subheadline, weight: .semibold))
                    .foregroundStyle(appStyle.tenant)
                    .frame(maxWidth: .infinity, minHeight: 44)
            }
        }
    }

    /// The decided state, same size as a button but not one: quiet fill, no border.
    private func statusSquare(icon: String, title: String, detail: String) -> some View {
        let shape = RoundedRectangle(cornerRadius: appStyle.cornerRadius(16))
        return Color.clear
            .frame(maxWidth: .infinity)
            .aspectRatio(1, contentMode: .fit)
            .overlay {
                VStack(spacing: 8) {
                    Image(systemName: icon).font(appStyle.font(size: 30, weight: .semibold)).frame(height: 42)
                    Text(title).font(appStyle.font(.title3, weight: .bold))
                    Text(detail).font(appStyle.font(.footnote)).multilineTextAlignment(.center)
                }
                .lineLimit(2)
                .minimumScaleFactor(0.6)
                .padding(10)
                .foregroundStyle(appStyle.meta)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .background(appStyle.sunk, in: shape)
                .accessibilityElement(children: .combine)
            }
    }

    /// 6. Penny's message line, under the numbers.
    @ViewBuilder private var undoOrMessage: some View {
        messageLine        // Undo lives on the answered card itself now: swipe back, Unmerge (Tim, 2026-11-06)
    }

    /// A square button: a width-filling clear base made square (half the row each, about 160 pt on a 393 pt phone,
    /// 120 at 320 pt), the button laid over it, so both buttons are exactly the same size whatever their text.
    private func squareButton(icon: String, title: String, detail: String, filled: Bool, action: @escaping () -> Void) -> some View {
        let shape = RoundedRectangle(cornerRadius: appStyle.cornerRadius(16))
        return Color.clear
            .frame(maxWidth: .infinity)
            .aspectRatio(1, contentMode: .fit)
            .overlay {
                Button(action: action) {
                    VStack(spacing: 8) {
                        if vm.isBusy { ProgressView().tint(filled ? appStyle.onTenant : appStyle.tenant).frame(height: 42) }
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

    /// The facts side by side. A row that matches shows only the values, with a filled indigo check between them
    /// (Tim, 2026-11-06); a row that differs or wasn't read keeps its label, and a difference is highlighted.
    private func factsTable(_ rows: [BKDupeFactRow], members: [BKDupeMember], keep: Int) -> some View {
        let gap: CGFloat = 46   // the values sit a few points clear of the tick's ring
        let indigo = appStyle.isEnabled ? appStyle.palette.color(.brand) : Color.indigo
        let n = members.count
        return VStack(spacing: 0) {
            HStack(spacing: 0) {
                ForEach(Array(members.enumerated()), id: \.element.id) { i, m in
                    if i > 0 { Color.clear.frame(width: gap, height: 1) }
                    Text("#\(m.id)\(m.id == keep ? " · kept" : "")")
                        .font(appStyle.font(.caption, weight: .bold))
                        .foregroundStyle(m.id == keep ? appStyle.tenant : appStyle.meta)
                        .frame(maxWidth: .infinity, alignment: Self.cellAlignment(i, of: n))
                }
            }
            .padding(.bottom, 4)
            ForEach(rows) { row in
                let match = row.state == "same"
                HStack(alignment: .center, spacing: 0) {
                    ForEach(Array(row.values.enumerated()), id: \.offset) { i, v in
                        if i > 0 {
                            Group {
                                if match {
                                    // Like the Kaname button: the centre line stops short, a page-coloured gap, then a thin
                                    // ring of the same weight around the filled tick.
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
                        }
                        Text(match ? (v ?? "–") : Self.unmatchedCell(row.label, v))
                            .font(appStyle.font(.footnote, weight: row.state == "differs" ? .semibold : .regular))
                            .foregroundStyle(v == nil ? appStyle.meta : appStyle.text)
                            .lineLimit(match ? 1 : 2)          // "Southlands Nursery" shrinks a little, never wraps
                            .minimumScaleFactor(match ? 0.75 : 1)
                            .multilineTextAlignment(Self.textAlignment(i, of: n))
                            .padding(.horizontal, row.state == "differs" && v != nil ? 4 : 0).padding(.vertical, 1)
                            .background(row.state == "differs" && v != nil ? appStyle.attention.opacity(0.18) : Color.clear,
                                        in: RoundedRectangle(cornerRadius: 4))
                            .frame(maxWidth: .infinity, alignment: Self.cellAlignment(i, of: n))
                    }
                }
                .padding(.vertical, 3)
                .accessibilityElement(children: .combine)
                .accessibilityLabel(match ? "\(row.label) matches: \(row.values.compactMap { $0 }.first ?? "")"
                                          : "\(row.label): " + row.values.map { $0 ?? "not read" }.joined(separator: ", "))
                Divider().overlay(appStyle.line)
            }
        }
        // A thin line down each gap, the nav bar's hairline colour, dividing the receipts; the checks sit on it.
        .background {
            if n > 2 { GeometryReader { geo in
                let colW = (geo.size.width - gap * CGFloat(n - 1)) / CGFloat(n)
                ForEach(1..<max(n, 2), id: \.self) { k in
                    Rectangle()
                        .fill(appStyle.line)
                        .frame(width: 1, height: geo.size.height)
                        .position(x: CGFloat(k) * colW + CGFloat(k - 1) * gap + gap / 2, y: geo.size.height / 2)
                }
            }
            .accessibilityHidden(true) }
        }
    }

    /// A cell in a row that doesn't match: its label goes first so "Card –" reads, unless the value already names itself
    /// ("Ticket 43198823", "Auth 433970" — not "Number Ticket 43198823").
    static func unmatchedCell(_ label: String, _ value: String?) -> String {
        guard let v = value else { return "\(label) –" }
        return label == "Number" ? v : "\(label) \(v)"
    }

    /// Two receipts: the left column hugs the check from the left, the right one from the right. Three or more: centred.
    static func cellAlignment(_ i: Int, of n: Int) -> Alignment {
        n == 2 ? (i == 0 ? .trailing : .leading) : .center
    }

    static func textAlignment(_ i: Int, of n: Int) -> TextAlignment {
        n == 2 ? (i == 0 ? .trailing : .leading) : .center
    }

    private func dupeThumb(_ m: BKDupeMember, index: Int, members: [BKDupeMember], keep: Int, status: String?, sender: String?,
                           reads: [String]) -> some View {
        let withPhoto = members.indices.filter { members[$0].receiptPath.flatMap(URL.init(string:)) != nil }
        let urls = withPhoto.compactMap { members[$0].receiptPath.flatMap(URL.init(string:)) }
        let labels = withPhoto.map { "#\(members[$0].id)" }
        let pageReads = withPhoto.map { reads[safe: $0] ?? "" }
        let start = labels.firstIndex(of: "#\(m.id)") ?? 0
        let kept = m.id == keep
        return VStack(spacing: 4) {
            Group {
                if let s = m.receiptPath, let url = URL(string: s) {
                    Button { pagerTarget = PagerTarget(urls: urls, labels: labels, reads: pageReads, start: start) } label: {
                        AsyncImage(url: url) { phase in
                            if let img = phase.image { img.resizable().scaledToFill() }
                            else if phase.error != nil { Image(systemName: "photo").foregroundStyle(appStyle.meta) }
                            else { ProgressView() }
                        }
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .clipped()
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel("Photo of receipt #\(m.id), opens full screen")
                } else {
                    Image(systemName: "photo").foregroundStyle(appStyle.meta).accessibilityLabel("No photo")
                }
            }
            .frame(maxWidth: .infinity)
            .frame(height: 96)
            .background(appStyle.sunk)
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)).stroke(kept ? appStyle.tenant : Color.clear, lineWidth: 2))
            Text(["#\(m.id)", kept && status == "waiting" ? "kept" : nil, status, sender.map(Self.shortName)].compactMap { $0 }.joined(separator: " · "))
                .font(appStyle.font(.caption, weight: kept ? .semibold : .regular))
                .foregroundStyle(kept ? appStyle.tenant : appStyle.meta)
                .lineLimit(2)
                .multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
    }

    /// Penny's headline. A printed-number match is just "Same receipt" — the number itself is in the table.
    static func headline(_ group: BKDupeGroup) -> String {
        if group.evidence == "number" { return "Same receipt" }
        return group.verdict ?? "Same total, within 3 days. Nothing printed proves it either way."
    }

    /// Penny's read of one receipt in one line, to check against the paper in the full-screen photo:
    /// "Shell · Oct 3, 2026 · 12:04 · $20.00 · Auth 433970 · ••7640" (what wasn't read is left out).
    static func pennyRead(_ rows: [BKDupeFactRow], at i: Int) -> String {
        rows.compactMap { $0.values[safe: i] ?? nil }.joined(separator: " · ")
    }

    /// On an answered card the snapshot's status is stale: a merged copy is now set aside.
    static func statusAfter(_ outcome: PennyCardViewModel.DupeOutcome?, member: Int) -> String? {
        if case .merged(_, let copies)? = outcome, copies.contains(member) { return "set aside" }
        return nil
    }

    /// "TIM SCHOFIELD" → "Tim S." — first name and initial, so it fits under a photo.
    static func shortName(_ name: String) -> String {
        let parts = name.split(separator: " ").map { $0.prefix(1).uppercased() + $0.dropFirst().lowercased() }
        guard let first = parts.first else { return name }
        return parts.count > 1 ? "\(first) \(parts.last!.prefix(1))." : first
    }
}

// MARK: - Confidence chip

private struct ConfidenceChip: View {
    @Environment(\.itAppStyle) private var appStyle
    let level: String

    var body: some View {
        Text(level)
            .font(appStyle.font(size: 12, weight: .semibold))
            .padding(.horizontal, 6).padding(.vertical, 1)
            .background(color.opacity(0.15), in: Capsule())
            .foregroundStyle(color)
    }

    private var color: Color {
        switch level.lowercased() {
        case "high": return appStyle.tenant
        case "medium": return appStyle.attention
        default: return appStyle.blocked
        }
    }
}

/// The duplicate photos open full screen, swipe between them.
private struct PagerTarget: Identifiable {
    let urls: [URL]
    let labels: [String]
    let reads: [String]
    let start: Int
    var id: String { urls.map(\.absoluteString).joined(separator: "|") + "#\(start)" }
}

/// Full-screen pager over a duplicate group's photos: each page is the receipt zoom view (pinch, drag,
/// double-tap); swipe sideways to the other receipt to compare the same spot.
private struct DupePhotoPager: View {
    let urls: [URL]
    let labels: [String]
    let reads: [String]
    @State var selection: Int

    init(urls: [URL], labels: [String], reads: [String] = [], start: Int) {
        self.urls = urls
        self.labels = labels
        self.reads = reads
        _selection = State(initialValue: min(max(0, start), max(0, urls.count - 1)))
    }

    var body: some View {
        TabView(selection: $selection) {
            ForEach(Array(urls.enumerated()), id: \.offset) { i, url in
                ZoomableReceiptView(url: url)
                    .overlay(alignment: .topLeading) {
                        Text("\(labels[safe: i] ?? "") · \(i + 1) of \(urls.count)")
                            .font(.headline)
                            .foregroundStyle(.white)
                            .padding(.horizontal, 10).padding(.vertical, 6)
                            .background(.black.opacity(0.55), in: Capsule())
                            .padding()
                    }
                    // Penny's read of this receipt, to check against the print before tapping Merge.
                    .overlay(alignment: .bottom) {
                        if let r = reads[safe: i], !r.isEmpty {
                            VStack(alignment: .leading, spacing: 2) {
                                Text("Penny read").font(.caption.weight(.semibold)).foregroundStyle(.white.opacity(0.75))
                                Text(r).font(.subheadline.weight(.semibold)).foregroundStyle(.white)
                                    .fixedSize(horizontal: false, vertical: true)
                            }
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(12)
                            .background(.black.opacity(0.7), in: RoundedRectangle(cornerRadius: 12))
                            .padding(.horizontal, 16).padding(.bottom, 44)
                            .allowsHitTesting(false)
                        }
                    }
                    .tag(i)
            }
        }
        .tabViewStyle(.page(indexDisplayMode: urls.count > 1 ? .always : .never))
        .background(Color.black.ignoresSafeArea())
        .ignoresSafeArea()
    }
}

private extension Array {
    subscript(safe i: Int) -> Element? { indices.contains(i) ? self[i] : nil }
}

/// The photo open in the full-screen viewer.
private struct ZoomTarget: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}

#if DEBUG
/// `-mwDupeDemo two|three|first|done` (add `-mwTimelineField` for field mode): Penny's duplicate card with invented
/// receipts — no sign-in, no client data. Merge answers as the server does (undo available).
struct PennyDupeDemo: View {
    let variant: String

    @MainActor private final class DemoAPI: BookkeeperDeskAPI {
        let variant: String
        var answered = 0          // groups merged / said different: the server drops them from the list
        init(variant: String) { self.variant = variant }
        func loadQueue() async throws -> BookkeeperQueueResponse {
            let shell = #"{"pairs":[[126,254]],"members":[{"id":126,"expense_date":"2026-10-03","total":20,"status":"draft","vendor_name":"Shell","submitted_by":"Trevor"},{"id":254,"expense_date":"2026-10-03","total":20,"status":"approved","vendor_name":"Shell","submitted_by":"Tim"}],"verdict":"Same receipt: auth 433970 is on both.","evidence":"number","confidence":"With Shell I've been right 14 of 14 times.","keep_id":254,"keep_reason":"already in the books","copy_ids":[126],"can_swap":[],"facts":[{"label":"Vendor","values":["Southlands Nursery","Southlands Nursery"],"state":"same"},{"label":"Date","values":["Oct 3, 2026","Oct 3, 2026"],"state":"same"},{"label":"Time","values":["12:04","12:04"],"state":"same"},{"label":"Total","values":["$20.00","$20.00"],"state":"same"},{"label":"Number","values":["Auth 433970","Auth 433970"],"state":"same"},{"label":"Card","values":["••7640",null],"state":"missing"},{"label":"Status","values":["waiting","in the books"],"state":"info"},{"label":"Sent in by","values":["Trevor","Tim"],"state":"info"}]}"#
            let champ = #"{"pairs":[[486,488],[488,490]],"members":[{"id":486,"expense_date":"2026-10-22","total":147.84,"status":"pending_approval","vendor_name":"Champion Lawn Mower Sales"},{"id":488,"expense_date":"2026-10-22","total":147.84,"status":"pending_approval","vendor_name":"Champion"},{"id":490,"expense_date":"2026-10-23","total":147.84,"status":"draft","vendor_name":"Champion"}],"verdict":"Same total, dates a few days apart. Nothing printed proves it either way.","evidence":"total_date","confidence":"","keep_id":486,"keep_reason":"sent in first","copy_ids":[488,490],"can_swap":[488,490],"facts":[{"label":"Vendor","values":["Champion Lawn Mower Sales","Champion","Champion"],"state":"differs"},{"label":"Date","values":["Oct 22, 2026","Oct 22, 2026","Oct 23, 2026"],"state":"differs"},{"label":"Time","values":["09:24",null,null],"state":"missing"},{"label":"Total","values":["$147.84","$147.84","$147.84"],"state":"same"},{"label":"Status","values":["waiting","waiting","waiting"],"state":"info"}]}"#
            let groups = variant == "done" ? "" : variant == "three" ? champ : variant == "first" ? champ + "," + shell
                : variant == "nine" ? ([shell] + (1...8).map { k in champ.replacingOccurrences(of: "48", with: "4\(k)") }).dropFirst(answered).joined(separator: ",")
                : shell + "," + champ
            let json = #"{"ok":true,"queue":[],"categories":[],"asset_tags":[],"dupes":["# + groups + "]}"
            return try JSONDecoder().decode(BookkeeperQueueResponse.self, from: Data(json.utf8))
        }
        func send(_ body: [String: Any]) async throws -> BookkeeperActionResponse {
            let mode = body["mode"] as? String ?? ""
            if mode == "merge" || mode == "not_dupe" { answered += 1 }
            if mode == "unmerge" || mode == "undismiss" { answered = max(0, answered - 1) }
            let json = mode == "merge" ? #"{"ok":true,"undo":true,"message":"Kept #254 — the copy is set aside."}"#
                     : #"{"ok":true,"message":"Got it — not a duplicate. Both go on for approval."}"#
            return try JSONDecoder().decode(BookkeeperActionResponse.self, from: Data(json.utf8))
        }
    }

    @StateObject private var vm: PennyCardViewModel

    init(variant: String) {
        self.variant = variant
        _vm = StateObject(wrappedValue: PennyCardViewModel(api: DemoAPI(variant: variant)))
    }

    var body: some View {
        // `-mwDupePager <image path>`: the full-screen pager on that image, with Penny's read over it.
        let args = ProcessInfo.processInfo.arguments
        if let i = args.firstIndex(of: "-mwDupePager"), i + 1 < args.count {
            let url = URL(fileURLWithPath: args[i + 1])
            DupePhotoPager(urls: [url, url], labels: ["#126", "#254"],
                           reads: ["Shell · Oct 3, 2026 · 12:04 · $20.00 · Auth 433970 · ••7640", "Shell · Oct 3, 2026 · 12:04 · $20.00 · Auth 433970"], start: 0)
        } else {
            demoCard
        }
    }

    private var demoCard: some View {
        NavigationStack {
            ScrollView { PennyCardView(vm: vm).padding(16) }
                .navigationTitle("Penny")
                .itAppScreen()
        }
        .task {
            await vm.load()
            // `-mwDupeAnswered N` (+ `-mwDupeBack`): merge the first N groups at launch, then go back to the first,
            // to see the filled numbers and an answered card.
            let args = ProcessInfo.processInfo.arguments
            if let i = args.firstIndex(of: "-mwDupeAnswered"), i + 1 < args.count, let n = Int(args[i + 1]) {
                for _ in 0..<n { if let g = vm.currentDupe { await vm.merge(g) } }
                if args.contains("-mwDupeBack"), let first = vm.dupeSession.first { vm.showDupe(id: first) }
            }
        }
    }
}
#endif
