//
//  ScreenHead.swift
//  MowologyCRM
//

import Foundation

/// Pure decisions for the two-line work-screen header. Kept free of SwiftUI so the
/// command-line logic suite can prove role, dismissal, and select-mode behaviour.
enum ScreenHeaderBriefStatus: Equatable { case loading, failed, loaded }

struct ScreenHeaderRules: Equatable {
    let showsHeadLine: Bool
    let stateText: String?
    let showsReview: Bool
    let showsTimeChip: Bool
    let showsRefresh: Bool

    static func reason(live: String?, demo: String?, isDemo: Bool) -> String? {
        isDemo ? demo : live
    }

    /// The quiet line says "No decisions waiting" only once the brief has actually loaded.
    private static func quietText(_ status: ScreenHeaderBriefStatus) -> String {
        switch status {
        case .loaded:  return "No decisions waiting"
        case .loading: return "Checking decisions"
        case .failed:  return "Decision status unavailable"
        }
    }

    static func resolve(
        isAdmin: Bool,
        reason: String?,
        dismissedReason: String?,
        isSelecting: Bool,
        briefStatus: ScreenHeaderBriefStatus = .loaded
    ) -> Self {
        guard isAdmin else {
            return Self(
                showsHeadLine: false,
                stateText: nil,
                showsReview: false,
                showsTimeChip: !isSelecting,
                showsRefresh: !isSelecting
            )
        }

        let readableReason = reason?.trimmingCharacters(in: .whitespacesAndNewlines)
        let needsReview = readableReason?.isEmpty == false && readableReason != dismissedReason
        return Self(
            showsHeadLine: true,
            stateText: needsReview ? readableReason : quietText(briefStatus),
            showsReview: needsReview,
            showsTimeChip: !isSelecting,
            showsRefresh: !isSelecting
        )
    }
}

#if !SCREEN_HEADER_LOGIC_ONLY
import SwiftUI

/// The common header for Schedule, Receipts, and Time Clock. A safe-area inset owns
/// its height, so Dynamic Type can stack either line without covering screen content.
struct ScreenHeader<Title: View, SecondaryActions: View>: View {
    let slug: String
    let isSelecting: Bool
    let isRefreshing: Bool
    let headOnly: Bool
    let onRefresh: () -> Void
    let title: () -> Title
    let secondaryActions: () -> SecondaryActions

    @EnvironmentObject private var authSession: AuthSession
    @EnvironmentObject private var store: ReadyBriefStore
    @Environment(\.it) private var it
    @Environment(\.itAppStyle) private var appStyle
    @AppStorage("ittai.design.field") private var field = false
    @AppStorage private var dismissed: String

    init(
        slug: String,
        isSelecting: Bool = false,
        isRefreshing: Bool = false,
        headOnly: Bool = false,
        onRefresh: @escaping () -> Void,
        @ViewBuilder title: @escaping () -> Title,
        @ViewBuilder secondaryActions: @escaping () -> SecondaryActions
    ) {
        self.slug = slug
        self.isSelecting = isSelecting
        self.isRefreshing = isRefreshing
        self.headOnly = headOnly
        self.onRefresh = onRefresh
        self.title = title
        self.secondaryActions = secondaryActions
        _dismissed = AppStorage(wrappedValue: "", "screenHead.dismissed.\(slug)")
    }

    private var head: TeamHead? { TeamHead.all.first(where: { $0.slug == slug }) }

    private var demoReason: String? {
        #if DEBUG
        guard ProcessInfo.processInfo.arguments.contains("-mwScreenHeaderDemo") else { return nil }
        switch slug {
        case "penny": return "Three receipts need your review"
        default: return nil
        }
        #else
        return nil
        #endif
    }

    private var isDemo: Bool {
        #if DEBUG
        ProcessInfo.processInfo.arguments.contains("-mwScreenHeaderDemo")
        #else
        false
        #endif
    }

    private var rules: ScreenHeaderRules {
        ScreenHeaderRules.resolve(
            isAdmin: isDemo || authSession.user?.isAdmin == true,
            reason: ScreenHeaderRules.reason(
                live: ScreenHead.needsYou(slug, store),
                demo: demoReason,
                isDemo: isDemo
            ),
            dismissedReason: isDemo ? nil : dismissed,
            isSelecting: isSelecting,
            briefStatus: isDemo || store.loaded && !store.failed ? .loaded : (store.failed ? .failed : .loading)
        )
    }

    var body: some View {
        Group {
            if rules.showsHeadLine || !headOnly {
                VStack(spacing: 0) {
                    if rules.showsHeadLine, let head {
                        headLine(head)
                    }
                    if !headOnly { controlsLine }
                }
                .background(it.surface)
                .overlay(alignment: .bottom) {
                    Rectangle().fill(it.line).frame(height: ItStroke.hairline)
                }
            }
        }
        .ittaiTheme(field: field)
    }

    private func headLine(_ head: TeamHead) -> some View {
        ViewThatFits(in: .horizontal) {
            HStack(spacing: ItSpace.s2) { headIdentity(head); Spacer(minLength: ItSpace.s2); headState }
            VStack(alignment: .leading, spacing: ItSpace.s1) { headIdentity(head); headState }
        }
        .padding(.horizontal, ItSpace.s4)
        .frame(minHeight: ItSize.targetPhone)
        .accessibilityElement(children: .contain)
    }

    private func headIdentity(_ head: TeamHead) -> some View {
        Button { ScreenHead.open(slug) } label: {
            HStack(spacing: ItSpace.s2) {
                ItPortrait(name: head.name, url: head.faceURL, state: ScreenHead.state(slug, store), size: ItSize.hexCompact)
                Text(head.name)
                    .itText(.body14)
                    .foregroundStyle(it.text)
            }
            .fixedSize(horizontal: true, vertical: false)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel("\(head.name), AI head of department")
        .accessibilityHint("Opens \(head.name)'s workspace")
    }

    private var headState: some View {
        HStack(spacing: ItSpace.s1) {
            Text(rules.stateText ?? "")
                .font(appStyle.font(.caption, weight: .semibold))
                .foregroundStyle(it.meta)
                .fixedSize(horizontal: false, vertical: true)
            if rules.showsReview {
                Button("Review") { ScreenHead.open(slug) }
                    .font(appStyle.font(.caption, weight: .semibold))
                    .foregroundStyle(it.color(.brand))
                    .frame(minHeight: ItSize.targetPhone)
                Button { dismissed = demoReason ?? ScreenHead.needsYou(slug, store) ?? "" } label: {
                    Image(systemName: "xmark")
                        .foregroundStyle(it.meta)
                        .frame(minWidth: ItSize.targetPhone, minHeight: ItSize.targetPhone)
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Dismiss until the reason changes")
            }
        }
    }

    private var controlsLine: some View {
        ViewThatFits(in: .horizontal) {
            HStack(spacing: ItSpace.s2) { controlsTitle; Spacer(minLength: ItSpace.s2); controls }
            VStack(alignment: .leading, spacing: ItSpace.s1) { controlsTitle; controls }
        }
        .padding(.horizontal, ItSpace.s4)
        .frame(minHeight: ItSize.targetPhone)
    }

    private var controlsTitle: some View {
        title()
            .fixedSize(horizontal: true, vertical: false)
            .layoutPriority(1)
    }

    private var controls: some View {
        HStack(spacing: ItSpace.s1) {
            if rules.showsTimeChip { TimeChip() }
            if rules.showsRefresh {
                Button(action: onRefresh) {
                    Image(systemName: "arrow.clockwise")
                        .foregroundStyle(it.color(.brand))
                        .frame(minWidth: ItSize.targetPhone, minHeight: ItSize.targetPhone)
                }
                .buttonStyle(.plain)
                .disabled(isRefreshing)
                .accessibilityLabel("Refresh")
            }
            secondaryActions()
        }
    }
}

extension ScreenHeader where SecondaryActions == EmptyView {
    init(
        slug: String,
        isSelecting: Bool = false,
        isRefreshing: Bool = false,
        onRefresh: @escaping () -> Void,
        @ViewBuilder title: @escaping () -> Title
    ) {
        self.init(
            slug: slug,
            isSelecting: isSelecting,
            isRefreshing: isRefreshing,
            headOnly: false,
            onRefresh: onRefresh,
            title: title,
            secondaryActions: { EmptyView() }
        )
    }
}

extension ScreenHeader where Title == EmptyView, SecondaryActions == EmptyView {
    /// The owning-head line for a pushed work screen. The destination keeps its own
    /// navigation title, toolbar, and refresh behaviour unchanged.
    init(slug: String) {
        self.init(
            slug: slug,
            headOnly: true,
            onRefresh: {},
            title: { EmptyView() },
            secondaryActions: { EmptyView() }
        )
    }
}

@MainActor
enum ScreenHead {
    static func state(_ slug: String, _ store: ReadyBriefStore) -> ItEmployeeState? {
        store.response?.heads?[slug]?.state.flatMap(ItEmployeeState.init(rawValue:))
    }

    static func needsYou(_ slug: String, _ store: ReadyBriefStore) -> String? {
        guard let state = state(slug, store), state == .approval || state == .blocked,
              let reason = store.response?.heads?[slug]?.reason?.trimmingCharacters(in: .whitespacesAndNewlines),
              !reason.isEmpty else { return nil }
        return reason
    }

    static func open(_ slug: String) {
        NotificationRouter.shared.pendingHead = HeadRoute(head: slug, quoteId: nil)
    }
}
#endif
