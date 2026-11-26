//
//  VisitWorkViewModel.swift
//  MowologyCRM
//

import Foundation
import UIKit
import Combine

@MainActor
final class VisitWorkViewModel: ObservableObject {

    @Published var checklist: [ChecklistItem] = []
    @Published var materials: [MaterialItem]  = []
    @Published private(set) var notes: [VisitNote] = []
    @Published private(set) var isLocked  = false
    @Published private(set) var isLoading = false
    @Published private(set) var isSaving  = false
    @Published var errorMessage: String?
    @Published private(set) var offlineStatusMessage: String?
    @Published private(set) var queuedEntries: [VisitWorkEntry] = []
    @Published private(set) var queuedCount = 0

    /// True when the checklist or materials on screen differ from what the server
    /// last confirmed — i.e. a save failed (usually no signal) and needs a retry.
    @Published private(set) var hasUnsavedChecklist = false
    @Published private(set) var hasUnsavedMaterials = false

    let visitId: Int
    private let apiClient: APIClient
    private let haptic = UINotificationFeedbackGenerator()
    private var checklistSaveTask: Task<Void, Never>?
    private var queueSink: AnyCancellable?
    private var isChecklistLocallyDirty = false
    private var areMaterialsLocallyDirty = false

    init(visitId: Int, authSession: AuthSession) {
        self.visitId   = visitId
        self.apiClient = APIClient(authSession: authSession)
        queueSink = VisitWorkQueue.shared.$entries
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in self?.refreshQueueStatus() }
        refreshQueueStatus()
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
        isChecklistLocallyDirty = true
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
        if ok, sent == checklist {
            isChecklistLocallyDirty = false
            refreshQueueStatus()
        }
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
        areMaterialsLocallyDirty = true
        hasUnsavedMaterials = true
        let sent = materials
        let ok = await post([
            "action": "save_materials", "visit_id": visitId, "items": sent.map(\.payload)
        ])
        if ok, sent == materials {
            areMaterialsLocallyDirty = false
            refreshQueueStatus()
        }
    }

    // MARK: - Notes

    /// Returns true when the note was stored, so the composer knows to clear itself.
    func addNote(_ content: String, type: VisitNoteType, visibleToCustomer: Bool) async -> Bool {
        let trimmed = content.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !isLocked, !trimmed.isEmpty else { return false }
        return await post([
            "action": "add_note", "visit_id": visitId, "content": trimmed,
            "note_type": type.rawValue, "visible_to_customer": visibleToCustomer,
            "client_request_id": UUID().uuidString
        ])
    }

    // MARK: - Private

    private func post(_ body: [String: Any]) async -> Bool {
        let performedAt = Date()
        isSaving     = true
        errorMessage = nil
        defer { isSaving = false }
        do {
            let state: VisitWorkState = try await apiClient.request(.visitWorkAction, body: body)
            guard state.success else {
                fail(state.message ?? "Visit work response success=false; server returned no message field.")
                return false
            }
            apply(state, keepLocalEdits: true)
            if let kind = queueKind(for: body), kind != .note, let userId = apiClient.currentUserId {
                VisitWorkQueue.shared.removeQueuedList(userId: userId, visitId: visitId, kind: kind)
            }
            return true
        } catch {
            guard let apiError = error as? APIError, case .networkError = apiError,
                  let userId = apiClient.currentUserId,
                  let kind = queueKind(for: body) else {
                fail(ErrorDetails.describe(error))
                return false
            }
            let id = body["client_request_id"] as? String ?? UUID().uuidString
            var queuedBody = body
            if kind == .note { queuedBody["client_request_id"] = id }
            guard VisitWorkQueue.shared.enqueue(
                userId: userId,
                visitId: visitId,
                kind: kind,
                body: queuedBody,
                id: id,
                performedAt: performedAt
            ) else {
                fail("\(ErrorDetails.describe(error)) The change also could not be saved on this phone.")
                return false
            }
            errorMessage = nil
            offlineStatusMessage = "Saved on this phone. It sends when you have signal."
            refreshQueueStatus()
            return kind == .note
        }
    }

    func discardQueuedEntry(_ id: String) {
        guard let userId = apiClient.currentUserId else { return }
        VisitWorkQueue.shared.discard(id, userId: userId)
        refreshQueueStatus()
    }

    private func queueKind(for body: [String: Any]) -> VisitWorkEntry.Kind? {
        switch body["action"] as? String {
        case "save_checklist": return .checklist
        case "save_materials": return .materials
        case "add_note": return .note
        default: return nil
        }
    }

    private func refreshQueueStatus() {
        guard let userId = apiClient.currentUserId else {
            queuedEntries = []
            queuedCount = 0
            offlineStatusMessage = nil
            return
        }
        queuedEntries = VisitWorkQueue.shared.entries(userId: userId, visitId: visitId)
        let waitingCount = queuedEntries.filter { !$0.isRefused }.count
        queuedCount = waitingCount
        if waitingCount == 0 {
            offlineStatusMessage = nil
        }
        hasUnsavedChecklist = VisitWorkQueueLogic.hasUnsavedList(
            kind: .checklist,
            localDirty: isChecklistLocallyDirty,
            entries: queuedEntries
        )
        hasUnsavedMaterials = VisitWorkQueueLogic.hasUnsavedList(
            kind: .materials,
            localDirty: areMaterialsLocallyDirty,
            entries: queuedEntries
        )
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

    private func message(for error: Error) -> String { ErrorDetails.describe(error) }
}
