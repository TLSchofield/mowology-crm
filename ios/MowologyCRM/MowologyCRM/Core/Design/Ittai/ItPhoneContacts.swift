import SwiftUI
#if canImport(UIKit)
import UIKit
#endif

struct ItPhoneContacts: View {
    @ObservedObject var viewModel: PhoneContactsViewModel
    @Environment(\.it) private var it
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.openURL) private var openURL

    var body: some View {
        if viewModel.phase != .hidden || viewModel.summaryCount != nil {
            VStack(alignment: .leading, spacing: ItSpace.s4) {
                content
            }
            .padding(ItSpace.s4)
            .background(it.surface, in: RoundedRectangle(cornerRadius: ItRadius.lg))
            .foregroundStyle(it.text)
            .onChange(of: viewModel.phase) { announce(announcement) }
        }
    }

    @ViewBuilder private var content: some View {
        switch viewModel.phase {
        case .hidden:
            if let count = viewModel.summaryCount {
                Text("\(count) clients from your phone").itText(.body16, weight: 600)
                    .accessibilityLabel("\(count) clients added from your phone")
            }
        case .loading:
            ProgressView("Checking the next step").tint(it.brand)
        case .intro:
            heading("Do you keep your clients in your phone? Pick the ones you work for.")
            Text("Only the contacts you choose leave your phone. They arrive with no marketing permission, so Ittai never emails or texts them until they say yes.")
                .itText(.body14).foregroundStyle(it.meta).fixedSize(horizontal: false, vertical: true)
            primary("Choose clients") { Task { await viewModel.chooseClients() } }
            ghost("Not now") { Task { await viewModel.skip() } }
        case .requestingPermission:
            ProgressView("Waiting for Contacts permission").tint(it.brand)
        case .denied:
            heading("Ittai can't see your contacts. You can turn that on in Settings.")
            primary("Open Settings") {
                if let url = URL(string: UIApplication.openSettingsURLString) { openURL(url) }
            }
            ghost("Not now") { Task { await viewModel.skip() } }
        case .picking:
            picker
        case .sending:
            ProgressView(value: Double(viewModel.sentCount), total: Double(max(viewModel.totalToSend, 1)))
                .tint(it.brand)
            heading("Adding \(min(viewModel.sentCount + 1, viewModel.totalToSend)) of \(viewModel.totalToSend)…")
        case .done:
            heading("Added \(viewModel.tally.created) clients. \(viewModel.tally.matched) were already in Ittai.")
            primary("Continue") { Task { await viewModel.finish() } }
        case .failed:
            heading("I couldn't finish adding these clients.")
            if let error = viewModel.error {
                Text(error).itText(.body14).foregroundStyle(it.color(.statusBlocked)).accessibilityLabel("Error: \(error)")
            }
            primary("Try again") { Task { await viewModel.retry() } }
            ghost("Not now") { Task { await viewModel.skip() } }
        }
    }

    private var picker: some View {
        VStack(alignment: .leading, spacing: ItSpace.s3) {
            ViewThatFits(in: .horizontal) {
                HStack {
                    heading("\(viewModel.selected.count) picked")
                    Spacer(minLength: ItSpace.s2)
                    selectionButtons
                }
                VStack(alignment: .leading, spacing: ItSpace.s2) {
                    heading("\(viewModel.selected.count) picked")
                    selectionButtons
                }
            }
            TextField("Search contacts", text: $viewModel.search)
                .textFieldStyle(.plain).itText(.body16).padding(.horizontal, ItSpace.s3)
                .frame(minHeight: ItSize.targetPhone)
                .background(it.sunk, in: RoundedRectangle(cornerRadius: ItRadius.md))
                .overlay(RoundedRectangle(cornerRadius: ItRadius.md).stroke(it.line, lineWidth: ItStroke.hairline))
                .accessibilityLabel("Search contacts")
            ScrollView {
                LazyVStack(alignment: .leading, spacing: ItSpace.s1) {
                    ForEach(viewModel.shownContacts) { contact in row(contact) }
                }
            }
            .frame(maxHeight: dynamicTypeSize.isAccessibilitySize ? ItSpace.s16 * 8 : ItSpace.s16 * 5)
            primary("Add \(viewModel.selected.count) clients") { Task { await viewModel.sendSelected() } }
                .disabled(viewModel.selected.isEmpty)
            ghost("Not now") { Task { await viewModel.skip() } }
        }
    }

    private var selectionButtons: some View {
        HStack(spacing: ItSpace.s3) {
            Button("Select all shown", action: viewModel.selectAllShown).buttonStyle(.plain).itText(.body14, weight: 600).foregroundStyle(it.brand)
                .frame(minHeight: ItSize.targetPhone)
            Button("Clear", action: viewModel.clearShown).buttonStyle(.plain).itText(.body14, weight: 600).foregroundStyle(it.brand)
                .frame(minHeight: ItSize.targetPhone)
        }
    }

    private func row(_ contact: PhoneContact) -> some View {
        let selected = viewModel.selected.contains(contact.id)
        return Button { viewModel.toggle(contact) } label: {
            HStack(spacing: ItSpace.s3) {
                Image(systemName: selected ? "checkmark.circle.fill" : "circle")
                    .foregroundStyle(selected ? it.brand : it.meta).accessibilityHidden(true)
                VStack(alignment: .leading, spacing: ItSpace.s1) {
                    Text(PhoneContactsLogic.displayName(contact)).itText(.body16, weight: 600)
                    if let detail = detail(contact) { Text(detail).itText(.body14).foregroundStyle(it.meta).lineLimit(1) }
                }
                Spacer(minLength: 0)
            }
            .frame(maxWidth: .infinity, minHeight: ItSize.targetPhone, alignment: .leading)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel([PhoneContactsLogic.displayName(contact), detail(contact)].compactMap { $0 }.joined(separator: ", "))
        .accessibilityValue(selected ? "Selected" : "Not selected")
        .accessibilityHint("Double-tap to \(selected ? "clear" : "select")")
    }

    private func detail(_ contact: PhoneContact) -> String? {
        if let phone = contact.phones.first(where: { $0.label == "mobile" || $0.label == "iphone" }) ?? contact.phones.first { return phone.number }
        if let email = contact.emails.first { return email }
        guard let city = contact.address?.city.trimmingCharacters(in: .whitespacesAndNewlines), !city.isEmpty else { return nil }
        return city
    }

    private func heading(_ value: String) -> some View {
        Text(value).itText(.body16, weight: 600).fixedSize(horizontal: false, vertical: true).accessibilityAddTraits(.isHeader)
    }

    private func primary(_ title: String, action: @escaping () -> Void) -> some View {
        Button(title, action: action).buttonStyle(.it(.primary)).frame(maxWidth: .infinity)
    }

    private func ghost(_ title: String, action: @escaping () -> Void) -> some View {
        Button(title, action: action).buttonStyle(.it(.ghost)).frame(maxWidth: .infinity)
    }

    private var announcement: String {
        switch viewModel.phase {
        case .picking: return "Contacts are ready to choose. Nothing is selected."
        case .sending: return "Adding selected clients."
        case .done: return "Clients added."
        case .denied: return "Contacts access is off."
        case .failed: return viewModel.error.map { "Error: \($0)" } ?? "Adding clients failed."
        default: return ""
        }
    }

    private func announce(_ value: String) {
        #if canImport(UIKit)
        guard !value.isEmpty else { return }
        UIAccessibility.post(notification: .announcement, argument: value)
        #endif
    }
}

#if DEBUG
struct PhoneContactsDemoHost: View {
    @StateObject private var viewModel = PhoneContactsViewModel(api: DemoPhoneContactsAPI(), reader: DemoPhoneContactsReader())
    var body: some View {
        ScrollView { ItPhoneContacts(viewModel: viewModel).padding(ItSpace.s4) }
            .ittaiTheme(field: false)
            .task { await viewModel.load() }
    }
}
#endif
