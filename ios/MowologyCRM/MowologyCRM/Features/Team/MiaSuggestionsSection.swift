//
//  MiaSuggestionsSection.swift
//  MowologyCRM
//
//  Mia's drafted messages on her card (the web card's carousel, MiaDeskService): a payment thank-you with a
//  fall cleanup ask, a seasonal reminder… Nothing goes without Tim's tap. Send waits for the person's best time
//  (SendTimeService) and says when; "Send now" is the exception. Two big square buttons, well apart
//  (Tim, 2026-11-05: small close buttons are too easy to hit wrongly).
//

import SwiftUI

struct MiaSuggestionsSection: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var vm: HeadCardViewModel
    @State private var index = 0
    @State private var editing: MiaSuggestion?
    @State private var confirmSend: MiaSuggestion?
    @State private var confirmNow: MiaSuggestion?
    @State private var skipping: MiaSuggestion?

    var body: some View {
        if vm.card?.canApprove == true, !vm.miaSuggestions.isEmpty {
            let list = vm.miaSuggestions
            let s = list[min(index, list.count - 1)]
            VStack(alignment: .leading, spacing: 12) {
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Mia's messages").font(appStyle.font(.headline))
                        Text("\(min(index, list.count - 1) + 1) of \(list.count) · \(s.kindLabel)")
                            .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    }
                    Spacer()
                    if list.count > 1 {
                        Button { index = max(0, index - 1) } label: { Image(systemName: "chevron.left").frame(width: 44, height: 44) }
                            .disabled(index == 0).accessibilityLabel("Previous")
                        Button { index = min(list.count - 1, index + 1) } label: { Image(systemName: "chevron.right").frame(width: 44, height: 44) }
                            .disabled(index >= list.count - 1).accessibilityLabel("Next")
                    }
                }
                VStack(alignment: .leading, spacing: 4) {
                    Text(s.name.isEmpty ? s.email : s.name).font(appStyle.font(.subheadline).weight(.semibold))
                    Text(s.why).font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
                        .fixedSize(horizontal: false, vertical: true)
                }
                VStack(alignment: .leading, spacing: 6) {
                    Text(s.subject).font(appStyle.font(.subheadline).weight(.semibold))
                    Text(s.body).font(appStyle.font(.subheadline))
                        .textSelection(.enabled)
                        .fixedSize(horizontal: false, vertical: true)
                    Button("Edit") { editing = s }
                        .font(appStyle.font(.footnote))
                        .frame(minHeight: 44)
                }
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(appStyle.color(.tenantSoft, legacy: Color.MW.light), in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
                if let when = MiaSuggestion.whenText(s.sendAt) {
                    Text("Goes \(when)" + (s.sendAtLearned ? ", when they usually read email." : " (the usual time until Mia learns theirs)."))
                        .font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
                }
                HStack(spacing: 16) {
                    tile(title: MiaSuggestion.whenText(s.sendAt).map { "Send \($0)" } ?? "Send", icon: "paperplane.fill", filled: true) { confirmSend = s }
                    tile(title: "Skip", icon: "xmark", filled: false) { skipping = s }
                }
                .disabled(vm.isBusy)
                if s.sendAt != nil {
                    Button("Send now instead") { confirmNow = s }
                        .font(appStyle.font(.footnote))
                        .frame(minHeight: 44)
                        .disabled(vm.isBusy)
                }
            }
            .confirmationDialog("Send to \(s.email)?", isPresented: Binding(get: { confirmSend != nil }, set: { if !$0 { confirmSend = nil } }), titleVisibility: .visible) {
                Button(MiaSuggestion.whenText(s.sendAt).map { "Queue for \($0)" } ?? "Send") {
                    if let c = confirmSend { Task { await vm.miaDecide(c, action: "send", subject: c.subject, body: c.body) } }
                }
                Button("Cancel", role: .cancel) {}
            }
            .confirmationDialog("Send now to \(s.email)?", isPresented: Binding(get: { confirmNow != nil }, set: { if !$0 { confirmNow = nil } }), titleVisibility: .visible) {
                Button("Send now") {
                    if let c = confirmNow { Task { await vm.miaDecide(c, action: "send", subject: c.subject, body: c.body, now: true) } }
                }
                Button("Cancel", role: .cancel) {}
            }
            .confirmationDialog("Why skip it?", isPresented: Binding(get: { skipping != nil }, set: { if !$0 { skipping = nil } }), titleVisibility: .visible) {
                ForEach([("not_now", "Not now"), ("talked", "Already talked"), ("not_fit", "Not a fit"), ("never", "Never")], id: \.0) { k in
                    Button(k.1) { if let c = skipping { Task { await vm.miaDecide(c, action: "skip", reason: k.0) } } }
                }
                Button("Cancel", role: .cancel) {}
            }
            .sheet(item: $editing) { e in
                MiaEditSheet(suggestion: e) { subject, body in
                    Task { await vm.miaDecide(e, action: "send", subject: subject, body: body) }
                }
            }
        }
    }

    /// A big square decision tile (filled = the main action, outline = the other one).
    private func tile(title: String, icon: String, filled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 26, weight: .semibold))
                Text(title).font(appStyle.font(.subheadline).weight(.semibold))
                    .multilineTextAlignment(.center)
                    .lineLimit(2)
                    .minimumScaleFactor(0.8)
            }
            .frame(maxWidth: .infinity, minHeight: 120)
            .aspectRatio(1, contentMode: .fit)
            .foregroundStyle(filled ? Color.white : appStyle.tenant)
            .background(filled ? appStyle.tenant : Color.clear, in: RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)).stroke(appStyle.tenant, lineWidth: filled ? 0 : 1.5))
            .contentShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
        }
        .buttonStyle(.plain)
    }
}

/// Edit the words before sending; Mia learns Tim's wording when he writes it the same way twice (server side).
private struct MiaEditSheet: View {
    @Environment(\.dismiss) private var dismiss
    let suggestion: MiaSuggestion
    let onSend: (String, String) -> Void
    @State private var subject = ""
    @State private var text = ""

    var body: some View {
        NavigationStack {
            Form {
                Section("Subject") { TextField("Subject", text: $subject) }
                Section("Email") { TextEditor(text: $text).frame(minHeight: 260) }
                if let when = MiaSuggestion.whenText(suggestion.sendAt) {
                    Section { Text("Goes \(when), to \(suggestion.email).").font(.footnote) }
                }
            }
            .navigationTitle(suggestion.name.isEmpty ? "Message" : suggestion.name)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button(MiaSuggestion.whenText(suggestion.sendAt) == nil ? "Send" : "Queue") {
                        onSend(subject, text); dismiss()
                    }
                    .disabled(subject.trimmingCharacters(in: .whitespaces).isEmpty || text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
            .onAppear { subject = suggestion.subject; text = suggestion.body }
        }
    }
}
