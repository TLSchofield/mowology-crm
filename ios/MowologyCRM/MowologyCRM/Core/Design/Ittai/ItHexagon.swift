//
//  ItHexagon.swift
//  MowologyCRM
//
//  The live AI Office hexagon (Six + One). Its states come only from server data: an employee with no
//  reported state is drawn neutral, never with an invented state. It is not the logo (that is ItSymbol).
//

import SwiftUI

/// One employee on the map. `state` nil = the server reports none (drawn neutral, no state icon).
struct ItOfficeMember: Identifiable, Equatable {
    let id: String          // "charlie" or a side id
    let name: String
    let department: String
    var state: ItEmployeeState? = nil
    var stateReason: String? = nil
    /// Their single most important item, e.g. "INV-2026-0233 · $546".
    var topItem: String? = nil
    var count: Int? = nil
    var loaded: Bool = true
    var loadFailed: Bool = false
    var loadState: ItLoadState? = nil
    /// False = not reachable from this app (e.g. Ada on the iPhone).
    var available = true
}

struct ItHexagon: View {
    var width: CGFloat = 340
    let charlie: ItOfficeMember
    let sides: [ItOfficeMember]            // any order; placed by id
    @Binding var selected: String?
    var onOpen: (String) -> Void = { _ in }
    @Environment(\.it) private var it
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private var s: CGFloat { width / ItHexagonGeometry.width }

    private func member(_ id: String) -> ItOfficeMember? { sides.first { $0.id == id } }

    private func ink(_ m: ItOfficeMember?) -> Color {
        guard let m, m.available else { return it.color(.stateUnavailable) }
        return m.state.map { it.color($0.token) } ?? it.meta
    }

    var body: some View {
        ZStack(alignment: .topLeading) {
            // Spokes
            ForEach(0..<6, id: \.self) { i in
                let id = ItHexagonGeometry.order[i]
                ItHexagonGeometry.spoke(i, scale: s)
                    .stroke(selected == id || selected == "charlie" ? it.text : it.line,
                            lineWidth: (selected == id || selected == "charlie" ? 1.5 : 1) * s)
            }
            // Sides
            ForEach(0..<6, id: \.self) { i in
                let id = ItHexagonGeometry.order[i], m = member(id)
                let dim = selected != nil && selected != "charlie" && selected != id
                ItHexagonGeometry.side(i, scale: s)
                    .stroke(ink(m), style: StrokeStyle(
                        lineWidth: (m?.available == false ? 1 : (selected == id ? 5 : 3)) * s,
                        lineCap: .round,
                        // idle = dotted: the spec's 8.434% of the side's 70.71-unit path between dots
                        dash: m?.state == .idle ? [0.01, 5.96 * s] : []))
                    .opacity(dim ? 0.25 : 1)
            }
            // Charlie's centre
            ZStack {
                Circle().fill(it.surface)
                Circle().strokeBorder(charlie.state.map { it.color($0.token) } ?? it.meta,
                                      lineWidth: (selected == "charlie" ? 2.5 : 1.5) * s)
                VStack(spacing: 1 * s) {
                    HStack(spacing: 3) {
                        if let st = charlie.state { ItIcon(shape: st.icon, size: 10 * s, color: it.color(st.token)) }
                        Text(charlie.name).font(ItFont.style(.caption12, weight: 600)).foregroundStyle(it.text)
                    }
                    if let c = charlie.count {
                        Text("\(c) in flow").font(ItFont.style(.caption12, weight: 400)).monospacedDigit().foregroundStyle(it.meta)
                    }
                }
            }
            .frame(width: 68 * s, height: 68 * s)
            .position(x: ItHexagonGeometry.centre.x * s, y: ItHexagonGeometry.centre.y * s)
            .allowsHitTesting(false)
            // Labels
            ForEach(0..<6, id: \.self) { i in
                let id = ItHexagonGeometry.order[i], m = member(id)
                HStack(spacing: 4) {
                    if let m, !m.available {
                        ItIcon(shape: .slashedCircle, size: 12, color: it.color(.stateUnavailable))
                    } else if let st = m?.state {
                        ItIcon(shape: st.icon, size: 12, color: it.color(st.token))
                    }
                    Text(m?.name ?? id.capitalized).font(ItFont.style(.caption12, weight: 500)).foregroundStyle(it.text)
                }
                .fixedSize()
                .position(ItHexagonGeometry.labelCentre(i, scale: s))
                .allowsHitTesting(false)
            }
            // VoiceOver: one labelled button per head (Charlie first, then clockwise from Yui). Touches go to
            // the single tap gesture below, so these never take part in hit testing.
            accessibilityButton(id: "charlie", at: ItHexagonGeometry.centre, member: charlie)
                .accessibilitySortPriority(7)
            ForEach(0..<6, id: \.self) { i in
                let id = ItHexagonGeometry.order[i]
                accessibilityButton(id: id, at: ItHexagonGeometry.point(ItHexagonGeometry.normals[i], 79), member: member(id))
                    .accessibilitySortPriority(Double(6 - i))
            }
        }
        .frame(width: width, height: ItHexagonGeometry.height * s, alignment: .topLeading)
        // Labels share fixed symbol geometry, so cap only this diagram's type size.
        .dynamicTypeSize(...DynamicTypeSize.xxxLarge)
        .contentShape(Rectangle())
        .gesture(SpatialTapGesture().onEnded { tap in
            guard let id = ItHexagonGeometry.ownerID(of: tap.location, scale: s) else { return }
            if id != "charlie", member(id)?.available == false { return }
            selected = id
            onOpen(id)
        })
        .animation(reduceMotion ? nil : .easeOut(duration: 0.15), value: selected)
        .accessibilityElement(children: .contain)
        .accessibilityLabel("AI office")
    }

    @ViewBuilder
    private func accessibilityButton(id: String, at c: CGPoint, member m: ItOfficeMember?) -> some View {
        let element = Color.clear
            .frame(width: 48, height: 48)
            .position(x: c.x * s, y: c.y * s)
            .allowsHitTesting(false)
            .accessibilityElement()
            .accessibilityLabel(accessibleName(m, id: id))

        if m?.available == false {
            element
        } else {
            element
                .accessibilityAddTraits(selected == id ? [.isButton, .isSelected] : .isButton)
                .accessibilityAction {
                    selected = id
                    onOpen(id)
                }
        }
    }

    private func accessibleName(_ m: ItOfficeMember?, id: String) -> String {
        guard let m else { return id.capitalized }
        if !m.available { return "\(m.name), \(m.department), unavailable in this app" }
        var parts = ["\(m.name), \(m.department)"]
        if let st = m.state { parts.append("Employee state: \(st.words.lowercased())") }
        if let item = m.topItem { parts.append(item) }
        let opens = id == "charlie" ? "Opens the Chief of Staff workspace." : "Opens \(m.name)'s workspace."
        return parts.joined(separator: ", ") + ". " + (m.available ? opens : "")
    }
}
