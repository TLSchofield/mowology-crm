//
//  ConnectivityMonitor.swift
//  MowologyCRM
//
//  Posts `.mwPingQueueOnline` when the phone gets signal back. Every offline queue
//  (clock, job actions, photos, recommendations, trip reports) drains on that notification.
//
//  This used to live inside PingQueue, a SwiftData queue nothing wrote to any more
//  (fixes go through FixStore). PingQueue was only created when GPS tracking started, so
//  before a clock-in nothing announced reconnects at all, and it opened a third SwiftData
//  store with a `try!` fallback. Removed 2026-10-12; the notification name is kept so
//  every observer keeps working unchanged. Started once from MowologyCRMApp.init.
//

import Foundation
import Combine
import Network

@MainActor
final class ConnectivityMonitor: ObservableObject {

    static let shared = ConnectivityMonitor()

    private let monitor = NWPathMonitor()
    private let queue   = DispatchQueue(label: "ca.mowology.connectivity", qos: .utility)
    /// Updated on the main actor so SwiftUI can distinguish offline from server failures.
    @Published private(set) var isOnline = true

    private init() {
        monitor.pathUpdateHandler = { [weak self] path in
            guard let self else { return }
            let online = path.status == .satisfied
            Task { @MainActor in
                let wasOffline = !self.isOnline
                self.isOnline = online
                if wasOffline && online {
                    NotificationCenter.default.post(name: .mwPingQueueOnline, object: nil)
                }
            }
        }
        monitor.start(queue: queue)
    }

    /// Touch once at launch so reconnects are announced from the start, not from clock-in.
    func start() {}
}

// MARK: - Notification

extension Notification.Name {
    /// Posted on the main thread when connectivity is restored (name kept from PingQueue).
    static let mwPingQueueOnline = Notification.Name("ca.mowology.pingQueueOnline")
    /// Posted after the ordered resend has sent every queued job action (screens refresh).
    static let mwTransitionsDrained = Notification.Name("ca.mowology.transitionsDrained")
}
