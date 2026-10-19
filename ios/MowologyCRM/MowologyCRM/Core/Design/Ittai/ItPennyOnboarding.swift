import SwiftUI
#if canImport(UIKit)
import UIKit
#endif

enum ItPennyOnboardingState: Equatable {
    case opening
    case searching
    case oneMatch(BusinessRegistryMatch)
    case several([BusinessRegistryMatch], selected: BusinessRegistryMatch?)
    case none(manual: Bool)
    case unavailable
    case confirmed(String)
}

struct ItPennyOnboarding: View {
    let state: ItPennyOnboardingState
    let stage: KnowledgeStage?
    let attribution: String?
    let isBusy: Bool
    let canEdit: Bool
    let initialName: String
    var showsOtherMatches = false
    var error: String?
    var contactsState: BusinessContactsState = .idle
    var onStart: (String) -> Void = { _ in }
    var onRetry: () -> Void = {}
    var onShowOtherMatches: () -> Void = {}
    var onSelect: (BusinessRegistryMatch) -> Void = { _ in }
    var onConfirm: (BusinessRegistryMatch) -> Void = { _ in }
    var onManual: (String, String?, String?, String?) -> Void = { _, _, _, _ in }

    @State private var name = ""
    @State private var legalName = ""
    @State private var operatingName = ""
    @State private var legalStructure = ""
    @State private var jurisdiction = "British Columbia"
    @State private var showsLicence = false
    @Environment(\.it) private var it
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    private let structures = ["", "Sole proprietorship", "Partnership", "Corporation", "Society", "Other"]
    private let jurisdictions = ["British Columbia", "Federal", "Alberta", "Other"]
    private var displayedStage: KnowledgeStage { stage ?? .recognized }

    var body: some View {
        VStack(alignment: .leading, spacing: ItSpace.s4) {
            HStack(alignment: .top, spacing: ItSpace.s4) {
                KnowledgeGlyph(state: displayedStage,
                               previousState: displayedStage == .identified ? .recognized : displayedStage,
                               animate: state.isConfirmed,
                               reducedMotion: reduceMotion)
                    .frame(width: 64, height: 64)
                conversation.frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .padding(ItSpace.s4)
        .background(it.surface, in: RoundedRectangle(cornerRadius: ItRadius.lg))
        .foregroundStyle(it.text)
        .onAppear { if name.isEmpty { name = initialName } }
        .onChange(of: spokenLine) { line in announce(line) }
    }

    @ViewBuilder private var conversation: some View {
        VStack(alignment: .leading, spacing: ItSpace.s4) {
            Text(spokenLine).itText(.body16).fixedSize(horizontal: false, vertical: true)
                .accessibilityAddTraits(.isHeader)
            switch state {
            case .opening:
                if let error {
                    Text(error).itText(.body14).foregroundStyle(it.color(.statusBlocked)).accessibilityLabel("Error: \(error)")
                    Button("Retry", action: onRetry).buttonStyle(.plain).itText(.body14, weight: 600)
                        .foregroundStyle(it.brand).frame(minHeight: ItSize.targetPhone)
                } else {
                    field("Business name", text: $name)
                    Button("Continue") { onStart(name.trimmed) }
                        .buttonStyle(ItOnboardingPrimaryButtonStyle()).disabled(name.trimmed.isEmpty || isBusy || !canEdit)
                }
            case .searching:
                ProgressView().tint(it.brand).accessibilityLabel("Looking this up in the BC Registry")
            case .oneMatch(let match): matchActions(match)
            case .several(let matches, let selected):
                if let selected { matchActions(selected) } else { matchList(matches) }
            case .none(let manual):
                if manual { manualForm }
                else { Button("Enter details manually") { prepareManual() }.buttonStyle(ItOnboardingPrimaryButtonStyle()).disabled(isBusy || !canEdit) }
            case .unavailable:
                Button("Try again", action: onRetry).buttonStyle(ItOnboardingPrimaryButtonStyle()).disabled(isBusy)
                secondaryButton("Enter details manually") { prepareManual() }.disabled(isBusy || !canEdit)
            case .confirmed:
                contactsCard
            }
        }
    }

    private var spokenLine: String {
        switch state {
        case .opening: return "Hello! I'm Penny. I'll be looking after your books and helping you keep your business finances organized.\n\nLet's start with something simple.\n\nWhat's your business called?"
        case .searching: return "Looking this up in the BC Registry…"
        case .oneMatch, .several(_, .some): return "I found a possible match. Is this your business?"
        case .several(_, nil): return "I found a few possible matches. Which one is yours?"
        case .none: return "I couldn't find a confident match. That's okay. We can enter your business details ourselves."
        case .unavailable: return "I can't reach the BC Registry right now."
        case .confirmed: return "Perfect — I've identified your registered business. Let me see if I can find your website and contact details."
        }
    }

    @ViewBuilder private func matchActions(_ match: BusinessRegistryMatch) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(displayName(for: match)).itText(.title22, weight: 600).fixedSize(horizontal: false, vertical: true)
            Text("Registered as \(match.legalName)").itText(.body14).foregroundStyle(it.meta).fixedSize(horizontal: false, vertical: true)
        }
        registryCard(match)
        Button(action: { onConfirm(match) }) {
            HStack(spacing: ItSpace.s2) {
                if isBusy { ProgressView().tint(it.mode == .dark ? it.page : it.surface) }
                Text(isBusy ? "Confirming…" : "Yes, that's my business")
            }.frame(maxWidth: .infinity)
        }
        .buttonStyle(ItOnboardingPrimaryButtonStyle()).disabled(isBusy || !canEdit)
        if let error {
            VStack(alignment: .leading, spacing: ItSpace.s1) {
                Text(error).itText(.body14).foregroundStyle(it.color(.statusBlocked)).accessibilityLabel("Error: \(error)")
                Button("Retry") { onConfirm(match) }
                    .buttonStyle(.plain).itText(.body14, weight: 600).foregroundStyle(it.brand).frame(minHeight: ItSize.targetPhone)
            }
        }
        if showsOtherMatches { secondaryButton("Show other matches", action: onShowOtherMatches).disabled(isBusy) }
        secondaryButton("Enter details manually") { prepareManual() }.disabled(isBusy || !canEdit)
    }

    private func matchList(_ matches: [BusinessRegistryMatch]) -> some View {
        VStack(spacing: ItSpace.s2) {
            ForEach(matches.prefix(5)) { match in
                Button { onSelect(match) } label: {
                    HStack {
                        VStack(alignment: .leading, spacing: 3) {
                            Text(match.legalName).itText(.body14, weight: 600)
                            if let op = match.operatingNames.first { Text(op).itText(.caption12).foregroundStyle(it.meta) }
                        }
                        Spacer(); Image(systemName: "chevron.right")
                    }
                    .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone, alignment: .leading)
                    .padding(.horizontal, ItSpace.s3).background(it.sunk, in: RoundedRectangle(cornerRadius: ItRadius.md))
                }.buttonStyle(.plain)
            }
            secondaryButton("Enter details manually") { prepareManual() }.disabled(isBusy || !canEdit)
        }
    }

    private func registryCard(_ match: BusinessRegistryMatch) -> some View {
        let primary = registryRows(match)
        let more = moreRows(match)
        return VStack(alignment: .leading, spacing: 0) {
            ForEach(Array(primary.enumerated()), id: \.offset) { index, row in
                compactRow(row.0, row.1, status: row.0 == "Status")
                if index < primary.count - 1 { Divider().overlay(it.line) }
            }
            if !more.isEmpty {
                Divider().overlay(it.line)
                DisclosureGroup("More details") {
                    VStack(spacing: 0) {
                        ForEach(Array(more.enumerated()), id: \.offset) { index, row in
                            compactRow(row.0, row.1)
                            if index < more.count - 1 { Divider().overlay(it.line) }
                        }
                    }.padding(.top, ItSpace.s1)
                }.itText(.body14, weight: 600).padding(.vertical, ItSpace.s2)
            }
        }
        .padding(.horizontal, ItSpace.s3).background(it.sunk, in: RoundedRectangle(cornerRadius: ItRadius.md))
        .overlay(alignment: .bottomLeading) {
            if match.source == "orgbook_bc" { attributionLine.padding(.top, ItSpace.s2).offset(y: 42) }
        }
        .padding(.bottom, match.source == "orgbook_bc" ? (showsLicence ? 96 : 42) : 0)
    }

    private var attributionLine: some View {
        VStack(alignment: .leading, spacing: ItSpace.s1) {
            HStack(spacing: ItSpace.s1) {
                Text("Source: BC Registries via OrgBook BC").itText(.caption12).foregroundStyle(it.meta)
                    .lineLimit(1).minimumScaleFactor(0.8)
                Button { showsLicence.toggle() } label: {
                    Image(systemName: "info.circle").frame(minWidth: ItSize.targetPhone, minHeight: ItSize.targetPhone)
                }.buttonStyle(.plain).foregroundStyle(it.brand).accessibilityLabel("About the registry information licence")
            }
            if showsLicence {
                Text(attribution?.trimmed.nilIfEmpty
                     ?? "Contains information licensed under the Open Government Licence – British Columbia.")
                    .itText(.caption12).foregroundStyle(it.meta).fixedSize(horizontal: false, vertical: true)
            }
        }
    }

    private func compactRow(_ label: String, _ value: String, status: Bool = false) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: ItSpace.s3) {
            Text(label).itText(.caption12).foregroundStyle(it.meta)
            Spacer(minLength: ItSpace.s2)
            if status { Circle().fill(it.color(.statusComplete)).frame(width: 7, height: 7).accessibilityHidden(true) }
            Text(value).itText(.body14, weight: 600).multilineTextAlignment(.trailing)
        }.padding(.vertical, ItSpace.s2)
    }

    private func registryRows(_ match: BusinessRegistryMatch) -> [(String, String)] {
        [("Registration", match.registrationNumber), ("Status", match.status), ("Jurisdiction", match.jurisdiction)].compactMap { label, value in
            value?.trimmed.nilIfEmpty.map { (label, $0) }
        }
    }

    private func moreRows(_ match: BusinessRegistryMatch) -> [(String, String)] {
        [("Legal structure", match.legalStructure), ("Registration type", match.registrationType), ("Registered on", match.registeredOn)].compactMap { label, value in
            value?.trimmed.nilIfEmpty.map { (label, $0) }
        }
    }

    private func displayName(for match: BusinessRegistryMatch) -> String {
        if let operating = match.operatingNames.first(where: { !$0.matches(match.legalName) }) { return operating }
        if !initialName.trimmed.isEmpty, !initialName.matches(match.legalName) { return initialName.trimmed }
        return match.legalName
    }

    @ViewBuilder private var contactsCard: some View {
        VStack(alignment: .leading, spacing: ItSpace.s3) {
            Text("Contact details").itText(.heading16)
            switch contactsState {
            case .idle, .loading:
                ProgressView().tint(it.brand).accessibilityLabel("Looking for website and contact details")
            case .missing:
                Text("I'll look for your website and contact details next.").itText(.body14).foregroundStyle(it.meta)
            case .failed:
                Text("I couldn't check contact details right now.").itText(.body14).foregroundStyle(it.meta)
            case .available(let contacts):
                if contacts.isEmpty { Text("I'll look for your website and contact details next.").itText(.body14).foregroundStyle(it.meta) }
                ForEach(contacts) { contact in contactRow(contact) }
                if !contacts.isEmpty {
                    Text("I'll go through these with you in the next step.").itText(.body14).foregroundStyle(it.meta)
                }
            }
        }.padding(ItSpace.s3).background(it.sunk, in: RoundedRectangle(cornerRadius: ItRadius.md))
    }

    private func contactRow(_ contact: BusinessContactSuggestion) -> some View {
        VStack(alignment: .leading, spacing: ItSpace.s2) {
            Text(contact.field.label).itText(.caption12).foregroundStyle(it.meta)
            Text(contact.value).itText(.body14, weight: 600).fixedSize(horizontal: false, vertical: true)
            Text("From your settings").itText(.caption12).foregroundStyle(it.meta)
        }.padding(.vertical, ItSpace.s1)
    }

    @ViewBuilder private var manualForm: some View {
        field("Legal name", text: $legalName)
        field("Operating name (optional)", text: $operatingName)
        Picker("Legal structure", selection: $legalStructure) { ForEach(structures, id: \.self) { Text($0.isEmpty ? "Not specified" : $0) } }.frame(minHeight: ItSize.targetPhone)
        Picker("Jurisdiction", selection: $jurisdiction) { ForEach(jurisdictions, id: \.self) { Text($0) } }.frame(minHeight: ItSize.targetPhone)
        Button("Save business details") {
            onManual(legalName.trimmed, operatingName.trimmed.nilIfEmpty, legalStructure.nilIfEmpty, jurisdiction.nilIfEmpty)
        }.buttonStyle(ItOnboardingPrimaryButtonStyle()).disabled(legalName.trimmed.isEmpty || isBusy || !canEdit)
    }

    private func field(_ title: String, text: Binding<String>) -> some View {
        TextField(title, text: text).itText(.body16).padding(.horizontal, ItSpace.s3).frame(minHeight: ItSize.targetPhone)
            .background(it.sunk, in: RoundedRectangle(cornerRadius: ItRadius.md))
            .overlay(RoundedRectangle(cornerRadius: ItRadius.md).stroke(it.line, lineWidth: ItStroke.hairline))
    }

    private func secondaryButton(_ title: String, action: @escaping () -> Void) -> some View {
        Button(title, action: action).buttonStyle(.plain).itText(.body16, weight: 600).foregroundStyle(it.brand)
            .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone)
    }

    private func prepareManual() { legalName = legalName.isEmpty ? name : legalName; onSelect(Self.manualSentinel) }
    static let manualSentinel: BusinessRegistryMatch = decodeMatch(["record_id":"__manual__", "legal_name":"Manual entry"])
    static let licenceURL = URL(string: "https://www2.gov.bc.ca/gov/content/data/policy-standards/open-data/open-government-licence-bc")!

    private func announce(_ line: String) {
        #if canImport(UIKit)
        UIAccessibility.post(notification: .announcement, argument: line)
        #endif
    }
}

struct ItBusinessIdentityRow: View {
    let identity: BusinessIdentity
    let canEdit: Bool
    let onChange: () -> Void
    @Environment(\.it) private var it
    var body: some View {
        HStack(alignment: .top, spacing: ItSpace.s3) {
            Image(systemName: "building.2").frame(width: ItSize.targetPhone, height: ItSize.targetPhone).background(it.sunk, in: Circle())
            VStack(alignment: .leading, spacing: 3) {
                Text("Your business").itText(.caption12, weight: 600).foregroundStyle(it.meta)
                Text(identity.legalName ?? identity.enteredName ?? "Business").itText(.body16, weight: 600)
                if let op = identity.operatingName, op != identity.legalName { Text(op).itText(.body14).foregroundStyle(it.meta) }
                Text(Array(Set(identity.provenance.values.map(\.words))).sorted().joined(separator: " · ")).itText(.caption12).foregroundStyle(it.meta)
            }
            Spacer()
            Button("Change", action: onChange).buttonStyle(.it(.ghost)).disabled(!canEdit)
        }.padding(ItSpace.s3).background(it.surface, in: RoundedRectangle(cornerRadius: ItRadius.lg)).foregroundStyle(it.text)
    }
}

struct KnowledgeGlyph: View {
    let state: KnowledgeStage
    let previousState: KnowledgeStage
    let animate: Bool
    let reducedMotion: Bool
    @Environment(\.it) private var it
    @State private var visualState: KnowledgeStage

    init(state: KnowledgeStage, previousState: KnowledgeStage, animate: Bool, reducedMotion: Bool) {
        self.state = state; self.previousState = previousState; self.animate = animate; self.reducedMotion = reducedMotion
        _visualState = State(initialValue: animate && !reducedMotion ? previousState : state)
    }

    var body: some View {
        ZStack {
            if visualState == .recognized || visualState == .identified {
                ForEach(0..<3, id: \.self) { piece in
                    Capsule().fill(it.color(.knowledgeDiscovered)).frame(width: 37, height: 2)
                        .rotationEffect(angle(piece, state: visualState))
                        .offset(offset(piece, state: visualState))
                }
            } else {
                ItKnowledgeTriangleShape(inset: ItKnowledgeGeometry.triangleInset)
                    .fill(it.color(visualState == .understood ? .knowledgeUnderstood : .knowledgeMastered))
                if visualState == .mastered { ItKnowledgeTriangleShape(inset: 0.18).stroke(it.page, lineWidth: 1) }
            }
        }
        .padding(6)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Business identity \(state.rawValue).")
        .onAppear { move(to: state) }
        .onChange(of: state) { move(to: $0) }
    }

    private func move(to newState: KnowledgeStage) {
        guard visualState != newState else { return }
        if reducedMotion || !animate { visualState = newState }
        else { withAnimation(.easeInOut(duration: 1.2)) { visualState = newState } }
    }

    private func angle(_ piece: Int, state: KnowledgeStage) -> Angle {
        let final: [Double] = [0, -60, 60]
        let loose: [Double] = [-5, -53, 67]
        return .degrees((state == .identified ? final : loose)[piece])
    }

    private func offset(_ piece: Int, state: KnowledgeStage) -> CGSize {
        let final = [CGSize(width: 0, height: 18), CGSize(width: -16, height: -9), CGSize(width: 16, height: -9)]
        let loose = [CGSize(width: -3, height: 20), CGSize(width: -20, height: -5), CGSize(width: 20, height: -13)]
        return (state == .identified ? final : loose)[piece]
    }
}

private struct ItOnboardingPrimaryButtonStyle: ButtonStyle {
    var fullWidth = true
    @Environment(\.it) private var it
    @Environment(\.isEnabled) private var enabled
    func makeBody(configuration: Configuration) -> some View {
        configuration.label.itText(.body16, weight: 600).padding(.horizontal, ItSpace.s4)
            .frame(minHeight: ItSize.targetPhone).frame(maxWidth: fullWidth ? .infinity : nil)
            .foregroundStyle(it.mode == .dark ? it.page : it.surface)
            .background(it.brand, in: RoundedRectangle(cornerRadius: ItRadius.md))
            .opacity(enabled ? (configuration.isPressed ? 0.8 : 1) : 0.55)
    }
}

private func decodeMatch(_ object: [String: Any]) -> BusinessRegistryMatch {
    try! JSONDecoder().decode(BusinessRegistryMatch.self, from: JSONSerialization.data(withJSONObject: object))
}

private extension ItPennyOnboardingState {
    var isConfirmed: Bool { if case .confirmed = self { return true }; return false }
}

private extension BusinessContactField {
    var label: String { rawValue.capitalized }
}

private extension String {
    var trimmed: String { trimmingCharacters(in: .whitespacesAndNewlines) }
    var nilIfEmpty: String? { isEmpty ? nil : self }
    func matches(_ other: String) -> Bool { trimmed.caseInsensitiveCompare(other.trimmed) == .orderedSame }
}
