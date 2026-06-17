//
//  AppPhotoQueueDrainService.swift
//  MowologyCRM
//
//  Global drain for pending offline job photo uploads.
//
//  Problem solved:
//    JobPhotoSection registered its own mwPingQueueOnline observer, so photos
//    only drained while that specific screen was visible. A photo captured
//    offline and then navigated away from was never retried until the user
//    returned to that exact visit.
//
//  Solution:
//    This singleton mirrors AppTransitionDrainService — it subscribes to
//    mwPingQueueOnline at the app level and drains ALL pending photo uploads
//    regardless of which screen is currently visible.
//    Wire it into MowologyCRMApp alongside AppTransitionDrainService.
//

import Foundation
import Combine

@MainActor
final class AppPhotoQueueDrainService {

    static let shared = AppPhotoQueueDrainService()

    private var apiClient: APIClient?
    private var sink: AnyCancellable?
    private var isDraining = false

    private init() {}

    // MARK: - Setup

    /// Call once from MowologyCRMApp after APIClient is available.
    func configure(apiClient: APIClient) {
        self.apiClient = apiClient

        sink = NotificationCenter.default.publisher(for: .mwPingQueueOnline)
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in
                Task { await self?.drain() }
            }
    }

    // MARK: - Drain

    /// Retries all pending photo uploads.
    /// Safe to call multiple times — re-entrant calls are ignored.
    func drain() async {
        guard !isDraining, let apiClient else { return }
        isDraining = true
        defer { isDraining = false }

        guard JobPhotoQueue.shared.pendingCount > 0 else { return }
        print("[AppPhotoQueueDrain] Draining \(JobPhotoQueue.shared.pendingCount) pending photo(s)")
        await JobPhotoQueue.shared.drain(using: apiClient)
    }
}
