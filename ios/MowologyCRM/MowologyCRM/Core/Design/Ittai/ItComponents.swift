//
//  ItComponents.swift
//  MowologyCRM
//
//  Ittai v1.2 base parts: buttons (one dominant action per decision), rows grouped by hairlines,
//  task statuses vs employee states (two vocabularies, one icon set), sync labels and the AI tag.
//  Rules: tokens only, status = icon + word (never colour alone), 48 pt phone targets.
//

import SwiftUI

// MARK: - Buttons

enum ItButtonKind { case primary, outline, ghost }

/// Primary = filled tenant colour (only the top-ranked/urgent action); outline = lower-ranked; ghost = "Not now".
struct ItButtonStyle: ButtonStyle {
    var kind: ItButtonKind = .primary
    var fullWidth = false
    @Environment(\.it) private var it
    @Environment(\.isEnabled) private var enabled

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .itText(.body16, weight: kind == .ghost ? 500 : 600)
            .fixedSize(horizontal: false, vertical: true)
            .padding(.horizontal, ItSpace.s4)
            .frame(minHeight: ItSize.targetPhone)
            .frame(maxWidth: fullWidth ? .infinity : nil)
            .foregroundStyle(kind == .primary ? it.onTenant : (kind == .outline ? it.tenant : it.meta))
            .background {
                RoundedRectangle(cornerRadius: ItRadius.md)
                    .fill(kind == .primary ? it.tenant : Color.clear)
            }
            .overlay {
                if kind == .outline {
                    RoundedRectangle(cornerRadius: ItRadius.md).strokeBorder(it.tenant, lineWidth: 1)
                }
            }
            .opacity(enabled ? (configuration.isPressed ? 0.8 : 1) : 0.55)
            .contentShape(RoundedRectangle(cornerRadius: ItRadius.md))
    }
}

extension ButtonStyle where Self == ItButtonStyle {
    static func it(_ kind: ItButtonKind = .primary, fullWidth: Bool = false) -> ItButtonStyle {
        ItButtonStyle(kind: kind, fullWidth: fullWidth)
    }
}

// MARK: - Task status (business tasks) — never an employee word

enum ItTaskStatus: String, CaseIterable {
    case proposed, attention, approving, pending, checking, approved, sending, sent, failed
    case noLongerNeeded = "no-longer-needed", permissionDenied = "permission-denied", snoozed, declined

    var words: String {
        switch self {
        case .proposed: return "Needs your approval"
        case .attention: return "Needs attention"
        case .approving: return "Approving…"
        case .pending: return "Approval saved on phone · will send when online"
        case .checking: return "Checking…"
        case .approved: return "Approved · recorded"
        case .sending: return "Sending…"
        case .sent: return "Sent · server confirmed"
        case .failed: return "Couldn't send"
        case .noLongerNeeded: return "Not sent: no longer needed"
        case .permissionDenied: return "Not approved: you no longer have permission"
        case .snoozed: return "Snoozed"
        case .declined: return "Declined"
        }
    }

    var icon: ItIconShape {
        switch self {
        case .proposed: return .triangle
        case .attention, .snoozed: return .clock
        case .approving, .checking, .sending: return .cloudSending
        case .pending: return .cloudSaved
        case .approved: return .check
        case .sent: return .cloudSent
        case .failed, .permissionDenied: return .octagon
        case .noLongerNeeded: return .cloudAlert
        case .declined: return .dashedCircle
        }
    }

    var token: ItColorToken {
        switch self {
        case .proposed, .attention, .snoozed: return .statusAttention
        case .approving, .checking, .sending: return .syncSending
        case .pending: return .syncSaved
        case .approved: return .statusComplete
        case .sent: return .syncSent
        case .failed, .permissionDenied: return .statusBlocked
        case .noLongerNeeded: return .syncConflict
        case .declined: return .meta
        }
    }
}

/// "▲ Needs your approval" — accessible name "Task: Needs your approval[ · detail]".
struct ItTaskStatusLabel: View {
    let status: ItTaskStatus
    var detail: String? = nil
    @Environment(\.it) private var it

    var body: some View {
        HStack(spacing: 5) {
            ItIcon(shape: status.icon, size: 14, color: it.color(status.token))
            Text(detail.map { "\(status.words) · \($0)" } ?? status.words)
        }
        .itText(.meta13, weight: 500)
        .foregroundStyle(it.color(status.token))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Task: " + (detail.map { "\(status.words), \($0)" } ?? status.words))
    }
}

// MARK: - Employee state (AI employees) — only from the employee record, never guessed

enum ItEmployeeState: String, CaseIterable {
    case working, approval, blocked, idle, unavailable

    var words: String {
        switch self {
        case .working: return "Working"
        case .approval: return "Waiting for approval"
        case .blocked: return "Blocked"
        case .idle: return "Idle"
        case .unavailable: return "Unavailable"
        }
    }
    var icon: ItIconShape {
        switch self {
        case .working: return .clock
        case .approval: return .triangle
        case .blocked: return .octagon
        case .idle: return .dashedCircle
        case .unavailable: return .slashedCircle
        }
    }
    var token: ItColorToken {
        switch self {
        case .working: return .stateWorking
        case .approval: return .stateApproval
        case .blocked: return .stateBlocked
        case .idle: return .stateIdle
        case .unavailable: return .stateUnavailable
        }
    }
}

struct ItEmployeeStateLabel: View {
    let state: ItEmployeeState
    var detail: String? = nil
    @Environment(\.it) private var it

    private var words: String {
        guard let detail = detail?.trimmingCharacters(in: .whitespacesAndNewlines), !detail.isEmpty else {
            return state.words
        }
        return "\(state.words) · \(detail)"
    }

    var body: some View {
        HStack(spacing: 4) {
            ItIcon(shape: state.icon, size: 12, color: it.color(state.token))
            Text(words)
        }
        .itText(.caption12)
        .foregroundStyle(it.color(state.token))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Employee state: " + words.replacingOccurrences(of: " · ", with: ", "))
    }
}

// MARK: - Small parts

/// Every AI employee is visibly marked AI.
struct ItAITag: View {
    @Environment(\.it) private var it
    var body: some View {
        Text("AI")
            .itText(.caption12, weight: 500)
            .foregroundStyle(it.meta)
            .padding(.horizontal, 5)
            .overlay(RoundedRectangle(cornerRadius: ItRadius.sm).strokeBorder(it.line, lineWidth: 1))
            .accessibilityLabel("AI employee")
    }
}

/// A section: heading, optional note, then rows separated by hairlines. Rows before cards.
struct ItSection<Content: View>: View {
    let title: String
    var note: String? = nil
    @ViewBuilder var content: Content
    @Environment(\.it) private var it
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        VStack(alignment: .leading, spacing: ItSpace.s3) {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: ItSpace.s1) {
                    sectionTitle
                    if let note {
                        Text(note).itText(.meta13).foregroundStyle(it.meta)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            } else {
                HStack(alignment: .firstTextBaseline) {
                    sectionTitle
                    Spacer(minLength: ItSpace.s2)
                    if let note { Text(note).itText(.meta13).foregroundStyle(it.meta) }
                }
            }
            VStack(spacing: 0) {
                Rectangle().fill(it.line).frame(height: 1)
                content
            }
            .background(it.surface)
        }
    }

    private var sectionTitle: some View {
        Text(title).itText(.heading18).foregroundStyle(it.text)
            .accessibilityAddTraits(.isHeader)
    }
}

/// A row with a hairline under it. Tap target at least 48 pt.
struct ItRow<Content: View>: View {
    var selected = false
    @ViewBuilder var content: Content
    @Environment(\.it) private var it

    var body: some View {
        VStack(spacing: 0) {
            content
                .padding(.vertical, ItSpace.s2)
                .padding(.horizontal, ItSpace.s4)
                .frame(maxWidth: .infinity, minHeight: 64, alignment: .leading)
                .background(selected ? it.selected : Color.clear)
            Rectangle().fill(it.line).frame(height: 1)
        }
    }
}
