//
//  OutboxStatusLine.swift
//  MowologyCRM
//
//  "2 waiting to sync" over the schedule while anything made offline is still on the phone (CreateOutbox). A tap
//  lists them; one the server refused shows its reason with Try again / Discard. Hidden when there is nothing.
//

import SwiftUI

struct OutboxStatusLine: View {
    @ObservedObject private var outbox = CreateOutbox.shared
    @EnvironmentObject private var authSession: AuthSession
    @Environment(\.itAppStyle) private var appStyle
    @State private var showing = false

    var body: some View {
        if let line = outbox.statusLine(me: authSession.user?.id) {
            Button { showing = true } label: {
                HStack(spacing: 8) {
                    Image(systemName: "icloud.and.arrow.up")
                        .foregroundStyle(appStyle.tenant)
                        .accessibilityHidden(true)
                    Text(line)
                        .font(appStyle.font(.subheadline, weight: .medium))
                        .foregroundStyle(appStyle.text)
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.right")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                        .accessibilityHidden(true)
                }
                .padding(.horizontal, 16)
                .frame(minHeight: 44)
                .background(appStyle.surface)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityHint("Shows what is saved on this phone and not yet sent")
            .sheet(isPresented: $showing) { OutboxList().environmentObject(authSession) }
        }
    }
}

private struct OutboxList: View {
    @ObservedObject private var outbox = CreateOutbox.shared
    @EnvironmentObject private var authSession: AuthSession
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dismiss) private var dismiss
    @State private var confirmDiscard: CreateOutbox.Entry?

    var body: some View {
        NavigationStack {
            List {
                let mine = outbox.mine(me: authSession.user?.id)
                if mine.isEmpty {
                    Text("Everything has been sent.").foregroundStyle(appStyle.meta)
                }
                ForEach(mine) { entry in
                    VStack(alignment: .leading, spacing: 6) {
                        Text(entry.title).font(appStyle.font(.subheadline, weight: .semibold))
                        Text("Made \(entry.queuedAt.formatted(date: .abbreviated, time: .shortened))")
                            .font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                        if let reason = entry.refused {
                            Text("Couldn't be saved: \(reason)")
                                .font(appStyle.font(.caption)).foregroundStyle(appStyle.blocked)
                                .fixedSize(horizontal: false, vertical: true)
                            HStack(spacing: 16) {
                                Button("Try again") {
                                    Task { await outbox.retry(entry.id, using: APIClient(authSession: authSession)) }
                                }
                                Button("Discard", role: .destructive) { confirmDiscard = entry }
                            }
                            .buttonStyle(.borderless)
                            .font(appStyle.font(.subheadline))
                        } else {
                            Text("Waiting for signal").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                        }
                    }
                    .padding(.vertical, 4)
                }
            }
            .navigationTitle("Saved on this phone")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } } }
            .refreshable { await outbox.drain(using: APIClient(authSession: authSession)) }
            .confirmationDialog("Discard this? It was never saved on the server.",
                                isPresented: Binding(get: { confirmDiscard != nil }, set: { if !$0 { confirmDiscard = nil } }),
                                titleVisibility: .visible, presenting: confirmDiscard) { entry in
                Button("Discard \(entry.title)", role: .destructive) { outbox.discard(entry.id) }
                Button("Keep it", role: .cancel) {}
            }
        }
    }
}
