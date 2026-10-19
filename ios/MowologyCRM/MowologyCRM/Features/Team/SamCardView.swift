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
    @Environment(\.itAppStyle) private var appStyle

    @ObservedObject var vm: SamCardViewModel
    @Environment(\.openURL) private var openURL

    @State private var threadTarget: SamThreadTarget?
    @State private var confirmQuote: SalesDraftQuote?

    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            if vm.isLoading && !vm.hasLoaded {
                HStack { Spacer(); ProgressView("Loading Sam's list…"); Spacer() }
                    .padding(.vertical, 40)
            } else if let err = vm.loadError, vm.queue.isEmpty && vm.replies.isEmpty {
                errorState(err)
            } else if vm.hasLoaded {
                statsStrip
                acceptanceSection
                toSendSection
                approvedSection
                repliesSection
                followupSection
                leadsSection
                questionsSection
            }
        }
        .sheet(item: $threadTarget) { t in
            SamThreadSheet(vm: vm, target: t)
        }
        .confirmationDialog("Send this quote?", isPresented: Binding(get: { confirmQuote != nil }, set: { if !$0 { confirmQuote = nil } }),
                            titleVisibility: .visible, presenting: confirmQuote) { q in
            Button("Send \(q.number) to \(q.client.isEmpty ? "the client" : q.client)") { Task { await vm.sendQuote(q) } }
            Button("Cancel", role: .cancel) {}
        } message: { q in
            Text((q.email.map { "Email to \($0) with the PDF and their link. " } ?? "") + String(format: "Total $%.2f incl. GST.", q.amount))
        }
    }

    // MARK: - States

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

    private func sectionHead(_ title: String, _ sub: String? = nil) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title).font(appStyle.font(.headline))
            if let sub { Text(sub).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta) }
        }
    }

    private func note(_ text: String?, error: Bool) -> some View {
        Group {
            if let text, !text.isEmpty {
                Text(text)
                    .font(appStyle.font(.footnote))
                    .foregroundStyle(error ? appStyle.blocked : appStyle.tenant)
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
            Text(value).font(appStyle.font(.title3, weight: .bold)).foregroundStyle(appStyle.color(.tenant, legacy: Color.MW.dark)).minimumScaleFactor(0.7).lineLimit(1)
            Text(label).font(appStyle.font(.caption, weight: .semibold))
            Text(sub).font(appStyle.font(size: 12)).foregroundStyle(appStyle.meta)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(10)
        .background(appStyle.color(.tenantSoft, legacy: Color.MW.light), in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
    }

    // MARK: - Looks like an acceptance

    /// A client wrote back with a yes (signed copies, "approved"): each matched quote, its signed PDF, Accept / Not this one.
    @ViewBuilder
    private var acceptanceSection: some View {
        if !vm.acceptances.isEmpty || vm.acceptanceMessage != nil {
            VStack(alignment: .leading, spacing: 10) {
                sectionHead("Looks like an acceptance", "a client wrote back with a yes — check each one")
                note(vm.acceptanceMessage, error: vm.acceptanceIsError)
                ForEach(vm.acceptances) { g in
                    VStack(alignment: .leading, spacing: 8) {
                        Text("\(g.from) · “\(g.subject)”").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                            .fixedSize(horizontal: false, vertical: true)
                        ForEach(g.quotes) { q in acceptanceRow(q) }
                    }
                    if g.id != vm.acceptances.last?.id { Divider() }
                }
            }
        }
    }

    private func acceptanceRow(_ q: SalesAcceptance.Quote) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(alignment: .firstTextBaseline) {
                Text(q.number).font(appStyle.font(.subheadline, weight: .bold))
                Spacer(minLength: 4)
                Text(String(format: "$%.2f", q.amount)).font(appStyle.font(.subheadline).monospacedDigit())
            }
            Text([q.title, q.address].filter { !$0.isEmpty }.joined(separator: " · "))
                .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta).fixedSize(horizontal: false, vertical: true)
            HStack(spacing: 10) {
                if let s = q.fileURL, let url = URL(string: s) {
                    Button { openURL(url) } label: { Label(q.fileName ?? "Signed copy", systemImage: "doc.richtext").lineLimit(1) }
                        .font(appStyle.font(.caption))
                }
                Spacer()
                Button("Not this one") { Task { await vm.decideAcceptance(q, accept: false) } }
                    .buttonStyle(.bordered).disabled(vm.isBusy)
                if q.stillOpen {
                    Button("Accept") { Task { await vm.decideAcceptance(q, accept: true) } }
                        .buttonStyle(.borderedProminent).tint(appStyle.tenant).disabled(vm.isBusy)
                }
            }
        }
    }

    // MARK: - Quotes to send

    /// Draft quotes waiting to go out (2026-10-15). Send = the web page's Send to Customer, after a confirm.
    @ViewBuilder
    private var toSendSection: some View {
        if !vm.toSend.isEmpty || vm.toSendMessage != nil {
            VStack(alignment: .leading, spacing: 10) {
                sectionHead("Quotes to send", "drafts waiting for your tap")
                note(vm.toSendMessage, error: vm.toSendIsError)
                ForEach(vm.toSend) { q in
                    draftQuoteRow(q)
                    if q.id != vm.toSend.last?.id { Divider() }
                }
            }
        }
    }

    private func draftQuoteRow(_ q: SalesDraftQuote) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("\(q.number) · \(q.title.isEmpty ? "Quote" : q.title)").font(appStyle.font(.subheadline, weight: .bold))
                .fixedSize(horizontal: false, vertical: true)
            Text([q.client, q.address].filter { !$0.isEmpty }.joined(separator: " · "))
                .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            if let e = q.email { Text(e).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta) }
            else { Text("No email on file — add one in the CRM first").font(appStyle.font(.caption)).foregroundStyle(appStyle.blocked) }
            ForEach(q.items, id: \.self) { it in
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Text(it.name + (it.optional ? " (optional)" : "")).font(appStyle.font(.subheadline))
                    Spacer(minLength: 4)
                    Text(String(format: "$%.2f", it.total)).font(appStyle.font(.subheadline).monospacedDigit())
                }
            }
            HStack {
                Text(String(format: "GST $%.2f · Total $%.2f", q.gst, q.amount)).font(appStyle.font(.caption).monospacedDigit()).foregroundStyle(appStyle.meta)
                Spacer()
            }
            HStack(spacing: 10) {
                if let url = SamCardViewModel.webURL("/crm/quotes/view.php?id=\(q.id)") {
                    Button("Open in CRM") { openURL(url) }.buttonStyle(.bordered)
                }
                Spacer()
                Button("Send…") { confirmQuote = q }
                    .buttonStyle(.borderedProminent)
                    .tint(appStyle.tenant)
                    .disabled(vm.isBusy || q.email == nil)
            }
        }
    }

    // MARK: - Just approved

    /// Every line of an approved quote, ✓ or ✗ — a line the client left out is named, never
    /// silently missing (owner, 2026-10-10).
    @ViewBuilder
    private var approvedSection: some View {
        if !vm.approved.isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                sectionHead("Just approved", "what the client said yes to, line by line")
                note(vm.approvedMessage, error: true)
                ForEach(vm.approved) { a in
                    approvedRow(a)
                    if a.id != vm.approved.last?.id { Divider() }
                }
            }
        }
    }

    private func approvedRow(_ a: SalesApproval) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(a.headline).font(appStyle.font(.subheadline, weight: .bold)).fixedSize(horizontal: false, vertical: true)
            ForEach(a.lines, id: \.self) { l in
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Image(systemName: l.approved ? "checkmark.circle.fill" : "xmark.circle.fill")
                        .foregroundStyle(l.approved ? appStyle.tenant : appStyle.blocked)
                    Text(l.label).font(appStyle.font(.subheadline))
                        .foregroundStyle(l.approved ? appStyle.text : appStyle.blocked)
                    Spacer(minLength: 4)
                    Text(String(format: "$%.2f", l.amount)).font(appStyle.font(.subheadline).monospacedDigit())
                        .foregroundStyle(l.approved ? appStyle.text : appStyle.blocked)
                }
                if !l.approved {
                    Text("Not included").font(appStyle.font(.caption)).foregroundStyle(appStyle.blocked).padding(.leading, 26)
                }
            }
            if !a.approver.isEmpty {
                Text("Approved by \(a.approver)" + (a.via.isEmpty || a.via == "approved" ? "" : " (\(a.via))"))
                    .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            }
            // Otto's line — the schedule is his job, so his face says what he set up.
            if !a.scheduleText.isEmpty {
                HStack(alignment: .center, spacing: 8) {
                    AsyncImage(url: TeamHead.all.first(where: { $0.slug == "otto" })?.faceURL) { phase in
                        if let img = phase.image { img.resizable().scaledToFill() } else { appStyle.color(.tenantSoft, legacy: Color.MW.light) }
                    }
                    .frame(width: 28, height: 28)
                    .clipShape(Circle())
                    .overlay(Circle().strokeBorder(Color.MW.lime, lineWidth: 2))
                    Text(a.scheduleText).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                        .fixedSize(horizontal: false, vertical: true)
                }
            } else if let pn = a.planNumber, a.planId != nil {
                Text("Job \(pn) is in the Unscheduled tray").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            } else if let n = a.jobNote, !n.isEmpty {
                Text(n).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            }
            HStack {
                Spacer()
                Button("Got it") { Task { await vm.approvalSeen(a) } }
                    .buttonStyle(.bordered)
                    .disabled(vm.isBusy)
            }
        }
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
                                .font(appStyle.font(.caption, weight: .heavy))
                                .padding(.horizontal, 7).padding(.vertical, 2)
                                .background(appStyle.tenant, in: Capsule())
                                .foregroundStyle(appStyle.onTenant)
                        }
                        Text(r.name).font(appStyle.font(.subheadline, weight: .bold))
                        Text(r.channel == "sms" ? "texted" : "replied").font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
                        Spacer(minLength: 0)
                        Image(systemName: "chevron.right").font(appStyle.font(.caption)).foregroundStyle(.tertiary)
                    }
                    if !r.quote.isEmpty {
                        Text("\u{201C}\(r.quote)\u{201D}").font(appStyle.font(.subheadline)).foregroundStyle(appStyle.text).lineLimit(2)
                    }
                    Text([r.channel != "sms" ? r.subject : "", SamCardViewModel.ago(r.at)].filter { !$0.isEmpty }.joined(separator: " · "))
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
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
                        .buttonStyle(.borderedProminent).tint(appStyle.tenant)
                    Button("Handled") { Task { await vm.handled(r) } }
                        .buttonStyle(.bordered).tint(appStyle.meta)
                    Button("Open") { if let u = SamCardViewModel.webURL(r.url) { openURL(u) } }
                        .buttonStyle(.bordered).tint(appStyle.tenant)
                    MoveToMenu(current: "sam", disabled: vm.isBusy) { to in Task { await vm.moveReply(r, to: to) } }
                }
                .font(appStyle.font(.subheadline))
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
                .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)).stroke(appStyle.line))
            Text("Drafted by Claude — check it. Goes from office@.")
                .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            HStack(spacing: 8) {
                Button("Send email") { Task { await vm.sendReply(r) } }
                    .buttonStyle(.borderedProminent).tint(appStyle.tenant)
                Button("Cancel") { vm.cancelReplyDraft(r) }
                    .buttonStyle(.bordered).tint(appStyle.meta)
            }
            .font(appStyle.font(.subheadline))
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
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
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
                        .font(appStyle.font(.headline))
                    Text("\(SamCardViewModel.money(vm.queueTotal)) across the list")
                        .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                }
                Spacer()
                Button { vm.move(1) } label: { Image(systemName: "chevron.right") }
                    .disabled(vm.queue.count < 2)
            }
            .tint(appStyle.tenant)

            HStack(alignment: .firstTextBaseline) {
                Text(c.name).font(appStyle.font(.subheadline, weight: .bold))
                if !c.company.isEmpty { Text(c.company).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta) }
                Spacer()
                Text(SamCardViewModel.money(c.amount)).font(appStyle.font(.subheadline, weight: .bold))
            }

            whyNow(c)

            VStack(alignment: .leading, spacing: 4) {
                ForEach(c.quotes) { q in
                    HStack(alignment: .firstTextBaseline) {
                        VStack(alignment: .leading, spacing: 1) {
                            Text("\(q.number) · \(q.service.isEmpty ? (q.title.isEmpty ? "Quote" : q.title) : q.service.replacingOccurrences(of: "_", with: " "))")
                                .font(appStyle.font(.caption, weight: .semibold))
                            Text(quoteMeta(q)).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta).lineLimit(2)
                        }
                        Spacer()
                        Text(SamCardViewModel.money(q.amount)).font(appStyle.font(.caption, weight: .semibold))
                    }
                    .padding(.vertical, 3)
                    .padding(.leading, quoteColour(q) == nil ? 0 : 8)
                    .background {
                        if let col = quoteColour(q) {
                            HStack(spacing: 0) {
                                col.frame(width: 4)
                                col.opacity(0.08)
                            }
                            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(4)))
                        }
                    }
                }
            }
            .padding(10)
            .background(appStyle.sunk, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))

            if !c.thread.isEmpty, let cid = c.contactId {
                Button {
                    threadTarget = SamThreadTarget(contactId: cid, title: c.name)
                } label: {
                    Label("\(c.thread.count == 1 ? "1 message" : "\(c.thread.count) recent messages") with \(c.firstName.isEmpty ? "them" : c.firstName)",
                          systemImage: "bubble.left.and.bubble.right")
                        .font(appStyle.font(.caption))
                }
                .tint(appStyle.tenant)
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
                    .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)).stroke(probs.isEmpty ? appStyle.line : appStyle.blocked))
                Text("\(e.sms.count)/\(SalesSMS.max)" + (probs.isEmpty ? " · OK for carriers" : " · " + probs.joined(separator: " · ")))
                    .font(appStyle.font(.caption))
                    .foregroundStyle(probs.isEmpty ? appStyle.meta : appStyle.blocked)
            } else {
                TextField("Subject", text: Binding(get: { vm.edit(for: c).subject }, set: { v in vm.update(c) { $0.subject = v } }))
                    .textFieldStyle(.roundedBorder)
                TextEditor(text: Binding(get: { vm.edit(for: c).body }, set: { v in vm.update(c) { $0.body = v } }))
                    .frame(minHeight: 190)
                    .padding(4)
                    .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)).stroke(appStyle.line))
                HStack(spacing: 6) {
                    if e.draftedBy == "learned" { Text("Written your way").font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.tenant) }
                    if e.draftedBy == "claude" { Text("Drafted by Claude — check it").font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.attention) }
                    Text(c.email.isEmpty ? "No email address on file." : "The quote link goes under your message.")
                        .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                }
            }

            note(vm.followupMessage, error: vm.followupIsError)

            VStack(spacing: 8) {
                HStack(spacing: 8) {
                    Button(isSms ? "Send text" : "Send email") { Task { await vm.sendFollowup() } }
                        .buttonStyle(.borderedProminent).tint(appStyle.tenant)
                        .disabled(isSms ? !probs.isEmpty : c.email.isEmpty)
                    if c.replied && !isSms {
                        Button("Draft reply") { Task { await vm.draftCardReply() } }
                            .buttonStyle(.bordered).tint(appStyle.tenant)
                    }
                    Spacer(minLength: 0)
                }
                HStack(spacing: 8) {
                    Button("Not now") { Task { await vm.notNow() } }
                        .buttonStyle(.bordered).tint(appStyle.meta)
                    Button("Skip") { vm.skip() }
                        .buttonStyle(.bordered).tint(appStyle.meta)
                        .disabled(vm.queue.count < 2)
                    Spacer(minLength: 0)
                    if c.replied {
                        MoveToMenu(current: "sam", disabled: vm.isBusy) { to in Task { await vm.moveCardReply(to: to) } }
                    }
                }
            }
            .font(appStyle.font(.subheadline))
            .disabled(vm.isBusy)
        }
    }

    private func whyNow(_ c: SalesCard) -> some View {
        let who = c.firstName.isEmpty ? c.name : c.firstName
        let text: String
        if c.replied {
            if let by = c.lastInBy, !by.isEmpty {
                text = "\(by) wrote back \(SamCardViewModel.ago(c.lastIn)) about \(who)'s quote and hasn't heard from us since."
            } else {
                text = "\(who) wrote back \(SamCardViewModel.ago(c.lastIn)) and hasn't heard from us since."
            }
        } else {
            var t = "No reply for \(c.days) days"
            if c.followups > 0 { t += " · \(c.followups) follow-up\(c.followups == 1 ? "" : "s") so far" }
            if c.viewed { t += " · they opened it" }
            text = t + "."
        }
        return Text(text)
            .font(appStyle.font(.footnote))
            .padding(8)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background((c.replied ? appStyle.attention : appStyle.tenant).opacity(0.1), in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
    }

    /// Orange opened, green accepted, red declined; nil = sent and not seen yet.
    private func quoteColour(_ q: SalesQuote) -> Color? {
        switch q.state {
        case "accepted": return appStyle.tenant
        case "declined": return appStyle.blocked
        case "opened":   return appStyle.attention
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
                                if l.hot { Image(systemName: "flame.fill").font(appStyle.font(.caption)).foregroundStyle(appStyle.attention) }
                                Text(l.name).font(appStyle.font(.subheadline, weight: .bold))
                                if l.value > 0 { Text("~" + SamCardViewModel.money(l.value)).font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.tenant) }
                            }
                            Text(l.services + (l.address.isEmpty ? "" : " · " + l.address))
                                .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta).lineLimit(2)
                            Text(l.next).font(appStyle.font(.caption)).foregroundStyle(appStyle.text)
                        }
                        Spacer(minLength: 0)
                        Button("Open") { if let u = SamCardViewModel.webURL(l.url) { openURL(u) } }
                            .buttonStyle(.bordered).tint(appStyle.tenant).font(appStyle.font(.caption))
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
                        Text(q.question).font(appStyle.font(.subheadline))
                        VStack(alignment: .leading, spacing: 6) {
                            answerButton(q, "lost", "Lost — stop chasing")
                            answerButton(q, "keep", "Still alive — keep 30 days")
                            answerButton(q, "won", "They said yes")
                        }
                    }
                    .padding(10)
                    .background(appStyle.sunk, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
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
        .tint(a == "won" ? appStyle.tenant : appStyle.meta)
        .font(appStyle.font(.subheadline))
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
    @Environment(\.itAppStyle) private var appStyle
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
                        Text(err).foregroundStyle(appStyle.blocked).padding(.top, 40)
                    } else if vm.threadMessages.isEmpty {
                        Text("No emails or texts with \(target.title) yet.").foregroundStyle(appStyle.meta).padding(.top, 40)
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
                    Button("Close") { dismiss() }.foregroundStyle(appStyle.tenant)
                }
            }
            .task { await vm.openThread(contactId: target.contactId, title: target.title) }
        }
    }
}

@MainActor
struct SamBubble: View {
    @Environment(\.itAppStyle) private var appStyle
    let message: SalesMessage
    let them: String

    var body: some View {
        HStack {
            if !message.inbound { Spacer(minLength: 40) }
            VStack(alignment: message.inbound ? .leading : .trailing, spacing: 3) {
                Text(meta).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                Text(message.snippet.isEmpty ? "(no text)" : message.snippet)
                    .font(appStyle.font(.subheadline))
                    .padding(10)
                    .background(message.inbound ? appStyle.sunk : appStyle.color(.tenantSoft, legacy: Color.MW.light),
                                in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
            }
            if message.inbound { Spacer(minLength: 40) }
        }
    }

    private var meta: String {
        var p = [message.inbound ? (message.by ?? them) : "Us"]
        if message.channel == "sms" { p.append("text") }
        p.append(SamCardViewModel.ago(message.at))
        if message.channel != "sms" && !message.subject.isEmpty { p.append(message.subject) }
        return p.joined(separator: " · ")
    }
}
