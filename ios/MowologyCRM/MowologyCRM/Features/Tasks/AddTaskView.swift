//
//  AddTaskView.swift
//  MowologyCRM
//
//  Add → Task (Tim, 2026-11-08): a task for a client, or for the business itself ("pick up salt"). Works with no
//  signal: it goes through CreateOutbox, picks from the phone's copy of the people and clients (OfflineDirectory),
//  and says plainly whether it was saved or is waiting on the phone.
//

import SwiftUI

struct AddTaskView: View {
    let authSession: AuthSession

    @ObservedObject private var directory = OfflineDirectory.shared
    @ObservedObject private var outbox = CreateOutbox.shared
    @Environment(\.dismiss) private var dismiss
    @Environment(\.itAppStyle) private var appStyle

    @State private var title = ""
    @State private var note = ""
    @State private var forClient = false
    @State private var client: DirectoryClient?
    @State private var hasDue = false
    @State private var due = Date()
    @State private var assignee: Int?
    @State private var saving = false
    @State private var errorMessage: String?
    @State private var waitingMessage = false
    /// Made once per task, so a second tap or a resend can't make a second one.
    @State private var requestId = UUID().uuidString

    private var canSave: Bool {
        !title.trimmingCharacters(in: .whitespaces).isEmpty && !saving && (!forClient || client != nil)
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("What needs doing?", text: $title, axis: .vertical)
                    TextField("Note (optional)", text: $note, axis: .vertical)
                }
                Section("For") {
                    Picker("For", selection: $forClient) {
                        Text("The business").tag(false)
                        Text("A client").tag(true)
                    }
                    .pickerStyle(.segmented)
                    .labelsHidden()
                    if forClient {
                        NavigationLink {
                            ClientPicker(selection: $client)
                        } label: {
                            LabeledContent("Client") {
                                Text(client?.name ?? "Choose").foregroundStyle(client == nil ? appStyle.meta : appStyle.text)
                            }
                        }
                        if let client {
                            Text(client.address).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                        }
                    }
                }
                Section {
                    Toggle("Due date", isOn: $hasDue.animation()).tint(appStyle.tenant)
                    if hasDue { DatePicker("Due", selection: $due, displayedComponents: .date) }
                    if directory.people.count > 1 {
                        Picker("Who", selection: $assignee) {
                            ForEach(directory.people) { Text($0.name).tag(Optional($0.id)) }
                        }
                    }
                }
                if forClient && directory.clients.isEmpty {
                    Section {
                        Text(directory.savedAt == nil
                             ? "The client list hasn't reached this phone yet. Open this once with signal, or make it a task for the business."
                             : "Your account doesn't include the client list, so make it a task for the business.")
                            .font(appStyle.font(.footnote)).foregroundStyle(appStyle.meta)
                    }
                }
            }
            .itAppForm()
            .itAppScreen()
            .navigationTitle("Add a task")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button(saving ? "Saving…" : "Save") { Task { await save() } }.disabled(!canSave)
                }
            }
            .task {
                let api = APIClient(authSession: authSession)
                await directory.load(using: api)
                if assignee == nil { assignee = api.currentUserId }
            }
            .alert("Couldn't save", isPresented: Binding(get: { errorMessage != nil }, set: { if !$0 { errorMessage = nil } })) {
                Button("OK", role: .cancel) {}
            } message: { Text(errorMessage ?? "") }
            .alert("Saved on this phone", isPresented: $waitingMessage) {
                Button("OK") { dismiss() }
            } message: { Text("No signal right now. The task goes through when you're back online.") }
        }
    }

    private func save() async {
        saving = true
        defer { saving = false }
        let api = APIClient(authSession: authSession)
        var body: [String: Any] = ["title": title.trimmingCharacters(in: .whitespaces),
                                   "description": note.trimmingCharacters(in: .whitespaces),
                                   "client_request_id": requestId]
        if hasDue {
            let f = DateFormatter(); f.calendar = Calendar(identifier: .gregorian); f.locale = Locale(identifier: "en_US_POSIX")
            f.dateFormat = "yyyy-MM-dd"
            body["due_date"] = f.string(from: due)
        }
        if let assignee { body["assigned_to"] = assignee }
        if forClient, let client {
            body["contact_id"] = client.contactId
            body["property_id"] = client.propertyId
        }
        let label = title.trimmingCharacters(in: .whitespaces)
        guard outbox.enqueue(kind: .task, userId: api.currentUserId, title: "Task: \(label)", body: body) else {
            errorMessage = "It couldn't be kept on this phone. Nothing was saved."
            return
        }
        await outbox.drain(using: api)
        guard let entry = outbox.entries.first(where: { $0.id == requestId }) else {
            dismiss()   // sent
            return
        }
        if let reason = entry.refused {
            // The form is still open, so the fix happens here, not in the sync list.
            outbox.discard(entry.id)
            requestId = UUID().uuidString
            errorMessage = reason
        } else {
            waitingMessage = true
        }
    }
}

private struct ClientPicker: View {
    @Binding var selection: DirectoryClient?
    @ObservedObject private var directory = OfflineDirectory.shared
    @Environment(\.dismiss) private var dismiss
    @Environment(\.itAppStyle) private var appStyle
    @State private var query = ""

    var body: some View {
        List(directory.matches(query)) { c in
            Button {
                selection = c
                dismiss()
            } label: {
                VStack(alignment: .leading, spacing: 2) {
                    Text(c.name).font(appStyle.font(.subheadline, weight: .semibold)).foregroundStyle(appStyle.text)
                    Text(c.address).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                }
            }
        }
        .searchable(text: $query, placement: .navigationBarDrawer(displayMode: .always), prompt: "Name or address")
        .navigationTitle("Choose a client")
        .navigationBarTitleDisplayMode(.inline)
    }
}
