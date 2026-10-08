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

    @ObservedObject var vm: PennyCardViewModel

    @State private var zoomTarget: ZoomTarget?
    @State private var showReject = false
    @State private var rejectReason = ""
    @State private var keepTarget: KeepTarget?

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
        .alert(keepTarget?.title ?? "", isPresented: Binding(
            get: { keepTarget != nil },
            set: { if !$0 { keepTarget = nil } }
        ), presenting: keepTarget) { target in
            Button(target.removeCount == 1 ? "Remove the copy" : "Remove \(target.removeCount) copies", role: .destructive) {
                Task { await vm.keep(target.member, in: target.group) }
            }
            Button("Cancel", role: .cancel) {}
        } message: { _ in
            Text("They're set aside as duplicates — kept on record, not deleted. Anything the one you keep is missing (job, category, notes, line items, photo) is taken from them.")
        }
    }

    // MARK: - States

    private var emptyState: some View {
        VStack(spacing: 10) {
            Image(systemName: "checkmark.seal.fill")
                .font(.system(size: 40))
                .foregroundStyle(Color.MW.green)
            Text("Nothing to review. Penny is working through the backlog.")
                .font(.subheadline)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
            messageLine
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 32)
    }

    private func errorState(_ err: String) -> some View {
        VStack(spacing: 10) {
            Label(err, systemImage: "exclamationmark.triangle.fill")
                .font(.subheadline)
                .foregroundStyle(.red)
                .multilineTextAlignment(.center)
            Button("Try again") { Task { await vm.load() } }
                .buttonStyle(.bordered)
                .tint(Color.MW.green)
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
                    .font(.footnote)
                    .foregroundStyle(.secondary)
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
                .tint(Color.MW.green)
            }
            field("For", key: "asset_tag") {
                Picker("For", selection: $vm.assetTag) {
                    ForEach(vm.tagOptions, id: \.value) { Text($0.label).tag($0.value) }
                }
                .pickerStyle(.segmented)
            }
            field("Job", key: "job") {
                Text(vm.jobLabel)
                    .font(.subheadline)
                    .foregroundStyle(vm.jobLabel == "None" ? .secondary : .primary)
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
                            .font(.caption)
                            .foregroundStyle(c.ok ? Color.MW.green : Color.MW.orange)
                    }
                }
                .padding(10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color(.secondarySystemBackground), in: RoundedRectangle(cornerRadius: 10))
            }

            if let notes = vm.notes {
                Label(notes, systemImage: "note.text")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            actions
            messageLine
        }
    }

    private func header(_ item: BookkeeperItem) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: 8) {
            Text("Receipt \(vm.index + 1) of \(vm.queue.count)")
                .font(.headline)
            badge(item.status == "pending_approval" ? "Submitted" : "Draft",
                  color: item.status == "pending_approval" ? Color.MW.green : .gray)
            if item.savedDraft != nil {
                badge("Saved draft", color: Color.MW.orange)
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
                            .font(.footnote).foregroundStyle(.secondary)
                    } else {
                        ProgressView()
                    }
                }
                .frame(maxWidth: .infinity, minHeight: 160, maxHeight: 260)
                .background(Color(.secondarySystemBackground), in: RoundedRectangle(cornerRadius: 12))
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .overlay(alignment: .bottomTrailing) {
                    Image(systemName: "arrow.up.left.and.arrow.down.right")
                        .font(.caption.bold())
                        .padding(6)
                        .background(.ultraThinMaterial, in: Circle())
                        .padding(8)
                }
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Receipt photo — tap to zoom")
        } else {
            Label("No photo", systemImage: "photo")
                .font(.footnote)
                .foregroundStyle(.secondary)
                .frame(maxWidth: .infinity, minHeight: 80)
                .background(Color(.secondarySystemBackground), in: RoundedRectangle(cornerRadius: 12))
        }
    }

    /// A field with Penny's confidence chip beside the label and her reason under it.
    private func field<Content: View>(_ label: String, key: String?, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 5) {
            HStack(spacing: 6) {
                Text(label).font(.caption.weight(.semibold)).foregroundStyle(.secondary)
                if let key, let c = vm.confidence(key) { ConfidenceChip(level: c) }
            }
            content()
            if let key, let why = vm.reason(key), !why.isEmpty {
                Text(why).font(.caption2).foregroundStyle(.secondary)
            }
        }
    }

    private func amount(_ label: String, key: String, text: Binding<String>) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 4) {
                Text(label).font(.caption.weight(.semibold)).foregroundStyle(.secondary)
                if let c = vm.confidence(key) { ConfidenceChip(level: c) }
            }
            TextField("0.00", text: text)
                .keyboardType(.decimalPad)
                .textFieldStyle(.roundedBorder)
                .monospacedDigit()
            if let why = vm.reason(key), !why.isEmpty {
                Text(why).font(.caption2).foregroundStyle(.secondary).lineLimit(3)
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
                    if vm.isBusy { ProgressView().tint(.white) }
                    Text("Approve").bold()
                }
                .frame(maxWidth: .infinity)
            }
            .buttonStyle(.borderedProminent)
            .tint(Color.MW.green)
            .controlSize(.large)

            HStack(spacing: 10) {
                Button { Task { await vm.saveDraft() } } label: {
                    Text("Save draft").frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .tint(Color.MW.green)

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
                .tint(.secondary)
                .disabled(vm.queue.count < 2)
            }
        }
        .disabled(vm.isBusy)
    }

    @ViewBuilder
    private var messageLine: some View {
        if let m = vm.message {
            Text(m)
                .font(.footnote)
                .foregroundStyle(vm.messageIsError ? .red : Color.MW.green)
                .fixedSize(horizontal: false, vertical: true)
        }
    }

    // MARK: - Customer billing mail (routed to Penny — reminders for Tim)

    @ViewBuilder
    private var mailSection: some View {
        if !vm.messages.isEmpty || vm.mailNote != nil {
            VStack(alignment: .leading, spacing: 10) {
                Text("From customers")
                    .font(.subheadline.weight(.semibold))
                ForEach(vm.messages) { m in
                    mailRow(m)
                    if m.id != vm.messages.last?.id { Divider() }
                }
                if let n = vm.mailNote {
                    Text(n)
                        .font(.footnote)
                        .foregroundStyle(vm.mailNoteIsError ? .red : Color.MW.green)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            .padding(12)
            .background(Color.MW.green.opacity(0.06), in: RoundedRectangle(cornerRadius: 12))
        }
    }

    private func mailRow(_ m: PennyMessage) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(m.text)
                .font(.subheadline.weight(.semibold))
                .fixedSize(horizontal: false, vertical: true)
            if let note = m.note {
                Text(note)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .fixedSize(horizontal: false, vertical: true)
            }
            ForEach(m.attachments) { a in
                Button {
                    if let u = URL(string: a.url) { openURL(u) }
                } label: {
                    Label(a.filename, systemImage: "doc.richtext")
                        .font(.caption)
                        .lineLimit(1)
                }
                .buttonStyle(.bordered)
                .tint(Color.MW.green)
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
                    .buttonStyle(.bordered).tint(.secondary)
                    .disabled(vm.isBusy)
            }
            .font(.caption)
        }
    }

    private func badge(_ text: String, color: Color) -> some View {
        Text(text)
            .font(.caption2.weight(.semibold))
            .padding(.horizontal, 7).padding(.vertical, 3)
            .background(color.opacity(0.15), in: Capsule())
            .foregroundStyle(color)
    }

    // MARK: - Duplicates

    private func dupeCard(_ group: BKDupeGroup) -> some View {
        let n = group.members.count
        return VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text(n > 2 ? "Possible duplicates" : "Possible duplicate").font(.headline)
                if vm.dupes.count > 1 {
                    badge("group 1 of \(vm.dupes.count)", color: .gray)
                }
                Spacer()
            }
            Text("\(n == 2 ? "These two look" : "These \(n) look") like the same purchase: same total, within 3 days. Sorted before anything is approved.")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            ScrollView(.horizontal, showsIndicators: false) {
                HStack(alignment: .top, spacing: 10) {
                    ForEach(group.members) { m in dupeMember(m, in: group) }
                }
            }

            Button {
                Task { await vm.notDuplicates(group) }
            } label: {
                HStack {
                    if vm.isBusy { ProgressView() }
                    Text(n == 2 ? "Not duplicates — approve both" : "None of these are duplicates")
                }
                .frame(maxWidth: .infinity)
            }
            .buttonStyle(.borderedProminent)
            .tint(Color.MW.green)
            .disabled(vm.isBusy)

            Text("Same receipt? Tap \"Keep this one\" under the copy to keep — the others are set aside, not deleted.")
                .font(.caption)
                .foregroundStyle(.secondary)
            messageLine
        }
    }

    private func dupeMember(_ m: BKDupeMember, in group: BKDupeGroup) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Group {
                if let s = m.receiptPath, let url = URL(string: s) {
                    Button { zoomTarget = ZoomTarget(url: url) } label: {
                        AsyncImage(url: url) { phase in
                            if let img = phase.image { img.resizable().scaledToFill() }
                            else if phase.error != nil { Image(systemName: "photo").foregroundStyle(.secondary) }
                            else { ProgressView() }
                        }
                    }
                    .buttonStyle(.plain)
                } else {
                    Label("No photo", systemImage: "photo").font(.caption).foregroundStyle(.secondary)
                }
            }
            .frame(width: 150, height: 170)
            .background(Color(.secondarySystemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 10))

            Text(m.displayVendor).font(.subheadline.bold()).lineLimit(1)
            Text("\(m.expenseDate ?? "") · \(m.total.map { String(format: "$%.2f", $0) } ?? "—")")
                .font(.caption)
            Text("#\(m.id) · \((m.status ?? "").replacingOccurrences(of: "_", with: " "))\(m.submittedBy.map { " · from \($0)" } ?? "")")
                .font(.caption2)
                .foregroundStyle(.secondary)
                .lineLimit(2)
            if !m.isWaiting {
                Text(m.status == "forwarded" ? "Already sent to accounting" : "Already approved")
                    .font(.caption2)
                    .foregroundStyle(Color.MW.green)
            }
            if PennyCardViewModel.canKeep(m, in: group) {
                Button {
                    keepTarget = KeepTarget(member: m, group: group)
                } label: {
                    Label("Keep this one", systemImage: "checkmark.circle")
                        .font(.caption.weight(.semibold))
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .tint(Color.MW.green)
                .controlSize(.small)
                .disabled(vm.isBusy)
            }
        }
        .frame(width: 150, alignment: .leading)
    }
}

// MARK: - Confidence chip

private struct ConfidenceChip: View {
    let level: String

    var body: some View {
        Text(level)
            .font(.system(size: 10, weight: .semibold))
            .padding(.horizontal, 6).padding(.vertical, 1)
            .background(color.opacity(0.15), in: Capsule())
            .foregroundStyle(color)
    }

    private var color: Color {
        switch level.lowercased() {
        case "high": return Color.MW.green
        case "medium": return Color.MW.orange
        default: return .red
        }
    }
}

/// "Keep this one" waiting for its confirmation.
private struct KeepTarget {
    let member: BKDupeMember
    let group: BKDupeGroup

    var removeCount: Int { PennyCardViewModel.copiesToRemove(keeping: member.id, in: group).count }
    var title: String {
        "Remove \(removeCount == 1 ? "1 copy" : "\(removeCount) copies"), keep #\(member.id)?"
    }
}

/// The photo open in the full-screen viewer.
private struct ZoomTarget: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}
