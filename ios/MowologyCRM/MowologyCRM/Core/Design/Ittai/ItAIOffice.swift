//
//  ItAIOffice.swift
//  MowologyCRM
//
//  The AI Office screen (Ittai v1.2, phone model): Charlie's one-line brief, the Six + One hexagon,
//  the seven rows (Charlie, then clockwise from Yui) with each head's most important item, then the
//  selected head's workspace. Pure SwiftUI: the Team tab feeds it real data; the Mac render feeds it samples.
//

import SwiftUI

struct ItAIOfficeView<Workspace: View>: View {
    let brief: String?
    let briefTime: String?
    let charlie: ItOfficeMember
    let sides: [ItOfficeMember]
    @Binding var selected: String?
    var portraitURL: (String) -> URL? = { _ in nil }
    /// Optional line under a head's name in the workspace header (e.g. their archetype).
    var subtitle: (String) -> String? = { _ in nil }
    @ViewBuilder var workspace: (String) -> Workspace
    @Environment(\.it) private var it

    private var rows: [ItOfficeMember] {
        [charlie] + ItHexagonGeometry.order.compactMap { id in sides.first { $0.id == id } }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: ItSpace.s8) {
            briefBlock
            ItSection(title: "AI office", note: "Six specialists, coordinated by Charlie") {
                VStack(spacing: ItSpace.s4) {
                    ItHexagon(width: 340, charlie: charlie, sides: sides, selected: $selected)
                        .frame(maxWidth: .infinity)
                        .padding(.top, ItSpace.s4)
                    VStack(spacing: 0) {
                        Rectangle().fill(it.line).frame(height: 1)
                        ForEach(rows) { m in row(m) }
                    }
                }
            }
            if let id = selected, let m = rows.first(where: { $0.id == id }), m.available {
                VStack(alignment: .leading, spacing: ItSpace.s4) {
                    workspaceHeader(m)
                    workspace(id)
                }
                .id("it-workspace-\(id)")
            }
        }
    }

    private var briefBlock: some View {
        HStack(alignment: .top, spacing: ItSpace.s3) {
            ItPortrait(name: charlie.name, url: portraitURL("charlie"), state: charlie.state, size: 44)
            VStack(alignment: .leading, spacing: ItSpace.s1) {
                Text(briefTime.map { "Charlie's brief · \($0)" } ?? "Charlie's brief")
                    .itText(.meta13, weight: 600).foregroundStyle(it.meta)
                Text(charlieBrief)
                    .itText(.title22).foregroundStyle(it.text)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
        .accessibilityElement(children: .combine)
    }

    private var charlieBrief: String {
        if !charlie.loaded { return "Charlie is gathering the day." }
        if charlie.loadFailed { return "Couldn't load Charlie's brief." }
        return brief ?? "Charlie is gathering the day."
    }

    private func row(_ m: ItOfficeMember) -> some View {
        Button {
            if m.available { selected = m.id }
        } label: {
            ItRow(selected: selected == m.id) {
                HStack(alignment: .center, spacing: ItSpace.s3) {
                    ItPortrait(name: m.name, url: portraitURL(m.id), state: m.available ? m.state : .unavailable, size: 40)
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 6) {
                            Text(m.name).itText(.body16, weight: 600).foregroundStyle(it.text)
                            ItAITag()
                            Text(m.department).itText(.meta13).foregroundStyle(it.meta).lineLimit(1)
                        }
                        if !m.available {
                            ItEmployeeStateLabel(state: .unavailable, detail: "not in the iPhone app yet")
                        } else if !m.loaded {
                            Text("Loading…").itText(.body14).foregroundStyle(it.meta)
                        } else if m.loadFailed {
                            Text("Couldn't load · pull to refresh").itText(.body14).foregroundStyle(it.meta)
                        } else if let item = m.topItem {
                            Text(item).itText(.body14).foregroundStyle(it.text)
                                .monospacedDigit()
                                .fixedSize(horizontal: false, vertical: true)
                        } else {
                            Text("Nothing waiting").itText(.body14).foregroundStyle(it.meta)
                        }
                        if let st = m.state, m.available { ItEmployeeStateLabel(state: st) }
                    }
                    Spacer(minLength: ItSpace.s2)
                    if let c = m.count, c > 0, m.available {
                        Text("\(c)").itText(.body14, weight: 600).monospacedDigit().foregroundStyle(it.text)
                            .accessibilityLabel("\(c) waiting")
                    }
                }
            }
        }
        .buttonStyle(.plain)
        // Not .disabled: that greys the text below readable contrast. An unavailable head is information.
        .allowsHitTesting(m.available)
        .accessibilityElement(children: .combine)
        .accessibilityHint(m.available ? "Opens \(m.name)'s workspace" : "")
        .accessibilityAddTraits(selected == m.id ? .isSelected : [])
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
