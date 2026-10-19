//
//  ItAIOffice.swift
//  MowologyCRM
//
//  The AI Office screen (Ittai v1.2, phone model): Charlie's one-line brief, the Six + One hexagon,
//  the seven rows (Charlie, then clockwise from Yui) with each head's most important item, then the
//  selected head's workspace. Pure SwiftUI: the Team tab feeds it real data; the Mac render feeds it samples.
//

import SwiftUI

/// Persistent, compact navigation for the seven AI workspaces.
struct ItOfficeBar: View {
    let members: [ItOfficeMember]
    var connectionUnavailable = false
    var portraitURL: (String) -> URL? = { _ in nil }
    let onSelect: (String) -> Void
    @Environment(\.it) private var it

    private let order = ["ada", "mia", "otto", "charlie", "penny", "sam", "yui"]

    var body: some View {
        HStack(spacing: 0) {
            ForEach(order, id: \.self) { slug in
                if let member = members.first(where: { $0.id == slug }) {
                    Button { onSelect(slug) } label: { portrait(member) }
                        .disabled(!member.available)
                        .buttonStyle(ItOfficeBarButtonStyle(selected: slug == "charlie", selectedColor: it.selected))
                        .frame(maxWidth: .infinity, minHeight: 48)
                        .contentShape(Rectangle())
                        .accessibilityLabel(accessibilityLabel(member))
                        .accessibilityHint("Opens \(member.name)'s workspace in AI Office")
                }
            }
        }
        .padding(.horizontal, 4)
        .frame(height: 52)
        .background(it.surface)
        .overlay(alignment: .bottom) { Rectangle().fill(it.line).frame(height: 1) }
    }

    private func portrait(_ member: ItOfficeMember) -> some View {
        let state: ItEmployeeState? = connectionUnavailable || !member.available ? .unavailable : member.state
        let visual: CGFloat = member.id == "charlie" ? 36 : 31
        return ZStack(alignment: .bottomTrailing) {
            ZStack {
                Circle().fill(it.sunk)
                Text(String(member.name.prefix(1))).itText(.caption12, weight: 600).foregroundStyle(it.meta)
                if let url = portraitURL(member.id) {
                    AsyncImage(url: url) { phase in
                        if let image = phase.image { image.resizable().scaledToFill() } else { Color.clear }
                    }
                }
            }
            .frame(width: visual, height: visual).clipShape(Circle())
            .overlay {
                Circle().strokeBorder(member.id == "charlie" ? it.color(.brand) : state.map { it.color($0.token) } ?? it.line,
                    style: StrokeStyle(lineWidth: member.id == "charlie" ? 2.5 : 1.5,
                                       dash: state == .idle ? [2.5, 2.5] : (state == .unavailable ? [1, 2] : [])))
            }
            if let state {
                ItIcon(shape: state.icon, size: 9, color: it.color(state.token))
                    .padding(2).background(it.surface, in: Circle()).offset(x: 2, y: 2)
            }
        }
        .frame(width: 42, height: 42)
    }

    private func accessibilityLabel(_ member: ItOfficeMember) -> String {
        if connectionUnavailable { return "\(member.name), connection unavailable" }
        if !member.available { return "\(member.name), not in the iPhone app yet" }
        if !member.loaded || member.state == nil { return "\(member.name), status not loaded yet" }
        guard let state = member.state else { return "\(member.name), status not loaded yet" }
        let words = state == .approval ? "waiting for your approval" : state.words.lowercased()
        return member.stateReason.map { "\(member.name), \(words), \($0)" } ?? "\(member.name), \(words)"
    }
}

private struct ItOfficeBarButtonStyle: ButtonStyle {
    let selected: Bool
    let selectedColor: Color
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .background((configuration.isPressed || selected) ? selectedColor.opacity(configuration.isPressed ? 0.8 : 0.45) : Color.clear,
                        in: RoundedRectangle(cornerRadius: 8))
    }
}

struct ItAIOfficeView<Workspace: View>: View {
    let brief: String?
    let briefTime: String?
    let charlie: ItOfficeMember
    let sides: [ItOfficeMember]
    @Binding var selected: String?
    var briefLoadState: ItLoadState? = nil
    var priorityText: String? = nil
    var recommendation: String? = nil
    var actionLabel: String? = nil
    var canSnooze = false
    var actionsBusy = false
    var actionsDisabled = false
    var onAction: () -> Void = {}
    var onSnooze: () -> Void = {}
    var portraitURL: (String) -> URL? = { _ in nil }
    /// Optional line under a head's name in the workspace header (e.g. their archetype).
    var subtitle: (String) -> String? = { _ in nil }
    var onOpen: (String) -> Void = { _ in }
    @ViewBuilder var workspace: (String) -> Workspace
    @Environment(\.it) private var it
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var rows: [ItOfficeMember] {
        [charlie] + ItHexagonGeometry.order.compactMap { id in sides.first { $0.id == id } }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: ItSpace.s8) {
            briefBlock
            priorityBlock
            ItSection(title: "AI office", note: "Six specialists, coordinated by Charlie") {
                VStack(spacing: ItSpace.s4) {
                    ItHexagon(width: 340, charlie: charlie, sides: sides, selected: $selected, onOpen: onOpen)
                        .frame(maxWidth: .infinity)
                        .padding(.top, ItSpace.s4)
                    VStack(spacing: 0) {
                        Rectangle().fill(it.line).frame(height: 1)
                        ForEach(rows) { m in row(m) }
                    }
                }
            }
        }
    }

    @ViewBuilder private var priorityBlock: some View {
        if charlie.loaded && (!charlie.loadFailed || isOfflineBrief) {
            Group {
                if dynamicTypeSize.isAccessibilitySize {
                    VStack(alignment: .leading, spacing: ItSpace.s3) {
                        ItPortrait(name: charlie.name, url: portraitURL("charlie"), state: charlie.state, size: 44)
                        priorityContent
                    }
                } else {
                    HStack(alignment: .top, spacing: ItSpace.s3) {
                        ItPortrait(name: charlie.name, url: portraitURL("charlie"), state: charlie.state, size: 44)
                        priorityContent
                    }
                }
            }
            .padding(ItSpace.s4).background(it.surface, in: RoundedRectangle(cornerRadius: ItRadius.lg))
        }
    }

    private var priorityContent: some View {
        VStack(alignment: .leading, spacing: ItSpace.s3) {
            Text("Today's priority").itText(.meta13, weight: 600).foregroundStyle(it.meta)
            if let priorityText {
                Text(priorityText).itText(.body16).foregroundStyle(it.text)
                    .fixedSize(horizontal: false, vertical: true)
                if let recommendation {
                    Text("Recommended next step: \(recommendation)").itText(.body14).foregroundStyle(it.text)
                        .fixedSize(horizontal: false, vertical: true)
                }
                ViewThatFits(in: .horizontal) {
                    HStack(spacing: ItSpace.s2) { priorityButtons }
                    VStack(alignment: .leading, spacing: ItSpace.s2) { priorityButtons }
                }
            } else {
                Text("Nothing needs you right now.").itText(.body16).foregroundStyle(it.meta)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private var isOfflineBrief: Bool {
        if case .offline = briefLoadState { return true }
        return false
    }

    @ViewBuilder private var priorityButtons: some View {
        if let actionLabel {
            Button(actionLabel, action: onAction).buttonStyle(.it(.primary)).disabled(actionsBusy || actionsDisabled)
        }
        if canSnooze {
            Button("Not now · back tomorrow", action: onSnooze).buttonStyle(.it(.ghost)).disabled(actionsBusy || actionsDisabled)
        }
    }

    private var briefBlock: some View {
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: ItSpace.s3) {
                    ItPortrait(name: charlie.name, url: portraitURL("charlie"), state: charlie.state, size: 44)
                    briefContent
                }
            } else {
                HStack(alignment: .top, spacing: ItSpace.s3) {
                    ItPortrait(name: charlie.name, url: portraitURL("charlie"), state: charlie.state, size: 44)
                    briefContent
                }
            }
        }
        .accessibilityElement(children: .combine)
    }

    private var briefContent: some View {
        VStack(alignment: .leading, spacing: ItSpace.s1) {
            Text(briefTime.map { "Charlie's brief · \($0)" } ?? "Charlie's brief")
                .itText(.meta13, weight: 600).foregroundStyle(it.meta)
            Text(charlieBrief)
                .itText(.title22).foregroundStyle(it.text)
                .fixedSize(horizontal: false, vertical: true)
            if let briefLoadState { ItLoadStateLine(state: briefLoadState) }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private var charlieBrief: String {
        if !charlie.loaded { return "Charlie is gathering the day." }
        if charlie.loadFailed && !isOfflineBrief { return "Couldn't load Charlie's brief." }
        return brief ?? "Charlie is gathering the day."
    }

    @ViewBuilder
    private func row(_ m: ItOfficeMember) -> some View {
        if m.available {
            Button { selected = m.id; onOpen(m.id) } label: { rowBody(m) }
                .buttonStyle(.plain)
                .accessibilityElement(children: .combine)
                .accessibilityHint("Opens \(m.name)'s workspace")
                .accessibilityAddTraits(selected == m.id ? .isSelected : [])
        } else {
            // An unavailable head is information, not a control: no button, so VoiceOver never offers an
            // action that does nothing (Codex review 2026-10-17). Not .disabled either — that greys the text.
            rowBody(m).accessibilityElement(children: .combine)
        }
    }

    private func rowBody(_ m: ItOfficeMember) -> some View {
        ItRow(selected: selected == m.id) {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: ItSpace.s2) {
                    HStack(alignment: .center, spacing: ItSpace.s3) {
                        ItPortrait(name: m.name, url: portraitURL(m.id), state: m.available ? m.state : .unavailable, size: 40)
                        Text(m.name).itText(.body16, weight: 600).foregroundStyle(it.text)
                            .fixedSize(horizontal: false, vertical: true)
                        ItAITag()
                    }
                    Text(m.department).itText(.meta13).foregroundStyle(it.meta)
                        .fixedSize(horizontal: false, vertical: true)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    rowDetails(m)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            } else {
                HStack(alignment: .center, spacing: ItSpace.s3) {
                    ItPortrait(name: m.name, url: portraitURL(m.id), state: m.available ? m.state : .unavailable, size: 40)
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 6) {
                            Text(m.name).itText(.body16, weight: 600).foregroundStyle(it.text)
                            ItAITag()
                            Text(m.department).itText(.meta13).foregroundStyle(it.meta)
                        }
                        if !m.available {
                            ItLoadStateLine(state: .unavailable)
                        } else if let state = m.loadState {
                            ItLoadStateLine(state: state)
                        } else if !m.loaded {
                            ItLoadStateLine(state: .loading)
                        } else if m.loadFailed {
                            ItLoadStateLine(state: .error)
                        } else if let item = m.topItem {
                            Text(item).itText(.body14).foregroundStyle(it.text)
                                .monospacedDigit()
                                .fixedSize(horizontal: false, vertical: true)
                        } else {
                            Text("Nothing needs you right now.").itText(.body14).foregroundStyle(it.meta)
                        }
                        if let st = m.state, m.available { ItEmployeeStateLabel(state: st, detail: m.stateReason) }
                    }
                    Spacer(minLength: ItSpace.s2)
                    if let c = m.count, c > 0, m.available {
                        Text("\(c)").itText(.body14, weight: 600).monospacedDigit().foregroundStyle(it.text)
                            .accessibilityLabel("\(c) waiting")
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func rowDetails(_ m: ItOfficeMember) -> some View {
        if !m.available {
            ItLoadStateLine(state: .unavailable).frame(maxWidth: .infinity, alignment: .leading)
        } else if let state = m.loadState {
            ItLoadStateLine(state: state).frame(maxWidth: .infinity, alignment: .leading)
        } else if !m.loaded {
            ItLoadStateLine(state: .loading).frame(maxWidth: .infinity, alignment: .leading)
        } else if m.loadFailed {
            ItLoadStateLine(state: .error).frame(maxWidth: .infinity, alignment: .leading)
        } else if let item = m.topItem {
            Text(item).itText(.body14).foregroundStyle(it.text).monospacedDigit()
                .fixedSize(horizontal: false, vertical: true)
                .frame(maxWidth: .infinity, alignment: .leading)
        } else {
            Text("Nothing needs you right now.").itText(.body14).foregroundStyle(it.meta)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        if let st = m.state, m.available {
            ItEmployeeStateLabel(state: st, detail: m.stateReason)
                .fixedSize(horizontal: false, vertical: true)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        if let c = m.count, c > 0, m.available {
            Text("\(c) waiting").itText(.body14, weight: 600).monospacedDigit().foregroundStyle(it.text)
                .fixedSize(horizontal: false, vertical: true)
                .frame(maxWidth: .infinity, alignment: .leading)
                .accessibilityLabel("\(c) waiting")
        }
    }

    private func workspaceHeader(_ m: ItOfficeMember) -> some View {
        HStack(spacing: ItSpace.s3) {
            ItPortrait(name: m.name, url: portraitURL(m.id), state: m.state, size: 44)
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 6) {
                    Text(m.name).itText(.title22, weight: 600).foregroundStyle(it.text)
                    ItAITag()
                }
                Text(m.department).itText(.meta13).foregroundStyle(it.meta)
                if let sub = subtitle(m.id) { Text(sub).itText(.meta13).foregroundStyle(it.meta) }
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(.isHeader)
    }
}

enum ItLoadState: Equatable { case loading, offline(String), error, unavailable }

struct ItLoadStateLine: View {
    let state: ItLoadState
    @Environment(\.it) private var it
    var body: some View {
        HStack(spacing: 6) {
            switch state {
            case .loading:
                ProgressView().controlSize(.small)
                Text("Loading…")
            case .offline(let time):
                ItIcon(shape: .cloudFailed, size: 14, color: it.meta)
                Text("You're offline · showing \(time)")
            case .error:
                ItIcon(shape: .cloudAlert, size: 14, color: it.meta)
                Text("Couldn't reach the office · pull to refresh")
            case .unavailable:
                ItIcon(shape: .slashedCircle, size: 14, color: it.meta)
                Text("Not in the iPhone app yet")
            }
        }
        .itText(.body14).foregroundStyle(it.meta)
        .fixedSize(horizontal: false, vertical: true)
    }
}

/// A head's portrait in a ring: solid / dashed / dotted by employee state; neutral when none is reported.
struct ItPortrait: View {
    let name: String
    let url: URL?
    var state: ItEmployeeState? = nil
    var size: CGFloat = 40
    @Environment(\.it) private var it

    var body: some View {
        let ring = state.map { it.color($0.token) } ?? it.line
        ZStack {
            Circle().fill(it.sunk)
            Text(String(name.prefix(1))).itText(.body14, weight: 600).foregroundStyle(it.meta)
            if let url {
                AsyncImage(url: url) { phase in
                    if let img = phase.image { img.resizable().scaledToFill() } else { Color.clear }
                }
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
        .overlay {
            Circle().strokeBorder(ring, style: StrokeStyle(
                lineWidth: state == .unavailable ? 1 : 2,
                dash: state == .idle ? [3, 3] : (state == .unavailable ? [1, 2] : [])))
        }
        .accessibilityHidden(true)
    }
}
