//
//  ScreenHead.swift
//  MowologyCRM
//
//  The head of department who owns a work screen (docs/plans/kaname-tab-and-screen-heads-spec.md §2):
//  Otto on Schedule and Time, Penny on Receipts. Their face sits in the screen's title bar; a line under the title
//  appears only when they need you and the screen has no card of its own saying so. Admins only — crew see neither.
//  Kaname himself is the centre tab, never a face here. Both read the ready brief's per-head states
//  (ReadyBriefStore, an environment object set by MainTabView); a tap opens that head's workspace in Kaname's tab.
//

import SwiftUI

/// The owning head's face, 28 pt with their state ring, for a screen's leading title-bar slot.
struct ScreenHeadFace: View {
    let slug: String
    @EnvironmentObject private var authSession: AuthSession
    @EnvironmentObject private var store: ReadyBriefStore
    @AppStorage("ittai.design.field") private var field = false

    var body: some View {
        if authSession.user?.isAdmin == true, let head = TeamHead.all.first(where: { $0.slug == slug }) {
            Button { ScreenHead.open(slug) } label: {
                ItPortrait(name: head.name, url: head.faceURL, state: ScreenHead.state(slug, store), size: 28)
                    .frame(minWidth: 44, minHeight: 44)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel("\(head.name), \(head.role)")
            .accessibilityHint("Opens \(head.name)'s workspace")
            .ittaiTheme(field: field)
        }
    }
}

/// "Otto: …" under the title, only while the head is waiting for approval or blocked and gave a reason.
/// Dismissed, it stays away until the reason changes.
struct ScreenHeadLine: View {
    let slug: String
    @EnvironmentObject private var authSession: AuthSession
    @EnvironmentObject private var store: ReadyBriefStore
    @AppStorage("ittai.design.field") private var field = false
    @AppStorage private var dismissed: String

    init(slug: String) {
        self.slug = slug
        _dismissed = AppStorage(wrappedValue: "", "screenHead.dismissed.\(slug)")
    }

    var body: some View {
        if authSession.user?.isAdmin == true,
           let head = TeamHead.all.first(where: { $0.slug == slug }),
           let reason = ScreenHead.needsYou(slug, store), reason != dismissed {
            Row(head: head, state: ScreenHead.state(slug, store), reason: reason) { dismissed = reason }
                .ittaiTheme(field: field)
        }
    }

    private struct Row: View {
        let head: TeamHead
        let state: ItEmployeeState?
        let reason: String
        let onDismiss: () -> Void
        @Environment(\.it) private var it

        var body: some View {
            HStack(spacing: ItSpace.s2) {
                Button { ScreenHead.open(head.slug) } label: {
                    HStack(spacing: ItSpace.s2) {
                        ItPortrait(name: head.name, url: head.faceURL, state: state, size: 24)
                        Text("\(head.name): \(reason)")
                            .itText(.body14)
                            .foregroundStyle(it.text)
                            .lineLimit(2)
                            .multilineTextAlignment(.leading)
                        Spacer(minLength: 0)
                    }
                    .frame(minHeight: 44)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityHint("Opens \(head.name)'s workspace")
                Button(action: onDismiss) {
                    Image(systemName: "xmark").foregroundStyle(it.meta)
                        .frame(width: 44, height: 44)
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Dismiss until something changes")
            }
            .padding(.leading, ItSpace.s4)
            .background(it.surface)
            .overlay(alignment: .bottom) { Rectangle().fill(it.line).frame(height: 1) }
        }
    }
}

@MainActor
enum ScreenHead {
    static func state(_ slug: String, _ store: ReadyBriefStore) -> ItEmployeeState? {
        store.response?.heads?[slug]?.state.flatMap(ItEmployeeState.init(rawValue:))
    }

    /// The reason to interrupt, or nil: only approval / blocked with a readable reason.
    static func needsYou(_ slug: String, _ store: ReadyBriefStore) -> String? {
        guard let s = state(slug, store), s == .approval || s == .blocked,
              let reason = store.response?.heads?[slug]?.reason?.trimmingCharacters(in: .whitespacesAndNewlines),
              !reason.isEmpty else { return nil }
        return reason
    }

    /// MainTabView follows pendingHead to Kaname's tab; TeamView opens the head's workspace.
    static func open(_ slug: String) {
        NotificationRouter.shared.pendingHead = HeadRoute(head: slug, quoteId: nil)
    }
}
