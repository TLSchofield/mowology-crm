//
//  YuiJobsView.swift
//  MowologyCRM
//
//  Yui's decision carousel inside her Team card (shown only while she has jobs). One card per decision, swiped
//  sideways with "1 of N" (the PennyCrewChaseCard pattern). Each card is two panels on a thin spine: what they
//  wrote on the left, what Yui will do on the right; the filled action sits left, the outline action right.
//    statement  recipient picker (each "On file", or the words "Not on file for this account"), subject, note
//    who        first and last name, account picker + "Another account…", then a 6-second Undo
//    reply      to, subject, note, "Draft with Claude"; Send stays off while the note is empty
//  A refusal shows its words on the card; nothing disappears until the server says ok.
//

import SwiftUI

/// Yui's "Decisions" node on her spine: absent while there is nothing to decide.
struct YuiJobsNode: View {
    @ObservedObject var model: YuiJobsViewModel

    var body: some View {
        if model.hasJobs || model.undoOffer != nil {
            SpineNode(slug: "yui", id: "decisions", title: "Decisions", symbol: "bubble.left.and.bubble.right.fill",
                      waiting: HeadSpineLogic.count(model.jobs.count, "to decide"), defaultOpen: true) {
                YuiJobsCarousel(model: model)
            }
        }
    }
}

struct YuiJobsCarousel: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var model: YuiJobsViewModel

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if let offer = model.undoOffer { undoBar(offer) }
            if !model.jobs.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    // Not lazy: the row takes the tallest card's height and does not jump while swiping.
                    HStack(alignment: .top, spacing: 12) {
                        ForEach(Array(model.jobs.enumerated()), id: \.element.id) { index, job in
                            YuiJobCard(model: model, job: job)
                                .accessibilityLabel("Decision \(index + 1) of \(model.jobs.count)")
                                .containerRelativeFrame(.horizontal)
                                .id(job.id)
                        }
                    }
                    .scrollTargetLayout()
                }
                .scrollTargetBehavior(.viewAligned)
                .scrollPosition(id: $model.shownId)
                Text("\(model.position) of \(model.jobs.count)" + (model.jobs.count > 1 ? " · swipe for the next" : ""))
                    .font(appStyle.font(.caption2))
                    .foregroundStyle(appStyle.meta)
                    .frame(maxWidth: .infinity, alignment: .center)
                    .accessibilityHidden(true)
            }
        }
    }

    private func undoBar(_ offer: YuiJobsViewModel.UndoOffer) -> some View {
        HStack(spacing: 10) {
            Label(offer.text, systemImage: "checkmark.circle.fill")
                .font(appStyle.font(.footnote))
                .foregroundStyle(appStyle.tenant)
                .fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 8)
            Button("Undo") { Task { await model.undo() } }
                .buttonStyle(.it(.outline))
                .disabled(model.busyKey != nil)
        }
        .padding(10)
        .background(appStyle.sunk, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
    }
}

// MARK: - One card

struct YuiJobCard: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @ObservedObject var model: YuiJobsViewModel
    let job: YuiJob
    @State private var pickingAccount = false
    @State private var earlierOpen = false

    private var busy: Bool { model.busyKey == job.key }

    private func bind(_ name: String) -> Binding<String> {
        Binding(get: { model.value(job, name) }, set: { model.set(job, name, to: $0) })
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            VStack(alignment: .leading, spacing: 2) {
                Text(job.title).font(appStyle.font(.subheadline, weight: .semibold))
                if !job.why.isEmpty {
                    Text(job.why).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            panels
            if let note = model.notes[job.key] {
                Label(note.text, systemImage: note.isError ? "exclamationmark.triangle.fill" : "checkmark.circle.fill")
                    .font(appStyle.font(.footnote))
                    .foregroundStyle(note.isError ? appStyle.blocked : appStyle.tenant)
                    .fixedSize(horizontal: false, vertical: true)
            }
            actions
        }
        .padding(12)
        .frame(maxHeight: .infinity, alignment: .top)
        .background(appStyle.sunk, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        .accessibilityElement(children: .contain)
        .sheet(isPresented: $pickingAccount) { YuiAccountSearchSheet(model: model, job: job) }
    }

    // MARK: Panels (their words | our side), a thin spine between; stacked at accessibility text sizes

    private var panels: some View {
        let layout = dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 12))
            : AnyLayout(HStackLayout(alignment: .top, spacing: 10))
        return layout {
            leftPanel.frame(maxWidth: .infinity, alignment: .topLeading)
            if !dynamicTypeSize.isAccessibilitySize {
                Rectangle().fill(appStyle.line).frame(width: 1).accessibilityHidden(true)
            }
            rightPanel.frame(maxWidth: .infinity, alignment: .topLeading)
        }
    }

    private var leftPanel: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(job.left.name.isEmpty ? "Unknown sender" : job.left.name)
                .font(appStyle.font(.subheadline, weight: .semibold))
            if !job.left.subject.isEmpty {
                Text(job.left.subject).font(appStyle.font(.footnote, weight: .semibold))
            }
            leftText
            if let addr = job.left.addr { Text(addr).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta) }
            if !job.left.at.isEmpty { Text(job.left.at).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta) }
            if let more = job.left.more { Text(more).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta) }
        }
    }

    /// Their words in full (never clamped); once loaded, the whole email scrolls here inside the card.
    @ViewBuilder private var leftText: some View {
        if let full = model.fullEmails[job.key] {
            ScrollView {
                VStack(alignment: .leading, spacing: 6) {
                    Text(full.text).font(appStyle.font(.footnote))
                        .fixedSize(horizontal: false, vertical: true)
                    if !full.earlier.isEmpty {
                        DisclosureGroup(isExpanded: $earlierOpen) {
                            Text(full.earlier).font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
                                .fixedSize(horizontal: false, vertical: true)
                                .frame(maxWidth: .infinity, alignment: .leading)
                        } label: {
                            Text("Earlier in the thread").font(appStyle.font(.footnote, weight: .semibold))
                                .frame(minHeight: 44, alignment: .leading)
                        }
                        .tint(appStyle.tenant)
                    }
                    if !full.message.isEmpty {
                        Text(full.message).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .frame(maxHeight: 320)
            Button("Show less") { earlierOpen = false; model.showLess(job) }
                .buttonStyle(.it(.ghost))
        } else {
            if !job.left.text.isEmpty {
                Text(job.left.text).font(appStyle.font(.footnote))
                    .fixedSize(horizontal: false, vertical: true)
            }
            if job.readRequest != nil {
                Button { Task { await model.readWhole(job) } } label: {
                    HStack(spacing: 6) {
                        if model.reading == job.key { ProgressView() }
                        Text("Read the whole email").multilineTextAlignment(.leading)
                    }
                    .frame(minHeight: 44, alignment: .leading)
                }
                .buttonStyle(.it(.ghost))
                .disabled(model.reading != nil)
                if let e = model.readErrors[job.key] {
                    Label(e, systemImage: "exclamationmark.triangle.fill")
                        .font(appStyle.font(.caption)).foregroundStyle(appStyle.blocked)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
        }
    }

    @ViewBuilder private var rightPanel: some View {
        switch job.kind {
        case .statement: statementPanel
        case .who: whoPanel
        case .reply: replyPanel
        }
    }

    // MARK: statement

    private var statementPanel: some View {
        VStack(alignment: .leading, spacing: 8) {
            if let s = job.statement {
                VStack(alignment: .leading, spacing: 2) {
                    Text(s.account).font(appStyle.font(.subheadline, weight: .semibold))
                    Text([s.label, s.closing].filter { !$0.isEmpty }.joined(separator: " · "))
                        .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                }
                fieldLabel("Send to")
                ForEach(s.recipients) { r in recipientRow(r) }
                fieldLabel("Subject")
                TextField("Subject", text: bind("subject")).textFieldStyle(job: true)
                fieldLabel("Note")
                noteEditor
            }
        }
    }

    private func recipientRow(_ r: YuiRecipient) -> some View {
        let on = model.value(job, "recipient") == r.email
        return Button { model.set(job, "recipient", to: r.email) } label: {
            HStack(alignment: .top, spacing: 8) {
                Image(systemName: on ? "largecircle.fill.circle" : "circle")
                    .foregroundStyle(on ? appStyle.tenant : appStyle.meta)
                VStack(alignment: .leading, spacing: 1) {
                    Text(r.name.isEmpty ? r.email : r.name).font(appStyle.font(.footnote, weight: .semibold))
                    if !r.name.isEmpty { Text(r.email).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta) }
                    if r.onFile {
                        Label("On file", systemImage: "checkmark.circle").font(appStyle.font(.caption)).foregroundStyle(appStyle.tenant)
                    } else {
                        Label("Not on file for this account", systemImage: "exclamationmark.triangle")
                            .font(appStyle.font(.caption)).foregroundStyle(appStyle.blocked)
                    }
                }
                .multilineTextAlignment(.leading)
                Spacer(minLength: 0)
            }
            .frame(minHeight: 44, alignment: .leading)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(on ? .isSelected : [])
    }

    // MARK: who

    private var whoPanel: some View {
        VStack(alignment: .leading, spacing: 8) {
            fieldLabel("First name")
            TextField("First name", text: bind("first_name")).textFieldStyle(job: true)
                .textContentType(.givenName)
            fieldLabel("Last name")
            TextField("Last name", text: bind("last_name")).textFieldStyle(job: true)
                .textContentType(.familyName)
            fieldLabel("Account")
            ForEach(model.accounts(job)) { c in accountRow(c) }
            Button { pickingAccount = true } label: {
                Label("Another account…", systemImage: "magnifyingglass")
                    .font(appStyle.font(.footnote, weight: .semibold))
                    .frame(minHeight: 44, alignment: .leading)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .foregroundStyle(appStyle.tenant)
            .disabled(job.searchRequest == nil)
        }
    }

    private func accountRow(_ c: YuiCandidate) -> some View {
        let on = model.value(job, "client_id") == String(c.clientId)
        return Button { model.set(job, "client_id", to: String(c.clientId)) } label: {
            HStack(spacing: 8) {
                Image(systemName: on ? "largecircle.fill.circle" : "circle")
                    .foregroundStyle(on ? appStyle.tenant : appStyle.meta)
                Text(c.name).font(appStyle.font(.footnote, weight: .semibold)).multilineTextAlignment(.leading)
                Spacer(minLength: 0)
            }
            .frame(minHeight: 44, alignment: .leading)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(on ? .isSelected : [])
    }

    // MARK: reply

    private var replyPanel: some View {
        VStack(alignment: .leading, spacing: 8) {
            if let r = job.reply {
                fieldLabel("To")
                Text(r.toName.isEmpty ? r.toEmail : "\(r.toName) · \(r.toEmail)")
                    .font(appStyle.font(.footnote, weight: .semibold))
                    .fixedSize(horizontal: false, vertical: true)
                fieldLabel("Subject")
                TextField("Subject", text: bind("subject")).textFieldStyle(job: true)
                fieldLabel("Note")
                noteEditor
                if r.canDraft && job.draftRequest != nil {
                    Button { Task { await model.draft(job) } } label: {
                        HStack(spacing: 6) {
                            if model.drafting == job.key { ProgressView() } else { Image(systemName: "sparkles") }
                            Text(model.drafting == job.key ? "Drafting…" : "Draft with Claude")
                        }
                    }
                    .buttonStyle(.it(.ghost))
                    .disabled(model.drafting != nil || busy)
                }
            }
        }
    }

    // MARK: shared bits

    private var noteEditor: some View {
        TextEditor(text: bind("body"))
            .font(appStyle.font(.footnote))
            .scrollContentBackground(.hidden)
            .frame(minHeight: 110)
            .padding(4)
            .background(appStyle.surface, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)).strokeBorder(appStyle.line, lineWidth: 1))
            .accessibilityLabel("Note")
    }

    private func fieldLabel(_ text: String) -> some View {
        Text(text).font(appStyle.font(.caption, weight: .semibold)).foregroundStyle(appStyle.meta)
    }

    // MARK: actions — filled left, outline right

    private var actions: some View {
        let layout = dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8))
            : AnyLayout(HStackLayout(alignment: .top, spacing: 8))
        let enabled = (job.kind == .who ? model.canAddToAccount(job) : model.canSend(job)) && !busy
        return layout {
            if let p = job.primary {
                actionButton(p, kind: .primary) { await model.primary(job) }
                    .disabled(!enabled)
            }
            if let s = job.secondary {
                actionButton(s, kind: .outline) { await model.secondary(job) }
                    .disabled(busy)
            }
        }
    }

    private func actionButton(_ a: YuiAction, kind: ItButtonKind, run: @escaping () async -> Void) -> some View {
        VStack(spacing: 3) {
            Button { Task { await run() } } label: {
                HStack(spacing: 6) {
                    if busy && kind == .primary { ProgressView() }
                    Text(a.label).multilineTextAlignment(.center)
                }
            }
            .buttonStyle(.it(kind, fullWidth: true))
            if !a.sub.isEmpty {
                Text(a.sub).font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                    .multilineTextAlignment(.center)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
        .frame(maxWidth: .infinity)
    }
}

private extension View {
    /// A plain text field in the card's panel, 44 pt tall.
    func textFieldStyle(job: Bool) -> some View { modifier(YuiFieldStyle()) }
}

private struct YuiFieldStyle: ViewModifier {
    @Environment(\.itAppStyle) private var appStyle
    func body(content: Content) -> some View {
        content
            .font(appStyle.font(.footnote))
            .padding(.horizontal, 8)
            .frame(minHeight: 44)
            .background(appStyle.surface, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)).strokeBorder(appStyle.line, lineWidth: 1))
    }
}

// MARK: - "Another account…"

struct YuiAccountSearchSheet: View {
    @Environment(\.dismiss) private var dismiss
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var model: YuiJobsViewModel
    let job: YuiJob
    @State private var query = ""

    var body: some View {
        NavigationStack {
            List {
                Section {
                    TextField("Account name", text: $query)
                        .frame(minHeight: 44)
                        .submitLabel(.search)
                        .onSubmit { Task { await model.searchAccounts(job, query: query) } }
                    Button("Search") { Task { await model.searchAccounts(job, query: query) } }
                        .frame(minHeight: 44)
                        .disabled(query.trimmingCharacters(in: .whitespaces).isEmpty || model.searching)
                }
                if model.searching { HStack { Spacer(); ProgressView(); Spacer() } }
                Section {
                    ForEach(model.foundAccounts[job.key] ?? []) { c in
                        Button {
                            model.set(job, "client_id", to: String(c.clientId))
                            dismiss()
                        } label: {
                            Text(c.name).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                        }
                    }
                }
                if let note = model.notes[job.key], note.isError {
                    Label(note.text, systemImage: "exclamationmark.triangle.fill").foregroundStyle(appStyle.blocked)
                }
            }
            .navigationTitle("Another account")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
        }
    }
}

// MARK: - Debug-only demo

#if DEBUG
/// `-mwYuiJobsDemo [statement|who|reply]` (add `-mwTimelineField` for field mode): the carousel with invented jobs,
/// no sign-in. An optional kind puts that card first. Acts answer ok and remove the card, as the server would.
struct YuiJobsDemo: View {
    @StateObject private var model: YuiJobsViewModel

    init() {
        let args = ProcessInfo.processInfo.arguments
        let first = args.firstIndex(of: "-mwYuiJobsDemo").flatMap { $0 + 1 < args.count ? args[$0 + 1] : nil }
        _model = StateObject(wrappedValue: YuiJobsViewModel(api: DemoYuiAPI(first: first)))
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    SpineHead(slug: "yui", name: "Yui", state: .approval)
                    YuiJobsNode(model: model)
                    SpineTail()
                }
                .padding(16)
            }
            .navigationTitle("Yui")
            .navigationBarTitleDisplayMode(.inline)
            .itAppScreen()
        }
        .task {
            UserDefaults.standard.removeObject(forKey: HeadSpineLogic.key("yui", "decisions"))
            await model.load()
        }
    }
}

@MainActor
private final class DemoYuiAPI: YuiJobsAPI {
    private var all: [YuiJob]
    private var gone: [String] = []

    init(first: String?) {
        let json = """
        {"ok":true,"jobs":[
         {"kind":"statement","key":"s1","title":"Send a statement","why":"Client A asked for their balance.","index":1,"total":3,
          "read":{"mode":"message","key":"s1"},
          "left":{"name":"Client A","subject":"Balance?","text":"Could you send me what I owe so far this season? Thanks.","at":"Today, 9:12","more":2},
          "right":{"account":"Client A Strata","label":"Nov 2026","closing":"$1,240.00","recipients":[{"email":"pat@example.com","name":"Pat Example","on_file":true},{"email":"clienta@example.com","name":"","on_file":false}],"recipient":"pat@example.com","subject":"Your statement, November","body":"Hi Pat, here is your statement. Thank you."},
          "fields":["recipient","subject","body"],
          "primary":{"label":"Send statement","sub":"Goes to Pat Example","request":{"mode":"send","key":"s1"}},
          "secondary":{"label":"Handled","sub":"Takes it off the list","request":{"mode":"handled","key":"s1"}}},
         {"kind":"who","key":"w1","title":"Who is this?","why":"Mail from someone not on file.","index":2,"total":3,
          "left":{"name":"Pat Example","subject":"Hedge trimming","text":"Hi, I am the new manager at Client A. Can you come Thursday?","at":"Yesterday, 4:40 pm","addr":"pat@example.com","more":3},
          "right":{"first_name":"Pat","last_name":"Example","candidates":[{"client_id":11,"name":"Client A Strata"},{"client_id":12,"name":"Client B"}],"client_id":null},
          "fields":["first_name","last_name","client_id"],
          "primary":{"label":"Add to account","sub":"Adds Pat to the account","request":{"mode":"who_confirm","id":5}},
          "secondary":{"label":"Not a client","sub":"Ignore this sender","request":{"mode":"who_not","id":5}},
          "undo":{"request":{"mode":"who_undo","id":5}},"search":{"mode":"who_accounts"}},
         {"kind":"reply","key":"r1","title":"Reply to Client B","why":"They asked about a Thursday visit.","index":3,"total":3,
          "read":{"mode":"message","key":"r1"},
          "left":{"name":"Client B","subject":"Thursday","text":"Is Thursday still on for the lawn? It looks wet out.","at":"Today, 7:55"},
          "right":{"to":{"name":"Client B","email":"clientb@example.com"},"subject":"Re: Thursday","body":"","can_draft":true},
          "fields":["subject","body"],
          "primary":{"label":"Send","sub":"Emails Client B","request":{"mode":"send","key":"r1"}},
          "secondary":{"label":"Handled","sub":"Takes it off the list","request":{"mode":"handled","key":"r1"}},
          "draft":{"mode":"draft","key":"r1"}}
        ]}
        """
        var jobs = ((try? JSONDecoder().decode(YuiJobsResponse.self, from: Data(json.utf8)))?.jobs) ?? []
        if let first, let i = jobs.firstIndex(where: { $0.kind.rawValue == first }) { jobs.insert(jobs.remove(at: i), at: 0) }
        all = jobs
    }

    func yuiJobs() async throws -> YuiJobsResponse {
        YuiJobsResponse(ok: true, jobs: all.filter { !gone.contains($0.key) })
    }

    func yuiAct(request: [String: Any], fields: [String: Any], idempotencyKey: String) async throws -> YuiActResponse {
        try? await Task.sleep(nanoseconds: 350_000_000)
        let mode = request["mode"] as? String ?? ""
        let key = (request["key"] as? String) ?? (request["id"] != nil ? "w1" : "")
        switch mode {
        case "who_accounts":
            return YuiActResponse(ok: true, accounts: [YuiCandidate(json: 21, "Client C"), YuiCandidate(json: 22, "Client D")])
        case "message":
            let para = "Hi, I am writing about the lawn and the hedges along the back fence. They have grown a lot since the last visit and the strata council would like them neat before the holiday meeting. "
            return YuiActResponse(ok: true, message: "Read from the office mailbox.",
                                  text: String(repeating: para, count: 8) + "\n\nThanks, Client B",
                                  earlier: "On Monday you wrote: We can come Thursday if the weather holds.\n\nOn Friday I wrote: Could you also look at the front beds?",
                                  fromMailbox: "office")
        case "draft":
            return YuiActResponse(ok: true, body: "Hi, yes, Thursday is still on. We will check the lawn that morning and let you know if it is too wet.")
        case "who_undo":
            gone.removeAll { $0 == key }
            return YuiActResponse(ok: true, message: "Undone.")
        default:
            gone.append(key)
            return YuiActResponse(ok: true, message: mode == "who_confirm" ? "Added to the account." : "Done.", undoId: mode.hasPrefix("who") ? "5" : nil)
        }
    }
}

private extension YuiCandidate {
    init(json id: Int, _ name: String) {
        let v = try? JSONDecoder().decode(BKValue.self, from: Data("{\"client_id\":\(id),\"name\":\"\(name)\"}".utf8))
        self = v.flatMap { YuiCandidate($0) }!
    }
}
#endif
