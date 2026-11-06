//
//  PennyCardView.swift
//  MowologyCRM
//
//  Penny's receipt card for the admin, one receipt at a time: the photo, her read of
//  every field (with how sure she is and why), and Approve · Save draft · Reject · Skip.
//  Possible duplicates come first, as on the web card — never offered for approval.
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
            } else if let err = vm.loadError, vm.queue.isEmpty && vm.dupes.isEmpty {
                errorState(err)
            } else if let group = vm.dupes.first {
                dupeCard(group)
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
            DupePhotoPager(urls: t.urls, labels: t.labels, start: t.start)
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
        return VStack(alignment: .leading, spacing: 14) {
            HStack {
                Text(members.count > 2 ? "Possible duplicates" : "Possible duplicate").font(appStyle.font(.headline))
                if vm.dupes.count > 1 { badge("1 of \(vm.dupes.count)", color: appStyle.meta) }
                Spacer()
            }

            // 1. Penny's verdict, large, and how sure she is with this vendor.
            VStack(alignment: .leading, spacing: 4) {
                Text(group.verdict ?? "Same total, within 3 days. Nothing printed proves it either way.")
                    .font(appStyle.font(.title3, weight: .semibold))
                    .fixedSize(horizontal: false, vertical: true)
                if let c = group.confidence {
                    Text(c).font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            .accessibilityElement(children: .combine)

            // 2. The facts side by side.
            if !group.facts.isEmpty { factsTable(group.facts, members: members, keep: keep) }

            // 3. Photos — tap for full screen, swipe between them.
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 10) {
                    ForEach(Array(members.enumerated()), id: \.element.id) { i, m in dupeThumb(m, index: i, members: members, keep: keep) }
                }
            }

            // 4. Which one is kept, in words; the swap well away from the buttons.
            VStack(alignment: .leading, spacing: 6) {
                Text(vm.keepLine(for: group))
                    .font(appStyle.font(.subheadline, weight: .semibold))
                ForEach(swaps, id: \.self) { id in
                    Button(swaps.count == 1 ? "Keep the other instead" : "Keep #\(id) instead") { vm.chooseKeep(id, in: group) }
                        .font(appStyle.font(.footnote))
                        .foregroundStyle(appStyle.tenant)
                        .disabled(vm.isBusy)
                }
            }

            // 5. Two square buttons, side by side, nothing else near them.
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
            .padding(.top, 4)

            undoOrMessage
        }
    }

    /// 6. "Merged into #N · Undo" for about 6 seconds after a merge, else the usual message line.
    @ViewBuilder private var undoOrMessage: some View {
        if let m = vm.lastMerge {
            HStack {
                Text("Merged into #\(m.keepId)").font(appStyle.font(.subheadline, weight: .semibold))
                Spacer()
                Button("Undo") { Task { await vm.undoLastMerge() } }
                    .font(appStyle.font(.subheadline, weight: .bold))
                    .foregroundStyle(appStyle.tenant)
                    .frame(minWidth: 64, minHeight: 44)
            }
            .padding(.horizontal, 12)
            .background(appStyle.sunk, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
        } else {
            messageLine
        }
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

    private func factsTable(_ rows: [BKDupeFactRow], members: [BKDupeMember], keep: Int) -> some View {
        let labelW: CGFloat = 78
        let colW: CGFloat = members.count > 2 ? 108 : 124
        return ScrollView(.horizontal, showsIndicators: false) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    Text("").frame(width: labelW, alignment: .leading)
                    ForEach(members) { m in
                        Text("#\(m.id)\(m.id == keep ? " · kept" : "")")
                            .font(appStyle.font(.caption, weight: .bold))
                            .foregroundStyle(m.id == keep ? appStyle.tenant : appStyle.meta)
                            .frame(width: colW, alignment: .leading)
                    }
                }
                .padding(.bottom, 4)
                ForEach(rows) { row in
                    HStack(alignment: .top, spacing: 0) {
                        HStack(spacing: 3) {
                            Text(row.label).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                            if row.state == "same" {
                                Image(systemName: "checkmark").font(appStyle.font(size: 10, weight: .bold)).foregroundStyle(appStyle.tenant)
                                    .accessibilityLabel("matches")
                            }
                        }
                        .frame(width: labelW, alignment: .leading)
                        ForEach(Array(row.values.enumerated()), id: \.offset) { _, v in
                            Text(v ?? "–")
                                .font(appStyle.font(.footnote, weight: row.state == "differs" ? .semibold : .regular))
                                .foregroundStyle(v == nil ? appStyle.meta : appStyle.text)
                                .lineLimit(2)
                                .padding(.horizontal, 4).padding(.vertical, 2)
                                .frame(width: colW - 6, alignment: .leading)
                                .background(row.state == "differs" && v != nil ? appStyle.attention.opacity(0.18) : Color.clear,
                                            in: RoundedRectangle(cornerRadius: 4))
                                .frame(width: colW, alignment: .leading)
                        }
                    }
                    .padding(.vertical, 4)
                    .accessibilityElement(children: .combine)
                    .accessibilityValue(row.state == "differs" ? "differs" : row.state == "same" ? "matches" : "")
                    Divider().overlay(appStyle.line)
                }
            }
        }
    }

    private func dupeThumb(_ m: BKDupeMember, index: Int, members: [BKDupeMember], keep: Int) -> some View {
        let urls = members.compactMap { $0.receiptPath.flatMap(URL.init(string:)) }
        let labels = members.filter { $0.receiptPath.flatMap(URL.init(string:)) != nil }.map { "#\($0.id)" }
        let start = labels.firstIndex(of: "#\(m.id)") ?? 0
        return VStack(alignment: .leading, spacing: 4) {
            Group {
                if let s = m.receiptPath, let url = URL(string: s) {
                    Button { pagerTarget = PagerTarget(urls: urls, labels: labels, start: start) } label: {
                        AsyncImage(url: url) { phase in
                            if let img = phase.image { img.resizable().scaledToFill() }
                            else if phase.error != nil { Image(systemName: "photo").foregroundStyle(appStyle.meta) }
                            else { ProgressView() }
                        }
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel("Photo of receipt #\(m.id), opens full screen")
                } else {
                    Label("No photo", systemImage: "photo").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                }
            }
            .frame(width: 110, height: 130)
            .background(appStyle.sunk)
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)).stroke(m.id == keep ? appStyle.tenant : Color.clear, lineWidth: 2))
            Text("#\(m.id)").font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(m.id == keep ? appStyle.tenant : appStyle.meta)
        }
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
    let start: Int
    var id: String { urls.map(\.absoluteString).joined(separator: "|") + "#\(start)" }
}

/// Full-screen pager over a duplicate group's photos: each page is the receipt zoom view (pinch, drag,
/// double-tap); swipe sideways to the other receipt to compare the same spot.
private struct DupePhotoPager: View {
    let urls: [URL]
    let labels: [String]
    @State var selection: Int

    init(urls: [URL], labels: [String], start: Int) {
        self.urls = urls
        self.labels = labels
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

    private struct DemoAPI: BookkeeperDeskAPI {
        let variant: String
        func loadQueue() async throws -> BookkeeperQueueResponse {
            let shell = #"{"pairs":[[126,254]],"members":[{"id":126,"expense_date":"2026-10-03","total":20,"status":"draft","vendor_name":"Shell","submitted_by":"Trevor"},{"id":254,"expense_date":"2026-10-03","total":20,"status":"approved","vendor_name":"Shell","submitted_by":"Tim"}],"verdict":"Same receipt: auth 433970 is on both.","evidence":"number","confidence":"With Shell I've been right 14 of 14 times.","keep_id":254,"keep_reason":"already in the books","copy_ids":[126],"can_swap":[],"facts":[{"label":"Vendor","values":["Shell","Shell"],"state":"same"},{"label":"Date","values":["Oct 3, 2026","Oct 3, 2026"],"state":"same"},{"label":"Time","values":["12:04","12:04"],"state":"same"},{"label":"Total","values":["$20.00","$20.00"],"state":"same"},{"label":"Number","values":["Auth 433970","Auth 433970"],"state":"same"},{"label":"Card","values":["••7640",null],"state":"missing"},{"label":"Status","values":["waiting","in the books"],"state":"info"},{"label":"Sent in by","values":["Trevor","Tim"],"state":"info"}]}"#
            let champ = #"{"pairs":[[486,488],[488,490]],"members":[{"id":486,"expense_date":"2026-10-22","total":147.84,"status":"pending_approval","vendor_name":"Champion Lawn Mower Sales"},{"id":488,"expense_date":"2026-10-22","total":147.84,"status":"pending_approval","vendor_name":"Champion"},{"id":490,"expense_date":"2026-10-23","total":147.84,"status":"draft","vendor_name":"Champion"}],"verdict":"Same total and date. Nothing printed proves it either way.","evidence":"total_date","confidence":"First Champion pair I've seen. Check the photos.","keep_id":486,"keep_reason":"sent in first","copy_ids":[488,490],"can_swap":[488,490],"facts":[{"label":"Vendor","values":["Champion Lawn Mower Sales","Champion","Champion"],"state":"differs"},{"label":"Date","values":["Oct 22, 2026","Oct 22, 2026","Oct 23, 2026"],"state":"differs"},{"label":"Time","values":["09:24",null,null],"state":"missing"},{"label":"Total","values":["$147.84","$147.84","$147.84"],"state":"same"},{"label":"Status","values":["waiting","waiting","waiting"],"state":"info"}]}"#
            let groups = variant == "done" ? "" : variant == "three" ? champ : variant == "first" ? champ + "," + shell : shell + "," + champ
            let json = #"{"ok":true,"queue":[],"categories":[],"asset_tags":[],"dupes":["# + groups + "]}"
            return try JSONDecoder().decode(BookkeeperQueueResponse.self, from: Data(json.utf8))
        }
        func send(_ body: [String: Any]) async throws -> BookkeeperActionResponse {
            let mode = body["mode"] as? String ?? ""
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
        NavigationStack {
            ScrollView { PennyCardView(vm: vm).padding(16) }
                .navigationTitle("Penny")
                .itAppScreen()
        }
        .task { await vm.load() }
    }
}
#endif
