import Foundation
#if canImport(UIKit)
import UIKit
#endif

struct HangWatchdogState {
    private(set) var stallStartedAt: Date?

    static func isStalled(lastReply: Date, now: Date, threshold: TimeInterval) -> Bool {
        now.timeIntervalSince(lastReply) > threshold
    }

    /// Returns the completed stall length once, when the main queue starts replying again.
    mutating func update(lastReply: Date, now: Date, threshold: TimeInterval,
                         isActive: Bool) -> TimeInterval? {
        guard isActive else {
            stallStartedAt = nil
            return nil
        }
        if Self.isStalled(lastReply: lastReply, now: now, threshold: threshold) {
            if stallStartedAt == nil { stallStartedAt = lastReply }
            return nil
        }
        guard let start = stallStartedAt else { return nil }
        stallStartedAt = nil
        return max(0, lastReply.timeIntervalSince(start))
    }
}

#if !CRASH_GUARD_LOGIC_ONLY
final class HangWatchdog: @unchecked Sendable {
    static let shared = HangWatchdog()

    private let lock = NSLock()
    private var lastReply = Date()
    private var isActive = true
    private var state = HangWatchdogState()
    private var timer: DispatchSourceTimer?
    private var observers: [NSObjectProtocol] = []
    private let queue = DispatchQueue(label: "ca.mowology.hang-watchdog", qos: .utility)
    private let interval: TimeInterval = 0.5
    private let threshold: TimeInterval = 2

    private init() {}

    func start(notificationCenter: NotificationCenter = .default) {
        lock.lock()
        guard timer == nil else { lock.unlock(); return }
        lock.unlock()

        #if canImport(UIKit)
        let inactiveNames: [Notification.Name] = [UIApplication.willResignActiveNotification,
                                                   UIApplication.didEnterBackgroundNotification]
        let activeNames: [Notification.Name] = [UIApplication.willEnterForegroundNotification,
                                                 UIApplication.didBecomeActiveNotification]
        observers += inactiveNames.map { name in
            notificationCenter.addObserver(forName: name, object: nil, queue: nil) { [weak self] _ in
                self?.setActive(false)
            }
        }
        observers += activeNames.map { name in
            notificationCenter.addObserver(forName: name, object: nil, queue: nil) { [weak self] _ in
                self?.setActive(true)
            }
        }
        #endif

        let source = DispatchSource.makeTimerSource(queue: queue)
        source.schedule(deadline: .now() + interval, repeating: interval)
        source.setEventHandler { [weak self] in self?.tick() }
        lock.lock(); timer = source; lock.unlock()
        source.resume()
    }

    private func setActive(_ active: Bool) {
        lock.lock()
        isActive = active
        lastReply = Date()
        if !active { _ = state.update(lastReply: lastReply, now: lastReply, threshold: threshold, isActive: false) }
        lock.unlock()
    }

    private func tick() {
        DispatchQueue.main.async { [weak self] in
            guard let self else { return }
            lock.lock(); lastReply = Date(); lock.unlock()
        }

        let now = Date()
        lock.lock()
        let hadStall = state.stallStartedAt != nil
        let completed = state.update(lastReply: lastReply, now: now, threshold: threshold, isActive: isActive)
        let startedStall = !hadStall && state.stallStartedAt != nil
        lock.unlock()
        let crumbs = AppDiagnostics.shared.recentBreadcrumbs()
        let trail = crumbs.isEmpty ? "(no navigation breadcrumbs)" : crumbs.joined(separator: " | ")
        // Logged when the stall starts: a stall that ends in an iOS kill never recovers, so an end-only report would miss it.
        if startedStall {
            AppDiagnostics.shared.record(category: "HangWatchdog",
                                         message: "Main thread not responding for over \(threshold) s. Last navigation: \(trail)")
        }
        guard let completed else { return }
        let duration = String(format: "%.2f", completed)
        AppDiagnostics.shared.record(category: "HangWatchdog",
                                     message: "Main thread stalled for \(duration) s. Last navigation: \(trail)")
    }
}
#endif
