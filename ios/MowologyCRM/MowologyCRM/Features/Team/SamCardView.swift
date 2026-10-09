//
//  SamCardView.swift
//  MowologyCRM
//
//  Sam's sales card for the admin, top to bottom: the numbers, replies waiting (a clear
//  yes first), the follow-up carousel one customer at a time with Sam's editable email or
//  text, new leads and Sam's questions. Nothing goes out without Tim's tap.
//

import SwiftUI

struct SamCardView: View {

    @ObservedObject var vm: SamCardViewModel
    @Environment(\.openURL) private var openURL

    @State private var threadTarget: SamThreadTarget?

    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            if vm.isLoading && !vm.hasLoaded {
                HStack { Spacer(); ProgressView("Loading Sam's list…"); Spacer() }
                    .padding(.vertical, 40)
            } else if let err = vm.loadError, vm.queue.isEmpty && vm.replies.isEmpty {
                errorState(err)
            } else if vm.hasLoaded {
                statsStrip
                repliesSection
                followupSection
                leadsSection
                questionsSection
            }
        }
        .sheet(item: $threadTarget) { t in
            SamThreadSheet(vm: vm, target: t)
        }
    }

    // MARK: - States

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

    private func sectionHead(_ title: String, _ sub: String? = nil) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title).font(.headline)
            if let sub { Text(sub).font(.caption).foregroundStyle(.secondary) }
        }
    }

    private func note(_ text: String?, error: Bool) -> some View {
        Group {
            if let text, !text.isEmpty {
                Text(text)
                    .font(.footnote)
                    .foregroundStyle(error ? .red : Color.MW.green)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
    }

    // MARK: - Stats

    private var statsStrip: some View {
        HStack(spacing: 10) {
            statTile(SamCardViewModel.money(vm.stats.waitingAmount), "waiting",
                     "\(vm.stats.waitingQuotes) quote\(vm.stats.waitingQuotes == 1 ? "" : "s")")
            statTile(vm.stats.winRate.map { "\($0)%" } ?? "—", "win rate", "last 12 months")
            statTile(vm.stats.avgDays.map { String(format: $0 < 10 ? "%.1f" : "%.0f", $0) } ?? "—", "days to a yes", "on average")
        }
    }

    private func statTile(_ value: String, _ label: String, _ sub: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(value).font(.title3.bold()).foregroundStyle(Color.MW.dark).minimumScaleFactor(0.7).lineLimit(1)
            Text(label).font(.caption.weight(.semibold))
            Text(sub).font(.system(size: 10)).foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(10)
        .background(Color.MW.light, in: RoundedRectangle(cornerRadius: 10))
    }

    // MARK: - Replies waiting

    @ViewBuilder
    private var repliesSection: some View {
        if !vm.replies.isEmpty || vm.replyMessage != nil {
            VStack(alignment: .leading, spacing: 10) {
                sectionHead("Replies waiting", "about a quote, and nobody has answered yet")
                note(vm.replyMessage, error: vm.replyIsError)
                ForEach(vm.sortedReplies) { r in
                    replyRow(r)
                    if r.id != vm.sortedReplies.last?.id { Divider() }
                }
            }
        }
    }

    private func replyRow(_ r: SalesReply) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Button {
                threadTarget = SamThreadTarget(contactId: r.contactId, title: r.name)
            } label: {
                VStack(alignment: .leading, spacing: 3) {
                    HStack(spacing: 6) {
                        if r.yes {
                            Text("YES")
                                .font(.caption2.weight(.heavy))
                                .padding(.horizontal, 7).padding(.vertical, 2)
                                .background(Color.MW.green, in: Capsule())
                                .foregroundStyle(.white)
                        }
                        Text(r.name).font(.subheadline.bold())
                        Text(r.channel == "sms" ? "texted" : "replied").font(.subheadline).foregroundStyle(.secondary)
                        Spacer(minLength: 0)
                        Image(systemName: "chevron.right").font(.caption).foregroundStyle(.tertiary)
                    }
                    if !r.quote.isEmpty {
                        Text("\u{201C}\(r.quote)\u{201D}").font(.subheadline).foregroundStyle(.primary).lineLimit(2)
                    }
                    Text([r.channel != "sms" ? r.subject : "", SamCardViewModel.ago(r.at)].filter { !$0.isEmpty }.joined(separator: " · "))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)

            if let draft = vm.replyDrafts[r.key] {
                replyComposer(r, draft)
            } else {
                HStack(spacing: 8) {
                    Button("Draft reply") { Task { await vm.draftReply(r) } }
                        .buttonStyle(.borderedProminent).tint(Color.MW.green)
                    Button("Handled") { Task { await vm.handled(r) } }
                        .buttonStyle(.bordered).tint(.secondary)
                    Button("Open") { if let u = SamCardViewModel.webURL(r.url) { openURL(u) } }
                        .buttonStyle(.bordered).tint(Color.MW.green)
                    MoveToMenu(current: "sam", disabled: vm.isBusy) { to in Task { await vm.moveReply(r, to: to) } }
                }
                .font(.subheadline)
                .disabled(vm.isBusy)
            }
        }
    }

    private func replyComposer(_ r: SalesReply, _ d: SamReplyDraft) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            TextField("Subject", text: Binding(
                get: { vm.replyDrafts[r.key]?.subject ?? d.subject },
                set: { vm.replyDrafts[r.key]?.subject = $0 }))
                .textFieldStyle(.roundedBorder)
            TextEditor(text: Binding(
                get: { vm.replyDrafts[r.key]?.body ?? d.body },
                set: { vm.replyDrafts[r.key]?.body = $0 }))
                .frame(minHeight: 150)
                .padding(4)
                .overlay(RoundedRectangle(cornerRadius: 8).stroke(Color(.systemGray4)))
            Text("Drafted by Claude — check it. Goes from office@.")
                .font(.caption).foregroundStyle(.secondary)
            HStack(spacing: 8) {
                Button("Send email") { Task { await vm.sendReply(r) } }
                    .buttonStyle(.borderedProminent).tint(Color.MW.green)
                Button("Cancel") { vm.cancelReplyDraft(r) }
                    .buttonStyle(.bordered).tint(.secondary)
            }
            .font(.subheadline)
            .disabled(vm.isBusy)
        }
    }

    // MARK: - Follow-ups

    private var followupSection: some View {
        VStack(alignment: .leading, spacing: 10) {
            if let c = vm.current {
                followupCard(c)
            } else {
                sectionHead("Follow-ups")
                note(vm.followupMessage, error: vm.followupIsError)
                Text("Nobody is waiting on a follow-up\(vm.name.isEmpty ? "" : ", \(vm.name)"). I'll bring the next one when it's due.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
    }

    private func followupCard(_ c: SalesCard) -> some View {
        let e = vm.edit(for: c)
        let isSms = e.channel == "sms"
        let probs = isSms ? vm.smsProblems(for: c) : []
        return VStack(alignment: .leading, spacing: 10) {
            HStack {
                Button { vm.move(-1) } label: { Image(systemName: "chevron.left") }
                    .disabled(vm.queue.count < 2)
                Spacer()
                VStack(spacing: 1) {
                    Text("\(c.replied ? "Waiting on you" : "Follow-up") \(vm.index + 1) of \(vm.queue.count)")
                        .font(.headline)
                    Text("\(SamCardViewModel.money(vm.queueTotal)) across the list")
                        .font(.caption).foregroundStyle(.secondary)
                }
                Spacer()
                Button { vm.move(1) } label: { Image(systemName: "chevron.right") }
                    .disabled(vm.queue.count < 2)
            }
            .tint(Color.MW.green)

            HStack(alignment: .firstTextBaseline) {
                Text(c.name).font(.subheadline.bold())
                if !c.company.isEmpty { Text(c.company).font(.caption).foregroundStyle(.secondary) }
                Spacer()
                Text(SamCardViewModel.money(c.amount)).font(.subheadline.bold())
            }

            whyNow(c)

            VStack(alignment: .leading, spacing: 4) {
                ForEach(c.quotes) { q in
                    HStack(alignment: .firstTextBaseline) {
                        VStack(alignment: .leading, spacing: 1) {
                            Text("\(q.number) · \(q.service.isEmpty ? (q.title.isEmpty ? "Quote" : q.title) : q.service.replacingOccurrences(of: "_", with: " "))")
                                .font(.caption.weight(.semibold))
                            Text(quoteMeta(q)).font(.caption2).foregroundStyle(.secondary).lineLimit(2)
                        }
                        Spacer()
                        Text(SamCardViewModel.money(q.amount)).font(.caption.weight(.semibold))
                    }
                    .padding(.vertical, 3)
                    .padding(.leading, quoteColour(q) == nil ? 0 : 8)
                    .background {
                        if let col = quoteColour(q) {
                            HStack(spacing: 0) {
                                col.frame(width: 4)
                                col.opacity(0.08)
                            }
                            .clipShape(RoundedRectangle(cornerRadius: 4))
                        }
                    }
                }
            }
            .padding(10)
            .background(Color(.secondarySystemBackground), in: RoundedRectangle(cornerRadius: 10))

            if !c.thread.isEmpty, let cid = c.contactId {
                Button {
                    threadTarget = SamThreadTarget(contactId: cid, title: c.name)
                } label: {
                    Label("\(c.thread.count == 1 ? "1 message" : "\(c.thread.count) recent messages") with \(c.firstName.isEmpty ? "them" : c.firstName)",
                          systemImage: "bubble.left.and.bubble.right")
                        .font(.caption)
                }
                .tint(Color.MW.green)
            }

            if c.smsOK {
                Picker("Send as", selection: Binding(get: { e.channel }, set: { vm.setChannel($0) })) {
                    Text("Email").tag("email")
                    Text("Text").tag("sms")
                }
                .pickerStyle(.segmented)
            }

            if isSms {
                TextEditor(text: Binding(get: { vm.edit(for: c).sms }, set: { v in vm.update(c) { $0.sms = v } }))
                    .frame(minHeight: 80)
                    .padding(4)
                    .overlay(RoundedRectangle(cornerRadius: 8).stroke(probs.isEmpty ? Color(.systemGray4) : .red))
                Text("\(e.sms.count)/\(SalesSMS.max)" + (probs.isEmpty ? " · OK for carriers" : " · " + probs.joined(separator: " · ")))
                    .font(.caption)
                    .foregroundStyle(probs.isEmpty ? Color.secondary : Color.red)
            } else {
                TextField("Subject", text: Binding(get: { vm.edit(for: c).subject }, set: { v in vm.update(c) { $0.subject = v } }))
                    .textFieldStyle(.roundedBorder)
                TextEditor(text: Binding(get: { vm.edit(for: c).body }, set: { v in vm.update(c) { $0.body = v } }))
                    .frame(minHeight: 190)
                    .padding(4)
                    .overlay(RoundedRectangle(cornerRadius: 8).stroke(Color(.systemGray4)))
                HStack(spacing: 6) {
                    if e.draftedBy == "learned" { Text("Written your way").font(.caption.weight(.semibold)).foregroundStyle(Color.MW.green) }
                    if e.draftedBy == "claude" { Text("Drafted by Claude — check it").font(.caption.weight(.semibold)).foregroundStyle(Color.MW.orange) }
                    Text(c.email.isEmpty ? "No email address on file." : "The quote link goes under your message.")
                        .font(.caption).foregroundStyle(.secondary)
                }
            }

            note(vm.followupMessage, error: vm.followupIsError)

            VStack(spacing: 8) {
                HStack(spacing: 8) {
                    Button(isSms ? "Send text" : "Send email") { Task { await vm.sendFollowup() } }
                        .buttonStyle(.borderedProminent).tint(Color.MW.green)
                        .disabled(isSms ? !probs.isEmpty : c.email.isEmpty)
                    if c.replied && !isSms {
                        Button("Draft reply") { Task { await vm.draftCardReply() } }
                            .buttonStyle(.bordered).tint(Color.MW.green)
                    }
                    Spacer(minLength: 0)
                }
                HStack(spacing: 8) {
                    Button("Not now") { Task { await vm.notNow() } }
                        .buttonStyle(.bordered).tint(.secondary)
                    Button("Skip") { vm.skip() }
                        .buttonStyle(.bordered).tint(.secondary)
                        .disabled(vm.queue.count < 2)
                    Spacer(minLength: 0)
                    if c.replied {
                        MoveToMenu(current: "sam", disabled: vm.isBusy) { to in Task { await vm.moveCardReply(to: to) } }
                    }
                }
            }
            .font(.subheadline)
            .disabled(vm.isBusy)
        }
    }

    private func whyNow(_ c: SalesCard) -> some View {
        let who = c.firstName.isEmpty ? c.name : c.firstName
        let text: String
        if c.replied {
            text = "\(who) wrote back \(SamCardViewModel.ago(c.lastIn)) and hasn't heard from us since."
        } else {
            var t = "No reply for \(c.days) days"
            if c.followups > 0 { t += " · \(c.followups) follow-up\(c.followups == 1 ? "" : "s") so far" }
            if c.viewed { t += " · they opened it" }
            text = t + "."
        }
        return Text(text)
            .font(.footnote)
            .padding(8)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background((c.replied ? Color.MW.orange : Color.MW.green).opacity(0.1), in: RoundedRectangle(cornerRadius: 8))
    }

    /// Orange opened, green accepted, red declined; nil = sent and not seen yet.
    private func quoteColour(_ q: SalesQuote) -> Color? {
        switch q.state {
        case "accepted": return Color.MW.green
        case "declined": return Color.MW.red
        case "opened":   return Color.MW.orange
        default:         return nil
        }
    }

    private func quoteMeta(_ q: SalesQuote) -> String {
        var parts: [String] = []
        if !q.address.isEmpty { parts.append(q.address) }
        var sent = "sent \(SamCardViewModel.shortDate(q.sentAt))"
        if let v = q.validUntil, !v.isEmpty { sent += ", good until \(SamCardViewModel.shortDate(v))" }
        if q.views > 0 { sent += ", viewed \(q.views)×" }
        parts.append(sent)
        return parts.joined(separator: " · ")
    }

    // MARK: - Leads

    @ViewBuilder
    private var leadsSection: some View {
        if !vm.leads.isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                sectionHead("New leads", "best first — likely value, urgency and age")
                ForEach(vm.leads.prefix(5)) { l in
                    HStack(alignment: .top, spacing: 10) {
                        VStack(alignment: .leading, spacing: 2) {
                            HStack(spacing: 6) {
                                if l.hot { Image(systemName: "flame.fill").font(.caption).foregroundStyle(Color.MW.orange) }
                                Text(l.name).font(.subheadline.bold())
                                if l.value > 0 { Text("~" + SamCardViewModel.money(l.value)).font(.caption.weight(.semibold)).foregroundStyle(Color.MW.green) }
                            }
                            Text(l.services + (l.address.isEmpty ? "" : " · " + l.address))
                                .font(.caption).foregroundStyle(.secondary).lineLimit(2)
                            Text(l.next).font(.caption).foregroundStyle(.primary)
                        }
                        Spacer(minLength: 0)
                        Button("Open") { if let u = SamCardViewModel.webURL(l.url) { openURL(u) } }
                            .buttonStyle(.bordered).tint(Color.MW.green).font(.caption)
                    }
                }
            }
        }
    }

    // MARK: - Questions

    @ViewBuilder
    private var questionsSection: some View {
        if !vm.questions.isEmpty || vm.questionMessage != nil {
            VStack(alignment: .leading, spacing: 10) {
                sectionHead(vm.questions.isEmpty ? "Sam's questions" : "Sam has \(vm.questions.count) question\(vm.questions.count == 1 ? "" : "s") for you")
                note(vm.questionMessage, error: false)
                ForEach(vm.questions.prefix(3)) { q in
                    VStack(alignment: .leading, spacing: 8) {
                        Text(q.question).font(.subheadline)
                        VStack(alignment: .leading, spacing: 6) {
                            answerButton(q, "lost", "Lost — stop chasing")
                            answerButton(q, "keep", "Still alive — keep 30 days")
                            answerButton(q, "won", "They said yes")
                        }
                    }
                    .padding(10)
                    .background(Color(.secondarySystemBackground), in: RoundedRectangle(cornerRadius: 10))
                }
            }
        }
    }

    private func answerButton(_ q: SalesQuestion, _ a: String, _ label: String) -> some View {
        Button(label) {
            Task {
                if let url = await vm.answer(q, a) { openURL(url) }
            }
        }
        .buttonStyle(.bordered)
        .tint(a == "won" ? Color.MW.green : .secondary)
        .font(.subheadline)
        .disabled(vm.isBusy)
    }
}

// MARK: - Thread sheet

struct SamThreadTarget: Identifiable, Equatable {
    let contactId: Int
    let title: String
    var id: Int { contactId }
}

private struct SamThreadSheet: View {
    @ObservedObject var vm: SamCardViewModel
    let target: SamThreadTarget
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 10) {
                    if vm.threadLoading {
                        ProgressView().padding(.top, 40)
                    } else if let err = vm.threadError {
                        Text(err).foregroundStyle(.red).padding(.top, 40)
                    } else if vm.threadMessages.isEmpty {
                        Text("No emails or texts with \(target.title) yet.").foregroundStyle(.secondary).padding(.top, 40)
                    } else {
                        ForEach(vm.threadMessages) { m in SamBubble(message: m, them: target.title) }
                    }
                }
                .padding(16)
            }
            .navigationTitle(target.title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }.foregroundStyle(Color.MW.green)
                }
            }
            .task { await vm.openThread(contactId: target.contactId, title: target.title) }
        }
    }
}

@MainActor
struct SamBubble: View {
    let message: SalesMessage
    let them: String

    var body: some View {
        HStack {
            if !message.inbound { Spacer(minLength: 40) }
            VStack(alignment: message.inbound ? .leading : .trailing, spacing: 3) {
                Text(meta).font(.caption2).foregroundStyle(.secondary)
                Text(message.snippet.isEmpty ? "(no text)" : message.snippet)
                    .font(.subheadline)
                    .padding(10)
                    .background(message.inbound ? Color(.secondarySystemBackground) : Color.MW.light,
                                in: RoundedRectangle(cornerRadius: 14))
            }
            if message.inbound { Spacer(minLength: 40) }
        }
    }

    private var meta: String {
        var p = [message.inbound ? them : "Us"]
        if message.channel == "sms" { p.append("text") }
        p.append(SamCardViewModel.ago(message.at))
        if message.channel != "sms" && !message.subject.isEmpty { p.append(message.subject) }
        return p.joined(separator: " · ")
    }
}
