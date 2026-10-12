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

    /// The one ordered resend (2026-10-12): queued clock punches first, then job actions
    /// oldest-first, ONE AT A TIME. They used to go in parallel — a job start could land after
    /// the clock-out (opening a phantom shift) or before the clock-in (replacing it), and a
    /// visit's stop could land before its start. A caller arriving mid-run waits for that run.
    func drain() async {
        if let running { await running.value; return }
        let task = Task { await self.drainOnce() }
        running = task
        await task.value
        running = nil
    }

    private var running: Task<Void, Never>?

    private func drainOnce() async {
        guard let apiClient else { return }

        // Clock first. If it stopped (no signal, expired login, a punch the server refused),
        // job actions wait too — they must not jump ahead of the shift they belong to.
        if await ClockQueue.shared.drain(using: apiClient) == .offline { return }

        let me = apiClient.currentUserId
        let pending = fetchAllPending()
            .filter { TransitionQueue.owner(of: $0.idempotencyKey) == nil
                   || TransitionQueue.owner(of: $0.idempotencyKey) == me }
            .sorted { $0.queuedAt < $1.queuedAt }
        guard !pending.isEmpty, me != nil else { return }

        print("[AppTransitionDrain] Draining \(pending.count) pending transition(s) in order")
        for transition in pending {
            if await retry(transition: transition, apiClient: apiClient) == .stop { return }
        }
        NotificationCenter.default.post(name: .mwTransitionsDrained, object: nil)
    }

    private enum Outcome { case done, kept, stop }

    // MARK: - Private

    private func retry(transition: PendingTransition, apiClient: APIClient) async -> Outcome {
        var body: [String: Any] = [
            "action":         transition.action,   // "start", "stop" or "skip"
            "visit_id":       transition.visitId,
        ]
        if transition.action == "stop" {
            body["complete_visit"] = true
        }
        if let lat = transition.lat { body["lat"] = lat }
        if let lng = transition.lng { body["lng"] = lng }
        // Recorded at the tap, not at the replay (queuedAt is set when the action was tapped).
        body["client_time"] = transition.queuedAt.mwClientTime
        body["queued"]      = true
        // Extra work typed into a completion made offline (saved by completeJob).
        if transition.action == "stop",
           let extras = UserDefaults.standard.dictionary(forKey: VisitDetailViewModel.stopExtrasKey(visitId: transition.visitId)) {
            if let m = extras["minutes"] as? Int, m > 0 { body["extras_minutes"] = m }
            if let n = extras["note"] as? String, !n.isEmpty { body["extras_note"] = n }
        }
        // When the start was really tapped: the server's special-request gate accepts a queued
        // start (logged) instead of refusing it forever.
        if transition.action == "start" {
            body["queued_at"] = Int(transition.queuedAt.timeIntervalSince1970 * 1000)
        }

        let skipReasonKey = VisitDetailViewModel.skipReasonKey(visitId: transition.visitId)
        if transition.action == "skip",
           let reason = UserDefaults.standard.string(forKey: skipReasonKey) {
            body["reason"] = reason
        }

        do {
            // TimerStartResponse and TimerStopResponse share `success: Bool` —
            // decode as the minimal shared shape.
            let response: TimerBaseResponse = try await apiClient.request(
                .scheduleTimer,
                body: body,
                extraHeaders: ["Idempotency-Key": transition.idempotencyKey]
            )
            // A skip the server refuses (visit since started/completed elsewhere)
            // is a definitive answer — drop it rather than retrying forever.
            if response.success || transition.action == "skip" {
                removePending(transition)
                UserDefaults.standard.removeObject(forKey: skipReasonKey)
                UserDefaults.standard.removeObject(forKey: VisitDetailViewModel.stopExtrasKey(visitId: transition.visitId))
                TransitionQueue.forgetOwner(of: transition.idempotencyKey)
                print("[AppTransitionDrain] ✓ visit \(transition.visitId) \(transition.action)")
                return .done
            }
            return .kept
        } catch let err as APIError {
            print("[AppTransitionDrain] ✗ visit \(transition.visitId) \(transition.action): \(err)")
            switch err {
            case .networkError, .unauthorized:
                return .stop   // no signal / login expired: keep everything, try again later
            default:
                // Server error: kept (the idempotency key makes a later retry safe). A stop that
                // follows this visit's start must not go ahead of it.
                return transition.action == "start" ? .stop : .kept
            }
        } catch {
            return .stop
        }
    }

    // MARK: - SwiftData helpers

    private func fetchAllPending() -> [PendingTransition] {
        let ctx = ModelContext(TransitionQueue.sharedContainer)
        return (try? ctx.fetch(FetchDescriptor<PendingTransition>())) ?? []
    }

    private func removePending(_ transition: PendingTransition) {
        let ctx = ModelContext(TransitionQueue.sharedContainer)
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
