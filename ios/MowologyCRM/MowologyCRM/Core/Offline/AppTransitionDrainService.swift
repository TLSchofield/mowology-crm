//
//  AppTransitionDrainService.swift
//  MowologyCRM
//
//  Global drain for pending job-timer transitions (start / stop).
//
//  Problem solved:
//    TransitionQueue persists PendingTransition records in SwiftData so they
//    survive app restarts. But the previous drain lived inside
//    VisitDetailViewModel — scoped to a single stop. When the user navigated
//    away, the ViewModel deallocated, its Combine subscription cancelled, and
//    orphaned transitions were never retried.
//
//  Solution:
//    This singleton subscribes to mwPingQueueOnline at the app level and drains
//    ALL pending transitions regardless of which screen is currently visible.
//    Wire it into MowologyCRMApp.init() once.
//

import Foundation
import SwiftData
import Combine

@MainActor
final class AppTransitionDrainService {

    static let shared = AppTransitionDrainService()

    private var apiClient: APIClient?
    private var sink: AnyCancellable?
    private var isDraining = false

    private init() {}

    // MARK: - Setup

    /// Call once from MowologyCRMApp after APIClient is available.
    func configure(apiClient: APIClient) {
        self.apiClient = apiClient

        // Drain on every reconnect event posted by PingQueue.
        sink = NotificationCenter.default.publisher(for: .mwPingQueueOnline)
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in
                Task { await self?.drain() }
            }
    }

    // MARK: - Drain

    /// Retries all persisted pending transitions.
    /// Safe to call multiple times — re-entrant calls are ignored.
    func drain() async {
        guard !isDraining, let apiClient else { return }
        isDraining = true
        defer { isDraining = false }

        let pending = fetchAllPending()
        guard !pending.isEmpty else { return }

        print("[AppTransitionDrain] Draining \(pending.count) pending transition(s)")

        await withTaskGroup(of: Void.self) { group in
            for transition in pending {
                group.addTask { [weak self] in
                    await self?.retry(transition: transition, apiClient: apiClient)
                }
            }
        }
    }

    // MARK: - Private

    private func retry(transition: PendingTransition, apiClient: APIClient) async {
        var body: [String: Any] = [
            "action":         transition.action,   // "start" or "stop"
            "visit_id":       transition.visitId,
        ]
        if transition.action == "stop" {
            body["complete_visit"] = true
        }
        if let lat = transition.lat { body["lat"] = lat }
        if let lng = transition.lng { body["lng"] = lng }

        do {
            // TimerStartResponse and TimerStopResponse share `success: Bool` —
            // decode as the minimal shared shape.
            let response: TimerBaseResponse = try await apiClient.request(
                .scheduleTimer,
                body: body,
                extraHeaders: ["Idempotency-Key": transition.idempotencyKey]
            )
            if response.success {
                removePending(transition)
                print("[AppTransitionDrain] ✓ visit \(transition.visitId) \(transition.action)")
            }
        } catch let err as APIError {
            // Network errors: leave in queue, will retry on next reconnect.
            // Server errors (4xx/5xx): leave in queue — server may still honour
            // the idempotency key once the issue resolves.
            print("[AppTransitionDrain] ✗ visit \(transition.visitId) \(transition.action): \(err)")
        } catch {
            print("[AppTransitionDrain] ✗ visit \(transition.visitId) \(transition.action): \(error)")
        }
    }

    // MARK: - SwiftData helpers

    private func fetchAllPending() -> [PendingTransition] {
        guard let container = try? ModelContainer(for: PendingTransition.self) else { return [] }
        let ctx = ModelContext(container)
        return (try? ctx.fetch(FetchDescriptor<PendingTransition>())) ?? []
    }

    private func removePending(_ transition: PendingTransition) {
        guard let container = try? ModelContainer(for: PendingTransition.self) else { return }
        let ctx = ModelContext(container)
        let all = (try? ctx.fetch(FetchDescriptor<PendingTransition>())) ?? []
        if let match = all.first(where: {
            $0.visitId == transition.visitId && $0.action == transition.action
        }) {
            ctx.delete(match)
            try? ctx.save()
        }
    }
}

// MARK: - Minimal shared response shape

struct TimerBaseResponse: Decodable {
    let success: Bool
    let message: String?
}
