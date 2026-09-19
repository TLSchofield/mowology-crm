//
//  VisitWorkViewModel.swift
//  MowologyCRM
//

import Foundation
import UIKit

@MainActor
final class VisitWorkViewModel: ObservableObject {

    @Published var checklist: [ChecklistItem] = []
    @Published var materials: [MaterialItem]  = []
    @Published private(set) var notes: [VisitNote] = []
    @Published private(set) var isLocked  = false
    @Published private(set) var isLoading = false
    @Published private(set) var isSaving  = false
    @Published var errorMessage: String?

    /// True when the checklist or materials on screen differ from what the server
    /// last confirmed — i.e. a save failed (usually no signal) and needs a retry.
    @Published private(set) var hasUnsavedChecklist = false
    @Published private(set) var hasUnsavedMaterials = false

    let visitId: Int
    private let apiClient: APIClient
    private let haptic = UINotificationFeedbackGenerator()
    private var checklistSaveTask: Task<Void, Never>?

    init(visitId: Int, authSession: AuthSession) {
        self.visitId   = visitId
        self.apiClient = APIClient(authSession: authSession)
    }

    var checkedCount: Int { checklist.filter(\.checked).count }

    // MARK: - Load

    func load() async {
        isLoading    = true
        errorMessage = nil
        do {
            let state: VisitWorkState = try await apiClient.request(.visitWork(visitId: visitId))
            apply(state, keepLocalEdits: true)
        } catch {
            errorMessage = message(for: error)
        }
        isLoading = false
    }

    // MARK: - Checklist

    func toggle(_ item: ChecklistItem) {
        guard !isLocked, let i = checklist.firstIndex(where: { $0.id == item.id }) else { return }
        checklist[i].checked.toggle()
        scheduleChecklistSave()
    }

    func addChecklistItem(_ text: String) {
        let label = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !isLocked, !label.isEmpty else { return }
        checklist.append(ChecklistItem(item: label))
        scheduleChecklistSave()
    }

    /// Crew tick several boxes in a burst — coalesce into one save.
    private func scheduleChecklistSave() {
        hasUnsavedChecklist = true
        checklistSaveTask?.cancel()
        checklistSaveTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 800_000_000)
            guard !Task.isCancelled else { return }
            await self?.saveChecklist()
        }
    }

    func saveChecklist() async {
        let sent = checklist
        let ok = await post([
            "action": "save_checklist", "visit_id": visitId, "items": sent.map(\.payload)
        ])
        // Only clear the flag if nothing changed while the request was in flight.
        if ok, sent == checklist { hasUnsavedChecklist = false }
    }

    // MARK: - Materials

    func addMaterial(name: String, qty: Double?, unit: String) async {
        let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !isLocked, !trimmed.isEmpty else { return }
        materials.append(MaterialItem(name: trimmed, qty: qty,
                                      unit: unit.trimmingCharacters(in: .whitespaces)))
        await saveMaterials()
    }

    func removeMaterials(at offsets: IndexSet) async {
        guard !isLocked else { return }
        materials.remove(atOffsets: offsets)
        await saveMaterials()
    }

    func saveMaterials() async {
        hasUnsavedMaterials = true
        let sent = materials
        let ok = await post([
            "action": "save_materials", "visit_id": visitId, "items": sent.map(\.payload)
        ])
        if ok, sent == materials { hasUnsavedMaterials = false }
    }

    // MARK: - Notes

    /// Returns true when the note was stored, so the composer knows to clear itself.
    func addNote(_ content: String, type: VisitNoteType, visibleToCustomer: Bool) async -> Bool {
        let trimmed = content.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !isLocked, !trimmed.isEmpty else { return false }
        return await post([
            "action": "add_note", "visit_id": visitId, "content": trimmed,
            "note_type": type.rawValue, "visible_to_customer": visibleToCustomer
        ])
    }

    // MARK: - Private

    private func post(_ body: [String: Any]) async -> Bool {
        isSaving     = true
        errorMessage = nil
        defer { isSaving = false }
        do {
            let state: VisitWorkState = try await apiClient.request(.visitWorkAction, body: body)
            guard state.success else {
                fail(state.message ?? "Could not save.")
                return false
            }
            apply(state, keepLocalEdits: true)
            return true
        } catch {
            fail(message(for: error))
            return false
        }
    }

    /// Adopt server state, except for a list the crew has edited but not yet
    /// managed to save — overwriting that would silently lose their work.
    private func apply(_ state: VisitWorkState, keepLocalEdits: Bool) {
        isLocked = state.locked ?? false
        notes    = state.notes ?? []
        if !(keepLocalEdits && hasUnsavedChecklist) { checklist = state.checklist ?? [] }
        if !(keepLocalEdits && hasUnsavedMaterials) { materials = state.materials ?? [] }
    }

    private func fail(_ text: String) {
        haptic.notificationOccurred(.error)
        errorMessage = text
    }

    private func message(for error: Error) -> String {
        if let api = error as? APIError, case .networkError = api {
            return "No signal — your changes are still on screen. Tap Retry when you're back in range."
        }
        return (error as? APIError)?.localizedDescription ?? error.localizedDescription
    }
}
